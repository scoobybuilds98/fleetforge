<?php declare(strict_types=1); ?>

        <!-- ── Page footer ── -->
        <footer style="
            margin-top: auto;
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            font-size: 0.75rem;
            color: var(--text-muted);
        ">
            <span>&copy; <?= date('Y') ?> Avi Nanda. All rights reserved.</span>
            <span>FleetForge <?= e(FF_VERSION) ?></span>
        </footer>

        </main>
        <!-- /page-content -->

    </div>
    <!-- /app-main -->

</div>
<!-- /app-layout -->

<!-- ============================================================
     TOAST CONTAINER — fixed top-right, managed by FF_Toast in app.js
     Position: top-right, 16px from edges (per design spec §5)
     ============================================================ -->
<div id="ff-toast-container"
     role="region"
     aria-live="polite"
     aria-label="Notifications"
     aria-atomic="false">
</div>

<!-- ============================================================
     GLOBAL SEARCH MODAL — triggered by ⌘K / Ctrl+K
     Populated and managed by FF_Search in app.js.
     ============================================================ -->
<div id="ff-search-modal"
     class="search-modal"
     role="dialog"
     aria-modal="true"
     aria-label="Global search"
     x-data="{ open: false }"
     x-show="open"
     x-cloak
     @keydown.escape.window="open = false"
     @ff-search-open.window="open = true; $nextTick(() => $refs.searchInput.focus())"
     @ff-search-close.window="open = false">

    <!-- Overlay -->
    <div class="search-overlay" @click="open = false" aria-hidden="true"></div>

    <!-- Search panel -->
    <div class="search-panel" @click.stop>

        <div class="search-input-wrap">
            <?= heroicon('magnifying-glass', 'search-panel-icon') ?>
            <input type="search"
                   id="ff-search-input"
                   x-ref="searchInput"
                   class="search-input"
                   placeholder="Search customers, equipment, leases, invoices…"
                   autocomplete="off"
                   spellcheck="false"
                   aria-autocomplete="list"
                   aria-controls="ff-search-results"
                   maxlength="100">
            <kbd class="search-esc-hint" aria-hidden="true">ESC</kbd>
        </div>

        <!-- Results area — populated by FF_Search in app.js -->
        <div id="ff-search-results"
             class="search-results"
             role="listbox"
             aria-label="Search results">
            <!-- Populated dynamically -->
        </div>

        <!-- Recent searches — shown on focus with empty input -->
        <div id="ff-search-recent"
             class="search-recent"
             aria-label="Recent searches">
            <!-- Populated from localStorage by FF_Search -->
        </div>

    </div>
</div>

<!-- ============================================================
     CONFIRM MODAL — generic confirmation dialog
     Used for delete/void/cancel actions across all modules.
     Triggered via FF_Confirm.show({ title, message, onConfirm })
     ============================================================ -->
<div id="ff-confirm-modal"
     class="modal-overlay"
     role="dialog"
     aria-modal="true"
     aria-labelledby="ff-confirm-title"
     x-data="FF_ConfirmModal()"
     x-show="open"
     x-cloak
     @ff-confirm.window="show($event.detail)"
     @keydown.escape.window="cancel()">

    <div class="modal modal-sm" @click.stop>
        <div class="modal-header">
            <h2 class="modal-title" id="ff-confirm-title" x-text="title"></h2>
        </div>
        <div class="modal-body">
            <p x-text="message" class="text-secondary"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-md" @click="cancel()">Cancel</button>
            <button class="btn btn-md"
                    :class="dangerMode ? 'btn-danger' : 'btn-primary'"
                    @click="confirm()"
                    x-text="confirmLabel">
            </button>
        </div>
    </div>

    <!-- Overlay click cancels -->
    <div class="modal-backdrop" @click="cancel()" aria-hidden="true"></div>
</div>

<!-- ============================================================
     CDN SCRIPTS
     Order: ApexCharts → app.js → Alpine.js
     Alpine must be last so it discovers all x-data in the DOM.
     ============================================================ -->

<!-- ApexCharts v3 (pinned) -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js"></script>

<!-- FleetForge application JS -->
<!-- D27: asset_url() has no /fleetforge prefix — assets served from public/ root under Herd -->
<script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<!-- Alpine.js v3 — loaded last, defer ensures it initialises after DOM is ready -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

</body>
</html>
