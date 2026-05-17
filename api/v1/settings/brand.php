<?php
declare(strict_types=1);

/**
 * api/v1/settings/brand.php
 *
 * Single POST endpoint that backs the Design tab in Settings.
 * Accepts a multipart/form-data submission containing any subset
 * of the design-tab fields — only fields actually present in the
 * request are written, so each card on the page can post its own
 * fields independently.
 *
 * Fields accepted (all optional — only saved when present):
 *   brand_primary_color           hex /^#[0-9a-fA-F]{6}$/
 *   logo_file                     PNG/SVG/JPG up to 2 MB
 *   logo_remove=1                 deletes the current logo
 *   favicon_file                  PNG/ICO up to 512 KB
 *   favicon_remove=1              deletes the current favicon
 *   defaults_theme                'dark' | 'light'
 *   defaults_density              'compact' | 'comfortable' | 'spacious'
 *   defaults_font_size            85..115 (step 5)
 *   defaults_rows_per_page        10 | 25 | 50 | 100
 *   regional_date_format          'M/D/YYYY' | 'D/M/YYYY' | 'YYYY-MM-DD'
 *   regional_time_format          '12h' | '24h'
 *   regional_currency_symbol      free text up to 8 chars
 *   regional_timezone             IANA timezone (allowlist)
 *   regional_distance_unit        'km' | 'miles'
 *   pdf_invoice_footer_text       up to 200 chars
 *   pdf_show_logo                 1 | 0
 *   pdf_accent_color              hex or empty
 *   ui_sidebar_collapsed_default  1 | 0
 *   ui_session_timeout_minutes    15 | 30 | 60 | 120 | 240 | 480
 *
 * Color derivation:
 *   primary_hover = primary RGB * 0.88 per channel (12% darker)
 *   primary_light = primary RGB * 0.10 + 255 * 0.90 (90% white mix)
 *
 * Security:
 *   - super_admin only (is_super_admin() → 403)
 *   - CSRF validated by api/bootstrap.php via X-CSRF-Token header
 *   - File uploads sanitized; extension derived from server-side MIME
 *   - Storage paths constructed from a fixed prefix + safe basename
 *
 * Decisions: D9 (StorageClient), Trap 5 (server-side MIME), Trap 7 (no path leak)
 * Session:   S-DESIGN-SETTINGS-FOOTER-LOGIN
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('POST');
require_auth_api();

// ── Permission gate ──────────────────────────────────────────
// is_super_admin() is the canonical check (matches the page-side
// gate on app/admin/settings/design.php and the tab visibility in
// app/admin/settings/index.php). Returning 403 keeps the error
// surface identical between page and API for this resource.
if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super-admin users may change brand settings.', 403);
}

// ============================================================
// Helpers — color math + value normalisation
// ============================================================

/**
 * Validate a 6-digit hex color string. Accepts upper/lower case.
 */
function ff_is_hex_color(string $hex): bool
{
    return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
}

/**
 * Split #RRGGBB into [r, g, b] ints (0-255).
 */
function ff_hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/**
 * Convert [r, g, b] ints back to #RRGGBB (lower-case).
 */
function ff_rgb_to_hex(int $r, int $g, int $b): string
{
    $r = max(0, min(255, $r));
    $g = max(0, min(255, $g));
    $b = max(0, min(255, $b));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Darken a hex color by reducing each channel by $pct percent.
 * 0.12 = 12% darker.
 */
function ff_darken(string $hex, float $pct): string
{
    [$r, $g, $b] = ff_hex_to_rgb($hex);
    $factor = 1.0 - $pct;
    return ff_rgb_to_hex((int) round($r * $factor), (int) round($g * $factor), (int) round($b * $factor));
}

/**
 * Mix a hex color with pure white at $whiteWeight percent white.
 * 0.90 means the result is 90% white + 10% original.
 */
function ff_mix_with_white(string $hex, float $whiteWeight): string
{
    [$r, $g, $b] = ff_hex_to_rgb($hex);
    $colorWeight = 1.0 - $whiteWeight;
    return ff_rgb_to_hex(
        (int) round($r * $colorWeight + 255 * $whiteWeight),
        (int) round($g * $colorWeight + 255 * $whiteWeight),
        (int) round($b * $colorWeight + 255 * $whiteWeight),
    );
}

/**
 * Persist one setting row by `key`. Skips silently if the row
 * does not exist — Task 1 seed creates every row up-front so a
 * missing row here means an out-of-sync schema, never a typo.
 */
function ff_settings_write(string $key, string $value): void
{
    db_execute(
        "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = ?",
        [$value, current_user_id(), $key]
    );
}

// ============================================================
// Collect + validate input
// ============================================================

$errors  = [];
$writes  = [];          // [key => value] — applied after validation
$deletes = [];          // storage keys to delete after writes commit

// ── Brand primary color (and its two derived siblings) ─────
if (isset($_POST['brand_primary_color']) && $_POST['brand_primary_color'] !== '') {
    $hex = trim((string) $_POST['brand_primary_color']);
    if (!ff_is_hex_color($hex)) {
        $errors[] = 'Brand primary color must be a 6-digit hex value, e.g. #2596be.';
    } else {
        $hex                              = strtolower($hex);
        $writes['brand.primary_color']    = $hex;
        $writes['brand.primary_hover']    = ff_darken($hex, 0.12);
        $writes['brand.primary_light']    = ff_mix_with_white($hex, 0.90);
    }
}

// ── Logo file (or removal) ─────────────────────────────────
// We accept PNG, SVG, JPG/JPEG. MIME is sniffed server-side
// (Trap 5 — never trust the client-supplied Content-Type).
$logoMaxBytes  = 2 * 1024 * 1024;
$logoMimeMap   = [
    'image/png'     => 'png',
    'image/svg+xml' => 'svg',
    'image/jpeg'    => 'jpg',
];
$currentLogo   = (string) (settings_get('brand.logo_path') ?? '');
$logoRemove    = !empty($_POST['logo_remove']);

if (!empty($_FILES['logo_file']) && ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['logo_file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Logo upload failed (error code ' . (int) $file['error'] . ').';
    } elseif ((int) $file['size'] > $logoMaxBytes) {
        $errors[] = 'Logo file exceeds the 2 MB limit.';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);

        if (!isset($logoMimeMap[$mime])) {
            $errors[] = 'Logo must be PNG, SVG, or JPG.';
        } else {
            $ext        = $logoMimeMap[$mime];
            $storageKey = 'branding/logo_' . time() . '.' . $ext;
            try {
                StorageClient::upload($file['tmp_name'], $storageKey);
                $writes['brand.logo_path'] = $storageKey;
                if ($currentLogo !== '' && $currentLogo !== $storageKey) {
                    $deletes[] = $currentLogo;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Logo upload failed: storage error.';
                error_log('[brand.php] logo upload failed: ' . $e->getMessage());
            }
        }
    }
} elseif ($logoRemove && $currentLogo !== '') {
    $writes['brand.logo_path'] = '';
    $deletes[]                 = $currentLogo;
}

// ── Favicon file (or removal) ──────────────────────────────
$faviconMaxBytes = 512 * 1024;
$faviconMimeMap  = [
    'image/png'           => 'png',
    'image/x-icon'        => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
];
$currentFavicon  = (string) (settings_get('brand.favicon_path') ?? '');
$faviconRemove   = !empty($_POST['favicon_remove']);

if (!empty($_FILES['favicon_file']) && ($_FILES['favicon_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['favicon_file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Favicon upload failed (error code ' . (int) $file['error'] . ').';
    } elseif ((int) $file['size'] > $faviconMaxBytes) {
        $errors[] = 'Favicon file exceeds the 512 KB limit.';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);

        if (!isset($faviconMimeMap[$mime])) {
            $errors[] = 'Favicon must be PNG or ICO.';
        } else {
            $ext        = $faviconMimeMap[$mime];
            $storageKey = 'branding/favicon_' . time() . '.' . $ext;
            try {
                StorageClient::upload($file['tmp_name'], $storageKey);
                $writes['brand.favicon_path'] = $storageKey;
                if ($currentFavicon !== '' && $currentFavicon !== $storageKey) {
                    $deletes[] = $currentFavicon;
                }
            } catch (\Throwable $e) {
                $errors[] = 'Favicon upload failed: storage error.';
                error_log('[brand.php] favicon upload failed: ' . $e->getMessage());
            }
        }
    }
} elseif ($faviconRemove && $currentFavicon !== '') {
    $writes['brand.favicon_path'] = '';
    $deletes[]                    = $currentFavicon;
}

// ── New-user defaults ───────────────────────────────────────
if (isset($_POST['defaults_theme'])) {
    $v = (string) $_POST['defaults_theme'];
    if (!in_array($v, ['dark', 'light'], true)) {
        $errors[] = 'Default theme must be dark or light.';
    } else {
        $writes['defaults.theme'] = $v;
    }
}
if (isset($_POST['defaults_density'])) {
    $v = (string) $_POST['defaults_density'];
    if (!in_array($v, ['compact', 'comfortable', 'spacious'], true)) {
        $errors[] = 'Default density must be compact, comfortable, or spacious.';
    } else {
        $writes['defaults.density'] = $v;
    }
}
if (isset($_POST['defaults_font_size'])) {
    $v = (int) $_POST['defaults_font_size'];
    if ($v < 85 || $v > 115 || $v % 5 !== 0) {
        $errors[] = 'Default font size must be 85-115 in steps of 5.';
    } else {
        $writes['defaults.font_size'] = (string) $v;
    }
}
if (isset($_POST['defaults_rows_per_page'])) {
    $v = (int) $_POST['defaults_rows_per_page'];
    if (!in_array($v, [10, 25, 50, 100], true)) {
        $errors[] = 'Default rows per page must be 10, 25, 50, or 100.';
    } else {
        $writes['defaults.rows_per_page'] = (string) $v;
    }
}

// ── Regional ────────────────────────────────────────────────
if (isset($_POST['regional_date_format'])) {
    $v = (string) $_POST['regional_date_format'];
    if (!in_array($v, ['M/D/YYYY', 'D/M/YYYY', 'YYYY-MM-DD'], true)) {
        $errors[] = 'Date format is not one of the allowed values.';
    } else {
        $writes['regional.date_format'] = $v;
    }
}
if (isset($_POST['regional_time_format'])) {
    $v = (string) $_POST['regional_time_format'];
    if (!in_array($v, ['12h', '24h'], true)) {
        $errors[] = 'Time format must be 12h or 24h.';
    } else {
        $writes['regional.time_format'] = $v;
    }
}
if (isset($_POST['regional_currency_symbol'])) {
    $v = trim((string) $_POST['regional_currency_symbol']);
    if ($v === '' || mb_strlen($v) > 8) {
        $errors[] = 'Currency symbol must be 1-8 characters.';
    } else {
        $writes['regional.currency_symbol'] = $v;
    }
}
if (isset($_POST['regional_timezone'])) {
    $v = (string) $_POST['regional_timezone'];
    // Allowlist: common Canadian + US timezones. Keeps the
    // selector tractable AND blocks arbitrary timezone strings
    // from being written into the settings table.
    $tzAllowed = [
        'America/Vancouver', 'America/Edmonton', 'America/Regina',
        'America/Winnipeg', 'America/Toronto', 'America/Halifax',
        'America/St_Johns',
        'America/Los_Angeles', 'America/Denver', 'America/Phoenix',
        'America/Chicago', 'America/New_York', 'America/Anchorage',
        'Pacific/Honolulu', 'UTC',
    ];
    if (!in_array($v, $tzAllowed, true)) {
        $errors[] = 'Timezone is not in the allowed list.';
    } else {
        $writes['regional.timezone'] = $v;
    }
}
if (isset($_POST['regional_distance_unit'])) {
    $v = (string) $_POST['regional_distance_unit'];
    if (!in_array($v, ['km', 'miles'], true)) {
        $errors[] = 'Distance unit must be km or miles.';
    } else {
        $writes['regional.distance_unit'] = $v;
    }
}

// ── PDF / Invoices ──────────────────────────────────────────
if (isset($_POST['pdf_invoice_footer_text'])) {
    $v = trim((string) $_POST['pdf_invoice_footer_text']);
    if (mb_strlen($v) > 200) {
        $errors[] = 'Invoice footer text must be 200 characters or fewer.';
    } else {
        $writes['pdf.invoice_footer_text'] = $v;
    }
}
if (isset($_POST['pdf_show_logo'])) {
    $writes['pdf.show_logo'] = ((int) $_POST['pdf_show_logo'] === 1) ? '1' : '0';
}
if (array_key_exists('pdf_accent_color', $_POST)) {
    $v = trim((string) $_POST['pdf_accent_color']);
    if ($v === '') {
        // Empty = fall back to brand.primary_color at render time
        $writes['pdf.accent_color'] = '';
    } elseif (!ff_is_hex_color($v)) {
        $errors[] = 'PDF accent color must be a 6-digit hex value or blank.';
    } else {
        $writes['pdf.accent_color'] = strtolower($v);
    }
}

// ── UI behaviour ────────────────────────────────────────────
if (isset($_POST['ui_sidebar_collapsed_default'])) {
    $writes['ui.sidebar_collapsed_default'] = ((int) $_POST['ui_sidebar_collapsed_default'] === 1) ? '1' : '0';
}
if (isset($_POST['ui_session_timeout_minutes'])) {
    $v = (int) $_POST['ui_session_timeout_minutes'];
    if (!in_array($v, [15, 30, 60, 120, 240, 480], true)) {
        $errors[] = 'Session timeout must be one of: 15, 30, 60, 120, 240, 480.';
    } else {
        $writes['ui.session_timeout_minutes'] = (string) $v;
    }
}

// ============================================================
// Persist or fail
// ============================================================

if (!empty($errors)) {
    json_error('VALIDATION_ERROR', 'Brand settings could not be saved.', 422, ['errors' => $errors]);
}

if (empty($writes)) {
    json_error('NO_OP', 'No brand fields were submitted.', 422);
}

db_transaction(function () use ($writes) {
    foreach ($writes as $key => $value) {
        ff_settings_write($key, $value);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'settings',
        'entity_type'  => 'settings_group',
        'entity_label' => 'brand',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'notes'        => 'brand settings updated: ' . implode(', ', array_keys($writes)),
    ]);
});

// ── Delete superseded files AFTER the transaction commits ──
// Doing this inside the transaction would risk an orphaned-file
// scenario if the COMMIT later failed. Running after means the
// new paths are already live; a delete failure here is a tidy-up
// issue, not a correctness one — we log it and move on.
foreach ($deletes as $oldKey) {
    try {
        StorageClient::delete($oldKey);
    } catch (\Throwable $e) {
        error_log('[brand.php] failed to delete old storage key ' . $oldKey . ': ' . $e->getMessage());
    }
}

json_success(['updated' => array_keys($writes)]);
