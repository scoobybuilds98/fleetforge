<?php
declare(strict_types=1);

/**
 * lib/RateCards/ConflictGuard.php
 *
 * Guards against a single customer being linked to more than one
 * rate card that covers the SAME equipment type over an OVERLAPPING
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
 * @decisions D5 (rate_cards soft-deleted — always filtered)
 * @session  S-RATES-CARD-CONFLICT-GUARD
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
     * @param  string[]    $equipmentTypes Equipment-type slugs the card will carry.
     * @param  string      $effectiveFrom  Y-m-d start of the card's effective window.
     * @param  string|null $effectiveTo    Y-m-d end, or NULL for open-ended.
     * @param  int|null    $excludeCardId  Card id to exclude (self, on update).
     * @return array<int, array{equipment_type:string, card_id:int, card_name:string}>
     */
    public static function conflicts(
        ?int $customerId,
        array $equipmentTypes,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeCardId = null
    ): array {
        // Global cards and item-less cards can never collide on this rule.
        if ($customerId === null || $equipmentTypes === []) {
            return [];
        }

        // Build the IN (...) list for the equipment types.
        $placeholders = implode(',', array_fill(0, count($equipmentTypes), '?'));

        $params = [$customerId];
        foreach ($equipmentTypes as $type) {
            $params[] = $type;
        }

        $sql = "SELECT rci.equipment_type, rc.id AS card_id, rc.name AS card_name
                FROM rate_card_items rci
                JOIN rate_cards rc ON rc.id = rci.rate_card_id
                WHERE rc.customer_id = ?
                  AND rc.deleted_at IS NULL
                  AND rci.equipment_type IN ($placeholders)";

        // Exclude the card itself when validating an update.
        if ($excludeCardId !== null) {
            $sql      .= " AND rc.id != ?";
            $params[]  = $excludeCardId;
        }

        // Overlap clause 1: thisFrom <= otherTo (open-ended other = always true).
        $sql      .= " AND (rc.effective_to IS NULL OR rc.effective_to >= ?)";
        $params[]  = $effectiveFrom;

        // Overlap clause 2: otherFrom <= thisTo. When this card is open-ended
        // (thisTo NULL) it extends to +∞, so every later card overlaps and no
        // upper bound is added.
        if ($effectiveTo !== null) {
            $sql      .= " AND rc.effective_from <= ?";
            $params[]  = $effectiveTo;
        }

        return \db_select($sql, $params);
    }

    /**
     * Human-readable, one-line-per-collision message for json_validation_error.
     * Returns '' when there are no conflicts.
     *
     * @param array<int, array{equipment_type:string, card_id:int, card_name:string}> $conflicts
     */
    public static function message(array $conflicts): string
    {
        $parts = [];
        foreach ($conflicts as $c) {
            $type = str_replace('_', ' ', $c['equipment_type']);
            $parts[] = sprintf(
                "“%s” is already covered by rate card “%s” for this customer over an overlapping period.",
                $type,
                $c['card_name']
            );
        }
        return implode(' ', $parts);
    }
}
