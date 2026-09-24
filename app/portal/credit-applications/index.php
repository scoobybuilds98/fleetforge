<?php
declare(strict_types=1);

/**
 * app/portal/credit-applications/index.php
 *
 * Customer portal — Credit application status (S-PORTAL-REDESIGN).
 *
 * Each application as a four-step progress (invited → opened → submitted →
 * decision) with the outcome, the approved limit, whether an invitation
 * link has expired, and a link to the submitted copy. Applications are
 * started by our team; with none on file the page offers a request to ask
 * for one.
 *
 * Trap 7 (unchanged): no token hash, signature path, submitter IP, review
 * notes, form data or reviewer is selected.
 * Trap 8: filtered by portal_customer_id().
 *
 * @session S-PORTAL-PAYMENTS-CCA (original), S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid = portal_customer_id();

$applications = db_select(
    "SELECT ca.id, ca.status, ca.review_outcome, ca.approved_credit_limit,
            ca.print_name_first, ca.print_name_last, ca.token_expires_at,
            ca.sent_at, ca.opened_at, ca.submitted_at, ca.reviewed_at, ca.created_at
       FROM customer_credit_applications ca
      WHERE ca.customer_id = ? AND ca.deleted_at IS NULL
      ORDER BY ca.created_at DESC
      LIMIT 50",
    [$cid]
);

$pageTitle = 'Credit application';
require_once dirname(__DIR__) . '/includes/header.php';

$askUrl = pt_url('requests/create?type=general&subject=' . rawurlencode('Credit application') . '&message=' . rawurlencode('We\'d like to apply for credit terms. Please send us a credit application.'));

echo pt_page_head([
    'eyebrow' => 'Account',
    'title'   => 'Credit application',
    'sub'     => 'Where your application for credit terms stands. We send the secure application link by email.',
    'actions' => $applications ? '' : '<a class="pt-btn pt-btn--primary" href="' . e($askUrl) . '">' . pt_icon('plus') . ' Ask for an application</a>',
]);
?>

<?php if (!$applications): ?>
    <div class="pt-card"><?= pt_empty('clipboard-document-check', 'No credit application on file', 'Want to pay on account terms? Ask us and we\'ll email you a secure application form.', '<a class="pt-btn pt-btn--soft pt-btn--sm" href="' . e($askUrl) . '">Ask for an application</a>') ?></div>
<?php else: ?>
    <div class="pt-stack">
    <?php foreach ($applications as $a):
        $expired = in_array($a['status'], ['sent', 'opened'], true) && !empty($a['token_expires_at']) && $a['token_expires_at'] < ff_now_utc();
        [$label, $tone] = match (true) {
            $a['status'] === 'reviewed' && $a['review_outcome'] === 'approved'   => ['Approved', 'success'],
            $a['status'] === 'reviewed' && $a['review_outcome'] === 'declined'   => ['Not approved', 'danger'],
            $a['status'] === 'reviewed' && $a['review_outcome'] === 'needs_info' => ['More info needed', 'warning'],
            $a['status'] === 'reviewed'  => ['Reviewed', 'neutral'],
            $a['status'] === 'submitted' => ['Under review', 'info'],
            $expired                     => ['Link expired', 'warning'],
            $a['status'] === 'opened'    => ['In progress', 'info'],
            default                      => ['Invitation sent', 'brand'],
        };
        $stepIdx = match ($a['status']) { 'sent' => 0, 'opened' => 1, 'submitted' => 2, 'reviewed' => 3, default => 0 };
        $steps = [
            ['Invited', $a['sent_at'] ?: $a['created_at']],
            ['Opened', $a['opened_at']],
            ['Submitted', $a['submitted_at']],
            ['Decision', $a['reviewed_at']],
        ];
    ?>
        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('clipboard-document-check') ?> Application #<?= (int) $a['id'] ?></h2>
                    <p class="pt-card-sub">Started <?= e(format_datetime($a['created_at'], 'M j, Y')) ?><?= trim((string) $a['print_name_first'] . ' ' . (string) $a['print_name_last']) !== '' ? ' · signed by ' . e(trim($a['print_name_first'] . ' ' . $a['print_name_last'])) : '' ?></p>
                </div>
                <?= pt_badge($label, $tone) ?>
            </div>
            <div class="pt-card-body">
                <ol class="pt-steps" aria-label="Application progress">
                    <?php foreach ($steps as $i => [$sLabel, $sDate]): ?>
                        <li class="pt-step<?= $i < $stepIdx || ($i === $stepIdx && $a['status'] === 'reviewed') ? ' is-done' : ($i === $stepIdx ? ' is-current' : '') ?>">
                            <span><?= e($sLabel) ?></span>
                            <span class="pt-faint" style="font-weight:500"><?= $sDate && $i <= $stepIdx ? e(format_datetime($sDate, 'M j')) : '' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($a['status'] === 'reviewed' && $a['review_outcome'] === 'approved' && !empty($a['approved_credit_limit'])): ?>
                    <div class="pt-note pt-note--success" style="margin-top:16px"><?= pt_icon('check-circle') ?><span>Approved credit limit: <strong><?= e(format_currency($a['approved_credit_limit'])) ?></strong></span></div>
                <?php elseif ($a['status'] === 'reviewed' && $a['review_outcome'] === 'needs_info'): ?>
                    <div class="pt-note pt-note--warning" style="margin-top:16px"><?= pt_icon('question-mark-circle') ?><span>Our team needs a little more information — check your email or <a class="pt-link" href="<?= e(pt_url('requests/create?type=general&subject=' . rawurlencode('Credit application #' . (int) $a['id']))) ?>">send us a message</a>.</span></div>
                <?php elseif ($expired): ?>
                    <div class="pt-note pt-note--warning" style="margin-top:16px"><?= pt_icon('clock') ?><span>The link we emailed has expired. <a class="pt-link" href="<?= e(pt_url('requests/create?type=general&subject=' . rawurlencode('New credit application link'))) ?>">Ask for a new one</a>.</span></div>
                <?php elseif (in_array($a['status'], ['sent', 'opened'], true)): ?>
                    <div class="pt-note pt-note--info" style="margin-top:16px"><?= pt_icon('envelope') ?><span>Finish your application using the secure link in your email<?= $a['token_expires_at'] ? ' (valid until ' . e(format_datetime($a['token_expires_at'], 'M j, Y')) . ')' : '' ?>.</span></div>
                <?php endif; ?>
            </div>
            <?php if (in_array($a['status'], ['submitted', 'reviewed'], true)): ?>
                <div class="pt-card-foot">
                    <span><?= $a['submitted_at'] ? 'Submitted ' . e(format_datetime($a['submitted_at'], 'M j, Y')) : '' ?></span>
                    <a class="pt-btn pt-btn--secondary pt-btn--sm" href="<?= e(pt_url('credit-applications/view?id=' . (int) $a['id'])) ?>">View submitted application</a>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
