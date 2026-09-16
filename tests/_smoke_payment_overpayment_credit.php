<?php
declare(strict_types=1);

/**
 * tests/_smoke_payment_overpayment_credit.php
 *
 * Money contract for recording an OVERPAYMENT through the real
 * api/v1/payments/create.php endpoint (S-FIX-2 Bug #2). The Record Payment form
 * (app/admin/payments/create.php) used to block any amount above the invoice
 * balance; it now allows it and warns "Overpayment of $X will be issued as account
 * credit". That UI promise is only safe while the server keeps doing exactly this:
 *
 *   OP  CAD 1,250.00 paid on a CAD 1,000.00 sent invoice
 *       - 201; allocated 1000.00, overpayment 250.00, credit_note in the response
 *       - invoice: paid, amount_paid 1000.00, balance_due 0.00, paid_date set
 *       - one payment_allocations row for 1000.00 (never the full 1,250)
 *       - payments row: amount 1250.00, overpayment_amount 250.00,
 *         overpayment_action credit_to_account, overpayment_resolved 1
 *       - customers.outstanding_balance 1000.00 → 0.00 (reduced by the ALLOCATED
 *         amount only — Path B / D45 canonical AR)
 *       - credit_notes: source overpayment, amount = amount_remaining = 250.00,
 *         active, source_invoice_id + source_payment_id stamped
 *       - derived account credit (ff_customer_credit_sql) = 250.00
 *       - GL: one balanced JE — DR cash 1250 / CR AR 1000 / CR 2060 credits 250
 *   EX  exact-balance payment (control) — paid, NO credit note, 2-line JE
 *   PP  partial payment (control) — partially_paid, OB reduced by the payment,
 *       NO credit note
 *   CM  overage in the WRONG currency — 422 on currency, nothing written
 *
 * HERMETIC: each scenario runs the real endpoint in a CLI subprocess whose PDO
 * connection opens an outer transaction first. db_transaction() nests inside it
 * (no commit), the harness's shutdown function snapshots the resulting rows on
 * that same connection AFTER json_success()/json_error() exit, then ROLLS BACK.
 * Fixture customer/invoice, payment, credit note, JE, audit rows, notification
 * rows and the gap-free PAY-/CN- number counters are all discarded — nothing
 * persists in the dev DB (unlike the older committed-then-deleted smokes).
 *
 * Run:  php tests/_smoke_payment_overpayment_credit.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session payments-overpayment-ui (training-video bug #2a)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

$ROOT     = dirname(__DIR__);
$PID      = getmypid();
$failures = [];
$passes   = 0;

/**
 * Record one assertion.
 *
 * @param string $label  what is being checked
 * @param bool   $ok     outcome
 * @param string $detail shown on failure only
 * @return void
 */
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "  \033[32mPASS\033[0m — {$label}\n";
    } else {
        $failures[] = $label;
        echo "  \033[31mFAIL\033[0m — {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

/**
 * bcmath money equality at 2dp (tolerates null as "not equal").
 *
 * @param mixed  $actual
 * @param string $expected
 * @return bool
 */
function money_eq(mixed $actual, string $expected): bool
{
    return $actual !== null && bccomp((string) $actual, $expected, 2) === 0;
}

// ── Harness: runs the endpoint inside an outer transaction, snapshots, rolls back ──
$harnessFile = sys_get_temp_dir() . '/_ff_overpay_harness_' . $PID . '.php';
file_put_contents($harnessFile, <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
$root     = $argv[1];
$fixture  = json_decode(base64_decode($argv[2]), true);
$payload  = json_decode(base64_decode($argv[3]), true);
$sess     = json_decode(base64_decode($argv[4]), true);

require $root . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

// Outer transaction FIRST — the endpoint's db_transaction() sees inTransaction()
// and neither commits nor opens its own.
db_pdo()->beginTransaction();

$tag   = 'SMOKE-OVERPAY-' . getmypid();
$custId = db_insert('customers', [
    'company_name'        => $tag . ' Co',
    'contact_name'        => 'Overpay Smoke',
    'province'            => 'BC',
    'currency'            => $fixture['currency'],
    'outstanding_balance' => $fixture['balance'],   // Path B: SENT invoice already counted
]);
$invId = db_insert('invoices', [
    'invoice_number'        => $tag,
    'customer_id'           => $custId,
    'lease_id'              => null,
    'company_name_snapshot' => $tag . ' Co',
    'billing_period_start'  => '2026-08-01',
    'billing_period_end'    => '2026-08-31',
    'billing_period_days'   => 31,
    'billing_type'          => 'single_period',
    'invoice_date'          => '2026-09-01',
    'due_date'              => '2026-09-15',
    'sent_date'             => '2026-09-01',
    'status'                => 'sent',
    'currency'              => $fixture['currency'],
    'subtotal'              => $fixture['balance'],
    'total_amount'          => $fixture['balance'],
    'amount_paid'           => '0.00',
    'credits_applied'       => '0.00',
    'balance_due'           => $fixture['balance'],
]);

register_shutdown_function(static function () use ($custId, $invId) {
    $pdo   = db_pdo();
    $state = ['rolled_back_by_endpoint' => !$pdo->inTransaction()];
    if ($pdo->inTransaction()) {
        $state['invoice']  = db_row("SELECT status, amount_paid, balance_due, credits_applied, paid_date FROM invoices WHERE id = ?", [$invId]);
        $state['customer'] = db_row("SELECT outstanding_balance, " . ff_customer_credit_sql('c') . " AS account_credit FROM customers c WHERE c.id = ?", [$custId]);
        $state['payments'] = db_select("SELECT id, amount, currency, overpayment_amount, overpayment_action, overpayment_resolved FROM payments WHERE customer_id = ?", [$custId]);
        $state['allocations'] = db_select("SELECT payment_id, amount FROM payment_allocations WHERE invoice_id = ?", [$invId]);
        $state['credit_notes'] = db_select("SELECT source, amount, amount_remaining, currency, status, source_invoice_id, source_payment_id FROM credit_notes WHERE customer_id = ?", [$custId]);
        $pids = array_map(static fn($p) => (int) $p['id'], $state['payments']);
        $state['je_lines'] = $pids ? db_select(
            "SELECT l.account_id, l.debit, l.credit
               FROM acc_journal_entry_lines l
               JOIN acc_journal_entries je ON je.id = l.journal_entry_id
              WHERE je.source_type = 'payment' AND je.source_id IN (" . implode(',', $pids) . ")
              ORDER BY l.id",
            []
        ) : [];
        $state['accounts'] = [
            'cash'    => (int) settings_get('accounting.default_cash_account_id', 0),
            'ar'      => (int) settings_get('accounting.ar_account_id', 0),
            'credits' => (int) settings_get('accounting.customer_credits_account_id', 0),
        ];
        $state['invoice_id'] = $invId;
        $pdo->rollBack();   // ← hermetic: discard every write this run made
    }
    echo "\n@@STATE@@" . json_encode($state);
});

// Point the payload at the fixture invoice, then feed it through php://input.
$payload['invoice_id'] = $invId;
class FfOverpayInput {
    public $context; private static string $buf = ''; private int $pos = 0;
    public static function set(string $s): void { self::$buf = $s; }
    public function stream_open($p, $m, $o, &$x): bool { $this->pos = 0; return true; }
    public function stream_read($c) { $ch = substr(self::$buf, $this->pos, $c); $this->pos += strlen($ch); return $ch; }
    public function stream_eof(): bool { return $this->pos >= strlen(self::$buf); }
    public function stream_stat(): array { return []; }
    public function stream_seek($o, $w): bool { $this->pos = $o; return true; }
    public function stream_tell(): int { return $this->pos; }
}
FfOverpayInput::set(json_encode($payload));
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'FfOverpayInput');
$_SERVER['REQUEST_METHOD']    = 'POST';
$_SERVER['CONTENT_TYPE']      = 'application/json';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'smoketoken';
$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
$_SERVER['HTTP_HOST']         = 'localhost';
@session_start();
$_SESSION['csrf_token'] = 'smoketoken';
$_SESSION['ff_user']    = $sess;
require $root . '/api/v1/payments/create.php';
PHP);

/**
 * Run one scenario through the harness.
 *
 * @param array $fixture ['currency' => 'CAD', 'balance' => '1000.00']
 * @param array $payload request body (invoice_id is injected by the harness)
 * @return array{response: array, state: array, raw: string}
 */
function run_scenario(array $fixture, array $payload): array
{
    global $harnessFile, $ROOT, $adminSession;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harnessFile)
        . ' ' . escapeshellarg($ROOT)
        . ' ' . escapeshellarg(base64_encode(json_encode($fixture)))
        . ' ' . escapeshellarg(base64_encode(json_encode($payload)))
        . ' ' . escapeshellarg(base64_encode(json_encode($adminSession))) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    $state = [];
    $body  = $out;
    if (($p = strpos($out, '@@STATE@@')) !== false) {
        $state = json_decode(substr($out, $p + 9), true) ?: [];
        $body  = substr($out, 0, $p);
    }
    $s = strpos($body, '{"success"');
    $response = $s !== false ? (json_decode(trim(substr($body, $s)), true) ?: []) : [];
    return ['response' => $response, 'state' => $state, 'raw' => $out];
}

// ── Setup ────────────────────────────────────────────────────────────────────
$admin = db_row(
    "SELECT u.id, u.name FROM users u JOIN user_roles ur ON ur.id = u.role_id
      WHERE ur.slug = 'super_admin' AND u.deleted_at IS NULL AND u.status = 'active'
      ORDER BY u.id LIMIT 1",
    []
);
if (!$admin) { echo "SETUP FAIL: no active super_admin\n"; @unlink($harnessFile); exit(2); }
$adminSession = ['id' => (int) $admin['id'], 'name' => 'Overpay Smoke', 'role_slug' => 'super_admin'];

// Counter snapshot — proves the rollback also discarded the gap-free number bumps.
$year = date('Y');
$counterSql = "SELECT `key`, `value` FROM settings WHERE `key` IN (?, ?) ORDER BY `key`";
$countersBefore = db_select($counterSql, ["payment.next_number.{$year}", "credit_note.next_number.{$year}"]);
$payCountBefore = (int) db_count("SELECT COUNT(*) FROM payments", []);

$today = date('Y-m-d');
$basePayload = ['currency' => 'CAD', 'payment_method' => 'e_transfer', 'payment_date' => $today, 'reference_number' => 'SMOKE-OVERPAY'];

echo str_repeat('─', 72) . "\n";
echo "PAYMENT OVERPAYMENT → CREDIT NOTE (hermetic, real endpoint)  admin={$admin['id']}\n";
echo str_repeat('─', 72) . "\n";

try {
    // ═════════════ OP — overpayment ═════════════
    echo "\nOP  CAD 1,250.00 on a CAD 1,000.00 invoice\n";
    $r  = run_scenario(['currency' => 'CAD', 'balance' => '1000.00'], $basePayload + ['amount' => '1250.00']);
    $d  = $r['response']['data'] ?? [];
    $st = $r['state'];
    check('OP response success', ($r['response']['success'] ?? null) === true,
        'response=' . json_encode($r['response']['error'] ?? $r['response']) . ' raw=' . substr($r['raw'], 0, 400));
    check('OP response split: allocated 1000.00 / overpayment 250.00',
        money_eq($d['allocated_amount'] ?? null, '1000.00') && money_eq($d['overpayment'] ?? null, '250.00'),
        json_encode($d));
    check('OP response carries the overpayment credit note',
        ($d['credit_note']['source'] ?? null) === 'overpayment' && money_eq($d['credit_note']['amount'] ?? null, '250.00'),
        json_encode($d['credit_note'] ?? null));
    check('OP state captured inside the outer transaction', empty($st['rolled_back_by_endpoint']) && isset($st['invoice']),
        json_encode($st));
    if (isset($st['invoice'])) {
        $inv = $st['invoice'];
        check('OP invoice paid, amount_paid 1000.00, balance_due 0.00, paid_date set',
            $inv['status'] === 'paid' && money_eq($inv['amount_paid'], '1000.00') && money_eq($inv['balance_due'], '0.00') && !empty($inv['paid_date']),
            json_encode($inv));
        check('OP exactly one allocation, for the balance (1000.00) not the full payment',
            count($st['allocations']) === 1 && money_eq($st['allocations'][0]['amount'], '1000.00'),
            json_encode($st['allocations']));
        $pay = $st['payments'][0] ?? [];
        check('OP payment row: amount 1250.00, overpayment 250.00 credit_to_account, resolved',
            count($st['payments']) === 1 && money_eq($pay['amount'] ?? null, '1250.00') && money_eq($pay['overpayment_amount'] ?? null, '250.00')
                && ($pay['overpayment_action'] ?? null) === 'credit_to_account' && (int) ($pay['overpayment_resolved'] ?? 0) === 1,
            json_encode($st['payments']));
        check('OP customers.outstanding_balance 1000.00 → 0.00 (reduced by allocated amount only)',
            money_eq($st['customer']['outstanding_balance'], '0.00'), json_encode($st['customer']));
        $cn = $st['credit_notes'][0] ?? [];
        check('OP one active overpayment CN: 250.00, fully remaining, CAD, linked to invoice + payment',
            count($st['credit_notes']) === 1 && ($cn['source'] ?? null) === 'overpayment' && ($cn['status'] ?? null) === 'active'
                && money_eq($cn['amount'] ?? null, '250.00') && money_eq($cn['amount_remaining'] ?? null, '250.00')
                && ($cn['currency'] ?? null) === 'CAD'
                && (int) ($cn['source_invoice_id'] ?? 0) === (int) $st['invoice_id']
                && (int) ($cn['source_payment_id'] ?? 0) === (int) ($pay['id'] ?? -1),
            json_encode($st['credit_notes']));
        check('OP derived account credit (customer tile) = 250.00',
            money_eq($st['customer']['account_credit'], '250.00'), json_encode($st['customer']));

        // GL: balanced 3-line entry.
        $dr = '0'; $cr = '0'; $by = [];
        foreach ($st['je_lines'] as $l) {
            $dr = bcadd($dr, (string) $l['debit'], 2);
            $cr = bcadd($cr, (string) $l['credit'], 2);
            $k  = (int) $l['account_id'];
            $by[$k] = bcadd($by[$k] ?? '0', bcsub((string) $l['debit'], (string) $l['credit'], 2), 2);
        }
        $acc = $st['accounts'];
        check('OP JE balanced: DR 1250.00 = CR 1250.00', count($st['je_lines']) >= 3 && money_eq($dr, '1250.00') && money_eq($cr, '1250.00'),
            json_encode($st['je_lines']));
        check('OP JE legs: cash +1250 / AR -1000 / 2060 customer credits -250',
            money_eq($by[$acc['cash']] ?? null, '1250.00') && money_eq($by[$acc['ar']] ?? null, '-1000.00') && money_eq($by[$acc['credits']] ?? null, '-250.00'),
            json_encode(['net_by_account' => $by, 'accounts' => $acc]));
    }

    // ═════════════ EX — exact balance (control) ═════════════
    echo "\nEX  CAD 1,000.00 on a CAD 1,000.00 invoice (control)\n";
    $r  = run_scenario(['currency' => 'CAD', 'balance' => '1000.00'], $basePayload + ['amount' => '1000.00']);
    $st = $r['state'];
    check('EX success, overpayment 0.00, no credit_note in response',
        ($r['response']['success'] ?? null) === true && money_eq($r['response']['data']['overpayment'] ?? null, '0.00')
            // array_key_exists, not ??: the key must be PRESENT and null.
            && array_key_exists('credit_note', $r['response']['data'] ?? []) && $r['response']['data']['credit_note'] === null,
        json_encode($r['response']));
    check('EX invoice paid, OB 0.00, NO credit note, account credit 0.00',
        ($st['invoice']['status'] ?? null) === 'paid' && money_eq($st['customer']['outstanding_balance'] ?? null, '0.00')
            && ($st['credit_notes'] ?? ['x']) === [] && money_eq($st['customer']['account_credit'] ?? null, '0.00'),
        json_encode($st));

    // ═════════════ PP — partial (control) ═════════════
    echo "\nPP  CAD 400.00 on a CAD 1,000.00 invoice (control)\n";
    $r  = run_scenario(['currency' => 'CAD', 'balance' => '1000.00'], $basePayload + ['amount' => '400.00']);
    $st = $r['state'];
    check('PP invoice partially_paid, balance 600.00, OB 600.00, no credit note',
        ($r['response']['success'] ?? null) === true
            && ($st['invoice']['status'] ?? null) === 'partially_paid' && money_eq($st['invoice']['balance_due'] ?? null, '600.00')
            && money_eq($st['customer']['outstanding_balance'] ?? null, '600.00') && ($st['credit_notes'] ?? ['x']) === [],
        json_encode(['resp' => $r['response'], 'state' => $st]));

    // ═════════════ CM — overage in the wrong currency ═════════════
    echo "\nCM  USD 1,250.00 on a CAD 1,000.00 invoice\n";
    $r = run_scenario(['currency' => 'CAD', 'balance' => '1000.00'], array_merge($basePayload, ['amount' => '1250.00', 'currency' => 'USD']));
    check('CM rejected 422 with a currency field error (mixed-currency overpayment is never auto-credited)',
        ($r['response']['success'] ?? null) === false && isset($r['response']['error']['fields']['currency']),
        json_encode($r['response']));
    check('CM nothing written (endpoint rolled back before any payment/CN)',
        !empty($r['state']['rolled_back_by_endpoint']) || (($r['state']['payments'] ?? ['x']) === [] && ($r['state']['credit_notes'] ?? ['x']) === []),
        json_encode($r['state']));

    // ═════════════ Hermeticity ═════════════
    echo "\nHermeticity\n";
    $countersAfter = db_select($counterSql, ["payment.next_number.{$year}", "credit_note.next_number.{$year}"]);
    check('PAY-/CN- gap-free counters unchanged after 4 endpoint runs', $countersAfter === $countersBefore,
        json_encode(['before' => $countersBefore, 'after' => $countersAfter]));
    check('payments table row count unchanged', (int) db_count("SELECT COUNT(*) FROM payments", []) === $payCountBefore);
    check('no SMOKE-OVERPAY fixtures left behind',
        (int) db_count("SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE 'SMOKE-OVERPAY-%'", []) === 0
        && (int) db_count("SELECT COUNT(*) FROM customers WHERE company_name LIKE 'SMOKE-OVERPAY-%'", []) === 0);
} finally {
    @unlink($harnessFile);
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("PAYMENT OVERPAYMENT — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
