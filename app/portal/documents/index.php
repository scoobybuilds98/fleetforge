<?php
declare(strict_types=1);

/**
 * app/portal/documents/index.php
 *
 * Customer portal — Documents (S-PORTAL-REDESIGN).
 *
 * Everything we've shared with the customer: account paperwork, lease
 * contracts/inspections, and the paperwork of units they have on rent now.
 * Search, a type filter built from the types actually present, expiry
 * warnings, and open/download through api/v1/portal/documents/file.
 *
 * Fixed here (security): the old list showed every document on any unit
 * the customer had EVER leased — including files uploaded while another
 * customer had the unit — and ignored `is_private`. Visibility is now
 * pt_portal_documents_sql() (the same rule the file stream enforces).
 * Also fixed: every "View" link pointed at a file that never existed, the
 * type filter offered values that are never stored, and entity labels were
 * fetched one query per row.
 *
 * Trap 8: scoped to portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$today = ff_today();
$soon  = ff_local_date_add($today, 30);

[$docSql, $docParams] = pt_portal_documents_sql($cid);
$rows = db_select(
    "SELECT d.id, d.title, d.document_type, d.file_name, d.file_size_kb, d.mime_type,
            d.expiration_date, d.uploaded_at, d.entity_type, d.entity_id,
            l.contract_number, eu.unit_number
       FROM documents d
       LEFT JOIN leases l ON d.entity_type = 'lease' AND l.id = d.entity_id
       LEFT JOIN equipment_units eu ON d.entity_type = 'equipment_unit' AND eu.id = d.entity_id
      WHERE {$docSql}
      ORDER BY d.uploaded_at DESC
      LIMIT 500",
    $docParams
);

$docs  = [];
$types = [];
$expiringCount = 0;
foreach ($rows as $d) {
    $typeLabel = pt_document_type_label($d['document_type']);
    $types[(string) $d['document_type']] = $typeLabel;
    $exp  = (string) ($d['expiration_date'] ?? '');
    $tone = $exp === '' ? '' : ($exp < $today ? 'danger' : ($exp <= $soon ? 'warning' : ''));
    if ($tone !== '') $expiringCount++;
    $docs[] = [
        'id'      => (int) $d['id'],
        'title'   => (string) ($d['title'] ?: $d['file_name'] ?: 'Document'),
        'type'    => (string) $d['document_type'],
        'typeLbl' => $typeLabel,
        'about'   => match ($d['entity_type']) {
            'lease'          => 'Lease ' . ($d['contract_number'] ?? ''),
            'equipment_unit' => 'Unit ' . ($d['unit_number'] ?? ''),
            default          => 'Your account',
        },
        'size'    => $d['file_size_kb'] ? ((int) $d['file_size_kb'] >= 1024 ? number_format((int) $d['file_size_kb'] / 1024, 1) . ' MB' : (int) $d['file_size_kb'] . ' KB') : '',
        'added'   => format_datetime($d['uploaded_at'], 'M j, Y'),
        'expiry'  => $exp !== '' ? format_date($exp) : '',
        'tone'    => $tone,
        'view'    => base_url('api/v1/portal/documents/file') . '?id=' . (int) $d['id'],
        'dl'      => base_url('api/v1/portal/documents/file') . '?id=' . (int) $d['id'] . '&download=1',
    ];
}
asort($types);

$pageTitle = 'Documents';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Fleet',
    'title'   => 'Documents',
    'sub'     => 'Contracts, inspections, registrations and other paperwork we\'ve shared with you.'
        . ($expiringCount ? ' <strong class="pt-danger-ink">' . $expiringCount . ' need' . ($expiringCount === 1 ? 's' : '') . ' attention.</strong>' : ''),
    'actions' => '<a class="pt-btn pt-btn--secondary" href="' . e(pt_url('requests/create?type=document_request')) . '">' . pt_icon('plus') . ' Request a document</a>',
]);
?>

<section class="pt-card" x-data="{
        q: '', type: '',
        rows: <?= e(json_encode($docs)) ?>,
        get list() {
            const t = this.q.trim().toLowerCase();
            return this.rows.filter(d => (!this.type || d.type === this.type)
                && (!t || d.title.toLowerCase().includes(t) || d.about.toLowerCase().includes(t) || d.typeLbl.toLowerCase().includes(t)));
        }
    }">
    <div class="pt-toolbar">
        <label class="pt-search-field">
            <?= pt_icon('magnifying-glass') ?>
            <span class="pt-sr">Search documents</span>
            <input type="search" class="pt-input" placeholder="Search by name, lease or unit" x-model="q">
        </label>
        <?php if (count($types) > 1): ?>
            <select class="pt-select" style="width:auto;min-height:38px;height:38px" x-model="type" aria-label="Document type">
                <option value="">All types</option>
                <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <div class="pt-toolbar-spacer"></div>
        <span class="pt-muted" style="font-size:13px" x-text="list.length + ' of ' + rows.length"></span>
    </div>

    <?php if (!$docs): ?>
        <?= pt_empty('folder-open', 'No documents yet', 'When we share a contract, inspection report or registration with you, it shows up here.') ?>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table data-no-auto-label class="pt-table pt-table--stack">
                <thead><tr><th>Document</th><th>About</th><th>Added</th><th>Expires</th><th class="shrink"><span class="pt-sr">Open</span></th></tr></thead>
                <tbody>
                    <template x-for="d in list" :key="d.id">
                        <tr>
                            <td class="pt-cell-primary">
                                <a class="pt-table-main" :href="d.view" target="_blank" rel="noopener" x-text="d.title"></a>
                                <span class="pt-table-sub" x-text="d.typeLbl + (d.size ? ' · ' + d.size : '')"></span>
                            </td>
                            <td data-label="About" x-text="d.about"></td>
                            <td class="nw" data-label="Added" x-text="d.added"></td>
                            <td class="nw" data-label="Expires">
                                <template x-if="d.expiry"><span class="pt-pill" :class="d.tone ? 'pt-pill--' + d.tone : 'pt-pill--plain'" x-text="(d.tone === 'danger' ? 'Expired ' : '') + d.expiry"></span></template>
                                <template x-if="!d.expiry"><span class="pt-faint">—</span></template>
                            </td>
                            <td class="shrink pt-cell-actions">
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    <a class="pt-btn pt-btn--secondary pt-btn--sm" :href="d.view" target="_blank" rel="noopener"><?= pt_icon('eye') ?> Open</a>
                                    <a class="pt-btn pt-btn--ghost pt-btn--sm" :href="d.dl" :aria-label="'Download ' + d.title"><?= pt_icon('arrow-down-tray') ?></a>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="list.length === 0" x-cloak><?= pt_empty('magnifying-glass', 'No documents match', 'Try another search or type.') ?></div>
    <?php endif; ?>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
