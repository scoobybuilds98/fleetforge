<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_bulk_resolve.php
 *
 * Bulk accept or suppress all OPEN drift events of a given category
 * (optionally narrowed by entity_type). The §15.6 "Mark all drifts of
 * category X" slice. Only accept + suppress are supported in bulk —
 * bulk-resolve and bulk-resync stay out of scope (resync is per-event
 * convenience; bulk force-resync is the S-QBO-26 Manual Sync page).
 *
 * Permission: require_permission('quickbooks','edit_credentials') —
 * this is a policy decision that hides events from the default view
 * (suppress) or marks them as known-accepted divergence (accept), and
 * both states are TERMINAL (recordDrift will never re-flag).
 *
 * Transactional: wraps the bulk UPDATE + audit_log INSERT in a single
 * db_transaction. On any failure both roll back together.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'edit_credentials').
 * @body    JSON: { category: string, action: 'accept'|'suppress', resolution_note: string, entity_type?: string }
 * @returns 200 { action, category, entity_type, affected: int }
 *        | 400 BAD_REQUEST
 *        | 403 FORBIDDEN
 *        | 422 VALIDATION_ERROR
 *
 * Spec ref: §15.6 (manual reconciliation — bulk-accept slice)
 * Session:  S-QBO-25
 * Decision: D-QBO-25-1 (resolution_type state machine),
 *           D-QBO-25-3 (bulk scope: accept+suppress here; force-resync/pull → S-QBO-26)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

$body         = json_body();
$category     = isset($body['category']) ? trim((string) $body['category']) : '';
$action       = isset($body['action']) ? strtolower(trim((string) $body['action'])) : '';
$note         = isset($body['resolution_note']) ? trim((string) $body['resolution_note']) : '';
$entityType   = isset($body['entity_type']) ? trim((string) $body['entity_type']) : '';

// Whitelist guards — never trust client-supplied ENUM values directly into SQL.
$allowedCategories = [
    'count_mismatch', 'field_mismatch', 'missing_in_qbo', 'missing_in_ff',
    'amount_drift', 'balance_drift', 'push_failed', 'pull_failed',
    'stale_object_unresolved',
];
$allowedActions = ['accept', 'suppress'];
$allowedEntityTypes = [
    'invoice', 'payment', 'bill', 'bill_payment', 'credit_memo',
    'journal_entry', 'customer', 'vendor',
];

if (!in_array($action, $allowedActions, true)) {
    json_error('BAD_REQUEST', "Unknown action '{$action}'. Allowed: " . implode(', ', $allowedActions), 400);
}
if (!in_array($category, $allowedCategories, true)) {
    json_validation_error(['category' => 'Provide a valid category from the 9-value ENUM.']);
}
if ($entityType !== '' && !in_array($entityType, $allowedEntityTypes, true)) {
    json_validation_error(['entity_type' => 'Provide a valid entity_type or omit.']);
}
if (strlen($note) < 5) {
    json_validation_error(['resolution_note' => 'Resolution note required (min 5 chars).']);
}
if (strlen($note) > 500) {
    $note = substr($note, 0, 497) . '...';
}

$resolutionType = $action; // 'accept' → 'accepted', 'suppress' → 'suppressed'
if ($action === 'accept') {
    $resolutionType = 'accepted';
} elseif ($action === 'suppress') {
    $resolutionType = 'suppressed';
}

try {
    $user   = current_user();
    $userId = $user['id'] ?? null;
    if (!$userId) {
        json_error('FORBIDDEN', 'No authenticated user.', 403);
    }

    $bulkNote = "bulk-{$action} {$category}"
              . ($entityType !== '' ? " ({$entityType})" : '')
              . ': ' . substr($note, 0, 400);

    $affected = 0;
    db_transaction(function () use ($category, $entityType, $resolutionType, $userId, $bulkNote, &$affected) {
        // Build the WHERE for the bulk update — always restrict to OPEN
        // events (resolved_at IS NULL AND resolution_type IS NULL) so we
        // don't accidentally re-tag already-resolved rows.
        $where   = ['resolved_at IS NULL', 'resolution_type IS NULL', 'category = ?'];
        $params  = [$resolutionType, $userId, $bulkNote, $category];
        if ($entityType !== '') {
            $where[]  = 'entity_type = ?';
            $params[] = $entityType;
        }
        $whereSql = implode(' AND ', $where);

        $affected = (int) db_execute(
            "UPDATE acc_qbo_drift_events
                SET resolved_at         = NOW(),
                    resolution_type     = ?,
                    resolved_by_user_id = ?,
                    resolution_note     = ?
              WHERE {$whereSql}",
            $params
        );
    });

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => $user['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_drift_bulk',
        'entity_id'    => null,
        'entity_label' => "bulk-{$action} category={$category}" . ($entityType !== '' ? " entity_type={$entityType}" : ''),
        'notes'        => "Bulk {$resolutionType} affected {$affected} drift events. Op note: " . substr($note, 0, 400),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success([
        'action'      => $resolutionType,
        'category'    => $category,
        'entity_type' => $entityType !== '' ? $entityType : null,
        'affected'    => $affected,
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Bulk resolve failed: ' . $e->getMessage(), 500);
}
