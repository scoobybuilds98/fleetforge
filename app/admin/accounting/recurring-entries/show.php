<?php declare(strict_types=1);

/**
 * app/admin/accounting/recurring-entries/show.php
 *
 * Recurring template detail: header + line items table + posting
 * history. Pause/Unpause action via inline Alpine. Post-Now action
 * available to super_admin/manager only (matches the API gate).
 *
 * Overdue state: when next_post_date has passed, the page says how many
 * occurrences are unposted, whether the nightly scheduler is switched off
 * (Settings → Scheduled Jobs), and offers "Catch up now" — which posts each
 * missed occurrence once, dated on its scheduled date.
 *
 * Layout (S-RECORD-REDESIGN):
 *   The Alpine component (recurringShow) opens ABOVE the header so the
 *   actions live there: Catch up / Post Now (primary), Pause / Unpause;
 *   Delete in the More menu.
 *   header  — ModuleHero entity: template name + Active/Paused/Overdue
 *             badges; schedule · window · auto-post chips
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — amount per posting · next post · last posted · postings
 *   main    — Schedule details · Template lines (balance check) · Posting
 *             history
 *   rail    — Needs attention (the overdue explanation that used to be a
 *             banner, scheduler off, unbalanced/empty template, paused,
 *             ended) · Schedule facts · Related links
 *
 * @session S037-REC, S-RECORD-REDESIGN
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
// The table shows the latest 24; the strip counts them all.
$historyTotal = (int) (db_row(
    "SELECT COUNT(*) AS c FROM acc_journal_entries WHERE source_type = 'recurring' AND source_id = ?",
    [$id]
)['c'] ?? 0);

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
$isBalanced = bccomp($sumDr, $sumCr, 2) === 0;
// NOT $isActive: includes/partials/accounting-nav.php (included below the
// header) sets its own $isActive in its loop and would clobber it.
$tplActive  = (int) $template['is_active'] === 1;
$hasEnded   = !empty($template['end_date']) && $template['end_date'] < $today;

// "in N days" / "N days ago" for the strip.
$relDays = static function (?string $date) use ($today): string {
    if (empty($date)) return '';
    $n = (int) round((strtotime($date) - strtotime($today)) / 86400);
    return $n === 0 ? 'today' : ($n > 0 ? 'in ' . $n . ' day' . ($n === 1 ? '' : 's') : (-$n) . ' day' . ($n === -1 ? '' : 's') . ' ago');
};
$ordinal = static function (int $n): string {
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
};

$pageTitle = $template['name'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = e($template['name'])
    . ($tplActive ? ' <span class="badge badge-green">Active</span>' : ' <span class="badge badge-red">Paused</span>')
    . ($missedDates ? ' <span class="badge badge-warning">Overdue</span>' : '');
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('arrow-path') . e(ucfirst((string) $template['frequency'])) . ' on the ' . e($ordinal((int) $template['day_of_month'])),
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($template['start_date'])) . ' → ' . ($template['end_date'] ? e(format_date($template['end_date'])) : 'open-ended'),
    \FleetForge\Sop\SopIcons::svg('check-circle') . ((int) $template['auto_post'] === 1 ? 'Auto-posts' : 'Drafts for review'),
];
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/recurring-entries') ?>">All recurring entries</a>
    <?php if ($canDelete && $canBeDeleted): ?>
        <button class="btn btn-danger btn-sm" @click="del()">Delete</button>
    <?php elseif ($canDelete): ?>
        <button class="btn btn-danger btn-sm" disabled title="Cannot delete — template has posting history. Pause instead.">Delete</button>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?php if ($canPostNow): ?>
        <button class="btn btn-primary btn-sm" @click="postNow(<?= count($missedDates) ?>)" :disabled="posting"
                x-text="posting ? 'Posting…' : '<?= $missedDates ? 'Catch up now (' . count($missedDates) . ')' : 'Post Now' ?>'"><?= $missedDates ? 'Catch up now' : 'Post Now' ?></button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <button class="btn btn-secondary btn-sm" @click="togglePause()" x-text="'<?= $tplActive ? 'Pause' : 'Unpause' ?>'"></button>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- The component opens ABOVE the header so its actions can live there. -->
<div x-data="recurringShow(<?= (int) $id ?>)">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $missedDates ? 'warning' : ($tplActive ? 'success' : 'info'),
    'icon'       => 'arrow-path',
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Recurring Entries', base_url('accounting/recurring-entries')], [(string) $template['name'], null]],
    'eyebrow'    => 'Recurring journal entry',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): what each posting books, when the next
     one is due (late ones in red), when it last ran, and how often it has. -->
<div class="stat-grid stat-grid--4 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Per posting</div>
        <div class="stat-value font-mono"><?= e(format_currency($sumDr)) ?></div>
        <div class="stat-delta"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></div>
    </div>
    <div class="stat-card <?= $missedDates ? 'stat-card--red' : 'stat-card--amber' ?>">
        <span class="stat-icon <?= $missedDates ? 'stat-icon--red' : 'stat-icon--amber' ?>"><svg><use href="#icon-<?= $missedDates ? 'exclamation-triangle' : 'clock' ?>"/></svg></span>
        <div class="stat-label">Next post</div>
        <div class="stat-value stat-value--date font-mono"<?= $missedDates ? ' style="color:var(--color-danger);"' : '' ?>><?= !empty($template['next_post_date']) && $tplActive && !$hasEnded ? e(format_date($template['next_post_date'])) : '—' ?></div>
        <div class="stat-delta"><?= $missedDates ? count($missedDates) . ' missed' : ($tplActive ? ($hasEnded ? 'ended' : e($relDays($template['next_post_date']))) : 'paused') ?></div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Last posted</div>
        <div class="stat-value stat-value--date font-mono"><?= $template['last_posted_date'] ? e(format_date($template['last_posted_date'])) : 'Never' ?></div>
        <div class="stat-delta"><?= $template['last_posted_date'] ? e($relDays($template['last_posted_date'])) : '' ?></div>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Postings</div>
        <div class="stat-value font-mono"><?= $historyTotal ?></div>
        <div class="stat-delta">journal entries</div>
    </div>
</div>

<div x-show="postMsg" x-cloak class="alert" :class="postIsError ? 'alert-danger' : 'alert-success'" role="status" style="margin-bottom:14px;" x-text="postMsg"></div>

<div class="rec-layout">
<div class="rec-main">

    <!-- Schedule details -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Schedule</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <dt>Frequency</dt>
                <dd><?= e(ucfirst((string) $template['frequency'])) ?></dd>
                <dt>Day of month</dt>
                <dd class="font-mono"><?= (int) $template['day_of_month'] ?></dd>
                <dt>Auto-post</dt>
                <dd><?= ((int) $template['auto_post']) === 1 ? '<span class="badge badge-green">Auto</span>' : '<span class="badge badge-neutral">Draft for review</span>' ?></dd>
                <dt>Start date</dt>
                <dd class="font-mono"><?= e(format_date($template['start_date'])) ?></dd>
                <dt>End date</dt>
                <dd class="font-mono"><?= $template['end_date'] ? e(format_date($template['end_date'])) : '<span class="text-secondary">open-ended</span>' ?></dd>
                <dt>Next post</dt>
                <dd class="font-mono"><?= e(format_date($template['next_post_date'] ?? null)) ?>
                    <?php if ($missedDates): ?><span class="badge badge-warning">Overdue</span><?php endif; ?>
                </dd>
                <dt>Last posted</dt>
                <dd class="font-mono"><?= $template['last_posted_date'] ? e(format_date($template['last_posted_date'])) : '<span class="text-secondary">never</span>' ?></dd>
                <?php if ($template['description']): ?>
                <dt>Description</dt>
                <dd style="white-space:pre-wrap;"><?= e($template['description']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Lines -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Template lines</h3></div>
        <div class="card-body">
        <?php if (empty($lines)): ?>
            <p class="text-secondary" style="margin:0;font-size:0.8125rem;">No lines configured.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="text-align:center;width:60px;">#</th>
                            <th>Account</th>
                            <th>Description</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                        <?php $dr = bccomp((string) $l['debit'], '0', 2) > 0; $cr = bccomp((string) $l['credit'], '0', 2) > 0; ?>
                        <tr>
                            <td class="font-mono" style="text-align:center;"><?= (int) $l['line_number'] ?></td>
                            <td class="font-mono" style="font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                            <td><?= e($l['description'] ?? '') ?></td>
                            <td class="font-mono text-right"<?= $dr ? ' style="font-weight:600;"' : ' style="color:var(--text-secondary);"' ?>><?= $dr ? e(format_currency($l['debit'])) : '—' ?></td>
                            <td class="font-mono text-right"<?= $cr ? ' style="font-weight:600;"' : ' style="color:var(--text-secondary);"' ?>><?= $cr ? e(format_currency($l['credit'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-right" style="font-weight:700;">Totals<?= $isBalanced ? '' : ' <span class="badge badge-red">unbalanced</span>' ?></td>
                            <td class="font-mono text-right" style="font-weight:700;"><?= e(format_currency($sumDr)) ?></td>
                            <td class="font-mono text-right" style="font-weight:700;"><?= e(format_currency($sumCr)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Posting history -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Posting history <span class="text-secondary" style="font-weight:400;font-size:0.78rem;margin-left:6px;"><?= $historyTotal ?> JE(s)<?= $historyTotal > 24 ? ', latest 24 shown' : '' ?></span></h3></div>
        <div class="card-body">
        <?php if (empty($history)): ?>
            <p class="text-secondary" style="margin:0;font-size:0.8125rem;">No JEs posted yet for this template.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Entry #</th>
                            <th>Entry date</th>
                            <th style="text-align:center;">Status</th>
                            <th>Reference</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="font-mono">
                                <a class="link" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $h['id']) ?>"><?= e($h['entry_number']) ?></a>
                            </td>
                            <td class="font-mono"><?= e(format_date($h['entry_date'])) ?></td>
                            <td style="text-align:center;">
                                <span class="badge <?= $h['status'] === 'posted' ? 'badge-green' : ($h['status'] === 'reversed' ? 'badge-red' : 'badge-neutral') ?>"><?= e($h['status']) ?></span>
                            </td>
                            <td class="font-mono" style="font-size:0.78rem;"><?= e($h['reference']) ?></td>
                            <td><?= e($h['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the template at a glance ─────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($missedDates) {
    // The old overdue banner, now the first attention item.
    $alerts[] = ['danger', '<b>Overdue</b> — ' . count($missedDates) . ' occurrence' . (count($missedDates) === 1 ? '' : 's') . ' not posted ('
        . e($missedDates[0]) . (count($missedDates) > 1 ? ' → ' . e(end($missedDates)) : '') . ').'
        . ($canPostNow ? ' “Catch up now” posts each one once, dated on its scheduled date' . (((int) $template['auto_post']) === 1 ? '' : ' (as drafts for review — this template is not auto-post)') . '.' : '')];
    $alerts[] = $cronOn
        ? ['info', 'The nightly job posts every missed occurrence on its next run (up to 24 per run).']
        : ['warning', 'The nightly <b>Recurring journal entries</b> job is switched <b>off</b> in Settings → Scheduled Jobs, so nothing posts automatically.'];
} elseif ($tplActive && !$cronOn && !$hasEnded) {
    $alerts[] = ['warning', 'The nightly <b>Recurring journal entries</b> job is off — this template only posts when someone presses Post Now.'];
}
if (empty($lines)) {
    $alerts[] = ['warning', 'No lines — a posting would fail.'];
} elseif (!$isBalanced) {
    $alerts[] = ['danger', 'Lines do not balance (debits ' . e(format_currency($sumDr)) . ' vs credits ' . e(format_currency($sumCr)) . ').'];
}
if (!$tplActive) {
    $alerts[] = ['info', 'Paused — nothing posts until it is unpaused.'];
}
if ($hasEnded) {
    $alerts[] = ['info', 'Ended ' . e(format_date($template['end_date'])) . ' — no further postings.'];
}
if ((int) $template['auto_post'] !== 1 && $tplActive) {
    $alerts[] = ['info', 'Posts as drafts — review and post them from <a href="' . e(base_url('accounting/journal-entries')) . '">Journal Entries</a>.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — on schedule.'), ['icon' => 'exclamation-triangle']);

$perLabel = match ((string) $template['frequency']) { 'annually' => 'year', 'quarterly' => 'quarter', default => 'month' };
$rail[] = $R::card('Schedule', $R::big(e(format_currency($sumDr)), 'per ' . $perLabel)
    . $R::kv([
        ['Scheduler', $cronOn ? 'On' : '<span style="color:var(--color-warning);">Off</span>'],
        ['Posts', (int) $template['auto_post'] === 1 ? 'Automatically' : 'As drafts'],
        ['Created', e($template['created_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($template['created_at'])) . '</span>'],
        ['Updated', !empty($template['updated_at']) ? e(format_datetime($template['updated_at'])) : null],
    ]),
    ['icon' => 'arrow-path', 'class' => 'rec-card--accent']);

$rel = [
    ['All recurring entries', base_url('accounting/recurring-entries'), 'list-bullet'],
    ['Journal entries', base_url('accounting/journal-entries'), 'book-open'],
];
if (!empty($history)) {
    $rel[] = ['Latest: ' . $history[0]['entry_number'], base_url('accounting/journal-entries/show?id=' . (int) $history[0]['id']), 'document-text', format_date($history[0]['entry_date'])];
}
if (can('settings', 'view')) {
    $rel[] = ['Scheduled jobs', base_url('settings') . '#intelligence', 'cog-6-tooth', 'Settings'];
}
$rail[] = $R::card('Related', $R::links($rel), ['icon' => 'document-duplicate']);
?>
<aside class="rec-rail" aria-label="Recurring entry at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->
</div><!-- /x-data recurringShow -->

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
