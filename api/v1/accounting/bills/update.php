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

$id = clean_int($_POST['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$submittedUpdatedAt = clean_string($_POST['updated_at'] ?? null);
if (!$submittedUpdatedAt) json_error('VALIDATION_ERROR', 'updated_at is required for optimistic lock.', 422);

$bill = db_row("SELECT * FROM acc_bills WHERE id = ?", [$id]);
if (!$bill) json_error('NOT_FOUND', 'Bill not found.', 404);

// WHY: Only draft bills can be edited — approved bills have posted JEs
if ($bill['status'] !== 'draft') {
    json_error('IMMUTABLE_RECORD', 'Only draft bills can be edited.', 422);
}

// D19 optimistic lock
if ($bill['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'Record modified by another user. Refresh and try again.', 409);
}

$vendorId        = clean_int($_POST['vendor_id'] ?? null) ?? (int)$bill['vendor_id'];
$billDate        = clean_date($_POST['bill_date'] ?? null) ?? $bill['bill_date'];
$dueDate         = clean_date($_POST['due_date'] ?? null) ?? $bill['due_date'];
$vendorBillNum   = clean_string($_POST['vendor_bill_number'] ?? null);
$workOrderId     = clean_int($_POST['work_order_id'] ?? null);
$equipmentUnitId = clean_int($_POST['equipment_unit_id'] ?? null);
$notes           = clean_string($_POST['notes'] ?? null, 2000);

// Validate vendor
$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) json_error('NOT_FOUND', 'Vendor not found.', 404);

// Parse lines
$rawLines = $_POST['lines'] ?? null;
if (is_string($rawLines)) {
    $rawLines = json_decode($rawLines, true);
}
if (!is_array($rawLines) || count($rawLines) === 0) {
    json_error('VALIDATION_ERROR', 'At least one line item is required.', 422);
}

// Validate lines and compute totals (bcmath only — D16)
$subtotal = '0.00';
$taxGst   = '0.00';
$taxPst   = '0.00';
$taxHst   = '0.00';
$validatedLines = [];

foreach ($rawLines as $i => $line) {
    $accountId   = clean_int($line['account_id'] ?? null);
    $description = clean_string($line['description'] ?? null, 500);
    $quantity    = clean_decimal($line['quantity'] ?? '1') ?? '1.0000';
    $unitCost    = clean_decimal($line['unit_cost'] ?? null);

    if (!$accountId) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": account_id is required.", 422);
    if (!$description) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": description is required.", 422);
    if (!$unitCost) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": unit_cost is required.", 422);

    $account = db_row("SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?", [$accountId]);
    if (!$account) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": GL account not found.", 422);
    if ($account['is_header']) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": cannot post to header account.", 422);
    if (!$account['is_active']) json_error('VALIDATION_ERROR', "Line " . ($i + 1) . ": GL account is inactive.", 422);

    $lineAmount = bcmul($quantity, $unitCost, 2);
    $lineGst = clean_decimal($line['tax_gst_amount'] ?? '0') ?? '0.00';
    $linePst = clean_decimal($line['tax_pst_amount'] ?? '0') ?? '0.00';
    $lineHst = clean_decimal($line['tax_hst_amount'] ?? '0') ?? '0.00';
    $isItc = (int)($line['is_tax_input_credit'] ?? 1);
    $isAutoCategorized = (int)($line['is_auto_categorized'] ?? 0);

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
