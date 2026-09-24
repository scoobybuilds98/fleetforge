<?php
declare(strict_types=1);

/**
 * api/v1/billing/fixes/double_mileage.php
 *
 * S-BILLING-MODULE-2 — one-click repair of the F71 shape on a DRAFT: a
 * "Mileage usage" line (odometer-exact) AND a "Mileage overage" line
 * (item_type 'mileage') billing the same distance. Removes the overage
 * line(s), recomputes the totals with InvoiceRecalc (the invoice's frozen tax
 * snapshot), moves leases.total_invoiced by the change (drafts count there —
 * same rule as update_lines), and audits. The close bug that produced the
 * shape is fixed; this cleans the drafts it left behind (F71).
 * Logic: BillingReview::removeDoubleMileage().
 *
 * Draft only — a sent invoice is corrected with a credit note (D12).
 *
 * @method  POST
 * @body    { invoice_id }
 * @auth    Session required; invoices:edit
 * @returns 200 { removed, old_total, new_total }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingReview;

$id = require_id(json_body()['invoice_id'] ?? null);

try {
    $res = BillingReview::removeDoubleMileage($id);
} catch (\DomainException $e) {
    [$code, $msg] = explode('|', $e->getMessage(), 2) + [1 => $e->getMessage()];
    json_error($code, $msg, $code === 'NOT_FOUND' ? 404 : 409);
}
json_success($res);
