<?php
/**
 * Tools for the Tough Days — Lead magnet signup API
 *
 * POST /api/lead-magnet.php
 * Body: { email, newsletter_opt_in }
 *
 * Public endpoint (no auth) used by free-guide.html. Saves the signup,
 * optionally subscribes to the Vision6 newsletter list, then emails the
 * free guide PDF as an attachment.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/newsletter.php';
require_once __DIR__ . '/../lib/utm.php';
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
}

$raw = file_get_contents('php://input');
$body = $raw !== '' ? (json_decode($raw, true) ?? []) : [];
if (empty($body)) {
    $body = $_POST;
}

$email = strtolower(trim((string) ($body['email'] ?? '')));
$newsletterOptIn = in_array($body['newsletter_opt_in'] ?? false, [true, 1, '1', 'on', 'true'], true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error(422, 'INVALID_EMAIL', 'Please enter a valid email address.');
}

$utm = read_utm_cookie(); // never throws; [] if absent/malformed
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
if ($ip) {
    $ip = trim(explode(',', $ip)[0]);
}

try {
    $stmt = db()->prepare(
        'INSERT INTO lead_magnet_signups
            (email, newsletter_opt_in, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
             gclid, fbclid, landing_page, signup_referrer, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $email,
        $newsletterOptIn ? 1 : 0,
        $utm['utm_source']   ?? null,
        $utm['utm_medium']   ?? null,
        $utm['utm_campaign'] ?? null,
        $utm['utm_term']     ?? null,
        $utm['utm_content']  ?? null,
        $utm['gclid']        ?? null,
        $utm['fbclid']       ?? null,
        $utm['landing_page'] ?? null,
        $utm['referrer']     ?? null,
        $ip,
    ]);
    $signupId = (int) db()->lastInsertId();
} catch (Throwable $e) {
    error_log('lead-magnet signup insert failed: ' . $e->getMessage());
    json_error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.');
}

if ($newsletterOptIn) {
    submit_to_vision6($email); // best-effort; errors are logged, never thrown
}

$emailSent = send_free_guide_email($email);

if ($emailSent) {
    try {
        db()->prepare('UPDATE lead_magnet_signups SET email_sent = 1 WHERE id = ?')->execute([$signupId]);
    } catch (Throwable $e) {
        error_log('lead-magnet email_sent update failed: ' . $e->getMessage());
    }
}

audit('lead_magnet.signup', null, array_merge(
    ['email' => $email, 'newsletter_opt_in' => $newsletterOptIn, 'email_sent' => $emailSent],
    $utm
));

if (!$emailSent) {
    json_error(502, 'EMAIL_FAILED', 'We saved your details but could not send the email right now. Please try again shortly.');
}

json_ok(['success' => true]);
