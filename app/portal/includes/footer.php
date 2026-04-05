<?php declare(strict_types=1); ?>

        </div>
        <!-- /portal-content -->

        <footer class="portal-footer">
            &copy; <?= date('Y') ?> <?= e(settings_get('company.name', 'FleetForge')) ?>. All rights reserved.
        </footer>

    </div>
    <!-- /portal-main -->

</div>
<!-- /portal-layout -->

<!-- Toast container (shared with admin app.js) -->
<div id="ff-toast-container" role="region" aria-live="polite" aria-label="Notifications" aria-atomic="false"></div>

<!-- CDN Scripts -->
<script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<script>
// Apply stored theme preference on portal pages
(function() {
    try {
        var stored = localStorage.getItem('ff-theme');
        if (stored === 'light' || stored === 'dark') {
            document.documentElement.setAttribute('data-theme', stored);
        }
    } catch(e) {}
})();
</script>

</body>
</html>
