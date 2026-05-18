<?php declare(strict_types=1);

/**
 * app/admin/accounting/recurring-entries/index.php
 *
 * List recurring JE templates. Server-rendered for the list itself
 * (table is small + we want first-paint state); Alpine handles
 * Pause/Unpause toggles inline.
 *
 * @session S037-REC
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$canCreate = can('journal_entries', 'create');
$canEdit   = can('journal_entries', 'edit');

$filter = clean_string($_GET['filter'] ?? null) ?: 'all';
$where  = ['1=1'];
$params = [];
if ($filter === 'active')   { $where[] = 't.is_active = 1'; }
if ($filter === 'paused')   { $where[] = 't.is_active = 0'; }
$whereSql = implode(' AND ', $where);

$rows = db_select(
    "SELECT t.id, t.name, t.description, t.frequency, t.day_of_month,
            t.start_date, t.end_date, t.next_post_date, t.last_posted_date,
            t.is_active, t.auto_post, t.created_at,
            u.name AS created_by_name,
            (SELECT COUNT(*) FROM acc_recurring_entry_lines WHERE recurring_entry_id = t.id) AS line_count,
            (SELECT COUNT(*) FROM acc_journal_entries
              WHERE source_type='recurring' AND source_id = t.id) AS post_count
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE {$whereSql}
      ORDER BY t.is_active DESC, t.name ASC",
    $params
);

$pageTitle = 'Recurring Entries';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Recurring Entries</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Recurring Journal Entry Templates</h1>
    <div class="page-header-actions">
        <?php if ($canCreate): ?>
            <a class="btn btn-primary btn-sm" href="<?= base_url('accounting/recurring-entries/create') ?>">+ New Template</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="{}" style="margin-bottom:14px;">
    <div class="card" style="padding:10px 14px;display:flex;gap:10px;align-items:center;">
        <span style="font-size:0.8125rem;color:var(--text-secondary);">Filter:</span>
        <a href="?filter=all"    class="btn btn-xs <?= $filter === 'all'    ? 'btn-primary'   : 'btn-secondary' ?>">All</a>
        <a href="?filter=active" class="btn btn-xs <?= $filter === 'active' ? 'btn-primary'   : 'btn-secondary' ?>">Active</a>
        <a href="?filter=paused" class="btn btn-xs <?= $filter === 'paused' ? 'btn-primary'   : 'btn-secondary' ?>">Paused</a>
    </div>
</div>

<?php if (empty($rows)): ?>
    <div class="card" style="padding:48px;text-align:center;">
        <div style="font-size:1rem;font-weight:600;margin-bottom:6px;">No recurring templates</div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);margin-bottom:14px;">
            Create your first template to automate monthly accruals, prepaid amortization, or any
            other recurring JE.
        </div>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary btn-sm" href="<?= base_url('accounting/recurring-entries/create') ?>">+ New Template</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:9px 12px;text-align:left;">Name</th>
                    <th style="padding:9px 12px;text-align:left;">Frequency</th>
                    <th style="padding:9px 12px;text-align:right;">Day</th>
                    <th style="padding:9px 12px;text-align:left;">Next Post</th>
                    <th style="padding:9px 12px;text-align:left;">Last Posted</th>
                    <th style="padding:9px 12px;text-align:center;">Auto-Post</th>
                    <th style="padding:9px 12px;text-align:right;">Lines</th>
                    <th style="padding:9px 12px;text-align:right;">Posts</th>
                    <th style="padding:9px 12px;text-align:center;">Status</th>
                    <th style="padding:9px 12px;text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="border-bottom:1px solid var(--border-default);<?= (int) $r['is_active'] === 0 ? 'opacity:0.65;' : '' ?>">
                        <td style="padding:8px 12px;">
                            <a href="<?= base_url('accounting/recurring-entries/show?id=' . (int) $r['id']) ?>" style="color:var(--color-accent);text-decoration:none;font-weight:500;">
                                <?= e($r['name']) ?>
                            </a>
                            <?php if ($r['description']): ?>
                                <div style="font-size:0.7rem;color:var(--text-secondary);margin-top:1px;"><?= e($r['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;text-transform:capitalize;"><?= e($r['frequency']) ?></td>
                        <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= (int) $r['day_of_month'] ?></td>
                        <td class="font-mono" style="padding:8px 12px;"><?= e($r['next_post_date'] ?? '—') ?></td>
                        <td class="font-mono" style="padding:8px 12px;"><?= $r['last_posted_date'] ? e($r['last_posted_date']) : '<span style="color:var(--text-secondary);">never</span>' ?></td>
                        <td style="padding:8px 12px;text-align:center;">
                            <?= ((int) $r['auto_post']) === 1
                                ? '<span class="badge badge-green" style="font-size:0.65rem;">Auto</span>'
                                : '<span class="badge badge-neutral" style="font-size:0.65rem;">Draft</span>' ?>
                        </td>
                        <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= (int) $r['line_count'] ?></td>
                        <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= (int) $r['post_count'] ?></td>
                        <td style="padding:8px 12px;text-align:center;">
                            <?= ((int) $r['is_active']) === 1
                                ? '<span class="badge badge-green">Active</span>'
                                : '<span class="badge badge-red">Paused</span>' ?>
                        </td>
                        <td style="padding:8px 12px;text-align:center;white-space:nowrap;">
                            <a class="btn btn-ghost btn-xs" href="<?= base_url('accounting/recurring-entries/show?id=' . (int) $r['id']) ?>">View</a>
                            <?php if ($canEdit): ?>
                                <button class="btn btn-secondary btn-xs"
                                        x-data="{}"
                                        @click="togglePause(<?= (int) $r['id'] ?>)"
                                        x-text="'<?= ((int) $r['is_active']) === 1 ? 'Pause' : 'Unpause' ?>'">
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
async function togglePause(id) {
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const r = await fetch('<?= e(base_url('api/v1/accounting/recurring/pause.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ id })
        });
        const j = await r.json();
        if (j && j.success) {
            window.location.reload();
        } else {
            alert((j && j.error && j.error.message) || 'Toggle failed.');
        }
    } catch (e) { alert('Toggle failed: ' + e.message); }
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
