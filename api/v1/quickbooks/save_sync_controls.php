<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/save_sync_controls.php
 *
 * POST endpoint behind the "Sync & monitoring" card on
 * app/admin/quickbooks/settings.php (I20 / I23). Saves the QuickBooks settings
 * that are read by crons and pushers but previously had no screen:
 *
 *   banking.cdc_enabled             '0'|'1'   cron/qbo_bank_cdc.php + BankTransactionPuller::runCdc()
 *   banking.cdc_lookback_days       1–365     BankTransactionPuller::computeSince() (first-run window)
 *   drift.enabled                   '0'|'1'   cron/qbo_drift_check.php + DriftChecker::run()
 *   drift.gl_balance_enabled        '0'|'1'   DriftChecker GL-account-balance layer
 *   refund.deposit_account_id       QBO Id    RefundReceiptPusher (bank account refunds leave from)
 *   refund.payment_method_id        QBO Id    RefundReceiptPusher (PaymentMethodRef, e.g. cheque)
 *   sync_mode.<entity>              see SYNC_MODE_VALUES below
 *
 * Every key is validated against a whitelist; an unknown key is REJECTED
 * (422) rather than ignored, so a typo or a crafted payload can never write
 * an arbitrary quickbooks.* setting (e.g. sync_enabled, tokens) through here.
 *
 * Sync-mode values — exactly what the readers honour today:
 *   'sync' / 'queue'         → FleetForge pushes (every Enqueuer queues the job
 *                              and cron/qbo_sync_worker.php sends it; the two
 *                              values behave identically at present).
 *   'disabled' / 'qbo_to_ff' → every Enqueuer, Pusher, retry endpoint and
 *                              manual_sync refuse the FF→QBO push.
 * 'off' (only the worker reads it) and 'inherit_je' (documentation marker)
 * are deliberately NOT accepted — neither is a complete, honoured mode.
 *
 * super_admin only — same gate as save_master_controls.php: turning a push
 * type off (or the drift check) changes what reaches the books.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'view')
 *          + super_admin check. CSRF enforced by api/bootstrap.php.
 * @body    JSON: { settings: { "<short key>": "<value>", ... } }
 * @returns 200 { success: true, data: { applied: object } }
 *        | 403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * @session I20 / I23 (QuickBooks settings without a screen)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may change QuickBooks sync & monitoring controls.', 403);
}

// Entities whose quickbooks.sync_mode.<entity> is actually read by an
// Enqueuer/Pusher. item / fixed_asset / tax_remittance are intentionally
// absent: nothing reads their mode (FA + GST JEs follow journal_entry).
const FF_QBO_SYNC_MODE_ENTITIES = [
    'customer', 'vendor', 'invoice', 'payment', 'credit_memo',
    'credit_application', 'refund_receipt', 'invoice_writeoff',
    'bill', 'bill_payment', 'journal_entry',
];
const FF_QBO_SYNC_MODE_VALUES = ['sync', 'queue', 'disabled', 'qbo_to_ff'];

$body     = json_body();
$settings = $body['settings'] ?? null;
if (!is_array($settings) || $settings === []) {
    json_validation_error(['settings' => 'Nothing to save.']);
}

$applied = [];
$errors  = [];

foreach ($settings as $shortKey => $rawValue) {
    $shortKey = (string) $shortKey;
    // Scalars only — an array/object value is never a valid setting.
    if (!is_scalar($rawValue)) {
        $errors[$shortKey] = 'Invalid value.';
        continue;
    }
    $value = trim((string) $rawValue);

    // ── Boolean toggles: strict '0' | '1' (same rule as master controls) ──
    if (in_array($shortKey, ['banking.cdc_enabled', 'drift.enabled', 'drift.gl_balance_enabled'], true)) {
        if (!in_array($value, ['0', '1'], true)) {
            $errors[$shortKey] = "Must be '0' or '1'.";
            continue;
        }
        $applied[$shortKey] = $value;
        continue;
    }

    // ── CDC first-run lookback window, whole days ─────────────────────
    if ($shortKey === 'banking.cdc_lookback_days') {
        // ctype_digit rejects '-5', '1.5', '1e3' — the reader casts with
        // (int) and would silently turn those into something else.
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 365) {
            $errors[$shortKey] = 'Must be a whole number of days between 1 and 365.';
            continue;
        }
        $applied[$shortKey] = (string) (int) $value;
        continue;
    }

    // ── Refund Receipt QBO references (Account.Id / PaymentMethod.Id) ──
    // Blank is allowed (= not configured; RefundReceiptPusher then parks
    // refunds with a clear "not configured" reason instead of pushing).
    if (in_array($shortKey, ['refund.deposit_account_id', 'refund.payment_method_id'], true)) {
        if ($value !== '' && preg_match('/^\d{1,20}$/', $value) !== 1) {
            $errors[$shortKey] = 'Must be a QuickBooks Id (digits only), or blank.';
            continue;
        }
        $applied[$shortKey] = $value;
        continue;
    }

    // ── Per-entity sync modes ──────────────────────────────────────────
    if (str_starts_with($shortKey, 'sync_mode.')) {
        $entity = substr($shortKey, strlen('sync_mode.'));
        if (!in_array($entity, FF_QBO_SYNC_MODE_ENTITIES, true)) {
            $errors[$shortKey] = 'Unknown sync-mode entity.';
            continue;
        }
        if (!in_array($value, FF_QBO_SYNC_MODE_VALUES, true)) {
            $errors[$shortKey] = 'Must be one of: ' . implode(', ', FF_QBO_SYNC_MODE_VALUES) . '.';
            continue;
        }
        $applied[$shortKey] = $value;
        continue;
    }

    // Anything else is outside this endpoint's whitelist — reject loudly.
    $errors[$shortKey] = 'Unknown setting.';
}

if ($errors !== []) {
    json_validation_error($errors);
}

db_transaction(function () use (&$applied): void {
    foreach ($applied as $shortKey => $value) {
        QuickBooksClient::settings_write_qbo($shortKey, $value);
    }

    $user = current_user();
    db_insert('audit_log', [
        'user_id'     => $user['id'] ?? null,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_sync_controls',
        'notes'       => 'QBO sync & monitoring controls updated: ' . json_encode($applied),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['applied' => $applied]);
