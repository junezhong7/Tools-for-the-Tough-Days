<?php
/**
 * Tools for the Tough Days — One-off: resend the free guide with an apology
 *
 * From 2026-11-11 13:15 UTC Brevo rejected every SMTP call from the site
 * ("525 5.7.1 Unauthorized IP address") after Azure moved the app to a new
 * worker IP. api/lead-magnet.php saved each signup but the email never went
 * out, so those rows were left with email_sent = 0. Brevo's IP filtering was
 * turned off on 2026-11-16.
 *
 * This script sends send_free_guide_apology_email() (guide PDF attached +
 * apology) to every affected address:
 *   - lead_magnet_signups.email_sent = 0, created since 11 Nov 00:00 AEST
 *   - de-duplicated by email
 *   - skipping anyone who received the guide successfully after the outage
 *     began (a success *before* it, followed by a failed re-request, still
 *     gets the apology — they asked again, so they likely couldn't find it)
 *   - skipping anyone already sent this apology (lead_magnet_sequence_sends)
 * On success the address's affected rows are marked email_sent = 1.
 *
 * Modes:
 *   ?dry_run=1   list who would receive it; sends nothing, changes nothing
 *   ?preview=1   send one copy each to Nic and June only; changes nothing
 *   (default)    send for real, at most ?limit=N addresses per run (default 40)
 *
 * Safe to re-run until "remaining=0" — each address is only ever sent once.
 *
 * Invocation:
 *   HTTP: GET /cron/resend-free-guide-apology-once.php?secret=<CRON_SECRET>[&dry_run=1|&preview=1][&limit=N]
 *   CLI:  php cron/resend-free-guide-apology-once.php [--dry-run|--preview] [--limit=N]
 */

declare(strict_types=1);

// ── Security ──────────────────────────────────────────────────────────────────
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $secret   = (string) (getenv('CRON_SECRET') ?: '');
    $provided = (string) ($_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['secret'] ?? ''));
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$cliArgs = $isCli ? array_slice($argv, 1) : [];
$dryRun  = $isCli ? in_array('--dry-run', $cliArgs, true) : (($_GET['dry_run'] ?? '') === '1');
$preview = $isCli ? in_array('--preview', $cliArgs, true) : (($_GET['preview'] ?? '') === '1');
$limit   = 40;
if ($isCli) {
    foreach ($cliArgs as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = (int) substr($arg, 8);
        }
    }
} elseif (isset($_GET['limit'])) {
    $limit = (int) $_GET['limit'];
}
$limit = max(1, min(200, $limit));

// Keep sending even if the browser tab is closed or the request times out.
ignore_user_abort(true);
set_time_limit(0);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mailer.php';

$configFile = __DIR__ . '/../config.php';
if (is_readable($configFile)) {
    require_once $configFile;
}

const APOLOGY_EMAIL_KEY = 'free_guide_apology_2026_11';
const OUTAGE_FROM_UTC   = '2026-11-10 14:00:00'; // 11 Nov 2026 00:00 AEST — failed rows from here
const OUTAGE_START_UTC  = '2026-11-11 12:00:00'; // last success 11:57, first failure 13:15 UTC
const PREVIEW_RECIPIENTS = [
    'nic.marcon@emotionalbalance.com.au',
    'june.zhong@emotionalbalance.com.au',
];

function out(string $line): void
{
    echo $line . "\n";
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

// ── Preview: send to Nic and June only ───────────────────────────────────────
if ($preview) {
    foreach (PREVIEW_RECIPIENTS as $email) {
        try {
            $ok = send_free_guide_apology_email($email);
            out(($ok ? 'SENT preview to ' : 'FAILED preview to ') . $email);
            error_log("[resend-free-guide-apology] PREVIEW " . ($ok ? 'SENT' : 'FAILED') . " email={$email}");
        } catch (Throwable $e) {
            out("EXCEPTION preview to {$email}: " . $e->getMessage());
            error_log("[resend-free-guide-apology] PREVIEW EXCEPTION email={$email}: " . $e->getMessage());
        }
    }
    exit;
}

// Dedup table — same one the follow-up crons use/create.
db()->exec(
    'CREATE TABLE IF NOT EXISTS lead_magnet_sequence_sends (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email      VARCHAR(255)    NOT NULL,
        email_key  VARCHAR(64)     NOT NULL,
        sent_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_lead_magnet_seq_email_key (email, email_key),
        KEY idx_lead_magnet_seq_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// ── Find affected addresses ──────────────────────────────────────────────────
$stmt = db()->prepare(
    "SELECT l.email, MIN(l.created_at) AS first_signup, COUNT(*) AS attempts
     FROM lead_magnet_signups l
     WHERE l.email_sent = 0
       AND l.created_at >= ?
       AND NOT EXISTS (
           SELECT 1 FROM lead_magnet_signups ok
           WHERE ok.email = l.email
             AND ok.email_sent = 1
             AND ok.created_at >= ?
       )
       AND NOT EXISTS (
           SELECT 1 FROM lead_magnet_sequence_sends s
           WHERE s.email = l.email
             AND s.email_key = ?
       )
     GROUP BY l.email
     ORDER BY first_signup ASC"
);
$stmt->execute([OUTAGE_FROM_UTC, OUTAGE_START_UTC, APOLOGY_EMAIL_KEY]);
$affected = $stmt->fetchAll();

if ($dryRun) {
    out('[DRY RUN] ' . count($affected) . ' address(es) would receive the free guide + apology:');
    foreach ($affected as $row) {
        out("  - {$row['email']}  (first signup {$row['first_signup']} UTC, {$row['attempts']} attempt(s))");
    }
    exit;
}

// ── Send ─────────────────────────────────────────────────────────────────────
$batch = array_slice($affected, 0, $limit);
$sent  = 0;
$error = 0;

foreach ($batch as $row) {
    $email     = (string) $row['email'];
    $logPrefix = "[resend-free-guide-apology] email={$email}";

    try {
        if (send_free_guide_apology_email($email)) {
            db()->prepare(
                'INSERT IGNORE INTO lead_magnet_sequence_sends (email, email_key) VALUES (?, ?)'
            )->execute([$email, APOLOGY_EMAIL_KEY]);
            db()->prepare(
                'UPDATE lead_magnet_signups SET email_sent = 1
                 WHERE email = ? AND email_sent = 0 AND created_at >= ?'
            )->execute([$email, OUTAGE_FROM_UTC]);
            $sent++;
            error_log("{$logPrefix} SENT");
            out("SENT {$email}");
        } else {
            $error++;
            error_log("{$logPrefix} FAILED: mailer returned false");
            out("FAILED {$email}");
        }
    } catch (Throwable $e) {
        $error++;
        error_log("{$logPrefix} EXCEPTION: " . $e->getMessage());
        out("EXCEPTION {$email}: " . $e->getMessage());
    }
}

$remaining = count($affected) - $sent;
$summary   = "[resend-free-guide-apology] Done — sent={$sent} errors={$error} remaining={$remaining}";
error_log($summary);
out($summary);
