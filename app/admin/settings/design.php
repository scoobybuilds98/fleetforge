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
 * Layout: one .card block (Brand Identity) holding self-contained <form>s
 * that POST to api/v1/settings/brand.php as multipart/form-data with only
 * their own fields. The shared API treats every field as optional and only
 * updates rows that arrive in the request.
 *
 * Cards:
 *   0. Background       — palette tiles (config/backgrounds.php); click previews
 *                         on this page by swapping <html data-bg>, Save writes
 *                         brand.background (S-BACKGROUNDS)
 *   1. Brand Identity   — color picker + 6 swatches + live preview + logo + favicon
 *
 * Bug #23 — REMOVED cards: "New User Defaults" (defaults.theme/density/
 * font_size), "Regional" (regional.*), "PDF & Invoices" (pdf.*) and
 * "UI Behaviour" (ui.sidebar_collapsed_default, defaults.rows_per_page,
 * ui.session_timeout_minutes). A repo-wide grep found NO reader for any of
 * those 14 keys — the only code touching them was this page (to pre-fill)
 * and brand.php (to write) — so every "Save" silently did nothing (e.g. the
 * session timeout never changed the session lifetime). The settings rows and
 * brand.php's write handlers are left in place; re-add a card only together
 * with the code that honours it.
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
// S-BACKGROUNDS: the palette registry + the palette in use (validated).
$backgrounds         = ff_backgrounds();
$brand_background    = ff_background();

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

    /* S-BACKGROUNDS — palette tiles. Each tile draws its palette from the
       registry (inline colours are the palette's own data): the dark theme
       on the left half, the light theme on the right, the always-dark
       sidebar as the strip in both, the brand colour as the button. */
    #ff-design-tab .ff-bg-grid {
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(210px,1fr));
        gap:14px;
    }
    #ff-design-tab .ff-bg-tile {
        display:flex;
        flex-direction:column;
        gap:10px;
        padding:10px 10px 12px;
        border-radius:14px;
        border:1px solid var(--border-color);
        background:var(--bg-surface);
        font:inherit;
        color:inherit;
        text-align:left;
        cursor:pointer;
        transition:transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    #ff-design-tab .ff-bg-tile:hover { transform:translateY(-2px); border-color:var(--border-color-strong); }
    #ff-design-tab .ff-bg-tile.is-active {
        border-color:var(--color-primary);
        box-shadow:0 0 0 3px color-mix(in srgb, var(--color-primary) 24%, transparent);
    }
    #ff-design-tab .ff-bg-mini {
        display:grid;
        grid-template-columns:1fr 1fr;
        height:88px;
        border-radius:10px;
        overflow:hidden;
        border:1px solid var(--border-color);
    }
    #ff-design-tab .ff-bg-half { display:flex; gap:6px; padding:8px; min-width:0; }
    #ff-design-tab .ff-bg-side { flex:0 0 10px; border-radius:4px; }
    #ff-design-tab .ff-bg-cardm {
        flex:1;
        min-width:0;
        display:flex;
        flex-direction:column;
        gap:5px;
        padding:8px 7px;
        border-radius:7px;
        border:1px solid transparent;
    }
    #ff-design-tab .ff-bg-cardm i { display:block; height:5px; border-radius:3px; }
    #ff-design-tab .ff-bg-cardm i:first-child { width:72%; }
    #ff-design-tab .ff-bg-cardm i:nth-child(2) { width:92%; }
    #ff-design-tab .ff-bg-cardm b { display:block; width:42%; height:10px; margin-top:auto; border-radius:4px; background:var(--color-primary); }
    #ff-design-tab .ff-bg-meta { display:flex; flex-direction:column; gap:3px; padding:0 2px; }
    #ff-design-tab .ff-bg-name {
        display:flex;
        align-items:center;
        gap:6px;
        flex-wrap:wrap;
        font-size:0.875rem;
        font-weight:650;
        color:var(--text-primary);
    }
    #ff-design-tab .ff-bg-chip {
        padding:2px 7px;
        border-radius:999px;
        font-size:0.625rem;
        font-weight:700;
        letter-spacing:0.08em;
        text-transform:uppercase;
        color:var(--color-primary);
        background:color-mix(in srgb, var(--color-primary) 14%, transparent);
    }
    #ff-design-tab .ff-bg-chip--used { color:var(--text-secondary); background:var(--bg-surface-2); }
    #ff-design-tab .ff-bg-blurb { font-size:0.75rem; line-height:1.4; color:var(--text-secondary); }
</style>

<div id="ff-design-tab" x-data="FF_DesignTab(<?= e(json_encode([
    'csrf'             => $_csrfToken ?? ($_SESSION['csrf_token'] ?? ''),
    'api'              => $brandApi,
    'primary'          => $brand_primary_color,
    'currentLogoUrl'   => $logoUrl,
    'currentFavicon'   => $faviconUrl,
    'hasLogo'          => $brand_logo_path !== '',
    'hasFavicon'       => $brand_favicon_path !== '',
    'background'       => $brand_background,
], JSON_UNESCAPED_SLASHES)) ?>)">

<!-- ════════════════════════════════════════════════════════════ -->
<!-- CARD 0 — Background (S-BACKGROUNDS)                         -->
<!-- ════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;">Background</div>
    <div class="card-body">
        <form @submit.prevent="saveBackground()" data-card="brand-background">
            <p class="ff-helper" style="margin:0 0 14px;font-size:0.8125rem;">
                The colour of every page, card and the sidebar, for everyone, in both dark and light mode.
                Click a palette to try it on this page. Nothing changes for anyone else until you save.
            </p>
            <div class="ff-bg-grid" role="radiogroup" aria-label="Background palette">
                <?php foreach ($backgrounds as $_bk => $_bg):
                    [$_dp, $_dc, $_dc2, $_dt] = $_bg['dark'];
                    [$_lp, $_lc, $_lc2, $_lt] = $_bg['light']; ?>
                <button type="button" class="ff-bg-tile" role="radio"
                        data-bg-key="<?= e($_bk) ?>"
                        aria-label="<?= e($_bg['label'] . ' — ' . $_bg['blurb']) ?>"
                        :class="{ 'is-active': bg === '<?= e($_bk) ?>' }"
                        :aria-checked="bg === '<?= e($_bk) ?>' ? 'true' : 'false'"
                        @click="previewBackground('<?= e($_bk) ?>')">
                    <span class="ff-bg-mini" aria-hidden="true">
                        <span class="ff-bg-half" style="background:<?= e($_dp) ?>;">
                            <span class="ff-bg-side" style="background:<?= e($_dc) ?>;"></span>
                            <span class="ff-bg-cardm" style="background:<?= e($_dc) ?>;border-color:<?= e($_dc2) ?>;">
                                <i style="background:<?= e($_dt) ?>;opacity:.85;"></i><i style="background:<?= e($_dc2) ?>;"></i><b></b>
                            </span>
                        </span>
                        <span class="ff-bg-half" style="background:<?= e($_lp) ?>;">
                            <span class="ff-bg-side" style="background:<?= e($_dp) ?>;"></span>
                            <span class="ff-bg-cardm" style="background:<?= e($_lc) ?>;border-color:<?= e($_lc2) ?>;">
                                <i style="background:<?= e($_lt) ?>;opacity:.85;"></i><i style="background:<?= e($_lc2) ?>;"></i><b></b>
                            </span>
                        </span>
                    </span>
                    <span class="ff-bg-meta">
                        <span class="ff-bg-name">
                            <?= e($_bg['label']) ?>
                            <?php if ($_bk === FF_BACKGROUND_DEFAULT): ?><span class="ff-bg-chip">Recommended</span><?php endif; ?>
                            <span class="ff-bg-chip ff-bg-chip--used" x-show="savedBg === '<?= e($_bk) ?>'" x-cloak>In use</span>
                        </span>
                        <span class="ff-bg-blurb"><?= e($_bg['blurb']) ?></span>
                    </span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="ff-card-actions">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="saving['brand-background'] || bg === savedBg">
                    <span x-show="!saving['brand-background']">Save Background</span>
                    <span x-show="saving['brand-background']" x-cloak>Saving&hellip;</span>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" x-show="bg !== savedBg" x-cloak @click="previewBackground(savedBg)">Undo preview</button>
                <span class="ff-save-msg"
                      :style="msgStyle('brand-background')"
                      x-text="msg['brand-background'] || ''"
                      x-show="msg['brand-background']"></span>
            </div>
        </form>
    </div>
</div>

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

<!-- Bug #23: the "New User Defaults", "Regional", "PDF & Invoices" and
     "UI Behaviour" cards were removed — none of their settings had a reader,
     so saving them changed nothing. See the file docblock. -->

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
        bg:             init.background || 'midnight',
        savedBg:        init.background || 'midnight',
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

        // ── Background palette (S-BACKGROUNDS) ────────────────
        // Preview = swap <html data-bg>; backgrounds.css re-colours the
        // whole page instantly. Saving writes brand.background, which
        // every page shell reads through ff_background().
        previewBackground(key) {
            this.bg = key;
            document.documentElement.setAttribute('data-bg', key);
        },
        async saveBackground() {
            await this.postFields({ brand_background: this.bg }, 'brand-background');
            if (this.msgOk['brand-background']) this.savedBg = this.bg;
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
