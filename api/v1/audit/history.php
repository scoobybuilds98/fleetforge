<?php
declare(strict_types=1);

/**
 * FleetForge — Entity Activity History
 *
 * @file        api/v1/audit/history.php
 * @description Returns paginated audit_log entries for any entity so show
 *              pages can display a "who changed what and when" activity tab.
 *              Excludes noisy 'view' and 'cron' actions; surfaces everything
 *              a human operator actually did.
 *
 * @method      GET
 * @param       entity_type (string) — audit_log.entity_type value (e.g. 'customer')
 * @param       entity_id   (int)    — primary key of the entity
 * @param       page        (int)    — 1-based page number (default 1)
 * @auth        Any authenticated session — same access gate as the entity's
 *              own show page. No separate audit:view permission needed here
 *              because the operator already sees the entity; showing who
 *              touched it is part of that context.
 * @returns     200 { items: [...], total: int, page: int, per_page: int }
 *
 * @session     S-ACTIVITY-LOG
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

$entityType = preg_replace('/[^a-z_]/', '', strtolower($_GET['entity_type'] ?? ''));
$entityId   = (int) ($_GET['entity_id'] ?? 0);
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 25;

// Optional origin params — set by show pages that know the entity's created_at /
// created_by. When present the API injects a guaranteed "Record created" entry
// at the bottom of the last page if audit_log has no 'create' action for this entity.
$originAt = trim($_GET['origin_at'] ?? '');
$originBy = trim($_GET['origin_by'] ?? '');

if ($entityType === '' || $entityId <= 0) {
    json_error('INVALID_PARAMS', 'entity_type and entity_id are required and must be non-empty.');
}

$offset = ($page - 1) * $perPage;

// Exclude view/cron noise — show only human-initiated actions.
$total = (int) (db_row(
    "SELECT COUNT(*) AS n
       FROM audit_log
      WHERE entity_type = ? AND entity_id = ?
        AND action NOT IN ('view', 'cron')",
    [$entityType, $entityId]
)['n'] ?? 0);

// Determine whether a 'create' entry already exists in audit_log.
// Used below to decide whether to inject the synthetic origin event.
$hasCreateEntry = false;
if ($originAt !== '') {
    $hasCreateEntry = (bool) (db_row(
        "SELECT 1 FROM audit_log
          WHERE entity_type = ? AND entity_id = ? AND action = 'create'
          LIMIT 1",
        [$entityType, $entityId]
    ) ?? false);
}

$rows = db_select(
    "SELECT id, action, user_id, user_name, notes, old_values, new_values, ip_address, created_at
       FROM audit_log
      WHERE entity_type = ? AND entity_id = ?
        AND action NOT IN ('view', 'cron')
      ORDER BY created_at DESC
      LIMIT ? OFFSET ?",
    [$entityType, $entityId, $perPage, $offset]
);

// Parse old_values / new_values into a human-readable changes array.
// We skip internal housekeeping keys (timestamps, updated_by_id style fields)
// that add noise without telling operators anything useful.
$skipKeys = ['updated_at', 'created_at', 'deleted_at', 'updated_by', 'created_by'];

$items = array_map(static function (array $row) use ($skipKeys): array {
    $changes = [];

    $old = is_string($row['old_values']) ? json_decode($row['old_values'], true) : null;
    $new = is_string($row['new_values']) ? json_decode($row['new_values'], true) : null;

    if (is_array($new)) {
        $oldArr = is_array($old) ? $old : [];
        foreach ($new as $key => $newVal) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            $oldVal = $oldArr[$key] ?? null;
            // Only surface keys that actually changed.
            // Loose comparison handles int/string coercion from JSON.
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($oldVal != $newVal) {
                $changes[] = [
                    'field' => $key,
                    'from'  => $oldVal,
                    'to'    => $newVal,
                ];
            }
        }
    }

    return [
        'id'         => (int) $row['id'],
        'action'     => $row['action'],
        'user_name'  => $row['user_name'],
        'user_id'    => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'notes'      => $row['notes'] ?? '',
        'changes'    => $changes,
        'ip_address' => $row['ip_address'] ?? '',
        'created_at' => $row['created_at'],
    ];
}, $rows);

// Inject synthetic "Record created" entry on the last page when:
//   • The caller provided an origin_at datetime
//   • audit_log has no 'create' action for this entity (avoids duplicates)
//   • We are on the last page (oldest events are at the bottom of the timeline)
$isLastPage = ($offset + count($rows)) >= $total;

if ($originAt !== '' && !$hasCreateEntry && $isLastPage) {
    $items[] = [
        'id'         => 0,   // synthetic — no real audit_log row
        'action'     => 'create',
        'user_name'  => $originBy !== '' ? $originBy : 'system',
        'user_id'    => null,
        'notes'      => 'Record created',
        'changes'    => [],
        'ip_address' => '',
        'created_at' => $originAt,
    ];
    // Bump the total so the client's page math stays accurate.
    $total++;
}

json_success([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
