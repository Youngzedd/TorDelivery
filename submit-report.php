<?php
/**
 * Tor Delivery — form handler
 *
 * Handles two form types:
 *   whistleblower  -> admin@tordelivery.com  (General Managing Director only)
 *   deletion       -> hello@tordelivery.com
 *
 * ROUTING — whistleblower reports go ONLY to the General Managing Director.
 * The page promises that managers, supervisors and other superiors never see
 * them. Do not add CC/BCC recipients or forwarding rules to this handler.
 *
 * PRIVACY — READ BEFORE EDITING
 * When a whistleblower report is submitted anonymously we deliberately do NOT
 * capture, log or transmit the submitter's IP address, user agent, or any other
 * identifying request metadata. Do not "improve" this file by adding request
 * logging: it would break the anonymity promise made on whistleblower.html.
 *
 * NOTE FOR SERVER ADMIN: the web server's own access log still records the IP
 * of every POST to this endpoint. To honour the anonymity promise end to end,
 * exclude this endpoint from access logging (or keep retention very short).
 * That is a server-config change and cannot be done from PHP.
 */

declare(strict_types=1);

// ---------------------------------------------------------------- constants
const RECIPIENT_WHISTLEBLOWER = 'admin@tordelivery.com';
const RECIPIENT_DELETION      = 'hello@tordelivery.com';
const MAIL_FROM               = 'no-reply@tordelivery.com';

const MAX_FIELD_LEN = 5000;
const MIN_FILL_SECS = 3;      // faster than this is almost certainly a bot

// ---------------------------------------------------------------- responses
function respond(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): void {
    respond($status, ['ok' => false, 'error' => $message]);
}

// ---------------------------------------------------------------- transport
// Only accept POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail('Method not allowed.', 405);
}

// Require HTTPS. Honour a reverse proxy's forwarded-proto header if present.
$proto = $_SERVER['HTTP_X_FORWARDED_PROTO']
    ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
if (strtolower((string) $proto) !== 'https') {
    fail('This form must be submitted over a secure (HTTPS) connection.', 403);
}

// ---------------------------------------------------------------- helpers
function field(string $key, int $max = MAX_FIELD_LEN): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    // Strip control characters (incl. CR/LF) that could be used for header injection.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

/** A value is only acceptable if it is one of a known set. */
function oneOf(string $value, array $allowed): bool {
    return $value !== '' && in_array($value, $allowed, true);
}

/** TD-YYYY-XXXXX — 5 chars from an unambiguous alphabet (no O/0/I/1). */
function referenceCode(string $prefix): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 5; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return sprintf('%s-%s-%s', $prefix, date('Y'), $out);
}

function sendMail(string $to, string $subject, string $body, ?string $replyTo = null): bool {
    $headers = [
        'From: Tor Delivery Forms <' . MAIL_FROM . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: tordelivery-form',
    ];
    // Only ever set Reply-To from an address that has passed validation.
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    // Encode the subject so non-ASCII cannot break the header.
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . MAIL_FROM);
}

// ---------------------------------------------------------------- anti-spam
// Honeypot: a field hidden from humans. Anything in it is a bot.
if (field('website') !== '') {
    // Pretend success so the bot does not learn it was caught.
    respond(200, ['ok' => true, 'reference' => referenceCode('TD')]);
}

// Timing check: forms filled impossibly fast are bots.
$startedAt = (int) field('startedAt', 20);
if ($startedAt > 0) {
    $elapsed = time() - (int) floor($startedAt / 1000);
    if ($elapsed >= 0 && $elapsed < MIN_FILL_SECS) {
        respond(200, ['ok' => true, 'reference' => referenceCode('TD')]);
    }
}

// ---------------------------------------------------------------- dispatch
$formType = field('formType', 32);

if ($formType === 'whistleblower') {
    handleWhistleblower();
} elseif ($formType === 'deletion') {
    handleDeletion();
}
fail('Unknown form type.');

// ---------------------------------------------------------------- handlers
function handleWhistleblower(): void {
    $categories = [
        'Fraud/financial misconduct', 'Theft', 'Safety violation',
        'Harassment/discrimination', 'Bribery/corruption', 'Data privacy breach',
        'Regulatory non-compliance', 'Conflict of interest', 'Other',
    ];
    $locations     = ['Cross River', 'Akwa Ibom'];
    $relationships = ['Employee', 'Rider', 'Merchant', 'Customer', 'Contractor', 'Other'];

    $reportType  = field('reportType', 32);
    if (!oneOf($reportType, ['anonymous', 'identified'])) {
        fail('Please choose whether to report anonymously or with your contact details.');
    }
    $isAnonymous = ($reportType === 'anonymous');

    $category    = field('category', 64);
    $description = field('description');
    $incidentAt  = field('incidentDate', 10);
    $location    = field('location', 32);
    $involved    = field('peopleInvolved');
    $witnesses   = field('witnesses');

    if (!oneOf($category, $categories))  fail('Please choose a category.');
    if ($description === '')             fail('Please describe the incident.');
    if ($incidentAt === '')              fail('Please give the date of the incident.');
    if (!oneOf($location, $locations))   fail('Please choose a location.');
    if ($involved === '')                fail('Please tell us who was involved.');

    // Date must be real and not in the future.
    $d = DateTime::createFromFormat('Y-m-d', $incidentAt);
    if (!$d || $d->format('Y-m-d') !== $incidentAt) fail('Please give a valid incident date.');
    if ($d > new DateTime('tomorrow')) fail('The incident date cannot be in the future.');

    // Contact block — only read when the reporter chose to identify themselves.
    $name = $relationship = $email = $phone = '';
    if (!$isAnonymous) {
        $name         = field('fullName', 200);
        $relationship = field('relationship', 32);
        $email        = field('email', 254);
        $phone        = field('phone', 40);

        if ($name === '')                              fail('Please enter your full name.');
        if (!oneOf($relationship, $relationships))     fail('Please choose your relationship to Tor Delivery.');
        if ($email === '')                             fail('Please enter your email address.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');
    }

    $reference = referenceCode('TD');

    // Build the report. For anonymous reports nothing identifying is added:
    // no IP, no user agent, no referrer.
    $lines = [
        'WHISTLEBLOWER REPORT',
        str_repeat('=', 56),
        'Reference:   ' . $reference,
        'Received:    ' . gmdate('Y-m-d H:i:s') . ' UTC',
        'Type:        ' . ($isAnonymous ? 'ANONYMOUS' : 'Identified'),
        '',
        'Category:    ' . $category,
        'Incident on: ' . $incidentAt,
        'Location:    ' . $location,
        '',
        'DESCRIPTION',
        str_repeat('-', 56),
        $description,
        '',
        'PEOPLE INVOLVED',
        str_repeat('-', 56),
        $involved,
        '',
        'WITNESSES',
        str_repeat('-', 56),
        $witnesses !== '' ? $witnesses : '(none given)',
        '',
    ];

    if ($isAnonymous) {
        $lines[] = 'REPORTER';
        $lines[] = str_repeat('-', 56);
        $lines[] = 'Submitted anonymously. No contact details were collected and no';
        $lines[] = 'IP address or device information has been recorded for this report.';
    } else {
        $lines[] = 'REPORTER';
        $lines[] = str_repeat('-', 56);
        $lines[] = 'Name:         ' . $name;
        $lines[] = 'Relationship: ' . $relationship;
        $lines[] = 'Email:        ' . $email;
        $lines[] = 'Phone:        ' . ($phone !== '' ? $phone : '(not given)');
    }

    $lines[] = '';
    $lines[] = str_repeat('=', 56);
    $lines[] = 'Confidential — for the General Managing Director only.';
    $lines[] = 'Do not forward to managers, supervisors, other superiors or HR.';

    $body = implode("\n", $lines);

    // Never expose an anonymous reporter through the Reply-To header.
    $replyTo = $isAnonymous ? null : ($email ?: null);
    $subject = sprintf('[%s] Whistleblower report — %s', $reference, $category);

    if (!sendMail(RECIPIENT_WHISTLEBLOWER, $subject, $body, $replyTo)) {
        fail('We could not submit your report. Please try again, or email admin@tordelivery.com directly.', 500);
    }

    // Acknowledge to the reporter only if they gave an address.
    $emailed = false;
    if (!$isAnonymous && $email !== '') {
        $ack = implode("\n", [
            'Thank you for raising your concern with Tor Delivery.',
            '',
            'Your reference is: ' . $reference,
            '',
            'Please keep this reference. You can quote it in any follow-up',
            'correspondence about your report.',
            '',
            'Your report has gone directly to the General Managing Director.',
            'Managers, supervisors and other superiors do not see it, and it has',
            'not been routed through line management or HR.',
            '',
            'No one raising a genuine concern in good faith will face dismissal,',
            'demotion or any other detriment for doing so.',
            '',
            '— Tor Delivery',
        ]);
        $emailed = sendMail($email, 'Your report reference: ' . $reference, $ack);
    }

    respond(200, ['ok' => true, 'reference' => $reference, 'emailed' => $emailed]);
}

function handleDeletion(): void {
    $reasons = ['no_longer_use', 'privacy_concerns', 'switching_service', 'other'];

    $name    = field('fullName', 200);
    $phone   = field('phone', 40);
    $email   = field('email', 254);
    $reason  = field('reason', 32);
    $extra   = field('additionalInfo');
    $confirm = field('confirmDeletion', 8);

    if ($name === '')                fail('Please enter your full name.');
    if ($phone === '')               fail('Please enter your registered phone number.');
    if (!oneOf($reason, $reasons))   fail('Please select a reason for deletion.');
    if ($confirm === '')             fail('Please confirm you understand this action is permanent.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Please enter a valid email address.');
    }

    $reference = referenceCode('TDR');

    $body = implode("\n", [
        'ACCOUNT DELETION REQUEST',
        str_repeat('=', 56),
        'Reference: ' . $reference,
        'Received:  ' . gmdate('Y-m-d H:i:s') . ' UTC',
        '',
        'Name:      ' . $name,
        'Phone:     ' . $phone,
        'Email:     ' . ($email !== '' ? $email : '(not given)'),
        'Reason:    ' . $reason,
        '',
        'ADDITIONAL INFORMATION',
        str_repeat('-', 56),
        $extra !== '' ? $extra : '(none given)',
        '',
        str_repeat('=', 56),
        'Submitted under the Nigeria Data Protection Act 2023.',
        'Process within 14 business days.',
    ]);

    if (!sendMail(RECIPIENT_DELETION, '[' . $reference . '] Account deletion request', $body, $email ?: null)) {
        fail('We could not submit your request. Please try again, or email hello@tordelivery.com directly.', 500);
    }

    respond(200, ['ok' => true, 'reference' => $reference]);
}
