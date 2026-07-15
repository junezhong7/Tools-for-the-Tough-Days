<?php
/**
 * Tools for the Tough Days — Outbound CTA redirect + logging
 * Root-level, alongside checkout.php.
 *
 * GET /go.php?to=trial&src=hero
 *
 * Allowlist-only redirect: `to` is never used to build an arbitrary URL,
 * only to select one of the hardcoded destinations below, so this cannot
 * be used as an open redirect. Logging is best-effort and must never
 * delay or block the redirect.
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (file_exists(__DIR__ . '/lib/db.php')) {
    require_once __DIR__ . '/lib/db.php';
}
if (file_exists(__DIR__ . '/lib/auth.php')) {
    require_once __DIR__ . '/lib/auth.php';
}
if (file_exists(__DIR__ . '/lib/utm.php')) {
    require_once __DIR__ . '/lib/utm.php';
}

const CTA_DESTINATIONS = [
    'trial' => [
        'url'    => 'https://forms.cloud.microsoft/r/uTTcL5UdMW',
        'action' => 'cta.trial_click',
    ],
    'booking' => [
        'url'    => 'https://outlook.office.com/book/FreeEAPServiceIntroduction@www.emotionalbalance.com.au/s/3wsENV7TREKnsrSqdoCgKA2?ismsaljsauthenabled',
        'action' => 'cta.booking_click',
    ],
];

$to = isset($_GET['to']) ? (string) $_GET['to'] : '';

if (!isset(CTA_DESTINATIONS[$to])) {
    http_response_code(302);
    header('Location: /business.html');
    exit;
}

$dest = CTA_DESTINATIONS[$to];

try {
    $utm  = function_exists('read_utm_cookie') ? read_utm_cookie() : [];
    $user = function_exists('current_user') ? current_user() : null;

    if (function_exists('audit')) {
        $details = $utm;
        if (!empty($_GET['src'])) {
            $details['src'] = mb_substr((string) $_GET['src'], 0, 64);
        }
        audit($dest['action'], $user ? (int) $user['id'] : null, $details);
    }
} catch (Throwable $e) {
    error_log('go.php logging failed: ' . $e->getMessage());
}

header('Location: ' . $dest['url'], true, 302);
exit;
