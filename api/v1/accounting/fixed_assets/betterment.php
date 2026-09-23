<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/betterment.php
 *
 * Capitalize a betterment to a fixed asset (ASPE 3061.14 per spec §23.5).
 * Adds amount to acquisition_cost and recomputes depreciable_cost. When
 * a bill_line_id is supplied, also marks acc_bill_lines.capitalize=1 +
 * betterment_note for audit-trail consistency.
 *
 * @method  POST
 * @body    JSON: asset_id (required), amount (decimal > 0),
 *                note (required text — ASPE 3061.14 justification),
 *                bill_line_id (optional — when surfaced from bills/show)
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 updated asset row | 422 validation error | 404 NOT_FOUND
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.5
 * Session: S-ACCT-COMP
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body       = json_body();
$assetId    = clean_int($body['asset_id'] ?? null);
$amount     = clean_decimal($body['amount'] ?? null);
$note       = is_string($body['note'] ?? null) ? trim($body['note']) : '';
$billLineId = clean_int($body['bill_line_id'] ?? null);
// SOP I2: without a bill line, the account the betterment was paid from.
$fundingAccountId = clean_int($body['funding_account_id'] ?? null);

$errors = [];
if (!$assetId) $errors['asset_id'] = 'asset_id is required.';
if ($amount === null || $amount === '' || bccomp($amount, '0.00', 2) <= 0) {
    $errors['amount'] = 'amount must be a positive decimal.';
}
if (strlen($note) < 5) {
    $errors['note'] = 'note is required (≥ 5 chars — ASPE 3061.14 justification).';
}
if ($errors) {
    json_validation_error($errors);
}

// If bill_line_id provided, verify it belongs to this asset before
// touching anything (so we fail fast with a clean error).
if ($billLineId !== null) {
    $line = db_row(
        "SELECT bl.id, bl.asset_id, bl.capitalize, b.status AS bill_status
           FROM acc_bill_lines bl JOIN acc_bills b ON b.id = bl.bill_id
          WHERE bl.id = ?",
        [$billLineId]
    );
    if (!$line) {
        json_error('NOT_FOUND', 'Bill line not found.', 404);
    }
    if ($line['asset_id'] !== null && (int) $line['asset_id'] !== $assetId) {
        json_validation_error(
            ['bill_line_id' => "Bill line is linked to a different asset (#{$line['asset_id']})."],
            'Bill line / asset mismatch.'
        );
    }
    if ((int) $line['capitalize'] === 1) {
        json_validation_error(
            ['bill_line_id' => 'This bill line has already been capitalized.'],
            'This bill line has already been capitalized.'
        );
    }
    // SOP I2: a DRAFT bill has posted nothing yet. Just classify the line —
    // bills/approve.php capitalizes it (with its reclass entry) on approval.
    // Capitalizing now too was a double capitalization.
    if ($line['bill_status'] === 'draft') {
        db_update('acc_bill_lines', [
            'capitalize'      => 1,
            'asset_id'        => $assetId,
            'betterment_note' => $note,
        ], 'id = ?', [$billLineId]);
        json_success([
            'deferred' => true,
            'message'  => 'Classified as a betterment — it is added to the asset when the bill is approved.',
            'asset'    => db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$assetId]),
        ]);
    }
} elseif (!$fundingAccountId) {
    json_validation_error(
        ['funding_account_id' => 'Choose the account the betterment was paid from.'],
        'Choose the account the betterment was paid from.'
    );
}

try {
    $updated = FixedAssetService::capitalize(
        $assetId,
        $amount,
        current_user_id(),
        $note,
        $billLineId,
        $billLineId === null ? $fundingAccountId : null
    );
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'not found') !== false) {
        json_error('NOT_FOUND', $msg, 404);
    }
    json_validation_error(['_general' => $msg], $msg);
}

// Mark the bill line as capitalized after the capitalize() succeeds.
if ($billLineId !== null) {
    db_update('acc_bill_lines', [
        'capitalize'      => 1,
        'asset_id'        => $assetId,
        'betterment_note' => $note,
    ], 'id = ?', [$billLineId]);
}

json_success($updated);
