<?php
declare(strict_types=1);

/**
 * app/portal/documents/index.php
 *
 * Portal document vault — all documents belonging to this customer.
 * Customer cannot upload — uploads go through service requests.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// ── AJAX handler (must run before header to avoid HTML output) ──
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');

    $search = clean_string($_GET['q'] ?? null);
    $type   = clean_string($_GET['type'] ?? null);

    $customerDocs = "SELECT d.id, d.title, d.document_type, d.file_name, d.file_size_kb,
                            d.expiration_date, d.uploaded_at, d.entity_type, d.entity_id,
                            'customer' AS source
                     FROM documents d
                     WHERE d.entity_type = 'customer' AND d.entity_id = ? AND d.deleted_at IS NULL";

    $leaseDocs = "SELECT d.id, d.title, d.document_type, d.file_name, d.file_size_kb,
                         d.expiration_date, d.uploaded_at, d.entity_type, d.entity_id,
                         'lease' AS source
                  FROM documents d
                  JOIN leases l ON d.entity_type = 'lease' AND d.entity_id = l.id
                  WHERE l.customer_id = ? AND l.deleted_at IS NULL AND d.deleted_at IS NULL";

    $equipDocs = "SELECT d.id, d.title, d.document_type, d.file_name, d.file_size_kb,
                         d.expiration_date, d.uploaded_at, d.entity_type, d.entity_id,
                         'equipment' AS source
                  FROM documents d
                  JOIN equipment_units eu ON d.entity_type = 'equipment_unit' AND d.entity_id = eu.id
                  JOIN leases l ON eu.id = l.equipment_unit_id AND l.customer_id = ? AND l.deleted_at IS NULL
                  WHERE d.deleted_at IS NULL AND eu.deleted_at IS NULL";

    $allDocs = "({$customerDocs}) UNION ({$leaseDocs}) UNION ({$equipDocs})";
    $allParams = [$cid, $cid, $cid];

    $wrapWhere = [];
    if ($search) {
        $wrapWhere[] = "(sub.title LIKE ? OR sub.file_name LIKE ?)";
        $allParams[] = "%{$search}%";
        $allParams[] = "%{$search}%";
    }
    if ($type) {
        $wrapWhere[] = "sub.document_type = ?";
        $allParams[] = $type;
    }

    $wrapSQL = $wrapWhere ? ' WHERE ' . implode(' AND ', $wrapWhere) : '';

    $rows = db_select(
        "SELECT sub.* FROM ({$allDocs}) AS sub {$wrapSQL} ORDER BY sub.uploaded_at DESC LIMIT 100",
        $allParams
    );

    $today = date('Y-m-d');
    $soon  = date('Y-m-d', strtotime('+30 days'));

    foreach ($rows as &$r) {
        $r['type_label'] = ucfirst(str_replace('_', ' ', $r['document_type']));
        $r['size_label'] = number_format((float)$r['file_size_kb']) . ' KB';
        $r['uploaded_at_fmt'] = format_date($r['uploaded_at']);
        $r['expiration_date_fmt'] = $r['expiration_date'] ? format_date($r['expiration_date']) : null;
        $r['view_url'] = base_url('api/v1/documents/serve.php?id=' . $r['id']);
        $r['expiry_class'] = '';
        if ($r['expiration_date']) {
            if ($r['expiration_date'] < $today) $r['expiry_class'] = 'danger';
            elseif ($r['expiration_date'] <= $soon) $r['expiry_class'] = 'warning';
        }
        // Entity label
        $r['entity_label'] = '';
        if ($r['entity_type'] === 'customer') {
            $r['entity_label'] = 'Company';
        } elseif ($r['entity_type'] === 'lease') {
            $lRow = db_row("SELECT contract_number FROM leases WHERE id = ?", [(int)$r['entity_id']]);
            $r['entity_label'] = $lRow ? 'Lease ' . $lRow['contract_number'] : 'Lease';
        } elseif ($r['entity_type'] === 'equipment_unit') {
            $eRow = db_row("SELECT unit_number FROM equipment_units WHERE id = ?", [(int)$r['entity_id']]);
            $r['entity_label'] = $eRow ? 'Unit ' . $eRow['unit_number'] : 'Equipment';
        }
        // Strip server paths (Trap 7)
        unset($r['source']);
    }

    echo json_encode(['success' => true, 'data' => ['documents' => $rows]]);
    exit;
}

$pageTitle = 'Documents';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div x-data="PortalDocuments()" x-init="load()" x-cloak>

    <!-- Filters -->
    <div class="portal-filters">
        <input type="text" class="portal-search-input"
               placeholder="Search documents..."
               x-model="search" @input.debounce.400ms="load()">
        <select class="portal-filter-select" x-model="typeFilter" @change="load()">
            <option value="">All Types</option>
            <option value="lease_contract">Lease Contracts</option>
            <option value="inspection_report">Inspection Reports</option>
            <option value="invoice">Invoices</option>
            <option value="cvi">CVI</option>
            <option value="registration">Registration</option>
            <option value="insurance">Insurance</option>
            <option value="other">Other</option>
        </select>
    </div>

    <!-- Documents Table -->
    <div class="portal-section">
        <div class="portal-section-body--flush">
            <template x-if="loading">
                <div class="portal-empty"><p class="portal-empty-text">Loading documents...</p></div>
            </template>
            <template x-if="!loading && docs.length === 0">
                <div class="portal-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                    <p class="portal-empty-title">No documents found</p>
                    <p class="portal-empty-text">Documents uploaded by our team will appear here.</p>
                </div>
            </template>
            <div class="table-responsive">
<table class="portal-table" x-show="!loading && docs.length > 0">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Type</th>
                        <th>Related To</th>
                        <th>Expires</th>
                        <th>Uploaded</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="d in docs" :key="d.id">
                        <tr>
                            <td>
                                <div style="font-weight:600;" x-text="d.title || d.file_name"></div>
                                <div style="font-size:0.75rem;color:var(--text-muted);" x-text="d.file_name + ' (' + d.size_label + ')'"></div>
                            </td>
                            <td><span class="badge badge-neutral" x-text="d.type_label"></span></td>
                            <td x-text="d.entity_label || '—'" style="font-size:0.8125rem;"></td>
                            <td>
                                <span class="font-mono"
                                      :style="d.expiry_class === 'danger' ? 'color:var(--color-danger);font-weight:600' : (d.expiry_class === 'warning' ? 'color:var(--color-warning);font-weight:600' : '')"
                                      x-text="d.expiration_date_fmt || '—'"></span>
                            </td>
                            <td class="font-mono" x-text="d.uploaded_at_fmt"></td>
                            <td>
                                <a :href="d.view_url" target="_blank" class="portal-form-link" style="font-size:0.8125rem;">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
    </div>

</div>

<script>
function PortalDocuments() {
    return {
        search: '',
        typeFilter: '',
        docs: [],
        loading: true,

        load() {
            this.loading = true;
            const p = new URLSearchParams({ q: this.search, type: this.typeFilter });
            FF_Api.get(FF_Api.url('/portal/documents/index.php?ajax=1&' + p.toString()))
                .then(d => {
                    if (d.success) this.docs = d.data.documents || [];
                    this.loading = false;
                }).catch(() => { this.loading = false; });
        },
    };
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
