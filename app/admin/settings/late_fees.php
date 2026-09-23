<?php
declare(strict_types=1);

/**
 * app/admin/settings/late_fees.php
 *
 * I19 — Settings → Late Fees. The missing operator screen for late_fee_rules:
 * the "Apply late fees" cron (cron/late_fee_apply.php) ran daily but never
 * charged anything because there was no way to create a rule.
 *
 * Layout:
 *   1. Status note — what the cron does, and whether its Scheduled Jobs switch
 *      is on (links to Settings → Intelligence → Scheduled Jobs).
 *   2. Global rule card — type, value, grace days, cap, active. Applies to every
 *      customer without an override.
 *   3. Customer overrides table — add/edit/delete, customer chosen with an
 *      FF_RecordPicker; "Exempt this customer" stores an ACTIVE flat $0 rule
 *      (an INACTIVE override falls through to the global rule, so it would NOT
 *      exempt — the billing lookup only reads is_active = 1 rows).
 *
 * Percentages are typed as a percent (2 = 2%); the API stores the fraction the
 * billing engine expects (0.0200). The page never converts in JS — the API
 * hands back `fee_value_input` already in form units.
 *
 * Permission: settings.view to see, settings.edit to change.
 * Routed at /settings/late_fees (public/index.php → app/admin/settings/late_fees.php).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/record-picker.php,
 *          api/v1/late_fee_rules/*
 * @session I19-LATE-FEE-RULES
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

$canEdit = can('settings', 'edit');

$pageTitle = 'Late Fees';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('settings') ?>">Settings</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Late Fees</span>
</nav>

<div x-data="FF_LateFees()">

    <div class="page-header" style="margin-bottom:20px;">
        <div>
            <h1 class="page-header-title h4" style="margin:0;">Late Fees</h1>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">
                Rules for the late fee charged on overdue invoices: one global rule, plus optional per-customer overrides.
            </p>
        </div>
        <div class="page-header-actions">
            <?php if (!$canEdit): ?>
            <span class="badge badge-neutral">View Only</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 1. How it works + cron status ──────────────────────────────── -->
    <div class="alert" :class="cronEnabled ? 'alert-info' : 'alert-warning'" style="margin-bottom:20px;">
        <div style="font-size:0.8125rem;line-height:1.55;">
            <strong>How late fees are charged.</strong>
            Once a day the <em>Apply late fees</em> job looks at every <strong>overdue</strong> invoice that has not had a
            late fee yet. If the invoice's customer has an active rule (their own override, otherwise the global rule) and
            the invoice has been overdue for more than the rule's grace days, it creates a separate <strong>draft</strong>
            late-fee invoice and marks the original so it is <strong>never charged twice</strong> (one late fee per invoice).
            A rule change does not touch late fees already issued.
            <div style="margin-top:6px;">
                <span x-show="cronEnabled">The <em>Apply late fees</em> job is <strong>on</strong>.</span>
                <span x-show="!cronEnabled" x-cloak>The <em>Apply late fees</em> job is <strong>off</strong> — no late fees are charged until it is switched on, whatever the rules below say.</span>
                <a href="<?= base_url('settings') ?>?tab=intelligence">Manage it in Settings → Intelligence → Scheduled Jobs →</a>
            </div>
        </div>
    </div>

    <div x-show="loading" class="card">
        <div class="card-body" style="text-align:center;padding:48px;">
            <span class="text-secondary">Loading late fee rules…</span>
        </div>
    </div>

    <template x-if="!loading">
    <div>

    <!-- ── 2. Global rule ─────────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;align-items:center;gap:8px;">
            <h3 class="card-title" style="margin:0;">Global rule</h3>
            <span class="badge" :class="globalBadge().cls" x-text="globalBadge().text"></span>
        </div>
        <div class="card-body">
            <p class="text-secondary text-sm" style="margin:0 0 14px;">
                Applies to every customer that does not have an override below.
                Leave it off (or don't create it) to charge no late fees by default.
            </p>
            <p x-show="globalCount > 1" x-cloak class="text-sm" style="margin:0 0 14px;color:var(--color-warning-text);">
                <span x-text="globalCount"></span> global rules exist from earlier data; billing uses the newest active one (shown here).
                Delete the extras from the database or ask an administrator.
            </p>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px 20px;max-width:900px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="lf_g_type">Fee type</label>
                    <select id="lf_g_type" class="form-control" x-model="g.fee_type" :disabled="!canEdit">
                        <option value="percentage">Percentage of balance</option>
                        <option value="flat">Flat amount</option>
                    </select>
                    <div class="field-error" x-text="gErrors.fee_type || ''"></div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="lf_g_value" x-text="g.fee_type === 'percentage' ? 'Fee (%)' : 'Fee ($)'"></label>
                    <input id="lf_g_value" type="text" inputmode="decimal" class="form-control" x-model="g.fee_value"
                           :placeholder="g.fee_type === 'percentage' ? 'e.g. 2 for 2%' : 'e.g. 25.00'" :disabled="!canEdit">
                    <div class="field-error" x-text="gErrors.fee_value || ''"></div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="lf_g_grace">Grace days</label>
                    <input id="lf_g_grace" type="number" min="0" max="255" step="1" class="form-control" x-model="g.grace_days" :disabled="!canEdit">
                    <div class="field-error" x-text="gErrors.grace_days || ''"></div>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="lf_g_cap">Maximum fee ($)</label>
                    <input id="lf_g_cap" type="text" inputmode="decimal" class="form-control" x-model="g.max_fee_amount"
                           placeholder="No cap" :disabled="!canEdit">
                    <div class="field-error" x-text="gErrors.max_fee_amount || ''"></div>
                </div>
            </div>
            <p class="form-hint" style="margin:10px 0 0;">
                Grace days count from the due date: with 10, an invoice due on the 1st is charged from the 12th.
                A percentage is taken of the invoice's unpaid balance; the maximum (optional) caps it.
            </p>

            <div class="form-check" style="margin-top:14px;">
                <input type="checkbox" id="lf_g_active" x-model="g.is_active" :disabled="!canEdit">
                <label for="lf_g_active" style="margin-left:6px;font-size:0.875rem;font-weight:500;">Rule is active</label>
            </div>

            <?php if ($canEdit): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:16px;margin-top:16px;border-top:1px solid var(--border-default);">
                <button type="button" class="btn btn-primary btn-sm" @click="saveGlobal()" :disabled="savingGlobal">
                    <span x-text="savingGlobal ? 'Saving…' : (g.id ? 'Save Global Rule' : 'Create Global Rule')"></span>
                </button>
                <button type="button" class="btn btn-danger btn-sm" x-show="g.id" x-cloak @click="removeRule(g.id, 'the global late fee rule')">
                    Delete Global Rule
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 3. Customer overrides ──────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <h3 class="card-title" style="margin:0;">Customer overrides (<span x-text="overrides.length"></span>)</h3>
            <?php if ($canEdit): ?>
            <button type="button" class="btn btn-primary btn-sm" @click="openModal(null)">+ Add Override</button>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
            <p class="text-secondary text-sm" style="margin:0;padding:12px 16px;border-bottom:1px solid var(--border-default);">
                An active override replaces the global rule for that customer. An <strong>inactive</strong> override is ignored,
                so the customer falls back to the global rule — to charge a customer nothing, use <strong>Exempt</strong>.
            </p>
            <div x-show="overrides.length === 0" style="padding:28px;text-align:center;" class="text-secondary text-sm">
                No customer overrides — every customer uses the global rule.
            </div>
            <div class="table-responsive" x-show="overrides.length > 0">
                <table class="table" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Fee</th>
                            <th>Grace days</th>
                            <th>Maximum</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in overrides" :key="r.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('customers') ?>/' + r.customer_id" x-text="r.customer_name || ('Customer #' + r.customer_id)"></a>
                                    <span x-show="r.customer_deleted" class="badge badge-neutral" style="margin-left:6px;">Deleted</span>
                                </td>
                                <td x-text="r.feeText"></td>
                                <td x-text="r.is_exempt ? '—' : r.grace_days"></td>
                                <td x-text="r.capText"></td>
                                <td><span class="badge" :class="r.statusCls" x-text="r.statusText"></span></td>
                                <td style="white-space:nowrap;text-align:right;">
                                    <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-xs btn-secondary" @click="openModal(r)">Edit</button>
                                    <button type="button" class="btn btn-xs btn-danger" @click="removeRule(r.id, 'the late fee override for ' + (r.customer_name || 'this customer'))">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    </div>
    </template>

    <!-- ── Override add/edit modal ────────────────────────────────────── -->
    <?php if ($canEdit): ?>
    <div x-show="modal.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
        <div class="modal-backdrop" @click="closeModal()"></div>
        <div class="modal" @click.stop style="max-width:560px;">
            <div class="modal-header">
                <h3 class="modal-title" x-text="modal.id ? 'Edit Late Fee Override' : 'Add Late Fee Override'"></h3>
                <button type="button" class="modal-close-btn" aria-label="Close" @click="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Customer <span class="required">*</span></label>
                    <div x-show="modal.id" class="form-control" style="background:var(--bg-muted);" x-text="modal.customer_name"></div>
                    <?php /* WHY x-if: re-creates the picker on every open so a previous pick never leaks into the next add. */ ?>
                    <template x-if="modal.open && !modal.id">
                        <div>
                            <?php
                            $pickerName      = 'lf_customer_picker';
                            $pickerConfig    = [
                                'endpoint'    => '/api/v1/customers/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'perPage'     => 10,
                                'placeholder' => 'Search customers…',
                                'mapResult'   => "r => ({ id: r.id, label: r.company_name + (r.contact_name ? ' (' + r.contact_name + ')' : ''), sublabel: [r.city, r.province].filter(Boolean).join(', '), raw: r })",
                            ];
                            $pickerOnPicked  = "modal.customer_id = \$event.detail.id; modal.customer_name = \$event.detail.raw ? \$event.detail.raw.company_name : \$event.detail.label";
                            $pickerOnCleared = "modal.customer_id = null; modal.customer_name = ''";
                            $pickerError     = 'mErrors.customer_id';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                        </div>
                    </template>
                    <div class="field-error" x-text="mErrors.customer_id || ''"></div>
                    <p class="form-hint" x-show="modal.id" style="margin-top:4px;">To move an override to another customer, delete it and add a new one.</p>
                </div>

                <div class="form-check" style="margin-bottom:6px;">
                    <input type="checkbox" id="lf_m_exempt" x-model="modal.exempt">
                    <label for="lf_m_exempt" style="margin-left:6px;font-size:0.875rem;font-weight:500;">Exempt this customer (no late fee)</label>
                </div>
                <p class="form-hint" style="margin:0 0 14px 22px;">
                    Saves an active $0.00 rule, which overrides the global rule so this customer is never charged a late fee.
                </p>

                <div x-show="!modal.exempt">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="lf_m_type">Fee type</label>
                            <select id="lf_m_type" class="form-control" x-model="modal.fee_type">
                                <option value="percentage">Percentage of balance</option>
                                <option value="flat">Flat amount</option>
                            </select>
                            <div class="field-error" x-text="mErrors.fee_type || ''"></div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="lf_m_value" x-text="modal.fee_type === 'percentage' ? 'Fee (%)' : 'Fee ($)'"></label>
                            <input id="lf_m_value" type="text" inputmode="decimal" class="form-control" x-model="modal.fee_value"
                                   :placeholder="modal.fee_type === 'percentage' ? 'e.g. 1.5 for 1.5%' : 'e.g. 25.00'">
                            <div class="field-error" x-text="mErrors.fee_value || ''"></div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="lf_m_grace">Grace days</label>
                            <input id="lf_m_grace" type="number" min="0" max="255" step="1" class="form-control" x-model="modal.grace_days">
                            <div class="field-error" x-text="mErrors.grace_days || ''"></div>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" for="lf_m_cap">Maximum fee ($)</label>
                            <input id="lf_m_cap" type="text" inputmode="decimal" class="form-control" x-model="modal.max_fee_amount" placeholder="No cap">
                            <div class="field-error" x-text="mErrors.max_fee_amount || ''"></div>
                        </div>
                    </div>
                    <div class="form-check" style="margin-top:14px;">
                        <input type="checkbox" id="lf_m_active" x-model="modal.is_active">
                        <label for="lf_m_active" style="margin-left:6px;font-size:0.875rem;font-weight:500;">Override is active</label>
                    </div>
                    <p class="form-hint" x-show="!modal.is_active" style="margin:4px 0 0 22px;">
                        While inactive, this customer is charged under the global rule.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" @click="closeModal()">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" @click="saveModal()" :disabled="modal.saving">
                    <span x-text="modal.saving ? 'Saving…' : (modal.id ? 'Save Changes' : 'Add Override')"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
/**
 * FF_LateFees — Alpine component for Settings → Late Fees.
 *
 * All writes go through /api/v1/late_fee_rules/*. FF_Api.post RESOLVES on a
 * 422, so every save gates on `r.success` and surfaces `r.error.fields`
 * next to the inputs. Values are kept as strings end to end (the API does the
 * bcmath percent→fraction conversion) — no float math here.
 */
function FF_LateFees() {
    const API = '/api/v1/late_fee_rules/';
    const blankForm = () => ({
        id: null, fee_type: 'percentage', fee_value: '', grace_days: '0',
        max_fee_amount: '', is_active: true,
    });

    return {
        canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        loading: true,
        cronEnabled: true,
        globalCount: 0,
        g: blankForm(),
        gErrors: {},
        savingGlobal: false,
        overrides: [],
        modal: { open: false, saving: false, customer_id: null, customer_name: '', exempt: false, ...blankForm() },
        mErrors: {},

        /** Alpine auto-runs init(); never add x-init="init()" (double-init trap). */
        async init() {
            await this.load();
        },

        /** Load every rule + the cron switch state. */
        async load() {
            try {
                const r = await FF_Api.get(FF_Api.url(API + 'index.php'));
                if (!r.success) {
                    FF_Toast.error('Could not load', (r.error && r.error.message) || 'Late fee rules failed to load.');
                    return;
                }
                const d = r.data;
                this.cronEnabled = !!d.cron_enabled;
                this.globalCount = d.global_count || 0;
                this.g = d.global ? this.toForm(d.global) : blankForm();
                this.overrides = (d.overrides || []).map(x => this.decorate(x));
            } catch (e) {
                FF_Toast.error('Could not load', 'Network error');
            } finally {
                this.loading = false;
            }
        },

        /** API rule → editable form fields (strings, percent units). */
        toForm(rule) {
            return {
                id: rule.id,
                fee_type: rule.fee_type,
                fee_value: rule.fee_value_input,
                grace_days: String(rule.grace_days),
                max_fee_amount: rule.max_fee_amount === null ? '' : rule.max_fee_amount,
                is_active: !!rule.is_active,
            };
        },

        /**
         * Precompute display strings per row (no function calls inside x-for
         * bindings — keeps large override lists cheap to render).
         */
        decorate(r) {
            r.feeText = r.is_exempt ? 'Exempt ($0)'
                : (r.fee_type === 'percentage' ? r.fee_value_input + '% of balance' : '$' + r.fee_value_input + ' flat');
            r.capText = r.is_exempt || r.max_fee_amount === null ? '—' : '$' + r.max_fee_amount;
            if (!r.is_active) {
                r.statusText = 'Inactive — uses global'; r.statusCls = 'badge-neutral';
            } else if (r.is_exempt) {
                r.statusText = 'Exempt'; r.statusCls = 'badge-warning';
            } else {
                r.statusText = 'Active'; r.statusCls = 'badge-success';
            }
            return r;
        },

        /** Badge for the global card header. */
        globalBadge() {
            if (!this.g.id)        return { text: 'Not set up', cls: 'badge-neutral' };
            if (!this.g.is_active) return { text: 'Off', cls: 'badge-neutral' };
            return { text: 'Active', cls: 'badge-success' };
        },

        /** Form fields → API payload (strings; the API validates + converts). */
        payload(f) {
            return {
                fee_type: f.fee_type,
                fee_value: String(f.fee_value ?? '').trim(),
                grace_days: String(f.grace_days ?? '').trim(),
                max_fee_amount: String(f.max_fee_amount ?? '').trim(),
                is_active: !!f.is_active,
            };
        },

        /** Create or update the global rule. */
        async saveGlobal() {
            this.savingGlobal = true;
            this.gErrors = {};
            try {
                const body = this.payload(this.g);
                let r;
                if (this.g.id) {
                    body.id = this.g.id;
                    r = await FF_Api.post(FF_Api.url(API + 'update.php'), body);
                } else {
                    r = await FF_Api.post(FF_Api.url(API + 'create.php'), body);
                }
                // FF_Api.post resolves on 422 — success must be checked explicitly.
                if (r.success) {
                    FF_Toast.success('Saved', 'Global late fee rule saved.');
                    await this.load();
                } else {
                    this.gErrors = (r.error && r.error.fields) || {};
                    FF_Toast.error('Not saved', (r.error && r.error.message) || 'Could not save the rule.');
                }
            } catch (e) {
                FF_Toast.error('Not saved', 'Network error');
            } finally {
                this.savingGlobal = false;
            }
        },

        /** Open the override modal (null = add). */
        openModal(r) {
            this.mErrors = {};
            if (r) {
                Object.assign(this.modal, this.toForm(r), {
                    customer_id: r.customer_id,
                    customer_name: r.customer_name || ('Customer #' + r.customer_id),
                    exempt: !!(r.is_exempt && r.is_active),
                });
            } else {
                Object.assign(this.modal, blankForm(), { customer_id: null, customer_name: '', exempt: false });
            }
            this.modal.saving = false;
            this.modal.open = true;
        },

        closeModal() { this.modal.open = false; },

        /** Create or update a customer override. */
        async saveModal() {
            this.mErrors = {};
            if (!this.modal.id && !this.modal.customer_id) {
                this.mErrors = { customer_id: 'Pick a customer.' };
                return;
            }
            this.modal.saving = true;
            try {
                const body = this.payload(this.modal);
                body.exempt = !!this.modal.exempt;
                let r;
                if (this.modal.id) {
                    body.id = this.modal.id;
                    r = await FF_Api.post(FF_Api.url(API + 'update.php'), body);
                } else {
                    body.customer_id = this.modal.customer_id;
                    r = await FF_Api.post(FF_Api.url(API + 'create.php'), body);
                }
                if (r.success) {
                    FF_Toast.success('Saved', 'Late fee override saved.');
                    this.modal.open = false;
                    await this.load();
                } else {
                    this.mErrors = (r.error && r.error.fields) || {};
                    FF_Toast.error('Not saved', (r.error && r.error.message) || 'Could not save the override.');
                }
            } catch (e) {
                FF_Toast.error('Not saved', 'Network error');
            } finally {
                this.modal.saving = false;
            }
        },

        /** Delete a rule after confirmation. */
        async removeRule(id, what) {
            if (!(await FF_Confirm.ask('Delete ' + what + '? Late fees already issued are not affected.'))) return;
            try {
                const r = await FF_Api.post(FF_Api.url(API + 'delete.php'), { id: id });
                if (r.success) {
                    FF_Toast.success('Deleted', 'Late fee rule removed.');
                    await this.load();
                } else {
                    FF_Toast.error('Not deleted', (r.error && r.error.message) || 'Could not delete the rule.');
                }
            } catch (e) {
                FF_Toast.error('Not deleted', 'Network error');
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
