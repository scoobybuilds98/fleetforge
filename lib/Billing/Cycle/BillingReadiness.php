<?php
declare(strict_types=1);

namespace FleetForge\Billing\Cycle;

use FleetForge\Billing\PaymentTerms;

/**
 * lib/Billing/Cycle/BillingReadiness.php
 *
 * S-BILLING-MODULE — the pre-billing audit for one cycle.
 *
 * Every check is a question about the month's leases and customers whose
 * wrong answer would put a wrong (or no) number on an invoice, or stop it
 * reaching the customer. Each returns:
 *   key, title, severity (blocker | warning | info), why (what goes wrong),
 *   fix (what to do), count, items[{label, detail, url}], acknowledged.
 *
 *   blocker  the generator will refuse or bill nothing — fix it first.
 *            Cannot be acknowledged.
 *   warning  billing will run but the invoice or its delivery is likely
 *            wrong. Fix, or acknowledge ("we know, bill anyway") — the
 *            acknowledgement is recorded on the cycle with who and when.
 *   info     worth knowing before generating; nothing to fix.
 *
 * Checks are read-only and each runs in its own try/catch: one failing
 * query degrades that check to an "error" row, never the whole page.
 *
 * The universe is BillingCycles::universe() — leases on rent during the
 * month. Nothing here duplicates a fix screen: every item links to the
 * page where the thing is actually fixed (lease edit, customer edit,
 * Samsara mapping, exchange rates, the cycle's own Readings tab, ...).
 *
 * @session S-BILLING-MODULE
 */
final class BillingReadiness
{
    private function __construct() {}

    /** Months of history the "earlier months unbilled" check walks back. */
    private const GAP_LOOKBACK_MONTHS = 36;

    /** Items listed per check (the count is always exact). */
    private const MAX_ITEMS = 200;

    /**
     * Run every check, persist the summary on the cycle, return the report.
     *
     * @return array{checks: array, summary: array{blocker:int, warning:int, info:int, acknowledged:int}, checked_at: string}
     */
    public static function run(array $cycle): array
    {
        ['checks' => $checks, 'summary' => $summary] = self::evaluate($cycle);

        $now = \ff_now_utc();
        try {
            \db_execute(
                "UPDATE billing_cycles SET readiness_checked_at = ?, readiness_summary = ? WHERE id = ?",
                [$now, json_encode($summary), $cycle['id']]
            );
        } catch (\Throwable $e) {
            error_log('[BillingReadiness] could not persist summary: ' . $e->getMessage());
        }

        return ['checks' => $checks, 'summary' => $summary, 'checked_at' => $now];
    }

    /**
     * Run every check WITHOUT saving anything — the read half of run().
     *
     * WHY split (S-AI-KNOWLEDGE): the AI assistant's get_billing_cycle tool
     * must be read-only, but run() stamps readiness_checked_at on the cycle
     * (which also marks the Prepare step as done). evaluate() gives the exact
     * same report without the write, so there's one copy of the 27 checks.
     *
     * @return array{checks: array, summary: array{blocker:int, warning:int, info:int, acknowledged:int, passed:int}}
     */
    public static function evaluate(array $cycle): array
    {
        $ctx = [
            'cycle'   => $cycle,
            'start'   => (string) $cycle['period_start'],
            'end'     => (string) $cycle['period_end'],
            'today'   => \ff_today(),
            'u'       => "l.deleted_at IS NULL AND l.start_date <= ?
                          AND (l.status = 'active'
                               OR (l.status = 'completed' AND COALESCE(l.actual_return_date, l.end_date, l.start_date) >= ?))",
            'up'      => [(string) $cycle['period_end'], (string) $cycle['period_start']],
        ];

        $defs = [
            'rates_missing'            => [self::class, 'checkRatesMissing'],
            'fx_rate_missing'          => [self::class, 'checkFxRate'],
            'earlier_months_unbilled'  => [self::class, 'checkEarlierMonths'],
            'readings_missing'         => [self::class, 'checkReadings'],
            'mileage_mode_off'         => [self::class, 'checkMileageModeOff'],
            'samsara_unmapped'         => [self::class, 'checkSamsaraUnmapped'],
            'samsara_stale'            => [self::class, 'checkSamsaraStale'],
            'lease_overrun'            => [self::class, 'checkOverrun'],
            'returned_not_closed'      => [self::class, 'checkReturnedNotClosed'],
            'closed_unbilled'          => [self::class, 'checkClosedUnbilled'],
            'no_recipient'             => [self::class, 'checkNoRecipient'],
            'email_bounced'            => [self::class, 'checkEmailBounced'],
            'po_missing'               => [self::class, 'checkPoMissing'],
            'tax_exemption_expired'    => [self::class, 'checkTaxExemption'],
            'province_missing'         => [self::class, 'checkProvince'],
            'accounting_period_closed' => [self::class, 'checkAccountingPeriod'],
            'drafts_earlier'           => [self::class, 'checkEarlierDrafts'],
            'exceptions_earlier'       => [self::class, 'checkEarlierExceptions'],
            'qbo_invoice_failures'     => [self::class, 'checkQboFailures'],
            'non_email_delivery'       => [self::class, 'checkNonEmailDelivery'],
            'due_on_send'              => [self::class, 'checkDueOnSend'],
            'on_close_only'            => [self::class, 'checkOnCloseOnly'],
            'holds_active'             => [self::class, 'checkHolds'],
            'credit_hold'              => [self::class, 'checkCreditHold'],
            'rate_amendments'          => [self::class, 'checkAmendments'],
            'approval_pending'         => [self::class, 'checkPendingRuns'],
            'charges_due'              => [self::class, 'checkChargesDue'],
        ];

        $acks = is_array($cycle['readiness_ack'] ?? null) ? $cycle['readiness_ack'] : [];
        $checks = [];
        foreach ($defs as $key => $fn) {
            try {
                $c = $fn($ctx);
            } catch (\Throwable $e) {
                error_log("[BillingReadiness] {$key} failed: " . $e->getMessage());
                $c = self::make('warning', 'Check could not run', 'This check hit an error and was skipped.',
                    'Tell an administrator; the error is in the server log.', []);
                $c['error'] = true;
            }
            $c['key'] = $key;
            $c['acknowledged'] = ($c['severity'] === 'warning' && $c['count'] > 0 && isset($acks[$key])) ? $acks[$key] : null;
            $checks[] = $c;
        }

        $summary = ['blocker' => 0, 'warning' => 0, 'info' => 0, 'acknowledged' => 0, 'passed' => 0];
        foreach ($checks as $c) {
            if ($c['count'] === 0) { $summary['passed']++; continue; }
            if ($c['acknowledged']) { $summary['acknowledged']++; continue; }
            $summary[$c['severity']]++;
        }

        // Severity order, then biggest first — the page reads top-down.
        $rank = ['blocker' => 0, 'warning' => 1, 'info' => 2];
        usort($checks, static function ($a, $b) use ($rank) {
            $ao = $a['count'] === 0 ? 3 : ($a['acknowledged'] ? 2.5 : $rank[$a['severity']]);
            $bo = $b['count'] === 0 ? 3 : ($b['acknowledged'] ? 2.5 : $rank[$b['severity']]);
            return [$ao, -$a['count']] <=> [$bo, -$b['count']];
        });

        return ['checks' => $checks, 'summary' => $summary];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private static function make(string $severity, string $title, string $why, string $fix, array $items, ?int $count = null): array
    {
        return [
            'severity' => $severity,
            'title'    => $title,
            'why'      => $why,
            'fix'      => $fix,
            'count'    => $count ?? count($items),
            'items'    => array_slice($items, 0, self::MAX_ITEMS),
        ];
    }

    private static function leaseItem(array $r, string $detail, ?string $url = null): array
    {
        return [
            'label'  => trim(($r['contract_number'] ?? '') . ' · ' . ($r['company_name'] ?? ''), ' ·'),
            'detail' => $detail,
            'url'    => $url ?? (\base_url('leases/show') . '?id=' . (int) $r['id']),
        ];
    }

    private static function customerItem(array $r, string $detail, string $tab = ''): array
    {
        return [
            'label'  => (string) $r['company_name'],
            'detail' => $detail,
            'url'    => \base_url('customers/edit') . '?id=' . (int) $r['customer_id'] . $tab,
        ];
    }

    /** Distinct customers of the universe with their billing fields. */
    private static function customers(array $ctx): array
    {
        return \db_select(
            "SELECT c.id AS customer_id, c.company_name, c.email, c.billing_email, c.invoice_email,
                    c.email_disabled, c.email_disabled_reason, c.invoice_delivery, c.po_required,
                    c.default_po_number, c.province, c.status, c.payment_terms,
                    c.tax_exempt, c.tax_exempt_expiry, c.gst_exempt, c.gst_exempt_expiry,
                    c.pst_exempt, c.pst_exempt_expiry,
                    COUNT(l.id) AS lease_count
               FROM leases l
               JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
              WHERE {$ctx['u']}
              GROUP BY c.id
              ORDER BY c.company_name",
            $ctx['up']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Blockers
    // ─────────────────────────────────────────────────────────────────────

    private static function checkRatesMissing(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.status = 'active'
                AND l.daily_rate = 0 AND l.weekly_rate = 0 AND l.monthly_rate = 0
                AND COALESCE(l.hourly_rate, 0) = 0
                AND COALESCE(l.mileage_rate_km, 0) = 0 AND l.mileage_rate = 0
              ORDER BY c.company_name",
            $ctx['up']
        );
        return self::make('blocker', 'Leases with no rate at all',
            'The billing engine refuses a lease with no daily, weekly, monthly, hourly or mileage rate — it cannot price anything.',
            'Open the lease and set its rates (or amend them), then re-run the checks.',
            array_map(static fn($r) => self::leaseItem($r, 'All rates are $0', \base_url('leases/edit') . '?id=' . (int) $r['id']), $rows));
    }

    private static function checkFxRate(array $ctx): array
    {
        $usd = \db_select(
            "SELECT l.id, l.contract_number, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.currency = 'USD'
              ORDER BY c.company_name",
            $ctx['up']
        );
        if (!$usd) {
            return self::make('warning', 'US-dollar exchange rate', 'No US-dollar leases bill this month.', '', []);
        }
        $rate = \db_row(
            "SELECT rate, rate_date FROM exchange_rates
              WHERE from_currency = 'USD' AND to_currency = 'CAD'
              ORDER BY rate_date DESC, id DESC LIMIT 1",
            []
        );
        $items = [[
            'label'  => $rate ? ('Latest USD→CAD: ' . $rate['rate'] . ' on ' . $rate['rate_date']) : 'No USD→CAD rate on file',
            'detail' => count($usd) . ' US-dollar lease(s) bill this month',
            'url'    => \base_url('billing/settings') . '#exchange-rate',
        ]];
        if (!$rate) {
            return self::make('blocker', 'No US-dollar exchange rate',
                'US-dollar invoices freeze the latest USD→CAD rate. With none on file the CAD value (and the ledger entry) cannot be worked out.',
                'Add today\'s USD→CAD rate, then re-run the checks.', $items, count($usd));
        }
        $age = (int) ((strtotime($ctx['today']) - strtotime((string) $rate['rate_date'])) / 86400);
        if ($age <= 7) {
            return self::make('warning', 'US-dollar exchange rate', 'The USD→CAD rate is current.', '', []);
        }
        $items[0]['detail'] .= " — the rate is {$age} days old";
        return self::make('warning', 'US-dollar exchange rate is out of date',
            "Each US-dollar invoice freezes the LATEST rate on file, whatever its date. The latest is {$age} days old.",
            'Enter a current USD→CAD rate before generating.', $items, count($usd));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Warnings — pricing inputs
    // ─────────────────────────────────────────────────────────────────────

    private static function checkEarlierMonths(array $ctx): array
    {
        $leases = \db_select(
            "SELECT l.id, l.contract_number, l.start_date, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.status = 'active' AND l.billing_cycle = 'monthly' AND l.start_date < ?
              ORDER BY c.company_name",
            array_merge($ctx['up'], [$ctx['start']])
        );
        if (!$leases) {
            return self::make('warning', 'Earlier months not billed', '', '', []);
        }
        $ids = array_map(static fn($l) => (int) $l['id'], $leases);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $inv = [];
        foreach (\db_select(
            "SELECT lease_id, billing_period_start, billing_period_end FROM invoices
              WHERE deleted_at IS NULL AND status <> 'void' AND lease_id IN ({$ph})
                AND billing_period_start < ?
                AND invoice_type IN ('" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "')
                AND billing_type IN ('" . implode("','", BillingCycles::RENTAL_BILLING_TYPES) . "')",
            array_merge($ids, [$ctx['start']])
        ) as $r) {
            $inv[(int) $r['lease_id']][] = [$r['billing_period_start'], $r['billing_period_end']];
        }
        $held = BillingHolds::heldMap($ids, $ctx['start'], $ctx['end']);

        $floor = BillingCycles::shiftMonth(substr($ctx['start'], 0, 7), -self::GAP_LOOKBACK_MONTHS);
        $items = [];
        foreach ($leases as $l) {
            $lid = (int) $l['id'];
            if (isset($held[$lid])) continue;
            $month = max(substr((string) $l['start_date'], 0, 7), $floor);
            $stop  = substr($ctx['start'], 0, 7);
            $gaps  = [];
            while ($month < $stop) {
                [$ms, $me] = BillingCycles::monthBounds($month);
                $from = max($ms, (string) $l['start_date']);
                $covered = false;
                foreach ($inv[$lid] ?? [] as [$ps, $pe]) {
                    if ($ps <= $me && $pe >= $from) { $covered = true; break; }
                }
                if (!$covered) $gaps[] = date('M Y', strtotime($ms));
                $month = BillingCycles::shiftMonth($month, 1);
            }
            if ($gaps) {
                $items[] = self::leaseItem($l, 'Not billed: ' . implode(', ', array_slice($gaps, 0, 6)) . (count($gaps) > 6 ? ' +' . (count($gaps) - 6) . ' more' : ''));
            }
        }
        return self::make('warning', 'Earlier months not billed',
            'Each invoice re-prices the lease from its start and bills the difference. An earlier month that was never billed gets swept into THIS month\'s invoice — the customer sees one large, confusing bill.',
            'Bill the earlier months first (open that month\'s cycle, or the lease\'s Generate Invoice), or put the lease on hold.',
            $items);
    }

    private static function checkReadings(array $ctx): array
    {
        $items = [];
        foreach (CycleReadings::sheet($ctx['cycle']) as $row) {
            if ($row['billed'] || !$row['missing']) continue;
            $need = [];
            if ($row['needs_odometer'] && !($row['reading']['odometer_km'] ?? null)) $need[] = 'odometer';
            if ($row['needs_hours'] && !($row['reading']['engine_hours'] ?? null)) $need[] = 'engine hours';
            $items[] = [
                'label'  => $row['contract_number'] . ' · ' . $row['company_name'],
                'detail' => 'Needs period-end ' . implode(' and ', $need),
                'url'    => '#readings',
            ];
        }
        return self::make('warning', 'Period-end readings not entered',
            'Manual-mileage and hourly leases bill their distance and hours only from a reading. Without one this month\'s invoice bills no usage, and the whole amount lands on a later invoice.',
            'Enter them on the Readings tab — generation picks them up automatically.', $items);
    }

    private static function checkMileageModeOff(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, l.mileage_rate_km, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.mileage_tracking_mode = 'off' AND COALESCE(l.mileage_rate_km, 0) > 0
              ORDER BY c.company_name",
            $ctx['up']
        );
        return self::make('warning', 'Mileage rate set but tracking is Off',
            'A lease with a mileage rate and tracking Off bills $0 mileage on every invoice.',
            'Set the lease\'s mileage tracking to Manual or Samsara — or remove the rate if mileage is not charged.',
            array_map(static fn($r) => self::leaseItem($r, 'Rate $' . rtrim(rtrim((string) $r['mileage_rate_km'], '0'), '.') . '/km, tracking Off', \base_url('leases/edit') . '?id=' . (int) $r['id']), $rows));
    }

    private static function checkSamsaraUnmapped(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name, eu.id AS unit_id,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number
               FROM leases l JOIN customers c ON c.id = l.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE {$ctx['u']} AND l.mileage_tracking_mode = 'samsara'
                AND (eu.id IS NULL OR eu.samsara_vehicle_id IS NULL OR eu.samsara_vehicle_id = '')
              ORDER BY c.company_name",
            $ctx['up']
        );
        return self::make('warning', 'Samsara leases whose unit is not mapped',
            'Samsara-mode leases bill distance from the unit\'s Samsara history. An unmapped unit returns nothing, so no mileage is billed.',
            'Map the unit to its Samsara vehicle or trailer, or switch the lease to Manual and enter a reading.',
            array_map(static fn($r) => self::leaseItem($r, 'Unit ' . ($r['unit_number'] ?? '—') . ' has no Samsara ID',
                $r['unit_id'] ? \base_url('equipment/show') . '?id=' . (int) $r['unit_id'] : null), $rows));
    }

    private static function checkSamsaraStale(array $ctx): array
    {
        $cutoff = \ff_now_utc('-2 days');
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name, eu.id AS unit_id, eu.samsara_last_synced_at,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number
               FROM leases l JOIN customers c ON c.id = l.customer_id
               JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE {$ctx['u']} AND l.mileage_tracking_mode = 'samsara'
                AND eu.samsara_vehicle_id IS NOT NULL AND eu.samsara_vehicle_id <> ''
                AND (eu.samsara_last_synced_at IS NULL OR eu.samsara_last_synced_at < ?)
              ORDER BY c.company_name",
            array_merge($ctx['up'], [$cutoff])
        );
        return self::make('warning', 'Samsara units not heard from in 2+ days',
            'Distance comes from Samsara\'s history for the month. A unit that has stopped reporting (dead gateway, unpowered trailer) may return a short distance.',
            'Check the unit on Samsara Tracking before generating; switch the lease to Manual for this month if the data is missing.',
            array_map(static fn($r) => self::leaseItem($r,
                'Unit ' . ($r['unit_number'] ?? '—') . ' last synced ' . ($r['samsara_last_synced_at'] ? substr((string) $r['samsara_last_synced_at'], 0, 10) : 'never'),
                \base_url('equipment/show') . '?id=' . (int) $r['unit_id']), $rows));
    }

    private static function checkOverrun(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, l.end_date, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.status = 'active' AND l.end_date IS NOT NULL AND l.end_date < ?
              ORDER BY l.end_date",
            array_merge($ctx['up'], [$ctx['start']])
        );
        return self::make('warning', 'Active leases past their end date',
            'These leases ended before this month but were never closed, so they keep billing as if the unit is still out.',
            'Close the lease with its real return date, or extend its end date if the unit really is still out.',
            array_map(static fn($r) => self::leaseItem($r, 'Ended ' . $r['end_date'] . ', still active'), $rows));
    }

    private static function checkReturnedNotClosed(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, l.actual_return_date, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.status = 'active' AND l.actual_return_date IS NOT NULL
              ORDER BY l.actual_return_date",
            $ctx['up']
        );
        return self::make('warning', 'Returned but not closed',
            'A return date is recorded but the lease is still active — a monthly invoice would bill past the return.',
            'Close the lease (it bills the final partial month itself) and leave it out of the batch.',
            array_map(static fn($r) => self::leaseItem($r, 'Returned ' . $r['actual_return_date']), $rows));
    }

    private static function checkClosedUnbilled(array $ctx): array
    {
        $cov = BillingCycles::coverage($ctx['cycle'], false);
        $items = [];
        foreach ($cov['rows'] as $r) {
            if ($r['status'] !== 'closed_unbilled') continue;
            $items[] = [
                'label'  => $r['contract_number'] . ' · ' . $r['company_name'],
                'detail' => 'Closed ' . ($r['return_date'] ?? $r['end_date'] ?? '') . ' with days in this month unbilled',
                'url'    => \base_url('invoices/create') . '?lease_id=' . $r['lease_id'],
            ];
        }
        return self::make('warning', 'Closed leases with unbilled days this month',
            'The workbench bills active leases only. A lease closed without its final invoice leaves its last days unbilled.',
            'Use Generate Invoice on each lease (it offers the unbilled month).', $items);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Warnings — customer / delivery
    // ─────────────────────────────────────────────────────────────────────

    private static function checkNoRecipient(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            if (($c['invoice_delivery'] ?? 'email') !== 'email') continue;
            if (trim((string) $c['invoice_email']) === '' && trim((string) $c['billing_email']) === '' && trim((string) $c['email']) === '') {
                $items[] = self::customerItem($c, 'Delivery is email but no address is on file');
            }
        }
        return self::make('warning', 'Customers with no email to send to',
            'These customers get invoices by email, but have no invoice, billing or main email. Send & Email will skip them.',
            'Add an Invoice Email on the customer.', $items);
    }

    private static function checkEmailBounced(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            if ((int) $c['email_disabled'] === 1) {
                $items[] = self::customerItem($c, 'Email turned off' . ($c['email_disabled_reason'] ? ': ' . $c['email_disabled_reason'] : ' after a bounce or complaint'));
            }
        }
        return self::make('warning', 'Customers whose email bounced',
            'Email to these customers is switched off after a bounce or complaint — the invoice email will not be sent.',
            'Correct the address and re-enable email on the customer, or print and mail this month.', $items);
    }

    private static function checkPoMissing(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND c.po_required = 1
                AND (l.po_number IS NULL OR l.po_number = '')
                AND (c.default_po_number IS NULL OR c.default_po_number = '')
              ORDER BY c.company_name",
            $ctx['up']
        );
        return self::make('warning', 'PO number required but missing',
            'The customer requires a PO number on invoices; without one the invoice is likely to be rejected by their accounts payable.',
            'Add the PO number on the lease (or a default PO on the customer).',
            array_map(static fn($r) => self::leaseItem($r, 'No PO on lease or customer', \base_url('leases/edit') . '?id=' . (int) $r['id']), $rows));
    }

    private static function checkTaxExemption(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            $expired = [];
            foreach (['tax' => 'Tax', 'gst' => 'GST', 'pst' => 'PST'] as $k => $label) {
                if ((int) $c["{$k}_exempt"] === 1 && $c["{$k}_exempt_expiry"] !== null && $c["{$k}_exempt_expiry"] < $ctx['start']) {
                    $expired[] = "{$label} exemption expired " . $c["{$k}_exempt_expiry"];
                }
            }
            if ($expired) {
                $items[] = self::customerItem($c, implode('; ', $expired));
            }
        }
        return self::make('warning', 'Tax exemptions that have expired',
            'An exemption certificate that expired before the month starts is ignored — the invoice is taxed.',
            'Get the renewed certificate and update the expiry on the customer, or accept that this month is taxed.', $items);
    }

    private static function checkProvince(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            if (trim((string) $c['province']) === '') {
                $items[] = self::customerItem($c, 'No province — taxed as BC');
            }
        }
        return self::make('warning', 'Customers with no province',
            'Tax is worked out from the customer\'s province. With none, the invoice is taxed as British Columbia.',
            'Set the province on the customer.', $items);
    }

    private static function checkAccountingPeriod(array $ctx): array
    {
        $enabled = \FleetForge\Accounting\AccountingService::setting('accounting.enabled', false);
        if (!$enabled) {
            return self::make('warning', 'Accounting period', 'Accounting is not switched on.', '', []);
        }
        $p = \db_row(
            "SELECT id, name, status FROM acc_periods WHERE year = ? AND month = ?",
            [(int) substr($ctx['start'], 0, 4), (int) substr($ctx['start'], 5, 2)]
        );
        if (!$p || $p['status'] === 'open') {
            return self::make('warning', 'Accounting period', 'The month is open in the ledger.', '', []);
        }
        return self::make('warning', 'The month is ' . $p['status'] . ' in the ledger',
            'Invoices are dated in this month. Sending posts their revenue; with the period ' . $p['status'] . ' the entries move to the next open period instead (a PERIOD REDIRECT in the audit log).',
            'Ask the accountant whether to reopen the period before sending, or accept the redirect.',
            [['label' => (string) $p['name'], 'detail' => 'Status: ' . $p['status'], 'url' => \base_url('accounting/periods')]]);
    }

    private static function checkEarlierDrafts(array $ctx): array
    {
        $rows = \db_select(
            "SELECT DATE_FORMAT(i.billing_period_start, '%Y-%m') AS month, COUNT(*) AS n
               FROM invoices i
              WHERE i.deleted_at IS NULL AND i.status = 'draft' AND i.lease_id IS NOT NULL
                AND i.invoice_type IN ('" . implode("','", BillingCycles::CYCLE_INVOICE_TYPES) . "')
                AND i.billing_period_start < ?
              GROUP BY month ORDER BY month",
            [$ctx['start']]
        );
        $total = array_sum(array_map(static fn($r) => (int) $r['n'], $rows));
        return self::make('warning', 'Unsent drafts from earlier months',
            'Drafts count for nothing — no revenue, no receivable, nothing in QuickBooks — until they are sent. Customers have not been billed for these.',
            'Open each month\'s cycle and send or void its drafts. Send a large backlog in stages.',
            array_map(static fn($r) => [
                'label'  => date('F Y', strtotime($r['month'] . '-01')),
                'detail' => $r['n'] . ' unsent draft(s)',
                'url'    => \base_url('billing/cycle') . '?month=' . $r['month'] . '#delivery',
            ], $rows), $total);
    }

    private static function checkEarlierExceptions(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name, e.period_start, e.reason
               FROM invoice_billing_exceptions e
               JOIN leases l ON l.id = e.lease_id
               JOIN customers c ON c.id = l.customer_id
              WHERE e.deleted_at IS NULL AND e.status = 'open' AND e.period_end < ?
              ORDER BY e.period_start",
            [$ctx['start']]
        );
        return self::make('warning', 'Open billing exceptions from earlier months',
            'These leases could not be billed in an earlier run and nobody has dealt with them — the customer was never billed.',
            'Fix and re-bill, or ignore with a note, on Billing → Exceptions.',
            array_map(static fn($r) => [
                'label'  => $r['contract_number'] . ' · ' . $r['company_name'],
                'detail' => date('M Y', strtotime((string) $r['period_start'])) . ': ' . mb_substr((string) $r['reason'], 0, 140),
                'url'    => \base_url('billing') . '#exceptions',
            ], $rows));
    }

    private static function checkQboFailures(array $ctx): array
    {
        $n = (int) \db_count(
            "SELECT COUNT(*) FROM acc_qbo_sync_queue WHERE entity_type = 'invoice' AND status = 'failed'",
            []
        );
        $items = $n > 0 ? [[
            'label' => "{$n} invoice push(es) failed", 'detail' => 'QuickBooks sync queue',
            'url' => \base_url('quickbooks/sync_queue'),
        ]] : [];
        return self::make('warning', 'Invoices that failed to reach QuickBooks',
            'Earlier invoices that failed to push are missing from QuickBooks; this month\'s will queue behind the same problem.',
            'Clear the failed items on QuickBooks → Sync Queue before sending.', $items, $n);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Info
    // ─────────────────────────────────────────────────────────────────────

    private static function checkNonEmailDelivery(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            $d = (string) ($c['invoice_delivery'] ?? 'email');
            if ($d !== 'email') {
                $items[] = self::customerItem($c, match ($d) {
                    'mail'   => 'Mail — print and post the invoice',
                    'portal' => 'Portal only — no email is sent',
                    default  => 'No delivery — invoice kept on file',
                });
            }
        }
        return self::make('info', 'Customers not billed by email',
            'Send & Email does not reach these customers.', 'Print, post or tell them it is on the portal.', $items);
    }

    private static function checkDueOnSend(array $ctx): array
    {
        // S-BILLING-MODULE-2 (KNOWN ISSUE #113): with terms running from the
        // send date, sending sets a fresh due date — nothing is past due on send.
        if ((string) \settings_get('billing_cycle.due_date_basis', 'send_date') === 'send_date') {
            return self::make('info', 'Invoices that will be past due when sent',
                'Payment terms run from the day an invoice is sent (Billing → Settings), so none is past due when it goes out.', '', []);
        }
        $items = [];
        foreach (self::customers($ctx) as $c) {
            $due = PaymentTerms::dueDate($ctx['start'], $c['payment_terms'] !== null ? (string) $c['payment_terms'] : null);
            if ($due < $ctx['today']) {
                $items[] = self::customerItem($c, 'Terms ' . ($c['payment_terms'] ?: 'default') . ' → due ' . $due);
            }
        }
        return self::make('info', 'Invoices that will be past due when sent',
            'A monthly invoice is dated the first day of the month it bills, and its due date follows the customer\'s terms from there. Sent today, these are already past due — the overdue job and late fees can pick them up straight away.',
            'Send promptly; consider telling these customers the new due date.', $items);
    }

    private static function checkOnCloseOnly(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name
               FROM leases l JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND l.status = 'active' AND l.billing_cycle = 'on_close_only'
              ORDER BY c.company_name",
            $ctx['up']
        );
        return self::make('info', 'Leases billed only when they close',
            'These leases are set to bill once, at close — they are not billed monthly.',
            'Nothing to do unless one should bill monthly (change its billing cycle on the lease).',
            array_map(static fn($r) => self::leaseItem($r, 'Bills at close'), $rows));
    }

    private static function checkHolds(array $ctx): array
    {
        $leaseIds = array_map(static fn($r) => (int) $r['id'], \db_select(
            "SELECT l.id FROM leases l WHERE {$ctx['u']}", $ctx['up']));
        $held = BillingHolds::heldMap($leaseIds, $ctx['start'], $ctx['end']);
        $items = [];
        if ($held) {
            $ph = implode(',', array_fill(0, count($held), '?'));
            $names = [];
            foreach (\db_select(
                "SELECT l.id, l.contract_number, c.company_name FROM leases l JOIN customers c ON c.id = l.customer_id WHERE l.id IN ({$ph})",
                array_keys($held)
            ) as $r) {
                $names[(int) $r['id']] = $r;
            }
            foreach ($held as $lid => $h) {
                $items[] = self::leaseItem($names[$lid] ?? ['id' => $lid], BillingHolds::describe($h), \base_url('billing') . '#holds');
            }
        }
        return self::make('info', 'Leases on billing hold',
            'Held leases are left out of generation until the hold is released.',
            'Release a hold on Billing → Holds when billing should resume.', $items);
    }

    private static function checkCreditHold(array $ctx): array
    {
        $items = [];
        foreach (self::customers($ctx) as $c) {
            if (in_array($c['status'], ['credit_hold', 'suspended'], true)) {
                $items[] = self::customerItem($c, $c['status'] === 'credit_hold' ? 'On credit hold' : 'Suspended');
            }
        }
        return self::make('info', 'Customers on credit hold or suspended',
            'Credit hold stops new rentals, not billing — their current leases still bill.',
            'Place a billing hold as well if they should not be billed.', $items);
    }

    private static function checkAmendments(array $ctx): array
    {
        $rows = \db_select(
            "SELECT l.id, l.contract_number, c.company_name, a.amendment_type, a.created_at
               FROM lease_amendments a
               JOIN leases l ON l.id = a.lease_id
               JOIN customers c ON c.id = l.customer_id
              WHERE {$ctx['u']} AND a.amendment_type = 'rate_change'
                AND a.created_at >= ?
              ORDER BY a.created_at DESC",
            array_merge($ctx['up'], [$ctx['start'] . ' 00:00:00'])
        );
        return self::make('info', 'Rate changes since the month started',
            'A rate amendment re-prices the whole lease, so this month\'s invoice includes a catch-up for earlier months at the new rate.',
            'Expect a larger (or credit) rental line on these leases.',
            array_map(static fn($r) => self::leaseItem($r, 'Rate amended ' . substr((string) $r['created_at'], 0, 10)), $rows));
    }

    private static function checkChargesDue(array $ctx): array
    {
        $rows = BillingCharges::list(['state' => 'pending', 'month' => substr($ctx['start'], 0, 7)]);
        return self::make('info', 'Charges waiting to be billed this month',
            'Queued charges ride on each lease\'s next invoice automatically (one-off charges once, monthly charges every month).',
            'Nothing to do — check the amounts on the Charges tab before generating.',
            array_map(static fn($c) => [
                'label'  => $c['contract_number'] . ' · ' . $c['company_name'],
                'detail' => $c['description'] . ' — ' . ($c['recurrence'] === 'monthly' ? 'monthly' : 'once'),
                'url'    => '#charges',
            ], $rows));
    }

    private static function checkPendingRuns(array $ctx): array
    {
        $rows = \db_select(
            "SELECT id, reference, status, invoice_count FROM invoice_batch_runs
              WHERE deleted_at IS NULL AND status IN ('pending','approved')
                AND period_start <= ? AND period_end >= ?
              ORDER BY id",
            [$ctx['end'], $ctx['start']]
        );
        return self::make('info', 'Approval runs in progress for this month',
            'These runs are waiting for a decision or to be generated.', 'Open the run to approve, reject or generate it.',
            array_map(static fn($r) => [
                'label'  => $r['reference'],
                'detail' => ucfirst((string) $r['status']) . ' — ' . (int) $r['invoice_count'] . ' invoice(s)',
                'url'    => \base_url('billing/approval') . '?id=' . (int) $r['id'],
            ], $rows));
    }
}
