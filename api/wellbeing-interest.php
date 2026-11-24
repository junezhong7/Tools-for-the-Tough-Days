<?php
/**
 * Tools for the Tough Days — 90-day journey interest and the one-month follow-up choice
 *
 * The journey is not available yet (Nic's launch handover, 23 Nov 2026). Interest is recorded
 * separately from purchases and from email consent, and takes no payment.
 *
 * POST /api/wellbeing-interest.php  JSON body, by "action":
 *   interest  {interest: yes|maybe|no}              -> {id, token}. Signed-in or not.
 *   followup  {id, token, choice: yes|no}           "Would you like us to email you in a month?"
 *             A yes needs a signed-in account (401 otherwise): the follow-up goes to the account
 *             email, and the consent wording, version, time, address and due date are stored.
 *             No email is scheduled or sent by this endpoint; a later job will use followup_due_on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

header('Cache-Control: no-store');

const WI_CONSENT_VERSION = 'launch-2026-11-23';
const WI_CONSENT_WORDING = 'Would you like us to email you in a month? If now isn’t the right time, we can check back with you in a month about the personalised 90-day wellbeing journey.';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
}
$body = json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true);
if (!is_array($body)) {
    json_error(400, 'INVALID_REQUEST', 'A JSON body is required.');
}

try {
    wi_ensure_table();
    $user = current_user();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'interest') {
        $interest = (string) ($body['interest'] ?? '');
        if (!in_array($interest, ['yes', 'maybe', 'no'], true)) {
            json_error(422, 'INVALID_CHOICE', 'Unknown choice.');
        }
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT INTO wellbeing_journey_interest (user_id, client_token, interest) VALUES (?, ?, ?)')
            ->execute([$user ? (int) $user['id'] : null, $token, $interest]);
        json_ok(['id' => (int) db()->lastInsertId(), 'token' => $token], 201);
    }

    if ($action === 'followup') {
        $choice = (string) ($body['choice'] ?? '');
        if (!in_array($choice, ['yes', 'no'], true)) {
            json_error(422, 'INVALID_CHOICE', 'Unknown choice.');
        }
        $stmt = db()->prepare('SELECT * FROM wellbeing_journey_interest WHERE id = ? AND client_token = ?');
        $stmt->execute([(int) ($body['id'] ?? 0), (string) ($body['token'] ?? '')]);
        $row = $stmt->fetch();
        if (!$row || $row['interest'] === 'yes') {
            json_error(404, 'NOT_FOUND', 'We couldn’t find your earlier answer. Please choose again.');
        }
        if ($choice === 'no') {
            db()->prepare(
                "UPDATE wellbeing_journey_interest
                 SET followup = 'no', consent_wording = NULL, consent_version = NULL, consent_at = NULL,
                     followup_email = NULL, followup_due_on = NULL, user_id = COALESCE(user_id, ?)
                 WHERE id = ?"
            )->execute([$user ? (int) $user['id'] : null, (int) $row['id']]);
            json_ok(['followup' => 'no']);
        }
        if (!$user) {
            json_error(401, 'SIGN_IN_REQUIRED', 'Please sign in so we know where to send the email.');
        }
        $due = (new DateTimeImmutable('now', new DateTimeZone('Australia/Brisbane')))->modify('+1 month')->format('Y-m-d');
        db()->prepare(
            "UPDATE wellbeing_journey_interest
             SET user_id = ?, followup = 'yes', consent_wording = ?, consent_version = ?, consent_at = NOW(),
                 followup_email = ?, followup_due_on = ?
             WHERE id = ?"
        )->execute([(int) $user['id'], WI_CONSENT_WORDING, WI_CONSENT_VERSION, (string) $user['email'], $due, (int) $row['id']]);
        json_ok(['followup' => 'yes', 'email' => (string) $user['email'], 'due_on' => $due]);
    }

    json_error(400, 'UNKNOWN_ACTION', 'Unknown action.');
} catch (Throwable $e) {
    error_log('wellbeing-interest: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
    json_error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.');
}

function wi_ensure_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS wellbeing_journey_interest (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id          INT UNSIGNED        NULL DEFAULT NULL,
            client_token     CHAR(32)        NOT NULL,
            interest         ENUM('yes','maybe','no') NOT NULL,
            followup         ENUM('yes','no')     NULL DEFAULT NULL,
            consent_wording  VARCHAR(500)        NULL DEFAULT NULL,
            consent_version  VARCHAR(32)         NULL DEFAULT NULL,
            consent_at       DATETIME            NULL DEFAULT NULL,
            followup_email   VARCHAR(255)        NULL DEFAULT NULL,
            followup_due_on  DATE                NULL DEFAULT NULL,
            followup_sent_at DATETIME            NULL DEFAULT NULL,
            withdrawn_at     DATETIME            NULL DEFAULT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wji_user (user_id),
            KEY idx_wji_followup_due (followup, followup_due_on, followup_sent_at),
            CONSTRAINT fk_wji_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
