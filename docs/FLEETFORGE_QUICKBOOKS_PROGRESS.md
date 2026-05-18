# FleetForge — QuickBooks Integration Progress

**Owner:** Avi (Mainland Truck & Trailer Sales)
**Companion docs:** `FLEETFORGE_QUICKBOOKS_SPEC.md` (canonical reference), `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9 (session-by-session plan), `FLEETFORGE_PROGRESS.md` (master FF progress log).
**Purpose:** Single living tracker for the QuickBooks integration arc. Every S-QBO-N session appends its row here when it ships. Decision locks land in the D-QBO-* table. Schema additions, crons, and settings tracked separately for quick lookup.

**Current arc status (as of 2026-05-18):**

🟡 **PRE-IMPLEMENTATION.** Phase QBO not started. Blocked by Phase A → B → C → D completion per dependency chain (D-ARCH-9). Specs locked, decisions locked, nothing built yet.

**Next session up:** S-ACCT-FIX-AP (Phase A — NOT QBO). S-QBO-1 will be queued after Phase A, B, C, D ship.

---

## 1. SESSION STATUS BOARD

Legend: 📋 PLANNED | 🟡 QUEUED | 🔄 IN-PROGRESS | ✅ DONE | ⛔ BLOCKED | ❌ FAILED | ⏸ DEFERRED

### Phase QBO-1: Foundation (4 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-1 | 📋 PLANNED | — | OAuth scaffolding, Settings → QuickBooks tab (Connection Card), token storage in settings, QuickBooksClient class skeleton, sandbox connection verification, refresh-token pinger cron |
| S-QBO-2 | 📋 PLANNED | — | QuickBooksClient HTTP boundary completion: GET/POST/PUT, query SQL, getEntity/createEntity/updateEntity, error classification, Sentry instrumentation |
| S-QBO-3 | 📋 PLANNED | — | Sync infrastructure tables: acc_qbo_sync_queue + acc_qbo_sync_log + acc_qbo_drift_events. Worker cron skeleton |
| S-QBO-4 | 📋 PLANNED | — | Sync infrastructure UI: Sync Log page, Drift Detection page (basic), QuickBooks Dashboard page (basic) |

### Phase QBO-2: Customers (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-5 | 📋 PLANNED | — | Customer mapping flow: pull QBO customers, manual matching UI, acc_qbo_customer_map population, Customers Sync page |
| S-QBO-6 | 📋 PLANNED | — | Customer push: QboCustomerPusher, sync on create/update/delete, name collision handling, sync mode toggle |

### Phase QBO-3: Vendors (1 session)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-7 | 📋 PLANNED | — | Vendor mapping + push: QboVendorPusher, acc_qbo_vendor_map, Vendors Sync page |

### Phase QBO-4: Reference Data Mapping (3 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-8 | 📋 PLANNED | — | Chart of Accounts mapping: pull QBO accounts, manual mapping UI, acc_qbo_account_map, bridge-account validator |
| S-QBO-9 | 📋 PLANNED | — | Tax Code mapping: pull QBO tax codes, identify 'NON' override target, acc_qbo_tax_code_map, NS HST 14% / 15% effective-date handling |
| S-QBO-10 | 📋 PLANNED | — | Item / Product mapping: create QBO Items for all 17 FF item types, including dedicated "Rental Reconciliation Credit" Item (D-QBO-10-1 locked), acc_qbo_item_map |

### Phase QBO-5: Invoices (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-11 | 📋 PLANNED | — | Invoice push (FF → QBO) on send: QboInvoicePusher with tax-override pattern, engine-version dispatch (period_independent vs holistic), FX rate pinning for USD, async queue path for cron-generated, sync path for user-initiated |
| S-QBO-12 | 📋 PLANNED | — | Invoice void + status sync: void operation, paid status from payment push, immutability guard |

### Phase QBO-6: Payments + Portal Embed (3 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-13 | 📋 PLANNED | — | Payment pull (QBO → FF) for QBO-Payments-webhook-originated: acc_qbo_webhook_events, handler logic, idempotency |
| S-QBO-14 | 📋 PLANNED | — | Payment push (FF → QBO) for FF-originated: QboPaymentPusher, allocate-to-invoice mapping, handshake-back for webhook-originated |
| S-QBO-15 | 📋 PLANNED | — | QBO Payments embed in customer portal: "Pay Online" button on portal invoice show, hosted-page redirect, webhook signature verification (HMAC-SHA256), reuse of S-PROD-2 webhook pattern |

### Phase QBO-7: Credit Memos & Refunds (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-16 | 📋 PLANNED | — | Credit memo push: QboCreditMemoPusher, two-step JE bridge (creation + application), LinkedTxn to QBO Invoice |
| S-QBO-17 | 📋 PLANNED | — | Refund receipt push: dependent on S-MILEAGE-3-ACCT-SPEC resolution (currently CPA-blocked per D-I (A) / D176) |

### Phase QBO-8: Bills & AP (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-18 | 📋 PLANNED | — | Bill push: QboBillPusher, ITC tax handling, account-based expense lines, acc_qbo_bill_map |
| S-QBO-19 | 📋 PLANNED | — | Bill payment push: QboBillPaymentPusher, check/EFT pay types, BankAccountRef from acc_qbo_bank_account_map |

### Phase QBO-9: Banking (1 session)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-20 | 📋 PLANNED | — | Bank account mapping + read-only CDC mirror: acc_qbo_bank_account_map, acc_qbo_bank_transaction_map, qbo_bank_cdc.php daily cron, Bank Mirror page |

### Phase QBO-10: Journal Entries (1 session)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-21 | 📋 PLANNED | — | JE push (FF → QBO): QboJournalEntryPusher, bridge-derived JE skip logic, push depreciation / tax remittance / year-end / recurring / manual / adjusting / reversing JEs only |

### Phase QBO-11: Fixed Assets & Tax Remittances (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-22 | 📋 PLANNED | — | Fixed asset JE sync (depreciation + disposal JEs only — asset records stay FF-only) |
| S-QBO-23 | 📋 PLANNED | — | Tax remittance JE sync — supports accountant filing GST34 through QBO NETFILE |

### Phase QBO-12: Reconciliation & Monitoring (3 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-24 | 📋 PLANNED | — | Drift detection cron qbo_drift_check.php with full per-entity comparison, tolerance configuration, notification dispatch |
| S-QBO-25 | 📋 PLANNED | — | Drift resolution workflows: resolve via FF action, resolve via QBO action, accept divergence, suppress; bulk operations |
| S-QBO-26 | 📋 PLANNED | — | Manual Sync page: force re-sync per entity, force full type re-sync, force pull from QBO |

### Phase QBO-13: Historical Migration (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-27 | 📋 PLANNED | — | **Historical pull from QBO (XL).** Absorbs AR drift remediation (D-QBO-CORE-11): H5 LP Logistics 5 orphan payments + H6 Lepore 4 inflated DR-AR JEs (1.375× ratio). 5-phase execution: sandbox dry-run, sandbox full run, prod dry-run, prod execution, verification. Stop-gates documented |
| S-QBO-28 | 📋 PLANNED | — | Historical verification: post-pull drift = 0 confirmation, manual review report, accountant sign-off |

### Phase QBO-14: Production Cutover (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-29 | 📋 PLANNED | — | Production OAuth + realm switch: register production Intuit Developer app, production credentials, environment toggle, production webhook URL |
| S-QBO-30 | 📋 PLANNED | — | Cutover dry run + execution + 14-day monitoring window with rollback procedure documented |

---

## 2. SESSION LOG (chronological — append on ship)

Format: `| SESSION-ID | DATE | One-line description | Files changed | SC results | Decisions locked |`

| Session | Date | Description | Files | SC results | Decisions |
|---|---|---|---|---|---|
| _(empty — first row appends when S-QBO-1 ships)_ | | | | | |

---

## 3. D-QBO-* DECISION LOG

### D-QBO-CORE-* (locked pre-Phase QBO, in spec)

| ID | Decision | Source |
|---|---|---|
| D-QBO-CORE-1 | FleetForge is canonical source of truth; QBO is downstream mirror | QBO spec §4.1 |
| D-QBO-CORE-2 | Three QBO → FF exceptions: payments webhook, bank feed CDC pull, one-time reference data import | QBO spec §4.1 |
| D-QBO-CORE-3 | Accounting module not deprecated; both systems run permanently | QBO spec §4.1 |
| D-QBO-CORE-4 | Push happens AFTER FF commits, never before/during | QBO spec §4.1 |
| D-QBO-CORE-5 | Push is queued and idempotent by default; sync mode reserved for user-initiated single actions | QBO spec §4.1 |
| D-QBO-CORE-6 | Tax computed FF-side; QBO accepts via TxnTaxDetail.TotalTax override with TaxCodeRef='NON' | QBO spec §4.1 |
| D-QBO-CORE-7 | Sent invoices immutable in both systems | QBO spec §4.1 |
| D-QBO-CORE-8 | OAuth credentials in settings table with is_sensitive=1; masked to last 4 chars in UI | QBO spec §4.1 |
| D-QBO-CORE-9 | Sandbox used for all development; production cutover one-time in S-QBO-29 / S-QBO-30 | QBO spec §4.1 |
| D-QBO-CORE-10 | Drift tolerance: $0.05 invoice; $0.01 payment; $0.00 customer/vendor; $1.00 GL account overall | QBO spec §4.1 |
| D-QBO-CORE-11 | AR drift remediation ($17,064.62) absorbed into S-QBO-27 historical pull cross-reference | QBO spec §4.1 |
| D-QBO-CORE-12 | Both engine versions (period_independent + holistic) push invoices to QBO; engine-version dispatch in S-QBO-11 | QBO spec §4.1 |
| D-QBO-CORE-13 | Production live at https://mainlandrentals.com/fleetforge since 2026-05-16; nginx (D202); SSL Let's Encrypt; D8 unlocked | QBO spec §4.1 |

### D-QBO-N-* (per-session, anticipated)

To be locked as each session ships. Anticipated locks:

| Session | Anticipated decisions |
|---|---|
| S-QBO-1 | OAuth callback URL format, token storage encryption approach, refresh-token pinger schedule, realm change runbook |
| S-QBO-5 | Customer name collision resolution, fuzzy-match threshold during initial mapping, deactivated-customer handling |
| S-QBO-8 | Bridge-account validator strictness, unmapped-account fallback, custom account creation |
| S-QBO-9 | NON code identification approach, NS HST date-effective handling, ITC tax code differentiation |
| S-QBO-10 | D-QBO-10-1 (recon credit representation — recommendation locked: dedicated Item), GPS Item account mapping |
| S-QBO-11 | Sync mode threshold synchronous-vs-queued, tax-override field precision, FX rate source, SyncToken refresh cadence, engine-version dispatch error handling |
| S-QBO-15 | Webhook signature verification approach (HMAC-SHA256 locked), retry policy on webhook failure, FF-side payment timing, success/cancel URL format |
| S-QBO-27 | Pull batch size, resumability checkpoint cadence, dry-run gating, H5 reconstruction approach, H6 stop-gate trigger criteria, post-pull AR verification |
| S-QBO-29 | Production credential storage location, webhook URL format, sandbox/production rollback procedure |

---

## 4. SCHEMA CHANGES LOG

### Tables created (20 new across Phase QBO)

| Table | Created in | Status | Notes |
|---|---|---|---|
| acc_qbo_sync_queue | S-QBO-3 | 📋 PLANNED | Push queue |
| acc_qbo_sync_log | S-QBO-3 | 📋 PLANNED | API call audit log; 365-day retention |
| acc_qbo_drift_events | S-QBO-4 | 📋 PLANNED | Drift detection events |
| acc_qbo_account_map | S-QBO-8 | 📋 PLANNED | COA mapping |
| acc_qbo_tax_code_map | S-QBO-9 | 📋 PLANNED | Tax code mapping |
| acc_qbo_item_map | S-QBO-10 | 📋 PLANNED | Line item type mapping |
| acc_qbo_customer_map | S-QBO-5 | 📋 PLANNED | Customer mapping |
| acc_qbo_vendor_map | S-QBO-7 | 📋 PLANNED | Vendor mapping |
| acc_qbo_invoice_map | S-QBO-11 | 📋 PLANNED | Invoice mapping + drift_amount generated column |
| acc_qbo_payment_map | S-QBO-13 | 📋 PLANNED | Payment mapping with origin field |
| acc_qbo_webhook_events | S-QBO-15 | 📋 PLANNED | Webhook event log |
| acc_qbo_payment_initiations | S-QBO-15 | 📋 PLANNED | Outbound payment initiation tracking |
| acc_qbo_credit_memo_map | S-QBO-16 | 📋 PLANNED | Credit memo mapping |
| acc_qbo_refund_receipt_map | S-QBO-17 | 📋 PLANNED | Refund mapping |
| acc_qbo_bill_map | S-QBO-18 | 📋 PLANNED | Bill mapping |
| acc_qbo_bill_payment_map | S-QBO-19 | 📋 PLANNED | Bill payment mapping |
| acc_qbo_bank_account_map | S-QBO-20 | 📋 PLANNED | Bank account mapping |
| acc_qbo_bank_transaction_map | S-QBO-20 | 📋 PLANNED | Bank transaction mapping |
| acc_qbo_journal_entry_map | S-QBO-21 | 📋 PLANNED | JE mapping |
| acc_qbo_fixed_asset_map | S-QBO-22 | 📋 PLANNED | Fixed asset reference (limited) |

### Column additions to existing tables

| Table | Column | Added in | Status |
|---|---|---|---|
| payments | origin | S-QBO-13 | 📋 PLANNED |
| acc_bank_transactions | source | S-QBO-20 | 📋 PLANNED |
| acc_bank_transactions | is_readonly | S-QBO-20 | 📋 PLANNED |
| acc_bank_transactions | qbo_bank_txn_id | S-QBO-20 | 📋 PLANNED |

---

## 5. CRON JOBS LOG

| Cron | Schedule | Added in | Status | Purpose |
|---|---|---|---|---|
| qbo_token_refresh.php | Daily 02:00 | S-QBO-1 | 📋 PLANNED | Refresh OAuth tokens; alert if < 14 days to expiry |
| qbo_sync_worker.php | Every 1 min | S-QBO-3 | 📋 PLANNED | Process queued push jobs |
| qbo_bank_cdc.php | Daily 02:30 | S-QBO-20 | 📋 PLANNED | Pull bank transactions from QBO (Exception #2) |
| qbo_drift_check.php | Daily 03:30 | S-QBO-24 | 📋 PLANNED | Detect and surface drift |
| qbo_webhook_replay.php | Every 15 min | S-QBO-15 | 📋 PLANNED | Reprocess stuck webhook events |
| qbo_payment_initiation_cleanup.php | Daily 04:00 | S-QBO-15 | 📋 PLANNED | Clean up stale (>24h) payment initiations |

---

## 6. SETTINGS KEYS LOG

Added in S-QBO-1 unless noted otherwise. All `is_sensitive=1` flagged in spec §24.

**Connection / OAuth (S-QBO-1):**
- quickbooks.environment, quickbooks.client_id, quickbooks.client_secret, quickbooks.realm_id, quickbooks.access_token, quickbooks.refresh_token, quickbooks.access_token_expires_at, quickbooks.refresh_token_expires_at, quickbooks.last_connected_at, quickbooks.last_token_refresh_at, quickbooks.connection_status, quickbooks.connection_error, quickbooks.webhook_verifier_token, quickbooks.tax_override_code_id

**Master controls (S-QBO-1, default kill-switch off until S-QBO-30):**
- quickbooks.sync_enabled, quickbooks.payments_enabled, quickbooks.dry_run_mode

**Per-entity sync mode (S-QBO-3):**
- quickbooks.sync_mode.{customer, vendor, invoice, payment, credit_memo, refund_receipt, bill, bill_payment, journal_entry, depreciation_je, recurring_je, tax_remittance_je, year_end_closing_je}

**Drift tolerance (S-QBO-4):**
- quickbooks.drift_tolerance.{customer, vendor, invoice, payment, credit_memo, bill, gl_account}

**Retry and rate limiting (S-QBO-2):**
- quickbooks.retry.max_attempts, quickbooks.retry.backoff_base_seconds, quickbooks.rate_limit.throttle_threshold, quickbooks.rate_limit.throttle_seconds

**CDC and webhook (S-QBO-20 / S-QBO-15):**
- quickbooks.cdc.bank_poll_interval_minutes, quickbooks.cdc.last_bank_pull_at, quickbooks.webhook.replay_window_hours

---

## 7. MAPPING TABLE POPULATION STATUS

| Mapping | Required entries | Status | Session |
|---|---|---|---|
| Chart of Accounts | 81 FF accounts | 0 of 81 mapped | S-QBO-8 (PLANNED) |
| Tax Codes | All FF tax_rates rows | 0 of N mapped | S-QBO-9 (PLANNED) |
| Items | 17 FF invoice_line_items.item_type ENUM values | 0 of 17 mapped | S-QBO-10 (PLANNED) |
| Customers | All non-deleted customers (~varies) | 0 mapped | S-QBO-5 (PLANNED) |
| Vendors | All non-deleted vendors (~varies) | 0 mapped | S-QBO-7 (PLANNED) |
| Bank Accounts | All acc_bank_accounts rows | 0 mapped | S-QBO-20 (PLANNED) |

---

## 8. KNOWN ISSUES / OPEN BLOCKERS

### Pre-Phase QBO accountant briefing

Q-CPA-1 through Q-CPA-7 must be resolved before S-QBO-1 starts:

- [ ] Q-CPA-1: Does the accountant use QBO Classes or Locations? (Default assumption: no)
- [ ] Q-CPA-2: QBO tier confirmed (Plus)? Any add-ons active?
- [ ] Q-CPA-3: Custom fields, custom tax codes, custom GL accounts already in your QBO file?
- [ ] Q-CPA-4: Does the accountant ever create invoices directly in QBO that don't originate in FF?
- [ ] Q-CPA-5: Acceptable bill-entry workflow shift (FF instead of QBO)?
- [ ] Q-CPA-6: Compilation vs review engagement target?
- [ ] Q-CPA-7: Sign-off on lease classification wizard (Phase D)

### Pre-Phase QBO infrastructure

- [ ] Intuit Developer account registered (sandbox app + production app)
- [ ] Sandbox app credentials obtained
- [ ] Webhook URL registered in Intuit Developer dashboard
- [ ] Production app credentials obtained (deferred until S-QBO-29)

### Pre-Phase QBO FleetForge state

- [x] Production live (D-ARCH-13)
- [x] D8 Lightsail unlocked
- [x] nginx D202 locked
- [x] SSL active (Let's Encrypt)
- [x] HolisticLeaseEngine shipped (S-BILLING-HOLISTIC-ENGINE 2026-05-17)
- [x] S-PROD-2 shipped (Sentry + SES webhook pattern available for QBO webhook reuse)
- [ ] Phase A complete (S-ACCT-FIX-AP, S-ACCT-FIX-DOCS)
- [ ] Phase B complete (S036, S037-FX, S037-YE, S037-REC, S037-CRONS, S037-CRUD)
- [ ] Phase C complete (11 S-ACCT-* sessions)
- [ ] Phase D complete (6 S-ACCT-LESSOR-* sessions)
- [ ] S-MILEAGE-3-ACCT-SPEC unblocked or deferred (affects S-QBO-17 only)

---

## 9. POST-CUTOVER OPERATIONAL CHECKLIST

To be filled in during S-QBO-30 14-day monitoring window:

- [ ] First 24h: Sentry dashboard reviewed every 2 hours
- [ ] First 24h: Drift dashboard reviewed every 4 hours
- [ ] First 24h: Sync log error patterns checked
- [ ] Day 2-14: Daily drift review
- [ ] Day 2-14: Daily sync error review
- [ ] Day 2-14: Weekly accountant check-in
- [ ] Day 14: Final cutover review meeting with accountant

---

## 10. NEXT SESSION UP

**Next QBO session:** S-QBO-1 (Foundation: OAuth + Settings + sync queue + drift detection scaffolding).
**Blocked by:** Phase A → B → C → D completion (D-ARCH-9 strict order).
**Estimated start:** TBD — depends on Phase A-D execution pace.

**Next FF session overall (not QBO):** **S-ACCT-FIX-AP** (Phase A — orphan AP-payment JE resolution + dedicated AP-payments page).

---

## 11. CHANGELOG

### v1.0 (2026-05-18) — Initial progress tracker

- 28 effective sessions catalogued across 14 sub-phases (Phase QBO-1 through Phase QBO-14).
- 13 D-QBO-CORE-* decisions pre-locked from spec.
- 20 new tables planned.
- 6 new crons planned.
- 50+ new settings keys planned.
- 6 mapping tables to populate during setup phases.
- Status board: all sessions 📋 PLANNED. First row appends to Session Log table when S-QBO-1 ships.
- Q-CPA-1 through Q-CPA-7 accountant questions tracked as pre-flight items.

---

*End of FLEETFORGE_QUICKBOOKS_PROGRESS.md v1.0.*
*Update protocol: append SESSION LOG row when each S-QBO-N ships; flip status board entry from 📋/🟡/🔄 to ✅; add D-QBO-N-* decisions to §3; mark schema/cron/setting status to ✅ DONE as they land.*
