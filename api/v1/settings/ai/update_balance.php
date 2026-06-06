<?php
declare(strict_types=1);

/**
 * api/v1/settings/ai/update_balance.php
 *
 * POST — set the internal Claude credit baseline. The operator copies their
 * current balance from the Anthropic Console and submits it here; we store it
 * as ai.credit_balance and stamp ai.credit_balance_as_of = now() in UTC. The
 * "credit remaining" tile then estimates baseline − spend-since-as-of.
 *
 * This figure is an INTERNAL ESTIMATE, not authoritative billing.
 *
 * Columns: settings uses value_type / group_name (NOT type/group) — Trap 71.
 * Gate matches the AI settings card itself: settings.delete (super_admin only,
 * the same `can('settings','delete')` check that renders the card).
 *
 * @method  POST
 * @auth    Session; require_permission('settings','delete'); CSRF via bootstrap (X-CSRF-Token)
 * @body    JSON: { balance: string|number }   // USD, >= 0
 * @returns 200 { success:true, balance, as_of_utc, remaining, remaining_display }
 *
 * @depends api/bootstrap.php, lib/AI/CreditEstimator.php
 * @session S-AI-CREDIT-TILE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\AI\CreditEstimator;

require_method('POST');
require_auth_api();
require_permission('settings', 'delete');

$body = json_body();
$rawBalance = $body['balance'] ?? null;

// Normalise: strip $ , and whitespace the operator may paste from the Console.
$balanceStr = is_string($rawBalance) || is_numeric($rawBalance)
    ? trim(str_replace(['$', ',', ' '], '', (string) $rawBalance))
    : '';

if ($balanceStr === '' || !is_numeric($balanceStr)) {
    json_error('INVALID_BALANCE', 'Enter a numeric USD balance (e.g. 250.00).', 422);
}
if (bccomp($balanceStr, '0', 6) < 0) {
    json_error('INVALID_BALANCE', 'Balance cannot be negative.', 422);
}

// Canonicalise to a 2-decimal string for storage.
$balanceStr = bcadd($balanceStr, '0', 2);

// UTC stamp — stored raw UTC; the UI renders it through format_datetime().
$nowUtc = gmdate('Y-m-d H:i:s');

db_transaction(function () use ($balanceStr, $nowUtc): void {
    // Upsert both keys (the migration seeds them, but upsert keeps this robust
    // on a DB that hasn't applied the migration yet). value_type/group_name per Trap 71.
    db_execute(
        "INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `updated_by`, `updated_at`)
         VALUES ('ai.credit_balance', ?, 'decimal', 'ai', 0, 0, ?, NOW())
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()",
        [$balanceStr, current_user_id()]
    );
    db_execute(
        "INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `updated_by`, `updated_at`)
         VALUES ('ai.credit_balance_as_of', ?, 'string', 'ai', 0, 0, ?, NOW())
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()",
        [$nowUtc, current_user_id()]
    );

    $user = current_user();
    db_insert('audit_log', [
        'user_id'     => $user['id'] ?? null,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'settings',
        'entity_type' => 'ai_credit_balance',
        'notes'       => 'Claude credit baseline set to $' . $balanceStr . ' USD (as of ' . $nowUtc . ' UTC).',
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

// Recompute remaining for an immediate UI refresh.
$remaining = CreditEstimator::remaining();

json_success([
    'balance'           => $balanceStr,
    'as_of_utc'         => $nowUtc,
    'remaining'         => $remaining,
    'remaining_display' => $remaining !== null ? CreditEstimator::round2($remaining) : null,
]);
