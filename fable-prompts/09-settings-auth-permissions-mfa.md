# Domain 09 — Settings, Auth, Permissions, MFA, Mileage Logs & Yards

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/09-settings-auth.md`.

Modules: `settings`, login/auth, permissions/roles, MFA, `mileage_logs`, `yards`.
Settings bugs are insidious — a setting that doesn't propagate to the flag the
runtime actually reads (Class 8). Auth bugs are CRITICAL by default.

## Scope
```
for g in settings mileage_logs yards; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/settings
grep -rn "require_permission\|require_auth\|mfa_required\|csrf" includes/auth.php includes/ | head -40
```
Schema: `settings` (cols are `value_type` / `group_name`; **no `db_value` helper** —
use `db_row(...)['value']`), `users.mfa_required`, roles/permissions tables,
`rate_limits`, `mileage_logs`, `yards`.

## Known landmines (from `project_mfa_settings_audit`, 2026-06-02)
- **MFA "off" still prompts:** the setting was never propagated to
  `users.mfa_required` — login gates on the COLUMN, not the setting. There is no
  global MFA switch; `required_roles=[]` is the off lever. Confirm the fix (in
  `app/admin/settings/index.php`, was UNCOMMITTED) actually shipped.
- **Security-card save zeroed all 13 `rate_limit` rows** — a save path that wiped
  unrelated config. Confirm fixed and look for the same "save one card, clobber
  others" pattern elsewhere in settings.
- **CSRF returning HTTP 200 on failure** (S002) — confirm CSRF rejects with the
  right status and that every state-changing endpoint actually checks it (Class 8).

## End-to-end flows
1. **Login / logout / remember-me** — soft-deleted/inactive user can't auth; stale
   remember-me invalidated on logout (S002 fixed a stale remember-me); session fixation.
2. **MFA** enroll / verify / disable — and the settings→`users.mfa_required`
   propagation (the marquee bug).
3. **Settings save** — for EACH settings card, confirm saving it only writes its own
   keys and doesn't zero/overwrite others (the rate_limit incident). Confirm the
   setting reaches the runtime flag it's supposed to control.
4. **Permissions / roles** — see Domain 05; re-run `tests/_smoke_permissions_rigorous.php`.
5. **Mileage logs** — manual entry + Samsara-fed (Domain 08); feeds mileage billing;
   tz on the log date (Class 6); negative/rollback odometer handling.
6. **Yards** — CRUD; referential integrity (can you delete a yard with units in it?).

## Hotspots
- **Class 8:** settings-not-propagated; missing CSRF/permission checks; auth bypass.
- **"save clobbers siblings":** any settings save that writes more than its card.
- **Class 6:** mileage-log dates.
- **Class 3:** settings `value_type` handling; role/permission enums.

## Start here
Confirm the three known auth/settings landmines shipped fixes, then audit every
settings card's save for the clobber pattern, then verify CSRF + permission coverage
across all state-changing endpoints in this domain.
