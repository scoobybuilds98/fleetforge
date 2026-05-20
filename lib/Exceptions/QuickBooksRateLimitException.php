<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksRateLimitException.php
 *
 * Internal shape thrown on HTTP 429. Carries the Retry-After hint
 * (in seconds) so the retry orchestrator can honor it instead of
 * the default exponential schedule (per spec §13.2).
 *
 * May or may not propagate to the caller depending on whether the
 * retry budget exhausts — if it does, the orchestrator rethrows
 * as QuickBooksTransientException (so callers don't have to handle
 * two separate exception types for "give up and surface to user").
 *
 * @session  S-QBO-2
 * @spec     §13.2 (Retry-After honoring), §14.2 (rate-limit awareness)
 */

namespace FleetForge\Exceptions;

class QuickBooksRateLimitException extends QuickBooksException
{
    /** Seconds to wait per Retry-After header. Null if header missing. */
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $message,
        ?int $retryAfterSeconds = null,
        ?string $errorCode = null,
        ?int $httpStatus = 429,
        ?array $faultDetail = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $faultDetail, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}
