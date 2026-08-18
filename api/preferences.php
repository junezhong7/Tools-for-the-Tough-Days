<?php
/**
 * Tools for the Tough Days — User Preferences API
 *
 * GET  /api/preferences.php?action=get
 * POST /api/preferences.php?action=save
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/newsletter.php';

$user   = require_auth();
$userId = (int) ($user['id'] ?? 0);

$action = strtolower(trim((string) ($_GET['action'] ?? 'get')));
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
    case 'get':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_get($userId);
        break;
    case 'save':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_save($userId, $body);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

function handle_get(int $userId): never
{
    $stmt = db()->prepare(
        'SELECT reminder_time, timezone, frequency, quiet_from, quiet_until, reminders_enabled,
                coping_strategies, professional_support
         FROM user_preferences WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    $userStmt = db()->prepare('SELECT newsletter_opt_in FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch();
    $newsletterOptIn = $userRow ? (bool) $userRow['newsletter_opt_in'] : false;

    if (!$row) {
        json_ok([
            'reminder_time'        => '7:30 am',
            'timezone'             => 'Australia/Brisbane',
            'frequency'            => 'daily',
            'quiet_from'           => '8:00 pm',
            'quiet_until'          => '6:30 am',
            'reminders_enabled'    => true,
            'newsletter_opt_in'    => $newsletterOptIn,
            'coping_strategies'    => [],
            'professional_support' => null,
            'saved'                => false,
        ]);
    }

    $copingStrategies = json_decode((string) ($row['coping_strategies'] ?? ''), true);
    if (!is_array($copingStrategies)) {
        $copingStrategies = [];
    }

    json_ok([
        'reminder_time'        => format_time_display((string) $row['reminder_time']),
        'timezone'             => (string) $row['timezone'],
        'frequency'            => (string) $row['frequency'],
        'quiet_from'           => format_time_display((string) $row['quiet_from']),
        'quiet_until'          => format_time_display((string) $row['quiet_until']),
        'reminders_enabled'    => (bool) $row['reminders_enabled'],
        'newsletter_opt_in'    => $newsletterOptIn,
        'coping_strategies'    => $copingStrategies,
        'professional_support' => $row['professional_support'] !== null ? (string) $row['professional_support'] : null,
        'saved'                => true,
    ]);
}

function handle_save(int $userId, array $body): never
{
    $reminderTime = parse_time_string((string) ($body['reminder_time'] ?? '7:30 am'));
    if ($reminderTime === null) {
        json_error(422, 'INVALID_REMINDER_TIME', 'Invalid reminder time format.');
    }

    $timezone = trim((string) ($body['timezone'] ?? 'Australia/Brisbane'));
    if (!is_valid_timezone($timezone)) {
        json_error(422, 'INVALID_TIMEZONE', 'Invalid timezone.');
    }

    $frequency = trim((string) ($body['frequency'] ?? 'daily'));
    if (!in_array($frequency, ['daily', 'every_2_days', 'every_3_days', 'not_now'], true)) {
        json_error(422, 'INVALID_FREQUENCY', 'Frequency must be daily, every_2_days, every_3_days, or not_now.');
    }

    $quietFrom = parse_time_string((string) ($body['quiet_from'] ?? '8:00 pm'));
    if ($quietFrom === null) {
        json_error(422, 'INVALID_QUIET_FROM', 'Invalid quiet-from time format.');
    }

    $quietUntil = parse_time_string((string) ($body['quiet_until'] ?? '6:30 am'));
    if ($quietUntil === null) {
        json_error(422, 'INVALID_QUIET_UNTIL', 'Invalid quiet-until time format.');
    }

    // "Not now" disables reminders entirely
    $remindersEnabled = ($frequency === 'not_now') ? 0 : 1;

    $copingStrategies = sanitize_coping_strategies($body['coping_strategies'] ?? []);
    $professionalSupport = sanitize_professional_support($body['professional_support'] ?? null);
    $copingStrategiesJson = json_encode($copingStrategies, JSON_UNESCAPED_UNICODE);

    db()->prepare(
        'INSERT INTO user_preferences
            (user_id, reminder_time, timezone, frequency, quiet_from, quiet_until, reminders_enabled,
             coping_strategies, professional_support)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            reminder_time        = VALUES(reminder_time),
            timezone             = VALUES(timezone),
            frequency            = VALUES(frequency),
            quiet_from           = VALUES(quiet_from),
            quiet_until          = VALUES(quiet_until),
            reminders_enabled    = VALUES(reminders_enabled),
            coping_strategies    = VALUES(coping_strategies),
            professional_support = VALUES(professional_support)'
    )->execute([
        $userId, $reminderTime, $timezone, $frequency, $quietFrom, $quietUntil, $remindersEnabled,
        $copingStrategiesJson, $professionalSupport,
    ]);

    if (array_key_exists('newsletter_opt_in', $body)) {
        $newsletterOptIn = $body['newsletter_opt_in'] ? 1 : 0;

        // Fetch current value to detect opt-in transitions (avoid duplicate submissions)
        $cur = db()->prepare('SELECT newsletter_opt_in, email, full_name FROM users WHERE id = ?');
        $cur->execute([$userId]);
        $userRow = $cur->fetch();

        db()->prepare('UPDATE users SET newsletter_opt_in = ? WHERE id = ?')
            ->execute([$newsletterOptIn, $userId]);

        // Submit to Vision6 only when user switches from opted-out → opted-in
        if ($newsletterOptIn && $userRow && !(bool) $userRow['newsletter_opt_in']) {
            submit_to_vision6((string) $userRow['email'], (string) ($userRow['full_name'] ?? ''));
        }
    }

    audit('preferences.save', $userId, [
        'reminder_time'        => $reminderTime,
        'timezone'             => $timezone,
        'frequency'            => $frequency,
        'reminders_enabled'    => (bool) $remindersEnabled,
        'coping_strategies'    => $copingStrategies,
        'professional_support' => $professionalSupport,
    ]);

    json_ok(['ok' => true]);
}

/**
 * Filters to the valid "What already works for you" labels from my-preference.html,
 * capped at 3 (the UI's own selection limit).
 */
function sanitize_coping_strategies(mixed $raw): array
{
    $valid = [
        'Walking or getting outside',
        'Exercise',
        'Music',
        'Talking to someone',
        'Reading or watching something',
        'Quiet time alone',
        'Something creative',
        'Sticking to a routine',
    ];

    if (!is_array($raw)) {
        return [];
    }

    $filtered = array_values(array_intersect(array_map('strval', $raw), $valid));
    return array_slice(array_unique($filtered), 0, 3);
}

/**
 * Validates against the "Professional support" pill labels from my-preference.html.
 */
function sanitize_professional_support(mixed $raw): ?string
{
    $valid = [
        'Yes, I see a psychologist',
        'Yes, I see my GP',
        'Yes, I see a counsellor',
        'Not currently, but open to it',
        'No, I am managing on my own',
    ];

    $value = trim((string) $raw);
    return in_array($value, $valid, true) ? $value : null;
}

function parse_time_string(string $raw): ?string
{
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('g:i a', $raw);
    if ($dt === false) {
        return null;
    }
    return $dt->format('H:i');
}

function format_time_display(string $hhmm): string
{
    $dt = DateTime::createFromFormat('H:i', $hhmm);
    if ($dt === false) {
        return $hhmm;
    }
    return $dt->format('g:i a');
}

function is_valid_timezone(string $tz): bool
{
    if ($tz === '') {
        return false;
    }
    try {
        new DateTimeZone($tz);
        return true;
    } catch (Throwable) {
        return false;
    }
}
