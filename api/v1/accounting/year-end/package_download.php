<?php
declare(strict_types=1);

/**
 * api/v1/accounting/year-end/package_download.php
 *
 * Streams the year-end package ZIP for a fiscal year. Verifies the
 * SHA-256 hash stored in acc_year_end_closures.package_hash before
 * serving — a mismatch returns 500 so the operator regenerates rather
 * than downloads a tampered file.
 *
 * @method  GET
 * @query   fiscal_year (required)
 * @auth    require_permission('journal_entries','view')
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$fiscalYear = clean_int($_GET['fiscal_year'] ?? null);
if (!$fiscalYear) {
    json_error('VALIDATION_ERROR', 'fiscal_year is required.', 422);
}

$row = db_row(
    "SELECT fiscal_year, package_path, package_hash, status
       FROM acc_year_end_closures
      WHERE fiscal_year = ?",
    [$fiscalYear]
);
if (!$row) {
    json_error('NOT_FOUND', "No year-end closure recorded for FY {$fiscalYear}.", 404);
}
if (empty($row['package_path'])) {
    json_error('PACKAGE_MISSING', 'Package was not generated (or generation failed).', 404);
}

$absPath = FF_ROOT . '/storage/' . ltrim((string) $row['package_path'], '/');
if (!is_file($absPath)) {
    json_error('PACKAGE_FILE_MISSING', 'Package file not found on disk.', 404);
}

// Verify hash before streaming
if (!empty($row['package_hash'])) {
    $currentHash = hash_file('sha256', $absPath);
    if ($currentHash !== $row['package_hash']) {
        json_error('PACKAGE_HASH_MISMATCH', 'Package hash mismatch. File may have been modified. Regenerate.', 500);
    }
}

$filename = basename($absPath);
$size     = filesize($absPath);

// Audit-log the download so we know who pulled the year-end package.
db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'user_name'   => current_user()['name'] ?? 'system',
    'action'      => 'export',
    'module'      => 'accounting',
    'entity_type' => 'year_end_closure',
    'entity_id'   => $fiscalYear,
    'entity_label' => "FY {$fiscalYear} package",
    'notes'       => "Year-end package downloaded: {$filename} ({$size} bytes).",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

// Stream the ZIP
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
readfile($absPath);
exit;
