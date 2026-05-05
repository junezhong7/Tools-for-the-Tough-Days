<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

/**
 * Subscription-gated redirect for source links.
 *
 * Usage:
 *   /source-access.php?target=https%3A%2F%2Femotionalbalance.sharepoint.com%2F...
 */

function current_request_path_with_query(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/source-access.php';
    return is_string($uri) && $uri !== '' ? $uri : '/source-access.php';
}

function redirect_to(string $location): never
{
    header('Location: ' . $location, true, 302);
    exit;
}

function redirect_to_login(): never
{
    $redirect = urlencode(current_request_path_with_query());
    redirect_to('/login.html?redirect=' . $redirect);
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

function is_allowed_target_host(string $host): bool
{
    $allowedHosts = [
        'emotionalbalance.sharepoint.com',
        'app4.vision6.com.au',
        'steapresources.blob.core.windows.net',
    ];

    foreach ($allowedHosts as $allowed) {
        if ($host === $allowed) {
            return true;
        }
    }

    return false;
}

$target = trim((string) ($_GET['target'] ?? ''));
if ($target === '') {
    redirect_to('/support.html?source_access=missing_target');
}

$parts = parse_url($target);
if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
    redirect_to('/support.html?source_access=invalid_target');
}

$scheme = strtolower((string) $parts['scheme']);
$host = strtolower((string) $parts['host']);

if (($scheme !== 'https' && $scheme !== 'http') || !is_allowed_target_host($host)) {
    redirect_to('/support.html?source_access=blocked_target');
}

$user = current_user();
if ($user === null) {
    redirect_to_login();
}

$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0 || !user_has_active_subscription($userId)) {
    redirect_to('/support.html?source_access=subscription_required');
}

redirect_to($target);
