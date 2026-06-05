<?php
declare(strict_types=1);

/**
 * FleetForge — Send Credit Application Invite API
 *
 * @file        api/v1/credit_applications/send.php
 * @description Creates a NEW credit-application row and emails the customer a
 *              PUBLIC tokenized link (NO login — D-CCA-1). Mirrors the portal
 *              password-reset token mechanism: a random token is generated, only
 *              its SHA-256 hash is stored, and the RAW token is emailed once and
 *              never persisted or returned by the API (Trap 7). Re-send is just a
 *              fresh call → a new row (D-CCA-3); full history is retained.
 *
 *              The public form + the route that consumes ?token= land in S-CCA-2;
 *              this endpoint only fixes the URL shape:
 *                  {base_url}/credit-application?token=<raw token>
 *              resolved by SHA-256(token) → customer_credit_applications row.
 *
 * @method      POST
 * @body        JSON { customer_id: int }
 * @auth        Session required; require_permission('customers','create').
 *              Matches the closest analog api/v1/email/send.php (which gates on
 *              customers/create) per S-CCA-1 Q1 — diverges from the semantic
 *              'edit' preference by the operator's explicit "match the precedent"
 *              rule. CSRF enforced by api/bootstrap.php on all POSTs.
 * @returns     201 { id, customer_id, status, sent_at, token_expires_at,
 *                    email_sent, email_error? }   (NEVER returns the token)
 *              404 if customer not found
 *              422 if the customer has no email on file
 *
 * @depends     api/bootstrap.php, lib/Email/EmailService.php
 * @spec        FLEETFORGE_SPEC_FINAL.md (§ customer portal, § users/invite token)
 * @session     S-CCA-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Email\EmailService;

require_method('POST');
require_auth_api();
require_permission('customers', 'create');

$body         = json_body();
$customerId   = clean_int($body['customer_id'] ?? null);
// Optional admin note to include in the resend email (D-CCA-4-C: carried into invite)
$resendNote   = clean_string($body['resend_note'] ?? null, 2000);
if ($resendNote === '') { $resendNote = null; }
if (!$customerId || $customerId <= 0) {
    json_error('INVALID_ID', 'A valid customer_id is required.', 400);
}

// ── Resolve customer + recipient address ────────────────────────────────────
$customer = db_row(
    "SELECT id, company_name, contact_name, email, billing_email
       FROM customers
      WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// Prefer the primary contact email; fall back to billing email.
$toEmail = clean_string($customer['email'] ?: ($customer['billing_email'] ?? ''));
if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    json_error('NO_EMAIL',
        'This customer has no valid email on file. Add an email before sending a credit application.',
        422);
}
$toName = (string) ($customer['contact_name'] ?: $customer['company_name']);

// ── Token (mirror app/portal/auth/reset_password: raw emailed, hash stored) ─
$rawToken  = bin2hex(random_bytes(32));        // 64 hex chars
$tokenHash = hash('sha256', $rawToken);        // 64 char SHA-256 — the ONLY copy stored
$expiryDays = (int) settings_get('credit_application.token_expiry_days', 30);
if ($expiryDays <= 0) { $expiryDays = 30; }
$now       = date('Y-m-d H:i:s');
$expiresAt = date('Y-m-d H:i:s', time() + ($expiryDays * 86400));

// Public link shape (route lands in S-CCA-2). The raw token rides ONLY here.
$publicUrl = base_url('credit-application') . '?token=' . $rawToken;

$userId = current_user_id();
$appId  = null;

db_transaction(function () use (&$appId, $customerId, $tokenHash, $expiresAt, $userId, $now, $customer): void {
    $appId = db_insert('customer_credit_applications', [
        'customer_id'      => $customerId,
        'token_hash'       => $tokenHash,
        'token_expires_at' => $expiresAt,
        'status'           => 'sent',
        'sent_at'          => $now,
        'sent_by'          => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'create',
        'module'       => 'customers',
        'entity_type'  => 'credit_application',
        'entity_id'    => $appId,
        'entity_label' => 'Credit Application #' . $appId . ' — ' . ($customer['company_name'] ?? ''),
        'new_values'   => json_encode(['customer_id' => $customerId, 'status' => 'sent']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

// ── Email the invite (after commit — the row stands even if delivery fails;
//    operator can re-send). Body-only template wrapped by renderEmailHtml.
//    company_name / company_phone / sender_name come from companyVariables(). ─
$emailSent  = false;
$emailError = null;
try {
    $minReqHtml = (string) settings_get('credit_application.minimum_requirements_html', '');

    // If a reviewer included a note (e.g. needs_info resend), prepend it as a
    // styled banner above the minimum requirements block (D-CCA-4-C).
    if ($resendNote !== null) {
        $noteHtml = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">'
            . '<tr><td style="border-left:4px solid #3b82f6;background:#eff6ff;padding:12px 16px;border-radius:0 4px 4px 0;">'
            . '<strong style="font-size:14px;color:#1e40af;">Note from our team:</strong><br>'
            . '<span style="font-size:14px;color:#1e3a8a;">' . htmlspecialchars($resendNote, ENT_QUOTES, 'UTF-8') . '</span>'
            . '</td></tr></table>';
        $minReqHtml = $noteHtml . $minReqHtml;
    }

    $result = EmailService::sendFromTemplate(
        'credit_application_invite',
        $toEmail,
        $toName,
        [
            'customer_name'             => $toName,
            'credit_application_url'    => $publicUrl,
            'minimum_requirements_html' => $minReqHtml,
        ],
        [
            'customer_id' => $customerId,
            'entity_type' => 'customer',
            'entity_id'   => $customerId,
            'sent_by'     => $userId,
        ]
    );
    $emailSent  = (bool) ($result['success'] ?? false);
    $emailError = $emailSent ? null : ($result['error'] ?? 'Email delivery failed.');
} catch (\Throwable $e) {
    error_log('[S-CCA-1 send] email failed for application ' . $appId . ': ' . $e->getMessage());
    $emailError = 'Email delivery failed.';
}

json_success([
    'id'               => $appId,
    'customer_id'      => $customerId,
    'status'           => 'sent',
    'sent_at'          => $now,
    'token_expires_at' => $expiresAt,
    'email_sent'       => $emailSent,
    'email_error'      => $emailError,
], 201);
