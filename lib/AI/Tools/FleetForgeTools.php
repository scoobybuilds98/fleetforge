<?php
declare(strict_types=1);

namespace FleetForge\AI\Tools;

use FleetForge\AI\ToolRegistry;

/**
 * lib/AI/Tools/FleetForgeTools.php
 *
 * Executes AI tool calls against the FleetForge database.
 * Each tool maps to a static method that runs a read-only query
 * and returns structured data for Claude to interpret.
 *
 * Architecture:
 *   - All methods are read-only (SELECT only — no writes)
 *   - Results capped at ToolRegistry::MAX_ROWS to prevent token explosion
 *   - Financial fields stripped when user lacks payments:view permission
 *   - Tool names map to handler methods via run() dispatcher
 *
 * Adding a new tool:
 *   1. Add definition in ToolRegistry::rawDefinitions()
 *   2. Add a case + handler method here
 *   3. Done — automatically available to all AI features
 *
 * @depends includes/db.php (db_select, db_row, db_count)
 * @depends includes/auth.php (can())
 * @session S027
 */
class FleetForgeTools
{
    // ────────────────────────────────────────────────────────────
    // run()
    //
    // Dispatcher — routes a tool name to its handler method.
    // Returns structured data (array or string). The caller
    // (ToolRegistry::execute) JSON-encodes if needed.
    //
    // @param  string   $toolName  Tool name from Claude's tool_use block
    // @param  array    $input     Input parameters from Claude
    // @param  int|null $userId    Current user (for permission checks)
    // @return mixed               Query result (array or string)
    // @throws \RuntimeException   If tool name is unknown
    // ────────────────────────────────────────────────────────────
    public static function run(string $toolName, array $input, ?int $userId = null, ?int $sessionId = null): mixed
    {
        return match ($toolName) {
            // Customer tools
            'search_customers'         => self::searchCustomers($input),
            'get_customer_details'     => self::getCustomerDetails($input),
            'get_customer_leases'      => self::getCustomerLeases($input),
            'get_customer_invoices'    => self::getCustomerInvoices($input, $userId),

            // Fleet / Equipment tools
            'get_fleet_summary'        => self::getFleetSummary(),
            'get_equipment_unit'       => self::getEquipmentUnit($input),
            'search_equipment'         => self::searchEquipment($input),

            // Lease tools
            'get_active_leases'        => self::getActiveLeases($input, $userId),
            'get_lease_details'        => self::getLeaseDetails($input, $userId),
            'get_lease_close_readiness'=> self::getLeaseCloseReadiness($input, $userId),

            // Financial tools
            'get_revenue_by_period'    => self::getRevenueByPeriod($input, $userId),
            'get_revenue_by_customer'  => self::getRevenueByCustomer($input, $userId),
            'get_overdue_invoices'     => self::getOverdueInvoices($userId),
            'get_ar_aging'             => self::getArAging($userId),
            'get_payment_summary'      => self::getPaymentSummary($input, $userId),
            'get_recent_payments'      => self::getRecentPayments($input, $userId),

            // Compliance tools
            'get_expiring_documents'   => self::getExpiringDocuments($input),

            // Maintenance tools
            'get_maintenance_summary'  => self::getMaintenanceSummary($input),

            // Dashboard tools
            'get_dashboard_kpis'       => self::getDashboardKpis($userId),

            // ── Rate / Pricing tools (S028) ─────────────────────
            'get_rate_cards'           => self::getRateCards(),
            'get_rate_card_items'      => self::getRateCardItems($input),
            'get_customer_rates'       => self::getCustomerRates($input),

            // ── Reservation tools (S028) ────────────────────────
            'get_reservations'         => self::getReservations($input),
            'get_reservation_details'  => self::getReservationDetails($input),

            // ── Yard tools (S028) ───────────────────────────────
            'get_yards'                => self::getYards(),
            'get_yard_inventory'       => self::getYardInventory($input),

            // ── Vendor tools (S028) ─────────────────────────────
            'search_vendors'           => self::searchVendors($input),
            'get_vendor_details'       => self::getVendorDetails($input),

            // ── Inspection tools (S028) ─────────────────────────
            'get_inspections'          => self::getInspections($input),
            'get_inspection_details'   => self::getInspectionDetails($input),

            // ── Damage Claim tools (S028) ───────────────────────
            'get_damage_claims'        => self::getDamageClaims($input, $userId),
            'get_damage_claim_details' => self::getDamageClaimDetails($input, $userId),

            // ── Mileage tools (S028) ────────────────────────────
            'get_mileage_logs'         => self::getMileageLogs($input),

            // ── Credit Note tools (S028) ────────────────────────
            'get_credit_notes'         => self::getCreditNotes($input, $userId),

            // ── General Ledger tools (S028) ─────────────────────
            'get_chart_of_accounts'    => self::getChartOfAccounts($input),
            'get_journal_entries'      => self::getJournalEntries($input, $userId),
            'get_trial_balance'        => self::getTrialBalance($input, $userId),
            'get_account_balance'      => self::getAccountBalance($input, $userId),

            // ── Accounts Payable tools (S028) ───────────────────
            'get_vendor_bills'         => self::getVendorBills($input, $userId),
            'get_ap_aging'             => self::getApAging($userId),

            // ── Banking tools (S028) ────────────────────────────
            'get_bank_accounts'        => self::getBankAccounts($userId),
            'get_bank_transactions'    => self::getBankTransactions($input, $userId),

            // ── Fixed Asset tools (S028) ────────────────────────
            'get_fixed_assets'         => self::getFixedAssets($input, $userId),
            'get_fixed_asset_details'  => self::getFixedAssetDetails($input, $userId),
            'get_payoff_analysis'      => self::getPayoffAnalysis($input, $userId),
            'get_depreciation_summary' => self::getDepreciationSummary($userId),
            'get_capex_requests'       => self::getCapexRequests($input, $userId),

            // ── Budget tools (S028) ─────────────────────────────
            'get_budgets'              => self::getBudgets($userId),

            // ── Phase B reporting tools (S036) ──────────────────
            'get_profit_and_loss'      => self::getProfitAndLoss($input, $userId),
            'get_balance_sheet'        => self::getBalanceSheet($input, $userId),
            'get_cash_flow'            => self::getCashFlow($input, $userId),
            'get_budget_variance'      => self::getBudgetVariance($input, $userId),

            // ── Tax tools (S028) ────────────────────────────────
            'get_tax_filing_periods'   => self::getTaxFilingPeriods($input, $userId),

            // ── Period tools (S028) ─────────────────────────────
            'get_accounting_periods'   => self::getAccountingPeriods($input),

            // ── Collections tools (S028) ────────────────────────
            'get_promise_to_pay'       => self::getPromiseToPay($input, $userId),
            'get_collection_notes'     => self::getCollectionNotes($input),

            // ── Write planners (S-AI-WRITE-1 / generalized S-AI-WRITE-2) ──
            'plan_update_record'       => self::planUpdateRecord($input, $userId, $sessionId),
            'plan_bulk_update_records' => self::planBulkUpdateRecords($input, $userId, $sessionId),

            // ── Action planner (S-AI-ACTION-1) — lifecycle transitions ──
            'plan_action'              => self::planAction($input, $userId, $sessionId),

            default => throw new \RuntimeException("Unknown tool: {$toolName}"),
        };
    }

    // ════════════════════════════════════════════════════════════
    //  CUSTOMER TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // searchCustomers
    //
    // Searches customers by name, email, or city. Optionally
    // filters by status. Returns up to MAX_ROWS matches.
    // ────────────────────────────────────────────────────────────
    private static function searchCustomers(array $input): array
    {
        $query  = trim($input['query'] ?? '');
        $status = trim($input['status'] ?? '');
        $limit  = ToolRegistry::MAX_ROWS;

        $where  = ['c.deleted_at IS NULL'];
        $params = [];

        if ($query !== '') {
            $where[]  = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.city LIKE ?)';
            $like     = "%{$query}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status !== '') {
            $where[]  = 'c.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT c.id, c.company_name, c.contact_name, c.email, c.phone,
                    c.city, c.province, c.status, c.risk_score,
                    c.active_lease_count, c.outstanding_balance
             FROM customers c
             WHERE {$whereSql}
             ORDER BY c.company_name ASC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getCustomerDetails
    //
    // Full profile for a single customer including contact info,
    // status, risk score, credit limit, and lease count.
    // ────────────────────────────────────────────────────────────
    private static function getCustomerDetails(array $input): array|string
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId <= 0) return 'Error: customer_id is required.';

        $row = db_row(
            "SELECT c.id, c.company_name, c.contact_name, c.email, c.phone, c.alt_phone,
                    c.address, c.city, c.province, c.postal_code, c.country,
                    c.status, c.risk_score, c.risk_notes, c.credit_limit,
                    c.payment_terms, c.currency, c.billing_cycle,
                    c.lease_count, c.active_lease_count,
                    c.total_revenue, c.outstanding_balance, c.account_credit_balance,
                    c.dot_number, c.mc_number, c.tax_exempt,
                    c.notes, c.created_at
             FROM customers c
             WHERE c.id = ? AND c.deleted_at IS NULL",
            [$customerId]
        );

        return $row ?? "No customer found with ID {$customerId}.";
    }

    // ────────────────────────────────────────────────────────────
    // getCustomerLeases
    //
    // Lists leases for a customer with optional status filter.
    // ────────────────────────────────────────────────────────────
    private static function getCustomerLeases(array $input): array|string
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId <= 0) return 'Error: customer_id is required.';

        $status = trim($input['status'] ?? '');
        $limit  = ToolRegistry::MAX_ROWS;

        $where  = ['l.customer_id = ?', 'l.deleted_at IS NULL'];
        $params = [$customerId];

        if ($status !== '') {
            $where[]  = 'l.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT l.id, l.contract_number, l.unit_number_snapshot AS unit_number,
                    l.start_date, l.end_date, l.status,
                    l.monthly_rate, l.daily_rate, l.weekly_rate, l.currency,
                    l.outstanding_balance
             FROM leases l
             WHERE {$whereSql}
             ORDER BY l.start_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getCustomerInvoices
    //
    // Lists invoices for a customer with status, amounts, due
    // dates, and payment info. Financial fields stripped if user
    // lacks payments:view permission.
    // ────────────────────────────────────────────────────────────
    private static function getCustomerInvoices(array $input, ?int $userId): array|string
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId <= 0) return 'Error: customer_id is required.';

        $status = trim($input['status'] ?? '');
        $limit  = ToolRegistry::MAX_ROWS;

        $where  = ['i.customer_id = ?', 'i.deleted_at IS NULL'];
        $params = [$customerId];

        if ($status !== '') {
            $where[]  = 'i.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $rows = db_select(
            "SELECT i.id, i.invoice_number, i.invoice_type, i.status,
                    i.invoice_date, i.due_date, i.paid_date,
                    i.total_amount, i.amount_paid, i.balance_due,
                    i.currency, i.contract_number_snapshot AS contract_number
             FROM invoices i
             WHERE {$whereSql}
             ORDER BY i.invoice_date DESC
             LIMIT {$limit}",
            $params
        );

        // WHY: Dispatchers can see invoice status/dates but not dollar amounts
        return self::stripFinancials($rows, $userId);
    }

    // ════════════════════════════════════════════════════════════
    //  FLEET / EQUIPMENT TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getFleetSummary
    //
    // Aggregate fleet stats: unit counts by status, total units,
    // and utilization rate (on_lease / total available+on_lease).
    // ────────────────────────────────────────────────────────────
    private static function getFleetSummary(): array
    {
        $rows = db_select(
            "SELECT eu.status, COUNT(*) AS count
             FROM equipment_units eu
             WHERE eu.deleted_at IS NULL
             GROUP BY eu.status
             ORDER BY count DESC"
        );

        // WHY: Build a status → count map for easy reading by Claude
        $byStatus = [];
        $total = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = (int) $r['count'];
            $total += (int) $r['count'];
        }

        $onLease   = $byStatus['on_lease'] ?? 0;
        $available  = $byStatus['available'] ?? 0;
        $leasable   = $onLease + $available;
        $utilization = $leasable > 0 ? round($onLease / $leasable * 100, 1) : 0;

        return [
            'total_units'      => $total,
            'by_status'        => $byStatus,
            'utilization_rate' => $utilization,
            'leasable_units'   => $leasable,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getEquipmentUnit
    //
    // Detailed info about a specific equipment unit including
    // template, status, mileage, compliance dates, GPS config.
    // ────────────────────────────────────────────────────────────
    private static function getEquipmentUnit(array $input): array|string
    {
        $unitId = (int) ($input['unit_id'] ?? 0);
        if ($unitId <= 0) return 'Error: unit_id is required.';

        $row = db_row(
            "SELECT eu.id, eu.unit_number, eu.vin, eu.year, eu.status,
                    eu.ownership_type, eu.yard_location, eu.mileage,
                    eu.license_plate, eu.license_state,
                    eu.cvi_expiry, eu.registration_expiry, eu.mvi_expiry, eu.insurance_expiry,
                    eu.tracking_provider, eu.gps_device_id,
                    eu.health_score, eu.lease_count, eu.total_revenue, eu.total_maintenance_cost,
                    eu.acquired_date, eu.acquisition_cost,
                    eu.notes, eu.created_at,
                    et.name AS template_name, et.category, et.brand, et.model
             FROM equipment_units eu
             LEFT JOIN equipment_templates et ON et.id = eu.template_id
             WHERE eu.id = ? AND eu.deleted_at IS NULL",
            [$unitId]
        );

        return $row ?? "No equipment unit found with ID {$unitId}.";
    }

    // ────────────────────────────────────────────────────────────
    // searchEquipment
    //
    // Search equipment units by unit number, template name,
    // status, or category. Returns up to MAX_ROWS matches.
    // ────────────────────────────────────────────────────────────
    private static function searchEquipment(array $input): array
    {
        $query    = trim($input['query'] ?? '');
        $status   = trim($input['status'] ?? '');
        $category = trim($input['category'] ?? '');
        $limit    = ToolRegistry::MAX_ROWS;

        $where  = ['eu.deleted_at IS NULL'];
        $params = [];

        if ($query !== '') {
            $where[]  = '(eu.unit_number LIKE ? OR et.name LIKE ?)';
            $like     = "%{$query}%";
            $params[] = $like;
            $params[] = $like;
        }

        if ($status !== '') {
            $where[]  = 'eu.status = ?';
            $params[] = $status;
        }

        if ($category !== '') {
            $where[]  = 'et.category = ?';
            $params[] = $category;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT eu.id, eu.unit_number, eu.status, eu.mileage,
                    eu.yard_location, eu.ownership_type,
                    et.name AS template_name, et.category
             FROM equipment_units eu
             LEFT JOIN equipment_templates et ON et.id = eu.template_id
             WHERE {$whereSql}
             ORDER BY eu.unit_number ASC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  LEASE TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getActiveLeases
    //
    // All currently active leases with customer name, unit
    // number, dates, rate, and status. Optional customer filter.
    // ────────────────────────────────────────────────────────────
    private static function getActiveLeases(array $input, ?int $userId): array
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        $limit      = ToolRegistry::MAX_ROWS;

        $where  = ["l.status = 'active'", 'l.deleted_at IS NULL'];
        $params = [];

        if ($customerId > 0) {
            $where[]  = 'l.customer_id = ?';
            $params[] = $customerId;
        }

        $whereSql = implode(' AND ', $where);

        $rows = db_select(
            "SELECT l.id, l.contract_number,
                    l.company_name_snapshot AS customer_name,
                    l.unit_number_snapshot AS unit_number,
                    l.start_date, l.end_date, l.status,
                    l.monthly_rate, l.daily_rate, l.currency,
                    l.outstanding_balance
             FROM leases l
             WHERE {$whereSql}
             ORDER BY l.start_date DESC
             LIMIT {$limit}",
            $params
        );

        return self::stripFinancials($rows, $userId);
    }

    // ────────────────────────────────────────────────────────────
    // getLeaseDetails
    //
    // Full details about a specific lease including customer,
    // unit, billing terms, mileage, and financial totals.
    // ────────────────────────────────────────────────────────────
    private static function getLeaseDetails(array $input, ?int $userId): array|string
    {
        $leaseId = (int) ($input['lease_id'] ?? 0);
        if ($leaseId <= 0) return 'Error: lease_id is required.';

        $row = db_row(
            "SELECT l.id, l.contract_number, l.status,
                    l.customer_id, l.company_name_snapshot AS customer_name,
                    l.equipment_unit_id, l.unit_number_snapshot AS unit_number,
                    l.template_name_snapshot AS template_name,
                    l.start_date, l.end_date, l.actual_return_date,
                    l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
                    l.mileage_rate, l.mileage_unit,
                    l.estimated_mileage, l.actual_mileage,
                    l.mileage_at_start, l.mileage_at_end,
                    l.billing_cycle, l.po_number,
                    l.discount_type, l.discount_value,
                    l.insurance_opt_in, l.insurance_cost,
                    l.total_invoiced, l.total_paid, l.outstanding_balance,
                    l.notes, l.created_at
             FROM leases l
             WHERE l.id = ? AND l.deleted_at IS NULL",
            [$leaseId]
        );

        if ($row === null) {
            return "No lease found with ID {$leaseId}.";
        }

        // WHY: Strip dollar fields for users who can't view payments
        if (!self::canViewFinancials($userId)) {
            $financialKeys = [
                'daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate',
                'insurance_cost', 'discount_value',
                'total_invoiced', 'total_paid', 'outstanding_balance',
            ];
            foreach ($financialKeys as $key) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    // ────────────────────────────────────────────────────────────
    // getLeaseCloseReadiness
    //
    // READ-ONLY assessment of what closing a lease would require. The AI
    // cannot execute a lease close yet (it's a multi-input flow: odometer,
    // refund method, advance reconciliation — see api/v1/leases/close.php),
    // but it CAN tell the user whether a lease is closeable and what inputs
    // the close would need. Mirrors close.php's read-side detection (status
    // gate, precharge-refund-owed, advance-batch reconciliation).
    // ────────────────────────────────────────────────────────────
    private static function getLeaseCloseReadiness(array $input, ?int $userId): array|string
    {
        $leaseId = (int) ($input['lease_id'] ?? 0);
        if ($leaseId <= 0) return 'Error: lease_id is required.';

        $lease = db_row(
            "SELECT l.id, l.contract_number, l.status, l.company_name_snapshot AS customer_name,
                    l.equipment_unit_id, l.unit_number_snapshot AS unit_number,
                    l.start_date, l.end_date, l.last_billed_date,
                    l.mileage_at_start, l.odometer_start_km,
                    l.precharge_enabled, l.precharge_amount, l.precharge_balance,
                    l.precharge_invoiced_at, l.precharge_refund_method, l.precharge_refund_settled_at
             FROM leases l
             WHERE l.id = ? AND l.deleted_at IS NULL",
            [$leaseId]
        );
        if ($lease === null) {
            return "No lease found with ID {$leaseId}.";
        }

        $canClose  = ($lease['status'] === 'active');
        $blockers  = [];
        if (!$canClose) {
            $blockers[] = "Lease status is '{$lease['status']}' — only active leases can be closed.";
        }
        if ($lease['equipment_unit_id'] && !db_exists('equipment_units', 'id = ? AND deleted_at IS NULL', [$lease['equipment_unit_id']])) {
            $blockers[] = 'Linked equipment unit not found.';
        }

        // Precharge refund owed? (precharge_enabled + residual balance > 0)
        $prechargeBalance = (float) ($lease['precharge_balance'] ?? 0);
        $refundOwed       = ((int) $lease['precharge_enabled'] === 1) && $prechargeBalance > 0;
        $refundLocked     = $lease['precharge_refund_method'] !== null; // method already chosen (immutable)

        // Advance-batch close? (activation generated advance invoices)
        $advanceCount = db_count(
            "SELECT COUNT(*) FROM invoices
              WHERE lease_id = ? AND generation_source = 'advance' AND deleted_at IS NULL AND status != 'void'",
            [$leaseId]
        );
        $isAdvanceClose = $advanceCount > 0;

        // What the close request would need the operator to decide:
        $requiredInputs = [];
        if ($refundOwed && !$refundLocked) {
            $requiredInputs[] = "precharge_refund method (cash or credit) — \$" . number_format($prechargeBalance, 2) . ' residual to return';
        }
        if ($isAdvanceClose) {
            $requiredInputs[] = 'reconciliation_mode (refund_unused or no_refund) for the prepaid advance invoices';
        }

        $result = [
            'lease_id'                 => (int) $lease['id'],
            'contract_number'          => $lease['contract_number'],
            'customer_name'            => $lease['customer_name'],
            'unit_number'              => $lease['unit_number'],
            'status'                   => $lease['status'],
            'can_close'                => $canClose && empty($blockers),
            'blockers'                 => $blockers,
            'precharge_refund_owed'    => $refundOwed,
            'precharge_refund_locked'  => $refundLocked,
            'is_advance_close'         => $isAdvanceClose,
            'advance_invoice_count'    => (int) $advanceCount,
            'has_start_mileage'        => $lease['mileage_at_start'] !== null,
            'has_start_odometer'       => $lease['odometer_start_km'] !== null,
            'last_billed_date'         => $lease['last_billed_date'],
            'end_date'                 => $lease['end_date'],
            'required_inputs_for_close'=> $requiredInputs,
            'note'                     => 'Read-only readiness check. Executing the close still happens via the lease Close form (it needs odometer + refund decisions the AI cannot make on its own yet).',
        ];

        // Gate dollar amounts behind payments:view (mirrors other tools).
        if (self::canViewFinancials($userId)) {
            $result['precharge_balance'] = $prechargeBalance;
            $result['precharge_amount']  = $lease['precharge_amount'] !== null ? (float) $lease['precharge_amount'] : null;
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════════
    //  FINANCIAL TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getRevenueByPeriod
    //
    // Revenue grouped by month for a date range. Shows total
    // invoiced and total collected per month.
    // ────────────────────────────────────────────────────────────
    private static function getRevenueByPeriod(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $dateFrom = $input['date_from'] ?? '';
        $dateTo   = $input['date_to'] ?? '';
        if ($dateFrom === '' || $dateTo === '') return 'Error: date_from and date_to are required.';

        return db_select(
            "SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS month,
                    COUNT(*) AS invoice_count,
                    SUM(i.total_amount) AS total_invoiced,
                    SUM(i.amount_paid) AS total_collected,
                    SUM(i.balance_due) AS total_outstanding
             FROM invoices i
             WHERE i.invoice_date BETWEEN ? AND ?
               AND i.status NOT IN ('void', 'written_off')
               AND i.deleted_at IS NULL
             GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
             ORDER BY month ASC",
            [$dateFrom, $dateTo]
        );
    }

    // ────────────────────────────────────────────────────────────
    // getRevenueByCustomer
    //
    // Revenue per customer for a date range. Shows invoice count,
    // total billed, total paid, and outstanding balance.
    // ────────────────────────────────────────────────────────────
    private static function getRevenueByCustomer(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $dateFrom = $input['date_from'] ?? '';
        $dateTo   = $input['date_to'] ?? '';
        if ($dateFrom === '' || $dateTo === '') return 'Error: date_from and date_to are required.';

        $limit = ToolRegistry::MAX_ROWS;

        return db_select(
            "SELECT i.customer_id,
                    i.company_name_snapshot AS customer_name,
                    COUNT(*) AS invoice_count,
                    SUM(i.total_amount) AS total_billed,
                    SUM(i.amount_paid) AS total_paid,
                    SUM(i.balance_due) AS outstanding
             FROM invoices i
             WHERE i.invoice_date BETWEEN ? AND ?
               AND i.status NOT IN ('void', 'written_off')
               AND i.deleted_at IS NULL
             GROUP BY i.customer_id, i.company_name_snapshot
             ORDER BY total_billed DESC
             LIMIT {$limit}",
            [$dateFrom, $dateTo]
        );
    }

    // ────────────────────────────────────────────────────────────
    // getOverdueInvoices
    //
    // All currently overdue invoices with customer name, amount,
    // days overdue, and due date.
    // ────────────────────────────────────────────────────────────
    private static function getOverdueInvoices(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $limit = ToolRegistry::MAX_ROWS;

        return db_select(
            "SELECT i.id, i.invoice_number,
                    i.company_name_snapshot AS customer_name,
                    i.total_amount, i.amount_paid, i.balance_due,
                    i.currency, i.due_date,
                    DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
                    i.contract_number_snapshot AS contract_number
             FROM invoices i
             WHERE i.status = 'overdue'
               AND i.deleted_at IS NULL
             ORDER BY days_overdue DESC
             LIMIT {$limit}"
        );
    }

    // ────────────────────────────────────────────────────────────
    // getArAging
    //
    // Accounts receivable aging: outstanding balances grouped by
    // aging buckets (current, 1-30, 31-60, 61-90, 90+ days).
    // ────────────────────────────────────────────────────────────
    private static function getArAging(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        // WHY: CASE-based aging buckets in a single query for efficiency
        $row = db_row(
            "SELECT
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) <= 0 THEN i.balance_due ELSE 0 END) AS current_amount,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.balance_due ELSE 0 END) AS days_1_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.balance_due ELSE 0 END) AS days_31_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.balance_due ELSE 0 END) AS days_61_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.balance_due ELSE 0 END) AS days_90_plus,
                SUM(i.balance_due) AS total_outstanding,
                COUNT(*) AS invoice_count
             FROM invoices i
             WHERE i.balance_due > 0
               AND i.status NOT IN ('void', 'written_off', 'draft')
               AND i.deleted_at IS NULL"
        );

        return $row ?? ['total_outstanding' => 0, 'invoice_count' => 0];
    }

    // ────────────────────────────────────────────────────────────
    // getPaymentSummary
    //
    // Payment totals and collection rate for a date range. Shows
    // total collected, payment count, methods, and collection %.
    // ────────────────────────────────────────────────────────────
    private static function getPaymentSummary(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $dateFrom = $input['date_from'] ?? '';
        $dateTo   = $input['date_to'] ?? '';
        if ($dateFrom === '' || $dateTo === '') return 'Error: date_from and date_to are required.';

        // Total payments in period
        $totals = db_row(
            "SELECT COUNT(*) AS payment_count,
                    COALESCE(SUM(p.amount), 0) AS total_collected
             FROM payments p
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.status = 'cleared'
               AND p.deleted_at IS NULL",
            [$dateFrom, $dateTo]
        );

        // Payment method breakdown
        $methods = db_select(
            "SELECT p.payment_method, COUNT(*) AS count,
                    SUM(p.amount) AS total
             FROM payments p
             WHERE p.payment_date BETWEEN ? AND ?
               AND p.status = 'cleared'
               AND p.deleted_at IS NULL
             GROUP BY p.payment_method
             ORDER BY total DESC",
            [$dateFrom, $dateTo]
        );

        // WHY: Collection rate = payments received / invoices due in same period
        $invoiced = db_row(
            "SELECT COALESCE(SUM(i.total_amount), 0) AS total_invoiced
             FROM invoices i
             WHERE i.invoice_date BETWEEN ? AND ?
               AND i.status NOT IN ('void', 'written_off', 'draft')
               AND i.deleted_at IS NULL",
            [$dateFrom, $dateTo]
        );

        $totalInvoiced  = (float) ($invoiced['total_invoiced'] ?? 0);
        $totalCollected = (float) ($totals['total_collected'] ?? 0);
        $collectionRate = $totalInvoiced > 0 ? round($totalCollected / $totalInvoiced * 100, 1) : 0;

        return [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'payment_count'   => (int) ($totals['payment_count'] ?? 0),
            'total_collected'  => $totalCollected,
            'total_invoiced'   => $totalInvoiced,
            'collection_rate'  => $collectionRate,
            'by_method'        => $methods,
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  COMPLIANCE TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getExpiringDocuments
    //
    // Equipment compliance documents (CVI, registration, MVI,
    // insurance) expiring within the next N days.
    // ────────────────────────────────────────────────────────────
    private static function getExpiringDocuments(array $input): array
    {
        $daysAhead = (int) ($input['days_ahead'] ?? 30);
        if ($daysAhead <= 0) $daysAhead = 30;

        $limit    = ToolRegistry::MAX_ROWS;
        $deadline = date('Y-m-d', strtotime("+{$daysAhead} days"));
        $today    = date('Y-m-d');

        // WHY: UNION ALL across four compliance date columns to capture all expiry types
        return db_select(
            "SELECT * FROM (
                SELECT eu.id AS unit_id, eu.unit_number, 'CVI' AS document_type,
                       eu.cvi_expiry AS expiry_date,
                       DATEDIFF(eu.cvi_expiry, CURDATE()) AS days_until_expiry
                FROM equipment_units eu
                WHERE eu.cvi_expiry IS NOT NULL
                  AND eu.cvi_expiry BETWEEN ? AND ?
                  AND eu.status NOT IN ('decommissioned', 'inactive')
                  AND eu.deleted_at IS NULL

                UNION ALL

                SELECT eu.id AS unit_id, eu.unit_number, 'Registration' AS document_type,
                       eu.registration_expiry AS expiry_date,
                       DATEDIFF(eu.registration_expiry, CURDATE()) AS days_until_expiry
                FROM equipment_units eu
                WHERE eu.registration_expiry IS NOT NULL
                  AND eu.registration_expiry BETWEEN ? AND ?
                  AND eu.status NOT IN ('decommissioned', 'inactive')
                  AND eu.deleted_at IS NULL

                UNION ALL

                SELECT eu.id AS unit_id, eu.unit_number, 'MVI' AS document_type,
                       eu.mvi_expiry AS expiry_date,
                       DATEDIFF(eu.mvi_expiry, CURDATE()) AS days_until_expiry
                FROM equipment_units eu
                WHERE eu.mvi_expiry IS NOT NULL
                  AND eu.mvi_expiry BETWEEN ? AND ?
                  AND eu.status NOT IN ('decommissioned', 'inactive')
                  AND eu.deleted_at IS NULL

                UNION ALL

                SELECT eu.id AS unit_id, eu.unit_number, 'Insurance' AS document_type,
                       eu.insurance_expiry AS expiry_date,
                       DATEDIFF(eu.insurance_expiry, CURDATE()) AS days_until_expiry
                FROM equipment_units eu
                WHERE eu.insurance_expiry IS NOT NULL
                  AND eu.insurance_expiry BETWEEN ? AND ?
                  AND eu.status NOT IN ('decommissioned', 'inactive')
                  AND eu.deleted_at IS NULL
            ) AS expiring
            ORDER BY days_until_expiry ASC
            LIMIT {$limit}",
            [$today, $deadline, $today, $deadline, $today, $deadline, $today, $deadline]
        );
    }

    // ════════════════════════════════════════════════════════════
    //  MAINTENANCE TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getMaintenanceSummary
    //
    // Work order stats: open/in-progress/completed counts, total
    // costs, and recent work orders. Optional unit_id filter.
    // ────────────────────────────────────────────────────────────
    private static function getMaintenanceSummary(array $input): array
    {
        $unitId = (int) ($input['unit_id'] ?? 0);

        $where  = ['wo.deleted_at IS NULL'];
        $params = [];

        if ($unitId > 0) {
            $where[]  = 'wo.equipment_unit_id = ?';
            $params[] = $unitId;
        }

        $whereSql = implode(' AND ', $where);

        // Status counts
        $statuses = db_select(
            "SELECT wo.status, COUNT(*) AS count,
                    COALESCE(SUM(wo.total_cost), 0) AS total_cost
             FROM maintenance_work_orders wo
             WHERE {$whereSql}
             GROUP BY wo.status",
            $params
        );

        // WHY: Build status → {count, cost} map
        $byStatus = [];
        $totalCost = 0;
        $totalOrders = 0;
        foreach ($statuses as $s) {
            $byStatus[$s['status']] = [
                'count' => (int) $s['count'],
                'cost'  => (float) $s['total_cost'],
            ];
            $totalCost   += (float) $s['total_cost'];
            $totalOrders += (int) $s['count'];
        }

        // Recent work orders (last 10)
        $recent = db_select(
            "SELECT wo.id, wo.work_order_number, wo.title, wo.status,
                    wo.work_type, wo.priority, wo.total_cost,
                    wo.requested_date, wo.completed_date,
                    eu.unit_number
             FROM maintenance_work_orders wo
             LEFT JOIN equipment_units eu ON eu.id = wo.equipment_unit_id
             WHERE {$whereSql}
             ORDER BY wo.created_at DESC
             LIMIT 10",
            $params
        );

        return [
            'total_work_orders' => $totalOrders,
            'total_cost'        => round($totalCost, 2),
            'by_status'         => $byStatus,
            'recent_orders'     => $recent,
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  DASHBOARD TOOLS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getDashboardKpis
    //
    // Key performance indicators: total revenue this month,
    // active leases, fleet utilization, overdue invoices,
    // upcoming compliance alerts.
    // ────────────────────────────────────────────────────────────
    private static function getDashboardKpis(?int $userId): array
    {
        $monthStart = date('Y-m-01');
        $today      = date('Y-m-d');
        $in30Days   = date('Y-m-d', strtotime('+30 days'));

        // Active leases
        $activeLeases = db_count(
            "SELECT COUNT(*) FROM leases WHERE status = 'active' AND deleted_at IS NULL"
        );

        // Fleet utilization
        $fleet = self::getFleetSummary();

        // Overdue invoice count + total
        $overdue = db_row(
            "SELECT COUNT(*) AS count, COALESCE(SUM(balance_due), 0) AS total
             FROM invoices
             WHERE status = 'overdue' AND deleted_at IS NULL"
        );

        // Revenue this month (only if user can view financials)
        $monthRevenue = null;
        if (self::canViewFinancials($userId)) {
            $rev = db_row(
                "SELECT COALESCE(SUM(total_amount), 0) AS invoiced,
                        COALESCE(SUM(amount_paid), 0) AS collected
                 FROM invoices
                 WHERE invoice_date >= ? AND invoice_date <= ?
                   AND status NOT IN ('void', 'written_off')
                   AND deleted_at IS NULL",
                [$monthStart, $today]
            );
            $monthRevenue = [
                'invoiced'  => (float) ($rev['invoiced'] ?? 0),
                'collected' => (float) ($rev['collected'] ?? 0),
            ];
        }

        // Upcoming compliance alerts (next 30 days)
        $complianceAlerts = db_count(
            "SELECT COUNT(*) FROM (
                SELECT id FROM equipment_units
                WHERE cvi_expiry BETWEEN ? AND ? AND status NOT IN ('decommissioned','inactive') AND deleted_at IS NULL
                UNION ALL
                SELECT id FROM equipment_units
                WHERE registration_expiry BETWEEN ? AND ? AND status NOT IN ('decommissioned','inactive') AND deleted_at IS NULL
                UNION ALL
                SELECT id FROM equipment_units
                WHERE mvi_expiry BETWEEN ? AND ? AND status NOT IN ('decommissioned','inactive') AND deleted_at IS NULL
                UNION ALL
                SELECT id FROM equipment_units
                WHERE insurance_expiry BETWEEN ? AND ? AND status NOT IN ('decommissioned','inactive') AND deleted_at IS NULL
            ) AS alerts",
            [$today, $in30Days, $today, $in30Days, $today, $in30Days, $today, $in30Days]
        );

        // Active customers
        $activeCustomers = db_count(
            "SELECT COUNT(*) FROM customers WHERE status = 'active' AND deleted_at IS NULL"
        );

        return [
            'active_leases'      => $activeLeases,
            'active_customers'   => $activeCustomers,
            'fleet_utilization'  => $fleet['utilization_rate'],
            'total_units'        => $fleet['total_units'],
            'overdue_invoices'   => [
                'count' => (int) ($overdue['count'] ?? 0),
                'total' => self::canViewFinancials($userId) ? (float) ($overdue['total'] ?? 0) : null,
            ],
            'compliance_alerts_30d' => $complianceAlerts,
            'month_revenue'         => $monthRevenue,
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  RATE / PRICING TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getRateCards
    //
    // Lists all rate cards with item counts and effective dates.
    // Used by the AI when answering rate/pricing questions.
    // ────────────────────────────────────────────────────────────
    private static function getRateCards(): array
    {
        return db_select(
            "SELECT rc.id, rc.name, rc.description, rc.is_default,
                    rc.effective_from, rc.effective_to,
                    (SELECT COUNT(*) FROM rate_card_items WHERE rate_card_id = rc.id) AS item_count
             FROM rate_cards rc
             WHERE rc.deleted_at IS NULL
             ORDER BY rc.is_default DESC, rc.name ASC"
        );
    }

    // ────────────────────────────────────────────────────────────
    // getRateCardItems
    //
    // Returns all rate items for a specific rate card. If no
    // rate_card_id is given, falls back to the default rate card.
    // ────────────────────────────────────────────────────────────
    private static function getRateCardItems(array $input): array|string
    {
        $cardId = (int) ($input['rate_card_id'] ?? 0);

        // WHY: AI may ask "what are our rates?" without specifying a card — fall back to default.
        if ($cardId <= 0) {
            $default = db_row(
                "SELECT id FROM rate_cards WHERE is_default = 1 AND deleted_at IS NULL LIMIT 1"
            );
            if ($default === null) return 'No default rate card found. Please specify rate_card_id.';
            $cardId = (int) $default['id'];
        }

        $card = db_row(
            "SELECT id, name, description, is_default, effective_from, effective_to
             FROM rate_cards WHERE id = ? AND deleted_at IS NULL",
            [$cardId]
        );
        if ($card === null) return "No rate card found with ID {$cardId}.";

        $items = db_select(
            "SELECT equipment_type, daily_rate, weekly_rate, monthly_rate,
                    mileage_rate, mileage_unit, currency, notes
             FROM rate_card_items
             WHERE rate_card_id = ?
             ORDER BY equipment_type ASC",
            [$cardId]
        );

        return ['rate_card' => $card, 'items' => $items];
    }

    // ────────────────────────────────────────────────────────────
    // getCustomerRates
    //
    // Customer-specific negotiated rates, read from that customer's
    // rate cards (S-RATES-CONSOLIDATE: per-type overrides retired —
    // customer pricing lives on customer-owned rate cards).
    // ────────────────────────────────────────────────────────────
    private static function getCustomerRates(array $input): array|string
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId <= 0) return 'Error: customer_id is required.';

        return db_select(
            "SELECT rci.equipment_type, rci.daily_rate, rci.weekly_rate, rci.monthly_rate,
                    rci.mileage_rate, rci.mileage_unit, rci.currency,
                    rc.name AS rate_card, rc.effective_from, rc.effective_to
             FROM rate_card_items rci
             JOIN rate_cards rc ON rc.id = rci.rate_card_id
             WHERE rc.customer_id = ? AND rc.deleted_at IS NULL
             ORDER BY rci.equipment_type ASC, rc.effective_from DESC",
            [$customerId]
        );
    }

    // ════════════════════════════════════════════════════════════
    //  RESERVATION TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getReservations
    //
    // List reservations with optional status filter.
    // ────────────────────────────────────────────────────────────
    private static function getReservations(array $input): array
    {
        $status = trim($input['status'] ?? '');
        $limit  = ToolRegistry::MAX_ROWS;

        $where  = ['r.deleted_at IS NULL'];
        $params = [];

        if ($status !== '') {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT r.id, r.status, r.contact_name, r.company_name,
                    r.contact_phone, r.contact_email, r.quantity,
                    r.pickup_date, r.pickup_time, r.yard_location,
                    r.priority, r.purpose, r.created_at
             FROM reservations r
             WHERE {$whereSql}
             ORDER BY r.pickup_date ASC, r.priority DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getReservationDetails
    //
    // Full reservation profile including reserved equipment units.
    // ────────────────────────────────────────────────────────────
    private static function getReservationDetails(array $input): array|string
    {
        $reservationId = (int) ($input['reservation_id'] ?? 0);
        if ($reservationId <= 0) return 'Error: reservation_id is required.';

        $row = db_row(
            "SELECT * FROM reservations WHERE id = ? AND deleted_at IS NULL",
            [$reservationId]
        );

        if ($row === null) return "No reservation found with ID {$reservationId}.";

        $units = db_select(
            "SELECT ru.equipment_unit_id, eu.unit_number, eu.status
             FROM reservation_units ru
             LEFT JOIN equipment_units eu ON eu.id = ru.equipment_unit_id
             WHERE ru.reservation_id = ?",
            [$reservationId]
        );

        $row['reserved_units'] = $units;
        return $row;
    }

    // ════════════════════════════════════════════════════════════
    //  YARD TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getYards
    //
    // List all yards with capacity and current unit count.
    // ────────────────────────────────────────────────────────────
    private static function getYards(): array
    {
        return db_select(
            "SELECT y.id, y.name, y.slug, y.city, y.state, y.capacity,
                    y.is_active, y.phone, y.address,
                    (SELECT COUNT(*) FROM equipment_units eu
                      WHERE eu.yard_location = y.slug AND eu.deleted_at IS NULL) AS unit_count
             FROM yards y
             ORDER BY y.is_active DESC, y.name ASC"
        );
    }

    // ────────────────────────────────────────────────────────────
    // getYardInventory
    //
    // List all equipment units currently parked at a yard.
    // ────────────────────────────────────────────────────────────
    private static function getYardInventory(array $input): array|string
    {
        $yardId = (int) ($input['yard_id'] ?? 0);
        if ($yardId <= 0) return 'Error: yard_id is required.';

        $yard = db_row("SELECT id, name, slug FROM yards WHERE id = ?", [$yardId]);
        if ($yard === null) return "No yard found with ID {$yardId}.";

        $units = db_select(
            "SELECT eu.id, eu.unit_number, eu.status, eu.mileage,
                    et.name AS template_name, et.category
             FROM equipment_units eu
             LEFT JOIN equipment_templates et ON et.id = eu.template_id
             WHERE eu.yard_location = ? AND eu.deleted_at IS NULL
             ORDER BY eu.unit_number ASC",
            [$yard['slug']]
        );

        return ['yard' => $yard, 'unit_count' => count($units), 'units' => $units];
    }

    // ════════════════════════════════════════════════════════════
    //  VENDOR TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // searchVendors
    //
    // Search vendors by name/contact/email and optional vendor_type.
    // ────────────────────────────────────────────────────────────
    private static function searchVendors(array $input): array
    {
        $query = trim($input['query'] ?? '');
        $type  = trim($input['vendor_type'] ?? '');
        $limit = ToolRegistry::MAX_ROWS;

        $where  = ['v.deleted_at IS NULL'];
        $params = [];

        if ($query !== '') {
            $where[]  = '(v.name LIKE ? OR v.contact_name LIKE ? OR v.email LIKE ?)';
            $like     = "%{$query}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($type !== '') {
            $where[]  = 'v.vendor_type = ?';
            $params[] = $type;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT v.id, v.name, v.vendor_type, v.contact_name, v.email, v.phone,
                    v.city, v.state, v.hourly_rate, v.rating, v.is_preferred, v.total_spent
             FROM vendors v
             WHERE {$whereSql}
             ORDER BY v.is_preferred DESC, v.name ASC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getVendorDetails
    //
    // Full vendor profile.
    // ────────────────────────────────────────────────────────────
    private static function getVendorDetails(array $input): array|string
    {
        $vendorId = (int) ($input['vendor_id'] ?? 0);
        if ($vendorId <= 0) return 'Error: vendor_id is required.';

        $row = db_row(
            "SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL",
            [$vendorId]
        );

        return $row ?? "No vendor found with ID {$vendorId}.";
    }

    // ════════════════════════════════════════════════════════════
    //  INSPECTION TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getInspections
    //
    // List inspections, optionally filtered by unit, lease, or type.
    // ────────────────────────────────────────────────────────────
    private static function getInspections(array $input): array
    {
        $unitId  = (int) ($input['unit_id'] ?? 0);
        $leaseId = (int) ($input['lease_id'] ?? 0);
        $type    = trim($input['inspection_type'] ?? '');
        $limit   = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($unitId > 0)  { $where[] = 'i.equipment_unit_id = ?'; $params[] = $unitId; }
        if ($leaseId > 0) { $where[] = 'i.lease_id = ?';          $params[] = $leaseId; }
        if ($type !== '') { $where[] = 'i.inspection_type = ?';   $params[] = $type; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT i.id, i.inspection_number, i.inspection_type,
                    i.equipment_unit_id, eu.unit_number,
                    i.lease_id, l.contract_number,
                    i.overall_condition, i.condition_score, i.status,
                    i.inspected_by, i.inspection_date, i.mileage_at_inspection,
                    i.fuel_level, i.is_clean
             FROM inspections i
             LEFT JOIN equipment_units eu ON eu.id = i.equipment_unit_id
             LEFT JOIN leases l           ON l.id = i.lease_id
             WHERE {$whereSql}
             ORDER BY i.inspection_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getInspectionDetails
    //
    // Full inspection record with unit and lease context.
    // ────────────────────────────────────────────────────────────
    private static function getInspectionDetails(array $input): array|string
    {
        $inspectionId = (int) ($input['inspection_id'] ?? 0);
        if ($inspectionId <= 0) return 'Error: inspection_id is required.';

        $row = db_row(
            "SELECT i.*, eu.unit_number, l.contract_number
             FROM inspections i
             LEFT JOIN equipment_units eu ON eu.id = i.equipment_unit_id
             LEFT JOIN leases l           ON l.id = i.lease_id
             WHERE i.id = ?",
            [$inspectionId]
        );

        return $row ?? "No inspection found with ID {$inspectionId}.";
    }

    // ════════════════════════════════════════════════════════════
    //  DAMAGE CLAIM TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getDamageClaims
    //
    // List damage claims with optional status/severity filter.
    // ────────────────────────────────────────────────────────────
    private static function getDamageClaims(array $input, ?int $userId): array
    {
        $status   = trim($input['status'] ?? '');
        $severity = trim($input['severity'] ?? '');
        $limit    = ToolRegistry::MAX_ROWS;

        $where  = ['dc.deleted_at IS NULL'];
        $params = [];

        if ($status !== '')   { $where[] = 'dc.status = ?';   $params[] = $status; }
        if ($severity !== '') { $where[] = 'dc.severity = ?'; $params[] = $severity; }

        $whereSql = implode(' AND ', $where);

        $rows = db_select(
            "SELECT dc.id, dc.claim_number, dc.equipment_unit_id, eu.unit_number,
                    dc.lease_id, dc.customer_id, dc.customer_name,
                    dc.severity, dc.status, dc.description, dc.damage_location,
                    dc.estimated_repair_cost, dc.actual_repair_cost,
                    dc.customer_liable_amount, dc.created_at
             FROM damage_claims dc
             LEFT JOIN equipment_units eu ON eu.id = dc.equipment_unit_id
             WHERE {$whereSql}
             ORDER BY dc.created_at DESC
             LIMIT {$limit}",
            $params
        );

        return self::stripFinancials($rows, $userId);
    }

    // ────────────────────────────────────────────────────────────
    // getDamageClaimDetails
    //
    // Full damage claim record with related context.
    // ────────────────────────────────────────────────────────────
    private static function getDamageClaimDetails(array $input, ?int $userId): array|string
    {
        $claimId = (int) ($input['claim_id'] ?? 0);
        if ($claimId <= 0) return 'Error: claim_id is required.';

        $row = db_row(
            "SELECT dc.*, eu.unit_number, l.contract_number
             FROM damage_claims dc
             LEFT JOIN equipment_units eu ON eu.id = dc.equipment_unit_id
             LEFT JOIN leases l           ON l.id = dc.lease_id
             WHERE dc.id = ? AND dc.deleted_at IS NULL",
            [$claimId]
        );

        if ($row === null) return "No damage claim found with ID {$claimId}.";

        if (!self::canViewFinancials($userId)) {
            foreach (['estimated_repair_cost', 'actual_repair_cost',
                      'customer_liable_amount', 'insurance_claim_amount'] as $k) {
                unset($row[$k]);
            }
        }

        return $row;
    }

    // ════════════════════════════════════════════════════════════
    //  MILEAGE TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getMileageLogs
    //
    // Recent mileage readings for a unit or lease.
    // ────────────────────────────────────────────────────────────
    private static function getMileageLogs(array $input): array
    {
        $unitId  = (int) ($input['unit_id'] ?? 0);
        $leaseId = (int) ($input['lease_id'] ?? 0);
        $limit   = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($unitId > 0)  { $where[] = 'ml.equipment_unit_id = ?'; $params[] = $unitId; }
        if ($leaseId > 0) { $where[] = 'ml.lease_id = ?';          $params[] = $leaseId; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT ml.id, ml.equipment_unit_id, eu.unit_number,
                    ml.lease_id, l.contract_number,
                    ml.log_type, ml.odometer_reading, ml.mileage_unit,
                    ml.log_date, ml.notes
             FROM mileage_logs ml
             LEFT JOIN equipment_units eu ON eu.id = ml.equipment_unit_id
             LEFT JOIN leases l           ON l.id = ml.lease_id
             WHERE {$whereSql}
             ORDER BY ml.log_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  CREDIT NOTE TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getCreditNotes
    //
    // List credit notes with optional customer/status filter.
    // ────────────────────────────────────────────────────────────
    private static function getCreditNotes(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $status     = trim($input['status'] ?? '');
        $limit      = ToolRegistry::MAX_ROWS;

        $where  = ['cn.deleted_at IS NULL'];
        $params = [];

        if ($customerId > 0) { $where[] = 'cn.customer_id = ?'; $params[] = $customerId; }
        if ($status !== '')  { $where[] = 'cn.status = ?';      $params[] = $status; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT cn.id, cn.credit_note_number, cn.customer_id,
                    c.company_name AS customer_name,
                    cn.source, cn.amount, cn.amount_remaining, cn.currency,
                    cn.status, cn.expires_at, cn.reason, cn.created_at
             FROM credit_notes cn
             LEFT JOIN customers c ON c.id = cn.customer_id
             WHERE {$whereSql}
             ORDER BY cn.created_at DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  GENERAL LEDGER TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getChartOfAccounts
    //
    // List the chart of accounts with optional account_type filter.
    // ────────────────────────────────────────────────────────────
    private static function getChartOfAccounts(array $input): array
    {
        $accountType = trim($input['account_type'] ?? '');
        $limit       = ToolRegistry::MAX_ROWS;

        $where  = ['is_active = 1'];
        $params = [];

        if ($accountType !== '') {
            $where[]  = 'account_type = ?';
            $params[] = $accountType;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT id, code, name, account_type, account_subtype, normal_balance,
                    currency, is_bank_account, parent_id, sort_order
             FROM acc_accounts
             WHERE {$whereSql}
             ORDER BY code ASC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getJournalEntries
    //
    // Recent journal entries within a date range.
    // ────────────────────────────────────────────────────────────
    private static function getJournalEntries(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $dateFrom = $input['date_from'] ?? date('Y-m-01');
        $dateTo   = $input['date_to']   ?? date('Y-m-d');
        $status   = trim($input['status'] ?? '');
        $limit    = ToolRegistry::MAX_ROWS;

        $where  = ['je.entry_date BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];

        if ($status !== '') {
            $where[]  = 'je.status = ?';
            $params[] = $status;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT je.id, je.entry_number, je.entry_date, je.entry_type,
                    je.status, je.description, je.reference, je.source_type,
                    je.currency,
                    (SELECT COALESCE(SUM(debit), 0) FROM acc_journal_entry_lines
                      WHERE journal_entry_id = je.id) AS total_debit
             FROM acc_journal_entries je
             WHERE {$whereSql}
             ORDER BY je.entry_date DESC, je.id DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getTrialBalance
    //
    // Trial balance as of a specific date — only posted entries.
    // ────────────────────────────────────────────────────────────
    private static function getTrialBalance(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $asOf = $input['as_of_date'] ?? date('Y-m-d');

        return db_select(
            "SELECT a.code, a.name, a.account_type, a.normal_balance,
                    COALESCE(SUM(jel.debit), 0)  AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit,
                    COALESCE(SUM(jel.debit - jel.credit), 0) AS balance
             FROM acc_accounts a
             LEFT JOIN acc_journal_entry_lines jel ON jel.account_id = a.id
             LEFT JOIN acc_journal_entries je      ON je.id = jel.journal_entry_id
                AND je.entry_date <= ?
                AND je.status = 'posted'
             WHERE a.is_active = 1 AND a.is_header = 0
             GROUP BY a.id, a.code, a.name, a.account_type, a.normal_balance
             HAVING balance != 0
             ORDER BY a.code ASC",
            [$asOf]
        );
    }

    // ────────────────────────────────────────────────────────────
    // getAccountBalance
    //
    // Net balance for a specific GL account (looked up by id or code).
    // ────────────────────────────────────────────────────────────
    private static function getAccountBalance(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $accountId = (int) ($input['account_id'] ?? 0);
        if ($accountId <= 0) {
            $code = trim($input['account_code'] ?? '');
            if ($code === '') return 'Error: account_id or account_code is required.';
            $a = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code]);
            if ($a === null) return "No account found with code {$code}.";
            $accountId = (int) $a['id'];
        }

        $account = db_row(
            "SELECT id, code, name, account_type, normal_balance
             FROM acc_accounts WHERE id = ?",
            [$accountId]
        );
        if ($account === null) return "No account found with ID {$accountId}.";

        $balance = db_row(
            "SELECT COALESCE(SUM(jel.debit), 0)  AS total_debit,
                    COALESCE(SUM(jel.credit), 0) AS total_credit,
                    COALESCE(SUM(jel.debit - jel.credit), 0) AS net_balance
             FROM acc_journal_entry_lines jel
             JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
             WHERE jel.account_id = ? AND je.status = 'posted'",
            [$accountId]
        );

        return array_merge($account, $balance ?? []);
    }

    // ════════════════════════════════════════════════════════════
    //  ACCOUNTS PAYABLE TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getVendorBills
    //
    // List AP bills, optionally filtered by vendor or status.
    // ────────────────────────────────────────────────────────────
    private static function getVendorBills(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $vendorId = (int) ($input['vendor_id'] ?? 0);
        $status   = trim($input['status'] ?? '');
        $limit    = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($vendorId > 0)  { $where[] = 'b.vendor_id = ?'; $params[] = $vendorId; }
        if ($status !== '') { $where[] = 'b.status = ?';    $params[] = $status; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT b.id, b.bill_number, b.vendor_id, v.name AS vendor_name,
                    b.bill_date, b.due_date, b.status, b.currency,
                    b.subtotal, b.tax_total, b.total_amount, b.amount_paid, b.balance_due
             FROM acc_bills b
             LEFT JOIN vendors v ON v.id = b.vendor_id
             WHERE {$whereSql}
             ORDER BY b.due_date ASC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getApAging
    //
    // AP aging buckets (current, 1-30, 31-60, 61-90, 90+ days overdue).
    // ────────────────────────────────────────────────────────────
    private static function getApAging(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $row = db_row(
            "SELECT
                SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) <= 0 THEN b.balance_due ELSE 0 END) AS current_amount,
                SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 1 AND 30 THEN b.balance_due ELSE 0 END) AS days_1_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 31 AND 60 THEN b.balance_due ELSE 0 END) AS days_31_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 61 AND 90 THEN b.balance_due ELSE 0 END) AS days_61_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) > 90 THEN b.balance_due ELSE 0 END) AS days_90_plus,
                SUM(b.balance_due) AS total_outstanding,
                COUNT(*) AS bill_count
             FROM acc_bills b
             WHERE b.balance_due > 0
               AND b.status NOT IN ('void', 'paid', 'draft')"
        );

        return $row ?? ['total_outstanding' => 0, 'bill_count' => 0];
    }

    // ════════════════════════════════════════════════════════════
    //  BANKING TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getBankAccounts
    //
    // List bank accounts with current movement totals.
    // ────────────────────────────────────────────────────────────
    private static function getBankAccounts(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        return db_select(
            "SELECT ba.id, ba.name, ba.institution, ba.account_type, ba.currency,
                    ba.account_number_last4, ba.opening_balance,
                    ba.is_active, ba.is_default,
                    (SELECT COALESCE(SUM(amount), 0)
                       FROM acc_bank_transactions
                      WHERE bank_account_id = ba.id) AS net_movement
             FROM acc_bank_accounts ba
             ORDER BY ba.is_default DESC, ba.name ASC"
        );
    }

    // ────────────────────────────────────────────────────────────
    // getBankTransactions
    //
    // Recent bank transactions, optionally for a specific account.
    // ────────────────────────────────────────────────────────────
    private static function getBankTransactions(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $accountId = (int) ($input['bank_account_id'] ?? 0);
        $dateFrom  = $input['date_from'] ?? date('Y-m-01');
        $dateTo    = $input['date_to']   ?? date('Y-m-d');
        $limit     = ToolRegistry::MAX_ROWS;

        $where  = ['t.transaction_date BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];

        if ($accountId > 0) {
            $where[]  = 't.bank_account_id = ?';
            $params[] = $accountId;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT t.id, t.bank_account_id, ba.name AS bank_account_name,
                    t.transaction_date, t.description, t.reference,
                    t.amount, t.transaction_type, t.status, t.is_cleared
             FROM acc_bank_transactions t
             LEFT JOIN acc_bank_accounts ba ON ba.id = t.bank_account_id
             WHERE {$whereSql}
             ORDER BY t.transaction_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  FIXED ASSET TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getFixedAssets
    //
    // List fixed assets with NBV, optionally filtered.
    // ────────────────────────────────────────────────────────────
    private static function getFixedAssets(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $assetClass = trim($input['asset_class'] ?? '');
        $status     = trim($input['status'] ?? '');
        $limit      = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($assetClass !== '') { $where[] = 'asset_class = ?'; $params[] = $assetClass; }
        if ($status !== '')     { $where[] = 'status = ?';      $params[] = $status; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT id, asset_number, name, asset_class, cra_class,
                    acquisition_date, acquisition_cost,
                    depreciation_method, useful_life_years,
                    accumulated_depreciation, net_book_value,
                    status, location
             FROM acc_fixed_assets
             WHERE {$whereSql}
             ORDER BY acquisition_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // resolveAssetRow()
    //
    // Internal helper for the new fixed-asset tools. Accepts any of:
    //   asset_id     — numeric primary key
    //   asset_number — e.g. FA-2026-00007
    //   unit_number  — e.g. CHS-001 (resolves the asset linked to it)
    //
    // Returns the matching acc_fixed_assets row joined with the
    // equipment_unit_number, or a string error message.
    //
    // WHY centralised: get_fixed_asset_details and get_payoff_analysis
    // both need this lookup, and we want consistent error messages
    // when the AI is given a unit number that doesn't exist or
    // has no linked asset (the most likely failure modes).
    // ────────────────────────────────────────────────────────────
    private static function resolveAssetRow(array $input): array|string
    {
        $assetId     = isset($input['asset_id'])     ? (int) $input['asset_id'] : 0;
        $assetNumber = trim((string) ($input['asset_number'] ?? ''));
        $unitNumber  = trim((string) ($input['unit_number']  ?? ''));

        $sql = "SELECT a.*, eu.unit_number AS equipment_unit_number,
                       et.category         AS equipment_category,
                       et.name             AS equipment_template_name
                FROM acc_fixed_assets a
                LEFT JOIN equipment_units eu
                       ON eu.id = a.equipment_unit_id
                      AND eu.deleted_at IS NULL
                LEFT JOIN equipment_templates et
                       ON et.id = eu.template_id";

        if ($assetId > 0) {
            $row = db_row("$sql WHERE a.id = ? LIMIT 1", [$assetId]);
            if (!$row) return "No fixed asset found with id={$assetId}.";
            return $row;
        }

        if ($assetNumber !== '') {
            $row = db_row("$sql WHERE a.asset_number = ? LIMIT 1", [$assetNumber]);
            if (!$row) return "No fixed asset found with asset_number={$assetNumber}.";
            return $row;
        }

        if ($unitNumber !== '') {
            // Resolve the unit first so we can give a precise error
            // when the unit exists but has no linked asset.
            $unit = db_row(
                "SELECT id, unit_number FROM equipment_units
                  WHERE unit_number = ? AND deleted_at IS NULL
                  LIMIT 1",
                [$unitNumber]
            );
            if (!$unit) {
                return "No equipment unit found with unit_number={$unitNumber}. "
                     . "Note: unit numbers like CHS-001, RFR-002, FLT-001 belong to "
                     . "the equipment fleet, not customers — use search_equipment to "
                     . "look up units, or search_customers for customers.";
            }
            $row = db_row(
                "$sql WHERE a.equipment_unit_id = ? AND a.status != 'disposed'
                       ORDER BY a.id DESC LIMIT 1",
                [(int) $unit['id']]
            );
            if (!$row) {
                return "Equipment unit {$unitNumber} (id={$unit['id']}) is not linked "
                     . "to any fixed asset. Link it on the asset Edit form before "
                     . "running a payoff analysis.";
            }
            return $row;
        }

        return 'Provide one of: asset_id, asset_number, or unit_number.';
    }

    // ────────────────────────────────────────────────────────────
    // getFixedAssetDetails()
    //
    // Returns the full record of a single fixed asset by id, asset
    // number, or linked equipment unit number. Used by the AI when
    // a user asks for the cost / financing / depreciation profile
    // of a specific asset or unit (e.g. "what's the financing on
    // FA-2026-00007?" or "show me the cost basis for CHS-001").
    // ────────────────────────────────────────────────────────────
    private static function getFixedAssetDetails(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $row = self::resolveAssetRow($input);
        if (is_string($row)) return $row;

        // WHY shape: We omit the noisy DB internals (created_by /
        // updated_at / *_account_id) and return a flat structure the
        // AI can speak about naturally.
        return [
            'id'                       => (int) $row['id'],
            'asset_number'             => $row['asset_number'],
            'name'                     => $row['name'],
            'description'              => $row['description'],
            'asset_class'              => $row['asset_class'],
            'status'                   => $row['status'],
            'location'                 => $row['location'],
            'equipment_unit_id'        => $row['equipment_unit_id'] !== null ? (int) $row['equipment_unit_id'] : null,
            'equipment_unit_number'    => $row['equipment_unit_number'],
            'equipment_category'       => $row['equipment_category'],
            'equipment_template_name'  => $row['equipment_template_name'],
            'acquisition_date'         => $row['acquisition_date'],
            'acquisition_cost'         => $row['acquisition_cost'],
            'purchase_tax_gst'         => $row['purchase_tax_gst'],
            'purchase_tax_pst'         => $row['purchase_tax_pst'],
            'delivery_cost'            => $row['delivery_cost'],
            'setup_cost'               => $row['setup_cost'],
            'is_financed'              => (int) ($row['is_financed'] ?? 0),
            'financing_monthly_payment'=> $row['financing_monthly_payment'],
            'financing_interest_rate'  => $row['financing_interest_rate'],
            'financing_remaining_months' => $row['financing_remaining_months'],
            'monthly_insurance_cost'   => $row['monthly_insurance_cost'],
            'monthly_licensing_cost'   => $row['monthly_licensing_cost'],
            'monthly_registration_cost'=> $row['monthly_registration_cost'],
            'depreciation_method'      => $row['depreciation_method'],
            'useful_life_years'        => $row['useful_life_years'],
            'salvage_value'            => $row['salvage_value'],
            'depreciable_cost'         => $row['depreciable_cost'],
            'accumulated_depreciation' => $row['accumulated_depreciation'],
            'net_book_value'           => $row['net_book_value'],
            'depreciation_start_date'  => $row['depreciation_start_date'],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getPayoffAnalysis()
    //
    // Mirrors the calculation done by api/v1/accounting/fixed_assets/
    // payoff.php. Returns the headline numbers the AI needs to answer
    // "how long until <unit> pays for itself?":
    //
    //   total_invested      = purchase + GST + PST + delivery + setup
    //   total_revenue       = invoice line items linked to the unit
    //   total_maintenance   = completed work order costs
    //   total_damage        = damage_claims (actual or estimated)
    //   total_financing_paid = months_since_acq × monthly_payment
    //   total_fixed_paid    = months_since_acq × (ins+lic+reg)
    //   net_revenue_to_date = revenue − all costs
    //   still_to_recover    = total_invested − net_revenue (floored at 0)
    //   progress_pct        = (net_revenue / total_invested) × 100
    //
    // Plus a 6-month-rolling projected payoff (months + date), and
    // the 3 / 6 / 12 month average net revenue per month.
    //
    // All math is bcmath strings — never floats — per D16.
    // ────────────────────────────────────────────────────────────
    private static function getPayoffAnalysis(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $asset = self::resolveAssetRow($input);
        if (is_string($asset)) return $asset;

        if (empty($asset['equipment_unit_id'])) {
            return 'This asset is not linked to an equipment unit, so payoff cannot '
                 . 'be computed. Link it to a unit on the asset Edit form first.';
        }

        $period = isset($input['period']) ? (int) $input['period'] : 6;
        if (!in_array($period, [3, 6, 12], true)) $period = 6;

        $eqUnitId = (int) $asset['equipment_unit_id'];

        // BC rounding helper — match payoff.php::$bcround behaviour.
        $bcround = static function (string $val, int $scale = 2): string {
            if ($val === '' || !is_numeric($val)) return '0.00';
            $half = '0.' . str_repeat('0', $scale) . '5';
            return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
        };

        // ── Acquisition total ───────────────────────────────────
        $purchaseCost = (string) ($asset['acquisition_cost'] ?? '0.00');
        $gst          = (string) ($asset['purchase_tax_gst'] ?? '0.00');
        $pst          = (string) ($asset['purchase_tax_pst'] ?? '0.00');
        $delivery     = (string) ($asset['delivery_cost']    ?? '0.00');
        $setup        = (string) ($asset['setup_cost']       ?? '0.00');
        if ($gst === '')      $gst = '0.00';
        if ($pst === '')      $pst = '0.00';
        if ($delivery === '') $delivery = '0.00';
        if ($setup === '')    $setup = '0.00';

        $totalAcquisition = bcadd($purchaseCost, $gst, 2);
        $totalAcquisition = bcadd($totalAcquisition, $pst, 2);
        $totalAcquisition = bcadd($totalAcquisition, $delivery, 2);
        $totalAcquisition = bcadd($totalAcquisition, $setup, 2);

        // ── Months since acquisition (clamped to >= 1) ──────────
        $acquiredDate   = (string) ($asset['acquisition_date'] ?? '');
        $monthsSinceAcq = 0;
        if ($acquiredDate !== '') {
            try {
                $start = new \DateTimeImmutable($acquiredDate);
                $now   = new \DateTimeImmutable('today');
                if ($now > $start) {
                    $diff = $start->diff($now);
                    $monthsSinceAcq = ($diff->y * 12) + $diff->m;
                    if ($diff->d > 0) $monthsSinceAcq += 1;
                }
            } catch (\Throwable $e) {
                $monthsSinceAcq = 0;
            }
        }
        if ($monthsSinceAcq < 1) $monthsSinceAcq = 1;

        // ── Monthly fixed cost total ────────────────────────────
        $ins = (string) ($asset['monthly_insurance_cost']    ?? '0.00');
        $lic = (string) ($asset['monthly_licensing_cost']    ?? '0.00');
        $reg = (string) ($asset['monthly_registration_cost'] ?? '0.00');
        if ($ins === '') $ins = '0.00';
        if ($lic === '') $lic = '0.00';
        if ($reg === '') $reg = '0.00';

        $monthlyFixed   = bcadd(bcadd($ins, $lic, 2), $reg, 2);
        $totalFixedPaid = bcmul($monthlyFixed, (string) $monthsSinceAcq, 2);

        // ── Financing paid so far ───────────────────────────────
        $totalFinancingPaid = '0.00';
        if ((int) ($asset['is_financed'] ?? 0) === 1
            && !empty($asset['financing_monthly_payment'])) {
            $payment = (string) $asset['financing_monthly_payment'];
            if ($payment === '') $payment = '0.00';
            $totalFinancingPaid = bcmul($payment, (string) $monthsSinceAcq, 2);
        }

        // ── Total revenue from linked leases/invoices ───────────
        $revenueRow = db_row(
            "SELECT COALESCE(SUM(ili.amount), 0) AS total
             FROM invoice_line_items ili
             JOIN invoices i ON i.id = ili.invoice_id
             JOIN leases   l ON l.id = i.lease_id
             WHERE l.equipment_unit_id = ?
               AND l.deleted_at IS NULL
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('void', 'written_off')
               AND ili.is_credit = 0",
            [$eqUnitId]
        );
        $totalRevenue = (string) ($revenueRow['total'] ?? '0.00');
        if ($totalRevenue === '') $totalRevenue = '0.00';

        // ── Total maintenance cost ──────────────────────────────
        $mntRow = db_row(
            "SELECT COALESCE(SUM(total_cost), 0) AS total
             FROM maintenance_work_orders
             WHERE equipment_unit_id = ?
               AND status = 'completed'
               AND deleted_at IS NULL",
            [$eqUnitId]
        );
        $totalMaintenance = (string) ($mntRow['total'] ?? '0.00');
        if ($totalMaintenance === '') $totalMaintenance = '0.00';

        // ── Total damage cost ───────────────────────────────────
        $dmgRow = db_row(
            "SELECT COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS total
             FROM damage_claims
             WHERE equipment_unit_id = ?
               AND deleted_at IS NULL",
            [$eqUnitId]
        );
        $totalDamage = (string) ($dmgRow['total'] ?? '0.00');
        if ($totalDamage === '') $totalDamage = '0.00';

        // ── Net revenue to date ─────────────────────────────────
        $netRevenue = bcsub($totalRevenue, $totalMaintenance, 2);
        $netRevenue = bcsub($netRevenue, $totalDamage, 2);
        $netRevenue = bcsub($netRevenue, $totalFinancingPaid, 2);
        $netRevenue = bcsub($netRevenue, $totalFixedPaid, 2);

        $stillToRecover = bcsub($totalAcquisition, $netRevenue, 2);
        if (bccomp($stillToRecover, '0', 2) < 0) $stillToRecover = '0.00';

        $progressPct = '0.00';
        if (bccomp($totalAcquisition, '0', 2) > 0) {
            $raw = bcdiv(bcmul($netRevenue, '100', 6), $totalAcquisition, 6);
            $progressPct = $bcround($raw, 2);
        }

        // ── 14-month rolling history → scenario averages ───────
        $monthlyRows = db_select(
            "SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym,
                    COALESCE(SUM(ili.amount), 0) AS revenue
             FROM invoice_line_items ili
             JOIN invoices i ON i.id = ili.invoice_id
             JOIN leases   l ON l.id = i.lease_id
             WHERE l.equipment_unit_id = ?
               AND l.deleted_at IS NULL
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('void', 'written_off')
               AND ili.is_credit = 0
               AND i.invoice_date >= (CURDATE() - INTERVAL 13 MONTH)
             GROUP BY ym",
            [$eqUnitId]
        );
        $revByMonth = [];
        foreach ($monthlyRows as $r) $revByMonth[$r['ym']] = (string) $r['revenue'];

        $mntMonthRows = db_select(
            "SELECT DATE_FORMAT(COALESCE(completed_date, requested_date), '%Y-%m') AS ym,
                    COALESCE(SUM(total_cost), 0) AS cost
             FROM maintenance_work_orders
             WHERE equipment_unit_id = ?
               AND status = 'completed'
               AND deleted_at IS NULL
               AND COALESCE(completed_date, requested_date) >= (CURDATE() - INTERVAL 13 MONTH)
             GROUP BY ym",
            [$eqUnitId]
        );
        $mntByMonth = [];
        foreach ($mntMonthRows as $r) $mntByMonth[$r['ym']] = (string) $r['cost'];

        $dmgMonthRows = db_select(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
                    COALESCE(SUM(COALESCE(actual_repair_cost, estimated_repair_cost, 0)), 0) AS cost
             FROM damage_claims
             WHERE equipment_unit_id = ?
               AND deleted_at IS NULL
               AND created_at >= (CURDATE() - INTERVAL 13 MONTH)
             GROUP BY ym",
            [$eqUnitId]
        );
        $dmgByMonth = [];
        foreach ($dmgMonthRows as $r) $dmgByMonth[$r['ym']] = (string) $r['cost'];

        // Dense 14-month series (oldest → newest) of net revenue.
        $netSeries = [];
        $cursor = new \DateTimeImmutable('first day of this month');
        $start  = $cursor->modify('-13 months');
        $monthlyFinancePortion = '0.00';
        if ((int) ($asset['is_financed'] ?? 0) === 1
            && !empty($asset['financing_monthly_payment'])) {
            $monthlyFinancePortion = (string) $asset['financing_monthly_payment'];
        }
        for ($m = $start; $m <= $cursor; $m = $m->modify('+1 month')) {
            $ym = $m->format('Y-m');
            $rev = $revByMonth[$ym] ?? '0.00';
            $mnt = $mntByMonth[$ym] ?? '0.00';
            $dmg = $dmgByMonth[$ym] ?? '0.00';

            $net = bcsub($rev, $mnt, 2);
            $net = bcsub($net, $dmg, 2);
            $net = bcsub($net, $monthlyFinancePortion, 2);
            $net = bcsub($net, $monthlyFixed, 2);
            $netSeries[] = $net;
        }

        // Tail-window average helper.
        $avgFromTail = function (array $rows, int $n) use ($bcround): string {
            if ($n <= 0 || count($rows) === 0) return '0.00';
            $n = min($n, count($rows));
            $slice = array_slice($rows, -$n);
            $sum = '0.00';
            foreach ($slice as $v) $sum = bcadd($sum, $v, 2);
            return $bcround(bcdiv($sum, (string) $n, 6), 2);
        };

        $avg3  = $avgFromTail($netSeries, 3);
        $avg6  = $avgFromTail($netSeries, 6);
        $avg12 = $avgFromTail($netSeries, 12);

        // Project months/date for an average net.
        $projectFor = function (string $avg) use ($stillToRecover) {
            if (bccomp($avg, '0', 2) <= 0 || bccomp($stillToRecover, '0', 2) <= 0) {
                return ['months' => null, 'date' => null];
            }
            $months    = bcdiv($stillToRecover, $avg, 2);
            $monthsInt = (int) ceil((float) $months);
            try {
                $d = (new \DateTimeImmutable('today'))->modify("+{$monthsInt} months");
                return ['months' => $monthsInt, 'date' => $d->format('Y-m-d')];
            } catch (\Throwable $e) {
                return ['months' => $monthsInt, 'date' => null];
            }
        };

        $selectedAvg = match ($period) {
            3  => $avg3,
            12 => $avg12,
            default => $avg6,
        };
        $selected = $projectFor($selectedAvg);
        $consProj = $projectFor($avg12);
        $currProj = $projectFor($avg6);
        $optProj  = $projectFor($avg3);

        $fullyPaid = bccomp($stillToRecover, '0', 2) === 0;

        return [
            'asset' => [
                'id'                    => (int) $asset['id'],
                'asset_number'          => $asset['asset_number'],
                'name'                  => $asset['name'],
                'equipment_unit_id'     => $eqUnitId,
                'equipment_unit_number' => $asset['equipment_unit_number'],
                'equipment_category'    => $asset['equipment_category'],
                'acquisition_date'      => $asset['acquisition_date'],
                'is_financed'           => (int) ($asset['is_financed'] ?? 0),
                'months_since_acquisition' => $monthsSinceAcq,
            ],
            'invested' => [
                'purchase_cost'   => $bcround($purchaseCost, 2),
                'gst'             => $bcround($gst, 2),
                'pst'             => $bcround($pst, 2),
                'delivery_cost'   => $bcround($delivery, 2),
                'setup_cost'      => $bcround($setup, 2),
                'total_invested'  => $bcround($totalAcquisition, 2),
            ],
            'totals' => [
                'total_revenue'        => $bcround($totalRevenue, 2),
                'total_maintenance'    => $bcround($totalMaintenance, 2),
                'total_damage'         => $bcround($totalDamage, 2),
                'total_financing_paid' => $bcround($totalFinancingPaid, 2),
                'monthly_fixed_cost'   => $bcround($monthlyFixed, 2),
                'total_fixed_paid'     => $bcround($totalFixedPaid, 2),
                'net_revenue_to_date'  => $bcround($netRevenue, 2),
                'still_to_recover'     => $bcround($stillToRecover, 2),
                'progress_pct'         => $progressPct,
                'fully_paid'           => $fullyPaid,
            ],
            'scenarios' => [
                'optimistic_3mo'   => ['avg_monthly_net' => $avg3,  'months' => $optProj['months'],  'date' => $optProj['date']],
                'current_6mo'      => ['avg_monthly_net' => $avg6,  'months' => $currProj['months'], 'date' => $currProj['date']],
                'conservative_12mo'=> ['avg_monthly_net' => $avg12, 'months' => $consProj['months'], 'date' => $consProj['date']],
            ],
            'projection' => [
                'period_months'    => $period,
                'avg_monthly_net'  => $selectedAvg,
                'projected_months' => $selected['months'],
                'projected_date'   => $selected['date'],
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────
    // getDepreciationSummary
    //
    // Totals for active fixed assets and recent depreciation runs.
    // ────────────────────────────────────────────────────────────
    private static function getDepreciationSummary(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $totals = db_row(
            "SELECT COUNT(*) AS total_assets,
                    COALESCE(SUM(acquisition_cost), 0)         AS total_cost,
                    COALESCE(SUM(accumulated_depreciation), 0) AS total_depreciation,
                    COALESCE(SUM(net_book_value), 0)           AS total_nbv
             FROM acc_fixed_assets
             WHERE status = 'active'"
        );

        $recentRuns = db_select(
            "SELECT id, run_date, status, total_depreciation, asset_count
             FROM acc_depreciation_runs
             ORDER BY run_date DESC
             LIMIT 10"
        );

        return ['totals' => $totals, 'recent_runs' => $recentRuns];
    }

    // ────────────────────────────────────────────────────────────
    // getCapexRequests
    //
    // Capital expenditure requests with optional status filter.
    // ────────────────────────────────────────────────────────────
    private static function getCapexRequests(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $status = trim($input['status'] ?? '');
        $limit  = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT id, request_number, title, asset_class, status,
                    budget_amount, actual_amount, requested_at, approved_at,
                    justification
             FROM acc_capex_requests
             WHERE {$whereSql}
             ORDER BY requested_at DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  BUDGET TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getBudgets
    //
    // List all budgets with annual totals.
    // ────────────────────────────────────────────────────────────
    private static function getBudgets(?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        return db_select(
            "SELECT b.id, b.name, b.year, b.version, b.status, b.is_active,
                    (SELECT COALESCE(SUM(annual_total), 0)
                       FROM acc_budget_lines
                      WHERE budget_id = b.id) AS total_budget
             FROM acc_budgets b
             ORDER BY b.year DESC, b.is_active DESC"
        );
    }

    // ════════════════════════════════════════════════════════════
    //  TAX TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getTaxFilingPeriods
    //
    // GST/HST/PST filing periods with totals and filing status.
    // ────────────────────────────────────────────────────────────
    private static function getTaxFilingPeriods(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $taxType = trim($input['tax_type'] ?? '');
        $status  = trim($input['status'] ?? '');
        $limit   = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($taxType !== '') { $where[] = 'tax_type = ?'; $params[] = $taxType; }
        if ($status !== '')  { $where[] = 'status = ?';   $params[] = $status; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT id, tax_type, period_start, period_end, filing_due_date,
                    frequency, total_sales, total_tax_collected, total_itc,
                    net_tax_owing, status, filed_date
             FROM acc_tax_filing_periods
             WHERE {$whereSql}
             ORDER BY period_end DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  PERIOD TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getAccountingPeriods
    //
    // List accounting periods with open/closed status for a year.
    // ────────────────────────────────────────────────────────────
    private static function getAccountingPeriods(array $input): array
    {
        $year  = (int) ($input['year'] ?? date('Y'));
        $limit = ToolRegistry::MAX_ROWS;

        return db_select(
            "SELECT id, year, month, name, start_date, end_date,
                    status, is_year_end, closed_at, locked_at
             FROM acc_periods
             WHERE year = ?
             ORDER BY month ASC
             LIMIT {$limit}",
            [$year]
        );
    }

    // ════════════════════════════════════════════════════════════
    //  COLLECTIONS TOOLS  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getPromiseToPay
    //
    // List promise-to-pay records with optional filters.
    // ────────────────────────────────────────────────────────────
    private static function getPromiseToPay(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $status     = trim($input['status'] ?? '');
        $customerId = (int) ($input['customer_id'] ?? 0);
        $limit      = ToolRegistry::MAX_ROWS;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '')  { $where[] = 'p.status = ?';      $params[] = $status; }
        if ($customerId > 0) { $where[] = 'p.customer_id = ?'; $params[] = $customerId; }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT p.id, p.customer_id, c.company_name AS customer_name,
                    p.invoice_id, p.promised_amount, p.promise_date,
                    p.promised_by, p.status, p.actual_payment_date, p.notes
             FROM acc_promise_to_pay p
             LEFT JOIN customers c ON c.id = p.customer_id
             WHERE {$whereSql}
             ORDER BY p.promise_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ────────────────────────────────────────────────────────────
    // getCollectionNotes
    //
    // Collection notes for a customer.
    // ────────────────────────────────────────────────────────────
    private static function getCollectionNotes(array $input): array|string
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        if ($customerId <= 0) return 'Error: customer_id is required.';

        $limit = ToolRegistry::MAX_ROWS;

        return db_select(
            "SELECT id, customer_id, invoice_id, note_date, contact_method,
                    contact_person, note, outcome, follow_up_date
             FROM acc_collection_notes
             WHERE customer_id = ?
             ORDER BY note_date DESC
             LIMIT {$limit}",
            [$customerId]
        );
    }

    // ════════════════════════════════════════════════════════════
    //  PAYMENT TOOLS — additional  (S028)
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // getRecentPayments
    //
    // Recent payments, optionally filtered by customer.
    // ────────────────────────────────────────────────────────────
    private static function getRecentPayments(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial data.';
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $limit      = ToolRegistry::MAX_ROWS;

        $where  = ['p.deleted_at IS NULL'];
        $params = [];

        if ($customerId > 0) {
            $where[]  = 'p.customer_id = ?';
            $params[] = $customerId;
        }

        $whereSql = implode(' AND ', $where);

        return db_select(
            "SELECT p.id, p.payment_number, p.customer_id,
                    c.company_name AS customer_name,
                    p.amount, p.currency, p.payment_method, p.payment_date,
                    p.status, p.reference_number
             FROM payments p
             LEFT JOIN customers c ON c.id = p.customer_id
             WHERE {$whereSql}
             ORDER BY p.payment_date DESC
             LIMIT {$limit}",
            $params
        );
    }

    // ════════════════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════════════════

    // ────────────────────────────────────────────────────────────
    // canViewFinancials()
    //
    // Returns true if the user has payments:view permission.
    // Null userId (system/cron) always gets access.
    // ────────────────────────────────────────────────────────────
    private static function canViewFinancials(?int $userId): bool
    {
        // WHY: System/cron calls (no user) always have full access
        if ($userId === null) return true;

        // WHY: can() reads from session — works when a user is logged in.
        // Leading backslash forces global namespace resolution — without it,
        // PHP tries FleetForge\AI\Tools\can() first and can fail in edge
        // cases (opcache, autoloader interference, partial bootstrap).
        return \can('payments', 'view');
    }

    // ────────────────────────────────────────────────────────────
    // stripFinancials()
    //
    // Removes dollar-amount fields from result rows when the
    // user lacks payments:view permission. Preserves dates,
    // statuses, and non-financial fields.
    //
    // @param  array    $rows    Query results
    // @param  int|null $userId  Current user
    // @return array             Filtered rows
    // ────────────────────────────────────────────────────────────
    private static function stripFinancials(array $rows, ?int $userId): array
    {
        if (self::canViewFinancials($userId)) {
            return $rows;
        }

        // WHY: These keys contain dollar amounts that dispatchers shouldn't see
        $financialKeys = [
            'total_amount', 'amount_paid', 'balance_due',
            'monthly_rate', 'daily_rate', 'weekly_rate',
            'outstanding_balance', 'total_invoiced', 'total_paid',
            'credit_limit', 'total_revenue', 'account_credit_balance',
            'subtotal', 'tax_total', 'discount_amount',
        ];

        return array_map(function (array $row) use ($financialKeys): array {
            foreach ($financialKeys as $key) {
                unset($row[$key]);
            }
            return $row;
        }, $rows);
    }

    // ════════════════════════════════════════════════════════════
    //  PHASE B REPORTING TOOLS (S036)
    //  Thin wrappers over FleetForge\Accounting\ReportingService and
    //  BudgetService. All four require `journal_entries.view`; the
    //  data returned is summary-shaped (no PII, no line-level
    //  drill-down) and safe for the AI to interpret.
    // ════════════════════════════════════════════════════════════

    private static function getProfitAndLoss(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial reports.';
        }
        $from = (string) ($input['from'] ?? date('Y-01-01'));
        $to   = (string) ($input['to']   ?? date('Y-m-d'));
        $report = \FleetForge\Accounting\ReportingService::profitAndLoss($from, $to);
        // Strip drill-down JE-line ids — large arrays inflate token cost
        foreach (['revenue', 'direct_costs', 'operating_expenses', 'other'] as $g) {
            foreach ($report[$g] as &$row) unset($row['je_line_ids']);
            unset($row);
        }
        return $report;
    }

    private static function getBalanceSheet(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial reports.';
        }
        $asOf = (string) ($input['as_of'] ?? date('Y-m-d'));
        return \FleetForge\Accounting\ReportingService::balanceSheet($asOf);
    }

    private static function getCashFlow(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial reports.';
        }
        $from = (string) ($input['from'] ?? date('Y-01-01'));
        $to   = (string) ($input['to']   ?? date('Y-m-d'));
        return \FleetForge\Accounting\ReportingService::cashFlow($from, $to);
    }

    private static function getBudgetVariance(array $input, ?int $userId): array|string
    {
        if (!self::canViewFinancials($userId)) {
            return 'Access denied: you do not have permission to view financial reports.';
        }
        $budgetId = (int) ($input['budget_id'] ?? 0);
        if ($budgetId <= 0) {
            return 'budget_id is required.';
        }
        $from = (string) ($input['from'] ?? date('Y-01-01'));
        $to   = (string) ($input['to']   ?? date('Y-m-d'));
        try {
            return \FleetForge\Accounting\BudgetService::variance($budgetId, $from, $to);
        } catch (\Throwable $e) {
            return 'Budget not found or unavailable.';
        }
    }

    // ════════════════════════════════════════════════════════════
    //  WRITE PLANNERS (S-AI-WRITE-1 / generalized S-AI-WRITE-2)
    //
    //  These NEVER mutate data. They validate the request via
    //  WriteRegistry, compute the exact old→new diff, and persist a
    //  PENDING row in ai_pending_changes. The actual write happens only
    //  when the user clicks Apply, which hits api/v1/ai/apply-change.php
    //  and re-runs the write through ChangeApplier. Works for EVERY
    //  registered entity (equipment, customers, vendors, yards,
    //  reservations, leases, maintenance, damage claims, rate cards).
    // ════════════════════════════════════════════════════════════

    /** Shared gate: feature flag + authenticated user + entity + edit permission. */
    private static function writeGate(?int $userId, ?array $entry): ?string
    {
        if (!settings_get('ai.write_enabled', false)) {
            return 'AI write actions are currently disabled. An administrator can enable them in Settings → AI.';
        }
        if ($userId === null) {
            return 'Write actions require an authenticated user.';
        }
        if ($entry === null) {
            return 'I can only edit these record types: ' . implode(', ', \FleetForge\AI\WriteRegistry::types()) . '.';
        }
        if (!\can($entry['permission'], 'edit')) {
            return "You don't have permission to edit {$entry['label']}s, so I can't propose this change.";
        }
        return null;
    }

    /** Persist a pending proposal and return the tool result for Claude. */
    private static function persistProposal(array $entry, string $entityType, string $summary, array $targets, ?int $userId, ?int $sessionId): array
    {
        $payload = [
            'table'        => $entry['table'],
            'entity_type'  => $entityType,
            'audit_module' => $entry['audit_module'],
            'targets'      => $targets,
        ];
        $proposalId = db_insert('ai_pending_changes', [
            'user_id'        => $userId,
            'session_id'     => $sessionId ?: null,
            'change_type'    => (count($targets) > 1 ? 'bulk_update_' : 'update_') . $entityType,
            'entity_type'    => $entityType,
            'summary'        => mb_substr($summary, 0, 500),
            'payload'        => json_encode($payload),
            'affected_count' => count($targets),
            'status'         => 'pending',
            'expires_at'     => date('Y-m-d H:i:s', time() + 1800), // 30 min
        ]);

        return [
            'status'                => 'proposed',
            'requires_confirmation' => true,
            'proposal_id'           => $proposalId,
            'summary'               => $summary,
            'affected_count'        => count($targets),
            'note'                  => 'A confirmation card has been shown to the user. Tell them what will change and that they must click Apply to confirm. Do NOT say the change has been made.',
        ];
    }

    /**
     * Propose a single-record field change on any registered entity.
     * Input: entity_type, identifier, field, new_value.
     */
    private static function planUpdateRecord(array $input, ?int $userId, ?int $sessionId): array|string
    {
        $entityType = trim((string) ($input['entity_type'] ?? ''));
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $field      = trim((string) ($input['field'] ?? ''));
        $newValue   = (string) ($input['new_value'] ?? '');

        $entry = \FleetForge\AI\WriteRegistry::get($entityType);
        if ($err = self::writeGate($userId, $entry)) return $err;

        if ($identifier === '' || $field === '') {
            return 'I need the record identifier and the field to change.';
        }

        $res = \FleetForge\AI\WriteRegistry::resolve($entry, $identifier);
        if (isset($res['error'])) return $res['error'];
        if (isset($res['ambiguous'])) {
            $list = implode("\n", array_map(fn($o) => "- {$o['label']} (#{$o['id']})", $res['ambiguous']));
            return "Multiple {$entry['label']}s match \"{$identifier}\". Ask the user which one (then pass its id or exact name):\n{$list}";
        }
        $record = $res['record'];

        $v = \FleetForge\AI\WriteRegistry::validateField($entry, $field, $newValue, $record);
        if (isset($v['error'])) return $v['error'];
        if (!empty($v['noop'])) return $v['message'];

        $recLabel = (string) ($record[$entry['label_column']] ?? ('#' . $record['id']));
        $summary  = "Change {$v['label']} of {$entry['label']} {$recLabel}: {$v['old_display']} → {$v['new_display']}";

        $targets = [[
            'id'           => (int) $record['id'],
            'label'        => $recLabel,
            'field'        => $field,
            'field_label'  => $v['label'],
            'column'       => $v['column'],
            'old_display'  => $v['old_display'],
            'new_display'  => $v['new_display'],
            'new_db_value' => $v['db_value'],
        ]];

        return self::persistProposal($entry, $entityType, $summary, $targets, $userId, $sessionId);
    }

    /**
     * Propose the SAME field change across many records selected by a filter.
     * Input: entity_type, filters (map of column=>value), field, new_value.
     * Honors a per-entity bulk-filter allow-list and a hard row cap.
     */
    private static function planBulkUpdateRecords(array $input, ?int $userId, ?int $sessionId): array|string
    {
        $entityType = trim((string) ($input['entity_type'] ?? ''));
        $field      = trim((string) ($input['field'] ?? ''));
        $newValue   = (string) ($input['new_value'] ?? '');
        $filters    = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        $entry = \FleetForge\AI\WriteRegistry::get($entityType);
        if ($err = self::writeGate($userId, $entry)) return $err;

        $allowed = $entry['bulk_filters'] ?? [];
        if (!$allowed) {
            return "Bulk edits aren't supported for {$entry['label']}s yet. Change them one at a time.";
        }
        if (!$filters) {
            return "I need at least one filter to select which {$entry['label']}s to change. Allowed filters: " . implode(', ', $allowed) . '.';
        }
        foreach (array_keys($filters) as $col) {
            if (!in_array($col, $allowed, true)) {
                return "\"{$col}\" isn't a valid filter for {$entry['label']}s. Allowed: " . implode(', ', $allowed) . '.';
            }
        }
        if ($field === '') return 'I need the field to change.';

        // Build the WHERE from the allow-listed filters.
        $where  = $entry['soft_delete'] ? ['deleted_at IS NULL'] : [];
        $params = [];
        foreach ($filters as $col => $val) {
            $where[]  = "`{$col}` = ?";
            $params[] = $val;
        }
        $whereSql = implode(' AND ', $where);

        $rows = db_select(
            "SELECT * FROM `{$entry['table']}` WHERE {$whereSql} ORDER BY id LIMIT " . (self::BULK_PROBE),
            $params
        );
        if (count($rows) === 0) {
            return "No {$entry['label']}s match those filters, so there's nothing to change.";
        }
        if (count($rows) > \FleetForge\AI\WriteRegistry::BULK_MAX) {
            $n = count($rows);
            return "That selection matches {$n}+ {$entry['label']}s, which exceeds the bulk limit of " . \FleetForge\AI\WriteRegistry::BULK_MAX . ". Narrow the filter and try again.";
        }

        // Validate the field/new_value once (against the first row to derive the
        // normalized db_value); the same value is applied to every match.
        $v = \FleetForge\AI\WriteRegistry::validateField($entry, $field, $newValue, $rows[0]);
        if (isset($v['error'])) return $v['error'];

        $column   = $v['column'];
        $dbValue  = $v['db_value'];
        $targets  = [];
        foreach ($rows as $r) {
            $targets[] = [
                'id'           => (int) $r['id'],
                'label'        => (string) ($r[$entry['label_column']] ?? ('#' . $r['id'])),
                'field'        => $field,
                'field_label'  => $v['label'],
                'column'       => $column,
                'old_display'  => (string) ($r[$column] ?? 'none'),
                'new_display'  => $v['new_display'],
                'new_db_value' => $dbValue,
            ];
        }

        $filterDesc = implode(', ', array_map(fn($k, $vv) => "{$k}={$vv}", array_keys($filters), array_values($filters)));
        $summary = "Set {$v['label']} → {$v['new_display']} for " . count($targets) . " {$entry['label']}(s) where {$filterDesc}";

        return self::persistProposal($entry, $entityType, $summary, $targets, $userId, $sessionId);
    }

    /** Probe limit = BULK_MAX + 1 so we can detect "exceeds cap" cleanly. */
    private const BULK_PROBE = 101;

    /**
     * Propose a LIFECYCLE action (state transition) on a record. Validates the
     * transition via ActionRegistry's preview, then persists a PENDING action
     * proposal. Applied (not undoable) only when the user clicks Apply.
     * Input: action, identifier, new_status, reason.
     */
    private static function planAction(array $input, ?int $userId, ?int $sessionId): array|string
    {
        $action     = trim((string) ($input['action'] ?? ''));
        $identifier = trim((string) ($input['identifier'] ?? ''));

        $entry = \FleetForge\AI\ActionRegistry::get($action);

        // Gate: feature flag + auth + per-action permission.
        if (!settings_get('ai.write_enabled', false)) {
            return 'AI write actions are currently disabled. An administrator can enable them in Settings → AI.';
        }
        if ($userId === null) {
            return 'Write actions require an authenticated user.';
        }
        if ($entry === null) {
            return 'I can only perform these actions: ' . implode(', ', \FleetForge\AI\ActionRegistry::names()) . '.';
        }
        if (!\FleetForge\AI\ActionRegistry::canPerform($entry)) {
            return "You don't have permission to {$entry['label']}.";
        }
        if ($identifier === '') {
            return 'I need the record identifier to act on.';
        }

        $res = \FleetForge\AI\ActionRegistry::resolve($entry, $identifier);
        if (isset($res['error'])) return $res['error'];
        if (isset($res['ambiguous'])) {
            $list = implode("\n", array_map(fn($o) => "- {$o['label']} (#{$o['id']})", $res['ambiguous']));
            return "Multiple records match \"{$identifier}\". Ask the user which one (then pass its id or exact name):\n{$list}";
        }
        $record = $res['record'];

        $params = [
            'new_status' => $input['new_status'] ?? ($input['new_value'] ?? ''),
            'reason'     => $input['reason'] ?? '',
        ];
        $preview = ($entry['preview'])($record, $params);
        if (isset($preview['error'])) return $preview['error'];

        $payload = [
            'kind'         => 'action',
            'action'       => $action,
            'entity_id'    => (int) $record['id'],
            'label'        => (string) ($record[$entry['label_column']] ?? ('#' . $record['id'])),
            'params'       => $preview['params'],
            'undoable'     => false,
        ];
        $proposalId = db_insert('ai_pending_changes', [
            'user_id'        => $userId,
            'session_id'     => $sessionId ?: null,
            'change_type'    => 'action_' . $action,
            'entity_type'    => $entry['entity'],
            'summary'        => mb_substr($preview['summary'], 0, 500),
            'payload'        => json_encode($payload),
            'affected_count' => 1,
            'status'         => 'pending',
            'expires_at'     => date('Y-m-d H:i:s', time() + 1800),
        ]);

        return [
            'status'                => 'proposed',
            'requires_confirmation' => true,
            'proposal_id'           => $proposalId,
            'summary'               => $preview['summary'],
            'affected_count'        => 1,
            'undoable'              => false,
            'note'                  => 'A confirmation card has been shown. Tell the user what will happen and that they must click Apply. This action is not undoable. Do NOT say it is done.',
        ];
    }
}
