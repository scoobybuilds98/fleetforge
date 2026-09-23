<?php
declare(strict_types=1);

/**
 * POST /api/v1/sop/read
 *
 * S-SOP-MODULE — "Mark as read" on an SOP chapter. Records the chapter's
 * CURRENT content hash, so a later edit shows the chapter as "Updated since
 * you read it" (SopReads). Always writes the caller's own row.
 *
 * Body (JSON): slug STRING required, read BOOL required (false = un-mark)
 *
 * @method  POST
 * @auth    Any signed-in staff user
 * @returns { slug, read, hash, read_at }
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Sop\SopReads;

require_method('POST');
require_auth_api();

$slug = (string) preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['slug'] ?? '')));
if ($slug === '' || !array_key_exists('read', $_POST)) {
    json_error('MISSING_REQUIRED', 'slug and read are required.', 422);
}
$read = filter_var($_POST['read'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($read === null) {
    json_error('INVALID_VALUE', 'read must be true or false.', 422);
}

try {
    $state = SopReads::set((int) current_user_id(), $slug, $read);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}

json_success([
    'slug'    => $slug,
    'read'    => $read,
    'hash'    => $state['hash'] ?? null,
    'read_at' => $state['read_at'] ?? null,
]);
