<?php
declare(strict_types=1);

/**
 * FleetForge — dashboard work-panel list (S-DASHBOARD-REDESIGN)
 *
 * @file        includes/partials/dashboard-list.php
 * @description One list body for the dashboard's work panels (Receivables,
 *              Leases, Reservations). Rows are pre-shaped by FF_Dashboard
 *              .buildLists() into one common form — {key, href, av, tone, id,
 *              pill, pillText, who, sub, amt, meta} — so a single template
 *              renders every list and no function runs inside x-for (the
 *              batch-invoicing scale trap). Amounts are blank, not "$NaN",
 *              when the API has redacted them for the viewer's role.
 *
 *              Expects $listKey: 'money' | 'leases' (tabbed — the list is
 *              lists.<key>[tab.<key>]) or 'reservations' (single list).
 *
 * @session     S-DASHBOARD-REDESIGN
 */

$listKey = in_array($listKey ?? '', ['money', 'leases', 'reservations'], true) ? $listKey : 'reservations';
$expr    = $listKey === 'reservations' ? 'lists.reservations' : "lists.{$listKey}[tab.{$listKey}]";
$empty   = $listKey === 'reservations' ? "'No upcoming reservations.'" : "emptyText.{$listKey}[tab.{$listKey}]";
?>
<div class="dash-list" role="<?= $listKey === 'reservations' ? 'list' : 'tabpanel' ?>">
    <template x-if="!tablesLoaded && !tablesError">
        <div class="dash-list-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
    </template>
    <template x-if="tablesError">
        <div class="dash-empty">Could not load this list. <button type="button" class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button></div>
    </template>
    <template x-if="tablesLoaded && <?= $expr ?>.length === 0">
        <div class="dash-empty" x-text="<?= $empty ?>"></div>
    </template>
    <template x-for="row in (tablesLoaded ? <?= $expr ?> : [])" :key="row.key">
        <a class="dash-item" :href="row.href">
            <span class="dash-item-av" :class="'dash-tone--' + row.tone" x-text="row.av" aria-hidden="true"></span>
            <span class="dash-item-main">
                <span class="dash-item-top">
                    <span class="dash-item-id" x-text="row.id"></span>
                    <span class="cc-pill" :class="'cc-pill--' + row.pill" x-text="row.pillText"></span>
                </span>
                <span class="dash-item-who" x-text="row.who"></span>
                <span class="dash-item-sub" x-show="row.sub" x-text="row.sub"></span>
            </span>
            <span class="dash-item-side">
                <span class="dash-item-amt" x-show="row.amt" x-text="row.amt"></span>
                <span class="dash-item-meta" x-text="row.meta"></span>
            </span>
        </a>
    </template>
</div>
