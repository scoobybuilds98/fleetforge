<?php declare(strict_types=1);

/**
 * api/v1/accounting/journal_entries/index.php
 *
 * Paginated journal entry listing with filters for status, type, period,
 * date range, and free-text search across entry_number, description, and reference.
 *
 * @method  GET
 * @query   status, type, period_id, date_from, date_to, search, sort, dir, page, per_page
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated list via json_paginated()
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

// --- Allowlisted sort columns ---
$allowedSorts = ['entry_date', 'entry_number', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'entry_date';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// --- Build WHERE from allowlisted filters ---
$where  = [];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[]  = 'je.status = ?';
    $params[] = $status;
}

if ($type = clean_string($_GET['type'] ?? null)) {
    $where[]  = 'je.entry_type = ?';
    $params[] = $type;
}

if ($periodId = clean_int($_GET['period_id'] ?? null)) {
    $where[]  = 'je.period_id = ?';
    $params[] = $periodId;
}

if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[]  = 'je.entry_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[]  = 'je.entry_date <= ?';
    $params[] = $dateTo;
}

// WHY: Search across entry_number, description, and reference — all short text fields
if ($search = clean_string($_GET['search'] ?? null)) {
    $where[]  = '(je.entry_number LIKE ? OR je.description LIKE ? OR je.reference LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset   = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_journal_entries je {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT
        je.id,
        je.entry_number,
        je.period_id,
        p.name AS period_name,
        je.entry_date,
        je.entry_type,
        je.status,
        je.description,
        je.reference,
        je.source_type,
        je.source_id,
        je.is_reversal,
        je.reversal_of_id,
        je.reversed_by_id,
        je.posted_by,
        je.posted_at,
        je.created_by,
        je.created_at
     FROM acc_journal_entries je
     LEFT JOIN acc_periods p ON p.id = je.period_id
     {$whereSQL}
     ORDER BY je.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
