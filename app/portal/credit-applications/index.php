<?php
declare(strict_types=1);

/**
 * app/portal/credit-applications/index.php
 *
 * Portal credit application status — customers view the lifecycle and
 * review outcome of their credit applications.
 *
 * Trap 7: NO token_hash, signature_path, submitted_ip, review_notes,
 *         form_data, or reviewed_by emitted.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// Most-recent first; exclude soft-deleted rows
$applications = db_select(
    "SELECT ca.id, ca.status, ca.review_outcome,
            ca.approved_credit_limit,
            ca.print_name_first, ca.print_name_last,
            ca.terms_accepted, ca.token_expires_at,
            ca.sent_at, ca.opened_at, ca.submitted_at, ca.reviewed_at,
            ca.created_at
       FROM customer_credit_applications ca
      WHERE ca.customer_id = ? AND ca.deleted_at IS NULL
      ORDER BY ca.created_at DESC
      LIMIT 50",
    [$cid]
);

$pageTitle = 'Credit Application';
require_once dirname(__DIR__) . '/includes/header.php';

/**
 * Map (status, review_outcome) → human label + badge class.
 * The status lifecycle is: sent → opened → submitted → reviewed.
 * review_outcome is only set when status = 'reviewed'.
 */
function cca_badge(string $status, ?string $outcome): array
{
    if ($status === 'reviewed') {
        return match($outcome) {
            'approved'    => ['Approved',          'badge-success'],
            'declined'    => ['Declined',           'badge-danger'],
            'needs_info'  => ['More Info Requested','badge-warning'],
            default       => ['Reviewed',           'badge-neutral'],
        };
    }
    return match($status) {
        'sent'      => ['Invitation Sent', 'badge-neutral'],
        'opened'    => ['Link Opened',     'badge-info'],
        'submitted' => ['Under Review',    'badge-warning'],
        default     => ['Unknown',         'badge-neutral'],
    };
}
?>

<?php if (empty($applications)): ?>

<div class="portal-section" style="margin-top:0;">
    <div style="padding:48px 24px;text-align:center;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;margin:0 auto 16px;color:var(--text-muted);display:block;"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
        <p style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">No credit application on file</p>
        <p style="font-size:0.875rem;color:var(--text-secondary);max-width:400px;margin:0 auto;">
            Credit applications are initiated by our team. If you'd like to apply for credit terms, please contact us and we'll send you an invitation.
        </p>
    </div>
</div>

<?php else: ?>

<?php foreach ($applications as $app):
    [$badgeLabel, $badgeClass] = cca_badge($app['status'], $app['review_outcome']);
    $isExpired   = $app['token_expires_at'] && strtotime($app['token_expires_at']) < time();
    $isSubmitted = in_array($app['status'], ['submitted', 'reviewed'], true);
    $isApproved  = $app['status'] === 'reviewed' && $app['review_outcome'] === 'approved';
?>

<div class="portal-section" style="margin-bottom:20px;">

    <!-- Application header row -->
    <div class="portal-section-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 class="portal-section-title" style="margin:0;">Credit Application</h2>
            <div style="font-size:0.8125rem;color:var(--text-secondary);margin-top:2px;">
                Sent <?= e(format_date($app['sent_at'] ?? $app['created_at'])) ?>
            </div>
        </div>
        <span class="badge <?= e($badgeClass) ?>" style="font-size:0.8125rem;padding:5px 12px;"><?= e($badgeLabel) ?></span>
    </div>

    <!-- Status timeline -->
    <div class="portal-section-body" style="padding:20px;">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:20px;">

            <!-- Sent -->
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--color-success);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                </div>
                <div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);">Sent</div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= e(format_date($app['sent_at'] ?? $app['created_at'])) ?></div>
            </div>

            <!-- Opened -->
            <?php $openedDone = !empty($app['opened_at']); ?>
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $openedDone ? 'var(--color-success)' : 'var(--bg-secondary)' ?>;border:<?= $openedDone ? 'none' : '2px solid var(--border-color)' ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <?php if ($openedDone): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <?php else: ?>
                        <div style="width:8px;height:8px;border-radius:50%;background:var(--border-color);"></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);">Opened</div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $openedDone ? e(format_date($app['opened_at'])) : '—' ?></div>
            </div>

            <!-- Submitted -->
            <?php $submittedDone = !empty($app['submitted_at']); ?>
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $submittedDone ? 'var(--color-success)' : 'var(--bg-secondary)' ?>;border:<?= $submittedDone ? 'none' : '2px solid var(--border-color)' ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <?php if ($submittedDone): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <?php else: ?>
                        <div style="width:8px;height:8px;border-radius:50%;background:var(--border-color);"></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);">Submitted</div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $submittedDone ? e(format_date($app['submitted_at'])) : '—' ?></div>
            </div>

            <!-- Reviewed -->
            <?php $reviewedDone = !empty($app['reviewed_at']); ?>
            <div style="text-align:center;">
                <?php
                $reviewCircleColor = 'var(--bg-secondary)';
                $reviewCircleBorder = '2px solid var(--border-color)';
                if ($reviewedDone) {
                    $reviewCircleColor = match($app['review_outcome']) {
                        'approved'   => 'var(--color-success)',
                        'declined'   => 'var(--color-danger)',
                        'needs_info' => 'var(--color-warning)',
                        default      => 'var(--text-muted)',
                    };
                    $reviewCircleBorder = 'none';
                }
                ?>
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $reviewCircleColor ?>;border:<?= $reviewCircleBorder ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <?php if ($reviewedDone): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <?php else: ?>
                        <div style="width:8px;height:8px;border-radius:50%;background:var(--border-color);"></div>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.75rem;font-weight:600;color:var(--text-primary);">Reviewed</div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $reviewedDone ? e(format_date($app['reviewed_at'])) : '—' ?></div>
            </div>

        </div><!-- /grid -->

        <!-- Outcome callout -->
        <?php if ($app['status'] === 'reviewed'): ?>
            <?php if ($isApproved): ?>
            <div style="background:var(--badge-success-bg);border:1px solid var(--color-success);border-radius:8px;padding:16px 20px;display:flex;align-items:center;gap:16px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success)" style="width:28px;height:28px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <div>
                    <div style="font-weight:700;color:var(--badge-success-text);margin-bottom:2px;">Credit Application Approved</div>
                    <?php if (!empty($app['approved_credit_limit'])): ?>
                    <div style="font-size:0.875rem;color:var(--badge-success-text);">
                        Approved credit limit: <strong class="font-mono"><?= e(format_currency($app['approved_credit_limit'])) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($app['review_outcome'] === 'declined'): ?>
            <div style="background:var(--badge-danger-bg);border:1px solid var(--color-danger);border-radius:8px;padding:16px 20px;">
                <div style="font-weight:600;color:var(--badge-danger-text);margin-bottom:4px;">Application Not Approved</div>
                <div style="font-size:0.875rem;color:var(--badge-danger-text);">
                    We were unable to approve this credit application. Please contact us if you have questions.
                </div>
            </div>
            <?php elseif ($app['review_outcome'] === 'needs_info'): ?>
            <div style="background:var(--badge-warning-bg);border:1px solid var(--color-warning);border-radius:8px;padding:16px 20px;">
                <div style="font-weight:600;color:var(--badge-warning-text);margin-bottom:4px;">Additional Information Required</div>
                <div style="font-size:0.875rem;color:var(--badge-warning-text);">
                    Our team needs additional information to process your application. Please check your email or contact us directly.
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($app['status'] === 'submitted'): ?>
            <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:8px;padding:14px 18px;font-size:0.875rem;color:var(--text-secondary);">
                Your application has been submitted and is currently under review. We'll contact you once a decision has been made.
            </div>

        <?php elseif (in_array($app['status'], ['sent', 'opened'], true)): ?>
            <?php if ($isExpired): ?>
            <div style="background:var(--badge-danger-bg);border:1px solid var(--color-danger);border-radius:8px;padding:14px 18px;font-size:0.875rem;color:var(--badge-danger-text);">
                The application link has expired. Please contact us to receive a new invitation.
            </div>
            <?php else: ?>
            <div style="background:var(--badge-info-bg,var(--bg-secondary));border:1px solid var(--border-color);border-radius:8px;padding:14px 18px;font-size:0.875rem;color:var(--text-secondary);">
                Your application link was sent to your email address on <?= e(format_date($app['sent_at'] ?? $app['created_at'])) ?>. Please check your inbox to complete the form.
                <?php if (!empty($app['token_expires_at'])): ?>
                    The link expires <?= e(format_date($app['token_expires_at'])) ?>.
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Signer info when submitted -->
        <?php if ($isSubmitted && ($app['print_name_first'] || $app['print_name_last'])): ?>
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color);font-size:0.8125rem;color:var(--text-secondary);">
            Signed by: <strong><?= e(trim($app['print_name_first'] . ' ' . $app['print_name_last'])) ?></strong>
            &mdash; <?= e(format_date($app['submitted_at'])) ?>
        </div>
        <?php endif; ?>

        <!-- View Application link — only for submitted/reviewed (rendered_html populated) -->
        <?php if ($isSubmitted): ?>
        <div style="margin-top:16px;<?= ($app['print_name_first'] || $app['print_name_last']) ? '' : 'padding-top:16px;border-top:1px solid var(--border-color);' ?>text-align:right;">
            <a href="<?= e(base_url('portal/credit-applications/view?id=' . (int)$app['id'])) ?>"
               class="btn btn-secondary btn-sm"
               style="display:inline-flex;align-items:center;gap:6px;">
                View Submitted Application
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>
        <?php endif; ?>

    </div><!-- /section-body -->
</div><!-- /portal-section -->

<?php endforeach; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
