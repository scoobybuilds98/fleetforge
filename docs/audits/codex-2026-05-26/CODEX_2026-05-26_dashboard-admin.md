# FleetForge Audit — Dashboard (admin)

Date: 2026-05-26  
Auditor: Codex (read-only)

Scope audited:
- `app/admin/dashboard/index.php`
- `api/v1/dashboard/kpis.php`
- `api/v1/dashboard/charts.php`
- `api/v1/dashboard/tables.php`
- `api/v1/dashboard/activity_feed.php`
- Cross-cutting: `config/navigation.php`, `config/permissions.php`
- Tables touched: `report_cache`, `leases`, `equipment_units`, `invoices`, `reservations`, `audit_log`, `customers`, `equipment_templates`

## [CRITICAL] — Dashboard APIs bypass role-level data restrictions
File: api/v1/dashboard/tables.php:59
Code:
```php
require_method('GET');
require_auth_api();

$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date,
            ...,
            i.total_amount, i.balance_due, i.due_date, i.status,
```
Why it's a problem: The dashboard endpoints are accessible to any authenticated staff user (`require_auth_api()` only), but they return financial amounts and audit-log activity. This bypasses role intent in `config/permissions.php` (dispatcher has `payments => NONE`, `audit => NONE`, and invoice access explicitly documented as status/date only with dollar fields stripped). A dispatcher can still retrieve monetary and audit-sensitive data via dashboard API calls.
Proposed fix: Add explicit permission gating for dashboard datasets (at minimum `require_permission` checks and per-field redaction). Keep non-sensitive cards for all staff, but block/strip monetary and audit payloads when `can('payments','view')` or `can('audit','view')` is false. (Pattern appears in `kpis.php`, `charts.php`, and `activity_feed.php` too.)

## [MEDIUM] — Weekly heatmap date loop includes future days
File: api/v1/dashboard/charts.php:496
Code:
```php
for ($d = 0; $d < 7; $d++) {
    $daysAgo = $weekOffset - $d;
    $dt      = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $dow     = (int) date('w', strtotime($dt));
```
Why it's a problem: In the final week (`$weekOffset = 0`), `$daysAgo` becomes negative for `d=1..6`, which generates future dates (`+1..+6 days`) that are outside the SQL window (`<= today`). Those cells render as zero, distorting the most recent week and under-reporting recent revenue.
Proposed fix: Generate each week from a fixed week start and add day offsets (`weekStart + d`), or compute `$daysAgo = $weekOffset + (6 - $d)` so all points remain within `0..83` days ago.

## [MEDIUM] — Revenue-by-type logic contradicts comment and drops historical revenue
File: api/v1/dashboard/charts.php:415
Code:
```php
 * Uses unit_number_invoice_snapshot to avoid broken joins on soft-deleted units.
...
JOIN leases l         ON l.id  = inv.lease_id
JOIN equipment_units eu ON eu.id = l.equipment_unit_id
JOIN equipment_templates et ON et.id = eu.template_id
...
AND eu.deleted_at  IS NULL
```
Why it's a problem: The comment says snapshot-based resilience, but the query requires live `equipment_units`/`equipment_templates` rows and filters soft-deleted units out. Historical invoices tied to deleted units disappear from the donut, so category totals drift from actual invoiced revenue.
Proposed fix: Either use snapshot-backed categorization for historical rows or switch to `LEFT JOIN` with fallback category buckets so deleted units do not erase prior revenue from the chart.

## [LOW] — Monetary values are converted to float in chart serialization
File: api/v1/dashboard/charts.php:267
Code:
```php
'data' => [
    (float) $buckets['0-30'],
    (float) $buckets['31-60'],
    (float) $buckets['61-90'],
    (float) $buckets['90+'],
],
```
Why it's a problem: Amounts are accumulated with bcmath, then cast to binary floats before response. This can introduce cent-level precision artifacts in chart totals (especially when values are later aggregated or compared client-side).
Proposed fix: Return money as fixed-scale strings (or integer cents) through the API and format only at render time. Similar float casts appear in 3 other spots in this file.

Summary: 1 CRITICAL / 2 MEDIUM / 1 LOW.
