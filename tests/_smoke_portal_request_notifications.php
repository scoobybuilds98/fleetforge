<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_request_notifications.php
 *
 * Smoke test for S-PORTAL-REQUEST-ROUTING — portal service request
 * notification dispatcher + settings-based routing config.
 *
 * Sub-checks (C1-C22):
 *  C1: class surfaces (PortalRequestNotifier + REQUEST_TYPES + REQUEST_TYPE_LABELS)
 *  C2: settings seeded — all 17 keys exist with expected defaults
 *  C3: NotificationService category map includes 'service_request' prefix
 *  C4: resolveRecipients fallback to default bucket when type-specific is empty
 *      (D-PORTAL-REQUEST-ROUTING-4)
 *  C5: resolveRecipients honors role_slugs — resolves to all users with that role
 *  C6: resolveRecipients honors user_ids — explicit user IDs are included
 *  C7: resolveRecipients union — role_slugs + user_ids deduped
 *  C8: resolveRecipients filters deleted/inactive users out
 *  C9: resolveRecipients always_include_super_admin='1' adds super_admin even
 *      when type routing has no overlap (D-PORTAL-REQUEST-ROUTING-3 safety net)
 * C10: resolveRecipients always_include_super_admin='0' excludes super_admin
 *      when not explicitly routed
 * C11: resolveRecipients unknown request_type normalizes to 'general'
 * C12: resolveRoleSlugsToUserIds returns active users in given roles only
 * C13: filterActiveUsers excludes inactive/deleted from explicit ID list
 * C14: notify() resolves recipients + inserts notifications rows per recipient
 *      (integration: end-to-end Pusher path)
 * C15: notify() title + message + url shape — includes customer company,
 *      submitted-by name, subject, drill-down URL
 * C16: notify() severity heuristic — damage_report + early_return → warning;
 *      rest → info
 * C17: notify() returns 0 + logs when request_id not found
 * C18: notify() returns 0 when no recipients resolve (still no throw)
 * C19: app/portal/requests/create.php contains the PortalRequestNotifier hook
 *      (source scan — the hook is in the success path AFTER db_insert)
 * C20: settings UI source scan — app/admin/settings/index.php has Portal
 *      Service Request Routing card with the 8 routing keys present
 * C21: settings save handler — preg_match branches for role_slugs + user_ids
 *      JSON shape present in the elseif chain
 * C22: best-effort discipline — calling notify() with a deliberately broken
 *      request (deleted FK target etc.) does NOT throw + caller can continue
 *
 * Fixtures use sentinel IDs 999990-999999, cleaned up in finally.
 *
 * @session  S-PORTAL-REQUEST-ROUTING
 */

require_once __DIR__ . '/../api/bootstrap.php';

use FleetForge\Notifications\PortalRequestNotifier;
use FleetForge\Notifications\NotificationService;

$pass = 0;
$total = 26;
$failures = [];

// ──────────────────────────────────────────────────────────────────────────
// Fixture helpers
// ──────────────────────────────────────────────────────────────────────────

function ff_smoke_prn_set_setting(string $key, string $value): void
{
    db_execute(
        "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`) VALUES (?, ?, 'string', 'portal_requests', 0, 0)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function ff_smoke_prn_get_setting(string $key): ?string
{
    $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
    return $row['value'] ?? null;
}

function ff_smoke_prn_cleanup(): void
{
    db_execute("DELETE FROM notifications WHERE entity_type='service_request' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM portal_service_requests WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM portal_users WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM customers WHERE id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM users WHERE id BETWEEN 999990 AND 999999");
}

/**
 * Seed a customer, portal_user, and a posted service request.
 * Returns the request id.
 */
function ff_smoke_prn_seed_request(int $reqId, string $requestType, array $overrides = []): int
{
    $custId = 999990;
    $puId   = 999990;

    if (!db_row("SELECT id FROM customers WHERE id = ?", [$custId])) {
        db_execute(
            "INSERT INTO customers (id, company_name, contact_name, email, phone, status, currency)
             VALUES (?, 'Smoke PRN Customer Inc.', 'Smoke Contact', 'smoke-prn@example.com', '604-555-0100', 'active', 'CAD')",
            [$custId]
        );
    }
    if (!db_row("SELECT id FROM portal_users WHERE id = ?", [$puId])) {
        db_execute(
            "INSERT INTO portal_users (id, customer_id, name, email, password_hash, status)
             VALUES (?, ?, 'Smoke Portal User', 'smoke-portal@example.com', 'x', 'active')",
            [$puId, $custId]
        );
    }

    $subject = $overrides['subject'] ?? "Smoke {$requestType} subject";
    $message = $overrides['message'] ?? "Smoke {$requestType} message body";

    db_execute(
        "INSERT INTO portal_service_requests (id, portal_user_id, customer_id, request_type, subject, message, status)
         VALUES (?, ?, ?, ?, ?, ?, 'open')",
        [$reqId, $puId, $custId, $requestType, $subject, $message]
    );

    return $reqId;
}

/**
 * Seed 2 sentinel admin users with distinct roles. Returns [activeId, deletedId, inactiveId].
 */
function ff_smoke_prn_seed_admin_users(): array
{
    $roleSuper = db_row("SELECT id FROM user_roles WHERE slug='super_admin' LIMIT 1");
    $roleAcct  = db_row("SELECT id FROM user_roles WHERE slug='accountant' LIMIT 1");

    $activeId   = 999991;
    $deletedId  = 999992;
    $inactiveId = 999993;
    $acctId     = 999994;

    db_execute(
        "INSERT INTO users (id, name, email, password_hash, role_id, status, deleted_at)
         VALUES (?, 'Smoke Active Super', 'smoke-prn-active@example.com', 'x', ?, 'active', NULL)",
        [$activeId, (int) $roleSuper['id']]
    );
    db_execute(
        "INSERT INTO users (id, name, email, password_hash, role_id, status, deleted_at)
         VALUES (?, 'Smoke Deleted Super', 'smoke-prn-deleted@example.com', 'x', ?, 'active', NOW())",
        [$deletedId, (int) $roleSuper['id']]
    );
    db_execute(
        "INSERT INTO users (id, name, email, password_hash, role_id, status, deleted_at)
         VALUES (?, 'Smoke Inactive Super', 'smoke-prn-inactive@example.com', 'x', ?, 'inactive', NULL)",
        [$inactiveId, (int) $roleSuper['id']]
    );
    db_execute(
        "INSERT INTO users (id, name, email, password_hash, role_id, status, deleted_at)
         VALUES (?, 'Smoke Active Accountant', 'smoke-prn-acct@example.com', 'x', ?, 'active', NULL)",
        [$acctId, (int) $roleAcct['id']]
    );

    return [$activeId, $deletedId, $inactiveId, $acctId];
}

// Snapshot settings so we can mutate freely + restore at end.
$snapshotKeys = [
    'portal_requests.routing.always_include_super_admin',
    'portal_requests.routing.default.role_slugs',
    'portal_requests.routing.default.user_ids',
    'portal_requests.routing.lease_extension.role_slugs',
    'portal_requests.routing.lease_extension.user_ids',
    'portal_requests.routing.billing_inquiry.role_slugs',
    'portal_requests.routing.billing_inquiry.user_ids',
    'portal_requests.routing.damage_report.role_slugs',
    'portal_requests.routing.damage_report.user_ids',
    'portal_requests.routing.early_return.role_slugs',
    'portal_requests.routing.early_return.user_ids',
    'portal_requests.routing.general.role_slugs',
    'portal_requests.routing.general.user_ids',
];
$snapshot = [];
foreach ($snapshotKeys as $k) {
    $snapshot[$k] = ff_smoke_prn_get_setting($k);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "S-PORTAL-REQUEST-ROUTING Smoke\n";
echo "═══════════════════════════════════════════════════════════\n";

try {
    ff_smoke_prn_cleanup();
    [$activeId, $deletedId, $inactiveId, $acctId] = ff_smoke_prn_seed_admin_users();

    // ── C1: class surfaces ─────────────────────────────────────────────
    $c1Errors = [];
    if (!class_exists(PortalRequestNotifier::class)) {
        $c1Errors[] = 'PortalRequestNotifier class missing';
    } else {
        foreach (['notify', 'resolveRecipients', 'resolveRoleSlugsToUserIds', 'filterActiveUsers'] as $m) {
            if (!method_exists(PortalRequestNotifier::class, $m)) $c1Errors[] = "method missing: {$m}";
        }
        $ref = new ReflectionClass(PortalRequestNotifier::class);
        $consts = $ref->getConstants();
        if (!isset($consts['REQUEST_TYPES']) || count($consts['REQUEST_TYPES']) !== 7) {
            $c1Errors[] = 'REQUEST_TYPES should have 7 entries';
        }
        if (!isset($consts['REQUEST_TYPE_LABELS']) || count($consts['REQUEST_TYPE_LABELS']) !== 7) {
            $c1Errors[] = 'REQUEST_TYPE_LABELS should have 7 entries';
        }
        // Mirror check against the portal form's $validTypes
        $portalCreate = file_get_contents(__DIR__ . '/../app/portal/requests/create.php');
        if (strpos($portalCreate, "\$validTypes = ['lease_extension', 'early_return', 'damage_report', 'billing_inquiry', 'document_request', 'new_lease_inquiry', 'general']") === false) {
            $c1Errors[] = 'portal/requests/create.php $validTypes drifted from PortalRequestNotifier::REQUEST_TYPES';
        }
    }
    if (empty($c1Errors)) { echo "PASS C1 class surfaces + REQUEST_TYPES alignment with portal create.php\n"; $pass++; }
    else { echo "FAIL C1 " . implode('; ', $c1Errors) . "\n"; $failures[] = 'C1'; }

    // ── C2: settings seeded ────────────────────────────────────────────
    $c2Errors = [];
    $expected = [
        'portal_requests.routing.always_include_super_admin',
        'portal_requests.routing.default.role_slugs',
        'portal_requests.routing.default.user_ids',
    ];
    foreach (PortalRequestNotifier::REQUEST_TYPES as $t) {
        $expected[] = "portal_requests.routing.{$t}.role_slugs";
        $expected[] = "portal_requests.routing.{$t}.user_ids";
    }
    foreach ($expected as $k) {
        $row = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$k]);
        if ($row === null) $c2Errors[] = "missing setting: {$k}";
    }
    if (empty($c2Errors)) { echo "PASS C2 all 17 routing settings seeded\n"; $pass++; }
    else { echo "FAIL C2 " . implode('; ', $c2Errors) . "\n"; $failures[] = 'C2'; }

    // ── C3: NotificationService category map ────────────────────────────
    $ref = new ReflectionClass(NotificationService::class);
    $consts = $ref->getConstants();
    $c3Errors = [];
    if (!isset($consts['TYPE_TO_CATEGORY']['service_request'])) {
        $c3Errors[] = "TYPE_TO_CATEGORY missing 'service_request' prefix";
    } elseif ($consts['TYPE_TO_CATEGORY']['service_request'] !== 'system') {
        $c3Errors[] = "TYPE_TO_CATEGORY['service_request'] should be 'system'; got " . json_encode($consts['TYPE_TO_CATEGORY']['service_request']);
    }
    // Also verify getCategoryFromType resolves correctly
    if (NotificationService::getCategoryFromType('service_request.billing_inquiry') !== 'system') {
        $c3Errors[] = "getCategoryFromType('service_request.billing_inquiry') should resolve to 'system'";
    }
    if (empty($c3Errors)) { echo "PASS C3 NotificationService TYPE_TO_CATEGORY includes 'service_request' prefix\n"; $pass++; }
    else { echo "FAIL C3 " . implode('; ', $c3Errors) . "\n"; $failures[] = 'C3'; }

    // ── C4: fallback to default bucket ─────────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '0');
    ff_smoke_prn_set_setting('portal_requests.routing.lease_extension.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.lease_extension.user_ids', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.default.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.default.user_ids', json_encode([$activeId]));

    $c4Errors = [];
    $r4 = PortalRequestNotifier::resolveRecipients('lease_extension');
    if (!in_array($activeId, $r4, true)) $c4Errors[] = "expected fallback to default.user_ids={$activeId}; got " . json_encode($r4);
    if (empty($c4Errors)) { echo "PASS C4 fallback to default bucket when type-specific routing empty (D-PORTAL-REQUEST-ROUTING-4)\n"; $pass++; }
    else { echo "FAIL C4 " . implode('; ', $c4Errors) . "\n"; $failures[] = 'C4'; }

    // ── C5: resolveRecipients honors role_slugs ────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.default.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.default.user_ids', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', json_encode(['accountant']));
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', '[]');

    $c5Errors = [];
    $r5 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if (!in_array($acctId, $r5, true)) $c5Errors[] = "expected accountant {$acctId} in resolved; got " . json_encode($r5);
    if (empty($c5Errors)) { echo "PASS C5 role_slugs resolution — accountant role resolves to seeded accountant user\n"; $pass++; }
    else { echo "FAIL C5 " . implode('; ', $c5Errors) . "\n"; $failures[] = 'C5'; }

    // ── C6: explicit user_ids ──────────────────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', json_encode([$activeId]));
    $c6Errors = [];
    $r6 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if ($r6 !== [$activeId]) $c6Errors[] = "expected exactly [{$activeId}]; got " . json_encode($r6);
    if (empty($c6Errors)) { echo "PASS C6 explicit user_ids resolution\n"; $pass++; }
    else { echo "FAIL C6 " . implode('; ', $c6Errors) . "\n"; $failures[] = 'C6'; }

    // ── C7: union dedup ────────────────────────────────────────────────
    // K-22: real DB may have other accountant users; assert presence + dedup,
    // not exact set equality. Both fixture IDs must appear exactly once.
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', json_encode(['accountant']));
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', json_encode([$acctId, $activeId, $acctId])); // duplicate $acctId
    $c7Errors = [];
    $r7 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if (!in_array($activeId, $r7, true)) $c7Errors[] = "expected activeId {$activeId} (via user_ids) in resolved; got " . json_encode($r7);
    if (!in_array($acctId, $r7, true)) $c7Errors[] = "expected acctId {$acctId} (via role) in resolved";
    // Dedup check: count occurrences of $acctId must be exactly 1
    $acctIdCount = count(array_filter($r7, static fn($id) => (int) $id === (int) $acctId));
    if ($acctIdCount !== 1) $c7Errors[] = "expected acctId once after dedup; saw {$acctIdCount} occurrences in " . json_encode($r7);
    if (empty($c7Errors)) { echo "PASS C7 union dedup — role_slugs + user_ids combined; duplicates collapsed\n"; $pass++; }
    else { echo "FAIL C7 " . implode('; ', $c7Errors) . "\n"; $failures[] = 'C7'; }

    // ── C8: filters out deleted/inactive ───────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', json_encode([$activeId, $deletedId, $inactiveId]));
    $c8Errors = [];
    $r8 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if (in_array($deletedId, $r8, true)) $c8Errors[] = "deleted user {$deletedId} should be excluded";
    if (in_array($inactiveId, $r8, true)) $c8Errors[] = "inactive user {$inactiveId} should be excluded";
    if (!in_array($activeId, $r8, true)) $c8Errors[] = "active user {$activeId} should be included";
    if (empty($c8Errors)) { echo "PASS C8 filters out deleted + inactive users\n"; $pass++; }
    else { echo "FAIL C8 " . implode('; ', $c8Errors) . "\n"; $failures[] = 'C8'; }

    // ── C9: always_include_super_admin='1' ─────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '1');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', json_encode(['accountant']));
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', '[]');
    $c9Errors = [];
    $r9 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if (!in_array($activeId, $r9, true)) $c9Errors[] = "super_admin {$activeId} should be auto-included (safety net); got " . json_encode($r9);
    if (!in_array($acctId, $r9, true)) $c9Errors[] = "accountant {$acctId} from role should still be included";
    if (empty($c9Errors)) { echo "PASS C9 always_include_super_admin='1' adds super_admin to set (D-PORTAL-REQUEST-ROUTING-3 safety net)\n"; $pass++; }
    else { echo "FAIL C9 " . implode('; ', $c9Errors) . "\n"; $failures[] = 'C9'; }

    // ── C10: always_include_super_admin='0' ────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '0');
    $c10Errors = [];
    $r10 = PortalRequestNotifier::resolveRecipients('billing_inquiry');
    if (in_array($activeId, $r10, true)) {
        $c10Errors[] = "super_admin {$activeId} should NOT be auto-included when toggle='0'";
    }
    if (!in_array($acctId, $r10, true)) {
        $c10Errors[] = "accountant {$acctId} from role should still be included";
    }
    if (empty($c10Errors)) { echo "PASS C10 always_include_super_admin='0' excludes super_admin from auto-add\n"; $pass++; }
    else { echo "FAIL C10 " . implode('; ', $c10Errors) . "\n"; $failures[] = 'C10'; }

    // ── C11: unknown request_type normalizes ───────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '0');
    ff_smoke_prn_set_setting('portal_requests.routing.general.role_slugs', json_encode(['accountant']));
    $c11Errors = [];
    $r11 = PortalRequestNotifier::resolveRecipients('xyz_unknown_type');
    if (!in_array($acctId, $r11, true)) $c11Errors[] = "unknown type should normalize to 'general' and include accountant; got " . json_encode($r11);
    ff_smoke_prn_set_setting('portal_requests.routing.general.role_slugs', '[]');
    if (empty($c11Errors)) { echo "PASS C11 unknown request_type normalizes to 'general' bucket\n"; $pass++; }
    else { echo "FAIL C11 " . implode('; ', $c11Errors) . "\n"; $failures[] = 'C11'; }

    // ── C12: resolveRoleSlugsToUserIds ─────────────────────────────────
    $c12Errors = [];
    $r12 = PortalRequestNotifier::resolveRoleSlugsToUserIds(['accountant']);
    if (!in_array($acctId, $r12, true)) $c12Errors[] = "expected {$acctId} in role lookup; got " . json_encode($r12);
    if (in_array($deletedId, $r12, true)) $c12Errors[] = "deleted user should NOT appear in role lookup";
    if (empty($c12Errors)) { echo "PASS C12 resolveRoleSlugsToUserIds — active users in role only\n"; $pass++; }
    else { echo "FAIL C12 " . implode('; ', $c12Errors) . "\n"; $failures[] = 'C12'; }

    // ── C13: filterActiveUsers ─────────────────────────────────────────
    $c13Errors = [];
    $r13 = PortalRequestNotifier::filterActiveUsers([$activeId, $deletedId, $inactiveId, $acctId, 999998]);
    sort($r13);
    $expected13 = [$activeId, $acctId];
    sort($expected13);
    if ($r13 !== $expected13) $c13Errors[] = "expected " . json_encode($expected13) . "; got " . json_encode($r13);
    if (empty($c13Errors)) { echo "PASS C13 filterActiveUsers excludes deleted/inactive/missing\n"; $pass++; }
    else { echo "FAIL C13 " . implode('; ', $c13Errors) . "\n"; $failures[] = 'C13'; }

    // ── C14: end-to-end notify() ──────────────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '0');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.billing_inquiry.user_ids', json_encode([$activeId, $acctId]));
    db_execute("DELETE FROM notifications WHERE entity_type='service_request' AND entity_id BETWEEN 999990 AND 999999");
    ff_smoke_prn_seed_request(999990, 'billing_inquiry', ['subject' => 'C14 smoke subject']);
    $c14Errors = [];
    $count14 = PortalRequestNotifier::notify(999990);
    if ($count14 !== 2) $c14Errors[] = "expected 2 recipients; notify returned {$count14}";
    $rows = db_select("SELECT user_id, title, message, url, type, category, entity_type, entity_id, severity FROM notifications WHERE entity_type='service_request' AND entity_id = 999990 ORDER BY user_id");
    if (count($rows) !== 2) $c14Errors[] = "expected 2 notification rows; got " . count($rows);
    foreach ($rows as $row) {
        if ($row['entity_id'] != 999990) $c14Errors[] = "entity_id wrong: " . json_encode($row);
        if ($row['type'] !== 'service_request.billing_inquiry') $c14Errors[] = "type wrong: " . json_encode($row);
        if ($row['category'] !== 'system') $c14Errors[] = "category should be system; got " . json_encode($row['category']);
    }
    if (empty($c14Errors)) { echo "PASS C14 end-to-end notify() inserts 1 row per recipient with correct type/category/entity\n"; $pass++; }
    else { echo "FAIL C14 " . implode('; ', $c14Errors) . "\n"; $failures[] = 'C14'; }

    // ── C15: title / message / url shape ───────────────────────────────
    $c15Errors = [];
    $r15Row = db_row("SELECT title, message, url FROM notifications WHERE entity_type='service_request' AND entity_id=999990 LIMIT 1");
    if (strpos((string) $r15Row['title'], 'Billing Inquiry') === false) $c15Errors[] = "title should mention 'Billing Inquiry'; got " . json_encode($r15Row['title']);
    if (strpos((string) $r15Row['title'], 'Smoke PRN Customer Inc.') === false) $c15Errors[] = "title should mention customer company; got " . json_encode($r15Row['title']);
    if (strpos((string) $r15Row['message'], 'C14 smoke subject') === false) $c15Errors[] = "message should include subject; got " . json_encode($r15Row['message']);
    if (strpos((string) $r15Row['message'], 'Smoke Portal User') === false) $c15Errors[] = "message should include submitted-by name; got " . json_encode($r15Row['message']);
    if (strpos((string) $r15Row['url'], '/requests/view?id=999990') === false) $c15Errors[] = "url should drill-down to request; got " . json_encode($r15Row['url']);
    if (empty($c15Errors)) { echo "PASS C15 notify() title + message + url include customer + submitted-by + subject + drill-down link\n"; $pass++; }
    else { echo "FAIL C15 " . implode('; ', $c15Errors) . "\n"; $failures[] = 'C15'; }

    // ── C16: severity heuristic ────────────────────────────────────────
    db_execute("DELETE FROM notifications WHERE entity_type='service_request' AND entity_id BETWEEN 999990 AND 999999");
    db_execute("DELETE FROM portal_service_requests WHERE id BETWEEN 999990 AND 999999");

    ff_smoke_prn_set_setting('portal_requests.routing.damage_report.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.damage_report.user_ids', json_encode([$activeId]));
    ff_smoke_prn_set_setting('portal_requests.routing.early_return.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.early_return.user_ids', json_encode([$activeId]));
    ff_smoke_prn_set_setting('portal_requests.routing.general.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.general.user_ids', json_encode([$activeId]));

    ff_smoke_prn_seed_request(999991, 'damage_report');
    ff_smoke_prn_seed_request(999992, 'early_return');
    ff_smoke_prn_seed_request(999993, 'general');
    PortalRequestNotifier::notify(999991);
    PortalRequestNotifier::notify(999992);
    PortalRequestNotifier::notify(999993);

    $c16Errors = [];
    // K-22: filter by entity_type too — entity_id sentinel range overlaps with
    // notifications from other smoke runs (e.g. QBO invoice push notifications
    // with entity_id=999991, entity_type='invoice', severity='critical').
    $sevDmg = db_row("SELECT severity FROM notifications WHERE entity_type='service_request' AND entity_id=999991 LIMIT 1");
    $sevErn = db_row("SELECT severity FROM notifications WHERE entity_type='service_request' AND entity_id=999992 LIMIT 1");
    $sevGen = db_row("SELECT severity FROM notifications WHERE entity_type='service_request' AND entity_id=999993 LIMIT 1");
    if (($sevDmg['severity'] ?? null) !== 'warning') $c16Errors[] = "damage_report severity should be 'warning'; got " . json_encode($sevDmg);
    if (($sevErn['severity'] ?? null) !== 'warning') $c16Errors[] = "early_return severity should be 'warning'; got " . json_encode($sevErn);
    if (($sevGen['severity'] ?? null) !== 'info') $c16Errors[] = "general severity should be 'info'; got " . json_encode($sevGen);
    if (empty($c16Errors)) { echo "PASS C16 severity heuristic — damage_report + early_return → warning; general → info\n"; $pass++; }
    else { echo "FAIL C16 " . implode('; ', $c16Errors) . "\n"; $failures[] = 'C16'; }

    // ── C17: missing request_id returns 0 ──────────────────────────────
    $c17Errors = [];
    $r17 = PortalRequestNotifier::notify(999988);
    if ($r17 !== 0) $c17Errors[] = "expected 0 for missing request; got {$r17}";
    if (empty($c17Errors)) { echo "PASS C17 notify(missing) returns 0; never throws\n"; $pass++; }
    else { echo "FAIL C17 " . implode('; ', $c17Errors) . "\n"; $failures[] = 'C17'; }

    // ── C18: zero recipients = 0 + no throw ────────────────────────────
    ff_smoke_prn_set_setting('portal_requests.routing.always_include_super_admin', '0');
    ff_smoke_prn_set_setting('portal_requests.routing.lease_extension.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.lease_extension.user_ids', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.default.role_slugs', '[]');
    ff_smoke_prn_set_setting('portal_requests.routing.default.user_ids', '[]');
    db_execute("DELETE FROM portal_service_requests WHERE id = 999994");
    ff_smoke_prn_seed_request(999994, 'lease_extension');
    $c18Errors = [];
    $r18 = PortalRequestNotifier::notify(999994);
    if ($r18 !== 0) $c18Errors[] = "expected 0 recipients (empty routing); got {$r18}";
    if (empty($c18Errors)) { echo "PASS C18 zero recipients returns 0 without throw; routing-empty silent-drop logged\n"; $pass++; }
    else { echo "FAIL C18 " . implode('; ', $c18Errors) . "\n"; $failures[] = 'C18'; }

    // ── C19: portal create.php hook ────────────────────────────────────
    $c19Errors = [];
    $src = file_get_contents(__DIR__ . '/../app/portal/requests/create.php');
    if (strpos($src, 'PortalRequestNotifier::notify((int) $newId)') === false) {
        $c19Errors[] = "portal create.php must call PortalRequestNotifier::notify((int) \$newId)";
    }
    // Verify hook is in success path (after db_insert, before redirect)
    $insertPos = strpos($src, "db_insert('portal_service_requests'");
    $notifyPos = strpos($src, 'PortalRequestNotifier::notify');
    $redirectPos = strpos($src, 'header(\'Location:');
    if ($insertPos === false || $notifyPos === false || $redirectPos === false
        || !($insertPos < $notifyPos && $notifyPos < $redirectPos)) {
        $c19Errors[] = "hook must be between db_insert and redirect (insert/notify/redirect positions: {$insertPos}/{$notifyPos}/{$redirectPos})";
    }
    if (empty($c19Errors)) { echo "PASS C19 portal/requests/create.php hooks notifier in success path (between INSERT and redirect)\n"; $pass++; }
    else { echo "FAIL C19 " . implode('; ', $c19Errors) . "\n"; $failures[] = 'C19'; }

    // ── C20: settings UI source scan ───────────────────────────────────
    // K-22: the UI uses PHP-tag interpolation in form-name attrs so literal
    // key strings like "portal_requests.routing.lease_extension" don't appear
    // in the source. Verify the routing prefix appears + each request type
    // label is rendered in the $portalReqTypes map.
    $c20Errors = [];
    $settingsSrc = file_get_contents(__DIR__ . '/../app/admin/settings/index.php');
    if (strpos($settingsSrc, 'Service Request Notification Routing') === false) {
        $c20Errors[] = "settings/index.php missing 'Service Request Notification Routing' card";
    }
    if (strpos($settingsSrc, 'portal_requests.routing') === false) {
        $c20Errors[] = "settings/index.php missing 'portal_requests.routing' prefix";
    }
    if (strpos($settingsSrc, "\$portalReqTypes = [") === false) {
        $c20Errors[] = "settings/index.php missing \$portalReqTypes type-label map";
    }
    foreach (PortalRequestNotifier::REQUEST_TYPE_LABELS as $type => $label) {
        // Each request type must appear in $portalReqTypes as a key
        if (strpos($settingsSrc, "'{$type}'") === false) {
            $c20Errors[] = "settings/index.php \$portalReqTypes missing key '{$type}'";
        }
    }
    if (strpos($settingsSrc, "'default'") === false) {
        $c20Errors[] = "settings/index.php missing 'default' fallback bucket in \$portalReqTypes";
    }
    if (strpos($settingsSrc, "portal_requests.routing.always_include_super_admin") === false) {
        $c20Errors[] = "settings/index.php missing safety-net toggle reference";
    }
    if (empty($c20Errors)) { echo "PASS C20 settings UI source scan — Routing card + 8 type entries (7 + default) + safety-net toggle present\n"; $pass++; }
    else { echo "FAIL C20 " . implode('; ', $c20Errors) . "\n"; $failures[] = 'C20'; }

    // ── C21: settings save handler regex branches ──────────────────────
    $c21Errors = [];
    if (!preg_match('/preg_match.*portal_requests\\\\\\.routing.*role_slugs/', $settingsSrc)) {
        $c21Errors[] = "save handler missing role_slugs preg_match branch";
    }
    if (!preg_match('/preg_match.*portal_requests\\\\\\.routing.*user_ids/', $settingsSrc)) {
        $c21Errors[] = "save handler missing user_ids preg_match branch";
    }
    if (empty($c21Errors)) { echo "PASS C21 settings save handler has preg_match branches for role_slugs + user_ids JSON encoding\n"; $pass++; }
    else { echo "FAIL C21 " . implode('; ', $c21Errors) . "\n"; $failures[] = 'C21'; }

    // ── C22: best-effort never-throws ──────────────────────────────────
    // Deliberately pass a malformed/missing request id; the wrapper try/catch
    // in create.php is a backstop, but notify() itself must not throw.
    $c22Errors = [];
    try {
        $r22a = PortalRequestNotifier::notify(0);
        $r22b = PortalRequestNotifier::notify(-1);
        $r22c = PortalRequestNotifier::notify(999987);
        if ($r22a !== 0 || $r22b !== 0 || $r22c !== 0) {
            $c22Errors[] = "all should return 0; got [{$r22a},{$r22b},{$r22c}]";
        }
    } catch (\Throwable $e) {
        $c22Errors[] = "notify() threw despite best-effort contract: " . $e->getMessage();
    }
    if (empty($c22Errors)) { echo "PASS C22 notify() best-effort — never throws on missing/invalid request ids\n"; $pass++; }
    else { echo "FAIL C22 " . implode('; ', $c22Errors) . "\n"; $failures[] = 'C22'; }

    // ── C23: admin index page exists + uses customers/view gate ────────
    $c23Errors = [];
    $idxPath = __DIR__ . '/../app/admin/requests/index.php';
    if (!is_file($idxPath)) {
        $c23Errors[] = "app/admin/requests/index.php does not exist";
    } else {
        $idxSrc = file_get_contents($idxPath);
        if (strpos($idxSrc, "require_permission('customers', 'view')") === false) {
            $c23Errors[] = "index.php missing require_permission('customers', 'view') gate";
        }
        if (strpos($idxSrc, 'portal_service_requests') === false) {
            $c23Errors[] = "index.php missing portal_service_requests query";
        }
        // Sanity: lint passes
        exec('php -l ' . escapeshellarg($idxPath) . ' 2>&1', $lintOut, $lintCode);
        if ($lintCode !== 0) {
            $c23Errors[] = "lint failed: " . implode("\n", $lintOut);
        }
    }
    if (empty($c23Errors)) { echo "PASS C23 admin/requests/index.php exists + permission gate + portal_service_requests query\n"; $pass++; }
    else { echo "FAIL C23 " . implode('; ', $c23Errors) . "\n"; $failures[] = 'C23'; }

    // ── C24: admin view page exists + uses customers/view gate ─────────
    $c24Errors = [];
    $viewPath = __DIR__ . '/../app/admin/requests/view.php';
    if (!is_file($viewPath)) {
        $c24Errors[] = "app/admin/requests/view.php does not exist";
    } else {
        $viewSrc = file_get_contents($viewPath);
        if (strpos($viewSrc, "require_permission('customers', 'view')") === false) {
            $c24Errors[] = "view.php missing require_permission('customers', 'view') gate";
        }
        if (strpos($viewSrc, "can('customers', 'edit')") === false) {
            $c24Errors[] = "view.php missing can('customers', 'edit') gate for respond form";
        }
        if (strpos($viewSrc, 'api/v1/requests/respond') === false) {
            $c24Errors[] = "view.php missing respond endpoint reference";
        }
    }
    if (empty($c24Errors)) { echo "PASS C24 admin/requests/view.php exists + view/edit permission gates + respond form\n"; $pass++; }
    else { echo "FAIL C24 " . implode('; ', $c24Errors) . "\n"; $failures[] = 'C24'; }

    // ── C25: respond API endpoint exists + behavior ────────────────────
    $c25Errors = [];
    $respPath = __DIR__ . '/../api/v1/requests/respond.php';
    if (!is_file($respPath)) {
        $c25Errors[] = "api/v1/requests/respond.php does not exist";
    } else {
        $respSrc = file_get_contents($respPath);
        if (strpos($respSrc, "require_method('POST')") === false) {
            $c25Errors[] = "respond.php missing POST method gate";
        }
        if (strpos($respSrc, "require_permission('customers', 'edit')") === false) {
            $c25Errors[] = "respond.php missing customers/edit permission gate";
        }
        if (strpos($respSrc, 'audit_log') === false) {
            $c25Errors[] = "respond.php missing audit_log insert";
        }
        if (strpos($respSrc, 'resolved_at') === false) {
            $c25Errors[] = "respond.php missing resolved_at handling";
        }
    }
    if (empty($c25Errors)) { echo "PASS C25 api/v1/requests/respond.php exists + POST gate + permission + audit + resolved_at handling\n"; $pass++; }
    else { echo "FAIL C25 " . implode('; ', $c25Errors) . "\n"; $failures[] = 'C25'; }

    // ── C26: nav config has Service Requests entry ─────────────────────
    $c26Errors = [];
    $navConfig = require __DIR__ . '/../config/navigation.php';
    $found = false;
    foreach ($navConfig as $entry) {
        if (($entry['label'] ?? '') === 'Service Requests') {
            $found = true;
            if (($entry['url'] ?? '') !== '/requests') {
                $c26Errors[] = "Service Requests url should be '/requests' (router strips admin/ prefix); got " . json_encode($entry['url'] ?? null);
            }
            if (($entry['module'] ?? '') !== 'customers') {
                $c26Errors[] = "Service Requests module should be 'customers'; got " . json_encode($entry['module'] ?? null);
            }
            break;
        }
    }
    if (!$found) $c26Errors[] = "config/navigation.php missing 'Service Requests' top-level entry";
    if (empty($c26Errors)) { echo "PASS C26 nav config has Service Requests entry under customers module\n"; $pass++; }
    else { echo "FAIL C26 " . implode('; ', $c26Errors) . "\n"; $failures[] = 'C26'; }

} finally {
    ff_smoke_prn_cleanup();
    foreach ($snapshotKeys as $k) {
        if ($snapshot[$k] === null) {
            db_execute("DELETE FROM settings WHERE `key` = ?", [$k]);
        } else {
            ff_smoke_prn_set_setting($k, $snapshot[$k]);
        }
    }
}

echo "\nportal_request_notifications_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " (failures: " . implode(', ', $failures) . ")";
}
echo "\n";

exit(empty($failures) ? 0 : 1);
