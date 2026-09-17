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
 *
 * GET /api/admin/reports.php?action=personal_usage&user_id=123&start=2026-07-01&end=2026-07-15
 *
 * Personal reports: single-member mood check-in and resource-access history, for
 * support/duty-of-care purposes. This is individual-level data, at the same trust
 * level as the per-member subscription/payment view in the member admin panel —
 * MIN_COHORT_SIZE does not apply here.
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
    case 'user_search':
        handle_user_search();
        break;
    case 'personal_usage':
        handle_personal_usage($admin);
        break;
    case 'aggregate_usage':
        handle_aggregate_usage($admin);
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

// ─────────────────────────────────────────────
// PERSONAL REPORTS — single-member mood & resource-access usage
// (individual detail, same trust level as the member admin panel's per-member
// subscription/payment view — not subject to MIN_COHORT_SIZE, which exists
// specifically to keep the workplace-trial cohort reports aggregate-only)
// ─────────────────────────────────────────────
function handle_user_search(): never
{
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        json_ok(['users' => []]);
    }

    $escapedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $like = '%' . $escapedQ . '%';

    $stmt = db()->prepare(
        "SELECT id, email, full_name, is_business_user, business_name
         FROM users
         WHERE email LIKE ? ESCAPE '\\\\' OR full_name LIKE ? ESCAPE '\\\\'
         ORDER BY email
         LIMIT 20"
    );
    $stmt->execute([$like, $like]);

    $users = array_map(static function (array $row): array {
        return [
            'id'               => (int) $row['id'],
            'email'            => $row['email'],
            'full_name'        => $row['full_name'],
            'is_business_user' => (bool) $row['is_business_user'],
            'business_name'    => $row['business_name'],
        ];
    }, $stmt->fetchAll());

    json_ok(['users' => $users]);
}

function handle_personal_usage(array $admin): never
{
    $userId = (int) ($_GET['user_id'] ?? 0);
    $start  = trim($_GET['start'] ?? '');
    $end    = trim($_GET['end'] ?? '');

    if ($userId <= 0) {
        json_error(422, 'MISSING_USER', 'user_id is required.');
    }
    if (!is_valid_date($start) || !is_valid_date($end)) {
        json_error(422, 'INVALID_DATE_RANGE', 'start and end must be dates in YYYY-MM-DD format.');
    }
    if ($start > $end) {
        json_error(422, 'INVALID_DATE_RANGE', 'start must not be after end.');
    }

    $userStmt = db()->prepare('SELECT id, email, full_name, business_name FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        json_error(404, 'NOT_FOUND', 'Member not found.');
    }

    $periodStart = $start . ' 00:00:00';
    $periodEnd   = $end . ' 23:59:59';

    $moodStmt = db()->prepare(
        'SELECT mood_score, source_page, checkin_at
         FROM mood_events
         WHERE user_id = ? AND checkin_at BETWEEN ? AND ?
         ORDER BY checkin_at DESC
         LIMIT 500'
    );
    $moodStmt->execute([$userId, $periodStart, $periodEnd]);
    $moodRecords = array_map(static function (array $row): array {
        return [
            'score'       => (int) $row['mood_score'],
            'source_page' => $row['source_page'],
            'checkin_at'  => $row['checkin_at'],
        ];
    }, $moodStmt->fetchAll());

    $moodSummaryStmt = db()->prepare(
        'SELECT COUNT(*) AS total_checkins,
                ROUND(AVG(mood_score), 2) AS avg_score,
                MIN(mood_score) AS min_score,
                MAX(mood_score) AS max_score
         FROM mood_events
         WHERE user_id = ? AND checkin_at BETWEEN ? AND ?'
    );
    $moodSummaryStmt->execute([$userId, $periodStart, $periodEnd]);
    $moodSummary = $moodSummaryStmt->fetch() ?: [];

    // "resource.issue.ok" fires every time the member opens a PDF/video, so it's the
    // cleanest single signal for "accessed this resource" (list/topics browsing is not access).
    $resourceStmt = db()->prepare(
        "SELECT
            JSON_UNQUOTE(JSON_EXTRACT(details, '$.resource_key')) AS resource_key,
            JSON_UNQUOTE(JSON_EXTRACT(details, '$.kind'))         AS kind,
            JSON_UNQUOTE(JSON_EXTRACT(details, '$.catalog'))      AS catalog,
            created_at
         FROM audit_logs
         WHERE user_id = ?
           AND action = 'resource.issue.ok'
           AND created_at BETWEEN ? AND ?
         ORDER BY created_at DESC
         LIMIT 500"
    );
    $resourceStmt->execute([$userId, $periodStart, $periodEnd]);
    $resourceRecords = array_map(static function (array $row): array {
        return [
            'resource_key' => $row['resource_key'],
            'kind'         => $row['kind'],
            'catalog'      => $row['catalog'],
            'accessed_at'  => $row['created_at'],
        ];
    }, $resourceStmt->fetchAll());

    $resourceSummaryStmt = db()->prepare(
        "SELECT
            COUNT(*) AS total_opens,
            COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(details, '$.resource_key'))) AS unique_resources
         FROM audit_logs
         WHERE user_id = ?
           AND action = 'resource.issue.ok'
           AND created_at BETWEEN ? AND ?"
    );
    $resourceSummaryStmt->execute([$userId, $periodStart, $periodEnd]);
    $resourceSummary = $resourceSummaryStmt->fetch() ?: [];

    audit('admin.report.personal_usage', $userId, [
        'start' => $start,
        'end'   => $end,
    ], (int) $admin['id']);

    json_ok([
        'user' => [
            'id'            => (int) $user['id'],
            'email'         => $user['email'],
            'full_name'     => $user['full_name'],
            'business_name' => $user['business_name'],
        ],
        'period' => ['start' => $start, 'end' => $end],
        'mood' => [
            'summary' => [
                'total_checkins' => (int) ($moodSummary['total_checkins'] ?? 0),
                'avg_score'      => isset($moodSummary['avg_score']) ? (float) $moodSummary['avg_score'] : null,
                'min_score'      => isset($moodSummary['min_score']) ? (int) $moodSummary['min_score'] : null,
                'max_score'      => isset($moodSummary['max_score']) ? (int) $moodSummary['max_score'] : null,
            ],
            'records' => $moodRecords,
        ],
        'resource_access' => [
            'summary' => [
                'total_opens'      => (int) ($resourceSummary['total_opens'] ?? 0),
                'unique_resources' => (int) ($resourceSummary['unique_resources'] ?? 0),
            ],
            'records' => $resourceRecords,
        ],
    ]);
}

// ─────────────────────────────────────────────
// AGGREGATE USAGE STATISTICS
// (Mood slider + resource-access averages across many members — same aggregate-only
// shape as the Workplace mood usage report, but not limited to one business cohort.
// business_name is optional here: leave blank for a sitewide aggregate, or set it to
// scope to one business, same cohort/"active user" definition as handle_mood_usage.)
// ─────────────────────────────────────────────
function handle_aggregate_usage(array $admin): never
{
    $businessName = trim($_GET['business_name'] ?? '');
    $start        = trim($_GET['start'] ?? '');
    $end          = trim($_GET['end'] ?? '');

    if (!is_valid_date($start) || !is_valid_date($end)) {
        json_error(422, 'INVALID_DATE_RANGE', 'start and end must be dates in YYYY-MM-DD format.');
    }
    if ($start > $end) {
        json_error(422, 'INVALID_DATE_RANGE', 'start must not be after end.');
    }

    $periodStart = $start . ' 00:00:00';
    $periodEnd   = $end . ' 23:59:59';

    $cohortFilter = $businessName !== '' ? 'AND u.business_name = ?' : '';

    $moodStmt = db()->prepare(
        "WITH cohort AS (
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_sessions s ON s.user_id = u.id
            WHERE s.last_active BETWEEN ? AND ? {$cohortFilter}
         )
         SELECT
            (SELECT COUNT(*) FROM cohort)  AS active_user_count,
            COUNT(DISTINCT m.user_id)      AS checkin_user_count,
            COUNT(m.id)                    AS total_checkins,
            ROUND(AVG(m.mood_score), 2)    AS avg_mood_score
         FROM cohort c
         LEFT JOIN mood_events m
           ON m.user_id = c.id AND m.checkin_at BETWEEN ? AND ?"
    );
    $moodParams = [$periodStart, $periodEnd];
    if ($businessName !== '') {
        $moodParams[] = $businessName;
    }
    $moodParams[] = $periodStart;
    $moodParams[] = $periodEnd;
    $moodStmt->execute($moodParams);
    $moodRow = $moodStmt->fetch();

    $activeUserCount = (int) $moodRow['active_user_count'];

    audit('admin.report.aggregate_usage', null, [
        'business_name' => $businessName !== '' ? $businessName : null,
        'start'         => $start,
        'end'           => $end,
    ], (int) $admin['id']);

    if ($activeUserCount < MIN_COHORT_SIZE) {
        json_ok([
            'business_name'     => $businessName !== '' ? $businessName : null,
            'period'            => ['start' => $start, 'end' => $end],
            'insufficient_data' => true,
            'message'           => 'Insufficient data for this period',
        ]);
    }

    $resourceStmt = db()->prepare(
        "WITH cohort AS (
            SELECT DISTINCT u.id
            FROM users u
            JOIN user_sessions s ON s.user_id = u.id
            WHERE s.last_active BETWEEN ? AND ? {$cohortFilter}
         )
         SELECT
            COUNT(DISTINCT a.user_id) AS resource_user_count,
            COUNT(a.id)               AS total_resource_opens,
            COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.resource_key'))) AS unique_resources_opened
         FROM cohort c
         LEFT JOIN audit_logs a
           ON a.user_id = c.id AND a.action = 'resource.issue.ok' AND a.created_at BETWEEN ? AND ?"
    );
    $resourceParams = [$periodStart, $periodEnd];
    if ($businessName !== '') {
        $resourceParams[] = $businessName;
    }
    $resourceParams[] = $periodStart;
    $resourceParams[] = $periodEnd;
    $resourceStmt->execute($resourceParams);
    $resourceRow = $resourceStmt->fetch();

    $checkinUserCount     = (int) $moodRow['checkin_user_count'];
    $totalCheckins        = (int) $moodRow['total_checkins'];
    $resourceUserCount    = (int) $resourceRow['resource_user_count'];
    $totalResourceOpens   = (int) $resourceRow['total_resource_opens'];
    $uniqueResourcesOpened = (int) $resourceRow['unique_resources_opened'];

    json_ok([
        'business_name'     => $businessName !== '' ? $businessName : null,
        'period'            => ['start' => $start, 'end' => $end],
        'insufficient_data' => false,
        'metrics'           => [
            'active_user_count'                   => $activeUserCount,
            'total_checkins'                       => $totalCheckins,
            'checkin_user_count'                   => $checkinUserCount,
            'avg_mood_score'                        => isset($moodRow['avg_mood_score']) ? (float) $moodRow['avg_mood_score'] : null,
            'avg_checkins_per_active_user'          => round($totalCheckins / $activeUserCount, 2),
            'pct_active_users_with_checkin'         => round($checkinUserCount / $activeUserCount * 100, 1),
            'total_resource_opens'                  => $totalResourceOpens,
            'resource_user_count'                   => $resourceUserCount,
            'unique_resources_opened'                => $uniqueResourcesOpened,
            'avg_resource_opens_per_active_user'    => round($totalResourceOpens / $activeUserCount, 2),
            'pct_active_users_with_resource_open'   => round($resourceUserCount / $activeUserCount * 100, 1),
        ],
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
