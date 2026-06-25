<?php
/**
 * Tools for the Tough Days — Mood API
 *
 * POST /api/mood.php?action=submit
 * GET  /api/mood.php?action=history
 * GET  /api/mood.php?action=alerts
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mailer.php';

const MOOD_BRISBANE_OFFSET_HOURS = 10;

$user = require_auth();
$userId = (int) ($user['id'] ?? 0);

$action = strtolower(trim((string) ($_GET['action'] ?? 'history')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true) ?? [];
    }
    if (empty($body)) {
        $body = $_POST;
    }
}

switch ($action) {
    case 'submit':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_submit($userId, $body);
        break;
    case 'history':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_history($userId);
        break;
    case 'alerts':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_alerts($userId);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

function handle_submit(int $userId, array $body): never
{
    $score = (int) ($body['score'] ?? 0);
    if ($score < 1 || $score > 10) {
        json_error(422, 'INVALID_SCORE', 'Score must be between 1 and 10.');
    }

    $sourcePage = trim((string) ($body['page'] ?? 'support.html'));
    if ($sourcePage === '') {
        $sourcePage = 'support.html';
    }
    if (strlen($sourcePage) > 64) {
        $sourcePage = substr($sourcePage, 0, 64);
    }

    $clientTs = null;
    if (!empty($body['client_ts']) && is_string($body['client_ts'])) {
        try {
            $dt = new DateTimeImmutable($body['client_ts']);
            $clientTs = $dt->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $clientTs = null;
        }
    }

    $checkinAtUtc = gmdate('Y-m-d H:i:s');

    db()->prepare(
        'INSERT INTO mood_events (user_id, mood_score, source_page, client_ts, checkin_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $score, $sourcePage, $clientTs, $checkinAtUtc]);

    audit('mood.submit', $userId, [
        'score' => $score,
        'source_page' => $sourcePage,
    ]);

    $alerts = evaluate_mood_rules($userId);

    maybe_send_milestone_email($userId);

    json_ok([
        'ok' => true,
        'score' => $score,
        'submitted_at' => gmdate('c'),
        'alerts' => $alerts,
    ], 201);
}

function maybe_send_milestone_email(int $userId): void
{
    static $milestones = [3, 7, 14, 21, 30];

    $stmt = db()->prepare('SELECT COUNT(*) FROM mood_events WHERE user_id = ?');
    $stmt->execute([$userId]);
    $total = (int) ($stmt->fetchColumn() ?: 0);

    if (!in_array($total, $milestones, true)) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id FROM milestone_sends WHERE user_id = ? AND milestone_count = ?'
    );
    $stmt->execute([$userId, $total]);
    if ($stmt->fetch()) {
        return;
    }

    $stmt = db()->prepare('SELECT email, full_name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch();
    if (!$userRow) {
        return;
    }

    $sent = send_milestone_email((string) $userRow['email'], $userRow['full_name'] ?? null, $total);

    if ($sent) {
        db()->prepare(
            'INSERT IGNORE INTO milestone_sends (user_id, milestone_count) VALUES (?, ?)'
        )->execute([$userId, $total]);
        audit('mood.milestone_email', $userId, ['count' => $total]);
    }
}

function handle_history(int $userId): never
{
    $days = max(7, min(365, (int) ($_GET['days'] ?? 120)));
    json_ok([
        'days' => $days,
        'daily' => get_daily_scores($userId, $days),
        'summary' => get_summary($userId),
    ]);
}

function handle_alerts(int $userId): never
{
    json_ok([
        'open_alerts' => get_open_alerts($userId),
    ]);
}

function get_daily_scores(int $userId, int $days): array
{
        $brisbaneCutoffDate = (new DateTimeImmutable('now', new DateTimeZone('Australia/Brisbane')))
                ->modify('-' . $days . ' days')
                ->format('Y-m-d');

    $stmt = db()->prepare(
                'SELECT DATE(DATE_ADD(me.checkin_at, INTERVAL ' . MOOD_BRISBANE_OFFSET_HOURS . ' HOUR)) AS score_date,
                SUBSTRING_INDEX(GROUP_CONCAT(me.mood_score ORDER BY me.checkin_at DESC, me.id DESC), ",", 1) AS mood_score
         FROM mood_events me
         WHERE me.user_id = ?
                     AND DATE(DATE_ADD(me.checkin_at, INTERVAL ' . MOOD_BRISBANE_OFFSET_HOURS . ' HOUR)) >= ?
                 GROUP BY DATE(DATE_ADD(me.checkin_at, INTERVAL ' . MOOD_BRISBANE_OFFSET_HOURS . ' HOUR))
         ORDER BY score_date DESC'
    );
        $stmt->execute([$userId, $brisbaneCutoffDate]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'date' => (string) $row['score_date'],
            'score' => (int) $row['mood_score'],
        ];
    }

    return $rows;
}

function get_open_alerts(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, alert_type, status, rule_window_start, rule_window_end, meta, triggered_at
         FROM mood_alerts
         WHERE user_id = ? AND status = "open"
         ORDER BY triggered_at DESC'
    );
    $stmt->execute([$userId]);

    $alerts = [];
    foreach ($stmt->fetchAll() as $row) {
        $alerts[] = [
            'id' => (int) $row['id'],
            'type' => (string) $row['alert_type'],
            'status' => (string) $row['status'],
            'window_start' => $row['rule_window_start'],
            'window_end' => $row['rule_window_end'],
            'meta' => json_decode((string) ($row['meta'] ?? ''), true) ?? [],
            'triggered_at' => $row['triggered_at'],
        ];
    }

    return $alerts;
}

function get_summary(int $userId): array
{
    $brisbaneCutoffDate = (new DateTimeImmutable('now', new DateTimeZone('Australia/Brisbane')))
        ->modify('-30 days')
        ->format('Y-m-d');

    $stmt = db()->prepare(
        'SELECT
            COUNT(*) AS total_checkins,
            ROUND(AVG(mood_score), 2) AS avg_score_30,
            MIN(mood_score) AS min_score_30,
            MAX(mood_score) AS max_score_30
         FROM mood_events
         WHERE user_id = ?
           AND DATE(DATE_ADD(checkin_at, INTERVAL ' . MOOD_BRISBANE_OFFSET_HOURS . ' HOUR)) >= ?'
    );
    $stmt->execute([$userId, $brisbaneCutoffDate]);
    $row = $stmt->fetch() ?: [];

    return [
        'total_checkins_30' => (int) ($row['total_checkins'] ?? 0),
        'avg_score_30' => isset($row['avg_score_30']) ? (float) $row['avg_score_30'] : null,
        'min_score_30' => isset($row['min_score_30']) ? (int) $row['min_score_30'] : null,
        'max_score_30' => isset($row['max_score_30']) ? (int) $row['max_score_30'] : null,
    ];
}

function evaluate_mood_rules(int $userId): array
{
    $daily = get_daily_scores($userId, 8);
    $alerts = [];

    $lowStreak = detect_low_streak($daily);
    if ($lowStreak !== null) {
        $alerts[] = open_or_refresh_alert($userId, 'low_streak', $lowStreak);
    } else {
        resolve_alert($userId, 'low_streak');
    }

    $downward = detect_downward_trend($daily);
    if ($downward !== null) {
        $alerts[] = open_or_refresh_alert($userId, 'downward_trend', $downward);
    } else {
        resolve_alert($userId, 'downward_trend');
    }

    return $alerts;
}

function detect_low_streak(array $daily): ?array
{
    if (count($daily) < 3) {
        return null;
    }

    $a = $daily[0];
    $b = $daily[1];
    $c = $daily[2];

    if ((int) $a['score'] > 3 || (int) $b['score'] > 3 || (int) $c['score'] > 3) {
        return null;
    }

    $dateA = new DateTimeImmutable((string) $a['date']);
    $dateB = new DateTimeImmutable((string) $b['date']);
    $dateC = new DateTimeImmutable((string) $c['date']);

    if ($dateA->diff($dateB)->days !== 1 || $dateB->diff($dateC)->days !== 1) {
        return null;
    }

    return [
        'window_start' => (string) $c['date'],
        'window_end' => (string) $a['date'],
        'meta' => [
            'scores' => [(int) $c['score'], (int) $b['score'], (int) $a['score']],
            'rule' => '3_consecutive_days_below_or_equal_3',
        ],
    ];
}

function detect_downward_trend(array $daily): ?array
{
    if (count($daily) < 2) {
        return null;
    }

    $window = array_slice($daily, 0, min(7, count($daily)));
    $latest = $window[0];
    $oldest = $window[count($window) - 1];

    $drop = (int) $oldest['score'] - (int) $latest['score'];
    if ($drop < 3) {
        return null;
    }

    return [
        'window_start' => (string) $oldest['date'],
        'window_end' => (string) $latest['date'],
        'meta' => [
            'start_score' => (int) $oldest['score'],
            'end_score' => (int) $latest['score'],
            'drop' => $drop,
            'rule' => '7_day_drop_greater_or_equal_3',
        ],
    ];
}

function open_or_refresh_alert(int $userId, string $type, array $payload): array
{
    $stmt = db()->prepare(
        'SELECT id FROM mood_alerts WHERE user_id = ? AND alert_type = ? AND status = "open" ORDER BY triggered_at DESC LIMIT 1'
    );
    $stmt->execute([$userId, $type]);
    $row = $stmt->fetch();

    $metaJson = json_encode($payload['meta'] ?? [], JSON_UNESCAPED_UNICODE);

    if ($row) {
        db()->prepare(
            'UPDATE mood_alerts
             SET rule_window_start = ?, rule_window_end = ?, meta = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $payload['window_start'] ?? null,
            $payload['window_end'] ?? null,
            $metaJson,
            (int) $row['id'],
        ]);

        return [
            'type' => $type,
            'status' => 'open',
            'window_start' => $payload['window_start'] ?? null,
            'window_end' => $payload['window_end'] ?? null,
            'meta' => $payload['meta'] ?? [],
            'new' => false,
        ];
    }

    db()->prepare(
        'INSERT INTO mood_alerts (user_id, alert_type, status, rule_window_start, rule_window_end, meta)
         VALUES (?, ?, "open", ?, ?, ?)'
    )->execute([
        $userId,
        $type,
        $payload['window_start'] ?? null,
        $payload['window_end'] ?? null,
        $metaJson,
    ]);

    audit('mood.alert.triggered', $userId, [
        'alert_type' => $type,
        'window_start' => $payload['window_start'] ?? null,
        'window_end' => $payload['window_end'] ?? null,
        'meta' => $payload['meta'] ?? [],
    ]);

    return [
        'type' => $type,
        'status' => 'open',
        'window_start' => $payload['window_start'] ?? null,
        'window_end' => $payload['window_end'] ?? null,
        'meta' => $payload['meta'] ?? [],
        'new' => true,
    ];
}

function resolve_alert(int $userId, string $type): void
{
    $stmt = db()->prepare(
        'SELECT id FROM mood_alerts WHERE user_id = ? AND alert_type = ? AND status = "open" ORDER BY triggered_at DESC LIMIT 1'
    );
    $stmt->execute([$userId, $type]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    db()->prepare(
        'UPDATE mood_alerts SET status = "resolved", resolved_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([(int) $row['id']]);

    audit('mood.alert.resolved', $userId, [
        'alert_type' => $type,
        'alert_id' => (int) $row['id'],
    ]);
}
