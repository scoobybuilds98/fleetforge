<?php
declare(strict_types=1);

namespace FleetForge\Migrations;

use PDO;
use Throwable;
use RuntimeException;

/**
 * lib/Migrations/Runner.php
 *
 * FleetForge schema migration runner. Scans db_migrations/, applies
 * un-applied .sql files in deterministic filename-ascending order,
 * records each application in schema_migrations with a SHA-256
 * checksum, and is safe to run repeatedly.
 *
 * Why a runner exists (vs `mysql ... < file.sql` manually):
 *   1. Records what's been applied — schema_migrations is the source
 *      of truth, not file presence.
 *   2. Detects post-apply edits via SHA-256 — alerts on drift.
 *   3. Concurrency guard via GET_LOCK('ff_migrations', 0).
 *   4. Default --dry-run; --apply required to mutate.
 *   5. Audit trail per applied file in audit_log.
 *
 * Why we shell out to the `mysql` CLI binary instead of PDO multiquery:
 *   S-LEASE-MILEAGE_schema.sql contains DELIMITER directives + stored
 *   procedures. DELIMITER is a mysql-client-side feature that PDO
 *   does not understand. Shelling out is the only correct way to
 *   replay arbitrary migration files.
 *
 * @session  S-MIGRATIONS-RUNNER
 * @decision D-A (lib/Migrations layout), D-B (checksum/applied_by/
 *           execution_ms cols), D-C (filename convention), D-D
 *           (filename-asc order), D-E (SHA-256 checksum), D-F
 *           (no rollback on DDL), D-G (GET_LOCK ff_migrations 0),
 *           D-H (dry-run default), D-I (audit_log per file),
 *           D-J (--backfill refuses if non-empty)
 */
class Runner
{
    public const LOCK_NAME       = 'ff_migrations';
    public const FILENAME_REGEX  = '/^[A-Za-z0-9_\-]+\.sql$/';
    public const HISTORICAL_FILES = [
        'SAMSARA-1_schema.sql',
        'S-FIX-2_credit_notes_source_overpayment.sql',
        'S-PROD-1A_security_hardening.sql',
        'S-PROD-2_ses_bounce_handler.sql',
        'S-LEASE-MILEAGE_schema.sql',
    ];

    private string $migrationsDir;
    private string $mysqlBinary;

    public function __construct(?string $migrationsDir = null, ?string $mysqlBinary = null)
    {
        $this->migrationsDir = $migrationsDir ?? FF_ROOT . '/db_migrations';
        $this->mysqlBinary   = $mysqlBinary ?? $this->resolveMysqlBinary();

        if (!is_dir($this->migrationsDir)) {
            throw new RuntimeException("Migrations directory not found: {$this->migrationsDir}");
        }
    }

    // ── Lock acquisition (D-G) ─────────────────────────────────

    public function acquireLock(): void
    {
        $row = db_row("SELECT GET_LOCK(?, 0) AS ok", [self::LOCK_NAME]);
        if (!$row || (int) $row['ok'] !== 1) {
            throw new RuntimeException(
                "Another migration run is in flight (GET_LOCK '" . self::LOCK_NAME . "' failed). "
              . "Wait for it to finish or investigate a stuck connection."
            );
        }
    }

    public function releaseLock(): void
    {
        // Failure to release is non-fatal — the lock auto-frees on
        // connection close. Swallow exceptions so we don't mask a
        // prior real error during cleanup.
        try {
            db_execute("SELECT RELEASE_LOCK(?)", [self::LOCK_NAME]);
        } catch (Throwable $e) {
            // intentionally ignored
        }
    }

    // ── Discovery ──────────────────────────────────────────────

    /**
     * @return list<string> filenames sorted ascending
     */
    public function listFiles(): array
    {
        $files = [];
        foreach (scandir($this->migrationsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (substr($entry, -4) !== '.sql') continue;
            if (!preg_match(self::FILENAME_REGEX, $entry)) {
                throw new RuntimeException(
                    "Migration filename rejected: '{$entry}'. "
                  . "Allowed: " . self::FILENAME_REGEX
                );
            }
            $files[] = $entry;
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * @return array<string,array{filename:string,checksum:string,applied_at:string,applied_by:string,execution_ms:?int}>
     *         keyed by filename
     */
    public function listApplied(): array
    {
        // D-BASELINE-3: on a completely fresh DB (000_baseline not yet applied)
        // schema_migrations doesn't exist yet. Catch the "table doesn't exist"
        // exception and return [] so plan() shows all files as pending, letting
        // 000_baseline run first — it creates schema_migrations and seeds it.
        try {
            $rows = db_select(
                "SELECT version, filename, checksum, applied_at, applied_by, execution_ms
                   FROM schema_migrations
                  ORDER BY version ASC",
                []
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), "schema_migrations") ||
                str_contains($e->getMessage(), "doesn't exist") ||
                str_contains($e->getMessage(), "Table") && str_contains($e->getMessage(), "exist")) {
                return [];
            }
            throw $e;
        }
        $byFilename = [];
        foreach ($rows as $r) {
            $byFilename[(string) $r['filename']] = [
                'filename'     => (string) $r['filename'],
                'checksum'     => (string) ($r['checksum'] ?? ''),
                'applied_at'   => (string) $r['applied_at'],
                'applied_by'   => (string) $r['applied_by'],
                'execution_ms' => $r['execution_ms'] !== null ? (int) $r['execution_ms'] : null,
            ];
        }
        return $byFilename;
    }

    /**
     * Compute the apply plan: files in directory minus rows in
     * schema_migrations. Also reports checksum drift on already-
     * applied files (warning, not part of the apply list).
     *
     * @return array{
     *   to_apply: list<array{filename:string, checksum:string}>,
     *   drift:    list<array{filename:string, stored:string, current:string}>,
     *   already:  int
     * }
     */
    public function plan(): array
    {
        $files   = $this->listFiles();
        $applied = $this->listApplied();
        $toApply = [];
        $drift   = [];

        foreach ($files as $f) {
            $current = $this->checksum($f);
            if (!isset($applied[$f])) {
                $toApply[] = ['filename' => $f, 'checksum' => $current];
                continue;
            }
            $stored = $applied[$f]['checksum'];
            // Empty stored checksum means the row pre-dates the
            // checksum column — treat as no-drift baseline rather
            // than flagging.
            if ($stored !== '' && $stored !== $current) {
                $drift[] = [
                    'filename' => $f,
                    'stored'   => $stored,
                    'current'  => $current,
                ];
            }
        }

        return [
            'to_apply' => $toApply,
            'drift'    => $drift,
            'already'  => count($applied),
        ];
    }

    // ── Apply (D-F: each file own connection, no rollback) ────

    /**
     * Apply a single migration file by shelling out to mysql CLI.
     * Returns execution time in milliseconds.
     *
     * Exit code 0 from mysql = success. Anything else throws.
     */
    public function applyFile(string $filename): int
    {
        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read migration file: {$path}");
        }

        $cmd = [
            $this->mysqlBinary,
            '--host=' . (string) env('DB_HOST', '127.0.0.1'),
            '--port=' . (string) env('DB_PORT', '3306'),
            '--user=' . (string) env('DB_USERNAME', 'root'),
            '--password=' . (string) env('DB_PASSWORD', ''),
            '--default-character-set=utf8mb4',
            '--protocol=TCP',
            (string) env('DB_DATABASE', 'fleetforge'),
        ];

        $start  = microtime(true);
        $stdin  = fopen($path, 'rb');
        if ($stdin === false) {
            throw new RuntimeException("Cannot open migration file for reading: {$path}");
        }

        $descriptors = [
            0 => $stdin,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc  = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException("Failed to spawn mysql binary at {$this->mysqlBinary}");
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $elapsed  = (int) round((microtime(true) - $start) * 1000);

        // mysql CLI emits a "Using a password on the command line interface
        // can be insecure" warning on stderr even on success. Treat as noise.
        $stderrFiltered = preg_replace(
            '/^.*Using a password on the command line interface.*\n?/m',
            '',
            $stderr
        ) ?? $stderr;

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Migration FAILED: {$filename}\n"
              . "Exit code: {$exitCode}\n"
              . "stderr:\n" . trim($stderrFiltered) . "\n"
              . (trim($stdout) !== '' ? "stdout:\n" . trim($stdout) . "\n" : '')
              . "MySQL DDL auto-commits — partial state may be present. "
              . "Inspect the DB and decide between roll-forward (fix the file, re-run) or restore from backup."
            );
        }

        return $elapsed;
    }

    /**
     * Record a successfully applied migration in schema_migrations
     * and audit_log. Caller is responsible for having actually run
     * the file first (this method does NOT execute SQL).
     */
    public function recordApplied(
        string $filename,
        string $checksum,
        int $executionMs,
        string $appliedBy,
        ?string $appliedAt = null
    ): int {
        $version = $this->versionFromFilename($filename);

        $row = [
            'version'      => $version,
            'filename'     => $filename,
            'checksum'     => $checksum,
            'applied_by'   => $appliedBy,
            'execution_ms' => $executionMs,
        ];
        if ($appliedAt !== null) {
            $row['applied_at'] = $appliedAt;
        }
        $id = db_insert('schema_migrations', $row);

        // Audit trail (D-I) — best-effort, doesn't roll back if it fails.
        try {
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'cron',
                'module'       => 'migrations',
                'entity_type'  => 'migration_file',
                'entity_id'    => $id,
                'entity_label' => $filename,
                'new_values'   => json_encode([
                    'version'      => $version,
                    'checksum'     => $checksum,
                    'execution_ms' => $executionMs,
                    'applied_by'   => $appliedBy,
                ], JSON_UNESCAPED_SLASHES),
                'notes'        => "Migration applied: {$filename} ({$executionMs}ms)",
            ]);
        } catch (Throwable $e) {
            error_log("[migrations] audit_log insert failed for {$filename}: " . $e->getMessage());
        }

        return $id;
    }

    // ── Backfill (D-J) ─────────────────────────────────────────

    /**
     * One-time bootstrap: record the 5 historical migration files
     * as already-applied, with applied_at = file mtime.
     *
     * Refuses to run if schema_migrations contains any row, so this
     * cannot accidentally double-record.
     *
     * @return list<array{filename:string,checksum:string,applied_at:string}>
     */
    public function backfill(string $appliedBy = 'backfill'): array
    {
        $existing = db_count("SELECT COUNT(*) AS n FROM schema_migrations", []);
        if ($existing > 0) {
            throw new RuntimeException(
                "Refusing to backfill: schema_migrations already has {$existing} rows. "
              . "Backfill is a one-time bootstrap. If this is a mistake, "
              . "TRUNCATE the table only after confirming you have a backup."
            );
        }

        $results = [];
        foreach (self::HISTORICAL_FILES as $f) {
            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $f;
            if (!is_file($path)) {
                throw new RuntimeException(
                    "Backfill expected historical file '{$f}' but it does not exist at {$path}"
                );
            }
            $checksum  = $this->checksum($f);
            $mtime     = filemtime($path) ?: time();
            $appliedAt = date('Y-m-d H:i:s', $mtime);
            $this->recordApplied($f, $checksum, 0, $appliedBy, $appliedAt);
            $results[] = [
                'filename'   => $f,
                'checksum'   => $checksum,
                'applied_at' => $appliedAt,
            ];
        }
        return $results;
    }

    // ── Verify (recompute all stored checksums) ───────────────

    /**
     * @return array{ok: list<string>, drift: list<array{filename:string, stored:string, current:string}>, missing_file: list<string>}
     */
    public function verify(): array
    {
        $applied = $this->listApplied();
        $ok      = [];
        $drift   = [];
        $missing = [];

        foreach ($applied as $filename => $row) {
            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                // D-BASELINE-2: pre-baseline migrations are archived as *.sql.txt
                // after the baseline is introduced. If the .sql.txt sibling exists
                // the file was deliberately superseded — not a missing/corrupt file.
                if (is_file($path . '.txt')) {
                    $ok[] = $filename;
                    continue;
                }
                $missing[] = $filename;
                continue;
            }
            $current = $this->checksum($filename);
            $stored  = $row['checksum'];
            if ($stored === '') {
                // Pre-checksum baseline — treat as ok.
                $ok[] = $filename;
                continue;
            }
            if ($stored === $current) {
                $ok[] = $filename;
            } else {
                $drift[] = [
                    'filename' => $filename,
                    'stored'   => $stored,
                    'current'  => $current,
                ];
            }
        }

        return [
            'ok'           => $ok,
            'drift'        => $drift,
            'missing_file' => $missing,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────

    public function checksum(string $filename): string
    {
        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;
        $h = hash_file('sha256', $path);
        if ($h === false) {
            throw new RuntimeException("Cannot compute SHA-256 for {$path}");
        }
        return $h;
    }

    private function versionFromFilename(string $filename): string
    {
        // Strip .sql; the remainder is the version. The UNIQUE
        // constraint on schema_migrations.version (varchar 100)
        // gives us idempotency per filename.
        $base = preg_replace('/\.sql$/i', '', $filename) ?? $filename;
        return substr($base, 0, 100);
    }

    private function resolveMysqlBinary(): string
    {
        // 1. Explicit env override wins.
        $envPath = (string) env('FF_MYSQL_BINARY', '');
        if ($envPath !== '' && is_executable($envPath)) {
            return $envPath;
        }
        // 2. PATH lookup via `command -v`.
        $which = trim((string) shell_exec('command -v mysql 2>/dev/null') ?? '');
        if ($which !== '' && is_executable($which)) {
            return $which;
        }
        // 3. Common install locations.
        foreach ([
            '/opt/homebrew/opt/mysql@8.0/bin/mysql',
            '/opt/homebrew/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/bin/mysql',
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException(
            "mysql binary not found. Set FF_MYSQL_BINARY in .env or install mysql-client."
        );
    }

    public function getMigrationsDir(): string
    {
        return $this->migrationsDir;
    }

    public function getMysqlBinary(): string
    {
        return $this->mysqlBinary;
    }
}
