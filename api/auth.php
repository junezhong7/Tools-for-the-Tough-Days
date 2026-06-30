<?php
/**
 * Tools for the Tough Days — Auth API
 *
 * POST /api/auth.php?action=register
 * POST /api/auth.php?action=login
 * POST /api/auth.php?action=logout
 * POST /api/auth.php?action=delete-account
 * POST /api/auth.php?action=change-password
 * POST /api/auth.php?action=request-password-reset
 * POST /api/auth.php?action=reset-password
 * GET  /api/auth.php?action=me
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/newsletter.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// Only accept POST (or GET for ?action=me)
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// Parse JSON body for POST requests
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true) ?? [];
    }
    // Also accept form-encoded
    if (empty($body)) {
        $body = $_POST;
    }
}

switch ($action) {
    case 'register':
        handle_register($body);
        break;
    case 'login':
        handle_login($body);
        break;
    case 'logout':
        handle_logout();
        break;
    case 'delete-account':
        handle_delete_account($body);
        break;
    case 'change-password':
        handle_change_password($body);
        break;
    case 'request-password-reset':
        handle_request_password_reset($body);
        break;
    case 'reset-password':
        handle_reset_password($body);
        break;
    case 'me':
        handle_me();
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// REGISTER
// ─────────────────────────────────────────────
function handle_register(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $fullName = trim($body['full_name'] ?? '');
    $isBusinessUser = normalize_bool($body['is_business_user'] ?? false);
    $businessName = trim((string) ($body['business_name'] ?? ''));
    $newsletterOptIn = normalize_bool($body['subscribe_newsletter'] ?? false);

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'INVALID_EMAIL', 'Please enter a valid email address.');
    }

    // Validate password (min 8 chars)
    if (strlen($password) < 8) {
        json_error(422, 'WEAK_PASSWORD', 'Password must be at least 8 characters.');
    }

    if ($isBusinessUser && $businessName === '') {
        json_error(422, 'MISSING_BUSINESS_NAME', 'Business name is required for business users.');
    }

    if (!$isBusinessUser) {
        $businessName = '';
    }

    try {
        // Check for existing account
        $existing = db()->prepare('SELECT id FROM users WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetch()) {
            json_error(409, 'EMAIL_EXISTS', 'An account with that email already exists.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = db()->prepare(
            'INSERT INTO users (email, password_hash, full_name, is_business_user, business_name, status, newsletter_opt_in)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            $hash,
            $fullName ?: null,
            $isBusinessUser ? 1 : 0,
            $businessName !== '' ? $businessName : null,
            'active',
            $newsletterOptIn ? 1 : 0,
        ]);
        $userId = (int) db()->lastInsertId();

        $token = create_session($userId);
        audit('user.register', $userId, ['email' => $email]);

        try {
            send_registration_welcome_email($email, $fullName ?: null);
        } catch (Throwable $mailErr) {
            error_log('registration email failed for user ' . $userId . ': ' . $mailErr->getMessage());
        }

        if ($newsletterOptIn) {
            submit_to_vision6($email, $fullName);
        }

        json_ok([
            'user' => [
                'id'         => $userId,
                'email'      => $email,
                'full_name'  => $fullName ?: null,
                'is_business_user' => $isBusinessUser,
                'business_name'    => $businessName !== '' ? $businessName : null,
            ],
            'session_token' => $token,
        ], 201);

    } catch (Throwable $e) {
        error_log('register error: ' . $e->getMessage());
        json_error(500, 'SERVER_ERROR', 'Registration failed. Please try again.');
    }
}

// ─────────────────────────────────────────────
// LOGIN
// ─────────────────────────────────────────────
function handle_login(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if ($email === '' || $password === '') {
        json_error(422, 'MISSING_FIELDS', 'Email and password are required.');
    }

    try {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Use constant-time comparison to prevent timing attacks
        $hash = $user['password_hash'] ?? '$2y$12$invalidsaltinvalidsaltinvalidxx';
        if (!$user || !password_verify($password, $hash)) {
            // Delay on failure to slow brute-force
            usleep(random_int(100_000, 300_000));
            json_error(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
        }

        if ($user['status'] === 'suspended') {
            json_error(403, 'ACCOUNT_SUSPENDED', 'Your account has been suspended. Please contact support.');
        }

        $token = create_session((int) $user['id']);
        audit('user.login', (int) $user['id'], ['email' => $email]);

        json_ok([
            'user' => [
                'id'        => (int) $user['id'],
                'email'     => $user['email'],
                'full_name' => $user['full_name'],
                'is_business_user' => (bool) $user['is_business_user'],
                'business_name'    => $user['business_name'],
            ],
            'session_token' => $token,
        ]);

    } catch (Throwable $e) {
        error_log('login error: ' . $e->getMessage());
        json_error(500, 'SERVER_ERROR', 'Login failed. Please try again.');
    }
}

// ─────────────────────────────────────────────
// LOGOUT
// ─────────────────────────────────────────────
function handle_logout(): never
{
    $user = current_user();
    if ($user) {
        audit('user.logout', (int) $user['id']);
    }
    destroy_session();
    json_ok(['message' => 'Logged out.']);
}

// ─────────────────────────────────────────────
// DELETE ACCOUNT
// ─────────────────────────────────────────────
function handle_delete_account(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $user = require_auth();
    $userId = (int) $user['id'];
    $password = (string) ($body['password'] ?? '');

    if (trim($password) === '') {
        json_error(422, 'MISSING_PASSWORD', 'Password is required to delete your account.');
    }

    try {
        $userStmt = db()->prepare('SELECT email, password_hash FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $dbUser = $userStmt->fetch();

        if (!$dbUser) {
            json_error(404, 'NOT_FOUND', 'Account not found.');
        }

        if (!password_verify($password, (string) $dbUser['password_hash'])) {
            usleep(random_int(100_000, 300_000));
            json_error(401, 'INVALID_CREDENTIALS', 'Password is incorrect.');
        }

        cancel_active_stripe_subscriptions_or_fail($userId);

        db()->beginTransaction();

        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        audit('user.delete', $userId, ['email' => (string) $dbUser['email']]);

        db()->commit();

        destroy_session();
        json_ok(['message' => 'Your account has been permanently deleted.']);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('delete account error for user ' . $userId . ': ' . $e->getMessage());
        json_error(500, 'DELETE_ACCOUNT_FAILED', 'We could not delete your account right now. Please try again.');
    }
}

// ─────────────────────────────────────────────
// CHANGE PASSWORD
// ─────────────────────────────────────────────
function handle_change_password(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $user   = require_auth();
    $userId = (int) $user['id'];

    $currentPassword = (string) ($body['current_password'] ?? '');
    $newPassword     = (string) ($body['new_password'] ?? '');

    if (trim($currentPassword) === '') {
        json_error(422, 'MISSING_CURRENT_PASSWORD', 'Current password is required.');
    }

    if (strlen($newPassword) < 8) {
        json_error(422, 'WEAK_PASSWORD', 'New password must be at least 8 characters.');
    }

    try {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            json_error(404, 'NOT_FOUND', 'Account not found.');
        }

        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            usleep(random_int(100_000, 300_000));
            json_error(401, 'INVALID_CREDENTIALS', 'Current password is incorrect.');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $userId]);

        // Invalidate all other sessions so old sessions can no longer access the account
        $currentToken = $_COOKIE['tttd_session'] ?? '';
        if ($currentToken !== '') {
            db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id != ?')
               ->execute([$userId, $currentToken]);
        } else {
            db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
        }

        audit('user.change_password', $userId);
        json_ok(['message' => 'Password updated successfully.']);

    } catch (Throwable $e) {
        error_log('change password error for user ' . $userId . ': ' . $e->getMessage());
        json_error(500, 'SERVER_ERROR', 'Could not update password. Please try again.');
    }
}

// ─────────────────────────────────────────────
// REQUEST PASSWORD RESET
// ─────────────────────────────────────────────
function handle_request_password_reset(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(422, 'INVALID_EMAIL', 'Please enter a valid email address.');
    }

    $genericResponse = [
        'message' => 'If the email exists, a password reset link has been sent.',
    ];

    try {
        $stmt = db()->prepare(
            'SELECT id, email, full_name, status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || (string) ($user['status'] ?? '') === 'suspended') {
            usleep(random_int(100_000, 250_000));
            json_ok($genericResponse);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $userId = (int) $user['id'];
        $expiresMinutes = password_reset_ttl_minutes();
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresMinutes * 60));

        db()->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);

        db()->prepare(
            'INSERT INTO password_reset_tokens (token_hash, user_id, requested_ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $tokenHash,
            $userId,
            client_ip_address(),
            client_user_agent(),
            $expiresAt,
        ]);

        $resetLink = rtrim((string) SITE_URL, '/') . '/forgot-password.html?token=' . urlencode($token);

        try {
            send_password_reset_email(
                (string) $user['email'],
                $user['full_name'] ? (string) $user['full_name'] : null,
                $resetLink,
                $expiresMinutes
            );
        } catch (Throwable $mailErr) {
            error_log('password reset email failed for user ' . $userId . ': ' . $mailErr->getMessage());
        }

        audit('user.password_reset_requested', $userId);
        json_ok($genericResponse);
    } catch (Throwable $e) {
        error_log('request password reset error: ' . $e->getMessage());
        json_ok($genericResponse);
    }
}

// ─────────────────────────────────────────────
// RESET PASSWORD BY TOKEN
// ─────────────────────────────────────────────
function handle_reset_password(array $body): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
    }

    $token = trim((string) ($body['token'] ?? ''));
    $newPassword = (string) ($body['new_password'] ?? '');

    if ($token === '') {
        json_error(422, 'MISSING_TOKEN', 'Reset token is required.');
    }

    if (strlen($newPassword) < 8) {
        json_error(422, 'WEAK_PASSWORD', 'New password must be at least 8 characters.');
    }

    $tokenHash = hash('sha256', $token);

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'SELECT prt.token_hash, prt.user_id, u.status
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = ?
               AND prt.used_at IS NULL
               AND prt.expires_at > NOW()
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        if (!$row || (string) ($row['status'] ?? '') === 'suspended') {
            db()->rollBack();
            usleep(random_int(120_000, 280_000));
            json_error(400, 'INVALID_OR_EXPIRED_TOKEN', 'This reset link is invalid or expired.');
        }

        $userId = (int) $row['user_id'];
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $userId]);

        db()->prepare(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);

        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);

        db()->commit();
        audit('user.password_reset_completed', $userId);

        json_ok(['message' => 'Password has been reset. You can now sign in.']);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        error_log('reset password error: ' . $e->getMessage());
        json_error(500, 'SERVER_ERROR', 'Could not reset password. Please try again.');
    }
}

// ─────────────────────────────────────────────
// ME  (current user info)
// ─────────────────────────────────────────────
function handle_me(): never
{
    $user = require_auth();

    // Fetch active subscription if any
    $subStmt = db()->prepare(
        "SELECT product_key, plan_type, status, current_period_end, cancel_at_period_end
         FROM subscriptions
         WHERE user_id = ? AND status IN ('active','trialing','past_due')
         ORDER BY created_at DESC LIMIT 1"
    );
    $subStmt->execute([(int) $user['id']]);
    $subscription = $subStmt->fetch() ?: null;

    json_ok([
        'user' => [
            'id'                 => (int) $user['id'],
            'email'              => $user['email'],
            'full_name'          => $user['full_name'],
            'is_business_user'   => (bool) $user['is_business_user'],
            'business_name'      => $user['business_name'],
        ],
        'subscription' => $subscription,
    ]);
}

/**
 * Accepts common boolean-like values from JSON/forms.
 */
function normalize_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        $v = strtolower(trim($value));
        return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    return false;
}

function password_reset_ttl_minutes(): int
{
    $ttl = (int) (getenv('PASSWORD_RESET_TTL_MIN') ?: 60);
    return max(10, min($ttl, 180));
}

function client_ip_address(): ?string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if (!$ip) {
        return null;
    }

    return trim(explode(',', $ip)[0]);
}

function client_user_agent(): ?string
{
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return null;
    }

    return substr($ua, 0, 512);
}

function cancel_active_stripe_subscriptions_or_fail(int $userId): void
{
    $subStmt = db()->prepare(
        "SELECT stripe_subscription_id
         FROM subscriptions
         WHERE user_id = ?
           AND stripe_subscription_id IS NOT NULL
           AND status IN ('active','trialing','past_due','unpaid')"
    );
    $subStmt->execute([$userId]);
    $rows = $subStmt->fetchAll() ?: [];

    $subscriptionIds = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['stripe_subscription_id'] ?? ''));
        if ($id !== '') {
            $subscriptionIds[] = $id;
        }
    }

    if ($subscriptionIds === []) {
        return;
    }

    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }

    if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
        throw new RuntimeException('Active subscription exists but STRIPE_SECRET_KEY is missing.');
    }

    require_once __DIR__ . '/../stripe-php/init.php';
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    foreach ($subscriptionIds as $subscriptionId) {
        try {
            $stripeSub = \Stripe\Subscription::retrieve($subscriptionId);
            $stripeSub->cancel();
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Failed to cancel Stripe subscription before account deletion: ' . $e->getMessage());
        }

        db()->prepare(
            "UPDATE subscriptions
             SET status = 'cancelled',
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 cancel_at_period_end = 0,
                 updated_at = NOW()
             WHERE stripe_subscription_id = ?"
        )->execute([$subscriptionId]);
    }
}
