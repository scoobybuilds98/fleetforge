<?php
declare(strict_types=1);

/**
 * FleetForge — Customers List API
 *
 * @file        api/v1/customers/index.php
 * @description Returns a paginated list of customers. Supports filtering by
 *              status and risk_score, full-text search, and sorting. Soft-deleted
 *              records are excluded. Dispatcher gets view+create only (no edit/delete).
 *
 * @method      GET
 * @params      search      (optional, string) — FULLTEXT search on company_name,
 *                          contact_name, email, dot_number, mc_number
 *              status      (optional) — active|inactive|pending|suspended|credit_hold
 *              risk_score  (optional) — low|medium|high
 *              sort        (optional) — company_name|created_at|status|risk_score
 *                          default: created_at DESC
 *              page        (optional, int ≥1, default 1)
 *              per_page    (optional, int 1–100, default 25)
 * @auth        Session required; require_permission('customers','view')
 * @returns     json_paginated — items array with customer rows + tags array
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

// ── Input ──────────────────────────────────────────────────────
$search    = clean_string($_GET['search'] ?? null, 255);
$status    = clean_string($_GET['status'] ?? null);
$riskScore = clean_string($_GET['risk_score'] ?? null);
$sort      = clean_string($_GET['sort'] ?? 'created_at');
$page      = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage   = min(100, max(1, clean_int($_GET['per_page'] ?? 25) ?? 25));

// ── Validate enum filters ──────────────────────────────────────
$validStatuses = ['active', 'inactive', 'pending', 'suspended', 'credit_hold'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    $status = null;
}

$validRiskScores = ['low', 'medium', 'high'];
if ($riskScore !== null && !in_array($riskScore, $validRiskScores, true)) {
    $riskScore = null;
}

// ── Sort ────────────────────────────────────────────────────────
// Allowlisted columns — never interpolate user input directly
$sortMap = [
    'company_name' => 'c.company_name ASC',
    'created_at'   => 'c.created_at DESC',
    'status'       => 'c.status ASC, c.company_name ASC',
    'risk_score'   => "FIELD(c.risk_score,'high','medium','low'), c.company_name ASC",
];
$orderBy = $sortMap[$sort] ?? 'c.created_at DESC';

// ── Build WHERE conditions ─────────────────────────────────────
$conditions = ['c.deleted_at IS NULL'];
$params     = [];

if ($search !== null && $search !== '') {
    // LIKE-based substring search — supports partial matches (e.g. "Smit" finds "Smith Trucking")
    $like         = '%' . $search . '%';
    $conditions[] = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.dot_number LIKE ? OR c.mc_number LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($status !== null) {
    $conditions[] = 'c.status = ?';
    $params[]     = $status;
}

if ($riskScore !== null) {
    $conditions[] = 'c.risk_score = ?';
    $params[]     = $riskScore;
}

$whereSQL = 'WHERE ' . implode(' AND ', $conditions);

// ── Count total ────────────────────────────────────────────────
$total = db_count(
    "SELECT COUNT(*) FROM customers c {$whereSQL}",
    $params
);

// ── Paginated query ────────────────────────────────────────────
$offset = ($page - 1) * $perPage;

$rows = db_select(
    "SELECT
        c.id,
        c.company_name,
        c.contact_name,
        c.email,
        c.phone,
        c.city,
        c.province,
        c.status,
        c.risk_score,
        c.currency,
        c.active_lease_count,
        c.lease_count,
        c.outstanding_balance,
        c.total_revenue,
        c.account_credit_balance,
        c.created_at,
        c.updated_at
       FROM customers c
      {$whereSQL}
      ORDER BY {$orderBy}
      LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Attach tags for each customer ─────────────────────────────
// WHY batch lookup: avoids N+1 queries — one query for all tags on this page
if (!empty($rows)) {
    $ids        = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $tagRows = db_select(
        "SELECT customer_id, tag FROM customer_tags WHERE customer_id IN ({$placeholders}) ORDER BY tag",
        $ids
    );

    // Group tags by customer_id
    $tagsByCustomer = [];
    foreach ($tagRows as $t) {
        $tagsByCustomer[(int) $t['customer_id']][] = $t['tag'];
    }
}

// ── Format items ───────────────────────────────────────────────
$items = [];
foreach ($rows as $row) {
    $cid     = (int) $row['id'];
    $items[] = [
        'id'                    => $cid,
        'company_name'          => $row['company_name'],
        'contact_name'          => $row['contact_name'],
        'email'                 => $row['email'],
        'phone'                 => $row['phone'],
        'city'                  => $row['city'],
        'province'              => $row['province'],
        'status'                => $row['status'],
        'risk_score'            => $row['risk_score'],
        'currency'              => $row['currency'],
        'active_lease_count'    => (int) $row['active_lease_count'],
        'lease_count'           => (int) $row['lease_count'],
        'outstanding_balance'   => $row['outstanding_balance'],
        'total_revenue'         => $row['total_revenue'],
        'account_credit_balance'=> $row['account_credit_balance'],
        'created_at'            => $row['created_at'],
        'updated_at'            => $row['updated_at'],
        'tags'                  => $tagsByCustomer[$cid] ?? [],
    ];
}

json_paginated($items, $total, $page, $perPage);
