<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/approve.php
 *
 * Approve a draft AP bill — transitions draft → approved and posts JE.
 * JE: DR expense accounts + DR GST Receivable (ITC) / CR 2010 AP.
 * Updates vendor.total_spent in same transaction (Trap 6).
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id, status, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (bill lifecycle: draft → approved)
 * Decision: A1 (accountant has full authority — no approval workflow)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;
use FleetForge\Accounting\FixedAssetService;

// VALID-2: accept both JSON and form-encoded payloads.
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Bill ID is required.']);
}

$result = db_transaction(function () use ($id) {
    // FOR UPDATE to prevent race condition on double-approve
    $bill = db_row("SELECT * FROM acc_bills WHERE id = ? FOR UPDATE", [$id]);
    if (!$bill) {
        json_validation_error(['id' => 'Bill not found.'], 'Bill not found.');
    }

    if ($bill['status'] !== 'draft') {
        json_error('INVALID_TRANSITION',
            "Cannot approve — bill is already {$bill['status']}.", 409,
            ['fields' => ['id' => "Cannot approve a bill in status '{$bill['status']}'."]]);
    }

    $vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [(int)$bill['vendor_id']]);
    if (!$vendor) {
        json_validation_error(['id' => 'Vendor not found on this bill.'], 'Vendor not found.');
    }

    $lines = db_select("SELECT * FROM acc_bill_lines WHERE bill_id = ? ORDER BY sort_order", [$id]);
    if (empty($lines)) {
        json_validation_error(['id' => 'Cannot approve a bill with no line items.']);
    }

    // Build JE lines
    $apAccountId = AccountingService::setting('accounting.ap_account_id');
    $gstReceivableId = AccountingService::setting('accounting.gst_receivable_account_id');

    if (!$apAccountId) {
        throw new \RuntimeException('AP account not configured. Go to Accounting → Settings.');
    }

    $jeLines = [];

    // DR each expense line
    foreach ($lines as $line) {
        $jeLines[] = [
            'account_id'  => (int) $line['account_id'],
            'debit'       => (string) $line['amount'],
            'credit'      => '0.00',
            'description' => $line['description'],
            'vendor_id'   => (int) $bill['vendor_id'],
        ];
    }

    // DR GST/HST Receivable (ITC)
    $itcTotal = '0.00';
    foreach ($lines as $line) {
        if ($line['is_tax_input_credit']) {
            $lineTax = bcadd((string)$line['tax_gst_amount'], (string)$line['tax_hst_amount'], 2);
            $itcTotal = bcadd($itcTotal, $lineTax, 2);
        }
    }

    // PST not recoverable — add to first expense line
    $nonItcPst = '0.00';
    foreach ($lines as $line) {
        $nonItcPst = bcadd($nonItcPst, (string)$line['tax_pst_amount'], 2);
    }

    if (bccomp($itcTotal, '0.00', 2) > 0 && $gstReceivableId) {
        $jeLines[] = [
            'account_id'  => (int) $gstReceivableId,
            'debit'       => $itcTotal,
            'credit'      => '0.00',
            'description' => "GST/HST ITC — Bill {$bill['bill_number']}",
            'vendor_id'   => (int) $bill['vendor_id'],
        ];
    }

    if (bccomp($nonItcPst, '0.00', 2) > 0) {
        $jeLines[0]['debit'] = bcadd($jeLines[0]['debit'], $nonItcPst, 2);
    }

    // CR AP for total amount
    $jeLines[] = [
        'account_id'  => (int) $apAccountId,
        'debit'       => '0.00',
        'credit'      => (string) $bill['total_amount'],
        'description' => "AP — Bill {$bill['bill_number']} — {$vendor['name']}",
        'vendor_id'   => (int) $bill['vendor_id'],
    ];

    $je = JournalEntryService::create([
        'entry_date'       => $bill['bill_date'],
        'description'      => "AP Bill {$bill['bill_number']} — {$vendor['name']}",
        'entry_type'       => 'system',
        'reference'        => $bill['bill_number'],
        'source_type'      => 'ap_bill',
        'source_id'        => $id,
        'post_immediately' => true,
    ], $jeLines, current_user_id());

    db_update('acc_bills', [
        'status'           => 'approved',
        'journal_entry_id' => $je['id'],
    ], 'id = ?', [$id]);

    // Trap 6: update vendor.total_spent in same transaction
    db_execute(
        "UPDATE vendors SET total_spent = total_spent + ? WHERE id = ?",
        [(string) $bill['total_amount'], (int) $bill['vendor_id']]
    );

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'status_change',
        'module'      => 'accounting',
        'entity_type' => 'ap_bill',
        'entity_id'   => $id,
        'notes'       => "Bill {$bill['bill_number']} approved — JE {$je['entry_number']}",
        'old_values'  => json_encode(['status' => 'draft']),
        'new_values'  => json_encode(['status' => 'approved', 'journal_entry_id' => $je['id']]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // S-ACCT-COMP: surface pre-classified vs un-classified asset-linked lines.
    // - capitalize=1 + asset_id NOT NULL → fire FixedAssetService::capitalize()
    //   immediately so the asset's depreciable_cost reflects the betterment.
    // - capitalize=0/NULL + asset_id NOT NULL → soft warning in response.
    //   (Not blocking — operator may post the expense and classify later.)
    $capitalized        = [];
    $uncategorizedCount = 0;
    foreach ($lines as $line) {
        if (empty($line['asset_id'])) continue;
        if ((int) $line['capitalize'] === 1) {
            $note = trim((string) ($line['betterment_note'] ?? '')) !== ''
                ? (string) $line['betterment_note']
                : "Bill {$bill['bill_number']} line auto-capitalized on bill approval.";
            try {
                FixedAssetService::capitalize(
                    (int) $line['asset_id'],
                    (string) $line['amount'],
                    current_user_id(),
                    $note,
                    (int) $line['id']
                );
                $capitalized[] = (int) $line['asset_id'];
            } catch (\RuntimeException $e) {
                // Don't abort the whole approval — log + continue.
                error_log("S-ACCT-COMP: capitalize failed for bill_line #{$line['id']}: {$e->getMessage()}");
            }
        } else {
            $uncategorizedCount++;
        }
    }

    return [
        'id'                  => $id,
        'bill_number'         => $bill['bill_number'],
        'status'              => 'approved',
        'journal_entry_id'    => (int) $je['id'],
        'capitalized_assets'  => $capitalized,
        'uncategorized_count' => $uncategorizedCount,
    ];
});

// S-ACCT-COMP: append soft warning when asset-linked lines were left unclassified.
if (!empty($result['uncategorized_count'])) {
    $result['warning'] = "{$result['uncategorized_count']} bill line(s) linked to fixed assets "
                       . "have not been classified as betterment or repair. "
                       . "Visit the bill to classify before year-end.";
}

json_success($result);
