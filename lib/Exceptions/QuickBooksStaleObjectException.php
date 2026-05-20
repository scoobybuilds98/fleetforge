<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksStaleObjectException.php
 *
 * Thrown on Fault.code='5010'. The QBO entity has been modified since
 * FF last pulled its SyncToken, so the update with the stored token
 * is rejected. The caller (a Pusher class added in S-QBO-6 onward)
 * is responsible for re-pulling the entity, updating its SyncToken
 * in the corresponding acc_qbo_*_map row, and re-attempting the push.
 *
 * Not retried by the client's internal retry loop — the rebound
 * involves re-pulling the entity which is push-context specific.
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "400 + Fault.code='5010'"
 */

namespace FleetForge\Exceptions;

class QuickBooksStaleObjectException extends QuickBooksException
{
}
