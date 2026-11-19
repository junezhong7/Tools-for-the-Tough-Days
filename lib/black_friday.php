<?php
/**
 * Tools for the Tough Days — Black Friday 30-day pass
 *
 * Offer (Sales Activation Plan, Nov 2026): $19 AUD, one-time payment, 30 days of full
 * platform access, no auto-renewal. Sold only inside the campaign window, one per account.
 *
 * Access is granted by a normal `subscriptions` row (product_key = black_friday_30d,
 * status = active, cancel_at_period_end = 1) with a fixed current_period_end, so every
 * existing access check (current_period_end > NOW()) works unchanged and access simply
 * lapses after 30 days. The 30 days start when the buyer's current access ends, so a
 * free trial or earlier access is never swallowed by the pass.
 *
 * Window overrides (for testing on sand), ISO 8601 with offset:
 *   BLACK_FRIDAY_START=2026-11-19T00:00:00+10:00
 *   BLACK_FRIDAY_END=2026-12-01T00:00:00+10:00   (exclusive)
 */

declare(strict_types=1);

const BLACK_FRIDAY_PRODUCT_KEY = 'black_friday_30d';
const BLACK_FRIDAY_PRICE_CENTS = 1900;
const BLACK_FRIDAY_PASS_DAYS = 30;
const BLACK_FRIDAY_PRODUCT_NAME = 'Tools for the Tough Days: 30 days of full access';
const BLACK_FRIDAY_TIMEZONE = 'Australia/Brisbane';
// 27 Nov 2026 00:00 to 30 Nov 2026 23:59:59, Brisbane time (end is exclusive).
const BLACK_FRIDAY_DEFAULT_START = '2026-11-27T00:00:00+10:00';
const BLACK_FRIDAY_DEFAULT_END = '2026-12-01T00:00:00+10:00';

/** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end) */
function black_friday_window(): array
{
    $parse = static function (string $envName, string $default): DateTimeImmutable {
        $raw = trim((string) (getenv($envName) ?: ''));
        if ($raw !== '') {
            try {
                return new DateTimeImmutable($raw);
            } catch (Throwable $e) {
                error_log("{$envName} is not a valid date ({$raw}); using the default.");
            }
        }
        return new DateTimeImmutable($default);
    };

    return [
        $parse('BLACK_FRIDAY_START', BLACK_FRIDAY_DEFAULT_START),
        $parse('BLACK_FRIDAY_END', BLACK_FRIDAY_DEFAULT_END),
    ];
}

/** 'upcoming' | 'open' | 'ended' */
function black_friday_state(?DateTimeImmutable $now = null): string
{
    [$start, $end] = black_friday_window();
    $now = $now ?? new DateTimeImmutable('now');
    if ($now < $start) {
        return 'upcoming';
    }
    return $now < $end ? 'open' : 'ended';
}

/**
 * Why this user cannot buy the pass, or null if they can.
 * 'member'    — has an active auto-renewing Stripe subscription (already has full access)
 * 'purchased' — already bought the pass (one per account)
 */
function black_friday_ineligibility(int $userId): ?string
{
    $stmt = db()->prepare(
        "SELECT 1 FROM subscriptions
         WHERE user_id = ?
           AND stripe_subscription_id IS NOT NULL
           AND status IN ('active', 'trialing', 'past_due')
           AND (current_period_end IS NULL OR current_period_end > NOW())
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn()) {
        return 'member';
    }

    $stmt = db()->prepare(
        "SELECT 1 FROM subscriptions
         WHERE user_id = ? AND product_key = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$userId, BLACK_FRIDAY_PRODUCT_KEY]);
    if ($stmt->fetchColumn()) {
        return 'purchased';
    }

    return null;
}

/**
 * Grant the 30-day pass for a paid Checkout Session. Idempotent per session: Stripe
 * retries webhooks, and a second run must not add another 30 days.
 *
 * @return array{granted: bool, subscription_id: ?int, period_end: ?string}
 */
function black_friday_grant_pass(
    int $userId,
    string $checkoutSessionId,
    ?string $customerId,
    ?string $paymentIntentId,
    int $amountCents,
    string $currency
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock this user's rows so two concurrent deliveries can't both stack.
        $rows = $pdo->prepare('SELECT id, product_key, status, stripe_checkout_session_id, current_period_end
                               FROM subscriptions WHERE user_id = ? FOR UPDATE');
        $rows->execute([$userId]);
        $existing = null;
        foreach ($rows->fetchAll() as $row) {
            if ($row['stripe_checkout_session_id'] === $checkoutSessionId) {
                $existing = $row;
            }
        }
        if ($existing && $existing['status'] === 'active') {
            $pdo->commit();
            return ['granted' => false, 'subscription_id' => (int) $existing['id'], 'period_end' => $existing['current_period_end']];
        }

        // Start when current access (trial, earlier pass, cancelled-but-paid-up plan) runs out.
        $startStmt = $pdo->prepare(
            "SELECT GREATEST(NOW(), COALESCE(MAX(current_period_end), NOW()))
             FROM subscriptions
             WHERE user_id = ?
               AND status IN ('active', 'trialing', 'past_due')
               AND current_period_end > NOW()"
        );
        $startStmt->execute([$userId]);
        $periodStart = (string) $startStmt->fetchColumn();

        if ($existing) {
            $pdo->prepare(
                "UPDATE subscriptions
                 SET status = 'active', product_key = ?, plan_type = 'individual', stripe_customer_id = ?,
                     current_period_start = ?, current_period_end = DATE_ADD(?, INTERVAL " . BLACK_FRIDAY_PASS_DAYS . " DAY),
                     cancel_at_period_end = 1, cancelled_at = NULL, updated_at = NOW()
                 WHERE id = ?"
            )->execute([BLACK_FRIDAY_PRODUCT_KEY, $customerId, $periodStart, $periodStart, $existing['id']]);
            $subscriptionId = (int) $existing['id'];
        } else {
            $pdo->prepare(
                "INSERT INTO subscriptions
                   (user_id, stripe_customer_id, product_key, plan_type, status,
                    current_period_start, current_period_end, cancel_at_period_end, stripe_checkout_session_id)
                 VALUES (?, ?, ?, 'individual', 'active', ?, DATE_ADD(?, INTERVAL " . BLACK_FRIDAY_PASS_DAYS . " DAY), 1, ?)"
            )->execute([$userId, $customerId, BLACK_FRIDAY_PRODUCT_KEY, $periodStart, $periodStart, $checkoutSessionId]);
            $subscriptionId = (int) $pdo->lastInsertId();
        }

        // One-off payments create no invoice, so record the payment here for the dashboard.
        $pdo->prepare(
            "INSERT INTO payments
               (user_id, subscription_id, stripe_payment_intent_id, amount_cents, currency, status, description)
             VALUES (?, ?, ?, ?, ?, 'succeeded', ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        )->execute([$userId, $subscriptionId, $paymentIntentId, $amountCents, strtolower($currency), '30-day pass (Black Friday)']);

        $endStmt = $pdo->prepare('SELECT current_period_end FROM subscriptions WHERE id = ?');
        $endStmt->execute([$subscriptionId]);
        $periodEnd = (string) $endStmt->fetchColumn();

        $pdo->commit();
        return ['granted' => true, 'subscription_id' => $subscriptionId, 'period_end' => $periodEnd];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
