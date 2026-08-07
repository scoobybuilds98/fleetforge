<?php
declare(strict_types=1);

/**
 * api/v1/admin/customer_notifications/test_send.php
 *
 * Send a SAMPLE of a given reminder type to the signed-in admin's own email so
 * an operator can preview exactly what a customer would receive. Uses synthetic
 * placeholder data only — it never reads or emails a real customer, and it is
 * not gated by the type's enabled/audience settings (it's a preview).
 *
 * POST JSON: { reminder_key: <key> }
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Notifications\CustomerReminders;
use FleetForge\Notifications\Mailer;
use FleetForge\Email\EmailService;

require_method('POST');
require_auth_api();
require_permission('settings_customer_notifications', 'edit');

require_once FF_ROOT . '/lib/Email/templates/customer_reminders.php';
require_once FF_ROOT . '/lib/Email/templates/customer_compliance_expiring.php';

$body = json_body();
$key  = (string) ($body['reminder_key'] ?? '');
$meta = CustomerReminders::meta($key);
if ($meta === null) {
    json_error('VALIDATION_ERROR', 'Unknown reminder_key.', 422);
}

$user = current_user();
$toEmail = (string) ($user['email'] ?? '');
$toName  = (string) ($user['name'] ?? 'Admin');
if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
    json_error('VALIDATION_ERROR', 'Your account has no valid email address to send the test to.', 422);
}

$company = (string) settings_get('company.name', 'FleetForge');
$appUrl  = rtrim((string) settings_get('app.url', ''), '/');
$cfg     = CustomerReminders::config($key);

// Build a representative sample body + default subject per type.
$sampleName = $toName ?: 'Sample Customer';
$portal     = $appUrl . '/portal';

switch ($key) {
    case 'compliance_expiry':
        $bodyHtml = render_customer_compliance_email([
            'customer_name' => $sampleName,
            'company_name'  => $company,
            'portal_url'    => $portal . '/documents',
            'units'         => [[
                'unit_number' => 'SAMPLE-01', 'unit_id' => 0, 'contract_number' => 'C-0000',
                'docs' => [
                    ['name' => 'Insurance',    'expiry' => date('Y-m-d', strtotime('+10 days'))],
                    ['name' => 'Registration', 'expiry' => date('Y-m-d', strtotime('+25 days'))],
                ],
            ]],
        ]);
        $defSubject = '[Sample] Compliance documents expiring — 1 unit';
        break;

    case 'invoice_due_soon':
        $bodyHtml = render_customer_invoice_due_soon([
            'customer_name' => $sampleName, 'company_name' => $company,
            'invoice_number' => 'INV-SAMPLE', 'due_date' => format_date(date('Y-m-d', strtotime('+3 days'))),
            'amount' => '$1,250.00 CAD', 'days_until_text' => 'in 3 days', 'portal_url' => $portal . '/invoices',
        ]);
        $defSubject = '[Sample] Invoice INV-SAMPLE is due in 3 days';
        break;

    case 'invoice_overdue':
        $bodyHtml = render_customer_invoice_overdue([
            'customer_name' => $sampleName, 'company_name' => $company,
            'invoice_number' => 'INV-SAMPLE', 'due_date' => format_date(date('Y-m-d', strtotime('-8 days'))),
            'amount' => '$1,250.00 CAD', 'days_overdue' => 8, 'portal_url' => $portal . '/invoices',
        ]);
        $defSubject = '[Sample] Payment reminder: invoice INV-SAMPLE is overdue';
        break;

    case 'payment_receipt':
        $bodyHtml = render_customer_payment_receipt([
            'customer_name' => $sampleName, 'company_name' => $company,
            'payment_number' => 'PMT-SAMPLE', 'payment_date' => format_date(date('Y-m-d')),
            'amount' => '$1,250.00 CAD', 'method' => 'E Transfer', 'applied_to' => 'INV-SAMPLE',
            'portal_url' => $portal . '/invoices',
        ]);
        $defSubject = '[Sample] Payment received — receipt PMT-SAMPLE';
        break;

    case 'statement':
        $bodyHtml = render_customer_statement([
            'customer_name' => $sampleName, 'company_name' => $company,
            'balance_total' => '$3,000.00 CAD', 'portal_url' => $portal . '/invoices',
            'invoices' => [
                ['invoice_number' => 'INV-1001', 'due_date' => format_date(date('Y-m-d', strtotime('-5 days'))),  'balance' => '$1,750.00 CAD'],
                ['invoice_number' => 'INV-1002', 'due_date' => format_date(date('Y-m-d', strtotime('+10 days'))), 'balance' => '$1,250.00 CAD'],
            ],
        ]);
        $defSubject = '[Sample] Your account statement — ' . date('F Y');
        break;

    case 'lease_ending_soon':
        $bodyHtml = render_customer_lease_ending([
            'customer_name' => $sampleName, 'company_name' => $company,
            'contract_number' => 'C-SAMPLE', 'unit_number' => 'SAMPLE-01',
            'end_date' => format_date(date('Y-m-d', strtotime('+7 days'))), 'days_until_text' => 'in 7 days',
            'portal_url' => $portal . '/leases',
        ]);
        $defSubject = '[Sample] Your rental C-SAMPLE ends in 7 days';
        break;

    case 'reservation_pickup':
        $bodyHtml = render_customer_reservation_pickup([
            'customer_name' => $sampleName, 'company_name' => $company,
            'pickup_date' => format_date(date('Y-m-d', strtotime('+1 day'))), 'pickup_time' => '9:00 AM',
            'quantity' => '2', 'days_until_text' => 'tomorrow', 'portal_url' => $portal,
        ]);
        $defSubject = '[Sample] Reminder: your equipment pickup is tomorrow';
        break;

    default:
        json_error('VALIDATION_ERROR', 'No sample available for this reminder.', 422);
}

// Honor a configured subject override, prefixed so the inbox shows it's a test.
$subject = trim((string) $cfg['subject']) !== '' ? '[Sample] ' . $cfg['subject'] : $defSubject;

$sent = Mailer::send(
    toEmail:  $toEmail,
    toName:   $toName,
    subject:  $subject,
    htmlBody: EmailService::renderEmailHtml($bodyHtml),
    replyTo:  (CustomerReminders::replyTo() !== '' ? [['email' => CustomerReminders::replyTo(), 'name' => '']] : []),
);

if (!$sent) {
    json_error('SEND_FAILED', 'Could not send the test email. Check email settings / logs/mail.log (dev mode logs instead of sending).', 500);
}

json_success(['sent_to' => $toEmail, 'reminder_key' => $key, 'subject' => $subject]);
