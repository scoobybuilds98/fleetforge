<?php
declare(strict_types=1);

/**
 * FleetForge — Reusable Activity Log Partial
 *
 * @file        includes/partials/activity-log.php
 * @description Renders a lazy-loaded, paginated timeline of every human-
 *              initiated change to an entity. Consumed by every entity show
 *              page (customers, leases, equipment, users, vendors, etc.).
 *
 * Required PHP variables (set by the including page before this require):
 *   string      $activityEntityType  — audit_log.entity_type  (e.g. 'customer')
 *   int         $activityEntityId    — the entity's primary key
 *
 * Optional PHP variables for guaranteed origin event:
 *   string      $activityOriginAt    — entity created_at datetime (always shows creation)
 *   string|null $activityOriginBy    — name of the user who created the entity
 *
 * When $activityOriginAt is set the API will inject a "Record created" entry at
 * the bottom of the last page if no 'create' action already exists in audit_log,
 * so creation is always visible even for records that pre-date the audit log.
 *
 * The FF_ActivityLog Alpine component is defined once per page via a PHP
 * constant guard so including this partial inside multiple tabs is safe.
 *
 * @session     S-ACTIVITY-LOG
 */

// Defensive: caller must set these.
if (!isset($activityEntityType, $activityEntityId)) {
    return;
}
$activityEntityType = (string) $activityEntityType;
$activityEntityId   = (int) $activityEntityId;
$activityOriginAt   = isset($activityOriginAt) ? (string) $activityOriginAt : '';
$activityOriginBy   = isset($activityOriginBy) ? (string) ($activityOriginBy ?? '') : '';
?>

<div x-data="FF_ActivityLog(
        '<?= e($activityEntityType) ?>',
        <?= $activityEntityId ?>,
        '<?= e(base_url('api/v1/audit/history')) ?>',
        '<?= e($activityOriginAt) ?>',
        '<?= e($activityOriginBy) ?>'
     )"
     x-init="load()">

    <!-- Loading skeleton -->
    <template x-if="loading">
        <div style="padding:24px 0;">
            <div class="skeleton skeleton-row" style="margin-bottom:12px;"></div>
            <div class="skeleton skeleton-row" style="margin-bottom:12px;width:80%;"></div>
            <div class="skeleton skeleton-row" style="width:60%;"></div>
        </div>
    </template>

    <!-- Error state -->
    <template x-if="!loading && error">
        <div class="empty-state" style="padding:32px 0;">
            <p class="empty-state-title">Could not load activity</p>
            <p class="empty-state-text" x-text="error"></p>
            <button class="btn btn-ghost btn-sm" style="margin-top:12px;" @click="load()">Retry</button>
        </div>
    </template>

    <!-- Empty state -->
    <template x-if="!loading && !error && items.length === 0">
        <div class="empty-state" style="padding:32px 0;">
            <p class="empty-state-title">No activity recorded yet</p>
            <p class="empty-state-text">Changes made to this record will appear here.</p>
        </div>
    </template>

    <!-- Timeline -->
    <template x-if="!loading && !error && items.length > 0">
        <div>
            <div class="activity-log-section">
                <template x-for="item in items" :key="item.id">
                    <div class="activity-item">
                        <div class="activity-dot" :class="dotClass(item.action)"></div>
                        <div class="activity-body" style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                                <span class="activity-action" x-text="actionLabel(item.action)"></span>
                                <span class="text-secondary text-sm">by</span>
                                <strong class="text-sm" x-text="item.user_name"></strong>
                                <span class="text-secondary text-sm" style="margin-left:auto;" x-text="formatDate(item.created_at)"></span>
                            </div>
                            <!-- Notes line -->
                            <template x-if="item.notes">
                                <p class="text-sm text-secondary" style="margin:4px 0 0;" x-text="item.notes"></p>
                            </template>
                            <!-- Field-level change diffs -->
                            <template x-if="item.changes.length > 0">
                                <div style="margin-top:6px;display:flex;flex-direction:column;gap:3px;">
                                    <template x-for="(ch, i) in item.changes" :key="i">
                                        <div class="text-sm" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                            <span class="text-secondary" x-text="formatField(ch.field)"></span>
                                            <template x-if="ch.from !== null && ch.from !== ''">
                                                <span style="color:var(--color-danger);text-decoration:line-through;word-break:break-all;" x-text="formatValue(ch.from)"></span>
                                            </template>
                                            <template x-if="ch.from !== null && ch.from !== ''">
                                                <span class="text-secondary">→</span>
                                            </template>
                                            <span style="color:var(--color-success);word-break:break-all;" x-text="formatValue(ch.to)"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <template x-if="totalPages > 1">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 0 0;border-top:1px solid var(--color-border);">
                    <button class="btn btn-ghost btn-sm"
                            :disabled="page <= 1"
                            @click="changePage(page - 1)">← Previous</button>
                    <span class="text-sm text-secondary"
                          x-text="'Page ' + page + ' of ' + totalPages + ' (' + total + ' events)'"></span>
                    <button class="btn btn-ghost btn-sm"
                            :disabled="page >= totalPages"
                            @click="changePage(page + 1)">Next →</button>
                </div>
            </template>
        </div>
    </template>

</div>

<?php if (!defined('FF_ACTIVITY_LOG_JS_LOADED')): define('FF_ACTIVITY_LOG_JS_LOADED', true); ?>
<script>
function FF_ActivityLog(entityType, entityId, apiUrl, originAt, originBy) {
    return {
        entityType,
        entityId,
        apiUrl,
        originAt:  originAt  || '',
        originBy:  originBy  || '',

        items:    [],
        total:    0,
        page:     1,
        perPage:  25,
        loading:  false,
        error:    null,

        get totalPages() {
            return this.perPage > 0 ? Math.ceil(this.total / this.perPage) : 1;
        },

        async load() {
            if (this.loading) return;
            this.loading = true;
            this.error   = null;
            try {
                let url = this.apiUrl
                    + '?entity_type=' + encodeURIComponent(this.entityType)
                    + '&entity_id='   + encodeURIComponent(this.entityId)
                    + '&page='        + this.page;
                if (this.originAt) {
                    url += '&origin_at=' + encodeURIComponent(this.originAt);
                    if (this.originBy) {
                        url += '&origin_by=' + encodeURIComponent(this.originBy);
                    }
                }
                const res  = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const json = await res.json();
                if (!json.success) throw new Error(json.error?.message || 'Failed to load.');
                this.items  = json.data.items;
                this.total  = json.data.total;
            } catch (err) {
                this.error = 'Could not load activity log. Please refresh and try again.';
                console.error('[FF_ActivityLog]', err);
            } finally {
                this.loading = false;
            }
        },

        async changePage(p) {
            if (p < 1 || p > this.totalPages) return;
            this.page = p;
            await this.load();
        },

        // ── Display helpers ─────────────────────────────────────────────

        actionLabel(action) {
            const map = {
                create:           'Created',
                update:           'Updated',
                delete:           'Deleted',
                restore:          'Restored',
                login:            'Logged in',
                logout:           'Logged out',
                export:           'Exported',
                status_change:    'Status changed',
                bulk_action:      'Bulk action',
                payment_recorded: 'Payment recorded',
                invoice_sent:     'Invoice sent',
                invoice_voided:   'Invoice voided',
                lease_closed:     'Lease closed',
                manual_trigger:   'Manual action',
            };
            return map[action] ?? action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },

        dotClass(action) {
            const map = {
                create:           'action-create',
                restore:          'action-create',
                payment_recorded: 'action-payment_recorded',
                invoice_sent:     'action-invoice_sent',
                update:           'action-update',
                status_change:    'action-status_change',
                delete:           'action-delete',
                invoice_voided:   'action-invoice_voided',
            };
            return 'activity-dot ' + (map[action] ?? 'action-update');
        },

        formatField(key) {
            // Convert snake_case keys to "Title Case Words" for display.
            return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },

        formatValue(val) {
            if (val === null || val === undefined) return '(none)';
            if (val === '')                        return '(empty)';
            if (typeof val === 'boolean')          return val ? 'Yes' : 'No';
            if (typeof val === 'object')           return JSON.stringify(val);
            // Truncate very long values (e.g. base64 data, JSON blobs)
            const s = String(val);
            return s.length > 120 ? s.slice(0, 120) + '…' : s;
        },

        formatDate(dt) {
            if (!dt) return '';
            // MySQL DATETIME is stored in server local time (America/Vancouver).
            // Replace the space separator so Date() parses it without a TZ suffix.
            try {
                const d = new Date(dt.replace(' ', 'T'));
                return d.toLocaleString(undefined, {
                    year: 'numeric', month: 'short', day: 'numeric',
                    hour: '2-digit', minute: '2-digit',
                });
            } catch (_) {
                return dt;
            }
        },
    };
}
</script>
<?php endif; ?>
