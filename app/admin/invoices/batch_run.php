<?php
declare(strict_types=1);

/**
 * app/admin/invoices/batch_run.php — MOVED (S-BILLING-MODULE).
 *
 * An approval run's page now lives in the Billing module
 * (app/admin/billing/approval.php). This stub redirects old links —
 * including the ones stored in earlier "batch run submitted" notifications —
 * with the query string intact. Do not add features to this path.
 *
 * @session S-BILLING-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . base_url('billing/approval') . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
