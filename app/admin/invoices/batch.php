<?php
declare(strict_types=1);

/**
 * app/admin/invoices/batch.php — MOVED (S-BILLING-MODULE).
 *
 * Batch Invoicing now lives in the Billing module as the cycle workbench
 * (app/admin/billing/run.php). This stub keeps old links, bookmarks and the
 * training walkthroughs working by redirecting with the query string intact.
 * Nothing else lives here — do not add features to this path.
 *
 * @session S-BILLING-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . base_url('billing/run') . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
