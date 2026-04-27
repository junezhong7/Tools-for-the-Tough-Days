<?php
/**
 * Tools for the Tough Days — Shared configuration
 * Included by PHP entry points; never exposed directly to the browser.
 */

if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
}

if (!defined('INTRO_COUPON_ID')) {
    $introCouponFromEnv = getenv('INTRO_COUPON_ID');
    if ($introCouponFromEnv !== false) {
        define('INTRO_COUPON_ID', trim($introCouponFromEnv));
    } else {
        define('INTRO_COUPON_ID', 'V3tjnqrW');
    }
}

if (!defined('SITE_URL')) {
    $siteUrlFromEnv = getenv('SITE_URL') ?: '';
    if ($siteUrlFromEnv !== '') {
        define('SITE_URL', rtrim($siteUrlFromEnv, '/'));
    } else {
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $forwardedProto !== '' ? $forwardedProto : ($isHttps ? 'https' : 'http');

        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = trim(preg_replace('/[\r\n]+/', '', $host));
        if ($host === '') {
            $host = 'localhost';
        }

        define('SITE_URL', $scheme . '://' . $host);
    }
}

define('PRICE_PLAN_MAP', [
    'price_1TLD1mC8xESC1BMDifb6wvHd' => 'individual',
    'price_1TLDMFC8xESC1BMDRVGsCGIY' => 'individual',
    'price_1TLElkC8xESC1BMD1IkfyBQl' => 'business',
    'price_1TLEnfC8xESC1BMDuM43gQNr' => 'business',
    'price_1TLEpWC8xESC1BMDuTCnIF2F' => 'business',
    'price_1TLeUZC8xESC1BMDlaDCRwQv' => 'business',
    'price_1TLeVDC8xESC1BMDxQFkfvDC' => 'business',
    'price_1TLeVeC8xESC1BMD25LqYUGK' => 'business',
]);