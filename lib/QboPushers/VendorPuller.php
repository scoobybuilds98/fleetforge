<?php
declare(strict_types=1);

/**
 * lib/QboPushers/VendorPuller.php
 *
 * QBO → FF vendor pull. Mirrors CustomerPuller (S-QBO-5) for the
 * Vendor entity: paginated /query against QBO using
 * STARTPOSITION + MAXRESULTS, normalized into flat records ready
 * for upsert into acc_qbo_vendor_map.
 *
 * Pagination strategy (spec §14.1 budget):
 *   - Page size 100 (well under Intuit's 1000 max + 40 req/min
 *     sandbox throttle even for a maximum-sized vendor list).
 *   - Loop terminates when (a) batch empty OR (b) batch < page size.
 *   - Mainland-sized list (~5-50 vendors) completes in 1 request.
 *
 * NO retries beyond what QuickBooksClient::dispatch already does
 * (S-QBO-2 retry orchestration). Permanent failures propagate as
 * QuickBooksException subclasses.
 *
 * Per K-22 Trap #60 + S-QBO-5-FIX-1: QuickBooksClient::query()
 * normalizes the 1-row-object case at the client boundary.
 * NO defensive single-row wrap here — `$response['QueryResponse']
 * ['Vendor'] ?? []` is always iterable.
 *
 * @session  S-QBO-7
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.5 (vendor mapping table),
 *           §6.8 (Pusher contract), §14.1 (rate limits)
 * @decision D-QBO-5-1 (FleetForge\QboPushers namespace shared by
 *                       Pushers + Pullers — established S-QBO-5)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class VendorPuller
{
    /** Page size for STARTPOSITION/MAXRESULTS pagination. Same default
     *  as CustomerPuller — conservative against the sandbox 40 req/min
     *  throttle while still big enough to one-shot most realistic lists. */
    public const PAGE_SIZE = 100;

    /**
     * Pull every QBO Vendor (active + inactive) into a flat array
     * of normalized records. Caller is responsible for upserting into
     * acc_qbo_vendor_map (side-effect-free here so the smoke can
     * exercise normalize() against mock JSON without a writable DB).
     *
     * Each returned record carries:
     *   qbo_id, sync_token, display_name, company_name, given_name,
     *   family_name, email, phone, active, v4v_status, last_updated_qbo
     *
     * @return array<int, array<string, mixed>>
     * @throws \FleetForge\Exceptions\QuickBooksException on HTTP errors per QuickBooksClient retry policy
     */
    public static function pullAll(): array
    {
        $client        = new QuickBooksClient();
        $allVendors    = [];
        $startPosition = 1;

        while (true) {
            $sql      = sprintf(
                'SELECT * FROM Vendor STARTPOSITION %d MAXRESULTS %d',
                $startPosition,
                self::PAGE_SIZE
            );
            $response = $client->query($sql, ['entity_type' => 'vendor']);

            // Per K-22 Trap #60: QuickBooksClient::query() normalizes
            // single-row object → array at the boundary, so this is
            // always iterable.
            $batch = $response['QueryResponse']['Vendor'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $v) {
                $allVendors[] = self::normalize($v);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $startPosition += self::PAGE_SIZE;
        }

        return $allVendors;
    }

    /**
     * Flatten a raw QBO Vendor entity into the storage shape used
     * by acc_qbo_vendor_map. Defensive about missing keys — QBO doesn't
     * always populate every field (e.g., personal vendors have
     * GivenName/FamilyName but no CompanyName; B2B vendors the reverse).
     *
     * Public-static so the offline smoke can exercise it without a
     * live HTTP call.
     *
     * @param  array<string, mixed> $qboVendor Raw QBO Vendor JSON
     * @return array<string, mixed>            Flat normalized record
     */
    public static function normalize(array $qboVendor): array
    {
        return [
            'qbo_id'           => (string) ($qboVendor['Id']                            ?? ''),
            'sync_token'       => (string) ($qboVendor['SyncToken']                     ?? '0'),
            'display_name'     => (string) ($qboVendor['DisplayName']                   ?? ''),
            'company_name'     => (string) ($qboVendor['CompanyName']                   ?? ''),
            'given_name'       => (string) ($qboVendor['GivenName']                     ?? ''),
            'family_name'      => (string) ($qboVendor['FamilyName']                    ?? ''),
            'email'            => (string) ($qboVendor['PrimaryEmailAddr']['Address']    ?? ''),
            'phone'            => (string) ($qboVendor['PrimaryPhone']['FreeFormNumber'] ?? ''),
            'active'           => (bool)   ($qboVendor['Active']                        ?? true),
            'v4v_status'       => (string) ($qboVendor['V4VStatus']                     ?? ''),
            'last_updated_qbo' => (string) ($qboVendor['MetaData']['LastUpdatedTime']   ?? ''),
        ];
    }
}
