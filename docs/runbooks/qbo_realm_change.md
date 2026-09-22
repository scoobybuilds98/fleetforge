# QBO Realm ID Change Runbook

**Owner:** super_admin + accountant
**Last reviewed:** 2026-09-23 (S-QBO-GOLIVE-AUDIT — Step 2 is now the enforced realm guard + Reset button; was 2026-05-20 S-QBO-1)
**Spec ref:** FLEETFORGE_QUICKBOOKS_SPEC.md §5.4

---

## What is the realm ID?

The QBO realm ID identifies the QuickBooks Online company file. It is captured from the OAuth callback as `?realmId={...}` and stored in `settings.value` under the key `quickbooks.realm_id`. The value is **stable for the life of the QBO file** — under normal operation it never changes.

## When does it change?

The realm ID changes only when:

1. The accountant migrates the books to a brand-new QBO company file (e.g. company restructure, switch from QBO Self-Employed to QBO Plus, etc.).
2. The operator authorizes against a different sandbox company during development.
3. Pre-production cleanup: switching from sandbox to production at S-QBO-29 (the production cutover session) — this is a planned realm change, not the operational case this runbook covers.

If the realm ID ever changes silently (without the operator/accountant deciding it should), that is a **break-glass condition**: stop pushing immediately and reconcile from the QBO side before resuming.

## Consequences of a realm change

All FF↔QBO mappings reference QBO entity IDs that are **scoped to a realm**. A realm change invalidates every row in every `acc_qbo_*_map` table:

- Customer mappings (FF customer → QBO Customer.Id) — invalid.
- Vendor mappings — invalid.
- GL account mappings — invalid.
- Tax code mappings — invalid.
- Item / product mappings — invalid.
- Invoice / payment / credit-memo mappings — invalid (and irreparable — QBO never copies invoices into a new realm).
- Bank account mappings — invalid.

Historical data in QBO is **not migrated automatically** to the new realm — that is a separate accounting project the accountant handles inside QBO using QBO's export/import tools (and is out of scope for FF).

---

## Procedure

### Step 1 — Operator initiates the disconnect

1. Navigate to **Settings → QuickBooks → Settings** (`/quickbooks/settings`).
2. Click **Disconnect**. This:
   - Sets `quickbooks.connection_status = 'disconnected'`.
   - Clears `quickbooks.access_token`, `quickbooks.refresh_token`, `quickbooks.realm_id`, and the expiry timestamps.
   - Forces `quickbooks.sync_enabled = '0'` as a safety belt (so no cron job pushes to a partially-configured realm if the operator pauses mid-procedure).
   - **Does NOT wipe the `acc_qbo_*_map` tables** — that is the explicit next step, which the operator opts into deliberately.

### Step 2 — Reset the mappings (enforced since S-QBO-GOLIVE-AUDIT, 2026-09-23)

You no longer run SQL by hand. When the OAuth callback (Step 3) lands on a company whose realm differs from the one the mappings were built for (`quickbooks.mapped_realm_id`), FleetForge:

- sets `quickbooks.realm_mismatch = '1'` and forces `quickbooks.sync_enabled = '0'`;
- refuses EVERY QBO API call except CompanyInfo (`QuickBooksClient::realmGuardReason()`), pulls included — a pull would merge the new company's Ids into tables still holding the old company's;
- makes the worker exit and refuses to re-enable master sync;
- shows a red **"Sync blocked — different QuickBooks company"** banner on Settings → QuickBooks with a super-admin **Reset mappings for this company** button.

The button (`api/v1/quickbooks/reset_mappings.php` → `RealmGuard::resetMappings()`, type `RESET` to confirm) deletes every row in `RealmGuard::REALM_SCOPED_TABLES` (all 17 `acc_qbo_*_map` tables — a smoke asserts the list matches the schema), deletes queued/processing sync jobs, drops the old company's OPEN drift events, expires pending portal pay links, clears the QBO-Id settings (`tax_override_code_id`, `refund.deposit_account_id`, `refund.payment_method_id`), adopts the connected realm as `mapped_realm_id`, and writes one `delete / qbo_realm_change` audit row with per-table counts. The sync log, webhook log and historical-pull runs are realm-stamped history and are kept.

Preconditions (refused otherwise): master sync OFF, and connected to the company being adopted — so the order is now **disconnect → connect to the new company → Reset**.

### Step 3 — Operator re-authorizes against the new realm

1. From **Settings → QuickBooks**, click **Connect to QuickBooks**.
2. Authorize against the new QBO company file in the Intuit consent screen.
3. On successful callback, `quickbooks.realm_id` is populated with the **new** ID and `connection_status` flips to `'connected'`. If mappings from another company exist, the realm guard engages (Step 2) — run **Reset mappings for this company** now.

### Step 4 — Re-run mapping flows for the new realm

Execute the S-QBO-5 through S-QBO-10 mapping flows in order against the new realm:

| Session | Page | Mapping |
|---|---|---|
| S-QBO-8 | /quickbooks/mapping/accounts | Chart of Accounts |
| S-QBO-9 | /quickbooks/mapping/tax-codes | Tax Codes (including 'NON' override target) |
| S-QBO-10 | /quickbooks/mapping/items | Items / Products (17 FF item types) |
| S-QBO-5 | /quickbooks/mapping/customers | Customers |
| S-QBO-7 | /quickbooks/mapping/vendors | Vendors |
| S-QBO-20 | /quickbooks/mapping/bank-accounts | Bank Accounts |

> Page URLs above are the planned routes — current-session readers may not see all pages live yet. Substitute the actual URL surfaced in your sidebar at the time you run this runbook.

### Step 5 — Drift verification

1. Run the drift detection cron manually:
   ```
   php /var/www/fleetforge/cron/qbo_drift_check.php
   ```
   (cron added in S-QBO-24; absent today, this step is a forward-looking placeholder)
2. Confirm `acc_qbo_drift_events` has zero unresolved rows.
3. If drift is non-zero: do NOT flip `sync_enabled` back on. Open a manual reconciliation work item with the accountant.

### Step 6 — Re-enable master sync

After drift = 0 is confirmed:

1. Settings → QuickBooks → **Master Controls** card (super_admin only).
2. Flip `Master Sync Kill-Switch` back to on.
3. Watch the first 24 hours of sync activity via the Sync Log page.

---

## What NOT to do

- **Do NOT manually edit `quickbooks.realm_id` in the settings table.** The realm ID must come from a successful OAuth callback so the access + refresh tokens, expiry timestamps, and realm ID stay consistent. Editing the realm ID alone leaves the tokens dangling against the OLD realm — every API call will 401.
- **Do NOT clear `quickbooks.realm_mismatch` by hand.** Use the Reset button. Stale rows in `acc_qbo_*_map` will collide with the new realm's entity IDs, producing silent corruption (one FF entity mapped to two different QBO IDs across realms).
- **Do NOT re-enable `sync_enabled` before Step 5 confirms drift = 0.** Pushing against a partially-mapped realm produces partial JEs in QBO that the accountant will then have to clean up by hand.
- **Do NOT amend `quickbooks.sandbox_redirect_uri` between Step 1 and Step 3** unless the operator is also switching ngrok tunnels. The redirect URI sent during init.php must match the redirect URI sent during callback.php verbatim or Intuit returns `invalid_grant`.

---

## Rollback (when the new realm is wrong)

If Step 3 succeeds against the wrong realm:

1. Re-run Step 1 (disconnect).
2. Mapping tables are already empty from Step 2 — no further wipe needed.
3. Re-run Step 3 against the correct realm.
4. Re-run Step 4 mapping flows.

If Step 4 has already been started against the wrong realm:

1. Re-run Step 1 (disconnect).
2. Connect to the correct company, then run **Reset mappings for this company** again (Step 2) — they need to start empty again.
3. Re-authorize against the correct realm (Step 3).
4. Re-run Step 4 mapping flows from scratch.

---

## Audit reconstruction

Every step above writes an `audit_log` row with `module='quickbooks'`. After a realm change the operator should be able to reconstruct the timeline by:

```sql
SELECT created_at, action, entity_type, entity_label, notes
  FROM audit_log
 WHERE module = 'quickbooks'
   AND created_at > <date-of-realm-change>
 ORDER BY created_at;
```

That query should show, in order:
1. `delete` / `qbo_oauth_connection` — Step 1 disconnect.
2. `delete` / `qbo_realm_change` — Step 2 mapping wipe.
3. `create` / `qbo_oauth_connection` — Step 3 OAuth callback for the new realm.
4. A burst of `create` / `qbo_*_map_*` rows — Step 4 mapping seed.
5. `cron` / `qbo_drift_check_batch` — Step 5 drift verification.
6. `update` / `qbo_master_controls` — Step 6 sync_enabled flipped to '1'.

If any step is missing from the timeline, the change was not completed end-to-end — investigate before resuming production sync.
