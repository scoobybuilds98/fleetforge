<?php
declare(strict_types=1);

/**
 * includes/partials/rates-line-editor.php
 *
 * The editable price lines of a rate card (S-RATES-MODULE) — one block per
 * equipment line with labelled inputs that wrap to the space available, so
 * the eight prices of a line never scroll sideways (the old wide table did
 * beside a rail / summary panel).
 *
 * Used by app/admin/rates/create.php and app/admin/rates/show.php (edit mode).
 * Expects an Alpine scope built on FF_Rates.gridMixin() (public/assets/js/
 * rates.js): lines, stdFor(), isChanged(), removeLine(), fillFromStandard().
 *
 * Optional PHP variable set before the include:
 *   $rtEditorLeaseHelp bool  — show "Use their lease prices" (the scope must
 *                              also provide leasePricesFor() / useLeasePrices())
 *   $rtEditorVs        bool  — show the "vs standard" note (customer cards)
 *
 * @session S-RATES-MODULE
 */

$rtEditorLeaseHelp = !empty($rtEditorLeaseHelp);
$rtEditorVs        = !isset($rtEditorVs) || $rtEditorVs;
$rtTrash           = \FleetForge\Sop\SopIcons::svg('trash', 'icon-sm');
?>
<div class="rt-lines">
    <template x-for="l in lines" :key="l._k">
        <div class="rt-line">
            <div class="rt-line-head">
                <div class="rt-line-name">
                    <b x-text="l.label"></b>
                    <small x-text="l.scope"></small>
                </div>
                <?php if ($rtEditorVs): ?>
                <template x-if="stdFor(l) && !stdFor(l).range && FF_Rates.vs(l.daily_rate, stdFor(l).daily)">
                    <span class="rt-vs" :class="FF_Rates.vs(l.daily_rate, stdFor(l).daily).cls" x-text="'daily ' + FF_Rates.vs(l.daily_rate, stdFor(l).daily).text"></span>
                </template>
                <template x-if="stdFor(l) && stdFor(l).range && stdFor(l).range.daily">
                    <span class="rt-faint text-sm" x-text="'standard ' + FF_Rates.short(stdFor(l).range.daily[0]) + '–' + FF_Rates.short(stdFor(l).range.daily[1]) + '/day'"></span>
                </template>
                <?php endif; ?>
                <span class="rt-spacer"></span>
                <?php if ($rtEditorLeaseHelp): ?>
                <button type="button" class="btn btn-link btn-sm" x-show="leasePricesFor(l)" @click="useLeasePrices(l)">Use their lease prices</button>
                <?php endif; ?>
                <button type="button" class="btn btn-link btn-sm" x-show="stdFor(l) && !stdFor(l).range && (!l.daily_rate || !l.weekly_rate || !l.monthly_rate)" @click="fillFromStandard(l)">Fill blanks from standard</button>
                <select class="form-select rt-mini-select" x-model="l.currency" aria-label="Currency"><option>CAD</option><option>USD</option></select>
                <button type="button" class="rt-icon-btn" @click="removeLine(l)" :aria-label="'Remove ' + l.label" title="Remove this line"><?= $rtTrash ?></button>
            </div>
            <div class="rt-line-fields">
                <label class="rt-field">
                    <span>Daily</span>
                    <span class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'daily_rate') }" x-model="l.daily_rate" :placeholder="stdFor(l) && stdFor(l).daily ? stdFor(l).daily : ''"></span>
                </label>
                <label class="rt-field">
                    <span>Weekly</span>
                    <span class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'weekly_rate') }" x-model="l.weekly_rate" :placeholder="stdFor(l) && stdFor(l).weekly ? stdFor(l).weekly : ''"></span>
                </label>
                <label class="rt-field">
                    <span>Monthly</span>
                    <span class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'monthly_rate') }" x-model="l.monthly_rate" :placeholder="stdFor(l) && stdFor(l).monthly ? stdFor(l).monthly : ''"></span>
                </label>
                <div class="rt-field rt-field--wide">
                    <span>Distance</span>
                    <span style="display:flex;gap:4px;">
                        <span class="rt-money" style="flex:1;min-width:0;"><span>$</span><input type="number" min="0" step="0.0001" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'mileage_rate') }" x-model="l.mileage_rate" aria-label="Distance price"></span>
                        <select class="form-select rt-mini-select" x-model="l.mileage_unit" aria-label="Distance unit"><option value="km">/km</option><option value="miles">/mi</option></select>
                    </span>
                </div>
                <label class="rt-field">
                    <span>Engine hours</span>
                    <span class="rt-money"><span>$</span><input type="number" min="0" step="0.0001" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'hourly_rate') }" x-model="l.hourly_rate" placeholder="per hr"></span>
                </label>
                <label class="rt-field">
                    <span>GPS / day</span>
                    <span class="rt-money"><span>$</span><input type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'gps_price') }" x-model="l.gps_price"></span>
                </label>
                <label class="rt-field">
                    <span>Min. days</span>
                    <input type="number" min="0" max="90" step="1" class="form-control form-control-sm" :class="{ 'is-changed': isChanged(l, 'minimum_days') }" style="text-align:right;" x-model="l.minimum_days" placeholder="none">
                </label>
            </div>
        </div>
    </template>
</div>
