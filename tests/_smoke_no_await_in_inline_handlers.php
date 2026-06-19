<?php declare(strict_types=1);

/**
 * tests/_smoke_no_await_in_inline_handlers.php
 *
 * Guards against `await` inside a NATIVE inline event-handler attribute
 * (onclick="…", onsubmit="…", onchange="…", …).
 *
 * WHY: a native inline handler is compiled by the browser into a plain,
 * NON-async function. `await` is only legal inside an async function, so the
 * handler is a SyntaxError — the browser drops it. For a plain button that
 * means the click does nothing; for a <button type="submit"> it means the form
 * submits WITHOUT the intended confirm, i.e. a destructive action fires with no
 * prompt. Alpine `@click` / `x-on:` handlers are EXEMPT — Alpine evaluates them
 * in an async context, so `await` is valid there. This guard therefore only
 * inspects real `on<event>=` HTML attributes.
 *
 * Surfaced 2026-06-19: six confirm/delete buttons were dead this way —
 * lease delete (commit 8b1b1b8) + suspend/delete user + reset/deactivate/delete
 * portal user (commit 27bec55). Origin: a hand-written `await FF_Confirm.ask()`
 * pattern copy-pasted across the settings/leases pages.
 *
 * Fix pattern: drop the await and gate on a .then() chain, e.g.
 *   onclick="event.preventDefault();var f=this.form;FF_Confirm.ask('…').then(function(ok){if(ok)f.submit();});"
 *
 * Usage:  php tests/_smoke_no_await_in_inline_handlers.php
 * Exit:   0 = clean, 1 = at least one offending handler.
 *
 * @session S-INLINE-HANDLER-AWAIT-GUARD
 */

$root = dirname(__DIR__);
$dirs = ['app', 'includes'];

// Native DOM event-handler attribute names (NOT Alpine @click / x-on:).
$events = implode('|', [
    'click','change','submit','input','keydown','keyup','keypress',
    'mousedown','mouseup','mouseover','mouseout','mouseenter','mouseleave',
    'blur','focus','focusin','focusout','dblclick','scroll','wheel',
    'paste','cut','copy','drop','reset','toggle','load','error',
]);
// on<event>="…await…"  OR  on<event>='…await…'  — the \bon prevents matching
// Alpine bindings (@click / :click / x-on:click never start with the literal "on").
$re = '/\bon(?:' . $events . ')\s*=\s*("[^"]*\bawait\b[^"]*"|\'[^\']*\bawait\b[^\']*\')/is';

$hits = [];
foreach ($dirs as $d) {
    $base = $root . '/' . $d;
    if (!is_dir($base)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $c = file_get_contents($f->getPathname());
        if ($c === false) continue;
        if (preg_match_all($re, $c, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $line = substr_count(substr($c, 0, (int) $hit[1]), "\n") + 1;
                $rel  = str_replace($root . '/', '', $f->getPathname());
                $hits[] = ['loc' => "{$rel}:{$line}", 'snip' => trim(preg_replace('/\s+/', ' ', $hit[0]))];
            }
        }
    }
}

echo "FleetForge — no-await-in-inline-handlers guard\n";
echo str_repeat('=', 70), "\n";
echo "Scanned: " . implode(', ', $dirs) . " (*.php)\n\n";

if (empty($hits)) {
    echo "PASS  No `await` found in any native inline on<event>= handler.\n";
    exit(0);
}

echo "FAIL  " . count($hits) . " native inline handler(s) use `await` "
   . "(SyntaxError → dead button / unconfirmed submit):\n";
foreach ($hits as $h) {
    echo "  {$h['loc']}\n      :: " . substr($h['snip'], 0, 110) . "\n";
}
echo "\n  Fix: remove `await`; gate on a .then() chain instead. Alpine @click may use await.\n";
exit(1);
