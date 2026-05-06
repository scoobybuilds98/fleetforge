<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_invariants.php
 *
 * S-BILLING-RATE-FIX D-F / D132 + S-MILEAGE-RATE-VALIDATION D-D / D133 —
 * runtime invariant smoke test for the billing rate-tier discipline.
 * Catches the class of bug found by the 2026-05-06 audit (INV-2026-00086
 * zero-base-rental) AND its mileage-tier counterpart on every code path
 * that mutates lease or invoice rows.
 *
 * Five invariants, all must hold:
 *
 *   I1 — No draft invoice carries subtotal=0 unless it has an explicit
 *        legitimate justification. Drafts with subtotal=0 that are NOT
 *        invoice_type in {credit_note, mileage_only, adjustment} AND have
 *        no manual_adjustment line item AND are not credit notes
 *        referencing another invoice are violations.
 *
 *   I2 — No active lease (deleted_at IS NULL) with billing_cycle='monthly'
 *        has a rate-tier-with-siblings hole: when any of (daily, weekly,
 *        monthly) > 0, all must be > 0. D132 invariant.
 *
 *   I3 — No equipment_template has the same hole on its default rates.
 *
 *   I4 — D133 lease-side: no lease (deleted_at IS NULL) has an intent
 *        signal — estimated_mileage_km > 0 OR precharge_enabled = 1 —
 *        with mileage_rate_km = 0 (or NULL). Mirrors the C1 engine throw
 *        and the C2 API validator at the data layer.
 *
 *   I5 — D133 invoice-side: no non-void invoice (status IN ('draft','sent'),
 *        deleted_at IS NULL) has period_distance_km > 0 against a parent
 *        lease whose mileage_rate_km = 0. Catches any draft/sent invoice
 *        that records distance against a zero-rate lease — the historical
 *        silent-zero-billing pattern.
 *
 * Exit 0 on all pass, exit 1 with the failing rows on any fail.
 *
 * D131 gate: every schema-touching session must run this smoke test
 * alongside tests/_smoke_master_schema_parity.php.
 *
 * Decisions: D131 (smoke gate), D132 (rental rate-tier completeness),
 *            D133 (mileage rate-tier completeness)
 * Spec ref:  S-BILLING-RATE-FIX, S-MILEAGE-RATE-VALIDATION
 */

require_once dirname(__DIR__) . '/config/app.php';

$failures = [];

// ────────────────────────────────────────────────────────────────────────────
// I1 — No unjustified zero-subtotal drafts
//
// Excludes:
//   - invoice_type in (credit_note, mileage_only, adjustment) — these
//     legitimately carry $0 base_rental.
//   - has at least one manual_adjustment line item — operator chose to
//     add a $0 manual adjustment for a corner case.
//   - has a credit_note_for_invoice_id reference — credit notes against
//     another invoice can legitimately net to 0.
//   - status='void' — voided drafts have a structured void_reason.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT i.id, i.invoice_number, i.lease_id, i.subtotal, i.invoice_type, i.status
     FROM invoices i
     WHERE i.status = 'draft'
       AND i.subtotal = 0
       AND i.invoice_type NOT IN ('credit_note', 'mileage_only', 'adjustment')
       AND i.credit_note_for_invoice_id IS NULL
       AND i.deleted_at IS NULL
       AND NOT EXISTS (
           SELECT 1 FROM invoice_line_items li
           WHERE li.invoice_id = i.id
             AND li.item_type = 'manual_adjustment'
       )
     ORDER BY i.id"
);
if ($rows) {
    $lines = ["I1 FAIL — " . count($rows) . " draft invoice(s) with unjustified subtotal=0:"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  id=%d  %-16s  lease=%-6s  invoice_type=%s",
            $r['id'], $r['invoice_number'], (string)$r['lease_id'], $r['invoice_type']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// I2 — No lease rate-tier hole
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT id, contract_number, daily_rate, weekly_rate, monthly_rate, status
     FROM leases
     WHERE deleted_at IS NULL
       AND billing_cycle = 'monthly'
       AND (
           (daily_rate   = 0 AND (weekly_rate  > 0 OR monthly_rate > 0))
        OR (weekly_rate  = 0 AND  daily_rate   > 0 AND monthly_rate > 0)
        OR (monthly_rate = 0 AND (daily_rate   > 0 OR weekly_rate  > 0))
       )
     ORDER BY id"
);
if ($rows) {
    $lines = ["I2 FAIL — " . count($rows) . " active lease(s) with rate-tier hole (D132):"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  id=%d  %-30s  status=%-9s  daily=%s  weekly=%s  monthly=%s",
            $r['id'], $r['contract_number'], $r['status'],
            (string)$r['daily_rate'], (string)$r['weekly_rate'], (string)$r['monthly_rate']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// I3 — No equipment_template rate-tier hole
//
// Templates use NULL to mean "tier not configured". The invariant only
// fires when at least one tier IS configured (> 0) AND another required
// tier is null/0 — that's the ambiguous shape that produced the upstream
// bug. A template with all three tiers null is a separate concern (no
// rates configured at all) and out of scope here.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT id, name,
            default_daily_rate, default_weekly_rate, default_monthly_rate
     FROM equipment_templates
     WHERE (
         ((default_daily_rate   IS NULL OR default_daily_rate   = 0) AND (default_weekly_rate  > 0 OR default_monthly_rate > 0))
      OR ((default_weekly_rate  IS NULL OR default_weekly_rate  = 0) AND  default_daily_rate   > 0 AND default_monthly_rate > 0)
      OR ((default_monthly_rate IS NULL OR default_monthly_rate = 0) AND (default_daily_rate   > 0 OR default_weekly_rate  > 0))
     )
     ORDER BY id"
);
if ($rows) {
    $lines = ["I3 FAIL — " . count($rows) . " equipment_template(s) with rate-tier hole (D132):"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  id=%d  %-30s  daily=%s  weekly=%s  monthly=%s",
            $r['id'], $r['name'],
            $r['default_daily_rate']   === null ? 'NULL' : (string)$r['default_daily_rate'],
            $r['default_weekly_rate']  === null ? 'NULL' : (string)$r['default_weekly_rate'],
            $r['default_monthly_rate'] === null ? 'NULL' : (string)$r['default_monthly_rate']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// I4 — D133 lease-side: intent signal present but mileage_rate_km = 0
//
// Trigger: estimated_mileage_km > 0 (allowance configured) OR
//          precharge_enabled = 1 (operator opted into precharge model).
// Required: mileage_rate_km > 0.
//
// COALESCE(NULL, 0) so NULL columns count as 0 in the comparison.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT id, contract_number, status,
            estimated_mileage_km, precharge_enabled,
            mileage_rate_km, mileage_unit
     FROM leases
     WHERE deleted_at IS NULL
       AND (
            COALESCE(estimated_mileage_km, 0) > 0
         OR COALESCE(precharge_enabled,    0) = 1
       )
       AND COALESCE(mileage_rate_km, 0) = 0
     ORDER BY id"
);
if ($rows) {
    $lines = ["I4 FAIL — " . count($rows) . " lease(s) with mileage intent signal but mileage_rate_km = 0 (D133):"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  id=%d  %-30s  status=%-9s  est_km=%s  precharge_enabled=%s  rate_km=%s",
            $r['id'], $r['contract_number'], $r['status'],
            $r['estimated_mileage_km']  === null ? 'NULL' : (string)$r['estimated_mileage_km'],
            (string)(int)($r['precharge_enabled'] ?? 0),
            $r['mileage_rate_km']       === null ? 'NULL' : (string)$r['mileage_rate_km']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// I5 — D133 invoice-side: non-void invoice with positive distance against a
// zero-rate lease.
//
// Status IN ('draft','sent') excludes void + paid + written_off (a paid
// invoice is locked; a void invoice already has a structured void_reason).
// deleted_at IS NULL excludes soft-deleted invoices/leases.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT i.id AS invoice_id, i.invoice_number, i.status AS invoice_status,
            i.lease_id, l.contract_number, i.period_distance_km, l.mileage_rate_km
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
     WHERE i.deleted_at IS NULL
       AND i.status IN ('draft','sent')
       AND COALESCE(i.period_distance_km, 0) > 0
       AND COALESCE(l.mileage_rate_km,    0) = 0
     ORDER BY i.id"
);
if ($rows) {
    $lines = ["I5 FAIL — " . count($rows) . " non-void invoice(s) with period_distance_km > 0 against zero-rate lease (D133):"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  invoice_id=%d  %-16s  status=%-7s  lease_id=%d  %-30s  distance=%s  rate_km=%s",
            $r['invoice_id'], $r['invoice_number'], $r['invoice_status'],
            (int)$r['lease_id'], $r['contract_number'],
            (string)$r['period_distance_km'],
            (string)$r['mileage_rate_km']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// Output
// ────────────────────────────────────────────────────────────────────────────

echo "FleetForge — billing invariants smoke test (S-BILLING-RATE-FIX D132 + S-MILEAGE-RATE-VALIDATION D133)\n";
echo str_repeat('═', 78), "\n";

if (!$failures) {
    echo "INVARIANTS OK — I1 (no unjustified zero-base drafts), I2 (no lease rate-tier holes), I3 (no template rate-tier holes), I4 (no lease mileage-intent + zero-rate hole), I5 (no invoice with distance against zero-rate lease).\n";
    exit(0);
}

foreach ($failures as $f) echo $f, "\n\n";
echo "INVARIANTS FAIL — " . count($failures) . " invariant(s) violated. See above.\n";
exit(1);
