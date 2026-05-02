<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/update.php
 *
 * Update a draft AP bill. Only draft bills can be edited.
 * Uses optimistic locking (D19) via updated_at comparison.
 *
 * @method  POST
 * @body    id, updated_at (optimistic lock), plus same fields as create
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { id, bill_number }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

// VALID-2: accept both JSON and form-encoded payloads.
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

// ── Phase 1: header validation accumulator ──
$fields = [];

$id = clean_int($input['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Bill ID is required.';
}
$submittedUpdatedAt = clean_string($input['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}
if ($fields) {
    json_validation_error($fields);
}

$bill = db_row("SELECT * FROM acc_bills WHERE id = ?", [$id]);
if (!$bill) {
    json_validation_error(['id' => 'Bill not found.'], 'Bill not found.');
}

// WHY: Only draft bills can be edited — approved bills have posted JEs
if ($bill['status'] !== 'draft') {
    json_error('IMMUTABLE_RECORD',
        'Only draft bills can be edited.', 422,
        ['fields' => ['id' => "Cannot edit a bill in status '{$bill['status']}'."]]);
}

// D19 optimistic lock
if (!optimistic_lock_matches($submittedUpdatedAt, $bill['updated_at'])) {
    json_error('STALE_DATA',
        'This bill was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This bill was modified by another user. Refresh and try again.']]);
}

$vendorId        = clean_int($input['vendor_id'] ?? null) ?? (int)$bill['vendor_id'];
$billDate        = clean_date($input['bill_date'] ?? null) ?? $bill['bill_date'];
$dueDate         = clean_date($input['due_date'] ?? null) ?? $bill['due_date'];
$vendorBillNum   = clean_string($input['vendor_bill_number'] ?? null);
$workOrderId     = clean_int($input['work_order_id'] ?? null);
$equipmentUnitId = clean_int($input['equipment_unit_id'] ?? null);
$notes           = clean_string($input['notes'] ?? null, 2000);

// Cross-field date check
if ($billDate && $dueDate && strtotime($dueDate) < strtotime($billDate)) {
    json_validation_error(['due_date' => 'Due date cannot be before the bill date.']);
}

// Validate vendor
$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) {
    json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
}

// Parse lines
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

// Validate lines and compute totals (bcmath only — D16)
$subtotal = '0.00';
$taxGst   = '0.00';
$taxPst   = '0.00';
$taxHst   = '0.00';
$validatedLines = [];
$lineErrors     = [];

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

    if ($accountId) {
        $account = db_row("SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?", [$accountId]);
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

if ($lineErrors) {
    json_validation_error(['lines' => implode(' ', $lineErrors)]);
}

$taxTotal = bcadd(bcadd($taxGst, $taxPst, 2), $taxHst, 2);
$totalAmount = bcadd($subtotal, $taxTotal, 2);

$result = db_transaction(function () use (
    $id, $vendorId, $billDate, $dueDate, $vendorBillNum, $workOrderId,
    $equipmentUnitId, $notes, $subtotal, $taxGst, $taxPst, $taxHst,
    $taxTotal, $totalAmount, $validatedLines, $bill, $vendor
) {
    // Resolve period
    $period = \FleetForge\Accounting\AccountingService::periodForDate($billDate);
    if (!$period) {
        throw new \RuntimeException("No accounting period found for date {$billDate}.");
    }

    db_update('acc_bills', [
        'vendor_id'           => $vendorId,
        'vendor_bill_number'  => $vendorBillNum,
        'bill_date'           => $billDate,
        'due_date'            => $dueDate,
        'period_id'           => $period['id'],
        'subtotal'            => $subtotal,
        'tax_gst_amount'      => $taxGst,
        'tax_pst_amount'      => $taxPst,
        'tax_hst_amount'      => $taxHst,
        'tax_total'           => $taxTotal,
        'total_amount'        => $totalAmount,
        'balance_due'         => $totalAmount,
        'work_order_id'       => $workOrderId,
        'equipment_unit_id'   => $equipmentUnitId,
        'notes'               => $notes,
    ], 'id = ?', [$id]);

    // Replace all line items
    db_execute("DELETE FROM acc_bill_lines WHERE bill_id = ?", [$id]);
    foreach ($validatedLines as $line) {
        $line['bill_id'] = $id;
        db_insert('acc_bill_lines', $line);
    }

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'ap_bill',
        'entity_id'   => $id,
        'notes'       => "Bill {$bill['bill_number']} updated — {$vendor['name']}: \${$totalAmount}",
        'old_values'  => json_encode(['total_amount' => $bill['total_amount']]),
        'new_values'  => json_encode(['total_amount' => $totalAmount]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $id, 'bill_number' => $bill['bill_number']];
});

json_success($result);
