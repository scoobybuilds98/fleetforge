<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/accounts/pull.php
 *
 * Pull all QBO accounts (active + inactive) and upsert into
 * acc_qbo_account_map. User-initiated from the Accounts Mapping page.
 * Per D-QBO-8-1 (Puller-only pattern), there is no corresponding
 * push endpoint — accountant manages QBO COA structure.
 *
 * Side effects:
 *   - 1 real HTTP request to QBO per ~100 accounts (typical chart
 *     ~30-60 = 1 request; well under spec §14.1 rate limit).
 *   - For each pulled account:
 *       * If row exists with this qbo_account_id: UPDATE snapshot
 *         fields (name, fully_qualified_name, account_type/subtype,
 *         classification, account_number, active, current_balance,
 *         sync_token) + last_pull_at.
 *       * Else: INSERT new row with mapping_status='qbo_only'.
 *   - Auto-runs AccountValidator::markCriticalAccounts() to ensure
 *     is_critical flags reflect current FF chart state.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — read-only against QBO
 * @returns 200 { success: true, pulled_count, inserted, updated, total_in_qbo, critical_marked, critical_unmapped }
 *
 * Spec ref: §7.1 (account mapping table), §14.1 (rate limits)
 * Session:  S-QBO-8
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\AccountPuller;
use FleetForge\QboPushers\AccountValidator;
use FleetForge\Exceptions\QuickBooksException;

try {
    $accounts     = AccountPuller::pullAll();
    $userId       = current_user_id();
    $insertedRows = 0;
    $updatedRows  = 0;

    foreach ($accounts as $a) {
        // UPDATE existing qbo_account_id row first — preserves
        // mapping_status, match_confidence, is_critical, ff link.
        $affected = db_execute(
            "UPDATE acc_qbo_account_map SET
                qbo_name                 = ?,
                qbo_fully_qualified_name = ?,
                qbo_account_type         = ?,
                qbo_account_subtype      = ?,
                qbo_classification       = ?,
                qbo_account_number       = ?,
                qbo_active               = ?,
                qbo_current_balance      = ?,
                qbo_sync_token           = ?,
                last_pull_at             = NOW()
              WHERE qbo_account_id = ?",
            [
                $a['name'],
                $a['fully_qualified_name'],
                $a['account_type'],
                $a['account_subtype'],
                $a['classification'],
                $a['account_number'],
                $a['active'] ? 1 : 0,
                $a['current_balance'],
                $a['sync_token'],
                $a['qbo_id'],
            ]
        );

        if ($affected > 0) {
            $updatedRows++;
            continue;
        }

        // No existing row → INSERT new qbo_only entry.
        db_insert('acc_qbo_account_map', [
            'qbo_account_id'           => $a['qbo_id'],
            'qbo_sync_token'           => $a['sync_token'],
            'qbo_name'                 => $a['name'],
            'qbo_fully_qualified_name' => $a['fully_qualified_name'],
            'qbo_account_type'         => $a['account_type'],
            'qbo_account_subtype'      => $a['account_subtype'],
            'qbo_classification'       => $a['classification'],
            'qbo_account_number'       => $a['account_number'],
            'qbo_active'               => $a['active'] ? 1 : 0,
            'qbo_current_balance'      => $a['current_balance'],
            'mapping_status'           => 'qbo_only',
            'last_pull_at'             => date('Y-m-d H:i:s'),
            'created_by_user_id'       => $userId,
        ]);
        $insertedRows++;
    }

    // Mark bridge accounts (idempotent — safe on every pull). The
    // validator inserts ff_only rows for critical accounts that
    // don't yet have any mapping row, so unmappedCritical() returns
    // accurate results even before the first Auto-Match.
    $criticalMarked = AccountValidator::markCriticalAccounts();
    $criticalUnmapped = count(AccountValidator::unmappedCritical());

    json_success([
        'pulled_count'      => count($accounts),
        'inserted'          => $insertedRows,
        'updated'           => $updatedRows,
        'total_in_qbo'      => count($accounts),
        'critical_marked'   => $criticalMarked,
        'critical_unmapped' => $criticalUnmapped,
    ]);

} catch (QuickBooksException $e) {
    json_error(
        'QBO_PULL_FAILED',
        'Account pull failed: ' . $e->getMessage(),
        502
    );
} catch (\Throwable $e) {
    json_error(
        'INTERNAL_ERROR',
        'Unexpected error during account pull: ' . $e->getMessage(),
        500
    );
}
