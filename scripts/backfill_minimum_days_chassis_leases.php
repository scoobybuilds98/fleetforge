<?php
/**
 * scripts/backfill_minimum_days_chassis_leases.php
 *
 * S-LEASE-MIN-DAYS — backfill the per-lease short-lease floor onto EXISTING
 * active chassis leases.
 *
 * The S-LEASE-MIN-DAYS migration adds leases.minimum_billing_days (config layer 2)
 * and freezes it on NEW leases at creation. Pre-existing leases created before the
 * feature have minimum_billing_days = NULL, so the floor never binds for them even
 * though chassis are the primary short-lease use case. This one-time backfill sets
 * minimum_billing_days = 3 on the leases that should carry the floor going forward.
 *
 * SCOPE — deliberately narrow, so settled billing is never disturbed:
 *   - Only ACTIVE/open leases (leases.status = 'active'). Closed/historical leases
 *     ('completed', 'cancelled') and unstarted drafts ('pending') are NEVER touched —
 *     their billing is already settled (or not yet frozen) and a retroactive floor
 *     could change amounts the customer was already invoiced.
 *   - Only CHASSIS leases: the lease's unit resolves to an equipment_templates row
 *     whose category = 'chassis'. This mirrors the rate-lookup join in
 *     api/v1/leases/lookup_rates.php (equipment_type key = template CATEGORY enum)
 *     and the migration's chassis rate-card seed (equipment_type = 'chassis').
 *   - Only rows where minimum_billing_days IS NULL: never overwrite a lease that
 *     already carries a floor (operator-set or frozen at creation). This also makes
 *     the script idempotent — a second run matches nothing.
 *
 * JOIN (mirrors lookup_rates.php's category semantic):
 *   leases.equipment_unit_id → equipment_units.id
 *   equipment_units.template_id → equipment_templates.id  (category = 'chassis')
 *
 * SAFETY:
 *   - DRY RUN by default — prints the affected count, a sample of lease ids, and the
 *     exact UPDATE SQL; mutates NOTHING.
 *   - --commit — execute the UPDATE.
 *   - PROD is operator-only: per repo policy the agent NEVER runs writes against the
 *     live host. On prod this script must be run by the OPERATOR.
 *
 * IDEMPOTENT: re-running finds nothing (the NULL predicate excludes rows it set).
 *
 * USAGE:
 *   php scripts/backfill_minimum_days_chassis_leases.php             # dry run (default)
 *   php scripts/backfill_minimum_days_chassis_leases.php --commit    # execute
 *
 * Session: S-LEASE-MIN-DAYS
 * Date:    2026-06-19
 */

declare(strict_types=1);

// config/app.php is the CLI bootstrap (loads includes/db.php + functions). It does
// NOT load auth.php on the CLI path, so current_user_id() would be undefined; load
// it explicitly per the remediation-script convention (memory: remediation scripts
// must load includes/auth.php for current_user_id() in CLI). Including it only
// DEFINES the auth helpers — session start stays lazy — so it is CLI-safe.
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// ── Args ─────────────────────────────────────────────────────────────────────
// S-LEASE-MIN-DAYS: default to DRY RUN; only --commit writes. Mirrors the
// --apply gate idiom in scripts/remediate_close_overshoot_2026_06_19.php, kept
// as --commit here to match this feature's prompt contract.
$commit = in_array('--commit', $argv, true);

echo ($commit
    ? "COMMIT MODE — minimum_billing_days WILL be written.\n"
    : "DRY RUN — no changes (pass --commit to execute).\n");
echo str_repeat('=', 90) . "\n";

// ── Selection predicate ──────────────────────────────────────────────────────
// S-LEASE-MIN-DAYS: the WHERE clause is the contract of this backfill — three
// conjuncts that keep it narrow and safe:
//   1. l.status = 'active'            → open leases only (never closed/historical).
//   2. et.category = 'chassis'        → chassis equipment only (the short-lease case).
//   3. l.minimum_billing_days IS NULL → never overwrite an existing floor (idempotent).
// l.deleted_at IS NULL excludes soft-deleted leases. INNER JOINs to the unit and
// template mean a lease with a NULL/missing equipment_unit_id is naturally excluded.
$whereSql = "l.status = 'active'
       AND l.deleted_at IS NULL
       AND et.category = 'chassis'
       AND l.minimum_billing_days IS NULL";

$joinSql = "FROM leases l
       JOIN equipment_units     eu ON eu.id = l.equipment_unit_id
       JOIN equipment_templates et ON et.id = eu.template_id";

// ── Inspect the affected set (read-only in both modes) ───────────────────────
$rows = db_select(
    "SELECT l.id, l.contract_number, l.unit_number_snapshot, et.name AS template_name
       {$joinSql}
      WHERE {$whereSql}
      ORDER BY l.id",
    []
);
$affected = count($rows);

// The exact UPDATE the --commit path runs (shown in dry run for operator review).
// S-LEASE-MIN-DAYS: the UPDATE re-states the same predicate as the SELECT above so
// the row set is identical; the JOIN scopes it to chassis units, the NULL guard
// keeps it idempotent, status='active' protects settled billing.
$updateSql =
    "UPDATE leases l
       JOIN equipment_units     eu ON eu.id = l.equipment_unit_id
       JOIN equipment_templates et ON et.id = eu.template_id
        SET l.minimum_billing_days = 3
      WHERE l.status = 'active'
        AND l.deleted_at IS NULL
        AND et.category = 'chassis'
        AND l.minimum_billing_days IS NULL";

// ── Report ───────────────────────────────────────────────────────────────────
printf("Affected active chassis lease(s) with NULL minimum_billing_days: %d\n", $affected);

if ($affected > 0) {
    // Sample up to the first 20 lease ids so the operator can eyeball the target set.
    $sample = array_slice($rows, 0, 20);
    echo "\nSample (first " . count($sample) . " of {$affected}):\n";
    foreach ($sample as $r) {
        printf("  • lease #%d  %s  unit=%s  template=%s\n",
            (int) $r['id'],
            (string) $r['contract_number'],
            (string) ($r['unit_number_snapshot'] ?? '—'),
            (string) ($r['template_name'] ?? '—')
        );
    }
    if ($affected > count($sample)) {
        printf("  … and %d more.\n", $affected - count($sample));
    }
}

echo "\nUPDATE SQL:\n" . $updateSql . "\n";

// ── DRY RUN exit ─────────────────────────────────────────────────────────────
if (!$commit) {
    echo "\n" . str_repeat('=', 90) . "\n";
    echo "DRY RUN complete — nothing written. Re-run with --commit to apply.\n";
    echo "PROD: run this script as the OPERATOR (agent never writes to the live host).\n";
    exit(0);
}

// ── COMMIT ───────────────────────────────────────────────────────────────────
// S-LEASE-MIN-DAYS: single set-based UPDATE — no per-row loop needed; the NULL
// guard makes a partial/interrupted run safe to re-run.
$updated = db_execute($updateSql, []);

echo "\n" . str_repeat('=', 90) . "\n";
printf("COMMIT complete — set minimum_billing_days = 3 on %d active chassis lease(s).\n", $updated);
exit(0);
