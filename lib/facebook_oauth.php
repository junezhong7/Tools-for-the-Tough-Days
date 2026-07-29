<?php
/**
 * Tools for the Tough Days — Facebook Login verification
 *
 * Verifies the access token returned by the Facebook JS SDK in the browser
 * against the Graph API, then fetches the profile. Requires FACEBOOK_APP_ID
 * and FACEBOOK_APP_SECRET to be set (see config.php).
 */

declare(strict_types=1);

const FACEBOOK_GRAPH_VERSION = 'v21.0';
const FACEBOOK_DEBUG_TOKEN_URL = 'https://graph.facebook.com/debug_token';

/**
 * Verifies a Facebook user access token and returns the linked profile, or
 * null if the token is missing, expired, or was not issued for this app.
 *
 * @return array{id:string,email:?string,name:?string}|null
 */
function verify_facebook_access_token(string $accessToken): ?array
{
    $accessToken = trim($accessToken);
    if ($accessToken === '') {
        return null;
    }

    if (!defined('FACEBOOK_APP_ID') || FACEBOOK_APP_ID === '' || !defined('FACEBOOK_APP_SECRET') || FACEBOOK_APP_SECRET === '') {
        error_log('verify_facebook_access_token: FACEBOOK_APP_ID/FACEBOOK_APP_SECRET is not configured.');
        return null;
    }

    $appAccessToken = FACEBOOK_APP_ID . '|' . FACEBOOK_APP_SECRET;

    // Confirm the token was issued for THIS app before trusting it.
    $debugUrl = FACEBOOK_DEBUG_TOKEN_URL . '?' . http_build_query([
        'input_token'  => $accessToken,
        'access_token' => $appAccessToken,
    ]);

    $debugInfo = facebook_graph_get($debugUrl);
    if ($debugInfo === null) {
        return null;
    }

    $data = $debugInfo['data'] ?? null;
    if (!is_array($data) || empty($data['is_valid'])) {
        return null;
    }

    if ((string) ($data['app_id'] ?? '') !== FACEBOOK_APP_ID) {
        error_log('verify_facebook_access_token: app_id mismatch (token not issued for this app).');
        return null;
    }

    $expiresAt = (int) ($data['expires_at'] ?? 0);
    if ($expiresAt > 0 && $expiresAt <= time()) {
        return null;
    }

    // Fetch the profile using the caller's own token (not the app token).
    $meUrl = 'https://graph.facebook.com/' . FACEBOOK_GRAPH_VERSION . '/me?' . http_build_query([
        'fields'       => 'id,name,email',
        'access_token' => $accessToken,
    ]);

    $profile = facebook_graph_get($meUrl);
    if ($profile === null) {
        return null;
    }

    $id = (string) ($profile['id'] ?? '');
    if ($id === '' || $id !== (string) ($data['user_id'] ?? $id)) {
        return null;
    }

    $email = isset($profile['email']) ? strtolower(trim((string) $profile['email'])) : null;
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = null;
    }

    return [
        'id'    => $id,
        'email' => $email,
        'name'  => isset($profile['name']) ? trim((string) $profile['name']) : null,
    ];
}

/**
 * GETs a Graph API URL and returns the decoded JSON body, or null on any
 * transport/HTTP/decode error.
 */
function facebook_graph_get(string $url): ?array
{
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
        error_log('facebook_graph_get: request failed — HTTP ' . $httpCode . ' — ' . $curlErr);
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}
