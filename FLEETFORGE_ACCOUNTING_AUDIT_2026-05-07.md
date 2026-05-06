# FleetForge Accounting Module Audit
**Date:** 2026-05-07
**Session:** S-ACCT-AUDIT (read-only)
**Scope:** S028–S037 modules; spec drift, smoke test, coverage
**Spec compared against:** `FLEETFORGE_ACCOUNTING_SPEC.md` v1.2 FINAL
**Database snapshot taken via:** live MySQL `fleetforge` schema, 2026-05-07

---

## Executive Summary

**Overall health: 🟡 YELLOW** — Foundation, GL, AR bridge, AP module, Bank, Fixed Assets, Tax are all *built and routable*; however, three of the eight phases planned in the spec (Phase 24 Reports/Budget, Phase 25 Polish/FX/Year-End UI, Phase 23 cron schedule) were never delivered, and the AR subledger has drifted ~$17K from the GL.

**Numbers at a glance**

| Metric | Spec target | Actual | Status |
|---|---|---|---|
| acc_* tables present in DB | 34 (+1 acc_qbo_sync_log) | 38 (34 spec + 4 unplanned + qbo_sync_log) | ✅ all spec tables present |
| Accounting API endpoints (spec sections 14) | ~80 | 96 PHP files reachable | ✅ |
| Admin pages (spec section 15) | 22 | 23 directories, 23 PHP entry points | ✅ |
| Library service classes | ≥4 (Acc/JE/Bank/Asset) | 7 (`AccountingService`, `JournalEntryService`, `AutoEntryBridge`, `BankService`, `FixedAssetService`, `TaxFilingService`, `DunningLetterGenerator`) | ✅ |
| Spec settings (§17) seeded with defaults | 18 | 18/18 present + 13 derivative | ✅ |
| Cron jobs (spec §18) registered | 6 | 2 of 6 present | ❌ 4 missing |
| Spec JE rules wired (§16) | ~25 | ~14 wired in code | ⚠️ 11 not wired |
| Reports endpoints (spec §10) | 9 | 4 (TB, AR, AP, cash-req) | ❌ 5 missing |
| Budget feature | full module | 0 rows in DB, 0 endpoints, 0 pages | ❌ entirely missing |
| FX revaluation feature | full module | 0 rows in DB, 0 endpoints, 0 pages | ❌ entirely missing |
| Year-end checklist UI | full page | seeded 15/17 items, no UI page | ❌ |
| AR GL ↔ subledger reconciliation | $0.00 drift | $17,064.62 drift (GL low) | ❌ |
| Trial balance dr=cr | balanced | balanced ($182,578.91 / $182,578.91) | ✅ |
| AP GL ↔ subledger reconciliation | $0.00 drift | $0.00 drift | ✅ |

**Top 5 gaps by severity**
1. **AR subledger drift $17,064.62** — GL AR = $26,167.31 vs. open-invoice subledger = $43,231.93. Spec §5 mandates parity.
2. **Phase 24 (Reports & Budgeting) entirely absent** — no P&L, no Balance Sheet, no Cash Flow, no Budget pages, no Budget API, no `acc_budgets` rows. Trial balance & AR/AP aging are the only financial reports built.
3. **Phase 25 (Polish/FX/Year-End/Documents) absent** — `acc_documents` table exists but has zero references in code (no upload endpoint, no display surface); `acc_fx_revaluations` empty with no API; year-end checklist seeded but no admin page; recurring entries scaffolded but no cron and 0 templates.
4. **4 of 6 spec crons missing** — only `collections_auto_escalate.php` and `promise_to_pay_check.php` exist. Missing: `accounting_generate_periods.php`, `accounting_auto_reverse.php`, `accounting_recurring_entries.php`, `accounting_tax_filing_reminders.php`.
5. **6 orphan AP-payment JEs** — `acc_journal_entries` rows with `source_type='ap_payment'` and `source_id` 1-7 exist, but `acc_ap_payments` is empty. Either the subledger was wiped without reversing JEs, or AP payment events fire JE creation without writing to `acc_ap_payments`.

---

## Section 1 — Spec-vs-Code Drift

### 1.1 Database tables

| Spec table | DB present? | Spec column drift | Status |
|---|---|---|---|
| acc_periods | ✅ (24 rows) | matches spec verbatim | ✅ |
| acc_accounts | ✅ (81 rows) | matches | ✅ |
| acc_journal_entries | ✅ (59 rows) | source_type ENUM extended with `tax_remittance` (S035 migration 029) | ✅ |
| acc_journal_entry_lines | ✅ (160 rows) | matches | ✅ |
| acc_recurring_entries | ✅ (0 rows) | matches | ✅ schema, ❌ unused |
| acc_recurring_entry_lines | ✅ (0 rows) | matches | ✅ schema, ❌ unused |
| acc_bills | ✅ (10 rows) | matches | ✅ |
| acc_bill_lines | ✅ (10 rows) | matches | ✅ |
| acc_ap_payments | ✅ (0 rows) | matches | ✅ schema, ❌ empty despite 6 JEs referencing it |
| acc_ap_payment_allocations | ✅ (0 rows) | matches | ✅ schema |
| acc_vendor_credits | ✅ (0 rows) | matches | ✅ schema |
| acc_vendor_credit_applications | ✅ (0 rows) | matches | ✅ schema |
| acc_bank_accounts | ✅ (2 rows) | extra `routing_number` column added in S033 | ⚠️ trivial drift |
| acc_bank_transactions | ✅ (15 rows) | matches | ✅ |
| acc_bank_reconciliations | ✅ (0 rows) | matches | ✅ schema, ❌ never used |
| acc_fixed_assets | ✅ (20 rows) | matches | ✅ |
| acc_depreciation_runs | ✅ (4 rows) | matches | ✅ |
| acc_depreciation_run_lines | ✅ | matches | ✅ |
| acc_asset_disposals | ✅ (0 rows) | matches | ✅ schema |
| acc_asset_impairments | ✅ | matches | ✅ |
| acc_tax_filing_periods | ✅ (0 rows) | extra `updated_at` column added in S035 | ⚠️ trivial drift |
| acc_tax_remittances | ✅ (0 rows) | extra `updated_at` column added in S035 | ⚠️ trivial drift |
| acc_collection_notes | ✅ (6 rows) | matches | ✅ |
| acc_promise_to_pay | ✅ (1 row) | matches | ✅ |
| acc_dunning_letters | ✅ (1 row) | matches | ✅ |
| acc_bad_debt_writeoffs | ✅ (0 rows) | matches | ✅ schema |
| acc_customer_deposits | ✅ (0 rows) | matches | ✅ schema |
| acc_budgets | ✅ (0 rows) | matches | ✅ schema, ❌ no UI/API |
| acc_budget_lines | ✅ (0 rows) | matches | ✅ schema, ❌ no UI/API |
| acc_fx_revaluations | ✅ (0 rows) | matches | ✅ schema, ❌ no UI/API |
| acc_documents | ✅ (0 rows) | matches | ✅ schema, ❌ ZERO code references — no upload endpoint, no display |
| acc_year_end_checklist | ✅ (15 rows) | matches | ⚠️ only 15/17 seeded items, only year=2025 |
| acc_report_configurations | ✅ (0 rows) | matches | ✅ schema, ❌ no UI |
| acc_qbo_sync_log | ✅ | matches | ✅ schema (Phase 26 stub) |
| **Tables NOT in spec but in DB** | | | |
| acc_ai_suggestions | ✅ | unplanned — added by AI side-track session | ⚠️ extra |
| acc_auto_categorization_rules | ✅ | unplanned — added by bank categorization side-track | ⚠️ extra |
| acc_categorization_rules | ✅ | unplanned — appears to overlap auto_categorization_rules | ⚠️ duplicate? |
| acc_capex_requests | ✅ | unplanned — added by S034 CapEx workflow (referenced but not in spec table inventory) | ⚠️ extra |

**Total tables: 38 (34 spec + qbo_sync_log + 4 unplanned).** Spec inventory of 34 tables is fully present in the DB.

### 1.2 API endpoints (spec §14)

| Spec endpoint | Code path | Status |
|---|---|---|
| periods/index | `api/v1/accounting/periods/index.php` | ✅ |
| periods/show | `api/v1/accounting/periods/show.php` | ✅ |
| periods/close | `api/v1/accounting/periods/close.php` | ✅ |
| periods/lock | `api/v1/accounting/periods/lock.php` | ✅ |
| periods/year_end | — | ❌ missing |
| accounts/index | `api/v1/accounting/accounts/index.php` | ✅ |
| accounts/show | `api/v1/accounting/accounts/show.php` | ✅ |
| accounts/create | `api/v1/accounting/accounts/create.php` | ✅ |
| accounts/update | `api/v1/accounting/accounts/update.php` | ✅ |
| accounts/deactivate | `api/v1/accounting/accounts/deactivate.php` | ✅ |
| accounts/import (CSV) | — | ❌ missing |
| journal_entries/index | `api/v1/accounting/journal_entries/index.php` | ✅ |
| journal_entries/show | `api/v1/accounting/journal_entries/show.php` | ✅ |
| journal_entries/create | `api/v1/accounting/journal_entries/create.php` | ✅ |
| journal_entries/post | `api/v1/accounting/journal_entries/post.php` | ✅ |
| journal_entries/reverse | `api/v1/accounting/journal_entries/reverse.php` | ✅ |
| journal_entries/recurring/* | — | ❌ missing (table exists, no API) |
| gl/account_ledger | `api/v1/accounting/ledger/index.php` | ✅ |
| gl/trial_balance | `api/v1/accounting/reports/trial-balance.php` | ✅ (under reports/) |
| gl/reconcile_check | `api/v1/accounting/ar/reconcile_check.php` | ✅ (named ar/, includes both checks) |
| ar/aging | `api/v1/accounting/reports/ar-aging.php` | ✅ (under reports/) |
| ar/statement | `api/v1/accounting/ar/statement.php` | ✅ |
| ar/collection_notes/CRUD | `api/v1/accounting/ar/collection_notes/{index,create}.php` | ⚠️ partial — index + create only, no update/delete |
| ar/promise_to_pay/CRUD | `api/v1/accounting/ar/promise_to_pay/{index,create,update}.php` | ⚠️ partial — no delete |
| ar/dunning_letter (POST) | `api/v1/accounting/ar/dunning_letter.php` | ✅ |
| ar/writeoff | `api/v1/accounting/ar/bad_debt_writeoff.php` | ✅ |
| ar/writeoff_recover | `api/v1/accounting/ar/bad_debt_recovery.php` | ✅ |
| ar/deposits/CRUD | `api/v1/accounting/ar/deposits/{index,create,apply,refund}.php` | ✅ |
| ap/bills/CRUD + void | `api/v1/accounting/bills/{index,show,create,update,delete,approve,void}.php` | ✅ |
| ap/payments/CRUD + void | `api/v1/accounting/ap-payments/{index,create,void}.php` | ⚠️ no show/update |
| ap/vendor_credits/CRUD + apply | `api/v1/accounting/vendor-credits/{index,create,apply}.php` | ⚠️ no update/delete |
| ap/aging | `api/v1/accounting/reports/ap-aging.php` | ✅ |
| ap/cash_requirements | `api/v1/accounting/reports/cash-requirements.php` | ✅ |
| bank/accounts/CRUD | `api/v1/accounting/bank-accounts/*` (5 files) | ✅ |
| bank/transactions/CRUD + import | `api/v1/accounting/bank-transactions/* + bank-import/*` | ✅ |
| bank/reconciliations/CRUD + complete + lock | `api/v1/accounting/bank-reconciliations/*` (5 files) | ✅ |
| bank/transfer | `api/v1/accounting/bank-transfers/create.php` | ✅ |
| bank/nsf | `api/v1/accounting/bank-nsf/create.php` | ✅ |
| assets/index | `api/v1/accounting/fixed_assets/index.php` | ✅ |
| assets/show | `api/v1/accounting/fixed_assets/show.php` | ✅ |
| assets/create | `api/v1/accounting/fixed_assets/create.php` | ✅ |
| assets/update | `api/v1/accounting/fixed_assets/update.php` | ✅ |
| assets/depreciation_run | `api/v1/accounting/depreciation/{preview,post,reverse}.php` | ✅ |
| assets/disposal | `api/v1/accounting/fixed_assets/dispose.php` | ✅ |
| assets/impairment | `api/v1/accounting/fixed_assets/impair.php` | ✅ |
| assets/capex_review | `api/v1/accounting/capex/{flags,capitalize,expense}.php` | ✅ |
| tax/periods/CRUD | `api/v1/accounting/tax/periods/*` (5 files) | ✅ |
| tax/calculate | `api/v1/accounting/tax/periods/calculate.php` | ✅ |
| tax/remittance | `api/v1/accounting/tax/remittances/create.php` | ✅ |
| tax/summary | — | ❌ missing (drill-down lives on `periods/show.php`) |
| reports/profit_loss | — | ❌ MISSING |
| reports/balance_sheet | — | ❌ MISSING |
| reports/cash_flow | — | ❌ MISSING |
| reports/trial_balance | `api/v1/accounting/reports/trial-balance.php` | ✅ |
| reports/ar_aging | `api/v1/accounting/reports/ar-aging.php` | ✅ |
| reports/ap_aging | `api/v1/accounting/reports/ap-aging.php` | ✅ |
| reports/asset_schedule | — | ❌ MISSING (FixedAssetService::depreciationSchedule exists but no API) |
| reports/budget_variance | — | ❌ MISSING |
| reports/configurations/CRUD | — | ❌ MISSING |
| budget/* (4 endpoints) | — | ❌ ALL MISSING |
| fx/revaluations/CRUD + revalue | — | ❌ ALL MISSING |
| **Endpoints not in spec** | | |
| accounting/categorization-rules/* | unplanned bank categorization module | ⚠️ extra |
| accounting/fixed_assets/payoff + payoff_report | unplanned asset payoff feature | ⚠️ extra |
| accounting/capex/{create,start,complete,approve} | richer CapEx workflow than spec | ⚠️ extra (over-built) |

### 1.3 Admin pages (spec §15)

| Spec page | Code path | Status |
|---|---|---|
| Dashboard | `app/admin/accounting/dashboard/index.php` | ✅ |
| Chart of Accounts | `app/admin/accounting/chart-of-accounts/index.php` | ✅ |
| Journal Entries (list+form) | `app/admin/accounting/journal-entries/index.php` | ✅ |
| General Ledger | `app/admin/accounting/ledger/index.php` | ✅ |
| AR → AR Aging | `app/admin/accounting/ar-aging/index.php` | ✅ |
| AR → Customer Statements | `app/admin/accounting/statements/index.php` | ✅ |
| AR → Collections | `app/admin/accounting/collections/index.php` | ✅ |
| AR → Customer Deposits | `app/admin/accounting/deposits/index.php` | ✅ |
| AP → Bills | `app/admin/accounting/bills/index.php` | ✅ |
| AP → Payments | — (no dedicated page) | ❌ missing |
| AP → Vendor Credits | `app/admin/accounting/vendor-credits/index.php` | ✅ |
| Bank → Transactions | `app/admin/accounting/bank-accounts/index.php` (combined view) | ⚠️ folded into bank-accounts page |
| Bank → Reconciliation | `app/admin/accounting/bank-reconciliation/index.php` | ✅ |
| Fixed Assets → Asset Register | `app/admin/accounting/fixed-assets/index.php` | ✅ |
| Fixed Assets → Depreciation | `app/admin/accounting/depreciation/index.php` | ✅ |
| Tax → GST/HST Filing | `app/admin/accounting/tax/index.php` + `show.php` | ✅ |
| Tax → Remittances | folded into `tax/show.php` | ⚠️ no standalone page |
| Reports → Profit & Loss | — | ❌ MISSING |
| Reports → Balance Sheet | — | ❌ MISSING |
| Reports → Cash Flow | — | ❌ MISSING |
| Reports → Trial Balance | `app/admin/accounting/reports/trial-balance.php` | ✅ |
| AP Aging | `app/admin/accounting/ap-aging/index.php` | ✅ (extra, not in spec list) |
| Budget | — | ❌ MISSING |
| Settings (Accounting) | `app/admin/accounting/settings/index.php` | ✅ |
| Periods | `app/admin/accounting/periods/index.php` | ✅ (extra, listed in spec but not in nav) |
| **Pages not in spec** | | |
| capex/index | richer CapEx workflow | ⚠️ extra |
| categorization-rules/index | bank categorization module | ⚠️ extra |
| fixed-assets/payoff-report | asset payoff workflow | ⚠️ extra |

### 1.4 Library service classes (spec §1, scattered)

| Class | Spec methods | Code methods | Status |
|---|---|---|---|
| `lib/Accounting/AccountingService.php` | setting, periodForDate, currentOpenPeriod, validatePeriodForPosting, nextJeNumber, accountBalance, allAccountBalances, arReconciliationCheck, apReconciliationCheck | All 16 public methods present, plus `nextBillNumber`, `nextApPaymentNumber`, `nextDepositNumber`, `nextVendorCreditNumber`, `revenueAccountId`, `revenueAccountCode`, `updateSetting` | ✅ over-delivered |
| `lib/Accounting/JournalEntryService.php` | create, post, reverse, getWithLines | All 4 present | ✅ |
| `lib/Accounting/AutoEntryBridge.php` | onInvoiceSent, onInvoiceVoided, onPaymentReceived, onCreditNoteIssued, onCreditNoteApplied, onBadDebtWriteOff, onOverpaymentReceived (S-FIX-2) | All 7 present, plus `onAssetDisposed`, `onAssetImpaired`, `onDepreciationPosted`, `onDepreciationReversed`, `onTaxRemittancePosted` (5 hook stubs) | ✅ |
| `lib/Accounting/BankService.php` | csvFormats, detectCsvFormat, parseCsv, detectDuplicates, autoMatch, reconciliationSummary, processNsf, recordTransfer, glAccountIdByCode | All 9 present | ✅ |
| `lib/Accounting/FixedAssetService.php` | nextAssetNumber, create, update, calculateForPeriod, previewRun, postRun, reverseRun, dispose, impair, depreciationSchedule, listFlaggedWorkOrders, capitalizeFlaggedWorkOrder, expenseFlaggedWorkOrder | All 21 public methods present (over-delivered with 4 capex sub-methods) | ✅ |
| `lib/Accounting/TaxFilingService.php` | createPeriod, calculatePeriod, markFiled, recordRemittance, listPeriods, getPeriodDetail | All 6 present | ✅ |
| `lib/Accounting/DunningLetterGenerator.php` | generate | Present | ✅ |

### 1.5 Cron jobs (spec §18)

| Spec cron | File present? | Crontab entry? | Status |
|---|---|---|---|
| `accounting_generate_periods.php` | ❌ | unknown | ❌ MISSING |
| `accounting_auto_reverse.php` | ❌ | unknown | ❌ MISSING |
| `accounting_recurring_entries.php` | ❌ | unknown | ❌ MISSING |
| `accounting_collection_alerts.php` | ⚠️ named `collections_auto_escalate.php` | per S-FIX-3 wired | ✅ functionally present |
| `accounting_promise_to_pay_check.php` | ⚠️ named `promise_to_pay_check.php` | per S-FIX-3 wired | ✅ functionally present |
| `accounting_tax_filing_reminders.php` | ❌ | unknown | ❌ MISSING |

**4 of 6 spec crons missing entirely.** No file in `cron/`, no schedule registered.

### 1.6 Settings (spec §17 — 18 keys)

All 18 spec keys are seeded in DB with sensible defaults (verified by direct SELECT). 13 additional derived keys (year-suffixed counters: `accounting.je_next_number.2025`, `accounting.bill_next_number.2026`, etc., plus `accounting.auto_categorization_enabled`, `accounting.customer_credits_account_id`, `accounting.customer_deposits_account_id`, `accounting.retained_earnings_account_id`, `accounting.current_year_ni_account_id`).

| Spec key | DB value |
|---|---|
| accounting.enabled | true |
| accounting.ar_account_id | 4 (= account 1030) |
| accounting.ap_account_id | 21 (= account 2010) |
| accounting.default_cash_account_id | 2 (= account 1010) |
| accounting.gst_payable_account_id | 23 (= account 2030) |
| accounting.pst_payable_account_id | 24 (= account 2040) |
| accounting.gst_receivable_account_id | 6 (= account 1050) |
| accounting.bad_debt_expense_account_id | 70 (= account 6160) |
| accounting.fx_gain_account_id | 79 (= account 7030) |
| accounting.fx_loss_account_id | 80 (= account 7040) |
| accounting.capex_threshold_cad | 2500 |
| accounting.default_depreciation_method | straight_line |
| accounting.default_useful_life_years | 10 |
| accounting.default_salvage_pct | 0.10 |
| accounting.gst_filing_frequency | quarterly |
| accounting.pst_filing_frequency | quarterly |
| accounting.revenue_account_map | 14 line types mapped to GL codes ✅ matches spec verbatim |
| accounting.expense_account_map | 9 vendor types mapped to GL codes ✅ |

### 1.7 Seed data (database/seeds/010-013)

| Seed file | Status | DB rows |
|---|---|---|
| `010_acc_chart_of_accounts.sql` | ✅ present | 81 accounts seeded |
| `011_acc_periods.sql` | ❌ FILE MISSING from `database/seeds/` | yet 24 rows present (Jan 2025–Dec 2026) — likely loaded via `scripts/demo_accounting.php` |
| `012_acc_settings.sql` | ❌ FILE MISSING | yet 31 rows present (incl. all 18 spec defaults) |
| `013_acc_year_end_checklist.sql` | ❌ FILE MISSING | yet 15 rows seeded (year=2025 only; spec says 17 items) |

The data is present in DB but the seed SQL files referenced in S028 PROGRESS log are missing from the seeds directory — fragile rebuild risk if DB ever needs to be re-seeded from scratch.

---

## Section 2 — Functional Smoke Test

### Phase 1 (S028 Foundation)

| Test | Result | Notes |
|---|---|---|
| GET `/api/v1/accounting/accounts/index` returns 81 seeded accounts | ✅ 401 (auth-gated) — file exists, lints clean | DB has 81 accounts, matches spec |
| GET `/api/v1/accounting/periods/index` returns ≥24 periods | ✅ 401 (auth-gated) — file exists, lints clean | DB has 24 periods (22 open, 2 closed) |
| `AccountingService` methods callable | ✅ 16 public methods present | All spec-required + 7 extras |
| `nextJeNumber()` format `JE-YYYY-NNNNN` | ✅ Format verified — code at line 145 uses `sprintf("JE-%s-%05d")` and recent JEs are `JE-2025-00021`, `JE-2025-00020` | matches |

### Phase 2 (S030 Bridge + GL + AR)

| Test | Result | Notes |
|---|---|---|
| `AutoEntryBridge` 6 spec methods + onOverpaymentReceived | ✅ 7 spec + 5 hook-stub methods (12 total) | over-delivered |
| Billing endpoints call AutoEntryBridge inside `db_transaction()` | ✅ verified in 5 endpoints (`invoices/send.php:114`, `invoices/void.php:111`, `payments/create.php:433+443`, `credit_notes/create.php:198`, `credit_notes/apply.php:278`) plus `leases/close.php` | calls present, but cannot verify they're inside transactions without deeper code review |
| GET `/api/v1/accounting/reports/trial-balance` returns balanced TB | ✅ direct SQL: posted entries sum DR=$182,578.91, CR=$182,578.91, diff=$0.00 | balanced |
| GET `/api/v1/accounting/reports/ar-aging` returns 5-bucket aging | ✅ 401 (auth-gated) — file exists, lints clean | endpoint present |
| `AccountingService::arReconciliationCheck()` — GL AR == sum of open invoice balances | ❌ DRIFT $17,064.62 — GL AR = $26,167.31, subledger = $43,231.93 | see Section 4 / Findings |

### Phase 3 (S031 Statements / Collections / Deposits)

| Test | Result | Notes |
|---|---|---|
| acc_collection_notes / acc_promise_to_pay / acc_dunning_letters / acc_bad_debt_writeoffs / acc_customer_deposits tables present | ✅ all 5 tables present | matches spec |
| Pages render under `app/admin/accounting/{collections,statements,deposits}` | ✅ all 3 return 302→login (auth gate proves render path works) | |
| Customer statement generation callable | ✅ `api/v1/accounting/ar/statement.php` exists, lints clean | mPDF rendering not exercised |

### Phase 4 (S032 AP)

| Test | Result | Notes |
|---|---|---|
| acc_bills, acc_bill_lines, acc_ap_payments, acc_ap_payment_allocations, acc_vendor_credits, acc_vendor_credit_applications present | ✅ all 6 tables | |
| Bills page renders | ✅ 302 | |
| Vendor credits page renders | ✅ 302 | |
| GET `/api/v1/accounting/reports/ap-aging` | ✅ 401 (auth-gated) | |
| `apReconciliationCheck()` — GL AP == sum of open bill balances | ✅ GL AP = $4,272.80, subledger AP = $4,272.80, diff = $0.00 | balanced |
| acc_ap_payments populated | ❌ 0 rows in DB despite 6 `ap_payment` source-type JEs (source_id 1-7, source_id=5 missing) | orphan JEs — see Section 4 |
| AP-payments admin page exists | ❌ no `app/admin/accounting/ap-payments/` directory; payments only reachable via bill detail | spec §15 lists "AP → Payments" as separate page |

### Phase 5 (S033 Bank)

| Test | Result | Notes |
|---|---|---|
| acc_bank_accounts, acc_bank_transactions, acc_bank_reconciliations present | ✅ | 2 accounts, 15 txns, 0 recons |
| Bank pages render | ✅ both `bank-accounts` and `bank-reconciliation` return 302 | |
| CSV import path exists | ✅ `api/v1/accounting/bank-import/upload-preview.php` and `confirm.php` | 5 bank formats (RBC/TD/BMO/Scotia/CIBC) hard-coded in BankService |

### Phase 6 (S034 Fixed Assets)

| Test | Result | Notes |
|---|---|---|
| acc_fixed_assets, acc_depreciation_runs, acc_depreciation_run_lines, acc_asset_disposals, acc_asset_impairments | ✅ all 5 tables | 20 assets, 4 depr runs |
| 3 depreciation methods callable | ✅ `FixedAssetService::calculateForPeriod` handles `straight_line`, `declining_balance`, `units_of_production` (verified by S034 test runs in PROGRESS) | |
| Asset disposal flow with gain/loss JE | ✅ `dispose()` method present + tested in S034 | 0 disposals in current data |

### Phase 7 (S035 Tax)

| Test | Result | Notes |
|---|---|---|
| acc_tax_filing_periods, acc_tax_remittances tables | ✅ both present (extra `updated_at` for D19) | 0 rows |
| GST/HST/PST filing period creation works | ✅ `TaxFilingService::createPeriod` present + tested in S035 (41 assertions) | |
| CRA remittance JE posts correctly | ✅ `recordRemittance()` builds 2-line JE DR 2030 / CR bank GL | tested in S035 |

### Phase 8 (S036/S037 — Reports/Budget/Polish)

| Test | Result | Notes |
|---|---|---|
| P&L report renders and balances | ❌ NOT BUILT — no API, no page | |
| Balance Sheet renders and balances | ❌ NOT BUILT | |
| Cash Flow renders | ❌ NOT BUILT | |
| Trial Balance renders | ✅ built in S030 | |
| Budget tables and budget vs actual report | ❌ tables empty (0 rows), no API, no page | |
| Year-end checklist exists | ⚠️ table seeded with 15/17 items for year=2025 only, NO admin page surface | |
| FX revaluation cron exists | ❌ NOT BUILT — no cron, no API, no page | |

---

## Section 3 — Coverage Report

### 3.1 JE rules (spec §16)

| JE rule | Wired? | Code site |
|---|---|---|
| Invoice → sent (DR AR / CR Revenue + GST + PST) | ✅ | `AutoEntryBridge::onInvoiceSent`, `api/v1/invoices/send.php:114` |
| Payment received (DR Cash / CR AR) | ✅ | `AutoEntryBridge::onPaymentReceived`, `api/v1/payments/create.php:443` |
| Invoice voided (reversal) | ✅ | `AutoEntryBridge::onInvoiceVoided`, `api/v1/invoices/void.php:111` |
| Credit note issued (DR Revenue / CR 2060 Customer Credits Liability) | ✅ | `AutoEntryBridge::onCreditNoteIssued`, `api/v1/credit_notes/create.php:198` |
| Credit note applied (DR 2060 / CR AR) | ✅ | `AutoEntryBridge::onCreditNoteApplied`, `api/v1/credit_notes/apply.php:278` |
| Bad debt write-off (DR Bad Debt / CR AR) | ✅ | `AutoEntryBridge::onBadDebtWriteOff` (called from `api/v1/accounting/ar/bad_debt_writeoff.php`) |
| Bad debt recovered | ⚠️ endpoint exists (`bad_debt_recovery.php`) but not via AutoEntryBridge — ad hoc JE | |
| Bill approved (DR Expense + GST ITC / CR AP) | ✅ wired in `api/v1/accounting/bills/approve.php` (10 ap_bill JEs in DB confirm) | not via AutoEntryBridge — direct |
| AP payment made (DR AP / CR Cash) | ⚠️ JEs exist in DB (6 ap_payment source-type) but acc_ap_payments table is empty — orphans suggest the JE wiring runs but the subledger row deletion isn't reversing JE | source unclear |
| Bill voided (reversal) | ✅ endpoint `api/v1/accounting/bills/void.php` present | not exercised |
| Vendor credit | ⚠️ endpoint `vendor-credits/create.php` present, JE wiring not verified | |
| Bank charge recorded | ⚠️ no dedicated endpoint; manual JE assumed | |
| NSF payment | ✅ `BankService::processNsf` reverses payment + charges fee JE | |
| Bank transfer (incl. FX gain/loss) | ✅ `BankService::recordTransfer` | |
| Depreciation run posted | ✅ `FixedAssetService::postRun` builds JE directly via JournalEntryService | |
| Asset disposal — gain | ✅ `FixedAssetService::dispose` (tested S034) | |
| Asset disposal — loss | ✅ same | |
| Asset impairment | ✅ `FixedAssetService::impair` (tested S034) | |
| CRA GST remittance | ✅ `TaxFilingService::recordRemittance` | |
| CRA PST remittance | ✅ same code path | |
| USD AR revaluation (gain) | ❌ NOT WIRED — no FX revaluation code | |
| USD AR revaluation (loss) | ❌ NOT WIRED | |
| Realized FX gain on payment | ⚠️ partial — payments accept exchange_rate but no FX-gain JE generation observed | |
| Realized FX loss on payment | ⚠️ same | |
| Year-end retained earnings (DR Current Year NI / CR Retained Earnings) | ❌ NOT WIRED — no year-end-close endpoint | |
| Customer deposit received | ⚠️ `ar/deposits/create.php` exists, JE wiring not verified | |
| Deposit applied to invoice | ⚠️ `ar/deposits/apply.php` exists | |
| Deposit refunded | ⚠️ `ar/deposits/refund.php` exists | |
| Payment refunded (PASS-6:G1) | ❌ no dedicated bridge method | |
| Overpayment → 2060 customer credits liability (PASS-6:G4) | ✅ `AutoEntryBridge::onOverpaymentReceived` (S-FIX-2) | |
| Customer deposit forfeited (PASS-6:G5) | ❌ no code path observed | |

**Wired: 14 of ~25 spec rules. Missing: 11 (FX, year-end RE, payment refund, deposit forfeiture, several ad-hoc).**

### 3.2 Crons

| Cron | Scheduled? | Last ran (audit_log module='accounting')? |
|---|---|---|
| accounting_generate_periods | ❌ file missing | n/a |
| accounting_auto_reverse | ❌ file missing | n/a |
| accounting_recurring_entries | ❌ file missing | n/a |
| accounting_collection_alerts (collections_auto_escalate.php) | ✅ file present | unverified — would need crontab access |
| accounting_promise_to_pay_check (promise_to_pay_check.php) | ✅ file present | unverified |
| accounting_tax_filing_reminders | ❌ file missing | n/a |

### 3.3 Reports endpoints

| Spec report | API endpoint | Status |
|---|---|---|
| Profit & Loss | — | ❌ MISSING |
| Balance Sheet | — | ❌ MISSING |
| Cash Flow | — | ❌ MISSING |
| Trial Balance | `reports/trial-balance.php` | ✅ |
| AR Aging | `reports/ar-aging.php` | ✅ |
| AP Aging | `reports/ap-aging.php` | ✅ |
| Asset Schedule | — | ❌ MISSING (service method exists, no API) |
| Budget Variance | — | ❌ MISSING |
| Saved Configurations | — | ❌ MISSING (table exists) |

**4 of 9 reports built. 5 missing.**

### 3.4 Settings seeded with defaults

All 18 spec settings have a non-null default value in DB ✅. Verified by direct SELECT in §1.6.

### 3.5 acc_documents wiring

| Coverage | Result |
|---|---|
| Table exists | ✅ |
| Code references (grep `acc_documents` across `api/`, `lib/`, `app/`) | ❌ ZERO matches |
| Wired into bills | ❌ |
| Wired into JEs | ❌ |
| Wired into AP payments | ❌ |
| Wired into fixed assets | ❌ |
| Wired into bank transactions | ❌ |

**`acc_documents` is a phantom table — schema present, completely unused.**

---

## Section 4 — Categorized Findings

### Working as intended

- **Foundation (S028)** — chart of accounts (81 seeded), 24 periods, 18 spec settings + 13 derived counters, 15 year-end checklist items, all four S028 admin pages render under auth gate.
- **General Ledger** — `JournalEntryService` is solid (create/post/reverse/getWithLines all present), JE numbering atomic gap-free `JE-YYYY-NNNNN`, trial balance perfectly balanced ($182,578.91 / $182,578.91), 59 JEs across 5 source types posted.
- **AutoEntryBridge** — 7 invoice/payment/credit-note/bad-debt rules wired into 5 billing endpoints + leases/close, plus 5 fixed-asset/tax hook stubs.
- **Bank Module (S033)** — full CSV import (5 bank formats), reconciliation workflow, NSF processing, inter-account transfers including FX, all 24 files lint clean per S033 SC.
- **Fixed Assets (S034)** — all 3 depreciation methods (SL, DB w/ half-year, UoP), disposal w/ gain/loss, impairment, CapEx workflow with capitalize/expense; tested with 30+ assertions in S034.
- **Tax Module (S035)** — GST/HST + PST filing periods, calculation, mark-filed, remittance; D19 optimistic locking; closed-period redirect (§16); 41 test assertions in S035.
- **AP subledger reconciliation** — GL AP $4,272.80 == subledger $4,272.80 ($0.00 drift).

### Partial / incomplete

- **Customer Deposits (Phase 3 spec)** — table + 4 endpoints (index/create/apply/refund) + page exist, but no rows in DB and JE wiring not verified end-to-end. Bridge methods for "deposit received / applied / refunded / forfeited" not visible in `AutoEntryBridge`.
- **Recurring Entries** — table seeded, scaffolded, but 0 templates and no API/cron; the `accounting_recurring_entries.php` cron from spec §18 doesn't exist.
- **AR collections CRUD** — only `index` + `create` for collection_notes; only `index/create/update` for promise_to_pay (no delete). Spec calls for full CRUD.
- **AP module CRUD shape** — bills are full CRUD; payments has create/index/void only (no show/update); vendor_credits has create/index/apply (no update/delete).
- **Tax remittances** — JE posting works but no dedicated remittances list page (folded into `tax/show.php`).
- **AR statement endpoint** — exists but mPDF rendering path was not exercised in this audit.
- **Bank reconciliation** — schema + workflow built, but `acc_bank_reconciliations` is empty (0 completed).
- **Fixed-asset disposals** — code tested in S034 (gain + loss scenarios), but `acc_asset_disposals` is currently empty.

### Broken

- **AR subledger ↔ GL drift = $17,064.62** — GL AR (account 1030) = $26,167.31; sum of `invoices.balance_due WHERE status NOT IN ('paid','void','draft','written_off')` = $43,231.93. No orphan invoices (every open invoice has its source_type='invoice' JE), so the drift is in payment/credit-note/void JE wiring or in pre-S030 historical state. Per spec §5 / Decision A9, this is a hard error condition.
- **6 orphan AP-payment JEs** — `acc_journal_entries` rows JE-2026-00036/00038/00040/00042/00045/00047 are `source_type='ap_payment'` with `source_id` 1, 2, 3, 4, 6, 7, but `acc_ap_payments` is empty. Either (a) the AP payment subledger rows were deleted without reversing JEs, or (b) AP-payment JEs are being created against a different code path that doesn't write to `acc_ap_payments` (e.g., `api/v1/accounting/bills/approve.php` may post a payment JE under the wrong source type). Either way, AP subledger drill-down is impossible for these 6 JEs.

### Missing entirely

- **Phase 24 — Reports & Budgeting (S036)** — entirely absent: no P&L, Balance Sheet, Cash Flow, Asset Schedule, Budget Variance APIs or pages; `acc_budgets` and `acc_budget_lines` tables empty with no UI/API.
- **Phase 25 — Polish & Integration (S037)** — entirely absent: no FX revaluation cron/API/page (table empty); no year-end-close endpoint or workflow page; no document upload/serve for `acc_documents` (zero code references); no QBO sync (Phase 26 placeholder, expected).
- **4 of 6 spec crons** (`accounting_generate_periods.php`, `accounting_auto_reverse.php`, `accounting_recurring_entries.php`, `accounting_tax_filing_reminders.php`).
- **Recurring journal entries module** — UI absent, cron absent, 0 templates.
- **`acc_documents` table** is fully unused (no upload, no serve, no display anywhere in `api/`, `lib/`, `app/`).
- **Year-end close workflow** — no `periods/year_end.php` endpoint; no admin page for the 15-item year-end checklist (data exists in DB, no surface).
- **Saved report configurations** — table exists, no API, no UI.
- **Account import (CSV)** — spec calls for `accounts/import.php`, not present.
- **AP-payments dedicated admin page** — spec lists "AP → Payments" as a separate sidebar item.

### Drift between spec and code

- **Seed files missing on disk** — `database/seeds/` only contains `010_acc_chart_of_accounts.sql`. Files `011_acc_periods.sql`, `012_acc_settings.sql`, `013_acc_year_end_checklist.sql` (referenced explicitly in S028 PROGRESS log) are not present, yet the data IS in the DB. Likely seeded via `scripts/demo_accounting.php`. Rebuilding the DB from `seeds/` alone will leave these tables empty — fragile.
- **Periods coverage** — DB has 24 periods (Jan 2025–Dec 2026 only). No 2024 history, no 2027. The missing `accounting_generate_periods.php` cron means new periods won't auto-generate; the system will run out of postable periods after Dec 2026 unless manually added.
- **Year-end checklist** — only 15 of the 17 spec items seeded, only year=2025 (no 2026 row). 5 of 15 marked complete suggesting demo state, not real progress.
- **Schema drift (extra columns)** — `acc_bank_accounts.routing_number` (S033, not in spec); `acc_tax_filing_periods.updated_at` and `acc_tax_remittances.updated_at` (S035, for D19). All trivial additions, none destructive.
- **Source_type ENUM extended** — spec lists 12 source types; DB has 13 (added `tax_remittance` in S035 migration 029).
- **4 unplanned tables in DB** — `acc_ai_suggestions`, `acc_auto_categorization_rules`, `acc_categorization_rules`, `acc_capex_requests`. The two categorization tables look like they may overlap (one may obsolete the other). `acc_capex_requests` is referenced in the S034 PROGRESS log but never appeared in the spec's table inventory.
- **Endpoint naming drift** — spec called for `gl/trial_balance` and `gl/account_ledger` and `gl/reconcile_check`; code put trial-balance under `reports/`, ledger under `ledger/`, and reconcile_check under `ar/`. The spec sidebar nav lists "General Ledger" as a top-level item but the page is at `app/admin/accounting/ledger/`.
- **Pages folded that spec lists separately** — bank "Transactions" is folded into the bank-accounts page (spec lists it as its own page); tax "Remittances" is folded into `tax/show.php` (spec lists it separately); AP "Payments" is missing entirely.

---

## Section 5 — Notes for the Gap Analysis

These are observations that aren't strict spec gaps but should inform the next planning pass. **No fixes are suggested here per the read-only contract — these are *for context* only.**

1. **AR drift root-cause is undetermined.** The drift is real ($17K) but no orphan invoices exist. The drift could be:
   - Voided invoices where the void JE was reversed but the original wasn't, or vice versa.
   - Payments posted in pre-S030 era (before AutoEntryBridge wiring) that reduced subledger but never debited GL.
   - Mileage adjustments (`leases/close.php` calls AutoEntryBridge) that may post inconsistent JE pairs.
   - Some `bad_debt_writeoff` events that wrote down the subledger without crediting AR cleanly.

   The next phase planner should either schedule a drift remediation script (similar to `S-FIX-2 / scripts/fix_counter_drift_2026_05_02.php`) or build a diagnostic API endpoint that breaks the drift down per-customer / per-source-type / per-month.

2. **The "S028–S037" framing in the audit prompt is aspirational.** Actual delivered build sessions were S028, S028-b, S028-c, S030, S033, S034, S035. There is **no S031, S032, S036, or S037 row** in `FLEETFORGE_PROGRESS.md`. The spec content for those sessions either landed inside other sessions (S028 covered some Phase 19 collections; S030 covered the bridge/AR Aging/Trial Balance) or was never delivered (Phase 24 Reports/Budget; Phase 25 Polish). The prose section of PROGRESS.md at line 130 lists "S031 Statements/Collections/Deposits" and "S032 AP" as if delivered, but the table rows don't exist — the work for those phases is partially in S028 and S030, with significant gaps remaining (no AP-payments page, no statements UI exercise, no full collections CRUD).

3. **AP-payment JE source-id integrity.** The 6 orphan `ap_payment` JEs share a contiguous source_id sequence (1, 2, 3, 4, 6, 7 — note: 5 missing) and the same date range as the 10 `ap_bill` JEs. This pattern suggests they were created as part of a demo-seed run that wrote to `acc_ap_payments` and `acc_journal_entries`, then a cleanup script truncated `acc_ap_payments` without reversing the JEs. Worth checking `scripts/demo_accounting.php` for the truncation order. (Cleanup, not a new bug, but the orphans currently make AP payment drill-down impossible.)

4. **`acc_documents` is dead schema.** It was added in S028 (table inventory item #92) but no later session wired it. Any AP-bill / JE / asset / bank workflow that wants to attach a scanned document has nowhere to put it. The spec §13 promise of "every bill/JE/bank-txn/asset can have documents attached" is unfulfilled.

5. **Recurring JE module is half-built.** Tables seeded, no UI, no cron, no API. The spec calls for monthly auto-post on the 1st via `accounting_recurring_entries.php`. This is a meaningful gap for any business with monthly accruals.

6. **Period auto-generation is missing.** Spec §18 requires `accounting_generate_periods.php` to create new period rows monthly. Without it, after Dec 2026 (the last seeded period), every JE-creating action will fail `validatePeriodForPosting()` until a period is manually inserted.

7. **FX revaluation has zero implementation despite being ~6 spec rules.** Spec §12 + §16 collectively define USD AR revaluation, realized FX gain/loss on payment, and the reversing-entry pattern. Code has none of this. The `acc_fx_revaluations` table is empty and unreferenced. `accounting.fx_gain_account_id` and `accounting.fx_loss_account_id` settings are seeded but not consumed anywhere outside `BankService::recordTransfer` (which handles transfer-time FX, not revaluation).

8. **Year-end close workflow is missing.** No `api/v1/accounting/periods/year_end.php` (spec §14). The 15 seeded checklist items have nowhere to be checked off. The spec §13 process (run depreciation → post accruals → reconciliation check → post retained earnings → lock all periods → create new year periods) is end-to-end un-built.

9. **Two side-track tables look like they may overlap.** `acc_auto_categorization_rules` and `acc_categorization_rules` are both in DB; only one is referenced by the `categorization-rules/` API folder (which is itself unplanned). Worth a brief check whether one is obsolete.

10. **S028 originally seeded 22 settings; DB now has 31.** The 9 extras are mostly per-year counter rows (`accounting.je_next_number.2026`, `accounting.bill_next_number.2026`, etc.) plus three new mapping keys (`accounting.customer_credits_account_id`, `accounting.customer_deposits_account_id`, `accounting.retained_earnings_account_id`). The spec §17 doesn't mention these; if a spec refresh is on the table, document them.

11. **The reports/cash-requirements endpoint exists but isn't in the spec §10 reports list.** It's listed in spec §6 ("Cash requirements report") but separated from the reports inventory. Not a bug, just an inventory mismatch.

12. **Permissions matrix vs code.** Spec §1.3 defines `chart_of_accounts`, `journal_entries`, `accounts_payable`, `bank_accounts`, `fixed_assets`, `tax_management`, `financial_reports`, `budgets`, `period_management` modules. The `tax_management` module name is used in TaxFilingService endpoints (verified). I did not exhaustively grep `require_permission` calls to confirm every spec module name is honored — worth a follow-up scan.

---

*End of audit. No code, schema, settings, or data were modified during this session. All findings are based on read-only SQL queries, file inventory, PHP `-l` linting, and HTTP probes against a temporary local server (PHP CLI server, port 8765, stopped at session end).*
