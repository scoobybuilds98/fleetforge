<?php
declare(strict_types=1);

/**
 * tests/_stress_refund_state_machine.php
 *
 * S-MILEAGE-3 C6 fault-injection stress for the precharge refund
 * state machine + the I9 smoke invariant (D-N).
 *
 * Strategy mirrors S-MILEAGE-2B C7 tests/_stress_smoke_invariants_i8.php:
 * inline-predicate execution (same connection as fault injection, so
 * BEGIN/ROLLBACK uncommitted rows are visible — a subprocess-isolation
 * gotcha confirmed in the I8 stress test development per D161).
 *
 * Cases:
 *   (a) Closed lease + credit refund + settled_at NOT NULL → I9 PASS
 *       (canonical credit-branch happy path)
 *   (b) Closed lease + cash refund + settled_at NULL → I9 PASS
 *       (D-B (i) deferred-settle; NULL settled_at is intentional)
 *   (c) Closed lease + cash refund + settled_at NOT NULL → I9 PASS
 *       (operator stamped settled_at via mark_refund_settled.php)
 *   (d) Closed lease + residual balance + method NULL → I9 FAIL
 *       (data integrity gap; would only surface if close.php D-K
 *        validator was bypassed)
 *   (e) Closed lease + credit refund + settled_at IS NULL → I9 FAIL
 *       (credit-branch should stamp at close-commit per D-E (ii);
 *        NULL indicates aborted transaction)
 *   (f) Active lease + precharge_balance > 0 + method NULL → I9 PASS
 *       (I9 only fires on closed leases; active leases are exempt
 *        per the WHERE clause)
 *
 * Hermetic via BEGIN/ROLLBACK; no production drift. The synthetic
 * lease + audit_log rows materialize inside the same transaction the
 * predicate evaluates against, so it sees both the inject + the
 * reconciliation in one connection.
 *
 * Decisions: D-N (I9 invariant), D-B (i) (cash branch defer-settle),
 *            D-E (ii) (settled_at = money moved), D-K (D-K state
 *            machine), D-L (audit_log entity_type).
 * Spec ref:  S-MILEAGE-3 spec block in FLEETFORGE_CURRENT_SESSIONS.md
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$results = [];

function recordCase(array &$results, string $case, bool $ok, string $msg): void {
    $results[] = ['case' => $case, 'ok' => $ok, 'msg' => $msg];
    $tag = $ok ? 'PASS' : 'FAIL';
    printf("  %s %s — %s\n", $tag, $case, $msg);
}

// Resolve FK parents
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-3 C6 — _stress_refund_state_machine.php\n";
echo str_repeat('═', 76), "\n";

/**
 * Inline-predicate evaluator for the I9 invariant body. Mirrors the
 * SELECT in tests/_smoke_billing_invariants.php (I9 block).
 * Returns array of violation strings (empty array = PASS).
 */
function evalI9(): array {
    $rows = db_select(
        "SELECT
            l.id              AS lease_id,
            l.contract_number,
            l.precharge_refund_method,
            l.precharge_refund_settled_at,
            JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.amount')) AS refund_amount_at_close
           FROM leases l
           LEFT JOIN audit_log al
                  ON al.entity_type = 'lease_precharge_refund_issued'
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.closed_by_user_id')) AS UNSIGNED) IS NOT NULL
                 AND al.entity_id = l.id
          WHERE l.status                = 'completed'
            AND l.deleted_at            IS NULL
            AND l.precharge_enabled     = 1
            AND l.precharge_invoiced_at IS NOT NULL
            AND al.id IS NOT NULL
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.amount')) AS DECIMAL(12,2)) > 0"
    );
    $vs = [];
    foreach ($rows as $r) {
        if ($r['precharge_refund_method'] === null) {
            $vs[] = "(a) lease={$r['lease_id']} method=NULL amount={$r['refund_amount_at_close']}";
            continue;
        }
        if ($r['precharge_refund_method'] === 'credit' && $r['precharge_refund_settled_at'] === null) {
            $vs[] = "(b) lease={$r['lease_id']} credit branch settled_at=NULL";
        }
    }
    return $vs;
}

/**
 * Inject a synthetic closed-lease + refund-issued audit_log row, run
 * predicate, capture violations from THIS injected lease only, then
 * ROLLBACK.
 */
$inject = function (string $caseTag, callable $configurator, callable $expector) use (&$results, $customerId, $unitId, $userId): void {
    db_execute('BEGIN');
    try {
        $leaseId = db_insert('leases', [
            'contract_number'         => 'STRESS-3-C6-' . substr(md5($caseTag . (string) microtime(true)), 0, 8),
            'customer_id'             => $customerId,
            'equipment_unit_id'       => $unitId,
            'start_date'              => '2026-01-01',
            'status'                  => 'completed',
            'daily_rate'              => '50.00',
            'weekly_rate'             => '300.00',
            'monthly_rate'            => '1000.00',
            'mileage_rate_km'         => '0.18',
            'mileage_unit'            => 'km',
            'estimated_mileage_km'    => '0',
            'currency'                => 'CAD',
            'billing_cycle'           => 'monthly',
            'odometer_start_km'       => '0.00',
            'precharge_enabled'       => 1,
            'precharge_amount'        => '500.00',
            'precharge_balance'       => '300.00',
            'precharge_invoiced_at'   => '2026-04-01 10:00:00',
            'closed_at'               => '2026-05-01 10:00:00',
            'closed_by_user_id'       => $userId,
        ]);

        // configurator stamps refund_method/settled_at + writes audit_log
        $configurator($leaseId, $userId);

        $vs = evalI9();
        // Filter for the injected lease only
        $myVs = array_values(array_filter($vs, fn($v) => str_contains($v, "lease={$leaseId} ")));

        $expector($myVs);
    } finally {
        db_execute('ROLLBACK');
    }
};

// ── (a) Credit + settled_at NOT NULL → PASS ──
$inject('a-credit-happy', function ($leaseId, $userId) {
    db_update('leases', [
        'precharge_refund_method'     => 'credit',
        'precharge_refund_settled_at' => '2026-05-01 10:00:01',
    ], 'id = ?', [$leaseId]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => 'stress',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_issued',
        'entity_id'    => $leaseId,
        'entity_label' => 'STRESS-3-C6-a',
        'notes'        => 'stress (a)',
        'new_values'   => json_encode(['amount' => '300.00', 'closed_by_user_id' => $userId, 'method' => 'credit']),
        'ip_address'   => '127.0.0.1',
    ]);
}, function ($vs) use (&$results) {
    recordCase($results, '(a) credit + settled_at NOT NULL', empty($vs), 'expected zero violations, got ' . count($vs));
});

// ── (b) Cash + settled_at NULL → PASS (D-B (i) defer-settle) ──
$inject('b-cash-deferred', function ($leaseId, $userId) {
    db_update('leases', [
        'precharge_refund_method'     => 'cash',
        'precharge_refund_settled_at' => null,
    ], 'id = ?', [$leaseId]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => 'stress',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_issued',
        'entity_id'    => $leaseId,
        'entity_label' => 'STRESS-3-C6-b',
        'notes'        => 'stress (b)',
        'new_values'   => json_encode(['amount' => '300.00', 'closed_by_user_id' => $userId, 'method' => 'cash']),
        'ip_address'   => '127.0.0.1',
    ]);
}, function ($vs) use (&$results) {
    recordCase($results, '(b) cash + settled_at NULL (defer-settle)', empty($vs), 'expected zero violations, got ' . count($vs));
});

// ── (c) Cash + settled_at NOT NULL → PASS ──
$inject('c-cash-settled', function ($leaseId, $userId) {
    db_update('leases', [
        'precharge_refund_method'     => 'cash',
        'precharge_refund_settled_at' => '2026-05-05 12:00:00',
    ], 'id = ?', [$leaseId]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => 'stress',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_issued',
        'entity_id'    => $leaseId,
        'entity_label' => 'STRESS-3-C6-c',
        'notes'        => 'stress (c)',
        'new_values'   => json_encode(['amount' => '300.00', 'closed_by_user_id' => $userId, 'method' => 'cash']),
        'ip_address'   => '127.0.0.1',
    ]);
}, function ($vs) use (&$results) {
    recordCase($results, '(c) cash + settled_at NOT NULL', empty($vs), 'expected zero violations, got ' . count($vs));
});

// ── (d) Method NULL + audit_log row exists → I9 FAIL (a) ──
$inject('d-method-null', function ($leaseId, $userId) {
    // Deliberately leave method NULL (D-K validator bypassed)
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => 'stress',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_issued',
        'entity_id'    => $leaseId,
        'entity_label' => 'STRESS-3-C6-d',
        'notes'        => 'stress (d) — bypassed D-K validator',
        'new_values'   => json_encode(['amount' => '300.00', 'closed_by_user_id' => $userId]),
        'ip_address'   => '127.0.0.1',
    ]);
}, function ($vs) use (&$results) {
    $caught = !empty($vs) && str_starts_with($vs[0], '(a)');
    recordCase($results, '(d) method NULL → expect violation (a)', $caught, $caught ? 'caught: ' . $vs[0] : 'failed to catch missing method');
});

// ── (e) Credit + settled_at NULL → I9 FAIL (b) ──
$inject('e-credit-aborted', function ($leaseId, $userId) {
    db_update('leases', [
        'precharge_refund_method'     => 'credit',
        'precharge_refund_settled_at' => null,   // <-- the bug: aborted transaction
    ], 'id = ?', [$leaseId]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => 'stress',
        'action'       => 'update',
        'module'       => 'leases',
        'entity_type'  => 'lease_precharge_refund_issued',
        'entity_id'    => $leaseId,
        'entity_label' => 'STRESS-3-C6-e',
        'notes'        => 'stress (e) — credit branch missing settled_at',
        'new_values'   => json_encode(['amount' => '300.00', 'closed_by_user_id' => $userId, 'method' => 'credit']),
        'ip_address'   => '127.0.0.1',
    ]);
}, function ($vs) use (&$results) {
    $caught = !empty($vs) && str_starts_with($vs[0], '(b)');
    recordCase($results, '(e) credit + settled_at NULL → expect violation (b)', $caught, $caught ? 'caught: ' . $vs[0] : 'failed to catch credit-branch unstamped');
});

// ── (f) Active lease + balance > 0 + method NULL → PASS (I9 scope = closed only) ──
db_execute('BEGIN');
try {
    $leaseId = db_insert('leases', [
        'contract_number'         => 'STRESS-3-C6-f-' . substr(md5((string) microtime(true)), 0, 8),
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'start_date'              => '2026-01-01',
        'status'                  => 'active',   // <-- active, not completed
        'daily_rate'              => '50.00',
        'weekly_rate'             => '300.00',
        'monthly_rate'            => '1000.00',
        'mileage_rate_km'         => '0.18',
        'mileage_unit'            => 'km',
        'estimated_mileage_km'    => '0',
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'odometer_start_km'       => '0.00',
        'precharge_enabled'       => 1,
        'precharge_amount'        => '500.00',
        'precharge_balance'       => '300.00',
        'precharge_invoiced_at'   => '2026-04-01 10:00:00',
    ]);
    $vs = evalI9();
    $myVs = array_values(array_filter($vs, fn($v) => str_contains($v, "lease={$leaseId} ")));
    recordCase($results, '(f) active lease + balance > 0 → I9 exempt', empty($myVs), 'expected zero violations on active lease, got ' . count($myVs));
} finally {
    db_execute('ROLLBACK');
}

// ── Summary ──
$failed = count(array_filter($results, fn($r) => !$r['ok']));
$passed = count($results) - $failed;
echo str_repeat('═', 76), "\n";
printf("Results: %d/%d PASS, %d FAIL\n", $passed, count($results), $failed);
exit($failed === 0 ? 0 : 1);
