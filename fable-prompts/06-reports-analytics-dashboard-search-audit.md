# Domain 06 — Reports, Analytics, Dashboard, Search, Audit & Documents

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/06-reports.md`.

Modules: `reports`, `analytics`, `dashboard`, `search`, `audit`, `documents`.
Mostly read paths — so the bugs are *wrong numbers* (silently), perf cliffs, and
access leaks, not crashes.

## Scope
```
for g in reports analytics dashboard audit documents; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls api/v1/search.php api/v1/health.php
ls app/admin/reports app/admin/analytics app/admin/dashboard app/admin/audit app/admin/documents
```

## End-to-end flows
1. **Reports** — for each report, recompute the headline number by an independent
   query against prod (read-only) and compare. Reports are the easiest place for
   Class 7 drift and Class 5 param-order bugs to hide because nobody cross-checks the
   total. Watch: SENT-only vs all invoices, soft-deleted rows included, tz on date
   ranges (Class 6), CAD/USD mixed without conversion.
2. **Analytics / dashboard tiles** — same: do the tiles agree with the underlying
   tables? Date-window boundaries (today/this-month) in business tz.
3. **Global search** (`api/v1/search.php`) — does it leak rows the caller can't
   otherwise see (Class 8)? Does it respect `deleted_at` (Class 9)? Injection on the
   search term?
4. **Audit log** — is every mutating action actually writing an audit row (the
   accounting/equipment creates do)? Are audit inserts *inside* the txn in a way that
   can fail the real write (Class 10)?
5. **Documents** — upload/download/delete; path traversal / access control on stored
   files; orphaned blobs on delete. (Note the repo-root `storage/credit_applications/`
   tree.)

## Hotspots
- **Class 7 / Class 5:** wrong aggregates — verify by recomputation, not by reading
  the SQL and trusting it.
- **Class 13:** report/list endpoints with no pagination cap or with per-row queries
  → perf cliff on real data volumes; run against prod row counts (read-only) to gauge.
- **Class 8:** search + report access scope; can a lower-privileged role pull a
  report it shouldn't?
- **Class 6:** every date-range filter.

## Start here
Pick the 3 most-used reports/dashboard tiles, independently recompute each against
prod, and report any discrepancy with both numbers. Then audit search for scope +
soft-delete leaks.
