## [CRITICAL] — Admin sessions do not re-validate live account status/role
File: includes/auth.php:100
Code:
```php
function require_auth_api(): void
{
    if (!current_user()) {
        json_error('UNAUTHORIZED', 'Authentication required.', 401);
    }
    _ff_check_permission_freshness();
}
```
Why it's a problem: `require_auth()`/`require_auth_api()` trust the session snapshot and never verify that the `users` row is still `active`/not deleted or that `role_id` is unchanged. A user who is suspended, deactivated, or role-downgraded by an admin can continue using existing sessions until timeout, retaining prior authorization scope. This is a live authorization gap with direct blast radius across all authenticated admin routes.
Proposed fix: Add a per-request auth freshness check that loads `users.status`, `users.deleted_at`, and `users.role_id` for `current_user_id()`, forces logout/401 when inactive/deleted, and refreshes session role/permission payload when role changes.

## [MEDIUM] — Portal page guard does not enforce portal user status after login
File: app/portal/includes/auth.php:81
Code:
```php
$cust = db_row(
    "SELECT status FROM customers WHERE id = ? AND deleted_at IS NULL",
    [portal_customer_id()]
);
if (!$cust || !in_array($cust['status'], ['active', 'pending', 'credit_hold'], true)) {
    _portal_session_clear();
```
Why it's a problem: The portal guard re-checks only customer status; it does not re-check `portal_users.status` (`active`/`inactive`) for the logged-in portal identity. If a portal user is deactivated mid-session, that session remains usable until timeout/logout instead of being cut off immediately. This weakens deprovisioning controls.
Proposed fix: Join `portal_users` in the guard check and require `pu.status = 'active'` (and optional lock checks) on every authenticated portal request.

## [MEDIUM] — Portal API bootstraps bypass suspension checks used by portal pages
File: app/portal/api/notifications/_bootstrap.php:54
Code:
```php
$portalUserId = portal_user_id();
if (!$portalUserId) {
    portal_json_err('UNAUTHORIZED', 'No authenticated portal user.', 401);
}
```
Why it's a problem: The API bootstrap validates only a session key, not the live customer/account status checks enforced in `require_portal_auth()`. A suspended customer (or inactive portal user) with an existing session can still hit portal APIs from background polling/AJAX. This pattern appears in `app/portal/api/chat/_bootstrap.php` and `app/portal/api/messenger/_bootstrap.php` as well.
Proposed fix: Route all portal API auth through a shared guard that calls `require_portal_auth()` (or equivalent live status checks) before allowing API execution.

## [LOW] — Logout endpoint is state-changing but CSRF-exempt and GET-accessible
File: app/auth/logout.php:10
Code:
```php
// Accepts GET or POST. No CSRF check needed for logout
// (logging out is never harmful), but the action is only
// reachable by authenticated users via the sidebar link.
```
Why it's a problem: Cross-site requests can trigger unwanted logout because the endpoint accepts GET without CSRF verification. This is typically low impact (availability/UX) but still a CSRF primitive on a state-changing route.
Proposed fix: Require POST + CSRF token for logout, and keep GET as a 405/redirect-only safe path.

Summary: 1 CRITICAL / 2 MEDIUM / 1 LOW.
