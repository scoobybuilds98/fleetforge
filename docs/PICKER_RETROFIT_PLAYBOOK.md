# FleetForge — FK Dropdown → FF_RecordPicker Retrofit Playbook

**Session:** S-DROPDOWN-RETROFIT-1-LEASES-INVOICES  
**Date:** 2026-06-04  
**Decision:** D-DROPDOWN-RETROFIT-PATTERN  
**Scope:** 38 selector-only FK fields audited in FORM_ENTITY_SELECTOR_AUDIT.md. This playbook covers the conversion recipe so remaining batches are mechanical.

---

## Overview

Each target field is a native `<select>` populated server-side from a PHP `db_select()` call. The retrofit replaces it with **FF_RecordPicker** — the discoverable picker introduced in S-PICKER-DISCOVERABILITY (chevron + open-on-focus initial load + type-to-filter search).

The canonical picker partial is `includes/partials/record-picker.php`. It renders the FF_RecordPicker Alpine component and dispatches `@record-picked` / `@record-cleared` events.

---

## When to use FF_RecordPicker vs FF_LookupPicker

| Picker | Use when | Examples |
|--------|----------|---------|
| **FF_RecordPicker** | Entity has a search API endpoint (`api/v1/<entity>/index.php`). Most FK fields. | Customers, Leases, Equipment, Vendors, Users |
| **FF_LookupPicker** | Accounting entities where a manual ID override fallback is useful. | GL Accounts (fixed assets), Equipment Units in accounting contexts |

---

## Retrofit recipe (5 steps)

### Step 1 — Remove PHP server-side preload

The `<select>` typically has a PHP preload:
```php
$customers = db_select("SELECT id, company_name ... FROM customers WHERE ...");
```
**Remove it.** The picker queries the API directly from JavaScript. Do not leave dead `$customers` arrays in the file.

**Exception:** If `?entity_id=N` URL param is used for pre-population, keep a minimal lookup:
```php
$preCustomerId = clean_int($_GET['customer_id'] ?? null);
$preCustomerLabel = null;
if ($preCustomerId) {
    $row = db_row("SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL", [$preCustomerId]);
    if ($row) {
        $preCustomerLabel = $row['company_name'];
    } else {
        $preCustomerId = null; // drop invalid ID
    }
}
```

### Step 2 — Replace the `<select>` with the picker partial

```php
<div class="form-group">
    <label class="form-label required">Customer</label>
    <?php
    $pickerConfig = [
        'endpoint'    => base_url('api/v1/customers/index.php'),
        'searchParam' => 'search',
        'resultKey'   => 'items',
        'perPage'     => 10,
        'extraParams' => 'status=active',       // optional: pre-filter the API
        'placeholder' => 'Search customers…',
        // mapResult: arrow fn returning { id, label, sublabel, raw }
        // - id:       the FK value to store (entity primary key)
        // - label:    primary display text (shown in the picker input after selection)
        // - sublabel: secondary info (shown in the dropdown only)
        // - raw:      full record — passed to @record-picked handler as $event.detail.raw
        'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: r.city || '', raw: r })",
    ];
    // Pre-populate edit mode: set initialId + initialLabel so picker shows the current value
    if ($preCustomerId && $preCustomerLabel) {
        $pickerConfig['initialId']    = (int) $preCustomerId;
        $pickerConfig['initialLabel'] = $preCustomerLabel;
    }
    $pickerOnPicked  = 'form.customer_id = $event.detail.id; onCustomerPickerSelected($event.detail.raw)';
    $pickerOnCleared = "form.customer_id = ''";
    $pickerError     = 'false'; // or e.g. 'errors.customer_id' to add is-invalid class
    require FF_ROOT . '/includes/partials/record-picker.php';
    ?>
    <div class="field-error" data-error-for="customer_id"></div>
</div>
```

**Required variables for the partial:**
| Variable | Description |
|----------|-------------|
| `$pickerConfig` | Array — see keys below |
| `$pickerOnPicked` | Alpine expression for `@record-picked.window` — must set `form.field_id` |
| `$pickerOnCleared` | Alpine expression for `@record-cleared.window` — must clear `form.field_id` |
| `$pickerError` | Alpine expression for `is-invalid` class (use `'false'` if no inline error) |

**`$pickerConfig` keys:**
| Key | Required | Notes |
|-----|----------|-------|
| `endpoint` | ✅ | Full URL — use `base_url('api/v1/...')` |
| `searchParam` | ✅ | Usually `'search'` or `'q'` |
| `resultKey` | ✅ | Usually `'items'` (from `json_paginated`) |
| `perPage` | — | Default 10. Cap at 20 for large datasets |
| `extraParams` | — | Static query string to append (e.g. `'status=active'`) |
| `placeholder` | — | Input placeholder text |
| `mapResult` | ✅ | JS arrow function string returning `{ id, label, sublabel, raw }` |
| `initialId` | — | Edit mode: current FK value. Set from PHP. |
| `initialLabel` | — | Edit mode: display text for the current selection. Set from PHP. |

### Step 3 — Add the error slot

Always add this after the picker include:
```html
<div class="field-error" data-error-for="customer_id"></div>
```
`FF_Validate.field(form, 'customer_id', ...)` writes to this slot. `FF_Validate.applyApi()` (server errors) also writes to it.

### Step 4 — Rewrite the change handler

The old `onChange` handler reads DOM data attributes from the selected option:
```js
onCustomerChange() {
    const sel = document.getElementById('customer_id');
    const opt = sel.options[sel.selectedIndex];
    this.form.currency = opt.dataset.currency;  // ❌ DOM data-attr pattern
    ...
}
```

Replace with a handler that reads from the raw API record:
```js
onCustomerPickerSelected(raw) {
    if (!raw) return;
    this.form.currency      = raw.currency      || 'CAD';
    this.form.billing_cycle = raw.billing_cycle || 'monthly';
    // ... etc
}
```

**API fields for side-effect handlers:** If the existing handler reads fields not currently in the API response, add them to the API. Examples added for this batch:
- `api/v1/customers/index.php` gained: `mileage_unit`, `billing_cycle`, `gst_exempt`, `pst_exempt`, `discount_type`, `discount_value`
- `api/v1/equipment/units/index.php` gained: `samsara_linked` (bool derived from `samsara_vehicle_id`)

### Step 5 — Handle pre-population on edit forms

For edit forms, the picker must show the currently-selected record on load.

**Pattern A (label from PHP — most forms):**
```php
// PHP
$preLease = db_row("SELECT id, contract_number FROM leases WHERE id = ? ...", [$leaseId]);
$pickerConfig['initialId']    = $lease['lease_id'];
$pickerConfig['initialLabel'] = $preLease['contract_number'];
```
The picker renders with the label pre-filled and the `is-selected` state set. `@record-picked` fires on load automatically from `initialId`.

**Pattern B (no side effects — simple ID-only fields):**
If the `@record-picked` handler only sets `form.field_id` with no other side effects, you can skip the secondary PHP lookup and just set `form.field_id` directly in the Alpine form state (server-rendered JSON) — the picker will need an `initialLabel` to display correctly, but functionally it submits the right value.

---

## Common patterns

### Large datasets (200+ items)
Keep `perPage: 10` and let the open-on-focus initial load show the first 10. The search narrows from there. Do NOT preload all items client-side.

### Status filtering
Use `extraParams` to filter to relevant records:
- Customer: `'status=active'` for forms that only accept active customers
- Equipment Unit: `'status=available'` for forms that only accept available units
- Leave blank to show all non-deleted records

For "available-only" pickers, add a hint explaining the filter:
```html
<div class="form-hint">Showing available units only.
    <a href="<?= base_url('equipment') ?>" target="_blank">View all →</a>
</div>
```

### Fields with heavy side effects (rate lookup, period auto-fill)
When the old `onChange()` made additional API calls (e.g., `_lookupRates()`), keep those calls intact in the new picker handler. The change is only in how the initial record data arrives (raw object vs DOM data attributes).

### Lazy-loaded full context
When the picker's API doesn't return all the data the handler needs (e.g., complex correlated subqueries), use a two-phase approach:
1. `@record-picked`: set immediate UI state from `raw` (basic fields)
2. Call a secondary API (e.g., `api/v1/leases/show.php?id=N`) to get full context
3. Populate odometer, period dates, etc. from the show API response

---

## What NOT to do

- **Don't keep the PHP `db_select()` preload** after the select is replaced — it's dead code.
- **Don't use `document.getElementById('field_id').options[...]`** in the handler — the DOM select no longer exists.
- **Don't change `validate()` logic** — just ensure the `data-error-for` slot exists.
- **Don't add `name="field_id"` as a hidden input** — the form submits from Alpine state, not DOM inputs.
- **Don't set `loadInitial: false`** unless open-on-focus would be confusing (e.g., a field where you almost always type rather than browse).

---

## Progress

| Batch | Session | Status | Fields converted |
|-------|---------|--------|-----------------|
| 1 — Leases + Invoices | S-DROPDOWN-RETROFIT-1-LEASES-INVOICES | ✅ DONE | Customer (leases/create), Equipment Unit (leases/create), Lease (invoices/create) — 3/38 |
| 2 — Equipment Unit (4 forms) | S-DROPDOWN-RETROFIT-2-EQUIPMENT | ✅ DONE | Equipment Unit on Damage Claims/Create, Maintenance WOs/Create, Inspections/Create, Mileage Logs/Create — 4 fields; vanilla-JS hidden-input bridge for Mileage Logs — 7/38 |
| 3 — Remaining selector fields | TBD | — | Lease + Inspector User (inspections); Lease + Equipment (mileage); Vendor + Assigned To (WOs/Damage Claims); Customer fields on various forms; Equipment Create yard; QBO Mapping; Accounting per-line pickers |

*Total: 38 selector-only fields. 7 converted. 31 remaining.*

---

*Generated by S-DROPDOWN-RETROFIT-1-LEASES-INVOICES. Playbook is the canonical reference for all subsequent retrofit batches.*
