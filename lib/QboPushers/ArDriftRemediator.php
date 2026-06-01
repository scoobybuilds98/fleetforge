<?php
declare(strict_types=1);

/**
 * lib/QboPushers/ArDriftRemediator.php
 *
 * S-QBO-27 AR-drift remediation (spec §16.3, D-QBO-CORE-11). The $17,064.62 AR
 * drift from the 2026-05-07 audit is resolved DURING the historical pull rather
 * than as a standalone Phase A session.
 *
 * Two sub-cases (both detectable purely from FF tables — no QBO needed to
 * DETECT; QBO confirmation gates POSTING):
 *
 *   H5 — LP Logistics (+$20,764.80, GL too LOW): invoices with a subledger
 *        balance but NO DR-AR journal-entry line. The compensating fix posts a
 *        DR-AR JE matching the invoice total (D-QBO-27-4).
 *
 *   H6 — Lepore (−$3,700.18, GL too HIGH): invoice DR-AR JE lines whose debit
 *        is exactly 1.375× (11/8) the invoice total — a deterministic ratio
 *        pointing at an InvoiceGenerator bug, not a data accident. The
 *        compensating fix posts a CR-AR JE for the excess.
 *
 * ── SAFETY: HARD-STOP-AND-REPORT (D-QBO-27-5, locked) ───────────────────────
 * This class DETECTS the anomalies + BUILDS the proposed compensating-JE plan
 * (tagged [A1-FIX-invoice-N]) + REPORTS. It NEVER auto-posts. Posting requires:
 *   1. an explicit operator approval action, AND
 *   2. live mode (HistoricalPuller::assertLiveAllowed — dry_run='0' + sync on),
 * neither of which holds in the machinery-only ship. postApprovedPlan() is the
 * gated seam. Posting a wrong GL entry is worse than a manual click.
 *
 * The H6 root-cause (the InvoiceGenerator 1.375× bug) is a SEPARATE bug
 * investigation surfaced as an operator follow-up — the compensating JE
 * resolves the symptom; the investigation fixes the cause so it can't recur.
 *
 * @session  S-QBO-27
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §16.3
 * @decision D-QBO-27-4 (H5 reconstruction = compensating DR-AR = QBO amounts),
 *           D-QBO-27-5 (H6 stop-gate: hard-stop-and-report; operator approves),
 *           D-QBO-27-6 (AR verification target $0.00 ±$1)
 */

namespace FleetForge\QboPushers;

use FleetForge\Exceptions\QuickBooksException;

class ArDriftRemediator
{
    /** AR control account code (spec §16.3 references GL 1030). */
    public const AR_ACCOUNT_CODE = '1030';

    /** The deterministic H6 inflation ratio: 11/8 = 1.375 (spec §16.3 H6). */
    public const H6_RATIO = '1.375';

    /** Invoice statuses that should carry a DR-AR JE (excludes void/draft). */
    public const AR_INVOICE_STATUSES = ['sent', 'partially_paid', 'paid', 'overdue'];

    /** Resolve the AR control account id. Null = not found (a detect blocker). */
    public static function arAccountId(): ?int
    {
        $row = db_row("SELECT id FROM acc_accounts WHERE code = ?", [self::AR_ACCOUNT_CODE]);
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Detect H5 + H6 anomalies across the invoice subledger vs the GL. Pure FF
     * queries — no QBO. Returns the two anomaly lists + summed amounts + the
     * net drift (H5 raises GL, H6 lowers it).
     *
     * @return array{
     *   ok:bool, reason:?string, ar_account_id:?int,
     *   h5:array<int,array>, h6:array<int,array>,
     *   total_h5:string, total_h6:string, net_drift:string
     * }
     */
    public static function detect(): array
    {
        $arId = self::arAccountId();
        if ($arId === null) {
            return ['ok' => false, 'reason' => "AR control account (code=" . self::AR_ACCOUNT_CODE . ") not found in acc_accounts.",
                'ar_account_id' => null, 'h5' => [], 'h6' => [], 'total_h5' => '0.00', 'total_h6' => '0.00', 'net_drift' => '0.00'];
        }

        $statusPlaceholders = implode(',', array_fill(0, count(self::AR_INVOICE_STATUSES), '?'));

        // For each AR-bearing invoice, the DR-AR posted amount = SUM(debit) on
        // the invoice's posted JE lines to the AR account. LEFT JOIN so missing
        // JEs surface as NULL (H5).
        $rows = db_select(
            "SELECT i.id, i.invoice_number, i.total_amount,
                    COALESCE(SUM(jel.debit), 0) AS dr_ar
               FROM invoices i
               LEFT JOIN acc_journal_entries je
                      ON je.source_type = 'invoice' AND je.source_id = i.id AND je.status = 'posted'
               LEFT JOIN acc_journal_entry_lines jel
                      ON jel.journal_entry_id = je.id AND jel.account_id = ?
              WHERE i.deleted_at IS NULL
                AND i.status IN ({$statusPlaceholders})
                AND i.total_amount > 0
              GROUP BY i.id, i.invoice_number, i.total_amount",
            array_merge([$arId], self::AR_INVOICE_STATUSES)
        );

        $h5 = [];
        $h6 = [];
        $totalH5 = '0.00';
        $totalH6 = '0.00';

        foreach ($rows as $r) {
            $total = bcadd((string) $r['total_amount'], '0', 2);
            $drAr  = bcadd((string) $r['dr_ar'], '0', 2);

            // H5: no DR-AR at all (missing JE) — GL too low by the full total.
            if (bccomp($drAr, '0', 2) === 0) {
                $h5[] = [
                    'invoice_id'     => (int) $r['id'],
                    'invoice_number' => (string) $r['invoice_number'],
                    'total_amount'   => $total,
                    'missing_dr_ar'  => $total,
                    'tag'            => "[A1-FIX-invoice-{$r['id']}]",
                ];
                $totalH5 = bcadd($totalH5, $total, 2);
                continue;
            }

            // H6: DR-AR exactly 1.375× the total — inflated; GL too high by excess.
            $expectedInflated = bcmul($total, self::H6_RATIO, 2);
            if (bccomp($drAr, $expectedInflated, 2) === 0 && bccomp($drAr, $total, 2) !== 0) {
                $excess = bcsub($drAr, $total, 2);
                $h6[] = [
                    'invoice_id'     => (int) $r['id'],
                    'invoice_number' => (string) $r['invoice_number'],
                    'total_amount'   => $total,
                    'posted_dr_ar'   => $drAr,
                    'excess'         => $excess,
                    'tag'            => "[A1-FIX-invoice-{$r['id']}]",
                ];
                $totalH6 = bcadd($totalH6, $excess, 2);
            }
        }

        // Net drift on AR: H5 raises GL (+), H6 lowers it (−).
        $netDrift = bcsub($totalH5, $totalH6, 2);

        return [
            'ok'            => true,
            'reason'        => null,
            'ar_account_id' => $arId,
            'h5'            => $h5,
            'h6'            => $h6,
            'total_h5'      => $totalH5,
            'total_h6'      => $totalH6,
            'net_drift'     => $netDrift,
        ];
    }

    /**
     * Build the proposed compensating-JE plan from a detection result. Each
     * entry is a tagged descriptor — NOT a posted JE. H5 → DR AR (raise GL);
     * H6 → CR AR (lower GL) for the excess (D-QBO-27-4/-5).
     *
     * @return array<int,array{case:string,invoice_id:int,tag:string,direction:string,ar_account_code:string,amount:string}>
     */
    public static function buildPlan(array $detection): array
    {
        $plan = [];
        foreach ($detection['h5'] ?? [] as $h) {
            $plan[] = [
                'case'            => 'H5',
                'invoice_id'      => (int) $h['invoice_id'],
                'invoice_number'  => (string) $h['invoice_number'],
                'tag'             => (string) $h['tag'],
                'direction'       => 'DR',
                'ar_account_code' => self::AR_ACCOUNT_CODE,
                'amount'          => (string) $h['missing_dr_ar'],
            ];
        }
        foreach ($detection['h6'] ?? [] as $h) {
            $plan[] = [
                'case'            => 'H6',
                'invoice_id'      => (int) $h['invoice_id'],
                'invoice_number'  => (string) $h['invoice_number'],
                'tag'             => (string) $h['tag'],
                'direction'       => 'CR',
                'ar_account_code' => self::AR_ACCOUNT_CODE,
                'amount'          => (string) $h['excess'],
            ];
        }
        return $plan;
    }

    /**
     * Run detection + plan against a pull run and record a REPORT. This is the
     * terminal action of the machinery-only ship: it sets remediation_status to
     * 'reported' (or 'stopped' on a blocker) and stores ar_drift_before +
     * remediation_plan. It NEVER posts (D-QBO-27-5).
     *
     * @return array detection + plan + the recorded status
     */
    public static function detectAndReport(int $runId): array
    {
        $detection = self::detect();
        $run = db_row("SELECT id FROM acc_qbo_historical_pull_runs WHERE id = ?", [$runId]);
        if ($run === null) {
            return $detection + ['recorded' => false, 'remediation_status' => 'not_run'];
        }

        if (!$detection['ok']) {
            db_update('acc_qbo_historical_pull_runs', [
                'remediation_status' => 'stopped',
                'error_message'      => 'AR-drift remediation stopped: ' . $detection['reason'],
            ], 'id = ?', [$runId]);
            return $detection + ['recorded' => true, 'remediation_status' => 'stopped'];
        }

        $plan = self::buildPlan($detection);
        $anomalyCount = count($detection['h5']) + count($detection['h6']);
        // Hard-stop-and-report: anomalies present → 'detected'/'reported', never
        // auto-post. No anomalies → still 'reported' with an empty plan.
        $status = $anomalyCount > 0 ? 'reported' : 'reported';

        db_update('acc_qbo_historical_pull_runs', [
            'remediation_status' => $status,
            'ar_drift_before'    => $detection['net_drift'],
            'remediation_plan'   => json_encode($plan, JSON_UNESCAPED_SLASHES),
        ], 'id = ?', [$runId]);

        return $detection + ['recorded' => true, 'remediation_status' => $status, 'plan' => $plan];
    }

    /**
     * GATED SEAM (D-QBO-27-5) — post the operator-approved compensating JEs.
     * Asserts live (dry_run='0' + sync enabled) so it can never run in the
     * machinery-only ship. The actual posting (idempotent on the [A1-FIX-…]
     * tag, balanced against the correct contra account) is implemented against
     * the seeded sandbox with the accountant present (F29).
     *
     * @throws QuickBooksException always under the machinery-only ship
     */
    public static function postApprovedPlan(int $runId): void
    {
        HistoricalPuller::assertLiveAllowed();
        $run = db_row("SELECT remediation_status FROM acc_qbo_historical_pull_runs WHERE id = ?", [$runId]);
        if (($run['remediation_status'] ?? '') !== 'approved') {
            throw new QuickBooksException("Cannot post remediation for run {$runId}: remediation_status must be 'approved' (operator action), got '" . ($run['remediation_status'] ?? 'null') . "'.");
        }
        throw new QuickBooksException(
            "postApprovedPlan() is the F29 live-verify seam — compensating JE posting is executed against the seeded sandbox with the accountant, not in the machinery-only S-QBO-27 ship."
        );
    }
}
