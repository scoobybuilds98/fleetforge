<?php
declare(strict_types=1);

/**
 * app/admin/rates/show.php
 *
 * One rate card (S-RATES-MODULE rebuild of S-RECORD-REDESIGN's page).
 *
 * Layout:
 *   header   card name + status + main-list badge; who it prices, its window,
 *            what it replaced. Actions: Change prices (primary — one step:
 *            this card ends, a new card carries the new prices from a date),
 *            Edit; More: duplicate for another customer, rate sheet PDF,
 *            price check, end these prices, main-list flag, delete
 *   tiles    equipment lines · applies to · in force until · on rent (leases
 *            this card prices today, and how many carry different prices)
 *   main     Prices   ONE table for every line — view mode shows each price
 *                     with how it compares to the standard price; Edit turns
 *                     the same table into inputs (add / remove lines, adjust
 *                     all by %, fill blanks from the standard) with one Save
 *                     for prices and details together (sticky save bar)
 *            Details  name, customer / everyone, window, notes, main list
 *            Leases on these prices   resolved, with the fields that differ
 *            Price history            each line's price over time across the
 *                     customer's cards + the change log with per-line diffs
 *   rail     needs attention · customer · how a new lease picks its price ·
 *            record
 *
 * Leases copy their prices when created and keep no rate_card_id, so editing
 * a card never changes a lease on rent (the "Leases on these prices" card
 * shows which leases differ; the lease's own Amend rate moves one).
 *
 * D5: rate_cards has deleted_at — 404 if the card is soft-deleted.
 * D16: money is shown here, computed server-side (bcmath).
 * D19: updated_at is sent on every save (optimistic locking is off —
 *      last write wins — but the token is kept for when it is turned on).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           api/v1/rate_cards/{update,delete,revise,leases,history,sheet_pdf},
 *           includes/partials/record-picker.php, lib/RateCards/*,
 *           public/assets/{css/rates.css,js/rates.js}
 * @decisions D5/D7/D16/D19/D30/D32
 * @session  S019, S-RATES-REDESIGN, S-RECORD-REDESIGN, S-RATES-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;
use FleetForge\Sop\SopIcons;
use FleetForge\Ui\ModuleHero;
use FleetForge\Ui\RecordUi;

require_auth();
require_permission('rates', 'view');

// ── Resolve rate card ────────────────────────────────────────────────────────
$cardId = clean_int($_GET['id'] ?? null);
if (!$cardId) { http_response_code(400); die('Missing id parameter.'); }

$card = db_row(
    "SELECT rc.*, c.company_name AS customer_name, u.name AS created_by_name
     FROM rate_cards rc
     LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u ON u.id = rc.created_by AND u.deleted_at IS NULL
     WHERE rc.id = ? AND rc.deleted_at IS NULL",
    [$cardId]
);
if (!$card) { http_response_code(404); die('Rate card not found.'); }

$canEdit    = can('rates', 'edit');
$canCreate  = can('rates', 'create');
$canDelete  = can('rates', 'delete');
$canRevise  = $canEdit && $canCreate;
$canLeases  = can('leases', 'view');
$canCust    = can('customers', 'view');
$isCustomer = $card['customer_id'] !== null;

$today  = ff_today();
$status = RateCardItems::status($card, $today);
$items  = RateInsights::itemsByCard([$cardId])[$cardId] ?? [];
$lines  = RateInsights::decorateLines($items, $today);
$daysTo = static fn (string $d): int => (int) round((strtotime($d) - strtotime($today)) / 86400);

// Every line a card can carry: a whole category, or one equipment type.
$lineOptions = [];
$standards   = [];
$slugs = array_unique(array_merge(
    array_column(RateInsights::templates(), 'category'),
    array_column($items, 'equipment_type')
));
sort($slugs);
foreach ($slugs as $slug) {
    $lbl = RateCardItems::label((string) $slug);
    $lineOptions[] = ['value' => 'c:' . $slug, 'label' => $lbl, 'scope' => 'every ' . $lbl . ' type', 'category' => $slug, 'template_id' => null, 'group' => 'Whole category'];
    $standards['c:' . $slug] = RateInsights::lineStandard(['equipment_type' => $slug, 'equipment_template_id' => null], $today);
}
foreach (RateInsights::templates() as $tid => $t) {
    $lineOptions[] = ['value' => 't:' . $tid, 'label' => $t['name'], 'scope' => RateCardItems::label((string) $t['category']) . ' · this type only',
                      'category' => $t['category'], 'template_id' => $tid, 'group' => 'One equipment type'];
    $standards['t:' . $tid] = RateInsights::lineStandard(['equipment_type' => $t['category'], 'equipment_template_id' => $tid], $today);
}

// ── Facts for the header / rail ──────────────────────────────────────────────
$custActiveLeases = null;
$otherCustCards   = [];
if ($isCustomer) {
    $custActiveLeases = (int) (db_row(
        "SELECT COUNT(*) AS c FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
        [(int) $card['customer_id']]
    )['c'] ?? 0);
    // Other cards for the same customer whose window overlaps this one.
    $otherCustCards = db_select(
        "SELECT id, name FROM rate_cards
          WHERE customer_id = ? AND id <> ? AND deleted_at IS NULL
            AND effective_from <= COALESCE(?, '9999-12-31')
            AND COALESCE(effective_to, '9999-12-31') >= ?
          ORDER BY is_default DESC, effective_from DESC LIMIT 5",
        [(int) $card['customer_id'], $cardId, $card['effective_to'], $card['effective_from']]
    );
}

// The card this one replaced / the card that continues it (price changes).
$replaces = null;
$replacedBy = null;
foreach (db_select(
    "SELECT action, new_values FROM audit_log
      WHERE entity_type = 'rate_card' AND entity_id = ? AND module = 'rates'
      ORDER BY id DESC LIMIT 50",
    [$cardId]
) as $a) {
    $nv = json_decode((string) $a['new_values'], true) ?: [];
    if ($replaces === null && !empty($nv['replaces_card_id'])) {
        $replaces = db_row("SELECT id, name FROM rate_cards WHERE id = ? AND deleted_at IS NULL", [(int) $nv['replaces_card_id']]) ?: null;
    }
    if ($replacedBy === null && !empty($nv['replaced_by'])) {
        $replacedBy = db_row("SELECT id, name, effective_from FROM rate_cards WHERE id = ? AND deleted_at IS NULL", [(int) $nv['replaced_by']]) ?: null;
    }
}

$issueCount = array_sum(array_map(static fn ($l) => count($l['issues']), $lines));
$statusText = ['active' => 'In force', 'ending' => 'Ending soon', 'upcoming' => 'Starts later', 'expired' => 'Ended'][$status];

// Raw: includes/header.php escapes it.
$pageTitle      = (string) $card['name'];
$helpModuleSlug = 'rates';
require_once FF_ROOT . '/includes/header.php';

// ── Header ───────────────────────────────────────────────────────────────────
$heroTitle = e($card['name'])
    . ' <span class="rt-status rt-status--' . $status . '" style="vertical-align:middle;font-size:12px;">' . e($statusText) . '</span>'
    . ($card['is_default'] ? ' <span class="badge badge-info" style="vertical-align:middle;">Main price list</span>' : '');
$heroFacts = [];
if ($isCustomer) {
    $heroFacts[] = SopIcons::svg('user-group') . ($canCust
        ? '<a href="' . e(base_url('customers/show')) . '?id=' . (int) $card['customer_id'] . '">' . e($card['customer_name'] ?? 'Archived customer') . '</a>'
        : e($card['customer_name'] ?? 'Archived customer'));
} else {
    $heroFacts[] = SopIcons::svg('users') . 'Everyone without their own price';
}
$heroFacts[] = SopIcons::svg('calendar-days') . e(format_date($card['effective_from'])) . ' → ' . ($card['effective_to'] ? e(format_date($card['effective_to'])) : 'open-ended');
if ($replaces) {
    $heroFacts[] = SopIcons::svg('arrow-uturn-left') . 'Replaces <a href="' . e(base_url('rates/show')) . '?id=' . (int) $replaces['id'] . '">' . e($replaces['name']) . '</a>';
}
if ($replacedBy) {
    $heroFacts[] = SopIcons::svg('arrow-right') . 'Continues on <a href="' . e(base_url('rates/show')) . '?id=' . (int) $replacedBy['id'] . '">' . e($replacedBy['name']) . '</a>';
}
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/rates.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
<script src="<?= asset_url('assets/js/rates.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <?php if ($canCreate): ?>
    <a href="<?= base_url('rates/create') ?>?from_card=<?= $cardId ?>"><?= SopIcons::svg('document-duplicate') ?> Duplicate…</a>
    <?php endif; ?>
    <?php if ($isCustomer): ?>
    <a href="<?= base_url('api/v1/rate_cards/sheet_pdf') ?>?customer_id=<?= (int) $card['customer_id'] ?>" target="_blank" rel="noopener"><?= SopIcons::svg('document-arrow-down') ?> Customer rate sheet (PDF)</a>
    <a href="<?= base_url('rates') ?>?check_customer=<?= (int) $card['customer_id'] ?>#check"><?= SopIcons::svg('magnifying-glass') ?> Price check for this customer</a>
    <?php else: ?>
    <a href="<?= base_url('api/v1/rate_cards/sheet_pdf') ?>?standard=1" target="_blank" rel="noopener"><?= SopIcons::svg('document-arrow-down') ?> Standard price sheet (PDF)</a>
    <?php endif; ?>
    <?php if ($canEdit && $status !== 'expired'): ?>
    <button type="button" @click="openEnd()"><?= SopIcons::svg('clock') ?> End these prices…</button>
    <?php endif; ?>
    <?php if ($canEdit && !$isCustomer): ?>
    <button type="button" @click="toggleDefault()" x-text="form.is_default ? 'Stop being the main price list' : 'Make this the main price list'"></button>
    <?php endif; ?>
    <hr>
    <a href="<?= base_url('rates') ?>#cards"><?= SopIcons::svg('list-bullet') ?> All rate cards</a>
    <?php if ($isCustomer && $canCust): ?>
    <a href="<?= base_url('customers/show') ?>?id=<?= (int) $card['customer_id'] ?>#rates"><?= SopIcons::svg('user-group') ?> Customer profile</a>
    <?php endif; ?>
    <?php if ($canDelete && !$card['is_default']): ?>
    <button type="button" class="rec-danger" @click="deleteModal.open = true"><?= SopIcons::svg('trash') ?> Delete this rate card</button>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?= help_button('rates') ?>
    <?php if ($canRevise && $status !== 'upcoming'): ?>
    <button type="button" class="btn btn-primary btn-sm" @click="openRevise()" x-show="!editMode"><?= $status === 'expired' ? 'Renew prices' : 'Change prices' ?></button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <button type="button" class="btn btn-secondary btn-sm" x-show="!editMode" @click="startEdit()">Edit</button>
    <button type="button" class="btn btn-secondary btn-sm" x-show="editMode" x-cloak @click="cancelEdit()">Cancel</button>
    <button type="button" class="btn btn-primary btn-sm" x-show="editMode" x-cloak :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save changes'"></button>
    <?php endif; ?>
    <?= RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- The component opens ABOVE the header so the header buttons reach it. -->
<div x-data="FF_RateCard()">
<?= ModuleHero::render([
    'entity'     => true,
    'accent'     => $status === 'expired' ? 'danger' : ($status === 'ending' ? 'warning' : 'primary'),
    'icon'       => 'currency-dollar',
    'avatar'     => $isCustomer ? ModuleHero::initials((string) ($card['customer_name'] ?? '')) : '',
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Rates', base_url('rates')], [(string) $card['name'], null]],
    'eyebrow'    => $isCustomer ? 'Customer rate card' : 'Standard price list',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

    <!-- KEY NUMBERS -->
    <div class="stat-grid stat-grid--4 ff-stats">
        <a class="stat-card stat-card--blue" href="#prices">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-tag"/></svg></span>
            <div class="stat-label">Prices for</div>
            <div class="stat-value font-mono" x-text="lines.length"><?= count($lines) ?></div>
            <div class="stat-delta" x-text="lines.length === 1 ? 'equipment line' : 'equipment lines'"><?= count($lines) === 1 ? 'equipment line' : 'equipment lines' ?></div>
        </a>
        <div class="stat-card stat-card--purple">
            <span class="stat-icon stat-icon--purple"><svg><use href="#icon-<?= $isCustomer ? 'key' : 'building' ?>"/></svg></span>
            <div class="stat-label">Applies to</div>
            <div class="stat-value"><?= $isCustomer ? 'One customer' : 'Everyone' ?></div>
            <div class="stat-delta"><?= $isCustomer ? e(mb_strimwidth((string) ($card['customer_name'] ?? 'Archived customer'), 0, 24, '…')) : 'without their own card' ?></div>
        </div>
        <?php
        if ($status === 'expired') {
            $effLabel = 'Ended'; $effVal = format_date($card['effective_to']); $effDelta = (-$daysTo($card['effective_to'])) . 'd ago'; $effTone = 'red';
        } elseif ($status === 'upcoming') {
            $effLabel = 'Starts'; $effVal = format_date($card['effective_from']); $effDelta = 'in ' . $daysTo($card['effective_from']) . 'd'; $effTone = 'amber';
        } elseif ($card['effective_to']) {
            $effLabel = 'In force until'; $effVal = format_date($card['effective_to']); $effDelta = $daysTo($card['effective_to']) . 'd left'; $effTone = $status === 'ending' ? 'amber' : 'green';
        } else {
            $effLabel = 'In force until'; $effVal = 'Open-ended'; $effDelta = 'no end date'; $effTone = 'green';
        }
        ?>
        <div class="stat-card stat-card--<?= $effTone ?>">
            <span class="stat-icon stat-icon--<?= $effTone ?>"><svg><use href="#icon-<?= $effTone === 'red' ? 'exclamation-triangle' : 'clock' ?>"/></svg></span>
            <div class="stat-label"><?= e($effLabel) ?></div>
            <div class="stat-value stat-value--date font-mono"><?= e($effVal) ?></div>
            <div class="stat-delta"><?= e($effDelta) ?></div>
        </div>
        <?php if ($canLeases): ?>
        <a class="stat-card stat-card--<?= 'teal' ?>" href="#leases">
            <span class="stat-icon stat-icon--teal"><svg><use href="#icon-truck"/></svg></span>
            <div class="stat-label">On rent at these prices</div>
            <div class="stat-value font-mono" x-text="leases.loaded ? leases.total : '…'">…</div>
            <div class="stat-delta" x-text="leases.loaded ? (leases.differ ? leases.differ + ' on different prices' : 'all match') : 'leases'"></div>
        </a>
        <?php else: ?>
        <div class="stat-card stat-card--slate">
            <span class="stat-icon stat-icon--slate"><svg><use href="#icon-pencil-square"/></svg></span>
            <div class="stat-label">Last changed</div>
            <div class="stat-value stat-value--date font-mono"><?= e(format_datetime($card['updated_at'], 'M j, Y')) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="alert alert-danger" x-show="error" x-cloak style="margin-bottom:14px;"><span x-text="error"></span></div>

<div class="rec-layout" :class="{ 'rt-wide': editMode }">
<div class="rec-main">

    <!-- ── Prices ─────────────────────────────────────────────────── -->
    <div class="card" id="prices">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <h3 class="card-title">Prices</h3>
            <span class="rt-faint text-sm" x-show="!editMode"><?= $isCustomer ? 'Compared with the standard price each line would otherwise get' : 'What a customer without their own card pays' ?></span>
            <span style="flex:1"></span>
            <?php if ($canEdit): ?>
            <template x-if="editMode">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <div class="rt-money rt-money--pct" style="width:92px;min-width:0;"><span>%</span><input type="number" step="0.1" class="form-control form-control-sm" x-model="adjustPct" placeholder="±%" aria-label="Adjust rent prices by percent"></div>
                    <select class="form-select form-control-sm" style="width:auto;" x-model="adjustRound" aria-label="Round to">
                        <option value="0.01">to the cent</option>
                        <option value="1">to $1</option>
                        <option value="5">to $5</option>
                    </select>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!adjustPct" @click="applyAdjust()">Adjust rent prices</button>
                </div>
            </template>
            <button type="button" class="btn btn-secondary btn-sm" x-show="!editMode" @click="startEdit()">Edit prices</button>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
            <template x-if="lines.length === 0 && !editMode">
                <div class="empty-state" style="padding:36px 16px;">
                    <p class="empty-state-title">No prices yet</p>
                    <p class="empty-state-text">This card prices nothing until it has at least one equipment line.</p>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-primary btn-sm" @click="startEdit()">Add prices</button><?php endif; ?>
                </div>
            </template>

            <!-- View mode -->
            <template x-if="!editMode && lines.length > 0">
                <div class="rt-grid-wrap">
                    <table class="rt-grid" aria-label="Prices">
                        <thead>
                            <tr>
                                <th style="padding-left:18px;">Equipment</th>
                                <th class="num">Daily</th>
                                <th class="num">Weekly</th>
                                <th class="num">Monthly</th>
                                <th class="num">Distance</th>
                                <th class="num" x-show="hasHourly()">Hourly</th>
                                <th class="num">GPS / day</th>
                                <th class="num" style="padding-right:18px;">Min. days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="l in lines" :key="l._k">
                                <tr>
                                    <td class="rt-eq" style="padding-left:18px;min-width:180px;">
                                        <b x-text="l.label"></b>
                                        <small x-text="l.scope + (l.currency !== 'CAD' ? ' · ' + l.currency : '')"></small>
                                        <template x-for="iss in (issues[l.key] || [])" :key="iss"><span class="rt-issue" x-text="iss"></span></template>
                                    </td>
                                    <template x-for="f in [['daily_rate','daily'],['weekly_rate','weekly'],['monthly_rate','monthly']]" :key="f[0]">
                                        <td class="num">
                                            <span class="rt-num" x-text="FF_Rates.money(l[f[0]])"></span>
                                            <?php if ($isCustomer): ?>
                                            <template x-if="stdFor(l) && FF_Rates.vs(l[f[0]], stdFor(l)[f[1]])">
                                                <span class="rt-vs rt-sub" :class="FF_Rates.vs(l[f[0]], stdFor(l)[f[1]]).cls" x-text="FF_Rates.vs(l[f[0]], stdFor(l)[f[1]]).text"></span>
                                            </template>
                                            <template x-if="stdFor(l) && stdFor(l).range && stdFor(l).range[f[1]] && l[f[0]]">
                                                <span class="rt-sub" x-text="'standard ' + FF_Rates.short(stdFor(l).range[f[1]][0]) + '–' + FF_Rates.short(stdFor(l).range[f[1]][1])"></span>
                                            </template>
                                            <?php endif; ?>
                                        </td>
                                    </template>
                                    <td class="num rt-nowrap">
                                        <span class="rt-num" x-text="FF_Rates.money(l.mileage_rate, 4)"></span>
                                        <span class="rt-sub" x-show="Number(l.mileage_rate) > 0" x-text="'per ' + (l.mileage_unit === 'miles' ? 'mile' : 'km')"></span>
                                    </td>
                                    <td class="num" x-show="hasHourly()"><span class="rt-num" x-text="FF_Rates.money(l.hourly_rate, 4)"></span><span class="rt-sub" x-show="Number(l.hourly_rate) > 0">per engine hr</span></td>
                                    <td class="num"><span class="rt-num" x-text="FF_Rates.money(l.gps_price)"></span></td>
                                    <td class="num" style="padding-right:18px;"><span class="rt-num" x-text="l.minimum_days !== '' ? l.minimum_days : '—'"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Edit mode -->
            <template x-if="editMode">
                <div>
                    <?php $rtEditorLeaseHelp = false; $rtEditorVs = $isCustomer; require FF_ROOT . '/includes/partials/rates-line-editor.php'; ?>
                    <div class="rt-lines-foot">
                        <select class="form-select form-control-sm" style="max-width:320px;" x-model="newLineKey" aria-label="Add equipment">
                            <option value="">+ Add equipment…</option>
                            <template x-for="grp in ['Whole category', 'One equipment type']" :key="grp">
                                <optgroup :label="grp">
                                    <template x-for="o in availableOptions().filter(o => o.group === grp)" :key="o.value">
                                        <option :value="o.value" x-text="o.label"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="!newLineKey" @click="addStandardLine()">Add line</button>
                        <span class="rt-faint text-sm">A line for one equipment type wins over a whole-category line.</span>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- ── Details ────────────────────────────────────────────────── -->
    <div class="card" id="details">
        <div class="card-header"><h3 class="card-title">Details</h3></div>
        <div class="card-body">
            <template x-if="!editMode">
                <dl class="rec-dl">
                    <dt>Name</dt><dd x-text="form.name"></dd>
                    <dt>For</dt>
                    <dd>
                        <template x-if="form.customer_id"><a class="link" :href="'<?= e(base_url('customers/show')) ?>?id=' + form.customer_id" x-text="form.customer_name || 'Archived customer'"></a></template>
                        <template x-if="!form.customer_id"><span>Everyone without their own price (standard price list)</span></template>
                    </dd>
                    <dt>In force</dt><dd x-text="FF_Rates.window(form.effective_from, form.effective_to)"></dd>
                    <template x-if="!form.customer_id"><dt>Main price list</dt></template>
                    <template x-if="!form.customer_id"><dd x-text="form.is_default ? 'Yes — wins when two general cards price the same equipment' : 'No'"></dd></template>
                    <dt>Notes</dt><dd x-text="form.description || '—'"></dd>
                </dl>
            </template>
            <template x-if="editMode">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="rc-name">Name</label>
                        <input id="rc-name" type="text" class="form-control" maxlength="255" x-model="form.name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">For</label>
                        <?php
                        $pickerName     = 'rcCustomer';
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/customers/index.php',
                            'searchParam' => 'search',
                            'resultKey'   => 'items',
                            'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                            'placeholder' => 'Everyone (standard price list)…',
                            'initialId'   => $isCustomer ? (int) $card['customer_id'] : null,
                            'initialLabel'=> $card['customer_name'] ?? '',
                        ];
                        $pickerOnPicked  = 'form.customer_id = $event.detail.id; form.customer_name = $event.detail.label';
                        $pickerOnCleared = 'form.customer_id = null; form.customer_name = null';
                        $pickerError     = 'false';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="form-hint">Clear it to make this a standard price list for everyone.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rc-from">Starts</label>
                        <input id="rc-from" type="date" class="form-control" x-model="form.effective_from">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rc-to">Ends</label>
                        <input id="rc-to" type="date" class="form-control" x-model="form.effective_to" :min="form.effective_from" :disabled="openEnded">
                        <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;cursor:pointer;">
                            <input type="checkbox" class="form-check-input" x-model="openEnded" @change="if (openEnded) form.effective_to = ''"> Open-ended
                        </label>
                    </div>
                    <template x-if="!form.customer_id">
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:26px;">
                                <input type="checkbox" class="form-check-input" x-model="form.is_default"> Main price list
                            </label>
                            <div class="form-hint">When two general cards price the same equipment, the main list wins. Only one card can be the main list.</div>
                        </div>
                    </template>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="rc-desc">Notes</label>
                        <input id="rc-desc" type="text" class="form-control" maxlength="1000" x-model="form.description" placeholder="e.g. Signed deal, reviewed every January">
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Unsaved changes -->
    <div class="rt-savebar" x-show="editMode" x-cloak>
        <span>
            <span x-text="dirty() ? 'Unsaved changes' : 'Editing — nothing changed yet'"></span>
            <small x-text="problems().length ? problems()[0] : 'Leases already on rent keep their prices.'"></small>
        </span>
        <button type="button" class="btn btn-ghost btn-sm" @click="cancelEdit()">Discard</button>
        <button type="button" class="btn btn-primary btn-sm" :disabled="saving || !dirty()" @click="save()" x-text="saving ? 'Saving…' : 'Save changes'"></button>
    </div>

    <?php if ($canLeases): ?>
    <!-- ── Leases on these prices ─────────────────────────────────── -->
    <div class="card" id="leases">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;">
            <h3 class="card-title">Leases on these prices</h3>
            <span class="rt-faint text-sm" x-show="leases.loaded" x-text="leases.total + ' on rent' + (leases.differ ? ' · ' + leases.differ + ' on different prices' : '')"></span>
        </div>
        <div class="card-body" style="padding:0;">
            <template x-if="!leases.loaded">
                <div style="padding:16px;"><div class="skeleton skeleton-row"></div></div>
            </template>
            <template x-if="leases.loaded && leases.total === 0">
                <p class="rt-muted text-sm" style="margin:0;padding:16px 18px;">No lease on rent gets its prices from this card today.</p>
            </template>
            <template x-if="leases.loaded && leases.total > 0">
                <div class="rt-grid-wrap">
                    <table class="rt-grid">
                        <thead>
                            <tr>
                                <th style="padding-left:18px;">Lease</th>
                                <?php if (!$isCustomer): ?><th>Customer</th><?php endif; ?>
                                <th>Unit</th>
                                <th>Since</th>
                                <th class="num">Daily</th>
                                <th class="num">Weekly</th>
                                <th class="num">Monthly</th>
                                <th style="padding-right:18px;">Compared with this card</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="le in leases.rows" :key="le.id">
                                <tr>
                                    <td style="padding-left:18px;"><a class="link rt-num" :href="'<?= e(base_url('leases/show')) ?>?id=' + le.id" x-text="le.contract_number"></a></td>
                                    <?php if (!$isCustomer): ?><td x-text="le.customer_name"></td><?php endif; ?>
                                    <td><span x-text="le.unit_number"></span><span class="rt-sub" x-text="le.equipment"></span></td>
                                    <td class="rt-nowrap" x-text="FF_Rates.date(le.start_date)"></td>
                                    <td class="num rt-num" x-text="FF_Rates.money(le.prices.daily_rate)"></td>
                                    <td class="num rt-num" x-text="FF_Rates.money(le.prices.weekly_rate)"></td>
                                    <td class="num rt-num" x-text="FF_Rates.money(le.prices.monthly_rate)"></td>
                                    <td style="padding-right:18px;">
                                        <span class="rt-status rt-status--active" x-show="!le.diffs.length">Same prices</span>
                                        <template x-if="le.diffs.length">
                                            <span>
                                                <span class="rt-status rt-status--ending">Different</span>
                                                <template x-for="d in le.diffs" :key="d.field">
                                                    <span class="rt-sub" x-text="FF_Rates.FIELD_LABELS[d.field] + ': lease ' + FF_Rates.money(d.lease, FF_Rates.FIELD_DP[d.field] || 2) + ' · card ' + FF_Rates.money(d.card, FF_Rates.FIELD_DP[d.field] || 2)"></span>
                                                </template>
                                            </span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="rt-faint text-sm" style="margin:0;padding:10px 18px 14px;">A lease keeps the prices it was created with. To move one onto this card's prices, use <b>Amend rate</b> on the lease.</p>
                </div>
            </template>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Price history ──────────────────────────────────────────── -->
    <div class="card" id="history">
        <div class="card-header"><h3 class="card-title">Price history</h3></div>
        <div class="card-body">
            <template x-if="!history.loaded"><div class="skeleton skeleton-row"></div></template>
            <template x-if="history.loaded">
                <div>
                    <template x-for="tl in history.timeline" :key="tl.key">
                        <div style="margin-bottom:14px;">
                            <div class="rt-group-label" x-text="tl.label + ' — price over time' + (form.customer_id ? ' for this customer' : ' on standard price lists')"></div>
                            <div class="rt-periods">
                                <template x-for="p in tl.periods" :key="p.card_id">
                                    <div class="rt-period" :class="{ 'is-this': p.is_this }">
                                        <span>
                                            <template x-if="!p.is_this"><a class="link" :href="'<?= e(base_url('rates/show')) ?>?id=' + p.card_id" x-text="p.card_name"></a></template>
                                            <template x-if="p.is_this"><b x-text="'This card'"></b></template>
                                            <span class="rt-sub rt-faint" x-text="FF_Rates.window(p.effective_from, p.effective_to)"></span>
                                        </span>
                                        <span class="num" x-text="FF_Rates.money(p.prices.daily_rate) + '/d'"></span>
                                        <span class="num" x-text="FF_Rates.money(p.prices.weekly_rate) + '/w'"></span>
                                        <span class="num" x-text="FF_Rates.money(p.prices.monthly_rate) + '/mo'"></span>
                                        <span class="rt-status" :class="'rt-status--' + p.status" x-text="FF_Rates.statusLabel(p.status)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                    <div class="rt-group-label" style="margin-top:6px;">Changes to this card</div>
                    <p class="rt-muted text-sm" x-show="!history.events.length">No changes recorded.</p>
                    <ul class="rt-timeline">
                        <template x-for="ev in history.events" :key="ev.id">
                            <li :class="ev.action === 'create' ? 'rt-tone-success' : (ev.replaced_by ? 'rt-tone-warning' : 'rt-tone-primary')">
                                <div class="rt-tl-head"><b x-text="eventTitle(ev)"></b></div>
                                <div class="rt-tl-meta" x-text="(ev.user_name || 'System') + ' · ' + FF_Rates.date(ev.at) "></div>
                                <div class="rt-tl-body">
                                    <div x-show="ev.notes" x-text="ev.notes"></div>
                                    <ul x-show="ev.fields.length || ev.lines.length">
                                        <template x-for="f in ev.fields" :key="f.field">
                                            <li><span x-text="f.label + ': '"></span><span class="rt-tl-change" x-text="fieldText(f.field, f.from) + ' → ' + fieldText(f.field, f.to)"></span></li>
                                        </template>
                                        <template x-for="ln in ev.lines" :key="ln.label + ln.change">
                                            <li>
                                                <b x-text="ln.label"></b>
                                                <span x-show="ln.change === 'added'" x-text="' added' + (ln.prices ? ' at ' + FF_Rates.chipPrices(ln.prices) : '')"></span>
                                                <span x-show="ln.change === 'removed'"> removed</span>
                                                <template x-for="fc in ln.fields" :key="fc.field">
                                                    <span class="rt-tl-change" x-text="' · ' + FF_Rates.FIELD_LABELS[fc.field] + ' ' + fieldText(fc.field, fc.from) + ' → ' + fieldText(fc.field, fc.to)"></span>
                                                </template>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </div>

</div><!-- /rec-main -->
<?php
// ── RAIL ─────────────────────────────────────────────────────────────────────
$rail   = [];
$alerts = [];
if ($status === 'expired') {
    $alerts[] = ['danger', 'Ended ' . e(format_date($card['effective_to'])) . ' — new leases no longer get these prices.'
        . ($canRevise && !$replacedBy ? ' Use <b>Renew prices</b> to carry them forward.' : '')];
} elseif ($status === 'upcoming') {
    $alerts[] = ['info', 'Starts ' . e(format_date($card['effective_from'])) . ' — until then new leases get the prices in force today.'];
} elseif ($status === 'ending') {
    $alerts[] = ['warning', 'Ends in ' . $daysTo($card['effective_to']) . ' day' . ($daysTo($card['effective_to']) === 1 ? '' : 's') . ' (' . e(format_date($card['effective_to'])) . ').'
        . ($canRevise ? ' Use <b>Change prices</b> to set what comes next.' : '')];
}
if ($issueCount > 0) {
    $alerts[] = ['danger', $issueCount . ' price line' . ($issueCount === 1 ? '' : 's') . ' would pre-fill a lease wrongly — see the red notes under Prices.'];
}
if ($isCustomer && $card['customer_name'] === null) {
    $alerts[] = ['warning', 'The customer on this card is archived — it prices nothing.'];
}
if ($otherCustCards) {
    $links = array_map(static fn ($c) => '<a href="' . e(base_url('rates/show')) . '?id=' . (int) $c['id'] . '">' . e($c['name']) . '</a>', $otherCustCards);
    $alerts[] = ['info', 'Also in force for this customer: ' . implode(', ', $links) . '. The same equipment cannot be on both.'];
}
$attention = RecordUi::alerts($alerts, '');
if ($alerts === []) {
    $attention = '<ul class="rec-alerts"></ul>';
}
$attention = str_replace('<ul class="rec-alerts">', '<ul class="rec-alerts"><li class="is-warning" x-show="lines.length === 0" x-cloak><span>No prices yet — this card prices nothing.</span></li>', $attention)
    . ($alerts === [] ? '<p class="rec-alerts-ok" x-show="lines.length > 0">All clear — nothing needs attention.</p>' : '');
$rail[] = RecordUi::card('Needs attention', $attention, ['icon' => 'exclamation-triangle']);

if ($isCustomer) {
    $rail[] = RecordUi::card('Customer',
        RecordUi::entity((string) ($card['customer_name'] ?? 'Archived customer'), $canCust ? base_url('customers/show') . '?id=' . (int) $card['customer_id'] : '',
            $custActiveLeases . ' lease' . ($custActiveLeases === 1 ? '' : 's') . ' on rent',
            ModuleHero::initials((string) ($card['customer_name'] ?? '')))
        . '<div style="margin-top:10px;">' . RecordUi::links(array_values(array_filter([
            $canCust ? ['All their prices', base_url('customers/show') . '?id=' . (int) $card['customer_id'] . '#rates', 'receipt-percent'] : null,
            ['Rate sheet (PDF)', base_url('api/v1/rate_cards/sheet_pdf') . '?customer_id=' . (int) $card['customer_id'], 'document-arrow-down'],
            $canCreate ? ['New card for them', base_url('rates/create') . '?customer_id=' . (int) $card['customer_id'], 'plus'] : null,
        ]))) . '</div>',
        ['icon' => 'user-group', 'class' => 'rec-card--accent']);
}

$rail[] = RecordUi::card('How a new lease picks its price', RecordUi::kv([
    ['1st', $isCustomer ? '<b>This customer\'s cards</b> — this one' : 'The customer\'s own card'],
    ['2nd', $isCustomer ? 'General cards (standard)' : '<b>General cards</b>' . ($card['is_default'] ? ' — this one first (main list)' : ' — the main list first')],
    ['3rd', 'The equipment type\'s own prices'],
    ['Within a card', 'A line for one equipment type beats a whole-category line'],
]) . '<p class="rt-faint" style="margin:8px 0 0;font-size:11.5px;">Leases copy their prices when created — changing this card never changes a lease on rent.</p>',
    ['icon' => 'scale']);

$rail[] = RecordUi::card('Record', RecordUi::kv([
    ['Created by', e($card['created_by_name'] ?? '—')],
    ['Created', e(format_datetime($card['created_at']))],
    ['Last changed', e(format_datetime($card['updated_at']))],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="Rate card at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

    <!-- ── Change prices ──────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="rev.open" x-cloak @keydown.escape.window="rev.open && !rev.working && (rev.open = false)">
        <div class="modal modal-full" role="dialog" aria-modal="true" aria-labelledby="rt-rev-title">
            <div class="modal-header">
                <h3 class="modal-title" id="rt-rev-title"><?= $status === 'expired' ? 'Renew prices' : 'Change prices' ?> — <?= e($card['name']) ?></h3>
                <button class="modal-close-btn" aria-label="Close" @click="rev.open = false" :disabled="rev.working">×</button>
            </div>
            <div class="modal-body" style="overflow:auto;">
                <template x-if="rev.step === 'setup'">
                    <div>
                        <p class="rt-muted" style="margin-top:0;">
                            <?php if ($status === 'expired'): ?>
                            A new card carries these prices (or new ones) from the date you choose. This card stays as history.
                            <?php else: ?>
                            This card keeps its prices until the day before the new ones start; a new card carries the new prices from that date. Both stay on record, and leases already on rent keep their prices.
                            <?php endif; ?>
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                            <div class="form-group">
                                <label class="form-label">New prices start</label>
                                <input type="date" class="form-control" x-model="rev.from" :min="minRevFrom">
                                <div class="rt-quick">
                                    <button type="button" @click="rev.from = FF_Rates.nextMonthStart()">1st of next month</button>
                                    <button type="button" @click="rev.from = FF_Rates.ymd(1)">Tomorrow</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">New card ends</label>
                                <select class="form-select" x-model="rev.end">
                                    <option value="keep"><?= $card['effective_to'] && $status !== 'expired' ? 'Same end date (' . e(format_date($card['effective_to'])) . ')' : 'Open-ended (same as now)' ?></option>
                                    <option value="open">Open-ended</option>
                                    <option value="date">On a date…</option>
                                </select>
                                <input type="date" class="form-control" style="margin-top:6px;" x-show="rev.end === 'date'" x-model="rev.end_date" :min="rev.from">
                            </div>
                            <div class="form-group">
                                <label class="form-label">New card name</label>
                                <input type="text" class="form-control" maxlength="255" x-model="rev.name" :placeholder="'<?= e(addslashes((string) preg_replace('/\s+·\s+from\s+[A-Z][a-z]{2}\s+\d{4}(\s+\(\d+\))?$/u', '', (string) $card['name']))) ?> · from ' + monthLabel(rev.from)">
                                <div class="form-hint">Leave blank for the suggested name.</div>
                            </div>
                        </div>

                        <div class="rt-seg" role="group" aria-label="How to set the new prices" style="margin:4px 0 12px;">
                            <button type="button" :class="{ 'is-on': rev.mode === 'pct' }" @click="rev.mode = 'pct'">Raise or lower by %</button>
                            <button type="button" :class="{ 'is-on': rev.mode === 'type' }" @click="rev.mode = 'type'; seedRevLines()">Type the new prices</button>
                        </div>

                        <template x-if="rev.mode === 'pct'">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                                <div class="form-group">
                                    <label class="form-label">Change by</label>
                                    <div class="rt-money rt-money--pct"><span>%</span><input type="number" class="form-control" step="0.1" x-model="rev.percent" placeholder="e.g. 5 or -3"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Round to</label>
                                    <div class="rt-seg" role="group" aria-label="Rounding">
                                        <button type="button" :class="{ 'is-on': rev.round === '0.01' }" @click="rev.round = '0.01'">Cent</button>
                                        <button type="button" :class="{ 'is-on': rev.round === '1' }" @click="rev.round = '1'">$1</button>
                                        <button type="button" :class="{ 'is-on': rev.round === '5' }" @click="rev.round = '5'">$5</button>
                                    </div>
                                </div>
                                <div class="form-group" style="display:flex;align-items:flex-end;">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                        <input type="checkbox" class="form-check-input" x-model="rev.all_prices"> Also distance, hourly &amp; GPS
                                    </label>
                                </div>
                            </div>
                        </template>

                        <template x-if="rev.mode === 'type'">
                            <div class="rt-grid-wrap" style="border:1px solid var(--border-color);border-radius:12px;">
                                <table class="rt-grid rt-grid--edit">
                                    <thead><tr><th style="padding-left:12px;">Equipment</th><th class="num">Daily</th><th class="num">Weekly</th><th class="num">Monthly</th><th class="num">Distance</th><th class="num">GPS / day</th><th class="num">Min. days</th></tr></thead>
                                    <tbody>
                                        <template x-for="rl in rev.lines" :key="rl._k">
                                            <tr>
                                                <td class="rt-eq"><b x-text="rl.label"></b><small x-text="rl.scope"></small></td>
                                                <td class="num"><div class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" x-model="rl.daily_rate"></div><span class="rt-was" x-text="'now ' + FF_Rates.money(rl._was.daily_rate)"></span></td>
                                                <td class="num"><div class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" x-model="rl.weekly_rate"></div><span class="rt-was" x-text="'now ' + FF_Rates.money(rl._was.weekly_rate)"></span></td>
                                                <td class="num"><div class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" x-model="rl.monthly_rate"></div><span class="rt-was" x-text="'now ' + FF_Rates.money(rl._was.monthly_rate)"></span></td>
                                                <td class="num"><div class="rt-money" style="min-width:78px;"><span>$</span><input type="number" min="0" step="0.0001" class="form-control form-control-sm" x-model="rl.mileage_rate"></div></td>
                                                <td class="num"><div class="rt-money" style="min-width:70px;"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" x-model="rl.gps_price"></div></td>
                                                <td class="num"><input type="number" min="0" max="90" step="1" class="form-control form-control-sm" style="width:64px;text-align:right;" x-model="rl.minimum_days" placeholder="—"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <div class="form-group" style="margin-top:12px;">
                            <label class="form-label">Note for the history <span class="rt-faint">(optional)</span></label>
                            <input type="text" class="form-control" maxlength="500" x-model="rev.note" placeholder="e.g. Annual increase agreed with the customer">
                        </div>
                    </div>
                </template>

                <template x-if="rev.step !== 'setup' && rev.result">
                    <div>
                        <template x-for="r in rev.result.results" :key="r.card_id">
                            <div>
                                <div class="alert" :class="r.status === 'ok' ? 'alert-info' : 'alert-danger'" style="margin-top:0;">
                                    <template x-if="r.status === 'ok'">
                                        <span>
                                            <span x-show="r.old_to !== r.old_to_new" x-text="'This card will end ' + FF_Rates.date(r.old_to_new) + '. '"></span>
                                            <span x-text="'“' + r.new_name + '” starts ' + FF_Rates.date(r.new_from) + (r.new_to ? ' and runs to ' + FF_Rates.date(r.new_to) : ', open-ended') + '.'"></span>
                                            <span x-show="r.gap_days > 0" x-text="' Note: ' + r.gap_days + ' day' + (r.gap_days === 1 ? '' : 's') + ' between the two cards will use standard prices.'"></span>
                                            <span x-show="r.was_default"> The main-price-list flag moves to the new card.</span>
                                        </span>
                                    </template>
                                    <span x-show="r.status !== 'ok'" x-text="r.error"></span>
                                </div>
                                <div class="rt-preview" x-show="r.status === 'ok'">
                                    <table class="rt-grid">
                                        <thead><tr><th>Equipment</th><th class="num">Daily</th><th class="num">Weekly</th><th class="num">Monthly</th><th class="num">Distance</th><th class="num">GPS / day</th></tr></thead>
                                        <tbody>
                                            <template x-for="ln in (r.lines || [])" :key="ln.key">
                                                <tr>
                                                    <td class="rt-eq"><b x-text="ln.label"></b><small x-text="ln.scope"></small></td>
                                                    <template x-for="f in [['daily_rate',2],['weekly_rate',2],['monthly_rate',2],['mileage_rate',4],['gps_price',2]]" :key="f[0]">
                                                        <td class="num">
                                                            <span class="rt-was" x-show="String(ln.before[f[0]]) !== String(ln.after[f[0]])" x-text="FF_Rates.money(ln.before[f[0]], f[1])"></span>
                                                            <span class="rt-num" x-text="FF_Rates.money(ln.after[f[0]], f[1])"></span>
                                                        </td>
                                                    </template>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <div class="alert alert-danger" x-show="rev.error" x-text="rev.error" style="margin-top:12px;" x-cloak></div>
            </div>
            <div class="modal-footer">
                <template x-if="rev.step === 'setup'">
                    <div style="display:flex;gap:8px;justify-content:flex-end;width:100%;">
                        <button class="btn btn-secondary btn-sm" @click="rev.open = false">Cancel</button>
                        <button class="btn btn-primary btn-sm" :disabled="rev.working || !rev.from" @click="previewRevise()" x-text="rev.working ? 'Working it out…' : 'Preview'"></button>
                    </div>
                </template>
                <template x-if="rev.step === 'preview'">
                    <div style="display:flex;gap:8px;justify-content:flex-end;width:100%;">
                        <button class="btn btn-secondary btn-sm" @click="rev.step = 'setup'" :disabled="rev.working">Back</button>
                        <button class="btn btn-primary btn-sm" :disabled="rev.working || !rev.result || !rev.result.ok" @click="applyRevise()" x-text="rev.working ? 'Saving…' : 'Save new prices'"></button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── End these prices ───────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="endModal.open" x-cloak>
        <div class="modal modal-sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h3 class="modal-title">End these prices</h3>
                <button class="modal-close-btn" aria-label="Close" @click="endModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Last day in force</label>
                    <input type="date" class="form-control" x-model="endModal.date" :min="form.effective_from">
                </div>
                <p class="rt-muted text-sm" x-text="'From ' + FF_Rates.date(FF_Rates.ymd(1, endModal.date || FF_Rates.ymd())) + ', new leases for ' + (form.customer_name || 'everyone') + ' get the next price in line (usually the standard price).'"></p>
                <p class="text-danger text-sm" x-show="endModal.error" x-text="endModal.error"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="endModal.open = false">Cancel</button>
                <button class="btn btn-primary btn-sm" :disabled="endModal.saving || !endModal.date" @click="saveEnd()" x-text="endModal.saving ? 'Saving…' : 'End prices'"></button>
            </div>
        </div>
    </div>

    <!-- ── Delete ─────────────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="deleteModal.open" x-cloak>
        <div class="modal modal-sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <h3 class="modal-title">Delete rate card</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p style="margin-top:0;">Delete <strong><?= e($card['name']) ?></strong>?</p>
                <p class="rt-muted text-sm">New leases stop getting these prices. Leases already on rent keep theirs. To keep the history instead, use <b>End these prices</b>.</p>
                <p class="text-danger text-sm" x-show="deleteModal.error" x-text="deleteModal.error"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="deleteModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm" :disabled="deleteModal.saving" @click="deleteCard()" x-text="deleteModal.saving ? 'Deleting…' : 'Delete'"></button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_RateCard() {
    const API = '<?= e(base_url('api/v1/rate_cards')) ?>/';
    const SELF = '<?= e(base_url('rates/show')) ?>?id=<?= $cardId ?>';
    const initial = <?= json_encode([
        'id'             => (int) $card['id'],
        'name'           => $card['name'],
        'description'    => $card['description'],
        'is_default'     => (bool) $card['is_default'],
        'effective_from' => $card['effective_from'],
        'effective_to'   => $card['effective_to'],
        'customer_id'    => $isCustomer ? (int) $card['customer_id'] : null,
        'customer_name'  => $card['customer_name'],
        'updated_at'     => $card['updated_at'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const initialLines = <?= json_encode($lines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const grid = FF_Rates.gridMixin({
        options:   <?= json_encode($lineOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        standards: <?= json_encode($standards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    });

    return Object.assign(grid, {
        form: { ...initial },
        lines: [],
        issues: {},
        editMode: false,
        openEnded: !initial.effective_to,
        saving: false,
        error: '',
        minRevFrom: FF_Rates.ymd(1, initial.effective_from),

        leases: { loaded: false, rows: [], total: 0, differ: 0 },
        history: { loaded: false, events: [], timeline: [] },
        rev: { open: false, step: 'setup', from: '', end: 'keep', end_date: '', name: '', mode: 'pct', percent: '', round: '1',
               all_prices: false, note: '', lines: [], result: null, working: false, error: '' },
        endModal: { open: false, date: '', saving: false, error: '' },
        deleteModal: { open: false, saving: false, error: '' },

        init() {
            this.resetLines();
            this.loadLeases();
            this.loadHistory();
            const qs = new URLSearchParams(location.search);
            if (qs.get('action') === 'revise' && <?= $canRevise ? 'true' : 'false' ?>) this.openRevise();
            if (qs.get('action') === 'edit' && <?= $canEdit ? 'true' : 'false' ?>) this.startEdit();
            if (qs.get('saved') && window.FF_Toast) FF_Toast.show('success', 'Saved', 'The rate card was updated.');
            if (qs.get('changed') && window.FF_Toast) FF_Toast.show('success', 'New prices saved', 'This card carries the new prices from ' + FF_Rates.date(initial.effective_from) + '.');
            if (qs.get('action') || qs.get('saved') || qs.get('changed')) history.replaceState(null, '', SELF + location.hash);
        },

        /** The Hourly column only earns its space when a line uses it. */
        hasHourly() { return this.lines.some(l => Number(l.hourly_rate) > 0); },

        resetLines() {
            this.lines = initialLines.map(l => this.makeLine(l));
            this.issues = {};
            initialLines.forEach(l => { if (l.issues && l.issues.length) this.issues[l.key] = l.issues; });
        },

        // ── Edit ────────────────────────────────────────────────────
        startEdit() {
            this.error = '';
            this.editMode = true;
            this.$nextTick(() => document.getElementById('prices')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },
        cancelEdit() {
            this.form = { ...initial };
            this.openEnded = !initial.effective_to;
            this.resetLines();
            this.editMode = false;
            this.error = '';
        },
        headerDirty() {
            const a = this.form, b = initial;
            return a.name !== b.name || (a.description || '') !== (b.description || '') || !!a.is_default !== !!b.is_default
                || a.effective_from !== b.effective_from || (this.openEnded ? '' : (a.effective_to || '')) !== (b.effective_to || '')
                || (a.customer_id || null) !== (b.customer_id || null);
        },
        dirty() { return this.headerDirty() || this.linesDirty() || this.lines.length !== initialLines.length; },
        problems() {
            const p = [];
            if (!String(this.form.name || '').trim()) p.push('The card needs a name.');
            if (!this.form.effective_from) p.push('Choose the day the prices start.');
            if (!this.openEnded && this.form.effective_to && this.form.effective_to < this.form.effective_from) p.push('The end date must be on or after the start date.');
            return p.concat(this.gridProblems());
        },
        addStandardLine() {
            const l = this.addLine(this.newLineKey);
            if (l) this.fillFromStandard(l);
        },
        async save() {
            const probs = this.problems();
            if (probs.length) { this.error = probs.join(' '); return; }
            this.saving = true;
            this.error = '';
            const body = {
                id: this.form.id, updated_at: this.form.updated_at,
                name: this.form.name.trim(), description: (this.form.description || '').trim() || null,
                effective_from: this.form.effective_from, effective_to: this.openEnded ? null : (this.form.effective_to || null),
                is_default: this.form.customer_id ? 0 : (this.form.is_default ? 1 : 0),
                customer_id: this.form.customer_id || null,
            };
            if (this.linesDirty() || this.lines.length !== initialLines.length) body.items = this.gridPayload();
            try {
                const r = await FF_Api.post(API + 'update', body);
                if (!r.success) {
                    this.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Save failed.');
                    return;
                }
                window.location = SELF + '&saved=1';
            } catch (e) {
                this.error = e.message || 'Save failed.';
            } finally {
                this.saving = false;
            }
        },
        async toggleDefault() {
            try {
                const r = await FF_Api.post(API + 'update', { id: this.form.id, updated_at: this.form.updated_at, is_default: this.form.is_default ? 0 : 1 });
                if (!r.success) { this.error = r.error?.message || 'Could not change the main price list.'; return; }
                window.location = SELF + '&saved=1';
            } catch (e) { this.error = e.message || 'Could not change the main price list.'; }
        },

        // ── Leases + history ────────────────────────────────────────
        async loadLeases() {
            <?php if (!$canLeases): ?>return;<?php endif; ?>
            try {
                const r = await FF_Api.get(API + 'leases?id=' + initial.id);
                if (r.success) this.leases = { loaded: true, rows: r.data.leases, total: r.data.total, differ: r.data.differ };
            } catch (e) { this.leases.loaded = true; }
        },
        async loadHistory() {
            try {
                const r = await FF_Api.get(API + 'history?id=' + initial.id);
                if (r.success) this.history = { loaded: true, events: r.data.events, timeline: r.data.timeline };
                else this.history.loaded = true;
            } catch (e) { this.history.loaded = true; }
        },
        eventTitle(ev) {
            if (ev.replaced_by) return 'Prices changed — continued on a new card';
            if (ev.replaces) return 'Created with new prices (replacing an earlier card)';
            if (ev.action === 'create') return 'Card created' + (ev.item_count !== null && ev.item_count !== undefined ? ' with ' + ev.item_count + ' line' + (ev.item_count === 1 ? '' : 's') : '');
            if (ev.action === 'delete') return 'Card deleted';
            if (ev.lines.length && !ev.fields.length) return 'Prices edited';
            if (ev.fields.length && !ev.lines.length) return 'Details edited';
            return 'Card edited';
        },
        fieldText(field, v) {
            if (v === null || v === undefined || v === '') return field === 'effective_to' ? 'open-ended' : '—';
            if (field === 'effective_from' || field === 'effective_to') return FF_Rates.date(v);
            if (field === 'is_default') return Number(v) ? 'yes' : 'no';
            if (FF_Rates.FIELD_DP[field]) return FF_Rates.money(v, FF_Rates.FIELD_DP[field], false);
            return String(v);
        },

        // ── Change prices ───────────────────────────────────────────
        monthLabel(ymd) {
            if (!ymd) return '…';
            const d = new Date(ymd + 'T12:00:00');
            return d.toLocaleString('en-CA', { month: 'short' }) + ' ' + d.getFullYear();
        },
        openRevise() {
            let from = FF_Rates.nextMonthStart();
            if (from < this.minRevFrom) from = this.minRevFrom;
            this.rev = Object.assign(this.rev, { open: true, step: 'setup', from, result: null, error: '', working: false });
        },
        seedRevLines() {
            if (this.rev.lines.length) return;
            let k = 1;
            this.rev.lines = initialLines.map(l => {
                const row = { _k: k++, key: l.key, label: l.label, scope: l.scope, equipment_type: l.equipment_type, equipment_template_id: l.equipment_template_id,
                              mileage_unit: l.mileage_unit, currency: l.currency, notes: l.notes || null, hourly_rate: l.hourly_rate,
                              minimum_days: l.minimum_days === null || l.minimum_days === undefined ? '' : String(l.minimum_days), _was: l };
                ['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'gps_price'].forEach(f => { row[f] = l[f] === null ? '' : FF_Rates.clean(l[f], FF_Rates.FIELD_DP[f]); });
                return row;
            });
        },
        revBody(dry) {
            const body = {
                card_ids: [initial.id], effective_from: this.rev.from,
                effective_to: this.rev.end === 'date' ? this.rev.end_date : this.rev.end,
                note: this.rev.note || null, dry_run: dry ? 1 : 0,
            };
            if (this.rev.name.trim()) body.names = { [initial.id]: this.rev.name.trim() };
            if (this.rev.mode === 'type') {
                body.items_by_card = { [initial.id]: this.rev.lines.map(l => ({
                    equipment_type: l.equipment_type, equipment_template_id: l.equipment_template_id || null,
                    daily_rate: l.daily_rate === '' ? null : l.daily_rate, weekly_rate: l.weekly_rate === '' ? null : l.weekly_rate,
                    monthly_rate: l.monthly_rate === '' ? null : l.monthly_rate, mileage_rate: l.mileage_rate === '' ? null : l.mileage_rate,
                    mileage_unit: l.mileage_unit, hourly_rate: l.hourly_rate, gps_price: l.gps_price === '' ? null : l.gps_price,
                    minimum_days: l.minimum_days === '' ? null : l.minimum_days, currency: l.currency, notes: l.notes,
                })) };
            } else {
                body.percent = this.rev.percent === '' ? '0' : String(this.rev.percent);
                body.round = this.rev.round;
                body.all_prices = this.rev.all_prices ? 1 : 0;
            }
            return body;
        },
        async previewRevise() {
            this.rev.working = true;
            this.rev.error = '';
            try {
                const r = await FF_Api.post(API + 'revise', this.revBody(true));
                if (!r.success) {
                    this.rev.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not preview.');
                    return;
                }
                this.rev.result = r.data;
                this.rev.step = 'preview';
            } catch (e) {
                this.rev.error = e.message || 'Could not preview.';
            } finally {
                this.rev.working = false;
            }
        },
        async applyRevise() {
            this.rev.working = true;
            this.rev.error = '';
            try {
                const r = await FF_Api.post(API + 'revise', this.revBody(false));
                if (!r.success) { this.rev.error = r.error?.message || 'Could not save the new prices.'; return; }
                this.rev.result = r.data;
                if (!r.data.applied) return;
                const nid = r.data.results[0]?.new_card_id;
                window.location = '<?= e(base_url('rates/show')) ?>?id=' + nid + '&changed=1';
            } catch (e) {
                this.rev.error = e.message || 'Could not save the new prices.';
            } finally {
                this.rev.working = false;
            }
        },

        // ── End / delete ────────────────────────────────────────────
        openEnd() {
            const today = FF_Rates.ymd();
            this.endModal = { open: true, date: today < initial.effective_from ? initial.effective_from : today, saving: false, error: '' };
        },
        async saveEnd() {
            this.endModal.saving = true;
            this.endModal.error = '';
            try {
                const r = await FF_Api.post(API + 'update', { id: initial.id, updated_at: this.form.updated_at, effective_to: this.endModal.date });
                if (!r.success) {
                    this.endModal.error = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Could not end the prices.');
                    return;
                }
                window.location = SELF + '&saved=1';
            } catch (e) {
                this.endModal.error = e.message || 'Could not end the prices.';
            } finally {
                this.endModal.saving = false;
            }
        },
        async deleteCard() {
            this.deleteModal.saving = true;
            this.deleteModal.error = '';
            try {
                const r = await FF_Api.post(API + 'delete', { id: initial.id, updated_at: this.form.updated_at });
                if (!r.success) { this.deleteModal.error = r.error?.message || 'Delete failed.'; return; }
                window.location = '<?= e(base_url('rates')) ?>#cards';
            } catch (e) {
                this.deleteModal.error = e.message || 'Delete failed.';
            } finally {
                this.deleteModal.saving = false;
            }
        },
    });
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
