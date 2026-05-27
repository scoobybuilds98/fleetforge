<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bills/list.php
 *
 * Paginated list of acc_qbo_bill_map rows with FF bill + vendor
 * snapshots for the QBO Bills admin page. Read-only — bill push
 * itself happens via the worker; this endpoint just surfaces state.
 *
 * Filters:
 *   - status: comma-separated push_status whitelist (default: all)
 *   - page, per_page: standard pagination
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-BILL-SYNC-UI (follow-up to S-QBO-18)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 (visibility surface for Pusher state)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

// Status filter — whitelist against actual ENUM values per S-QBO-18 migration.
// Bills don't have failed_preflight_currency_mismatch or
// failed_preflight_field_too_long (no per-customer mismatch context for bills;
// gate 7 fails into generic failed_preflight in v1 — can be split into typed
// states later if operator demands sub-classification).
$validStatuses = [
    'pending', 'pushed', 'voided', 'failed',
    'skipped_voided', 'skipped_unmapped_void', 'skipped_by_mode',
    'failed_preflight',
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
    // KPI rollup across all statuses (no filter applied here so the
    // operator always sees the full breakdown).
    $kpiRows = db_select(
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_bill_map GROUP BY push_status"
    );
    $kpis = [
        'pending'               => 0,
        'pushed'                => 0,
        'voided'                => 0,
        'failed'                => 0,
        'failed_preflight'      => 0,
        'skipped_voided'        => 0,
        'skipped_unmapped_void' => 0,
        'skipped_by_mode'       => 0,
    ];
    foreach ($kpiRows as $k) {
        $kpis[$k['push_status']] = (int) $k['c'];
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_bill_map m WHERE {$where}",
        $params
    );

    // Bills don't have company_name_snapshot like invoices do — JOIN vendors
    // for the display name. vendors table has `name` (not `company_name`).
    $rows = db_select(
        "SELECT m.id, m.ff_bill_id, m.qbo_bill_id, m.qbo_doc_number,
                m.qbo_vendor_id, m.qbo_total_amt, m.qbo_balance, m.qbo_status,
                m.qbo_currency, m.qbo_exchange_rate, m.ff_bill_snapshot_total,
                m.push_status, m.push_error, m.pushed_at, m.last_synced_at,
                b.bill_number, b.vendor_bill_number, b.vendor_id,
                b.bill_date, b.due_date, b.total_amount, b.balance_due,
                b.currency AS ff_currency, b.status AS ff_status,
                v.name AS vendor_name
           FROM acc_qbo_bill_map m
      LEFT JOIN acc_bills b ON b.id = m.ff_bill_id
      LEFT JOIN vendors v ON v.id = b.vendor_id
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
