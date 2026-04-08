<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/index.php
 *
 * GET → list all active email templates grouped by category.
 *
 * Returns the slug + name + subject preview only (no body) for
 * dropdowns and the templates management page list. Use show.php
 * to fetch a single template's full body.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$rows = db_select(
    "SELECT id, slug, name, subject, category, is_active, variables,
            created_at, updated_at
     FROM email_templates
     WHERE deleted_at IS NULL
     ORDER BY category, name"
);

// Decode variables JSON for the UI chips panel
foreach ($rows as &$r) {
    $r['id']        = (int)$r['id'];
    $r['is_active'] = (int)$r['is_active'];
    if (!empty($r['variables'])) {
        $decoded = json_decode((string)$r['variables'], true);
        $r['variables'] = is_array($decoded) ? $decoded : [];
    } else {
        $r['variables'] = [];
    }
}
unset($r);

// Group by category for the management page UI
$grouped = [];
foreach ($rows as $r) {
    $cat = (string)$r['category'];
    if (!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = $r;
}

json_success([
    'templates' => $rows,
    'grouped'   => $grouped,
    'count'     => count($rows),
]);
