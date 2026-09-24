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
 * @auth        Session + the SAME view gate as the entity's own show page
 *              ($activityGates below). No separate audit:view permission: an
 *              operator who can open the record may see who touched it.
 *              entity_types without a mapping → 403 for every role (fail
 *              closed). Before S-AUDIT-HISTORY-REDACT this only called
 *              require_auth_api(), so any signed-in user could read any
 *              entity's history by editing the query string (KNOWN ISSUE #110).
 * @redaction   Viewers failing can_view_financials() (dispatchers): change
 *              entries on money-named fields (ff_is_money_field) are dropped —
 *              EXCEPT the lease contract-rate fields api/v1/leases/show.php
 *              deliberately serves to them ($historyVisibleMoney, operator
 *              decision 2026-09-17: history must not hide rates the lease page
 *              shows the dispatcher who set them),
 *              non-scalar change values are dropped, and money inside free
 *              text — notes and non-numeric change values — is replaced with
 *              "[amount hidden]" (ff_scrub_money_text).
 *              POLICY (D-AUDIT-HISTORY-REDACT-2): SCRUB, not suppress. Lease
 *              and vendor notes mix operational facts (contract/invoice/unit
 *              numbers, dates, unit moves) with figures in one sentence, so
 *              suppressing notes per financial entity type would either blank
 *              the dispatcher's lease timeline or leave those types leaking.
 * @returns     200 { items: [...], total: int, page: int, per_page: int }
 *
 * @session     S-ACTIVITY-LOG, S-AUDIT-HISTORY-REDACT
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

// ── Authorization (D-AUDIT-HISTORY-REDACT-1) ────────────────────────────────
// entity_type → the view gate of the show page that renders its Activity card
// (every $activityEntityType in app/admin/**/show.php). Mirrors each page's
// require_permission() exactly — including the non-obvious ones: vendors and
// damage claims live under maintenance, portal users under settings, credit
// notes under invoices, and users/show.php is super_admin-only (not a module
// permission). NOT document_entity_module(): that map falls back to equipment
// for unknown types, and this endpoint must fail closed instead. A new show
// page that adds the Activity card must add its type here or the card 403s.
$activityGates = [
    // S-BILLING-MODULE: the billing cycle record page (app/admin/billing/cycle.php).
    'billing_cycle'  => 'invoices',
    'credit_note'    => 'invoices',
    'customer'       => 'customers',
    'damage_claim'   => 'maintenance',
    'equipment_unit' => 'equipment',
    'inspection'     => 'inspections',
    'lease'          => 'leases',
    'payment'        => 'payments',
    'portal_user'    => 'settings',
    'user'           => 'super_admin', // pseudo-module: is_super_admin(), not can()
    'vendor'         => 'maintenance',
    'work_order'     => 'maintenance',
];

if (!isset($activityGates[$entityType])) {
    json_error('FORBIDDEN', 'Activity history is not available for this record type.', 403);
}
if ($activityGates[$entityType] === 'super_admin') {
    if (!is_super_admin()) {
        json_error('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }
} else {
    require_permission($activityGates[$entityType], 'view');
}

// Serve-time money redaction (never cached — computed per request per viewer).
$canSeeMoney = can_view_financials();

// Money-named fields an entity's OWN show API deliberately serves to
// non-financial viewers, so its history shows them too. Lease only:
// api/v1/leases/show.php redacts AR/billing-outcome totals but leaves contract
// rates visible because dispatchers hold leases:create+edit and set them
// (operator decision 2026-09-17). This is the money-named subset of that
// endpoint's dispatcher response — the ASPE accounting columns it never
// returns (initial_fair_value, implicit_rate, residuals…) and its redacted
// totals stay hidden. Other entities get NO exemptions even where their show
// APIs don't redact (work orders, vendors, damage claims): those gaps were
// never audited, and history must not widen them. Drift guard:
// tests/_smoke_audit_history_authz_redaction.php compares this list with a live
// leases/show.php dispatcher response.
$historyVisibleMoney = [
    'lease' => [
        'daily_rate', 'weekly_rate', 'monthly_rate', 'hourly_rate',
        'mileage_rate', 'mileage_rate_km', 'mileage_rate_miles', 'rate_notes',
        'precharge_amount', 'precharge_balance', 'precharge_invoiced_at',
        'discount_type', 'discount_value', 'exchange_rate_to_cad',
        'tax_exempt', 'tax_rate_gst', 'tax_rate_pst', 'tax_rate_hst',
        'cartage_amount', 'gps_cost', 'insurance_cost', 'warranty_cost',
    ],
];
$visibleMoney = array_flip($historyVisibleMoney[$entityType] ?? []);

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

// Scrub one change value for a non-financial viewer. Numeric values pass
// through: their meaning comes from the field name, which ff_is_money_field()
// already vetted (so odometer_start_km "45120.50" survives while a free-text
// reason "paid $1,250.00" is scrubbed).
$scrubValue = static function (mixed $v): mixed {
    return (is_string($v) && !is_numeric($v)) ? ff_scrub_money_text($v) : $v;
};

$items = array_map(static function (array $row) use ($skipKeys, $canSeeMoney, $scrubValue, $visibleMoney): array {
    $changes = [];

    $old = is_string($row['old_values']) ? json_decode($row['old_values'], true) : null;
    $new = is_string($row['new_values']) ? json_decode($row['new_values'], true) : null;

    if (is_array($new)) {
        $oldArr = is_array($old) ? $old : [];
        foreach ($new as $key => $newVal) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            // Money-named fields never reach a non-financial viewer unless the
            // entity's own page already shows them ($visibleMoney).
            // (string) — a JSON list decodes with int keys and strict_types is on.
            $servedVerbatim = isset($visibleMoney[(string) $key]);
            if (!$canSeeMoney && !$servedVerbatim && ff_is_money_field((string) $key)) {
                continue;
            }
            $oldVal = $oldArr[$key] ?? null;
            // Only surface keys that actually changed.
            // Loose comparison handles int/string coercion from JSON.
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($oldVal != $newVal) {
                if (!$canSeeMoney) {
                    // A nested array/object can carry money under keys this loop
                    // never sees (e.g. line items) — fail closed and omit it.
                    if (is_array($oldVal) || is_array($newVal)) {
                        continue;
                    }
                    // Exempt fields go out exactly as the entity's page serves them.
                    if (!$servedVerbatim) {
                        $oldVal = $scrubValue($oldVal);
                        $newVal = $scrubValue($newVal);
                    }
                }
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
        // Free text interpolates figures ("CN-… (CAD 600.00) issued…"); scrub them
        // and keep the operational wording (D-AUDIT-HISTORY-REDACT-2).
        'notes'      => $canSeeMoney ? ($row['notes'] ?? '') : ff_scrub_money_text((string) ($row['notes'] ?? '')),
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
