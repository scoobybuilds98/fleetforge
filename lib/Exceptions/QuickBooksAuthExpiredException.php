<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksAuthExpiredException.php
 *
 * Thrown on HTTP 401 or Fault.type='AuthenticationFault' AFTER one
 * token refresh has already been attempted in the current request.
 * The first 401 triggers a silent refresh + retry by the retry
 * orchestrator; if the refreshed token still 401s, this exception
 * propagates so the caller can flip connection_status='expired' and
 * prompt the operator to re-authorize.
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "401 | Unauthorized"
 */

namespace FleetForge\Exceptions;

class QuickBooksAuthExpiredException extends QuickBooksException
{
}
