<?php
/**
 * Tools for the Tough Days — Admin Auth API
 *
 * POST /api/admin/auth.php?action=login
 * POST /api/admin/auth.php?action=logout
 * GET  /api/admin/auth.php?action=me
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;
}

switch (true) {
    case $method === 'POST' && $action === 'login':
        handle_login($body);
        break;
    case $method === 'POST' && $action === 'logout':
        handle_logout();
        break;
    case $method === 'GET' && $action === 'me':
        handle_me();
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action or method.');
}

// ─────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────
function handle_login(array $body): never
{
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if ($email === '' || $password === '') {
        json_error(422, 'MISSING_FIELDS', 'Email and password are required.');
    }

    $stmt = db()->prepare('SELECT * FROM admin_users WHERE email = ?');
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    // Constant-time comparison against a dummy hash to avoid leaking valid emails via timing.
    $hash = $admin['password_hash'] ?? '$2y$12$invalidsaltinvalidsaltinvalidxx';
    if (!$admin || !password_verify($password, $hash)) {
        usleep(random_int(100_000, 300_000));
        json_error(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
    }

    if ($admin['status'] !== 'active') {
        json_error(403, 'ACCOUNT_DISABLED', 'This admin account has been disabled.');
    }

    create_admin_session((int) $admin['id']);
    audit('admin.login', null, ['email' => $email], (int) $admin['id']);

    json_ok([
        'admin' => [
            'id'        => (int) $admin['id'],
            'email'     => $admin['email'],
            'full_name' => $admin['full_name'],
        ],
    ]);
}

// ─────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────
function handle_logout(): never
{
    $admin = current_admin();
    destroy_admin_session();

    if ($admin) {
        audit('admin.logout', null, [], (int) $admin['id']);
    }

    json_ok(['message' => 'Logged out.']);
}

// ─────────────────────────────────────────────
// ME
// ─────────────────────────────────────────────
function handle_me(): never
{
    $admin = require_admin_auth();

    json_ok([
        'admin' => [
            'id'        => (int) $admin['id'],
            'email'     => $admin['email'],
            'full_name' => $admin['full_name'],
        ],
    ]);
}
