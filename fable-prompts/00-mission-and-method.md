# Master Prompt — Mission & Method

> Read this in full before any domain prompt. It encodes the diagnostic
> discipline that found today's bug. Every domain prompt assumes you are
> operating under these rules and this method.

## Your mission

You are auditing **FleetForge** — a heavy-equipment rental/lease ERP (PHP 8 +
nginx/php-fpm backend, MySQL, vanilla JS + Alpine.js + a custom `FF_*` JS layer
on the front end). Find **real, reproducible bugs** end-to-end: in each module,
each endpoint, and each UI → API → DB flow. Not style nits. Not hypotheticals.
Bugs that would (or already do) bite a real operator.

The benchmark is the bug we just fixed:

> A rate card couldn't be created. The operator's theory was "new equipment
> types aren't saving to the database." **Wrong.** The types saved fine (verified
> by reading prod). The real bug: the create form auto-adds a blank item row and
> the "Add new +" button adds more, but client-side `validate()` required *every*
> row — including empty ones — to have an equipment type. A leftover blank row
> threw "Item 2: please select an equipment type" and the request never reached
> the API. Fix: ignore entirely-blank rows in validation + payload.

Two lessons drive this whole audit:

1. **The reported symptom and the real root cause are often in different layers.**
   "Data isn't saving" was actually "UI validation blocks submit." Always trace
   the *whole* path before concluding.
2. **The source of truth is the database and the running code — not the bug
   report, not the comments, not the docstring.** We only knew the types saved
   because we read the prod `equipment_templates` table.

## Ground rules (safety)

- **PROD IS READ-ONLY.** `ssh fleetforge` is the LIVE site (mainlandrentals.com).
  Read freely for diagnosis (`mysql -ufleetforge -p"$(grep ^DB_PASSWORD= /var/www/fleetforge/.env | cut -d= -f2-)" fleetforge -e "SELECT …"`).
  **Never** run prod writes, sends, deploys, `chmod`, settings changes, or crontab
  edits. Prepare the command, hand it to the operator. "Run against prod" in a task
  ≠ permission to mutate.
- **Reproduce locally.** Local dev DB: `mysql -uroot -p"$(grep DB_PASSWORD .env | cut -d= -f2-)" -h127.0.0.1 fleetforge`.
  Local site: `http://fleetforge.test` (Herd). Local super-admin login is in the
  repo-root `user-credentials` file. Local DB is largely seed data — when you need
  *real* data shapes, read prod (read-only) and reproduce the shape locally.
- **Never mutate state you didn't create.** When reproducing in the DB, wrap writes
  in `BEGIN … ROLLBACK` (the repo's smoke convention — see `tests/DbState.php`).
- **Don't fix during the audit unless told to.** Default output is a *findings
  report*. Fixing is a separate, triaged step with its own reviewable commits.

## The codebase contract (so you can read it fast)

**API endpoints** (`api/v1/<group>/<action>.php`) almost all follow this shape:

```php
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('POST');                  // 405 if wrong verb
require_auth_api();                       // 401 if no session
require_permission('module', 'action');   // 403 if not allowed
$body = json_body();                      // decoded JSON
$name = clean_string($body['name'] ?? null, 255);   // sanitizers: clean_string/clean_int/clean_decimal/clean_date
if (!$name) $fields['name'] = '…';
if ($fields) json_validation_error($fields);          // 422 {error:{fields:{…}}}
db_transaction(function() { db_insert(…); db_update(…); });   // DB helpers
json_success([...], 201);
```

DB helpers: `db_select` (rows), `db_row` (one), `db_insert` (returns id),
`db_update`, `db_execute`, `db_exists`, `db_transaction`. **There is no
`db_value` helper** — use `db_row(...)['col']`.

**Front end**: pages are PHP in `app/admin/<module>/`. Interactivity is Alpine
components (`x-data="FF_Thing()"`) talking to the API via the `FF_Api` wrapper
(`FF_Api.get/post`). Errors surface via `FF_Toast` / `FF_Validate` / inline
`globalError`. Record pickers use `FF_RecordPicker`.

**Tests**: `tests/_smoke_*.php` are *schema-real* regression smokes — they EXECUTE
the real script/class against the real `db.php` + schema (often inside
BEGIN/ROLLBACK), not unit round-trips. Run one: `php tests/_smoke_<name>.php`.
The harness is `tests/runner.php`. When you confirm a bug, the ideal deliverable
includes a new schema-real smoke that fails pre-fix and passes post-fix.

**Migrations** live in `db_migrations/` ONLY (`bin/migrate.php` scans there).
`database/migrations/` is a deprecated drift trap — ignore it.
`FLEETFORGE_DATABASE_MASTER.sql` is the authoritative schema.

## The method (do this for every endpoint / flow)

For each module, walk **every** page and endpoint. For each, run this loop:

### 1. Map the contract three ways
- **UI**: what does the Alpine component send? Read the `submit()/save()` payload
  builder and the client-side `validate()`. Note every field, default, and
  required check.
- **API**: what does the endpoint expect and enforce? Read the sanitizers,
  required checks, enum/whitelist checks, uniqueness checks, permission string.
- **DB**: what does the schema actually allow? Read the table in
  `FLEETFORGE_DATABASE_MASTER.sql` — column types, **enum members**, NOT NULL,
  defaults, unique keys, FKs, generated columns.

Then ask: **do the three agree?** Mismatches are where bugs live.

### 2. Hunt the specific failure modes (see `bug-taxonomy.md` for the full list)
The highest-yield questions, derived from this codebase's real bug history:
- **Client validation traps**: does the UI block legitimate submits? (today's bug)
  Blank/optional rows, over-strict required checks, regex that rejects valid input,
  date `min`/`max` attrs that fight real data.
- **UI ↔ API field-name / shape mismatches**: UI sends `search`, API reads `q`;
  UI sends a name, API/lookup keys on a category slug (the `S-RATES-UI-CATEGORY-DEDUP`
  bug — rate overrides silently failed); UI sends array, API expects object.
- **Enum / schema truncation**: code writes a value the column's enum doesn't list →
  under `STRICT_TRANS_TABLES` (dev AND prod) the INSERT throws 1265 and aborts the
  whole transaction (the `period_type='mileage_only'` bug).
- **Silent empty / silent skip**: a fetch 404s → caught → renders an empty selector
  with no error (the picker `base_url()` double-prepend bug); a gate filters all rows
  so nothing happens and nobody's told.
- **SQL param-order / placeholder bugs**: positional `?` bound in the wrong order →
  `WHERE role IN (7)` instead of the hour (the AI-digest-never-sent bug). 0 results
  forever, no error.
- **Timezone / date bugs**: server-UTC vs business-local; `date('Y-m-d')` boundaries;
  future-date guards; billing-period derivation (monthly-billing tz bug, QBO dating).
- **Counter / derived-state drift**: a denormalized counter (`outstanding_balance`,
  lease totals) updated on one path but not its inverse (void/draft) → drift.
- **Permission / auth gaps**: endpoint missing `require_permission`, or UI hides a
  control the API still honors; CSRF check that returns 200 on failure; soft-deleted
  row re-login/re-invite (the 1062 revive bug); MFA setting not propagated to the
  column login actually gates on.
- **Soft-delete correctness**: queries that forget `deleted_at IS NULL`, or uniqueness
  checks that don't, blocking reuse of a deleted name/slug.
- **Transaction integrity**: multi-write op not wrapped in `db_transaction` → partial
  writes on failure; audit-log insert inside the txn that can fail it.
- **N+1 / unbounded queries**: list endpoints with no pagination cap, per-row queries
  in a loop.

### 3. Verify against the source of truth — don't trust the report or the docstring
- If a flow "should" write a row, **read the table** and confirm it does (today's win).
- If a docstring says "items optional," confirm the code and UI agree.
- A `// KNOWN ISSUE:` comment is a lead, not a closed case — chase it (the rate-items
  file literally documents the category/name mismatch in a comment).

### 4. Reproduce
- Minimal repro: the exact request (curl or the Alpine payload) + the exact DB/UI
  state. Reproduce locally; wrap any write in BEGIN/ROLLBACK.
- `php -l` is necessary but **not sufficient** — it never catches undefined funcs,
  enum truncation, or logic bugs. Execute the real path.

### 5. Write the finding
Use `findings-template.md`. Every finding needs: severity, the three-layer contract
mismatch (or the precise logic error), a concrete repro, the root cause in one
sentence, and a fix sketch. If you're <80% sure it's real, mark it **SUSPECTED** and
say what would confirm it — don't pad the report with hunches presented as facts.

## What "done" means for a module

You have: opened every page and every endpoint in the module; traced each
mutating flow UI→API→DB; checked each against the taxonomy; reproduced every
CONFIRMED finding; and listed the endpoints you cleared (so coverage is auditable).
A module with zero findings is a valid result **only** if you can show the coverage.
