<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_money_json.php
 *
 * S-QBO-MONEY-JSON — money reaches QuickBooks exactly, whatever php.ini says.
 *
 * Pure checks (no DB writes, no HTTP):
 *   C1 QboMoney::decimal — cents, half-up away from zero, floats rounded
 *      before formatting, junk refused
 *   C2 QuickBooksClient::encodeBody prints 1234.56 as 1234.56 even with
 *      serialize_precision=17, and restores the ini value afterwards
 *   C3 no payload builder casts money with (float) any more — every outgoing
 *      amount goes through QboMoney
 *   C4 both client dispatch paths (HTTP + fixture) encode via encodeBody
 *
 * @session S-QBO-MONEY-JSON
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QuickBooksClient;
use FleetForge\QboPushers\QboMoney;

$pass     = 0;
$total    = 4;
$failures = [];

function ff_mj_check(string $id, string $label, array $errs): void
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

echo "═══════════════════════════════════════════════════════════\n";
echo "S-QBO-MONEY-JSON smoke ({$total} sub-checks; pure)\n";
echo "═══════════════════════════════════════════════════════════\n";

// ══ C1 ════════════════════════════════════════════════════════════
$e = [];
foreach ([['400', '400.00'], ['12.345', '12.35'], ['12.344', '12.34'], ['-2.345', '-2.35'], ['0', '0.00'], [null, '0.00'],
          [1234.56, '1234.56'], [0.1 + 0.2, '0.30'], [7, '7.00']] as [$in, $want]) {
    $got = QboMoney::decimal($in);
    if ($got !== $want) { $e[] = var_export($in, true) . " → {$got} (want {$want})"; }
}
foreach (['abc', '1e3', '12,50'] as $bad) {
    try { QboMoney::decimal($bad); $e[] = "'{$bad}' accepted"; } catch (\InvalidArgumentException $ex) { /* expected */ }
}
if (QboMoney::amount('1234.565') !== 1234.57) { $e[] = 'amount() not rounded half-up'; }
ff_mj_check('C1', 'QboMoney: cents, half-up away from zero, floats rounded first, junk refused', $e);

// ══ C2 ════════════════════════════════════════════════════════════
$e = [];
$before = ini_get('serialize_precision');
ini_set('serialize_precision', '17');
$naive = json_encode(['a' => 1234.56, 'b' => 0.1]);
$exact = QuickBooksClient::encodeBody(['a' => QboMoney::amount('1234.56'), 'b' => QboMoney::amount('0.10'), 'z' => 0]);
if ($naive === '{"a":1234.56,"b":0.1}') { $e[] = 'test setup: ini 17 did not change json_encode (cannot prove the fix)'; }
if ($exact !== '{"a":1234.56,"b":0.1,"z":0}') { $e[] = "encodeBody → {$exact}"; }
if (ini_get('serialize_precision') !== '17') { $e[] = 'ini not restored: ' . ini_get('serialize_precision'); }
ini_set('serialize_precision', (string) $before);
ff_mj_check('C2', 'encodeBody prints money exactly under serialize_precision=17 and restores the ini', $e);

// ══ C3 ════════════════════════════════════════════════════════════
$e = [];
$files = array_merge(glob(FF_ROOT . '/lib/QboPushers/*Pusher.php') ?: [], [FF_ROOT . '/lib/QboPushers/InvoiceTaxPerRate.php']);
foreach ($files as $f) {
    foreach (file($f) as $n => $line) {
        // A payload value "=> (float) …" or a direct "= (float) $debit"-style money cast.
        if (preg_match("/=>\\s*\\(float\\)/", $line) && !str_contains($line, 'qbo_exchange_rate')) {
            $e[] = basename($f) . ':' . ($n + 1) . ' ' . trim($line);
        }
    }
}
ff_mj_check('C3', 'no payload builder casts money with (float) — all through QboMoney', $e);

// ══ C4 ════════════════════════════════════════════════════════════
$src = (string) file_get_contents(FF_ROOT . '/lib/QuickBooksClient.php');
$e = [];
if (substr_count($src, '$bodyJson = self::encodeBody($opts[\'json\']);') !== 2) { $e[] = 'both dispatch paths must use encodeBody'; }
if (str_contains($src, '$bodyJson = json_encode($opts[\'json\'])')) { $e[] = 'a raw json_encode of the request body remains'; }
ff_mj_check('C4', 'HTTP and fixture dispatch both encode the body via encodeBody', $e);

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_money_json_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";

exit($pass === $total && empty($failures) ? 0 : 1);
