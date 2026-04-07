<?php
declare(strict_types=1);

/**
 * api/v1/accounting/categorization-rules/delete.php
 *
 * Delete an auto-categorization rule.
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('accounts_payable','delete')
 * @returns 200 { deleted: true }
 *
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'delete');

$id = clean_int($_POST['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$rule = db_row("SELECT * FROM acc_categorization_rules WHERE id = ?", [$id]);
if (!$rule) json_error('NOT_FOUND', 'Rule not found.', 404);

db_execute("DELETE FROM acc_categorization_rules WHERE id = ?", [$id]);

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'delete',
    'module'      => 'accounting',
    'entity_type' => 'categorization_rule',
    'entity_id'   => $id,
    'notes'       => "Categorization rule '{$rule['name']}' deleted",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true]);
