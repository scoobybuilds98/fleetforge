<?php
declare(strict_types=1);

/**
 * lib/RateCards/RevisionRefused.php
 *
 * A rate card that RateCardRevision refused to change, with the context the
 * "change prices" preview shows beside the reason (card, customer, dates).
 *
 * @session  S-RATES-MODULE
 */

namespace FleetForge\RateCards;

final class RevisionRefused extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
