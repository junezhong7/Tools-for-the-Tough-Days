<?php
/**
 * Tools for the Tough Days — Admin Subscriptions API
 *
 * POST /api/admin/subscriptions.php?action=cancel  {subscription_id}
 * POST /api/admin/subscriptions.php?action=extend  {member_id, duration}
 *      duration: "7d" | "30d" | "90d" | "permanent"
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

if (file_exists(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config.php';
}
require_once __DIR__ . '/../../stripe-php/init.php';

const EXTEND_DURATIONS = [
    '7d'        => 7,
    '30d'       => 30,
    '90d'       => 90,
    'permanent' => null, // handled specially
];

header('Content-Type: application/json; charset=utf-8');

$admin  = require_admin_auth();
$adminId = (int) $admin['id'];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'POST') {
    json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
}

$raw  = file_get_contents('php://input');
$body = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;

switch ($action) {
    case 'cancel':
        handle_cancel($body, $adminId);
        break;
    case 'extend':
        handle_extend($body, $adminId);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// CANCEL — Stripe-aware. If the subscription is Stripe-backed, cancel it
// there too (this is the gap the old manual SQL script had: it only ever
// touched the local row, leaving the real Stripe subscription billing).
// ─────────────────────────────────────────────
function handle_cancel(array $body, int $adminId): never
{
    $subId = (int) ($body['subscription_id'] ?? 0);
    if ($subId <= 0) {
        json_error(400, 'MISSING_ID', 'subscription_id is required.');
    }

    $stmt = db()->prepare('SELECT * FROM subscriptions WHERE id = ?');
    $stmt->execute([$subId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        json_error(404, 'NOT_FOUND', 'Subscription not found.');
    }

    if ($sub['status'] === 'cancelled') {
        json_error(400, 'ALREADY_CANCELLED', 'Subscription is already cancelled.');
    }

    $isStripeManaged = $sub['stripe_subscription_id'] !== null;

    if ($isStripeManaged) {
        if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
            json_error(500, 'CONFIG_ERROR', 'Payment system is not configured.');
        }
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        try {
            \Stripe\Subscription::update($sub['stripe_subscription_id'], [
                'cancel_at_period_end' => true,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('admin cancel error: ' . $e->getMessage());
            json_error(502, 'STRIPE_ERROR', 'Could not cancel the Stripe subscription. Please try again.');
        }

        db()->prepare('UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = ?')->execute([$subId]);
        $message = 'Subscription will cancel at the end of the current billing period.';
    } else {
        db()->prepare(
            'UPDATE subscriptions SET status = \'cancelled\', cancelled_at = NOW() WHERE id = ?'
        )->execute([$subId]);
        $message = 'Comp subscription cancelled immediately.';
    }

    audit('admin.subscription.cancel', (int) $sub['user_id'], [
        'subscription_id' => $subId,
        'stripe_managed'  => $isStripeManaged,
    ], $adminId);

    json_ok(['message' => $message]);
}

// ─────────────────────────────────────────────
// EXTEND — comp/manual grants only. Refuses to touch a member who
// currently has an active Stripe-backed subscription; MVP doesn't edit
// real billing periods.
// ─────────────────────────────────────────────
function handle_extend(array $body, int $adminId): never
{
    $memberId = (int) ($body['member_id'] ?? 0);
    $duration = (string) ($body['duration'] ?? '');

    if ($memberId <= 0) {
        json_error(400, 'MISSING_ID', 'member_id is required.');
    }
    if (!array_key_exists($duration, EXTEND_DURATIONS)) {
        json_error(422, 'INVALID_DURATION', 'duration must be one of: ' . implode(', ', array_keys(EXTEND_DURATIONS)));
    }

    $memberStmt = db()->prepare('SELECT id FROM users WHERE id = ?');
    $memberStmt->execute([$memberId]);
    if (!$memberStmt->fetch()) {
        json_error(404, 'NOT_FOUND', 'Member not found.');
    }

    $stripeManagedStmt = db()->prepare(
        "SELECT id FROM subscriptions
         WHERE user_id = ? AND stripe_subscription_id IS NOT NULL
           AND status IN ('active', 'trialing', 'past_due')"
    );
    $stripeManagedStmt->execute([$memberId]);
    if ($stripeManagedStmt->fetch()) {
        json_error(400, 'STRIPE_MANAGED', 'This member has an active Stripe subscription. Manage it in Stripe or cancel it here first.');
    }

    $newPeriodEnd = $duration === 'permanent'
        ? '2099-12-31 23:59:59'
        : date('Y-m-d H:i:s', strtotime('+' . EXTEND_DURATIONS[$duration] . ' days'));

    $compStmt = db()->prepare(
        "SELECT id, current_period_end FROM subscriptions
         WHERE user_id = ? AND stripe_subscription_id IS NULL
           AND status IN ('active', 'trialing', 'pending')
         ORDER BY created_at DESC LIMIT 1"
    );
    $compStmt->execute([$memberId]);
    $comp = $compStmt->fetch();

    if ($comp) {
        $subId = (int) $comp['id'];
        $base  = $comp['current_period_end'] && strtotime($comp['current_period_end']) > time()
            ? $comp['current_period_end']
            : date('Y-m-d H:i:s');
        $newPeriodEnd = $duration === 'permanent'
            ? '2099-12-31 23:59:59'
            : date('Y-m-d H:i:s', strtotime($base) + EXTEND_DURATIONS[$duration] * 86400);

        db()->prepare(
            "UPDATE subscriptions
             SET status = 'active', current_period_end = ?, cancel_at_period_end = 0, cancelled_at = NULL
             WHERE id = ?"
        )->execute([$newPeriodEnd, $subId]);
    } else {
        $insertStmt = db()->prepare(
            "INSERT INTO subscriptions
                (user_id, product_key, plan_type, status, current_period_start, current_period_end, cancel_at_period_end)
             VALUES (?, 'comp_grant', 'individual', 'active', NOW(), ?, 0)"
        );
        $insertStmt->execute([$memberId, $newPeriodEnd]);
        $subId = (int) db()->lastInsertId();
    }

    audit('admin.subscription.extend', $memberId, [
        'subscription_id' => $subId,
        'duration'         => $duration,
        'new_period_end'   => $newPeriodEnd,
    ], $adminId);

    json_ok(['message' => 'Subscription extended.', 'current_period_end' => $newPeriodEnd]);
}
