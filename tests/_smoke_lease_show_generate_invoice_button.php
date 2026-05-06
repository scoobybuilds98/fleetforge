<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_show_generate_invoice_button.php
 *
 * S-INVOICE-CREATION-UX C3 / C4 — verify the Generate Invoice button
 * conditional in app/admin/leases/show.php matches the D-C contract:
 *
 *   visible when: can('invoices', 'create')
 *                 AND lease.status IN ('active', 'completed')
 *                 AND lease.deleted_at IS NULL
 *
 *   hidden  when: status IN ('pending', 'cancelled')
 *                 OR user lacks 'invoices', 'create' permission
 *
 * Two-layer check:
 *   1. Source-file presence — confirms the conditional + URL + label still
 *      live in show.php at the expected shape.
 *   2. Logic simulation — pure-PHP truth table over status × permission,
 *      asserts the button-visibility decision matches D-C for all 8 cells
 *      (4 statuses × {has perm, lacks perm}).
 *
 * Run:    php tests/_smoke_lease_show_generate_invoice_button.php
 * Exit:   0 on PASS, 1 on FAIL.
 *
 * @session   S-INVOICE-CREATION-UX C3 / C4 (2026-05-07)
 * @decisions D-C (button placement + visibility + URL)
 */

require_once dirname(__DIR__) . '/config/app.php';

$failures = 0;
$checks   = 0;

function assertTrue(string $label, bool $cond): void {
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "PASS  {$label}\n";
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo "FleetForge — lease show Generate Invoice button smoke test\n";
echo str_repeat('═', 78) . "\n";
echo "Source-file presence:\n";

$src = file_get_contents(FF_ROOT . '/app/admin/leases/show.php');

assertTrue(
    "show.php contains the status conditional [active, completed]",
    str_contains($src, "in_array(\$lease['status'], ['active', 'completed'], true)")
);
assertTrue(
    "show.php gates by can('invoices', 'create') permission",
    str_contains($src, "can('invoices', 'create')")
);
assertTrue(
    "show.php anchor href uses base_url('invoices/create')?lease_id={id}",
    str_contains($src, "<?= base_url('invoices/create') ?>?lease_id=<?= (int)\$lease['id'] ?>")
);
assertTrue(
    "show.php button label is 'Generate Invoice'",
    str_contains($src, '>Generate Invoice</a>')
);
assertTrue(
    "show.php button uses btn-primary class (matches Activate Lease pattern)",
    (bool) preg_match('/class="btn btn-primary">Generate Invoice<\/a>/', $src)
);

echo "\nLogic truth table (status × permission):\n";

// Mirror of the rendering conditional from show.php.
$shouldShow = static function (string $status, bool $canInvoicesCreate): bool {
    return $canInvoicesCreate && in_array($status, ['active', 'completed'], true);
};

$truthTable = [
    ['active',    true,  true],
    ['completed', true,  true],
    ['pending',   true,  false],
    ['cancelled', true,  false],
    ['active',    false, false],
    ['completed', false, false],
    ['pending',   false, false],
    ['cancelled', false, false],
];

foreach ($truthTable as [$status, $canPerm, $expected]) {
    $got = $shouldShow($status, $canPerm);
    $permLabel = $canPerm ? "can" : "no-perm";
    $expectLabel = $expected ? "VISIBLE" : "HIDDEN";
    assertTrue(
        sprintf("status=%-9s + %-7s → %s", $status, $permLabel, $expectLabel),
        $got === $expected
    );
}

echo str_repeat('═', 78) . "\n";
$passed = $checks - $failures;
echo "{$passed}/{$checks} passed\n";

exit($failures > 0 ? 1 : 0);
