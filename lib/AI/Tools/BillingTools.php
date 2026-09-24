<?php
declare(strict_types=1);

namespace FleetForge\AI\Tools;

use FleetForge\Billing\Cycle\BillingCharges;
use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\Cycle\BillingHolds;
use FleetForge\Billing\Cycle\BillingReadiness;
use FleetForge\Billing\Cycle\BillingReview;
use FleetForge\Billing\Cycle\CycleClose;
use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;
use FleetForge\RateCards\RateResolver;

/**
 * lib/AI/Tools/BillingTools.php
 *
 * Billing tools for the AI assistant: "why is this invoice $X", "where are we
 * with this month's billing", "what is on hold / queued to bill", and "what
 * would this customer pay" (S-AI-KNOWLEDGE).
 *
 * WHY a module: the core tools (FleetForgeTools) list invoices, but they cannot
 * explain one. Staff ask the assistant to explain an amount, and the answer is
 * in the line items, the holistic-engine figures stored on the invoice
 * (rate_method_explanation, cumulative_correct_amount,
 * already_billed_before_this), the mileage/hours readings, and the credits and
 * payments against it. The monthly cycle, holds, charges and price check
 * already have their read logic in lib/Billing/Cycle and lib/RateCards. These
 * tools call that logic so the assistant gives the same answer the Billing and
 * Rates pages give.
 *
 * READ-ONLY. Nothing here writes. BillingReadiness::run() saves its summary on
 * the cycle row, so get_billing_cycle calls its read half, evaluate(), instead
 * (see liveReadiness()). BillingCycles::ensure() creates
 * a cycle, so it is never called either: a month with no cycle gets
 * "not opened yet, open it on /billing".
 *
 * Permissions (the chat endpoint only checks ai:view):
 *   - invoice + billing tools need invoices:view (the Billing module's own gate)
 *   - get_price_check needs rates:view (it is a Rates-module feature, so
 *     dispatchers, who have rates = none, are refused)
 *   - money (totals, balances, payments, charge amounts, engine $ working) is
 *     shown only when can_view_financials() (payments:view). Dispatchers see
 *     numbers, statuses, dates and descriptions, like api/v1/invoices/show.php.
 *   - a null $userId (system/cron) gets full access, as in FleetForgeTools.
 *
 * Money: amounts come back as the DB's decimal strings. Any arithmetic uses
 * bcmath. CAD is canonical (exchange_rate_to_cad). Drafts, voids and
 * write-offs are labelled as "not revenue".
 *
 * Tools:
 *   get_invoice_details  one invoice explained, or a lease's invoice list
 *   get_billing_cycle    one month's billing cycle: stage, counts, readiness,
 *                        review flags, pre-close checks
 *   get_billing_holds    standing "do not bill" holds
 *   get_billing_charges  charges queued to ride on the next invoice(s)
 *   get_price_check      the price a customer would get for an equipment type
 *                        on a date, why, and an optional rental quote
 *
 * @depends includes/db.php (db_select, db_row, db_count)
 * @depends includes/auth.php (can, can_view_financials)
 * @depends includes/functions.php (ff_today, base_url, settings_get)
 * @depends lib/Billing/Cycle/{BillingCycles,BillingReadiness,BillingReview,CycleClose,BillingHolds,BillingCharges}.php
 * @depends lib/RateCards/{RateResolver,RateInsights,RateCardItems}.php
 * @session S-AI-KNOWLEDGE
 */
final class BillingTools
{
    /** Tool names this module owns. */
    private const TOOLS = [
        'get_invoice_details',
        'get_billing_cycle',
        'get_billing_holds',
        'get_billing_charges',
        'get_price_check',
    ];

    /** Rows returned by the list tools. The total count is always given as well. */
    private const LIST_CAP = 50;

    /** Line items shown for one invoice (real invoices have < 15). */
    private const LINE_CAP = 100;

    /** Examples shown per readiness check / review flag (the page lists them all). */
    private const EXAMPLES = 3;

    // ────────────────────────────────────────────────────────────
    // Module contract (see FleetForgeTools::MODULES)
    // ────────────────────────────────────────────────────────────

    /** Anthropic tool definitions (+ internal _tags) for this module. */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'get_invoice_details',
                'description' => 'Explain one invoice: header (status, customer, lease/contract, unit, billing period, invoice/due/sent dates, currency, subtotal/tax/total/paid/balance), every line item, how the rental was priced (the billing engine\'s working: rate method, cumulative amount owed so far vs already billed), the odometer / distance / engine-hours readings it billed, credit notes applied or created from it, payments allocated, and its QuickBooks sync state. Use this to answer "why is this invoice $X", "what is on INV-…", "why is there a credit line", "which reading did it bill". Give invoice_number (e.g. INV-2026-02384) or invoice_id. Give only lease_id to list that lease\'s invoices (number, period, status, total, balance), then call again with the invoice you need.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_number' => ['type' => 'string', 'description' => 'Invoice number, e.g. INV-2026-02384 (the last digits alone also work when they are unique).'],
                        'invoice_id'     => ['type' => 'integer', 'description' => 'Invoice ID.'],
                        'lease_id'       => ['type' => 'integer', 'description' => 'Lease ID. When no invoice is given, lists this lease\'s invoices.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_billing_cycle',
                'description' => 'One month\'s billing cycle (Billing → the month\'s cycle page): status, owner, bill-by / send-by targets, the seven-step progress (Prepare, Readings, Generate, Review, Approve, Send, Close), how many leases are to bill / billed / held / covered elsewhere, invoice counts (drafts, sent, emailed, reviewed, queried), readings still missing, live readiness blockers and warnings, review flags (double billing, big swings, $0, no tax...), leases billed last month but missing now, and what still stops the cycle closing. Use for "where are we with September billing", "what is blocking billing", "can we close the month", "what still needs to be sent". Read-only: it never opens a cycle or saves anything.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'month'     => ['type' => 'string', 'description' => "Billed month 'YYYY-MM'. Default: the month billing is currently due for (last month in arrears mode), else the latest cycle."],
                        'reference' => ['type' => 'string', 'description' => "Cycle reference, e.g. 'BC-2026-09' (alternative to month)."],
                        'include_readiness' => ['type' => 'boolean', 'description' => 'Run the readiness checks live (default true). Set false for a quick status.'],
                        'include_review'    => ['type' => 'boolean', 'description' => 'Include the invoice review flags (default true).'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_billing_holds',
                'description' => 'Billing holds: standing "do not bill" instructions on one lease or on all of a customer\'s leases (reason, from/until, who placed or released it). Held leases are left out of monthly generation until the hold is released. Use for "why wasn\'t this lease billed", "who is on billing hold", "is customer X on hold".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'state'       => ['type' => 'string', 'enum' => ['active', 'released', 'all'], 'description' => "Default 'active'. 'released' also includes holds whose end date has passed."],
                        'customer_id' => ['type' => 'integer', 'description' => 'Only this customer\'s holds.'],
                        'lease_id'    => ['type' => 'integer', 'description' => 'Holds that cover this lease (its own, plus customer-wide holds for its customer).'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_billing_charges',
                'description' => 'Charges queued on leases (Billing → Charges): one-off charges (damage, wash, fuel, admin fee…) billed once on the next rental invoice, and monthly charges billed every month until an end date or cancellation. Shows what each is, its amount, when it starts billing, and whether it is pending, already billed (with the invoice) or cancelled. Use for "what extra charges will go on the next invoice", "was the wash charge billed", "why is there a damage line".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'state'       => ['type' => 'string', 'enum' => ['pending', 'billed', 'cancelled', 'all'], 'description' => "Default 'pending' (still to bill, including recurring monthly charges)."],
                        'month'       => ['type' => 'string', 'description' => "Only charges that bill (or billed) in this month, 'YYYY-MM'."],
                        'lease_id'    => ['type' => 'integer', 'description' => 'Only this lease\'s charges.'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Only this customer\'s charges.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_price_check',
                'description' => 'Price check (Rates → Price check): the daily / weekly / monthly / mileage / hourly price a NEW lease for this customer and equipment type would get on a date, which rate card it came from and why that card won over the others (customer card vs general card vs the equipment type\'s default), the standard price for comparison, and, if start_date and end_date are given, an estimate for that rental worked out the way the billing engine bills it. Use for "what would X pay for a 53\' dry van", "what is our standard reefer rate", "quote 10 days of a flatbed for Y". Not for an existing lease\'s rates (use get_lease_details).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id'           => ['type' => 'integer', 'description' => 'Customer ID. Omit for the standard price (a customer with no card of their own).'],
                        'customer_name'         => ['type' => 'string', 'description' => 'Customer name search, instead of customer_id.'],
                        'equipment_template_id' => ['type' => 'integer', 'description' => 'Equipment type (template) ID.'],
                        'equipment_type'        => ['type' => 'string', 'description' => 'Equipment type name search (e.g. "53 dry van", "reefer"), instead of the ID.'],
                        'date'                  => ['type' => 'string', 'description' => 'Price on this date, YYYY-MM-DD (default today).'],
                        'start_date'            => ['type' => 'string', 'description' => 'Rental start for a quote, YYYY-MM-DD.'],
                        'end_date'              => ['type' => 'string', 'description' => 'Rental return for a quote, YYYY-MM-DD.'],
                        'gps'                   => ['type' => 'boolean', 'description' => 'Include GPS tracking in the quote.'],
                        'distance_per_day'      => ['type' => 'number', 'description' => 'Estimated distance per day for the quote (in the price\'s mileage unit).'],
                        'hours_per_day'         => ['type' => 'number', 'description' => 'Estimated engine hours per day for the quote.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
        ];
    }

    /** Does this module own $toolName? */
    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::TOOLS, true);
    }

    /** Dispatch one tool call. Every handler is read-only. */
    public static function run(string $toolName, array $input, ?int $userId = null, ?int $sessionId = null): mixed
    {
        return match ($toolName) {
            'get_invoice_details' => self::getInvoiceDetails($input, $userId),
            'get_billing_cycle'   => self::getBillingCycle($input, $userId),
            'get_billing_holds'   => self::getBillingHolds($input, $userId),
            'get_billing_charges' => self::getBillingCharges($input, $userId),
            'get_price_check'     => self::getPriceCheck($input, $userId),
        };
    }

    // ════════════════════════════════════════════════════════════
    //  get_invoice_details
    // ════════════════════════════════════════════════════════════

    /**
     * One invoice explained, or a lease's invoice list when only lease_id is given.
     *
     * @param  array    $input  invoice_number | invoice_id | lease_id
     * @param  int|null $userId current user (permission + money gate)
     * @return array            result or {error, message}
     */
    private static function getInvoiceDetails(array $input, ?int $userId): array
    {
        if ($deny = self::gate($userId, 'invoices', 'view', 'Invoices')) {
            return $deny;
        }
        $money = self::money($userId);

        $invoiceId = (int) ($input['invoice_id'] ?? 0);
        $number    = trim((string) ($input['invoice_number'] ?? ''));
        $leaseId   = (int) ($input['lease_id'] ?? 0);

        if ($invoiceId <= 0 && $number === '') {
            if ($leaseId > 0) {
                return self::leaseInvoices($leaseId, $money);
            }
            return ['error' => true, 'message' => 'Give invoice_number (e.g. INV-2026-02384) or invoice_id, or lease_id to list a lease\'s invoices.'];
        }

        if ($invoiceId <= 0) {
            $resolved = self::resolveInvoiceNumber($number);
            if (isset($resolved['error']) || isset($resolved['matches'])) {
                return $resolved;
            }
            $invoiceId = (int) $resolved['id'];
        }

        $inv = \db_row(
            "SELECT i.id, i.invoice_number, i.invoice_type, i.billing_type, i.status, i.generation_source,
                    i.customer_id, COALESCE(c.company_name, i.company_name_snapshot) AS customer_name,
                    i.lease_id, COALESCE(i.contract_number_snapshot, l.contract_number) AS contract_number,
                    COALESCE(i.unit_number_invoice_snapshot, eu.unit_number, l.unit_number_snapshot) AS unit_number,
                    l.status AS lease_status, l.mileage_tracking_mode,
                    i.po_number, i.province_snapshot AS province,
                    i.billing_period_start, i.billing_period_end, i.billing_period_days,
                    i.invoice_date, i.due_date, i.sent_date, i.sent_at, i.sent_to_email, i.delivery_method,
                    i.paid_date, i.voided_date, i.void_reason, i.written_off_at, i.write_off_reason,
                    i.currency, i.exchange_rate_to_cad,
                    i.subtotal, i.discount_type, i.discount_value, i.discount_amount, i.subtotal_after_discount,
                    i.tax_gst_rate, i.tax_pst_rate, i.tax_hst_rate,
                    i.tax_gst_amount, i.tax_pst_amount, i.tax_hst_amount, i.tax_total,
                    i.total_amount, i.amount_paid, i.credits_applied, i.balance_due,
                    i.tax_exempt_snapshot, i.gst_exempt_snapshot, i.pst_exempt_snapshot,
                    i.rate_method_used, i.rate_method_explanation,
                    i.total_days_at_period_end, i.cumulative_correct_amount, i.already_billed_before_this,
                    i.odometer_at_period_start_km, i.odometer_at_period_end_km, i.period_distance_km,
                    i.cumulative_distance_km, i.odometer_source,
                    i.engine_hours_at_period_start, i.engine_hours_at_period_end, i.period_engine_hours,
                    i.credit_note_for_invoice_id, i.late_fee_invoice_id, i.notes,
                    i.created_at
               FROM invoices i
               LEFT JOIN leases l ON l.id = i.lease_id
               LEFT JOIN customers c ON c.id = i.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE i.id = ? AND i.deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$inv) {
            return ['error' => true, 'message' => "No invoice found with ID {$invoiceId}."];
        }
        $id = (int) $inv['id'];

        // ── Header ────────────────────────────────────────────────
        $header = [
            'id'              => $id,
            'invoice_number'  => $inv['invoice_number'],
            'url'             => \base_url('invoices/show') . '?id=' . $id,
            'status'          => $inv['status'],
            'revenue_note'    => self::revenueNote((string) $inv['status']),
            'invoice_type'    => $inv['invoice_type'],
            'billing_type'    => $inv['billing_type'],
            'generated_by'    => $inv['generation_source'],
            'customer_id'     => $inv['customer_id'] !== null ? (int) $inv['customer_id'] : null,
            'customer_name'   => $inv['customer_name'],
            'lease_id'        => $inv['lease_id'] !== null ? (int) $inv['lease_id'] : null,
            'contract_number' => $inv['contract_number'],
            'unit_number'     => $inv['unit_number'],
            'lease_status'    => $inv['lease_status'],
            'po_number'       => $inv['po_number'],
            'period_start'    => $inv['billing_period_start'],
            'period_end'      => $inv['billing_period_end'],
            'period_days'     => (int) $inv['billing_period_days'],
            'invoice_date'    => $inv['invoice_date'],
            'due_date'        => $inv['due_date'],
            'sent_at'         => $inv['sent_at'],
            'sent_to'         => $inv['sent_to_email'],
            'delivery_method' => $inv['delivery_method'],
            'paid_date'       => $inv['paid_date'],
            'voided_date'     => $inv['voided_date'],
            'void_reason'     => $inv['void_reason'],
            'written_off_at'  => $inv['written_off_at'],
            'currency'        => $inv['currency'],
            'province'        => $inv['province'],
            'tax_exempt'      => ((int) $inv['tax_exempt_snapshot'] === 1) ? 'all' : trim(((int) $inv['gst_exempt_snapshot'] === 1 ? 'gst ' : '') . ((int) $inv['pst_exempt_snapshot'] === 1 ? 'pst' : '')),
            'credit_note_for_invoice_id' => $inv['credit_note_for_invoice_id'] !== null ? (int) $inv['credit_note_for_invoice_id'] : null,
            'notes'           => $inv['notes'] !== null ? mb_substr((string) $inv['notes'], 0, 500) : null,
        ];
        if ($money) {
            $header += [
                'subtotal'          => $inv['subtotal'],
                'discount'          => $inv['discount_type'] !== 'none'
                    ? ['type' => $inv['discount_type'], 'value' => $inv['discount_value'], 'amount' => $inv['discount_amount']]
                    : null,
                'tax'               => self::taxBreakdown($inv),
                'tax_total'         => $inv['tax_total'],
                'total_amount'      => $inv['total_amount'],
                'amount_paid'       => $inv['amount_paid'],
                'credits_applied'   => $inv['credits_applied'],
                'balance_due'       => $inv['balance_due'],
                'exchange_rate_to_cad' => $inv['exchange_rate_to_cad'],
                // CAD canonical: a USD invoice's CAD value uses its frozen rate.
                'total_cad'         => self::toCad((string) $inv['total_amount'], (string) $inv['currency'], $inv['exchange_rate_to_cad']),
            ];
        } else {
            // Tax RATES are not money; api/v1/invoices/show.php keeps them for dispatchers too.
            $header['tax_rates'] = array_filter([
                'gst' => $inv['tax_gst_rate'], 'pst' => $inv['tax_pst_rate'], 'hst' => $inv['tax_hst_rate'],
            ], static fn ($r) => bccomp((string) $r, '0', 4) !== 0);
        }

        // ── How the rental was priced (holistic engine) ──────────
        // WHY: the engine bills "what the whole lease should cost so far"
        // minus "what earlier invoices already billed". That difference is the
        // usual answer to "why is this invoice more or less than the rate".
        $pricing = array_filter([
            'rate_method_used'         => $inv['rate_method_used'] !== 'none' ? $inv['rate_method_used'] : null,
            'lease_days_to_period_end' => $inv['total_days_at_period_end'] !== null ? (int) $inv['total_days_at_period_end'] : null,
        ], static fn ($v) => $v !== null);
        if ($money) {
            $explanation = $inv['rate_method_explanation'] !== null ? json_decode((string) $inv['rate_method_explanation'], true) : null;
            $pricing += array_filter([
                'explanation'                  => $explanation ?: null,
                'lease_should_have_cost_so_far' => $inv['cumulative_correct_amount'],
                'already_billed_before_this'   => $inv['already_billed_before_this'],
            ], static fn ($v) => $v !== null);
            if ($inv['cumulative_correct_amount'] !== null && $inv['already_billed_before_this'] !== null) {
                $pricing['rental_this_invoice'] = bcsub((string) $inv['cumulative_correct_amount'], (string) $inv['already_billed_before_this'], 2);
                $pricing['note'] = 'Holistic engine: rental on this invoice = what the lease should have cost from its start to this period end, minus rental already billed on earlier live invoices. Rate changes, early returns and voided invoices show up here as catch-ups or credits.';
            }
        }

        // ── Usage readings billed ─────────────────────────────────
        $usage = array_filter([
            'mileage_tracking_mode'   => $inv['mileage_tracking_mode'],
            'odometer_start_km'       => $inv['odometer_at_period_start_km'],
            'odometer_end_km'         => $inv['odometer_at_period_end_km'],
            'period_distance_km'      => $inv['period_distance_km'],
            'distance_since_lease_start_km' => $inv['cumulative_distance_km'],
            'odometer_source'         => ($inv['odometer_at_period_end_km'] !== null || $inv['period_distance_km'] !== null) ? $inv['odometer_source'] : null,
            'engine_hours_start'      => $inv['engine_hours_at_period_start'],
            'engine_hours_end'        => $inv['engine_hours_at_period_end'],
            'period_engine_hours'     => $inv['period_engine_hours'],
        ], static fn ($v) => $v !== null && $v !== '');

        // ── Line items ────────────────────────────────────────────
        $lineRows = \db_select(
            "SELECT item_type, description, detail_lines, quantity, unit, unit_price, amount, is_credit, taxable,
                    billing_days, rate_method, period_start, period_end,
                    mileage_distance, mileage_unit, mileage_rate, reference_type, reference_id
               FROM invoice_line_items
              WHERE invoice_id = ?
              ORDER BY sort_order, id
              LIMIT " . self::LINE_CAP,
            [$id]
        );
        $lines = [];
        foreach ($lineRows as $li) {
            $line = [
                'type'         => $li['item_type'],
                'description'  => $li['description'],
                'quantity'     => self::trimDecimal((string) $li['quantity']),
                'unit'         => $li['unit'],
                'is_credit'    => (int) $li['is_credit'] === 1 ? true : null,
                'taxable'      => (int) $li['taxable'] === 1 ? null : false,
                'billing_days' => $li['billing_days'] !== null ? (int) $li['billing_days'] : null,
                'rate_method'  => $li['rate_method'],
                'period'       => $li['period_start'] !== null ? $li['period_start'] . '..' . $li['period_end'] : null,
                'distance'     => $li['mileage_distance'] !== null ? $li['mileage_distance'] . ' ' . $li['mileage_unit'] : null,
                'from'         => $li['reference_type'] === 'billing_charge' ? 'queued charge #' . (int) $li['reference_id'] : null,
            ];
            if ($money) {
                $line['unit_price']   = $li['unit_price'];
                $line['amount']       = $li['amount'];
                $line['mileage_rate'] = $li['mileage_rate'];
                // detail_lines = the engine's own working for the line (tier,
                // rates, cumulative vs already billed). Worth the tokens when
                // explaining an amount; skipped if unusually large.
                if ($li['detail_lines'] !== null && strlen((string) $li['detail_lines']) <= 2000) {
                    $line['working'] = json_decode((string) $li['detail_lines'], true);
                }
            }
            $lines[] = array_filter($line, static fn ($v) => $v !== null);
        }

        // ── Credit notes: applied to this invoice, and minted from it ──
        $applied = \db_select(
            "SELECT cn.id, cn.credit_note_number, cn.source, cn.reason, a.amount_applied, a.status, a.applied_at
               FROM credit_note_applications a
               JOIN credit_notes cn ON cn.id = a.credit_note_id
              WHERE a.invoice_id = ?
              ORDER BY a.applied_at",
            [$id]
        );
        $minted = \db_select(
            "SELECT id, credit_note_number, source, reason, amount, amount_remaining, status, created_at
               FROM credit_notes
              WHERE source_invoice_id = ? AND deleted_at IS NULL
              ORDER BY id",
            [$id]
        );
        $credits = [
            'applied_to_this_invoice' => array_map(static fn ($r) => array_filter([
                'credit_note_number' => $r['credit_note_number'],
                'source'             => $r['source'],
                'reason'             => mb_substr((string) $r['reason'], 0, 200),
                'amount_applied'     => $money ? $r['amount_applied'] : null,
                'status'             => $r['status'],
                'applied_at'         => $r['applied_at'],
            ], static fn ($v) => $v !== null), $applied),
            'created_from_this_invoice' => array_map(static fn ($r) => array_filter([
                'credit_note_number' => $r['credit_note_number'],
                'source'             => $r['source'],
                'reason'             => mb_substr((string) $r['reason'], 0, 200),
                'amount'             => $money ? $r['amount'] : null,
                'amount_remaining'   => $money ? $r['amount_remaining'] : null,
                'status'             => $r['status'],
                'created_at'         => $r['created_at'],
            ], static fn ($v) => $v !== null), $minted),
        ];

        // ── Payments (payments module → money viewers only) ───────
        if ($money) {
            $payments = array_map(static fn ($r) => [
                'payment_number' => $r['payment_number'],
                'payment_date'   => $r['payment_date'],
                'method'         => $r['payment_method'],
                'status'         => $r['status'],
                'allocated'      => $r['amount'],
                'currency'       => $r['currency'],
            ], \db_select(
                "SELECT p.payment_number, p.payment_date, p.payment_method, p.status, pa.amount, pa.currency
                   FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                  WHERE pa.invoice_id = ? AND p.deleted_at IS NULL
                  ORDER BY p.payment_date, p.id",
                [$id]
            ));
        } else {
            $payments = 'Hidden: payments need payments access (manager or accountant).';
        }

        return array_filter([
            'invoice'           => array_filter($header, static fn ($v) => $v !== null && $v !== ''),
            'pricing'           => $pricing ?: null,
            'usage_billed'      => $usage ?: null,
            'line_items'        => $lines,
            'line_items_capped' => count($lineRows) >= self::LINE_CAP ? 'Only the first ' . self::LINE_CAP . ' lines are shown.' : null,
            'credit_notes'      => ($applied || $minted) ? $credits : null,
            'payments'          => $payments,
            'quickbooks'        => self::qboState($id),
            'amounts_hidden'    => $money ? null : 'Dollar amounts are hidden for your role. A manager or accountant can see them.',
        ], static fn ($v) => $v !== null);
    }

    /**
     * Resolve an invoice number: exact match first, then a unique suffix
     * ("2384" → INV-2026-02384). Several suffix hits → a choice list.
     *
     * @return array{id:int}|array{matches:array}|array{error:bool,message:string}
     */
    private static function resolveInvoiceNumber(string $number): array
    {
        $row = \db_row("SELECT id FROM invoices WHERE invoice_number = ? AND deleted_at IS NULL", [$number]);
        if ($row) {
            return ['id' => (int) $row['id']];
        }
        // WHY a suffix search: people quote "invoice 2384" or drop the prefix.
        // LIKE wildcards in the input are escaped so they match literally.
        $like = '%' . addcslashes($number, '%_\\');
        $hits = \db_select(
            "SELECT id, invoice_number, status, company_name_snapshot AS customer_name, billing_period_start
               FROM invoices
              WHERE invoice_number LIKE ? AND deleted_at IS NULL
              ORDER BY id DESC
              LIMIT 6",
            [$like]
        );
        if (count($hits) === 1) {
            return ['id' => (int) $hits[0]['id']];
        }
        if ($hits) {
            return [
                'matches' => array_map(static fn ($h) => [
                    'invoice_id' => (int) $h['id'], 'invoice_number' => $h['invoice_number'],
                    'status' => $h['status'], 'customer_name' => $h['customer_name'], 'period_start' => $h['billing_period_start'],
                ], $hits),
                'note' => 'Several invoices match. Ask which one, or call again with its invoice_id.',
            ];
        }
        return ['error' => true, 'message' => "No invoice found with number '{$number}'."];
    }

    /**
     * A lease's invoices, newest period first (capped), with status counts
     * and (money viewers) issued totals per currency.
     *
     * @return array
     */
    private static function leaseInvoices(int $leaseId, bool $money): array
    {
        $lease = \db_row(
            "SELECT l.id, l.contract_number, l.status, l.start_date, l.end_date, l.actual_return_date,
                    l.billing_cycle, l.currency, l.last_billed_date,
                    COALESCE(c.company_name, l.company_name_snapshot) AS customer_name,
                    COALESCE(eu.unit_number, l.unit_number_snapshot) AS unit_number
               FROM leases l
               LEFT JOIN customers c ON c.id = l.customer_id
               LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
              WHERE l.id = ? AND l.deleted_at IS NULL",
            [$leaseId]
        );
        if (!$lease) {
            return ['error' => true, 'message' => "No lease found with ID {$leaseId}."];
        }

        $total = (int) \db_count("SELECT COUNT(*) FROM invoices WHERE lease_id = ? AND deleted_at IS NULL", [$leaseId]);
        $rows  = \db_select(
            "SELECT id, invoice_number, invoice_type, billing_type, status,
                    billing_period_start, billing_period_end, invoice_date, due_date,
                    currency, total_amount, balance_due
               FROM invoices
              WHERE lease_id = ? AND deleted_at IS NULL
              ORDER BY billing_period_start DESC, id DESC
              LIMIT " . self::LIST_CAP,
            [$leaseId]
        );

        $byStatus = [];
        foreach (\db_select(
            "SELECT status, COUNT(*) AS n FROM invoices WHERE lease_id = ? AND deleted_at IS NULL GROUP BY status",
            [$leaseId]
        ) as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }

        $out = [
            'lease' => [
                'lease_id'         => (int) $lease['id'],
                'contract_number'  => $lease['contract_number'],
                'customer_name'    => $lease['customer_name'],
                'unit_number'      => $lease['unit_number'],
                'status'           => $lease['status'],
                'billing_cycle'    => $lease['billing_cycle'],
                'start_date'       => $lease['start_date'],
                'end_date'         => $lease['end_date'],
                'return_date'      => $lease['actual_return_date'],
                'last_billed_date' => $lease['last_billed_date'],
            ],
            'invoice_count' => $total,
            'by_status'     => $byStatus,
            'invoices'      => array_map(static function (array $r) use ($money): array {
                $row = [
                    'invoice_id'     => (int) $r['id'],
                    'invoice_number' => $r['invoice_number'],
                    'type'           => $r['invoice_type'] === 'regular' ? $r['billing_type'] : $r['invoice_type'] . '/' . $r['billing_type'],
                    'period'         => $r['billing_period_start'] . '..' . $r['billing_period_end'],
                    'status'         => $r['status'],
                    'invoice_date'   => $r['invoice_date'],
                    'due_date'       => $r['due_date'],
                ];
                if ($money) {
                    $row['total']    = $r['total_amount'];
                    $row['balance']  = $r['balance_due'];
                    $row['currency'] = $r['currency'];
                }
                return $row;
            }, $rows),
        ];

        if ($money) {
            // Issued = sent/partially_paid/paid/overdue: drafts, voids and
            // write-offs are not revenue (reporting policy). SUM over DECIMAL
            // columns is exact; no PHP float touches the figures.
            $totals = [];
            foreach (\db_select(
                "SELECT currency, SUM(total_amount) AS issued, SUM(balance_due) AS open_balance
                   FROM invoices
                  WHERE lease_id = ? AND deleted_at IS NULL AND status IN ('sent','partially_paid','paid','overdue')
                  GROUP BY currency",
                [$leaseId]
            ) as $r) {
                $totals[$r['currency']] = ['issued_total' => bcadd((string) $r['issued'], '0', 2), 'open_balance' => bcadd((string) $r['open_balance'], '0', 2)];
            }
            $out['issued_totals'] = $totals;
            $out['totals_note']   = 'Issued totals exclude drafts, void and written-off invoices.';
        } else {
            $out['amounts_hidden'] = 'Dollar amounts are hidden for your role.';
        }
        if ($total > self::LIST_CAP) {
            $out['note'] = 'Showing the ' . self::LIST_CAP . " most recent of {$total} invoices.";
        }
        return $out;
    }

    /** Non-zero GST/PST/HST as {tax: {rate, amount}}. */
    private static function taxBreakdown(array $inv): array
    {
        $out = [];
        foreach (['gst', 'pst', 'hst'] as $t) {
            if (bccomp((string) $inv["tax_{$t}_amount"], '0', 2) !== 0 || bccomp((string) $inv["tax_{$t}_rate"], '0', 4) !== 0) {
                $out[$t] = ['rate' => $inv["tax_{$t}_rate"], 'amount' => $inv["tax_{$t}_amount"]];
            }
        }
        return $out;
    }

    /** QuickBooks link state for an invoice (null = never queued / table absent). */
    private static function qboState(int $invoiceId): ?array
    {
        try {
            $m = \db_row(
                "SELECT push_status, qbo_doc_number, origin, pushed_at, last_synced_at, push_error
                   FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
                [$invoiceId]
            );
        } catch (\Throwable $e) {
            // A deployment without the QBO tables must not break the invoice answer.
            return null;
        }
        if (!$m) {
            return ['state' => 'not synced to QuickBooks'];
        }
        return array_filter([
            'state'          => $m['push_status'],
            'qbo_doc_number' => $m['qbo_doc_number'],
            'origin'         => $m['origin'],
            'pushed_at'      => $m['pushed_at'],
            'last_synced_at' => $m['last_synced_at'],
            'error'          => $m['push_error'] !== null ? mb_substr((string) $m['push_error'], 0, 200) : null,
        ], static fn ($v) => $v !== null);
    }

    /** Short "does this count as revenue" note per invoice status (reporting policy). */
    private static function revenueNote(string $status): ?string
    {
        return match ($status) {
            'draft'       => 'Draft: not sent yet, so it is not revenue and not owed.',
            'void'        => 'Void: cancelled; not revenue.',
            'written_off' => 'Written off: not revenue (bad debt).',
            default       => null,
        };
    }

    // ════════════════════════════════════════════════════════════
    //  get_billing_cycle
    // ════════════════════════════════════════════════════════════

    /**
     * One month's billing cycle: record, stage, counts, readiness (live, not
     * saved), review flags and pre-close checks. Writes nothing.
     *
     * @param  array    $input  month | reference, include_readiness, include_review
     * @param  int|null $userId current user
     * @return array
     */
    private static function getBillingCycle(array $input, ?int $userId): array
    {
        if ($deny = self::gate($userId, 'invoices', 'view', 'Billing')) {
            return $deny;
        }
        $money = self::money($userId);

        $month = trim((string) ($input['month'] ?? ''));
        $ref   = strtoupper(trim((string) ($input['reference'] ?? '')));
        if ($month === '' && preg_match('/^BC-(\d{4}-\d{2})$/', $ref, $m)) {
            $month = $m[1];
        }

        $picked = null;
        if ($month === '') {
            // No month given: the month billing is due for now, else the latest cycle.
            $target = BillingCycles::targetMonth();
            $cycle  = BillingCycles::findByMonth($target);
            $picked = "No month given, so this is the month billing is currently due for ({$target}).";
            if (!$cycle) {
                $latest = \db_row("SELECT id FROM billing_cycles ORDER BY period_start DESC LIMIT 1");
                $cycle  = $latest ? BillingCycles::find((int) $latest['id']) : null;
                $picked = $cycle
                    ? "No cycle is open for {$target} (the month now due), so this is the latest cycle."
                    : null;
            }
            if (!$cycle) {
                return [
                    'found'   => false,
                    'message' => 'No billing cycle has been opened yet. Open one on the Billing page.',
                    'url'     => \base_url('billing'),
                ];
            }
        } else {
            if (!BillingCycles::isValidMonth($month)) {
                return ['error' => true, 'message' => "Month must be 'YYYY-MM' (e.g. 2026-09), or give reference 'BC-2026-09'."];
            }
            // findByMonth only reads. ensure() would CREATE the cycle, so it is never used here.
            $cycle = BillingCycles::findByMonth($month);
            if (!$cycle) {
                $recent = array_map(
                    static fn ($r) => $r['reference'] . ' (' . $r['status'] . ')',
                    \db_select("SELECT reference, status FROM billing_cycles ORDER BY period_start DESC LIMIT 6")
                );
                return [
                    'found'          => false,
                    'month'          => $month,
                    'message'        => 'No billing cycle has been opened for ' . BillingCycles::label($month . '-01') . '. Someone with invoice access opens it on the Billing page (/billing). The assistant cannot open it.',
                    'url'            => \base_url('billing'),
                    'recent_cycles'  => $recent,
                ];
            }
        }

        $ov = BillingCycles::overview($cycle, $money);
        $stats = $ov['stats'];

        $out = [
            'cycle' => array_filter([
                'id'             => $cycle['id'],
                'reference'      => $cycle['reference'],
                'month'          => $cycle['month'],
                'label'          => $cycle['label'],
                'url'            => \base_url('billing/cycle') . '?month=' . $cycle['month'],
                'status'         => $cycle['status'],
                'owner'          => $cycle['owner_name'] ?? null,
                'opened_by'      => $cycle['opened_by'] === null ? 'scheduled job' : ($cycle['opened_by_name'] ?? null),
                'opened_at'      => $cycle['created_at'],
                'bill_by_date'   => $cycle['bill_by_date'],
                'send_by_date'   => $cycle['send_by_date'],
                'closed_at'      => $cycle['closed_at'],
                'closed_by'      => $cycle['closed_by_name'] ?? null,
                'close_note'     => $cycle['close_note'],
                'reopened_at'    => $cycle['reopened_at'],
                'reopened_by'    => $cycle['reopened_by_name'] ?? null,
                'reopen_reason'  => $cycle['reopen_reason'],
                'notes'          => $cycle['notes'],
            ], static fn ($v) => $v !== null && $v !== ''),
            'selection_note' => $picked,
            'progress' => [
                'percent' => $ov['stage']['percent'],
                'current_step' => $ov['stage']['current'],
                'steps' => array_map(static fn ($s) => array_filter([
                    'step'      => $s['label'],
                    'state'     => $s['state'],
                    'hint'      => $s['hint'],
                    'attention' => $s['attention'] ?: null,
                    'signed_off_by' => is_array($s['signoff'] ?? null) ? ($s['signoff']['by'] ?? null) . ' ' . substr((string) ($s['signoff']['at'] ?? ''), 0, 10) : null,
                ], static fn ($v) => $v !== null), $ov['stage']['steps']),
            ],
            'overdue' => array_keys(array_filter($ov['overdue'])),
            'leases' => [
                'on_rent_this_month' => array_sum($ov['coverage']),
                'by_status'          => $ov['coverage'],
                'status_meaning'     => 'billed = has an invoice this month; covered_elsewhere = an invoice from another period covers these days; held = billing hold; exception = open billing exception; bills_at_close = lease bills only when closed; closed_unbilled = closed lease with unbilled days (use the lease\'s Generate Invoice); void_rebillable = only void invoices; to_bill = still needs billing.',
            ],
            'invoices' => [
                'by_status'         => $stats['by_status'],
                'live'              => $stats['live'],
                'drafts'            => $stats['drafts'],
                'issued'            => $stats['issued'],
                'emailed'           => $stats['emailed'],
                'reviewed'          => $stats['reviewed'],
                'queried'           => $stats['queried'],
                'unreviewed_drafts' => $stats['unreviewed_drafts'],
                'open_exceptions'   => $stats['open_exceptions'],
                'approval_runs_pending' => $stats['pending_runs'],
            ],
            'readings' => $ov['readings'],
        ];
        if ($money && $stats['money'] !== null) {
            $out['money'] = $stats['money'] + ['note' => 'Totals exclude void invoices. total_cad converts USD at each invoice\'s frozen rate. Drafts are not revenue until sent.'];
        } elseif (!$money) {
            $out['amounts_hidden'] = 'Dollar amounts are hidden for your role.';
        }

        // ── Readiness (live, never saved) ─────────────────────────
        if (($input['include_readiness'] ?? true) !== false) {
            $out['readiness'] = self::liveReadiness($cycle);
        } else {
            $out['readiness'] = self::savedReadiness($cycle);
        }

        // ── Review flags ──────────────────────────────────────────
        if (($input['include_review'] ?? true) !== false) {
            $out['review'] = self::reviewSummary($cycle, $money);
        }

        // ── Pre-close checks ──────────────────────────────────────
        $pre = CycleClose::preCloseChecks($cycle);
        $out['close_check'] = [
            'can_close'          => $pre['can_close'],
            'must_fix'           => array_column($pre['hard'], 'text'),
            'needs_override_note' => array_column($pre['soft'], 'text'),
            'note'               => 'must_fix items block closing. needs_override_note items can be overridden with a written reason when closing.',
        ];

        return array_filter($out, static fn ($v) => $v !== null);
    }

    /**
     * Run every BillingReadiness check WITHOUT saving the summary.
     *
     * WHY evaluate(): BillingReadiness::run() stamps readiness_checked_at /
     * readiness_summary on the cycle (and so marks Prepare done) — this tool
     * must not write. evaluate() is run()'s read half: the page's exact 27
     * checks, same acknowledgements, no UPDATE. If it throws, the last saved
     * summary is returned instead, labelled as such.
     *
     * @return array summary + blockers/warnings/info with examples
     */
    private static function liveReadiness(array $cycle): array
    {
        try {
            $report = BillingReadiness::evaluate($cycle);
        } catch (\Throwable $e) {
            return self::savedReadiness($cycle) + ['warning' => 'Live checks unavailable; showing the last saved result.'];
        }

        $groups = ['blocker' => [], 'warning' => [], 'info' => []];
        $errors = [];
        foreach ($report['checks'] as $c) {
            if (!empty($c['error'])) {
                $errors[] = $c['key'];
                continue;
            }
            if ((int) $c['count'] === 0) {
                continue;
            }
            $ack = $c['acknowledged'] ?? null;
            $groups[$c['severity']][] = array_filter([
                'check'    => $c['key'],
                'title'    => $c['title'],
                'count'    => (int) $c['count'],
                'why'      => $c['severity'] !== 'info' ? $c['why'] : null,
                'fix'      => $c['fix'] ?: null,
                'acknowledged_by' => is_array($ack) ? trim(($ack['by'] ?? '') . ' ' . substr((string) ($ack['at'] ?? ''), 0, 10)) : null,
                'examples' => array_map(
                    static fn ($it) => trim(($it['label'] ?? '') . (isset($it['detail']) && $it['detail'] !== '' ? ' — ' . $it['detail'] : '')),
                    array_slice($c['items'] ?? [], 0, self::EXAMPLES)
                ),
            ], static fn ($v) => $v !== null && $v !== []);
        }

        return array_filter([
            'ran_live'  => true,
            'summary'   => $report['summary'],
            'blockers'  => $groups['blocker'],
            'warnings'  => $groups['warning'],
            'info'      => array_map(static fn ($c) => ['title' => $c['title'], 'count' => $c['count']], $groups['info']),
            'checks_that_failed_to_run' => $errors ?: null,
            'last_saved_run' => $cycle['readiness_checked_at'] !== null
                ? ['checked_at' => $cycle['readiness_checked_at'], 'summary' => $cycle['readiness_summary']]
                : 'Never run on the cycle page (the Prepare step counts as not done until someone runs it there).',
            'note' => 'Checked just now for this answer; not saved to the cycle. Blockers stop generation; warnings can be fixed or acknowledged on the cycle page (Prepare step).',
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /** The readiness result last saved on the cycle page (no live run). */
    private static function savedReadiness(array $cycle): array
    {
        return [
            'ran_live'   => false,
            'checked_at' => $cycle['readiness_checked_at'],
            'summary'    => $cycle['readiness_summary'],
            'note'       => $cycle['readiness_checked_at'] === null
                ? 'The readiness checks have never been run for this cycle.'
                : 'Summary saved the last time the checks ran on the cycle page.',
        ];
    }

    /**
     * BillingReview::run() cut down for the model: flag counts, the flagged
     * invoices (danger/warning first, capped), and leases billed last month
     * with nothing this month.
     */
    private static function reviewSummary(array $cycle, bool $money): array
    {
        $rev = BillingReview::run($cycle, $money);
        $flagged = array_values(array_filter($rev['rows'], static fn ($r) => in_array($r['worst'], ['danger', 'warning'], true)));
        $queried = array_values(array_filter($rev['rows'], static fn ($r) => ($r['review']['status'] ?? null) === 'query'));

        $shape = static fn (array $r): array => array_filter([
            'invoice_number' => $r['invoice_number'],
            'invoice_id'     => $r['invoice_id'],
            'customer'       => $r['company_name'],
            'contract'       => $r['contract_number'],
            'status'         => $r['status'],
            'total'          => $money ? $r['total_amount'] : null,
            'last_month'     => $money ? $r['previous_total'] : null,
            'flags'          => array_map(static fn ($f) => $f['severity'] . ': ' . $f['text'], array_filter($r['flags'], static fn ($f) => $f['severity'] !== 'info')),
            'review'         => $r['review'] ? trim($r['review']['status'] . ' ' . ($r['review']['note'] ?? '')) : null,
        ], static fn ($v) => $v !== null && $v !== []);

        return array_filter([
            'invoices_reviewed_against_last_month' => count($rev['rows']),
            'flag_counts'   => $rev['flag_counts'],
            'flagged'       => array_map($shape, array_slice($flagged, 0, 15)),
            'flagged_total' => count($flagged),
            'queried'       => $queried ? array_map($shape, array_slice($queried, 0, 10)) : null,
            'missing_this_month' => [
                'count'  => count($rev['missing']),
                'leases' => array_map(
                    static fn ($m) => $m['contract_number'] . ' · ' . $m['company_name'] . ' (' . $m['status'] . ')',
                    array_slice($rev['missing'], 0, 10)
                ),
            ],
            'swing_threshold' => $rev['thresholds'],
        ], static fn ($v) => $v !== null && $v !== []);
    }

    // ════════════════════════════════════════════════════════════
    //  get_billing_holds
    // ════════════════════════════════════════════════════════════

    /**
     * Billing holds via BillingHolds::list (read-only).
     *
     * @param  array    $input  state, customer_id, lease_id
     * @param  int|null $userId current user
     * @return array
     */
    private static function getBillingHolds(array $input, ?int $userId): array
    {
        if ($deny = self::gate($userId, 'invoices', 'view', 'Billing holds')) {
            return $deny;
        }
        $state = (string) ($input['state'] ?? 'active');
        if (!in_array($state, ['active', 'released', 'all'], true)) {
            $state = 'active';
        }
        $customerId = (int) ($input['customer_id'] ?? 0) ?: null;
        $leaseId    = (int) ($input['lease_id'] ?? 0) ?: null;

        $rows  = BillingHolds::list($state, $customerId, $leaseId);
        $today = \ff_today();

        $holds = array_map(static fn ($h) => array_filter([
            'id'          => (int) $h['id'],
            'scope'       => $h['scope'] === 'customer' ? 'whole customer (' . (int) $h['customer_active_leases'] . ' active lease(s))' : 'one lease',
            'customer'    => $h['company_name'],
            'customer_id' => (int) $h['customer_id'],
            'lease_id'    => $h['lease_id'] !== null ? (int) $h['lease_id'] : null,
            'contract'    => $h['contract_number'],
            'unit'        => $h['unit_number'],
            'reason'      => $h['reason'],
            'from'        => $h['starts_on'],
            'until'       => $h['ends_on'] ?? 'until released',
            'active'      => $h['released_at'] === null && ($h['ends_on'] === null || $h['ends_on'] >= $today),
            'placed_by'   => trim(($h['created_by_name'] ?? '') . ' ' . substr((string) $h['created_at'], 0, 10)),
            'released'    => $h['released_at'] !== null
                ? trim(substr((string) $h['released_at'], 0, 10) . ' by ' . ($h['released_by_name'] ?? '?') . ($h['release_note'] ? ': ' . $h['release_note'] : ''))
                : null,
        ], static fn ($v) => $v !== null && $v !== ''), $rows);

        return array_filter([
            'state' => $state,
            'count' => count($holds),
            'holds' => array_slice($holds, 0, self::LIST_CAP),
            'note'  => (count($holds) > self::LIST_CAP ? 'Showing ' . self::LIST_CAP . ' of ' . count($holds) . '. ' : '')
                . ($holds === [] ? 'No holds match. ' : '')
                . 'A hold keeps a lease out of monthly generation (a lease\'s own Generate Invoice / close still bills it, and the cycle Review flags that). Holds are placed and released on the Billing page, Holds tab.',
        ], static fn ($v) => $v !== null);
    }

    // ════════════════════════════════════════════════════════════
    //  get_billing_charges
    // ════════════════════════════════════════════════════════════

    /**
     * Queued charges via BillingCharges::list (read-only; billed state derived).
     *
     * @param  array    $input  state, month, lease_id, customer_id
     * @param  int|null $userId current user
     * @return array
     */
    private static function getBillingCharges(array $input, ?int $userId): array
    {
        if ($deny = self::gate($userId, 'invoices', 'view', 'Billing charges')) {
            return $deny;
        }
        $money = self::money($userId);

        $state = (string) ($input['state'] ?? 'pending');
        if (!in_array($state, ['pending', 'billed', 'cancelled', 'all'], true)) {
            $state = 'pending';
        }
        $month = trim((string) ($input['month'] ?? ''));
        if ($month !== '' && !BillingCycles::isValidMonth($month)) {
            return ['error' => true, 'message' => "Month must be 'YYYY-MM'."];
        }

        $rows = BillingCharges::list([
            'state'       => $state,
            'month'       => $month,
            'lease_id'    => (int) ($input['lease_id'] ?? 0) ?: null,
            'customer_id' => (int) ($input['customer_id'] ?? 0) ?: null,
        ]);

        $charges = [];
        $pendingTotals = [];
        foreach ($rows as $r) {
            $c = [
                'id'          => $r['id'],
                'customer'    => $r['company_name'],
                'lease_id'    => $r['lease_id'],
                'contract'    => $r['contract_number'],
                'unit'        => $r['unit_number'],
                'description' => $r['description'],
                'kind'        => $r['item_label'],
                'recurrence'  => $r['recurrence'],
                'bill_from'   => $r['bill_from'],
                'bill_until'  => $r['bill_until'],
                'state'       => $r['state'],
                'billed_on'   => $r['billed_on'] ? array_map(static fn ($b) => $b['invoice_number'] . ' (' . $b['month'] . ', ' . $b['status'] . ')', $r['billed_on']) : null,
                'cancelled'   => $r['status'] === 'cancelled' ? trim(substr((string) $r['cancelled_at'], 0, 10) . ' ' . ($r['cancel_reason'] ?? '')) : null,
                'notes'       => $r['notes'],
            ];
            if ($money) {
                $c['quantity']   = self::trimDecimal((string) $r['quantity']);
                $c['unit_price'] = $r['unit_price'];
                $c['amount']     = $r['amount'];
                $c['currency']   = $r['currency'];
                $c['taxable']    = $r['taxable'];
                if (in_array($r['state'], ['pending', 'recurring'], true)) {
                    // bcmath: charge amounts are DECIMAL strings; totals per lease currency.
                    $cur = (string) $r['currency'];
                    $pendingTotals[$cur] = bcadd($pendingTotals[$cur] ?? '0', (string) $r['amount'], 2);
                }
            }
            $charges[] = array_filter($c, static fn ($v) => $v !== null && $v !== '');
        }

        return array_filter([
            'state'   => $state,
            'month'   => $month !== '' ? $month : null,
            'count'   => count($charges),
            'charges' => array_slice($charges, 0, self::LIST_CAP),
            'pending_total_by_currency' => $pendingTotals ?: null,
            'amounts_hidden' => $money ? null : 'Charge amounts are hidden for your role.',
            'note'    => (count($charges) > self::LIST_CAP ? 'Showing ' . self::LIST_CAP . ' of ' . count($charges) . '. ' : '')
                . ($charges === [] ? 'No charges match. ' : '')
                . 'A once charge rides on the lease\'s next rental invoice; a monthly charge bills once each month. "pending" includes recurring monthly charges. Pre-tax amounts in the lease currency. Queued and cancelled on the Billing page, Charges tab.',
        ], static fn ($v) => $v !== null);
    }

    // ════════════════════════════════════════════════════════════
    //  get_price_check
    // ════════════════════════════════════════════════════════════

    /**
     * RateResolver::explain (+ quote) for a customer / equipment type / date.
     *
     * @param  array    $input  customer_id|customer_name, equipment_template_id|equipment_type,
     *                          date, start_date, end_date, gps, distance_per_day, hours_per_day
     * @param  int|null $userId current user
     * @return array
     */
    private static function getPriceCheck(array $input, ?int $userId): array
    {
        // WHY rates:view and not only can_view_financials: this is the Rates
        // module's Price check, and dispatchers have rates = none.
        if ($deny = self::gate($userId, 'rates', 'view', 'The price check (Rates)')) {
            return $deny;
        }

        // ── Equipment type ────────────────────────────────────────
        $templateId = (int) ($input['equipment_template_id'] ?? 0);
        if ($templateId <= 0) {
            $search = trim((string) ($input['equipment_type'] ?? ''));
            if ($search === '') {
                return ['error' => true, 'message' => 'Give equipment_template_id or an equipment_type name to search.'];
            }
            $found = self::findTemplate($search);
            if (!isset($found['id'])) {
                return $found;
            }
            $templateId = $found['id'];
        }
        $template = RateResolver::template($templateId);
        if (!$template) {
            return ['error' => true, 'message' => "No equipment type found with ID {$templateId}."];
        }

        // ── Customer (optional: none = standard price) ────────────
        $customer   = null;
        $customerId = (int) ($input['customer_id'] ?? 0);
        $custName   = trim((string) ($input['customer_name'] ?? ''));
        if ($customerId > 0) {
            $customer = \db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
            if (!$customer) {
                return ['error' => true, 'message' => "No customer found with ID {$customerId}."];
            }
        } elseif ($custName !== '') {
            $hits = \db_select(
                "SELECT id, company_name FROM customers
                  WHERE deleted_at IS NULL AND company_name LIKE ?
                  ORDER BY (company_name = ?) DESC, company_name LIMIT 8",
                ['%' . addcslashes($custName, '%_\\') . '%', $custName]
            );
            if (!$hits) {
                return ['error' => true, 'message' => "No customer matches '{$custName}'. Use search_customers, or omit the customer for the standard price."];
            }
            if (count($hits) > 1 && strcasecmp((string) $hits[0]['company_name'], $custName) !== 0) {
                return [
                    'matches' => array_map(static fn ($h) => ['customer_id' => (int) $h['id'], 'name' => $h['company_name']], $hits),
                    'note'    => 'Several customers match. Ask which one, or call again with customer_id.',
                ];
            }
            $customer = $hits[0];
        }

        // ── Dates ─────────────────────────────────────────────────
        $date = \ff_today();
        if (!empty($input['date'])) {
            $date = RateCardItems::validDate($input['date']);
            if ($date === null) {
                return ['error' => true, 'message' => 'date must be YYYY-MM-DD.'];
            }
        }

        $ex = RateResolver::explain($customer ? (int) $customer['id'] : null, $template, $date);
        $p  = $ex['price'];
        $s  = $ex['standard'];

        $prices = static fn (array $r): array => array_filter([
            'daily'   => $r['daily_rate'] ?? null,
            'weekly'  => $r['weekly_rate'] ?? null,
            'monthly' => $r['monthly_rate'] ?? null,
            'mileage' => ($r['mileage_rate'] ?? null) !== null && bccomp((string) $r['mileage_rate'], '0', 4) > 0
                ? $r['mileage_rate'] . '/' . ($r['mileage_unit'] ?? 'km') : null,
            'hourly'  => $r['hourly_rate'] ?? null,
            'gps_per_day' => $r['gps_price'] ?? null,
            'minimum_days' => $r['minimum_days'] ?? null,
            'currency' => $r['currency'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $out = [
            'customer'       => $customer ? ['id' => (int) $customer['id'], 'name' => $customer['company_name']] : 'none (standard price)',
            'equipment_type' => [
                'id'       => (int) $template['id'],
                'name'     => $template['name'],
                'category' => RateCardItems::label((string) $template['category']),
                'active'   => (int) $template['is_active'] === 1,
            ],
            'date'           => $date,
            'price'          => $prices($p),
            'source'         => $p['source'],
            'source_label'   => $p['source_label'],
            'rate_card_id'   => $p['rate_card_id'],
            'line_scope'     => $p['line_scope'] === 'category' ? 'whole-category line' : ($p['line_scope'] === 'type' ? 'line for this exact equipment type' : null),
            'how_it_was_chosen' => array_map(static fn ($t) => $t['label'] . ': ' . $t['state'] . ' (' . $t['detail'] . ')', $ex['tiers']),
            'candidate_lines' => array_map(static fn ($c) => array_filter([
                'rank'      => $c['rank'],
                'card'      => $c['card_name'] . ($c['is_customer'] ? ' (customer card)' : ($c['is_default'] ? ' (main price list)' : ' (general card)')),
                'scope'     => $c['line_scope'],
                'in_force'  => $c['effective_from'] . ' to ' . ($c['effective_to'] ?? 'open'),
                'daily'     => $c['prices']['daily_rate'],
                'weekly'    => $c['prices']['weekly_rate'],
                'monthly'   => $c['prices']['monthly_rate'],
                'why'       => $c['why'],
            ], static fn ($v) => $v !== null), array_slice($ex['candidates'], 0, 8)),
            'standard_price' => ['prices' => $prices($s), 'source_label' => $s['source_label']],
        ];
        // How far this customer's price sits from standard (only when they differ).
        if ($customer && $p['source'] === 'customer') {
            $out['vs_standard_pct'] = array_filter([
                'daily'   => RateResolver::pctDiff($p['daily_rate'], $s['daily_rate']),
                'weekly'  => RateResolver::pctDiff($p['weekly_rate'], $s['weekly_rate']),
                'monthly' => RateResolver::pctDiff($p['monthly_rate'], $s['monthly_rate']),
            ], static fn ($v) => $v !== null);
        }
        if (count($ex['candidates']) > 8) {
            $out['candidate_note'] = 'Showing 8 of ' . count($ex['candidates']) . ' candidate lines.';
        }

        // ── Quote (optional) ──────────────────────────────────────
        $startIn = $input['start_date'] ?? null;
        $endIn   = $input['end_date'] ?? null;
        if (!empty($startIn) || !empty($endIn)) {
            $start = RateCardItems::validDate($startIn);
            $end   = RateCardItems::validDate($endIn);
            if ($start === null || $end === null) {
                return $out + ['quote_error' => 'A quote needs both start_date and end_date as YYYY-MM-DD.'];
            }
            if ($end < $start) {
                return $out + ['quote_error' => 'end_date must be on or after start_date.'];
            }
            if ((new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days > 1100) {
                return $out + ['quote_error' => 'Quote at most three years at a time.'];
            }
            // Numbers pass to quote() as strings: it runs clean_decimal + bcmath on them.
            $num = static fn ($v) => ($v === null || $v === '') ? null : (string) $v;
            $q = RateResolver::quote($ex['price'], $template, $start, $end, [
                'gps'              => !empty($input['gps']),
                'distance_per_day' => $num($input['distance_per_day'] ?? null),
                'hours_per_day'    => $num($input['hours_per_day'] ?? null),
            ]);
            $out['quote'] = array_filter([
                'start'        => $q['start'],
                'end'          => $q['end'],
                'days'         => $q['days'],
                'currency'     => $q['currency'],
                'minimum_days' => $q['minimum_days'] > 0 ? $q['minimum_days'] . ' (from ' . $q['minimum_from'] . ')' : null,
                'lines'        => array_map(static fn ($l) => ['item' => $l['label'], 'amount' => $l['amount'], 'working' => $l['detail']], $q['lines']),
                'total_before_tax' => $q['total'],
                'per_day'      => $q['per_day'],
                'warnings'     => $q['warnings'] ?: null,
                'note'         => 'Estimate using the billing engine\'s rules, before tax. The real invoices depend on the actual dates and readings.',
            ], static fn ($v) => $v !== null);
        }

        $out['url'] = \base_url('rates') . '#check';
        return array_filter($out, static fn ($v) => $v !== null);
    }

    /**
     * Equipment type by name: exact (case-insensitive) match wins; one partial
     * match is used; several → a choice list.
     *
     * @return array{id:int}|array
     */
    private static function findTemplate(string $search): array
    {
        $needle = mb_strtolower($search);
        $exact = [];
        $partial = [];
        foreach (RateInsights::templates() as $id => $t) {
            $name = mb_strtolower((string) $t['name']);
            if ($name === $needle) {
                $exact[] = $id;
            } elseif (str_contains($name, $needle) || str_contains(mb_strtolower((string) $t['category']), $needle)) {
                $partial[] = $id;
            }
        }
        if (count($exact) === 1) {
            return ['id' => $exact[0]];
        }
        if (!$exact && count($partial) === 1) {
            return ['id' => $partial[0]];
        }
        $ids = $exact ?: $partial;
        if (!$ids) {
            return ['error' => true, 'message' => "No equipment type matches '{$search}'. Try a shorter name, or use search_equipment to find the type."];
        }
        $all = RateInsights::templates();
        return [
            'matches' => array_map(static fn ($id) => [
                'equipment_template_id' => (int) $id,
                'name'     => $all[$id]['name'],
                'category' => $all[$id]['category'],
                'active'   => (int) $all[$id]['is_active'] === 1,
            ], array_slice($ids, 0, 15)),
            'note' => 'Several equipment types match. Ask which one, or call again with equipment_template_id.',
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  Shared helpers
    // ════════════════════════════════════════════════════════════

    /**
     * Permission gate. Null $userId = system/cron (full access, as in
     * FleetForgeTools). Returns null when allowed, else the denial payload
     * naming the roles that have the permission by default.
     */
    private static function gate(?int $userId, string $module, string $action, string $what): ?array
    {
        if ($userId === null || \can($module, $action)) {
            return null;
        }
        $roles = self::rolesWith($module, $action);
        return [
            'error'   => true,
            'message' => "{$what} is not available to your role (needs {$module}:{$action})."
                . ($roles ? ' By default these roles can see it: ' . implode(', ', $roles) . '.' : '')
                . ' Ask an administrator if you need access.',
        ];
    }

    /** May this user see money? Null user = system → yes. */
    private static function money(?int $userId): bool
    {
        return $userId === null || \can_view_financials();
    }

    /** Role labels whose factory permissions grant $module:$action (for denial messages). */
    private static function rolesWith(string $module, string $action): array
    {
        static $perms = null;
        if ($perms === null) {
            $file  = (defined('FF_ROOT') ? FF_ROOT : dirname(__DIR__, 3)) . '/config/permissions.php';
            $perms = is_file($file) ? (array) (require $file) : [];
        }
        $out = [];
        foreach ($perms as $slug => $mods) {
            if ($slug === 'super_admin' || !empty($mods[$module][$action])) {
                $out[] = ucwords(str_replace('_', ' ', (string) $slug));
            }
        }
        return $out;
    }

    /** CAD value of an amount (USD × frozen rate, rounded half-up to cents; CAD as is). */
    private static function toCad(string $amount, string $currency, mixed $rate): string
    {
        if ($currency !== 'USD') {
            return $amount;
        }
        $r = ($rate === null || $rate === '') ? '1' : (string) $rate;
        // Same result as BillingCycles::cadSql (SQL ROUND(x * rate, 2)), in bcmath.
        $raw = bcmul($amount, $r, 8);
        $half = str_starts_with($raw, '-') ? '-0.005' : '0.005';
        return bcadd(bcadd($raw, $half, 8), '0', 2);
    }

    /** "3.0000" → "3", "2.5000" → "2.5" (quantities read better without padding). */
    private static function trimDecimal(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }
}
