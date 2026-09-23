<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/cutover_link.php
 *
 * Go-live linking of FF invoices / credit notes / bills to documents QuickBooks
 * already has (the first year was invoiced in QuickBooks). See
 * lib/QboPushers/InvoiceLinker.php for the rules.
 *
 * GET  ?kind=invoice|credit_memo|bill&from=&to=&include_drafts=0|1&window_days=&party_id=&limit=
 *      → { rows, summary, truncated } — unlinked FF documents with a
 *        proposed QuickBooks match. Reads QuickBooks (one query per customer).
 * POST { action, kind, ... }
 *      link              items: [{ ff_id, qbo_id, method, accept_difference }] (≤ 200)
 *      unlink            ff_id — undo a go-live link (not a real push)
 *      push_new          ff_id — QuickBooks really lacks this pre-go-live
 *                        document: allow it to push as NEW, and queue it
 *      import_payments   ff_id — mirror QuickBooks payments of one linked
 *                        invoice (kind=bill: its QuickBooks bill payments)
 *      catch_up_payments limit, after_id — every open linked/pushed invoice
 *                        (kind=bill: bill) paid down in QuickBooks gets its
 *                        payments mirrored
 *
 * @auth GET quickbooks.view; POST quickbooks.force_full_resync (accounting
 *       decisions: they create FF payments and decide what reaches QuickBooks)
 * @session S-QBO-GOLIVE-AUDIT; bills' payments S-QBO-BILLPAY-MIRROR
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QboPushers\InvoiceLinker;

require_auth_api();

$kind = (string) ($_GET['kind'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    require_permission('quickbooks', 'view');
    if (!in_array($kind, ['invoice', 'credit_memo', 'bill'], true)) {
        json_validation_error(['kind' => "Must be 'invoice', 'credit_memo' or 'bill'."]);
    }
    if ((string) settings_get('quickbooks.connection_status', '') !== 'connected') {
        json_error('NOT_CONNECTED', 'Connect QuickBooks first (QuickBooks → Settings).', 409);
    }
    try {
        json_success(InvoiceLinker::candidates($kind, [
            'from'           => $_GET['from'] ?? null,
            'to'             => $_GET['to'] ?? null,
            'include_drafts' => !empty($_GET['include_drafts']),
            'window_days'    => isset($_GET['window_days']) ? (int) $_GET['window_days'] : InvoiceLinker::DEFAULT_WINDOW_DAYS,
            'party_id'       => isset($_GET['party_id']) ? (int) $_GET['party_id'] : null,
            'limit'          => isset($_GET['limit']) ? (int) $_GET['limit'] : 200,
        ]));
    } catch (\Throwable $e) {
        error_log('[cutover_link] candidates failed: ' . $e->getMessage());
        json_error('SERVER_ERROR', 'Could not build the match list: ' . $e->getMessage(), 500);
    }
}

require_method('POST');
require_permission('quickbooks', 'force_full_resync');
$body   = json_body();
$action = (string) ($body['action'] ?? '');
$kind   = (string) ($body['kind'] ?? 'invoice');
if (!in_array($kind, ['invoice', 'credit_memo', 'bill'], true)) {
    json_validation_error(['kind' => "Must be 'invoice', 'credit_memo' or 'bill'."]);
}
$user = current_user();
$needsQbo = ['link', 'import_payments', 'catch_up_payments'];
if (in_array($action, $needsQbo, true) && (string) settings_get('quickbooks.connection_status', '') !== 'connected') {
    json_error('NOT_CONNECTED', 'Connect QuickBooks first (QuickBooks → Settings).', 409);
}
if ((string) settings_get('quickbooks.realm_mismatch', '0') === '1') {
    json_error('REALM_MISMATCH', 'FleetForge is connected to a different QuickBooks company than its mappings — resolve that on QuickBooks → Settings first.', 409);
}

// Each link reads QuickBooks and may import several payments (~1–3 s on the
// live API), so a batch runs against a time budget well inside nginx's 60 s
// / PHP's limits: items it doesn't reach come back code='deferred' and the
// page re-sends them. Without this a 25-item batch died mid-way on the
// sandbox rehearsal (links committed, response lost).
@set_time_limit(120);
$deadline = microtime(true) + 40.0;

try {
    switch ($action) {
        case 'link':
            $items = $body['items'] ?? null;
            if (!is_array($items) || $items === [] || count($items) > 200) {
                json_validation_error(['items' => 'Send 1–200 items.']);
            }
            $results = [];
            $linked = 0;
            $deferred = 0;
            foreach ($items as $it) {
                $ffId  = (int) ($it['ff_id'] ?? 0);
                $qboId = trim((string) ($it['qbo_id'] ?? ''));
                if (microtime(true) > $deadline) {
                    $results[] = ['ff_id' => $ffId, 'ok' => false, 'code' => 'deferred', 'error' => 'Not processed yet — sent again automatically.'];
                    $deferred++;
                    continue;
                }
                if ($ffId <= 0 || $qboId === '') {
                    $results[] = ['ff_id' => $ffId, 'ok' => false, 'error' => 'ff_id and qbo_id are required.'];
                    continue;
                }
                $r = InvoiceLinker::link($kind, $ffId, $qboId, (string) ($it['method'] ?? 'manual'), $user, !empty($it['accept_difference']));
                $linked += $r['ok'] ? 1 : 0;
                $results[] = ['ff_id' => $ffId] + $r;
            }
            json_success(['linked' => $linked, 'failed' => count($items) - $linked - $deferred, 'deferred' => $deferred, 'results' => $results]);

        case 'unlink':
            $r = InvoiceLinker::unlink($kind, (int) ($body['ff_id'] ?? 0), $user);
            $r['ok'] ? json_success($r) : json_error(strtoupper($r['code'] ?? 'FAILED'), $r['error'] ?? 'Could not unlink.', 422);

        case 'push_new':
            $r = InvoiceLinker::pushAsNew($kind, (int) ($body['ff_id'] ?? 0), $user);
            $r['ok'] ? json_success($r) : json_error(strtoupper($r['code'] ?? 'FAILED'), $r['error'] ?? 'Could not release.', 422);

        case 'import_payments':
            if ($kind === 'credit_memo') {
                json_validation_error(['kind' => 'Payments import is for invoices and bills.']);
            }
            // S-QBO-BILLPAY-MIRROR: bills bring in their QuickBooks bill payments.
            json_success($kind === 'bill'
                ? InvoiceLinker::importBillPayments((int) ($body['ff_id'] ?? 0))
                : InvoiceLinker::importPayments((int) ($body['ff_id'] ?? 0)));

        case 'catch_up_payments':
            // kind=invoice → customer payments; kind=bill → bill payments
            // (S-QBO-BILLPAY-MIRROR). The page sweeps both, one kind at a time.
            $out = $kind === 'bill'
                ? InvoiceLinker::syncOpenBillPayments((int) ($body['limit'] ?? 200), null, (int) ($body['after_id'] ?? 0), $deadline)
                : InvoiceLinker::syncOpenInvoicePayments((int) ($body['limit'] ?? 200), null, (int) ($body['after_id'] ?? 0), $deadline);
            $what = $kind === 'bill' ? 'bill payment' : 'payment';
            db_insert('audit_log', [
                'user_id'     => $user['id'] ?? null,
                'user_name'   => $user['name'] ?? 'system',
                'action'      => 'update',
                'module'      => 'quickbooks',
                'entity_type' => 'qbo_payment_catchup',
                'notes'       => "QuickBooks {$what} catch-up: checked {$out['checked']}, imported {$out['imported']}, unchanged {$out['unchanged']}, errors {$out['errors']}",
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            json_success($out);

        default:
            json_validation_error(['action' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    error_log("[cutover_link] {$action} failed: " . $e->getMessage());
    json_error('SERVER_ERROR', $e->getMessage(), 500);
}
