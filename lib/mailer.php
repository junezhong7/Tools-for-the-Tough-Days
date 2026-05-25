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
    $greeting = $name !== '' ? "Hi {$name}," : 'Hi there,';

    $subject = 'Welcome to Tools for the Tough Days';
    $body = $greeting . "\n\n"
        . "Thanks for creating your account. Your support space is now ready.\n\n"
        . "You can sign in anytime to explore resources tailored to how you are feeling.\n\n"
        . "If you have any questions, just reply to this email.\n\n"
        . "Warmly,\n"
        . "Tools for the Tough Days";

    return send_transactional_email($toEmail, $subject, $body);
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
    $greeting = $name !== '' ? "Hi {$name}," : 'Hi there,';
    $planLabel = subscription_label_from_product_key($productKey, $planType);
    $periodLine = '';

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
            . "Tools for the Tough Days";
    } else {
        $subject = 'Subscription confirmed - Tools for the Tough Days';
        $body = $greeting . "\n\n"
            . "Thanks for subscribing. Your {$planLabel} plan is now active.\n\n"
            . $periodLine
            . "You can now access your subscriber resources.\n\n"
            . "Warmly,\n"
            . "Tools for the Tough Days";
    }

    return send_transactional_email($toEmail, $subject, $body);
}

function should_send_renewal_emails(): bool
{
    $scope = strtolower(trim((string) getenv('SUBSCRIPTION_EMAIL_SCOPE')));
    return $scope === 'include_renewals';
}

function send_transactional_email(string $toEmail, string $subject, string $body): bool
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

    smtp_send_mail($recipients, $subject, $body);
    return true;
}

function smtp_send_mail(array $recipients, string $subject, string $textBody): void
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
        smtp_write($socket, build_rfc822_message($from, $cleanRecipients, $replyTo, $subject, $textBody));
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
    string $textBody
): string {
    $safeSubject = preg_replace('/[\r\n]+/', ' ', $subject) ?? 'Message';
    $safeBody = preg_replace('/\r\n|\r|\n/', "\r\n", $textBody) ?? '';

    // SMTP DATA escaping: lines beginning with a dot must be doubled.
    $safeBody = preg_replace('/(^|\r\n)\./', '$1..', $safeBody) ?? $safeBody;

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . smtp_client_name() . '>',
        'From: <' . $from . '>',
        'To: ' . implode(', ', array_map(static fn($v) => '<' . $v . '>', $to)),
        'Reply-To: <' . $replyTo . '>',
        'Subject: ' . $safeSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . $safeBody;
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