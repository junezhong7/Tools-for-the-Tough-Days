<?php
/**
 * Tools for the Tough Days — Admin auth middleware
 * Mirrors lib/auth.php, but for ops/staff accounts (admin_users / admin_sessions),
 * fully separate from the member-facing session system.
 *
 * Usage:
 *   require_once __DIR__ . '/../lib/admin_auth.php';
 *   $admin = require_admin_auth();   // exits with 401 JSON if not logged in
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php'; // reuse json_ok()/json_error()

const ADMIN_SESSION_COOKIE = 'tttd_admin_session';
const ADMIN_SESSION_IDLE_TTL_SEC = 60 * 60 * 6; // 6 hours of inactivity, same as member sessions

/**
 * Returns the authenticated admin row, or sends a 401 response and exits.
 */
function require_admin_auth(): array
{
    $admin = current_admin();
    if ($admin === null) {
        json_error(401, 'Unauthenticated', 'You must be logged in as an admin.');
    }
    return $admin;
}

/**
 * Returns the authenticated admin row, or null if not logged in.
 */
function current_admin(): ?array
{
    $token = $_COOKIE[ADMIN_SESSION_COOKIE] ?? '';
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT s.admin_user_id, s.expires_at, a.id, a.email, a.full_name, a.status
             FROM admin_sessions s
             JOIN admin_users a ON a.id = s.admin_user_id
             WHERE s.id = ? AND s.expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            return null;
        }

        refresh_admin_session_activity($token);

        return $row;
    } catch (Throwable $e) {
        error_log('current_admin() error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Creates a new server-side admin session, sets the cookie, and returns the token.
 */
function create_admin_session(int $adminId): string
{
    $token     = bin2hex(random_bytes(32)); // 64 hex chars
    $expiresAt = date('Y-m-d H:i:s', time() + ADMIN_SESSION_IDLE_TTL_SEC);

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($ip) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    db()->prepare(
        'INSERT INTO admin_sessions (id, admin_user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$token, $adminId, $ip, $ua, $expiresAt]);

    set_admin_session_cookie($token, time() + ADMIN_SESSION_IDLE_TTL_SEC);

    return $token;
}

/**
 * Refreshes admin session activity timestamp and sliding expiry.
 */
function refresh_admin_session_activity(string $token): void
{
    $expiresAt = date('Y-m-d H:i:s', time() + ADMIN_SESSION_IDLE_TTL_SEC);

    db()->prepare(
        'UPDATE admin_sessions
         SET last_active = NOW(), expires_at = ?
         WHERE id = ? AND expires_at > NOW()'
    )->execute([$expiresAt, $token]);

    set_admin_session_cookie($token, time() + ADMIN_SESSION_IDLE_TTL_SEC);
}

/**
 * Sets the authenticated admin session cookie with secure defaults.
 */
function set_admin_session_cookie(string $token, int $expiresAtUnix): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie(ADMIN_SESSION_COOKIE, $token, [
        'expires'  => $expiresAtUnix,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Destroys the current admin session (cookie + DB row).
 */
function destroy_admin_session(): void
{
    $token = $_COOKIE[ADMIN_SESSION_COOKIE] ?? '';
    if ($token !== '') {
        try {
            db()->prepare('DELETE FROM admin_sessions WHERE id = ?')->execute([$token]);
        } catch (Throwable) {}
    }
    setcookie(ADMIN_SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}
