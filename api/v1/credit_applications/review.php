<?php
declare(strict_types=1);

/**
 * FleetForge — Credit Application Review API
 *
 * @file        api/v1/credit_applications/review.php
 * @description Records an admin review decision (approve/decline/needs_info) on a
 *              submitted credit application. Optionally writes the approved credit
 *              limit and/or status back to the customers row — both behind explicit
 *              opt-in checkboxes (D-CCA-4: no value is EVER auto-copied).
 *
 *              Both the application row and any customer row updates run in a
 *              single transaction, each with its own optimistic-lock check and
 *              its own audit_log row.
 *
 *              Review is allowed on status='submitted' OR status='reviewed' (re-review
 *              of an already-reviewed row is permitted and audit-logged — D-CCA-4-A).
 *
 * @method      POST
 * @body        JSON {
 *                id:                    int     — customer_credit_applications.id
 *                updated_at:            string  — CCA row optimistic lock token
 *                review_outcome:        string  — approved|declined|needs_info
 *                review_notes:          ?string — internal admin notes
 *                approved_credit_limit: ?number — required when apply_credit_limit=true
 *                apply_credit_limit:    ?bool   — default false; write credit limit to customers row
 *                apply_customer_status: ?bool   — default false; write status to customers row
 *                customer_status:       ?string — required when apply_customer_status=true
 *                customer_updated_at:   ?string — customers row optimistic lock (required if either apply_ is true)
 *              }
 * @auth        Session required; require_permission('customers','edit') — D-CCA-4-A / D-CCA-PERM
 * @returns     200 { id, status, review_outcome, review_notes, approved_credit_limit,
 *                    reviewed_at, reviewed_by_name, customers_updated }
 *              400 on validation errors
 *              403 if permission denied
 *              404 if application not found
 *              409 STALE_DATA on optimistic-lock conflict
 *              409 INVALID_TRANSITION if not submitted/reviewed
 *
 * @depends     api/bootstrap.php
 * @spec        D-CCA-4, D-CCA-4-A..E
 * @session     S-CCA-4
 */

// dirname(__DIR__, 3): api/v1/credit_applications/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

$body = json_body();

// ── Validate required fields ────────────────────────────────────────────────
$fields = [];

$appId = clean_int($body['id'] ?? null);
if (!$appId || $appId <= 0) {
    $fields['id'] = 'A valid application ID is required.';
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null, 30);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token (updated_at) is required.';
}

$reviewOutcome = clean_string($body['review_outcome'] ?? null, 20);
$validOutcomes = ['approved', 'declined', 'needs_info'];
if (!in_array($reviewOutcome, $validOutcomes, true)) {
    $fields['review_outcome'] = 'review_outcome must be one of: approved, declined, needs_info.';
}

$reviewNotes = clean_string($body['review_notes'] ?? null, 5000);
if ($reviewNotes === '') { $reviewNotes = null; }

// approved_credit_limit: numeric, positive, required if apply_credit_limit is true
$applyLimit        = !empty($body['apply_credit_limit']);
$approvedLimit     = null;
if (isset($body['approved_credit_limit']) && $body['approved_credit_limit'] !== null && $body['approved_credit_limit'] !== '') {
    $approvedLimit = clean_positive_decimal($body['approved_credit_limit']);
    if ($approvedLimit === null) {
        $fields['approved_credit_limit'] = 'Approved credit limit must be a positive number.';
    }
}
if ($applyLimit && $approvedLimit === null && !isset($fields['approved_credit_limit'])) {
    $fields['approved_credit_limit'] = 'Approved credit limit is required when "Apply credit limit" is checked.';
}

// Customer row opt-in fields
$applyStatus     = !empty($body['apply_customer_status']);
$newCustStatus   = clean_string($body['customer_status'] ?? null, 30);
$validCustStatus = ['active', 'inactive', 'pending', 'suspended', 'credit_hold'];
if ($applyStatus) {
    if (!in_array($newCustStatus, $validCustStatus, true)) {
        $fields['customer_status'] = 'customer_status must be one of: ' . implode(', ', $validCustStatus) . '.';
    }
}

$customerUpdatedAt = clean_string($body['customer_updated_at'] ?? null, 30);
if (($applyLimit || $applyStatus) && !$customerUpdatedAt) {
    $fields['customer_updated_at'] = 'customer_updated_at (optimistic lock) is required when updating the customer record.';
}

if ($fields) {
    json_validation_error($fields);
}

// ── Load the application row ────────────────────────────────────────────────
$app = db_row(
    "SELECT ca.id, ca.customer_id, ca.status, ca.updated_at,
            c.company_name AS customer_company_name,
            ca.review_outcome AS old_outcome
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
      WHERE ca.id = ? AND ca.deleted_at IS NULL",
    [$appId]
);

if (!$app) {
    json_error('NOT_FOUND', 'Credit application not found.', 404);
}

// ── Status gate: only submitted or reviewed ─────────────────────────────────
if (!in_array($app['status'], ['submitted', 'reviewed'], true)) {
    json_error('INVALID_TRANSITION',
        'Review is only allowed on submitted or reviewed applications.', 409);
}

// ── Optimistic lock — application row ──────────────────────────────────────
if (!optimistic_lock_matches($submittedUpdatedAt, $app['updated_at'])) {
    json_error('STALE_DATA',
        'This application was modified by another user. Refresh and try again.', 409);
}

$customerId = (int) $app['customer_id'];
$userId     = current_user_id();
$userName   = current_user()['name'] ?? 'unknown';
$now        = date('Y-m-d H:i:s');

// ── If touching the customer row, load it now (outside txn) for lock check ─
$custRow = null;
if ($applyLimit || $applyStatus) {
    $custRow = db_row(
        "SELECT id, company_name, credit_limit, status, updated_at FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$customerId]
    );
    if (!$custRow) {
        json_error('NOT_FOUND', 'Associated customer record not found.', 404);
    }
    // Optimistic lock check for the customer row
    if (!optimistic_lock_matches($customerUpdatedAt, $custRow['updated_at'])) {
        json_error('STALE_DATA',
            'The customer record was modified by another user. Refresh and try again.', 409);
    }
}

// ── Build the application update payload ────────────────────────────────────
$appUpdate = [
    'review_outcome'         => $reviewOutcome,
    'review_notes'           => $reviewNotes,
    'reviewed_at'            => $now,
    'reviewed_by'            => $userId,
    'status'                 => 'reviewed',
];
// approved_credit_limit is stored whenever provided (even for non-approved outcomes)
// but only applied to customers when apply_credit_limit is checked.
if ($approvedLimit !== null) {
    $appUpdate['approved_credit_limit'] = $approvedLimit;
}

$customersUpdated = false;

db_transaction(function () use (
    $appId, $appUpdate, $customerId, $userId, $userName, $now,
    $app, $custRow, $applyLimit, $applyStatus, $approvedLimit,
    $newCustStatus, $reviewOutcome, $reviewNotes, &$customersUpdated
): void {
    // 1. Update the application row
    db_update('customer_credit_applications', $appUpdate, 'id = ?', [$appId]);

    // 2. Audit log — credit application review
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $userName,
        'action'       => 'update',
        'module'       => 'customers',
        'entity_type'  => 'credit_application',
        'entity_id'    => $appId,
        'entity_label' => 'Credit Application #' . $appId . ' — ' . ($app['customer_company_name'] ?? ''),
        'old_values'   => json_encode([
            'status'         => $app['status'],
            'review_outcome' => $app['old_outcome'],
        ]),
        'new_values'   => json_encode([
            'status'                 => 'reviewed',
            'review_outcome'         => $reviewOutcome,
            'approved_credit_limit'  => $approvedLimit,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    // 3. Optional: write approved_credit_limit → customers.credit_limit
    if ($applyLimit && $approvedLimit !== null) {
        $oldLimit = $custRow['credit_limit'];
        db_update('customers',
            ['credit_limit' => $approvedLimit, 'updated_by' => $userId],
            'id = ?', [$customerId]
        );
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'customers',
            'entity_type'  => 'customer',
            'entity_id'    => $customerId,
            'entity_label' => $custRow['company_name'] ?? ($app['customer_company_name'] ?? ''),
            'old_values'   => json_encode(['credit_limit' => $oldLimit]),
            'new_values'   => json_encode(['credit_limit' => $approvedLimit]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            'notes'        => 'Credit limit applied from credit application #' . $appId,
        ]);
        $customersUpdated = true;
    }

    // 4. Optional: write customer status
    if ($applyStatus && $newCustStatus) {
        $oldStatus = $custRow['status'];
        $custData  = ['status' => $newCustStatus, 'updated_by' => $userId];
        // If we already wrote credit_limit above, updated_at was already bumped —
        // the customers row is still valid within the same transaction.
        db_update('customers', $custData, 'id = ?', [$customerId]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'customers',
            'entity_type'  => 'customer',
            'entity_id'    => $customerId,
            'entity_label' => $custRow['company_name'] ?? ($app['customer_company_name'] ?? ''),
            'old_values'   => json_encode(['status' => $oldStatus]),
            'new_values'   => json_encode(['status' => $newCustStatus]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            'notes'        => 'Status set from credit application #' . $appId . ' review',
        ]);
        $customersUpdated = true;
    }
});

// ── Return the updated application data ────────────────────────────────────
$updated = db_row(
    "SELECT ca.id, ca.status, ca.review_outcome, ca.review_notes,
            ca.approved_credit_limit, ca.reviewed_at, ca.updated_at,
            u.name AS reviewed_by_name
       FROM customer_credit_applications ca
       LEFT JOIN users u ON u.id = ca.reviewed_by
      WHERE ca.id = ?",
    [$appId]
);

json_success([
    'id'                     => $appId,
    'status'                 => $updated['status'] ?? 'reviewed',
    'review_outcome'         => $updated['review_outcome'] ?? $reviewOutcome,
    'review_notes'           => $updated['review_notes'],
    'approved_credit_limit'  => $updated['approved_credit_limit'],
    'reviewed_at'            => $updated['reviewed_at'],
    'reviewed_by_name'       => $updated['reviewed_by_name'],
    'updated_at'             => $updated['updated_at'],
    'customers_updated'      => $customersUpdated,
]);
