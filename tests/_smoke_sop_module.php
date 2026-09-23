<?php
declare(strict_types=1);

/**
 * tests/_smoke_sop_module.php
 *
 * S-SOP-MODULE — the in-app SOP (/sop): content, graphics, the shared
 * month-end checklist with live checks, and "Mark as read".
 *
 *   C1  every chapter renders; front matter complete (title, summary, known
 *       icon, valid accent, part, audience, reviewed date); numbers 1..N,
 *       slugs unique; no directive / token left unrendered
 *   C2  every link resolves: {{… @/path}} chips + checklist "@/path" → a real
 *       admin route file; [x](sop:slug#anchor) → a chapter + heading that exist
 *   C3  every :::diagram exists, is well-formed SVG, carries no hardcoded
 *       colours, and starts its animations at or before load (no corner dots)
 *   C4  checklist: 5 stages A–E, 24 unique keys; every live check keys an
 *       item; live checks for the default period run on the REAL schema with
 *       no "error" state and only known states
 *   C5  ticks (rolled back): tick → who/when; a second tick keeps the first
 *       ticker; untick removes; unknown item / future month refused; progress
 *   C6  reads (rolled back): read → 'read'; chapter edited → 'updated';
 *       un-mark → 'unread'; team() reports the reader
 *   C7  renderer contract: callout / cards / entries / filter / diagram
 *       markup; <ol start> counter; plain path chip; unknown + unclosed
 *       directives throw
 *   C8  permissions by role (simulated sessions from config/permissions.php):
 *       dispatcher neither sees nor ticks; read_only sees, can't tick;
 *       manager / accountant / super_admin tick
 *   C9  search index: every chapter present, anchors match headings, no SVG
 *   C10 wiring: nav entry, router fallback, pages, APIs gated, CSS/JS,
 *       migration + master schema carry both tables
 *
 * @session S-SOP-MODULE
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\Sop\SopChecklist;
use FleetForge\Sop\SopIcons;
use FleetForge\Sop\SopLibrary;
use FleetForge\Sop\SopReads;
use FleetForge\Sop\SopRenderer;
use FleetForge\Sop\SopSignals;

$pass     = 0;
$total    = 10;
$failures = [];

function ff_sop_check(string $id, string $label, array $errs): void
{
    global $pass, $failures;
    if ($errs === []) {
        echo "PASS {$id} {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$id} {$label} — " . implode('; ', array_slice($errs, 0, 8)) . (count($errs) > 8 ? ' …(+' . (count($errs) - 8) . ')' : '') . "\n";
        $failures[] = $id;
    }
}

/** Does a root-relative app path resolve to a routable admin file? (mirrors public/index.php) */
function ff_sop_route_exists(string $path): bool
{
    $path = (string) preg_replace('/[?#].*$/', '', $path);
    $rel  = trim($path, '/');
    if ($rel === '') {
        return false;
    }
    if (str_starts_with($rel, 'help')) {
        return is_dir(FF_ROOT . '/app/admin/help');
    }
    if (str_starts_with($rel, 'sop')) {
        return true;
    }
    $root = FF_ROOT . '/app/admin/' . $rel;
    return is_file($root . '.php') || is_file($root . '/index.php');
}

/** Simulate a signed-in user of a role with the factory permissions. */
function ff_sop_as(string $role, int $id = 999999): void
{
    static $perms = null;
    $perms ??= require FF_ROOT . '/config/permissions.php';
    $_SESSION['ff_user'] = ['id' => $id, 'name' => 'Smoke ' . $role, 'role_slug' => $role, 'permissions' => $perms[$role] ?? [],
                            'permission_overrides' => [], 'role_permission_overrides' => []];
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-SOP-MODULE smoke ({$total} sub-checks; DB writes rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$chapters = SopLibrary::chapters();
$bySlug   = array_column($chapters, null, 'slug');
$rendered = [];

// ══ C1 ══════════════════════════════════════════════════════════
$e = [];
if (count($chapters) < 13) { $e[] = 'expected at least 13 chapters, found ' . count($chapters); }
foreach ($chapters as $i => $c) {
    if ($c['number'] !== $i + 1) { $e[] = "{$c['slug']}: number {$c['number']} out of sequence"; }
    foreach (['title', 'short', 'summary', 'part', 'reviewed'] as $f) {
        if (($c[$f] ?? '') === '') { $e[] = "{$c['slug']}: front matter '{$f}' missing"; }
    }
    if (!SopIcons::exists($c['icon'])) { $e[] = "{$c['slug']}: icon '{$c['icon']}' has no file"; }
    if ($c['audience'] === []) { $e[] = "{$c['slug']}: no audience"; }
    try {
        $rendered[$c['slug']] = SopLibrary::render($c['slug']);
        $h = $rendered[$c['slug']]['html'];
        foreach (['%%SOP' => 'placeholder', "\n:::" => 'directive', '{{' => 'path token', 'href="sop:' => 'sop: link'] as $needle => $what) {
            if (str_contains($h, $needle)) { $e[] = "{$c['slug']}: unrendered {$what}"; }
        }
    } catch (\Throwable $ex) {
        $e[] = "{$c['slug']}: " . $ex->getMessage();
    }
}
if (count($bySlug) !== count($chapters)) { $e[] = 'duplicate slugs'; }
ff_sop_check('C1', 'every chapter renders; front matter complete; numbered 1..N; nothing left unrendered', $e);

// ══ C2 ══════════════════════════════════════════════════════════
$e = [];
$tocIds = [];
foreach ($rendered as $slug => $r) {
    $tocIds[$slug] = array_column($r['toc'], 'id');
    if (str_contains($r['html'], '<!--sop-checklist:')) { $tocIds[$slug][] = 'checklist'; }
}
foreach ($chapters as $c) {
    $raw = SopLibrary::body($c['slug']);
    preg_match_all('/\{\{[^{}]*?@(\/[^}\s]*)\s*\}\}/', $raw, $m1);
    preg_match_all('/^- \{[a-z0-9_]+\} .*?@(\/\S+)\s*$/m', $raw, $m2);
    foreach (array_merge($m1[1], $m2[1]) as $path) {
        if (!ff_sop_route_exists($path)) { $e[] = "{$c['slug']}: {$path} is not a screen"; }
    }
    preg_match_all('/\]\(sop:([a-z0-9-]*)(?:#([a-z0-9-]+))?\)/', $raw, $m3, PREG_SET_ORDER);
    foreach ($m3 as $l) {
        $to = $l[1];
        if (!isset($bySlug[$to])) { $e[] = "{$c['slug']}: sop:{$to} is not a chapter"; continue; }
        if (($l[2] ?? '') !== '' && !in_array($l[2], $tocIds[$to], true)) { $e[] = "{$c['slug']}: sop:{$to}#{$l[2]} has no such heading"; }
    }
}
ff_sop_check('C2', 'every screen link and chapter cross-link resolves', $e);

// ══ C3 ══════════════════════════════════════════════════════════
$e = [];
$used = [];
foreach ($chapters as $c) {
    preg_match_all('/^:::diagram\s+([a-z0-9-]+)/m', SopLibrary::body($c['slug']), $m);
    $used = array_merge($used, $m[1]);
}
$used[] = 'two-systems'; // hub hero
foreach (array_unique($used) as $name) {
    $file = FF_ROOT . '/lib/Sop/diagrams/' . $name . '.svg';
    if (!is_file($file)) { $e[] = "diagram {$name} missing"; continue; }
    $svg = (string) file_get_contents($file);
    libxml_use_internal_errors(true);
    if (simplexml_load_string($svg) === false) { $e[] = "{$name}.svg is not well-formed"; }
    if (preg_match('/(fill|stroke)="#|style="[^"]*(fill|stroke|color)\s*:/', $svg)) { $e[] = "{$name}.svg hardcodes a colour (style it via dg-* classes)"; }
    if (preg_match('/begin="[0-9.]+s"/', $svg)) { $e[] = "{$name}.svg has a positive animation begin (draws a dot in the corner before it starts)"; }
    if (!str_contains($svg, 'aria-label=')) { $e[] = "{$name}.svg has no aria-label"; }
}
foreach ((array) glob(FF_ROOT . '/lib/Sop/diagrams/*.svg') as $f) {
    if (!in_array(basename((string) $f, '.svg'), $used, true)) { $e[] = basename((string) $f) . ' is not used anywhere'; }
}
ff_sop_check('C3', 'diagrams exist, well-formed, token-coloured, no pre-start dots, all used', $e);

// ══ C4 ══════════════════════════════════════════════════════════
$e = [];
$def = SopChecklist::definition(SopChecklist::MONTH_END);
if (array_column($def['stages'], 'letter') !== ['A', 'B', 'C', 'D', 'E']) { $e[] = 'stages are not A–E'; }
if (count($def['keys']) !== 24 || count(array_unique($def['keys'])) !== 24) { $e[] = 'expected 24 unique item keys, got ' . count($def['keys']); }
$period  = SopChecklist::defaultPeriod();
$signals = SopSignals::forPeriod($period);
foreach ($signals as $k => $s) {
    if (!in_array($k, $def['keys'], true)) { $e[] = "signal '{$k}' has no checklist item"; }
    if (!in_array($s['state'], ['ok', 'warn', 'danger', 'info'], true)) { $e[] = "signal {$k} state '{$s['state']}': {$s['text']}"; }
    if ($s['text'] === '' || preg_match('/\$\s?\d/', $s['text'])) { $e[] = "signal {$k} text empty or shows an amount"; }
}
if (count($signals) < 15) { $e[] = 'expected 15 live checks, got ' . count($signals); }
ff_sop_check('C4', "checklist A–E × 24 keys; all {$period} live checks run on the real schema", $e);

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $u1 = (int) (db_row("SELECT id FROM users WHERE deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT 1")['id'] ?? 0);
    $u2 = (int) (db_row("SELECT id FROM users WHERE deleted_at IS NULL AND status = 'active' AND id <> ? ORDER BY id LIMIT 1", [$u1])['id'] ?? 0);

    // ══ C5 ══════════════════════════════════════════════════════
    $e = [];
    if ($u1 === 0 || $u2 === 0) {
        $e[] = 'need two active users on the dev DB';
    } else {
        $p = '2024-03';
        db_execute("DELETE FROM sop_checklist_ticks WHERE checklist_key = 'month_end' AND period = ?", [$p]);
        SopChecklist::set('month_end', $p, 'trial_balance', true, $u1);
        SopChecklist::set('month_end', $p, 'trial_balance', true, $u2); // second person: first stays on record
        SopChecklist::set('month_end', $p, 'je_drafts', true, $u2);
        $t = SopChecklist::ticks('month_end', $p);
        if (($t['trial_balance']['by'] ?? 0) !== $u1) { $e[] = 'second tick replaced the first ticker'; }
        if (SopChecklist::progress('month_end', $p) !== ['done' => 2, 'total' => 24]) { $e[] = 'progress ' . json_encode(SopChecklist::progress('month_end', $p)); }
        SopChecklist::set('month_end', $p, 'je_drafts', false, $u1);
        if (isset(SopChecklist::ticks('month_end', $p)['je_drafts'])) { $e[] = 'untick left the row'; }
        $pl = SopChecklist::payload('month_end', $p);
        if ($pl['done'] !== 1 || $pl['stages'][2]['done'] !== 1 || $pl['period_label'] !== 'March 2024') { $e[] = 'payload counts/label wrong'; }
        foreach ([['nope', $p], ['trial_balance', '2099-01'], ['trial_balance', '2024-13']] as [$item, $per]) {
            try { SopChecklist::set('month_end', $per, $item, true, $u1); $e[] = "accepted {$item}@{$per}"; } catch (\InvalidArgumentException $ex) { /* expected */ }
        }
    }
    ff_sop_check('C5', 'ticks: who/when, first ticker kept, untick, bad item/month refused, progress', $e);

    // ══ C6 ══════════════════════════════════════════════════════
    $e = [];
    $slug = $chapters[0]['slug'];
    db_execute("DELETE FROM sop_chapter_reads WHERE user_id = ?", [$u1]);
    SopReads::set($u1, $slug, true);
    if (SopReads::status($bySlug[$slug], SopReads::forUser($u1)) !== 'read') { $e[] = 'not read after marking'; }
    db_execute("UPDATE sop_chapter_reads SET content_hash = 'stale-version-00' WHERE user_id = ? AND chapter_slug = ?", [$u1, $slug]);
    if (SopReads::status($bySlug[$slug], SopReads::forUser($u1)) !== 'updated') { $e[] = 'edited chapter not flagged updated'; }
    $mine = array_values(array_filter(SopReads::team(), static fn ($r) => $r['id'] === $u1))[0] ?? null;
    if ($mine === null || $mine['updated'] !== 1 || $mine['read'] !== 0) { $e[] = 'team() row wrong: ' . json_encode($mine); }
    SopReads::set($u1, $slug, false);
    if (SopReads::status($bySlug[$slug], SopReads::forUser($u1)) !== 'unread') { $e[] = 'still read after un-mark'; }
    try { SopReads::set($u1, 'no-such-chapter', true); $e[] = 'unknown chapter accepted'; } catch (\InvalidArgumentException $ex) { /* expected */ }
    ff_sop_check('C6', 'reads: read → updated on edit → unread; team view; unknown chapter refused', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// ══ C7 ══════════════════════════════════════════════════════════
$e = [];
$r = SopRenderer::render(":::callout warning Mind this\nBody **bold**.\n:::\n\n:::cards\n### :truck: One\nFirst card.\n### Two\nSecond.\n:::\n\n:::entries\nThing | AR 1030 + GST 2030 | Revenue | a note\nReversal | — | — | only a note\n:::\n\n:::filter Find\n| A | B |\n| --- | --- |\n| x | y |\n:::\n\n## Head\n\n4. four\n5. five\n\nSee {{Accounting › Periods}} and [link](sop:roles).\n\n:::diagram golive-phases Caption here\n");
$h = $r['html'];
foreach (['sop-callout--warning', 'Mind this', '<strong>bold</strong>', 'class="sop-cards"', 'sop-card-icon', 'sop-entry-line--dr', 'sop-entry-line--cr', 'only a note',
          'x-data="sopFilter()"', 'class="sop-table"', 'id="head"', 'counter-reset: sopstep 3', '<span class="sop-path">', 'href="' . base_url('sop/roles') . '"',
          'sop-figure--golive-phases', '<figcaption>Caption here</figcaption>'] as $needle) {
    if (!str_contains($h, $needle)) { $e[] = "missing: {$needle}"; }
}
if (substr_count($h, 'sop-entry-line--dr') !== 2) { $e[] = 'DR side with " + " should make 2 lines, reversal none'; }
foreach ([":::bogus\nx\n:::" => 'unknown', ":::callout info\nnever closed" => 'unclosed', ":::diagram nope" => 'missing diagram'] as $bad => $what) {
    try { SopRenderer::render($bad); $e[] = "{$what} directive did not throw"; } catch (\RuntimeException $ex) { /* expected */ }
}
ff_sop_check('C7', 'renderer: callout/cards/entries/filter/diagram, ol counter, chips, links; bad directives throw', $e);

// ══ C8 ══════════════════════════════════════════════════════════
$e = [];
$saved = $_SESSION['ff_user'] ?? null;
foreach (['dispatcher' => [false, false], 'read_only' => [true, false], 'manager' => [true, true], 'accountant' => [true, true], 'super_admin' => [true, true]] as $role => [$view, $tick]) {
    ff_sop_as($role);
    if (SopChecklist::canView() !== $view) { $e[] = "{$role} canView should be " . var_export($view, true); }
    if (SopChecklist::canTick() !== $tick) { $e[] = "{$role} canTick should be " . var_export($tick, true); }
}
if ($saved === null) { unset($_SESSION['ff_user']); } else { $_SESSION['ff_user'] = $saved; }
ff_sop_check('C8', 'dispatcher hidden; read_only view-only; manager/accountant/super_admin tick', $e);

// ══ C9 ══════════════════════════════════════════════════════════
$e = [];
$index = SopLibrary::searchIndex();
$inIndex = array_unique(array_column($index, 'slug'));
foreach ($chapters as $c) {
    if (!in_array($c['slug'], $inIndex, true)) { $e[] = "{$c['slug']} not in the search index"; }
}
foreach ($index as $row) {
    if ($row['anchor'] !== '' && !in_array($row['anchor'], $tocIds[$row['slug']], true)) { $e[] = "anchor {$row['slug']}#{$row['anchor']} not a heading"; }
    if ($row['text'] === '' || str_contains($row['text'], '<svg') || str_contains($row['text'], 'dg-')) { $e[] = "bad text in {$row['slug']}#{$row['anchor']}"; }
}
ff_sop_check('C9', 'search index covers every chapter; anchors real; plain text only (' . count($index) . ' sections)', $e);

// ══ C10 ═════════════════════════════════════════════════════════
$e = [];
$src = static fn (string $f): string => (string) @file_get_contents(FF_ROOT . '/' . $f);
if (!str_contains($src('config/navigation.php'), "'url'    => '/sop'")) { $e[] = 'nav entry missing'; }
if (!str_contains($src('public/index.php'), '$sopSlugFallback') || !str_contains($src('public/index.php'), 'app/admin/sop/_chapter.php')) { $e[] = 'router fallback missing'; }
foreach (['app/admin/sop/index.php', 'app/admin/sop/_chapter.php', 'app/admin/sop/print.php'] as $page) {
    if (!str_contains($src($page), 'require_auth();')) { $e[] = "{$page} not auth-gated"; }
}
$apiC = $src('api/v1/sop/checklist.php');
if (!str_contains($apiC, 'require_auth_api();') || !str_contains($apiC, 'SopChecklist::canView()') || !str_contains($apiC, 'SopChecklist::canTick()')) { $e[] = 'checklist API gates missing'; }
if (!str_contains($src('api/v1/sop/read.php'), 'require_auth_api();')) { $e[] = 'read API not gated'; }
if (str_contains($src('includes/partials/sop-checklist.php'), '|| pending[it.key]"')) { $e[] = 'checkbox :disabled must be boolean (undefined disables every box)'; }
foreach (['public/assets/css/sop.css', 'public/assets/js/sop.js'] as $asset) {
    if (!is_file(FF_ROOT . '/' . $asset)) { $e[] = "{$asset} missing"; }
}
if (str_contains($src('public/assets/css/sop.css'), '#f97316') || str_contains($src('public/assets/css/sop.css'), '--color-primary-text')) { $e[] = 'sop.css hardcodes orange / uses the un-overridden primary-text'; }
$master = $src('FLEETFORGE_DATABASE_MASTER.sql');
foreach (['sop_chapter_reads', 'sop_checklist_ticks'] as $t) {
    if (!str_contains($master, "CREATE TABLE `{$t}`")) { $e[] = "{$t} not in master schema"; }
    if (!db_row("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t])) { $e[] = "{$t} not in the DB"; }
}
ff_sop_check('C10', 'wired: nav, router, pages + APIs gated, assets, schema', $e);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "sop_module_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
