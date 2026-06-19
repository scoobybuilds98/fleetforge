<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/WriteRegistry.php
 *
 * S-AI-WRITE-2 — Declarative registry of what the AI may PROPOSE to edit,
 * across every module. One config entry per entity; the generic planner
 * (FleetForgeTools::planUpdateRecord / planBulkUpdateRecords) and the
 * generic applier (ChangeApplier) read from here, so adding a module is a
 * data change, not new code.
 *
 * SAFETY MODEL — what belongs here:
 *   - Only plain METADATA fields: free text, contact info, addresses,
 *     non-financial dates, descriptive enums, locations, simple flags.
 *   - NEVER financial amounts/balances/rates, counters, monotonic *_number
 *     columns, state-machine status columns, or system audit/timestamps.
 *     Those have side effects and dedicated endpoints (void/send/close/
 *     status-change) — the AI reaches them via future ACTION tools, not
 *     generic field edits.
 *
 * Entities deliberately NOT here (need their dedicated write path's rules):
 *   - inspections   — immutable once signed
 *   - mileage_logs  — odometer monotonicity + system-type immutability
 *   - invoices / payments / lease lifecycle / accounting — financial state
 *     machines (edits happen through their own endpoints)
 *
 * Permission: each entry's `permission` is the can($permission,'edit') slug
 * the matching manual update endpoint enforces (verified against
 * api/v1/<entity>/update.php). The apply step re-checks it.
 *
 * @session S-AI-WRITE-2
 */
class WriteRegistry
{
    /** Hard cap on rows a single bulk proposal may touch. */
    public const BULK_MAX = 100;

    /**
     * The registry. Keyed by entity_type (the value the AI passes).
     *
     * Per entry:
     *   label              human noun ("customer")
     *   table              DB table
     *   permission         can(<slug>,'edit') gate
     *   soft_delete        whether the table has deleted_at
     *   audit_module       audit_log.module value
     *   has_updated_by     true only when the table carries an updated_by column
     *                      (equipment_units/customers/reservations/leases). The
     *                      applier MUST omit updated_by for the rest or the write
     *                      throws 1054 (vendors/yards/work orders/damage claims/
     *                      rate cards have no such column).
     *   resolve_column     column matched against the human identifier
     *   label_column       column shown back to the user
     *   fields[]           editable fields → validator spec
     *   bulk_filters[]     columns usable to select rows for a bulk edit
     */
    private static function entities(): array
    {
        return [
            // ── Equipment ───────────────────────────────────────────────
            'equipment_unit' => [
                'label' => 'equipment unit', 'table' => 'equipment_units',
                'permission' => 'equipment', 'soft_delete' => true,
                'audit_module' => 'equipment', 'has_updated_by' => true,
                'resolve_column' => 'unit_number', 'label_column' => 'unit_number',
                'fields' => [
                    'category'       => ['type' => 'template_category', 'column' => 'template_id', 'label' => 'Category'],
                    'year'           => ['type' => 'int', 'min' => 1900, 'max_year_plus' => 1, 'label' => 'Year'],
                    'mileage'        => ['type' => 'int', 'min' => 0, 'label' => 'Mileage'],
                    'license_plate'  => ['type' => 'string', 'max' => 50, 'label' => 'License plate'],
                    'license_state'  => ['type' => 'string', 'max' => 50, 'label' => 'License state/province'],
                    'ownership_type' => ['type' => 'enum', 'values' => ['owned', 'leased', 'brokered'], 'label' => 'Ownership type'],
                    'yard_location'  => ['type' => 'string', 'max' => 100, 'label' => 'Yard location'],
                    'notes'          => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                ],
                'bulk_filters' => ['status', 'ownership_type', 'yard_location', 'year'],
            ],

            // ── Customers ───────────────────────────────────────────────
            'customer' => [
                'label' => 'customer', 'table' => 'customers',
                'permission' => 'customers', 'soft_delete' => true,
                'audit_module' => 'customers', 'has_updated_by' => true,
                'resolve_column' => 'company_name', 'label_column' => 'company_name',
                'fields' => [
                    'contact_name'         => ['type' => 'string', 'max' => 255, 'label' => 'Contact name'],
                    'email'                => ['type' => 'string', 'max' => 255, 'label' => 'Email'],
                    'phone'                => ['type' => 'string', 'max' => 50,  'label' => 'Phone'],
                    'alt_phone'            => ['type' => 'string', 'max' => 50,  'label' => 'Alt phone'],
                    'website'              => ['type' => 'string', 'max' => 500, 'label' => 'Website'],
                    'address'              => ['type' => 'string', 'max' => 500, 'label' => 'Address'],
                    'city'                 => ['type' => 'string', 'max' => 100, 'label' => 'City'],
                    'state'                => ['type' => 'string', 'max' => 100, 'label' => 'State'],
                    'province'             => ['type' => 'string', 'max' => 100, 'label' => 'Province'],
                    'postal_code'          => ['type' => 'string', 'max' => 20,  'label' => 'Postal code'],
                    'country'              => ['type' => 'string', 'max' => 100, 'label' => 'Country'],
                    'payment_terms'        => ['type' => 'string', 'max' => 100, 'label' => 'Payment terms'],
                    'preferred_yard'       => ['type' => 'string', 'max' => 100, 'label' => 'Preferred yard'],
                    'billing_contact_name' => ['type' => 'string', 'max' => 255, 'label' => 'Billing contact name'],
                    'billing_email'        => ['type' => 'string', 'max' => 255, 'label' => 'Billing email'],
                    'billing_phone'        => ['type' => 'string', 'max' => 50,  'label' => 'Billing phone'],
                    'currency'             => ['type' => 'enum', 'values' => ['CAD', 'USD'], 'label' => 'Currency'],
                    'mileage_unit'         => ['type' => 'enum', 'values' => ['km', 'miles'], 'label' => 'Mileage unit'],
                    'notes'                => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                    'internal_notes'       => ['type' => 'string', 'max' => 5000, 'label' => 'Internal notes'],
                    'risk_notes'           => ['type' => 'string', 'max' => 5000, 'label' => 'Risk notes'],
                ],
                'bulk_filters' => [],
            ],

            // ── Vendors (gated by maintenance:edit) ─────────────────────
            'vendor' => [
                'label' => 'vendor', 'table' => 'vendors',
                'permission' => 'maintenance', 'soft_delete' => true,
                'audit_module' => 'maintenance',
                'resolve_column' => 'name', 'label_column' => 'name',
                'fields' => [
                    'contact_name' => ['type' => 'string', 'max' => 255, 'label' => 'Contact name'],
                    'email'        => ['type' => 'string', 'max' => 255, 'label' => 'Email'],
                    'phone'        => ['type' => 'string', 'max' => 50,  'label' => 'Phone'],
                    'address'      => ['type' => 'string', 'max' => 500, 'label' => 'Address'],
                    'city'         => ['type' => 'string', 'max' => 100, 'label' => 'City'],
                    'state'        => ['type' => 'string', 'max' => 100, 'label' => 'State'],
                    'currency'     => ['type' => 'enum', 'values' => ['CAD', 'USD'], 'label' => 'Currency'],
                    'notes'        => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                ],
                'bulk_filters' => [],
            ],

            // ── Yards (gated by settings:edit) ──────────────────────────
            'yard' => [
                'label' => 'yard', 'table' => 'yards',
                'permission' => 'settings', 'soft_delete' => true,
                'audit_module' => 'settings',
                'resolve_column' => 'name', 'label_column' => 'name',
                'fields' => [
                    'address'     => ['type' => 'string', 'max' => 500, 'label' => 'Address'],
                    'city'        => ['type' => 'string', 'max' => 100, 'label' => 'City'],
                    'state'       => ['type' => 'string', 'max' => 100, 'label' => 'State'],
                    'postal_code' => ['type' => 'string', 'max' => 20,  'label' => 'Postal code'],
                    'phone'       => ['type' => 'string', 'max' => 50,  'label' => 'Phone'],
                    'capacity'    => ['type' => 'int', 'min' => 0, 'max' => 65535, 'label' => 'Capacity'],
                    'is_active'   => ['type' => 'bool', 'label' => 'Active'],
                    'notes'       => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                ],
                'bulk_filters' => [],
            ],

            // ── Reservations ────────────────────────────────────────────
            'reservation' => [
                'label' => 'reservation', 'table' => 'reservations',
                'permission' => 'reservations', 'soft_delete' => true,
                'audit_module' => 'reservations', 'has_updated_by' => true,
                'resolve_column' => 'company_name', 'label_column' => 'company_name',
                'fields' => [
                    'contact_name'  => ['type' => 'string', 'max' => 255, 'label' => 'Contact name'],
                    'contact_phone' => ['type' => 'string', 'max' => 50,  'label' => 'Contact phone'],
                    'contact_email' => ['type' => 'string', 'max' => 255, 'label' => 'Contact email'],
                    'pickup_date'   => ['type' => 'date', 'label' => 'Pickup date'],
                    'yard_location' => ['type' => 'string', 'max' => 100, 'label' => 'Yard location'],
                    'purpose'       => ['type' => 'string', 'max' => 500, 'label' => 'Purpose'],
                    'priority'      => ['type' => 'enum', 'values' => ['low', 'medium', 'high', 'urgent'], 'label' => 'Priority'],
                    'notes'         => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                    'internal_notes'=> ['type' => 'string', 'max' => 5000, 'label' => 'Internal notes'],
                ],
                'bulk_filters' => ['status', 'priority', 'yard_location'],
            ],

            // ── Leases (metadata only — never rates/dates/lifecycle) ────
            'lease' => [
                'label' => 'lease', 'table' => 'leases',
                'permission' => 'leases', 'soft_delete' => true,
                'audit_module' => 'leases', 'has_updated_by' => true,
                'resolve_column' => 'contract_number', 'label_column' => 'contract_number',
                'fields' => [
                    'po_number'      => ['type' => 'string', 'max' => 100,  'label' => 'PO number'],
                    'rate_notes'     => ['type' => 'string', 'max' => 5000, 'label' => 'Rate notes'],
                    'notes'          => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                    'internal_notes' => ['type' => 'string', 'max' => 5000, 'label' => 'Internal notes'],
                ],
                'bulk_filters' => [],
            ],

            // ── Maintenance work orders (gated by maintenance:edit) ─────
            'maintenance_work_order' => [
                'label' => 'work order', 'table' => 'maintenance_work_orders',
                'permission' => 'maintenance', 'soft_delete' => true,
                'audit_module' => 'maintenance',
                'resolve_column' => 'work_order_number', 'label_column' => 'work_order_number',
                'fields' => [
                    'title'              => ['type' => 'string', 'max' => 500, 'label' => 'Title'],
                    'description'        => ['type' => 'string', 'max' => 5000, 'label' => 'Description'],
                    'work_type'          => ['type' => 'enum', 'values' => ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'], 'label' => 'Work type'],
                    'priority'           => ['type' => 'enum', 'values' => ['low', 'medium', 'high', 'emergency'], 'label' => 'Priority'],
                    'requested_date'     => ['type' => 'date', 'label' => 'Requested date'],
                    'scheduled_date'     => ['type' => 'date', 'label' => 'Scheduled date'],
                    'mileage_at_service' => ['type' => 'int', 'min' => 0, 'label' => 'Mileage at service'],
                    'notes'              => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                    'internal_notes'     => ['type' => 'string', 'max' => 5000, 'label' => 'Internal notes'],
                ],
                'bulk_filters' => ['status', 'priority', 'work_type'],
            ],

            // ── Damage claims (gated by maintenance:edit; text only) ────
            'damage_claim' => [
                'label' => 'damage claim', 'table' => 'damage_claims',
                'permission' => 'maintenance', 'soft_delete' => true,
                'audit_module' => 'maintenance',
                'resolve_column' => 'claim_number', 'label_column' => 'claim_number',
                'fields' => [
                    'description'      => ['type' => 'string', 'max' => 5000, 'label' => 'Description'],
                    'damage_location'  => ['type' => 'string', 'max' => 255, 'label' => 'Damage location'],
                    'severity'         => ['type' => 'enum', 'values' => ['minor', 'moderate', 'major', 'total_loss'], 'label' => 'Severity'],
                    'notes'            => ['type' => 'string', 'max' => 5000, 'label' => 'Notes'],
                    'resolution_notes' => ['type' => 'string', 'max' => 5000, 'label' => 'Resolution notes'],
                ],
                'bulk_filters' => [],
            ],

            // ── Rate cards (metadata only — items live elsewhere) ───────
            'rate_card' => [
                'label' => 'rate card', 'table' => 'rate_cards',
                'permission' => 'rates', 'soft_delete' => true,
                'audit_module' => 'rates',
                'resolve_column' => 'name', 'label_column' => 'name',
                'fields' => [
                    'name'           => ['type' => 'string', 'max' => 255, 'unique' => true, 'label' => 'Name'],
                    'description'    => ['type' => 'string', 'max' => 5000, 'label' => 'Description'],
                    'effective_from' => ['type' => 'date', 'label' => 'Effective from'],
                    'effective_to'   => ['type' => 'date', 'label' => 'Effective to'],
                ],
                'bulk_filters' => [],
            ],
        ];
    }

    /** Lookup a single entity entry by type. */
    public static function get(string $entityType): ?array
    {
        return self::entities()[$entityType] ?? null;
    }

    /** All registered entity types. */
    public static function types(): array
    {
        return array_keys(self::entities());
    }

    /** A compact entity→fields map for the system prompt. */
    public static function promptSummary(): string
    {
        $lines = [];
        foreach (self::entities() as $type => $e) {
            $fields = implode(', ', array_keys($e['fields']));
            $lines[] = "- {$type} ({$e['label']}, resolve by {$e['resolve_column']}): {$fields}";
        }
        return implode("\n", $lines);
    }

    /**
     * Resolve a human identifier to a single live record.
     *
     * @return array  ['record'=>row]  | ['error'=>msg]  | ['ambiguous'=>[[id,label],...]]
     */
    public static function resolve(array $entry, string $identifier): array
    {
        $table   = $entry['table'];
        $col     = $entry['resolve_column'];
        $labelCol= $entry['label_column'];
        $softSql = $entry['soft_delete'] ? ' AND deleted_at IS NULL' : '';

        $rows = db_select(
            "SELECT * FROM `{$table}` WHERE `{$col}` = ?{$softSql} LIMIT 25",
            [$identifier]
        );

        // Numeric fallback: "reservation 45" / "#45" → match by id.
        if (count($rows) === 0 && preg_match('/^\d+$/', $identifier)) {
            $rows = db_select("SELECT * FROM `{$table}` WHERE id = ?{$softSql} LIMIT 1", [(int) $identifier]);
        }

        if (count($rows) === 0) {
            return ['error' => "No {$entry['label']} found matching \"{$identifier}\". Double-check the identifier."];
        }
        if (count($rows) > 1) {
            $opts = array_map(fn($r) => ['id' => (int) $r['id'], 'label' => (string) ($r[$labelCol] ?? $r['id'])], $rows);
            return ['ambiguous' => $opts];
        }
        return ['record' => $rows[0]];
    }

    /**
     * Validate + normalize a new value for one field against the current row.
     *
     * @return array  ['column','db_value','old_display','new_display']
     *              | ['error'=>msg]
     *              | ['noop'=>true,'message'=>msg]
     */
    public static function validateField(array $entry, string $field, string $newValue, array $record): array
    {
        $def = $entry['fields'][$field] ?? null;
        if ($def === null) {
            $valid = implode(', ', array_keys($entry['fields']));
            return ['error' => "I can only change these fields on a {$entry['label']}: {$valid}."];
        }
        $column = $def['column'] ?? $field;
        $label  = $def['label'] ?? $field;
        $newValue = trim($newValue);

        switch ($def['type']) {
            case 'template_category':
                return self::validateCategory($entry, $record, $newValue, $label, $column);

            case 'int': {
                if (!preg_match('/^-?\d+$/', $newValue)) {
                    return ['error' => "{$label} must be a whole number."];
                }
                $i = (int) $newValue;
                if (isset($def['min']) && $i < $def['min']) return ['error' => "{$label} must be {$def['min']} or more."];
                if (isset($def['max']) && $i > $def['max']) return ['error' => "{$label} must be {$def['max']} or less."];
                if (isset($def['max_year_plus'])) {
                    $maxY = (int) date('Y') + (int) $def['max_year_plus'];
                    if ($i > $maxY) return ['error' => "{$label} must be {$maxY} or less."];
                }
                return self::diff($record, $column, $i, (string) ($record[$column] ?? 'none'), (string) $i, $label);
            }

            case 'decimal': {
                if (!is_numeric($newValue)) return ['error' => "{$label} must be a number."];
                $d = (float) $newValue;
                if (isset($def['min']) && $d < $def['min']) return ['error' => "{$label} must be {$def['min']} or more."];
                return self::diff($record, $column, $newValue, (string) ($record[$column] ?? 'none'), $newValue, $label);
            }

            case 'enum': {
                $v = strtolower($newValue);
                $vals = $def['values'];
                // allow case-insensitive match, return the canonical casing
                $match = null;
                foreach ($vals as $cand) { if (strtolower($cand) === $v) { $match = $cand; break; } }
                if ($match === null) {
                    return ['error' => "{$label} must be one of: " . implode(', ', $vals) . '.'];
                }
                return self::diff($record, $column, $match, (string) ($record[$column] ?? 'none'), $match, $label);
            }

            case 'date': {
                $d = clean_date($newValue);
                if ($d === null && $newValue !== '') return ['error' => "{$label} is not a valid date (use YYYY-MM-DD)."];
                return self::diff($record, $column, $d, (string) ($record[$column] ?: 'none'), (string) ($d ?: 'none'), $label);
            }

            case 'bool': {
                $v = strtolower($newValue);
                if (in_array($v, ['1', 'true', 'yes', 'on', 'active', 'enabled'], true))  $b = 1;
                elseif (in_array($v, ['0', 'false', 'no', 'off', 'inactive', 'disabled'], true)) $b = 0;
                else return ['error' => "{$label} must be yes/no (true/false)."];
                return self::diff($record, $column, $b, ((int) ($record[$column] ?? 0)) ? 'yes' : 'no', $b ? 'yes' : 'no', $label);
            }

            case 'string':
            default: {
                $max = $def['max'] ?? 255;
                $val = clean_string($newValue, $max);
                $oldRaw = (string) ($record[$column] ?? '');
                $clip = static fn(string $s): string => $s === '' ? 'none' : (mb_strlen($s) > 60 ? mb_substr($s, 0, 60) . '…' : $s);
                return self::diff($record, $column, $val, $clip($oldRaw), $clip((string) $val), $label);
            }
        }
    }

    /**
     * Build a diff result. Always carries db_value/new_display (so bulk can
     * reuse it); a `noop` flag tells single-edit to decline an unchanged value.
     */
    private static function diff(array $record, string $column, mixed $dbValue, string $oldDisplay, string $newDisplay, string $label): array
    {
        $cur  = $record[$column] ?? null;
        $noop = ((string) $cur === (string) $dbValue); // loose so "0"==0 and dates match
        return [
            'column' => $column, 'db_value' => $dbValue,
            'old_display' => $oldDisplay, 'new_display' => $newDisplay, 'label' => $label,
            'noop' => $noop,
            'message' => $noop ? "{$label} is already \"{$newDisplay}\" — nothing to change." : '',
        ];
    }

    /**
     * Category lives on equipment_templates — changing it reassigns the unit
     * to a live/active template of that category. Disambiguates when several
     * templates share the category.
     */
    private static function validateCategory(array $entry, array $record, string $newValue, string $label, string $column): array
    {
        $validCats = ['chassis', 'dry_van', 'reefer', 'container', 'flatbed', 'step_deck', 'lowboy', 'tanker', 'dump', 'combo', 'other'];
        $cat = strtolower($newValue);
        if (!in_array($cat, $validCats, true)) {
            return ['error' => "\"{$newValue}\" isn't a valid category. Valid: " . implode(', ', $validCats) . '.'];
        }
        $curTpl = db_row("SELECT category, name FROM equipment_templates WHERE id = ?", [(int) ($record['template_id'] ?? 0)]);
        if ($curTpl && strtolower((string) $curTpl['category']) === $cat) {
            return [
                'column' => 'template_id', 'db_value' => (int) ($record['template_id'] ?? 0),
                'old_display' => $cat, 'new_display' => $cat, 'label' => $label,
                'noop' => true, 'message' => "This unit is already category \"{$cat}\" — nothing to change.",
            ];
        }
        $templates = db_select(
            "SELECT id, name FROM equipment_templates WHERE category = ? AND deleted_at IS NULL AND is_active = 1 ORDER BY name LIMIT 25",
            [$cat]
        );
        if (count($templates) === 0) {
            return ['error' => "There are no active templates in the \"{$cat}\" category, so I can't reassign this unit to it. Create a {$cat} template first."];
        }
        if (count($templates) > 1) {
            $list = implode("\n", array_map(fn($t) => "- {$t['name']} (template #{$t['id']})", $templates));
            return ['error' => "Multiple \"{$cat}\" templates exist. Ask the user which one to use:\n{$list}"];
        }
        $tpl = $templates[0];
        return [
            'column'      => 'template_id',
            'db_value'    => (int) $tpl['id'],
            'old_display' => ($curTpl['category'] ?? 'none') . ' (' . ($curTpl['name'] ?? 'no template') . ')',
            'new_display' => $cat . ' (' . $tpl['name'] . ')',
            'label'       => $label,
            'noop'        => false,
            'message'     => '',
        ];
    }
}
