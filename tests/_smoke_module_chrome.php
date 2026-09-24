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
 *       money strip segments / rail Account card (S-RECORD-REDESIGN; was the
 *       hero's "owing" fact) — can_view_financials() gate; accountant sees them
 *   C7  dashboard (S-DASHBOARD-REDESIGN) renders for super_admin, accountant
 *       and dispatcher: hero + attention + #kpi-grid + section bar; each role
 *       gets exactly its chart containers once (money charts, money tiles
 *       and the Customers section only with can_view_financials()); the
 *       selectors the training walkthrough drives still exist; the list
 *       partial is not routable; dashboard.css is token-only
 *   C8  shell (S-SHELL-REDESIGN): shell.css linked + token-only + visual-only
 *       (never sets a sidebar width); navigation config groups the main menu
 *       into labelled sections and every item's accent is valid; a rendered
 *       page carries the sidebar brand/sections/accent classes and the
 *       topbar context block + utility tray (walkthrough selectors intact)
 *   C9  settings (S-SETTINGS-REDESIGN): hero + grouped section nav; every
 *       nav section maps to a permission-mapped tab with its own panel;
 *       Lockout only for super_admin; settings.css linked + token-only
 *   C10 tables (S-TABLES-REDESIGN): tables.css linked by both shells,
 *       token-only, visual-only; FF_TableFit present in app.js; only
 *       short-value columns (IDs, dates, amounts, statuses) are kept on one
 *       line — names wrap, so a list fits its card and View / Edit never
 *       slide out of sight (the first cut kept every cell on one line);
 *       every column class it can emit has CSS; an overflowing table pins
 *       its actions column; spec-table rules out-rank the equipment page's
 *       inline copy; the Settings nav no longer jumps the page to the top
 *   C12 lists (S-LIST-COMPACT): status tabs live in the table toolbar with a
 *       compact pager (no full-width tab bar / top pager row); the leases
 *       strip's counts and its ?focus= filter share one definition
 *       (_focus.php); list heroes compact; the strip is one band
 *   C13 record pages (S-RECORD-REDESIGN): records.css in both shells,
 *       token-only; RecordUi escapes + skips empties; customer / lease /
 *       invoice / unit pages carry the layout + rail + More menu, sticky tabs
 *       keep their place (onSwitchKeep), and render with a rail for super_admin
 *   C14 payoff (S-PAYOFF-ONE-PAGE): equipment/payoff.php is only a redirect to
 *       the unit's Payoff tab; the payoff API serves ?detail=1 and converts USD;
 *       the tab is hidden from dispatchers (money) and shown to super_admin
 *   C11 backgrounds (S-BACKGROUNDS): registry ↔ backgrounds.css (three
 *       blocks per palette, the dark one never leaks into light), the
 *       default exists, every page shell loads the sheet and writes
 *       data-bg from ff_background(), the API allowlists the registry,
 *       and the Design tab draws one tile per palette
 *
 * @session S-MODULE-CHROME
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\Ui\ModuleHero;

$pass     = 0;
$total    = 14;
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
    // S-MODULE-CHROME-2 (remaining sidebar modules)
    'payments'                => ['app/admin/payments/index.php', 'success', true],
    'credit_notes'            => ['app/admin/credit_notes/index.php', 'purple', true],
    'rates'                   => ['app/admin/rates/index.php', 'primary', true],
    'credit_applications'     => ['app/admin/credit_applications/index.php', 'info', true],
    'vendors'                 => ['app/admin/vendors/index.php', 'warning', true],
    'maintenance_work_orders' => ['app/admin/maintenance_work_orders/index.php', 'warning', true],
    'inspections'             => ['app/admin/inspections/index.php', 'info', true],
    'requests'                => ['app/admin/requests/index.php', 'primary', true],
    'damage_claims'           => ['app/admin/damage_claims/index.php', 'danger', true],
    'mileage_logs'            => ['app/admin/mileage_logs/index.php', 'info', true],
    'yards'                   => ['app/admin/yards/index.php', 'success', true],
    'tracking'                => ['app/admin/tracking/index.php', 'info', false],
    'compliance'              => ['app/admin/compliance/index.php', 'warning', true],
    'documents'               => ['app/admin/documents/index.php', 'primary', false],
    'reports'                 => ['app/admin/reports/index.php', 'purple', true],
    'analytics'               => ['app/admin/analytics/index.php', 'info', false],
    // S-SETTINGS-REDESIGN
    'settings'                => ['app/admin/settings/index.php', 'primary', false],
    // S-ATTENTION-INBOX
    'notifications'           => ['app/admin/notifications/index.php', 'warning', false],
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
// Accent must reach the hero: a same-specificity `.ff-hero { --acc }` declared after
// the .ff-acc--* rules silently overrode them (every hero rendered primary).
if (preg_match('/(^|\})\s*\.ff-hero\s*\{[^}]*--acc\s*:/', $cssNoComments)) { $e[] = '.ff-hero sets --acc (overrides every .ff-acc--* accent) — default it via :where(.ff-hero)'; }
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
    // S-RECORD-REDESIGN: the money moved from a hero fact to the key-numbers
    // strip (Outstanding / Overdue / Lifetime revenue / Account credit) and the
    // rail's Account card — the gate is checked there now.
    elseif (str_contains($h, '<div class="stat-label">Outstanding</div>') || str_contains($h, 'Lifetime revenue') || str_contains($h, '>Account</h3>')) { $e[] = 'dispatcher sees the money strip / Account card'; }
    elseif (!str_contains($h, 'Open damage claims')) { $e[] = 'dispatcher strip lost its non-money segments'; }
    $a = ff_mc_render('app/admin/customers/show.php', 'id=' . $ids['customers/show'], 'accountant');
    $ah = (string) ($a['html'] ?? '');
    if (!str_contains($ah, '<div class="stat-label">Outstanding</div>') || !str_contains($ah, '>Account</h3>')) { $e[] = 'accountant lacks the money strip / Account card (gate over-blocks)'; }
} else {
    $e[] = 'no customer to render';
}
ff_mc_check('C6', 'money fact gated: dispatcher hidden, accountant shown', $e);

// ══ C7 ══════════════════════════════════════════════════════════
$e = [];
// S-DASHBOARD-VIZ: five ApexCharts remain; the rest of the page is HTML visuals.
$moneyCharts = ['chart-cash-flow', 'chart-revenue-forecast'];
$opsCharts   = ['chart-payment-speed', 'chart-lease-flow', 'chart-utilization-trend'];
$retired     = ['chart-revenue-trend', 'chart-ar-aging', 'chart-weekly-heatmap', 'chart-top-customers', 'chart-revenue-by-type',
                'chart-fleet-status', 'chart-occupancy-by-type', 'chart-leases-trend', 'chart-lease-expiry-calendar'];
$moneyCards  = ['Billed vs Collected', 'Owed to you', 'Most overdue', 'Coming up', 'Top customers', 'Revenue by equipment type'];
$opsCards    = ['Days to pay', 'Starting vs returning', 'Coming to an end', 'Fleet mix', 'Utilization', 'Sitting idle'];
foreach (['super_admin' => true, 'accountant' => true, 'dispatcher' => false] as $role => $money) {
    $d = ff_mc_render('app/admin/dashboard/index.php', '', $role);
    $h = (string) ($d['html'] ?? '');
    if (!empty($d['fatal'])) { $e[] = "{$role}: fatal {$d['fatal']}"; continue; }
    foreach (['class="ff-hero ff-acc--primary dash-hero"', 'class="dash-attn"', 'id="kpi-grid"', 'class="dash-nav"', 'x-data="FF_Dashboard()"', 'dashboard.css', 'id="dash-leases"', 'id="dash-fleet"', 'id="dash-money"', 'id="dash-activity"'] as $needle) {
        if (!str_contains($h, $needle)) { $e[] = "{$role}: missing {$needle}"; }
    }
    foreach ($opsCharts as $id) {
        if (substr_count($h, 'id="' . $id . '"') !== 1) { $e[] = "{$role}: {$id} x" . substr_count($h, 'id="' . $id . '"'); }
    }
    foreach ($moneyCharts as $id) {
        $want = $money ? 1 : 0;
        if (substr_count($h, 'id="' . $id . '"') !== $want) { $e[] = "{$role}: {$id} x" . substr_count($h, 'id="' . $id . '"') . " (want {$want})"; }
    }
    foreach ($retired as $id) {
        if (str_contains($h, 'id="' . $id . '"')) { $e[] = "{$role}: retired chart {$id} still rendered"; }
    }
    foreach ($moneyCards as $t) {
        if ($money !== str_contains($h, '<span class="card-title">' . $t . '</span>')) { $e[] = "{$role}: card '{$t}' " . ($money ? 'missing' : 'shown without financial access'); }
    }
    foreach ($opsCards as $t) {
        if (!str_contains($h, '<span class="card-title">' . $t . '</span>')) { $e[] = "{$role}: card '{$t}' missing"; }
    }
    if (!str_contains($h, "api/v1/dashboard/charts') ?>?charts=") && !preg_match('~dashboard/charts[^\']*\?charts=~', $h)) { $e[] = "{$role}: page no longer asks for its chart subset (?charts=)"; }
    if ($money !== str_contains($h, 'id="dash-customers"')) { $e[] = "{$role}: Customers section " . ($money ? 'missing' : 'shown without financial access'); }
    foreach (['Active Revenue', 'Monthly Collections'] as $lbl) {
        if ($money !== str_contains($h, '<div class="stat-label">' . $lbl . '</div>')) { $e[] = "{$role}: tile {$lbl} " . ($money ? 'missing' : 'shown'); }
    }
    // Training walkthrough (scripts/walkthrough/chapters/01-getting-started.mjs) selectors
    foreach (['page-header-title', '<div class="stat-label">On Lease Now</div>', '<div class="stat-label">Overdue Invoices</div>', '<div class="stat-label">Compliance Alerts</div>', 'class="dashboard-section-title"', 'class="dash-pulse"', 'class="dash-attn"'] as $needle) {
        if (!str_contains($h, $needle)) { $e[] = "{$role}: walkthrough selector gone ({$needle})"; }
    }
    $own = array_values(array_filter((array) $d['warnings'], static fn ($w) => preg_match('/^(index|dashboard-list|SopIcons):/', $w)));
    if ($own !== []) { $e[] = "{$role}: " . implode(' | ', array_slice($own, 0, 2)); }
}
// S-DASHBOARD-ATTN-2: the KPI API serves the service-request count the new card reads.
$k = ff_mc_render('api/v1/dashboard/kpis.php', '');
$kj = json_decode((string) ($k['html'] ?? ''), true);
if (!is_array($kj) || !array_key_exists('open_service_requests', (array) ($kj['data'] ?? []))) { $e[] = 'kpis API lacks open_service_requests'; }
$dsrc = $src('app/admin/dashboard/index.php');
foreach (["key: 'requests'", "key: 'idle'", 'can.requests', 'can.units'] as $needle) {
    if (!str_contains($dsrc, $needle)) { $e[] = "dashboard attention card missing ({$needle})"; }
}
if (is_file(FF_ROOT . '/app/admin/dashboard/_dash_list.php')) { $e[] = 'list partial lives under app/admin (routable)'; }
$dcss = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/dashboard.css'));
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $dcss, $hm)) { $e[] = "dashboard.css hardcodes {$hm[0]}"; }
if (preg_match('/\.stat-card[^{]*::(before|after)/', $dcss)) { $e[] = 'dashboard.css gives tiles a pseudo-element (top bar)'; }
if (!str_contains($dcss, 'prefers-reduced-motion')) { $e[] = 'dashboard.css ignores reduced motion'; }
ff_mc_check('C7', 'dashboard renders per role: money charts/cards only with financial access, each chart once, retired charts gone, walkthrough selectors kept', $e);

// ══ C8 ══════════════════════════════════════════════════════════
$e = [];
if (!str_contains($src('includes/header.php'), "asset_url('assets/css/shell.css')")) { $e[] = 'header.php does not load shell.css'; }
$shell = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/shell.css'));
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $shell, $hm)) { $e[] = "shell.css hardcodes {$hm[0]}"; }
if (preg_match('/(^|[;{\s])width\s*:\s*var\(--sidebar-width/m', $shell)) { $e[] = 'shell.css sets a sidebar width (widths belong to app.css)'; }
// S-TOPBAR-CHROME: the topbar is the sidebar's dark chrome (out-ranking
// app.css's light no-blur fallback), and only its CONTROLS are re-tokened —
// a token on .topbar itself would darken every menu that opens from it.
if (!preg_match('/\[data-theme="light"\] \.topbar\s*\{[^}]*var\(--sidebar-bg\)/', $shell)) { $e[] = 'topbar lost the dark chrome background'; }
if (!preg_match('/(\.topbar-left,[^{]*)\{[^}]*--text-primary:/', $shell, $tm)) { $e[] = 'topbar controls are not re-tokened'; }
elseif (str_contains($tm[1], 'dropdown') || preg_match('/(^|,)\s*\.topbar\s*(,|$)/', $tm[1])) { $e[] = 'topbar re-tokening reaches its dropdown menus'; }
if (preg_match('/(^|\})\s*\.topbar\s*\{[^}]*--text-primary:/', $shell)) { $e[] = '.topbar itself is re-tokened (menus would go dark)'; }
$nav = require FF_ROOT . '/config/navigation.php';
$labels = []; $sections = 0;
foreach ($nav as $item) {
    if (!empty($item['separator'])) { $sections++; continue; }
    $labels[] = $item['label'];
    if (isset($item['accent']) && !in_array($item['accent'], ModuleHero::ACCENTS, true)) { $e[] = "nav '{$item['label']}' has unknown accent {$item['accent']}"; }
    if (!isset($item['accent'])) { $e[] = "nav '{$item['label']}' has no accent"; }
}
if ($sections < 8) { $e[] = "expected ≥8 menu sections, got {$sections}"; }
foreach (['Dashboard', 'Customers', 'Leases', 'Invoices', 'Equipment', 'Maintenance', 'Reports', 'SOP', 'Help Center', 'QuickBooks', 'Accounting'] as $must) {
    if (!in_array($must, $labels, true)) { $e[] = "menu lost '{$must}'"; }
}
$r = ff_mc_render('app/admin/customers/index.php', '');
$h = (string) ($r['html'] ?? '');
foreach (['class="sidebar-brand', 'class="nav-section-label">Rentals<', 'nav-item ff-acc--purple', 'class="topbar-context"', 'topbar-context-ic ff-acc--purple', 'class="topbar-tray"', 'class="sidebar-footer"', 'aria-label="Toggle navigation menu"', 'class="topbar-create-btn"'] as $needle) {
    if (!str_contains($h, $needle)) { $e[] = "rendered shell lacks {$needle}"; }
}
// heroicon() caches by icon NAME: a custom class passed from the sidebar leaks onto
// every later use of that icon (the phone search button jumped to the far left).
if (preg_match('/<svg class="(?!nav-icon)[^"]*"[^>]*>(?:(?!<\/svg>).)*<\/svg>\s*<\/span>\s*<span class="nav-item-label"/s', $h)) { $e[] = 'a nav item icon lost its nav-icon class (heroicon cache leak)'; }
if (preg_match('/<svg class="(brand-icon)"/', $h)) { $e[] = 'shell passes a custom class through heroicon() (cache leak)'; }
if (str_contains($h, 'sidebar-find')) { $e[] = 'sidebar still renders the removed Find-a-page box'; }
ff_mc_check('C8', 'shell: stylesheet token-only + visual-only, grouped menu with valid accents, sidebar + topbar markup rendered', $e);

// ══ C9 ══════════════════════════════════════════════════════════
$e = [];
$ssrc = $src('app/admin/settings/index.php');
if (!str_contains($ssrc, "asset_url('assets/css/settings.css')")) { $e[] = 'settings page does not load settings.css'; }
if (str_contains($ssrc, 'class="tab-bar"')) { $e[] = 'settings still renders the old tab bar'; }
preg_match('/\$tabPermMap = \[(.*?)\];/s', $ssrc, $tm);
preg_match_all("/'([a-z_]+)'\s*=>\s*'settings_/", $tm[1] ?? '', $tk);
preg_match('/\$setTabs = \[(.*?)\n\];/s', $ssrc, $sm);
preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*\[/m", $sm[1] ?? '', $sk);
if (count($sk[1]) < 12) { $e[] = 'settings nav lists ' . count($sk[1]) . ' sections (want 12)'; }
foreach ($sk[1] as $key) {
    if (!in_array($key, $tk[1], true)) { $e[] = "nav section {$key} has no permission mapping"; }
    if (!str_contains($ssrc, "x-show=\"activeTab === '{$key}'\"")) { $e[] = "nav section {$key} has no panel"; }
}
foreach ($tk[1] as $key) {
    if (!in_array($key, $sk[1], true)) { $e[] = "tab {$key} missing from the settings nav"; }
}
$r = ff_mc_render('app/admin/settings/index.php', '');
$h = (string) ($r['html'] ?? '');
if (!empty($r['fatal'])) { $e[] = 'settings render fatal: ' . $r['fatal']; }
if (substr_count($h, 'class="set-nav-item ') < 12) { $e[] = 'super_admin sees ' . substr_count($h, 'class="set-nav-item ') . ' nav items (want ≥12)'; }
if (!str_contains($h, '>Lockout<')) { $e[] = 'super_admin nav lacks Lockout'; }
foreach (['class="set-panel-head"', 'class="set-shell"', 'dg--art-settings'] as $needle) {
    if (!str_contains($h, $needle)) { $e[] = "settings render lacks {$needle}"; }
}
$m = ff_mc_render('app/admin/settings/index.php', '', 'manager');
if (str_contains((string) ($m['html'] ?? ''), '>Lockout<')) { $e[] = 'manager can see the Lockout section'; }
$scss = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/settings.css'));
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $scss, $hm)) { $e[] = "settings.css hardcodes {$hm[0]}"; }
// The nav must stay put when a section is picked: FF_TabHash.onSwitch()
// scrolls the page to the very top (operator: "the sidebar moves every
// time I click on a new tab").
if (str_contains($ssrc, 'FF_TabHash.onSwitch(')) { $e[] = 'settings still calls FF_TabHash.onSwitch (jumps the page — and the nav — to the top)'; }
if (!str_contains($ssrc, 'FF_TabHash.write(v)')) { $e[] = 'settings no longer writes the section to the URL hash'; }
ff_mc_check('C9', 'settings: grouped nav ↔ permission map ↔ panels, lockout gated, styles token-only, nav stays put', $e);

// ══ C10 ═════════════════════════════════════════════════════════
$e = [];
foreach (['includes/header.php', 'includes/header_embed.php'] as $shell) {
    if (!str_contains($src($shell), "asset_url('assets/css/tables.css')")) { $e[] = "{$shell} does not load tables.css"; }
}
$tcss = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/tables.css'));
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $tcss, $hm)) { $e[] = "tables.css hardcodes {$hm[0]}"; }
// Floor widths only on the free-text and name (title) columns.
if (preg_match('/(^|[;{\s])(min-)?width\s*:\s*\d{3,}px/m', (string) preg_replace('/\.ff-(wrap|title)-c[\s\S]*?\}/', '', $tcss))) { $e[] = 'tables.css forces a width outside the free-text / name column rules'; }
$appjs = $src('public/assets/js/app.js');
if (!str_contains($appjs, 'function fitColumns(table)') || !str_contains($appjs, 'window.FF_TableFit')) { $e[] = 'FF_TableFit missing from app.js'; }
if (!preg_match('/let scroller = scrollAncestor\(table\);[\s\S]{0,500}?if \(!scroller\) return;/', $appjs)) { $e[] = 'FF_TableFit no longer checks for a scroll container (nowrap could widen the page)'; }
if (preg_match("/col \+ k <= (\d+)/", $appjs, $cm)) {
    for ($i = 1; $i <= (int) $cm[1]; $i++) {
        foreach (['ff-wrap-c', 'ff-nw-c'] as $cls) {
            if (!preg_match('/\.' . $cls . $i . '\s+>\s*tbody/', $tcss)) { $e[] = "no CSS for .{$cls}{$i}"; }
        }
    }
    for ($i = 1; $i <= 4; $i++) {
        if (!str_contains($tcss, ".ff-title-c{$i} > tbody > tr > td:nth-child({$i})")) { $e[] = "no CSS for .ff-title-c{$i}"; }
    }
} else { $e[] = 'FF_TableFit column cap not found'; }
// The first cut kept EVERY cell on one line (.ff-fit td nowrap) and pushed
// View / Edit off-card at 1280px. Only tagged short columns may be nowrap.
if (preg_match('/\.ff-fit\s*>\s*tbody\s*>\s*tr\s*>\s*td\s*\{[^}]*nowrap/', $tcss)) { $e[] = 'tables.css keeps every cell of a fitted table on one line again'; }
if (!preg_match('/\.ff-overflow\.ff-act[^{]*td:last-child[^{]*\{[^}]*position:\s*sticky/s', $tcss)) { $e[] = 'an overflowing table no longer pins its actions column'; }
if (!str_contains($appjs, "classList.toggle('ff-overflow'")) { $e[] = 'FF_TableFit no longer flags overflowing tables'; }
// Header classification — the JS regexes, run on real header labels.
$rx = static function (string $name) use ($appjs): ?string {
    return preg_match('/const ' . $name . ' = \/(.+?)\/i;/', $appjs, $mm) ? '/' . $mm[1] . '/i' : null;
};
$short = $rx('SHORT_VALUE'); $free = $rx('FREE_TEXT');
if ($short === null || $free === null) { $e[] = 'FF_TableFit header regexes not found'; }
else {
    foreach (['Invoice #', 'Due', 'Issued', 'Total', 'Balance', 'Status', 'Risk', 'Start Date', 'Outstanding', 'VIN'] as $lbl) {
        if (!preg_match($short, $lbl)) { $e[] = "'{$lbl}' is not treated as a short value"; }
    }
    foreach (['Company', 'Customer', 'Contact', 'Location', 'Equipment Type', 'Tags'] as $lbl) {
        if (preg_match($short, $lbl)) { $e[] = "'{$lbl}' would never wrap (treated as a short value)"; }
    }
    foreach (['Description', 'Notes', 'Address', 'Memo'] as $lbl) {
        if (!preg_match($free, $lbl)) { $e[] = "'{$lbl}' is not treated as free text"; }
    }
}
if (!str_contains($tcss, 'table.spec-table tr:nth-child(even)')) { $e[] = 'spec-table rules lost their table. prefix (equipment/show inline styles would win)'; }
ff_mc_check('C10', 'tables: stylesheet in both shells, token-only, short columns only stay on one line, actions pin, spec rules out-rank inline copies', $e);

// ══ C11 ═════════════════════════════════════════════════════════
$e = [];
$bgReg = require FF_ROOT . '/config/backgrounds.php';
$bcss  = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/backgrounds.css'));
if (!defined('FF_BACKGROUND_DEFAULT') || !isset($bgReg[FF_BACKGROUND_DEFAULT])) { $e[] = 'default background is not in the registry'; }
if (!isset($bgReg['espresso'])) { $e[] = "the original 'espresso' palette was dropped from the registry"; }
if (str_contains($bcss, 'data-bg="espresso"')) { $e[] = 'espresso must not re-declare tokens (app.css is that palette)'; }
foreach ($bgReg as $k => $b) {
    foreach (['label', 'blurb', 'dark', 'light'] as $f) { if (empty($b[$f])) { $e[] = "palette {$k} has no {$f}"; } }
    if ($k === 'espresso') { continue; }
    foreach (["html[data-bg=\"{$k}\"] {", "html[data-bg=\"{$k}\"]:not([data-theme=\"light\"]) {", "html[data-bg=\"{$k}\"][data-theme=\"light\"] {"] as $sel) {
        if (!str_contains($bcss, $sel)) { $e[] = "backgrounds.css lacks {$sel}"; }
    }
}
preg_match_all('/html\[data-bg="([a-z]+)"\]/', $bcss, $bm);
foreach (array_unique($bm[1]) as $k) { if (!isset($bgReg[$k])) { $e[] = "backgrounds.css styles unknown palette {$k}"; } }
// A bare html[data-bg] block may carry only sidebar tokens (dark in both themes).
if (preg_match_all('/html\[data-bg="[a-z]+"\] \{([^}]*)\}/', $bcss, $plain)) {
    foreach ($plain[1] as $body) {
        preg_match_all('/(--[a-z0-9-]+)\s*:/', $body, $toks);
        foreach ($toks[1] as $t) {
            if (!str_starts_with($t, '--sidebar-')) { $e[] = "{$t} is set for both themes (would leak dark into light)"; }
            // Operator: keep the sidebar's original font colour — palettes set its background only.
            elseif ($t !== '--sidebar-bg') { $e[] = "a palette recolours the sidebar ({$t})"; }
        }
    }
}
$bgShells = ['includes/header.php', 'includes/header_embed.php', 'app/portal/includes/header.php', 'app/auth/login.php',
             'app/auth/mfa_required.php', 'app/auth/reset_password.php', 'app/auth/forgot_password.php',
             'app/auth/mfa_challenge.php', 'app/auth/accept_invite.php'];
foreach ($bgShells as $shell) {
    $ssrc2 = $src($shell);
    if (!str_contains($ssrc2, "asset_url('assets/css/backgrounds.css')")) { $e[] = "{$shell} does not load backgrounds.css"; }
    if (!str_contains($ssrc2, 'data-bg="<?= e(ff_background()) ?>"')) { $e[] = "{$shell} does not write data-bg"; }
}
if (!in_array(ff_background(), array_keys($bgReg), true)) { $e[] = 'ff_background() returned a key outside the registry'; }
$bapi = $src('api/v1/settings/brand.php');
if (!str_contains($bapi, "array_key_exists(\$bg, ff_backgrounds())")) { $e[] = 'brand.php does not allowlist brand_background against the registry'; }
$dsrc = $src('app/admin/settings/design.php');
if (!str_contains($dsrc, 'foreach ($backgrounds as $_bk => $_bg)') || !str_contains($dsrc, 'saveBackground()')) { $e[] = 'Design tab lost the background picker'; }
$r = ff_mc_render('app/admin/settings/index.php', 'tab=design');
$h = (string) ($r['html'] ?? '');
if (substr_count($h, 'class="ff-bg-tile"') !== count($bgReg)) { $e[] = 'Design tab draws ' . substr_count($h, 'class="ff-bg-tile"') . ' palette tiles (want ' . count($bgReg) . ')'; }
if (!preg_match('/<html[^>]*data-bg="(' . implode('|', array_keys($bgReg)) . ')"/', $h)) { $e[] = 'rendered page has no valid <html data-bg>'; }
ff_mc_check('C11', 'backgrounds: registry ↔ stylesheet ↔ shells ↔ API ↔ Design picker', $e);

// ══ C12 ═════════════════════════════════════════════════════════
$e = [];
foreach (['leases', 'invoices'] as $mod) {
    $ls = $src("app/admin/{$mod}/index.php");
    if (!preg_match('/<div class="table-toolbar">\s*<div class="table-toolbar-left">\s*<div class="tab-bar"/', $ls)) { $e[] = "{$mod} list: status tabs are not inside the table toolbar"; }
    if (str_contains($ls, "\$position = 'top'")) { $e[] = "{$mod} list still renders the full-width top pager row"; }
    if (!str_contains($ls, "\$position = 'toolbar'")) { $e[] = "{$mod} list has no compact toolbar pager"; }
}
$pag = $src('includes/partials/pagination-bar.php');
if (!str_contains($pag, "=== 'toolbar'") || !str_contains($pag, 'class="ff-pager"')) { $e[] = 'pagination partial lost its toolbar variant'; }
$lk = $src('api/v1/leases/kpis.php'); $li = $src('api/v1/leases/index.php');
if (!str_contains($lk, 'ff_lease_focus_sql(') || !str_contains($li, 'ff_lease_focus_sql(')) { $e[] = 'leases strip + list no longer share the _focus.php windows'; }
if (!str_contains($lk, 'can_view_financials()')) { $e[] = 'leases strip money total is not gated'; }
require_once FF_ROOT . '/api/v1/leases/_focus.php';
foreach (FF_LEASE_FOCUS_KEYS as $fk) {
    if (ff_lease_focus_sql($fk, ff_today()) === null) { $e[] = "focus window {$fk} undefined"; }
}
if (ff_lease_focus_sql('bogus', ff_today()) !== null) { $e[] = 'unknown focus key is not ignored'; }
$mcc = (string) preg_replace('~/\*.*?\*/~s', '', $css);
if (!str_contains($mcc, ':is(.ff-hero ~ .stat-grid.ff-stats, .ff-hero ~ * > .stat-grid.ff-stats)')) { $e[] = 'summary strip selector missing from module-chrome.css'; }
// Operator: the header cards stay full size ("should be this big") — no
// page-type overrides may shrink the hero.
if (preg_match('/\.ff-hero:not\(\.ff-hero--entity\)\s*\{/', $mcc) || preg_match('/\.ff-hero--entity\s*\{[^}]*padding:\s*16px/', (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/records.css')))) { $e[] = 'a stylesheet shrinks the module hero again'; }
// Operator: "a clear distinction between tiles" — separate cards with a gap,
// not one band with divider lines.
if (!preg_match('/:is\(\.ff-hero ~ \.stat-grid\.ff-stats, \.ff-hero ~ \* > \.stat-grid\.ff-stats\)\s*\{[^}]*gap:\s*12px/', $mcc)) { $e[] = 'key-number tiles have no gap between them'; }
if (str_contains($mcc, 'linear-gradient(var(--border-color), var(--border-color))')) { $e[] = 'key-number tiles are back to one band with divider lines'; }
$lr = ff_mc_render('app/admin/leases/index.php', '');
$lh = (string) ($lr['html'] ?? '');
if (!empty($lr['fatal'])) { $e[] = 'leases list render fatal: ' . $lr['fatal']; }
foreach (['class="ff-focus-chip"', 'api/v1/leases/kpis', "setFocus('unbilled')"] as $needle) {
    if (!str_contains($lh, $needle)) { $e[] = "leases list render lacks {$needle}"; }
}
ff_mc_check('C12', 'lists: tabs + pager in the table toolbar, strip ↔ focus filter share one definition, full-size hero, separate compact tiles', $e);

// ══ C13 ═════════════════════════════════════════════════════════
$e = [];
foreach (['includes/header.php', 'includes/header_embed.php'] as $shell) {
    if (!str_contains($src($shell), "asset_url('assets/css/records.css')")) { $e[] = "{$shell} does not load records.css"; }
}
$rcss = (string) preg_replace('~/\*.*?\*/~s', '', $src('public/assets/css/records.css'));
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $rcss, $hm)) { $e[] = "records.css hardcodes {$hm[0]}"; }
$R = \FleetForge\Ui\RecordUi::class;
if (!str_contains($R::card('<b>x</b>', 'body'), '&lt;b&gt;x&lt;/b&gt;')) { $e[] = 'RecordUi::card does not escape its title'; }
if ($R::kv([['A', null], ['B', '']]) !== '') { $e[] = 'RecordUi::kv renders empty values'; }
if (!str_contains($R::alerts([], 'All good'), 'rec-alerts-ok')) { $e[] = 'RecordUi::alerts has no all-clear state'; }
if (!str_contains($R::meter('P', '1', 250.0), 'width:100%')) { $e[] = 'RecordUi::meter does not clamp to 100%'; }
if ($R::more('   ') !== '') { $e[] = 'RecordUi::more renders an empty menu'; }
foreach (['customers', 'leases', 'invoices', 'equipment'] as $mod) {
    $ss = $src("app/admin/{$mod}/show.php");
    foreach (['class="rec-layout"', 'class="rec-rail"', 'RecordUi::more('] as $needle) {
        if (!str_contains($ss, $needle)) { $e[] = "{$mod}/show lacks {$needle}"; }
    }
    if ($mod !== 'invoices') {
        if (!str_contains($ss, 'tab-bar tab-bar--sticky')) { $e[] = "{$mod}/show tabs are not sticky"; }
        if (!str_contains($ss, 'FF_TabHash.onSwitchKeep(')) { $e[] = "{$mod}/show tab switch jumps to the page top"; }
        // Operator: every tab visible — no "More" tab.
        if (str_contains($ss, 'tab-more')) { $e[] = "{$mod}/show hides tabs behind a More tab"; }
        if (!preg_match('/<div class="tab-anchor" aria-hidden="true"><\/div>\s*<div class="tab-bar tab-bar--sticky"/', $ss)) { $e[] = "{$mod}/show tab bar has no .tab-anchor right before it"; }
    }
}
if (!str_contains($src('public/assets/js/app.js'), 'onSwitchKeep: function')) { $e[] = 'FF_TabHash.onSwitchKeep missing'; }
foreach (['customers/show', 'leases/show', 'invoices/show'] as $route) {
    if ($ids[$route] <= 0) { continue; }
    $rr = ff_mc_render($PAGES[$route][0], 'id=' . $ids[$route]);
    $rh = (string) ($rr['html'] ?? '');
    if (!empty($rr['fatal'])) { $e[] = "{$route} render fatal: " . $rr['fatal']; continue; }
    if (substr_count($rh, 'class="rec-card') < 3) { $e[] = "{$route} rail has fewer than 3 cards"; }
    if (!str_contains($rh, 'class="rec-more"')) { $e[] = "{$route} header has no More menu"; }
}
if ($ids['invoices/show'] > 0 && str_contains((string) ((ff_mc_render('app/admin/invoices/show.php', 'id=' . $ids['invoices/show'] . '&embed=1'))['html'] ?? ''), 'class="rec-rail"')) {
    $e[] = 'embedded invoice (batch preview) renders the rail';
}
ff_mc_check('C13', 'records: kit loaded + token-only, RecordUi contract, main pages carry layout/rail/More/sticky tabs', $e);

// ══ C14 ═════════════════════════════════════════════════════════
$e = [];
$old = $src('app/admin/equipment/payoff.php');
if (!str_contains($old, "#payoff'") || !str_contains($old, 'header(') || substr_count($old, "\n") > 60) { $e[] = 'equipment/payoff.php is not just a redirect to the Payoff tab'; }
$papi = $src('api/v1/accounting/fixed_assets/payoff.php');
if (!str_contains($papi, "(\$_GET['detail'] ?? '') === '1'")) { $e[] = 'payoff API has no ?detail=1'; }
if (substr_count($papi, "THEN ili.amount * COALESCE(i.exchange_rate_to_cad, 1)") < 3) { $e[] = 'payoff API does not convert USD revenue everywhere'; }
$eq = $src('app/admin/equipment/show.php');
if (!str_contains($eq, "\$canSeePayoff = can('fixed_assets', 'view') && can_view_financials();")) { $e[] = 'Payoff tab gate missing'; }
if (str_contains($eq, "base_url('equipment/payoff')")) { $e[] = 'unit page still links to the retired payoff page'; }
$linked = db_row("SELECT equipment_unit_id AS id FROM acc_fixed_assets WHERE equipment_unit_id IS NOT NULL AND status <> 'disposed' ORDER BY id LIMIT 1");
if ($linked) {
    $sa = (string) (ff_mc_render('app/admin/equipment/show.php', 'id=' . (int) $linked['id'])['html'] ?? '');
    if (!str_contains($sa, 'id="unit-payoff-chart"') || !str_contains($sa, 'Revenue by lease')) { $e[] = 'super_admin unit page lacks the merged Payoff tab'; }
    $di = (string) (ff_mc_render('app/admin/equipment/show.php', 'id=' . (int) $linked['id'], 'dispatcher')['html'] ?? '');
    if (str_contains($di, 'id="unit-payoff-chart"') || str_contains($di, "activeTab = 'payoff'")) { $e[] = 'dispatcher sees the Payoff tab (money)'; }
}
ff_mc_check('C14', 'payoff: one page (redirect), detail API with USD conversion, tab gated to money roles', $e);

@unlink($harness);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "module_chrome_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
