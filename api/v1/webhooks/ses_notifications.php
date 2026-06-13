<?php
declare(strict_types=1);

/**
 * api/v1/webhooks/ses_notifications.php
 *
 * AWS SNS → SES bounce and complaint notification handler.
 * (S-PROD-2 / #21)
 *
 * This endpoint is publicly accessible — SNS delivers HTTPS POST
 * requests from AWS infrastructure, not from authenticated users.
 * Authentication is via SNS message signature verification ONLY.
 *
 * Message types handled:
 *   SubscriptionConfirmation — auto-confirms the SNS subscription
 *   Notification             — processes SES bounce/complaint payloads
 *   UnsubscribeConfirmation  — logged and ignored (do not re-confirm)
 *
 * Bounce policy (D-C):
 *   Permanent bounce → email_disabled=1 on customers + portal_users
 *   Complaint        → email_disabled=1 unconditionally (legal exposure)
 *   Transient bounce → logged only, no disable
 *
 * ALWAYS returns HTTP 200 to SNS on valid signatures.
 * HTTP 403 on signature verification failure (prevents DB writes).
 * Never return 5xx — SNS interprets that as a retry trigger.
 *
 * @method POST
 * @auth   SNS signature verification (no session)
 */

require_once dirname(__DIR__, 3) . '/config/app.php';

use FleetForge\Notifications\NotificationService;

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// ── Read raw body ────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    exit;
}

$msg = json_decode($rawBody, true);
if (!is_array($msg)) {
    http_response_code(400);
    exit;
}

// ── SNS signature verification ───────────────────────────────
// Uses aws/aws-sdk-php's built-in MessageValidator. Rejects any
// message whose signature cannot be verified against AWS's cert.
try {
    $validator = new \Aws\Sns\MessageValidator();
    // MessageValidator expects the fields as an object implementing ArrayAccess.
    // The SDK accepts a plain array wrapped in its own type; we pass the decoded array.
    $validator->validate(new \Aws\Sns\Message($msg));
} catch (\Aws\Sns\Exception\InvalidSnsMessageException $e) {
    error_log('[ses_webhook] Signature verification failed: ' . $e->getMessage());
    http_response_code(403);
    exit;
} catch (\Throwable $e) {
    error_log('[ses_webhook] Unexpected error during signature check: ' . $e->getMessage());
    http_response_code(403);
    exit;
}

$msgType = $msg['Type'] ?? '';

// ── SubscriptionConfirmation ─────────────────────────────────
if ($msgType === 'SubscriptionConfirmation') {
    $confirmUrl = $msg['SubscribeURL'] ?? '';
    if ($confirmUrl !== '' && str_starts_with($confirmUrl, 'https://sns.')) {
        $ch = curl_init($confirmUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        curl_close($ch);
        error_log('[ses_webhook] SNS subscription confirmed: ' . $confirmUrl);
    } else {
        error_log('[ses_webhook] SubscriptionConfirmation: suspicious URL, not confirmed: ' . $confirmUrl);
    }
    http_response_code(200);
    exit;
}

// ── UnsubscribeConfirmation — log and ignore ─────────────────
if ($msgType === 'UnsubscribeConfirmation') {
    error_log('[ses_webhook] Received UnsubscribeConfirmation — ignored.');
    http_response_code(200);
    exit;
}

// ── Notification — parse SES bounce/complaint payload ────────
if ($msgType !== 'Notification') {
    http_response_code(200);
    exit;
}

$sesPayload = json_decode($msg['Message'] ?? '{}', true);
if (!is_array($sesPayload)) {
    error_log('[ses_webhook] Notification Message is not valid JSON.');
    http_response_code(200);
    exit;
}

$notifType = $sesPayload['notificationType'] ?? $sesPayload['eventType'] ?? '';

// ── Idempotency on the SNS MessageId (MEDIUM 08) ─────────────
// SNS retries until it gets a 2xx and may deliver the same message more than
// once. Without dedup, a redelivered bounce/complaint was reprocessed —
// duplicating email_bounces + audit_log rows and re-disabling email. Record
// each MessageId once (the QBO payment webhook already does this with
// acc_qbo_webhook_events; email_bounces can't be the key since one message
// fans out to several recipients). A replay is a no-op.
$snsMessageId = (string) ($msg['MessageId'] ?? '');
if ($snsMessageId !== '') {
    if (db_row("SELECT id FROM ses_webhook_events WHERE message_id = ?", [$snsMessageId]) !== null) {
        error_log("[ses_webhook] duplicate SNS MessageId {$snsMessageId} — skipping reprocess.");
        http_response_code(200);
        echo json_encode(['status' => 'duplicate', 'message_id' => $snsMessageId]);
        exit;
    }
    try {
        db_insert('ses_webhook_events', [
            'message_id'        => $snsMessageId,
            'notification_type' => $notifType !== '' ? $notifType : null,
        ]);
    } catch (\Throwable $e) {
        // Concurrent delivery won the UNIQUE race — the other handler owns it.
        error_log("[ses_webhook] MessageId insert race for {$snsMessageId}: " . $e->getMessage());
        http_response_code(200);
        echo json_encode(['status' => 'duplicate_race', 'message_id' => $snsMessageId]);
        exit;
    }
}

// ── Build recipient list ─────────────────────────────────────
$recipients = [];

if ($notifType === 'Bounce') {
    $bounceData    = $sesPayload['bounce'] ?? [];
    $bounceType    = $bounceData['bounceType']    ?? 'Undetermined'; // Permanent|Transient|Undetermined
    $bounceSubtype = $bounceData['bounceSubType'] ?? '';
    foreach ($bounceData['bouncedRecipients'] ?? [] as $r) {
        $recipients[] = [
            'email'      => strtolower(trim($r['emailAddress'] ?? '')),
            'type'       => $bounceType,
            'subtype'    => $bounceSubtype,
            'diagnostic' => $r['diagnosticCode'] ?? '',
        ];
    }
} elseif ($notifType === 'Complaint') {
    foreach ($sesPayload['complaint']['complainedRecipients'] ?? [] as $r) {
        $recipients[] = [
            'email'      => strtolower(trim($r['emailAddress'] ?? '')),
            'type'       => 'Complaint',
            'subtype'    => $sesPayload['complaint']['complaintFeedbackType'] ?? '',
            'diagnostic' => '',
        ];
    }
} else {
    // Delivery confirmations, sends, etc. — not actionable here.
    http_response_code(200);
    exit;
}

if (empty($recipients)) {
    http_response_code(200);
    exit;
}

// ── Process each recipient ───────────────────────────────────
foreach ($recipients as $recipient) {
    $email      = $recipient['email'];
    $type       = $recipient['type'];    // Permanent|Transient|Complaint|Undetermined
    $subtype    = $recipient['subtype'];
    $diagnostic = $recipient['diagnostic'];

    if ($email === '') continue;

    // D-C: hard bounces (Permanent) and complaints auto-disable.
    //      Transient/Undetermined bounces are logged only.
    $shouldDisable = ($type === 'Permanent' || $type === 'Complaint');
    $actionTaken   = $shouldDisable ? 'email_disabled' : 'logged';

    // Insert bounce record
    $bounceId = db_insert('email_bounces', [
        'recipient_email' => $email,
        'bounce_type'     => $type,
        'bounce_subtype'  => $subtype,
        'diagnostic_code' => $diagnostic !== '' ? $diagnostic : null,
        'action_taken'    => $actionTaken,
        'raw_payload'     => json_encode($sesPayload),
        'processed_at'    => date('Y-m-d H:i:s'),
    ]);

    if (!$shouldDisable) {
        // Transient — log only, move on.
        error_log("[ses_webhook] Soft bounce logged for {$email} (type: {$type}, subtype: {$subtype})");
        continue;
    }

    // Auto-disable: customers table
    $disableReason = "SES {$type} ({$subtype}): " . substr($diagnostic, 0, 200);
    $disabledAt    = date('Y-m-d H:i:s');

    $customer = db_row(
        "SELECT id, email, email_disabled FROM customers WHERE LOWER(email) = ? AND deleted_at IS NULL LIMIT 1",
        [$email]
    );

    if ($customer && !(int) $customer['email_disabled']) {
        db_execute(
            "UPDATE customers SET email_disabled=1, email_disabled_reason=?, email_disabled_at=? WHERE id=?",
            [$disableReason, $disabledAt, $customer['id']]
        );
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'ses_webhook',
            'action'       => 'update',
            'module'       => 'customers',
            'entity_type'  => 'customer',
            'entity_id'    => $customer['id'],
            'entity_label' => $email,
            'notes'        => "email_disabled=1 set by SES bounce handler. Type: {$type}, Subtype: {$subtype}. Bounce record ID: {$bounceId}.",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);

        // Notify account managers so a human knows.
        try {
            NotificationService::notifyRole(
                'manager',
                'email.bounce_auto_disabled',
                "Customer email disabled: {$email} ({$type})",
                "SES reported a {$type} bounce for {$email}. Email sending has been automatically disabled for this customer. Review and re-enable from the customer edit page if the address is valid.",
                'warning',
                ['customer_id' => $customer['id'], 'bounce_type' => $type]
            );
        } catch (\Throwable $ex) {
            error_log('[ses_webhook] NotificationService failed: ' . $ex->getMessage());
        }

        error_log("[ses_webhook] Customer {$customer['id']} ({$email}) email_disabled=1 ({$type})");
    }

    // Auto-disable: portal_users table
    $portalUser = db_row(
        "SELECT id, email, email_disabled FROM portal_users WHERE LOWER(email) = ? LIMIT 1",
        [$email]
    );

    if ($portalUser && !(int) $portalUser['email_disabled']) {
        db_execute(
            "UPDATE portal_users SET email_disabled=1, email_disabled_reason=?, email_disabled_at=? WHERE id=?",
            [$disableReason, $disabledAt, $portalUser['id']]
        );
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'ses_webhook',
            'action'       => 'update',
            'module'       => 'users',
            'entity_type'  => 'portal_user',
            'entity_id'    => $portalUser['id'],
            'entity_label' => $email,
            'notes'        => "email_disabled=1 set by SES bounce handler. Type: {$type}, Subtype: {$subtype}. Bounce record ID: {$bounceId}.",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
        error_log("[ses_webhook] portal_user {$portalUser['id']} ({$email}) email_disabled=1 ({$type})");
    }
}

http_response_code(200);
exit;
