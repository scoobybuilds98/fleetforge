<?php
declare(strict_types=1);

/**
 * lib/QboPushers/TaxCodePuller.php
 *
 * QBO → FF tax code pull. PULLER-ONLY per D-QBO-9-1 — no
 * corresponding TaxCodePusher class. The accountant owns the tax
 * code structure in QBO; FF mirrors via acc_qbo_tax_code_map.
 *
 * The CRITICAL deliverable of this Puller's downstream wiring is
 * identifying QBO's 'NON' tax code (Intuit-seeded no-tax-computed
 * code) which becomes the override target for S-QBO-11 invoice
 * push (D-QBO-CORE-6 + D-QBO-9-2 tax-override pattern). The
 * identification happens in TaxCodeMatcher::identifyOverrideTarget;
 * this Puller just normalizes the list.
 *
 * Pagination: same shape as Account/Vendor/CustomerPuller. Page
 * size 100; loop terminates on empty or short batch.
 *
 * Per K-22 Trap #60 + S-QBO-5-FIX-1: QuickBooksClient::query()
 * normalizes 1-row-object → array at boundary; no defensive wrap.
 *
 * Effective rate caveat: QBO TaxCode carries TaxRateRef references
 * (in SalesTaxRateList / PurchaseTaxRateList), not direct percentages.
 * Resolving actual rate values requires querying TaxRate entities
 * (separate endpoint) and joining. For v1 we capture the refs as JSON
 * but don't resolve. If operator needs the percentage in the UI later,
 * a follow-up session adds TaxRatePuller. UI displays "see QBO
 * directly" for the rate column rather than a partial number.
 *
 * @session  S-QBO-9
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §7.2 (tax code mapping table)
 * @decision D-QBO-9-1 (Puller-only — no TaxCodePusher; accountant
 *                       owns tax code structure in QBO)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class TaxCodePuller
{
    /** Page size for STARTPOSITION/MAXRESULTS pagination. */
    public const PAGE_SIZE = 100;

    /**
     * Pull every QBO TaxCode (active + inactive) as a flat array of
     * normalized records. Caller is responsible for upserting into
     * acc_qbo_tax_code_map (side-effect-free here so the smoke can
     * exercise normalize() against mock JSON without a writable DB).
     *
     * Each record carries:
     *   qbo_id, sync_token, name, description, taxable, hidden,
     *   active, tax_group, sales_rate_refs, last_updated_qbo
     *
     * @return array<int, array<string, mixed>>
     * @throws \FleetForge\Exceptions\QuickBooksException on HTTP errors per QuickBooksClient retry policy
     */
    public static function pullAll(): array
    {
        $client        = new QuickBooksClient();
        $allCodes      = [];
        $startPosition = 1;

        while (true) {
            $sql      = sprintf(
                'SELECT * FROM TaxCode STARTPOSITION %d MAXRESULTS %d',
                $startPosition,
                self::PAGE_SIZE
            );
            $response = $client->query($sql, ['entity_type' => 'tax_code']);

            // Per K-22 Trap #60: normalized at client boundary.
            $batch = $response['QueryResponse']['TaxCode'] ?? [];

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $tc) {
                $allCodes[] = self::normalize($tc);
            }

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }

            $startPosition += self::PAGE_SIZE;
        }

        return $allCodes;
    }

    /**
     * Flatten a raw QBO TaxCode entity into the storage shape used by
     * acc_qbo_tax_code_map. Defensive about missing keys — QBO sandbox
     * TaxCode shapes vary by region (US sandboxes have different
     * SalesTaxRateList structure than CA sandboxes).
     *
     * Public-static so the offline smoke can exercise it without HTTP.
     *
     * @param  array<string, mixed> $qboTaxCode Raw QBO TaxCode JSON
     * @return array<string, mixed>             Flat normalized record
     */
    public static function normalize(array $qboTaxCode): array
    {
        // SalesTaxRateList carries TaxRateDetail entries with
        // TaxRateRef refs (not values). Capture the full structure
        // for future resolution; rate values not computed in v1.
        $salesRateDetails = $qboTaxCode['SalesTaxRateList']['TaxRateDetail'] ?? [];
        // Defensive: handle both single-detail (object) and
        // multi-detail (array of objects) shapes. Intuit's query
        // response normalization in QuickBooksClient handles top-level
        // entities but nested TaxRateDetail under SalesTaxRateList is
        // an inner shape that may not pass through normalizeQueryResponse.
        if (is_array($salesRateDetails) && !empty($salesRateDetails) && !isset($salesRateDetails[0])) {
            // Single-detail object → wrap to 1-element array.
            $salesRateDetails = [$salesRateDetails];
        }

        return [
            'qbo_id'           => (string) ($qboTaxCode['Id']           ?? ''),
            'sync_token'       => (string) ($qboTaxCode['SyncToken']    ?? '0'),
            'name'             => (string) ($qboTaxCode['Name']         ?? ''),
            'description'      => (string) ($qboTaxCode['Description']  ?? ''),
            'taxable'          => (bool)   ($qboTaxCode['Taxable']      ?? false),
            'hidden'           => (bool)   ($qboTaxCode['Hidden']       ?? false),
            'active'           => (bool)   ($qboTaxCode['Active']       ?? true),
            'tax_group'        => (bool)   ($qboTaxCode['TaxGroup']     ?? false),
            'sales_rate_refs'  => $salesRateDetails, // JSON-storable
            'last_updated_qbo' => (string) ($qboTaxCode['MetaData']['LastUpdatedTime'] ?? ''),
        ];
    }
}
