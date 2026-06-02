<?php
declare(strict_types=1);

/**
 * scripts/restore_rate_limit_defaults_2026_06_02.php
 *
 * One-shot repair tied to the 2026-06-02 security-settings audit.
 *
 * The 13 security.rate_limit.* rows were all sitting at 0. Root cause: the
 * Settings save handler (app/admin/settings/index.php) loaded EVERY key in a
 * group and reset any key absent from POST to its type default. The
 * rate_limit.* rows carry a NULL label so they are never rendered in the
 * Security card and were therefore never in POST — so every save of that card
 * forced them to 0, which disables IP + MFA rate limiting (threshold/window 0
 * makes RateLimiter::check() reset-and-allow on every request).
 *
 * The handler bug is fixed separately (save scope now restricted to
 * label IS NOT NULL). This script restores the rows to the canonical values
 * from db_migrations/S-PROD-1A_security_hardening.sql, which also match the
 * defaults hard-coded in the settings_get() callers (login.php,
 * mfa_challenge.php, forgot_password.php, the AI endpoints).
 *
 * Idempotent: only rows whose value differs from canonical are updated; a
 * second run reports "already correct". Dry-run by default.
 *
 * USAGE:
 *   php scripts/restore_rate_limit_defaults_2026_06_02.php            (dry-run)
 *   php scripts/restore_rate_limit_defaults_2026_06_02.php --execute  (writes)
 */

require_once realpath(__DIR__ . '/../config/app.php');

$dryRun = !in_array('--execute', $argv, true);
echo 'Mode: ' . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n\n";

// Canonical defaults — verbatim from S-PROD-1A_security_hardening.sql and the
// settings_get() fallbacks in the auth code paths.
$canonical = [
    'security.rate_limit.login_ip_threshold'              => '20',
    'security.rate_limit.login_ip_window_minutes'         => '15',
    'security.rate_limit.login_ip_block_minutes'          => '60',
    'security.rate_limit.forgot_password_ip_threshold'    => '5',
    'security.rate_limit.forgot_password_ip_window_minutes'=> '60',
    'security.rate_limit.forgot_password_ip_block_minutes' => '60',
    'security.rate_limit.ai_user_threshold'               => '60',
    'security.rate_limit.ai_user_window_minutes'          => '60',
    'security.rate_limit.mfa_user_threshold'              => '5',
    'security.rate_limit.mfa_user_window_minutes'         => '15',
    'security.rate_limit.mfa_ip_threshold'                => '10',
    'security.rate_limit.mfa_ip_window_minutes'           => '15',
    'security.rate_limit.mfa_ip_block_minutes'            => '60',
];

$drift = [];
foreach ($canonical as $key => $want) {
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    if ($row === null) {
        echo "  ! MISSING row: {$key} (skipped — not seeded)\n";
        continue;
    }
    $have = (string) $row['value'];
    if ($have !== $want) {
        $drift[$key] = ['have' => $have, 'want' => $want];
    }
}

if (!$drift) {
    echo "All 13 rate_limit settings already match canonical values. Nothing to do.\n";
    exit(0);
}

printf("%d row(s) differ from canonical:\n", count($drift));
echo str_repeat('─', 72) . "\n";
foreach ($drift as $key => $d) {
    printf("  %-52s %s → %s\n", $key, $d['have'], $d['want']);
}
echo "\n";

if ($dryRun) {
    echo "Dry-run done. Re-run with --execute to apply.\n";
    exit(0);
}

try {
    db_transaction(function () use ($drift) {
        foreach ($drift as $key => $d) {
            db_execute(
                "UPDATE settings SET `value` = ?, updated_by = NULL, updated_at = NOW() WHERE `key` = ?",
                [$d['want'], $key]
            );
        }
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'cli',
            'action'       => 'update',
            'module'       => 'settings',
            'entity_type'  => 'settings_group',
            'entity_label' => 'security',
            'notes'        => 'restore_rate_limit_defaults_2026_06_02.php restored '
                            . count($drift) . ' security.rate_limit.* row(s) to canonical values '
                            . '(were zeroed by the pre-fix Settings save-scope bug).',
            'ip_address'   => '127.0.0.1',
        ]);
    });
    printf("\n✓ Done. Restored %d rate_limit setting(s).\n", count($drift));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: transaction rolled back. No changes applied.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
