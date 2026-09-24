<?php declare(strict_types=1);

/**
 * tests/_smoke_ai_billing_tools.php
 *
 * Smoke test for the AI billing tool module (lib/AI/Tools/BillingTools.php,
 * S-AI-KNOWLEDGE): get_invoice_details, get_billing_cycle, get_billing_holds,
 * get_billing_charges, get_price_check.
 *
 * Every call goes through ToolRegistry::execute() (dispatcher → module →
 * JSON encode), the same path the chat endpoint uses, against real dev data
 * (ids picked by SQL, never hard-coded).
 *
 * What it checks:
 *   1. The five tools are registered in the chat tool list.
 *   2. Super admin: every tool answers without {"error":true}, with the expected keys.
 *   3. Dispatcher (invoices:view, payments/rates = none): invoice/billing tools
 *      answer with NO money keys anywhere in the result; get_price_check is refused.
 *   4. get_billing_cycle writes nothing: the billing_cycles rows (incl.
 *      readiness_checked_at / readiness_summary / updated_at) and the row counts
 *      of the tables the billing code can write are identical before and after.
 *   5. Holds/charges with real rows: one hold + one charge are inserted inside
 *      a transaction that is ALWAYS rolled back (dev has none), so the list
 *      shapes and the dispatcher money redaction are exercised for real.
 *
 * Usage:  php tests/_smoke_ai_billing_tools.php      (exit 0 = all pass)
 *
 * @session S-AI-KNOWLEDGE
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\AI\ToolRegistry;

$pass = 0;
$fail = 0;

/** Record one assertion. */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
    }
}

/** Run a tool through the registry; return [raw string, decoded array|null]. */
function tool(string $name, array $input, int $userId): array
{
    $raw = ToolRegistry::execute($name, $input, $userId);
    $dec = json_decode($raw, true);
    return [$raw, is_array($dec) ? $dec : null];
}

/** Every key path in a nested array whose key is a money key. */
function moneyKeys(mixed $data, string $path = ''): array
{
    // Keys BillingTools emits for money; must never reach a dispatcher.
    static $money = [
        'subtotal', 'tax', 'tax_total', 'total_amount', 'amount_paid', 'credits_applied', 'balance_due',
        'total_cad', 'discount', 'amount', 'unit_price', 'mileage_rate', 'working', 'explanation',
        'lease_should_have_cost_so_far', 'already_billed_before_this', 'rental_this_invoice',
        'amount_applied', 'amount_remaining', 'allocated', 'total', 'balance', 'issued_totals',
        'money', 'last_month', 'pending_total_by_currency', 'exchange_rate_to_cad',
    ];
    $hits = [];
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $p = $path === '' ? (string) $k : $path . '.' . $k;
            if (is_string($k) && in_array($k, $money, true)) {
                $hits[] = $p;
            }
            $hits = array_merge($hits, moneyKeys($v, $p));
        }
    }
    return $hits;
}

/** Session stubs. */
function asAdmin(): void
{
    $_SESSION['ff_user'] = [
        'id' => 1, 'name' => 'Smoke Admin', 'email' => 'smoke@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();
}
function asDispatcher(): void
{
    $perms = require FF_ROOT . '/config/permissions.php';
    $_SESSION['ff_user'] = [
        'id' => 2, 'name' => 'Smoke Dispatcher', 'email' => 'dispatch@fleetforge.test',
        'role_id' => 3, 'role_slug' => 'dispatcher', 'permissions' => $perms['dispatcher'], 'theme' => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();
}

/** Snapshot of everything get_billing_cycle could conceivably write. */
function cycleSnapshot(): array
{
    $snap = ['cycles' => db_select("SELECT * FROM billing_cycles ORDER BY id")];
    foreach (['billing_cycles', 'audit_log', 'billing_cycle_reviews', 'billing_cycle_readings', 'mileage_logs', 'invoices', 'invoice_line_items'] as $t) {
        $snap['count_' . $t] = db_count("SELECT COUNT(*) FROM {$t}");
    }
    return $snap;
}

echo "\n=== AI billing tools smoke ===\n";

// ── Real ids ────────────────────────────────────────────────────────────────
$invWithPayment = db_row(
    "SELECT i.id, i.invoice_number, i.lease_id FROM invoices i
       JOIN payment_allocations pa ON pa.invoice_id = i.id
      WHERE i.deleted_at IS NULL AND i.lease_id IS NOT NULL
      ORDER BY i.id DESC LIMIT 1"
);
$invEngine = db_row(
    "SELECT id, invoice_number, lease_id FROM invoices
      WHERE deleted_at IS NULL AND lease_id IS NOT NULL AND rate_method_explanation IS NOT NULL
      ORDER BY id DESC LIMIT 1"
);
$invCredit = db_row(
    "SELECT i.id, i.invoice_number FROM invoices i
       JOIN credit_note_applications a ON a.invoice_id = i.id
      WHERE i.deleted_at IS NULL ORDER BY i.id DESC LIMIT 1"
);
$cycleRow = db_row("SELECT period_start, reference FROM billing_cycles ORDER BY period_start DESC LIMIT 1");
$priceRow = db_row(
    "SELECT rc.customer_id, rci.equipment_template_id
       FROM rate_card_items rci JOIN rate_cards rc ON rc.id = rci.rate_card_id
      WHERE rc.customer_id IS NOT NULL AND rc.deleted_at IS NULL AND rci.equipment_template_id IS NOT NULL
        AND rc.effective_from <= ? AND (rc.effective_to IS NULL OR rc.effective_to >= ?)
      LIMIT 1",
    [ff_today(), ff_today()]
);
$anyTemplate = db_row("SELECT id, name FROM equipment_templates WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id LIMIT 1");

if (!$invWithPayment || !$invEngine || !$cycleRow || !$anyTemplate) {
    fwrite(STDERR, "dev data missing (need a paid lease invoice, an engine invoice, a billing cycle, an equipment type)\n");
    exit(1);
}
$month = substr((string) $cycleRow['period_start'], 0, 7);
echo "picks: paid={$invWithPayment['invoice_number']} engine={$invEngine['invoice_number']} credit=" . ($invCredit['invoice_number'] ?? '-')
    . " cycle={$cycleRow['reference']} template={$anyTemplate['id']}"
    . ($priceRow ? " customer_card={$priceRow['customer_id']}/{$priceRow['equipment_template_id']}" : '') . "\n";
echo str_repeat('-', 90) . "\n";

// ── 1. Registered ───────────────────────────────────────────────────────────
asAdmin();
$names = array_column(ToolRegistry::getTools('chat'), 'name');
foreach (['get_invoice_details', 'get_billing_cycle', 'get_billing_holds', 'get_billing_charges', 'get_price_check'] as $n) {
    check("registered: {$n}", in_array($n, $names, true));
}
check('tool names unique in chat list', count($names) === count(array_unique($names)),
    'duplicates: ' . implode(',', array_keys(array_filter(array_count_values($names), static fn ($c) => $c > 1))));

// ── 2. Admin: every tool answers ────────────────────────────────────────────
$adminCases = [
    ['get_invoice_details by number (paid)', 'get_invoice_details', ['invoice_number' => $invWithPayment['invoice_number']], ['invoice', 'line_items', 'payments']],
    ['get_invoice_details by id (engine)',   'get_invoice_details', ['invoice_id' => (int) $invEngine['id']], ['invoice', 'line_items', 'pricing']],
    ['get_invoice_details by suffix',        'get_invoice_details', ['invoice_number' => substr((string) $invEngine['invoice_number'], -5)], ['invoice']],
    ['get_invoice_details lease list',       'get_invoice_details', ['lease_id' => (int) $invWithPayment['lease_id']], ['lease', 'invoices', 'issued_totals']],
    ['get_billing_cycle by month',           'get_billing_cycle',   ['month' => $month], ['cycle', 'progress', 'leases', 'invoices', 'readiness', 'review', 'close_check', 'money']],
    ['get_billing_cycle by reference',       'get_billing_cycle',   ['reference' => $cycleRow['reference'], 'include_readiness' => false, 'include_review' => false], ['cycle', 'readiness', 'close_check']],
    ['get_billing_cycle default month',      'get_billing_cycle',   [], ['cycle', 'progress']],
    ['get_billing_cycle unopened month',     'get_billing_cycle',   ['month' => '2001-01'], ['found', 'message']],
    ['get_billing_holds all',                'get_billing_holds',   ['state' => 'all'], ['state', 'count', 'holds']],
    ['get_billing_charges all',              'get_billing_charges', ['state' => 'all'], ['state', 'count', 'charges']],
    ['get_price_check standard by name',     'get_price_check',     ['equipment_type' => $anyTemplate['name']], ['price', 'source', 'standard_price', 'how_it_was_chosen']],
    ['get_price_check with quote',           'get_price_check',     ['equipment_template_id' => (int) $anyTemplate['id'], 'start_date' => '2026-10-01', 'end_date' => '2026-10-17', 'gps' => true, 'distance_per_day' => 300], ['price', 'quote']],
];
if ($invCredit) {
    $adminCases[] = ['get_invoice_details with credit note', 'get_invoice_details', ['invoice_id' => (int) $invCredit['id']], ['invoice', 'credit_notes']];
}
if ($priceRow) {
    $adminCases[] = ['get_price_check customer card', 'get_price_check', ['customer_id' => (int) $priceRow['customer_id'], 'equipment_template_id' => (int) $priceRow['equipment_template_id'], 'start_date' => ff_today(), 'end_date' => date('Y-m-d', strtotime(ff_today() . ' +40 days'))], ['customer', 'price', 'candidate_lines', 'quote']];
}

$bytes = [];
foreach ($adminCases as [$label, $name, $input, $keys]) {
    [$raw, $dec] = tool($name, $input, 1);
    $bytes[$label] = strlen($raw);
    $missing = $dec === null ? ['<not json>'] : array_values(array_diff($keys, array_keys($dec)));
    $isError = $dec !== null && !empty($dec['error']);
    check("admin: {$label} (" . strlen($raw) . " bytes)", !$isError && $missing === [],
        $isError ? 'error: ' . ($dec['message'] ?? '') : 'missing keys: ' . implode(',', $missing) . ' | got: ' . substr($raw, 0, 300));
}

// Specific content checks.
[, $d] = tool('get_invoice_details', ['invoice_id' => (int) $invEngine['id']], 1);
check('admin: engine invoice explains rental (rental_this_invoice)', isset($d['pricing']['rental_this_invoice']), json_encode($d['pricing'] ?? null));
check('admin: engine invoice shows total_amount', isset($d['invoice']['total_amount']));
[, $d] = tool('get_invoice_details', ['invoice_number' => $invWithPayment['invoice_number']], 1);
check('admin: paid invoice lists payments', is_array($d['payments'] ?? null) && count($d['payments']) > 0);
[, $d] = tool('get_billing_cycle', ['month' => $month], 1);
check('admin: cycle readiness ran live', ($d['readiness']['ran_live'] ?? null) === true, json_encode($d['readiness'] ?? null));
check('admin: cycle money present', isset($d['money']['total_cad']));
[, $d] = tool('get_billing_cycle', ['month' => '2001-01'], 1);
check('admin: unopened month says not opened + /billing', ($d['found'] ?? null) === false && str_contains((string) ($d['url'] ?? ''), 'billing'));
[, $d] = tool('get_invoice_details', ['invoice_number' => 'INV-NOPE-000000'], 1);
check('admin: unknown invoice is a clean error', ($d['error'] ?? false) === true);

// ── 4. get_billing_cycle writes nothing ─────────────────────────────────────
$before = cycleSnapshot();
foreach ([['month' => $month], [], ['month' => '2001-01'], ['reference' => $cycleRow['reference']]] as $in) {
    tool('get_billing_cycle', $in, 1);
}
asDispatcher();
tool('get_billing_cycle', ['month' => $month], 2);
asAdmin();
$after = cycleSnapshot();
check('get_billing_cycle wrote nothing (billing_cycles rows identical)', $before['cycles'] === $after['cycles']);
foreach ($before as $k => $v) {
    if ($k === 'cycles') continue;
    check("get_billing_cycle wrote nothing ({$k})", $v === $after[$k], "before={$v} after={$after[$k]}");
}

// ── 3. Dispatcher: no money / denial ────────────────────────────────────────
asDispatcher();
$dispCases = [
    ['get_invoice_details (paid)',  'get_invoice_details', ['invoice_number' => $invWithPayment['invoice_number']]],
    ['get_invoice_details (engine)', 'get_invoice_details', ['invoice_id' => (int) $invEngine['id']]],
    ['get_invoice_details lease list', 'get_invoice_details', ['lease_id' => (int) $invWithPayment['lease_id']]],
    ['get_billing_cycle', 'get_billing_cycle', ['month' => $month]],
    ['get_billing_holds', 'get_billing_holds', ['state' => 'all']],
    ['get_billing_charges', 'get_billing_charges', ['state' => 'all']],
];
if ($invCredit) {
    $dispCases[] = ['get_invoice_details (credit)', 'get_invoice_details', ['invoice_id' => (int) $invCredit['id']]];
}
foreach ($dispCases as [$label, $name, $input]) {
    [$raw, $dec] = tool($name, $input, 2);
    $isError = $dec === null || !empty($dec['error']);
    $leaks = $dec ? moneyKeys($dec) : [];
    check("dispatcher: {$label} answers with no money keys", !$isError && $leaks === [],
        $isError ? 'error: ' . substr($raw, 0, 200) : 'money keys: ' . implode(', ', array_slice($leaks, 0, 8)));
}
[, $d] = tool('get_invoice_details', ['invoice_number' => $invWithPayment['invoice_number']], 2);
check('dispatcher: payments hidden (string notice)', is_string($d['payments'] ?? null));
[, $d] = tool('get_price_check', ['equipment_template_id' => (int) $anyTemplate['id']], 2);
check('dispatcher: get_price_check denied (rates:view)', ($d['error'] ?? false) === true && str_contains((string) ($d['message'] ?? ''), 'rates:view'),
    json_encode($d));

// ── 5. Holds + charges with real rows (rolled back) ─────────────────────────
$lease = db_row(
    "SELECT id, customer_id FROM leases WHERE deleted_at IS NULL AND status = 'active' ORDER BY id DESC LIMIT 1"
);
if ($lease) {
    $pdo = db_pdo();
    $pdo->beginTransaction();
    try {
        db_insert('billing_holds', [
            'scope' => 'lease', 'lease_id' => (int) $lease['id'], 'customer_id' => (int) $lease['customer_id'],
            'reason' => 'SMOKE hold (rolled back)', 'starts_on' => ff_today(), 'created_by' => null,
        ]);
        db_insert('billing_charges', [
            'lease_id' => (int) $lease['id'], 'customer_id' => (int) $lease['customer_id'],
            'description' => 'SMOKE wash (rolled back)', 'item_type' => 'wash', 'quantity' => '2.0000',
            'unit_price' => '75.00', 'amount' => '150.00', 'taxable' => 1, 'recurrence' => 'once',
            'bill_from' => ff_today(), 'status' => 'active',
        ]);

        asAdmin();
        [, $d] = tool('get_billing_holds', ['lease_id' => (int) $lease['id']], 1);
        check('admin (txn): hold listed for its lease', ($d['count'] ?? 0) >= 1 && str_contains(json_encode($d), 'SMOKE hold'), json_encode($d));
        [, $d] = tool('get_billing_charges', ['lease_id' => (int) $lease['id']], 1);
        $row = null;
        foreach ($d['charges'] ?? [] as $c) {
            if (($c['description'] ?? '') === 'SMOKE wash (rolled back)') $row = $c;
        }
        check('admin (txn): pending charge listed with amount 150.00', $row !== null && ($row['amount'] ?? null) === '150.00' && ($row['state'] ?? null) === 'pending', json_encode($row));
        $cur = $row['currency'] ?? 'CAD';
        check('admin (txn): pending total includes the charge', bccomp((string) ($d['pending_total_by_currency'][$cur] ?? '0'), '150.00', 2) >= 0, json_encode($d['pending_total_by_currency'] ?? null));

        asDispatcher();
        [, $d] = tool('get_billing_charges', ['lease_id' => (int) $lease['id']], 2);
        $leaks = moneyKeys($d);
        check('dispatcher (txn): charge listed without amounts', ($d['count'] ?? 0) >= 1 && $leaks === [], 'money keys: ' . implode(',', $leaks));
        [, $d] = tool('get_billing_holds', ['lease_id' => (int) $lease['id']], 2);
        check('dispatcher (txn): hold visible (invoices:view)', ($d['count'] ?? 0) >= 1);
    } finally {
        $pdo->rollBack();
    }
    check('txn rolled back (no SMOKE rows remain)',
        db_count("SELECT COUNT(*) FROM billing_holds WHERE reason LIKE 'SMOKE hold%'") === 0
        && db_count("SELECT COUNT(*) FROM billing_charges WHERE description LIKE 'SMOKE wash%'") === 0);
} else {
    echo "[SKIP] holds/charges txn: no active lease\n";
}

echo str_repeat('-', 90) . "\n";
echo "Largest admin payloads: ";
arsort($bytes);
echo implode(', ', array_map(static fn ($k, $v) => "{$k}={$v}B", array_slice(array_keys($bytes), 0, 3), array_slice($bytes, 0, 3))) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
