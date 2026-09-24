<?php
declare(strict_types=1);

/**
 * FleetForge — credit application PDF (S-PDF-LETTERHEAD)
 *
 * @file        lib/Pdf/CreditApplicationPdf.php
 * @description Turns a submitted credit application into its PDF, and makes
 *              sure a PDF can always be served.
 *
 *              Source of truth is customer_credit_applications.rendered_html —
 *              the frozen snapshot taken at submit (D-CCA-3/6), which already
 *              embeds the drawn signature as a data URI. The PDF is that
 *              snapshot on the shared letterhead: the snapshot's own dark
 *              title bar is swapped for PdfKit's logo band, its page wrapper
 *              is flattened for print, and every field, the signature, the
 *              disclaimer and the legal footer print untouched. The snapshot
 *              itself is never modified.
 *
 *              bytes() serves the stored PDF when it still exists and
 *              otherwise rebuilds it from the snapshot and stores it again —
 *              production lost every file saved before storage moved to S3,
 *              so application #2's "Download PDF" was a dead S3 link (404).
 *
 * Required by: api/v1/credit_applications/pdf.php, api/v1/portal/credit_applications/pdf.php,
 *              api/v1/credit_applications/generate_pdf.php, app/admin/credit-application.php
 * Defines:     FleetForge\Pdf\CreditApplicationPdf
 *
 * Decisions: D-CCA-3 (rendered_html is the frozen record), D-PDF-LETTERHEAD-1
 * @session S-PDF-LETTERHEAD
 */

namespace FleetForge\Pdf;

use FleetForge\Storage\StorageClient;

final class CreditApplicationPdf
{
    private function __construct() {}

    /**
     * Render PDF bytes from a rendered_html snapshot.
     *
     * @param string $renderedHtml the stored snapshot (full HTML document)
     * @param array{id:int, company:string, submitted_at:?string} $info
     */
    public static function fromSnapshot(string $renderedHtml, array $info): string
    {
        // The snapshot is a complete HTML document; mPDF needs the pieces.
        $css = '';
        if (preg_match_all('#<style[^>]*>(.*?)</style>#si', $renderedHtml, $m)) {
            $css = implode("\n", $m[1]);
        }
        $body = preg_match('#<body[^>]*>(.*)</body>#si', $renderedHtml, $b) ? $b[1] : $renderedHtml;

        // Swap the snapshot's own title bar for the letterhead band.
        $body = (string) preg_replace(
            '#<div class="hdr-bar">\s*<div class="co">.*?</div>\s*<div class="sub">.*?</div>\s*</div>#si',
            '',
            $body
        );
        $body = str_replace('<div class="hdr-accent"></div>', '', $body);

        // Its body/page-wrap rules set a web font and a 24px frame that would
        // double the print margins; the overrides come AFTER in the cascade.
        $css = (string) preg_replace('#(^|\})\s*body\s*\{[^}]*\}#s', '$1', $css);
        $css .= "\n.page-wrap { max-width: none; margin: 0; padding: 0; }\n"
              . ".meta-bar { font-size: 8pt; }\n"
              . ".sig-block img { max-height: 25mm; }\n"
              // Older snapshots carry the brand colour of their day (June's
              // were orange); print them in today's accent.
              . '.section-title { border-left: 3px solid ' . PdfKit::brand()['accent'] . "; }\n";

        $submitted = (string) ($info['submitted_at'] ?? '');
        $meta = ['Application no.' => '#' . (int) $info['id']];
        if ($submitted !== '') {
            $meta['Submitted'] = \format_datetime($submitted, 'M j, Y');
        }

        return PdfKit::render($body, [
            'title'     => 'Credit Application',
            'reference' => (string) ($info['company'] ?? ''),
            'meta'      => $meta,
            'css'       => $css,
        ]);
    }

    /**
     * The application's PDF bytes — the stored file when present, otherwise
     * rebuilt from the snapshot (and stored again for next time).
     *
     * @return array{bytes:string, filename:string}
     * @throws \InvalidArgumentException application not found
     * @throws \DomainException          not submitted yet / no snapshot to build from
     */
    public static function bytes(int $appId, ?int $userId = null): array
    {
        $app = \db_row(
            "SELECT ca.id, ca.customer_id, ca.status, ca.rendered_html, ca.generated_pdf_document_id,
                    ca.submitted_at, ca.signed_date, c.company_name
               FROM customer_credit_applications ca
               JOIN customers c ON c.id = ca.customer_id
              WHERE ca.id = ? AND ca.deleted_at IS NULL",
            [$appId]
        );
        if (!$app) {
            throw new \InvalidArgumentException("Credit application #{$appId} not found.");
        }
        $filename = PdfKit::filename('credit_application_' . $appId . '_' . $app['company_name']);

        // 1. The stored copy, if storage still has it AND it is in the
        //    current layout (file name ends _v{LAYOUT}.pdf — see layoutKey()).
        //    An older-layout copy is rebuilt so every PDF looks the same; the
        //    content is identical either way (both come from the snapshot).
        $doc = null;
        if ($app['generated_pdf_document_id'] !== null) {
            $doc = \db_row(
                "SELECT id, file_path FROM documents WHERE id = ? AND deleted_at IS NULL",
                [(int) $app['generated_pdf_document_id']]
            );
            if ($doc && !empty($doc['file_path']) && str_ends_with((string) $doc['file_path'], '_v' . PdfKit::LAYOUT . '.pdf')) {
                try {
                    $stored = StorageClient::read((string) $doc['file_path']);
                    if ($stored !== null && $stored !== '') {
                        return ['bytes' => $stored, 'filename' => $filename];
                    }
                } catch (\Throwable $e) {
                    error_log("[CreditApplicationPdf] stored PDF unreadable for #{$appId}: " . $e->getMessage());
                }
            }
        }

        // 2. Rebuild from the frozen snapshot.
        $snapshot = (string) ($app['rendered_html'] ?? '');
        if (!in_array($app['status'], ['submitted', 'reviewed'], true) || $snapshot === '') {
            throw new \DomainException('This application has not been submitted, so there is no PDF yet.');
        }
        $bytes = self::fromSnapshot($snapshot, [
            'id'           => $appId,
            'company'      => (string) $app['company_name'],
            'submitted_at' => $app['submitted_at'],
        ]);

        // 3. Store it so the next open is a plain read. Failure here must not
        //    stop the person in front of the screen getting their PDF.
        try {
            self::store($app, $doc, $bytes, $userId);
        } catch (\Throwable $e) {
            error_log("[CreditApplicationPdf] re-store failed for #{$appId}: " . $e->getMessage());
        }

        return ['bytes' => $bytes, 'filename' => $filename];
    }

    /**
     * Storage key for a PDF in the current layout. The _v{LAYOUT} suffix is
     * how bytes() tells a current copy from an older design.
     */
    public static function layoutKey(int $appId): string
    {
        return 'credit_applications/' . $appId . '/credit_application_' . date('Ymd_His') . '_v' . PdfKit::LAYOUT . '.pdf';
    }

    /**
     * Upload the PDF and point the application at it — repairing the
     * existing documents row when there is one (its file went missing),
     * creating one otherwise.
     *
     * @param array<string,mixed>      $app
     * @param array<string,mixed>|null $doc existing documents row, if any
     */
    private static function store(array $app, ?array $doc, string $bytes, ?int $userId): void
    {
        $appId = (int) $app['id'];
        $tmp = tempnam(sys_get_temp_dir(), 'ff_cca_pdf_');
        if ($tmp === false) {
            throw new \RuntimeException('no temp file');
        }
        try {
            file_put_contents($tmp, $bytes);
            $key = StorageClient::upload($tmp, self::layoutKey($appId));
        } finally {
            @unlink($tmp);
        }

        $sizeKb = (int) ceil(strlen($bytes) / 1024);
        if ($doc) {
            \db_execute(
                "UPDATE documents SET file_path = ?, file_name = ?, file_size_kb = ? WHERE id = ?",
                [$key, basename($key), $sizeKb, (int) $doc['id']]
            );
            $docId = (int) $doc['id'];
        } else {
            $label = $app['signed_date'] ?: \ff_today();
            $docId = \db_insert('documents', [
                'entity_type'   => 'customer',
                'entity_id'     => (int) $app['customer_id'],
                'document_type' => 'credit_application',
                'title'         => substr('Credit Application — ' . $app['company_name'] . ' (' . $label . ')', 0, 255),
                'file_path'     => $key,
                'file_name'     => basename($key),
                'file_size_kb'  => $sizeKb,
                'mime_type'     => 'application/pdf',
                'uploaded_by'   => $userId,
            ]);
            \db_execute(
                "UPDATE customer_credit_applications SET generated_pdf_document_id = ? WHERE id = ?",
                [$docId, $appId]
            );
        }

        \db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userId ? (string) (\db_row("SELECT name FROM users WHERE id = ?", [$userId])['name'] ?? 'user') : 'system',
            'action'       => 'update',
            'module'       => 'customers',
            'entity_type'  => 'credit_application',
            'entity_id'    => $appId,
            'entity_label' => 'Credit Application #' . $appId . ' — PDF rebuilt from snapshot',
            'new_values'   => json_encode(['generated_pdf_document_id' => $docId]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
}
