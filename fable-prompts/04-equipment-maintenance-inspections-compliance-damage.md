# Domain 04 — Equipment, Maintenance, Inspections, Compliance & Damage

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/04-equipment.md`.

Modules: `equipment` (units + equipment types/templates), `maintenance_work_orders`,
`inspections`, `compliance`, `damage_claims`. We just renamed "template" → "equipment
type" across the equipment UI — verify nothing functional broke in that rename.

## Scope
```
for g in equipment maintenance_work_orders inspections compliance damage_claims; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/equipment app/admin/equipment/templates app/admin/maintenance_work_orders app/admin/inspections app/admin/compliance app/admin/damage_claims
```
Schema: `equipment_units`, `equipment_templates`, `maintenance_work_orders`,
`inspections`, compliance/interval tables, `damage_claims`. Enums for unit status,
WO status, inspection type/result, claim status.

## End-to-end flows
1. **Equipment type (template) create/edit** — RE-VERIFY the recent label rename
   didn't break submit/validation (we changed display strings + a `value="template"`
   sort key was left intentionally). Confirm create still inserts (we proved prod
   ids 7/8/9 saved). Slug uniqueness excludes soft-deleted rows (Class 9) — confirm.
2. **Unit create/edit** — template prefill (`onTemplateChange`). Equipment type IS
   now changeable on edit (a live FK validated by `db_exists` + the NOT NULL FK):
   confirm the change persists, is audited (old/new template_id), and that changing
   it does NOT mutate the unit's stored specs or corrupt existing lease snapshots
   (leases freeze their own rates + `equipment_snapshot_json`); only FUTURE leases
   use the new type's rate lookup. Watch the category→rate-card coupling (Class 2).
3. **Maintenance work order** lifecycle — open→in_progress→closed; parts/labor cost
   roll-up; does closing a WO touch equipment status or accounting?
4. **Inspections** (CVI/MVI/etc.) — interval scheduling drives compliance; tz of the
   "next due" calc (Class 6); does a passed/failed result update unit availability?
5. **Compliance** intervals — `default_*_interval_days` from the template propagate to
   units; expiry/overdue detection; the cron tie-in (anomaly/notification).
6. **Damage claims** — intake, linkage to lease/unit/customer, status, any cost →
   invoice/credit path.

## Hotspots
- **Class 1:** repeatable rows on WO parts/labor, inspection checklists, claim
  line items — same blank-row trap as rate cards.
- **Class 2:** template **name vs category vs id** keys (the rates bug originates from
  equipment_type category/name — confirm equipment-side writes the field rate lookup
  expects).
- **Class 3:** unit/WO/inspection/claim status enums on every write.
- **Class 6:** inspection/compliance "next due" and overdue boundaries.
- **Class 9:** equipment_templates + units soft-delete in every SELECT and uniqueness
  check (the re-invite/revive pattern, FLEETFORGE-F, is the cautionary tale).

## Start here
Re-verify equipment-type create/edit post-rename, then walk unit lifecycle →
inspections/compliance scheduling → WO → damage claims, checking each status enum
against the schema.
