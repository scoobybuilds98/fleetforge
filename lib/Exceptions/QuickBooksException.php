<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksException.php
 *
 * Base exception class for every QBO API failure surface. Concrete
 * subclasses map to the categories defined in
 * FLEETFORGE_QUICKBOOKS_SPEC.md §13.1 — auth_expired, validation_failed,
 * stale_object, duplicate_name, forbidden, not_found, transient_5xx,
 * rate_limited, and unknown.
 *
 * Carries the QBO-side error code, HTTP status, and the parsed
 * Fault.Error block from the response so callers can switch on the
 * specifics without re-parsing the response.
 *
 * @session  S-QBO-2
 * @spec     §13.1 (categorization), §13.3 (Fault parsing)
 */

namespace FleetForge\Exceptions;

use RuntimeException;
use Throwable;

class QuickBooksException extends RuntimeException
{
    /** QBO-side error code (e.g. '5010', '6240') or null when no Fault was returned. */
    public readonly ?string $errorCode;

    /** HTTP status code from the response (or null for transport-level failures). */
    public readonly ?int $httpStatus;

    /**
     * Parsed Fault.Error[0] block from the response (or null when no
     * Fault was returned). Keys: code, Message, Detail, element.
     */
    public readonly ?array $faultDetail;

    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $httpStatus = null,
        ?array $faultDetail = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode   = $errorCode;
        $this->httpStatus  = $httpStatus;
        $this->faultDetail = $faultDetail;
    }
}
