<?php
declare(strict_types=1);

/**
 * scripts/_prod_schema_dump.php
 *
 * READ-ONLY diagnostic. Dumps the column set of EVERY table in the database
 * so we can diff prod against the master schema and find ALL drifted
 * (missing/extra) columns at once — not one Sentry error at a time.
 *
 * Runs ONLY SHOW COLUMNS / information_schema SELECTs — no writes whatsoever.
 * Intended to be piped to prod PHP over stdin via ssh; safe on prod.
 */

// S-NORTHLAND-P0: was hardcoded to /var/www/fleetforge, which silently bound
// this to one deployment. It is usually piped over stdin (php < script.php), so
// __DIR__ is the CWD rather than the script's home and cannot be trusted alone
// — hence FF_APP_ROOT first, then the on-disk location, then CWD. Fails loudly
// rather than guessing, because guessing means dumping the wrong company's schema.
$appRoot = (string) getenv('FF_APP_ROOT');
if ($appRoot === '') {
    $appRoot = is_file(dirname(__DIR__) . '/config/app.php') ? dirname(__DIR__) : (string) getcwd();
}
if (!is_file($appRoot . '/config/app.php')) {
    fwrite(STDERR, "FATAL: cannot locate config/app.php (looked in '{$appRoot}').\n"
                 . "       Run from the repo, or export FF_APP_ROOT=/path/to/deployment.\n");
    exit(1);
}
require_once $appRoot . '/config/app.php';

$tableRows = db_select(
    "SELECT table_name AS t FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
     ORDER BY table_name"
);

$out = [];
foreach ($tableRows as $tr) {
    $t = $tr['t'];
    $cols = db_select("SHOW COLUMNS FROM `{$t}`");
    $map = [];
    foreach ($cols as $c) {
        $map[$c['Field']] = $c['Type'] . '|' . $c['Null'] . '|' . ($c['Default'] ?? 'NULL');
    }
    $out[$t] = $map;
}

echo "FF_PROD_SCHEMA_JSON_START\n";
echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
echo "FF_PROD_SCHEMA_JSON_END\n";
