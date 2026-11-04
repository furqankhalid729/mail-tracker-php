<?php
/**
 * Amazon SES email validation (SESv2 GetEmailAddressInsights), called before campaign mail is sent.
 * Plain curl + AWS Signature V4, so no AWS SDK is needed on shared hosting.
 *
 * Verdicts (IsValid.ConfidenceVerdict): HIGH → 'valid', MEDIUM → 'risky', LOW → 'invalid'.
 * Results are stored on the customer (email_check*) and reused for SES_VALIDATION_CACHE_DAYS, so each
 * address is paid for once.
 */
defined('APP_ROOT') || exit;

/**
 * Ask SES about one address.
 * Returns ['ok' => true, 'result' => valid|risky|invalid, 'detail' => string]
 *      or ['ok' => false, 'error' => string, 'retry_after' => seconds].
 */
function ses_validate_email(string $email): array
{
    $host = 'email.' . AWS_REGION . '.amazonaws.com';
    $path = '/v2/email/email-address-insights/';
    $body = json_encode(['EmailAddress' => $email]);
    $amzDate = gmdate('Ymd\THis\Z');
    $date = substr($amzDate, 0, 8);
    $scope = "$date/" . AWS_REGION . '/ses/aws4_request';

    $canonical = "POST\n$path\n\ncontent-type:application/json\nhost:$host\nx-amz-date:$amzDate\n\ncontent-type;host;x-amz-date\n" . hash('sha256', $body);
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'ses', hash_hmac('sha256', AWS_REGION, hash_hmac('sha256', $date, 'AWS4' . AWS_SECRET_KEY, true), true), true), true);
    $signature = hash_hmac('sha256', $toSign, $key);

    $ch = curl_init("https://$host$path");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "X-Amz-Date: $amzDate",
            'Authorization: AWS4-HMAC-SHA256 Credential=' . AWS_ACCESS_KEY . "/$scope, SignedHeaders=content-type;host;x-amz-date, Signature=$signature",
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'SES validation request failed: ' . $curlErr, 'retry_after' => 600];
    }
    $data = json_decode((string) $raw, true) ?: [];

    if ($code === 400) {
        // SES rejects addresses it cannot even parse → treat as invalid rather than retrying forever
        return ['ok' => true, 'result' => 'invalid', 'detail' => 'Rejected by SES: ' . mb_substr((string) ($data['message'] ?? $data['Message'] ?? 'bad address'), 0, 150)];
    }
    if ($code !== 200) {
        $msg = (string) ($data['message'] ?? $data['Message'] ?? mb_substr((string) $raw, 0, 150));
        return ['ok' => false, 'error' => "SES validation error (HTTP $code): $msg", 'retry_after' => $code === 429 ? 120 : 900];
    }

    $mv = $data['MailboxValidation'] ?? [];
    $verdict = strtoupper((string) ($mv['IsValid']['ConfidenceVerdict'] ?? ''));
    $result = match ($verdict) {
        'HIGH' => 'valid',
        'MEDIUM' => 'risky',
        'LOW' => 'invalid',
        default => 'risky',
    };

    // Short human-readable reason from the individual checks
    $labels = [
        'HasValidSyntax' => 'bad syntax', 'HasValidDnsRecords' => 'domain cannot receive mail',
        'MailboxExists' => 'mailbox may not exist', 'IsDisposable' => 'disposable address',
        'IsRoleAddress' => 'role address', 'IsRandomInput' => 'looks randomly typed',
    ];
    $problems = [];
    foreach ($labels as $check => $label) {
        $v = strtoupper((string) ($mv['Evaluations'][$check]['ConfidenceVerdict'] ?? ''));
        if ($v === '') {
            continue;
        }
        // "Has/Exists" checks are bad when LOW; "Is…" checks are bad when HIGH
        $bad = str_starts_with($check, 'Is') ? $v === 'HIGH' : $v === 'LOW';
        if ($bad) {
            $problems[] = $label;
        }
    }
    $detail = 'SES confidence ' . ($verdict ?: 'unknown') . ($problems ? ': ' . implode(', ', $problems) : '');

    return ['ok' => true, 'result' => $result, 'detail' => $detail];
}

/**
 * Decide whether a campaign message may go to this customer. Uses the stored result when it is recent and for
 * the same address, otherwise asks SES and stores the answer.
 *
 * Returns ['send' => true] | ['send' => false, 'reason' => string] | ['defer' => seconds, 'reason' => string].
 * A blocked address marks the customer status 'invalid'. Setting the customer back to 'active' by hand is an
 * override: the stored check is kept, and mail is sent.
 */
function email_validation_gate(array $customer): array
{
    if (!SES_VALIDATION_ENABLED) {
        return ['send' => true];
    }
    $email = strtolower(trim((string) $customer['email']));
    $fresh = $customer['email_check'] !== null
        && $customer['email_checked_address'] === $email
        && $customer['email_checked_at'] >= date('Y-m-d H:i:s', time() - SES_VALIDATION_CACHE_DAYS * 86400);
    if ($fresh) {
        return ['send' => true]; // already checked; status (not the cached verdict) decides, so manual overrides stick
    }

    $r = ses_validate_email($email);
    if (!$r['ok']) {
        return ['defer' => $r['retry_after'], 'reason' => $r['error']];
    }

    $blocked = $r['result'] === 'invalid' || ($r['result'] === 'risky' && SES_VALIDATION_BLOCK_RISKY);
    q(
        'UPDATE customers SET email_check = ?, email_check_detail = ?, email_checked_address = ?, email_checked_at = ?'
        . ($blocked ? ", status = IF(status = 'active', 'invalid', status)" : '') . ', updated_at = ? WHERE id = ?',
        [$r['result'], mb_substr($r['detail'], 0, 255), $email, now(), now(), $customer['id']]
    );

    return $blocked
        ? ['send' => false, 'reason' => 'Email failed validation (' . $r['result'] . '): ' . $r['detail']]
        : ['send' => true];
}
