<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Accept Invitation
//
// Activated when an admin creates a new user account.
// The new user clicks the emailed invite link, sets their
// name (if not pre-filled) and password, and their account
// becomes active.
//
// Flow:
//   Admin creates user (is_active=0, no password_hash)
//   → invite email sent with token
//   GET  ?token=xxx → validate → show setup form
//   POST ?token=xxx → validate → activate account → login flash
//
// Security:
//   • Token validated with hash_equals() on SHA-256 digest
//   • Token expires in 7 days
//   • On success: token deleted, user activated (is_active=1),
//     password_hash set — all in one transaction
//   • Account stays inactive until this page is completed
//   • Already-active users with this email cannot use the link
// ============================================================

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// Already logged in → bounce
if (current_user_id()) {
    header('Location: ' . base_url('dashboard'));
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_csrfToken = $_SESSION['csrf_token'];

// ── Validate the invite token ───────────────────────────────
function find_valid_invite(string $plainToken): ?array
{
    if ($plainToken === '' || strlen($plainToken) !== 64) {
        return null;
    }

    $tokenHash = hash('sha256', $plainToken);

    try {
        $row = db_row(
            "SELECT ui.id AS invite_id, ui.user_id, ui.expires_at,
                    u.name, u.email, u.role_slug, u.is_active
             FROM user_invitations ui
             JOIN users u ON u.id = ui.user_id
             WHERE ui.token_hash = ?
               AND ui.expires_at > NOW()
               AND u.deleted_at IS NULL",
            [$tokenHash]
        );
    } catch (Throwable) {
        return null;
    }

    // If account was already activated (e.g. link clicked twice), reject
    if ($row && (int) $row['is_active'] === 1) {
        return null;
    }

    return $row ?: null;
}

$plainToken  = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$invite      = find_valid_invite($plainToken);
$inviteValid = $invite !== null;

$error    = '';
$nameVal  = $invite['name'] ?? '';

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Invalid request token. Please refresh and try again.';

    } elseif (!$inviteValid) {
        // Error state rendered below

    } else {
        $nameVal         = trim((string) ($_POST['name']             ?? ''));
        $password        =       (string) ($_POST['password']         ?? '');
        $passwordConfirm =       (string) ($_POST['password_confirm'] ?? '');

        if ($nameVal === '') {
            $error = 'Full name is required.';
        } elseif (strlen($nameVal) > 100) {
            $error = 'Name must be 100 characters or fewer.';
        } elseif ($password === '') {
            $error = 'Password is required.';
        } elseif (strlen($password) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                db_transaction(function () use ($invite, $newHash, $nameVal) {
                    // Activate the account
                    db_execute(
                        "UPDATE users
                         SET name          = ?,
                             password_hash = ?,
                             is_active     = 1,
                             login_attempts = 0,
                             locked_until   = NULL
                         WHERE id = ?",
                        [$nameVal, $newHash, $invite['user_id']]
                    );

                    // Delete the used invite token
                    db_execute(
                        "DELETE FROM user_invitations WHERE id = ?",
                        [$invite['invite_id']]
                    );
                });

                $appName = settings_get('company.name', 'FleetForge');
                $_SESSION['auth_flash'] =
                    "Welcome to {$appName}! Your account is active — please sign in.";

                header('Location: ' . base_url('auth/login'));
                exit;

            } catch (Throwable $e) {
                error_log('[AcceptInvite] ' . $e->getMessage());
                $error = 'An error occurred while activating your account. Please try again.';
            }
        }
    }
}

// Friendly role label for display
$_roleLabels = [
    'super_admin'  => 'Administrator',
    'manager'      => 'Manager',
    'dispatcher'   => 'Dispatcher',
    'accountant'   => 'Accountant',
    'readonly'     => 'Read-only',
];
$_roleLabel = $_roleLabels[$invite['role_slug'] ?? ''] ?? ucfirst($invite['role_slug'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Accept Invitation — FleetForge</title>
    <link rel="icon" href="<?= base_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script>
        try { var t=localStorage.getItem('ff-theme'); if(t==='light'||t==='dark') document.documentElement.setAttribute('data-theme',t); } catch(e){}
    </script>
    <style>
        .auth-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 16px; background-color:var(--bg-body); }
        .auth-card { width:100%; max-width:440px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-xl); box-shadow:var(--shadow-lg); padding:36px 32px 32px; }
        .auth-logo { display:flex; flex-direction:column; align-items:center; margin-bottom:28px; }
        .auth-logo-mark { width:48px; height:48px; background:var(--color-primary); border-radius:var(--radius-lg); display:flex; align-items:center; justify-content:center; margin-bottom:12px; }
        .auth-logo-mark svg { width:28px; height:28px; color:#fff; }
        .auth-logo-name { font-size:1.25rem; font-weight:700; color:var(--text-primary); }
        .auth-heading { font-size:1rem; font-weight:600; color:var(--text-primary); margin-bottom:4px; }
        .auth-subheading { font-size:0.875rem; color:var(--text-tertiary); margin-bottom:24px; line-height:1.5; }
        .invite-context { display:flex; align-items:center; gap:10px; padding:12px 14px; background:var(--bg-surface-2); border:1px solid var(--border-color); border-radius:var(--radius-lg); margin-bottom:20px; font-size:0.875rem; }
        .invite-context-email { font-weight:600; color:var(--text-primary); }
        .invite-context-role { color:var(--text-secondary); }
        .auth-back { text-align:center; margin-top:20px; font-size:0.875rem; }
        .input-password-wrap { position:relative; }
        .input-password-wrap .form-control { padding-right:44px; }
        .password-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:var(--text-tertiary); background:none; border:none; padding:4px; cursor:pointer; display:flex; align-items:center; border-radius:var(--radius-sm); transition:color var(--transition-fast); }
        .password-toggle:hover { color:var(--text-secondary); }
        .password-toggle svg { width:18px; height:18px; pointer-events:none; }
        .password-toggle .icon-hide { display:none; }
        .password-toggle.is-visible .icon-show { display:none; }
        .password-toggle.is-visible .icon-hide { display:block; }
        .pw-strength { margin-top:6px; height:4px; border-radius:2px; background:var(--border-color); overflow:hidden; }
        .pw-strength-bar { height:100%; width:0; border-radius:2px; transition:width 300ms ease, background 300ms ease; }
        .pw-hint { font-size:0.75rem; color:var(--text-tertiary); margin-top:4px; }
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <div class="auth-logo-mark" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                </svg>
            </div>
            <div class="auth-logo-name">FleetForge</div>
        </div>

        <?php if (!$inviteValid): ?>

            <!-- ── Invalid / expired invite ── -->
            <div style="text-align:center;padding:8px 0;">
                <div style="width:48px;height:48px;background:var(--color-warning-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-warning)" style="width:26px;height:26px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                    </svg>
                </div>
                <h1 class="auth-heading" style="margin-bottom:8px;">Invitation expired or already used</h1>
                <p style="font-size:0.875rem;color:var(--text-secondary);line-height:1.5;">
                    This invitation link has expired or has already been used to set up an account.
                    Invitations are valid for <strong>7 days</strong>.
                </p>
                <p style="font-size:0.8125rem;color:var(--text-tertiary);margin-top:12px;">
                    If you believe this is an error, ask an administrator to
                    send you a new invitation.
                </p>
                <a href="<?= e(base_url('auth/login')) ?>"
                   class="btn btn-secondary btn-md"
                   style="margin-top:20px;display:inline-flex;">
                    Go to sign in
                </a>
            </div>

        <?php else: ?>

            <!-- ── Setup form ── -->
            <h1 class="auth-heading">Welcome — set up your account</h1>
            <p class="auth-subheading">
                You've been invited to FleetForge. Choose your display name and
                create a password to activate your account.
            </p>

            <!-- Invite context: who this is for -->
            <div class="invite-context">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-primary)" style="width:20px;height:20px;flex-shrink:0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
                <div>
                    <div class="invite-context-email"><?= e($invite['email']) ?></div>
                    <?php if ($_roleLabel !== ''): ?>
                        <div class="invite-context-role">Role: <?= e($_roleLabel) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="toast toast-danger" role="alert" style="position:relative;margin-bottom:16px;animation:none;">
                    <span class="toast-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                    </span>
                    <div class="toast-body"><div class="toast-message"><?= e($error) ?></div></div>
                </div>
            <?php endif; ?>

            <form method="post"
                  action="<?= e(base_url('auth/accept-invite')) ?>?token=<?= urlencode($plainToken) ?>"
                  novalidate>

                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                <input type="hidden" name="token"      value="<?= e($plainToken) ?>">

                <!-- Full name -->
                <div class="form-group">
                    <label class="form-label" for="name">
                        Your full name <span class="required" aria-hidden="true">*</span>
                    </label>
                    <input type="text"
                           id="name"
                           name="name"
                           class="form-control<?= $error !== '' && $nameVal === '' ? ' is-invalid' : '' ?>"
                           value="<?= e($nameVal) ?>"
                           autocomplete="name"
                           autofocus
                           required
                           maxlength="100"
                           placeholder="Jane Smith">
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label" for="password">
                        Create a password <span class="required" aria-hidden="true">*</span>
                    </label>
                    <div class="input-password-wrap">
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control"
                               autocomplete="new-password"
                               required
                               minlength="10"
                               maxlength="255"
                               placeholder="At least 10 characters"
                               aria-describedby="pw-strength-hint">
                        <button type="button" class="password-toggle" id="pw-toggle-1"
                                aria-label="Show password" aria-pressed="false" tabindex="-1">
                            <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                    <div class="pw-strength" aria-hidden="true">
                        <div class="pw-strength-bar" id="pw-strength-bar"></div>
                    </div>
                    <div class="pw-hint" id="pw-strength-hint" aria-live="polite"></div>
                </div>

                <!-- Confirm password -->
                <div class="form-group">
                    <label class="form-label" for="password_confirm">
                        Confirm password <span class="required" aria-hidden="true">*</span>
                    </label>
                    <div class="input-password-wrap">
                        <input type="password"
                               id="password_confirm"
                               name="password_confirm"
                               class="form-control"
                               autocomplete="new-password"
                               required
                               maxlength="255"
                               placeholder="Repeat your password">
                        <button type="button" class="password-toggle" id="pw-toggle-2"
                                aria-label="Show confirmation" aria-pressed="false" tabindex="-1">
                            <svg class="icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full btn-lg">
                    Activate my account
                </button>

                <p style="font-size:0.75rem;color:var(--text-tertiary);text-align:center;margin-top:14px;">
                    By activating your account you agree to keep your credentials
                    confidential and not share access with others.
                </p>

            </form>

        <?php endif; ?>

        <div class="auth-back">
            <a href="<?= e(base_url('auth/login')) ?>" class="btn-link btn-sm">
                Already have an account? Sign in
            </a>
        </div>

    </div>
</div>

<script>
(function () {
    function makeToggle(btnId, inputId) {
        var btn = document.getElementById(btnId);
        var inp = document.getElementById(inputId);
        if (!btn || !inp) return;
        btn.addEventListener('click', function () {
            var v = inp.type === 'text';
            inp.type = v ? 'password' : 'text';
            btn.classList.toggle('is-visible', !v);
            btn.setAttribute('aria-pressed', String(!v));
        });
    }
    makeToggle('pw-toggle-1', 'password');
    makeToggle('pw-toggle-2', 'password_confirm');

    var pwInput = document.getElementById('password');
    var bar     = document.getElementById('pw-strength-bar');
    var hint    = document.getElementById('pw-strength-hint');

    if (pwInput && bar && hint) {
        pwInput.addEventListener('input', function () {
            var val = this.value, len = val.length, score = 0;
            if (len >= 10) score++;
            if (len >= 16) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;
            var levels = [
                { pct:'0%',   color:'transparent',         label:'' },
                { pct:'25%',  color:'var(--color-danger)', label:'Weak' },
                { pct:'50%',  color:'var(--color-warning)',label:'Fair' },
                { pct:'75%',  color:'var(--color-info)',   label:'Good' },
                { pct:'100%', color:'var(--color-success)',label:'Strong' },
            ];
            var lvl = len === 0 ? 0 : Math.max(1, Math.min(4, score));
            bar.style.width      = levels[lvl].pct;
            bar.style.background = levels[lvl].color;
            hint.textContent     = levels[lvl].label;
        });
    }
})();
</script>

</body>
</html>
<?php unset($_csrfToken, $plainToken, $invite, $inviteValid, $error, $nameVal, $_roleLabel, $_roleLabels); ?>
