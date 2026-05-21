<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/items/create_qbo_item.php
 *
 * Operator-confirmed QBO Item authoring (D-QBO-10-4). For an FF
 * (item_type, variant) tuple that has no QBO Item counterpart, this
 * endpoint authors a new QBO Item via ItemCreator::createMissingItem
 * and upserts the resulting Id into acc_qbo_item_map with
 * match_confidence='auto_created'.
 *
 * Gate: quickbooks.edit_credentials (D-QBO-10-4). Higher than view
 * because this WRITES to QBO — every successful call provisions a
 * persistent QBO Item that will appear in the operator's QBO company.
 * Read-only roles see the button hidden in the UI; this gate is the
 * server-side enforcement.
 *
 * Per ChartOfAccountsIncompleteException semantics, if no revenue
 * account is mapped in acc_qbo_account_map (S-QBO-8), the endpoint
 * returns 422 with a structured error pointing the operator back to
 * the chart-of-accounts mapping flow.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @body    JSON: { ff_item_type, ff_item_type_variant?, override_income_account_id? }
 * @returns 200 { success: true, qbo_id, qbo_name, mapping_id }
 *          422 { success: false, error: { code, message } } on COA-incomplete
 *
 * Spec ref: §7.3
 * Session:  S-QBO-10
 * Decision: D-QBO-10-3 (createEntity via QuickBooksClient),
 *           D-QBO-10-4 (operator confirmation gate)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

use FleetForge\QboPushers\ItemCreator;
use FleetForge\QboPushers\ItemMatcher;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;
use FleetForge\Exceptions\QuickBooksException;

$body         = json_body();
$ffItemType   = isset($body['ff_item_type']) && $body['ff_item_type'] !== ''
                ? (string) $body['ff_item_type']
                : null;
$ffVariant    = isset($body['ff_item_type_variant']) && $body['ff_item_type_variant'] !== ''
                ? (string) $body['ff_item_type_variant']
                : null;
$overrideAcct = isset($body['override_income_account_id']) && $body['override_income_account_id'] !== ''
                ? (string) $body['override_income_account_id']
                : null;

if ($ffItemType === null) {
    json_error('VALIDATION_ERROR', 'ff_item_type is required', 422);
}

// Validate tuple is in the canonical ENUM list.
$validTuples = ItemMatcher::ffItemTypes();
$found = false;
foreach ($validTuples as $t) {
    if ($t['ff_item_type'] === $ffItemType && (($t['variant'] === $ffVariant) || ($t['variant'] === null && $ffVariant === null))) {
        $found = true;
        break;
    }
}
if (!$found) {
    json_error('VALIDATION_ERROR', "Unknown (ff_item_type, variant) tuple: ({$ffItemType}, " . ($ffVariant ?? 'null') . ")", 422);
}

$userId = current_user_id();

try {
    $result = ItemCreator::createMissingItem($ffItemType, $ffVariant, $overrideAcct);

    if (!$result['success']) {
        json_error('QBO_CREATE_FAILED', 'QBO Item creation failed: ' . (string) $result['error'], 502);
    }

    $now          = date('Y-m-d H:i:s');
    $isCredit     = ($ffItemType === 'base_rental_reconciliation_credit') ? 1 : 0;
    $presentation = ($ffItemType === 'gps' && $ffVariant !== null) ? $ffVariant : null;

    // Drop any pre-existing ff_only row for this tuple.
    db_execute(
        "DELETE FROM acc_qbo_item_map
          WHERE ff_item_type = ?
            AND ((ff_item_type_variant IS NULL AND ? IS NULL)
              OR ff_item_type_variant = ?)
            AND qbo_item_id IS NULL",
        [$ffItemType, $ffVariant, $ffVariant]
    );

    // Insert the mapped row with auto_created provenance.
    $mappingId = db_insert('acc_qbo_item_map', [
        'ff_item_type'            => $ffItemType,
        'ff_item_type_variant'    => $ffVariant,
        'qbo_item_id'             => $result['qbo_id'],
        'qbo_sync_token'          => $result['sync_token'],
        'qbo_name'                => $result['qbo_name'],
        'qbo_type'                => 'Service',
        'qbo_active'              => 1,
        'qbo_income_account_id'   => $result['income_account_id'],
        'qbo_income_account_name' => $result['income_account_name'],
        'mapping_status'          => 'mapped',
        'match_confidence'        => 'auto_created',
        'is_credit_variant'       => $isCredit,
        'presentation_variant'    => $presentation,
        'last_synced_at'          => $now,
        'last_pull_at'            => $now,
        'created_by_user_id'      => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_item',
        'entity_id'    => (int) $mappingId,
        'entity_label' => 'QBO Item ' . (string) $result['qbo_id'],
        'notes'        => "Authored QBO Item '{$result['qbo_name']}' (Id={$result['qbo_id']}) "
                        . "for FF tuple ({$ffItemType}" . ($ffVariant !== null ? ", {$ffVariant}" : '') . "); "
                        . "IncomeAccountRef=" . (string) $result['income_account_id'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'qbo_id'              => $result['qbo_id'],
        'qbo_name'            => $result['qbo_name'],
        'mapping_id'          => $mappingId,
        'income_account_id'   => $result['income_account_id'],
        'income_account_name' => $result['income_account_name'],
    ]);

} catch (ChartOfAccountsIncompleteException $e) {
    json_error('COA_INCOMPLETE', $e->getMessage(), 422);
} catch (QuickBooksException $e) {
    json_error('QBO_CREATE_FAILED', 'QBO Item creation failed: ' . $e->getMessage(), 502);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Unexpected error during Item creation: ' . $e->getMessage(), 500);
}
