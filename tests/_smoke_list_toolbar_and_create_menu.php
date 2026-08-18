<?php
declare(strict_types=1);

/**
 * tests/_smoke_list_toolbar_and_create_menu.php
 *
 * Covers two paired UI standards shipped together:
 *
 *   S-TOPBAR-CREATE-ALL  — the topbar "+ New" menu offers every record the app
 *                          can create, grouped into sections, each entry
 *                          permission-gated. Three entries are `?new=1` deep
 *                          links into pages whose create lives in a modal.
 *
 *   S-LIST-TOOLBAR       — every list page's filter bar uses the same
 *                          .table-toolbar / -left / -right shape as the
 *                          customers and invoices lists.
 *
 * Schema-real per the repo convention: every create-menu destination is
 * resolved against the ACTUAL router (public/index.php resolve_route rules)
 * and the actual files on disk, so a renamed or deleted create page fails here
 * rather than 404-ing in the topbar.
 *
 * Run: php tests/_smoke_list_toolbar_and_create_menu.php   (0 = pass, 1 = fail)
 * @session S-TOPBAR-CREATE-ALL / S-LIST-TOOLBAR
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS  {$l}\n"; } else { $fail++; echo "FAIL  {$l}\n"; }
}

$root   = dirname(__DIR__);
$topbar = (string) file_get_contents($root . '/includes/topbar.php');
$header = (string) file_get_contents($root . '/includes/header.php');
$appJs  = (string) file_get_contents($root . '/public/assets/js/app.js');
$css    = (string) file_get_contents($root . '/public/assets/css/app.css');

echo "\n── S-TOPBAR-CREATE-ALL — quick-create menu ──────────────────\n";

// ── 1. Every destination in the menu must resolve to a real page ──────────
// Extracted straight from the source so adding a menu row without a page fails.
preg_match_all(
    "/\[\s*'([^']+)',\s*base_url\(([^)]*)\)\s*(?:\.\s*'([^']*)')?,\s*'([a-z0-9-]+)'\s*\]/i",
    $topbar,
    $m,
    PREG_SET_ORDER
);
ok(count($m) >= 16, 'topbar declares the full create menu (' . count($m) . ' entries)');

/** Resolve a route the way public/index.php does: {rel}.php, then {rel}/index.php. */
$routeExists = static function (string $rel) use ($root): bool {
    $rel = trim(parse_url($rel, PHP_URL_PATH) ?? $rel, '/');
    if ($rel === '') { return false; }
    foreach ([$root . '/app/admin/' . $rel . '.php', $root . '/app/admin/' . $rel . '/index.php'] as $cand) {
        if (is_file($cand)) { return true; }
    }
    return false;
};

$seenLabels = [];
foreach ($m as $entry) {
    [$whole, $label, $urlArg, , $icon] = $entry + [4 => ''];
    $rel = trim($urlArg, "' ");
    ok($routeExists($rel), "menu \"{$label}\" → /{$rel} resolves to a real page");

    // Icons are read off disk by heroicon(); a missing file renders an empty span.
    $svg = $root . '/public/assets/icons/' . $icon . '.svg';
    ok(is_file($svg), "menu \"{$label}\" icon '{$icon}' exists on disk");

    ok(!isset($seenLabels[$label]), "menu \"{$label}\" appears once");
    $seenLabels[$label] = true;
}

// ── 2. Grouped rendering ─────────────────────────────────────────────────
ok(
    (bool) preg_match('/foreach \(\$_creates as \[\$_section, \$_items\]\)/', $topbar),
    'dropdown iterates [section, items] pairs'
);
foreach (['Sales & Billing', 'Fleet', 'Maintenance', 'Accounting', 'Other'] as $section) {
    ok(str_contains($topbar, "'{$section}'"), "section \"{$section}\" is declared");
}

// A ~20-row menu must be able to scroll rather than run off a short viewport.
ok(str_contains($css, 'max-height: calc(100vh - var(--topbar-height'), 'dropdown caps its height to the viewport');
ok((bool) preg_match('/\.topbar-create-dropdown\s*\{[^}]*overflow-y:\s*auto/s', $css), 'dropdown scrolls its overflow');
ok(str_contains($css, '.topbar-create-item + .topbar-dropdown-label'), 'section rule separates groups');

// ── 3. Permission gating ─────────────────────────────────────────────────
// Nothing in the menu may be ungated: every group is built behind can()/role.
foreach ([
    "can('customers', 'create')",
    "can('leases', 'create')",
    "can('invoices', 'create')",
    "can('payments', 'create')",
    "can('rates', 'create')",
    "can('reservations', 'create')",
    "can('equipment', 'create')",
    "can('maintenance', 'create')",
    "can('inspections', 'create')",
    "can('journal_entries', 'create')",
    'is_super_admin()',
] as $gate) {
    ok(str_contains($topbar, $gate), "menu gates on {$gate}");
}

echo "\n── ?new=1 deep links into modal-create pages ────────────────\n";

// ── 4. The ?new=1 → open-modal chain, end to end ─────────────────────────
ok(str_contains($header, 'data-ff-new-event'), 'header.php publishes the create-modal event on <body>');
ok(str_contains($header, '$createModalEvent'), 'header.php reads $createModalEvent');
ok(str_contains($appJs, "params.get('new') !== '1'"), 'app.js acts only on ?new=1');
ok(str_contains($appJs, "addEventListener('alpine:initialized'"), 'app.js waits for Alpine before dispatching');
ok(str_contains($appJs, 'history.replaceState'), 'app.js strips ?new=1 so a reload does not re-open the modal');

// Each deep-linked page must declare the event AND have a listener for it.
$deepLinks = [
    'app/admin/yards/index.php'                     => 'open-create-yard',
    'app/admin/accounting/journal-entries/index.php' => 'open-je-create',
    'app/admin/documents/index.php'                  => 'open-document-upload',
];
foreach ($deepLinks as $file => $event) {
    $src = (string) file_get_contents($root . '/' . $file);
    ok(str_contains($src, "\$createModalEvent = '{$event}'"), basename(dirname($file)) . " declares \$createModalEvent = '{$event}'");
    ok(str_contains($src, "@{$event}.window"), basename(dirname($file)) . " listens for @{$event}.window");
}
// …and the topbar must actually link to them with ?new=1.
foreach (['yards', 'accounting/journal-entries', 'documents'] as $rel) {
    ok(
        (bool) preg_match('/base_url\(\'' . preg_quote($rel, '/') . '\'\)\s*\.\s*\'\?new=1\'/', $topbar),
        "topbar deep-links /{$rel}?new=1"
    );
}

echo "\n── S-LIST-TOOLBAR — filter bar shape across list pages ──────\n";

// ── 5. Every list page uses the canonical toolbar ────────────────────────
// customers + invoices are the reference; the rest were converted to match.
$listPages = [
    'app/admin/customers/index.php',
    'app/admin/invoices/index.php',
    'app/admin/leases/index.php',
    'app/admin/equipment/index.php',
    'app/admin/vendors/index.php',
    'app/admin/maintenance_work_orders/index.php',
    'app/admin/inspections/index.php',
    'app/admin/damage_claims/index.php',
    'app/admin/mileage_logs/index.php',
    'app/admin/payments/index.php',
    'app/admin/credit_notes/index.php',
    'app/admin/compliance/index.php',
    'app/admin/documents/index.php',
    'app/admin/reservations/index.php',
    'app/admin/yards/index.php',
    'app/admin/rates/index.php',
    'app/admin/requests/index.php',
    'app/admin/audit/index.php',
    'app/admin/notifications/index.php',
    'app/admin/accounting/budgets/index.php',
    'app/admin/accounting/ap-payments/index.php',
    'app/admin/quickbooks/customers.php',
    'app/admin/quickbooks/vendors.php',
    'app/admin/quickbooks/accounts.php',
    'app/admin/quickbooks/items.php',
    'app/admin/quickbooks/tax_codes.php',
    'app/admin/quickbooks/drift.php',
    'app/admin/quickbooks/sync_queue.php',
    'app/admin/quickbooks/sync_log.php',
    'app/admin/quickbooks/invoices.php',
    'app/admin/quickbooks/bills.php',
    'app/admin/quickbooks/payments.php',
    'app/admin/quickbooks/credit_memos.php',
    'app/admin/quickbooks/refund_receipts.php',
    'app/admin/quickbooks/bill_payments.php',
    'app/admin/quickbooks/journal_entries.php',
];
foreach ($listPages as $file) {
    $src = (string) file_get_contents($root . '/' . $file);
    $label = str_replace(['app/admin/', '/index.php', '.php'], '', $file);
    ok(str_contains($src, 'class="table-toolbar"'), "{$label} uses .table-toolbar");
    ok(str_contains($src, 'table-toolbar-left'), "{$label} has a left filter group");
    ok(str_contains($src, 'table-toolbar-right'), "{$label} has a right sort/count group");
}

// ── 6. The old bespoke filter-bar shapes are gone ────────────────────────
// A filter bar welded into .card-header is what made these pages look
// different (full-width stacked selects); none should remain.
foreach ($listPages as $file) {
    $src   = (string) file_get_contents($root . '/' . $file);
    $label = str_replace(['app/admin/', '/index.php', '.php'], '', $file);
    $bad   = preg_match('/<div class="card-header"[^>]*>\s*(?:<!--[^>]*-->\s*)*(?:<select|<input type="(?:text|search|date)")/s', $src);
    ok(!$bad, "{$label} has no filter controls left in a .card-header");
}

// ── 7. Toolbar CSS contract ──────────────────────────────────────────────
ok(str_contains($css, '.table-toolbar-left--wrap'), 'CSS defines the dense-filter wrap modifier');
ok(
    (bool) preg_match('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*?\.table-toolbar-left,\s*\n\s*\.table-toolbar-right\s*\{\s*flex-wrap:\s*wrap;/s', $css),
    'mobile media query lets both toolbar groups wrap'
);
ok(
    (bool) preg_match('/\.table-toolbar-left \.form-select,\s*\n\s*\.table-toolbar-right \.form-select,/s', $css),
    'mobile media query gives .form-select full width (was inputs/buttons only)'
);

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
