<?php declare(strict_types=1);

/**
 * includes/partials/pagination-bar.php
 *
 * Reusable list-table pagination bar. Renders Page-count + First/Prev/Next/Last
 * plus a "jump to page" number input so the user can go straight to any page
 * (essential once a list has hundreds/thousands of pages — clicking Next N times
 * doesn't scale). Used at BOTH the top and bottom of the leases + invoices tables.
 *
 * Required PHP variable in scope before include (use `require`, not
 * `require_once` — the bar renders twice per page):
 *   $position — 'top' | 'bottom' (top gets the .pagination-top border modifier)
 *
 * Required Alpine scope where this is included (both FF_Leases + FF_Invoices
 * provide these):
 *   pagination — { page:int, total_pages:int, has_more:bool }
 *   loading    — bool
 *   goToPage(p)      — clamps p to [1, total_pages] and reloads
 *   jumpToPage($event) — reads/clamps the input value and calls goToPage
 *
 * Rendered only when there is more than one page (same guard as before).
 */

$ffPagClass = (($position ?? 'bottom') === 'top') ? 'pagination pagination-top' : 'pagination';
?>
<template x-if="!loading && pagination.total_pages > 1">
    <div class="<?= $ffPagClass ?>">
        <div class="pagination-controls">
            <button class="page-btn"
                    :disabled="pagination.page <= 1"
                    @click="goToPage(1)"
                    aria-label="First page" title="First page">« First</button>
            <button class="page-btn"
                    :disabled="pagination.page <= 1"
                    @click="goToPage(pagination.page - 1)">← Prev</button>
        </div>

        <div class="pagination-jump">
            <label class="pagination-info">Page
                <input type="number" class="pagination-input"
                       min="1" :max="pagination.total_pages"
                       :value="pagination.page"
                       @change="jumpToPage($event)"
                       @keydown.enter.prevent="jumpToPage($event)"
                       aria-label="Go to page">
            </label>
            <span class="pagination-info" x-text="'of ' + pagination.total_pages"></span>
        </div>

        <div class="pagination-controls">
            <button class="page-btn"
                    :disabled="!pagination.has_more"
                    @click="goToPage(pagination.page + 1)">Next →</button>
            <button class="page-btn"
                    :disabled="!pagination.has_more"
                    @click="goToPage(pagination.total_pages)"
                    aria-label="Last page" title="Last page">Last »</button>
        </div>
    </div>
</template>
