<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_invariants.php
 *
 * S-BILLING-RATE-FIX D-F / D132 + S-MILEAGE-RATE-VALIDATION D-D / D133 +
 * S-MILEAGE-ALLOWANCE-ZERO-FIX D-B / D133 clarification +
 * S-MILEAGE-2A C6 / I7 (D132 precharge extension) — runtime invariant
 * smoke test for the billing rate-tier discipline. Catches the class of bug
 * found by the 2026-05-06 audit (INV-2026-00086 zero-base-rental), its
 * mileage-tier counterpart, the silent-skip class on Model B Lite leases
 * (rate>0 + allowance=0), AND the precharge sanity rule (Model B leases
 * with precharge_enabled=1 must carry a positive precharge_amount).
 *
 * Seven invariants, all must hold:
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
 *   I6 — D133 silent-skip class: no non-void invoice with period_distance_km
 *        > 0 against a rate>0 lease has mileage_review_status='not_required'
 *        AND no mileage line item. Catches the bug pattern fixed by
 *        S-MILEAGE-ALLOWANCE-ZERO-FIX C1 — the engine should have flagged
 *        review='pending' or written a line item; 'not_required' with no line
 *        means the engine silent-skipped a billable charge. invoice_type
 *        'mileage_only' excluded (those ARE the mileage line by definition).
 *
 *   I7 — D132 precharge extension: no active lease (deleted_at IS NULL) has
 *        precharge_enabled = 1 with a NULL, zero, or negative precharge_amount.
 *        Mirror of chk_leases_precharge_amount at the invariant layer —
 *        catches drift if the CHECK is ever weakened (NOT ENFORCED, dropped,
 *        rewritten) or if a privileged write bypasses it. Locked by
 *        S-MILEAGE-2A as a D132 extension into the precharge tier (parallels
 *        I2/I3/I4 for rental + mileage rate tiers).
 *
 * Exit 0 on all pass, exit 1 with the failing rows on any fail.
 *
 * D131 gate: every schema-touching session must run this smoke test
 * alongside tests/_smoke_master_schema_parity.php.
 *
 * Decisions: D131 (smoke gate), D132 (rental rate-tier completeness +
 *            S-MILEAGE-2A precharge extension), D133 (mileage rate-tier
 *            completeness + silent-skip)
 * Spec ref:  S-BILLING-RATE-FIX, S-MILEAGE-RATE-VALIDATION,
 *            S-MILEAGE-ALLOWANCE-ZERO-FIX, S-MILEAGE-2A
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
// I6 — D133 silent-skip class: non-void invoice with positive distance against
// a Model B Lite lease (rate>0, allowance=0) has mileage_review_status=
// 'not_required' AND no mileage line. This is the exact bug shape fixed by
// S-MILEAGE-ALLOWANCE-ZERO-FIX C1.
//
// Pre-fix engine guard required estimated_mileage_km > 0 to enter the calc,
// so leases with rate>0 + allowance=0 silent-skipped: review_status stayed
// 'not_required' and no line item was written despite distance being
// recorded. After the C1 fix, the engine sets review='pending' for any
// positive excess (allowance=0 means all distance is excess), so any
// draft in 'not_required' state with this shape is the silent-skip bug.
//
// Scope deliberately narrow:
//   estimated_mileage_km = 0 — Model B Lite lease shape only.
// Model C silent-skip (rate>0 + allowance>0 + review='not_required') would
// require excess math vs allowance to disambiguate from the legitimate
// "distance ≤ allowance" case, which the engine itself is the source of
// truth for. I6 doesn't second-guess the engine on Model C; it pins the
// specific Model B Lite shape that the C1 fix targets.
//
// Statuses considered:
//   draft + sent  — checked
//   void          — excluded (already structured void_reason)
//   paid + written_off — excluded (post-billing terminal states)
//
// review_status mapping:
//   'not_required'        — engine chose not to flag (BUG for Model B Lite + distance>0)
//   'pending'             — engine flagged correctly, manager hasn't acted (OK)
//   'approved'            — manager approved → line item present (OK)
//   'overridden'          — manager waived/adjusted (OK; line item or zero ack)
//
// invoice_type 'mileage_only' excluded — those carry the mileage line by
// definition and don't go through the regular review gate.
// ────────────────────────────────────────────────────────────────────────────

// S-MILEAGE-2B C4 refactor: I6 now adapted for Model B (Model C dropped).
// Original I6 keyed on mileage_review_status='not_required' (dropped in C4).
// Model B equivalent: invoice with period_distance_km > 0 + lease rate > 0
// MUST emit at least one mileage line item (mileage_usage from drawdown emit,
// or mileage_precharge from 2A, or the legacy mileage_adjustment/credit from
// historical Model C drafts). Silent-skip would be a missing line item.
//
// Scope filter: created_at >= MODEL_B_SHIP_DATE. Pre-C3 Model C drafts that
// correctly skipped mileage under Model C semantics (e.g. distance < allowance)
// are exempt — they were correct under the engine in effect at their
// generation time. I6's purpose is to catch BUGS in the current Model B
// engine, not historical Model C drift (per K-15 / D129 audit scope discipline).
//
// MODEL_B_SHIP_DATE = '2026-05-12' (S-MILEAGE-2B C3 InvoiceGenerator drawdown
// emit shipped 2026-05-12; commit a24cb49).
$rows = db_select(
    "SELECT i.id AS invoice_id, i.invoice_number, i.status AS invoice_status,
            i.lease_id, l.contract_number, i.period_distance_km,
            l.mileage_rate_km, l.estimated_mileage_km
     FROM invoices i
     JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
     WHERE i.deleted_at IS NULL
       AND i.status IN ('draft','sent')
       AND i.invoice_type != 'mileage_only'
       AND i.created_at >= '2026-05-12 00:00:00'
       AND COALESCE(i.period_distance_km, 0) > 0
       AND COALESCE(l.mileage_rate_km,    0) > 0
       AND NOT EXISTS (
         SELECT 1 FROM invoice_line_items ili
         WHERE ili.invoice_id = i.id
           AND ili.item_type IN (
             'mileage_adjustment',
             'mileage_precharge',
             'mileage_credit',
             'mileage_usage',
             'mileage_drawdown_credit'
           )
       )
     ORDER BY i.id"
);
if ($rows) {
    $lines = ["I6 FAIL — " . count($rows) . " non-void invoice(s) silent-skipped mileage despite rate>0 + distance>0 (Model B drawdown emit gap):"];
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
// I7 — D132 precharge extension: precharge_enabled=1 implies precharge_amount > 0
//
// Schema layer (chk_leases_precharge_amount CHECK at
// FLEETFORGE_DATABASE_MASTER.sql:2248) is the primary enforcement; the CHECK
// blocks malformed writes at INSERT/UPDATE time. I7 is the invariant-layer
// mirror: catches drift if the CHECK is ever weakened (ALTER ... NOT ENFORCED,
// dropped, rewritten under a NULL/zero gap) or if a privileged migration
// inserts a malformed row directly.
//
// The CHECK admits two valid shapes:
//   precharge_enabled = 0                                  (Model B opt-out)
//   precharge_enabled = 1 AND precharge_amount > 0         (Model B opt-in)
//
// Violations I7 catches:
//   precharge_enabled = 1 AND precharge_amount IS NULL
//   precharge_enabled = 1 AND precharge_amount = 0
//   precharge_enabled = 1 AND precharge_amount < 0
//
// COALESCE(precharge_amount, 0) <= 0 collapses NULL/zero/negative into a
// single condition.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT id, contract_number, status, precharge_enabled, precharge_amount,
            precharge_balance, precharge_invoiced_at
     FROM leases
     WHERE deleted_at IS NULL
       AND precharge_enabled = 1
       AND COALESCE(precharge_amount, 0) <= 0
     ORDER BY id"
);
if ($rows) {
    $lines = ["I7 FAIL — " . count($rows) . " lease(s) with precharge_enabled=1 but precharge_amount NULL/zero/negative (D132 precharge extension; chk_leases_precharge_amount drift):"];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  id=%d  %-30s  status=%-9s  precharge_amount=%s  precharge_balance=%s  precharge_invoiced_at=%s",
            $r['id'], $r['contract_number'], $r['status'],
            $r['precharge_amount']      === null ? 'NULL' : (string)$r['precharge_amount'],
            $r['precharge_balance']     === null ? 'NULL' : (string)$r['precharge_balance'],
            $r['precharge_invoiced_at'] === null ? 'NULL' : (string)$r['precharge_invoiced_at']
        );
    }
    $failures[] = implode("\n", $lines);
}

// ────────────────────────────────────────────────────────────────────────────
// I8 — Drawdown math sanity (S-MILEAGE-2B C7, D-O / D-B verification)
//
// For every invoice carrying both a mileage_usage and a mileage_drawdown_credit
// line, the credit's amount must equal min(usage_amount, pre-emission
// precharge_balance) AND the post-emission balance must equal pre − credit.
//
// Source-of-truth for pre/post balance + drawdown amount: the audit_log row
// with entity_type='lease_precharge_balance_drawdown' written by the C3
// drawdown emit. JOIN by JSON_EXTRACT(new_values, '$.invoice_id') = invoice.id.
//
// Three violation classes I8 catches:
//   (a) credit_amount != min(usage_amount, pre_balance)
//   (b) post_balance  != pre_balance - credit_amount
//   (c) usage line present + drawdown_credit line present + NO audit_log row
//       (means the emit happened but the audit_log INSERT failed silently —
//       data integrity gap)
//
// All checks BCmath-aware: amounts compared via bccomp at 2dp scale.
//
// Invoices that emit ONLY mileage_usage (Branch B per D-B; no precharge_balance
// at emit time) are not in scope — no drawdown audit_log row was written, no
// reconciliation needed.
// ────────────────────────────────────────────────────────────────────────────

$rows = db_select(
    "SELECT
        i.id AS invoice_id,
        i.invoice_number,
        i.status AS invoice_status,
        usage_li.amount AS usage_amount,
        credit_li.amount AS credit_amount,
        JSON_UNQUOTE(JSON_EXTRACT(al.old_values, '$.precharge_balance')) AS pre_balance,
        JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.precharge_balance')) AS post_balance,
        JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.drawdown_amount')) AS audit_drawdown_amount
     FROM invoices i
     JOIN invoice_line_items usage_li ON usage_li.invoice_id = i.id AND usage_li.item_type = 'mileage_usage'
     JOIN invoice_line_items credit_li ON credit_li.invoice_id = i.id AND credit_li.item_type = 'mileage_drawdown_credit'
     LEFT JOIN audit_log al ON al.entity_type = 'lease_precharge_balance_drawdown'
                            AND JSON_EXTRACT(al.new_values, '$.invoice_id') = i.id
     WHERE i.deleted_at IS NULL"
);
$i8Failures = [];
foreach ($rows as $r) {
    $usage  = (string) $r['usage_amount'];
    $credit = (string) $r['credit_amount'];
    if ($r['pre_balance'] === null || $r['post_balance'] === null) {
        $i8Failures[] = sprintf(
            "  invoice_id=%d  %-16s  status=%-7s  MISSING audit_log row (entity_type='lease_precharge_balance_drawdown')",
            $r['invoice_id'], $r['invoice_number'], $r['invoice_status']
        );
        continue;
    }
    $pre  = (string) $r['pre_balance'];
    $post = (string) $r['post_balance'];

    // (a) credit_amount == min(usage_amount, pre_balance)
    $expectedCredit = bccomp($usage, $pre, 2) <= 0 ? $usage : $pre;
    if (bccomp($credit, $expectedCredit, 2) !== 0) {
        $i8Failures[] = sprintf(
            "  invoice_id=%d  %-16s  credit=%s != min(usage=%s, pre_balance=%s)=%s",
            $r['invoice_id'], $r['invoice_number'],
            $credit, $usage, $pre, $expectedCredit
        );
    }

    // (b) post_balance == pre_balance - credit_amount
    $expectedPost = bcsub($pre, $credit, 2);
    if (bccomp($post, $expectedPost, 2) !== 0) {
        $i8Failures[] = sprintf(
            "  invoice_id=%d  %-16s  post_balance=%s != pre_balance(%s) − credit(%s) = %s",
            $r['invoice_id'], $r['invoice_number'],
            $post, $pre, $credit, $expectedPost
        );
    }
}
if ($i8Failures) {
    array_unshift($i8Failures, "I8 FAIL — " . count($i8Failures) . " drawdown math violation(s) (D-O / D-B):");
    $failures[] = implode("\n", $i8Failures);
}

// ────────────────────────────────────────────────────────────────────────────
// I9 — Precharge refund state machine sanity (S-MILEAGE-3 C6, D-N)
//
// For every closed lease (status='completed', deleted_at IS NULL) with
// precharge_enabled=1 AND precharge_invoiced_at IS NOT NULL AND a
// residual precharge_balance > 0 AT CLOSE TIME, the lease MUST have a
// non-NULL precharge_refund_method AND (for method='credit') a non-NULL
// precharge_refund_settled_at.
//
// The "at close time" balance is reconstructed via the audit_log row
// entity_type='lease_precharge_refund_issued' new_values JSON field
// `amount` (D-L; equals precharge_balance at close-commit). The live
// precharge_balance column post-close stays at the historical at-close
// value per the S-MILEAGE-3-SPEC-LOCK V1 verification (preserve, NOT
// zero — preserves audit trail of refund-vs-drawdown distinction).
//
// Violation classes:
//   (a) refund_method NULL when a refund should have fired (residual
//       balance > 0 at close + no method recorded — would only surface
//       if a close transaction bypassed the D-K validator)
//   (b) method='credit' AND settled_at IS NULL (credit-branch should
//       stamp at close-commit per D-E (ii); NULL indicates an aborted
//       transaction OR a code path that skipped the stamp)
//
// Cash-branch method='cash' AND settled_at IS NULL is NOT a violation
// by design — D-B (i) deferred-settle pattern; operator stamps
// settled_at later via api/v1/leases/mark_refund_settled.php when the
// physical disbursement happens.
//
// For leases that closed WITHOUT a residual balance (drawdown fully
// consumed precharge_balance), refund_method stays NULL and I9 doesn't
// fire — the gate at line ~830 in close.php (needsRefund check)
// silently skips the refund block.
// ────────────────────────────────────────────────────────────────────────────

$i9Rows = db_select(
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

$i9Failures = [];
foreach ($i9Rows as $r) {
    // (a) Method NULL when refund should have fired
    if ($r['precharge_refund_method'] === null) {
        $i9Failures[] = sprintf(
            "  lease_id=%d  %-22s  refund_amount_at_close=%s  but precharge_refund_method IS NULL (D-K validator bypassed?)",
            $r['lease_id'], $r['contract_number'], $r['refund_amount_at_close']
        );
        continue;
    }

    // (b) Credit branch must have settled_at stamped at close-commit
    if ($r['precharge_refund_method'] === 'credit' && $r['precharge_refund_settled_at'] === null) {
        $i9Failures[] = sprintf(
            "  lease_id=%d  %-22s  method=credit but precharge_refund_settled_at IS NULL (D-E (ii) gap — close commit didn't stamp)",
            $r['lease_id'], $r['contract_number']
        );
    }

    // Cash-branch NULL settled_at is NOT a violation (D-B (i) defer-settle).
}
if ($i9Failures) {
    array_unshift($i9Failures, "I9 FAIL — " . count($i9Failures) . " refund state machine violation(s) (D-N / D-B (i) / D-E (ii)):");
    $failures[] = implode("\n", $i9Failures);
}

// ────────────────────────────────────────────────────────────────────────────
// Output
// ────────────────────────────────────────────────────────────────────────────

echo "FleetForge — billing invariants smoke test (S-BILLING-RATE-FIX D132 + S-MILEAGE-RATE-VALIDATION D133 + S-MILEAGE-ALLOWANCE-ZERO-FIX + S-MILEAGE-2A D132 precharge ext. + S-MILEAGE-2B D-O drawdown sanity + S-MILEAGE-3 D-N refund state machine)\n";
echo str_repeat('═', 78), "\n";

if (!$failures) {
    echo "INVARIANTS OK — I1 (no unjustified zero-base drafts), I2 (no lease rate-tier holes), I3 (no template rate-tier holes), I4 (no lease mileage-intent + zero-rate hole), I5 (no invoice with distance against zero-rate lease), I6 (no silent-skip mileage on rate>0 + distance>0 invoice), I7 (no precharge_enabled=1 with NULL/zero/negative precharge_amount), I8 (drawdown math sanity: credit == min(usage, pre_balance) AND post == pre − credit), I9 (refund state machine: closed lease + residual balance → non-NULL method + settled_at for credit branch).\n";
    exit(0);
}

foreach ($failures as $f) echo $f, "\n\n";
echo "INVARIANTS FAIL — " . count($failures) . " invariant(s) violated. See above.\n";
exit(1);
