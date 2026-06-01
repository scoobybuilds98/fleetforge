<?php
/**
 * FleetForge Demo DB Wipe
 *
 * Deletes all customer-facing, billing, and accounting transaction data
 * so the database can be reseeded with a focused demo dataset. Preserves:
 *   - config tables: settings, tax_rates, exchange_rates, yards, roles, permissions
 *   - fleet tables:  equipment_units (all), equipment_templates, samsara_location_history
 *   - accounting config: acc_accounts (chart of accounts), acc_periods (fiscal calendar)
 *   - users, sessions, portal_users
 *
 * Deletes:
 *   - All customers, leases, invoices, payments, credit_notes
 *   - All rate_cards, customer_equipment_rates
 *   - All accounting transactions (journal entries, fixed assets, bank txns, etc.)
 *   - All lease operational data (damage claims, inspections, maintenance WOs, mileage, reservations)
 *   - AI chat sessions, notifications, audit log, alerts
 *
 * Uses SET FOREIGN_KEY_CHECKS = 0 + TRUNCATE for speed and FK-constraint avoidance.
 * Run /tmp/ff_demo_leases.php afterwards to reseed.
 */

declare(strict_types=1);
require '/Users/avi/Documents/fleetforge/config/app.php';

// ── D-WIPE-PRODUCTION-GUARD ──────────────────────────────────────────────────
// G1 fix (S-DRESS-REHEARSAL-PREP-1): fail-closed production guard.
// Modeled on D-QBO-FIXTURE-2 (QuickBooksClient::fixtureRefusalReason).
//
// THREE LAYERS — all must pass before the first TRUNCATE fires:
//   1. APP_ENV must NOT be 'production'. config/app.php line 96 defaults
//      APP_ENV to 'production' when the env var is absent, so an unset or
//      missing .env line ALSO refuses — ambiguity always fails closed.
//   2. Explicit confirmation token required as a CLI arg so accidental
//      execution (cron, script runner, fat-finger) cannot wipe silently.
//      The token is intentionally verbose so it cannot appear by accident.
//   3. Blast-radius echo: resolved DB + APP_ENV + QBO realm printed to
//      stdout before the first TRUNCATE so the operator sees exactly what
//      will be wiped.

// ── Layer 1: hard-refuse on production (or ambiguous/missing ENV) ─────────
if (!defined('APP_ENV') || APP_ENV === 'production') {
    $detectedEnv = defined('APP_ENV') ? APP_ENV : '(undefined — defaults to production)';
    $detectedDb  = defined('FF_DB_NAME') ? FF_DB_NAME : '(unknown)';
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  ┌──────────────────────────────────────────────────────────────┐\n");
    fwrite(STDERR, "  │  HARD REFUSAL — demo_wipe.php refuses on production          │\n");
    fwrite(STDERR, "  └──────────────────────────────────────────────────────────────┘\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  APP_ENV = {$detectedEnv}\n");
    fwrite(STDERR, "  DB      = {$detectedDb}\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  This script TRUNCATEs customer, billing, and accounting data.\n");
    fwrite(STDERR, "  Running it against a production DB destroys live records with\n");
    fwrite(STDERR, "  no undo. There is NO override for production — ever.\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  If this is a dev/staging server, set APP_ENV=development in\n");
    fwrite(STDERR, "  your .env and re-run. (D-WIPE-PRODUCTION-GUARD)\n");
    fwrite(STDERR, "\n");
    exit(1);
}

// ── Layer 2: require explicit confirmation token ──────────────────────────
// Must be passed as a literal CLI argument. Intentionally verbose so a
// cron job or accidental invocation cannot supply it without human intent.
const DEMO_WIPE_TOKEN = '--i-understand-this-wipes-dev';
if (!in_array(DEMO_WIPE_TOKEN, $argv ?? [], true)) {
    $qboEnv   = (string) settings_get('quickbooks.environment', '(unknown)');
    $qboRealm = (string) settings_get('quickbooks.realm_id',    '(not connected)');
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  USAGE: php scripts/demo_wipe.php " . DEMO_WIPE_TOKEN . "\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Confirm this is the correct target before passing the token:\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "    APP_ENV  = " . APP_ENV . "\n");
    fwrite(STDERR, "    DB       = " . FF_DB_NAME . "\n");
    fwrite(STDERR, "    QBO env  = {$qboEnv}\n");
    fwrite(STDERR, "    QBO realm= {$qboRealm}\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Pass the token above to proceed. (D-WIPE-PRODUCTION-GUARD)\n");
    fwrite(STDERR, "\n");
    exit(1);
}

// ── Layer 3: blast-radius echo to stdout (last-look before first TRUNCATE) ─
$_wipeQboEnv   = (string) settings_get('quickbooks.environment', '(unknown)');
$_wipeQboRealm = (string) settings_get('quickbooks.realm_id',    '(not connected)');
echo "\n";
echo "  Target DB : " . FF_DB_NAME . "\n";
echo "  APP_ENV   : " . APP_ENV . "\n";
echo "  QBO env   : {$_wipeQboEnv}  realm = {$_wipeQboRealm}\n";
echo "\n";
unset($_wipeQboEnv, $_wipeQboRealm);
// ── End D-WIPE-PRODUCTION-GUARD ──────────────────────────────────────────────

echo "=== FleetForge demo DB wipe ===\n\n";
echo "Disabling FK checks for bulk truncate...\n";
db_execute('SET FOREIGN_KEY_CHECKS = 0', []);

// Order doesn't actually matter with FK_CHECKS=0, but grouping for clarity:
$toWipe = [
    // ── Billing / AR ──
    'payment_allocations', 'payments',
    'credit_note_applications', 'credit_notes',
    'invoice_line_items', 'invoices',
    'lease_billing_periods',
    'late_fee_rules',

    // ── Leases / status logs ──
    'lease_status_log',
    'leases',

    // ── Customers + related ──
    'customer_equipment_rates', 'customer_rate_history', 'customer_tags',
    'customer_contacts',
    'rate_card_items', 'rate_cards',
    'customers',

    // ── Operational (lease-dependent) ──
    'reservation_units', 'reservations',
    'damage_claims',
    'inspection_sections', 'inspections',
    'maintenance_line_items', 'maintenance_work_orders',
    'mileage_logs',

    // ── Accounting transactions ──
    'acc_ap_payment_allocations', 'acc_ap_payments',
    'acc_bill_lines', 'acc_bills',
    'acc_vendor_credit_applications', 'acc_vendor_credits',
    'acc_bank_transactions', 'acc_bank_reconciliations', 'acc_bank_accounts',
    'acc_journal_entry_lines', 'acc_journal_entries',
    'acc_depreciation_run_lines', 'acc_depreciation_runs',
    'acc_asset_disposals', 'acc_asset_impairments',
    'acc_capex_requests',
    'acc_fixed_assets',
    'acc_budget_lines', 'acc_budgets',
    'acc_categorization_rules',
    'acc_year_end_checklist',

    // ── Vendors ──
    'vendors',

    // ── Auxiliary ──
    'notification_log', 'notifications',
    'ai_anomaly_alerts', 'ai_chat_messages', 'ai_chat_sessions', 'ai_query_log', 'ai_summaries',
    'audit_log',
    'documents',
    'report_cache',
    'equipment_status_log',
];

// ── KEEP: equipment_units (but we WILL reset their counters + status to 'available')
// ──       equipment_templates, tax_rates, exchange_rates, yards, settings
// ──       acc_accounts, acc_periods
// ──       users, roles, permissions, role_permissions, user_roles, user_permissions,
// ──       user_permission_overrides, user_display_settings, portal_users
// ──       samsara_location_history (GPS breadcrumb trail — keeps live tracking demo alive)

$results = [];
foreach ($toWipe as $t) {
    try {
        $before = db_row("SELECT COUNT(*) as n FROM {$t}", [])['n'] ?? 0;
        db_execute("TRUNCATE TABLE {$t}", []);
        $results[$t] = ['before' => $before, 'status' => 'OK'];
        printf("  TRUNCATE  %-40s (%d rows removed)\n", $t, $before);
    } catch (\Throwable $e) {
        $results[$t] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        printf("  FAIL      %-40s (%s)\n", $t, substr($e->getMessage(), 0, 60));
    }
}

// Reset equipment_unit counters + status so every unit is "fresh" again
echo "\nResetting equipment_units counters + status...\n";
$reset = db_execute(
    "UPDATE equipment_units
        SET status = 'available',
            lease_count = 0,
            total_revenue = 0,
            total_maintenance_cost = 0,
            updated_by = 1
      WHERE deleted_at IS NULL
        AND status NOT IN ('decommissioned', 'inactive')",
    []
);
echo "  Equipment units reset: {$reset} rows\n";

// Re-seed invoice number counter consistent with invoices-table reality.
// S-DEMO-WIPE-COUNTER-SYNC (2026-05-26): the prior implementation hardcoded
// value='1', which collided with existing INV-YYYY-NNNNN rows if invoices
// weren't fully truncated (e.g. a future edit removing 'invoices' from
// $toWipe, or a partial-wipe run). Now we sync to MAX(invoice_number)+1
// per year, so the post-wipe state is self-consistent regardless of how
// many invoice rows survive the TRUNCATE pass. See S-INVOICE-COUNTER-BUMP
// for the recurring drift this resolves at root.
echo "\nResetting invoice number counter (sync to MAX(invoice_number)+1)...\n";
db_execute("DELETE FROM settings WHERE `key` LIKE 'invoice.next_number.%'", []);
$_yr = date('Y');
$_prefix = settings_get('invoice.prefix', 'INV');
$_max = (int)(db_row("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) AS m FROM invoices WHERE invoice_number LIKE ?", ["{$_prefix}-{$_yr}-%"])['m'] ?? 0);
$_next = $_max + 1;
db_execute("INSERT INTO settings (`key`, `value`, `value_type`, `group_name`) VALUES (?, ?, 'integer', 'invoices')", ["invoice.next_number.{$_yr}", (string)$_next]);
echo "  Counter for {$_yr} set to {$_next} (MAX existing INV-{$_yr}-* was {$_max})\n";

// Re-seed journal entry counter
db_execute("DELETE FROM settings WHERE `key` LIKE 'acc.next_je_number.%'", []);
echo "  JE counter reset\n";

echo "\nRe-enabling FK checks...\n";
db_execute('SET FOREIGN_KEY_CHECKS = 1', []);

echo "\n=== Wipe complete ===\n";
$okCount = count(array_filter($results, fn($r) => $r['status'] === 'OK'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
echo "  {$okCount} tables truncated, {$failCount} failures.\n";
