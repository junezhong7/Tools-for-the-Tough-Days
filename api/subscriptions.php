<?php
/**
 * Tools for the Tough Days — Subscriptions API
 *
 * GET  /api/subscriptions.php                    list current user's subscriptions
 * POST /api/subscriptions.php?action=cancel      cancel at period end
 * POST /api/subscriptions.php?action=resume      undo a pending cancellation
 * POST /api/subscriptions.php?action=update-plan upgrade / downgrade plan
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../stripe-php/init.php';

if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
    json_error(500, 'CONFIG_ERROR', 'Payment system is not configured.');
}
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$user   = require_auth();
$userId = (int) $user['id'];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;
}

switch (true) {
    case $method === 'GET' && $action === '':
        handle_list($userId);
        break;
    case $method === 'POST' && $action === 'cancel':
        handle_cancel($userId, $body);
        break;
    case $method === 'POST' && $action === 'resume':
        handle_resume($userId, $body);
        break;
    case $method === 'POST' && $action === 'update-plan':
        handle_update_plan($userId, $body);
        break;
    default:
        json_error(400, 'INVALID_REQUEST', 'Unknown action or method.');
}

// ─────────────────────────────────────────────
// LIST
// ─────────────────────────────────────────────
function handle_list(int $userId): never
{
    $stmt = db()->prepare(
        'SELECT id, product_key, plan_type, status, current_period_start, current_period_end,
                cancel_at_period_end, cancelled_at, created_at
         FROM subscriptions
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    json_ok(['subscriptions' => $stmt->fetchAll()]);
}

// ─────────────────────────────────────────────
// CANCEL (at period end)
// ─────────────────────────────────────────────
function handle_cancel(int $userId, array $body): never
{
    $sub = fetch_owned_subscription($userId, (int) ($body['subscription_id'] ?? 0));

    if (!$sub['stripe_subscription_id']) {
        json_error(400, 'NO_STRIPE_SUB', 'No Stripe subscription associated with this record.');
    }

    if ($sub['status'] === 'cancelled') {
        json_error(400, 'ALREADY_CANCELLED', 'Subscription is already cancelled.');
    }

    try {
        \Stripe\Subscription::update($sub['stripe_subscription_id'], [
            'cancel_at_period_end' => true,
        ]);

        db()->prepare(
            'UPDATE subscriptions SET cancel_at_period_end = 1 WHERE id = ?'
        )->execute([$sub['id']]);

        audit('subscription.cancel_requested', $userId, ['subscription_id' => $sub['id']]);

        json_ok(['message' => 'Subscription will cancel at the end of the current period.']);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('cancel error: ' . $e->getMessage());
        json_error(502, 'STRIPE_ERROR', 'Could not cancel subscription. Please try again.');
    }
}

// ─────────────────────────────────────────────
// RESUME (undo pending cancellation)
// ─────────────────────────────────────────────
function handle_resume(int $userId, array $body): never
{
    $sub = fetch_owned_subscription($userId, (int) ($body['subscription_id'] ?? 0));

    if (!$sub['cancel_at_period_end']) {
        json_error(400, 'NOT_PENDING_CANCEL', 'Subscription is not pending cancellation.');
    }

    try {
        \Stripe\Subscription::update($sub['stripe_subscription_id'], [
            'cancel_at_period_end' => false,
        ]);

        db()->prepare(
            'UPDATE subscriptions SET cancel_at_period_end = 0 WHERE id = ?'
        )->execute([$sub['id']]);

        audit('subscription.resume', $userId, ['subscription_id' => $sub['id']]);

        json_ok(['message' => 'Subscription resumed — it will no longer be cancelled.']);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('resume error: ' . $e->getMessage());
        json_error(502, 'STRIPE_ERROR', 'Could not resume subscription. Please try again.');
    }
}

// ─────────────────────────────────────────────
// UPDATE PLAN (upgrade / downgrade)
// ─────────────────────────────────────────────
function handle_update_plan(int $userId, array $body): never
{
    $sub        = fetch_owned_subscription($userId, (int) ($body['subscription_id'] ?? 0));
    $newPriceId = trim($body['new_price_id'] ?? '');

    if (!$newPriceId) {
        json_error(422, 'MISSING_PRICE', 'new_price_id is required.');
    }

    // Only allow known price IDs
    $allowedPrices = [
        'price_1TLD1mC8xESC1BMDifb6wvHd',
        'price_1TLDMFC8xESC1BMDRVGsCGIY',
        'price_1TLElkC8xESC1BMD1IkfyBQl',
        'price_1TLEnfC8xESC1BMDuM43gQNr',
        'price_1TLEpWC8xESC1BMDuTCnIF2F',
        'price_1TLeUZC8xESC1BMDlaDCRwQv',
        'price_1TLeVDC8xESC1BMDxQFkfvDC',
        'price_1TLeVeC8xESC1BMD25LqYUGK',
    ];

    if (!in_array($newPriceId, $allowedPrices, true)) {
        json_error(422, 'INVALID_PRICE', 'The selected plan is not valid.');
    }

    try {
        $stripeSub = \Stripe\Subscription::retrieve($sub['stripe_subscription_id']);
        $itemId    = $stripeSub->items->data[0]->id;

        \Stripe\Subscription::update($sub['stripe_subscription_id'], [
            'items'               => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior'  => 'create_prorations',
        ]);

        audit('subscription.plan_updated', $userId, [
            'subscription_id' => $sub['id'],
            'new_price_id'    => $newPriceId,
        ]);

        json_ok(['message' => 'Plan updated. Changes take effect immediately.']);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('update-plan error: ' . $e->getMessage());
        json_error(502, 'STRIPE_ERROR', 'Could not update plan. Please try again.');
    }
}

// ─────────────────────────────────────────────
// HELPER — fetch subscription owned by user
// ─────────────────────────────────────────────
function fetch_owned_subscription(int $userId, int $subId): array
{
    if ($subId <= 0) {
        json_error(400, 'MISSING_ID', 'subscription_id is required.');
    }

    $stmt = db()->prepare(
        'SELECT * FROM subscriptions WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$subId, $userId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        json_error(404, 'NOT_FOUND', 'Subscription not found.');
    }

    return $sub;
}
