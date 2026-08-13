<?php
declare(strict_types=1);

/**
 * api/v1/invoices/billing_exceptions/index.php
 *
 * S-BILLING-EXCEPTIONS — the "couldn't bill these" review queue.
 *
 * Joins through to the lease + customer so the operator can act on a flag
 * without a second lookup: which customer, which contract, which unit, and
 * whether the lease is still active (a flag on a lease that has since been
 * closed usually just needs dismissing).
 *
 * @method  GET
 * @query   status? (open|resolved|ignored, default open), limit? (default 50, max 200)
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { exceptions: [...], open_count: int }
 *
 * @session S-BILLING-EXCEPTIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\BillingExceptions;

$status = clean_string($_GET['status'] ?? null, 20);
if (!in_array($status, ['open', 'resolved', 'ignored'], true)) {
    $status = 'open';
}

$limit = clean_int($_GET['limit'] ?? null) ?: 50;
$limit = max(1, min(200, $limit));

$rows = db_select(
    "SELECT e.id, e.lease_id, e.customer_id, e.period_start, e.period_end,
            e.reason, e.source, e.status, e.flagged_count, e.last_flagged_at,
            e.resolution_note, e.resolved_at, e.created_at,
            l.contract_number, l.status AS lease_status,
            c.company_name,
            eu.unit_number,
            ru.name AS resolved_by_name
       FROM invoice_billing_exceptions e
       LEFT JOIN leases l           ON l.id  = e.lease_id
       LEFT JOIN customers c        ON c.id  = e.customer_id AND c.deleted_at IS NULL
       LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
       LEFT JOIN users ru           ON ru.id = e.resolved_by
      WHERE e.status = ? AND e.deleted_at IS NULL
      ORDER BY e.last_flagged_at DESC, e.id DESC
      LIMIT {$limit}",
    [$status]
);

json_success([
    'exceptions' => array_map(static fn ($r) => [
        'id'              => (int) $r['id'],
        'lease_id'        => (int) $r['lease_id'],
        'customer_id'     => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        'company_name'    => $r['company_name'],
        'contract_number' => $r['contract_number'],
        'unit_number'     => $r['unit_number'],
        'lease_status'    => $r['lease_status'],
        'period_start'    => $r['period_start'],
        'period_end'      => $r['period_end'],
        'reason'          => $r['reason'],
        'source'          => $r['source'],
        'status'          => $r['status'],
        'flagged_count'   => (int) $r['flagged_count'],
        'last_flagged_at' => $r['last_flagged_at'],
        'resolution_note' => $r['resolution_note'],
        'resolved_at'     => $r['resolved_at'],
        'resolved_by_name'=> $r['resolved_by_name'],
    ], $rows),
    'open_count' => BillingExceptions::openCount(),
]);
