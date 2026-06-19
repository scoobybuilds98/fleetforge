<!-- equipment-module-audit workflow (90 agents). Raw 107 -> canonical 62 -> confirmed 58 / refuted 4. Date 2026-06-20. Scope: core equipment (units+templates+compliance+tracking+payoff). Inspections + maintenance deferred. -->

# FleetForge Audit — Equipment Module (Units, Templates, Compliance, Tracking)

The equipment module's security perimeter holds (parameterized queries, serve-time financial redaction on the unit endpoints, server-enforced state machine), but the audit surfaced **47 confirmed findings** concentrated in four clusters: **(1) the `FF_Api.post`-resolves-on-failure family** — frontend handlers (compliance grid, tracking sync, status-log) that mutate UI or NULL cells on rejected saves; **(2) a compliance-clock fault line** — UTC `CURDATE()` vs Vancouver-local `date()` vs browser-UTC, plus inclusive/exclusive boundary and population-filter disagreements that make tiles, grid, and reports contradict each other on a regulatory surface; **(3) check-then-write races** — unlocked soft-delete (single + bulk) and an unguarded `on_lease→available` transition that orphan leases or double-book units; and **(4) the `payoff.php` financial-display divergences** — draft-inclusion, missing CAD conversion, and absent serve-time redaction on the most money-sensitive equipment page. Counts: **0 Critical, 5 High → (1 escalated, mixed verdicts), 9 Medium, 23 Low, 10 Info** (final severities below reflect verifier adjustments). Headline items to fix first: E02 (compliance silent data loss), E11/E12 (soft-delete orphan races), E13 (uncontrolled lease decoupling), E14 (template-rate leak to dispatchers), E15/E16 (payoff revenue policy violations).

---

## Critical

_None._

---

## High

### [HIGH] Compliance grid `saveUpdate()` does not gate on `resp.success` — a rejected save silently NULLs the grid cells and closes the modal as if saved
**File:** `app/admin/compliance/index.php:729-760` (writes 745-746, close 750)

```js
const resp = await FF_Api.post('.../compliance/update', {...});
this.rows[idx][docType+'_from']   = resp.data?.from_date   ?? null;
this.rows[idx][docType+'_expiry'] = resp.data?.expiry_date ?? null;
this.modal.open = false;          // no `if (resp.success)` gate
// FF_Api.post (app.js:192-199) ends in `return res.json()` — no res.ok check
```

**Why:** Operator-facing silent data loss + false success on a safety/regulatory screen. `FF_Api.post` resolves (does not reject) on HTTP 422/404, and `compliance/update.php` returns 422 VALIDATION_ERROR (e.g. an impossible date like 2026-02-30) and 404 NOT_FOUND (unit soft-deleted by another session). On a rejected save `resp.data` is undefined, so both displayed CVI/registration dates blank to `null` and the modal closes signalling success while the DB keeps the old values — and the now-blank cell invites a destructive re-save. The only error branch is `catch()`, which cannot fire on a resolved 4xx. (Note: the 409 STALE_DATA path is inert — optimistic locking is disabled app-wide — but the 422/404 paths are not gated by that flag and remain fully reachable.)
**Fix:** Immediately after the await, before touching `this.rows`/`modal.open`: `if (!resp.success) { this.modal.error = resp.error?.message || 'Failed to save.'; return; }`. Only apply `resp.data` and call `loadKpis()` when `resp.success`. Mirror the success-gated pattern in equipment create/show handlers.
**Verification:** Save a compliance date the server rejects (impossible date, or a unit deleted in another session); confirm the cell does NOT blank, the modal stays open, and the server's error message is shown.

_(One of two verifiers adjusted this to medium, noting native `type="date"` inputs and disabled optimistic locking narrow the trigger surface; the other confirmed high because the 422/404 paths remain reachable on a regulatory screen with silent data loss. Retained at high.)_

---

## Medium

### [MEDIUM] Status Log tab calls a non-existent API endpoint (`status-log.php` 404s) — tab always shows "No status changes recorded"
**File:** `app/admin/equipment/show.php:2465` (loadStatusLog); rendered 1477-1530

```js
async loadStatusLog() {
    this.loadingLog = true;
    try {
        const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/status-log') ?>?unit_id=<?= $unitId ?>');
        if (r.success) this.statusLog = r.data.items ?? r.data;
    } catch(e) { /* non-fatal */ }   // swallows the HTML-404 res.json() reject
    this.loadingLog = false;
},
// api/v1/equipment/units/ has: bulk_delete, bulk_update_status, create, delete,
// index, show, update, update_status — NO status-log.php
```

**Why:** The Status Log tab on the unit command-center page is dead: the GET resolves to a router HTML 404, `res.json()` rejects, and the empty `catch` swallows it, so `statusLog` stays `[]`. No endpoint anywhere READs `equipment_status_log` for output (grep for any `FROM equipment_status_log` returns zero), so the tab shows "No status changes recorded" for every unit regardless of real history — a false "no history" signal during compliance/audit review. (Pre-existing CODEX 2026-05-26 finding, still unfixed.)
**Fix:** Create `api/v1/equipment/units/status-log.php` (GET, `require_permission('equipment','view')`, `clean_int` unit_id, soft-delete-safe parameterized SELECT from `equipment_status_log` JOIN `users` for `changed_by` name, `ORDER BY changed_at DESC`, `json_success(['items'=>...])`), OR fold the log into `units/show.php`'s response and read `this.unit`.
**Verification:** Open a unit with known `equipment_status_log` rows; confirm the tab renders them. Status changes also remain visible via `audit_log` as an alternate operator surface.

### [MEDIUM] Compliance expired/expiring classification splits across UTC (`CURDATE`) and Vancouver-local (PHP `date`) — grid list and KPI tiles disagree by a day each evening
**File:** `api/v1/compliance/index.php:73-83`; `kpis.php:25-50`; `reports/compliance.php:42`

```php
// includes/db.php:66 — connection pinned to UTC
PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
// compliance/index.php:73 (grid list) — UTC
eu.cvi_expiry < CURDATE()
// kpis.php:25 (tiles) — Vancouver-local, bound as param
$today = date('Y-m-d');   // config/app.php:148 date_default_timezone_set('America/Vancouver')
```

**Why:** Compliance is a regulatory surface where the dashboard is the single source of truth for what's overdue. The grid-list filters use `CURDATE()` (UTC date) while the KPI tiles, CSV export, and reports use PHP `date()` (local date). During the Vancouver evening (~16:00–24:00 PT, after UTC rolls over), the "Expired Documents" tile count and the grid it drills into show different numbers, and a unit whose CVI expires exactly today flips expired/not-expired purely on server-vs-DB clock. The frontend compounds this with a third clock (`toISOString()` UTC at line 682).
**Fix:** Standardize on company-local date across the cluster: replace `CURDATE()` in `compliance/index.php:73,82` with a bound `$today = date('Y-m-d')` (as kpis/reports already do), and fix the frontend `expiryStatus()`/cell-color to compute "today" in company tz (e.g. `window.FF_TIMEZONE`) rather than `toISOString()`.
**Verification:** Add a regression smoke asserting all four surfaces (grid, tiles, CSV, report) agree for a document with `expiry == today`, run during the evening UTC-rollover window.

### [MEDIUM] Soft-delete is a TOCTOU race: status checked unlocked, then unconditional UPDATE can soft-delete a unit that just became reserved/on_lease (orphans the lease)
**File:** `api/v1/equipment/units/delete.php:40-44` (unlocked read) → `70-73` (unconditional UPDATE)

```php
db_row("SELECT ... WHERE id=? AND deleted_at IS NULL");   // 40-44, no FOR UPDATE
// guards on snapshot (51-64) ...
UPDATE equipment_units SET deleted_at=NOW(), updated_by=? WHERE id=?;  // 71-72, no status/deleted_at predicate, no lock
```

**Why:** Status is read with a plain `db_row` (no `FOR UPDATE`) outside the txn; the on_lease/reserved guards run on that snapshot; then the soft-delete UPDATE fires with no status predicate, no `deleted_at IS NULL` predicate, and no row lock. `leases/create.php` takes `FOR UPDATE` and transitions available→reserved; in the window between read and write a concurrent create/activate can flip the unit and the delete still soft-deletes it. The leases FK is `ON DELETE SET NULL`, but a soft-delete is an UPDATE and FK actions don't fire on UPDATE — so the new reservation/lease is left pointing at a `deleted_at` unit (the exact orphan the reserved-guard was meant to prevent). Optimistic locking being disabled removes any incidental defense. Compare `StatusActions.php:44-48` and `bulk_update_status.php:125-130`, which re-SELECT `FOR UPDATE` inside the txn.
**Fix:** Open the txn first, `SELECT ... WHERE id=? AND deleted_at IS NULL FOR UPDATE`, re-evaluate guards on the locked row, then UPDATE; or make the UPDATE conditional `... WHERE id=? AND deleted_at IS NULL AND status NOT IN ('on_lease','reserved')` and treat 0 affected rows as a 409.
**Verification:** Concurrency test: interleave a unit delete with a lease create/activate on the same unit; assert the delete either blocks or the lease wins, never both.

### [MEDIUM] Bulk delete has the same unlocked check-then-delete race as `delete.php` (× up to 100 ids)
**File:** `api/v1/equipment/units/bulk_delete.php:68-72` (unlocked read) → `104-107` (unconditional UPDATE)

```php
// per-id db_row (68-72), on_lease check (81), reserved check (91) on snapshot
db_transaction(function() use (...) {
    UPDATE equipment_units SET deleted_at=NOW(), updated_by=? WHERE id=?;  // 105-106, no FOR UPDATE, no predicate
});
```

**Why:** Same orphan-lease consequence as E11, multiplied across up to 100 ids. Per-id status is fetched with a plain `db_row` outside the txn; guards run on that snapshot; the soft-delete UPDATE has no `FOR UPDATE` re-read and no status/`deleted_at` predicate. Real concurrent writers (lease create→reserved, activate→on_lease, reservation create→reserved) can flip a unit in the window, after which bulk_delete still soft-deletes it. Sibling `bulk_update_status.php:125-130` re-SELECTs `FOR UPDATE` — the correct pattern already exists in the same directory.
**Fix:** Move the SELECT inside the per-id `db_transaction` with `FOR UPDATE` and re-check guards under lock, OR make the UPDATE conditional `... WHERE id=? AND deleted_at IS NULL AND status NOT IN ('on_lease','reserved')` and count 0-affected as a skip with reason.
**Verification:** Concurrency test interleaving bulk_delete with a lease create on one of the targeted ids; assert no orphan lease results.

### [MEDIUM] `on_lease → available` transition reachable from operator status endpoints with no open-lease guard — decouples a unit from its still-active lease (double-book hazard)
**File:** `lib/AI/Actions/StatusActions.php:26` (transition map) + `74-79` (UPDATE); also `bulk_update_status.php:89,149,159-164`

```php
// StatusActions.php:26
'on_lease' => ['available', 'maintenance'],
// validates against map only (61), then writes status (74-79) — NO active-lease check
```

**Why:** `UNIT_STATUS_TRANSITIONS` allows `on_lease→available` and `changeEquipmentStatus` validates only against the map, never checking whether an active lease still references the unit; the bulk path replicates this. A manager (single or bulk) can flip an `on_lease` unit to `available` while `leases.status` is still `active`, bypassing `close.php` — the canonical `on_lease→available` path that sets `leases.status='completed'`, writes `equipment_status_log` with `lease_id`, and runs billing/overshoot reconciliation. The unit then shows free for re-rental while the lease stays active and uninvoiced-at-close — a double-book / billing-integrity hazard, and `close.php` later expects `old_status='on_lease'`. (Prior CODEX 2026-05-26 MEDIUM; refactored into StatusActions but the guard was never added, and the bulk endpoint inherited the gap.)
**Fix:** When transitioning a unit OUT of `on_lease` via the operator endpoint (single + bulk), require that no lease with `status='active'` references this `equipment_unit_id` (else throw INVALID_TRANSITION / skip with reason directing the user to close the lease). Reserve `on_lease→available` for `close.php`.
**Verification:** Attempt `on_lease→available` (single + bulk) on a unit with an active lease; confirm it is rejected and the lease/close path still works.

### [MEDIUM] Equipment template default rate card (daily/weekly/monthly/mileage) exposed to dispatchers who are denied the rates module and financials
**File:** `api/v1/equipment/templates/index.php:127-130` (also `show.php:69-72`)

```php
// returns default_daily_rate/default_weekly_rate/default_monthly_rate/default_mileage_rate
// gate is only require_permission('equipment','view') — NO can_view_financials()/redact_rows()
```

**Why:** Dispatcher has `equipment:view=true` but `rates:view=false` and `payments:view=false` (`config/permissions.php`), so the rates module (canonical home of pricing) is invisible to them and equipment-unit money fields ARE redacted in `units/index.php`/`show.php` — yet these template endpoints leak the full per-category default pricing, letting a dispatcher enumerate every equipment type's rate sheet. Under-redaction inconsistency vs the rates-module gate and the shipped unit-endpoint redaction (`units/index.php:180-181`, `units/show.php:205-206`). (Prior CODEX 2026-05-26 flagged this CRITICAL across unit + template endpoints; the unit half was remediated, the template half left unfixed.)
**Fix:** After building `$items` (index) / before `json_success` (show): `if (!can_view_financials()) { $items = redact_rows($items, ['default_daily_rate','default_weekly_rate','default_monthly_rate','default_mileage_rate']); }` (`redact_keys` for show). Keep specs/dimensions/intervals/category. Confirm with operator whether template default rates count as financial.
**Verification:** Authenticate as a dispatcher and GET both endpoints; confirm the four `default_*_rate` fields are absent/redacted.

### [MEDIUM] `payoff.php` gross/net revenue includes DRAFT (unsent) invoices — diverges from the SEND-only `total_revenue` counter and the reporting policy
**File:** `app/admin/equipment/payoff.php:119-126` (hero totalRevenue), `163-164` (revenue-by-lease), `182` (monthly revenue)

```sql
... AND i.status NOT IN ('void','written_off') AND ili.is_credit = 0   -- 'draft' NOT excluded
```

**Why:** All three revenue queries filter only `status NOT IN ('void','written_off')` and do NOT exclude `'draft'`. The `equipment_units.total_revenue` counter is booked ONLY at invoice SEND, and the operator-locked reporting policy (2026-06-13) is explicit: exclude written_off/void/draft from revenue everywhere (reports + analytics + dashboard must agree). So Gross/Net Revenue, Still-to-Recover, Progress, per-lease revenue, and Monthly P&L count drafts as recognized revenue — overstating recovery vs the equipment list/show pages and letting a draft make a unit look "paid off" before any invoice is sent. The payoff API (`api/v1/accounting/fixed_assets/payoff.php`) has the same omission.
**Fix:** Add `AND i.status NOT IN ('void','written_off','draft')` to all three queries (and both payoff-API queries) so the page agrees with the `total_revenue` counter and the policy.
**Verification:** Create a draft invoice on a unit's lease; confirm payoff Gross/Net Revenue does not move until the invoice is sent.

### [MEDIUM] `payoff.php` revenue/cost aggregates not converted to CAD — USD invoices summed at face value
**File:** `app/admin/equipment/payoff.php:120` (totalRevenue), `160` (revenue-by-lease), `177` (monthly revenue)

```sql
SUM(ili.amount)   -- no multiplication by i.exchange_rate_to_cad
```

**Why:** Revenue is summed without multiplying by the invoice's `exchange_rate_to_cad`. The operator-locked policy requires converting all money aggregates to CAD so reports/analytics/dashboard agree. For any unit leased under a USD invoice the page adds USD to CAD as if 1 USD = 1 CAD, mis-stating Gross/Net Revenue, Still-to-Recover, per-lease contribution, and Monthly P&L on the most money-sensitive equipment page. Every offending query already JOINs `invoices i`, so the conversion column is in scope.
**Fix:** Multiply `ili.amount` by the canonical CAD-conversion expression keyed on `i.currency`/`COALESCE(i.exchange_rate_to_cad,1)` in the revenue sums, matching the reports module; apply the same fix in the payoff API (~lines 228/294).
**Verification:** Seed a USD invoice with `exchange_rate_to_cad` ≠ 1 on a unit; confirm payoff totals equal the CAD-converted amount, not the face value.

### [MEDIUM] Distance-log create: scientific-notation distance passes `is_numeric` then throws `ValueError` in `bccomp` → 500 instead of 422
**File:** `api/v1/equipment_units/distance_logs/create.php:72`

```php
} elseif (!is_numeric($distance) || bccomp($distance, '0', 2) < 0) {
// is_numeric('1e9') === true → short-circuits past, bccomp('1e9','0',2) throws ValueError on PHP 8+
```

**Why:** The `is_numeric` short-circuit accepts scientific-notation strings (`'1e9'`, `'1e10'`), which then reach `bccomp`, which throws `ValueError: not well-formed` (verified on this repo's PHP 8.4.20). The throw is uncaught → global handler → HTTP 500 INTERNAL_ERROR (leaking the bcmath internal string to super_admin). The distance field on `show.php:590` is `type=text` and POSTed as `String(distEditValue)` (`show.php:3000`), so a user typing `1e9` triggers this through the normal UI — a value the endpoint's own `is_numeric` guard treats as valid throws an unhandled exception and the save silently fails with no field-level message.
**Fix:** Reject exponent/hex/leading-`+` forms before `bccomp`, e.g. `} elseif (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $distance)) { $errors['distance']='distance must be a non-negative decimal (max 2 dp).'; }` (also caps integer digits for the column), or use `clean_non_negative_decimal()`. Do NOT rely on `is_numeric` alone before `bccomp`.
**Verification:** POST `distance=1e9`; confirm a 422 field error, not a 500.

---

## Low

### [LOW] `saveUpdate()` catch reads `e.message` but `FF_Api` never rejects with a server message — error banner unhelpful on the real failure path
**File:** `app/admin/compliance/index.php:755-756`

```js
catch (e) { this.modal.error = e.message || 'Failed to save. Please try again.'; }
// FF_Api.post resolves (not rejects) on 422/409/500, so this catch never sees the server message;
// resp.error.message is never surfaced
```

**Why:** Even after the E02 success-gate fix, the server's actual `error.message` lives in `resp.error.message`, which the code ignores; the `catch` only fires on network/parse failures where `e.message` is a generic "Failed to fetch." Compounds the silent-failure UX on a compliance screen.
**Fix:** In the new `if (!resp.success)` branch (E02), set `this.modal.error = resp.error?.message || resp.message || 'Failed to save.';`. Keep the catch for true network errors.
**Verification:** Trigger a server-side validation failure; confirm the server's specific reason is shown.

### [LOW] "Expired" boundary semantics differ: UI cell/CSV treat `expiry==today` as expired (`<=`) while backend KPI/filter treat it as still-valid (`<`) — same-day mismatch independent of timezone
**File:** `app/admin/compliance/index.php:684` vs `kpis.php:37` + `compliance/index.php:73`

```js
if (date <= today) return 'expired';   // frontend expiryStatus() (684) + CSV (104) — inclusive
```
```sql
cvi_expiry < ?            -- kpis.php:37  (strict <)
cvi_expiry < CURDATE()    -- grid expired_only, compliance/index.php:73 (strict <)
```

**Why:** A document expiring exactly today is painted RED and labelled "Expired" in the grid cell and CSV, yet is not counted by the `expired_count` KPI tile nor returned by the `expired_only` drill-down — red cells won't reconcile with the tile count or filter result. Distinct from E04: this is the inclusive (`<=`) vs exclusive (`<`) boundary, present even when timezones agree.
**Fix:** Choose one convention and apply it across `expiryStatus()` (684), CSV (104), `kpis.php` (37/47), reports, and the grid `expired_only` filter (73). Recommend `expired = expiry < today` (valid through end of expiry day); document the rule in a shared comment.
**Verification:** Set a unit's CVI to today; confirm the cell, CSV, tile count, and drill-down all agree.

### [LOW] `reports/compliance.php` `ok_count` double-subtracts units that have one expired doc AND another doc expiring within 90 days
**File:** `api/v1/reports/compliance.php:60-94` (ok_count formula at 94)

```php
// expired_count (60-61): unit counted if ANY doc < today
// expiring_90_count (70-75): unit counted if ANY doc BETWEEN today AND today+90
$okCount = max(0, $totalUnits - $expiring90Count - $expiredCount);   // 94 — overlapping buckets
```

**Why:** The two unit-level OR buckets are not mutually exclusive: a unit with an expired CVI and a registration expiring in 60 days is counted in both and subtracted twice, understating "OK" whenever units have a mixed expired+expiring profile (common in a real fleet). `max(0,...)` only prevents a negative; the four KPI tiles then won't sum to `total_units`.
**Fix:** Compute `ok_count` from a single mutually-exclusive per-unit classification in SQL, e.g. `SUM(CASE WHEN <no doc expired> AND <no doc within 90d> THEN 1 ELSE 0 END)`, rather than subtracting two overlapping buckets.
**Verification:** Seed a unit with one expired doc + one doc expiring in 60 days; confirm `ok_count` and the tile sum reconcile to `total_units`.

### [LOW] `reports/compliance.php` excludes only 'decommissioned' while compliance index/kpis also exclude 'inactive' — same units classified inconsistently across the two surfaces
**File:** `api/v1/reports/compliance.php:61,63,70,77,114,124,135,211,257,304`

```sql
-- reports/compliance.php (throughout)
AND status != 'decommissioned'
-- compliance index.php:50 / kpis.php:30,35,45
status NOT IN ('inactive','decommissioned')
```

**Why:** Reports>Compliance (excludes only decommissioned) and the Compliance grid/tiles (exclude both inactive and decommissioned) operate over different unit populations, so an inactive unit with an expired CVI appears in Reports KPIs/timeline but not in the Compliance grid or its tiles. An operator reconciling the two sees a discrepancy attributable purely to whether 'inactive' units are included.
**Fix:** Decide one policy for inactive units and apply it to both surfaces (add 'inactive' to the `NOT IN` lists in `reports/compliance.php`, or drop it from `index.php:50` and `kpis.php`).
**Verification:** Set a unit `inactive` with an expired CVI; confirm both surfaces agree on whether it is counted.

### [LOW] `compliance/update.php` accepts `from_date` later than `expiry_date` (no cross-field ordering validation) — stores an inverted validity window
**File:** `api/v1/compliance/update.php:57-74, 106-112`

```php
// each date validated independently (preg + checkdate), 60-71
db_update('equipment_units', [$expiryCol=>$expiryDate, $fromCol=>$fromDate], 'id = ?', [$unitId]);  // 106-112, no ordering check
```

**Why:** No `from_date <= expiry_date` check, so an operator can save `from=2027-01-01 / expiry=2025-01-01`. The model treats `*_from_date` as validity start and `*_expiry` as end; an inverted window produces nonsensical "days overdue"/"days left" DATEDIFF math downstream. Data-quality gap; no crash or security impact.
**Fix:** When both present: `if ($fromDate && $expiryDate && strtotime($fromDate) > strtotime($expiryDate)) json_error('VALIDATION_ERROR','from_date must be on or before expiry_date.',422);` before the transaction; mirror client-side in `saveUpdate()` (modal at `admin/compliance/index.php:543-567`).
**Verification:** POST `from_date` after `expiry_date`; confirm 422.

### [LOW] `tracking/index.php` `syncAllNow()` does not gate on `.success` — a failed/partial sync silently swallows the error and refreshes as if it worked
**File:** `app/admin/tracking/index.php:563-572`

```js
async syncAllNow(){ this.syncing=true; try { await FF_Api.post(SYNC_URL,{}); await this.refresh(); } catch(e){ this.error='Sync request failed.'; } this.syncing=false; }
// POST result discarded; sync.php returns {synced_count, failed_count, ...} but always 200 even on per-unit failures
```

**Why:** No `if (res.success)` check; `FF_Api.post` resolves on 4xx/422, so a 403 CSRF/permission rejection, a SAMSARA_SYNC_FAILED body, or a 200 with `failed_count>0` are all swallowed and `refresh()` runs as if the sync succeeded. The operator gets no signal that the sync was rejected or that N units failed. Sibling `importFromSamsara()` correctly gates on `res.success`. No data corruption (refresh re-fetches authoritative state).
**Fix:** `const r = await FF_Api.post(SYNC_URL,{}); if (!r.success){ this.error = r.error?.message || 'Sync failed.'; this.syncing=false; return; }` and optionally warn when `r.data?.failed_count > 0` — mirror `importFromSamsara()`.
**Verification:** Force a sync rejection (revoke CSRF/permission); confirm an error is shown, not a silent refresh.

### [LOW] `show.php` confirmDeleteDoc reads `r.message` instead of `r.error.message` — failed document deletes always show the generic fallback
**File:** `app/admin/equipment/show.php:2760`

```js
FF_Toast.error(r.message || 'Could not remove document.');   // r.message is always undefined
```

**Why:** The error envelope is `{success:false, error:{code, message}}`, so `r.message` is always undefined and the server's real error never surfaces on a genuine delete failure — the user always sees the bland fallback. Every other handler in this file uses `r.error?.message`. Functionally minor (the `.success` gate at 2757 still prevents removing the row), but degrades error reporting and is inconsistent.
**Fix:** `FF_Toast.error(r.error?.message || 'Could not remove document.')`.
**Verification:** Force a delete failure; confirm the server reason is shown.

### [LOW] `show.php` Delete Unit button shown for 'reserved' units even though the endpoint rejects them (422 UNIT_RESERVED)
**File:** `app/admin/equipment/show.php:216`

```php
can('equipment','delete') && $unit['status'] !== 'on_lease'   // delete.php blocks on_lease AND reserved
```

**Why:** The hide-rule mirrors only one of the two blocked statuses, so a reserved unit renders an enabled Delete button; the click POSTs and the server returns 422 with a toast. State machine is correctly enforced server-side (no security gap), but the UI offers an action it knows will fail.
**Fix:** `can('equipment','delete') && !in_array($unit['status'], ['on_lease','reserved'], true)`.
**Verification:** Open a reserved unit; confirm the Delete button is hidden.

### [LOW] `show.php` Leaflet map popup builds raw HTML from `unit_number` + Samsara address without escaping — defense-in-depth XSS sink, inconsistent with the tracking page
**File:** `app/admin/equipment/show.php:2906-2918`

```js
popupHtml = `...<strong>${unitNum}</strong><br>...${addr}...`;
marker.bindPopup(popupHtml);   // Leaflet renders popup content as HTML; addr = unit.samsara_last_location_address
```

**Why:** The only un-escaped innerHTML sink on the page (everything else uses Alpine `x-text`). `unit_number` passes `clean_string()`/strip_tags at write and the address is Samsara-sourced, so practical exploitability is low — but the sibling tracking page routes the SAME fields through `esc()` (`tracking/index.php:698-703`), so `show.php` relies on an upstream sanitizer that could change. Stored-XSS-class sink.
**Fix:** Add an `esc()` helper to `FF_UnitDetail` (mirror tracking) and wrap `${esc(unitNum)}`/`${esc(addr)}`, or pass a DOM element to `bindPopup`.
**Verification:** Set a unit_number/address containing `<img onerror>`; confirm it renders inert.

### [LOW] `show.php` Alpine double-init runs `loadUnit()` twice → duplicate unit GET + two stacked `activeTab` `$watch` listeners
**File:** `app/admin/equipment/show.php:325` (`x-init="init()"`), `2399-2401` (init), `2455-2459` (`$watch`)

```html
<div x-data="FF_UnitDetail()" x-init="init()">
```
```js
async init(){ await this.loadUnit(); }   // Alpine 3.15.12 auto-invokes init() AND x-init re-invokes it
```

**Why:** The documented repo-wide double-init trap. `loadUnit()` has no idempotency guard, so it fires `FF_Api.get(.../units/show)` twice and registers TWO `$watch('activeTab',...)` handlers; every tab switch then runs `_onTabEnter` + `FF_TabHash.onSwitch` twice. Mostly de-duped by lazy-load guards and idempotent `onSwitch`, so no corruption — but wasted work and a latent double-side-effect.
**Fix:** Drop the redundant `x-init="init()"` (Alpine 3 auto-calls component init()), OR add `if (this._inited) return; this._inited = true;` (declare `_inited:false`).
**Verification:** Watch the network tab on page load; confirm a single `units/show` GET.

### [LOW] `index.php` client compliance 'Expiring' flag parses date-only strings as UTC midnight — off-by-one mislabel in negative-offset timezones
**File:** `app/admin/equipment/index.php:657-664`

```js
new Date(unit[f]).getTime() <= soon   // unit[f] is 'YYYY-MM-DD' → parsed as UTC midnight; soon is local
```

**Why:** `new Date('2026-07-19')` is parsed as UTC midnight while `Date.now()/soon` are local, so in PST/PDT a date exactly 30 days out can flip across the `<=` boundary and a 'today' expiry is treated as already-past. The badge is purely advisory (no write); authoritative state lives on `compliance/index.php` and the detail page.
**Fix:** Parse date-only in local time (`new Date(y, m-1, d)`) or compare ISO date strings lexically against a locally-computed `soon` ISO date.
**Verification:** Set a unit's expiry to exactly 30 days out; confirm the badge matches the detail page in a negative-offset tz.

### [LOW] `index.php` `$_GET`-seeded filter values HTML-escaped but not JS-string-escaped where injected into a single-quoted JS literal
**File:** `app/admin/equipment/index.php:462-463`

```php
search: '<?= htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES) ?>',   // inside a single-quoted JS string in <script>
```

**Why:** `ENT_QUOTES` neutralizes the practical breakouts (`'`→`&#039;`, `</script>` encoded), so the residual is only a trailing backslash that escapes the closing quote — a syntax breakage at worst, and only in the attacker's own querystring (self/reflected). Still worth hardening since `json_encode` is the context-correct primitive for a JS-string literal.
**Fix:** `search: <?= json_encode($_GET['search'] ?? '') ?>` (drop the hand-written quotes).
**Verification:** Pass `?search=a\` and confirm no JS syntax error / correct value binding.

### [LOW] `units/index.php` docblock claims FULLTEXT search but code uses leading-wildcard LIKE — unused index + full table scan at scale
**File:** `api/v1/equipment/units/index.php:13, 70-74`

```php
$like = '%' . $search . '%';
'(u.unit_number LIKE ? OR u.vin LIKE ? OR u.gps_device_id LIKE ? OR u.license_plate LIKE ?)'
// schema: FULLTEXT KEY idx_ft_units (unit_number,vin,gps_device_id,license_plate) — never used
```

**Why:** The docblock advertises FULLTEXT but the code runs a leading-wildcard LIKE that cannot use any index, so every search is a full scan of `equipment_units` joined to templates. The FULLTEXT index is dead weight and the docblock is misleading (it also omits `gps_device_id`, which the LIKE includes).
**Fix:** Either correct the docblock to "LIKE substring" and drop the unused FULLTEXT index, or switch to `MATCH(...) AGAINST(? IN BOOLEAN MODE)` (note: FULLTEXT loses mid-string partial matches operators rely on — keeping LIKE + fixing the docblock is lower-risk).
**Verification:** N/A (doc/index hygiene); confirm `EXPLAIN` shows the FULLTEXT key is not used.

### [LOW] `units/index.php` array-valued query params (`search[]=x`) raise TypeError → generic 500 instead of 422
**File:** `api/v1/equipment/units/index.php:36, 39`

```php
$search = clean_string($_GET['search'] ?? null, 255);   // clean_string is function(?string $val,...) under strict_types
$yard   = clean_string($_GET['yard'] ?? null, 100);
```

**Why:** With `?search[]=a`, a non-string array is passed into the `?string`-typed `clean_string`, throwing a TypeError that the global handler returns as HTTP 500 (echoing the raw message for super_admin). Read-only GET, attacker-controllable but no data exposure/state change — just a wrong status code (500 vs 422) and noisy Sentry. `clean_int` params degrade gracefully. (Note: `$status`/`$sort`/`$dir` are also unguarded `?string` `clean_string` calls that throw identically — the fix should cover all of them.)
**Fix:** Coerce to scalar before cleaning: `clean_string(is_array($_GET['search'] ?? null) ? null : ($_GET['search'] ?? null), 255)`, or normalize `$_GET` scalars at bootstrap.
**Verification:** GET `?search[]=a`; confirm 422, not 500.

### [LOW] units create/update non-string scalar/array JSON values for string fields throw TypeError → HTTP 500 instead of 422
**File:** `api/v1/equipment/units/create.php:50,117,127-162`; `update.php:43,75,99,124-128,293,297`

```php
clean_string($body['unit_number'], 100)   // {"unit_number":123} or {"vin":[]} → TypeError under strict_types
// not a PDOException, so the local 23000 catch never sees it → global handler → 500
```

**Why:** Both files run `strict_types=1` and pass raw `json_body()` values into the `?string`-typed `clean_string`. A well-formed JSON body sending a non-string scalar or array throws a TypeError that propagates to the global handler → HTTP 500 + Sentry instead of a clean 422. Not reachable from the Alpine form (`x-model` yields strings), so low real-world risk, but noisy 500s of the same class the endpoint otherwise avoids.
**Fix:** Coerce before cleaning: `clean_string(is_scalar($body['vin'] ?? null) ? (string)$body['vin'] : null, 50)` at every raw-`$body` `clean_string` site, or change `clean_string` to a `mixed` signature with an internal `is_scalar` guard (matching `clean_int`'s tolerance).
**Verification:** POST `{"vin":[]}`; confirm 422.

### [LOW] units create/update mileage has no upper-bound clamp → out-of-range value overflows `int unsigned` → SQLSTATE 22003 → HTTP 500
**File:** `api/v1/equipment/units/create.php:75-87`; `update.php:217-230`

```php
if ($mi === null || $mi < 0) { $fields['mileage'] = 'Odometer cannot be negative.'; }  // no upper bound
// column: mileage int unsigned NOT NULL (max 4294967295); 23000 catch only matches vin/unit_number
```

**Why:** mileage is validated only for `>= 0`; a value like `9999999999` passes and overflows on INSERT/UPDATE → PDOException SQLSTATE 22003 whose message contains neither 'vin' nor 'unit_number', so the narrow 23000 catch falls through → 500. This is the exact FLEETFORGE-M overflow class the code was hardened against for `length_ft/height_ft/width_ft/weight_capacity_lbs/axle_count` (create.php:176-190) — mileage is the one numeric column the guard was not extended to.
**Fix:** Add an upper-bound check mirroring the weight guard: reject `> 4294967295` with a `$fields['mileage']` error in both files.
**Verification:** POST `mileage=9999999999`; confirm 422.

### [LOW] units create/update `owner_company_id` accepted as any int with no FK existence check → FK 1452 → HTTP 500; soft-deleted customer assignable as owner
**File:** `api/v1/equipment/units/create.php:133, 227`; `update.php:278-281`

```php
$ownerCompanyId = clean_int($body['owner_company_id'] ?? null);   // no existence check (unlike template_id)
// FK equipment_units_ibfk_2 → customers(id) ON DELETE SET NULL; FK satisfied by soft-deleted rows too
```

**Why:** A non-existent id → PDOException 23000 whose message lacks 'vin'/'unit_number', so the narrow catch re-throws → 500 (vs a clean 422). Separately, the FK is satisfied by any physically-present `customers` row including a soft-deleted one, so a unit can be pointed at a deleted owner with no validation.
**Fix:** Validate when non-null: `if ($ownerCompanyId !== null && !db_exists('customers','id = ?',[$ownerCompanyId])) $fields['owner_company_id']='Selected owner company not found.';` (`db_exists` auto-appends `deleted_at IS NULL`, closing the soft-deleted gap) in both files.
**Verification:** POST a non-existent and a soft-deleted `owner_company_id`; confirm both 422.

### [LOW] units `update.php` mass-assigns state-machine-owned `decommissioned_date` / `decommission_reason` — bypasses the status state machine
**File:** `api/v1/equipment/units/update.php:122` (`decommission_reason` in `$stringFields`), `285` (`decommissioned_date` in date loop)

```php
$stringFields = [ ..., 'decommission_reason' => 5000, ... ];           // 122
foreach (['cvi_expiry',...,'decommissioned_date'] as $f) { ... }       // 285
// update.php never writes status; StatusActions::changeEquipmentStatus is the canonical atomic writer
```

**Why:** `update.php` accepts `decommission_reason`/`decommissioned_date` as free metadata, so an `equipment.edit` user can set a decommissioned_date+reason on a live `available`/`on_lease` unit, or clear them on a genuinely decommissioned unit — none routing through `StatusActions::changeEquipmentStatus` (which also flips status, logs `equipment_status_log`, and sends the decommission notification). `show.php` renders both fields, so a live unit can show a `decommissioned_date` with no corresponding status, log entry, or notification. Not privilege escalation (caller has edit), but desyncs state-machine fields from status.
**Fix:** Remove `decommission_reason` from `$stringFields` and `decommissioned_date` from the date-field loop; let `StatusActions::changeEquipmentStatus` be the sole writer. If operators must amend a reason, add a narrow path gated on `status='decommissioned'`.
**Verification:** PATCH `decommissioned_date` on an `available` unit; confirm it is rejected/ignored.

### [LOW] units `update.php` always re-submits prefilled mileage → silently reverts concurrent Samsara/GPS odometer updates (optlock disabled = last-write-wins)
**File:** `app/admin/equipment/edit.php:315` (prefill) + `419` (full-form POST)

```php
mileage: <?= (int)$unit['mileage'] ?>   // 315 — prefilled int; submit() posts entire form (419); update.php writes mileage every save
```

**Why:** With optimistic locking disabled, the STALE_DATA branch never fires. If an operator opens edit, `cron/gps_mileage_sync.php` advances mileage, then the operator saves an unrelated field, the page rewrites mileage back to the stale page-load value with no warning. Equipment odometer feeds lease/billing distance, so a silent revert can mis-bill. The actionable defect is shipping a high-churn machine-owned column in the form payload — the fix is payload-shaping, compatible with the last-write-wins decision.
**Fix:** Only include mileage if the user actually edited it (dirty-tracking), or omit mileage entirely (it has a dedicated odometer-update path) and let GPS own it; at minimum don't send mileage when `tracking_provider==='samsara'`.
**Verification:** Open edit, advance mileage via the GPS path, save an unrelated field; confirm mileage is not reverted.

### [LOW] `edit.php` editing `cvi_expiry`/`registration_expiry` updates expiry but leaves `*_from_date` stale — diverges from the atomic compliance editor
**File:** `app/admin/equipment/edit.php:248, 252`

```php
// edit.php exposes/posts cvi_expiry (248), registration_expiry (252)
// update.php date-loop writes only *_expiry, never *_from_date; compliance/update.php:106-111 sets both atomically
```

**Why:** Two sanctioned editors with different invariants: the compliance grid keeps `from_date`/`expiry` coupled while `equipment/edit.php` moves expiry independently, leaving the "valid from" anchor stale. The compliance grid surfaces `*_from_date` as "Valid From" (`compliance/index.php:141,146`), so a stale from_date is operator-observable as a from/expiry pair that disagrees. No data loss; an integrity/consistency gap. _(Verifier note: the finding's cited downstream reader `reports/compliance.php:248-250` is inaccurate — the reports module reads only `*_expiry`; the real consumer is the compliance grid.)_
**Fix:** Drop the compliance-date inputs from `equipment/edit.php` (route compliance edits through the compliance editor, as MVI/Insurance were hidden), or have `update.php` clear/adjust the corresponding `*_from_date` when an expiry is edited.
**Verification:** Edit CVI expiry via `equipment/edit.php`; confirm the compliance grid's "Valid From" stays coherent (or the input is gone).

### [LOW] `edit.php` `tracking_provider='none'` does not clear `samsara_vehicle_id`, and `update.php` still PATCHes Samsara on subsequent saves
**File:** `api/v1/equipment/units/update.php:292-294, 371-389`

```php
// 292-294: clamps/writes tracking_provider but never clears samsara_vehicle_id/entity_type
// 371-389: PATCH block keys off $existing['samsara_vehicle_id'] / entity_type, independent of tracking_provider
```

**Why:** A unit shown as "None" remains Samsara-linked and still gets name/vin/year/plate changes pushed to Samsara — "disconnect tracking" via this form is a no-op at the integration layer. (Distinct from the known Samsara trailer-blind READ follow-up; this is a write/PATCH path.) No FF data corruption, but a surprising side effect.
**Fix:** When `tracking_provider` transitions to 'none', also clear `samsara_vehicle_id`/`entity_type` and skip the PATCH, or gate the PATCH on the NEW `tracking_provider` rather than only the pre-existing link.
**Verification:** Set provider to None and save an edit; confirm no outbound Samsara PATCH and the link is cleared.

### [LOW] Template create/update numeric default fields have no upper bound → DECIMAL/INT overflow aborts INSERT → 500 instead of 422
**File:** `api/v1/equipment/templates/create.php:79-99,126-135,232-258`; `update.php:133-214,325-330`

```php
// $checkPosInt/$checkNonNegDecimal/$checkPosDecimal enforce only >0/>=0, NO upper bound
// catch: if ($e->getCode() === '23000' && stripos($e->getMessage(),'slug') !== false) — doesn't cover 1264/22003
```

**Why:** Columns are tightly sized (`axle_count` tinyint 255; intervals/sort_order smallint 65535; `weight_capacity_lbs` int unsigned; rates `decimal(10,2)`/`decimal(10,4)`; dimensions `decimal(6,2)`). Under STRICT mode a fat-fingered value (axle 300, rate 1e8) passes validation, reaches `db_insert`, and raises 22003/1264; the slug-only catch lets it propagate → 500. The sibling `units/create.php:173-191` bounds the same column types citing FLEETFORGE-M — the established guard the template endpoints omit.
**Fix:** Add upper-bound checks matching column limits in both template endpoints (axle≤255; intervals/sort_order≤65535; weight≤4294967295; dimensions bccomp>'9999.99'; daily/weekly/monthly bccomp>'99999999.99'; mileage_rate bccomp>'999999.9999') emitting `$fields` errors → 422. Add `max` attributes + JS bounds in create/edit.
**Verification:** POST `default_axle_count=300`; confirm 422.

### [LOW] Template all-symbol / non-ASCII name produces an empty base slug; dedup loop yields `''` then `'-2'`
**File:** `api/v1/equipment/templates/create.php:189-206` (`update.php:87-103` mirror)

```php
$slug = preg_replace('/[^a-z0-9]+/','-', $slug);   // 191
return trim($slug,'-');                            // 192 → '' for '###' / '日本語' / '—'
```

**Why:** `make_template_slug()` slugifies symbol-only/multibyte names to `''`, which passes the `!$name` required check (clean_string keeps them). The dedup loop inserts `slug=''` first; a second all-symbol name dedups to `'-2'`, `'-3'`. Empty/leading-dash slugs violate the "URL-friendly + unique" contract. Low because slug is currently only stored/displayed (routing uses `?id=`) and the global UNIQUE prevents a hard 1062.
**Fix:** Fall back to a deterministic token when empty: `if ($slug === '') $slug = 'template';` (dedup then yields `template`, `template-2`, …), or transliterate. Apply in both files.
**Verification:** Create a template named `###`; confirm a non-empty slug.

### [LOW] Distance-log create: no upper bound on distance → DECIMAL(12,2) overflow aborts INSERT under STRICT → 500
**File:** `api/v1/equipment_units/distance_logs/create.php:70-74, 107-122`

```php
} elseif (!is_numeric($distance) || bccomp($distance, '0', 2) < 0) {   // only >= 0
// column: distance decimal(12,2) NOT NULL (max 9,999,999,999.99); db_insert has no try/catch → 22003 → 500
```

**Why:** Distance is validated only for `>= 0`; a value like `'99999999999999'` passes and overflows on INSERT → SQLSTATE 22003 → uncaught → 500. The distance box is free-text, so an operator can type an overlong number. Same overflow-becomes-500 class hardened on `units/create.php`/`update.php`; `distance_logs` was missed.
**Fix:** Add an upper-bound check after the `>=0` check: `elseif (bccomp($distance, '9999999999.99', 2) > 0) { $errors['distance'] = 'distance is too large.'; }` (the E47 regex `^\d{1,10}(\.\d{1,2})?$` enforces both digit count and scale and is the cleanest single fix for both).
**Verification:** POST an oversized distance; confirm 422.

### [LOW] Distance-log create: `period_start`/`period_end` never validated as real dates → garbage silently coerced to 1970-01-01; no start≤end ordering check
**File:** `api/v1/equipment_units/distance_logs/create.php:64-69, 99-103`

```php
// validation only checks $periodStart === ''
date('Y-m-d H:i:s', strtotime($periodStart))   // strtotime('garbage')=false → date(...,false)='1970-01-01 00:00:00'
```

**Why:** Unparseable input is accepted and stored as the epoch in the NOT NULL DATETIME columns with no error; there's also no `period_start<=period_end` check, so a reversed window is accepted. Silent data corruption of telemetry history (worse than a 500), though distance_logs are informational-only and the path is write-gated behind `equipment:edit`.
**Fix:** Validate each datetime explicitly (`$ts = strtotime($x); if ($ts === false) $errors[...]=...;`, reuse `$ts` for formatting) for period_start/end and the optional reading timestamps, and after both parse add `if (strtotime($periodEnd) < strtotime($periodStart)) $errors['period_end']='period_end must be on or after period_start.';`. Reject before `db_insert`.
**Verification:** POST `period_start=garbage`; confirm 422 (not a 1970 row).

### [LOW] Distance-log create: `warnings` array stored without per-element type validation (non-string/nested elements persisted)
**File:** `api/v1/equipment_units/distance_logs/create.php:52`

```php
$warnings = isset($body['warnings']) && is_array($body['warnings']) ? $body['warnings'] : null;   // no per-element check
```

**Why:** Only `is_array` is checked, not that elements are strings (docblock + column comment say "string array"). `warnings:[{"x":1},[1,2,3]]` is json_encoded into the `warnings` json column verbatim; the UI iterates with `x-text` (escapes, so no XSS — renders `[object Object]`). Minor data-integrity/contract drift, operator/API-controlled.
**Fix:** Coerce to strings and cap count: `array_values(array_filter(array_map(fn($w)=>is_scalar($w)?(string)$w:null, $body['warnings']), fn($w)=>$w!==null))` and optionally `array_slice(...,0,50)`.
**Verification:** POST a nested `warnings` element; confirm it is dropped or stringified.

### [LOW] Distance-log create: row + audit_log written as two un-wrapped statements (no transaction)
**File:** `api/v1/equipment_units/distance_logs/create.php:107-136`

```php
$id = db_insert('equipment_distance_logs', [...]);   // 107
db_insert('audit_log', [...]);                        // 124 — neither inside db_transaction()
```

**Why:** Sibling equipment writers wrap entity-write + audit_log in `db_transaction()`. Here, if the audit_log insert throws, the distance_logs row is already committed, leaving a telemetry row with no audit trail and a 500 after the data was saved; the UI's `r.success` gate then shows a network error and the user may re-submit, creating a duplicate. Diverges from the module's atomicity convention. _(Verifier note: the audit_log-overflow trigger the finding hypothesizes is improbable — the user-supplied label goes to `notes` (TEXT), and `entity_label` is well under 255; the real defect is the convention divergence itself.)_
**Fix:** Wrap both inserts in `db_transaction(function() use (...) { $id = db_insert(...); db_insert('audit_log', ...); })` returning `$id`, matching `units/create.php`/`units/delete.php`.
**Verification:** N/A (structural); confirm both inserts are atomic.

### [LOW] `bulk_update_status` / `bulk_delete` return raw DB exception messages verbatim into the JSON response (schema disclosure)
**File:** `api/v1/equipment/units/bulk_update_status.php:193-197` (also `bulk_delete.php:140`)

```php
catch (\Throwable $e){ ... $errors[]=['id'=>$id,'reason'=>'Database error: '.$e->getMessage()]; }
```

**Why:** The per-id catch embeds raw exception text into the returned `errors[]`; a DB message can include table/column/constraint names and SQL fragments. An authenticated `equipment.edit`/`delete` user can surface internal schema/SQL details by triggering a per-id DB error. Single-item endpoints don't do this (they let the exception 500 to the generic handler, which hides internals). The inline comment claims the failure is "logged" but there is no `error_log()` call. Info-level disclosure, not privilege escalation.
**Fix:** `error_log()` the full message server-side and return a generic per-id reason (`'A database error occurred processing this unit.'`) in both files.
**Verification:** Trigger a per-id DB error; confirm the response carries no schema details.

### [LOW] `bulk_delete.php` id filter keeps negative IDs (cosmetic divergence from `bulk_update_status`)
**File:** `api/v1/equipment/units/bulk_delete.php:52`

```php
$cleanIds = array_values(array_filter(array_map('intval', $ids)));   // intval('-5')=-5 survives
// bulk_update_status.php:74 uses array_filter(..., fn($v)=>$v>0)
```

**Why:** Bare `array_filter` drops only falsy values, so a negative id survives; the parallel endpoint uses a `$v>0` callback. Harmless — a negative id fails `WHERE id=? AND deleted_at IS NULL` and is reported skipped "Not found" — only an input-hygiene inconsistency.
**Fix:** `array_filter(array_map('intval',$ids), static fn(int $v)=>$v>0)` for parity.
**Verification:** N/A (cosmetic).

### [LOW] `StatusActions::changeReservationStatus` writes the int user-id into `equipment_status_log.changed_by` (name column) and leaves `changed_by_user_id` NULL — wrong audit attribution
**File:** `lib/AI/Actions/StatusActions.php:384-391`

```php
db_insert('equipment_status_log', [
    'equipment_unit_id' => $u['equipment_unit_id'],
    'changed_by'        => $userId,   // int into varchar(255) name col; changed_by_user_id NOT set
    ...
]);
```

**Why:** `changed_by` is `varchar(255)` holding a human name (default 'system') and `changed_by_user_id` is the int FK. The reservation path stores the numeric id into the name column and omits the FK, so the Status Log tab renders a bare integer as the actor and the users-join FK is NULL for confirm/cancel reservation transitions. Every other writer sets `changed_by=$userName + changed_by_user_id=$userId`. No STRICT abort (varchar accepts the int), so it silently produces wrong attribution. The method already receives `$userName`.
**Fix:** Set `'changed_by'=>$userName` and `'changed_by_user_id'=>$userId` (mirror the other writers); drop the manual `changed_at` (column defaults to CURRENT_TIMESTAMP).
**Verification:** Confirm/cancel a reservation; confirm the Status Log shows the operator name and the FK resolves.

---

## Info

### [INFO] Status-change endpoints never invalidate the dashboard cache (delete paths do) → stale fleet-availability tiles
**File:** `api/v1/equipment/units/bulk_update_status.php:200-204`; `update_status.php:65` and `StatusActions.php` (no call)

```php
// delete.php:101 and bulk_delete.php:147 call invalidate_dashboard_cache();
// update_status.php / bulk_update_status.php / StatusActions::changeEquipmentStatus do NOT
```

**Why:** A status change (available↔maintenance↔inactive↔decommissioned) directly changes the fleet-availability counts the dashboard caches (`available_units`, `fleet_utilization`, fleet-status donut, cached 5–15 min). After a status change the tiles can show stale counts until the next unrelated invalidation or TTL expiry — inconsistent with the delete paths. Bounded transient staleness; no correctness/security/money impact. _(Verdict: low; grouped under Info as a data-freshness nuisance.)_
**Fix:** Call `invalidate_dashboard_cache()` after a successful status change — once after the StatusActions call in `update_status.php` and once (if `actioned>0`) at the end of `bulk_update_status.php`.
**Verification:** Change a unit's status; confirm the dashboard availability tile updates without waiting for TTL.

### [INFO] `payoff.php` utilisation counts pending/future-dated leases, producing negative or inflated days-on-lease
**File:** `app/admin/equipment/payoff.php:272-280, 289-291`

```sql
WHERE equipment_unit_id = ? AND status != 'cancelled' AND deleted_at IS NULL   -- includes 'pending'
-- days_on_lease uses LEAST(COALESCE(end_date,CURDATE()),CURDATE()) - start_date → negative for future starts
```

**Why:** Including `status='pending'` (future-dated) leases makes the per-row `DATEDIFF` term negative, distorting the SUM. The post-aggregate `max(0,...)`/`min(...,$daysSinceAcq)` clamps cannot recover days a negative pending row subtracts from a legitimately active sibling lease, and `$avgLeaseDays` is distorted because `lease_count` still counts the pending lease. Read-only KPI page; understated Utilisation Rate / wrong Avg Lease Duration. _(Verdict: low.)_
**Fix:** Restrict the utilisation aggregate to `status IN ('active','completed')` and/or clamp the per-row `DATEDIFF` with `GREATEST(0, ...)`.
**Verification:** Seed a future-dated pending lease alongside an active one on a unit; confirm Utilisation Rate is unaffected.

### [INFO] `payoff.php` has no serve-time financial redaction — safe only because `fixed_assets:view` defaults align with `payments:view`; a per-user override leaks everything
**File:** `app/admin/equipment/payoff.php:29-30` (gate); entire render `388-996`

```php
require_permission('fixed_assets','view');   // only gate; no can_view_financials()/redact_keys() anywhere
```

**Why:** The page renders acquisition cost, financing payment/balance, total_revenue, maintenance cost, depreciation, NBV, and every invoice/WO/damage amount with no redaction. `can()` resolves per-user/role overrides first, so a user granted a `fixed_assets:view` override while denied `payments:view` (e.g. a dispatcher given asset-register access) reaches this page and sees ALL financials. Coincidentally safe under factory defaults; a misconfiguration leaks. _(Verdict: low.)_
**Fix:** Gate the page on `can_view_financials()` in addition to `fixed_assets:view`, or wrap each money field group in `if (can_view_financials())` with a redacted placeholder — compute redaction at serve-time from the predicate, not a sibling permission's default.
**Verification:** Grant `fixed_assets:view` to a user with `payments:view=false`; confirm money fields are redacted.

### [INFO] Template create/update silently coerce invalid enum values (ownership/tracking/currency/mileage_unit) instead of returning a 422
**File:** `api/v1/equipment/templates/update.php:231-246` (create.php:118-123,146-149 mirror)

```php
$updates['default_currency'] = in_array($v,['CAD','USD'],true) ? $v : 'CAD';   // no $fields error
```

**Why:** Out-of-set enums are silently coerced (ownership→null, tracking→'none', currency→'CAD', mileage_unit→'km') rather than rejected. This protects the STRICT enum columns from a 1265 abort (good) but means a typo or stale client silently writes a different value than intended (e.g. a USD template saved as CAD) — a data-quality/UX gap vs the `category` field, which DOES 422. Intentional, consistent design.
**Fix:** If strict feedback is desired, treat these like `category` (set a `$fields[...]` error when non-empty but not in the allowlist). Otherwise leave as-is.
**Verification:** POST `default_currency=GBP`; decide whether 422 or coercion is intended.

### [INFO] Template create form caps description at 2000 chars vs API/edit 5000, hardcodes `is_active`, and omits several API-accepted fields (UI-completeness gap)
**File:** `app/admin/equipment/templates/create.php:104-106, 322`

```html
<textarea maxlength="2000">   <!-- API clean_string(...,5000); edit.php maxlength="5000" -->
```
```js
is_active: true,   // 322 — hardcoded, no checkbox
```

**Why:** Create caps description at 2000 vs the API/edit 5000; hardcodes `is_active:true` with no control; and omits `default_wheel_size/tire_size/yard_location/notes/inspection_notes/sort_order` that the create/update APIs accept (edit omits the same string fields), so an API-set value can never be viewed/cleared through the UI (preserved on save, no data loss). Pure UX/consistency. _(Verifier note: the finding also lists `hourly_rate`, which templates do not have — that sub-claim is wrong.)_
**Fix:** Align create description maxlength + counter to 5000. If the omitted fields are meant to be editable, add controls; otherwise document them as API-only.
**Verification:** N/A (UX hygiene).

### [INFO] `templates/edit.php` STALE_DATA branch + stale-data banner are dead under app-wide-disabled optimistic locking
**File:** `app/admin/equipment/templates/edit.php:68-73, 569-571`

```js
else if (r.error?.code === 'STALE_DATA') { this.staleError = true; }   // never fires
// update.php:62 gates on optimistic_lock_matches() → true when FF_OPTIMISTIC_LOCKING unset
```

**Why:** `edit.php` sends `updated_at` as a lock token and handles STALE_DATA with a banner, but `optimistic_lock_matches()` returns true whenever `FF_OPTIMISTIC_LOCKING` is unset (the documented disabled state), so the 409 path can never fire — the banner + reload flow is unreachable. The docblock still advertises D19 as active. No functional harm; stale documentation/dead UI only.
**Fix:** Update the `edit.php` docblock (11-13) to note optimistic locking is disabled app-wide (last-write-wins) so the dead branch is understood as a re-enable hook. No code change required.
**Verification:** N/A (doc/dead-UI).

### [INFO] `update_status.php` header docblock lists stale/incorrect transition rules vs the executed StatusActions map
**File:** `api/v1/equipment/units/update_status.php:12-18`

```
// docblock: available → reserved, on_lease, maintenance, inactive | reserved → on_lease, available
// executed: available → [reserved,maintenance,inactive]; reserved → [available]; maintenance/inactive → [...,decommissioned]
```

**Why:** The docblock claims `available→on_lease` and `reserved→on_lease` (neither in the executed map) and omits `decommissioned` from maintenance/inactive (both allowed). Pure documentation drift (the endpoint delegates to StatusActions so behavior is correct), but misleads a maintainer extending the state machine or its bulk copy.
**Fix:** Update the docblock to match `StatusActions::UNIT_STATUS_TRANSITIONS`, or replace it with a pointer: "Canonical transitions: StatusActions::UNIT_STATUS_TRANSITIONS".
**Verification:** N/A (doc).

### [INFO] `bulk_update_status` keeps a second hand-copied copy of `UNIT_STATUS_TRANSITIONS` that can silently drift from the canonical StatusActions map
**File:** `api/v1/equipment/units/bulk_update_status.php:86-93` (vs `StatusActions.php:23-30`; allowlist at 52)

```php
const BULK_UNIT_STATUS_TRANSITIONS = [ ... 'maintenance'=>[...,'decommissioned'], 'inactive'=>[...,'decommissioned'] ];
// duplicate of StatusActions::UNIT_STATUS_TRANSITIONS; 'decommissioned' targets already dead (blocked by BULK_STATUS_ALLOWED_TARGETS:52)
```

**Why:** Hand-maintained duplicate of the canonical map, with no test asserting they stay in sync (the documented duplication trap). The maps currently agree (the `decommissioned` entries are harmlessly unreachable), but a future edit to the canonical map won't propagate, letting the single-unit and bulk state machines drift.
**Fix:** Import the canonical map (`StatusActions::UNIT_STATUS_TRANSITIONS`) and intersect with `BULK_STATUS_ALLOWED_TARGETS` at runtime instead of re-declaring it, or add a smoke asserting `BULK_UNIT_STATUS_TRANSITIONS === StatusActions::UNIT_STATUS_TRANSITIONS`.
**Verification:** Add the equality smoke; confirm it passes today and would fail on drift.

### [INFO] Distance-log create: field errors merged into `error.*` instead of `error.fields` (FF_Validate.applyApi contract mismatch)
**File:** `api/v1/equipment_units/distance_logs/create.php:86`

```php
json_error('VALIDATION_ERROR','Validation failed.',422,$errors);   // merges $extra into error → error.distance, not error.fields
```

**Why:** `json_error` merges `$extra` directly into the error object, so the field map lands at `error.distance` / `error.equipment_unit_id`, NOT under `error.fields` (the shape `FF_Validate.applyApi` reads, `app.js:1143`). Every other validating endpoint uses `json_validation_error()`. No active bug (`show.php` renders only `error.message`), but latent contract drift — any future UI wiring this endpoint to `applyApi` gets no field highlighting.
**Fix:** Use `json_validation_error($errors);` (which sets `error.fields`).
**Verification:** N/A (latent).

### [INFO] `payoff.php` negative dollar amounts render as `$-500.00` instead of `-$500.00`
**File:** `app/admin/equipment/payoff.php:300-302` (`pa_money`), used at 396, 708, 813

```php
function pa_money(string $val): string { return '$' . number_format((float) $val, 2); }   // '$-500.00'
```

**Why:** Sign placed after the `$`, so negative Net Revenue / Earnings / Monthly P&L Net render `$-500.00` — non-standard on a finance page. Maintenance/Damage columns manually prepend a minus; the Net columns don't. Value/sign correct; cosmetic.
**Fix:** `($val<0 ? '-$' : '$') . number_format(abs((float)$val),2)`.
**Verification:** N/A (cosmetic).

### [INFO] `show.php` upload/delete of compliance docs does not refresh unit → stale hero CVI tile + Compliance grid until reload
**File:** `app/admin/equipment/show.php:2736-2743` (submitUpload), `2754-2762` (confirmDeleteDoc)

```js
// success only mutates this.documents; neither calls this.loadUnit()
```

**Why:** The Compliance grid and hero CVI tile derive from `unit.cvi_expiry`/`registration_expiry`, which `documents/upload.php` server-syncs; after uploading a CVI with an expiry the Documents list updates but the Compliance tab keeps the old expiry until reload. DB correct. _(Verifier note: the hero CVI tile is rendered server-side in PHP, so `loadUnit()` refreshes only the Alpine compliance grid — the tile needs a full reload.)_
**Fix:** After a successful upload (and optionally delete) of a cvi/registration doc, call `await this.loadUnit()`, or merge the returned expiry into `this.unit`.
**Verification:** Upload a CVI doc; confirm the Compliance tab reflects the new expiry without a manual reload.

### [INFO] `show.php` PHP `daysUntil()` invoked up to 5 times per hero CVI card (redundant DateTime allocation)
**File:** `app/admin/equipment/show.php:277-281`

```php
// lines 277-278 evaluate daysUntil($unit['cvi_expiry']) twice each in class ternaries (4 calls);
// line 281 computes $cviDays a 5th time. daysUntil() builds two DateTimeImmutable + a diff per call.
```

**Why:** Single card, single render — negligible cost. Flagged for completeness.
**Fix:** Hoist `$cviDays = daysUntil($unit['cvi_expiry']);` above line 277 and reference it in the ternaries.
**Verification:** N/A (cosmetic).

### [INFO] `units/show.php` `tags`/`samsara_tags` `json_decode` returns non-array on corrupt JSON — frontend `x-for`/`.length` guards assume an array
**File:** `api/v1/equipment/units/show.php:157, 200`

```php
'tags' => $row['tags'] ? json_decode($row['tags'], true) : [],   // no is_array() coercion (same for samsara_tags)
```

**Why:** A JSON scalar/string value would yield a non-array; a JSON string has `.length` and would iterate characters in the frontend `x-for`. MySQL's `json` column type makes this near-impossible via normal writes (validates on write), so defense-in-depth only, no security impact.
**Fix:** Coerce defensively: `$tags = json_decode($row['tags'] ?? 'null', true); $payload['tags'] = is_array($tags) ? $tags : [];` (same for samsara_tags).
**Verification:** N/A (defense-in-depth).

### [INFO] `units/index.php` redact_rows lists `total_maintenance_cost`/`acquisition_cost` which are never selected or output — latent no-op, asymmetric with `show.php`
**File:** `api/v1/equipment/units/index.php:171, 180-182`

```php
redact_rows($items, ['total_revenue','total_maintenance_cost','acquisition_cost'])   // only total_revenue exists in rows
```

**Why:** The list SELECT/output includes only `total_revenue` (correctly redacted for non-financial roles), so the other two `unset()` keys are harmless no-ops. Not a leak today, but the asymmetry between the list payload (1 money field) and the show payload (3) is a maintenance trap that could mask a real omission. Recorded as info that the index surface was checked and is clean.
**Fix:** No change required. Optionally trim the index redact list to `['total_revenue']`, or drop `total_revenue` from the SELECT (the list page doesn't use it).
**Verification:** N/A (info).

### [INFO] `samsara/link.php` conflict re-check is not under FOR UPDATE — TOCTOU window allows two units linking the same Samsara trackable
**File:** `api/v1/samsara/link.php:100-118` (conflict SELECT) → `181` (update)

```php
$conflict = db_row("SELECT ... WHERE samsara_vehicle_id = :sid AND id <> :uid AND deleted_at IS NULL LIMIT 1", [...]);  // no FOR UPDATE, before txn
// equipment_units.samsara_vehicle_id has NO UNIQUE index; update at 181
```

**Why:** The "already linked elsewhere?" check is a plain `db_row` before the transaction, and `samsara_vehicle_id` has no UNIQUE index, so two concurrent link requests for the same id against two different units can both pass the check and commit, leaving two FF units pointing at one Samsara trackable. Low impact (no DB unique → no 1062/500; worst case is duplicate links the next sync surfaces); the in-code comment claims "race-safe via the second SELECT" without closing the window.
**Fix:** Add a UNIQUE index on `samsara_vehicle_id` (with a narrow 23000 catch → ALREADY_LINKED, accounting for the soft-delete blind spot), or move the conflict re-check inside `db_transaction` and `SELECT ... FOR UPDATE` on the candidate so concurrent links serialize.
**Verification:** Concurrency test: two links to the same `samsara_vehicle_id` on different units; assert only one succeeds.

### [INFO] units `create.php` failed Samsara push leaves `tracking_provider='samsara'` with no `samsara_vehicle_id` (inconsistent link state)
**File:** `api/v1/equipment/units/create.php:130-131` + `218-256` (insert) and `320-353` (post-txn Samsara)

```php
// tracking_provider clamped/inserted as-is (130-131,225)
// post-txn push sets samsara_vehicle_id/entity_type/tracking_provider ONLY on createTrailer success (334-346);
// on failure records $samsaraWarning (347-352), leaving tracking_provider='samsara', samsara_vehicle_id NULL
```

**Why:** A client passing `tracking_provider='samsara'` whose push fails persists a row flagged Samsara-tracked but unlinked, which downstream sync/map code (keyed on `samsara_vehicle_id`) treats as not-in-Samsara. The row is always created in FF, a `samsara_warning` is surfaced, and the Mapping tab can relink — cosmetic/operational only.
**Fix:** On Samsara push failure, normalize `tracking_provider` back to 'none', or only set it to 'samsara' inside the success branch alongside `samsara_vehicle_id`.
**Verification:** Create a unit with `tracking_provider='samsara'` and a forced push failure; confirm provider/link state stay consistent.

---

## Refuted / already-fixed / by-design

- **E09 — compliance/update.php leaves `*_interval_days` stale on manual override.** Not a live bug: no cron/path recomputes expiry from `from_date+interval_days` (grep confirms zero `DATE_ADD(...interval_days)`); purely latent contingent on a feature that does not exist. By-design / speculative-future.
- **E20 — payoff.php principal double-count for financed assets.** Intentional, documented cash-recovery model mirrored verbatim in `api/v1/accounting/fixed_assets/payoff.php`; the schema lacks any principal/interest split to support the proposed "interest-only" fix. Operator design judgment, not a defect.
- **E40 — edit.php bare `json_encode()` in `<script>` chains with unsanitized Samsara import for stored XSS.** Refuted as exploitable XSS: default `json_encode` escapes `/` → `\/`, so a literal `</script>` can never appear and the value stays trapped inside the JS string literal; the `<!--<script` state-flip only delays termination without creating a breakout. The missing `JSON_HEX` flags + unsanitized `import.php` source are a real defense-in-depth gap (worth the proposed fix) but not a live vulnerability. Downgraded medium→low/hardening.
- **E45 — templates/create.php duplicate-name pre-check has a redundant `deleted_at` clause.** Correct in outcome; `db_exists` auto-appends its own `deleted_at IS NULL`, so the doubled predicate is idempotent (X AND X = X). NOT a soft-delete blind-spot bug because only `slug` is UNIQUE (handled separately) — name has no unique index, so no 1062 risk. Cosmetic redundancy, no fix required.


---

## Confirmed findings — index table

| ID | Sev | File | Line | Dimension | Title |
|----|-----|------|------|-----------|-------|
| E02 | high | app/admin/compliance/index.php | 729-760 (writes 745-746, close 750) | frontend | Compliance grid saveUpdate() does not gate on resp.success — a 422/409 silently NULLs the grid cells and closes the modal as if saved |
| E04 | medium | api/v1/compliance/index.php | 73-83; kpis.php:25-50; reports/compliance.php:42 | compliance | Compliance expired/expiring classification splits across UTC (CURDATE) and Vancouver-local (PHP date) — grid list and KPI tiles disagree by a day each evening |
| E14 | medium | api/v1/equipment/templates/index.php | 127-130 (also show.php:69-72) | financial-leak | Equipment template default rate card (daily/weekly/monthly/mileage) exposed to dispatchers who are denied the rates module and financials |
| E12 | medium | api/v1/equipment/units/bulk_delete.php | 68-72 (unlocked read) → 104-107 (unconditional UPDATE) | race | Bulk delete has the same unlocked check-then-delete race as delete.php (×up to 100 ids) |
| E11 | medium | api/v1/equipment/units/delete.php | 40-44 (unlocked read) → 70-73 (unconditional UPDATE) | race | Soft-delete is a TOCTOU race: status checked unlocked, then unconditional UPDATE can soft-delete a unit that just became reserved/on_lease (orphans the lease) |
| E47 | medium | api/v1/equipment_units/distance_logs/create.php | 72 | validation | Distance-log create: scientific-notation distance passes is_numeric then throws ValueError in bccomp → 500 instead of 422 |
| E15 | medium | app/admin/equipment/payoff.php | 119-126 (hero totalRevenue), 163-164 (revenue-by-lease), 182 (monthly revenue) | financial-leak | payoff.php gross/net revenue includes DRAFT (unsent) invoices — diverges from the SEND-only total_revenue counter and the reporting policy |
| E16 | medium | app/admin/equipment/payoff.php | 120 (totalRevenue), 160 (revenue-by-lease), 177 (monthly revenue) | financial-leak | payoff.php revenue/cost aggregates not converted to CAD — USD invoices summed at face value |
| E01 | medium | app/admin/equipment/show.php | 2465 (loadStatusLog); rendered 1477-1530 | correctness | Status Log tab calls a non-existent API endpoint (status-log.php 404s) — tab always shows 'No status changes recorded' |
| E13 | medium | lib/AI/Actions/StatusActions.php | 26 (transition map) + 74-79 (UPDATE); also bulk_update_status.php:89,149,159-164 | state-machine | on_lease → available transition reachable from operator status endpoints with no open-lease guard — decouples a unit from its still-active lease (double-book hazard) |
| E08 | low | api/v1/compliance/update.php | 57-74, 106-112 | validation | compliance/update.php accepts from_date later than expiry_date (no cross-field ordering validation) — stores an inverted validity window |
| E41 | low | api/v1/equipment/templates/create.php | 79-99,126-135,232-258; update.php:133-214,325-330 | validation | Template create/update numeric default fields (axle/intervals/rates/weight/dimensions) have no upper bound → DECIMAL/INT overflow aborts INSERT with 500 instead of 422 |
| E42 | low | api/v1/equipment/templates/create.php | 189-206 (update.php:87-103 mirror) | data-integrity | Template all-symbol / non-ASCII name produces an empty base slug; dedup loop yields '' then '-2' |
| E53 | low | api/v1/equipment/units/bulk_update_status.php | 193-197 (also bulk_delete.php:140) | error-handling | bulk_update_status / bulk_delete return raw DB exception messages verbatim into the JSON response (schema disclosure) |
| E55 | low | api/v1/equipment/units/bulk_update_status.php | 200-204; also update_status.php:65 and StatusActions.php (no call) | data-integrity | Status-change endpoints never invalidate the dashboard cache (delete paths do) → stale fleet-availability tiles |
| E33 | low | api/v1/equipment/units/create.php | 50,117,127-162; update.php:43,75,99,124-128,293,297 | error-handling | units create/update non-string scalar/array JSON values for string fields throw TypeError → HTTP 500 instead of 422 |
| E34 | low | api/v1/equipment/units/create.php | 75-87 (create); update.php:217-230 | validation | units create/update mileage has no upper-bound clamp → out-of-range value overflows int unsigned → SQLSTATE 22003 → HTTP 500 |
| E35 | low | api/v1/equipment/units/create.php | 133, 227 (create); update.php:278-281 | validation | units create/update owner_company_id accepted as any int with no FK existence check → FK 1452 → HTTP 500; soft-deleted customer assignable as owner |
| E30 | low | api/v1/equipment/units/index.php | 13, 70-74 | perf | units/index.php docblock claims FULLTEXT search but code uses leading-wildcard LIKE — unused index + full table scan at scale |
| E31 | low | api/v1/equipment/units/index.php | 36, 39 | error-handling | units/index.php array-valued query params (search[]=x) raise TypeError → generic 500 instead of 422 |
| E36 | low | api/v1/equipment/units/update.php | 122 (decommission_reason in $stringFields), 285 (decommissioned_date in date loop) | state-machine | units update.php mass-assigns state-machine-owned decommissioned_date / decommission_reason — bypasses the status state machine |
| E39 | low | api/v1/equipment/units/update.php | 292-294, 371-389 | data-integrity | edit.php tracking_provider='none' does not clear samsara_vehicle_id, and update.php still PATCHes Samsara on subsequent saves |
| E48 | low | api/v1/equipment_units/distance_logs/create.php | 70-74, 107-122 | validation | Distance-log create: no upper bound on distance → DECIMAL(12,2) overflow aborts INSERT under STRICT → 500 |
| E49 | low | api/v1/equipment_units/distance_logs/create.php | 64-69, 99-103 | validation | Distance-log create: period_start/period_end never validated as real dates → garbage silently coerced to 1970-01-01; no start<=end ordering check |
| E50 | low | api/v1/equipment_units/distance_logs/create.php | 52 | validation | Distance-log create: warnings array stored without per-element type validation (non-string/nested elements persisted) |
| E51 | low | api/v1/equipment_units/distance_logs/create.php | 107-136 | data-integrity | Distance-log create: row + audit_log written as two un-wrapped statements (no transaction) |
| E06 | low | api/v1/reports/compliance.php | 60-94 (ok_count formula at 94) | correctness | reports/compliance.php ok_count double-subtracts units that have one expired doc AND another doc expiring within 90 days |
| E07 | low | api/v1/reports/compliance.php | 61,63,70,77,114,124,135,211,257,304 | correctness | reports/compliance.php excludes only 'decommissioned' while compliance index/kpis also exclude 'inactive' — same units classified inconsistently across the two compliance surfaces |
| E03 | low | app/admin/compliance/index.php | 755-756 | error-handling | saveUpdate() catch reads e.message but FF_Api never rejects with a server message — error banner unhelpful on real failure path |
| E05 | low | app/admin/compliance/index.php | 684 vs kpis.php:37 + compliance/index.php:73 | compliance | 'Expired' boundary semantics differ: UI cell/CSV treat expiry==today as expired (<=) while backend KPI/filter treat it as still-valid (<) — same-day mismatch independent of timezone |
| E37 | low | app/admin/equipment/edit.php | 315 (prefill) + 419 (full-form POST) | race | units update.php always re-submits prefilled mileage → silently reverts concurrent Samsara/GPS odometer updates (optlock disabled = last-write-wins) |
| E38 | low | app/admin/equipment/edit.php | 248, 252 | compliance | edit.php editing cvi_expiry/registration_expiry updates expiry but leaves *_from_date stale — diverges from the atomic compliance editor |
| E28 | low | app/admin/equipment/index.php | 657-664 | compliance | index.php client compliance 'Expiring' flag parses date-only strings as UTC midnight — off-by-one mislabel in negative-offset timezones |
| E29 | low | app/admin/equipment/index.php | 462-463 | xss | index.php $_GET-seeded filter values HTML-escaped but not JS-string-escaped where injected into a single-quoted JS literal |
| E17 | low | app/admin/equipment/payoff.php | 29-30 (gate); entire render 388-996 | authz | payoff.php has no serve-time financial redaction — safe only because fixed_assets:view defaults align with payments:view; a per-user override leaks everything |
| E18 | low | app/admin/equipment/payoff.php | 272-280, 289-291 | correctness | payoff.php utilisation counts pending/future-dated leases, producing negative or inflated days-on-lease |
| E21 | low | app/admin/equipment/show.php | 1357 (dc.estimated_repair_cost), 1622 (wo.total_cost) | financial-leak | Maintenance & damage-claim cost amounts leak to dispatchers via show.php tabs (backend list endpoints don't redact) |
| E22 | low | app/admin/equipment/show.php | 325 (x-init="init()"), 2399-2401 (init), 2455-2459 ($watch) | frontend | show.php Alpine double-init runs loadUnit() twice → duplicate unit GET + two stacked activeTab $watch listeners |
| E23 | low | app/admin/equipment/show.php | 2760 | error-handling | show.php confirmDeleteDoc reads r.message instead of r.error.message — failed document deletes always show the generic fallback |
| E24 | low | app/admin/equipment/show.php | 216 | state-machine | show.php Delete Unit button shown for 'reserved' units even though the endpoint rejects them (422 UNIT_RESERVED) |
| E25 | low | app/admin/equipment/show.php | 2906-2918 | xss | show.php Leaflet map popup builds raw HTML from unit_number + Samsara address without escaping — defense-in-depth XSS sink, inconsistent with the tracking page |
| E10 | low | app/admin/tracking/index.php | 563-572 | error-handling | tracking/index.php syncAllNow() does not gate on .success — a failed/partial sync silently swallows the error and refreshes as if it worked |
| E58 | low | lib/AI/Actions/StatusActions.php | 384-391 | data-integrity | StatusActions::changeReservationStatus writes the int user-id into equipment_status_log.changed_by (name column) and leaves changed_by_user_id NULL — wrong audit attribution |
| E43 | info | api/v1/equipment/templates/update.php | 231-246 (create.php:118-123, 146-149 mirror) | validation | Template create/update silently coerce invalid enum values (ownership/tracking/currency/mileage_unit) instead of returning a 422 |
| E56 | info | api/v1/equipment/units/bulk_delete.php | 52 | validation | bulk_delete.php id filter keeps negative IDs (cosmetic divergence from bulk_update_status) |
| E54 | info | api/v1/equipment/units/bulk_update_status.php | 86-93 (vs StatusActions.php:23-30; allowlist at 52) | state-machine | bulk_update_status keeps a second hand-copied copy of UNIT_STATUS_TRANSITIONS that can silently drift from the canonical StatusActions map |
| E62 | info | api/v1/equipment/units/create.php | 130-131 + 218-256 (insert) and 320-353 (post-txn Samsara) | data-integrity | units create.php failed Samsara push leaves tracking_provider='samsara' with no samsara_vehicle_id (inconsistent link state) |
| E32 | info | api/v1/equipment/units/index.php | 171, 180-182 | financial-leak | units/index.php redact_rows lists total_maintenance_cost / acquisition_cost which are never selected or output — latent no-op, asymmetric with show.php |
| E60 | info | api/v1/equipment/units/index.php | 171, 180-182 | financial-leak | units/index.php selects total_revenue and ships it to the redaction layer though the page never renders it (defense-in-depth OK) |
| E59 | info | api/v1/equipment/units/show.php | 157, 200 | data-integrity | units/show.php tags / samsara_tags json_decode returns non-array on corrupt JSON — frontend x-for/.length guards assume an array |
| E57 | info | api/v1/equipment/units/update_status.php | 12-18 | correctness | update_status.php header docblock lists stale/incorrect transition rules vs the executed StatusActions map |
| E52 | info | api/v1/equipment_units/distance_logs/create.php | 86 | error-handling | Distance-log create: field errors merged into error.* instead of error.fields (FF_Validate.applyApi contract mismatch) |
| E61 | info | api/v1/samsara/link.php | 100-118 (conflict SELECT) → 181 (update) | race | samsara/link.php conflict re-check is not under FOR UPDATE — TOCTOU window allows two units linking the same Samsara trackable |
| E19 | info | app/admin/equipment/payoff.php | 300-302 (pa_money), used at 396, 708, 813 | frontend | payoff.php negative dollar amounts render as '$-500.00' instead of '-$500.00' |
| E26 | info | app/admin/equipment/show.php | 2736-2743 (submitUpload success), 2754-2762 (confirmDeleteDoc) | frontend | show.php upload/delete of compliance docs does not refresh unit → stale hero CVI tile + Compliance grid until reload |
| E27 | info | app/admin/equipment/show.php | 277-281 | perf | show.php PHP daysUntil() invoked up to 5 times per hero CVI card (redundant DateTime allocation) |
| E44 | info | app/admin/equipment/templates/create.php | 104-106, 322 | frontend | Template create form caps description at 2000 chars vs API/edit 5000, hardcodes is_active, and omits several API-accepted fields (UI-completeness gap) |
| E46 | info | app/admin/equipment/templates/edit.php | 68-73, 569-571 | state-machine | templates/edit.php STALE_DATA branch + stale-data banner are dead under app-wide-disabled optimistic locking |
