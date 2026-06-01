# FleetForge Audit Template (Codex)

AUDIT TASK: <module name>
ROLE: Second-pair-of-eyes auditor. Read-only. Do not modify any file in the repo.

SCOPE (production code only — skip vendor/, public/, tests/, db_migrations/, docs/)
  Directories: <list>
  Specific files: <cross-cutting files touched>
  Database tables: <tables read/written>

GROUND RULE
  Audit what code actually does. If code and comments/docs disagree, treat code as truth and log the mismatch.

WHAT TO CHECK (priority order)
  1) Security: SQLi, XSS, missing auth, IDOR, CSRF
  2) Money math: DECIMAL + float/native ops vs bcmath
  3) Race conditions: read-then-write without lock/version check
  4) Silent failures: swallowed exceptions, ignored return values, weak guards
  5) Schema drift: code column names vs FLEETFORGE_DATABASE_MASTER.sql
  6) Date math: boundaries, timezone/DST assumptions
  7) Authorization gaps: record-by-id without ownership/tenant checks
  8) Logic bugs: domain formulas/rules (billing, mileage, tax)

ENTRY-POINT CHECKLIST (add this pass explicitly)
  - Every route/page/API/cron entrypoint in scope has auth guard.
  - State-changing routes enforce CSRF (or explicit non-browser justification).
  - Permission checks happen server-side (not only UI hide/show).
  - Tenant/customer scoping exists on every data fetch/mutation.

EVIDENCE RULES
  - Quote only 3–10 lines per finding.
  - Include one concrete exploit/failure path sentence (how it can happen in prod).
  - De-duplicate recurring patterns: one primary finding + “appears in N other files”.

DO NOT FLAG
  - Style/naming/formatting/comments/TODOs.
  - Expected defensive null-coalesce with clear legacy rationale.

SEVERITY GUIDE
  - CRITICAL: high-confidence live bug, concrete blast radius, security/data/financial impact now.
  - MEDIUM: real bug/risk with bounded impact or preconditions.
  - LOW: hygiene/defense gap with limited impact.
  - When uncertain, prefer MEDIUM/LOW over CRITICAL.

OUTPUT
  File: ~/Documents/fleetforge-audits/CODEX_<YYYY-MM-DD>_<module>.md

Per finding:
## [CRITICAL | MEDIUM | LOW] — one-line title
File: <path:line>
Code:
```php
<3-10 line snippet>
```
Why it's a problem: <2-3 concrete sentences>
Proposed fix: <1-2 conceptual sentences>

End with: X CRITICAL / Y MEDIUM / Z LOW. If clean: "No findings."
