<?php
/**
 * FleetForge — Stage Demo Data to Production
 *
 * Production-ONLY, heavily-guarded staging tool. Wipes pre-launch production
 * dummy data and seeds it with the validated rehearsal demo dataset
 * (S-DRESS-REHEARSAL-WIPE-AND-SEED). Intended for the AWS Lightsail server
 * before the first real customer goes live.
 *
 * OPERATOR RUNS THIS ON LIGHTSAIL. Claude Code builds + dev-verifies ONLY.
 * Never run the real (non-dry-run) path in dev or CI. (D-STAGE-PROD-TOOL)
 *
 * Five guards (in order):
 *   G1 — production-only + dry-run: refuses unless APP_ENV='production'.
 *         --dry-run runs all pre-flight checks and prints the full plan
 *         without mutating anything — verifiable in dev (reports "would refuse").
 *   G2 — explicit token + typed DB name: requires
 *         --i-am-staging-demo-data-to-production AND
 *         --confirm-db=<name> matching the configured DB name exactly.
 *   G3 — QBO safety pre-flight: refuses if connection_status='connected' AND
 *         environment is not 'sandbox' AND sync_enabled='1' — that combination
 *         would push fake data to real books.
 *   G4 — backup-first: takes a full gzipped mysqldump before any wipe.
 *         Refuses to proceed if the backup fails or the file is too small.
 *   G5 — blast-radius echo + inline confirmation: prints DB, APP_ENV, QBO
 *         state, row counts about to be wiped, dataset about to be seeded,
 *         backup path, and restore command. Prompts for final confirmation.
 *
 * Usage (on Lightsail production server):
 *   # Dry-run — verify all guards pass and review the plan:
 *   php scripts/stage_demo_to_production.php --dry-run --confirm-db=<prod-db>
 *
 *   # Real run — wipe + seed (IRREVERSIBLE without the backup):
 *   php scripts/stage_demo_to_production.php \
 *       --i-am-staging-demo-data-to-production \
 *       --confirm-db=<prod-db>
 *
 * @session S-STAGE-DEMO-TO-PRODUCTION
 * @decision D-STAGE-PROD-TOOL
 * @see docs/runbooks/stage_demo_to_production.md
 */

declare(strict_types=1);

// Load wipe + seed logic as libraries — bypasses their dev-only guards.
// These defines MUST come before the require calls so the include guards
// are set when the files are parsed.
define('FF_DEMO_WIPE_INCLUDE',      true);
define('FF_REHEARSAL_SEED_INCLUDE', true);

require __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../lib/Billing/InvoiceGenerator.php';
require __DIR__ . '/demo_wipe.php';      // defines ff_demo_wipe_execute()
require __DIR__ . '/rehearsal_seed.php'; // defines rs_* helpers + ff_rehearsal_seed_execute()

// ─────────────────────────────────────────────────────────────────────────────
// Parse CLI arguments
// ─────────────────────────────────────────────────────────────────────────────
$cliArgs  = $argv ?? [];
$isDryRun = in_array('--dry-run', $cliArgs, true);
$hasToken = in_array('--i-am-staging-demo-data-to-production', $cliArgs, true);

$confirmDb = null;
foreach ($cliArgs as $arg) {
    if (str_starts_with($arg, '--confirm-db=')) {
        $confirmDb = substr($arg, strlen('--confirm-db='));
        break;
    }
}

$dbName = defined('FF_DB_NAME') ? FF_DB_NAME : '';
$appEnv = defined('APP_ENV')   ? APP_ENV    : '(undefined)';

// ─────────────────────────────────────────────────────────────────────────────
// GUARD 1 — production-only
// config/app.php defaults APP_ENV to 'production' when unset, so an
// ambiguous/missing .env also fails closed (same logic as demo_wipe.php G1).
// In dry-run mode we report the refusal without exiting 1 — so the operator
// can verify from dev that the guard is wired correctly.
// ─────────────────────────────────────────────────────────────────────────────
$isProduction = (defined('APP_ENV') && APP_ENV === 'production');

if (!$isProduction) {
    echo "\n";
    if ($isDryRun) {
        echo "  [DRY-RUN] ✓ G1 verified: APP_ENV='{$appEnv}' — NOT production.\n";
        echo "  This tool runs only on a production server. In dev, use:\n";
        echo "    php scripts/demo_wipe.php --i-understand-this-wipes-dev\n";
        echo "    php scripts/rehearsal_seed.php\n";
        echo "\n  Pre-flight checks cannot continue (no production DB to probe).\n";
        echo "  Run on the Lightsail production server to complete the dry-run check.\n\n";
        exit(0);
    }
    fwrite(STDERR, "  REFUSE (G1) — APP_ENV='{$appEnv}', not 'production'. Exiting.\n");
    fwrite(STDERR, "  This tool runs only on the production server.\n\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// GUARD 2 — explicit token + typed DB name
// ─────────────────────────────────────────────────────────────────────────────
if (!$isDryRun && !$hasToken) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  MISSING TOKEN (G2)\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  USAGE:\n");
    fwrite(STDERR, "    php scripts/stage_demo_to_production.php \\\n");
    fwrite(STDERR, "        --i-am-staging-demo-data-to-production \\\n");
    fwrite(STDERR, "        --confirm-db={$dbName}\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  Add --dry-run to preview without mutating anything.\n\n");
    exit(1);
}

if ($confirmDb === null) {
    fwrite(STDERR, "\n  MISSING --confirm-db=<name> (G2).\n");
    fwrite(STDERR, "  Pass --confirm-db={$dbName} to confirm the target database.\n\n");
    exit(1);
}

if ($confirmDb !== $dbName) {
    fwrite(STDERR, "\n  DB NAME MISMATCH (G2)\n");
    fwrite(STDERR, "  --confirm-db   = {$confirmDb}\n");
    fwrite(STDERR, "  Configured DB  = {$dbName}\n");
    fwrite(STDERR, "  The typed name must exactly match the configured DB name.\n\n");
    exit(1);
}

echo "\n";
echo "  ╔══════════════════════════════════════════════════════════════╗\n";
echo "  ║  FleetForge — Stage Demo Data to Production                  ║\n";
echo "  ╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  APP_ENV  = {$appEnv}\n";
echo "  DB       = {$dbName}\n";
if ($isDryRun) {
    echo "  Mode     = DRY-RUN (no mutations)\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// GUARD 3 — QBO safety pre-flight
// REFUSE if: connection_status='connected' AND environment is not 'sandbox'
//            AND sync_enabled='1'
// That combination would push fake data to real Intuit books.
// ─────────────────────────────────────────────────────────────────────────────
$qboConnStatus  = (string) settings_get('quickbooks.connection_status', 'not_connected');
$qboEnvironment = (string) settings_get('quickbooks.environment',       '(unknown)');
$qboRealmId     = (string) settings_get('quickbooks.realm_id',          '(not connected)');
$qboSyncEnabled = (string) settings_get('quickbooks.sync_enabled',      '0');

$qboIsConnected  = ($qboConnStatus === 'connected');
$qboIsRealRealm  = ($qboIsConnected
                    && $qboEnvironment !== 'sandbox'
                    && $qboRealmId !== ''
                    && $qboRealmId !== '(not connected)');
$qboIsSyncOn     = ($qboSyncEnabled === '1');

echo "  ── QBO Pre-flight (G3) ──────────────────────────────────────────\n";
echo "  connection_status = {$qboConnStatus}\n";
echo "  environment       = {$qboEnvironment}\n";
echo "  realm_id          = {$qboRealmId}\n";
echo "  sync_enabled      = {$qboSyncEnabled}\n";

$qboBlocking = ($qboIsConnected && $qboIsRealRealm && $qboIsSyncOn);

if ($qboBlocking) {
    echo "\n";
    fwrite(STDERR, "  REFUSE (G3) — QBO is connected to a real Intuit realm with sync_enabled='1'.\n");
    fwrite(STDERR, "  Seeding fake data while QBO sync is active WILL push fake data to real books.\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "  To proceed, disable QBO sync first:\n");
    fwrite(STDERR, "    UPDATE settings SET value='0' WHERE \`key\`='quickbooks.sync_enabled';\n");
    fwrite(STDERR, "  — or confirm you are on the sandbox realm:\n");
    fwrite(STDERR, "    UPDATE settings SET value='sandbox' WHERE \`key\`='quickbooks.environment';\n");
    fwrite(STDERR, "  Then re-run this script.\n\n");
    exit(1);
}

// Determine why QBO is safe
if (!$qboIsConnected) {
    $qboSafeReason = 'not_connected — no QBO push possible';
} elseif ($qboEnvironment === 'sandbox') {
    $qboSafeReason = 'sandbox_realm — fake data stays in the Intuit sandbox';
} else {
    $qboSafeReason = 'sync_enabled=0 — push pipeline is disabled';
}
echo "  QBO safe to proceed: {$qboSafeReason}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Collect current row counts (for blast-radius echo)
// ─────────────────────────────────────────────────────────────────────────────
$countTables = [
    'customers', 'vendors', 'equipment_units', 'equipment_templates',
    'leases', 'invoices', 'payments', 'acc_bills',
    'leases', 'rate_cards', 'audit_log', 'notifications',
];
$rowCounts = [];
foreach (array_unique($countTables) as $tbl) {
    $rowCounts[$tbl] = (int) (db_row("SELECT COUNT(*) n FROM {$tbl}", [])['n'] ?? 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// GUARD 4 — Backup-first
// Take a full gzipped mysqldump before any mutation. Refuse if it fails or
// produces an empty/corrupt file. The backup path and restore command are
// printed for the operator.
// ─────────────────────────────────────────────────────────────────────────────
$backupDir  = FF_ROOT . '/storage/backups/staging';
$timestamp  = date('YmdHis');
$backupFile = "{$backupDir}/pre_staging_{$timestamp}.sql.gz";
$restoreCmd = "zcat {$backupFile} | mysql --host=" . FF_DB_HOST
            . " --port=" . FF_DB_PORT
            . " --user=" . FF_DB_USER
            . " --password=<password>"
            . " " . FF_DB_NAME;

echo "  ── Backup (G4) ─────────────────────────────────────────────────\n";
echo "  Backup path: {$backupFile}\n";

if ($isDryRun) {
    echo "  [DRY-RUN] Backup step skipped — no mutations.\n\n";
} else {
    // Ensure backup directory exists
    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true)) {
        fwrite(STDERR, "  REFUSE (G4) — cannot create backup directory: {$backupDir}\n");
        exit(1);
    }

    $dumpCmd = [
        'mysqldump',
        '--single-transaction',
        '--quick',
        '--lock-tables=false',
        '--default-character-set=utf8mb4',
        '--set-gtid-purged=OFF',
        '--host='   . FF_DB_HOST,
        '--port='   . FF_DB_PORT,
        '--user='   . FF_DB_USER,
        '--password=' . FF_DB_PASS,
        FF_DB_NAME,
    ];

    $dumpProc = proc_open($dumpCmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($dumpProc)) {
        fwrite(STDERR, "  REFUSE (G4) — proc_open failed: cannot start mysqldump.\n");
        exit(1);
    }
    fclose($pipes[0]);

    $gz = gzopen($backupFile, 'wb9');
    if ($gz === false) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($dumpProc);
        fwrite(STDERR, "  REFUSE (G4) — gzopen failed for {$backupFile}. Check ext-zlib.\n");
        exit(1);
    }

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== false && $chunk !== '') {
            gzwrite($gz, $chunk);
        }
    }
    gzclose($gz);

    $dumpStderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $dumpExit = proc_close($dumpProc);

    if ($dumpExit !== 0) {
        @unlink($backupFile);
        fwrite(STDERR, "  REFUSE (G4) — mysqldump exited {$dumpExit}: {$dumpStderr}\n");
        exit(1);
    }

    clearstatcache();
    $backupSize = file_exists($backupFile) ? (int) filesize($backupFile) : 0;
    if ($backupSize < 1024) {
        @unlink($backupFile);
        fwrite(STDERR, "  REFUSE (G4) — backup file too small ({$backupSize} bytes); likely empty/corrupt.\n");
        exit(1);
    }

    // Verify gzip header contains a mysql dump
    $gzVerify = gzopen($backupFile, 'rb');
    if ($gzVerify !== false) {
        $header = (string) gzread($gzVerify, 100);
        gzclose($gzVerify);
        if (!str_contains($header, '-- MySQL dump') && !str_contains($header, '-- MariaDB dump')) {
            @unlink($backupFile);
            fwrite(STDERR, "  REFUSE (G4) — backup header verification failed (not a mysqldump).\n");
            exit(1);
        }
    }

    $sizeMb = round($backupSize / 1048576, 2);
    echo "  Backup complete: {$sizeMb} MB\n";
    echo "  Restore command:\n";
    echo "    {$restoreCmd}\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// GUARD 5 — Blast-radius echo + final confirmation
// ─────────────────────────────────────────────────────────────────────────────
echo "  ── Blast-Radius Echo ───────────────────────────────────────────\n";
echo "\n";
echo "  TARGET DB   : {$dbName}\n";
echo "  APP_ENV     : {$appEnv}\n";
echo "  QBO status  : {$qboSafeReason}\n";
echo "\n";
echo "  WILL WIPE (current row counts):\n";
foreach ($rowCounts as $tbl => $n) {
    printf("    %-30s %d rows\n", $tbl, $n);
}
echo "\n";
echo "  WILL SEED (rehearsal dataset):\n";
echo "    6  equipment_templates  (dry van, reefer, flatbed, step deck, day-cab, sleeper)\n";
echo "    25 equipment_units      (8 dry van, 5 reefer, 4 flatbed, 3 step deck, 3 day-cab, 2 sleeper)\n";
echo "    12 customers            (BC trucking companies, province=BC)\n";
echo "    4  vendors              (maintenance, parts, fuel, finance)\n";
echo "    6  leases               (just-started, mid-partial, mid-current, ending-soon, long-term, closed)\n";
echo "    ~14 invoices            (via InvoiceGenerator, sent via direct UPDATE — no JE, no QBO enqueue)\n";
echo "    4  payments             (leases 2/3/5/6; cheque/EFT/e-transfer)\n";
echo "    3  bills                (maintenance/parts/fuel, status approved)\n";
echo "\n";
echo "  NO QBO push: seed uses direct db_insert + direct UPDATE — no Enqueuers called.\n";
echo "\n";

if ($isDryRun) {
    echo "  [DRY-RUN] All pre-flight checks passed. No mutations performed.\n";
    echo "\n  To run for real:\n";
    echo "    php scripts/stage_demo_to_production.php \\\n";
    echo "        --i-am-staging-demo-data-to-production \\\n";
    echo "        --confirm-db={$dbName}\n\n";
    exit(0);
}

// Inline confirmation
echo "  ┌─────────────────────────────────────────────────────────────────┐\n";
echo "  │  FINAL CONFIRMATION REQUIRED                                     │\n";
echo "  │  Type exactly: yes-wipe-and-seed                                 │\n";
echo "  │  Or press Ctrl-C to abort.                                       │\n";
echo "  └─────────────────────────────────────────────────────────────────┘\n";
echo "\n  Confirm: ";
$confirm = trim((string) fgets(STDIN));
if ($confirm !== 'yes-wipe-and-seed') {
    echo "\n  Aborted (typed '{$confirm}'). No changes made.\n\n";
    exit(0);
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────────
// EXECUTE — wipe then seed
// Record queue baseline to prove no QBO enqueue fires.
// ─────────────────────────────────────────────────────────────────────────────
$queueBefore = (int) (db_row("SELECT COUNT(*) n FROM acc_qbo_sync_queue", [])['n'] ?? 0);
$maxQueueId  = (int) (db_row("SELECT COALESCE(MAX(id), 0) n FROM acc_qbo_sync_queue", [])['n'] ?? 0);

echo "  ── Executing wipe ─────────────────────────────────────────────\n";
ff_demo_wipe_execute();

echo "\n  ── Executing seed ─────────────────────────────────────────────\n";
ff_rehearsal_seed_execute();

// ─────────────────────────────────────────────────────────────────────────────
// POST-VERIFY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n  ── Post-verify ────────────────────────────────────────────────\n";
$verifyPass = 0;
$verifyFail = 0;

function staging_check(string $label, bool $pass, string $detail = ''): void
{
    global $verifyPass, $verifyFail;
    if ($pass) {
        printf("  PASS  %-45s %s\n", $label, $detail);
        $verifyPass++;
    } else {
        printf("  FAIL  %-45s %s\n", $label, $detail);
        $verifyFail++;
    }
}

// Row count checks
$expected = [
    'equipment_templates' => 6,
    'equipment_units'     => 25,
    'customers'           => 12,
    'vendors'             => 4,
    'leases'              => 6,
    'invoices'            => null,  // ~14 — just check > 0
    'payments'            => 4,
    'acc_bills'           => 3,
];
foreach ($expected as $tbl => $exp) {
    $actual = (int) (db_row("SELECT COUNT(*) n FROM {$tbl}", [])['n'] ?? 0);
    if ($exp === null) {
        staging_check("{$tbl} count > 0", $actual > 0, "got {$actual}");
    } else {
        staging_check("{$tbl} count = {$exp}", $actual === $exp, "got {$actual}");
    }
}

// Reconciliation spot-check: lease 2 (reefer, mid-cycle-partial)
// Should have 2 invoices: 1 paid + 1 sent (outstanding)
$lease2 = db_row(
    "SELECT id FROM leases WHERE contract_number LIKE 'CN-RHRSL-0002' LIMIT 1",
    []
);
if ($lease2) {
    $l2Id   = (int) $lease2['id'];
    $l2Paid = (int) (db_row(
        "SELECT COUNT(*) n FROM invoices WHERE lease_id=? AND status='paid'",
        [$l2Id]
    )['n'] ?? 0);
    $l2Sent = (int) (db_row(
        "SELECT COUNT(*) n FROM invoices WHERE lease_id=? AND status IN ('sent','partially_paid','overdue')",
        [$l2Id]
    )['n'] ?? 0);
    staging_check('lease2 has 1 paid invoice',       $l2Paid === 1, "paid={$l2Paid}");
    staging_check('lease2 has 1 outstanding invoice', $l2Sent === 1, "outstanding={$l2Sent}");
    $l2Pay = (int) (db_row(
        "SELECT COUNT(*) n FROM payments p JOIN leases l ON l.customer_id=p.customer_id WHERE l.id=?",
        [$l2Id]
    )['n'] ?? 0);
    staging_check('lease2 has a payment record', $l2Pay >= 1, "payments={$l2Pay}");
} else {
    staging_check('lease2 found by contract_number', false, 'CN-RHRSL-0002 not found');
}

// No zero-amount invoices (guards the silent-zero-rate bug class)
$zeroInv = (int) (db_row(
    "SELECT COUNT(*) n FROM invoices WHERE total_amount <= 0",
    []
)['n'] ?? 0);
staging_check('no zero-amount invoices', $zeroInv === 0, "zero-amount={$zeroInv}");

// All invoices are sent or paid (no drafts left over)
$draftInv = (int) (db_row(
    "SELECT COUNT(*) n FROM invoices WHERE status='draft' AND deleted_at IS NULL",
    []
)['n'] ?? 0);
staging_check('no draft invoices remain', $draftInv === 0, "drafts={$draftInv}");

// NO NEW QBO queue rows added during the seed
$queueAfter    = (int) (db_row("SELECT COUNT(*) n FROM acc_qbo_sync_queue", [])['n'] ?? 0);
$maxQueueIdNow = (int) (db_row("SELECT COALESCE(MAX(id), 0) n FROM acc_qbo_sync_queue", [])['n'] ?? 0);
$newQueueRows  = max(0, $maxQueueIdNow - $maxQueueId);
staging_check('no new QBO queue rows (no accidental push)', $newQueueRows === 0, "new_rows={$newQueueRows}");

// acc_qbo_sync_queue row count check (existing rows are fine — no new ones)
staging_check('acc_qbo_sync_queue baseline unchanged', $queueAfter === $queueBefore,
    "before={$queueBefore} after={$queueAfter}");

// Outstanding AR matches sum of open invoices
$arFromInvoices  = (string) (db_row(
    "SELECT COALESCE(SUM(balance_due),'0.00') t FROM invoices WHERE deleted_at IS NULL AND status IN ('sent','partially_paid','overdue')",
    []
)['t'] ?? '0.00');
$arFromCustomers = (string) (db_row(
    "SELECT COALESCE(SUM(outstanding_balance),'0.00') t FROM customers WHERE deleted_at IS NULL",
    []
)['t'] ?? '0.00');
$arMatch = (bccomp($arFromInvoices, $arFromCustomers, 2) === 0);
staging_check('customer outstanding_balance == open invoice sum',
    $arMatch,
    sprintf('inv_sum=$%s  cust_sum=$%s', number_format((float)$arFromInvoices, 2), number_format((float)$arFromCustomers, 2))
);

echo "\n";
$verifyTotal = $verifyPass + $verifyFail;
echo "  Post-verify: {$verifyPass}/{$verifyTotal} PASS" . ($verifyFail > 0 ? " ({$verifyFail} FAIL)" : '') . "\n";

if ($verifyFail > 0) {
    echo "\n  ⚠  Some post-verify checks failed. Review the failures above.\n";
    echo "  The backup is at: {$backupFile}\n";
    echo "  Restore command:\n    {$restoreCmd}\n\n";
    exit(1);
}

echo "\n";
echo "  ══════════════════════════════════════════════════════════════\n";
echo "  Staging complete — production DB now holds the rehearsal demo  \n";
echo "  dataset. See docs/runbooks/stage_demo_to_production.md §5 for \n";
echo "  the post-run checklist.                                        \n";
echo "  Backup at: {$backupFile}\n";
echo "  ══════════════════════════════════════════════════════════════\n\n";
exit(0);
