<?php
declare(strict_types=1);

/**
 * GET /api/v1/training
 *
 * S-TRAINING-MODULE — the signed-in user's training course: every active
 * chapter in order with their own resume point / percent / completion, plus
 * a length-weighted course summary.
 *
 * @method  GET
 * @auth    Any signed-in staff user (training is for everyone; no module key,
 *          because new permission keys stay invisible until re-login)
 * @returns { chapters: [...], summary: {chapters, completed, percent, watched_seconds, total_seconds} }
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Training\TrainingProgress;

require_method('GET');
require_auth_api();

$catalog = TrainingProgress::catalogFor((int) current_user_id());
json_success([
    'chapters' => $catalog,
    'summary'  => TrainingProgress::summarize($catalog),
]);
