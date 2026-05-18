<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/collection_notes/update.php
 *
 * Update editable fields on a collection note: note_date, contact_method,
 * note, outcome, follow_up_date, contact_person. Financial linkage fields
 * (customer_id, invoice_id, created_by) are NOT updatable — they would
 * orphan the note from its source context.
 *
 * @method  POST
 * @body    id (required) + any subset of: note_date, contact_method,
 *          contact_person, note, outcome, follow_up_date
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 { id }
 *          404 if not found
 *          422 if no editable fields provided
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 *
 * Note: acc_collection_notes has no updated_at column — D19 optimistic
 * lock is skipped here. Collection notes are operational records (call
 * logs), not financial records — concurrent edits are low-risk.
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id = clean_int($input['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Collection note ID is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$existing = db_row("SELECT * FROM acc_collection_notes WHERE id = ?", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Collection note not found.', 404);
}

$updates = [];

if (array_key_exists('note_date', $input)) {
    $noteDate = clean_date($input['note_date']);
    if (!$noteDate) {
        $fields['note_date'] = 'Note date is invalid.';
    } else {
        $updates['note_date'] = $noteDate;
    }
}

if (array_key_exists('contact_method', $input)) {
    $contactMethod = clean_string($input['contact_method']);
    $validMethods = ['phone', 'email', 'letter', 'in_person', 'other'];
    if (!$contactMethod || !in_array($contactMethod, $validMethods, true)) {
        $fields['contact_method'] = 'Contact method must be one of: ' . implode(', ', $validMethods) . '.';
    } else {
        $updates['contact_method'] = $contactMethod;
    }
}

if (array_key_exists('contact_person', $input)) {
    $updates['contact_person'] = clean_string($input['contact_person'], 255);
}

if (array_key_exists('note', $input)) {
    $note = clean_string($input['note'], 4000);
    if (!$note) {
        $fields['note'] = 'Note content cannot be empty.';
    } else {
        $updates['note'] = $note;
    }
}

if (array_key_exists('outcome', $input)) {
    $outcome = clean_string($input['outcome']);
    $validOutcomes = ['no_answer', 'left_message', 'spoke_with_customer', 'payment_promised', 'dispute', 'other'];
    if (!$outcome || !in_array($outcome, $validOutcomes, true)) {
        $fields['outcome'] = 'Outcome must be one of: ' . implode(', ', $validOutcomes) . '.';
    } else {
        $updates['outcome'] = $outcome;
    }
}

if (array_key_exists('follow_up_date', $input)) {
    $followUp = $input['follow_up_date'];
    if ($followUp === null || $followUp === '') {
        $updates['follow_up_date'] = null;
    } else {
        $cleanFollow = clean_date($followUp);
        if (!$cleanFollow) {
            $fields['follow_up_date'] = 'Follow-up date is invalid.';
        } else {
            $updates['follow_up_date'] = $cleanFollow;
        }
    }
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error(['_general' => 'No editable fields provided.']);
}

db_transaction(function () use ($id, $updates, $existing) {
    db_update('acc_collection_notes', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'collection_note',
        'entity_id'   => $id,
        'old_values'  => json_encode($existing),
        'new_values'  => json_encode($updates),
        'notes'       => "Collection note #{$id} updated",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
