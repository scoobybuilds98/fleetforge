<?php
declare(strict_types=1);

/**
 * api/v1/billing/mark_delivered.php
 *
 * S-BILLING-MODULE-2 — record how SENT invoices reached a customer who is
 * not billed by email: printed and mailed / handed over ('manual') or left on
 * the portal ('portal'). Sets invoices.delivery_method (the column the send
 * path already writes 'email' to) and audits it, so the cycle's Delivery tab
 * shows them as delivered instead of "sent, never emailed".
 * Logic: InvoiceDelivery::markDelivered().
 *
 * @method  POST
 * @body    { ids: [int] (max 200), method: manual|portal, note? }
 * @auth    Session required; invoices:edit
 * @returns 200 { updated, errors: [{id, reason}] }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\InvoiceDelivery;

$body = json_body();
$method = (string) ($body['method'] ?? '');
if (!in_array($method, ['manual', 'portal'], true)) {
    json_validation_error(['method' => 'Choose mailed / handed over, or portal.']);
}
$ids = [];
foreach ((array) ($body['ids'] ?? []) as $r) {
    $i = clean_int($r);
    if ($i && $i > 0) $ids[] = $i;
}
$ids = array_values(array_unique($ids));
if (!$ids || count($ids) > 200) {
    json_validation_error(['ids' => 'Select between 1 and 200 invoices.']);
}
$note = clean_string($body['note'] ?? null, 500);

json_success(InvoiceDelivery::markDelivered($ids, $method, $note, current_user_id(), (string) (current_user()['name'] ?? 'system')));
