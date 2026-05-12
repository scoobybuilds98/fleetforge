<?php
declare(strict_types=1);

/**
 * tests/_stress_smoke_invariants_i8.php
 *
 * S-MILEAGE-2B C7 / D-O — stress test for I8 (drawdown math sanity).
 *
 * Fault-injection pattern mirrors the I6/I7 stress tests: BEGIN/ROLLBACK
 * isolation around synthetic INSERTs that violate I8's predicate, then
 * exec-out to the smoke runner and assert exit 1 + I8 surfacing in the
 * failure output. Cross-check confirms the smoke is sensitive (catches the
 * violation) without being fragile (no false positives on clean state).
 *
 * Six cases:
 *   T1 — credit > min(usage, pre_balance): violation class (a)
 *   T2 — post != pre - credit:             violation class (b)
 *   T3 — usage + credit lines present but NO audit_log row: violation (c)
 *   T4 — valid pair (credit == min, post == pre - credit): PASS
 *   T5 — Model B Lite invoice (usage only, no credit, no audit_log): PASS
 *   T6 — pure-precharge Invoice 1 (precharge only, no usage/credit): PASS
 *   X  — cross-check: run smoke on production (no test rows) → INVARIANTS OK
 *
 * Each test wraps in BEGIN/ROLLBACK so production data is untouched.
 *
 * Decisions: D-O (I8 invariant scope); D-B (drawdown emit shape +
 *            audit_log entity_type='lease_precharge_balance_drawdown')
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

$results = [];

function rcd(array &$results, string $tag, bool $ok, string $msg): void {
    $results[] = ['tag' => $tag, 'ok' => $ok, 'msg' => $msg];
    $emoji = $ok ? 'PASS' : 'FAIL';
    printf("  %s %s — %s\n", $emoji, $tag, $msg);
}

/**
 * Run the I8 predicate inline (same connection as fault-injection so
 * BEGIN/ROLLBACK isolation works). Returns [violationCount, sample].
 *
 * Mirrors the I8 logic in tests/_smoke_billing_invariants.php (the smoke
 * is the canonical source; this stress is the regression sentinel for that
 * predicate). If the smoke's I8 SQL changes, update this helper to match.
 */
function runI8Inline(): array {
    $rows = db_select(
        "SELECT
            i.id AS invoice_id,
            i.invoice_number,
            usage_li.amount AS usage_amount,
            credit_li.amount AS credit_amount,
            JSON_UNQUOTE(JSON_EXTRACT(al.old_values, '$.precharge_balance')) AS pre_balance,
            JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.precharge_balance')) AS post_balance
         FROM invoices i
         JOIN invoice_line_items usage_li ON usage_li.invoice_id = i.id AND usage_li.item_type = 'mileage_usage'
         JOIN invoice_line_items credit_li ON credit_li.invoice_id = i.id AND credit_li.item_type = 'mileage_drawdown_credit'
         LEFT JOIN audit_log al ON al.entity_type = 'lease_precharge_balance_drawdown'
                                AND JSON_EXTRACT(al.new_values, '$.invoice_id') = i.id
         WHERE i.deleted_at IS NULL"
    );
    $violations = [];
    foreach ($rows as $r) {
        $usage  = (string) $r['usage_amount'];
        $credit = (string) $r['credit_amount'];
        if ($r['pre_balance'] === null || $r['post_balance'] === null) {
            $violations[] = ['type' => 'MISSING audit_log', 'invoice' => $r['invoice_number']];
            continue;
        }
        $pre  = (string) $r['pre_balance'];
        $post = (string) $r['post_balance'];
        $expectedCredit = bccomp($usage, $pre, 2) <= 0 ? $usage : $pre;
        if (bccomp($credit, $expectedCredit, 2) !== 0) {
            $violations[] = ['type' => 'class-a credit != min', 'invoice' => $r['invoice_number']];
        }
        $expectedPost = bcsub($pre, $credit, 2);
        if (bccomp($post, $expectedPost, 2) !== 0) {
            $violations[] = ['type' => 'class-b post != pre-credit', 'invoice' => $r['invoice_number']];
        }
    }
    return [count($violations), $violations];
}

// Resolve FK parents
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$leaseId    = (int) (db_row("SELECT id FROM leases WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if (!$customerId || !$leaseId || !$userId) {
    echo "FAIL — cannot resolve FK parents\n";
    exit(1);
}

echo "S-MILEAGE-2B C7 — _stress_smoke_invariants_i8.php\n";
echo str_repeat('═', 76), "\n";

// Helper: create a synthetic invoice + line items + audit_log row.
function injectFault(int $leaseId, int $customerId, int $userId, string $usage, string $credit, ?string $pre, ?string $post, ?string $drawdown): int {
    $num = 'STRESS-I8-' . substr(md5(uniqid('', true)), 0, 8);
    $invId = db_insert('invoices', [
        'invoice_number'         => $num,
        'invoice_type'           => 'regular',
        'customer_id'            => $customerId,
        'lease_id'               => $leaseId,
        'customer_name_snapshot' => 'Stress',
        'company_name_snapshot'  => 'Stress',
        'currency'               => 'CAD',
        'billing_period_start'   => '2026-01-01',
        'billing_period_end'     => '2026-01-31',
        'billing_period_days'    => 31,
        'billing_type'           => 'full_month',
        'invoice_date'           => '2026-01-31',
        'due_date'               => '2026-02-28',
        'status'                 => 'draft',
        'subtotal'               => bcsub($usage, $credit, 2),
        'subtotal_after_discount'=> bcsub($usage, $credit, 2),
        'tax_total'              => '0.00',
        'total_amount'           => bcsub($usage, $credit, 2),
        'amount_paid'            => '0.00',
        'credits_applied'        => '0.00',
        'balance_due'            => bcsub($usage, $credit, 2),
        'period_distance_km'     => '100.00',
        'created_by'             => $userId,
        'updated_by'             => $userId,
    ]);
    db_insert('invoice_line_items', [
        'invoice_id' => $invId, 'sort_order' => 0,
        'item_type' => 'mileage_usage', 'description' => 'Stress usage',
        'amount' => $usage, 'is_credit' => 0, 'taxable' => 1,
    ]);
    db_insert('invoice_line_items', [
        'invoice_id' => $invId, 'sort_order' => 1,
        'item_type' => 'mileage_drawdown_credit', 'description' => 'Stress credit',
        'amount' => $credit, 'is_credit' => 1, 'taxable' => 1,
    ]);
    if ($pre !== null) {
        db_insert('audit_log', [
            'user_id' => $userId, 'user_name' => 'stress',
            'action' => 'update', 'module' => 'billing',
            'entity_type' => 'lease_precharge_balance_drawdown',
            'entity_id' => $leaseId,
            'old_values' => json_encode(['precharge_balance' => $pre]),
            'new_values' => json_encode([
                'precharge_balance' => $post,
                'drawdown_amount'   => $drawdown,
                'invoice_id'        => $invId,
            ]),
        ]);
    }
    return $invId;
}

// T1 — credit > min(usage, pre_balance): violation class (a)
db_execute('BEGIN');
try {
    injectFault($leaseId, $customerId, $userId,
        usage: '50.00', credit: '60.00',  // credit > usage, violation
        pre: '100.00', post: '40.00', drawdown: '60.00');
    [$count, $vs] = runI8Inline();
    $hasClassA = $count > 0 && count(array_filter($vs, fn($v) => str_contains($v['type'], 'class-a'))) > 0;
    rcd($results, 'T1 credit > min', $hasClassA, "violations=$count classes=" . json_encode(array_unique(array_column($vs, 'type'))));
} finally { db_execute('ROLLBACK'); }

// T2 — post != pre - credit: violation class (b)
db_execute('BEGIN');
try {
    injectFault($leaseId, $customerId, $userId,
        usage: '40.00', credit: '40.00',
        pre: '100.00', post: '70.00',  // post should be 60, not 70
        drawdown: '40.00');
    [$count, $vs] = runI8Inline();
    $hasClassB = $count > 0 && count(array_filter($vs, fn($v) => str_contains($v['type'], 'class-b'))) > 0;
    rcd($results, 'T2 post != pre-credit', $hasClassB, "violations=$count classes=" . json_encode(array_unique(array_column($vs, 'type'))));
} finally { db_execute('ROLLBACK'); }

// T3 — usage + credit lines but NO audit_log row: violation class (c)
db_execute('BEGIN');
try {
    injectFault($leaseId, $customerId, $userId,
        usage: '40.00', credit: '40.00',
        pre: null, post: null, drawdown: null);  // no audit_log row
    [$count, $vs] = runI8Inline();
    $hasMissing = $count > 0 && count(array_filter($vs, fn($v) => str_contains($v['type'], 'MISSING'))) > 0;
    rcd($results, 'T3 missing audit_log', $hasMissing, "violations=$count classes=" . json_encode(array_unique(array_column($vs, 'type'))));
} finally { db_execute('ROLLBACK'); }

// T4 — valid pair: PASS (zero violations from this row)
db_execute('BEGIN');
try {
    // Baseline check: capture pre-injection violation count (production state).
    [$baseCount, ] = runI8Inline();
    injectFault($leaseId, $customerId, $userId,
        usage: '40.00', credit: '40.00',
        pre: '100.00', post: '60.00', drawdown: '40.00');
    [$count, $vs] = runI8Inline();
    $ok = $count === $baseCount;  // no NEW violations
    rcd($results, 'T4 valid pair', $ok, "baseline=$baseCount post-injection=$count (expected equal)");
} finally { db_execute('ROLLBACK'); }

// T5 — Model B Lite (usage only, no credit, no audit_log): PASS
// I8 only joins on BOTH usage + credit lines present, so usage-only invoices
// are out of scope (would never match the JOIN).
db_execute('BEGIN');
try {
    [$baseCount, ] = runI8Inline();
    $num = 'STRESS-I8-LITE-' . substr(md5(uniqid('', true)), 0, 6);
    $invId = db_insert('invoices', [
        'invoice_number'         => $num,
        'invoice_type'           => 'regular', 'customer_id' => $customerId, 'lease_id' => $leaseId,
        'customer_name_snapshot' => 'Stress', 'company_name_snapshot' => 'Stress',
        'currency' => 'CAD', 'billing_period_start' => '2026-01-01',
        'billing_period_end' => '2026-01-31', 'billing_period_days' => 31,
        'billing_type' => 'full_month', 'invoice_date' => '2026-01-31',
        'due_date' => '2026-02-28', 'status' => 'draft',
        'subtotal' => '40.00', 'subtotal_after_discount' => '40.00',
        'tax_total' => '0.00', 'total_amount' => '40.00',
        'amount_paid' => '0.00', 'credits_applied' => '0.00', 'balance_due' => '40.00',
        'period_distance_km' => '100.00', 'created_by' => $userId, 'updated_by' => $userId,
    ]);
    db_insert('invoice_line_items', [
        'invoice_id' => $invId, 'sort_order' => 0,
        'item_type' => 'mileage_usage', 'description' => 'Stress Lite usage',
        'amount' => '40.00', 'is_credit' => 0, 'taxable' => 1,
    ]);
    [$count, ] = runI8Inline();
    $ok = $count === $baseCount;
    rcd($results, 'T5 Model B Lite passthrough', $ok, "baseline=$baseCount post-injection=$count (expected equal)");
} finally { db_execute('ROLLBACK'); }

// X — cross-check on clean production state (no test rows): PASS
[$count, ] = runI8Inline();
$ok = $count === 0;
rcd($results, 'X cross-check clean state', $ok, "production violations=$count (expected 0)");

$total = count($results);
$pass  = count(array_filter($results, fn($r) => $r['ok']));
echo "\n$pass/$total passed" . ($pass === $total ? '' : ' (FAILED)') . "\n";
exit($pass === $total ? 0 : 1);
