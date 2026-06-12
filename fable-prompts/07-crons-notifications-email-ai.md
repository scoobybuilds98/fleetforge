# Domain 07 — Crons, Notifications, Email & AI

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/07-crons.md`.

The 33 cron scripts run unattended — bugs here fail **silently and forever**. This
domain has the richest history of confirmed prod bugs; treat the audit as
"re-confirm the fixed ones held + find the open ones." **Class 11 is the headline:
you must EXECUTE each script, not just read it.**

## Scope
```
find cron -name '*.php' | sort
for g in notifications email ai; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/notifications app/admin/email app/admin/ai
ls lib/Mailer* lib/Notification* lib/Ai* 2>/dev/null
```

## Known landmines (from `project_cron_audit_findings`, 2026-06-02)
Fixed — confirm they held:
- HIGH-1 monthly-billing tz (`7ceac74`), HIGH-3 qbo-worker orphan rows (`81a5012`),
  HIGH-2 + MED-5 notification enum/catch/hour-gate (`f4e438c`).
Still OPEN — confirm + report with repro:
- **HIGH-4 archive corruption**, **MED-6 anomaly lock**, **MED-7 Samsara tz**,
  **MED-8 sentry init**.

## The AI-digest bug (Class 5) — the canonical silent failure
`cron/notification_digest.php` bound role slugs to the **hour** placeholders →
`WHERE ur.slug IN (7)` → 0 recipients, forever, no error (the digest was NEVER
emailed). `S-AI-DIGEST-PARAM-FIX` was queued. **Confirm the fix shipped and the
query now binds correctly.** Also: prod crontab lives under **www-data**
(`sudo -u www-data crontab -l`); run manual cron tests as `sudo -u www-data`.
(`project_ai_digest_dispatch_bug`)

## End-to-end flows
1. **Every cron**: execute it as a subprocess against the real DB (locally; or read
   prod state to confirm it ran). Check: does it actually produce output/rows? Is the
   query parameterized correctly (Class 5)? Timezone of "now"/windows (Class 6)?
   Does it catch+log errors or die silently? Is it idempotent on re-run?
2. **Notifications**: enum members for notification type/channel/status (Class 3 — the
   notification enum bug); the hour-gate logic; digest assembly; recipient role
   resolution.
3. **Email** (`lib/Mailer`): send path (DO NOT actually send from prod — Class 11 +
   safety); template rendering with missing vars; bounce/failure handling; the
   compose-email modal blur bug (`project_email_compose_modal_blur_bug` — z-index
   `.modal` 2 vs `.modal-backdrop` 60).
4. **AI**: the digest dispatch (above); rate-limit handling (the logs show 429s);
   token/cost guards; what happens when the AI call fails mid-cron.

## Hotspots
- **Class 11:** execute every script; `php -l` proves nothing here. Watch for
  undefined funcs, wrong settings cols (`value_type`/`group_name`; no `db_value`).
- **Class 5:** every multi-`?` query in a cron.
- **Class 6:** every "now"/date-window in a scheduled job, esp. Samsara (MED-7).
- **Class 4:** swallowed errors → silent no-op (the whole point of this domain).
- **www-data context** on prod for any path/permission assumption.

## Start here
Re-confirm the 4 open cron findings (HIGH-4, MED-6/7/8) and the AI-digest param fix,
each with a concrete repro/evidence, before broadening to the rest.
