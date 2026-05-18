<?php declare(strict_types=1);

/**
 * includes/partials/acc-documents-section.php
 *
 * Reusable Documents section for accounting entity detail pages.
 * Renders a card with: section header (with count), upload form
 * (title + notes + file picker), and a documents list table with
 * View and Delete actions.
 *
 * Required PHP variables in scope before include:
 *   $entityType — one of acc_documents.entity_type ENUM values
 *                 (journal_entry, bill, ap_payment, bank_transaction,
 *                  asset, tax_filing, reconciliation, other)
 *   $entityId   — int — the entity row id
 *
 * Backed by api/v1/accounting/documents/{index,upload,delete}.php.
 * file_path never leaves the server — signed_url is the only file
 * reference returned to the client (Trap 7).
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3
 * Session:  S-ACCT-FIX-DOCS
 */

if (!isset($entityType) || !isset($entityId)) {
    // Defensive — partial must not silently render empty section.
    throw new \RuntimeException('acc-documents-section partial requires $entityType and $entityId');
}
?>
<div class="card" style="padding:18px;margin-bottom:14px;"
     x-data="accDocuments(<?= htmlspecialchars(json_encode((string) $entityType), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $entityId ?>)"
     x-init="load()">

    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;">
        <div style="font-weight:600;font-size:0.95rem;">
            Documents
            <span style="font-weight:400;color:var(--text-secondary);font-size:0.85rem;margin-left:6px;"
                  x-text="'(' + docs.length + ')'">(0)</span>
        </div>
    </div>

    <!-- Upload form -->
    <div style="background:var(--bg-elev);border:1px solid var(--border-default);border-radius:6px;padding:14px;margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:2fr 3fr;gap:10px;margin-bottom:10px;">
            <div>
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Title <span style="color:var(--color-danger);">*</span></label>
                <input type="text" x-model="form.title" maxlength="255"
                       class="form-input"
                       style="width:100%;padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                       placeholder="Brief description">
            </div>
            <div>
                <label style="display:block;font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Notes</label>
                <input type="text" x-model="form.notes"
                       class="form-input"
                       style="width:100%;padding:7px 9px;border:1px solid var(--border-default);border-radius:4px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;"
                       placeholder="Optional notes">
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="file" x-ref="filepick"
                   accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.docx,.xlsx"
                   @change="onFileChange($event)"
                   style="flex:1;min-width:200px;font-size:0.8125rem;color:var(--text-secondary);">
            <button class="btn btn-primary btn-sm"
                    @click="upload()"
                    :disabled="!form.file || !form.title || uploading"
                    x-text="uploading ? 'Uploading…' : 'Upload Document'">Upload Document</button>
        </div>
        <div x-show="uploadError" x-cloak style="margin-top:8px;color:var(--color-danger);font-size:0.8125rem;" x-text="uploadError"></div>
        <div x-show="uploadSuccess" x-cloak style="margin-top:8px;color:var(--color-success);font-size:0.8125rem;" x-text="uploadSuccess"></div>
        <div style="margin-top:6px;font-size:0.7rem;color:var(--text-secondary);">
            Max 20 MB. Allowed: PDF, JPEG, PNG, TIFF, DOCX, XLSX.
        </div>
    </div>

    <!-- Loading state -->
    <template x-if="loading">
        <div style="text-align:center;padding:18px;color:var(--text-secondary);font-size:0.8125rem;">Loading documents…</div>
    </template>

    <!-- Empty state -->
    <template x-if="!loading && docs.length === 0">
        <div style="text-align:center;padding:18px;color:var(--text-secondary);font-size:0.8125rem;">
            No documents attached. Upload the first one above.
        </div>
    </template>

    <!-- Documents list -->
    <template x-if="!loading && docs.length > 0">
        <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Title</th>
                        <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">File</th>
                        <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Size</th>
                        <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Uploaded By</th>
                        <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                        <th style="padding:9px 10px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="d in docs" :key="d.id">
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td style="padding:8px 10px;">
                                <div style="font-weight:500;" x-text="d.title"></div>
                                <div x-show="d.notes" x-cloak style="color:var(--text-secondary);font-size:0.75rem;margin-top:1px;" x-text="d.notes"></div>
                            </td>
                            <td style="padding:8px 10px;">
                                <span style="margin-right:5px;" x-text="fileIcon(d.mime_type)"></span>
                                <span style="font-family:var(--font-mono);font-size:0.78rem;color:var(--text-secondary);" x-text="d.file_name"></span>
                            </td>
                            <td style="padding:8px 10px;text-align:right;font-family:var(--font-mono);font-size:0.78rem;" x-text="(d.file_size_kb || 0) + ' KB'"></td>
                            <td style="padding:8px 10px;" x-text="d.uploaded_by_name || '—'"></td>
                            <td style="padding:8px 10px;font-family:var(--font-mono);font-size:0.78rem;" x-text="(d.uploaded_at || '').replace('T',' ').substring(0,16)"></td>
                            <td style="padding:8px 10px;text-align:center;white-space:nowrap;">
                                <a :href="d.signed_url" target="_blank" rel="noopener" class="btn btn-ghost btn-xs" style="margin-right:4px;">View</a>
                                <button class="btn btn-danger btn-xs" @click="del(d.id, d.title)">Delete</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>
</div>

<?php
// Inline factory once per page. The flag guards against multiple includes
// (e.g. if a page renders documents for two entities side by side).
if (!defined('FF_ACC_DOCUMENTS_FACTORY_RENDERED')):
    define('FF_ACC_DOCUMENTS_FACTORY_RENDERED', true);
?>
<script>
// accDocuments — Alpine factory for the Documents section partial.
// Backed by api/v1/accounting/documents/{index,upload,delete}.php.
window.accDocuments = function (entityType, entityId) {
    return {
        entityType: entityType,
        entityId: entityId,
        docs: [],
        loading: true,
        uploading: false,
        uploadError: '',
        uploadSuccess: '',
        form: { title: '', notes: '', file: null },

        apiBase: '<?= e(base_url('api/v1/accounting/documents')) ?>',

        async load() {
            this.loading = true;
            try {
                const url = new URL(this.apiBase + '/index.php', window.location.origin);
                url.searchParams.set('entity_type', this.entityType);
                url.searchParams.set('entity_id', String(this.entityId));
                const r = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.docs = (j && j.success && Array.isArray(j.data)) ? j.data : [];
            } catch (e) {
                this.docs = [];
            }
            this.loading = false;
        },

        onFileChange(ev) {
            this.form.file = ev.target.files[0] || null;
            this.uploadError = '';
            this.uploadSuccess = '';
        },

        _csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        async upload() {
            if (!this.form.file || !this.form.title) return;
            this.uploading = true;
            this.uploadError = '';
            this.uploadSuccess = '';
            try {
                const fd = new FormData();
                fd.append('entity_type', this.entityType);
                fd.append('entity_id', String(this.entityId));
                fd.append('title', this.form.title);
                if (this.form.notes) fd.append('notes', this.form.notes);
                fd.append('file', this.form.file);
                const r = await fetch(this.apiBase + '/upload.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-CSRF-Token': this._csrfToken() }
                });
                const j = await r.json();
                if (j && j.success) {
                    this.docs.unshift(j.data);
                    this.uploadSuccess = 'Uploaded.';
                    this.form = { title: '', notes: '', file: null };
                    if (this.$refs.filepick) this.$refs.filepick.value = '';
                    setTimeout(() => { this.uploadSuccess = ''; }, 3000);
                } else {
                    this.uploadError = (j && j.error && j.error.message) || 'Upload failed.';
                }
            } catch (e) {
                this.uploadError = 'Upload failed: ' + e.message;
            }
            this.uploading = false;
        },

        async del(id, title) {
            if (!confirm('Delete this document?\n\n' + title)) return;
            try {
                const r = await fetch(this.apiBase + '/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this._csrfToken()
                    },
                    body: JSON.stringify({ id: id })
                });
                const j = await r.json();
                if (j && j.success) {
                    this.docs = this.docs.filter(d => d.id !== id);
                } else {
                    alert('Delete failed: ' + ((j && j.error && j.error.message) || 'Unknown error'));
                }
            } catch (e) {
                alert('Delete failed: ' + e.message);
            }
        },

        fileIcon(mime) {
            if (!mime) return '📄';
            if (mime.startsWith('image/')) return '🖼️';
            if (mime === 'application/pdf') return '📕';
            if (mime.indexOf('spreadsheet') !== -1) return '📊';
            if (mime.indexOf('word') !== -1) return '📝';
            return '📄';
        }
    };
};
</script>
<?php endif; ?>
