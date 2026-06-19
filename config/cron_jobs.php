<?php
declare(strict_types=1);

/**
 * config/cron_jobs.php
 *
 * Single source of truth for the operator-toggleable BUSINESS-AUTOMATION crons
 * surfaced in Settings → Intelligence → Scheduled Jobs. Each entry drives three
 * things that must agree: (1) the cron_enabled() gate at the top of the cron
 * script, (2) the seed migration that creates the cron.<name>_enabled setting,
 * and (3) the toggle card in the settings UI.
 *
 * Infrastructure crons (DB/Dropbox/storage backups, QBO sync/token-refresh,
 * cache cleanup, accounting period/FX/reversal jobs, notification digest) are
 * DELIBERATELY NOT listed — they must not be switchable from a settings screen.
 *
 * `default` is the seeded value for fresh installs ('1' = on). The monthly
 * invoice generator ships OFF ('0') per the operator request — turn it back on
 * from the Scheduled Jobs panel when billing should resume.
 *
 * @session S-CRON-TOGGLES
 */

return [
    'invoice_generate_monthly' => [
        'label' => 'Monthly invoice generation', 'category' => 'Billing', 'default' => '0',
        'description' => 'Generates full-month draft invoices for due monthly leases.',
    ],
    'invoice_overdue' => [
        'label' => 'Mark overdue invoices', 'category' => 'Billing', 'default' => '1',
        'description' => 'Flags past-due invoices as overdue.',
    ],
    'late_fee_apply' => [
        'label' => 'Apply late fees', 'category' => 'Billing', 'default' => '1',
        'description' => 'Adds configured late fees to overdue invoices.',
    ],
    'accounting_recurring_entries' => [
        'label' => 'Recurring journal entries', 'category' => 'Billing', 'default' => '1',
        'description' => 'Posts scheduled recurring accounting entries.',
    ],
    'collections_auto_escalate' => [
        'label' => 'Collections auto-escalation', 'category' => 'Collections', 'default' => '1',
        'description' => 'Escalates aging collections accounts.',
    ],
    'promise_to_pay_check' => [
        'label' => 'Promise-to-pay checks', 'category' => 'Collections', 'default' => '1',
        'description' => 'Reviews broken promise-to-pay arrangements.',
    ],
    'compliance_alerts' => [
        'label' => 'Compliance expiry alerts', 'category' => 'Compliance', 'default' => '1',
        'description' => 'Alerts on expiring CVI / registration / MVI / insurance.',
    ],
    'stale_reservations' => [
        'label' => 'Stale reservation cleanup', 'category' => 'Reservations', 'default' => '1',
        'description' => 'Expires abandoned/stale reservations.',
    ],
    'health_scores' => [
        'label' => 'Customer health scores', 'category' => 'Analytics', 'default' => '1',
        'description' => 'Recomputes customer health scores.',
    ],
    'risk_scores' => [
        'label' => 'Customer risk scores', 'category' => 'Analytics', 'default' => '1',
        'description' => 'Recomputes customer risk scores.',
    ],
    'gps_mileage_sync' => [
        'label' => 'GPS mileage sync', 'category' => 'Telematics', 'default' => '1',
        'description' => 'Syncs GPS odometer / mileage readings.',
    ],
    'samsara_sync' => [
        'label' => 'Samsara telematics sync', 'category' => 'Telematics', 'default' => '1',
        'description' => 'Pulls Samsara vehicle / trailer telematics.',
    ],
];
