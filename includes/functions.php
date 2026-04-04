<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Global Helper Functions
//
// Loaded automatically via Composer `files` autoload.
// config/app.php and includes/db.php must be loaded first.
//
// ALL HTML output must pass through e() — no exceptions.
// ALL monetary math must use bcmath — never float (D16).
// ============================================================

// ============================================================
// e() — HTML-escape a value for safe output
//
// Use on EVERY value printed into HTML, including attributes.
//   echo e($customer['name']);
//   echo '<input value="' . e($val) . '">';
// ============================================================
if (!function_exists('e')) {
function e(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
}

// ============================================================
// base_url() — build an app URL (includes FF_BASE_PATH prefix)
//
// APP_URL is the origin only (e.g. https://yourdomain.com).
// FF_BASE_PATH ('/fleetforge') is appended automatically.
//   base_url('auth/login')
//   → https://yourdomain.com/fleetforge/auth/login
// ============================================================
if (!function_exists('base_url')) {
function base_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = rtrim(APP_URL, '/') . FF_BASE_PATH;
    return $path === '' ? $base : $base . '/' . $path;
}
}

// ============================================================
// asset_url() — build a URL for a static file in public/
//
// Does NOT include FF_BASE_PATH — assets are served directly
// from the docroot (public/) with no subdirectory prefix.
//   asset_url('assets/css/app.css')
//   → https://yourdomain.com/assets/css/app.css
// ============================================================
if (!function_exists('asset_url')) {
function asset_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return $path === '' ? rtrim(APP_URL, '/') : rtrim(APP_URL, '/') . '/' . $path;
}
}

// ============================================================
// SANITISATION HELPERS
// ============================================================

// clean_string() — trim, strip HTML tags, truncate
// Returns null if the result is an empty string.
if (!function_exists('clean_string')) {
function clean_string(?string $val, int $maxLen = 255): ?string
{
    if ($val === null) return null;

    $val = trim(strip_tags($val));

    // FIX #41: strip NUL bytes and non-printable control characters that can
    // cause truncated DB writes, header injection, or log poisoning.
    // Keeps tab (0x09), LF (0x0A), CR (0x0D) as they are valid in text bodies.
    $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $val) ?? $val;

    if ($val === '') return null;

    // Truncate to maxLen (multibyte-safe)
    if (mb_strlen($val) > $maxLen) {
        $val = mb_substr($val, 0, $maxLen);
    }

    return $val;
}
}

// clean_int() — return a validated integer or null
// Rejects floats (both PHP float type and strings containing '.').
// Accepts: 0, positive ints, negative ints, numeric strings like "42".
if (!function_exists('clean_int')) {
function clean_int(mixed $val): ?int
{
    if ($val === null || $val === '') return null;

    // Reject PHP float types outright (e.g. 25.0)
    if (is_float($val)) return null;

    // Normalise to string for pattern check
    $str = (string) $val;
    $str = trim($str);

    // Must be digits only, optional leading minus — no decimal point allowed
    if (!preg_match('/^-?\d+$/', $str)) return null;

    return (int) $str;
}
}

// clean_positive_int() — return a validated integer > 0, or null
// Rejects zero, negatives, floats and non-numeric strings.
// Use for quantities, counts, IDs, measurements that must be positive.
if (!function_exists('clean_positive_int')) {
function clean_positive_int(mixed $val): ?int
{
    $int = clean_int($val);
    return ($int !== null && $int > 0) ? $int : null;
}
}

// clean_non_negative_int() — return a validated integer >= 0, or null
// Rejects negatives, floats and non-numeric strings.
// Use for odometer/mileage readings, counts that may be zero.
if (!function_exists('clean_non_negative_int')) {
function clean_non_negative_int(mixed $val): ?int
{
    $int = clean_int($val);
    return ($int !== null && $int >= 0) ? $int : null;
}
}

// clean_decimal() — return a validated decimal string for bcmath, or null
// Rejects formatted values: "$1,234.56", "1,234", "1 234", etc.
// Accepts: "1234.56", "1234", "-1234.56", "0", "0.00"
if (!function_exists('clean_decimal')) {
function clean_decimal(mixed $val): ?string
{
    if ($val === null || $val === '') return null;

    $str = trim((string) $val);

    if ($str === '') return null;

    // Only allow: optional minus, digits, optional single decimal point + digits
    if (!preg_match('/^-?\d+(\.\d+)?$/', $str)) return null;

    return $str;
}
}

// clean_positive_decimal() — return a validated decimal string > 0, or null
// Rejects zero, negatives, and non-numeric strings.
// Use for rates, costs, prices that must be strictly positive.
if (!function_exists('clean_positive_decimal')) {
function clean_positive_decimal(mixed $val): ?string
{
    $d = clean_decimal($val);
    if ($d === null) return null;
    // Use bccomp to compare — avoids float precision issues (D16)
    return bccomp($d, '0', 6) > 0 ? $d : null;
}
}

// clean_non_negative_decimal() — return a validated decimal string >= 0, or null
// Rejects negatives and non-numeric strings.
// Use for costs and amounts that may legitimately be zero.
if (!function_exists('clean_non_negative_decimal')) {
function clean_non_negative_decimal(mixed $val): ?string
{
    $d = clean_decimal($val);
    if ($d === null) return null;
    return bccomp($d, '0', 6) >= 0 ? $d : null;
}
}

// clean_time() — return a validated HH:MM or HH:MM:SS time string, or null
// Validates that the value is a real time (00:00–23:59).
if (!function_exists('clean_time')) {
function clean_time(?string $val): ?string
{
    if ($val === null || $val === '') return null;
    $val = trim($val);
    // Match HH:MM or HH:MM:SS
    if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $val, $m)) return null;
    $h = (int) $m[1]; $min = (int) $m[2]; $sec = isset($m[3]) ? (int) $m[3] : 0;
    if ($h > 23 || $min > 59 || $sec > 59) return null;
    return $val;
}
}

// clean_date() — return a validated Y-m-d date string, or null
// Rejects invalid calendar dates (e.g. Feb 30).
if (!function_exists('clean_date')) {
function clean_date(?string $val): ?string
{
    if ($val === null || $val === '') return null;

    $val = trim($val);

    // Must match YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return null;

    // Validate it is a real calendar date
    [$year, $month, $day] = explode('-', $val);
    if (!checkdate((int) $month, (int) $day, (int) $year)) return null;

    return $val;
}
}

// clean_email() — return a valid, normalised email address or null
if (!function_exists('clean_email')) {
function clean_email(?string $val): ?string
{
    if ($val === null || $val === '') return null;

    $val = strtolower(trim($val));

    $filtered = filter_var($val, FILTER_VALIDATE_EMAIL);
    return $filtered === false ? null : $filtered;
}
}

// ============================================================
// FORMATTING HELPERS
// (display only — these do NOT do math, so number_format is safe here)
// ============================================================

// format_currency() — format a monetary amount for display
// Returns an em dash if the value is null, empty, or non-numeric.
//   format_currency('1234.56')        → '$1,234.56'
//   format_currency('1234.56', 'US$') → 'US$1,234.56'
//   format_currency(null)             → '—'
if (!function_exists('format_currency')) {
function format_currency(mixed $amount, string $symbol = '$'): string
{
    if ($amount === null || $amount === '') return '—';

    $str = trim((string) $amount);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $str)) return '—';

    $negative  = str_starts_with($str, '-');
    $absolute  = $negative ? substr($str, 1) : $str;
    $formatted = $symbol . number_format((float) $absolute, 2);

    return $negative ? '-' . $formatted : $formatted;
}
}

// format_datetime() — convert a UTC datetime string to the company timezone
// Default format: 'M j, Y g:i A'  e.g. 'Mar 17, 2025 9:30 AM'
// Returns em dash if value is null or empty.
if (!function_exists('format_datetime')) {
function format_datetime(mixed $val, string $format = 'M j, Y g:i A'): string
{
    if ($val === null || $val === '') return '—';

    try {
        $dt = new DateTime((string) $val, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(APP_TIMEZONE));
        return $dt->format($format);
    } catch (Throwable) {
        return '—';
    }
}
}

// format_date() — format a Y-m-d date string for display
// Returns 'Mar 17, 2025' format.
// Returns em dash if value is null or empty.
if (!function_exists('format_date')) {
function format_date(mixed $val): string
{
    if ($val === null || $val === '') return '—';

    try {
        $dt = new DateTime((string) $val);
        return $dt->format('M j, Y');
    } catch (Throwable) {
        return '—';
    }
}
}

// ============================================================
// SETTINGS
// ============================================================

// settings_get() — read a value from the settings table
// Trap 1 [PASS-8:T1]: settings table may not exist in early sessions.
// Always catches Throwable and returns $default — never throws.
if (!function_exists('settings_get')) {
function settings_get(string $key, mixed $default = null): mixed
{
    try {
        // DB schema: columns are `key` and `value` (reserved words — must be backtick-quoted)
        $row = db_row(
            "SELECT `value` FROM settings WHERE `key` = ?",
            [$key]
        );
        return $row !== null ? $row['value'] : $default;
    } catch (Throwable) {
        // Table doesn't exist yet — silent fallback
        return $default;
    }
}
}

// ============================================================
// ID GENERATION
// ============================================================

// generate_id() — generate a sequential, gap-free ID string
//
// Format: {PREFIX}-{YEAR}-{NNNNN}  e.g. INV-2025-00847
//
// IMPORTANT: This function does NOT manage its own transaction.
// It MUST be called from within a db_transaction() so that the
// FOR UPDATE lock on the settings row is effective (Trap 9 / D15).
//
// Example:
//   db_transaction(function() use (&$invoiceNumber) {
//       $invoiceNumber = generate_id('INV', date('Y'));
//       db_insert('invoices', ['invoice_number' => $invoiceNumber, ...]);
//   });
if (!function_exists('generate_id')) {
function generate_id(string $prefix, string $year): string
{
    $key  = strtolower($prefix) . '.next_number.' . $year;

    // Lock the settings row for this prefix+year (must be inside transaction)
    // DB schema: columns are `key` and `value` — reserved words, backtick-quoted throughout.
    // db_insert() is NOT used here because db_sanitize_column() does not add backticks,
    // and `key`/`value` are MySQL reserved words that require them. Raw SQL used instead.
    $row  = db_row(
        "SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE",
        [$key]
    );

    $next   = $row !== null ? (int) $row['value'] : 1;
    $result = sprintf('%s-%s-%05d', strtoupper($prefix), $year, $next);

    if ($row !== null) {
        db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$next + 1, $key]
        );
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, group_name) VALUES (?, ?, ?)",
            [$key, (string) ($next + 1), strtolower($prefix)]
        );
    }

    return $result;
}
}

// generate_random_code() — cryptographically random A-Z0-9 code
//
// Excludes confusable characters: 0 (zero), 1 (one), I, L, O
// which look similar to each other in many fonts.
// Default length 6 gives 30^6 = 729,000,000 combinations.
//
// Used for: lease contract numbers, invite tokens (short codes only)
if (!function_exists('generate_random_code')) {
function generate_random_code(int $length = 6): string
{
    // Alphanumeric minus visually ambiguous characters
    $charset = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max     = strlen($charset) - 1;
    $code    = '';

    // random_bytes() is cryptographically secure
    $bytes = random_bytes($length * 2); // over-provision to avoid modulo bias
    $i     = 0;
    $b     = 0;

    while ($i < $length) {
        $byte = ord($bytes[$b++]);
        // Rejection sampling — discard values that would cause modulo bias
        if ($byte < (256 - (256 % ($max + 1)))) {
            $code .= $charset[$byte % ($max + 1)];
            $i++;
        }
        // If we exhaust the pre-generated bytes, get more
        if ($b >= strlen($bytes)) {
            $bytes = random_bytes($length * 2);
            $b     = 0;
        }
    }

    return $code;
}
}

// ============================================================
// BCMATH HELPERS — D16 (never use float for monetary math)
// ============================================================

// bcround() — round a bcmath decimal string to $scale decimal places
// Uses "round half away from zero" (standard rounding for financial math).
// For positive numbers, adds 0.005 and truncates.
// For negative numbers, subtracts 0.005 and truncates — this correctly
// rounds -2.345 to -2.35 (away from zero), not -2.34.
//
//   bcround('2.345',  2) → '2.35'
//   bcround('2.344',  2) → '2.34'
//   bcround('-2.345', 2) → '-2.35'  (away from zero — FIX #40)
//   bcround('-2.344', 2) → '-2.34'
if (!function_exists('bcround')) {
function bcround(string $val, int $scale = 2): string
{
    $half = '0.' . str_repeat('0', $scale) . '5';
    // WHY: for negative numbers we must subtract (not add) the half value so
    // that -2.345 rounds to -2.35 (away from zero) rather than -2.34 (toward zero).
    if (bccomp($val, '0', $scale + 1) < 0) {
        return bcadd(bcsub($val, $half, $scale + 1), '0', $scale);
    }
    return bcsub(bcadd($val, $half, $scale + 1), '0', $scale);
}
}

// ============================================================
// CSRF HELPERS — S010 carry-over from S001 Known Issue #3
// ============================================================

// generate_csrf_token() — return the session CSRF token,
// creating it if it does not yet exist.
// Token is 64 hex characters (32 random bytes).
// Session must already be started before calling this.
if (!function_exists('generate_csrf_token')) {
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
}

// verify_csrf_token() — compare a submitted token against the
// session token using constant-time comparison.
// Returns false if: no session token, empty submitted token,
// or mismatch.
if (!function_exists('verify_csrf_token')) {
function verify_csrf_token(?string $submitted): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if ($sessionToken === '' || $submitted === null || $submitted === '') {
        return false;
    }

    return hash_equals($sessionToken, $submitted);
}
}

// ============================================================
// MILEAGE HELPERS — D34 (km default for Canadian fleets)
// ============================================================

// format_mileage() — format an odometer reading for display
//
// Appends unit label: 'km' or 'mi' (short form for miles).
// Returns em dash if value is null or empty.
//
// Examples:
//   format_mileage(84200, 'km')    → '84,200 km'
//   format_mileage(84200, 'miles') → '84,200 mi'
//   format_mileage(null, 'km')     → '—'
if (!function_exists('format_mileage')) {
function format_mileage(mixed $distance, string $unit = 'km'): string
{
    if ($distance === null || $distance === '') return '—';

    $formatted = number_format((int) $distance);
    // D34: 'miles' unit displays as 'mi' (short label matches industry convention)
    $label = ($unit === 'miles') ? 'mi' : 'km';

    return $formatted . ' ' . $label;
}
}
