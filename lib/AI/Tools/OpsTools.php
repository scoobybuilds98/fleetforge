<?php
declare(strict_types=1);

namespace FleetForge\AI\Tools;

use FleetForge\Notifications\CustomerReminders;

/**
 * lib/AI/Tools/OpsTools.php
 *
 * Day-to-day operations tools for the AI assistant — S-AI-KNOWLEDGE.
 *
 * WHY: the office asks the assistant operational questions that the core
 * data tools could not answer: "where is unit 5312 / when did it last
 * report?", "which trailers have gone quiet?", "is QuickBooks syncing?",
 * "does this customer have portal access, and which reminder emails would
 * they get?", "what was done on work order WO-0042?". Every answer below is
 * read from what FleetForge has ALREADY stored — the Samsara columns written
 * by cron/samsara_sync.php, the QBO queue/log/drift tables, the Customer
 * Emails settings — so a chat question never calls Samsara or QuickBooks and
 * never spends their rate limits.
 *
 * Tools:
 *   get_unit_location              — last Samsara report for one unit, or the "not reporting" list
 *   get_quickbooks_status          — connection + queue + recent errors + open drift (+ one invoice's link)
 *   get_customer_portal_and_emails — portal users + the 3-switch customer-email gate + recent emails
 *   get_work_order_details         — one maintenance work order with its parts/labor lines
 *
 * Rules every handler follows:
 *   - READ-ONLY (SELECT / settings_get only; CustomerReminders gate helpers are SELECT-only).
 *   - Each tool checks its own module permission (the chat endpoint only checks ai:view)
 *     and names the roles that can see it when it refuses.
 *   - Money only when can_view_financials(); never tokens, secrets, password hashes,
 *     invite/reset tokens, request/response payloads.
 *   - DATETIME columns are UTC; "N days ago" is computed against ff_now_utc(), never CURDATE().
 *   - Lists are capped (LIST_CAP) and carry a total count so the model can say "and 12 more".
 *
 * @depends includes/db.php (db_select, db_row, db_count), includes/auth.php (can, can_view_financials),
 *          includes/functions.php (settings_get, ff_now_utc, ff_today, format_datetime, cron_enabled),
 *          lib/Notifications/CustomerReminders.php, config/customer_notifications.php, config/permissions.php
 * @session S-AI-KNOWLEDGE
 */
final class OpsTools
{
    /** Max rows in any list a tool returns (token budget); totals are always reported. */
    private const LIST_CAP = 50;

    /** Longest error / description text passed back per row (chars). */
    private const TEXT_CAP = 300;

    /** Tool names this module owns. */
    private const TOOLS = [
        'get_unit_location',
        'get_quickbooks_status',
        'get_customer_portal_and_emails',
        'get_work_order_details',
    ];

    // ────────────────────────────────────────────────────────────
    // Module contract (see FleetForgeTools::MODULES)
    // ────────────────────────────────────────────────────────────

    /** Anthropic tool definitions (+ internal _tags) for this module. */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'get_unit_location',
                'description' => 'Where a unit is and when it last reported to Samsara GPS, from the last stored sync (no live call). Use for "where is unit X", "when did X last report / check in", "is X moving", "what is X\'s odometer", or "which units are not reporting / offline / gone quiet". Give unit_id or unit_number for one unit (returns last location, address, speed, odometer, battery, how long ago it reported, current lease/customer, and recent breadcrumbs). Give mode="not_reporting" (optionally stale_days, default 3) to list Samsara-linked units whose last report is older than that.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit_id'       => ['type' => 'integer', 'description' => 'equipment_units.id'],
                        'unit_number'   => ['type' => 'string', 'description' => 'Unit number as staff say it (e.g. "5312" or "CHS-104"). Partial numbers return candidates.'],
                        'mode'          => ['type' => 'string', 'enum' => ['unit', 'not_reporting'], 'description' => "'unit' (default) = one unit; 'not_reporting' = list units whose last Samsara report is older than stale_days."],
                        'stale_days'    => ['type' => 'integer', 'description' => 'not_reporting mode: how many days without a report counts as not reporting (1-365, default 3).'],
                        'history_limit' => ['type' => 'integer', 'description' => 'unit mode: how many recent GPS breadcrumbs to include (0-20, default 5).'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_quickbooks_status',
                'description' => 'QuickBooks Online sync health, read from FleetForge\'s own sync tables (no call to QuickBooks). Use for "is QuickBooks connected / syncing", "why didn\'t X reach QuickBooks", "any QuickBooks errors", "what is stuck in the sync queue", "any drift between FleetForge and QuickBooks", or "is invoice INV-… in QuickBooks". Returns connection state (never tokens), master switches, queue counts by status with the oldest waiting item, recent failed API calls, open drift events, and — when an invoice is given — that invoice\'s QuickBooks link and push status.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id'     => ['type' => 'integer', 'description' => 'Optional FleetForge invoices.id to show its QuickBooks link.'],
                        'invoice_number' => ['type' => 'string', 'description' => 'Optional FleetForge invoice number (e.g. "INV-2026-01993") to show its QuickBooks link.'],
                        'errors_limit'   => ['type' => 'integer', 'description' => 'How many recent failed API calls to list (1-25, default 10).'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_customer_portal_and_emails',
                'description' => 'One customer\'s customer-portal access and automatic customer emails. Use for "does X have portal access / when did they last log in / was their invite accepted", "why didn\'t X get a reminder / statement / receipt", "which emails will X receive", "is X on the do-not-email list", or "what emails did we send X recently". Returns portal users (status, last login, opt-outs), each reminder type with whether it would reach this customer and why not (master switch, type switch, audience/do-not-email/portal opt-out, missing or bounced address), and the most recent customer emails with status. Find customer_id with search_customers first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'customers.id'],
                        'email_limit' => ['type' => 'integer', 'description' => 'How many recent emails to list (1-25, default 10).'],
                    ],
                    'required' => ['customer_id'],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'get_work_order_details',
                'description' => 'Full detail of ONE maintenance work order: unit, vendor, type, priority, status, requested/scheduled/completed dates, description and notes, who created/is assigned/completed it, and its parts & labor lines (costs only for users allowed to see money). Use for "what was done on WO-…", "what parts went on work order …", "who is working on …", "why is this work order still open". For counts or lists of work orders use get_maintenance_summary instead.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'work_order_id'     => ['type' => 'integer', 'description' => 'maintenance_work_orders.id'],
                        'work_order_number' => ['type' => 'string', 'description' => 'Work order number as shown on screen. Partial numbers return candidates.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
        ];
    }

    /** Does this module own $toolName? */
    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::TOOLS, true);
    }

    /** Dispatch one tool call (read-only). */
    public static function run(string $toolName, array $input, ?int $userId = null, ?int $sessionId = null): mixed
    {
        return match ($toolName) {
            'get_unit_location'              => self::getUnitLocation($input, $userId),
            'get_quickbooks_status'          => self::getQuickbooksStatus($input, $userId),
            'get_customer_portal_and_emails' => self::getCustomerPortalAndEmails($input, $userId),
            'get_work_order_details'         => self::getWorkOrderDetails($input, $userId),
        };
    }

    // ════════════════════════════════════════════════════════════
    //  get_unit_location
    // ════════════════════════════════════════════════════════════

    /**
     * One unit's last stored Samsara report, or the "not reporting" list.
     *
     * Canonical Samsara identity is samsara_vehicle_id + samsara_entity_type;
     * gps_device_id is a dead legacy column and is never read here.
     *
     * @param  array    $input  unit_id | unit_number | mode | stale_days | history_limit
     * @param  int|null $userId null = system caller (no permission gate)
     * @return array
     */
    public static function getUnitLocation(array $input, ?int $userId): array
    {
        if ($denied = self::deny($userId, 'equipment', 'view', 'unit locations')) {
            return $denied;
        }

        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        $hasUnit = (int) ($input['unit_id'] ?? 0) > 0 || trim((string) ($input['unit_number'] ?? '')) !== '';
        // WHY: "which units aren't reporting" arrives with no unit at all, and the
        // model sometimes sends only stale_days — treat that as the list mode too.
        if ($mode === 'not_reporting' || (!$hasUnit && isset($input['stale_days']))) {
            return self::unitsNotReporting($input);
        }
        if (!$hasUnit) {
            return ['error' => true, 'message' => 'Give unit_id or unit_number for one unit, or mode="not_reporting" to list units that have stopped reporting.'];
        }

        $unit = self::resolveUnit($input);
        if (isset($unit['error']) || isset($unit['candidates'])) {
            return $unit;
        }

        $linked = trim((string) ($unit['samsara_vehicle_id'] ?? '')) !== '';
        $lease  = self::currentLease((int) $unit['id']);

        $out = [
            'unit' => [
                'id'          => (int) $unit['id'],
                'unit_number' => $unit['unit_number'],
                'status'      => $unit['status'],
                'type'        => $unit['template_name'],
                'yard'        => $unit['yard_location'],
            ],
            'current_lease' => $lease,
            'samsara' => [
                'linked' => $linked,
            ],
            'url' => base_url('equipment/show?id=' . (int) $unit['id']),
        ];

        if (!$linked) {
            $out['samsara']['note'] = 'This unit is not linked to Samsara, so FleetForge has no GPS location for it. Link it on the unit page (Tracking) or in Settings → Samsara.';
            return $out;
        }

        $last = $unit['samsara_last_connected_at'];
        $out['samsara'] += [
            'entity_type'          => $unit['samsara_entity_type'],
            'samsara_name'         => $unit['samsara_vehicle_name'],
            'last_reported_at_utc' => $last,
            'last_reported_local'  => $last ? \format_datetime($last) : null,
            'last_reported'        => self::ago($last),
            'reporting'            => self::freshness($last),
            'last_location' => $unit['samsara_last_location_lat'] === null ? null : [
                'lat'     => $unit['samsara_last_location_lat'],
                'lng'     => $unit['samsara_last_location_lng'],
                'address' => $unit['samsara_last_location_address'],
                'map'     => 'https://maps.google.com/?q=' . $unit['samsara_last_location_lat'] . ',' . $unit['samsara_last_location_lng'],
            ],
            'speed_kph'   => $unit['samsara_last_speed_kph'],
            'odometer_km' => $unit['samsara_odometer_km'],
            // WHY bcmath: the codebase never floats a stored decimal; 1 km = 0.621371 mi.
            'odometer_mi' => $unit['samsara_odometer_km'] !== null
                ? bcmul((string) $unit['samsara_odometer_km'], '0.621371', 0)
                : null,
            'battery_pct'   => $unit['samsara_battery_pct'] !== null ? (int) $unit['samsara_battery_pct'] : null,
            'power_source'  => $unit['samsara_power_source'],
            'check_in_mode' => $unit['samsara_check_in_mode'],
            // last_synced = FleetForge's own cron tick; distinct from when the
            // GATEWAY last reported. A fresh sync with an old report = the device is quiet.
            'last_synced_by_fleetforge' => self::ago($unit['samsara_last_synced_at']),
            'sync_cron_enabled'         => \cron_enabled('samsara_sync'),
        ];

        // Recent breadcrumbs — filtered to the CURRENT mapping so a unit that was
        // re-linked never shows the previous device's trail.
        $historyLimit = max(0, min(20, (int) ($input['history_limit'] ?? 5)));
        if ($historyLimit > 0) {
            $crumbs = \db_select(
                "SELECT recorded_at, latitude, longitude, speed_kph, address
                   FROM samsara_location_history
                  WHERE equipment_unit_id = ? AND samsara_vehicle_id = ?
                  ORDER BY recorded_at DESC
                  LIMIT {$historyLimit}",
                [(int) $unit['id'], (string) $unit['samsara_vehicle_id']]
            );
            $out['recent_history'] = array_map(static fn (array $r): array => [
                'at_local'  => \format_datetime($r['recorded_at']),
                'ago'       => self::ago($r['recorded_at']),
                'lat'       => $r['latitude'],
                'lng'       => $r['longitude'],
                'speed_kph' => $r['speed_kph'],
                'address'   => $r['address'],
            ], $crumbs);
        }

        return $out;
    }

    /**
     * Samsara-linked units whose last report is older than stale_days (or never).
     * Decommissioned units are excluded — nobody expects them to report.
     */
    private static function unitsNotReporting(array $input): array
    {
        $days   = max(1, min(365, (int) ($input['stale_days'] ?? 3)));
        // WHY ff_now_utc(): samsara_last_connected_at is a UTC DATETIME; a PHP
        // local-time cutoff would shift the window by 7–8 hours.
        $cutoff = \ff_now_utc("-{$days} days");

        $where = "eu.deleted_at IS NULL
                  AND eu.samsara_vehicle_id IS NOT NULL AND eu.samsara_vehicle_id <> ''
                  AND eu.status <> 'decommissioned'
                  AND (eu.samsara_last_connected_at IS NULL OR eu.samsara_last_connected_at < ?)";

        $total  = \db_count("SELECT COUNT(*) FROM equipment_units eu WHERE {$where}", [$cutoff]);
        $linked = \db_count(
            "SELECT COUNT(*) FROM equipment_units
              WHERE deleted_at IS NULL AND samsara_vehicle_id IS NOT NULL AND samsara_vehicle_id <> ''
                AND status <> 'decommissioned'"
        );

        $cap  = self::LIST_CAP;
        $rows = \db_select(
            "SELECT eu.id, eu.unit_number, eu.status, eu.samsara_entity_type,
                    eu.samsara_last_connected_at, eu.samsara_last_location_address,
                    eu.samsara_battery_pct,
                    l.contract_number, c.company_name
               FROM equipment_units eu
               LEFT JOIN leases l
                      ON l.id = (SELECT l2.id FROM leases l2
                                  WHERE l2.equipment_unit_id = eu.id AND l2.status = 'active'
                                    AND l2.deleted_at IS NULL
                                  ORDER BY l2.start_date DESC LIMIT 1)
               LEFT JOIN customers c ON c.id = l.customer_id
              WHERE {$where}
              ORDER BY eu.samsara_last_connected_at IS NULL DESC, eu.samsara_last_connected_at ASC
              LIMIT {$cap}",
            [$cutoff]
        );

        return [
            'stale_days'            => $days,
            'samsara_linked_units'  => $linked,
            'not_reporting_total'   => $total,
            'showing'               => count($rows),
            'units' => array_map(static fn (array $r): array => [
                'id'            => (int) $r['id'],
                'unit_number'   => $r['unit_number'],
                'status'        => $r['status'],
                'entity_type'   => $r['samsara_entity_type'],
                'last_reported' => $r['samsara_last_connected_at'] ? self::ago($r['samsara_last_connected_at']) : 'never',
                'last_address'  => $r['samsara_last_location_address'],
                'battery_pct'   => $r['samsara_battery_pct'] !== null ? (int) $r['samsara_battery_pct'] : null,
                'on_lease_to'   => $r['company_name'],
                'lease'         => $r['contract_number'],
            ], $rows),
            'sync_cron_enabled' => \cron_enabled('samsara_sync'),
            'note' => 'Excludes decommissioned units. "Last reported" is when the Samsara gateway last checked in (stored by the sync), not a live reading. The Tracking page lists the same units.',
        ];
    }

    /**
     * Resolve a unit by id or number. Exact number first; a partial number
     * returns up to 10 candidates instead of guessing.
     *
     * @return array unit row | ['candidates'=>…] | ['error'=>true,…]
     */
    private static function resolveUnit(array $input): array
    {
        $select = "SELECT eu.id, eu.unit_number, eu.status, eu.yard_location,
                          eu.samsara_vehicle_id, eu.samsara_entity_type, eu.samsara_vehicle_name,
                          eu.samsara_last_location_lat, eu.samsara_last_location_lng,
                          eu.samsara_last_location_address, eu.samsara_last_speed_kph,
                          eu.samsara_last_connected_at, eu.samsara_last_synced_at,
                          eu.samsara_odometer_km, eu.samsara_battery_pct,
                          eu.samsara_power_source, eu.samsara_check_in_mode,
                          et.name AS template_name
                     FROM equipment_units eu
                     LEFT JOIN equipment_templates et ON et.id = eu.template_id";

        $id = (int) ($input['unit_id'] ?? 0);
        if ($id > 0) {
            $row = \db_row("{$select} WHERE eu.id = ? AND eu.deleted_at IS NULL", [$id]);
            return $row ?? ['error' => true, 'message' => "No equipment unit found with ID {$id}."];
        }

        $number = trim((string) ($input['unit_number'] ?? ''));
        $row = \db_row("{$select} WHERE eu.unit_number = ? AND eu.deleted_at IS NULL", [$number]);
        if ($row) {
            return $row;
        }
        $matches = \db_select(
            "SELECT id, unit_number, status FROM equipment_units
              WHERE unit_number LIKE ? AND deleted_at IS NULL
              ORDER BY unit_number LIMIT 10",
            ['%' . $number . '%']
        );
        if (count($matches) === 1) {
            return \db_row("{$select} WHERE eu.id = ?", [(int) $matches[0]['id']]) ?? [];
        }
        if ($matches === []) {
            return ['error' => true, 'message' => "No equipment unit found with number \"{$number}\". Try search_equipment."];
        }
        return [
            'candidates' => $matches,
            'message'    => "Several units match \"{$number}\" — ask which one, or call again with unit_id.",
        ];
    }

    /** The unit's active lease (contract + customer), or null when off lease. */
    private static function currentLease(int $unitId): ?array
    {
        $row = \db_row(
            "SELECT l.id, l.contract_number, l.start_date, l.end_date, c.id AS customer_id, c.company_name
               FROM leases l
               LEFT JOIN customers c ON c.id = l.customer_id
              WHERE l.equipment_unit_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
              ORDER BY l.start_date DESC
              LIMIT 1",
            [$unitId]
        );
        if (!$row) {
            return null;
        }
        return [
            'lease_id'    => (int) $row['id'],
            'contract'    => $row['contract_number'],
            'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'customer'    => $row['company_name'],
            'start_date'  => $row['start_date'],
            'end_date'    => $row['end_date'],
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  get_quickbooks_status
    // ════════════════════════════════════════════════════════════

    /**
     * QBO sync health from local tables — mirrors the read half of
     * api/v1/quickbooks/dashboard_metrics.php. Settings are read through an
     * explicit ALLOWLIST so no token / client secret / webhook verifier can leak.
     *
     * @param  array    $input  invoice_id | invoice_number | errors_limit
     * @param  int|null $userId
     * @return array
     */
    public static function getQuickbooksStatus(array $input, ?int $userId): array
    {
        if ($denied = self::deny($userId, 'quickbooks', 'view', 'QuickBooks sync status')) {
            return $denied;
        }
        $money = self::money($userId);

        // ── Connection (allowlisted, non-secret settings only) ──
        $s = static fn (string $k, string $d = ''): string => (string) \settings_get('quickbooks.' . $k, $d);
        $refreshExp = $s('refresh_token_expires_at');
        $refreshDays = null;
        if ($refreshExp !== '' && ($ts = strtotime($refreshExp)) !== false) {
            $refreshDays = (int) floor(($ts - time()) / 86400);
        }
        $connection = [
            'status'                        => $s('connection_status', 'disconnected'),
            'environment'                   => $s('environment', 'sandbox'),
            'connection_error'              => $s('connection_error') ?: null,
            'realm_mismatch'                => $s('realm_mismatch', '0') === '1',
            'last_connected'                => self::agoIso($s('last_connected_at')),
            'last_auth_renewal'             => self::agoIso($s('last_token_refresh_at')),
            'must_reconnect_in_days'        => $refreshDays,
            'sync_enabled'                  => $s('sync_enabled', '0') === '1',
            'dry_run_mode'                  => $s('dry_run_mode', '0') === '1',
            'go_live_cutover'               => self::agoIso($s('cutover_at')),
            'last_drift_check'              => self::agoIso($s('drift.last_check_at')),
        ];

        // ── Queue ────────────────────────────────────────────────
        $queue = ['queued' => 0, 'processing' => 0, 'failed' => 0, 'skipped' => 0, 'completed' => 0];
        foreach (\db_select("SELECT status, COUNT(*) AS n FROM acc_qbo_sync_queue GROUP BY status") as $r) {
            $queue[$r['status']] = (int) $r['n'];
        }
        $oldest = \db_row(
            "SELECT entity_type, entity_id, operation, enqueued_at, retry_count, next_retry_at, error_message
               FROM acc_qbo_sync_queue
              WHERE status IN ('queued','processing')
              ORDER BY enqueued_at ASC, id ASC
              LIMIT 1"
        );
        $waitingByType = \db_select(
            "SELECT entity_type, COUNT(*) AS n FROM acc_qbo_sync_queue
              WHERE status IN ('queued','processing')
              GROUP BY entity_type ORDER BY n DESC"
        );
        $failedItems = \db_select(
            "SELECT id, entity_type, entity_id, operation, retry_count, error_code, error_message, completed_at, enqueued_at
               FROM acc_qbo_sync_queue
              WHERE status = 'failed'
              ORDER BY COALESCE(completed_at, enqueued_at) DESC
              LIMIT 10"
        );

        // ── Recent failed API calls (no payloads — they carry customer data/amounts) ──
        $errLimit = max(1, min(25, (int) ($input['errors_limit'] ?? 10)));
        $errWhere = "(response_status IS NULL OR response_status < 200 OR response_status >= 300 OR error_message IS NOT NULL)";
        $errors = \db_select(
            "SELECT created_at, direction, entity_type, entity_id, qbo_entity_id, operation,
                    response_status, error_code, error_message
               FROM acc_qbo_sync_log
              WHERE {$errWhere}
              ORDER BY created_at DESC, id DESC
              LIMIT {$errLimit}"
        );
        $errors24h = \db_count(
            "SELECT COUNT(*) FROM acc_qbo_sync_log WHERE created_at >= ? AND {$errWhere}",
            [\ff_now_utc('-24 hours')]
        );
        $calls24h = \db_count(
            "SELECT COUNT(*) FROM acc_qbo_sync_log WHERE created_at >= ?",
            [\ff_now_utc('-24 hours')]
        );

        // ── Open drift ───────────────────────────────────────────
        $driftOpen = \db_count("SELECT COUNT(*) FROM acc_qbo_drift_events WHERE resolved_at IS NULL");
        $driftByCat = [];
        foreach (\db_select(
            "SELECT category, COUNT(*) AS n FROM acc_qbo_drift_events
              WHERE resolved_at IS NULL GROUP BY category ORDER BY n DESC"
        ) as $r) {
            $driftByCat[$r['category']] = (int) $r['n'];
        }
        $driftRows = \db_select(
            "SELECT id, detected_at, detection_source, category, entity_type, entity_id,
                    qbo_entity_id, drift_amount, description
               FROM acc_qbo_drift_events
              WHERE resolved_at IS NULL
              ORDER BY detected_at DESC
              LIMIT 10"
        );

        $out = [
            'connection' => $connection,
            'queue' => [
                'by_status'        => $queue,
                'waiting_by_type'  => array_column($waitingByType, 'n', 'entity_type'),
                'oldest_waiting'   => $oldest ? [
                    'entity'      => $oldest['entity_type'] . ' #' . $oldest['entity_id'],
                    'operation'   => $oldest['operation'],
                    'waiting'     => self::ago($oldest['enqueued_at']),
                    'retry_count' => (int) $oldest['retry_count'],
                    'last_error'  => self::clip($oldest['error_message']),
                ] : null,
                'failed_items' => array_map(static fn (array $r): array => [
                    'queue_id'  => (int) $r['id'],
                    'entity'    => $r['entity_type'] . ' #' . $r['entity_id'],
                    'operation' => $r['operation'],
                    'retries'   => (int) $r['retry_count'],
                    'error'     => self::clip(trim(($r['error_code'] ? '[' . $r['error_code'] . '] ' : '') . (string) $r['error_message'])),
                    'when'      => self::ago($r['completed_at'] ?? $r['enqueued_at']),
                ], $failedItems),
            ],
            'api_calls_24h' => ['total' => $calls24h, 'errors' => $errors24h],
            'recent_errors' => array_map(static fn (array $r): array => [
                'when'      => self::ago($r['created_at']),
                'at_local'  => \format_datetime($r['created_at']),
                'direction' => $r['direction'],
                'entity'    => $r['entity_type'] . ($r['entity_id'] !== null ? ' #' . $r['entity_id'] : ''),
                'qbo_id'    => $r['qbo_entity_id'],
                'operation' => $r['operation'],
                'http'      => $r['response_status'] !== null ? (int) $r['response_status'] : null,
                'error'     => self::clip(trim(($r['error_code'] ? '[' . $r['error_code'] . '] ' : '') . (string) $r['error_message'])),
            ], $errors),
            'drift' => [
                'open'        => $driftOpen,
                'by_category' => $driftByCat,
                'open_events' => array_map(static function (array $r) use ($money): array {
                    $row = [
                        'id'          => (int) $r['id'],
                        'detected'    => self::ago($r['detected_at']),
                        'source'      => $r['detection_source'],
                        'category'    => $r['category'],
                        'entity'      => $r['entity_type'] . ($r['entity_id'] !== null ? ' #' . $r['entity_id'] : ''),
                        'description' => self::clip($r['description']),
                    ];
                    if ($money) {
                        $row['drift_amount'] = $r['drift_amount'];
                    }
                    return $row;
                }, $driftRows),
            ],
            'url'  => base_url('quickbooks/dashboard'),
            'note' => 'Read from FleetForge\'s stored sync state; nothing was fetched from QuickBooks. Queue "failed" = gave up after retries; "skipped" = intentionally not pushed.',
        ];

        // ── One invoice's QuickBooks link (optional) ─────────────
        $invId  = (int) ($input['invoice_id'] ?? 0);
        $invNum = trim((string) ($input['invoice_number'] ?? ''));
        if ($invId > 0 || $invNum !== '') {
            $out['invoice'] = self::invoiceQboLink($invId, $invNum, $money);
        }

        return $out;
    }

    /** One FleetForge invoice's acc_qbo_invoice_map row + its queue/log/drift trail. */
    private static function invoiceQboLink(int $invId, string $invNum, bool $money): array
    {
        $inv = $invId > 0
            ? \db_row("SELECT id, invoice_number, status, total_amount, currency FROM invoices WHERE id = ? AND deleted_at IS NULL", [$invId])
            : \db_row("SELECT id, invoice_number, status, total_amount, currency FROM invoices WHERE invoice_number = ? AND deleted_at IS NULL", [$invNum]);
        if (!$inv) {
            return ['error' => true, 'message' => 'No invoice found with ' . ($invId > 0 ? "ID {$invId}" : "number \"{$invNum}\"") . '.'];
        }
        $id  = (int) $inv['id'];
        $map = \db_row(
            "SELECT qbo_invoice_id, qbo_doc_number, qbo_total_amt, qbo_balance, qbo_status, qbo_currency,
                    ff_invoice_snapshot_total, origin, link_method, linked_at,
                    push_status, push_error, pushed_at, last_synced_at
               FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$id]
        );

        $out = [
            'invoice_id'     => $id,
            'invoice_number' => $inv['invoice_number'],
            'ff_status'      => $inv['status'],
        ];
        if ($money) {
            $out['ff_total'] = $inv['total_amount'] . ' ' . $inv['currency'];
        }

        if (!$map) {
            // WHY this wording: drafts never push (FleetForge pushes on send), and
            // pre-go-live invoices are LINKED, not pushed — the two usual reasons.
            $out['in_quickbooks'] = false;
            $out['note'] = $inv['status'] === 'draft'
                ? 'Not in QuickBooks — drafts are pushed only when the invoice is sent.'
                : 'No QuickBooks link recorded for this invoice yet. Check the queue/errors below, and whether QuickBooks sync is on.';
        } else {
            $out['in_quickbooks'] = $map['qbo_invoice_id'] !== null;
            $out['qbo'] = [
                'qbo_invoice_id' => $map['qbo_invoice_id'],
                'qbo_doc_number' => $map['qbo_doc_number'],
                'push_status'    => $map['push_status'],
                'push_error'     => self::clip($map['push_error']),
                'origin'         => $map['origin'] === 'cutover_link' ? 'linked to an existing QuickBooks invoice at go-live' : 'created in QuickBooks by FleetForge',
                'link_method'    => $map['link_method'],
                'pushed'         => self::ago($map['pushed_at']),
                'last_synced'    => self::ago($map['last_synced_at']),
                'qbo_status'     => $map['qbo_status'],
            ];
            if ($money) {
                $out['qbo'] += [
                    'qbo_total'   => $map['qbo_total_amt'],
                    'qbo_balance' => $map['qbo_balance'],
                    'qbo_currency'=> $map['qbo_currency'],
                    'ff_total_at_push' => $map['ff_invoice_snapshot_total'],
                ];
            }
        }

        $out['queue_rows'] = array_map(static fn (array $r): array => [
            'operation' => $r['operation'],
            'status'    => $r['status'],
            'queued'    => self::ago($r['enqueued_at']),
            'error'     => self::clip($r['error_message']),
        ], \db_select(
            "SELECT operation, status, enqueued_at, error_message FROM acc_qbo_sync_queue
              WHERE entity_type = 'invoice' AND entity_id = ?
              ORDER BY enqueued_at DESC LIMIT 5",
            [$id]
        ));
        $out['recent_api_calls'] = array_map(static fn (array $r): array => [
            'when'      => self::ago($r['created_at']),
            'operation' => $r['operation'],
            'http'      => $r['response_status'] !== null ? (int) $r['response_status'] : null,
            'error'     => self::clip($r['error_message']),
        ], \db_select(
            "SELECT created_at, operation, response_status, error_message FROM acc_qbo_sync_log
              WHERE entity_type = 'invoice' AND entity_id = ?
              ORDER BY created_at DESC, id DESC LIMIT 5",
            [$id]
        ));
        $out['open_drift'] = array_map(static fn (array $r): array => [
            'category'    => $r['category'],
            'detected'    => self::ago($r['detected_at']),
            'description' => self::clip($r['description']),
        ], \db_select(
            "SELECT category, detected_at, description FROM acc_qbo_drift_events
              WHERE entity_type = 'invoice' AND entity_id = ? AND resolved_at IS NULL
              ORDER BY detected_at DESC LIMIT 5",
            [$id]
        ));

        return $out;
    }

    // ════════════════════════════════════════════════════════════
    //  get_customer_portal_and_emails
    // ════════════════════════════════════════════════════════════

    /**
     * Portal users + the Customer Emails gate for one customer + recent emails.
     *
     * The gate is evaluated with CustomerReminders' own helpers (mayEmailCustomer,
     * config, available) so this tool can never disagree with what the senders
     * actually do: master switch → type switch → do-not-email list → audience /
     * portal opt-out → a valid, non-bounced address. The cron that sends each
     * type has its own on/off (Settings → Scheduled Jobs), reported alongside.
     *
     * @param  array    $input  customer_id | email_limit
     * @param  int|null $userId
     * @return array
     */
    public static function getCustomerPortalAndEmails(array $input, ?int $userId): array
    {
        if ($denied = self::deny($userId, 'customers', 'view', 'customer portal access and emails')) {
            return $denied;
        }
        $cid = (int) ($input['customer_id'] ?? 0);
        if ($cid <= 0) {
            return ['error' => true, 'message' => 'customer_id is required — find it with search_customers.'];
        }

        $c = \db_row(
            "SELECT id, company_name, status, email, billing_email, invoice_email, invoice_delivery,
                    email_disabled, email_disabled_reason
               FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$cid]
        );
        if (!$c) {
            return ['error' => true, 'message' => "No customer found with ID {$cid}."];
        }

        // ── Portal users — explicit column list: never password_hash, invite_token,
        //    password_reset_token, auth0_sub or last_login_ip. ──
        $nowUtc = \ff_now_utc();
        $portal = array_map(static function (array $u) use ($nowUtc): array {
            $prefs = json_decode((string) ($u['notification_preferences'] ?? ''), true);
            $optedOut = is_array($prefs)
                ? array_keys(array_filter($prefs, static fn ($v): bool => $v === false))
                : [];
            return [
                'id'             => (int) $u['id'],
                'name'           => $u['name'],
                'email'          => $u['email'],
                'status'         => $u['status'],
                'primary'        => (int) $u['is_primary'] === 1,
                'last_login'     => $u['last_login_at'] ? self::ago($u['last_login_at']) : 'never',
                'invite_sent'    => self::ago($u['invite_sent_at']),
                'invite_expired' => $u['status'] === 'invited' && $u['invite_token_expiry'] !== null
                    ? $u['invite_token_expiry'] < $nowUtc : null,
                'locked'         => $u['locked_until'] !== null && $u['locked_until'] > $nowUtc,
                'email_disabled' => (int) $u['email_disabled'] === 1 ? ($u['email_disabled_reason'] ?: 'yes') : false,
                'opted_out_of'   => $optedOut,
            ];
        }, \db_select(
            "SELECT id, name, email, status, is_primary, last_login_at, invite_sent_at,
                    invite_token_expiry, locked_until, email_disabled, email_disabled_reason,
                    notification_preferences
               FROM portal_users WHERE customer_id = ?
              ORDER BY is_primary DESC, id ASC",
            [$cid]
        ));

        // ── Customer Emails gate, per reminder type ──
        $types = [];
        $onDoNotEmail = false;
        foreach (CustomerReminders::registry() as $key => $meta) {
            $key = (string) $key;
            if (!CustomerReminders::available($key)) {
                continue;
            }
            $cfg  = CustomerReminders::config($key);
            $gate = CustomerReminders::mayEmailCustomer($key, $cid, true);
            if (str_contains($gate['reason'], 'do-not-email')) {
                $onDoNotEmail = true;
            }
            $types[] = [
                'type'          => $key,
                'label'         => $cfg['label'],
                'type_enabled'  => $cfg['enabled'],
                'audience_mode' => $cfg['audience_mode'],
                'channels'      => $cfg['channels'],
                'would_send'    => $gate['ok'],
                'to'            => $gate['to'],
                'why_not'       => $gate['ok'] ? null : $gate['reason'],
            ];
        }
        if (!$onDoNotEmail) {
            // The master-off reason short-circuits before the list is checked,
            // so read the global suppression row directly as well.
            $onDoNotEmail = \db_count(
                "SELECT COUNT(*) FROM customer_notification_audience
                  WHERE reminder_key = '*' AND mode = 'exclude' AND customer_id = ?",
                [$cid]
            ) > 0;
        }

        // ── Recent emails — two logs:
        //    email_logs (EmailService: invoices, statements, compose) keyed by customer_id;
        //    notification_log (reminder engine) keyed by the reminder's entity. ──
        $limit = max(1, min(25, (int) ($input['email_limit'] ?? 10)));
        $sent = \db_select(
            "SELECT 'email_log' AS src, entity_type AS kind, to_email AS recipient, subject, status,
                    error_message, COALESCE(sent_at, created_at) AS at
               FROM email_logs WHERE customer_id = ?
              ORDER BY created_at DESC LIMIT {$limit}",
            [$cid]
        );
        $reminders = \db_select(
            "SELECT 'reminder' AS src, notification_type AS kind, recipient, subject, status,
                    error_message, COALESCE(sent_at, created_at) AS at
               FROM notification_log
              WHERE channel = 'email' AND (
                    (entity_type = 'customer'    AND entity_id = ?)
                 OR (entity_type = 'invoice'     AND entity_id IN (SELECT id FROM invoices     WHERE customer_id = ?))
                 OR (entity_type = 'lease'       AND entity_id IN (SELECT id FROM leases       WHERE customer_id = ?))
                 OR (entity_type = 'payment'     AND entity_id IN (SELECT id FROM payments     WHERE customer_id = ?))
                 OR (entity_type = 'reservation' AND entity_id IN (SELECT id FROM reservations WHERE customer_id = ?)))
              ORDER BY created_at DESC LIMIT {$limit}",
            [$cid, $cid, $cid, $cid, $cid]
        );
        $emails = array_merge($sent, $reminders);
        usort($emails, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
        $emails = array_slice($emails, 0, $limit);
        $emailTotal = \db_count("SELECT COUNT(*) FROM email_logs WHERE customer_id = ?", [$cid]);

        return [
            'customer' => [
                'id'               => (int) $c['id'],
                'name'             => $c['company_name'],
                'status'           => $c['status'],
                'invoice_delivery' => $c['invoice_delivery'],
                'email'            => $c['email'],
                'billing_email'    => $c['billing_email'],
                'invoice_email'    => $c['invoice_email'],
                'main_email_disabled' => (int) $c['email_disabled'] === 1 ? ($c['email_disabled_reason'] ?: 'yes') : false,
            ],
            'portal' => [
                'has_portal_access' => (bool) array_filter($portal, static fn (array $u): bool => $u['status'] === 'active'),
                'users'             => $portal,
            ],
            'customer_emails' => [
                'master_switch_on'        => CustomerReminders::masterEnabled(),
                'respect_portal_opt_outs' => CustomerReminders::respectPortalOptOut(),
                'on_do_not_email_list'    => $onDoNotEmail,
                'reminder_cron_enabled'   => \cron_enabled('customer_reminders'),
                'compliance_cron_enabled' => \cron_enabled('compliance_alerts'),
                'types'                   => $types,
                'note' => 'would_send = this customer passes every switch for that automatic email today (master, type, do-not-email list, audience/portal opt-out, a valid non-bounced address). The type\'s own schedule/timing still decides WHEN it goes. These switches govern the automatic reminders and dunning letters (a manual dunning send skips only the type switch). Invoice emails and Compose sent by staff are NOT gated by them.',
            ],
            'recent_emails' => array_map(static fn (array $e): array => [
                'when'      => self::ago($e['at']),
                'at_local'  => \format_datetime($e['at']),
                'source'    => $e['src'],
                'kind'      => $e['kind'],
                'to'        => $e['recipient'],
                'subject'   => $e['subject'],
                'status'    => $e['status'],
                'error'     => self::clip($e['error_message']),
            ], $emails),
            'email_history_total' => $emailTotal,
            'url' => base_url('customers/show?id=' . $cid),
        ];
    }

    // ════════════════════════════════════════════════════════════
    //  get_work_order_details
    // ════════════════════════════════════════════════════════════

    /**
     * One maintenance work order with its line items. Costs (header totals and
     * line unit/total cost) only when the user may see money.
     *
     * @param  array    $input  work_order_id | work_order_number
     * @param  int|null $userId
     * @return array
     */
    public static function getWorkOrderDetails(array $input, ?int $userId): array
    {
        if ($denied = self::deny($userId, 'maintenance', 'view', 'maintenance work orders')) {
            return $denied;
        }
        $money = self::money($userId);

        $select = "SELECT wo.*, eu.unit_number, eu.status AS unit_status,
                          v.name AS vendor_name,
                          uc.name AS created_by_name, ua.name AS assigned_to_name, ud.name AS completed_by_name
                     FROM maintenance_work_orders wo
                     LEFT JOIN equipment_units eu ON eu.id = wo.equipment_unit_id
                     LEFT JOIN vendors v  ON v.id  = wo.vendor_id
                     LEFT JOIN users   uc ON uc.id = wo.created_by
                     LEFT JOIN users   ua ON ua.id = wo.assigned_to
                     LEFT JOIN users   ud ON ud.id = wo.completed_by";

        $id     = (int) ($input['work_order_id'] ?? 0);
        $number = trim((string) ($input['work_order_number'] ?? ''));
        if ($id <= 0 && $number === '') {
            return ['error' => true, 'message' => 'Give work_order_id or work_order_number. For lists of work orders use get_maintenance_summary.'];
        }

        $wo = $id > 0
            ? \db_row("{$select} WHERE wo.id = ? AND wo.deleted_at IS NULL", [$id])
            : \db_row("{$select} WHERE wo.work_order_number = ? AND wo.deleted_at IS NULL", [$number]);

        if (!$wo && $number !== '') {
            $matches = \db_select(
                "SELECT id, work_order_number, title, status FROM maintenance_work_orders
                  WHERE work_order_number LIKE ? AND deleted_at IS NULL
                  ORDER BY created_at DESC LIMIT 10",
                ['%' . $number . '%']
            );
            if (count($matches) === 1) {
                $wo = \db_row("{$select} WHERE wo.id = ?", [(int) $matches[0]['id']]);
            } elseif ($matches !== []) {
                return ['candidates' => $matches, 'message' => "Several work orders match \"{$number}\" — ask which one, or call again with work_order_id."];
            }
        }
        if (!$wo) {
            return ['error' => true, 'message' => 'No work order found with ' . ($id > 0 ? "ID {$id}" : "number \"{$number}\"") . '.'];
        }

        // Days open — business-local calendar days (requested_date is a DATE).
        $open = !in_array($wo['status'], ['completed', 'cancelled'], true);
        $daysOpen = null;
        if ($open && $wo['requested_date']) {
            $daysOpen = (int) (new \DateTimeImmutable((string) $wo['requested_date']))
                ->diff(new \DateTimeImmutable(\ff_today()))->format('%r%a');
        }

        $out = [
            'id'                 => (int) $wo['id'],
            'work_order_number'  => $wo['work_order_number'],
            'title'              => $wo['title'],
            'status'             => $wo['status'],
            'work_type'          => $wo['work_type'],
            'priority'           => $wo['priority'],
            'unit'               => ['id' => (int) $wo['equipment_unit_id'], 'unit_number' => $wo['unit_number'], 'status' => $wo['unit_status']],
            'vendor'             => $wo['vendor_id'] !== null ? ['id' => (int) $wo['vendor_id'], 'name' => $wo['vendor_name']] : null,
            'requested_date'     => $wo['requested_date'],
            'scheduled_date'     => $wo['scheduled_date'],
            'completed_date'     => $wo['completed_date'],
            'days_open'          => $daysOpen,
            'mileage_at_service' => $wo['mileage_at_service'] !== null ? (int) $wo['mileage_at_service'] : null,
            'description'        => $wo['description'],
            'notes'              => $wo['notes'],
            'internal_notes'     => $wo['internal_notes'],
            'resolution_notes'   => $wo['resolution_notes'],
            'created_by'         => $wo['created_by_name'],
            'assigned_to'        => $wo['assigned_to_name'],
            'completed_by'       => $wo['completed_by_name'],
            'created'            => \format_datetime($wo['created_at']),
            'url'                => base_url('maintenance_work_orders/show?id=' . (int) $wo['id']),
        ];
        if ($money) {
            $out['costs'] = [
                'labor' => $wo['labor_cost'],
                'parts' => $wo['parts_cost'],
                'total' => $wo['total_cost'],
            ];
        }

        $lines = \db_select(
            "SELECT item_type, description, quantity, unit_cost, total_cost, part_number
               FROM maintenance_line_items WHERE work_order_id = ?
              ORDER BY id ASC LIMIT " . self::LIST_CAP,
            [(int) $wo['id']]
        );
        $out['line_items'] = array_map(static function (array $l) use ($money): array {
            $row = [
                'type'        => $l['item_type'],
                'description' => $l['description'],
                'part_number' => $l['part_number'],
                'quantity'    => $l['quantity'],
            ];
            if ($money) {
                $row['unit_cost']  = $l['unit_cost'];
                $row['total_cost'] = $l['total_cost'];
            }
            return $row;
        }, $lines);
        $out['line_item_count'] = \db_count("SELECT COUNT(*) FROM maintenance_line_items WHERE work_order_id = ?", [(int) $wo['id']]);
        if (!$money) {
            $out['money_hidden'] = 'Costs are hidden for your role (needs payments:view).';
        }

        return $out;
    }

    // ════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════

    /**
     * Permission gate. Returns null when allowed, else the error payload that
     * names which roles (factory defaults) can see it — so the model can tell
     * the user who to ask instead of just "no".
     * A null $userId is a system caller (cron/summary) and is always allowed,
     * matching FleetForgeTools' convention.
     */
    private static function deny(?int $userId, string $module, string $action, string $what): ?array
    {
        if ($userId === null || \can($module, $action)) {
            return null;
        }
        $roles = self::rolesWith($module, $action);
        return [
            'error'   => true,
            'message' => "Your role can't view {$what} (needs {$module}:{$action})."
                . ($roles !== [] ? ' By default these roles can: ' . implode(', ', $roles) . '.' : '')
                . ' An admin can grant it in Settings → Users & Roles.',
        ];
    }

    /** Roles whose factory default grants $module:$action, as readable names. */
    private static function rolesWith(string $module, string $action): array
    {
        static $perms = null;
        $perms ??= (array) (@require FF_ROOT . '/config/permissions.php');
        $out = [];
        foreach ($perms as $role => $mods) {
            if ($role === 'super_admin' || !empty($mods[$module][$action])) {
                $out[] = ucwords(str_replace('_', ' ', (string) $role));
            }
        }
        return $out;
    }

    /** May this caller see money? System callers always may. */
    private static function money(?int $userId): bool
    {
        return $userId === null || \can_view_financials();
    }

    /**
     * "3 days ago" from a UTC DATETIME string ('Y-m-d H:i:s'), measured against
     * the UTC clock (ff_now_utc) so PHP's Pacific default timezone never skews it.
     */
    private static function ago(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) {
            return null;
        }
        $secs = strtotime(\ff_now_utc() . ' UTC') - $ts;
        if ($secs < 0) {
            return 'just now';
        }
        return match (true) {
            $secs < 90     => 'just now',
            $secs < 3600   => (int) round($secs / 60) . ' minutes ago',
            $secs < 86400  => ($h = (int) floor($secs / 3600)) . ($h === 1 ? ' hour ago' : ' hours ago'),
            default        => ($d = (int) floor($secs / 86400)) . ($d === 1 ? ' day ago' : ' days ago'),
        };
    }

    /**
     * Same as ago() for settings stamps, which are ISO-8601 with an offset
     * ('…+00:00') or, for rows older than S-UTC-STAMPS, bare local wall time.
     * strtotime reads both; normalise to a UTC DATETIME and reuse ago().
     */
    private static function agoIso(string $stamp): ?string
    {
        if ($stamp === '' || ($ts = strtotime($stamp)) === false) {
            return null;
        }
        $when = self::ago(gmdate('Y-m-d H:i:s', $ts));
        return $when . ' (' . \format_datetime(gmdate('Y-m-d H:i:s', $ts)) . ')';
    }

    /** Reporting freshness bucket for a Samsara last-connected stamp. */
    private static function freshness(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'never reported';
        }
        $hours = (strtotime(\ff_now_utc() . ' UTC') - (int) strtotime($utc . ' UTC')) / 3600;
        // Same 8h / 24h thresholds the Tracking page uses for its offline alerts.
        return match (true) {
            $hours < 8  => 'reporting normally',
            $hours < 24 => 'quiet for over 8 hours',
            $hours < 72 => 'offline for over a day',
            default     => 'not reporting (over 3 days)',
        };
    }

    /** Trim long error/description text to TEXT_CAP chars. */
    private static function clip(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        return mb_strlen($s) > self::TEXT_CAP ? mb_substr($s, 0, self::TEXT_CAP) . '…' : $s;
    }
}
