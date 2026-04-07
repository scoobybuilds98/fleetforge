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
        bool   $forceRefresh = false
    ): ?array {
        // WHY: Check cache first unless explicitly refreshing
        if (!$forceRefresh && (bool) settings_get('ai.cache_summaries', true)) {
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
        $context = self::gatherContext($entityType, $entityId, $summaryType, $userId);
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
            maxTokens:    1024,
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
    // Stores a generated summary in ai_summaries table.
    // Marks previous summaries for the same entity+type as not current.
    // ────────────────────────────────────────────────────────────
    private static function cacheSummary(
        string $entityType,
        int    $entityId,
        string $summaryType,
        string $content,
        int    $tokensUsed,
        string $model,
        ?int   $userId
    ): void {
        try {
            $ttlHours = (int) settings_get('ai.summary_ttl_hours', self::DEFAULT_TTL_HOURS);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlHours} hours"));

            // WHY: Mark old summaries as not current before inserting new one
            db_execute(
                "UPDATE ai_summaries SET is_current = 0
                 WHERE entity_type = ? AND entity_id = ? AND summary_type = ?",
                [$entityType, $entityId, $summaryType]
            );

            db_insert('ai_summaries', [
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'summary_type' => $summaryType,
                'content'      => $content,
                'tokens_used'  => $tokensUsed,
                'model_used'   => $model,
                'generated_at' => date('Y-m-d H:i:s'),
                'expires_at'   => $expiresAt,
                'generated_by' => $userId,
                'is_current'   => 1,
            ]);
        } catch (\Throwable) {
            // Cache write failure is non-fatal
        }
    }

    // ────────────────────────────────────────────────────────────
    // gatherContext()
    //
    // Collects relevant data for the summary by calling the
    // appropriate tool handlers directly.
    // ────────────────────────────────────────────────────────────
    private static function gatherContext(string $entityType, int $entityId, string $summaryType, ?int $userId): ?array
    {
        try {
            return match ($summaryType) {
                'customer_insights'   => self::gatherCustomerContext($entityId, $userId),
                'lease_summary'       => self::gatherLeaseContext($entityId, $userId),
                'unit_analysis'       => self::gatherUnitContext($entityId),
                'fleet_health'        => self::gatherFleetContext(),
                'payment_risk'        => self::gatherPaymentRiskContext($entityId, $userId),
                'accounting_overview' => self::gatherAccountingContext($userId),
                default               => null,
            };
        } catch (\Throwable) {
            return null;
        }
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
    private static function buildPrompt(string $summaryType, array $context): string
    {
        $dataJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return match ($summaryType) {
            'customer_insights' => <<<PROMPT
Analyze this customer data and provide a brief insights summary (3-5 bullet points). Cover:
- Overall account health and status
- Lease activity patterns
- Payment behavior and any overdue concerns
- Risk assessment based on the data
- Recommendations for account management

Customer data:
{$dataJson}
PROMPT,

            'lease_summary' => <<<PROMPT
Provide a concise summary of this lease (3-4 bullet points). Cover:
- Key terms (dates, rates, billing cycle)
- Current status and any notable conditions
- Financial standing (invoiced vs paid)
- Any flags or concerns

Lease data:
{$dataJson}
PROMPT,

            'unit_analysis' => <<<PROMPT
Analyze this equipment unit and provide a brief assessment (3-5 bullet points). Cover:
- Current status and utilization
- Compliance document status (any upcoming expirations)
- Maintenance history and costs
- Revenue performance
- Recommended actions

Unit data:
{$dataJson}
PROMPT,

            'fleet_health' => <<<PROMPT
Provide a fleet health overview (4-6 bullet points). Cover:
- Overall fleet utilization and capacity
- Status distribution and availability
- Upcoming compliance concerns
- Key performance indicators
- Maintenance workload
- Recommendations

Fleet data:
{$dataJson}
PROMPT,

            'payment_risk' => <<<PROMPT
Assess the payment risk for this customer (3-4 bullet points). Cover:
- Payment history patterns
- Current overdue status
- Risk score and credit standing
- Recommended collection actions if needed

Customer payment data:
{$dataJson}
PROMPT,

            'accounting_overview' => <<<PROMPT
Provide a concise accounting health snapshot for the CFO/controller (5-7 bullet points). Cover:
- Cash position across bank accounts (flag any low balances)
- AR aging — total outstanding, current vs overdue buckets, any concentration risk
- AP aging — overdue vendor bills and their impact on cash
- Revenue vs expenses (from the trial balance) and net position
- Unusual or notable items in recent journal entries (large adjustments, manual entries, etc.)
- Top 1-2 actions the finance team should take this week

Format monetary values with \$ and currency (CAD/USD). Include specific customer/vendor names where relevant. Do NOT just repeat the numbers — summarize what they mean.

Accounting data:
{$dataJson}
PROMPT,

            default => "Analyze the following data and provide a brief summary:\n\n{$dataJson}",
        };
    }

    // ────────────────────────────────────────────────────────────
    // getSystemPrompt()
    // ────────────────────────────────────────────────────────────
    private static function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are FleetForge AI, generating concise data summaries for a trailer and equipment leasing company.

Rules:
- Be concise and factual. Use bullet points.
- Format monetary values with $ and two decimal places, include currency (CAD/USD).
- Use clear date formats (e.g., "January 15, 2026").
- Highlight any concerns or anomalies.
- Do not hallucinate — only reference data provided.
- Keep summaries under 200 words.
PROMPT;
    }
}
