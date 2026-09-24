<?php
declare(strict_types=1);

/**
 * api/v1/portal/documents/file.php
 *
 * Customer portal — open or download one document (S-PORTAL-REDESIGN).
 *
 * WHY: every portal "View" link pointed at api/v1/documents/serve.php, a
 * file that never existed (404). This streams the stored bytes after an
 * ownership check, so the link is a plain GET (no pre-signed URL handed to
 * the page, no pop-up after await).
 *
 * Who may see what (pt_portal_documents_sql, the same rule the Documents
 * page lists with — also fixes a leak where a customer saw every document
 * on any unit they had EVER leased, and private staff documents):
 *   customer documents   entity_type='customer' AND entity_id = the customer
 *   lease documents      the customer's own leases (any status)
 *   equipment documents  only units CURRENTLY on an active lease with them
 *   always               not deleted, not private, current version only
 *
 * @method  GET
 * @query   id (documents.id), download (0|1)
 * @auth    portal session
 * @returns the file (inline unless download=1); 404 JSON otherwise
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_portal_auth_api();

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$cid = portal_customer_id();
[$visibleSql, $visibleParams] = pt_portal_documents_sql($cid);
$doc = db_row(
    "SELECT d.id, d.title, d.file_path, d.file_name, d.mime_type
       FROM documents d
      WHERE d.id = ? AND {$visibleSql}",
    array_merge([$id], $visibleParams)
);
if (!$doc || empty($doc['file_path'])) {
    json_error('NOT_FOUND', 'Document not found.', 404);
}

try {
    $bytes = StorageClient::read((string) $doc['file_path']);
} catch (\Throwable $e) {
    error_log('[portal/documents/file] doc ' . $id . ': ' . $e->getMessage());
    $bytes = null;
}
if ($bytes === null) {
    json_error('FILE_MISSING', 'This file isn\'t available right now. Please ask us to resend it.', 404);
}

$name = (string) ($doc['file_name'] ?: ($doc['title'] ?: 'document'));
$safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'document';
$mime = (string) ($doc['mime_type'] ?: 'application/octet-stream');
// Only formats a browser can show safely are served inline; anything else downloads.
$inlineOk = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'], true);
$download = ($_GET['download'] ?? '') === '1' || !$inlineOk;

if (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: ' . ($inlineOk ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safe . '"');
header('Content-Length: ' . (string) strlen($bytes));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $bytes;
exit;
