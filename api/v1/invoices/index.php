<?php
declare(strict_types=1);

/**
 * api/v1/invoices/index.php
 *
 * Paginated invoice list with filters for status, customer_id, lease_id,
 * and FULLTEXT search on invoice_number + company_name_snapshot.
 * Snapshot columns enable zero-join list queries (PASS-5:1E).
 *
 * @method  GET
 * @query   status, customer_id, lease_id, q (search), sort, dir, page, per_page
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 paginated list
 *
 * Decisions: D5 (soft-delete), D32 (Trap 7: pdf_path stripped)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

// Allowlisted sort columns
$allowedSorts = [
    'created_at', 'invoice_date', 'due_date', 'total_amount', 'balance_due',
    'status', 'invoice_number',
    // Extended sorts
    'company_name_snapshot', 'updated_at',
];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// Build WHERE from allowlisted filters
$where = ['i.deleted_at IS NULL'];
$params = [];

// Allowed invoice statuses (mirrors the DB enum). Used to validate both the
// single `status` filter and the multi-status `statuses` scope below.
$allowedInvoiceStatuses = ['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'void', 'written_off'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $allowedInvoiceStatuses, true)) {
        $where[] = 'i.status = ?';
        $params[] = $status;
    }
} elseif ($statusesRaw = clean_string($_GET['statuses'] ?? null)) {
    // Multi-status scope (statuses=draft,sent,partially_paid,overdue) — lets the
    // Outstanding/Paid tabs paginate server-side instead of over-fetching one
    // page and filtering client-side (which made page counts wrong and could
    // render a page with zero matching rows). Validated against the allowlist;
    // the single `status` param wins when both are supplied.
    $list = array_values(array_intersect(
        array_map('trim', explode(',', $statusesRaw)),
        $allowedInvoiceStatuses
    ));
    if ($list) {
        $where[] = 'i.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
        array_push($params, ...$list);
    }
}
if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[] = 'i.customer_id = ?';
    $params[] = $customerId;
}
if ($leaseId = clean_int($_GET['lease_id'] ?? null)) {
    $where[] = 'i.lease_id = ?';
    $params[] = $leaseId;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $like    = '%' . $search . '%';
    $where[] = '(i.invoice_number LIKE ? OR i.company_name_snapshot LIKE ?)';
    array_push($params, $like, $like);
}

// customer_filter — contextual sub-filter shown when sorting by customer name.
if ($customerFilter = clean_string($_GET['customer_filter'] ?? null)) {
    $like    = '%' . $customerFilter . '%';
    $where[] = 'i.company_name_snapshot LIKE ?';
    $params[] = $like;
}

// TILES-1: AR-aging bucket filter used by the invoice-list KPI tiles.
// The tile click sets aging=current|ar30|ar60|ar90 which translates to
// a due_date range + an outstanding-status constraint so the list shows
// only the invoices contributing to that bucket.
//
// Buckets mirror the aggregates in app/admin/invoices/index.php:
//   current → status='sent'  AND due_date >= today
//   ar30    → status IN ('sent','overdue') AND today-30d <= due_date < today
//   ar60    → status IN ('sent','overdue') AND today-60d <= due_date < today-30d
//   ar90    → status IN ('sent','overdue') AND due_date < today-60d
$aging = clean_string($_GET['aging'] ?? null);
if ($aging !== null && $aging !== '') {
    switch ($aging) {
        case 'current':
            $where[] = "i.status = 'sent'";
            $where[] = "i.due_date >= CURDATE()";
            break;
        case 'ar30':
            $where[] = "i.status IN ('sent','overdue')";
            $where[] = "i.due_date <  CURDATE()";
            $where[] = "i.due_date >= CURDATE() - INTERVAL 30 DAY";
            break;
        case 'ar60':
            $where[] = "i.status IN ('sent','overdue')";
            $where[] = "i.due_date <  CURDATE() - INTERVAL 30 DAY";
            $where[] = "i.due_date >= CURDATE() - INTERVAL 60 DAY";
            break;
        case 'ar90':
            $where[] = "i.status IN ('sent','overdue')";
            $where[] = "i.due_date <  CURDATE() - INTERVAL 60 DAY";
            break;
        // any other value silently ignored — keeps the filter forgiving
    }
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

// S-QBO-INVOICE-LIST-BADGE: project the QBO mapping row alongside each
// invoice when QBO is connected, so the index page can render a per-row
// push-status badge. JOIN omitted entirely when disconnected to keep the
// query cost minimal. Aliased columns match the UI's Alpine fields.
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboSelect    = $qboConnected ? ", m.push_status AS qbo_push_status, m.qbo_invoice_id AS qbo_invoice_id" : "";
$qboJoin      = $qboConnected ? " LEFT JOIN acc_qbo_invoice_map m ON m.ff_invoice_id = i.id" : "";

$total = db_count("SELECT COUNT(*) FROM invoices i WHERE {$whereSQL}", $params);
$rows = db_select(
    "SELECT
        i.id,
        i.invoice_number,
        i.invoice_type,
        i.customer_id,
        i.lease_id,
        i.company_name_snapshot,
        i.contract_number_snapshot,
        i.unit_number_invoice_snapshot,
        i.status,
        i.currency,
        i.billing_period_start,
        i.billing_period_end,
        i.billing_period_days,
        i.billing_type,
        i.invoice_date,
        i.due_date,
        i.paid_date,
        i.sent_date,
        i.subtotal,
        i.discount_amount,
        i.subtotal_after_discount,
        i.tax_total,
        i.total_amount,
        i.amount_paid,
        i.credits_applied,
        i.balance_due,
        i.auto_generated,
        i.created_at,
        i.updated_at,
        l.actual_return_date,
        l.actual_return_time,
        l.start_time,
        l.billing_days_removed
        {$qboSelect}
     FROM invoices i
     LEFT JOIN leases l ON l.id = i.lease_id
     {$qboJoin}
     WHERE {$whereSQL}
     ORDER BY i.{$sort} {$dir}, i.id {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// S-LEASE-CLOSE-ACTUAL-DATE: surface the ACTUAL rental end (real return date)
// for DISPLAY when the return-day-not-billed time-of-day rule trimmed the final
// day. billing_period_end stays the trimmed billed extent (coverage / overshoot
// math, S-CLOSE-OVERSHOOT); display_period_end is what the list + the lease- and
// customer-detail invoice tables render. The four joined lease fields are helper
// inputs only — strip them from the payload (Trap 7 spirit: don't leak lease
// internals on the invoice list).
foreach ($rows as &$_r) {
    $_r['display_period_end'] = ff_invoice_display_period_end($_r);
    unset($_r['actual_return_date'], $_r['actual_return_time'], $_r['start_time'], $_r['billing_days_removed']);
}
unset($_r);

// I03: dispatchers (invoices:view, payments:NONE) get the operational list —
// invoice_number, status, dates, customer/unit — but NOT amounts. config/
// permissions.php documents this "status + dates only — no amounts (enforced in
// API)" contract; it was never implemented. Mirrors customers/leases redaction.
if (!can_view_financials()) {
    $rows = redact_rows($rows, [
        'subtotal', 'discount_amount', 'subtotal_after_discount', 'tax_total',
        'total_amount', 'amount_paid', 'credits_applied', 'balance_due',
    ]);
}

json_paginated($rows, $total, $page, $perPage);
