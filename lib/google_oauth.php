<?php
/**
 * Tools for the Tough Days — Google Sign-In verification
 *
 * Verifies the ID token returned by Google Identity Services (GIS) in the
 * browser against Google's tokeninfo endpoint. Requires GOOGLE_CLIENT_ID to
 * be set (see config.php).
 */

declare(strict_types=1);

const GOOGLE_TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

/**
 * Verifies a Google ID token and returns its claims, or null if the token
 * is missing, expired, badly signed, or was not issued for this app.
 *
 * @return array{sub:string,email:string,email_verified:bool,name:?string,picture:?string}|null
 */
function verify_google_id_token(string $idToken): ?array
{
    $idToken = trim($idToken);
    if ($idToken === '' || substr_count($idToken, '.') !== 2) {
        return null;
    }

    if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '') {
        error_log('verify_google_id_token: GOOGLE_CLIENT_ID is not configured.');
        return null;
    }

    $url = GOOGLE_TOKENINFO_URL . '?id_token=' . urlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'TTTD-Server/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200 || !$response) {
        error_log('verify_google_id_token: tokeninfo request failed — HTTP ' . $httpCode . ' — ' . $curlErr);
        return null;
    }

    $claims = json_decode($response, true);
    if (!is_array($claims)) {
        return null;
    }

    $aud = (string) ($claims['aud'] ?? '');
    $iss = (string) ($claims['iss'] ?? '');
    $exp = (int) ($claims['exp'] ?? 0);
    $sub = (string) ($claims['sub'] ?? '');
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    $emailVerified = in_array($claims['email_verified'] ?? '', ['true', true, '1', 1], true);

    if (!hash_equals(GOOGLE_CLIENT_ID, $aud)) {
        error_log('verify_google_id_token: aud mismatch (token not issued for this app).');
        return null;
    }

    if ($iss !== 'accounts.google.com' && $iss !== 'https://accounts.google.com') {
        return null;
    }

    if ($exp <= time()) {
        return null;
    }

    if ($sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return [
        'sub'            => $sub,
        'email'          => $email,
        'email_verified' => $emailVerified,
        'name'           => isset($claims['name']) ? trim((string) $claims['name']) : null,
        'picture'        => isset($claims['picture']) ? (string) $claims['picture'] : null,
    ];
}
