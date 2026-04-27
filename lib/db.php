<?php
/**
 * Tools for the Tough Days — Database connection
 * Returns a singleton PDO instance.
 * Config is read exclusively from environment variables.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host   = getenv('DB_HOST') ?: '127.0.0.1';
    $port   = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: 'tttd';
    $user   = getenv('DB_USER') ?: '';
    $pass   = getenv('DB_PASS') ?: '';

    if ($user === '') {
        throw new RuntimeException('DB_USER environment variable is not set.');
    }

    $sslMode = strtolower((string) (getenv('DB_SSL_MODE') ?: 'require'));
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    if ($sslMode !== 'disable') {
        $dsn .= ';sslmode=require';
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Azure MySQL commonly enforces TLS (require_secure_transport=ON).
    // Use system CA bundle by default; override via DB_SSL_CA if needed.
    if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $sslCa = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
        if (is_readable($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }
    }

    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $pdo = new PDO($dsn, $user, $pass, $options);

    return $pdo;
}

/**
 * Write one row to audit_logs.
 */
function audit(string $action, ?int $userId = null, array $details = []): void
{
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        if ($ip) {
            // Use only the first IP if forwarded list
            $ip = trim(explode(',', $ip)[0]);
        }

        db()->prepare(
            'INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)'
        )->execute([
            $userId,
            $action,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $ip,
        ]);
    } catch (Throwable $e) {
        error_log('audit() failed: ' . $e->getMessage());
    }
}
