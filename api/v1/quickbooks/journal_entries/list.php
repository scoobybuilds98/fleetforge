<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/journal_entries/list.php
 *
 * Paginated list of acc_qbo_journal_entry_map rows with FF JE +
 * source attribution + line count for the QBO Journal Entries admin page.
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-21
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = [
    'pending', 'pushed', 'voided', 'failed',
    'skipped_voided', 'skipped_unmapped_void', 'skipped_by_mode',
    'failed_preflight', 'failed_preflight_currency_mismatch',
    'failed_preflight_field_too_long',
];
$statusFilter = isset($_GET['status']) && $_GET['status'] !== ''
    ? array_values(array_intersect(explode(',', (string) $_GET['status']), $validStatuses))
    : [];

$where  = '1=1';
$params = [];
if (!empty($statusFilter)) {
    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
    $where .= " AND m.push_status IN ({$placeholders})";
    $params = array_merge($params, $statusFilter);
}

try {
    $kpiRows = db_select(
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_journal_entry_map GROUP BY push_status"
    );
    $kpis = [
        'pending'                            => 0,
        'pushed'                             => 0,
        'voided'                             => 0,
        'failed'                             => 0,
        'failed_preflight'                   => 0,
        'failed_preflight_currency_mismatch' => 0,
        'failed_preflight_field_too_long'    => 0,
        'skipped_voided'                     => 0,
        'skipped_by_mode'                    => 0,
        'bridge_derived_sync_log'            => 0,
    ];
    foreach ($kpiRows as $k) {
        if (isset($kpis[$k['push_status']])) {
            $kpis[$k['push_status']] = (int) $k['c'];
        }
    }

    // Bridge-derived skip count from sync_log (no map row pattern per D-QBO-21-1).
    $bridgeCount = db_row(
        "SELECT COUNT(*) AS c FROM acc_qbo_sync_log
          WHERE entity_type = 'journal_entry'
            AND error_code  = 'skipped_bridge_derived'"
    );
    $kpis['bridge_derived_sync_log'] = (int) ($bridgeCount['c'] ?? 0);

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_journal_entry_map m WHERE {$where}",
        $params
    );

    // Join journal_entries; aggregate line stats via subqueries.
    $rows = db_select(
        "SELECT m.id, m.ff_journal_entry_id, m.qbo_journal_entry_id, m.qbo_sync_token,
                m.qbo_doc_number, m.qbo_total_amt, m.qbo_currency, m.qbo_exchange_rate,
                m.qbo_txn_date, m.qbo_private_note,
                m.ff_je_snapshot_total,
                m.push_status, m.push_error, m.pushed_at, m.last_synced_at,
                je.entry_number, je.entry_date, je.entry_type,
                je.status AS ff_status, je.entry_status AS ff_entry_status,
                je.description AS ff_description,
                je.source_type, je.source_id,
                je.currency AS ff_currency, je.exchange_rate AS ff_exchange_rate,
                je.is_reversal, je.reversal_of_id,
                (SELECT COUNT(*) FROM acc_journal_entry_lines jl WHERE jl.journal_entry_id = je.id) AS line_count,
                (SELECT COUNT(*) FROM acc_journal_entry_lines jl WHERE jl.journal_entry_id = je.id AND jl.debit  > 0) AS debit_line_count,
                (SELECT COUNT(*) FROM acc_journal_entry_lines jl WHERE jl.journal_entry_id = je.id AND jl.credit > 0) AS credit_line_count,
                (SELECT SUM(jl.debit)  FROM acc_journal_entry_lines jl WHERE jl.journal_entry_id = je.id) AS ff_balanced_total
           FROM acc_qbo_journal_entry_map m
      LEFT JOIN acc_journal_entries je ON je.id = m.ff_journal_entry_id
          WHERE {$where}
          ORDER BY COALESCE(m.last_synced_at, m.created_at) DESC, m.id DESC
          LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    json_success([
        'rows'     => $rows,
        'kpis'     => $kpis,
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'List failed: ' . $e->getMessage(), 500);
}
