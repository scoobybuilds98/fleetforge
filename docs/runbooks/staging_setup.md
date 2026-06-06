# FleetForge — Staging Environment Setup Runbook

**Purpose:** Provision, configure, and maintain a staging environment that mirrors
production. This runbook covers why staging exists, how to provision it, data isolation
rules, the deployment workflow, cost, and the timing decision for initial launch.

---

## 1. Why Staging Exists

The staging environment exists to catch two categories of problem before they reach
production users:

**Code defects.** Every deploy is a risk. Running the deploy on staging first means a broken
migration, a PHP fatal, or a misconfigured Nginx rewrite hits a non-customer server. Staging
absorbs the blast radius of bad deploys. Production users never see the error.

**AWS cutover dress rehearsal.** The planned cutover from local hosting to AWS Lightsail +
RDS + SES + S3 is a multi-step procedure with a defined rollback window. Rehearsing this on
staging before touching production means the team has muscle memory for each step, the
timing is measured, and any environmental differences (IAM permissions, SES sandbox limits,
DNS propagation delays) surface safely before they affect paying customers.

Without staging, the first time any of these procedures runs is in production under pressure.
That is an avoidable risk.

---

## 2. Provisioning

### Lightsail Instance

Create a second Lightsail instance with the same specifications as production:

| Parameter | Value |
|---|---|
| Instance size | 8 GB RAM / 2 vCPU / 160 GB SSD |
| OS | Ubuntu 22.04 LTS |
| Region | Same region as production |
| Static IP | Assign a dedicated Lightsail static IP |

Follow the same provisioning checklist used for production:
- Install PHP 8.2-FPM, Nginx, MySQL 8.0, Composer
- Clone the FleetForge repository to `/var/www/fleetforge`
- Copy `.env.example` → `.env` and fill in staging-specific values (see Section 3)
- Run `composer install --no-dev`
- Configure Nginx virtual host with the staging subdomain
- Run database migrations: `php bin/migrate.php --apply`
  (fresh DB: apply `000_baseline.sql` first, which creates all tables + seeds schema_migrations)
- Run seed data if desired: `php scripts/seed.php`
- Configure logrotate (see `docs/runbooks/logrotate_setup.md`)
- Install SSL certificate via Certbot for the staging subdomain

### DNS and Subdomain

1. Create a Lightsail static IP and assign it to the staging instance.
2. In Cloudflare (or your DNS provider), create an A record:
   ```
   Type: A
   Name: staging
   Value: <staging_static_ip>
   TTL: 300 (or Cloudflare proxy if desired)
   ```
   This resolves `staging.{prod_domain}` to the staging instance.
3. Verify DNS propagation: `dig staging.{prod_domain} +short`
4. Issue an SSL certificate: `sudo certbot --nginx -d staging.{prod_domain}`

### SSH Access

Add the same SSH key pair used for production, or create a staging-specific key pair.
Staging does not require restricted access, but treat it like production: no passwords,
key-based auth only, UFW rules allowing only ports 22, 80, and 443.

---

## 3. Data Isolation

**Every resource used by staging must be separate from production.** Sharing any resource
between staging and production creates a path for data corruption, accidental PII exposure,
or cascading failures.

### MySQL Database

- Staging uses a separate database: `staging_fleetforge`
- Never point `DB_DATABASE` on staging at `fleetforge` (production)
- In `.env` on staging:
  ```ini
  DB_DATABASE=staging_fleetforge
  DB_USERNAME=fleetforge_staging_user
  DB_PASSWORD=<staging_specific_password>
  ```
- Create the staging DB user with privileges scoped to `staging_fleetforge` only:
  ```sql
  CREATE DATABASE staging_fleetforge;
  CREATE USER 'fleetforge_staging_user'@'localhost' IDENTIFIED BY '<password>';
  GRANT ALL PRIVILEGES ON staging_fleetforge.* TO 'fleetforge_staging_user'@'localhost';
  FLUSH PRIVILEGES;
  ```

### S3 Bucket

- Staging uses a separate bucket: `fleetforge-staging-uploads`
- Never share the production bucket with staging — uploaded files, invoice PDFs, and backup
  objects from staging must not co-mingle with production data
- Create a separate IAM user or policy for staging with access scoped to `fleetforge-staging-*`
  buckets only
- In `.env` on staging:
  ```ini
  AWS_S3_BUCKET=fleetforge-staging-uploads
  ```

### SES Email

Two options:
- **Option A (preferred during early staging):** Use SES sandbox mode. Sandbox limits sending
  to verified email addresses only — no real customers will receive staging emails by accident.
- **Option B:** Create a separate SES sending identity for the staging domain (e.g.,
  `no-reply@staging.{prod_domain}`) with its own SMTP credentials

In either case, set in `.env` on staging:
```ini
MAIL_FROM_ADDRESS=no-reply@staging.{prod_domain}
MAIL_FROM_NAME="FleetForge (Staging)"
```

The distinct `From` name makes it immediately obvious if a staging email escapes to a real inbox.

### Sentry

- Use a separate Sentry project (`fleetforge-staging`) so staging noise does not pollute the
  production error feed, or set the environment tag on the shared project:
  ```ini
  SENTRY_ENVIRONMENT=staging
  ```
  Sentry's environment filter lets you view staging and production errors independently.
- Staging errors should not trigger PagerDuty or on-call alerts — only production alerts
  should page anyone.

### FF_MFA_SECRET_KEY and All Other Secrets

**Never share secret keys across environments.** This is non-negotiable.

- `FF_MFA_SECRET_KEY` on staging must be a different randomly generated value from production
- `APP_KEY` on staging must be different
- All AWS credentials on staging must belong to staging-scoped IAM policies
- All database credentials on staging are staging-specific

Rationale: if a staging secret is compromised (e.g., leaked in a log or accidentally
committed to a branch), it must not give an attacker any access to production resources.
Environment separation is the last line of defense when secrets leak.

Generate staging-specific values:
```bash
# FF_MFA_SECRET_KEY
openssl rand -hex 32

# APP_KEY (Laravel-style)
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

---

## 4. Deployment Workflow

### Normal Deployment Flow

The canonical deployment order is **staging first, then production**:

```
1. Push branch to GitHub / origin
2. SSH to staging: git pull, composer install --no-dev, php scripts/migrate.php
3. Run smoke tests on staging (see below)
4. If smoke tests pass: SSH to production and repeat the same steps
5. If smoke tests fail: roll back staging, fix the branch, redeploy to staging
```

Production is never touched until staging is green.

### Staging Smoke Test Checklist

After every staging deploy, verify the following before deploying to production:

- [ ] Login page loads without PHP errors: `curl -I https://staging.{prod_domain}/fleetforge/auth/login`
- [ ] Can log in with a test account
- [ ] Dashboard loads and displays data
- [ ] Invoice list loads; create a draft invoice
- [ ] MFA setup flow works (if the deploy touches MFA)
- [ ] File upload works (creates an object in `fleetforge-staging-uploads`)
- [ ] Check `logs/error.log` on staging for any new PHP errors
- [ ] Check Sentry staging project for any new errors

### Refreshing Staging Data from Production

To keep staging representative of production workloads, refresh staging with a sanitized
production backup approximately once per quarter:

1. Take a production backup: `php /var/www/fleetforge/scripts/backup_db.php`
2. Download the backup: `aws s3 cp s3://$AWS_S3_BUCKET/backups/db/... /tmp/prod_backup.sql.gz`
3. Decompress: `gunzip /tmp/prod_backup.sql.gz`
4. **Anonymize PII before loading into staging** — overwrite real customer names, emails,
   phone numbers, and invoice notes with synthetic data:
   ```sql
   UPDATE customers SET
     company_name = CONCAT('Test Company ', id),
     contact_name = CONCAT('Test User ', id),
     email        = CONCAT('test', id, '@example.invalid'),
     phone        = '555-000-0000';

   UPDATE users SET
     email    = CONCAT('user', id, '@example.invalid'),
     password = '$2y$12$fakehashfakehashfakehashfakehash12345678';
   ```
5. Load the anonymized dump into `staging_fleetforge`:
   ```bash
   mysql -u fleetforge_staging_user -p staging_fleetforge < /tmp/prod_backup.sql
   ```
6. Delete `/tmp/prod_backup.sql` immediately after loading.

**Never load unanonymized production data into staging.** Staging servers have broader
access (multiple developers may have SSH keys) and should be treated as lower-trust.

### Staging Downtime Policy

Staging is allowed to be broken for short periods. It is a development and rehearsal
environment, not a customer-facing one. If a broken deploy takes down staging for a few
hours while it is being fixed, that is acceptable. Production SLAs do not apply to staging.

---

## 5. Cost

| Resource | Monthly cost (approximate) |
|---|---|
| Lightsail 8 GB instance | ~$40/mo |
| Lightsail static IP (attached to running instance) | $0 |
| S3 storage for `fleetforge-staging-uploads` | ~$0.50–$2/mo depending on usage |
| SES (staging volume is low) | ~$0.10/mo or free in sandbox |
| Sentry (if using shared project with env tag) | $0 additional |
| SSL certificate (Certbot/Let's Encrypt) | $0 |
| **Total** | **~$40–$43/mo** |

The dominant cost is the Lightsail instance. Staging can be stopped (not terminated) during
periods of inactivity to reduce cost — Lightsail charges for storage on stopped instances
but not for compute. However, stopping and restarting requires updating the static IP
assignment each time, so the convenience tradeoff is low unless costs become a concern.

---

## 6. Timing Decision (D-E): When to Provision Staging

**Decision recorded:** Staging is NOT required for the initial AWS cutover.

### Tradeoff analysis

| Factor | Defer staging | Provision staging before cutover |
|---|---|---|
| Cutover safety | Lower (no rehearsal environment) | Higher (can rehearse on staging first) |
| Cost | $0 until deferred | $40+/mo starting now |
| Provisioning time | Saved for post-launch | Spent before launch (1–2 days) |
| Operational complexity | Simpler | More systems to manage |
| Customer impact if cutover fails | Rollback window covers this | Staging rehearsal reduces risk |

### Recommendation: Defer

For the initial launch, FleetForge is a single-tenant deployment serving one customer
(or a small initial customer set). The cutover risks are manageable with a well-documented
runbook and a tested rollback procedure. The $40/mo and 1–2 days of provisioning time before
launch are better spent on hardening production.

**Default recommendation: defer staging until after the first month of production stability.**

Once the system has been stable in production for 30 days — no critical bugs, no emergency
deploys — provision staging as the first post-launch infrastructure task. At that point,
staging pays for itself immediately: the first non-trivial deploy is tested safely instead
of going straight to customers.

This decision is locked as **D-E**. Revisit if:
- A second customer is onboarded before the 30-day window closes
- A high-risk feature (e.g., billing, payment processing, multi-tenant) is planned
- The team grows and concurrent development branches make staging immediately valuable

---

*Runbook version: 1.0.0 — Last updated: 2026-05-02*
