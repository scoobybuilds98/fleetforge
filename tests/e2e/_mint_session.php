<?php
// tests/e2e/_mint_session.php
//
// E2E auth helper: mint an authenticated admin session for the persistent dev
// test user (.claude/dev_credentials.json, user_id=22) via the app's OWN
// auth_login() — NO password is entered. Prints the session id on stdout so the
// Playwright global-setup can set it as the `ff_session` cookie (httponly is fine
// — Playwright addCookies/storageState can set httponly cookies).
//
// The session file lands in PHP's default save_path, which the `php -S` dev
// server (same Herd php binary) reads from. K-1/K-19 dev-credential pattern.
//
// Usage:  php tests/e2e/_mint_session.php   ->   <session_id>

require_once __DIR__ . '/../../config/app.php';      // defines FF_ROOT + session ini (name=ff_session)
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';          // auto-starts the session

$user = db_row(
    "SELECT u.*, r.slug AS role_slug
     FROM users u JOIN user_roles r ON r.id = u.role_id
     WHERE u.id = 22 AND u.deleted_at IS NULL"
);
if (!$user) { fwrite(STDERR, "dev test user (id=22) not found / deleted\n"); exit(1); }

auth_login($user);
echo session_id() . "\n";
session_write_close();
