<?php
declare(strict_types=1);

/**
 * FleetForge — Demo Customer Portal Accounts (S-DEMO-MULTIYEAR)
 *
 * @file        scripts/seed_portal_accounts.php
 * @description Creates customer-portal login accounts for the demo customers so
 *              the presentation can log into the CUSTOMER PORTAL and show the
 *              customer-facing side populated: their leases, invoices, equipment,
 *              credit applications, and service requests. The customers already
 *              carry years of leases/invoices/units (S-DEMO-MULTIYEAR), so this
 *              script adds:
 *                • portal_users — active logins (one primary + a second contact
 *                  on a couple accounts, plus one 'invited' account to show that
 *                  lifecycle state). All active accounts share ONE demo password.
 *                • customer_credit_applications — the FULL lifecycle: sent →
 *                  opened → submitted → reviewed (approved / declined /
 *                  needs_info). Submitted + reviewed apps carry a real form_data
 *                  snapshot + rendered_html built by the canonical
 *                  cca_render_html() so both the portal detail view and the
 *                  admin snapshot render exactly like a live submission.
 *                • portal_service_requests — a spread of request types/statuses
 *                  tied to each customer's real leases + units.
 *
 *              Idempotent: clears the three tables it owns (whole-table — they
 *              are demo-only) before reseeding.
 *
 * @usage       php scripts/seed_portal_accounts.php
 * @session     S-DEMO-MULTIYEAR
 * @decision    Portal accounts tied to existing demo customers; one shared demo
 *              password; credit apps rendered via the real cca_render_html so
 *              they are visually identical to live submissions.
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/auth.php';
require_once FF_ROOT . '/includes/partials/credit_application_render.php';

// Fail-closed: local dev only.
if (!defined('APP_ENV') || APP_ENV === 'production') {
    fwrite(STDERR, "REFUSED: APP_ENV is production/undefined.\n");
    exit(1);
}

const ADMIN_USER   = 1;
const DEMO_PASSWORD = 'Portal123!';   // shared login for every active demo portal account

function money(float $v): string { return number_format($v, 2, '.', ''); }
function token64(): string { return bin2hex(random_bytes(32)); }   // 64 hex chars
function ts_ago(int $days, string $t = '10:00:00'): string { return date('Y-m-d', strtotime("-{$days} days")) . ' ' . $t; }

echo "=== FleetForge demo portal accounts ===\n\n";

// ── Idempotent reset (demo-only tables) ──────────────────────────────────────
echo "Clearing prior portal seed data...\n";
db_execute('SET FOREIGN_KEY_CHECKS = 0', []);
foreach (['portal_service_requests', 'customer_credit_applications', 'portal_users'] as $t) {
    db_execute("DELETE FROM {$t}", []);
}
db_execute('SET FOREIGN_KEY_CHECKS = 1', []);
echo "  Cleared.\n\n";

$pwHash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

// ── Load demo customers (with the columns the credit-app form needs) ─────────
$customers = db_select(
    "SELECT id, company_name, contact_name, email, phone, address, city, province,
            postal_code, credit_limit, currency, status
       FROM customers WHERE deleted_at IS NULL ORDER BY id", []
);
$custById = [];
foreach ($customers as $c) $custById[(int) $c['id']] = $c;

// ════════════════════════════════════════════════════════════════════════════
// PHASE 1 — PORTAL USERS
// ════════════════════════════════════════════════════════════════════════════
echo "[1] Portal users...\n";

// Second-contact names for the accounts that get a 2nd portal user.
$secondContacts = [
    1  => ['Priya Rai',      'ap@northgatelogistics.ca'],
    5  => ['Dylan Mercer',   'accounts@summitcarriers.ca'],
    3  => ['Helen Vasquez',  'dispatch@cascadefreight.ca'],
];

$portalUserByCustomer = [];   // customer_id => primary portal_user_id
$puCount = 0;
foreach ($customers as $c) {
    $cid    = (int) $c['id'];
    $login  = in_array($c['status'], ['active', 'pending', 'credit_hold'], true);

    // Harbour City Cartage (inactive customer) → seed an 'invited' portal user
    // so the admin portal-users screen shows a not-yet-activated account.
    $status = $login ? 'active' : 'invited';

    $pid = db_insert('portal_users', [
        'customer_id'             => $cid,
        'name'                    => $c['contact_name'] ?: 'Account Admin',
        'email'                   => $c['email'],
        'password_hash'           => $status === 'active' ? $pwHash : null,
        'status'                  => $status,
        'is_primary'              => 1,
        'invite_token'            => $status === 'invited' ? token64() : null,
        'invite_token_expiry'     => $status === 'invited' ? date('Y-m-d H:i:s', strtotime('+7 days')) : null,
        'invite_sent_at'          => $status === 'invited' ? ts_ago(3) : null,
        'last_login_at'           => $status === 'active' ? ts_ago(random_int(0, 9), sprintf('%02d:%02d:00', random_int(8, 18), random_int(0, 59))) : null,
        'notification_preferences'=> json_encode(['invoice_sent' => true, 'payment_received' => true, 'lease_expiring' => true, 'request_update' => true]),
        'created_at'              => ts_ago(random_int(400, 900)),
    ]);
    if ($status === 'active') $portalUserByCustomer[$cid] = $pid;
    $puCount++;
    printf("  + #%-3d %-30s %-8s %s\n", $pid, $c['company_name'], $status, $status === 'active' ? $c['email'] : '(invited)');

    // Second contact on a few anchor accounts.
    if (isset($secondContacts[$cid]) && $status === 'active') {
        [$n2, $e2] = $secondContacts[$cid];
        $pid2 = db_insert('portal_users', [
            'customer_id'             => $cid,
            'name'                    => $n2,
            'email'                   => $e2,
            'password_hash'           => $pwHash,
            'status'                  => 'active',
            'is_primary'              => 0,
            'last_login_at'           => ts_ago(random_int(1, 20)),
            'notification_preferences'=> json_encode(['invoice_sent' => true, 'payment_received' => false, 'lease_expiring' => true, 'request_update' => true]),
            'created_at'              => ts_ago(random_int(200, 500)),
        ]);
        $puCount++;
        printf("  + #%-3d %-30s %-8s %s (2nd contact)\n", $pid2, $c['company_name'], 'active', $e2);
    }
}
echo "  {$puCount} portal users created (password for all active: " . DEMO_PASSWORD . ")\n\n";

// ════════════════════════════════════════════════════════════════════════════
// PHASE 2 — CREDIT APPLICATIONS (full lifecycle)
// ════════════════════════════════════════════════════════════════════════════
echo "[2] Credit applications...\n";

/** Build a realistic form_data snapshot from a customer row. */
function build_form_data(array $c): array {
    $isUsd  = ($c['currency'] ?? 'CAD') === 'USD';
    $gst    = $isUsd ? '' : ('R' . random_int(100000000, 899999999) . 'RT0001');
    $reqCredit = money(max(25000, (float) $c['credit_limit']));
    return [
        'company' => [
            'name'              => $c['company_name'],
            'email'             => $c['email'],
            'phone'             => $c['phone'],
            'physical_address'  => $c['address'],
            'physical_city'     => $c['city'],
            'physical_province' => $c['province'],
            'physical_postal'   => $c['postal_code'],
            'same_as_physical'  => '1',
            'billing_address'   => $c['address'],
            'billing_city'      => $c['city'],
            'billing_province'  => $c['province'],
            'billing_postal'    => $c['postal_code'],
            'business_type'     => ['Corporation', 'Sole Proprietorship', 'Partnership'][random_int(0, 2)],
            'incorporation'     => $c['province'],
            'duration'          => random_int(3, 22) . ' years',
            'wcb_number'        => 'WCB-' . random_int(100000, 999999),
            'gst_number'        => $gst,
            'pst_number'        => $isUsd ? '' : ('PST-' . random_int(1000000, 9999999)),
        ],
        'principals' => [
            ['name' => $c['contact_name'], 'title' => 'President'],
            ['name' => '', 'title' => ''],
        ],
        'insurance' => [
            'has_trailer_insurance' => 'yes',
            'company'               => ['ICBC Commercial', 'Northbridge Insurance', 'Intact Insurance', 'Travelers Canada'][random_int(0, 3)],
            'agent'                 => ['Morgan & Associates', 'Coast Insurance Brokers', 'Prairie Risk Partners'][random_int(0, 2)],
            'phone'                 => '1-800-' . random_int(200, 899) . '-' . random_int(1000, 9999),
        ],
        'equipment' => [
            'tractors_owned'   => (string) random_int(2, 40),
            'tractors_leased'  => (string) random_int(0, 15),
            'owner_operators'  => (string) random_int(0, 25),
        ],
        'credit' => [
            'credit_requested'   => $reqCredit,
            'has_purchase_order' => random_int(0, 1) ? 'yes' : 'no',
        ],
        'references' => [
            ['company' => 'Petro-Pass Fuel Card',   'phone' => '1-800-' . random_int(200, 899) . '-' . random_int(1000, 9999), 'email' => 'ar@petropass.ca'],
            ['company' => 'Traction Heavy Duty',     'phone' => '1-888-' . random_int(200, 899) . '-' . random_int(1000, 9999), 'email' => 'credit@traction.com'],
            ['company' => 'OK Tire Fleet Services',  'phone' => '1-877-' . random_int(200, 899) . '-' . random_int(1000, 9999), 'email' => 'fleet@oktire.com'],
        ],
    ];
}

/** Insert a credit application; renders HTML for submitted/reviewed apps. */
function seed_credit_app(array $c, string $status, ?string $outcome, int $ageDays, ?float $approvedLimit, ?string $reviewNote): int {
    $cid  = (int) $c['id'];
    $sentAt      = ts_ago($ageDays);
    $openedAt    = in_array($status, ['opened', 'submitted', 'reviewed'], true) ? ts_ago($ageDays - 1) : null;
    $submittedAt = in_array($status, ['submitted', 'reviewed'], true)          ? ts_ago($ageDays - 2) : null;
    $reviewedAt  = $status === 'reviewed'                                       ? ts_ago(max(1, $ageDays - 5)) : null;

    $row = [
        'customer_id'      => $cid,
        'token_hash'       => hash('sha256', token64()),
        'token_expires_at' => date('Y-m-d H:i:s', strtotime($sentAt . ' +30 days')),
        'status'           => $status,
        'review_outcome'   => $outcome,
        'terms_accepted'   => in_array($status, ['submitted', 'reviewed'], true) ? 1 : 0,
        'sent_at'          => $sentAt,
        'opened_at'        => $openedAt,
        'submitted_at'     => $submittedAt,
        'reviewed_at'      => $reviewedAt,
        'sent_by'          => ADMIN_USER,
        'reviewed_by'      => $reviewedAt ? ADMIN_USER : null,
        'review_notes'     => $reviewNote,
        'approved_credit_limit' => $approvedLimit !== null ? money($approvedLimit) : null,
        'created_at'       => $sentAt,
    ];

    if (in_array($status, ['submitted', 'reviewed'], true)) {
        $fd = build_form_data($c);
        [$first, $last] = array_pad(explode(' ', (string) $c['contact_name'], 2), 2, '');
        $row['form_data']        = json_encode($fd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['print_name_first'] = $first;
        $row['print_name_last']  = $last;
        $row['signed_date']      = substr((string) $submittedAt, 0, 10);
        $row['terms_version']    = 'snapshot';
        $row['terms_url']        = base_url('legal/credit-terms');
        $row['submitted_ip']     = '198.51.100.' . random_int(2, 250);
        $row['rendered_html']    = cca_render_html([
            'app_id'           => 0,   // patched after insert (need the id)
            'customer_company' => $c['company_name'],
            'company_name'     => (string) (settings_get('company.name') ?: 'FleetForge'),
            'submitted_at'     => $submittedAt,
            'submitted_ip'     => $row['submitted_ip'],
            'print_name_first' => $first,
            'print_name_last'  => $last,
            'signed_date'      => $row['signed_date'],
            'terms_accepted'   => 1,
            'terms_version'    => 'snapshot',
            'form_data'        => $fd,
            'signature_b64'    => '',
            'disclaimer_html'  => '',
            'min_req_html'     => '',
        ]);
    }

    $id = db_insert('customer_credit_applications', $row);

    // Re-render with the real app_id in the header now that we have it.
    if (isset($fd)) {
        $html = cca_render_html([
            'app_id'           => $id,
            'customer_company' => $c['company_name'],
            'company_name'     => (string) (settings_get('company.name') ?: 'FleetForge'),
            'submitted_at'     => $submittedAt,
            'submitted_ip'     => $row['submitted_ip'],
            'print_name_first' => $row['print_name_first'],
            'print_name_last'  => $row['print_name_last'],
            'signed_date'      => $row['signed_date'],
            'terms_accepted'   => 1,
            'terms_version'    => 'snapshot',
            'form_data'        => $fd,
            'signature_b64'    => '',
            'disclaimer_html'  => '',
            'min_req_html'     => '',
        ]);
        db_execute("UPDATE customer_credit_applications SET rendered_html = ? WHERE id = ?", [$html, $id]);
    }
    return $id;
}

// Lifecycle plan: [customer_id, status, outcome, ageDays, approvedLimit, reviewNote]
$caPlan = [
    // Reviewed + approved (the common happy path)
    [1,  'reviewed',  'approved',  240, 120000.0, 'Strong references + 4-year history. Approved at requested limit.'],
    [1,  'reviewed',  'approved',  620,  90000.0, 'Original onboarding application (renewed 2024).'],
    [5,  'reviewed',  'approved',  180,  85000.0, 'Approved. Verified WCB + insurance certificate on file.'],
    [7,  'reviewed',  'approved',  200, 110000.0, 'Approved — clean credit references, AB GST-only.'],
    [4,  'reviewed',  'approved',  160,  75000.0, 'Approved. PST-exempt resale certificate verified.'],
    [2,  'reviewed',  'approved',  150,  90000.0, 'Approved at requested limit. GPS-tracked fleet.'],
    [9,  'reviewed',  'approved',  300, 160000.0, 'Approved — USD cross-border terms, Net 45.'],
    // Reviewed with non-approval outcomes
    [13, 'reviewed',  'declined',   45,     null, 'Declined pending resolution of aged receivables. On credit hold.'],
    [8,  'reviewed',  'needs_info', 30,     null, 'Need updated financial statements + a third trade reference before approval.'],
    // Submitted — awaiting review
    [3,  'submitted', null,          6,     null, null],
    [10, 'submitted', null,          3,     null, null],
    // Opened — customer started but has not submitted
    [6,  'opened',    null,          9,     null, null],
    // Sent — invite emailed, not yet opened
    [11, 'sent',      null,          2,     null, null],
    [12, 'sent',      null,          5,     null, null],
];

$caCount = 0; $caByStatus = [];
foreach ($caPlan as [$cid, $status, $outcome, $age, $limit, $note]) {
    if (!isset($custById[$cid])) continue;
    seed_credit_app($custById[$cid], $status, $outcome, $age, $limit, $note);
    $key = $status . ($outcome ? "/{$outcome}" : '');
    $caByStatus[$key] = ($caByStatus[$key] ?? 0) + 1;
    $caCount++;
}
echo "  {$caCount} credit applications: " . json_encode($caByStatus) . "\n\n";

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3 — PORTAL SERVICE REQUESTS (tied to real leases/units)
// ════════════════════════════════════════════════════════════════════════════
echo "[3] Portal service requests...\n";

$reqTemplates = [
    ['lease_extension',  'Request to extend lease term',        'We would like to extend the current lease by 6 months — can you send updated terms?'],
    ['early_return',     'Early return — unit no longer needed', 'One of our contracts ended early; we would like to return this unit ahead of schedule.'],
    ['damage_report',    'Minor damage to report',              'Noticed a cracked marker light and a small dent on the rear door. Reporting proactively.'],
    ['billing_inquiry',  'Question about latest invoice',       'The mileage charge on the latest invoice looks higher than expected — can you confirm the reading?'],
    ['document_request', 'Copy of insurance certificate',       'Our broker needs a copy of the insurance certificate for this unit for our audit.'],
    ['new_lease_inquiry','Interested in adding a reefer',       'We have a new produce contract starting next month — do you have a 53ft reefer available?'],
    ['general',          'Update our billing contact',          'Please update the AP contact email on our account to the address on file.'],
];
$reqStatuses = ['open', 'in_review', 'resolved', 'closed'];
$reqCount = 0;
foreach ($portalUserByCustomer as $cid => $puId) {
    // Pull a couple of this customer's real leases (+ their units) to attach.
    $leases = db_select(
        "SELECT id, equipment_unit_id FROM leases
          WHERE customer_id = ? AND deleted_at IS NULL ORDER BY start_date DESC LIMIT 3", [$cid]);
    $n = random_int(1, 3);
    for ($i = 0; $i < $n; $i++) {
        $tpl    = $reqTemplates[array_rand($reqTemplates)];
        $status = $reqStatuses[array_rand($reqStatuses)];
        $lease  = $leases ? $leases[array_rand($leases)] : null;
        $ageDays = random_int(2, 240);
        $resolved = in_array($status, ['resolved', 'closed'], true);
        $rid = db_insert('portal_service_requests', [
            'portal_user_id'    => $puId,
            'customer_id'       => $cid,
            'equipment_unit_id' => $lease['equipment_unit_id'] ?? null,
            'lease_id'          => $lease['id'] ?? null,
            'request_type'      => $tpl[0],
            'subject'           => $tpl[1],
            'message'           => $tpl[2],
            'status'            => $status,
            'assigned_to'       => $status === 'open' ? null : ADMIN_USER,
            'response'          => $resolved ? 'Thanks for reaching out — this has been handled. Let us know if anything else comes up.' : null,
            'resolved_at'       => $resolved ? ts_ago(max(1, $ageDays - random_int(1, 5))) : null,
            'created_at'        => ts_ago($ageDays),
        ]);
        $reqCount++;
    }
}
echo "  {$reqCount} portal service requests created\n\n";

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY + credentials
// ════════════════════════════════════════════════════════════════════════════
echo str_repeat('─', 68) . "\n";
echo "  PORTAL SEED COMPLETE\n";
echo str_repeat('─', 68) . "\n";
printf("  %-26s %d\n", 'portal users',        $puCount);
printf("  %-26s %d\n", 'credit applications', $caCount);
printf("  %-26s %d\n", 'service requests',    $reqCount);
echo str_repeat('─', 68) . "\n";
echo "  Portal login: " . base_url('portal/auth/login') . "\n";
echo "  Password for ALL active accounts: " . DEMO_PASSWORD . "\n\n";
echo "  Sample logins (all use the password above):\n";
foreach (array_slice($customers, 0, 6) as $c) {
    if (in_array($c['status'], ['active', 'pending', 'credit_hold'], true)) {
        printf("    %-34s %s\n", $c['email'], $c['company_name']);
    }
}
echo "\n";
