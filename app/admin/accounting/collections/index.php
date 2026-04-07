<?php declare(strict_types=1);

/**
 * app/admin/accounting/collections/index.php
 *
 * Collections management page — tabbed view with collection notes,
 * promise-to-pay tracking, and dunning letter history.
 * Customer selector drives all three tabs.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @session S031
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

// Pre-load customers with overdue invoices for the dropdown
$customers = db_select(
    "SELECT DISTINCT c.id, c.company_name, c.outstanding_balance
     FROM customers c
     JOIN invoices i ON i.customer_id = c.id AND i.deleted_at IS NULL
     WHERE c.deleted_at IS NULL AND i.status IN ('sent','overdue','partially_paid') AND i.balance_due > 0
     ORDER BY c.company_name",
    []
);

$pageTitle = 'Collections';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Collections</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Collections</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div x-data="collectionsPage()" x-init="init()">
    <div style="margin-bottom:20px;display:flex;justify-content:flex-end;align-items:end;gap:8px;">
        <div>
            <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Customer</label>
            <select x-model="customerId" @change="loadAll()" class="form-input" style="padding:8px 12px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;min-width:250px;">
                <option value="">Select customer...</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['company_name']) ?> (<?= format_currency($c['outstanding_balance']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar" style="margin-bottom:16px;">
        <button class="tab-btn" :class="tab === 'notes' && 'active'" @click="tab = 'notes'">
            Collection Notes <template x-if="notes.length > 0"><span class="tab-badge" x-text="notes.length"></span></template>
        </button>
        <button class="tab-btn" :class="tab === 'promises' && 'active'" @click="tab = 'promises'">
            Promise to Pay <template x-if="promises.length > 0"><span class="tab-badge" x-text="promises.length"></span></template>
        </button>
        <button class="tab-btn" :class="tab === 'dunning' && 'active'" @click="tab = 'dunning'">
            Dunning Letters <template x-if="dunning.length > 0"><span class="tab-badge" x-text="dunning.length"></span></template>
        </button>
        <button class="tab-btn" :class="tab === 'writeoffs' && 'active'" @click="tab = 'writeoffs'">
            Write-offs
        </button>
    </div>

    <!-- Empty state when no customer selected -->
    <template x-if="!customerId">
        <div class="card" style="padding:48px;text-align:center;">
            <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">Select a Customer</div>
            <div style="font-size:0.8125rem;color:var(--text-secondary);">Choose a customer above to view their collection activity.</div>
        </div>
    </template>

    <!-- COLLECTION NOTES TAB -->
    <template x-if="customerId && tab === 'notes'">
        <div>
            <!-- Add Note Form -->
            <div class="card" style="padding:16px;margin-bottom:16px;" x-show="can_create">
                <div style="font-weight:600;font-size:0.875rem;margin-bottom:12px;">Add Collection Note</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Date</label>
                        <input type="date" x-model="noteForm.note_date" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Contact Method</label>
                        <select x-model="noteForm.contact_method" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="phone">Phone</option>
                            <option value="email">Email</option>
                            <option value="letter">Letter</option>
                            <option value="in_person">In Person</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Outcome</label>
                        <select x-model="noteForm.outcome" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="no_answer">No Answer</option>
                            <option value="left_message">Left Message</option>
                            <option value="spoke_with_customer">Spoke with Customer</option>
                            <option value="payment_promised">Payment Promised</option>
                            <option value="dispute">Dispute</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Follow-up Date</label>
                        <input type="date" x-model="noteForm.follow_up_date" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Note</label>
                    <textarea x-model="noteForm.note" rows="3" class="form-input" style="width:100%;padding:8px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;resize:vertical;"></textarea>
                </div>
                <button class="btn btn-primary btn-sm" @click="saveNote()" :disabled="!noteForm.note">Save Note</button>
            </div>

            <!-- Notes List -->
            <div class="card" style="overflow-x:auto;">
                <template x-if="notes.length > 0">
                    <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-default);">
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Method</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Outcome</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Note</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Follow-up</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="n in notes" :key="n.id">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:8px 12px;white-space:nowrap;" x-text="n.note_date"></td>
                                    <td style="padding:8px 12px;">
                                        <span class="badge badge-neutral badge-sm" x-text="n.contact_method.replace('_',' ')"></span>
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <span class="badge badge-sm" :class="outcomeBadge(n.outcome)" x-text="n.outcome.replace(/_/g,' ')"></span>
                                    </td>
                                    <td style="padding:8px 12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;" x-text="n.note"></td>
                                    <td style="padding:8px 12px;white-space:nowrap;" x-text="n.follow_up_date || '—'"></td>
                                    <td style="padding:8px 12px;" x-text="n.created_by_name || '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
                <template x-if="notes.length === 0 && !loading">
                    <div style="padding:32px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">No collection notes yet.</div>
                </template>
            </div>
        </div>
    </template>

    <!-- PROMISE TO PAY TAB -->
    <template x-if="customerId && tab === 'promises'">
        <div>
            <div class="card" style="padding:16px;margin-bottom:16px;" x-show="can_create">
                <div style="font-weight:600;font-size:0.875rem;margin-bottom:12px;">Record Promise to Pay</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Promise Date</label>
                        <input type="date" x-model="promiseForm.promise_date" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Amount</label>
                        <input type="number" step="0.01" min="0" x-model="promiseForm.promised_amount" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Promised By</label>
                        <input type="text" x-model="promiseForm.promised_by" placeholder="Contact name" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Notes</label>
                        <input type="text" x-model="promiseForm.notes" class="form-input" style="width:100%;padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" @click="savePromise()" :disabled="!promiseForm.promised_amount || !promiseForm.promise_date">Record Promise</button>
            </div>

            <div class="card" style="overflow-x:auto;">
                <template x-if="promises.length > 0">
                    <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-default);">
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Promise Date</th>
                                <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Promised By</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Status</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Invoice</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Notes</th>
                                <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="p in promises" :key="p.id">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:8px 12px;white-space:nowrap;" x-text="p.promise_date"></td>
                                    <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="'$' + Number(p.promised_amount).toFixed(2)"></td>
                                    <td style="padding:8px 12px;" x-text="p.promised_by || '—'"></td>
                                    <td style="padding:8px 12px;">
                                        <span class="badge badge-sm" :class="promiseStatusBadge(p.status)" x-text="p.status"></span>
                                    </td>
                                    <td style="padding:8px 12px;" x-text="p.invoice_number || '—'"></td>
                                    <td style="padding:8px 12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;" x-text="p.notes || '—'"></td>
                                    <td style="padding:8px 12px;text-align:center;">
                                        <template x-if="p.status === 'pending' && can_edit">
                                            <div style="display:flex;gap:4px;justify-content:center;">
                                                <button class="btn btn-success btn-xs" @click="updatePromise(p.id, 'kept')">Kept</button>
                                                <button class="btn btn-danger btn-xs" @click="updatePromise(p.id, 'broken')">Broken</button>
                                            </div>
                                        </template>
                                        <template x-if="p.status !== 'pending'">
                                            <span style="color:var(--text-tertiary);font-size:0.75rem;">—</span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
                <template x-if="promises.length === 0 && !loading">
                    <div style="padding:32px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">No promise-to-pay records.</div>
                </template>
            </div>
        </div>
    </template>

    <!-- DUNNING TAB -->
    <template x-if="customerId && tab === 'dunning'">
        <div>
            <div class="card" style="padding:16px;margin-bottom:16px;" x-show="can_create">
                <div style="font-weight:600;font-size:0.875rem;margin-bottom:12px;">Generate Dunning Letter</div>
                <div style="display:flex;gap:10px;align-items:end;">
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Letter Type</label>
                        <select x-model="dunningForm.letter_type" class="form-input" style="padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="reminder_30">30-Day Reminder</option>
                            <option value="reminder_60">60-Day Second Notice</option>
                            <option value="warning_90">90-Day Final Warning</option>
                            <option value="final_notice">Final Notice / Collections</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Send Method</label>
                        <select x-model="dunningForm.sent_method" class="form-input" style="padding:6px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
                            <option value="email">Email</option>
                            <option value="mail">Mail</option>
                            <option value="both">Both</option>
                        </select>
                    </div>
                    <button class="btn btn-warning btn-sm" @click="sendDunning()" :disabled="dunningBusy" style="height:36px;">
                        <span x-show="!dunningBusy">Generate & Send</span>
                        <span x-show="dunningBusy">Generating...</span>
                    </button>
                </div>
            </div>

            <div class="card" style="overflow-x:auto;">
                <template x-if="dunning.length > 0">
                    <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-default);">
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date Sent</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Type</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Method</th>
                                <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Total Overdue</th>
                                <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Invoices</th>
                                <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Sent To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="d in dunning" :key="d.id">
                                <tr style="border-bottom:1px solid var(--border-default);">
                                    <td style="padding:8px 12px;white-space:nowrap;" x-text="d.sent_date"></td>
                                    <td style="padding:8px 12px;">
                                        <span class="badge badge-sm" :class="dunningBadge(d.letter_type)" x-text="dunningLabel(d.letter_type)"></span>
                                    </td>
                                    <td style="padding:8px 12px;" x-text="d.sent_method"></td>
                                    <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="'$' + Number(d.total_overdue).toFixed(2)"></td>
                                    <td style="padding:8px 12px;text-align:right;" x-text="d.invoice_count"></td>
                                    <td style="padding:8px 12px;" x-text="d.sent_to_email || '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </template>
                <template x-if="dunning.length === 0 && !loading">
                    <div style="padding:32px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">No dunning letters sent.</div>
                </template>
            </div>
        </div>
    </template>

    <!-- WRITE-OFFS TAB -->
    <template x-if="customerId && tab === 'writeoffs'">
        <div class="card" style="overflow-x:auto;">
            <template x-if="writeoffs.length > 0">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-default);">
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Invoice</th>
                            <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Reason</th>
                            <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Recovered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="w in writeoffs" :key="w.id">
                            <tr style="border-bottom:1px solid var(--border-default);">
                                <td style="padding:8px 12px;white-space:nowrap;" x-text="w.writeoff_date"></td>
                                <td style="padding:8px 12px;" x-text="w.invoice_number"></td>
                                <td class="font-mono" style="padding:8px 12px;text-align:right;" x-text="'$' + Number(w.amount).toFixed(2)"></td>
                                <td style="padding:8px 12px;max-width:250px;overflow:hidden;text-overflow:ellipsis;" x-text="w.reason"></td>
                                <td style="padding:8px 12px;">
                                    <template x-if="w.recovered == 1">
                                        <span class="badge badge-success badge-sm" x-text="'$' + Number(w.recovered_amount).toFixed(2) + ' on ' + w.recovered_date"></span>
                                    </template>
                                    <template x-if="w.recovered != 1">
                                        <span style="color:var(--text-tertiary);font-size:0.75rem;">—</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>
            <template x-if="writeoffs.length === 0 && !loading">
                <div style="padding:32px;text-align:center;color:var(--text-secondary);font-size:0.875rem;">No bad debt write-offs.</div>
            </template>
        </div>
    </template>

    <!-- Loading -->
    <template x-if="loading && customerId">
        <div class="card" style="padding:48px;text-align:center;">
            <div class="spinner" style="margin:0 auto 12px;"></div>
            <div style="color:var(--text-secondary);font-size:0.875rem;">Loading...</div>
        </div>
    </template>
</div>

<script>
function collectionsPage() {
    return {
        customerId: '',
        tab: 'notes',
        loading: false,
        notes: [],
        promises: [],
        dunning: [],
        writeoffs: [],
        can_create: <?= can('journal_entries', 'create') ? 'true' : 'false' ?>,
        can_edit: <?= can('journal_entries', 'edit') ? 'true' : 'false' ?>,
        dunningBusy: false,
        noteForm: { note_date: new Date().toISOString().slice(0,10), contact_method: 'phone', outcome: 'no_answer', note: '', follow_up_date: '', contact_person: '' },
        promiseForm: { promise_date: '', promised_amount: '', promised_by: '', notes: '' },
        dunningForm: { letter_type: 'reminder_30', sent_method: 'email' },

        init() {
            const urlCust = new URLSearchParams(window.location.search).get('customer_id');
            if (urlCust) { this.customerId = urlCust; this.loadAll(); }
        },

        async loadAll() {
            if (!this.customerId) return;
            this.loading = true;
            await Promise.all([this.loadNotes(), this.loadPromises(), this.loadDunning(), this.loadWriteoffs()]);
            this.loading = false;
        },

        async loadNotes() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/collection_notes/index.php?customer_id=' + this.customerId + '&per_page=100'));
                const j = await r.json();
                if (j.success) this.notes = j.data.data || j.data;
            } catch(e) { console.error(e); }
        },
        async loadPromises() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/promise_to_pay/index.php?customer_id=' + this.customerId + '&per_page=100'));
                const j = await r.json();
                if (j.success) this.promises = j.data.data || j.data;
            } catch(e) { console.error(e); }
        },
        async loadDunning() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/dunning_letters/index.php?customer_id=' + this.customerId + '&per_page=100'));
                const j = await r.json();
                if (j.success) this.dunning = j.data.items || j.data;
            } catch(e) { console.error(e); }
        },
        async loadWriteoffs() {
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/bad_debt_writeoffs/index.php?customer_id=' + this.customerId + '&per_page=100'));
                const j = await r.json();
                if (j.success) this.writeoffs = j.data.items || j.data;
            } catch(e) { console.error(e); }
        },

        async saveNote() {
            const body = new FormData();
            body.append('customer_id', this.customerId);
            body.append('note_date', this.noteForm.note_date);
            body.append('contact_method', this.noteForm.contact_method);
            body.append('note', this.noteForm.note);
            body.append('outcome', this.noteForm.outcome);
            if (this.noteForm.follow_up_date) body.append('follow_up_date', this.noteForm.follow_up_date);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/collection_notes/create.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.noteForm.note = ''; this.loadNotes(); FF_Toast.success('Collection note saved.'); }
                else FF_Toast.error(j.message || 'Failed to save.');
            } catch(e) { FF_Toast.error('Error saving note.'); }
        },

        async savePromise() {
            const body = new FormData();
            body.append('customer_id', this.customerId);
            body.append('promise_date', this.promiseForm.promise_date);
            body.append('promised_amount', this.promiseForm.promised_amount);
            if (this.promiseForm.promised_by) body.append('promised_by', this.promiseForm.promised_by);
            if (this.promiseForm.notes) body.append('notes', this.promiseForm.notes);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/promise_to_pay/create.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.promiseForm = {promise_date:'',promised_amount:'',promised_by:'',notes:''}; this.loadPromises(); FF_Toast.success('Promise recorded.'); }
                else FF_Toast.error(j.message || 'Failed.');
            } catch(e) { FF_Toast.error('Error.'); }
        },

        async updatePromise(id, status) {
            const body = new FormData();
            body.append('id', id);
            body.append('status', status);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/promise_to_pay/update.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.loadPromises(); FF_Toast.success('Promise marked as ' + status + '.'); }
                else FF_Toast.error(j.message || 'Failed.');
            } catch(e) { FF_Toast.error('Error.'); }
        },

        async sendDunning() {
            this.dunningBusy = true;
            const body = new FormData();
            body.append('customer_id', this.customerId);
            body.append('letter_type', this.dunningForm.letter_type);
            body.append('sent_method', this.dunningForm.sent_method);
            body.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
            try {
                const r = await fetch(FF_Api.url('/api/v1/accounting/ar/dunning_letter.php'), { method: 'POST', body });
                const j = await r.json();
                if (j.success) { this.loadAll(); FF_Toast.success('Dunning letter generated.'); }
                else FF_Toast.error(j.message || 'Failed to generate.');
            } catch(e) { FF_Toast.error('Error generating letter.'); }
            this.dunningBusy = false;
        },

        outcomeBadge(outcome) {
            return { payment_promised: 'badge-success', spoke_with_customer: 'badge-info', dispute: 'badge-danger', no_answer: 'badge-neutral', left_message: 'badge-warning' }[outcome] || 'badge-neutral';
        },
        promiseStatusBadge(s) {
            return { pending: 'badge-info', kept: 'badge-success', broken: 'badge-danger', cancelled: 'badge-neutral' }[s] || 'badge-neutral';
        },
        dunningBadge(t) {
            return { reminder_30: 'badge-warning', reminder_60: 'badge-warning', warning_90: 'badge-danger', final_notice: 'badge-danger' }[t] || 'badge-neutral';
        },
        dunningLabel(t) {
            return { reminder_30: '30-Day', reminder_60: '60-Day', warning_90: '90-Day Warning', final_notice: 'Final Notice' }[t] || t;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
