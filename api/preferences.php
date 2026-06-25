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
        'SELECT reminder_time, timezone, frequency, quiet_from, quiet_until, reminders_enabled
         FROM user_preferences WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        json_ok([
            'reminder_time'     => '7:30 am',
            'timezone'          => 'Australia/Brisbane',
            'frequency'         => 'daily',
            'quiet_from'        => '8:00 pm',
            'quiet_until'       => '6:30 am',
            'reminders_enabled' => true,
            'saved'             => false,
        ]);
    }

    json_ok([
        'reminder_time'     => format_time_display((string) $row['reminder_time']),
        'timezone'          => (string) $row['timezone'],
        'frequency'         => (string) $row['frequency'],
        'quiet_from'        => format_time_display((string) $row['quiet_from']),
        'quiet_until'       => format_time_display((string) $row['quiet_until']),
        'reminders_enabled' => (bool) $row['reminders_enabled'],
        'saved'             => true,
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

    db()->prepare(
        'INSERT INTO user_preferences
            (user_id, reminder_time, timezone, frequency, quiet_from, quiet_until, reminders_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            reminder_time     = VALUES(reminder_time),
            timezone          = VALUES(timezone),
            frequency         = VALUES(frequency),
            quiet_from        = VALUES(quiet_from),
            quiet_until       = VALUES(quiet_until),
            reminders_enabled = VALUES(reminders_enabled)'
    )->execute([$userId, $reminderTime, $timezone, $frequency, $quietFrom, $quietUntil, $remindersEnabled]);

    audit('preferences.save', $userId, [
        'reminder_time'     => $reminderTime,
        'timezone'          => $timezone,
        'frequency'         => $frequency,
        'reminders_enabled' => (bool) $remindersEnabled,
    ]);

    json_ok(['ok' => true]);
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
