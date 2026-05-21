# FleetForge — QuickBooks Integration Progress

**Owner:** Avi (Mainland Truck & Trailer Sales)
**Companion docs:** `FLEETFORGE_QUICKBOOKS_SPEC.md` (canonical reference), `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9 (session-by-session plan), `FLEETFORGE_PROGRESS.md` (master FF progress log).
**Purpose:** Single living tracker for the QuickBooks integration arc. Every S-QBO-N session appends its row here when it ships. Decision locks land in the D-QBO-* table. Schema additions, crons, and settings tracked separately for quick lookup.

**Current arc status (as of 2026-05-20):**

🟢 **PHASE QBO-1 COMPLETE + Phase QBO-2/3/4 in progress — 8 of 30 sessions shipped (4 Phase QBO-1 + 2 Phase QBO-2 + 1 Phase QBO-3 + 1 Phase QBO-4 of 3; +2 debt-paydowns).** Phase QBO-1: S-QBO-1 (OAuth foundation) + S-QBO-2 (HTTP boundary) + S-QBO-3 (sync infrastructure) + S-QBO-4 (admin UI). Phase QBO-2 (customers): **S-QBO-5** + **S-QBO-6** shipped full pull → mapping → push arc. Phase QBO-3 (vendors): **S-QBO-7** shipped the entire vendor arc in one session per the locked §6.8/§6.9 contracts. Phase QBO-4 (reference data): **S-QBO-8 (2026-05-21)** shipped first of 3 — chart of accounts mapping (Puller-only per D-QBO-8-1; accountant owns QBO COA structure), AccountPuller + AccountMatcher (account_code-priority cascade with type-compatibility gating per D-QBO-8-3) + AccountValidator (bridge-account heuristic + `assertReadyForInvoicePush` gate for future S-QBO-11) + new `ChartOfAccountsIncompleteException` + type-grouped UI with critical-unmapped banner; 7 live FF bridge accounts identified (codes 1030/1050/1060/2010/2030/2040/4122). **4 decisions locked S-QBO-8**: D-QBO-8-1 (Puller-only — no AccountPusher/AccountEnqueuer/sync_mode.account), D-QBO-8-2 (bridge-account validator + assertReadyForInvoicePush gate), D-QBO-8-3 (cascade respects type compatibility — no cross-type matches), D-QBO-8-4 (is_critical column independent of mapping_status). Master sync kill-switch `quickbooks.sync_enabled='0'` remains OFF until S-QBO-30 cutover per D-CPA-5. **Debt-paydowns**: S-QBO-OAUTH-FIX (K-22 Trap #59) + S-QBO-5-FIX-1 (K-22 Trap #60).

**Next session up:** S-QBO-9 — second Phase QBO-4 session. Tax Code mapping: pull QBO tax codes via QuickBooksClient::query, identify the 'NON' override target (QBO's no-tax-computed code used with TxnTaxDetail.TotalTax to push FF-computed tax without QBO recalculation per D-QBO-CORE-6), populate `acc_qbo_tax_code_map`, NS HST 14%/15% effective-date handling. Smaller scope than accounts — tax codes typically number ~10-20 in a QBO sandbox.

---

## 1. SESSION STATUS BOARD

Legend: 📋 PLANNED | 🟡 QUEUED | 🔄 IN-PROGRESS | ✅ DONE | ⛔ BLOCKED | ❌ FAILED | ⏸ DEFERRED

### Phase QBO-1: Foundation (4 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-1 | ✅ DONE | 2026-05-20 | OAuth scaffolding, Settings → QuickBooks tab (Connection Card), token storage in settings (with NEW is_sensitive column), QuickBooksClient class skeleton (token management implemented; HTTP boundary stubs for S-QBO-2), sandbox connection verification, refresh-token pinger cron |
| S-QBO-2 | ✅ DONE | 2026-05-20 | QuickBooksClient HTTP boundary completion: GET/POST/PUT, query SQL, getEntity/createEntity/updateEntity, error classification (9 typed exceptions), retry orchestration + rate-limit throttling, Sentry instrumentation (structured), canonical §6.5 acc_qbo_sync_log shape (D-QBO-2-1 drop+recreate), minorversion '70' locked (D-QBO-2-2) |
| S-QBO-3 | ✅ DONE | 2026-05-20 | Sync infrastructure foundation: acc_qbo_sync_queue + acc_qbo_drift_events tables (sync_log already created S-QBO-2 D-QBO-2-1), 13 sync_mode settings, QboPusherDispatcher (convention-based registry-less), QuickBooksSync facade, worker cron skeleton with kill-switch + dry-run + retry + drift event + pusher_not_implemented suppression. D-QBO-3-1 / 3-2 / 3-3 locked. |
| S-QBO-4 | ✅ DONE | 2026-05-20 | QBO admin UI buildout — Dashboard (KPI cards + 14d activity chart + recent feed + quick actions), Sync Queue (NEW page with retry/cancel/clear), Sync Log (search/filter/detail with view_raw_payloads gating), Drift Detection (4 summary cards + resolution flow with required note), nav update +1 child, 9 API endpoints, empty-state safe across all 4 pages. D-QBO-4-1 / 4-2 / 4-3 locked. PHASE QBO-1 COMPLETE. |
| S-QBO-OAUTH-FIX | ✅ DONE | 2026-05-20 | DB-backed OAuth state tokens + auth-context-free callback — `acc_oauth_states` (10-min TTL, single-use), `FleetForge\OAuth\StateManager`. Removes session dependency from callback.php (state token IS the auth — K-22 Trap #59 architectural fix). D-QBO-OAUTH-FIX-1/-2/-3/-4 locked. Debt-paydown (does not advance Phase QBO count). |

### Phase QBO-2: Customers (2 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-5 | ✅ DONE | 2026-05-20 | Customer mapping flow — acc_qbo_customer_map (state machine: mapped/ff_only/qbo_only/ignored), CustomerPuller (paginated /query pull), CustomerMatcher (normalize → exact → Levenshtein ≤ 3 → email → phone last-7 cascade), 4 API endpoints (pull/auto_match/save_mapping/list), Alpine.js Customers Sync UI with KPI tiles + per-row actions, nav +1 child (between Drift and Settings). D-QBO-5 / D-QBO-5-1 / D-QBO-5-2 locked. PHASE QBO-2 BEGUN. |
| S-QBO-6 | ✅ DONE | 2026-05-21 | Customer push (FF→QBO) — CustomerPusher (pushCreate + pushUpdate, dispatcher-aligned per D-QBO-3-2) + CustomerEnqueuer (best-effort queue insert from customer API endpoints) + UI mapping badge on customer show page + 10/10 push smoke. D-QBO-6-1/2/3/4/5 locked. First session creating data IN QBO; sync_enabled stays '0' per D-CPA-5 (operator flips temporarily for live verification). |

### Phase QBO-3: Vendors (1 session)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-7 | ✅ DONE | 2026-05-21 | Vendor mapping + push (combined session) — VendorPuller + VendorMatcher + VendorPusher + VendorEnqueuer (all under `FleetForge\QboPushers`) + 4 API endpoints (pull/auto_match/save_mapping/list) + Vendors Sync Alpine.js UI mirroring customers.php + vendor detail page mapping badge + sidebar nav +1 child (Vendors between Customers and Settings) + acc_qbo_vendor_map state-machine schema (mirrors customer_map with qbo_balance REMOVED + qbo_given_name + qbo_family_name + qbo_v4v_status ADDED). 2 new smokes (vendor_mapping 12/12 + vendor_push 10/10; D131 12→14). D-QBO-7-1/2/3/4 locked (1099 out of scope v1; vendor_type NOT mapped to QBO; contact_name split on FIRST space; state-machine pattern lifted from D-QBO-5). First session per the locked §6.8 Pusher Contract + §6.9 Enqueuer Contract — entire pull-match-push arc lands in one ship. sync_enabled stays '0' per D-CPA-5 (operator flips temporarily for live verification). |

### Phase QBO-4: Reference Data Mapping (3 sessions)

| ID | Status | Date shipped | Description |
|---|---|---|---|
| S-QBO-8 | ✅ DONE | 2026-05-21 | Chart of Accounts mapping (Puller-only per D-QBO-8-1) — `FleetForge\QboPushers\AccountPuller` (paginated /query Account entity) + `AccountMatcher` (cascade: exact_code → exact_name+type-compatible → Levenshtein+type → subtype+token → singleton-type) + `AccountValidator` (bridge-account heuristic + `assertReadyForInvoicePush` gate for future S-QBO-11 invoice push) + new `FleetForge\Exceptions\ChartOfAccountsIncompleteException` extending QuickBooksException + 4 API endpoints (pull/auto_match/save_mapping with mark_critical+unmark_critical actions/list with critical_only filter) + type-grouped Alpine UI with red banner for unmapped bridge accounts + sidebar nav +1 child (Accounts between Vendors and Settings, book-open icon). acc_qbo_account_map state-machine schema (22 cols including is_critical + critical_reason + idx_critical lookup index). 7 live FF bridge accounts identified (codes 1030 AR / 1050+1060 tax receivable / 2010 AP / 2030+2040 tax payable / 4122 sales revenue). 1 new smoke (account_mapping 17/17; D131 14→15). D-QBO-8-1/2/3/4 locked. sync_enabled stays '0' per D-CPA-5; Puller-only direction so no live QBO writes. |
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
| S-QBO-3 | 2026-05-20 | Sync infrastructure foundation: 2 tables + 13 sync_mode settings + dispatcher + facade + worker cron with kill-switch + dry-run + retry + drift + pusher_not_implemented suppression (Phase QBO-1 / 3 of 4) | db_migrations/202605202100_S-QBO-3.sql, FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md, lib/QboPusherDispatcher.php, lib/QuickBooksSync.php, lib/Exceptions/PusherNotImplementedException.php, cron/qbo_sync_worker.php, tests/_smoke_qbo_queue.php, lib/Notifications/NotificationService.php (TYPE map only), docs/FLEETFORGE_QUICKBOOKS_SPEC.md (§6.7 rewrite), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md | D131 8/8 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 6/6 + qbo_queue 9/9 NEW + migrate 47/0/0) | D-QBO-3-1 (worker behavior extensions: kill-switch + dry-run + sync_mode.off + drift_events + pusher_not_implemented suppression — supersedes original §6.7 sketch), D-QBO-3-2 (Pusher convention: {EntityType}Pusher + pushCreate/Update/Void/Delete; dispatcher checks FleetForge\\QboPushers\\<Name> first then FleetForge\\<Name> fallback), D-QBO-3-3 (notification audience via explicit specificUserIds workaround until user_permissions seeded for 'quickbooks' module) |
| S-QBO-4 | 2026-05-20 | QBO admin UI: Dashboard + Sync Queue (NEW) + Sync Log + Drift + 9 API endpoints; empty-state safe across all 4 pages; nav +1 child; spec §15.1/§19.3/§19.4 path reconciliation (Phase QBO-1 / 4 of 4 — PHASE QBO-1 COMPLETE) | config/navigation.php, app/admin/quickbooks/{dashboard,sync_queue,sync_log,drift}.php, api/v1/quickbooks/{dashboard_metrics,refresh_token_now,sync_queue_{list,retry,clear},sync_log_{search,detail},drift_{list,resolve}}.php, tests/_smoke_qbo_admin_ui.php, docs/FLEETFORGE_QUICKBOOKS_SPEC.md (§15.1+§19.3+§19.4), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 8→9), docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_PROGRESS.md | D131 9/9 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 6/6 + qbo_queue 9/9 + qbo_admin_ui 8/8 NEW + migrate 47/0/0) | D-QBO-4-1 (queue completed-row retention 7d), D-QBO-4-2 (queue failed-row retention 30d), D-QBO-4-3 (spec §15.1+§19.3+§19.4 path reconciliation — drift/sync-log flat-paths matching D-QBO-CLIENT-LOCATION; §19.5+§19.6 deferred to future Pusher / manual-sync sessions) |
| S-QBO-OAUTH-FIX | 2026-05-20 | DB-backed OAuth state tokens (acc_oauth_states + FleetForge\OAuth\StateManager) replace `$_SESSION['qbo_oauth_state']`; callback runs auth-context-free with audit attribution preserved via initiated_by_user_id captured at init time. Closes K-22 Trap #59 architecturally (ngrok cross-origin session cookie failure). Debt-paydown session — does not advance Phase QBO count (still 4/30). | db_migrations/202605202200_S-QBO-OAUTH-FIX.sql, FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md, lib/OAuth/StateManager.php (NEW), app/admin/oauth/qbo/{init,callback}.php, tests/_smoke_oauth_state.php (NEW), docs/FLEETFORGE_QUICKBOOKS_SPEC.md (§5.1 rewrite + §5.1.1 NEW), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 9→10), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 10/10 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 6/6 + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 NEW + migrate 48/0/0) | D-QBO-OAUTH-FIX-1 (FleetForge\OAuth namespace at lib/OAuth/StateManager.php), D-QBO-OAUTH-FIX-2 (callback auth-context-free per OAuth 2.0; state token IS the auth proof; init retains require_auth), D-QBO-OAUTH-FIX-3 (600s TTL + atomic single-use via UPDATE-with-condition + 24h forensic cleanup buffer; raw INSERT with DATE_ADD(NOW(), INTERVAL ? SECOND) to avoid PHP/MySQL timezone clock divergence — K-22 catch worth Trap #60 in next docs-lock), D-QBO-OAUTH-FIX-4 (initiated_by_user_id captured at init under require_auth + recovered at callback for audit attribution via ff_qbo_lookup_user_name helper; FK ON DELETE SET NULL handles user-deleted-mid-flow) |
| S-QBO-5 | 2026-05-20 | First Pusher session — `acc_qbo_customer_map` (state machine: mapped/ff_only/qbo_only/ignored), `FleetForge\QboPushers\CustomerPuller` (paginated /query pull), `FleetForge\QboPushers\CustomerMatcher` (normalize → exact name → Levenshtein ≤ 3 → email → phone last-7 cascade), 4 API endpoints, Customers Sync UI, Auto-Match algorithm, nav +1 child. **Phase QBO-2 / 1 of N — Phase QBO-2 BEGUN.** | db_migrations/202605202300_S-QBO-5.sql (NEW), FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md, lib/QboPushers/{CustomerPuller,CustomerMatcher}.php (2 NEW), api/v1/quickbooks/customers/{pull,auto_match,save_mapping,list}.php (4 NEW), app/admin/quickbooks/customers.php (NEW), tests/_smoke_qbo_customer_mapping.php (NEW), config/navigation.php (+1 child), docs/FLEETFORGE_QUICKBOOKS_SPEC.md (§7.4 rewrite), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 10→11), tests/_smoke_qbo_admin_ui.php (C4 nav children 5→6), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 11/11 PASS (PARITY OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 6/6 + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 + qbo_customer_mapping 12/12 NEW + migrate 49/0/0) | D-QBO-5 (acc_qbo_customer_map state-machine extension — mapping_status ENUM(mapped/ff_only/qbo_only/ignored) replaces spec §7.4 mapped-only assumption; UNIQUE on nullable ff/qbo ids permits many single-sided rows via InnoDB multi-NULL semantics; spec §7.4 rewritten in same commit; realm_id intentionally omitted — single-realm scope), D-QBO-5-1 (FleetForge\QboPushers namespace shared by both Pushers AND Pullers at lib/QboPushers/ — single namespace keeps dispatcher lookup simple; first exemplars: CustomerPuller + CustomerMatcher), D-QBO-5-2 (auto-match cascade: normalize → exact name → Levenshtein ≤ 3 with max-side-≥5 gate → email case-insensitive → phone last-7 digits → unmatched; manual overrides preserved across re-runs; LEVENSHTEIN_MIN_LENGTH=5/MAX_DISTANCE=3, PHONE_MIN_DIGITS=7) |
| S-QBO-5-FIX-1 | 2026-05-21 | Foundation cleanup before S-QBO-6 — centralize QBO `QueryResponse` 1-row-object → array normalization at `QuickBooksClient::query()` boundary; K-22 Trap #60 locked. Closes a trap class that would have replicated across every future Pusher (Vendor/Invoice/Payment/Bill/JournalEntry/etc.) if left as per-Pusher defensive wraps. Debt-paydown (does not advance Phase QBO count). | lib/QuickBooksClient.php (query() + normalizeQueryResponse() added), lib/QboPushers/CustomerPuller.php (defensive wrap deleted), tests/_smoke_qbo_client.php (C7 + total=7), docs/FLEETFORGE_QUICKBOOKS_SPEC.md (§6.6 normalization contract + §29 v1.0.1 changelog), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (K-22 Trap #60), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 11/11 PASS (parity OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 7/7 (was 6/6) + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 + qbo_customer_mapping 12/12 + migrate 49/0/0) | D-QBO-5-FIX-1-1 (normalization at query() boundary not per-Pusher; exposed as public static normalizeQueryResponse for offline smoke access), D-QBO-5-FIX-1-2 (preserve full QueryResponse envelope including pagination metadata; ctype_upper-keyed-fields-only heuristic), D-QBO-5-FIX-1-3 (CustomerPuller defensive wrap DELETED not commented per D-TRAP-CATALOG-SWEEP) |
| S-QBO-6 | 2026-05-21 | First session pushing data INTO QBO — CustomerPusher (pushCreate + pushUpdate, dispatcher-aligned) + CustomerEnqueuer (best-effort queue insert from customer API endpoints) + customer API integration (create.php + update.php) + UI mapping badge on customer show page; 10/10 push smoke; D-QBO-6-1/2/3/4/5 locked. Phase QBO-2 / 2 of N. sync_enabled stays '0' per D-CPA-5 — operator flips temporarily for live verification only. | lib/QboPushers/CustomerPusher.php (NEW), lib/QboPushers/CustomerEnqueuer.php (NEW), api/v1/customers/{create,update}.php (+enqueue call), app/admin/customers/show.php (+QBO badge), tests/_smoke_qbo_customer_push.php (NEW), tests/_smoke_qbo_queue.php (C8 + C9 updates), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 11→12), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 12/12 PASS (parity OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 7/7 + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 + qbo_customer_mapping 12/12 + qbo_customer_push 10/10 NEW + migrate 49/0/0) | D-QBO-6-1 (no delete enqueue), D-QBO-6-2 (idempotency on pushCreate), D-QBO-6-3 (enqueue at API endpoint not DB-trigger/model-hook), D-QBO-6-4 (sync mode gating: enforced at BOTH enqueue + dispatch), D-QBO-6-5 (field mapping: company_name → DisplayName+CompanyName, email/phone, BillAddr from address+city+province+postal_code+country) |
| S-QBO-8 | 2026-05-21 | **Phase QBO-4 / 1 of 3 — chart of accounts mapping (Puller-only per D-QBO-8-1).** acc_qbo_account_map state-machine schema (22 cols with is_critical + critical_reason + idx_critical lookup index) + `FleetForge\QboPushers\AccountPuller` (paginated /query Account entity) + `AccountMatcher` (cascade: exact_code → exact_name+type → Levenshtein+type → subtype+token → singleton-type per D-QBO-8-3) + `AccountValidator` (bridge-account heuristic + `assertReadyForInvoicePush` gate) + new `FleetForge\Exceptions\ChartOfAccountsIncompleteException` extending QuickBooksException + 4 API endpoints (pull/auto_match/save_mapping with mark_critical+unmark_critical actions/list with critical_only filter) + type-grouped Alpine UI with red banner for unmapped bridge accounts. **4 decisions locked**: D-QBO-8-1/2/3/4. **1 new smoke** (account_mapping 17/17; D131 grows 14→15). | db_migrations/202605210757_S-QBO-8.sql (NEW), FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md (regen 135→136), lib/QboPushers/{AccountPuller,AccountMatcher,AccountValidator}.php (3 NEW), lib/Exceptions/ChartOfAccountsIncompleteException.php (NEW), api/v1/quickbooks/accounts/{pull,auto_match,save_mapping,list}.php (4 NEW), app/admin/quickbooks/accounts.php (NEW), tests/_smoke_qbo_account_mapping.php (NEW), config/navigation.php (+1 child Accounts), includes/partials/quickbooks-nav.php (+1 entry), tests/_smoke_qbo_admin_ui.php (C4 nav 7→8), tests/_smoke_qbo_customer_mapping.php (C11 nav 7→8), tests/_smoke_qbo_vendor_mapping.php (C11 nav 7→8), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 14→15), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 15/15 PASS (parity OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 7/7 + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 + qbo_customer_mapping 12/12 + qbo_customer_push 10/10 + qbo_vendor_mapping 12/12 + qbo_vendor_push 10/10 + qbo_account_mapping 17/17 NEW + migrate 51/0/0) | D-QBO-8-1 (Puller-only — no AccountPusher/AccountEnqueuer/sync_mode.account; accountant owns COA structure in QBO), D-QBO-8-2 (bridge-account validator with is_critical column independent of mapping_status; assertReadyForInvoicePush gate fires from future S-QBO-11 invoice push), D-QBO-8-3 (auto-match cascade — exact_code first as highest signal; type-compatibility GATING on every name-based pass; cross-type matches forbidden), D-QBO-8-4 (acc_qbo_account_map state-machine schema mirrors customer/vendor state machines with is_critical + critical_reason added; idx_critical multi-column index for validator lookup) |
| S-QBO-7 | 2026-05-21 | **Phase QBO-3 / 1 of 1 — combined vendor mapping + push session per locked §6.8 + §6.9 contracts.** acc_qbo_vendor_map (21-col state-machine schema mirroring customer_map, qbo_balance REMOVED + qbo_given/family_name + qbo_v4v_status ADDED) + `FleetForge\QboPushers\VendorPuller` (paginated /query) + `VendorMatcher` (4-pass cascade reused from CustomerMatcher with `vendors.name` swap) + `VendorPusher` (pushCreate/Update/Impl/buildQboPayload per §6.8) + `VendorEnqueuer` (4-gate filter per §6.9) + 4 API endpoints + Vendors Sync Alpine UI + vendor detail mapping badge + nav +1 child. **2 new smokes** (mapping 12/12 + push 10/10; D131 grows 12→14). | db_migrations/202605210233_S-QBO-7.sql (NEW), FLEETFORGE_DATABASE_MASTER.sql, docs/FLEETFORGE_SCHEMA_QUICK_REF.md (regen 134→135), lib/QboPushers/{VendorPuller,VendorMatcher,VendorPusher,VendorEnqueuer}.php (4 NEW), api/v1/quickbooks/vendors/{pull,auto_match,save_mapping,list}.php (4 NEW), app/admin/quickbooks/vendors.php (NEW), tests/_smoke_qbo_vendor_mapping.php (NEW), tests/_smoke_qbo_vendor_push.php (NEW), api/v1/vendors/{create,update}.php (+enqueue call), app/admin/vendors/show.php (+QBO badge), config/navigation.php (+1 child Vendors), includes/partials/quickbooks-nav.php (+1 entry), tests/_smoke_qbo_admin_ui.php (C4 nav 6→7), tests/_smoke_qbo_customer_mapping.php (C11 nav 6→7), tests/_smoke_qbo_queue.php (C8 hasImplementation list), docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md (D131 12→14), docs/FLEETFORGE_PROGRESS.md, docs/FLEETFORGE_CURRENT_SESSIONS.md, docs/FLEETFORGE_QUICKBOOKS_PROGRESS.md | D131 14/14 PASS (parity OK + I1-I10 + samsara 16/16 + model_b 20/20 + doc_freshness 17/17 + qbo_client 7/7 + qbo_queue 9/9 + qbo_admin_ui 8/8 + oauth_state 9/9 + qbo_customer_mapping 12/12 + qbo_customer_push 10/10 + qbo_vendor_mapping 12/12 NEW + qbo_vendor_push 10/10 NEW + migrate 50/0/0) | D-QBO-7-1 (1099/V4V out of scope v1; qbo_v4v_status snapshot only; revisit S-QBO-18 Bills), D-QBO-7-2 (vendor_type ENUM NOT mapped to QBO — no clean Vendor analog), D-QBO-7-3 (vendors.contact_name → split on FIRST space into GivenName + FamilyName; no space → all to GivenName, FamilyName empty), D-QBO-7-4 (acc_qbo_vendor_map state-machine schema mirrors acc_qbo_customer_map per D-QBO-5) |

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
| S-QBO-4 | ✅ LOCKED 2026-05-20 | **D-QBO-4-1** acc_qbo_sync_queue completed-row retention 7d (bulk Clear Completed deletes completed_at < NOW() - 7d). **D-QBO-4-2** failed-row retention 30d (4× completed; forensic value). **D-QBO-4-3** spec §15.1+§19.3+§19.4 admin-path subdirectory→flat reconciliation (drift.php, sync_log.php); §19.5+§19.6 paths intentionally deferred to future Pusher/manual-sync sessions. |
| S-QBO-3 | ✅ LOCKED 2026-05-20 | **D-QBO-3-1** spec §6.7 worker pseudocode supersession — live cron extends original 4-step sketch with kill-switch + dry-run + sync_mode.off skip + drift_events insertion on permanent failure + pusher_not_implemented notification + drift suppression. **D-QBO-3-2** Pusher convention — `{EntityType}Pusher` class (snake→PascalCase) with static `pushCreate`/`pushUpdate`/`pushVoid`/`pushDelete` methods; dispatcher checks `FleetForge\QboPushers\<Name>` first then `FleetForge\<Name>` fallback; registry-less convention-based lookup. **D-QBO-3-3** QBO failure notifications use explicit `$specificUserIds` (super_admin + accountant resolved from `users JOIN user_roles`) — temporary workaround until `user_permissions` table is seeded with 'quickbooks' module rows; TYPE_TO_MODULE/CATEGORY entries added for future-proofing. |
| S-QBO-5 | Customer name collision resolution, fuzzy-match threshold during initial mapping, deactivated-customer handling |
| S-QBO-8 | ✅ LOCKED 2026-05-21 | **D-QBO-8-1** Puller-only pattern — NO AccountPusher, NO AccountEnqueuer, NO sync_mode.account setting; FF does not push accounts to QBO; accountant owns COA structure in QBO; FF mirrors via mapping table; operator re-pulls when accountant adds accounts in QBO. **D-QBO-8-2** Bridge-account validator — `is_critical` column in acc_qbo_account_map flags accounts required for downstream invoice push (S-QBO-11) + JE push (S-QBO-21); heuristic identifies AR/AP/tax-payable/tax-receivable/sales-revenue accounts via FF code-pattern + is_system match; `AccountValidator::assertReadyForInvoicePush()` throws `ChartOfAccountsIncompleteException` if any critical unmapped (pre-flight gate for future Pushers); idx_critical multi-column index for fast lookup. **D-QBO-8-3** Auto-match cascade respects acct_type compatibility — `TYPE_COMPATIBILITY` map enforces FF account_type → QBO AccountType pairs (asset → Bank/OtherCurrentAsset/FixedAsset/OtherAsset/AccountsReceivable; revenue → Income; cost_of_revenue → Cost of Goods Sold; operating_expense → Expense; etc.); cascade order = exact_code (AcctNum match, type-gating skipped because codes are operator-aligned) → exact_name+type → Levenshtein+type → subtype+token → singleton-type; cross-type matches NEVER promoted to mapped. **D-QBO-8-4** State-machine schema parity — acc_qbo_account_map carries the same 4-state mapping_status ENUM as customer/vendor maps with two additions: is_critical (independent of mapping_status — operator can flag a critical account whether or not it's currently mapped) + critical_reason (free-text). FF table is `acc_accounts` (not `chart_of_accounts` as prompt assumed); FF columns are `code`/`name`/`account_type`/`account_subtype` (not `account_code`/`acct_type`/`acct_subtype`); FF uses `is_active` (not `deleted_at`) — all K-22 catches surfaced at pre-flight. |
| S-QBO-7 | ✅ LOCKED 2026-05-21 | **D-QBO-7-1** 1099/V4V vendor handling out of scope v1 — `vendors.is_1099` column absent on disk; `acc_qbo_vendor_map.qbo_v4v_status varchar(20)` captures QBO V4VStatus as informational snapshot only, NOT routed in buildQboPayload; revisit in S-QBO-18 Bills when AP/1099-NEC pipeline lands. **D-QBO-7-2** `vendors.vendor_type` ENUM NOT mapped to QBO Vendor — no clean analog (QBO VendorTypeRef deprecated in v3); FF retains as internal classification only; buildQboPayload deliberately omits. **D-QBO-7-3** `vendors.contact_name` → QBO `GivenName` + `FamilyName` split on FIRST space; "John A. Smith" → ('John','A. Smith'); "Cher" → ('Cher',''); empty contact_name → both keys absent from payload. **D-QBO-7-4** `acc_qbo_vendor_map` state-machine schema lifted verbatim from `acc_qbo_customer_map` per D-QBO-5 (same 4-state lifecycle, UNIQUE-on-nullable-FK pattern, snapshot-fields-on-pull) with vendor-specific deltas: qbo_balance REMOVED (vendors have AP not AR), qbo_given_name + qbo_family_name + qbo_v4v_status ADDED. |
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
| acc_qbo_sync_queue | S-QBO-3 | ✅ DONE 2026-05-20 | Push queue per spec §6.4 verbatim. 16 cols + 3 indexes (idx_status_priority, idx_entity, idx_retry). Worker batch-claims 10 items/run under FOR UPDATE. |
| acc_qbo_sync_log | S-QBO-2 | ✅ DONE 2026-05-20 | API call audit log; 365-day retention. Created in S-QBO-2 (not S-QBO-3 as originally planned) — D-QBO-2-1: pre-flight surfaced a legacy mapping-style placeholder already in live DB with wrong shape; dropped + recreated with canonical §6.5 shape in same migration. |
| acc_qbo_drift_events | S-QBO-3 | ✅ DONE 2026-05-20 | Drift detection events. Created in S-QBO-3 (not S-QBO-4 as originally planned) — moved up alongside sync_queue so the worker can insert push_failed rows on permanent failure (D-QBO-3-1). 17 cols + 4 indexes + 2 FKs (queue_id→sync_queue SET NULL, resolved_by_user_id→users SET NULL). Categories: count_mismatch / field_mismatch / missing_in_qbo / missing_in_ff / amount_drift / balance_drift / push_failed / pull_failed / stale_object_unresolved. S-QBO-24 will add the drift-check cron that performs per-entity comparison. |
| acc_qbo_account_map | S-QBO-8 | 📋 PLANNED | COA mapping |
| acc_qbo_tax_code_map | S-QBO-9 | 📋 PLANNED | Tax code mapping |
| acc_qbo_item_map | S-QBO-10 | 📋 PLANNED | Line item type mapping |
| acc_qbo_customer_map | S-QBO-5 | ✅ DONE 2026-05-20 | Customer mapping (extended state-machine schema per D-QBO-5; spec §7.4 rewritten) |
| acc_qbo_vendor_map | S-QBO-7 | ✅ DONE 2026-05-21 | Vendor mapping (21 cols + 2 UNIQUE on nullable ff_vendor_id/qbo_vendor_id + state machine ENUM(mapped/ff_only/qbo_only/ignored) + qbo_v4v_status snapshot) |
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
| qbo_sync_worker.php | Every 1 min | S-QBO-3 | ✅ CODE SHIPPED 2026-05-20 (crontab install deferred to S-QBO-30 production cutover) | Process queued push jobs. Kill-switch at top (sync_enabled='0' → exit silently); dry-run mode; per-entity sync_mode.off skip; FOR UPDATE batch claim (10/run); QboPusherDispatcher dispatch with try/catch on PusherNotImplementedException + QuickBooksTransientException (exponential backoff retry) + QuickBooksException (permanent) + Throwable (Sentry capture); drift_events insert on permanent; pusher_not_implemented notification + drift suppressed. |
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

**Per-entity sync mode (S-QBO-3 ✅ SHIPPED 2026-05-20 — 13 keys):**
- 6 default `sync`: quickbooks.sync_mode.{customer, vendor, invoice, payment, credit_memo, refund_receipt}
- 5 default `queue`: quickbooks.sync_mode.{bill, bill_payment, depreciation_je, recurring_je}
- 4 sync_mode lookups on JEs: quickbooks.sync_mode.{journal_entry (sync), tax_remittance_je (sync), year_end_closing_je (sync), depreciation_je (queue), recurring_je (queue)}
- Live count: 35 quickbooks.* rows (18 from S-QBO-1 + 4 from S-QBO-2 + 13 from S-QBO-3).

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
| Customers | All non-deleted customers (~varies) | 0 mapped (table created S-QBO-5; mapping rows populated on operator-initiated Pull + Auto-Match) | S-QBO-5 ✅ DONE 2026-05-20 |
| Vendors | All non-deleted vendors (~varies) | 0 mapped (UI live; operator runs Pull during live verification) | S-QBO-7 (DONE 2026-05-21) |
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
