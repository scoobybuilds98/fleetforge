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

// Re-seed invoice number counter so new invoices start at 001
echo "\nResetting invoice number counter...\n";
db_execute("DELETE FROM settings WHERE `key` LIKE 'invoice.next_number.%'", []);
db_execute(
    "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`) VALUES (?, '1', 'integer', 'invoices')",
    ['invoice.next_number.' . date('Y')]
);
echo "  Counter reset to 1 for " . date('Y') . "\n";

// Re-seed journal entry counter
db_execute("DELETE FROM settings WHERE `key` LIKE 'acc.next_je_number.%'", []);
echo "  JE counter reset\n";

echo "\nRe-enabling FK checks...\n";
db_execute('SET FOREIGN_KEY_CHECKS = 1', []);

echo "\n=== Wipe complete ===\n";
$okCount = count(array_filter($results, fn($r) => $r['status'] === 'OK'));
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'FAIL'));
echo "  {$okCount} tables truncated, {$failCount} failures.\n";
