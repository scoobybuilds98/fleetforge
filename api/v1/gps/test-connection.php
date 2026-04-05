<?php
declare(strict_types=1);

/**
 * api/v1/gps/test-connection.php
 *
 * Tests the Samsara GPS API connection.
 * Called from Settings → Integrations tab "Test Connection" button.
 *
 * GET /api/v1/gps/test-connection
 *
 * Returns: { "success": true, "data": { "connected": bool, "message": "...", "details": {...} } }
 *
 * Permission: settings:edit (super_admin only)
 *
 * @depends api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session S026
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('settings', 'edit');

use FleetForge\GPS\SamsaraClient;

$gps    = new SamsaraClient();
$result = $gps->testConnection();

// Log the test attempt to audit log
db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'settings',
    'entity_type'  => 'gps_integration',
    'entity_label' => 'Samsara Connection Test',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => $result['success'] ? 'Connection test passed' : 'Connection test failed: ' . $result['message'],
]);

json_success([
    'connected' => $result['success'],
    'message'   => $result['message'],
    'details'   => $result['details'],
]);
