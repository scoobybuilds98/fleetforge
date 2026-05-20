<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksForbiddenException.php
 *
 * Thrown on HTTP 403 or Fault.type='AuthorizationFault'. The token
 * is valid but lacks the required OAuth scope for this resource —
 * typically a sign the OAuth consent didn't include all required
 * scopes ('com.intuit.quickbooks.accounting' and/or
 * 'com.intuit.quickbooks.payment').
 *
 * Permanent failure. Caller surfaces to the operator with the
 * recommendation: "Reconnect with proper scopes."
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "403 | Forbidden"
 */

namespace FleetForge\Exceptions;

class QuickBooksForbiddenException extends QuickBooksException
{
}
