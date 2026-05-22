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

// S-INTEL-TAB / D-INTEL-2: gate on ai.anomaly_scan_enabled. Independent
// from ai.enabled so operator can pause anomaly scan without touching
// AI chat or morning briefing. Silent exit when 0 — same pattern as
// other AI feature gates.
if ((string) settings_get('ai.anomaly_scan_enabled', '1') !== '1') {
    echo "{$logPrefix} Anomaly scan disabled (ai.anomaly_scan_enabled=0). Skipping.\n";
    exit(0);
}

// Master AI kill switch (consistent with ai_fleet_brief.php). Anomaly
// scan does most of its work via SQL statistical checks, but the
// AnomalyDetector::runAll path can optionally enrich with Claude;
// gating up front keeps the whole pipeline disabled cleanly.
if ((string) settings_get('ai.enabled', '1') !== '1') {
    echo "{$logPrefix} AI disabled (ai.enabled=0). Skipping.\n";
    exit(0);
}

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
