<?php
/**
 * Tools for the Tough Days — Public newsletter unsubscribe endpoint
 *
 * POST /api/newsletter-unsubscribe.php
 * Body: { email }
 *
 * Public endpoint (no auth) used by unsubscribe.html. Lets anyone — logged in
 * or not — stop being treated as newsletter-subscribed on our side. Always
 * responds with success regardless of whether the address is on file, so the
 * endpoint can't be used to probe which emails are registered.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/newsletter.php';
require_once __DIR__ . '/../lib/mailer.php';
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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error(422, 'INVALID_EMAIL', 'Please enter a valid email address.');
}

$wasSubscribed = unsubscribe_from_newsletter($email);

if ($wasSubscribed) {
    send_newsletter_unsubscribe_email($email); // best-effort; failure shouldn't block the unsubscribe
}

audit('newsletter.unsubscribe', null, ['email' => $email, 'was_subscribed' => $wasSubscribed]);

json_ok(['success' => true]);
