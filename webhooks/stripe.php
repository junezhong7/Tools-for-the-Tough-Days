<?php
/**
 * Tools for the Tough Days — Stripe Webhook Handler
 *
 * Register this URL in Stripe Dashboard → Webhooks:
 *   https://yourdomain.com/webhooks/stripe.php
 *
 * Required events to subscribe to:
 *   - checkout.session.completed
 *   - customer.subscription.updated
 *   - customer.subscription.deleted
 *   - invoice.payment_succeeded
 *   - invoice.payment_failed
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';

// Load Stripe and config
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../stripe-php/init.php';

if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
    http_response_code(500);
    exit;
}

$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
if ($webhookSecret === '') {
    error_log('STRIPE_WEBHOOK_SECRET is not set — webhook signature verification skipped (insecure).');
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Read raw body BEFORE any output
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify signature ──────────────────────────────────────────────────────────
try {
    if ($webhookSecret !== '') {
        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    } else {
        // Fallback: parse without verification (dev only — never in production)
        $event = \Stripe\Event::constructFrom(json_decode($payload, true));
    }
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    error_log('Stripe webhook signature verification failed: ' . $e->getMessage());
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    error_log('Stripe webhook parse error: ' . $e->getMessage());
    exit;
}

// ── Dispatch event ────────────────────────────────────────────────────────────
try {
    switch ($event->type) {

        case 'checkout.session.completed':
            handle_checkout_completed($event->data->object);
            break;

        case 'customer.subscription.updated':
            handle_subscription_updated($event->data->object);
            break;

        case 'customer.subscription.deleted':
            handle_subscription_deleted($event->data->object);
            break;

        case 'invoice.payment_succeeded':
            handle_invoice_paid($event->data->object);
            break;

        case 'invoice.payment_failed':
            handle_invoice_failed($event->data->object);
            break;

        default:
            // Unhandled event — acknowledge receipt
            break;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Throwable $e) {
    error_log('Webhook handler error [' . $event->type . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}

// ─────────────────────────────────────────────
// checkout.session.completed
// ─────────────────────────────────────────────
function handle_checkout_completed(\Stripe\Checkout\Session $session): void
{
    $customerId     = $session->customer ?? null;
    $subscriptionId = $session->subscription ?? null;
    $sessionId      = $session->id;
    $userId         = resolve_checkout_user_id($session);

    // If we still can't link to a user, we skip (edge case: guest checkout)
    if (!$userId) {
        error_log("checkout.session.completed: no user found for customer {$customerId}");
        return;
    }

    // Ensure stripe_customer_id is stored on user
    db()->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ? AND stripe_customer_id IS NULL')
        ->execute([$customerId, $userId]);

    if ($subscriptionId) {
        // Fetch full subscription from Stripe
        $stripeSub = \Stripe\Subscription::retrieve($subscriptionId);
        upsert_subscription($userId, $stripeSub, $sessionId);
    } elseif ($session->mode === 'payment') {
        // One-off payment (counselling sessions)
        $productKey = $session->metadata['product_key'] ?? 'counselling';
        db()->prepare(
            'INSERT INTO subscriptions
               (user_id, stripe_customer_id, product_key, plan_type, status, stripe_checkout_session_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)'
        )->execute([$userId, $customerId, $productKey, 'counselling', 'active', $sessionId]);
    }

    audit_webhook('checkout.session.completed', $userId, ['session_id' => $sessionId]);
}

// ─────────────────────────────────────────────
// customer.subscription.updated
// ─────────────────────────────────────────────
function handle_subscription_updated(\Stripe\Subscription $stripeSub): void
{
    $customerId = $stripeSub->customer;
    $userId     = user_id_from_customer($customerId);

    if (!$userId) {
        error_log("subscription.updated: no user for customer {$customerId}");
        return;
    }

    upsert_subscription($userId, $stripeSub);
    audit_webhook('subscription.updated', $userId, [
        'subscription_id' => $stripeSub->id,
        'status'          => $stripeSub->status,
    ]);
}

// ─────────────────────────────────────────────
// customer.subscription.deleted
// ─────────────────────────────────────────────
function handle_subscription_deleted(\Stripe\Subscription $stripeSub): void
{
    $customerId = $stripeSub->customer;
    $userId     = user_id_from_customer($customerId);

    db()->prepare(
        "UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW()
         WHERE stripe_subscription_id = ?"
    )->execute([$stripeSub->id]);

    audit_webhook('subscription.deleted', $userId, ['subscription_id' => $stripeSub->id]);
}

// ─────────────────────────────────────────────
// invoice.payment_succeeded
// ─────────────────────────────────────────────
function handle_invoice_paid(\Stripe\Invoice $invoice): void
{
    $customerId = $invoice->customer;
    $userId     = user_id_from_customer($customerId);

    if (!$userId) return;

    $subId = null;
    if ($invoice->subscription) {
        $subStmt = db()->prepare('SELECT id FROM subscriptions WHERE stripe_subscription_id = ?');
        $subStmt->execute([$invoice->subscription]);
        $sub = $subStmt->fetch();
        $subId = $sub ? (int) $sub['id'] : null;
    }

    db()->prepare(
        'INSERT INTO payments
           (user_id, subscription_id, stripe_payment_intent_id, stripe_invoice_id,
            amount_cents, currency, status, description)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status)'
    )->execute([
        $userId,
        $subId,
        $invoice->payment_intent ?? null,
        $invoice->id,
        (int) $invoice->amount_paid,
        strtolower($invoice->currency ?? 'aud'),
        'succeeded',
        $invoice->description ?? null,
    ]);

    // Re-activate subscription if it was past_due
    if ($invoice->subscription) {
        db()->prepare(
            "UPDATE subscriptions SET status = 'active'
             WHERE stripe_subscription_id = ? AND status = 'past_due'"
        )->execute([$invoice->subscription]);
    }

    audit_webhook('invoice.payment_succeeded', $userId, ['invoice_id' => $invoice->id]);
}

// ─────────────────────────────────────────────
// invoice.payment_failed
// ─────────────────────────────────────────────
function handle_invoice_failed(\Stripe\Invoice $invoice): void
{
    $customerId = $invoice->customer;
    $userId     = user_id_from_customer($customerId);

    if ($invoice->subscription) {
        db()->prepare(
            "UPDATE subscriptions SET status = 'past_due'
             WHERE stripe_subscription_id = ?"
        )->execute([$invoice->subscription]);
    }

    audit_webhook('invoice.payment_failed', $userId, [
        'invoice_id'  => $invoice->id,
        'amount_due'  => $invoice->amount_due,
    ]);
}

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────

function upsert_subscription(int $userId, \Stripe\Subscription $s, ?string $sessionId = null): void
{
    $priceId = $s->items->data[0]->price->id ?? null;

    // Determine product_key from price ID
    $priceToKey = [
        'price_1TLD1mC8xESC1BMDifb6wvHd' => 'individual_monthly',
        'price_1TLDMFC8xESC1BMDRVGsCGIY' => 'individual_yearly',
        'price_1TLElkC8xESC1BMD1IkfyBQl' => 'starter_only',
        'price_1TLEnfC8xESC1BMDuM43gQNr' => 'growth_only',
        'price_1TLEpWC8xESC1BMDuTCnIF2F' => 'team_only',
        'price_1TLeUZC8xESC1BMDlaDCRwQv' => 'starter_bundle',
        'price_1TLeVDC8xESC1BMDxQFkfvDC' => 'growth_bundle',
        'price_1TLeVeC8xESC1BMD25LqYUGK' => 'team_bundle',
    ];

    $productKey = $priceToKey[$priceId] ?? 'unknown';
    $planType   = str_starts_with($productKey, 'individual') ? 'individual' : 'business';

    $periodStart = $s->current_period_start ? date('Y-m-d H:i:s', $s->current_period_start) : null;
    $periodEnd   = $s->current_period_end   ? date('Y-m-d H:i:s', $s->current_period_end)   : null;

    $existingId = subscription_row_id_from_stripe($s->id);
    if ($existingId === null && $sessionId !== null) {
        $existingId = subscription_row_id_from_checkout_session($sessionId);
    }

    if ($existingId !== null) {
        db()->prepare(
            'UPDATE subscriptions
             SET user_id = ?,
                 stripe_subscription_id = ?,
                 stripe_customer_id = ?,
                 product_key = ?,
                 plan_type = ?,
                 status = ?,
                 current_period_start = ?,
                 current_period_end = ?,
                 cancel_at_period_end = ?,
                 stripe_checkout_session_id = COALESCE(?, stripe_checkout_session_id),
                 cancelled_at = CASE WHEN ? = "cancelled" THEN COALESCE(cancelled_at, NOW()) ELSE NULL END,
                 updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $userId,
            $s->id,
            $s->customer,
            $productKey,
            $planType,
            $s->status,
            $periodStart,
            $periodEnd,
            $s->cancel_at_period_end ? 1 : 0,
            $sessionId,
            $s->status,
            $existingId,
        ]);

        return;
    }

    db()->prepare(
        'INSERT INTO subscriptions
           (user_id, stripe_subscription_id, stripe_customer_id, product_key, plan_type,
            status, current_period_start, current_period_end, cancel_at_period_end, stripe_checkout_session_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        $s->id,
        $s->customer,
        $productKey,
        $planType,
        $s->status,
        $periodStart,
        $periodEnd,
        $s->cancel_at_period_end ? 1 : 0,
        $sessionId,
    ]);
}

function user_id_from_customer(string $customerId): ?int
{
    $stmt = db()->prepare('SELECT id FROM users WHERE stripe_customer_id = ?');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

function resolve_checkout_user_id(\Stripe\Checkout\Session $session): ?int
{
    $customerId = is_string($session->customer ?? null) ? $session->customer : null;
    if ($customerId) {
        $userId = user_id_from_customer($customerId);
        if ($userId !== null) {
            return $userId;
        }
    }

    $sessionId = $session->id ?? null;
    if (is_string($sessionId) && $sessionId !== '') {
        $stmt = db()->prepare(
            'SELECT user_id FROM subscriptions WHERE stripe_checkout_session_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['user_id'];
        }
    }

    $metadataUserId = $session->metadata['user_id'] ?? null;
    if (is_string($metadataUserId) && ctype_digit($metadataUserId)) {
        return (int) $metadataUserId;
    }

    $clientReferenceId = $session->client_reference_id ?? null;
    if (is_string($clientReferenceId) && ctype_digit($clientReferenceId)) {
        return (int) $clientReferenceId;
    }

    return null;
}

function subscription_row_id_from_stripe(string $stripeSubscriptionId): ?int
{
    $stmt = db()->prepare('SELECT id FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1');
    $stmt->execute([$stripeSubscriptionId]);
    $row = $stmt->fetch();

    return $row ? (int) $row['id'] : null;
}

function subscription_row_id_from_checkout_session(string $checkoutSessionId): ?int
{
    $stmt = db()->prepare(
        'SELECT id FROM subscriptions WHERE stripe_checkout_session_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$checkoutSessionId]);
    $row = $stmt->fetch();

    return $row ? (int) $row['id'] : null;
}

function audit_webhook(string $action, ?int $userId, array $details = []): void
{
    try {
        db()->prepare(
            'INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)'
        )->execute([
            $userId,
            $action,
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('audit_webhook() failed: ' . $e->getMessage());
    }
}
