<?php
declare(strict_types=1);

/**
 * scripts/mileage_rate_zero_fix_2026_05_06.php
 *
 * S-MILEAGE-RATE-ZERO-FIX Commit 1 — One-shot remediation of the zero/null
 * mileage-rate hole across templates, customer rates, rate cards, and active
 * leases. Parallel arc to S-BILLING-RATE-FIX (which closed the base_rental
 * zero-rate bug); this closes the per-km/per-mile mileage-rate side.
 *
 * Root cause is the same chain — equipment_templates with NULL/0
 * default_mileage_rate flow through lookup_rates.php, the create form, and
 * eventually land as $0 on the lease record. Bonus finding (out of scope here):
 * api/v1/leases/lookup_rates.php:79-82 also has a namespace mismatch — it
 * queries customer_equipment_rates / rate_card_items by template name
 * ("53ft Dry Van") but data stores category ("dry_van"). To be addressed in
 * a follow-up session (S-LOOKUP-RATES-NAMESPACE).
 *
 * SCOPE (signed off as D-A through D-D, 2026-05-06):
 *
 *   D-A — Backfill equipment_templates.default_mileage_rate where NULL or 0,
 *         IF the template has at least one active or pending lease. Pre-work
 *         scan found 6 affected templates: 4 production NULL (id 1, 3, 4, 5)
 *         + 2 seed templates with $0 default and active leases (id 13, 16).
 *         Unused seed templates (id 6, 7, 10, 11, 12, 14, 17) are LEFT alone
 *         — separate cleanup if Avi wants them done.
 *
 *   D-B — Backfill customer_equipment_rates.mileage_rate where 0/NULL across
 *         all customers (11 rows). Avi confirmed the existing zeros are
 *         placeholders, not deliberate "no per-km charge" pricing intent.
 *         Writes a customer_rate_history row per change (per S019 convention).
 *
 *   D-C — Backfill rate_card_items.mileage_rate where 0/NULL on non-deleted
 *         cards (11 rows mirroring D-B). audit_log only — no rate_card_history
 *         table exists.
 *
 *   D-D — Backfill leases.mileage_rate_km / mileage_rate_miles / mileage_rate
 *         on 12 active+pending leases. Lease 17 also gets estimated_mileage_km
 *         + estimated_mileage_miles + estimated_mileage = 1500 km equivalent
 *         (Avi: "any value"; chassis short-term default heuristic). All other
 *         leases keep their existing estimated_mileage values.
 *
 *         NOTE on rate immutability: spec [FLEETFORGE_SPEC_FINAL.md:843]
 *         declares lease rates immutable post-creation; rate changes require
 *         a lease_amendment record. This is deliberately bypassed here
 *         because the writes are *bug-data backfill*, not a contract change
 *         — same departure as S-BILLING-RATE-FIX D-B. audit_log entry per
 *         lease records the backfill nature and rationale.
 *
 *   D-E — (intentionally omitted) Pre-work scan confirmed ZERO non-void
 *         invoice_line_items with $0 mileage_rate. No mileage-line voids
 *         needed. Mileage billing has not yet been exercised on these leases
 *         — the fix is forward-only.
 *
 * RUBRIC SOURCE:
 *   scripts/seed_rate_cards.php:39-45 — "Standard 2025" base reference rates,
 *   CAD, per km. Categories not in rubric (step_deck, dump) mapped to
 *   flatbed-equivalent ($0.17/km) by adjacency reasoning.
 *
 *   $0.18/km dry_van, $0.22/km reefer, $0.17/km flatbed,
 *   $0.15/km container, $0.13/km chassis, $0.17/km step_deck, $0.17/km dump
 *
 * UNIT HANDLING:
 *   Existing rows that store mileage_unit='miles' keep their unit but the
 *   stored rate is converted from the canonical km value via the per-settings
 *   factor 1.609344 (settings.lease.miles_to_km_default). This preserves the
 *   operator's chosen display unit on each row while honoring the rubric.
 *
 *   For leases, BOTH mileage_rate_km AND mileage_rate_miles are populated
 *   (matches existing convention seen on leases 38/39 — both columns
 *   populated regardless of mileage_unit). The legacy `mileage_rate` column
 *   stores whichever value matches the row's mileage_unit.
 *
 * SAFETY:
 *   - Default mode is --dry-run. Writes nothing, exits 0.
 *   - --execute prints the proposed diff first, then prompts for the literal
 *     string 'yes'.
 *   - Single db_transaction wraps all writes — full rollback on any error.
 *   - Idempotent: re-running with --execute on already-corrected state writes
 *     zero rows (each step has a `needs work?` guard).
 *
 * USAGE:
 *   php scripts/mileage_rate_zero_fix_2026_05_06.php --dry-run   (default)
 *   php scripts/mileage_rate_zero_fix_2026_05_06.php --execute   (prompts 'yes')
 *
 * SPEC:    S-MILEAGE-RATE-ZERO-FIX, research dated 2026-05-06
 * AUTHOR:  S-MILEAGE-RATE-ZERO-FIX session
 */

require_once dirname(__DIR__) . '/config/app.php';

// ────────────────────────────────────────────────────────────────────────────
// Argument parsing
// ────────────────────────────────────────────────────────────────────────────
$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if      ($arg === '--execute') $mode = 'execute';
    elseif  ($arg === '--dry-run') $mode = 'dry-run';
    else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/mileage_rate_zero_fix_2026_05_06.php [--dry-run|--execute]\n");
        exit(2);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// Constants — rubric + conversion factors
// ────────────────────────────────────────────────────────────────────────────

// Per-km canonical rates (CAD). Source: scripts/seed_rate_cards.php:39-45
// Categories not in rubric (step_deck, dump) mapped to flatbed-equivalent.
$RATES_KM = [
    'dry_van'   => '0.1800',
    'reefer'    => '0.2200',
    'flatbed'   => '0.1700',
    'container' => '0.1500',
    'chassis'   => '0.1300',
    'step_deck' => '0.1700',
    'dump'      => '0.1700',
];

// Conversion factors — sourced live from settings to avoid drift.
$KM_TO_MI = (string) (db_row("SELECT value FROM settings WHERE `key` = 'lease.km_to_miles_default'")['value'] ?? '0.621371');
$MI_TO_KM = (string) (db_row("SELECT value FROM settings WHERE `key` = 'lease.miles_to_km_default'")['value'] ?? '1.609344');

// Lease-17 specific estimated_mileage_km — chassis short-term heuristic
$LEASE_17_EST_KM = '1500.000';

// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

/**
 * Convert a per-km rate to per-mile (rate × miles-to-km factor).
 * Result rounded to 4 decimal places (matches schema decimal(8,4) / (10,4)).
 */
$rate_km_to_mi = function (string $rateKm) use ($MI_TO_KM): string {
    return bcround(bcmul($rateKm, $MI_TO_KM, 8), 4);
};

/**
 * Look up the canonical km rate for a category, or throw if unknown.
 */
$canonical_km = function (string $category) use ($RATES_KM): string {
    if (!isset($RATES_KM[$category])) {
        throw new RuntimeException("No canonical rate for category: {$category}");
    }
    return $RATES_KM[$category];
};

// ────────────────────────────────────────────────────────────────────────────
// Pre-flight reads — drives both dry-run printout and execute-time guards
// ────────────────────────────────────────────────────────────────────────────

// D-A — equipment_templates: NULL/0 default_mileage_rate AND has active/pending leases
$templateHoles = db_select(
    "SELECT et.id, et.name, et.category, et.default_mileage_rate, et.default_mileage_unit,
            (SELECT COUNT(*) FROM leases l
               JOIN equipment_units eu ON eu.id = l.equipment_unit_id
               WHERE eu.template_id = et.id
                 AND l.status IN ('active','pending')
                 AND l.deleted_at IS NULL) AS active_pending_leases
     FROM equipment_templates et
     WHERE (et.default_mileage_rate IS NULL OR et.default_mileage_rate = 0)
       AND et.deleted_at IS NULL
     HAVING active_pending_leases > 0
     ORDER BY et.id"
);

// D-B — customer_equipment_rates: mileage_rate=0 OR NULL
$customerRateHoles = db_select(
    "SELECT cer.id, cer.customer_id, c.company_name, cer.equipment_type,
            cer.daily_rate, cer.weekly_rate, cer.monthly_rate,
            cer.mileage_rate, cer.mileage_unit, cer.currency
     FROM customer_equipment_rates cer
     JOIN customers c ON c.id = cer.customer_id
     WHERE (cer.mileage_rate IS NULL OR cer.mileage_rate = 0)
     ORDER BY cer.customer_id, cer.equipment_type"
);

// D-C — rate_card_items: mileage_rate=0 OR NULL on non-deleted cards
$rateCardHoles = db_select(
    "SELECT rci.id, rci.rate_card_id, rc.name AS card_name, rci.equipment_type,
            rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
            rci.mileage_rate, rci.mileage_unit, rci.currency
     FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id
     WHERE (rci.mileage_rate IS NULL OR rci.mileage_rate = 0)
       AND rc.deleted_at IS NULL
     ORDER BY rci.rate_card_id, rci.equipment_type"
);

// D-D — leases: active+pending with mileage_rate_km=0 or NULL
$leaseHoles = db_select(
    "SELECT l.id, l.contract_number, l.status, l.customer_id, c.company_name,
            eu.template_id, et.name AS template_name, et.category,
            l.mileage_unit, l.mileage_rate, l.mileage_rate_km, l.mileage_rate_miles,
            l.estimated_mileage, l.estimated_mileage_km, l.estimated_mileage_miles,
            l.start_date, l.end_date
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     LEFT JOIN customers c ON c.id = l.customer_id
     WHERE (l.mileage_rate_km IS NULL OR l.mileage_rate_km = 0)
       AND l.deleted_at IS NULL
       AND l.status IN ('active','pending')
     ORDER BY eu.template_id, l.id"
);

// ────────────────────────────────────────────────────────────────────────────
// Plan printout — same shape for dry-run and execute confirm-prompt
// ────────────────────────────────────────────────────────────────────────────

echo str_repeat('═', 78), "\n";
echo "S-MILEAGE-RATE-ZERO-FIX Commit 1 — mode: {$mode}\n";
echo "Conversion factors (live from settings):\n";
echo sprintf("  km → miles: %s    miles → km: %s\n", $KM_TO_MI, $MI_TO_KM);
echo str_repeat('═', 78), "\n\n";

// D-A
echo "D-A — equipment_templates backfill\n";
echo str_repeat('─', 78), "\n";
if (!$templateHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($templateHoles as $t) {
        $cat       = $t['category'];
        $unit      = $t['default_mileage_unit'];
        $rateKm    = $canonical_km($cat);
        $newRate   = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        echo sprintf(
            "  template id=%-3d %-32s  category=%-10s  unit=%-5s  current=%s → %s  (%d active/pending leases)\n",
            $t['id'], $t['name'], $cat, $unit,
            $t['default_mileage_rate'] === null ? 'NULL' : (string)$t['default_mileage_rate'],
            $newRate, $t['active_pending_leases']
        );
    }
}
echo "\n";

// D-B
echo "D-B — customer_equipment_rates backfill\n";
echo str_repeat('─', 78), "\n";
if (!$customerRateHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($customerRateHoles as $r) {
        $cat     = $r['equipment_type'];
        $unit    = $r['mileage_unit'];
        $rateKm  = $canonical_km($cat);
        $newRate = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        echo sprintf(
            "  cer id=%-3d  cust=%-2d %-30s  type=%-10s  unit=%-5s  current=%s → %s\n",
            $r['id'], $r['customer_id'], $r['company_name'], $cat, $unit,
            (string)$r['mileage_rate'], $newRate
        );
    }
}
echo "\n";

// D-C
echo "D-C — rate_card_items backfill\n";
echo str_repeat('─', 78), "\n";
if (!$rateCardHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($rateCardHoles as $r) {
        $cat     = $r['equipment_type'];
        $unit    = $r['mileage_unit'];
        $rateKm  = $canonical_km($cat);
        $newRate = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        echo sprintf(
            "  rci id=%-3d  card=%-2d %-34s  type=%-10s  unit=%-5s  current=%s → %s\n",
            $r['id'], $r['rate_card_id'], $r['card_name'], $cat, $unit,
            (string)$r['mileage_rate'], $newRate
        );
    }
}
echo "\n";

// D-D
echo "D-D — leases backfill (active/pending only)\n";
echo str_repeat('─', 78), "\n";
if (!$leaseHoles) {
    echo "  (no holes — nothing to do)\n";
} else {
    foreach ($leaseHoles as $l) {
        $cat        = $l['category'];
        $unit       = $l['mileage_unit'];
        $rateKm     = $canonical_km($cat);
        $rateMi     = $rate_km_to_mi($rateKm);
        $primary    = ($unit === 'miles') ? $rateMi : $rateKm;
        $estNote    = '';
        if ((int)$l['id'] === 17) {
            $estNote = sprintf("  [+ est_km %s → %s]", (string)$l['estimated_mileage_km'], $LEASE_17_EST_KM);
        }
        echo sprintf(
            "  lease id=%-3d %-30s  status=%-7s  tmpl=%-2d %-15s  unit=%-5s  rate=%s → km=%s mi=%s%s\n",
            $l['id'], $l['contract_number'], $l['status'],
            $l['template_id'], $l['template_name'], $unit,
            (string)$l['mileage_rate'], $rateKm, $rateMi, $estNote
        );
    }
}
echo "\n";

$totalChanges = count($templateHoles) + count($customerRateHoles) + count($rateCardHoles) + count($leaseHoles);
echo str_repeat('─', 78), "\n";
echo sprintf("Total rows to write: %d\n", $totalChanges);
echo "  templates:                 ", count($templateHoles), "\n";
echo "  customer_equipment_rates:  ", count($customerRateHoles), "\n";
echo "  rate_card_items:           ", count($rateCardHoles), "\n";
echo "  leases:                    ", count($leaseHoles), "\n";
echo "\n";

// ────────────────────────────────────────────────────────────────────────────
// Dry-run exit
// ────────────────────────────────────────────────────────────────────────────
if ($mode === 'dry-run') {
    echo "DRY-RUN — no changes written. Re-run with --execute to apply.\n";
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// Execute confirmation
// ────────────────────────────────────────────────────────────────────────────
echo "About to apply the changes above in a single transaction.\n";
echo "Type 'yes' (lowercase, no quotes) to proceed, anything else to cancel: ";
$line = trim((string)fgets(STDIN));
if ($line !== 'yes') {
    echo "Cancelled.\n";
    exit(1);
}

// ────────────────────────────────────────────────────────────────────────────
// Apply
// ────────────────────────────────────────────────────────────────────────────
$writes = [
    'template_backfills'   => 0,
    'cer_backfills'        => 0,
    'cer_history_entries'  => 0,
    'rci_backfills'        => 0,
    'lease_backfills'      => 0,
    'audit_log_entries'    => 0,
];

db_transaction(function () use (
    $templateHoles, $customerRateHoles, $rateCardHoles, $leaseHoles,
    $canonical_km, $rate_km_to_mi, $LEASE_17_EST_KM, $KM_TO_MI,
    &$writes
) {
    $now      = date('Y-m-d H:i:s');
    $userId   = 1; // Avi
    $userName = 'Avi (S-MILEAGE-RATE-ZERO-FIX)';
    $ip       = '127.0.0.1';

    // ── D-A: equipment_templates ──────────────────────────────────────────
    foreach ($templateHoles as $t) {
        $cat     = $t['category'];
        $unit    = $t['default_mileage_unit'];
        $rateKm  = $canonical_km($cat);
        $newRate = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        $oldRate = $t['default_mileage_rate'];

        db_update('equipment_templates', [
            'default_mileage_rate' => $newRate,
            'updated_at'           => $now,
        ], 'id = ?', [$t['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'equipment_templates',
            'entity_type'  => 'equipment_template',
            'entity_id'    => $t['id'],
            'entity_label' => $t['name'],
            'notes'        => sprintf(
                'S-MILEAGE-RATE-ZERO-FIX D-A backfill: default_mileage_rate set from Standard 2025 rubric for category=%s (km=%s, stored as %s/%s).',
                $cat, $rateKm, $newRate, $unit
            ),
            'old_values'   => json_encode(['default_mileage_rate' => $oldRate]),
            'new_values'   => json_encode(['default_mileage_rate' => $newRate]),
            'ip_address'   => $ip,
        ]);

        $writes['template_backfills']++;
        $writes['audit_log_entries']++;
    }

    // ── D-B: customer_equipment_rates ─────────────────────────────────────
    foreach ($customerRateHoles as $r) {
        $cat     = $r['equipment_type'];
        $unit    = $r['mileage_unit'];
        $rateKm  = $canonical_km($cat);
        $newRate = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        $oldRate = $r['mileage_rate'];

        db_update('customer_equipment_rates', [
            'mileage_rate' => $newRate,
            'updated_at'   => $now,
        ], 'id = ?', [$r['id']]);

        // S019 convention: write customer_rate_history per change.
        db_insert('customer_rate_history', [
            'customer_id'    => $r['customer_id'],
            'equipment_type' => $cat,
            'lease_id'       => null,
            'daily_rate'     => $r['daily_rate'],
            'weekly_rate'    => $r['weekly_rate'],
            'monthly_rate'   => $r['monthly_rate'],
            'mileage_rate'   => $newRate,
            'mileage_unit'   => $unit,
            'currency'       => $r['currency'],
            'change_type'    => 'updated',
            'change_source'  => 'system',
            'change_notes'   => 'S-MILEAGE-RATE-ZERO-FIX D-B backfill: zero/null mileage_rate replaced with Standard 2025 rubric value.',
            'created_by'     => $userId,
        ]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'customer_equipment_rates',
            'entity_type'  => 'customer_equipment_rate',
            'entity_id'    => $r['id'],
            'entity_label' => sprintf('cust=%d %s', $r['customer_id'], $cat),
            'notes'        => sprintf(
                'S-MILEAGE-RATE-ZERO-FIX D-B backfill: mileage_rate set from Standard 2025 rubric for category=%s (km=%s, stored as %s/%s).',
                $cat, $rateKm, $newRate, $unit
            ),
            'old_values'   => json_encode(['mileage_rate' => $oldRate]),
            'new_values'   => json_encode(['mileage_rate' => $newRate]),
            'ip_address'   => $ip,
        ]);

        $writes['cer_backfills']++;
        $writes['cer_history_entries']++;
        $writes['audit_log_entries']++;
    }

    // ── D-C: rate_card_items ──────────────────────────────────────────────
    foreach ($rateCardHoles as $r) {
        $cat     = $r['equipment_type'];
        $unit    = $r['mileage_unit'];
        $rateKm  = $canonical_km($cat);
        $newRate = ($unit === 'miles') ? $rate_km_to_mi($rateKm) : $rateKm;
        $oldRate = $r['mileage_rate'];

        db_update('rate_card_items', [
            'mileage_rate' => $newRate,
            'updated_at'   => $now,
        ], 'id = ?', [$r['id']]);

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'rate_cards',
            'entity_type'  => 'rate_card_item',
            'entity_id'    => $r['id'],
            'entity_label' => sprintf('card=%d %s', $r['rate_card_id'], $cat),
            'notes'        => sprintf(
                'S-MILEAGE-RATE-ZERO-FIX D-C backfill: mileage_rate set from Standard 2025 rubric for category=%s (km=%s, stored as %s/%s).',
                $cat, $rateKm, $newRate, $unit
            ),
            'old_values'   => json_encode(['mileage_rate' => $oldRate]),
            'new_values'   => json_encode(['mileage_rate' => $newRate]),
            'ip_address'   => $ip,
        ]);

        $writes['rci_backfills']++;
        $writes['audit_log_entries']++;
    }

    // ── D-D: leases ───────────────────────────────────────────────────────
    foreach ($leaseHoles as $l) {
        $cat       = $l['category'];
        $unit      = $l['mileage_unit'];
        $rateKm    = $canonical_km($cat);
        $rateMi    = $rate_km_to_mi($rateKm);
        $primary   = ($unit === 'miles') ? $rateMi : $rateKm;

        $oldVals = [
            'mileage_rate'       => $l['mileage_rate'],
            'mileage_rate_km'    => $l['mileage_rate_km'],
            'mileage_rate_miles' => $l['mileage_rate_miles'],
        ];

        $newVals = [
            'mileage_rate'       => $primary,
            'mileage_rate_km'    => $rateKm,
            'mileage_rate_miles' => $rateMi,
            'updated_at'         => $now,
            'updated_by'         => $userId,
        ];

        // Lease 17 — also backfill estimated_mileage_km/miles/legacy.
        // Other leases keep their existing estimated_mileage values.
        if ((int)$l['id'] === 17) {
            $estKm = $LEASE_17_EST_KM;
            $estMi = bcround(bcmul($estKm, $KM_TO_MI, 8), 3);
            $estPrimary = ($unit === 'miles') ? $estMi : $estKm;

            $oldVals['estimated_mileage']        = $l['estimated_mileage'];
            $oldVals['estimated_mileage_km']     = $l['estimated_mileage_km'];
            $oldVals['estimated_mileage_miles']  = $l['estimated_mileage_miles'];

            $newVals['estimated_mileage']        = $estPrimary;
            $newVals['estimated_mileage_km']     = $estKm;
            $newVals['estimated_mileage_miles']  = $estMi;
        }

        db_update('leases', $newVals, 'id = ?', [$l['id']]);

        $note = sprintf(
            'S-MILEAGE-RATE-ZERO-FIX D-D backfill: mileage_rate_km/miles set from Standard 2025 rubric for category=%s (km=%s, miles=%s, primary unit=%s). Backfill of bug-data; per spec §rate-immutability this would normally require lease_amendment but is bypassed deliberately — same departure as S-BILLING-RATE-FIX D-B.',
            $cat, $rateKm, $rateMi, $unit
        );
        if ((int)$l['id'] === 17) {
            $note .= sprintf(' Also backfilled estimated_mileage_km=%s (chassis short-term heuristic per Avi).', $LEASE_17_EST_KM);
        }

        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease',
            'entity_id'    => $l['id'],
            'entity_label' => $l['contract_number'],
            'notes'        => $note,
            'old_values'   => json_encode($oldVals),
            'new_values'   => json_encode(array_diff_key($newVals, ['updated_at' => 1, 'updated_by' => 1])),
            'ip_address'   => $ip,
        ]);

        $writes['lease_backfills']++;
        $writes['audit_log_entries']++;
    }
});

// ────────────────────────────────────────────────────────────────────────────
// Verification — re-query the same holes; expect zero on each
// ────────────────────────────────────────────────────────────────────────────
$check1Templates = db_count(
    "SELECT COUNT(*) FROM equipment_templates et
     WHERE (et.default_mileage_rate IS NULL OR et.default_mileage_rate = 0)
       AND et.deleted_at IS NULL
       AND EXISTS (
         SELECT 1 FROM leases l JOIN equipment_units eu ON eu.id = l.equipment_unit_id
         WHERE eu.template_id = et.id AND l.status IN ('active','pending') AND l.deleted_at IS NULL
       )"
);
$check2Cer = db_count(
    "SELECT COUNT(*) FROM customer_equipment_rates
     WHERE mileage_rate IS NULL OR mileage_rate = 0"
);
$check3Rci = db_count(
    "SELECT COUNT(*) FROM rate_card_items rci
     JOIN rate_cards rc ON rc.id = rci.rate_card_id
     WHERE (rci.mileage_rate IS NULL OR rci.mileage_rate = 0)
       AND rc.deleted_at IS NULL"
);
$check4Leases = db_count(
    "SELECT COUNT(*) FROM leases
     WHERE deleted_at IS NULL
       AND status IN ('active','pending')
       AND (mileage_rate_km IS NULL OR mileage_rate_km = 0)"
);
$check5Lease17Est = db_count(
    "SELECT COUNT(*) FROM leases WHERE id = 17 AND estimated_mileage_km > 0"
);

echo "\n";
echo str_repeat('═', 78), "\n";
echo "Writes applied:\n";
foreach ($writes as $k => $v) {
    echo sprintf("  %-22s %d\n", $k, $v);
}
echo "\n";
echo "Post-write verification:\n";
echo sprintf("  templates with hole+leases:          %s   (expect 0)\n", $check1Templates);
echo sprintf("  customer_equipment_rates with hole:  %s   (expect 0)\n", $check2Cer);
echo sprintf("  rate_card_items with hole:           %s   (expect 0)\n", $check3Rci);
echo sprintf("  active/pending leases with hole:     %s   (expect 0)\n", $check4Leases);
echo sprintf("  lease 17 estimated_mileage_km > 0:   %s   (expect 1)\n", $check5Lease17Est);

$ok = ($check1Templates === 0)
   && ($check2Cer       === 0)
   && ($check3Rci       === 0)
   && ($check4Leases    === 0)
   && ($check5Lease17Est === 1);

echo "\n", ($ok ? 'OK — all post-conditions met.' : 'FAIL — see counts above.'), "\n";
exit($ok ? 0 : 3);
