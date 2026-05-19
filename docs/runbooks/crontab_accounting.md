# Accounting Crontab Runbook

**Purpose:** definitive reference for every accounting-related cron, the
exact crontab line, advisory-lock key, and the smoke test that proves
each one runs cleanly post-deploy.

**Last updated:** S037-CRONS (2026-05-19)

---

## Cron inventory

| Cron file | Schedule | Purpose | Advisory lock key | Origin session |
|---|---|---|---|---|
| `accounting_generate_periods.php`     | `0 4 1 * *`  | Ensure 12-month period horizon              | `ff_acct_generate_periods`     | S037-CRONS |
| `accounting_auto_reverse.php`         | `30 2 * * *` | Post auto-reversal JEs (e.g. FX revaluations) | `ff_acct_auto_reverse`         | S037-CRONS |
| `accounting_recurring_entries.php`    | `0 3 * * *`  | Post due recurring JE templates              | `ff_acct_recurring`            | S037-REC |
| `accounting_fx_revaluation.php`       | `0 2 1 * *`  | Monthly FX revaluation engine               | `ff_cron_accounting_fx_revaluation` | S037-FX |
| `accounting_tax_filing_reminders.php` | `30 7 * * *` | GST/HST filing-deadline notifications        | `ff_acct_tax_reminders`        | S037-CRONS |
| `accounting_lease_ni_reclass.php`     | `0 5 1 * *`  | Monthly: reclass NI between 1090 (current) and 1600 (long-term) for active capital leases per ASPE 3065.54 | `ff_lease_ni_reclass_cron`     | S-ACCT-LESSOR-5 |
| `collections_auto_escalate.php`       | `0 8 * * *`  | AR collections status escalation            | (existing; see file)           | S-CRON-3 |
| `promise_to_pay_check.php`            | `30 7 * * *` | Promise-to-pay follow-up + outcome tagging  | (existing; see file)           | S-CRON-3 |

All accounting crons follow the same pattern:
1. Acquire advisory lock (`GET_LOCK(<key>, 0)` or `10`); exit 0 if not acquired.
2. Do work with per-row exception isolation (one failure ≠ batch abort).
3. Append a summary row to `audit_log` (`action='cron'`, `module='accounting'`).
4. Release lock; exit 0 on success, 1 on cron-level error.
5. Errors caught at the top throw to `error_log()` AND
   `FleetForge\Observability\Sentry::captureException()` when Sentry
   is configured.

---

## Crontab block (copy-paste into production)

Edit as the `www-data` user (or whichever user the nginx PHP-FPM pool
runs as) so the cron processes inherit the right filesystem permissions
for `storage/` and the right Sentry DSN from `.env`:

```cron
# FleetForge accounting crons (S037-CRONS canonical block)
0  4 1 * * php /var/www/fleetforge/cron/accounting_generate_periods.php     >> /var/log/fleetforge-cron.log 2>&1
30 2 * * * php /var/www/fleetforge/cron/accounting_auto_reverse.php         >> /var/log/fleetforge-cron.log 2>&1
0  3 * * * php /var/www/fleetforge/cron/accounting_recurring_entries.php    >> /var/log/fleetforge-cron.log 2>&1
0  2 1 * * php /var/www/fleetforge/cron/accounting_fx_revaluation.php       >> /var/log/fleetforge-cron.log 2>&1
30 7 * * * php /var/www/fleetforge/cron/accounting_tax_filing_reminders.php >> /var/log/fleetforge-cron.log 2>&1
0  5 1 * * php /var/www/fleetforge/cron/accounting_lease_ni_reclass.php     >> /var/log/fleetforge-cron.log 2>&1

# Existing (do not duplicate if already installed)
0  8 * * * php /var/www/fleetforge/cron/collections_auto_escalate.php       >> /var/log/fleetforge-cron.log 2>&1
30 7 * * * php /var/www/fleetforge/cron/promise_to_pay_check.php            >> /var/log/fleetforge-cron.log 2>&1
```

### Install

```bash
ssh ubuntu@mainlandrentals.com
sudo -u www-data crontab -e          # paste the block above
sudo -u www-data crontab -l          # verify
sudo touch /var/log/fleetforge-cron.log
sudo chown www-data:www-data /var/log/fleetforge-cron.log
sudo chmod 0644 /var/log/fleetforge-cron.log
```

---

## Per-cron detail

### accounting_generate_periods.php

**What it does:** ensures `acc_periods` always has at least 12 months of
`open` periods extending into the future. Without this, end-of-year
posting would fail with "No open period for YYYY-MM-DD".

**Idempotency:** uses `acc_periods.uq_period UNIQUE(year, month)` —
existence check before INSERT, no race conditions under the advisory lock.

**Smoke:**
```bash
php /var/www/fleetforge/cron/accounting_generate_periods.php
# Expected output: "Generated N period(s). Horizon through YYYY-MM-DD."
# Confirm: SELECT MAX(end_date) FROM acc_periods; should now be ≥ today + 12 months.
```

### accounting_auto_reverse.php

**What it does:** for every JE where
`auto_reverse=1 AND auto_reverse_date=today AND reversed_by_id IS NULL AND status='posted'`,
call `JournalEntryService::reverse()` so the reversing JE lands on
schedule. Primary consumer: FX revaluation JEs.

**Per-JE failure isolation:** if a reversal throws (period locked, JE
already partially reversed, etc.), it's caught locally + logged + the
batch continues.

**Smoke:**
```bash
php /var/www/fleetforge/cron/accounting_auto_reverse.php
# Expected output: "Summary YYYY-MM-DD: reversed=N failed=M"
# Confirm: SELECT * FROM acc_journal_entries WHERE auto_reverse=1
#   AND auto_reverse_date=CURDATE() AND reversed_by_id IS NULL AND status='posted';
#   should return 0 rows.
```

### accounting_recurring_entries.php

**What it does:** for every active `acc_recurring_entries` template where
`isDueToday()` returns true, post the JE via
`RecurringEntryService::postTemplate()` (auto_post=1) or create a draft
(auto_post=0). Idempotency via reference key `[REC-{id}-{YYYY-MM}]`.

**Smoke:** (see `RecurringEntryService` smoke from S037-REC SESSION LOG)

### accounting_fx_revaluation.php

**What it does:** monthly FX mark-to-market for USD-denominated balances.
Posts the gain/loss JE with `auto_reverse=1` so the reversal lands on
the 1st of the next month via `accounting_auto_reverse.php` above.

**Smoke:** (see `FxRevaluationService` smoke from S037-FX SESSION LOG)

### accounting_tax_filing_reminders.php

**What it does:** dispatches notifications via `NotificationService::notify(
'accounting.tax_filing_due', ...)` for tax-filing periods due in
{30, 14, 7, 1} days. Severity = critical when ≤ 7 days, warning
otherwise.

**Idempotency:** skips if a notification was already sent today for the
same `(entity_id, day_bucket)` pair (checked via title LIKE '%{N} day%').

**Smoke:**
```bash
php /var/www/fleetforge/cron/accounting_tax_filing_reminders.php
# Expected output: "Summary YYYY-MM-DD: sent=N skipped=M failed=K"
# Confirm: SELECT id, title, severity FROM notifications
#   WHERE entity_type='tax_filing_period' AND DATE(created_at)=CURDATE();
```

---

## Troubleshooting

**Cron runs but does nothing:**
1. Check the advisory lock isn't stuck:
   `SHOW PROCESSLIST` — look for connections holding `GET_LOCK`.
2. Check `/var/log/fleetforge-cron.log` for the most recent run.

**Cron fails with permission errors:**
1. Verify the cron's user (`www-data`) owns or can read the FleetForge
   working tree.
2. Check `storage/tmp/` is writable for mPDF temp files.

**Periods not being generated:**
1. `accounting_generate_periods.php` runs only on the 1st of the month.
   Run it manually to backfill: `php cron/accounting_generate_periods.php`.

**Auto-reverse JEs not posting:**
1. Confirm the target period is `open` (not `closed` or `locked`).
   `JournalEntryService::reverse()` enforces the period gate.

---

## Decision history

- **S037-CRONS** (2026-05-19) — Created this runbook + 3 missing crons
  (`accounting_generate_periods`, `accounting_auto_reverse`,
  `accounting_tax_filing_reminders`).
- **S037-REC** (2026-05-19) — `accounting_recurring_entries.php`.
- **S037-FX** (2026-05-19) — `accounting_fx_revaluation.php`.
- **S-CRON-3** — collections + promise-to-pay crons.
- **S-ACCT-LESSOR-5** (2026-05-19) — `accounting_lease_ni_reclass.php`
  (monthly NI current/long-term reclass per ASPE 3065.54). Gated on
  `accounting.lessor_module_enabled='1'` setting — short-circuits with
  audit log when disabled. Per-lease idempotency via JE reference
  `LSE-NI-RECLASS-{contract}-YYYY-MM` (D-LESSOR-5-RECLASS-IDEMPOTENT).
