<?php
declare(strict_types=1);

/**
 * FleetForge — Dashboard Activity Feed API
 *
 * @file        api/v1/dashboard/activity_feed.php
 * @description Returns the 20 most recent audit_log events for the activity feed
 *              widget on the admin dashboard. Filters to the business-relevant
 *              actions only — excludes view, login, logout, and cron noise.
 *              Results are NOT cached (feed is expected to be near real-time).
 *
 * @method      GET
 * @params      limit (optional, int 1–50, default 20)
 * @auth        Session required (require_auth_api)
 * @returns     { items: [ { id, action, module, entity_type, entity_id,
 *                           entity_label, user_name, description, created_at,
 *                           time_ago } ] }
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard — recent lease activity feed
 * @session     S004
 */

// dirname(__DIR__, 3): api/v1/dashboard/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

// The activity feed surfaces audit_log rows, so it is gated on audit:view —
// dispatchers (audit=NONE) get an empty feed, not the audit trail. Endpoint
// stays 200 so the card renders an empty state rather than erroring.
if (!can('audit', 'view')) {
    json_success(['items' => []]);
}

// ── Input ──────────────────────────────────────────────────────
// Clamp limit to 1–50 to prevent unbounded queries
$limit = min(50, max(1, clean_int($_GET['limit'] ?? 20) ?? 20));

// ── Query ──────────────────────────────────────────────────────
// Exclude 'view', 'login', 'logout', 'cron' — those are not meaningful
// business events for the dashboard activity feed (too noisy).
// WHY explicit exclusion list: new event types added later will appear
// in the feed by default, which is the safer direction.
$rows = db_select(
    "SELECT
        id,
        action,
        module,
        entity_type,
        entity_id,
        entity_label,
        user_name,
        notes,
        created_at
       FROM audit_log
      WHERE action NOT IN ('view','login','logout','cron')
      ORDER BY created_at DESC
      LIMIT ?",
    [$limit]
);

// ── Format items ───────────────────────────────────────────────
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'           => (int) $row['id'],
        'action'       => $row['action'],
        'module'       => $row['module'],
        'entity_type'  => $row['entity_type'],
        'entity_id'    => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
        'entity_label' => $row['entity_label'],
        'user_name'    => $row['user_name'],
        'description'  => build_activity_description($row),
        'created_at'   => $row['created_at'],
        'time_ago'     => time_ago($row['created_at']),
    ];
}

json_success(['items' => $items]);


// ============================================================
// HELPERS — local to this file only
// ============================================================

/**
 * Build a human-readable description string for an audit log row.
 * Falls back gracefully when entity_label is null (e.g. soft-deleted records).
 */
function build_activity_description(array $row): string
{
    $label  = $row['entity_label'] ?? '';
    $module = str_replace('_', ' ', $row['module']);
    $label  = $label !== '' ? " — {$label}" : '';

    return match ($row['action']) {
        'create'           => ucfirst($module) . " created{$label}",
        'update'           => ucfirst($module) . " updated{$label}",
        'delete'           => ucfirst($module) . " deleted{$label}",
        'restore'          => ucfirst($module) . " restored{$label}",
        'export'           => ucfirst($module) . " exported{$label}",
        'status_change'    => ucfirst($module) . " status changed{$label}",
        'bulk_action'      => ucfirst($module) . " bulk action{$label}",
        'payment_recorded' => "Payment recorded{$label}",
        'invoice_sent'     => "Invoice sent{$label}",
        'invoice_voided'   => "Invoice voided{$label}",
        'lease_closed'     => "Lease closed{$label}",
        default            => ucfirst(str_replace('_', ' ', $row['action'])) . " — {$module}{$label}",
    };
}

/**
 * Returns a human-friendly "X ago" string from a MySQL DATETIME string (UTC).
 * Keeps recent events precise (seconds/minutes) and older events coarser.
 *
 * @param string $datetimeStr  e.g. "2026-03-28 14:22:05"
 */
function time_ago(string $datetimeStr): string
{
    // MEDIUM [06]: MySQL DATETIMEs are stored UTC, but PHP's default tz is
    // America/Vancouver, so strtotime() on a BARE datetime parsed it as LOCAL —
    // then subtracting from time() (true UTC epoch) skewed every age by the UTC
    // offset (~7-8h: hours-old events read "just now"; the old "diff is correct"
    // comment was wrong). Parse the string explicitly as UTC.
    $diff = time() - (int) strtotime($datetimeStr . ' UTC');

    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' min' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hr' . ($h === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $d = (int) floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }

    // Older than a week: show the date (parse as UTC, render in business tz).
    return date('M j', (int) strtotime($datetimeStr . ' UTC'));
}
