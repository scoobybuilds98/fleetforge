<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_presets.php
 *
 * S-BATCH-INVOICING-2 — save / list / delete named Batch Invoicing presets
 * ("Monthly — all trucking accounts"), so a recurring run is one click
 * instead of re-selecting the same customers every month.
 *
 * Storage: a single JSON row in `settings` (key `invoices.batch_presets`,
 * value_type 'json'). Deliberately NOT a new table — presets are a small,
 * operator-level config blob with no relational queries against it, and
 * `settings.value` is longtext. A migration here would also have to be
 * mirrored into FLEETFORGE_DATABASE_MASTER.sql (D-GUARD-1) for what is
 * effectively a preferences list.
 *
 * A preset stores the SELECTION and OPTIONS, never generated invoice ids:
 * { id, name, customer_ids[], lease_ids[], status_filter, send_email,
 *   attach_pdf, created_by, created_at }
 * Lease ids are re-validated against the chosen period at apply time by
 * batch_eligible.php, so a preset naming a since-closed lease simply
 * doesn't match anything rather than erroring.
 *
 * @method  GET    list all presets
 * @method  POST   { name, customer_ids[], lease_ids[], status_filter,
 *                   send_email, attach_pdf }  → creates (or replaces by name)
 * @method  DELETE { id }  (also accepts POST with _action=delete)
 * @auth    Session required; require_permission('invoices','view') to read,
 *          'create' to modify.
 * @returns 200 { presets: [...] }
 *
 * @session S-BATCH-INVOICING-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET', 'POST', 'DELETE');
require_auth_api();

const FF_BATCH_PRESETS_KEY = 'invoices.batch_presets';

/** @return array<int,array<string,mixed>> */
function ff_batch_presets_load(): array
{
    $raw = settings_get(FF_BATCH_PRESETS_KEY, '');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function ff_batch_presets_save(array $presets): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `updated_by`, `updated_at`)
         VALUES (?, ?, 'json', 'invoices', ?, NOW())
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`),
                                 `updated_by` = VALUES(`updated_by`),
                                 `updated_at` = NOW()",
        [FF_BATCH_PRESETS_KEY, json_encode(array_values($presets), JSON_UNESCAPED_UNICODE), current_user_id()]
    );
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ── Read ────────────────────────────────────────────────────────────
if ($method === 'GET') {
    require_permission('invoices', 'view');
    json_success(['presets' => ff_batch_presets_load()]);
}

require_permission('invoices', 'create');
$body = json_body();

// ── Delete ──────────────────────────────────────────────────────────
if ($method === 'DELETE' || ($body['_action'] ?? '') === 'delete') {
    $id = clean_string($body['id'] ?? null, 64);
    if (!$id) {
        json_error('MISSING_REQUIRED', 'id is required.', 422);
    }
    $presets = ff_batch_presets_load();
    $before  = count($presets);
    $presets = array_values(array_filter($presets, static fn ($p) => (string) ($p['id'] ?? '') !== $id));
    if (count($presets) === $before) {
        json_error('NOT_FOUND', 'Preset not found.', 404);
    }
    ff_batch_presets_save($presets);
    json_success(['presets' => $presets]);
}

// ── Create / replace ────────────────────────────────────────────────
$name = clean_string($body['name'] ?? null, 120);
if (!$name) {
    json_validation_error(['name' => 'Give the preset a name.']);
}

$intList = static function ($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $i = clean_int($v);
        if ($i && $i > 0) $out[] = $i;
    }
    return array_values(array_unique($out));
};

$leaseIds = $intList($body['lease_ids'] ?? null);
if (!$leaseIds) {
    json_validation_error(['lease_ids' => 'Select at least one lease before saving a preset.']);
}
if (count($leaseIds) > 500) {
    json_validation_error(['lease_ids' => 'A preset can hold at most 500 leases.']);
}

$statusFilter = clean_string($body['status_filter'] ?? 'unbilled', 20);
if (!in_array($statusFilter, ['unbilled', 'all', 'billed', 'void'], true)) {
    $statusFilter = 'unbilled';
}

$presets = ff_batch_presets_load();

// Replace by name so re-saving the same preset updates it instead of
// silently creating a second entry with an identical label.
$presets = array_values(array_filter(
    $presets,
    static fn ($p) => strcasecmp((string) ($p['name'] ?? ''), $name) !== 0
));

if (count($presets) >= 50) {
    json_error('VALIDATION_ERROR', 'You already have 50 saved presets — delete one first.', 422);
}

$preset = [
    'id'            => bin2hex(random_bytes(8)),
    'name'          => $name,
    'customer_ids'  => $intList($body['customer_ids'] ?? null),
    'lease_ids'     => $leaseIds,
    'status_filter' => $statusFilter,
    'send_email'    => !empty($body['send_email']),
    'attach_pdf'    => !empty($body['attach_pdf']),
    'created_by'    => current_user()['name'] ?? 'System',
    'created_at'    => date('Y-m-d H:i:s'),
];
$presets[] = $preset;

ff_batch_presets_save($presets);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'System',
    'action'       => 'create',
    'module'       => 'invoices',
    'entity_type'  => 'batch_preset',
    'entity_id'    => null,
    'entity_label' => $name,
    'notes'        => 'Saved Batch Invoicing preset "' . $name . '" (' . count($leaseIds) . ' lease(s)).',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['preset' => $preset, 'presets' => $presets], 201);
