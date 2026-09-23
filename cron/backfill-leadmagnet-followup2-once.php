<?php
/**
 * Tools for the Tough Days — One-off backfill: Lead Magnet Follow-up #2
 *
 * The daily job (cron/send-leadmagnet-followup.php) only catches signups
 * whose created_at falls in a rolling 10-11 day window. Since it only went
 * live on 2026-09-22, anyone who signed up earlier than that window already
 * aged past it and was permanently skipped. This script has no upper bound
 * on created_at, so it catches that backlog in one run.
 *
 * Same eligibility rules as the daily job otherwise: newsletter_opt_in = 1,
 * not already recorded in lead_magnet_sequence_sends for
 * 'lead_magnet_followup2', and not since registered on the platform.
 * Safe to re-run — the dedup table means it will only ever send once per
 * address.
 *
 * Invocation:
 *   CLI:  php cron/backfill-leadmagnet-followup2-once.php [--dry-run]
 *   HTTP: GET /cron/backfill-leadmagnet-followup2-once.php?secret=<CRON_SECRET>[&dry_run=1]
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
    header('Content-Type: text/plain');
}

$dryRun = php_sapi_name() === 'cli'
    ? in_array('--dry-run', $argv, true)
    : (($_GET['dry_run'] ?? '') === '1');

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/mailer.php';

$configFile = __DIR__ . '/../config.php';
if (is_readable($configFile)) {
    require_once $configFile;
}

// Dedup table — same one the daily cron uses/creates.
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

// ── Find everyone older than 10 days who was never sent followup2 ──────────
$stmt = db()->prepare(
    "SELECT l.email
     FROM lead_magnet_signups l
     WHERE l.newsletter_opt_in = 1
       AND l.created_at < DATE_SUB(NOW(), INTERVAL 10 DAY)
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
$stmt->execute();
$signups = $stmt->fetchAll();

if ($dryRun) {
    echo "[DRY RUN] Would send lead_magnet_followup2 to " . count($signups) . " address(es):\n";
    foreach ($signups as $signup) {
        echo "  - {$signup['email']}\n";
    }
    exit;
}

$sent  = 0;
$error = 0;

foreach ($signups as $signup) {
    $email     = (string) $signup['email'];
    $logPrefix = "[backfill-leadmagnet-followup2] email={$email}";

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

$summary = "[backfill-leadmagnet-followup2] Done — sent={$sent} errors={$error}";
error_log($summary);
echo $summary . "\n";
