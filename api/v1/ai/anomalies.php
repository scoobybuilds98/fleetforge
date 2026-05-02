<?php
declare(strict_types=1);

/**
 * api/v1/ai/anomalies.php
 *
 * AI anomaly alerts — list and manage detected anomalies.
 *
 * GET /api/v1/ai/anomalies?unread_only=1&limit=20
 *   → { alerts: [{id, alert_type, severity, title, description, ...}] }
 *
 * POST /api/v1/ai/anomalies/acknowledge
 *   Body: { alert_id: N }
 *   → { success: true }
 *
 * POST /api/v1/ai/anomalies/scan
 *   → { success: true, alerts_created: N }
 *   (Manual trigger — super_admin only)
 *
 * Permission: ai:view (GET), ai:edit (POST acknowledge), settings:edit (POST scan)
 *
 * @depends lib/AI/AnomalyDetector.php
 * @session S027
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_auth_api();

if (!can('ai', 'view')) {
    json_error('FORBIDDEN', 'Forbidden', 403);
}

// ── User-level rate limit (S-PROD-1A) ────────────────────────────────────────
$_rlCheck = \FleetForge\Security\RateLimiter::check(
    'ai:user:' . (int) ($_SESSION['ff_user']['id'] ?? 0),
    (int) settings_get('security.rate_limit.ai_user_threshold', 60),
    (int) settings_get('security.rate_limit.ai_user_window_minutes', 60)
);
if (!$_rlCheck['allowed']) {
    json_error('RATE_LIMITED', 'Too many AI requests. Try again in ' . $_rlCheck['retry_after_seconds'] . ' seconds.', 429);
}
unset($_rlCheck);

header('Content-Type: application/json');

$userId = (int) ($_SESSION['ff_user']['id'] ?? 0);

// ────────────────────────────────────────────────────────────
// GET — list recent alerts
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit      = min((int) ($_GET['limit'] ?? 20), 100);
    $unreadOnly = (bool) ($_GET['unread_only'] ?? false);

    $alerts = \FleetForge\AI\AnomalyDetector::getRecentAlerts($limit, $unreadOnly);

    echo json_encode(['alerts' => $alerts]);
    exit;
}

// ────────────────────────────────────────────────────────────
// POST — acknowledge or trigger scan
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim($body['action'] ?? '');

    // Acknowledge an alert
    if ($action === 'acknowledge' || isset($body['alert_id'])) {
        if (!can('ai', 'edit')) {
            json_error('FORBIDDEN', 'Forbidden', 403);
        }

        $alertId = (int) ($body['alert_id'] ?? 0);
        if ($alertId <= 0) {
            json_error('VALIDATION_ERROR', 'alert_id is required', 400);
        }

        \FleetForge\AI\AnomalyDetector::acknowledgeAlert($alertId, $userId);
        echo json_encode(['success' => true]);
        exit;
    }

    // Trigger a manual scan (super_admin only)
    if ($action === 'scan') {
        if (!can('settings', 'edit')) {
            json_error('FORBIDDEN', 'Only administrators can trigger manual scans', 403);
        }

        $count = \FleetForge\AI\AnomalyDetector::runAll($userId);
        echo json_encode(['success' => true, 'alerts_created' => $count]);
        exit;
    }

    json_error('VALIDATION_ERROR', 'Invalid action. Use "acknowledge" or "scan".', 400);
}

json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
