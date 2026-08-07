<?php
declare(strict_types=1);

/**
 * config/customer_notifications.php
 *
 * Single source of truth for the CUSTOMER-FACING email / reminder types
 * surfaced in Settings → Customer Emails. This is the customer analogue of
 * config/cron_jobs.php: one registry that must agree with three things —
 *   (1) the CustomerReminders engine gate that reads each type's settings,
 *   (2) the seed migration that creates the customer_notifications.* rows, and
 *   (3) the per-reminder cards rendered in the settings UI.
 *
 * WHY a registry with fail-safe defaults (mirrors cron_enabled()):
 * every reader resolves a type's config through CustomerReminders::config(),
 * which falls back to the `default` here whenever the settings row is absent.
 * That makes the DEFAULTS the contract — a fresh install (or a deploy that
 * lands before the seed migration) behaves exactly as declared here, with no
 * silent "table missing → send anyway" surprise for customer-facing email.
 *
 * SAFETY POSTURE — everything ships OFF ('default_enabled' => '0'):
 * these are emails to CUSTOMERS. Turning one on is a deliberate operator act
 * in the Customer Emails panel, never a side effect of deploying this file.
 * `compliance_expiry` ships OFF too — that is Task 1 (stop customer compliance
 * emails); the per-document toggles below only matter once it is re-enabled.
 *
 * TIMING kinds:
 *   'before'    — fires `lead_days` BEFORE a target date (renewal-style).
 *   'after'     — fires `offset_days` AFTER a target date, optionally repeating
 *                 every `repeat_days` up to `max_count` (dunning-style).
 *   'event'     — fires off a recent-activity scan (e.g. a payment landed).
 *   'scheduled' — fires on a fixed calendar slot (`send_day` of the month).
 *
 * AUDIENCE modes (per type, resolved by CustomerReminders + the audience table
 * customer_notification_audience):
 *   'all'        — every eligible customer.
 *   'selected'   — ONLY customers with an 'include' row for this type.
 *   'all_except' — every eligible customer EXCEPT those with an 'exclude' row.
 * A '*' reminder_key row with mode='exclude' is the GLOBAL do-not-email
 * suppression list, applied on top of every type.
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 * @see     lib/Notifications/CustomerReminders.php  (engine + gates)
 * @see     cron/customer_reminders.php              (scheduled senders)
 * @see     cron/compliance_alerts.php               (compliance sender)
 * @see     app/admin/settings/customer_notifications.php (UI)
 */

return [

    // =====================================================================
    // COMPLIANCE
    // =====================================================================
    'compliance_expiry' => [
        'label'           => 'Compliance document expiry',
        'category'        => 'Compliance',
        'description'     => 'Warns customers when compliance documents on their leased equipment are expiring or expired.',
        'timing'          => 'before',
        'default_enabled' => '0',          // Task 1: customer compliance emails OFF.
        'default_lead_days' => 30,
        'default_channels'  => ['email'],
        'supports_docs'   => true,         // per-document toggles (see 'docs' below)
        'docs'            => [             // slug => default-on when the type is enabled
            'cvi'          => true,
            'registration' => true,
            'mvi'          => true,
            'insurance'    => true,
        ],
        'dedup_type'      => 'customer_compliance_alert',
        'entity'          => 'customer',
        'template_slug'   => 'customer_compliance_expiring',
        'handler'         => 'compliance_alerts',   // sent by cron/compliance_alerts.php
    ],

    // =====================================================================
    // BILLING
    // =====================================================================
    'invoice_due_soon' => [
        'label'           => 'Invoice due soon',
        'category'        => 'Billing',
        'description'     => 'Reminds customers a few days before an invoice falls due.',
        'timing'          => 'before',
        'default_enabled' => '0',
        'default_lead_days' => 3,
        'default_channels'  => ['email'],
        'dedup_type'      => 'customer_invoice_due',
        'entity'          => 'invoice',
        'template_slug'   => 'customer_invoice_due_soon',
        'handler'         => 'customer_reminders',
    ],

    'invoice_overdue' => [
        'label'           => 'Overdue payment reminder',
        'category'        => 'Billing',
        'description'     => 'Chases unpaid invoices after the due date, repeating on a cadence up to a cap.',
        'timing'          => 'after',
        'default_enabled' => '0',
        'default_offset_days' => 1,        // first nudge 1 day past due
        'default_repeat_days' => 7,        // then weekly
        'default_max_count'   => 4,        // at most 4 reminders per invoice
        'default_channels'    => ['email'],
        'dedup_type'      => 'customer_invoice_overdue',
        'entity'          => 'invoice',
        'template_slug'   => 'customer_invoice_overdue',
        'handler'         => 'customer_reminders',
    ],

    'payment_receipt' => [
        'label'           => 'Payment receipt',
        'category'        => 'Billing',
        'description'     => 'Thanks customers and confirms details when a payment is recorded.',
        'timing'          => 'event',
        'default_enabled' => '0',
        'default_channels'  => ['email'],
        'dedup_type'      => 'customer_payment_receipt',
        'entity'          => 'payment',
        'template_slug'   => 'customer_payment_receipt',
        'handler'         => 'customer_reminders',
    ],

    'statement' => [
        'label'           => 'Monthly account statement',
        'category'        => 'Billing',
        'description'     => 'Emails an account summary (open invoices + balance) on a set day each month.',
        'timing'          => 'scheduled',
        'default_enabled' => '0',
        'default_send_day' => 1,           // day-of-month
        'default_channels' => ['email'],
        'only_with_balance' => true,       // skip customers who owe nothing
        'dedup_type'      => 'customer_statement',
        'entity'          => 'customer',
        'template_slug'   => 'customer_statement',
        'handler'         => 'customer_reminders',
    ],

    // =====================================================================
    // LEASE & RESERVATIONS
    // =====================================================================
    'lease_ending_soon' => [
        'label'           => 'Lease ending soon',
        'category'        => 'Lease & Reservations',
        'description'     => 'Reminds customers before an active lease reaches its scheduled end date.',
        'timing'          => 'before',
        'default_enabled' => '0',
        'default_lead_days' => 7,
        'default_channels'  => ['email'],
        'dedup_type'      => 'customer_lease_ending',
        'entity'          => 'lease',
        'template_slug'   => 'customer_lease_ending',
        'handler'         => 'customer_reminders',
    ],

    // Reservations model a single pickup event (reservations.pickup_date); the
    // schema has NO reservation return/end date column, so there is no
    // "reservation return" reminder — a returning unit is an ACTIVE LEASE and is
    // covered by lease_ending_soon above. Do not re-add a reservation_return
    // type without first adding a return-date column to the reservations table.
    'reservation_pickup' => [
        'label'           => 'Reservation pickup reminder',
        'category'        => 'Lease & Reservations',
        'description'     => 'Reminds customers ahead of a reserved equipment pickup date.',
        'timing'          => 'before',
        'default_enabled' => '0',
        'default_lead_days' => 1,
        'default_channels'  => ['email'],
        'dedup_type'      => 'customer_reservation_pickup',
        'entity'          => 'reservation',
        'template_slug'   => 'customer_reservation_pickup',
        'handler'         => 'customer_reminders',
        'requires_table'  => 'reservations',   // hidden if the table is absent
    ],
];
