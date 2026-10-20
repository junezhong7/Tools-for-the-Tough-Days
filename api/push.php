<?php
/**
 * Tools for the Tough Days — Push notifications API (per device)
 *
 * Push is managed separately from email reminders: each browser/phone the member
 * turns on gets its own row in push_subscriptions.
 *
 * GET  /api/push.php?action=config                        VAPID public key + whether push is available
 * POST /api/push.php?action=subscribe    {subscription}    save this browser's PushSubscription.toJSON()
 * POST /api/push.php?action=unsubscribe  {endpoint}        remove this browser
 * POST /api/push.php?action=test         {endpoint}        send a test notification to this browser
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/webpush.php';

$user   = require_auth();
$userId = (int) ($user['id'] ?? 0);

$action = strtolower(trim((string) ($_GET['action'] ?? 'config')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true) ?? [];
    }
}

switch ($action) {
    case 'config':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        json_ok([
            'configured' => webpush_is_configured(),
            'public_key' => webpush_public_key(),
        ]);
        break;
    case 'subscribe':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_subscribe($userId, $body);
        break;
    case 'unsubscribe':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_unsubscribe($userId, $body);
        break;
    case 'test':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_test($userId, $body);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

function handle_subscribe(int $userId, array $body): never
{
    if (!webpush_is_configured()) {
        json_error(503, 'PUSH_NOT_CONFIGURED', 'Notifications are not available yet.');
    }

    $subscription = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $p256dh   = trim((string) ($subscription['keys']['p256dh'] ?? ''));
    $auth     = trim((string) ($subscription['keys']['auth'] ?? ''));

    if ($endpoint === '' || strlen($endpoint) > 2048 || !webpush_endpoint_allowed($endpoint)) {
        json_error(422, 'INVALID_ENDPOINT', 'This browser\'s push service is not supported.');
    }

    try {
        $validKeys = strlen(webpush_b64url_decode($p256dh)) === 65 && strlen(webpush_b64url_decode($auth)) === 16;
    } catch (Throwable) {
        $validKeys = false;
    }
    if (!$validKeys) {
        json_error(422, 'INVALID_KEYS', 'Subscription keys are missing or malformed.');
    }

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isNewDevice = push_save_subscription($userId, $endpoint, $p256dh, $auth, $userAgent);

    if ($isNewDevice) {
        audit('push.subscribe', $userId, ['user_agent' => substr($userAgent, 0, 200)]);
    }

    json_ok(['ok' => true]);
}

function handle_unsubscribe(int $userId, array $body): never
{
    $endpoint = trim((string) ($body['endpoint'] ?? ''));
    if ($endpoint === '') {
        json_error(422, 'MISSING_ENDPOINT', 'endpoint is required.');
    }

    if (push_delete_subscription($userId, $endpoint)) {
        audit('push.unsubscribe', $userId);
    }

    json_ok(['ok' => true]);
}

function handle_test(int $userId, array $body): never
{
    $endpoint = trim((string) ($body['endpoint'] ?? ''));
    $row = $endpoint !== '' ? push_find_subscription($userId, $endpoint) : null;
    if ($row === null) {
        json_error(404, 'NOT_SUBSCRIBED', 'Notifications are not turned on for this device.');
    }

    $summary = push_send_to_rows([$row], [
        'title' => 'Tools for the Tough Days',
        'body'  => 'Notifications are working on this device.',
        'url'   => '/my-preference.html',
        'tag'   => 'tttd-test',
    ], 5 * 60);

    if ($summary['sent'] === 0) {
        $message = $summary['removed'] > 0
            ? 'This device\'s notification subscription has expired. Turn notifications off and on again.'
            : 'The test notification could not be sent. Please try again later.';
        json_error(502, 'PUSH_FAILED', $message);
    }

    json_ok(['ok' => true]);
}
