<?php
declare(strict_types=1);

/**
 * lib/RateCards/ConflictGuard.php
 *
 * Guards against a single customer being linked to more than one
 * rate card that covers the SAME equipment type/template over an OVERLAPPING
 * effective period.
 *
 * WHY: rate_cards.customer_id (added by S-RATES-REDESIGN) has only a
 * plain index — no unique key — and the equipment types live in the
 * child table rate_card_items, so the collision cannot be expressed as
 * a DB UNIQUE constraint. Without this guard a customer could own two
 * active cards both defining e.g. `dry_van`, and lookup_rates.php would
 * silently pick one (customer-specific → is_default → newest
 * effective_from) while the other became a dead, shadowed card. This
 * guard turns that silent ambiguity into a hard, explained rejection at
 * write time.
 *
 * SCOPE: customer-specific cards only. Global cards (customer_id IS
 * NULL) are intentionally exempt — multiple globals covering the same
 * type are resolved by precedence (is_default, effective_from) and are
 * a supported pattern (e.g. a default global plus a promo global).
 *
 * S-RATE-CARD-TEMPLATE-ITEM: Items now carry an optional equipment_template_id.
 * The guard checks two kinds of conflict independently:
 *   - Category conflicts:  two cards with the same equipment_type AND template_id=NULL
 *   - Template conflicts:  two cards with the same equipment_template_id (non-null)
 *
 * @decisions D5 (rate_cards soft-deleted — always filtered)
 * @session  S-RATES-CARD-CONFLICT-GUARD, S-RATE-CARD-TEMPLATE-ITEM
 */

namespace FleetForge\RateCards;

final class ConflictGuard
{
    /**
     * Return the rate-card collisions a prospective card would create.
     *
     * Each returned row is one (equipment_type) that is already covered
     * by another active card for the same customer over an overlapping
     * effective window — annotated with that card's id and name so the
     * caller can produce a precise, actionable error message.
     *
     * Two effective windows [from, to] overlap when
     *   thisFrom <= otherTo   (otherTo  NULL = open-ended → +∞)
     *   AND otherFrom <= thisTo (thisTo NULL = open-ended → +∞).
     *
     * @param  int|null    $customerId     Target card's customer (NULL = global → never conflicts).
     * @param  array       $items          Items for the card:
     *                                     [ ['equipment_type'=>'dry_van','equipment_template_id'=>null], ... ]
     * @param  string      $effectiveFrom  Y-m-d start of the card's effective window.
     * @param  string|null $effectiveTo    Y-m-d end, or NULL for open-ended.
     * @param  int|null    $excludeCardId  Card id to exclude (self, on update).
     * @return array<int, array{equipment_type:string, equipment_template_id:int|null, card_id:int, card_name:string}>
     */
    public static function conflicts(
        ?int $customerId,
        array $items,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeCardId = null
    ): array {
        // Global cards and item-less cards can never collide on this rule.
        if ($customerId === null || $items === []) {
            return [];
        }

        // Split into category items (template_id IS NULL) and template items (template_id != NULL).
        $categoryTypes = [];
        $templateIds   = [];
        foreach ($items as $item) {
            if (empty($item['equipment_template_id'])) {
                $categoryTypes[] = $item['equipment_type'];
            } else {
                $templateIds[] = (int)$item['equipment_template_id'];
            }
        }

        $results = [];

        // --- Category conflict query ---
        if ($categoryTypes !== []) {
            $placeholders = implode(',', array_fill(0, count($categoryTypes), '?'));

            $params = [$customerId];
            foreach ($categoryTypes as $type) {
                $params[] = $type;
            }

            $sql = "SELECT rci.equipment_type, rci.equipment_template_id,
                           rc.id AS card_id, rc.name AS card_name
                    FROM rate_card_items rci
                    JOIN rate_cards rc ON rc.id = rci.rate_card_id
                    WHERE rc.customer_id = ?
                      AND rc.deleted_at IS NULL
                      AND rci.equipment_template_id IS NULL
                      AND rci.equipment_type IN ($placeholders)";

            if ($excludeCardId !== null) {
                $sql     .= " AND rc.id != ?";
                $params[] = $excludeCardId;
            }

            $sql     .= " AND (rc.effective_to IS NULL OR rc.effective_to >= ?)";
            $params[] = $effectiveFrom;

            if ($effectiveTo !== null) {
                $sql     .= " AND rc.effective_from <= ?";
                $params[] = $effectiveTo;
            }

            $results = array_merge($results, \db_select($sql, $params));
        }

        // --- Template conflict query ---
        if ($templateIds !== []) {
            $placeholders = implode(',', array_fill(0, count($templateIds), '?'));

            $params = [$customerId];
            foreach ($templateIds as $tid) {
                $params[] = $tid;
            }

            $sql = "SELECT rci.equipment_type, rci.equipment_template_id,
                           rc.id AS card_id, rc.name AS card_name
                    FROM rate_card_items rci
                    JOIN rate_cards rc ON rc.id = rci.rate_card_id
                    WHERE rc.customer_id = ?
                      AND rc.deleted_at IS NULL
                      AND rci.equipment_template_id IN ($placeholders)";

            if ($excludeCardId !== null) {
                $sql     .= " AND rc.id != ?";
                $params[] = $excludeCardId;
            }

            $sql     .= " AND (rc.effective_to IS NULL OR rc.effective_to >= ?)";
            $params[] = $effectiveFrom;

            if ($effectiveTo !== null) {
                $sql     .= " AND rc.effective_from <= ?";
                $params[] = $effectiveTo;
            }

            $results = array_merge($results, \db_select($sql, $params));
        }

        return $results;
    }

    /**
     * Human-readable, one-line-per-collision message for json_validation_error.
     * Returns '' when there are no conflicts.
     *
     * @param array<int, array{equipment_type:string, equipment_template_id:int|null, card_id:int, card_name:string}> $conflicts
     */
    public static function message(array $conflicts): string
    {
        $parts = [];
        foreach ($conflicts as $c) {
            $type = str_replace('_', ' ', $c['equipment_type']);
            if (!empty($c['equipment_template_id'])) {
                $parts[] = sprintf(
                    'A template-specific "%s" row is already covered by rate card "%s" for this customer over an overlapping period.',
                    $type,
                    $c['card_name']
                );
            } else {
                $parts[] = sprintf(
                    '"%s" is already covered by rate card "%s" for this customer over an overlapping period.',
                    $type,
                    $c['card_name']
                );
            }
        }
        return implode(' ', $parts);
    }
}
