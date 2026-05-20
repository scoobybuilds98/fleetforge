<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/index.php
 *
 * Bare-path redirect — /quickbooks → /quickbooks/dashboard. Mirrors
 * the pattern used by /accounting and /equipment (both redirect to
 * a /<module>/dashboard child page rather than render their own
 * landing card). Permission gate at /quickbooks/dashboard catches
 * unauthorized visitors there, so no auth check needed here.
 *
 * Session: S-QBO-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');

header('Location: ' . base_url('quickbooks/dashboard'));
exit;
