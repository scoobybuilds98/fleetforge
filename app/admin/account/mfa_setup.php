<?php
declare(strict_types=1);

/**
 * app/admin/account/mfa_setup.php
 *
 * Three-step MFA setup flow. Accessible from two entry points:
 *   1. My Profile page (voluntary setup)
 *   2. mfa_required.php (forced setup for mfa_required roles)
 *
 * In forced setup mode, $_SESSION['ff_mfa_must_setup'] must be set
 * and the user is not yet fully logged in.
 *
 * For voluntary setup, require_auth() guards the page normally.
 *
 * @session S-PROD-1A
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

_ff_session_start();

// Determine entry point:
//   - Forced setup: ff_mfa_must_setup is set, user not logged in
//   - Voluntary: fully logged in
$forcedSetup   = !empty($_SESSION['ff_mfa_must_setup']);
$forcedUserId  = $forcedSetup ? (int) $_SESSION['ff_mfa_must_setup'] : 0;

if (!$forcedSetup) {
    require_auth();
}

if (!$forcedSetup && !current_user_id()) {
    header('Location: ' . base_url('auth/login'));
    exit;
}

$effectiveUserId = $forcedSetup ? $forcedUserId : current_user_id();
$userEmail       = '';

if ($forcedSetup) {
    $userRow   = db_row("SELECT email FROM users WHERE id = ? AND deleted_at IS NULL", [$forcedUserId]);
    $userEmail = $userRow ? $userRow['email'] : '';
} else {
    $userEmail = current_user()['email'] ?? '';
}

$pageTitle = 'Set Up Two-Factor Authentication';
if (!$forcedSetup) {
    require_once FF_ROOT . '/includes/header.php';
}
?>
<?php if ($forcedSetup): ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(generate_csrf_token()) ?>">
    <title>Set Up Two-Factor Authentication — FleetForge</title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&display=swap">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>" defer></script>
    <!-- S-PROD-1A-FIX-4 T7: forced-setup head does not include footer.php (which loads Alpine).
         Must load Alpine here; Bundle 2 (S-PROD-1A-FIX-5) will replace with local vendor file. -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>try{var t=localStorage.getItem('ff-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}
    window.FF_BASE_PATH = <?= json_encode(FF_BASE_PATH) ?>;
    </script>
    <style>
        .mfa-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;background:var(--bg-body);}
        .mfa-card{width:100%;max-width:480px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);padding:36px 32px;}
    </style>
</head>
<body>
<div class="mfa-page">
    <div class="mfa-card" id="mfa-setup-root">
<?php else: ?>
<div class="container" style="max-width:600px;margin:0 auto;">
    <div class="card" id="mfa-setup-root">
        <div class="card-header">
            <h2 class="card-title">Set Up Two-Factor Authentication</h2>
        </div>
<?php endif; ?>

        <div x-data="mfaSetup()" x-init="init()" x-cloak><!-- S-PROD-1A-FIX-4 T28: x-cloak prevents flash of all steps before Alpine binds -->

            <!-- ── Step indicator ──────────────────────────────────────────── -->
            <div style="display:flex;gap:8px;margin-bottom:28px;" x-show="step < 4">
                <template x-for="s in [1,2,3]" :key="s">
                    <div style="flex:1;height:4px;border-radius:2px;transition:background 0.3s;"
                         :style="step >= s ? 'background:var(--color-primary)' : 'background:var(--border-color)'">
                    </div>
                </template>
            </div>

            <!-- ── Step 1: Introduction ───────────────────────────────────── -->
            <div x-show="step === 1">
                <h2 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">
                    Secure your account with an authenticator app
                </h2>
                <p style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:16px;line-height:1.6;">
                    Two-factor authentication adds a second layer of security. After entering your
                    password you'll be asked for a one-time code from your phone.
                </p>
                <p style="font-size:0.875rem;color:var(--text-tertiary);margin-bottom:24px;line-height:1.6;">
                    Have your phone ready with <strong>Google Authenticator</strong>, <strong>Authy</strong>,
                    or <strong>1Password</strong> installed.
                </p>

                <div x-show="error" class="toast toast-danger" role="alert"
                     style="position:relative;margin-bottom:16px;animation:none;">
                    <div class="toast-body"><div class="toast-message" x-text="error"></div></div>
                </div>

                <button class="btn btn-primary w-full btn-lg"
                        @click="initSetup()"
                        :disabled="loading">
                    <span x-show="loading">Loading&hellip;</span>
                    <span x-show="!loading">Continue</span>
                </button>
            </div>

            <!-- ── Step 2: Scan QR code ───────────────────────────────────── -->
            <div x-show="step === 2">
                <h2 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">
                    Scan this QR code with your authenticator app
                </h2>
                <p style="font-size:0.875rem;color:var(--text-tertiary);margin-bottom:20px;">
                    Point your phone's camera at the code below, then enter the 6-digit code it shows.
                </p>

                <!-- QR Code SVG from server -->
                <div style="display:flex;justify-content:center;margin-bottom:16px;padding:12px;background:#fff;border-radius:var(--radius-lg);width:fit-content;margin-left:auto;margin-right:auto;">
                    <div x-html="qrSvg" style="width:280px;height:280px;"></div>
                </div>

                <!-- Manual entry -->
                <details style="margin-bottom:20px;font-size:0.8125rem;color:var(--text-tertiary);">
                    <summary style="cursor:pointer;margin-bottom:8px;">Can't scan? Enter the code manually</summary>
                    <div style="font-family:monospace;font-size:0.875rem;word-break:break-all;padding:8px;background:var(--bg-surface-2);border-radius:var(--radius-md);color:var(--text-primary);"
                         x-text="manualSecret"></div>
                </details>

                <div class="form-group">
                    <label class="form-label" for="totp-code">Enter the 6-digit code from your app</label>
                    <input type="text" id="totp-code"
                           class="form-control"
                           style="font-size:1.5rem;letter-spacing:0.25em;text-align:center;font-variant-numeric:tabular-nums;"
                           inputmode="numeric"
                           maxlength="6"
                           x-model="verifyCode"
                           @keyup.enter="verifySetup()"
                           autocomplete="one-time-code"
                           placeholder="000000">
                </div>

                <div x-show="error" class="toast toast-danger" role="alert"
                     style="position:relative;margin-bottom:16px;animation:none;">
                    <div class="toast-body"><div class="toast-message" x-text="error"></div></div>
                </div>

                <div style="display:flex;gap:12px;">
                    <button class="btn btn-secondary" @click="step = 1; error = ''">Back</button>
                    <button class="btn btn-primary" style="flex:1;"
                            @click="verifySetup()"
                            :disabled="loading || verifyCode.length < 6">
                        <span x-show="loading">Verifying&hellip;</span>
                        <span x-show="!loading">Verify &amp; Enable</span>
                    </button>
                </div>
            </div>

            <!-- ── Step 3: Backup codes ───────────────────────────────────── -->
            <div x-show="step === 3">
                <h2 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">
                    Save your backup codes
                </h2>
                <div class="toast toast-warning" style="position:relative;animation:none;margin-bottom:16px;">
                    <div class="toast-body">
                        <div class="toast-message">
                            <strong>Save these codes NOW.</strong> Each can be used once if you lose your
                            authenticator app. We will not show them again.
                        </div>
                    </div>
                </div>

                <div style="background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px;margin-bottom:16px;font-family:monospace;">
                    <template x-for="(code, i) in backupCodes" :key="i">
                        <div style="padding:4px 0;font-size:0.9375rem;color:var(--text-primary);" x-text="(i+1) + '. ' + code"></div>
                    </template>
                </div>

                <div style="display:flex;gap:8px;margin-bottom:20px;">
                    <button class="btn btn-secondary btn-sm" @click="copyToClipboard()">
                        <span x-show="!copied">Copy to clipboard</span>
                        <span x-show="copied">Copied!</span>
                    </button>
                    <button class="btn btn-secondary btn-sm" @click="downloadCodes()">
                        Download as .txt
                    </button>
                </div>

                <button class="btn btn-primary w-full btn-lg" @click="finishSetup()">
                    I've saved my codes — finish setup
                </button>
            </div>

            <!-- ── Step 4: Success (forced setup only) ────────────────────── -->
            <div x-show="step === 4" style="text-align:center;padding:8px 0;">
                <div style="width:56px;height:56px;background:var(--color-success-light,#d1fae5);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success,#10b981)" style="width:28px;height:28px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
                    </svg>
                </div>
                <h2 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:8px;">
                    Two-factor authentication enabled
                </h2>
                <p style="font-size:0.875rem;color:var(--text-secondary);margin-bottom:24px;">
                    Your account is now protected. Please sign in again to continue.
                </p>
                <a href="<?= e(base_url('auth/login')) ?>" class="btn btn-primary btn-lg">Sign in</a>
            </div>

        </div><!-- /x-data -->

<?php if ($forcedSetup): ?>
    </div><!-- /mfa-card -->
</div><!-- /mfa-page -->
<?php else: ?>
    </div><!-- /card -->
</div><!-- /container -->
<?php endif; ?>

<script>
function mfaSetup() {
    return {
        step:         1,
        loading:      false,
        error:        '',
        qrSvg:        '',
        manualSecret: '',
        verifyCode:   '',
        backupCodes:  [],
        copied:       false,
        forcedSetup:  <?= $forcedSetup ? 'true' : 'false' ?>,

        init() {},

        async initSetup() {
            this.loading = true;
            this.error   = '';
            try {
                const r = await fetch(window.FF_BASE_PATH + '/api/v1/account/mfa/setup_init.php', {
                    method:  'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });
                const d = await r.json();
                if (!r.ok || d.success === false) {
                    this.error = d.error?.message ?? 'Failed to initialize setup. Please try again.';
                    return;
                }
                this.qrSvg        = d.data.qr_code_svg;
                this.manualSecret = d.data.manual_secret;
                this.step         = 2;
            } catch (e) {
                this.error = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async verifySetup() {
            if (this.verifyCode.length < 6) return;
            this.loading = true;
            this.error   = '';
            try {
                const r = await fetch(window.FF_BASE_PATH + '/api/v1/account/mfa/setup_verify.php', {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ code: this.verifyCode }),
                });
                const d = await r.json();
                if (!r.ok || d.success === false) {
                    const code = d.error?.code ?? '';
                    this.error = code === 'SESSION_EXPIRED'
                        ? 'Setup session expired. Please start again.'
                        : 'The code is incorrect. Check your authenticator app and try again.';
                    this.verifyCode = '';
                    if (code === 'SESSION_EXPIRED') this.step = 1;
                    return;
                }
                this.backupCodes = d.data.backup_codes;
                this.step        = 3;
            } catch (e) {
                this.error = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async copyToClipboard() {
            const text = this.backupCodes.map((c, i) => (i+1) + '. ' + c).join('\n');
            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => this.copied = false, 3000);
            } catch (e) {
                alert('Copy failed — please select and copy the codes manually.');
            }
        },

        downloadCodes() {
            const text = 'FleetForge MFA Backup Codes\n' +
                'Generated: ' + new Date().toLocaleString() + '\n\n' +
                this.backupCodes.map((c, i) => (i+1) + '. ' + c).join('\n') +
                '\n\nEach code can be used once. Store securely.';
            const blob = new Blob([text], { type: 'text/plain' });
            const a    = document.createElement('a');
            a.href     = URL.createObjectURL(blob);
            a.download = 'fleetforge-mfa-backup-codes.txt';
            a.click();
            URL.revokeObjectURL(a.href);
        },

        finishSetup() {
            if (this.forcedSetup) {
                this.step = 4;
                // Clear the forced-setup session server-side by navigating away after 2s
                setTimeout(() => { window.location = window.FF_BASE_PATH + '/auth/login'; }, 2000);
            } else {
                window.location = window.FF_BASE_PATH + '/profile';
            }
        },
    };
}
</script>

<?php if (!$forcedSetup): ?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
<?php endif; ?>
