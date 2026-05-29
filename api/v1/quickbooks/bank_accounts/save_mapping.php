<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bank_accounts/save_mapping.php
 *
 * Two actions:
 *   link   — assign a QBO bank account to a FF bank account
 *   unmap  — remove the mapping
 *
 * link requires: ff_bank_account_id, qbo_bank_account_id.
 * unmap requires: ff_bank_account_id.
 *
 * Mirrors the Accounts save_mapping.php JSON envelope for parity with
 * the existing admin Alpine.js client helpers.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @session S-QBO-20
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\BankAccountMatcher;

$body   = json_body();
$action = (string) ($body['action'] ?? '');
$ffId   = isset($body['ff_bank_account_id']) ? (int) $body['ff_bank_account_id'] : 0;
$qboId  = isset($body['qbo_bank_account_id']) ? (string) $body['qbo_bank_account_id'] : '';

if (!in_array($action, ['link', 'unmap'], true)) {
    json_error('VALIDATION_ERROR', 'action must be link or unmap', 422);
}
if ($ffId <= 0) {
    json_error('VALIDATION_ERROR', 'ff_bank_account_id is required', 422);
}

$userId = current_user_id();

try {
    if ($action === 'link') {
        if ($qboId === '') {
            json_error('VALIDATION_ERROR', 'qbo_bank_account_id is required for link', 422);
        }

        // Resolve the snapshot from a fresh QBO pull so we capture
        // currency/name/active at link time. Single pull per call; the
        // Matcher's pullFromQbo is the canonical source.
        $qboAccounts = BankAccountMatcher::pullFromQbo();
        $snapshot = null;
        foreach ($qboAccounts as $a) {
            if (($a['qbo_id'] ?? '') === $qboId) {
                $snapshot = $a;
                break;
            }
        }
        if ($snapshot === null) {
            json_error('NOT_FOUND', "QBO bank account {$qboId} not found in current QBO list", 404);
        }

        $result = BankAccountMatcher::assignMapping($ffId, $qboId, $snapshot, $userId);
        json_success([
            'mapping_id' => $result['mapping_id'],
            'action'     => $result['action'],
            'snapshot'   => $result['snapshot'],
        ]);
    }

    if ($action === 'unmap') {
        $ok = BankAccountMatcher::unmapping($ffId, $userId);
        if (!$ok) {
            json_error('NOT_FOUND', "FF bank account {$ffId} has no current mapping", 404);
        }
        json_success(['action' => 'unmapped', 'ff_bank_account_id' => $ffId]);
    }
} catch (\RuntimeException $e) {
    json_error('CONFLICT', $e->getMessage(), 409);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Save mapping failed: ' . $e->getMessage(), 500);
}
