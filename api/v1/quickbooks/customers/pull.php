<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/customers/pull.php
 *
 * Pull all QBO customers (active + inactive) and upsert into
 * acc_qbo_customer_map. User-initiated — fires when the operator
 * clicks "Pull from QuickBooks" on the Customers Sync page.
 *
 * Side effects:
 *   - 1 real HTTP request to QBO per 100 customers (typical Mainland
 *     ~50-200 = 1-2 requests; well under spec §14.1's 40 req/min
 *     sandbox throttle).
 *   - For each pulled customer:
 *       * If row exists with this qbo_customer_id: UPDATE snapshot
 *         fields (display_name, company_name, email, phone, active,
 *         balance, sync_token) + last_pull_at.
 *       * Else: INSERT new row with mapping_status='qbo_only'.
 *         (Auto-match will reclassify on next operator click.)
 *   - The 'mapped' / 'manual' rows are left in their current state
 *     — pull only refreshes QBO-side snapshot data, never breaks
 *     existing operator-confirmed links.
 *
 * Failure handling: QuickBooksException + any retryable transients
 * surface as success:false with the diagnostic message. The
 * underlying QuickBooksClient already writes to acc_qbo_sync_log
 * per S-QBO-2 — failed pulls leave a forensic trail there.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — pull is read-only
 *          against QBO; doesn't push, doesn't disconnect, doesn't
 *          rotate credentials. View-level gate is appropriate.
 * @returns 200 { success: true, pulled_count: int, inserted: int, updated: int, total_in_qbo: int }
 *
 * Spec ref: §7.4 (mapping table), §8.1 (Customer field map), §14.1 (rate limits)
 * Session:  S-QBO-5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\CustomerPuller;
use FleetForge\Exceptions\QuickBooksException;

try {
    $customers   = CustomerPuller::pullAll();
    $userId      = current_user_id();
    $insertedRows = 0;
    $updatedRows  = 0;

    foreach ($customers as $c) {
        // Try UPDATE first — keeps existing mapping_status + match_confidence
        // untouched (we only refresh QBO-side snapshot fields). Operator-
        // linked rows survive pulls without losing their manual confirmation.
        $affected = db_execute(
            "UPDATE acc_qbo_customer_map SET
                qbo_display_name = ?,
                qbo_company_name = ?,
                qbo_email        = ?,
                qbo_phone        = ?,
                qbo_active       = ?,
                qbo_balance      = ?,
                qbo_sync_token   = ?,
                last_pull_at     = NOW()
              WHERE qbo_customer_id = ?",
            [
                $c['display_name'],
                $c['company_name'],
                $c['email'],
                $c['phone'],
                $c['active'] ? 1 : 0,
                $c['balance'],
                $c['sync_token'],
                $c['qbo_id'],
            ]
        );

        if ($affected > 0) {
            $updatedRows++;
            continue;
        }

        // No existing row → INSERT new qbo_only entry. Default status
        // matches the schema default; we set it explicitly for clarity.
        db_insert('acc_qbo_customer_map', [
            'qbo_customer_id'    => $c['qbo_id'],
            'qbo_sync_token'     => $c['sync_token'],
            'qbo_display_name'   => $c['display_name'],
            'qbo_company_name'   => $c['company_name'],
            'qbo_email'          => $c['email'],
            'qbo_phone'          => $c['phone'],
            'qbo_active'         => $c['active'] ? 1 : 0,
            'qbo_balance'        => $c['balance'],
            'mapping_status'     => 'qbo_only',
            'last_pull_at'       => date('Y-m-d H:i:s'),
            'created_by_user_id' => $userId,
        ]);
        $insertedRows++;
    }

    json_success([
        'pulled_count' => count($customers),
        'inserted'     => $insertedRows,
        'updated'      => $updatedRows,
        'total_in_qbo' => count($customers),
    ]);

} catch (QuickBooksException $e) {
    // Typed QBO failure — sync_log already has the diagnostic row
    // (written by QuickBooksClient::dispatch). Surface a clean
    // error envelope to the UI so the operator sees actionable text.
    json_error(
        'QBO_PULL_FAILED',
        'Customer pull failed: ' . $e->getMessage(),
        502
    );
} catch (\Throwable $e) {
    json_error(
        'INTERNAL_ERROR',
        'Unexpected error during customer pull: ' . $e->getMessage(),
        500
    );
}
