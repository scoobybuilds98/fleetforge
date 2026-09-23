<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_item_account_check.php
 *
 * S-QBO-ITEM-ACCOUNT-CHECK — QuickBooks item income accounts vs the
 * accounts FleetForge books the same revenue to (audit B8).
 *
 * ItemAccountCheck::checkRow() takes an item-map row, so the checks feed it
 * synthetic rows; FF→QuickBooks account links are set inside one outer
 * transaction, rolled back in `finally`.
 *
 *   C1 matching account → ok
 *   C2 item on another account → different, both sides named
 *   C3 FF's account not linked to QuickBooks → ff_account_unmapped
 *   C4 a line type with no mapping of its own → FF's 'other' fallback flagged
 *   C5 GPS: gross → the GPS gross account; net → net account + "by design" note
 *   C6 Bad-debt item → Bad Debt Expense
 *   C7 item income account not pulled → item_account_unknown
 *   C8 checkAll counts + the Items API/page carry the check
 *
 * @session S-QBO-ITEM-ACCOUNT-CHECK
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QboPushers\ItemAccountCheck;

$pass     = 0;
$total    = 8;
$failures = [];

function ff_iac_check(string $id, string $label, array $errs): void
{
    global $pass, $failures;
    if ($errs === []) {
        echo "PASS {$id} {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$id} {$label} — " . implode('; ', $errs) . "\n";
        $failures[] = $id;
    }
}

/** Link FF account $code to QuickBooks account $qboId (inside the rolled-back txn). */
function ff_iac_link(string $code, string $qboId, string $qboName): int
{
    $acct = (int) (db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code])['id'] ?? 0);
    if ($acct === 0) {
        throw new \RuntimeException("dev account {$code} missing");
    }
    db_execute("DELETE FROM acc_qbo_account_map WHERE qbo_account_id = ?", [$qboId]);
    if (db_row("SELECT id FROM acc_qbo_account_map WHERE ff_account_id = ?", [$acct])) {
        db_execute("UPDATE acc_qbo_account_map SET qbo_account_id = ?, qbo_name = ?, mapping_status = 'mapped' WHERE ff_account_id = ?", [$qboId, $qboName, $acct]);
    } else {
        db_execute("INSERT INTO acc_qbo_account_map (ff_account_id, qbo_account_id, qbo_name, mapping_status) VALUES (?, ?, ?, 'mapped')", [$acct, $qboId, $qboName]);
    }
    return $acct;
}

function ff_iac_row(string $type, ?string $variant, ?string $qboAcct, ?string $qboAcctName = null): array
{
    return ['ff_item_type' => $type, 'ff_item_type_variant' => $variant, 'qbo_income_account_id' => $qboAcct, 'qbo_income_account_name' => $qboAcctName];
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-ITEM-ACCOUNT-CHECK smoke ({$total} sub-checks; hermetic — rolled back)\n";
echo "═══════════════════════════════════════════════════════════\n";

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`) VALUES ('accounting.revenue_account_map', ?, 'json', 'accounting')
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [json_encode(['late_fee' => '4090', 'mileage_precharge' => '4060', 'other' => '4110'])]
    );
    settings_cache_flush();
    ff_iac_link('4090', 'SMK-Q-LATE', 'Late fees');
    ff_iac_link('4110', 'SMK-Q-OTHER', 'Other income');

    // ══ C1 ══
    $r = ItemAccountCheck::checkRow(ff_iac_row('late_fee', null, 'SMK-Q-LATE', 'Late fees'));
    ff_iac_check('C1', 'item on the account linked to FF\'s revenue account → ok',
        ($r['status'] === 'ok' && !$r['fallback'] && str_contains($r['message'], '4090')) ? [] : [json_encode($r)]);

    // ══ C2 ══
    $r = ItemAccountCheck::checkRow(ff_iac_row('late_fee', null, 'SMK-Q-SALES', 'Sales'));
    ff_iac_check('C2', 'item on another account → different, both named',
        ($r['status'] === 'different' && str_contains($r['message'], '"Sales"') && str_contains($r['message'], 'Late fees') && ($r['expected_qbo_account']['id'] ?? '') === 'SMK-Q-LATE') ? [] : [json_encode($r)]);

    // ══ C3 ══
    $acct4060 = (int) db_row("SELECT id FROM acc_accounts WHERE code = '4060'")['id'];
    db_execute("UPDATE acc_qbo_account_map SET mapping_status = 'ff_only', qbo_account_id = NULL WHERE ff_account_id = ?", [$acct4060]);
    $r = ItemAccountCheck::checkRow(ff_iac_row('mileage_precharge', null, 'SMK-Q-SALES', 'Sales'));
    ff_iac_check('C3', "FF's revenue account not linked to QuickBooks → ff_account_unmapped",
        ($r['status'] === 'ff_account_unmapped' && str_contains($r['message'], '4060')) ? [] : [json_encode($r)]);

    // ══ C4 ══
    $r = ItemAccountCheck::checkRow(ff_iac_row('base_rental', null, 'SMK-Q-OTHER', 'Other income'));
    ff_iac_check('C4', "line type without its own mapping → FF's 'other' fallback flagged",
        ($r['status'] === 'ok' && $r['fallback'] === true && str_contains($r['message'], 'fallback') && ($r['ff_account']['code'] ?? '') === '4110') ? [] : [json_encode($r)]);

    // ══ C5 ══
    $e = [];
    $gross = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.gps_gross_revenue_account_id'")['value'] ?? 0);
    $net   = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.gps_net_revenue_account_id'")['value'] ?? 0);
    if ($gross === 0 || $net === 0) {
        $e[] = 'dev GPS account settings missing';
    } else {
        $rg = ItemAccountCheck::checkRow(ff_iac_row('gps', 'gross', 'X'));
        $rn = ItemAccountCheck::checkRow(ff_iac_row('gps', 'net', 'X'));
        if (($rg['ff_account']['id'] ?? 0) !== $gross || $rg['note'] !== null) { $e[] = 'gross ' . json_encode($rg); }
        if (($rn['ff_account']['id'] ?? 0) !== $net || !str_contains((string) $rn['note'], 'Samsara cost')) { $e[] = 'net ' . json_encode($rn); }
    }
    ff_iac_check('C5', 'GPS gross → GPS gross account; net → net account + "differs by design" note', $e);

    // ══ C6 ══
    $bad = (int) (db_row("SELECT `value` FROM settings WHERE `key` = 'accounting.bad_debt_expense_account_id'")['value'] ?? 0);
    $r = ItemAccountCheck::checkRow(ff_iac_row('bad_debt', null, 'X'));
    ff_iac_check('C6', 'Bad-debt item → Bad Debt Expense', (($r['ff_account']['id'] ?? 0) === $bad && $bad > 0) ? [] : [json_encode($r)]);

    // ══ C7 ══
    $r = ItemAccountCheck::checkRow(ff_iac_row('late_fee', null, null));
    ff_iac_check('C7', 'item income account not pulled → item_account_unknown', $r['status'] === 'item_account_unknown' ? [] : [json_encode($r)]);

    // ══ C8 ══
    $e = [];
    $all = ItemAccountCheck::checkAll();
    if (array_sum($all['counts']) !== count($all['rows'])) { $e[] = 'counts do not add up'; }
    foreach (['ok', 'different', 'ff_account_unmapped', 'no_ff_account', 'item_account_unknown'] as $k) {
        if (!array_key_exists($k, $all['counts'])) { $e[] = "count {$k} missing"; }
    }
    $api  = (string) file_get_contents(FF_ROOT . '/api/v1/quickbooks/items/list.php');
    $page = (string) file_get_contents(FF_ROOT . '/app/admin/quickbooks/items.php');
    if (!str_contains($api, 'ItemAccountCheck::checkRow(') || !str_contains($api, "'account_check'")) { $e[] = 'Items API does not carry the check'; }
    if (!str_contains($page, 'row.account_check') || !str_contains($page, 'accountCheck.counts')) { $e[] = 'Items page does not show it'; }
    ff_iac_check('C8', 'checkAll counts add up; Items API + page carry the check', $e);
} catch (\Throwable $fatal) {
    echo "FATAL " . get_class($fatal) . ': ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . "\n";
    $failures[] = 'FATAL';
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    settings_cache_flush();
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_item_account_check_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
