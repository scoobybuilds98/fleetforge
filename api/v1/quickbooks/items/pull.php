<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/items/pull.php
 *
 * Pull all QBO Items and upsert into acc_qbo_item_map. Then ensure
 * every FF item_type tuple (17 ENUM values + 2 GPS variants per
 * D-QBO-10-2 = 18 total) has an ff_only row in the map — so the UI
 * can surface unmapped FF item_types for the operator to provision
 * QBO Items via ItemCreator.
 *
 * Unlike tax_codes/pull.php this endpoint does NOT auto-wire any
 * settings value — Items don't have a single load-bearing override
 * (every FF item_type needs an explicit mapping for S-QBO-11 to push
 * a line item).
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — read-only against QBO
 * @returns 200 { success: true, pulled_count, inserted, updated,
 *                ff_types_count, missing_ff_to_qbo: int }
 *
 * Spec ref: §7.3 (item mapping table)
 * Session:  S-QBO-10
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\ItemPuller;
use FleetForge\QboPushers\ItemMatcher;
use FleetForge\Exceptions\QuickBooksException;

try {
    $items        = ItemPuller::pullAll();
    $userId       = current_user_id();
    $insertedRows = 0;
    $updatedRows  = 0;

    foreach ($items as $it) {
        $affected = db_execute(
            "UPDATE acc_qbo_item_map SET
                qbo_name                 = ?,
                qbo_fully_qualified_name = ?,
                qbo_description          = ?,
                qbo_type                 = ?,
                qbo_active               = ?,
                qbo_income_account_id    = ?,
                qbo_income_account_name  = ?,
                qbo_expense_account_id   = ?,
                qbo_expense_account_name = ?,
                qbo_sync_token           = ?,
                last_pull_at             = NOW()
              WHERE qbo_item_id = ?",
            [
                $it['name'],
                $it['fully_qualified_name'],
                $it['description'],
                $it['type'],
                $it['active'] ? 1 : 0,
                $it['income_account_id']  !== '' ? $it['income_account_id']  : null,
                $it['income_account_name'] !== '' ? $it['income_account_name'] : null,
                $it['expense_account_id'] !== '' ? $it['expense_account_id'] : null,
                $it['expense_account_name'] !== '' ? $it['expense_account_name'] : null,
                $it['sync_token'],
                $it['qbo_id'],
            ]
        );

        if ($affected > 0) {
            $updatedRows++;
            continue;
        }

        // INSERT new qbo_only entry.
        db_insert('acc_qbo_item_map', [
            'qbo_item_id'              => $it['qbo_id'],
            'qbo_sync_token'           => $it['sync_token'],
            'qbo_name'                 => $it['name'],
            'qbo_fully_qualified_name' => $it['fully_qualified_name'],
            'qbo_description'          => $it['description'],
            'qbo_type'                 => $it['type'],
            'qbo_active'               => $it['active'] ? 1 : 0,
            'qbo_income_account_id'    => $it['income_account_id']  !== '' ? $it['income_account_id']  : null,
            'qbo_income_account_name'  => $it['income_account_name'] !== '' ? $it['income_account_name'] : null,
            'qbo_expense_account_id'   => $it['expense_account_id'] !== '' ? $it['expense_account_id'] : null,
            'qbo_expense_account_name' => $it['expense_account_name'] !== '' ? $it['expense_account_name'] : null,
            'mapping_status'           => 'qbo_only',
            'last_pull_at'             => date('Y-m-d H:i:s'),
            'created_by_user_id'       => $userId,
        ]);
        $insertedRows++;
    }

    // Ensure every FF item_type tuple has an ff_only row when no
    // mapping/ignore exists. This makes the UI's "unmapped FF item types"
    // count accurate.
    $tuples = ItemMatcher::ffItemTypes();
    foreach ($tuples as $tuple) {
        $exists = db_row(
            "SELECT id, mapping_status FROM acc_qbo_item_map
              WHERE ff_item_type = ?
                AND ((ff_item_type_variant IS NULL AND ? IS NULL)
                  OR ff_item_type_variant = ?)",
            [$tuple['ff_item_type'], $tuple['variant'], $tuple['variant']]
        );
        if ($exists !== null) {
            continue;
        }
        $isCredit     = ($tuple['ff_item_type'] === 'base_rental_reconciliation_credit') ? 1 : 0;
        $presentation = ($tuple['ff_item_type'] === 'gps' && $tuple['variant'] !== null)
                        ? $tuple['variant']
                        : null;
        db_insert('acc_qbo_item_map', [
            'ff_item_type'         => $tuple['ff_item_type'],
            'ff_item_type_variant' => $tuple['variant'],
            'mapping_status'       => 'ff_only',
            'is_credit_variant'    => $isCredit,
            'presentation_variant' => $presentation,
            'created_by_user_id'   => $userId,
        ]);
    }

    // Compute "missing FF→QBO" count for response.
    $missingFfToQbo = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_item_map WHERE mapping_status = 'ff_only'"
    );

    json_success([
        'pulled_count'     => count($items),
        'inserted'         => $insertedRows,
        'updated'          => $updatedRows,
        'ff_types_count'   => count($tuples),
        'missing_ff_to_qbo' => $missingFfToQbo,
    ]);

} catch (QuickBooksException $e) {
    json_error('QBO_PULL_FAILED', 'Item pull failed: ' . $e->getMessage(), 502);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Unexpected error during item pull: ' . $e->getMessage(), 500);
}
