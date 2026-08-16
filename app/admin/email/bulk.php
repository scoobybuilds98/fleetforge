<?php
declare(strict_types=1);

/**
 * app/admin/email/bulk.php
 *
 * Bulk Email — send the same email to many customers at once.
 *
 * Three-step wizard:
 *   1. Select recipients (status, balance, province, custom)
 *   2. Compose (template + subject + body, preview per-customer)
 *   3. Review + Send (recipient list + send button)
 *
 * Permission: customers.create (manager+)
 *
 * @session  EMAIL-1
 * @depends  api/v1/email/bulk.php, api/v1/email/templates/*
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'create');

$pageTitle = 'Bulk Email';
require_once FF_ROOT . '/includes/header.php';

// Province list for filter
$provinces = ['BC','AB','SK','MB','ON','QC','NB','NS','PE','NL','YT','NT','NU'];
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Bulk Email</span>
</nav>

<div x-data="FF_BulkEmail()">

    <div class="page-header" style="margin-bottom:24px;">
        <div>
            <h1 class="page-header-title h4" style="margin:0;">Bulk Email</h1>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">
                Send the same email to a group of customers. A separate email is logged
                for each recipient.
            </p>
        </div>
    </div>

    <!-- Step indicator -->
    <div class="card" style="margin-bottom:16px;">
        <div class="card-body" style="display:flex;gap:24px;align-items:center;justify-content:space-between;">
            <div style="display:flex;gap:24px;align-items:center;">
                <div :class="step >= 1 ? 'text-primary' : 'text-secondary'" style="font-weight:600;font-size:13px;">1. Select Recipients</div>
                <div class="text-muted">→</div>
                <div :class="step >= 2 ? 'text-primary' : 'text-secondary'" style="font-weight:600;font-size:13px;">2. Compose Message</div>
                <div class="text-muted">→</div>
                <div :class="step >= 3 ? 'text-primary' : 'text-secondary'" style="font-weight:600;font-size:13px;">3. Review &amp; Send</div>
            </div>
            <div class="text-secondary text-sm" x-show="selectedCount > 0">
                <strong x-text="selectedCount"></strong> recipient<span x-show="selectedCount !== 1">s</span> selected
            </div>
        </div>
    </div>

    <!-- ── STEP 1: SELECT RECIPIENTS ─────────────────────────── -->
    <div class="card" x-show="step === 1">
        <div class="card-header"><h3 class="card-title">Step 1 — Select Recipients</h3></div>
        <div class="card-body">

            <!-- Filter row -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" x-model="filters.status" @change="applyFilters()">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="suspended">Suspended</option>
                        <option value="credit_hold">Credit Hold</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Province</label>
                    <select class="form-control" x-model="filters.province" @change="applyFilters()">
                        <option value="">All</option>
                        <?php foreach ($provinces as $p): ?>
                        <option value="<?= e($p) ?>"><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Min Outstanding Balance</label>
                    <input type="number" class="form-control" x-model="filters.minBalance" @change="applyFilters()" placeholder="0.00" min="0" step="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" x-model="filters.search" @input.debounce.300="applyFilters()" placeholder="Name or email...">
                </div>
            </div>

            <!-- Bulk actions -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <button class="btn btn-secondary btn-sm" @click="selectAllVisible()">Select All Visible</button>
                <button class="btn btn-secondary btn-sm" @click="clearSelection()">Clear Selection</button>
                <span class="text-secondary text-sm" x-text="customers.length + ' customers shown'"></span>
            </div>

            <!-- Customers list -->
            <div style="max-height:420px;overflow-y:auto;border:1px solid var(--border-color);border-radius:6px;">
                <div class="table-responsive">
<table class="table" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" @change="toggleAll($event.target.checked)"></th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Province</th>
                            <th class="text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in customers" :key="c.id">
                            <tr :class="{ 'is-selected': selected.includes(c.id) }">
                                <td><input type="checkbox" :checked="selected.includes(c.id)" @change="toggleOne(c.id)"></td>
                                <td>
                                    <div x-text="c.company_name"></div>
                                    <div class="text-secondary text-sm" x-text="c.contact_name || ''"></div>
                                </td>
                                <td class="text-sm" x-text="c.email || c.billing_email || '—'"></td>
                                <td><span class="badge" :class="'badge-' + statusClass(c.status)" x-text="c.status"></span></td>
                                <td class="text-sm" x-text="c.province || '—'"></td>
                                <td class="text-right text-sm font-mono" x-text="formatMoney(c.outstanding_balance)"></td>
                            </tr>
                        </template>
                        <tr x-show="customers.length === 0">
                            <td colspan="6" class="text-center text-secondary" style="padding:24px;">No customers match these filters.</td>
                        </tr>
                    </tbody>
                </table>
</div>
            </div>

        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;">
            <button class="btn btn-primary btn-sm"
                    @click="step = 2"
                    :disabled="selectedCount === 0">
                Next: Compose →
            </button>
        </div>
    </div>

    <!-- ── STEP 2: COMPOSE ───────────────────────────────────── -->
    <div class="card" x-show="step === 2">
        <div class="card-header"><h3 class="card-title">Step 2 — Compose Message</h3></div>
        <div class="card-body">

            <div class="form-group">
                <label class="form-label">Template (optional)</label>
                <select class="form-control" x-model="message.template_id" @change="loadTemplate()">
                    <option value="">— Custom message —</option>
                    <template x-for="t in templates" :key="t.id">
                        <option :value="t.id" x-text="t.name + ' (' + t.category + ')'"></option>
                    </template>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Subject <span class="required">*</span></label>
                <input type="text" class="form-control" x-model="message.subject"
                       placeholder="Email subject — supports {variables}">
            </div>

            <div class="form-group">
                <label class="form-label">Reply-to</label>
                <input type="email" class="form-control" x-model="message.reply_to" placeholder="Leave blank to use default">
            </div>

            <div class="form-group">
                <label class="form-label">Body (HTML supported)</label>
                <textarea class="form-control" x-model="message.body_html" rows="14"
                          style="font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 12.5px;"></textarea>
                <p class="form-hint">Use <code>{customer_name}</code>, <code>{company_name}</code>, etc. — replaced per recipient.</p>
            </div>

        </div>
        <div class="card-footer" style="display:flex;justify-content:space-between;gap:8px;padding:12px 16px;">
            <button class="btn btn-secondary btn-sm" @click="step = 1">← Back</button>
            <button class="btn btn-primary btn-sm"
                    @click="step = 3"
                    :disabled="!message.subject || !message.body_html">
                Next: Review →
            </button>
        </div>
    </div>

    <!-- ── STEP 3: REVIEW & SEND ─────────────────────────────── -->
    <div class="card" x-show="step === 3">
        <div class="card-header"><h3 class="card-title">Step 3 — Review &amp; Send</h3></div>
        <div class="card-body">

            <div class="alert" :class="selectedCount > 50 ? 'alert-warning' : 'alert-info'" style="margin-bottom:16px;padding:12px;border-radius:6px;border:1px solid var(--border-color);">
                <strong>About to send to <span x-text="selectedCount"></span> customer<span x-show="selectedCount !== 1">s</span>.</strong>
                <span x-show="selectedCount > 50">
                    Large batches may take a few minutes to complete due to rate limiting.
                </span>
            </div>

            <div style="display:grid;grid-template-columns: 1fr 1fr; gap:20px;">
                <div>
                    <h4 style="font-size:13px;margin:0 0 8px;">Recipients</h4>
                    <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border-color);border-radius:6px;padding:8px;">
                        <template x-for="cid in selected" :key="cid">
                            <div class="text-sm" style="padding:4px 0;border-bottom:1px solid var(--border-color);">
                                <span x-text="customerLabel(cid)"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div>
                    <h4 style="font-size:13px;margin:0 0 8px;">Message Preview</h4>
                    <div class="email-preview-frame">
                        <div class="email-preview-label">Subject</div>
                        <div class="email-preview-body" style="padding:8px 12px;font-weight:600;" x-text="message.subject"></div>
                        <div class="email-preview-label">Body</div>
                        <div class="email-preview-body" x-html="message.body_html"></div>
                    </div>
                </div>
            </div>

            <!-- Send results -->
            <div x-show="result" class="alert" :class="result && result.failed > 0 ? 'alert-warning' : 'alert-success'" style="margin-top:16px;padding:12px;border-radius:6px;border:1px solid var(--border-color);">
                <div><strong>Send complete.</strong></div>
                <div>Sent: <span x-text="result?.sent || 0"></span> &nbsp;·&nbsp; Failed: <span x-text="result?.failed || 0"></span></div>
            </div>

        </div>
        <div class="card-footer" style="display:flex;justify-content:space-between;gap:8px;padding:12px 16px;">
            <button class="btn btn-secondary btn-sm" @click="step = 2" :disabled="sending">← Back</button>
            <button class="btn btn-primary btn-sm" @click="sendBulk()" :disabled="sending">
                <span x-show="!sending">Send to <span x-text="selectedCount"></span> customer<span x-show="selectedCount !== 1">s</span></span>
                <span x-show="sending">Sending… <span x-text="progress"></span></span>
            </button>
        </div>
    </div>

</div>

<script>
function FF_BulkEmail() {
    return {
        step: 1,
        sending: false,
        progress: '',
        result: null,
        templates: [],
        allCustomers: [],
        customers: [],
        selected: [],
        filters: { status: '', province: '', minBalance: '', search: '' },
        message: { template_id: '', subject: '', body_html: '', reply_to: '' },

        get selectedCount() { return this.selected.length; },

        async init() {
            await Promise.all([this.loadCustomers(), this.loadTemplates()]);
        },

        async loadCustomers() {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/customers/?per_page=500'));
                if (r.success) {
                    this.allCustomers = r.data.items || [];
                    this.applyFilters();
                }
            } catch (e) { console.error(e); }
        },

        async loadTemplates() {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/'));
                if (r.success) this.templates = r.data.templates || [];
            } catch (e) { console.error(e); }
        },

        async loadTemplate() {
            if (!this.message.template_id) return;
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/preview.php?template_id=' + this.message.template_id));
                if (r.success) {
                    this.message.subject   = r.data.subject;
                    this.message.body_html = r.data.body_html;
                }
            } catch (e) { console.error(e); }
        },

        applyFilters() {
            const f = this.filters;
            this.customers = this.allCustomers.filter((c) => {
                if (f.status && c.status !== f.status) return false;
                if (f.province && c.province !== f.province) return false;
                if (f.minBalance && parseFloat(c.outstanding_balance || 0) < parseFloat(f.minBalance)) return false;
                if (f.search) {
                    const q = f.search.toLowerCase();
                    if (!((c.company_name || '').toLowerCase().includes(q) ||
                          (c.contact_name || '').toLowerCase().includes(q) ||
                          (c.email || '').toLowerCase().includes(q))) return false;
                }
                return true;
            });
        },

        toggleAll(checked) {
            if (checked) {
                const ids = this.customers.map((c) => c.id);
                this.selected = Array.from(new Set([...this.selected, ...ids]));
            } else {
                const ids = new Set(this.customers.map((c) => c.id));
                this.selected = this.selected.filter((id) => !ids.has(id));
            }
        },

        toggleOne(id) {
            if (this.selected.includes(id)) {
                this.selected = this.selected.filter((x) => x !== id);
            } else {
                this.selected.push(id);
            }
        },

        selectAllVisible() {
            this.toggleAll(true);
        },

        clearSelection() {
            this.selected = [];
        },

        statusClass(s) {
            return ({
                active: 'success', inactive: 'neutral', pending: 'info',
                suspended: 'danger', credit_hold: 'warning',
            })[s] || 'neutral';
        },

        formatMoney(v) {
            const n = parseFloat(v || 0);
            return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        customerLabel(cid) {
            const c = this.allCustomers.find((x) => x.id === cid);
            if (!c) return 'Customer #' + cid;
            return c.company_name + ' <' + (c.email || c.billing_email || 'no email') + '>';
        },

        async sendBulk() {
            if (this.selected.length === 0) return;
            if (!(await FF_Confirm.ask('Send this email to ' + this.selected.length + ' customer(s)? This cannot be undone.'))) return;
            this.sending = true;
            this.result = null;
            this.progress = '0/' + this.selected.length;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/email/bulk.php'), {
                    customer_ids: this.selected,
                    template_id: this.message.template_id || null,
                    subject: this.message.subject,
                    body_html: this.message.body_html,
                    reply_to: this.message.reply_to,
                });
                if (r.success) {
                    this.result = r.data;
                    FF_Toast.success('Bulk send complete', r.data.sent + ' sent, ' + r.data.failed + ' failed');
                } else {
                    FF_Toast.error('Send failed', (r.error && r.error.message) || 'Unknown error');
                }
            } catch (e) {
                console.error(e);
                FF_Toast.error('Send failed', 'Network error');
            } finally {
                this.sending = false;
                this.progress = '';
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
