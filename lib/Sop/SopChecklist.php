<?php
declare(strict_types=1);

/**
 * FleetForge — SOP shared checklists (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopChecklist.php
 * @description The month-end close checklist inside the SOP, shared by the
 *              whole team: one set of ticks per month, each showing who ticked
 *              it and when, next to a LIVE check of the books where one can be
 *              computed (SopSignals).
 *
 *              The items are written in the SOP itself, inside
 *              ":::checklist month_end" in docs/sop/10-month-end-close.md:
 *
 *                ## A | Finish the month's documents | Office
 *                - {leases_closed} Every lease that ended … is **closed**. @/leases
 *
 *              "## letter | title | owner" starts a stage; "- {key} text" is an
 *              item; an optional trailing " @/path" is the screen its Open
 *              button goes to. WHY in the markdown: the checklist and the
 *              procedure it summarises are edited together, so they can never
 *              drift apart. Keys are what ticks are stored against — renaming
 *              a key orphans that item's past ticks, so don't.
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

final class SopChecklist
{
    public const MONTH_END = 'month_end';

    /** Earliest month the period picker offers. */
    private const FIRST_PERIOD = '2022-01';

    // ============================================================
    // Definition (parsed from the SOP markdown)
    // ============================================================

    /**
     * Parse a checklist block body.
     *
     * @return array{stages: list<array{letter:string,title:string,owner:string,items:list<array{key:string,html:string,text:string,href:?string}>}>, keys: list<string>}
     * @throws \RuntimeException on a malformed or duplicate line (caught by the smoke)
     */
    public static function parse(string $body): array
    {
        $stages = [];
        $keys   = [];
        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^##\s+([A-Z])\s*\|\s*([^|]+?)\s*(?:\|\s*(.+))?$/', $line, $m)) {
                $stages[] = ['letter' => $m[1], 'title' => trim($m[2]), 'owner' => trim($m[3] ?? ''), 'items' => []];
                continue;
            }
            if (preg_match('/^-\s+\{([a-z0-9_]+)\}\s+(.+)$/', $line, $m)) {
                if ($stages === []) {
                    throw new \RuntimeException("Checklist item before any '## letter | title' stage: {$line}");
                }
                $key = $m[1];
                if (in_array($key, $keys, true)) {
                    throw new \RuntimeException("Duplicate checklist key '{$key}'");
                }
                $text = $m[2];
                $href = null;
                if (preg_match('/^(.*?)\s+@(\/[A-Za-z0-9_\-\/.?=&#]*)$/', $text, $hm)) {
                    $text = $hm[1];
                    $href = $hm[2];
                }
                $keys[] = $key;
                $stages[count($stages) - 1]['items'][] = [
                    'key'  => $key,
                    'html' => SopRenderer::inline($text),
                    'text' => trim(strip_tags(SopRenderer::inline($text))),
                    'href' => $href,
                ];
                continue;
            }
            throw new \RuntimeException("Unrecognised checklist line: {$line}");
        }
        return ['stages' => $stages, 'keys' => $keys];
    }

    /**
     * The checklist named $key, found in whichever SOP chapter carries it.
     *
     * @return array{stages: list<array>, keys: list<string>, chapter: string}
     */
    public static function definition(string $key): array
    {
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        foreach (SopLibrary::chapters() as $ch) {
            $raw = SopLibrary::body($ch['slug']);
            if (preg_match('/^:::checklist\s+' . preg_quote($key, '/') . '\s*\n(.*?)\n:::\s*$/ms', $raw, $m)) {
                return $cache[$key] = self::parse($m[1]) + ['chapter' => $ch['slug']];
            }
        }
        throw new \InvalidArgumentException("No SOP checklist named '{$key}'");
    }

    // ============================================================
    // Who may see / tick
    // ============================================================

    /**
     * Seeing the live state (ticks + the checks of the books) needs money
     * visibility: the checks talk about drafts, reconciliations and balances.
     * Dispatchers still read the procedure itself.
     */
    public static function canView(): bool
    {
        return can_view_financials();
    }

    /**
     * Ticking is for the people who do the close: invoice editors (office
     * manager) and journal-entry editors (accountant). Read Only can look,
     * not tick.
     */
    public static function canTick(): bool
    {
        return self::canView() && (can('invoices', 'edit') || can('journal_entries', 'edit'));
    }

    // ============================================================
    // Periods
    // ============================================================

    /** The month normally being closed: last month (business timezone). */
    public static function defaultPeriod(): string
    {
        return (new \DateTimeImmutable(ff_today()))->modify('first day of last month')->format('Y-m');
    }

    /** This month — the latest a checklist can be opened for. */
    public static function currentPeriod(): string
    {
        return substr(ff_today(), 0, 7);
    }

    public static function isValidPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)
            && $period >= self::FIRST_PERIOD
            && $period <= self::currentPeriod();
    }

    /** @return array{0:string,1:string} first and last date of the month */
    public static function bounds(string $period): array
    {
        $start = $period . '-01';
        return [$start, (new \DateTimeImmutable($start))->format('Y-m-t')];
    }

    public static function label(string $period): string
    {
        return (new \DateTimeImmutable($period . '-01'))->format('F Y');
    }

    private static function shift(string $period, int $months): string
    {
        return (new \DateTimeImmutable($period . '-01'))->modify(($months >= 0 ? '+' : '') . $months . ' month')->format('Y-m');
    }

    // ============================================================
    // State
    // ============================================================

    /**
     * Ticks for one period: item_key → who / when.
     *
     * @return array<string, array{by:int, by_name:string, at:string}>
     */
    public static function ticks(string $key, string $period): array
    {
        $out = [];
        foreach (db_select(
            "SELECT t.item_key, t.checked_by, t.checked_at, u.name
               FROM sop_checklist_ticks t
               LEFT JOIN users u ON u.id = t.checked_by
              WHERE t.checklist_key = ? AND t.period = ?",
            [$key, $period]
        ) as $r) {
            $out[(string) $r['item_key']] = [
                'by'      => (int) $r['checked_by'],
                'by_name' => (string) ($r['name'] ?? 'Unknown user'),
                'at'      => (string) $r['checked_at'],
            ];
        }
        return $out;
    }

    /**
     * Tick or untick one item. Ticking an item someone already ticked keeps
     * THEIR name and time (INSERT IGNORE): the first person to confirm a step
     * is the one on record.
     *
     * @throws \InvalidArgumentException on an unknown item or period
     */
    public static function set(string $key, string $period, string $itemKey, bool $checked, int $userId): void
    {
        $def = self::definition($key);
        if (!in_array($itemKey, $def['keys'], true)) {
            throw new \InvalidArgumentException("'{$itemKey}' is not an item on this checklist.");
        }
        if (!self::isValidPeriod($period)) {
            throw new \InvalidArgumentException("'{$period}' is not a month the checklist can be opened for.");
        }
        if ($checked) {
            db_execute(
                "INSERT IGNORE INTO sop_checklist_ticks (checklist_key, period, item_key, checked_by, checked_at)
                 VALUES (?, ?, ?, ?, ?)",
                [$key, $period, $itemKey, $userId, ff_now_utc()]
            );
        } else {
            db_execute(
                "DELETE FROM sop_checklist_ticks WHERE checklist_key = ? AND period = ? AND item_key = ?",
                [$key, $period, $itemKey]
            );
        }
    }

    /**
     * Done / total for one period, without the live checks (cheap; the hub
     * tile). Ticks on keys no longer in the checklist are not counted.
     *
     * @return array{done:int, total:int}
     */
    public static function progress(string $key, string $period): array
    {
        $keys = self::definition($key)['keys'];
        $done = count(array_intersect(array_keys(self::ticks($key, $period)), $keys));
        return ['done' => $done, 'total' => count($keys)];
    }

    /**
     * Everything the checklist component draws for one period.
     *
     * @return array<string,mixed>
     */
    public static function payload(string $key, string $period): array
    {
        $def     = self::definition($key);
        $ticks   = self::ticks($key, $period);
        $signals = $key === self::MONTH_END ? SopSignals::forPeriod($period) : [];

        $stages = [];
        $done   = 0;
        $total  = 0;
        foreach ($def['stages'] as $stage) {
            $items     = [];
            $stageDone = 0;
            foreach ($stage['items'] as $item) {
                $tick = $ticks[$item['key']] ?? null;
                $items[] = [
                    'key'     => $item['key'],
                    'html'    => $item['html'],
                    'href'    => $item['href'] !== null ? base_url(ltrim($item['href'], '/')) : null,
                    'ticked'  => $tick !== null,
                    'by_name' => $tick['by_name'] ?? null,
                    'at'      => $tick['at'] ?? null,
                    'signal'  => $signals[$item['key']] ?? null,
                ];
                $stageDone += $tick !== null ? 1 : 0;
            }
            $stages[] = [
                'letter' => $stage['letter'],
                'title'  => $stage['title'],
                'owner'  => $stage['owner'],
                'items'  => $items,
                'done'   => $stageDone,
                'total'  => count($items),
            ];
            $done  += $stageDone;
            $total += count($items);
        }

        $next = self::shift($period, 1);
        $prev = self::shift($period, -1);

        return [
            'key'          => $key,
            'period'       => $period,
            'period_label' => self::label($period),
            'prev'         => $prev >= self::FIRST_PERIOD ? $prev : null,
            'next'         => $next <= self::currentPeriod() ? $next : null,
            'is_december'  => substr($period, 5, 2) === '12',
            'stages'       => $stages,
            'done'         => $done,
            'total'        => $total,
            'can_tick'     => self::canTick(),
            'checked_at'   => ff_now_utc(),
        ];
    }
}
