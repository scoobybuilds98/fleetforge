## [MEDIUM] — Deprecated Settings Users route bypasses MFA-policy sync on role changes
File: app/admin/settings/users.php:150
Code:
```php
if ($action === 'change_role' && $isSuperAdmin) {
    $targetId = clean_int($_POST['target_user_id'] ?? null);
    $newRole  = clean_int($_POST['new_role_id'] ?? null);

    if ($targetId && $newRole && $targetId !== current_user_id()) {
        db_update('users', ['role_id' => $newRole], 'id = ? AND deleted_at IS NULL', [$targetId]);
```
Why it's a problem: This still-routable legacy page mutates `users.role_id` directly and skips newer role-change side effects (notably `mfa_required` policy synchronization). A super_admin using this standalone settings route can move a user into an MFA-required role without enforcing MFA requirement flags, creating auth-policy drift from the canonical users API path.
Proposed fix: Hard-disable inline mutating actions in this deprecated page (read-only banner + redirect), or route all writes through `api/v1/users/*` only.

## [LOW] — Legacy invite flow logs a non-existent invite URL
File: app/admin/settings/users.php:89
Code:
```php
// Log invite URL (dev mode)
$resetUrl = base_url('auth/set_password?token=' . $plainToken);
```
Why it's a problem: The invite completion flow is implemented at `auth/accept_invite`, not `auth/set_password`. If this deprecated route is used, operators get a wrong link in `logs/mail.log`, causing failed onboarding and confusion during incident recovery/testing.
Proposed fix: Update legacy URL generation to `auth/accept_invite` or remove invite writes from this deprecated surface entirely.

## [LOW] — Brand asset storage keys are second-granularity and collision-prone
File: api/v1/settings/brand.php:212
Code:
```php
$ext        = $logoMimeMap[$mime];
$storageKey = 'branding/logo_' . time() . '.' . $ext;
try {
    StorageClient::upload($file['tmp_name'], $storageKey);
```
Why it's a problem: Filenames rely only on `time()` (second precision). Concurrent uploads within the same second can collide and overwrite objects unexpectedly (same pattern exists for favicon key generation in the same file), creating non-deterministic brand asset state.
Proposed fix: Include high-entropy uniqueness in object keys (e.g., random suffix/UUID) and keep deterministic prefixes only for grouping.

Summary: 0 CRITICAL / 1 MEDIUM / 2 LOW.
