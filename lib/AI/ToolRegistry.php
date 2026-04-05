<?php
declare(strict_types=1);

namespace FleetForge\AI;

use FleetForge\AI\Tools\FleetForgeTools;

/**
 * lib/AI/ToolRegistry.php
 *
 * Registry for AI "tools" (function calling). Tools are database query
 * functions that Claude can invoke to answer questions about FleetForge data.
 *
 * Architecture:
 *   - Tool definitions follow the Anthropic tool_use JSON schema format
 *   - Each tool maps to a static method in FleetForgeTools
 *   - Tools are read-only — no writes to the database
 *   - Results are truncated to prevent token explosion (max 50 rows)
 *   - Financial data is stripped if user lacks payments:view permission
 *   - Context parameter allows feature-specific tool filtering
 *
 * Adding a new tool:
 *   1. Add the definition to getToolDefinitions() below
 *   2. Add the execute handler to FleetForgeTools.php
 *   3. Done — it's automatically available to all AI features
 *
 * @depends lib/AI/Tools/FleetForgeTools.php
 * @session S026
 */
class ToolRegistry
{
    /** Maximum rows any tool can return (prevents token explosion) */
    public const MAX_ROWS = 50;

    // ────────────────────────────────────────────────────────────
    // getTools()
    //
    // Returns tool definitions filtered by context.
    // Contexts: 'chat' (all), 'summary' (subset), 'report' (query-focused)
    //
    // @param  string $context  Feature context for filtering
    // @return array            Anthropic-format tool definitions
    // ────────────────────────────────────────────────────────────
    public static function getTools(string $context = 'chat'): array
    {
        // WHY: Filter by _tags BEFORE stripping them; rawDefinitions() still has the tags
        $raw = self::rawDefinitions();

        if ($context !== 'chat') {
            $raw = array_values(array_filter($raw, function (array $tool) use ($context) {
                $tags = $tool['_tags'] ?? ['chat'];
                return in_array($context, $tags, true);
            }));
        }

        // Strip internal _tags before returning to Anthropic API
        return array_map(function (array $tool) {
            unset($tool['_tags']);
            return $tool;
        }, $raw);
    }

    // ────────────────────────────────────────────────────────────
    // execute()
    //
    // Runs a tool by name with the given input parameters.
    // Returns the tool result as a string (JSON-encoded for structured data).
    //
    // @param  string   $toolName  Tool name from Claude's tool_use block
    // @param  array    $input     Input parameters from Claude
    // @param  int|null $userId    Current user (for permission checks)
    // @return string              Result string to send back to Claude
    // ────────────────────────────────────────────────────────────
    public static function execute(string $toolName, array $input, ?int $userId = null): string
    {
        try {
            $result = FleetForgeTools::run($toolName, $input, $userId);
            return is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => true,
                'message' => 'Tool execution failed: ' . $e->getMessage(),
            ]);
        }
    }

    // ────────────────────────────────────────────────────────────
    // getToolDefinitions()
    //
    // Master list of all tool definitions. Each tool follows the
    // Anthropic format plus an internal '_tags' key for filtering.
    // The '_tags' key is stripped before sending to the API.
    //
    // @return array  Tool definition arrays
    // ────────────────────────────────────────────────────────────
    private static function getToolDefinitions(): array
    {
        // WHY: Strip internal tags before returning to Anthropic API
        $tools = self::rawDefinitions();
        return array_map(function (array $tool) {
            unset($tool['_tags']);
            return $tool;
        }, $tools);
    }

    // ────────────────────────────────────────────────────────────
    // rawDefinitions() — tool definitions with internal metadata
    // ────────────────────────────────────────────────────────────
    private static function rawDefinitions(): array
    {
        return [
            // ── Customer Tools ──────────────────────────────────
            [
                'name' => 'search_customers',
                'description' => 'Search customers by name, email, status, or city. Returns up to 50 matches with key details.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query'  => ['type' => 'string', 'description' => 'Search term (name, email, or city)'],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'suspended', ''], 'description' => 'Filter by status (empty = all)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_customer_details',
                'description' => 'Get full profile for a specific customer including contact info, status, risk score, credit limit, and lease count.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],
            [
                'name' => 'get_customer_leases',
                'description' => 'List leases for a customer. Returns lease ID, unit number, dates, status, and rate.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                        'status'      => ['type' => 'string', 'enum' => ['active', 'pending', 'completed', 'cancelled', ''], 'description' => 'Filter by status'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],
            [
                'name' => 'get_customer_invoices',
                'description' => 'List invoices for a customer with status, amounts, due dates, and payment info.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                        'status'      => ['type' => 'string', 'enum' => ['draft', 'sent', 'paid', 'partial', 'overdue', 'void', 'cancelled', ''], 'description' => 'Filter by status'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Fleet / Equipment Tools ─────────────────────────
            [
                'name' => 'get_fleet_summary',
                'description' => 'Get a summary of the entire fleet: unit counts by status (available, on_lease, maintenance, etc.), total units, utilization rate.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],
            [
                'name' => 'get_equipment_unit',
                'description' => 'Get detailed info about a specific equipment unit including template, status, mileage, compliance dates, and GPS tracking.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id' => ['type' => 'integer', 'description' => 'Equipment unit ID'],
                    ],
                    'required' => ['unit_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],
            [
                'name' => 'search_equipment',
                'description' => 'Search equipment units by unit number, template name, status, or category. Returns up to 50 matches.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string', 'description' => 'Search term (unit number or template name)'],
                        'status'   => ['type' => 'string', 'enum' => ['available', 'on_lease', 'reserved', 'maintenance', 'inactive', 'decommissioned', ''], 'description' => 'Filter by status'],
                        'category' => ['type' => 'string', 'description' => 'Template category filter (e.g. dry_van, reefer, flatbed)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Lease Tools ─────────────────────────────────────
            [
                'name' => 'get_active_leases',
                'description' => 'List all currently active leases with customer name, unit number, dates, rate, and status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Optional: filter to specific customer'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_lease_details',
                'description' => 'Get full details about a specific lease including customer, unit, billing terms, mileage, amendments, and status history.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'lease_id' => ['type' => 'integer', 'description' => 'Lease ID'],
                    ],
                    'required' => ['lease_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ── Financial Tools ─────────────────────────────────
            [
                'name' => 'get_revenue_by_period',
                'description' => 'Get total revenue (invoiced and collected) grouped by month for a date range. Useful for trends and forecasting.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                        'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    ],
                    'required' => ['date_from', 'date_to'],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_revenue_by_customer',
                'description' => 'Get revenue per customer for a date range. Shows invoice count, total billed, total paid, and outstanding balance per customer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                        'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    ],
                    'required' => ['date_from', 'date_to'],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_overdue_invoices',
                'description' => 'Get all currently overdue invoices with customer name, amount, days overdue, and due date.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report', 'summary'],
            ],
            [
                'name' => 'get_ar_aging',
                'description' => 'Get accounts receivable aging report: outstanding balances grouped by aging buckets (current, 1-30, 31-60, 61-90, 90+ days).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_payment_summary',
                'description' => 'Get payment totals and collection rate for a date range. Shows total collected, payment count, methods breakdown, and collection percentage.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                        'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                    ],
                    'required' => ['date_from', 'date_to'],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Compliance Tools ────────────────────────────────
            [
                'name' => 'get_expiring_documents',
                'description' => 'Get equipment compliance documents (CVI, registration, MVI, insurance) expiring within the next N days.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'days_ahead' => ['type' => 'integer', 'description' => 'Number of days to look ahead (default 30)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Maintenance Tools ───────────────────────────────
            [
                'name' => 'get_maintenance_summary',
                'description' => 'Get maintenance work order stats: open/in-progress/completed counts, total costs, and recent work orders.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id' => ['type' => 'integer', 'description' => 'Optional: filter to specific unit'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Dashboard / General Tools ───────────────────────
            [
                'name' => 'get_dashboard_kpis',
                'description' => 'Get key performance indicators: total revenue this month, active leases, fleet utilization, overdue invoices, upcoming compliance alerts.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary'],
            ],
        ];
    }
}
