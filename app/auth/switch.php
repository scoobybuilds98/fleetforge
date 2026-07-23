<?php
declare(strict_types=1);

/**
 * app/auth/switch.php
 *
 * Deployment-switch confirmation.
 *
 * Reached only from app/auth/login.php, when someone arrives via the sibling-
 * deployment switcher (?sw=<hint>) and THIS browser already holds a session
 * belonging to a different person.
 *
 * WHY THIS EXISTS: login.php bounces any authenticated visitor straight to the
 * dashboard without checking who they are. Sessions last 8 hours (30 days when
 * "Stay signed in" is used), so on a shared machine a colleague's session is
 * often still alive. Without this page you would land on the other deployment
 * silently operating as them, and every invoice, lease close and journal entry
 * would be written to audit_log under their name. On a system that posts to a
 * general ledger, that is an accountability failure, not a cosmetic one.
 *
 * SECURITY: the ?sw hint is COMPARED, never TRUSTED — it authenticates nobody
 * and grants nothing (see ff_switch_identity_hint()). This page therefore
 * cannot be used to gain access: it only ever offers to CONTINUE as the
 * already-signed-in user, or to SIGN OUT. Both options are available to whoever
 * holds the session anyway. Forging the hint changes nothing but whether this
 * page is shown.
 *
 * @session S-DEPLOY-SWITCHER
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// No session here → nothing to disambiguate; the normal login page applies.
if (!current_user_id()) {
    header('Location: ' . base_url('auth/login'));
    exit;
}

$_me   = current_user() ?? [];
$_hint = trim((string) ($_GET['sw'] ?? ''));

// Same person (or no hint) → nothing to warn about. Straight through, so the
// common case stays frictionless.
if ($_hint === '' || hash_equals(ff_switch_identity_hint($_me['email'] ?? ''), $_hint)) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

$_company = ff_company_short_name();
$_name    = (string) ($_me['name'] ?? 'this account');
$_email   = (string) ($_me['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Already signed in — <?= e($_company) ?></title>

    <?= ff_favicon_tags() ?>
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <?= ff_brand_override_css() ?>
    <script>try{var t=localStorage.getItem('ff-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
    <style>
        .auth-page{min-height:100vh;display:flex;align-items:center;justify-content:flex-start;padding:24px 16px;
            background:radial-gradient(600px circle at 50% 22%, color-mix(in srgb, var(--color-primary) 6%, transparent), transparent 70%), var(--bg-body);}
        .auth-card{width:100%;margin:auto;max-width:460px;background:var(--bg-surface);border:1px solid var(--border-color);
            border-radius:var(--radius-2xl);box-shadow:var(--card-sheen),var(--shadow-xl);padding:32px;
            animation:auth-card-in 280ms cubic-bezier(0.25,1,0.5,1) both;}
        @keyframes auth-card-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
        @media (prefers-reduced-motion:reduce){.auth-card{animation:none;}}
        .sw-head{font-size:1.25rem;font-weight:600;color:var(--text-primary);margin:0 0 8px;letter-spacing:var(--tracking-tight);}
        .sw-sub{font-size:.9375rem;color:var(--text-secondary);margin:0 0 22px;line-height:1.5;}
        .sw-who{display:flex;align-items:center;gap:12px;padding:14px 16px;margin:0 0 22px;
            background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:var(--radius-lg);}
        .sw-avatar{width:40px;height:40px;flex-shrink:0;border-radius:50%;background:var(--color-primary);
            color:var(--color-on-primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9375rem;}
        .sw-name{font-weight:600;color:var(--text-primary);font-size:.9375rem;line-height:1.3;}
        .sw-email{font-size:.8125rem;color:var(--text-secondary);word-break:break-all;}
        .sw-actions{display:flex;flex-direction:column;gap:10px;}
        .sw-btn{display:block;width:100%;text-align:center;padding:12px 16px;border-radius:10px;
            font-size:.9375rem;font-weight:600;text-decoration:none;border:1px solid transparent;transition:filter 150ms ease;}
        .sw-btn:hover{filter:brightness(1.08);text-decoration:none;}
        .sw-btn--primary{background:var(--color-primary);color:var(--color-on-primary);}
        .sw-btn--ghost{background:transparent;color:var(--text-primary);border-color:var(--border-color-strong,var(--border-color));}
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <h1 class="sw-head">You're already signed in here</h1>
        <p class="sw-sub">
            This browser already has an active <?= e($_company) ?> session, and it
            belongs to a different account than the one you switched from.
        </p>

        <div class="sw-who">
            <div class="sw-avatar" aria-hidden="true"><?php
                $__p = preg_split('/\s+/', trim($_name));
                $__i = strtoupper(mb_substr($__p[0] ?? 'U', 0, 1));
                if (count($__p) > 1) { $__i .= strtoupper(mb_substr((string) end($__p), 0, 1)); }
                echo e($__i);
            ?></div>
            <div>
                <div class="sw-name"><?= e($_name) ?></div>
                <?php if ($_email !== ''): ?><div class="sw-email"><?= e($_email) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="sw-actions">
            <a class="sw-btn sw-btn--primary" href="<?= e(base_url('dashboard')) ?>">
                Continue as <?= e($_name) ?>
            </a>
            <a class="sw-btn sw-btn--ghost" href="<?= e(base_url('auth/logout')) ?>">
                Sign out and use a different account
            </a>
        </div>

    </div>
</div>
</body>
</html>
