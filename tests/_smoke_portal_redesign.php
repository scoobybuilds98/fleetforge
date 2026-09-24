<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_redesign.php
 *
 * S-PORTAL-REDESIGN — executes the redesigned customer portal for real:
 * every page and every new endpoint over HTTP (Herd, fleetforge.test)
 * against the real schema, as a temporary portal login on the busiest real
 * customer, with another customer's ids used to prove isolation (Trap 8).
 *
 *   R1  every portal page renders 200 with no PHP warning/fatal
 *   R2  portal JSON endpoints answer 401 JSON without a session (not a redirect)
 *   R3  payable: only payable rows; count + sum equal pt_account_summary()
 *   R4  payable ignores another customer's invoice ids
 *   R5  list: tab counts consistent, rows are the customer's, LIKE wildcards literal
 *   R6  zip: another customer's ids → 404; own ids → a ZIP
 *   R7  export: CSV with BOM + header, one row per invoice in the tab
 *   R8  statement: PDF; from > to → 422; admin endpoint shares CustomerStatement
 *   R9  receipt: own payment → PDF; another customer's payment → 404
 *   R10 payment notice: CSRF enforced, amount validated, foreign invoices
 *       dropped, lands as a billing_inquiry request, records NO payment
 *   R11 the created request renders as a conversation
 *   R12 search is customer-scoped
 *   R13 documents: private docs, units not on rent with them, and other
 *       customers' docs are invisible on the page AND 404 on the file stream
 *   R14 payments/go: another customer's invoice → "couldn't find"
 *   R15 initiate_qbo_payment never shows operator-facing error text
 *   R16 request reply: > 5,000 characters → 422
 *   R17 email-reminder preferences save (primary contact) and round-trip
 *   R18 wiring guards: shell assets, table opt-out, no dead serve.php link,
 *       messenger double-init fixed, portal.css has no stray hex colours,
 *       every pt_icon() name exists
 *
 * Hermetic: creates one temp portal user (+ fixture documents), deletes them,
 * the requests/notifications/audit rows they produced, and the session file.
 *
 * Usage:  php tests/_smoke_portal_redesign.php     Exit 0 = all pass, 1 = any fail, 2 = setup
 *
 * @session S-PORTAL-REDESIGN
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/app/portal/includes/ui.php';

use FleetForge\Billing\CustomerStatement;

$pass = 0;
$fail = [];
$ok   = static function (string $label, bool $cond, string $why = '') use (&$pass, &$fail): void {
    if ($cond) { $pass++; echo "PASS {$label}\n"; }
    else { $fail[] = $label; echo "FAIL {$label}" . ($why !== '' ? " — {$why}" : '') . "\n"; }
};

// ── Fixture customers ─────────────────────────────────────────────────────
$cust = db_row(
    "SELECT c.id, c.company_name
       FROM customers c
       JOIN invoices i ON i.customer_id = c.id AND i.deleted_at IS NULL AND i.status IN ('sent','partially_paid','overdue') AND i.balance_due > 0
      WHERE c.deleted_at IS NULL AND c.status = 'active'
      GROUP BY c.id ORDER BY COUNT(*) DESC LIMIT 1"
);
if (!$cust) { echo "SETUP FAIL: no active customer with open invoices\n"; exit(2); }
$cid = (int) $cust['id'];

$foreignInv = db_row(
    "SELECT id, invoice_number FROM invoices
      WHERE customer_id <> ? AND deleted_at IS NULL AND status IN ('sent','partially_paid','overdue','paid')
      ORDER BY id DESC LIMIT 1",
    [$cid]
);
$foreignPay = db_row("SELECT id FROM payments WHERE customer_id <> ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", [$cid]);
if (!$foreignInv) { echo "SETUP FAIL: need an invoice on another customer\n"; exit(2); }

$email = '_smoke_ptr_' . bin2hex(random_bytes(4)) . '@fleetforge.test';
$puid  = (int) db_insert('portal_users', [
    'customer_id'   => $cid,
    'name'          => 'Smoke Redesign',
    'email'         => $email,
    'password_hash' => password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT),
    'status'        => 'active',
    'is_primary'    => 1,
]);

$docIds = [];
$cleanup = static function () use ($puid, &$docIds): void {
    try {
        $reqIds = array_map('intval', array_column(db_select("SELECT id FROM portal_service_requests WHERE portal_user_id = ?", [$puid]), 'id'));
        foreach ($reqIds as $rid) {
            db_execute("DELETE FROM notifications WHERE url LIKE ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR", ['%requests/view?id=' . $rid]);
        }
        db_execute("DELETE FROM portal_service_requests WHERE portal_user_id = ?", [$puid]);
        db_execute("DELETE FROM audit_log WHERE user_name = ?", ['portal:' . $puid]);
        if ($docIds) {
            db_execute('DELETE FROM documents WHERE id IN (' . implode(',', array_map('intval', $docIds)) . ')');
        }
        db_execute("DELETE FROM portal_users WHERE id = ?", [$puid]);
    } catch (\Throwable $e) {
        echo "CLEANUP WARN: " . $e->getMessage() . "\n";
    }
};

// ── Session file for Herd FPM (/var/tmp) ─────────────────────────────────
$sessId = bin2hex(random_bytes(16));
$csrf   = bin2hex(random_bytes(32));
$sessFile = '/var/tmp/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_portal_user|' . serialize(['id' => $puid, 'customer_id' => $cid, 'name' => 'Smoke Redesign', 'email' => $email, 'is_primary' => true, 'company_name' => (string) $cust['company_name']])
    . 'ff_portal_last_activity|' . serialize(time())
    . 'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

$base = rtrim(APP_URL, '/') . '/' . trim(FF_BASE_PATH, '/') . '/';
$http = static function (string $method, string $path, $body = null, array $opt = []) use ($base, $sessId, $csrf): array {
    $ch = curl_init($base . ltrim($path, '/'));
    $headers = ['Accept: application/json, text/html, */*'];
    if (empty($opt['anon'])) $headers[] = 'Cookie: ff_session=' . $sessId;
    if ($method !== 'GET' && empty($opt['no_csrf'])) $headers[] = 'X-CSRF-Token: ' . $csrf;
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        if (!empty($opt['form'])) {
            $o[CURLOPT_POSTFIELDS] = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $o[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
    }
    $o[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $o);
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $hdr  = substr($raw, 0, $hs);
    $out  = substr($raw, $hs);
    preg_match('/^content-type:\s*([^\r\n;]+)/im', $hdr, $m);
    preg_match('/^location:\s*([^\r\n]+)/im', $hdr, $l);
    $json = json_decode($out, true);
    return ['code' => $code, 'type' => strtolower(trim($m[1] ?? '')), 'body' => $out, 'json' => is_array($json) ? $json : null, 'location' => trim($l[1] ?? '')];
};
$noFatal = static fn (string $b): bool => !preg_match('/(Fatal error|Uncaught |Warning: |Notice: |Deprecated: |Parse error)/', $b);

try {
    echo "Customer #{$cid} ({$cust['company_name']}), temp portal user #{$puid}\n";

    // ── R1 pages ─────────────────────────────────────────────────────────
    $openInv = db_row("SELECT id FROM invoices WHERE customer_id = ? AND deleted_at IS NULL AND status IN ('sent','partially_paid','overdue') AND balance_due > 0 ORDER BY due_date LIMIT 1", [$cid]);
    $lease   = db_row("SELECT id FROM leases WHERE customer_id = ? AND deleted_at IS NULL ORDER BY status = 'active' DESC, id DESC LIMIT 1", [$cid]);
    $pages = [
        'portal'                        => 'Balance due',
        'portal/invoices'               => 'PT_InvoiceList',
        'portal/invoices/view?id=' . (int) $openInv['id'] => 'Balance due',
        'portal/payments'               => 'Pay invoices',
        'portal/leases'                 => 'Leases',
        'portal/equipment'              => 'Equipment on rent',
        'portal/documents'              => 'Documents',
        'portal/requests'               => 'Requests',
        'portal/requests/create'        => 'How can we help?',
        'portal/notifications'          => 'Notifications',
        'portal/account'                => 'Email reminders',
        'portal/account/users'          => 'Team members',
        'portal/credit-applications'    => 'Credit application',
        'portal/messages'               => 'FF_PortalMessenger',
        'portal/chat'                   => 'FF_PortalChat',
    ];
    if ($lease) $pages['portal/leases/view?id=' . (int) $lease['id']] = 'Lease terms';
    $bad = [];
    foreach ($pages as $path => $marker) {
        $r = $http('GET', $path);
        if ($r['code'] !== 200 || !$noFatal($r['body']) || !str_contains($r['body'], $marker) || !str_contains($r['body'], 'pt-app')) {
            $bad[] = "{$path} (HTTP {$r['code']}" . ($noFatal($r['body']) ? '' : ', PHP error') . (str_contains($r['body'], $marker) ? '' : ", no '{$marker}'") . ')';
        }
    }
    $ok('R1 ' . count($pages) . ' portal pages render with no PHP error', !$bad, implode('; ', $bad));

    // ── R2 anonymous API → 401 JSON ──────────────────────────────────────
    $bad = [];
    foreach (['api/v1/portal/invoices/payable', 'api/v1/portal/invoices/list', 'api/v1/portal/search?q=ab', 'api/v1/portal/payments/receipt?id=1'] as $p) {
        $r = $http('GET', $p, null, ['anon' => true]);
        if ($r['code'] !== 401 || ($r['json']['error']['code'] ?? '') !== 'UNAUTHORIZED') $bad[] = "{$p} → {$r['code']}";
    }
    $ok('R2 portal JSON endpoints answer 401 JSON without a session', !$bad, implode('; ', $bad));

    // ── R3 payable == summary ────────────────────────────────────────────
    $sum = pt_account_summary($cid);
    $r = $http('GET', 'api/v1/portal/invoices/payable');
    $rows = $r['json']['data']['invoices'] ?? [];
    $total = '0.00';
    $allPayable = true;
    foreach ($rows as $row) { $total = bcadd($total, (string) $row['balance'], 2); $allPayable = $allPayable && $row['payable']; }
    $ok('R3 payable rows are payable and add up to the account balance',
        $r['code'] === 200 && $allPayable && count($rows) === $sum['open_count'] && bccomp($total, $sum['outstanding'], 2) === 0,
        "rows=" . count($rows) . "/{$sum['open_count']} total={$total}/{$sum['outstanding']}");

    // ── R4 payable ignores foreign ids ───────────────────────────────────
    $r = $http('GET', 'api/v1/portal/invoices/payable?ids=' . (int) $foreignInv['id']);
    $ok('R4 payable ignores another customer\'s invoice ids', $r['code'] === 200 && ($r['json']['data']['invoices'] ?? null) === []);

    // ── R5 list ──────────────────────────────────────────────────────────
    $r = $http('GET', 'api/v1/portal/invoices/list?tab=all&per_page=100');
    $c = $r['json']['data']['counts'] ?? [];
    $ids = array_column($r['json']['data']['invoices'] ?? [], 'id');
    $foreign = $ids ? db_count('SELECT COUNT(*) FROM invoices WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND customer_id <> ?', [$cid]) : 0;
    $w = $http('GET', 'api/v1/portal/invoices/list?tab=all&q=' . rawurlencode('%_%'));
    $ok('R5 list counts are consistent, rows are the customer\'s, LIKE wildcards are literal',
        $r['code'] === 200 && ($c['all'] ?? 0) >= ($c['open'] ?? 0) && ($c['open'] ?? 0) >= ($c['past_due'] ?? 0)
            && ($c['all'] ?? 0) >= ($c['paid'] ?? 0) && $foreign === 0 && ($w['json']['data']['total'] ?? -1) === 0,
        json_encode($c) . " foreign={$foreign} wildcard_total=" . ($w['json']['data']['total'] ?? 'n/a'));

    // ── R6 zip ───────────────────────────────────────────────────────────
    $zf = $http('GET', 'api/v1/portal/invoices/zip?ids=' . (int) $foreignInv['id']);
    $two = array_slice(array_column($rows, 'id'), 0, 2);
    $zo = $http('GET', 'api/v1/portal/invoices/zip?ids=' . implode(',', $two));
    $ok('R6 zip refuses foreign ids and bundles own PDFs',
        $zf['code'] === 404 && $zo['code'] === 200 && $zo['type'] === 'application/zip' && str_starts_with($zo['body'], 'PK'),
        "foreign={$zf['code']} own={$zo['code']} {$zo['type']}");

    // ── R7 export ────────────────────────────────────────────────────────
    $e = $http('GET', 'api/v1/portal/invoices/export?tab=open');
    $lines = array_values(array_filter(preg_split('/\r?\n/', $e['body']) ?: [], static fn ($l) => $l !== ''));
    $ok('R7 CSV export has a BOM, a header and one row per open invoice',
        $e['code'] === 200 && $e['type'] === 'text/csv' && str_starts_with($e['body'], "\xEF\xBB\xBFInvoice,") && count($lines) - 1 === (int) ($c['open'] ?? -1)
            && $noFatal($e['body']),
        "code={$e['code']} rows=" . (count($lines) - 1) . "/" . ($c['open'] ?? '?'));

    // ── R8 statement ─────────────────────────────────────────────────────
    $s  = $http('GET', 'api/v1/portal/statement?from=2026-01-01&to=' . ff_today());
    $sb = $http('GET', 'api/v1/portal/statement?from=2026-06-01&to=2026-01-01');
    $adminSrc = (string) file_get_contents(FF_ROOT . '/api/v1/accounting/ar/statement.php');
    $built = CustomerStatement::build($cid, '2026-01-01', ff_today());
    $ok('R8 statement PDF streams; bad range 422; staff + portal share CustomerStatement',
        $s['code'] === 200 && $s['type'] === 'application/pdf' && str_starts_with($s['body'], '%PDF') && $sb['code'] === 422
            && str_contains($adminSrc, 'CustomerStatement::build(') && !str_contains($adminSrc, 'FROM invoices') && $built !== null,
        "pdf={$s['code']} badrange={$sb['code']}");

    // ── R9 receipt ───────────────────────────────────────────────────────
    $own = db_row("SELECT id FROM payments WHERE customer_id = ? AND deleted_at IS NULL AND status IN ('pending','cleared') ORDER BY id DESC LIMIT 1", [$cid]);
    $ro  = $own ? $http('GET', 'api/v1/portal/payments/receipt?id=' . (int) $own['id']) : null;
    $rf  = $foreignPay ? $http('GET', 'api/v1/portal/payments/receipt?id=' . (int) $foreignPay['id']) : ['code' => 404];
    $ok('R9 receipt: own payment → PDF, another customer\'s → 404',
        (!$own || ($ro['code'] === 200 && str_starts_with($ro['body'], '%PDF'))) && $rf['code'] === 404,
        'own=' . ($ro['code'] ?? 'none') . " foreign={$rf['code']}");

    // ── R10 payment notice ───────────────────────────────────────────────
    $payCount0 = db_count("SELECT COUNT(*) FROM payments WHERE customer_id = ?", [$cid]);
    $inv2 = db_select("SELECT id, invoice_number FROM invoices WHERE id IN (" . implode(',', array_map('intval', $two ?: [0])) . ")");
    $n0 = $http('POST', 'api/v1/portal/payments/notice', ['amount' => '10', 'paid_on' => ff_today(), 'method' => 'e_transfer'], ['no_csrf' => true]);
    $n1 = $http('POST', 'api/v1/portal/payments/notice', ['amount' => '12.345', 'paid_on' => ff_today(), 'method' => 'e_transfer']);
    $n2 = $http('POST', 'api/v1/portal/payments/notice', [
        'amount' => '1,234.50', 'paid_on' => ff_today(), 'method' => 'check', 'reference' => 'SMOKE-CHQ-1',
        'invoice_ids' => array_merge($two, [(int) $foreignInv['id']]), 'note' => 'smoke',
    ]);
    $reqId = (int) ($n2['json']['data']['request_id'] ?? 0);
    $req   = $reqId ? db_row("SELECT * FROM portal_service_requests WHERE id = ?", [$reqId]) : null;
    $msgOk = $req && !str_contains((string) $req['message'], (string) $foreignInv['invoice_number']);
    foreach ($inv2 as $iv) { $msgOk = $msgOk && str_contains((string) $req['message'], (string) $iv['invoice_number']); }
    $ok('R10 payment notice: CSRF enforced, amount validated, foreign invoices dropped, billing request created, no payment recorded',
        $n0['code'] === 403 && $n1['code'] === 422 && $n2['code'] === 201 && $req
            && $req['request_type'] === 'billing_inquiry' && (int) $req['customer_id'] === $cid
            && str_starts_with((string) $req['subject'], 'Payment sent: $1,234.50') && $msgOk
            && db_count("SELECT COUNT(*) FROM payments WHERE customer_id = ?", [$cid]) === $payCount0,
        "csrf={$n0['code']} badamt={$n1['code']} ok={$n2['code']} req=" . ($req['subject'] ?? 'none'));

    // ── R11 request conversation ─────────────────────────────────────────
    $rv = $reqId ? $http('GET', 'portal/requests/view?id=' . $reqId . '&created=1') : ['code' => 0, 'body' => ''];
    $ok('R11 the new request renders as a conversation', $rv['code'] === 200 && $noFatal($rv['body']) && str_contains($rv['body'], 'SMOKE-CHQ-1') && str_contains($rv['body'], 'Request sent.'));

    // ── R12 search scope ─────────────────────────────────────────────────
    $sOwn = $http('GET', 'api/v1/portal/search?q=' . rawurlencode(substr((string) ($inv2[0]['invoice_number'] ?? 'INV'), -5)));
    $sFor = $http('GET', 'api/v1/portal/search?q=' . rawurlencode((string) $foreignInv['invoice_number']));
    $forHit = false;
    foreach ($sFor['json']['data']['results'] ?? [] as $hit) { if (str_contains((string) $hit['title'], (string) $foreignInv['invoice_number'])) $forHit = true; }
    $ok('R12 search finds own invoices and never another customer\'s', $sOwn['code'] === 200 && count($sOwn['json']['data']['results'] ?? []) > 0 && !$forHit);

    // ── R13 documents visibility ─────────────────────────────────────────
    $mk = static function (string $type, int $entity, int $private, string $title) use (&$docIds): int {
        $id = (int) db_insert('documents', [
            'entity_type' => $type, 'entity_id' => $entity, 'title' => $title, 'document_type' => 'other',
            'file_path' => 'smoke/does-not-exist-' . bin2hex(random_bytes(4)) . '.pdf', 'file_name' => $title . '.pdf',
            'mime_type' => 'application/pdf', 'is_current' => 1, 'is_private' => $private,
        ]);
        $docIds[] = $id;
        return $id;
    };
    $otherUnit = db_row(
        "SELECT eu.id FROM equipment_units eu
          WHERE eu.deleted_at IS NULL
            AND eu.id NOT IN (SELECT equipment_unit_id FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL)
          LIMIT 1",
        [$cid]
    );
    $dPublic  = $mk('customer', $cid, 0, 'SmokeDocPublic');
    $dPrivate = $mk('customer', $cid, 1, 'SmokeDocPrivate');
    $dForeign = $mk('customer', (int) db_row("SELECT customer_id FROM invoices WHERE id = ?", [$foreignInv['id']])['customer_id'], 0, 'SmokeDocForeign');
    $dUnit    = $otherUnit ? $mk('equipment_unit', (int) $otherUnit['id'], 0, 'SmokeDocOtherUnit') : 0;
    $page = $http('GET', 'portal/documents');
    $fPub = $http('GET', 'api/v1/portal/documents/file?id=' . $dPublic);
    $fPri = $http('GET', 'api/v1/portal/documents/file?id=' . $dPrivate);
    $fFor = $http('GET', 'api/v1/portal/documents/file?id=' . $dForeign);
    $fUni = $dUnit ? $http('GET', 'api/v1/portal/documents/file?id=' . $dUnit) : ['code' => 404, 'json' => ['error' => ['code' => 'NOT_FOUND']]];
    $ok('R13 documents: only the customer\'s shareable docs are listed or streamable',
        str_contains($page['body'], 'SmokeDocPublic') && !str_contains($page['body'], 'SmokeDocPrivate')
            && !str_contains($page['body'], 'SmokeDocForeign') && !str_contains($page['body'], 'SmokeDocOtherUnit')
            && ($fPub['json']['error']['code'] ?? '') === 'FILE_MISSING'
            && ($fPri['json']['error']['code'] ?? '') === 'NOT_FOUND' && ($fFor['json']['error']['code'] ?? '') === 'NOT_FOUND'
            && ($fUni['json']['error']['code'] ?? '') === 'NOT_FOUND',
        'public=' . ($fPub['json']['error']['code'] ?? $fPub['code']) . ' private=' . ($fPri['json']['error']['code'] ?? '') . ' foreign=' . ($fFor['json']['error']['code'] ?? '') . ' unit=' . ($fUni['json']['error']['code'] ?? ''));

    // ── R14 go.php ───────────────────────────────────────────────────────
    $g = $http('GET', 'portal/payments/go?invoice=' . (int) $foreignInv['id']);
    $ok('R14 payments/go treats another customer\'s invoice as missing', $g['code'] === 200 && str_contains($g['body'], 'find that invoice on your account') && $g['location'] === '');

    // ── R15 initiate_qbo_payment error text ──────────────────────────────
    $ip = $http('POST', 'api/v1/portal/invoices/initiate_qbo_payment', ['invoice_id' => (int) $openInv['id']]);
    $err = (string) ($ip['json']['data']['error'] ?? '');
    $ok('R15 initiate_qbo_payment never shows operator-facing error text',
        !empty($ip['json']['data']['success']) || ($err !== '' && !preg_match('/settings|quickbooks\.|QBO|sync first|ff_invoice/i', $err)),
        "error='{$err}'");

    // ── R16 reply cap ────────────────────────────────────────────────────
    $rr = $reqId ? $http('POST', 'api/v1/portal/requests/reply', ['request_id' => $reqId, 'body' => str_repeat('x', 5001)]) : ['code' => 0];
    $ok('R16 request reply over 5,000 characters → 422', $rr['code'] === 422);

    // ── R17 email-reminder preferences round-trip ────────────────────────
    $pr = $http('POST', 'portal/account', ['csrf_token' => $csrf, 'action' => 'update_notifications', 'pref' => ['invoice_due_soon' => '1', 'statement' => '1']], ['form' => true]);
    $prefs = json_decode((string) (db_row("SELECT notification_preferences FROM portal_users WHERE id = ?", [$puid])['notification_preferences'] ?? '{}'), true) ?: [];
    $ok('R17 email-reminder preferences save and round-trip',
        $pr['code'] === 302 && str_contains($pr['location'], 'saved=email') && ($prefs['invoice_due_soon'] ?? null) === true
            && ($prefs['statement'] ?? null) === true && ($prefs['invoice_overdue'] ?? null) === false && ($prefs['compliance_expiring'] ?? null) === false,
        "code={$pr['code']} prefs=" . json_encode($prefs));

    // ── R18 wiring guards ────────────────────────────────────────────────
    $w = [];
    $hdr = (string) file_get_contents(FF_ROOT . '/app/portal/includes/header.php');
    $ftr = (string) file_get_contents(FF_ROOT . '/app/portal/includes/footer.php');
    if (!str_contains($hdr, "assets/css/portal.css")) $w[] = 'header does not load portal.css';
    $pj = strpos($ftr, 'assets/js/portal.js'); $al = strpos($ftr, 'alpinejs/cdn.min.js');
    if ($pj === false || $al === false || $pj > $al) $w[] = 'portal.js must load before Alpine';
    $all = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FF_ROOT . '/app/portal', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if (str_ends_with((string) $f, '.php')) $all .= "\n/*FILE " . $f . "*/\n" . file_get_contents((string) $f); }
    if (preg_match_all('/<table(?![^>]*data-no-auto-label)(?![^>]*class="pt-sr")[^>]*class="pt-table/', $all) > 0) $w[] = 'a .pt-table lacks data-no-auto-label';
    if (str_contains($all, 'api/v1/documents/serve.php')) $w[] = 'dead serve.php link remains';
    if (str_contains((string) file_get_contents(FF_ROOT . '/app/portal/messages/index.php'), 'x-init="init(')) $w[] = 'messages x-init double-init';
    if (!str_contains((string) file_get_contents(FF_ROOT . '/public/assets/js/app.js'), 'if (this._booted) return;')) $w[] = 'FF_PortalMessenger guard missing';
    $css = (string) file_get_contents(FF_ROOT . '/public/assets/css/portal.css');
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $css = (string) preg_replace('/url\("data:[^"]*"\)/', '', $css);
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $hex);
    $stray = array_diff(array_unique(array_map('strtolower', $hex[0])), ['#fff', '#000', '#0b0b0c']);
    if ($stray) $w[] = 'portal.css stray hex: ' . implode(',', $stray);
    preg_match_all("/pt_icon\\('([a-z0-9-]+)'/", $all . file_get_contents(FF_ROOT . '/app/portal/includes/ui.php'), $icons);
    $missing = array_filter(array_unique($icons[1]), static fn ($n) => !\FleetForge\Sop\SopIcons::exists($n));
    if ($missing) $w[] = 'missing icons: ' . implode(',', $missing);
    $ok('R18 wiring guards (assets, table opt-out, dead links, double-init, stray hex, icons)', !$w, implode('; ', $w));

} finally {
    @unlink($sessFile);
    $cleanup();
    $left = db_count("SELECT COUNT(*) FROM portal_users WHERE id = ?", [$puid])
          + db_count("SELECT COUNT(*) FROM portal_service_requests WHERE portal_user_id = ?", [$puid]);
    echo ($left === 0 ? "PASS" : "FAIL") . " cleanup: temp portal user, requests, documents and notices removed\n";
    if ($left === 0) $pass++; else $fail[] = 'cleanup';
}

echo "\nportal_redesign_smoke: {$pass}/" . ($pass + count($fail)) . " PASS" . ($fail ? ' — FAIL: ' . implode(', ', $fail) : '') . "\n";
exit($fail ? 1 : 0);
