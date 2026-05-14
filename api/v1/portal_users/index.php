<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/index.php
 *
 * Paginated list of portal users with optional filters and sort.
 *
 * Filters: status (active/inactive/invited), customer_id, q (LIKE on
 *   pu.name OR pu.email OR c.company_name), email_disabled (1/0).
 * Sort allowlist: name, email, status, last_login_at, created_at, company_name.
 * Default sort: company_name ASC then is_primary DESC then name ASC.
 *
 * NOTE: portal_users is NOT a soft-delete table (no deleted_at column).
 * The JOIN against customers DOES respect customers.deleted_at IS NULL,
 * which hides portal users whose customer was soft-deleted — those
 * portal users can no longer log in regardless because portal auth
 * requires an active customer.
 *
 * @method  GET
 * @auth    Session required; require_permission('settings','view')
 * @returns 200 json_paginated([portal_user rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete via customer JOIN), D7 (routing)
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['c.deleted_at IS NULL'];
$params = [];

$validStatuses = ['active', 'inactive', 'invited'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'pu.status = ?';
        $params[] = $status;
    }
}

if ($custId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'pu.customer_id = ?';
    $params[] = $custId;
}

if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(pu.name LIKE ? OR pu.email LIKE ? OR c.company_name LIKE ?)';
    array_push($params, $like, $like, $like);
}

if (array_key_exists('email_disabled', $_GET)) {
    $ed = (int) ($_GET['email_disabled']);
    $where[]  = 'pu.email_disabled = ?';
    $params[] = $ed ? 1 : 0;
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted
// -----------------------------------------------------------------------
$allowedSorts = [
    'name'         => 'pu.name',
    'email'        => 'pu.email',
    'status'       => 'pu.status',
    'last_login_at'=> 'pu.last_login_at',
    'created_at'   => 'pu.created_at',
    'company_name' => 'c.company_name',
];
$sortKey = in_array($_GET['sort'] ?? '', array_keys($allowedSorts), true) ? $_GET['sort'] : 'company_name';
$sortCol = $allowedSorts[$sortKey];
$dir     = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

// -----------------------------------------------------------------------
// 3. Pagination
// -----------------------------------------------------------------------
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

// -----------------------------------------------------------------------
// 4. Count + rows — NEVER include password_hash or token columns
// -----------------------------------------------------------------------
$total = db_count(
    "SELECT COUNT(*) FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE $whereSQL",
    $params
);

$rows = db_select(
    "SELECT
         pu.id, pu.name, pu.email, pu.status, pu.is_primary,
         pu.email_disabled, pu.email_disabled_reason, pu.email_disabled_at,
         pu.last_login_at, pu.login_attempts, pu.locked_until, pu.created_at,
         c.id AS customer_id, c.company_name, c.status AS customer_status
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE $whereSQL
     ORDER BY $sortCol $dir, pu.is_primary DESC, pu.name ASC
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
