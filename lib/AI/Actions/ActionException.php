<?php
declare(strict_types=1);

namespace FleetForge\AI\Actions;

/**
 * lib/AI/Actions/ActionException.php
 *
 * S-AI-ACTION-1 — Thrown by action services on a precondition / state-machine
 * failure. Carries a machine code + HTTP-ish status so BOTH callers can map it:
 *   - the real endpoint → json_error($code, $message, $status)
 *   - the AI apply path  → a plain error string shown on the confirm card
 *
 * This is how an action's guard logic is written ONCE and reused by the
 * canonical endpoint and the AI executor without drift.
 *
 * @session S-AI-ACTION-1
 */
class ActionException extends \RuntimeException
{
    public string $errorCode;
    public int $status;

    public function __construct(string $errorCode, string $message, int $status = 409)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status    = $status;
    }
}
