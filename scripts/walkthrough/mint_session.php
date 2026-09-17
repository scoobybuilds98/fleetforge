<?php
declare(strict_types=1);

// ============================================================
// scripts/walkthrough/mint_session.php
//
// Mints an authenticated session for the training-video recorder via the
// app's OWN auth_login() — no password is typed on camera and none is stored
// in the walkthrough scripts. Prints the session id; record.mjs sets it as the
// `ff_session` cookie.
//
// DEV ONLY. Refuses to run unless APP_ENV=development, because the recorder
// clicks through real create/edit flows and must never touch production.
//
// Usage:  php scripts/walkthrough/mint_session.php [user_id]   (default 19 = sc8_test super_admin)
// ============================================================

require_once __DIR__ . '/../../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

// Hard gate: the walkthrough mutates data, so dev only.
if (APP_ENV !== 'development') {
    fwrite(STDERR, "mint_session.php refuses to run outside APP_ENV=development\n");
    exit(1);
}

$userId = (int) ($argv[1] ?? 19);

$user = db_row(
    "SELECT u.*, r.slug AS role_slug
     FROM users u JOIN user_roles r ON r.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL AND u.status = 'active'",
    [$userId]
);
if (!$user) {
    fwrite(STDERR, "user {$userId} not found / inactive\n");
    exit(1);
}

auth_login($user);
echo session_id() . "\n";
session_write_close();
