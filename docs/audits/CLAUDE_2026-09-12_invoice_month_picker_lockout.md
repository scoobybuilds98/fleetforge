# Invoice month picker locks running leases out of billing

Reported by Mike La Pore (Mainland TTS), 2026-09-12, on lease MTTS484 / id 538.
Investigation: read-only against production. No files changed, nothing written to prod.
Production and local `main` are byte-identical on every relevant file, HEAD `570af884`.

---

## Reply you can forward to Mike

> The August invoice is already right. INV-2026-01967 bills August 26 to 31 only, six days,
> and there is nothing from September on it. It was generated automatically when the lease
> was activated, so you do not need to redo it.
>
> The "Aug 26 → Sep 11" line is a display bug. For a rental less than a month old, the
> billing-month list shows the whole rental to date as one row instead of splitting it by
> month, and then wrongly decides there is nothing left to bill. That is why Generate is
> greyed out.
>
> Six late-August rentals are affected. Each one clears itself once it passes its one-month
> mark. MTTS484 clears on September 26.
>
> If you need September billed before then, use Invoices → Batch Invoicing and set the period
> from September 1 to today. Do not set it to September 30, or you will bill the rest of the
> month before it has been earned. Please do not void the August invoice to try to fix this,
> as voiding makes the page worse, not better.

---

## What is actually happening

`api/v1/leases/billable_months.php` drives the Billing Month list.

1. For a lease with no end date and no return date, it sets its horizon to **today**
   (`:74`, `$extent = date('Y-m-d')`).
2. It prices the **entire lease** from start to that horizon, and splits the lease into
   calendar months only when the answer comes back `monthly_multi_month` (`:104`).
3. MTTS484 is 17 days old, so the answer is `monthly_short_flat`. That is the deliberate
   S-MONTHLY-SHORT-FLAT rule that a rental of one month or less bills one flat month. It is a
   statement about the lease's **final total**, not about how many invoices it needs.
4. So the split is skipped and the whole lease becomes **one** segment, Aug 26 to Sep 11.
5. The row is labelled from its **start month** only (`:172`), which is why a row ending in
   September says "August 2026".
6. The existing 6-day August invoice is matched to that 17-day segment by bare date overlap
   (`:142-143`), so the segment is tagged `billed`, `fully_billed` becomes true, and
   `app/admin/invoices/create.php:391` disables Generate.

Regression origin: commit `efebdc6` (2026-06-24, S-MONTHLY-SHORT-FLAT-PICKER) replaced an
unconditional `segmentsFor()` call with the basis gate. Before it, these leases showed two rows.

## Money

No dollar figure on any blocked lease is wrong. Every invoice bills
`cumulative_correct − already_billed`, so the lease total is the same under any in-order split.
This is revenue **deferred**, not mis-charged. INV-2026-01967's $300 for six days is correct.

Base rental currently blocked from invoicing, measured on prod at extent 2026-09-11:

| Lease    | Start      | Engine total | Billed  | Blocked | Clears on  |
|----------|------------|--------------|---------|---------|------------|
| MTTS480  | 2026-08-20 | 550.00       | 411.43  | 138.57  | 2026-09-20 |
| MTTS479  | 2026-08-20 | 550.00       | 411.43  | 138.57  | 2026-09-20 |
| MTTS481  | 2026-08-27 | 750.00       | 250.00  | 500.00  | 2026-09-27 |
| MTTS483  | 2026-08-27 | 750.00       | 250.00  | 500.00  | 2026-09-27 |
| MTTS484  | 2026-08-26 | 750.00       | 300.00  | 450.00  | 2026-09-26 |
| MTTS478  | 2026-08-14 | 750.00       | 750.00  | 0.00    | 2026-09-14 |
|          |            |              |         |**1727.14**|          |

MTTS478 is blocked but owes nothing more in base rent, as it already reached the flat cap.

## Second, worse defect: voiding does not unlock

The `void` branch at `:157-163` sets a status label but never assigns `$nextDueIndex`. So a
voided invoice leaves `fully_billed` true, and `monthSelectable()` in create.php only accepts
`unbilled`. The void-then-regenerate recovery that create.php:611 advertises in its own warning
text is a dead end.

Nine completed leases are permanently stuck this way, with **$1,040.00** of base rental that no
path can bill. `batch_generate.php:165` rejects non-active leases, so the batch escape hatch does
not reach them either.

| Lease | Contract | Blocked | Lease | Contract | Blocked |
|-------|----------|---------|-------|----------|---------|
| 305   | MTTS398  | 550.00  | 240   | MTTS290  | 50.00   |
| 321   | MTTS206  | 200.00  | 531   | MTTS477  | 50.00   |
| 173   | MTTS184  | 60.00   | 278   | MTTS326  | 25.00   |
| 178   | MTTS191  | 50.00   | 435   | MTTS323  | 25.00   |
| 175   | MTTS186  | 30.00   |       |          |         |

## Recommended fix

Track whether the extent is real, and only apply the whole-lease flat cap when it is.
`generateForLease()` already does this with its `$extentDefinitive` flag. `billable_months.php`
has no equivalent.

1. `billable_months.php:74-79` — set `$extentDefinitive = true` in the `actual_return_date` and
   `end_date` branches, false otherwise.
2. `billable_months.php:96-105` — when the extent is not definitive, set `$spanning = true`
   unconditionally instead of classifying against a horizon that moves every day.
3. `billable_months.php:107` — after `segmentsFor()`, if it returned exactly one segment, restore
   `billing_type = 'single_period'` so single-month spans stay byte-identical.
4. `billable_months.php:157-163` — assign `$nextDueIndex` in the `void` branch too, and relax
   `monthSelectable()` at `create.php:736-738` to accept `void`. This is what fixes the nine
   stuck leases; edits 1 to 3 do not touch them.

Ship alongside: do not disable Generate when the extent is indefinite (`create.php:391`), and
reword the banner at `create.php:161-162` from "fully billed through <today>" to
"billed through <last invoiced period end>, this lease is still running".

This is a bug fix, not a policy change. It does not touch the S-MONTHLY-SHORT-FLAT gate at
`HolisticLeaseEngine.php:485`. The flat cap still decides the amount at generation time through
the running reconciliation, and still applies in full once the lease acquires a real end.

Regression surface: 154 of 161 open-ended active leases already classify `monthly_multi_month`
and are untouched. Run `_smoke_invoice_month_picker.php`, `_smoke_holistic_generate_fix.php`
(including T3 at :182, the one open-ended fixture), `_smoke_billing_engine_fixes.php`,
`_smoke_lease_minimum_days.php`, `_smoke_lease_close_remove_days.php`, `_smoke_invoice_dating.php`.
There is currently no open-ended picker fixture, which is why `efebdc6` shipped. Add one.
`api/v1/leases/update.php:~1012` is a second consumer and inherits both defects.

## Worth a separate look

- **MTTS485 / lease 539** carries draft INV-2026-02128 billing Sep 8 to 30, $750 base rental, on
  a rental that started September 8. The amount is correct for the period it covers, so this is
  advance billing rather than an error, but it charges three weeks that have not happened. Same
  `activate.php:401` rule that produced Mike's correct 6-day August invoice.
- **September is unbilled fleet-wide for an unrelated reason.** 160 monthly leases have
  `next_billing_date` in the past, and `cron.invoice_generate_monthly_enabled` is `'0'` on prod.
  Do not let the picker bug be read as the explanation for that backlog.
- **GPS days leak on gapped leases.** On leases with a missing month, base rental self-reconciles
  but GPS does not, so those days are billed by nothing.
