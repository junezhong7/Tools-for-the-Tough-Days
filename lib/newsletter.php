<?php
/**
 * Tools for the Tough Days — Vision6 newsletter subscription helper
 *
 * Submits a subscriber to the Vision6 list via server-side cURL.
 *
 * IMPORTANT: If the submission silently fails, confirm the VISION6_FIELD_*
 * constants below by expanding the .webform_step div in browser DevTools
 * and reading the exact name= attributes on each visible <input>.
 */

declare(strict_types=1);

// ── Vision6 form endpoint — exact action= URL from the form tag ───────────────
const VISION6_SUBSCRIBE_URL = 'https://app4.vision6.com.au/em/forms/subscribe.php'
    . '?db=993085&s=998863&a=1174284&k=1%2Cmky8Uq9M4_8whMu-oDGjW7AOjDjMCG0JKE2fD2b5DFE&wt=1';

// ── Visible field names (confirmed via browser console on 2026-06-30) ─────────
const VISION6_FIELD_EMAIL      = 'em_wfs_formfield_8225049';
const VISION6_FIELD_FIRST_NAME = 'em_wfs_formfield_8225066';
const VISION6_FIELD_LAST_NAME  = 'em_wfs_formfield_8225067';

/**
 * Subscribe a user to the Vision6 newsletter list.
 *
 * Splits full_name on the first space to derive first/last.
 * Errors are logged but never thrown — a Vision6 failure must not break
 * the user's registration or preference save.
 */
function submit_to_vision6(string $email, string $fullName = ''): void
{
    $parts     = explode(' ', trim($fullName), 2);
    $firstName = $parts[0] ?? '';
    $lastName  = $parts[1] ?? '';

    // Include the hidden fields the form requires
    $fields = [
        'webform_submit_catch'   => 'em_subscribe_form',
        'sf_catch'               => 'em_subscribe_form',
        'webform_e23eda6'        => '',   // honeypot — must be empty
        VISION6_FIELD_EMAIL      => $email,
        VISION6_FIELD_FIRST_NAME => $firstName,
        VISION6_FIELD_LAST_NAME  => $lastName,
    ];

    // Form uses enctype="multipart/form-data" — pass array, not url-encoded string
    $ch = curl_init(VISION6_SUBSCRIBE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'TTTD-Server/1.0',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode >= 400) {
        error_log(sprintf(
            'vision6_subscribe failed for %s — HTTP %d — %s',
            $email,
            $httpCode,
            $curlErr ?: substr((string) $response, 0, 200)
        ));
    }
}
