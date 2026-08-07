<?php
declare(strict_types=1);

/**
 * cron/customer_reminders.php
 *
 * Dispatcher for the SCHEDULED customer reminder emails defined in
 * config/customer_notifications.php (everything whose 'handler' is
 * 'customer_reminders'): invoice-due-soon, overdue payment reminders, payment
 * receipts, monthly statements, lease-ending and reservation-pickup. Compliance
 * expiry is sent by cron/compliance_alerts.php; this cron never touches it.
 *
 * All gating, audience resolution, dedup and delivery run through
 * FleetForge\Notifications\CustomerReminders so the cron and Settings → Customer
 * Emails can never disagree. Every reminder type ships DISABLED — this cron is a
 * no-op until an operator turns a type on in the module.
 *
 * Three independent switches, all must allow a send:
 *   1. cron.customer_reminders_enabled  — Scheduled Jobs toggle (run at all?)
 *   2. customer_notifications.master_enabled — module master kill switch
 *   3. customer_notifications.<type>.enabled — the individual reminder
 *
 * Schedule: hourly on the server crontab; the body only does work at the
 * configured local send-hour on an allowed weekday (Settings → Customer Emails →
 * Sending window). Set FF_CRON_FORCE=1 to bypass the window for local testing.
 *
 * Crontab (production): 0 * * * * php /var/www/fleetforge/cron/customer_reminders.php
 * Local test:           FF_CRON_FORCE=1 php cron/customer_reminders.php
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 * @see     config/customer_notifications.php, lib/Notifications/CustomerReminders.php
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Notifications\CustomerReminders;

// Testability seam: a smoke can `define('FF_CUSTOMER_REMINDERS_INCLUDE', 1)` and
// require this file to get the cr_* handler functions (hoisted) WITHOUT running
// the cron body. Mirrors cron/notification_digest.php.
if (defined('FF_CUSTOMER_REMINDERS_INCLUDE')) {
    return;
}

// -----------------------------------------------------------------------------
// Switch 1: Scheduled Jobs toggle. S-CRON-TOGGLES.
// -----------------------------------------------------------------------------
if (!cron_enabled('customer_reminders')) {
    error_log('[CRON] customer_reminders disabled - skipping.');
    exit(0);
}

// Switch 2: module master kill switch.
if (!CustomerReminders::masterEnabled()) {
    error_log('[CRON customer_reminders] master switch OFF - skipping.');
    exit(0);
}

// -----------------------------------------------------------------------------
// Send-window gate (local hour + weekday). FF_CRON_FORCE=1 bypasses.
// -----------------------------------------------------------------------------
$forced    = (string) (getenv('FF_CRON_FORCE') ?: '') === '1';
$companyTz = (string) settings_get('company.timezone', 'America/Vancouver');
try {
    $localNow = new DateTime('now', new DateTimeZone($companyTz));
} catch (\Throwable $e) {
    error_log("[CRON customer_reminders] invalid timezone '{$companyTz}', using UTC");
    $localNow = new DateTime('now', new DateTimeZone('UTC'));
}
if (!$forced && !CustomerReminders::inSendWindow($localNow)) {
    // Not the configured hour/day — quiet exit (this fires up to 24×/day).
    exit(0);
}

// -----------------------------------------------------------------------------
// Advisory lock — one dispatcher at a time (mirrors compliance_alerts, D21).
// -----------------------------------------------------------------------------
$lock = db_row("SELECT GET_LOCK('ff_cron_customer_reminders', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    exit(0); // another instance running
}

require_once FF_ROOT . '/lib/Email/templates/customer_reminders.php';

$today       = $localNow->format('Y-m-d');
$companyName = (string) settings_get('company.name', 'FleetForge');
$appUrl      = rtrim((string) settings_get('app.url', ''), '/');

$totals = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

try {
    // Only run types whose handler is this cron, that are enabled, and that are
    // available in this deployment (e.g. reservations table present).
    foreach (CustomerReminders::registry() as $key => $meta) {
        if (($meta['handler'] ?? '') !== 'customer_reminders') {
            continue;
        }
        if (!CustomerReminders::typeEnabled($key) || !CustomerReminders::available($key)) {
            continue;
        }

        $cfg = CustomerReminders::config($key);
        try {
            $r = match ($key) {
                'invoice_due_soon'   => cr_run_invoice_due_soon($cfg, $today, $appUrl, $companyName),
                'invoice_overdue'    => cr_run_invoice_overdue($cfg, $today, $appUrl, $companyName),
                'payment_receipt'    => cr_run_payment_receipt($cfg, $today, $appUrl, $companyName),
                'statement'          => cr_run_statement($cfg, $today, $appUrl, $companyName, $localNow),
                'lease_ending_soon'  => cr_run_lease_ending($cfg, $today, $appUrl, $companyName),
                'reservation_pickup' => cr_run_reservation_pickup($cfg, $today, $appUrl, $companyName),
                default              => ['sent' => 0, 'skipped' => 0, 'errors' => 0],
            };
        } catch (\Throwable $e) {
            \FleetForge\Observability\Sentry::captureException($e);
            error_log("[CRON customer_reminders] type {$key} failed: " . $e->getMessage());
            $r = ['sent' => 0, 'skipped' => 0, 'errors' => 1];
        }
        $totals['sent']    += $r['sent'];
        $totals['skipped'] += $r['skipped'];
        $totals['errors']  += $r['errors'];
    }

    $summary = "customer_reminders completed: {$totals['sent']} sent, {$totals['skipped']} skipped, {$totals['errors']} errors";
    error_log("[CRON] {$summary}");
    db_insert('audit_log', [
        'user_id' => null, 'user_name' => 'system', 'action' => 'cron', 'module' => 'notifications',
        'entity_type' => 'cron', 'entity_id' => null, 'entity_label' => 'customer_reminders',
        'notes' => $summary, 'ip_address' => '127.0.0.1',
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON customer_reminders] Fatal: ' . $e->getMessage());
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_customer_reminders')", []);
}

// =============================================================================
// HELPERS (top-level, hoisted — available to the include seam above)
// =============================================================================

/**
 * cr_resolve_recipient() — pick the best deliverable email + display name +
 * portal prefs from a candidate row that JOINed customers + primary portal user.
 * Preference: active portal user → invoice_email → billing_email → customer email.
 *
 * @return array{email:string, name:string, prefs:?string}
 */
function cr_resolve_recipient(array $row): array
{
    $portalEmail = trim((string) ($row['portal_email'] ?? ''));
    $puDisabled  = (int) ($row['pu_disabled'] ?? 0) === 1;
    $invEmail    = trim((string) ($row['invoice_email'] ?? ''));
    $billEmail   = trim((string) ($row['billing_email'] ?? ''));
    $custEmail   = trim((string) ($row['cust_email'] ?? ''));
    $custDisabled = (int) ($row['cust_disabled'] ?? 0) === 1;

    $email = '';
    if ($portalEmail !== '' && !$puDisabled) {
        $email = $portalEmail;
    } elseif ($invEmail !== '') {
        $email = $invEmail;
    } elseif ($billEmail !== '') {
        $email = $billEmail;
    } elseif ($custEmail !== '' && !$custDisabled) {
        $email = $custEmail;
    }

    $name = trim((string) ($row['portal_name'] ?? ''))
        ?: trim((string) ($row['contact_name'] ?? ''))
        ?: trim((string) ($row['company_name'] ?? ''));

    return ['email' => $email, 'name' => $name, 'prefs' => $row['notification_preferences'] ?? null];
}

/** SELECT fragment: customer + primary-portal-user columns for recipient resolution. */
function cr_recipient_select(string $custAlias = 'c'): string
{
    return "{$custAlias}.company_name, {$custAlias}.contact_name,
            {$custAlias}.email AS cust_email, {$custAlias}.billing_email, {$custAlias}.invoice_email,
            {$custAlias}.email_disabled AS cust_disabled,
            pu.email AS portal_email, pu.name AS portal_name,
            pu.notification_preferences, pu.email_disabled AS pu_disabled";
}

/** Human phrase for the gap between two Y-m-d dates ("today"/"tomorrow"/"in 3 days"). */
function cr_days_phrase(string $today, string $target): string
{
    try {
        $a = new DateTime($today);
        $b = new DateTime($target);
    } catch (\Throwable) {
        return 'soon';
    }
    $diff = (int) $a->diff($b)->format('%r%a');
    return match (true) {
        $diff === 0  => 'today',
        $diff === 1  => 'tomorrow',
        $diff === -1 => 'yesterday',
        $diff > 1    => "in {$diff} days",
        default      => abs($diff) . ' days ago',
    };
}

/** Currency-disambiguated money string, e.g. "$1,234.56 CAD". */
function cr_money(mixed $amount, string $currency): string
{
    return format_currency($amount) . ' ' . strtoupper($currency !== '' ? $currency : 'CAD');
}

/**
 * cr_dispatch() — shared tail: audience check → deliver → status.
 * The caller has already resolved the recipient and passed the dedup gate.
 *
 * @return 'sent'|'skipped'|'error'
 */
function cr_dispatch(
    string $key, array $cfg, array $audience,
    int $customerId, ?string $prefs,
    string $toEmail, string $toName, string $subject, string $bodyHtml,
    string $entityType, ?int $entityId, array $inApp = []
): string {
    // customer_id 0 = no linked customer (e.g. a reservation contact); such rows
    // have no audience/suppression to check, so they proceed to delivery.
    if ($customerId > 0 && !CustomerReminders::customerAllowed($key, $customerId, $prefs, $audience, $cfg)) {
        return 'skipped';
    }
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        return 'skipped';
    }
    $res = CustomerReminders::deliver([
        'reminder_key' => $key,
        'customer_id'  => $customerId,
        'dedup_type'   => $cfg['dedup_type'],
        'entity_type'  => $entityType,
        'entity_id'    => $entityId,
        'channels'     => $cfg['channels'],
        'to_email'     => $toEmail,
        'to_name'      => $toName,
        'subject'      => $subject,
        'body_html'    => $bodyHtml,
        'in_app'       => $inApp,
    ]);
    return !empty($res['email']) ? 'sent' : 'error';
}

/** Resolve the effective subject: operator override (if any) else the default. */
function cr_subject(array $cfg, string $default): string
{
    $override = trim((string) ($cfg['subject'] ?? ''));
    return $override !== '' ? $override : $default;
}

// -----------------------------------------------------------------------------
// invoice_due_soon — one email per invoice, on the exact lead day before due.
// -----------------------------------------------------------------------------
function cr_run_invoice_due_soon(array $cfg, string $today, string $appUrl, string $company): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $audience = CustomerReminders::audienceSets($cfg['key']);
    $target = date('Y-m-d', strtotime($today . ' +' . (int) $cfg['lead_days'] . ' days'));

    $rows = db_select(
        "SELECT i.id AS invoice_id, i.invoice_number, i.due_date, i.total_amount, i.balance_due, i.currency,
                c.id AS customer_id, " . cr_recipient_select('c') . "
           FROM invoices i
           JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
           LEFT JOIN portal_users pu ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE i.deleted_at IS NULL
            AND i.status IN ('sent','partially_paid','overdue')
            AND i.balance_due > 0
            AND i.due_date = ?",
        [$target]
    );

    foreach ($rows as $row) {
        $invId = (int) $row['invoice_id'];
        // One-shot: never twice for the same invoice; also rate-limit retries.
        if (CustomerReminders::sentCount($cfg['dedup_type'], 'invoice', $invId) > 0
            || CustomerReminders::recentlyLogged($cfg['dedup_type'], 'invoice', $invId, '20 HOUR')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        $phrase = cr_days_phrase($today, (string) $row['due_date']);
        $body = render_customer_invoice_due_soon([
            'customer_name'   => (string) $row['company_name'],
            'company_name'    => $company,
            'invoice_number'  => (string) $row['invoice_number'],
            'due_date'        => format_date($row['due_date']),
            'amount'          => cr_money($row['balance_due'], (string) $row['currency']),
            'days_until_text' => $phrase,
            'portal_url'      => $appUrl . '/portal/invoices',
        ]);
        $subject = cr_subject($cfg, 'Invoice ' . $row['invoice_number'] . ' is due ' . $phrase);
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, (int) $row['customer_id'], $rcpt['prefs'],
            $rcpt['email'], $rcpt['name'], $subject, $body, 'invoice', $invId,
            ['type' => 'invoice.due', 'title' => $subject, 'message' => 'An invoice is due ' . $phrase . '.', 'url' => '/portal/invoices', 'severity' => 'info']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// invoice_overdue — cadence: first at offset_days past due, then every
// repeat_days, capped at max_count, per invoice.
// -----------------------------------------------------------------------------
function cr_run_invoice_overdue(array $cfg, string $today, string $appUrl, string $company): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $audience = CustomerReminders::audienceSets($cfg['key']);
    $offset = max(0, (int) $cfg['offset_days']);
    $repeat = max(1, (int) $cfg['repeat_days']);
    $maxCount = max(1, (int) $cfg['max_count']);
    $cutoff = date('Y-m-d', strtotime($today . ' -' . $offset . ' days')); // due on/before this = eligible

    $rows = db_select(
        "SELECT i.id AS invoice_id, i.invoice_number, i.due_date, i.balance_due, i.currency,
                DATEDIFF(?, i.due_date) AS days_overdue,
                c.id AS customer_id, " . cr_recipient_select('c') . "
           FROM invoices i
           JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
           LEFT JOIN portal_users pu ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE i.deleted_at IS NULL
            AND i.status IN ('sent','partially_paid','overdue')
            AND i.balance_due > 0
            AND i.due_date <= ?",
        [$today, $cutoff]
    );

    foreach ($rows as $row) {
        $invId = (int) $row['invoice_id'];
        $daysOverdue = (int) $row['days_overdue'];
        // Cap reached, or one already went out within the repeat window → skip.
        if (CustomerReminders::sentCount($cfg['dedup_type'], 'invoice', $invId) >= $maxCount) {
            $out['skipped']++;
            continue;
        }
        if (CustomerReminders::recentlyLogged($cfg['dedup_type'], 'invoice', $invId, $repeat . ' DAY')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        $body = render_customer_invoice_overdue([
            'customer_name'  => (string) $row['company_name'],
            'company_name'   => $company,
            'invoice_number' => (string) $row['invoice_number'],
            'due_date'       => format_date($row['due_date']),
            'amount'         => cr_money($row['balance_due'], (string) $row['currency']),
            'days_overdue'   => $daysOverdue,
            'portal_url'     => $appUrl . '/portal/invoices',
        ]);
        $subject = cr_subject($cfg, 'Payment reminder: invoice ' . $row['invoice_number'] . ' is overdue');
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, (int) $row['customer_id'], $rcpt['prefs'],
            $rcpt['email'], $rcpt['name'], $subject, $body, 'invoice', $invId,
            ['type' => 'invoice.overdue', 'title' => $subject, 'message' => 'An invoice on your account is overdue.', 'url' => '/portal/invoices', 'severity' => 'warning']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// payment_receipt — one receipt per recently-recorded payment.
// -----------------------------------------------------------------------------
function cr_run_payment_receipt(array $cfg, string $today, string $appUrl, string $company): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $audience = CustomerReminders::audienceSets($cfg['key']);

    $rows = db_select(
        "SELECT p.id AS payment_id, p.payment_number, p.amount, p.currency, p.payment_date, p.payment_method,
                (SELECT i2.invoice_number FROM payment_allocations pa
                   JOIN invoices i2 ON i2.id = pa.invoice_id AND i2.deleted_at IS NULL
                  WHERE pa.payment_id = p.id ORDER BY pa.id DESC LIMIT 1) AS applied_invoice,
                c.id AS customer_id, " . cr_recipient_select('c') . "
           FROM payments p
           JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
           LEFT JOIN portal_users pu ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE p.deleted_at IS NULL
            AND p.status NOT IN ('failed','void','returned')
            AND p.created_at >= NOW() - INTERVAL 3 DAY",
        []
    );

    foreach ($rows as $row) {
        $payId = (int) $row['payment_id'];
        if (CustomerReminders::sentCount($cfg['dedup_type'], 'payment', $payId) > 0
            || CustomerReminders::recentlyLogged($cfg['dedup_type'], 'payment', $payId, '20 HOUR')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        $method = ucwords(str_replace('_', ' ', (string) $row['payment_method']));
        $body = render_customer_payment_receipt([
            'customer_name'  => (string) $row['company_name'],
            'company_name'   => $company,
            'payment_number' => (string) $row['payment_number'],
            'payment_date'   => format_date($row['payment_date']),
            'amount'         => cr_money($row['amount'], (string) $row['currency']),
            'method'         => $method,
            'applied_to'     => (string) ($row['applied_invoice'] ?? ''),
            'portal_url'     => $appUrl . '/portal/invoices',
        ]);
        $subject = cr_subject($cfg, 'Payment received — receipt ' . $row['payment_number']);
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, (int) $row['customer_id'], $rcpt['prefs'],
            $rcpt['email'], $rcpt['name'], $subject, $body, 'payment', $payId,
            ['type' => 'payment.received', 'title' => $subject, 'message' => 'We received your payment. Thank you.', 'url' => '/portal/invoices', 'severity' => 'info']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// statement — monthly account summary, on send_day, per customer with a balance.
// -----------------------------------------------------------------------------
function cr_run_statement(array $cfg, string $today, string $appUrl, string $company, DateTimeInterface $localNow): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    // Only on the configured day-of-month.
    if ((int) $localNow->format('j') !== (int) $cfg['send_day']) {
        return $out;
    }
    $audience = CustomerReminders::audienceSets($cfg['key']);

    $rows = db_select(
        "SELECT c.id AS customer_id, c.currency, ob.tot AS balance_total, " . cr_recipient_select('c') . "
           FROM customers c
           JOIN (SELECT customer_id, SUM(balance_due) AS tot
                   FROM invoices
                  WHERE status IN ('sent','partially_paid','overdue') AND balance_due > 0 AND deleted_at IS NULL
                  GROUP BY customer_id) ob ON ob.customer_id = c.id
           LEFT JOIN portal_users pu ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE c.deleted_at IS NULL
            AND c.status <> 'inactive'
            AND ob.tot > 0",
        []
    );

    foreach ($rows as $row) {
        $cid = (int) $row['customer_id'];
        // Once per ~month per customer.
        if (CustomerReminders::recentlyLogged($cfg['dedup_type'], 'customer', $cid, '25 DAY')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        $currency = (string) ($row['currency'] ?: 'CAD');

        $open = db_select(
            "SELECT invoice_number, invoice_date, due_date, balance_due
               FROM invoices
              WHERE customer_id = ? AND status IN ('sent','partially_paid','overdue')
                AND balance_due > 0 AND deleted_at IS NULL
              ORDER BY due_date ASC",
            [$cid]
        );
        $invRows = [];
        foreach ($open as $o) {
            $invRows[] = [
                'invoice_number' => (string) $o['invoice_number'],
                'due_date'       => format_date($o['due_date']),
                'balance'        => cr_money($o['balance_due'], $currency),
            ];
        }
        $body = render_customer_statement([
            'customer_name' => (string) $row['company_name'],
            'company_name'  => $company,
            'balance_total' => cr_money($row['balance_total'], $currency),
            'invoices'      => $invRows,
            'portal_url'    => $appUrl . '/portal/invoices',
        ]);
        $subject = cr_subject($cfg, 'Your account statement — ' . $localNow->format('F Y'));
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, $cid, $rcpt['prefs'],
            $rcpt['email'], $rcpt['name'], $subject, $body, 'customer', $cid,
            ['type' => 'statement', 'title' => $subject, 'message' => 'Your monthly account statement is ready.', 'url' => '/portal/invoices', 'severity' => 'info']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// lease_ending_soon — one email per active lease, on the exact lead day.
// -----------------------------------------------------------------------------
function cr_run_lease_ending(array $cfg, string $today, string $appUrl, string $company): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $audience = CustomerReminders::audienceSets($cfg['key']);
    $target = date('Y-m-d', strtotime($today . ' +' . (int) $cfg['lead_days'] . ' days'));

    $rows = db_select(
        "SELECT l.id AS lease_id, l.contract_number, l.end_date, eu.unit_number,
                c.id AS customer_id, " . cr_recipient_select('c') . "
           FROM leases l
           JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
           LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
           LEFT JOIN portal_users pu ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE l.deleted_at IS NULL AND l.status = 'active' AND l.end_date = ?",
        [$target]
    );

    foreach ($rows as $row) {
        $leaseId = (int) $row['lease_id'];
        if (CustomerReminders::sentCount($cfg['dedup_type'], 'lease', $leaseId) > 0
            || CustomerReminders::recentlyLogged($cfg['dedup_type'], 'lease', $leaseId, '20 HOUR')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        $phrase = cr_days_phrase($today, (string) $row['end_date']);
        $body = render_customer_lease_ending([
            'customer_name'   => (string) $row['company_name'],
            'company_name'    => $company,
            'contract_number' => (string) $row['contract_number'],
            'unit_number'     => (string) ($row['unit_number'] ?? ''),
            'end_date'        => format_date($row['end_date']),
            'days_until_text' => $phrase,
            'portal_url'      => $appUrl . '/portal/leases',
        ]);
        $subject = cr_subject($cfg, 'Your rental ' . $row['contract_number'] . ' ends ' . $phrase);
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, (int) $row['customer_id'], $rcpt['prefs'],
            $rcpt['email'], $rcpt['name'], $subject, $body, 'lease', $leaseId,
            ['type' => 'lease.ending', 'title' => $subject, 'message' => 'A rental agreement is ending ' . $phrase . '.', 'url' => '/portal/leases', 'severity' => 'info']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}

// -----------------------------------------------------------------------------
// reservation_pickup — one email per reservation, on the exact lead day.
// customer_id may be NULL (walk-in style); then the reservation's own
// contact_email is used and there is no audience/suppression to apply.
// -----------------------------------------------------------------------------
function cr_run_reservation_pickup(array $cfg, string $today, string $appUrl, string $company): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => 0];
    $audience = CustomerReminders::audienceSets($cfg['key']);
    $target = date('Y-m-d', strtotime($today . ' +' . (int) $cfg['lead_days'] . ' days'));

    $rows = db_select(
        "SELECT r.id AS reservation_id, r.pickup_date, r.pickup_time, r.quantity,
                r.contact_email AS res_email, r.contact_name AS res_name, r.company_name AS res_company,
                c.id AS customer_id, " . cr_recipient_select('c') . "
           FROM reservations r
           LEFT JOIN customers c ON c.id = r.customer_id AND c.deleted_at IS NULL
           LEFT JOIN portal_users pu ON pu.customer_id = r.customer_id AND pu.is_primary = 1 AND pu.status = 'active'
          WHERE r.deleted_at IS NULL AND r.status IN ('pending','confirmed') AND r.pickup_date = ?",
        [$target]
    );

    foreach ($rows as $row) {
        $resId = (int) $row['reservation_id'];
        if (CustomerReminders::sentCount($cfg['dedup_type'], 'reservation', $resId) > 0
            || CustomerReminders::recentlyLogged($cfg['dedup_type'], 'reservation', $resId, '20 HOUR')) {
            $out['skipped']++;
            continue;
        }
        $rcpt = cr_resolve_recipient($row);
        // Fall back to the reservation's own contact when no customer is linked.
        $email = $rcpt['email'] !== '' ? $rcpt['email'] : trim((string) ($row['res_email'] ?? ''));
        $name  = $rcpt['name']  !== '' ? $rcpt['name']  : trim((string) ($row['res_name'] ?? ($row['res_company'] ?? '')));
        $phrase = cr_days_phrase($today, (string) $row['pickup_date']);
        $body = render_customer_reservation_pickup([
            'customer_name'   => $name,
            'company_name'    => $company,
            'pickup_date'     => format_date($row['pickup_date']),
            'pickup_time'     => (string) ($row['pickup_time'] ?? ''),
            'quantity'        => (string) ($row['quantity'] ?? ''),
            'days_until_text' => $phrase,
            'portal_url'      => $appUrl . '/portal',
        ]);
        $subject = cr_subject($cfg, 'Reminder: your equipment pickup is ' . $phrase);
        $status = cr_dispatch(
            $cfg['key'], $cfg, $audience, (int) ($row['customer_id'] ?? 0), $rcpt['prefs'],
            $email, $name, $subject, $body, 'reservation', $resId,
            ['type' => 'reservation.pickup', 'title' => $subject, 'message' => 'Your reservation pickup is ' . $phrase . '.', 'url' => '/portal', 'severity' => 'info']
        );
        $out[$status === 'sent' ? 'sent' : ($status === 'error' ? 'errors' : 'skipped')]++;
    }
    return $out;
}
