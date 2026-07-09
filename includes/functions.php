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
// ff_favicon_tags() — the SINGLE <link rel="icon"> for every <head>
//
// Returns exactly ONE icon link, never two: the custom favicon uploaded
// via Settings → Design (brand.favicon_path) when one is set, otherwise
// the bundled SVG default. This is the fix for "I saved a favicon and it
// didn't update": the admin <head> used to emit the static SVG AND the
// uploaded PNG, and Chrome/Firefox prefer an SVG icon when one is present
// regardless of DOM order — so the upload was declared but never used.
// One link removes the ambiguity (S-FAVICON-SINGLE-LINK).
//
// Defensive: any settings/storage failure falls back to the SVG default,
// so this never breaks a <head> (safe to call on error pages too). The
// default carries ?v=FF_ASSET_VERSION so a changed bundled icon isn't
// served stale from cache; the custom URL is already unique per upload.
// ============================================================
if (!function_exists('ff_favicon_tags')) {
function ff_favicon_tags(): string
{
    $ver = defined('FF_ASSET_VERSION') ? FF_ASSET_VERSION : '1';
    $svgDefault = '<link rel="icon" type="image/svg+xml" href="'
        . e(asset_url('assets/icons/favicon.svg')) . '?v=' . e((string) $ver) . '">';

    try {
        $key = (string) (settings_get('brand.favicon_path') ?? '');
        if ($key === '') {
            return $svgDefault;
        }
        // Upload only accepts PNG/ICO (api/v1/settings/brand.php); match the
        // <link type> to the stored key's extension so browsers get the hint
        // even when S3 serves the object as application/octet-stream.
        $ext  = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $type = $ext === 'ico' ? 'image/x-icon' : 'image/png';
        $href = \FleetForge\Storage\StorageClient::url($key, 86400);
        return '<link rel="icon" type="' . e($type) . '" href="' . e($href) . '">';
    } catch (\Throwable $e) {
        // Storage/driver/DB hiccup — never break the head; show the default.
        return $svgDefault;
    }
}
}

// ============================================================
// SANITISATION HELPERS
// ============================================================

// clean_string() — trim, strip HTML tags, truncate
// Returns null if the result is an empty string.
if (!function_exists('clean_string')) {
function clean_string(mixed $val, int $maxLen = 255): ?string
{
    // S-AUDIT-LIFECYCLE-1 #23: accept mixed — a JSON number/bool coerces, a
    // JSON array/object returns null (→ the caller's normal "missing/invalid"
    // path) instead of a strict_types TypeError 500. Mirrors clean_int/
    // clean_decimal's ingestion posture.
    if ($val === null) return null;
    if (!is_string($val)) {
        if (is_int($val) || is_float($val) || is_bool($val)) {
            $val = (string) $val;
        } else {
            return null;
        }
    }

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
function clean_time(mixed $val): ?string
{
    // S-AUDIT-LIFECYCLE-1 #23: mixed ingestion — non-string JSON → null, not
    // a strict_types TypeError 500 (see clean_string).
    if ($val === null || $val === '' || !is_string($val)) return null;
    $val = trim($val);
    // Match HH:MM or HH:MM:SS
    if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $val, $m)) return null;
    $h = (int) $m[1]; $min = (int) $m[2]; $sec = isset($m[3]) ? (int) $m[3] : 0;
    if ($h > 23 || $min > 59 || $sec > 59) return null;
    return $val;
}
}

// ff_mileage_line_display() — S-MILEAGE-RATE-CONVERT-FIX
// The billing engine computes mileage canonically in KM (distanceKm ×
// mileage_rate_km). For a MILES lease the customer-facing line should read
// "{miles} miles × ${per-mile}/mile", not km. This renders the display tuple in
// the lease's unit; the dollar AMOUNT is the caller's (unit-agnostic — after the
// rate fix, km×rate_km == miles×rate_mile within rounding), so this only relabels.
// Returns ['distance','rate','unit','rate_unit'] where unit is 'km'|'miles' (the
// quantity label) and rate_unit is the singular 'km'|'mile' (for the "/unit" suffix).
if (!function_exists('ff_mileage_line_display')) {
function ff_mileage_line_display(array $lease, string $distanceKm, string $rateKm): array
{
    $isMiles = (($lease['mileage_unit'] ?? 'km') === 'miles');
    if (!$isMiles) {
        return ['distance' => $distanceKm, 'rate' => $rateKm, 'unit' => 'km', 'rate_unit' => 'km'];
    }
    // km → miles for the distance (a distance scales directly).
    $kmToMiles = (string) ($lease['km_to_miles_conversion'] ?? '0.621371');
    if (bccomp($kmToMiles, '0', 6) <= 0) $kmToMiles = '0.621371';
    $distanceMiles = bcround(bcmul($distanceKm, $kmToMiles, 8), 2);
    // Per-mile rate: the stored primary mileage_rate IS per-mile for a miles lease
    // (exact); fall back to deriving it from rate_km if absent/zero.
    $rateMile = (isset($lease['mileage_rate']) && $lease['mileage_rate'] !== null
                 && bccomp((string) $lease['mileage_rate'], '0', 4) > 0)
        ? (string) $lease['mileage_rate']
        : bcround(bcmul($rateKm, (string) ($lease['miles_to_km_conversion'] ?? '1.609344'), 8), 4);
    return ['distance' => $distanceMiles, 'rate' => $rateMile, 'unit' => 'miles', 'rate_unit' => 'mile'];
}
}

// ff_expand_capped_invoice_lines() — S-REFUND-ON-INVOICE display expansion.
//
// When a final invoice's credit lines exceeded its charges, the engine caps
// the credit lines so the subtotal floors at $0 and routes the excess to a
// customer account-credit credit_note (see InvoiceGenerator's overflow cap /
// S-CAP-MULTILINE). The STORED lines are therefore the capped remainders —
// which read as nonsense to a human ("mileage credit $0.00").
//
// This expands the stored lines back to their ORIGINAL amounts for DISPLAY
// (each capped line's detail_lines records original_credit_amount) and
// appends one synthetic balancing row — "Converted to customer account
// credit — CN-xxxx" — for the total routed amount, so the visible lines
// still sum exactly to the stored subtotal. Pure presentation: nothing is
// written; totals/tax always come from the invoice row.
//
// Call AFTER loading lines, BEFORE any redaction pass (redaction then zeroes
// the expanded amounts for restricted roles like any other line).
if (!function_exists('ff_expand_capped_invoice_lines')) {
function ff_expand_capped_invoice_lines(array $lineItems, int $invoiceId): array
{
    if (!$lineItems) return $lineItems;

    $routed = '0.00';
    foreach ($lineItems as &$li) {
        $detail = null;
        if (isset($li['_detail']) && is_array($li['_detail'])) {
            $detail = $li['_detail'];
        } elseif (!empty($li['detail_lines'])) {
            $detail = json_decode((string) $li['detail_lines'], true);
        }
        if (is_array($detail) && !empty($detail['cap_applied'])
            && isset($detail['original_credit_amount']) && !empty($li['is_credit'])) {
            $orig    = (string) $detail['original_credit_amount'];
            $removed = bcsub($orig, (string) $li['amount'], 2);
            if (bccomp($removed, '0', 2) > 0) {
                $routed = bcadd($routed, $removed, 2);
                $li['amount'] = $orig;
                // Keep qty × price == amount readable (capped lines are qty=1).
                if (bccomp((string) ($li['quantity'] ?? '1'), '1', 4) === 0) {
                    $li['unit_price'] = $orig;
                }
            }
        }
    }
    unset($li);

    if (bccomp($routed, '0', 2) > 0) {
        // Name the live overflow CN when there is one (regenerates void +
        // re-issue, so newest live wins); degrade to a generic label otherwise.
        $cn = db_row(
            "SELECT credit_note_number FROM credit_notes
              WHERE source_invoice_id = ?
                AND source IN ('mileage_overpayment','base_rental_reconciliation_overflow')
                AND deleted_at IS NULL AND voided_at IS NULL AND status <> 'void'
              ORDER BY id DESC LIMIT 1",
            [$invoiceId]
        );
        $label = $cn
            ? "Refund converted to customer account credit — {$cn['credit_note_number']} (apply to any invoice or refund)"
            : 'Refund converted to customer account credit';

        // Synthetic display row: charge-side (+) so the expanded lines still
        // sum to the stored subtotal. Inherit the key shape of a real row so
        // renderers touching optional columns never hit undefined indexes.
        $template = array_fill_keys(array_keys($lineItems[0]), null);
        $lineItems[] = array_merge($template, [
            'id'          => 0,
            'invoice_id'  => $invoiceId,
            'item_type'   => 'account_credit_issued',
            'description' => $label,
            'quantity'    => '1.0000',
            'unit'        => null,
            'unit_price'  => $routed,
            'amount'      => $routed,
            'is_credit'   => 0,
            'taxable'     => 0,
            '_detail'     => null,
            '_synthetic'  => true,
        ]);
    }

    return $lineItems;
}
}

// ff_reverse_precharge_on_invoice_removal() — S-AUDIT-LIFECYCLE-1 (closes F33,
// widened to ALL removal sites). When an invoice is voided or soft-deleted its
// precharge side effects must be reversed or the lease's money state corrupts
// silently:
//   (a) drawdown — createFromLease decremented leases.precharge_balance when it
//       emitted the invoice's mileage_drawdown_credit line(s); the credit line
//       dies with the invoice but the balance stayed decremented → the next
//       drawdown AND the close-time refund are both short (customer pays twice).
//       Restore exactly the invoice's own drawdown-credit sum (never recomputed).
//   (b) precharge stamp — voiding the SENT Invoice 1 that carried the
//       mileage_precharge charge left precharge_invoiced_at stamped, so the
//       precharge could never be re-billed while drawdowns kept spending the
//       activation-initialized balance the customer never paid. Un-stamp it so
//       the D138 emit gate re-opens on the next invoice.
// MUST be called INSIDE the removal transaction, before the invoice's line
// items are hard-deleted (void/soft-delete keep them). Safe no-op for
// non-precharge leases and for invoices carrying neither line type.
if (!function_exists('ff_reverse_precharge_on_invoice_removal')) {
function ff_reverse_precharge_on_invoice_removal(int $invoiceId, ?int $userId, string $userName, string $context): array
{
    $inv = db_row(
        "SELECT i.lease_id, i.invoice_number,
                COALESCE(SUM(CASE WHEN li.item_type = 'mileage_drawdown_credit' THEN li.amount ELSE 0 END), '0.00') AS drawdown_sum,
                MAX(CASE WHEN li.item_type = 'mileage_precharge' THEN 1 ELSE 0 END) AS has_precharge_line
           FROM invoices i
           LEFT JOIN invoice_line_items li ON li.invoice_id = i.id
          WHERE i.id = ?
          GROUP BY i.id",
        [$invoiceId]
    );
    $result = ['drawdown_restored' => '0.00', 'stamp_cleared' => false];
    if (!$inv || !$inv['lease_id']) {
        return $result;
    }
    $leaseId     = (int) $inv['lease_id'];
    $drawdownSum = (string) $inv['drawdown_sum'];

    // Lock the lease row (re-entrant within the caller's txn) and read the
    // precharge state fresh — K-21 discipline: never trust a cached row.
    $lease = db_row(
        "SELECT contract_number, precharge_enabled, precharge_balance, precharge_invoiced_at
           FROM leases WHERE id = ? FOR UPDATE",
        [$leaseId]
    );
    if (!$lease || (int) $lease['precharge_enabled'] !== 1) {
        return $result;
    }

    if (bccomp($drawdownSum, '0', 2) > 0 && $lease['precharge_balance'] !== null) {
        $pre  = (string) $lease['precharge_balance'];
        $post = bcadd($pre, $drawdownSum, 2);
        db_execute(
            "UPDATE leases SET precharge_balance = ?, updated_at = NOW() WHERE id = ?",
            [$post, $leaseId]
        );
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'billing',
            'entity_type'  => 'lease_precharge_balance_drawdown_reversal',
            'entity_id'    => $leaseId,
            'entity_label' => $lease['contract_number'] ?? null,
            'notes'        => sprintf(
                'Precharge drawdown reversed on invoice %s %s: pre=$%s, restored=$%s, post=$%s',
                $inv['invoice_number'], $context, $pre, $drawdownSum, $post
            ),
            'old_values'   => json_encode(['precharge_balance' => $pre]),
            'new_values'   => json_encode([
                'precharge_balance' => $post,
                'restored_amount'   => $drawdownSum,
                'invoice_id'        => $invoiceId,
                'reason'            => $context,
            ]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
        $result['drawdown_restored'] = $drawdownSum;
    }

    if ((int) $inv['has_precharge_line'] === 1 && $lease['precharge_invoiced_at'] !== null) {
        db_execute(
            "UPDATE leases SET precharge_invoiced_at = NULL, updated_at = NOW() WHERE id = ?",
            [$leaseId]
        );
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => $userName,
            'action'       => 'update',
            'module'       => 'billing',
            'entity_type'  => 'lease_precharge_invoiced_at_unstamp',
            'entity_id'    => $leaseId,
            'entity_label' => $lease['contract_number'] ?? null,
            'notes'        => sprintf(
                'precharge_invoiced_at cleared: invoice %s (carried the mileage_precharge charge) %s — precharge re-bills on the next invoice (D138 gate re-opened)',
                $inv['invoice_number'], $context
            ),
            'old_values'   => json_encode(['precharge_invoiced_at' => $lease['precharge_invoiced_at']]),
            'new_values'   => json_encode(['precharge_invoiced_at' => null, 'invoice_id' => $invoiceId, 'reason' => $context]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
        $result['stamp_cleared'] = true;
    }

    return $result;
}
}

// ff_next_credit_note_number() — mint the next gap-free credit-note number
// (PREFIX-YYYY-NNNNN) via FOR UPDATE on the per-year settings counter row.
// MUST be called inside the caller's db_transaction (the row lock is what
// serializes concurrent minting; the counter bump commits/rolls back with the
// CN insert). S-AUDIT-LIFECYCLE-1 #24e: this pattern existed as FOUR verbatim
// copies (credit_notes/create.php, InvoiceGenerator overflow block, close.php
// precharge refund, _close_reconciliation adv_create_credit_note) — one home
// now, so a numbering change can't drift between sites.
if (!function_exists('ff_next_credit_note_number')) {
function ff_next_credit_note_number(): string
{
    $year = date('Y');
    $key  = "credit_note.next_number.{$year}";

    $row  = db_row("SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE", [$key]);
    $next = $row ? (int) $row['value'] : 1;
    $prefix = settings_get('credit_note.prefix', 'CN-CR');
    $number = sprintf('%s-%s-%05d', $prefix, $year, $next);

    if ($row) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [$next + 1, $key]);
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'credit_notes')",
            [$key, $next + 1]
        );
    }

    return $number;
}
}

// ff_mileage_unit_label() — the lease's mileage unit for display ('km' | 'miles'),
// and the singular '/unit' suffix label ('km' | 'mile'). S-MILEAGE-RATE-CONVERT-FIX.
if (!function_exists('ff_mileage_unit_label')) {
function ff_mileage_unit_label(array $lease, bool $singular = false): string
{
    $miles = (($lease['mileage_unit'] ?? 'km') === 'miles');
    return $miles ? ($singular ? 'mile' : 'miles') : 'km';
}
}

// ff_km_to_lease_unit() — convert a km-canonical distance to the lease's display
// unit (miles for a miles lease, else unchanged). For relabeling km-canonical audit
// values (period_distance_km etc.) on customer/admin surfaces. S-MILEAGE-RATE-CONVERT-FIX.
if (!function_exists('ff_km_to_lease_unit')) {
function ff_km_to_lease_unit(array $lease, $km): string
{
    if (($lease['mileage_unit'] ?? 'km') !== 'miles') return (string) $km;
    $f = (string) ($lease['km_to_miles_conversion'] ?? '0.621371');
    if (bccomp($f, '0', 6) <= 0) $f = '0.621371';
    return bcround(bcmul((string) $km, $f, 8), 2);
}
}

// clean_date() — return a validated Y-m-d date string, or null
// Rejects invalid calendar dates (e.g. Feb 30).
if (!function_exists('clean_date')) {
function clean_date(mixed $val): ?string
{
    // S-AUDIT-LIFECYCLE-1 #23: mixed ingestion — non-string JSON → null, not
    // a strict_types TypeError 500 (see clean_string).
    if ($val === null || $val === '' || !is_string($val)) return null;

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
// ff_invoice_display_period_end() — S-LEASE-CLOSE-ACTUAL-DATE
//
// Customer-facing rental-period END date for an invoice. invoices.
// billing_period_end is the BILLED extent — when the return-day-not-billed
// time-of-day rule trimmed the final day (customer returned at/before pickup
// time + grace), it is one day short of the real return. Surface the ACTUAL
// return date to the customer while billing_period_end stays the trimmed value
// for coverage / overshoot math (S-CLOSE-OVERSHOOT). Excludes the operator
// "Remove N days" case (billing_days_removed > 0), which intentionally presents
// the reduced period. The $inv row must carry billing_period_end plus the joined
// lease fields actual_return_date / actual_return_time / start_time /
// billing_days_removed; falls back to billing_period_end when they're absent.
// ============================================================
if (!function_exists('ff_invoice_display_period_end')) {
function ff_invoice_display_period_end(array $inv): ?string
{
    $billed = $inv['billing_period_end'] ?? null;
    $actual = $inv['actual_return_date'] ?? null;
    if ($actual === null || $billed === null) {
        return $billed;
    }
    // Swap to the actual return ONLY for the FINAL invoice the time-of-day rule
    // trimmed — its billed end is EXACTLY one day before the return (the rule
    // removes a single day: return at/before pickup time → return day not
    // billed). Keying on the exact 1-day signature (not merely actual > billed)
    // is what stops an INTERIM invoice that legitimately ends earlier — e.g. an
    // August monthly invoice on a lease returned Sep 19 — from mis-displaying
    // the lease's eventual return date as its own period end.
    try {
        $billedPlusOne = (new \DateTimeImmutable((string) $billed))->modify('+1 day')->format('Y-m-d');
    } catch (\Throwable) {
        return $billed;
    }
    $timeRuleTrimmed = !empty($inv['actual_return_time'])
        && !empty($inv['start_time'])
        && (int) ($inv['billing_days_removed'] ?? 0) === 0
        && $billedPlusOne === (string) $actual;
    return $timeRuleTrimmed ? (string) $actual : (string) $billed;
}
}

// ============================================================
// ff_samsara_odometer_likely_stalled() — S-SAMSARA-TRAILER-ODO-STALL-FLAG
//
// A TRAILER's Samsara odometer is the gateway-derived gpsOdometerMeters, which
// is known to FREEZE on some units (e.g. 12TR1303 stuck at 375 km while moving
// ~690 km/month per GPS). There is no cheap, exact way to know a value is frozen
// on a unit page (we overwrite samsara_odometer_km each sync and keep no
// history), so this is a deliberate PLAUSIBILITY heuristic: a trailer whose
// odometer reads implausibly LOW for an in-service trailer is flagged so the UI
// can warn "may be stalled — verify via Distance Travelled" (operator 2026-06-25
// chose flag-only; the real GPS distance lives in the Distance Travelled tool /
// trailerGpsDistance billing path). Vehicles (OBD odometer) are never flagged.
// The floor is the tunable setting `samsara.trailer_odometer_min_plausible_km`
// (default 1000) — no migration needed (default applies when the row is absent).
// NOTE: catches LOW-frozen values (the reported cases); a trailer frozen at a
// HIGH value won't trip this — a robust fix would track odometer-change time in
// the sync (a separate, larger change).
// ============================================================
if (!function_exists('ff_samsara_odometer_likely_stalled')) {
function ff_samsara_odometer_likely_stalled(?string $entityType, mixed $odoKm): bool
{
    if (($entityType ?? 'vehicle') !== 'trailer' || $odoKm === null || $odoKm === '') {
        return false;
    }
    $km = (float) $odoKm;
    if ($km <= 0) {
        return false; // 0 / no reading is handled as "no data", not "stalled"
    }
    $floor = (float) (settings_get('samsara.trailer_odometer_min_plausible_km', '1000') ?? '1000');
    return $floor > 0 && $km < $floor;
}
}

// ============================================================
// vin_conflict_message() — name the unit blocking a VIN
//
// equipment_units.vin is a GLOBAL UNIQUE index spanning soft-deleted rows, so a
// VIN can be "taken" by a unit the operator can't see (a soft-deleted row) or by
// a live unit that got the VIN mis-assigned during an import. The old "VIN
// already exists" error gave no clue which. This returns a message naming the
// conflicting unit (preferring a LIVE holder, and flagging a soft-deleted one
// with how to free it), or NULL when the VIN is free. Pass $excludeId to ignore
// the unit being edited.
// ============================================================
if (!function_exists('vin_conflict_message')) {
function vin_conflict_message(string $vin, ?int $excludeId = null): ?string
{
    $sql    = "SELECT unit_number, deleted_at FROM equipment_units WHERE vin = ?";
    $params = [$vin];
    if ($excludeId !== null) {
        $sql     .= " AND id != ?";
        $params[] = $excludeId;
    }
    // Prefer a LIVE holder (deleted_at IS NULL sorts first) so the message points
    // at the unit the operator can actually act on.
    $sql .= " ORDER BY (deleted_at IS NULL) DESC LIMIT 1";

    $row = db_row($sql, $params);
    if (!$row) {
        return null;
    }
    $unit = $row['unit_number'] ?? '(unknown unit)';
    return $row['deleted_at']
        ? "VIN already in use by deleted unit {$unit} — restore or hard-delete it to free the VIN."
        : "VIN already in use by unit {$unit}.";
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

// cron_enabled() — operator on/off switch for a business-automation cron.
// Reads cron.<name>_enabled (seeded by the S-CRON-TOGGLES migration, toggled in
// Settings → Intelligence → Scheduled Jobs), falling back to the registry default
// in config/cron_jobs.php when the row is absent. Any cron NOT in the registry
// (infra: backups, QBO, cache, …) is ALWAYS enabled — never gated from settings.
if (!function_exists('cron_enabled')) {
function cron_enabled(string $name): bool
{
    static $registry = null;
    if ($registry === null) {
        $registry = @require FF_ROOT . '/config/cron_jobs.php';
        if (!is_array($registry)) { $registry = []; }
    }
    if (!isset($registry[$name])) {
        return true; // not operator-toggleable
    }
    $default = (string) ($registry[$name]['default'] ?? '1');
    return (string) settings_get("cron.{$name}_enabled", $default) === '1';
}
}

// ============================================================
// LEGAL / COMPANY METADATA
// S-LEGAL-FOOTER-COMMERCIAL: helpers over $GLOBALS['_ff_legal']
// (populated by config/app.php from config/legal.php).
// ============================================================

/**
 * Read a value from the legal config using dot notation.
 *
 * Examples:
 *   legal_config()                          → full config array
 *   legal_config('company.legal_name')      → "Avi Technologies Inc."
 *   legal_config('pages.terms.title')       → "Terms of Service"
 *   legal_config('nonexistent.key')         → null
 */
if (!function_exists('legal_config')) {
function legal_config(string $key = ''): mixed
{
    $cfg = $GLOBALS['_ff_legal'] ?? [];

    if ($key === '') {
        return $cfg;
    }

    // Dot-notation walk — any missing segment short-circuits to null.
    foreach (explode('.', $key) as $part) {
        if (!is_array($cfg) || !array_key_exists($part, $cfg)) {
            return null;
        }
        $cfg = $cfg[$part];
    }
    return $cfg;
}
}

/**
 * Build the full URL to a /legal/{slug} page.
 *
 * Examples:
 *   legal_url('terms')   → "/fleetforge/legal/terms"
 *   legal_url('privacy') → "/fleetforge/legal/privacy"
 */
if (!function_exists('legal_url')) {
function legal_url(string $page): string
{
    return base_url('/legal/' . ltrim($page, '/'));
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
// EQUIPMENT-UNIT STATUS HELPERS — [SELECTOR-1]
//
// Centralised label / selectability rules for the
// equipment_units.status ENUM. The ENUM values
// (available, reserved, on_lease, maintenance, inactive,
// decommissioned) are used in dropdowns across every
// create form in the app, and the rules for which states a
// user may pick MUST be the same in every place — UI label,
// option-disabled flag, and server-side 409 rejection.
//
// USAGE:
//   ff_unit_status_label('on_lease')  → 'On Lease'
//   ff_unit_is_selectable('on_lease') → false   (block create)
//   ff_unit_selector_label($row)      → '12TR1301 — 53ft Dry Van  ·  AVAILABLE'
//
// Forms render an <option> with the disabled attribute set when
// ff_unit_is_selectable($u['status']) is false. APIs call the same
// helper and respond with json_error('UNIT_UNAVAILABLE', $msg, 409,
// ['fields' => ...]) if a non-available unit slips through anyway.
// ============================================================

if (!function_exists('ff_unit_status_label')) {
function ff_unit_status_label(?string $status): string
{
    // Centralised so a label change here cascades everywhere.
    // Keys MUST match the equipment_units.status ENUM exactly.
    static $labels = [
        'available'      => 'Available',
        'reserved'       => 'Reserved',
        'on_lease'       => 'On Lease',
        'maintenance'    => 'Maintenance',
        'inactive'       => 'Inactive',
        'decommissioned' => 'Decommissioned',
    ];
    if ($status === null || $status === '') return 'Unknown';
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
}

if (!function_exists('ff_unit_is_selectable')) {
/**
 * Whether a unit in this status may be selected for a NEW create
 * action. Two contexts:
 *
 *   'booking'  (default) — picking a unit for a NEW LEASE or
 *              RESERVATION. Only 'available' is selectable,
 *              because a reserved/leased/maintenance unit can't
 *              be double-booked. This is the STRICT rule.
 *
 *   'service' — picking a unit for a MAINTENANCE work order,
 *              inspection, damage claim, or mileage log. These
 *              are all "things that happen TO the unit", and must
 *              still be allowed while the unit is on lease, in
 *              maintenance, or reserved (a leased truck still
 *              needs its 90-day inspection, a reserved trailer
 *              still needs PM before it goes out). Only
 *              'decommissioned' and 'inactive' units are blocked.
 *
 * Reserved/on_lease are visible-but-disabled for booking so the
 * user SEES why they can't pick the unit, rather than the entry
 * silently disappearing from the list. Filter dropdowns (e.g.
 * mileage_logs/index.php) intentionally bypass this and show
 * every status — they are read-only filters.
 */
function ff_unit_is_selectable(?string $status, string $context = 'booking'): bool
{
    if ($context === 'service') {
        // Service workflow: block only the two "dead" states.
        return !in_array($status, ['decommissioned', 'inactive'], true);
    }
    // Booking workflow (default): only truly-available units.
    return $status === 'available';
}
}

if (!function_exists('ff_unit_unavailable_message')) {
/**
 * Build the user-facing 409 / validation error message for a unit
 * whose status blocks selection. Centralised so the API + form +
 * audit log all read the same English. Pass the same context value
 * you used with ff_unit_is_selectable() so the wording matches the
 * actual rule (a leased unit is "already on a lease" in booking
 * context but "decommissioned" in service context, etc.).
 */
function ff_unit_unavailable_message(string $unitNumber, string $status, string $context = 'booking'): string
{
    if ($context === 'booking' && in_array($status, ['on_lease', 'reserved'], true)) {
        return "Unit {$unitNumber} already has an active lease.";
    }
    if ($context === 'service') {
        return sprintf(
            'Unit %s cannot be serviced (status: %s).',
            $unitNumber,
            ff_unit_status_label($status)
        );
    }
    return sprintf(
        'Unit %s is not available (status: %s).',
        $unitNumber,
        ff_unit_status_label($status)
    );
}
}

if (!function_exists('ff_unit_selector_label')) {
/**
 * Build the visible <option> label for an equipment-unit selector.
 *
 * Format: "12TR1301 — 53ft Dry Van  ·  AVAILABLE"
 *  - The em-dash separator is used everywhere for unit_number
 *    vs description, so this matches existing form aesthetics.
 *  - The status suffix is uppercased so it reads as a badge,
 *    visually separable from the descriptive text.
 *  - Description is optional — pass any of template_name, year,
 *    brand, model, category in $u and the function will assemble
 *    a tidy descriptor in the same order forms have always used.
 *
 * @param array $u  Row containing at minimum unit_number + status.
 *                  Optional: template_name, year, brand, model, category.
 */
function ff_unit_selector_label(array $u): string
{
    $unitNumber = (string) ($u['unit_number'] ?? '');
    $status     = (string) ($u['status']      ?? '');

    // Build the descriptive middle segment from whichever optional
    // columns the caller selected. Order matches the conventions
    // already in use in the various create forms.
    $descParts = [];
    if (!empty($u['template_name'])) {
        $descParts[] = (string) $u['template_name'];
    } else {
        // Fallback for forms that pull year/brand/model individually.
        $ybm = trim(sprintf(
            '%s %s %s',
            (string) ($u['year']  ?? ''),
            (string) ($u['brand'] ?? ''),
            (string) ($u['model'] ?? '')
        ));
        if ($ybm !== '') $descParts[] = $ybm;
        if (!empty($u['category'])) $descParts[] = (string) $u['category'];
    }
    $desc = implode(' ', $descParts);

    $label = $unitNumber;
    if ($desc !== '') $label .= ' — ' . $desc;
    if ($status !== '') $label .= '  ·  ' . strtoupper(ff_unit_status_label($status));

    return $label;
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

// ============================================================
// EQUIPMENT HEALTH SCORE HELPERS — S-CRON-3
// ============================================================

// equipment_health_color() — derive color band from numeric health score
//
// WHY: Spec §12 locks the score→color mapping. Pure function, recompute on
// every read (no health_color column on equipment_units — D-NEW S-CRON-3).
// Cron writes health_score only; render sites call this helper.
//
// Mapping (locked):
//   80-100 → 'green'
//   50-79  → 'yellow'
//   20-49  → 'orange'
//   0-19   → 'red'
//   null   → 'unknown' (unit never scored)
if (!function_exists('equipment_health_color')) {
function equipment_health_color(?int $score): string
{
    if ($score === null) return 'unknown';
    if ($score >= 80) return 'green';
    if ($score >= 50) return 'yellow';
    if ($score >= 20) return 'orange';
    return 'red';
}
}

// ============================================================
// EQUIPMENT UNIT STATUS BADGE HELPER — S-UNIT-STATUS-COLOR
// ============================================================

// unit_status_badge_class() — map equipment_units.status to badge CSS class.
//
// WHY: DESIGN_DETAILS.md §9 locks the status→color mapping for equipment
// units. Centralized here so every surface (admin pages, server-rendered
// snippets, search endpoint) routes through one function rather than each
// page duplicating the map and drifting (see S-UNIT-STATUS-COLOR pre-flight
// audit which surfaced two existing drifted JS copies: reservations/show.php
// unitStatusBadge() had reserved→warning + maintenance→danger +
// decommissioned→neutral; api/v1/search.php $badge_class closure had
// maintenance→info + inactive→info; both corrected in same session).
//
// Mapping (locked per DESIGN_DETAILS.md §9 + equipment_units.status ENUM):
//   available      → badge-success  (green)
//   reserved       → badge-purple
//   on_lease       → badge-info     (blue)
//   maintenance    → badge-warning  (amber)
//   inactive       → badge-neutral  (gray)
//   decommissioned → badge-danger   (red)
//
// Returns the matching `.badge-*` class name. Unknown statuses fall through
// to badge-neutral (defensive default — shouldn't happen given the DB ENUM
// constraint, but renders sensibly if status is NULL or schema drifts).
if (!function_exists('unit_status_badge_class')) {
function unit_status_badge_class(?string $status): string
{
    return match ($status) {
        'available'      => 'badge-success',
        'reserved'       => 'badge-purple',
        'on_lease'       => 'badge-info',
        'maintenance'    => 'badge-warning',
        'inactive'       => 'badge-neutral',
        'decommissioned' => 'badge-danger',
        default          => 'badge-neutral',
    };
}
}

// ============================================================
// help_button() — S-HELP-DRAWER-TUTORIAL-REWORK
//
// Renders the standard "How this works" button for a module page
// header. Clicking dispatches a window CustomEvent ('ff-help-drawer')
// which opens the global right-side help drawer (help-drawer.php).
// The user stays on the current page — no navigation.
//
// Usage (inside a .page-header-actions block):
//   echo help_button('customers');
//
// The button dispatches via window.dispatchEvent so it works outside
// Alpine x-data scope (page headers are often outside the main
// Alpine component). The drawer partial listens via @ff-help-drawer.window.
// ============================================================
// WHY guard: functions.php is occasionally included via two different
// resolved paths (absolute vs symlinked) in CLI scripts, triggering a
// fatal "Cannot redeclare" without this guard. All other functions in
// this file follow the same pattern (see e(), base_url(), etc. above).
if (!function_exists('help_button')):
function help_button(string $moduleSlug): string
{
    $slug  = preg_replace('/[^a-z0-9_-]/', '', strtolower($moduleSlug));
    $label = e(ucwords(str_replace('-', ' ', $slug)));

    return '<button type="button" '
         . 'class="btn btn-ghost btn-sm help-btn" '
         . 'title="How this works — ' . $label . '" '
         . 'onclick="window.dispatchEvent(new CustomEvent(\'ff-help-drawer\',{detail:{slug:\'' . $slug . '\'}}))">'
         . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="15" height="15" aria-hidden="true" style="margin-right:5px;vertical-align:-2px;">'
         . '<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>'
         . '</svg>'
         . 'How this works'
         . '</button>';
}
endif; // function_exists('help_button')

/**
 * Invalidate all dashboard cache rows (KPIs + all 12 charts).
 *
 * Call after any write that changes KPI-relevant data so the next
 * dashboard load reflects the change immediately rather than waiting
 * for the TTL (5 min KPIs, 15 min charts) to expire naturally.
 */
function invalidate_dashboard_cache(): void
{
    db_execute("DELETE FROM report_cache WHERE report_type LIKE 'dashboard_%'");
}

/**
 * document_entity_module() — map a documents.entity_type to the permission
 * module that gates viewing that entity.
 *
 * SINGLE SOURCE OF TRUTH shared by every document access surface:
 *   - api/v1/documents/index.php  Mode A (entity-scoped list) permission gate
 *   - api/v1/documents/index.php  Mode B (global list) per-row scoping
 *   - api/v1/storage/serve.php    per-entity download authorization
 *
 * Covers all seven documents.entity_type enum members. The 'view' action on the
 * returned module is the gate for seeing/downloading a document of that type.
 * (C1 — documents access-scope leak: the global list and file-serve previously
 * trusted authentication without authorization.)
 */
function document_entity_module(string $entityType): string
{
    return match ($entityType) {
        'equipment_unit'                  => 'equipment',
        'lease', 'contract'               => 'leases',
        'customer'                        => 'customers',
        'inspection'                      => 'inspections',
        'damage_claim', 'service_request' => 'maintenance',
        default                           => 'equipment', // unknown type → gate behind a real module, never open
    };
}

/**
 * can_view_document() — true if the current user may see/download a document
 * owned by the given entity_type. super_admin short-circuits inside can().
 */
function can_view_document(string $entityType): bool
{
    return can(document_entity_module($entityType), 'view');
}

/**
 * redact_keys() — return $row without the listed top-level keys.
 *
 * Serve-time redaction primitive: drop fields a role may not see right before
 * a payload is serialized, rather than re-querying or branching every SELECT.
 * Pairs with can_view_financials() for the money-field redaction sweep.
 */
function redact_keys(array $row, array $keys): array
{
    foreach ($keys as $k) {
        unset($row[$k]);
    }
    return $row;
}

/**
 * redact_rows() — strip $keys from every associative row of a list. Non-array
 * elements pass through untouched.
 */
function redact_rows(array $rows, array $keys): array
{
    return array_map(
        static fn($r) => is_array($r) ? redact_keys($r, $keys) : $r,
        $rows
    );
}
