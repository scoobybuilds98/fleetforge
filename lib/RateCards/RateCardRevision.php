<?php
declare(strict_types=1);

/**
 * lib/RateCards/RateCardRevision.php
 *
 * "Change prices from a date" — for one card or many at once (S-RATES-MODULE).
 *
 * WHY: a price change used to take four manual steps (edit the old card's end
 * date, create a new card, retype every line, pick a name the uniqueness check
 * accepts) — and if the steps were done in the wrong order the conflict guard
 * refused the new card. This does it as one move and keeps the old prices as
 * history instead of overwriting them:
 *
 *   1. the old card ends the day before the new prices start (left alone when
 *      it already ends earlier);
 *   2. a new card starts on that date with the same customer / description /
 *      main-list flag and the new prices — either the old lines raised or
 *      lowered by a percentage (rounded), or lines the caller supplies;
 *   3. the new card ends on the old card's original end date when that is
 *      still ahead (so a fixed-term deal keeps its term), else stays open;
 *   4. both cards get an audit row that points at the other.
 *
 * Leases already on rent keep the prices they were created with — a card
 * never reprices an existing lease.
 *
 * DRY RUN: the preview runs the SAME code inside a transaction that is rolled
 * back, so what the operator is shown is exactly what Apply will do (every
 * validation and the ConflictGuard included). Each card runs inside its own
 * SAVEPOINT, so one card's refusal never leaks into the next card's checks.
 * Apply is all-or-nothing: if any card is refused, nothing is saved.
 *
 * @depends  lib/RateCards/{RateCardItems,ConflictGuard}.php, includes/db.php
 * @session  S-RATES-MODULE
 */

namespace FleetForge\RateCards;

final class RateCardRevision
{
    /** Fields a percentage change touches by default (the rent prices). */
    public const RENT_FIELDS = ['daily_rate', 'weekly_rate', 'monthly_rate'];

    /** Most cards one request may change. */
    public const MAX_CARDS = 100;

    /**
     * Validate the request-level options. Throws with a JSON field map.
     *
     * @param  array<string,mixed> $in raw options
     * @return array<string,mixed> cleaned options
     * @throws \InvalidArgumentException message = JSON {field: message}
     */
    public static function options(array $in): array
    {
        $errors = [];

        $from = RateCardItems::validDate($in['effective_from'] ?? null);
        if ($from === null) {
            $errors['effective_from'] = 'Choose the date the new prices start.';
        }

        $percent = '0';
        if (isset($in['percent']) && $in['percent'] !== '' && $in['percent'] !== null) {
            $p = \clean_decimal(is_scalar($in['percent']) ? (string) $in['percent'] : '');
            if ($p === null || bccomp($p, '-90', 4) < 0 || bccomp($p, '500', 4) > 0) {
                $errors['percent'] = 'The change must be a percentage between -90 and 500.';
            } else {
                $percent = $p;
            }
        }

        $round = (string) ($in['round'] ?? '0.01');
        if (!in_array($round, ['0.01', '1', '5'], true)) {
            $round = '0.01';
        }

        $fields = self::RENT_FIELDS;
        if (!empty($in['all_prices'])) {
            $fields = array_keys(RateCardItems::RATE_LABELS);
        }

        // End of the new card: keep | open | a date.
        $endMode = 'keep';
        $endDate = null;
        $endRaw  = $in['effective_to'] ?? 'keep';
        if ($endRaw === null || $endRaw === '' || $endRaw === 'open') {
            $endMode = 'open';
        } elseif ($endRaw !== 'keep') {
            $endDate = RateCardItems::validDate($endRaw);
            if ($endDate === null) {
                $errors['effective_to'] = 'The end date is not a valid date.';
            } elseif ($from !== null && $endDate < $from) {
                $errors['effective_to'] = 'The end date must be on or after the start date.';
            } else {
                $endMode = 'date';
            }
        }

        if ($errors) {
            throw new \InvalidArgumentException((string) json_encode($errors));
        }

        return [
            'effective_from' => $from,
            'percent'        => $percent,
            'round'          => $round,
            'fields'         => $fields,
            'end_mode'       => $endMode,
            'end_date'       => $endDate,
            'items_by_card'  => is_array($in['items_by_card'] ?? null) ? $in['items_by_card'] : [],
            'names'          => is_array($in['names'] ?? null) ? $in['names'] : [],
            'note'           => \clean_string($in['note'] ?? null, 500),
        ];
    }

    /**
     * Change the prices of $cardIds from $opts['effective_from'].
     *
     * @param  list<int>           $cardIds
     * @param  array<string,mixed> $opts    from options()
     * @return array{ok:bool, applied:bool, results:list<array<string,mixed>>}
     */
    public static function revise(array $cardIds, array $opts, bool $dryRun, ?int $userId, string $userName, string $ip): array
    {
        // One outer savepoint around the whole request (plus our own
        // transaction when none is open) so a dry run — or a refused Apply —
        // undoes exactly this call, whether or not a caller (a test) already
        // holds a transaction.
        $pdo = \db_pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $pdo->exec('SAVEPOINT rc_rev_all');

        $results = [];
        $allOk   = true;
        try {
            foreach (array_values(array_unique(array_map('intval', $cardIds))) as $i => $cardId) {
                $sp = 'rc_rev_' . $i;
                $pdo->exec("SAVEPOINT {$sp}");
                try {
                    $results[] = self::reviseOne($cardId, $opts, $userId, $userName, $ip) + ['status' => 'ok'];
                    $pdo->exec("RELEASE SAVEPOINT {$sp}");
                } catch (RevisionRefused $e) {
                    $pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                    $allOk     = false;
                    $results[] = $e->context + ['card_id' => $cardId, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }

            $apply = !$dryRun && $allOk && $results !== [];
            if ($apply) {
                $pdo->exec('RELEASE SAVEPOINT rc_rev_all');
                if ($own) {
                    $pdo->commit();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT rc_rev_all');
                if ($own) {
                    $pdo->rollBack();
                }
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $applied = !$dryRun && $allOk && $results !== [];
        if (!$applied) {
            // The ids a rolled-back insert handed out do not exist.
            foreach ($results as &$r) {
                $r['new_card_id'] = null;
            }
            unset($r);
        }

        return ['ok' => $allOk, 'applied' => $applied, 'results' => $results];
    }

    /**
     * One card. Throws RevisionRefused with a plain-English reason.
     *
     * @param  array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private static function reviseOne(int $cardId, array $opts, ?int $userId, string $userName, string $ip): array
    {
        $card = \db_row(
            "SELECT rc.*, c.company_name AS customer_name
               FROM rate_cards rc
               LEFT JOIN customers c ON c.id = rc.customer_id AND c.deleted_at IS NULL
              WHERE rc.id = ? AND rc.deleted_at IS NULL",
            [$cardId]
        );
        if (!$card) {
            throw new RevisionRefused('Rate card not found.', ['card_id' => $cardId]);
        }

        $ctx = [
            'card_id'       => $cardId,
            'name'          => (string) $card['name'],
            'customer_id'   => $card['customer_id'] !== null ? (int) $card['customer_id'] : null,
            'customer_name' => $card['customer_id'] !== null ? ($card['customer_name'] ?? 'Archived customer') : null,
            'old_from'      => $card['effective_from'],
            'old_to'        => $card['effective_to'],
        ];

        if ($card['customer_id'] !== null && $card['customer_name'] === null) {
            throw new RevisionRefused('The customer on this card is archived.', $ctx);
        }

        $newFrom = (string) $opts['effective_from'];
        if ($newFrom <= $card['effective_from']) {
            throw new RevisionRefused(
                'The new prices must start after this card started (' . \format_date($card['effective_from']) . ').',
                $ctx
            );
        }

        // Where the old card ends.
        $oldTo    = $card['effective_to'];
        $dayBefore = (new \DateTimeImmutable($newFrom))->modify('-1 day')->format('Y-m-d');
        $oldToNew = ($oldTo === null || $oldTo >= $newFrom) ? $dayBefore : $oldTo;
        $gapDays  = ($oldTo !== null && $oldTo < $dayBefore)
            ? (int) (new \DateTimeImmutable($oldTo))->diff(new \DateTimeImmutable($dayBefore))->days
            : 0;

        // Where the new card ends.
        $newTo = match ($opts['end_mode']) {
            'open'  => null,
            'date'  => $opts['end_date'],
            default => ($oldTo !== null && $oldTo >= $newFrom) ? $oldTo : null,
        };
        if ($newTo !== null && $newTo < $newFrom) {
            throw new RevisionRefused('The end date must be on or after the start date.', $ctx);
        }

        // The lines.
        $oldItems = \db_select(
            "SELECT equipment_type, equipment_template_id, daily_rate, weekly_rate, monthly_rate,
                    mileage_rate, mileage_unit, hourly_rate, gps_price, minimum_days, currency, notes
               FROM rate_card_items WHERE rate_card_id = ? ORDER BY id",
            [$cardId]
        );
        $explicit = $opts['items_by_card'][$cardId] ?? $opts['items_by_card'][(string) $cardId] ?? null;
        if (is_array($explicit)) {
            [$newItems, $errors] = RateCardItems::normalize($explicit);
            if ($errors) {
                throw new RevisionRefused(implode(' ', $errors), $ctx);
            }
        } else {
            $newItems = RateCardItems::adjust($oldItems, (string) $opts['percent'], (string) $opts['round'], $opts['fields']);
        }
        if ($newItems === []) {
            throw new RevisionRefused('This card has no prices to carry over — add prices to it first.', $ctx);
        }
        foreach ($newItems as $it) {
            // D132 — rounding a small price to the nearest $5 can make it $0.
            $trio = array_filter([$it['daily_rate'], $it['weekly_rate'], $it['monthly_rate']],
                static fn ($v) => $v !== null && $v !== '' && bccomp((string) $v, '0', 4) > 0);
            if ($trio !== [] && count($trio) < 3) {
                throw new RevisionRefused('A daily, weekly or monthly price would become $0 — the three must all stay above $0. Try finer rounding.', $ctx);
            }
            foreach (RateCardItems::MAX as $f => $max) {
                if ($it[$f] !== null && $it[$f] !== '' && bccomp((string) $it[$f], $max, 6) > 0) {
                    throw new RevisionRefused(RateCardItems::RATE_LABELS[$f] . ' would be too large.', $ctx);
                }
            }
        }

        // The new card's name: the caller's, or "<old name> · from Oct 2026",
        // made unique among live cards.
        $requested = $opts['names'][$cardId] ?? $opts['names'][(string) $cardId] ?? null;
        $requested = \clean_string(is_string($requested) ? $requested : null, 255);
        if ($requested !== null) {
            if (\db_exists('rate_cards', 'name = ? AND deleted_at IS NULL', [$requested])) {
                throw new RevisionRefused('A rate card named "' . $requested . '" already exists — choose another name.', $ctx);
            }
            $newName = $requested;
        } else {
            $newName = self::suggestName((string) $card['name'], $newFrom);
        }

        // ── Writes (inside the caller's savepoint) ────────────────────────
        $wasDefault = (int) $card['is_default'] === 1;
        \db_update('rate_cards', [
            'effective_to' => $oldToNew,
            'is_default'   => 0,
        ], 'id = ?', [$cardId]);

        $customerId = $card['customer_id'] !== null ? (int) $card['customer_id'] : null;
        $conflicts  = ConflictGuard::conflicts($customerId, $newItems, $newFrom, $newTo, null);
        if ($conflicts) {
            throw new RevisionRefused(ConflictGuard::message($conflicts), $ctx);
        }

        $newId = \db_insert('rate_cards', [
            'name'           => $newName,
            'description'    => $card['description'],
            'is_default'     => $wasDefault ? 1 : 0,
            'effective_from' => $newFrom,
            'effective_to'   => $newTo,
            'customer_id'    => $customerId,
            'created_by'     => $userId,
        ]);
        foreach ($newItems as $it) {
            \db_insert('rate_card_items', ['rate_card_id' => $newId] + $it);
        }

        $note = trim((string) ($opts['note'] ?? ''));
        \db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'rates',
            'entity_type'  => 'rate_card',
            'entity_id'    => $cardId,
            'entity_label' => $card['name'],
            'old_values'   => json_encode(['effective_to' => $oldTo, 'is_default' => (int) $card['is_default']]),
            'new_values'   => json_encode(['effective_to' => $oldToNew, 'is_default' => 0, 'replaced_by' => $newId]),
            'notes'        => 'Prices changed from ' . $newFrom . ' — continued on "' . $newName . '" (#' . $newId . ')' . ($note !== '' ? '. ' . $note : ''),
            'ip_address'   => $ip,
        ]);
        \db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'create',
            'module'       => 'rates',
            'entity_type'  => 'rate_card',
            'entity_id'    => $newId,
            'entity_label' => $newName,
            'new_values'   => json_encode([
                'name'             => $newName,
                'effective_from'   => $newFrom,
                'effective_to'     => $newTo,
                'is_default'       => $wasDefault ? 1 : 0,
                'customer_id'      => $customerId,
                'replaces_card_id' => $cardId,
                'items'            => RateCardItems::snapshot($newItems),
            ]),
            'notes'        => 'New prices from ' . $newFrom . ', replacing "' . $card['name'] . '" (#' . $cardId . ')'
                . ((string) $opts['percent'] !== '0' && !is_array($explicit) ? ' — ' . self::pctLabel((string) $opts['percent']) : '')
                . ($note !== '' ? '. ' . $note : ''),
            'ip_address'   => $ip,
        ]);

        return $ctx + [
            'old_to_new'    => $oldToNew,
            'gap_days'      => $gapDays,
            'new_card_id'   => $newId,
            'new_name'      => $newName,
            'new_from'      => $newFrom,
            'new_to'        => $newTo,
            'was_default'   => $wasDefault,
            'lines'         => self::lineChanges($oldItems, $newItems),
        ];
    }

    /**
     * Before/after prices per line for the preview.
     *
     * @param  list<array<string,mixed>> $old
     * @param  list<array<string,mixed>> $new
     * @return list<array<string,mixed>>
     */
    private static function lineChanges(array $old, array $new): array
    {
        $byKey = [];
        foreach ($old as $o) {
            $byKey[RateCardItems::lineKey($o)] = $o;
        }
        $out = [];
        foreach ($new as $n) {
            $key    = RateCardItems::lineKey($n);
            $before = $byKey[$key] ?? null;
            [$label, $scope] = RateInsights::lineLabels($n);
            $row = ['key' => $key, 'label' => $label, 'scope' => $scope, 'currency' => $n['currency'] ?? 'CAD', 'mileage_unit' => $n['mileage_unit'] ?? 'km', 'before' => [], 'after' => []];
            foreach (array_keys(RateCardItems::RATE_LABELS) as $f) {
                $row['before'][$f] = $before[$f] ?? null;
                $row['after'][$f]  = $n[$f] ?? null;
            }
            $row['before']['minimum_days'] = $before['minimum_days'] ?? null;
            $row['after']['minimum_days']  = $n['minimum_days'] ?? null;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * "<name> · from Oct 2026", with a previous " · from …" suffix replaced and
     * a counter added until no live card has the name.
     */
    public static function suggestName(string $oldName, string $from): string
    {
        $base = (string) preg_replace('/\s+·\s+from\s+[A-Z][a-z]{2}\s+\d{4}(\s+\(\d+\))?$/u', '', $oldName);
        $base = mb_substr(trim($base) !== '' ? trim($base) : 'Rate card', 0, 220);
        $stem = $base . ' · from ' . date('M Y', strtotime($from));
        $name = $stem;
        for ($n = 2; \db_exists('rate_cards', 'name = ? AND deleted_at IS NULL', [$name]); $n++) {
            $name = $stem . ' (' . $n . ')';
        }
        return $name;
    }

    /** "+5 %" / "−3.5 %" for notes. */
    private static function pctLabel(string $pct): string
    {
        $clean = rtrim(rtrim($pct, '0'), '.');
        return (bccomp($pct, '0', 4) > 0 ? '+' : '') . $clean . '%';
    }
}
