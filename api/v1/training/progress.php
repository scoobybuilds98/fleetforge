<?php
declare(strict_types=1);

/**
 * POST /api/v1/training/progress
 *
 * S-TRAINING-MODULE — player heartbeat. The page sends the current playback
 * position every ~10s, on pause, on seek and when the tab is hidden; the
 * server derives percent / furthest point / completion (see
 * TrainingProgress::record for why the browser never sends a percent).
 *
 * Body (JSON): video_id INT required, position FLOAT seconds required
 *
 * @method  POST
 * @auth    Any signed-in staff user; always writes the CALLER's own row
 * @returns { position_seconds, max_position_seconds, percent, completed_at }
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Training\TrainingProgress;

require_method('POST');
require_auth_api();

$videoId  = clean_int($_POST['video_id'] ?? null);
$position = $_POST['position'] ?? null;

if (!$videoId) {
    json_error('MISSING_REQUIRED', 'video_id is required.', 422);
}
if (!is_numeric($position) || !is_finite((float) $position)) {
    json_error('INVALID_VALUE', 'position must be a number of seconds.', 422);
}

try {
    json_success(TrainingProgress::record((int) current_user_id(), $videoId, (float) $position));
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}
