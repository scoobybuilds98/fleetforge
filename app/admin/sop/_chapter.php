<?php
declare(strict_types=1);

/**
 * FleetForge — SOP chapter reader (S-SOP-MODULE)
 *
 * @file        app/admin/sop/_chapter.php
 * @description Renders one SOP chapter: hero, the chapter body (diagrams,
 *              callouts, path chips that link to the real screens), a sticky
 *              table of contents with scroll-spy, "Mark as read", and
 *              previous / next chapter. The month-end chapter's checklist is
 *              swapped for the live shared checklist for roles that can see
 *              it (SopChecklist::canView()); everyone else sees it static.
 *
 *              Reached through the router fallback: /sop/{slug} has no file
 *              per chapter (public/index.php → $sopSlugFallback).
 *              Any signed-in staff user; no module key (new keys are invisible
 *              until re-login — see config/permissions.php note).
 *
 * @query       period  YYYY-MM  month the checklist opens on (month-end chapter)
 *              hl      string   words to highlight (arriving from search)
 * @session     S-SOP-MODULE
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

use FleetForge\Sop\SopChecklist;
use FleetForge\Sop\SopIcons;
use FleetForge\Sop\SopLibrary;
use FleetForge\Sop\SopReads;

// /fleetforge/sop/{slug} → slug (same extraction as the Help guide renderer)
$_sopPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_sopBase = rtrim(FF_BASE_PATH, '/') . '/sop/';
$sopSlug  = str_starts_with($_sopPath, $_sopBase) ? rtrim(substr($_sopPath, strlen($_sopBase)), '/') : '';
$sopSlug  = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($sopSlug));

$chapter = $sopSlug !== '' ? SopLibrary::chapter($sopSlug) : null;
if ($chapter === null) {
    ff_not_found();
}

$rendered = SopLibrary::render($chapter['slug']);
$html     = $rendered['html'];
$toc      = $rendered['toc'];

// ── Live checklist in place of the static one ────────────────────
if (preg_match('/<!--sop-checklist:([a-z_]+)-->.*?<!--\/sop-checklist-->/s', $html, $m)) {
    if (SopChecklist::canView()) {
        $period = (string) ($_GET['period'] ?? '');
        if (!SopChecklist::isValidPeriod($period)) {
            $period = SopChecklist::defaultPeriod();
        }
        $sopChecklistPayload = SopChecklist::payload($m[1], $period);
        ob_start();
        require FF_ROOT . '/includes/partials/sop-checklist.php';
        $live = (string) ob_get_clean();
        $html = str_replace($m[0], $live, $html);
        array_unshift($toc, ['level' => 2, 'id' => 'checklist', 'text' => 'The checklist']);
    } else {
        $html = str_replace($m[0], '<div class="alert alert-info">The live, shared checklist is available to roles that can see payments. The steps are:</div>' . $m[0], $html);
    }
}

// ── Read state ───────────────────────────────────────────────────
$userId  = (int) current_user_id();
$reads   = SopReads::forUser($userId);
$status  = SopReads::status($chapter, $reads);
$others  = array_filter(SopLibrary::chapters(), static fn (array $c): bool => $c['slug'] !== $chapter['slug']);
$allRead = $others === [] || count(array_filter($others, static fn (array $c): bool => SopReads::status($c, $reads) === 'read')) === count($others);
$nb      = SopLibrary::neighbours($chapter['slug']);
$num     = str_pad((string) $chapter['number'], 2, '0', STR_PAD_LEFT);

$readerCfg = [
    'slug'    => $chapter['slug'],
    'number'  => $num,
    'title'   => $chapter['title'],
    'status'  => $status,
    'readAt'  => $reads[$chapter['slug']]['read_at'] ?? null,
    'allRead' => $allRead,
    'api'     => base_url('api/v1/sop/read'),
];

$pageTitle = $chapter['short'] . ' — SOP';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/sop.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<div x-data="sopChapter(<?= e(json_encode($readerCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
<div class="sop-readbar" x-ref="readbar" aria-hidden="true"></div>

<nav class="breadcrumb">
    <a href="<?= e(base_url('sop')) ?>">SOP</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($num . ' · ' . $chapter['short']) ?></span>
</nav>

<header class="sop-ch-hero sop-acc--<?= e($chapter['accent']) ?>">
    <span class="sop-icon-tile sop-acc--<?= e($chapter['accent']) ?>"><?= SopIcons::svg($chapter['icon']) ?></span>
    <div style="min-width:0;">
        <div class="sop-eyebrow">Chapter <?= e($num) ?><?= $chapter['part'] !== '' ? ' · ' . e($chapter['part']) : '' ?></div>
        <h1 class="sop-ch-title"><?= e($chapter['title']) ?></h1>
        <?php if ($chapter['summary'] !== ''): ?><p class="sop-ch-summary"><?= e($chapter['summary']) ?></p><?php endif; ?>
        <div class="sop-ch-meta">
            <span class="sop-chip"><?= SopIcons::svg('clock') ?><?= (int) $chapter['minutes'] ?> min read</span>
            <?php if ($chapter['reviewed'] !== ''): ?>
            <span class="sop-chip"><?= SopIcons::svg('calendar-days') ?>Reviewed <?= e(date('M j, Y', (int) strtotime($chapter['reviewed']))) ?></span>
            <?php endif; ?>
            <?php foreach ($chapter['audience'] as $a): ?>
            <span class="sop-chip"><?= e(SopLibrary::AUDIENCES[$a]) ?></span>
            <?php endforeach; ?>
            <span class="sop-status" :class="'sop-status--' + status" x-text="readLabel()"></span>
        </div>
    </div>
    <div class="sop-ch-bignum" aria-hidden="true"><?= e($num) ?></div>
</header>

<div class="sop-layout">
    <article class="sop-content">
        <?= $html ?>

        <div class="sop-done sop-no-print">
            <div class="sop-done-text" x-show="status !== 'read'">Finished this chapter? Mark it as read — if it changes later, it will show as updated.</div>
            <div class="sop-done-text" x-show="status === 'read'" x-cloak>You've read this version of the chapter.</div>
            <button type="button" class="btn btn-sm" :class="status === 'read' ? 'btn-secondary' : 'btn-primary'" @click="toggleRead()" :disabled="busy"
                    x-text="status === 'read' ? 'Mark as unread' : 'Mark as read'"></button>
        </div>

        <nav class="sop-pager" aria-label="Chapters">
            <?php if ($nb['prev']): ?>
            <a href="<?= e(base_url('sop/' . $nb['prev']['slug'])) ?>">
                <span class="sop-pager-dir">← Previous · <?= e(str_pad((string) $nb['prev']['number'], 2, '0', STR_PAD_LEFT)) ?></span>
                <span class="sop-pager-title"><?= e($nb['prev']['short']) ?></span>
            </a>
            <?php endif; ?>
            <?php if ($nb['next']): ?>
            <a href="<?= e(base_url('sop/' . $nb['next']['slug'])) ?>" class="is-next">
                <span class="sop-pager-dir">Next · <?= e(str_pad((string) $nb['next']['number'], 2, '0', STR_PAD_LEFT)) ?> →</span>
                <span class="sop-pager-title"><?= e($nb['next']['short']) ?></span>
            </a>
            <?php endif; ?>
        </nav>
    </article>

    <aside class="sop-aside">
        <?php if ($toc !== []): ?>
        <nav class="sop-toc" aria-label="On this page">
            <div class="sop-toc-title">On this page</div>
            <?php foreach ($toc as $t): ?>
            <a href="#<?= e($t['id']) ?>" class="lvl-<?= (int) $t['level'] ?>" :class="{ 'is-active': activeId === <?= e(json_encode($t['id'])) ?> }"><?= e($t['text']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="sop-aside-card">
            <button type="button" class="btn btn-sm" :class="status === 'read' ? 'btn-secondary' : 'btn-primary'" @click="toggleRead()" :disabled="busy"
                    x-text="status === 'read' ? '✓ Read' : (status === 'updated' ? 'Mark updated version read' : 'Mark as read')"></button>
            <a class="btn btn-ghost btn-sm" href="<?= e(base_url('sop/print') . '?chapter=' . rawurlencode($chapter['slug'])) ?>" target="_blank" rel="noopener">Print / save as PDF</a>
            <a class="btn btn-ghost btn-sm" href="<?= e(base_url('sop')) ?>">All chapters</a>
            <div class="sop-aside-note">Screen names in grey boxes open that screen.</div>
        </div>
    </aside>
</div>
</div>

<script src="<?= asset_url('assets/js/sop.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
