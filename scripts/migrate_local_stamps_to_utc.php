<?php
declare(strict_types=1);

/**
 * scripts/migrate_local_stamps_to_utc.php
 *
 * S-UTC-STAMPS one-time data repair. The S-UTC-STAMPS code change makes the
 * columns in REGISTRY below write and read UTC (the convention for every
 * DATETIME: includes/db.php pins the session to '+00:00'). Rows written BEFORE
 * that deploy still hold company-LOCAL wall time (PHP date('Y-m-d H:i:s')) and
 * would now display/compare 7–8h early. This script shifts those historical
 * values to UTC with the correct PDT/PST offset for each value's own date
 * (FleetForge\Support\LocalStampMigrator).
 *
 * HOW TO RUN (operator; dev first):
 *   1. Deploy the S-UTC-STAMPS code.
 *   2. Note the local wall time of the deploy (or any moment BEFORE it), e.g.
 *      2026-09-18 21:05:00 — rows below it are pre-deploy local stamps; UTC rows
 *      written after the deploy are always above it.
 *   3. Dry run (default, writes nothing):
 *        sudo -u www-data php scripts/migrate_local_stamps_to_utc.php --cutover="2026-09-18 21:05:00"
 *   4. Apply:
 *        sudo -u www-data php scripts/migrate_local_stamps_to_utc.php --cutover="2026-09-18 21:05:00" --apply
 *   Optional: --only=table.column (repeatable, comma-separated).
 *
 * SAFE TO RE-RUN: each column is converted in one transaction together with a
 * settings marker row (data_migration.utc_stamps.<table>.<column>); marked
 * columns are skipped. ON UPDATE CURRENT_TIMESTAMP columns are left untouched.
 * REGISTRY ORDER MATTERS: a column filtered through a companion column
 * (e.g. an expiry filtered by its sent_at) is listed BEFORE that companion.
 *
 * Refuses a cutover in the future. Prints before/after samples per column.
 *
 * @session S-UTC-STAMPS
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Support\LocalStampMigrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

/**
 * Columns converted by S-UTC-STAMPS, in processing order.
 * Each entry: [table, column, mode, extraWhere|null, paramNames[], note]
 *   mode 'past'   — value-below-cutover: historical stamps (created/sent/voided…).
 *   mode 'future' — row filter: expiry / lock-until values that can lie ABOVE the
 *                   cutover; extraWhere selects pre-deploy rows (e.g. "updated_at < ?"
 *                   bound to cutover_utc — updated_at is UTC and pinned by the repair).
 *   paramNames: values for the '?' in extraWhere, in order: 'cutover_local' | 'cutover_utc'.
 */
const REGISTRY = [
    // ── Portal users / staff lockout / service requests ─────────────────────
    ['portal_users', 'password_reset_expiry', 'future', 'updated_at < ?', ['cutover_utc'], 'future-dated expiry: select rows last written pre-deploy'],
    ['portal_users', 'invite_token_expiry', 'future', 'updated_at < ?', ['cutover_utc'], 'future-dated expiry (seeder-only writer)'],
    ['portal_users', 'locked_until', 'future', 'updated_at < ?', ['cutover_utc'], 'future-dated lock'],
    ['portal_users', 'last_login_at', 'past', null, [], 'display'],
    ['portal_users', 'invite_sent_at', 'past', null, [], 'display'],
    ['users', 'locked_until', 'future', 'updated_at < ?', ['cutover_utc'], 'future-dated staff lock'],
    ['portal_service_requests', 'resolved_at', 'past', null, [], 'display'],
    // ── Credit applications ─────────────────────────────────────────────────
    ['customer_credit_applications', 'token_expires_at', 'future', 'updated_at < ?', ['cutover_utc'], 'future-dated +30d link expiry'],
    ['customer_credit_applications', 'sent_at', 'past', null, [], ''],
    ['customer_credit_applications', 'submitted_at', 'past', null, [], 'rendered_html/PDF snapshots keep their local text'],
    ['customer_credit_applications', 'reviewed_at', 'past', null, [], ''],
    // ── AR / credit notes / refunds / batch runs ────────────────────────────
    ['credit_note_applications', 'applied_at', 'past', null, [], 'AR aging + statements + QBO TxnDate read it as UTC now'],
    ['credit_note_applications', 'reversed_at', 'past', null, [], ''],
    ['credit_notes', 'voided_at', 'past', null, [], ''],
    ['payments', 'deleted_at', 'past', null, [], 'AR aging as-of void comparison'],
    ['leases', 'precharge_refund_settled_at', 'past', null, [], 'QBO refund receipt TxnDate'],
    ['leases', 'precharge_invoiced_at', 'past', null, [], ''],
    ['invoices', 'sent_at', 'past', null, [], ''],
    ['invoice_batch_runs', 'submitted_at', 'past', null, [], ''],
    ['invoice_batch_runs', 'decided_at', 'past', null, [], ''],
    ['invoice_batch_runs', 'generated_at', 'past', null, [], ''],
    // ── Samsara / GPS / odometer (externally-dated values: select by write stamp) ──
    ['samsara_location_history', 'recorded_at', 'future', 'synced_at < ? AND (address IS NULL OR address <> \'Hwy 1, BC\')', ['cutover_utc'], 'reading time can be older than the cutover gap; convert BEFORE synced_at'],
    ['samsara_location_history', 'synced_at', 'past', "address IS NULL OR address <> 'Hwy 1, BC'", [], 'marketing-demo rows (address Hwy 1, BC) used the UTC DB default'],
    ['equipment_units', 'samsara_last_connected_at', 'future', 'updated_at < ?', ['cutover_utc'], 'last-connected can be days old'],
    ['equipment_units', 'samsara_last_synced_at', 'past', null, [], ''],
    ['equipment_units', 'health_score_updated_at', 'past', null, [], ''],
    ['equipment_distance_logs', 'period_start', 'future', 'created_at < ? AND (label IS NULL OR label NOT LIKE \'FFDEMO-MKT%\')', ['cutover_utc'], 'window bounds; convert BEFORE created_at'],
    ['equipment_distance_logs', 'period_end', 'future', 'created_at < ? AND (label IS NULL OR label NOT LIKE \'FFDEMO-MKT%\')', ['cutover_utc'], ''],
    ['equipment_distance_logs', 'first_reading_at', 'future', 'created_at < ? AND (label IS NULL OR label NOT LIKE \'FFDEMO-MKT%\')', ['cutover_utc'], ''],
    ['equipment_distance_logs', 'last_reading_at', 'future', 'created_at < ? AND (label IS NULL OR label NOT LIKE \'FFDEMO-MKT%\')', ['cutover_utc'], ''],
    ['equipment_distance_logs', 'queried_at', 'future', 'created_at < ? AND (label IS NULL OR label NOT LIKE \'FFDEMO-MKT%\')', ['cutover_utc'], ''],
    ['equipment_distance_logs', 'created_at', 'past', "label IS NULL OR label NOT LIKE 'FFDEMO-MKT%'", [], 'API writer set it local; marketing-demo rows used the UTC default'],
    ['invoices', 'odometer_fetched_at', 'future', 'updated_at < ?', ['cutover_utc'], 'client/Samsara-supplied time'],
    ['leases', 'odometer_start_fetched_at', 'future', 'updated_at < ?', ['cutover_utc'], 'client/Samsara-supplied time'],
    ['leases', 'odometer_end_fetched_at', 'future', 'updated_at < ?', ['cutover_utc'], 'client/Samsara-supplied time'],
    // ── Accounting ──────────────────────────────────────────────────────────
    ['acc_periods', 'closed_at', 'past', null, [], ''],
    ['acc_periods', 'locked_at', 'past', "NOT EXISTS (SELECT 1 FROM acc_year_end_closures c WHERE c.fiscal_year = acc_periods.year AND c.status = 'closed')", [], 'year-end-close locks were NOW() (UTC) — excluded'],
    ['acc_bank_reconciliations', 'completed_at', 'past', null, [], ''],
    ['acc_ap_payments', 'voided_at', 'past', null, [], ''],
    ['acc_bills', 'voided_at', 'past', null, [], ''],
    ['acc_year_end_checklist', 'completed_at', 'past', null, [], ''],
    ['acc_year_end_closures', 'closed_at', 'past', null, [], ''],
    ['acc_bank_transactions', 'matched_at', 'past', null, [], ''],
    ['acc_journal_entries', 'posted_at', 'past', null, [], ''],
    ['acc_journal_entries', 'submitted_at', 'past', null, [], ''],
    ['acc_journal_entries', 'approved_at', 'past', null, [], ''],
    ['acc_capex_requests', 'approved_at', 'past', null, [], ''],
    ['acc_capex_requests', 'completed_at', 'past', null, [], ''],
    ['acc_fx_revaluations', 'run_at', 'past', null, [], ''],
    ['acc_cca_continuity', 'computed_at', 'past', null, [], ''],
    ['acc_disclosure_notes', 'edited_at', 'past', null, [], ''],
    ['acc_depreciation_runs', 'run_date', 'past', null, [], 'asset schedule YTD bounds read it as UTC now'],
    // ── Payments / AI / QuickBooks mapping stamps ──────────────────────────────
    ['payments', 'received_at', 'past', null, [], ''],
    ['ai_chat_sessions', 'last_message_at', 'past', null, [], ''],
    ['ai_pending_changes', 'expires_at', 'past', null, [], '30-min TTL; a pre-deploy value above the cutover simply stays expired early'],
    ['ai_pending_changes', 'applied_at', 'past', null, [], ''],
    // (The MSGR-1 thread-reads stamp was dropped with its table by S-CHAT-REBUILD;
    //  conversation_reads.updated_at is a DB DEFAULT/ON UPDATE stamp, UTC already.)
    ['acc_qbo_customer_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_customer_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_customer_map', 'last_push_at', 'past', null, [], ''],
    ['acc_qbo_vendor_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_vendor_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_vendor_map', 'last_push_at', 'past', null, [], ''],
    ['acc_qbo_account_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_item_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_tax_code_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_bank_account_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_bank_account_map', 'mapped_at', 'past', null, [], ''],
    ['acc_qbo_bank_transaction_map', 'last_pulled_at', 'past', null, [], ''],
    ['acc_qbo_bank_transaction_map', 'first_seen_at', 'past', null, [], ''],
    ['acc_qbo_invoice_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_invoice_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_bill_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_bill_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_bill_payment_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_bill_payment_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_payment_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_payment_map', 'pulled_at', 'past', null, [], ''],
    ['acc_qbo_payment_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_credit_memo_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_credit_memo_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_credit_application_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_credit_application_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_refund_receipt_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_refund_receipt_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_journal_entry_map', 'pushed_at', 'past', null, [], ''],
    ['acc_qbo_journal_entry_map', 'last_synced_at', 'past', null, [], ''],
    ['acc_qbo_historical_pull_runs', 'started_at', 'past', null, [], ''],
    ['acc_qbo_historical_pull_runs', 'finished_at', 'past', null, [], ''],
];

$opts   = getopt('', ['cutover:', 'apply', 'only:']);
$cutover = (string) ($opts['cutover'] ?? '');
$apply   = array_key_exists('apply', $opts);
$only    = [];
foreach ((array) ($opts['only'] ?? []) as $o) {
    foreach (explode(',', (string) $o) as $x) {
        if (trim($x) !== '') $only[] = trim($x);
    }
}

$dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $cutover, ff_business_timezone());
if ($dt === false || $dt->format('Y-m-d H:i:s') !== $cutover) {
    fwrite(STDERR, "Usage: php scripts/migrate_local_stamps_to_utc.php --cutover=\"YYYY-MM-DD HH:MM:SS\" (local wall time of the deploy) [--apply] [--only=table.column]\n");
    exit(2);
}
if ($dt > new DateTimeImmutable('now', ff_business_timezone())) {
    fwrite(STDERR, "Refusing: cutover {$cutover} is in the future ({$dt->getTimezone()->getName()}).\n");
    exit(2);
}

echo ($apply ? 'APPLY' : 'DRY RUN') . " — local→UTC stamp repair, cutover {$cutover} (" . ff_business_timezone()->getName() . ")\n";
echo str_repeat('=', 100) . "\n";

$cutoverUtc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$named = ['cutover_local' => $cutover, 'cutover_utc' => $cutoverUtc];

$total = 0;
$exit  = 0;
foreach (REGISTRY as [$table, $column, $mode, $extraWhere, $paramNames, $note]) {
    $key = "{$table}.{$column}";
    if ($only && !in_array($key, $only, true)) {
        continue;
    }
    try {
        $params = array_map(static fn(string $n) => $named[$n], $paramNames);
        $r = LocalStampMigrator::convert($table, $column, $cutover, $extraWhere, $params, $apply, $mode !== 'future');
    } catch (Throwable $e) {
        printf("%-55s ERROR %s\n", $key, $e->getMessage());
        $exit = 1;
        continue;
    }
    printf("%-55s %-18s rows=%d  %s\n", $key, $r['status'], $r['rows'], $note);
    foreach ($r['sample'] as $s) {
        printf("%57s %s → %s\n", '', $s['before_value'], $s['after_value']);
    }
    $total += $r['rows'];
}
echo str_repeat('=', 100) . "\n";
echo ($apply ? "Converted" : "Would convert") . " {$total} value(s).\n";
exit($exit);
