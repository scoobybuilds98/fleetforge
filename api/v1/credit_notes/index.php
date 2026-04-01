<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/index.php
 *
 * Paginated list of credit notes with optional filters and sort.
 * Supports filters: customer_id, status, currency, date range (created_at).
 * Sort allowlist prevents SQL injection (D security §15).
 *
 * SOFT_DELETE: credit_notes has deleted_at — always filter AND deleted_at IS NULL.
 *
 * @method  GET
 * @auth    Session required; require_permission('invoices','view')
 *          (Credit notes live under the invoices permission scope per §12 matrix)
 * @returns 200 json_paginated([credit_note rows], total, page, per_page)
 *
 * Decisions: D5/D16, §5 Soft Delete, §10 List Endpoint Pattern
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['cn.deleted_at IS NULL'];
$params = [];

if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'cn.customer_id = ?';
    $params[] = $customerId;
}

if ($status = clean_string($_GET['status'] ?? null)) {
    $validStatuses = ['active', 'partially_used', 'fully_used', 'expired', 'void'];
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'cn.status = ?';
        $params[] = $status;
    }
}

if ($currency = clean_string($_GET['currency'] ?? null)) {
    if (in_array(strtoupper($currency), ['CAD', 'USD'], true)) {
        $where[]  = 'cn.currency = ?';
        $params[] = strtoupper($currency);
    }
}

if ($source = clean_string($_GET['source'] ?? null)) {
    $validSources = ['mileage_overpayment', 'invoice_adjustment', 'damage_resolution', 'goodwill', 'payment_returned', 'other'];
    if (in_array($source, $validSources, true)) {
        $where[]  = 'cn.source = ?';
        $params[] = $source;
    }
}

if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'DATE(cn.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'DATE(cn.created_at) <= ?';
    $params[] = $dateTo;
}

// -----------------------------------------------------------------------
// 2. Sort (allowlisted — never from raw user input)
// -----------------------------------------------------------------------
$allowedSorts = ['created_at', 'amount', 'amount_remaining', 'status', 'credit_note_number'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Queries
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM credit_notes cn WHERE {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT
        cn.id,
        cn.credit_note_number,
        cn.customer_id,
        c.company_name AS customer_name,
        cn.lease_id,
        cn.source,
        cn.source_invoice_id,
        cn.source_payment_id,
        cn.amount,
        cn.currency,
        cn.amount_remaining,
        cn.status,
        cn.expires_at,
        cn.reason,
        cn.created_by,
        cn.created_at,
        cn.updated_at
     FROM credit_notes cn
     LEFT JOIN customers c ON c.id = cn.customer_id AND c.deleted_at IS NULL
     WHERE {$whereSQL}
     ORDER BY cn.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
