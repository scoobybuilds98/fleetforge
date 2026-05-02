<?php
declare(strict_types=1);

/**
 * api/v1/account/mfa/setup_init.php
 *
 * Step 1 of MFA setup: generate a TOTP secret, store it in the
 * session (NOT the DB yet), and return the QR code SVG + manual entry key.
 *
 * POST /api/v1/account/mfa/setup_init
 *   → { qr_code_svg: string, manual_secret: string }
 *
 * The secret is held in $_SESSION['ff_mfa_setup'] for up to 10 minutes.
 * Nothing is committed to the users table until setup_verify succeeds.
 *
 * @session S-PROD-1A
 */

require_once __DIR__ . '/../../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

define('FF_API_CONTEXT', true);
require_method('POST');
require_auth_api();

$userId = current_user_id();

use FleetForge\Auth\MfaService;

$plain      = MfaService::generateSecret();
$companyName = settings_get('company.name', 'FleetForge');
$me         = current_user();
$email      = $me['email'] ?? '';

$otpauthUrl = MfaService::generateQrCodeUrl($plain, $email, $companyName);
$qrSvg      = MfaService::generateQrSvg($otpauthUrl);

// Store in session — NOT yet committed to DB
$_SESSION['ff_mfa_setup'] = [
    'secret'     => $plain,
    'started_at' => time(),
];

json_success([
    'qr_code_svg'   => $qrSvg,
    'manual_secret' => $plain,
]);
