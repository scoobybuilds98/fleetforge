# Domain 05 — Customers, Vendors, Users, Portal & Account

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/05-entities.md`.

Modules: `customers`, `vendors`, `users`, `portal_users`, `portal`, `account`,
`profile`. CRUD-heavy and identity-heavy — soft-delete and permission bugs cluster
here.

## Scope
```
for g in customers vendors users portal_users portal account; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/customers app/admin/vendors app/admin/users app/admin/portal_users app/admin/account app/admin/profile
```
Schema: `customers`, `vendors`, `users`, `user_roles`/`roles`/permissions,
`portal_users`, plus `outstanding_balance` and any denormalized counters.

## End-to-end flows
1. **User invite / re-invite / deactivate / reactivate** — the **soft-deleted user
   re-invite** path must REVIVE, not hit a 1062 duplicate-key crash (fixed in
   `f29d991` FLEETFORGE-F; confirm it holds and that vendors/customers/portal_users
   don't have the same un-fixed pattern — Class 9).
2. **Record pickers everywhere** — the searchParam contract: Vendors/Users use `q`,
   Customers/Leases use `search`; the `endpoint` MUST be root-relative `/api/v1/…`
   (NOT `base_url()`, which double-prepends → 404 → silent EMPTY selector). 17
   instances were fixed (`project_picker_endpoint_root_relative`); confirm none
   regressed and `FF_Api.url()` is still idempotent. Class 2 + Class 4.
3. **Customer create/edit** — denormalized counters; does deleting/merging a customer
   orphan invoices/leases?
4. **Permissions / roles** — assigning/removing a permission takes effect on the next
   request AND invalidates active sessions' stale cache (verified once at 304/304 in
   `tests/_smoke_permissions_rigorous.php` — re-run it; confirm no endpoint is missing
   `require_permission`, Class 8).
5. **Portal users** — the customer-facing portal: auth scope (a portal user must NOT
   reach admin endpoints), data isolation (can customer A see customer B's data?),
   token/session handling.
6. **Account / profile** — self-service edits, password change, the MFA toggle (see
   Domain 09; the MFA-not-propagated bug lives at the settings↔users boundary).

## Hotspots
- **Class 9:** soft-delete in every list query + uniqueness check (email/name reuse
  after delete). The re-invite revive is the model fix.
- **Class 2 + 4:** picker endpoint + searchParam contract — silent empty selectors.
- **Class 8:** missing `require_permission`; portal-user privilege escalation; UI
  hides a control the API still honors.
- **Data isolation:** portal/customer-scoped endpoints must filter by the caller's
  customer_id server-side, never trust a client-supplied id.

## Start here
Run `tests/_smoke_permissions_rigorous.php`, audit every picker `endpoint`/searchParam
in this domain's pages, then attempt cross-tenant access on portal endpoints
(read-only / local) and report any leak as CRITICAL.
