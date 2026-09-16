<?php declare(strict_types=1);

/**
 * app/admin/accounting/recurring-entries/show.php
 *
 * Recurring template detail: header card + line items table + posting
 * history. Pause/Unpause action via inline Alpine. Post-Now action
 * available to super_admin/manager only (matches the API gate).
 *
 * Overdue banner: when next_post_date has passed, the page says how many
 * occurrences are unposted, whether the nightly scheduler is switched off
 * (Settings → Scheduled Jobs), and offers "Catch up now" — which posts each
 * missed occurrence once, dated on its scheduled date.
 *
 * @session S037-REC
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Template Not Specified</h1>';
    exit;
}

$template = db_row(
    "SELECT t.*, u.name AS created_by_name
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE t.id = ?",
    [$id]
);
if (!$template) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Template Not Found</h1>';
    exit;
}

$lines = db_select(
    "SELECT l.id, l.line_number, l.description, l.debit, l.credit,
            a.code AS account_code, a.name AS account_name, a.account_type
       FROM acc_recurring_entry_lines l
       JOIN acc_accounts a ON a.id = l.account_id
      WHERE l.recurring_entry_id = ?
      ORDER BY l.line_number, l.id",
    [$id]
);

$history = db_select(
    "SELECT id, entry_number, entry_date, status, description, reference
       FROM acc_journal_entries
      WHERE source_type = 'recurring' AND source_id = ?
      ORDER BY entry_date DESC, id DESC
      LIMIT 24",
    [$id]
);

$canEdit       = can('journal_entries', 'edit');
$canDelete     = can('journal_entries', 'delete');
$roleSlug      = current_user()['role_slug'] ?? '';
$canPostNow    = $canEdit && in_array($roleSlug, ['super_admin', 'manager'], true);
$canBeDeleted  = count($history) === 0;

// Overdue state — computed with the same engine the cron + Post Now use, so
// the count shown is exactly what "Catch up now" would post.
$today          = \FleetForge\Accounting\AccountingService::businessToday();
$missedDates    = ((int) $template['is_active'] === 1)
    ? \FleetForge\Accounting\RecurringEntryService::dueOccurrences($template, $today, 1000)
    : [];
$cronOn         = cron_enabled('accounting_recurring_entries');

$sumDr = '0.00'; $sumCr = '0.00';
foreach ($lines as $l) {
    $sumDr = bcadd($sumDr, (string) $l['debit'], 2);
    $sumCr = bcadd($sumCr, (string) $l['credit'], 2);
}

$pageTitle = $template['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/recurring-entries') ?>">Recurring Entries</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($template['name']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        <?= e($template['name']) ?>
        <?= ((int) $template['is_active']) === 1
            ? '<span class="badge badge-green" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;">Active</span>'
            : '<span class="badge badge-red" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;">Paused</span>' ?>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/recurring-entries') ?>">← Back</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="recurringShow(<?= (int) $id ?>)">
    <?php if ($missedDates): ?>
    <!-- Overdue banner -->
    <div class="alert alert-warning" role="status" style="margin-bottom:14px;">
        <div style="font-weight:600;margin-bottom:4px;">
            Overdue — <?= count($missedDates) ?> occurrence<?= count($missedDates) === 1 ? '' : 's' ?> not posted
            (<?= e($missedDates[0]) ?><?= count($missedDates) > 1 ? ' → ' . e(end($missedDates)) : '' ?>)
        </div>
        <div style="font-size:0.8125rem;">
            <?php if (!$cronOn): ?>
                The nightly <strong>Recurring journal entries</strong> job is switched <strong>off</strong>
                in Settings → Scheduled Jobs, so nothing posts automatically.
            <?php else: ?>
                The nightly job posts every missed occurrence on its next run (up to 24 per run).
            <?php endif; ?>
            <?php if ($canPostNow): ?>
                “Catch up now” posts each one once, dated on its scheduled date<?= ((int) $template['auto_post']) === 1 ? '' : ' (as drafts for review — this template is not auto-post)' ?>.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Header card -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Frequency</div>
                <div style="text-transform:capitalize;"><?= e($template['frequency']) ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Day of Month</div>
                <div class="font-mono"><?= (int) $template['day_of_month'] ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Auto-Post</div>
                <div><?= ((int) $template['auto_post']) === 1 ? '<span class="badge badge-green" style="font-size:0.65rem;">Auto</span>' : '<span class="badge badge-neutral" style="font-size:0.65rem;">Draft for review</span>' ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Start Date</div>
                <div class="font-mono"><?= e($template['start_date']) ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">End Date</div>
                <div class="font-mono"><?= $template['end_date'] ? e($template['end_date']) : '<span style="color:var(--text-secondary);">open-ended</span>' ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Next Post</div>
                <div class="font-mono"><?= e($template['next_post_date'] ?? '—') ?>
                    <?php if ($missedDates): ?><span class="badge badge-warning" style="margin-left:6px;font-size:0.65rem;">Overdue</span><?php endif; ?>
                </div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Last Posted</div>
                <div class="font-mono"><?= $template['last_posted_date'] ? e($template['last_posted_date']) : '<span style="color:var(--text-secondary);">never</span>' ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:2px;">Created</div>
                <div><?= e($template['created_by_name'] ?? 'system') ?> <span style="font-size:0.7rem;color:var(--text-secondary);"><?= e(substr((string) $template['created_at'], 0, 10)) ?></span></div>
            </div>
        </div>
        <?php if ($template['description']): ?>
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;margin-bottom:4px;">Description</div>
            <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($template['description']) ?></div>
        </div>
        <?php endif; ?>

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($canEdit): ?>
                <button class="btn btn-secondary btn-sm" @click="togglePause()" x-text="'<?= ((int) $template['is_active']) === 1 ? 'Pause' : 'Unpause' ?>'"></button>
            <?php endif; ?>
            <?php if ($canPostNow): ?>
                <button class="btn btn-primary btn-sm" @click="postNow(<?= count($missedDates) ?>)" :disabled="posting"
                        x-text="posting ? 'Posting…' : '<?= $missedDates ? 'Catch up now (' . count($missedDates) . ')' : 'Post Now' ?>'"><?= $missedDates ? 'Catch up now' : 'Post Now' ?></button>
            <?php endif; ?>
            <?php if ($canDelete && $canBeDeleted): ?>
                <button class="btn btn-danger btn-sm" @click="del()">Delete</button>
            <?php elseif ($canDelete): ?>
                <button class="btn btn-danger btn-sm" disabled title="Cannot delete — template has posting history. Pause instead.">Delete</button>
            <?php endif; ?>
        </div>
        <div x-show="postMsg" x-cloak style="margin-top:10px;font-size:0.8125rem;" :style="postIsError ? 'color:var(--color-danger);' : 'color:var(--color-success);'" x-text="postMsg"></div>
    </div>

    <!-- Lines -->
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Template Lines</div>
        <?php if (empty($lines)): ?>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">No lines configured.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:9px 12px;text-align:center;width:60px;">#</th>
                            <th style="padding:9px 12px;text-align:left;">Account</th>
                            <th style="padding:9px 12px;text-align:left;">Description</th>
                            <th style="padding:9px 12px;text-align:right;">Debit</th>
                            <th style="padding:9px 12px;text-align:right;">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td class="font-mono" style="padding:7px 12px;text-align:center;"><?= (int) $l['line_number'] ?></td>
                            <td class="font-mono" style="padding:7px 12px;font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                            <td style="padding:7px 12px;"><?= e($l['description'] ?? '') ?></td>
                            <td class="font-mono" style="padding:7px 12px;text-align:right;<?= ((float) $l['debit'] > 0) ? 'font-weight:600;' : 'color:var(--text-secondary);' ?>"><?= ((float) $l['debit'] > 0) ? '$' . number_format((float) $l['debit'], 2) : '—' ?></td>
                            <td class="font-mono" style="padding:7px 12px;text-align:right;<?= ((float) $l['credit'] > 0) ? 'font-weight:600;' : 'color:var(--text-secondary);' ?>"><?= ((float) $l['credit'] > 0) ? '$' . number_format((float) $l['credit'], 2) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--bg-elev);border-top:2px solid var(--border-default);">
                            <td colspan="3" style="padding:9px 12px;font-weight:700;text-align:right;">Totals</td>
                            <td class="font-mono" style="padding:9px 12px;text-align:right;font-weight:700;">$<?= number_format((float) $sumDr, 2) ?></td>
                            <td class="font-mono" style="padding:9px 12px;text-align:right;font-weight:700;">$<?= number_format((float) $sumCr, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Posting history -->
    <div class="card" style="padding:18px;">
        <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Posting History <span style="font-weight:400;color:var(--text-secondary);font-size:0.78rem;margin-left:6px;"><?= count($history) ?> JE(s), last 24 shown</span></div>
        <?php if (empty($history)): ?>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">No JEs posted yet for this template.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:9px 12px;text-align:left;">Entry #</th>
                            <th style="padding:9px 12px;text-align:left;">Entry Date</th>
                            <th style="padding:9px 12px;text-align:center;">Status</th>
                            <th style="padding:9px 12px;text-align:left;">Reference</th>
                            <th style="padding:9px 12px;text-align:left;">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td class="font-mono" style="padding:7px 12px;">
                                <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $h['id']) ?>" style="color:var(--color-accent);text-decoration:none;"><?= e($h['entry_number']) ?></a>
                            </td>
                            <td class="font-mono" style="padding:7px 12px;"><?= e($h['entry_date']) ?></td>
                            <td style="padding:7px 12px;text-align:center;">
                                <span class="badge <?= $h['status'] === 'posted' ? 'badge-green' : ($h['status'] === 'reversed' ? 'badge-red' : 'badge-neutral') ?>"><?= e($h['status']) ?></span>
                            </td>
                            <td class="font-mono" style="padding:7px 12px;font-size:0.78rem;"><?= e($h['reference']) ?></td>
                            <td style="padding:7px 12px;"><?= e($h['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function recurringShow(id) {
    const apiBase = '<?= e(base_url('api/v1/accounting/recurring')) ?>';
    return {
        id: id,
        posting: false,
        postMsg: '',
        postIsError: false,
        async togglePause() {
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/pause.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: this.id })
                });
                const j = await r.json();
                if (j && j.success) window.location.reload();
                else alert((j && j.error && j.error.message) || 'Toggle failed.');
            } catch (e) { alert('Toggle failed: ' + e.message); }
        },
        async postNow(missed) {
            const prompt = missed > 0
                ? 'Post the ' + missed + ' missed occurrence(s) now? Each is posted once, dated on its scheduled date. Safe to re-run.'
                : 'Post this template now? Idempotent — re-running on the same year-month returns the existing JE.';
            if (!confirm(prompt)) return;
            this.posting = true; this.postMsg = ''; this.postIsError = false;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/post_now.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: this.id })
                });
                const j = await r.json();
                const d = (j && j.data) || {};
                if (j && j.success && d.mode === 'catch_up') {
                    const n = (d.posted || []).length;
                    this.postMsg = '✓ Posted ' + n + ' missed occurrence' + (n === 1 ? '' : 's')
                        + (n ? ' (' + d.posted.map(p => p.entry_number).join(', ') + ')' : '')
                        + ((d.skipped || []).length ? ' — ' + d.skipped.length + ' already posted' : '')
                        + (d.remaining ? ' — ' + d.remaining + ' still due, run again' : '') + '.';
                    setTimeout(() => window.location.reload(), 2000);
                } else if (j && j.success) {
                    const created = d.created;
                    const je = d.je;
                    this.postMsg = created
                        ? '✓ Posted ' + (je ? je.entry_number : '(new JE)')
                        : 'Already posted (' + (je ? je.entry_number : 'existing JE') + ') — idempotent skip.';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    // A catch-up that stopped part-way still posted the earlier months.
                    this.postMsg = (j && j.error && j.error.message) || 'Post failed.';
                    this.postIsError = true;
                    if (j && j.error && j.error.result && (j.error.result.posted || []).length) {
                        setTimeout(() => window.location.reload(), 4000);
                    }
                }
            } catch (e) { this.postMsg = 'Post failed: ' + e.message; this.postIsError = true; }
            this.posting = false;
        },
        async del() {
            if (!confirm('Delete this template? This is permanent (only allowed if no posting history).')) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const r = await fetch(apiBase + '/delete.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ id: this.id })
                });
                const j = await r.json();
                if (j && j.success) {
                    window.location.href = '<?= e(base_url('accounting/recurring-entries')) ?>';
                } else {
                    alert((j && j.error && j.error.message) || 'Delete failed.');
                }
            } catch (e) { alert('Delete failed: ' + e.message); }
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
