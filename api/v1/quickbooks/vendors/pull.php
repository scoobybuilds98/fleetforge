<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/vendors/pull.php
 *
 * Pull all QBO vendors (active + inactive) and upsert into
 * acc_qbo_vendor_map. User-initiated — fires when the operator
 * clicks "Pull from QuickBooks" on the Vendors Sync page.
 *
 * Mirrors api/v1/quickbooks/customers/pull.php (S-QBO-5):
 *   - 1 real HTTP request to QBO per 100 vendors (typical Mainland
 *     ~5-50 = 1 request; well under spec §14.1's 40 req/min sandbox
 *     throttle).
 *   - For each pulled vendor:
 *       * If row exists with this qbo_vendor_id: UPDATE snapshot
 *         fields (display_name, company_name, given/family_name,
 *         email, phone, active, v4v_status, sync_token) + last_pull_at.
 *       * Else: INSERT new row with mapping_status='qbo_only'.
 *         (Auto-match will reclassify on next operator click.)
 *   - 'mapped' / 'manual' rows are left in their current state —
 *     pull only refreshes QBO-side snapshot data, never breaks
 *     existing operator-confirmed links.
 *
 * Failure handling: QuickBooksException + any retryable transients
 * surface as success:false with a diagnostic message. The underlying
 * QuickBooksClient already writes to acc_qbo_sync_log per S-QBO-2 —
 * failed pulls leave a forensic trail there.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — read-only against QBO
 * @returns 200 { success: true, pulled_count: int, inserted: int, updated: int, total_in_qbo: int }
 *
 * Spec ref: §7.5 (vendor mapping table), §14.1 (rate limits)
 * Session:  S-QBO-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\VendorPuller;
use FleetForge\Exceptions\QuickBooksException;

try {
    $vendors      = VendorPuller::pullAll();
    $userId       = current_user_id();
    $insertedRows = 0;
    $updatedRows  = 0;

    foreach ($vendors as $v) {
        // Try UPDATE first — keeps existing mapping_status +
        // match_confidence untouched (we only refresh QBO-side
        // snapshot fields). Operator-linked rows survive pulls
        // without losing their manual confirmation.
        $affected = db_execute(
            "UPDATE acc_qbo_vendor_map SET
                qbo_display_name = ?,
                qbo_company_name = ?,
                qbo_given_name   = ?,
                qbo_family_name  = ?,
                qbo_email        = ?,
                qbo_phone        = ?,
                qbo_active       = ?,
                qbo_v4v_status   = ?,
                qbo_sync_token   = ?,
                last_pull_at     = NOW()
              WHERE qbo_vendor_id = ?",
            [
                $v['display_name'],
                $v['company_name'],
                $v['given_name'],
                $v['family_name'],
                $v['email'],
                $v['phone'],
                $v['active'] ? 1 : 0,
                $v['v4v_status'],
                $v['sync_token'],
                $v['qbo_id'],
            ]
        );

        if ($affected > 0) {
            $updatedRows++;
            continue;
        }

        // No existing row → INSERT new qbo_only entry.
        db_insert('acc_qbo_vendor_map', [
            'qbo_vendor_id'      => $v['qbo_id'],
            'qbo_sync_token'     => $v['sync_token'],
            'qbo_display_name'   => $v['display_name'],
            'qbo_company_name'   => $v['company_name'],
            'qbo_given_name'     => $v['given_name'],
            'qbo_family_name'    => $v['family_name'],
            'qbo_email'          => $v['email'],
            'qbo_phone'          => $v['phone'],
            'qbo_active'         => $v['active'] ? 1 : 0,
            'qbo_v4v_status'     => $v['v4v_status'],
            'mapping_status'     => 'qbo_only',
            'last_pull_at'       => date('Y-m-d H:i:s'),
            'created_by_user_id' => $userId,
        ]);
        $insertedRows++;
    }

    json_success([
        'pulled_count' => count($vendors),
        'inserted'     => $insertedRows,
        'updated'      => $updatedRows,
        'total_in_qbo' => count($vendors),
    ]);

} catch (QuickBooksException $e) {
    // Typed QBO failure — sync_log already has the diagnostic row
    // (written by QuickBooksClient::dispatch). Surface a clean error
    // envelope so the operator sees actionable text.
    json_error(
        'QBO_PULL_FAILED',
        'Vendor pull failed: ' . $e->getMessage(),
        502
    );
} catch (\Throwable $e) {
    json_error(
        'INTERNAL_ERROR',
        'Unexpected error during vendor pull: ' . $e->getMessage(),
        500
    );
}
