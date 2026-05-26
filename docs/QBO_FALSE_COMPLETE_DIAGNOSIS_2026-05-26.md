# QBO Worker False-Complete Diagnosis — 2026-05-26

**Session:** S-QBO-WORKER-FALSE-COMPLETE-DIAGNOSIS (RO investigation)
**Triggered by:** S-QBO-LIVE-VERIFY-2026-05-26 STOP condition at PART A2
**Status:** Root cause identified. Two fix sessions queued.

---

## TL;DR

Queue row 180 (invoice 42, status=`draft`) was marked `status='completed'` with
`error_code=NULL` + `error_message=NULL` by the worker on 2026-05-26 17:19:03.
No mapping row written. No sync_log entry. Worker output reported `OK queue#180`.

**Root cause:** `lib/QboPushers/InvoicePusher.php:147-150` (the
`skipped_soft_deleted` branch) returns `['success' => true, 'status' =>
'skipped_soft_deleted']` **without calling `self::recordSkipped()`**. The
worker's success branch at `cron/qbo_sync_worker.php:241-261` (post-S-QBO-FIXPACK-14)
only inspects `$result['success']` — a truthy success+no-side-effects return is
indistinguishable from a successful create+mapping-write.

**Why it's broader than one branch:** `skipped_by_mode` (line 126-128) has the
same defect. `skipped_voided` (line 142-145) is the only `success=true` skip
path that actually records the skip. The asymmetry is the bug.

**Compounding gap:** `acc_qbo_invoice_map.push_status` ENUM doesn't include
`skipped_soft_deleted` (see `FLEETFORGE_DATABASE_MASTER.sql:1112`), so the
Pusher can't write the state even if it tried — `recordSkipped` would fail on
ENUM constraint. So the fix shape involves either ENUM extension or
state-storage redesign.

**Pre-cutover impact:** If a real FF invoice gets soft-deleted between enqueue
and dispatch (e.g. operator clicks Delete on a recently-sent invoice), the
worker silently marks 'completed' with no audit trail. The QBO admin sync_queue
table will lie. The mapping row never gets created, so the show-page panel will
correctly show "○ Not Synced" — but the operator looking at the queue admin
sees `completed` and assumes the invoice is in QBO. **This is exactly the
class of false-positive operational metric that D-QBO-FIXPACK-14/-16 was
locked to close**; FIXPACK fixed `success=false` returns but didn't address
`success=true` from non-pushing paths.

---

## Evidence

### Queue row 180 state

```php
['id' => 180,
 'entity_type' => 'invoice',
 'entity_id' => 42,
 'operation' => 'create',
 'status' => 'completed',          // ← FIXPACK-14 only writes this when $result['success'] is truthy
 'error_code' => NULL,
 'error_message' => NULL,
 'retry_count' => 0,
 'enqueued_at' => '2026-05-26 17:18:01',
 'picked_up_at' => '2026-05-26 17:19:03',
 'completed_at' => '2026-05-26 17:19:03',
 'worker_id' => 'Avis-MacBook-Air.local-97946-77b558',
 'payload_snapshot' => NULL]
```

### Invoice 42 state

```php
['id' => 42,
 'invoice_number' => 'INV-2026-00042',
 'status' => 'draft',
 'deleted_at' => '2026-05-02 10:12:41',   // ← SOFT-DELETED 24 days before enqueue
 'sent_at' => NULL,
 'created_at' => '2026-05-02 04:36:13']
```

### Mapping + sync_log

- `acc_qbo_invoice_map WHERE ff_invoice_id=42` — **no row**
- `acc_qbo_sync_log WHERE entity_type='invoice' AND entity_id=42` — **0 rows**
- Entire `acc_qbo_invoice_map` table — **1 row total** (invoice 236,
  `push_status='failed_preflight'`, from earlier verification work)

### Settings at session time

- `quickbooks.sync_enabled='1'` (flipped on earlier in S-QBO-LIVE-VERIFY)
- `quickbooks.sync_mode.invoice='queue'`
- `quickbooks.dry_run_mode` (not checked but worker output was not `DRY` so was '0')

---

## Why each candidate completion path was eliminated

Worker writes `acc_qbo_sync_queue.status='completed'` in exactly 3 places
(`cron/qbo_sync_worker.php`):

| Path | Line | Writes error_message | Match? |
|---|---|---|---|
| Dry-run short-circuit | 209-220 | `'[DRY RUN] would push'` | ❌ Queue 180 has NULL error_message |
| Success branch (FIXPACK-14) | 241-261 | `NULL` | ✅ Matches queue 180 exactly |
| (No other 'completed' writes) | — | — | — |

The 'skipped' status (line 173-184) writes `error_code='sync_mode_off'` + a
non-null error_message, so doesn't match. All 'failed' branches write non-null
error_code. **Only the success branch produces the observed state.**

So `$result['success']` was truthy. Time to look at the Pusher.

---

## InvoicePusher success-path enumeration

`lib/QboPushers/InvoicePusher.php::pushImpl` returns `success=true` from 5 paths:

| Line | Status | Records to acc_qbo_invoice_map? | Records to acc_qbo_sync_log? | Notes |
|---|---|---|---|---|
| 127 | `skipped_by_mode` | **NO** | NO (no HTTP call) | Bug — same class as 149 |
| 144 | `skipped_voided` | YES via `recordSkipped()` | NO (no HTTP call) | Only success-path that records |
| **149** | **`skipped_soft_deleted`** | **NO** | **NO (no HTTP call)** | **Root cause for queue 180** |
| 181 | `already_mapped` | NO (mapping already exists) | NO (no HTTP call) | Correct — replay no-op |
| 260 | `created` | YES via `recordSuccessfulPush()` | YES via QuickBooksClient HTTP | Happy path |

**Three of five paths are silent on the side-effect side.**
- `already_mapped` is correct (mapping pre-exists; nothing to write).
- `created` is correct (full happy path).
- `skipped_voided` is correct (only path that records).
- `skipped_by_mode` and `skipped_soft_deleted` are **wrong** — they return
  `success=true` without leaving any trace, so the worker can't distinguish
  them from `created` based on `$result['success']` alone.

**For queue 180 specifically:** invoice 42's `deleted_at` is non-null →
**line 148 `($invoice['deleted_at'] ?? null) !== null` evaluates true** → line
149 returns `['success' => true, 'status' => 'skipped_soft_deleted']` → worker
sees `success=true`, marks completed.

---

## QboPusherDispatcher trace

`lib/QboPusherDispatcher.php::dispatch` (line 91-128) is a thin convention-
based delegator. **No kill_switch / dryRun / short-circuit** — it does:
1. Resolve class name (line 93)
2. Resolve method name (line 94)
3. Throw `PusherNotImplementedException` if either lookup fails (line 96-119)
4. Delegate: `return $className::$methodName($entityId, $payloadSnapshot)` (line 127)

The dispatcher cannot produce a vacuous `success=true`. The result is whatever
the Pusher returns. **Dispatcher cleared.**

---

## InvoiceEnqueuer trace

`lib/QboPushers/InvoiceEnqueuer.php::enqueue` 4-step gating:

| Gate | Line | Check | Eligibility check? |
|---|---|---|---|
| 1 | 55-57 | `quickbooks.sync_enabled='1'` | settings |
| 2 | 60-63 | `sync_mode.invoice` not in {qbo_to_ff, disabled} | settings |
| 3 | 68-70 | `$operation === 'create'` | operation whitelist |
| 4 | 75-94 | `db_insert acc_qbo_sync_queue` | none — accepts any $ffInvoiceId |

**No check on invoice eligibility.** The enqueuer accepts any integer
`$ffInvoiceId` regardless of:
- Whether the row exists in `invoices`
- Whether `status='sent'` (per D12 immutability — the spec contract is "QBO
  only sees sent invoices")
- Whether `deleted_at IS NULL`

### Production call sites (grep clean)

| Caller | File:line | Path |
|---|---|---|
| Canonical send | `api/v1/invoices/send.php:264` | Fires AFTER `draft→sent` transaction commits. Production-eligible by construction. |
| Retry endpoint | `api/v1/quickbooks/invoices/retry.php:61` | Pre-gates on mapping row existing + push_status in retryable allowlist (line 41-49). Production-eligible by construction. |
| Smoke (×2) | `tests/_smoke_qbo_invoice_push.php:757,769` | Sentinel id 999990 in cleanup-bracketed test path. Not production. |

**Conclusion:** the queue 180 row must have come from a manual CLI invocation
outside of these call sites (e.g. the operator running
`php -r "...InvoiceEnqueuer::enqueue(42, 'create')..."` while debugging the
sandbox earlier today). The enqueuer accepted it because no eligibility gate
existed. **Production-normal flows do not hit this gap** — but the gap means
manual/debug invocations + future call sites can silently enqueue ineligible
work.

---

## ENUM gap (compounding)

`acc_qbo_invoice_map.push_status` ENUM (FLEETFORGE_DATABASE_MASTER.sql:1112):

```sql
ENUM('pending','pushed','failed','skipped_voided','skipped_by_mode',
     'failed_preflight','failed_preflight_field_too_long',
     'failed_preflight_currency_mismatch')
```

Missing: **`skipped_soft_deleted`**. So even if `InvoicePusher::pushImpl`
line 149 wanted to call `self::recordSkipped($ffInvoiceId, $invoice,
'skipped_soft_deleted')`, that call would fail with an ENUM constraint
violation. The fix must either:
- (a) Add `skipped_soft_deleted` to the ENUM via migration, then make the
  Pusher record the skip, OR
- (b) Map `skipped_soft_deleted` to a different stored status (e.g. reuse
  `skipped_voided` semantically, or add a generic `skipped`), OR
- (c) Have the Pusher write directly to `acc_qbo_sync_log` from inside the
  skip branches (sync_log has no ENUM constraints; just text/json columns).

Note: the QBO admin UI surfaces 2 skipped KPI tiles (`skipped_voided` + 
`skipped_by_mode`); the operator-facing skipped-count for soft-deleted
invoices is currently invisible regardless of which fix shape is chosen unless
the UI is also updated.

---

## Sympathetic bug class (out-of-session scope but worth surfacing)

`CustomerPusher.php` and `VendorPusher.php` have the same defect class:

| File:line | Branch | Issue |
|---|---|---|
| `lib/QboPushers/CustomerPusher.php:131` | `skipped_by_mode` | Returns `success=true`; no skip record |
| `lib/QboPushers/CustomerPusher.php:151` | `skipped_soft_deleted` | Returns `success=true`; no skip record |
| `lib/QboPushers/VendorPusher.php:136` | `skipped_by_mode` | Returns `success=true`; no skip record |
| `lib/QboPushers/VendorPusher.php:157` | `skipped_soft_deleted` | Returns `success=true`; no skip record |

Per-entity ENUMs in `acc_qbo_customer_map.push_status` /
`acc_qbo_vendor_map.push_status` would need parallel audit; left as a
follow-up scope.

---

## Proposed fix shapes (sketches; not full prompts)

### Fix Session 1 (P1 — pre-cutover blocker): `S-QBO-PUSHER-SKIP-RECORD-FIX`

**Scope:** All 3 Pushers (Invoice, Customer, Vendor) — the
`skipped_by_mode` + `skipped_soft_deleted` branches each get either
a `recordSkipped()` call OR a sync_log write so the operational
audit trail is honest.

**Decisions to lock in planning chat:**
- (D1) ENUM extension (add `skipped_soft_deleted`) vs reuse existing
  values vs sync_log-only recording.
- (D2) Whether `skipped_*` returns should be `success=true` (informational
  no-op, as today) or `success=false` (operator gets a failure
  notification — probably too noisy for routine skips).
- (D3) Whether to also write a sync_log row for non-HTTP skips so the
  show-page Push History table reflects every dispatch attempt.

**Migration footprint:** 1 ALTER if extending ENUM. Zero migrations if
sync_log-only.

**Smoke additions:** `_smoke_qbo_invoice_push.php` needs a new C48 +
C49 (soft-deleted invoice → mapping row state, sync_log state).
Parallel C-checks in customer_push and vendor_push smokes.

### Fix Session 2 (P2 — defensive eligibility gate): `S-QBO-ENQUEUER-ELIGIBILITY-GATE`

**Scope:** `InvoiceEnqueuer::enqueue` adds a gate 0 (pre-INSERT)
that loads the invoice and rejects if:
- Row doesn't exist
- `status != 'sent'` (per D12)
- `deleted_at IS NOT NULL`

Parallel hardening for `CustomerEnqueuer` and `VendorEnqueuer` (likely
same shape — entity exists + active status + deleted_at IS NULL).

**Open question:** does the retry endpoint pre-gate make this
redundant? Yes for the Retry button path, but no for any other call
site. Belt-and-suspenders is appropriate at this layer given the
pre-cutover risk profile.

**Migration footprint:** Zero.

**Smoke additions:** New C-check per Enqueuer for each rejection
condition.

---

## Cross-references

- **D-QBO-FIXPACK-14** (worker queue-row failure branch) — only addressed
  the `success=false` path; this finding is the symmetric gap for the
  `success=true`+no-side-effects path.
- **D-QBO-11-9** (voided-invoice skip) — established the
  `recordSkipped` pattern for `skipped_voided`; the bug is that the
  soft_deleted + by_mode branches don't follow it.
- **D12** (invoice immutability post-send) — the enqueuer gap is the
  reason a draft invoice's id reached the queue in the first place.
- **S-QBO-INVOICE-SHOW-RICH-PANEL** (this morning) — the new "Push
  History" table on the show page would have surfaced this bug
  IMMEDIATELY for the operator IF the skip paths wrote to sync_log.
  As-is, the panel correctly shows "No push attempts yet" for invoice
  42 because no sync_log row exists.

---

## What this session did NOT do

Per RO + DO NOT list:

- No production .php modified
- No test smoke modified
- No FLEETFORGE_DATABASE_MASTER.sql modified
- No DB state mutated (no UPDATE/INSERT/DELETE)
- No inline fix applied (even the obvious 1-liner — fix sessions queued
  separately for proper diff review per post-Phase-1 pattern)
- No sync_enabled flip (left at the state operator set in S-QBO-LIVE-VERIFY-2026-05-26
  cleanup; if operator forgot to flip back to '0', that's a separate
  housekeeping item)
