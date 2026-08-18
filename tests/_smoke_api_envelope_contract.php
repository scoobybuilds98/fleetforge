<?php
declare(strict_types=1);

/**
 * tests/_smoke_api_envelope_contract.php
 *
 * S-ENVELOPE-SWEEP — every FleetForge endpoint answers one of two shapes:
 *
 *   json_success($payload)  ->  { success: true,  data: <payload> }
 *   json_paginated($items…) ->  { success: true,  data: { items, pagination } }
 *   json_error($code,$msg)  ->  { success: false, error: { code, message, … } }
 *
 * FF_Api.get/post resolve to that WHOLE envelope (they return res.json()), so a
 * page must reach through `.data` for payload and `.error` for the reason. Read
 * a payload key straight off the envelope and you get `undefined` — which the
 * near-universal `|| []` / `|| 0` / `|| 'fallback'` idiom converts into a
 * convincing empty state or generic error instead of a visible failure.
 *
 * That is not hypothetical. Three separate instances shipped and survived:
 *   • Credit Notes read `data.data` / `data.total` / `data.last_page` — the
 *     list rendered one phantom all-dashes row and never its empty state.
 *   • Seven QuickBooks consoles read `r.rows` / `r.kpis` / `r.total` — the
 *     Invoices console showed "0 rows" over 188 real ones.
 *   • Retry/resolve handlers read `r.action` — every SUCCESSFUL re-enqueue
 *     reported "Skipped: gate refused".
 *
 * This smoke is the regression lock. It parses the shipped pages rather than
 * trusting a convention, so a new page that reads the envelope wrong fails here.
 *
 * DOCUMENTED EXCEPTIONS. A handful of endpoints under api/v1/ai/ bypass the
 * helpers on their SUCCESS path — they `echo json_encode($payload)` directly, so
 * their payload really is top-level (their FAILURE paths still use json_error(),
 * so `.error.message` stays correct there). A caller of one of those must carry
 * a comment containing EXCEPTION-ENVELOPE within the 8 lines above the read,
 * explaining which endpoint and why. That keeps the exemption reviewable instead
 * of silently widening the rule.
 *
 * Run: php tests/_smoke_api_envelope_contract.php   (0 = pass, 1 = fail)
 * @session S-ENVELOPE-SWEEP
 */

require_once dirname(__DIR__) . '/config/app.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "PASS  {$l}\n"; } else { $fail++; echo "FAIL  {$l}\n"; }
}

$root = dirname(__DIR__);

/**
 * Read a page with its JS line comments stripped.
 *
 * WHY: several of these files now carry a WHY-comment that QUOTES the old broken
 * expression (e.g. "used to read data.last_page"). A naive str_contains() would
 * match that prose and report the bug as still present. Assertions below must
 * see code only.
 */
function src_no_comments(string $path): string
{
    $out = [];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
            continue;
        }
        // Trailing comment on a code line.
        $out[] = preg_replace('~\s//(?![^\'"]*[\'"]\s*[;,)]).*$~', '', $line);
    }
    return implode("\n", $out);
}

// ── The contract itself, asserted against api/bootstrap.php ──────────────────
// If these ever change, every assertion below is measuring the wrong thing.
echo "\n── Envelope contract (api/bootstrap.php) ────────────────────\n";
$bootstrap = (string) file_get_contents($root . '/api/bootstrap.php');
ok(str_contains($bootstrap, "'data'    => ["), 'json_paginated nests under data');
ok(str_contains($bootstrap, "'items'      => \$items"), 'json_paginated emits data.items');
ok(str_contains($bootstrap, "'pagination' => ["), 'json_paginated emits data.pagination');
ok(str_contains($bootstrap, "'total_pages' => \$totalPages"), 'pagination uses total_pages (never last_page)');
ok(str_contains($bootstrap, "'error'   => array_merge("), 'json_error nests under error');

// FF_Api must still hand back the raw envelope — the whole premise.
$appJs = (string) file_get_contents($root . '/public/assets/js/app.js');
ok(
    (bool) preg_match('/async\s+get\s*\([^)]*\)\s*\{(?:[^{}]|\{[^{}]*\})*?return\s+res\.json\(\)/s', $appJs)
    || str_contains($appJs, 'return res.json();'),
    'FF_Api returns the whole envelope, not the unwrapped payload'
);

// ── Sweep every admin + portal page for envelope-level payload reads ─────────
echo "\n── Payload keys must be read through .data ──────────────────\n";

/** Keys that only ever exist INSIDE data — never at the envelope level. */
$payloadKeys = [
    'items', 'rows', 'pagination', 'total', 'total_pages', 'last_page',
    'kpis', 'action', 'reason',
];

/** Every .php page under app/ (list pages, show pages, portal). */
$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $pages[] = $f->getPathname();
    }
}
sort($pages);
ok(count($pages) > 100, 'scanned the app tree (' . count($pages) . ' pages)');

/**
 * Find names bound to an API response, then look for payload-key reads on them
 * inside the handler window that follows. Deliberately narrow: it only inspects
 * the ~30 lines after a call site, so unrelated same-named locals elsewhere in
 * the file cannot produce a false positive.
 */
$bindRe = '/(?:(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*)?await\s+FF_Api\.(?:get|post)\('
        . '|FF_Api\.(?:get|post)\([^;]*?\)\s*\.then\(\s*\(?\s*([A-Za-z_$][\w$]*)/';

$offenders = [];
foreach ($pages as $page) {
    $lines = explode("\n", (string) file_get_contents($page));
    foreach ($lines as $i => $line) {
        if (!preg_match($bindRe, $line, $m)) {
            continue;
        }
        $name = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        if ($name === '') {
            continue;
        }
        $end = min($i + 30, count($lines));
        for ($j = $i; $j < $end; $j++) {
            $body = trim($lines[$j]);
            if ($body === '' || str_starts_with($body, '//') || str_starts_with($body, '*')) {
                continue;
            }
            foreach ($payloadKeys as $key) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\.' . $key . '\b/', $body)) {
                    $offenders[] = str_replace($root . '/', '', $page) . ':' . ($j + 1)
                                 . '  ' . $name . '.' . $key;
                }
            }
            // Stop at the end of the handler block.
            if ($j > $i + 3 && preg_match('/^\s{0,16}\},?\s*$/', $lines[$j])) {
                break;
            }
        }
    }
}
$offenders = array_values(array_unique($offenders));
ok($offenders === [], 'no page reads a payload key off the envelope');
foreach (array_slice($offenders, 0, 15) as $o) {
    echo "        -> {$o}\n";
}

// ── The specific pages that shipped broken — named, so they stay fixed ───────
echo "\n── Regression locks on the three shipped instances ──────────\n";

// (a) Credit Notes: json_paginated reader.
$cn = src_no_comments($root . '/app/admin/credit_notes/index.php');
ok(str_contains($cn, 'r.data.items'),                'credit_notes reads data.items');
ok(!str_contains($cn, 'data.last_page'),             'credit_notes no longer reads last_page');
ok(!preg_match('/this\.rows\s*=\s*data\.data/', $cn), 'credit_notes no longer reads data.data as the row array');

// (b) The seven QuickBooks push consoles: json_success readers.
$qbo = ['bills', 'invoices', 'payments', 'credit_memos', 'refund_receipts', 'bill_payments', 'journal_entries'];
foreach ($qbo as $name) {
    $src = src_no_comments($root . '/app/admin/quickbooks/' . $name . '.php');
    ok(str_contains($src, 'const d = r.data || {};'), "quickbooks/{$name} unwraps r.data before reading rows/kpis/total");
    ok(!preg_match('/this\.(?:rows|kpis|total|initRows|initKpis|initTotal)\s*=\s*r\.(?:rows|kpis|total)\b/', $src),
        "quickbooks/{$name} has no envelope-level rows/kpis/total read");
    ok(!preg_match("/if \(r\.action ===/", $src), "quickbooks/{$name} retry reads d.action, not r.action");
}

// (c) The action/reason handlers outside /quickbooks/.
$inv = src_no_comments($root . '/app/admin/invoices/show.php');
ok(str_contains($inv, "d.action === 'enqueued'"),   'invoices/show retry reads d.action');
ok(!str_contains($inv, "r.action === 'enqueued'"),  'invoices/show retry no longer reads r.action');
$drift = src_no_comments($root . '/app/admin/quickbooks/drift_show.php');
ok(!preg_match('/res\.action\b/', $drift),          'drift_show reads d.action, not res.action');
ok(!preg_match('/res\.reason\b/', $drift),          'drift_show reads d.reason, not res.reason');

// ── Error text must come from .error.message ────────────────────────────────
echo "\n── Error text must be read through .error ───────────────────\n";
$msgOffenders = [];
foreach ($pages as $page) {
    $src = (string) file_get_contents($page);
    if (!preg_match_all($bindRe, $src, $mm, PREG_SET_ORDER)) {
        continue;
    }
    $names = [];
    foreach ($mm as $m) {
        $n = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        // err/e/ex hold caught Errors, where .message IS correct.
        if ($n !== '' && !in_array($n, ['err', 'e', 'ex', 'error', 'exc'], true)) {
            $names[$n] = true;
        }
    }
    foreach (array_keys($names) as $n) {
        foreach (explode("\n", $src) as $i => $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*')) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($n, '/') . '\.message\b/', $line)) {
                // Honour a documented EXCEPTION-ENVELOPE marker in the 8 lines above.
                $exempt = false;
                $all = explode("\n", $src);
                for ($k = max(0, $i - 8); $k < $i; $k++) {
                    if (str_contains($all[$k], 'EXCEPTION to the envelope contract')
                        || str_contains($all[$k], 'EXCEPTION-ENVELOPE')) {
                        $exempt = true;
                        break;
                    }
                }
                if (!$exempt) {
                    $msgOffenders[] = str_replace($root . '/', '', $page) . ':' . ($i + 1) . '  ' . $n . '.message';
                }
            }
        }
    }
}
$msgOffenders = array_values(array_unique($msgOffenders));
ok($msgOffenders === [], 'no page reads the error text off the envelope (' . count($msgOffenders) . ' found)');

// The exemption must stay exercised — if this ever drops to zero the marker
// logic above is dead code and the next real exception will be missed.
$settings = (string) file_get_contents($root . '/app/admin/settings/index.php');
ok(str_contains($settings, 'EXCEPTION to the envelope contract'),
   'the documented non-envelope caller (ai/test-connection) still carries its marker');
foreach (array_slice($msgOffenders, 0, 15) as $o) {
    echo "        -> {$o}\n";
}

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
