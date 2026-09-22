<?php
declare(strict_types=1);

/**
 * lib/QboPushers/RealmGuard.php
 *
 * Protects the books when FleetForge is connected to a DIFFERENT QuickBooks
 * company than the one its mappings were built against — the sandbox →
 * real-company switch at go-live is exactly this.
 *
 * WHY it matters: every acc_qbo_*_map table stores bare QBO Ids (Customer
 * "58", Account "35", Item "12" …). QBO Ids are small per-company integers,
 * so the same Id usually EXISTS in the other company and names a different
 * record. Pushing with stale mappings does not fail loudly — it posts real
 * invoices to the wrong customers, JEs to the wrong accounts, and treats
 * invoices "already_mapped" to sandbox Ids as already pushed. The runbook
 * (docs/runbooks/qbo_realm_change.md) described a manual SQL wipe; nothing
 * enforced it, and its table list had fallen out of date.
 *
 * Contract:
 *   - quickbooks.mapped_realm_id — the company the current map rows belong
 *     to. Stamped on the first connect with empty maps, or by resetMappings().
 *   - quickbooks.realm_mismatch — '1' while connected to another company.
 *     QuickBooksClient::realmGuardReason() reads it and refuses every API
 *     call except CompanyInfo; the worker exits; master sync cannot be
 *     enabled (save_master_controls.php).
 *   - resetMappings() is the only way to clear it: wipes every realm-scoped
 *     row, then adopts the connected company as the mapped realm.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

use FleetForge\QboFixture;
use FleetForge\QuickBooksClient;

class RealmGuard
{
    /**
     * Every table whose rows hold QBO Ids from ONE company. Wiped by
     * resetMappings(). Keep in sync when a new acc_qbo_*_map ships — the
     * smoke asserts every acc_qbo_%_map table in the schema is listed.
     * Order is irrelevant (no FKs point INTO these tables).
     */
    public const REALM_SCOPED_TABLES = [
        'acc_qbo_customer_map',
        'acc_qbo_vendor_map',
        'acc_qbo_account_map',
        'acc_qbo_item_map',
        'acc_qbo_tax_code_map',
        'acc_qbo_tax_rate_map',
        'acc_qbo_bank_account_map',
        'acc_qbo_bank_transaction_map',
        'acc_qbo_invoice_map',
        'acc_qbo_payment_map',
        'acc_qbo_credit_memo_map',
        'acc_qbo_credit_application_map',
        'acc_qbo_refund_receipt_map',
        'acc_qbo_bill_map',
        'acc_qbo_bill_payment_map',
        'acc_qbo_journal_entry_map',
        'acc_qbo_fixed_asset_map',
    ];

    /**
     * Settings that hold a QBO Id from the old company and must be cleared
     * with the mappings (they would otherwise point at a random record).
     */
    private const REALM_SCOPED_SETTINGS = [
        'tax_override_code_id',
        'refund.deposit_account_id',
        'refund.payment_method_id',
        'invoice.tax_code_exempt',
        // Business tagging (QboTagging): Class / Location ids of the old company.
        'class_id',
        'class_name',
        'location_id',
        'location_name',
        // Company preferences read from the old company (re-read on the next
        // CompanyInfo sync) and the go-live stamp, which belongs to the
        // company FleetForge first synced with — the new one gets its own.
        'pref.class_tracking',
        'pref.track_locations',
        'pref.book_close_date',
        'pref.custom_txn_numbers',
        'pref.synced_at',
        'cutover_at',
        'push_from_date',
    ];

    /** Realms that never identify a real company (smoke + fixture sentinels). */
    private const SENTINEL_REALMS = ['', 'unknown', 'SMOKE-REALM', QboFixture::REALM_SENTINEL];

    /** True when any realm-scoped table holds at least one row. */
    public static function hasMappings(): bool
    {
        foreach (self::REALM_SCOPED_TABLES as $table) {
            try {
                if (db_row("SELECT 1 AS x FROM `{$table}` LIMIT 1") !== null) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Table not migrated on this install — nothing to protect.
            }
        }
        return false;
    }

    /**
     * The company the current map rows belong to. quickbooks.mapped_realm_id
     * when stamped; otherwise (installs that predate this guard) the realm of
     * the most recent real API call in the sync log. '' when unknown.
     */
    public static function mappingRealm(): string
    {
        $stamped = (string) settings_get('quickbooks.mapped_realm_id', '');
        if ($stamped !== '') {
            return $stamped;
        }
        try {
            $in  = implode(',', array_fill(0, count(self::SENTINEL_REALMS), '?'));
            $row = db_row(
                "SELECT realm_id FROM acc_qbo_sync_log
                  WHERE realm_id NOT IN ({$in})
                  ORDER BY id DESC LIMIT 1",
                self::SENTINEL_REALMS
            );
            return (string) ($row['realm_id'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Called by the OAuth callback once the new realm is known. Decides
     * whether the existing mappings belong to it and records the verdict.
     *
     * On mismatch also forces quickbooks.sync_enabled='0' — the realm guard
     * already blocks every call, but leaving the kill-switch on would resume
     * pushing the moment someone cleared the flag by hand.
     *
     * @return array{mismatch: bool, mapped_realm: string}
     */
    public static function onConnect(string $newRealm): array
    {
        if (!self::hasMappings()) {
            // Fresh start — whatever gets mapped from here belongs to this company.
            QuickBooksClient::settings_write_qbo('mapped_realm_id', $newRealm);
            QuickBooksClient::settings_write_qbo('realm_mismatch', '0');
            return ['mismatch' => false, 'mapped_realm' => $newRealm];
        }

        $mapped = self::mappingRealm();
        if ($mapped !== '' && $mapped === $newRealm) {
            // Re-connect to the same company — resume cleanly (spec §5.5).
            QuickBooksClient::settings_write_qbo('mapped_realm_id', $newRealm);
            QuickBooksClient::settings_write_qbo('realm_mismatch', '0');
            return ['mismatch' => false, 'mapped_realm' => $newRealm];
        }

        // Mappings exist for another company, or for a company we cannot
        // identify (e.g. only fixture/seed rows). Fail closed: an operator
        // reset is cheap; posting to the wrong books is not.
        QuickBooksClient::settings_write_qbo('realm_mismatch', '1');
        QuickBooksClient::settings_write_qbo('sync_enabled', '0');
        return ['mismatch' => true, 'mapped_realm' => $mapped];
    }

    /**
     * Wipe every realm-scoped row and adopt the connected company as the
     * mapped realm. Refuses while master sync is on (the worker could race
     * the wipe) or while not connected (nothing to adopt).
     *
     * Also clears queued/processing sync jobs (they were enqueued under the
     * old mappings; the operator re-queues deliberately after re-mapping),
     * drops the old company's OPEN drift events, and expires pending portal
     * payment links (they point at the old company's invoices). The sync log,
     * webhook log and historical-pull runs are realm-stamped history and stay.
     *
     * @return array<string, int> rows removed per table
     * @throws \RuntimeException when a precondition fails
     */
    public static function resetMappings(?int $userId, string $userName): array
    {
        if ((string) settings_get('quickbooks.sync_enabled', '0') === '1') {
            throw new \RuntimeException('Turn off master sync before resetting mappings.');
        }
        $realm = (string) settings_get('quickbooks.realm_id', '');
        if ($realm === '' || (string) settings_get('quickbooks.connection_status', '') !== 'connected') {
            throw new \RuntimeException('Connect to the QuickBooks company you want to use before resetting mappings.');
        }
        $oldRealm = self::mappingRealm();

        return db_transaction(function () use ($realm, $oldRealm, $userId, $userName): array {
            $removed = [];
            foreach (self::REALM_SCOPED_TABLES as $table) {
                try {
                    $removed[$table] = (int) db_execute("DELETE FROM `{$table}`");
                } catch (\Throwable $e) {
                    // Table absent on this install — nothing to wipe.
                    $removed[$table] = 0;
                }
            }
            $removed['acc_qbo_sync_queue'] = (int) db_execute(
                "DELETE FROM acc_qbo_sync_queue WHERE status IN ('queued','processing')"
            );
            $removed['acc_qbo_drift_events'] = (int) db_execute(
                "DELETE FROM acc_qbo_drift_events WHERE resolved_at IS NULL AND realm_id <> ?",
                [$realm]
            );
            $removed['acc_qbo_payment_initiations'] = (int) db_execute(
                "UPDATE acc_qbo_payment_initiations SET status = 'expired', completed_at = ?
                  WHERE status = 'pending' AND realm_id <> ?",
                [ff_now_utc(), $realm]
            );

            foreach (self::REALM_SCOPED_SETTINGS as $shortKey) {
                QuickBooksClient::settings_write_qbo($shortKey, '');
            }
            QuickBooksClient::settings_write_qbo('mapped_realm_id', $realm);
            QuickBooksClient::settings_write_qbo('realm_mismatch', '0');

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_realm_change',
                'entity_label' => 'mapping reset',
                'notes'        => "QBO mappings reset for realm change ("
                    . ($oldRealm !== '' ? $oldRealm : 'unknown') . " → {$realm}). Rows removed: "
                    . json_encode(array_filter($removed)),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            return $removed;
        });
    }
}
