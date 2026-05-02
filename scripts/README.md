# FleetForge Scripts

Utility and automation scripts. PHP scripts require the app bootstrap environment.

## sc8_alpine_sweep.js

**Automated Alpine load sweep across 8 representative pages (S-PROD-1A-FIX-5).**

Verifies that Alpine.js loads from the local vendor path (not CDN) on all 8 pages,
and that no Alpine-related JavaScript errors are present.

**Run after:**
- Any Alpine version upgrade (`public/assets/vendor/alpinejs/cdn.min.js` replaced)
- Any change to `FF_ASSET_VERSION` or `asset_url()` logic
- Any new standalone auth/admin page that might load Alpine independently

**Usage:**
```bash
cd /path/to/fleetforge
node scripts/sc8_alpine_sweep.js
```

**Requirements:**
- Node.js ≥ 18 with Playwright installed (`npm install` in repo root)
- Chromium (`npx playwright install chromium`)
- `.env` with test credentials (see below)
- `fleetforge.test` dev server running

**Test credentials in `.env`:**
```
FF_TEST_ADMIN_EMAIL=sc8_test@fleetforge.test
FF_TEST_ADMIN_PASSWORD=SC8test2026!
FF_TEST_MFA_EMAIL=sc8_mfa@fleetforge.test
FF_TEST_MFA_PASSWORD=SC8test2026!
FF_TEST_PORTAL_EMAIL=john@apexfreight.com
FF_TEST_PORTAL_PASSWORD=SC8test2026!
```
These users are seeded test accounts. See `db_migrations/` for setup context.

**Pages tested:**

| Page | Auth | Notes |
|------|------|-------|
| dashboard | admin | Alpine reactive UI |
| customers_list | admin | Search filter |
| customer_show | admin | Tab switching |
| leases_create | admin | Multi-step form |
| equipment_list | admin | Action dropdowns |
| mfa_setup_volunt | admin | x-cloak + step wizard |
| mfa_challenge | admin (MFA) | Plain JS page — CDN=0 check only |
| portal_dashboard | portal | Separate auth context |

**Exit codes:** 0 = all pass, 1 = one or more failures.

---

## Other scripts

| Script | Purpose |
|--------|---------|
| `seed_dataset.php` | Seed full demo dataset (customers, leases, equipment, invoices) |
| `seed_demo.php` | Seed minimal demo data |
| `seed_reservations.php` | Seed reservation test data |
| `stress_test_final.php` | 98-case DB integrity stress test |
| `restore_db.php` | DB restore tool (see `docs/runbooks/restore_drill.md`) |
| `admin_override_mfa.php` | CLI emergency MFA disable for a user |
| `fix_counter_drift_2026_05_02.php` | One-time counter drift fix (S-FIX-2) |
