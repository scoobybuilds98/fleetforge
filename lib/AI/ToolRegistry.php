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
    /** Maximum rows any list tool can return. Raised 50→500 (S-AI-ROWCAP):
     *  the old 50 cap forced the AI to paginate just to count a ~190-unit fleet.
     *  500 covers fleet/customer-scale lists in one call; the backstop remains
     *  to protect the token budget on pathological tables (e.g. mileage logs).
     *  For pure counts, prefer the aggregate tools (get_fleet_summary returns
     *  by_status + by_category) so no rows are fetched at all. */
    public const MAX_ROWS = 500;

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
    public static function execute(string $toolName, array $input, ?int $userId = null, ?int $sessionId = null): string
    {
        try {
            $result = FleetForgeTools::run($toolName, $input, $userId, $sessionId);
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
                'description' => 'Search customers by name, email, status, or city. Returns up to 500 matches with key details.',
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

            // ── Credit Application + Service Request Tools (S-AI-READ-GAPS) ──
            [
                'name' => 'get_credit_applications',
                'description' => 'List customer credit applications. Filter by status (sent/opened/submitted/reviewed), outcome (approved/declined/needs_info), customer_id, or a name/company search. Returns status, review outcome, key dates, and approved credit limit. Use for "which credit apps are pending review", "did Acme submit their credit application", etc.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status'      => ['type' => 'string', 'enum' => ['sent', 'opened', 'submitted', 'reviewed', ''], 'description' => 'Filter by application status'],
                        'outcome'     => ['type' => 'string', 'enum' => ['approved', 'declined', 'needs_info', ''], 'description' => 'Filter by review outcome'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Filter to one customer'],
                        'query'       => ['type' => 'string', 'description' => 'Search company or signer name'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_credit_application_details',
                'description' => 'Get one credit application: status, review outcome, dates, signer name, approved credit limit, review notes, and whether a signed PDF exists. Does not expose the raw form submission, signature, or token (those are restricted).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'application_id' => ['type' => 'integer', 'description' => 'Credit application ID'],
                    ],
                    'required' => ['application_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],
            [
                'name' => 'get_service_requests',
                'description' => 'List customer-portal service requests (lease extensions, early returns, damage reports, billing inquiries, document requests, etc.). Filter by status (open/in_review/resolved/closed), request_type, or customer_id. Use for "what service requests are open", "any unresolved damage reports", etc.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status'       => ['type' => 'string', 'enum' => ['open', 'in_review', 'resolved', 'closed', ''], 'description' => 'Filter by status'],
                        'request_type' => ['type' => 'string', 'enum' => ['lease_extension', 'early_return', 'damage_report', 'billing_inquiry', 'document_request', 'new_lease_inquiry', 'general', ''], 'description' => 'Filter by request type'],
                        'customer_id'  => ['type' => 'integer', 'description' => 'Filter to one customer'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_service_request_details',
                'description' => 'Get one service request including the full message, current status, assigned operator, linked lease/unit, and the recent reply thread.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'request_id' => ['type' => 'integer', 'description' => 'Service request ID'],
                    ],
                    'required' => ['request_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],
            [
                'name' => 'get_documents',
                'description' => "List uploaded document METADATA (titles, types, file names, sizes, expiry dates, who uploaded, which record they're attached to). Does NOT return file contents or storage paths. Filter by entity_type (customer/equipment_unit/lease/inspection/damage_claim/contract/service_request), entity_id (the attached record's id), document_type (e.g. cvi, registration, insurance, contract, tax_exemption), a title/filename search, or expiring_within_days (documents with their own expiration_date coming due — distinct from equipment compliance dates, which are get_expiring_documents). Use for \"what documents are on file for unit X / customer Y\", \"which uploaded documents expire soon\".",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type'          => ['type' => 'string', 'enum' => ['customer', 'equipment_unit', 'lease', 'inspection', 'damage_claim', 'contract', 'service_request', ''], 'description' => 'Type of record the document is attached to'],
                        'entity_id'            => ['type' => 'integer', 'description' => 'ID of the attached record (use with entity_type)'],
                        'document_type'        => ['type' => 'string', 'description' => 'Filter by document type (cvi, registration, insurance, contract, tax_exemption, etc.)'],
                        'query'                => ['type' => 'string', 'description' => 'Search the document title or file name'],
                        'expiring_within_days' => ['type' => 'integer', 'description' => 'Only documents whose own expiration_date falls within this many days'],
                        'current_only'         => ['type' => 'boolean', 'description' => 'Only the current version of each document'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Fleet / Equipment Tools ─────────────────────────
            [
                'name' => 'get_fleet_summary',
                'description' => 'Get a summary of the entire fleet: unit counts BY STATUS (available, on_lease, maintenance, etc.) AND BY CATEGORY (dry_van, chassis, reefer, flatbed, etc.), total units, and utilization rate. Use this for any "how many units / how many of each category/status" question — it returns exact counts in one call (no row listing, no cap).',
                'input_schema' => [
                    'type' => 'object',
                    // WHY: Empty PHP array [] json_encodes to [] (array); Anthropic needs {} (object) for properties. Cast to stdClass.
                    'properties' => (object) [],
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
                'description' => 'Search equipment units by unit number, template name, status, or category. Returns up to 500 matches. NOTE: to COUNT units (e.g. "how many of each category" or "by status"), call get_fleet_summary instead — it returns by_status + by_category counts in one shot without listing rows.',
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
            [
                'name' => 'get_lease_close_readiness',
                'description' => "Assess whether a lease can be closed and what inputs the close would require. READ-ONLY — does not close anything. Returns can_close + blockers, whether a precharge refund is owed (and how much), whether it's an advance-billed lease needing reconciliation, and the list of decisions the operator must make. Use when asked \"can I close lease X?\" or \"what's needed to close this lease?\". Executing the close itself is still done via the lease Close form, not by you.",
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
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report', 'summary'],
            ],
            [
                'name' => 'get_ar_aging',
                'description' => 'Get accounts receivable aging report: outstanding balances grouped by aging buckets (current, 1-30, 31-60, 61-90, 90+ days).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
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
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ════════════════════════════════════════════════════
            // S028 — Full-coverage tool expansion
            // ════════════════════════════════════════════════════

            // ── Rate / Pricing Tools ────────────────────────────
            [
                'name' => 'get_rate_cards',
                'description' => 'List all rate cards (pricing templates) with effective dates and item counts. Use this when the user asks about rates, pricing, or rate cards in general.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_rate_card_items',
                'description' => 'Get all rates (daily/weekly/monthly/mileage) for a specific rate card by equipment type. If rate_card_id is omitted, returns the default rate card.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'rate_card_id' => ['type' => 'integer', 'description' => 'Rate card ID (omit for default card)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_customer_rates',
                'description' => 'Get custom negotiated rates for a specific customer (overrides standard rate card pricing).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Reservation Tools ───────────────────────────────
            [
                'name' => 'get_reservations',
                'description' => 'List equipment pickup reservations with optional status filter (pending/confirmed/cancelled/completed).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'cancelled', 'completed', ''], 'description' => 'Filter by reservation status'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_reservation_details',
                'description' => 'Get full details of a specific reservation including reserved equipment units.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_id' => ['type' => 'integer', 'description' => 'Reservation ID'],
                    ],
                    'required' => ['reservation_id'],
                ],
                '_tags' => ['chat'],
            ],

            // ── Yard Tools ──────────────────────────────────────
            [
                'name' => 'get_yards',
                'description' => 'List all yards (equipment storage locations) with capacity and current unit count.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_yard_inventory',
                'description' => 'List all equipment units currently parked at a specific yard.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'yard_id' => ['type' => 'integer', 'description' => 'Yard ID'],
                    ],
                    'required' => ['yard_id'],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Vendor Tools ────────────────────────────────────
            [
                'name' => 'search_vendors',
                'description' => 'Search vendors (maintenance/repair/parts suppliers) by name, contact, or vendor type.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query'       => ['type' => 'string', 'description' => 'Search term (name, contact, or email)'],
                        'vendor_type' => ['type' => 'string', 'enum' => ['maintenance', 'repair', 'parts', 'inspection', 'towing', 'other', ''], 'description' => 'Vendor type filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_vendor_details',
                'description' => 'Get full vendor profile including contact info, hourly rate, rating, and total spent.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'vendor_id' => ['type' => 'integer', 'description' => 'Vendor ID'],
                    ],
                    'required' => ['vendor_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ── Inspection Tools ────────────────────────────────
            [
                'name' => 'get_inspections',
                'description' => 'List equipment inspections with optional unit/lease/type filter (pre_lease/post_lease/periodic/damage/compliance).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id'         => ['type' => 'integer', 'description' => 'Optional: filter to specific equipment unit'],
                        'lease_id'        => ['type' => 'integer', 'description' => 'Optional: filter to specific lease'],
                        'inspection_type' => ['type' => 'string', 'enum' => ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance', ''], 'description' => 'Inspection type filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_inspection_details',
                'description' => 'Get full inspection record with unit and lease context.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'inspection_id' => ['type' => 'integer', 'description' => 'Inspection ID'],
                    ],
                    'required' => ['inspection_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ── Damage Claim Tools ──────────────────────────────
            [
                'name' => 'get_damage_claims',
                'description' => 'List equipment damage claims with optional status/severity filter. Used for tracking accident-related repairs and customer chargebacks.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status'   => ['type' => 'string', 'enum' => ['reported', 'assessed', 'repair_ordered', 'invoiced', 'resolved', 'written_off', ''], 'description' => 'Claim status filter'],
                        'severity' => ['type' => 'string', 'enum' => ['minor', 'moderate', 'major', 'total_loss', ''], 'description' => 'Damage severity filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_damage_claim_details',
                'description' => 'Get full damage claim record with related unit, lease, and resolution notes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'claim_id' => ['type' => 'integer', 'description' => 'Damage claim ID'],
                    ],
                    'required' => ['claim_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ── Mileage Tools ───────────────────────────────────
            [
                'name' => 'get_mileage_logs',
                'description' => 'Get recent mileage readings for an equipment unit or lease.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id'  => ['type' => 'integer', 'description' => 'Optional: filter to specific equipment unit'],
                        'lease_id' => ['type' => 'integer', 'description' => 'Optional: filter to specific lease'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'summary', 'report'],
            ],

            // ── Credit Note Tools ───────────────────────────────
            [
                'name' => 'get_credit_notes',
                'description' => 'List customer credit notes with optional customer/status filter. Credit notes track refunds, mileage overpayments, goodwill, and damage adjustments.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Optional: filter to specific customer'],
                        'status'      => ['type' => 'string', 'enum' => ['active', 'partially_used', 'fully_used', 'expired', 'void', ''], 'description' => 'Credit note status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── General Ledger Tools ────────────────────────────
            [
                'name' => 'get_chart_of_accounts',
                'description' => 'List the company chart of accounts (GL accounts) with optional account_type filter (asset/liability/equity/revenue/operating_expense/etc).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'account_type' => ['type' => 'string', 'enum' => ['asset', 'liability', 'equity', 'revenue', 'cost_of_revenue', 'operating_expense', 'other_income', 'other_expense', ''], 'description' => 'Filter by account type'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_journal_entries',
                'description' => 'List general ledger journal entries within a date range, optionally filtered by status (draft/posted/reversed).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (defaults to first of current month)'],
                        'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (defaults to today)'],
                        'status'    => ['type' => 'string', 'enum' => ['draft', 'posted', 'reversed', ''], 'description' => 'Status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_trial_balance',
                'description' => 'Get the trial balance — net debit/credit balance for every active GL account as of a specific date (only posted entries).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'as_of_date' => ['type' => 'string', 'description' => 'As-of date YYYY-MM-DD (defaults to today)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_account_balance',
                'description' => 'Get the current net balance (debit minus credit) for a specific GL account by id or code.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'account_id'   => ['type' => 'integer', 'description' => 'GL account ID'],
                        'account_code' => ['type' => 'string', 'description' => 'GL account code (alternative to account_id)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Accounts Payable Tools ──────────────────────────
            [
                'name' => 'get_vendor_bills',
                'description' => 'List vendor bills (AP invoices) with optional vendor or status filter.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'vendor_id' => ['type' => 'integer', 'description' => 'Optional: filter to a specific vendor'],
                        'status'    => ['type' => 'string', 'enum' => ['draft', 'approved', 'scheduled', 'partially_paid', 'paid', 'void', ''], 'description' => 'Bill status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_ap_aging',
                'description' => 'Get accounts payable aging report — outstanding vendor bills grouped by aging buckets (current, 1-30, 31-60, 61-90, 90+ days).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Banking Tools ───────────────────────────────────
            [
                'name' => 'get_bank_accounts',
                'description' => 'List all bank accounts (checking/savings/credit cards/lines of credit) with current movement totals.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_bank_transactions',
                'description' => 'Recent bank transactions, optionally filtered by bank account and date range.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'bank_account_id' => ['type' => 'integer', 'description' => 'Optional: filter to a specific bank account'],
                        'date_from'       => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (defaults to first of current month)'],
                        'date_to'         => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (defaults to today)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Fixed Asset Tools ───────────────────────────────
            [
                'name' => 'get_fixed_assets',
                'description' => 'List company fixed assets (fleet equipment, vehicles, office equipment, etc.) with NBV, optionally filtered by class or status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'asset_class' => ['type' => 'string', 'enum' => ['fleet_equipment', 'vehicles', 'office_equipment', 'leasehold_improvements', 'land', 'building', 'other', ''], 'description' => 'Asset class filter'],
                        'status'      => ['type' => 'string', 'enum' => ['active', 'fully_depreciated', 'disposed', 'impaired', ''], 'description' => 'Status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_fixed_asset_details',
                'description' => 'Get the full record of a single fixed asset by ID, asset_number (e.g. FA-2026-00007), or by linked equipment unit number (e.g. CHS-001, RFR-002, FLT-001). Returns acquisition cost, taxes, delivery, setup, financing terms, monthly fixed costs (insurance/licensing/registration), depreciation method, salvage value, NBV, GL account links, and the linked equipment unit (if any).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'asset_id'     => ['type' => 'integer', 'description' => 'Numeric fixed asset ID (preferred when known).'],
                        'asset_number' => ['type' => 'string', 'description' => 'Asset number like FA-2026-00007.'],
                        'unit_number'  => ['type' => 'string', 'description' => 'Equipment unit number like CHS-001, RFR-002, FLT-001 — resolves the asset linked to that unit.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_payoff_analysis',
                'description' => 'Calculate how long until a fixed asset linked to an equipment unit pays for itself. Returns total invested (acquisition + taxes + delivery + setup), total revenue, total maintenance / damage / financing / fixed costs, net revenue to date, still-to-recover, progress %, and a projected payoff date based on the rolling 3 / 6 / 12 month average net revenue. Accepts an asset_id, asset_number, or equipment unit_number (e.g. CHS-001, RFR-002, FLT-001 — these are equipment units, NOT customers).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'asset_id'     => ['type' => 'integer', 'description' => 'Numeric fixed asset ID (preferred when known).'],
                        'asset_number' => ['type' => 'string', 'description' => 'Asset number like FA-2026-00007.'],
                        'unit_number'  => ['type' => 'string', 'description' => 'Equipment unit number like CHS-001, RFR-002, FLT-001 — resolves the linked asset for that unit.'],
                        'period'       => ['type' => 'integer', 'enum' => [3, 6, 12], 'description' => 'Rolling window in months for the projection (default 6).'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_depreciation_summary',
                'description' => 'Get depreciation totals for active fixed assets and recent depreciation runs.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_capex_requests',
                'description' => 'List capital expenditure (capex) requests with optional status filter.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'approved', 'rejected', 'in_progress', 'completed', 'cancelled', ''], 'description' => 'Capex request status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Budget Tools ────────────────────────────────────
            [
                'name' => 'get_budgets',
                'description' => 'List all budgets with annual totals.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Phase B Reporting Tools (S036) ──────────────────
            [
                'name' => 'get_profit_and_loss',
                'description' => 'Compute the Profit & Loss statement for a date range. Returns revenue, direct costs, gross profit, operating expenses, operating income, other income/expense, tax provision, and net income — all bcmath strings.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Period start YYYY-MM-DD (defaults to year-start)'],
                        'to'   => ['type' => 'string', 'description' => 'Period end YYYY-MM-DD (defaults to today)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_balance_sheet',
                'description' => 'Compute the Balance Sheet as of a given date. Returns assets (current + long-term), liabilities (current + long-term), equity with YTD net income injected, balance check (is_balanced + drift).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'as_of' => ['type' => 'string', 'description' => 'As-of date YYYY-MM-DD (defaults to today)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_cash_flow',
                'description' => 'Compute the Cash Flow Statement (indirect method, ASPE 1540) for a date range. Returns operating / investing / financing sections with the non-cash adjustments, working capital changes, and tie-out check.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Period start YYYY-MM-DD'],
                        'to'   => ['type' => 'string', 'description' => 'Period end YYYY-MM-DD'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_budget_variance',
                'description' => 'Compute the budget variance report for a date range. Returns per-account budgeted vs actual amounts, variance $ and %, and favorable/unfavorable classification by account normal-balance side.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'budget_id' => ['type' => 'integer', 'description' => 'Budget ID to compare against actuals'],
                        'from'      => ['type' => 'string',  'description' => 'Period start YYYY-MM-DD'],
                        'to'        => ['type' => 'string',  'description' => 'Period end YYYY-MM-DD'],
                    ],
                    'required' => ['budget_id'],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Tax Tools ───────────────────────────────────────
            [
                'name' => 'get_tax_filing_periods',
                'description' => 'List GST/HST/PST tax filing periods with totals (sales, tax collected, ITC, net owing) and filing status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'tax_type' => ['type' => 'string', 'enum' => ['gst_hst', 'pst_bc', 'pst_sk', 'pst_mb', ''], 'description' => 'Tax type filter'],
                        'status'   => ['type' => 'string', 'enum' => ['open', 'calculated', 'filed', 'remitted', ''], 'description' => 'Filing status filter'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Period Tools ────────────────────────────────────
            [
                'name' => 'get_accounting_periods',
                'description' => 'List accounting periods (months) for a year with open/closed/locked status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'year' => ['type' => 'integer', 'description' => 'Year (defaults to current year)'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Collections Tools ───────────────────────────────
            [
                'name' => 'get_promise_to_pay',
                'description' => 'List promise-to-pay records (customer commitments to pay overdue invoices) with optional filters.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status'      => ['type' => 'string', 'enum' => ['pending', 'kept', 'broken', 'cancelled', ''], 'description' => 'P2P status filter'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Optional: filter to a specific customer'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],
            [
                'name' => 'get_collection_notes',
                'description' => 'Get collection contact notes (calls, emails, letters) for a specific customer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat', 'summary'],
            ],

            // ── Payment Tools (additional) ──────────────────────
            [
                'name' => 'get_recent_payments',
                'description' => 'List recent customer payments, optionally filtered by customer.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Optional: filter to specific customer'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat', 'report'],
            ],

            // ── Write / mutation PLANNERS (S-AI-WRITE-1 / S-AI-WRITE-2) ──
            // These do NOT mutate data. They validate the request and
            // compute the exact diff, then persist a PENDING proposal.
            // The change only happens when the user clicks Apply, which
            // hits api/v1/ai/apply-change.php. Gated by ai.write_enabled
            // + the matching module's edit permission. Editable entities +
            // fields are declared in lib/AI/WriteRegistry.php.
            [
                'name' => 'plan_update_record',
                'description' => "Propose a change to ONE field of ONE record, on any supported entity. This does NOT apply the change — it validates and returns a proposal the user confirms with an Apply button. Use when the user asks to change/set/update a record's field (e.g. \"set unit ABCD category to chassis\", \"change customer Acme's phone to 555-1234\", \"set reservation 45 priority to urgent\", \"update vendor Joe's Repair email\"). Supported entity_type values: equipment_unit, customer, vendor, yard, reservation, lease, maintenance_work_order, damage_claim, rate_card. After calling, tell the user what will change and that they must click Apply — do NOT claim it is done.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string', 'enum' => ['equipment_unit', 'customer', 'vendor', 'yard', 'reservation', 'lease', 'maintenance_work_order', 'damage_claim', 'rate_card'], 'description' => 'Which kind of record to edit.'],
                        'identifier'  => ['type' => 'string', 'description' => 'How to find the record: its human identifier (unit_number, company name, vendor/yard/rate-card name, contract_number, work_order_number, claim_number) OR its numeric id.'],
                        'field'       => ['type' => 'string', 'description' => 'The field to change. Use a field name valid for the entity (e.g. equipment_unit: category/year/mileage/license_plate/ownership_type/yard_location/notes; customer/vendor: contact_name/email/phone/address/notes; reservation: priority/pickup_date/notes; etc). If unsure, ask the user or try a sensible name — the tool will reject invalid ones and list valid fields.'],
                        'new_value'   => ['type' => 'string', 'description' => 'The new value (as a string). For category use the slug (e.g. "chassis"); for dates use YYYY-MM-DD; for yes/no flags use "yes"/"no".'],
                    ],
                    'required' => ['entity_type', 'identifier', 'field', 'new_value'],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'plan_bulk_update_records',
                'description' => "Propose the SAME field change across MANY records selected by a filter (e.g. \"set all reefer units in Surrey yard to inactive\", \"mark all high-priority reservations urgent\"). Does NOT apply — returns one proposal listing every affected record for the user to confirm with Apply. Supported entity_type: equipment_unit (filters: status, ownership_type, yard_location, year), reservation (status, priority, yard_location), maintenance_work_order (status, priority, work_type). Capped at 100 records. After calling, tell the user how many records will change and that they must click Apply.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => ['type' => 'string', 'enum' => ['equipment_unit', 'reservation', 'maintenance_work_order'], 'description' => 'Which kind of record to bulk-edit.'],
                        'filters'     => ['type' => 'object', 'description' => 'Map of column→value to SELECT the records, e.g. {"status":"available","yard_location":"Surrey Yard"}. Only allow-listed filter columns are accepted.'],
                        'field'       => ['type' => 'string', 'description' => 'The field to set on every matched record (same field names as plan_update_record).'],
                        'new_value'   => ['type' => 'string', 'description' => 'The new value applied to all matched records.'],
                    ],
                    'required' => ['entity_type', 'filters', 'field', 'new_value'],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'plan_action',
                'description' => "Propose a LIFECYCLE action (a status/state transition with side effects), distinct from a plain field edit. Does NOT apply — returns a proposal the user confirms with an Apply button (NOT undoable). Supported actions: change_equipment_status (move a unit between available/reserved/maintenance/inactive/decommissioned, state-machine enforced — e.g. \"put unit T5301 into maintenance\", \"decommission unit X\"); void_invoice (void a DRAFT or SENT invoice — reverses its journal entry + balance counters; requires a reason; paid invoices cannot be voided, they need a credit note); send_invoice (mark a DRAFT invoice as sent — posts the revenue journal entry and advances balances; e.g. \"send invoice INV-2026-00054\"); void_payment (void/reverse a recorded payment — soft-deletes it, reverses invoice allocations + balance counters + the GL journal entry; requires a reason; needs the payments:delete permission); change_reservation_status (confirm/cancel/etc a reservation — state-machine enforced, frees/holds the unit; cancelling requires a reason; e.g. \"confirm the reservation for Acme\", \"cancel reservation 45\"); change_work_order_status (move a maintenance work order open→in_progress→waiting_parts/completed/cancelled; completing it finalizes the cost into vendor + unit maintenance totals; identifier is the work_order_number); set_yard_active (activate or deactivate a yard — pass new_status='activate' or 'deactivate'; identifier is the yard name; deactivation is blocked if the yard has upcoming reservations; manager-only). After calling, state what will happen and that the user must click Apply — do NOT claim it is done.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'action'     => ['type' => 'string', 'enum' => ['change_equipment_status', 'void_invoice', 'send_invoice', 'void_payment', 'change_reservation_status', 'change_work_order_status', 'set_yard_active'], 'description' => 'Which lifecycle action.'],
                        'identifier' => ['type' => 'string', 'description' => "The record's human identifier (unit_number for equipment, invoice_number like INV-2026-00054 for invoices, payment_number for payments, company_name or numeric id for reservations) or numeric id."],
                        'new_status' => ['type' => 'string', 'description' => 'For change_equipment_status: the target status (available, reserved, maintenance, inactive, decommissioned). The tool enforces allowed transitions. Ignored for void_invoice.'],
                        'reason'     => ['type' => 'string', 'description' => 'Reason/note. REQUIRED for void_invoice; optional for status changes.'],
                    ],
                    'required' => ['action', 'identifier'],
                ],
                '_tags' => ['chat'],
            ],
        ];
    }
}
