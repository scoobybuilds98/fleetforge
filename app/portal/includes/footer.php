<?php
declare(strict_types=1);

/**
 * app/portal/includes/footer.php
 *
 * Customer portal footer — commercial 2-column layout with legal links
 * and brand attribution. The portal is customer-facing so the footer
 * carries more weight than the admin footer: product line, legal links
 * grouped by purpose, attribution to Avi Technologies.
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

use FleetForge\Storage\StorageClient;

// Resolve the portal logo with the same fallback chain the login page uses:
//   1) brand.logo_path settings row → signed StorageClient URL
//   2) public/media/login-logo.{svg,png,jpg,jpeg} → asset_url() to the file
//   3) Empty → render the product name as text
$_portalLogoKey = (string) (settings_get('brand.logo_path') ?? '');
$_portalLogoUrl = '';
if ($_portalLogoKey !== '') {
    try { $_portalLogoUrl = StorageClient::url($_portalLogoKey, 3600); }
    catch (\Throwable) { /* fall through to file fallback */ }
}
if ($_portalLogoUrl === '') {
    foreach (['svg', 'png', 'jpg', 'jpeg'] as $_ext) {
        $_candidate = FF_ROOT . '/public/media/login-logo.' . $_ext;
        if (is_file($_candidate)) {
            $_portalLogoUrl = asset_url('media/login-logo.' . $_ext);
            break;
        }
    }
}

$_companyName = settings_get('company.name') ?: legal_config('company.brand_name');
?>

        </div>
        <!-- /portal-content -->

        <footer class="portal-footer">
            <div class="portal-footer-inner">

                <div class="portal-footer-brand">
                    <div class="portal-footer-logo">
                        <?php if ($_portalLogoUrl !== ''): ?>
                            <img src="<?= e($_portalLogoUrl) ?>" alt="<?= e($_companyName) ?>" class="portal-footer-logo-img">
                        <?php else: ?>
                            <span class="portal-footer-name"><?= e($_companyName) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="portal-footer-tagline">
                        Fleet management powered by <?= e(legal_config('company.product_name')) ?>.
                    </p>
                    <p class="portal-footer-sub">
                        A software by <strong>Avi Technologies</strong>
                    </p>
                </div>

                <?php // S-LEGAL-FOOTER-COMMERCIAL: legal links render /legal/terms, /legal/privacy, /legal/aup, /legal/dpa, /legal/security, /legal/cookies ?>
                <div class="portal-footer-links">
                    <div class="portal-footer-col">
                        <h4>Legal</h4>
                        <a href="<?= e(legal_url('terms')) ?>" target="_blank" rel="noopener">Terms of Service</a>
                        <a href="<?= e(legal_url('privacy')) ?>" target="_blank" rel="noopener">Privacy Policy</a>
                        <a href="<?= e(legal_url('aup')) ?>" target="_blank" rel="noopener">Acceptable Use</a>
                        <a href="<?= e(legal_url('dpa')) ?>" target="_blank" rel="noopener">Data Processing</a>
                    </div>
                    <div class="portal-footer-col">
                        <h4>Support</h4>
                        <a href="mailto:<?= e(legal_config('company.email_support')) ?>">Contact Support</a>
                        <a href="<?= e(legal_url('security')) ?>" target="_blank" rel="noopener">Security</a>
                        <a href="<?= e(legal_url('cookies')) ?>" target="_blank" rel="noopener">Cookie Policy</a>
                    </div>
                </div>

            </div>

            <div class="portal-footer-bottom">
                <span>&copy; <?= date('Y') ?> <?= e($_companyName) ?>. All rights reserved.</span>
                <span><?= e(legal_config('company.product_name')) ?> <?= e(FF_VERSION) ?></span>
            </div>
        </footer>

    </div>
    <!-- /portal-main -->

</div>
<!-- /portal-layout -->

<!-- Toast container (shared with admin app.js) -->
<div id="ff-toast-container" role="region" aria-live="polite" aria-label="Notifications" aria-atomic="false"></div>

<!-- CDN Scripts -->
<script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<script defer src="<?= asset_url('assets/vendor/alpinejs/cdn.min.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

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
<?php unset($_portalLogoKey, $_portalLogoUrl, $_companyName); ?>
