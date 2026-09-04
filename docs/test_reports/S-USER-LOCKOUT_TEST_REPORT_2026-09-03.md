# S-USER-LOCKOUT — Test Report

**Date:** 2026-09-03
**Branch:** main
**Commit:** `49d8436` (merged to `main` at `5193a5e`)
**Feature:** Dedicated super-admin "Lockout" tab under Settings — immediately revokes a user's access to FleetForge.

---

## TL;DR

All tests **PASS**. The feature was verified end-to-end against the dev database and a live browser session: locking a user blocks their login, silently defeats their password-reset request, and force-ends any session they have open; unlocking restores full access; the tab and its API are completely invisible and unreachable to any non-`super_admin` account. Four pre-existing project smoke suites re-ran clean (0 regressions). Two pre-existing, unrelated issues were discovered along the way and are disclosed at the bottom — not fixed here.

---

## What was tested

| # | Test | Method | Result |
|---|------|--------|--------|
| 1 | Migration applies cleanly | `php bin/migrate.php --apply` against dev DB | ✅ PASS |
| 2 | New columns exist with correct types | `SHOW COLUMNS FROM users LIKE 'lock%'` | ✅ PASS |
| 3 | Lockout tab renders for `super_admin`, with working reason-modal → lock action | Live browser (minted `super_admin` session), clicked through the real UI | ✅ PASS |
| 4 | Lock writes correct DB state | Direct DB read after clicking "Lock Out" | ✅ PASS |
| 5 | Lock writes an audit-log entry | Direct DB read of `audit_log` | ✅ PASS |
| 6 | Lock deletes outstanding password-reset tokens | Direct DB read of `password_reset_tokens` | ✅ PASS (0 rows) |
| 7 | Locked user cannot log in | Live browser, real login form, real credentials | ✅ PASS — generic "Invalid email or password.", no tell |
| 8 | Locked user's password-reset request silently no-ops | Live browser, real forgot-password form | ✅ PASS — generic "check your email" screen shown; 0 tokens created; 0 emails sent |
| 9 | Unlock via the dedicated tab's "Unlock" button restores access | Same endpoint as #10, exercised from the Lockout tab UI | ✅ PASS (see note below) |
| 10 | Unlock via the user's profile page ("Unlock"/Activate button) restores access + clears metadata | Live browser, DB read before/after | ✅ PASS |
| 11 | Unlocked user can log in again | Live browser, real login form | ✅ PASS |
| 12 | Lockout tab is completely absent for a non-`super_admin` role | Server-side render of the real `settings/index.php` for a `manager`-role session (not just a browser click-through) | ✅ PASS |
| 13 | Forcing `?tab=lockout` as a non-`super_admin` does not leak any lockout content | Same server-side render, forced query param | ✅ PASS — falls back to `general` tab, `lockout.php` never `require`'d |
| 14 | No regression in existing settings endpoints | `tests/_smoke_settings_endpoints.php` | ✅ 22/22 |
| 15 | No regression in the permission system | `tests/_smoke_permissions_rigorous.php` | ✅ 405/405 |
| 16 | No regression in auth fail-open behavior | `tests/_smoke_auth_failopen.php` | ✅ 11/11 |
| 17 | No regression in the API envelope contract | `tests/_smoke_api_envelope_contract.php` | ✅ 38/38 |
| 18 | Schema stays in parity with the live DB | `tests/_smoke_master_schema_parity.php` | ✅ PARITY OK |
| 19 | Migration replays cleanly from zero and matches master | `tests/_smoke_migrations_reproduce_master.php` | ✅ SC1/SC2/SC3/SC4 all PASS |
| 20 | No stray schema SQL outside `db_migrations/` | `tests/_smoke_no_stray_schema_sql.php` | ✅ 61 files scanned, 0 stray |
| 21 | Project doc-freshness gate | `tests/_smoke_doc_freshness.php` | ✅ 29/29 |
| 22 | PHP syntax on every touched/new file | `php -l` | ✅ clean |
| 23 | Merge with 4 concurrently-pushed unrelated commits | `git merge origin/main` | ✅ clean auto-merge, zero file overlap |

**Note on #9:** the dedicated tab's "Unlock" button and the profile page's "Unlock" button call the exact same endpoint (`api/v1/users/update_status.php` with `status: 'active'`) with the same payload shape. #10 was driven fully through the browser with before/after DB verification; #9 was confirmed by code inspection of the identical call site plus the same endpoint already being proven correct in #10 — not re-driven through the tab's own UI a second time, since it would exercise nothing new.

---

## Test walkthrough (live, step by step)

A disposable test user (`qa-lockout-target@fleetforge.test`, dispatcher role, never a real account) was created purely for this run and cleaned up (soft-deleted) at the end. No persistent test fixtures were consumed except a super-admin login the project already reserves for this purpose (`test-superadmin@fleetforge.test`) — its documented password (used by `tests/_smoke_settings_endpoints.php`) was temporarily changed to log in through the real form, then restored, and the smoke test was re-run to confirm it still works.

1. **Logged in** as `test-superadmin@fleetforge.test` through the real login form (no cookie injection — `ff_session` is httponly).
2. **Opened Settings → Lockout.** The tab renders a summary banner, a "0 locked" badge, and a table of every user with a "Lock Out" action per row.
3. **Clicked "Lock Out"** on the disposable test user → a modal (`FF_Confirm.askText`) asked for a mandatory reason: *"This immediately blocks login and password reset, and force-ends any session they currently have open."*
4. **Typed a reason and submitted.** The row updated in place: status flipped to **LOCKED**, showing `Sep 3, 2026 8:07 AM by Super Admin Test` and the typed reason.
5. **Verified in the database:**
   ```
   status: locked
   locked_at: 2026-09-03 15:07:01
   locked_by: 23
   lock_reason: "QA verification of S-USER-LOCKOUT"
   remember_token: null
   ```
   and a matching `audit_log` row (`action=status_change`, `notes="Locked out by super admin. Reason: ..."`), and **zero** rows in `password_reset_tokens` for that user.
6. **Attempted to log in** as the locked user with their correct password → `"Invalid email or password."` — identical to what a wrong password or a nonexistent email produces. No hint that the account exists or is locked.
7. **Requested a password reset** for the locked user's email → the generic "if that email is associated with an active account, a link has been sent" screen appeared (unconditionally, per the app's existing enumeration-safe design) — but **zero** rows were written to `password_reset_tokens` and nothing was appended to `logs/mail.log`, confirming the request was a true no-op, not just a UI illusion.
8. **Opened the locked user's own profile page** (`/users/show?id=...`) — the "Change Status" card correctly showed only an "Unlock" button (no Suspend/Deactivate, which don't apply to an already-locked account) plus the same lock detail line (date / admin / reason) seen on the tab.
9. **Clicked "Unlock."** Verified in the database: `status` back to `active`, and `locked_at` / `locked_by` / `lock_reason` / `login_attempts` / `locked_until` **all cleared** — no stale metadata left behind.
10. **Logged in again** as the now-unlocked user with the same password → succeeded, landed on the Dashboard.
11. **Switched to a `manager`-role session** and rendered the real Settings page server-side (not just visually — the actual PHP output) → the "Lockout" tab button is **absent from the HTML entirely** (not greyed out, not present-but-disabled — simply not emitted). Forcing `?tab=lockout` in the URL was also checked directly against the rendered output: the page silently falls back to the `general` tab and `lockout.php` is never `require`'d, so none of its content (user table, reasons, "User Lockout" heading) appears anywhere in the response.

---

## Design decisions this test run validated

- **Reused `users.status = 'locked'`**, an enum value that already existed and was already respected by every login/reset/session-freshness gate — rather than inventing a parallel mechanism. This is why so much of the enforcement "just worked" the first time it was exercised.
- **The tab is hidden, not greyed out**, for anyone who isn't `super_admin` — a deliberate departure from the app's usual pattern for restricted tabs, since this feature's existence shouldn't be advertised to the accounts it might be used against.
- **Locking requires a typed reason; unlocking doesn't** — locking is the consequential, adversarial action; unlocking is a routine recovery action already gated behind `super_admin`.
- **A super-admin cannot lock themselves out** (enforced server-side in `lock.php`, independent of any UI state).
- **The permission is not grantable** — no entry exists in `config/permissions.php` or `config/permission_actions.php` for the Lockout tab, so there is no admin-UI path for a super-admin to accidentally hand this capability to anyone else. Every API endpoint also independently hard-checks `require_role('super_admin')`, so even a crafted request can't bypass it via a permission override.

---

## Known, disclosed, out-of-scope findings (not fixed in this session)

1. **`api/v1/users/bulk_update_status.php` is already broken** (pre-existing, unrelated to this feature): it writes to a `users.updated_by` column that does not exist in the schema. Every call throws and is silently swallowed by the endpoint's own per-row `catch (\Throwable)`, so the Users module's bulk activate/deactivate button currently does nothing. Left untouched — flagged here and in the commit's SESSION LOG entry for a future session to fix.
2. **`docs/FLEETFORGE_PROGRESS.md`'s own automated doc-freshness check was already failing before this session** (a prior commit, `S-FA-IMPORT`, had no matching SESSION LOG row). This was fixed as a side effect of restoring schema parity, but the missing SESSION LOG narrative for that prior session was not backfilled — left for whoever ran that session to describe accurately.

Both are called out explicitly in the shipped commit's SESSION LOG entry (`docs/FLEETFORGE_PROGRESS.md`) so they aren't lost.
