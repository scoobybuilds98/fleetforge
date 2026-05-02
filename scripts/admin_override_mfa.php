#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * scripts/admin_override_mfa.php
 *
 * Emergency CLI tool to disable MFA for a user who has lost access to
 * both their authenticator app and all backup codes.
 *
 * Usage:
 *   php scripts/admin_override_mfa.php --user-email=someone@example.com --action=disable
 *
 * Requires interactive "yes" confirmation. Writes to audit_log with actor='cli'.
 *
 * @session S-PROD-1A
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ── Parse arguments ───────────────────────────────────────────────────────────
$opts = getopt('', ['user-email:', 'action:']);

$email  = $opts['user-email'] ?? '';
$action = $opts['action'] ?? '';

if ($email === '' || $action === '') {
    fwrite(STDERR, "Usage: php scripts/admin_override_mfa.php --user-email=X --action=disable\n");
    exit(1);
}

if ($action !== 'disable') {
    fwrite(STDERR, "Unknown action '{$action}'. Only 'disable' is supported.\n");
    exit(1);
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Auth\MfaService;

// ── Look up user ──────────────────────────────────────────────────────────────
$user = db_row(
    "SELECT id, name, email, mfa_enabled FROM users WHERE email = ? AND deleted_at IS NULL",
    [$email]
);

if (!$user) {
    fwrite(STDERR, "No active user found with email: {$email}\n");
    exit(1);
}

if (!(int) $user['mfa_enabled']) {
    echo "User {$user['name']} <{$user['email']}> does not have MFA enabled. Nothing to do.\n";
    exit(0);
}

// ── Confirm ───────────────────────────────────────────────────────────────────
echo "\n";
echo "=== FleetForge MFA Emergency Override ===\n\n";
echo "User:   {$user['name']} <{$user['email']}> (ID: {$user['id']})\n";
echo "Action: Disable MFA\n\n";
echo "This will remove MFA from the user's account. They will be required\n";
echo "to re-enroll if their role requires it.\n\n";
echo "Type 'yes' to confirm: ";

$confirm = trim(fgets(STDIN));

if ($confirm !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

// ── Execute ───────────────────────────────────────────────────────────────────
MfaService::disableMfa($user['id'], 0, 'cli');

echo "\nMFA disabled for {$user['email']}. Audit log entry written.\n";
echo "If their role requires MFA, they will be prompted to re-enroll on next login.\n";
exit(0);
