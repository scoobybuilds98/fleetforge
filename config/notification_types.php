<?php
declare(strict_types=1);

/**
 * config/notification_types.php
 *
 * Where every notification type goes (S-ATTENTION-INBOX). Read by
 * lib/Attention/NotificationRouter.php, which NotificationService::notify()
 * consults before writing anything.
 *
 * Keys are notification types exactly as passed to notify(), or a prefix
 * ending in ".*" (the longest matching prefix wins). A type with no entry is
 * a plain update.
 *
 * Rule fields:
 *   attention => kind key (lib/Attention/Kinds). The event raises / re-checks
 *                that kind's item instead of writing a row per person. If the
 *                kind's database check says there's no problem after all
 *                (e.g. "risk HIGH" for a customer with nothing overdue), the
 *                event falls back to being an update, so nothing is lost.
 *   entity    => how to get the kind's entity id from the event:
 *                self (default) — the event's entity_id
 *                system         — 0 (one system-wide item, e.g. QuickBooks)
 *                invoice_customer / payment_customer / promise_customer —
 *                look up the customer of that invoice / payment / promise
 *                sweep          — re-check the whole kind (events that
 *                                 cover several records at once)
 *   refresh   => [[kind, entity-resolver], …] — for updates that FIX
 *                something: re-check that item so it closes immediately
 *                (a payment closes the customer's overdue item).
 *   group     => ['title' => '{n} invoices created', 'url' => …] — a burst
 *                of this update collapses into one unread row per person.
 *
 * Required by: lib/Attention/NotificationRouter.php
 *
 * @session S-ATTENTION-INBOX
 */

return [
    // ── Needs attention ──────────────────────────────────────────────────
    'customer.credit_application_submitted' => ['attention' => 'credit_application'],
    'service_request.*'                     => ['attention' => 'customer_request'],
    'compliance.*'                          => ['attention' => 'compliance'],
    'invoice.overdue'                       => ['attention' => 'customer_account', 'entity' => 'invoice_customer'],
    'customer.risk_high'                    => ['attention' => 'customer_account'],
    'accounting.collection_status_change'   => ['attention' => 'customer_account'],
    'accounting.collections_90day'          => ['attention' => 'customer_account'],
    'accounting.promise_broken'             => ['attention' => 'customer_account', 'entity' => 'promise_customer'],
    'lease.reopened'                        => ['attention' => 'lease_reopened'],
    'damage.created'                        => ['attention' => 'damage_claim'],
    'email.bounce_auto_disabled'            => ['attention' => 'email_bounce'],
    'accounting.tax_filing_due'             => ['attention' => 'tax_filing'],
    'invoice.billing_cycle'                 => ['attention' => 'billing_cycle'],
    'invoice.batch_run_submitted'           => ['attention' => 'batch_run_approval'],
    'equipment.health_red'                  => ['attention' => 'unit_health'],
    'samsara.battery_critical'              => ['attention' => 'gps_battery', 'entity' => 'sweep'],
    'samsara.battery_low'                   => ['attention' => 'gps_battery', 'entity' => 'sweep'],
    'quickbooks.sync_paused'                => ['attention' => 'qbo_connection', 'entity' => 'system'],
    'qbo_token_expiry_warning'              => ['attention' => 'qbo_connection', 'entity' => 'system'],
    'quickbooks.push_failed'                => ['attention' => 'qbo_failed', 'entity' => 'system'],
    'quickbooks.drift'                      => ['attention' => 'qbo_drift', 'entity' => 'system'],
    'accounting.reconciliation_drift'       => ['attention' => 'counter_drift', 'entity' => 'system'],

    // ── Updates that also fix an item ────────────────────────────────────
    'invoice.paid'            => ['refresh' => [['customer_account', 'invoice_customer']], 'group' => ['title' => '{n} invoices paid', 'url' => '/fleetforge/invoices']],
    'invoice.partially_paid'  => ['refresh' => [['customer_account', 'invoice_customer']], 'group' => ['title' => '{n} invoices part-paid', 'url' => '/fleetforge/invoices']],
    'invoice.voided'          => ['refresh' => [['customer_account', 'invoice_customer']], 'group' => ['title' => '{n} invoices voided', 'url' => '/fleetforge/invoices']],
    'payment.received'        => ['refresh' => [['customer_account', 'payment_customer']], 'group' => ['title' => '{n} payments received', 'url' => '/fleetforge/payments']],
    'payment.reversed'        => ['refresh' => [['customer_account', 'payment_customer']]],
    'accounting.promise_kept' => ['refresh' => [['customer_account', 'promise_customer']]],
    'lease.closed'            => ['refresh' => [['lease_reopened', 'self']], 'group' => ['title' => '{n} leases closed', 'url' => '/fleetforge/leases']],
    'lease.cancelled'         => ['refresh' => [['lease_reopened', 'self']]],
    'damage.updated'          => ['refresh' => [['damage_claim', 'self']]],
    'invoice.batch_run_approved' => ['refresh' => [['batch_run_approval', 'self']]],
    'invoice.batch_run_rejected' => ['refresh' => [['batch_run_approval', 'self']]],
    'invoice.billing_cycle_closed' => ['refresh' => [['billing_cycle', 'self']]],

    // ── Plain updates, grouped when they come in bursts ──────────────────
    'invoice.created'         => ['group' => ['title' => '{n} invoices created', 'url' => '/fleetforge/invoices']],
    'invoice.sent'            => ['group' => ['title' => '{n} invoices sent', 'url' => '/fleetforge/invoices']],
    'lease.created'           => ['group' => ['title' => '{n} leases created', 'url' => '/fleetforge/leases']],
    'lease.activated'         => ['group' => ['title' => '{n} leases activated', 'url' => '/fleetforge/leases']],
    'customer.created'        => ['group' => ['title' => '{n} new customers', 'url' => '/fleetforge/customers']],
    'equipment.created'       => ['group' => ['title' => '{n} units added', 'url' => '/fleetforge/equipment']],
    'reservation.created'     => ['group' => ['title' => '{n} reservations created', 'url' => '/fleetforge/reservations']],
    'accounting.dunning_sent' => ['group' => ['title' => '{n} dunning letters sent', 'url' => '/fleetforge/accounting/collections']],
];
