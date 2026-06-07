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

require_once '/var/www/fleetforge/config/app.php';

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
