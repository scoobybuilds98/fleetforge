<?php
declare(strict_types=1);

/**
 * lib/QboPushers/ItemPuller.php
 *
 * QBO → FF item pull. Pulls Intuit Item entities (products/services
 * in QBO taxonomy) and normalizes them for upsert into
 * acc_qbo_item_map (as `qbo_only` rows until the operator links them
 * to an FF item_type ENUM value via the mapping UI, or until
 * ItemCreator authors a new QBO Item via the
 * D-QBO-10-4 operator-confirmation flow).
 *
 * Different from TaxCodePuller in two ways:
 *   1. Items have IncomeAccountRef + ExpenseAccountRef — captured here
 *      as id+name snapshots so the UI can show "this Item posts to
 *      <account>" without re-querying QBO every render.
 *   2. The FF "source" side is an ENUM column on
 *      invoice_line_items.item_type (17 fixed values verified at
 *      pre-flight), not a separate table. So unlike Customer/Vendor/
 *      Account pullers, there is no FF-side row-pull pass; instead
 *      ItemMatcher::ffItemTypes() iterates the ENUM via
 *      INFORMATION_SCHEMA introspection. This Puller only handles
 *      the QBO side.
 *
 * Pagination: same shape as Customer/Vendor/Account/TaxCode pullers.
 * Page size 100; loop terminates on empty or short batch.
 *
 * Per K-22 Trap #60 + S-QBO-5-FIX-1: QuickBooksClient::query()
 * normalizes 1-row-object → array at the HTTP boundary; no defensive
 * wrap here.
 *
 * @session  S-QBO-10
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.3 (item mapping table)
 * @decision D-QBO-10-3 (ItemCreator via QuickBooksClient::createEntity;
 *                       Type=Service for all FF item_types)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class ItemPuller
{
    /** Page size for STARTPOSITION/MAXRESULTS pagination. */
    public const PAGE_SIZE = 100;

    /**
     * Pull every QBO Item (active + inactive) as a flat array of
     * normalized records. Caller is responsible for upserting into
     * acc_qbo_item_map (side-effect-free here so the smoke can
     * exercise normalize() against mock JSON without a writable DB).
     *
     * @return array<int, array<string, mixed>>
     * @throws \FleetForge\Exceptions\QuickBooksException on HTTP errors
     */
    public static function pullAll(): array
    {
        $client        = new QuickBooksClient();
        $allItems      = [];
        $startPosition = 1;

        while (true) {
            $sql      = sprintf(
                'SELECT * FROM Item STARTPOSITION %d MAXRESULTS %d',
                $startPosition,
                self::PAGE_SIZE
            );
            $response = $client->query($sql, ['entity_type' => 'item']);

            // K-22 Trap #60: normalized at client boundary.
            $batch = $response['QueryResponse']['Item'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                $allItems[] = self::normalize($item);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $startPosition += self::PAGE_SIZE;
        }

        return $allItems;
    }

    /**
     * Flatten a raw QBO Item entity into the storage shape used by
     * acc_qbo_item_map. Defensive about missing keys — QBO Items
     * may omit ExpenseAccountRef for service-only items, and Bundle
     * type items omit IncomeAccountRef entirely (they roll up child
     * line account references).
     *
     * Public-static so the offline smoke can exercise it without HTTP.
     *
     * @param  array<string, mixed> $qboItem Raw QBO Item JSON
     * @return array<string, mixed>          Flat normalized record
     */
    public static function normalize(array $qboItem): array
    {
        return [
            'qbo_id'                   => (string) ($qboItem['Id']                           ?? ''),
            'sync_token'               => (string) ($qboItem['SyncToken']                    ?? '0'),
            'name'                     => (string) ($qboItem['Name']                         ?? ''),
            'fully_qualified_name'     => (string) ($qboItem['FullyQualifiedName']           ?? ''),
            'description'              => (string) ($qboItem['Description']                  ?? ''),
            'type'                     => (string) ($qboItem['Type']                         ?? ''),
            'active'                   => (bool)   ($qboItem['Active']                       ?? true),
            'income_account_id'        => (string) ($qboItem['IncomeAccountRef']['value']    ?? ''),
            'income_account_name'      => (string) ($qboItem['IncomeAccountRef']['name']     ?? ''),
            'expense_account_id'       => (string) ($qboItem['ExpenseAccountRef']['value']   ?? ''),
            'expense_account_name'     => (string) ($qboItem['ExpenseAccountRef']['name']    ?? ''),
            'last_updated_qbo'         => (string) ($qboItem['MetaData']['LastUpdatedTime']  ?? ''),
        ];
    }
}
