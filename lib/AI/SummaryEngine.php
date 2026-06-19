<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/SummaryEngine.php
 *
 * Generates and caches AI-powered summaries for FleetForge entities.
 * Summaries are stored in the ai_summaries table with expiration.
 *
 * Summary types (from ai_summaries.summary_type ENUM):
 *   - lease_summary       — Key lease info, billing status, risk factors
 *   - customer_insights   — Customer health, payment patterns, risk
 *   - fleet_health        — Overall fleet status, utilization, maintenance
 *   - unit_analysis       — Individual unit condition, cost analysis
 *   - payment_risk        — Payment behavior patterns, overdue risk
 *   - forecast            — Revenue/demand forecasting
 *   - anomaly             — Detected anomalies explanation
 *   - accounting_overview — Trial balance, AR/AP, P&L, cash position
 *                           (fleet-wide, no entity_id required)
 *
 * Caching:
 *   - Summaries cached in ai_summaries table (is_current = 1)
 *   - Default TTL: 24 hours (configurable via ai.summary_ttl_hours)
 *   - Cache can be bypassed with $forceRefresh parameter
 *   - Only one "current" summary per (entity_type, entity_id, summary_type)
 *
 * @depends lib/AI/ClaudeClient.php, lib/AI/Tools/FleetForgeTools.php
 * @session S027
 */
class SummaryEngine
{
    /** Default cache TTL in hours */
    private const DEFAULT_TTL_HOURS = 24;

    // ────────────────────────────────────────────────────────────
    // generate()
    //
    // Generate (or retrieve cached) summary for an entity.
    // Returns the summary text, or null on failure.
    //
    // @param  string   $entityType    Entity type (customer, lease, equipment_unit, fleet)
    // @param  int      $entityId      Entity ID (0 for fleet-level summaries)
    // @param  string   $summaryType   One of the ENUM values above
    // @param  int|null $userId        Current user (for permissions + tracking)
    // @param  bool     $forceRefresh  Skip cache and regenerate
    // @return array|null              {summary: string, generated_at: string, cached: bool}
    // ────────────────────────────────────────────────────────────
    public static function generate(
        string $entityType,
        int    $entityId,
        string $summaryType,
        ?int   $userId = null,
        bool   $forceRefresh = false,
        array  $reportContext = []
    ): ?array {
        // WHY: Check cache first unless explicitly refreshing.
        // Date-range reports (P&L / BS / CF / budget_variance) skip cache
        // because the same (entity_type, entity_id, summary_type) tuple can
        // legitimately produce different narratives for different ranges.
        $skipCacheForDateRange = in_array($summaryType, [
            'pl_narrative', 'bs_narrative', 'cashflow_narrative', 'budget_variance',
        ], true);
        if (!$forceRefresh && !$skipCacheForDateRange && (bool) settings_get('ai.cache_summaries', true)) {
            $cached = self::getCached($entityType, $entityId, $summaryType);
            if ($cached !== null) {
                return [
                    'summary'      => $cached['content'],
                    'generated_at' => $cached['generated_at'],
                    'cached'       => true,
                ];
            }
        }

        // Build the context data and prompt for this summary type
        $context = self::gatherContext($entityType, $entityId, $summaryType, $userId, $reportContext);
        if ($context === null) {
            return null;
        }

        $prompt = self::buildPrompt($summaryType, $context);

        // Generate via Claude
        $ai = new ClaudeClient();
        if (!$ai->isEnabled()) {
            return null;
        }

        $response = $ai->sendMessage(
            messages:     [['role' => 'user', 'content' => $prompt]],
            systemPrompt: self::getSystemPrompt(),
            tools:        [],
            maxTokens:    4096,
            userId:       $userId,
            queryType:    'summary'
        );

        if ($response === null) {
            return null;
        }

        $summaryText = ClaudeClient::extractTextContent($response);
        if ($summaryText === '') {
            return null;
        }

        $tokensUsed = ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

        // WHY: Store in cache — mark old summaries as not current first
        self::cacheSummary($entityType, $entityId, $summaryType, $summaryText, $tokensUsed, $ai->getModel(), $userId);

        return [
            'summary'      => $summaryText,
            'generated_at' => date('Y-m-d H:i:s'),
            'cached'       => false,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getCached()
    //
    // Retrieves a non-expired cached summary. Returns null if
    // no valid cache exists.
    // ────────────────────────────────────────────────────────────
    private static function getCached(string $entityType, int $entityId, string $summaryType): ?array
    {
        return db_row(
            "SELECT content, generated_at, expires_at
             FROM ai_summaries
             WHERE entity_type = ? AND entity_id = ? AND summary_type = ?
               AND is_current = 1
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY generated_at DESC
             LIMIT 1",
            [$entityType, $entityId, $summaryType]
        );
    }

    // ────────────────────────────────────────────────────────────
    // cacheSummary()
    //
    // Upserts a generated summary into ai_summaries (one row per
    // entity+type, keyed by UNIQUE(entity_type,entity_id,summary_type)).
    // ────────────────────────────────────────────────────────────
    public static function cacheSummary(
        string $entityType,
        int    $entityId,
        string $summaryType,
        string $content,
        int    $tokensUsed,
        string $model,
        ?int   $userId
    ): void {
        try {
            $ttlHours  = (int) settings_get('ai.summary_ttl_hours', self::DEFAULT_TTL_HOURS);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlHours} hours"));
            $now       = date('Y-m-d H:i:s');

            // WHY: ai_summaries has UNIQUE(entity_type,entity_id,summary_type) — at
            // most one row per tuple. The old "UPDATE is_current=0 then INSERT the
            // same tuple" collided with that key (1062) on EVERY regeneration; the
            // error was swallowed and the row left is_current=0, so getCached()
            // returned null forever and each later request re-billed Claude
            // (S-AI-AUDIT-HIGH-FIX). Upsert in place instead — the dead is_current
            // "history" rotation is impossible under the unique key.
            db_execute(
                "INSERT INTO ai_summaries
                    (entity_type, entity_id, summary_type, content, tokens_used,
                     model_used, generated_at, expires_at, generated_by, is_current)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    content      = VALUES(content),
                    tokens_used  = VALUES(tokens_used),
                    model_used   = VALUES(model_used),
                    generated_at = VALUES(generated_at),
                    expires_at   = VALUES(expires_at),
                    generated_by = VALUES(generated_by),
                    is_current   = 1",
                [$entityType, $entityId, $summaryType, $content, $tokensUsed,
                 $model, $now, $expiresAt, $userId]
            );
        } catch (\Throwable $e) {
            // Cache write failure is non-fatal to the user (the summary already
            // streamed), but DON'T swallow it silently — a schema mismatch (e.g. a
            // summary_type missing from the ENUM → 1265) would otherwise hide here
            // forever while re-billing tokens. Log it so the drift is observable.
            self::logCacheFailure($summaryType, $e);
        }
    }

    /** Append a one-line cache-write failure to logs/ai.log (never throws). */
    private static function logCacheFailure(string $summaryType, \Throwable $e): void
    {
        try {
            $logPath = dirname(__DIR__, 2) . '/logs/ai.log';
            $canWrite = file_exists($logPath) ? is_writable($logPath) : is_writable(dirname($logPath));
            if (!$canWrite) {
                return;
            }
            $line = sprintf(
                "[%s] [WARN] cacheSummary failed (summary_type=%s): %s\n",
                date('Y-m-d H:i:s'),
                $summaryType,
                $e->getMessage()
            );
            file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // logging must never crash the request
        }
    }

    // ────────────────────────────────────────────────────────────
    // gatherContext()
    //
    // Collects relevant data for the summary by calling the
    // appropriate tool handlers directly.
    // ────────────────────────────────────────────────────────────
    public static function gatherContext(string $entityType, int $entityId, string $summaryType, ?int $userId, array $reportContext = []): ?array
    {
        try {
            return match ($summaryType) {
                'customer_insights'   => self::gatherCustomerContext($entityId, $userId),
                'lease_summary'       => self::gatherLeaseContext($entityId, $userId),
                'unit_analysis'       => self::gatherUnitContext($entityId),
                'fleet_health'        => self::gatherFleetContext(),
                'payment_risk'        => self::gatherPaymentRiskContext($entityId, $userId),
                'accounting_overview' => self::gatherAccountingContext($userId),
                'invoice_analysis'    => self::gatherInvoiceContext($entityId),
                'reservation_summary' => self::gatherReservationContext($entityId),
                'vendor_summary'      => self::gatherVendorContext($entityId, $userId),
                'payment_summary'     => self::gatherPaymentContext($entityId, $userId),
                // ── S036 Phase B accounting narratives ──────────────
                'pl_narrative'        => self::gatherPLContext($reportContext, $userId),
                'bs_narrative'        => self::gatherBSContext($reportContext, $userId),
                'cashflow_narrative'  => self::gatherCashFlowContext($reportContext, $userId),
                'budget_variance'     => self::gatherBudgetVarianceContext($entityId, $reportContext, $userId),
                default               => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pull a P&L block for the requested date range. Drill-down JE-line
     * ids are stripped to keep the prompt small.
     */
    private static function gatherPLContext(array $ctx, ?int $userId): ?array
    {
        $from = (string) ($ctx['from'] ?? date('Y-01-01'));
        $to   = (string) ($ctx['to']   ?? date('Y-m-d'));
        $report = \FleetForge\Accounting\ReportingService::profitAndLoss($from, $to);
        foreach (['revenue', 'direct_costs', 'operating_expenses', 'other'] as $g) {
            foreach ($report[$g] as &$row) unset($row['je_line_ids']);
            unset($row);
        }
        return ['report' => $report];
    }

    private static function gatherBSContext(array $ctx, ?int $userId): ?array
    {
        $asOf = (string) ($ctx['as_of'] ?? date('Y-m-d'));
        return ['report' => \FleetForge\Accounting\ReportingService::balanceSheet($asOf)];
    }

    private static function gatherCashFlowContext(array $ctx, ?int $userId): ?array
    {
        $from = (string) ($ctx['from'] ?? date('Y-01-01'));
        $to   = (string) ($ctx['to']   ?? date('Y-m-d'));
        return ['report' => \FleetForge\Accounting\ReportingService::cashFlow($from, $to)];
    }

    private static function gatherBudgetVarianceContext(int $budgetId, array $ctx, ?int $userId): ?array
    {
        if ($budgetId <= 0) return null;
        $from = (string) ($ctx['from'] ?? date('Y-01-01'));
        $to   = (string) ($ctx['to']   ?? date('Y-m-d'));
        return ['report' => \FleetForge\Accounting\BudgetService::variance($budgetId, $from, $to)];
    }

    private static function gatherCustomerContext(int $customerId, ?int $userId): array
    {
        $details  = Tools\FleetForgeTools::run('get_customer_details', ['customer_id' => $customerId], $userId);
        $leases   = Tools\FleetForgeTools::run('get_customer_leases', ['customer_id' => $customerId], $userId);
        $invoices = Tools\FleetForgeTools::run('get_customer_invoices', ['customer_id' => $customerId], $userId);

        return [
            'customer' => $details,
            'leases'   => is_array($leases) ? array_slice($leases, 0, 10) : [],
            'invoices' => is_array($invoices) ? array_slice($invoices, 0, 10) : [],
        ];
    }

    private static function gatherLeaseContext(int $leaseId, ?int $userId): array
    {
        $details = Tools\FleetForgeTools::run('get_lease_details', ['lease_id' => $leaseId], $userId);
        return ['lease' => $details];
    }

    private static function gatherUnitContext(int $unitId): array
    {
        $unit        = Tools\FleetForgeTools::run('get_equipment_unit', ['unit_id' => $unitId]);
        $maintenance = Tools\FleetForgeTools::run('get_maintenance_summary', ['unit_id' => $unitId]);

        return [
            'unit'        => $unit,
            'maintenance' => $maintenance,
        ];
    }

    private static function gatherInvoiceContext(int $invoiceId): ?array
    {
        $inv = db_row(
            "SELECT i.*, c.company_name, c.contact_name, c.email AS customer_email,
                    c.outstanding_balance AS customer_outstanding,
                    l.contract_number, l.status AS lease_status,
                    eu.unit_number AS unit_number_live, eu.year AS unit_year,
                    t.name AS unit_type_name
             FROM invoices i
             LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
             LEFT JOIN leases   l ON l.id = i.lease_id    AND l.deleted_at IS NULL
             LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
             LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
             WHERE i.id = ? AND i.deleted_at IS NULL",
            [$invoiceId]
        );
        if (!$inv) return null;

        // Line items
        $lines = db_select(
            "SELECT description, quantity, unit_price, amount, item_type
             FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order, id",
            [$invoiceId]
        );

        // Payments applied to this invoice (via allocation table)
        $payments = db_select(
            "SELECT p.payment_number, pa.amount AS allocated_amount, p.amount AS payment_total,
                    p.payment_date, p.payment_method, p.status, p.notes
             FROM payment_allocations pa
             JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
             WHERE pa.invoice_id = ?
             ORDER BY p.payment_date DESC LIMIT 10",
            [$invoiceId]
        );

        // Other recent invoices for this customer (for payment pattern context)
        $recentInvoices = db_select(
            "SELECT invoice_number, status, total_amount, balance_due, invoice_date, due_date
             FROM invoices
             WHERE customer_id = ? AND id != ? AND deleted_at IS NULL
             ORDER BY invoice_date DESC LIMIT 8",
            [$inv['customer_id'], $invoiceId]
        );

        return [
            'invoice'         => $inv,
            'line_items'      => $lines,
            'payments'        => $payments,
            'recent_invoices' => $recentInvoices,
        ];
    }

    // ── Reservation context (S-AI-SUMMARY-PANELS) ──────────────
    private static function gatherReservationContext(int $reservationId): ?array
    {
        $res = db_row(
            "SELECT r.*, c.company_name AS customer_company, c.status AS customer_status,
                    c.outstanding_balance AS customer_outstanding
             FROM reservations r
             LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
             WHERE r.id = ? AND r.deleted_at IS NULL",
            [$reservationId]
        );
        if (!$res) return null;
        unset($res['internal_notes']); // keep the summary customer-safe

        $units = db_select(
            "SELECT ru.unit_number_snapshot, eu.unit_number AS unit_number_live,
                    eu.status AS unit_status, t.name AS template_name, t.category
             FROM reservation_units ru
             LEFT JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
             LEFT JOIN equipment_templates t ON t.id = eu.template_id AND t.deleted_at IS NULL
             WHERE ru.reservation_id = ?",
            [$reservationId]
        );
        return ['reservation' => $res, 'units' => $units];
    }

    // ── Vendor context (S-AI-SUMMARY-PANELS) ───────────────────
    private static function gatherVendorContext(int $vendorId, ?int $userId): ?array
    {
        $vendor = db_row(
            "SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL",
            [$vendorId]
        );
        if (!$vendor) return null;

        $workOrders = db_select(
            "SELECT wo.work_order_number, wo.status, wo.work_type, wo.title,
                    wo.total_cost, wo.requested_date, wo.completed_date,
                    eu.unit_number
             FROM maintenance_work_orders wo
             LEFT JOIN equipment_units eu ON eu.id = wo.equipment_unit_id AND eu.deleted_at IS NULL
             WHERE wo.vendor_id = ? AND wo.deleted_at IS NULL
             ORDER BY wo.requested_date DESC LIMIT 20",
            [$vendorId]
        );
        $woStats = db_row(
            "SELECT COUNT(*) AS total_work_orders,
                    SUM(status = 'completed') AS completed,
                    SUM(status IN ('open','in_progress','waiting_parts')) AS open
             FROM maintenance_work_orders WHERE vendor_id = ? AND deleted_at IS NULL",
            [$vendorId]
        );

        // Strip money for users without financial visibility (serve-time gate).
        if (!\can_view_financials()) {
            unset($vendor['total_spent'], $vendor['hourly_rate']);
            foreach ($workOrders as &$w) { unset($w['total_cost']); }
            unset($w);
        }
        return ['vendor' => $vendor, 'recent_work_orders' => $workOrders, 'work_order_stats' => $woStats];
    }

    // ── Payment context (S-AI-SUMMARY-PANELS) ──────────────────
    private static function gatherPaymentContext(int $paymentId, ?int $userId): ?array
    {
        $pay = db_row(
            "SELECT p.*, c.company_name AS customer_company, c.outstanding_balance AS customer_outstanding
             FROM payments p
             LEFT JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
             WHERE p.id = ?",
            [$paymentId]
        );
        if (!$pay) return null;
        unset($pay['internal_notes']);

        $allocations = db_select(
            "SELECT pa.amount AS allocated_amount, i.invoice_number, i.status AS invoice_status,
                    i.total_amount, i.balance_due
             FROM payment_allocations pa
             JOIN invoices i ON i.id = pa.invoice_id
             WHERE pa.payment_id = ? ORDER BY i.invoice_number",
            [$paymentId]
        );

        // WHY: serve-time money gate — parity with gatherVendorContext(). Without
        // it, an ai:view-only user (no payments:view) gets raw payment amount,
        // method, customer AR, and per-invoice balances embedded in the AI context.
        if (!\can_view_financials()) {
            foreach (['amount', 'amount_in_cad', 'exchange_rate_to_cad',
                      'currency_markup_pct', 'overpayment_amount', 'refund_amount',
                      'payment_method', 'check_number', 'card_last_four',
                      'customer_outstanding'] as $k) {
                unset($pay[$k]);
            }
            foreach ($allocations as &$a) {
                unset($a['allocated_amount'], $a['total_amount'], $a['balance_due']);
            }
            unset($a);
        }

        return ['payment' => $pay, 'allocations' => $allocations];
    }

    private static function gatherFleetContext(): array
    {
        $fleet      = Tools\FleetForgeTools::run('get_fleet_summary', []);
        $kpis       = Tools\FleetForgeTools::run('get_dashboard_kpis', []);
        $compliance = Tools\FleetForgeTools::run('get_expiring_documents', ['days_ahead' => 30]);

        return [
            'fleet'      => $fleet,
            'kpis'       => $kpis,
            'compliance' => is_array($compliance) ? array_slice($compliance, 0, 15) : [],
        ];
    }

    private static function gatherPaymentRiskContext(int $customerId, ?int $userId): array
    {
        $details  = Tools\FleetForgeTools::run('get_customer_details', ['customer_id' => $customerId], $userId);
        $invoices = Tools\FleetForgeTools::run('get_customer_invoices', ['customer_id' => $customerId], $userId);

        return [
            'customer' => $details,
            'invoices' => is_array($invoices) ? $invoices : [],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // gatherAccountingContext()
    //
    // Pulls the data needed for a fleet-wide accounting overview:
    //   - Trial balance (current period)
    //   - AR aging bucket totals
    //   - AP aging / overdue bills
    //   - Recent posted journal entries
    //   - Bank account balances
    //   - Payment summary (recent collections)
    //   - Overdue invoice list
    //
    // Cached by SummaryEngine, so all of these tool calls only run
    // once per TTL window (24h by default).
    // ────────────────────────────────────────────────────────────
    private static function gatherAccountingContext(?int $userId): array
    {
        $trial     = Tools\FleetForgeTools::run('get_trial_balance', [], $userId);
        $arAging   = Tools\FleetForgeTools::run('get_ar_aging', [], $userId);
        $overdue   = Tools\FleetForgeTools::run('get_overdue_invoices', [], $userId);
        $paySum    = Tools\FleetForgeTools::run('get_payment_summary', [], $userId);
        $banks     = Tools\FleetForgeTools::run('get_bank_accounts', [], $userId);
        $recentJes = Tools\FleetForgeTools::run('get_journal_entries', ['limit' => 15], $userId);

        return [
            'trial_balance'         => $trial,
            'ar_aging'              => $arAging,
            'overdue_invoices'      => is_array($overdue) ? array_slice($overdue, 0, 10) : $overdue,
            'payment_summary'       => $paySum,
            'bank_accounts'         => $banks,
            'recent_journal_entries'=> is_array($recentJes) ? $recentJes : [],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // buildPrompt()
    //
    // Constructs the user prompt for Claude based on summary
    // type and gathered context data.
    // ────────────────────────────────────────────────────────────
    public static function buildPrompt(string $summaryType, array $context): string
    {
        $dataJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return match ($summaryType) {
            'customer_insights' => <<<PROMPT
You are reviewing a customer account for a trailer leasing company. Give a thorough account intelligence briefing that a sales manager or account executive would find genuinely actionable.

Structure your response with these sections:

**Account Overview**
Who this customer is, their current relationship status, credit standing, and how long they've been a customer.

**Lease Portfolio**
Active leases: unit types, rates, billing cycles, any unusual terms. Are rates competitive or stale? Any leases expiring soon that should be renewed proactively?

**Financial Health**
Outstanding balance, payment patterns (on time? consistently late?), largest overdue invoices. Be specific about dollar amounts and days overdue. Flag any collections risk.

**Risk & Opportunity Assessment**
What risks exist (payment risk, fleet reduction, under-billing)? What opportunities are there (expand the fleet, rate renegotiation, cross-sell)?

**Recommended Actions**
Concrete next steps, prioritized. E.g. "Call re: INV-xxx ($2,400 overdue 45 days)", "Propose renewal for lease ending June 2026", etc.

Customer data:
{$dataJson}
PROMPT,

            'lease_summary' => <<<PROMPT
You are reviewing a specific trailer lease. Provide a thorough lease intelligence summary that an operations or billing manager would find useful.

Structure your response with these sections:

**Lease Overview**
Unit, customer, contract number, status, lease period (start → end or open-ended), billing cycle. Flag if the lease is overdue for renewal or extension.

**Rate Analysis**
Daily/weekly/monthly rates, mileage rates, GPS add-on, hourly rate. Are rates in line with what you'd expect? Any free/discounted rates that look unusual?

**Financial Position**
Total invoiced vs paid, outstanding balance, last billing date, next billing date. Any overdue invoices? Estimate remaining contract value if applicable.

**Compliance & Operational Notes**
GPS tracking status, mileage tracking (from/to), any close notes or inspection issues.

**Action Items**
What needs to happen next? Be specific (e.g. "Invoice overdue $X since [date]", "Lease ending in 30 days — initiate renewal", "Mileage allowance nearly exhausted").

Lease data:
{$dataJson}
PROMPT,

            'unit_analysis' => <<<PROMPT
You are reviewing a specific piece of equipment in a trailer fleet. Give a thorough unit intelligence report that a fleet manager would find genuinely useful.

Structure your response with these sections:

**Unit Overview**
Unit number, type/category, year, current status (available/on lease/maintenance), yard location. Current lease customer if on lease.

**Compliance Status**
CVI, Registration, and MVI expiry dates. For each: how many days until expiry? Flag anything expiring within 60 days as urgent. Recommend specific action (e.g. "Schedule CVI inspection — expires in 22 days").

**Maintenance & Condition**
Recent maintenance work orders, costs, recurring issues. Is maintenance spend high for the unit age? Any open work orders?

**Revenue Performance**
How long has this unit been in the fleet? What is it generating? Utilization rate (time on lease vs sitting). If it's been sitting available for a long time, flag it.

**Recommended Actions**
Prioritized list of what needs to happen for this unit. Be specific and actionable.

Unit data:
{$dataJson}
PROMPT,

            'reservation_summary' => <<<PROMPT
You are reviewing a trailer/equipment reservation for a leasing company. Give a concise, actionable briefing an operations dispatcher would use.

Structure your response with these sections:

**Reservation Overview**
Who it's for (company/contact), status, pickup date/time, yard location, quantity, purpose, priority. Flag if the pickup date is in the past or imminent.

**Units Reserved**
The specific units (or unit types) held, their live status, and whether any reserved unit is not currently in a 'reserved' state (a data/availability concern).

**Customer Context**
The customer's standing and any outstanding balance that's relevant to fulfilling this reservation.

**Risks & Action Items**
Conflicts, stale/expired reservations, missing info, or anything blocking pickup. Concrete next steps (confirm, cancel, follow up).

Reservation data:
{$dataJson}
PROMPT,

            'vendor_summary' => <<<PROMPT
You are reviewing a maintenance/parts vendor for a trailer leasing company. Give a thorough vendor briefing a maintenance manager would find useful.

Structure your response with these sections:

**Vendor Overview**
Name, type, contact info, preferred/related-party flags, rating, specializations.

**Work History**
Volume of work orders (total / completed / open), recent jobs and what they cost, which units they've serviced. Any recurring work types?

**Spend & Value**
Total spend with this vendor, hourly rate, whether spend looks high/low for the work volume. (Omit dollar figures if not available to you.)

**Risks & Recommendations**
Open work orders needing follow-up, quality concerns (low rating), over-reliance on one vendor, or opportunities to consolidate. Concrete next steps.

Vendor data:
{$dataJson}
PROMPT,

            'payment_summary' => <<<PROMPT
You are reviewing a recorded customer payment for a leasing company. Give a clear, accurate payment briefing a billing/AR manager would use.

Structure your response with these sections:

**Payment Overview**
Payment number, customer, amount + currency, method, payment date, and current status (completed, returned, refunded, etc.). Flag any failed/returned/refunded state.

**Allocation**
Which invoices this payment was applied to and how much went to each. Is the payment fully allocated, partially allocated, or is there an overpayment? Note any overpayment handling.

**Customer Context**
The customer's outstanding balance and how this payment affects it.

**Notes & Action Items**
Anything unusual (returned payment, unresolved overpayment, refund pending) and the concrete next step. If everything is clean, say so plainly.

Payment data:
{$dataJson}
PROMPT,

            'fleet_health' => <<<PROMPT
You are generating a fleet-wide health report for management at a Canadian trailer leasing company. Give a thorough executive-level fleet briefing.

Structure your response with these sections:

**Fleet Utilization**
Total units, how many on lease, available, in maintenance, reserved. Calculate utilization rate. Is it healthy (>70%? >80%)? What's sitting idle that shouldn't be?

**Compliance Alert Summary**
Units with documents expiring within 30 days. Name the specific units and what needs renewing. Flag anything already expired.

**Revenue Snapshot**
Active lease revenue, open invoices vs collected, any overdue AR concerns.

**Maintenance & Downtime**
Units in maintenance — how many, how long, any patterns suggesting systemic issues?

**Top Opportunities & Risks**
What are the 2-3 biggest opportunities to improve fleet performance right now? What are the 2-3 biggest operational risks?

**Recommended Actions This Week**
Specific, prioritized action items for the fleet manager.

Fleet data:
{$dataJson}
PROMPT,

            'payment_risk' => <<<PROMPT
You are conducting a payment risk assessment for a customer at a trailer leasing company. Give a thorough risk analysis that a credit manager would use to decide on collections or credit actions.

Structure your response with these sections:

**Payment Pattern Analysis**
How does this customer pay? On time, slightly late, or chronically delinquent? Be specific about patterns in the invoice data.

**Current Exposure**
Total outstanding, how much is current vs 30/60/90+ days overdue. Name specific invoices that are the biggest concerns.

**Risk Rating**
Low / Medium / High / Critical — with specific justification based on the actual data.

**Recommended Actions**
Concrete collections or credit actions: send reminder, escalate to phone call, hold new leases, engage collections agency, etc. Be specific about which invoices and amounts to reference.

Customer payment data:
{$dataJson}
PROMPT,

            'accounting_overview' => <<<PROMPT
You are generating a financial health briefing for the CFO/controller of a Canadian trailer leasing company. Give a thorough accounting snapshot that surfaces what actually matters.

Structure your response with these sections:

**Cash Position**
Bank account balances, total cash. Any accounts running low? Cover runway if there are concerning burn signals.

**Accounts Receivable**
Total AR outstanding, aging breakdown (current / 30 / 60 / 90+ days overdue). Name specific customers with large or severely overdue balances. Concentration risk?

**Accounts Payable**
Overdue vendor bills — amounts, vendors, days overdue. Cash flow impact.

**Revenue vs Expenses**
From the trial balance: revenue drivers, major expense categories, net position YTD. What's the margin story?

**Notable Journal Entry Activity**
Any large manual adjustments, unusual entries, or items that warrant a second look?

**Top Actions This Week**
Specific, prioritized actions for the finance team — include customer names, invoice numbers, and dollar amounts where relevant.

Format monetary values with \$ and CAD/USD. Reference specific account names where they sharpen the analysis.

Accounting data:
{$dataJson}
PROMPT,

            'invoice_analysis' => <<<PROMPT
You are reviewing a specific invoice for a trailer leasing company. Provide a concise, actionable invoice intelligence brief that an AR or billing manager would find useful.

Structure your response with these sections:

## Invoice Summary
Invoice number, customer, billing period, total amount, balance due, current status. How many days overdue (if applicable)?

## Line Item Breakdown
Summarise the key charges — base rental, mileage, add-ons. Any items that look unusual (e.g. very large overage, zero-dollar lines)?

## Payment Status & History
What has been collected? Is there an outstanding balance? How does payment on this invoice compare to the customer's recent invoice history?

## Customer Context
Based on the customer's recent invoice history, characterise their payment behaviour — reliable, occasionally late, or consistently delinquent?

## Recommended Action
One or two specific next steps: send reminder, record payment, escalate, write off, or note if no action needed.

Invoice data:
{$dataJson}
PROMPT,

            'pl_narrative' => <<<PROMPT
You are a Canadian CPA reviewing this Profit & Loss statement. Provide a 3-5 paragraph narrative that a senior controller would write to the CFO.

Cover:
- Top revenue drivers and any notable concentrations
- Direct cost composition and gross margin trends
- Operating expense composition — which categories dominate
- Any "Other Income / Expense" items worth flagging
- Bottom line: net income result and what it means for the period
- 1-2 recommendations or watch items

Format monetary values with \$ and the CAD currency. Reference specific account names + codes where it sharpens the point. Do NOT just restate every account — synthesise.

P&L data:
{$dataJson}
PROMPT,

            'bs_narrative' => <<<PROMPT
You are a Canadian CPA explaining this Balance Sheet to a small-business owner. Provide a 3-5 paragraph narrative.

Cover:
- Liquidity (current assets vs current liabilities) — flag if working capital is thin or strained
- Long-term asset base — what is the company tied up in
- Liability composition — short-term operating debt vs long-term financing
- Equity position and the YTD net income contribution
- If `is_balanced` is false: explain that the drift indicates a reconciliation gap and is being tracked as a separate item (do NOT speculate on the cause beyond "AR or AP reconciliation")
- 1-2 recommendations or watch items

Format monetary values with \$ and CAD. Be plain-language but accurate.

Balance Sheet data:
{$dataJson}
PROMPT,

            'cashflow_narrative' => <<<PROMPT
You are a Canadian CPA explaining this Cash Flow Statement (ASPE 1540 indirect method) to a CEO.

Provide a 3-5 paragraph narrative covering:
- Operating cash — start with net income, then explain the non-cash adjustments (depreciation, etc.) and the working-capital movement (which assets/liabilities consumed or freed cash)
- Investing activities — capital expenditure and any disposal proceeds
- Financing activities — debt drawdown/repayment, dividends or owner draws
- Bottom line: net change in cash and whether the closing balance reconciles to the GL
- If `is_tied_out` is false: note the small tie-out difference as a known reconciliation item

Format monetary values with \$ and CAD. Be plain-language but accurate.

Cash Flow data:
{$dataJson}
PROMPT,

            'budget_variance' => <<<PROMPT
You are a Canadian CPA reviewing this Budget vs Actual variance report. Provide a 3-5 paragraph narrative for the management team.

Cover:
- Revenue performance — favorable / unfavorable variance and what's driving it
- Major expense variances — which categories are over- or under-budget and by how much
- Accounts that cross the variance warning threshold (`crosses_threshold = true`) — call them out specifically
- Overall budgeted vs actual net (the totals.budgeted_net vs totals.actual_net)
- 1-2 corrective actions if any category is materially adverse

Format monetary values with \$ and CAD. Reference account codes + names. A favorable variance means actual is better than budget for that account's normal-balance side.

Budget variance data:
{$dataJson}
PROMPT,

            default => "Analyze the following data and provide a brief summary:\n\n{$dataJson}",
        };
    }

    // ────────────────────────────────────────────────────────────
    // getSystemPrompt()
    // ────────────────────────────────────────────────────────────
    public static function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are FleetForge AI — the built-in fleet intelligence assistant for Mainland Truck & Trailer Sales & Leasing, a Canadian commercial trailer and equipment leasing company.

You have deep expertise in:
- Commercial trailer and equipment leasing (dry vans, reefers, flatbeds, chassis, combos, etc.)
- Fleet operations, utilization, and maintenance cost analysis
- Canadian trucking industry norms and compliance requirements (CVI, MVI, annual registrations)
- Invoice billing cycles, overdue AR, and customer payment patterns
- Revenue optimization and risk identification for leasing portfolios

When responding:
- Be thorough and genuinely insightful — do not just restate the numbers, explain what they mean and why they matter
- Use ## for main section headers and ### for sub-headers
- Use bullet points (-) for lists
- Use **bold** for emphasis on key numbers, dates, and action items
- Format monetary values with $ and the currency (CAD/USD)
- Use clear date formats (e.g., "June 15, 2026")
- When compliance documents are expiring, calculate urgency and recommend exactly what to do
- When leases have financial issues, be specific about amounts and timelines
- Do NOT hallucinate — only reference data that was provided
- Do NOT pad with generic disclaimers or filler sentences
- Do NOT use emoji characters — use plain text only
- Do NOT use coloured circle indicators (🔴🟡🟢) — describe urgency in words instead
PROMPT;
    }
}
