<?php
declare(strict_types=1);

/**
 * cron/accounting_lease_ni_reclass.php
 *
 * Monthly cron — first day of each month at 05:00 server time.
 *
 * Walks every active capital lease (sales_type, direct_financing) and
 * posts the ASPE 3065.54 current/long-term NI reclass JE: principal
 * scheduled within the next 12 months moves from 1600 NI Long-Term
 * to 1090 NI Current. Per-lease deltas are computed by
 * LeaseNiReclassService::computeBalancesForLease (JE-trail-based — no
 * separate per-lease ledger). The reclass JE reference includes
 * YYYY-MM so re-running within a month is a no-op (idempotent per
 * D-LESSOR-5-RECLASS-IDEMPOTENT).
 *
 * Per-lease failures are caught and logged; one failure does NOT
 * abort the batch. Lessor module disabled (lessor_module_enabled='0')
 * short-circuits the entire cron with a single log line.
 *
 * Crontab: `0 5 1 * * php /var/www/fleetforge/cron/accounting_lease_ni_reclass.php`
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.6
 * Session:  S-ACCT-LESSOR-5
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\LeaseNiReclassService;

$lock = db_row("SELECT GET_LOCK('ff_lease_ni_reclass_cron', 10) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    error_log('cron/accounting_lease_ni_reclass: another instance is running — exiting.');
    exit(0);
}

$today        = date('Y-m-d');
$asOfMonth    = date('Y-m');

try {
    // Lessor module gate — skip entirely if disabled. Logged so the
    // operator knows the cron fired but had nothing to do.
    $enabled = (string) AccountingService::setting('accounting.lessor_module_enabled', '0');
    if ($enabled !== '1') {
        echo "Lessor module disabled — NI reclass cron skipped.\n";
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'accounting',
            'entity_type' => 'lease_ni_reclass_batch',
            'notes'       => "Lease NI reclass cron {$asOfMonth}: SKIPPED (lessor_module_enabled='0').",
            'ip_address'  => '127.0.0.1',
        ]);
        db_execute("SELECT RELEASE_LOCK('ff_lease_ni_reclass_cron')", []);
        exit(0);
    }

    // System user = oldest active super_admin (same pattern as
    // accounting_auto_reverse.php and accounting_recurring_entries.php).
    $systemUser = db_row(
        "SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = 'super_admin' AND u.status = 'active'
          ORDER BY u.id ASC LIMIT 1"
    );
    $systemUserId = $systemUser ? (int) $systemUser['id'] : 1;

    $results = LeaseNiReclassService::reclassAllActive($systemUserId, $asOfMonth);

    echo sprintf(
        "Lease NI reclass cron %s: processed=%d posted=%d skipped=%d failed=%d\n",
        $asOfMonth, $results['processed'], $results['posted'],
        $results['skipped'], $results['failed']
    );
    if (!empty($results['errors'])) {
        echo "Failures:\n";
        foreach ($results['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }

    db_insert('audit_log', [
        'user_id'     => $systemUserId,
        'user_name'   => 'system',
        'action'      => 'cron',
        'module'      => 'accounting',
        'entity_type' => 'lease_ni_reclass_batch',
        'notes'       => sprintf(
            'Lease NI reclass cron %s: processed=%d posted=%d skipped=%d failed=%d.%s',
            $asOfMonth, $results['processed'], $results['posted'],
            $results['skipped'], $results['failed'],
            $results['failed'] > 0 ? ' Failures: ' . implode(' | ', $results['errors']) : ''
        ),
        'ip_address'  => '127.0.0.1',
    ]);

    db_execute("SELECT RELEASE_LOCK('ff_lease_ni_reclass_cron')", []);
    exit($results['failed'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    error_log('cron/accounting_lease_ni_reclass: ' . $e->getMessage());
    if (class_exists('\FleetForge\Observability\Sentry')) {
        \FleetForge\Observability\Sentry::captureException($e);
    }
    try {
        db_insert('audit_log', [
            'user_id'     => null,
            'user_name'   => 'system',
            'action'      => 'cron',
            'module'      => 'accounting',
            'entity_type' => 'lease_ni_reclass_batch',
            'notes'       => 'Cron-level error: ' . $e->getMessage(),
            'ip_address'  => '127.0.0.1',
        ]);
    } catch (\Throwable) { /* non-fatal */ }
    db_execute("SELECT RELEASE_LOCK('ff_lease_ni_reclass_cron')", []);
    exit(1);
}
