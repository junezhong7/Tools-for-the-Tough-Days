<?php
/**
 * Tools for the Tough Days — Admin Reports API
 *
 * GET /api/admin/reports.php?action=mood_usage&business_name=Acme+Pty+Ltd&start=2026-07-01&end=2026-07-15
 *
 * Workplace trial reporting: aggregate-only metrics for a single business cohort.
 * Cohort is matched by users.business_name (no organization/roster table exists yet —
 * see workplace-trial-data-spec.pdf open question re: shared org model).
 *
 * "Active" for this report = a user with that business_name who had at least one
 * session active during the period (lib/admin_auth.php has no broader activity signal
 * to draw on yet). Per the data spec's privacy rules, any cohort smaller than
 * MIN_COHORT_SIZE returns no numbers at all, individual or aggregate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

const MIN_COHORT_SIZE = 5;

header('Content-Type: application/json; charset=utf-8');

$admin = require_admin_auth();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'mood_usage':
        handle_mood_usage($admin);
        break;
    case 'business_names':
        handle_business_names();
        break;
    case 'channel_summary':
        handle_channel_summary($admin);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// BUSINESS NAME LOOKUP (autocomplete helper — business_name is free text,
// so exact-match search is otherwise painful to get right)
// ─────────────────────────────────────────────
function handle_business_names(): never
{
    $q = trim($_GET['q'] ?? '');
    $escapedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

    $stmt = db()->prepare(
        "SELECT DISTINCT business_name
         FROM users
         WHERE is_business_user = 1
           AND business_name LIKE ? ESCAPE '\\\\'
         ORDER BY business_name
         LIMIT 20"
    );
    $stmt->execute(['%' . $escapedQ . '%']);

    json_ok(['business_names' => array_column($stmt->fetchAll(), 'business_name')]);
}

// ─────────────────────────────────────────────
// MOOD SLIDER USAGE
// (total check-ins + % of active users who logged at least one)
// ─────────────────────────────────────────────
function handle_mood_usage(array $admin): never
{
    $businessName = trim($_GET['business_name'] ?? '');
    $start        = trim($_GET['start'] ?? '');
    $end          = trim($_GET['end'] ?? '');

    if ($businessName === '') {
        json_error(422, 'MISSING_BUSINESS_NAME', 'business_name is required.');
    }
    if (!is_valid_date($start) || !is_valid_date($end)) {
        json_error(422, 'INVALID_DATE_RANGE', 'start and end must be dates in YYYY-MM-DD format.');
    }
    if ($start > $end) {
        json_error(422, 'INVALID_DATE_RANGE', 'start must not be after end.');
    }

    $periodStart = $start . ' 00:00:00';
    $periodEnd   = $end . ' 23:59:59';

    $stmt = db()->prepare(
        'WITH cohort AS (
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_sessions s ON s.user_id = u.id
            WHERE u.business_name = ?
              AND s.last_active BETWEEN ? AND ?
         )
         SELECT
            (SELECT COUNT(*) FROM cohort) AS active_user_count,
            COUNT(DISTINCT m.user_id)     AS checkin_user_count,
            COUNT(m.id)                   AS total_checkins
         FROM cohort c
         LEFT JOIN mood_events m
           ON m.user_id = c.id AND m.checkin_at BETWEEN ? AND ?'
    );
    $stmt->execute([$businessName, $periodStart, $periodEnd, $periodStart, $periodEnd]);
    $row = $stmt->fetch();

    $activeUserCount = (int) $row['active_user_count'];

    audit('admin.report.mood_usage', null, [
        'business_name' => $businessName,
        'start'         => $start,
        'end'           => $end,
    ], (int) $admin['id']);

    if ($activeUserCount < MIN_COHORT_SIZE) {
        json_ok([
            'business_name'     => $businessName,
            'period'            => ['start' => $start, 'end' => $end],
            'insufficient_data' => true,
            'message'           => 'Insufficient data for this period',
        ]);
    }

    $checkinUserCount = (int) $row['checkin_user_count'];
    $totalCheckins    = (int) $row['total_checkins'];

    json_ok([
        'business_name'     => $businessName,
        'period'            => ['start' => $start, 'end' => $end],
        'insufficient_data' => false,
        'metrics'           => [
            'active_user_count'             => $activeUserCount,
            'total_checkins'                => $totalCheckins,
            'checkin_user_count'            => $checkinUserCount,
            'pct_active_users_with_checkin' => round($checkinUserCount / $activeUserCount * 100, 1),
        ],
    ]);
}

// ─────────────────────────────────────────────
// CHANNEL / UTM SUMMARY
// (signups + CTA clicks by source — aggregate marketing-funnel data,
// not per-individual behaviour, so MIN_COHORT_SIZE does not apply here)
// ─────────────────────────────────────────────
function handle_channel_summary(array $admin): never
{
    $start = trim($_GET['start'] ?? '');
    $end   = trim($_GET['end'] ?? '');

    if (!is_valid_date($start) || !is_valid_date($end)) {
        json_error(422, 'INVALID_DATE_RANGE', 'start and end must be dates in YYYY-MM-DD format.');
    }
    if ($start > $end) {
        json_error(422, 'INVALID_DATE_RANGE', 'start must not be after end.');
    }

    $periodStart = $start . ' 00:00:00';
    $periodEnd   = $end . ' 23:59:59';

    $signupStmt = db()->prepare(
        "SELECT
            COALESCE(utm_source, '(none)')   AS utm_source,
            COALESCE(utm_campaign, '(none)') AS utm_campaign,
            COUNT(*) AS signup_count
         FROM users
         WHERE created_at BETWEEN ? AND ?
         GROUP BY utm_source, utm_campaign
         ORDER BY signup_count DESC"
    );
    $signupStmt->execute([$periodStart, $periodEnd]);
    $signups = $signupStmt->fetchAll();

    $clickStmt = db()->prepare(
        "SELECT
            action,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(details, '$.utm_source')), '(none)') AS utm_source,
            COUNT(*) AS click_count
         FROM audit_logs
         WHERE action IN ('cta.trial_click', 'cta.booking_click')
           AND created_at BETWEEN ? AND ?
         GROUP BY action, utm_source
         ORDER BY click_count DESC"
    );
    $clickStmt->execute([$periodStart, $periodEnd]);
    $clicks = $clickStmt->fetchAll();

    audit('admin.report.channel_summary', null, ['start' => $start, 'end' => $end], (int) $admin['id']);

    json_ok([
        'period'                => ['start' => $start, 'end' => $end],
        'signups_by_channel'    => $signups,
        'cta_clicks_by_channel' => $clicks,
    ]);
}

function is_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $value));
    return checkdate($m, $d, $y);
}
