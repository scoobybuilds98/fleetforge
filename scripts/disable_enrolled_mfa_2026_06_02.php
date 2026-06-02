<?php
declare(strict_types=1);

/**
 * scripts/disable_enrolled_mfa_2026_06_02.php
 *
 * One-shot cleanup tied to the 2026-06-02 "MFA still prompts after it was
 * turned off in Settings" incident.
 *
 * Root cause (fixed separately in app/admin/settings/index.php): saving
 * security.mfa.required_roles never propagated to the per-user
 * users.mfa_required column that login.php actually checks, so emptying the
 * role list left super_admins/managers still gated. The companion code fix
 * reconciles users.mfa_required on every settings save; scripts/
 * reconcile_mfa_required.php repairs the already-stale column rows.
 *
 * This script handles the OTHER half — the per-user second factor that a
 * column reconcile deliberately never touches (D-E):
 *
 *   (a) Operator intent "turn MFA off for everyone": disable MFA for every
 *       active user who currently has mfa_enabled = 1 but whose role is NOT
 *       in security.mfa.required_roles. With an empty role list this is every
 *       enrolled user. Their TOTP secret + unused backup codes are cleared;
 *       re-enabling MFA later simply re-enrolls them.
 *
 *   (b) Data-integrity repair: disable MFA for any active user with
 *       mfa_enabled = 1 AND mfa_secret IS NULL. That row can NEVER pass the
 *       TOTP challenge (there is no secret to verify against) = a permanent
 *       lockout. Covered regardless of role.
 *
 * Uses MfaService::disableMfa(), which clears mfa_enabled / mfa_secret /
 * mfa_enabled_at, deletes unused backup codes, and writes a per-user
 * audit_log entry (actor 'cli').
 *
 * Idempotent: a second run finds nothing to do (every target now has
 * mfa_enabled = 0). Dry-run by default.
 *
 * USAGE:
 *   php scripts/disable_enrolled_mfa_2026_06_02.php            (dry-run, no writes)
 *   php scripts/disable_enrolled_mfa_2026_06_02.php --execute  (applies changes)
 */

require_once realpath(__DIR__ . '/../config/app.php');

use FleetForge\Auth\MfaService;

$dryRun = !in_array('--execute', $argv, true);
echo 'Mode: ' . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n\n";

// ── Resolve the current MFA policy ──────────────────────────────────────────
$requiredRolesJson = settings_get('security.mfa.required_roles', '["super_admin","manager"]');
$requiredRoles     = json_decode((string) $requiredRolesJson, true);
if (!is_array($requiredRoles)) {
    fwrite(STDERR, "ERROR: security.mfa.required_roles is not a valid JSON array.\n");
    exit(1);
}
echo 'Required MFA roles: ' . ($requiredRoles ? implode(', ', $requiredRoles) : '(none)') . "\n\n";

// ── Find enrolled users to disable ──────────────────────────────────────────
// (a) enrolled but role no longer requires MFA  → honor "off for everyone"
// (b) enrolled with no secret (un-loginable)    → repair regardless of role
$enrolled = db_select(
    "SELECT u.id, u.email, r.slug AS role_slug,
            (u.mfa_secret IS NULL) AS missing_secret
       FROM users u
       JOIN user_roles r ON r.id = u.role_id
      WHERE u.deleted_at IS NULL
        AND u.mfa_enabled = 1
      ORDER BY u.id ASC"
);

$targets = [];
foreach ($enrolled as $u) {
    $roleNotRequired = !in_array($u['role_slug'], $requiredRoles, true);
    $missingSecret   = (int) $u['missing_secret'] === 1;
    if ($roleNotRequired || $missingSecret) {
        $reason = $missingSecret
            ? 'enabled but NO secret (un-loginable) — repair'
            : 'enrolled, role no longer requires MFA — off-for-everyone';
        $targets[] = ['id' => (int) $u['id'], 'email' => $u['email'], 'role' => $u['role_slug'], 'reason' => $reason];
    }
}

if (!$targets) {
    echo "No enrolled users need disabling. Nothing to do.\n";
    exit(0);
}

printf("Found %d enrolled user(s) to disable:\n", count($targets));
echo str_repeat('─', 86) . "\n";
foreach ($targets as $t) {
    printf("  #%-3d %-34s %-13s %s\n", $t['id'], substr($t['email'], 0, 33), $t['role'], $t['reason']);
}
echo "\n";

if ($dryRun) {
    echo "Dry-run done. Re-run with --execute to apply.\n";
    exit(0);
}

// ── Apply ───────────────────────────────────────────────────────────────────
// disableMfa() is self-contained (clears columns, deletes backup codes, writes
// its own audit entry). Wrap the batch in one transaction so a mid-loop failure
// rolls the whole cleanup back rather than leaving it half-applied.
try {
    db_transaction(function () use ($targets) {
        foreach ($targets as $t) {
            MfaService::disableMfa($t['id'], 0, 'cli');
            echo "  ✓ Disabled MFA for #{$t['id']} ({$t['email']})\n";
        }
    });
    printf("\n✓ Done. Disabled MFA for %d user(s).\n", count($targets));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: transaction rolled back. No changes applied.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
