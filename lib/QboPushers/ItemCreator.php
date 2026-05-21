<?php
declare(strict_types=1);

/**
 * lib/QboPushers/ItemCreator.php
 *
 * Operator-gated QBO Item authoring. Different from the regular Pusher
 * surface (CustomerPusher, VendorPusher) in two ways per D-QBO-10-3
 * and D-QBO-10-4:
 *
 *   1. NOT a Pusher in the dispatcher sense — Items are reference data
 *      (created once during S-QBO-10 mapping phase + then static).
 *      There is no pushCreate/pushUpdate contract here; no enqueuing;
 *      no acc_qbo_sync_queue interaction. The single entry point
 *      createMissingItem() is called synchronously from
 *      api/v1/quickbooks/items/create_qbo_item.php in response to an
 *      explicit operator click in the mapping UI.
 *
 *   2. Authors NEW QBO Items rather than mirroring an FF row. The
 *      "FF source" is just an item_type ENUM value + optional variant
 *      — no FF row exists. The created QBO Item's Id is upserted into
 *      acc_qbo_item_map with match_confidence='auto_created' to
 *      preserve provenance ("FF authored this Item on 2026-05-22").
 *
 * IncomeAccountRef resolution cascade (D-QBO-10-3):
 *   1. operator override (passed as 4th arg to createMissingItem)
 *   2. specific revenue account from S-QBO-8 mapping per item_type
 *      semantics (currently a flat resolution — every revenue
 *      item_type routes to FF code 4122 'Sales Revenue —
 *      Lease-to-Own' if mapped; non-revenue item_types covered below)
 *   3. ANY mapped revenue account (account_type='revenue') as a
 *      fallback so the UI can still author Items if the S-QBO-8
 *      live-verification critical-mapping is incomplete
 *   4. throw ChartOfAccountsIncompleteException pointing the operator
 *      back to S-QBO-8 if no revenue account is mapped at all
 *
 * Special-case account routing (D-QBO-10-3):
 *   - prepayment    → liability account (not relevant — not in 17
 *                     ENUM; reserved for future)
 *   - discount      → contra-revenue (currently same revenue acct;
 *                     QBO negative-amount line handles directionality)
 *   - account_credit_applied → liability/clearing account (deferred to
 *                     S-QBO-11 negotiation; for now same revenue +
 *                     QBO Type=Service since no separate ENUM)
 *
 * @session  S-QBO-10
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.3
 * @decision D-QBO-10-3 (createEntity('item', ...); Type=Service),
 *           D-QBO-10-4 (operator confirmation gate)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\ChartOfAccountsIncompleteException;

class ItemCreator
{
    /**
     * Create a QBO Item for the given FF (item_type, variant) tuple.
     * Operator-confirmed action (D-QBO-10-4) — the caller endpoint is
     * gated by `quickbooks.edit_credentials`.
     *
     * @param string  $itemType                FF item_type ENUM value
     * @param ?string $variant                 D-QBO-10-2 GPS variant ('net'|'gross') or NULL
     * @param ?string $overrideIncomeAccountId Operator override; if NULL, falls back to resolution cascade
     * @return array{
     *     success: bool,
     *     qbo_id: ?string,
     *     qbo_name: ?string,
     *     sync_token: ?string,
     *     income_account_id: ?string,
     *     income_account_name: ?string,
     *     error: ?string
     * }
     * @throws ChartOfAccountsIncompleteException if no revenue account mapped
     */
    public static function createMissingItem(
        string $itemType,
        ?string $variant = null,
        ?string $overrideIncomeAccountId = null
    ): array {
        $displayName = ItemMatcher::displayNameFor($itemType, $variant);

        // Resolve IncomeAccountRef per cascade (D-QBO-10-3).
        $incomeAccount = self::resolveIncomeAccount($itemType, $overrideIncomeAccountId);
        // resolveIncomeAccount throws ChartOfAccountsIncompleteException
        // on total absence of mapped revenue accounts — bubble up.

        // QBO Item payload. Type='Service' for every FF item_type
        // per D-QBO-10-3. Description preserves the FF item_type for
        // operator drill-down clarity.
        $payload = [
            'Name'             => $displayName,
            'Type'             => 'Service',
            'Description'      => 'FleetForge ' . $itemType
                                . ($variant !== null ? ' (' . $variant . ')' : '')
                                . ' line item. Created by FleetForge mapping flow (S-QBO-10).',
            'IncomeAccountRef' => [
                'value' => $incomeAccount['qbo_id'],
                'name'  => $incomeAccount['qbo_name'],
            ],
            'Taxable'          => true,
        ];

        try {
            $client   = new QuickBooksClient();
            $response = $client->createEntity('item', $payload);

            // QBO returns the created entity at $response['Item'].
            $item = $response['Item'] ?? [];
            if (empty($item['Id'])) {
                return [
                    'success'             => false,
                    'qbo_id'              => null,
                    'qbo_name'            => null,
                    'sync_token'          => null,
                    'income_account_id'   => null,
                    'income_account_name' => null,
                    'error'               => 'QBO response missing Item.Id',
                ];
            }

            return [
                'success'             => true,
                'qbo_id'              => (string) $item['Id'],
                'qbo_name'            => (string) ($item['Name'] ?? $displayName),
                'sync_token'          => (string) ($item['SyncToken'] ?? '0'),
                'income_account_id'   => $incomeAccount['qbo_id'],
                'income_account_name' => $incomeAccount['qbo_name'],
                'error'               => null,
            ];
        } catch (ChartOfAccountsIncompleteException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return [
                'success'             => false,
                'qbo_id'              => null,
                'qbo_name'            => null,
                'sync_token'          => null,
                'income_account_id'   => null,
                'income_account_name' => null,
                'error'               => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve the QBO IncomeAccountRef for the given FF item_type.
     * Public-static so the smoke can exercise the cascade without
     * going through createEntity.
     *
     * @return array{qbo_id: string, qbo_name: string}
     * @throws ChartOfAccountsIncompleteException
     */
    public static function resolveIncomeAccount(
        string $itemType,
        ?string $overrideIncomeAccountId = null
    ): array {
        // Pass 1: operator override.
        if ($overrideIncomeAccountId !== null && $overrideIncomeAccountId !== '') {
            $row = db_row(
                "SELECT qbo_account_id, qbo_name
                   FROM acc_qbo_account_map
                  WHERE qbo_account_id = ?
                    AND mapping_status = 'mapped'",
                [$overrideIncomeAccountId]
            );
            if ($row) {
                return [
                    'qbo_id'   => (string) $row['qbo_account_id'],
                    'qbo_name' => (string) ($row['qbo_name'] ?? ''),
                ];
            }
            // override id didn't resolve — fall through to cascade
            // rather than failing hard; operator can re-author with
            // a different override
        }

        // Pass 2: critical revenue account from S-QBO-8 (the canonical
        // 4122 Sales Revenue — Lease-to-Own).
        $crit = db_row(
            "SELECT m.qbo_account_id, m.qbo_name
               FROM acc_qbo_account_map m
               JOIN acc_accounts acc ON acc.id = m.ff_account_id
              WHERE m.mapping_status = 'mapped'
                AND m.is_critical    = 1
                AND acc.account_type = 'revenue'
              ORDER BY acc.code
              LIMIT 1"
        );
        if ($crit) {
            return [
                'qbo_id'   => (string) $crit['qbo_account_id'],
                'qbo_name' => (string) ($crit['qbo_name'] ?? ''),
            ];
        }

        // Pass 3: ANY mapped revenue account (fallback when S-QBO-8
        // critical-mapping live verification is incomplete; lets the
        // operator still author Items rather than blocking the flow).
        $any = db_row(
            "SELECT m.qbo_account_id, m.qbo_name
               FROM acc_qbo_account_map m
               JOIN acc_accounts acc ON acc.id = m.ff_account_id
              WHERE m.mapping_status = 'mapped'
                AND acc.account_type = 'revenue'
              ORDER BY acc.code
              LIMIT 1"
        );
        if ($any) {
            return [
                'qbo_id'   => (string) $any['qbo_account_id'],
                'qbo_name' => (string) ($any['qbo_name'] ?? ''),
            ];
        }

        // Pass 4: nothing mapped — halt with actionable error.
        throw new ChartOfAccountsIncompleteException(
            "Cannot create QBO Item for '{$itemType}': no mapped revenue account in acc_qbo_account_map. "
            . "Complete S-QBO-8 chart-of-accounts mapping (map at least one account_type='revenue' account) "
            . "before authoring Items."
        );
    }
}
