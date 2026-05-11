<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$action = strtolower(trim((string) ($_GET['action'] ?? 'issue')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET') {
    json_error(405, 'METHOD_NOT_ALLOWED', 'Only GET is supported.');
}

// The stream action is authenticated via its own signed token (no session cookie needed).
// PDF.js fetches the stream URL internally and may not always forward session cookies.
if ($action === 'stream') {
    handle_stream();
}

// All other actions require a valid session.
$user = require_auth();
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    json_error(401, 'Unauthenticated', 'You must be logged in.');
}

switch ($action) {
    case 'topics':
        handle_topics($userId);
        break;
    case 'list':
        handle_list($userId);
        break;
    case 'issue':
        handle_issue($userId);
        break;
    case 'resolve':
        handle_resolve($userId);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

function handle_topics(int $userId): never
{
    if (!user_has_active_subscription($userId)) {
        audit('resource.topics.denied.subscription', $userId, []);
        json_error(403, 'SUBSCRIPTION_REQUIRED', 'Active subscription required.');
    }

    $topics = get_topic_prefix_map();

    audit('resource.topics.ok', $userId, [
        'count' => count($topics),
    ]);

    json_ok([
        'count' => count($topics),
        'topics' => $topics,
    ]);
}

function get_topic_prefix_map(): array
{
    return [
        'crisis-support' => ['title' => 'Crisis support', 'label' => 'Crisis support', 'icon' => '🆘', 'order' => 10, 'prefixes' => ['CRISIS']],
        'domestic-violence-abuse' => ['title' => 'Domestic violence & abuse', 'label' => 'Domestic violence & abuse', 'icon' => '🏠', 'order' => 20, 'prefixes' => ['DMV']],
        'grief-loss' => ['title' => 'Grief & loss', 'label' => 'Grief & loss', 'icon' => '🕊️', 'order' => 30, 'prefixes' => ['GRIEF']],
        'low-mood-depression' => ['title' => 'Low mood & depression', 'label' => 'Low mood & depression', 'icon' => '💙', 'order' => 40, 'prefixes' => ['DEP']],
        'anxiety-worry' => ['title' => 'Anxiety & worry', 'label' => 'Anxiety & worry', 'icon' => '🌀', 'order' => 50, 'prefixes' => ['ANX']],
        'self-esteem' => ['title' => 'Self-esteem', 'label' => 'Self-esteem', 'icon' => '💪', 'order' => 60, 'prefixes' => ['EST']],
        'feeling-flat-angry' => ['title' => 'Feeling flat or angry', 'label' => 'Feeling flat or angry', 'icon' => '😶', 'order' => 70, 'prefixes' => ['MHW']],
        'postnatal-struggles' => ['title' => 'Postnatal struggles', 'label' => 'Postnatal struggles', 'icon' => '🍼', 'order' => 80, 'prefixes' => ['PNN']],
        'pregnancy' => ['title' => 'Pregnancy', 'label' => 'Pregnancy', 'icon' => '🤰', 'order' => 90, 'prefixes' => ['PRG']],
        'expecting-fathers' => ['title' => 'Expecting fathers', 'label' => 'Expecting fathers', 'icon' => '👨‍👧', 'order' => 100, 'prefixes' => ['DAD']],
        'gambling-substance' => ['title' => 'Gambling & substance use', 'label' => 'Gambling & substance use', 'icon' => '🎲', 'order' => 110, 'prefixes' => ['GSA']],
        'daily-habits-wellbeing' => ['title' => 'Daily habits & wellbeing', 'label' => 'Daily habits & wellbeing', 'icon' => '🌱', 'order' => 120, 'prefixes' => ['WB', 'MHW']],
        'relationships' => ['title' => 'Relationships', 'label' => 'Relationships', 'icon' => '❤️', 'order' => 130, 'prefixes' => ['REL']],
        'parenting-young-children' => ['title' => 'Parenting young children', 'label' => 'Parenting young children', 'icon' => '👶', 'order' => 140, 'prefixes' => ['PYC']],
        'parenting-teenagers' => ['title' => 'Parenting teenagers', 'label' => 'Parenting teenagers', 'icon' => '🧒', 'order' => 150, 'prefixes' => ['PTN']],
        'getting-older' => ['title' => 'Getting older', 'label' => 'Getting older', 'icon' => '🧓', 'order' => 160, 'prefixes' => ['AGE']],
        'identity-belonging' => ['title' => 'Identity & belonging', 'label' => 'Identity & belonging', 'icon' => '🌍', 'order' => 170, 'prefixes' => ['IDN']],
        'supporting-someone-else' => ['title' => 'Supporting someone else', 'label' => 'Supporting someone else', 'icon' => '🤝', 'order' => 180, 'prefixes' => ['AOK']],
        'financial-stress' => ['title' => 'Financial stress', 'label' => 'Financial stress', 'icon' => '💸', 'order' => 190, 'prefixes' => ['FIN']],
        'big-life-changes' => ['title' => 'Big life changes', 'label' => 'Big life changes', 'icon' => '🔄', 'order' => 200, 'prefixes' => ['LT']],
        'stress-about-future' => ['title' => 'Stress about the future', 'label' => 'Stress about the future', 'icon' => '🔮', 'order' => 210, 'prefixes' => ['FUT']],
        'trauma-difficult-memories' => ['title' => 'Trauma & difficult memories', 'label' => 'Trauma & difficult memories', 'icon' => '💭', 'order' => 215, 'prefixes' => ['TRM']],
        'workplace-stress-pressure' => ['title' => 'Workplace stress & pressure', 'label' => 'Workplace stress & pressure', 'icon' => '📊', 'order' => 220, 'prefixes' => ['WSP']],
        'burnout' => ['title' => 'Burnout', 'label' => 'Burnout', 'icon' => '🔋', 'order' => 230, 'prefixes' => ['BRN']],
        'difficult-workplace-relationships' => ['title' => 'Difficult workplace relationships', 'label' => 'Difficult workplace relationships', 'icon' => '🤯', 'order' => 240, 'prefixes' => ['WPR']],
        'work-life-balance' => ['title' => 'Work-life balance', 'label' => 'Work-life balance', 'icon' => '⚖️', 'order' => 250, 'prefixes' => ['WLB']],
        'job-loss-unemployment' => ['title' => 'Job loss & unemployment', 'label' => 'Job loss & unemployment', 'icon' => '📉', 'order' => 260, 'prefixes' => ['JLU']],
        'career-uncertainty-change' => ['title' => 'Career uncertainty & change', 'label' => 'Career uncertainty & change', 'icon' => '🧭', 'order' => 270, 'prefixes' => ['CUC']],
    ];
}

function handle_list(int $userId): never
{
    if (!user_has_active_subscription($userId)) {
        audit('resource.list.denied.subscription', $userId, []);
        json_error(403, 'SUBSCRIPTION_REQUIRED', 'Active subscription required.');
    }

    $prefixesRaw = trim((string) ($_GET['prefixes'] ?? ''));
    if ($prefixesRaw === '') {
        json_error(422, 'MISSING_PREFIXES', 'prefixes is required.');
    }

    $requestedPrefixes = array_filter(array_map(
        static function (string $value): string {
            $cleaned = strtoupper(trim($value));
            return preg_replace('/[^A-Z0-9-]/', '', $cleaned) ?? '';
        },
        explode(',', $prefixesRaw)
    ));

    if (count($requestedPrefixes) === 0) {
        json_error(422, 'INVALID_PREFIXES', 'No valid prefixes were provided.');
    }

    $resourceMap = load_resource_map_from_csv();
    $items = [];

    foreach ($resourceMap as $resourceKey => $resource) {
        foreach ($requestedPrefixes as $prefix) {
            if (strpos($resourceKey, $prefix . '-') === 0 || $resourceKey === $prefix) {
                $hasPdf = trim((string) ($resource['pdf_blob'] ?? '')) !== '';
                $hasVideo = trim((string) ($resource['video_blob'] ?? '')) !== '';

                $items[] = [
                    'resource_key' => $resourceKey,
                    'name' => (string) ($resource['name'] ?? $resourceKey),
                    'has_pdf' => $hasPdf,
                    'has_video' => $hasVideo,
                ];
                break;
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) $a['resource_key'], (string) $b['resource_key']);
    });

    audit('resource.list.ok', $userId, [
        'prefixes' => array_values($requestedPrefixes),
        'count' => count($items),
    ]);

    json_ok([
        'prefixes' => array_values($requestedPrefixes),
        'count' => count($items),
        'resources' => $items,
    ]);
}

function handle_issue(int $userId): never
{
    if (!user_has_active_subscription($userId)) {
        audit('resource.issue.denied.subscription', $userId, []);
        json_error(403, 'SUBSCRIPTION_REQUIRED', 'Active subscription required.');
    }

    $resourceKey = strtoupper(trim((string) ($_GET['resource_key'] ?? '')));
    $kind = normalize_kind((string) ($_GET['kind'] ?? 'pdf'));

    if ($resourceKey === '') {
        json_error(422, 'MISSING_RESOURCE', 'resource_key is required.');
    }

    $resource = get_resource_by_key($resourceKey);
    if ($resource === null) {
        audit('resource.issue.not_found', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_NOT_FOUND', 'Resource is not available.');
    }

    $blobName = $kind === 'video' ? ($resource['video_blob'] ?? '') : ($resource['pdf_blob'] ?? '');
    if ($blobName === '') {
        audit('resource.issue.missing_blob', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_FORMAT_NOT_FOUND', 'Requested format is not available.');
    }

    $expiresAt = time() + get_resource_token_ttl();
    $token = create_resource_token([
        'uid' => $userId,
        'key' => $resourceKey,
        'kind' => $kind,
        'exp' => $expiresAt,
    ]);

    $viewerPath = $kind === 'video' ? '/resource-video.html' : '/resource-pdf.html';

    audit('resource.issue.ok', $userId, [
        'resource_key' => $resourceKey,
        'kind' => $kind,
        'exp' => $expiresAt,
    ]);

    json_ok([
        'resource_key' => $resourceKey,
        'kind' => $kind,
        'resource_name' => $resource['name'],
        'expires_at' => gmdate('c', $expiresAt),
        'viewer_url' => $viewerPath . '?token=' . rawurlencode($token),
    ]);
}

function handle_resolve(int $userId): never
{
    if (!user_has_active_subscription($userId)) {
        audit('resource.resolve.denied.subscription', $userId, []);
        json_error(403, 'SUBSCRIPTION_REQUIRED', 'Active subscription required.');
    }

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        json_error(422, 'MISSING_TOKEN', 'token is required.');
    }

    $payload = parse_and_verify_resource_token($token);

    $tokenUserId = (int) ($payload['uid'] ?? 0);
    $resourceKey = strtoupper(trim((string) ($payload['key'] ?? '')));
    $kind = normalize_kind((string) ($payload['kind'] ?? 'pdf'));
    $exp = (int) ($payload['exp'] ?? 0);

    if ($tokenUserId !== $userId) {
        audit('resource.resolve.denied.user_mismatch', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(403, 'TOKEN_USER_MISMATCH', 'Token does not belong to current user.');
    }

    if ($exp <= time()) {
        audit('resource.resolve.denied.expired', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(410, 'TOKEN_EXPIRED', 'Resource link has expired.');
    }

    $resource = get_resource_by_key($resourceKey);
    if ($resource === null) {
        audit('resource.resolve.not_found', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_NOT_FOUND', 'Resource is not available.');
    }

    $blobName = $kind === 'video' ? ($resource['video_blob'] ?? '') : ($resource['pdf_blob'] ?? '');
    if ($blobName === '') {
        audit('resource.resolve.missing_blob', $userId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_FORMAT_NOT_FOUND', 'Requested format is not available.');
    }

    $streamUrl = '/api/resources.php?action=stream&token=' . rawurlencode($token);

    audit('resource.resolve.ok', $userId, [
        'resource_key' => $resourceKey,
        'kind' => $kind,
    ]);

    json_ok([
        'resource_key' => $resourceKey,
        'kind' => $kind,
        'resource_name' => $resource['name'],
        'expires_at' => gmdate('c', time() + get_resource_token_ttl()),
        'url' => $streamUrl,
    ]);
}

function handle_stream(): never
{
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        json_error(422, 'MISSING_TOKEN', 'token is required.');
    }

    // Token is HMAC-signed — no session cookie needed. The signed payload proves
    // the bearer was authenticated when the token was issued.
    $payload = parse_and_verify_resource_token($token);

    $tokenUserId = (int) ($payload['uid'] ?? 0);
    $resourceKey = strtoupper(trim((string) ($payload['key'] ?? '')));
    $kind = normalize_kind((string) ($payload['kind'] ?? 'pdf'));
    $exp = (int) ($payload['exp'] ?? 0);

    if ($tokenUserId <= 0 || $resourceKey === '') {
        json_error(400, 'INVALID_TOKEN_PAYLOAD', 'Token payload is invalid.');
    }

    if ($exp <= time()) {
        audit('resource.stream.denied.expired', $tokenUserId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(410, 'TOKEN_EXPIRED', 'Resource link has expired.');
    }

    $resource = get_resource_by_key($resourceKey);
    if ($resource === null) {
        audit('resource.stream.not_found', $tokenUserId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_NOT_FOUND', 'Resource is not available.');
    }

    $blobName = $kind === 'video' ? ($resource['video_blob'] ?? '') : ($resource['pdf_blob'] ?? '');
    if ($blobName === '') {
        audit('resource.stream.missing_blob', $tokenUserId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(404, 'RESOURCE_FORMAT_NOT_FOUND', 'Requested format is not available.');
    }

    $sasUrl = build_blob_sas_url($blobName, get_resource_token_ttl());

    $requestHeaders = [];
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $requestHeaders[] = 'Range: ' . (string) $_SERVER['HTTP_RANGE'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'header' => implode("\r\n", $requestHeaders),
        ],
    ]);

    $handle = @fopen($sasUrl, 'rb', false, $context);
    if ($handle === false) {
        audit('resource.stream.fetch_failed', $tokenUserId, ['resource_key' => $resourceKey, 'kind' => $kind]);
        json_error(502, 'UPSTREAM_FETCH_FAILED', 'Unable to fetch resource content.');
    }

    $meta = stream_get_meta_data($handle);
    $upstreamHeaders = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];

    $statusCode = 200;
    $forwardHeaders = [];
    foreach ($upstreamHeaders as $headerLine) {
        if (!is_string($headerLine)) {
            continue;
        }

        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
            $statusCode = (int) $matches[1];
            continue;
        }

        $parts = explode(':', $headerLine, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $forwardHeaders[$name] = $value;
    }

    http_response_code($statusCode);

    if (isset($forwardHeaders['content-type'])) {
        header('Content-Type: ' . $forwardHeaders['content-type']);
    } else {
        header('Content-Type: application/octet-stream');
    }

    foreach (['content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified', 'cache-control'] as $headerName) {
        if (isset($forwardHeaders[$headerName])) {
            header(ucwords($headerName, '-') . ': ' . $forwardHeaders[$headerName]);
        }
    }

    header('X-Content-Type-Options: nosniff');

    fpassthru($handle);
    fclose($handle);
    exit;
}

function user_has_active_subscription(int $userId): bool
{
    $stmt = db()->prepare(
        "SELECT 1
         FROM subscriptions
         WHERE user_id = ?
           AND status IN ('active', 'trialing', 'past_due')
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

function normalize_kind(string $kind): string
{
    $value = strtolower(trim($kind));
    if ($value === 'mp4' || $value === 'video') {
        return 'video';
    }
    return 'pdf';
}

function get_resource_by_key(string $resourceKey): ?array
{
    static $resourceMap = null;
    if ($resourceMap === null) {
        $resourceMap = load_resource_map_from_csv();
    }

    return $resourceMap[$resourceKey] ?? null;
}

function load_resource_map_from_csv(): array
{
    $path = __DIR__ . '/../data/Tools_for_Tough_Days_Personal_Support_Glide - Latest.csv';
    if (!is_readable($path)) {
        throw new RuntimeException('Resource CSV file is not readable: ' . $path);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open resource CSV file.');
    }

    $resourceMap = [];
    $headerFound = false;
    $headerIndexMap = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (!$headerFound) {
            if (isset($row[0]) && trim((string) $row[0]) === 'Mood_Score_Range') {
                $headerFound = true;
                foreach ($row as $idx => $name) {
                    $headerName = trim((string) $name);
                    if ($headerName !== '') {
                        $headerIndexMap[$headerName] = $idx;
                    }
                }
            }
            continue;
        }

        if (count($row) < 5) {
            continue;
        }

        $resourceCodeIndex = $headerIndexMap['Resource_Code'] ?? 3;
        $resourceNameIndex = $headerIndexMap['Resource_Name'] ?? 4;
        $fileReferenceIndex = $headerIndexMap['File_Reference'] ?? 8;
        $audioReferenceIndex = $headerIndexMap['SharePoint_Audio_Link'] ?? 9;

        $resourceCode = strtoupper(trim((string) ($row[$resourceCodeIndex] ?? '')));
        if ($resourceCode === '') {
            continue;
        }

        $resourceName = trim((string) ($row[$resourceNameIndex] ?? ''));
        $pdfBlob = trim((string) ($row[$fileReferenceIndex] ?? ''));
        $videoBlob = trim((string) ($row[$audioReferenceIndex] ?? ''));

        $resourceMap[$resourceCode] = [
            'name' => $resourceName,
            'pdf_blob' => $pdfBlob,
            'video_blob' => $videoBlob,
        ];
    }

    fclose($handle);

    return $resourceMap;
}

function create_resource_token(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode token payload.');
    }

    $secret = get_resource_token_secret();
    $sig = hash_hmac('sha256', $json, $secret, true);

    return base64url_encode($json) . '.' . base64url_encode($sig);
}

function parse_and_verify_resource_token(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        json_error(400, 'INVALID_TOKEN', 'Malformed token.');
    }

    $payloadRaw = base64url_decode($parts[0]);
    $sigRaw = base64url_decode($parts[1]);

    if ($payloadRaw === false || $sigRaw === false) {
        json_error(400, 'INVALID_TOKEN', 'Malformed token encoding.');
    }

    $expectedSig = hash_hmac('sha256', $payloadRaw, get_resource_token_secret(), true);
    if (!hash_equals($expectedSig, $sigRaw)) {
        json_error(403, 'INVALID_TOKEN_SIGNATURE', 'Token signature is invalid.');
    }

    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        json_error(400, 'INVALID_TOKEN_PAYLOAD', 'Token payload is invalid.');
    }

    return $payload;
}

function get_resource_token_secret(): string
{
    $secret = trim((string) getenv('RESOURCE_TOKEN_SECRET'));
    if ($secret !== '') {
        return $secret;
    }

    $fallback = trim((string) getenv('APP_KEY'));
    if ($fallback !== '') {
        return $fallback;
    }

    json_error(500, 'CONFIG_ERROR', 'RESOURCE_TOKEN_SECRET is not configured.');
}

function get_resource_token_ttl(): int
{
    $ttl = (int) (getenv('RESOURCE_URL_TTL_SEC') ?: 1800);
    if ($ttl < 60) {
        $ttl = 60;
    }
    if ($ttl > 7200) {
        $ttl = 7200;
    }

    return $ttl;
}

function build_blob_sas_url(string $blobName, int $ttlSec): string
{
    $account = trim((string) getenv('AZURE_STORAGE_ACCOUNT'));
    $container = trim((string) getenv('AZURE_STORAGE_CONTAINER'));
    $prefix = trim((string) getenv('AZURE_STORAGE_PREFIX'));
    $accountKey = trim((string) getenv('AZURE_STORAGE_ACCOUNT_KEY'));

    // Backward compatibility: old config used "resource", actual container is "resources".
    if (strcasecmp($container, 'resource') === 0) {
        $container = 'resources';
    }

    if ($account === '' || $container === '' || $accountKey === '') {
        json_error(500, 'CONFIG_ERROR', 'Azure storage environment variables are missing.');
    }

    $normalizedBlob = trim($blobName);
    $normalizedBlob = ltrim($normalizedBlob, '/');

    if ($prefix !== '') {
        $normalizedBlob = trim($prefix, '/') . '/' . $normalizedBlob;
    }

    $start = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
    $expiry = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSec);

    $signedPermissions = 'r';
    // Keep service version aligned with this exact string-to-sign template.
    $signedVersion = '2020-02-10';
    $signedResource = 'b';
    $signedProtocol = 'https';

    $canonicalizedResource = '/blob/' . $account . '/' . $container . '/' . $normalizedBlob;

    $stringToSign = implode("\n", [
        $signedPermissions,
        $start,
        $expiry,
        $canonicalizedResource,
        '',
        '',
        $signedProtocol,
        $signedVersion,
        $signedResource,
        '',
        '',
        '',
        '',
        '',
        '',
    ]);

    $decodedKey = base64_decode($accountKey, true);
    $hmacKey = $decodedKey === false ? $accountKey : $decodedKey;
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $hmacKey, true));

    $query = http_build_query([
        'sp' => $signedPermissions,
        'st' => $start,
        'se' => $expiry,
        'spr' => $signedProtocol,
        'sv' => $signedVersion,
        'sr' => $signedResource,
        'sig' => $signature,
    ], '', '&', PHP_QUERY_RFC3986);

    return 'https://' . $account . '.blob.core.windows.net/'
        . rawurlencode($container) . '/'
        . encode_blob_path_for_url($normalizedBlob)
        . '?' . $query;
}

function encode_blob_path_for_url(string $path): string
{
    $segments = explode('/', $path);
    $encoded = array_map(static function (string $segment): string {
        return rawurlencode($segment);
    }, $segments);

    return implode('/', $encoded);
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value)
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}
