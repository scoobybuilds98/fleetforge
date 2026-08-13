<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_preview.php
 *
 * S-BATCH-INVOICING-2 — DRY RUN. Computes exactly what each selected lease
 * WOULD be billed for the period, without persisting anything.
 *
 * The actual work lives in lib/Billing/BatchPreviewService.php so this
 * endpoint and api/v1/invoices/batch_runs/create.php (which FREEZES the
 * same output as the approval snapshot a manager signs off) can never
 * drift apart — see that class for why the dry run really generates and
 * then rolls back rather than reimplementing the billing math.
 *
 * @method  POST
 * @body    { period_start, period_end, lease_ids: [int,...] }  (max 200)
 * @auth    Session required; require_permission('invoices','create')
 *          — 'create' not 'view': this exercises the real generator, so it
 *          is gated like generation even though nothing persists.
 * @returns 200 { period, previews: [...], totals: {...} }
 *
 * @depends lib/Billing/BatchPreviewService.php
 * @session S-BATCH-INVOICING-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'create');

use FleetForge\Billing\BatchPreviewService;

$body = json_body();

$periodStart = clean_date($body['period_start'] ?? null);
$periodEnd   = clean_date($body['period_end'] ?? null);

$fields = [];
if (!$periodStart) $fields['period_start'] = 'A valid period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'A valid period end date is required.';
if ($periodStart && $periodEnd && $periodEnd < $periodStart) {
    $fields['period_end'] = 'Period end cannot be before period start.';
}
if ($periodStart && $periodEnd && !isset($fields['period_end'])) {
    if ($periodErr = ff_billing_period_error($periodStart, $periodEnd)) {
        $fields['period_start'] = $periodErr;
    }
}

$rawIds = $body['lease_ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    $fields['lease_ids'] = 'Select at least one lease to preview.';
} elseif (count($rawIds) > 200) {
    $fields['lease_ids'] = 'A maximum of 200 leases can be previewed at once.';
}
if ($fields) {
    json_validation_error($fields);
}

$leaseIds = [];
foreach ($rawIds as $raw) {
    $id = clean_int($raw);
    if ($id && $id > 0) $leaseIds[] = $id;
}
$leaseIds = array_values(array_unique($leaseIds));
if (!$leaseIds) {
    json_validation_error(['lease_ids' => 'No valid lease IDs were submitted.']);
}

json_success(BatchPreviewService::run($leaseIds, $periodStart, $periodEnd, current_user_id()));
