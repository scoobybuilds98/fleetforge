<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/dunning_letters/pdf.php
 *
 * Open a dunning letter's PDF (S-PDF-LETTERHEAD).
 *
 * The Collections page told staff to "print the PDF to mail it" but gave no
 * way to open it — the letter history listed dates and totals only. This
 * serves the exact PDF stored when the letter was generated. It is NOT
 * re-rendered: a letter is a record of what was sent, and acc_dunning_letters
 * does not keep which invoices it listed, so a re-render from today's data
 * would be a different letter. A missing file gets an explicit message.
 *
 * @method  GET
 * @query   id (int, required), download (0|1, optional)
 * @auth    Session required; require_permission('journal_entries','view')
 *          — the same gate as the letter list (dunning_letters/index.php)
 * @returns application/pdf body; 404 JSON if the letter or its file is gone
 *
 * @session S-PDF-LETTERHEAD
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Pdf\PdfKit;
use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$letterId = clean_int($_GET['id'] ?? null);
if (!$letterId || $letterId <= 0) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$letter = db_row(
    "SELECT dl.id, dl.letter_type, dl.sent_date, dl.pdf_path, c.company_name
       FROM acc_dunning_letters dl
       JOIN customers c ON c.id = dl.customer_id
      WHERE dl.id = ?",
    [$letterId]
);
if (!$letter) {
    json_error('NOT_FOUND', 'Dunning letter not found.', 404);
}

$bytes = null;
if (!empty($letter['pdf_path'])) {
    try {
        $bytes = StorageClient::read((string) $letter['pdf_path']);
    } catch (\Throwable $e) {
        error_log("[dunning_letters/pdf] letter #{$letterId}: " . $e->getMessage());
    }
}
if ($bytes === null || $bytes === '') {
    json_error('FILE_MISSING',
        'The stored copy of this letter is missing from file storage. Generate a new letter from this page to get a fresh PDF.',
        404);
}

PdfKit::stream(
    $bytes,
    'dunning_' . $letter['letter_type'] . '_' . $letter['sent_date'] . '_' . $letter['company_name'],
    ($_GET['download'] ?? '') === '1'
);
