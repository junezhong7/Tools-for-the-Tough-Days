<?php
/**
 * Tools for the Tough Days — Stripe Checkout
 * Upload this file to the root of your VentraIP hosting (same folder as index.html)
 *
 * BEFORE GOING LIVE:
 * 1. Replace YOUR_SECRET_KEY_HERE with your sk_live_ key
 * 2. Replace YOUR_COUPON_ID_HERE with your Stripe coupon ID for $5 off x 3 months
 * 3. Upload stripe-php library (instructions below)
 */

// ─────────────────────────────────────────────
// CONFIGURATION — fill these in before uploading
// ─────────────────────────────────────────────

define('STRIPE_SECRET_KEY', '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');
define('INTRO_COUPON_ID',   'V3tjnqrW'); // $5 off x 3 months → $10/mo for first 3 months
define('SITE_URL',          'https://www.toolsforthetoughdays.com.au');

// ─────────────────────────────────────────────
// PRICE IDs — mapped from your Stripe catalogue
// ─────────────────────────────────────────────

$prices = [

  // ── INDIVIDUAL ──────────────────────────────
  'individual_monthly'   => 'price_1TLD1mC8xESC1BMDifb6wvHd',  // $15/mo (coupon applied = $10 x 3 months)
  'individual_yearly'    => 'price_1TLDMFC8xESC1BMDRVGsCGIY',  // $120/yr

  // ── BUSINESS — RESOURCES ONLY ───────────────
  'starter_only'         => 'price_1TLElkC8xESC1BMD1IkfyBQl',  // $150/mo · 6–20 staff
  'growth_only'          => 'price_1TLEnfC8xESC1BMDuM43gQNr',  // $250/mo · 21–50 staff
  'team_only'            => 'price_1TLEpWC8xESC1BMDuTCnIF2F',  // $300/mo · 51–80 staff

  // ── BUSINESS — BUNDLES (annual) ─────────────
  'starter_bundle' => 'price_1TLeUZC8xESC1BMDlaDCRwQv',  // $1,350/yr · 6–20 staff
  'growth_bundle'  => 'price_1TLeVDC8xESC1BMDxQFkfvDC',  // $2,835/yr · 21–50 staff
  'team_bundle'    => 'price_1TLeVeC8xESC1BMD25LqYUGK',  // $3,780/yr · 51–80 staff

  // ── COUNSELLING BUNDLES (one-off purchases) ──
  'sessions_3'           => 'price_1TLJt2C8xESC1BMDkCPB9a8a',  // 3 sessions · $600
  'sessions_6'           => 'price_1TLbvXC8xESC1BMD1YUbOGbX',  // 6 sessions · $1,200
];

// ─────────────────────────────────────────────
// LOAD STRIPE LIBRARY
// ─────────────────────────────────────────────

// Requires stripe-php in a /stripe-php folder next to this file.
// Download from: https://github.com/stripe/stripe-php/releases
// Upload the extracted folder and rename it stripe-php
require_once __DIR__ . '/stripe-php/init.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ─────────────────────────────────────────────
// HANDLE REQUEST
// ─────────────────────────────────────────────

$product = isset($_GET['product']) ? trim($_GET['product']) : '';

if (empty($product)) {
  http_response_code(400);
  die('Missing product parameter.');
}

// ─────────────────────────────────────────────
// BUILD CHECKOUT SESSION
// ─────────────────────────────────────────────

try {

  // Shared session config
  $session_params = [
    'payment_method_types' => ['card'],
    'billing_address_collection' => 'required',
    'currency' => 'aud',
    'cancel_url' => SITE_URL . '/checkout-cancelled.html',
  ];

  // ── INDIVIDUAL MONTHLY ──────────────────────
  if ($product === 'individual_monthly') {
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices['individual_monthly'],
      'quantity' => 1,
    ]];
    $session_params['subscription_data'] = [
      'description' => 'First 3 months at $10/month, then $15/month',
    ];
    $session_params['discounts'] = [['coupon' => INTRO_COUPON_ID]];
    $session_params['success_url'] = SITE_URL . '/welcome-individual.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── INDIVIDUAL YEARLY ───────────────────────
  elseif ($product === 'individual_yearly') {
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices['individual_yearly'],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/welcome-individual.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── BUSINESS — RESOURCES ONLY ───────────────
  elseif (in_array($product, ['starter_only', 'growth_only', 'team_only'])) {
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/welcome-business.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── BUSINESS — BUNDLED (annual) ─────────────
  elseif (in_array($product, ['starter_bundle', 'growth_bundle', 'team_bundle'])) {
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $session_params['subscription_data'] = [
      'description' => 'Resource library + EAP + 4 quarterly calls with Nic — billed annually',
    ];
    $session_params['success_url'] = SITE_URL . '/welcome-business.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── COUNSELLING BUNDLES (one-off) ───────────
  elseif (in_array($product, ['sessions_3', 'sessions_6'])) {
    $session_params['mode'] = 'payment';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/welcome-business.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── UNKNOWN PRODUCT ─────────────────────────
  else {
    http_response_code(400);
    die('Unknown product.');
  }

  // Create the session and redirect
  $session = \Stripe\Checkout\Session::create($session_params);
  header('Location: ' . $session->url);
  exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
  // Log error and show friendly message
  error_log('Stripe error: ' . $e->getMessage());
  header('Location: ' . SITE_URL . '/checkout-cancelled.html?error=1');
  exit;
}
?>
