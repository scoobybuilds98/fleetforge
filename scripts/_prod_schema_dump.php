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
 *
 * USAGE — pass the deployment root explicitly, because when PHP reads the
 * program from stdin __DIR__ is the CWD, not the repo:
 *   ssh fleetforge 'FF_APP_ROOT=/var/www/fleetforge php' < scripts/_prod_schema_dump.php
 * Run in place (root auto-detected from the file's own location):
 *   php scripts/_prod_schema_dump.php
 */

// S-NORTHLAND-P0: was hardcoded to /var/www/fleetforge, which silently bound
// this to one deployment. It is usually piped over stdin (php < script.php), so
// __DIR__ is the CWD rather than the script's home and cannot be trusted alone
// — hence FF_APP_ROOT first, then the on-disk location, then CWD. Fails loudly
// rather than guessing, because guessing means dumping the wrong company's schema.
// Candidate roots, most explicit first. When this script is PIPED over stdin
// (`php < script.php`, its documented usage) PHP sets __DIR__ to the CWD and
// __FILE__ to "Standard input code", so __DIR__ cannot locate the repo — hence
// FF_APP_ROOT is the reliable answer on that path and is tried first.
$appRootCandidates = array_filter([
    (string) getenv('FF_APP_ROOT'),
    dirname(__DIR__),   // running the file in place: scripts/ -> repo root
    (string) getcwd(),  // piped, and CWD happens to be the repo
]);
$appRoot = '';
foreach ($appRootCandidates as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/config/app.php')) {
        $appRoot = $candidate;
        break;
    }
}
if ($appRoot === '') {
    // NOT fwrite(STDERR, ...): the STDERR constant is undefined when PHP reads
    // the program from stdin, so using it here turns a clear diagnostic into an
    // "Undefined constant" fatal on the exact path this script is built for.
    $err = fopen('php://stderr', 'w');
    fwrite($err, "FATAL: cannot locate config/app.php (tried: " . implode(', ', $appRootCandidates) . ").\n"
               . "       Pipe it with an explicit root, e.g.\n"
               . "         ssh fleetforge 'FF_APP_ROOT=/var/www/fleetforge php' < scripts/_prod_schema_dump.php\n");
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
