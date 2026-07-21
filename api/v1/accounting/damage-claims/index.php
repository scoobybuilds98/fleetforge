<?php
declare(strict_types=1);

/**
 * api/v1/accounting/damage-claims/index.php
 *
 * Damage claims accounting subledger per spec §23.11. Reports
 * per-claim repair cost, recovery billed/collected, net P&L impact,
 * and JE links (damage_recovery / damage_repair / damage_writeoff).
 *
 * @method  GET
 * @query   period_start (YYYY-MM-DD, optional — defaults to YTD),
 *          period_end   (YYYY-MM-DD, optional — defaults to today),
 *          status       (optional — filter to specific damage_claims.status)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { claims: [...], totals: { total_repair_cost,
 *                total_recovery_billed, total_recovery_collected,
 *                total_net_pnl, claim_count } }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.11
 * Session: S-ACCT-DMG
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$from = clean_string($_GET['period_start'] ?? '', 10);
$to   = clean_string($_GET['period_end']   ?? '', 10);
$statusFilter = clean_string($_GET['status'] ?? null);

if ($from === '') $from = date('Y') . '-01-01';
if ($to === '')   $to   = date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    json_error('VALIDATION_ERROR', 'period_start must be YYYY-MM-DD.', 422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    json_error('VALIDATION_ERROR', 'period_end must be YYYY-MM-DD.', 422);
}

// Pull claims in window, with customer / unit / template / invoice / repair-bill
// joins. K-22 catches: customers.company_name (not name); equipment_templates
// model via template_id FK, but brand now lives on the UNIT
// (equipment_units.brand_id -> equipment_brands, S-UNIT-BRAND); the
// template_brand output alias is preserved for downstream consumers;
// damage_claims status ENUM has 'invoiced' not
// 'billed_to_customer'; invoices uses total_amount + balance_due (not 'total').
$whereExtra = '';
$params = [$from, $to];
if ($statusFilter !== null && $statusFilter !== '') {
    $whereExtra = ' AND dc.status = ?';
    $params[]   = $statusFilter;
}

$claims = db_select(
    "SELECT dc.id, dc.claim_number, dc.created_at AS claim_date,
            dc.status, dc.severity, dc.description,
            dc.estimated_repair_cost, dc.actual_repair_cost,
            dc.customer_liable_amount, dc.invoice_id,
            c.id AS customer_id, c.company_name,
            u.unit_number, u.year AS unit_year,
            eb.label AS template_brand, t.model AS template_model,
            i.invoice_number, i.total_amount AS invoice_total, i.balance_due AS invoice_balance,
            (i.total_amount - i.balance_due) AS invoice_collected
       FROM damage_claims dc
       LEFT JOIN customers c ON c.id = dc.customer_id
       LEFT JOIN equipment_units u ON u.id = dc.equipment_unit_id
       LEFT JOIN equipment_templates t ON t.id = u.template_id
       LEFT JOIN equipment_brands eb ON eb.id = u.brand_id
       LEFT JOIN invoices i ON i.id = dc.invoice_id AND i.deleted_at IS NULL
      WHERE dc.deleted_at IS NULL
        AND dc.created_at BETWEEN ? AND ?
        {$whereExtra}
      ORDER BY dc.created_at DESC",
    array_merge([$from . ' 00:00:00', $to . ' 23:59:59'], $statusFilter !== null && $statusFilter !== '' ? [$statusFilter] : [])
);

if (!$claims) {
    json_success([
        'period_start' => $from,
        'period_end'   => $to,
        'claims'       => [],
        'totals'       => [
            'total_repair_cost'        => '0.00',
            'total_recovery_billed'    => '0.00',
            'total_recovery_collected' => '0.00',
            'total_net_pnl'            => '0.00',
            'claim_count'              => 0,
        ],
    ]);
}

$claimIds = array_map(static fn($c) => (int) $c['id'], $claims);
$placeholders = implode(',', array_fill(0, count($claimIds), '?'));

// Pull JE links for all claims in one query
$jeRows = db_select(
    "SELECT id, source_type, source_id, entry_number, entry_date, status
       FROM acc_journal_entries
      WHERE source_type IN ('damage_recovery','damage_repair','damage_writeoff')
        AND source_id IN ({$placeholders})",
    $claimIds
);

$jeByClaim = [];
foreach ($jeRows as $je) {
    $cid = (int) $je['source_id'];
    $jeByClaim[$cid][$je['source_type']] = [
        'je_id'           => (int) $je['id'],
        'entry_number'    => $je['entry_number'],
        'entry_date'      => $je['entry_date'],
        'status'          => $je['status'],
    ];
}

$totalRepair    = '0.00';
$totalBilled    = '0.00';
$totalCollected = '0.00';
$totalNet       = '0.00';
$out = [];

foreach ($claims as $c) {
    $cid = (int) $c['id'];

    // Repair cost preference: actual > estimated > 0
    $repair = (string) ($c['actual_repair_cost'] ?? '');
    if ($repair === '' || $repair === null) $repair = (string) ($c['estimated_repair_cost'] ?? '0.00');
    if ($repair === '') $repair = '0.00';

    $billed    = (string) ($c['invoice_total']     ?? '0.00');
    $collected = (string) ($c['invoice_collected'] ?? '0.00');
    if ($billed === '' || $billed === null)    $billed = '0.00';
    if ($collected === '' || $collected === null) $collected = '0.00';

    $netPnl = bcsub($collected, $repair, 2);

    $totalRepair    = bcadd($totalRepair, $repair, 2);
    $totalBilled    = bcadd($totalBilled, $billed, 2);
    $totalCollected = bcadd($totalCollected, $collected, 2);
    $totalNet       = bcadd($totalNet, $netPnl, 2);

    $displayLabel = trim(sprintf(
        '%s %s %s',
        $c['unit_year'] ?? '',
        $c['template_brand'] ?? '',
        $c['template_model'] ?? ''
    ));

    $out[] = [
        'claim_id'           => $cid,
        'claim_number'       => $c['claim_number'],
        'claim_date'         => $c['claim_date'],
        'status'             => $c['status'],
        'severity'           => $c['severity'],
        'customer_id'        => $c['customer_id'] ? (int) $c['customer_id'] : null,
        'customer_name'      => $c['company_name'],
        'unit_number'        => $c['unit_number'],
        'unit_label'         => $displayLabel ?: ($c['unit_number'] ?? ''),
        'description'        => $c['description'],
        'repair_cost'        => $repair,
        'recovery_billed'    => $billed,
        'recovery_collected' => $collected,
        'recovery_balance'   => (string) ($c['invoice_balance'] ?? '0.00'),
        'net_pnl_impact'     => $netPnl,
        'invoice_id'         => $c['invoice_id'] ? (int) $c['invoice_id'] : null,
        'invoice_number'     => $c['invoice_number'],
        'je_links'           => [
            'damage_recovery'  => $jeByClaim[$cid]['damage_recovery']  ?? null,
            'damage_repair'    => $jeByClaim[$cid]['damage_repair']    ?? null,
            'damage_writeoff'  => $jeByClaim[$cid]['damage_writeoff']  ?? null,
        ],
    ];
}

json_success([
    'period_start' => $from,
    'period_end'   => $to,
    'claims'       => $out,
    'totals'       => [
        'total_repair_cost'        => $totalRepair,
        'total_recovery_billed'    => $totalBilled,
        'total_recovery_collected' => $totalCollected,
        'total_net_pnl'            => $totalNet,
        'claim_count'              => count($out),
    ],
]);
