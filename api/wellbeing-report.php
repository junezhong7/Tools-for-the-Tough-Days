<?php
/**
 * Tools for the Tough Days — Wellbeing Profile full report ($39.95 one-off)
 *
 * All requests need a signed-in user (401 otherwise).
 *
 * GET  /api/wellbeing-report.php                       latest purchased report, or {"report": null}
 * GET  /api/wellbeing-report.php?session_id=cs_...     after Stripe Checkout: confirm the payment
 *                                                      (in case the webhook is slower) and return it
 * POST /api/wellbeing-report.php  JSON body, by "action":
 *   save       {answers, accepted_primary, language, safety_confirmed}
 *              -> {run_id, checkout_url}. Answers are scored again here; nothing is charged yet.
 *   secondary  {run_id, selected, response}   answer the "does this also fit?" question once
 *   language   {run_id, language}             'characters' | 'plain'
 *   email      {run_id}                       email a copy of the report again (rate limited)
 *
 * Answers are never logged. Unpaid runs are deleted after 30 days (lib/wellbeing_report.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/wellbeing_report.php';
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// The page asks on every load; a signed-out visitor simply has no report.
if ($method === 'GET' && !isset($_GET['session_id']) && current_user() === null) {
    json_ok(['report' => null, 'signed_in' => false, 'has_resource_access' => false]);
}
$user = require_auth();
$userId = (int) $user['id'];

try {
    if ($method === 'GET') {
        $sessionId = trim((string) ($_GET['session_id'] ?? ''));
        if ($sessionId !== '') {
            if (!preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $sessionId)) {
                json_error(400, 'INVALID_SESSION', 'That checkout link is not valid.');
            }
            $runId = wr_confirm_return($sessionId, $userId);
            if ($runId === null) {
                json_ok(['report' => null, 'payment' => 'not_confirmed']);
            }
            $run = wr_find_run($runId, $userId);
            json_ok(['report' => $run && $run['purchased_at'] ? wr_report_for_run($run) : null, 'payment' => 'confirmed']);
        }
        $run = wr_latest_purchased_run($userId);
        json_ok([
            'report' => $run ? wr_report_for_run($run) : null,
            'signed_in' => true,
            'has_resource_access' => wr_has_resource_access($userId),
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        json_error(405, 'METHOD_NOT_ALLOWED', 'GET or POST required.');
    }

    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($body)) {
        json_error(400, 'INVALID_REQUEST', 'A JSON body is required.');
    }
    $action = (string) ($body['action'] ?? '');

    if ($action === 'save') {
        $answers = is_array($body['answers'] ?? null) ? $body['answers'] : null;
        $primary = (string) ($body['accepted_primary'] ?? '');
        $language = (string) ($body['language'] ?? 'characters');
        if ($answers === null || $primary === '') {
            json_error(400, 'INVALID_REQUEST', 'Answers and a confirmed description are required.');
        }
        $runId = wr_save_run($userId, $answers, $primary, $language, ($body['safety_confirmed'] ?? false) === true);
        json_ok([
            'run_id' => $runId,
            'checkout_url' => '/checkout.php?product=' . WR_PRODUCT_KEY . '&run=' . $runId,
        ], 201);
    }

    if ($action === 'email') {
        $run = wr_find_run((int) ($body['run_id'] ?? 0), $userId);
        if (!$run || $run['purchased_at'] === null) {
            json_error(404, 'NOT_FOUND', 'Report not found.');
        }
        if ($run['report_emailed_at'] !== null && strtotime((string) $run['report_emailed_at']) > time() - WR_EMAIL_RESEND_SECONDS) {
            json_error(429, 'TOO_SOON', 'We sent your report a moment ago. Please check your inbox, including junk or spam.');
        }
        if (!wr_email_report($run)) {
            json_error(502, 'EMAIL_FAILED', 'We couldn’t send the email just now. Your report is still saved here; please try again later.');
        }
        json_ok(['sent' => true, 'email' => (string) $user['email']]);
    }

    if ($action === 'secondary' || $action === 'language') {
        $run = wr_find_run((int) ($body['run_id'] ?? 0), $userId);
        if (!$run || $run['purchased_at'] === null) {
            json_error(404, 'NOT_FOUND', 'Report not found.');
        }
        if ($action === 'secondary') {
            $selected = isset($body['selected']) && is_string($body['selected']) ? $body['selected'] : null;
            wr_save_secondary_answer($run, $selected, (string) ($body['response'] ?? ''));
        } else {
            wr_save_language($run, (string) ($body['language'] ?? ''));
        }
        json_ok(['report' => wr_report_for_run(wr_find_run((int) $run['id'], $userId))]);
    }

    json_error(400, 'UNKNOWN_ACTION', 'Unknown action.');
} catch (WellbeingScoringError $e) {
    // Messages name a question or rule, never an answer.
    json_error(422, 'INVALID_ANSWERS', $e->getMessage());
} catch (Throwable $e) {
    error_log('wellbeing-report: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
    json_error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.');
}

/** Look the Checkout Session up at Stripe and record the purchase if it is paid. */
function wr_confirm_return(string $sessionId, int $userId): ?int
{
    if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
        return null;
    }
    require_once __DIR__ . '/../stripe-php/init.php';
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    try {
        $session = \Stripe\Checkout\Session::retrieve($sessionId);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('wellbeing-report: could not retrieve checkout session: ' . $e->getMessage());
        return null;
    }
    return wr_confirm_checkout_session($session, $userId);
}
