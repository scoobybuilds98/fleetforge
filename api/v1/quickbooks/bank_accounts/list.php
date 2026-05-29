<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/list.php
 *
 * List query for the Bank Account Mapping admin page (S-QBO-20).
 * Returns one row per FF acc_bank_accounts row joined to the
 * acc_qbo_bank_account_map state, plus KPI counters.
 *
 * KPIs:
 *   mapped       — count of FF rows with mapping_status='mapped'
 *   unmapped     — count of FF rows with no map row OR mapping_status='unmapped'
 *   conflict     — count of FF rows with mapping_status='conflict' (drift)
 *   mirror_rows  — count of acc_bank_transactions rows with source='qbo_cdc'
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @session S-QBO-20
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

try {
    $rows = db_select(
        "SELECT ba.id                       AS ff_bank_account_id,
                ba.name                     AS ff_name,
                ba.account_type             AS ff_account_type,
                ba.currency                 AS ff_currency,
                ba.account_number_last4     AS ff_last4,
                ba.institution              AS ff_institution,
                ba.is_active                AS ff_is_active,
                m.id                        AS mapping_id,
                m.qbo_bank_account_id,
                m.qbo_account_name_snapshot AS qbo_name_snapshot,
                m.qbo_currency_snapshot,
                m.qbo_active_snapshot,
                m.qbo_account_type_snapshot,
                COALESCE(m.mapping_status, 'unmapped') AS mapping_status,
                m.last_synced_at,
                m.mapped_at
           FROM acc_bank_accounts ba
      LEFT JOIN acc_qbo_bank_account_map m ON m.ff_bank_account_id = ba.id
          WHERE ba.is_active = 1
       ORDER BY ba.is_default DESC, ba.name ASC"
    );

    // KPI roll-up. mapped/unmapped/conflict come from the same SELECT;
    // mirror_rows is the read-only CDC mirror count for context.
    $kpis = ['mapped' => 0, 'unmapped' => 0, 'conflict' => 0, 'mirror_rows' => 0];
    foreach ($rows as $r) {
        $status = (string) $r['mapping_status'];
        if (!isset($kpis[$status])) {
            $kpis[$status] = 0;
        }
        $kpis[$status]++;
    }
    $mirrorRow = db_row(
        "SELECT COUNT(*) AS n FROM acc_bank_transactions WHERE source = 'qbo_cdc'"
    );
    $kpis['mirror_rows'] = (int) ($mirrorRow['n'] ?? 0);

    json_success([
        'rows'             => $rows,
        'kpis'             => $kpis,
        'last_bank_cdc_at' => (string) settings_get('quickbooks.banking.last_bank_cdc_at', ''),
        'last_pulled_at'   => (string) settings_get('quickbooks.banking.last_bank_account_pull_at', ''),
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'List failed: ' . $e->getMessage(), 500);
}
