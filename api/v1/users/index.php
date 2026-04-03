<?php
declare(strict_types=1);

/**
 * api/v1/users/index.php
 *
 * Paginated list of users with optional filters and sort.
 *
 * Filters: status, role_id, q (LIKE search on name/email).
 * Sort allowlist: name, email, last_login_at, created_at, status.
 * Default sort: name ASC.
 *
 * NOTE: LIKE search is used (not FULLTEXT) — users table has no FULLTEXT index.
 * SOFT_DELETE: users has deleted_at — always AND u.deleted_at IS NULL.
 *
 * @method  GET
 * @auth    Session required; require_permission('users','view')
 * @returns 200 json_paginated([user rows], total, page, per_page)
 *
 * Decisions: D5 (soft delete), D7 (routing)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('users', 'view');

// -----------------------------------------------------------------------
// 1. Filters
// -----------------------------------------------------------------------
$where  = ['u.deleted_at IS NULL'];
$params = [];

// Filter: status ENUM
$validStatuses = ['active', 'inactive', 'invited', 'suspended', 'locked'];
if ($status = clean_string($_GET['status'] ?? null)) {
    if (in_array($status, $validStatuses, true)) {
        $where[]  = 'u.status = ?';
        $params[] = $status;
    }
}

// Filter: role_id
if ($roleId = clean_int($_GET['role_id'] ?? null)) {
    $where[]  = 'u.role_id = ?';
    $params[] = $roleId;
}

// LIKE-based search — users table has no FULLTEXT index
if ($q = clean_string($_GET['q'] ?? null)) {
    $like     = '%' . $q . '%';
    $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
    array_push($params, $like, $like);
}

// -----------------------------------------------------------------------
// 2. Sort — allowlisted, never from raw user input
// -----------------------------------------------------------------------
$allowedSorts = ['name', 'email', 'last_login_at', 'created_at', 'status'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'name';
$dir  = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

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
$total = db_count("SELECT COUNT(*) FROM users u WHERE $whereSQL", $params);

$rows = db_select(
    "SELECT
         u.id, u.name, u.email, u.status, u.phone,
         u.last_login_at, u.created_at,
         ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE $whereSQL
     ORDER BY u.$sort $dir
     LIMIT $perPage OFFSET $offset",
    $params
);

json_paginated($rows, $total, $page, $perPage);
