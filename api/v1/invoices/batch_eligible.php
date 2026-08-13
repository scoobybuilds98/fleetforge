<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_eligible.php
 *
 * S-BATCH-INVOICING — read-only enumeration for the Batch Invoicing page.
 * Given a billing period (month or custom range) and optional customer
 * filters, returns every customer with at least one monthly, active lease
 * whose life-span overlaps the period, each lease tagged with its billing
 * status for THAT period:
 *   - 'unbilled' — no non-void invoice covers it yet (batch_generate.php
 *                  will create one)
 *   - 'billed'   — a non-void invoice already covers it (existing_invoice
 *                  names the invoice; batch_generate.php will skip it)
 *   - 'void'     — only a VOID invoice covers it (informational —
 *                  re-billable, batch_generate.php WILL generate a new one;
 *                  findOverlappingInvoice() ignores void rows by design)
 *
 * This mirrors the eligibility filter cron/invoice_generate_monthly.php
 * uses (status='active' AND billing_cycle='monthly'), but keyed to an
 * OPERATOR-CHOSEN period instead of each lease's next_billing_date — the
 * cron's own dedupe query (invoices.billing_period_start match) is
 * generalised here via InvoiceGenerator::findOverlappingInvoice() so a
 * custom date range (not just a calendar month) is handled correctly.
 *
 * Also resolves the recipient email FleetForge would use to send each
 * customer's invoice — same precedence EmailService::getCustomerContacts()
 * documents: invoice_email → billing_email → email — plus any delivery
 * warnings (email_disabled, invoice_delivery != 'email', no email at all)
 * so the batch page's "Email Settings" panel can surface them before send.
 *
 * @method  GET
 * @query   period_start (required, Y-m-d), period_end (required, Y-m-d),
 *          customer_ids (optional, comma-separated int list),
 *          search (optional, matches customers.company_name)
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { period, customers: [...], summary: {...} }
 *
 * @depends lib/Billing/InvoiceGenerator.php (findOverlappingInvoice)
 * @session S-BATCH-INVOICING
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\InvoiceGenerator;

// ── Input validation ─────────────────────────────────────────────
$periodStart = clean_date($_GET['period_start'] ?? null);
$periodEnd   = clean_date($_GET['period_end'] ?? null);

$fields = [];
if (!$periodStart) $fields['period_start'] = 'A valid period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'A valid period end date is required.';
if ($periodStart && $periodEnd && $periodEnd < $periodStart) {
    $fields['period_end'] = 'Period end cannot be before period start.';
}
if ($periodStart && $periodEnd && !isset($fields['period_end'])) {
    if ($periodErr = ff_billing_period_error($periodStart, $periodEnd)) {
        $fields['period_start'] = $periodErr;
    }
}
if ($fields) {
    json_validation_error($fields);
}

$isFullCalendarMonth = ($periodStart === date('Y-m-01', strtotime($periodStart)))
                    && ($periodEnd   === date('Y-m-t',   strtotime($periodStart)));

// Optional customer_ids filter: comma-separated positive ints.
$customerIds = [];
if (!empty($_GET['customer_ids'])) {
    foreach (explode(',', (string) $_GET['customer_ids']) as $raw) {
        $id = clean_int(trim($raw));
        if ($id && $id > 0) $customerIds[] = $id;
    }
}

$search = clean_string($_GET['search'] ?? null, 255);

// ── Candidate leases: active + monthly + alive during the period ──
// Mirrors the cron's eligibility gate (status='active' AND
// billing_cycle='monthly') but without the next_billing_date filter —
// the operator picks the period explicitly here. "Alive during the
// period" = the lease started on/before period_end; an active lease has
// no reliable upper bound (memory: leases run past end_date when not
// yet closed), so we don't filter on end_date/actual_return_date — an
// active lease is presumed ongoing.
$sql = "SELECT l.id, l.contract_number, l.customer_id, l.start_date, l.end_date,
               l.billing_cycle, l.status AS lease_status,
               eu.unit_number,
               c.id AS c_id, c.company_name, c.status AS customer_status,
               c.currency, c.email, c.billing_email, c.invoice_email,
               c.invoice_cc_emails, c.invoice_delivery, c.email_disabled
          FROM leases l
          JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
          LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE l.status = 'active'
           AND l.billing_cycle = 'monthly'
           AND l.deleted_at IS NULL
           AND l.start_date <= ?";
$params = [$periodEnd];

if ($customerIds) {
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $sql .= " AND c.id IN ({$placeholders})";
    array_push($params, ...$customerIds);
}
if ($search !== null && $search !== '') {
    $sql .= " AND c.company_name LIKE ?";
    $params[] = '%' . $search . '%';
}
$sql .= " ORDER BY c.company_name ASC, l.contract_number ASC";

$rows = db_select($sql, $params);

// ── Per-lease billing status for the requested period ──────────────
$customersOut = [];
$summary = ['customers_count' => 0, 'leases_count' => 0, 'unbilled_count' => 0, 'billed_count' => 0, 'void_count' => 0];

foreach ($rows as $row) {
    $leaseId = (int) $row['id'];

    $overlap = InvoiceGenerator::findOverlappingInvoice($leaseId, $periodStart, $periodEnd);
    if ($overlap) {
        $billingStatus   = 'billed';
        $existingInvoice = [
            'id'             => (int) $overlap['id'],
            'invoice_number' => (string) $overlap['invoice_number'],
            'status'         => (string) $overlap['status'],
        ];
        $summary['billed_count']++;
    } else {
        // findOverlappingInvoice() ignores void rows by design — check
        // separately, purely for the operator's "was voided" context.
        $voidOverlap = db_row(
            "SELECT id, invoice_number FROM invoices
              WHERE lease_id = ? AND deleted_at IS NULL AND status = 'void'
                AND billing_period_start IS NOT NULL AND billing_period_end IS NOT NULL
                AND billing_period_start <= ? AND billing_period_end >= ?
              ORDER BY billing_period_start DESC, id DESC LIMIT 1",
            [$leaseId, $periodEnd, $periodStart]
        );
        if ($voidOverlap) {
            $billingStatus   = 'void';
            $existingInvoice = [
                'id'             => (int) $voidOverlap['id'],
                'invoice_number' => (string) $voidOverlap['invoice_number'],
                'status'         => 'void',
            ];
            $summary['void_count']++;
        } else {
            $billingStatus   = 'unbilled';
            $existingInvoice = null;
            $summary['unbilled_count']++;
        }
    }
    $summary['leases_count']++;

    $cid = (int) $row['c_id'];
    if (!isset($customersOut[$cid])) {
        // ── Recipient resolution — mirrors EmailService::getCustomerContacts()
        // precedence (invoice_email → billing_email → email).
        $resolvedEmail  = $row['invoice_email'] ?: ($row['billing_email'] ?: $row['email']);
        $resolvedSource = $row['invoice_email'] ? 'invoice_email' : ($row['billing_email'] ? 'billing_email' : ($row['email'] ? 'email' : null));

        $warnings = [];
        if (!$resolvedEmail) {
            $warnings[] = 'No email address on file — batch email will be skipped for this customer.';
        }
        if ((int) $row['email_disabled'] === 1) {
            $warnings[] = 'Email delivery is disabled for this customer (bounced/complained) — sends will be blocked.';
        }
        if ($row['invoice_delivery'] && $row['invoice_delivery'] !== 'email') {
            $warnings[] = "Customer's invoice delivery preference is '" . $row['invoice_delivery'] . "', not email.";
        }

        $ccEmails = [];
        if (!empty($row['invoice_cc_emails'])) {
            $decoded = json_decode((string) $row['invoice_cc_emails'], true);
            if (is_array($decoded)) $ccEmails = array_values(array_filter($decoded));
        }

        $customersOut[$cid] = [
            'id'              => $cid,
            'company_name'    => (string) $row['company_name'],
            'status'          => (string) $row['customer_status'],
            'currency'        => (string) $row['currency'],
            'recipient'       => [
                'email'      => $resolvedEmail,
                'source'     => $resolvedSource,
                'cc_emails'  => $ccEmails,
                'warnings'   => $warnings,
            ],
            'leases'          => [],
        ];
        $summary['customers_count']++;
    }

    $customersOut[$cid]['leases'][] = [
        'id'               => $leaseId,
        'contract_number'  => (string) $row['contract_number'],
        'unit_number'      => $row['unit_number'] !== null ? (string) $row['unit_number'] : null,
        'start_date'       => (string) $row['start_date'],
        'end_date'         => $row['end_date'] !== null ? (string) $row['end_date'] : null,
        'billing_status'   => $billingStatus,
        'existing_invoice' => $existingInvoice,
    ];
}

json_success([
    'period' => [
        'start'                  => $periodStart,
        'end'                    => $periodEnd,
        'is_full_calendar_month' => $isFullCalendarMonth,
    ],
    'customers' => array_values($customersOut),
    'summary'   => $summary,
]);
