<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Application Bootstrap
// Every entry point (pages, API, cron) includes this first.
// ============================================================

// Guard against double-inclusion [PASS-8:1]
if (defined('FF_LOADED')) return;
define('FF_LOADED', true);

// Project root — absolute path, no trailing slash
define('FF_ROOT', dirname(__DIR__));

// ============================================================
// .env Parser — must run before Composer autoload
// Custom implementation — no external dependency required.
// ============================================================
(function (): void {
    $envFile = FF_ROOT . '/.env';
    if (!is_readable($envFile)) return;

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip blank lines and comments
        if ($line === '' || $line[0] === '#') continue;

        // Must contain an = sign
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;

        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));

        // Skip invalid keys (must be alphanumeric + underscore)
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;

        // Strip surrounding single or double quotes
        if (
            strlen($val) >= 2 &&
            (($val[0] === '"'  && $val[-1] === '"')  ||
             ($val[0] === "'"  && $val[-1] === "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        // Respect environment variables already set by the SAPI
        // (e.g. shell wrapper, FastCGI pass-through). This is
        // standard 12-factor precedence: real env > .env file.
        // Lets dev wrappers override APP_URL etc. without editing .env.
        if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
            continue;
        }

        // Populate both $_ENV and putenv for maximum compatibility
        $_ENV[$key] = $val;
        putenv("{$key}={$val}");
    }
})();

// ============================================================
// env() — read a value from the parsed environment
// Converts string representations of bool/null to real types.
// ============================================================
if (!function_exists('env')) {
function env(string $key, mixed $default = null): mixed
{
    // Prefer $_ENV (set by parser above); fall back to process env
    $val = $_ENV[$key] ?? getenv($key);

    if ($val === false || $val === null) return $default;

    // Cast special string literals
    return match (strtolower((string) $val)) {
        'true',  '(true)'  => true,
        'false', '(false)' => false,
        'null',  '(null)'  => null,
        'empty', '(empty)' => '',
        default            => $val,
    };
}
}

// ============================================================
// Core application constants
// ============================================================

// D7 — base subpath is LOCKED. Do not change.
define('FF_BASE_PATH', '/fleetforge');

define('APP_ENV',          (string) env('APP_ENV',          'production'));
define('APP_URL',          rtrim((string) env('APP_URL', ''), '/'));
define('APP_TIMEZONE',     (string) env('APP_TIMEZONE',     'America/Vancouver'));
define('APP_SECRET',       (string) env('APP_SECRET',       ''));

define('FF_VERSION',       (string) env('FF_VERSION',       '1.0.0'));
define('FF_ASSET_VERSION', (string) env('FF_ASSET_VERSION', '1.0.1'));
define('FF_DEBUG',         (bool)   env('APP_DEBUG',        false));

// ============================================================
// Database constants — consumed by includes/db.php
// ============================================================
define('FF_DB_HOST', (string) env('DB_HOST',     '127.0.0.1'));
define('FF_DB_PORT', (string) env('DB_PORT',     '3306'));
define('FF_DB_NAME', (string) env('DB_DATABASE', 'fleetforge'));
define('FF_DB_USER', (string) env('DB_USERNAME', ''));
define('FF_DB_PASS', (string) env('DB_PASSWORD', ''));

// ============================================================
// Anthropic AI constants — consumed by lib/AI/ClaudeClient.php
// ============================================================
define('AI_ENABLED',           (bool)   env('AI_ENABLED',           false));
define('AI_ANTHROPIC_API_KEY', (string) env('AI_ANTHROPIC_API_KEY', ''));
define('AI_DAILY_TOKEN_LIMIT', (int)    env('AI_DAILY_TOKEN_LIMIT', 500000));

// ============================================================
// PHP runtime configuration
// ============================================================
date_default_timezone_set(APP_TIMEZONE);

if (FF_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

ini_set('log_errors', '1');

// ============================================================
// Session configuration
// ini_set() only — session_start() is called in includes/auth.php.
// Must be set before any session_start() call.
// ============================================================
$_sessionLifetime = (int) env('SESSION_LIFETIME', 28800); // 8 hours default

ini_set('session.gc_maxlifetime',  (string) $_sessionLifetime);
ini_set('session.cookie_lifetime', '0');           // Cookie expires when browser closes
ini_set('session.cookie_httponly', '1');           // JS cannot read the cookie
ini_set('session.cookie_samesite', 'Lax');         // CSRF mitigation
ini_set('session.use_strict_mode', '1');           // [PASS-4] reject unrecognised session IDs
ini_set('session.use_only_cookies', '1');          // Never pass session ID in URL
ini_set('session.cookie_secure', APP_ENV === 'production' ? '1' : '0');
ini_set('session.name', 'ff_session');

unset($_sessionLifetime);

// ============================================================
// Composer autoload (D17 — PSR-4: FleetForge\ → lib/)
// All FF_* constants must be defined before this line.
// ============================================================
$_autoload = FF_ROOT . '/vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}
unset($_autoload);

// Explicit loads — after vendor/autoload.php so FF_* constants
// and Composer PSR-4 classes are available. require_once is safe
// here because this entire block is inside the FF_LOADED guard.
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';

// ============================================================
// S-LEGAL-FOOTER-COMMERCIAL: load legal/company metadata into
// $GLOBALS['_ff_legal']. legal_config() in functions.php reads
// from here so every footer, login page, customer portal, and
// /legal/* page sees a single source of truth.
// ============================================================
$GLOBALS['_ff_legal'] = require FF_ROOT . '/config/legal.php';
