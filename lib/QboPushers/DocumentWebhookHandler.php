<?php
declare(strict_types=1);

/**
 * lib/QboPushers/DocumentWebhookHandler.php
 *
 * Webhook handling for QuickBooks-side changes to documents FleetForge
 * PUSHED — invoices and credit memos.
 *
 * FleetForge is the source for these (D-QBO-CORE-1): it never rewrites an FF
 * invoice from QBO. But a change made in QuickBooks used to be invisible —
 * the accountant voiding or editing an FF invoice in QBO left the two
 * systems silently disagreeing (FF still collecting on an invoice QBO no
 * longer has, or at a different amount). This handler turns each such change
 * into a drift event + bell notification, and ignores the echoes of FF's own
 * pushes and the routine balance changes payments cause.
 *
 *   Delete           → drift ("deleted in QuickBooks").
 *   Void             → drift, unless FF already voided it (FF's own push).
 *   Create / Update  → re-read the QBO document; drift only when its TOTAL no
 *                      longer matches FF (payments change Balance, not
 *                      TotalAmt, so they never trip this).
 *   Not FF-pushed    → ignored (another business in a shared file, or
 *                      pre-go-live history).
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class DocumentWebhookHandler
{
    /** QBO entity name → [map table, qbo id col, ff fk, ff table, ff total col, ff number col, label]. */
    private const ENTITIES = [
        'Invoice'    => ['acc_qbo_invoice_map', 'qbo_invoice_id', 'ff_invoice_id', 'invoices', 'total_amount', 'invoice_number', 'invoice'],
        'Creditmemo' => ['acc_qbo_credit_memo_map', 'qbo_credit_memo_id', 'ff_credit_note_id', 'credit_notes', 'amount', 'credit_note_number', 'credit note'],
    ];

    /** True when this handler owns events for the (normalized) entity name. */
    public static function handles(string $entityName): bool
    {
        return isset(self::ENTITIES[self::key($entityName)]);
    }

    /**
     * @return array{result: string, detail?: string}
     */
    public static function handle(string $entityName, string $qboId, string $operation, string $realmId): array
    {
        $key = self::key($entityName);
        if (!isset(self::ENTITIES[$key])) {
            return ['result' => 'unsupported_entity'];
        }
        [$map, $qboCol, $ffCol, $ffTable, $totalCol, $numberCol, $label] = self::ENTITIES[$key];

        $connectedRealm = (string) settings_get('quickbooks.realm_id', '');
        if ($connectedRealm === '' || $realmId !== $connectedRealm) {
            return ['result' => 'wrong_realm'];
        }

        $row = db_row(
            "SELECT m.{$ffCol} AS ff_id, ff.status, ff.{$totalCol} AS ff_total, ff.{$numberCol} AS ff_number
               FROM {$map} m
               JOIN {$ffTable} ff ON ff.id = m.{$ffCol}
              WHERE m.{$qboCol} = ?",
            [$qboId]
        );
        if (!$row) {
            return ['result' => 'not_ff', 'detail' => "QBO {$label} {$qboId} was not pushed by FleetForge"];
        }
        $ffId = (int) $row['ff_id'];
        $num  = (string) $row['ff_number'];

        if ($operation === 'Delete') {
            PaymentWebhookHandler::recordQboSideChange(
                $label === 'invoice' ? 'invoice' : 'credit_memo', $ffId, $qboId,
                ucfirst($label) . " {$num} was DELETED in QuickBooks. FleetForge still has it — void it in FleetForge (which keeps both in step), or ask the accountant to restore it in QuickBooks."
            );
            return ['result' => 'drift_recorded', 'detail' => "{$label} {$num} deleted in QBO"];
        }
        if ($operation === 'Void') {
            if ((string) $row['status'] === 'void') {
                return ['result' => 'ff_origin_echo', 'detail' => "{$label} {$num} is already void in FleetForge"];
            }
            PaymentWebhookHandler::recordQboSideChange(
                $label === 'invoice' ? 'invoice' : 'credit_memo', $ffId, $qboId,
                ucfirst($label) . " {$num} was VOIDED in QuickBooks but is still open in FleetForge. Void it in FleetForge too (or ask the accountant to un-void it) — FleetForge is the source for {$label}s."
            );
            return ['result' => 'drift_recorded', 'detail' => "{$label} {$num} voided in QBO"];
        }

        // Create / Update: compare the total. Echoes of FF's own push and
        // payment-driven Balance changes leave TotalAmt equal to FF's.
        try {
            $resp = (new QuickBooksClient())->getEntity(strtolower($key), $qboId);
        } catch (\Throwable $e) {
            return ['result' => 'qbo_pull_failed', 'detail' => $e->getMessage()];
        }
        $doc = $resp[$key === 'Creditmemo' ? 'CreditMemo' : 'Invoice'] ?? null;
        if (!is_array($doc)) {
            return ['result' => 'qbo_not_found'];
        }
        $qboTotal = bcadd((string) ($doc['TotalAmt'] ?? '0'), '0', 2);
        $ffTotal  = bcadd((string) $row['ff_total'], '0', 2);
        if (bccomp($qboTotal, $ffTotal, 2) === 0) {
            return ['result' => 'unchanged'];
        }
        PaymentWebhookHandler::recordQboSideChange(
            $label === 'invoice' ? 'invoice' : 'credit_memo', $ffId, $qboId,
            ucfirst($label) . " {$num} was edited in QuickBooks: QuickBooks total {$qboTotal}, FleetForge total {$ffTotal}. "
            . "FleetForge is the source for {$label}s — make the change in FleetForge (it re-syncs to QuickBooks) or revert it in QuickBooks."
        );
        return ['result' => 'drift_recorded', 'detail' => "{$label} {$num} total changed in QBO"];
    }

    /** 'Invoice' / 'invoice' / 'CreditMemo' / 'creditmemo' → table key. */
    private static function key(string $entityName): string
    {
        return ucfirst(strtolower($entityName));
    }
}
