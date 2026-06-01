<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/fixed_asset_map_sync.php
 *
 * Operator-initiated "Refresh fixed-asset reference map" — populates/refreshes
 * acc_qbo_fixed_asset_map for every FF fixed asset (resolves each asset's QBO
 * GL-account refs + cost/depr snapshots + sync_status). Idempotent.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'edit_credentials')
 * @returns 200 { total, synced, pending, errors }
 *
 * @session  S-QBO-FA-MAP (operator-directed)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.13
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

try {
    $stats = \FleetForge\QboPushers\FixedAssetMapSync::sync();

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'manual_trigger',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_fixed_asset_map_sync',
        'entity_label' => 'Fixed-asset reference map refresh',
        'notes'        => 'Operator refreshed acc_qbo_fixed_asset_map. '
            . 'total=' . (int) ($stats['total'] ?? 0)
            . ' synced=' . (int) ($stats['synced'] ?? 0)
            . ' pending=' . (int) ($stats['pending'] ?? 0)
            . ' errors=' . (int) ($stats['errors'] ?? 0),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success($stats);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'FA reference map sync failed: ' . $e->getMessage(), 500);
}
