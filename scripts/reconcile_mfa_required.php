<?php
declare(strict_types=1);

/**
 * scripts/reconcile_mfa_required.php
 *
 * S-PROD-1B #67 — Detect and repair mfa_required drift.
 *
 * Compares every active user's mfa_required value against the canonical
 * truth derived from their role_id and the security.mfa.required_roles
 * setting. Reports all drift rows in dry-run mode; applies corrections
 * in --execute mode after an interactive 'yes' confirmation.
 *
 * Canonical formula:
 *   mfa_required = 1  if user's role slug is in security.mfa.required_roles
 *   mfa_required = 0  otherwise
 *
 * USAGE:
 *   php scripts/reconcile_mfa_required.php             (default — dry-run, no writes)
 *   php scripts/reconcile_mfa_required.php --dry-run   (explicit dry-run)
 *   php scripts/reconcile_mfa_required.php --execute   (prompts for 'yes' before writing)
 *
 * SAFETY:
 *   - Default mode is dry-run. Writes nothing, exits 0.
 *   - --execute prints the drift list first, then prompts for the literal string 'yes'.
 *   - Any input other than exactly 'yes' aborts cleanly with no DB writes.
 *   - Single db_transaction wraps all writes — full rollback on any error.
 *   - Idempotent: rows already correct are skipped. Re-running after a clean
 *     run produces 0 drift rows.
 *   - Each correction writes an audit_log entry with actor='cli'.
 *
 * AUTHOR:  S-PROD-1B
 * DATE:    2026-05-02
 * SPEC:    D62, security.mfa.required_roles, FLEETFORGE_SPEC_FINAL.md §8
 */

require_once dirname(__DIR__) . '/config/app.php';

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$args = array_slice($argv ?? [], 1);
$mode = 'dry-run';
foreach ($args as $arg) {
    if ($arg === '--execute') {
        $mode = 'execute';
    } elseif ($arg === '--dry-run') {
        $mode = 'dry-run';
    }
}

echo "┌─────────────────────────────────────────────────────────┐\n";
echo "│  reconcile_mfa_required.php  [S-PROD-1B #67]            │\n";
echo "│  Mode: " . str_pad(strtoupper($mode), 50) . "│\n";
echo "└─────────────────────────────────────────────────────────┘\n\n";

// ---------------------------------------------------------------------------
// Load required_roles from settings
// ---------------------------------------------------------------------------
$requiredRolesJson = settings_get('security.mfa.required_roles', '["super_admin","manager"]');
$requiredRoles = json_decode($requiredRolesJson, true);
if (!is_array($requiredRoles)) {
    echo "ERROR: security.mfa.required_roles is not a valid JSON array.\n";
    exit(1);
}
echo "Required MFA roles: " . implode(', ', $requiredRoles) . "\n\n";

// ---------------------------------------------------------------------------
// Fetch all non-deleted users with their role slug
// ---------------------------------------------------------------------------
$users = db_select(
    "SELECT u.id, u.email, u.name, u.role_id, u.mfa_required, u.mfa_enabled,
            ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.deleted_at IS NULL
     ORDER BY u.id ASC",
    []
);

// ---------------------------------------------------------------------------
// Compute drift
// ---------------------------------------------------------------------------
$driftRows = [];
foreach ($users as $user) {
    $shouldRequire = in_array($user['role_slug'], $requiredRoles, true) ? 1 : 0;
    $currentValue  = (int) $user['mfa_required'];
    if ($currentValue !== $shouldRequire) {
        $driftRows[] = [
            'user_id'       => (int) $user['id'],
            'email'         => $user['email'],
            'name'          => $user['name'],
            'role_slug'     => $user['role_slug'],
            'current'       => $currentValue,
            'correct'       => $shouldRequire,
            'mfa_enabled'   => (int) $user['mfa_enabled'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$driftCount = count($driftRows);

if ($driftCount === 0) {
    echo "✓ No drift detected. All {" . count($users) . "} users have correct mfa_required values.\n";
    exit(0);
}

echo "⚠  DRIFT DETECTED: {$driftCount} user(s) have incorrect mfa_required values.\n\n";
echo sprintf("%-6s %-35s %-16s %-8s %-8s %-12s\n",
    'ID', 'Email', 'Role', 'Current', 'Correct', 'mfa_enabled');
echo str_repeat('─', 90) . "\n";

foreach ($driftRows as $row) {
    $flag = $row['correct'] === 1 ? '↑ promote' : '↓ demote';
    echo sprintf("%-6d %-35s %-16s %-8s %-8s %-12s\n",
        $row['user_id'],
        substr($row['email'], 0, 34),
        $row['role_slug'],
        $row['current'],
        $row['correct'],
        $row['mfa_enabled'] . " ({$flag})"
    );
}

echo "\n";

// ---------------------------------------------------------------------------
// Dry-run exits here
// ---------------------------------------------------------------------------
if ($mode === 'dry-run') {
    echo "DRY-RUN mode — no changes made. Run with --execute to apply corrections.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// --execute: prompt for confirmation
// ---------------------------------------------------------------------------
echo "─── EXECUTE MODE ───────────────────────────────────────────\n";
echo "This will update mfa_required for {$driftCount} user(s).\n";
echo "Per D-E: mfa_required is adjusted; mfa_enabled + mfa_secret are untouched.\n\n";
echo "Type exactly 'yes' to proceed, or anything else to abort: ";

$confirm = trim(fgets(STDIN));
if ($confirm !== 'yes') {
    echo "\nAborted. No changes made.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply corrections inside a single transaction
// ---------------------------------------------------------------------------
echo "\nApplying corrections...\n";

try {
    db_transaction(function () use ($driftRows) {
        $corrected = 0;
        foreach ($driftRows as $row) {
            db_execute(
                "UPDATE users SET mfa_required = ? WHERE id = ?",
                [$row['correct'], $row['user_id']]
            );

            $direction = $row['correct'] === 1 ? 'MFA now required' : 'MFA requirement lifted (mfa_enabled preserved per D-E)';

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'cli',
                'action'       => 'update',
                'module'       => 'users',
                'entity_type'  => 'user',
                'entity_id'    => $row['user_id'],
                'entity_label' => $row['email'],
                'old_values'   => json_encode([
                    'mfa_required' => $row['current'],
                    'role_slug'    => $row['role_slug'],
                ]),
                'new_values'   => json_encode([
                    'mfa_required' => $row['correct'],
                    'role_slug'    => $row['role_slug'],
                ]),
                'notes'        => sprintf(
                    'reconcile_mfa_required.php corrected mfa_required for user %s (role: %s). %s.',
                    $row['email'],
                    $row['role_slug'],
                    $direction
                ),
                'ip_address'   => '127.0.0.1',
            ]);

            echo "  ✓ User {$row['user_id']} ({$row['email']}): mfa_required {$row['current']} → {$row['correct']}\n";
            $corrected++;
        }
        echo "\nCommitting transaction ({$corrected} correction(s))...\n";
    });

    echo "\n✓ Done. {$driftCount} user(s) corrected.\n";
    exit(0);

} catch (Throwable $e) {
    echo "\nERROR: Transaction rolled back. No changes applied.\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
