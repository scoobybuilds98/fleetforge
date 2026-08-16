<?php
declare(strict_types=1);

/**
 * app/admin/settings/email_templates.php
 *
 * Email Templates management page.
 *
 * Lists all 10 seeded templates (and any custom additions) grouped
 * by category. Each row supports inline edit, preview, duplicate,
 * enable/disable, and delete (super_admin only).
 *
 * Permission: settings.view to see, settings.edit to modify.
 *
 * @session  EMAIL-1
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, api/v1/email/templates/*
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

$canEdit   = can('settings', 'edit');
$canDelete = can('settings', 'delete');

$pageTitle = 'Email Templates';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('settings') ?>">Settings</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Email Templates</span>
</nav>

<div x-data="FF_EmailTemplates()">

    <div class="page-header" style="margin-bottom:24px;">
        <div>
            <h1 class="page-header-title h4" style="margin:0;">Email Templates</h1>
            <p class="text-secondary text-sm" style="margin:4px 0 0;">
                Manage the email templates used by the Customer Email System and bulk-send features.
            </p>
        </div>
        <div class="page-header-actions">
            <?php if ($canEdit): ?>
            <button class="btn btn-primary btn-sm" @click="openEditor(null)">
                + New Template
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div x-show="loading" class="card">
        <div class="card-body" style="text-align:center; padding:48px;">
            <span class="text-secondary">Loading templates…</span>
        </div>
    </div>

    <template x-for="(group, category) in grouped" :key="category">
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <h3 class="card-title" x-text="categoryLabel(category) + ' (' + group.length + ')'"></h3>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
<table class="table" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Template Name</th>
                            <th>Slug</th>
                            <th>Subject Preview</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="t in group" :key="t.id">
                            <tr>
                                <td><strong x-text="t.name"></strong></td>
                                <td><code class="text-sm" x-text="t.slug"></code></td>
                                <td class="text-sm text-secondary" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="t.subject"></td>
                                <td>
                                    <span class="badge" :class="t.is_active ? 'badge-success' : 'badge-neutral'"
                                          x-text="t.is_active ? 'Active' : 'Inactive'"></span>
                                </td>
                                <td style="white-space:nowrap;text-align:right;">
                                    <button class="btn btn-xs btn-ghost" @click="previewTemplate(t)">Preview</button>
                                    <?php if ($canEdit): ?>
                                    <button class="btn btn-xs btn-secondary" @click="openEditor(t)">Edit</button>
                                    <button class="btn btn-xs btn-secondary" @click="duplicateTemplate(t)">Duplicate</button>
                                    <button class="btn btn-xs btn-secondary" @click="toggleActive(t)"
                                            x-text="t.is_active ? 'Disable' : 'Enable'"></button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <button class="btn btn-xs btn-outline-danger" @click="deleteTemplate(t)">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
</div>
            </div>
        </div>
    </template>

    <!-- ── EDITOR MODAL ───────────────────────────────────────── -->
    <div x-show="editor.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
        <div class="modal-backdrop" @click="closeEditor()"></div>
        <div class="modal modal-full" @click.stop style="max-height: calc(100vh - 32px);">
            <div class="modal-header">
                <h3 class="modal-title" x-text="editor.id ? 'Edit Template' : 'New Template'"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="closeEditor()">×</button>
            </div>
            <div class="modal-body">
                <div style="display:grid;grid-template-columns: 2fr 1fr;gap:20px;">
                    <!-- Left: form -->
                    <div>
                        <div class="form-group">
                            <label class="form-label">Name <span class="required">*</span></label>
                            <input type="text" class="form-control" x-model="editor.name" placeholder="e.g. Invoice Reminder">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control" x-model="editor.slug"
                                   :placeholder="editor.id ? '' : 'Auto-generated from name if blank'">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category <span class="required">*</span></label>
                            <select class="form-control" x-model="editor.category">
                                <option value="invoice">Invoice</option>
                                <option value="payment">Payment</option>
                                <option value="lease">Lease</option>
                                <option value="reminder">Reminder</option>
                                <option value="compliance">Compliance</option>
                                <option value="general">General</option>
                                <option value="collection">Collection</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Subject <span class="required">*</span></label>
                            <input type="text" class="form-control" x-model="editor.subject"
                                   placeholder="e.g. Invoice {invoice_number} from {company_name}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Body (HTML) <span class="required">*</span></label>
                            <textarea class="form-control" x-model="editor.body_html" rows="14"
                                      style="font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 12.5px;"></textarea>
                            <p class="form-hint">Basic HTML supported. Click variables on the right to insert.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" x-model="editor.is_active">
                                Template is active
                            </label>
                        </div>
                    </div>
                    <!-- Right: variable reference + test send -->
                    <div>
                        <div class="card">
                            <div class="card-header"><span class="card-title" style="font-size:13px;">Available Variables</span></div>
                            <div class="card-body" style="padding:10px;">
                                <div class="email-variables-list">
                                    <template x-for="v in availableVariables" :key="v">
                                        <button type="button" class="email-variable-chip" @click="insertVariable(v)" x-text="'{' + v + '}'"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="card" style="margin-top:12px;">
                            <div class="card-header"><span class="card-title" style="font-size:13px;">Test Send</span></div>
                            <div class="card-body" style="padding:10px;">
                                <p class="text-secondary text-sm" style="margin:0 0 8px;">
                                    Send a test email to your own address using sample data.
                                </p>
                                <button type="button"
                                        class="btn btn-secondary btn-sm"
                                        @click="testSend()"
                                        :disabled="editor.testing"
                                        x-text="editor.testing ? 'Sending…' : 'Send Test'"></button>
                                <p class="text-secondary text-sm" style="margin:8px 0 0;" x-show="editor.testResult" x-text="editor.testResult"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="closeEditor()">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="saveTemplate()" :disabled="editor.saving">
                    <span x-text="editor.saving ? 'Saving…' : (editor.id ? 'Save Changes' : 'Create Template')"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── PREVIEW MODAL ──────────────────────────────────────── -->
    <div x-show="preview.open" x-cloak class="modal-overlay" style="z-index:var(--z-modal);">
        <div class="modal-backdrop" @click="preview.open = false"></div>
        <div class="modal modal-lg" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Preview: <span x-text="preview.name"></span></h3>
                <button class="modal-close-btn" aria-label="Close" @click="preview.open = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <div class="form-control" style="background:var(--bg-muted);" x-text="preview.subject"></div>
                </div>
                <div class="email-preview-frame">
                    <div class="email-preview-label">Body</div>
                    <div class="email-preview-body" x-html="preview.body_html"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="preview.open = false">Close</button>
            </div>
        </div>
    </div>

</div>

<script>
function FF_EmailTemplates() {
    return {
        loading: true,
        templates: [],
        grouped: {},

        editor: {
            open: false,
            saving: false,
            testing: false,
            testResult: '',
            id: null,
            updated_at: '',
            name: '',
            slug: '',
            subject: '',
            body_html: '',
            body_text: '',
            category: 'general',
            is_active: true,
        },
        availableVariables: [
            'customer_name','company_name','contact_name',
            'invoice_number','invoice_date','due_date','amount','amount_due','days_overdue',
            'contract_number','unit_number','lease_start','lease_end',
            'payment_amount','payment_date',
            'document_type','expiry_date',
            'subject','sender_name',
            'company_phone','company_email','company_address',
            'month','year',
        ],

        preview: { open: false, name: '', subject: '', body_html: '' },

        async init() {
            await this.loadTemplates();
        },

        categoryLabel(c) {
            const map = {
                invoice: 'Invoice', payment: 'Payment', lease: 'Lease',
                reminder: 'Reminders', compliance: 'Compliance',
                general: 'General', collection: 'Collections',
            };
            return map[c] || c;
        },

        async loadTemplates() {
            this.loading = true;
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/'));
                if (r.success) {
                    this.templates = r.data.templates;
                    this.grouped   = r.data.grouped;
                }
            } catch (e) { console.error(e); }
            finally { this.loading = false; }
        },

        openEditor(t) {
            this.editor.open       = true;
            this.editor.saving     = false;
            this.editor.testResult = '';
            if (t) {
                this.editor.id         = t.id;
                this.editor.updated_at = t.updated_at;
                this.editor.name       = t.name;
                this.editor.slug       = t.slug;
                this.editor.category   = t.category;
                this.editor.subject    = t.subject;
                this.editor.is_active  = t.is_active === 1 || t.is_active === true;
                // Fetch the full body
                this.fetchFullTemplate(t.id);
            } else {
                this.editor.id         = null;
                this.editor.updated_at = '';
                this.editor.name       = '';
                this.editor.slug       = '';
                this.editor.category   = 'general';
                this.editor.subject    = '';
                this.editor.body_html  = '';
                this.editor.body_text  = '';
                this.editor.is_active  = true;
            }
        },

        async fetchFullTemplate(id) {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/show.php?id=' + id));
                if (r.success) {
                    this.editor.body_html  = r.data.body_html || '';
                    this.editor.body_text  = r.data.body_text || '';
                    this.editor.updated_at = r.data.updated_at || '';
                }
            } catch (e) { console.error(e); }
        },

        closeEditor() { this.editor.open = false; },

        insertVariable(v) {
            this.editor.body_html += '{' + v + '}';
        },

        async saveTemplate() {
            if (!this.editor.name || !this.editor.subject || !this.editor.body_html) {
                FF_Toast.error('Missing fields', 'Name, subject, and body are required.');
                return;
            }
            this.editor.saving = true;
            try {
                const url = this.editor.id
                    ? FF_Api.url('/api/v1/email/templates/update.php')
                    : FF_Api.url('/api/v1/email/templates/create.php');
                const payload = {
                    name:       this.editor.name,
                    slug:       this.editor.slug,
                    subject:    this.editor.subject,
                    body_html:  this.editor.body_html,
                    body_text:  this.editor.body_text,
                    category:   this.editor.category,
                    is_active:  this.editor.is_active,
                    variables:  this.availableVariables,
                };
                if (this.editor.id) {
                    payload.id         = this.editor.id;
                    payload.updated_at = this.editor.updated_at;
                }
                const r = await FF_Api.post(url, payload);
                if (r.success) {
                    FF_Toast.success('Saved', 'Template saved successfully.');
                    this.closeEditor();
                    await this.loadTemplates();
                } else {
                    FF_Toast.error('Save failed', (r.error && r.error.message) || 'Unknown error');
                }
            } catch (e) {
                FF_Toast.error('Save failed', 'Network error');
            } finally {
                this.editor.saving = false;
            }
        },

        async testSend() {
            this.editor.testing = true;
            this.editor.testResult = '';
            // Use the global compose modal pre-loaded with this body
            try {
                window.openEmailCompose({
                    toEmail: <?= json_encode((string)(current_user()['email'] ?? '')) ?>,
                    toName:  <?= json_encode((string)(current_user()['name'] ?? 'Test')) ?>,
                    subject: this.editor.subject,
                    body:    this.editor.body_html,
                });
                this.editor.testResult = 'Compose modal opened with template — click Send to deliver.';
            } catch (e) {
                this.editor.testResult = 'Could not open compose modal: ' + (e.message || e);
            } finally {
                this.editor.testing = false;
            }
        },

        async previewTemplate(t) {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/preview.php?template_id=' + t.id));
                if (r.success) {
                    this.preview.name      = t.name;
                    this.preview.subject   = r.data.subject;
                    this.preview.body_html = r.data.body_html;
                    this.preview.open      = true;
                }
            } catch (e) { console.error(e); }
        },

        async duplicateTemplate(t) {
            try {
                const full = await FF_Api.get(FF_Api.url('/api/v1/email/templates/show.php?id=' + t.id));
                if (!full.success) return;
                const r = await FF_Api.post(FF_Api.url('/api/v1/email/templates/create.php'), {
                    name:      'Copy of ' + t.name,
                    subject:   full.data.subject,
                    body_html: full.data.body_html,
                    body_text: full.data.body_text,
                    category:  t.category,
                    is_active: false,
                });
                if (r.success) {
                    FF_Toast.success('Duplicated', 'Template copied.');
                    await this.loadTemplates();
                }
            } catch (e) { console.error(e); }
        },

        async toggleActive(t) {
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/email/templates/update.php'), {
                    id: t.id,
                    updated_at: t.updated_at,
                    is_active: !t.is_active,
                });
                if (r.success) {
                    FF_Toast.success('Updated', t.name + ' is now ' + (!t.is_active ? 'active' : 'inactive'));
                    await this.loadTemplates();
                } else if (r.error) {
                    FF_Toast.error('Update failed', r.error.message);
                }
            } catch (e) { console.error(e); }
        },

        async deleteTemplate(t) {
            if (!(await FF_Confirm.ask('Delete template "' + t.name + '"? This cannot be undone.'))) return;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/email/templates/delete.php'), { id: t.id });
                if (r.success) {
                    FF_Toast.success('Deleted', 'Template removed.');
                    await this.loadTemplates();
                }
            } catch (e) { console.error(e); }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
