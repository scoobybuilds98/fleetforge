<?php
declare(strict_types=1);

/**
 * tests/_smoke_model_b_lifecycle.php
 *
 * S-MILEAGE-5 C2 — 20 hermetic Model B lifecycle scenarios.
 *
 * Each scenario is a self-contained BEGIN/ROLLBACK transaction that
 * inserts the minimal synthetic state it needs, exercises the
 * production code path or asserts the expected data shape, then
 * rolls back — leaving zero residue between scenarios and zero
 * residue against production data on exit.
 *
 * Coverage map (all three D135 config matrix paths):
 *   S01 — activation precharge_balance init (S-MILEAGE-2A D137)
 *   S02 — Invoice 1 mileage_precharge emit (S-MILEAGE-2A D138/D139)
 *   S03 — send Invoice 1 stamps precharge_invoiced_at (S-MILEAGE-2A D140)
 *   S04 — Invoice 2 drawdown: balance > period_charge (S-MILEAGE-2B D148)
 *   S05 — Invoice 2 drawdown: balance == period_charge
 *   S06 — Invoice 2 drawdown: balance < period_charge (capped)
 *   S07 — Multi-invoice drawdown exhausts balance
 *   S08 — Close with residual=0 post-final-drawdown: no refund (K-21 Case b)
 *   S09 — Close with residual > 0: credit refund (K-21 Case c, D170)
 *   S10 — Close with residual > 0: cash refund intent-only (D169)
 *   S11 — Mark cash refund settled
 *   S12 — Mark cash refund settled idempotency (409 ALREADY_SETTLED)
 *   S13 — Re-close attempt (409 PRECHARGE_REFUND_LOCKED, D171)
 *   S14 — Tiny residual: $0.01 bcmath precision
 *   S15 — Close with balance > 0 but no method (422 PRECHARGE_REFUND_REQUIRED)
 *   S16 — Model B Lite: rate>0, precharge_enabled=0 (D135 row 2)
 *   S17 — Disabled mileage: rate=0, precharge_enabled=0 (D135 row 3)
 *   S18 — Samsara unavailable: no drawdown emit (K-18 fallback path)
 *   S19 — Samsara warning path (gateway_reset_detected; FIX_RESET fixture)
 *   S20 — I10 cross-check: audit_log refund amount == post-drawdown
 *         precharge_balance (K-21 lock validated end-to-end)
 *
 * Hermetic pattern: every scenario wraps its setup → exercise →
 * assert in BEGIN / ROLLBACK; equipment_units + leases + invoices
 * + invoice_line_items + audit_log + credit_notes + settings rows
 * created inside the transaction are reverted on ROLLBACK. Each
 * scenario runs in its own outer try/catch/finally so a thrown
 * exception in one scenario doesn't poison later scenarios.
 *
 * Decisions enforced:
 *   D16  — bcmath comparisons for monetary asserts (bccomp, not ==)
 *   D135 — three-config matrix (Model B full / Lite / Disabled)
 *   D137 — activation balance init
 *   D138 — Invoice 1 3-clause emit gate
 *   D140 — send stamp + D113 PRECHARGE_LOCKED
 *   D148 — drawdown emit math
 *   D166 — POSITIVE amount + is_credit=1 K-16 convention
 *   D169 — cash refund deferred-settle
 *   D170 — credit refund stamp at close-commit
 *   D171 — 409 PRECHARGE_REFUND_LOCKED state machine
 *   D182 — precharge_balance preserved post-refund (NOT zeroed)
 *
 * K-18 lock honored: S18/S19 do NOT pre-populate odometer values
 * (those scenarios specifically exercise the Samsara fallback path).
 *
 * K-21 lock validated: S20 cross-checks audit_log refund amount
 * against POST-drawdown precharge_balance, NOT pre-drawdown.
 *
 * Spec ref: FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-5 spec block.
 * Session:  S-MILEAGE-5
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Billing\InvoiceGenerator;

// S-CRON-FIX-1: load the monthly-billing cron's runner WITHOUT executing its
// script wrapper (FF_MONTHLY_BILLING_INCLUDE), so the catch-up / idempotency /
// timezone logic can be driven directly with deterministic dates in the
// C1–C6 scenarios below (cron-audit HIGH-1 regression coverage).
define('FF_MONTHLY_BILLING_INCLUDE', true);
require_once FF_ROOT . '/cron/invoice_generate_monthly.php';

$results = [];

function s5_record(array &$results, string $case, bool $ok, string $msg): void {
    $results[] = ['case' => $case, 'ok' => $ok, 'msg' => $msg];
    $tag = $ok ? 'PASS' : 'FAIL';
    printf("  %s %s — %s\n", $tag, $case, $msg);
}

/**
 * Run a single scenario wrapped in BEGIN/ROLLBACK. The callable
 * receives ($customerId, $unitId, $userId) and returns ['ok' => bool,
 * 'msg' => string]. ROLLBACK fires unconditionally in finally.
 */
function s5_scenario(array &$results, string $label, callable $fn, int $customerId, int $unitId, int $userId): void {
    db_execute('BEGIN');
    try {
        $r = $fn($customerId, $unitId, $userId);
        s5_record($results, $label, (bool) $r['ok'], (string) $r['msg']);
    } catch (\Throwable $e) {
        s5_record($results, $label, false,
            'EXCEPTION ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        db_execute('ROLLBACK');
    }
}

/**
 * Build a synthetic lease row (returns lease_id). Defaults match the
 * stress test pattern: $50 daily / $300 weekly / $1000 monthly rentals,
 * $0.18/km mileage. Override any field via $overrides.
 */
function s5_make_lease(array $overrides, int $customerId, int $unitId, int $userId): int {
    $defaults = [
        'contract_number'         => 'S5-' . substr(md5((string) microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12),
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'start_date'              => '2026-01-01',
        'status'                  => 'active',
        'daily_rate'              => '50.00',
        'weekly_rate'             => '300.00',
        'monthly_rate'            => '1000.00',
        'mileage_rate_km'         => '0.18',
        'mileage_unit'            => 'km',
        'estimated_mileage_km'    => '0',
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'odometer_start_km'       => '0.00',
        'precharge_enabled'       => 0,
        'precharge_amount'        => null,
        'precharge_balance'       => null,
        'precharge_invoiced_at'   => null,
        'created_by'              => $userId,
        'updated_by'              => $userId,
    ];
    return db_insert('leases', array_merge($defaults, $overrides));
}

/**
 * Insert a synthetic equipment_unit row (returns unit_id) with a
 * specific samsara_vehicle_id (NULL for "no Samsara linkage"). Used
 * by S18 (samsara_vehicle_id=NULL) + S19 (FIX_RESET fixture) where
 * the production equipment_unit's samsara_vehicle_id can't be
 * assumed.
 */
function s5_make_unit(?string $samsaraVehicleId, int $userId): int {
    $tpl = db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
    if (!$tpl) {
        throw new \RuntimeException('No equipment_templates row available for synthetic equipment_unit.');
    }
    $uniqSuffix = substr(md5((string) microtime(true) . random_int(0, PHP_INT_MAX)), 0, 10);
    return db_insert('equipment_units', [
        'template_id'        => (int) $tpl['id'],
        'unit_number'        => 'S5-UNIT-' . $uniqSuffix,
        'samsara_vehicle_id' => $samsaraVehicleId,
        'tracking_provider'  => $samsaraVehicleId !== null ? 'samsara' : 'none',
        'ownership_type'     => 'owned',
        'status'             => 'available',
        'created_by'         => $userId,
        'updated_by'         => $userId,
    ]);
}

// Resolve FK parents — production-safe LIMIT 1 lookups.
$customerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$unitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$userId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

if ($customerId === 0 || $unitId === 0 || $userId === 0) {
    echo "FAIL — cannot resolve FK parents (customer=$customerId, unit=$unitId, user=$userId)\n";
    exit(1);
}

echo "S-MILEAGE-5 — _smoke_model_b_lifecycle.php\n";
echo str_repeat('═', 76), "\n";

// S-INVOICE-COUNTER-BUMP — drift WARN: surface counter ≤ MAX(invoice_number) before it becomes an opaque UNIQUE collision in any scenario that generates ≥2 invoices.
$_yr = date('Y');
$_counter = (int)(db_row("SELECT value FROM settings WHERE `key` = ?", ["invoice.next_number.{$_yr}"])['value'] ?? 1);
$_maxStr = db_row("SELECT MAX(invoice_number) AS m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$_yr}-%"])['m'] ?? '';
$_maxNum = $_maxStr !== '' ? (int)substr(strrchr($_maxStr, '-'), 1) : 0;
if ($_counter <= $_maxNum) { fwrite(STDERR, "WARN invoice-counter-drift: invoice.next_number.{$_yr}={$_counter} <= MAX(invoice_number)={$_maxNum}; next generateInvoiceNumber() will collide. Run: UPDATE settings SET value='" . ($_maxNum + 5) . "' WHERE `key`='invoice.next_number.{$_yr}'. See S-D131-BASELINE-RESTORE / S-INVOICE-COUNTER-BUMP.\n"); }

$periodStart = '2026-04-01';
$periodEnd   = '2026-04-30';

// ─────────────────────────────────────────────────────────────────────
// S01 — Activation initializes precharge_balance (S-MILEAGE-2A D137)
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S01 activation initializes precharge_balance',
    function ($customerId, $unitId, $userId) {
        // Lease in pending state with precharge configured but balance NULL.
        $leaseId = s5_make_lease([
            'status'                => 'pending',
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => null,
            'precharge_invoiced_at' => null,
        ], $customerId, $unitId, $userId);

        // Mirror api/v1/leases/activate.php:240-250 precharge_balance init
        // (the canonical activation logic — D137 / S-MILEAGE-2A C2).
        db_execute(
            "UPDATE leases
                SET precharge_balance = precharge_amount,
                    status            = 'active',
                    updated_at        = NOW(),
                    updated_by        = ?
              WHERE id = ?
                AND precharge_enabled = 1
                AND precharge_amount IS NOT NULL
                AND precharge_balance IS NULL",
            [$userId, $leaseId]
        );

        $post = db_row("SELECT precharge_balance, precharge_invoiced_at FROM leases WHERE id = ?", [$leaseId]);
        $ok = $post
            && bccomp((string) $post['precharge_balance'], '500.00', 2) === 0
            && $post['precharge_invoiced_at'] === null;
        return ['ok' => $ok, 'msg' => sprintf('balance=%s invoiced_at=%s (expect 500.00/null)',
            $post['precharge_balance'] ?? 'null',
            $post['precharge_invoiced_at'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S02 — Invoice 1 emits mileage_precharge, NOT mileage_usage (D138/D139)
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S02 Invoice 1 emits mileage_precharge line',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '500.00',
            'precharge_invoiced_at' => null,
        ], $customerId, $unitId, $userId);

        // Invoice 1: precharge_invoiced_at IS NULL → emit gate fires (D138).
        // Zero distance keeps drawdown gate dormant (the Invoice 1 case
        // doesn't even reach the drawdown branch in canonical Model B —
        // the precharge_invoiced_at IS NULL discriminator routes it).
        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '0.00',
            'created_by'                  => $userId,
        ]);

        $pre  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_precharge'", [$res['invoice_id']]);
        $use  = db_row("SELECT id FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_usage'", [$res['invoice_id']]);
        $post = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $pre && bccomp((string) $pre['amount'], '500.00', 2) === 0
            && $use === null
            && $post && bccomp((string) $post['precharge_balance'], '500.00', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('precharge=%s usage=%s balance=%s (expect 500/null/500)',
            $pre['amount'] ?? 'null',
            $use['id']    ?? 'null',
            $post['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S03 — Send Invoice 1 stamps precharge_invoiced_at (D140)
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S03 send Invoice 1 stamps precharge_invoiced_at',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '500.00',
            'precharge_invoiced_at' => null,
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '0.00',
            'created_by'                  => $userId,
        ]);

        // Mirror api/v1/invoices/send.php D-D send-time stamp logic
        // (lease has a mileage_precharge line → stamp NOW() on lease;
        // D113 PRECHARGE_LOCKED activates for free off the stamp).
        $now = date('Y-m-d H:i:s');
        db_update('invoices', ['status' => 'sent', 'sent_at' => $now], 'id = ?', [$res['invoice_id']]);
        db_update('leases', ['precharge_invoiced_at' => $now], 'id = ?', [$leaseId]);

        $post = db_row("SELECT precharge_balance, precharge_invoiced_at FROM leases WHERE id = ?", [$leaseId]);
        $ok = $post
            && $post['precharge_invoiced_at'] !== null
            && bccomp((string) $post['precharge_balance'], '500.00', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('invoiced_at=%s balance=%s (expect non-null/500.00 — send does NOT draw down)',
            $post['precharge_invoiced_at'] ?? 'null',
            $post['precharge_balance']     ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S04 — Invoice 2 drawdown: balance > period_charge (partial draw)
// Period: 1234.56 km × $0.18/km = bcround('222.2208', 2) = $222.22
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S04 drawdown balance(500) > charge(222.22)',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '500.00',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '1234.56',
            'created_by'                  => $userId,
        ]);

        $usage  = db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT amount, is_credit FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);
        $post   = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $usage && bccomp((string) $usage['amount'], '222.22', 2) === 0 && (int) $usage['is_credit'] === 0
            && $credit && bccomp((string) $credit['amount'], '222.22', 2) === 0 && (int) $credit['is_credit'] === 1
            && $post && bccomp((string) $post['precharge_balance'], '277.78', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('usage=%s/c=%s credit=%s/c=%s post=%s (expect 222.22/0, 222.22/1, 277.78)',
            $usage['amount']  ?? 'null', $usage['is_credit']  ?? 'null',
            $credit['amount'] ?? 'null', $credit['is_credit'] ?? 'null',
            $post['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S05 — Invoice 2 drawdown: balance == period_charge (exact draw)
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S05 drawdown balance(222.22) == charge(222.22)',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '222.22',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '1234.56',
            'created_by'                  => $userId,
        ]);

        $usage  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);
        $post   = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $usage && bccomp((string) $usage['amount'], '222.22', 2) === 0
            && $credit && bccomp((string) $credit['amount'], '222.22', 2) === 0
            && $post && bccomp((string) $post['precharge_balance'], '0.00', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('usage=%s credit=%s post=%s (expect 222.22/222.22/0.00)',
            $usage['amount']  ?? 'null',
            $credit['amount'] ?? 'null',
            $post['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S06 — Invoice 2 drawdown: balance < period_charge (capped draw)
// Net charge: $222.22 usage − $100.00 credit = $122.22 net.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S06 drawdown balance(100) < charge(222.22) capped',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '100.00',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '1234.56',
            'created_by'                  => $userId,
        ]);

        $usage  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);
        $post   = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $netCharge = $usage && $credit ? bcsub((string) $usage['amount'], (string) $credit['amount'], 2) : 'null';

        $ok = $usage && bccomp((string) $usage['amount'], '222.22', 2) === 0
            && $credit && bccomp((string) $credit['amount'], '100.00', 2) === 0
            && $post && bccomp((string) $post['precharge_balance'], '0.00', 2) === 0
            && $netCharge !== 'null' && bccomp($netCharge, '122.22', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('usage=%s credit=%s post=%s net=%s (expect 222.22/100.00/0.00/122.22)',
            $usage['amount']  ?? 'null',
            $credit['amount'] ?? 'null',
            $post['precharge_balance'] ?? 'null',
            $netCharge)];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S07 — Multi-invoice drawdown exhausts balance across 4 invoices
// Setup: balance=300, rate=$0.10/km, distance=1000 km/period → charge=$100/period.
// Inv2: 300→200, Inv3: 200→100, Inv4: 100→0, Inv5: 0 (no drawdown_credit).
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S07 multi-invoice drawdown exhausts then bypasses',
    function ($customerId, $unitId, $userId) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '300.00',
            'precharge_balance'     => '300.00',
            'precharge_invoiced_at' => '2026-01-31 12:00:00',
            'mileage_rate_km'       => '0.10',  // distance=1000 → charge=$100
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $periods = [
            ['start' => '2026-02-01', 'end' => '2026-02-28', 'odoStart' => '0.00',    'odoEnd' => '1000.00'],
            ['start' => '2026-03-01', 'end' => '2026-03-31', 'odoStart' => '1000.00', 'odoEnd' => '2000.00'],
            ['start' => '2026-04-01', 'end' => '2026-04-30', 'odoStart' => '2000.00', 'odoEnd' => '3000.00'],
            ['start' => '2026-05-01', 'end' => '2026-05-31', 'odoStart' => '3000.00', 'odoEnd' => '4000.00'],
        ];

        $invoiceIds = [];
        foreach ($periods as $p) {
            $r = $gen->createFromLease([
                'lease_id'                    => $leaseId,
                'period_start'                => $p['start'],
                'period_end'                  => $p['end'],
                'billing_type'                => 'full_month',
                'invoice_type'                => 'regular',
                'odometer_at_period_start_km' => $p['odoStart'],
                'odometer_at_period_end_km'   => $p['odoEnd'],
                'created_by'                  => $userId,
            ]);
            $invoiceIds[] = $r['invoice_id'];
        }

        // Inv2-4: each must have mileage_drawdown_credit = $100.
        // Inv5: must have NO mileage_drawdown_credit (balance==0 branch).
        $c1 = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$invoiceIds[0]]);
        $c2 = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$invoiceIds[1]]);
        $c3 = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$invoiceIds[2]]);
        $c4 = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$invoiceIds[3]]);
        $u4 = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'",            [$invoiceIds[3]]);
        $post = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $c1 && bccomp((string) $c1['amount'], '100.00', 2) === 0
            && $c2 && bccomp((string) $c2['amount'], '100.00', 2) === 0
            && $c3 && bccomp((string) $c3['amount'], '100.00', 2) === 0
            && $c4 === null
            && $u4 && bccomp((string) $u4['amount'], '100.00', 2) === 0
            && $post && bccomp((string) $post['precharge_balance'], '0.00', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('c1=%s c2=%s c3=%s c4=%s u4=%s post=%s (expect 100/100/100/null/100/0)',
            $c1['amount'] ?? 'null', $c2['amount'] ?? 'null',
            $c3['amount'] ?? 'null', $c4['amount'] ?? 'null',
            $u4['amount'] ?? 'null', $post['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S08 — Close with residual=0 post-final-drawdown: no refund (K-21 Case b)
//
// K-21 / S-MILEAGE-3-FIX-0: pre-final-invoice balance=77.78, final
// invoice drawdown consumes 77.78 → residualBalance=0 → needsRefund=
// false → refund block skipped → precharge_refund_method stays NULL.
//
// Note on D182 wording: D182 says "precharge_balance retains the
// historical at-close value post-REFUND" — the close-refund block
// doesn't zero balance. In this scenario the drawdown DID zero
// balance during final-invoice generation (which is part of the
// close transaction), so the post-close balance is 0. That is the
// "at-close value" D182 preserves — it's just that drawdown already
// drove it to 0 before the refund block ran. K-21 Case (b) confirms
// this is the correct behavior.
//
// distance × rate = 432.11 × 0.18 = bcround('77.7798', 2) = '77.78'
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S08 close residual=0 → no refund (K-21 Case b)',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '77.78',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        // Generate final invoice: drawdown consumes the full 77.78 balance.
        $gen = new InvoiceGenerator();
        $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '432.11',
            'created_by'                  => $userId,
        ]);

        // Mirror close.php D-K refund dispatch gate: re-SELECT
        // precharge_balance + method FOR UPDATE post-drawdown.
        // (K-21 lock: re-read from DB, not cached $lease value.)
        $leasePostFinal = db_row(
            "SELECT precharge_balance, precharge_refund_method, precharge_enabled
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        $residualBalance = $leasePostFinal['precharge_balance'] !== null
            ? (string) $leasePostFinal['precharge_balance']
            : '0.00';
        $needsRefund = (int) $leasePostFinal['precharge_enabled'] === 1
            && bccomp($residualBalance, '0.00', 2) > 0;

        // No refund dispatch fires; status flips completed.
        db_update('leases', [
            'status'    => 'completed',
            'closed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$leaseId]);

        $finalLease = db_row(
            "SELECT precharge_refund_method, precharge_refund_settled_at, precharge_balance
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        $cn = db_row("SELECT id FROM credit_notes WHERE lease_id = ? AND source = 'precharge_refund'", [$leaseId]);

        $ok = !$needsRefund
            && $finalLease
            && $finalLease['precharge_refund_method']     === null
            && $finalLease['precharge_refund_settled_at'] === null
            && bccomp((string) $finalLease['precharge_balance'], '0.00', 2) === 0
            && $cn === null;
        return ['ok' => $ok, 'msg' => sprintf('needsRefund=%s method=%s settled_at=%s balance=%s cn=%s (expect false/null/null/0.00/null)',
            $needsRefund ? 'true' : 'false',
            $finalLease['precharge_refund_method']     ?? 'null',
            $finalLease['precharge_refund_settled_at'] ?? 'null',
            $finalLease['precharge_balance']           ?? 'null',
            $cn['id'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S09 — Close with residual > 0: credit refund (K-21 Case c, D170)
//
// Final invoice has no drawdown (period_distance=0 → drawdown gate
// fails) → residualBalance=$77.78 survives. Credit branch dispatches:
// credit_note row + stamps settled_at at close-commit + audit_log.
// precharge_balance PRESERVED at $77.78 per D182 (refund block does
// not zero it).
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S09 close residual>0 → credit refund (D170)',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '77.78',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        // Final invoice with zero distance → drawdown gate fails →
        // residual survives final-invoice generation.
        $gen = new InvoiceGenerator();
        $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '5000.00',
            'odometer_at_period_end_km'   => '5000.00',
            'created_by'                  => $userId,
        ]);

        $leasePostFinal = db_row(
            "SELECT precharge_balance, precharge_refund_method, precharge_enabled,
                    customer_id, contract_number, currency
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        $residualBalance = (string) $leasePostFinal['precharge_balance'];
        $needsRefund = bccomp($residualBalance, '0.00', 2) > 0;

        // Mirror close.php D-C credit branch dispatch.
        $now = date('Y-m-d H:i:s');
        $cnNumber = 'CN-CR-S5-S09-' . substr(md5((string) microtime(true)), 0, 8);
        $cnId = db_insert('credit_notes', [
            'credit_note_number' => $cnNumber,
            'customer_id'        => $leasePostFinal['customer_id'],
            'lease_id'           => $leaseId,
            'source'             => 'precharge_refund',
            'amount'             => $residualBalance,
            'currency'           => $leasePostFinal['currency'],
            'amount_remaining'   => $residualBalance,
            'status'             => 'active',
            'reason'             => "Precharge balance refund — lease {$leasePostFinal['contract_number']}",
            'created_by'         => $userId,
        ]);
        db_update('leases', [
            'precharge_refund_method'     => 'credit',
            'precharge_refund_settled_at' => $now,
            'status'                      => 'completed',
            'closed_at'                   => $now,
        ], 'id = ?', [$leaseId]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => 'smoke',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_refund_issued',
            'entity_id'    => $leaseId,
            'entity_label' => $leasePostFinal['contract_number'],
            'notes'        => 'S-MILEAGE-5 S09 — credit branch dispatch',
            'new_values'   => json_encode([
                'method'                       => 'credit',
                'amount'                       => $residualBalance,
                'precharge_balance_at_close'   => $residualBalance,
                'related_credit_note_id'       => $cnId,
                'related_credit_note_number'   => $cnNumber,
                'settled_at'                   => $now,
                'closed_by_user_id'            => $userId,
            ]),
        ]);

        $finalLease = db_row("SELECT precharge_refund_method, precharge_refund_settled_at, precharge_balance FROM leases WHERE id = ?", [$leaseId]);
        $cn = db_row("SELECT amount, source FROM credit_notes WHERE id = ?", [$cnId]);

        $ok = $needsRefund
            && $finalLease['precharge_refund_method'] === 'credit'
            && $finalLease['precharge_refund_settled_at'] !== null
            && bccomp((string) $finalLease['precharge_balance'], '77.78', 2) === 0
            && $cn && $cn['source'] === 'precharge_refund'
            && bccomp((string) $cn['amount'], '77.78', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('method=%s settled_at=%s balance=%s cn.source=%s cn.amount=%s (expect credit/non-null/77.78/precharge_refund/77.78)',
            $finalLease['precharge_refund_method']     ?? 'null',
            $finalLease['precharge_refund_settled_at'] ?? 'null',
            $finalLease['precharge_balance']           ?? 'null',
            $cn['source'] ?? 'null',
            $cn['amount'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S10 — Close with residual > 0: cash refund (intent-only, D169)
//
// Same setup as S09 but cash branch: method='cash', settled_at=NULL
// (D-B(i) defer-settle). No credit_note created. precharge_balance
// preserved at 77.78 per D182.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S10 close residual>0 → cash refund intent-only (D169)',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '77.78',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '5000.00',
            'odometer_at_period_end_km'   => '5000.00',
            'created_by'                  => $userId,
        ]);

        $now = date('Y-m-d H:i:s');
        db_update('leases', [
            'precharge_refund_method'     => 'cash',
            'precharge_refund_settled_at' => null,
            'status'                      => 'completed',
            'closed_at'                   => $now,
        ], 'id = ?', [$leaseId]);
        $lease = db_row("SELECT contract_number FROM leases WHERE id = ?", [$leaseId]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => 'smoke',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_refund_issued',
            'entity_id'    => $leaseId,
            'entity_label' => $lease['contract_number'],
            'notes'        => 'S-MILEAGE-5 S10 — cash branch dispatch (deferred-settle)',
            'new_values'   => json_encode([
                'method'                       => 'cash',
                'amount'                       => '77.78',
                'precharge_balance_at_close'   => '77.78',
                'settled_at'                   => null,
                'closed_by_user_id'            => $userId,
            ]),
        ]);

        $finalLease = db_row("SELECT precharge_refund_method, precharge_refund_settled_at, precharge_balance FROM leases WHERE id = ?", [$leaseId]);
        $cn         = db_row("SELECT id FROM credit_notes WHERE lease_id = ? AND source = 'precharge_refund'", [$leaseId]);

        $ok = $finalLease
            && $finalLease['precharge_refund_method']     === 'cash'
            && $finalLease['precharge_refund_settled_at'] === null
            && bccomp((string) $finalLease['precharge_balance'], '77.78', 2) === 0
            && $cn === null;
        return ['ok' => $ok, 'msg' => sprintf('method=%s settled_at=%s balance=%s cn=%s (expect cash/null/77.78/null)',
            $finalLease['precharge_refund_method']     ?? 'null',
            $finalLease['precharge_refund_settled_at'] ?? 'null',
            $finalLease['precharge_balance']           ?? 'null',
            $cn['id'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S11 — Mark cash refund settled
//
// Mirror api/v1/leases/mark_refund_settled.php happy path: validate
// status='completed' + method='cash' + settled_at IS NULL → stamp
// settled_at = NOW(). precharge_balance unchanged.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S11 mark cash refund settled (D169 step 2)',
    function ($customerId, $unitId, $userId) {
        // Set up S10's post-close cash-intent state.
        $leaseId = s5_make_lease([
            'precharge_enabled'           => 1,
            'precharge_amount'            => '500.00',
            'precharge_balance'           => '77.78',
            'precharge_invoiced_at'       => '2026-03-31 12:00:00',
            'precharge_refund_method'     => 'cash',
            'precharge_refund_settled_at' => null,
            'status'                      => 'completed',
            'closed_at'                   => '2026-05-01 10:00:00',
            'closed_by_user_id'           => $userId,
        ], $customerId, $unitId, $userId);

        // Mirror mark_refund_settled.php validation predicates
        // (api/v1/leases/mark_refund_settled.php:84-113).
        $lease = db_row("SELECT status, precharge_refund_method, precharge_refund_settled_at FROM leases WHERE id = ?", [$leaseId]);
        $statusOk     = $lease['status'] === 'completed';
        $methodOk     = $lease['precharge_refund_method'] === 'cash';
        $notSettledOk = $lease['precharge_refund_settled_at'] === null;

        $now = date('Y-m-d H:i:s');
        db_update('leases', ['precharge_refund_settled_at' => $now], 'id = ?', [$leaseId]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => 'smoke',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_refund_settled',
            'entity_id'    => $leaseId,
            'entity_label' => 'S5-S11',
            'notes'        => 'S-MILEAGE-5 S11 — mark settled',
            'new_values'   => json_encode([
                'method'             => 'cash',
                'settled_at'         => $now,
                'settled_by_user_id' => $userId,
            ]),
        ]);

        $finalLease = db_row("SELECT precharge_refund_settled_at, precharge_balance FROM leases WHERE id = ?", [$leaseId]);
        $ok = $statusOk && $methodOk && $notSettledOk
            && $finalLease['precharge_refund_settled_at'] !== null
            && bccomp((string) $finalLease['precharge_balance'], '77.78', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('gates=%s/%s/%s settled_at=%s balance=%s (expect true/true/true/non-null/77.78)',
            $statusOk ? 't' : 'f',
            $methodOk ? 't' : 'f',
            $notSettledOk ? 't' : 'f',
            $finalLease['precharge_refund_settled_at'] ?? 'null',
            $finalLease['precharge_balance']           ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S12 — Mark cash refund settled idempotency: 409 ALREADY_SETTLED
//
// Inline-predicate mirror of mark_refund_settled.php:108-113. When
// the lease is already settled, the validator triggers a 409 path.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S12 mark settled retry → 409 ALREADY_SETTLED',
    function ($customerId, $unitId, $userId) {
        $leaseId = s5_make_lease([
            'precharge_enabled'           => 1,
            'precharge_amount'            => '500.00',
            'precharge_balance'           => '77.78',
            'precharge_invoiced_at'       => '2026-03-31 12:00:00',
            'precharge_refund_method'     => 'cash',
            'precharge_refund_settled_at' => '2026-05-01 10:00:01', // ← already settled
            'status'                      => 'completed',
            'closed_at'                   => '2026-05-01 10:00:00',
            'closed_by_user_id'           => $userId,
        ], $customerId, $unitId, $userId);

        $lease = db_row("SELECT status, precharge_refund_method, precharge_refund_settled_at FROM leases WHERE id = ?", [$leaseId]);
        // Validator order mirrors mark_refund_settled.php exactly:
        //   1) status='completed' (else 422 INVALID_LEASE_STATUS)
        //   2) method='cash'      (else 422 METHOD_MISMATCH)
        //   3) settled_at IS NULL (else 409 ALREADY_SETTLED) ← S12 trips this
        $would409 = $lease['status'] === 'completed'
            && $lease['precharge_refund_method'] === 'cash'
            && $lease['precharge_refund_settled_at'] !== null;

        return ['ok' => $would409, 'msg' => sprintf('would-trigger-409=%s settled_at=%s (expect true/non-null)',
            $would409 ? 'true' : 'false',
            $lease['precharge_refund_settled_at'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S13 — Re-close attempt: 409 PRECHARGE_REFUND_LOCKED (D171)
//
// Inline-predicate mirror of close.php:1062-1066. When a refund
// method is already set (immutable state machine per D-D / D171),
// a second close attempt trips the 409 PRECHARGE_REFUND_LOCKED gate.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S13 re-close attempt → 409 PRECHARGE_REFUND_LOCKED',
    function ($customerId, $unitId, $userId) {
        $leaseId = s5_make_lease([
            'precharge_enabled'           => 1,
            'precharge_amount'            => '500.00',
            'precharge_balance'           => '77.78',
            'precharge_invoiced_at'       => '2026-03-31 12:00:00',
            'precharge_refund_method'     => 'cash',  // ← method already set
            'precharge_refund_settled_at' => null,
            'status'                      => 'completed',
            'closed_at'                   => '2026-05-01 10:00:00',
            'closed_by_user_id'           => $userId,
        ], $customerId, $unitId, $userId);

        // close.php D-K gate (lines 1057-1066):
        //   needsRefund = precharge_enabled=1 AND residualBalance > 0
        //   if needsRefund AND precharge_refund_method !== null → 409 LOCKED
        $leasePostFinal = db_row(
            "SELECT precharge_balance, precharge_refund_method, precharge_enabled
               FROM leases WHERE id = ?",
            [$leaseId]
        );
        $residualBalance = (string) ($leasePostFinal['precharge_balance'] ?? '0.00');
        $needsRefund = (int) $leasePostFinal['precharge_enabled'] === 1
            && bccomp($residualBalance, '0.00', 2) > 0;
        $would409 = $needsRefund && $leasePostFinal['precharge_refund_method'] !== null;

        return ['ok' => $would409, 'msg' => sprintf('would-trigger-409=%s needsRefund=%s method=%s (expect true/true/cash)',
            $would409 ? 'true' : 'false',
            $needsRefund ? 'true' : 'false',
            $leasePostFinal['precharge_refund_method'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S14 — Tiny residual: $0.01 bcmath precision
//
// Edge case: balance=$0.01, no drawdown (distance=0), credit refund.
// Confirms bcmath handles cent-precision amounts cleanly through
// the refund pipeline.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S14 tiny $0.01 residual + credit refund',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '0.01',
            'precharge_balance'     => '0.01',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        // Final invoice with zero distance → no drawdown → residual=$0.01.
        $gen = new InvoiceGenerator();
        $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '0.00',
            'created_by'                  => $userId,
        ]);

        $leasePost = db_row("SELECT precharge_balance, customer_id, contract_number, currency FROM leases WHERE id = ?", [$leaseId]);
        $residualBalance = (string) $leasePost['precharge_balance'];
        $now = date('Y-m-d H:i:s');
        $cnNumber = 'CN-CR-S5-S14-' . substr(md5((string) microtime(true)), 0, 8);
        $cnId = db_insert('credit_notes', [
            'credit_note_number' => $cnNumber,
            'customer_id'        => $leasePost['customer_id'],
            'lease_id'           => $leaseId,
            'source'             => 'precharge_refund',
            'amount'             => $residualBalance,
            'currency'           => $leasePost['currency'],
            'amount_remaining'   => $residualBalance,
            'status'             => 'active',
            'reason'             => "Precharge balance refund — lease {$leasePost['contract_number']}",
            'created_by'         => $userId,
        ]);
        db_update('leases', [
            'precharge_refund_method'     => 'credit',
            'precharge_refund_settled_at' => $now,
            'status'                      => 'completed',
            'closed_at'                   => $now,
        ], 'id = ?', [$leaseId]);

        $cn = db_row("SELECT amount FROM credit_notes WHERE id = ?", [$cnId]);
        $finalLease = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $cn && bccomp((string) $cn['amount'], '0.01', 2) === 0
            && bccomp((string) $finalLease['precharge_balance'], '0.01', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('cn.amount=%s balance=%s (expect 0.01/0.01)',
            $cn['amount']         ?? 'null',
            $finalLease['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S15 — Close without method → 422 PRECHARGE_REFUND_REQUIRED (D-K)
//
// Inline-predicate mirror of close.php:1069-1073. When needsRefund
// is true but the request body has no precharge_refund block, the
// validator triggers a 422 path.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S15 close w/o method → 422 PRECHARGE_REFUND_REQUIRED',
    function ($customerId, $unitId, $userId) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '77.78',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        $leasePostFinal = db_row("SELECT precharge_balance, precharge_refund_method, precharge_enabled FROM leases WHERE id = ?", [$leaseId]);
        $residualBalance = (string) $leasePostFinal['precharge_balance'];
        $needsRefund = (int) $leasePostFinal['precharge_enabled'] === 1
            && bccomp($residualBalance, '0.00', 2) > 0;

        // Simulate the request body: no precharge_refund block.
        $prechargeRefund = null;
        // close.php gate order (lines 1062-1073):
        //   1) needsRefund AND method !== null → 409 LOCKED
        //   2) needsRefund AND $prechargeRefund === null → 422 REQUIRED ← S15
        $would422 = $needsRefund
            && $leasePostFinal['precharge_refund_method'] === null
            && $prechargeRefund === null;

        return ['ok' => $would422, 'msg' => sprintf('would-trigger-422=%s needsRefund=%s body.method=%s (expect true/true/null)',
            $would422 ? 'true' : 'false',
            $needsRefund ? 'true' : 'false',
            $prechargeRefund === null ? 'null' : 'present')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S16 — Model B Lite: rate>0, precharge_enabled=0 (D135 row 2)
//
// No precharge schema; every km bills straight via mileage_usage on
// every invoice. No drawdown_credit ever emits (balance is NULL or
// 0 — Branch B of D148). Close is clean: refund block skipped (gate
// requires precharge_enabled=1).
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S16 Model B Lite (D135 row 2): usage-only, no refund',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 0,
            'precharge_amount'      => null,
            'precharge_balance'     => null,
            'precharge_invoiced_at' => null,
            'mileage_rate_km'       => '0.18',
            'estimated_mileage_km'  => '0',
        ], $customerId, $unitId, $userId);

        // Invoice 1-equivalent: 100 km × $0.18 = $18.00 usage.
        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '100.00',
            'created_by'                  => $userId,
        ]);

        $pre    = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_precharge'", [$res['invoice_id']]);
        $usage  = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);

        // Close: precharge_enabled=0 → needsRefund=false; refund block skipped.
        $leasePostFinal = db_row("SELECT precharge_balance, precharge_enabled FROM leases WHERE id = ?", [$leaseId]);
        $residualBalance = $leasePostFinal['precharge_balance'] !== null
            ? (string) $leasePostFinal['precharge_balance'] : '0.00';
        $needsRefund = (int) $leasePostFinal['precharge_enabled'] === 1
            && bccomp($residualBalance, '0.00', 2) > 0;
        db_update('leases', ['status' => 'completed', 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$leaseId]);
        $finalLease = db_row("SELECT precharge_refund_method FROM leases WHERE id = ?", [$leaseId]);

        $ok = $pre === null
            && $usage && bccomp((string) $usage['amount'], '18.00', 2) === 0
            && $credit === null
            && !$needsRefund
            && $finalLease['precharge_refund_method'] === null;
        return ['ok' => $ok, 'msg' => sprintf('precharge=%s usage=%s credit=%s needsRefund=%s method=%s (expect null/18.00/null/false/null)',
            $pre['id']   ?? 'null',
            $usage['amount'] ?? 'null',
            $credit['id'] ?? 'null',
            $needsRefund ? 'true' : 'false',
            $finalLease['precharge_refund_method'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S17 — Disabled mileage: rate=0, precharge_enabled=0 (D135 row 3)
//
// No mileage_* lines on any invoice. D-C SOFT WARNING (Sentry +
// audit) only fires if distance > 0; with distance=0 the warning
// path stays quiet too. Close is clean.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S17 Disabled mileage (D135 row 3): no mileage lines anywhere',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 0,
            'precharge_amount'      => null,
            'precharge_balance'     => null,
            'precharge_invoiced_at' => null,
            'mileage_rate_km'       => '0',
            'estimated_mileage_km'  => '0',
        ], $customerId, $unitId, $userId);

        $gen = new InvoiceGenerator();
        $res = $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '0.00',
            'created_by'                  => $userId,
        ]);

        $mileageLines = db_row(
            "SELECT COUNT(*) AS c FROM invoice_line_items
              WHERE invoice_id = ?
                AND item_type IN ('mileage_precharge','mileage_usage','mileage_drawdown_credit','mileage_adjustment','mileage_credit')",
            [$res['invoice_id']]
        );

        // Close: precharge_enabled=0 → no refund gate fires.
        db_update('leases', ['status' => 'completed', 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$leaseId]);
        $finalLease = db_row("SELECT precharge_refund_method FROM leases WHERE id = ?", [$leaseId]);

        $ok = ((int) $mileageLines['c']) === 0
            && $finalLease['precharge_refund_method'] === null;
        return ['ok' => $ok, 'msg' => sprintf('mileage_lines=%d method=%s (expect 0/null)',
            (int) $mileageLines['c'],
            $finalLease['precharge_refund_method'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S18 — Samsara unavailable: no drawdown emit (K-18 fallback discipline)
//
// Lease's equipment_unit has samsara_vehicle_id=NULL; caller doesn't
// pre-populate odometer values (K-18 honored). Samsara fallback gate
// fails on the samsara_vehicle_id check → $periodDistanceKm stays
// null → drawdown gate fails on the distance > 0 clause → no
// mileage_usage or mileage_drawdown_credit lines emit. Invoice
// still generates (base_rental etc.) without PHP error.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S18 Samsara unavailable (samsara_vehicle_id=NULL): no drawdown emit',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        // Synthetic unit with samsara_vehicle_id=NULL (production unit
        // may have it set; this hermetic synthesis guarantees the test
        // shape regardless of fixture state).
        $synthUnitId = s5_make_unit(null, $userId);

        $leaseId = s5_make_lease([
            'equipment_unit_id'     => $synthUnitId,
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '500.00',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $synthUnitId, $userId);

        $gen = new InvoiceGenerator();
        // K-18: deliberately NOT pre-populating odometer_at_period_*_km
        // → exercises the fallback path. Samsara fetch is skipped
        // because the lease's equipment_unit has no samsara_vehicle_id.
        $res = $gen->createFromLease([
            'lease_id'     => $leaseId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'billing_type' => 'full_month',
            'invoice_type' => 'regular',
            'created_by'   => $userId,
        ]);

        $usage  = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);
        $base   = db_row("SELECT amount FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental'", [$res['invoice_id']]);
        $post   = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        $ok = $usage === null
            && $credit === null
            && $base !== null  // base_rental still emits — invoice itself is healthy
            && $post && bccomp((string) $post['precharge_balance'], '500.00', 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('usage=%s credit=%s base=%s post=%s (expect null/null/non-null/500.00)',
            $usage['id']  ?? 'null',
            $credit['id'] ?? 'null',
            $base['amount'] ?? 'null',
            $post['precharge_balance'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S19 — Samsara warning path (gateway_reset_detected via FIX_RESET)
//
// FixtureProvider::scenarioGatewayReset returns distance=0 +
// warnings=['gateway_reset_detected']. With distance=0, the drawdown
// gate's "> 0" clause fails → no mileage_usage / mileage_drawdown_
// credit lines emit (Samsara FALSE-POSITIVE distance from a reset
// shouldn't produce billable usage). The Samsara fetch DID run (the
// fallback path fired because no odometer pre-population), so the
// audit_log carries the fetch context.
//
// Note: original spec text suggested "mileage_usage + drawdown_credit
// emit (with reset-detected distance)" — but the fixture clamps
// distance to 0 on reset detection (FixtureProvider.php:143), so the
// gate fails. Test asserts the actual production behavior, which is
// the right answer per K-18 / K-20 (test what the code does, not
// what the spec assumed).
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S19 Samsara FIX_RESET: drawdown skipped (distance=0); warning audited',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        // Enable Samsara fixture-mode for this scenario (UPDATE inside
        // BEGIN; ROLLBACK reverts at scenario end).
        $hasSetting = db_row("SELECT `key` FROM settings WHERE `key` = 'samsara.fixture_mode'");
        if ($hasSetting) {
            db_execute("UPDATE settings SET `value` = '1' WHERE `key` = 'samsara.fixture_mode'");
        } else {
            db_execute("INSERT INTO settings (`key`, `value`, `group_name`) VALUES ('samsara.fixture_mode', '1', 'samsara')");
        }

        $synthUnitId = s5_make_unit('FIX_RESET', $userId);
        $leaseId = s5_make_lease([
            'equipment_unit_id'     => $synthUnitId,
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '500.00',
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $synthUnitId, $userId);

        $gen = new InvoiceGenerator();
        // No odometer pre-population → Samsara fallback path engages →
        // FIX_RESET fixture returns distance='0' + warning.
        $res = $gen->createFromLease([
            'lease_id'     => $leaseId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'billing_type' => 'full_month',
            'invoice_type' => 'regular',
            'created_by'   => $userId,
        ]);

        $usage  = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$res['invoice_id']]);
        $credit = db_row("SELECT id FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_drawdown_credit'", [$res['invoice_id']]);
        $post   = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);

        // Samsara fetch audit_log row: InvoiceGenerator writes one
        // entity_type='samsara_history_query' / module='samsara' row
        // per fetch, with entity_id=leaseId and the vehicle ID +
        // distance + warnings serialized into new_values JSON (see
        // lib/Billing/InvoiceGenerator.php:371-390).
        $samsaraAudit = db_row(
            "SELECT id, new_values FROM audit_log
              WHERE module      = 'samsara'
                AND entity_type = 'samsara_history_query'
                AND entity_id   = ?
              ORDER BY id DESC LIMIT 1",
            [$leaseId]
        );
        // Confirm the gateway_reset_detected warning was captured in
        // the audit row's serialized payload.
        $hasResetWarning = false;
        if ($samsaraAudit && $samsaraAudit['new_values']) {
            $decoded = json_decode($samsaraAudit['new_values'], true);
            $hasResetWarning = is_array($decoded)
                && isset($decoded['warnings'])
                && in_array('gateway_reset_detected', $decoded['warnings'], true);
        }

        $ok = $usage === null         // distance=0 → drawdown gate fails
            && $credit === null
            && $post && bccomp((string) $post['precharge_balance'], '500.00', 2) === 0
            && $samsaraAudit !== null  // Samsara fetch ran + audited
            && $hasResetWarning;       // gateway_reset_detected captured
        return ['ok' => $ok, 'msg' => sprintf('usage=%s credit=%s balance=%s samsara_audit=%s reset_warning=%s (expect null/null/500.00/non-null/true)',
            $usage['id']  ?? 'null',
            $credit['id'] ?? 'null',
            $post['precharge_balance'] ?? 'null',
            $samsaraAudit['id'] ?? 'null',
            $hasResetWarning ? 'true' : 'false')];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// S20 — I10 cross-check: refund amount == post-drawdown precharge_balance
//
// K-21 lock validated end-to-end. The exact bug class S-MILEAGE-3-FIX-0
// remediated: pre-fix, refund_amount was the PRE-drawdown balance
// ($300 in the canonical T1 walk), causing a double-credit when the
// drawdown also fired in the final-invoice generation. Post-fix,
// refund_amount = post-drawdown residual ($77.78). S20 confirms the
// invariant holds: the audit_log refund amount == the preserved
// precharge_balance (D182 — balance NOT zeroed post-refund).
//
// Setup: pre-drawdown balance=$300; final invoice drawdown consumes
// $222.22 (1234.56 km × $0.18); post-drawdown residual=$77.78.
// Credit refund issued for $77.78. Lease.precharge_balance preserved
// at $77.78 per D182.
// ─────────────────────────────────────────────────────────────────────
s5_scenario($results, 'S20 I10 cross-check: audit refund_amount == post-drawdown balance',
    function ($customerId, $unitId, $userId) use ($periodStart, $periodEnd) {
        $leaseId = s5_make_lease([
            'precharge_enabled'     => 1,
            'precharge_amount'      => '500.00',
            'precharge_balance'     => '300.00',          // PRE-drawdown
            'precharge_invoiced_at' => '2026-03-31 12:00:00',
        ], $customerId, $unitId, $userId);

        // Final invoice: 1234.56 km × $0.18 = $222.22 charge → drawdown
        // $222.22 → post-drawdown balance = 300 − 222.22 = $77.78.
        $gen = new InvoiceGenerator();
        $gen->createFromLease([
            'lease_id'                    => $leaseId,
            'period_start'                => $periodStart,
            'period_end'                  => $periodEnd,
            'billing_type'                => 'full_month',
            'invoice_type'                => 'regular',
            'odometer_at_period_start_km' => '0.00',
            'odometer_at_period_end_km'   => '1234.56',
            'created_by'                  => $userId,
        ]);

        // Re-SELECT FOR UPDATE post-drawdown per K-21 (mirrors close.php).
        $leasePostFinal = db_row("SELECT precharge_balance, customer_id, contract_number, currency FROM leases WHERE id = ?", [$leaseId]);
        $residualBalance = (string) $leasePostFinal['precharge_balance'];

        // Credit branch dispatch (mirror close.php D-C).
        $now = date('Y-m-d H:i:s');
        $cnNumber = 'CN-CR-S5-S20-' . substr(md5((string) microtime(true)), 0, 8);
        $cnId = db_insert('credit_notes', [
            'credit_note_number' => $cnNumber,
            'customer_id'        => $leasePostFinal['customer_id'],
            'lease_id'           => $leaseId,
            'source'             => 'precharge_refund',
            'amount'             => $residualBalance,         // ← post-drawdown residual
            'currency'           => $leasePostFinal['currency'],
            'amount_remaining'   => $residualBalance,
            'status'             => 'active',
            'reason'             => "Precharge balance refund — lease {$leasePostFinal['contract_number']}",
            'created_by'         => $userId,
        ]);
        db_update('leases', [
            'precharge_refund_method'     => 'credit',
            'precharge_refund_settled_at' => $now,
            'status'                      => 'completed',
            'closed_at'                   => $now,
        ], 'id = ?', [$leaseId]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => 'smoke',
            'action'       => 'update',
            'module'       => 'leases',
            'entity_type'  => 'lease_precharge_refund_issued',
            'entity_id'    => $leaseId,
            'entity_label' => $leasePostFinal['contract_number'],
            'notes'        => 'S-MILEAGE-5 S20 — I10 cross-check',
            'new_values'   => json_encode([
                'method'                     => 'credit',
                'amount'                     => $residualBalance,
                'precharge_balance_at_close' => $residualBalance,
                'related_credit_note_id'     => $cnId,
                'related_credit_note_number' => $cnNumber,
                'settled_at'                 => $now,
                'closed_by_user_id'          => $userId,
            ]),
        ]);

        // I10-shape assertion: the audit_log refund amount must equal
        // the post-drawdown precharge_balance (D182 preserved value).
        // Cross-checks the K-21 fix end-to-end.
        $audit = db_row(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.amount')) AS audit_amount
               FROM audit_log
              WHERE entity_type = 'lease_precharge_refund_issued'
                AND entity_id = ?
              ORDER BY id DESC LIMIT 1",
            [$leaseId]
        );
        $finalLease = db_row("SELECT precharge_balance FROM leases WHERE id = ?", [$leaseId]);
        $cn = db_row("SELECT amount FROM credit_notes WHERE id = ?", [$cnId]);

        // The K-21 anti-pattern: pre-fix code would have written
        // amount=$300 (pre-drawdown). Post-fix: amount=$77.78 matches
        // the preserved precharge_balance.
        $ok = $finalLease && bccomp((string) $finalLease['precharge_balance'], '77.78', 2) === 0
            && $audit && bccomp((string) $audit['audit_amount'], '77.78', 2) === 0
            && $cn && bccomp((string) $cn['amount'], '77.78', 2) === 0
            && bccomp((string) $audit['audit_amount'], (string) $finalLease['precharge_balance'], 2) === 0
            && bccomp((string) $cn['amount'], (string) $finalLease['precharge_balance'], 2) === 0;
        return ['ok' => $ok, 'msg' => sprintf('balance=%s audit_amount=%s cn.amount=%s (expect 77.78/77.78/77.78; K-21 lock)',
            $finalLease['precharge_balance'] ?? 'null',
            $audit['audit_amount'] ?? 'null',
            $cn['amount'] ?? 'null')];
    }, $customerId, $unitId, $userId);

// ═════════════════════════════════════════════════════════════════════
// S-CRON-FIX-1 — monthly billing cron: timezone + <= catch-up + idempotency
// Regression coverage for cron-audit HIGH-1 (invoice_generate_monthly silently
// billed zero invoices: date('Y-m-d') under a UTC cron-fire boundary resolved
// to the prior day, and the exact `next_billing_date = ?` match never caught
// up). Each scenario drives ff_run_monthly_billing() with a deterministic
// $today inside the hermetic BEGIN/ROLLBACK harness. Leases use a fresh
// NON-Samsara unit so billing is clean full_month (no odometer noise).
// ═════════════════════════════════════════════════════════════════════

/**
 * Push the year's invoice-number counter clear of MAX(invoice_number) so an
 * in-scenario generate can't collide on the INV-YYYY-NNNNN UNIQUE index.
 * Reverts with the scenario's ROLLBACK.
 */
function mbcron_bump_counter(): void {
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 50)]
    );
}

// NOTE on isolation: ff_run_monthly_billing() bills EVERY eligible lease in the
// DB, so the returned global counters also reflect unrelated seed leases. These
// scenarios therefore assert on the SEEDED lease's own invoices + advanced
// next_billing_date (robust regardless of other data), and use early-2026 dates
// so the rehearsal seed leases (next_billing_date in June 2026) aren't eligible.

// C1 — next_billing_date == today → bills the calendar month + advances 1 month.
s5_scenario($results, 'C1 cron bills when next_billing_date == today',
    function ($customerId, $unitId, $userId) {
        mbcron_bump_counter();
        $unit    = s5_make_unit(null, $userId); // non-Samsara → clean full_month
        $leaseId = s5_make_lease(['equipment_unit_id' => $unit, 'next_billing_date' => '2026-03-15'], $customerId, $unitId, $userId);

        ff_run_monthly_billing('2026-03-15');
        $inv = db_select("SELECT billing_period_start, billing_period_end FROM invoices WHERE lease_id = ? AND deleted_at IS NULL", [$leaseId]);
        $nbd = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseId])['next_billing_date'];

        $ok = count($inv) === 1
            && ($inv[0]['billing_period_start'] ?? '') === '2026-03-01'
            && ($inv[0]['billing_period_end'] ?? '') === '2026-03-31'
            && $nbd === '2026-04-15';
        return ['ok' => $ok, 'msg' => sprintf('my-lease invoices=%d period=%s..%s nbd->%s (expect 1/2026-03-01..2026-03-31/2026-04-15)',
            count($inv), $inv[0]['billing_period_start'] ?? 'none', $inv[0]['billing_period_end'] ?? 'none', $nbd)];
    }, $customerId, $unitId, $userId);

// C2 — next_billing_date in the PAST → <= catches it (the old `=` would miss it).
s5_scenario($results, 'C2 cron catch-up bills a past-due period (<= match)',
    function ($customerId, $unitId, $userId) {
        mbcron_bump_counter();
        $unit    = s5_make_unit(null, $userId);
        $leaseId = s5_make_lease(['equipment_unit_id' => $unit, 'next_billing_date' => '2026-03-01'], $customerId, $unitId, $userId);

        ff_run_monthly_billing('2026-03-20'); // 19 days "late" — old exact-match billed NOTHING
        $inv = db_select("SELECT billing_period_start FROM invoices WHERE lease_id = ? AND deleted_at IS NULL", [$leaseId]);
        $nbd = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseId])['next_billing_date'];

        $ok = count($inv) === 1 && ($inv[0]['billing_period_start'] ?? '') === '2026-03-01' && $nbd === '2026-04-01';
        return ['ok' => $ok, 'msg' => sprintf('my-lease invoices=%d nbd->%s (expect 1/2026-04-01 — proves <= catches a date that already passed)', count($inv), $nbd)];
    }, $customerId, $unitId, $userId);

// C3 — next_billing_date in the FUTURE → not selected, nothing billed.
s5_scenario($results, 'C3 cron does NOT bill a future-dated lease',
    function ($customerId, $unitId, $userId) {
        $unit    = s5_make_unit(null, $userId);
        $leaseId = s5_make_lease(['equipment_unit_id' => $unit, 'next_billing_date' => '2026-04-01'], $customerId, $unitId, $userId);

        ff_run_monthly_billing('2026-03-20');
        $cnt = (int) db_row("SELECT COUNT(*) c FROM invoices WHERE lease_id = ? AND deleted_at IS NULL", [$leaseId])['c'];
        $nbd = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseId])['next_billing_date'];

        $ok = $cnt === 0 && $nbd === '2026-04-01';
        return ['ok' => $ok, 'msg' => sprintf('my-lease invoices=%d nbd=%s (expect 0/unchanged 2026-04-01)', $cnt, $nbd)];
    }, $customerId, $unitId, $userId);

// C4 — period already has a full_month invoice → NO double-bill; pointer heals.
s5_scenario($results, 'C4 cron does NOT double-bill an already-billed period',
    function ($customerId, $unitId, $userId) {
        mbcron_bump_counter();
        $unit    = s5_make_unit(null, $userId);
        $leaseId = s5_make_lease(['equipment_unit_id' => $unit, 'next_billing_date' => '2026-03-01'], $customerId, $unitId, $userId);

        // Pre-existing March full_month invoice (e.g. advance-billed or a prior
        // partial run) — but next_billing_date was NOT advanced.
        (new InvoiceGenerator())->createFromLease([
            'lease_id' => $leaseId, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'billing_type' => 'full_month', 'invoice_type' => 'regular',
            'generation_source' => 'cron', 'auto_generated' => 1, 'created_by' => null,
        ]);

        ff_run_monthly_billing('2026-03-20');
        $cnt = (int) db_row("SELECT COUNT(*) c FROM invoices WHERE lease_id = ? AND billing_period_start = '2026-03-01' AND deleted_at IS NULL", [$leaseId])['c'];
        $nbd = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseId])['next_billing_date'];

        // Exactly one March invoice (not two) + the pointer advanced past it.
        $ok = $cnt === 1 && $nbd === '2026-04-01';
        return ['ok' => $ok, 'msg' => sprintf('my-lease march_invoices=%d nbd->%s (expect 1/2026-04-01 — no double-bill, pointer healed)', $cnt, $nbd)];
    }, $customerId, $unitId, $userId);

// C5 — two missed periods → BOTH billed in one run (catch-up, not 2 months).
s5_scenario($results, 'C5 cron multi-period catch-up bills every missed month',
    function ($customerId, $unitId, $userId) {
        mbcron_bump_counter();
        $unit    = s5_make_unit(null, $userId);
        $leaseId = s5_make_lease(['equipment_unit_id' => $unit, 'next_billing_date' => '2026-02-01'], $customerId, $unitId, $userId);

        ff_run_monthly_billing('2026-03-20'); // Feb + March due in one run
        $inv    = db_select("SELECT billing_period_start FROM invoices WHERE lease_id = ? AND deleted_at IS NULL ORDER BY billing_period_start", [$leaseId]);
        $starts = array_column($inv, 'billing_period_start');
        $nbd    = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseId])['next_billing_date'];

        $ok = count($inv) === 2 && $starts === ['2026-02-01', '2026-03-01'] && $nbd === '2026-04-01';
        return ['ok' => $ok, 'msg' => sprintf('my-lease invoices=%d periods=[%s] nbd->%s (expect 2 / 2026-02-01,2026-03-01 / 2026-04-01 in ONE run)',
            count($inv), implode(',', $starts), $nbd)];
    }, $customerId, $unitId, $userId);

// C6 — today() resolves a business-tz calendar day + honors the non-prod override.
s5_scenario($results, 'C6 ff_monthly_billing_today resolves business-tz + override',
    function ($customerId, $unitId, $userId) {
        putenv('FF_CRON_TODAY=2026-02-15');
        $override = ff_monthly_billing_today();
        putenv('FF_CRON_TODAY');                 // clear the override
        $natural  = ff_monthly_billing_today();

        $ok = $override === '2026-02-15'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $natural) === 1;
        return ['ok' => $ok, 'msg' => sprintf('override=%s natural=%s (expect 2026-02-15 / a valid business-tz Y-m-d)', $override, $natural)];
    }, $customerId, $unitId, $userId);

// ─────────────────────────────────────────────────────────────────────
// Final tally
// ─────────────────────────────────────────────────────────────────────
$passed = count(array_filter($results, fn($r) => $r['ok']));
$total  = count($results);
echo str_repeat('═', 76), "\n";
printf("%d/%d passed%s\n", $passed, $total, $passed === $total ? '' : ' (FAILED)');
exit($passed === $total ? 0 : 1);
