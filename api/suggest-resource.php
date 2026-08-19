<?php
/**
 * Tools for the Tough Days — Suggest a resource API
 *
 * POST /api/suggest-resource.php
 * Body: { message, catalog }
 *
 * Authenticated endpoint used by the "Can't find what you're looking for?"
 * widget on support.html / business.html. Emails the suggestion straight to
 * the team, no DB storage.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
}

$user = require_auth();

$raw = file_get_contents('php://input');
$body = $raw !== '' ? (json_decode($raw, true) ?? []) : [];
if (empty($body)) {
    $body = $_POST;
}

$message = trim((string) ($body['message'] ?? ''));
$catalog = ($body['catalog'] ?? '') === 'workplace' ? 'workplace' : 'personal';

if ($message === '') {
    json_error(422, 'MISSING_MESSAGE', "Please tell us what you're looking for.");
}
if (mb_strlen($message) > 2000) {
    json_error(422, 'MESSAGE_TOO_LONG', 'Please keep your message under 2000 characters.');
}

$emailSent = send_resource_suggestion_email(
    (string) ($user['email'] ?? ''),
    (string) ($user['full_name'] ?? ''),
    $message,
    $catalog
);

audit('resource.suggestion.submitted', (int) ($user['id'] ?? 0), [
    'catalog' => $catalog,
    'email_sent' => $emailSent,
]);

if (!$emailSent) {
    json_error(502, 'EMAIL_FAILED', 'We could not send your suggestion right now. Please try again shortly.');
}

json_ok(['success' => true]);
