<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/create.php
 *
 * Create a new AP bill with line items. Optionally auto-approve (Decision A1:
 * accountant has full authority, no separate approval workflow).
 * On approval, posts JE: DR expense accounts + DR GST Receivable (ITC) / CR 2010 AP.
 * Runs auto-categorization rules engine if enabled.
 *
 * @method  POST
 * @body    vendor_id, bill_date, due_date, vendor_bill_number?, work_order_id?,
 *          equipment_unit_id?, notes?, lines[] (each: account_id, description,
 *          quantity, unit_cost, tax_gst_amount?, tax_pst_amount?, tax_hst_amount?,
 *          is_tax_input_credit?), auto_approve?
 * @auth    Session required; require_permission('accounts_payable','create')
 * @returns 201 { id, bill_number }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (AP bill lifecycle)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'create');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

// ── Input validation ───────────────────────────────────────
// VALID-2: accept both form-encoded and JSON bodies so the
// admin UI can send a structured JSON payload (the Alpine
// client does this now) while legacy form posts still work.
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$vendorId        = clean_int($input['vendor_id'] ?? null);
$billDate        = clean_date($input['bill_date'] ?? null);
$dueDate         = clean_date($input['due_date'] ?? null);
$vendorBillNum   = clean_string($input['vendor_bill_number'] ?? null);
$workOrderId     = clean_int($input['work_order_id'] ?? null);
$equipmentUnitId = clean_int($input['equipment_unit_id'] ?? null);
$notes           = clean_string($input['notes'] ?? null, 2000);
$currency        = clean_string($input['currency'] ?? null) ?? 'CAD';
$autoApprove     = !empty($input['auto_approve']) && $input['auto_approve'] !== '0';

// VALID-2: accumulator pattern — collect every error, return all at once
$fields = [];

if (!$vendorId)  $fields['vendor_id']  = 'Please select a vendor.';
if (!$billDate)  $fields['bill_date']  = 'Bill date is required.';
if (!$dueDate)   $fields['due_date']   = 'Due date is required.';

// Cross-field date check — only when both are present and valid
if ($billDate && $dueDate && strtotime($dueDate) < strtotime($billDate)) {
    $fields['due_date'] = 'Due date cannot be before the bill date.';
}

// Short-circuit if header fields are bad — further DB checks are pointless
if ($fields) {
    json_validation_error($fields);
}

// Validate vendor exists and is not deleted
$vendor = db_row(
    "SELECT id, name, vendor_type FROM vendors WHERE id = ? AND deleted_at IS NULL",
    [$vendorId]
);
if (!$vendor) {
    json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
}

// Validate optional work order link
if ($workOrderId) {
    $wo = db_row(
        "SELECT id FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL",
        [$workOrderId]
    );
    if (!$wo) {
        json_validation_error(['work_order_id' => 'Work order not found.'], 'Work order not found.');
    }
}

// Validate optional equipment unit link
if ($equipmentUnitId) {
    $eu = db_row(
        "SELECT id FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
        [$equipmentUnitId]
    );
    if (!$eu) {
        json_validation_error(['equipment_unit_id' => 'Equipment unit not found.'], 'Equipment unit not found.');
    }
}

// Parse lines from JSON or form data
$rawLines = $input['lines'] ?? null;
if (is_string($rawLines)) {
    $rawLines = json_decode($rawLines, true);
}
if (!is_array($rawLines) || count($rawLines) === 0) {
    json_validation_error(['lines' => 'At least one line item is required.']);
}
if (count($rawLines) > 50) {
    json_validation_error(['lines' => 'Maximum 50 line items per bill.']);
}

// Validate each line and compute totals (bcmath only — D16)
$subtotal = '0.00';
$taxGst   = '0.00';
$taxPst   = '0.00';
$taxHst   = '0.00';
$validatedLines = [];
// VALID-2: line-level errors collected as a list, joined into the
// `lines` field slot so every problem is surfaced at once.
$lineErrors = [];

foreach ($rawLines as $i => $line) {
    $lineNum     = $i + 1;
    $accountId   = clean_int($line['account_id'] ?? null);
    $description = clean_string($line['description'] ?? null, 500);
    $quantity    = clean_decimal($line['quantity'] ?? '1') ?? '1.0000';
    $unitCost    = clean_decimal($line['unit_cost'] ?? null);

    $hasError = false;
    if (!$accountId)   { $lineErrors[] = "Line {$lineNum}: please select a GL account."; $hasError = true; }
    if (!$description) { $lineErrors[] = "Line {$lineNum}: description is required."; $hasError = true; }
    if ($unitCost === null || $unitCost === '') {
        $lineErrors[] = "Line {$lineNum}: unit cost is required.";
        $hasError = true;
    } elseif (bccomp($unitCost, '0', 2) < 0) {
        $lineErrors[] = "Line {$lineNum}: unit cost cannot be negative.";
        $hasError = true;
    }
    if ($quantity !== null && $quantity !== '' && bccomp($quantity, '0', 4) <= 0) {
        $lineErrors[] = "Line {$lineNum}: quantity must be greater than zero.";
        $hasError = true;
    }

    // Verify GL account exists, is active, and is not a header
    if ($accountId) {
        $account = db_row(
            "SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?",
            [$accountId]
        );
        if (!$account) {
            $lineErrors[] = "Line {$lineNum}: GL account not found.";
            $hasError = true;
        } elseif ($account['is_header']) {
            $lineErrors[] = "Line {$lineNum}: cannot post to a header account.";
            $hasError = true;
        } elseif (!$account['is_active']) {
            $lineErrors[] = "Line {$lineNum}: GL account is inactive.";
            $hasError = true;
        }
    }

    if ($hasError) continue;

    $lineAmount = bcmul($quantity, $unitCost, 2);
    $lineGst = clean_decimal($line['tax_gst_amount'] ?? '0') ?? '0.00';
    $linePst = clean_decimal($line['tax_pst_amount'] ?? '0') ?? '0.00';
    $lineHst = clean_decimal($line['tax_hst_amount'] ?? '0') ?? '0.00';
    $isItc = (int)($line['is_tax_input_credit'] ?? 1);
    $isAutoCategorized = (int)($line['is_auto_categorized'] ?? 0);

    // VALID-2: reject negative taxes — they would invert the JE sides
    if (bccomp($lineGst, '0', 2) < 0) { $lineErrors[] = "Line {$lineNum}: GST cannot be negative."; continue; }
    if (bccomp($linePst, '0', 2) < 0) { $lineErrors[] = "Line {$lineNum}: PST cannot be negative."; continue; }
    if (bccomp($lineHst, '0', 2) < 0) { $lineErrors[] = "Line {$lineNum}: HST cannot be negative."; continue; }

    $subtotal = bcadd($subtotal, $lineAmount, 2);
    $taxGst = bcadd($taxGst, $lineGst, 2);
    $taxPst = bcadd($taxPst, $linePst, 2);
    $taxHst = bcadd($taxHst, $lineHst, 2);

    $validatedLines[] = [
        'account_id'          => $accountId,
        'description'         => $description,
        'quantity'            => $quantity,
        'unit_cost'           => $unitCost,
        'amount'              => $lineAmount,
        'tax_gst_amount'      => $lineGst,
        'tax_pst_amount'      => $linePst,
        'tax_hst_amount'      => $lineHst,
        'is_tax_input_credit' => $isItc,
        'is_auto_categorized' => $isAutoCategorized,
        'sort_order'          => $i,
    ];
}

// Surface every collected line error at once
if ($lineErrors) {
    json_validation_error(['lines' => implode(' ', $lineErrors)]);
}

$taxTotal = bcadd(bcadd($taxGst, $taxPst, 2), $taxHst, 2);
$totalAmount = bcadd($subtotal, $taxTotal, 2);

// ── Create bill inside transaction ─────────────────────────
$result = db_transaction(function () use (
    $vendorId, $billDate, $dueDate, $vendorBillNum, $workOrderId,
    $equipmentUnitId, $notes, $currency, $autoApprove, $subtotal,
    $taxGst, $taxPst, $taxHst, $taxTotal, $totalAmount, $validatedLines,
    $vendor
) {
    $year = substr($billDate, 0, 4);
    $billNumber = AccountingService::nextBillNumber($year);

    // Resolve period for the bill date
    $period = AccountingService::periodForDate($billDate);
    if (!$period) {
        throw new \RuntimeException("No accounting period found for date {$billDate}.");
    }

    $status = $autoApprove ? 'approved' : 'draft';

    $billId = db_insert('acc_bills', [
        'bill_number'         => $billNumber,
        'vendor_id'           => $vendorId,
        'vendor_bill_number'  => $vendorBillNum,
        'bill_date'           => $billDate,
        'due_date'            => $dueDate,
        'period_id'           => $period['id'],
        'status'              => $status,
        'currency'            => $currency,
        'subtotal'            => $subtotal,
        'tax_gst_amount'      => $taxGst,
        'tax_pst_amount'      => $taxPst,
        'tax_hst_amount'      => $taxHst,
        'tax_total'           => $taxTotal,
        'total_amount'        => $totalAmount,
        'amount_paid'         => '0.00',
        'balance_due'         => $totalAmount,
        'work_order_id'       => $workOrderId,
        'equipment_unit_id'   => $equipmentUnitId,
        'notes'               => $notes,
        'created_by'          => current_user_id(),
    ]);

    // Insert line items
    foreach ($validatedLines as $line) {
        $line['bill_id'] = $billId;
        db_insert('acc_bill_lines', $line);
    }

    $jeId = null;

    // If auto-approve, post the JE immediately
    // WHY: Decision A1 — accountant has full authority, approve = immediate
    if ($autoApprove) {
        $jeId = self_postBillJe($billId, $billNumber, $billDate, $totalAmount,
            $taxGst, $taxPst, $taxHst, $validatedLines, $vendorId, $vendor['name']);

        db_update('acc_bills', [
            'journal_entry_id' => $jeId,
        ], 'id = ?', [$billId]);

        // Update vendor.total_spent in same transaction (Trap 6)
        db_execute(
            "UPDATE vendors SET total_spent = total_spent + ? WHERE id = ?",
            [$totalAmount, $vendorId]
        );
    }

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'ap_bill',
        'entity_id'   => $billId,
        'notes'       => "Bill {$billNumber} created ({$status}) — {$vendor['name']}: \${$totalAmount}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'id'              => $billId,
        'bill_number'     => $billNumber,
        'status'          => $status,
        'total_amount'    => $totalAmount,
        'journal_entry_id'=> $jeId,
    ];
});

json_success($result, 201);

// ── Helper: Post journal entry for an approved bill ────────
/**
 * Post JE for bill approval: DR expense accounts + DR GST Receivable (ITC) / CR AP.
 * WHY: Every bill approval must post a JE per Decision A8 (JE backbone).
 */
function self_postBillJe(
    int $billId, string $billNumber, string $billDate, string $totalAmount,
    string $taxGst, string $taxPst, string $taxHst,
    array $lines, int $vendorId, string $vendorName
): int {
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
            'debit'       => $line['amount'],
            'credit'      => '0.00',
            'description' => $line['description'],
            'vendor_id'   => $vendorId,
        ];
    }

    // DR GST/HST Receivable (ITC) — only for lines with is_tax_input_credit
    $itcTotal = '0.00';
    foreach ($lines as $line) {
        if ($line['is_tax_input_credit']) {
            $lineTax = bcadd(bcadd($line['tax_gst_amount'], $line['tax_hst_amount'], 2), '0.00', 2);
            $itcTotal = bcadd($itcTotal, $lineTax, 2);
        }
    }

    // Non-ITC PST goes to expense (not recoverable)
    $nonItcPst = '0.00';
    foreach ($lines as $line) {
        $nonItcPst = bcadd($nonItcPst, $line['tax_pst_amount'], 2);
    }

    if (bccomp($itcTotal, '0.00', 2) > 0 && $gstReceivableId) {
        $jeLines[] = [
            'account_id'  => (int) $gstReceivableId,
            'debit'       => $itcTotal,
            'credit'      => '0.00',
            'description' => "GST/HST ITC — Bill {$billNumber}",
            'vendor_id'   => $vendorId,
        ];
    }

    // PST is not recoverable in most provinces — add to first expense line or separate
    if (bccomp($nonItcPst, '0.00', 2) > 0) {
        // PST added as expense debit (not recoverable)
        $jeLines[0]['debit'] = bcadd($jeLines[0]['debit'], $nonItcPst, 2);
    }

    // CR AP for total amount
    $jeLines[] = [
        'account_id'  => (int) $apAccountId,
        'debit'       => '0.00',
        'credit'      => $totalAmount,
        'description' => "AP — Bill {$billNumber} — {$vendorName}",
        'vendor_id'   => $vendorId,
    ];

    $je = JournalEntryService::create([
        'entry_date'       => $billDate,
        'description'      => "AP Bill {$billNumber} — {$vendorName}",
        'entry_type'       => 'system',
        'reference'        => $billNumber,
        'source_type'      => 'ap_bill',
        'source_id'        => $billId,
        'post_immediately' => true,
    ], $jeLines, current_user_id());

    return (int) $je['id'];
}
