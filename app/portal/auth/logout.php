<?php
declare(strict_types=1);

/**
 * app/portal/auth/logout.php
 *
 * Portal logout — clears portal session keys only.
 * Does NOT destroy admin session if also logged in.
 */

require_once dirname(__DIR__) . '/includes/auth.php';

portal_logout();

header('Location: ' . base_url('portal/auth/login'));
exit;
