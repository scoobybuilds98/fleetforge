<?php
declare(strict_types=1);

/**
 * tests/_smoke_fx_revaluation_liability_sign.php
 *
 * MEDIUM [01c] — FX revaluation sign for credit-normal (liability) USD accounts.
 *
 * FxRevaluationService booked a positive delta (cad_revalued − cad_book) as a
 * gain that DEBITED the account for EVERY account. For a credit-normal USD
 * liability a rising rate means you owe MORE CAD — a LOSS that should CREDIT
 * (increase) the liability. The old code reduced the liability and recorded a
 * gain — backwards on both P&L and balance sheet. Fixed: gain/loss + DR/CR are
 * now normal-balance-aware.
 *
 * Scenario: USD liability, 10,000 USD; CAD book 13,000 (rate 1.30). Revalue at
 * 1.40 → cad_revalued 14,000, delta +1,000. Correct: DR FX Loss 1,000 / CR the
 * liability 1,000 (liability rises to 14,000-equiv; a loss is booked).
 *
 * Drives the REAL FxRevaluationService::post(); the revaluation JE +
 * acc_fx_revaluations row + the seeding JE + test accounts are all removed in
 * finally, and accounting.fx_revaluation_enabled is restored.
 *
 * PRE-FIX  : the liability is DEBITED (gain) → FAIL.
 * POST-FIX : the liability is CREDITED (loss).
 *
 * Run:  php tests/_smoke_fx_revaluation_liability_sign.php   Exit 0/1/2.
 *
 * @session MEDIUM-01c-FX-LIABILITY-SIGN
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\FxRevaluationService;
use FleetForge\Accounting\JournalEntryService;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$PERIOD = 18; // June 2026 (open), no existing revaluation

$liabId = null; $ctrId = null; $seedJe = null; $settingExisted = null; $settingOld = null;

$cleanup = static function () use (&$liabId, &$ctrId, &$seedJe, &$settingExisted, &$settingOld, $PERIOD) {
    // Revaluation JE(s) + acc_fx_revaluations rows created for this period.
    foreach (db_select("SELECT id, journal_entry_id FROM acc_fx_revaluations WHERE period_id = ?", [$PERIOD]) as $rv) {
        $jeId = (int) ($rv['journal_entry_id'] ?? 0);
        if ($jeId) {
            // any auto-reverse child first
            foreach (db_select("SELECT id FROM acc_journal_entries WHERE reversal_of_id = ?", [$jeId]) as $rev) {
                db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [(int) $rev['id']]);
                db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [(int) $rev['id']]);
            }
            db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$jeId]);
            db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [$jeId]);
        }
        db_execute("DELETE FROM acc_fx_revaluations WHERE id = ?", [(int) $rv['id']]);
    }
    if ($seedJe) {
        db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$seedJe]);
        db_execute("DELETE FROM acc_journal_entries WHERE id = ?", [$seedJe]);
    }
    foreach ([$liabId, $ctrId] as $aid) { if ($aid) db_execute("DELETE FROM acc_accounts WHERE id = ?", [$aid]); }
    if ($settingExisted === true) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = 'accounting.fx_revaluation_enabled'", [$settingOld]);
    } elseif ($settingExisted === false) {
        db_execute("DELETE FROM settings WHERE `key` = 'accounting.fx_revaluation_enabled'");
    }
};

try {
    $admin = (int) (db_row("SELECT u.id FROM users u JOIN user_roles ur ON ur.id=u.role_id WHERE ur.slug='super_admin' AND u.deleted_at IS NULL LIMIT 1")['id'] ?? 0);
    if (!$admin) { echo "SETUP FAIL no super_admin\n"; exit(2); }

    // Enable FX revaluation (snapshot to restore).
    $cur = db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.fx_revaluation_enabled'");
    if ($cur !== null) { $settingExisted = true; $settingOld = $cur['value'];
        db_execute("UPDATE settings SET `value` = '1' WHERE `key` = 'accounting.fx_revaluation_enabled'");
    } else { $settingExisted = false;
        db_insert('settings', ['key' => 'accounting.fx_revaluation_enabled', 'value' => '1', 'value_type' => 'boolean', 'group_name' => 'accounting']);
    }

    // USD liability (revalued) + a plain CAD counterpart (not fx-monetary).
    $liabId = db_insert('acc_accounts', ['code' => "ZZLIAB{$PID}", 'name' => "FX Liab {$PID}", 'account_type' => 'liability', 'normal_balance' => 'credit', 'currency' => 'USD', 'is_fx_monetary' => 1, 'is_active' => 1, 'is_header' => 0]);
    $ctrId  = db_insert('acc_accounts', ['code' => "ZZCTR{$PID}",  'name' => "FX Ctr {$PID}",  'account_type' => 'asset',     'normal_balance' => 'debit',  'currency' => 'CAD', 'is_fx_monetary' => 0, 'is_active' => 1, 'is_header' => 0]);

    // Seed: DR counterpart 13000 / CR liability 13000 (USD 10000 @ 1.30) → liability CAD book 13000.
    $je = JournalEntryService::create([
        'entry_date' => '2026-06-15', 'description' => "FX liab seed {$PID}", 'entry_type' => 'system',
        'reference' => "FXSEED-{$PID}", 'post_immediately' => true,
    ], [
        ['account_id' => $ctrId,  'debit' => '13000.00', 'credit' => '0.00', 'description' => 'ctr'],
        ['account_id' => $liabId, 'debit' => '0.00', 'credit' => '13000.00', 'description' => 'usd liab',
         'foreign_amount' => '10000.00', 'foreign_currency' => 'USD', 'exchange_rate' => '1.300000'],
    ], $admin);
    $seedJe = (int) $je['id'];

    echo str_repeat('─', 72) . "\n";
    echo "MEDIUM [01c] FX REVALUATION LIABILITY SIGN — USD liab #{$liabId} (10k @1.30→1.40)\n";
    echo str_repeat('─', 72) . "\n";

    FxRevaluationService::post($PERIOD, '1.40', $admin);

    // Find the revaluation JE and the line on our liability.
    $rv = db_row("SELECT journal_entry_id FROM acc_fx_revaluations WHERE period_id = ? ORDER BY id DESC LIMIT 1", [$PERIOD]);
    $jeId = (int) ($rv['journal_entry_id'] ?? 0);
    $line = $jeId ? db_row("SELECT debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ? AND account_id = ?", [$jeId, $liabId]) : null;

    if ($line === null) {
        $fail("no revaluation line booked for the USD liability (je={$jeId})");
    } elseif (bccomp((string) $line['credit'], '1000.00', 2) === 0 && bccomp((string) $line['debit'], '0', 2) === 0) {
        $pass("liability CREDITED 1000.00 (loss; owes more CAD) — correct sign + direction");
    } else {
        $fail("liability mis-booked: debit={$line['debit']} credit={$line['credit']} (expected CR 1000.00; pre-fix DR 1000.00 = inverted gain)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    $cleanup();
    echo "  removed revaluation JE + fx_revaluations + seed JE + accounts; restored setting\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("FX REVALUATION LIABILITY SIGN — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
