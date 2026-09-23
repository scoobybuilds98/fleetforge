<?php
declare(strict_types=1);

/**
 * lib/QboPushers/DisplayNameClash.php
 *
 * S-QBO-NAME-CLASH — what to do when QuickBooks refuses to CREATE a customer
 * or vendor because its name is taken (Fault 6240, "Duplicate Name Exists").
 *
 * QuickBooks requires DisplayName to be unique across customers, vendors
 * AND employees. A company that is both a rental customer and a supplier
 * (common in trucking) therefore failed its second push, and the audit left
 * it for the accountant to rename one side by hand. Operator decision
 * (2026-09-23): add " (Vendor)" / " (Customer)" automatically.
 *
 * But a 6240 has two very different causes, and only one may be renamed:
 *   - the name belongs to a DIFFERENT kind of record (the customer side of
 *     a dual-role company, an employee) → create this one as
 *     "ACME (Vendor)" — CompanyName and the cheque name keep "ACME";
 *   - a record of the SAME kind already has the name (the accountant's
 *     vendor, maybe inactive, or another business's in the shared file) →
 *     creating "ACME (Vendor)" would make a duplicate vendor. Refuse and ask
 *     for the FF record to be LINKED to it on QuickBooks → Vendors.
 *
 * Used by CustomerPusher and VendorPusher on their create path only
 * (updates never send DisplayName — D-QBO-GOLIVE-2).
 *
 * @session  S-QBO-NAME-CLASH
 * @decision D-QBO-NAME-CLASH-1
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

final class DisplayNameClash
{
    /** Suffix per QuickBooks entity. */
    public const SUFFIX = [
        'Vendor'   => ' (Vendor)',
        'Customer' => ' (Customer)',
    ];

    /** Longest DisplayName FF builds (QuickBooks allows more; this stays clear of any limit). */
    public const MAX_LEN = 100;

    /** True for QuickBooks' "name already taken" fault. */
    public static function isClash(QuickBooksException $e): bool
    {
        return (string) ($e->errorCode ?? '') === '6240';
    }

    /**
     * The existing QuickBooks record of the SAME kind holding $name
     * (active or inactive), or null when the name is held by another kind
     * of record. Throws when QuickBooks can't be asked — the caller then
     * keeps the original error rather than guessing.
     *
     * @param 'Vendor'|'Customer' $entity
     * @return array{id:string, active:bool}|null
     */
    public static function sameTypeHolder(QuickBooksClient $client, string $entity, string $name): ?array
    {
        $resp = $client->query(
            "SELECT Id, DisplayName, Active FROM {$entity} WHERE DisplayName = '" . self::escape($name) . "' AND Active IN (true, false)",
            ['entity_type' => strtolower($entity), 'operation' => 'name_clash_lookup']
        );
        foreach ($resp['QueryResponse'][$entity] ?? [] as $row) {
            // QuickBooks matches DisplayName case-insensitively; so do we.
            if (strcasecmp(trim((string) ($row['DisplayName'] ?? '')), trim($name)) === 0) {
                return ['id' => (string) ($row['Id'] ?? ''), 'active' => !empty($row['Active'])];
            }
        }
        return null;
    }

    /**
     * "ACME" → "ACME (Vendor)", trimming the base so the result fits MAX_LEN.
     *
     * @param 'Vendor'|'Customer' $entity
     */
    public static function suffixed(string $name, string $entity): string
    {
        $suffix = self::SUFFIX[$entity];
        $base   = trim($name);
        $room   = self::MAX_LEN - mb_strlen($suffix);
        if (mb_strlen($base) > $room) {
            $base = rtrim(mb_substr($base, 0, $room));
        }
        return $base . $suffix;
    }

    /**
     * After a 6240 on create: create the record anyway when that is safe.
     * Same-kind holder → refuse with the link message. Otherwise one retry
     * with the suffixed DisplayName; CompanyName is untouched and the
     * cheque / refund name (PrintOnCheckName) keeps the real name, so
     * nothing printed says "(Vendor)".
     *
     * @param 'Vendor'|'Customer'   $entity
     * @param callable(array):array $create creates the entity from a payload, returns QuickBooks' response
     * @return array{response?:array, renamed_to?:string, note?:string, error?:string, status?:string}
     */
    public static function createAnyway(QuickBooksClient $client, string $entity, array $payload, callable $create): array
    {
        $name = trim((string) ($payload['DisplayName'] ?? ''));
        $label = strtolower($entity);
        try {
            $holder = self::sameTypeHolder($client, $entity, $name);
        } catch (\Throwable $e) {
            return ['error' => "QuickBooks says the name \"{$name}\" is taken, and FleetForge could not check by what ("
                . $e->getMessage() . '). Retry, or link / rename it in QuickBooks.', 'status' => 'duplicate_name'];
        }
        if ($holder !== null) {
            return ['error' => self::linkMessage($entity, $name, $holder), 'status' => 'duplicate_name'];
        }

        $renamed = self::suffixed($name, $entity);
        $payload['DisplayName'] = $renamed;
        if (empty($payload['PrintOnCheckName'])) {
            $payload['PrintOnCheckName'] = mb_substr($name, 0, 110);
        }
        try {
            $response = $create($payload);
        } catch (QuickBooksException $e) {
            return self::isClash($e)
                ? ['error' => "Both \"{$name}\" and \"{$renamed}\" are taken in QuickBooks — rename one of them there, then retry.", 'status' => 'duplicate_name']
                : ['error' => $e->getMessage()];
        }
        return [
            'response'   => $response,
            'renamed_to' => $renamed,
            'note'       => "\"{$name}\" is already used in QuickBooks by " . ($entity === 'Vendor' ? 'a customer or employee' : 'a vendor or employee')
                . " — created this {$label} as \"{$renamed}\" (company name and cheque name stay \"{$name}\"). S-QBO-NAME-CLASH",
        ];
    }

    /** The message when the name belongs to a record of the same kind. */
    public static function linkMessage(string $entity, string $name, array $holder): string
    {
        $label = strtolower($entity);
        $page  = $entity === 'Vendor' ? 'Vendors' : 'Customers';
        return "QuickBooks already has " . ($holder['active'] ? 'a' : 'an INACTIVE') . " {$label} named \"{$name}\" (id {$holder['id']}). "
            . "Link this FleetForge {$label} to it on QuickBooks → {$page} instead of creating a second one"
            . ($holder['active'] ? '.' : ' (make it active in QuickBooks first), or rename one of them.');
    }

    /** QuickBooks query-language string escaping (backslash before a quote). */
    public static function escape(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    }
}
