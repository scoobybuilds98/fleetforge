# scripts/archive/

Abandoned-session artifacts preserved for historical reference. Files here are **NOT to be executed**; they're kept so SESSION LOG narrative references resolve.

## Index

- **fix_ar_drift_2026_05_07.php** — S-ACCT-FIX-A1 abandoned-session script (2026-05-07). Per `FLEETFORGE_PROGRESS.md` S-DOC-STATUS-RECONCILE-CLOSE-FIXUP entry + S-ACCT-FIX-A1 abandonment row, this script was authored when S-ACCT-FIX-A1 attempted to remediate $17,064.62 of AR subledger ↔ GL drift surfaced in S-ACCT-AUDIT (b74b947). The session registered IN-FLIGHT in working tree on 2026-05-07 then crashed without committing — the working-tree-only registration is the canonical D136-COMMIT-DISCIPLINE incident that locked the "registration must be COMMITTED before any subsequent operation" rule in S-DOCS-CLUSTER-2026-05-11. AR drift remediation eventually deferred to QBO integration arc (F1 in `FLEETFORGE_PREDEPLOY_CHECKLIST.md`). The earlier S-FIX-2 remediation (`scripts/fix_counter_drift_2026_05_02.php`, 2026-05-02) already addressed the customer-side counter drift via Path B semantics — see memory entry `project_drift_remediation_history.md`. Archived 2026-05-12 via S-FORK-CLOSE-RESOLVE Phase 2.2.

- **legacy_database_migrations/** — 12 pre-runner migration files (`027_ai_anomaly_alerts.sql` through `038_lease_dual_units.sql`) that were applied manually via `mysql` CLI between 2026-04-05 and 2026-05-03, BEFORE the migration runner shipped in S-MIGRATIONS-RUNNER (2026-05-04). All 12 verified safe duplicates by S-MIGRATION-AUDIT (2026-05-17): every CREATE TABLE / ALTER COLUMN / ENUM extension / settings INSERT they contain is already present in the live DB (`information_schema` checks) AND in `FLEETFORGE_DATABASE_MASTER.sql` (11 `CREATE TABLE` matches + 18 column/ENUM mentions). Not registered in `schema_migrations` because the runner only governs post-2026-05-04 deltas. Archived 2026-05-17 via S-MIGRATION-AUDIT.

## Discipline

- Never `git rm` an archived script. They're permanent historical references.
- Never re-execute. If similar diagnostic/remediation is needed in the future, write a new dated script — don't resurrect the abandoned one.
- New additions: prepend the file with a comment block citing the originating session + abandonment context + SESSION LOG row that documents the abandonment.
