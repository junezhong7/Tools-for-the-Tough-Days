<?php
/**
 * Tools for the Tough Days — First-party UTM cookie reader
 * Shared by api/auth.php (signup persistence) and go.php (CTA click logging).
 */

declare(strict_types=1);

const UTM_COOKIE_NAME = 'tttd_utm';

/**
 * Reads and validates the first-touch UTM cookie written by
 * scripts/utm-tracker.js. Never throws; returns [] if missing or malformed
 * so callers can proceed unaffected.
 */
function read_utm_cookie(): array
{
    $raw = $_COOKIE[UTM_COOKIE_NAME] ?? '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $caps = [
        'utm_source'   => 64,
        'utm_medium'   => 64,
        'utm_campaign' => 128,
        'utm_term'     => 255,
        'utm_content'  => 255,
        'gclid'        => 128,
        'fbclid'       => 128,
        'landing_page' => 512,
        'referrer'     => 512,
    ];

    $out = [];
    foreach ($caps as $field => $maxLen) {
        $v = $decoded[$field] ?? null;
        if (is_string($v) && trim($v) !== '') {
            $out[$field] = mb_substr(trim($v), 0, $maxLen);
        }
    }

    return $out;
}
