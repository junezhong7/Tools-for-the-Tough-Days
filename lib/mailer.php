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

function send_trial_day10_email(string $toEmail, ?string $fullName = null): bool
{
    $firstName  = extract_first_name($fullName);
    $greeting   = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $siteUrl    = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $platformUrl    = $siteUrl . '/support.html';
    $membershipUrl  = (string) (getenv('FOUNDATION_MEMBERSHIP_URL') ?: $platformUrl);

    $subject = 'Your trial ends soon — a quick note';

    $textBody = $greeting . "\n\n"
        . "Your free trial ends in four days, so we wanted to check in.\n\n"
        . "If you've been using the daily tool, you'll know what it offers: a short reset, a quick mood check, and a few small actions to carry into tomorrow.\n\n"
        . "If you haven't had a chance yet, there's still time. Log on today — it takes about three minutes.\n\n"
        . $platformUrl . "\n\n"
        . "Becoming a Foundation Member\n\n"
        . "When your trial ends, we'll invite you to continue as a Foundation Member — with a founding rate, early access to what we build next, and the chance to help shape The Tough Days Project from the beginning.\n\n"
        . "If the tool has helped, even a little, we'd love you to stay connected.\n\n"
        . "See Foundation Membership details: " . $membershipUrl . "\n\n"
        . "Either way, thank you for being part of the start of this.\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $htmlGreeting      = $firstName !== ''
        ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hi there,';
    $safePlatformUrl   = htmlspecialchars($platformUrl,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMembershipUrl = htmlspecialchars($membershipUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $htmlBody = '<p>' . $htmlGreeting . '</p>'
        . '<p>Your free trial ends in four days, so we wanted to check in.</p>'
        . '<p>If you\'ve been using the daily tool, you\'ll know what it offers: a short reset, a quick mood check, and a few small actions to carry into tomorrow.</p>'
        . '<p>If you haven\'t had a chance yet, there\'s still time. <a href="' . $safePlatformUrl . '">Log on today</a> — it takes about three minutes.</p>'
        . '<h3 style="font-size:16px;font-family:inherit;margin:24px 0 8px;">Becoming a Foundation Member</h3>'
        . '<p>When your trial ends, we\'ll invite you to continue as a Foundation Member — with a founding rate, early access to what we build next, and the chance to help shape The Tough Days Project from the beginning.</p>'
        . '<p>If the tool has helped, even a little, we\'d love you to stay connected.</p>'
        . '<p><a href="' . $safeMembershipUrl . '">See Foundation Membership details &rarr;</a></p>'
        . '<p>Either way, thank you for being part of the start of this.</p>'
        . '<p>Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>'
        . 'Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

function send_trial_day14_email(string $toEmail, ?string $fullName = null): bool
{
    $firstName     = extract_first_name($fullName);
    $greeting      = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $siteUrl       = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $membershipUrl = (string) (getenv('FOUNDATION_MEMBERSHIP_URL') ?: $siteUrl . '/support.html');

    $subject = 'Your trial has ended — here\'s how to continue';

    $textBody = $greeting . "\n\n"
        . "Your 14-day trial has come to an end.\n\n"
        . "If you've used the daily tool over the past two weeks, you'll have a sense of what it offers day to day: a short reset, a quick mood check, and small actions you can carry forward. If it's helped, even in a small way, the next step is simple.\n\n"
        . "Becoming a Foundation Member\n\n"
        . "You can continue now as a Foundation Member, locking in the founding rate before it closes. Foundation Members get early access to what we build next, and a genuine say in shaping The Tough Days Project from the beginning.\n\n"
        . "Continue as a Foundation Member: " . $membershipUrl . "\n\n"
        . "If now isn't the right time, that's okay too. You can come back to this whenever you're ready.\n\n"
        . "Because you're worth showing up for.\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $htmlGreeting      = $firstName !== ''
        ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hi there,';
    $safeMembershipUrl = htmlspecialchars($membershipUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $htmlBody = '<p>' . $htmlGreeting . '</p>'
        . '<p>Your 14-day trial has come to an end.</p>'
        . '<p>If you\'ve used the daily tool over the past two weeks, you\'ll have a sense of what it offers day to day: a short reset, a quick mood check, and small actions you can carry forward. If it\'s helped, even in a small way, the next step is simple.</p>'
        . '<h3 style="font-size:16px;font-family:inherit;margin:24px 0 8px;">Becoming a Foundation Member</h3>'
        . '<p>You can continue now as a Foundation Member, locking in the founding rate before it closes. Foundation Members get early access to what we build next, and a genuine say in shaping The Tough Days Project from the beginning.</p>'
        . '<p><a href="' . $safeMembershipUrl . '" style="display:inline-block;background:#26777B;color:#ffffff;padding:13px 24px;border-radius:8px;text-decoration:none;font-weight:600;">Continue as a Foundation Member</a></p>'
        . '<p>If now isn\'t the right time, that\'s okay too. You can come back to this whenever you\'re ready.</p>'
        . '<p><em>Because you\'re worth showing up for.</em></p>'
        . '<p>Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>'
        . 'Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

function send_registration_welcome_email(string $toEmail, ?string $fullName = null): bool
{
    $firstName = extract_first_name($fullName);
    $greeting  = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';

    $guidePath = __DIR__ . '/../data/Welcome Guide.pdf';

    $videos = [
        'Financial Stress'                => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQBDG-Vb9tJCQqMWuw78PFnZAfZWh59MiJloYc0BhyRBEbY?e=e8MXwG',
        'Raising Children'                => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQCCg-K4M2MDRbH1dtpoIwZRAUf5eePU4GX8VvscXM5_-08?e=TPXN6n',
        'Relationships'                   => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQBPROfZXFQAQK4hIspcb6YWAdlWEcwGEdgRDkx6PADHyvs?e=OPeCNa',
        'Sleep, Diet & Movement'          => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQAiKA8KfPUWS7BCGMTGaSYxAT1B1Fgf7wKmviMY26Zueqs?e=4e8Fbq',
        'We all struggle with Addictions' => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQAHlL0sWK6oToowX5T1igziAUIgmY8--wb8yoF2etr4wQY?e=NunbVY',
        'What is Anxiety'                 => 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQAjqC5nkyefQYZxLDkcfogYAcZpu3B-9xNfhW7tda3pAjA?e=Hjg88x',
    ];

    $subject = 'Great to have you with us';

    $textBody = $greeting . "\n\n"
        . "Thank you for joining Tools for the Tough Days. Taking that first step is often the hardest part, so well done for making it happen.\n\n"
        . "We have put together a Welcome Guide for you to read in your own time (attached to this email). It walks through some sample resources you will find on the platform and how to make the most of your first 14 days.\n\n"
        . "We have also included six short videos below. Our Founder and Principal Psychologist, Nic Marcon, recorded these himself if you would rather watch and listen than read.\n\n";
    foreach ($videos as $title => $url) {
        $textBody .= "{$title}: {$url}\n";
    }
    $textBody .= "\n"
        . "Have a look through whichever format suits you best, and we hope you find them helpful.\n\n"
        . "Warmly,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $videoListHtml = '<ul style="padding-left:20px;margin:0 0 16px;">';
    foreach ($videos as $title => $url) {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $videoListHtml .= '<li><a href="' . $safeUrl . '">' . $safeTitle . '</a></li>';
    }
    $videoListHtml .= '</ul>';

    $htmlBody = '<p>' . $safeGreeting . '</p>'
        . '<p>Thank you for joining Tools for the Tough Days. Taking that first step is often the hardest part, so well done for making it happen.</p>'
        . '<p>We have put together a Welcome Guide for you to read in your own time (attached to this email). It walks through some sample resources you will find on the platform and how to make the most of your first 14 days.</p>'
        . '<p>We have also included six short videos below. Our Founder and Principal Psychologist, Nic Marcon, recorded these himself if you would rather watch and listen than read.</p>'
        . $videoListHtml
        . '<p>Have a look through whichever format suits you best, and we hope you find them helpful.</p>'
        . '<p>Warmly,<br>Nic Marcon<br>Registered Psychologist<br>'
        . 'Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    $attachments = [];
    if (is_readable($guidePath)) {
        $attachments[] = [
            'path'     => $guidePath,
            'filename' => 'Welcome Guide.pdf',
            'mime'     => 'application/pdf',
        ];
    } else {
        error_log('send_registration_welcome_email: Welcome Guide PDF missing at ' . $guidePath);
    }

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody, $attachments);
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

function send_transactional_email(
    string $toEmail,
    string $subject,
    string $body,
    ?string $htmlBody = null,
    array $attachments = []
): bool {
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

    smtp_send_mail($recipients, $subject, $body, $htmlBody, $attachments);
    return true;
}

/**
 * Sends the "8 Tools for Your Tough Days" free guide as a PDF attachment.
 */
function send_free_guide_email(string $toEmail): bool
{
    $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $pdfPath = __DIR__ . '/../data/8-Tools-for-Your-Tough-Days-Guide.pdf';

    if (!is_readable($pdfPath)) {
        error_log('send_free_guide_email: PDF attachment missing at ' . $pdfPath);
        return false;
    }

    $name = ucfirst(explode('@', $toEmail)[0]);
    $registerUrl = 'https://www.toolsforthetoughdays.com.au/register.html';

    $subject = 'Your free guide: 8 Tools for Your Tough Days';

    $textBody = "Hi {$name},\n\n"
        . "Thanks for downloading the free guide. I hope you got some valuable tips, and even one small thing you could try this week.\n\n"
        . "If you want to keep going, Tools for the Tough Days gives you a simple way to check in on how you're really tracking. "
        . "A daily mood slider feeds straight into your dashboard, giving you a clear visual picture of how you're going, "
        . "alongside 370+ resources you can read or listen to at your own pace. You're also fully in control of when and how often "
        . "you hear from us, since we believe regular notifications can help you stay on track and focused.\n\n"
        . "You can try it free for fourteen days (no card required) and see if it fits into your week.\n"
        . $registerUrl . "\n\n"
        . "One small step at a time.\n\n"
        . "Nic Marcon, Registered Psychologist, Founder of Tools for the Tough Days\n\n"
        . "Unsubscribe from our newsletter: " . $siteUrl . "/unsubscribe.html?email=" . rawurlencode($toEmail);

    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeRegisterUrl = htmlspecialchars($registerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUnsubUrl = htmlspecialchars($siteUrl . '/unsubscribe.html?email=' . rawurlencode($toEmail), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlBody = "<p>Hi {$safeName},</p>"
        . '<p>Thanks for downloading the free guide. I hope you got some valuable tips, and even one small thing you could try this week.</p>'
        . '<p>If you want to keep going, Tools for the Tough Days gives you a simple way to check in on how you\'re really tracking. '
        . 'A daily mood slider feeds straight into your dashboard, giving you a clear visual picture of how you\'re going, '
        . 'alongside 370+ resources you can read or listen to at your own pace. You\'re also fully in control of when and how often '
        . 'you hear from us, since we believe regular notifications can help you stay on track and focused.</p>'
        . '<p>You can try it free for fourteen days (no card required) and see if it fits into your week.<br>'
        . '<a href="' . $safeRegisterUrl . '">' . $safeRegisterUrl . '</a></p>'
        . '<p>One small step at a time.</p>'
        . '<p>Nic Marcon, Registered Psychologist, Founder of Tools for the Tough Days</p>'
        . '<p style="font-size:12px;color:#999;"><a href="' . $safeUnsubUrl . '">Unsubscribe from our newsletter</a>.</p>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody, [
        [
            'path'     => $pdfPath,
            'filename' => '8-Tools-for-Your-Tough-Days.pdf',
            'mime'     => 'application/pdf',
        ],
    ]);
}

/**
 * Sends the first lead-magnet follow-up email (a walkthrough of the mood
 * slider) five days after someone downloads the free guide. Only meant to be
 * sent to addresses that opted into the newsletter at signup — see
 * cron/send-leadmagnet-followup.php, which filters on
 * lead_magnet_signups.newsletter_opt_in before calling this.
 */
function send_lead_magnet_followup1_email(string $toEmail): bool
{
    $siteUrl     = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $registerUrl = $siteUrl . '/register.html';
    $unsubUrl    = $siteUrl . '/unsubscribe.html?email=' . rawurlencode($toEmail);
    $videoUrl    = 'https://emotionalbalance.sharepoint.com/:v:/s/ResourceCenter/IQDaHJ8dezQFRJxjfP7nFwctAbcIpmYd6boud7yysHxwe0w?e=h3MHZm';
    $img2Url     = $siteUrl . '/assets/email/mood-slider-score-2.png';
    $img5Url     = $siteUrl . '/assets/email/mood-slider-score-5.png';
    $img8Url     = $siteUrl . '/assets/email/mood-slider-score-8.png';

    $subject = 'Feature Spotlight: Getting to know your mood slider';

    $textBody = "Hi there,\n\n"
        . "Every check-in starts with one question: how are you feeling right now? "
        . "That's the mood slider, a few seconds that shapes what you see next, and builds the picture on your dashboard over time.\n\n"
        . "Watch the 90-second walkthrough: {$videoUrl}\n\n"
        . "When things are really tough (score 2): you're taken straight to important information. No browsing required.\n\n"
        . "When you're getting by (score 5): you're invited to check in and see what's available, at your own pace.\n\n"
        . "When things are on track (score 8): worth logging anyway. It helps you notice what's working.\n\n"
        . "It doesn't need to be perfect. Even a rough guess, checked in regularly, tells you more than most people ever track.\n\n"
        . "Haven't tried it yet? Free for 14 days, no card required.\n"
        . $registerUrl . "\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au\n\n"
        . "Unsubscribe from our newsletter: " . $unsubUrl;

    $safeUnsubUrl    = htmlspecialchars($unsubUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeRegisterUrl = htmlspecialchars($registerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeVideoUrl    = htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeImg2Url     = htmlspecialchars($img2Url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeImg5Url     = htmlspecialchars($img5Url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeImg8Url     = htmlspecialchars($img8Url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $htmlBody = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:680px; max-width:680px; background-color:#ffffff;">'
        . '<tr><td align="center" style="background-color:#1a4e52; padding:32px 24px 28px;">'
        . '<div style="font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:2px; color:#a8d7c6; text-transform:uppercase; margin-bottom:6px;">Feature Spotlight</div>'
        . '<div style="font-family:Georgia, \'Times New Roman\', serif; font-size:28px; line-height:1.25; color:#ffffff;">'
        . '<span style="font-weight:normal;">Tools for the</span><br><span style="font-weight:bold; color:#ffffff;">Tough Days</span></div>'
        . '<div style="font-family:Arial, Helvetica, sans-serif; font-size:13px; color:#cfe3e0; margin-top:10px; font-style:italic;">Here for the tough days, and the good ones too.</div>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 32px 0;">'
        . '<h1 style="margin:0 0 6px; font-family:Georgia, \'Times New Roman\', serif; font-size:24px; color:#1a4e52;">Getting to know your mood slider</h1>'
        . '<p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#26777B; font-weight:bold;">The thirty seconds that starts every check-in</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.65; color:#333333;">'
        . '<p style="margin:0 0 12px;">Hi there,</p>'
        . '<p style="margin:0 0 12px;">Every check-in starts with one question: <em>how are you feeling right now?</em> '
        . 'That\'s the mood slider, a few seconds that shapes what you see next, and builds the picture on your dashboard over time.</p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:8px 32px 4px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#26777B" style="border-radius:6px;">'
        . '<a href="' . $safeVideoUrl . '" target="_blank" style="display:inline-block; padding:14px 28px; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">&#9654;&nbsp; Watch the 90-second walkthrough</a>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 32px 0; border-top:1px solid #e2e2e2;">'
        . '<p style="margin:16px 0 8px; font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#26777B; font-weight:bold; text-align:left;">When things are really tough</p>'
        . '<img src="' . $safeImg2Url . '" alt="Mood slider set to 2, Really struggling" width="400" style="width:400px; max-width:100%; height:auto; display:block; margin:0 auto; border:1px solid #e2e2e2; border-radius:8px;">'
        . '<p style="margin:10px 0 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.5; color:#555555; text-align:left;">You\'re taken straight to important information. No browsing required.</p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 32px 0;">'
        . '<p style="margin:0 0 8px; font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#26777B; font-weight:bold; text-align:left;">When you\'re getting by</p>'
        . '<img src="' . $safeImg5Url . '" alt="Mood slider set to 5, Getting by" width="400" style="width:400px; max-width:100%; height:auto; display:block; margin:0 auto; border:1px solid #e2e2e2; border-radius:8px;">'
        . '<p style="margin:10px 0 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.5; color:#555555; text-align:left;">You\'re invited to check in and see what\'s available, at your own pace.</p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:18px 32px 0;">'
        . '<p style="margin:0 0 8px; font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#26777B; font-weight:bold; text-align:left;">When things are on track</p>'
        . '<img src="' . $safeImg8Url . '" alt="Mood slider set to 8, Pretty good" width="400" style="width:400px; max-width:100%; height:auto; display:block; margin:0 auto; border:1px solid #e2e2e2; border-radius:8px;">'
        . '<p style="margin:10px 0 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.5; color:#555555; text-align:left;">Worth logging anyway. It helps you notice what\'s working.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#333333;">'
        . '<p style="margin:0 0 12px;">It doesn\'t need to be perfect. Even a rough guess, checked in regularly, tells you more than most people ever track.</p>'
        . '<p style="margin:0;">Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>Tools for the Tough Days</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 28px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff6e0; border-radius:8px;"><tr>'
        . '<td style="padding:18px 24px; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.5; color:#5a4a1f;">'
        . '<p style="margin:0 0 10px;"><strong>Haven\'t tried it yet?</strong> Free for 14 days, no card required.</p>'
        . '<a href="' . $safeRegisterUrl . '" style="color:#26777B; font-weight:bold; text-decoration:none;">www.toolsforthetoughdays.com.au &rarr;</a>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 32px; border-top:1px solid #e2e2e2; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6; color:#999999;">'
        . '<p style="margin:0;">Tools for the Tough Days &middot; <a href="https://www.toolsforthetoughdays.com.au" style="color:#999999;">www.toolsforthetoughdays.com.au</a></p>'
        . '<p style="margin:6px 0 0;"><a href="' . $safeUnsubUrl . '" style="color:#999999;">Unsubscribe from our newsletter</a>.</p>'
        . '</td></tr>'
        . '</table></td></tr></table>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

/**
 * Sends the second lead-magnet follow-up email (the "gym analogy" consistency
 * nudge) ten days after someone downloads the free guide. Only meant to be
 * sent to addresses that opted into the newsletter at signup AND have not
 * since registered on the platform — see cron/send-leadmagnet-followup.php,
 * which filters on lead_magnet_signups.newsletter_opt_in and users.email
 * before calling this.
 */
function send_lead_magnet_followup2_email(string $toEmail): bool
{
    $siteUrl     = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $registerUrl = $siteUrl . '/register.html';
    $unsubUrl    = $siteUrl . '/unsubscribe.html?email=' . rawurlencode($toEmail);

    $subject = 'Nobody goes to the gym once and expects to look like Arnold';

    $textBody = "Hi there,\n\n"
        . "A few days ago I showed you your mood slider. I was wondering if you got a chance to try it for yourself? "
        . "What matters more than any single check-in is doing it regularly and consistently. Research shows that's what has the most benefit to improving our wellbeing.\n\n"
        . "Nobody goes to the gym once and calls it fitness. We understand that about our bodies without needing to be convinced. "
        . "We turn up a few times a week, mostly when we don't feel like it, and the value sits in the turning up rather than in any one session.\n\n"
        . "The same logic applies to checking in with yourself. Three times a week is the number I suggest as a minimum, often enough that your own patterns start to show.\n\n"
        . "The patterns are the point. One low day tells you very little. Three low Mondays in a row tells you something you can do something about.\n\n"
        . "That's the idea behind the platform. You set your own check-in time, you choose how you want to be supported, "
        . "and what you get on the day is matched to where you actually are, rather than to where an average person might be.\n\n"
        . "If you're curious, it's free for 14 days, no credit card required.\n"
        . $registerUrl . "\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au\n\n"
        . "Every resource on this platform is clinically authored and reviewed by Nic Marcon, Registered Psychologist, before publication.\n\n"
        . "Marcon Emotional Balance Centres Pty Ltd, Brisbane, Queensland. You are receiving this because you asked for the newsletter.\n"
        . "Unsubscribe: " . $unsubUrl;

    $safeUnsubUrl    = htmlspecialchars($unsubUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeRegisterUrl = htmlspecialchars($registerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $htmlBody = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#efeeea;"><tr><td align="center" style="padding:32px 12px;">'
        . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:680px; max-width:680px; background-color:#ffffff;">'
        . '<tr><td style="padding:44px 44px 0;">'
        . '<div style="font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:1.5px; color:#26777B; font-weight:bold; text-transform:uppercase;">Tools for the Tough Days</div>'
        . '<div style="height:4px; width:44px; background-color:#a4c639; margin-top:10px;"></div>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 44px 0;"><div style="border-top:1px solid #e2e0d8; font-size:1px; line-height:1px;">&nbsp;</div></td></tr>'
        . '<tr><td style="padding:28px 44px 0;">'
        . '<h1 style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:30px; line-height:1.25; color:#22313a; font-weight:bold;">Nobody goes to the gym once and expects to look like Arnold</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 44px 0; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.7; color:#3a3a3a;">'
        . '<p style="margin:0 0 20px;">Hi there,</p>'
        . '<p style="margin:0 0 20px;">A few days ago I showed you your mood slider. I was wondering if you got a chance to try it for yourself? '
        . 'What matters more than any single check-in is doing it regularly and consistently. Research shows that\'s what has the most benefit to improving our wellbeing.</p>'
        . '<p style="margin:0 0 20px;">Nobody goes to the gym once and calls it fitness. We understand that about our bodies without needing to be convinced. '
        . 'We turn up a few times a week, mostly when we don\'t feel like it, and the value sits in the turning up rather than in any one session.</p>'
        . '<p style="margin:0 0 20px;">The same logic applies to checking in with yourself. Three times a week is the number I suggest as a minimum, often enough that your own patterns start to show.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:4px 44px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e8f4f4;"><tr>'
        . '<td style="padding:24px 28px;">'
        . '<p style="margin:0 0 8px; font-family:Arial, Helvetica, sans-serif; font-size:12px; letter-spacing:1.5px; color:#26777B; font-weight:bold; text-transform:uppercase;">The pattern</p>'
        . '<p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.6; color:#22313a;">One low day tells you very little. Three low Mondays in a row tells you something you can do something about.</p>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 44px 0; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.7; color:#3a3a3a;">'
        . '<p style="margin:0 0 20px;">That\'s the idea behind the platform. You set your own check-in time, you choose how you want to be supported, '
        . 'and what you get on the day is matched to where you actually are, rather than to where an average person might be.</p>'
        . '<p style="margin:0;">If you\'re curious, it\'s free for 14 days, no credit card required.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 44px 0;">'
        . '<a href="' . $safeRegisterUrl . '" style="font-family:Arial, Helvetica, sans-serif; font-size:16px; font-weight:bold; color:#26777B; text-decoration:underline;">Try the platform free for 14 days</a>'
        . '</td></tr>'
        . '<tr><td style="padding:32px 44px 0; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.7; color:#3a3a3a;">'
        . '<p style="margin:0;">Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>'
        . '</td></tr>'
        . '<tr><td style="padding:32px 44px 0;"><div style="border-top:1px solid #e2e0d8; font-size:1px; line-height:1px;">&nbsp;</div></td></tr>'
        . '<tr><td style="padding:20px 44px 44px; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.6; color:#8a8a8a;">'
        . '<p style="margin:0 0 10px;">Every resource on this platform is clinically authored and reviewed by Nic Marcon, Registered Psychologist, before publication.</p>'
        . '<p style="margin:0;">Marcon Emotional Balance Centres Pty Ltd, Brisbane, Queensland. You are receiving this because you asked for the newsletter. '
        . '<a href="' . $safeUnsubUrl . '" style="color:#8a8a8a; text-decoration:underline;">Unsubscribe</a></p>'
        . '</td></tr>'
        . '</table></td></tr></table>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

/**
 * Confirms to a subscriber that they've been unsubscribed from the newsletter.
 */
function send_newsletter_unsubscribe_email(string $toEmail): bool
{
    $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://www.toolsforthetoughdays.com.au';
    $resubscribeUrl = $siteUrl . '/support.html';

    $subject = "You've been unsubscribed";

    $textBody = "Hi there,\n\n"
        . "This confirms you've been unsubscribed from the Tools for the Tough Days newsletter. You won't receive any more newsletter emails from us.\n\n"
        . "If this was a mistake, you can resubscribe any time here:\n"
        . $resubscribeUrl . "\n\n"
        . "Warm regards,\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $safeResubscribeUrl = htmlspecialchars($resubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlBody = '<p>Hi there,</p>'
        . '<p>This confirms you\'ve been unsubscribed from the Tools for the Tough Days newsletter. You won\'t receive any more newsletter emails from us.</p>'
        . '<p>If this was a mistake, you can <a href="' . $safeResubscribeUrl . '">resubscribe any time</a>.</p>'
        . '<p>Warm regards,<br>Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

/**
 * Notifies the team when a member suggests a resource that's missing from the library.
 */
function send_resource_suggestion_email(string $fromEmail, ?string $fromName, string $message, string $catalog): bool
{
    $recipients = ['nic.marcon@emotionalbalance.com.au', 'june.zhong@emotionalbalance.com.au'];
    $name = trim((string) $fromName);
    $catalogLabel = $catalog === 'workplace' ? 'Workplace library (business.html)' : 'Personal library (support.html)';
    $fromLine = $name !== '' ? "{$name} <{$fromEmail}>" : $fromEmail;

    $subject = 'Resource suggestion from a member';

    $textBody = "A member couldn't find what they were looking for on the {$catalogLabel}.\n\n"
        . "From: {$fromLine}\n\n"
        . "Message:\n{$message}";

    $safeFromLine = htmlspecialchars($fromLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCatalog = htmlspecialchars($catalogLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    $htmlBody = "<p>A member couldn't find what they were looking for on the {$safeCatalog}.</p>"
        . "<p><strong>From:</strong> {$safeFromLine}</p>"
        . "<p><strong>Message:</strong><br>{$safeMessage}</p>";

    $sentAny = false;
    foreach ($recipients as $recipient) {
        if (send_transactional_email($recipient, $subject, $textBody, $htmlBody)) {
            $sentAny = true;
        }
    }

    return $sentAny;
}

function smtp_send_mail(array $recipients, string $subject, string $textBody, ?string $htmlBody = null, array $attachments = []): void
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
        smtp_write($socket, build_rfc822_message($from, $cleanRecipients, $replyTo, $subject, $textBody, $htmlBody, $attachments));
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
    ?string $htmlBody = null,
    array $attachments = []
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
        $altBoundary = '=_Part_' . bin2hex(random_bytes(12));
        $bodyPayload = '--' . $altBoundary . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
            . $safeTextBody . "\r\n\r\n"
            . '--' . $altBoundary . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n"
            . $safeHtmlBody . "\r\n\r\n"
            . '--' . $altBoundary . "--";
        $bodyContentType = 'multipart/alternative; boundary="' . $altBoundary . '"';
    } else {
        $bodyPayload = $safeTextBody;
        $bodyContentType = 'text/plain; charset=UTF-8';
    }

    if (empty($attachments)) {
        $headers[] = 'Content-Type: ' . $bodyContentType;
        if ($safeHtmlBody === '') {
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        }
        $payload = $bodyPayload;
    } else {
        $mixedBoundary = '=_Part_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

        $payload = '--' . $mixedBoundary . "\r\n"
            . 'Content-Type: ' . $bodyContentType . "\r\n"
            . ($safeHtmlBody === '' ? 'Content-Transfer-Encoding: 8bit' . "\r\n" : '')
            . "\r\n"
            . $bodyPayload . "\r\n\r\n";

        foreach ($attachments as $attachment) {
            $payload .= build_mime_attachment_part($mixedBoundary, $attachment);
        }

        $payload .= '--' . $mixedBoundary . "--";
    }

    // SMTP DATA escaping: lines beginning with a dot must be doubled.
    $payload = preg_replace('/(^|\r\n)\./', '$1..', $payload) ?? $payload;

    return implode("\r\n", $headers) . "\r\n\r\n" . $payload;
}

/**
 * Builds one base64-encoded MIME attachment part.
 * $attachment: ['path' => string, 'filename' => string, 'mime' => string]
 */
function build_mime_attachment_part(string $boundary, array $attachment): string
{
    $contents = file_get_contents($attachment['path']);
    if ($contents === false) {
        throw new RuntimeException('Could not read attachment: ' . $attachment['path']);
    }

    $safeFilename = preg_replace('/[\r\n"]+/', '', $attachment['filename']) ?? 'attachment';
    $mime = $attachment['mime'] ?: 'application/octet-stream';
    $encoded = chunk_split(base64_encode($contents), 76, "\r\n");

    return '--' . $boundary . "\r\n"
        . 'Content-Type: ' . $mime . '; name="' . $safeFilename . '"' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n"
        . 'Content-Disposition: attachment; filename="' . $safeFilename . '"' . "\r\n\r\n"
        . $encoded . "\r\n";
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

function extract_first_name(?string $fullName): string
{
    $name = trim((string) $fullName);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    return $parts[0] ?? $name;
}

function load_reminder_messages(): array
{
    $path = __DIR__ . '/../data/TTTD - Automated message database.csv';
    if (!is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $messages = [];
    fgetcsv($handle); // skip header row

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 4) {
            continue;
        }

        $subject = trim($row[2]);
        $rawBody = str_replace("\r\n", "\n", $row[3]);

        // Extract CTA label before stripping internal sections
        $ctaText = 'Check in now';
        if (preg_match('/\nCTA:\s*(.+)/', $rawBody, $m)) {
            $candidate = trim(str_replace('→', '', $m[1]));
            if ($candidate !== '') {
                $ctaText = $candidate;
            }
        }

        // Keep only the main message — strip Why it works / Note for dev team / CTA
        $parts   = preg_split('/\n(?:Why it works|Note for dev team|CTA):/', $rawBody, 2);
        $msgBody = trim($parts[0] ?? $rawBody);

        if ($subject === '' || $msgBody === '') {
            continue;
        }

        $messages[] = [
            'subject' => $subject,
            'body'    => $msgBody,
            'cta'     => $ctaText,
        ];
    }

    fclose($handle);
    return $messages;
}

function load_personalized_messages(): array
{
    $path = __DIR__ . '/../data/coping-strategy-messages.php';
    if (!is_readable($path)) {
        return ['strategies' => [], 'professional_support' => []];
    }

    $data = require $path;
    return is_array($data) ? $data : ['strategies' => [], 'professional_support' => []];
}

function reminder_message_hash(string $subject, string $body): string
{
    return md5($subject . '|' . $body);
}

function send_checkin_reminder_email(
    string $toEmail,
    ?string $fullName,
    array $copingStrategies = [],
    ?string $professionalSupport = null,
    array $excludeMessageHashes = [],
    ?string &$sentMessageHash = null
): bool {
    $firstName  = extract_first_name($fullName);
    $greeting   = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $siteUrl    = defined('SITE_URL') ? SITE_URL : 'https://www.toolsforthetoughdays.com.au';
    $checkinUrl = $siteUrl . '/support.html?utm_source=email&utm_medium=checkin';
    $prefsUrl   = $siteUrl . '/my-preference.html';

    $allMessages = load_reminder_messages();

    $personalized = load_personalized_messages();
    $defaultSubject = 'Time for your daily check-in';
    foreach ($copingStrategies as $strategy) {
        foreach ($personalized['strategies'][$strategy] ?? [] as $line) {
            $allMessages[] = ['subject' => $defaultSubject, 'body' => $line, 'cta' => 'Check in now'];
        }
    }
    foreach ($personalized['professional_support'][$professionalSupport ?? ''] ?? [] as $line) {
        $allMessages[] = ['subject' => $defaultSubject, 'body' => $line, 'cta' => 'Check in now'];
    }

    // Avoid repeating the user's most recent messages — but never let exclusion empty the pool.
    if (!empty($excludeMessageHashes) && !empty($allMessages)) {
        $unrepeated = array_values(array_filter(
            $allMessages,
            fn(array $m): bool => !in_array(reminder_message_hash($m['subject'], $m['body']), $excludeMessageHashes, true)
        ));
        if (!empty($unrepeated)) {
            $allMessages = $unrepeated;
        }
    }

    if (!empty($allMessages)) {
        $msg             = $allMessages[array_rand($allMessages)];
        $subject         = $msg['subject'];
        $ctaText         = $msg['cta'];
        $msgBody         = $msg['body'];
        $sentMessageHash = reminder_message_hash($subject, $msgBody);
    } else {
        $subject = 'Time for your daily check-in';
        $ctaText = 'Check in now';
        $msgBody = "This is your gentle reminder to take a moment for your daily mood check-in.\n\n"
                 . "It only takes a few seconds and helps you track how you are really travelling over time.";
        $sentMessageHash = reminder_message_hash($subject, $msgBody);
    }

    $textBody = $greeting . "\n\n"
        . $msgBody . "\n\n"
        . $ctaText . ': ' . $checkinUrl . "\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au\n\n"
        . "To change your reminder settings: " . $prefsUrl;

    $safeCheckinUrl = htmlspecialchars($checkinUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePrefsUrl   = htmlspecialchars($prefsUrl,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlGreeting   = $firstName !== ''
        ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hi there,';
    $safeCtaText    = htmlspecialchars($ctaText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMsgBody    = nl2br(htmlspecialchars($msgBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    $htmlBody = '<p>' . $htmlGreeting . '</p>'
        . '<p>' . $safeMsgBody . '</p>'
        . '<p><a href="' . $safeCheckinUrl . '">' . $safeCtaText . '</a></p>'
        . '<p>Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>'
        . 'Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>'
        . '<p style="font-size:12px;color:#999;">To change your reminder settings, '
        . '<a href="' . $safePrefsUrl . '">update your preferences</a>.</p>';

    return send_transactional_email($toEmail, $subject, $textBody, $htmlBody);
}

function send_milestone_email(string $toEmail, ?string $fullName, int $count): bool
{
    $firstName = extract_first_name($fullName);
    $greeting  = $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    $siteUrl   = defined('SITE_URL') ? SITE_URL : 'https://www.toolsforthetoughdays.com.au';

    // Placeholder text — replace with content supplied by user
    $messages = [
        3  => [
            'subject' => 'You have done 3 check-ins',
            'body'    => "Three check-ins in. You are building something real here.\n\n"
                       . "Most people never get this far. Checking in on yourself — honestly, regularly — takes more courage than it looks.\n\n"
                       . "Keep going.",
        ],
        7  => [
            'subject' => 'Seven check-ins — you are showing up',
            'body'    => "Seven check-ins. That is a week of showing up for yourself.\n\n"
                       . "That kind of consistency is exactly what builds real self-awareness over time. You are doing it.\n\n"
                       . "Keep going.",
        ],
        14 => [
            'subject' => 'Two weeks of check-ins',
            'body'    => "Fourteen check-ins. You have been at this for two weeks.\n\n"
                       . "Two weeks of honest self-reflection is something genuinely worth marking. You are building a picture of your own wellbeing that most people never have.\n\n"
                       . "Keep going.",
        ],
        21 => [
            'subject' => 'Three weeks — almost a habit',
            'body'    => "Twenty-one check-ins. Research puts habit formation right around here.\n\n"
                       . "What started as a conscious effort is becoming something more automatic. That is exactly the goal.\n\n"
                       . "Keep going.",
        ],
        30 => [
            'subject' => '30 check-ins — a month of data',
            'body'    => "Thirty check-ins. A full month of honest data about how you are really travelling.\n\n"
                       . "Thirty days of self-reflection is something to be genuinely proud of. You now have more insight into your own mental health than most people ever develop.\n\n"
                       . "That matters.",
        ],
    ];

    $template = $messages[$count] ?? [
        'subject' => "Check-in milestone: {$count}",
        'body'    => "You have reached {$count} check-ins. Keep going.",
    ];

    $textBody = $greeting . "\n\n"
        . $template['body'] . "\n\n"
        . "Warm regards,\n"
        . "Nic Marcon\n"
        . "Registered Psychologist\n"
        . "Tools for the Tough Days\n"
        . "www.toolsforthetoughdays.com.au";

    $htmlGreeting = $firstName !== ''
        ? 'Hi ' . htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hi there,';

    $htmlBody = '<p>' . $htmlGreeting . '</p>'
        . '<p>' . nl2br(htmlspecialchars($template['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
        . '<p>Warm regards,<br>Nic Marcon<br>Registered Psychologist<br>'
        . 'Tools for the Tough Days<br>www.toolsforthetoughdays.com.au</p>';

    return send_transactional_email($toEmail, $template['subject'], $textBody, $htmlBody);
}