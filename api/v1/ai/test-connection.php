<?php
declare(strict_types=1);

/**
 * api/v1/ai/test-connection.php
 *
 * Tests the Anthropic API connection from Settings → Integrations.
 * Sends a minimal request to verify the API key works.
 *
 * POST /api/v1/ai/test-connection
 *   → { success, message, details }
 *
 * Permission: settings:edit (super_admin only)
 *
 * @depends lib/AI/ClaudeClient.php
 * @session S027
 */

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

// WHY: Only super_admin should test API connections (settings:edit permission)
if (!can('settings', 'edit')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$client = new \FleetForge\AI\ClaudeClient();
$result = $client->testConnection();

// WHY: Log test attempt to audit trail
try {
    db_insert('audit_log', [
        'user_id'     => $_SESSION['ff_user']['id'] ?? null,
        'action'      => 'ai_test_connection',
        'entity_type' => 'settings',
        'entity_id'   => 0,
        'details'     => json_encode(['success' => $result['success'], 'model' => $result['details']['model'] ?? '']),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
} catch (\Throwable) {
    // Audit failure should never block the response
}

echo json_encode($result);
