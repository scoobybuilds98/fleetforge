<?php
declare(strict_types=1);

/**
 * api/v1/late_fee_rules/_helpers.php
 *
 * I19 — shared helpers for the late-fee rule CRUD endpoints
 * (index/create/update/delete). Keeps the validation, the percent<->fraction
 * conversion, the API row shape and the audit_log write in ONE place so the
 * four endpoints can never drift apart.
 *
 * Storage contract (read by lib/Billing/LateFeeEngine.php::calculate() and the
 * rule lookup in InvoiceGenerator::generateLateFeeInvoice()):
 *   - customer_id NULL  = the global rule; non-NULL = a per-customer override
 *   - fee_type 'percentage' → fee_value is a FRACTION (0.0200 = 2%)
 *   - fee_type 'flat'       → fee_value is a dollar amount
 *   - max_fee_amount NULL   = no cap
 *   - lookup is `is_active = 1 ORDER BY customer_id DESC, id DESC`, so an
 *     INACTIVE customer rule falls through to the global rule. Exempting a
 *     customer therefore needs an ACTIVE flat 0.00 rule (the engine then
 *     computes a zero fee and skips the invoice).
 *
 * Column limits honoured here (FLEETFORGE_DATABASE_MASTER.sql):
 *   fee_value DECIMAL(8,4)       → flat ≤ 9999.99, percent ≤ 100 (fraction ≤ 1)
 *   grace_days TINYINT UNSIGNED  → 0–255 (a larger value would 1264 under STRICT)
 *   max_fee_amount DECIMAL(10,2) → ≤ 99,999,999.99
 *
 * Money math is bcmath strings throughout (D16) — never floats.
 *
 * @depends api/bootstrap.php (db + json helpers, clean_* validators)
 * @session I19-LATE-FEE-RULES
 */

if (!function_exists('lfr_validate')) {

    // Hard ceilings derived from the column types (see file docblock).
    // define() not `const`: `const` is illegal inside this function_exists guard block.
    define('LFR_MAX_PERCENT', '100');
    define('LFR_MAX_FLAT', '9999.99');
    define('LFR_MAX_CAP', '99999999.99');
    define('LFR_MAX_GRACE_DAYS', 255);

    /**
     * Count decimal places in an already-validated decimal string.
     *
     * WHY: MySQL silently ROUNDS extra fractional digits into a DECIMAL column,
     * so "2.555%" would quietly become 2.56% — we refuse instead of guessing.
     *
     * @param  string $d  Decimal string that passed clean_decimal().
     * @return int        Number of digits after the point (0 when none).
     */
    function lfr_decimals(string $d): int
    {
        $dot = strpos($d, '.');
        return $dot === false ? 0 : strlen($d) - $dot - 1;
    }

    /**
     * Normalise a JSON boolean-ish input (true/1/"1"/"true"/"on") to 0|1.
     *
     * @param  mixed $v        Raw body value.
     * @param  int   $default  Value when the key is absent/null.
     * @return int             0 or 1.
     */
    function lfr_bool(mixed $v, int $default): int
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
    }

    /**
     * Validate the editable rule fields from a request body and return the
     * DB-ready column values. Terminates with a 422 json_validation_error()
     * listing every bad field when anything is invalid.
     *
     * UI contract: a percentage is entered as a PERCENT (0–100, ≤ 2 decimals)
     * and stored ÷100 as a fraction; a flat fee / cap is dollars (≤ 2 decimals).
     * `exempt = true` (customer rules only) short-circuits to the canonical
     * exemption shape: active, flat, 0.00, no grace, no cap.
     *
     * @param  array $body        Decoded JSON body.
     * @param  bool  $isCustomer  True when the rule is a per-customer override.
     * @return array{fee_type:string, fee_value:string, grace_days:int,
     *               max_fee_amount:?string, is_active:int}
     */
    function lfr_validate(array $body, bool $isCustomer): array
    {
        // Exemption: only meaningful for a customer rule — a global "exempt"
        // is just "no late fees", which is expressed by switching it off.
        if ($isCustomer && lfr_bool($body['exempt'] ?? null, 0) === 1) {
            return [
                'fee_type'       => 'flat',
                'fee_value'      => '0.0000',
                'grace_days'     => 0,
                'max_fee_amount' => null,
                'is_active'      => 1, // WHY: an inactive rule would fall through to the global one
            ];
        }

        $fields  = [];
        $feeType = is_string($body['fee_type'] ?? null) ? trim($body['fee_type']) : '';
        if (!in_array($feeType, ['percentage', 'flat'], true)) {
            $fields['fee_type'] = 'Choose Percentage or Flat amount.';
        }

        // fee_value — percent (UI) or dollars, both ≥ 0 with ≤ 2 decimals.
        $rawValue = $body['fee_value'] ?? null;
        $value    = clean_non_negative_decimal(is_scalar($rawValue) ? (string) $rawValue : null);
        $stored   = null;
        if ($value === null) {
            $fields['fee_value'] = 'Enter a fee of 0 or more (numbers only, no $ or %).';
        } elseif (lfr_decimals($value) > 2) {
            $fields['fee_value'] = 'Use at most 2 decimal places.';
        } elseif ($feeType === 'percentage') {
            if (bccomp($value, LFR_MAX_PERCENT, 2) > 0) {
                $fields['fee_value'] = 'A percentage fee must be between 0 and 100.';
            } else {
                // Percent → fraction: 2.5 → 0.0250 (scale 4 = the column's scale, exact for ≤2dp input)
                $stored = bcdiv($value, '100', 4);
            }
        } elseif ($feeType === 'flat') {
            if (bccomp($value, LFR_MAX_FLAT, 2) > 0) {
                $fields['fee_value'] = 'A flat fee cannot exceed $' . LFR_MAX_FLAT . '.';
            } else {
                $stored = bcadd($value, '0', 4);
            }
        }

        // grace_days — whole days, 0–255 (TINYINT UNSIGNED).
        $graceRaw = $body['grace_days'] ?? 0;
        $grace    = ($graceRaw === '' || $graceRaw === null) ? 0 : (is_scalar($graceRaw) ? clean_int($graceRaw) : null);
        if ($grace === null || $grace < 0 || $grace > LFR_MAX_GRACE_DAYS) {
            $fields['grace_days'] = 'Grace days must be a whole number from 0 to ' . LFR_MAX_GRACE_DAYS . '.';
        }

        // max_fee_amount — optional cap; blank = no cap.
        $capRaw = $body['max_fee_amount'] ?? null;
        $cap    = null;
        if ($capRaw !== null && (!is_scalar($capRaw) || trim((string) $capRaw) !== '')) {
            $cap = clean_non_negative_decimal(is_scalar($capRaw) ? (string) $capRaw : null);
            if ($cap === null) {
                $fields['max_fee_amount'] = 'The cap must be 0 or more (or leave it blank for no cap).';
            } elseif (lfr_decimals($cap) > 2) {
                $fields['max_fee_amount'] = 'Use at most 2 decimal places.';
            } elseif (bccomp($cap, LFR_MAX_CAP, 2) > 0) {
                $fields['max_fee_amount'] = 'The cap is too large.';
            } else {
                $cap = bcadd($cap, '0', 2);
            }
        }

        if ($fields) {
            json_validation_error($fields);
        }

        return [
            'fee_type'       => $feeType,
            'fee_value'      => (string) $stored,
            'grace_days'     => (int) $grace,
            'max_fee_amount' => $cap,
            'is_active'      => lfr_bool($body['is_active'] ?? null, 1),
        ];
    }

    /**
     * Fetch one rule in the API shape (with customer name + UI-ready values).
     *
     * @param  int $id  late_fee_rules.id
     * @return array|null  Null when the rule does not exist.
     */
    function lfr_fetch(int $id): ?array
    {
        $row = db_row(
            "SELECT r.*, c.company_name AS customer_name, c.deleted_at AS customer_deleted_at,
                    u.name AS created_by_name
               FROM late_fee_rules r
               LEFT JOIN customers c ON c.id = r.customer_id
               LEFT JOIN users u     ON u.id = r.created_by
              WHERE r.id = ?",
            [$id]
        );
        return $row ? lfr_shape($row) : null;
    }

    /**
     * Convert a raw joined row into the API shape.
     *
     * Adds `fee_value_input` — the number the UI form shows/edits (percent for
     * percentage rules, dollars for flat) — so the page never multiplies a
     * fraction by 100 in JavaScript floats.
     *
     * @param  array $r  Row from late_fee_rules (+ optional customer/user joins).
     * @return array
     */
    function lfr_shape(array $r): array
    {
        $feeValue = (string) $r['fee_value'];
        $input    = $r['fee_type'] === 'percentage'
            ? bcmul($feeValue, '100', 2)
            : bcadd($feeValue, '0', 2);

        return [
            'id'               => (int) $r['id'],
            'customer_id'      => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'customer_name'    => $r['customer_name'] ?? null,
            'customer_deleted' => !empty($r['customer_deleted_at']),
            'fee_type'         => (string) $r['fee_type'],
            'fee_value'        => $feeValue,
            'fee_value_input'  => $input,
            'grace_days'       => (int) $r['grace_days'],
            'max_fee_amount'   => $r['max_fee_amount'] !== null ? (string) $r['max_fee_amount'] : null,
            'is_active'        => (int) $r['is_active'] === 1,
            // Exempt = an active rule that can only ever compute $0 (the shape the UI writes).
            'is_exempt'        => $r['customer_id'] !== null && bccomp($feeValue, '0', 4) === 0,
            'created_by_name'  => $r['created_by_name'] ?? null,
            'created_at'       => $r['created_at'] ?? null,
        ];
    }

    /**
     * Human label for audit rows / messages.
     *
     * @param  array $shaped  lfr_shape() output.
     * @return string
     */
    function lfr_label(array $shaped): string
    {
        return $shaped['customer_id'] === null
            ? 'Global late fee rule'
            : 'Late fee rule — ' . ($shaped['customer_name'] ?? ('customer #' . $shaped['customer_id']));
    }

    /**
     * Write one audit_log row for a rule change (pattern: accounting/periods/close.php).
     *
     * @param  string     $action  create | update | delete
     * @param  int        $id      Rule id.
     * @param  string     $label   entity_label.
     * @param  array|null $old     Previous values (null on create).
     * @param  array|null $new     New values (null on delete).
     * @param  string     $notes   Free-text note.
     * @return void
     */
    function lfr_audit(string $action, int $id, string $label, ?array $old, ?array $new, string $notes): void
    {
        $user = current_user();
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $user['name'] ?? 'System',
            'action'       => $action,
            'module'       => 'settings',
            'entity_type'  => 'late_fee_rule',
            'entity_id'    => $id,
            'entity_label' => mb_substr($label, 0, 255),
            'notes'        => $notes,
            'old_values'   => $old !== null ? json_encode($old) : null,
            'new_values'   => $new !== null ? json_encode($new) : null,
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }

    /**
     * Serialise rule writes across requests with a MySQL named lock.
     *
     * WHY: "one global rule / one rule per customer" is enforced in code (the
     * table has no UNIQUE on customer_id, and NULLs never collide in a UNIQUE
     * anyway). Without a lock two simultaneous "Add" clicks could both pass the
     * duplicate check. The lock is per-connection, so an early exit via
     * json_error() still releases it when the request's connection closes.
     *
     * @return void  Terminates with 409 LOCKED when the lock can't be taken in 5s.
     */
    function lfr_lock(): void
    {
        $got = db_row("SELECT GET_LOCK('ff_late_fee_rules_write', 5) AS ok", []);
        if (!$got || (int) $got['ok'] !== 1) {
            json_error('LOCKED', 'Another late-fee rule change is in progress — please try again.', 409);
        }
    }

    /** Release the lock taken by lfr_lock(). */
    function lfr_unlock(): void
    {
        db_row("SELECT RELEASE_LOCK('ff_late_fee_rules_write') AS ok", []);
    }
}
