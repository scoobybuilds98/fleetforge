<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/bad_debt_writeoff.php
 *
 * Write off an invoice as bad debt. Updates invoice status to 'written_off',
 * records in acc_bad_debt_writeoffs, and posts JE via AutoEntryBridge — all
 * through InvoiceWriteOff (shared with damage-claim write-offs), then queues
 * the QuickBooks CreditMemo applied to the invoice (S-QBO-INVOICE-WRITEOFF).
 *
 * @method  POST
 * @body    invoice_id (required), reason (required)
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Bad debt write-off)
 * Session: S031
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

use FleetForge\Accounting\InvoiceWriteOff;

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$invoiceId = clean_int($input['invoice_id'] ?? null);
$reason    = clean_string($input['reason'] ?? null, 1000);

if (!$invoiceId) $fields['invoice_id'] = 'Please select an invoice.';
if (!$reason)    $fields['reason']     = 'Please provide a reason for write-off.';

if ($fields) {
    json_validation_error($fields);
}

// The write-off itself (GL entry, acc_bad_debt_writeoffs row, invoice
// closed, customer balance) lives in InvoiceWriteOff — shared with damage-
// claim write-offs (S-QBO-INVOICE-WRITEOFF). Its refusals map onto this
// endpoint's original responses.
try {
    $result = db_transaction(function () use ($invoiceId, $reason) {
        $w = InvoiceWriteOff::writeOff($invoiceId, $reason, current_user_id());

        // Audit log
        db_insert('audit_log', [
            'user_id'     => current_user_id(),
            'action'      => 'create',
            'module'      => 'accounting',
            'entity_type' => 'bad_debt_writeoff',
            'entity_id'   => $w['writeoff_id'],
            'notes'       => "Bad debt write-off: {$w['invoice_number']} — {$w['amount']} ({$reason})",
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        return [
            'id'               => $w['writeoff_id'],
            'invoice_number'   => $w['invoice_number'],
            'amount'           => $w['amount'],
            'journal_entry_id' => $w['journal_entry_id'],
        ];
    });
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', 'Invoice not found.', 404, ['fields' => ['invoice_id' => 'Invoice not found.']]);
} catch (\DomainException $e) {
    if (str_contains($e->getMessage(), 'no balance')) {
        json_validation_error(['invoice_id' => 'Invoice has no balance to write off.'], 'Invoice has no balance to write off.');
    }
    json_error('INVALID_TRANSITION', $e->getMessage(), 409, ['fields' => ['invoice_id' => $e->getMessage()]]);
}

// S-QBO-INVOICE-WRITEOFF: QuickBooks gets a CreditMemo (Bad-debt item)
// applied to the invoice. Best-effort, after commit — never breaks the
// write-off (D-ENQUEUER-CONTRACT).
\FleetForge\QboPushers\InvoiceWriteoffEnqueuer::enqueue((int) $result['id'], 'create');

json_success($result, 201);
