<?php
declare(strict_types=1);

/**
 * api/v1/billing/deliver.php
 *
 * S-BILLING-MODULE — email ALREADY-SENT invoices to their customers
 * (again). This is the delivery half of bulk_send without the status
 * change: for invoices that went out as "Mark as Sent" without an email,
 * whose email failed or bounced and has since been fixed, or a customer
 * who asks for a copy. Drafts are refused — sending a draft is a status
 * change (counters, ledger, QuickBooks) that belongs to bulk_send / send.
 *
 * Same code path as bulk_send's email step (InvoiceDelivery::email), so the
 * template, recipient order, PDF attachment and email_logs row are
 * identical. Per-id isolated: one failure never stops the rest.
 *
 * Also behind the invoice page's "Re-send Invoice" (which used to call
 * send.php and could only ever 409 on a non-draft).
 *
 * @method  POST
 * @body    { ids: [int] (max 100), attach_pdf?: bool (default true), email_overrides?: {id: email} }
 * @auth    Session required; invoices:edit
 * @returns 200 { emailed, errors: [{id, reason}], results: [{id, to}] }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\InvoiceDelivery;

$body = json_body();
$raw = $body['ids'] ?? null;
if (!is_array($raw) || !$raw) {
    json_validation_error(['ids' => 'Select at least one invoice.']);
}
if (count($raw) > 100) {
    json_validation_error(['ids' => 'At most 100 invoices at a time.']);
}
$ids = [];
foreach ($raw as $r) {
    $i = clean_int($r);
    if (!$i || $i <= 0) {
        json_validation_error(['ids' => 'All ids must be positive integers.']);
    }
    $ids[] = $i;
}
$ids = array_values(array_unique($ids));
$attachPdf = !array_key_exists('attach_pdf', $body) || !empty($body['attach_pdf']);

$overrides = [];
foreach ((is_array($body['email_overrides'] ?? null) ? $body['email_overrides'] : []) as $k => $v) {
    $k = clean_int($k);
    $v = clean_email(is_string($v) ? $v : null);
    if ($k && $v) $overrides[$k] = $v;
}

$ph = implode(',', array_fill(0, count($ids), '?'));
$status = [];
foreach (db_select("SELECT id, invoice_number, status FROM invoices WHERE id IN ({$ph}) AND deleted_at IS NULL", $ids) as $r) {
    $status[(int) $r['id']] = $r;
}

$userId  = current_user_id();
$emailed = 0;
$errors  = [];
$results = [];
foreach ($ids as $id) {
    $inv = $status[$id] ?? null;
    if (!$inv) {
        $errors[] = ['id' => $id, 'reason' => 'Invoice not found.'];
        continue;
    }
    if ($inv['status'] === 'draft') {
        $errors[] = ['id' => $id, 'reason' => "{$inv['invoice_number']} is still a draft — send it first (Send & Email)."];
        continue;
    }
    if ($inv['status'] === 'void') {
        $errors[] = ['id' => $id, 'reason' => "{$inv['invoice_number']} is void."];
        continue;
    }
    $res = InvoiceDelivery::email($id, $overrides[$id] ?? null, $attachPdf, $userId);
    if ($res['success']) {
        $emailed++;
        $results[] = ['id' => $id, 'to' => $res['to']];
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'update',
            'module'       => 'billing',
            'entity_type'  => 'invoice',
            'entity_id'    => $id,
            'entity_label' => $inv['invoice_number'],
            'notes'        => "Emailed {$inv['invoice_number']} to {$res['to']}" . ($attachPdf ? ' with the PDF' : '') . '.',
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    } else {
        $errors[] = ['id' => $id, 'reason' => $inv['invoice_number'] . ': ' . ($res['error'] ?? 'Email could not be sent.')];
    }
}

json_success(['emailed' => $emailed, 'errors' => $errors, 'results' => $results]);
