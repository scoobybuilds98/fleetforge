# Domain 08 — Integrations: Samsara, GPS, Tracking, Webhooks & Storage

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/08-integrations.md`.

Modules: `samsara`, `gps`, `tracking`, `webhooks`, `storage`. External boundaries —
contract drift, signature/idempotency, and timezone bugs dominate (Class 12, 6, 4).

## Scope
```
for g in samsara gps tracking webhooks storage; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/tracking
ls lib/Samsara* lib/Gps* 2>/dev/null
ls "samsara api" 2>/dev/null
```
Note: there's a top-level `samsara api/` folder and `logs/gps.log` (active) — read
both for the real wire contract and recent behavior.

## End-to-end flows
1. **Samsara distance / location ingest** — the **MED-7 timezone bug** is open
   (`project_cron_audit_findings`): distance attributed to the wrong day. Confirm and
   report. Distance → mileage_logs → mileage billing (ties to Domain 02/03).
2. **Webhook receivers** — signature verification present and correct? Idempotency
   (replay the same webhook — does it double-process)? What happens on malformed
   payload (Class 4 silent swallow vs crash)? Auth context (Class 11 — no session).
3. **GPS / tracking display** — stale-data handling; unit ↔ device mapping; does a
   missing/decommissioned device render empty silently?
4. **Storage** (`api/v1/storage`, 1 endpoint) — access control, path traversal,
   content-type handling on up/download.
5. **OAuth** (also in Domain 01) — token refresh, callback without session, disconnect.

## Hotspots
- **Class 12:** mapping tables (device↔unit, `acc_qbo_*_map`); enum/case mismatch
  across the boundary; one side of a sync shipped without the other.
- **Class 6:** Samsara tz (MED-7) — the marquee open bug here.
- **Class 4:** webhook/ingest errors swallowed; the `gps.log` will show silent
  failures — read it.
- **Idempotency + signatures:** the security-critical part of any webhook.
- **Rate limits / retries:** external API 429/5xx handling without infinite retry.

## Start here
Confirm the Samsara tz bug (MED-7) end-to-end (ingest → which day the distance lands
on → mileage billing impact), then audit every webhook for signature + idempotency.
