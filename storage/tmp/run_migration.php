<?php
// Apply a single .sql migration file by splitting on ; and running each statement
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$file = $argv[1] ?? null;
if (!$file) { fwrite(STDERR, "usage: php run_migration.php <path-to-sql>\n"); exit(2); }

$sql = file_get_contents($file);
if ($sql === false) { fwrite(STDERR, "cannot read $file\n"); exit(2); }

// Strip line comments before splitting on ;
$lines = preg_split("/\r?\n/", $sql);
$clean = [];
foreach ($lines as $l) {
    $trim = ltrim($l);
    if (str_starts_with($trim, '--')) continue;
    $clean[] = $l;
}
$sql = implode("\n", $clean);

// Split on ; that ends a statement (naive but fine for our migrations)
$stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

$pdo = db_pdo();
foreach ($stmts as $s) {
    try {
        $pdo->exec($s);
        echo "OK: " . substr(preg_replace('/\s+/', ' ', $s), 0, 80) . "\n";
    } catch (\PDOException $e) {
        // Allow IF NOT EXISTS / INSERT IGNORE re-runs
        echo "ERR: " . $e->getMessage() . "\n";
        echo "  in: " . substr(preg_replace('/\s+/', ' ', $s), 0, 200) . "\n";
        exit(1);
    }
}
echo "\nDone.\n";
