<?php
declare(strict_types=1);

/**
 * Lightweight SMTP sender (STARTTLS + AUTH LOGIN) with simple transactional templates.
 */

function mailer_is_configured(): bool
{
    return smtp_host() !== ''
        && smtp_port() > 0
        && smtp_username() !== ''
        && smtp_password() !== ''
        && smtp_from() !== '';
}

function send_registration_welcome_email(string $toEmail, ?string $fullName = null): bool
{
    $name = trim((string) $fullName);
    $firstName = $name;
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = $parts[0] ?? $name;
    }

    $greeting = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';

    $subject = 'Great to have you with us';
    $body = $greeting . "\n\n"
        . "Thank you for joining. Welcome to the Tools for the Tough Days community.\n\n"
        . "I am Nic Marcon, a Registered Psychologist with over 20 years of clinical experience. I created this platform because I believe that good mental health support should not be hard to access.\n\n"
        . "As part of our community, you will be among the first to hear about new resources, tips and insights from my clinical practice, upcoming events, and special offers including our founding member rate. We also have a few exciting things in the pipeline so stay tuned for those announcements.\n\n"
        . "We are always looking to grow and add new topics, so if there is something you would like to see covered that you cannot find, please let us know. Your feedback genuinely helps shape what we work on next.\n\n"
        . "Looking forward to being part of your journey.\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    return send_transactional_email($toEmail, $subject, $body);
}

function send_password_reset_email(
    string $toEmail,
    ?string $fullName,
    string $resetUrl,
    int $expiresMinutes
): bool {
    $name = trim((string) $fullName);
    $firstName = $name;
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = $parts[0] ?? $name;
    }

    $greeting = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $subject = 'Reset your password';
    $body = $greeting . "\n\n"
        . "We received a request to reset your Tools for the Tough Days password.\n\n"
        . "Reset your password here:\n"
        . $resetUrl . "\n\n"
        . "This link will expire in {$expiresMinutes} minutes and can only be used once.\n\n"
        . "If you did not request this, you can ignore this email.\n\n"
        . "Warm regards,\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $htmlGreeting = $firstName !== ''
        ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hi there,';

    $htmlBody = '<p>' . $htmlGreeting . '</p>'
        . '<p>We received a request to reset your Tools for the Tough Days password.</p>'
        . '<p><a href="' . $safeUrl . '">Reset your password</a></p>'
        . '<p>This link will expire in ' . (int) $expiresMinutes . ' minutes and can only be used once.</p>'
        . '<p>If you did not request this, you can ignore this email.</p>'
        . '<p>Warm regards,<br>Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    return send_transactional_email($toEmail, $subject, $body, $htmlBody);
}

function send_subscription_email(
    string $toEmail,
    ?string $fullName,
    string $productKey,
    string $planType,
    ?string $periodEnd,
    bool $isRenewal
): bool {
    $name = trim((string) $fullName);
    $firstName = $name;
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = $parts[0] ?? $name;
    }

    $greeting = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $planLabel = subscription_label_from_product_key($productKey, $planType);
    $periodLine = '';
    $htmlBody = null;

    if ($periodEnd) {
        $ts = strtotime($periodEnd);
        if ($ts !== false) {
            $periodLine = "Current period ends on " . date('j M Y', $ts) . ".\n\n";
        }
    }

    if ($isRenewal) {
        $subject = 'Your Tools for the Tough Days subscription renewed';
        $body = $greeting . "\n\n"
            . "Your {$planLabel} subscription has renewed successfully.\n\n"
            . $periodLine
            . "Thank you for continuing with us.\n\n"
            . "Warmly,\n"
            . "Tools for the Tough Days\n"
            . "www.toolsforthetoughdays.com.au";
    } else {
        $videoUrl = 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQC5aZu51NRPRIwA-NbnwgPyAVzxFltV33r9xK1zbLZ7CE4?e=zwyljM';
        $subject = 'Welcome to Tools for the Tough Days';
        $body = $greeting . "\n\n"
            . "Thank you so much for joining Tools for the Tough Days. It genuinely means a lot that you have taken this step, and I want to make sure you feel right at home from day one.\n\n"
            . "You now have full access to the platform, including a library of resources designed to support you through the moments that feel a little harder than usual. Whether you are navigating stress, sleep, relationships, for a mate or simply trying to feel more like yourself, there is something in there for you.\n\n"
            . "Before you dive in, I have put together a short welcome video to give you a feel for what is here for you.\n\n"
            . "Welcome to Tools for the Tough Days\n"
            . "{$videoUrl}\n\n"
            . "We are always working to add new topics, so if you ever cannot find what you are looking for, please let us know. Your feedback helps shape what we work on next. Also, we have a few exciting things in the pipeline, so keep an eye out for updates.\n\n"
            . "If you have any questions or need a hand finding the right resource, just reply to this email. I am glad you are here.\n\n"
            . "Warm regards,\n"
            . "Nic Marcon\n"
            . "Registered Psychologist\n"
            . "Tools for the Tough Days\n"
            . "www.toolsforthetoughdays.com.au";

        $htmlGreeting = $firstName !== ''
            ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
            : 'Hi there,';
        $safeVideoUrl = htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlBody = '<p>' . $htmlGreeting . '</p>'
            . '<p>Thank you so much for joining Tools for the Tough Days. It genuinely means a lot that you have taken this step, and I want to make sure you feel right at home from day one.</p>'
            . '<p>You now have full access to the platform, including a library of resources designed to support you through the moments that feel a little harder than usual. Whether you are navigating stress, sleep, relationships, for a mate or simply trying to feel more like yourself, there is something in there for you.</p>'
            . '<p>Before you dive in, I have put together a short welcome video to give you a feel for what is here for you.</p>'
            . '<p><a href="' . $safeVideoUrl . '">Welcome to Tools for the Tough Days</a></p>'
            . '<p>We are always working to add new topics, so if you ever cannot find what you are looking for, please let us know. Your feedback helps shape what we work on next. Also, we have a few exciting things in the pipeline, so keep an eye out for updates.</p>'
            . '<p>If you have any questions or need a hand finding the right resource, just reply to this email. I am glad you are here.</p>'
            . '<p>Warm regards,<br>'
            . 'Nic Marcon<br>'
            . 'Registered Psychologist<br>'
            . 'Tools for the Tough Days<br>'
            . 'www.toolsforthetoughdays.com.au</p>';
    }

    return send_transactional_email($toEmail, $subject, $body, $htmlBody);
}

function should_send_renewal_emails(): bool
{
    $scope = strtolower(trim((string) getenv('SUBSCRIPTION_EMAIL_SCOPE')));
    return $scope === 'include_renewals';
}

function send_transactional_email(string $toEmail, string $subject, string $body, ?string $htmlBody = null): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (!mailer_is_configured()) {
        error_log('SMTP email skipped: configuration incomplete.');
        return false;
    }

    $recipients = [$toEmail];
    foreach (test_recipients() as $testRecipient) {
        if (!in_array($testRecipient, $recipients, true)) {
            $recipients[] = $testRecipient;
        }
    }

    smtp_send_mail($recipients, $subject, $body, $htmlBody);
    return true;
}

function smtp_send_mail(array $recipients, string $subject, string $textBody, ?string $htmlBody = null): void
{
    $host = smtp_host();
    $port = smtp_port();
    $from = smtp_from();
    $replyTo = smtp_reply_to();
    $username = smtp_username();
    $password = smtp_password();

    $cleanRecipients = [];
    foreach ($recipients as $recipient) {
        $recipient = trim((string) $recipient);
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $cleanRecipients[] = strtolower($recipient);
        }
    }

    if (empty($cleanRecipients)) {
        throw new RuntimeException('No valid recipients supplied.');
    }

    $remote = 'tcp://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException('SMTP connect failed: ' . $errstr);
    }

    try {
        stream_set_timeout($socket, 20);

        smtp_expect_code($socket, [220]);
        smtp_command($socket, 'EHLO ' . smtp_client_name(), [250]);

        if ($port === 587) {
            smtp_command($socket, 'STARTTLS', [220]);
            $cryptoOk = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($cryptoOk !== true) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }

            smtp_command($socket, 'EHLO ' . smtp_client_name(), [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);

        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        foreach ($cleanRecipients as $recipient) {
            smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        }

        smtp_command($socket, 'DATA', [354]);
        smtp_write($socket, build_rfc822_message($from, $cleanRecipients, $replyTo, $subject, $textBody, $htmlBody));
        smtp_write($socket, "\r\n.\r\n");
        smtp_expect_code($socket, [250]);

        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    smtp_write($socket, $command . "\r\n");
    return smtp_expect_code($socket, $expectedCodes);
}

function smtp_expect_code($socket, array $expectedCodes): string
{
    $response = '';
    $code = 0;

    while (($line = fgets($socket, 2048)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') {
                break;
            }
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP read timeout or empty response.');
    }

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($response));
    }

    return $response;
}

function smtp_write($socket, string $data): void
{
    $written = fwrite($socket, $data);
    if ($written === false || $written < strlen($data)) {
        throw new RuntimeException('SMTP write failed.');
    }
}

function build_rfc822_message(
    string $from,
    array $to,
    string $replyTo,
    string $subject,
    string $textBody,
    ?string $htmlBody = null
): string {
    $safeSubject = preg_replace('/[\r\n]+/', ' ', $subject) ?? 'Message';
    $safeTextBody = preg_replace('/\r\n|\r|\n/', "\r\n", $textBody) ?? '';

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . smtp_client_name() . '>',
        'From: <' . $from . '>',
        'To: ' . implode(', ', array_map(static fn($v) => '<' . $v . '>', $to)),
        'Reply-To: <' . $replyTo . '>',
        'Subject: ' . $safeSubject,
        'MIME-Version: 1.0',
    ];

    $safeHtmlBody = '';
    if ($htmlBody !== null) {
        $safeHtmlBody = preg_replace('/\r\n|\r|\n/', "\r\n", $htmlBody) ?? '';
    }

    if ($safeHtmlBody !== '') {
        $boundary = '=_Part_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $payload = '--' . $boundary . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
            . $safeTextBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
            . $safeHtmlBody . "\r\n\r\n"
            . '--' . $boundary . "--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payload = $safeTextBody;
    }

    // SMTP DATA escaping: lines beginning with a dot must be doubled.
    $payload = preg_replace('/(^|\r\n)\./', '$1..', $payload) ?? $payload;

    return implode("\r\n", $headers) . "\r\n\r\n" . $payload;
}

function subscription_label_from_product_key(string $productKey, string $planType): string
{
    $map = [
        'individual_monthly' => 'Individual Monthly',
        'individual_yearly' => 'Individual Yearly',
        'starter_only' => 'Starter Monthly',
        'growth_only' => 'Growth Monthly',
        'team_only' => 'Team Monthly',
        'starter_bundle' => 'Starter Yearly',
        'growth_bundle' => 'Growth Yearly',
        'team_bundle' => 'Team Yearly',
    ];

    if (isset($map[$productKey])) {
        return $map[$productKey];
    }

    return strtolower($planType) === 'business' ? 'Business' : 'Individual';
}

function smtp_host(): string
{
    return trim((string) getenv('SMTP_HOST'));
}

function smtp_port(): int
{
    return (int) (getenv('SMTP_PORT') ?: 587);
}

function smtp_username(): string
{
    return trim((string) getenv('SMTP_USERNAME'));
}

function smtp_password(): string
{
    return (string) (getenv('SMTP_PASSWORD') ?: '');
}

function smtp_from(): string
{
    return trim((string) (getenv('MAIL_FROM') ?: smtp_username()));
}

function smtp_reply_to(): string
{
    return trim((string) (getenv('MAIL_REPLY_TO') ?: smtp_from()));
}

function smtp_client_name(): string
{
    $host = parse_url((string) (defined('SITE_URL') ? SITE_URL : ''), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return $host;
    }

    return 'localhost';
}

function test_recipients(): array
{
    $raw = trim((string) getenv('TEST_RECIPIENTS'));
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[;,\s]+/', $raw) ?: [];
    $valid = [];
    foreach ($parts as $part) {
        $candidate = strtolower(trim((string) $part));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $valid[] = $candidate;
        }
    }

    return array_values(array_unique($valid));
}