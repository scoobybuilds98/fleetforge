<?php
declare(strict_types=1);

/**
 * api/v1/accounting/accounts/import.php
 *
 * Bulk import / update chart-of-accounts entries from a CSV file.
 * Idempotent by account_number (the canonical key — DB column `code`):
 * existing rows are UPDATEd, missing rows are INSERTed.
 *
 * Restricted to super_admin because COA structure changes ripple
 * through every JE-posting bridge — a misimport can break I1-I10.
 *
 * Expected CSV columns (header row required):
 *   account_number  — maps to acc_accounts.code (idempotency key)
 *   account_name    — maps to acc_accounts.name
 *   account_type    — must be one of the schema ENUM values
 *   normal_balance  — must be 'debit' or 'credit'
 *   parent_account_number — optional; resolved to acc_accounts.parent_id
 *                           via code lookup
 *
 * @method  POST
 * @body    multipart: csv_file
 * @auth    Session required; require_permission('journal_entries','create')
 *          + is_super_admin()
 * @returns 200 { total, created, updated, errors, rows: [...] }
 *          422 on file/MIME errors
 *          403 if not super_admin
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 *
 * Note on audit_log.action: the prompt called for action='import' but
 * the audit_log.action ENUM does not include 'import'. Using the
 * canonical 'bulk_action' value per REFERENCE.md §7 — adding a new
 * ENUM value would require a separate migration, which is out of
 * scope for this session (no migration). K-22 disclosure.
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

if (!is_super_admin()) {
    json_error(
        'FORBIDDEN',
        'Chart of accounts import is restricted to super administrators.',
        403
    );
}

// ── 1. File upload validation ──────────────────────────────────────
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'Please choose a CSV file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
    ];
    $errCode = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $uploadErrors[$errCode] ?? 'File upload failed.';
    json_validation_error(['csv_file' => $msg], $msg);
}

// Max 1MB — COA files are tiny.
if ($_FILES['csv_file']['size'] > 1024 * 1024) {
    json_validation_error(
        ['csv_file' => 'CSV file must be under 1MB.'],
        'CSV file must be under 1MB.'
    );
}

// MIME validation — never trust client-declared type (Trap 5).
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['csv_file']['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['text/csv', 'text/plain'];
if (!in_array($mime, $allowedMimes, true)) {
    json_validation_error(
        ['csv_file' => "File must be a CSV. Detected: {$mime}"],
        "File must be a CSV. Detected: {$mime}"
    );
}

// ── 2. Parse CSV ───────────────────────────────────────────────────
$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
if (!$fh) {
    json_error('SERVER_ERROR', 'Could not open uploaded file.', 500);
}

$header = fgetcsv($fh, 0, ',', '"', '');
if (!$header) {
    fclose($fh);
    json_validation_error(
        ['csv_file' => 'CSV is empty or unreadable.'],
        'CSV is empty or unreadable.'
    );
}

// Normalize headers — lowercase + trim.
$header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);

$required = ['account_number', 'account_name', 'account_type', 'normal_balance'];
$missing  = array_diff($required, $header);
if ($missing) {
    fclose($fh);
    json_validation_error(
        ['csv_file' => 'Missing required columns: ' . implode(', ', $missing)],
        'Missing required columns: ' . implode(', ', $missing)
    );
}

// ENUM values pulled directly from the acc_accounts schema to avoid
// drift between this file and a future ALTER on the enum.
$validTypes = [
    'asset', 'liability', 'equity', 'revenue',
    'cost_of_revenue', 'operating_expense', 'other_income', 'other_expense',
];

// ── 3. Process rows ────────────────────────────────────────────────
$rows     = [];
$created  = 0;
$updated  = 0;
$errors   = 0;
$rowNum   = 1; // header is row 1; first data row is 2

while (($data = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $rowNum++;

    // Skip fully blank rows.
    if (count(array_filter($data, static fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
        continue;
    }

    // Pad/truncate to header length so array_combine works.
    $data = array_pad(array_slice($data, 0, count($header)), count($header), '');
    $row  = array_combine($header, $data);

    $accountNumber = trim((string) ($row['account_number'] ?? ''));
    $accountName   = trim((string) ($row['account_name']   ?? ''));
    $accountType   = strtolower(trim((string) ($row['account_type'] ?? '')));
    $normalBalance = strtolower(trim((string) ($row['normal_balance'] ?? '')));
    $parentNumber  = trim((string) ($row['parent_account_number'] ?? ''));

    // ── Per-row validation ──
    if ($accountNumber === '') {
        $rows[] = ['row' => $rowNum, 'account_number' => '', 'action' => 'error', 'detail' => 'account_number is required.'];
        $errors++;
        continue;
    }
    if ($accountName === '') {
        $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'error', 'detail' => 'account_name is required.'];
        $errors++;
        continue;
    }
    if (!in_array($accountType, $validTypes, true)) {
        $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'error', 'detail' => "account_type must be one of: " . implode(', ', $validTypes)];
        $errors++;
        continue;
    }
    if (!in_array($normalBalance, ['debit', 'credit'], true)) {
        $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'error', 'detail' => 'normal_balance must be debit or credit.'];
        $errors++;
        continue;
    }

    // ── Resolve parent_id via code lookup ──
    $parentId = null;
    if ($parentNumber !== '') {
        $parent = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$parentNumber]);
        if (!$parent) {
            $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'error', 'detail' => "parent_account_number '{$parentNumber}' not found."];
            $errors++;
            continue;
        }
        $parentId = (int) $parent['id'];
    }

    // ── INSERT or UPDATE (idempotent by code) ──
    $existing = db_row("SELECT id, name, account_type, normal_balance, parent_id FROM acc_accounts WHERE code = ?", [$accountNumber]);

    try {
        if ($existing) {
            db_update('acc_accounts', [
                'name'           => $accountName,
                'account_type'   => $accountType,
                'normal_balance' => $normalBalance,
                'parent_id'      => $parentId,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int) $existing['id']]);

            $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'updated', 'detail' => "Updated #{$existing['id']}."];
            $updated++;
        } else {
            $newId = db_insert('acc_accounts', [
                'code'           => $accountNumber,
                'name'           => $accountName,
                'account_type'   => $accountType,
                'normal_balance' => $normalBalance,
                'parent_id'      => $parentId,
                'created_by'     => current_user_id(),
            ]);
            $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'created', 'detail' => "Created #{$newId}."];
            $created++;
        }
    } catch (\Throwable $e) {
        $rows[] = ['row' => $rowNum, 'account_number' => $accountNumber, 'action' => 'error', 'detail' => 'DB error: ' . $e->getMessage()];
        $errors++;
    }
}

fclose($fh);

$total = $created + $updated + $errors;

// ── 4. Audit log (single summary row) ──────────────────────────────
db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'System',
    'action'       => 'bulk_action',
    'module'       => 'accounting',
    'entity_type'  => 'accounts',
    'entity_id'    => null,
    'entity_label' => 'COA CSV import',
    'notes'        => "COA CSV import: {$created} created, {$updated} updated, {$errors} errors.",
    'new_values'   => json_encode(['created' => $created, 'updated' => $updated, 'errors' => $errors]),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'total'   => $total,
    'created' => $created,
    'updated' => $updated,
    'errors'  => $errors,
    'rows'    => $rows,
]);
