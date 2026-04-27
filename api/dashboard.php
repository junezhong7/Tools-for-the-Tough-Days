<?php
/**
 * Tools for the Tough Days — Dashboard API
 * GET /api/dashboard.php
 *
 * Returns the current user's profile, subscriptions, and recent payments.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$user   = require_auth();
$userId = (int) $user['id'];

// Subscriptions
$subStmt = db()->prepare(
    'SELECT id, product_key, plan_type, status, current_period_start, current_period_end,
            cancel_at_period_end, cancelled_at, created_at
     FROM subscriptions WHERE user_id = ?
     ORDER BY created_at DESC'
);
$subStmt->execute([$userId]);
$subscriptions = $subStmt->fetchAll();

// Recent payments (last 20)
$payStmt = db()->prepare(
    'SELECT id, amount_cents, currency, status, description, created_at
     FROM payments WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 20'
);
$payStmt->execute([$userId]);
$payments = $payStmt->fetchAll();

// Convert cents → dollars for display
foreach ($payments as &$p) {
    $p['amount'] = number_format($p['amount_cents'] / 100, 2);
}
unset($p);

json_ok([
    'user' => [
        'id'               => (int) $user['id'],
        'email'            => $user['email'],
        'full_name'        => $user['full_name'],
        'is_business_user' => (bool) $user['is_business_user'],
        'business_name'    => $user['business_name'],
    ],
    'subscriptions' => $subscriptions,
    'payments'      => $payments,
]);
