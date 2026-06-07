<?php
declare(strict_types=1);

/**
 * tests/_smoke_topbar_module_link.php
 *
 * S-TOPBAR-MODULE-LINK — the topbar page title links to the current module's
 * dashboard. Verifies (a) the wiring is present in includes/topbar.php +
 * app.css, and (b) the path→module-dashboard resolution (matched against the
 * real config/navigation.php) is correct for representative routes.
 *
 * Run: php tests/_smoke_topbar_module_link.php   (0 = pass, 1 = fail)
 * @session S-TOPBAR-MODULE-LINK
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  {$l}\n"; } else { $fail++; echo "FAIL  {$l}\n"; } }

// Mirror of the topbar's resolution (kept in lockstep with includes/topbar.php).
$nav = require dirname(__DIR__) . '/config/navigation.php';
$resolve = static function (string $rel) use ($nav): ?string {
    $seg = $rel === '' ? '' : (explode('/', $rel)[0] ?? '');
    if ($seg === '' || $seg === 'dashboard') {
        return null; // plain title (no link)
    }
    foreach ($nav as $ni) {
        if (!empty($ni['separator']) || empty($ni['url'])) {
            continue;
        }
        if ((explode('/', ltrim((string) $ni['url'], '/'))[0] ?? '') === $seg) {
            return base_url(ltrim((string) $ni['url'], '/'));
        }
    }
    return null;
};

// ── Wiring present ───────────────────────────────────────────
$topbar = file_get_contents(dirname(__DIR__) . '/includes/topbar.php');
ok(str_contains($topbar, 'topbar-title-link'), 'topbar.php renders the title as a module link');
ok(str_contains($topbar, "require FF_ROOT . '/config/navigation.php'"), 'topbar.php resolves module from navigation config');
ok(str_contains($topbar, '$_moduleHref'), 'topbar.php builds $_moduleHref');
$css = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
ok(str_contains($css, '.topbar-title-link'), 'app.css styles .topbar-title-link');

// ── Resolution correctness ───────────────────────────────────
$cases = [
    'invoices/create'      => base_url('invoices'),
    'invoices/show'        => base_url('invoices'),
    'invoices'             => base_url('invoices'),
    'leases/2620'          => base_url('leases'),
    'customers'            => base_url('customers'),
    'accounting/reports'   => base_url('accounting/dashboard'),
    'quickbooks/invoices'  => base_url('quickbooks/dashboard'),
    'settings'             => base_url('settings'),
];
foreach ($cases as $path => $expected) {
    $got = $resolve($path);
    ok($got === $expected, "/{$path} → module dashboard " . ($got ?? 'NULL'));
}

// Pages that must NOT get a link (home + non-module routes).
foreach (['dashboard', '', 'profile', 'notifications'] as $path) {
    ok($resolve($path) === null, "/{$path} → plain title (no module link)");
}

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
