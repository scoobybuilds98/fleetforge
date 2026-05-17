<?php
declare(strict_types=1);

/**
 * includes/legal_footer.php
 *
 * Closes every public /legal/* page started by legal_header.php.
 * Renders a horizontal link list of all 6 legal pages so visitors
 * can navigate between them without bouncing through the top nav.
 *
 * Pairs with: includes/legal_header.php
 * Session:    S-LEGAL-FOOTER-COMMERCIAL
 */
$_lc = legal_config();
?>
</main>
<div class="legal-bottom">
    <div class="legal-footer-nav">
        <?php foreach ($_lc['pages'] as $_slug => $_pg): ?>
            <a href="<?= e(base_url($_pg['url'])) ?>"><?= e($_pg['title']) ?></a>
        <?php endforeach; ?>
        <a href="mailto:<?= e($_lc['company']['email_support']) ?>">Contact Support</a>
    </div>
    <p class="legal-copyright">
        &copy; <?= date('Y') ?> <?= e($_lc['company']['legal_name']) ?>.
        All rights reserved. A software by <?= e($_lc['company']['brand_name']) ?>.
    </p>
</div>
</body>
</html>
