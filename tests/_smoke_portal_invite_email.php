<?php
declare(strict_types=1);

/**
 * tests/_smoke_portal_invite_email.php
 *
 * S-FIX-PORTAL-INVITE-EMAIL — guards that the admin portal-user INVITE and
 * "Send Reset Link" paths route through the shared Mailer (SES in production),
 * not the old mail.log-only STUB that silently produced no email in prod.
 *
 *   C1  api/v1/portal_users/create.php calls Mailer::send() AND no longer writes
 *       the invite via file_put_contents(...mail.log...) (the stub is gone).
 *   C2  api/v1/portal_users/reset_password.php — same (Mailer::send, no stub).
 *   C3  Mailer selects the SES transport when APP_ENV==='production' — isLogMode()
 *       short-circuits to false in production (so prod NEVER log-mode-stubs).
 *   C4  Behavioral: Mailer::send() is callable and, in this (dev) env, takes the
 *       log branch (returns true + appends to logs/mail.log) — proves the wired
 *       path executes end-to-end and dev behavior is preserved.
 *   C5  app/portal/auth/forgot_password.php (portal self-service reset) calls
 *       Mailer::send() AND no longer writes via file_put_contents(...mail.log...)
 *       (S-FIX-PORTAL-FORGOT-EMAIL — same stub class).
 *
 * @session S-FIX-PORTAL-INVITE-EMAIL / S-FIX-PORTAL-FORGOT-EMAIL
 */

$root = dirname(__DIR__);
require_once $root . '/config/app.php';

use FleetForge\Notifications\Mailer;
use FleetForge\Email\EmailService;

$failures = [];
$pass     = 0;
$total    = 5;

$create = (string) file_get_contents($root . '/api/v1/portal_users/create.php');
$reset  = (string) file_get_contents($root . '/api/v1/portal_users/reset_password.php');
$mailer = (string) file_get_contents($root . '/lib/Notifications/Mailer.php');
$forgot = (string) file_get_contents($root . '/app/portal/auth/forgot_password.php');

// Detect the old stub: a file_put_contents() writing to mail.log as the send.
$stubRe = '/file_put_contents\s*\([^;]*mail\.log/s';

// ── C1: create.php uses Mailer, not the stub ──
$err = [];
if (!str_contains($create, 'Mailer::send(')) $err[] = 'create.php does not call Mailer::send()';
if (preg_match($stubRe, $create))            $err[] = 'create.php still writes the invite to mail.log via file_put_contents (stub not removed)';
if (empty($err)) { echo "PASS C1 invite (create.php) routes through Mailer::send(), no mail.log stub\n"; $pass++; }
else { echo "FAIL C1 " . implode('; ', $err) . "\n"; $failures[] = 'C1'; }

// ── C2: reset_password.php uses Mailer, not the stub ──
$err = [];
if (!str_contains($reset, 'Mailer::send(')) $err[] = 'reset_password.php does not call Mailer::send()';
if (preg_match($stubRe, $reset))            $err[] = 'reset_password.php still writes to mail.log via file_put_contents (stub not removed)';
if (empty($err)) { echo "PASS C2 reset (reset_password.php) routes through Mailer::send(), no mail.log stub\n"; $pass++; }
else { echo "FAIL C2 " . implode('; ', $err) . "\n"; $failures[] = 'C2'; }

// ── C3: Mailer forces SES (non-log) in production ──
// isLogMode() is private; assert the production short-circuit is present so the
// transport rule "APP_ENV=production → SES" cannot silently regress.
$err = [];
if (!preg_match('/function\s+isLogMode\b/', $mailer)) $err[] = 'isLogMode() not found in Mailer';
if (!preg_match('/APP_ENV\s*===\s*[\'"]production[\'"]\s*\)\s*\{\s*return\s+false/s', $mailer)) {
    $err[] = "Mailer no longer forces SES (return false) when APP_ENV==='production'";
}
if (empty($err)) { echo "PASS C3 Mailer selects SES transport when APP_ENV='production' (isLogMode→false)\n"; $pass++; }
else { echo "FAIL C3 " . implode('; ', $err) . "\n"; $failures[] = 'C3'; }

// ── C4: behavioral — Mailer::send() callable; dev takes log branch ──
$err = [];
$logFile = $root . '/logs/mail.log';
$before  = is_file($logFile) ? (int) filesize($logFile) : 0;
$ok      = Mailer::send('smoke_portal@fleetforge.test', 'Smoke', '[SMOKE] portal invite path',
                        EmailService::renderEmailHtml('<p>portal invite/reset wiring smoke</p>'));
$after   = is_file($logFile) ? (int) filesize($logFile) : 0;
if ($ok !== true) $err[] = 'Mailer::send() did not return true';
if (APP_ENV !== 'production' && $after <= $before) {
    $err[] = 'dev env did not append to logs/mail.log (log-mode regressed)';
}
if (empty($err)) {
    echo "PASS C4 Mailer::send() callable; " . (APP_ENV === 'production' ? 'prod (SES path)' : 'dev log-mode appended to mail.log') . "\n";
    $pass++;
} else { echo "FAIL C4 " . implode('; ', $err) . "\n"; $failures[] = 'C4'; }

// ── C5: portal self-service forgot_password.php uses Mailer, not the stub ──
$err = [];
if (!str_contains($forgot, 'Mailer::send(')) $err[] = 'forgot_password.php does not call Mailer::send()';
if (preg_match($stubRe, $forgot))            $err[] = 'forgot_password.php still writes to mail.log via file_put_contents (stub not removed)';
if (empty($err)) { echo "PASS C5 portal forgot-password routes through Mailer::send(), no mail.log stub\n"; $pass++; }
else { echo "FAIL C5 " . implode('; ', $err) . "\n"; $failures[] = 'C5'; }

if (!empty($failures)) {
    echo "\nportal_invite_email_smoke: {$pass}/{$total} PASS — failures: " . implode(', ', $failures) . "\n";
    exit(1);
}
echo "\nportal_invite_email_smoke: {$pass}/{$total} PASS\n";
exit(0);
