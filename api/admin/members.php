<?php
/**
 * Tools for the Tough Days — Admin Members API
 *
 * GET /api/admin/members.php                          list + search + pagination
 * GET /api/admin/members.php?action=detail&member_id=N single member + full history
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

const SUBSCRIPTION_STATUSES = ['active', 'cancelled', 'past_due', 'unpaid', 'trialing', 'paused', 'pending'];

header('Content-Type: application/json; charset=utf-8');

require_admin_auth();

$action = $_GET['action'] ?? '';

switch ($action) {
    case '':
        handle_list();
        break;
    case 'detail':
        handle_detail();
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// LIST (search + status filter + pagination)
// ─────────────────────────────────────────────
function handle_list(): never
{
    $q      = trim($_GET['q'] ?? '');
    $status = $_GET['status'] ?? 'all';
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = (int) ($_GET['page_size'] ?? 25);
    $pageSize = $pageSize > 0 ? min(100, $pageSize) : 25;
    $offset   = ($page - 1) * $pageSize;

    if ($status !== 'all' && $status !== 'none' && !in_array($status, SUBSCRIPTION_STATUSES, true)) {
        json_error(422, 'INVALID_STATUS', 'Unknown subscription status filter.');
    }

    [$where, $params] = build_filter($q, $status);

    $countStmt = db()->prepare(
        "SELECT COUNT(*) AS total
         FROM users u
         LEFT JOIN subscriptions s ON s.id = (
             SELECT id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY created_at DESC LIMIT 1
         )
         WHERE {$where}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['total'];

    $listStmt = db()->prepare(
        "SELECT u.id, u.email, u.full_name, u.is_business_user, u.business_name,
                u.status AS user_status, u.created_at,
                s.id AS subscription_id, s.product_key, s.plan_type,
                s.status AS subscription_status, s.current_period_end,
                s.cancel_at_period_end, s.stripe_subscription_id
         FROM users u
         LEFT JOIN subscriptions s ON s.id = (
             SELECT id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY created_at DESC LIMIT 1
         )
         WHERE {$where}
         ORDER BY u.created_at DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $listStmt->execute($params);

    $members = array_map('format_member_row', $listStmt->fetchAll());

    json_ok([
        'members'     => $members,
        'page'        => $page,
        'page_size'   => $pageSize,
        'total'       => $total,
        'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
    ]);
}

/**
 * Builds a WHERE clause + bound params array for the search/status filter.
 * @return array{0:string,1:array}
 */
function build_filter(string $q, string $status): array
{
    $conditions = ['1=1'];
    $params     = [];

    if ($q !== '') {
        $conditions[] = '(u.email LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if ($status === 'none') {
        $conditions[] = 's.id IS NULL';
    } elseif ($status !== 'all') {
        $conditions[] = 's.status = ?';
        $params[] = $status;
    }

    return [implode(' AND ', $conditions), $params];
}

function format_member_row(array $row): array
{
    return [
        'id'               => (int) $row['id'],
        'email'            => $row['email'],
        'full_name'        => $row['full_name'],
        'is_business_user' => (bool) $row['is_business_user'],
        'business_name'    => $row['business_name'],
        'user_status'      => $row['user_status'],
        'created_at'       => $row['created_at'],
        'subscription'     => $row['subscription_id'] ? [
            'id'                   => (int) $row['subscription_id'],
            'product_key'          => $row['product_key'],
            'plan_type'            => $row['plan_type'],
            'status'               => $row['subscription_status'],
            'current_period_end'   => $row['current_period_end'],
            'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'is_stripe_managed'    => $row['stripe_subscription_id'] !== null,
        ] : null,
    ];
}

// ─────────────────────────────────────────────
// DETAIL (single member + full history)
// ─────────────────────────────────────────────
function handle_detail(): never
{
    $memberId = (int) ($_GET['member_id'] ?? 0);
    if ($memberId <= 0) {
        json_error(400, 'MISSING_ID', 'member_id is required.');
    }

    $stmt = db()->prepare(
        'SELECT id, email, full_name, is_business_user, business_name, status AS user_status,
                stripe_customer_id, created_at
         FROM users WHERE id = ?'
    );
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();

    if (!$member) {
        json_error(404, 'NOT_FOUND', 'Member not found.');
    }

    $subStmt = db()->prepare(
        'SELECT id, stripe_subscription_id, product_key, plan_type, status,
                current_period_start, current_period_end, cancel_at_period_end,
                cancelled_at, created_at
         FROM subscriptions
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $subStmt->execute([$memberId]);

    $payStmt = db()->prepare(
        'SELECT id, subscription_id, amount_cents, currency, status, description, created_at
         FROM payments
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 200'
    );
    $payStmt->execute([$memberId]);

    json_ok([
        'member' => [
            'id'                => (int) $member['id'],
            'email'             => $member['email'],
            'full_name'         => $member['full_name'],
            'is_business_user'  => (bool) $member['is_business_user'],
            'business_name'     => $member['business_name'],
            'user_status'       => $member['user_status'],
            'stripe_customer_id'=> $member['stripe_customer_id'],
            'created_at'        => $member['created_at'],
        ],
        'subscriptions' => $subStmt->fetchAll(),
        'payments'      => $payStmt->fetchAll(),
    ]);
}
