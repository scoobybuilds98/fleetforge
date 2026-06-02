<?php
declare(strict_types=1);

/**
 * FleetForge — Help Center Index
 *
 * @file        app/admin/help/index.php
 * @description Auto-discovers guides from docs/help/*.md and lists them in a card grid.
 *              Accessible to all authenticated users (no role gate — guides are for all staff).
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, lib/Help/HelpRenderer.php
 * @session     S-HELP-SYSTEM-FOUNDATION
 */

// dirname(__DIR__, 3): app/admin/help/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$pageTitle = 'Help Center';
require_once FF_ROOT . '/includes/header.php';

$guides = \FleetForge\Help\HelpRenderer::listGuides();
?>

<div class="page-header">
    <h1 class="page-header-title h4">Help Center</h1>
</div>

<p class="text-secondary" style="margin-bottom:24px;max-width:640px;">
    Step-by-step guides for every FleetForge module — plain-language overviews for daily
    operations, plus technical detail for anyone who wants to understand what's happening
    under the hood.
</p>

<?php if (empty($guides)): ?>
<div class="card" style="max-width:560px;">
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No guides available yet</p>
            <p class="empty-state-text">Module guides are being added progressively. Check back soon.</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="help-guide-grid">
    <?php foreach ($guides as $guide): ?>
    <a href="<?= e(base_url('help/' . $guide['slug'])) ?>" class="card help-guide-card" style="text-decoration:none;display:block;">
        <div class="card-body" style="padding:18px 20px;">
            <div class="help-guide-title"><?= e($guide['title'] ?: ucwords(str_replace('-', ' ', $guide['slug']))) ?></div>
            <?php if ($guide['description']): ?>
            <p class="help-guide-desc text-secondary"><?= e($guide['description']) ?></p>
            <?php endif; ?>
            <span class="help-guide-link">Read guide →</span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
