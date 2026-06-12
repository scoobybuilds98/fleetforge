# Domain 01 — Accounting, QuickBooks & OAuth

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output via
> `findings-template.md` → `fable-prompts/findings/01-accounting-quickbooks.md`.

**This is the largest and highest-risk domain** — it moves money and syncs to an
external system of record. ~185 accounting endpoints, ~67 QuickBooks endpoints,
57 accounting pages, 21 QBO pages, 4 oauth pages. **Subdivide it** across several
Fable agents (e.g. GL/journal, AR/AP, reconciliation, period-close, QBO push,
QBO pull/match, OAuth) and merge findings.

## Scope — enumerate before you start
```
find api/v1/accounting -name '*.php' | sort
find api/v1/quickbooks -name '*.php' | sort
ls app/admin/accounting app/admin/quickbooks app/admin/oauth
ls lib/QboPushers lib/Qbo* lib/Accounting* 2>/dev/null
```
Read the schema for: `journal_entries`, `journal_entry_lines`, `accounts` (chart of
accounts), `gl_*`, `acc_qbo_*_map`, `reconciliation*`, `periods`/period-close tables,
`invoices`, `payments`, `bills`, `vendor_credits`. List every enum/constraint.

## End-to-end flows to trace (money correctness)
1. **Journal entry create/edit** → lines must balance (Σdebits = Σcredits) at UI,
   API, and DB; rev-rec/issue/due dates derive from the right source (recent commit
   `a2bc7ff` derives them from the billing period — confirm no path bypasses it).
2. **Invoice → GL** posting and **payment → GL** posting; void/credit-note reversals
   produce the correct contra entries (Class 7 drift — `outstanding_balance` and
   lease totals reflect SENT only).
3. **Period close / lock**: can you post into a closed period? Does close re-open
   leak? Timezone of the boundary (Class 6).
4. **Bank reconciliation**: matching, partial matches, un-match, the anomaly lock
   (cron MED-6 is an open finding — see `project_cron_audit_findings`).
5. **QBO push** (`lib/QboPushers/*Pusher.php`): every entity (Invoice, Payment, Bill,
   Customer, Vendor, Account, JournalEntry, CreditMemo…) — enqueue gate, idempotency,
   the `acc_qbo_*_map` row, retry/orphan handling (cron HIGH-3 fixed orphan rows).
6. **QBO pull / account matching**: `AccountMatcher::SUBTYPE_EQUIVALENCE` — FF
   snake_case (9 vals) vs QBO PascalCase-plural; **do not conflate** (Class 12,
   `project_qbo_subtype_taxonomy`).
7. **OAuth connect/refresh/disconnect**: token refresh on expiry; callback without a
   session (Class 11); disconnect that orphans maps.

## Domain hotspots (check explicitly)
- **Class 3 (enum truncation):** every JE/invoice/payment status + `period_type`
  write. The `period_type='mileage_only'` fatal lives in this neighborhood.
- **Class 6 (dates):** `TxnDate` future-guard parity with the GL JE source
  (commit `cda118a` D-QBO-DATING-1); issue/due/rev-rec derivation; period boundaries.
- **Class 7 (drift):** any `SET balance = balance ± ?` and its inverse; reconcile
  against fresh aggregates on prod (read-only).
- **Class 12 (integration drift):** wipe doesn't clear `acc_qbo_*_map`; payments need
  an FF undeposited-funds account mapped; a Pusher shipped without its UI page
  (`feedback_ui_completeness_with_backend` — cross-check every `lib/QboPushers/*` has
  an `app/admin/quickbooks/<entity>.php`).
- **bcmath:** money is stored as strings and compared with `bccomp`. Look for native
  float math (`+`, `<`) on money, or `clean_decimal` scale mismatches.
- **Idempotency:** double-submit a push or a payment — does it duplicate?

## Start here
Pick a sub-area, enumerate its endpoints, and for each mutating endpoint run the
5-step method. Reconcile at least one money counter against prod data (read-only)
and report any drift as CONFIRMED with the query + numbers.
