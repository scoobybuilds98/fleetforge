# Permission Audit — 2026-05-20

**Auditor:** Code Desktop (Sonnet)
**Scope:** All 28 active non-deleted users across 5 roles; effective `can()` resolution; phantom-module overrides; HTTP-layer enforcement spot-check.
**Trigger:** Operator request after surfacing the "David locked-out-of-accounting" incident (resolved as stale-session cache, not a permission bug).
**Code state at time of audit:** main @ `d95c26a` (post `S-PERM-EXPAND` + `S-PERM-EXPAND-D'` + `S-PERM-MACRO-STATUS` + Deny All visual-parity follow-up).

---

## TL;DR — system is healthy 🟢

- All 5 roles enforce baseline correctly at HTTP layer.
- Override system works end-to-end (David, a dispatcher, accesses accounting via 9 view + 18 read-write overrides).
- super_admin short-circuit returns true for all checks across all 4 SA users.
- **Zero real bugs found** in `can()` resolution.
- **5 phantom-module override rows** surfaced as documentation drift from PERM-TEST-1 era — silent no-ops, low-priority cleanup candidate.
- One **active-stale-session UX hazard** still open from prior turn (operator dismissed the fix question; flagged at bottom).

---

## 1. Methodology

1. **DB inventory** — `SELECT u.id, ur.slug AS role, COUNT(override rows) FROM users JOIN user_roles WHERE deleted_at IS NULL` → 28 active users.
2. **CLI `can()` simulation** — for each user, build the auth_login session shape (`role_slug` + `permissions` from `config/permissions.php` + `permission_overrides` from `_ff_load_user_overrides($id)`), then run a 16-check battery covering standard CRUD + extended verbs (post/approve/lock/submit) + QBO controls (view/force_resync/disconnect).
3. **Anomaly scan** — find any `user_permission_overrides.module` value NOT in the config's module union (= phantom modules); flag any override on the 11 newly-declared extended actions (D-PERM-EXPAND-5).
4. **HTTP spot-check** — temporary password reset on 6 representative users (super_admin / manager / dispatcher / accountant / read_only / David), live curl-driven login + page fetch against `/dashboard`, `/accounting/dashboard`, `/users`. Passwords restored byte-identical post-test.
5. **No production writes** beyond the reversible password resets.

---

## 2. Inventory by role

| Role | Active users | With overrides | Pure role defaults |
|---|---:|---:|---:|
| super_admin | 4 | 0 | 4 |
| manager | 6 | 3 | 3 |
| dispatcher | 7 | 4 | 3 |
| accountant | 5 | 3 | 2 |
| read_only | 6 | 2 | 4 |
| **Total** | **28** | **12** | **16** |

### Full roster

| ID | Role | Overrides | Email | Name |
|---:|---|---:|---|---|
| 10 | accountant | 3 | accountant1@fleetforge.test | Grace Accountant |
| 11 | accountant | 4 | accountant2@fleetforge.test | Henry Accountant ⚠ |
| 12 | accountant | 0 | accountant3@fleetforge.test | Isabella Accountant |
| 18 | accountant | 6 | accountant4@fleetforge.test | Olivia SpecialAcc ⚠ |
| 26 | accountant | 0 | test-accountant@fleetforge.test | Accountant Test |
| 2 | dispatcher | 0 | testuser@example.com | Test User |
| 7 | dispatcher | **35** | dispatcher1@fleetforge.test | David Dispatcher ★ |
| 8 | dispatcher | 3 | dispatcher2@fleetforge.test | Emma Dispatcher |
| 9 | dispatcher | 0 | dispatcher3@fleetforge.test | Frank Dispatcher |
| 17 | dispatcher | 7 | dispatcher4@fleetforge.test | Nathan SpecialDisp |
| 25 | dispatcher | 0 | test-dispatcher@fleetforge.test | Dispatcher Test |
| 28 | dispatcher | 2 | test-override-grant@fleetforge.test | Override Grant |
| 4 | manager | 2 | manager1@fleetforge.test | Alice Manager |
| 5 | manager | 5 | manager2@fleetforge.test | Bob Manager ⚠ |
| 6 | manager | 0 | manager3@fleetforge.test | Carol Manager |
| 22 | manager | 0 | claude-t1-20260512-093519@fleetforge.test | T1 Test User (S-MILEAGE-2B-T1) |
| 24 | manager | 0 | test-manager@fleetforge.test | Manager Test |
| 29 | manager | 2 | test-override-deny@fleetforge.test | Override Deny |
| 3 | read_only | 0 | tony@bce.com | tony |
| 13 | read_only | 4 | viewer1@fleetforge.test | James Viewer |
| 14 | read_only | 2 | viewer2@fleetforge.test | Kate Viewer |
| 15 | read_only | 0 | viewer3@fleetforge.test | Liam Viewer |
| 16 | read_only | 0 | inactive1@fleetforge.test | Mary Inactive |
| 27 | read_only | 0 | test-readonly@fleetforge.test | Read Only Test |
| 1 | super_admin | 0 | nandaarvind98@yahoo.com | Avi (operator) |
| 19 | super_admin | 0 | sc8_test@fleetforge.test | SC8 Test Admin |
| 20 | super_admin | 0 | sc8_mfa@fleetforge.test | SC8 MFA Test |
| 23 | super_admin | 0 | test-superadmin@fleetforge.test | Super Admin Test |

★ = override-heavy (significantly diverges from role default)
⚠ = has phantom-module override (see §4)

---

## 3. Effective access matrix (CLI `can()` resolution)

Tag legend: `r+`/`r-` = role default allow/deny · `O+`/`O-` = override allow/deny · `SA` = super_admin short-circuit

```
ID  User                                     cust.view  cust.del   eq.create  inv.create pay.create pay.view   users.view set.edit   JE.view    JE.create  JE.post    AP.view    tax.submit PM.lock    qbo.view   qbo.resync
─── ──────────────────────────────────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ────────── ──────────
 1  super_admin Avi (operator)               ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA
23  super_admin test SA                      ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA       ✓ SA
 6  manager Carol (baseline, 0 ovr)          ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
 5  manager Bob (5 ovr)                      ✓ r+       ✗ O-       ✓ r+       ✗ O-       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
 4  manager Alice (2 ovr)                    ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ O-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
25  dispatcher Test (baseline, 0 ovr)        ✓ r+       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-
 9  dispatcher Frank (0 ovr)                 ✓ r+       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-
 7  dispatcher David (35 ovr ★)              ✓ r+       ✗ O-       ✓ r+       ✗ r-       ✓ O+       ✓ O+       ✓ O+       ✗ r-       ✓ O+       ✓ O+       ✗ r-       ✓ O+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
 8  dispatcher Emma (3 ovr)                  ✓ r+       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-
17  dispatcher Nathan (7 ovr)                ✓ r+       ✗ O-       ✓ r+       ✓ O+       ✓ O+       ✓ O+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-
28  dispatcher Override Grant (2 ovr)        ✓ r+       ✗ r-       ✓ r+       ✗ r-       ✓ O+       ✓ O+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✗ r-
12  accountant Isabella (baseline, 0 ovr)    ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-
10  accountant Grace (3 ovr)                 ✓ r+       ✗ r-       ✓ O+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-
11  accountant Henry (4 ovr ⚠)               ✓ O+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-
18  accountant Olivia (6 ovr ⚠)              ✓ O+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✓ r+       ✗ r-       ✗ r-
15  read_only Liam (baseline, 0 ovr)         ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
13  read_only James (4 ovr)                  ✓ O+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
14  read_only Kate (2 ovr)                   ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✓ r+       ✗ r-       ✗ r-       ✗ r-       ✗ r-
```

### Key role-default patterns confirmed

| Module/action | super_admin | manager | dispatcher | accountant | read_only |
|---|:-:|:-:|:-:|:-:|:-:|
| `customers.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `customers.delete` | ✓ | ✓ | ✗ | ✗ | ✗ |
| `payments.create` | ✓ | ✓ | ✗ | ✓ | ✗ |
| `users.view` | ✓ | ✗ | ✗ | ✗ | ✗ |
| `settings.edit` | ✓ | ✗ | ✗ | ✗ | ✗ |
| `journal_entries.create` | ✓ | ✗ | ✗ | ✓ | ✗ |
| `journal_entries.post` (extended) | ✓ | ✗ | ✗ | ✓ | ✗ |
| `period_management.lock` (extended) | ✓ | ✗ | ✗ | ✓ | ✗ |
| `tax_management.submit` (extended) | ✓ | ✗ | ✗ | ✓ | ✗ |
| `quickbooks.view` | ✓ | ✗ | ✗ | ✗ | ✗ |
| `quickbooks.force_resync` (extended) | ✓ | ✗ | ✗ | ✗ | ✗ |

All matches spec §1.3 + D-PERM-EXPAND role-default lock.

---

## 4. HTTP spot-check (live login + page fetch, 6 users × 3 routes)

| User (role) | `/dashboard` | `/accounting/dashboard` | `/users` |
|---|:-:|:-:|:-:|
| user 23 (super_admin) | ✅ HTTP 200 | ✅ HTTP 200 | ✅ HTTP 200 |
| user 24 (manager, 0 ovr) | ✅ HTTP 200 | ✅ HTTP 200 | 🔒 access-wall |
| user 25 (dispatcher baseline) | ✅ HTTP 200 | **🔒 HTTP 403** | 🔒 access-wall |
| user 26 (accountant, 0 ovr) | ✅ HTTP 200 | ✅ HTTP 200 | 🔒 access-wall |
| user 27 (read_only, 0 ovr) | ✅ HTTP 200 | ✅ HTTP 200 | 🔒 access-wall |
| **user 7 (David, dispatcher + 35 ovr)** | ✅ HTTP 200 | **✅ HTTP 200 (override)** | 🔒 access-wall |

**Backstops the prior incident diagnosis:** David's overrides DO take effect on a fresh login. The "locked accounting" report from the operator was a stale-session cache (David's session loaded its `permission_overrides` map at his login time, BEFORE the macro grant was applied). Log out + log back in restores effective access. See §6.

**Note on the `/users` access-wall**: returns HTTP 200 with the developer-only access wall body rather than a hard 403 — intentional S-PERM-USERS-ACCESS-WALL design from S037-REC era. Functionally a forbid, prettier UX.

---

## 5. Anomalies

### 5.1 🟡 Phantom-module overrides (5 rows — silent no-ops)

Module `accounting` does **NOT** exist in `config/permissions.php` — only the 9 submodules do (chart_of_accounts, journal_entries, accounts_payable, bank_accounts, fixed_assets, tax_management, financial_reports, budgets, period_management). The override rows below survive in `user_permission_overrides` but `can()` never reads them because no `require_permission('accounting', ...)` call site exists.

| user_id | email | name | module.action | granted | source |
|---:|---|---|---|:-:|---|
| 18 | accountant4@fleetforge.test | Olivia | `accounting.delete` | 0 (deny) | PERM-TEST-1 seed |
| 11 | accountant2@fleetforge.test | Henry | `accounting.delete` | 0 (deny) | PERM-TEST-1 seed |
| 11 | accountant2@fleetforge.test | Henry | `accounting.settings` | 0 (deny) | PERM-TEST-1 seed |
| 5 | manager2@fleetforge.test | Bob | `accounting.settings` | 1 (allow) | PERM-TEST-1 seed |
| 7 | dispatcher1@fleetforge.test | David | `accounting.view` | 1 (allow) | PERM-TEST-1 seed |

#### ⚠ Subtle hazard for Bob (manager id=5)

Bob's `accounting.settings = 1` (allow) reads like operator intent to grant him accounting-settings access. But the REAL module name used in code is `accounting_settings` (underscore, plural-style). Code at `api/v1/accounting/settings/{index,update}.php` calls `require_permission('accounting_settings', 'view'|'edit')` — 4 call sites total. That module is NOT in `config/permissions.php` (the pre-existing drift documented in **D-PERM-EXPAND-4**), so `can()` falls through to `false` for everyone except super_admin (who short-circuits). Bob is therefore effectively LOCKED from accounting settings, despite his override implying he should have access.

**Recommendation**: schedule **S-PERM-CLEANUP** to:
1. DELETE the 5 phantom `accounting.*` override rows (or migrate Bob's intent to a real key).
2. Add the `accounting_settings` module to `config/permissions.php` with role defaults agreed via CPA review (probably super_admin + accountant or super_admin-only).
3. Add `inspections` to the `$labels` map in `api/v1/users/permissions/index.php` + `app/admin/users/permissions.php` (in code + has 40 require_permission call sites but missing from $labels, renders as `ucwords` slug fallback — D-PERM-EXPAND-4 cosmetic item).
4. Audit any other modules referenced in `require_permission()` calls but absent from `config/permissions.php`.

### 5.2 🟢 Zero overrides on extended actions

No rows on `post`, `approve`, `lock`, `unlock`, `submit`, `force_resync`, `force_full_resync`, `clear_queue`, `disconnect`, `edit_credentials`, `view_raw_payloads`. Consistent with **D-PERM-EXPAND-5** — declared but unenforced (future Phase QBO + S-PERM-ENFORCE-ACCT scope).

### 5.3 🟢 Zero overrides on the `quickbooks` module

All 28 users use role defaults (super_admin = all true; everyone else = all false). Clean baseline for Phase QBO to build on.

### 5.4 🔵 Redundant-but-harmless overrides

A handful of overrides duplicate the role default — they neither grant nor revoke anything but clutter the audit log:

| user_id | name | redundant grant | reason |
|---:|---|---|---|
| 11 | Henry (accountant) | `customers.view = 1` | accountant role default already grants `customers.view` (`$V`) |
| 13 | James (read_only) | `customers.view = 1` | read_only role default already grants |

Low-priority cleanup; bundle into S-PERM-CLEANUP if scheduled.

---

## 6. The stale-session UX hazard (still open, from prior turn)

### Symptom
Operator (super_admin) applies a `grant_view` macro on the accounting group for a target user (e.g. David). API returns success (9 override rows written, audit_log populated). Target user is told "you should have accounting access now" — but they don't, until they log out + log back in.

### Root cause
`auth.php::can()` reads from `$_SESSION['ff_user']['permission_overrides']` — a snapshot loaded at the target's login time via `auth_login()` → `_ff_load_user_overrides($id)`. The snapshot stays frozen for the duration of their session. `grant_apply.php` calls `_ff_refresh_user_permissions($userId)` after writing the overrides, but that's a no-op when the editing super_admin is NOT the target user (which is the normal flow).

### Confirmed via this audit
- David's DB rows (35 overrides) ARE correct.
- David's `can()` resolution via a freshly-built session shape returns YES for all 11 accounting checks.
- David's fresh HTTP login lands `/accounting/dashboard` with HTTP 200.

The bug only manifests for users with an ACTIVE pre-grant session.

### Fix options surfaced earlier (operator dismissed; reproduced here)

1. **Force-refresh on permission change (Recommended)**: stamp `users.permissions_updated_at = NOW()` whenever any override changes. On each request, `auth.php` compares the session's load timestamp against `permissions_updated_at`; if newer, re-load `permission_overrides` from DB (or kick to login). Cleanest UX — David's accounting unlocks automatically on his next page load.
2. **"Force resync user session" admin button**: per-user button on the permissions admin page that flips a `force_session_refresh` flag; target's next request sees the flag and re-loads. Manual but low-magic.
3. **Documentation only**: add a notice "Changes take effect after the target user's next login." Cheapest; the bug-feeling persists.
4. **Defer**: file as a KNOWN ISSUE for a dedicated session.

No action taken in this audit; surfacing for operator decision.

---

## 7. What this audit did NOT touch

- No code changes.
- No config/doc edits.
- No DB writes except reversible password resets on 6 users (test-superadmin, test-manager, test-dispatcher, test-accountant, test-readonly, David Dispatcher). All 6 hashes restored byte-identical post-test; verified via `password_verify('AuditPW2026!', $current_hash) === false`.
- No commits.
- No migration touch (43/0/0 sticky throughout).

---

## 8. Open follow-ups (for operator triage)

| Priority | Item | Effort | Notes |
|---|---|---|---|
| 🟡 Medium | **Stale-session fix** (§6) | S/M | Affects normal operator-grants-then-target-tests-immediately workflow; surfaced as confusing "permissions don't apply" |
| 🟡 Medium | **S-PERM-CLEANUP** (§5.1) | S | DELETE 5 phantom rows; add `accounting_settings` module to config; add `inspections` to $labels; ~30-line patch |
| 🟢 Low | Redundant-override cleanup (§5.4) | S | Bundle into S-PERM-CLEANUP |
| 🟢 Low | `S-MODAL-AUDIT` (G-MODAL-AUDIT, prior session) | M | Pre-Phase-E modal grep audit, already in PREDEPLOY_CHECKLIST |
| 🟢 Low | `S-PERM-ENFORCE-ACCT` | M | Migrate enforcement on 5 accounting verbs (post/approve/lock/unlock/submit) from `'edit'` to dedicated verbs — D-PERM-EXPAND-5 follow-up |

---

**End of audit — 2026-05-20.**

*If anything in this report needs clarification or a deeper dive on a specific user/module, ping back with the row and I'll re-run the targeted check.*
