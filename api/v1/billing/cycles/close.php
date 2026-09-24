<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/close.php
 *
 * S-BILLING-MODULE — close a billing cycle: run the pre-close checks,
 * freeze the summary into close_snapshot, lock the workbench out of the
 * month. Hard checks cannot be overridden; soft checks need
 * override = true AND a note (the reason is kept on the cycle + audit).
 * See lib/Billing/Cycle/CycleClose.php.
 *
 * @method  POST
 * @body    { id, note?, override? }
 * @auth    Session required; invoices:edit
 * @returns 200 { snapshot }   409 CLOSE_REFUSED { checks }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\Billing\Cycle\CycleClose;

$cycle = billing_cycle_from_request();
billing_cycle_require_open($cycle);
$body = json_body();
$note = (string) (clean_string($body['note'] ?? null, 2000) ?? '');

try {
    $snapshot = CycleClose::close($cycle, $note, !empty($body['override']), current_user_id());
} catch (\DomainException $e) {
    json_error('CLOSE_REFUSED', $e->getMessage(), 409, ['checks' => CycleClose::preCloseChecks($cycle)]);
}

try {
    \FleetForge\Notifications\NotificationService::notify(
        type:       'invoice.billing_cycle_closed',
        title:      "Billing closed for {$cycle['label']}",
        message:    "{$cycle['reference']} closed by " . (current_user()['name'] ?? 'a user')
                  . " — {$snapshot['invoices']['live']} invoice(s).",
        entityType: 'billing_cycle',
        entityId:   $cycle['id'],
        url:        '/fleetforge/billing/cycle?id=' . $cycle['id']
    );
} catch (\Throwable $e) {
    error_log('[NOTIF invoice.billing_cycle_closed] ' . $e->getMessage());
}

json_success(['snapshot' => can_view_financials() ? $snapshot : null]);
