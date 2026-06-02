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

// ── Advisory lock (D21) — prevents two parallel cron ticks from racing
// (double-create alerts / double-spend API tokens). Lock key aligns with
// the ff_cron_<filename> convention. Timeout 0: don't block, exit cleanly.
// (D-ANOMALY-SCAN-LOCK, locked S-CRON-FIX-REMAINING 2026-06-03)
$lock = db_row("SELECT GET_LOCK('ff_cron_ai_anomaly_scan', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    echo "{$logPrefix} Another instance is already running — exiting.\n";
    exit(0);
}

try {
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'intelligence',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'ai_anomaly_scan',
        'notes'        => 'Anomaly scan started',
        'ip_address'   => '127.0.0.1',
    ]);

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

    $duration = round(microtime(true) - $startTime, 2);
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'intelligence',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'ai_anomaly_scan',
        'notes'        => "Anomaly scan completed: {$alertCount} new alert(s) in {$duration}s",
        'ip_address'   => '127.0.0.1',
    ]);

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    echo "{$logPrefix} FATAL: {$e->getMessage()}\n";
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_ai_anomaly_scan')", []);
}
