<?php
/**
 * Tools for the Tough Days — Auth middleware
 * Include at the top of any PHP file that requires a logged-in user.
 *
 * Usage:
 *   require_once __DIR__ . '/../lib/auth.php';
 *   $user = require_auth();   // exits with 401 JSON if not logged in
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SESSION_COOKIE  = 'tttd_session';
const SESSION_IDLE_TTL_SEC = 60 * 60 * 6; // 6 hours of inactivity

/**
 * Returns the authenticated user row, or sends a 401 response and exits.
 */
function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        json_error(401, 'Unauthenticated', 'You must be logged in.');
    }
    return $user;
}

/**
 * Returns the authenticated user row, or null if not logged in.
 */
function current_user(): ?array
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT s.user_id, s.expires_at, u.id, u.email, u.full_name, u.is_business_user,
                    u.business_name, u.status, u.stripe_customer_id
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] === 'suspended') {
            return null;
        }

        refresh_session_activity($token);

        return $row;
    } catch (Throwable $e) {
        error_log('current_user() error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Creates a new server-side session, sets the cookie, and returns the token.
 */
function create_session(int $userId): string
{
    $token     = bin2hex(random_bytes(32)); // 64 hex chars
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_IDLE_TTL_SEC);

    $ip        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($ip) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    db()->prepare(
        'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$token, $userId, $ip, $ua, $expiresAt]);

    set_session_cookie($token, time() + SESSION_IDLE_TTL_SEC);

    return $token;
}

/**
 * Refreshes session activity timestamp and sliding expiry.
 */
function refresh_session_activity(string $token): void
{
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_IDLE_TTL_SEC);

    db()->prepare(
        'UPDATE user_sessions
         SET last_active = NOW(), expires_at = ?
         WHERE id = ? AND expires_at > NOW()'
    )->execute([$expiresAt, $token]);

    set_session_cookie($token, time() + SESSION_IDLE_TTL_SEC);
}

/**
 * Sets the authenticated session cookie with secure defaults.
 */
function set_session_cookie(string $token, int $expiresAtUnix): void
{
    $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => $expiresAtUnix,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Destroys the current session (cookie + DB row).
 */
function destroy_session(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') {
        try {
            db()->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([$token]);
        } catch (Throwable) {}
    }
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/**
 * Emits a JSON error response and exits.
 */
function json_error(int $status, string $code, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $code, 'message' => $message]);
    exit;
}

/**
 * Emits a JSON success response.
 */
function json_ok(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
