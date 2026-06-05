<?php
declare(strict_types=1);

/**
 * app/admin/credit_applications/snapshot.php
 *
 * Serves the stored rendered_html for a submitted credit application as a
 * standalone HTML document. Used as the iframe src in show.php so the
 * frozen form renders without srcdoc size/encoding limitations.
 *
 * Security:
 *   - Auth-gated: customers:view required.
 *   - Only serves submitted or reviewed applications (status gate).
 *   - Strict CSP: blocks all external resources; allows only inline styles
 *     and data: URIs (for the embedded signature image, D-CCA-3-C).
 *   - X-Frame-Options: SAMEORIGIN prevents embedding outside FF admin.
 *   - Trap 7: rendered_html is trusted server-generated output, never raw
 *     user input. token_hash / signature_path / form_data never emitted here.
 *
 * @method  GET
 * @query   id (int) — customer_credit_applications.id
 * @auth    Session; customers:view
 * @returns text/html — the stored rendered_html snapshot
 *          404 — application not found, wrong status, or no snapshot stored
 *
 * @session S-CCA-3
 */

// dirname(__DIR__, 3): app/admin/credit_applications/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

$appId = clean_int($_GET['id'] ?? null);
if (!$appId || $appId <= 0) {
    http_response_code(404);
    exit;
}

// Only serve snapshots for submitted/reviewed applications (D-CCA-3-B rule).
// Trap 7: SELECT only rendered_html — no token_hash, signature_path, form_data.
$app = db_row(
    "SELECT rendered_html
       FROM customer_credit_applications
      WHERE id = ? AND deleted_at IS NULL
        AND status IN ('submitted', 'reviewed')",
    [$appId]
);

if (!$app || empty($app['rendered_html'])) {
    http_response_code(404);
    // Minimal fallback rendered inside the iframe if snapshot not available.
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><body style="font-family:sans-serif;padding:32px;color:#64748b;">'
       . '<p>No snapshot available for this application.</p></body></html>';
    exit;
}

// Security headers — tight CSP for iframe content.
// default-src 'none' blocks all external fetches.
// style-src 'unsafe-inline' allows the inline <style> block in rendered_html.
// img-src data: allows the embedded base64 signature image (D-CCA-3-C).
// base-uri 'none' prevents base-tag hijack.
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; base-uri \'none\';');
header('Cache-Control: no-store');

echo $app['rendered_html'];
exit;
