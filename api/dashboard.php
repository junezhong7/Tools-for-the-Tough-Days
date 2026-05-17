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
         FROM subscriptions
         WHERE user_id = ?
             AND (
                 status <> "pending"
                 OR created_at >= (NOW() - INTERVAL 30 MINUTE)
             )
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

$mood = [
    'daily' => [],
    'summary' => [
        'total_checkins_30' => 0,
        'avg_score_30' => null,
        'min_score_30' => null,
        'max_score_30' => null,
    ],
    'open_alerts' => [],
];

try {
    $dailyStmt = db()->prepare(
        'SELECT DATE(me.checkin_at) AS score_date,
                SUBSTRING_INDEX(GROUP_CONCAT(me.mood_score ORDER BY me.checkin_at DESC, me.id DESC), ",", 1) AS mood_score
         FROM mood_events me
         WHERE me.user_id = ?
           AND me.checkin_at >= (CURDATE() - INTERVAL 365 DAY)
         GROUP BY DATE(me.checkin_at)
         ORDER BY score_date DESC'
    );
    $dailyStmt->execute([$userId]);
    foreach ($dailyStmt->fetchAll() as $row) {
        $mood['daily'][] = [
            'date' => (string) $row['score_date'],
            'score' => (int) $row['mood_score'],
        ];
    }

    $summaryStmt = db()->prepare(
        'SELECT
            COUNT(*) AS total_checkins,
            ROUND(AVG(mood_score), 2) AS avg_score_30,
            MIN(mood_score) AS min_score_30,
            MAX(mood_score) AS max_score_30
         FROM mood_events
         WHERE user_id = ?
           AND checkin_at >= (CURDATE() - INTERVAL 30 DAY)'
    );
    $summaryStmt->execute([$userId]);
    $summaryRow = $summaryStmt->fetch() ?: [];
    $mood['summary'] = [
        'total_checkins_30' => (int) ($summaryRow['total_checkins'] ?? 0),
        'avg_score_30' => isset($summaryRow['avg_score_30']) ? (float) $summaryRow['avg_score_30'] : null,
        'min_score_30' => isset($summaryRow['min_score_30']) ? (int) $summaryRow['min_score_30'] : null,
        'max_score_30' => isset($summaryRow['max_score_30']) ? (int) $summaryRow['max_score_30'] : null,
    ];

    $alertsStmt = db()->prepare(
        'SELECT id, alert_type, status, rule_window_start, rule_window_end, meta, triggered_at
         FROM mood_alerts
         WHERE user_id = ? AND status = "open"
         ORDER BY triggered_at DESC'
    );
    $alertsStmt->execute([$userId]);
    foreach ($alertsStmt->fetchAll() as $row) {
        $mood['open_alerts'][] = [
            'id' => (int) $row['id'],
            'type' => (string) $row['alert_type'],
            'status' => (string) $row['status'],
            'window_start' => $row['rule_window_start'],
            'window_end' => $row['rule_window_end'],
            'meta' => json_decode((string) ($row['meta'] ?? ''), true) ?? [],
            'triggered_at' => $row['triggered_at'],
        ];
    }
} catch (Throwable $e) {
    // Keep dashboard available even if mood tables are not migrated yet.
    error_log('dashboard mood query failed: ' . $e->getMessage());
}

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
    'mood'          => $mood,
]);
