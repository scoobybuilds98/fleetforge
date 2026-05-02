# FleetForge — Secret Key Rotation Runbook

**Purpose:** Step-by-step rotation procedures for every production secret managed by
FleetForge. Each section covers: pre-rotation checklist, rotation steps, verification,
and rollback. Run this runbook on the schedules noted below or immediately if a secret
is suspected to be compromised.

**General rule:** Never rotate more than one secret at a time. Rotate, verify, wait 24 hours
(or one full cron cycle), then rotate the next. Rotating multiple secrets simultaneously
makes rollback ambiguous.

---

## 1. FF_MFA_SECRET_KEY

**What it protects:** User TOTP secrets stored in `users.mfa_secret` (encrypted AES-256-CBC).
Every time a user enrolls MFA, `MfaService::encryptSecret()` encrypts their TOTP seed with
this key before writing to the database. Decryption happens on every MFA login attempt.

**Rotation schedule:** Every 12 months, or immediately if compromised.

> **FOLLOW-UP REQUIRED (Phase 5 cutover prep):** The script `scripts/rotate_mfa_secret_key.php`
> does **not yet exist**. This runbook documents the intended rotation procedure; the script
> must be written before the first rotation is due. Flag this as a Phase 5 pre-launch task.
> Until the script exists, key rotation requires a manual re-encryption pass (see Step 3 below).

**Failure-safe behavior:** If `FF_MFA_SECRET_KEY` does not match the key used to encrypt a
user's TOTP secret, `MfaService::decryptSecret()` returns `null`. The MFA verification will
fail and the user cannot log in via TOTP. This is intentional — a bad key fails safe rather
than letting an unauthenticated user through. The user must use a backup code to recover.

### Pre-rotation checklist

- [ ] Confirm the current key value is recorded in the secure credential store (not just in `.env`)
- [ ] Confirm the new key will be a fresh 32-byte random value: `openssl rand -hex 32`
- [ ] Identify all users with `mfa_enabled = 1` — these are the affected rows:
  ```sql
  SELECT COUNT(*) FROM users WHERE mfa_enabled = 1;
  ```
- [ ] Schedule the rotation during low-traffic hours (weeknight 2–4 AM)
- [ ] Confirm a database backup was taken in the last 2 hours:
  ```bash
  php /var/www/fleetforge/scripts/backup_db.php
  ```
- [ ] Notify any active admin users that MFA login will be briefly unavailable

### Rotation steps

**Step 1 — Generate a new key:**
```bash
openssl rand -hex 32
# Copy this value — it becomes FF_MFA_SECRET_KEY_NEW
```

**Step 2 — Add the new key to .env alongside the old key:**
```ini
FF_MFA_SECRET_KEY=<current_key>
FF_MFA_SECRET_KEY_OLD=<current_key>   # keep old key for fallback decrypt
FF_MFA_SECRET_KEY_NEW=<new_key>
```

**Step 3 — Re-encrypt all TOTP secrets (run as the www-data / ubuntu user):**

When `scripts/rotate_mfa_secret_key.php` is written (Phase 5), run:
```bash
php /var/www/fleetforge/scripts/rotate_mfa_secret_key.php --dry-run
php /var/www/fleetforge/scripts/rotate_mfa_secret_key.php --execute
```

Until that script exists, perform re-encryption manually:
```php
// Connect to DB, for each row in users where mfa_enabled = 1:
// 1. Decrypt mfa_secret with FF_MFA_SECRET_KEY_OLD
// 2. Re-encrypt with FF_MFA_SECRET_KEY_NEW
// 3. Update the row
// Use a transaction; roll back if any decrypt returns null
```

**Step 4 — Promote the new key:**

After all rows are re-encrypted, update `.env`:
```ini
FF_MFA_SECRET_KEY=<new_key>
FF_MFA_SECRET_KEY_OLD=<old_key>   # keep for 24h fallback window
# Remove FF_MFA_SECRET_KEY_NEW
```

**Step 5 — Restart PHP-FPM to reload .env:**
```bash
sudo systemctl restart php8.2-fpm
```

### Verification steps

- [ ] Log in as a test user with MFA enabled — confirm TOTP prompt appears and a valid code is accepted
- [ ] Check `logs/error.log` for any `MfaService` decrypt errors
- [ ] Run:
  ```sql
  SELECT id, email FROM users WHERE mfa_enabled = 1 AND mfa_secret IS NULL;
  -- Should return 0 rows
  ```
- [ ] Confirm `MfaService::decryptSecret()` returns non-null for at least one user:
  ```bash
  php /var/www/fleetforge/scripts/rotate_mfa_secret_key.php --verify
  ```

### Rollback steps

If MFA login fails after rotation:

1. Restore `.env` to use the old key:
   ```ini
   FF_MFA_SECRET_KEY=<old_key>
   ```
2. Restart PHP-FPM: `sudo systemctl restart php8.2-fpm`
3. Verify MFA login works with the old key
4. Restore the database from the pre-rotation backup if re-encryption was partial
5. Investigate why re-encryption failed before attempting again

**Fallback decrypt path (recommended long-term):** `MfaService::decryptSecret()` should
attempt decryption with `FF_MFA_SECRET_KEY` first, then fall back to `FF_MFA_SECRET_KEY_OLD`
if the first attempt returns null. This allows a zero-downtime rotation window where both
keys are valid simultaneously. Add this fallback logic in Phase 5.

---

## 2. AWS Access Keys (S3, SES, SNS)

**What they protect:** FleetForge uses a single IAM user with scoped policies for:
- S3: file uploads and database backups (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
- SES: transactional email sending (same IAM credentials or SES SMTP — see Section 4)
- SNS: SMS notifications (same IAM credentials)

**Rotation schedule:** Every 90 days minimum, or immediately if exposed (e.g., accidentally
committed to git, logged in plaintext, or IAM credential report shows unusual activity).

### Pre-rotation checklist

- [ ] Open AWS IAM → Users → fleetforge-prod-user → Security credentials
- [ ] Confirm the current key's `Last used` date — if it's never been used, the key may already be stale or wrong
- [ ] Confirm a test backup or S3 upload succeeded recently (verifies current key is active)
- [ ] Confirm SES and SNS were sending/receiving in the last 24 hours
- [ ] Have `.env` open and ready to edit

### Rotation steps

**Step 1 — Create the new IAM access key:**

In AWS IAM → Users → fleetforge-prod-user → Security credentials → Create access key.
Note: IAM allows a maximum of two active access keys per user. If two already exist,
one must be deleted before creating a new one — delete the oldest inactive key.

Copy the new `Access key ID` and `Secret access key` immediately (the secret is shown once).

**Step 2 — Add new credentials to .env under temporary NEW variable names:**
```ini
AWS_ACCESS_KEY_ID_NEW=<new_access_key_id>
AWS_SECRET_ACCESS_KEY_NEW=<new_secret_access_key>
```

**Step 3 — Temporarily point the application at the new keys** by swapping variable names
in `.env`:
```ini
AWS_ACCESS_KEY_ID=<new_access_key_id>
AWS_SECRET_ACCESS_KEY=<new_secret_access_key>
```

**Step 4 — Restart PHP-FPM:**
```bash
sudo systemctl restart php8.2-fpm
```

**Step 5 — Run a live verification** (see Verification section below).

**Step 6 — Disable (do not yet delete) the old key in IAM:**

IAM → Security credentials → find the old key → Actions → Deactivate.
Disabling allows instant rollback without needing the secret again.

**Step 7 — Wait 24 hours.** Monitor `logs/error.log` and `logs/cron.log` for any S3 or
SES authentication errors. If none appear after 24 hours, the rotation is complete.

**Step 8 — Delete the old key in IAM.**

Remove the temporary `_NEW` variables from `.env`.

### Verification steps

```bash
# Verify S3 write access (upload a test object)
aws s3 cp /tmp/key_rotation_test.txt s3://$AWS_S3_BUCKET/test/key_rotation_test.txt \
  --profile fleetforge-prod

# Verify S3 read access (list backups)
aws s3 ls s3://$AWS_S3_BUCKET/backups/db/ | head -5

# Trigger a manual backup to confirm the PHP application uses the new key
php /var/www/fleetforge/scripts/backup_db.php

# Verify SES can send (trigger a test email or check SES send statistics in the console)
```

Check `logs/cron.log` after the next scheduled backup run. A successful S3 PUT confirms
the new key is working end-to-end from the PHP application.

### Rollback steps

1. Re-activate the old key in IAM: Security credentials → find disabled old key → Activate
2. Update `.env` to restore the old key values
3. Restart PHP-FPM: `sudo systemctl restart php8.2-fpm`
4. Verify S3 and SES are working
5. Investigate why the new key failed (IAM policy too restrictive, wrong region, etc.)

---

## 3. Database Password

**What it protects:** The MySQL password for the `fleetforge` application user (`DB_USERNAME`
in `.env`). Used by every PHP request that opens a PDO connection.

**Rotation schedule:** Every 90 days, or immediately if the password is exposed.

### Pre-rotation checklist

- [ ] Confirm current DB credentials work: `mysql -u $DB_USERNAME -p$DB_PASSWORD -e "SELECT 1;"`
- [ ] Take a database backup before rotating: `php /var/www/fleetforge/scripts/backup_db.php`
- [ ] Schedule during low-traffic hours — the application will be briefly unavailable during
  the PHP-FPM restart
- [ ] Confirm you have root (or admin) MySQL access to run `ALTER USER`

### Rotation steps

**Step 1 — Generate a strong new password:**
```bash
openssl rand -base64 32
```

**Step 2 — Update the MySQL user password:**
```sql
-- Connect as MySQL root
ALTER USER 'fleetforge_user'@'localhost' IDENTIFIED BY '<new_password>';
FLUSH PRIVILEGES;
```

Replace `fleetforge_user` and `localhost` with the actual values from `DB_USERNAME` and
`DB_HOST` in `.env`.

**Step 3 — Update `.env`:**
```ini
DB_PASSWORD=<new_password>
```

**Step 4 — Restart PHP-FPM to reload the new password:**
```bash
sudo systemctl restart php8.2-fpm
```

### Verification steps

```bash
# Confirm PHP-FPM can connect with the new password
curl -I https://<your_domain>/fleetforge/auth/login
# Should return HTTP 200

# Check error log for any PDO connection failures
tail -50 /var/www/fleetforge/logs/error.log

# Direct MySQL test with the new password
mysql -u fleetforge_user -p<new_password> fleetforge -e "SELECT COUNT(*) FROM users;"
```

### Rollback steps

1. Restore the old password in MySQL:
   ```sql
   ALTER USER 'fleetforge_user'@'localhost' IDENTIFIED BY '<old_password>';
   FLUSH PRIVILEGES;
   ```
2. Restore `.env` `DB_PASSWORD` to the old value
3. Restart PHP-FPM
4. Verify connectivity

---

## 4. SES SMTP Credentials

**What they protect:** If FleetForge uses SMTP to send email via SES (rather than the SES
API), the SMTP username and password are separate IAM-derived credentials stored in `.env`
as `MAIL_USERNAME` and `MAIL_PASSWORD`.

**Note:** SES SMTP credentials are IAM-derived but are **not** the same as the IAM access
keys in Section 2. They are generated in the SES console and cannot be retrieved after
creation.

**Rotation schedule:** Every 180 days, or immediately if exposed.

### Pre-rotation checklist

- [ ] Confirm outbound email is working: check `logs/mail.log` for recent successful sends
- [ ] Confirm you have AWS Console access to the SES SMTP credentials page
- [ ] Identify all `.env` variables that hold SES SMTP credentials:
  - `MAIL_USERNAME` (starts with `AKIA...`)
  - `MAIL_PASSWORD` (long base64 string)
  - `MAIL_HOST` (e.g., `email-smtp.us-east-1.amazonaws.com`)
  - `MAIL_PORT` (typically `587`)

### Rotation steps

**Step 1 — Create new SMTP credentials in the SES console:**

AWS Console → SES → Account dashboard → SMTP settings → Create SMTP credentials.
This creates a new IAM user specifically for SMTP. Copy the SMTP username and password
immediately (shown once only).

**Step 2 — Add new credentials to .env under temporary names:**
```ini
MAIL_USERNAME_NEW=<new_smtp_username>
MAIL_PASSWORD_NEW=<new_smtp_password>
```

**Step 3 — Send a test email using the new credentials** before cutting over:
```bash
php /var/www/fleetforge/scripts/test_email.php \
  --smtp-user=<new_smtp_username> \
  --smtp-pass=<new_smtp_password>
```

If `test_email.php` does not exist, use `swaks` or `telnet` to send a test SMTP message
directly.

**Step 4 — Swap the credentials in .env:**
```ini
MAIL_USERNAME=<new_smtp_username>
MAIL_PASSWORD=<new_smtp_password>
```

**Step 5 — Restart PHP-FPM:**
```bash
sudo systemctl restart php8.2-fpm
```

**Step 6 — Monitor logs/mail.log for 24 hours.** If no errors, delete the old SMTP IAM user
in the IAM console (the old SMTP credentials IAM user, not the main fleetforge-prod-user).

### Verification steps

- [ ] Trigger a test invoice email send (or use the forgot-password flow to send an email)
- [ ] Confirm delivery in `logs/mail.log`
- [ ] Check the SES send statistics in the AWS Console — successful sends should increment

### Rollback steps

1. Restore old SMTP credentials to `.env`
2. Restart PHP-FPM
3. Verify email delivery
4. Investigate why new credentials failed (wrong region endpoint, IAM policy issue, etc.)

---

## 5. Cloudflare API Token

**What it protects:** If Cloudflare DNS updates are automated (e.g., for dynamic IP changes,
staging subdomain management, or CI/CD pipeline DNS flips), a Cloudflare API token with
scoped `Zone:DNS:Edit` permissions is stored in `.env` as `CLOUDFLARE_API_TOKEN`.

**Rotation schedule:** Every 90 days, or immediately if the token appears in logs, error
messages, or any version-controlled file.

**Note:** If DNS is managed manually and no automation uses a Cloudflare API token, this
section does not apply. Verify by running: `grep -r CLOUDFLARE /var/www/fleetforge/.env`

### Pre-rotation checklist

- [ ] Confirm which automation consumes `CLOUDFLARE_API_TOKEN` (deployment scripts, CI/CD, etc.)
- [ ] Log in to Cloudflare dashboard → My Profile → API Tokens
- [ ] Identify the existing token (name, zone permissions, last used date)
- [ ] Confirm the token is scoped to the minimum required permissions (Zone:DNS:Edit only)

### Rotation steps

**Step 1 — Create a new token in Cloudflare:**

Cloudflare → My Profile → API Tokens → Create Token.
Use the "Edit zone DNS" template. Scope it to the specific zone (domain) only.
Copy the token value immediately (shown once only).

**Step 2 — Add the new token to .env under a temporary name:**
```ini
CLOUDFLARE_API_TOKEN_NEW=<new_token>
```

**Step 3 — Test the new token:**
```bash
curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer <new_token>" \
     -H "Content-Type: application/json"
# Should return: "result": {"status": "active"}
```

**Step 4 — Update .env to promote the new token:**
```ini
CLOUDFLARE_API_TOKEN=<new_token>
```

**Step 5 — Restart PHP-FPM** (only required if PHP reads this variable directly):
```bash
sudo systemctl restart php8.2-fpm
```

**Step 6 — Revoke the old token in Cloudflare:**

Cloudflare → API Tokens → find old token → Revoke (or Delete). Revoking is immediate and
irreversible. Wait until verification in Step 3 passes before revoking.

### Verification steps

```bash
# Verify the new token is active
curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer $(grep CLOUDFLARE_API_TOKEN /var/www/fleetforge/.env | cut -d= -f2)"

# Run any automation that depends on the token (e.g., a deployment script that flips DNS)
# and confirm it completes without a 403 Forbidden from the Cloudflare API
```

### Rollback steps

If the new token does not work and the old token has not yet been revoked:

1. Restore the old token in `.env`
2. Restart PHP-FPM if applicable
3. Verify automation works with the old token
4. Investigate the issue (wrong zone scope, permissions too narrow, etc.) before retrying

If the old token was already revoked, create a new token from scratch (Step 1) and proceed
through the rotation steps again.

---

## Rotation Calendar

Use this table to track when each secret was last rotated and when the next rotation is due.

| Secret | Frequency | Last Rotated | Next Due |
|---|---|---|---|
| FF_MFA_SECRET_KEY | 12 months | — (new) | 2027-05 |
| AWS access keys | 90 days | — (new) | 2026-07 |
| Database password | 90 days | — (new) | 2026-07 |
| SES SMTP credentials | 180 days | — (new) | 2026-11 |
| Cloudflare API token | 90 days | — (new) | 2026-07 |

Update this table in the runbook after each rotation.

---

*Runbook version: 1.0.0 — Last updated: 2026-05-02*
