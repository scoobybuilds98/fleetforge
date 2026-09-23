<?php
declare(strict_types=1);

/**
 * FleetForge — SOP print view (S-SOP-MODULE)
 *
 * @file        app/admin/sop/print.php
 * @description The whole SOP (or one chapter with ?chapter=slug) on one page,
 *              laid out for paper / "Save as PDF": a cover with the contents,
 *              then each chapter starting on a new page. The month-end
 *              checklist prints as its static list (no ticks — a printed
 *              checklist is for working through by hand). Diagrams print
 *              without animation (sop.css @media print).
 *
 *              Any signed-in staff user.
 *
 * @query       chapter  slug  print just this chapter
 * @session     S-SOP-MODULE
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

use FleetForge\Sop\SopIcons;
use FleetForge\Sop\SopLibrary;

$only     = (string) preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['chapter'] ?? '')));
$chapters = SopLibrary::chapters();
if ($only !== '') {
    $chapters = array_values(array_filter($chapters, static fn (array $c): bool => $c['slug'] === $only));
    if ($chapters === []) {
        ff_not_found();
    }
}
$reviewed = SopLibrary::lastReviewed();

$pageTitle = ($only !== '' ? $chapters[0]['short'] . ' — ' : '') . 'SOP (print)';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/sop.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<div class="sop-no-print" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px;">
    <nav class="breadcrumb" style="margin:0;">
        <a href="<?= e(base_url('sop')) ?>">SOP</a>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">Print</span>
    </nav>
    <div style="display:flex;gap:8px;">
        <?php if ($only !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(base_url('sop/print')) ?>">Print the whole SOP instead</a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print / Save as PDF</button>
    </div>
</div>

<?php if ($only === ''): ?>
<section class="sop-hero" style="grid-template-columns:1fr;">
    <div>
        <div class="sop-eyebrow">Standard Operating Procedures</div>
        <h1 class="sop-hero-title">FleetForge SOP — Operations, Accounting &amp; QuickBooks</h1>
        <p class="sop-hero-sub">
            <?= count($chapters) ?> chapters<?= $reviewed !== '' ? ' · checked against the screens on ' . e(date('F j, Y', (int) strtotime($reviewed))) : '' ?>
            · printed <?= e(date('F j, Y', (int) strtotime(ff_today()))) ?>.
            The live version, with the shared month-end checklist, is in FleetForge under SOP.
        </p>
    </div>
</section>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header"><h2 class="card-title">Contents</h2></div>
    <div class="card-body">
        <ol style="margin:0;padding-left:22px;columns:2;column-gap:32px;">
            <?php foreach ($chapters as $c): ?>
            <li style="margin:3px 0;break-inside:avoid;"><a href="#ch-<?= e($c['slug']) ?>"><?= e($c['title']) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php foreach ($chapters as $c):
    $num = str_pad((string) $c['number'], 2, '0', STR_PAD_LEFT);
?>
<section class="sop-print-chapter" id="ch-<?= e($c['slug']) ?>">
    <header class="sop-ch-hero sop-acc--<?= e($c['accent']) ?>">
        <span class="sop-icon-tile sop-acc--<?= e($c['accent']) ?>"><?= SopIcons::svg($c['icon']) ?></span>
        <div style="min-width:0;">
            <div class="sop-eyebrow">Chapter <?= e($num) ?><?= $c['part'] !== '' ? ' · ' . e($c['part']) : '' ?></div>
            <h1 class="sop-ch-title"><?= e($c['title']) ?></h1>
            <?php if ($c['summary'] !== ''): ?><p class="sop-ch-summary" style="margin:0;"><?= e($c['summary']) ?></p><?php endif; ?>
        </div>
    </header>
    <article class="sop-content" style="max-width:none;">
        <?= SopLibrary::render($c['slug'])['html'] ?>
    </article>
</section>
<?php endforeach; ?>

<script src="<?= asset_url('assets/js/sop.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
