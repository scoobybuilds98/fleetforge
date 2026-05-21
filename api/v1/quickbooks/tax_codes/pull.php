<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/tax_codes/pull.php
 *
 * Pull all QBO tax codes and upsert into acc_qbo_tax_code_map.
 * User-initiated from the Tax Codes Mapping page.
 *
 * The CRITICAL post-pull step per D-QBO-9-2: identify the 'NON'
 * override target via TaxCodeMatcher::identifyOverrideTarget and
 * auto-wire it to settings.quickbooks.tax_override_code_id. This
 * setting becomes the load-bearing piece for S-QBO-11 invoice push.
 *
 * If 'NON' is not found in the pulled list (very unusual — Intuit
 * seeds it by default in every QBO company), the response surfaces
 * override_resolved=false so the UI can render a red error banner.
 * Mapping is incomplete; S-QBO-11 will be blocked until resolved.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — read-only against QBO
 * @returns 200 { success: true, pulled_count, inserted, updated,
 *                total_in_qbo, override_target_id, override_target_name,
 *                override_resolved: bool }
 *
 * Spec ref: §7.2 (tax code mapping table), §6.6 (tax-override pattern)
 * Session:  S-QBO-9
 * Decision: D-QBO-9-2 (NON identification + auto-wire to settings)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\TaxCodePuller;
use FleetForge\QboPushers\TaxCodeMatcher;
use FleetForge\Exceptions\QuickBooksException;

try {
    $codes        = TaxCodePuller::pullAll();
    $userId       = current_user_id();
    $insertedRows = 0;
    $updatedRows  = 0;

    foreach ($codes as $tc) {
        $salesRefsJson = json_encode($tc['sales_rate_refs']) ?: null;

        // UPDATE existing qbo_tax_code_id row — preserves
        // mapping_status, match_confidence, is_override_target,
        // ff link.
        $affected = db_execute(
            "UPDATE acc_qbo_tax_code_map SET
                qbo_name             = ?,
                qbo_description      = ?,
                qbo_taxable          = ?,
                qbo_hidden           = ?,
                qbo_active           = ?,
                qbo_tax_group        = ?,
                qbo_sales_rate_refs  = ?,
                qbo_sync_token       = ?,
                last_pull_at         = NOW()
              WHERE qbo_tax_code_id = ?",
            [
                $tc['name'],
                $tc['description'],
                $tc['taxable']   ? 1 : 0,
                $tc['hidden']    ? 1 : 0,
                $tc['active']    ? 1 : 0,
                $tc['tax_group'] ? 1 : 0,
                $salesRefsJson,
                $tc['sync_token'],
                $tc['qbo_id'],
            ]
        );

        if ($affected > 0) {
            $updatedRows++;
            continue;
        }

        // INSERT new qbo_only entry.
        db_insert('acc_qbo_tax_code_map', [
            'qbo_tax_code_id'      => $tc['qbo_id'],
            'qbo_sync_token'       => $tc['sync_token'],
            'qbo_name'             => $tc['name'],
            'qbo_description'      => $tc['description'],
            'qbo_taxable'          => $tc['taxable']   ? 1 : 0,
            'qbo_hidden'           => $tc['hidden']    ? 1 : 0,
            'qbo_active'           => $tc['active']    ? 1 : 0,
            'qbo_tax_group'        => $tc['tax_group'] ? 1 : 0,
            'qbo_sales_rate_refs'  => $salesRefsJson,
            'mapping_status'       => 'qbo_only',
            'last_pull_at'         => date('Y-m-d H:i:s'),
            'created_by_user_id'   => $userId,
        ]);
        $insertedRows++;
    }

    // ── D-QBO-9-2: identify + auto-wire override target ──
    $overrideTarget    = TaxCodeMatcher::identifyOverrideTarget($codes);
    $overrideResolved  = $overrideTarget !== null;
    $overrideTargetId  = $overrideResolved ? (string) $overrideTarget['qbo_id'] : null;
    $overrideTargetName = $overrideResolved ? (string) $overrideTarget['name']  : null;

    if ($overrideResolved) {
        // Transaction: clear any prior is_override_target=1 row, set
        // the new one. UNIQUE constraint on is_override_target enforces
        // single-1 (rest are NULL); the two-step clear-then-set avoids
        // the UNIQUE violation that would fire if both rows briefly
        // held value 1.
        db_transaction(function () use ($overrideTargetId) {
            db_execute(
                "UPDATE acc_qbo_tax_code_map SET is_override_target = NULL
                  WHERE is_override_target = 1"
            );
            db_execute(
                "UPDATE acc_qbo_tax_code_map SET is_override_target = 1
                  WHERE qbo_tax_code_id = ?",
                [$overrideTargetId]
            );
        });

        // Wire to settings — the load-bearing piece for S-QBO-11.
        db_execute(
            "INSERT INTO settings (`key`, `value`, is_public, is_sensitive)
             VALUES ('quickbooks.tax_override_code_id', ?, 0, 0)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$overrideTargetId]
        );

        // Audit log entry.
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'quickbooks',
            'entity_type'  => 'qbo_tax_override_target',
            'entity_id'    => 0,
            'entity_label' => 'Override target',
            'notes'        => "Override target identified: QBO TaxCode {$overrideTargetId} \"{$overrideTargetName}\"",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } else {
        // No NON found — log to error_log + audit entry. UI banner
        // will render based on override_resolved=false in the response.
        error_log(
            "[S-QBO-9 pull] CRITICAL: No 'NON' TaxCode found in QBO sandbox. "
            . "S-QBO-11 invoice push will be blocked until resolved. "
            . "Pulled count={" . count($codes) . "}."
        );
    }

    json_success([
        'pulled_count'         => count($codes),
        'inserted'             => $insertedRows,
        'updated'              => $updatedRows,
        'total_in_qbo'         => count($codes),
        'override_target_id'   => $overrideTargetId,
        'override_target_name' => $overrideTargetName,
        'override_resolved'    => $overrideResolved,
    ]);

} catch (QuickBooksException $e) {
    json_error('QBO_PULL_FAILED', 'Tax code pull failed: ' . $e->getMessage(), 502);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Unexpected error during tax code pull: ' . $e->getMessage(), 500);
}
