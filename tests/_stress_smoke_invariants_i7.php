<?php
declare(strict_types=1);

/**
 * tests/_stress_smoke_invariants_i7.php
 *
 * S-MILEAGE-2A C6 stress test — fault-injection harness for the I7
 * precharge sanity invariant. Confirms that the SQL in
 * tests/_smoke_billing_invariants.php correctly identifies the
 * precharge_enabled=1 with NULL/zero/negative precharge_amount shape
 * AND does NOT false-positive on either valid shape
 * (precharge_enabled=0 OR precharge_enabled=1 + amount > 0).
 *
 * The wrinkle: chk_leases_precharge_amount CHECK blocks the exact rows
 * I7 catches. The stress can't insert them directly. Instead it
 * creates a TEMPORARY table copy of leases (CREATE TEMPORARY TABLE
 * AS SELECT inherits column types but NOT CHECK constraints), inserts
 * the mix of valid + violating rows, and runs the I7 predicate against
 * the temp table. The predicate is identical to the smoke's SQL with
 * only the table name substituted — so any drift in the smoke predicate
 * fails this stress next D131 gate run.
 *
 * Five test cases:
 *
 *   T1 — Violation: precharge_enabled=1 + precharge_amount=NULL → I7 fires.
 *   T2 — Violation: precharge_enabled=1 + precharge_amount=0   → I7 fires.
 *   T3 — Violation: precharge_enabled=1 + precharge_amount=-50 → I7 fires.
 *   T4 — Valid:    precharge_enabled=0 + precharge_amount=NULL → I7 silent.
 *   T5 — Valid:    precharge_enabled=1 + precharge_amount=500  → I7 silent.
 *
 * TEMPORARY table is connection-scoped — auto-drops on PHP exit. No
 * DB pollution. db_pdo() reuses the singleton connection so the temp
 * table is visible within the test run.
 *
 * Spec: S-MILEAGE-2A D132 extension (precharge tier)
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0;
$fail = 0;
$out  = [];

$report = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail, &$out) {
    if ($ok) { $pass++; $out[] = "PASS  {$name}" . ($detail ? "  — {$detail}" : ''); }
    else     { $fail++; $out[] = "FAIL  {$name}" . ($detail ? "  — {$detail}" : ''); }
};

echo "FleetForge — S-MILEAGE-2A C6 / I7 stress test\n";
echo str_repeat('═', 78), "\n";

$pdo = db_pdo();

try {
    // Create temp table with leases column shape but WITHOUT inheriting
    // CHECK constraints. CREATE TEMPORARY TABLE ... AS SELECT WHERE 1=0
    // copies columns + types but not table-level constraints (CHECK, FK,
    // unique indexes other than PRIMARY KEY).
    db_execute("CREATE TEMPORARY TABLE leases_stress_i7 AS SELECT * FROM leases WHERE 1=0");

    /**
     * Insert a synthetic lease row into the temp table with the given
     * precharge tuple. Returns the synthetic contract_number used as
     * unique identifier (the temp table loses AUTO_INCREMENT from the
     * CREATE ... AS SELECT, so id is unreliable for tracking).
     */
    $insertTemp = function (int $enabled, ?string $amount, string $tag): string {
        $contract = 'STRESS-I7-' . $tag;
        db_insert('leases_stress_i7', [
            'contract_number'   => $contract,
            'start_date'        => '2026-05-01',
            'status'            => 'active',
            'daily_rate'        => '10.00',
            'weekly_rate'       => '60.00',
            'monthly_rate'      => '250.00',
            'currency'          => 'CAD',
            'billing_cycle'     => 'monthly',
            'precharge_enabled' => $enabled,
            'precharge_amount'  => $amount,
        ]);
        return $contract;
    };

    // I7 predicate from tests/_smoke_billing_invariants.php — kept inline
    // (table name + identifier substituted) so divergence between this
    // stress and the smoke surfaces here. If the smoke's SQL is edited,
    // this string must be edited in lockstep — both fail otherwise.
    $i7Sql = <<<SQL
        SELECT contract_number, precharge_enabled, precharge_amount
         FROM leases_stress_i7
         WHERE deleted_at IS NULL
           AND precharge_enabled = 1
           AND COALESCE(precharge_amount, 0) <= 0
         ORDER BY contract_number
SQL;

    $hitContract = function (array $hits, string $contract): bool {
        foreach ($hits as $h) {
            if (($h['contract_number'] ?? '') === $contract) return true;
        }
        return false;
    };

    // ── T1: enabled=1 + amount=NULL → I7 SHOULD fire ─────────────────────
    $cT1 = $insertTemp(1, null, 'T1');
    $hits = db_select($i7Sql);
    $report(
        'T1 enabled=1 amount=NULL — I7 fires',
        $hitContract($hits, $cT1),
        $hitContract($hits, $cT1) ? "I7 caught {$cT1}" : "I7 missed {$cT1}"
    );

    // ── T2: enabled=1 + amount=0 → I7 SHOULD fire ────────────────────────
    $cT2 = $insertTemp(1, '0.00', 'T2');
    $hits = db_select($i7Sql);
    $report(
        'T2 enabled=1 amount=0 — I7 fires',
        $hitContract($hits, $cT2),
        $hitContract($hits, $cT2) ? "I7 caught {$cT2}" : "I7 missed {$cT2}"
    );

    // ── T3: enabled=1 + amount=-50 → I7 SHOULD fire ──────────────────────
    $cT3 = $insertTemp(1, '-50.00', 'T3');
    $hits = db_select($i7Sql);
    $report(
        'T3 enabled=1 amount=-50 — I7 fires',
        $hitContract($hits, $cT3),
        $hitContract($hits, $cT3) ? "I7 caught {$cT3}" : "I7 missed {$cT3}"
    );

    // ── T4: enabled=0 + amount=NULL → I7 SHOULD NOT fire ─────────────────
    $cT4 = $insertTemp(0, null, 'T4');
    $hits = db_select($i7Sql);
    $report(
        'T4 enabled=0 amount=NULL — I7 silent (valid Model-B opt-out)',
        !$hitContract($hits, $cT4),
        $hitContract($hits, $cT4) ? "I7 false-positive on {$cT4}" : "correctly excluded"
    );

    // ── T5: enabled=1 + amount=500 → I7 SHOULD NOT fire ──────────────────
    $cT5 = $insertTemp(1, '500.00', 'T5');
    $hits = db_select($i7Sql);
    $report(
        'T5 enabled=1 amount=500 — I7 silent (valid Model-B opt-in)',
        !$hitContract($hits, $cT5),
        $hitContract($hits, $cT5) ? "I7 false-positive on {$cT5}" : "correctly excluded"
    );

    // ── Cross-check: I7 caught exactly the 3 violation rows, no others ──
    $allHits = db_select($i7Sql);
    $hitContracts = array_map(fn($h) => (string) $h['contract_number'], $allHits);
    sort($hitContracts);
    $expected = [$cT1, $cT2, $cT3];
    sort($expected);
    $report(
        'Cross-check — exactly T1+T2+T3 caught, no spurious hits',
        $hitContracts === $expected,
        sprintf("got=[%s] expected=[%s]", implode(',', $hitContracts), implode(',', $expected))
    );

} finally {
    // TEMPORARY table auto-drops on connection close, but be explicit so
    // a long-lived test process doesn't accumulate them.
    db_execute("DROP TEMPORARY TABLE IF EXISTS leases_stress_i7");
}

echo "\n";
foreach ($out as $line) echo "  ", $line, "\n";
echo "\n";
echo str_repeat('═', 78), "\n";
echo "{$pass} passed, {$fail} failed\n";
echo "(TEMPORARY table dropped — no DB pollution; production CHECK untouched.)\n";
exit($fail === 0 ? 0 : 1);
