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
 *       T1b: with the settings rows absent (fresh install) the engine falls
 *       back to those defaults, and honours portal opt-outs.
 *   T2  Audience predicate (pure): selected / all_except / global suppression.
 *   T2b Portal opt-out, with "Honor portal opt-outs" PINNED on in-txn: the
 *       compliance_expiring=false preference blocks; the switch off lets it
 *       through (that switch is the operator's lever, by design).
 *   T2c Schema-real round-trip: a preference written the way the portal page
 *       writes it, read back from portal_users the way the senders read it.
 *   T3  Per-document toggle resolution (registration can be silenced; a retired
 *       document left in a stale saved map never switches back on).
 *   H1  invoice_due_soon handler EXECUTES real SQL + deliver + logging against a
 *       seeded invoice, and dedups on re-run.
 *   H2  Global suppression list blocks a seeded customer end-to-end.
 *   H3  lease_ending_soon handler EXECUTES against a seeded active lease.
 *
 * HERMETIC: nothing asserted here may depend on the dev DB's live Customer
 * Emails settings. Dev is loaded from a production dump, and production saved
 * the page with every switch off (incl. respect_portal_optout) on 2026-08-11 —
 * the portal opt-out check used to read that live switch and failed (19/20).
 * Every setting an assertion depends on is written inside the transaction.
 *
 * SAFETY: everything runs inside ONE BEGIN/ROLLBACK — no committed writes. Email
 * is forced into log-mode (AWS creds blanked in-txn + in $_ENV) so NO real SES
 * mail is ever sent; sends land in logs/mail.log. The compliance cron's customer
 * branch is validated by T1/T3 (its gate) without running the full staff cron.
 *
 * Run:  php tests/_smoke_customer_reminders.php
 * Exit: 0 all pass, 1 on any failure.
 *
 * @session S-CUSTOMER-NOTIFICATIONS (hermetic pass: S-REMINDERS-SMOKE-HERMETIC)
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
// Read the REGISTRY, not config(): config() resolves the live settings rows,
// which on dev are whatever production last saved. T1b checks the fallback.
$reg = CustomerReminders::registry();
ok(count($reg) >= 7, 'registry loaded (' . count($reg) . ' types)');
$allOff = true;
foreach ($reg as $meta) { if ((string) ($meta['default_enabled'] ?? '0') !== '0') { $allOff = false; } }
ok($allOff, 'every reminder type ships disabled');
ok((string) ($reg['compliance_expiry']['default_enabled'] ?? '') === '0', 'TASK 1: compliance_expiry OFF by default (no customer compliance emails)');

// ── T2: audience predicate (pure) ───────────────────────────────────────────
// These never reach the portal-pref branch (prefs null), so no setting matters.
$sets = ['include' => [5 => true], 'exclude' => [7 => true], 'suppressed' => [9 => true]];
ok(CustomerReminders::customerAllowed('x', 5, null, $sets, ['audience_mode' => 'selected']) === true,  'selected: included customer allowed');
ok(CustomerReminders::customerAllowed('x', 6, null, $sets, ['audience_mode' => 'selected']) === false, 'selected: non-listed customer blocked');
ok(CustomerReminders::customerAllowed('x', 7, null, $sets, ['audience_mode' => 'all_except']) === false,'all_except: excluded customer blocked');
ok(CustomerReminders::customerAllowed('x', 6, null, $sets, ['audience_mode' => 'all_except']) === true, 'all_except: other customer allowed');
ok(CustomerReminders::customerAllowed('x', 9, null, $sets, ['audience_mode' => 'all']) === false,       'global suppression always blocks');

// ── Hermetic section ────────────────────────────────────────────────────────
db_execute('START TRANSACTION');
$rolledBack = false;
try {
    // T1b: fresh-install fallback — with no customer_notifications.* rows the
    // engine must resolve every type OFF and honour portal opt-outs.
    db_execute("DELETE FROM settings WHERE `key` LIKE 'customer_notifications.%'");
    $fallbackOff = true;
    foreach (array_keys($reg) as $k) { if (CustomerReminders::config($k)['enabled']) { $fallbackOff = false; } }
    ok($fallbackOff, 'T1b: settings rows absent → every type resolves OFF');
    ok(CustomerReminders::respectPortalOptOut() === true, 'T1b: settings rows absent → portal opt-outs honoured');

    // T2b: portal opt-out. Pin "Honor portal opt-outs" ON — it is a global the
    // operator can turn off, and production has it off.
    $pinOptOut = static function (string $val) {
        db_execute(
            "INSERT INTO `settings` (`key`,`value`,`value_type`,`group_name`,`updated_at`)
             VALUES ('customer_notifications.respect_portal_optout', ?, 'boolean', 'customer_notifications', NOW())
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            [$val]
        );
    };
    $pinOptOut('1');
    ok(CustomerReminders::customerAllowed('compliance_expiry', 6, '{"compliance_expiring":false}', $sets, ['audience_mode' => 'all']) === false, 'portal opt-out (compliance_expiring=false) blocks');
    ok(CustomerReminders::customerAllowed('compliance_expiry', 6, '{"compliance_expiring":true}',  $sets, ['audience_mode' => 'all']) === true,  'portal opt-in allows');
    $pinOptOut('0');
    ok(CustomerReminders::customerAllowed('compliance_expiry', 6, '{"compliance_expiring":false}', $sets, ['audience_mode' => 'all']) === true, 'T2b: "Honor portal opt-outs" off → opt-out ignored (operator lever)');
    $pinOptOut('1');

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

    // T3: per-document toggle — silence registration, keep CVI. The map also
    // carries the retired mvi/insurance slugs switched ON, the shape production
    // still stores; config() must drop them. (Insurance is retired, so it can no
    // longer prove the toggle works: it reads OFF whatever the saved value.)
    db_execute(
        "INSERT INTO `settings` (`key`,`value`,`value_type`,`group_name`,`updated_at`)
         VALUES ('customer_notifications.compliance_expiry.docs', ?, 'json', 'customer_notifications', NOW())
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        ['{"cvi":true,"registration":false,"mvi":true,"insurance":true}']
    );
    ok(CustomerReminders::docEnabled('compliance_expiry', 'registration') === false, 'T3: registration document can be silenced independently');
    ok(CustomerReminders::docEnabled('compliance_expiry', 'cvi') === true,           'T3: CVI document still on');
    ok(CustomerReminders::docEnabled('compliance_expiry', 'insurance') === false,    'T3: retired insurance slug in a stale saved map stays off');

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

    // T2c: schema-real portal round-trip, on its own customer so H1/H3's
    // recipient resolution is untouched. Write the preference exactly as
    // app/portal/account/index.php does (json_encode of a PHP bool into the JSON
    // column), read it back with cron/compliance_alerts.php's JOIN, and gate it.
    // Catches a column rename, a key rename, or MySQL JSON normalisation
    // breaking the `=== false` match.
    $cid3 = db_insert('customers', [
        'company_name' => 'CR OptOut Co', 'email' => 'smoke_cr_opt_' . substr(md5((string) mt_rand()), 0, 6) . '@example.test',
        'status' => 'active', 'currency' => 'CAD',
    ]);
    $puId = db_insert('portal_users', [
        'customer_id' => $cid3, 'name' => 'CR OptOut', 'status' => 'active', 'is_primary' => 1,
        'email' => 'smoke_cr_pu_' . substr(md5((string) mt_rand()), 0, 6) . '@example.test',
        'notification_preferences' => json_encode(['compliance_expiring' => false]),
    ]);
    $readPrefs = static fn(int $cust): ?string => db_row(
        "SELECT pu.notification_preferences
           FROM customers c
           LEFT JOIN portal_users pu
                  ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE c.id = ?",
        [$cust]
    )['notification_preferences'] ?? null;
    $ccfg = CustomerReminders::config('compliance_expiry');
    $ccAudience = CustomerReminders::audienceSets('compliance_expiry');
    $stored = $readPrefs($cid3);
    ok(CustomerReminders::customerAllowed('compliance_expiry', $cid3, $stored, $ccAudience, $ccfg) === false,
        'T2c: portal-written opt-out read back from portal_users blocks (stored ' . var_export($stored, true) . ')');
    db_update('portal_users', ['notification_preferences' => json_encode(['compliance_expiring' => true])], 'id = ?', [$puId]);
    ok(CustomerReminders::customerAllowed('compliance_expiry', $cid3, $readPrefs($cid3), $ccAudience, $ccfg) === true,
        'T2c: re-ticking the portal box lets the reminder through');

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
