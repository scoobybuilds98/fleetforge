# Domain 03 — Leases, Reservations & Requests

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/03-leases.md`.

Modules: `leases`, `reservations`, `requests`. The lease lifecycle drives billing,
so bugs here cascade into Domain 02.

## Scope
```
for g in leases reservations requests; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/leases app/admin/reservations app/admin/requests
ls lib/Lease* lib/Reservation* 2>/dev/null
```
Schema: `leases`, `lease_billing_periods`, `lease_equipment`/lease lines,
`reservations`, `requests`, plus the status enums on each.

## End-to-end flows
1. **Lease create** — rate selection (ties to rate cards / customer-equipment rates,
   Class 2 category/name bug), term dates, billing schedule generation.
2. **Lease close** — standard AND **advance** path. The advance path is where the
   `period_type='mileage_only'` truncation fatal lived (Class 3). Close with a
   mileage overage; confirm the mapping to a valid `period_type` holds. There is a
   schema-real smoke `tests/_smoke_advance_close_mileage.php` — run it.
3. **Lease modify / extend / early-terminate** — proration, counter updates
   (Class 7 lease totals drift; `project_drift_remediation_history` corrected
   $75,995.60 of lease drift once — reconcile again).
4. **Reservation → lease conversion** — does converting double-count, or leave the
   reservation in a stale status? Idempotency on convert.
5. **Request intake → reservation/lease** — the `requests` module is thin (1 API
   endpoint); confirm the full handoff and that nothing is dropped silently (Class 4).
6. **Billing-period generation** timezone (Class 6) — the monthly-billing cron tz bug
   (HIGH-1, fixed `7ceac74`) originated from period math; confirm UI/API paths agree.

## Hotspots
- **Class 3:** lease + billing-period status/period_type enums on every transition.
- **Class 7:** lease total counters on modify/close/terminate and their inverses.
- **Class 6:** term boundaries, billing-period derivation, "today" vs business tz.
- **Class 10:** close/convert are multi-table — confirm `db_transaction` wraps them
  and a mid-failure rolls back cleanly (incl. the audit-log insert).
- **Counter ↔ invoice coupling:** closing a lease must leave AR consistent.

## Start here
Run `tests/_smoke_advance_close_mileage.php`, then trace lease create → generate
billing → close (both paths) → reconcile lease + customer counters against prod.
