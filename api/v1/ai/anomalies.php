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

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

if (!can('ai', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

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
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => 'Forbidden']);
            exit;
        }

        $alertId = (int) ($body['alert_id'] ?? 0);
        if ($alertId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => true, 'message' => 'alert_id is required']);
            exit;
        }

        \FleetForge\AI\AnomalyDetector::acknowledgeAlert($alertId, $userId);
        echo json_encode(['success' => true]);
        exit;
    }

    // Trigger a manual scan (super_admin only)
    if ($action === 'scan') {
        if (!can('settings', 'edit')) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => 'Only administrators can trigger manual scans']);
            exit;
        }

        $count = \FleetForge\AI\AnomalyDetector::runAll($userId);
        echo json_encode(['success' => true, 'alerts_created' => $count]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid action. Use "acknowledge" or "scan".']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => true, 'message' => 'Method not allowed']);
