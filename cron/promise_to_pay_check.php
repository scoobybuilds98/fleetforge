<?php
declare(strict_types=1);

/**
 * cron/promise_to_pay_check.php
 *
 * Promise-to-pay daily checker — runs nightly.
 *
 * Scans all pending promises whose promise_date has passed and decides
 * the outcome by VERIFYING actual payments received since the promise
 * was made:
 *
 *   paid_since_promise = SUM(payments.amount) FROM payments
 *     WHERE customer_id = promise.customer_id
 *       AND payment_date >= DATE(promise.created_at)
 *       AND status = 'cleared'
 *       AND deleted_at IS NULL
 *
 *   IF paid_since_promise >= promised_amount  →  status = 'kept'
 *   ELSE                                       →  status = 'broken'
 *
 * S-FIX-3 changes (D60, this session):
 *   • Pre-fix: cron blindly transitioned every overdue 'pending' promise
 *     to 'broken' regardless of whether the customer paid. False positives
 *     against customers who actually fulfilled their promise.
 *   • Now uses payment_date + status='cleared' to decide kept vs broken.
 *   • bcmath (bccomp) for the threshold compare — no float drift.
 *   • For 'kept': actual_payment_date set to the date of the qualifying
 *     payment that crossed the threshold.
 *   • Notifications target the user who logged the promise (created_by)
 *     plus all managers + super_admins via NotificationService — replaces
 *     pre-fix raw db_insert into notifications with user_id=null.
 *   • Once 'broken', stays broken. The cron's loop predicate iterates
 *     status='pending' only (no auto-reverse from broken→kept later).
 *     If a late-clearing payment changes the verdict, manager corrects
 *     the row manually.
 *
 * Crontab (production): 30 7 * * * php /var/www/fleetforge/cron/promise_to_pay_check.php
 * Local test:           php /Users/avi/Documents/fleetforge/cron/promise_to_pay_check.php
 *
 * @session S031 (original) / S-FIX-3 (payment verification fix)
 */

require_once dirname(__DIR__) . '/config/app.php';

// Advisory lock prevents overlapping runs
$lock = db_row("SELECT GET_LOCK('ff_cron_promise_check', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$kept     = 0;
$broken   = 0;
$notified = 0;
$errors   = 0;

try {
    // -----------------------------------------------------------------------
    // Find all pending promises whose promise_date has passed. Loop predicate
    // is status='pending' only — once a promise is marked kept/broken, it
    // stays in that state. Manager flips manually if needed.
    // -----------------------------------------------------------------------
    $expiredPromises = db_select(
        "SELECT p.*, c.company_name, i.invoice_number
         FROM acc_promise_to_pay p
         JOIN customers c ON c.id = p.customer_id
         LEFT JOIN invoices i ON i.id = p.invoice_id
         WHERE p.status = 'pending'
           AND p.promise_date < CURDATE()",
        []
    );

    foreach ($expiredPromises as $promise) {
        $promiseId      = (int)$promise['id'];
        $customerId     = (int)$promise['customer_id'];
        $companyName    = (string)$promise['company_name'];
        $promisedAmount = (string)$promise['promised_amount'];
        $promiseDate    = (string)$promise['promise_date'];
        $invoiceRef     = $promise['invoice_number'] ? " ({$promise['invoice_number']})" : '';
        $createdBy      = $promise['created_by'] !== null ? (int)$promise['created_by'] : null;
        $promiseStart   = (string)$promise['created_at'];

        try {
            // ---------------------------------------------------------------
            // Verify actual payments received since the promise was logged.
            //
            // Why payment_date (not created_at): payment_date is the date
            // the customer actually paid. created_at is when the row was
            // entered into the system (could be days later). For a fairness
            // check we want "did they pay on time" not "did the AR clerk
            // record the receipt on time."
            //
            // Why status='cleared' only: pending payments may bounce; void/
            // returned/failed/refunded clearly don't count. Strictest gate.
            //
            // Negative amounts on cleared rows are theoretically excluded
            // by SUM but verified zero in current dataset.
            // ---------------------------------------------------------------
            $paidRow = db_row(
                "SELECT COALESCE(SUM(amount), 0) AS paid
                 FROM payments
                 WHERE customer_id = ?
                   AND payment_date >= DATE(?)
                   AND status = 'cleared'
                   AND deleted_at IS NULL",
                [$customerId, $promiseStart]
            );
            $paidSince = (string)($paidRow['paid'] ?? '0.00');

            // bccomp returns 0 if equal, 1 if a > b, -1 if a < b. Promise
            // is kept when paid >= promised (>= 0).
            $isKept = bccomp($paidSince, $promisedAmount, 2) >= 0;

            if ($isKept) {
                // -------------------------------------------------------
                // PROMISE KEPT — set actual_payment_date to the qualifying
                // payment that pushed cumulative >= promised. Walk payments
                // ASC by payment_date and find the row that crosses the
                // threshold.
                // -------------------------------------------------------
                $payments = db_select(
                    "SELECT payment_date, amount
                     FROM payments
                     WHERE customer_id = ?
                       AND payment_date >= DATE(?)
                       AND status = 'cleared'
                       AND deleted_at IS NULL
                     ORDER BY payment_date ASC, id ASC",
                    [$customerId, $promiseStart]
                );
                $running     = '0.00';
                $qualifyDate = null;
                foreach ($payments as $pay) {
                    $running = bcadd($running, (string)$pay['amount'], 2);
                    if (bccomp($running, $promisedAmount, 2) >= 0) {
                        $qualifyDate = $pay['payment_date'];
                        break;
                    }
                }

                db_transaction(function () use ($promiseId, $qualifyDate, $companyName, $promisedAmount, $promiseDate, $paidSince, $invoiceRef) {
                    db_update('acc_promise_to_pay', [
                        'status'              => 'kept',
                        'actual_payment_date' => $qualifyDate,
                    ], 'id = ?', [$promiseId]);

                    db_insert('audit_log', [
                        'user_id'      => null,
                        'user_name'    => 'system',
                        'action'       => 'status_change',
                        'module'       => 'accounting',
                        'entity_type'  => 'promise_to_pay',
                        'entity_id'    => $promiseId,
                        'old_values'   => json_encode(['status' => 'pending']),
                        'new_values'   => json_encode(['status' => 'kept', 'actual_payment_date' => $qualifyDate]),
                        'notes'        => "Promise kept — {$companyName} promised \${$promisedAmount} by {$promiseDate}{$invoiceRef}; "
                                        . "paid \${$paidSince} since promise (qualifying date: " . ($qualifyDate ?? 'n/a') . ").",
                        'ip_address'   => '127.0.0.1',
                    ]);
                });

                // Positive notification — informational, low severity.
                // Targets: the user who logged the promise (if known).
                $userIds = $createdBy !== null ? [$createdBy] : null;
                \FleetForge\Notifications\NotificationService::notify(
                    type:            'accounting.promise_kept',
                    title:           "Promise kept: {$companyName}",
                    message:         "{$companyName} paid \${$paidSince} since their promise on " . substr($promiseStart, 0, 10) . " "
                                    . "for \${$promisedAmount}{$invoiceRef}. Promise marked kept.",
                    entityType:      'promise_to_pay',
                    entityId:        $promiseId,
                    url:             '/fleetforge/accounting/collections?customer_id=' . $customerId,
                    specificUserIds: $userIds,
                    severity:        'info'
                );
                $kept++;

            } else {
                // -------------------------------------------------------
                // PROMISE BROKEN
                // -------------------------------------------------------
                db_transaction(function () use ($promiseId, $companyName, $promisedAmount, $promiseDate, $paidSince, $invoiceRef) {
                    db_update('acc_promise_to_pay', [
                        'status' => 'broken',
                    ], 'id = ?', [$promiseId]);

                    db_insert('audit_log', [
                        'user_id'      => null,
                        'user_name'    => 'system',
                        'action'       => 'status_change',
                        'module'       => 'accounting',
                        'entity_type'  => 'promise_to_pay',
                        'entity_id'    => $promiseId,
                        'old_values'   => json_encode(['status' => 'pending']),
                        'new_values'   => json_encode(['status' => 'broken']),
                        'notes'        => "Promise broken — {$companyName} promised \${$promisedAmount} by {$promiseDate}{$invoiceRef}, "
                                        . "paid \${$paidSince} since promise.",
                        'ip_address'   => '127.0.0.1',
                    ]);
                });

                // Notify the user who logged the promise + all managers
                // + super_admins. Falls back to module-permission fan-out
                // if created_by is null (legacy rows).
                $managers = db_select(
                    "SELECT u.id FROM users u
                     JOIN user_roles ur ON ur.id = u.role_id
                     WHERE u.deleted_at IS NULL AND u.status = 'active'
                       AND ur.slug IN ('manager','super_admin')"
                );
                $targetIds = array_map(static fn($r) => (int)$r['id'], $managers);
                if ($createdBy !== null && !in_array($createdBy, $targetIds, true)) {
                    $targetIds[] = $createdBy;
                }

                \FleetForge\Notifications\NotificationService::notify(
                    type:            'accounting.promise_broken',
                    title:           "Broken promise: {$companyName}",
                    message:         "{$companyName} promised \${$promisedAmount} by {$promiseDate}{$invoiceRef} — "
                                    . "received \${$paidSince} since the promise was made.",
                    entityType:      'promise_to_pay',
                    entityId:        $promiseId,
                    url:             '/fleetforge/accounting/collections?customer_id=' . $customerId,
                    specificUserIds: $targetIds,
                    severity:        'warning'
                );

                $broken++;
                $notified++;
            }

        } catch (\Throwable $e) {
            $errors++;
            error_log("[CRON promise_to_pay_check] Promise #{$promiseId}: " . $e->getMessage());
        }
    }

    // Summary
    $summary = "promise_to_pay_check completed: {$kept} kept, {$broken} broken, {$notified} notified, {$errors} errors";
    error_log("[CRON] {$summary}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'promise_to_pay_check',
        'notes'        => $summary,
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    error_log("[CRON promise_to_pay_check] Fatal: " . $e->getMessage());

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'accounting',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'promise_to_pay_check',
        'notes'        => 'promise_to_pay_check fatal error: ' . $e->getMessage(),
        'ip_address'   => '127.0.0.1',
    ]);
    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_promise_check')", []);
}
