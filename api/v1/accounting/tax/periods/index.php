<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/periods/index.php
 *
 * Paginated listing of tax filing periods. Used by the index page
 * (app/admin/accounting/tax/index.php) to render the period table
 * with optional filters.
 *
 * @method  GET
 * @query   tax_type, status, year, page, per_page
 * @auth    Session required; require_permission('tax_management','view')
 * @returns 200 paginated list
 *
 * @depends api/bootstrap.php, TaxFilingService
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\TaxFilingService;

require_method('GET');
require_auth_api();
require_permission('tax_management', 'view');

// Pull and sanitize filters. Filters are optional — empty values mean
// "no filter on that field" (handled in TaxFilingService::listPeriods).
$filters = [
    'tax_type' => clean_string($_GET['tax_type'] ?? null),
    'status'   => clean_string($_GET['status']   ?? null),
    'year'     => clean_int($_GET['year'] ?? null),
];

$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));

$result = TaxFilingService::listPeriods($filters, $page, $perPage);

json_paginated($result['items'], $result['total'], $result['page'], $result['per_page']);
