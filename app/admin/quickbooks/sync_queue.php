<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/sync_queue.php
 *
 * Sync Queue admin view. Lists acc_qbo_sync_queue rows with
 * status/entity/operation/sort filters + per-row retry/cancel
 * actions + super_admin-only bulk Retry/Clear-Completed/Clear-Failed.
 *
 * The worker (cron/qbo_sync_worker.php) writes to this table; the
 * Sync Queue page is purely operational visibility — it does NOT
 * dispatch QBO calls itself. Retry resets a failed row to queued
 * so the next worker tick picks it up.
 *
 * Empty-state safe: when no rows match the filters, shows
 * contextual messaging tied to Phase QBO buildout status.
 *
 * Spec ref: §6.4 (queue table + states), §6.7 (worker pickup order)
 * Session:  S-QBO-4
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$canForceResync = can('quickbooks', 'force_resync');
$canClearQueue  = can('quickbooks', 'clear_queue');

$pageTitle = 'QuickBooks Sync Queue';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Sync Queue</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Sync Queue</h1>
</div>

<div x-data="qboSyncQueue()" x-init="init()">

    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:14px;"
         x-text="flash.message"></div>

    <!-- ── Filters bar ─────────────────────────────────────── -->
    <div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div>
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" x-model="filters.status" @change="reload()">
                <option value="">All</option>
                <option value="queued">Queued</option>
                <option value="processing">Processing</option>
                <option value="failed">Failed</option>
                <option value="skipped">Skipped</option>
                <option value="completed">Completed</option>
            </select>
        </div>
        <div>
            <label class="form-label">Entity</label>
            <select class="form-select form-select-sm" x-model="filters.entity" @change="reload()">
                <option value="">All</option>
                <template x-for="e in entityTypes" :key="e"><option :value="e" x-text="e"></option></template>
            </select>
        </div>
        <div>
            <label class="form-label">Operation</label>
            <select class="form-select form-select-sm" x-model="filters.operation" @change="reload()">
                <option value="">All</option>
                <option value="create">create</option>
                <option value="update">update</option>
                <option value="void">void</option>
                <option value="delete">delete</option>
            </select>
        </div>
        <div>
            <label class="form-label">Sort</label>
            <select class="form-select form-select-sm" x-model="filters.sort" @change="reload()">
                <option value="priority_enqueued">Pickup order (default)</option>
                <option value="enqueued_desc">Enqueued ↓</option>
                <option value="enqueued_asc">Enqueued ↑</option>
                <option value="priority_desc">Priority ↓</option>
                <option value="retry_desc">Retry count ↓</option>
            </select>
        </div>
        <div style="margin-left:auto;">
            <button class="btn btn-secondary btn-sm" @click="reload()">↻ Refresh</button>
        </div>
    </div>

    <!-- ── Bulk actions bar (super_admin / extended-action gates) ── -->
    <?php if ($canForceResync || $canClearQueue): ?>
    <div class="card" style="padding:12px 18px;margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span class="text-secondary text-sm" x-text="selectedCount() + ' selected'"></span>
        <?php if ($canForceResync): ?>
        <button class="btn btn-secondary btn-sm" :disabled="selectedCount()===0 || busy" @click="bulkRetry()">Retry Selected</button>
        <?php endif; ?>
        <?php if ($canClearQueue): ?>
        <button class="btn btn-secondary btn-sm" :disabled="selectedCount()===0 || busy" @click="bulkClearSelected()">Delete Selected</button>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button class="btn btn-outline-danger btn-sm" :disabled="busy" @click="bulkClearScope('completed')">Clear Completed (&gt;7d)</button>
            <button class="btn btn-outline-danger btn-sm" :disabled="busy" @click="bulkClearScope('failed')">Clear Failed (&gt;30d)</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Rows table ──────────────────────────────────────── -->
    <div class="card" style="padding:0;">
        <template x-if="!loading && rows.length === 0">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">
                Sync queue is empty. Items will be enqueued when sync turns on at S-QBO-30 and Pushers exist (S-QBO-5+).
            </div>
        </template>

        <template x-if="loading">
            <div style="padding:24px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">Loading…</div>
        </template>

        <template x-if="!loading && rows.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if ($canForceResync || $canClearQueue): ?>
                            <th style="width:32px;"><input type="checkbox" @change="toggleSelectAll($event.target.checked)"></th>
                            <?php endif; ?>
                            <th>ID</th>
                            <th>Entity</th>
                            <th>Op</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Retries</th>
                            <th>Enqueued</th>
                            <th>Next Retry</th>
                            <th>Error</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                <?php if ($canForceResync || $canClearQueue): ?>
                                <td><input type="checkbox" :checked="selected.includes(row.id)" @change="toggleRow(row.id)"></td>
                                <?php endif; ?>
                                <td class="font-mono text-sm" x-text="'#' + row.id"></td>
                                <td class="text-sm" x-text="row.entity_type + '#' + row.entity_id"></td>
                                <td class="text-sm" x-text="row.operation"></td>
                                <td><span class="badge" :class="statusBadgeClass(row.status)" x-text="row.status"></span></td>
                                <td class="text-sm" x-text="row.priority"></td>
                                <td class="text-sm font-mono" x-text="row.retry_count + '/' + row.max_retries"></td>
                                <td class="text-sm font-mono" x-text="formatTs(row.enqueued_at)"></td>
                                <td class="text-sm font-mono" x-text="formatTs(row.next_retry_at)"></td>
                                <td class="text-sm" :title="row.error_message || ''" x-text="row.error_code || '—'"></td>
                                <td class="text-right">
                                    <?php if ($canForceResync): ?>
                                    <button class="btn btn-link btn-sm" x-show="row.status==='failed' || row.status==='skipped'" @click="retryOne(row.id)">Retry</button>
                                    <?php endif; ?>
                                    <a class="btn btn-link btn-sm" :href="'<?= base_url('quickbooks/sync_log') ?>?queue_id=' + row.id">View Log</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── Pagination ───────────────────────────────────── -->
        <div x-show="total > 0" x-cloak style="padding:12px 18px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-color);">
            <span class="text-secondary text-sm" x-text="'Page ' + page + ' of ' + totalPages + ' · ' + total + ' total'"></span>
            <div style="display:flex;gap:6px;">
                <button class="btn btn-secondary btn-sm" :disabled="page<=1" @click="page--; reload()">← Prev</button>
                <button class="btn btn-secondary btn-sm" :disabled="page>=totalPages" @click="page++; reload()">Next →</button>
            </div>
        </div>
    </div>
</div>

<script>
function qboSyncQueue() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        perPage:    25,
        totalPages: 1,
        loading:    false,
        busy:       false,
        selected:   [],
        flash:      { message: '', type: 'success' },
        filters: {
            status:    'queued',
            entity:    '',
            operation: '',
            sort:      'priority_enqueued',
        },
        entityTypes: ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code'],

        async init() { await this.reload(); },

        async reload() {
            this.loading = true;
            this.selected = [];
            try {
                const qs = new URLSearchParams({
                    status:    this.filters.status,
                    entity:    this.filters.entity,
                    operation: this.filters.operation,
                    sort:      this.filters.sort,
                    page:      this.page,
                    per_page:  this.perPage,
                });
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/sync_queue_list.php?' + qs.toString()), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const j = await r.json();
                if (j.success) {
                    const d = j.data;
                    this.rows       = d.rows;
                    this.total      = d.total;
                    this.totalPages = d.total_pages;
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Load failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.loading = false;
            }
        },

        selectedCount() { return this.selected.length; },
        toggleRow(id) {
            const i = this.selected.indexOf(id);
            if (i === -1) this.selected.push(id); else this.selected.splice(i, 1);
        },
        toggleSelectAll(on) {
            this.selected = on ? this.rows.map(r => r.id) : [];
        },

        statusBadgeClass(status) {
            return {
                queued:     'badge badge-info',
                processing: 'badge badge-warning',
                failed:     'badge badge-danger',
                skipped:    'badge badge-neutral',
                completed:  'badge badge-success',
            }[status] || 'badge badge-neutral';
        },

        formatTs(s) {
            if (!s) return '—';
            try { return new Date(s.replace(' ', 'T')).toLocaleString(); } catch { return s; }
        },

        async retryOne(id) {
            if (!confirm('Reset queue row #' + id + ' back to queued?')) return;
            this.busy = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/sync_queue_retry.php'), { id });
                if (j.success) {
                    this.flash = { message: 'Row reset. Worker will retry on next tick.', type: 'success' };
                    await this.reload();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Retry failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.busy = false; }
        },

        async bulkRetry() {
            if (this.selected.length === 0) return;
            if (!confirm('Retry ' + this.selected.length + ' selected row(s)?')) return;
            this.busy = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/sync_queue_retry.php'), { ids: this.selected });
                if (j.success) {
                    const d = j.data;
                    let msg = d.reset_count + ' row(s) reset.';
                    if (d.skipped_ids.length > 0) msg += ' ' + d.skipped_ids.length + ' skipped (wrong status).';
                    this.flash = { message: msg, type: 'success' };
                    await this.reload();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Bulk retry failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.busy = false; }
        },

        async bulkClearSelected() {
            if (this.selected.length === 0) return;
            if (!confirm('Permanently delete ' + this.selected.length + ' selected row(s)? (Only completed/failed/skipped rows will be deleted.)')) return;
            this.busy = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/sync_queue_clear.php'), { scope: 'selected', ids: this.selected });
                if (j.success) {
                    this.flash = { message: j.data.deleted_count + ' row(s) deleted.', type: 'success' };
                    await this.reload();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Delete failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.busy = false; }
        },

        async bulkClearScope(scope) {
            const desc = scope === 'completed' ? 'completed rows older than 7 days' : 'failed rows older than 30 days';
            if (!confirm('Permanently delete ALL ' + desc + '?')) return;
            this.busy = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/sync_queue_clear.php'), { scope });
                if (j.success) {
                    this.flash = { message: j.data.deleted_count + ' row(s) deleted (scope=' + scope + ').', type: 'success' };
                    await this.reload();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Clear failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally { this.busy = false; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
