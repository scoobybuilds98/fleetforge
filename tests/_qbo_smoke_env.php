<?php
declare(strict_types=1);

/**
 * tests/_qbo_smoke_env.php
 *
 * Shared helper for QuickBooks smokes (S-QBO-GOLIVE-AUDIT). The older push
 * smokes were written for a dev DB that had never gone live; once a dev
 * install has rehearsed go-live (cutover date stamped, per-rate invoice tax
 * on, real mappings) their fixtures — dated months earlier — were stopped by
 * the pre-go-live guard before the gate each check targets.
 *
 * ff_qbo_smoke_env() puts the named `quickbooks.*` settings into a known
 * state for the run and restores the install's real values when the script
 * ends (register_shutdown_function — runs on exit/failure too).
 *
 * Usage:  require_once __DIR__ . '/_qbo_smoke_env.php';
 *         ff_qbo_smoke_env(['cutover_at' => '', 'push_from_date' => '']);
 */

if (!function_exists('ff_qbo_smoke_env')) {
    /**
     * @param array<string,string> $overrides short keys under quickbooks.
     */
    function ff_qbo_smoke_env(array $overrides): void
    {
        $saved = [];
        foreach ($overrides as $short => $value) {
            $key = 'quickbooks.' . $short;
            $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
            $saved[$key] = $row === null ? null : (string) $row['value'];
            db_execute(
                "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`)
                 VALUES (?, ?, 'string', 'quickbooks', 0, 0)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $value]
            );
        }
        settings_cache_flush();
        register_shutdown_function(static function () use ($saved): void {
            try {
                if (function_exists('db_rollback_if_active')) {
                    db_rollback_if_active();
                }
                foreach ($saved as $key => $value) {
                    if ($value === null) {
                        db_execute("DELETE FROM settings WHERE `key` = ?", [$key]);
                    } else {
                        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$value, $key]);
                    }
                }
                settings_cache_flush();
            } catch (\Throwable $e) {
                fwrite(STDERR, '[_qbo_smoke_env] restore failed: ' . $e->getMessage() . "\n");
            }
        });
    }
}

if (!function_exists('ff_qbo_smoke_preserve_tables')) {
    /**
     * Snapshot whole tables a smoke rewrites in place (e.g. the account map,
     * which _smoke_qbo_account_mapping links/unlinks on REAL rows and
     * "reverts" to ff_only) and restore them exactly at script end.
     *
     * @param list<string> $tables
     */
    function ff_qbo_smoke_preserve_tables(array $tables): void
    {
        $copies = [];
        foreach ($tables as $t) {
            $copy = '_smoke_snapshot_' . $t;
            db_execute("DROP TABLE IF EXISTS `{$copy}`");
            db_execute("CREATE TABLE `{$copy}` LIKE `{$t}`");
            db_execute("INSERT INTO `{$copy}` SELECT * FROM `{$t}`");
            $copies[$t] = $copy;
        }
        register_shutdown_function(static function () use ($copies): void {
            try {
                if (function_exists('db_rollback_if_active')) {
                    db_rollback_if_active();
                }
                db_execute('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($copies as $t => $copy) {
                    db_execute("DELETE FROM `{$t}`");
                    db_execute("INSERT INTO `{$t}` SELECT * FROM `{$copy}`");
                    db_execute("DROP TABLE `{$copy}`");
                }
                db_execute('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e) {
                fwrite(STDERR, '[_qbo_smoke_env] table restore failed: ' . $e->getMessage() . "\n");
            }
        });
    }
}
