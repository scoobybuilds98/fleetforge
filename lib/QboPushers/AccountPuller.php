<?php
declare(strict_types=1);

/**
 * lib/QboPushers/AccountPuller.php
 *
 * QBO → FF account pull. Mirrors VendorPuller / CustomerPuller shape
 * for the Account entity. PULLER-ONLY per D-QBO-8-1 — no corresponding
 * AccountPusher class exists; the accountant owns the COA structure in
 * QBO and FF mirrors via the mapping table.
 *
 * Pagination strategy (spec §14.1 budget):
 *   - Page size 100 (sandbox-safe; ~30-60 accounts in a typical chart
 *     fits in one request).
 *   - Loop terminates when (a) batch empty OR (b) batch < page size.
 *
 * Per K-22 Trap #60 + S-QBO-5-FIX-1: QuickBooksClient::query()
 * normalizes the 1-row-object case at the client boundary —
 * `$response['QueryResponse']['Account'] ?? []` is always iterable,
 * no defensive single-row wrap needed.
 *
 * @session  S-QBO-8
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.1 (account mapping table)
 * @decision D-QBO-8-1 (Puller-only — no AccountPusher; accountant
 *                       owns COA structure in QBO)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class AccountPuller
{
    /** Page size for STARTPOSITION/MAXRESULTS pagination. */
    public const PAGE_SIZE = 100;

    /**
     * Pull every QBO Account (active + inactive) as a flat array of
     * normalized records. Caller is responsible for upserting into
     * acc_qbo_account_map (side-effect-free here so the smoke can
     * exercise normalize() against mock JSON without a writable DB).
     *
     * Each record carries:
     *   qbo_id, sync_token, name, fully_qualified_name, account_type,
     *   account_subtype, classification, account_number, active,
     *   current_balance, parent_ref, last_updated_qbo
     *
     * @return array<int, array<string, mixed>>
     * @throws \FleetForge\Exceptions\QuickBooksException on HTTP errors per QuickBooksClient retry policy
     */
    public static function pullAll(): array
    {
        $client        = new QuickBooksClient();
        $allAccounts   = [];
        $startPosition = 1;

        while (true) {
            $sql      = sprintf(
                'SELECT * FROM Account STARTPOSITION %d MAXRESULTS %d',
                $startPosition,
                self::PAGE_SIZE
            );
            $response = $client->query($sql, ['entity_type' => 'account']);

            // Per K-22 Trap #60: normalized at client boundary.
            $batch = $response['QueryResponse']['Account'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $a) {
                $allAccounts[] = self::normalize($a);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $startPosition += self::PAGE_SIZE;
        }

        return $allAccounts;
    }

    /**
     * Flatten a raw QBO Account entity into the storage shape used by
     * acc_qbo_account_map. Defensive about missing keys — QBO doesn't
     * populate every field on every account (e.g. CurrentBalance is
     * absent on header / non-posting accounts; ParentRef is absent on
     * top-level accounts).
     *
     * Public-static so the offline smoke can exercise it without HTTP.
     *
     * @param  array<string, mixed> $qboAccount Raw QBO Account JSON
     * @return array<string, mixed>             Flat normalized record
     */
    public static function normalize(array $qboAccount): array
    {
        return [
            'qbo_id'               => (string) ($qboAccount['Id']                       ?? ''),
            'sync_token'           => (string) ($qboAccount['SyncToken']                ?? '0'),
            'name'                 => (string) ($qboAccount['Name']                     ?? ''),
            'fully_qualified_name' => (string) ($qboAccount['FullyQualifiedName']       ?? ''),
            'account_type'         => (string) ($qboAccount['AccountType']              ?? ''),
            'account_subtype'      => (string) ($qboAccount['AccountSubType']           ?? ''),
            'classification'       => (string) ($qboAccount['Classification']           ?? ''),
            'account_number'       => (string) ($qboAccount['AcctNum']                  ?? ''),
            'active'               => (bool)   ($qboAccount['Active']                   ?? true),
            'current_balance'      => (float)  ($qboAccount['CurrentBalance']           ?? 0),
            'parent_ref'           => (string) ($qboAccount['ParentRef']['value']       ?? ''),
            'last_updated_qbo'     => (string) ($qboAccount['MetaData']['LastUpdatedTime'] ?? ''),
        ];
    }
}
