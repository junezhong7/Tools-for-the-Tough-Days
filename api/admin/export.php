<?php
/**
 * Tools for the Tough Days — Admin Member Export
 *
 * GET /api/admin/export.php?q=...&status=...
 * Streams a CSV of the member list matching the same filters as members.php's list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

$admin = require_admin_auth();

$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';

$validStatuses = ['active', 'cancelled', 'past_due', 'unpaid', 'trialing', 'paused', 'pending'];
if ($status !== 'all' && $status !== 'none' && !in_array($status, $validStatuses, true)) {
    json_error(422, 'INVALID_STATUS', 'Unknown subscription status filter.');
}

[$where, $params] = build_export_filter($q, $status);

audit('admin.member.export', null, ['q' => $q, 'status' => $status], (int) $admin['id']);

$filename = 'members-export-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'id', 'email', 'full_name', 'is_business_user', 'business_name', 'user_status',
    'created_at', 'subscription_status', 'product_key', 'plan_type',
    'current_period_end', 'cancel_at_period_end',
]);

$chunkSize = 500;
$offset    = 0;

while (true) {
    $stmt = db()->prepare(
        "SELECT u.id, u.email, u.full_name, u.is_business_user, u.business_name,
                u.status AS user_status, u.created_at,
                s.status AS subscription_status, s.product_key, s.plan_type,
                s.current_period_end, s.cancel_at_period_end
         FROM users u
         LEFT JOIN subscriptions s ON s.id = (
             SELECT id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY created_at DESC LIMIT 1
         )
         WHERE {$where}
         ORDER BY u.id ASC
         LIMIT {$chunkSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        break;
    }

    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['email'],
            $row['full_name'],
            $row['is_business_user'] ? '1' : '0',
            $row['business_name'],
            $row['user_status'],
            $row['created_at'],
            $row['subscription_status'],
            $row['product_key'],
            $row['plan_type'],
            $row['current_period_end'],
            $row['cancel_at_period_end'] ? '1' : '0',
        ]);
    }

    $offset += $chunkSize;
    if (count($rows) < $chunkSize) {
        break;
    }
}

fclose($out);
exit;

/**
 * Builds a WHERE clause + bound params array for the search/status filter.
 * @return array{0:string,1:array}
 */
function build_export_filter(string $q, string $status): array
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
