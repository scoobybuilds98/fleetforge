<?php
declare(strict_types=1);

/**
 * lib/Attention/KindRegistry.php
 *
 * Every "Needs attention" kind, plus the operator's per-kind settings
 * (S-ATTENTION-INBOX).
 *
 * Defaults live in each Kind class; Settings → Notifications can override,
 * per kind:
 *   roles     — which roles see it (super_admin always does)
 *   priority  — urgent|todo, for kinds whose priority isn't decided per item
 *   whatsapp  — now|summary|off (used by the WhatsApp channel)
 * stored as one JSON setting, `notifications.kind_overrides`:
 *   {"compliance": {"roles": ["manager"], "whatsapp": "now"}, …}
 *
 * Required by: lib/Attention/AttentionService.php, lib/Attention/NotificationRouter.php,
 *              app/admin/settings/notifications.php, api/v1/attention/*
 * Defines:     FleetForge\Attention\KindRegistry
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention;

use FleetForge\Attention\Kinds\BatchRunKind;
use FleetForge\Attention\Kinds\BillingCycleKind;
use FleetForge\Attention\Kinds\ComplianceKind;
use FleetForge\Attention\Kinds\CreditApplicationKind;
use FleetForge\Attention\Kinds\CustomerAccountKind;
use FleetForge\Attention\Kinds\CustomerRequestKind;
use FleetForge\Attention\Kinds\DamageClaimKind;
use FleetForge\Attention\Kinds\EmailBounceKind;
use FleetForge\Attention\Kinds\EventKind;
use FleetForge\Attention\Kinds\GpsBatteryKind;
use FleetForge\Attention\Kinds\Kind;
use FleetForge\Attention\Kinds\LeaseReopenedKind;
use FleetForge\Attention\Kinds\QuickBooksConnectionKind;
use FleetForge\Attention\Kinds\QuickBooksDriftKind;
use FleetForge\Attention\Kinds\QuickBooksFailedKind;
use FleetForge\Attention\Kinds\TaxFilingKind;
use FleetForge\Attention\Kinds\UnitHealthKind;

final class KindRegistry
{
    /** Settings key holding the per-kind overrides (JSON). */
    public const SETTING = 'notifications.kind_overrides';

    /** Valid WhatsApp modes. */
    public const WHATSAPP_MODES = ['now', 'summary', 'off'];

    /** Settings grouping, in display order. */
    public const AREAS = [
        'customers' => 'Customers',
        'money'     => 'Money',
        'fleet'     => 'Fleet',
        'system'    => 'System',
    ];

    /** @var array<string, Kind>|null */
    private static ?array $kinds = null;

    /**
     * All kinds, keyed by key(), in display order.
     *
     * @return array<string, Kind>
     */
    public static function all(): array
    {
        if (self::$kinds !== null) {
            return self::$kinds;
        }
        $list = [
            new CreditApplicationKind(),
            new CustomerRequestKind(),
            new EmailBounceKind(),
            new CustomerAccountKind(),
            new BatchRunKind(),
            new BillingCycleKind(),
            new TaxFilingKind(),
            new ComplianceKind(),
            new LeaseReopenedKind(),
            new DamageClaimKind(),
            new UnitHealthKind(),
            new GpsBatteryKind(),
            new QuickBooksConnectionKind(),
            new QuickBooksFailedKind(),
            new QuickBooksDriftKind(),
            // WHY event-only + super admin only: the nightly counter check
            // (cron/reconcile_counters.php) is an internal consistency alarm
            // whose fix is an investigation + a CLI script. It used to post a
            // fresh notification to everyone every night; now it's one item,
            // refreshed in place while drift persists, closed by the next
            // clean run.
            new EventKind(
                'counter_drift',
                'Balance check',
                'The nightly check found customer balances or lease invoiced totals that don\'t match their invoices. Closes on the next clean check.',
                'reconciliation',
                [],
                'system',
                'todo'
            ),
        ];
        self::$kinds = [];
        foreach ($list as $k) {
            self::$kinds[$k->key()] = $k;
        }
        return self::$kinds;
    }

    /**
     * One kind by key.
     *
     * @param  string $key
     * @return Kind|null
     */
    public static function get(string $key): ?Kind
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The saved overrides (sanitized).
     *
     * @return array<string, array>
     */
    public static function overrides(): array
    {
        $raw = \settings_get(self::SETTING, null);
        $d   = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    /**
     * Roles that see a kind (super_admin implied, never listed).
     *
     * @param  Kind $kind
     * @return string[]
     */
    public static function rolesFor(Kind $kind): array
    {
        $o = self::overrides()[$kind->key()]['roles'] ?? null;
        $roles = is_array($o) ? $o : $kind->defaultRoles();
        return array_values(array_filter(array_map('strval', $roles), static fn($r) => $r !== '' && $r !== 'super_admin'));
    }

    /**
     * Effective priority for a new/refreshed item.
     *
     * @param  Kind        $kind
     * @param  string|null $itemPriority  The kind's own per-item decision
     * @return string urgent|todo
     */
    public static function priorityFor(Kind $kind, ?string $itemPriority): string
    {
        if ($kind->dynamicPriority()) {
            return $itemPriority === 'urgent' ? 'urgent' : 'todo';
        }
        $o = self::overrides()[$kind->key()]['priority'] ?? null;
        if ($o === 'urgent' || $o === 'todo') {
            return $o;
        }
        return $kind->defaultPriority() === 'urgent' ? 'urgent' : 'todo';
    }

    /**
     * WhatsApp mode for a kind.
     *
     * @param  Kind $kind
     * @return string now|summary|off
     */
    public static function whatsappFor(Kind $kind): string
    {
        $o = self::overrides()[$kind->key()]['whatsapp'] ?? null;
        return in_array($o, self::WHATSAPP_MODES, true) ? $o : $kind->defaultWhatsApp();
    }

    /**
     * Kind keys visible to a role (all of them for super_admin).
     *
     * @param  string $roleSlug
     * @return string[]
     */
    public static function kindsForRole(string $roleSlug): array
    {
        $out = [];
        foreach (self::all() as $key => $kind) {
            if ($roleSlug === 'super_admin' || in_array($roleSlug, self::rolesFor($kind), true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Validate and save overrides from the Settings page. Only values that
     * differ from the kind's defaults are stored, so a later change to a
     * default still reaches kinds nobody customised.
     *
     * @param  array    $input   kind => {roles?: string[], priority?: string, whatsapp?: string}
     * @param  string[] $validRoles
     * @param  int|null $userId  Who saved (settings.updated_by)
     * @return array<string, array> What was stored
     */
    public static function saveOverrides(array $input, array $validRoles, ?int $userId): array
    {
        $store = [];
        foreach (self::all() as $key => $kind) {
            $in = $input[$key] ?? null;
            if (!is_array($in)) {
                continue;
            }
            $row = [];
            if (isset($in['roles']) && is_array($in['roles'])) {
                $roles = array_values(array_unique(array_filter(
                    array_map('strval', $in['roles']),
                    static fn($r) => in_array($r, $validRoles, true) && $r !== 'super_admin'
                )));
                sort($roles);
                $def = $kind->defaultRoles();
                sort($def);
                if ($roles !== $def) {
                    $row['roles'] = $roles;
                }
            }
            if (!$kind->dynamicPriority() && in_array($in['priority'] ?? null, ['urgent', 'todo'], true)
                && $in['priority'] !== $kind->defaultPriority()) {
                $row['priority'] = $in['priority'];
            }
            if (in_array($in['whatsapp'] ?? null, self::WHATSAPP_MODES, true) && $in['whatsapp'] !== $kind->defaultWhatsApp()) {
                $row['whatsapp'] = $in['whatsapp'];
            }
            if ($row) {
                $store[$key] = $row;
            }
        }

        $json = json_encode((object) $store);
        \db_execute(
            "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, updated_by)
             VALUES (?, ?, 'json', 'attention', 'Needs attention: per-kind settings',
                     'Roles / priority / WhatsApp overrides per kind (Settings → Notifications). Only non-default values are stored.', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)",
            [self::SETTING, $json, $userId]
        );
        return $store;
    }

    /**
     * Reset the in-process kind cache (tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$kinds = null;
    }
}
