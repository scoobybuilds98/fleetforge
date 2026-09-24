<?php
declare(strict_types=1);

/**
 * app/portal/leases/index.php
 *
 * Customer portal — Leases (S-PORTAL-REDESIGN).
 *
 * A card per lease (unit, type, contract, since, rate, days on rent) with
 * On rent / Upcoming / Returned / All tabs and instant search by contract or
 * unit number. Leases are server-rendered once as JSON and filtered in the
 * browser — a customer has tens of leases, not thousands (capped at 500).
 *
 * Replaces the ?ajax=1 list whose tab counts ignored the equipment join
 * (counts could disagree with rows) and whose search didn't escape LIKE
 * wildcards.
 *
 * Trap 8: leases filtered by portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$today = ff_today();

$rows = db_select(
    "SELECT l.id, l.contract_number, l.status, l.start_date, l.end_date, l.actual_return_date,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.hourly_rate, l.currency,
            eu.id AS unit_id, eu.unit_number, eb.label AS brand, et.name AS type_name, et.category
       FROM leases l
       JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
       LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
      WHERE l.customer_id = ? AND l.deleted_at IS NULL
      ORDER BY FIELD(l.status, 'active', 'pending', 'completed', 'cancelled'), l.start_date DESC
      LIMIT 500",
    [$cid]
);

$leases = [];
$counts = ['active' => 0, 'pending' => 0, 'past' => 0, 'all' => 0];
foreach ($rows as $r) {
    $cur = (string) ($r['currency'] ?? 'CAD');
    $rate = '';
    foreach ([['monthly_rate', '/mo'], ['weekly_rate', '/wk'], ['daily_rate', '/day'], ['hourly_rate', '/hr']] as [$col, $suffix]) {
        if (bccomp((string) ($r[$col] ?? '0'), '0', 2) > 0) { $rate = pt_money($r[$col], $cur) . $suffix; break; }
    }
    $end  = $r['actual_return_date'] ?: ($r['status'] === 'active' ? $today : ($r['end_date'] ?: $today));
    $days = max(1, pt_days_between((string) $r['start_date'], (string) $end) + 1);
    $group = match ($r['status']) { 'active' => 'active', 'pending' => 'pending', default => 'past' };
    $counts[$group]++;
    $counts['all']++;
    [$sl, $st] = match ($r['status']) {
        'active'    => ['On rent', 'success'],
        'pending'   => ['Starting soon', 'info'],
        'completed' => ['Returned', 'neutral'],
        'cancelled' => ['Cancelled', 'danger'],
        default     => [ucfirst((string) $r['status']), 'neutral'],
    };
    $leases[] = [
        'id'       => (int) $r['id'],
        'contract' => (string) $r['contract_number'],
        'unit'     => (string) $r['unit_number'],
        'unit_id'  => (int) $r['unit_id'],
        'type'     => trim(($r['brand'] ? $r['brand'] . ' ' : '') . ($r['type_name'] ?: ucfirst((string) $r['category']))),
        'group'    => $group,
        'status'   => $sl,
        'tone'     => $st,
        'since'    => format_date($r['start_date']),
        'ended'    => $r['actual_return_date'] ? format_date($r['actual_return_date']) : ($r['end_date'] ? format_date($r['end_date']) : ''),
        'ends_soon'=> $r['status'] === 'active' && $r['end_date'] && $r['end_date'] >= $today && $r['end_date'] <= ff_local_date_add($today, 21),
        'rate'     => $rate,
        'days'     => $days,
        'url'      => pt_url('leases/view?id=' . (int) $r['id']),
        'report'   => pt_url('requests/create?type=damage_report&lease_id=' . (int) $r['id'] . '&equipment_id=' . (int) $r['unit_id']),
    ];
}

$tab = (string) ($_GET['tab'] ?? ($counts['active'] > 0 ? 'active' : 'all'));
if (!in_array($tab, ['active', 'pending', 'past', 'all'], true)) $tab = 'all';

$pageTitle = 'Leases';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Fleet',
    'title'   => 'Leases',
    'sub'     => 'Every rental on your account. Open one to see its invoices, mileage, documents and rates — or to extend or return it.',
    'actions' => '<a class="pt-btn pt-btn--primary" href="' . e(pt_url('requests/create?type=new_lease_inquiry')) . '">' . pt_icon('plus') . ' Rent more equipment</a>',
]);
?>

<div x-data="{
        tab: <?= e(json_encode($tab)) ?>,
        q: '',
        rows: <?= e(json_encode($leases)) ?>,
        get list() {
            const t = this.q.trim().toLowerCase();
            return this.rows.filter(r => (this.tab === 'all' || r.group === this.tab)
                && (!t || r.contract.toLowerCase().includes(t) || r.unit.toLowerCase().includes(t) || r.type.toLowerCase().includes(t)));
        }
    }">
    <div class="pt-card" style="margin-bottom:var(--pt-gap)">
        <div class="pt-toolbar" style="border-bottom:0">
            <div class="pt-tabs" role="tablist" aria-label="Lease status">
                <?php foreach (['active' => 'On rent', 'pending' => 'Upcoming', 'past' => 'Returned', 'all' => 'All'] as $k => $label):
                    if ($k === 'pending' && $counts['pending'] === 0) continue; ?>
                    <button type="button" class="pt-tab" role="tab" :class="{ 'is-active': tab === '<?= $k ?>' }" :aria-selected="tab === '<?= $k ?>'" @click="tab = '<?= $k ?>'">
                        <?= e($label) ?> <span class="pt-tab-count"><?= (int) $counts[$k] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="pt-toolbar-spacer"></div>
            <label class="pt-search-field">
                <?= pt_icon('magnifying-glass') ?>
                <span class="pt-sr">Search leases</span>
                <input type="search" class="pt-input" placeholder="Lease, unit or type" x-model="q">
            </label>
        </div>
    </div>

    <div class="pt-units">
        <template x-for="l in list" :key="l.id">
            <article class="pt-unit">
                <a :href="l.url" class="pt-unit-top" style="text-decoration:none;color:inherit">
                    <span class="pt-unit-art"><?= pt_icon('truck') ?></span>
                    <span style="min-width:0">
                        <span class="pt-unit-id" style="display:block" x-text="l.unit"></span>
                        <span class="pt-unit-type" style="display:block" x-text="l.type"></span>
                    </span>
                    <span class="pt-unit-status"><span class="pt-pill" :class="'pt-pill--' + l.tone" x-text="l.status"></span></span>
                </a>
                <div class="pt-unit-facts">
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">Lease</div><div class="pt-unit-fact-v" x-text="l.contract"></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k" x-text="l.group === 'past' ? 'Returned' : 'Since'"></div><div class="pt-unit-fact-v" x-text="l.group === 'past' ? (l.ended || '—') : l.since"></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k">Rate</div><div class="pt-unit-fact-v" x-text="l.rate || '—'"></div></div>
                    <div class="pt-unit-fact"><div class="pt-unit-fact-k" x-text="l.group === 'active' ? 'Days on rent' : 'Days'"></div><div class="pt-unit-fact-v" x-text="l.days.toLocaleString()"></div></div>
                </div>
                <div class="pt-note pt-note--info" x-show="l.ends_soon" style="margin:10px 12px 0;padding:8px 12px;font-size:12.5px"><span x-text="'Ends ' + l.ended + ' — need it longer?'"></span></div>
                <div class="pt-unit-foot">
                    <a class="pt-btn pt-btn--secondary pt-btn--sm" :href="l.url">View lease</a>
                    <a class="pt-btn pt-btn--ghost pt-btn--sm" :href="l.report" x-show="l.group === 'active'"><?= pt_icon('wrench-screwdriver') ?> Report a problem</a>
                </div>
            </article>
        </template>
    </div>

    <div class="pt-card" x-show="list.length === 0" x-cloak>
        <div x-show="q.trim() !== ''"><?= pt_empty('magnifying-glass', 'No leases match', 'Try a different contract or unit number.') ?></div>
        <div x-show="q.trim() === ''"><?= pt_empty('clipboard-document-list', 'Nothing here yet', 'Leases in this view will appear here.', '<a class="pt-btn pt-btn--soft pt-btn--sm" href="' . e(pt_url('requests/create?type=new_lease_inquiry')) . '">Rent equipment</a>') ?></div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
