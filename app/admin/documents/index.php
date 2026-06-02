<?php
declare(strict_types=1);

/**
 * FleetForge — Global Documents List
 *
 * @file        app/admin/documents/index.php
 * @description Global view of all uploaded documents across all entity types
 *              (customers, equipment units, leases, inspections, damage claims).
 *              Supports filtering by entity_type and text search. Each row links
 *              back to its parent entity show page. Documents can be viewed (via
 *              signed URL) or removed directly from this page.
 *
 *              Upload flow: the "+ Upload" button opens a modal where the user
 *              selects entity_type, enters the entity ID, picks a document_type,
 *              and attaches a file. On success the row is prepended to the table.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php,
 *              api/v1/documents/index.php (global mode — no entity_id),
 *              api/v1/documents/upload.php,
 *              api/v1/documents/delete.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §Documents
 * @session     S022 (Documents Module)
 */

// dirname(__DIR__, 3): app/admin/documents/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
// WHY: documents nav entry has module=null — visible to all authenticated staff
// No per-module permission required for listing; delete is gated in the API.

$pageTitle      = 'Documents';
$helpModuleSlug = 'documents';
require_once FF_ROOT . '/includes/header.php';
?>

<div x-data="FF_Documents()" x-init="init()" x-cloak>

    <!-- ── Page header ──────────────────────────────────────────── -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Documents</h1>
            <p class="page-subtitle">All uploaded files across customers, equipment, and leases.</p>
        </div>
        <div class="page-header-actions">
            <?= help_button('documents') ?>
            <?php if (can('equipment', 'edit') || can('customers', 'edit') || can('leases', 'edit')): ?>
            <button class="btn btn-primary" @click="openUploadModal()">+ Upload</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filter bar ───────────────────────────────────────────── -->
    <div class="filter-bar">
        <div class="filter-bar__left">
            <select class="form-select form-select-sm" x-model="filters.entity_type"
                    @change="applyFilters()">
                <option value="">All Types</option>
                <option value="customer">Customers</option>
                <option value="equipment_unit">Equipment</option>
                <option value="lease">Leases</option>
                <option value="inspection">Inspections</option>
                <option value="damage_claim">Damage Claims</option>
            </select>
            <input type="search" class="form-control form-control-sm"
                   placeholder="Search title or filename…"
                   x-model="filters.q"
                   @input.debounce.400ms="applyFilters()">
        </div>
        <div class="filter-bar__right">
            <span class="text-secondary text-sm"
                  x-show="!loading" x-text="total + ' document' + (total !== 1 ? 's' : '')"></span>
        </div>
    </div>

    <!-- ── Table ────────────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <div x-show="loading && items.length === 0" class="card-body" style="text-align:center;padding:48px;">
            <span class="text-secondary">Loading documents…</span>
        </div>

        <!-- Empty state -->
        <div x-show="!loading && items.length === 0" class="card-body">
            <div class="empty-state">
                <svg class="empty-state-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                </svg>
                <p class="empty-state-title">No documents</p>
                <p class="empty-state-text">Upload documents for customers and equipment.</p>
            </div>
        </div>

        <!-- Data table -->
        <div x-show="items.length > 0" class="tab-table-container">
            <div class="table-responsive">
<table class="table">
                <thead>
                    <tr>
                        <th>
                            <a href="#" @click.prevent="toggleSort('document_type')" class="sort-link">
                                Type
                                <span x-show="filters.sort === 'document_type'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </a>
                        </th>
                        <th>
                            <a href="#" @click.prevent="toggleSort('title')" class="sort-link">
                                Title
                                <span x-show="filters.sort === 'title'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </a>
                        </th>
                        <th>Entity</th>
                        <th>
                            <a href="#" @click.prevent="toggleSort('file_size_kb')" class="sort-link">
                                Size
                                <span x-show="filters.sort === 'file_size_kb'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </a>
                        </th>
                        <th>
                            <a href="#" @click.prevent="toggleSort('expiration_date')" class="sort-link">
                                Expires
                                <span x-show="filters.sort === 'expiration_date'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </a>
                        </th>
                        <th>
                            <a href="#" @click.prevent="toggleSort('uploaded_at')" class="sort-link">
                                Uploaded
                                <span x-show="filters.sort === 'uploaded_at'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </a>
                        </th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="doc in items" :key="doc.id">
                        <tr>
                            <td>
                                <span :class="docTypeBadge(doc.document_type, doc.entity_type)"
                                      x-text="docTypeLabel(doc.document_type)"></span>
                            </td>
                            <td x-text="doc.title || doc.file_name"></td>
                            <td>
                                <div>
                                    <span :class="entityTypeBadge(doc.entity_type)"
                                          x-text="entityTypeLabel(doc.entity_type)"></span>
                                </div>
                                <a :href="entityUrl(doc)" class="text-sm text-link"
                                   x-text="doc.entity_label"></a>
                            </td>
                            <td class="font-mono text-sm"
                                x-text="doc.file_size_kb ? doc.file_size_kb + ' KB' : '—'"></td>
                            <td :class="expiryClass(doc.expiration_date)">
                                <span x-text="doc.expiration_date ? formatDate(doc.expiration_date) : '—'"></span>
                            </td>
                            <td class="text-sm" x-text="formatDate(doc.uploaded_at)"></td>
                            <td class="text-sm text-secondary" x-text="doc.uploaded_by_name || '—'"></td>
                            <td style="white-space:nowrap;">
                                <a :href="doc.url" target="_blank" rel="noopener"
                                   class="btn btn-xs btn-ghost">View</a>
                                <button class="btn btn-xs btn-outline-danger"
                                        @click="confirmDelete(doc)"
                                        style="margin-left:4px;">Remove</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>

        <!-- Pagination footer -->
        <div x-show="items.length > 0" class="tab-table-footer">
            <span x-text="'Showing ' + items.length + ' of ' + total"></span>
            <button x-show="items.length < total && !loading"
                    class="btn btn-sm btn-ghost"
                    @click="loadMore()" :disabled="loading">Load more</button>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         UPLOAD MODAL
         ════════════════════════════════════════════════════════════ -->
    <div x-show="uploadModal.open" x-cloak
         style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);"
         @click.self="uploadModal.open = false">
        <div class="modal" style="width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;" @click.stop>
            <div class="modal-header">
                <h3 class="modal-title">Upload Document</h3>
                <button class="modal-close-btn" aria-label="Close" @click="uploadModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <div x-show="uploadModal.error" class="alert alert-danger"
                     x-text="uploadModal.error" style="margin-bottom:12px;"></div>

                <div class="form-grid">
                    <!-- Entity Type -->
                    <div class="form-group">
                        <label class="form-label">Entity Type <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="uploadModal.entity_type"
                                @change="uploadModal.document_type = ''">
                            <option value="">— Select —</option>
                            <option value="customer">Customer</option>
                            <option value="equipment_unit">Equipment Unit</option>
                            <option value="lease">Lease</option>
                            <option value="inspection">Inspection</option>
                            <option value="damage_claim">Damage Claim</option>
                        </select>
                    </div>
                    <!-- Entity ID -->
                    <div class="form-group">
                        <label class="form-label">Entity ID <span class="text-danger">*</span></label>
                        <input type="number" class="form-control font-mono" x-model="uploadModal.entity_id"
                               min="1" step="1" placeholder="e.g. 42">
                        <div class="form-hint">The numeric ID of the record to attach to.</div>
                    </div>
                    <!-- Document Type -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Document Type <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="uploadModal.document_type"
                                :disabled="!uploadModal.entity_type">
                            <option value="">— Select entity type first —</option>
                            <!-- equipment_unit -->
                            <template x-if="uploadModal.entity_type === 'equipment_unit'">
                                <template x-for="opt in [['cvi','CVI Certificate'],['registration','Registration'],['insurance','Insurance'],['other','Other']]" :key="opt[0]">
                                    <option :value="opt[0]" x-text="opt[1]"></option>
                                </template>
                            </template>
                            <!-- lease -->
                            <template x-if="uploadModal.entity_type === 'lease'">
                                <template x-for="opt in [['contract','Lease Contract'],['inspection_in','Pre-Lease Inspection'],['inspection_out','Post-Lease Inspection'],['amendment','Amendment'],['other','Other']]" :key="opt[0]">
                                    <option :value="opt[0]" x-text="opt[1]"></option>
                                </template>
                            </template>
                            <!-- customer -->
                            <template x-if="uploadModal.entity_type === 'customer'">
                                <template x-for="opt in [['tax_exemption','Tax Exemption'],['credit_agreement','Credit Agreement'],['other','Other']]" :key="opt[0]">
                                    <option :value="opt[0]" x-text="opt[1]"></option>
                                </template>
                            </template>
                            <!-- inspection -->
                            <template x-if="uploadModal.entity_type === 'inspection'">
                                <template x-for="opt in [['report','Inspection Report'],['other','Other']]" :key="opt[0]">
                                    <option :value="opt[0]" x-text="opt[1]"></option>
                                </template>
                            </template>
                            <!-- damage_claim -->
                            <template x-if="uploadModal.entity_type === 'damage_claim'">
                                <template x-for="opt in [['estimate','Repair Estimate'],['repair_invoice','Repair Invoice'],['other','Other']]" :key="opt[0]">
                                    <option :value="opt[0]" x-text="opt[1]"></option>
                                </template>
                            </template>
                        </select>
                    </div>
                    <!-- Title -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" x-model="uploadModal.title"
                               maxlength="255" placeholder="Optional — defaults to document type name">
                    </div>
                    <!-- Expiration Date -->
                    <div class="form-group">
                        <label class="form-label">Expiration Date</label>
                        <input type="date" class="form-control" x-model="uploadModal.expiration_date">
                    </div>
                    <!-- File -->
                    <div class="form-group">
                        <label class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png"
                               @change="uploadModal.file = $event.target.files[0]">
                        <div class="form-hint">PDF, JPEG, or PNG — max 20 MB.</div>
                    </div>
                    <!-- Notes -->
                    <div class="form-group form-group--full">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" x-model="uploadModal.notes"
                               maxlength="500" placeholder="Optional">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        @click="uploadModal.open = false">Cancel</button>
                <button type="button" class="btn btn-primary"
                        :disabled="uploadModal.saving || !uploadModal.entity_type || !uploadModal.entity_id"
                        @click="submitUpload()"
                        x-text="uploadModal.saving ? 'Uploading…' : 'Upload'">
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_Documents() {
    return {
        items:   [],
        total:   0,
        page:    1,
        loading: false,
        filters: {
            entity_type: '',
            q:    '',
            sort: 'uploaded_at',
            dir:  'DESC',
        },
        uploadModal: {
            open:            false,
            saving:          false,
            error:           null,
            entity_type:     '',
            entity_id:       '',
            document_type:   '',
            title:           '',
            expiration_date: '',
            notes:           '',
            file:            null,
        },

        init() {
            this.load();
        },

        // ── Data loading ───────────────────────────────────────────
        async load(append = false) {
            this.loading = true;
            try {
                const p = new URLSearchParams({ page: this.page, per_page: 25,
                                                sort: this.filters.sort,
                                                dir:  this.filters.dir });
                if (this.filters.entity_type) p.set('entity_type', this.filters.entity_type);
                if (this.filters.q)           p.set('q',           this.filters.q);

                const json = await (await fetch(
                    '<?= base_url('api/v1/documents') ?>?' + p
                )).json();

                if (json.success) {
                    const rows  = json.data.items || [];
                    this.items  = append ? [...this.items, ...rows] : rows;
                    this.total  = json.data.pagination?.total ?? rows.length;
                }
            } catch (e) { /* silent — network errors don't crash the page */ }
            this.loading = false;
        },

        applyFilters() {
            this.items = [];
            this.page  = 1;
            this.total = 0;
            this.load();
        },

        loadMore() {
            this.page++;
            this.load(true);
        },

        toggleSort(col) {
            if (this.filters.sort === col) {
                this.filters.dir = this.filters.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.filters.sort = col;
                this.filters.dir  = 'DESC';
            }
            this.applyFilters();
        },

        // ── Upload ─────────────────────────────────────────────────
        openUploadModal() {
            this.uploadModal = {
                open: true, saving: false, error: null,
                entity_type: '', entity_id: '', document_type: '',
                title: '', expiration_date: '', notes: '', file: null,
            };
        },

        async submitUpload() {
            const m = this.uploadModal;
            if (!m.entity_type)   { m.error = 'Entity type is required.';  return; }
            if (!m.entity_id)     { m.error = 'Entity ID is required.';    return; }
            if (!m.document_type) { m.error = 'Document type is required.'; return; }
            if (!m.file)          { m.error = 'Please select a file.';     return; }

            m.saving = true;
            m.error  = null;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const fd   = new FormData();
                fd.append('entity_type',     m.entity_type);
                fd.append('entity_id',       m.entity_id);
                fd.append('document_type',   m.document_type);
                fd.append('document',        m.file);
                if (m.title)           fd.append('title',           m.title);
                if (m.expiration_date) fd.append('expiration_date', m.expiration_date);
                if (m.notes)           fd.append('notes',           m.notes);

                const res  = await fetch('<?= base_url('api/v1/documents/upload') ?>', {
                    method:  'POST',
                    headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body:    fd,
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    m.open = false;
                    // Reload from page 1 to show the new document
                    this.applyFilters();
                } else {
                    m.error = json.error?.message ?? 'Upload failed. Please try again.';
                }
            } catch (e) {
                m.error = 'Network error. Please try again.';
            } finally {
                m.saving = false;
            }
        },

        // ── Delete ─────────────────────────────────────────────────
        async confirmDelete(doc) {
            const label = doc.title || doc.file_name;
            if (!(await FF_Confirm.ask(`Remove "${label}"? This cannot be undone.`))) return;
            try {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                const res  = await fetch('<?= base_url('api/v1/documents/delete') ?>', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json',
                               'X-CSRF-Token': csrf,
                               'X-Requested-With': 'XMLHttpRequest' },
                    body:    JSON.stringify({ id: doc.id }),
                });
                const json = await res.json();
                if (res.ok && json.success) {
                    this.items = this.items.filter(d => d.id !== doc.id);
                    this.total = Math.max(0, this.total - 1);
                } else {
                    FF_Toast.error(json.error?.message ?? 'Failed to remove document.');
                }
            } catch (e) {
                FF_Toast.error('Network error. Please try again.');
            }
        },

        // ── Entity helpers ─────────────────────────────────────────
        entityUrl(doc) {
            const base = (window.FF_BASE_PATH || '') + '/';
            const m = {
                customer:       base + 'customers/show?id=' + doc.entity_id,
                equipment_unit: base + 'equipment/show?id=' + doc.entity_id,
                lease:          base + 'leases/show?id=' + doc.entity_id,
                inspection:     base + 'inspections/show?id=' + doc.entity_id,
                damage_claim:   base + 'damage_claims/show?id=' + doc.entity_id,
            };
            return m[doc.entity_type] ?? '#';
        },

        entityTypeLabel(t) {
            return { customer:'Customer', equipment_unit:'Equipment',
                     lease:'Lease', inspection:'Inspection',
                     damage_claim:'Damage Claim' }[t] ?? t;
        },

        entityTypeBadge(t) {
            return { customer:'badge badge-info', equipment_unit:'badge badge-success',
                     lease:'badge badge-primary', inspection:'badge badge-warning',
                     damage_claim:'badge badge-danger' }[t] ?? 'badge badge-neutral';
        },

        docTypeLabel(t) {
            const m = {
                cvi:'CVI', registration:'Registration', insurance:'Insurance',
                contract:'Contract', inspection_in:'Insp. In', inspection_out:'Insp. Out',
                amendment:'Amendment', tax_exemption:'Tax Exempt',
                credit_agreement:'Credit Agr.', estimate:'Estimate',
                repair_invoice:'Repair Inv.', report:'Report', other:'Other',
            };
            return m[t] ?? t;
        },

        docTypeBadge(t, entityType) {
            // Compliance documents get warning/success color cues
            if (['cvi','registration','insurance'].includes(t)) return 'badge badge-warning';
            if (['contract'].includes(t))                       return 'badge badge-primary';
            if (['amendment'].includes(t))                      return 'badge badge-info';
            return 'badge badge-neutral';
        },

        expiryClass(date) {
            if (!date) return '';
            const d    = new Date(date);
            const now  = new Date();
            const diff = (d - now) / (1000 * 60 * 60 * 24);
            if (diff < 0)  return 'text-danger font-mono text-sm';
            if (diff < 30) return 'text-warning font-mono text-sm';
            return 'font-mono text-sm';
        },

        // ── Formatting ─────────────────────────────────────────────
        formatDate(dt) {
            if (!dt) return '—';
            try {
                const d = new Date(dt.includes('T') ? dt : dt + 'T00:00:00');
                return d.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
            } catch (e) { return dt; }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
