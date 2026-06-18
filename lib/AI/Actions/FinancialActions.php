<?php
declare(strict_types=1);

namespace FleetForge\AI\Actions;

/**
 * lib/AI/Actions/FinancialActions.php
 *
 * S-AI-ACTION-2 — Financial lifecycle actions, extracted from their API
 * endpoints so the canonical endpoint AND the AI confirm→apply path run the
 * exact same guards, denormalized-counter updates, accounting JE calls
 * (AutoEntryBridge), audit rows, notifications, and QBO enqueue. No drift.
 *
 * High blast radius — each method preserves the endpoint's behavior verbatim:
 *   - voidInvoice:   Path B counters (lease/customer/equipment) + JE reversal
 *                    + last_billed anchor walk-back + QBO void enqueue.
 *
 * Auth/permission are the caller's responsibility.
 *
 * @session S-AI-ACTION-2
 */
class FinancialActions
{
    private const VOIDABLE = ['draft', 'sent'];

    /**
     * Void an invoice (draft or sent only). Mirrors api/v1/invoices/void.php.
     *
     * @throws ActionException  NOT_FOUND / VALIDATION_ERROR / INVALID_TRANSITION
     * @return array{id:int, invoice_number:string, status:string, prev_status:string}
     */
    public static function voidInvoice(int $id, ?string $voidReason, int $userId, string $userName, ?string $ip): array
    {
        $voidReason = $voidReason !== null ? trim($voidReason) : '';
        if ($voidReason === '') {
            throw new ActionException('VALIDATION_ERROR', 'A void reason is required.', 422);
        }

        $invoice = db_row(
            "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$invoice) {
            throw new ActionException('NOT_FOUND', 'Invoice not found.', 404);
        }
        if (!in_array($invoice['status'], self::VOIDABLE, true)) {
            throw new ActionException('INVALID_TRANSITION',
                "Cannot void invoice {$invoice['invoice_number']} with status '{$invoice['status']}'. Only draft or sent invoices can be voided (paid invoices need a credit note).", 409);
        }

        db_transaction(function () use ($id, $invoice, $voidReason, $userId, $userName, $ip): void {
            $preVoidStatus = $invoice['status'];
            $totalAmount   = (string) $invoice['total_amount'];
            $balanceDue    = (string) $invoice['balance_due'];

            // Path B: draft→void OB unchanged; sent→void OB -= balance_due.
            $decOb      = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;
            $decRevenue = ($preVoidStatus === 'sent') ? $totalAmount : '0.00';

            db_update('invoices', [
                'status'      => 'void',
                'balance_due' => '0.00',
                'voided_date' => date('Y-m-d'),
                'void_reason' => $voidReason,
                'voided_by'   => $userId,
                'updated_by'  => $userId,
            ], 'id = ?', [$id]);

            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE leases SET total_invoiced = total_invoiced - ?, outstanding_balance = outstanding_balance - ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $decOb, $invoice['lease_id']]
                );
                // Walk last_billed anchor back to the latest still-live invoice.
                $cov = db_row(
                    "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
                       FROM invoices i2
                      WHERE i2.lease_id = ? AND i2.deleted_at IS NULL AND i2.status <> 'void'
                        AND i2.billing_period_end IS NOT NULL
                      ORDER BY i2.billing_period_end DESC, i2.id DESC LIMIT 1",
                    [$invoice['lease_id']]
                );
                db_execute(
                    "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
                    [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $invoice['lease_id']]
                );
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance - ?, total_revenue = total_revenue - ?, updated_at = NOW() WHERE id = ?",
                    [$decOb, $decRevenue, $invoice['customer_id']]
                );
            }
            if ($decRevenue !== '0.00' && $invoice['lease_id']) {
                db_execute(
                    "UPDATE equipment_units eu
                       JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                        SET eu.total_revenue = eu.total_revenue - ?, eu.updated_at = NOW()",
                    [$invoice['lease_id'], $decRevenue]
                );
            }

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $id,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "Invoice {$invoice['invoice_number']} voided (was {$preVoidStatus}): {$voidReason}. Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
                'old_values'   => json_encode(['status' => $preVoidStatus, 'balance_due' => $balanceDue]),
                'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
                'ip_address'   => $ip ?? '127.0.0.1',
            ]);

            // Reverse the original invoice JE — inside the txn, so a reversal
            // failure rolls back the whole void (A8, §16).
            \FleetForge\Accounting\AutoEntryBridge::onInvoiceVoided($id, $userId);

            try {
                \FleetForge\Notifications\NotificationService::notify(
                    type: 'invoice.voided',
                    title: "Invoice {$invoice['invoice_number']} voided",
                    message: "Invoice {$invoice['invoice_number']} voided: {$voidReason}",
                    entityType: 'invoice', entityId: $id,
                    url: '/fleetforge/invoices/show?id=' . $id, severity: 'warning'
                );
            } catch (\Throwable $e) {
                error_log('[NOTIF invoice.voided] ' . $e->getMessage());
            }
        });

        // Best-effort QBO void enqueue AFTER commit (state durably persisted).
        \FleetForge\QboPushers\InvoiceEnqueuer::enqueue($id, 'void');

        return ['id' => $id, 'invoice_number' => $invoice['invoice_number'], 'status' => 'void', 'prev_status' => $invoice['status']];
    }

    /**
     * Send an invoice (draft → sent). Mirrors api/v1/invoices/send.php: Path B
     * counter increments + revenue JE posting + precharge lifecycle stamp.
     * Does NOT itself email the customer (the DB flip is separate from delivery,
     * which is async); downstream notifications fire.
     *
     * @throws ActionException  NOT_FOUND / INVALID_TRANSITION / PRECHARGE_ALREADY_BILLED
     * @return array{id:int, invoice_number:string, status:string}
     */
    public static function sendInvoice(int $id, ?string $sentToEmailOverride, int $userId, string $userName, ?string $ip): array
    {
        $invoice = db_row(
            "SELECT id, status, invoice_number, customer_email_snapshot, customer_id,
                    company_name_snapshot, total_amount, balance_due, lease_id, due_date
             FROM invoices WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$invoice) {
            throw new ActionException('NOT_FOUND', 'Invoice not found.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            throw new ActionException('INVALID_TRANSITION',
                "Cannot send invoice {$invoice['invoice_number']} with status '{$invoice['status']}'. Only draft invoices can be sent.", 409);
        }

        $override   = ($sentToEmailOverride !== null && function_exists('clean_email')) ? clean_email($sentToEmailOverride) : $sentToEmailOverride;
        $sentToEmail = ($override !== null && $override !== '') ? $override : $invoice['customer_email_snapshot'];
        $now = date('Y-m-d H:i:s');

        db_transaction(function () use ($id, $invoice, $sentToEmail, $now, $userId, $userName, $ip): void {
            // Precharge lifecycle gate (S-MILEAGE-2A D-D)
            $stampPrechargeAfter = false;
            $stampPrechargeLease = null;
            if (!empty($invoice['lease_id'])) {
                $prechargeLine = db_row(
                    "SELECT 1 AS hit FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'mileage_precharge' LIMIT 1",
                    [$id]
                );
                if ($prechargeLine) {
                    $leaseRow = db_row(
                        "SELECT id, contract_number, precharge_invoiced_at, precharge_amount
                           FROM leases WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                        [$invoice['lease_id']]
                    );
                    if (!$leaseRow) {
                        throw new ActionException('NOT_FOUND', 'Lease referenced by invoice not found.', 404);
                    }
                    if ($leaseRow['precharge_invoiced_at'] !== null) {
                        $prior = db_row(
                            "SELECT i.invoice_number FROM invoices i
                               JOIN invoice_line_items li ON li.invoice_id = i.id
                              WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'
                                AND i.id <> ? AND li.item_type = 'mileage_precharge'
                              ORDER BY i.id LIMIT 1",
                            [$invoice['lease_id'], $id]
                        );
                        $priorNum = $prior['invoice_number'] ?? 'an earlier invoice';
                        $amt = $leaseRow['precharge_amount'] !== null ? number_format((float) $leaseRow['precharge_amount'], 2, '.', ',') : '0.00';
                        throw new ActionException('PRECHARGE_ALREADY_BILLED',
                            "Precharge of \${$amt} was already billed on invoice {$priorNum}. Void or strip the mileage_precharge line before sending this one.", 409);
                    }
                    $stampPrechargeAfter = true;
                    $stampPrechargeLease = $leaseRow;
                }
            }

            db_update('invoices', [
                'status'          => 'sent',
                'sent_date'       => date('Y-m-d'),
                'sent_at'         => $now,
                'sent_by'         => $userId,
                'sent_to_email'   => $sentToEmail,
                'delivery_method' => 'email',
                'updated_by'      => $userId,
            ], 'id = ?', [$id]);

            $balanceDue  = (string) $invoice['balance_due'];
            $totalAmount = (string) $invoice['total_amount'];
            if ($invoice['lease_id']) {
                db_execute("UPDATE leases SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?", [$balanceDue, $invoice['lease_id']]);
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers SET outstanding_balance = outstanding_balance + ?, total_revenue = total_revenue + ?, updated_at = NOW() WHERE id = ?",
                    [$balanceDue, $totalAmount, $invoice['customer_id']]
                );
            }
            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE equipment_units eu JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                        SET eu.total_revenue = eu.total_revenue + ?, eu.updated_at = NOW()",
                    [$invoice['lease_id'], $totalAmount]
                );
            }

            if ($stampPrechargeAfter) {
                db_execute("UPDATE leases SET precharge_invoiced_at = ?, updated_at = NOW(), updated_by = ? WHERE id = ?", [$now, $userId, $invoice['lease_id']]);
                db_insert('audit_log', [
                    'user_id' => $userId, 'user_name' => $userName, 'action' => 'update', 'module' => 'leases',
                    'entity_type' => 'lease_precharge_invoiced_at_stamp', 'entity_id' => (int) $invoice['lease_id'],
                    'entity_label' => $stampPrechargeLease['contract_number'] ?? null,
                    'notes' => "Lease precharge_invoiced_at stamped on invoice {$invoice['invoice_number']} send (S-MILEAGE-2A D-D).",
                    'old_values' => json_encode(['precharge_invoiced_at' => null]),
                    'new_values' => json_encode(['precharge_invoiced_at' => $now, 'invoice_id' => $id, 'invoice_number' => $invoice['invoice_number']]),
                    'ip_address' => $ip ?? '127.0.0.1',
                ]);
            }

            db_insert('audit_log', [
                'user_id' => $userId, 'user_name' => $userName, 'action' => 'status_change', 'module' => 'invoices',
                'entity_type' => 'invoice', 'entity_id' => $id, 'entity_label' => $invoice['invoice_number'],
                'notes' => "Invoice {$invoice['invoice_number']} sent (draft → sent). Counter delta: outstanding_balance += {$balanceDue} (Path B).",
                'old_values' => json_encode(['status' => 'draft']),
                'new_values' => json_encode(['status' => 'sent', 'outstanding_balance_delta' => $balanceDue]),
                'ip_address' => $ip ?? '127.0.0.1',
            ]);

            // Revenue JE — posted inside the txn; failure rolls back the send.
            \FleetForge\Accounting\AutoEntryBridge::onInvoiceSent($id, $userId);

            try {
                $companyName = $invoice['company_name_snapshot'] ?? 'customer';
                \FleetForge\Notifications\NotificationService::notify(
                    type: 'invoice.sent', title: "Invoice {$invoice['invoice_number']} sent",
                    message: "Invoice {$invoice['invoice_number']} sent to {$companyName}",
                    entityType: 'invoice', entityId: $id, url: '/fleetforge/invoices/show?id=' . $id
                );
                if (!empty($invoice['customer_id'])) {
                    $amt = '$' . number_format((float) $invoice['total_amount'], 2);
                    \FleetForge\Notifications\NotificationService::notifyPortal(
                        type: 'invoice.sent', customerId: (int) $invoice['customer_id'],
                        title: "Your invoice is ready",
                        message: "Invoice {$invoice['invoice_number']} — {$amt} due " . ($invoice['due_date'] ?? 'on receipt'),
                        entityType: 'invoice', entityId: $id, url: '/fleetforge/portal/invoices/view?id=' . $id
                    );
                }
            } catch (\Throwable $e) {
                error_log('[NOTIF invoice.sent] ' . $e->getMessage());
            }
        });

        \FleetForge\QboPushers\InvoiceEnqueuer::enqueue($id, 'create');

        return ['id' => $id, 'invoice_number' => $invoice['invoice_number'], 'status' => 'sent'];
    }
}
