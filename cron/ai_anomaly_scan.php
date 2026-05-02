<?php
declare(strict_types=1);

/**
 * cron/ai_anomaly_scan.php
 *
 * Nightly anomaly detection scan. Analyzes FleetForge data
 * for unusual patterns and stores alerts.
 *
 * Recommended cron schedule: daily at 2:00 AM
 *   0 2 * * * php /path/to/fleetforge/cron/ai_anomaly_scan.php
 *
 * This script runs all anomaly detectors (SQL-based statistical
 * checks) and optionally enriches results with AI explanations.
 * No external API calls required for basic detection.
 *
 * @depends lib/AI/AnomalyDetector.php
 * @session S027
 */

// WHY: Cron scripts must bootstrap the app themselves
require_once __DIR__ . '/../config/app.php';
\FleetForge\Observability\Sentry::init();

$startTime = microtime(true);
$logPrefix = '[' . date('Y-m-d H:i:s') . '] [AI_ANOMALY_SCAN]';

echo "{$logPrefix} Starting anomaly scan...\n";

try {
    $alertCount = \FleetForge\AI\AnomalyDetector::runAll(null);
    $elapsed    = round((microtime(true) - $startTime) * 1000);

    echo "{$logPrefix} Scan complete. {$alertCount} new alert(s) created in {$elapsed}ms.\n";

    // WHY: Update settings with last scan time so the UI can show it
    try {
        $existing = db_row("SELECT id FROM settings WHERE `key` = 'ai.last_anomaly_scan'");
        if ($existing) {
            db_execute(
                "UPDATE settings SET `value` = ? WHERE `key` = 'ai.last_anomaly_scan'",
                [date('Y-m-d H:i:s')]
            );
        } else {
            db_insert('settings', [
                'key'        => 'ai.last_anomaly_scan',
                'value'      => date('Y-m-d H:i:s'),
                'group_name' => 'ai',
            ]);
        }
    } catch (\Throwable) {
        // Non-fatal — settings update failure shouldn't crash the cron
    }

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    echo "{$logPrefix} FATAL: {$e->getMessage()}\n";
    exit(1);
}
