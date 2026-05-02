<?php
declare(strict_types=1);

/**
 * api/v1/ai/usage.php
 *
 * Returns AI token usage statistics for the admin dashboard.
 *
 * GET /api/v1/ai/usage
 *   → { today: {tokens, cost, requests, limit, remaining},
 *       month: {tokens, cost, requests},
 *       by_user: [{user_id, user_name, tokens, cost, requests}] }
 *
 * Permission: ai:view (all roles with AI access)
 *             by_user breakdown requires settings:view (manager+)
 *
 * @depends lib/AI/TokenTracker.php
 * @session S027
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_auth_api();

if (!can('ai', 'view')) {
    json_error('FORBIDDEN', 'Forbidden', 403);
}

header('Content-Type: application/json');

$userId = $_SESSION['ff_user']['id'] ?? null;

$response = [
    'today' => \FleetForge\AI\TokenTracker::getTodayUsage($userId),
    'month' => \FleetForge\AI\TokenTracker::getMonthUsage(),
];

// WHY: Per-user breakdown only for managers+ who can view settings
if (can('settings', 'view')) {
    $response['by_user'] = \FleetForge\AI\TokenTracker::getUsageByUser();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
