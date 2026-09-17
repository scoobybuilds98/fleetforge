<?php
declare(strict_types=1);

/**
 * GET /api/v1/training/report[?user_id=N]
 *
 * S-TRAINING-MODULE — team training report, SUPER ADMINS ONLY (operator
 * decision 2026-09-17). Without user_id: one row per active/invited user with
 * chapters started/completed, length-weighted percent and last activity.
 * With user_id: that user's per-chapter breakdown.
 *
 * WHY is_super_admin() and not a permission key: keys are baked into the
 * session at login, so a new key would be invisible until everyone re-logs.
 *
 * @method  GET
 * @auth    super_admin
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Training\TrainingProgress;

require_method('GET');
require_auth_api();
if (!is_super_admin()) {
    json_error('FORBIDDEN', 'The training report is available to super admins only.', 403);
}

$userId = clean_int($_GET['user_id'] ?? null);
if ($userId) {
    $user = db_row("SELECT id, name, email FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        json_error('NOT_FOUND', 'User not found.', 404);
    }
    $catalog = TrainingProgress::catalogFor($userId);
    json_success([
        'user'     => ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']],
        'chapters' => $catalog,
        'summary'  => TrainingProgress::summarize($catalog),
    ]);
}

json_success(['users' => TrainingProgress::teamReport()]);
