<?php
declare(strict_types=1);

/**
 * lib/QboPushers/CustomerPuller.php
 *
 * QBO → FF customer pull. Uses QuickBooksClient::query() to fetch
 * Customer records from Intuit, paginating via STARTPOSITION +
 * MAXRESULTS until an empty page returns. Hands back a flat array
 * of normalized records ready for upsert into acc_qbo_customer_map.
 *
 * Lives under `lib/QboPushers/` alongside (forthcoming) Pusher
 * classes per D-QBO-5-1 — Pullers + Pushers share a namespace
 * because they're both "QBO-facing entity-specific HTTP boundary
 * classes" and the dispatcher from S-QBO-3 already looks here
 * first (FleetForge\QboPushers\<Name>).
 *
 * Pagination strategy (spec §14.1 budget):
 *   - Page size 100 (Intuit max is 1000 but 100 is the safer batch
 *     for a fresh sandbox + leaves headroom against the 40 req/min
 *     sandbox rate limit).
 *   - Loop terminates when (a) batch comes back empty OR (b) batch
 *     count < page size (last page).
 *   - Typical Mainland-sized list (~50-200 customers) completes in
 *     1-2 requests — well under the rate-limit budget.
 *
 * NO retries beyond what QuickBooksClient::dispatch already does
 * (S-QBO-2 retry orchestration on transient errors). Permanent
 * failures propagate via QuickBooksException subclasses.
 *
 * @session  S-QBO-5
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.4 (mapping table), §8.1 (Customer field map)
 * @decision D-QBO-5-1 (FleetForge\QboPushers namespace for Pullers + Pushers)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class CustomerPuller
{
    /** Page size for STARTPOSITION/MAXRESULTS pagination. Conservative
     *  default — well under Intuit's 1000 max and well below the
     *  40 req/min sandbox throttle even for the largest customer list. */
    public const PAGE_SIZE = 100;

    /**
     * Pull every QBO Customer (active and inactive) into a flat array
     * of normalized records. Caller is responsible for upserting into
     * acc_qbo_customer_map (we keep this class side-effect-free so
     * the smoke can exercise normalize() against mock JSON without
     * needing a writable DB).
     *
     * Each returned record carries:
     *   qbo_id, sync_token, display_name, company_name, given_name,
     *   family_name, email, phone, active, balance, last_updated_qbo
     *
     * @return array<int, array<string, mixed>>
     * @throws \FleetForge\Exceptions\QuickBooksException on HTTP errors per QuickBooksClient retry policy
     */
    public static function pullAll(): array
    {
        $client        = new QuickBooksClient();
        $allCustomers  = [];
        $startPosition = 1;

        // Intuit's QBO query language uses STARTPOSITION + MAXRESULTS,
        // both 1-indexed (per Intuit's docs). The loop terminates on
        // empty batch OR short batch (last page).
        while (true) {
            $sql      = sprintf(
                'SELECT * FROM Customer STARTPOSITION %d MAXRESULTS %d',
                $startPosition,
                self::PAGE_SIZE
            );
            $response = $client->query($sql, ['entity_type' => 'customer']);

            // QBO returns Customer as an ARRAY when ≥2 rows, OBJECT
            // when exactly 1, and ABSENT when 0. Normalize all three
            // shapes into an array of records.
            $batch = $response['QueryResponse']['Customer'] ?? [];
            if (!empty($batch) && !isset($batch[0])) {
                // Single-row response — QBO returned an object,
                // not a list. Wrap into a 1-element array.
                $batch = [$batch];
            }

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $c) {
                $allCustomers[] = self::normalize($c);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $startPosition += self::PAGE_SIZE;
        }

        return $allCustomers;
    }

    /**
     * Flatten a raw QBO Customer entity into the storage shape used
     * by acc_qbo_customer_map. Defensive against missing keys — QBO
     * doesn't always populate every field (e.g., personal Customer
     * records have GivenName/FamilyName but no CompanyName; B2B
     * Customers have the reverse). Every accessor uses `?? ''` or
     * `?? false`/`?? 0` to avoid undefined-index warnings.
     *
     * @param  array<string, mixed> $qboCustomer Raw QBO Customer JSON
     * @return array<string, mixed>              Flat normalized record
     */
    public static function normalize(array $qboCustomer): array
    {
        return [
            'qbo_id'           => (string) ($qboCustomer['Id']                          ?? ''),
            'sync_token'       => (string) ($qboCustomer['SyncToken']                   ?? '0'),
            'display_name'     => (string) ($qboCustomer['DisplayName']                 ?? ''),
            'company_name'     => (string) ($qboCustomer['CompanyName']                 ?? ''),
            'given_name'       => (string) ($qboCustomer['GivenName']                   ?? ''),
            'family_name'      => (string) ($qboCustomer['FamilyName']                  ?? ''),
            'email'            => (string) ($qboCustomer['PrimaryEmailAddr']['Address'] ?? ''),
            'phone'            => (string) ($qboCustomer['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'active'           => (bool)   ($qboCustomer['Active']                      ?? true),
            'balance'          => (float)  ($qboCustomer['Balance']                     ?? 0),
            'last_updated_qbo' => (string) ($qboCustomer['MetaData']['LastUpdatedTime'] ?? ''),
        ];
    }
}
