<?php
declare(strict_types=1);

/**
 * api/v1/accounting/cca/adjust.php
 *
 * Record a manual adjustment to acc_cca_continuity.adjustments_transfers
 * (spec §23.3, Step 3 — adjustments / inter-class transfers / government
 * grants reducing UCC). Per S-ACCT-CCA-1 these were placeholder 0; this
 * endpoint allows operator override with an audit-trail note.
 *
 * Workflow:
 *   1. Validate row exists for (fiscal_year, cca_class_id) AND is_locked=0.
 *   2. UPDATE adjustments_transfers on the existing row.
 *   3. Trigger CcaService::compute(year, userId, recompute=true) — downstream
 *      steps (Step 5 ucc_after, Steps 6-12) depend on adjustments_transfers
 *      so a recompute is the only correctness-preserving path.
 *   4. Return refreshed schedule.
 *
 * Locked-year guard: returns 422 (not 423) for clarity — manual adjustment
 * requires unlock, not just a retry.
 *
 * @method  POST
 * @body    JSON: fiscal_year (required), cca_class_id (required),
 *                adjustments_transfers (decimal string, required, may be negative),
 *                adjustment_note (text, required ≥ 5 chars — audit trail).
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { fiscal_year, rows[] } refreshed via getSchedule
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.3 (Step 3)
 * Session: S-ACCT-CCA-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\CcaService;

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body          = json_body();
$fiscalYear    = clean_int($body['fiscal_year'] ?? null);
$ccaClassId    = clean_int($body['cca_class_id'] ?? null);
$adjustments   = clean_decimal($body['adjustments_transfers'] ?? null);
$adjustmentNote = is_string($body['adjustment_note'] ?? null) ? trim($body['adjustment_note']) : '';

$errors = [];
if (!$fiscalYear)                      $errors['fiscal_year'] = 'fiscal_year is required.';
if (!$ccaClassId)                      $errors['cca_class_id'] = 'cca_class_id is required.';
if ($adjustments === null || $adjustments === '') {
    $errors['adjustments_transfers'] = 'adjustments_transfers is required.';
}
if (strlen($adjustmentNote) < 5) {
    $errors['adjustment_note'] = 'adjustment_note is required and must be at least 5 characters (audit trail).';
}
if ($errors) {
    json_validation_error($errors);
}

// Guard 1: row must exist for (fiscal_year, cca_class_id).
$existing = db_row(
    "SELECT cont.id, cont.is_locked, cls.class_number
       FROM acc_cca_continuity cont
       JOIN acc_cca_classes cls ON cls.id = cont.cca_class_id
      WHERE cont.fiscal_year = ? AND cont.cca_class_id = ?",
    [$fiscalYear, $ccaClassId]
);
if (!$existing) {
    json_error('NOT_FOUND', "No CCA continuity row for FY{$fiscalYear} / class id {$ccaClassId}. Run Compute first.", 404);
}

// Guard 2: locked year cannot be adjusted (STOP CONDITION).
if ((int) $existing['is_locked'] === 1) {
    json_error(
        'VALIDATION_ERROR',
        'CCA schedule is locked. Unlock (super_admin) before making adjustments.',
        422
    );
}

$userId   = current_user_id();
$classNum = $existing['class_number'];

try {
    db_transaction(function () use ($fiscalYear, $ccaClassId, $adjustments, $adjustmentNote, $userId, $classNum) {
        db_execute(
            "UPDATE acc_cca_continuity
                SET adjustments_transfers = ?
              WHERE fiscal_year = ? AND cca_class_id = ?",
            [$adjustments, $fiscalYear, $ccaClassId]
        );
        db_insert('audit_log', [
            'user_id'     => $userId,
            'action'      => 'update',
            'module'      => 'accounting',
            'entity_type' => 'cca_continuity',
            'entity_id'   => $fiscalYear,
            'notes'       => "Manual adjustment: class {$classNum} FY{$fiscalYear}: {$adjustmentNote}. adjustments_transfers set to {$adjustments}.",
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    });

    // Recompute outside the transaction — compute() opens its own
    // db_transaction() with an advisory lock. Calling it nested would still
    // work but the outer audit-log write commits cleanly first.
    CcaService::compute($fiscalYear, $userId, true);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success(CcaService::getSchedule($fiscalYear));
