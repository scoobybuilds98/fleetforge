<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/review_mark.php
 *
 * S-BILLING-MODULE — mark invoices in a cycle Reviewed or Query, or clear
 * the mark. Only invoices that belong to the cycle are touched. A Query
 * needs a note (it is a question for someone else).
 *
 * @method  POST
 * @body    { id, invoice_ids: [int], status: 'reviewed'|'query'|'clear', note? }  (max 500 ids)
 * @auth    Session required; invoices:edit
 * @returns 200 { changed }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\BillingReview;

$cycle = billing_cycle_from_request();
billing_cycle_require_open($cycle);
$body = json_body();

$status = (string) ($body['status'] ?? '');
if (!in_array($status, ['reviewed', 'query', 'clear'], true)) {
    json_validation_error(['status' => 'Choose Reviewed, Query or Clear.']);
}
$ids = [];
foreach ((array) ($body['invoice_ids'] ?? []) as $raw) {
    $i = clean_int($raw);
    if ($i && $i > 0) $ids[] = $i;
}
$ids = array_values(array_unique($ids));
if (!$ids) {
    json_validation_error(['invoice_ids' => 'Select at least one invoice.']);
}
if (count($ids) > 500) {
    json_validation_error(['invoice_ids' => 'At most 500 invoices at a time.']);
}
$note = clean_string($body['note'] ?? null, 500);
if ($status === 'query' && ($note === null || trim($note) === '')) {
    json_validation_error(['note' => 'Say what needs checking.']);
}

json_success(['changed' => BillingReview::mark($cycle, $ids, $status, $note, current_user_id())]);
