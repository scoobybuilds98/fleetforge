<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

use FleetForge\Billing\BillingExceptions;
use FleetForge\Billing\BillingRateException;
use FleetForge\Billing\InvoiceGenerator;

/**
 * lib/Billing/Cycle/CycleGenerator.php
 *
 * S-BILLING-MODULE-2 — THE per-lease batch generation loop.
 *
 * api/v1/invoices/batch_generate.php (workbench "Generate") and
 * api/v1/invoices/batch_runs/generate.php (generate an approved run) each
 * carried their own copy of the same loop; they now both call run(), so the
 * rules cannot drift apart:
 *
 *   per lease, in order, each isolated (one failure never stops the rest):
 *     1. re-verify it exists, is ACTIVE and bills MONTHLY
 *     2. billing hold → skipped, reported, NOT an exception (held = true)
 *     3. live invoice already overlaps the period → skipped (double-bill guard;
 *        replicates generateForLease()'s assertNoOverlap without its exit)
 *     4. InvoiceGenerator::createFromLease() with the cycle's period-end
 *        readings (CycleReadings::generatorParams) — queued charges are added
 *        by the generator itself (BillingCharges)
 *     5. audit + invoice.created notification
 *   afterwards: every non-hold skip is flagged in the billing-exceptions queue
 *   and every success clears its open exception.
 *
 * Why createFromLease() and not generateForLease(): the latter calls
 * json_error() (echo + exit, uncatchable) on overlap / not-found / inverted
 * period — the first already-billed lease would kill the whole request.
 * createFromLease() keeps ONE residual unconditional json_error (the
 * credit-overflow-cap invariant refusal); it is rare and self-heals on retry
 * (every lease commits its own transaction, and re-runs skip billed leases).
 *
 * @session S-BILLING-MODULE-2 (extracted from S-BATCH-INVOICING / S-BATCH-APPROVAL)
 */
final class CycleGenerator
{
    private function __construct() {}

    /**
     * @param int[] $leaseIds
     * @param array{
     *   user_id?: ?int, user_name?: string, generation_source?: string,
     *   internal_notes?: string, exception_source?: string, batch_run_id?: ?int,
     *   label?: string, notify?: bool, overlap_message?: string
     * } $o
     * @return array{actioned:int, skipped:int, errors:array, invoices:array, flagged:int}
     */
    public static function run(array $leaseIds, string $periodStart, string $periodEnd, array $o = []): array
    {
        $isFullMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                    && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));
        $billingType = $isFullMonth ? 'full_month' : 'single_period';

        $userId   = $o['user_id'] ?? null;
        $userName = $o['user_name'] ?? BillingCycles::actor()['name'];
        $label    = $o['label'] ?? 'Batch Invoicing';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $gen      = new InvoiceGenerator();

        $actioned = 0;
        $skipped  = 0;
        $errors   = [];
        $invoices = [];

        foreach ($leaseIds as $leaseId) {
            $leaseId = (int) $leaseId;
            $lease = \db_row(
                "SELECT l.id, l.contract_number, l.customer_id, l.status, l.billing_cycle, c.company_name
                   FROM leases l
                   JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
                  WHERE l.id = ? AND l.deleted_at IS NULL",
                [$leaseId]
            );
            if (!$lease) {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => 'Lease not found or deleted.'];
                continue;
            }
            if ($lease['status'] !== 'active') {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease is '{$lease['status']}', not active."];
                continue;
            }
            if ($lease['billing_cycle'] !== 'monthly') {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => "Lease bills '{$lease['billing_cycle']}' — the workbench bills monthly leases only."];
                continue;
            }
            if ($hold = BillingHolds::activeFor($leaseId, $periodStart, $periodEnd)) {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => BillingHolds::describe($hold), 'held' => true];
                continue;
            }
            $overlap = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
            if ($overlap) {
                $skipped++;
                $errors[] = [
                    'lease_id' => $leaseId,
                    'reason'   => sprintf($o['overlap_message'] ?? 'Already covered by invoice %s (%s).', $overlap['invoice_number'], $overlap['status']),
                ];
                continue;
            }

            try {
                $result = $gen->createFromLease(CycleReadings::generatorParams($leaseId, $periodStart, $periodEnd) + [
                    'lease_id'             => $leaseId,
                    'period_start'         => $periodStart,
                    'period_end'           => $periodEnd,
                    'billing_type'         => $billingType,
                    'invoice_type'         => 'regular',
                    'generation_source'    => $o['generation_source'] ?? 'manual', // no 'batch' enum value; audit carries provenance
                    'auto_generated'       => ($o['generation_source'] ?? 'manual') === 'cron' ? 1 : 0,
                    'created_by'           => $userId,
                    'require_lease_status' => 'active',
                    'internal_notes'       => $o['internal_notes'] ?? "Generated via {$label} ({$periodStart} to {$periodEnd}).",
                ]);
                $actioned++;
                $invoices[] = [
                    'lease_id'       => $leaseId,
                    'customer_id'    => (int) $lease['customer_id'],
                    'invoice_id'     => (int) $result['invoice_id'],
                    'invoice_number' => (string) $result['invoice_number'],
                    'total_amount'   => (string) $result['total_amount'],
                ];
                \db_insert('audit_log', [
                    'user_id'      => $userId,
                    'user_name'    => $userName,
                    'action'       => 'create',
                    'module'       => 'invoices',
                    'entity_type'  => 'invoice',
                    'entity_id'    => $result['invoice_id'],
                    'entity_label' => $result['invoice_number'],
                    'notes'        => "{$label}: created {$result['invoice_number']} for lease #{$leaseId} ({$lease['contract_number']}, {$lease['company_name']}), period {$periodStart}..{$periodEnd}.",
                    'ip_address'   => $ip,
                ]);
                if ($o['notify'] ?? true) {
                    try {
                        \FleetForge\Notifications\NotificationService::notify(
                            type:       'invoice.created',
                            title:      "New invoice {$result['invoice_number']}",
                            message:    "Invoice {$result['invoice_number']} created for {$lease['company_name']} via {$label} — \$" . number_format((float) $result['total_amount'], 2),
                            entityType: 'invoice',
                            entityId:   (int) $result['invoice_id'],
                            url:        '/fleetforge/invoices/show?id=' . $result['invoice_id']
                        );
                    } catch (\Throwable $e) {
                        error_log('[NOTIF invoice.created] ' . $e->getMessage());
                    }
                }
            } catch (BillingRateException $e) {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => 'No billable rate configured for this period: ' . $e->getMessage()];
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ['lease_id' => $leaseId, 'reason' => $e->getMessage()];
                error_log("[CycleGenerator] Lease #{$leaseId}: " . $e->getMessage());
            }
        }

        $flagged = self::recordOutcome($errors, $invoices, $periodStart, $periodEnd,
            $o['exception_source'] ?? 'batch_generate', $o['batch_run_id'] ?? null, $userId);

        return [
            'actioned' => $actioned,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'invoices' => $invoices,
            'flagged'  => $flagged,
        ];
    }

    /**
     * Persist a run's mixed outcome in the exceptions queue: flag every
     * skip/failure except billing holds (intentional, not a problem), and
     * clear the open exception of every lease that billed. Returns how many
     * were flagged.
     */
    public static function recordOutcome(array $errors, array $invoices, string $periodStart, string $periodEnd,
                                         string $source, ?int $batchRunId, ?int $userId): int
    {
        $flag = array_values(array_filter($errors, static fn($e) => empty($e['held'])));
        $cust = [];
        if ($flag) {
            $ids = array_values(array_unique(array_map(static fn($e) => (int) $e['lease_id'], $flag)));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            foreach (\db_select("SELECT id, customer_id FROM leases WHERE id IN ({$ph})", $ids) as $row) {
                $cust[(int) $row['id']] = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
            }
        }
        foreach ($flag as $err) {
            BillingExceptions::flag((int) $err['lease_id'], $cust[(int) $err['lease_id']] ?? null,
                $periodStart, $periodEnd, (string) $err['reason'], $source, $batchRunId, $userId);
        }
        foreach ($invoices as $inv) {
            BillingExceptions::clear((int) $inv['lease_id'], $periodStart, $periodEnd, $userId);
        }
        return count($flag);
    }
}
