<?php
/**
 * Tools for the Tough Days — Check-in Reminder Cron
 *
 * Runs every 30 minutes. Sends one reminder email per user per day
 * when the current time (in the user's timezone) falls within a 30-minute
 * window of their chosen reminder_time.
 *
 * Invocation:
 *   CLI:  php cron/send-reminders.php
 *   HTTP: GET /cron/send-reminders.php?secret=<CRON_SECRET>
 *         or with header  X-Cron-Secret: <CRON_SECRET>
 */

declare(strict_types=1);

// ── Security ──────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $secret   = (string) (getenv('CRON_SECRET') ?: '');
    $provided = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['secret'] ?? ''));
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit;
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mailer.php';

// Load SITE_URL constant for email links if config exists
$configFile = __DIR__ . '/../config.php';
if (is_readable($configFile)) {
    require_once $configFile;
}

// ── Main ──────────────────────────────────────────────────────────────────────
$utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$stmt = db()->prepare(
    'SELECT up.user_id, up.reminder_time, up.timezone, up.frequency,
            up.quiet_from, up.quiet_until,
            u.email, u.full_name
     FROM user_preferences up
     JOIN users u ON u.id = up.user_id
     WHERE up.reminders_enabled = 1
       AND u.status = "active"'
);
$stmt->execute();
$prefs = $stmt->fetchAll();

$sent  = 0;
$skip  = 0;
$error = 0;

foreach ($prefs as $pref) {
    try {
        $userId = (int) $pref['user_id'];

        // Convert UTC now to the user's local timezone
        $tz        = new DateTimeZone((string) $pref['timezone']);
        $userNow   = $utcNow->setTimezone($tz);
        $localHHMM = $userNow->format('H:i');
        $localDate = $userNow->format('Y-m-d');

        $logPrefix = "[send-reminders] user_id={$userId} local={$localHHMM}";

        // Map frequency to minimum hours since last check-in required before sending
        $thresholds = ['daily' => 24, 'every_2_days' => 48, 'every_3_days' => 72];
        $threshold  = $thresholds[(string) $pref['frequency']] ?? null;
        if ($threshold === null) {
            error_log("{$logPrefix} SKIP: frequency={$pref['frequency']} (not_now or unknown)");
            $skip++; continue;
        }

        // Check hours since user's last check-in
        $lastCheckinStmt = db()->prepare(
            'SELECT MAX(checkin_at) FROM mood_events WHERE user_id = ?'
        );
        $lastCheckinStmt->execute([$userId]);
        $lastCheckin = $lastCheckinStmt->fetchColumn();

        if ($lastCheckin !== null && $lastCheckin !== false && $lastCheckin !== '') {
            $lastCheckinDt = new DateTimeImmutable((string) $lastCheckin, new DateTimeZone('UTC'));
            $hoursSince    = round(($utcNow->getTimestamp() - $lastCheckinDt->getTimestamp()) / 3600, 1);
            if ($hoursSince < $threshold) {
                error_log("{$logPrefix} SKIP: last_checkin={$lastCheckin} hours_since={$hoursSince} threshold={$threshold}h");
                $skip++; continue;
            }
        }

        // Check quiet hours
        if (is_in_quiet_window($localHHMM, (string) $pref['quiet_from'], (string) $pref['quiet_until'])) {
            error_log("{$logPrefix} SKIP: in quiet window ({$pref['quiet_from']}–{$pref['quiet_until']})");
            $skip++;
            continue;
        }

        // Check if current time is within the 30-minute reminder window
        if (!time_in_window($localHHMM, (string) $pref['reminder_time'], 30)) {
            error_log("{$logPrefix} SKIP: outside reminder window (target={$pref['reminder_time']})");
            $skip++;
            continue;
        }

        // Deduplication — has this user already been sent a reminder today?
        $dedupStmt = db()->prepare(
            'SELECT id FROM reminder_sends WHERE user_id = ? AND send_date = ?'
        );
        $dedupStmt->execute([$userId, $localDate]);
        if ($dedupStmt->fetch()) {
            error_log("{$logPrefix} SKIP: already sent today ({$localDate})");
            $skip++;
            continue;
        }

        // Send the email
        $emailSent = send_checkin_reminder_email((string) $pref['email'], $pref['full_name'] ?? null);

        if ($emailSent) {
            // INSERT IGNORE handles the rare case of concurrent cron runs
            db()->prepare(
                'INSERT IGNORE INTO reminder_sends (user_id, send_date) VALUES (?, ?)'
            )->execute([$userId, $localDate]);
            $sent++;
        } else {
            $error++;
            error_log("[send-reminders] Email failed for user_id={$userId}");
        }
    } catch (Throwable $e) {
        $error++;
        error_log('[send-reminders] Exception for user_id=' . ($pref['user_id'] ?? '?') . ': ' . $e->getMessage());
    }
}

$summary = "[send-reminders] Done at " . $utcNow->format('Y-m-d H:i:s') . " UTC"
    . " — sent={$sent} skipped={$skip} errors={$error}";
error_log($summary);

if (php_sapi_name() !== 'cli') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo $summary . "\n";
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Returns true if $localNow (HH:MM) falls within [$target, $target + $windowMinutes).
 * Handles midnight rollover (e.g. target = 23:45 with a 30-minute window).
 */
function time_in_window(string $localNow, string $target, int $windowMinutes): bool
{
    $nowMins    = time_to_minutes($localNow);
    $targetMins = time_to_minutes($target);
    $endMins    = ($targetMins + $windowMinutes) % (24 * 60);

    if ($targetMins < $endMins) {
        return $nowMins >= $targetMins && $nowMins < $endMins;
    }
    // Window wraps midnight
    return $nowMins >= $targetMins || $nowMins < $endMins;
}

/**
 * Returns true if $localNow falls inside the quiet window [$from, $until).
 * When $from > $until the window spans midnight (the common case: e.g. 20:00 – 06:30).
 */
function is_in_quiet_window(string $localNow, string $from, string $until): bool
{
    $nowMins   = time_to_minutes($localNow);
    $fromMins  = time_to_minutes($from);
    $untilMins = time_to_minutes($until);

    if ($fromMins <= $untilMins) {
        return $nowMins >= $fromMins && $nowMins < $untilMins;
    }
    // Spans midnight
    return $nowMins >= $fromMins || $nowMins < $untilMins;
}

function time_to_minutes(string $hhmm): int
{
    [$h, $m] = explode(':', $hhmm, 2) + [0, 0];
    return (int) $h * 60 + (int) $m;
}
