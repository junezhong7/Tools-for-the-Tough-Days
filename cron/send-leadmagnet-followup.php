<?php
/**
 * Tools for the Tough Days — Lead Magnet Follow-up Emails
 *
 * Follow-up #1 (day 5): a "getting to know your mood slider" walkthrough.
 * Follow-up #2 (day 10): a consistency nudge, skipped for anyone who has
 * since registered on the platform.
 *
 * Both are sent only to addresses that opted into the newsletter at signup.
 *
 * Invocation:
 *   CLI:  php cron/send-leadmagnet-followup.php
 *   HTTP: GET /cron/send-leadmagnet-followup.php?secret=<CRON_SECRET>
 *         or with header  X-Cron-Secret: <CRON_SECRET>
 *
 * Schedule: daily (see cron/webjob-leadmagnet-followup/settings.job).
 * Deduplication via lead_magnet_sequence_sends ensures each email_key is sent
 * only once per email address, so it is safe to run more frequently than once a day.
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

$configFile = __DIR__ . '/../config.php';
if (is_readable($configFile)) {
    require_once $configFile;
}

// ── Ensure dedup table exists ─────────────────────────────────────────────────
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

// ── Main ──────────────────────────────────────────────────────────────────────
$utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$sent  = 0;
$error = 0;

// ── Follow-up #1 (day 5) ─────────────────────────────────────────────────────
// Find lead-magnet signups from 5-6 days ago, opted into the newsletter,
// who have not yet received the followup1 email (keyed by email, since the
// same address can appear in lead_magnet_signups more than once).
$stmt1 = db()->prepare(
    "SELECT l.email
     FROM lead_magnet_signups l
     WHERE l.newsletter_opt_in = 1
       AND l.created_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
       AND l.created_at <  DATE_SUB(NOW(), INTERVAL 5 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM lead_magnet_sequence_sends s
           WHERE s.email     = l.email
             AND s.email_key = 'lead_magnet_followup1'
       )
     GROUP BY l.email"
);
$stmt1->execute();
$signups1 = $stmt1->fetchAll();

foreach ($signups1 as $signup) {
    $email     = (string) $signup['email'];
    $logPrefix = "[send-leadmagnet-followup] email={$email}";

    try {
        $emailSent = send_lead_magnet_followup1_email($email);

        if ($emailSent) {
            db()->prepare(
                'INSERT IGNORE INTO lead_magnet_sequence_sends (email, email_key) VALUES (?, ?)'
            )->execute([$email, 'lead_magnet_followup1']);
            $sent++;
            error_log("{$logPrefix} SENT lead_magnet_followup1");
        } else {
            $error++;
            error_log("{$logPrefix} FAILED lead_magnet_followup1: mailer returned false");
        }
    } catch (Throwable $e) {
        $error++;
        error_log("{$logPrefix} EXCEPTION lead_magnet_followup1: " . $e->getMessage());
    }
}

// ── Follow-up #2 (day 10) ────────────────────────────────────────────────────
// Same window logic, but additionally skips anyone who has since registered
// on the platform (matched by email against the users table).
$stmt2 = db()->prepare(
    "SELECT l.email
     FROM lead_magnet_signups l
     WHERE l.newsletter_opt_in = 1
       AND l.created_at >= DATE_SUB(NOW(), INTERVAL 11 DAY)
       AND l.created_at <  DATE_SUB(NOW(), INTERVAL 10 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM lead_magnet_sequence_sends s
           WHERE s.email     = l.email
             AND s.email_key = 'lead_magnet_followup2'
       )
       AND NOT EXISTS (
           SELECT 1 FROM users u
           WHERE u.email = l.email
       )
     GROUP BY l.email"
);
$stmt2->execute();
$signups2 = $stmt2->fetchAll();

foreach ($signups2 as $signup) {
    $email     = (string) $signup['email'];
    $logPrefix = "[send-leadmagnet-followup] email={$email}";

    try {
        $emailSent = send_lead_magnet_followup2_email($email);

        if ($emailSent) {
            db()->prepare(
                'INSERT IGNORE INTO lead_magnet_sequence_sends (email, email_key) VALUES (?, ?)'
            )->execute([$email, 'lead_magnet_followup2']);
            $sent++;
            error_log("{$logPrefix} SENT lead_magnet_followup2");
        } else {
            $error++;
            error_log("{$logPrefix} FAILED lead_magnet_followup2: mailer returned false");
        }
    } catch (Throwable $e) {
        $error++;
        error_log("{$logPrefix} EXCEPTION lead_magnet_followup2: " . $e->getMessage());
    }
}

$summary = "[send-leadmagnet-followup] Done at " . $utcNow->format('Y-m-d H:i:s') . " UTC"
    . " — sent={$sent} errors={$error}";
error_log($summary);

if (php_sapi_name() !== 'cli') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo $summary . "\n";
}
