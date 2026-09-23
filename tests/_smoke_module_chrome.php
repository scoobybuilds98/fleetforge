<?php
declare(strict_types=1);

/**
 * tests/_smoke_module_chrome.php
 *
 * S-MODULE-CHROME — the SOP module's look (blueprint-grid hero, animated
 * illustration, glowing KPI tiles) carried to Customers, Equipment,
 * Invoices, Leases and Reservations.
 *
 *   C1  ModuleHero::render contract: list shape (crumbs, eyebrow, title,
 *       subtitle, actions, art), entity shape (avatar, main_html, facts,
 *       watermark), escaping, accent + class sanitising, initials()
 *   C2  every lib/Ui/art/*.svg is well-formed, titled, token-coloured (no
 *       hex / inline colour), starts its animations at load, uses only dg-*
 *       classes module-chrome.css defines, and is used by a page
 *   C3  module-chrome.css: loaded by header.php AND header_embed.php; no
 *       hardcoded brand orange / hex colours; no top bar on KPI tiles
 *       (operator: "looks tacky"); honours prefers-reduced-motion
 *   C4  every converted page calls ModuleHero::render, keeps no legacy
 *       .page-header, and exactly one breadcrumb source
 *   C5  every page RENDERS as super_admin (real records, rolled back):
 *       one .ff-hero with the module accent, KPI grid carries ff-stats, no
 *       PHP warning/notice from the page; embedded invoice has no crumbs
 *       and no action buttons; invoice hero hidden in print
 *   C6  dispatcher renders customers/show with the hero but WITHOUT the
 *       money fact (can_view_financials() gate)
 *
 * @session S-MODULE-CHROME
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\Ui\ModuleHero;

$pass     = 0;
$total    = 6;
$failures = [];

function ff_mc_check(string $id, string $label, array $errs): void
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

$src = static fn (string $rel): string => (string) @file_get_contents(FF_ROOT . '/' . $rel);

// The converted pages: route → [file, accent, has KPI grid]
$PAGES = [
    'customers'         => ['app/admin/customers/index.php', 'purple', true],
    'customers/show'    => ['app/admin/customers/show.php', 'purple', true],
    'equipment'         => ['app/admin/equipment/index.php', 'success', true],
    'equipment/show'    => ['app/admin/equipment/show.php', 'success', true],
    'invoices'          => ['app/admin/invoices/index.php', 'primary', true],
    'invoices/show'     => ['app/admin/invoices/show.php', 'primary', true],
    'leases'            => ['app/admin/leases/index.php', 'warning', true],
    'leases/show'       => ['app/admin/leases/show.php', 'warning', true],
    'reservations'      => ['app/admin/reservations/index.php', 'info', true],
    'reservations/show' => ['app/admin/reservations/show.php', 'info', false],
];

// ══ C1 ══════════════════════════════════════════════════════════
$e = [];
$list = ModuleHero::render([
    'title' => 'Fleet <x>', 'eyebrow' => 'Fleet', 'icon' => 'truck', 'accent' => 'success',
    'subtitle' => 'Every unit.', 'art' => 'equipment', 'actions' => '<a class="btn">New</a>',
    'crumbs' => [['Dashboard', '/d?a=1&b=2'], ['Equipment', null]], 'class' => 'ff-print-hide x"onmouseover=y',
]);
foreach (['class="ff-hero ff-acc--success ff-print-hide xonmouseovery"', 'Fleet &lt;x&gt;', '<p class="ff-hero-sub">Every unit.</p>', 'href="/d?a=1&amp;b=2"', '<span>Equipment</span>', 'class="ff-hero-art"', '<a class="btn">New</a>', 'dg--art-equipment'] as $needle) {
    if (!str_contains($list, $needle)) { $e[] = "list shape lacks {$needle}"; }
}
if (str_contains($list, 'ff-hero--entity') || str_contains($list, 'ff-hero-mark')) { $e[] = 'list shape rendered entity parts'; }
$ent = ModuleHero::render([
    'entity' => true, 'accent' => 'bogus', 'icon' => 'document-text', 'avatar' => 'RR', 'mark' => '<02319>',
    'main_html' => '<h1 class="page-header-title">X</h1>', 'facts' => ['<b>1</b> lease'], 'art' => 'invoices',
]);
foreach (['ff-acc--primary ff-hero--entity', '<span class="ff-hero-avatar" aria-hidden="true">RR</span>', '<div class="ff-hero-own"><h1 class="page-header-title">X</h1></div>', '<span class="ff-hero-fact"><b>1</b> lease</span>', '&lt;02319&gt;'] as $needle) {
    if (!str_contains($ent, $needle)) { $e[] = "entity shape lacks {$needle}"; }
}
if (str_contains($ent, 'ff-hero-art')) { $e[] = 'entity shape drew list art'; }
if (!str_contains(ModuleHero::render(['entity' => true, 'icon' => 'truck', 'title' => 'U']), '<svg')) { $e[] = 'entity avatar without initials should fall back to the icon'; }
if (ModuleHero::art('../../config/app') !== '' || ModuleHero::art('nope') !== '') { $e[] = 'art() must refuse traversal / unknown names'; }
foreach (['Rolls Right Industries Inc.' => 'RR', 'The Acme Co' => 'AC', 'Zed' => 'ZE', '' => '?', 'élan transport' => 'ÉT'] as $in => $want) {
    if (ModuleHero::initials($in) !== $want) { $e[] = "initials('{$in}') = " . ModuleHero::initials($in) . ", want {$want}"; }
}
ff_mc_check('C1', 'ModuleHero contract: list + entity shapes, escaping, accent/class sanitising, initials', $e);

// ══ C2 ══════════════════════════════════════════════════════════
$e   = [];
$css = $src('public/assets/css/module-chrome.css');
$pagesSrc = implode("\n", array_map(static fn ($p) => $src($p[0]), $PAGES));
foreach ((array) glob(FF_ROOT . '/lib/Ui/art/*.svg') as $file) {
    $name = basename((string) $file, '.svg');
    $svg  = (string) file_get_contents((string) $file);
    libxml_use_internal_errors(true);
    if (simplexml_load_string($svg) === false) { $e[] = "{$name}.svg is not well-formed"; }
    if (!preg_match('/<title>[^<]+<\/title>/', $svg)) { $e[] = "{$name}.svg has no <title>"; }
    if (preg_match('/(fill|stroke)="#|style="[^"]*(fill|stroke|color)\s*:/', $svg)) { $e[] = "{$name}.svg hardcodes a colour"; }
    if (preg_match('/begin="[0-9.]+s"/', $svg)) { $e[] = "{$name}.svg has a positive animation begin"; }
    preg_match_all('/class="([^"]+)"/', $svg, $m);
    foreach (array_unique(preg_split('/\s+/', implode(' ', $m[1]))) as $cls) {
        // dg-* paint classes must be defined; dg--art-* is only an identifying hook
        if (str_starts_with($cls, 'dg-') && !str_starts_with($cls, 'dg--art-') && !preg_match('/\.' . preg_quote($cls, '/') . '\b/', $css)) { $e[] = "{$name}.svg uses undefined .{$cls}"; }
    }
    if (!str_contains($pagesSrc, "'art'      => '{$name}'") && !str_contains($pagesSrc, "'art' => '{$name}'") && !preg_match("/'art'\s*=>\s*'{$name}'/", $pagesSrc)) { $e[] = "{$name}.svg is not used by any page"; }
}
if (count((array) glob(FF_ROOT . '/lib/Ui/art/*.svg')) < 5) { $e[] = 'expected 5 module illustrations'; }
ff_mc_check('C2', 'illustrations well-formed, titled, token-coloured, defined classes, all used', $e);

// ══ C3 ══════════════════════════════════════════════════════════
$e = [];
foreach (['includes/header.php', 'includes/header_embed.php'] as $shell) {
    if (!str_contains($src($shell), "asset_url('assets/css/module-chrome.css')")) { $e[] = "{$shell} does not load module-chrome.css"; }
}
$cssNoComments = (string) preg_replace('~/\*.*?\*/~s', '', $css);
// mask-image stencils use #000 as pure alpha — not a colour anyone sees
$cssPaint = (string) preg_replace('/(-webkit-)?mask-image\s*:[^;]*;/', '', $cssNoComments);
// The one allowed literal: purple has no design token (app.css's .stat-card--purple
// uses the same value). Everything else — above all brand orange — must be a token.
if (preg_match('/#(?!8b5cf6\b)[0-9a-fA-F]{3,8}\b/i', $cssPaint, $hm)) { $e[] = "module-chrome.css hardcodes {$hm[0]} (use tokens)"; }
if (str_contains($cssNoComments, '--color-primary-text')) { $e[] = 'uses the un-overridden --color-primary-text'; }
if (preg_match('/\.ff-stats[^{]*::(before|after)/', $cssNoComments)) { $e[] = 'KPI tiles grew a pseudo-element (top bar) — operator rejected it'; }
if (!preg_match('/prefers-reduced-motion[^{]*\{[^}]*\.ff-stats/s', $cssNoComments)) { $e[] = 'reduced-motion does not stop the tile rise'; }
if (preg_match('/^\.dg-(panel|flow|packet)\b[^{]*\{/m', $src('public/assets/css/sop.css'))) { $e[] = 'dg-* rules duplicated back into sop.css'; }
ff_mc_check('C3', 'stylesheet loaded in both shells, token-only colours, no tile top bar, reduced motion', $e);

// ══ C4 ══════════════════════════════════════════════════════════
$e = [];
foreach ($PAGES as $route => [$file, $accent, $hasGrid]) {
    $s = $src($file);
    if (!str_contains($s, '\FleetForge\Ui\ModuleHero::render(')) { $e[] = "{$route} does not render the hero"; }
    if (!preg_match("/'accent'\s*=>\s*'{$accent}'/", $s)) { $e[] = "{$route} accent is not {$accent}"; }
    if (preg_match('/<div class="page-header"/', $s)) { $e[] = "{$route} still has a legacy .page-header"; }
    $crumbSources = (int) str_contains($s, "'crumbs'") + (int) (preg_match('/<nav class="breadcrumb/', $s) === 1);
    if ($crumbSources !== 1) { $e[] = "{$route} has {$crumbSources} breadcrumb sources (want 1)"; }
    if ($hasGrid && !str_contains($s, 'ff-stats')) { $e[] = "{$route} KPI grid lacks ff-stats"; }
}
ff_mc_check('C4', 'pages call ModuleHero, no legacy header, one breadcrumb, ff-stats grids', $e);

// ── Render harness: one page per subprocess, rolled back ─────────────
$harness = sys_get_temp_dir() . '/_ff_module_chrome_harness_' . getmypid() . '.php';
file_put_contents($harness, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
[$_, $root, $file, $query, $role] = $argv;
$state = ['warnings' => []];
ob_start();
require $root . '/config/app.php';
require_once FF_ROOT . '/includes/auth.php';
$pdo = db_pdo();
$pdo->beginTransaction();
set_error_handler(static function (int $no, string $msg, string $f, int $l) use (&$state): bool {
    if ($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED)) {
        $state['warnings'][] = basename($f) . ":{$l} {$msg}";
    }
    return true;
});
register_shutdown_function(static function () use ($pdo, &$state) {
    $state['html'] = (string) ob_get_clean();
    $fatal = error_get_last();
    if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $state['fatal'] = $fatal['message'] . ' @ ' . $fatal['file'] . ':' . $fatal['line'];
    }
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo '@@STATE@@' . json_encode($state);
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/' . $file . ($query !== '' ? '?' . $query : '');
parse_str($query, $_GET);
$user = db_row(
    "SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE r.slug = ? AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.id LIMIT 1", [$role]
);
if (!$user) { $state['fatal'] = "no active {$role} user"; exit; }
auth_login($user);
require $root . '/' . $file;
PHP);

/** Render one page as a role; returns ['html','warnings','fatal'?]. */
function ff_mc_render(string $file, string $query, string $role = 'super_admin'): array
{
    global $harness;
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg(FF_ROOT) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($query) . ' ' . escapeshellarg($role) . ' 2>&1');
    $pos = strrpos($out, '@@STATE@@');
    return $pos === false ? ['html' => '', 'warnings' => [], 'fatal' => 'no harness state: ' . substr($out, 0, 300)]
                          : (json_decode(substr($out, $pos + 9), true) ?: ['html' => '', 'warnings' => [], 'fatal' => 'bad state']);
}

$ids = [
    'customers/show'    => (int) (db_row("SELECT c.id FROM customers c JOIN leases l ON l.customer_id = c.id AND l.deleted_at IS NULL WHERE c.deleted_at IS NULL ORDER BY c.id DESC LIMIT 1")['id'] ?? 0),
    'equipment/show'    => (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0),
    'invoices/show'     => (int) (db_row("SELECT id FROM invoices WHERE deleted_at IS NULL AND status <> 'void' ORDER BY id DESC LIMIT 1")['id'] ?? 0),
    'leases/show'       => (int) (db_row("SELECT id FROM leases WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1")['id'] ?? 0),
    'reservations/show' => (int) (db_row("SELECT id FROM reservations ORDER BY id DESC LIMIT 1")['id'] ?? 0),
];

// ══ C5 ══════════════════════════════════════════════════════════
$e = [];
$rendered = 0;
foreach ($PAGES as $route => [$file, $accent, $hasGrid]) {
    $query = '';
    if (isset($ids[$route])) {
        if ($ids[$route] === 0) { $e[] = "{$route}: no record in this DB to render"; continue; }
        $query = 'id=' . $ids[$route];
    }
    $r    = ff_mc_render($file, $query);
    $html = (string) ($r['html'] ?? '');
    if (!empty($r['fatal'])) { $e[] = "{$route}: fatal {$r['fatal']}"; continue; }
    $heroes = substr_count($html, '<section class="ff-hero ');
    if ($heroes !== 1) { $e[] = "{$route}: {$heroes} heroes"; }
    if (!str_contains($html, 'ff-hero ff-acc--' . $accent)) { $e[] = "{$route}: hero accent not {$accent}"; }
    if ($hasGrid && !preg_match('/class="stat-grid[^"]*\bff-stats\b/', $html)) { $e[] = "{$route}: rendered KPI grid lacks ff-stats"; }
    if (!str_contains($html, 'ff-hero-actions')) { $e[] = "{$route}: no action row rendered"; }
    $own = array_values(array_filter((array) $r['warnings'], static fn ($w) => preg_match('/^(ModuleHero|SopIcons|' . preg_quote(basename($file), '/') . '):/', $w)));
    if ($own !== []) { $e[] = "{$route}: " . implode(' | ', array_slice($own, 0, 2)); }
    $rendered++;
}
if ($ids['invoices/show'] > 0) {
    $emb = ff_mc_render('app/admin/invoices/show.php', 'id=' . $ids['invoices/show'] . '&embed=1');
    $h   = (string) ($emb['html'] ?? '');
    if (!empty($emb['fatal'])) { $e[] = 'embed: fatal ' . $emb['fatal']; }
    if (!str_contains($h, 'module-chrome.css')) { $e[] = 'embed shell does not link module-chrome.css'; }
    if (str_contains($h, 'ff-hero-crumbs')) { $e[] = 'embedded invoice shows hero crumbs'; }
    if (str_contains($h, 'ff-hero-actions')) { $e[] = 'embedded invoice shows action buttons'; }
    if (!str_contains($h, 'ff-hero ff-acc--primary ff-hero--entity ff-print-hide')) { $e[] = 'invoice hero is not print-hidden'; }
}
ff_mc_check('C5', "all {$rendered}/" . count($PAGES) . ' pages render one accented hero + ff-stats, no page warnings; embed + print safe', $e);

// ══ C6 ══════════════════════════════════════════════════════════
$e = [];
if ($ids['customers/show'] > 0) {
    $d = ff_mc_render('app/admin/customers/show.php', 'id=' . $ids['customers/show'], 'dispatcher');
    $h = (string) ($d['html'] ?? '');
    if (!empty($d['fatal'])) { $e[] = 'dispatcher render fatal: ' . $d['fatal']; }
    elseif (!str_contains($h, 'ff-hero--entity')) { $e[] = 'dispatcher sees no hero'; }
    elseif (preg_match('/<span class="ff-hero-fact">[^<]*<svg.*?<\/svg><b>[^<]*<\/b>\s*[A-Z]{3} owing/s', $h) || str_contains($h, ' owing</span>')) { $e[] = 'dispatcher hero shows the money owing fact'; }
    $a = ff_mc_render('app/admin/customers/show.php', 'id=' . $ids['customers/show'], 'accountant');
    if (!str_contains((string) ($a['html'] ?? ''), ' owing</span>')) { $e[] = 'accountant hero lacks the owing fact (gate over-blocks)'; }
} else {
    $e[] = 'no customer to render';
}
ff_mc_check('C6', 'money fact gated: dispatcher hidden, accountant shown', $e);

@unlink($harness);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "module_chrome_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
