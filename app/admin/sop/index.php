<?php
declare(strict_types=1);

/**
 * FleetForge — SOP hub (S-SOP-MODULE)
 *
 * @file        app/admin/sop/index.php
 * @description The front page of the in-app SOP (Standard Operating
 *              Procedures): hero with the "two systems" diagram, the reader's
 *              progress, this month's close progress, instant search across
 *              every section of every chapter, a role filter, the chapter
 *              cards grouped by part, and — for super admins — who on the
 *              team has read what.
 *
 *              Content: docs/sop/NN-slug.md (SopLibrary). Read state:
 *              sop_chapter_reads. Month-end ticks: sop_checklist_ticks.
 *              Any signed-in staff user; no module key (new keys are invisible
 *              until re-login — see config/permissions.php note).
 *
 * @query       q  string  pre-fills the search (chapter pages' search box)
 * @session     S-SOP-MODULE
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

use FleetForge\Sop\SopChecklist;
use FleetForge\Sop\SopIcons;
use FleetForge\Sop\SopLibrary;
use FleetForge\Sop\SopReads;

$chapters = SopLibrary::chapters();
$userId   = (int) current_user_id();
$reads    = SopReads::forUser($userId);
$myRole   = (string) (current_user()['role_slug'] ?? '');

$readCount = 0;
$updated   = 0;
$next      = null;
$parts     = [];
foreach ($chapters as $i => $c) {
    $c['status'] = SopReads::status($c, $reads);
    $c['i']      = $i;
    $readCount  += $c['status'] === 'read' ? 1 : 0;
    $updated    += $c['status'] === 'updated' ? 1 : 0;
    if ($next === null && $c['status'] !== 'read') {
        $next = $c;
    }
    $parts[$c['part'] !== '' ? $c['part'] : 'Chapters'][] = $c;
}
$total = count($chapters);

$monthEnd = null;
if (SopChecklist::canView()) {
    $mePeriod = SopChecklist::defaultPeriod();
    $monthEnd = SopChecklist::progress(SopChecklist::MONTH_END, $mePeriod) + [
        'label' => SopChecklist::label($mePeriod),
        'href'  => base_url('sop/' . SopChecklist::definition(SopChecklist::MONTH_END)['chapter']) . '?period=' . $mePeriod . '#checklist',
    ];
}
$team = is_super_admin() ? SopReads::team() : [];

$hubCfg = [
    'index' => SopLibrary::searchIndex(),
    'base'  => rtrim(base_url('sop'), '/'),
];

$ring = static function (int $done, int $of, int $r = 22): string {
    $c   = 2 * M_PI * $r;
    $off = $of > 0 ? $c * (1 - $done / $of) : $c;
    return '<svg class="sop-ring" viewBox="0 0 52 52" aria-hidden="true"><circle class="sop-ring-track" cx="26" cy="26" r="' . $r . '"/>'
        . '<circle class="sop-ring-fill" cx="26" cy="26" r="' . $r . '" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($off, 2) . '"/></svg>';
};

$pageTitle = 'SOP';
require_once FF_ROOT . '/includes/header.php';
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/sop.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<div x-data="sopHub(<?= e(json_encode($hubCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">

<section class="sop-hero">
    <div>
        <div class="sop-eyebrow">Standard Operating Procedures</div>
        <h1 class="sop-hero-title">How we run FleetForge, <em>step by step</em>.</h1>
        <p class="sop-hero-sub">
            Operations, billing, accounting and QuickBooks in one place for the whole team.
            Every grey screen path opens the real screen, and the month-end checklist is live and shared.
        </p>
        <div class="sop-hero-actions">
            <?php $start = $next ?? $chapters[0] ?? null; ?>
            <?php if ($start): ?>
            <a class="btn btn-primary" href="<?= e(base_url('sop/' . $start['slug'])) ?>">
                <?= $readCount === 0 ? 'Start reading' : ($next ? 'Continue' : 'Read again') ?> · <?= e(str_pad((string) $start['number'], 2, '0', STR_PAD_LEFT) . ' ' . $start['short']) ?>
            </a>
            <?php endif; ?>
            <?php if ($monthEnd): ?>
            <a class="btn btn-secondary" href="<?= e($monthEnd['href']) ?>">Month-end checklist</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(base_url('sop/print')) ?>" target="_blank" rel="noopener">Print the whole SOP</a>
        </div>
    </div>
    <div class="sop-hero-art" aria-hidden="true">
        <?= (string) file_get_contents(FF_ROOT . '/lib/Sop/diagrams/two-systems.svg') ?>
    </div>
</section>

<div class="sop-stats">
    <div class="sop-stat sop-acc--success">
        <?= $ring($readCount, $total) ?>
        <div class="sop-stat-body">
            <div class="sop-stat-value"><?= $readCount ?> / <?= $total ?></div>
            <div class="sop-stat-label">chapters you've read<?= $updated > 0 ? ' · ' . $updated . ' updated since' : '' ?></div>
        </div>
    </div>
    <div class="sop-stat">
        <span class="sop-icon-tile sop-acc--info"><?= SopIcons::svg('clock') ?></span>
        <div class="sop-stat-body">
            <div class="sop-stat-value">~<?= SopLibrary::totalMinutes() ?> min</div>
            <div class="sop-stat-label"><?= $total ?> chapters · <?= count($hubCfg['index']) ?> sections</div>
        </div>
    </div>
    <?php if ($monthEnd): ?>
    <a class="sop-stat sop-acc--warning" href="<?= e($monthEnd['href']) ?>">
        <?= $ring($monthEnd['done'], $monthEnd['total']) ?>
        <div class="sop-stat-body">
            <div class="sop-stat-value"><?= (int) $monthEnd['done'] ?> / <?= (int) $monthEnd['total'] ?></div>
            <div class="sop-stat-label"><?= e($monthEnd['label']) ?> close — steps ticked</div>
        </div>
    </a>
    <?php else: ?>
    <div class="sop-stat">
        <span class="sop-icon-tile sop-acc--warning"><?= SopIcons::svg('clipboard-document-check') ?></span>
        <div class="sop-stat-body">
            <div class="sop-stat-value"><?= count(SopChecklist::definition(SopChecklist::MONTH_END)['keys']) ?> steps</div>
            <div class="sop-stat-label">in the month-end close</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="sop-stat">
        <span class="sop-icon-tile sop-acc--purple"><?= SopIcons::svg('calendar-days') ?></span>
        <div class="sop-stat-body">
            <?php $reviewed = SopLibrary::lastReviewed(); ?>
            <div class="sop-stat-value"><?= $reviewed !== '' ? e(date('M j, Y', (int) strtotime($reviewed))) : '—' ?></div>
            <div class="sop-stat-label">last checked against the screens</div>
        </div>
    </div>
</div>

<div class="sop-toolbar">
    <div class="sop-search" @click.outside="open = false">
        <label class="sop-search-box">
            <?= SopIcons::svg('magnifying-glass') ?>
            <input type="search" x-ref="search" x-model="q" @input="search(); open = true" @focus="open = true"
                   @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go()" @keydown.escape="open = false"
                   placeholder="Search the SOP — e.g. “void invoice”, “drift”, “GST”" aria-label="Search the SOP" autocomplete="off">
            <span class="sop-kbd" x-show="!q">/</span>
        </label>
        <div class="sop-results" x-show="open && q.trim().length > 1" x-cloak role="listbox">
            <template x-for="(r, i) in results" :key="r.href">
                <a class="sop-result" :href="r.href" :class="{ 'is-active': i === active }" @mouseenter="active = i" role="option">
                    <div class="sop-result-where" x-text="r.where"></div>
                    <div class="sop-result-title" x-text="r.title"></div>
                    <div class="sop-result-snippet" x-html="r.snippet"></div>
                </a>
            </template>
            <div class="sop-results-empty" x-show="results.length === 0">Nothing found for “<span x-text="q"></span>”. Try fewer or different words.</div>
        </div>
    </div>
    <div class="sop-roles" role="group" aria-label="Show chapters for a role">
        <span class="sop-roles-label">Show for</span>
        <button type="button" class="sop-role" :class="{ 'is-active': role === 'all' }" @click="setRole('all')">Everyone</button>
        <?php foreach (SopLibrary::AUDIENCES as $key => $label): ?>
        <button type="button" class="sop-role" :class="{ 'is-active': role === <?= e(json_encode($key)) ?> }" @click="setRole(<?= e(json_encode($key)) ?>)"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
</div>

<?php foreach ($parts as $partName => $partChapters): ?>
<div class="sop-part"><span class="sop-part-title"><?= e($partName) ?></span><span class="sop-part-rule"></span></div>
<div class="sop-grid">
    <?php foreach ($partChapters as $c):
        $num = str_pad((string) $c['number'], 2, '0', STR_PAD_LEFT);
        $forYou = $myRole !== '' && in_array($myRole, $c['audience'], true);
    ?>
    <a class="sop-chapter-card sop-acc--<?= e($c['accent']) ?>" href="<?= e(base_url('sop/' . $c['slug'])) ?>" style="--i: <?= (int) $c['i'] ?>"
       :class="{ 'is-dim': !inRole(<?= e(json_encode($c['audience'])) ?>) }">
        <div class="sop-card-top">
            <span class="sop-icon-tile sop-acc--<?= e($c['accent']) ?>"><?= SopIcons::svg($c['icon']) ?></span>
            <span class="sop-card-num"><?= e($num) ?></span>
        </div>
        <h2 class="sop-chapter-title"><?= e($c['title']) ?></h2>
        <p class="sop-chapter-summary"><?= e($c['summary']) ?></p>
        <div class="sop-card-meta">
            <span class="sop-card-meta-left">
                <span><?= (int) $c['minutes'] ?> min</span>
                <?php if ($forYou): ?><span class="sop-for-you">For your role</span><?php endif; ?>
            </span>
            <?php if ($c['status'] === 'read'): ?>
            <span class="sop-status sop-status--read">✓ Read</span>
            <?php elseif ($c['status'] === 'updated'): ?>
            <span class="sop-status sop-status--updated">Updated</span>
            <?php else: ?>
            <span class="sop-status sop-status--unread">Not read</span>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($team !== []): ?>
<div class="card sop-team">
    <div class="card-header">
        <h2 class="card-title">Team reading — who has read the current version of each chapter</h2>
        <span class="badge badge-neutral">Super admins only</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Name</th><th>Role</th><th>Chapters read</th><th>Last read</th><th>Still to read</th></tr></thead>
            <tbody>
            <?php foreach ($team as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td><?= e($t['role']) ?></td>
                    <td style="white-space:nowrap;">
                        <span class="sop-team-bar"><span style="width: <?= $t['total'] > 0 ? round(100 * $t['read'] / $t['total']) : 0 ?>%"></span></span>
                        <span style="margin-left:8px;font-variant-numeric:tabular-nums;"><?= (int) $t['read'] ?> / <?= (int) $t['total'] ?></span>
                        <?php if ($t['updated'] > 0): ?><span class="badge badge-warning" style="margin-left:6px;"><?= (int) $t['updated'] ?> updated</span><?php endif; ?>
                    </td>
                    <td><?= $t['last_read'] ? e(format_datetime($t['last_read'], 'M j, Y')) : '<span class="text-secondary">Never</span>' ?></td>
                    <td class="sop-team-missing">
                        <?php if ($t['missing'] === []): ?>
                        <span class="badge badge-success">All read</span>
                        <?php elseif ($t['read'] === 0 && $t['updated'] === 0): ?>
                        <span class="text-secondary">Not started</span>
                        <?php else: ?>
                        <?php foreach ($t['missing'] as $mc): ?>
                        <span class="sop-team-ch" title="<?= e($mc['short']) ?>"><?= e(str_pad((string) $mc['number'], 2, '0', STR_PAD_LEFT)) ?></span>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div>

<script src="<?= asset_url('assets/js/sop.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
