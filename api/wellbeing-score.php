<?php
/**
 * Tools for the Tough Days — Wellbeing Profile free result
 *
 * POST /api/wellbeing-score.php   body: {"answers": {"1.1": ["1.1.a"], "1.2": 4, ...}}
 *
 * Scores the questionnaire with lib/wellbeing_scoring.php and returns only what the free
 * summary needs: the safety check, the primary resolution and the recent-change flag.
 * Nothing is stored and answers are never logged. Pattern percentages and the secondary
 * driver are deliberately not returned (no scores on screen; the secondary is paid content).
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/wellbeing_scoring.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function wbs_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    wbs_respond(405, ['error' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required.']);
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$body = json_decode((string) $raw, true);
$answers = is_array($body['answers'] ?? null) ? $body['answers'] : null;
if ($answers === null) {
    wbs_respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Answers are required.']);
}

try {
    $result = wp_score_questionnaire($answers);
} catch (WellbeingScoringError $e) {
    // The message names the missing question only, never an answer.
    wbs_respond(422, ['error' => 'INCOMPLETE_ANSWERS', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('wellbeing-score: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine());
    wbs_respond(500, ['error' => 'SERVER_ERROR', 'message' => 'Could not create your summary.']);
}

wbs_respond(200, [
    'score_version' => $result['score_version'],
    'safety' => [
        'safety_check_required' => $result['safety']['safety_check_required'],
        'support_concerns' => $result['safety']['support_concerns'],
    ],
    'recent_change' => $result['recent_change'],
    'primary' => [
        'kind' => $result['primary']['kind'],          // adult | single_scored_candidate | participant_choice_required | no_supported_pattern
        'candidates' => $result['primary']['candidates'],
    ],
]);
