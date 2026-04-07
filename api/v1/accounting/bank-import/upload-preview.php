<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-import/upload-preview.php
 *
 * Upload a bank CSV file, auto-detect format, parse transactions,
 * detect duplicates, and auto-match against existing records.
 * Returns a preview for user review before confirming import.
 *
 * @method  POST
 * @body    bank_account_id, csv_file (file upload), format? (manual override)
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 200 parsed preview with matches and duplicates flagged
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank statement import)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BankService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

// File uploads come via $_POST/$_FILES; no JSON body here.
$bankAccountId = clean_int($_POST['bank_account_id'] ?? null);
if (!$bankAccountId) {
    json_validation_error(['bank_account_id' => 'Please select a bank account.']);
}

$bankAccount = db_row("SELECT * FROM acc_bank_accounts WHERE id = ? AND is_active = 1", [$bankAccountId]);
if (!$bankAccount) {
    json_error('NOT_FOUND', 'Bank account not found or inactive.', 404, [
        'fields' => ['bank_account_id' => 'Bank account not found or inactive.'],
    ]);
}

// Validate file upload
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

// Validate MIME type — must be CSV/text
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['csv_file']['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
if (!in_array($mime, $allowedMimes, true)) {
    json_validation_error(
        ['csv_file' => "File must be a CSV. Detected: {$mime}"],
        "File must be a CSV. Detected: {$mime}"
    );
}

// Size limit: 5MB
if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
    json_validation_error(
        ['csv_file' => 'CSV file must be under 5MB.'],
        'CSV file must be under 5MB.'
    );
}

// Read CSV content
$csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
if ($csvContent === false || strlen($csvContent) === 0) {
    json_validation_error(
        ['csv_file' => 'CSV file is empty.'],
        'CSV file is empty.'
    );
}

// Detect or use manual format override
$formatKey = clean_string($_POST['format'] ?? null);
if (!$formatKey) {
    // Auto-detect from first line
    $firstLine = strtok($csvContent, "\n");
    $headers = str_getcsv($firstLine, ',', '"', '');
    $formatKey = BankService::detectCsvFormat($headers);
}

if (!$formatKey) {
    // Return available formats so user can pick manually
    $formats = [];
    foreach (BankService::csvFormats() as $key => $fmt) {
        $formats[] = ['key' => $key, 'name' => $fmt['name']];
    }
    json_error('VALIDATION_ERROR',
        'Could not auto-detect bank format. Please select manually.', 422, [
        'fields'            => ['format' => 'Could not auto-detect bank format. Please select manually.'],
        'available_formats' => $formats,
    ]);
}

// Parse CSV
$columnOverrides = null;
if (isset($_POST['column_overrides'])) {
    $columnOverrides = json_decode($_POST['column_overrides'], true);
}

$result = BankService::parseCsv($csvContent, $formatKey, $columnOverrides);

if (!empty($result['errors']) && empty($result['transactions'])) {
    json_validation_error(
        ['csv_file' => 'CSV parsing failed: ' . implode('; ', $result['errors'])],
        'CSV parsing failed: ' . implode('; ', $result['errors'])
    );
}

// Detect duplicates
$result['transactions'] = BankService::detectDuplicates($bankAccountId, $result['transactions']);

// Auto-match
$result['transactions'] = BankService::autoMatch($bankAccountId, $result['transactions']);

// Summary stats
$total = count($result['transactions']);
$duplicates = count(array_filter($result['transactions'], fn($t) => $t['is_duplicate']));
$matched = count(array_filter($result['transactions'], fn($t) => !empty($t['match'])));
$unmatched = $total - $duplicates - $matched;

$result['summary'] = [
    'total_rows'  => $total,
    'duplicates'  => $duplicates,
    'auto_matched'=> $matched,
    'unmatched'   => $unmatched,
    'bank_account'=> $bankAccount['name'],
    'currency'    => $bankAccount['currency'],
];

// Store parsed data in session for confirm step
$_SESSION['bank_import_preview'] = [
    'bank_account_id' => $bankAccountId,
    'format_key'      => $formatKey,
    'transactions'    => $result['transactions'],
    'timestamp'       => time(),
];

json_success($result);
