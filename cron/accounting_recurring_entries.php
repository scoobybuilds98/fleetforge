<?php
declare(strict_types=1);

/**
 * cron/accounting_recurring_entries.php
 *
 * Daily recurring-JE cron. Intended schedule: 03:00 server time.
 *
 * Behavior:
 *   1. Advisory-lock ('ff_acct_recurring') — skip if another instance running.
 *   2. Fetch every active acc_recurring_entries row in-window for $today.
 *   3. For each: if RecurringEntryService::isDueToday() → postTemplate().
 *   4. Per-template exceptions are caught and logged; one failure does
 *      not abort the batch (STOP conditions are template-local).
 *   5. Summary audit_log row at end.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.3
 * Session:  S037-REC
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Accounting\RecurringEntryService;

$lock = db_row("SELECT GET_LOCK('ff_acct_recurring', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_recurring_entries: another instance is running — exiting.');
    exit(0);
}

$today = date('Y-m-d');
$posted  = 0;
$skipped = 0;
$failed  = 0;
$failureMessages = [];

try {
    // Resolve system user — same pattern as accounting_fx_revaluation.php
    $systemUser = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = 'super_admin' AND u.status = 'active'
          ORDER BY u.id ASC LIMIT 1"
    );
    $systemUserId = $systemUser ? (int) $systemUser['id'] : 1;

    $templates = RecurringEntryService::fetchActiveTemplates($today);
    foreach ($templates as $t) {
        if (!RecurringEntryService::isDueToday($t, $today)) {
            $skipped++;
            continue;
        }

        try {
            $result = RecurringEntryService::postTemplate($t, $today, $systemUserId);
            if ($result['created']) {
                $posted++;
                echo sprintf(
                    "POSTED  template=%d %s  ref=%s  je=%s\n",
                    (int) $t['id'],
                    $t['name'],
                    $result['je']['reference'] ?? '?',
                    $result['je']['entry_number'] ?? '?'
                );
            } else {
                $skipped++;
                echo sprintf(
                    "SKIP    template=%d %s  reason=%s\n",
                    (int) $t['id'],
                    $t['name'],
                    $result['skipped_reason'] ?? '?'
                );
            }
        } catch (\Throwable $e) {
            $failed++;
            $msg = "template=" . (int) $t['id'] . " " . $t['name'] . ": " . $e->getMessage();
            $failureMessages[] = $msg;
            error_log("cron/accounting_recurring_entries: {$msg}");
            echo "FAIL    {$msg}\n";
            // Continue to next template — one failure must not abort the batch.
        }
    }

    db_insert('audit_log', [
        'user_id'     => $systemUserId,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'recurring_entries_batch',
        'notes'       => sprintf(
            'Recurring entries cron %s: %d posted, %d skipped, %d failed.%s',
            $today,
            $posted,
            $skipped,
            $failed,
            $failed > 0 ? ' Failures: ' . implode(' | ', $failureMessages) : ''
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    echo sprintf("\nSummary %s: posted=%d skipped=%d failed=%d\n", $today, $posted, $skipped, $failed);
    db_execute("SELECT RELEASE_LOCK('ff_acct_recurring')", []);
    exit(0);
} catch (\Throwable $e) {
    error_log('cron/accounting_recurring_entries: ' . $e->getMessage());
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'     => 'cron',
            'module'     => 'accounting',
            'entity_type'=> 'recurring_entries_batch',
            'notes'      => 'Cron-level error: ' . $e->getMessage(),
            'ip_address' => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_acct_recurring')", []);
    exit(1);
}
