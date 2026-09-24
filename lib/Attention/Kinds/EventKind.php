<?php
declare(strict_types=1);

/**
 * lib/Attention/Kinds/EventKind.php
 *
 * A "Needs attention" kind the system can't re-check in the database: the
 * item is raised by an event (a payment was reversed, a customer's emails
 * bounced, the counter check found drift) and closed by a person — or, for
 * alerts that simply stop firing, auto-resolved after staleAfterDays().
 *
 * Configured by constructor arguments instead of one class per kind, since
 * these kinds differ only in their labels, audience and priority.
 *
 * Required by: lib/Attention/KindRegistry.php
 * Defines:     FleetForge\Attention\Kinds\EventKind
 *
 * @session S-ATTENTION-INBOX
 */

namespace FleetForge\Attention\Kinds;

final class EventKind extends Kind
{
    /**
     * @param string   $key          attention_items.kind value
     * @param string   $label        Settings / filter label
     * @param string   $description  What it is and what closes it
     * @param string   $entityType   entity_type the item is keyed on
     * @param string[] $roles        Default roles (super_admin is implicit)
     * @param string   $area         money|fleet|customers|system
     * @param string   $priority     urgent|todo
     * @param int|null $staleDays    Auto-resolve after N days without a repeat
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $description,
        private readonly string $entityType,
        private readonly array $roles,
        private readonly string $area = 'system',
        private readonly string $priority = 'todo',
        private readonly ?int $staleDays = null,
    ) {
    }

    /** @return string */
    public function key(): string
    {
        return $this->key;
    }

    /** @return string */
    public function label(): string
    {
        return $this->label;
    }

    /** @return string */
    public function description(): string
    {
        return $this->description;
    }

    /** @return string */
    public function entityType(): string
    {
        return $this->entityType;
    }

    /** @return string[] */
    public function defaultRoles(): array
    {
        return $this->roles;
    }

    /** @return string */
    public function area(): string
    {
        return $this->area;
    }

    /** @return string */
    public function defaultPriority(): string
    {
        return $this->priority;
    }

    /** @return int|null */
    public function staleAfterDays(): ?int
    {
        return $this->staleDays;
    }
}
