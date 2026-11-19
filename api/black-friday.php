<?php
/**
 * Tools for the Tough Days — Black Friday 30-day pass status
 *
 * GET /api/black-friday.php
 *
 * Public. Tells black-friday.html whether the offer is open and, for a signed-in
 * visitor, whether they can buy it and when an existing pass ends. Never errors on a
 * missing session: signed-out visitors simply get signed_in = false.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/black_friday.php';

header('Cache-Control: no-store');

[$start, $end] = black_friday_window();
$data = [
    'state'       => black_friday_state(),
    'starts_at'   => $start->format(DATE_ATOM),
    'ends_at'     => $end->format(DATE_ATOM),
    'price_cents' => BLACK_FRIDAY_PRICE_CENTS,
    'days'        => BLACK_FRIDAY_PASS_DAYS,
    'signed_in'   => false,
    'eligibility' => null,   // null = can buy; 'member' | 'purchased'
    'pass_ends_at' => null,  // UTC DATETIME of the buyer's pass, if any
];

$user = current_user();
if ($user) {
    $userId = (int) $user['id'];
    $data['signed_in'] = true;
    try {
        $data['eligibility'] = black_friday_ineligibility($userId);
        $stmt = db()->prepare(
            "SELECT current_period_end FROM subscriptions
             WHERE user_id = ? AND product_key = ? AND status = 'active'
             ORDER BY current_period_end DESC LIMIT 1"
        );
        $stmt->execute([$userId, BLACK_FRIDAY_PRODUCT_KEY]);
        $passEnd = $stmt->fetchColumn();
        $data['pass_ends_at'] = $passEnd !== false ? (string) $passEnd : null;
    } catch (Throwable $e) {
        error_log('black-friday status error: ' . $e->getMessage());
    }
}

json_ok($data);
