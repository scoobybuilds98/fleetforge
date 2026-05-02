# S-PROD-1A Auth Surface Test — Full Findings

**Date:** 2026-05-02  
**Branch:** main  
**Commits verified:** S-PROD-1A (3048b1f), S-PROD-1A-FIX (c8cda64)  
**Test runner:** Claude Code comprehensive auth surface test (35 tests)

---

## TL;DR

One **CRITICAL BLOCKER** (T34) prevents all password reset testing and blocks S-PROD-1B.
One **HIGH** Alpine gap (T7) breaks the MFA forced-setup flow for all manager/super_admin first logins.
One **HIGH** session flag leak (T17) allows TOTP secret rotation after forced setup.
Five planned fix sessions (S-PROD-1A-FIX-3 through FIX-5) were never committed to main.

---

## Git State

| Expected Commit | Present? | Notes |
|----------------|----------|-------|
| S-PROD-1A (3048b1f) | ✅ | Initial MFA + rate limiting hardening |
| S-PROD-1A-FIX (c8cda64) | ✅ | T6 auth gap + T18 forgot_password + routing fix (3-in-1) |
| S-PROD-1A-FIX-2 | ❌ | Not a separate commit — subsumed in S-PROD-1A-FIX |
| S-PROD-1A-FIX-3 (Alpine standalone head) | ❌ | Planned but never committed |
| S-PROD-1A-FIX-4 (Bundle 1) | ❌ | Planned but never committed |
| S-PROD-1A-FIX-4-HOTFIX (password_reset_tokens) | ❌ | No named commit — but table exists and code uses it (likely pre-existing) |
| S-PROD-1A-FIX-5 (Bundle 2 Alpine self-host) | ❌ | Not run |

---

## Schema State (T2) — PASS

All required tables and columns present:

| Item | Status |
|------|--------|
| users.mfa_enabled TINYINT(1) | ✅ |
| users.mfa_secret VARCHAR(500) | ✅ |
| users.mfa_enabled_at DATETIME nullable | ✅ |
| users.mfa_required TINYINT(1) | ✅ |
| user_mfa_backup_codes table | ✅ |
| rate_limit_attempts table | ✅ |
| password_reset_tokens table | ✅ (Option A — multi-token) |
| users.password_reset_token / password_reset_expiry | ✅ exists but unused (orphaned Option B columns, harmless) |

**Password reset architecture: Option A** — separate `password_reset_tokens` table with `(id, user_id FK, token_hash, expires_at, used_at)`. Code in both forgot_password.php and reset_password.php confirmed to use this table. T11 (token overwrite test) is N/A.

---

## Settings State (T3) — PASS

All 16 `security.*` rows present with correct defaults:

```
security.rate_limit.login_ip_threshold           = 20
security.rate_limit.login_ip_window_minutes      = 15
security.rate_limit.login_ip_block_minutes       = 60
security.rate_limit.forgot_password_ip_threshold = 5
security.rate_limit.forgot_password_ip_window_minutes = 60
security.rate_limit.forgot_password_ip_block_minutes  = 60
security.rate_limit.ai_user_threshold            = 60
security.rate_limit.ai_user_window_minutes       = 60
security.rate_limit.mfa_ip_threshold             = 10
security.rate_limit.mfa_ip_window_minutes        = 15
security.rate_limit.mfa_ip_block_minutes         = 60
security.rate_limit.mfa_user_threshold           = 5
security.rate_limit.mfa_user_window_minutes      = 15
security.mfa.required_roles                      = ["super_admin","manager"]
security.mfa.totp_window                         = 1
security.mfa.backup_code_count                   = 10
```

---

## .env State (T4) — FAIL (LOW)

| Key | Status |
|-----|--------|
| FF_MFA_SECRET_KEY | EXISTS (value hidden) ✅ |
| FF_ASSET_VERSION | 1.0.18 ❌ — expected ≥1.0.19 (Bundle 1) or ≥1.0.20 (Bundle 2) |

Version stuck at 1.0.18 confirms neither Bundle 1 nor Bundle 2 were applied.

---

## User mfa_required Matrix (T5) — PASS

| User | Role | mfa_required | mfa_enabled |
|------|------|-------------|------------|
| nandaarvind98@yahoo.com | super_admin | 1 ✅ | 1 (setup complete) |
| manager1@fleetforge.test | manager | 1 ✅ | 0 |
| manager2@fleetforge.test | manager | 1 ✅ | 0 |
| manager3@fleetforge.test | manager | 1 ✅ | 0 |
| dispatcher1–4, testuser | dispatcher | 0 ✅ | 0 |
| accountant1–4 | accountant | 0 ✅ | 0 |
| viewer1–3, tony, inactive1 | read_only | 0 ✅ | 0 |

All privileged roles correctly required. All non-privileged roles correctly unrequired.

---

## Cache-Control Headers (T6) — PASS

All auth/MFA pages return `Cache-Control: no-store, no-cache, must-revalidate` and `Pragma: no-cache`. Source: PHP default `session.cache_limiter='nocache'` — fires automatically on any page that starts a session. M3 (explicit header() calls from FIX-4) was never applied, but PHP session behaviour delivers the same result. Functionally equivalent.

---

## BLOCKER: reset_password.php is_active (T34) — CRITICAL FAIL

**File:** `app/auth/reset_password.php:60`  
**Bug:** `AND u.is_active = 1`  
**Root cause:** `users` table has no `is_active` column — schema uses `status ENUM('active',...)`. Column `is_active` does not exist on the `users` table.  
**Effect:** `db_row()` call wrapped in `try/catch Throwable` → catches the SQLSTATE[42S22] → returns `null` → `find_valid_reset_token()` returns `null` → page renders "Link expired or invalid" for every valid token.  
**User experience:** Password reset emails send correctly (forgot_password.php was fixed in S-PROD-1A-FIX). But every link in those emails is broken. Users who request a reset receive an email, click the link, and see a dead-end error.  
**Fix:** One line — change line 60 from:
```php
AND u.is_active = 1
```
to:
```php
AND u.status = 'active'
```
This is the identical fix applied to forgot_password.php in S-PROD-1A-FIX (Audit Finding D1 / Fix B1). Was planned for S-PROD-1A-FIX-4 Bundle 1 but never committed.

**Sweep:** All other `is_active` references in the codebase are against tables that DO have an `is_active` column (`acc_accounts`, `yards`, `equipment_templates`, `acc_bank_accounts`). No other `users.is_active` SQL references found.

---

## HIGH: Alpine Missing in Forced-Setup Mode (T7)

**File:** `app/admin/account/mfa_setup.php` (standalone head, lines 60–80)  
**Bug:** The forced-setup standalone HTML head loads `app.js` (defer) but has NO Alpine.js script tag. `app.js` (184KB) does NOT bundle Alpine — it contains Alpine component factories that depend on Alpine being loaded separately.  
**footer.php** (used in voluntary setup path) loads Alpine from CDN at line 169. Forced-setup mode does not include footer.php.

**Effect:** Any user with `mfa_required=1` hitting their first post-migration login will land on `mfa_setup.php` in forced-setup mode with Alpine missing. All three steps render stacked immediately (no `x-show` bindings, no `x-cloak`). This is the original reported symptom. Three manager accounts (`manager1–3`) will hit this on first login.

**Fix:** Add one script tag to the standalone head in `mfa_setup.php`, after the `app.js` line (~line 70):
```html
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
```
(Or point to the local vendored file once Bundle 2 runs.)

This was planned as S-PROD-1A-FIX-3 but never committed.

**Voluntary setup path (via profile page while fully logged in) works correctly** — uses footer.php which loads Alpine from CDN.

---

## HIGH: ff_mfa_must_setup Not Cleared After Setup (T17)

**File:** `api/v1/account/mfa/setup_verify.php`  
**Bug:** After successful MFA setup, the endpoint unsets `$_SESSION['ff_mfa_setup']` but does NOT unset `$_SESSION['ff_mfa_must_setup']`.  
**Effect:** After a forced-setup user completes the 3-step wizard, the flag persists in their session. The JS redirects to `/auth/login` after 2 seconds (correct). But if the user navigates directly to `/account/mfa_setup` before their next login, `$forcedSetup = !empty($_SESSION['ff_mfa_must_setup'])` is still `true`, and the page allows them to call `setup_init` again — silently rotating their TOTP secret and invalidating their authenticator app.

**Additional gap:** After forced setup completes, `auth_login()` is never called — the user is not logged in and must go through the full login flow again (password + new TOTP). This is the intended design per the JS redirect to `/auth/login`. However, it means the forced-setup user's session has a live `ff_mfa_must_setup` between completing setup and logging in again.

**Fix (Audit H1):** In `setup_verify.php`, immediately after `unset($_SESSION['ff_mfa_setup'])`, add:
```php
unset($_SESSION['ff_mfa_must_setup']);
```

---

## MEDIUM: consumeBackupCode Not Atomic (T16)

**File:** `lib/Auth/MfaService.php`, `consumeBackupCode()` method (~line 128)  
**Current UPDATE query:**
```sql
UPDATE user_mfa_backup_codes SET used_at = NOW(), used_ip = ? WHERE id = ?
```
**Missing:** `AND used_at IS NULL` in the WHERE clause, and no `rowCount() == 1` check.

**Race condition:** Two parallel requests (e.g. double-click or concurrent API calls) can both pass `password_verify()` against the same unused row. Both reach the UPDATE. The first sets `used_at`. The second overwrites it with a new timestamp and different IP. Result: one backup code consumed twice.

**Fix (Audit M1):**
```sql
UPDATE user_mfa_backup_codes SET used_at = NOW(), used_ip = ? 
WHERE id = ? AND used_at IS NULL
```
Then check that `db_execute()` affected exactly 1 row; if 0, return `false` (race lost).

---

## MEDIUM: x-cloak Missing on MFA Setup Wrapper (T28)

**File:** `app/admin/account/mfa_setup.php:88`  
**Current:**
```html
<div x-data="mfaSetup()" x-init="init()">
```
**Should be:**
```html
<div x-data="mfaSetup()" x-init="init()" x-cloak>
```

The CSS rule `[x-cloak] { display: none !important; }` is already present in `public/assets/css/app.css` (lines 2971–2972). The attribute just hasn't been applied to the wrapper div. Without it, all steps flash visible for at least one frame before Alpine binds.

---

## MEDIUM: Only One Session Regeneration in MFA Flow (T14)

**Expected:** Two `session_regenerate_id(true)` calls — one after password verification (before setting `ff_mfa_pending`), one after TOTP verification (in `auth_login()`).  
**Actual:** Only one call — in `auth_login()` (includes/auth.php:185), which fires after TOTP.

**Gap:** `login.php` sets `$_SESSION['ff_mfa_pending']` without first regenerating the session ID. An attacker who knows the victim's password but not their TOTP code could plant a known session ID (session fixation) and wait for the victim to complete TOTP in that session.

**Fix:** Add `session_regenerate_id(true)` in `login.php` immediately before the `$_SESSION['ff_mfa_pending'] = [...]` assignment (around line 168).

---

## Rate Limiting — PASS (T19, T20)

Automated curl tests with proper session cookie persistence:

| Test | Threshold | Block attempt | HTTP status | Retry-After |
|------|-----------|--------------|-------------|-------------|
| Login IP (T19) | 20/15min | 21st attempt | 429 ✅ | 48600s present ✅ |
| Forgot-password IP (T20) | 5/60min | 6th attempt | 429 ✅ | — |

DB confirmed: `bucket_key=login:ip:127.0.0.1`, `attempt_count=21`, `blocked_until` set.

T21 (reset on success): `RateLimiter::reset()` confirmed to DELETE the bucket row in `login.php:163`. Curl test inconclusive (test user password unknown); code path verified.

---

## MFA Secret Encryption at Rest (T22) — PASS

```sql
SELECT id, email, LEFT(mfa_secret, 4), LENGTH(mfa_secret) FROM users WHERE mfa_enabled=1;
-- id=1 | nandaarvind98@yahoo.com | ENC: | 92
```

Prefix `ENC:` confirmed. Length 92 = `ENC:` (4) + base64(IV[16] || ciphertext) (88). Not a readable base32 TOTP secret. AES-256-CBC confirmed in `MfaService::encryptSecret()`.

---

## Alpine CDN Still in Use (T7 / T30)

Bundle 2 (S-PROD-1A-FIX-5 — Alpine self-host) not run. CDN references remain:
- `includes/footer.php:169`
- `app/portal/includes/footer.php:21`

`public/assets/vendor/alpinejs/cdn.min.js` does not exist.

This is a **separate issue from the forced-setup Alpine gap above.** The forced-setup gap needs fixing regardless of whether Bundle 2 runs.

---

## Audit Log State (T33) — PARTIAL

Events confirmed in `audit_log` (last 2 hours at time of test):

| Event | Module | Confirmed |
|-------|--------|-----------|
| MFA enabled — 10 backup codes issued | auth | ✅ |
| MFA verified (totp) | auth | ✅ |
| Failed login attempt | auth | ✅ |
| Login IP rate limit hit | auth | ✅ |
| Logout | auth | ✅ |
| Password reset | auth | ⬜ pending manual test |
| MFA verified via backup code | auth | ⬜ pending manual test |
| MFA disabled by admin | auth | ⬜ pending manual test |
| Self-disable rejected | auth | ⬜ pending manual test |
| CLI override | auth | ⬜ pending manual test |

Note: audit_log entries show character encoding artefact in `notes` column (`�` instead of `—`). Functional, cosmetic issue.

---

## Manual Checks Still Required

The following tests require a browser and a real authenticated session. They could not be automated. Run these in order after T34 and T7 are fixed:

| Test | What to verify | Pre-condition |
|------|---------------|---------------|
| T9 | Forgot password → email → reset link → new password form renders → login works | T34 must be fixed first |
| T10 | Same reset URL fails second time (single-use) | After T9 |
| T12 | Fake email → same generic message, nothing in mail.log | — |
| T13 | Login as manager → forced MFA setup → 3-step wizard (step 1 only visible) → scan QR → backup codes → dashboard | T7 must be fixed first |
| T15 | Use one backup code → log out → use same code again → rejected | After T13 |
| T18 | Enter wrong TOTP 5× → forced re-login | After T13 |
| T24 | Disable MFA button grayed for super_admin in UI + direct POST → 403 | After T13 |
| T25 | Admin disables MFA for another user → DB confirms mfa_enabled=0 | After T13 |
| T29 | Alpine works on dashboard, customers, mfa_setup voluntary path | — |
| T31 | Non-MFA user (dispatcher) logs in → dashboard directly, no MFA prompt | — |
| T32 | Portal login → portal dashboard, no MFA prompt, no MFA section in account | — |

---

## Full Test Results Table

| Test | Status | Severity | Short notes |
|------|--------|----------|-------------|
| T0 | FAIL | HIGH | Only 2 of 7 expected commits present |
| T1 | PASS | — | 23 files, 0 PHP lint errors |
| T2 | PASS | — | All MFA/rate-limit schema; Option A password reset |
| T3 | PASS | — | All 16 security.* settings with correct defaults |
| T4 | FAIL | LOW | FF_ASSET_VERSION=1.0.18, not bumped post-Bundle 1 |
| T5 | PASS | — | mfa_required correct for all 18 active users |
| T6 | PASS | — | Cache-Control via PHP session.cache_limiter (implicit) |
| T7 | FAIL | HIGH | No Alpine in forced-setup standalone head; Bundle 2 not run |
| T8 | PASS | — | 200/302/404 routing all correct |
| T9 | MANUAL | CRITICAL | Expected FAIL — T34 root cause blocks this |
| T10 | MANUAL | HIGH | Depends on T9 |
| T11 | N/A | — | Option A = no single-token overwrite test |
| T12 | MANUAL | MEDIUM | Anti-enumeration spot check |
| T13 | MANUAL | CRITICAL | Forced-setup Alpine broken (T7); voluntary should pass |
| T14 | FAIL | MEDIUM | Only 1 session regen; pre-TOTP window not regenerated |
| T15 | MANUAL | HIGH | Depends on T13 |
| T16 | FAIL | MEDIUM | consumeBackupCode UPDATE missing AND used_at IS NULL |
| T17 | FAIL | HIGH | ff_mfa_must_setup not unset after setup_verify success |
| T18 | MANUAL | HIGH | Code confirmed; manual trigger needed |
| T19 | PASS | — | 429 on attempt 21; Retry-After header present |
| T20 | PASS | — | 429 on attempt 6 for forgot_password |
| T21 | PASS (code) | — | reset() DELETE confirmed; curl inconclusive (pw unknown) |
| T22 | PASS | — | ENC: prefix, length=92, not readable base32 |
| T23 | PASS (code) | — | decryptSecret null fail-safe forces backup code path |
| T24 | MANUAL | MEDIUM | API block confirmed in code; UI greyout needs manual |
| T25 | MANUAL | MEDIUM | Depends on T13 |
| T26 | PASS (code) | — | Self-disable rejected: target_id == actor_id check |
| T27 | PASS (code) | — | PHP_SAPI guard, 'yes' confirm, audit_log write confirmed |
| T28 | FAIL | MEDIUM | x-cloak CSS rule exists but NOT on wrapper div |
| T29 | MANUAL | MEDIUM | Alpine CDN baseline regression |
| T30 | N/A | — | Bundle 2 not run; CDN still correct for now |
| T31 | MANUAL | MEDIUM | Non-MFA login regression |
| T32 | MANUAL | CRITICAL | Portal isolation |
| T33 | PARTIAL | MEDIUM | 5 event types logged; 5 pending manual flows |
| T34 | FAIL | **BLOCKER** | reset_password.php:60 AND u.is_active=1 — column missing |

**PASS: 13 | FAIL: 7 | MANUAL: 13 | N/A: 2**  
**Critical failures: T9 (expected), T13 (expected), T32 (untested), T34 (BLOCKER)**

---

## Recommended Fix Sequence

### Immediate (before any user testing)

1. **T34 — one line** `app/auth/reset_password.php:60`  
   `AND u.is_active = 1` → `AND u.status = 'active'`

2. **T7 — two lines** `app/admin/account/mfa_setup.php` (~line 70, inside the `<?php if ($forcedSetup): ?>` head block, after the app.js script tag)  
   Add: `<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>`

3. **T17 — one line** `api/v1/account/mfa/setup_verify.php` (after `unset($_SESSION['ff_mfa_setup'])`)  
   Add: `unset($_SESSION['ff_mfa_must_setup']);`

### Bundle as S-PROD-1A-FIX-4 (revised Bundle 1)

4. **T28** — add `x-cloak` attribute to `mfa_setup.php:88` wrapper div
5. **T16** — add `AND used_at IS NULL` to consumeBackupCode UPDATE + rowCount check
6. **T14** — add `session_regenerate_id(true)` in login.php before ff_mfa_pending assignment
7. **T4** — bump FF_ASSET_VERSION to 1.0.19

### Then run all MANUAL checks (T9, T10, T12, T13, T15, T18, T24, T25, T29, T31, T32)

### Deferred: S-PROD-1A-FIX-5 (Bundle 2)
- Download alpinejs@3 to `public/assets/vendor/alpinejs/cdn.min.js`
- Update footer.php, mfa_setup.php, and portal/footer.php to reference local file
- Bump FF_ASSET_VERSION to 1.0.20
- Smoke-test Alpine on all major pages

---

## Verdict

**`BLOCKER on T34 and T7 — DO NOT proceed to S-PROD-1B`**

T34 is a one-line fix. T7 is a two-line fix. Both can be resolved in under 5 minutes. Once fixed, run T9 and T13 manually to confirm the two most critical user-facing flows work end-to-end, then proceed with the remaining manual checks before declaring S-PROD-1A complete.
