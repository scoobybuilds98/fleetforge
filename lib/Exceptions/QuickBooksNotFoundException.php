<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksNotFoundException.php
 *
 * Thrown on HTTP 404. The entity ID does not exist in the realm.
 * Conditional semantics — on UPDATE this means the entity was
 * deleted in QBO outside FF's awareness, which surfaces to the
 * drift dashboard in S-QBO-24. On GET it can simply mean the caller
 * is probing an entity that was never mapped.
 *
 * Not retried.
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "404 | Not Found"
 */

namespace FleetForge\Exceptions;

class QuickBooksNotFoundException extends QuickBooksException
{
}
