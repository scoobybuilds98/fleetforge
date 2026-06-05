<?php
declare(strict_types=1);

/**
 * api/v1/credit_applications/all.php
 *
 * Credit Applications — global list API (all customers).
 *
 * Drives the Credit Applications module index page (app/admin/credit_applications/index.php).
 * Returns a paginated, filterable, sortable list of every credit application across all
 * customers, plus global KPI counts.
 *
 * Trap 7: NEVER emits token_hash, signature_path, form_data, rendered_html, or submitted_ip.
 *
 * @method      GET
 * @params      q           (optional) — search company_name / applicant first/last name
 *              status      (optional) — sent|opened|submitted|reviewed
 *              outcome     (optional) — approved|declined|needs_info
 *              date_from   (optional, Y-m-d) — filter by created_at
 *              date_to     (optional, Y-m-d) — filter by created_at
 *              sort        (optional) — sent_at|submitted_at|reviewed_at|created_at|company_name|status
 *              dir         (optional) — ASC|DESC (default DESC)
 *              page        (optional, int ≥1, default 1)
 *              per_page    (optional, int 1–100, default 25)
 * @auth        Session required; require_permission('customers','view')
 * @returns     json_success with { items, pagination, kpis }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7 Credit Applications
 * @session     S-CCA-MODULE
 */

// dirname(__DIR__, 3): api/v1/credit_applications/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

// ── Input ─────────────────────────────────────────────────────────────────────
$q          = clean_string($_GET['q']         ?? null, 255);
$status     = clean_string($_GET['status']    ?? null);
$outcome    = clean_string($_GET['outcome']   ?? null);
$dateFrom   = clean_string($_GET['date_from'] ?? null);
$dateTo     = clean_string($_GET['date_to']   ?? null);
$sort       = clean_string($_GET['sort']      ?? 'created_at');
$dir        = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
$page       = max(1, clean_int($_GET['page']     ?? 1) ?? 1);
$perPage    = min(100, max(1, clean_int($_GET['per_page'] ?? 25) ?? 25));

// ── Validate enum filters ─────────────────────────────────────────────────────
$validStatuses  = ['sent', 'opened', 'submitted', 'reviewed'];
$validOutcomes  = ['approved', 'declined', 'needs_info'];
if ($status  !== null && !in_array($status,  $validStatuses,  true)) { $status  = null; }
if ($outcome !== null && !in_array($outcome, $validOutcomes, true)) { $outcome = null; }

// ── Sort (allowlist — never interpolate user input directly) ──────────────────
$sortMap = [
    'sent_at'      => "ca.sent_at {$dir}, ca.id DESC",
    'submitted_at' => "ca.submitted_at {$dir}, ca.id DESC",
    'reviewed_at'  => "ca.reviewed_at {$dir}, ca.id DESC",
    'created_at'   => "ca.created_at {$dir}, ca.id DESC",
    'company_name' => "c.company_name {$dir}, ca.created_at DESC",
    'status'       => "ca.status {$dir}, ca.created_at DESC",
];
$orderBy = $sortMap[$sort] ?? "ca.created_at DESC, ca.id DESC";

// ── Build WHERE ───────────────────────────────────────────────────────────────
$conditions = ['ca.deleted_at IS NULL'];
$params     = [];

if ($q !== null && $q !== '') {
    $like         = '%' . $q . '%';
    $conditions[] = '(c.company_name LIKE ? OR ca.print_name_first LIKE ? OR ca.print_name_last LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($status !== null) {
    $conditions[] = 'ca.status = ?';
    $params[]     = $status;
}
if ($outcome !== null) {
    $conditions[] = 'ca.review_outcome = ?';
    $params[]     = $outcome;
}
if ($dateFrom !== null && $dateFrom !== '') {
    $conditions[] = 'DATE(ca.created_at) >= ?';
    $params[]     = $dateFrom;
}
if ($dateTo !== null && $dateTo !== '') {
    $conditions[] = 'DATE(ca.created_at) <= ?';
    $params[]     = $dateTo;
}

$whereSQL = 'WHERE ' . implode(' AND ', $conditions);

// ── KPI counts (always global — not filtered by current query params) ─────────
$kpis = [
    'awaiting_review' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status = 'submitted' AND deleted_at IS NULL"
    ),
    'submitted_total' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status IN ('submitted','reviewed') AND deleted_at IS NULL"
    ),
    'active' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status IN ('sent','opened') AND deleted_at IS NULL"
    ),
    'reviewed' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status = 'reviewed' AND deleted_at IS NULL"
    ),
    'total' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications WHERE deleted_at IS NULL"
    ),
];

// ── Count matching rows for pagination ────────────────────────────────────────
$total = db_count(
    "SELECT COUNT(*)
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
     {$whereSQL}",
    $params
);

// ── Paginated fetch ───────────────────────────────────────────────────────────
// Trap 7: token_hash, signature_path, form_data, rendered_html, submitted_ip excluded.
$offset = ($page - 1) * $perPage;
$rows = db_select(
    "SELECT ca.id,
            ca.status,
            ca.review_outcome,
            ca.sent_at,
            ca.opened_at,
            ca.submitted_at,
            ca.reviewed_at,
            ca.created_at,
            ca.token_expires_at,
            ca.approved_credit_limit,
            ca.print_name_first,
            ca.print_name_last,
            (ca.generated_pdf_document_id IS NOT NULL) AS has_pdf,
            c.id   AS customer_id,
            c.company_name,
            sb.name AS sent_by_name,
            rb.name AS reviewed_by_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
       LEFT JOIN users sb ON sb.id = ca.sent_by
       LEFT JOIN users rb ON rb.id = ca.reviewed_by
     {$whereSQL}
     ORDER BY {$orderBy}
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Derive is_expired for 'sent' rows ─────────────────────────────────────────
$now = time();
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'                   => (int) $row['id'],
        'status'               => $row['status'],
        'review_outcome'       => $row['review_outcome'],
        'sent_at'              => $row['sent_at'],
        'opened_at'            => $row['opened_at'],
        'submitted_at'         => $row['submitted_at'],
        'reviewed_at'          => $row['reviewed_at'],
        'created_at'           => $row['created_at'],
        'token_expires_at'     => $row['token_expires_at'],
        'approved_credit_limit'=> $row['approved_credit_limit'],
        'print_name_first'     => $row['print_name_first'],
        'print_name_last'      => $row['print_name_last'],
        'has_pdf'              => (bool) $row['has_pdf'],
        'customer_id'          => (int) $row['customer_id'],
        'company_name'         => $row['company_name'],
        'sent_by_name'         => $row['sent_by_name'],
        'reviewed_by_name'     => $row['reviewed_by_name'],
        'is_expired'           => (
            $row['status'] === 'sent'
            && !empty($row['token_expires_at'])
            && strtotime((string) $row['token_expires_at']) < $now
        ),
    ];
}

// ── Response ──────────────────────────────────────────────────────────────────
$totalPages = max(1, (int) ceil($total / $perPage));

json_success([
    'items'      => $items,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'has_more'    => $page < $totalPages,
    ],
    'kpis' => $kpis,
]);
