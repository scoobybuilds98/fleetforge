<?php
declare(strict_types=1);

/**
 * lib/Exceptions/QuickBooksValidationException.php
 *
 * Thrown on Fault.type='ValidationFault' excluding the two special
 * cases that get their own subclass — 5010 (StaleObject) + 6240
 * (DuplicateName). Also covers structural validation codes like
 * 2500 (invalid_reference) and 610 (invalid_object).
 *
 * Permanent failure — the entity payload itself is malformed or
 * references something that doesn't exist in QBO. Caller surfaces
 * to the operator via the drift dashboard. Not retried.
 *
 * @session  S-QBO-2
 * @spec     §13.1 row "400 + Fault.type='ValidationFault'"
 */

namespace FleetForge\Exceptions;

class QuickBooksValidationException extends QuickBooksException
{
}
