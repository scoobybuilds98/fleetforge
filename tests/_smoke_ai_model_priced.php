<?php
declare(strict_types=1);

/**
 * tests/_smoke_ai_model_priced.php
 *
 * S-AI-AUDIT-HIGH-FIX — the canonical guard against the model/pricing drift the
 * audit found: the live ai.model ('claude-sonnet-4-6') was NOT in Pricing::PRICES
 * (only the retired 'claude-sonnet-4-20250514' was), so CreditEstimator counted
 * every real call's spend as $0 and the credit tile never burned down.
 *
 * This asserts the LIVE configured model is priced. A future model bump that
 * lands an unpriced id here fails CI instead of silently zeroing the estimate.
 * Read-only — no DB writes.
 *
 * @session S-AI-AUDIT-HIGH-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\AI\Pricing;
use FleetForge\AI\ClaudeClient;

$pass = 0; $fail = 0; $fails = [];
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  PASS  {$label}\n"; }
    else { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fails[] = $label; }
}

$liveModel = (string) (settings_get('ai.model') ?: '');
echo "live ai.model = '{$liveModel}'\n\n";

ok('ai.model setting is non-empty', $liveModel !== '');
ok("live ai.model '{$liveModel}' is priced in Pricing::PRICES", Pricing::isPriced($liveModel),
    'priced models: ' . implode(', ', Pricing::pricedModels()));

// The price row must expose numeric input + output rates (bcmath-safe strings).
$row = Pricing::forModel($liveModel);
ok('priced row has numeric input+output', is_array($row)
    && isset($row['input'], $row['output'])
    && is_numeric($row['input']) && is_numeric($row['output']),
    json_encode($row));

// The ClaudeClient default constant should also resolve to a priced model so a
// cleared ai.model setting doesn't fall back to an unpriced/retired id.
$client = new ClaudeClient();
$defaultModel = $client->getModel();
ok("ClaudeClient default model '{$defaultModel}' is priced", Pricing::isPriced($defaultModel),
    'default=' . $defaultModel);

// And a deliberately bogus id must read as unpriced (proves the check has teeth).
ok('a bogus model id reads as unpriced', !Pricing::isPriced('claude-not-a-real-model'));

echo "\n" . str_repeat('─', 60) . "\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
if ($fail) { echo "FAILURES: " . implode('; ', $fails) . "\n"; }
exit($fail ? 1 : 0);
