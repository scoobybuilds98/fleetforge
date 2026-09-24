<?php
declare(strict_types=1);

/**
 * app/admin/rates/show.php
 *
 * Rate card detail / edit page.
 * Two-panel layout: card metadata (incl. customer) + rate items table.
 * Equipment type dropdown uses category slugs (S-RATES-UI-CATEGORY-DEDUP fix).
 * S-LEASE-MIN-DAYS: each rate item shows/edits minimum_days — the per-equipment
 * short-lease daily floor (em-dash when null); persisted via update.php.
 *
 * Layout (S-RECORD-REDESIGN):
 *   The Alpine component (FF_RateCardShow) opens ABOVE the header so the
 *   actions live there: + Add Rate (primary), Edit details; the rest (new
 *   card for this customer, all cards, Delete) in the More menu — the old
 *   bottom-of-page "Delete This Rate Card" button moved there.
 *   header — ModuleHero entity: card name + Active/Expired/Upcoming +
 *            Default badges; customer (or "All customers") · effective
 *            window chips
 *   strip  — rates (live count) · applies to (customer / general) ·
 *            effective (days left / expired / starts) · last updated
 *   main   — Card Details (in-place edit, customer record-picker) · Rate
 *            Items (the cream cards + editor, unchanged)
 *   rail   — Needs attention (no rates, expired / not yet effective, rates
 *            with no prices, archived customer, overlapping customer cards)
 *            · Customer (with active-lease count) · How this card is used
 *            (the lookup order) · Record (created / updated)
 *   "Leases using this card" is not shown: leases copy their rates and keep
 *   no rate_card_id, so it is not queryable (see the rail note instead).
 *
 * D30: asset_url() / base_url().
 * D32: Only confirmed CSS classes.
 * D19: updated_at passed on every update.
 * D5:  rate_cards has deleted_at — 404 if card soft-deleted.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/rate_cards/show.php, api/v1/rate_cards/update.php
 *           includes/partials/record-picker.php
 * @decisions D5/D7/D16/D19/D30/D32
 * @session  S019, S-RATES-REDESIGN, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

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

$items = db_select(
    "SELECT rci.id, rci.equipment_type, rci.equipment_template_id,
            et.name AS equipment_template_name,
            rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
            rci.mileage_rate, rci.mileage_unit, rci.hourly_rate, rci.gps_price,
            rci.minimum_days, rci.currency, rci.notes, rci.updated_at
     FROM rate_card_items rci
     LEFT JOIN equipment_templates et ON et.id = rci.equipment_template_id AND et.deleted_at IS NULL
     WHERE rci.rate_card_id = ?
     ORDER BY rci.equipment_type ASC, ISNULL(rci.equipment_template_id) DESC, et.name ASC",
    [$cardId]
);

// Distinct equipment categories for the items editor
$categories = db_select(
    "SELECT DISTINCT category FROM equipment_templates
     WHERE deleted_at IS NULL
     ORDER BY category ASC"
);

// S-EQTAX: slug → label sourced from the operator-managed taxonomy (categories +
// sub-categories). Category labels win over a same-slug sub; unknown slugs are
// humanized downstream.
$categoryLabels = [];
foreach (db_select("SELECT slug, label FROM equipment_subcategories WHERE deleted_at IS NULL") as $r) { $categoryLabels[$r['slug']] = $r['label']; }
foreach (db_select("SELECT slug, label FROM equipment_categories WHERE deleted_at IS NULL") as $r) { $categoryLabels[$r['slug']] = $r['label']; }

// Company-local day (S-RECORD-REDESIGN: was PHP-local date('Y-m-d')).
$today    = ff_today();
$isActive = ($card['effective_from'] <= $today &&
             ($card['effective_to'] === null || $card['effective_to'] >= $today));
$isExpired  = $card['effective_to'] !== null && $card['effective_to'] < $today;
$isUpcoming = $card['effective_from'] > $today;
$daysTo = static fn (string $d): int => (int) round((strtotime($d) - strtotime($today)) / 86400);

// ── Rail / strip facts (S-RECORD-REDESIGN) ─────────────────────────────────
// Rates with every price blank (a row the lookup would find but can't price).
$emptyItems = 0;
$templateItems = 0;
foreach ($items as $it) {
    $priced = false;
    foreach (['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'hourly_rate', 'gps_price'] as $col) {
        if ($it[$col] !== null && bccomp((string) $it[$col], '0', 4) > 0) { $priced = true; break; }
    }
    if (!$priced) $emptyItems++;
    if (!empty($it['equipment_template_id'])) $templateItems++;
}
$custActiveLeases = null;
$otherCustCards   = [];
if (!empty($card['customer_id'])) {
    $custActiveLeases = (int) (db_row(
        "SELECT COUNT(*) AS c FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
        [(int) $card['customer_id']]
    )['c'] ?? 0);
    // Other cards for the same customer whose window overlaps this one — the
    // lookup then prefers is_default, then the newest effective_from.
    $otherCustCards = db_select(
        "SELECT id, name FROM rate_cards
          WHERE customer_id = ? AND id <> ? AND deleted_at IS NULL
            AND effective_from <= COALESCE(?, '9999-12-31')
            AND COALESCE(effective_to, '9999-12-31') >= ?
          ORDER BY is_default DESC, effective_from DESC LIMIT 5",
        [(int) $card['customer_id'], $cardId, $card['effective_to'], $card['effective_from']]
    );
}

// Raw: includes/header.php escapes it (was e() here → "53&#039; T/A" in the tab/topbar).
$pageTitle = (string) $card['name'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = e($card['name'])
    . ' <span class="badge ' . ($isActive ? 'badge-success' : ($isExpired ? 'badge-danger' : 'badge-neutral')) . '">' . ($isActive ? 'Active' : ($isExpired ? 'Expired' : ($isUpcoming ? 'Upcoming' : 'Inactive'))) . '</span>'
    . ($card['is_default'] ? ' <span class="badge badge-info">Default</span>' : '');
$heroFacts = [];
if ($card['customer_id']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group') . (can('customers', 'view')
        ? '<a href="' . e(base_url('customers/show')) . '?id=' . (int) $card['customer_id'] . '">' . e($card['customer_name'] ?? 'Customer') . '</a>'
        : e($card['customer_name'] ?? 'Customer'));
} else {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('users') . 'All customers (general card)';
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($card['effective_from'])) . ' → ' . ($card['effective_to'] ? e(format_date($card['effective_to'])) : 'open-ended');
if (!empty($card['description'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('document-text') . e(mb_strimwidth((string) $card['description'], 0, 60, '…'));
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('rates') ?>">All rate cards</a>
    <?php if ($card['customer_id'] && can('rates', 'create')): ?>
    <a href="<?= base_url('rates/create') ?>?customer_id=<?= (int) $card['customer_id'] ?>">New card for this customer</a>
    <?php endif; ?>
    <?php if ($card['customer_id'] && can('customers', 'view')): ?>
    <a href="<?= base_url('customers/show') ?>?id=<?= (int) $card['customer_id'] ?>#rates">Customer's rate cards</a>
    <?php endif; ?>
    <?php if (can('rates', 'delete') && !$card['is_default']): ?>
    <button class="btn btn-danger btn-sm" @click="deleteModal.open = true">Delete This Rate Card</button>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?= help_button('rates') ?>
    <?php if (can('rates', 'edit')): ?>
    <button class="btn btn-primary btn-sm" @click="addItem()">+ Add Rate</button>
    <button class="btn btn-secondary btn-sm" x-show="!editMode" @click="editMode = true">Edit details</button>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>

<!-- The component opens ABOVE the header so + Add Rate / Edit / Delete can live there. -->
<div x-data="FF_RateCardShow()">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $isExpired ? 'danger' : 'primary',
    'icon'       => 'currency-dollar',
    'avatar'     => $card['customer_id'] ? \FleetForge\Ui\ModuleHero::initials((string) ($card['customer_name'] ?? '')) : '',
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Rates', base_url('rates')], [(string) $card['name'], null]],
    'eyebrow'    => $card['customer_id'] ? 'Customer rate card' : 'General rate card',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

    <!-- KEY NUMBERS (S-RECORD-REDESIGN): how many rates the card carries
         (live), who it prices, how long it stays in force, and when it last
         changed. -->
    <div class="stat-grid stat-grid--4 ff-stats">
        <div class="stat-card stat-card--blue">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-tag"/></svg></span>
            <div class="stat-label">Rates</div>
            <div class="stat-value font-mono" x-text="items.length"><?= count($items) ?></div>
            <div class="stat-delta" x-text="new Set(items.map(i => i.equipment_type).filter(Boolean)).size + ' types'"></div>
        </div>
        <?php if ($card['customer_id'] && can('customers', 'view')): ?>
        <a class="stat-card stat-card--purple" href="<?= base_url('customers/show') ?>?id=<?= (int) $card['customer_id'] ?>#rates" title="The customer's rate cards">
        <?php else: ?>
        <div class="stat-card stat-card--purple">
        <?php endif; ?>
            <span class="stat-icon stat-icon--purple"><svg><use href="#icon-<?= $card['customer_id'] ? 'key' : 'building' ?>"/></svg></span>
            <div class="stat-label">Applies to</div>
            <div class="stat-value"><?= $card['customer_id'] ? 'One customer' : 'Everyone' ?></div>
            <div class="stat-delta"><?= $card['customer_id'] ? e(mb_strimwidth((string) ($card['customer_name'] ?? ''), 0, 22, '…')) : ($card['is_default'] ? 'default' : 'general') ?></div>
        <?= ($card['customer_id'] && can('customers', 'view')) ? '</a>' : '</div>' ?>
        <?php
        if ($isExpired) {
            $effVal = format_date($card['effective_to']); $effDelta = (-$daysTo($card['effective_to'])) . 'd ago'; $effTone = 'red';
        } elseif ($isUpcoming) {
            $effVal = format_date($card['effective_from']); $effDelta = 'in ' . $daysTo($card['effective_from']) . 'd'; $effTone = 'amber';
        } elseif ($card['effective_to']) {
            $effVal = format_date($card['effective_to']); $effDelta = $daysTo($card['effective_to']) . 'd left'; $effTone = $daysTo($card['effective_to']) <= 30 ? 'amber' : 'green';
        } else {
            $effVal = 'Open-ended'; $effDelta = 'no end date'; $effTone = 'green';
        }
        ?>
        <div class="stat-card stat-card--<?= $effTone ?>">
            <span class="stat-icon stat-icon--<?= $effTone ?>"><svg><use href="#icon-<?= $effTone === 'red' ? 'exclamation-triangle' : 'clock' ?>"/></svg></span>
            <div class="stat-label"><?= $isExpired ? 'Expired' : ($isUpcoming ? 'Starts' : 'In force until') ?></div>
            <div class="stat-value stat-value--date font-mono"<?= $isExpired ? ' style="color:var(--color-danger);"' : '' ?>><?= e($effVal) ?></div>
            <div class="stat-delta"><?= e($effDelta) ?></div>
        </div>
        <div class="stat-card stat-card--slate">
            <span class="stat-icon stat-icon--slate"><svg><use href="#icon-pencil-square"/></svg></span>
            <div class="stat-label">Last updated</div>
            <div class="stat-value stat-value--date font-mono"><?= e(format_datetime($card['updated_at'], 'M j, Y')) ?></div>
        </div>
    </div>

    <!-- Global messages -->
    <div class="alert alert-danger" x-show="globalError" x-text="globalError"
         style="margin-bottom:16px;" x-cloak></div>
    <div class="alert alert-success" x-show="saveSuccess"
         style="margin-bottom:16px;" x-cloak>Rate card saved successfully.</div>

<div class="rec-layout">
<div class="rec-main">

    <!-- ── Card metadata ─────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3 class="card-title">Card Details</h3>
            <?php if (can('rates', 'edit')): ?>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary btn-sm" x-show="!editMode" @click="editMode = true">Edit</button>
                <button class="btn btn-secondary btn-sm" x-show="editMode"  @click="cancelEdit()">Cancel</button>
                <button class="btn btn-primary btn-sm"   x-show="editMode"  :disabled="saving" @click="saveCard()">
                    <span x-text="saving ? 'Saving…' : 'Save Changes'"></span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="form-grid">

                <!-- Name -->
                <div class="form-group">
                    <label class="form-label">Card Name</label>
                    <div x-show="!editMode" class="form-control-static" x-text="form.name"></div>
                    <input x-show="editMode" type="text" class="form-control" x-model="form.name" maxlength="255">
                </div>

                <!-- Customer -->
                <div class="form-group">
                    <label class="form-label">Customer
                        <span x-show="!editMode && !form.customer_id" class="text-secondary" style="font-weight:400;">(global)</span>
                    </label>
                    <!-- View mode -->
                    <template x-if="!editMode">
                        <div class="form-control-static">
                            <template x-if="form.customer_id">
                                <a :href="'<?= base_url('customers/show') ?>?id=' + form.customer_id"
                                   class="link" x-text="form.customer_name || '—'"></a>
                            </template>
                            <template x-if="!form.customer_id">
                                <span class="text-secondary">— Global rate card —</span>
                            </template>
                        </div>
                    </template>
                    <!-- Edit mode -->
                    <template x-if="editMode">
                        <div>
                            <?php
                            $pickerName     = 'customerPickerEdit';
                            $pickerConfig   = [
                                'endpoint'    => '/api/v1/customers/index.php',
                                'searchParam' => 'search',
                                'resultKey'   => 'items',
                                'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: (r.city ?? '') + (r.province ? ', ' + r.province : '') })",
                                'placeholder' => 'Leave blank for global…',
                                'initialId'   => (int)($card['customer_id'] ?? 0) ?: null,
                                'initialLabel'=> $card['customer_name'] ?? '',
                            ];
                            $pickerOnPicked  = 'form.customer_id = $event.detail.id; form.customer_name = $event.detail.label';
                            $pickerOnCleared = 'form.customer_id = null; form.customer_name = null';
                            $pickerError     = 'false';
                            require FF_ROOT . '/includes/partials/record-picker.php';
                            ?>
                            <div class="form-hint">Clear to make this a global rate card.</div>
                        </div>
                    </template>
                </div>

                <!-- Effective From -->
                <div class="form-group">
                    <label class="form-label">Effective From</label>
                    <div x-show="!editMode" class="form-control-static font-mono" x-text="form.effective_from"></div>
                    <input x-show="editMode" type="date" class="form-control" x-model="form.effective_from">
                </div>

                <!-- Effective To -->
                <div class="form-group">
                    <label class="form-label">Effective To</label>
                    <div x-show="!editMode" class="form-control-static font-mono"
                         x-text="form.effective_to || 'Open-ended'"></div>
                    <input x-show="editMode" type="date" class="form-control"
                           x-model="form.effective_to" :min="form.effective_from || ''">
                </div>

                <!-- Is Default -->
                <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:28px;">
                    <div x-show="!editMode">
                        <span class="badge badge-info" x-show="form.is_default">Default Card</span>
                        <span class="text-secondary" x-show="!form.is_default">Not default</span>
                    </div>
                    <template x-if="editMode">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="checkbox" class="form-check-input" x-model="form.is_default">
                            <label class="form-label" style="margin:0;">Set as Default</label>
                        </div>
                    </template>
                </div>

                <!-- Description -->
                <div class="form-group form-group--full">
                    <label class="form-label">Description</label>
                    <div x-show="!editMode" class="form-control-static" x-text="form.description || '—'"></div>
                    <input x-show="editMode" type="text" class="form-control" x-model="form.description" maxlength="1000">
                </div>

            </div>
            <?php /* S-RECORD-REDESIGN: the created/updated meta row moved to the rail's Record card. */ ?>
        </div>
    </div>

    <!-- ── Rate Items ─────────────────────────────────────────────────────── -->
    <div class="card" id="rate-items">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <div>
                <h3 class="card-title" style="display:inline;">Rate Items</h3>
                <span class="badge badge-neutral" style="font-size:0.75rem;margin-left:8px;"
                      x-text="items.length + ' item' + (items.length === 1 ? '' : 's')"></span>
            </div>
            <?php if (can('rates', 'edit')): ?>
            <button class="btn btn-secondary btn-sm" @click="addItem()">+ Add Rate</button>
            <?php endif; ?>
        </div>

        <template x-if="items.length === 0">
            <div class="card-body" style="text-align:center;padding:40px 24px;">
                <p class="text-secondary" style="font-size:0.875rem;margin:0;">No rate items yet.</p>
                <?php if (can('rates', 'edit')): ?>
                <p class="text-secondary" style="font-size:0.8125rem;margin:4px 0 0;">
                    Click <strong>+ Add Type</strong> to define rates per equipment category.
                </p>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="items.length > 0">
            <div class="card-body" style="padding-top:8px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
                    <template x-for="(item, idx) in items" :key="idx">
                        <div>

                            <!-- ── VIEW MODE — cream card (shared .rate-item-card) ─ -->
                            <template x-if="!item.editing">
                                <div class="rate-item-card">
                                    <!-- Header: type name + template badge + currency + edit/delete -->
                                    <div class="rate-item-card__head">
                                        <div style="display:flex;align-items:center;gap:6px;min-width:0;flex-wrap:wrap;">
                                            <span class="rate-item-card__type rate-item-card__type--name"
                                                  x-text="categoryLabel(item.equipment_type)"></span>
                                            <template x-if="item.equipment_template_id">
                                                <span class="badge badge-info" style="font-size:0.7rem;"
                                                      x-text="item._templateName || '—'"></span>
                                            </template>
                                            <span class="rate-item-card__cur" x-text="item.currency || 'CAD'"></span>
                                        </div>
                                        <?php if (can('rates', 'edit')): ?>
                                        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
                                            <button class="btn btn-secondary btn-sm"
                                                    style="padding:3px 12px;font-size:0.75rem;"
                                                    @click="item.editing = true">Edit</button>
                                            <button class="btn btn-outline-danger btn-sm"
                                                    style="padding:3px 12px;font-size:0.75rem;"
                                                    @click="deleteItem(idx)">Delete</button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Body: rate key-value pairs -->
                                    <div class="rate-item-card__rows">
                                        <span class="rate-item-card__k" x-show="item.daily_rate">Daily</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.daily_rate"
                                              x-text="item.daily_rate ? '$' + parseFloat(item.daily_rate).toFixed(2) : ''"></span>

                                        <span class="rate-item-card__k" x-show="item.weekly_rate">Weekly</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.weekly_rate"
                                              x-text="item.weekly_rate ? '$' + parseFloat(item.weekly_rate).toFixed(2) : ''"></span>

                                        <span class="rate-item-card__k" x-show="item.monthly_rate">Monthly</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.monthly_rate"
                                              x-text="item.monthly_rate ? '$' + parseFloat(item.monthly_rate).toFixed(2) : ''"></span>

                                        <span class="rate-item-card__k" x-show="item.mileage_rate">Mileage</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.mileage_rate"
                                              x-text="item.mileage_rate ? '$' + parseFloat(item.mileage_rate).toFixed(4) + ' / ' + item.mileage_unit : ''"></span>

                                        <span class="rate-item-card__k" x-show="item.hourly_rate">Hourly</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.hourly_rate"
                                              x-text="item.hourly_rate ? '$' + parseFloat(item.hourly_rate).toFixed(4) + '/hr' : ''"></span>

                                        <span class="rate-item-card__k" x-show="item.gps_price">GPS</span>
                                        <span class="rate-item-card__v font-mono" x-show="item.gps_price"
                                              x-text="item.gps_price ? '$' + parseFloat(item.gps_price).toFixed(2) + '/day' : ''"></span>

                                        <!-- S-LEASE-MIN-DAYS: short-lease floor; em-dash when null/none -->
                                        <span class="rate-item-card__k">Min days</span>
                                        <span class="rate-item-card__v font-mono"
                                              x-text="(item.minimum_days !== null && item.minimum_days !== '' && item.minimum_days !== undefined) ? item.minimum_days : '—'"></span>

                                        <template x-if="!item.daily_rate && !item.weekly_rate && !item.monthly_rate && !item.mileage_rate && !item.hourly_rate && !item.gps_price">
                                            <span class="rate-item-card__empty">No rates set</span>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- ── EDIT MODE — dark card (form) ──────────────── -->
                            <template x-if="item.editing">
                                <div style="border:1px solid var(--border-color);border-radius:14px;background:var(--bg-secondary);overflow:hidden;">
                                    <!-- Header: type selector + currency + remove -->
                                    <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                            <label class="form-label" style="margin:0;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.03em;color:var(--text-secondary);">Equipment Type</label>
                                            <?php if (can('rates', 'edit')): ?>
                                            <button style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:1.25rem;line-height:1;padding:0 2px;flex-shrink:0;"
                                                    onmouseover="this.style.color='var(--color-danger)'"
                                                    onmouseout="this.style.color='var(--text-secondary)'"
                                                    @click="removeItem(idx)" title="Remove this equipment type">&times;</button>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <select class="form-select"
                                                    x-model="item.equipment_type"
                                                    @change="clearTemplate(items.indexOf(item))"
                                                    style="flex:1;min-width:0;">
                                                <option value="">— Select type —</option>
                                                <?php foreach ($categories as $cat): ?>
                                                <option value="<?= e($cat['category']) ?>">
                                                    <?= e($categoryLabels[$cat['category']] ?? ucfirst(str_replace('_', ' ', $cat['category']))) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select class="form-select"
                                                    x-model="item.currency"
                                                    style="width:90px;flex-shrink:0;">
                                                <option value="CAD">CAD</option>
                                                <option value="USD">USD</option>
                                            </select>
                                        </div>
                                        <!-- S-RATE-CARD-TEMPLATE-ITEM: optional unit-type override -->
                                        <div style="margin-top:8px;">
                                            <div style="font-size:0.7rem;color:var(--text-muted);margin-bottom:3px;">
                                                Specific Unit Type
                                                <span style="font-weight:400;">(optional — leave blank to apply to all
                                                    <span x-text="item.equipment_type ? '&quot;' + categoryLabel(item.equipment_type) + '&quot;' : 'this category'"></span>)
                                                </span>
                                            </div>
                                            <template x-if="item.equipment_template_id">
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <span class="badge badge-info" style="font-size:0.75rem;" x-text="item._templateName"></span>
                                                    <button type="button"
                                                            style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:0.75rem;padding:0 4px;"
                                                            @click="clearTemplate(items.indexOf(item))">× clear</button>
                                                </div>
                                            </template>
                                            <template x-if="!item.equipment_template_id">
                                                <div style="position:relative;" @click.outside="item._templateOpen = false">
                                                    <input type="text" class="form-control"
                                                           style="font-size:0.8125rem;height:34px;"
                                                           :disabled="!item.equipment_type"
                                                           :placeholder="item.equipment_type ? 'Search unit types…' : 'Select category first'"
                                                           x-model="item._templateSearch"
                                                           @input.debounce.300ms="searchTemplates(items.indexOf(item), $event.target.value)"
                                                           @focus="if(item._templateResults.length) item._templateOpen = true">
                                                    <div x-show="item._templateOpen" x-cloak
                                                         style="position:absolute;top:100%;left:0;right:0;z-index:100;
                                                                background:var(--bg-primary);border:1px solid var(--border-color);
                                                                border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);
                                                                max-height:160px;overflow-y:auto;margin-top:2px;">
                                                        <template x-for="tmpl in item._templateResults" :key="tmpl.id">
                                                            <div style="padding:8px 12px;cursor:pointer;font-size:0.8125rem;"
                                                                 onmouseover="this.style.background='var(--bg-secondary)'"
                                                                 onmouseout="this.style.background=''"
                                                                 @mousedown.prevent="pickTemplate(items.indexOf(item), tmpl)"
                                                                 x-text="tmpl.name"></div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <!-- Body: 2×2 rate input grid -->
                                    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">

                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Daily Rate</label>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.daily_rate" step="0.01" min="0" placeholder="0.00">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Weekly Rate</label>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.weekly_rate" step="0.01" min="0" placeholder="0.00">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Monthly Rate</label>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.monthly_rate" step="0.01" min="0" placeholder="0.00">
                                            </div>
                                        </div>

                                        <div>
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;height:18px;">
                                                <label class="form-label" style="font-size:0.75rem;margin:0;">Mileage Rate</label>
                                                <select class="form-select"
                                                        x-model="item.mileage_unit"
                                                        style="width:auto;height:20px;font-size:0.7rem;padding:0 18px 0 6px;">
                                                    <option value="km">/ km</option>
                                                    <option value="miles">/ mi</option>
                                                </select>
                                            </div>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.mileage_rate" step="0.0001" min="0" placeholder="0.0000">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Hourly (reefer) $/hr</label>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.hourly_rate" step="0.0001" min="0" placeholder="0.0000">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">GPS $/day</label>
                                            <div style="position:relative;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-secondary);font-size:0.875rem;pointer-events:none;user-select:none;">$</span>
                                                <input type="number" class="form-control font-mono"
                                                       style="padding-left:22px;"
                                                       x-model="item.gps_price" step="0.01" min="0" placeholder="0.00">
                                            </div>
                                        </div>

                                        <!-- S-LEASE-MIN-DAYS: per-equipment short-lease floor. Blank = no
                                             minimum; when a lease's total billable duration is shorter than
                                             this many days, billing bills a flat N × daily rate. -->
                                        <div>
                                            <label class="form-label" style="font-size:0.75rem;margin-bottom:4px;">Min days</label>
                                            <input type="number" class="form-control font-mono"
                                                   x-model="item.minimum_days" step="1" min="0" placeholder="—">
                                            <div class="form-hint" style="font-size:0.7rem;margin-top:3px;">Short-lease floor for this equipment (blank = none)</div>
                                        </div>

                                    </div>
                                    <!-- Footer: save/cancel -->
                                    <?php if (can('rates', 'edit')): ?>
                                    <div style="padding:12px 16px;border-top:1px solid var(--border-color);display:flex;gap:8px;justify-content:flex-end;">
                                        <button class="btn btn-secondary btn-sm"
                                                @click="cancelItemEdit(idx)">Cancel</button>
                                        <button class="btn btn-primary btn-sm"
                                                :disabled="saving"
                                                @click="saveItems()">
                                            <span x-text="saving ? 'Saving…' : 'Save'"></span>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </template>

                        </div>
                    </template>
                </div>

                <?php if (can('rates', 'edit')): ?>
                <div style="display:flex;align-items:center;justify-content:flex-end;margin-top:16px;">
                    <button class="btn btn-primary btn-sm" :disabled="saving" @click="saveItems()">
                        <span x-text="saving ? 'Saving…' : 'Save All Items'"></span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </template>
    </div>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the rate card at a glance ────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($isExpired) {
    $alerts[] = ['danger', 'Expired ' . e(format_date($card['effective_to'])) . ' — new leases no longer pick this card up.'];
} elseif ($isUpcoming) {
    $alerts[] = ['info', 'Not in force until ' . e(format_date($card['effective_from'])) . '.'];
} elseif ($card['effective_to'] && $daysTo($card['effective_to']) <= 30) {
    $alerts[] = ['warning', 'Ends in ' . $daysTo($card['effective_to']) . ' day' . ($daysTo($card['effective_to']) === 1 ? '' : 's') . ' (' . e(format_date($card['effective_to'])) . ') — renew or extend it.'];
}
if ($emptyItems > 0) {
    $alerts[] = ['warning', $emptyItems . ' rate' . ($emptyItems === 1 ? ' has' : 's have') . ' no prices set.'];
}
if ($card['customer_id'] && $card['customer_name'] === null) {
    $alerts[] = ['warning', 'The customer on this card is archived — it will not price any new lease.'];
}
if ($otherCustCards) {
    $links = array_map(static fn ($c) => '<a href="' . e(base_url('rates/show')) . '?id=' . (int) $c['id'] . '">' . e($c['name']) . '</a>', $otherCustCards);
    $alerts[] = ['info', 'Also in force for this customer: ' . implode(', ', $links) . '. A type on both cards is refused on save.'];
}
// The "no rates" item is live — it follows + Add Rate / Delete before saving.
$attention = $R::alerts($alerts, '');
if ($alerts === []) {
    $attention = '<ul class="rec-alerts"></ul>';
}
$attention = str_replace('<ul class="rec-alerts">', '<ul class="rec-alerts"><li class="is-warning" x-show="items.length === 0" x-cloak><span>No rates yet — this card prices nothing.' . (can('rates', 'edit') ? ' Use <b>+ Add Rate</b>.' : '') . '</span></li>', $attention)
    . ($alerts === [] ? '<p class="rec-alerts-ok" x-show="items.length > 0">All clear — nothing needs attention.</p>' : '');
$rail[] = $R::card('Needs attention', $attention, ['icon' => 'exclamation-triangle']);

// Customer.
if ($card['customer_id']) {
    $rail[] = $R::card('Customer',
        $R::entity((string) ($card['customer_name'] ?? 'Archived customer'), can('customers', 'view') ? base_url('customers/show') . '?id=' . (int) $card['customer_id'] : '',
            $custActiveLeases !== null ? $custActiveLeases . ' active lease' . ($custActiveLeases === 1 ? '' : 's') : 'Customer',
            \FleetForge\Ui\ModuleHero::initials((string) ($card['customer_name'] ?? '')))
        . (can('customers', 'view') && can('leases', 'view') && $custActiveLeases ? '<div style="margin-top:10px;">' . $R::links([['Their leases', base_url('customers/show') . '?id=' . (int) $card['customer_id'] . '#leases', 'calendar-days']]) . '</div>' : ''),
        ['icon' => 'user-group', 'class' => 'rec-card--accent']);
}

// How the card is used — the lookup order new leases follow.
$rail[] = $R::card('How it is used', $R::kv([
    ['Priced first', $card['customer_id'] ? 'This customer\'s cards' : 'Customer cards'],
    ['Then', $card['customer_id'] ? 'General cards' : ($card['is_default'] ? '<b>This card</b> (default)' : 'General cards — default first')],
    ['Then', 'The unit type\'s default rates'],
    ['Unit-specific', $templateItems > 0 ? $templateItems . ' rate' . ($templateItems === 1 ? '' : 's') . ' override a category' : null],
]) . '<p class="text-secondary" style="margin:8px 0 0;font-size:11.5px;">Leases copy their rates when created, so editing this card never changes existing leases.</p>',
    ['icon' => 'scale']);

$rail[] = $R::card('Record', $R::kv([
    ['Created by', e($card['created_by_name'] ?? '—')],
    ['Created', e(format_datetime($card['created_at']))],
    ['Updated', e(format_datetime($card['updated_at']))],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="Rate card at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

    <!-- ── Delete modal ───────────────────────────────────────────────────── -->
    <div class="modal-backdrop" x-show="deleteModal.open" x-cloak>
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">Delete Rate Card</h3>
                <button class="modal-close-btn" aria-label="Close" @click="deleteModal.open = false">×</button>
            </div>
            <div class="modal-body">
                <p>Permanently delete <strong><?= e($card['name']) ?></strong>?</p>
                <p class="text-secondary" style="font-size:0.875rem;margin-top:8px;">Historical lease rates are unaffected.</p>
                <p class="text-danger" x-show="deleteModal.error" x-text="deleteModal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="deleteModal.open = false">Cancel</button>
                <button class="btn btn-danger btn-sm" :disabled="deleteModal.saving" @click="deleteCard()">
                    <span x-text="deleteModal.saving ? 'Deleting…' : 'Delete'"></span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_RateCardShow() {
    const initial = <?= json_encode([
        'id'            => $card['id'],
        'name'          => $card['name'],
        'description'   => $card['description'],
        'is_default'    => (bool)$card['is_default'],
        'effective_from'=> $card['effective_from'],
        'effective_to'  => $card['effective_to'],
        'customer_id'   => $card['customer_id'] ? (int)$card['customer_id'] : null,
        'customer_name' => $card['customer_name'],
        'updated_at'    => $card['updated_at'],
    ]) ?>;

    const initialItems = <?= json_encode(array_map(fn($i) => array_merge($i, [
        'editing'          => false,
        '_templateName'    => $i['equipment_template_name'] ?? '',
        '_templateSearch'  => $i['equipment_template_name'] ?? '',
        '_templateResults' => [],
        '_templateOpen'    => false,
    ]), $items)) ?>;

    // S-EQTAX: slug → display label, sourced from the taxonomy (mirrors PHP $categoryLabels).
    const CATEGORY_LABELS = <?= json_encode($categoryLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    return {
        form:          { ...initial },
        formSnapshot:  { ...initial },
        items:         initialItems.map(i => ({...i})),
        itemsSnapshot: initialItems.map(i => ({...i})),

        editMode:    false,
        saving:      false,
        saveSuccess: false,
        globalError: null,
        deleteModal: { open: false, saving: false, error: '' },

        init() {},

        categoryLabel(slug) {
            if (!slug) return '—';
            return CATEGORY_LABELS[slug] || slug.replace(/_/g, ' ');
        },

        cancelEdit() {
            this.form     = { ...this.formSnapshot };
            this.editMode = false;
            this.globalError = null;
        },

        async saveCard() {
            this.globalError = null;
            this.saveSuccess = false;

            if (!this.form.name?.trim()) { this.globalError = 'Rate card name is required.'; return; }
            if (!this.form.effective_from) { this.globalError = 'Effective from date is required.'; return; }
            if (this.form.effective_to && this.form.effective_to < this.form.effective_from) {
                this.globalError = 'End date must be on or after the start date.'; return;
            }

            this.saving = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/update') ?>', {
                    id:             this.form.id,
                    updated_at:     this.form.updated_at,
                    name:           this.form.name.trim(),
                    description:    this.form.description?.trim() || null,
                    effective_from: this.form.effective_from,
                    effective_to:   this.form.effective_to || null,
                    is_default:     this.form.is_default ? 1 : 0,
                    customer_id:    this.form.customer_id || null,
                });
                if (!r.success) {
                    if (r.error?.code === 'STALE_DATA') {
                        this.globalError = 'This rate card was modified by another user. Please reload and try again.';
                    } else {
                        this.globalError = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Save failed.');
                    }
                    return;
                }
                this.form.updated_at    = r.data.updated_at;
                this.form.customer_id   = r.data.customer_id ?? null;
                this.formSnapshot       = { ...this.form };
                this.editMode           = false;
                this.saveSuccess        = true;
                setTimeout(() => this.saveSuccess = false, 3000);
            } catch (e) {
                this.globalError = e.message || 'Save failed.';
            } finally {
                this.saving = false;
            }
        },

        addItem() {
            this.items.push({
                id: null, equipment_type: '', equipment_template_id: null,
                _templateName: '', _templateSearch: '', _templateResults: [], _templateOpen: false,
                daily_rate: '', weekly_rate: '',
                monthly_rate: '', mileage_rate: '', mileage_unit: 'km', hourly_rate: '', gps_price: '',
                minimum_days: '', // S-LEASE-MIN-DAYS: short-lease floor (blank = none)
                currency: 'CAD',
                notes: '', editing: true,
            });
        },

        removeItem(idx) { this.items.splice(idx, 1); },

        // S-RATE-CARD-TEMPLATE-ITEM: template search / pick / clear
        async searchTemplates(idx, query) {
            const item = this.items[idx];
            if (!item) return;
            if (!query || query.length < 1 || !item.equipment_type) {
                item._templateResults = [];
                item._templateOpen    = false;
                return;
            }
            try {
                const params = new URLSearchParams({ search: query, category: item.equipment_type, per_page: 10 });
                const r = await FF_Api.get(FF_Api.url('/api/v1/equipment/templates/index.php') + '?' + params.toString());
                if (r.success) {
                    item._templateResults = r.data.items || [];
                    item._templateOpen    = item._templateResults.length > 0;
                }
            } catch (e) { /* silent */ }
        },

        pickTemplate(idx, tmpl) {
            const item               = this.items[idx];
            if (!item) return;
            item.equipment_template_id = tmpl.id;
            item._templateName       = tmpl.name;
            item._templateSearch     = tmpl.name;
            item._templateOpen       = false;
            if (!item.equipment_type && tmpl.category) item.equipment_type = tmpl.category;
        },

        clearTemplate(idx) {
            const item               = this.items[idx];
            if (!item) return;
            item.equipment_template_id = null;
            item._templateName       = '';
            item._templateSearch     = '';
            item._templateResults    = [];
            item._templateOpen       = false;
        },

        async deleteItem(idx) {
            this.items.splice(idx, 1);
            await this.saveItems();
        },

        isBlankItem(item) {
            return !item.equipment_type
                && (item.daily_rate   === '' || item.daily_rate   == null)
                && (item.weekly_rate  === '' || item.weekly_rate  == null)
                && (item.monthly_rate === '' || item.monthly_rate == null)
                && (item.mileage_rate === '' || item.mileage_rate == null)
                && (item.hourly_rate  === '' || item.hourly_rate  == null)
                && (item.gps_price    === '' || item.gps_price    == null);
        },

        cancelItemEdit(idx) {
            const snapshot = this.itemsSnapshot[idx];
            if (snapshot) this.items[idx] = { ...snapshot, editing: false };
            else          this.items.splice(idx, 1);
        },

        async saveItems() {
            this.globalError = null;
            this.saveSuccess = false;

            // Validate — S-RATE-CARD-TEMPLATE-ITEM: dedup by "t:{id}" or "c:{type}"
            const seen     = new Set();
            const problems = [];
            for (let i = 0; i < this.items.length; i++) {
                const item = this.items[i];
                const num  = i + 1;
                if (this.isBlankItem(item)) continue;
                if (!item.equipment_type) { problems.push(`Item ${num}: select an equipment category.`); continue; }
                const key = item.equipment_template_id ? `t:${item.equipment_template_id}` : `c:${item.equipment_type}`;
                if (seen.has(key)) {
                    problems.push(`Item ${num}: '${item.equipment_template_id ? item._templateName : this.categoryLabel(item.equipment_type)}' listed more than once.`);
                    continue;
                }
                seen.add(key);
                const fields = { daily_rate: 'Daily', weekly_rate: 'Weekly', monthly_rate: 'Monthly', mileage_rate: 'Mileage', hourly_rate: 'Hourly', gps_price: 'GPS rate' };
                for (const [f, label] of Object.entries(fields)) {
                    const raw = item[f];
                    if (raw === '' || raw == null) continue;
                    const n = parseFloat(raw);
                    if (isNaN(n))  problems.push(`Item ${num}: ${label} must be a number.`);
                    else if (n < 0) problems.push(`Item ${num}: ${label} cannot be negative.`);
                }
            }
            if (problems.length) { this.globalError = problems.join(' '); return; }

            this.saving = true;
            try {
                const itemPayload = this.items.filter(i => !this.isBlankItem(i)).map(item => ({
                    equipment_type:        item.equipment_type,
                    equipment_template_id: item.equipment_template_id || null,
                    daily_rate:            item.daily_rate   || null,
                    weekly_rate:           item.weekly_rate  || null,
                    monthly_rate:          item.monthly_rate || null,
                    mileage_rate:          item.mileage_rate || null,
                    mileage_unit:          item.mileage_unit,
                    hourly_rate:           item.hourly_rate  || null,
                    gps_price:             item.gps_price    || null,
                    // S-LEASE-MIN-DAYS: send blank → null, but preserve 0 (don't clobber via ||)
                    minimum_days:          (item.minimum_days === '' || item.minimum_days === null || item.minimum_days === undefined) ? null : item.minimum_days,
                    currency:              item.currency,
                    notes:                 item.notes || null,
                }));

                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/update') ?>', {
                    id:         this.form.id,
                    updated_at: this.form.updated_at,
                    items:      itemPayload,
                });

                if (!r.success) {
                    if (r.error?.code === 'STALE_DATA') {
                        this.globalError = 'This rate card was modified by another user. Please reload and try again.';
                    } else {
                        this.globalError = r.error?.fields ? Object.values(r.error.fields).join(' ') : (r.error?.message || 'Save failed.');
                    }
                    return;
                }

                this.form.updated_at = r.data.updated_at;
                this.formSnapshot    = { ...this.form };
                this.items           = this.items.map(i => ({...i, editing: false}));
                this.itemsSnapshot   = this.items.map(i => ({...i}));
                this.saveSuccess     = true;
                setTimeout(() => this.saveSuccess = false, 3000);
            } catch (e) {
                this.globalError = e.message || 'Save failed.';
            } finally {
                this.saving = false;
            }
        },

        async deleteCard() {
            this.deleteModal.saving = true;
            this.deleteModal.error  = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/rate_cards/delete') ?>', { id: this.form.id, updated_at: this.form.updated_at });
                if (!r.success) { this.deleteModal.error = r.error?.message || 'Delete failed.'; this.deleteModal.saving = false; return; }
                window.location = '<?= base_url('rates') ?>';
            } catch (e) {
                this.deleteModal.error  = e.message || 'Delete failed.';
                this.deleteModal.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
