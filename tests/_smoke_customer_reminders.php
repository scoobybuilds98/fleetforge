<?php
declare(strict_types=1);

/**
 * tests/_smoke_customer_reminders.php
 *
 * Schema-real smoke for S-CUSTOMER-NOTIFICATIONS (Settings → Customer Emails)
 * and Task 1 (stop customer compliance emails).
 *
 * Coverage:
 *   T1  Registry defaults — every reminder ships OFF; compliance_expiry OFF is
 *       Task 1 (customers no longer emailed about expiring insurance/CVI/etc.).
 *   T2  Audience predicate (pure): selected / all_except / global suppression /
 *       portal opt-out.
 *   T3  Per-document toggle resolution (compliance insurance can be silenced).
 *   H1  invoice_due_soon handler EXECUTES real SQL + deliver + logging against a
 *       seeded invoice, and dedups on re-run.
 *   H2  Global suppression list blocks a seeded customer end-to-end.
 *   H3  lease_ending_soon handler EXECUTES against a seeded active lease.
 *
 * SAFETY: everything runs inside ONE BEGIN/ROLLBACK — no committed writes. Email
 * is forced into log-mode (AWS creds blanked in-txn + in $_ENV) so NO real SES
 * mail is ever sent; sends land in logs/mail.log. The compliance cron's customer
 * branch is validated by T1/T3 (its gate) without running the full staff cron.
 *
 * Run:  php tests/_smoke_customer_reminders.php
 * Exit: 0 all pass, 1 on any failure.
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Notifications\CustomerReminders;

// Load the cron's cr_* handlers WITHOUT running its body, plus the templates.
define('FF_CUSTOMER_REMINDERS_INCLUDE', 1);
require_once __DIR__ . '/../cron/customer_reminders.php';
require_once __DIR__ . '/../lib/Email/templates/customer_reminders.php';

$pass = 0; $fail = 0; $fails = [];
function ok(bool $cond, string $label): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else       { $fail++; $fails[] = $label; echo "  FAIL  {$label}\n"; }
}

echo "customer reminders smoke (S-CUSTOMER-NOTIFICATIONS / Task 1)\n";

// ── T1: registry defaults — everything OFF, compliance OFF = Task 1 ──────────
$reg = CustomerReminders::registry();
ok(count($reg) >= 7, 'registry loaded (' . count($reg) . ' types)');
$allOff = true;
foreach (array_keys($reg) as $k) { if (CustomerReminders::config($k)['enabled']) { $allOff = false; } }
ok($allOff, 'every reminder type ships disabled');
ok(CustomerReminders::typeEnabled('compliance_expiry') === false, 'TASK 1: compliance_expiry OFF by default (no customer compliance emails)');

// ── T2: audience predicate (pure) ───────────────────────────────────────────
$sets = ['include' => [5 => true], 'exclude' => [7 => true], 'suppressed' => [9 => true]];
ok(CustomerReminders::customerAllowed('x', 5, null, $sets, ['audience_mode' => 'selected']) === true,  'selected: included customer allowed');
ok(CustomerReminders::customerAllowed('x', 6, null, $sets, ['audience_mode' => 'selected']) === false, 'selected: non-listed customer blocked');
ok(CustomerReminders::customerAllowed('x', 7, null, $sets, ['audience_mode' => 'all_except']) === false,'all_except: excluded customer blocked');
ok(CustomerReminders::customerAllowed('x', 6, null, $sets, ['audience_mode' => 'all_except']) === true, 'all_except: other customer allowed');
ok(CustomerReminders::customerAllowed('x', 9, null, $sets, ['audience_mode' => 'all']) === false,       'global suppression always blocks');
ok(CustomerReminders::customerAllowed('compliance_expiry', 6, '{"compliance_expiring":false}', $sets, ['audience_mode' => 'all']) === false, 'portal opt-out (compliance_expiring=false) blocks');
ok(CustomerReminders::customerAllowed('compliance_expiry', 6, '{"compliance_expiring":true}',  $sets, ['audience_mode' => 'all']) === true,  'portal opt-in allows');

// ── Hermetic section ────────────────────────────────────────────────────────
db_execute('START TRANSACTION');
$rolledBack = false;
try {
    // Force Mailer log-mode so NO real SES send happens: blank creds in-txn + env.
    db_execute("UPDATE settings SET `value`='' WHERE `key` IN ('aws.access_key_id','aws.secret_access_key')");
    $_ENV['AWS_ACCESS_KEY_ID'] = '';
    $_ENV['AWS_SECRET_ACCESS_KEY'] = '';
    putenv('AWS_ACCESS_KEY_ID=');
    putenv('AWS_SECRET_ACCESS_KEY=');

    // Turn the module + relevant types ON (rolled back).
    $enable = static function (string $key, string $val) {
        db_execute(
            "INSERT INTO `settings` (`key`,`value`,`value_type`,`group_name`,`updated_at`)
             VALUES (?, ?, 'boolean', 'customer_notifications', NOW())
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            [$key, $val]
        );
    };
    $enable('customer_notifications.master_enabled', '1');
    $enable('customer_notifications.invoice_due_soon.enabled', '1');
    $enable('customer_notifications.lease_ending_soon.enabled', '1');

    ok(CustomerReminders::typeEnabled('invoice_due_soon') === true, 'invoice_due_soon enabled after settings write');

    // T3: per-document toggle — silence insurance, keep CVI.
    db_execute(
        "INSERT INTO `settings` (`key`,`value`,`value_type`,`group_name`,`updated_at`)
         VALUES ('customer_notifications.compliance_expiry.docs', ?, 'json', 'customer_notifications', NOW())
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        ['{"cvi":true,"registration":true,"mvi":true,"insurance":false}']
    );
    ok(CustomerReminders::docEnabled('compliance_expiry', 'insurance') === false, 'T3: insurance document can be silenced independently');
    ok(CustomerReminders::docEnabled('compliance_expiry', 'cvi') === true,        'T3: CVI document still on');

    // Seed a customer with a deliverable email.
    $custEmail = 'smoke_cr_' . substr(md5((string) mt_rand()), 0, 8) . '@example.test';
    $cid = db_insert('customers', [
        'company_name' => 'CR Smoke Co', 'contact_name' => 'CR Tester',
        'email' => $custEmail, 'status' => 'active', 'currency' => 'CAD',
    ]);
    ok($cid > 0, 'seeded customer #' . $cid);

    // Seed an invoice due exactly lead_days out, unpaid + sent.
    $cfgDue = CustomerReminders::config('invoice_due_soon');
    $target = date('Y-m-d', strtotime('+' . (int) $cfgDue['lead_days'] . ' days'));
    $invNo  = 'INVCR' . substr((string) microtime(true), -8);
    $invId  = db_insert('invoices', [
        'invoice_number' => $invNo, 'invoice_type' => 'regular', 'customer_id' => $cid,
        'currency' => 'CAD', 'invoice_date' => date('Y-m-d'), 'due_date' => $target,
        'billing_type' => 'single_period', 'billing_period_start' => date('Y-m-d'),
        'billing_period_end' => $target, 'billing_period_days' => 30,
        'status' => 'sent', 'subtotal' => '100.00', 'total_amount' => '100.00',
        'balance_due' => '100.00',
    ]);
    ok($invId > 0, 'seeded invoice #' . $invId . ' due ' . $target);

    // H1: run the handler — expect exactly one attempt logged for this invoice.
    $r1 = cr_run_invoice_due_soon(CustomerReminders::config('invoice_due_soon'), date('Y-m-d'), '', 'CR Smoke');
    $logged = db_count(
        "SELECT COUNT(*) FROM notification_log WHERE notification_type='customer_invoice_due' AND entity_type='invoice' AND entity_id=? AND channel='email'",
        [$invId]
    );
    ok($logged === 1, 'H1: invoice_due_soon logged exactly one email attempt (got ' . $logged . ')');
    ok(($r1['sent'] ?? 0) >= 1, 'H1: handler reported a send (log-mode, no real SES) — sent=' . ($r1['sent'] ?? 0));

    // H1b: dedup — second run must not re-log.
    cr_run_invoice_due_soon(CustomerReminders::config('invoice_due_soon'), date('Y-m-d'), '', 'CR Smoke');
    $logged2 = db_count(
        "SELECT COUNT(*) FROM notification_log WHERE notification_type='customer_invoice_due' AND entity_type='invoice' AND entity_id=? AND channel='email'",
        [$invId]
    );
    ok($logged2 === 1, 'H1b: dedup — re-run did not send again (still ' . $logged2 . ')');

    // H2: global suppression blocks a fresh invoice for a suppressed customer.
    $cid2 = db_insert('customers', [
        'company_name' => 'CR Suppressed Co', 'email' => 'smoke_cr_sup_' . substr(md5((string) mt_rand()), 0, 6) . '@example.test',
        'status' => 'active', 'currency' => 'CAD',
    ]);
    $inv2 = db_insert('invoices', [
        'invoice_number' => 'INVCR2' . substr((string) microtime(true), -7), 'invoice_type' => 'regular',
        'customer_id' => $cid2, 'currency' => 'CAD', 'invoice_date' => date('Y-m-d'), 'due_date' => $target,
        'billing_type' => 'single_period', 'billing_period_start' => date('Y-m-d'),
        'billing_period_end' => $target, 'billing_period_days' => 30,
        'status' => 'sent', 'subtotal' => '50.00', 'total_amount' => '50.00', 'balance_due' => '50.00',
    ]);
    db_execute("INSERT INTO customer_notification_audience (reminder_key, customer_id, mode) VALUES ('*', ?, 'exclude')", [$cid2]);
    cr_run_invoice_due_soon(CustomerReminders::config('invoice_due_soon'), date('Y-m-d'), '', 'CR Smoke');
    $suppLogged = db_count(
        "SELECT COUNT(*) FROM notification_log WHERE notification_type='customer_invoice_due' AND entity_type='invoice' AND entity_id=?",
        [$inv2]
    );
    ok($suppLogged === 0, 'H2: do-not-email suppression blocked the send (got ' . $suppLogged . ' rows)');

    // H3: lease_ending_soon handler executes against a seeded active lease.
    $cfgLease = CustomerReminders::config('lease_ending_soon');
    $endTarget = date('Y-m-d', strtotime('+' . (int) $cfgLease['lead_days'] . ' days'));
    $leaseId = db_insert('leases', [
        'contract_number' => 'CCR' . substr((string) microtime(true), -8), 'customer_id' => $cid,
        'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => $endTarget,
        'status' => 'active', 'currency' => 'CAD',
    ]);
    $rL = cr_run_lease_ending(CustomerReminders::config('lease_ending_soon'), date('Y-m-d'), '', 'CR Smoke');
    $leaseLogged = db_count(
        "SELECT COUNT(*) FROM notification_log WHERE notification_type='customer_lease_ending' AND entity_type='lease' AND entity_id=?",
        [$leaseId]
    );
    ok($leaseLogged === 1, 'H3: lease_ending_soon logged one attempt for seeded lease (got ' . $leaseLogged . ')');

} catch (\Throwable $e) {
    $fail++; $fails[] = 'EXCEPTION: ' . $e->getMessage();
    echo "  FAIL  exception: " . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    db_execute('ROLLBACK');
    $rolledBack = true;
    echo "  (transaction rolled back — no committed writes)\n";
}

echo "\n" . ($fail === 0 ? "OK" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "Failures:\n  - " . implode("\n  - ", $fails) . "\n"; }
exit($fail === 0 ? 0 : 1);
