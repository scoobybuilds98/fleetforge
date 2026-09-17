<?php
declare(strict_types=1);

// ============================================================
// scripts/walkthrough/mint_portal_session.php
//
// Mints an authenticated CUSTOMER PORTAL session for the training-video
// recorder, so the "Customer Portal" chapter can show the customer's view
// without typing a portal password on camera (and without storing one in the
// walkthrough scripts). Prints the session id; the chapter swaps it in as the
// `ff_session` cookie via page.context().addCookies().
//
// Portal auth shares the admin session cookie name (`ff_session`) but keeps its
// user under a separate key (`ff_portal_user`, see app/portal/includes/auth.php).
// This script builds exactly the session shape portal_login() builds, with the
// same eligibility checks the portal login form enforces (portal user active,
// customer active/pending/credit_hold).
//
// WHY not call portal_login() directly: it also stamps portal_users.last_login_at
// / last_login_ip. That timestamp is shown in Users → Portal Users, so every
// recording would make the demo customer look like they "just logged in" and
// would leave a DB write behind. Minting the session is read-only.
// If portal_login()'s session shape changes, mirror it here.
//
// DEV ONLY. Refuses to run unless APP_ENV=development.
//
// Usage:  php scripts/walkthrough/mint_portal_session.php [portal_user_id]
//         (default 1000027 = Kyle Thompson, primary contact at Summit Carriers Ltd.)
// ============================================================

require_once __DIR__ . '/../../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/app/portal/includes/auth.php'; // starts the session (CLI) + portal helpers

// Hard gate: never mint customer sessions outside the dev box.
if (APP_ENV !== 'development') {
    fwrite(STDERR, "mint_portal_session.php refuses to run outside APP_ENV=development\n");
    exit(1);
}

$portalUserId = (int) ($argv[1] ?? 1000027);

// Same eligibility rules as app/portal/auth/login.php + portal_status_revoked().
$user = db_row(
    "SELECT pu.id, pu.customer_id, pu.name, pu.email, pu.is_primary, pu.status,
            c.company_name, c.status AS customer_status
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
     WHERE pu.id = ?",
    [$portalUserId]
);
if (!$user || $user['status'] !== 'active') {
    fwrite(STDERR, "portal user {$portalUserId} not found / not active\n");
    exit(1);
}
if (!in_array($user['customer_status'], ['active', 'pending', 'credit_hold'], true)) {
    fwrite(STDERR, "customer for portal user {$portalUserId} is not active\n");
    exit(1);
}

// Fresh session id (mirrors portal_login()'s fixation guard).
session_regenerate_id(true);

// Session shape copied from portal_login() — minus its last_login DB update (see WHY above).
$_SESSION['ff_portal_user'] = [
    'id'           => (int) $user['id'],
    'customer_id'  => (int) $user['customer_id'],
    'name'         => $user['name'],
    'email'        => $user['email'],
    'is_primary'   => (bool) ($user['is_primary'] ?? false),
    'company_name' => $user['company_name'] ?? '',
];
$_SESSION['ff_portal_last_activity'] = time();
portal_csrf_token(); // portal forms expect a CSRF token in the session

echo session_id() . "\n";
session_write_close();
