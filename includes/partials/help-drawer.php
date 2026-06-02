<?php
declare(strict_types=1);

/**
 * FleetForge — Global Help Drawer (S-HELP-DRAWER-TUTORIAL-REWORK)
 *
 * @file        includes/partials/help-drawer.php
 * @description Right-side slide-in drawer for in-module help guides.
 *              Included once from includes/footer.php so any page can open it.
 *              Triggered via: window.dispatchEvent(new CustomEvent('ff-help-drawer', {detail:{slug:'...'}}))
 *              Content is fetched on demand from /help/fragment?slug=... (bare HTML, no layout).
 *              The drawer's Alpine component is self-contained here.
 *
 * @depends     app.js (FF_Api, FF_Toast), Alpine.js, HelpRenderer, /help/fragment endpoint
 * @session     S-HELP-DRAWER-TUTORIAL-REWORK
 */
?>
<!-- ============================================================
     GLOBAL HELP DRAWER — opened via window.dispatchEvent(new CustomEvent('ff-help-drawer', ...))
     ============================================================ -->
<div id="ff-help-drawer"
     x-data="FF_HelpDrawer()"
     x-init="init()"
     @ff-help-drawer.window="open($event.detail.slug)"
     @keydown.escape.window="if (isOpen) close()"
     x-cloak>

    <!-- Backdrop -->
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
           role="dialog"
           aria-modal="true"
           aria-label="How this works"
           @click.stop>

        <!-- Header (sticky) -->
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
                    aria-label="Close help drawer">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Body (scrollable) -->
        <div class="help-drawer-body">

            <!-- Loading skeleton -->
            <div x-show="loading" class="help-drawer-loading" aria-busy="true" aria-label="Loading guide…">
                <div class="skeleton" style="height:22px;width:55%;border-radius:4px;margin-bottom:16px;"></div>
                <div class="skeleton" style="height:14px;width:90%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:80%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:85%;border-radius:3px;margin-bottom:24px;"></div>
                <div class="skeleton" style="height:20px;width:45%;border-radius:4px;margin-bottom:14px;"></div>
                <div class="skeleton" style="height:14px;width:95%;border-radius:3px;margin-bottom:8px;"></div>
                <div class="skeleton" style="height:14px;width:75%;border-radius:3px;margin-bottom:8px;"></div>
            </div>

            <!-- Error -->
            <div x-show="!loading && loadError" class="help-drawer-error">
                <p x-text="loadError"></p>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px;" @click="reload()">Try again</button>
            </div>

            <!-- Rendered guide content -->
            <div x-show="!loading && !loadError"
                 class="help-content help-drawer-content"
                 x-html="contentHtml"></div>

        </div>

        <!-- Footer: link to full-page guide -->
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

        init() {
            // Nothing to preload — content fetched on first open per slug.
        },

        async open(slug) {
            if (!slug) return;

            this.currentSlug = slug;
            this.isOpen      = true;
            this.loading     = true;
            this.loadError   = null;
            this.contentHtml = '';
            this.drawerTitle = '';

            // Prevent background scroll while drawer is open
            document.body.style.overflow = 'hidden';

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
                this.drawerTitle = data.title  || 'How This Works';
                this.contentHtml = data.found  ? (data.html || '') : this._comingSoon(slug);

            } catch (_) {
                this.loadError = 'Network error loading the guide. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        reload() {
            if (this.currentSlug) this.open(this.currentSlug);
        },

        close() {
            this.isOpen = false;
            document.body.style.overflow = '';
        },

        _comingSoon(slug) {
            const name = slug.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return '<p class="text-secondary" style="padding:4px 0;">The guide for <strong>'
                + name + '</strong> is being written. Check back soon.</p>';
        },
    };
}
</script>
