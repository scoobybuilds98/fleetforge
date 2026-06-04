# FleetForge — Form Entity Selector Audit

**Session:** S-FORM-ENTITY-SELECTOR-AUDIT  
**Date:** 2026-06-04  
**Scope:** Every admin create/edit form + inline/modal forms. Read-only inventory — no changes made.  
**Purpose:** Baseline for a consistent retrofit giving every entity-selection field both pick-from-list AND type-to-filter, with a manual-entry fallback where appropriate.

---

## Control-type legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Already supports typing/search (FF_RecordPicker, FF_LookupPicker) |
| ⚠️ S | Selector-only — native `<select>`, no typing. **Main retrofit target.** |
| ⚠️ M | Manual-only / free-text on an FK — integrity risk (stored as raw string or bare ID) |
| ℹ️ | Context field (filter bar, read-only display, not a data-entry form) |

---

## Module-by-module tables

### LEASES

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Customer | `customers` → `customer_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |
| Create | Equipment Unit | `equipment_units` → `equipment_unit_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |
| Edit | Customer | — | read-only `<div>` (locked at creation) | — | — | — | ℹ️ |
| Edit | Equipment Unit | — | read-only `<div>` (locked at creation) | — | — | — | ℹ️ |
| Show — Close Lease modal | *(none — all scalar fields)* | — | — | — | — | — | — |
| Show — Amendment modal | *(none — amendment_type is a local enum, not a FK)* | — | — | — | — | — | — |
| Show — Rate Amendment modal | *(none — all rate number inputs)* | — | — | — | — | — | — |

**Notes:** The `leases/create.php` Customer dropdown populates with all active customers (could grow to 200+). Unit dropdown shows all available units and greys out unavailable ones. Both are among the highest-priority retrofit candidates — every lease creation starts here.

---

### INVOICES

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Lease | `leases` → `lease_id` | native `<select>` (PHP-rendered, all active+completed leases) | no | no | yes — `?lease_id=N` | ⚠️ S |
| Show — Record Payment | *(navigates to payments/create, not inline)* | — | — | — | — | — | ℹ️ |

---

### PAYMENTS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Invoice | `invoices` → `invoice_id` | **FF_RecordPicker** (debounced autocomplete, `/api/v1/invoices/index.php`) | yes | no | yes — `?invoice_id=N` | ✅ |

---

### CREDIT NOTES

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Customer *(required)* | `customers` → `customer_id` | **FF_RecordPicker** (autocomplete, `/api/v1/customers/index.php`) | yes | no | yes — `?customer_id=N` (JS) | ✅ |
| Create | Lease *(optional, collapsible)* | `leases` → `lease_id` | **FF_RecordPicker** (autocomplete, `/api/v1/leases/index.php`) | yes | no | no | ✅ |
| Create | Source Invoice *(optional, collapsible)* | `invoices` → `source_invoice_id` | **FF_RecordPicker** (autocomplete, `/api/v1/invoices/index.php`) | yes | no | no | ✅ |
| Create | Source Payment *(optional, collapsible)* | `payments` → `source_payment_id` | **FF_RecordPicker** (autocomplete, `/api/v1/payments/index.php`) | yes | no | no | ✅ |

**Notes:** `credit_notes/create.php` is the gold-standard form — every entity field uses FF_RecordPicker.

---

### CUSTOMERS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | *(none — all enum/scalar fields)* | — | — | — | — | — | — |
| Show — Rate Override modal | Equipment Type | `equipment_templates` → `equipment_type` *(stored as name string, not ID)* | native `<select>` (PHP-rendered from templates) | no | no | no | ⚠️ S + ⚠️ M |
| Show — Doc Upload modal | Document Type | *(local enum, not a FK)* | — | — | — | — | — |

**Note on Rate Override:** Stores `equipment_templates.name` as a string snapshot rather than a numeric `template_id`. Flagged as ⚠️ M (free-text-on-FK) — if a template is renamed the stored string becomes orphaned. Low severity for now (template names are stable), but worth normalising to `template_id` in the future.

---

### RESERVATIONS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Customer *(Existing mode)* | `customers` → `customer_id` | **FF_RecordPicker** (autocomplete, `/api/v1/customers/index.php`) | yes | no | no | ✅ |
| Create | Customer name *(Manual mode)* | — → `company_name` (free text) | plain `<input type="text">` | yes (free) | — | no | ℹ️ by design |
| Create | Trailer Type | `equipment_templates` → `trailer_type_id` | native `<select>` (Alpine x-for, API-loaded) | no | no | no | ⚠️ S |
| Create | Unit# *(Existing mode)* | `equipment_units` → `equipment_unit_id` | native `<select>` (Alpine x-for, grouped optgroups) | no | no | no | ⚠️ S |
| Create | Unit# *(Manual mode)* | — → `unit_number` (free text) | plain `<input type="text">` | yes (free) | — | no | ℹ️ by design |
| Create | Pickup Yard | `yards` → `yard_location` *(stored as name string, not ID)* | native `<select>` (Alpine x-for, API-loaded) | no | no | no | ⚠️ S + ⚠️ M |

**Note on Yard:** Stores `yards.name` as a string snapshot. Same orphaning risk as Rate Override above.

---

### DAMAGE CLAIMS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Unit *(required)* | `equipment_units` → `equipment_unit_id` | native `<select>` (PHP-rendered) | no | no | yes — `?equipment_unit_id=N` | ⚠️ S |
| Create | Customer | `customers` → `customer_id` | **FF_RecordPicker** (autocomplete, `/api/v1/customers/index.php`; companion free-text for `customer_name` kept) | yes | no | yes — `?customer_id=N` | ✅ |
| Create | Vendor Sent To *(optional)* | `vendors` → `vendor_id` | **FF_RecordPicker** (autocomplete, `/api/v1/vendors/index.php`) | yes | no | no | ✅ |
| Create | Linked Lease ID | `leases` → `lease_id` | **FF_RecordPicker** (integrity session S-FORM-INTEGRITY-FIX-FK-FIELDS) | yes | no | yes — `?lease_id=N` | ✅ |

**Note on Linked Lease ID:** Bare numeric input — user must know the lease database ID. No validation that the ID exists. This is the clearest integrity risk in the codebase: a typo silently orphans the claim.

---

### MILEAGE LOGS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Unit *(required)* | `equipment_units` → `equipment_unit_id` | native `<select>` (PHP-rendered) | no | no | yes — `?equipment_unit_id=N` | ⚠️ S |
| Create | Linked Lease *(optional)* | `leases` → `lease_id` | **FF_RecordPicker** (autocomplete, `/api/v1/leases/index.php`; vanilla-JS bridge) | yes | no | yes — `?lease_id=N` | ✅ |

---

### MAINTENANCE WORK ORDERS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Unit *(required)* | `equipment_units` → `equipment_unit_id` | **FF_RecordPicker** (Batch 2) | yes | no | yes — `?unit_id=N` | ✅ |
| Create | Vendor *(optional)* | `vendors` → `vendor_id` | **FF_RecordPicker** (autocomplete, `/api/v1/vendors/index.php`) | yes | no | no | ✅ |
| Create | Assigned To *(optional)* | `users` → `assigned_to` | **FF_RecordPicker** (autocomplete, `/api/v1/users/index.php`; D-PICKER-USER-VARIANT) | yes | no | no | ✅ |
| Show — inline edit mode | Vendor *(edit)* | `vendors` → `vendor_id` | **FF_RecordPicker** (autocomplete, `/api/v1/vendors/index.php`; initialId from `$wo`) | yes | no | no (from record) | ✅ |
| Show — inline edit mode | Assigned To *(edit)* | `users` → `assigned_to` | **FF_RecordPicker** (autocomplete, `/api/v1/users/index.php`; initialId from `$wo`; D-PICKER-USER-VARIANT) | yes | no | no (from record) | ✅ |

---

### INSPECTIONS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Unit *(required)* | `equipment_units` → `equipment_unit_id` | **FF_RecordPicker** (Batch 2) | yes | no | yes — `?unit_id=N` | ✅ |
| Create | Linked Lease *(optional)* | `leases` → `lease_id` | **FF_RecordPicker** (autocomplete, `/api/v1/leases/index.php`; shows all non-deleted leases) | yes | no | yes — `?lease_id=N` | ✅ |
| Create | Inspector (User) *(optional)* | `users` → `inspected_by_user_id` | **FF_RecordPicker** (autocomplete, `/api/v1/users/index.php`; D-PICKER-USER-VARIANT) | yes | no | no | ✅ |

**Note:** "Inspected By (Name)" free-text field is a separate plain string field, not a FK — excluded from scope.

---

### EQUIPMENT — UNITS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Template *(required)* | `equipment_templates` → `template_id` | native `<select>` (PHP-rendered; `@change` auto-fills dimension/rate defaults from `data-*`) | no | no | no | ⚠️ S |
| Create | Yard Location | `yards` → `yard_location` *(stored as name string)* | native `<select>` (PHP-rendered from yards table) | no | no | no | ⚠️ S + ⚠️ M |
| Edit | Template | read-only (locked at creation) | — | — | — | — | ℹ️ |

---

### EQUIPMENT — TEMPLATES

No FK entity-selection fields on create or edit. All fields are scalar values (name, category, dimensions, rates).

---

### VENDORS

No FK entity-selection fields on create or edit. Standalone master record — all fields are plain values.

---

### RATES

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Equipment Type *(per row, repeating)* | `equipment_templates` → `equipment_type` *(stored as name string)* | native `<select>` inside Alpine `x-for` loop | no | no | no | ⚠️ S + ⚠️ M |

---

### ACCOUNTING — JOURNAL ENTRIES (create-JE modal)

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create JE modal | Account *(per line, repeating)* | `acc_accounts` → `account_id` | native `<select>` (Alpine x-for over API-loaded accounts array) | no | no | no | ⚠️ S |

---

### ACCOUNTING — BILLS (create/edit modal + pay modal)

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create/Edit bill modal | Vendor *(required)* | `vendors` → `vendor_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |
| Create/Edit bill modal | GL Account *(per line, repeating)* | `acc_accounts` → `account_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |
| Pay modal | Bank Account *(required)* | `acc_bank_accounts` → `bank_account_id` | native `<select>` (PHP-rendered; auto-selects first) | no | no | no | ⚠️ S |

---

### ACCOUNTING — RECURRING ENTRIES (create)

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Account *(per line, repeating)* | `acc_accounts` → `account_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |

---

### ACCOUNTING — FIXED ASSETS (create/edit asset modals)

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create asset modal | Asset Account | `acc_accounts` → `asset_account_id` | **FF_LookupPicker** (autocomplete + Manual ID toggle) | yes | no | no | ✅ |
| Create asset modal | Accum Depreciation Account | `acc_accounts` → `accum_depr_account_id` | **FF_LookupPicker** | yes | no | no | ✅ |
| Create asset modal | Depreciation Expense Account | `acc_accounts` → `depr_expense_account_id` | **FF_LookupPicker** | yes | no | no | ✅ |
| Create asset modal | Equipment Unit *(optional)* | `equipment_units` → `equipment_unit_id` | **FF_LookupPicker** | yes | no | no | ✅ |
| Create asset modal | CCA Class | `acc_cca_classes` → `cca_class_id` | native `<select>` (Alpine x-for, API-loaded) | no | no | no | ⚠️ S |
| Edit asset modal | Equipment Unit | `equipment_units` → `equipment_unit_id` | **FF_LookupPicker** | yes | no | no | ✅ |

**Notes:** Fixed assets is the only module (besides credit notes) where type-ahead is used consistently for account fields. FF_LookupPicker includes a "Manual ID" toggle — enter a raw account ID if the search doesn't find what you need. This is a good pattern to replicate.

---

### ACCOUNTING — BANK ACCOUNTS (account modal + transfer modal + manual transaction modal)

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create/Edit account modal | GL Cash Account *(required)* | `acc_accounts` → `gl_account_id` | native `<select>` (PHP-rendered) | no | no | no | ⚠️ S |
| CSV Import modal | Bank Account | `acc_bank_accounts` → `bank_account_id` | native `<select>` (Alpine x-for) | no | no | no | ⚠️ S |
| Transfer modal | From Account | `acc_bank_accounts` → `from_account_id` | native `<select>` (Alpine x-for) | no | no | no | ⚠️ S |
| Transfer modal | To Account | `acc_bank_accounts` → `to_account_id` | native `<select>` (Alpine x-for) | no | no | no | ⚠️ S |
| Manual Transaction modal | GL Account *(optional — creates JE)* | `acc_accounts` → `expense_account_id` | native `<select>` (PHP-rendered expense accounts only) | no | no | no | ⚠️ S |

---

### QUICKBOOKS — Customer Mapping UI

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Link modal (pick-QBO mode) | QBO Customer | `acc_qbo_customer_map` → `qbo_customer_id` | native `<select>` (Alpine x-for, API-loaded QBO-side customers) | no | no | no | ⚠️ S |
| Link modal (pick-FF mode) | FF Customer | `customers` → `ff_customer_id` | native `<select>` (Alpine x-for, API-loaded FF customers) | no | no | no | ⚠️ S |

---

### USERS

| Form | Field label | Entity → FK column | Control | Typing? | Create-new? | URL param? | Category |
|------|-------------|-------------------|---------|---------|-------------|------------|----------|
| Create | Role *(required)* | `user_roles` → `role_id` | native `<select>` (PHP-rendered, typically 5–6 rows) | no | no | no | ⚠️ S |

**Note:** Only 5–6 roles — list size doesn't warrant type-ahead, but a consistent combobox is still preferable.

---

### SETTINGS

No FK entity-selection fields in any settings tab. Role/user checkboxes in Intelligence, Security, and Portal settings are multi-select checkbox lists rendered server-side (not FK dropdown pickers).

---

## Summary

### Totals

| Category | Count |
|----------|-------|
| **Total FK entity-selection fields audited** | **52** |
| ✅ Already searchable or converted (FF_RecordPicker / FF_LookupPicker) | **31** |
| ⚠️ S — Selector-only, no typing (native `<select>`) — remaining | **22** |
| ⚠️ M → ✅ — Was manual FK, now picker (integrity session) | **1** |
| ⚠️ M — Manual-only / free-text on FK (integrity risk) | **3** |

*Retrofit progress: 16 of 38 selector-only fields converted (Batch 1: 3; Batch 2: 4; Batch 2B: 9). Last updated S-DROPDOWN-RETROFIT-2B-FINISH-FORMS 2026-06-05.*

### Already-searchable / converted fields (31 — includes all retrofit batches)

| Module | Fields |
|--------|--------|
| Payments / Create | Invoice (FF_RecordPicker) |
| Credit Notes / Create | Customer, Lease, Source Invoice, Source Payment (FF_RecordPicker × 4) |
| Reservations / Create | Customer — Existing mode (FF_RecordPicker) |
| Fixed Assets / Create | Asset Acct, Accum Depr Acct, Depr Expense Acct, Equipment Unit (FF_LookupPicker × 4) |
| Fixed Assets / Edit | Equipment Unit (FF_LookupPicker) |
| **Batch 1 (S-DROPDOWN-RETROFIT-1)** | Leases/Create: Customer, Equipment Unit; Invoices/Create: Lease (3 fields) |
| **Batch 2 (S-DROPDOWN-RETROFIT-2)** | Equipment Unit on Damage Claims/WOs/Inspections/Mileage Logs Create (4 fields) |
| **Batch 2B (S-DROPDOWN-RETROFIT-2B)** | Damage Claims/Create: Customer, Vendor; WOs/Create: Vendor, Assigned To; WOs/Show-edit: Vendor, Assigned To; Inspections/Create: Linked Lease, Inspector User; Mileage/Create: Linked Lease (9 fields) |
| **Integrity session** | Damage Claims/Create: Linked Lease (was ⚠️ M raw-ID, now picker) |

### Integrity-risk fields (4 — free-text on FK)

| Module / Form | Field | Risk |
|---------------|-------|------|
| Damage Claims / Create | Linked Lease ID | `<input type="number">` raw ID — no existence check, silent orphan on typo |
| Customers / Show — Rate Override modal | Equipment Type | Stores `equipment_templates.name` string — orphaned if template renamed |
| Reservations / Create | Pickup Yard | Stores `yards.name` string — orphaned if yard renamed |
| Rates / Create | Equipment Type (per row) | Stores `equipment_templates.name` string — same as above |

### Selector-only retrofit targets by priority

**Priority 1 — high volume / high impact:**

| Module | Field(s) | Dataset size |
|--------|----------|-------------|
| Leases / Create | Customer, Equipment Unit | Customers: could grow to 200+; Units: 10–50 |
| Invoices / Create | Lease | All active+completed leases |
| Accounting / Bills modal | Vendor, GL Account (per row) | Vendors: typically 10–30; Accounts: 30–100+ |
| Accounting / Journal Entries modal | Account (per row) | 30–100+ GL accounts |
| Accounting / Recurring Entries | Account (per row) | Same |
| Accounting / Bank Accounts | GL Cash Account, Transfer From/To | Small list but consistency matters |
| Damage Claims / Create | Equipment Unit, Customer, Vendor | Equipment: 10–50; Customers: growing |

**Priority 2 — medium volume:**

| Module | Field(s) | Dataset size |
|--------|----------|-------------|
| Maintenance WOs / Create + Edit | Equipment Unit, Vendor, Assigned To | Small but type-ahead improves UX |
| Inspections / Create | Equipment Unit, Lease, Inspector | Same |
| Mileage Logs / Create | Equipment Unit, Lease | Same |
| Equipment / Create | Template, Yard Location | Small lists |
| Users / Create | Role | 5–6 rows (low priority) |
| QBO Mapping UI | QBO/FF Customer in Link modal | Medium (all customers) |
| Fixed Assets / Create | CCA Class | Small — low urgency |
| Reservations / Create | Trailer Type, Unit# (Existing mode), Pickup Yard | Medium |

### Recommended control per entity type

| Entity | Recommended control | Create-new inline? |
|--------|--------------------|--------------------|
| Customers | FF_RecordPicker (already used in credit notes, reservations, payments) | Yes — could be useful on damage claims |
| Leases | FF_RecordPicker | No |
| Invoices | FF_RecordPicker (already used in payments) | No |
| Payments | FF_RecordPicker (already used in credit notes) | No |
| Equipment Units | FF_RecordPicker or FF_LookupPicker | No |
| Equipment Templates | FF_RecordPicker (replace name-snapshot with ID lookup) | No |
| Vendors | FF_RecordPicker | Yes — "+Quick add vendor" on bills/WOs would help |
| GL Accounts | FF_LookupPicker with Manual ID toggle (already used in fixed assets) | No — accounts are controlled |
| Yards | FF_RecordPicker (fix name-snapshot → numeric FK at same time) | No |
| Users / Assigned To | Native `<select>` acceptable (small list); combobox if team grows | No |
| User Roles | Native `<select>` acceptable (5–6 rows) | No |
| CCA Classes | Native `<select>` acceptable (small, controlled list) | No |
| Bank Accounts | Native `<select>` acceptable (few accounts) | No |

---

## Retrofit pattern reference

Two existing patterns to follow — do not invent a new one:

1. **FF_RecordPicker** (`includes/partials/record-picker.php`) — used in payments, credit notes, reservations. Configures via a PHP `$pickerConfig` array: `endpoint`, `searchParam`, `resultKey`, `mapResult`, `perPage`, `placeholder`. Best for entities with API search endpoints. Dispatches `record-picked` custom event; parent binds via `@record-picked.window`.

2. **FF_LookupPicker** — used in fixed assets for GL accounts and equipment units. Includes a "Manual ID" toggle, making it the pattern to use when a power user might need to enter a raw ID. Best for accounting entities where the search might miss edge cases.

For the integrity-risk fields (name-snapshot FKs): the retrofit should simultaneously normalise storage to a numeric FK — otherwise adding a picker on top of a string column solves the UX but not the integrity risk.

---

*Generated by S-FORM-ENTITY-SELECTOR-AUDIT. No forms were modified.*
