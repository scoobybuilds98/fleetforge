# FleetForge — QuickBooks Integration Progress

**Owner:** Avi (Mainland Truck & Trailer Sales)
**Companion docs:** `FLEETFORGE_QUICKBOOKS_SPEC.md` (canonical reference), `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9 (session-by-session plan), `FLEETFORGE_PROGRESS.md` (master FF progress log).
**Purpose:** Single living tracker for the QuickBooks integration arc. Every S-QBO-N session appends its row here when it ships. Decision locks land in the D-QBO-* table. Schema additions, crons, and settings tracked separately for quick lookup.

**Current arc status (as of 2026-05-20):**

🟢 **PHASE QBO-1 50% COMPLETE.** S-QBO-1 + S-QBO-2 both SHIPPED 2026-05-20. **2 of 30 sessions complete.** S-QBO-1 delivered OAuth scaffolding + Settings → QuickBooks page + QuickBooksClient skeleton + token pinger cron. S-QBO-2 delivered the HTTP boundary: get/post/put/query/getEntity/createEntity/updateEntity + getCompanyInfo (real call), 9 typed exceptions (`FleetForge\Exceptions\QuickBooks*`), retry orchestration (spec §13.2 exponential backoff), rate-limit awareness (spec §14.2 throttle), Sentry instrumentation (structured tags + extra per spec §13.5), and the canonical `acc_qbo_sync_log` table (spec §6.5 shape replacing a legacy mapping-style placeholder — D-QBO-2-1). Master sync kill-switch `quickbooks.sync_enabled='0'` remains OFF until S-QBO-30 production cutover per D-CPA-5.

**Next session up:** S-QBO-3 — Sync infrastructure tables (acc_qbo_sync_queue + acc_qbo_drift_events) + worker cron skeleton (`cron/qbo_sync_worker.php` using QuickBooksClient with $opts['no_retry']=true and next_retry_at scheduling).

---

## 1. SESSION STATUS BOARD

Legend: 📋 PLANNED | 🟡 QUEUED | 🔄 IN-PROGRESS | ✅ DONE | ⛔ BLOCKED | ❌ FAILED | ⏸ DEFERRED

### Phase QBO-1: Foundation (4 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-1 | ✅ DONE | 2026-05-20 | OAuth scaffolding, Settings → QuickBooks tab (Connection Card), token storage in settings (with NEW is_sensitive column), QuickBooksClient class skeleton (token management implemented; HTTP boundary stubs for S-QBO-2), sandbox connection verification, refresh-token pinger cron |
| S-QBO-2 | ✅ DONE | 2026-05-20 | QuickBooksClient HTTP boundary completion: GET/POST/PUT, query SQL, getEntity/createEntity/updateEntity, error classification (9 typed exceptions), retry orchestration + rate-limit throttling, Sentry instrumentation (structured), canonical §6.5 acc_qbo_sync_log shape (D-QBO-2-1 drop+recreate), minorversion '70' locked (D-QBO-2-2) |
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
| S-QBO-1 | 2026-05-20 | OAuth scaffolding, QBO settings tab, QuickBooksClient skeleton, token pinger cron (Phase QBO-1 / 1 of 4) | db_migrations/202605200500_S-QBO-1.sql, FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md, config/navigation.php, app/admin/quickbooks/{settings,dashboard,sync_log,drift,index}.php, app/admin/oauth/qbo/{init,callback}.php, api/v1/quickbooks/{disconnect,test_connection,save_credentials,save_master_controls}.php, lib/QuickBooksClient.php, cron/qbo_token_refresh.php, docs/runbooks/qbo_realm_change.md | D131 6/6 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + migrate 45/0/0) | D-QBO-1-1 (settings.is_sensitive column added — separate from is_public, controls UI masking + audit redaction), D-QBO-1-2 (sidebar nav in config/navigation.php — array-driven, K-22 file-over-prompt resolution) |
| S-QBO-2 | 2026-05-20 | QuickBooksClient HTTP boundary completion: methods + 9 typed exceptions + retry orchestration + rate-limit throttling + Sentry instrumentation + canonical §6.5 acc_qbo_sync_log (Phase QBO-1 / 2 of 4) | db_migrations/202605201900_S-QBO-2.sql, FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md, lib/QuickBooksClient.php, lib/Exceptions/QuickBooks{,AuthExpired,StaleObject,DuplicateName,Validation,Forbidden,NotFound,Transient,RateLimit}Exception.php, tests/_smoke_qbo_client.php, api/v1/quickbooks/test_connection.php (docblock only), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md | D131 7/7 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 6/6 NEW + migrate 46/0/0) | D-QBO-2-1 (legacy acc_qbo_sync_log dropped + recreated with canonical §6.5 shape this session rather than deferring to S-QBO-3), D-QBO-2-2 (QBO minorversion '70' locked + auto-appended to every request) |

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

### D-QBO-N-* (per-session, locked / anticipated)

Per-session decisions locked when each session ships (✅) or anticipated for sessions still pending (📋).

| Session | Status | Decisions |
|---|---|---|
| S-QBO-1 | ✅ LOCKED 2026-05-20 | **D-QBO-1-1** settings.is_sensitive column added (TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public; backfilled 6 existing credential rows; semantically distinct from is_public per [[feedback_trust_file_over_prompt]] resolution of pre-flight STOP). **D-QBO-1-2** sidebar nav in config/navigation.php — array-driven, rendered by includes/sidebar.php (NOT app/views/layout/sidebar.php as the prompt incorrectly referenced); QuickBooks group placed as SEPARATE top-level above Accounting per D-QBO-CORE-3 parallel-running invariant. |
| S-QBO-2 | ✅ LOCKED 2026-05-20 | **D-QBO-2-1** legacy `acc_qbo_sync_log` table dropped + recreated with canonical spec §6.5 shape in S-QBO-2 migration rather than deferring to S-QBO-3 (the pre-existing placeholder was a mapping-style table with wrong shape, 0 rows, 0 consumers per pre-flight). **D-QBO-2-2** QBO Online minorversion locked at '70' — auto-appended to every API request URL when caller doesn't override. |
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
| acc_qbo_sync_log | S-QBO-2 | ✅ DONE 2026-05-20 | API call audit log; 365-day retention. Created in S-QBO-2 (not S-QBO-3 as originally planned) — D-QBO-2-1: pre-flight surfaced a legacy mapping-style placeholder already in live DB with wrong shape; dropped + recreated with canonical §6.5 shape in same migration. |
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
| qbo_token_refresh.php | Daily 02:00 | S-QBO-1 | ✅ CODE SHIPPED 2026-05-20 (crontab install deferred to S-QBO-29 production cutover) | Refresh OAuth tokens; alert if < 14 days to expiry |
| qbo_sync_worker.php | Every 1 min | S-QBO-3 | 📋 PLANNED | Process queued push jobs |
| qbo_bank_cdc.php | Daily 02:30 | S-QBO-20 | 📋 PLANNED | Pull bank transactions from QBO (Exception #2) |
| qbo_drift_check.php | Daily 03:30 | S-QBO-24 | 📋 PLANNED | Detect and surface drift |
| qbo_webhook_replay.php | Every 15 min | S-QBO-15 | 📋 PLANNED | Reprocess stuck webhook events |
| qbo_payment_initiation_cleanup.php | Daily 04:00 | S-QBO-15 | 📋 PLANNED | Clean up stale (>24h) payment initiations |

---

## 6. SETTINGS KEYS LOG

Added in S-QBO-1 unless noted otherwise. `is_sensitive=1` flag added to settings table by S-QBO-1 migration (D-QBO-1-1) and applied to the 5 OAuth credential rows per spec §24.1.

**Connection / OAuth (S-QBO-1 ✅ SHIPPED 2026-05-20 — 15 keys):**
- quickbooks.environment (is_sensitive=0), quickbooks.client_id (=1), quickbooks.client_secret (=1), quickbooks.realm_id (=0), quickbooks.access_token (=1), quickbooks.refresh_token (=1), quickbooks.access_token_expires_at (=0), quickbooks.refresh_token_expires_at (=0), quickbooks.last_connected_at (=0), quickbooks.last_token_refresh_at (=0), quickbooks.connection_status (=0), quickbooks.connection_error (=0), quickbooks.webhook_verifier_token (=1), quickbooks.tax_override_code_id (=0), quickbooks.sandbox_redirect_uri (=0)

**Master controls (S-QBO-1 ✅ SHIPPED 2026-05-20 — default kill-switch off until S-QBO-30; 3 keys):**
- quickbooks.sync_enabled (D-CPA-5), quickbooks.payments_enabled, quickbooks.dry_run_mode

**Settings table schema change (S-QBO-1 ✅ SHIPPED 2026-05-20, D-QBO-1-1):**
- ALTER TABLE settings ADD COLUMN is_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public
- 6 existing rows backfilled to is_sensitive=1 in same migration: ai.anthropic_api_key, aws.secret_access_key, email.smtp_pass, gps.geotab_password, gps.samsara_api_key, notifications.smtp_pass

**Per-entity sync mode (S-QBO-3):**
- quickbooks.sync_mode.{customer, vendor, invoice, payment, credit_memo, refund_receipt, bill, bill_payment, journal_entry, depreciation_je, recurring_je, tax_remittance_je, year_end_closing_je}

**Drift tolerance (S-QBO-4):**
- quickbooks.drift_tolerance.{customer, vendor, invoice, payment, credit_memo, bill, gl_account}

**Retry and rate limiting (S-QBO-2 ✅ SHIPPED 2026-05-20 — 4 keys):**
- quickbooks.retry.max_attempts ('5'), quickbooks.retry.backoff_base_seconds ('60'), quickbooks.rate_limit.throttle_threshold ('10'), quickbooks.rate_limit.throttle_seconds ('30') — all integer + is_sensitive=0. Live count: 22 quickbooks.* rows (18 from S-QBO-1 + 4 from S-QBO-2).

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
