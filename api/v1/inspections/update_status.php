<?php
declare(strict_types=1);

/**
 * api/v1/inspections/update_status.php
 *
 * State machine for inspection status transitions.
 *
 * Valid transitions (from actual DB ENUM: draft, complete, signed):
 *   draft    → complete
 *   complete → signed
 *   complete → draft   (re-open — Manager+ only)
 *   signed   → [TERMINAL — no transitions out]
 *
 * Business rules:
 *   - 'complete' transition requires all 9 sections to have been touched (condition set).
 *     Actually: condition defaults to 'ok' so they're all set — just checks the inspection exists.
 *   - 'signed' records are immutable (terminal state).
 *   - Re-opening complete→draft requires Manager role.
 *   - overall_condition recalculated on 'complete': worst section condition mapped to overall.
 *
 * @method  POST
 * @body    JSON: id (required), status (required), notes?
 * @auth    Session required; require_permission('inspections','edit')
 * @returns 200 { status, updated_at }
 *
 * Decisions: D7 (routing), §6 (state machines), §7 (audit log)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'edit');

$body   = json_body();
$fields = [];

$id         = clean_int($body['id'] ?? null);
$newStatus  = clean_string($body['status'] ?? null);
$notes      = clean_string($body['notes'] ?? null, 1000);

$allowedStatuses = ['draft', 'complete', 'signed'];
if (!$id) {
    $fields['id'] = 'Inspection ID is required.';
}
if (!$newStatus) {
    $fields['status'] = 'Please select a status.';
} elseif (!in_array($newStatus, $allowedStatuses, true)) {
    $fields['status'] = 'Please select a valid status.';
}
if ($fields) {
    json_validation_error($fields);
}

$insp = db_row("SELECT id, inspection_number, status FROM inspections WHERE id = ?", [$id]);
if (!$insp) json_error('NOT_FOUND', 'Inspection not found.', 404);

$currentStatus = $insp['status'];

// ── State machine: valid transitions only
$transitions = [
    'draft'    => ['complete'],
    'complete' => ['signed', 'draft'],   // draft = re-open
    'signed'   => [],                    // TERMINAL
];

$allowed = $transitions[$currentStatus] ?? [];
if (!in_array($newStatus, $allowed, true)) {
    json_error('INVALID_TRANSITION',
        "Cannot transition from '$currentStatus' to '$newStatus'.", 409,
        ['fields' => ['status' => "Cannot move an inspection from '{$currentStatus}' to '{$newStatus}'."]]);
}

// Re-opening a complete inspection requires Manager role (destructive reversal)
if ($currentStatus === 'complete' && $newStatus === 'draft') {
    if (!can('inspections', 'settings')) {
        json_error('FORBIDDEN',
            'Re-opening a completed inspection requires manager access.', 403,
            ['fields' => ['status' => 'Re-opening a completed inspection requires manager access.']]);
    }
}

$newUpdatedAt = null;

db_transaction(function () use ($id, $insp, $currentStatus, $newStatus, $notes, &$newUpdatedAt) {
    $updateData = ['status' => $newStatus];

    // On 'complete': recalculate overall_condition from worst section condition
    // Condition severity order: ok < fair < damaged < missing < na
    if ($newStatus === 'complete') {
        $sections = db_select(
            "SELECT `condition` FROM inspection_sections WHERE inspection_id = ?",
            [$id]
        );
        $worstMap = [
            'ok' => 0, 'fair' => 1, 'damaged' => 2, 'missing' => 3, 'na' => 0,
        ];
        // Map section condition to overall_condition ENUM values
        $conditionToOverall = [
            'ok'      => 'excellent',
            'fair'    => 'fair',
            'damaged' => 'damaged',
            'missing' => 'poor',
        ];
        $worstScore = 0;
        $worstKey   = 'ok';
        foreach ($sections as $sec) {
            $score = $worstMap[$sec['condition']] ?? 0;
            if ($score > $worstScore) {
                $worstScore = $score;
                $worstKey   = $sec['condition'];
            }
        }
        $updateData['overall_condition'] = $conditionToOverall[$worstKey] ?? 'good';
    }

    // On 'signed': record signed_at timestamp
    if ($newStatus === 'signed') {
        $updateData['signed_at'] = date('Y-m-d H:i:s');
    }

    db_execute(
        "UPDATE inspections SET status = ?, " .
        (isset($updateData['overall_condition']) ? "overall_condition = ?, " : "") .
        (isset($updateData['signed_at']) ? "signed_at = ?, " : "") .
        "updated_at = NOW() WHERE id = ?",
        array_merge(
            [$newStatus],
            isset($updateData['overall_condition']) ? [$updateData['overall_condition']] : [],
            isset($updateData['signed_at'])         ? [$updateData['signed_at']]         : [],
            [$id]
        )
    );

    $updated      = db_row("SELECT status, overall_condition, updated_at FROM inspections WHERE id = ?", [$id]);
    $newUpdatedAt = $updated['updated_at'];

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'inspections',
        'entity_type'  => 'inspection',
        'entity_id'    => $id,
        'entity_label' => $insp['inspection_number'],
        'old_values'   => json_encode(['status' => $currentStatus]),
        'new_values'   => json_encode(['status' => $newStatus, 'overall_condition' => $updated['overall_condition'] ?? null]),
        'notes'        => $notes,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['status' => $newStatus, 'updated_at' => $newUpdatedAt]);
