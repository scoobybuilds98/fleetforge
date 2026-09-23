<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/settings.php
 *
 * Settings → QuickBooks. Three cards:
 *   1. Connection Status — environment badge + connection badge +
 *      timestamps + Connect / Disconnect / Test Connection buttons.
 *   1.5 Company Detection — read-only display of auto-detected QBO
 *      company settings (multi_currency_enabled, home_currency,
 *      company_country). Populated by CompanyInfoSync at OAuth connect
 *      and token refresh time (D-QBO-FIXPACK-11).
 *   2. API Credentials — environment + client_id + client_secret +
 *      webhook_verifier_token + sandbox_redirect_uri. Sensitive values
 *      always rendered masked to last 4 chars; full values never
 *      reach the HTML.
 *   3. Master Controls — sync_enabled + dry_run_mode + payments_enabled
 *      kill-switches. super_admin only.
 *   4. Sync & monitoring (I20 / I23) — bank-feed CDC on/off + lookback,
 *      nightly drift check + GL-balance layer, Refund Receipt deposit
 *      account / payment method Ids, and the per-entity sync modes as
 *      editable selects. super_admin only; saved through
 *      api/v1/quickbooks/save_sync_controls.php. Everyone else sees the
 *      sync modes as a read-only table.
 *
 * Permission gate:
 *   - require_permission('quickbooks', 'view') — page-level access
 *   - can('quickbooks', 'edit_credentials') — Card 2 + Disconnect /
 *     Connect actions
 *   - is_super_admin()                       — Card 3 + Card 4 (edit) visibility
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1, §5.2, §5.5
 * Session:  S-QBO-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$canEditCredentials = can('quickbooks', 'edit_credentials');
$canDisconnect      = can('quickbooks', 'disconnect');
$isSuperAdmin       = is_super_admin();

// ── Load all quickbooks.* settings into $qbo flat map ──────────
// WHY: Single round-trip then array lookup beats 18 separate
// settings_get() calls. Strip 'quickbooks.' prefix for tidier
// key access in this file and the Alpine init blob.
$qbo = [];
foreach (db_select("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'quickbooks.%'", []) as $row) {
    $qbo[substr($row['key'], 11)] = $row['value'];
}

/**
 * ff_qbo_mask — render a sensitive value as "••••••••XXXX" where XXXX
 * is the last 4 chars, or "Not configured" if empty/null. Used for
 * client_id / client_secret / webhook_verifier_token display.
 *
 * Never returns the full value. The function is local to this file
 * by intent — generalising it to includes/functions.php is deferred
 * until a second caller actually needs it (avoid premature reuse).
 *
 * @param  string|null $value
 * @return string
 */
function ff_qbo_mask(?string $value): string
{
    $value = (string) ($value ?? '');
    if ($value === '') {
        return 'Not configured';
    }
    if (strlen($value) <= 4) {
        // Short values get fully masked — last 4 = whole string would
        // leak the entire credential, defeating the purpose.
        return str_repeat('•', 8);
    }
    return str_repeat('•', 8) . substr($value, -4);
}

/**
 * ff_qbo_format_ts — format an ISO timestamp string for display,
 * or return "—" if empty/null.
 *
 * @param  string|null $value
 * @return string
 */
function ff_qbo_format_ts(?string $value): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return e($value);
    }
    return date('Y-m-d H:i:s', $ts);
}

// ── Refresh-token expiry countdown (UI banner) ─────────────────
// WHY: Spec §5.3 — the pinger cron alerts at T-14d, but the UI
// also surfaces the warning so an operator who lands on this page
// at any time sees it immediately.
$refreshExpiresAt = $qbo['refresh_token_expires_at'] ?? '';
$refreshExpiresInDays = null;
if (!empty($refreshExpiresAt)) {
    $expTs = strtotime($refreshExpiresAt);
    if ($expTs !== false) {
        $refreshExpiresInDays = (int) floor(($expTs - time()) / 86400);
    }
}

$connectionStatus = $qbo['connection_status'] ?? 'disconnected';
$environment      = $qbo['environment'] ?? 'sandbox';

// ── Per-entity sync modes (I20 / I23) ──────────────────────────
// WHY one list here: the editable selects (super_admin) and the read-only
// table (everyone else) render from the same rows. Only entities whose
// quickbooks.sync_mode.<entity> is actually read by an Enqueuer/Pusher are
// listed — the endpoint whitelists the same set. 'default' mirrors the
// fallback each reader passes to settings_get() when the row is absent.
$syncModeEntities = [
    'customer'           => ['label' => 'Customers',                   'default' => 'sync',  'note' => null],
    'vendor'             => ['label' => 'Vendors',                     'default' => 'sync',  'note' => null],
    'invoice'            => ['label' => 'Invoices',                    'default' => 'sync',  'note' => null],
    'payment'            => ['label' => 'Customer payments',           'default' => 'queue', 'note' => 'Payments received in QuickBooks are still copied to FleetForge.'],
    'credit_memo'        => ['label' => 'Credit notes (credit memos)', 'default' => 'sync',  'note' => null],
    'credit_application' => ['label' => 'Credit-note applications',    'default' => 'sync',
        'note' => 'FleetForge pushes credit-note applications it records. QuickBooks-side applications are NOT copied back — they raise a drift item. Apply credits in FleetForge (default), or set Disabled and apply them in QuickBooks, then apply the same credit note in FleetForge by hand.'],
    'refund_receipt'     => ['label' => 'Customer refunds (refund receipts)', 'default' => 'sync', 'note' => 'Needs the refund deposit account and payment method below.'],
    'invoice_writeoff'   => ['label' => 'Invoice write-offs',          'default' => 'queue', 'note' => 'Sent as a credit memo applied to the invoice.'],
    'bill'               => ['label' => 'Vendor bills',                'default' => 'queue', 'note' => null],
    'bill_payment'       => ['label' => 'Bill payments',               'default' => 'queue', 'note' => null],
    'journal_entry'      => ['label' => 'Journal entries',             'default' => 'queue', 'note' => 'Also governs fixed-asset (depreciation / disposal / impairment) and GST remittance entries.'],
];
// Values the readers honour: every Enqueuer/Pusher refuses the push on
// 'disabled' or 'qbo_to_ff'; anything else pushes via the queue worker.
$syncModeOptions = [
    'sync'      => 'Push to QuickBooks',
    'queue'     => 'Push to QuickBooks (queue)',
    'disabled'  => 'Disabled — don\'t push',
    'qbo_to_ff' => 'QuickBooks → FleetForge only (don\'t push)',
];
$syncModesCurrent = [];
foreach ($syncModeEntities as $entityKey => $meta) {
    $syncModesCurrent[$entityKey] = (string) ($qbo['sync_mode.' . $entityKey] ?? $meta['default']);
}

// Refund deposit account suggestions — the QuickBooks Bank accounts already
// pulled into acc_qbo_account_map (Accounts mapping page). Offered through a
// <datalist> so the field still accepts a typed Id when nothing is pulled yet.
$refundAccountOptions = [];
if ($isSuperAdmin) {
    try {
        $refundAccountOptions = db_select(
            "SELECT DISTINCT qbo_account_id, COALESCE(qbo_fully_qualified_name, qbo_name) AS qbo_name
               FROM acc_qbo_account_map
              WHERE qbo_account_type = 'Bank'
                AND qbo_account_id IS NOT NULL
                AND COALESCE(qbo_active, 1) = 1
              ORDER BY qbo_name",
            []
        );
    } catch (\Throwable $e) {
        // Suggestions are a convenience — a failed lookup leaves a plain Id input.
        $refundAccountOptions = [];
    }
}

$pageTitle = 'QuickBooks Settings';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Settings</span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks Settings</h1>
</div>

<div x-data="qboSettings()" style="max-width:880px;">

    <!-- ── Flash message (top of page) ───────────────────────── -->
    <div x-show="flash.message" x-cloak
         :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
         style="margin-bottom:16px;"
         x-text="flash.message"></div>

    <?php if (($qbo['realm_mismatch'] ?? '0') === '1'): ?>
    <!-- ── Realm-change guard (S-QBO-GOLIVE-AUDIT) ─────────────────
         Connected to a different QBO company than the mappings belong
         to. Every sync call is blocked (QuickBooksClient::realmGuardReason)
         until the old company's mappings are wiped. -->
    <div class="alert alert-danger" style="margin-bottom:16px;">
        <strong>Sync blocked — different QuickBooks company.</strong>
        FleetForge is connected to realm <code><?= e($qbo['realm_id'] ?? '') ?></code>, but its customer,
        account, item and tax mappings were built for
        <?= ($qbo['mapped_realm_id'] ?? '') !== '' ? 'realm <code>' . e($qbo['mapped_realm_id']) . '</code>' : 'another company' ?>.
        Pushing with them would post to the wrong customers and accounts, so nothing will sync until the
        old mappings are reset. After resetting, re-run the mapping pages (Accounts → Tax Codes → Items →
        Customers → Vendors → Bank Accounts) before turning master sync back on.
        <?php if ($isSuperAdmin): ?>
            <div style="margin-top:10px;">
                <button class="btn btn-danger btn-sm" @click="resetMappings()" :disabled="resetting">
                    <span x-text="resetting ? 'Resetting…' : 'Reset mappings for this company'"></span>
                </button>
            </div>
        <?php else: ?>
            <div style="margin-top:6px;">Ask a super admin to reset the mappings.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         CARD 1 — Connection Status
         ============================================================ -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 12px;">Connection Status</h3>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <?php
            $statusBadgeClass = match ($connectionStatus) {
                'connected'    => 'badge badge-success',
                'expired'      => 'badge badge-danger',
                'error'        => 'badge badge-danger',
                default        => 'badge badge-neutral',
            };
            ?>
            <span class="<?= $statusBadgeClass ?>" style="text-transform:capitalize;"><?= e($connectionStatus) ?></span>
            <span class="badge badge-info" style="text-transform:capitalize;"><?= e($environment) ?></span>
        </div>

        <?php if ($connectionStatus === 'connected'): ?>
            <dl class="dl-grid" style="margin-bottom:14px;">
                <dt>Realm ID</dt>
                <dd><code><?= e($qbo['realm_id'] ?? '') ?></code></dd>

                <dt>Last connected</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['last_connected_at'] ?? null)) ?></dd>

                <dt>Last token refresh</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['last_token_refresh_at'] ?? null)) ?></dd>

                <dt>Access token expires</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['access_token_expires_at'] ?? null)) ?></dd>

                <dt>Refresh token expires</dt>
                <dd><?= e(ff_qbo_format_ts($qbo['refresh_token_expires_at'] ?? null)) ?></dd>
            </dl>
        <?php endif; ?>

        <?php if ($connectionStatus === 'error' && !empty($qbo['connection_error'])): ?>
            <div class="alert alert-danger" style="margin-bottom:14px;">
                <strong>Connection error:</strong> <?= e($qbo['connection_error']) ?>
            </div>
        <?php endif; ?>

        <?php if ($connectionStatus === 'expired'): ?>
            <div class="alert alert-danger" style="margin-bottom:14px;">
                <strong>Refresh token expired.</strong> Re-authorization required — click Connect to QuickBooks below.
            </div>
        <?php endif; ?>

        <?php if ($connectionStatus === 'connected' && $refreshExpiresInDays !== null && $refreshExpiresInDays <= 14): ?>
            <div class="alert alert-warning" style="margin-bottom:14px;">
                <strong>Refresh token expires in <?= e((string) max($refreshExpiresInDays, 0)) ?> day(s).</strong>
                Consider re-authorizing soon — refresh tokens that lapse force a full re-connect.
            </div>
        <?php endif; ?>

        <!-- ── Action buttons ─────────────────────────────────── -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if (in_array($connectionStatus, ['disconnected', 'expired', 'error'], true)): ?>
                <a href="<?= base_url('oauth/qbo/init.php') ?>"
                   class="btn btn-primary btn-sm <?= $canEditCredentials ? '' : 'is-disabled' ?>"
                   <?= $canEditCredentials ? '' : 'aria-disabled="true" onclick="event.preventDefault()"' ?>>
                    Connect to QuickBooks
                </a>
            <?php endif; ?>

            <?php if ($connectionStatus === 'connected'): ?>
                <button class="btn btn-secondary btn-sm" @click="testConnection()" :disabled="testing">
                    <span x-show="!testing">Test Connection</span>
                    <span x-show="testing" x-cloak>Testing…</span>
                </button>
                <button class="btn btn-danger btn-sm" @click="disconnect()" :disabled="disconnecting || !<?= $canDisconnect ? 'true' : 'false' ?>">
                    <span x-show="!disconnecting">Disconnect</span>
                    <span x-show="disconnecting" x-cloak>Disconnecting…</span>
                </button>
            <?php endif; ?>
        </div>

        <!-- ── Test connection result panel ───────────────────── -->
        <div x-show="testResult" x-cloak style="margin-top:14px;">
            <div :class="testResult && testResult.success ? 'alert alert-success' : 'alert alert-danger'">
                <template x-if="testResult && testResult.success">
                    <div>
                        <strong>Connected.</strong> Company: <span x-text="testResult.company_name"></span>
                        — Realm ID <code x-text="testResult.realm_id"></code>
                    </div>
                </template>
                <template x-if="testResult && !testResult.success">
                    <div>
                        <strong>Test failed:</strong> <span x-text="testResult.error"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CARD 1.5 — Company Detection (D-QBO-FIXPACK-11)
         Read-only display of QBO company settings auto-detected at
         OAuth connect time and at each token refresh. These values
         gate CurrencyRef emission across all Pushers (D-QBO-FIXPACK-12).
         ============================================================ -->
    <?php
    $multiCurrEnabled = ($qbo['multi_currency_enabled'] ?? '0') === '1';
    $homeCurrency     = $qbo['home_currency']    ?? '';
    $companyCountry   = $qbo['company_country']  ?? '';
    // Find when multi_currency_enabled was last updated (proxy for "last detected")
    $lastDetectedRow  = db_row(
        "SELECT updated_at FROM settings WHERE `key` = 'quickbooks.multi_currency_enabled'",
        []
    );
    $lastDetected = $lastDetectedRow ? ff_qbo_format_ts($lastDetectedRow['updated_at']) : '—';
    ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Company Detection</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Auto-detected from QuickBooks on connect and token refresh — not editable here.
            These settings gate <code>CurrencyRef</code> emission in all Pushers.
            Re-connect QBO or wait for the next token rotation to refresh.
        </p>
        <dl class="dl-grid">
            <dt>Multi-currency enabled</dt>
            <dd>
                <?php if ($multiCurrEnabled): ?>
                    <span class="badge badge-success">Yes</span>
                    <span class="text-secondary text-sm" style="margin-left:6px;">CurrencyRef will be emitted on entity pushes</span>
                <?php else: ?>
                    <span class="badge badge-neutral">No</span>
                    <span class="text-secondary text-sm" style="margin-left:6px;">CurrencyRef omitted (QBO error 6000 prevention)</span>
                <?php endif; ?>
            </dd>

            <dt>Home currency</dt>
            <dd><?= $homeCurrency !== '' ? e($homeCurrency) : '<span class="text-secondary">Not detected yet</span>' ?></dd>

            <dt>Company country</dt>
            <dd><?= $companyCountry !== '' ? e($companyCountry) : '<span class="text-secondary">Not detected yet</span>' ?></dd>

            <dt>Last detected</dt>
            <dd><?= e($lastDetected) ?></dd>
        </dl>
    </div>

    <!-- ============================================================
         CARD 2 — API Credentials
         ============================================================ -->
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">API Credentials</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Intuit Developer credentials. Sensitive values are masked — type a new value to replace; leave blank to keep the current value.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
                <label class="form-label">Environment</label>
                <select class="form-select" x-model="creds.environment" <?= $canEditCredentials ? '' : 'disabled' ?>>
                    <option value="sandbox">Sandbox</option>
                    <option value="production">Production</option>
                </select>
            </div>
            <div>
                <label class="form-label">Sandbox Redirect URI</label>
                <input type="text" class="form-input" x-model="creds.sandbox_redirect_uri"
                       placeholder="https://your-ngrok-tunnel.ngrok.io/fleetforge/oauth/qbo/callback.php"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
                <p class="text-secondary text-sm" style="margin:2px 0 0;">Your ngrok callback URL for local dev.</p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
            <div>
                <label class="form-label">Client ID</label>
                <input type="text" class="form-input" x-model="creds.client_id"
                       :placeholder="placeholders.client_id"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
            </div>
            <div>
                <label class="form-label">Client Secret</label>
                <input type="text" class="form-input" x-model="creds.client_secret"
                       :placeholder="placeholders.client_secret"
                       <?= $canEditCredentials ? '' : 'disabled' ?>>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="form-label">Webhook Verifier Token</label>
            <input type="text" class="form-input" x-model="creds.webhook_verifier_token"
                   :placeholder="placeholders.webhook_verifier_token"
                   <?= $canEditCredentials ? '' : 'disabled' ?>>
            <p class="text-secondary text-sm" style="margin:2px 0 0;">From Intuit webhook config — used to verify inbound payment webhooks (HMAC-SHA256).</p>
        </div>

        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="saveCredentials()"
                    :disabled="savingCreds || !<?= $canEditCredentials ? 'true' : 'false' ?>">
                <span x-show="!savingCreds">Save Credentials</span>
                <span x-show="savingCreds" x-cloak>Saving…</span>
            </button>
            <?php if (!$canEditCredentials): ?>
                <span class="text-secondary text-sm" style="margin-left:10px;">View-only — edit_credentials permission required.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         CARD 2.5 — Business tagging (shared QuickBooks file)
         S-QBO-GOLIVE-AUDIT: the QuickBooks company holds more than one
         business — every FF document is stamped with the rental Class /
         Location (QboTagging) so rental revenue and costs stay separable.
         ============================================================ -->
    <div class="card" style="padding:20px;margin-bottom:16px;" x-init="loadTagging(false)">
        <h3 class="h6" style="margin:0 0 4px;">Business tagging (shared QuickBooks file)</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Your QuickBooks company holds more than one business. Pick the rental business's Class and/or Location —
            FleetForge adds it to every invoice, credit memo, refund, bill and journal entry it sends, so rental figures
            stay separate. Leave both empty only if the businesses are separated by accounts alone.
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <label class="form-group" style="margin:0;">
                <span class="form-label">Rental Class</span>
                <select class="form-select" id="qbo-tag-class" x-model="tagging.class_id" <?= $canEditCredentials ? '' : 'disabled' ?>>
                    <option value="">— none —</option>
                    <template x-for="c in tagging.classes" :key="c.id">
                        <option :value="c.id" x-text="c.name"></option>
                    </template>
                    <option x-show="tagging.class_id && !tagging.classes.some(c => c.id === tagging.class_id)"
                            :value="tagging.class_id" x-text="tagging.class_name || ('Class ' + tagging.class_id)"></option>
                </select>
            </label>
            <label class="form-group" style="margin:0;">
                <span class="form-label">Rental Location</span>
                <select class="form-select" id="qbo-tag-location" x-model="tagging.location_id" <?= $canEditCredentials ? '' : 'disabled' ?>>
                    <option value="">— none —</option>
                    <template x-for="l in tagging.locations" :key="l.id">
                        <option :value="l.id" x-text="l.name"></option>
                    </template>
                    <option x-show="tagging.location_id && !tagging.locations.some(l => l.id === tagging.location_id)"
                            :value="tagging.location_id" x-text="tagging.location_name || ('Location ' + tagging.location_id)"></option>
                </select>
            </label>
        </div>
        <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:12px;">
            <input type="checkbox" id="qbo-tag-shared" x-model="tagging.shared" <?= $canEditCredentials ? '' : 'disabled' ?>>
            <span class="text-sm">This QuickBooks company is shared with other businesses — the nightly drift check only
                reports records that belong to FleetForge customers and vendors, and Auto-Match links only exact names
                (other matches are shown as suggestions to confirm).</span>
        </label>
        <label class="form-label" for="qbo-push-from" style="display:block;margin-bottom:12px;">
            <span class="text-sm">Push transactions dated from</span>
            <input type="date" class="form-control" id="qbo-push-from" style="max-width:200px;" x-model="tagging.push_from_date"
                   <?= $canEditCredentials ? '' : 'disabled' ?>>
            <span class="text-xs text-secondary" style="display:block;margin-top:4px;">
                Anything FleetForge records with an earlier date (invoices, credit notes, bills, payments, journal entries such as
                depreciation) is treated as already in QuickBooks and is never pushed as new. Leave empty to use the go-live day
                (<span x-text="tagging.go_live_day || 'set the first time sync is switched on'"></span>).
            </span>
        </label>
        <dl class="dl-grid text-sm" style="margin:0 0 12px;" x-show="tagging.prefs.synced_at">
            <dt>Class tracking</dt><dd x-text="{none:'Off', txn:'One class per transaction', line:'Class per line'}[tagging.prefs.class_tracking] || '—'"></dd>
            <dt>Location tracking</dt><dd x-text="tagging.prefs.track_locations === '1' ? 'On' : (tagging.prefs.track_locations === '0' ? 'Off' : '—')"></dd>
            <dt>Custom transaction numbers</dt>
            <dd>
                <span x-text="tagging.prefs.custom_txn_numbers === '1' ? 'On — FleetForge invoice numbers are kept' : (tagging.prefs.custom_txn_numbers === '0' ? 'Off' : '—')"></span>
                <span x-show="tagging.prefs.custom_txn_numbers === '0'" class="text-secondary"> — QuickBooks will number FleetForge's invoices itself (the FleetForge number goes in the memo). Turn it on in QuickBooks (Account and settings → Sales → Custom transaction numbers) to keep the numbers your customers see.</span>
            </dd>
            <dt>Books closed through</dt><dd x-text="tagging.prefs.book_close_date || 'Not set'"></dd>
        </dl>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-secondary btn-sm" @click="loadTagging(true)" :disabled="taggingLoading">
                <span x-text="taggingLoading ? 'Loading…' : 'Load from QuickBooks'"></span>
            </button>
            <button class="btn btn-primary btn-sm" @click="saveTagging()" :disabled="taggingSaving || !<?= $canEditCredentials ? 'true' : 'false' ?>">
                <span x-text="taggingSaving ? 'Saving…' : 'Save tagging'"></span>
            </button>
        </div>
    </div>

    <!-- ============================================================
         CARD 3 — Master Controls (super_admin only)
         ============================================================ -->
    <?php if ($isSuperAdmin): ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Master Controls</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Kill-switches that govern QBO behaviour globally. Visible to super_admin only.
        </p>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.sync_enabled" style="margin-top:3px;">
                <span>
                    <strong>Master Sync Kill-Switch</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Keep OFF until production cutover (S-QBO-30). While OFF, no FF-side change pushes to QBO and no QBO-originated event is processed.</p>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.dry_run_mode" style="margin-top:3px;">
                <span>
                    <strong>Dry Run Mode</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Pushes logged but not sent to QBO. Used during production cutover validation.</p>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="masters.payments_enabled" style="margin-top:3px;">
                <span>
                    <strong>QBO Payments</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Customers pay invoices online through QuickBooks Payments: a <strong>Pay now</strong> button is added to every invoice email, due-soon and overdue reminder, dunning letter and invoice PDF (with a QR code), plus "Pay Online" in the customer portal. Payments come back to FleetForge on their own. Turn on only once QuickBooks Payments is set up on the QuickBooks company.</p>
                </span>
            </label>
        </div>

        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="saveMasters()" :disabled="savingMasters">
                <span x-show="!savingMasters">Save Master Controls</span>
                <span x-show="savingMasters" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         CARD 3.5 — QBO Payments Configuration (F11)
         ============================================================
         Exposes the quickbooks.payments.* URL/TTL keys (seeded by
         S-QBO-15, previously only editable via DB). The master
         payments_enabled toggle lives in Master Controls above.
         Gated on edit_credentials (operator config, not the master
         kill-switch). D-QBO-PAYMENTS-SETTINGS-UI-1.
         ============================================================ -->
    <?php if (can('quickbooks', 'edit_credentials')): ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">QBO Payments Configuration</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Redirect paths + URL lifetime for the customer-portal "Pay Online" hosted page (S-QBO-15).
            The master on/off switch is <strong>QBO Payments</strong> in Master Controls above.
        </p>
        <div style="display:flex;flex-direction:column;gap:14px;max-width:520px;">
            <div>
                <label class="form-label">Success redirect URL</label>
                <input type="text" class="form-control" x-model="payments.success_url"
                       placeholder="portal/payments/payment_success">
            </div>
            <div>
                <label class="form-label">Cancel redirect URL</label>
                <input type="text" class="form-control" x-model="payments.cancel_url"
                       placeholder="portal/payments/payment_cancel">
            </div>
            <div>
                <label class="form-label">Hosted-page URL lifetime (minutes)</label>
                <input type="number" class="form-control" x-model="payments.url_ttl_minutes"
                       min="1" max="1440" step="1" style="max-width:160px;">
                <p class="text-secondary text-sm" style="margin:4px 0 0;">1–1440. After this, a generated payment URL expires.</p>
            </div>
        </div>
        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="savePaymentsConfig()" :disabled="savingPayments">
                <span x-show="!savingPayments">Save Payments Config</span>
                <span x-show="savingPayments" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         CARD 4 — Sync & monitoring (I20 / I23)
         ============================================================
         Settings the crons + pushers read that previously had no screen
         (operators had to edit the settings table): bank-feed CDC, the
         nightly drift check, the Refund Receipt QBO references, and the
         per-entity sync modes. super_admin edits them (same gate as Master
         Controls — they change what reaches the books); everyone else gets
         the read-only sync-mode table in the else-branch below.
         Saved via api/v1/quickbooks/save_sync_controls.php (whitelisted).
         ============================================================ -->
    <?php if ($isSuperAdmin): ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Sync &amp; monitoring</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Background jobs and per-record-type push behaviour. These only matter while the Master Sync switch above is on.
            Visible to super_admin only.
        </p>

        <!-- Bank feed + drift toggles -->
        <div style="display:flex;flex-direction:column;gap:14px;">
            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="syncCtl.cdc_enabled" style="margin-top:3px;">
                <span>
                    <strong>Bank transaction feed (nightly)</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Copies new and changed bank transactions from QuickBooks every night (02:30), and powers "Run CDC Now" on the Bank Accounts page. Turn off to stop pulling bank activity.</p>
                </span>
            </label>
            <div style="padding-left:24px;">
                <label class="form-label" for="qbo-cdc-lookback">First-run lookback (days)</label>
                <input type="number" class="form-control" id="qbo-cdc-lookback" x-model="syncCtl.cdc_lookback_days"
                       min="1" max="365" step="1" style="max-width:160px;">
                <p class="text-secondary text-sm" style="margin:4px 0 0;">1–365. How far back the very first pull reaches; later runs continue from the last successful pull.</p>
            </div>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="syncCtl.drift_enabled" style="margin-top:3px;">
                <span>
                    <strong>Nightly drift check</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Compares FleetForge records with QuickBooks every night and lists differences on the Drift page. Turn off only while investigating a noisy run.</p>
                </span>
            </label>

            <label style="display:flex;align-items:flex-start;gap:10px;">
                <input type="checkbox" x-model="syncCtl.drift_gl_balance_enabled" style="margin-top:3px;">
                <span>
                    <strong>Include account-balance comparison</strong>
                    <p class="text-secondary text-sm" style="margin:2px 0 0;">Also compares each mapped account's balance with QuickBooks. Off by default — balances legitimately differ mid-month, so turn it on once your accountant has set a reconciliation routine.</p>
                </span>
            </label>
        </div>

        <!-- Refund Receipt references -->
        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-default);">
            <h4 class="text-sm" style="margin:0 0 4px;font-weight:600;">Customer refunds</h4>
            <p class="text-secondary text-sm" style="margin:0 0 12px;">
                Refunds are sent to QuickBooks as Refund Receipts, which need the bank account the money leaves from and a payment method.
                Until both are set, refunds wait on the Refund Receipts page with a "not configured" reason.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
                <div>
                    <label class="form-label" for="qbo-refund-account">Refund bank account (QuickBooks Account Id)</label>
                    <input type="text" class="form-control" id="qbo-refund-account" list="qbo-refund-account-options"
                           inputmode="numeric" x-model="syncCtl.refund_deposit_account_id" placeholder="e.g. 35">
                    <datalist id="qbo-refund-account-options">
                        <?php foreach ($refundAccountOptions as $opt): ?>
                            <option value="<?= e((string) $opt['qbo_account_id']) ?>"><?= e((string) $opt['qbo_name']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="text-secondary text-sm" style="margin:4px 0 0;">
                        <?php if ($refundAccountOptions !== []): ?>
                            Pick one of your QuickBooks bank accounts from the suggestions, or type its Id.
                        <?php else: ?>
                            No QuickBooks bank accounts pulled yet — pull them on the Accounts mapping page, or type the Id.
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <label class="form-label" for="qbo-refund-method">Refund payment method (QuickBooks PaymentMethod Id)</label>
                    <input type="text" class="form-control" id="qbo-refund-method" inputmode="numeric"
                           x-model="syncCtl.refund_payment_method_id" placeholder="e.g. 2">
                    <p class="text-secondary text-sm" style="margin:4px 0 0;">Usually your "Cheque" payment method. Find the Id in QuickBooks under Lists → Payment methods.</p>
                </div>
            </div>
        </div>

        <!-- Per-entity sync modes -->
        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-default);">
            <h4 class="text-sm" style="margin:0 0 4px;font-weight:600;">Per-record-type sync</h4>
            <p class="text-secondary text-sm" style="margin:0 0 12px;">
                Whether FleetForge sends each kind of record to QuickBooks. "Push to QuickBooks" and "Push (queue)" behave the same today —
                the change is queued and the background worker sends it within a minute. "Disabled" and "QuickBooks → FleetForge only" both
                stop FleetForge from sending that record type; neither changes what FleetForge reads from QuickBooks.
            </p>
            <table class="table table-striped" style="margin:0;font-size:0.875rem;">
                <thead>
                    <tr>
                        <th>Record type</th>
                        <th style="width:380px;">Mode</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($syncModeEntities as $entityKey => $meta): ?>
                    <tr>
                        <td>
                            <strong><?= e($meta['label']) ?></strong>
                            <?php if ($meta['note'] !== null): ?>
                                <p class="text-secondary text-sm" style="margin:2px 0 0;"><?= e($meta['note']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="form-select" x-model="syncCtl.modes.<?= e($entityKey) ?>" aria-label="<?= e($meta['label']) ?> sync mode">
                                <?php foreach ($syncModeOptions as $optValue => $optLabel): ?>
                                    <option value="<?= e($optValue) ?>"><?= e($optLabel) ?></option>
                                <?php endforeach; ?>
                                <?php if (!isset($syncModeOptions[$syncModesCurrent[$entityKey]])): ?>
                                    <!-- Stored value outside the honoured set (e.g. legacy 'off'): shown so the
                                         select doesn't silently misreport it; only sent if the operator changes it. -->
                                    <option value="<?= e($syncModesCurrent[$entityKey]) ?>"><?= e($syncModesCurrent[$entityKey]) ?> (current — not a supported mode)</option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-secondary text-xs" style="margin:10px 0 0;">
                Items are created from the Items page and have no sync mode. Fixed-asset and GST remittance entries follow Journal entries.
            </p>
        </div>

        <div style="margin-top:18px;">
            <button class="btn btn-primary btn-sm" @click="saveSyncControls()" :disabled="savingSync">
                <span x-show="!savingSync">Save Sync &amp; Monitoring</span>
                <span x-show="savingSync" x-cloak>Saving…</span>
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card" style="padding:20px;margin-bottom:16px;">
        <h3 class="h6" style="margin:0 0 4px;">Per-Record-Type Sync (read-only)</h3>
        <p class="text-secondary text-sm" style="margin:0 0 14px;">
            Whether FleetForge sends each kind of record to QuickBooks while master sync is on.
            <code>sync</code> / <code>queue</code> = pushed (via the background worker); <code>disabled</code> / <code>qbo_to_ff</code> = not pushed.
        </p>
        <table class="table table-striped" style="margin:0;font-size:0.875rem;">
            <thead>
                <tr>
                    <th>Record type</th>
                    <th>Mode</th>
                    <th class="text-secondary">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($syncModeEntities as $entityKey => $meta):
                    $mode = $syncModesCurrent[$entityKey];
                    $modeColor = in_array($mode, ['disabled', 'qbo_to_ff'], true) ? 'badge-neutral' : 'badge-success';
                ?>
                <tr>
                    <td><strong><?= e($meta['label']) ?></strong></td>
                    <td><span class="badge <?= $modeColor ?>"><?= e($mode) ?></span></td>
                    <td class="text-secondary text-sm"><?= $meta['note'] !== null ? e($meta['note']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-secondary text-xs" style="margin:14px 0 0;">
            A super admin can change these under Sync &amp; monitoring on this page.
        </p>
    </div>
    <?php endif; ?>

</div>

<script>
function qboSettings() {
    return {
        // ── State ─────────────────────────────────────────────
        flash:           { message: '', type: 'success' },
        testing:         false,
        disconnecting:   false,
        savingCreds:     false,
        savingMasters:   false,
        testResult:      null,

        // The form initial values: environment + sandbox_redirect_uri
        // are NON-sensitive so they're seeded from the server-rendered
        // values directly. The sensitive fields start blank — the
        // operator types a new value to replace, blank=keep-existing.
        creds: {
            environment:            '<?= e($qbo['environment'] ?? 'sandbox') ?>',
            client_id:              '',
            client_secret:          '',
            webhook_verifier_token: '',
            sandbox_redirect_uri:   '<?= e($qbo['sandbox_redirect_uri'] ?? '') ?>',
        },

        // Sensitive-field placeholders show the masked existing value
        // so operators see "we already have something stored" without
        // ever sending the full token to the browser.
        placeholders: {
            client_id:              '<?= e(ff_qbo_mask($qbo['client_id'] ?? null)) ?>',
            client_secret:          '<?= e(ff_qbo_mask(\FleetForge\QuickBooksClient::secret('client_secret'))) ?>', // S-QBO-GOLIVE-AUDIT: decrypt before masking
            webhook_verifier_token: '<?= e(ff_qbo_mask(\FleetForge\QuickBooksClient::secret('webhook_verifier_token'))) ?>',
        },

        masters: {
            sync_enabled:     <?= ($qbo['sync_enabled'] ?? '0') === '1' ? 'true' : 'false' ?>,
            dry_run_mode:     <?= ($qbo['dry_run_mode'] ?? '0') === '1' ? 'true' : 'false' ?>,
            payments_enabled: <?= ($qbo['payments_enabled'] ?? '0') === '1' ? 'true' : 'false' ?>,
        },

        // F11 — QBO Payments config (URL/TTL knobs).
        savingPayments: false,
        payments: {
            success_url:     '<?= e($qbo['payments.success_url'] ?? 'portal/payments/payment_success') ?>',
            cancel_url:      '<?= e($qbo['payments.cancel_url'] ?? 'portal/payments/payment_cancel') ?>',
            url_ttl_minutes: '<?= e($qbo['payments.url_ttl_minutes'] ?? '30') ?>',
        },

        // I20 / I23 — Sync & monitoring card (super_admin). Booleans held as
        // real booleans for the checkboxes and serialised to '1'/'0' on save.
        savingSync: false,
        syncCtl: {
            cdc_enabled:              <?= ($qbo['banking.cdc_enabled'] ?? '1') === '1' ? 'true' : 'false' ?>,
            cdc_lookback_days:        <?= json_encode((string) ($qbo['banking.cdc_lookback_days'] ?? '90')) ?>,
            drift_enabled:            <?= ($qbo['drift.enabled'] ?? '1') === '1' ? 'true' : 'false' ?>,
            drift_gl_balance_enabled: <?= ($qbo['drift.gl_balance_enabled'] ?? '0') === '1' ? 'true' : 'false' ?>,
            refund_deposit_account_id: <?= json_encode((string) ($qbo['refund.deposit_account_id'] ?? '')) ?>,
            refund_payment_method_id:  <?= json_encode((string) ($qbo['refund.payment_method_id'] ?? '')) ?>,
            modes: <?= json_encode((object) $syncModesCurrent, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        },
        // Modes as loaded — only CHANGED modes are sent, so a legacy stored
        // value outside the endpoint's whitelist (e.g. 'off') never blocks a
        // save of the other fields just by being displayed.
        syncModesSaved: <?= json_encode((object) $syncModesCurrent, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        // ── Lifecycle ─────────────────────────────────────────
        init() {
            // Surface query-string flash messages (e.g. from OAuth
            // callback redirect). Cleared after read.
            const params = new URLSearchParams(window.location.search);
            if (params.has('flash_success')) {
                this.flash = { message: params.get('flash_success'), type: 'success' };
            } else if (params.has('flash_error')) {
                this.flash = { message: params.get('flash_error'), type: 'error' };
            }
        },

        // ── Actions ───────────────────────────────────────────
        async testConnection() {
            this.testing    = true;
            this.testResult = null;
            try {
                const r = await fetch(FF_Api.url('/api/v1/quickbooks/test_connection.php'), {
                    method: 'GET',
                    headers: { 'X-CSRF-Token': FF_CSRF_TOKEN, 'Accept': 'application/json' },
                });
                const data = await r.json();
                this.testResult = data;
            } catch (e) {
                this.testResult = { success: false, error: e.message || 'Network error' };
            } finally {
                this.testing = false;
            }
        },

        async disconnect() {
            if (!confirm('Disconnect from QuickBooks? Pending sync queue items will remain queued but will not process until re-connected.')) {
                return;
            }
            this.disconnecting = true;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/disconnect.php'), {});
                if (r.success) {
                    this.flash = { message: 'Disconnected from QuickBooks.', type: 'success' };
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Disconnect failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.disconnecting = false;
            }
        },

        // S-QBO-GOLIVE-AUDIT — business tagging for the shared QuickBooks file.
        tagging: { class_id: '', class_name: '', location_id: '', location_name: '', shared: true, push_from_date: '', go_live_day: '', classes: [], locations: [], prefs: {} },
        taggingLoading: false,
        taggingSaving: false,
        async loadTagging(fromQbo) {
            this.taggingLoading = !!fromQbo;
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/quickbooks/business_tagging.php' + (fromQbo ? '?load=1' : '')));
                if (r && r.success) {
                    const d = r.data;
                    this.tagging = Object.assign(this.tagging, {
                        class_id: d.class_id || '', class_name: d.class_name || '',
                        location_id: d.location_id || '', location_name: d.location_name || '',
                        shared: d.shared_company_file !== '0',
                        push_from_date: d.push_from_date || '', go_live_day: d.go_live_day || '',
                        classes: d.classes || [], locations: d.locations || [], prefs: d.prefs || {},
                    });
                    if (d.load_error) {
                        this.flash = { message: d.load_error, type: 'error' };
                    }
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.taggingLoading = false;
            }
        },
        async saveTagging() {
            this.taggingSaving = true;
            const cls = this.tagging.classes.find(c => c.id === this.tagging.class_id);
            const loc = this.tagging.locations.find(l => l.id === this.tagging.location_id);
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/business_tagging.php'), {
                    class_id: this.tagging.class_id, class_name: cls ? cls.name : this.tagging.class_name,
                    location_id: this.tagging.location_id, location_name: loc ? loc.name : this.tagging.location_name,
                    shared_company_file: this.tagging.shared ? '1' : '0',
                    push_from_date: this.tagging.push_from_date || '',
                });
                this.flash = r.success
                    ? { message: 'Business tagging saved.', type: 'success' }
                    : { message: (r.error && r.error.message) || 'Save failed.', type: 'error' };
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.taggingSaving = false;
            }
        },

        // S-QBO-GOLIVE-AUDIT — wipe the old company's mappings after a realm change.
        resetting: false,
        async resetMappings() {
            const typed = prompt('This permanently deletes every QuickBooks mapping (customers, accounts, items, tax codes, pushed-document links) so FleetForge can be used with the connected company.\n\nType RESET to continue.');
            if (typed !== 'RESET') {
                return;
            }
            this.resetting = true;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/reset_mappings.php'), { confirm: 'RESET' });
                if (r.success) {
                    this.flash = { message: 'Mappings reset. Re-run the mapping pages before enabling sync.', type: 'success' };
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Reset failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.resetting = false;
            }
        },

        async saveCredentials() {
            this.savingCreds = true;
            try {
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_credentials.php'), this.creds);
                if (r.success) {
                    this.flash = { message: 'Credentials saved.', type: 'success' };
                    // Blank the typed-in sensitive fields so they re-mask
                    this.creds.client_id              = '';
                    this.creds.client_secret          = '';
                    this.creds.webhook_verifier_token = '';
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Save failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingCreds = false;
            }
        },

        async saveMasters() {
            this.savingMasters = true;
            try {
                const payload = {
                    sync_enabled:     this.masters.sync_enabled     ? '1' : '0',
                    dry_run_mode:     this.masters.dry_run_mode     ? '1' : '0',
                    payments_enabled: this.masters.payments_enabled ? '1' : '0',
                };
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_master_controls.php'), payload);
                if (r.success) {
                    this.flash = { message: 'Master controls saved.', type: 'success' };
                } else {
                    this.flash = { message: (r.error && r.error.message) || 'Save failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingMasters = false;
            }
        },

        // I20 / I23 — save the Sync & monitoring card. The endpoint validates
        // every key against its whitelist; FF_Api.post RESOLVES on a 422, so
        // success is gated on r.success and field errors are surfaced.
        async saveSyncControls() {
            this.savingSync = true;
            try {
                const settings = {
                    'banking.cdc_enabled':        this.syncCtl.cdc_enabled ? '1' : '0',
                    'banking.cdc_lookback_days':  String(this.syncCtl.cdc_lookback_days ?? '').trim(),
                    'drift.enabled':              this.syncCtl.drift_enabled ? '1' : '0',
                    'drift.gl_balance_enabled':   this.syncCtl.drift_gl_balance_enabled ? '1' : '0',
                    'refund.deposit_account_id':  String(this.syncCtl.refund_deposit_account_id ?? '').trim(),
                    'refund.payment_method_id':   String(this.syncCtl.refund_payment_method_id ?? '').trim(),
                };
                for (const [entity, mode] of Object.entries(this.syncCtl.modes)) {
                    if (mode !== this.syncModesSaved[entity]) {
                        settings['sync_mode.' + entity] = mode;
                    }
                }
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_sync_controls.php'), { settings });
                if (r.success) {
                    // New baseline so the next save only sends later changes.
                    this.syncModesSaved = Object.assign({}, this.syncCtl.modes);
                    this.flash = { message: 'Sync & monitoring settings saved.', type: 'success' };
                } else {
                    const fields = r.error && r.error.fields;
                    const detail = fields ? Object.entries(fields).map(([k, v]) => k + ': ' + v).join(' ') : '';
                    this.flash = { message: detail || (r.error && r.error.message) || 'Save failed.', type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingSync = false;
            }
        },

        // F11 — save the QBO Payments URL/TTL config.
        async savePaymentsConfig() {
            this.savingPayments = true;
            try {
                const payload = {
                    success_url:     (this.payments.success_url || '').trim(),
                    cancel_url:      (this.payments.cancel_url || '').trim(),
                    url_ttl_minutes: parseInt(this.payments.url_ttl_minutes, 10) || 30,
                };
                const r = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/save_payments_config.php'), payload);
                if (r.success) {
                    this.flash = { message: 'Payments config saved.', type: 'success' };
                } else {
                    const msg = (r.error && (r.error.message || (r.error.fields && Object.values(r.error.fields).join(' ')))) || 'Save failed.';
                    this.flash = { message: msg, type: 'error' };
                }
            } catch (e) {
                this.flash = { message: e.message || 'Network error', type: 'error' };
            } finally {
                this.savingPayments = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
