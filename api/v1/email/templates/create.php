<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/create.php
 *
 * POST → create a new email template.
 *
 * Permission: settings.edit (manager+) — templates affect every
 * customer the company contacts so we restrict creation to
 * users with settings access.
 *
 * Body (JSON):
 *   name       string  required
 *   slug       string  optional (auto-generated from name)
 *   subject    string  required
 *   body_html  string  required
 *   body_text  string  optional (auto-stripped from html)
 *   category   string  required (invoice|payment|lease|reminder|compliance|general|collection)
 *   variables  array   optional (list of allowed {var} keys)
 *   is_active  bool    optional (default true)
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$name      = clean_string($body['name']     ?? null, 255);
$slug      = clean_string($body['slug']     ?? null, 100);
$subject   = clean_string($body['subject']  ?? null, 500);
$bodyHtml  = (string)($body['body_html']    ?? '');
$bodyText  = (string)($body['body_text']    ?? '');
$category  = clean_string($body['category'] ?? null, 50) ?: 'general';
$variables = is_array($body['variables'] ?? null) ? $body['variables'] : [];
$isActive  = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

$errors = [];
if (!$name)               $errors['name']      = 'Name is required.';
if (!$subject)            $errors['subject']   = 'Subject is required.';
if (trim($bodyHtml) === '')$errors['body_html']= 'Email body is required.';
$validCategories = ['invoice','payment','lease','reminder','compliance','general','collection'];
if (!in_array($category, $validCategories, true)) {
    $errors['category'] = 'Invalid category.';
}
if (!empty($errors)) {
    json_validation_error($errors);
}

// Auto-generate slug if not provided
if (!$slug) {
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)$name));
    $slug = trim($slug, '_');
}

// De-duplicate slug if it already exists
$base = $slug;
$i = 2;
while (db_row("SELECT id FROM email_templates WHERE slug = ?", [$slug])) {
    $slug = $base . '_' . $i;
    $i++;
}

// Auto-strip text body if not provided
if (trim($bodyText) === '') {
    $bodyText = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml));
}

// Belt-and-suspenders for the concurrent-create race: two requests resolving to
// the same slug both pass the all-rows dedup loop above, then one collides on the
// slug UNIQUE index. Translate that 1062 into a clean 422 instead of an uncaught
// PDOException → HTTP 500. Narrow on 'slug' so the created_by FK / other 23000s surface.
try {
    $id = db_insert('email_templates', [
        'name'       => $name,
        'slug'       => $slug,
        'subject'    => $subject,
        'body_html'  => $bodyHtml,
        'body_text'  => $bodyText,
        'category'   => $category,
        'variables'  => json_encode(array_values(array_unique($variables))),
        'is_active'  => $isActive,
        'created_by' => current_user_id(),
    ]);
} catch (\PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_error('VALIDATION_ERROR', 'A template with the slug ' . $slug . ' already exists.', 422,
            ['fields' => ['slug' => 'This slug is already in use.']]);
    }
    throw $e;
}

try {
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'settings',
        'entity_type' => 'email_template',
        'entity_id'   => $id,
        'description' => 'Created email template: ' . $name . ' (slug: ' . $slug . ')',
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log('[email/templates/create] audit failed: ' . $e->getMessage());
}

json_success(['id' => $id, 'slug' => $slug], 201);
