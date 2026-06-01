<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_payments_settings.php
 *
 * Smoke for F11 — admin QBO Payments configuration UI. Structural guards so the
 * settings page can't silently drop the payments card or its save endpoint.
 *
 *   C1 save_payments_config.php exists + lints + edit_credentials gate + validates
 *      url_ttl_minutes (1..1440)
 *   C2 settings.php hosts the payments card (savePaymentsConfig + 3 inputs)
 *   C3 settings_write_qbo round-trips quickbooks.payments.* keys
 *
 * @session F11 (S-QBO-PAYMENTS-SETTINGS-UI)
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\QuickBooksClient;

$pass = 0; $total = 3; $failures = [];
$root = dirname(__DIR__);

// C1 — endpoint.
$ep = $root . '/api/v1/quickbooks/save_payments_config.php';
$lint = is_file($ep) ? trim((string) shell_exec('php -l ' . escapeshellarg($ep) . ' 2>&1')) : 'missing';
$src = is_file($ep) ? (string) file_get_contents($ep) : '';
$c1 = is_file($ep) && strpos($lint, 'No syntax errors') !== false
   && strpos($src, "require_permission('quickbooks', 'edit_credentials')") !== false
   && strpos($src, '1440') !== false
   && strpos($src, 'payments.success_url') !== false;
if ($c1) { echo "PASS C1 save_payments_config endpoint (gate + validation + keys)\n"; $pass++; }
else { echo "FAIL C1 lint={$lint}\n"; $failures[] = 'C1'; }

// C2 — settings page wiring.
$ss = (string) file_get_contents($root . '/app/admin/quickbooks/settings.php');
$c2 = strpos($ss, 'savePaymentsConfig') !== false
   && strpos($ss, "x-model=\"payments.success_url\"") !== false
   && strpos($ss, "x-model=\"payments.cancel_url\"") !== false
   && strpos($ss, "x-model=\"payments.url_ttl_minutes\"") !== false
   && strpos($ss, 'save_payments_config.php') !== false;
if ($c2) { echo "PASS C2 settings.php hosts the QBO Payments card\n"; $pass++; }
else { echo "FAIL C2 settings page missing payments card wiring\n"; $failures[] = 'C2'; }

// C3 — settings_write_qbo round-trip (restore after).
$before = settings_get('quickbooks.payments.url_ttl_minutes', '30');
try {
    QuickBooksClient::settings_write_qbo('payments.url_ttl_minutes', '47');
    $read = settings_get('quickbooks.payments.url_ttl_minutes', '?');
    QuickBooksClient::settings_write_qbo('payments.url_ttl_minutes', (string) $before);
    $restored = settings_get('quickbooks.payments.url_ttl_minutes', '?');
    if ($read === '47' && $restored === (string) $before) { echo "PASS C3 settings_write_qbo round-trips quickbooks.payments.*\n"; $pass++; }
    else { echo "FAIL C3 read={$read} restored={$restored}\n"; $failures[] = 'C3'; }
} catch (\Throwable $e) {
    echo "FAIL C3 " . $e->getMessage() . "\n"; $failures[] = 'C3';
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "qbo_payments_settings_smoke: {$pass}/{$total} " . ($pass === $total ? 'PASS' : 'FAIL') . "\n";
if (!empty($failures)) { echo "Failed: " . implode(', ', $failures) . "\n"; }
echo "═══════════════════════════════════════════════════════════\n";
exit($pass === $total ? 0 : 1);
