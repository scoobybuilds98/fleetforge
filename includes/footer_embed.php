<?php declare(strict_types=1); ?>

        </main>
        <!-- /page-content (embed) -->

        <!-- Toast container — kept so any in-page JS (e.g. inline notes
             save) that calls FF_Toast still has somewhere to render,
             even though the action toolbar itself is hidden in embed
             mode (see $isEmbed in invoices/show.php). -->
        <div id="ff-toast-container"
             role="region"
             aria-live="polite"
             aria-label="Notifications"
             aria-atomic="false">
        </div>

        <!-- ============================================================
             CDN SCRIPTS — same load order as footer.php (Alpine last).
             Search modal, confirm modal, chat widget, compose modal, and
             help drawer are intentionally OMITTED: the embed pane is a
             read-only preview (no action buttons render), so none of
             those globals are reachable, and skipping them keeps the
             iframe payload lighter.
             ============================================================ -->
        <?php // S-PERF-CHARTS — same opt-in gate as footer.php. The embed pane is a
              // read-only invoice preview and draws no charts, so this is normally
              // skipped entirely (saves 522 KB raw / 134 KB gzipped per iframe load). ?>
        <?php if (!empty($pageNeedsCharts)): ?>
        <script src="<?= e(asset_v('assets/vendor/apexcharts/apexcharts.min.js')) ?>"></script>
        <script src="<?= e(asset_v('assets/js/ff-chart-theme.js')) ?>"></script>
        <?php endif; ?>
        <script src="<?= e(asset_v('assets/js/app.js')) ?>"></script>
        <script src="<?= e(asset_v('assets/js/ff-animations.js')) ?>"></script>
        <script defer src="<?= e(asset_v('assets/vendor/alpinejs/cdn.min.js')) ?>"></script>

</body>
</html>
