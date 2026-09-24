<?php declare(strict_types=1);

/**
 * tests/_smoke_ai_ops_tools.php
 *
 * Smoke test for the AI assistant's operations tool module
 * (lib/AI/Tools/OpsTools.php — S-AI-KNOWLEDGE):
 *   get_unit_location, get_quickbooks_status,
 *   get_customer_portal_and_emails, get_work_order_details
 *
 * WHY: every call goes through ToolRegistry::execute() — the same
 * dispatcher → module → JSON-encode path the chat endpoint uses — against
 * the REAL dev schema and data (ids picked by SQL), so a column-name drift
 * or a registration miss fails here, not in front of a user.
 *
 * Checks:
 *   1. The four tools are registered in the chat tool list.
 *   2. As super_admin every call returns JSON with no {"error":true}
 *      (except the cases that are SUPPOSED to be helpful errors).
 *   3. No output carries a secret: no token/secret/password/hash KEYS, and
 *      none of the actual sensitive settings values or portal-user
 *      hash/token values appear anywhere in the text.
 *   4. As dispatcher (factory permissions from config/permissions.php):
 *      quickbooks:view is denied with a helpful message; the other three
 *      run and carry no money fields (dispatcher lacks payments:view).
 *
 * READ-ONLY: the tools only SELECT; this script writes nothing.
 *
 * Usage: php tests/_smoke_ai_ops_tools.php   (exit 0 = all pass)
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
$check = static function (bool $ok, string $label, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
    }
};

/** Stub the session as a role (super_admin short-circuits can()). */
$asRole = static function (string $role): void {
    $perms = require FF_ROOT . '/config/permissions.php';
    $_SESSION['ff_user'] = [
        'id'          => 1,
        'name'        => 'Smoke ' . $role,
        'email'       => 'smoke@fleetforge.test',
        'role_id'     => 1,
        'role_slug'   => $role,
        'permissions' => $role === 'super_admin' ? [] : ($perms[$role] ?? []),
        'theme'       => 'dark',
    ];
    $_SESSION['ff_last_activity'] = time();
};

// ── Secret material that must never appear in any output ──────
$secretValues = [];
foreach (db_select("SELECT value FROM settings WHERE is_sensitive = 1 AND value IS NOT NULL AND value <> ''") as $r) {
    if (strlen((string) $r['value']) >= 8) {
        $secretValues[] = (string) $r['value'];
    }
}
foreach (db_select(
    "SELECT password_hash, invite_token, password_reset_token, auth0_sub FROM portal_users"
) as $r) {
    foreach ($r as $v) {
        if ($v !== null && strlen((string) $v) >= 8) {
            $secretValues[] = (string) $v;
        }
    }
}
$forbiddenKey = '/(token|secret|password|hash|auth0|api_key|client_id|request_payload|response_payload|last_login_ip)/i';
$moneyKeys    = ['costs', 'unit_cost', 'total_cost', 'labor_cost', 'parts_cost', 'ff_total', 'qbo_total',
                 'qbo_balance', 'drift_amount', 'ff_total_at_push', 'total_amount'];

/** Every key in a decoded structure, recursively. */
$allKeys = static function (mixed $data) use (&$allKeys): array {
    if (!is_array($data)) {
        return [];
    }
    $keys = [];
    foreach ($data as $k => $v) {
        if (is_string($k)) {
            $keys[] = $k;
        }
        $keys = array_merge($keys, $allKeys($v));
    }
    return $keys;
};

/**
 * Run one tool and apply the standard assertions.
 * $expect: 'ok' (no error), 'error:<substring>' (helpful error), 'deny' (permission error).
 */
$run = static function (string $label, string $tool, array $input, string $expect, bool $noMoney = false, ?callable $extra = null)
    use ($check, $secretValues, $forbiddenKey, $moneyKeys, $allKeys): ?array {
    $raw = ToolRegistry::execute($tool, $input, 1);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $check(false, "{$label}: JSON", 'not JSON: ' . substr($raw, 0, 200));
        return null;
    }
    $isError = ($data['error'] ?? false) === true;

    if ($expect === 'ok') {
        $check(!$isError, "{$label}: no error", $isError ? (string) ($data['message'] ?? '') : '');
    } elseif ($expect === 'deny') {
        $msg = (string) ($data['message'] ?? '');
        $check($isError && str_contains($msg, "can't view") && str_contains($msg, 'Super Admin'),
            "{$label}: denied with role hint", substr($raw, 0, 200));
    } elseif (str_starts_with($expect, 'error:')) {
        $needle = substr($expect, 6);
        $check($isError && str_contains((string) ($data['message'] ?? ''), $needle),
            "{$label}: helpful error contains \"{$needle}\"", substr($raw, 0, 200));
    }

    // No secret-shaped keys, no secret values.
    $keys = $allKeys($data);
    $badKeys = array_values(array_filter($keys, static fn (string $k): bool => (bool) preg_match($forbiddenKey, $k)));
    $check($badKeys === [], "{$label}: no secret-shaped keys", 'found: ' . implode(',', array_unique($badKeys)));
    $leaks = array_filter($secretValues, static fn (string $s): bool => str_contains($raw, $s));
    $check($leaks === [], "{$label}: no secret values in output", count($leaks) . ' leaked value(s)');

    if ($noMoney) {
        $m = array_values(array_intersect($keys, $moneyKeys));
        $check($m === [], "{$label}: no money fields", 'found: ' . implode(',', array_unique($m)));
    }
    if ($extra !== null && !$isError) {
        $extra($data);
    }
    return $data;
};

// ── Picks (real dev data) ──────────────────────────────────────
$onLeaseUnit = db_row(
    "SELECT eu.id, eu.unit_number FROM equipment_units eu
       JOIN leases l ON l.equipment_unit_id = eu.id AND l.status = 'active' AND l.deleted_at IS NULL
      WHERE eu.deleted_at IS NULL AND eu.samsara_vehicle_id IS NOT NULL AND eu.samsara_vehicle_id <> ''
        AND eu.samsara_last_connected_at IS NOT NULL
      ORDER BY eu.samsara_last_connected_at DESC LIMIT 1"
);
$unlinkedUnit = db_row(
    "SELECT id, unit_number FROM equipment_units
      WHERE deleted_at IS NULL AND (samsara_vehicle_id IS NULL OR samsara_vehicle_id = '') LIMIT 1"
);
$mappedInvoice = db_row(
    "SELECT i.id, i.invoice_number FROM acc_qbo_invoice_map m JOIN invoices i ON i.id = m.ff_invoice_id
      WHERE i.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1"
);
$unmappedInvoice = db_row(
    "SELECT i.id FROM invoices i LEFT JOIN acc_qbo_invoice_map m ON m.ff_invoice_id = i.id
      WHERE m.id IS NULL AND i.deleted_at IS NULL LIMIT 1"
);
$portalCustomer = db_row(
    "SELECT pu.customer_id AS id FROM portal_users pu JOIN customers c ON c.id = pu.customer_id
      WHERE c.deleted_at IS NULL ORDER BY pu.last_login_at DESC LIMIT 1"
);
$reminderCustomer = db_row(
    "SELECT n.entity_id AS id FROM notification_log n JOIN customers c ON c.id = n.entity_id
      WHERE n.entity_type = 'customer' AND n.channel = 'email' AND c.deleted_at IS NULL
      ORDER BY n.id DESC LIMIT 1"
);
$workOrder = db_row("SELECT id, work_order_number FROM maintenance_work_orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1");

echo "\n=== AI ops-tools smoke ===\n";
printf("picks: on-lease unit=%s  unlinked unit=%s  qbo invoice=%s  unmapped invoice=%s  portal cust=%s  reminder cust=%s  work order=%s\n",
    $onLeaseUnit['unit_number'] ?? '-', $unlinkedUnit['unit_number'] ?? '-', $mappedInvoice['invoice_number'] ?? '-',
    $unmappedInvoice['id'] ?? '-', $portalCustomer['id'] ?? '-', $reminderCustomer['id'] ?? '-',
    $workOrder['work_order_number'] ?? 'NONE IN DEV DB');
echo str_repeat('-', 100) . "\n";

// ── 1. Registration ────────────────────────────────────────────
$names = array_column(ToolRegistry::getTools('chat'), 'name');
foreach (['get_unit_location', 'get_quickbooks_status', 'get_customer_portal_and_emails', 'get_work_order_details'] as $t) {
    $check(in_array($t, $names, true), "registered in chat tools: {$t}");
}

// ── 2. super_admin ─────────────────────────────────────────────
$asRole('super_admin');

if ($onLeaseUnit) {
    $run("admin unit by number {$onLeaseUnit['unit_number']}", 'get_unit_location', ['unit_number' => $onLeaseUnit['unit_number'], 'history_limit' => 3], 'ok', false,
        static function (array $d) use ($check): void {
            $check(($d['samsara']['linked'] ?? false) === true && isset($d['samsara']['last_reported']), '  unit: linked + last_reported present');
            $check(is_array($d['current_lease'] ?? null) && !empty($d['current_lease']['customer']), '  unit: current lease/customer present');
            $check(count($d['recent_history'] ?? []) <= 3, '  unit: history capped at history_limit');
            echo "        -> {$d['samsara']['last_reported']} / {$d['samsara']['reporting']} / " . ($d['samsara']['last_location']['address'] ?? 'no address') . "\n";
        });
    $run('admin unit by id', 'get_unit_location', ['unit_id' => (int) $onLeaseUnit['id']], 'ok');
}
if ($unlinkedUnit) {
    $run('admin unlinked unit', 'get_unit_location', ['unit_id' => (int) $unlinkedUnit['id']], 'ok', false,
        static fn (array $d) => $check(($d['samsara']['linked'] ?? true) === false && isset($d['samsara']['note']), '  unlinked: says not linked'));
}
$run('admin not_reporting (default 3d)', 'get_unit_location', ['mode' => 'not_reporting'], 'ok', false,
    static function (array $d) use ($check): void {
        $check(isset($d['not_reporting_total']) && count($d['units']) <= 50 && count($d['units']) <= $d['not_reporting_total'], '  not_reporting: total + capped list');
        echo "        -> {$d['not_reporting_total']} of {$d['samsara_linked_units']} linked units quiet > {$d['stale_days']}d\n";
    });
$run('admin not_reporting via stale_days only', 'get_unit_location', ['stale_days' => 30], 'ok');
$run('admin bogus unit', 'get_unit_location', ['unit_number' => 'NOPE-ZZZ-999'], 'error:No equipment unit found');
$run('admin unit with no input', 'get_unit_location', [], 'error:unit_id or unit_number');

$run('admin qbo status', 'get_quickbooks_status', [], 'ok', false,
    static function (array $d) use ($check): void {
        $check(isset($d['connection']['status'], $d['queue']['by_status'], $d['drift']['open']), '  qbo: connection/queue/drift present');
        echo "        -> {$d['connection']['status']} ({$d['connection']['environment']}), queued={$d['queue']['by_status']['queued']}, failed={$d['queue']['by_status']['failed']}, open drift={$d['drift']['open']}, errors listed=" . count($d['recent_errors']) . "\n";
    });
if ($mappedInvoice) {
    $run("admin qbo invoice {$mappedInvoice['invoice_number']}", 'get_quickbooks_status', ['invoice_number' => $mappedInvoice['invoice_number'], 'errors_limit' => 2], 'ok', false,
        static fn (array $d) => $check(($d['invoice']['in_quickbooks'] ?? false) === true && isset($d['invoice']['qbo']['push_status']), '  qbo invoice: linked with push_status'));
}
if ($unmappedInvoice) {
    $run('admin qbo unmapped invoice', 'get_quickbooks_status', ['invoice_id' => (int) $unmappedInvoice['id']], 'ok', false,
        static fn (array $d) => $check(($d['invoice']['in_quickbooks'] ?? true) === false, '  qbo unmapped invoice: in_quickbooks=false'));
}

foreach (array_filter(['portal' => $portalCustomer, 'reminders' => $reminderCustomer]) as $tag => $c) {
    $run("admin customer {$tag} #{$c['id']}", 'get_customer_portal_and_emails', ['customer_id' => (int) $c['id']], 'ok', false,
        static function (array $d) use ($check, $tag): void {
            $check(count($d['customer_emails']['types'] ?? []) >= 5, "  {$tag}: reminder types evaluated");
            $check(isset($d['portal']['users']) && isset($d['recent_emails']), "  {$tag}: portal + recent_emails present");
            if ($tag === 'portal') {
                $check(($d['portal']['has_portal_access'] ?? false) === true, '  portal: has_portal_access');
            } else {
                $check(count($d['recent_emails']) > 0, '  reminders: recent reminder emails found');
            }
            echo "        -> master=" . json_encode($d['customer_emails']['master_switch_on']) . ", portal users=" . count($d['portal']['users']) . ", recent emails=" . count($d['recent_emails']) . ", first why_not=" . ($d['customer_emails']['types'][0]['why_not'] ?? 'n/a') . "\n";
        });
}
$run('admin bogus customer', 'get_customer_portal_and_emails', ['customer_id' => 999999999], 'error:No customer found');

if ($workOrder) {
    $run("admin work order {$workOrder['work_order_number']}", 'get_work_order_details', ['work_order_number' => $workOrder['work_order_number']], 'ok', false,
        static fn (array $d) => $check(isset($d['costs'], $d['line_items']), '  work order: costs + line items for admin'));
} else {
    echo "        (dev DB has no maintenance work orders — exercising the lookup SQL via not-found paths)\n";
}
$run('admin bogus work order id', 'get_work_order_details', ['work_order_id' => 999999999], 'error:No work order found');
$run('admin bogus work order number', 'get_work_order_details', ['work_order_number' => 'WO-NOPE-ZZZ'], 'error:No work order found');
$run('admin work order no input', 'get_work_order_details', [], 'error:work_order_id or work_order_number');

// ── 3. dispatcher (no quickbooks:view, no payments:view) ──────
$asRole('dispatcher');
$check(!can('quickbooks', 'view') && !can_view_financials() && can('equipment', 'view'), 'dispatcher stub: expected permission shape');

$run('dispatcher qbo status', 'get_quickbooks_status', [], 'deny', true);
if ($onLeaseUnit) {
    $run('dispatcher unit', 'get_unit_location', ['unit_id' => (int) $onLeaseUnit['id']], 'ok', true);
}
$run('dispatcher not_reporting', 'get_unit_location', ['mode' => 'not_reporting'], 'ok', true);
if ($portalCustomer) {
    $run('dispatcher customer', 'get_customer_portal_and_emails', ['customer_id' => (int) $portalCustomer['id']], 'ok', true);
}
if ($workOrder) {
    $run('dispatcher work order', 'get_work_order_details', ['work_order_id' => (int) $workOrder['id']], 'ok', true,
        static fn (array $d) => $check(isset($d['money_hidden']), '  dispatcher work order: money_hidden note'));
} else {
    $run('dispatcher work order (not found, gate passed)', 'get_work_order_details', ['work_order_id' => 999999999], 'error:No work order found', true);
}

// ── 4. read_only role denied QuickBooks too (only super_admin has it by default) ──
$asRole('accountant');
$run('accountant qbo status', 'get_quickbooks_status', [], 'deny');

echo str_repeat('-', 100) . "\n";
echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
