# Bug Taxonomy — the classes to hunt

Each class below is a real failure pattern in *this* codebase, with a concrete
incident so you recognize the shape. When auditing, run every flow against this
list. Cite the class number in your findings (e.g. "Class 3: enum truncation").

---

## Class 1 — Client-side validation traps
The UI blocks a legitimate submit; the request never reaches the API.
- **Incident (today):** rate-card create auto-adds a blank item row + "Add new +"
  adds more; `validate()` required every row to have an equipment type, so a
  leftover blank row threw "Item 2: please select an equipment type." Types were
  fine in the DB. Fix: skip entirely-blank rows.
- **Hunt:** optional/repeatable rows, "select one" requireds on pre-seeded rows,
  date `min`/`max` attributes that reject valid real dates, maxlength/regex that
  rejects valid input, disabled submit that never re-enables, `is-invalid` states
  that stick.

## Class 2 — UI ↔ API field-name / shape mismatch
The UI and the endpoint disagree on a key, casing, or structure → silent no-op.
- **Incident:** record pickers — searchParam is `q` for Vendors/Users but `search`
  for Customers/Leases; mixing them returns nothing. (`project_dropdown_retrofit_status`)
- **Incident:** rate lookup keyed on equipment_type **category slug** but the UI
  stored the template **name** → every rate override silently missed
  (`S-RATES-UI-CATEGORY-DEDUP`).
- **Hunt:** compare the JS payload keys to the `$body['…']` reads one by one; check
  array-vs-object, string-vs-int, name-vs-id-vs-slug.

## Class 3 — Enum / schema truncation under STRICT mode
Code writes a value not in the column's `enum(...)`; under `STRICT_TRANS_TABLES`
(dev AND prod) the INSERT throws SQLSTATE 1265 and aborts the whole transaction.
- **Incident:** `InvoiceGenerator::createFromLease(billing_type='mileage_only')`
  wrote `period_type='mileage_only'`, but the enum has only
  `partial_start|full_month|partial_end|single_period` → entire lease-close txn
  fatal'd. Fix mapped non-period types to `single_period`.
- **Hunt:** for every INSERT/UPDATE of an enum or constrained column, list the
  column's allowed members from `FLEETFORGE_DATABASE_MASTER.sql` and confirm every
  code path writes only those. Watch status machines especially.

## Class 4 — Silent failure / silent skip
An error is swallowed and the user sees emptiness, not a message.
- **Incident:** picker `endpoint` built with `base_url()` while `FF_Api.url()`
  re-prepended base → `/fleetforghttps://…` → 404 → caught → **empty selector, no
  error**. 17 instances across 7 forms. (`project_picker_endpoint_root_relative`)
- **Incident:** an enqueuer gate-0 that only pushed `'sent'` invoices, silently
  skipping everything else.
- **Hunt:** `catch` blocks that set empty state without surfacing the error; gates/
  filters that can match zero rows with no log; `?? ''`/`?? []` that masks a missing
  field; toasts that only fire on `success`.

## Class 5 — SQL parameter-order / placeholder bugs
Positional `?` bound in the wrong order, or a value bound to the wrong placeholder.
- **Incident:** `notification_digest.php` bound role slugs to the hour placeholders →
  `WHERE ur.slug IN (7)` → **0 recipients, forever, no error.** The AI digest was
  never emailed. (`project_ai_digest_dispatch_bug`)
- **Hunt:** any query with multiple `?` — map each bind to its placeholder; named
  params that don't match; reused `$params` arrays across queries.

## Class 6 — Timezone / date-boundary bugs
Server UTC vs business-local; day boundaries; future guards; period derivation.
- **Incidents:** monthly-billing cron tz (HIGH-1), Samsara distance tz (MED-7), QBO
  `TxnDate` future-guard, invoice issue/due + GL rev-rec dates derived from the
  billing period. (`project_cron_audit_findings`, recent commits)
- **Hunt:** `date()`/`strtotime()`/`new DateTime()` without explicit tz; comparisons
  of a stored UTC datetime to a local "today"; off-by-one on month boundaries; future
  dates sent to QBO.

## Class 7 — Counter / derived-state drift
A denormalized aggregate is updated on one transition but not its inverse.
- **Incident:** `customers.outstanding_balance` must reflect SENT invoices only;
  increment fires on draft→sent, but voids/drafts must be excluded. Drift of
  $61k+ was corrected once. (`project_path_b_counter_semantics`, `project_drift_remediation_history`)
- **Hunt:** every `SET col = col +/- ?` — find the inverse path (void, delete, revert,
  downgrade) and confirm it adjusts too. Reconcile the counter against a fresh
  aggregate query.

## Class 8 — Permission / auth / session gaps
- **Incidents:** CSRF check returning HTTP 200 on failure (S002); soft-deleted user
  re-invite hitting a 1062 duplicate-key crash instead of reviving (FLEETFORGE-F);
  MFA "off" still prompting because the setting never propagated to `users.mfa_required`,
  which is the column login actually gates on (`project_mfa_settings_audit`).
- **Hunt:** endpoints missing `require_permission`; UI hides a button but the API
  still accepts the call; `deleted_at`/`status` not re-checked on re-auth; settings
  that don't propagate to the column/flag the runtime reads.

## Class 9 — Soft-delete correctness
- **Hunt:** SELECTs/uniqueness checks missing `deleted_at IS NULL`; or *over*-strict
  uniqueness that blocks reusing a soft-deleted name/slug (the template-create slug
  loop explicitly excludes deleted rows so they can be reused — confirm peers do too).

## Class 10 — Transaction integrity & audit-in-txn
- **Hunt:** multi-table mutations not inside `db_transaction` → partial writes; an
  `audit_log` insert inside the txn whose own failure rolls back the real work;
  STALE_DATA/optimistic-lock (`updated_at`) paths that don't actually re-check.

## Class 11 — Cron / CLI / callback fatals not caught by unit tests
- **Incident cluster:** three prod fatals (T70/71/72) shared one cause — only
  executing the real script against real schema surfaced them; `php -l` and unit
  round-trips missed them. settings cols are `value_type`/`group_name`; there is **no
  `db_value`** helper. (`feedback_schema_real_smoke_coverage`)
- **Hunt:** run each cron/CLI as a subprocess against the real DB; check `www-data`
  crontab context on prod (`sudo -u www-data crontab -l`); OAuth/webhook callbacks
  that assume a session that isn't there.

## Class 12 — Integration contract drift (QBO / Samsara / Pusher)
- **Incidents:** wipe doesn't clear `acc_qbo_*_map`; payments need an FF
  undeposited-funds account mapped; QBO subtype taxonomy FF snake_case vs QBO
  PascalCase-plural must not be conflated (`AccountMatcher::SUBTYPE_EQUIVALENCE`);
  a new `{Entity}Pusher` shipped without its `{entity}.php` UI.
  (`project_dress_rehearsal_recon`, `project_qbo_subtype_taxonomy`, `feedback_ui_completeness_with_backend`)
- **Hunt:** mapping tables not cleared/seeded; enum/case mismatches across the
  boundary; one side of a sync without the other; webhook signature/idempotency.

## Class 13 — N+1, unbounded, and performance cliffs
- **Hunt:** list endpoints without a `per_page` cap; queries inside `foreach`;
  `SELECT *` feeding a tight loop; missing index on a hot filter column.

---

### Severity rubric
- **CRITICAL** — data loss/corruption, money wrong, prod fatal on a common path,
  auth bypass.
- **HIGH** — a core flow is broken or silently no-ops for real inputs (today's bug).
- **MEDIUM** — broken edge case, drift that accumulates, missing guard.
- **LOW** — cosmetic, rare, or defense-in-depth.
