<?php
/**
 * Tools for the Tough Days — Web Push (phone / browser notifications)
 *
 * Dependency-free implementation of the two standards a push service needs:
 *   - VAPID (RFC 8292): an ES256-signed JWT that identifies us to the push service.
 *   - Message encryption (RFC 8291, aes128gcm): the payload is encrypted to the
 *     browser's own key, so Google / Apple / Mozilla relay it without reading it.
 *
 * Push is managed separately from email reminders: one row per browser/device
 * in push_subscriptions, turned on and off from my-preference.html.
 *
 * Config (environment variables):
 *   VAPID_PUBLIC_KEY   base64url, 65-byte uncompressed P-256 point
 *   VAPID_PRIVATE_KEY  base64url, 32-byte P-256 private key
 *   VAPID_SUBJECT      optional contact (mailto: or https:), defaults to SITE_URL
 *
 * Generate a key pair with:  npx web-push generate-vapid-keys
 *
 * Usage:
 *   require_once __DIR__ . '/../lib/webpush.php';
 *   $summary = push_send_to_user($userId, ['title' => '...', 'body' => '...', 'url' => '/dashboard.html']);
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$configFile = __DIR__ . '/../config.php';
if (is_readable($configFile)) {
    require_once $configFile;
}

const WEBPUSH_RECORD_SIZE = 4096;
const WEBPUSH_MAX_PAYLOAD = 3000;           // bytes of JSON; push services cap the encrypted body at 4096
const WEBPUSH_JWT_TTL_SEC = 12 * 60 * 60;   // VAPID tokens may live at most 24h
const WEBPUSH_DEFAULT_TTL = 24 * 60 * 60;   // how long the push service holds an undelivered message

// Push services browsers actually use. Endpoints come from the browser, so the
// allowlist stops a member from pointing the server at an arbitrary URL.
const WEBPUSH_ALLOWED_HOST_SUFFIXES = [
    'fcm.googleapis.com',        // Chrome, Android, Samsung Internet, Opera
    'android.googleapis.com',    // older Chrome
    'push.services.mozilla.com', // Firefox
    'push.apple.com',            // Safari, iOS / iPadOS Home Screen apps
    'notify.windows.com',        // Edge
];

// DER prefix that wraps a raw 65-byte P-256 point into a SubjectPublicKeyInfo.
const WEBPUSH_P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

// ─────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────

function webpush_public_key(): string
{
    return trim((string) (getenv('VAPID_PUBLIC_KEY') ?: ''));
}

function webpush_is_configured(): bool
{
    return webpush_public_key() !== '' && trim((string) (getenv('VAPID_PRIVATE_KEY') ?: '')) !== '';
}

/**
 * Returns null when the VAPID keys load and form a matching pair, otherwise a
 * human-readable reason. Shown on the admin page to catch config mistakes.
 */
function webpush_config_error(): ?string
{
    if (!webpush_is_configured()) {
        return 'VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY are not set.';
    }

    try {
        $publicRaw = webpush_b64url_decode(webpush_public_key());
        if (webpush_p256_public_raw(webpush_vapid_private_key()) !== $publicRaw) {
            return 'VAPID_PUBLIC_KEY does not match VAPID_PRIVATE_KEY.';
        }
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
}

function webpush_vapid_subject(): string
{
    $subject = trim((string) (getenv('VAPID_SUBJECT') ?: ''));
    if ($subject !== '') {
        return $subject;
    }

    // Apple rejects subjects that aren't a public https: URL or a mailto: address.
    $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
    return str_starts_with($siteUrl, 'https://') ? $siteUrl : 'https://www.toolsforthetoughdays.com.au';
}

function webpush_vapid_private_key(): OpenSSLAsymmetricKey
{
    static $key = null;
    return $key ??= webpush_p256_private_key(webpush_b64url_decode((string) getenv('VAPID_PRIVATE_KEY')));
}

function webpush_endpoint_allowed(string $endpoint): bool
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
        return false;
    }
    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }

    $host = strtolower($parts['host']);
    foreach (WEBPUSH_ALLOWED_HOST_SUFFIXES as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return true;
        }
    }
    return false;
}

// ─────────────────────────────────────────────
// SENDING
// ─────────────────────────────────────────────

/**
 * Sends one notification to one browser subscription.
 *
 * $subscription keys are base64url strings, exactly as PushSubscription.toJSON() reports them.
 * $payload is JSON-encoded and read by sw.js (title, body, url, tag).
 *
 * @param array{endpoint:string,p256dh:string,auth:string} $subscription
 * @return array{ok:bool,status:int,gone:bool,error:?string}
 */
function webpush_send(array $subscription, array $payload, int $ttl = WEBPUSH_DEFAULT_TTL, string $urgency = 'normal'): array
{
    $fail = static fn (string $error, int $status = 0): array
        => ['ok' => false, 'status' => $status, 'gone' => false, 'error' => $error];

    if (!webpush_is_configured()) {
        return $fail('Push is not configured.');
    }
    if (!webpush_endpoint_allowed($subscription['endpoint'])) {
        return $fail('Endpoint is not a recognised push service.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > WEBPUSH_MAX_PAYLOAD) {
        return $fail('Payload is too large or could not be encoded.');
    }

    try {
        $body = webpush_encrypt(
            $json,
            webpush_b64url_decode($subscription['p256dh']),
            webpush_b64url_decode($subscription['auth'])
        );
        $authorization = webpush_vapid_authorization($subscription['endpoint']);
    } catch (Throwable $e) {
        return $fail($e->getMessage());
    }

    $ch = curl_init($subscription['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $authorization,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . max(0, $ttl),
            'Urgency: ' . $urgency,
        ],
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return $fail('cURL: ' . $curlErr);
    }

    $ok = $status >= 200 && $status < 300;
    return [
        'ok'     => $ok,
        'status' => $status,
        // 404/410: the browser unsubscribed or the subscription expired; never retry it.
        'gone'   => $status === 404 || $status === 410,
        'error'  => $ok ? null : trim(substr((string) $response, 0, 300)),
    ];
}

/**
 * Builds the "Authorization: vapid t=<jwt>, k=<public key>" header value.
 */
function webpush_vapid_authorization(string $endpoint): string
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('Invalid push endpoint.');
    }
    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $header = webpush_b64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = webpush_b64url_encode((string) json_encode([
        'aud' => $audience,
        'exp' => time() + WEBPUSH_JWT_TTL_SEC,
        'sub' => webpush_vapid_subject(),
    ], JSON_UNESCAPED_SLASHES));

    $signingInput = $header . '.' . $claims;
    if (!openssl_sign($signingInput, $derSignature, webpush_vapid_private_key(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID signing failed.');
    }

    $jwt = $signingInput . '.' . webpush_b64url_encode(webpush_ecdsa_der_to_raw($derSignature));
    $k   = webpush_b64url_encode(webpush_b64url_decode(webpush_public_key()));

    return 'vapid t=' . $jwt . ', k=' . $k;
}

/**
 * Encrypts a payload for one subscription (RFC 8291 / RFC 8188 aes128gcm, single record).
 *
 * $senderKey and $salt are only passed by tests; normally both are fresh per message.
 */
function webpush_encrypt(
    string $plaintext,
    string $uaPublic,
    string $authSecret,
    ?OpenSSLAsymmetricKey $senderKey = null,
    ?string $salt = null
): string {
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
        throw new InvalidArgumentException('Invalid p256dh key.');
    }
    if (strlen($authSecret) !== 16) {
        throw new InvalidArgumentException('Invalid auth secret.');
    }

    if ($senderKey === null) {
        $senderKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($senderKey === false) {
            throw new RuntimeException('Could not generate an ephemeral key.');
        }
    }
    $salt ??= random_bytes(16);
    $asPublic = webpush_p256_public_raw($senderKey);

    $uaKey = openssl_pkey_get_public(webpush_pem('PUBLIC KEY', hex2bin(WEBPUSH_P256_SPKI_PREFIX) . $uaPublic));
    if ($uaKey === false) {
        throw new InvalidArgumentException('p256dh is not a valid P-256 point.');
    }

    $ecdhSecret = openssl_pkey_derive($uaKey, $senderKey);
    if ($ecdhSecret === false || strlen($ecdhSecret) !== 32) {
        throw new RuntimeException('ECDH key agreement failed.');
    }

    $ikm   = hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    // One record: plaintext + 0x02 delimiter (marks the last record), no extra padding.
    $ciphertext = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Payload encryption failed.');
    }

    // Header: salt(16) | record size(4) | key id length(1) | key id = sender public key(65)
    return $salt . pack('N', WEBPUSH_RECORD_SIZE) . chr(65) . $asPublic . $ciphertext . $tag;
}

// ─────────────────────────────────────────────
// KEY + ENCODING HELPERS
// ─────────────────────────────────────────────

function webpush_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function webpush_b64url_decode(string $value): string
{
    $value = strtr(trim($value), '-_', '+/');
    $remainder = strlen($value) % 4;
    if ($remainder !== 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    $bin = base64_decode($value, true);
    if ($bin === false) {
        throw new InvalidArgumentException('Invalid base64url value.');
    }
    return $bin;
}

function webpush_pem(string $label, string $der): string
{
    return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
}

/**
 * Loads a raw 32-byte P-256 private key. OpenSSL derives the public point itself.
 */
function webpush_p256_private_key(string $d): OpenSSLAsymmetricKey
{
    if (strlen($d) !== 32) {
        throw new InvalidArgumentException('P-256 private key must be 32 bytes.');
    }

    // SEC1 ECPrivateKey { version 1, privateKey d, parameters prime256v1 }
    $der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');
    $key = openssl_pkey_get_private(webpush_pem('EC PRIVATE KEY', $der));
    if ($key === false) {
        throw new RuntimeException('Could not load the P-256 private key.');
    }
    return $key;
}

/**
 * Returns the uncompressed public point (0x04 | X | Y) of an EC key.
 */
function webpush_p256_public_raw(OpenSSLAsymmetricKey $key): string
{
    $ec = openssl_pkey_get_details($key)['ec'] ?? null;
    if (!is_array($ec) || !isset($ec['x'], $ec['y'])) {
        throw new RuntimeException('Not an EC key.');
    }
    // OpenSSL drops leading zero bytes, so coordinates can come back shorter than 32.
    return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
}

/**
 * Converts OpenSSL's DER ECDSA signature into the raw r|s form JWS requires.
 */
function webpush_ecdsa_der_to_raw(string $der): string
{
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        throw new RuntimeException('Unexpected signature format.');
    }
    $offset++; // sequence length; P-256 signatures are always short-form

    $raw = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Unexpected signature format.');
        }
        $length  = ord($der[$offset++]);
        $integer = ltrim(substr($der, $offset, $length), "\0");
        $offset += $length;
        $raw .= str_pad($integer, 32, "\0", STR_PAD_LEFT);
    }
    return $raw;
}

// ─────────────────────────────────────────────
// SUBSCRIPTION STORAGE
// ─────────────────────────────────────────────

function push_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         INT UNSIGNED    NOT NULL,
            endpoint        TEXT            NOT NULL,
            endpoint_hash   CHAR(64)        NOT NULL,
            p256dh          VARCHAR(255)    NOT NULL,
            auth_secret     VARCHAR(64)     NOT NULL,
            user_agent      VARCHAR(512)    NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_success_at DATETIME        NULL,
            last_failure_at DATETIME        NULL,
            failure_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
            KEY idx_push_user (user_id),
            CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

/**
 * Stores this browser's subscription for the member. The endpoint is unique per
 * browser profile, so on a shared device it moves to whoever turned it on last.
 *
 * Returns true when the device is new for this member (used to limit audit noise,
 * since the preferences page re-syncs the subscription on every visit).
 */
function push_save_subscription(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): bool
{
    push_ensure_table();

    $hash = hash('sha256', $endpoint);
    $existing = db()->prepare('SELECT user_id FROM push_subscriptions WHERE endpoint_hash = ?');
    $existing->execute([$hash]);
    $previousOwner = $existing->fetchColumn();

    db()->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_secret, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_id       = VALUES(user_id),
            p256dh        = VALUES(p256dh),
            auth_secret   = VALUES(auth_secret),
            user_agent    = VALUES(user_agent),
            last_seen_at  = NOW(),
            failure_count = 0'
    )->execute([$userId, $endpoint, $hash, $p256dh, $auth, substr($userAgent, 0, 512)]);

    return $previousOwner === false || (int) $previousOwner !== $userId;
}

function push_delete_subscription(int $userId, string $endpoint): bool
{
    push_ensure_table();

    $stmt = db()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?');
    $stmt->execute([$userId, hash('sha256', $endpoint)]);
    return $stmt->rowCount() > 0;
}

function push_find_subscription(int $userId, string $endpoint): ?array
{
    push_ensure_table();

    $stmt = db()->prepare(
        'SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?'
    );
    $stmt->execute([$userId, hash('sha256', $endpoint)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sends to every device a member has turned on.
 *
 * @return array{devices:int,sent:int,failed:int,removed:int,errors:list<string>}
 */
function push_send_to_user(int $userId, array $payload, int $ttl = WEBPUSH_DEFAULT_TTL): array
{
    push_ensure_table();

    $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth_secret FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    return push_send_to_rows($stmt->fetchAll(), $payload, $ttl);
}

/**
 * Sends to a list of push_subscriptions rows and records the outcome on each row.
 * Subscriptions the push service reports as gone are deleted.
 *
 * @return array{devices:int,sent:int,failed:int,removed:int,errors:list<string>}
 */
function push_send_to_rows(array $rows, array $payload, int $ttl = WEBPUSH_DEFAULT_TTL): array
{
    $summary = ['devices' => count($rows), 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];

    foreach ($rows as $row) {
        $result = webpush_send(
            ['endpoint' => (string) $row['endpoint'], 'p256dh' => (string) $row['p256dh'], 'auth' => (string) $row['auth_secret']],
            $payload,
            $ttl
        );

        if ($result['ok']) {
            $summary['sent']++;
            db()->prepare('UPDATE push_subscriptions SET last_success_at = NOW(), failure_count = 0 WHERE id = ?')
                ->execute([$row['id']]);
        } elseif ($result['gone']) {
            $summary['removed']++;
            db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$row['id']]);
        } else {
            $summary['failed']++;
            $error = 'HTTP ' . $result['status'] . ': ' . ($result['error'] ?? 'unknown error');
            if (count($summary['errors']) < 5 && !in_array($error, $summary['errors'], true)) {
                $summary['errors'][] = $error;
            }
            error_log("[webpush] subscription_id={$row['id']} {$error}");
            db()->prepare(
                'UPDATE push_subscriptions SET last_failure_at = NOW(), failure_count = failure_count + 1 WHERE id = ?'
            )->execute([$row['id']]);
        }
    }

    return $summary;
}
