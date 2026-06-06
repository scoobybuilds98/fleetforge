<?php
declare(strict_types=1);

/**
 * scripts/golive_reset_transactions.php
 *
 * Pre-go-live production transaction reset.
 *
 * Wipes all test transactional / financial data while preserving customers,
 * equipment_units, users, config, and the chart of accounts. After the wipe,
 * resets derived counters on KEEP tables (outstanding_balance, lease_count,
 * active_lease_count, total_revenue, account_credit_balance, collection_status
 * on customers; lease_count, total_revenue, total_maintenance_cost, status for
 * on_lease/reserved → available on equipment_units). Clears report_cache.
 *
 * USAGE:
 *   php scripts/golive_reset_transactions.php              → dry-run (DEFAULT)
 *   php scripts/golive_reset_transactions.php --dry-run    → explicit dry-run
 *   php scripts/golive_reset_transactions.php --confirm-prod
 *     → requires typing "yes wipe transactions" on STDIN
 *
 * DRY-RUN:
 *   Lists every WIPE table with current row counts, every KEEP table, and the
 *   counter resets it would apply. Deletes NOTHING.
 *
 * CONFIRM-PROD:
 *   Wraps all DELETEs + counter resets in a single MySQL transaction with
 *   FK_CHECKS=0. Writes one audit_log row before commit. Prints per-table
 *   deleted-count summary.
 *
 * SAFETY RULES:
 *   - Default is dry-run — fail SAFE, not fail open.
 *   - Refuses to run if any KEEP table is absent from INFORMATION_SCHEMA.
 *   - All DELETEs + resets inside one transaction; full rollback on error.
 *   - FK_CHECKS=0 is session-scoped; restored to 1 at end of wipe block.
 *   - Audit_log row written BEFORE commit.
 *   - Idempotent: re-running on an already-wiped DB is a no-op.
 *
 * ALWAYS TEST IN DEV FIRST. Never first-run on production.
 *
 * Session:   S-GOLIVE-RESET-TXN
 * Decisions: D-GOLIVE-1 (KEEP table list), D-GOLIVE-2 (WIPE table list)
 */

require_once dirname(__DIR__) . '/config/app.php';

// ── CLI args ──────────────────────────────────────────────────────────────────
$opts        = getopt('', ['dry-run', 'confirm-prod']);
$confirmProd = isset($opts['confirm-prod']);
$dryRun      = !$confirmProd; // default is dry-run

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!in_array($arg, ['--dry-run', '--confirm-prod'], true)) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php scripts/golive_reset_transactions.php [--dry-run|--confirm-prod]\n");
        exit(2);
    }
}

// ── KEEP table list (D-GOLIVE-1) ──────────────────────────────────────────────
// Script REFUSES to run if any of these is absent from the DB.
// Rationale for each category is in the session prompt and PROGRESS.md.
const KEEP_TABLES = [
    // Chart of accounts + fiscal config
    'acc_accounts',
    'acc_periods',
    'acc_place_of_supply_rules',
    'acc_auto_categorization_rules',
    'acc_categorization_rules',
    'acc_report_configurations',
    // Bank account config (institution/account-type setup, not transaction history)
    'acc_bank_accounts',
    // CCA class reference (Canadian tax classification — Class 10, Class 8, etc.)
    'acc_cca_classes',
    // QBO mapping config (operator-set mappings that survive a transaction wipe)
    'acc_qbo_account_map',
    'acc_qbo_bank_account_map',
    'acc_qbo_customer_map',
    'acc_qbo_item_map',
    'acc_qbo_tax_code_map',
    'acc_qbo_tax_rate_map',
    'acc_qbo_vendor_map',
    // Audit trail — NEVER wipe
    'audit_log',
    'audit_log_archive',
    // Real customer + equipment data
    'customers',
    'customer_contacts',
    'customer_tags',
    'equipment_units',
    'equipment_templates',
    // User + permission config
    'users',
    'user_roles',
    'user_permissions',
    'user_permission_overrides',
    'user_mfa_backup_codes',
    'role_permission_overrides',
    'portal_users',
    // Reference / config tables
    'settings',
    'tax_rates',
    'vendors',
    'yards',
    'late_fee_rules',
    'notification_rules',
    'contract_templates',
    'email_templates',
    'inspection_sections',
    'exchange_rates',
    // Rate config
    'rate_cards',
    'rate_card_items',
    // Schema integrity
    'schema_migrations',
    // Infra/ops — backup run history is operational config, not test transaction data
    'backup_runs',
];

// ── WIPE table list (D-GOLIVE-2) ─────────────────────────────────────────────
// Ordered children-before-parents for clarity; FK_CHECKS=0 makes order
// non-critical for correctness.
const WIPE_TABLES = [
    // GL child rows
    'acc_journal_entry_lines',
    'acc_bill_lines',
    'acc_budget_lines',
    'acc_recurring_entry_lines',
    'acc_depreciation_run_lines',
    'acc_ap_payment_allocations',
    'acc_asset_disposals',
    'acc_asset_impairments',
    // QBO transaction maps (child rows)
    'acc_qbo_bank_transaction_map',
    'acc_qbo_bill_payment_map',
    'acc_qbo_invoice_map',
    'acc_qbo_payment_map',
    'acc_qbo_journal_entry_map',
    'acc_qbo_credit_memo_map',
    'acc_qbo_credit_application_map',
    'acc_qbo_refund_receipt_map',
    'acc_qbo_fixed_asset_map',
    'acc_qbo_drift_events',
    'acc_qbo_sync_log',
    'acc_qbo_sync_queue',
    'acc_qbo_webhook_events',
    'acc_qbo_payment_initiations',
    'acc_qbo_historical_pull_runs',
    // GL parent tables
    'acc_journal_entries',
    'acc_bills',
    'acc_budgets',
    'acc_recurring_entries',
    'acc_depreciation_runs',
    'acc_ap_payments',
    'acc_fixed_assets',
    'acc_bank_transactions',
    'acc_bank_reconciliations',
    'acc_vendor_credit_applications',
    'acc_vendor_credits',
    // Other GL / accounting tables
    'acc_ai_suggestions',
    'acc_bad_debt_writeoffs',
    'acc_capex_requests',
    'acc_cca_continuity',
    'acc_collection_notes',
    'acc_customer_deposits',
    'acc_disclosure_notes',
    'acc_documents',
    'acc_dunning_letters',
    'acc_fx_revaluations',
    'acc_impairment_tests',
    'acc_lease_amortization_schedules',
    'acc_lease_classifications',
    'acc_lease_residual_reviews',
    'acc_oauth_states',
    'acc_promise_to_pay',
    'acc_tax_filing_periods',
    'acc_tax_remittances',
    'acc_workpaper_annotations',
    'acc_year_end_checklist',
    'acc_year_end_closures',
    // AI / chat / messenger
    'ai_chat_messages',
    'ai_chat_sessions',
    'ai_anomaly_alerts',
    'ai_query_log',
    'ai_summaries',
    'chat_attachments',
    'chat_channel_members',
    'chat_reactions',
    'chat_messages',
    'chat_channels',
    'messenger_thread_reads',
    'messenger_messages',
    'messenger_threads',
    // Lease / invoice / payment arc (children first)
    'credit_note_applications',
    'invoice_line_items',
    'payment_allocations',
    'lease_amendments',
    'lease_billing_periods',
    'lease_status_log',
    'mileage_logs',
    'generated_contracts',
    'credit_notes',
    'invoices',
    'payments',
    'leases',
    // Backup/legacy tables
    'invoices_model_c_backup_S_MILEAGE_2B',
    'lease_close_adjustments_backup_S_MILEAGE_3',
    'leases_precharge_backup_S_MILEAGE_1',
    // Customer transactional
    'customer_discounts',
    'customer_equipment_rates',
    'customer_credit_applications',
    'customer_notes',
    'customer_rate_history',
    // Equipment / operational
    'damage_claim_photos',
    'damage_claims',
    'documents',
    'equipment_status_log',
    'inspection_photos',
    'inspections',
    'maintenance_line_items',
    'maintenance_work_orders',
    'reservation_units',
    'reservations',
    'samsara_location_history',
    'yard_transfers',
    // Portal / service
    'portal_service_request_messages',
    'portal_service_requests',
    // Notifications / email / reporting
    'email_attachments',
    'email_bounces',
    'email_logs',
    'notification_log_archive',
    'notification_log',
    'notifications',
    'report_cache',
    'saved_reports',
    'scheduled_reports',
    // Session / auth transient
    'password_reset_tokens',
    'rate_limit_attempts',
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function out(string $line = ''): void
{
    echo $line . "\n";
}

function fmt_count(int $n): string
{
    return number_format($n);
}

// ── Step 1: Verify KEEP tables exist ─────────────────────────────────────────
$dbName = defined('FF_DB_NAME') ? FF_DB_NAME : '';

out(str_repeat('═', 74));
out('  FleetForge — Go-Live Transaction Reset  (S-GOLIVE-RESET-TXN)');
out('  DB: ' . $dbName);
out('  Mode: ' . ($dryRun ? 'DRY-RUN (no writes)' : 'CONFIRM-PROD (will write after confirmation)'));
out('  Date: ' . date('Y-m-d H:i:s'));
out(str_repeat('═', 74));
out();

out('Step 1/4: Verifying all KEEP tables exist in INFORMATION_SCHEMA...');
$missing = [];
foreach (KEEP_TABLES as $tbl) {
    $exists = db_count(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
        [$dbName, $tbl]
    );
    if ($exists === 0) {
        $missing[] = $tbl;
    }
}

if (!empty($missing)) {
    fwrite(STDERR, "\n  ERROR: Cannot proceed — the following KEEP tables are MISSING from the DB:\n");
    foreach ($missing as $tbl) {
        fwrite(STDERR, "    - {$tbl}\n");
    }
    fwrite(STDERR, "\n  This script refuses to run without positively identifying all KEEP tables.\n");
    fwrite(STDERR, "  Check the DB connection and schema before retrying.\n");
    exit(1);
}
out('  ✓ All ' . count(KEEP_TABLES) . ' KEEP tables present.');

// ── Step 2: Gather row counts for all WIPE tables ────────────────────────────
out();
out('Step 2/4: Collecting row counts for all WIPE tables...');

$wipeCounts = [];
$totalRows  = 0;
foreach (WIPE_TABLES as $tbl) {
    $cnt = db_count("SELECT COUNT(*) FROM `{$tbl}`", []);
    $wipeCounts[$tbl] = $cnt;
    $totalRows += $cnt;
}

// ── Step 3: Print dry-run report ──────────────────────────────────────────────
out();
out('Step 3/4: Classification report');
out();
out('  ── WIPE tables (' . count(WIPE_TABLES) . ') ──────────────────────────────────────────');
foreach ($wipeCounts as $tbl => $cnt) {
    $marker = $cnt > 0 ? '  !' : '   ';
    printf("%s  %-55s %s rows\n", $marker, $tbl . ':', fmt_count($cnt));
}
out();
out('  Total rows that WOULD be deleted: ' . fmt_count($totalRows));
out();
out('  ── KEEP tables (' . count(KEEP_TABLES) . ') ─────────────────────────────────────────');
foreach (KEEP_TABLES as $tbl) {
    $cnt = db_count("SELECT COUNT(*) FROM `{$tbl}`", []);
    printf("     %-55s %s rows (preserved)\n", $tbl . ':', fmt_count($cnt));
}
out();
out('  ── Counter resets (on KEEP tables) ────────────────────────────────');
$custCount  = db_count("SELECT COUNT(*) FROM customers", []);
$unitCount  = db_count("SELECT COUNT(*) FROM equipment_units", []);
out("     customers ({$custCount} rows): outstanding_balance → 0, account_credit_balance → 0,");
out("       lease_count → 0, active_lease_count → 0, total_revenue → 0,");
out("       collection_status → 'current'");
$onLeaseCount = db_count(
    "SELECT COUNT(*) FROM equipment_units WHERE status IN ('on_lease','reserved')",
    []
);
out("     equipment_units ({$unitCount} rows): lease_count → 0, total_revenue → 0,");
out("       total_maintenance_cost → 0");
out("       status 'on_lease'/'reserved' → 'available' ({$onLeaseCount} units affected)");
out("     report_cache: cleared entirely (see WIPE list above)");

if ($dryRun) {
    out();
    out(str_repeat('─', 74));
    out('  DRY-RUN COMPLETE — no data was modified.');
    out('  Re-run with --confirm-prod to execute the wipe.');
    out(str_repeat('─', 74));
    out();
    exit(0);
}

// ── Step 4: Confirm-prod safety gate ─────────────────────────────────────────
out();
out(str_repeat('═', 74));
out('  ⚠   PRODUCTION TRANSACTION WIPE — DO NOT RUN UNLESS APPROVED   ⚠');
out(str_repeat('═', 74));
out();
out("  You are about to DELETE {$totalRows} rows across " . count(WIPE_TABLES) . ' tables from:');
out("    DB:   {$dbName}");
out("  This cannot be undone without a database restore.");
out();
out('  Type exactly: yes wipe transactions');
out('> ');

$confirmation = trim((string) fgets(STDIN));
if ($confirmation !== 'yes wipe transactions') {
    fwrite(STDERR, "\n  Confirmation text did not match. Aborting — no data was modified.\n");
    exit(1);
}
out();
out('  Confirmation accepted. Proceeding with wipe...');
out();

// ── Execute wipe ──────────────────────────────────────────────────────────────
$deletedCounts = [];

try {
    db_transaction(function () use (&$deletedCounts, $totalRows, $wipeCounts): void {
        $pdo = db_pdo();

        // Disable FK checks for the wipe block (session-scoped)
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (WIPE_TABLES as $tbl) {
            $stmt = $pdo->prepare("DELETE FROM `{$tbl}`");
            $stmt->execute();
            $deletedCounts[$tbl] = $stmt->rowCount();
        }

        // Restore FK checks before counter resets (KEEP tables have valid FKs)
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Reset derived counters on customers
        $pdo->exec(
            "UPDATE customers SET
                outstanding_balance   = 0,
                account_credit_balance = 0,
                lease_count           = 0,
                active_lease_count    = 0,
                total_revenue         = 0,
                collection_status     = 'current'"
        );

        // Reset derived counters + lease-derived statuses on equipment_units
        $pdo->exec(
            "UPDATE equipment_units SET
                lease_count             = 0,
                total_revenue           = 0,
                total_maintenance_cost  = 0"
        );
        $pdo->exec(
            "UPDATE equipment_units SET status = 'available'
             WHERE status IN ('on_lease', 'reserved')"
        );

        // Write audit_log row BEFORE commit
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'manual_trigger',
            'module'       => 'admin',
            'entity_type'  => 'database',
            'entity_id'    => null,
            'entity_label' => 'golive_reset_transactions',
            'notes'        => sprintf(
                'S-GOLIVE-RESET-TXN: wiped %d rows across %d tables; '
                . 'customers/equipment counters reset; FK_CHECKS=0 within transaction.',
                $totalRows,
                count(WIPE_TABLES)
            ),
            'ip_address'   => '127.0.0.1',
        ]);

        // Transaction commits here (db_transaction COMMIT)
    });
} catch (\Throwable $e) {
    fwrite(STDERR, "\nWipe FAILED — transaction rolled back. No data was modified.\n");
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

// ── Print summary ─────────────────────────────────────────────────────────────
out('Step 4/4: Wipe complete. Per-table summary:');
out();
$totalDeleted = 0;
foreach ($deletedCounts as $tbl => $cnt) {
    $marker = $cnt > 0 ? '  !' : '   ';
    printf("%s  %-55s %s deleted\n", $marker, $tbl . ':', fmt_count($cnt));
    $totalDeleted += $cnt;
}

out();
out(str_repeat('═', 74));
out("  WIPE COMPLETE — {$totalDeleted} rows deleted across " . count(WIPE_TABLES) . ' tables.');
out('  Customers + equipment counters reset. Report cache cleared.');
out('  Audit_log row written (action=golive_reset).');
out();
out('  Next steps:');
out('    1. Verify the application loads without errors.');
out('    2. Confirm customers + equipment_units are intact in the UI.');
out('    3. Confirm AR tile and invoice subledger both show $0.');
out(str_repeat('═', 74));
out();
