<?php
declare(strict_types=1);

/**
 * app/admin/quickbooks/manual_sync.php
 *
 * QuickBooks Manual Sync page (Phase QBO-12 / 3 of 3, S-QBO-26). The bulk
 * reconciliation surface per spec §15.6 — companion to the per-event +
 * bulk-by-category drift resolution shipped in S-QBO-25.
 *
 * Three operator actions, all gated on quickbooks/force_full_resync:
 *   • Force full re-sync of an entity type → bulk re-enqueue every mapped
 *     row via its Enqueuer (8 push types). Honors the Enqueuer gates
 *     (origin / status / sync_enabled / sync_mode / bridge-derived), so a
 *     disabled entity or the master kill-switch silently skips with a
 *     surfaced reason (D-QBO-26-1 / D-QBO-26-4).
 *   • Force pull from QBO → bulk refresh + SyncToken write for the 5
 *     pull-capable types (customer/vendor/account/item/tax_code). Push-only
 *     types show no pull button (D-QBO-26-2; historical pull is S-QBO-27).
 *   • Reset SyncToken → NULL qbo_sync_token for an entity type (realm-
 *     migration recovery; extra-guarded separate action — D-QBO-26-3).
 *
 * @session  S-QBO-26
 * @depends  api/v1/quickbooks/manual_sync.php, FF_Api
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

// The page itself is view-gated for navigation, but every action requires
// the heavy force_full_resync permission — the action buttons are hidden
// (and the endpoint rejects) without it.
$canForceSync = can('quickbooks', 'force_full_resync');

$pageTitle = 'QuickBooks Manual Sync';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Manual Sync</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Manual Sync</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Bulk reconciliation tools (spec §15.6). <strong>Force re-sync</strong> re-enqueues every mapped row of an entity type
        through its normal push pipeline — it respects the master sync switch + per-entity sync mode, so nothing is pushed
        while sync is disabled. <strong>Force pull</strong> refreshes the QBO-side snapshot + SyncToken for reference data.
        <strong>Reset SyncToken</strong> is the realm-migration recovery tool. All actions are audit-logged.
    </div>
</div>

<?php if (!$canForceSync): ?>
    <div class="card" style="padding:18px;margin-top:14px;">
        <p class="text-secondary text-sm" style="margin:0;">
            You have read access to this page but the manual-sync actions require the
            <code>force_full_resync</code> permission on the QuickBooks module. Ask a super-admin to grant it.
        </p>
    </div>
<?php else: ?>

<div x-data="qboManualSync()">

    <!-- Flash -->
    <template x-if="flash.message">
        <div class="alert" :class="'alert-' + (flash.type === 'error' ? 'danger' : flash.type)"
             style="margin:14px 0;" x-text="flash.message"></div>
    </template>

    <!-- ── Force re-sync (push entity types) ─────────────────── -->
    <div class="card" style="padding:18px;margin-top:14px;">
        <h2 class="h6" style="margin:0 0 4px;">Force full re-sync of entity type</h2>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Re-enqueues a <code>create</code> push for <em>every</em> mapped row of the type. Used after a known data issue
            or after the accountant manually edited many QBO records. Ineligible rows (wrong status, webhook-originated
            payments, bridge-derived JEs) are skipped automatically.
        </p>
        <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>Entity type</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    <template x-for="t in pushTypes" :key="t.key">
                        <tr>
                            <td x-text="t.label"></td>
                            <td class="text-right">
                                <button class="btn btn-secondary btn-sm"
                                        :disabled="busy"
                                        @click="confirmResync(t)">Force re-sync</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Force pull from QBO (pull-capable types) ──────────── -->
    <div class="card" style="padding:18px;margin-top:14px;">
        <h2 class="h6" style="margin:0 0 4px;">Force pull from QBO</h2>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Pulls the current state of every mapped reference entity from QBO and refreshes the SyncToken
            (used after realm migration or a long disconnection). Only available for reference data —
            transactional types (invoices, payments, bills, …) are inbound-migrated via the historical pull (S-QBO-27).
        </p>
        <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>Entity type</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    <template x-for="t in pullTypes" :key="t.key">
                        <tr>
                            <td x-text="t.label"></td>
                            <td class="text-right">
                                <button class="btn btn-secondary btn-sm"
                                        :disabled="busy"
                                        @click="confirmPull(t)">Force pull</button>
                                <button class="btn btn-secondary btn-sm"
                                        :disabled="busy"
                                        style="margin-left:6px;"
                                        title="NULL the SyncToken for every mapped row — realm-migration recovery only."
                                        @click="confirmReset(t)">Reset SyncToken</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Confirm modal ─────────────────────────────────────── -->
    <div x-show="confirm.open" x-cloak class="modal-overlay" @click.self="closeConfirm()">
        <div class="modal-backdrop" @click="closeConfirm()" aria-hidden="true"></div>
        <div class="modal modal-md" @click.stop>
            <div class="modal-header">
                <h3 class="h5" style="margin:0;" x-text="confirm.title"></h3>
                <button class="modal-close" @click="closeConfirm()">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-sm" x-html="confirm.body"></p>
                <p class="text-sm" x-show="confirm.danger" x-cloak
                   style="color:var(--color-danger);margin-top:10px;">
                    This is a recovery tool — only use it after a realm migration or a long disconnection.
                </p>
            </div>
            <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
                <button class="btn btn-secondary btn-sm" @click="closeConfirm()" :disabled="busy">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="runConfirmed()" :disabled="busy">
                    <span x-show="!busy" x-text="confirm.cta"></span>
                    <span x-show="busy" x-cloak>Working…</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function qboManualSync() {
    return {
        busy: false,
        flash: { message: '', type: 'success' },
        pushTypes: [
            { key: 'invoice',       label: 'Invoices' },
            { key: 'payment',       label: 'Payments' },
            { key: 'bill',          label: 'Bills' },
            { key: 'bill_payment',  label: 'Bill Payments' },
            { key: 'credit_memo',   label: 'Credit Memos' },
            { key: 'journal_entry', label: 'Journal Entries' },
            { key: 'customer',      label: 'Customers' },
            { key: 'vendor',        label: 'Vendors' },
        ],
        pullTypes: [
            { key: 'customer', label: 'Customers' },
            { key: 'vendor',   label: 'Vendors' },
            { key: 'account',  label: 'Accounts' },
            { key: 'item',     label: 'Items' },
            { key: 'tax_code', label: 'Tax Codes' },
        ],
        confirm: { open: false, title: '', body: '', cta: '', danger: false, action: null, entity: null },

        confirmResync(t) {
            this.confirm = {
                open: true, action: 'force_resync', entity: t.key,
                title: 'Force re-sync ' + t.label,
                body: 'Re-enqueue a push for <strong>every mapped ' + t.label.toLowerCase() +
                      '</strong> row. Ineligible rows are skipped automatically. Nothing is pushed while ' +
                      'the master sync switch is off.',
                cta: 'Force re-sync', danger: false,
            };
        },
        confirmPull(t) {
            this.confirm = {
                open: true, action: 'force_pull', entity: t.key,
                title: 'Force pull ' + t.label,
                body: 'Pull current ' + t.label.toLowerCase() + ' state from QBO and refresh the SyncToken snapshot.',
                cta: 'Force pull', danger: false,
            };
        },
        confirmReset(t) {
            this.confirm = {
                open: true, action: 'reset_synctoken', entity: t.key,
                title: 'Reset SyncToken for ' + t.label,
                body: 'NULL the <code>qbo_sync_token</code> on every mapped ' + t.label.toLowerCase() +
                      ' row. The next pull/push re-establishes tokens.',
                cta: 'Reset SyncToken', danger: true,
            };
        },
        closeConfirm() { this.confirm.open = false; },

        async runConfirmed() {
            const action = this.confirm.action;
            const entity = this.confirm.entity;
            this.busy = true;
            try {
                const j = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/manual_sync.php'), {
                    action, entity_type: entity,
                });
                if (j.success) {
                    const d = j.data || {};
                    let msg;
                    if (action === 'force_resync') {
                        msg = 'Re-sync ' + entity + ': ' + (d.enqueued ?? 0) + ' enqueued / ' +
                              (d.skipped ?? 0) + ' skipped of ' + (d.total ?? 0) + '.' +
                              (d.reason ? ' ' + d.reason : '');
                    } else if (action === 'force_pull') {
                        msg = 'Pulled ' + (d.pulled ?? 0) + ' ' + entity + ' record(s); ' +
                              (d.updated ?? 0) + ' token(s) refreshed.';
                    } else {
                        msg = 'Reset SyncToken on ' + (d.reset ?? 0) + ' ' + entity + ' row(s).';
                    }
                    this.flash = { message: msg, type: 'success' };
                    this.closeConfirm();
                } else {
                    this.flash = { message: (j.error && j.error.message) || 'Action failed.', type: 'error' };
                    this.closeConfirm();
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
                this.closeConfirm();
            } finally { this.busy = false; }
        },
    };
}
</script>

<?php endif; ?>
