<?php
declare(strict_types=1);

/**
 * FleetForge — Help Guide Renderer
 *
 * @file        app/admin/help/_guide.php
 * @description Renders a single help guide from docs/help/{slug}.md.
 *              Used as a router fallback for any /help/{slug} path that does not
 *              have its own PHP file — enables "guide coming soon" graceful degradation
 *              so module "How this works" buttons can be wired before their guide exists.
 *              Accessible to all authenticated users (no role gate).
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, lib/Help/HelpRenderer.php
 * @session     S-HELP-SYSTEM-FOUNDATION
 */

// dirname(__DIR__, 3): app/admin/help/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// Extract the slug from the URL path: /fleetforge/help/{slug} → slug
$_reqPath  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_helpBase = rtrim(FF_BASE_PATH, '/') . '/help/';
$_slug     = '';
if (str_starts_with($_reqPath, $_helpBase)) {
    $_slug = rtrim(substr($_reqPath, strlen($_helpBase)), '/');
}
// Sanitise — only lowercase alphanumeric, hyphens, underscores allowed
$_slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_slug));

$_guide = \FleetForge\Help\HelpRenderer::render($_slug);

$pageTitle = $_guide['found']
    ? strip_tags($_guide['title']) . ' — Help'
    : ucwords(str_replace('-', ' ', $_slug)) . ' — Guide Coming Soon';

require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('help')) ?>">Help Center</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e(ucwords(str_replace('-', ' ', $_slug))) ?></span>
</nav>

<?php if (!$_guide['found']): ?>

<div class="page-header">
    <h1 class="page-header-title h4"><?= e(ucwords(str_replace('-', ' ', $_slug))) ?> — Guide Coming Soon</h1>
</div>

<div class="card" style="max-width:600px;">
    <div class="card-body">
        <p>The guide for <strong><?= e(ucwords(str_replace('-', ' ', $_slug))) ?></strong> is being authored. Check back soon.</p>
        <p style="margin-top:16px;">
            <a href="<?= e(base_url('help')) ?>" class="btn btn-secondary btn-sm">← Back to Help Center</a>
        </p>
    </div>
</div>

<?php else: ?>

<div class="page-header">
    <div>
        <h1 class="page-header-title h4"><?= e(strip_tags($_guide['title'])) ?></h1>
        <?php if ($_guide['description']): ?>
        <p class="text-secondary" style="margin-top:4px;"><?= e($_guide['description']) ?></p>
        <?php endif; ?>
    </div>
    <div class="page-header-actions">
        <a href="<?= e(base_url('help')) ?>" class="btn btn-ghost btn-sm">← All Guides</a>
    </div>
</div>

<div class="card">
    <div class="card-body help-content">
        <?= $_guide['html'] ?>
    </div>
</div>

<?php endif; ?>

<?php
// Clean up local variables from router scope
unset($_reqPath, $_helpBase, $_slug, $_guide);
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
