<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksDuplicateNameException.php
 *
 * Thrown on Fault.code='6240'. QBO rejects the entity because its
 * DisplayName / Name collides with an existing entity in the realm.
 * Caller (Pusher) decides rename/discriminator logic — typically
 * appending a customer-account-number suffix or asking the operator
 * to link to the existing QBO entity via the mapping UI.
 *
 * Not retried by the client's internal retry loop — the resolution
 * is entity-specific (rename vs link-to-existing) and lives in the
 * customer-mapping flows in S-QBO-5+.
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "400 + Fault.code='6240'"
 */

namespace FleetForge\Exceptions;

class QuickBooksDuplicateNameException extends QuickBooksException
{
}
