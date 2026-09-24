<?php
declare(strict_types=1);

/**
 * app/portal/credit-applications/view.php
 *
 * Customer portal — a submitted credit application (S-PORTAL-REDESIGN).
 *
 * Outcome banner (approved + limit / not approved / more info needed /
 * under review), who signed and when, the PDF, and a read-only preview of
 * what was submitted.
 *
 * Fixed here:
 *   - the frozen rendered_html is a whole HTML document with its own
 *     <style> (body{background:#fff;font-size:10pt}…) and was echoed straight
 *     into the page — it restyled the entire portal. It now renders inside a
 *     sandboxed <iframe srcdoc> (no scripts; styles can't escape).
 *   - the page claimed (Trap 7) to hide the submitter IP and the signature
 *     image, but the hiding CSS targeted class names that don't exist. Both
 *     are now removed from the markup server-side before it is shown.
 *   - outcome banners used --badge-* tokens that are undefined (rendered
 *     transparent); they use the portal note recipe now.
 *
 * Visibility (unchanged, Trap 8): the customer's own application, only once
 * submitted or reviewed.
 *
 * @session S-PORTAL-CCA-VIEW (original), S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$appId = clean_int($_GET['id'] ?? null);
if (!$appId) {
    header('Location: ' . pt_url('credit-applications'));
    exit;
}

$app = db_row(
    "SELECT ca.id, ca.status, ca.review_outcome, ca.approved_credit_limit, ca.rendered_html,
            ca.generated_pdf_document_id, ca.print_name_first, ca.print_name_last,
            ca.signed_date, ca.submitted_at, ca.reviewed_at
       FROM customer_credit_applications ca
      WHERE ca.id = ? AND ca.customer_id = ? AND ca.deleted_at IS NULL",
    [$appId, $cid]
);
if (!$app || !in_array($app['status'], ['submitted', 'reviewed'], true)) {
    header('Location: ' . pt_url('credit-applications'));
    exit;
}

$pdfUrl = ((string) ($app['rendered_html'] ?? '') !== '' || !empty($app['generated_pdf_document_id']))
    ? base_url('api/v1/portal/credit_applications/pdf') . '?id=' . (int) $appId
    : null;

// Snapshot for the preview: strip the submitter IP cell and the signature
// image (Trap 7), and anything executable, before it goes in the sandbox.
$snapshot = (string) ($app['rendered_html'] ?? '');
if ($snapshot !== '') {
    $snapshot = (string) preg_replace('#<td[^>]*>\s*IP:.*?</td>#is', '<td></td>', $snapshot);
    $snapshot = (string) preg_replace('#<img\b[^>]*src=["\']data:image/[^>]*>#is', '<em style="color:#6b7280">Signature on file</em>', $snapshot);
    $snapshot = (string) preg_replace('#<(script|iframe|object|embed)\b.*?</\1\s*>#is', '', $snapshot);
    $snapshot = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $snapshot);
}

[$tone, $icon, $title, $text] = match (true) {
    $app['status'] === 'submitted'           => ['info', 'clock', 'Under review', 'Thanks — we have your application and we\'re reviewing it. We\'ll be in touch once a decision is made.'],
    $app['review_outcome'] === 'approved'    => ['success', 'check-circle', 'Approved', !empty($app['approved_credit_limit']) ? 'Your approved credit limit is ' . format_currency($app['approved_credit_limit']) . '.' : 'Your credit application has been approved.'],
    $app['review_outcome'] === 'declined'    => ['danger', 'exclamation-triangle', 'Not approved', 'We weren\'t able to approve this application. Please contact us if you have questions.'],
    $app['review_outcome'] === 'needs_info'  => ['warning', 'question-mark-circle', 'More information needed', 'Our team needs a little more information. Please check your email or send us a message.'],
    default                                  => ['info', 'information-circle', 'Reviewed', 'Your application has been reviewed. Contact us for details.'],
};

$pageTitle = 'Credit application';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'back'    => ['Credit application', pt_url('credit-applications')],
    'title'   => 'Application #' . (int) $app['id'],
    'meta'    => pt_badge($title, $tone)
        . ($app['submitted_at'] ? '<span>Submitted ' . e(format_datetime($app['submitted_at'], 'M j, Y')) . '</span>' : '')
        . (trim((string) $app['print_name_first'] . ' ' . (string) $app['print_name_last']) !== ''
            ? '<span>Signed by ' . e(trim($app['print_name_first'] . ' ' . $app['print_name_last'])) . ($app['signed_date'] ? ' on ' . e(format_date($app['signed_date'])) : '') . '</span>' : ''),
    'actions' => $pdfUrl ? '<a class="pt-btn pt-btn--secondary" href="' . e($pdfUrl) . '" target="_blank" rel="noopener">' . pt_icon('arrow-down-tray') . ' PDF</a>' : '',
]);
?>

<div class="pt-note pt-note--<?= e($tone) ?>" style="margin-bottom:20px;padding:18px 20px">
    <?= pt_icon($icon, 'pt-ic pt-ic--lg') ?>
    <span><strong style="display:block;font-size:15px;margin-bottom:2px"><?= e($title) ?></strong><?= e($text) ?></span>
</div>

<section class="pt-card">
    <div class="pt-card-head pt-card-head--line">
        <div><h2 class="pt-card-title"><?= pt_icon('document-text') ?> What you submitted</h2><p class="pt-card-sub">A read-only copy. Your signature is kept on file and not shown here.</p></div>
    </div>
    <?php if ($snapshot === ''): ?>
        <?= pt_empty('document-text', 'Preview not available', 'Download the PDF, or send us a message if you need a copy.') ?>
    <?php else: ?>
        <div style="padding:16px;background:var(--bg-surface-2);border-radius:0 0 16px 16px">
            <iframe title="Submitted credit application" sandbox="allow-same-origin" srcdoc="<?= e($snapshot) ?>"
                    style="display:block;width:100%;min-height:600px;border:0;border-radius:10px;background:#fff"
                    onload="try { this.style.height = (this.contentDocument.documentElement.scrollHeight + 8) + 'px'; } catch (e) {}"></iframe>
        </div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
