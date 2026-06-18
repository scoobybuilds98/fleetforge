<?php
declare(strict_types=1);

namespace FleetForge\AI;

use FleetForge\AI\Actions\StatusActions;
use FleetForge\AI\Actions\FinancialActions;
use FleetForge\AI\Actions\ActionException;

/**
 * lib/AI/ActionRegistry.php
 *
 * S-AI-ACTION-1 — Registry of LIFECYCLE actions the AI may propose (distinct
 * from WriteRegistry field edits). An action is a state transition with side
 * effects (status logs, notifications, and — for the deferred financial tier —
 * accounting). Each entry declares how to resolve the target, how to PREVIEW
 * the action for the confirm card, and how to RUN it (delegating to a shared
 * service so the canonical endpoint and the AI path never drift).
 *
 * Actions are NOT undoable from the card (you don't "un-decommission" by
 * reversing a column) — the proposal is marked undoable=false.
 *
 * Shipped: change_equipment_status (safe — no money/email/accounting).
 * Deferred (financial blast radius, need accounting-aware extraction):
 *   void/send invoice, void payment, close lease, confirm/cancel reservation,
 *   work-order status. Each becomes one entry here once its service exists.
 *
 * @session S-AI-ACTION-1
 */
class ActionRegistry
{
    /**
     * @return array<string,array>  keyed by action name.
     *   Each: label, entity, table, permission, resolve_column, label_column,
     *   soft_delete, preview(record,params)→['summary'] | ['error'],
     *   run(record,params,userId,userName,ip)→result array (may throw ActionException).
     */
    private static function actions(): array
    {
        return [
            'change_equipment_status' => [
                'label'          => 'change equipment status',
                'entity'         => 'equipment_unit',
                'table'          => 'equipment_units',
                'permission'     => 'equipment',
                'resolve_column' => 'unit_number',
                'label_column'   => 'unit_number',
                'soft_delete'    => true,
                'statuses'       => ['available', 'reserved', 'maintenance', 'inactive', 'decommissioned'],
                'preview' => static function (array $rec, array $params): array {
                    $new = strtolower(trim((string) ($params['new_status'] ?? '')));
                    $old = (string) $rec['status'];
                    $all = ['available', 'reserved', 'on_lease', 'maintenance', 'inactive', 'decommissioned'];
                    if (!in_array($new, $all, true)) {
                        return ['error' => "\"{$new}\" isn't a valid status. Valid: available, reserved, maintenance, inactive, decommissioned."];
                    }
                    if ($new === $old) {
                        return ['error' => "Unit {$rec['unit_number']} is already \"{$old}\" — nothing to change."];
                    }
                    $allowed = StatusActions::UNIT_STATUS_TRANSITIONS[$old] ?? [];
                    if (!in_array($new, $allowed, true)) {
                        $okList = $allowed ? implode(', ', $allowed) : 'none (terminal state)';
                        return ['error' => "Unit {$rec['unit_number']} can't go from \"{$old}\" to \"{$new}\". Allowed from \"{$old}\": {$okList}."];
                    }
                    $reason = trim((string) ($params['reason'] ?? ''));
                    $summary = "Change status of unit {$rec['unit_number']}: {$old} → {$new}" . ($reason !== '' ? " (reason: {$reason})" : '');
                    return ['summary' => $summary, 'params' => ['new_status' => $new, 'reason' => $reason ?: null]];
                },
                'run' => static function (array $rec, array $params, int $userId, string $userName, ?string $ip): array {
                    return StatusActions::changeEquipmentStatus(
                        (int) $rec['id'], (string) $params['new_status'], $params['reason'] ?? null,
                        $userId, $userName, $ip
                    );
                },
            ],

            // ── Invoice void (financial — S-AI-ACTION-2) ───────────────
            'void_invoice' => [
                'label'          => 'void invoices',
                'entity'         => 'invoice',
                'table'          => 'invoices',
                'permission'     => 'invoices',
                'resolve_column' => 'invoice_number',
                'label_column'   => 'invoice_number',
                'soft_delete'    => true,
                'preview' => static function (array $rec, array $params): array {
                    $reason = trim((string) ($params['reason'] ?? ''));
                    $status = (string) $rec['status'];
                    if (!in_array($status, ['draft', 'sent'], true)) {
                        return ['error' => "Invoice {$rec['invoice_number']} is '{$status}' and can't be voided — only draft or sent invoices can be voided (a paid invoice needs a credit note instead)."];
                    }
                    if ($reason === '') {
                        return ['error' => "Voiding invoice {$rec['invoice_number']} requires a reason. Ask the user why they're voiding it, then call this again with the reason."];
                    }
                    $amt = number_format((float) $rec['total_amount'], 2);
                    return [
                        'summary' => "Void invoice {$rec['invoice_number']} (status {$status}, \${$amt}) — reason: {$reason}. Reverses its journal entry and balance counters.",
                        'params'  => ['reason' => $reason],
                    ];
                },
                'run' => static function (array $rec, array $params, int $userId, string $userName, ?string $ip): array {
                    return FinancialActions::voidInvoice((int) $rec['id'], (string) ($params['reason'] ?? ''), $userId, $userName, $ip);
                },
            ],

            // ── Invoice send (financial — S-AI-ACTION-2) ───────────────
            'send_invoice' => [
                'label'          => 'send invoices',
                'entity'         => 'invoice',
                'table'          => 'invoices',
                'permission'     => 'invoices',
                'resolve_column' => 'invoice_number',
                'label_column'   => 'invoice_number',
                'soft_delete'    => true,
                'preview' => static function (array $rec, array $params): array {
                    $status = (string) $rec['status'];
                    if ($status !== 'draft') {
                        return ['error' => "Invoice {$rec['invoice_number']} is '{$status}' — only draft invoices can be sent."];
                    }
                    $amt = number_format((float) $rec['total_amount'], 2);
                    return [
                        'summary' => "Send invoice {$rec['invoice_number']} (\${$amt}) — marks it sent, posts the revenue journal entry, and advances the balance counters.",
                        'params'  => [],
                    ];
                },
                'run' => static function (array $rec, array $params, int $userId, string $userName, ?string $ip): array {
                    return FinancialActions::sendInvoice((int) $rec['id'], null, $userId, $userName, $ip);
                },
            ],
        ];
    }

    public static function get(string $action): ?array
    {
        return self::actions()[$action] ?? null;
    }

    public static function names(): array
    {
        return array_keys(self::actions());
    }

    /** Resolve the target record for an action (reuses WriteRegistry's resolver). */
    public static function resolve(array $entry, string $identifier): array
    {
        return WriteRegistry::resolve([
            'table'          => $entry['table'],
            'label'          => $entry['label'],
            'resolve_column' => $entry['resolve_column'],
            'label_column'   => $entry['label_column'],
            'soft_delete'    => $entry['soft_delete'],
        ], $identifier);
    }

    /** Execute an action by name against an already-resolved record id. */
    public static function execute(string $action, int $entityId, array $params, int $userId, string $userName, ?string $ip): array
    {
        $entry = self::get($action);
        if ($entry === null) {
            throw new ActionException('UNSUPPORTED', "Unknown action: {$action}", 422);
        }
        $rec = db_row("SELECT * FROM `{$entry['table']}` WHERE id = ?" . ($entry['soft_delete'] ? ' AND deleted_at IS NULL' : ''), [$entityId]);
        if (!$rec) {
            throw new ActionException('NOT_FOUND', ucfirst($entry['label']) . " target not found.", 404);
        }
        return ($entry['run'])($rec, $params, $userId, $userName, $ip);
    }
}
