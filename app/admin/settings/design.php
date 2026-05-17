<?php
declare(strict_types=1);

/**
 * app/admin/settings/design.php
 *
 * Design tab content — rendered inside app/admin/settings/index.php
 * via require_once when activeTab === 'design'. Super-admin only;
 * the tab button on index.php is also gated by is_super_admin(), and
 * this file re-checks server-side so direct require / route access
 * cannot bypass it.
 *
 * Layout: five vertically stacked .card blocks, each a self-contained
 * <form> that POSTs to api/v1/settings/brand.php as multipart/form-data
 * with only its own fields. The shared API treats every field as
 * optional and only updates rows that arrive in the request.
 *
 * Cards:
 *   1. Brand Identity   — color picker + 6 swatches + live preview + logo + favicon
 *   2. New User Defaults — theme / density / font size
 *   3. Regional         — date / time / currency / timezone / distance unit
 *   4. PDF & Invoices   — footer text + logo toggle + accent override
 *   5. UI Behaviour     — sidebar default + rows per page + session timeout
 *
 * Decisions: D9 (StorageClient), D32 (CSS classes), Trap 7 (no path leak)
 * Session:   S-DESIGN-SETTINGS-FOOTER-LOGIN
 */

// Server-side gate — direct includes / route access must also be super_admin.
// The tab button on settings/index.php is wrapped in the same check, so the
// only path that reaches this file unprotected would be a misrouted require.
if (!is_super_admin()) {
    http_response_code(403);
    echo '<div class="card"><div class="card-body" style="text-align:center;padding:48px;">'
       . '<strong>Forbidden</strong><p style="color:var(--text-muted);font-size:0.875rem;margin-top:8px;">'
       . 'Design settings are limited to Developer users.</p></div></div>';
    return;
}

// ── Current values (used to pre-populate every control) ───────
$brand_primary_color = (string) (settings_get('brand.primary_color') ?? '#2596be');
$brand_logo_path     = (string) (settings_get('brand.logo_path')     ?? '');
$brand_favicon_path  = (string) (settings_get('brand.favicon_path')  ?? '');

$defaults_theme         = (string) (settings_get('defaults.theme')         ?? 'dark');
$defaults_density       = (string) (settings_get('defaults.density')       ?? 'comfortable');
$defaults_font_size     = (int)    (settings_get('defaults.font_size')     ?? 100);
$defaults_rows_per_page = (int)    (settings_get('defaults.rows_per_page') ?? 25);

$regional_date_format     = (string) (settings_get('regional.date_format')     ?? 'M/D/YYYY');
$regional_time_format     = (string) (settings_get('regional.time_format')     ?? '12h');
$regional_currency_symbol = (string) (settings_get('regional.currency_symbol') ?? '$');
$regional_timezone        = (string) (settings_get('regional.timezone')        ?? 'America/Vancouver');
$regional_distance_unit   = (string) (settings_get('regional.distance_unit')   ?? 'km');

$pdf_invoice_footer_text = (string) (settings_get('pdf.invoice_footer_text') ?? 'Thank you for your business.');
$pdf_show_logo           = (int)    (settings_get('pdf.show_logo')           ?? 1);
$pdf_accent_color        = (string) (settings_get('pdf.accent_color')        ?? '');

$ui_sidebar_collapsed_default = (int) (settings_get('ui.sidebar_collapsed_default') ?? 0);
$ui_session_timeout_minutes   = (int) (settings_get('ui.session_timeout_minutes')   ?? 480);

// Logo / favicon preview URLs — empty string if nothing uploaded yet.
// StorageClient::url() returns a signed local URL OR an S3 presigned
// URL depending on driver. Generated only when a path exists so we
// don't sign an empty key.
$logoUrl    = $brand_logo_path    !== '' ? \FleetForge\Storage\StorageClient::url($brand_logo_path, 3600)    : '';
$faviconUrl = $brand_favicon_path !== '' ? \FleetForge\Storage\StorageClient::url($brand_favicon_path, 3600) : '';

// The 6 preset swatches — first is the current Steel Blue default,
// then the original Amber Orange, then four conservative alternates.
$swatches = [
    ['#2596be', 'Steel Blue'],
    ['#ea6f00', 'Amber Orange'],
    ['#0f6e56', 'Fleet Green'],
    ['#6366f1', 'Indigo'],
    ['#dc2626', 'Red'],
    ['#1a1a1a', 'Charcoal'],
];

$brandApi = base_url('api/v1/settings/brand');
?>

<style>
    /* Inline design-page CSS — scoped via #ff-design-tab so it
       cannot bleed into other settings tabs sharing the page. */
    #ff-design-tab .ff-swatch-row {
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:10px;
    }
    #ff-design-tab .ff-swatch {
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:6px 12px;
        border-radius:999px;
        border:1px solid var(--border-color);
        background:var(--bg-muted);
        cursor:pointer;
        font-size:0.8125rem;
        color:var(--text-secondary);
        transition:transform 0.15s ease, border-color 0.15s ease;
    }
    #ff-design-tab .ff-swatch:hover { transform:translateY(-1px); }
    #ff-design-tab .ff-swatch.is-active {
        border-color:var(--color-primary);
        color:var(--text-primary);
    }
    #ff-design-tab .ff-swatch-dot {
        width:14px;
        height:14px;
        border-radius:50%;
        border:1px solid rgba(0,0,0,0.18);
    }
    #ff-design-tab .ff-color-picker {
        width:56px;
        height:36px;
        padding:0;
        border:1px solid var(--border-color);
        border-radius:8px;
        background:transparent;
        cursor:pointer;
    }
    #ff-design-tab .ff-preview-strip {
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        gap:12px;
        margin-top:16px;
        padding:16px;
        border-radius:10px;
        background:var(--bg-muted);
        border:1px solid var(--border-color);
    }
    #ff-design-tab .ff-preview-strip .ff-preview-btn {
        padding:8px 16px;
        border-radius:8px;
        border:none;
        color:#fff;
        font-size:0.8125rem;
        font-weight:500;
        cursor:default;
    }
    #ff-design-tab .ff-preview-strip .ff-preview-badge {
        padding:3px 10px;
        border-radius:999px;
        font-size:0.75rem;
        color:#fff;
    }
    #ff-design-tab .ff-preview-strip .ff-preview-nav {
        padding:6px 12px;
        border-radius:8px;
        font-size:0.8125rem;
        font-weight:500;
        color:#fff;
    }
    #ff-design-tab .ff-preview-strip .ff-preview-link {
        font-size:0.8125rem;
        text-decoration:underline;
    }
    #ff-design-tab .ff-preview-strip .ff-preview-input {
        flex:1;
        min-width:180px;
        padding:8px 12px;
        border-radius:8px;
        border:1px solid var(--border-color);
        background:var(--bg-input);
        color:var(--text-primary);
        font-size:0.8125rem;
        outline:none;
    }
    #ff-design-tab .ff-preview-strip .ff-preview-input:focus {
        box-shadow:0 0 0 2px var(--color-primary, #2596be);
    }
    #ff-design-tab .ff-thumb {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        background:var(--bg-muted);
        border:1px dashed var(--border-color);
        border-radius:10px;
        overflow:hidden;
    }
    #ff-design-tab .ff-thumb-logo    { width:200px; height:56px; }
    #ff-design-tab .ff-thumb-favicon { width:48px;  height:48px; }
    #ff-design-tab .ff-thumb img {
        max-width:100%;
        max-height:100%;
        object-fit:contain;
    }
    #ff-design-tab .ff-segment {
        display:inline-flex;
        gap:0;
        padding:2px;
        border-radius:8px;
        background:var(--bg-muted);
        border:1px solid var(--border-color);
    }
    #ff-design-tab .ff-segment label {
        padding:6px 14px;
        border-radius:6px;
        font-size:0.8125rem;
        font-weight:500;
        color:var(--text-secondary);
        cursor:pointer;
        margin:0;
    }
    #ff-design-tab .ff-segment input[type="radio"] {
        position:absolute;
        opacity:0;
        pointer-events:none;
    }
    #ff-design-tab .ff-segment input[type="radio"]:checked + span {
        background:var(--color-primary);
        color:#fff;
        padding:6px 14px;
        margin:-6px -14px;
        border-radius:6px;
    }
    #ff-design-tab .ff-helper {
        font-size:0.75rem;
        color:var(--text-muted);
        margin:6px 0 0;
    }
    #ff-design-tab .ff-form-row {
        display:flex;
        flex-direction:column;
        gap:6px;
        margin-bottom:18px;
    }
    #ff-design-tab .ff-form-grid {
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(240px,1fr));
        gap:18px 24px;
    }
    #ff-design-tab .ff-file-meta {
        font-size:0.75rem;
        color:var(--text-muted);
        margin-top:6px;
    }
    #ff-design-tab .ff-card-actions {
        padding-top:18px;
        margin-top:18px;
        border-top:1px solid var(--border-color);
        display:flex;
        align-items:center;
        gap:12px;
    }
    #ff-design-tab .ff-card-actions .ff-save-msg {
        font-size:0.8125rem;
    }
</style>

<div id="ff-design-tab" x-data="FF_DesignTab(<?= e(json_encode([
    'csrf'             => $_csrfToken ?? ($_SESSION['csrf_token'] ?? ''),
    'api'              => $brandApi,
    'primary'          => $brand_primary_color,
    'currentLogoUrl'   => $logoUrl,
    'currentFavicon'   => $faviconUrl,
    'hasLogo'          => $brand_logo_path !== '',
    'hasFavicon'       => $brand_favicon_path !== '',
], JSON_UNESCAPED_SLASHES)) ?>)">

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 1 — Brand Identity                                    -->
<!-- ════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Brand Identity</div>
    <div class="card-body">

        <!-- Brand primary color form (covers color + derived hover/light) -->
        <form @submit.prevent="saveCard('brand-color', $event)" data-card="brand-color">
            <div class="ff-form-row">
                <label class="form-label">Primary Color</label>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <input type="color"
                           name="brand_primary_color"
                           class="ff-color-picker"
                           :value="primary"
                           @input="applyPreview($event.target.value)">
                    <code style="font-size:0.8125rem;color:var(--text-secondary);" x-text="primary"></code>
                    <a href="#" @click.prevent="resetDefault()" style="font-size:0.8125rem;">Reset to default</a>
                </div>

                <div class="ff-swatch-row" role="group" aria-label="Color presets">
                    <?php foreach ($swatches as [$hex, $name]): ?>
                    <button type="button"
                            class="ff-swatch"
                            :class="{ 'is-active': primary.toLowerCase() === '<?= e(strtolower($hex)) ?>' }"
                            data-color="<?= e($hex) ?>"
                            @click="applyPreview('<?= e($hex) ?>')">
                        <span class="ff-swatch-dot" style="background:<?= e($hex) ?>;"></span>
                        <?= e($name) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Live preview strip — updates inline as the color changes. -->
                <div class="ff-preview-strip" :style="`--color-primary:${primary}; --color-primary-hover:${hover}; --color-primary-light:${light};`">
                    <button class="ff-preview-btn" :style="`background:${primary};`" type="button">Primary Action</button>
                    <span class="ff-preview-badge" :style="`background:${primary};`">Badge</span>
                    <span class="ff-preview-nav" :style="`background:${primary};`">Active Nav</span>
                    <input class="ff-preview-input" type="text" placeholder="Input focus ring" :style="`--color-primary:${primary};`">
                    <a href="#" class="ff-preview-link" :style="`color:${primary};`" @click.prevent>A styled link</a>
                </div>
            </div>

            <div class="ff-card-actions">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['brand-color']">
                    <span x-show="!saving['brand-color']">Save Brand Color</span>
                    <span x-show="saving['brand-color']" x-cloak>Saving&hellip;</span>
                </button>
                <span class="ff-save-msg"
                      :style="msgStyle('brand-color')"
                      x-text="msg['brand-color'] || ''"
                      x-show="msg['brand-color']"></span>
            </div>
        </form>

        <hr style="margin:24px 0;border:none;border-top:1px solid var(--border-color);">

        <!-- Logo upload form -->
        <form @submit.prevent="saveCard('brand-logo', $event)" data-card="brand-logo" enctype="multipart/form-data">
            <div class="ff-form-row">
                <label class="form-label">Company Logo</label>
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div class="ff-thumb ff-thumb-logo">
                        <template x-if="logoPreview">
                            <img :src="logoPreview" alt="Logo preview">
                        </template>
                        <template x-if="!logoPreview">
                            <span style="font-size:0.75rem;color:var(--text-muted);">No logo</span>
                        </template>
                    </div>
                    <div>
                        <input type="file"
                               name="logo_file"
                               accept=".png,.svg,.jpg,.jpeg,image/png,image/svg+xml,image/jpeg"
                               @change="onLogoChange($event)">
                        <p class="ff-file-meta" x-text="logoMeta || 'PNG, SVG or JPG · Max 2MB · Recommended: 200×48px'"></p>
                    </div>
                </div>
                <template x-if="hasLogo">
                    <div style="margin-top:10px;">
                        <button type="button" class="btn btn-secondary btn-xs" @click="removeLogo()">Remove logo</button>
                    </div>
                </template>
            </div>

            <div class="ff-card-actions">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['brand-logo']">
                    <span x-show="!saving['brand-logo']">Save Logo</span>
                    <span x-show="saving['brand-logo']" x-cloak>Saving&hellip;</span>
                </button>
                <span class="ff-save-msg"
                      :style="msgStyle('brand-logo')"
                      x-text="msg['brand-logo'] || ''"
                      x-show="msg['brand-logo']"></span>
            </div>
        </form>

        <hr style="margin:24px 0;border:none;border-top:1px solid var(--border-color);">

        <!-- Favicon upload form -->
        <form @submit.prevent="saveCard('brand-favicon', $event)" data-card="brand-favicon" enctype="multipart/form-data">
            <div class="ff-form-row">
                <label class="form-label">Favicon</label>
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div class="ff-thumb ff-thumb-favicon">
                        <template x-if="faviconPreview">
                            <img :src="faviconPreview" alt="Favicon preview">
                        </template>
                        <template x-if="!faviconPreview">
                            <span style="font-size:0.65rem;color:var(--text-muted);">none</span>
                        </template>
                    </div>
                    <div>
                        <input type="file"
                               name="favicon_file"
                               accept=".png,.ico,image/png,image/x-icon"
                               @change="onFaviconChange($event)">
                        <p class="ff-file-meta" x-text="faviconMeta || 'PNG or ICO · Max 512KB · Recommended: 32×32px'"></p>
                    </div>
                </div>
                <template x-if="hasFavicon">
                    <div style="margin-top:10px;">
                        <button type="button" class="btn btn-secondary btn-xs" @click="removeFavicon()">Remove favicon</button>
                    </div>
                </template>
            </div>

            <div class="ff-card-actions">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['brand-favicon']">
                    <span x-show="!saving['brand-favicon']">Save Favicon</span>
                    <span x-show="saving['brand-favicon']" x-cloak>Saving&hellip;</span>
                </button>
                <span class="ff-save-msg"
                      :style="msgStyle('brand-favicon')"
                      x-text="msg['brand-favicon'] || ''"
                      x-show="msg['brand-favicon']"></span>
            </div>
        </form>

    </div>
</div>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 2 — New User Defaults                                  -->
<!-- ════════════════════════════════════════════════════════════ -->
<form @submit.prevent="saveCard('defaults', $event)" data-card="defaults">
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">New User Defaults</div>
    <div class="card-body">

        <div class="ff-form-grid">

            <div>
                <label class="form-label">Default Theme</label>
                <div class="ff-segment" role="radiogroup" aria-label="Default theme">
                    <label>
                        <input type="radio" name="defaults_theme" value="dark" <?= $defaults_theme === 'dark' ? 'checked' : '' ?>>
                        <span>Dark</span>
                    </label>
                    <label>
                        <input type="radio" name="defaults_theme" value="light" <?= $defaults_theme === 'light' ? 'checked' : '' ?>>
                        <span>Light</span>
                    </label>
                </div>
                <p class="ff-helper">Applied when a new user is invited. Existing users keep their own preferences.</p>
            </div>

            <div>
                <label class="form-label">Default Density</label>
                <div class="ff-segment" role="radiogroup" aria-label="Default density">
                    <label>
                        <input type="radio" name="defaults_density" value="compact"      <?= $defaults_density === 'compact'      ? 'checked' : '' ?>>
                        <span>Compact</span>
                    </label>
                    <label>
                        <input type="radio" name="defaults_density" value="comfortable"  <?= $defaults_density === 'comfortable'  ? 'checked' : '' ?>>
                        <span>Comfortable</span>
                    </label>
                    <label>
                        <input type="radio" name="defaults_density" value="spacious"     <?= $defaults_density === 'spacious'     ? 'checked' : '' ?>>
                        <span>Spacious</span>
                    </label>
                </div>
                <p class="ff-helper">Applied when a new user is invited. Existing users keep their own preferences.</p>
            </div>

            <div>
                <label class="form-label" for="defaults_font_size">Default Font Size — <span id="font-size-readout"><?= e((string) $defaults_font_size) ?></span>%</label>
                <input type="range"
                       id="defaults_font_size"
                       name="defaults_font_size"
                       min="85" max="115" step="5"
                       value="<?= e((string) $defaults_font_size) ?>"
                       style="width:100%;"
                       oninput="document.getElementById('font-size-readout').textContent = this.value;">
                <p class="ff-helper">Applied when a new user is invited. Existing users keep their own preferences.</p>
            </div>

        </div>

        <div class="ff-card-actions">
            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['defaults']">
                <span x-show="!saving['defaults']">Save Defaults</span>
                <span x-show="saving['defaults']" x-cloak>Saving&hellip;</span>
            </button>
            <span class="ff-save-msg"
                  :style="msgStyle('defaults')"
                  x-text="msg['defaults'] || ''"
                  x-show="msg['defaults']"></span>
        </div>

    </div>
</div>
</form>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 3 — Regional                                           -->
<!-- ════════════════════════════════════════════════════════════ -->
<form @submit.prevent="saveCard('regional', $event)" data-card="regional">
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Regional</div>
    <div class="card-body">

        <div class="ff-form-grid">

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="regional_date_format">Date Format</label>
                <select name="regional_date_format" id="regional_date_format" class="form-control">
                    <?php foreach (['M/D/YYYY' => 'M/D/YYYY (e.g. 5/17/2026)', 'D/M/YYYY' => 'D/M/YYYY (e.g. 17/5/2026)', 'YYYY-MM-DD' => 'YYYY-MM-DD (e.g. 2026-05-17)'] as $v => $l): ?>
                        <option value="<?= e($v) ?>" <?= $regional_date_format === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="regional_time_format">Time Format</label>
                <select name="regional_time_format" id="regional_time_format" class="form-control">
                    <option value="12h" <?= $regional_time_format === '12h' ? 'selected' : '' ?>>12-hour (e.g. 3:45 PM)</option>
                    <option value="24h" <?= $regional_time_format === '24h' ? 'selected' : '' ?>>24-hour (e.g. 15:45)</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="regional_currency_symbol">Currency Symbol</label>
                <input type="text"
                       name="regional_currency_symbol"
                       id="regional_currency_symbol"
                       class="form-control"
                       maxlength="8"
                       value="<?= e($regional_currency_symbol) ?>">
                <p class="ff-helper">Prefix shown before monetary values. Examples: $, CAD $, USD $, €.</p>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="regional_timezone">Timezone</label>
                <select name="regional_timezone" id="regional_timezone" class="form-control">
                    <?php foreach ([
                        'America/Vancouver'   => 'Vancouver (PT)',
                        'America/Edmonton'    => 'Edmonton (MT)',
                        'America/Regina'      => 'Regina (CST, no DST)',
                        'America/Winnipeg'    => 'Winnipeg (CT)',
                        'America/Toronto'     => 'Toronto (ET)',
                        'America/Halifax'     => 'Halifax (AT)',
                        'America/St_Johns'    => 'St. John\'s (NT)',
                        'America/Los_Angeles' => 'Los Angeles (PT)',
                        'America/Denver'      => 'Denver (MT)',
                        'America/Phoenix'     => 'Phoenix (MST, no DST)',
                        'America/Chicago'     => 'Chicago (CT)',
                        'America/New_York'    => 'New York (ET)',
                        'America/Anchorage'   => 'Anchorage (AKT)',
                        'Pacific/Honolulu'    => 'Honolulu (HST)',
                        'UTC'                 => 'UTC',
                    ] as $tz => $label): ?>
                        <option value="<?= e($tz) ?>" <?= $regional_timezone === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="form-label">Distance Unit Default</label>
                <div class="ff-segment" role="radiogroup" aria-label="Distance unit default">
                    <label>
                        <input type="radio" name="regional_distance_unit" value="km"    <?= $regional_distance_unit === 'km'    ? 'checked' : '' ?>>
                        <span>km</span>
                    </label>
                    <label>
                        <input type="radio" name="regional_distance_unit" value="miles" <?= $regional_distance_unit === 'miles' ? 'checked' : '' ?>>
                        <span>miles</span>
                    </label>
                </div>
                <p class="ff-helper">Default mileage unit suggested on new leases.</p>
            </div>

        </div>

        <div class="ff-card-actions">
            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['regional']">
                <span x-show="!saving['regional']">Save Regional</span>
                <span x-show="saving['regional']" x-cloak>Saving&hellip;</span>
            </button>
            <span class="ff-save-msg"
                  :style="msgStyle('regional')"
                  x-text="msg['regional'] || ''"
                  x-show="msg['regional']"></span>
        </div>

    </div>
</div>
</form>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 4 — PDF & Invoices                                     -->
<!-- ════════════════════════════════════════════════════════════ -->
<form @submit.prevent="saveCard('pdf', $event)" data-card="pdf">
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">PDF &amp; Invoices</div>
    <div class="card-body">

        <div class="form-group">
            <label class="form-label" for="pdf_invoice_footer_text">Invoice Footer Text</label>
            <textarea name="pdf_invoice_footer_text"
                      id="pdf_invoice_footer_text"
                      class="form-control"
                      maxlength="200"
                      rows="2"
                      oninput="document.getElementById('pdf-footer-readout').textContent = this.value.length;"><?= e($pdf_invoice_footer_text) ?></textarea>
            <p class="ff-helper"><span id="pdf-footer-readout"><?= e((string) mb_strlen($pdf_invoice_footer_text)) ?></span> / 200 characters.</p>
        </div>

        <div class="form-group" style="margin-top:16px;">
            <label class="form-label" style="display:flex;align-items:center;gap:10px;">
                <input type="checkbox" name="pdf_show_logo" value="1" <?= $pdf_show_logo === 1 ? 'checked' : '' ?>>
                Show Logo on PDFs
            </label>
            <p class="ff-helper">When off, generated PDFs render the company name in text only.</p>
        </div>

        <div class="form-group" style="margin-top:16px;">
            <label class="form-label" for="pdf_accent_color">PDF Accent Color</label>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <input type="color"
                       name="pdf_accent_color"
                       id="pdf_accent_color"
                       class="ff-color-picker"
                       value="<?= e($pdf_accent_color !== '' ? $pdf_accent_color : $brand_primary_color) ?>">
                <input type="text"
                       class="form-control"
                       style="max-width:160px;"
                       placeholder="Leave blank to inherit"
                       value="<?= e($pdf_accent_color) ?>"
                       oninput="document.getElementById('pdf_accent_color').value = this.value.match(/^#[0-9a-fA-F]{6}$/) ? this.value : document.getElementById('pdf_accent_color').value;"
                       onchange="document.querySelector('[name=pdf_accent_color]').value = this.value;">
            </div>
            <p class="ff-helper">Leave blank to inherit Brand Primary Color. Otherwise a 6-digit hex.</p>
        </div>

        <div class="ff-card-actions">
            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['pdf']">
                <span x-show="!saving['pdf']">Save PDF Settings</span>
                <span x-show="saving['pdf']" x-cloak>Saving&hellip;</span>
            </button>
            <span class="ff-save-msg"
                  :style="msgStyle('pdf')"
                  x-text="msg['pdf'] || ''"
                  x-show="msg['pdf']"></span>
        </div>

    </div>
</div>
</form>

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 5 — UI Behaviour                                       -->
<!-- ════════════════════════════════════════════════════════════ -->
<form @submit.prevent="saveCard('ui', $event)" data-card="ui">
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">UI Behaviour</div>
    <div class="card-body">

        <div class="ff-form-grid">

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="ui_sidebar_collapsed_default" value="1" <?= $ui_sidebar_collapsed_default === 1 ? 'checked' : '' ?>>
                    Sidebar Collapsed by Default
                </label>
                <p class="ff-helper">First-visit sidebar state; per-user toggles still apply.</p>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="defaults_rows_per_page">Default Rows Per Page</label>
                <select name="defaults_rows_per_page" id="defaults_rows_per_page" class="form-control">
                    <?php foreach ([10, 25, 50, 100] as $n): ?>
                        <option value="<?= $n ?>" <?= $defaults_rows_per_page === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="ui_session_timeout_minutes">Session Timeout</label>
                <select name="ui_session_timeout_minutes" id="ui_session_timeout_minutes" class="form-control">
                    <?php foreach ([
                        15  => '15 minutes',
                        30  => '30 minutes',
                        60  => '1 hour',
                        120 => '2 hours',
                        240 => '4 hours',
                        480 => '8 hours',
                    ] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $ui_session_timeout_minutes === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div class="ff-card-actions">
            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['ui']">
                <span x-show="!saving['ui']">Save UI Behaviour</span>
                <span x-show="saving['ui']" x-cloak>Saving&hellip;</span>
            </button>
            <span class="ff-save-msg"
                  :style="msgStyle('ui')"
                  x-text="msg['ui'] || ''"
                  x-show="msg['ui']"></span>
        </div>

    </div>
</div>
</form>

</div><!-- /#ff-design-tab -->

<script>
/**
 * FF_DesignTab — Alpine component backing the Design tab.
 * Each card has its own <form>; submit hooks here so we can POST
 * multipart/form-data via fetch() and surface inline save state.
 */
function FF_DesignTab(init) {
    return {
        csrf:           init.csrf,
        api:            init.api,
        primary:        init.primary || '#2596be',
        hover:          '',
        light:          '',
        logoPreview:    init.currentLogoUrl || '',
        logoMeta:       '',
        hasLogo:        !!init.hasLogo,
        faviconPreview: init.currentFavicon || '',
        faviconMeta:    '',
        hasFavicon:     !!init.hasFavicon,
        saving:         {},
        msg:            {},
        msgOk:          {},

        init() {
            this.recomputeDerived();
            this.applyPreviewToRoot(this.primary);
        },

        // ── Color math (mirrors api/v1/settings/brand.php) ────
        toRgb(hex) {
            const m = /^#([0-9a-f]{6})$/i.exec(hex);
            if (!m) return [0, 0, 0];
            const n = parseInt(m[1], 16);
            return [(n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff];
        },
        toHex(r, g, b) {
            const c = v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0');
            return '#' + c(r) + c(g) + c(b);
        },
        darken(hex, pct) {
            const [r, g, b] = this.toRgb(hex);
            const k = 1 - pct;
            return this.toHex(r * k, g * k, b * k);
        },
        mixWhite(hex, w) {
            const [r, g, b] = this.toRgb(hex);
            const c = 1 - w;
            return this.toHex(r * c + 255 * w, g * c + 255 * w, b * c + 255 * w);
        },
        recomputeDerived() {
            this.hover = this.darken(this.primary, 0.12);
            this.light = this.mixWhite(this.primary, 0.90);
        },

        // ── Preview ───────────────────────────────────────────
        applyPreview(hex) {
            if (!/^#[0-9a-f]{6}$/i.test(hex)) return;
            this.primary = hex.toLowerCase();
            this.recomputeDerived();
            this.applyPreviewToRoot(this.primary);
            // Sync the <input type="color"> if event came from a swatch
            const picker = document.querySelector('input[name="brand_primary_color"]');
            if (picker) picker.value = this.primary;
        },
        applyPreviewToRoot(hex) {
            // Live-update the document root so the rest of the admin
            // UI (sidebar, buttons) also reflects the new color while
            // the user is still picking — feels much more "real".
            document.documentElement.style.setProperty('--color-primary',       hex);
            document.documentElement.style.setProperty('--color-primary-hover', this.hover);
            document.documentElement.style.setProperty('--color-primary-light', this.light);
        },
        resetDefault() {
            this.applyPreview('#2596be');
        },

        // ── File previews ─────────────────────────────────────
        onLogoChange(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            this.logoMeta = `${f.name} · ${(f.size / 1024).toFixed(1)} KB`;
            const reader = new FileReader();
            reader.onload = ev => { this.logoPreview = ev.target.result; };
            reader.readAsDataURL(f);
        },
        onFaviconChange(e) {
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            this.faviconMeta = `${f.name} · ${(f.size / 1024).toFixed(1)} KB`;
            const reader = new FileReader();
            reader.onload = ev => { this.faviconPreview = ev.target.result; };
            reader.readAsDataURL(f);
        },

        // ── Remove buttons (post logo_remove / favicon_remove) ─
        async removeLogo() {
            if (!confirm('Remove the current logo?')) return;
            await this.postFields({ logo_remove: '1' }, 'brand-logo');
            this.logoPreview = '';
            this.hasLogo     = false;
        },
        async removeFavicon() {
            if (!confirm('Remove the current favicon?')) return;
            await this.postFields({ favicon_remove: '1' }, 'brand-favicon');
            this.faviconPreview = '';
            this.hasFavicon     = false;
        },

        // ── Save ──────────────────────────────────────────────
        async saveCard(cardKey, ev) {
            const form = ev.target;
            const fd   = new FormData(form);
            // Unchecked checkboxes don't appear in FormData — explicitly
            // submit a 0 so the API can distinguish "checkbox unchecked"
            // from "checkbox not present in this card's form".
            form.querySelectorAll('input[type=checkbox]').forEach(cb => {
                if (!cb.checked) fd.set(cb.name, '0');
            });
            await this.postForm(fd, cardKey);
        },

        async postForm(formData, cardKey) {
            this.saving[cardKey] = true;
            this.msg[cardKey]    = '';
            try {
                const r = await fetch(this.api, {
                    method:  'POST',
                    headers: { 'X-CSRF-Token': this.csrf, 'Accept': 'application/json' },
                    body:    formData,
                    credentials: 'same-origin',
                });
                const j = await r.json().catch(() => ({}));
                if (r.ok && j.success) {
                    this.msg[cardKey]   = 'Saved.';
                    this.msgOk[cardKey] = true;
                    if (cardKey === 'brand-logo')    this.hasLogo    = this.logoPreview !== '';
                    if (cardKey === 'brand-favicon') this.hasFavicon = this.faviconPreview !== '';
                } else {
                    const errs = j.error?.errors;
                    this.msg[cardKey]   = Array.isArray(errs) ? errs.join(' ') : (j.error?.message || 'Save failed.');
                    this.msgOk[cardKey] = false;
                }
            } catch (e) {
                this.msg[cardKey]   = 'Network error. Please try again.';
                this.msgOk[cardKey] = false;
            }
            this.saving[cardKey] = false;
            // Auto-clear success message after 4s
            setTimeout(() => { if (this.msgOk[cardKey]) this.msg[cardKey] = ''; }, 4000);
        },

        async postFields(obj, cardKey) {
            const fd = new FormData();
            Object.entries(obj).forEach(([k, v]) => fd.set(k, v));
            await this.postForm(fd, cardKey);
        },

        msgStyle(cardKey) {
            return this.msgOk[cardKey]
                ? 'color:var(--color-success);'
                : 'color:var(--color-danger);';
        },
    };
}
</script>
