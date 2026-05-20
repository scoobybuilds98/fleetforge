<?php
declare(strict_types=1);

/**
 * lib/Exceptions/PusherNotImplementedException.php
 *
 * Thrown by QboPusherDispatcher::dispatch() when the convention-based
 * class+method lookup fails to find a Pusher implementation for the
 * (entity_type, operation) pair.
 *
 * Expected condition during Phase QBO-1 (S-QBO-3) and Phase QBO-2/3
 * before the per-entity Pusher sessions (S-QBO-5/6/7+) land. The
 * worker treats this exception specially:
 *   - Marks the queue row as 'failed' with error_code='pusher_not_implemented'
 *   - Does NOT dispatch a failure notification (operator already knows;
 *     spamming during the Phase QBO build-out is counterproductive)
 *   - Does NOT insert a drift_events row (no actual divergence with QBO —
 *     the push simply hasn't been built yet)
 *
 * Distinct from QuickBooksException's other subclasses, which all
 * represent genuine QBO-side or transport-side failures.
 *
 * @session  S-QBO-3
 */

namespace FleetForge\Exceptions;

class PusherNotImplementedException extends QuickBooksException
{
}
