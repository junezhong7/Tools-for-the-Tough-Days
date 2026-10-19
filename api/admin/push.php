<?php
/**
 * Tools for the Tough Days — Admin push notifications API
 *
 * GET  /api/admin/push.php                    config status + members who have notifications on
 * POST /api/admin/push.php?action=send        {target: member|all, email?, title, body?, url?}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../lib/webpush.php';

header('Content-Type: application/json; charset=utf-8');

$admin = require_admin_auth();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

switch ($action) {
    case '':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_status();
        break;
    case 'send':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_send($admin);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// STATUS (config check + subscribed members)
// ─────────────────────────────────────────────
function handle_status(): never
{
    push_ensure_table();

    $totals = db()->query(
        'SELECT COUNT(DISTINCT user_id) AS members, COUNT(*) AS devices FROM push_subscriptions'
    )->fetch();

    $members = db()->query(
        'SELECT u.email, u.full_name,
                COUNT(*)               AS devices,
                MAX(ps.last_seen_at)   AS last_seen_at,
                MAX(ps.last_success_at) AS last_success_at,
                MAX(ps.failure_count)  AS failure_count
         FROM push_subscriptions ps
         JOIN users u ON u.id = ps.user_id
         GROUP BY u.id, u.email, u.full_name
         ORDER BY last_seen_at DESC
         LIMIT 100'
    )->fetchAll();

    json_ok([
        'configured'   => webpush_is_configured(),
        'config_error' => webpush_config_error(),
        'totals'       => [
            'members' => (int) $totals['members'],
            'devices' => (int) $totals['devices'],
        ],
        'members'      => array_map(static fn (array $m): array => [
            'email'           => $m['email'],
            'full_name'       => $m['full_name'],
            'devices'         => (int) $m['devices'],
            'last_seen_at'    => $m['last_seen_at'],
            'last_success_at' => $m['last_success_at'],
            'failure_count'   => (int) $m['failure_count'],
        ], $members),
    ]);
}

// ─────────────────────────────────────────────
// SEND (one member by email, or everyone who has notifications on)
// ─────────────────────────────────────────────
function handle_send(array $admin): never
{
    $raw  = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : [];

    $configError = webpush_config_error();
    if ($configError !== null) {
        json_error(503, 'PUSH_NOT_CONFIGURED', $configError);
    }

    $target = (string) ($body['target'] ?? 'member');
    $title  = trim((string) ($body['title'] ?? ''));
    $text   = trim((string) ($body['body'] ?? ''));
    $url    = trim((string) ($body['url'] ?? ''));

    if (!in_array($target, ['member', 'all'], true)) {
        json_error(422, 'INVALID_TARGET', 'target must be member or all.');
    }
    if ($title === '' || mb_strlen($title) > 80) {
        json_error(422, 'INVALID_TITLE', 'Title is required (up to 80 characters).');
    }
    if (mb_strlen($text) > 240) {
        json_error(422, 'INVALID_BODY', 'Message must be 240 characters or fewer.');
    }
    if ($url === '') {
        $url = '/dashboard.html';
    }
    // Same-site paths only, so a notification can never send members off-site.
    if (!str_starts_with($url, '/') || str_starts_with($url, '//') || strlen($url) > 300) {
        json_error(422, 'INVALID_URL', 'Link must be a path on this site, e.g. /dashboard.html');
    }

    push_ensure_table();

    $targetUserId = null;
    if ($target === 'member') {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $userStmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $userStmt->execute([$email]);
        $targetUserId = $userStmt->fetchColumn();
        if ($targetUserId === false) {
            json_error(404, 'MEMBER_NOT_FOUND', 'No member with that email.');
        }
        $targetUserId = (int) $targetUserId;

        $rowsStmt = db()->prepare('SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE user_id = ?');
        $rowsStmt->execute([$targetUserId]);
    } else {
        $rowsStmt = db()->query(
            'SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth_secret
             FROM push_subscriptions ps
             JOIN users u ON u.id = ps.user_id
             WHERE u.status = "active"'
        );
    }
    $rows = $rowsStmt->fetchAll();

    if (!$rows) {
        json_error(404, 'NO_DEVICES', $target === 'member'
            ? 'This member has not turned on notifications on any device.'
            : 'No members have turned on notifications yet.');
    }

    $summary = push_send_to_rows($rows, [
        'title' => $title,
        'body'  => $text,
        'url'   => $url,
    ]);

    audit('push.send', $targetUserId, [
        'target'  => $target,
        'title'   => $title,
        'url'     => $url,
        'devices' => $summary['devices'],
        'sent'    => $summary['sent'],
        'failed'  => $summary['failed'],
        'removed' => $summary['removed'],
    ], (int) $admin['id']);

    json_ok($summary);
}
