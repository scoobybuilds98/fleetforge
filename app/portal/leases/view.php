<?php
declare(strict_types=1);

/**
 * app/portal/leases/view.php
 *
 * Customer portal — one lease (S-PORTAL-REDESIGN).
 *
 * Header actions (active leases): extend, return, report damage — each a
 * pre-filled service request. Key numbers: days on rent, billed through,
 * open balance on this lease, invoices so far. Then invoices, the mileage
 * section, documents and status history; the side rail carries the unit,
 * lease terms, rates and a help card.
 *
 * Kept from the previous page (behaviour unchanged):
 *   - S-PORTAL-MILEAGE-MODEL-B (D135 matrix): precharge card (paid / credits
 *     applied / balance + refund block + drawdown history) or usage-only
 *     card; drafts never shown in mileage history
 *   - S-LEASE-CLOSE-ACTUAL-DATE: period ends show the actual return day
 *   - GPS "Track live" when the unit has a Samsara share link
 *
 * Fixed here:
 *   - money in the mileage section is bcmath (was float casts — D16)
 *   - the mileage section was gated on mileage_rate_km only, so a lease
 *     priced per MILE with no km rate hid it; now either rate shows it
 *   - document "View" pointed at a file that never existed; documents now
 *     stream through api/v1/portal/documents/file (private docs excluded)
 *   - staff-written notes on status changes are no longer shown
 *   - invoice visibility = pt_invoice_visible_sql() (advance bills appear,
 *     matching the invoice list/PDF)
 *
 * Trap 8: the lease must belong to portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid     = portal_customer_id();
$leaseId = clean_int($_GET['id'] ?? null);
if (!$leaseId) {
    header('Location: ' . pt_url('leases'));
    exit;
}

$lease = db_row(
    "SELECT l.*, eu.unit_number, eu.samsara_vehicle_url, eu.yard_location, eu.license_plate, eu.vin,
            eb.label AS brand, et.model, et.category, et.name AS type_name, eu.year
       FROM leases l
       JOIN equipment_units eu ON eu.id = l.equipment_unit_id
       JOIN equipment_templates et ON et.id = eu.template_id
       LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
      WHERE l.id = ? AND l.customer_id = ? AND l.deleted_at IS NULL
        AND eu.deleted_at IS NULL AND et.deleted_at IS NULL",
    [$leaseId, $cid]
);
if (!$lease) {
    header('Location: ' . pt_url('leases'));
    exit;
}

$today = ff_today();
$cur   = (string) ($lease['currency'] ?? 'CAD');

// Lease period context for ff_invoice_display_period_end() on this lease's invoices.
$_leasePeriodCtx = [
    'actual_return_date'   => $lease['actual_return_date']   ?? null,
    'actual_return_time'   => $lease['actual_return_time']   ?? null,
    'start_time'           => $lease['start_time']           ?? null,
    'billing_days_removed' => $lease['billing_days_removed'] ?? 0,
];

$invoices = array_map('pt_invoice_json', db_select(
    pt_invoice_select_sql() . " WHERE i.lease_id = ? AND i.customer_id = ? AND " . pt_invoice_visible_sql('i') . "
     ORDER BY i.invoice_date DESC, i.id DESC",
    [$leaseId, $cid]
));
$leaseOpen = '0.00';
foreach ($invoices as $iv) {
    if ($iv['payable']) $leaseOpen = bcadd($leaseOpen, $iv['balance'], 2);
}
$openIds = array_values(array_map(static fn ($iv) => $iv['id'], array_filter($invoices, static fn ($iv) => $iv['payable'])));

// Status history — the customer sees WHAT changed and when, never staff notes.
$timeline = db_select(
    "SELECT new_status, changed_at FROM lease_status_log WHERE lease_id = ? ORDER BY changed_at DESC LIMIT 20",
    [$leaseId]
);

// Documents on this lease + on this unit while it's with the customer.
[$docSql, $docParams] = pt_portal_documents_sql($cid);
$documents = db_select(
    "SELECT d.id, d.title, d.document_type, d.file_name, d.file_size_kb, d.expiration_date, d.uploaded_at
       FROM documents d
      WHERE {$docSql}
        AND ((d.entity_type = 'lease' AND d.entity_id = ?) OR (d.entity_type = 'equipment_unit' AND d.entity_id = ?))
      ORDER BY d.uploaded_at DESC",
    array_merge($docParams, [$leaseId, (int) $lease['equipment_unit_id']])
);

$endForDays = $lease['actual_return_date'] ?: ($lease['status'] === 'active' ? $today : ($lease['end_date'] ?: $today));
$daysOnRent = max(1, pt_days_between($lease['start_date'], $endForDays) + 1);

// ── Mileage (S-PORTAL-MILEAGE-MODEL-B, D135) ─────────────────────────────
$rateKm    = (string) ($lease['mileage_rate_km'] ?? '0');
$rateShown = (string) ($lease['mileage_rate'] ?? '0');
$hasMileage = bccomp($rateKm ?: '0', '0', 4) > 0 || bccomp($rateShown ?: '0', '0', 4) > 0;
$mileageConfig = $hasMileage ? ((int) ($lease['precharge_enabled'] ?? 0) === 1 ? 'precharge' : 'usage_only') : null;
$mileageHistory = [];
$precharge = null;
$refund    = null;
if ($mileageConfig !== null) {
    $mileageHistory = db_select(
        "SELECT ili.amount AS usage_amount, ili.quantity AS qty, i.id AS invoice_id, i.invoice_number,
                i.billing_period_start, i.billing_period_end,
                (SELECT cn.amount FROM invoice_line_items cn
                  WHERE cn.invoice_id = i.id AND cn.item_type = 'mileage_drawdown_credit' LIMIT 1) AS credit_amount
           FROM invoice_line_items ili
           JOIN invoices i ON i.id = ili.invoice_id
          WHERE i.lease_id = ? AND i.deleted_at IS NULL
            AND i.status IN ('sent','partially_paid','paid','overdue')
            AND ili.item_type IN ('mileage_usage','mileage_estimate')
          ORDER BY i.billing_period_end DESC, i.id DESC
          LIMIT 5",
        [$leaseId]
    );
    if ($mileageConfig === 'precharge') {
        $credits = (string) (db_row(
            "SELECT COALESCE(SUM(ili.amount), 0) AS c
               FROM invoice_line_items ili
               JOIN invoices i ON i.id = ili.invoice_id
              WHERE i.lease_id = ? AND i.deleted_at IS NULL
                AND i.status IN ('sent','partially_paid','paid','overdue')
                AND ili.item_type = 'mileage_drawdown_credit'",
            [$leaseId]
        )['c'] ?? '0.00');
        $precharge = [
            'paid'    => (string) ($lease['precharge_amount'] ?? '0.00'),
            'credits' => $credits,
            'balance' => (string) ($lease['precharge_balance'] ?? '0.00'),
        ];
        $method = $lease['precharge_refund_method'] ?? null;
        if ($method !== null) {
            $cn = $method === 'credit' ? db_row(
                "SELECT credit_note_number, amount, created_at FROM credit_notes
                  WHERE lease_id = ? AND source = 'precharge_refund' AND deleted_at IS NULL AND status != 'void'
                  ORDER BY id DESC LIMIT 1",
                [$leaseId]
            ) : null;
            $settled = $lease['precharge_refund_settled_at'] ?? null;
            $refund = [
                'label'  => $method === 'credit' ? 'Credit applied' : ($settled ? 'Cash refund issued' : 'Cash refund pending'),
                'tone'   => $method === 'credit' ? 'info' : ($settled ? 'success' : 'warning'),
                'amount' => $cn ? (string) $cn['amount'] : $precharge['balance'],
                'detail' => $cn
                    ? 'Credit note ' . $cn['credit_note_number'] . ($cn['created_at'] ? ' · ' . format_datetime($cn['created_at'], 'M j, Y') : '')
                    : ($settled ? 'Paid ' . format_datetime($settled, 'M j, Y') : ''),
            ];
        }
    }
}
$unitLabel = ff_mileage_unit_label($lease);

// Rates that actually apply (hourly-only leases are first-class — d/w/m may be 0).
$rates = [];
foreach ([['daily_rate', 'Daily'], ['weekly_rate', 'Weekly'], ['monthly_rate', 'Monthly']] as [$col, $label]) {
    if (bccomp((string) ($lease[$col] ?? '0'), '0', 2) > 0) $rates[] = [$label, pt_money($lease[$col], $cur)];
}
if (bccomp((string) ($lease['hourly_rate'] ?? '0'), '0', 4) > 0) {
    $rates[] = ['Engine hours', pt_money($lease['hourly_rate'], $cur) . ' / hr'];
}
if ($hasMileage) {
    $rates[] = ['Mileage', pt_money($rateShown ?: $rateKm, $cur) . ' / ' . ff_mileage_unit_label($lease, true)];
}
if (!empty($lease['gps_opt_in']) && bccomp((string) ($lease['gps_cost'] ?? '0'), '0', 2) > 0) {
    $rates[] = ['GPS tracking', pt_money($lease['gps_cost'], $cur)];
}

[$stLabel, $stTone] = match ($lease['status']) {
    'active'    => ['On rent', 'success'],
    'completed' => ['Returned', 'neutral'],
    'cancelled' => ['Cancelled', 'danger'],
    'pending'   => ['Starting soon', 'info'],
    default     => [ucfirst((string) $lease['status']), 'neutral'],
};
$unitType = trim(($lease['brand'] ? $lease['brand'] . ' ' : '') . ($lease['type_name'] ?: ucfirst((string) $lease['category'])));

$reqBase = 'requests/create?lease_id=' . $leaseId . '&equipment_id=' . (int) $lease['equipment_unit_id'] . '&type=';

$pageTitle    = 'Lease ' . $lease['contract_number'];
require_once dirname(__DIR__) . '/includes/header.php';

ob_start();
if ($lease['status'] === 'active'): ?>
    <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url($reqBase . 'lease_extension')) ?>"><?= pt_icon('calendar-days') ?> Extend</a>
    <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url($reqBase . 'early_return')) ?>"><?= pt_icon('arrow-uturn-left') ?> Return</a>
    <a class="pt-btn pt-btn--primary" href="<?= e(pt_url($reqBase . 'damage_report')) ?>"><?= pt_icon('wrench-screwdriver') ?> Report a problem</a>
<?php endif;
$actions = ob_get_clean();

echo pt_page_head([
    'back'    => ['Leases', pt_url('leases')],
    'title'   => 'Lease ' . $lease['contract_number'],
    'meta'    => pt_badge($stLabel, $stTone)
        . '<span>Unit <strong>' . e($lease['unit_number']) . '</strong> · ' . e($unitType) . '</span>'
        . '<span>Since ' . e(format_date($lease['start_date'])) . '</span>',
    'actions' => $actions,
]);
?>

<div class="pt-stats">
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label"><?= $lease['status'] === 'active' ? 'Days on rent' : 'Days rented' ?></span><span class="pt-stat-ic pt-stat-ic--brand"><?= pt_icon('calendar-days') ?></span></div>
        <div class="pt-stat-value"><?= number_format($daysOnRent) ?></div>
        <div class="pt-stat-sub"><?= e(format_date($lease['start_date'])) ?> – <?= $lease['status'] === 'active' ? 'today' : e(format_date($endForDays)) ?></div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Billed through</span><span class="pt-stat-ic pt-stat-ic--info"><?= pt_icon('document-check') ?></span></div>
        <div class="pt-stat-value" style="font-size:22px"><?= !empty($lease['last_billed_date']) ? e(format_date($lease['last_billed_date'])) : '—' ?></div>
        <div class="pt-stat-sub"><?= ucfirst(str_replace('_', ' ', (string) ($lease['billing_cycle'] ?? 'monthly'))) ?> billing</div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Open on this lease</span><span class="pt-stat-ic <?= bccomp($leaseOpen, '0', 2) > 0 ? 'pt-stat-ic--warning' : 'pt-stat-ic--success' ?>"><?= pt_icon('banknotes') ?></span></div>
        <div class="pt-stat-value"><?= e(pt_money($leaseOpen, $cur)) ?></div>
        <div class="pt-stat-sub"><?= count($openIds) ? count($openIds) . ' open invoice' . (count($openIds) === 1 ? '' : 's') : 'Nothing owing' ?></div>
    </div>
    <div class="pt-stat">
        <div class="pt-stat-top"><span class="pt-stat-label">Invoices</span><span class="pt-stat-ic"><?= pt_icon('document-text') ?></span></div>
        <div class="pt-stat-value"><?= count($invoices) ?></div>
        <div class="pt-stat-sub">On this lease so far</div>
    </div>
</div>

<div class="pt-grid pt-grid--main">
    <div class="pt-stack">

        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('document-text') ?> Invoices</h2>
                    <p class="pt-card-sub">Newest first</p>
                </div>
                <?php if ($openIds): ?>
                    <button type="button" class="pt-btn pt-btn--primary pt-btn--sm" x-data @click="$store.checkout.start(<?= e(json_encode($openIds)) ?>)"><?= pt_icon('credit-card') ?> Pay <?= e(pt_money($leaseOpen, $cur)) ?></button>
                <?php endif; ?>
            </div>
            <?php if (!$invoices): ?>
                <?= pt_empty('document-text', 'No invoices yet', 'Invoices for this lease will appear here once issued.') ?>
            <?php else: ?>
                <div class="pt-card-body--flush pt-table-wrap">
                    <table data-no-auto-label class="pt-table pt-table--stack">
                        <thead><tr><th>Invoice</th><th>Issued</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($invoices as $iv): ?>
                            <tr>
                                <td class="pt-cell-primary"><a class="pt-table-main" href="<?= e(pt_url('invoices/view?id=' . $iv['id'])) ?>"><?= e($iv['number']) ?></a><?php if ($iv['period'] !== ''): ?><span class="pt-table-sub"><?= e($iv['period']) ?></span><?php endif; ?></td>
                                <td class="nw" data-label="Issued"><?= e(format_date($iv['invoice_date'])) ?></td>
                                <td data-label="Status"><?= pt_badge($iv['status_label'], $iv['status_tone']) ?></td>
                                <td class="num" data-label="Total"><?= e($iv['total_fmt']) ?></td>
                                <td class="num" data-label="Balance"><strong><?= $iv['payable'] ? e($iv['balance_fmt']) : '—' ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($mileageConfig !== null): ?>
        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('map') ?> <?= $mileageConfig === 'precharge' ? 'Mileage precharge' : 'Mileage' ?></h2>
                    <p class="pt-card-sub">Charged at <?= e(pt_money($rateShown ?: $rateKm, $cur)) ?> per <?= e(ff_mileage_unit_label($lease, true)) ?><?= $mileageConfig === 'precharge' ? ' and drawn down from your prepaid balance' : '' ?></p>
                </div>
            </div>
            <div class="pt-card-body">
                <?php if ($precharge): ?>
                    <div class="pt-grid pt-grid--3" style="gap:12px;margin-bottom:14px">
                        <div class="pt-copy-row" style="display:block"><div class="pt-copy-k">Precharge paid</div><div class="pt-copy-v pt-num" style="font-size:18px"><?= e(pt_money($precharge['paid'], $cur)) ?></div></div>
                        <div class="pt-copy-row" style="display:block;margin:0"><div class="pt-copy-k">Credits applied</div><div class="pt-copy-v pt-num" style="font-size:18px"><?= e(pt_money($precharge['credits'], $cur)) ?></div></div>
                        <div class="pt-copy-row" style="display:block;margin:0"><div class="pt-copy-k">Balance remaining</div><div class="pt-copy-v pt-num" style="font-size:18px"><?= e(pt_money($precharge['balance'], $cur)) ?></div></div>
                    </div>
                    <?php if ($refund): ?>
                        <div class="pt-note pt-note--<?= e($refund['tone']) ?>" style="margin-bottom:14px"><?= pt_icon('receipt-percent') ?>
                            <span><strong><?= e($refund['label']) ?>: <?= e(pt_money($refund['amount'], $cur)) ?></strong><?= $refund['detail'] !== '' ? ' · ' . e($refund['detail']) : '' ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($mileageHistory): ?>
                    <div class="pt-table-wrap" style="margin:0 -20px -20px">
                        <table data-no-auto-label class="pt-table pt-table--compact">
                            <thead><tr><th>Invoice</th><th>Period</th><th class="num">Distance</th><th class="num">Charge</th><?php if ($precharge): ?><th class="num">Credit</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($mileageHistory as $row): ?>
                                <tr>
                                    <td><a class="pt-table-main" href="<?= e(pt_url('invoices/view?id=' . (int) $row['invoice_id'])) ?>"><?= e($row['invoice_number']) ?></a></td>
                                    <td class="nw"><?= e(format_date($row['billing_period_start'])) ?> – <?= e(format_date(ff_invoice_display_period_end($row + $_leasePeriodCtx))) ?></td>
                                    <?php /* quantity is already in the lease's display unit — never re-convert */ ?>
                                    <td class="num"><?= e(number_format((float) $row['qty'], 2)) ?> <?= e($unitLabel) ?></td>
                                    <td class="num"><?= e(pt_money($row['usage_amount'], $cur)) ?></td>
                                    <?php if ($precharge): ?><td class="num pt-credit-line"><?= $row['credit_amount'] !== null ? e(pt_money($row['credit_amount'], $cur)) : '—' ?></td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="pt-muted" style="margin:0;font-size:13.5px">No mileage recorded yet — it shows here once invoices start reporting distance.</p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="pt-card">
            <div class="pt-card-head">
                <div><h2 class="pt-card-title"><?= pt_icon('folder-open') ?> Documents</h2><p class="pt-card-sub">Contract, inspections and unit paperwork</p></div>
            </div>
            <?php if (!$documents): ?>
                <?= pt_empty('folder-open', 'No documents yet', 'Need a copy of your contract or the unit registration? Ask us.', '<a class="pt-btn pt-btn--soft pt-btn--sm" href="' . e(pt_url($reqBase . 'document_request')) . '">Request a document</a>') ?>
            <?php else: ?>
                <ul class="pt-attn">
                    <?php foreach ($documents as $d):
                        $late = !empty($d['expiration_date']) && $d['expiration_date'] < $today;
                    ?>
                        <li>
                            <a class="pt-attn-item" data-tone="<?= $late ? 'danger' : 'brand' ?>" href="<?= e(base_url('api/v1/portal/documents/file') . '?id=' . (int) $d['id']) ?>" target="_blank" rel="noopener">
                                <span class="pt-attn-ic"><?= pt_icon('document-text') ?></span>
                                <span class="pt-attn-body">
                                    <span class="pt-attn-title" style="display:block"><?= e($d['title'] ?: $d['file_name']) ?></span>
                                    <span class="pt-attn-text" style="display:block"><?= e(pt_document_type_label($d['document_type'])) ?><?= $d['expiration_date'] ? ' · ' . ($late ? 'expired ' : 'expires ') . e(format_date($d['expiration_date'])) : '' ?></span>
                                </span>
                                <span class="pt-attn-go"><?= pt_icon('arrow-top-right-on-square') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($timeline): ?>
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('clock') ?> Status history</h2></div>
            <ul class="pt-timeline" style="padding-top:12px">
                <?php foreach ($timeline as $ev):
                    [$tl, $tt, $ti] = match ($ev['new_status']) {
                        'active'    => ['Went on rent', 'success', 'truck'],
                        'completed' => ['Returned', 'brand', 'check-circle'],
                        'cancelled' => ['Cancelled', 'danger', 'x-circle'],
                        'pending'   => ['Booked', 'info', 'calendar-days'],
                        default     => ['Status: ' . ucfirst((string) $ev['new_status']), 'info', 'information-circle'],
                    };
                ?>
                    <li class="pt-tl-item">
                        <span class="pt-tl-ic pt-tl-ic--<?= e($tt) ?>"><?= pt_icon($ti) ?></span>
                        <div class="pt-tl-body"><div class="pt-tl-title"><?= e($tl) ?></div><div class="pt-tl-meta"><?= e(format_datetime($ev['changed_at'], 'M j, Y')) ?></div></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <aside class="pt-stack pt-sticky">
        <section class="pt-card">
            <div class="pt-unit-top">
                <span class="pt-unit-art"><?= pt_icon('truck') ?></span>
                <div style="min-width:0">
                    <div class="pt-unit-id"><?= e($lease['unit_number']) ?></div>
                    <div class="pt-unit-type"><?= e($unitType) ?></div>
                </div>
            </div>
            <div class="pt-card-body" style="padding-top:4px">
                <dl class="pt-kv">
                    <?php if (!empty($lease['model'])): ?><div class="pt-kv-row"><dt>Model</dt><dd><?= e($lease['model']) ?></dd></div><?php endif; ?>
                    <?php if (!empty($lease['year'])): ?><div class="pt-kv-row"><dt>Year</dt><dd><?= e((string) $lease['year']) ?></dd></div><?php endif; ?>
                    <?php if (!empty($lease['license_plate'])): ?><div class="pt-kv-row"><dt>Plate</dt><dd><?= e($lease['license_plate']) ?></dd></div><?php endif; ?>
                    <?php if (!empty($lease['vin'])): ?><div class="pt-kv-row"><dt>VIN</dt><dd class="pt-mono" style="font-size:12.5px"><?= e($lease['vin']) ?></dd></div><?php endif; ?>
                    <?php if (!empty($lease['yard_location'])): ?><div class="pt-kv-row"><dt>Home yard</dt><dd><?= e($lease['yard_location']) ?></dd></div><?php endif; ?>
                </dl>
                <?php if (!empty($lease['samsara_vehicle_url'])): ?>
                    <a class="pt-btn pt-btn--soft pt-btn--block" style="margin-top:12px" href="<?= e($lease['samsara_vehicle_url']) ?>" target="_blank" rel="noopener"><?= pt_icon('map-pin') ?> Track live</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('clipboard-document-list') ?> Lease terms</h2></div>
            <div class="pt-card-body" style="padding-top:6px">
                <dl class="pt-kv">
                    <div class="pt-kv-row"><dt>Start</dt><dd><?= e(format_date($lease['start_date'])) ?></dd></div>
                    <div class="pt-kv-row"><dt><?= $lease['actual_return_date'] ? 'Returned' : 'End' ?></dt><dd><?= ($lease['actual_return_date'] ?: $lease['end_date']) ? e(format_date($lease['actual_return_date'] ?: $lease['end_date'])) : 'Open-ended' ?></dd></div>
                    <?php if (!empty($lease['minimum_end_date'])): ?><div class="pt-kv-row"><dt>Minimum term to</dt><dd><?= e(format_date($lease['minimum_end_date'])) ?></dd></div><?php endif; ?>
                    <?php if (!empty($lease['po_number'])): ?><div class="pt-kv-row"><dt>PO number</dt><dd><?= e($lease['po_number']) ?></dd></div><?php endif; ?>
                    <div class="pt-kv-row"><dt>Currency</dt><dd><?= e($cur) ?></dd></div>
                </dl>
            </div>
        </section>

        <?php if ($rates): ?>
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon('currency-dollar') ?> Rates</h2></div>
            <div class="pt-card-body" style="padding-top:6px">
                <dl class="pt-kv">
                    <?php foreach ($rates as [$k, $v]): ?><div class="pt-kv-row"><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div><?php endforeach; ?>
                </dl>
            </div>
        </section>
        <?php endif; ?>
    </aside>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
