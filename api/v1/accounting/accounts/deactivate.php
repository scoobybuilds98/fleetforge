<?php declare(strict_types=1);

/**
 * api/v1/accounting/accounts/deactivate.php
 *
 * Soft-deactivate a chart-of-accounts entry (sets is_active = 0).
 * System accounts cannot be deactivated. Accounts with a non-zero balance
 * must be zeroed out via journal entries before deactivation.
 *
 * Dependencies: bootstrap.php, AccountingService (balance check)
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('chart_of_accounts','edit')
 * @returns 200 { id, is_active: 0 }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md SS3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('chart_of_accounts', 'edit');

use FleetForge\Accounting\AccountingService;

$body = json_body();

// -----------------------------------------------------------------------
// 1. Validate required ID
// -----------------------------------------------------------------------
$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Load existing account
// -----------------------------------------------------------------------
$account = db_row(
    "SELECT id, code, name, is_system, is_active, is_header
     FROM acc_accounts WHERE id = ?",
    [$id]
);
if (!$account) {
    json_error('NOT_FOUND', 'Account not found.', 404);
}

// Already deactivated — idempotent, just return success
if ((int) $account['is_active'] === 0) {
    json_success(['id' => $id, 'is_active' => 0]);
}

// -----------------------------------------------------------------------
// 3. Business rule: system accounts cannot be deactivated
// -----------------------------------------------------------------------
if ((int) $account['is_system'] === 1) {
    json_error(
        'SYSTEM_ACCOUNT',
        "Account '{$account['code']} — {$account['name']}' is a system account and cannot be deactivated.",
        422
    );
}

// -----------------------------------------------------------------------
// 4. Business rule: cannot deactivate if account has a non-zero balance
//    WHY: Deactivating an account with a balance would orphan money in the GL.
//    The user must zero it out with a transfer journal entry first.
// -----------------------------------------------------------------------
if ((int) $account['is_header'] !== 1) {
    $balance = AccountingService::accountBalance((int) $account['id']);
    if (bccomp($balance, '0.00', 2) !== 0) {
        json_error(
            'NON_ZERO_BALANCE',
            "Account '{$account['code']}' has a balance of {$balance}. Transfer the balance to another account before deactivating.",
            422
        );
    }
}

// -----------------------------------------------------------------------
// 5. Deactivate inside transaction (update + audit log)
// -----------------------------------------------------------------------
$now = date('Y-m-d H:i:s');

db_transaction(function () use ($id, $account, $now) {
    db_update('acc_accounts', [
        'is_active'  => 0,
        'updated_at' => $now,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'status_change',
        'module'       => 'accounting',
        'entity_type'  => 'account',
        'entity_id'    => $id,
        'entity_label' => $account['code'] . ' — ' . $account['name'],
        'notes'        => "Deactivated chart of accounts entry: {$account['code']} ({$account['name']}).",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'is_active' => 0]);
