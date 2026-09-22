<?php
declare(strict_types=1);

/**
 * lib/QboPushers/QboTagging.php
 *
 * Business tagging for a SHARED QuickBooks company file.
 *
 * Mainland's QuickBooks file holds more than one business. Everything
 * FleetForge pushes belongs to the rental business, so every document is
 * stamped with the rental business's QuickBooks Class and/or Location
 * (QBO calls Locations "Departments" in the API) chosen on Settings →
 * QuickBooks → Business tagging. Without it every FF invoice, credit memo,
 * refund, bill and journal entry landed "Unclassified" and rental revenue
 * disappeared into company-wide totals.
 *
 * Where QuickBooks takes each reference (Intuit field docs):
 *   - ClassRef: header when Preferences.AccountingInfoPrefs.ClassTrackingPerTxn,
 *     per line (SalesItemLineDetail / AccountBasedExpenseLineDetail) when
 *     ClassTrackingPerTxnLine — cached as quickbooks.pref.class_tracking by
 *     CompanyInfoSync::syncPreferences. Journal entry lines always per line.
 *   - DepartmentRef (Location): transaction header (JE: per line), only when
 *     TrackDepartments is on.
 * Payments and bill payments carry neither — QuickBooks reports them through
 * the documents they pay.
 *
 * No-op until a Class or Location is chosen, so single-business companies
 * are unaffected.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

final class QboTagging
{
    /** Configured QBO Class Id ('' = none). */
    public static function classId(): string
    {
        return trim((string) settings_get('quickbooks.class_id', ''));
    }

    /** Configured QBO Department (Location) Id ('' = none). */
    public static function locationId(): string
    {
        return trim((string) settings_get('quickbooks.location_id', ''));
    }

    /**
     * Stamp a sales document (Invoice / CreditMemo / RefundReceipt) payload.
     * Lines of DetailType SalesItemLineDetail get a line ClassRef when the
     * company tracks class per line.
     */
    public static function applyToSalesDoc(array $payload): array
    {
        return self::applyHeaderAndLines($payload, 'SalesItemLineDetail');
    }

    /** Stamp a Bill payload (AccountBasedExpenseLineDetail lines). */
    public static function applyToBill(array $payload): array
    {
        return self::applyHeaderAndLines($payload, 'AccountBasedExpenseLineDetail');
    }

    /**
     * Stamp a JournalEntry payload — class and location both live on each
     * JournalEntryLineDetail.
     */
    public static function applyToJournalEntry(array $payload): array
    {
        $class = self::classId();
        $loc   = self::locationId();
        if ($class === '' && $loc === '') {
            return $payload;
        }
        foreach ($payload['Line'] ?? [] as $i => $line) {
            if (!isset($line['JournalEntryLineDetail']) || !is_array($line['JournalEntryLineDetail'])) {
                continue;
            }
            if ($class !== '') {
                $payload['Line'][$i]['JournalEntryLineDetail']['ClassRef'] = ['value' => $class];
            }
            if ($loc !== '' && self::tracksLocations()) {
                $payload['Line'][$i]['JournalEntryLineDetail']['DepartmentRef'] = ['value' => $loc];
            }
        }
        return $payload;
    }

    private static function applyHeaderAndLines(array $payload, string $detailKey): array
    {
        $class = self::classId();
        $loc   = self::locationId();
        if ($class === '' && $loc === '') {
            return $payload;
        }
        if ($loc !== '' && self::tracksLocations()) {
            $payload['DepartmentRef'] = ['value' => $loc];
        }
        if ($class !== '') {
            $mode = (string) settings_get('quickbooks.pref.class_tracking', 'txn');
            if ($mode === 'line') {
                foreach ($payload['Line'] ?? [] as $i => $line) {
                    if (isset($line[$detailKey]) && is_array($line[$detailKey])) {
                        $payload['Line'][$i][$detailKey]['ClassRef'] = ['value' => $class];
                    }
                }
            } elseif ($detailKey === 'AccountBasedExpenseLineDetail') {
                // Bills have no header ClassRef in QBO — class always rides
                // the expense lines.
                foreach ($payload['Line'] ?? [] as $i => $line) {
                    if (isset($line[$detailKey]) && is_array($line[$detailKey])) {
                        $payload['Line'][$i][$detailKey]['ClassRef'] = ['value' => $class];
                    }
                }
            } else {
                $payload['ClassRef'] = ['value' => $class];
            }
        }
        return $payload;
    }

    /**
     * DocNumber policy for sales forms (credit memos, refund receipts):
     * QuickBooks only takes a supplied number safely when "Custom
     * transaction numbers" is ON (quickbooks.pref.custom_txn_numbers). With
     * it OFF, drop DocNumber — QBO numbers the document — and carry the FF
     * number in the customer message instead. (InvoicePusher applies the same
     * rule inline, where the memo also carries unit / contract.)
     */
    public static function applyDocNumberPolicy(array $payload, string $label): array
    {
        if ((string) settings_get('quickbooks.pref.custom_txn_numbers', '1') !== '0' || empty($payload['DocNumber'])) {
            return $payload;
        }
        $ref = "FleetForge {$label} " . (string) $payload['DocNumber'];
        unset($payload['DocNumber']);
        $existing = (string) ($payload['CustomerMemo']['value'] ?? '');
        $payload['CustomerMemo'] = ['value' => substr($existing === '' ? $ref : $ref . "\n" . $existing, 0, QboFieldLimits::INVOICE_CUSTOMER_MEMO_MAX)];
        return $payload;
    }

    /**
     * Location tracking state. Unknown (preferences never synced) counts as
     * ON: the Location was picked from QuickBooks' own Department list, which
     * only exists when the feature is on. An explicit '0' suppresses it.
     */
    private static function tracksLocations(): bool
    {
        return (string) settings_get('quickbooks.pref.track_locations', '1') !== '0';
    }
}
