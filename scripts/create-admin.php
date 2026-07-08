<?php
/**
 * Tools for the Tough Days — create an admin (ops) account
 *
 * CLI-only. Prompts for a password and stores a bcrypt hash — never pass
 * a password as a command-line argument (it would end up in shell history
 * and process listings).
 *
 * Usage:
 *   php scripts/create-admin.php <email> ["Full Name"]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../lib/db.php';

$email    = strtolower(trim((string) ($argv[1] ?? '')));
$fullName = trim((string) ($argv[2] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <email> [\"Full Name\"]\n");
    fwrite(STDERR, "Error: a valid email address is required.\n");
    exit(1);
}

$existing = db()->prepare('SELECT id FROM admin_users WHERE email = ?');
$existing->execute([$email]);
if ($existing->fetch()) {
    fwrite(STDERR, "Error: an admin account with that email already exists.\n");
    exit(1);
}

fwrite(STDOUT, "Password (min 8 characters): ");
$password = read_line_hidden();

fwrite(STDOUT, "Confirm password: ");
$confirm = read_line_hidden();

if ($password !== $confirm) {
    fwrite(STDERR, "Error: passwords did not match.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Error: password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = db()->prepare(
    'INSERT INTO admin_users (email, password_hash, full_name, status) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$email, $hash, $fullName !== '' ? $fullName : null, 'active']);

fwrite(STDOUT, "Created admin account #" . db()->lastInsertId() . " ({$email}).\n");

/**
 * Reads a line from STDIN without echoing it back, where the terminal supports it.
 * Falls back to a plain (visible) prompt on platforms without `stty` (e.g. Windows cmd).
 */
function read_line_hidden(): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        return trim((string) fgets(STDIN));
    }

    system('stty -echo');
    $value = trim((string) fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");

    return $value;
}
