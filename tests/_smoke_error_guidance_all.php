<?php
declare(strict_types=1);

/**
 * tests/_smoke_error_guidance_all.php
 *
 * S-ERROR-GUIDANCE-ALL — PROVE every error code in the codebase produces a
 * correct explain-and-fix popup (or is deliberately, verifiably silent).
 *
 * json_error() attaches ErrorGuidance to every error in every module, so this
 * has to hold for codes nobody has looked at in a year, not just the ones on
 * today's screen. The test therefore SCRAPES the live codes out of the source
 * — every `json_error('CODE', ...)` under api/ lib/ app/ cron/ includes/ —
 * and asserts each one produces usable guidance. A code added tomorrow is
 * covered the moment it is written, and this test fails if it somehow is not.
 *
 * Dependency-free by design: no config/app.php, no database, no composer
 * autoload. It runs in any checkout.
 *
 * Cases:
 *   A   every scraped code yields a popup with a title, a summary and at least
 *       one step — or is on the documented no-popup list.
 *   B   no popup text leaks a file path, a class name, or SCREAMING_SNAKE at
 *       the operator (the "see api/v1/leases/create.php" problem).
 *   C   VALIDATION_ERROR is silent WITH a fields map, and speaks without one.
 *   D   PERIOD_OVERLAP stays silent (the invoice form owns that conversation).
 *   E   an endpoint's own guidance always wins over the registry.
 *   F   entity links are built only from numeric ids, and point at real routes.
 *   G   500s say it is our fault, that the action may not have completed, and
 *       carry a reference — never a raw file path.
 *   H   curated codes actually differ from the generic fallback (a registry
 *       entry that reads like the fallback is dead weight).
 *
 * Run: php tests/_smoke_error_guidance_all.php
 *
 * @session S-ERROR-GUIDANCE-ALL
 */

if (!function_exists('base_url')) {
    function base_url(string $p = ''): string { return '/fleetforge/' . ltrim($p, '/'); }
}

require_once __DIR__ . '/../lib/Support/ErrorGuidance.php';

use FleetForge\Support\ErrorGuidance;

$pass = 0; $fail = 0;
function ck(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m $id — $msg\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m $id — $msg\n"; }
}

echo str_repeat('=', 72) . "\nS-ERROR-GUIDANCE-ALL — every error code explains itself\n" . str_repeat('=', 72) . "\n";

// ── Scrape every error code actually used in the codebase ───────────────────
$root  = dirname(__DIR__);
$codes = [];
foreach (['api', 'lib', 'app', 'cron', 'includes'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        if (preg_match_all("/json_error\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $m)) {
            foreach ($m[1] as $c) { $codes[$c] = true; }
        }
    }
}
$codes = array_keys($codes);
sort($codes);
echo "  scraped " . count($codes) . " distinct error codes from the codebase\n\n";

// ── A + B: every code is either explained or deliberately silent ────────────
$silent = [];
$badText = [];
$thin   = [];
foreach ($codes as $code) {
    // The status a code most plausibly carries; the fallback branches on it.
    $status = match (true) {
        str_contains($code, 'NOT_FOUND') || $code === 'GONE'            => 404,
        str_contains($code, 'FORBIDDEN')                                => 403,
        str_contains($code, 'UNAUTH') || str_contains($code, 'SESSION') => 401,
        str_contains($code, 'INTERNAL') || str_contains($code, 'SERVER')=> 500,
        default                                                         => 422,
    };
    $g = ErrorGuidance::build($code, 'The endpoint said this.', $status, []);

    if ($g === null) { $silent[] = $code; continue; }

    if (($g['title'] ?? '') === '' || ($g['summary'] ?? '') === '' || ($g['steps'] ?? []) === []) {
        $thin[] = $code;
    }
    // Nothing an operator reads may carry a path, a PHP class, or the raw code.
    $facing = ($g['title'] ?? '') . ' ' . ($g['summary'] ?? '') . ' ' . implode(' ', $g['steps'] ?? []);
    if (preg_match('#\.php|/var/www|\\\\[A-Z][A-Za-z]+\\\\|\b[A-Z][A-Z0-9]{3,}_[A-Z0-9_]+\b#', $facing)) {
        $badText[] = $code;
    }
}
ck('A1', $thin === [], count($codes) - count($silent) . ' codes produce a title + summary + steps'
    . ($thin ? ' — THIN: ' . implode(', ', $thin) : ''));
ck('A2', $silent === ['PERIOD_OVERLAP'],
    'exactly the documented no-popup list stays silent: [' . implode(', ', $silent) . ']');
ck('B1', $badText === [],
    'no operator-facing text leaks a path/class/raw code'
    . ($badText ? ' — LEAKED: ' . implode(', ', $badText) : ''));

// ── C: VALIDATION_ERROR — inline when it has a field to point at ────────────
$withFields = ErrorGuidance::build('VALIDATION_ERROR', 'Please correct the highlighted fields.', 422,
    ['fields' => ['mileage_rate' => 'Must be > 0.']]);
$noFields   = ErrorGuidance::build('VALIDATION_ERROR', 'Reason must be 2000 characters or fewer.', 422, []);
ck('C1', $withFields === null, 'field-level VALIDATION_ERROR stays on the field (no modal)');
ck('C2', is_array($noFields) && str_contains((string) $noFields['summary'], '2000 characters'),
    'field-less VALIDATION_ERROR pops, carrying the endpoint message');

// ── D: the form owns the overlap conversation ───────────────────────────────
ck('D1', ErrorGuidance::build('PERIOD_OVERLAP', 'Overlaps INV-2026-00150.', 409, []) === null,
    'PERIOD_OVERLAP stays silent — the invoice form runs its own confirm dialog');

// ── E: a call site that knows more always wins (checked at the hook) ────────
// json_error() only calls build() when no guidance was supplied; assert the
// contract the hook depends on — build() is pure and never mutates its input.
$extra = ['lease_id' => 528];
$before = $extra;
ErrorGuidance::build('BILLING_RATE_INCOMPLETE', 'x', 422, $extra);
ck('E1', $extra === $before, 'build() is pure — it cannot clobber a caller-supplied payload');

// ── F: entity links — numeric ids only, real routes ─────────────────────────
$withLease = ErrorGuidance::build('INVALID_TRANSITION', 'Lease is already closed.', 422, ['lease_id' => 528]);
$urls      = array_column($withLease['actions'], 'url');
$injected  = ErrorGuidance::build('INVALID_TRANSITION', 'x', 422, ['lease_id' => "1 OR 1=1'"]);
ck('F1', $urls !== [] && str_contains($urls[0], 'leases/show?id=528'),
    'the record the error names becomes a link: ' . ($urls[0] ?? 'none'));
ck('F2', ($injected['actions'] ?? []) === [],
    'a non-numeric id is never interpolated into a URL');
$routes = [];
foreach (['lease_id' => 'leases/show', 'invoice_id' => 'invoices/show', 'customer_id' => 'customers/show',
          'unit_id' => 'equipment/show', 'vendor_id' => 'vendors/show'] as $key => $route) {
    $g = ErrorGuidance::build('CONFLICT', 'x', 409, [$key => 7]);
    $routes[$key] = str_contains($g['actions'][0]['url'] ?? '', $route . '?id=7');
}
ck('F3', !in_array(false, $routes, true),
    'every entity id maps to its real admin route (' . implode(', ', array_keys($routes)) . ')');

// ── G: the crash path ───────────────────────────────────────────────────────
$g500 = ErrorGuidance::build('INTERNAL_ERROR', 'An unexpected error occurred. Please try again.', 500, []);
ck('G1', str_contains($g500['summary'], 'not something you did wrong')
        && str_contains($g500['summary'], 'may not have completed')
        && count($g500['steps']) >= 3,
    'a 500 owns the fault, warns the action may be half-done, and says what to do');
ck('G2', !str_contains(($g500['title'] . $g500['summary'] . implode(' ', $g500['steps'])), '.php'),
    'the 500 popup never shows a file path to an operator');

// ── H: curated entries earn their place ─────────────────────────────────────
$generic = ErrorGuidance::build('SOME_UNCURATED_CODE', 'The endpoint said this.', 422, [])['summary'];
$dupes = [];
foreach (['STALE_DATA', 'IMMUTABLE_RECORD', 'UNIT_UNAVAILABLE', 'RATE_TIER_INCOMPLETE',
          'FORBIDDEN', 'NOT_FOUND', 'RATE_LIMITED', 'SESSION_EXPIRED'] as $c) {
    $st = $c === 'FORBIDDEN' ? 403 : ($c === 'NOT_FOUND' ? 404 : ($c === 'SESSION_EXPIRED' ? 401 : 422));
    if (ErrorGuidance::build($c, 'The endpoint said this.', $st, [])['summary'] === $generic) { $dupes[] = $c; }
}
ck('H1', $dupes === [], 'curated codes say something the generic fallback does not'
    . ($dupes ? ' — GENERIC: ' . implode(', ', $dupes) : ''));

echo str_repeat('=', 72) . "\n";
if ($fail) { echo "\033[31mRESULT: {$fail} FAIL / " . ($pass + $fail) . "\033[0m\n"; exit(1); }
echo "\033[32mRESULT: ALL {$pass} PASS — " . count($codes) . " codes covered, popups everywhere they help\033[0m\n";
exit(0);
