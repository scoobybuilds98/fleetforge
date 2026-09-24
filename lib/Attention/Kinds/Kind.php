<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/Kind.php
 *
 * Base class for one kind of "Needs attention" problem (S-ATTENTION-INBOX).
 *
 * A kind knows:
 *   - what it's called and who handles it by default (roles);
 *   - how urgent it is (fixed, or decided per item — e.g. a compliance
 *     document is to-do while expiring and urgent once expired);
 *   - optionally, how to CHECK the database for the problem ("truth"):
 *       evaluate($id)   → is this one entity a problem right now?
 *       evaluateAll()   → every entity that is a problem right now.
 *     With a truth check the item closes itself the moment the problem is
 *     fixed (the fixing event triggers a re-check) and the hourly sweep
 *     catches anything missed. Without one ("event-only" kinds, e.g. a
 *     payment reversal) the item is raised by the event and closed by a
 *     person, or by staleAfterDays().
 *
 * Item data returned by evaluate()/evaluateAll() (and accepted by
 * AttentionService::raise()):
 *   [
 *     'title'    => string,                      // no money in titles
 *     'facts'    => [['t' => string, 'm' => 0|1]], // m=1 = money, hidden from
 *                                                // users without payments:view
 *     'url'      => string,                      // root-relative, '/fleetforge/...'
 *     'priority' => 'urgent'|'todo',             // only read when dynamicPriority()
 *     'stage'    => ?string,                     // one of stages(); later = worse
 *   ]
 *
 * Required by: lib/Attention/KindRegistry.php, lib/Attention/AttentionService.php
 * Defines:     FleetForge\Attention\Kinds\Kind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

abstract class Kind
{
    /**
     * Registry key stored in attention_items.kind (snake_case, stable).
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * Short label shown in Settings, filters and WhatsApp ("Customer overdue").
     *
     * @return string
     */
    abstract public function label(): string;

    /**
     * One plain sentence: what this is and what closes it (shown in Settings).
     *
     * @return string
     */
    abstract public function description(): string;

    /**
     * entity_type the items are keyed on ('customer', 'equipment_unit', …).
     *
     * @return string
     */
    abstract public function entityType(): string;

    /**
     * Role slugs that see this kind by default. super_admin always sees every
     * kind, so it never needs listing. Overridable in Settings → Notifications.
     *
     * @return string[]
     */
    abstract public function defaultRoles(): array;

    /**
     * Settings grouping: 'money' | 'fleet' | 'customers' | 'system'.
     *
     * @return string
     */
    public function area(): string
    {
        return 'system';
    }

    /**
     * Default priority for kinds whose urgency doesn't depend on the item.
     *
     * @return string 'urgent'|'todo'
     */
    public function defaultPriority(): string
    {
        return 'todo';
    }

    /**
     * True when each item carries its own priority (evaluate() decides) — the
     * Settings page then shows the rule instead of a priority picker.
     *
     * @return bool
     */
    public function dynamicPriority(): bool
    {
        return false;
    }

    /**
     * Human rule shown in Settings for dynamic-priority kinds
     * ("Urgent once a document has expired").
     *
     * @return string
     */
    public function priorityRule(): string
    {
        return '';
    }

    /**
     * Ordered severity stages, least → most severe. When a person marks an
     * item done but the problem is still there, the item only comes back if
     * it reaches a LATER stage (or becomes urgent). Empty = no stages.
     *
     * @return string[]
     */
    public function stages(): array
    {
        return [];
    }

    /**
     * Whether the system can check the database for this problem.
     *
     * @return bool
     */
    public function hasTruth(): bool
    {
        return false;
    }

    /**
     * Check one entity. Only called when hasTruth().
     *
     * @param  int         $entityId
     * @return array|null  Item data when the problem exists now, null when it doesn't
     */
    public function evaluate(int $entityId): ?array
    {
        return null;
    }

    /**
     * Check every entity. Only called when hasTruth().
     *
     * @return array<int, array>  entityId => item data, for every current problem
     */
    public function evaluateAll(): array
    {
        return [];
    }

    /**
     * Event-only kinds: auto-resolve an item that hasn't been seen again for
     * this many days (e.g. a GPS alert that stopped firing). null = never;
     * a person closes it.
     *
     * @return int|null
     */
    public function staleAfterDays(): ?int
    {
        return null;
    }

    /**
     * Whether "Done" needs a short note. True for problems the system can
     * still see: closing one without fixing it must say why ("called — paying
     * Friday"), which is the accountability that stops things quietly going
     * stale. Event-only kinds are "handled" with one click.
     *
     * @return bool
     */
    public function doneNeedsNote(): bool
    {
        return $this->hasTruth();
    }

    /**
     * Default WhatsApp behaviour for this kind: 'now' (instant message when an
     * item opens), 'summary' (only in the morning summary) or 'off'.
     *
     * @return string
     */
    public function defaultWhatsApp(): string
    {
        return $this->defaultPriority() === 'urgent' ? 'now' : 'summary';
    }

    /**
     * Label for a stage value (used in history notes: "Got worse: expired").
     *
     * @param  string|null $stage
     * @return string
     */
    public function stageLabel(?string $stage): string
    {
        return $stage === null ? '' : str_replace('_', ' ', $stage);
    }

    // ── Helpers for subclasses ─────────────────────────────────────────────

    /**
     * Plain fact.
     *
     * @param  string $text
     * @return array{t: string, m: int}
     */
    protected static function fact(string $text): array
    {
        return ['t' => $text, 'm' => 0];
    }

    /**
     * Money fact — dropped for users without payments:view.
     *
     * @param  string $text
     * @return array{t: string, m: int}
     */
    protected static function money(string $text): array
    {
        return ['t' => $text, 'm' => 1];
    }

    /**
     * "$2,545.20" from a DECIMAL string, without float math on the value
     * (D16: money is never a float). Display-only formatting.
     *
     * @param  string|null $amount  DECIMAL string
     * @param  string      $currency ISO code; non-CAD is suffixed
     * @return string
     */
    protected static function fmtMoney(?string $amount, string $currency = 'CAD'): string
    {
        $amount = $amount === null || $amount === '' ? '0.00' : $amount;
        $neg    = str_starts_with($amount, '-');
        $abs    = ltrim($amount, '-');
        $parts  = explode('.', $abs, 2);
        $whole  = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0] === '' ? '0' : $parts[0]);
        $cents  = str_pad(substr($parts[1] ?? '00', 0, 2), 2, '0');
        $out    = ($neg ? '-' : '') . '$' . $whole . '.' . $cents;
        return strtoupper($currency) === 'CAD' ? $out : $out . ' ' . strtoupper($currency);
    }

    /**
     * "30 Sep" / "30 Sep 2027" for a Y-m-d business date (year only when not
     * the current business year).
     *
     * @param  string $ymd
     * @return string
     */
    protected static function fmtDate(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        $thisYear = substr(\ff_today(), 0, 4);
        return date('Y', $ts) === $thisYear ? date('j M', $ts) : date('j M Y', $ts);
    }

    /**
     * Whole days from business-today to $ymd (negative = in the past).
     *
     * @param  string $ymd
     * @return int
     */
    protected static function daysUntil(string $ymd): int
    {
        $today = new \DateTimeImmutable(\ff_today());
        $then  = new \DateTimeImmutable(substr($ymd, 0, 10));
        return (int) $today->diff($then)->format('%r%a');
    }

    /**
     * "1 day" / "5 days".
     *
     * @param  int    $n
     * @param  string $unit singular
     * @return string
     */
    protected static function plural(int $n, string $unit): string
    {
        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }
}
