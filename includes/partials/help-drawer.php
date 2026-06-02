<?php
declare(strict_types=1);

/**
 * FleetForge — Global Help Drawer (S-HELP-DRAWER-PUSH-AND-PERSIST)
 *
 * @file        includes/partials/help-drawer.php
 * @description Right-side help panel. Desktop: PUSHES/squeezes the main
 *              content (no backdrop, fully interactive). Mobile: full-screen
 *              overlay with backdrop. Open state persists per tab via
 *              sessionStorage and is restored on page navigation.
 *
 *              Open triggers:
 *                window.dispatchEvent(new CustomEvent('ff-help-drawer', {detail:{slug:'...'}}))
 *              Auto-restore on load via sessionStorage + window.FF_HELP_SLUG
 *              (declared by module pages before including header.php).
 *
 *              CSS push hook: sets/removes `data-help-drawer-open` on <body>.
 *              CSS handles the margin-right on .app-main at ≥1024px.
 *
 * @depends     Alpine.js, /help/fragment endpoint (app/admin/help/fragment.php)
 * @session     S-HELP-DRAWER-PUSH-AND-PERSIST
 */
?>
<!-- ============================================================
     GLOBAL HELP DRAWER
     Desktop: push layout (no backdrop). Mobile: overlay + backdrop.
     ============================================================ -->
<div id="ff-help-drawer"
     x-data="FF_HelpDrawer()"
     x-init="init()"
     @ff-help-drawer.window="open($event.detail.slug)"
     @keydown.escape.window="if (isOpen) close()"
     x-cloak>

    <!-- Backdrop — visible on mobile only (CSS hides on desktop) -->
    <div x-show="isOpen"
         class="help-drawer-backdrop"
         x-transition:enter="help-drawer-bd-enter"
         x-transition:enter-start="help-drawer-bd-from"
         x-transition:enter-end="help-drawer-bd-to"
         x-transition:leave="help-drawer-bd-leave"
         x-transition:leave-start="help-drawer-bd-to"
         x-transition:leave-end="help-drawer-bd-from"
         @click="close()"
         aria-hidden="true"></div>

    <!-- Panel -->
    <aside x-show="isOpen"
           class="help-drawer-panel"
           x-transition:enter="help-drawer-panel-enter"
           x-transition:enter-start="help-drawer-panel-from"
           x-transition:enter-end="help-drawer-panel-to"
           x-transition:leave="help-drawer-panel-leave"
           x-transition:leave-start="help-drawer-panel-to"
           x-transition:leave-end="help-drawer-panel-from"
           role="complementary"
           aria-label="How this works"
           @click.stop>

        <!-- Header -->
        <div class="help-drawer-header">
            <div class="help-drawer-header-inner">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                     width="16" height="16" aria-hidden="true" style="flex-shrink:0;color:var(--color-primary);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
                </svg>
                <span class="help-drawer-title" x-text="drawerTitle || 'How This Works'"></span>
            </div>
            <button type="button"
                    class="help-drawer-close btn btn-ghost btn-xs"
                    @click="close()"
                    aria-label="Collapse help panel"
                    title="Collapse">
                <!-- Chevron-right — indicates the panel collapses back to the right -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
            </button>
        </div>

        <!-- Body (scrollable) -->
        <div class="help-drawer-body">

            <div x-show="loading" class="help-drawer-loading" aria-busy="true" aria-label="Loading guide…">
                <div class="skeleton" style="height:22px;width:55%;border-radius:4px;margin-bottom:16px;"></div>
                <div class="skeleton" style="height:14px;width:90%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:80%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:85%;border-radius:3px;margin-bottom:24px;"></div>
                <div class="skeleton" style="height:20px;width:45%;border-radius:4px;margin-bottom:14px;"></div>
                <div class="skeleton" style="height:14px;width:95%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:75%;border-radius:3px;margin-bottom:8px;"></div>
            </div>

            <div x-show="!loading && loadError" class="help-drawer-error">
                <p x-text="loadError"></p>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px;" @click="reload()">Try again</button>
            </div>

            <div x-show="!loading && !loadError"
                 class="help-content help-drawer-content"
                 x-html="contentHtml"></div>

        </div>

        <!-- Footer -->
        <div class="help-drawer-footer" x-show="!loading && !loadError && currentSlug">
            <a :href="'<?= e(base_url('help')) ?>/' + currentSlug"
               class="help-drawer-full-link"
               target="_blank"
               rel="noopener">
                Open full guide ↗
            </a>
        </div>

    </aside>

</div>

<script>
function FF_HelpDrawer() {
    return {
        isOpen:      false,
        loading:     false,
        loadError:   null,
        contentHtml: '',
        drawerTitle: '',
        currentSlug: '',

        _STORAGE_KEY: 'ff_help_drawer',
        _BREAKPOINT:  1024,  // px — push on desktop, overlay on mobile

        get isDesktop() {
            return window.innerWidth >= this._BREAKPOINT;
        },

        init() {
            // Restore persisted state — use $nextTick so the slide-in
            // transition plays even on a restored page load.
            try {
                const stored = JSON.parse(sessionStorage.getItem(this._STORAGE_KEY) || 'null');
                if (stored && stored.open) {
                    // Current page's slug takes priority over the stored slug
                    // (so /customers/create correctly shows the customers guide).
                    const slug = (window.FF_HELP_SLUG || stored.slug || '').trim();
                    if (slug) {
                        this.$nextTick(() => this.open(slug));
                    }
                }
            } catch (_) { /* sessionStorage unavailable — continue without restore */ }
        },

        async open(slug) {
            if (!slug) return;

            this.currentSlug = slug;
            this.isOpen      = true;
            this.loading     = true;
            this.loadError   = null;
            this.contentHtml = '';
            this.drawerTitle = '';

            // Desktop: push main content via CSS (body attribute → margin-right).
            // Mobile: overlay — prevent body scroll.
            document.body.setAttribute('data-help-drawer-open', '1');
            if (!this.isDesktop) {
                document.body.style.overflow = 'hidden';
            }

            // Measure the sticky page-header so the panel starts below it
            // (the header spans full width above the drawer, like the topbar).
            this._syncHeaderOffset();

            this._persist(true, slug);

            try {
                const url = '<?= e(base_url('help/fragment')) ?>?slug=' + encodeURIComponent(slug);
                const res = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!res.ok) {
                    this.loadError = 'Could not load the guide. Please try again.';
                    return;
                }

                const data = await res.json();
                this.drawerTitle = data.title || 'How This Works';
                this.contentHtml = data.found ? (data.html || '') : this._comingSoon(slug);

            } catch (_) {
                this.loadError = 'Network error loading the guide. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        close() {
            this.isOpen = false;
            document.body.removeAttribute('data-help-drawer-open');
            document.body.style.overflow = '';
            document.documentElement.style.setProperty('--ff-help-drawer-offset', '0px');
            this._persist(false, this.currentSlug);
        },

        // Sets --ff-help-drawer-offset to the sticky page-header's height so
        // the drawer panel starts beneath it. Falls back to 0 when the page
        // has no page-header (drawer then sits directly below the topbar).
        _syncHeaderOffset() {
            const ph = document.querySelector('.page-content .page-header');
            const h  = ph ? Math.round(ph.getBoundingClientRect().height) : 0;
            document.documentElement.style.setProperty('--ff-help-drawer-offset', h + 'px');
        },

        reload() {
            if (this.currentSlug) this.open(this.currentSlug);
        },

        _persist(open, slug) {
            try {
                sessionStorage.setItem(this._STORAGE_KEY, JSON.stringify({ open, slug: slug || '' }));
            } catch (_) { /* sessionStorage blocked — in-memory only */ }
        },

        _comingSoon(slug) {
            const name = slug.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return '<p class="text-secondary" style="padding:4px 0;">The guide for <strong>'
                + name + '</strong> is being written. Check back soon.</p>';
        },
    };
}
</script>
