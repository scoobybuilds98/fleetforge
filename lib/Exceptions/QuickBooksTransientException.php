<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksTransientException.php
 *
 * Thrown when the client's retry budget (default 5 attempts per
 * spec §13.2) is exhausted on a retryable failure category — 5xx,
 * 429, 408, or Fault.type='SystemFault'. Caller's options:
 *   - For ad-hoc test_connection calls: surface to operator UI.
 *   - For queue worker calls (S-QBO-3): mark queue row 'failed',
 *     dispatch notification.
 *
 * Distinct from QuickBooksRateLimitException — that's the internal
 * shape used during the retry loop to carry the Retry-After hint;
 * when retries exhaust it bubbles up as Transient.
 *
 * @session  S-QBO-2
 * @spec     §13.2 (retry budget)
 */

namespace FleetForge\Exceptions;

class QuickBooksTransientException extends QuickBooksException
{
}
