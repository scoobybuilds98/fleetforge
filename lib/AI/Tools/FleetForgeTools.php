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
    public static function run(string $toolName, array $input, ?int $userId = null): mixed
    {
        return match ($toolName) {
            // Customer tools
            'search_customers'       => self::searchCustomers($input),
            'get_customer_details'   => self::getCustomerDetails($input),
            'get_customer_leases'    => self::getCustomerLeases($input),
            'get_customer_invoices'  => self::getCustomerInvoices($input, $userId),

            // Fleet / Equipment tools
            'get_fleet_summary'      => self::getFleetSummary(),
            'get_equipment_unit'     => self::getEquipmentUnit($input),
            'search_equipment'       => self::searchEquipment($input),

            // Lease tools
            'get_active_leases'      => self::getActiveLeases($input, $userId),
            'get_lease_details'      => self::getLeaseDetails($input, $userId),

            // Financial tools
            'get_revenue_by_period'    => self::getRevenueByPeriod($input, $userId),
            'get_revenue_by_customer'  => self::getRevenueByCustomer($input, $userId),
            'get_overdue_invoices'     => self::getOverdueInvoices($userId),
            'get_ar_aging'             => self::getArAging($userId),
            'get_payment_summary'      => self::getPaymentSummary($input, $userId),

            // Compliance tools
            'get_expiring_documents'   => self::getExpiringDocuments($input),

            // Maintenance tools
            'get_maintenance_summary'  => self::getMaintenanceSummary($input),

            // Dashboard tools
            'get_dashboard_kpis'       => self::getDashboardKpis($userId),

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

        // WHY: can() reads from session — works when a user is logged in
        return can('payments', 'view');
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
}
