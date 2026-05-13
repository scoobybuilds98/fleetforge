<?php declare(strict_types=1); ?>

        </main>
        <!-- /page-content -->

        <!-- ── Page footer — sticky to viewport bottom, outside <main> so it
             does not scroll away. position:sticky + bottom:0 pins it once the
             user scrolls far enough; on short pages it sits naturally at the
             bottom of the content area. -->
        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> A software by Avi Nanda. All rights reserved.</span>
            <span>FleetForge <?= e(FF_VERSION) ?></span>
        </footer>

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
     or via the Promise helpers FF_Confirm.ask() / FF_Confirm.askText().
     [UI-AUDIT-1:M13] Prompt mode adds a text input for askText().
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
            <!-- [M13] Text input — rendered only when FF_Confirm.askText() is used. -->
            <div x-show="prompt" style="margin-top:12px;">
                <input type="text"
                       class="form-control"
                       data-ff-confirm-input
                       x-model="promptValue"
                       :placeholder="placeholder"
                       @keydown.enter.prevent="confirm()">
            </div>
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

<!-- ── Team Chat Widget (CHAT-1 — floating bubble) ──────────── -->
<?php require_once FF_ROOT . '/includes/partials/chat-widget.php'; ?>

<!--
  ── AI Chat Widget ─────────────────────────────────────────
  DELIBERATELY NOT RENDERED as a floating bubble. Per user request
  (2026-04-09): the bottom-right floating box is reserved for the
  team chat / DM widget ONLY. AI lives in the topbar icon (includes/
  topbar.php) which navigates to the full /ai page. This removes
  all chance of the floating AI widget colliding with the theme
  toggle button (observed: theme click was opening the AI panel).
-->
<?php /* require_once FF_ROOT . '/includes/partials/ai-chat-widget.php'; — see note above */ ?>

<!-- ── Global Email Compose Modal (EMAIL-1) ──────────────────
     Available on every admin page. Open via:
       window.openEmailCompose({customerId, toEmail, ..., templateSlug, entityType, entityId})
     ────────────────────────────────────────────────────────── -->
<?php require_once FF_ROOT . '/includes/partials/email-compose-modal.php'; ?>

<!-- ============================================================
     CDN SCRIPTS
     Order: ApexCharts → app.js → Alpine.js
     Alpine must be last so it discovers all x-data in the DOM.
     ============================================================ -->

<!-- ApexCharts v3.45.1 (pinned, self-hosted via S-PROD-3 2026-05-14) -->
<script src="<?= asset_url('assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>

<!-- FleetForge application JS -->
<!-- D27: asset_url() has no /fleetforge prefix — assets served from public/ root under Herd -->
<script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<!-- Alpine.js v3.15.12 — self-hosted (S-PROD-1A-FIX-5); defer ensures it initialises after DOM is ready -->
<script defer src="<?= asset_url('assets/vendor/alpinejs/cdn.min.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<!-- ============================================================
     SVG Icon Sprite — shared stat-card & UI icons (Heroicons outline 24)
     Referenced via <svg><use href="#icon-name"/></svg>
     ============================================================ -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
    <symbol id="icon-check-circle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
    </symbol>
    <symbol id="icon-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/>
    </symbol>
    <symbol id="icon-wrench" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852Z"/>
    </symbol>
    <symbol id="icon-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H21M3.375 14.25V3.75h8.25m0 0h4.875c.621 0 1.125.504 1.125 1.125v4.875m-6-6v6h6"/>
    </symbol>
    <symbol id="icon-shield-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
    </symbol>
    <symbol id="icon-exclamation-triangle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
    </symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
    </symbol>
    <symbol id="icon-currency-dollar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 6v12m-3-2.818.879.659 1.871.879 3.053.879 2.174 0 3-1.272 3-2.818 0-2.28-3-2.818-6-2.818-2.545 0-3-1.272-3-2.818 0-1.546.826-2.159 1.871-2.818L12 6"/>
    </symbol>
    <symbol id="icon-document-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm-1.5 9.75h-3m5.25 3h-7.5"/>
    </symbol>
    <symbol id="icon-fire" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z"/>
        <path d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z"/>
    </symbol>
    <symbol id="icon-chart-bar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
    </symbol>
    <symbol id="icon-star" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/>
    </symbol>
    <symbol id="icon-building" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
    </symbol>
    <symbol id="icon-trophy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .982-3.172M6.75 3h10.5a.75.75 0 0 1 .75.75v2.25c0 2.9-2.35 5.25-5.25 5.25h-1.5C8.6 11.25 6.25 8.9 6.25 6V3.75A.75.75 0 0 1 6.75 3Z"/>
    </symbol>
    <symbol id="icon-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
    </symbol>
    <symbol id="icon-magnifying-glass" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
    </symbol>
    <symbol id="icon-pencil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>
    </symbol>
    <symbol id="icon-x-circle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
    </symbol>
    <symbol id="icon-clipboard" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z"/>
    </symbol>
    <symbol id="icon-lock-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M13.5 10.5V6.75a4.5 4.5 0 1 1 9 0v3.75M3.75 21.75h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
    </symbol>
    <symbol id="icon-arrow-up-tray" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
    </symbol>
    <symbol id="icon-heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>
    </symbol>
    <symbol id="icon-map-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
        <path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
    </symbol>
    <symbol id="icon-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/>
        <path d="M6 6h.008v.008H6V6Z"/>
    </symbol>
    <symbol id="icon-credit-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
    </symbol>
    <symbol id="icon-arrow-trending-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/>
    </symbol>
    <symbol id="icon-pencil-square" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"/>
    </symbol>
</svg>

</body>
</html>
