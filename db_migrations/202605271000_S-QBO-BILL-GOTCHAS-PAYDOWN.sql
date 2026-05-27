-- S-QBO-BILL-GOTCHAS-PAYDOWN Migration
-- Extend acc_qbo_bill_map.push_status ENUM with typed preflight failure
-- states, mirroring the invoice_map pattern (D-QBO-FIXPACK-5).
--
-- WHY: S-QBO-18 v1 used the generic `failed_preflight` state for all
-- pre-flight gate failures. The S-QBO-BILL-GOTCHAS-PAYDOWN session adds
-- two new pre-flight gates that warrant typed sub-states for operator
-- diagnostic clarity:
--   1. failed_preflight_currency_mismatch — FF bill currency ≠ QBO vendor
--      currency (D-QBO-BILL-GOTCHAS-1; mirrors D-QBO-FIXPACK-3 for invoices)
--   2. failed_preflight_field_too_long — vendor_bill_number > 21 chars
--      (D-QBO-BILL-GOTCHAS-2; mirrors D-QBO-FIXPACK-5 for invoices). The
--      existing gate 7 already detects this at preflight; this typed
--      state lets the operator filter/retry the specific class.
--
-- Existing 'failed_preflight' generic state preserved for any gates
-- that don't have a typed sub-state.
-- 2026-05-27 | S-QBO-BILL-GOTCHAS-PAYDOWN

ALTER TABLE `acc_qbo_bill_map`
  MODIFY COLUMN `push_status` ENUM(
    'pending',
    'pushed',
    'voided',
    'failed',
    'skipped_voided',
    'skipped_unmapped_void',
    'skipped_by_mode',
    'failed_preflight',
    'failed_preflight_currency_mismatch',
    'failed_preflight_field_too_long'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending'
  COMMENT 'D-QBO-18-* lifecycle states + S-QBO-BILL-GOTCHAS-PAYDOWN typed preflight sub-states. failed_preflight_currency_mismatch mirrors D-QBO-FIXPACK-3 (invoice gate 4.5). failed_preflight_field_too_long mirrors D-QBO-FIXPACK-5 (invoice gate 6). Generic failed_preflight retained for unspecialized gate failures.';
