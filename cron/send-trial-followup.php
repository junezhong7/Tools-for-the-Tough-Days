<?php
/**
 * Tools for the Tough Days — Trial Day-10 Follow-up Email
 *
 * Sends a pre-conversion nudge on day 10 of a user's 14-day free trial
 * (four days before the trial expires).
 *
 * Invocation:
 *   CLI:  php cron/send-trial-followup.php
 *   HTTP: GET /cron/send-trial-followup.php?secret=<CRON_SECRET>
 *         or with header  X-Cron-Secret: <CRON_SECRET>
 *
 * Schedule: daily (see cron/webjob-trial-followup/settings.job).
 * Deduplication via trial_sequence_sends ensures each email_key is sent
 * only once per user, so it is safe to run more frequently than once a day.
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
    'CREATE TABLE IF NOT EXISTS trial_sequence_sends (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED    NOT NULL,
        email_key  VARCHAR(64)     NOT NULL,
        sent_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_trial_seq_user_key (user_id, email_key),
        KEY idx_trial_seq_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// ── Main ──────────────────────────────────────────────────────────────────────
$utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// Find trialing users whose free trial started between 10 and 11 days ago
// who have not yet received the trial_day10 email.
$stmt = db()->prepare(
    "SELECT u.id AS user_id, u.email, u.full_name
     FROM users u
     JOIN subscriptions s ON s.user_id = u.id
     WHERE s.product_key = 'free_trial'
       AND s.status      = 'trialing'
       AND u.status      = 'active'
       AND s.current_period_start >= DATE_SUB(NOW(), INTERVAL 11 DAY)
       AND s.current_period_start <  DATE_SUB(NOW(), INTERVAL 10 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM trial_sequence_sends tss
           WHERE tss.user_id   = u.id
             AND tss.email_key = 'trial_day10'
       )"
);
$stmt->execute();
$users = $stmt->fetchAll();

$sent  = 0;
$error = 0;

// ── Day 10 sends ───────────────────────────────────────────────────────────────
foreach ($users as $user) {
    $userId    = (int) $user['user_id'];
    $logPrefix = "[send-trial-followup] user_id={$userId}";

    try {
        $emailSent = send_trial_day10_email(
            (string) $user['email'],
            $user['full_name'] ?? null
        );

        if ($emailSent) {
            db()->prepare(
                'INSERT IGNORE INTO trial_sequence_sends (user_id, email_key) VALUES (?, ?)'
            )->execute([$userId, 'trial_day10']);
            $sent++;
            error_log("{$logPrefix} SENT trial_day10");
        } else {
            $error++;
            error_log("{$logPrefix} FAILED trial_day10: mailer returned false");
        }
    } catch (Throwable $e) {
        $error++;
        error_log("{$logPrefix} EXCEPTION trial_day10: " . $e->getMessage());
    }
}

// ── Day 14 sends ───────────────────────────────────────────────────────────────
// Target: still-trialing users whose trial started 14–15 days ago (not yet converted).
// Skips users who upgraded to a paid subscription before trial ended.
$stmt14 = db()->prepare(
    "SELECT u.id AS user_id, u.email, u.full_name
     FROM users u
     JOIN subscriptions s ON s.user_id = u.id
     WHERE s.product_key = 'free_trial'
       AND s.status      = 'trialing'
       AND u.status      = 'active'
       AND s.current_period_start >= DATE_SUB(NOW(), INTERVAL 15 DAY)
       AND s.current_period_start <  DATE_SUB(NOW(), INTERVAL 14 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM trial_sequence_sends tss
           WHERE tss.user_id   = u.id
             AND tss.email_key = 'trial_day14'
       )"
);
$stmt14->execute();
$users14 = $stmt14->fetchAll();

foreach ($users14 as $user) {
    $userId    = (int) $user['user_id'];
    $logPrefix = "[send-trial-followup] user_id={$userId}";

    try {
        $emailSent = send_trial_day14_email(
            (string) $user['email'],
            $user['full_name'] ?? null
        );

        if ($emailSent) {
            db()->prepare(
                'INSERT IGNORE INTO trial_sequence_sends (user_id, email_key) VALUES (?, ?)'
            )->execute([$userId, 'trial_day14']);
            $sent++;
            error_log("{$logPrefix} SENT trial_day14");
        } else {
            $error++;
            error_log("{$logPrefix} FAILED trial_day14: mailer returned false");
        }
    } catch (Throwable $e) {
        $error++;
        error_log("{$logPrefix} EXCEPTION trial_day14: " . $e->getMessage());
    }
}

$summary = "[send-trial-followup] Done at " . $utcNow->format('Y-m-d H:i:s') . " UTC"
    . " — sent={$sent} errors={$error}";
error_log($summary);

if (php_sapi_name() !== 'cli') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo $summary . "\n";
}
