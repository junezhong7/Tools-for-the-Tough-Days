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
// CONFIGURATION — prefer shared config / env, then fall back
// ─────────────────────────────────────────────

if (file_exists(__DIR__ . '/config.php')) {
  require_once __DIR__ . '/config.php';
}
  // Auth and DB (optional — if DB is unavailable we still allow checkout)
  if (file_exists(__DIR__ . '/lib/db.php') && file_exists(__DIR__ . '/lib/auth.php')) {
    require_once __DIR__ . '/lib/db.php';
    require_once __DIR__ . '/lib/auth.php';
  }

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

// ─────────────────────────────────────────────
// PRICE IDs — mapped from your Stripe catalogue
// ─────────────────────────────────────────────

$prices = [

  // ── INDIVIDUAL ──────────────────────────────
  'individual_monthly'   => getenv('STRIPE_PRICE_INDIVIDUAL_MONTHLY') ?: 'price_1TLD1mC8xESC1BMDifb6wvHd',  // $15/mo (coupon applied = $10 x 3 months)
  'individual_yearly'    => getenv('STRIPE_PRICE_INDIVIDUAL_YEARLY') ?: 'price_1TLDMFC8xESC1BMDRVGsCGIY',  // $120/yr

  // ── RESOURCE LIBRARY (offered on the Wellbeing Profile page) ──
  'individual_resource_monthly' => getenv('STRIPE_PRICE_RESOURCE_MONTHLY') ?: 'price_1Udf9FC8xESC1BMDo43Vx9az',  // $9.95/mo
  'individual_resource_yearly'  => getenv('STRIPE_PRICE_RESOURCE_YEARLY') ?: 'price_1UdfGLC8xESC1BMDlTmUCSs9',   // $99/yr

  // ── BUSINESS — RESOURCES ONLY ───────────────
  'starter_only'         => getenv('STRIPE_PRICE_STARTER_ONLY') ?: 'price_1TLElkC8xESC1BMD1IkfyBQl',  // $150/mo · 6–20 staff
  'growth_only'          => getenv('STRIPE_PRICE_GROWTH_ONLY') ?: 'price_1TLEnfC8xESC1BMDuM43gQNr',  // $250/mo · 21–50 staff
  'team_only'            => getenv('STRIPE_PRICE_TEAM_ONLY') ?: 'price_1TLEpWC8xESC1BMDuTCnIF2F',  // $300/mo · 51–80 staff

  // ── BUSINESS — BUNDLES (annual) ─────────────
  'starter_bundle' => getenv('STRIPE_PRICE_STARTER_BUNDLE') ?: 'price_1TLeUZC8xESC1BMDlaDCRwQv',  // $1,350/yr · 6–20 staff
  'growth_bundle'  => getenv('STRIPE_PRICE_GROWTH_BUNDLE') ?: 'price_1TLeVDC8xESC1BMDxQFkfvDC',  // $2,835/yr · 21–50 staff
  'team_bundle'    => getenv('STRIPE_PRICE_TEAM_BUNDLE') ?: 'price_1TLeVeC8xESC1BMD25LqYUGK',  // $3,780/yr · 51–80 staff

  // ── COUNSELLING BUNDLES (one-off purchases) ──
  'sessions_3'           => getenv('STRIPE_PRICE_SESSIONS_3') ?: 'price_1TLJt2C8xESC1BMDkCPB9a8a',  // 3 sessions · $600
  'sessions_6'           => getenv('STRIPE_PRICE_SESSIONS_6') ?: 'price_1TLbvXC8xESC1BMD1YUbOGbX',  // 6 sessions · $1,200
];

// ─────────────────────────────────────────────
// LOAD STRIPE LIBRARY
// ─────────────────────────────────────────────

// Requires stripe-php in a /stripe-php folder next to this file.
// Download from: https://github.com/stripe/stripe-php/releases
// Upload the extracted folder and rename it stripe-php
require_once __DIR__ . '/stripe-php/init.php';

if (STRIPE_SECRET_KEY === '') {
  error_log('Stripe checkout configuration error: STRIPE_SECRET_KEY is not set.');
  header('Location: ' . SITE_URL . '/checkout-cancelled.html?error=1');
  exit;
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// ─────────────────────────────────────────────
// RESOLVE AUTHENTICATED USER (if available)
// ─────────────────────────────────────────────
$authUser           = function_exists('current_user') ? current_user() : null;
$authUserId         = $authUser ? (int) $authUser['id'] : null;
$authCustomerId     = $authUser['stripe_customer_id'] ?? null;

// ─────────────────────────────────────────────
// HANDLE REQUEST
// ─────────────────────────────────────────────

$product = isset($_GET['product']) ? trim($_GET['product']) : '';

if (empty($product)) {
  http_response_code(400);
  die('Missing product parameter.');
}

// ── RESOURCE LIBRARY from the Wellbeing Profile page: needs an account, one subscription at a time ──
if (in_array($product, ['individual_resource_monthly', 'individual_resource_yearly'], true)) {
  if (!$authUserId) {
    header('Location: ' . SITE_URL . '/login.html?redirect=' . rawurlencode('/wellbeing-profile.html?view=resources'));
    exit;
  }
  if (function_exists('db')) {
    $active = db()->prepare(
      "SELECT 1 FROM subscriptions
       WHERE user_id = ? AND stripe_subscription_id IS NOT NULL
         AND status IN ('active', 'trialing', 'past_due')
         AND (current_period_end IS NULL OR current_period_end > NOW())
       LIMIT 1"
    );
    $active->execute([$authUserId]);
    if ($active->fetchColumn()) {
      header('Location: ' . SITE_URL . '/dashboard.html');
      exit;
    }
  }
}

// ── FULL WELLBEING PROFILE REPORT: needs an account and a saved, unpaid questionnaire run ──
// The page saves the run (api/wellbeing-report.php) and then sends the buyer here with its id.
$wellbeingRunId = null;
if ($product === 'wellbeing_report') {
  require_once __DIR__ . '/lib/wellbeing_report.php';
  if (!$authUserId) {
    header('Location: ' . SITE_URL . '/login.html?redirect=' . rawurlencode('/wellbeing-profile.html?view=report'));
    exit;
  }
  $run = wr_find_run((int) ($_GET['run'] ?? 0), $authUserId);
  if (!$run) {
    header('Location: ' . SITE_URL . '/wellbeing-profile.html?report=missing');
    exit;
  }
  if ($run['purchased_at'] !== null) {
    header('Location: ' . SITE_URL . '/wellbeing-profile.html?view=report');
    exit;
  }
  $wellbeingRunId = (int) $run['id'];
}

// ─────────────────────────────────────────────
// BUILD CHECKOUT SESSION
// ─────────────────────────────────────────────

try {
  $selected_price_id = null;

  // Shared session config
  $session_params = [
    'payment_method_types' => ['card'],
    'billing_address_collection' => 'required',
    'currency' => 'aud',
    'cancel_url' => SITE_URL . '/checkout-cancelled.html',
  ];

    // Re-use the existing Stripe customer so billing history is preserved
    if ($authCustomerId) {
      $session_params['customer'] = $authCustomerId;
    }

      if ($authUserId) {
        $session_params['client_reference_id'] = (string) $authUserId;
      }

      // Pass enough context for the webhook to recover the local user on first checkout
      $session_params['metadata'] = [
        'product_key' => $product,
        'user_id' => $authUserId ? (string) $authUserId : '',
      ];

  // ── INDIVIDUAL MONTHLY ──────────────────────
  if ($product === 'individual_monthly') {
    $selected_price_id = $prices['individual_monthly'];
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices['individual_monthly'],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/my-preference.html?new_subscriber=1&session_id={CHECKOUT_SESSION_ID}';
  }

  // ── INDIVIDUAL YEARLY ───────────────────────
  elseif ($product === 'individual_yearly') {
    $selected_price_id = $prices['individual_yearly'];
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices['individual_yearly'],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/my-preference.html?new_subscriber=1&session_id={CHECKOUT_SESSION_ID}';
  }

  // ── RESOURCE LIBRARY (Wellbeing Profile page) ──
  elseif (in_array($product, ['individual_resource_monthly', 'individual_resource_yearly'], true)) {
    $selected_price_id = $prices[$product];
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $plan = $product === 'individual_resource_monthly' ? 'monthly' : 'yearly';
    $session_params['success_url'] = SITE_URL . '/wellbeing-profile.html?subscribed=' . $plan . '&session_id={CHECKOUT_SESSION_ID}';
    $session_params['cancel_url'] = SITE_URL . '/wellbeing-profile.html?view=resources';
  }

  // ── BUSINESS — RESOURCES ONLY ───────────────
  elseif (in_array($product, ['starter_only', 'growth_only', 'team_only'])) {
    $selected_price_id = $prices[$product];
    $session_params['mode'] = 'subscription';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/welcome-business.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── BUSINESS — BUNDLED (annual) ─────────────
  elseif (in_array($product, ['starter_bundle', 'growth_bundle', 'team_bundle'])) {
    $selected_price_id = $prices[$product];
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
    $selected_price_id = $prices[$product];
    $session_params['mode'] = 'payment';
    $session_params['line_items'] = [[
      'price'    => $prices[$product],
      'quantity' => 1,
    ]];
    $session_params['success_url'] = SITE_URL . '/welcome-business.html?session_id={CHECKOUT_SESSION_ID}';
  }

  // ── FULL WELLBEING PROFILE REPORT (one-off; not platform access) ──
  elseif ($product === WR_PRODUCT_KEY) {
    $selected_price_id = wr_price_id();
    $session_params['mode'] = 'payment';
    $session_params['line_items'] = [[
      'price'    => $selected_price_id,
      'quantity' => 1,
    ]];
    $session_params['metadata']['run_id'] = (string) $wellbeingRunId;
    if (!$authCustomerId) {
      // Create a Stripe customer so later purchases reuse it, and prefill the account email.
      $session_params['customer_creation'] = 'always';
      $session_params['customer_email'] = $authUser['email'];
    }
    $session_params['payment_intent_data'] = [
      'description' => WR_PRODUCT_NAME . ' (one-time payment)',
      'metadata'    => $session_params['metadata'],
    ];
    $session_params['success_url'] = SITE_URL . '/wellbeing-profile.html?report_session={CHECKOUT_SESSION_ID}';
    $session_params['cancel_url'] = SITE_URL . '/wellbeing-profile.html?report_cancelled=' . $wellbeingRunId;
  }

  // ── UNKNOWN PRODUCT ─────────────────────────
  else {
    http_response_code(400);
    die('Unknown product.');
  }

  // Create the session and redirect
  $session = \Stripe\Checkout\Session::create($session_params);

    // ── RECORD PENDING SUBSCRIPTION IN DB ──────
    // (not for the report: it is a one-off purchase recorded on its questionnaire run)
    if ($authUserId && function_exists('db') && $product !== 'wellbeing_report') {
      $planType = in_array($product, ['individual_monthly','individual_yearly','individual_resource_monthly','individual_resource_yearly']) ? 'individual'
                : (in_array($product, ['sessions_3','sessions_6']) ? 'counselling' : 'business');
      try {
        db()->prepare(
          'INSERT INTO subscriptions
             (user_id, stripe_checkout_session_id, product_key, plan_type, status)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE updated_at = NOW()'
        )->execute([$authUserId, $session->id, $product, $planType, 'pending']);
      } catch (Throwable $dbEx) {
        error_log('checkout DB record error: ' . $dbEx->getMessage());
      }
    }

  header('Location: ' . $session->url);
  exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
  // Log error and show friendly message
  $key_mode = str_starts_with(STRIPE_SECRET_KEY, 'sk_test_') ? 'test' : (str_starts_with(STRIPE_SECRET_KEY, 'sk_live_') ? 'live' : 'unknown');
  error_log('Stripe checkout error: product=' . $product . '; price=' . ($selected_price_id ?: 'n/a') . '; key_mode=' . $key_mode . '; message=' . $e->getMessage());
  header('Location: ' . SITE_URL . '/checkout-cancelled.html?error=1');
  exit;
}
?>
