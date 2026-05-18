# FleetForge — QuickBooks Online Integration Specification

**Version:** 1.0
**Date:** 2026-05-18
**Status:** PRE-IMPLEMENTATION (Phase QBO not yet started)
**Owner:** Avi (Mainland Truck & Trailer Sales)
**Companion docs:** `FLEETFORGE_ACCOUNTING_SPEC.md` v1.3; `FLEETFORGE_SPEC_FINAL.md`; `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`; `FLEETFORGE_PROGRESS.md`; `FLEETFORGE_CURRENT_SESSIONS.md`; `FLEETFORGE_DATABASE_MASTER.sql`
**Implementation arc:** Phase QBO sessions S-QBO-1 through S-QBO-30 (28 effective sessions; see `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9).

This is the canonical reference document for the FleetForge ↔ QuickBooks Online integration. Every QBO session reads this document first. Decisions locked here are normative — sessions that need to deviate must lock new D-QBO-* decisions and update this spec.

---

## TABLE OF CONTENTS

1. Why this exists — the operational case
2. Architecture: master-mirror with three exceptions
3. Workflow shifts for operators and accountants
4. Locked decisions
5. OAuth and credential management
6. Sync infrastructure
7. Mapping tables
8. Per-entity sync rules
9. Tax handling and place-of-supply
10. Multi-currency
11. QBO Payments embed in customer portal
12. Webhooks
13. Error handling and retry policy
14. Rate limits
15. Drift detection and reconciliation
16. Historical backfill (S-QBO-27)
17. Production cutover runbook
18. CRA defensibility
19. UI surfaces
20. Cron jobs
21. Schema additions
22. Edge cases and how we handle them
23. Security and compliance
24. Settings keys
25. Notifications
26. Permissions and roles
27. Open questions
28. Glossary
29. Changelog

---

## 1. WHY THIS EXISTS — THE OPERATIONAL CASE

Mainland Truck & Trailer Sales already uses QuickBooks Online (Plus tier) for accounting. The external accountant works in QBO. FleetForge has built its own internal accounting module (S028–S035 shipped) which is fully functional and ASPE-grade-targeted.

The question this spec answers: how do these two systems coexist permanently, with one being the source of truth and the other being a current mirror, without either drifting from the other and without the accountant having to copy-paste data between them?

**The pain point this eliminates:** today's workflow has the accountant manually copying data from spreadsheets into QBO, which is brittle, slow, and error-prone. After this integration is live, that copy-paste step is gone entirely. The accountant works in FleetForge for everything operational and QuickBooks shows the result automatically.

**Why not just deprecate FleetForge's accounting module and use QBO directly?** Three reasons:

1. **Operational lock-in to FleetForge.** Mainland's fleet operations live in FleetForge — leases, customers, equipment, invoices, mileage tracking, GPS integration. Posting JEs from those events to a *remote* system (QBO) is fragile and creates an external dependency for every billing tick.
2. **CRA defensibility.** Having a complete in-house GL with full audit trail, immutable sent invoices (D14), bcmath-only monetary math (D16), and source-document attachments (§20.3 of accounting spec) is the strongest CRA defense. QBO as a single source of truth is fine for many businesses but loses the deep linkage to operational data.
3. **Accountant familiarity preserved.** Your accountant still sees QBO with all the data they're used to — they don't have to learn a new system to do their job. They just stop entering data manually because FF feeds it.

**Why not a true bidirectional sync?** Two reasons:

1. **Conflict resolution is hard.** True bidirectional sync requires answering "what if both sides change the same record?" at every entity. The implementation surface explodes.
2. **The accountant's edits in QBO that aren't already in FF should be rare.** Once the workflow shifts to FF-first, the accountant only edits in QBO for the three documented exceptions (bank reconciliation, payment processing, GST filing).

So: **FleetForge is canonical, QBO is mirror, three exceptions documented, no conflict resolution needed for the 95% case.**

---

## 2. ARCHITECTURE: MASTER-MIRROR WITH THREE EXCEPTIONS

### 2.1 The data flow diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        FLEETFORGE (canonical)                        │
│                                                                      │
│  Operational data            Accounting layer                        │
│  ─────────────────           ──────────────────                      │
│  Customers                                                           │
│  Equipment units                                                     │
│  Leases (operating + capital)                                        │
│  Invoices (FF generates) ─► AutoEntryBridge ─► FF GL JE              │
│  Payments (FF records)   ─► AutoEntryBridge ─► FF GL JE              │
│  Credit notes             ─► AutoEntryBridge ─► FF GL JE              │
│  Bills (FF AP module)     ─► AutoEntryBridge ─► FF GL JE              │
│  Bank reconciliations                                                │
│  Fixed assets             ─► FixedAssetService ─► FF GL JE           │
│  Tax filings              ─► TaxFilingService ─► FF GL JE            │
│  Manual JEs               ─► JournalEntryService ─► FF GL            │
│  Recurring JEs            ─► Cron ─► FF GL                            │
│  Year-end closing         ─► YE workflow ─► FF GL                     │
│                                  │                                   │
│                                  │ QBO sync layer (push)             │
│                                  │ • Queued or synchronous           │
│                                  │ • Per-entity rules                │
│                                  │ • Idempotent via mapping tables   │
│                                  ▼                                   │
└──────────────────────────────────┼───────────────────────────────────┘
                                   │
                       Push only   │   Pull only for 3 exceptions
                                   │
┌──────────────────────────────────┼───────────────────────────────────┐
│                                  ▼                  QUICKBOOKS ONLINE│
│                                                                      │
│  Customers (mirrored from FF)                                        │
│  Vendors (mirrored from FF)                                          │
│  Items (mirrored from FF taxonomy)                                   │
│  Invoices (mirrored from FF, tax-override pattern)                   │
│  Payments (mirrored from FF OR originated in QBO Payments)           │
│  Credit memos (mirrored from FF)                                     │
│  Refund receipts (mirrored from FF)                                  │
│  Bills (mirrored from FF)                                            │
│  Bill payments (mirrored from FF)                                    │
│  Journal entries (mirrored from FF)                                  │
│  Tax codes (FF reads QBO's at setup; FF computes thereafter)         │
│  Bank feeds (QBO-native; FF doesn't touch)                           │
│  GST/HST filing (QBO-native via NETFILE; FF feeds remittance JEs)    │
│  Reports (QBO computes from mirrored data)                           │
│                                                                      │
│                                  │   Three documented exceptions     │
│                                  │   pull from QBO into FF:          │
│                                  ▼                                   │
│  Exception 1: QBO Payments         ── webhook ──► FF creates payment │
│  Exception 2: Bank transactions    ── CDC pull ──► FF read-only      │
│  Exception 3: Reference IDs        ── one-time ──► FF captures setup │
└──────────────────────────────────────────────────────────────────────┘
```

### 2.2 The three QBO → FF exceptions, in detail

#### Exception 1: QBO Payments (the most operationally important)

**When triggered:** customer pays online via the "Pay Online" button on their FleetForge portal invoice view.

**Why QBO owns this:** customers enter card details on QBO's PCI-DSS-compliant hosted payment page. The money physically lands in QBO's payment processor first. FleetForge cannot be the source of truth for an event that hasn't reached it yet.

**Pattern:**

1. Customer clicks "Pay Online" in FF portal.
2. FF generates a QBO Payments hosted-payment URL for the specific QBO Invoice (which already exists from FF push).
3. Customer redirected to QBO-hosted page.
4. Customer enters card. QBO processes payment.
5. QBO fires webhook to `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`.
6. FF webhook handler verifies HMAC signature, looks up FF invoice via `acc_qbo_invoice_map`, creates FF payment row, calls `AutoEntryBridge::onPaymentReceived` (which posts DR 1010 Cash / CR 1030 AR), and returns 200 OK.
7. FF then **pushes the payment back to QBO** as a confirmation handshake — this closes the loop and ensures QBO's payment record matches FF's payment ID.

**Why the handshake back to QBO:** without it, QBO and FF have independent payment records that happen to refer to the same money. The handshake updates the QBO Payment with FF's reference, so both systems agree which FF payment corresponds to which QBO payment.

**Failure modes:**

- Webhook delivery fails (network, FF down). QBO retries per its webhook retry policy. FF's webhook is idempotent so a delayed redelivery doesn't double-post.
- FF accepts webhook but invoice lookup fails (mapping table out of sync). FF logs to `acc_qbo_sync_log` with error and dispatches accountant notification; payment sits in QBO unallocated until manual reconciliation.
- FF accepts webhook but `AutoEntryBridge::onPaymentReceived` fails (GL account misconfigured). Same — log + notify.

See §12 for full webhook implementation detail.

#### Exception 2: Bank feed transactions

**When triggered:** daily CDC pull from QBO bank transactions.

**Why QBO owns this:** QBO has bank feeds connected to your real banks (RBC, TD, etc.). FleetForge doesn't connect to banks directly. The bank transaction stream is QBO's.

**Pattern:**

1. Daily cron `qbo_bank_cdc.php` at 02:00 server time queries QBO API for any bank transactions modified since last successful pull (CDC = Change Data Capture).
2. New/modified transactions are inserted/updated in `acc_bank_transactions` as **read-only mirror** rows. They carry `source='qbo_cdc'` and `qbo_bank_txn_id` for round-trip identification.
3. FF displays the transactions in the bank module so operators can see them, but **cannot edit or delete** them in FF.
4. **Reconciliation actions remain in QBO.** Matching transactions to invoices/bills, categorizing untagged transactions, marking reconciled — all happens in QBO. FF just shows what QBO knows.

**Why not let FF reconcile too:** introducing dual reconciliation paths means the two systems can disagree about reconciliation state. Keeping it QBO-only avoids that entire class of drift.

**Edge case:** the existing S033 FleetForge bank reconciliation module continues to function for any FF-originated transactions (manual bank account, internal transfers). It just doesn't touch QBO-feed transactions.

#### Exception 3: One-time reference data import

**When triggered:** during initial QBO connection (S-QBO-1 through S-QBO-10).

**Why this is one-time:** QBO already has accounts (Chart of Accounts), tax codes, items, customers, vendors with QBO-assigned IDs. FleetForge needs those IDs to push correctly going forward. We capture them once and then go silent on inbound.

**Pattern:**

1. Operator authorizes OAuth connection to QBO sandbox first (S-QBO-1).
2. FF pulls QBO Chart of Accounts → manual mapping UI maps FF `acc_accounts` (81 seeded) to QBO `Account.Id` (S-QBO-8).
3. FF pulls QBO tax codes → manual mapping UI maps FF `tax_rates` to QBO `TaxCode.Id` (S-QBO-9).
4. FF pulls QBO items list → manual mapping (or creation) maps FF invoice line item types to QBO `Item.Id` (S-QBO-10).
5. FF pulls QBO customers → manual matching UI for each FF customer ↔ QBO customer (S-QBO-5).
6. FF pulls QBO vendors → manual matching (S-QBO-7).

After cutover, this becomes dormant. FF creates new entities in QBO and captures the QBO-assigned IDs at creation time, no pulls needed.

**Why mapping is manual (not auto-fuzzy-match):** auto-matching customer names "LP Logistics" vs "LP Logistics Inc." vs "L.P. Logistics Inc" risks miscategorization. The cost of an early miscategorization compounds across every future invoice. Manual confirmation at setup pays for itself.

### 2.3 What stays FF-only and never syncs

| FF data | Why FF-only |
|---|---|
| Leases | QBO has no lease concept. FF originates invoices from leases; QBO sees only the invoices. |
| Equipment units / fleet | QBO has no fleet concept beyond fixed assets. |
| Mileage logs | QBO has no per-unit mileage tracking. |
| Damage claims | QBO has no damage claim concept. Claims feed invoices and bills, which sync. |
| Customer portal users / sessions | QBO has no customer-facing portal. |
| Notifications | FF-only internal notification system. |
| Audit log | FF-only operational audit. |
| Compliance alerts (CVIP, insurance, registration) | FF-only fleet ops. |
| Per-unit profitability reports | FF-only (uses operational data QBO doesn't have). |
| CCA Schedule 8 continuity | FF-only (QBO doesn't model CCA at all). |
| Working trial balance with lead schedules | FF-only (CaseWare-style workpaper). |
| Document attachments via acc_documents | FF-only (S3 storage; can be cross-referenced from QBO via private notes). |
| Customer equipment rates | FF-only operational pricing. |

### 2.4 What syncs at the JE level

The fundamental sync surface is the **journal entry**. Every FF JE pushes to QBO. Higher-level entities (invoices, payments, bills, credit memos) sync as themselves; QBO derives JEs from them.

Specifically:
- Invoice push → creates QBO Invoice. QBO derives its own JE from the Invoice. **No separate JE push** for invoice-driven JEs.
- Payment push → creates QBO Payment. QBO derives its JE.
- Credit memo, refund, bill, bill payment → all sync as their native entity type; QBO derives JEs.
- Depreciation runs, tax remittances, year-end closing entries, recurring entries, manual JEs → these don't have higher-level entity equivalents in QBO, so they push as raw QBO `JournalEntry`.

### 2.5 The "everything pushes after FF commits" rule

A locked architectural rule: **QBO pushes happen AFTER FF has successfully committed the underlying transaction.** Never before, never during.

Why: if QBO push happens before FF commit and the push succeeds but FF rolls back (validation failure later in the request), QBO has data FF doesn't. Drift is created and the user gets a confusing error.

Implementation: every FF API endpoint that triggers a QBO push enqueues the push job AFTER `db_commit()`. If FF's commit fails, the job is never enqueued. If QBO push subsequently fails, FF is still consistent — only the mirror is behind, and drift detection will catch up.

---

## 3. WORKFLOW SHIFTS FOR OPERATORS AND ACCOUNTANTS

### 3.1 What changes for Avi (operator)

Almost nothing. FleetForge UI for invoicing, payment recording, customer creation, vendor entry, bill entry, manual JEs, etc., is unchanged in shape. The only visible difference is:

- New "QuickBooks" sidebar parent above "Accounting" with sub-pages: Dashboard, Sync Log, Drift, per-entity sync status.
- New Settings → QuickBooks tab with connection management.
- Each entity show-page gets a small "Synced to QBO" badge with timestamp and "Force re-sync" link.
- New notification category `quickbooks` that surfaces sync failures.

The actual operational work doesn't change.

### 3.2 What changes for the accountant

This is the workflow that genuinely shifts.

| Task today (in QBO) | Task post-integration (in FleetForge) |
|---|---|
| Enters new customer in QBO | Enters in **FleetForge** (Customers module) |
| Enters new vendor in QBO | Enters in **FleetForge** (AP → Vendors) |
| Enters a bill from a vendor in QBO | Enters in **FleetForge** (AP → Bills, S032 module) |
| Records a manual JE in QBO | Records in **FleetForge** (S028 JE service) |
| Edits a customer's billing address in QBO | Edits in **FleetForge** — change flows to QBO automatically |
| Voids an invoice in QBO | Voids in **FleetForge** — void flows to QBO |
| Reconciles bank feed transactions in QBO | **Stays in QBO** unchanged |
| Files GST/HST/PST return via QBO NETFILE | **Stays in QBO** unchanged (FF generates the GST34 line-by-line breakdown + posts remittance JE which pushes to QBO; accountant files through QBO NETFILE) |
| Processes customer payment via QBO Payments | **Stays in QBO** unchanged (FF portal "Pay Online" uses QBO Payments hosted page) |
| Year-end closing in QBO | Year-end close in **FleetForge** (§22 of accounting spec); FF pushes closing JEs to QBO |
| Year-end reports + working papers | Generated by **FleetForge** (WTB v2, lead schedules, CCA, year-end package per §22 + §23) |
| Spreadsheet → manual QBO entry | **Goes away entirely** |

### 3.3 The accountant pre-briefing

Before Phase QBO starts (before S-QBO-1), Avi should have an explicit conversation with the accountant to:

1. Walk them through the workflow shifts above.
2. Get sign-off that bill-entry in FleetForge (instead of QBO) is acceptable — this is the biggest behavior change.
3. Confirm Q-CPA-1 through Q-CPA-7 from the master roadmap §17.2: QBO tier (Plus confirmed), use of QBO Classes/Locations, custom fields/tax codes/accounts in current QBO file, whether accountant ever creates invoices directly in QBO, sign-off on the lease classification wizard (for Phase D), engagement type (compilation vs review).

If the accountant pushes back on the bill-entry shift, fallback options exist (allowing bills to be entered in QBO and pulled to FF — an additional exception). The default plan assumes the accountant agrees.

---

## 4. LOCKED DECISIONS

These decisions are normative. Sessions that need to deviate must lock new D-QBO-* numbers and amend this spec.

### 4.1 D-QBO-CORE-* (architectural)

| ID | Decision |
|---|---|
| D-QBO-CORE-1 | FleetForge is the canonical source of truth. QBO is a downstream mirror. |
| D-QBO-CORE-2 | Three documented QBO → FF exceptions: (a) QBO Payments via webhook; (b) bank feed transactions via daily CDC pull; (c) one-time reference-data ID mapping at setup. |
| D-QBO-CORE-3 | The FleetForge accounting module is not deprecated. Both systems run permanently. AutoEntryBridge continues to post FF JEs as today. |
| D-QBO-CORE-4 | Push happens AFTER FF commits the underlying transaction, never before or during. |
| D-QBO-CORE-5 | Push is queued and idempotent by default. Synchronous push is reserved for user-initiated single-entity actions where the user needs immediate confirmation. |
| D-QBO-CORE-6 | Tax computation is FF-side. QBO accepts FF's computed tax via `TxnTaxDetail.TotalTax` override with `TaxCodeRef='NON'` per line. No QBO-side recalculation. |
| D-QBO-CORE-7 | Sent invoices are immutable in both systems (D14 + QBO Invoice modification disabled post-send). |
| D-QBO-CORE-8 | OAuth credentials stored in `settings` table with `is_sensitive=1`; never logged, never displayed beyond first 4 chars in UI. |
| D-QBO-CORE-9 | Sandbox environment used for all development and integration testing. Production cutover is a one-time event in S-QBO-29 and S-QBO-30. |
| D-QBO-CORE-10 | Drift tolerance: $0.05 per invoice (tax rounding); $0.01 per payment; $0.00 for customers/vendors; $1.00 for GL account balances overall. Drift exceeding tolerance fires accountant notification. |
| D-QBO-CORE-11 | AR drift remediation ($17,064.62 from 2026-05-07 audit, root cause H5 + H6) is absorbed into S-QBO-27 historical pull cross-reference. Not a separate Phase A session. |
| D-QBO-CORE-12 | Both engine versions (`period_independent` legacy ProRateCalculator and `holistic` HolisticLeaseEngine) push invoices to QBO. S-QBO-11 invoice push reads `lease.engine_version` and dispatches accordingly. |
| D-QBO-CORE-13 | Production environment is live at `https://mainlandrentals.com/fleetforge` since 2026-05-16 (D-ARCH-13). nginx is the production web server (D202). SSL via Let's Encrypt. Production webhook URL: `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`. |

### 4.2 D-QBO-N-* per-session

Each session locks 4-10 D-QBO-N-* decisions. The full decision log lives in `FLEETFORGE_PROGRESS.md`; this spec mirrors the QBO-relevant subset as sessions complete.

---

## 5. OAUTH AND CREDENTIAL MANAGEMENT

### 5.1 OAuth 2.0 flow

Intuit's OAuth uses the standard authorization-code grant.

**Setup prerequisites (operator one-time tasks before S-QBO-1):**

1. Register an Intuit Developer account at `developer.intuit.com`.
2. Create a sandbox app:
   - App name: `FleetForge — Mainland (Sandbox)`
   - Scopes: `com.intuit.quickbooks.accounting` (mandatory), `com.intuit.quickbooks.payment` (for QBO Payments)
   - Redirect URI (sandbox): `https://<ngrok-tunnel>.ngrok.io/fleetforge/oauth/qbo/callback.php` during dev
   - Note Client ID and Client Secret.
3. Create a production app (registered in S-QBO-29):
   - App name: `FleetForge — Mainland`
   - Redirect URI (production): `https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php`
   - Production Client ID and Client Secret.

**The flow (S-QBO-1 implements):**

1. Operator navigates to Settings → QuickBooks → Connect.
2. FF redirects to Intuit authorize URL with `client_id`, `response_type=code`, `scope`, `redirect_uri`, `state` (CSRF token).
3. Operator logs into Intuit and authorizes the app.
4. Intuit redirects back to `/oauth/qbo/callback.php?code={AUTH_CODE}&state={CSRF_TOKEN}&realmId={REALM_ID}`.
5. FF verifies the CSRF state matches.
6. FF exchanges the auth code for access + refresh tokens via `POST https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`.
7. Response contains:
   ```json
   {
     "access_token": "...",
     "refresh_token": "...",
     "token_type": "bearer",
     "expires_in": 3600,
     "x_refresh_token_expires_in": 8726400
   }
   ```
8. FF stores tokens + realm ID + expiry timestamps in `settings` with `is_sensitive=1`.

**Token characteristics:**

- Access token expires in 1 hour (3600 seconds).
- Refresh token expires in 101 days (8726400 seconds).
- Access token is refreshed on every use if expiring within 5 minutes.
- Refresh token is rotated (replaced with new value) on every refresh — FF stores the new value.
- **If the refresh token is not used for 101 days, the entire connection dies and must be re-authorized manually.** This is why the refresh-token pinger is mandatory.

### 5.2 Token storage

All keys with `is_sensitive=1`:

| Key | Value type |
|---|---|
| `quickbooks.environment` | 'sandbox' or 'production' |
| `quickbooks.client_id` | from Intuit Developer |
| `quickbooks.client_secret` | from Intuit Developer (masked) |
| `quickbooks.realm_id` | company file ID, captured at OAuth callback |
| `quickbooks.access_token` | refreshed every <1hr (masked) |
| `quickbooks.refresh_token` | rotated on every refresh (masked) |
| `quickbooks.access_token_expires_at` | for expiry check |
| `quickbooks.refresh_token_expires_at` | for pinger alert |
| `quickbooks.last_connected_at` | for UI display |
| `quickbooks.last_token_refresh_at` | for diagnostics |
| `quickbooks.connection_status` | 'connected' / 'disconnected' / 'expired' / 'error' |
| `quickbooks.connection_error` | last error message if status='error' |
| `quickbooks.webhook_verifier_token` | from Intuit webhook config (masked) |
| `quickbooks.sync_enabled` | master kill-switch; defaults '0' until S-QBO-30 |

**Sensitive value display rule:** UI shows `••••••••XXXX` where XXXX is last 4 chars. Full value never rendered to user, never logged, never in API audit log.

### 5.3 Refresh-token pinger (cron)

`cron/qbo_token_refresh.php` runs daily at 02:00 server time.

Logic:
1. Acquire advisory lock `GET_LOCK('ff_qbo_token_refresh')` (D21).
2. Read `quickbooks.refresh_token_expires_at`. If NULL or > 14 days away, no action — log "OK" and exit.
3. If 14 days or less to expiry: force a token refresh by calling Intuit's token endpoint with the current refresh token.
4. On success: update tokens in settings. Log to `qbo_token_refresh_log`.
5. On failure:
   - Set `quickbooks.connection_status = 'expired'`.
   - Dispatch critical notification: "QBO connection at risk — refresh token rotation failed. Re-authorize at Settings → QuickBooks."
   - Sentry alert.
6. Release lock.

**Why the 14-day buffer:** if anything goes wrong with refresh, the operator has 14 days to manually re-authorize before connection dies.

### 5.4 Realm ID change runbook

The realm ID identifies the QBO company file. It changes if the accountant migrates the QBO file (rare).

**If realm ID changes:**

1. All FF ↔ QBO mappings become invalid because they reference the old realm.
2. **All `acc_qbo_*_map` tables must be wiped and rebuilt** via S-QBO-5 through S-QBO-10 mapping flows against the new realm.
3. Historical data in QBO is not migrated automatically.

**Procedure:**

1. Operator disconnects current realm at Settings → QuickBooks → Disconnect.
2. FF wipes all map tables (audit_log row).
3. Operator authorizes new realm via OAuth.
4. Operator re-runs S-QBO-5 through S-QBO-10 mapping flows.
5. Operator confirms drift = 0 via S-QBO-25 cron.

Runbook lives at `docs/runbooks/qbo_realm_change.md` (created in S-QBO-1).

### 5.5 Connection disconnect / reconnect

Operator can disconnect at Settings → QuickBooks → Disconnect. Effect:

- `quickbooks.connection_status = 'disconnected'`.
- `quickbooks.sync_enabled = '0'`.
- Pending queue items remain queued (not deleted) but not processed.
- Drift detection cron continues and surfaces state divergence.

Reconnecting:

- Operator clicks Connect → OAuth flow.
- Reconnecting to the **same realm**: existing mappings preserved, sync resumes.
- Reconnecting to a **different realm**: realm-change runbook applies.

---

## 6. SYNC INFRASTRUCTURE

### 6.1 The push pipeline

Every entity that pushes follows the same pipeline:

```
FF event commits (e.g., invoice sent)
         │
         ▼
Push job enqueued to acc_qbo_sync_queue (status='queued')
         │
         ▼
Background worker (cron, every 1 min) picks up oldest queued job
         │
         ▼
Worker acquires advisory lock per entity (e.g., 'ff_qbo_push_invoice_42')
         │
         ▼
Worker checks token expiry; refreshes if <5 min remaining
         │
         ▼
Worker builds QBO API payload from FF entity
         │
         ▼
Worker calls QBO API (POST or PUT depending on create vs update)
         │
         ├─► Success (200/201)
         │     │
         │     ▼
         │   Worker captures QBO-assigned ID
         │   Worker writes to acc_qbo_*_map (FF id ↔ QBO id ↔ sync timestamp)
         │   Worker writes to acc_qbo_sync_log (status='success')
         │   Worker flips queue entry status='completed'
         │
         └─► Failure
               │
               ▼
             Worker classifies error:
             • 401 → token expired; refresh and retry once
             • 403 → permissions issue; status='error_permanent', notify
             • 429 → rate limit; back-off and retry per retry-after header
             • 5xx → retry with exponential backoff (max 5 attempts)
             • 4xx other → classify per error code; usually permanent
             Worker writes to acc_qbo_sync_log with full error detail
             If retries exhausted, queue entry status='failed'; notify
```

### 6.2 Push modes

Two modes per entity type, configurable in settings:

- **Synchronous** (`sync_mode='sync'`): push happens in the same HTTP request that triggered the FF event. User waits for QBO confirmation. Used for user-initiated single-entity actions ("Send Invoice" button) where user expects immediate feedback. Adds 300-800ms latency.
- **Queued** (`sync_mode='queue'`): push enqueued and processed by background worker. User gets immediate FF response; QBO sync happens within ~1 minute. Used for cron-generated events (monthly invoice generation, depreciation runs).

**Default per entity:**

| Entity | Default mode |
|---|---|
| Customer create/update | sync |
| Vendor create/update | sync |
| Invoice send (user-initiated) | sync |
| Invoice send (cron-generated) | queue |
| Invoice void | sync |
| Payment record (manual) | sync |
| Payment record (from QBO webhook) | sync (FF must respond quickly) |
| Credit memo / refund | sync |
| Bill create | queue |
| Bill payment | queue |
| Manual JE | sync |
| Depreciation run | queue |
| Recurring JE post | queue |
| Tax remittance JE | sync |
| Year-end closing JE | sync |

Setting `quickbooks.sync_mode.<entity>` overrides per-entity.

### 6.3 Idempotency

Every push is idempotent via the mapping table.

Before pushing, the worker checks `acc_qbo_<entity>_map` for the FF entity ID:

- If mapped already with a QBO ID: this is an **update**. Use PUT with the QBO ID and latest SyncToken.
- If not mapped: this is a **create**. Use POST. Capture the QBO-assigned ID and write to the map.

Re-running a queued push job against an already-pushed entity:

- For creates: the existence check turns it into an update. No duplicate QBO entity created.
- For updates: SyncToken mismatch (handled below) is the safety net.

**SyncToken handshake** (QBO's optimistic-lock token):

QBO assigns a `SyncToken` to every entity. Updates must include the current SyncToken; if QBO's SyncToken differs from FF's stored one, QBO returns 400 "Stale Object Error."

FF handles this by:

1. On any successful response (create or update), store the SyncToken returned in `acc_qbo_<entity>_map`.
2. Before any update, read the current SyncToken from the map.
3. If update fails with stale object error: pull the current QBO entity, update FF's SyncToken record, retry the update once. If retry also fails, queue as failed and notify (rare — usually means someone edited the QBO entity directly).

### 6.4 The sync queue table

```sql
CREATE TABLE acc_qbo_sync_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM(
    'customer','vendor','invoice','payment','credit_memo','refund_receipt',
    'bill','bill_payment','journal_entry','item','account','tax_code'
  ) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  operation ENUM('create','update','void','delete') NOT NULL,
  status ENUM('queued','processing','completed','failed','skipped') NOT NULL DEFAULT 'queued',
  priority TINYINT NOT NULL DEFAULT 5,
  retry_count TINYINT NOT NULL DEFAULT 0,
  max_retries TINYINT NOT NULL DEFAULT 5,
  next_retry_at DATETIME NULL,
  error_message TEXT NULL,
  error_code VARCHAR(50) NULL,
  enqueued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  picked_up_at DATETIME NULL,
  completed_at DATETIME NULL,
  worker_id VARCHAR(50) NULL,
  payload_snapshot JSON NULL,
  INDEX idx_status_priority (status, priority, enqueued_at),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_retry (status, next_retry_at)
);
```

### 6.5 The sync log table

Every API call to QBO logged for diagnostics and audit:

```sql
CREATE TABLE acc_qbo_sync_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('push','pull') NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NULL,
  qbo_entity_id VARCHAR(50) NULL,
  operation VARCHAR(20) NOT NULL,
  http_method VARCHAR(10) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  request_payload JSON NULL,
  response_status INT NULL,
  response_payload JSON NULL,
  duration_ms INT NULL,
  error_code VARCHAR(50) NULL,
  error_message TEXT NULL,
  user_id INT UNSIGNED NULL,
  queue_id INT UNSIGNED NULL,
  realm_id VARCHAR(50) NOT NULL,
  environment ENUM('sandbox','production') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_qbo (entity_type, qbo_entity_id),
  INDEX idx_errors (error_code, created_at),
  INDEX idx_created (created_at)
);
```

**Retention:** 365 days, then archived. Most-recent 90 days searchable in FF UI.

### 6.6 The QuickBooksClient class

`lib/Integrations/QuickBooksClient.php` is the single HTTP boundary. Every QBO call goes through it.

Responsibilities:
- Bearer token management (auto-refresh middleware).
- Rate limit awareness (read `RateLimit-Remaining` header; throttle when low).
- Retry policy (exponential backoff for 5xx + 429; max 5 retries; baseline 60s for 429).
- Structured error mapping (parses Intuit's `Fault.Error[]` response, categorizes as transient vs permanent).
- Request/response logging to `acc_qbo_sync_log`.
- Sentry instrumentation on every exception.
- Sandbox/production base URL switching:
  - Sandbox: `https://sandbox-quickbooks.api.intuit.com/v3/company/{realmId}/`
  - Production: `https://quickbooks.api.intuit.com/v3/company/{realmId}/`

**Class skeleton:**

```php
namespace FleetForge\Integrations;

class QuickBooksClient
{
    public function __construct() { /* loads tokens, env from settings */ }
    
    public function get(string $resource, array $params = []): array;
    public function post(string $resource, array $payload): array;
    public function put(string $resource, array $payload): array;
    
    public function query(string $sql): array;  // QBO Query SQL
    
    public function getEntity(string $type, string $id): array;
    public function createEntity(string $type, array $data): array;
    public function updateEntity(string $type, string $id, string $syncToken, array $data): array;
    
    public function refreshToken(): void;
    public function verifyConnection(): bool;  // round-trip CompanyInfo
    
    private function executeRequest(string $method, string $endpoint, array $opts): array;
    private function classifyError(array $faultResponse): string;
    private function shouldRetry(int $statusCode, string $errorCode): bool;
}
```

### 6.7 Worker / cron

`cron/qbo_sync_worker.php` runs every 1 minute. Logic:

1. Acquire advisory lock `GET_LOCK('ff_qbo_sync_worker')`.
2. SELECT up to 10 queue items: `WHERE status='queued' AND (next_retry_at IS NULL OR next_retry_at <= NOW()) ORDER BY priority ASC, enqueued_at ASC LIMIT 10 FOR UPDATE`.
3. For each item:
   - Mark `status='processing'`, set `picked_up_at`, `worker_id`.
   - Build payload from current FF entity state (re-read; don't trust queue payload_snapshot for create/update — only for void/delete which may have lost the entity).
   - Call QBO API via QuickBooksClient.
   - On success: update mapping table, mark queue `status='completed'`.
   - On retryable failure: increment retry_count, set `next_retry_at = NOW() + 2^retry_count minutes`.
   - On permanent failure or retries exhausted: `status='failed'`, dispatch notification.
4. Release lock.

**Concurrency:** only one worker per host. Multi-host deployment would need distributed lock (Redis); Mainland's single-host setup uses MySQL advisory locks.

---

## 7. MAPPING TABLES

The mapping tables are the lookup spine. Every push reads its mapping table to find the QBO ID; every pull reads to find the FF ID.

### 7.1 acc_qbo_account_map

```sql
CREATE TABLE acc_qbo_account_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  acc_account_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_account_id VARCHAR(50) NOT NULL,
  qbo_account_name VARCHAR(255) NOT NULL,
  qbo_account_type VARCHAR(50) NOT NULL,
  qbo_account_sub_type VARCHAR(50) NULL,
  qbo_sync_token VARCHAR(20) NULL,
  mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mapped_by INT UNSIGNED NULL,
  last_synced_at DATETIME NULL,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_acct FOREIGN KEY (acc_account_id) REFERENCES acc_accounts(id),
  CONSTRAINT fk_qbo_acct_mapper FOREIGN KEY (mapped_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_qbo (qbo_account_id, realm_id)
);
```

Populated in S-QBO-8. Every FF account touched by AutoEntryBridge MUST have a QBO mapping; S-QBO-8 includes a validator that refuses completion until all bridge-touching accounts are mapped.

### 7.2 acc_qbo_tax_code_map

```sql
CREATE TABLE acc_qbo_tax_code_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tax_rate_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_tax_code_id VARCHAR(50) NOT NULL,
  qbo_tax_code_name VARCHAR(255) NOT NULL,
  qbo_rate_value DECIMAL(7,4) NOT NULL,
  is_tax_override_target BOOLEAN NOT NULL DEFAULT FALSE,
  mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_tax FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id),
  INDEX idx_qbo (qbo_tax_code_id, realm_id)
);
```

Populated in S-QBO-9. Two patterns:

1. **One-to-one map** for each rate (FF GST 5% → QBO GST 5%; FF PST 7% → QBO PST 7%). Used for reporting tax in QBO.
2. **'NON' / Override** pattern. QBO has a built-in 'NON' tax code meaning "no tax computed by QBO." FF uses this on Invoice lines combined with `TxnTaxDetail.TotalTax = <computed amount>` to push exact FF tax without QBO recalculation. The `is_tax_override_target=TRUE` row identifies this code.

### 7.3 acc_qbo_item_map

```sql
CREATE TABLE acc_qbo_item_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ff_item_type VARCHAR(50) NOT NULL UNIQUE,
  qbo_item_id VARCHAR(50) NOT NULL,
  qbo_item_name VARCHAR(255) NOT NULL,
  qbo_item_type ENUM('Service','Inventory','NonInventory') NOT NULL,
  qbo_income_account_id VARCHAR(50) NOT NULL,
  qbo_expense_account_id VARCHAR(50) NULL,
  qbo_sync_token VARCHAR(20) NULL,
  mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  realm_id VARCHAR(50) NOT NULL,
  INDEX idx_qbo (qbo_item_id, realm_id)
);
```

Populated in S-QBO-10. Current FF `invoice_line_items.item_type` ENUM has 17 values as of 2026-05-17 (post-S-BILLING-HOLISTIC-ENGINE). All must be mapped:

| FF item_type | QBO Item type | QBO Income account | Notes |
|---|---|---|---|
| `base_rental` | Service | 4100 Rental revenue | Bread and butter |
| `base_rental_reconciliation_credit` | Service | 4100 Rental revenue | NEW from S-BILLING-HOLISTIC-ENGINE. Decision D-QBO-10-1 |
| `mileage_overage` | Service | 4110 Mileage overage revenue | |
| `mileage_credit` | Service | 4110 Mileage overage revenue | is_credit=1; negative QBO line amount |
| `mileage_drawdown_credit` | Service | 4110 Mileage overage revenue | is_credit=1; negative |
| `gps` | Service | 4120 GPS recharge revenue (net or gross per toggle) | NEW from S-LEASE-GPS-COST |
| `damage_recovery` | Service | 4140 Damage recovery revenue | |
| `late_fee` | Service | 4900 Other revenue (late fees) | |
| `early_termination_fee` | Service | 4900 Other revenue (ETF) | |
| `insurance` | Service | 4200 Insurance recharge revenue | |
| `warranty` | Service | 4210 Warranty recharge revenue | |
| `setup_fee` | Service | 4220 Setup fee revenue | |
| `delivery_fee` | Service | 4230 Delivery fee revenue | |
| `manual` | Service | (operator chooses) | Ad-hoc line items |
| `discount` | Service | 4900 Other revenue (discount applied) | is_credit=1; negative |
| `tax_adjustment` | Service | (mapped to tax account) | |
| `prepayment` | Service | 2050 Customer deposits (liability) | |

**D-QBO-10-1: how to represent `base_rental_reconciliation_credit` in QBO.**

Options:
- (a) Negative-quantity line on the same `base_rental` Item.
- (b) Dedicated `Rental Reconciliation Credit` QBO Item with positive quantity but is_credit semantic → negative line amount.
- (c) Separate QBO CreditMemo per reconciliation event.

**Recommendation (locked at S-QBO-10): option (b) — dedicated Item.** Keeps the QBO Invoice as a single document showing both base charge and reconciliation credit in the same view (better drill-down than option c); avoids the ambiguity of "what does a negative quantity on a positive-priced Item mean?" in option (a). Created during S-QBO-10 with name "Rental Reconciliation Credit" mapped to FF item_type `base_rental_reconciliation_credit`. Income account: 4100 Rental revenue.

### 7.4 acc_qbo_customer_map

```sql
CREATE TABLE acc_qbo_customer_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_customer_id VARCHAR(50) NOT NULL,
  qbo_display_name VARCHAR(255) NOT NULL,
  qbo_sync_token VARCHAR(20) NULL,
  mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mapped_by INT UNSIGNED NULL,
  last_synced_at DATETIME NULL,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_cust FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_qbo (qbo_customer_id, realm_id)
);
```

### 7.5 acc_qbo_vendor_map

```sql
CREATE TABLE acc_qbo_vendor_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_vendor_id VARCHAR(50) NOT NULL,
  qbo_display_name VARCHAR(255) NOT NULL,
  qbo_sync_token VARCHAR(20) NULL,
  mapped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_vend FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  INDEX idx_qbo (qbo_vendor_id, realm_id)
);
```

### 7.6 acc_qbo_invoice_map

```sql
CREATE TABLE acc_qbo_invoice_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_invoice_id VARCHAR(50) NOT NULL,
  qbo_doc_number VARCHAR(50) NOT NULL,
  qbo_sync_token VARCHAR(20) NULL,
  ff_total_amount DECIMAL(15,2) NOT NULL,
  qbo_total_amount DECIMAL(15,2) NULL,
  ff_tax_amount DECIMAL(15,2) NOT NULL,
  qbo_tax_amount DECIMAL(15,2) NULL,
  drift_amount DECIMAL(15,2) GENERATED ALWAYS AS (qbo_total_amount - ff_total_amount) STORED,
  pushed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_synced_at DATETIME NULL,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  INDEX idx_qbo (qbo_invoice_id, realm_id),
  INDEX idx_drift (drift_amount)
);
```

Computed `drift_amount` column is the per-invoice tax-rounding drift surface for the drift detector.

### 7.7 acc_qbo_payment_map

```sql
CREATE TABLE acc_qbo_payment_map (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL UNIQUE,
  qbo_payment_id VARCHAR(50) NOT NULL,
  qbo_sync_token VARCHAR(20) NULL,
  origin ENUM('ff','qbo_payments_webhook','qbo_other') NOT NULL,
  webhook_event_id VARCHAR(100) NULL,
  pushed_at DATETIME NULL,
  pulled_at DATETIME NULL,
  realm_id VARCHAR(50) NOT NULL,
  CONSTRAINT fk_qbo_pay FOREIGN KEY (payment_id) REFERENCES payments(id),
  INDEX idx_qbo (qbo_payment_id, realm_id),
  INDEX idx_webhook (webhook_event_id)
);
```

`origin` distinguishes FF-originated vs QBO-webhook-originated payments for bidirectional dedup.

### 7.8 Remaining map tables

Same pattern — one row per entity, FF id (UNIQUE), QBO id, SyncToken, sync timestamps, realm_id:

- `acc_qbo_credit_memo_map`
- `acc_qbo_refund_receipt_map`
- `acc_qbo_bill_map`
- `acc_qbo_bill_payment_map`
- `acc_qbo_journal_entry_map`
- `acc_qbo_bank_account_map`
- `acc_qbo_bank_transaction_map`
- `acc_qbo_fixed_asset_map` (limited; FF is canonical for fixed assets)

---

*End of Part 1. Part 2 covers §8 per-entity sync rules, §9 tax handling, §10 multi-currency, §11 QBO Payments embed.*

---


## 8. PER-ENTITY SYNC RULES

This is the operational meat. For every entity type: what triggers a push, what payload is built, what fields map where, what happens on failure.

### 8.1 Customer

**FleetForge-owned fields** (FF is canonical; QBO mirrors):

| FF field | QBO field |
|---|---|
| `company_name` | `DisplayName`, `CompanyName` |
| `contact_name` | `GivenName` + `FamilyName` (parsed) |
| `phone` | `PrimaryPhone.FreeFormNumber` |
| `email` | `PrimaryEmailAddr.Address` |
| `billing_address_line1` | `BillAddr.Line1` |
| `billing_address_line2` | `BillAddr.Line2` |
| `billing_city` | `BillAddr.City` |
| `billing_province` | `BillAddr.CountrySubDivisionCode` |
| `billing_postal_code` | `BillAddr.PostalCode` |
| `billing_country` | `BillAddr.Country` |
| `gst_exempt` | mapped to QBO `Taxable` (inverted) |
| `is_active` (soft-delete) | mapped to QBO `Active` |
| `notes` | `Notes` |

**FleetForge-only fields** (don't sync):

`gst_number`, `pst_number`, `dot_number`, `mc_number`, `customer_tags`, `portal_user_id`, `customer_equipment_rates`, `risk_score`, `health_score`, `is_related_party` (§23.9 of accounting spec), `gps_revenue_presentation` (§23.8). These are FF operational/CRM fields with no QBO equivalent.

**Triggers (FF → QBO push):**

| FF event | QBO operation | Mode |
|---|---|---|
| `POST /api/v1/customers/create.php` | Create Customer | sync |
| `POST /api/v1/customers/update.php` (operational fields only) | No-op | (none) |
| `POST /api/v1/customers/update.php` (sync fields per table above) | Update Customer | sync |
| `POST /api/v1/customers/delete.php` (soft-delete) | Set `Active=false` | sync |

**Payload builder** (`lib/Integrations/QboCustomerPusher.php`):

```php
public function build(int $customerId): array
{
    $customer = db_row("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
    if (!$customer) throw new \RuntimeException("Customer not found: $customerId");
    
    $payload = [
        'DisplayName' => $customer['company_name'],
        'CompanyName' => $customer['company_name'],
        'Active' => true,
        'Taxable' => !$customer['gst_exempt'],
        'Notes' => $customer['notes'] ?? '',
    ];
    
    if (!empty($customer['contact_name'])) {
        $parts = explode(' ', $customer['contact_name'], 2);
        $payload['GivenName'] = $parts[0];
        if (count($parts) > 1) $payload['FamilyName'] = $parts[1];
    }
    if (!empty($customer['phone'])) {
        $payload['PrimaryPhone'] = ['FreeFormNumber' => $customer['phone']];
    }
    if (!empty($customer['email'])) {
        $payload['PrimaryEmailAddr'] = ['Address' => $customer['email']];
    }
    if (!empty($customer['billing_address_line1'])) {
        $payload['BillAddr'] = [
            'Line1' => $customer['billing_address_line1'],
            'Line2' => $customer['billing_address_line2'] ?? '',
            'City'  => $customer['billing_city'],
            'CountrySubDivisionCode' => $customer['billing_province'],
            'PostalCode' => $customer['billing_postal_code'],
            'Country' => $customer['billing_country'] ?? 'Canada',
        ];
    }
    
    return $payload;
}
```

**Name collision handling** (QBO requires DisplayName uniqueness):

- At initial mapping (S-QBO-5): manual matching UI surfaces the case; operator confirms linking the existing QBO customer to the FF customer, or renaming.
- For new customers post-cutover: QBO error 6240 → FF auto-appends `(2)` or similar discriminator with operator-visible warning.

### 8.2 Vendor

Same pattern as customer. Fewer fields. Map: `acc_qbo_vendor_map`. Pusher: `lib/Integrations/QboVendorPusher.php`.

### 8.3 Item / Product / Service

Items are the line-item taxonomy. Most created during S-QBO-10 setup. FF doesn't allow operators to create new line item types ad-hoc — ENUM is fixed in code. Map is stable after S-QBO-10.

New item types added post-cutover (e.g., a future session introduces a new ENUM value) require updating the map and creating the corresponding QBO Item.

### 8.4 Invoice

**The most important entity to sync correctly.**

**Trigger:** invoice transitions to `status='sent'` via `api/v1/invoices/send.php`. FF JE posted by AutoEntryBridge BEFORE QBO push enqueues.

**Mode default:** queue (for cron-generated, the common case); sync for user-initiated via "Send Invoice" button.

**Payload structure:**

```json
{
  "TxnDate": "2026-04-15",
  "DueDate": "2026-04-30",
  "DocNumber": "INV-2026-00042",
  "CustomerRef": {"value": "qbo_customer_id_from_map"},
  "CurrencyRef": {"value": "CAD"},
  "ExchangeRate": 1.0,
  "PrivateNote": "FF holistic engine: tier=monthly_math, total_days_so_far=34, cumulative_correct=793.33, already_billed=200.00, delta=593.33",
  "CustomerMemo": {"value": "Lease ABC-123 — Apr 2026 rental"},
  "Line": [
    {
      "Id": "1",
      "LineNum": 1,
      "Description": "Base rental: 2026-04-01 to 2026-04-30 (30 days, lease day 5-34)",
      "Amount": 593.33,
      "DetailType": "SalesItemLineDetail",
      "SalesItemLineDetail": {
        "ItemRef": {"value": "qbo_item_id_for_base_rental"},
        "Qty": 1,
        "UnitPrice": 593.33,
        "TaxCodeRef": {"value": "NON"}
      }
    },
    {
      "Id": "2",
      "LineNum": 2,
      "Description": "GPS tracking — Apr 2026 (30 days × $1.00/day)",
      "Amount": 30.00,
      "DetailType": "SalesItemLineDetail",
      "SalesItemLineDetail": {
        "ItemRef": {"value": "qbo_item_id_for_gps"},
        "Qty": 30,
        "UnitPrice": 1.00,
        "TaxCodeRef": {"value": "NON"}
      }
    }
  ],
  "TxnTaxDetail": {
    "TotalTax": 74.80,
    "TaxLine": [
      {
        "Amount": 31.17,
        "DetailType": "TaxLineDetail",
        "TaxLineDetail": {
          "TaxRateRef": {"value": "qbo_tax_rate_id_for_gst_5pct"},
          "PercentBased": true,
          "TaxPercent": 5.0,
          "NetAmountTaxable": 623.33
        }
      },
      {
        "Amount": 43.63,
        "DetailType": "TaxLineDetail",
        "TaxLineDetail": {
          "TaxRateRef": {"value": "qbo_tax_rate_id_for_pst_7pct"},
          "PercentBased": true,
          "TaxPercent": 7.0,
          "NetAmountTaxable": 623.33
        }
      }
    ]
  }
}
```

**Tax-override pattern (D-QBO-CORE-6).** Every line has `TaxCodeRef: {"value": "NON"}` — meaning "no tax computed by QBO." The `TxnTaxDetail.TotalTax` is the FF-computed total tax; QBO accepts as-is. Tax line breakdown provided for QBO report visibility but doesn't trigger recalculation.

**Why this pattern:** QBO's tax engine and FF's tax engine compute rounding differently in edge cases (especially for multi-rate stacks like GST + PST). Without override, every invoice has $0.01-$0.03 drift.

**Engine-version dispatch (D-QBO-CORE-12):**

```php
// In QboInvoicePusher::build():

$lease = ...; // load lease for invoice
if ($lease['engine_version'] === 'holistic') {
    $payload['PrivateNote'] = $this->buildHolisticAuditNote($invoice);
}

// For each line, if item_type = 'base_rental_reconciliation_credit',
// use the dedicated Reconciliation Credit Item with negative amount.
```

The 23 active+pending leases on `period_independent` never emit reconciliation-credit lines (the holistic engine generates those). For holistic leases, if a line is `item_type='base_rental_reconciliation_credit'`, QBO line uses the dedicated Reconciliation Credit Item (per D-QBO-10-1) and the line amount is negative.

**Invoice update (post-send modifications):**

Per D-QBO-CORE-7, sent invoices are immutable. Most updates blocked at FF API level. Updates that propagate to QBO:

- Status changes: paid (via payment push, not invoice update), voided (via void operation), written off.
- Customer-facing memo / private note: editable in FF, syncs to QBO.

**Invoice void:**

FF `api/v1/invoices/void.php` → sets `status='void'`. Push enqueues `operation='void'`. Worker calls QBO Invoice void API: `POST /v3/company/{realmId}/invoice?include=void&operation=void`.

Edge case: if QBO Invoice has been paid (via QBO Payments or accountant action in QBO), void is rejected. FF logs conflict, surfaces to drift dashboard, notifies accountant. FF-side void stands but the FF JE reversal may need manual review.

### 8.5 Payment

**Three origins:**

1. **FF-originated** (operator records cash/cheque/EFT in FF): FF creates payment → AutoEntryBridge posts FF JE → push enqueues → QBO Payment created → mapping written with `origin='ff'`.

2. **QBO-Payments-originated** (customer clicks "Pay Online" in FF portal): QBO processes → webhook to FF → FF creates payment row with `origin='qbo_payments_webhook'` → AutoEntryBridge posts FF JE → handshake push back to QBO updates QBO Payment with FF's reference → mapping written.

3. **QBO-other-originated** (accountant records a payment directly in QBO, bypassing FF — rare): caught by daily drift detection. Resolution options:
   - **Recommended:** operator manually creates FF payment matching QBO payment, runs "Link to QBO Payment" action that writes mapping without re-pushing.
   - **Alternative:** if it's a QBO-only event that shouldn't be in FF, flag drift as "accepted divergence" and document.

**Payment payload to QBO:**

```json
{
  "TxnDate": "2026-04-15",
  "CustomerRef": {"value": "qbo_customer_id"},
  "TotalAmt": 593.33,
  "CurrencyRef": {"value": "CAD"},
  "ExchangeRate": 1.0,
  "PaymentMethodRef": {"value": "qbo_payment_method_id_eft"},
  "PaymentRefNum": "PAY-2026-00042",
  "DepositToAccountRef": {"value": "qbo_account_id_for_undeposited_funds"},
  "Line": [
    {
      "Amount": 593.33,
      "LinkedTxn": [
        {
          "TxnId": "qbo_invoice_id_from_map",
          "TxnType": "Invoice"
        }
      ]
    }
  ]
}
```

`LinkedTxn` allocates payment to specific invoices. FF can allocate one payment across multiple invoices; QBO supports the same.

### 8.6 Credit Memo

**Trigger:** FF `api/v1/credit_notes/create.php` → AutoEntryBridge posts the two-step credit JE (DR Revenue / CR 2060 Customer Credits Liability at creation; DR 2060 / CR AR at application).

**Payload:**

```json
{
  "TxnDate": "2026-04-20",
  "DocNumber": "CN-2026-00007",
  "CustomerRef": {"value": "qbo_customer_id"},
  "Line": [
    {
      "Description": "Mileage overpayment refund",
      "Amount": 80.00,
      "DetailType": "SalesItemLineDetail",
      "SalesItemLineDetail": {
        "ItemRef": {"value": "qbo_item_id_for_mileage_credit"},
        "Qty": 1,
        "UnitPrice": 80.00,
        "TaxCodeRef": {"value": "NON"}
      }
    }
  ],
  "TxnTaxDetail": {"TotalTax": 0.00}
}
```

**Application to invoice** (FF `api/v1/credit_notes/apply.php`): updates the QBO CreditMemo to link to the QBO Invoice via `LinkedTxn`.

### 8.7 Refund Receipt

**Trigger:** FF refund flow (defined in S-MILEAGE-3 spec, currently CPA-blocked on 5 questions per D-I (A) / D176; S-QBO-17 depends on resolution).

**Payload:**

```json
{
  "TxnDate": "2026-04-22",
  "DocNumber": "REF-2026-00003",
  "CustomerRef": {"value": "qbo_customer_id"},
  "PaymentMethodRef": {"value": "qbo_payment_method_id_eft"},
  "DepositToAccountRef": {"value": "qbo_account_id_for_bank"},
  "TotalAmt": 250.00,
  "Line": [
    {
      "Description": "Mileage prepayment refund on close",
      "Amount": 250.00,
      "DetailType": "SalesItemLineDetail",
      "SalesItemLineDetail": {
        "ItemRef": {"value": "qbo_item_id_for_mileage_credit"},
        "TaxCodeRef": {"value": "NON"}
      }
    }
  ]
}
```

### 8.8 Bill (Accounts Payable)

**Trigger:** FF `api/v1/accounting/bills/create.php` → AutoEntryBridge posts DR Expense + GST ITC / CR AP JE.

**Payload:**

```json
{
  "TxnDate": "2026-04-10",
  "DueDate": "2026-04-30",
  "DocNumber": "BILL-FROM-VENDOR-12345",
  "VendorRef": {"value": "qbo_vendor_id"},
  "CurrencyRef": {"value": "CAD"},
  "PrivateNote": "FF bill #BILL-2026-00018",
  "Line": [
    {
      "Description": "Insurance premium — Apr 2026 fleet coverage",
      "Amount": 4200.00,
      "DetailType": "AccountBasedExpenseLineDetail",
      "AccountBasedExpenseLineDetail": {
        "AccountRef": {"value": "qbo_account_id_for_insurance_expense"},
        "TaxCodeRef": {"value": "NON"}
      }
    }
  ],
  "TxnTaxDetail": {
    "TotalTax": 210.00,
    "TaxLine": [
      {
        "Amount": 210.00,
        "DetailType": "TaxLineDetail",
        "TaxLineDetail": {
          "TaxRateRef": {"value": "qbo_tax_rate_id_for_gst_5pct_itc"},
          "PercentBased": true,
          "TaxPercent": 5.0
        }
      }
    ]
  }
}
```

The tax line represents recoverable GST/HST (Input Tax Credit). Mapping to QBO ITC-eligible tax code.

### 8.9 Bill Payment

**Trigger:** FF `api/v1/accounting/ap-payments/create.php` (after S-ACCT-FIX-AP builds the dedicated page).

**Payload:**

```json
{
  "TxnDate": "2026-04-25",
  "VendorRef": {"value": "qbo_vendor_id"},
  "TotalAmt": 4410.00,
  "PayType": "Check",
  "CheckPayment": {
    "BankAccountRef": {"value": "qbo_account_id_for_bank"},
    "PrintStatus": "NotSet"
  },
  "Line": [
    {
      "Amount": 4410.00,
      "LinkedTxn": [
        {
          "TxnId": "qbo_bill_id",
          "TxnType": "Bill"
        }
      ]
    }
  ]
}
```

### 8.10 Journal Entry

Catch-all for any FF JE that doesn't have a higher-level entity equivalent in QBO. Includes:

- Depreciation runs (DR Depreciation Expense / CR Accumulated Depreciation).
- Tax remittance JEs.
- Year-end closing JEs.
- Recurring entries (insurance amortization, rent accruals, etc.).
- Adjusting JEs from accountant (S-ACCT-AJE workflow).
- Reversing entries.
- Manual JEs from operators.

**Payload:**

```json
{
  "TxnDate": "2026-04-30",
  "DocNumber": "JE-2026-00128",
  "PrivateNote": "Monthly depreciation — Apr 2026",
  "Line": [
    {
      "Description": "Depreciation expense — fleet",
      "Amount": 4250.00,
      "DetailType": "JournalEntryLineDetail",
      "JournalEntryLineDetail": {
        "PostingType": "Debit",
        "AccountRef": {"value": "qbo_account_id_for_depr_expense"}
      }
    },
    {
      "Description": "Accumulated depreciation — fleet",
      "Amount": 4250.00,
      "DetailType": "JournalEntryLineDetail",
      "JournalEntryLineDetail": {
        "PostingType": "Credit",
        "AccountRef": {"value": "qbo_account_id_for_accum_depr"}
      }
    }
  ]
}
```

**JEs that DON'T push:**

- JEs already implicit in another entity sync (invoice JE, payment JE, bill JE, credit memo JE) — QBO derives those from the entities.
- Draft JEs (`entry_status='draft'` per S-ACCT-AJE).
- Submitted/approved-but-not-posted JEs.

Only `entry_status='posted'` JEs that are NOT bridge-derived from a higher-level entity push.

**Identification rule** (in `lib/Integrations/QboJournalEntryPusher.php`):

```php
public function shouldPushJe(int $jeId): bool
{
    $je = db_row("SELECT * FROM acc_journal_entries WHERE id = ?", [$jeId]);
    
    if ($je['entry_status'] !== 'posted') return false;
    
    $bridgeSourceTypes = ['invoice', 'payment', 'credit_note', 'ap_bill', 'ap_payment'];
    if (in_array($je['source_type'], $bridgeSourceTypes, true)) return false;
    
    // Push everything else: depreciation, tax_remittance, year_end_close, recurring, manual, adjustment, reversal
    return true;
}
```

### 8.11 Bank Account

Mapped at S-QBO-20. FF `acc_bank_accounts` rows match QBO bank accounts. After mapping, used by:

- Payment push (DepositToAccountRef).
- Bill payment push (BankAccountRef).
- Bank transaction CDC pull (to find which FF bank account a QBO transaction belongs to).

### 8.12 Bank Transaction (read-only mirror)

**Direction:** QBO → FF only. Exception #2.

**Trigger:** daily CDC cron `qbo_bank_cdc.php` at 02:00 server time.

**Pull logic:**

```
Last successful pull timestamp = settings.quickbooks.last_bank_cdc_at
For each mapped bank account:
    Query QBO: SELECT * FROM Purchase, Deposit, Transfer, JournalEntry 
               WHERE TxnDate >= last_pull AND BankAccountRef = mapped_id
    For each result:
        If already in acc_qbo_bank_transaction_map: update FF bank transaction
        Else: insert new FF bank transaction with source='qbo_cdc', mark read-only
Update last_bank_cdc_at
```

CDC API endpoint:
```
GET /v3/company/{realmId}/cdc?entities=Purchase,Deposit,Transfer,JournalEntry&changedSince=2026-04-30T00:00:00-07:00
```

### 8.13 Fixed Asset

FF is canonical. QBO doesn't have a full fixed-asset module (Plus tier has limited tracking). Sync surface:

- Depreciation JEs push as standard JE (§8.10).
- Disposal JEs push as standard JE.
- Asset records themselves (cost, accumulated depreciation, useful life, etc.) DON'T sync — they live only in FF.

This is because FF has CCA Schedule 8 continuity (§23.3 of accounting spec) which QBO can't represent. The accountant pulls CCA data from FF; QBO sees only the book depreciation JEs.

### 8.14 Tax Code

Read at S-QBO-9. FF doesn't update QBO tax codes (accountant manages tax codes in QBO directly). FF maps to existing codes and uses tax-override pattern (§9).

### 8.15 Tax Remittance

When FF posts a tax remittance JE (via TaxFilingService in S035), the JE pushes via §8.10. Accountant files the actual GST34 return through QBO's NETFILE workflow — FF doesn't file directly, just supplies the data and remittance JE.

---

## 9. TAX HANDLING AND PLACE-OF-SUPPLY

### 9.1 The tax-override pattern (locked D-QBO-CORE-6)

FleetForge computes all tax. QBO accepts the computed amounts via `TxnTaxDetail.TotalTax` override with all line items tagged `TaxCodeRef: {"value": "NON"}`.

This is the single most important decision in the entire QBO integration. Without it, every invoice has $0.01-$0.05 of tax-rounding drift, accumulating into hundreds of dollars over a year.

**The 'NON' code:**

QBO has a system-defined 'NON' (sometimes labeled 'OUT' or 'Exempt' depending on QBO file setup) tax code meaning "no QBO tax calculation." S-QBO-9 mapping identifies the 'NON' code in the operator's QBO file and stores its QBO TaxCode.Id in setting `quickbooks.tax_override_code_id`.

**The TxnTaxDetail block:**

For every invoice / bill / credit memo / refund receipt:

```json
"TxnTaxDetail": {
    "TotalTax": <ff_computed_total_tax>,
    "TaxLine": [
        {
            "Amount": <ff_gst_amount>,
            "DetailType": "TaxLineDetail",
            "TaxLineDetail": {
                "TaxRateRef": {"value": "<qbo_tax_rate_id_for_gst>"},
                "PercentBased": true,
                "TaxPercent": 5.0,
                "NetAmountTaxable": <ff_subtotal>
            }
        }
    ]
}
```

QBO stores per-rate breakdown for tax-summary reports but doesn't recompute. Override is authoritative.

### 9.2 Multi-province support

FleetForge supports multiple provinces via §23.6 of accounting spec (place-of-supply rule engine).

| Province | Codes applied |
|---|---|
| BC | GST 5% + PST 7% |
| AB | GST 5% only |
| ON | HST 13% |
| QC | GST 5% + QST 9.975% |
| NS (post-Apr 1, 2025) | HST 14% |
| NS (pre-Apr 1, 2025) | HST 15% |
| NB/NL/PE | HST 15% |
| SK | GST 5% + PST 6% |
| MB | GST 5% + RST 7% |
| NWT/NU/YT | GST 5% only |

Each province's tax codes map to QBO via `acc_qbo_tax_code_map`. The mapping is conceptually bi-directional but only used for outbound (FF tells QBO which rate it computed).

For invoices with cross-province items: FF place-of-supply engine determines applicable rates per line. TxnTaxDetail block carries the breakdown. QBO accepts.

### 9.3 NS HST rate change April 1, 2025

NS HST dropped from 15% to 14% on April 1, 2025. Transactions before that use 15%; after use 14%.

FF `tax_rates` has `effective_from`/`effective_to` columns (§23.6 of accounting spec). The place-of-supply engine selects correct rate per transaction date.

For QBO mapping: both NS HST 15% and NS HST 14% codes exist in QBO. S-QBO-9 maps both, with `effective_from`/`effective_to` carried to `acc_qbo_tax_code_map`. The push selects right code per transaction date.

### 9.4 ITC handling

Input Tax Credits (recoverable GST/HST on bills) handled symmetrically to output tax:

- FF computes recoverable ITC on bill creation.
- Bill push includes `TxnTaxDetail.TotalTax = <ff_itc_amount>` with line `TaxCodeRef='NON'`.
- QBO's ITC tracking shows the FF-computed amount.

Motor vehicle ITC restrictions (passenger vehicle cap; freight truck full ITC) enforced FF-side; bill push reflects post-restriction amount.

### 9.5 Quick Method (deferred)

If Mainland elects Quick Method (currently not eligible — exceeds $400K threshold), FF supports it via §23.7 of accounting spec. The remittance JE pushes to QBO same shape. No additional QBO mapping needed.

---

## 10. MULTI-CURRENCY

Mainland operates primarily in CAD with some USD leases.

### 10.1 The exchange rate problem

FX rates change daily. If FF books a USD invoice on April 1 at 1 USD = 1.3500 CAD and customer pays April 15 at 1 USD = 1.3650 CAD, there's a realized FX gain/loss on settlement.

ASPE 1651 (temporal method, §22.1 of accounting spec):

- USD invoice booked at April 1 rate (1.3500). AR in CAD = USD invoice amount × 1.3500.
- USD payment received at April 15 rate (1.3650). Cash in CAD = USD amount × 1.3650. AR reduced by USD amount × 1.3500. Difference = FX gain.

### 10.2 Pushing USD invoices to QBO

QBO supports multi-currency. Invoice payload includes:

```json
"CurrencyRef": {"value": "USD"},
"ExchangeRate": 1.3500
```

QBO stores foreign-currency amount and home-currency-equivalent. FF's pinned exchange rate (from FF settings or Bank of Canada feed) pushed as QBO ExchangeRate. QBO doesn't compute its own rate when ExchangeRate provided.

### 10.3 Payment FX

When USD payment lands (whether via QBO Payments or FF-recorded), realized FX gain/loss computed FF-side, posted as separate JE via AutoEntryBridge. Payment push carries FX rate at settlement:

```json
"CurrencyRef": {"value": "USD"},
"ExchangeRate": 1.3650
```

QBO sees the payment at settlement rate.

### 10.4 Period-end FX revaluation

Per §22.1 of accounting spec, monthly FX revaluation revalues USD AR/AP/Cash at month-end rate. Produces an unrealized FX JE in FF, which pushes to QBO via §8.10. QBO's CAD-equivalent balances stay current.

---

## 11. QBO PAYMENTS EMBED IN CUSTOMER PORTAL

One of the most operationally important features. Enables customers to pay invoices online with money going directly to QBO Payments and AR clearing automatically in both systems.

### 11.1 Why we use the hosted-page pattern

QBO Payments offers two patterns:
- **Hosted page redirect**: customer redirected to QBO-hosted payment page. PCI-DSS scope avoided entirely.
- **Embedded form (Intuit Payment Card Tokenizer)**: FF embeds tokenizer in its own page. Tokens replace raw card data, but FF still has PCI obligations.

**Locked decision: hosted page redirect.** Rationale: PCI scope avoided; implementation simpler; UX acceptable.

### 11.2 The flow

1. Customer logs into FF portal at `/portal`.
2. Customer navigates to outstanding invoice at `/portal/invoices/show.php?id=N`.
3. FF renders invoice with "Pay Online" button (visible when `invoice.status IN ('sent','partially_paid','overdue')` AND `quickbooks.payments_enabled='1'`).
4. Customer clicks "Pay Online."
5. FF backend at `api/v1/portal/invoices/initiate_qbo_payment.php`:
   - Verifies customer owns this invoice.
   - Reads QBO Invoice ID from `acc_qbo_invoice_map`.
   - Calls QBO Payments API to generate hosted-page URL for this invoice.
   - Stores payment-initiation record in `acc_qbo_payment_initiations` (idempotency key, customer ID, invoice ID, initiated_at, return URL).
   - Redirects browser to QBO-hosted URL.
6. Customer lands on QBO's hosted page (e.g., `https://quickbooks.intuit.com/payments/pay/...`).
7. Customer enters card. QBO processes.
8. QBO processing:
   - On success: redirects browser to `https://mainlandrentals.com/fleetforge/portal/invoices/payment_success.php?initiation_id=X`.
   - On cancel: redirects to cancel URL.
   - On error: redirects to error URL.
9. **Independently, QBO fires webhook** to FF's webhook endpoint (the source of truth for payment outcome; redirect URLs are just UX).
10. FF webhook handler (§12) verifies signature, looks up FF invoice, creates FF payment, calls AutoEntryBridge, pushes confirmation back to QBO.
11. When customer lands on success URL, FF can show "Payment processed — Thank you" because webhook has fired.

### 11.3 Idempotency

The webhook handler must be idempotent because QBO retries on any non-2xx response.

**Idempotency key:** `QBO_WEBHOOK_EVENT_ID` header on each webhook delivery is unique per event. FF stores it in `acc_qbo_webhook_events` and de-duplicates.

```sql
CREATE TABLE acc_qbo_webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  webhook_event_id VARCHAR(100) NOT NULL UNIQUE,
  event_type VARCHAR(50) NOT NULL,
  payload JSON NOT NULL,
  signature_verified BOOLEAN NOT NULL,
  processed_at DATETIME NULL,
  processing_result VARCHAR(20) NULL,
  error_message TEXT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  realm_id VARCHAR(50) NOT NULL,
  INDEX idx_received (received_at),
  INDEX idx_unprocessed (processed_at)
);
```

### 11.4 PCI-DSS scope

Because the card form is hosted by QBO/Intuit, FF is **out of PCI-DSS scope** for card data. FF never touches card numbers, CVVs, or card details.

Only QBO-Payments-related data FF stores:
- Payment ID (QBO-assigned)
- Last 4 of card (returned by QBO in webhook payload, safe to store)
- Card brand (Visa/MC/Amex)
- Authorization code (for audit)
- Amount and timestamp

In `acc_qbo_payment_initiations` and `payments` tables. No PCI scope.

### 11.5 Customer experience

1. Click "Pay Online" on FF portal.
2. Redirected to QBO-hosted payment page (visually styled — QBO Payments allows minimal branding).
3. Enter card. Click Pay.
4. Redirected back to FF with "Payment processed — Thank you" and updated invoice status (now `paid`).

End-to-end: typically 30-60 seconds.

### 11.6 What happens if QBO Payments is down

If QBO Payments service unavailable (rare):
- Step 5 (URL generation) fails.
- FF displays error: "Online payment is temporarily unavailable. Please pay by EFT or contact us."
- Customer can call/email Mainland to arrange alternative.

Customer portal continues to function for invoice viewing, document download, lease details — only online payment path affected.

---

*End of Part 2. Part 3 covers §12 webhooks, §13 errors, §14 rate limits, §15 drift detection, §16 historical backfill, §17 cutover runbook.*

---


## 12. WEBHOOKS

The single webhook FF accepts from QBO is for QBO Payments events. All other QBO data flows are pull-driven (CDC) or initiated by FF push.

### 12.1 Webhook configuration

In Intuit Developer dashboard:
- Webhook URL (production): `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`
- Verifier token: generated by Intuit when webhook configured; stored in FF settings as `quickbooks.webhook_verifier_token` (sensitive).
- Subscribed event types: `Payment.Create`, `Payment.Update`, `Payment.Void`.

For sandbox, URL is ngrok tunnel during dev; switched to production at S-QBO-29 cutover.

### 12.2 Signature verification

Every webhook delivery has header `intuit-signature: <hmac-sha256-base64>`. FF verifies:

```php
$body = file_get_contents('php://input');
$verifierToken = AccountingService::setting('quickbooks.webhook_verifier_token');
$expectedSignature = base64_encode(hash_hmac('sha256', $body, $verifierToken, true));
$receivedSignature = $_SERVER['HTTP_INTUIT_SIGNATURE'] ?? '';

if (!hash_equals($expectedSignature, $receivedSignature)) {
    http_response_code(403);
    error_log("Invalid QBO webhook signature");
    Sentry::captureMessage("QBO webhook signature mismatch", ['payload_length' => strlen($body)]);
    exit;
}
```

Forged or replayed webhooks rejected with 403. Sentry alert surfaces repeat attacks.

### 12.3 Event handling

Typical Payment.Create webhook payload:

```json
{
  "eventNotifications": [
    {
      "realmId": "9341452983754321",
      "dataChangeEvent": {
        "entities": [
          {
            "name": "Payment",
            "id": "12345",
            "operation": "Create",
            "lastUpdated": "2026-04-15T10:30:00-07:00"
          }
        ]
      }
    }
  ]
}
```

Webhook only tells FF something changed; doesn't include entity data. FF must then **pull the payment**:

```
GET /v3/company/{realmId}/payment/12345
```

This is QBO's standard pattern — webhooks are notifications, not data feeds.

### 12.4 The handler logic

`api/v1/webhooks/qbo_payment_notifications.php`:

```php
<?php
require __DIR__ . '/../../../bootstrap.php';

// 1. Read body and signature
$body = file_get_contents('php://input');
$receivedSig = $_SERVER['HTTP_INTUIT_SIGNATURE'] ?? '';

// 2. Verify signature
$verifierToken = AccountingService::setting('quickbooks.webhook_verifier_token');
$expectedSig = base64_encode(hash_hmac('sha256', $body, $verifierToken, true));
if (!hash_equals($expectedSig, $receivedSig)) {
    http_response_code(403);
    Sentry::captureMessage("QBO webhook signature mismatch");
    exit;
}

// 3. Parse payload
$payload = json_decode($body, true);
$webhookEventId = $_SERVER['HTTP_INTUIT_WEBHOOK_EVENT_ID'] ?? bin2hex(random_bytes(8));

// 4. Idempotency check
$existing = db_row("SELECT id, processing_result FROM acc_qbo_webhook_events WHERE webhook_event_id = ?", [$webhookEventId]);
if ($existing) {
    http_response_code(200); // already processed
    exit;
}

// 5. Insert webhook event row
db_insert('acc_qbo_webhook_events', [
    'webhook_event_id' => $webhookEventId,
    'event_type' => 'Payment',
    'payload' => $body,
    'signature_verified' => 1,
    'realm_id' => AccountingService::setting('quickbooks.realm_id'),
]);

// 6. Return 200 immediately (process async)
http_response_code(200);
echo json_encode(['status' => 'received']);

// 7. Hand off to worker (or process inline if small)
fastcgi_finish_request();

// 8. Now process each event
foreach ($payload['eventNotifications'] ?? [] as $notif) {
    foreach ($notif['dataChangeEvent']['entities'] ?? [] as $entity) {
        if ($entity['name'] === 'Payment') {
            try {
                $handler = new \FleetForge\Integrations\QboPaymentWebhookHandler();
                $result = $handler->handle(
                    qboPaymentId: $entity['id'],
                    operation: $entity['operation'],
                    realmId: $notif['realmId']
                );
                db_update('acc_qbo_webhook_events', 
                    ['processed_at' => date('Y-m-d H:i:s'), 'processing_result' => $result],
                    ['webhook_event_id' => $webhookEventId]
                );
            } catch (\Throwable $e) {
                Sentry::captureException($e, ['webhook_event_id' => $webhookEventId]);
                db_update('acc_qbo_webhook_events',
                    ['processed_at' => date('Y-m-d H:i:s'), 'processing_result' => 'error', 'error_message' => $e->getMessage()],
                    ['webhook_event_id' => $webhookEventId]
                );
            }
        }
    }
}
```

### 12.5 The payment webhook handler

`lib/Integrations/QboPaymentWebhookHandler.php`:

```php
public function handle(string $qboPaymentId, string $operation, string $realmId): string
{
    // 1. Verify realm matches our connected realm
    $connectedRealm = AccountingService::setting('quickbooks.realm_id');
    if ($realmId !== $connectedRealm) {
        return 'wrong_realm';
    }
    
    // 2. Pull payment details from QBO
    $client = new QuickBooksClient();
    $qboPayment = $client->getEntity('payment', $qboPaymentId);
    
    // 3. Check if already mapped
    $existing = db_row("SELECT payment_id FROM acc_qbo_payment_map WHERE qbo_payment_id = ?", [$qboPaymentId]);
    if ($existing) {
        // Updating — fetch FF payment and reconcile state
        return $this->handleUpdate($existing['payment_id'], $qboPayment, $operation);
    }
    
    // 4. New QBO payment — find the matching FF invoice
    $qboInvoiceId = $qboPayment['Line'][0]['LinkedTxn'][0]['TxnId'] ?? null;
    if (!$qboInvoiceId) {
        return 'no_linked_invoice';
    }
    
    $invoiceMap = db_row("SELECT invoice_id FROM acc_qbo_invoice_map WHERE qbo_invoice_id = ?", [$qboInvoiceId]);
    if (!$invoiceMap) {
        // QBO invoice not mapped to FF — shouldn't happen in normal flow
        NotificationService::dispatch('quickbooks.payment_unmapped_invoice', [
            'qbo_payment_id' => $qboPaymentId,
            'qbo_invoice_id' => $qboInvoiceId,
        ]);
        return 'no_ff_invoice_mapped';
    }
    
    // 5. Create FF payment via existing endpoint logic
    db_transaction(function() use ($qboPayment, $invoiceMap, $qboPaymentId) {
        $paymentId = PaymentService::create([
            'customer_id' => InvoiceService::getCustomerId($invoiceMap['invoice_id']),
            'amount' => $qboPayment['TotalAmt'],
            'payment_date' => substr($qboPayment['TxnDate'], 0, 10),
            'payment_method' => 'qbo_payments',
            'reference_number' => $qboPayment['PaymentRefNum'] ?? "QBO-{$qboPaymentId}",
            'origin' => 'qbo_payments_webhook',
        ]);
        
        // Allocate to the invoice
        PaymentService::allocateToInvoice($paymentId, $invoiceMap['invoice_id'], $qboPayment['TotalAmt']);
        
        // AutoEntryBridge::onPaymentReceived posts the JE
        AutoEntryBridge::onPaymentReceived($paymentId);
        
        // Record the mapping
        db_insert('acc_qbo_payment_map', [
            'payment_id' => $paymentId,
            'qbo_payment_id' => $qboPaymentId,
            'qbo_sync_token' => $qboPayment['SyncToken'],
            'origin' => 'qbo_payments_webhook',
            'webhook_event_id' => $_SERVER['HTTP_INTUIT_WEBHOOK_EVENT_ID'] ?? null,
            'pulled_at' => date('Y-m-d H:i:s'),
            'realm_id' => AccountingService::setting('quickbooks.realm_id'),
        ]);
    });
    
    // 6. Optional handshake — push FF reference back to QBO Payment
    // (so QBO Payment record shows the FF payment number for cross-reference)
    QboPaymentPusher::handshake($paymentId);
    
    return 'success';
}
```

### 12.6 Webhook retry behavior

QBO retries failed webhook deliveries with exponential backoff over 24 hours. FF's idempotency check (§12.4 step 4) ensures replays don't double-post.

If FF is down for an extended period, QBO may eventually stop retrying. To catch missed webhooks, the daily drift detection cron (§15) compares QBO payment count vs FF payment count and surfaces any QBO payments not in FF.

### 12.7 Webhook security best practices

- HTTPS required (production). Lightsail + Let's Encrypt covers this.
- Signature verification on every request.
- Return 200 quickly (target <500ms). Process async if heavy.
- Log every webhook event to `acc_qbo_webhook_events` for audit.
- Sentry alert on signature failure (potential attack).
- Sentry alert on processing errors.
- Never log the webhook body to system log (may contain PII).
- Rate limit at infrastructure level: reject >100 webhook deliveries/min from any IP.

---

## 13. ERROR HANDLING AND RETRY POLICY

### 13.1 Error categorization

Every QBO API call can fail. FF's `QuickBooksClient` categorizes errors:

| HTTP status | Category | Retry? | Action |
|---|---|---|---|
| 200, 201 | Success | n/a | Process response |
| 400 + Fault.type='AuthenticationFault' | Auth expired | Yes (once after refresh) | Refresh token, retry |
| 400 + Fault.type='ValidationFault' | Validation | No | Permanent failure; surface to operator |
| 400 + Fault.code='5010' | Stale Object | Yes (once after sync) | Re-pull entity, update SyncToken, retry |
| 400 + Fault.code='6240' | DuplicateName | No (special handling) | Append discriminator, retry once |
| 401 | Unauthorized | Yes (once after refresh) | Refresh token, retry |
| 403 | Forbidden | No | Permanent; check OAuth scopes |
| 404 | Not Found | Conditional | If updating, entity may have been deleted in QBO; surface to drift dashboard |
| 429 | Rate Limited | Yes (with backoff) | Honor Retry-After header; max 5 retries |
| 500, 502, 503, 504 | Transient | Yes (with backoff) | Exponential backoff; max 5 retries |
| 408 (timeout) | Transient | Yes (with backoff) | Same as 5xx |

### 13.2 The retry policy

For transient failures (5xx, 429, 408), retry with exponential backoff:

```
Attempt 1: immediate (initial)
Attempt 2: wait 1 minute
Attempt 3: wait 2 minutes
Attempt 4: wait 4 minutes
Attempt 5: wait 8 minutes
Attempt 6: wait 16 minutes (final attempt)
```

Total time across all retries: ~31 minutes for a fully-failing transient case.

For 429 (rate limited), honor `Retry-After` header instead of exponential schedule:

```php
$retryAfter = $response->getHeader('Retry-After')[0] ?? 60;
$queueItem['next_retry_at'] = date('Y-m-d H:i:s', time() + $retryAfter);
```

After max retries (5 attempts beyond the initial), queue entry marked `status='failed'` and accountant notification dispatched.

### 13.3 Fault response parsing

QBO returns errors in a structured `Fault` block:

```json
{
  "Fault": {
    "Error": [
      {
        "Message": "Invalid Reference Id",
        "code": "2500",
        "Detail": "Reference 'CustomerRef' is invalid for Field Invoice.CustomerRef. Customer with Id 12345 does not exist.",
        "element": "CustomerRef"
      }
    ],
    "type": "ValidationFault"
  },
  "time": "2026-04-15T10:30:00.000-07:00"
}
```

FF's `QuickBooksClient::classifyError()`:

```php
private function classifyError(array $faultResponse): string
{
    $error = $faultResponse['Fault']['Error'][0] ?? null;
    if (!$error) return 'unknown';
    
    $code = $error['code'] ?? null;
    $type = $faultResponse['Fault']['type'] ?? null;
    
    // Known specific cases
    if ($code === '5010') return 'stale_object';
    if ($code === '6240') return 'duplicate_name';
    if ($code === '2500') return 'invalid_reference';
    if ($code === '610') return 'invalid_object';
    if ($type === 'AuthenticationFault') return 'auth_expired';
    if ($type === 'AuthorizationFault') return 'forbidden';
    if ($type === 'ValidationFault') return 'validation_failed';
    if ($type === 'SystemFault') return 'transient_5xx';
    
    return 'unknown';
}
```

### 13.4 Operator-visible error display

When a queue entry fails permanently, the sync log shows:

- Entity type and ID (with link to FF entity).
- Error code and category.
- Human-readable error message (from QBO).
- Detailed Fault.Detail string.
- Suggested action (computed from error code).
- "Retry" button (resets retry count to 0).

For common errors, suggested action text:

- `validation_failed`: "Edit the entity in FleetForge to address the validation issue. Common causes: missing required field, invalid format. See QBO error detail."
- `duplicate_name`: "Customer name already exists in QBO. Either rename in FF or link to the existing QBO customer in Settings → QuickBooks → Customers."
- `invalid_reference`: "A referenced entity (customer, account, item) doesn't exist in QBO. Re-run the mapping in Settings → QuickBooks."
- `forbidden`: "QBO connection lacks required permissions. Reconnect with proper scopes."
- `auth_expired`: "QBO connection expired. Re-authorize in Settings → QuickBooks."

### 13.5 Sentry integration

Every QBO API call exception captured to Sentry with structured tags:

```php
Sentry::captureException($e, [
    'tags' => [
        'integration' => 'quickbooks',
        'entity_type' => $entityType,
        'operation' => $operation,
        'error_code' => $errorCode,
        'realm_id' => $realmId,
        'environment' => $environment,
    ],
    'extra' => [
        'entity_id' => $entityId,
        'request_payload' => $payload,
        'response_status' => $statusCode,
        'response_body' => $responseBody,
        'retry_count' => $retryCount,
    ],
]);
```

Sentry alerts configured for:
- >10 QBO errors in 1 hour → warning alert.
- >50 QBO errors in 1 hour → critical alert.
- Any auth_expired error → immediate critical alert.
- Any signature mismatch in webhook → immediate critical alert.

### 13.6 Failure escalation

When queue entry transitions to `status='failed'`:

1. Sync log row updated with full error detail.
2. `acc_qbo_drift_events` row inserted with category='push_failed'.
3. NotificationService dispatch:
   - Category: `quickbooks.push_failed`.
   - Severity: high.
   - Recipients: accountant + super_admin (per category routing per S-NOTIF-1).
   - Message: "QBO sync failed for {entity_type} #{entity_id}. Reason: {error_message}. Click to view and retry."
4. Notification appears in operator's notification feed.
5. Operator can click through to the sync log entry → see full diagnostic → click "Retry" if appropriate.

---

## 14. RATE LIMITS

### 14.1 QBO API rate limits

**Sandbox:** 40 requests/minute, 500 requests/day total.

**Production:** 500 requests/minute per realm (much more generous).

These limits are documented at developer.intuit.com but are sometimes changed by Intuit without notice. FF's rate-limit handling is dynamic — reads response headers rather than hardcoding limits.

### 14.2 Rate-limit-aware throttling

`QuickBooksClient` reads response headers on every call:

- `X-RateLimit-Limit`: total allowed per window.
- `X-RateLimit-Remaining`: remaining in current window.
- `X-RateLimit-Reset`: epoch seconds when window resets.

When `Remaining < 10`:
- Worker pauses for `(Reset - now)` seconds before next call.
- Logs to `acc_qbo_sync_log` with note: "Rate limit approaching; throttling."
- Sentry breadcrumb captured.

When 429 returned:
- Worker honors `Retry-After` header (per §13.2).
- Increments retry_count.
- Doesn't double-throttle — just waits.

### 14.3 Historical pull rate limiting (S-QBO-27)

The S-QBO-27 historical migration is the highest-volume scenario — potentially thousands of entity pulls in a single run. Strategy:

- Pull in batches of 100 per entity type.
- Pause 30 seconds between batches.
- Total throughput target: ~200 requests/minute (well under 500/min production limit).
- Resume from last successful batch on failure (checkpointing).
- Monitor `X-RateLimit-Remaining` and auto-throttle if approaching limit.

Estimated total time for historical pull on Mainland's QBO file (~500 customers, ~5000 historical invoices, ~5000 payments): 4-6 hours.

### 14.4 Bursting protection

If a sudden batch of FF events generates many QBO pushes (e.g., monthly invoice cron creating 50 invoices), the queue worker processes them one at a time at ~10/minute pace. This stays well under rate limits even at peak.

---

## 15. DRIFT DETECTION AND RECONCILIATION

The drift detection system is the safety net that ensures FF and QBO stay aligned. Even with perfect push logic, transient failures, manual edits in QBO, or undetected bugs can cause drift. Daily drift detection surfaces it.

### 15.1 The drift dashboard

UI at `app/admin/quickbooks/drift/index.php`. Per-entity drift report:

| Section | Drift type | Display |
|---|---|---|
| Customers | Count mismatch | "FF has 47, QBO has 48 — 1 customer in QBO not mapped" |
| Customers | Field mismatch | "FF 'LP Logistics Inc.' ≠ QBO 'LP Logistics' — 1 customer with name drift" |
| Invoices | Total amount drift | "12 invoices with FF total ≠ QBO total (tax rounding); total drift $0.14" |
| Invoices | Missing in QBO | "3 FF invoices have no QBO mapping" |
| Invoices | Missing in FF | "1 QBO invoice has no FF mapping (likely manual creation in QBO)" |
| Payments | Same as invoices | |
| GL Account Balances | Per-account drift | "5 accounts with FF balance ≠ QBO balance; total drift $342.18" |

Drilldown to per-entity detail showing the exact divergence.

### 15.2 The drift cron

`cron/qbo_drift_check.php` runs nightly at 03:30 server time (after token refresh at 02:00).

Logic:

1. Acquire advisory lock `GET_LOCK('ff_qbo_drift_check')`.
2. For each entity type:
   a. Query FF: count, list of (FF id, QBO id from map, FF total).
   b. Query QBO: count, list of (QBO id, total).
   c. Compare:
      - Entities in FF not mapped → missing in QBO.
      - Entities in QBO not in any mapping → missing in FF.
      - Mapped entities with mismatched totals → drift event.
   d. Insert rows into `acc_qbo_drift_events` for each anomaly.
3. For mapped invoices, compute aggregate drift = SUM(|qbo_total - ff_total|) across all invoices. If > $10, dispatch notification.
4. For GL accounts (a different check — compares account balance, not entity-by-entity):
   a. For each mapped FF account, get FF balance.
   b. Get QBO account balance via QBO API.
   c. If |delta| > $1 → drift event.
5. Release lock.

### 15.3 The drift event table

```sql
CREATE TABLE acc_qbo_drift_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entity_type VARCHAR(50) NOT NULL,
  ff_entity_id INT UNSIGNED NULL,
  qbo_entity_id VARCHAR(50) NULL,
  drift_category ENUM(
    'missing_in_qbo', 'missing_in_ff', 'amount_drift',
    'status_drift', 'field_drift', 'push_failed', 'sync_token_stale'
  ) NOT NULL,
  ff_value TEXT NULL,
  qbo_value TEXT NULL,
  drift_amount DECIMAL(15,2) NULL,
  status ENUM('open','resolved','accepted','suppressed') NOT NULL DEFAULT 'open',
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  resolution_notes TEXT NULL,
  realm_id VARCHAR(50) NOT NULL,
  INDEX idx_status (status, detected_at),
  INDEX idx_entity (entity_type, ff_entity_id),
  CONSTRAINT fk_drift_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
);
```

### 15.4 Drift resolution workflows

Per drift event, operator can:

- **Resolve via FF action.** If the drift is fixable in FF (e.g., re-sync after a transient failure), click "Re-sync" — enqueues a fresh push. Drift event auto-marks resolved when next drift check confirms parity.
- **Resolve via QBO action.** If the drift requires accountant action in QBO (e.g., a QBO-originated payment that should be deleted), notes are captured and operator marks resolved manually.
- **Accept divergence.** Some divergences are intentional (e.g., FF has a customer that the accountant doesn't want in QBO for tax reasons). Mark `status='accepted'` with notes.
- **Suppress.** For false-positive drifts (e.g., known sub-cent tax-rounding that we accept), mark `status='suppressed'`. Suppressed events are hidden from drift dashboard but logged.

### 15.5 Tolerance configuration

Per-entity drift tolerance configurable in settings:

| Entity | Default tolerance |
|---|---|
| Customer (name, address, etc.) | $0.00 (exact match) |
| Vendor | $0.00 |
| Invoice total amount | $0.05 |
| Payment amount | $0.01 |
| Credit memo total | $0.05 |
| Bill total | $0.05 |
| GL account balance | $1.00 |

Drift below tolerance is logged but doesn't trigger notification. Drift above tolerance fires notification.

### 15.6 Manual reconciliation actions

In addition to per-event resolutions, operators can trigger bulk reconciliation:

- **"Force full re-sync of entity type"** — enqueues a push for every mapped entity of that type. Used after a known data issue or after the accountant manually edited many QBO records.
- **"Force pull from QBO"** — pulls the current state of every mapped entity from QBO and writes the SyncToken (used after realm migration or after a long disconnection).
- **"Mark all drifts of category X as accepted"** — bulk-accept (used when known acceptable divergence exists across many entities).

Each action logged to `audit_log` and `acc_qbo_drift_events.resolution_notes`.

---

## 16. HISTORICAL BACKFILL (S-QBO-27)

The one-time inbound migration. Pulls Mainland's existing real QBO data into FleetForge.

### 16.1 Why historical backfill is needed

Currently FF has only dummy data + the H5 LP Logistics + H6 Lepore data that has the drift. Mainland's actual operating history lives in QBO — years of real customers, real invoices, real payments. After cutover, FF needs to be the canonical record going forward, but it needs to also have historical context so reports can show prior-year comparisons.

S-QBO-27 is the session that pulls all of that historical data into FF.

### 16.2 Scope

What gets backfilled:

- **All customers** ever in QBO (active + inactive).
- **All vendors** ever in QBO.
- **All historical invoices** (every Invoice in QBO regardless of date).
- **All historical payments** (every Payment).
- **All historical credit memos.**
- **All historical bills.**
- **All historical bill payments.**
- **All historical journal entries** (every JournalEntry).

What does NOT get backfilled:

- Bank feed transactions older than 30 days (the §8.12 CDC continues from here forward; historical bank txns stay in QBO).
- Reconciliation state (which bank txn matched which invoice — QBO owns this, FF doesn't replicate).
- Reports (computed on-demand from data).

### 16.3 AR drift remediation absorbed (D-QBO-CORE-11)

Per the master architecture decision, the $17,064.62 AR drift from the 2026-05-07 audit is resolved during this historical pull rather than as a standalone Phase A session.

**The two sub-cases:**

**H5 — LP Logistics (+$20,764.80, GL too low):**

5 orphan payment AR-CR JEs (JE-2025-00017 through JE-2025-00021) credited AR for invoices 32, 43, 44, 45, 46. Those invoices have no DR-AR JE. Likely pre-S030 invoices paid post-S030.

Resolution during S-QBO-27:

1. The historical pull will fetch QBO's records for those 5 invoices (which have the correct invoice JE on the QBO side because QBO derives JEs from invoices automatically).
2. For each of the 5 FF invoices, the pull detects mismatch with FF state (FF has invoice row but no DR-AR JE).
3. The pull posts a compensating DR-AR JE with idempotency tag `[A1-FIX-invoice-N]` matching QBO's recorded amounts.
4. Post-fix, GL balance for account 1030 increases by $20,764.80 to align with subledger.

**H6 — Lepore Enterprise Trucking (−$3,700.18, GL too high):**

4 invoice DR-AR JEs (INV-2026-00016 through INV-2026-00019) have JE DR-AR amounts exactly 1.375× invoice.total_amount. Deterministic ratio 11/8 suggests code bug, not data accident.

Resolution during S-QBO-27:

1. Historical pull fetches QBO's records for the 4 invoices.
2. If QBO's invoice totals match FF's `total_amount` (which they should — FF pushed the invoice with FF's total): the 1.375× FF JE is the anomaly. **Post 4 compensating CR-AR JEs** with idempotency tag `[A1-FIX-invoice-N]` to bring GL into alignment with subledger.
3. **Plus: open a bug investigation** into the FF InvoiceGenerator code path that produced the 1.375× JEs. The compensating JE resolves the symptom; the investigation finds and fixes the root cause so it doesn't recur.
4. If QBO totals also show the 1.375× pattern (unlikely but possible — would mean FF pushed the inflated total to QBO and QBO accepted): root cause is elsewhere; escalate.

**Stop-gate:** if neither case resolves cleanly, S-QBO-27 HARD STOPS and reports — does not post compensating JEs that mask an unknown active bug.

### 16.4 The pull strategy

S-QBO-27 is the biggest session in Phase QBO. It runs in phases:

**Phase 27.A: Dry run on sandbox.**
- Connect to sandbox QBO file (which the accountant pre-seeded with a subset of real data).
- Pull customers, vendors, items, accounts, tax codes (reference data).
- Pull last 90 days of invoices, payments, etc.
- Verify mapping works.
- Verify drift computation matches expectations.

**Phase 27.B: Full sandbox run.**
- Same as 27.A but pull ALL historical data.
- Measure runtime (target: <6 hours).
- Verify post-pull FF state is internally consistent.
- Run drift detection — should report 0 drift.

**Phase 27.C: Production dry run.**
- Connect to production QBO file (read-only — no FF writes yet, just verify pull works).
- Confirm entity counts match expectations.

**Phase 27.D: Production execution.**
- Pull everything from production QBO.
- Apply H5 + H6 compensating JEs.
- Verify post-pull state.

**Phase 27.E: Verification.**
- Run drift detection — should report 0 drift on critical entities.
- Operator and accountant review summary report.

### 16.5 Idempotency and resumability

The pull is checkpoint-based. Every batch of 100 entities is committed independently and the last successful pull timestamp stored. If the pull fails mid-run:

```sql
SELECT MAX(pushed_at) FROM acc_qbo_customer_map WHERE realm_id = ?;
SELECT MAX(pushed_at) FROM acc_qbo_invoice_map WHERE realm_id = ?;
-- etc per entity type
```

Resume from these timestamps. Re-running an already-pulled batch is safe — the idempotency check (mapping table existence) prevents duplicates.

### 16.6 Pull entity order

Strict order required because later entities reference earlier ones:

1. Accounts (via S-QBO-8 mapping; should already be in place).
2. Tax codes (S-QBO-9).
3. Items (S-QBO-10).
4. Customers.
5. Vendors.
6. All invoices (oldest to newest).
7. All bills.
8. All payments.
9. All bill payments.
10. All credit memos.
11. All refund receipts.
12. All journal entries.
13. (Optionally) all transfer / deposit / purchase transactions for FF bank module.

### 16.7 Conflict resolution during pull

If a pulled QBO invoice conflicts with an existing FF invoice (same DocNumber, different content):

- Default: QBO wins for historical invoices (FF didn't have this invoice or its representation differed; QBO is source of truth for history).
- Override: operator can mark specific FF invoices as "preserve FF version" before the pull starts.

Logged extensively to `acc_qbo_sync_log`.

---

## 17. PRODUCTION CUTOVER RUNBOOK

S-QBO-29 and S-QBO-30 are the production cutover sessions. The runbook is detailed here so operator and accountant know exactly what to expect.

### 17.1 Pre-cutover checklist

Before S-QBO-29 starts, every item below must be ✅:

- [ ] Sandbox connection has run for at least 2 weeks with zero unresolved drift.
- [ ] Historical pull (S-QBO-27) completed successfully on sandbox.
- [ ] AR drift = $0.00 ± $1 on sandbox (H5 + H6 resolved per §16.3).
- [ ] AP drift = $0.00 ± $1 on sandbox.
- [ ] All four crons scheduled and running: `qbo_token_refresh`, `qbo_sync_worker`, `qbo_bank_cdc`, `qbo_drift_check`.
- [ ] Webhook endpoint registered with Intuit (production app) and webhook deliveries tested.
- [ ] Production OAuth credentials in place.
- [ ] All operator-side PREDEPLOY_CHECKLIST.md items addressed.
- [ ] Accountant has been briefed on workflow shift and has signed off.
- [ ] Backup of production FF database taken (snapshot via Lightsail console).
- [ ] Backup of production QBO data exported (via QBO's data export, retained as ZIP).

### 17.2 S-QBO-29 — Production OAuth + Realm Switch

**Duration:** ~2 hours (mostly verification).

1. Register production Intuit Developer app:
   - App name: `FleetForge — Mainland`.
   - Production OAuth credentials issued.
   - Webhook URL configured: `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`.
   - Verifier token captured.
2. In FF Settings → QuickBooks:
   - Flip `quickbooks.environment` to 'production'.
   - Enter production Client ID, Client Secret.
   - Click "Connect to Production QBO."
3. OAuth flow:
   - Operator logged in as super_admin.
   - Operator (with accountant on call) authorizes the app in Intuit.
   - Realm ID captured.
4. Verify connection:
   - Click "Test Connection" — verify CompanyInfo round-trip.
5. Settings `quickbooks.sync_enabled` remains '0' (kill-switch still on).
6. Sandbox connection remains active in case rollback needed.
7. Operator and accountant verify Settings UI shows production realm + connection healthy.

### 17.3 S-QBO-30 — Cutover Dry Run + Execution + Monitoring

**Duration:** ~1 day for dry run + cutover + first 24-hour monitoring window. 14 days for full monitoring period.

**Phase 30.A: Production dry run (1 day).**

1. With `quickbooks.sync_enabled = '0'`, run a manual push of a single test entity:
   - Operator creates a test customer in FF named "QBO Test Customer 2026-05-XX".
   - Manually triggers push via Settings → QuickBooks → Manual Sync.
   - Verifies customer appears in production QBO.
   - Verifies customer can be voided/deleted from QBO and re-created (idempotency check).
2. Repeat for test invoice, test payment, test JE.
3. Verify webhook flow: process a $1.00 test payment via QBO Payments hosted page → confirm webhook fires → confirm FF receives payment → confirm FF AR clears.
4. Verify drift detection cron runs and reports parity.
5. Soak test: leave for 24 hours, observe whether any background activity surfaces issues.

**Phase 30.B: Production historical pull.**

1. Run S-QBO-27 historical pull against production QBO.
2. Estimated runtime: 4-6 hours.
3. Operator and accountant verify post-pull state via drift dashboard.
4. AR drift = $0.00 ± $1. AP drift = $0.00 ± $1. GL account balances match within tolerances.

**Phase 30.C: Enable sync.**

1. Flip `quickbooks.sync_enabled` to '1'.
2. All new FF events now push to production QBO.
3. Existing queue items (any from pre-enable batched events) begin processing.
4. Operator and accountant monitor closely for the next 24 hours.

**Phase 30.D: First 24-hour monitoring.**

- Sentry dashboard reviewed every 2 hours.
- Drift dashboard reviewed every 4 hours.
- Sync log reviewed for any error patterns.
- Any single permanent failure escalated immediately.
- Operator and accountant on standby for any urgent fix.

**Phase 30.E: Days 2-14 monitoring.**

- Daily drift review.
- Daily sync error review.
- Weekly accountant check-in: "Does QBO match what you expect to see?"
- Any unresolved drift after 48 hours flagged for investigation.

### 17.4 Rollback procedure

If cutover encounters insurmountable problems:

1. Flip `quickbooks.sync_enabled = '0'` immediately (stops new pushes).
2. Don't roll back FF data — FF remains canonical regardless.
3. Investigate: which entity type is failing? What's the error category?
4. If issue is local (a specific bug in FF push logic), fix and re-enable.
5. If issue is QBO-side (e.g., realm permissions, tax code mismatch), resolve with accountant.
6. Cutover can be re-attempted any time after fix.

The architecture's design protects against catastrophic rollback: FF is canonical, so even if QBO sync is fully turned off, FF continues to operate. QBO mirror just becomes stale. Re-enabling sync catches it up.

### 17.5 Post-cutover state

After cutover:

- FF is the operational system. Operators and accountant work in FF.
- QBO is the mirror. Accountant uses QBO for the three exception domains (bank reconciliation, GST filing, payment processing).
- Drift detection runs nightly forever.
- Sync queue processes every 1 minute forever.
- Token refresh runs daily forever.
- QBO webhook receives payment notifications continuously.

The integration is "always on" from this point forward.

---

*End of Part 3. Part 4 covers §18-29: CRA defensibility, UI surfaces, cron jobs, schema, edge cases, security, settings, notifications, permissions, open questions, glossary, changelog.*

---


## 18. CRA DEFENSIBILITY

The integration design preserves and strengthens FleetForge's CRA defensibility story. The QBO mirror is supplementary, not a replacement for FF's primary CRA-grade audit trail.

### 18.1 Primary CRA evidence (FF-only, unchanged by QBO integration)

- **D14 — Sent invoice immutability.** FF sent invoices cannot be modified. Period.
- **D15 — Gap-free invoice numbering.** `INV-YYYY-NNNNN` with no gaps.
- **D16 — bcmath-only monetary math.** No float drift.
- **Source document attachments** via `acc_documents` (after S-ACCT-FIX-DOCS in Phase A). Every JE can attach the supporting document.
- **Audit log** on every entity create/update/delete via `audit_log` table.
- **Period locking** prevents posting to closed periods.
- **JE balanced-pair enforcement** at JournalEntryService level.

### 18.2 QBO mirror as supplementary evidence

QBO is a mirror but it's still real-time evidence:

- Every FF JE pushed to QBO is independently auditable in QBO.
- QBO has its own audit log (visible in QBO Activity Log).
- Two independent systems agreeing on every transaction is stronger than one system.
- During an audit, FF is presented as the primary record with QBO as corroboration.

### 18.3 Audit trail for QBO sync events

`acc_qbo_sync_log` retains every API call to QBO for 365 days (per §6.5). An auditor asking "show me proof that invoice INV-2026-00042 was pushed to QBO" can be served:

- The `acc_qbo_invoice_map` row showing FF id → QBO id linkage with timestamp.
- The `acc_qbo_sync_log` row showing the exact API call and response.
- The QBO entity itself (via QBO web UI or API call).

Triple corroboration on every transaction.

### 18.4 Tax filing defensibility

GST34 filing happens through QBO's NETFILE integration. FF generates the line-by-line breakdown (§23.7 of accounting spec) and posts the remittance JE. The accountant files through QBO.

CRA audit position: "FF computed the tax based on its tax engine (place-of-supply rules per §23.6, multi-rate effective-date logic). QBO accepted FF's computed amounts via the override pattern. The remittance JE in FF posts to GL. QBO files through NETFILE. Trail is complete."

### 18.5 Reversal and adjustment trail

When FF reverses a JE (e.g., a posted JE later found to be wrong):

- FF posts a reversing JE with explicit `reversal_of_id` link.
- QBO sync mirrors the reversing JE.
- Both systems show original JE + reversing JE with the linkage.
- Auditor can trace the full history.

CRA cannot challenge a transaction whose reversal is properly documented.

---

## 19. UI SURFACES

### 19.1 Sidebar navigation

New parent in admin sidebar **above Accounting**:

```
QuickBooks
├── Dashboard
├── Sync Log
├── Drift Detection
├── Customers Sync
├── Vendors Sync
├── Invoices Sync
├── Payments Sync
├── Bills Sync
├── Journal Entries Sync
├── Bank Mirror
├── Manual Sync
```

Permission gate: `quickbooks` module key required. Default grants: super_admin, accountant. Manager and operator do NOT have access (these are accounting-domain pages).

### 19.2 Settings → QuickBooks tab

The Settings UI gets a new tab at the top level: `Settings → QuickBooks`. Layout:

**Connection Card:**
- Environment toggle: Sandbox / Production.
- Client ID (masked, last 4).
- Client Secret (masked, last 4).
- Realm ID (full).
- Connection status badge: Connected / Disconnected / Expired / Error.
- Last connected at.
- Refresh token countdown: "Expires in 87 days" (green > 30d, yellow 14-30d, red < 14d).
- Buttons: Connect / Disconnect / Reconnect / Refresh Token Now.

**Sync Status Card:**
- Per-entity sync metrics: count synced today, count failed today, count in queue.

**Sync Configuration Card:**
- Mode per entity (sync / queue).
- CDC poll interval for bank feed.
- Drift tolerance per entity.

**Mapping Tables Card:**
- Links to: Account Mapping, Tax Code Mapping, Item Mapping, Customer Mapping, Vendor Mapping.

**Maintenance Card:**
- Force full reconciliation.
- Test connection (CompanyInfo round-trip).
- Refresh token now.
- Clear queue (super_admin only — emergency tool).

**Diagnostics Card (super_admin only):**
- API call counter (last 24h).
- 429 hits (last 24h).
- 5xx hits (last 24h).
- Raw request log for the last 100 calls.
- Toggle: enable debug logging (extra verbose for next 24h).

### 19.3 Drift Detection dashboard

`app/admin/quickbooks/drift/index.php`:

- **Summary tiles** at top: Open drift events count, accepted divergences count, suppressed count.
- **Filter controls**: entity type, drift category, status, date range.
- **Drift events table**: detected_at, entity_type, drift_category, FF value, QBO value, drift_amount, status, actions.
- **Action buttons per row**: Resolve via FF (re-sync), Resolve via QBO (notes), Accept divergence, Suppress.
- **Bulk actions**: select multiple, bulk resolve/accept/suppress.

### 19.4 Sync Log viewer

`app/admin/quickbooks/sync-log/index.php`:

- **Search**: entity type, entity ID, QBO entity ID, error code, error message.
- **Filters**: direction (push/pull), date range, success/failure.
- **Table**: created_at, entity, operation, status, duration, error_code.
- **Per-row drill-down**: full request payload, full response payload, full error stack (super_admin only sees payloads).
- **Retry button** per failed row.

### 19.5 Per-entity sync status pages

`app/admin/quickbooks/customers/index.php`, etc. Each entity type has its own sync status page:

- **Mapping table**: FF entity → QBO entity → last synced → sync status.
- **Filter**: synced / queued / failed / unmapped.
- **Actions**: re-sync, view in FF, view in QBO (via deep-link to QBO web app).

### 19.6 Manual Sync page

`app/admin/quickbooks/manual-sync/index.php`:

- **Force sync individual entity**: pick entity type + ID → trigger immediate sync.
- **Force full entity-type sync**: select entity type → confirm → enqueue all entities for re-push.
- **Force pull from QBO**: select entity type → confirm → pull current state from QBO and update FF map.

Used after manual edits in QBO or after issues requiring forced re-sync.

### 19.7 Entity show-page sync badge

Every relevant FF entity show-page (invoice, payment, customer, vendor, bill, JE) gets a small "Synced to QBO" badge near the top:

- Green checkmark + "Synced 2 hours ago" → all good.
- Yellow exclamation + "Sync pending" → queued, not yet pushed.
- Red X + "Sync failed — see error" → permanent failure, link to sync log.
- Gray dash + "Not synced" → not yet pushed (e.g., draft).

Click badge → opens modal with sync history for this entity + force re-sync button.

### 19.8 Customer portal "Pay Online" button

On `app/portal/invoices/show.php`:

- Visible when invoice status IN ('sent', 'partially_paid', 'overdue') AND `quickbooks.payments_enabled='1'`.
- Button: blue, prominent, labeled "Pay Online with Credit Card."
- Click → initiates QBO Payments hosted page redirect (§11).
- Below button: small text "Powered by QuickBooks Payments. Your card details are processed securely by Intuit."

---

## 20. CRON JOBS

All QBO-related crons in one place. Add to crontab during S-QBO-1 setup.

| Cron | Schedule | Purpose |
|---|---|---|
| `cron/qbo_token_refresh.php` | Daily at 02:00 | Refresh OAuth tokens; alert if < 14 days to expiry |
| `cron/qbo_sync_worker.php` | Every 1 minute | Process queued push jobs |
| `cron/qbo_bank_cdc.php` | Daily at 02:30 | Pull bank transactions from QBO (Exception #2) |
| `cron/qbo_drift_check.php` | Daily at 03:30 | Detect and surface drift between FF and QBO |
| `cron/qbo_webhook_replay.php` | Every 15 minutes | Reprocess any webhook events stuck in `received` status |
| `cron/qbo_payment_initiation_cleanup.php` | Daily at 04:00 | Clean up stale (>24h) payment initiation records |

**Crontab entries:**

```
0  2 * * * php /var/www/fleetforge/cron/qbo_token_refresh.php
*  * * * * php /var/www/fleetforge/cron/qbo_sync_worker.php
30 2 * * * php /var/www/fleetforge/cron/qbo_bank_cdc.php
30 3 * * * php /var/www/fleetforge/cron/qbo_drift_check.php
*/15 * * * * php /var/www/fleetforge/cron/qbo_webhook_replay.php
0  4 * * * php /var/www/fleetforge/cron/qbo_payment_initiation_cleanup.php
```

All crons use advisory locks to prevent double-running (D21 pattern).

---

## 21. SCHEMA ADDITIONS

Consolidated list of all new tables and column additions for the QBO integration. These get created across S-QBO-1 through S-QBO-30 sessions.

### 21.1 New tables

| Table | Created in | Purpose |
|---|---|---|
| `acc_qbo_sync_queue` | S-QBO-4 | Outbound push queue |
| `acc_qbo_sync_log` | S-QBO-4 | API call audit log |
| `acc_qbo_drift_events` | S-QBO-4 | Drift detection events |
| `acc_qbo_webhook_events` | S-QBO-15 | Incoming webhook event log |
| `acc_qbo_payment_initiations` | S-QBO-15 | Outbound payment initiation tracking |
| `acc_qbo_account_map` | S-QBO-8 | Account ID mapping |
| `acc_qbo_tax_code_map` | S-QBO-9 | Tax code ID mapping |
| `acc_qbo_item_map` | S-QBO-10 | Item / line type mapping |
| `acc_qbo_customer_map` | S-QBO-5 | Customer ID mapping |
| `acc_qbo_vendor_map` | S-QBO-7 | Vendor ID mapping |
| `acc_qbo_invoice_map` | S-QBO-11 | Invoice ID mapping + drift tracking |
| `acc_qbo_payment_map` | S-QBO-13 | Payment ID mapping |
| `acc_qbo_credit_memo_map` | S-QBO-16 | Credit memo ID mapping |
| `acc_qbo_refund_receipt_map` | S-QBO-17 | Refund ID mapping |
| `acc_qbo_bill_map` | S-QBO-18 | Bill ID mapping |
| `acc_qbo_bill_payment_map` | S-QBO-19 | Bill payment ID mapping |
| `acc_qbo_journal_entry_map` | S-QBO-21 | JE ID mapping |
| `acc_qbo_bank_account_map` | S-QBO-20 | Bank account ID mapping |
| `acc_qbo_bank_transaction_map` | S-QBO-20 | Bank transaction mapping |
| `acc_qbo_fixed_asset_map` | S-QBO-22 | Fixed asset reference (limited) |

Total new tables: 20.

### 21.2 Column additions to existing tables

| Table | Column | Type | Purpose |
|---|---|---|---|
| `payments` | `origin` | ENUM('ff','qbo_payments_webhook','qbo_other') | Track payment source |
| `acc_bank_transactions` | `source` | ENUM('ff','qbo_cdc') | Distinguish FF-originated vs QBO-mirror |
| `acc_bank_transactions` | `is_readonly` | TINYINT(1) | Mark QBO-mirrored as read-only |
| `acc_bank_transactions` | `qbo_bank_txn_id` | VARCHAR(50) | Round-trip ID |
| `customers` | `gps_revenue_presentation` | ENUM('net','gross') | §23.8 of accounting spec, also affects QBO Item mapping |

### 21.3 Settings keys (consolidated)

See §24 for complete settings reference.

---

## 22. EDGE CASES AND HOW WE HANDLE THEM

### 22.1 Customer renamed in QBO

**Scenario:** Accountant renames a customer in QBO. FF has the old name.

**Detection:** Drift cron detects field mismatch.

**Resolution:** Drift event shows old/new names. Operator confirms in FF (or accepts divergence if intentional). FF customer update triggers re-push which corrects QBO if FF was right.

### 22.2 Invoice modified in QBO after sync

**Scenario:** Accountant edits a sent FF invoice's customer-facing memo directly in QBO.

**Detection:** Drift cron detects difference.

**Resolution:** Operator decides per-invoice. Most likely action: re-sync from FF (FF is canonical, FF's memo wins). Or update FF memo to match QBO (then re-sync, which is a no-op since they match).

### 22.3 QBO Payment for nonexistent FF invoice

**Scenario:** Webhook fires for a QBO payment whose LinkedTxn references an Invoice ID not in `acc_qbo_invoice_map`.

**Possible causes:**
- Accountant manually created an invoice in QBO that's not in FF.
- The invoice mapping was somehow lost.
- The QBO Invoice ID is from a different realm (shouldn't happen — realm verified in handler).

**Resolution:** Handler returns 'no_ff_invoice_mapped'. Notification dispatched to accountant. Payment sits in QBO unallocated; operator and accountant decide whether to create the missing FF invoice or accept the divergence.

### 22.4 Same payment via QBO Payments + manual FF entry

**Scenario:** Customer pays via QBO Payments → webhook creates FF payment. But the operator was unaware and also manually recorded a payment in FF for the same invoice.

**Detection:** Drift cron detects two FF payments allocated to the same invoice with overlapping amount.

**Resolution:** Operator reviews. Deletes the duplicate FF payment (with audit trail). Re-syncs the canonical record.

**Prevention:** the operator-facing FF UI for manual payment shows recent QBO Payments activity for the customer to reduce risk.

### 22.5 Refund receipt for invoice that was never invoiced through QBO

**Scenario:** Accountant refunds a deposit that FF tracked but never invoiced through QBO.

**Resolution:** FF creates the refund receipt in QBO as a standalone document (no linked Invoice). QBO accepts. JE pushes separately.

### 22.6 Tax code deleted in QBO

**Scenario:** Accountant deletes a tax code in QBO that FF was mapped to.

**Detection:** Next push fails with 'invalid_reference' on tax code.

**Resolution:** Notification: "Tax code X deleted in QBO. Re-map tax_rates row Y in Settings → QuickBooks → Tax Codes." Operator re-maps. Queue resumes.

### 22.7 Multiple QBO realms (shouldn't happen)

**Scenario:** Settings somehow have two realm IDs.

**Prevention:** `quickbooks.realm_id` is a singleton setting. Connection flow rejects connecting to a different realm without explicit disconnect first.

**Detection:** If somehow corrupted, sync calls fail because credentials don't match realm.

**Resolution:** Realm-change runbook (§5.4).

### 22.8 Sandbox accidentally connected in production

**Scenario:** Operator clicks "Connect" while environment is set to sandbox; intends production.

**Prevention:** Connection UI shows large environment badge ("PRODUCTION" or "SANDBOX") at top. Pre-connection confirmation dialog: "You are about to connect to {ENVIRONMENT}. Are you sure?"

**Detection:** Realm IDs differ between sandbox and production. UI surfaces realm ID.

**Resolution:** Disconnect, switch environment, reconnect.

### 22.9 Webhook delivery race condition

**Scenario:** QBO sends webhook delivery 1, FF starts processing. Delivery 2 (retry) arrives before FF returns 200. Both processing concurrently.

**Prevention:** Idempotency check (§12.4 step 4) catches the duplicate. The second handler returns 200 immediately without re-processing.

### 22.10 Time-zone drift between FF and QBO

**Scenario:** FF stores TxnDate as 2026-04-30 (Pacific). QBO interprets the API value differently if no time-zone tag.

**Prevention:** All TxnDate values sent as 'YYYY-MM-DD' without time. QBO's company time-zone is set in QBO settings (matches FF: America/Vancouver). QBO date interpretation consistent.

### 22.11 Invoice voided in FF after payment cleared in QBO

**Scenario:** Customer pays via QBO Payments. Days later, operator voids the FF invoice (e.g., due to billing dispute).

**Detection:** QBO Invoice void API call fails because the invoice has payments.

**Resolution:**
- Void fails. Push status='failed'.
- Notification: "Cannot void invoice INV-XXX in QBO — it has been paid. Operator: refund the payment first if appropriate, or unvoid the FF invoice."
- Operator and accountant coordinate on next steps.

### 22.12 Currency mismatch on payment

**Scenario:** USD invoice paid in CAD (rare, but possible if customer pays from CAD account).

**Detection:** QBO Payment has different currency than the FF invoice it allocates to.

**Resolution:** Handler captures both currencies, posts FX conversion JE, allocates the CAD-equivalent to the USD invoice. Notification to accountant for verification.

### 22.13 Class or Location data exists in QBO

**Scenario:** Accountant has been using QBO Classes (e.g., for departmentalization) and expects new FF-pushed invoices to use them.

**Resolution:** Q-CPA-1 captures this at pre-Phase-QBO briefing. If yes, S-QBO-11 invoice push includes the appropriate Class reference (`ClassRef` in invoice payload). Class mapping table needed (new requirement; would be added in a follow-up).

**Default assumption:** Mainland is small enough not to use Classes. If discovered to be in use, S-QBO-11 scope expands.

---

## 23. SECURITY AND COMPLIANCE

### 23.1 PCI-DSS scope

**FleetForge is OUT of PCI-DSS scope.** Per §11.4, QBO Payments hosts the card form; FF never touches card data.

The only payment-related data FF stores:
- QBO-assigned payment ID.
- Last 4 of card (returned by QBO; safe to store).
- Card brand (Visa/MC/Amex).
- Authorization code.
- Amount, currency, timestamp.

None of this is in PCI-DSS scope.

### 23.2 OAuth credentials

- Stored in `settings` table with `is_sensitive=1`.
- Access controlled via `is_sensitive` flag — only super_admin can read sensitive values.
- Never logged.
- Never returned in API responses.
- UI display masked to last 4 chars only.

### 23.3 At-rest encryption

The settings table currently is not field-encrypted at rest (it's protected by MySQL access control + Lightsail volume encryption). For S-QBO-1 going forward, sensitive OAuth credentials could be encrypted with a key derived from `APP_KEY` if Avi wants extra protection (locked as future decision; default is reliance on existing protections).

### 23.4 In-transit encryption

All QBO API calls over HTTPS (TLS 1.2+). All webhook deliveries over HTTPS. Certificate pinning not required (Intuit's certificate stable).

### 23.5 Webhook signature verification

Required on every webhook. Failure = 403 return + Sentry alert. No exceptions.

### 23.6 Audit log retention

`acc_qbo_sync_log` retained 365 days; archived to S3 cold storage after that for CRA-mandated 7-year retention. `audit_log` table (FF-side) retained indefinitely per existing policy.

### 23.7 Access control

QBO module pages access-gated by `quickbooks` module key. Default grants:

- super_admin: full access.
- accountant: full access except super_admin-only diagnostics.
- manager: no access.
- operator: no access.
- portal_user: no access (different role surface; only portal "Pay Online" feature).

### 23.8 Data residency

QBO Online's data is in the U.S. or Canada depending on Intuit's region routing. Intuit confirms Canadian-domiciled QBO files are stored in Canada. Customer data flowing FF → QBO is therefore not crossing border in any unexpected way (FF on Lightsail Oregon → QBO Canada/US).

For PIPEDA compliance: Mainland's customer data is in Canada (FF + QBO Canada). Customers are notified in the privacy policy that their data may be processed by service providers.

---

## 24. SETTINGS KEYS

Complete reference of all QBO-related settings.

### 24.1 Connection / OAuth

| Key | Type | Default | Sensitive | Notes |
|---|---|---|---|---|
| `quickbooks.environment` | string | 'sandbox' | No | 'sandbox' or 'production' |
| `quickbooks.client_id` | string | '' | Yes | from Intuit Developer |
| `quickbooks.client_secret` | string | '' | Yes | from Intuit Developer |
| `quickbooks.realm_id` | string | '' | No | Company file ID |
| `quickbooks.access_token` | string | '' | Yes | Auto-refreshed |
| `quickbooks.refresh_token` | string | '' | Yes | Rotated on every refresh |
| `quickbooks.access_token_expires_at` | datetime | NULL | No | |
| `quickbooks.refresh_token_expires_at` | datetime | NULL | No | |
| `quickbooks.last_connected_at` | datetime | NULL | No | |
| `quickbooks.last_token_refresh_at` | datetime | NULL | No | |
| `quickbooks.connection_status` | string | 'disconnected' | No | |
| `quickbooks.connection_error` | string | '' | No | |
| `quickbooks.webhook_verifier_token` | string | '' | Yes | From Intuit webhook config |
| `quickbooks.tax_override_code_id` | string | '' | No | QBO 'NON' tax code ID for override pattern |

### 24.2 Master controls

| Key | Type | Default | Notes |
|---|---|---|---|
| `quickbooks.sync_enabled` | boolean | '0' | Master kill-switch. '0' = no sync; '1' = sync active |
| `quickbooks.payments_enabled` | boolean | '0' | "Pay Online" button visibility |
| `quickbooks.dry_run_mode` | boolean | '0' | When '1', pushes are logged but not actually sent to QBO. For testing |

### 24.3 Per-entity sync mode

| Key | Type | Default | Notes |
|---|---|---|---|
| `quickbooks.sync_mode.customer` | enum | 'sync' | sync / queue |
| `quickbooks.sync_mode.vendor` | enum | 'sync' | |
| `quickbooks.sync_mode.invoice` | enum | 'queue' | (sync if user-initiated) |
| `quickbooks.sync_mode.payment` | enum | 'sync' | |
| `quickbooks.sync_mode.credit_memo` | enum | 'sync' | |
| `quickbooks.sync_mode.refund_receipt` | enum | 'sync' | |
| `quickbooks.sync_mode.bill` | enum | 'queue' | |
| `quickbooks.sync_mode.bill_payment` | enum | 'queue' | |
| `quickbooks.sync_mode.journal_entry` | enum | 'sync' | |
| `quickbooks.sync_mode.depreciation_je` | enum | 'queue' | |
| `quickbooks.sync_mode.recurring_je` | enum | 'queue' | |
| `quickbooks.sync_mode.tax_remittance_je` | enum | 'sync' | |
| `quickbooks.sync_mode.year_end_closing_je` | enum | 'sync' | |

### 24.4 Drift tolerance

| Key | Type | Default | Notes |
|---|---|---|---|
| `quickbooks.drift_tolerance.customer` | decimal | 0.00 | Exact match |
| `quickbooks.drift_tolerance.vendor` | decimal | 0.00 | |
| `quickbooks.drift_tolerance.invoice` | decimal | 0.05 | Tax rounding |
| `quickbooks.drift_tolerance.payment` | decimal | 0.01 | |
| `quickbooks.drift_tolerance.credit_memo` | decimal | 0.05 | |
| `quickbooks.drift_tolerance.bill` | decimal | 0.05 | |
| `quickbooks.drift_tolerance.gl_account` | decimal | 1.00 | Aggregate per account |

### 24.5 Retry and rate limiting

| Key | Type | Default | Notes |
|---|---|---|---|
| `quickbooks.retry.max_attempts` | int | 5 | After this, queue status='failed' |
| `quickbooks.retry.backoff_base_seconds` | int | 60 | |
| `quickbooks.rate_limit.throttle_threshold` | int | 10 | Pause when RateLimit-Remaining < N |
| `quickbooks.rate_limit.throttle_seconds` | int | 30 | Pause duration when throttled |

### 24.6 CDC and webhook

| Key | Type | Default | Notes |
|---|---|---|---|
| `quickbooks.cdc.bank_poll_interval_minutes` | int | 1440 | 24 hours = once daily |
| `quickbooks.cdc.last_bank_pull_at` | datetime | NULL | Watermark for resumability |
| `quickbooks.webhook.replay_window_hours` | int | 24 | Reprocess webhooks stuck this long |

---

## 25. NOTIFICATIONS

The QBO integration adds a new notification category and several specific notification types.

### 25.1 Category

`quickbooks` — new notification category. Per the S-NOTIF-1 routing system:

Default recipients:
- super_admin: all severities.
- accountant: all severities.
- manager: none (intentional — QBO drift is accounting-domain).

### 25.2 Notification types

| Type | Severity | Trigger | Message |
|---|---|---|---|
| `quickbooks.connection_at_risk` | High | Refresh token < 14 days to expiry | "QBO connection expires in N days. Re-authorize at Settings → QuickBooks." |
| `quickbooks.connection_expired` | Critical | Token refresh failed | "QBO connection expired. Sync paused. Re-authorize at Settings → QuickBooks." |
| `quickbooks.push_failed` | High | Queue entry failed after max retries | "QBO sync failed for {entity} #{id}. Reason: {error}. View at /quickbooks/sync-log/." |
| `quickbooks.drift_detected` | Medium | Drift > threshold detected by cron | "QBO drift detected: {category} affecting {count} entities, total drift {amount}. View at /quickbooks/drift/." |
| `quickbooks.rate_limit_hit` | Low | 429 received from QBO | "QBO rate limit hit. Sync throttled. No action needed." |
| `quickbooks.webhook_signature_failed` | Critical | Webhook with bad signature | "QBO webhook signature mismatch (potential security event). Verify webhook config." |
| `quickbooks.webhook_unprocessable` | High | Webhook for unmappable entity | "Received QBO payment webhook for invoice not in FF. Manual review needed." |
| `quickbooks.cutover_complete` | Info | Cutover successful | "QBO production cutover complete. Sync active." |

All notifications include:
- Link to the relevant FF page for action.
- Timestamp.
- Severity badge.
- "Dismiss" and "Mark resolved" actions.

---

## 26. PERMISSIONS AND ROLES

### 26.1 Module key

New module key in the `modules` table: `quickbooks`.

### 26.2 Role grants

| Role | quickbooks module access |
|---|---|
| super_admin | Full (view, edit, manage credentials, force actions) |
| accountant | Full except super_admin-only diagnostics + clear queue |
| manager | None (cannot see QuickBooks pages in sidebar) |
| operator | None |
| viewer | None |
| portal_user | None (separate role surface) |

### 26.3 Action-level granularity

Within `quickbooks` module:

- View pages: all granted (super_admin, accountant).
- Force re-sync entity: granted (super_admin, accountant).
- Force full re-sync entity type: super_admin only.
- Clear queue: super_admin only.
- Disconnect: super_admin only.
- Edit credentials: super_admin only.
- View raw API payloads in sync log: super_admin only (may contain sensitive data).
- View masked payloads (last 4 chars only): accountant.

### 26.4 Audit log

Every QBO action logged to `audit_log` with `module='quickbooks'`. Includes the actor's user_id, entity, operation, timestamp, before/after values where applicable.

---

## 27. OPEN QUESTIONS

These need operator/accountant input before specific sessions can fully proceed. Track in `FLEETFORGE_CURRENT_SESSIONS.md` or this section as they're resolved.

### 27.1 Pre-Phase QBO accountant conversation

Q-CPA-1 through Q-CPA-7 from the master roadmap §17.2:

| ID | Question | Blocks |
|---|---|---|
| Q-CPA-1 | Does the accountant currently use QBO Classes or Locations? | S-QBO-11 |
| Q-CPA-2 | QBO tier confirmed (Plus)? Any add-on integrations active? | S-QBO-1 |
| Q-CPA-3 | Custom fields, custom tax codes, custom GL accounts already in your QBO file? | S-QBO-8, S-QBO-9 |
| Q-CPA-4 | Does the accountant ever create invoices directly in QBO that don't originate in FF? | S-QBO-12 |
| Q-CPA-5 | Acceptable bill-entry workflow shift (FF instead of QBO)? | Phase QBO start |
| Q-CPA-6 | Compilation vs review engagement target? | Phase E scope |
| Q-CPA-7 | Sign-off on lease classification wizard before first sales-type lease activates | S-ACCT-LESSOR-3 |

### 27.2 Resolved at S-QBO-1

- Sandbox app credentials obtained.
- Production app credentials obtained.
- Webhook URL configured in Intuit Developer.
- OAuth scopes confirmed.

### 27.3 Resolved at S-QBO-10

- D-QBO-10-1: `base_rental_reconciliation_credit` representation (dedicated Item; recommendation locked in spec).

### 27.4 Resolved at S-QBO-15

- D-QBO-15-1 through 15-4: webhook signature verification approach, retry policy, FF-side timing, return URL format.

### 27.5 Resolved at S-QBO-27

- D-QBO-27-1 through 27-6: pull batch size, resumability checkpoint, dry-run gating, H5 reconstruction approach, H6 stop-gate criteria, AR verification.

---

## 28. GLOSSARY

| Term | Meaning |
|---|---|
| **CDC** | Change Data Capture. QBO endpoint for fetching modified records since a timestamp |
| **D-QBO-N-*** | Locked decision identifier for QBO sessions |
| **DisplayName** | QBO's customer/vendor display name field (must be unique within realm) |
| **Fault** | QBO's structured error response object |
| **HMAC-SHA256** | The hash algorithm Intuit uses for webhook signatures |
| **Intuit Developer** | The developer portal at developer.intuit.com where apps are registered |
| **JournalEntry** | QBO entity type for raw journal entries (one of the entity types FF pushes) |
| **LinkedTxn** | QBO field linking entities (e.g., Payment.Line.LinkedTxn points to Invoice) |
| **NETFILE** | CRA's online tax filing system; QBO files GST34 through NETFILE |
| **NON** | QBO's built-in tax code meaning "no tax computed by QBO." Used in the override pattern |
| **OAuth 2.0** | The authorization protocol QBO uses |
| **PCI-DSS** | Payment Card Industry Data Security Standard |
| **QBO** | QuickBooks Online (Intuit's cloud accounting product) |
| **QBOA** | QuickBooks Online Accountant (practitioner portal); not used by FF |
| **QBO Payments** | Intuit's payment processing service; used for "Pay Online" feature |
| **Realm ID** | QBO's company file identifier; tied to a single QBO subscription |
| **Sandbox** | QBO's test environment; separate URL and credentials from production |
| **SyncToken** | QBO's optimistic-lock token. Required on every update; QBO rejects stale tokens |
| **Tax-override pattern** | FF's locked approach: compute tax FF-side, push to QBO via TxnTaxDetail.TotalTax with TaxCodeRef='NON' on lines |
| **TxnTaxDetail** | QBO's tax detail block on invoices, bills, credit memos, refunds |
| **Webhook** | Intuit's notification mechanism. FF receives one type: QBO Payments events |

---

## 29. CHANGELOG

### v1.0 (2026-05-18) — Initial canonical spec

Comprehensive specification for FleetForge ↔ QuickBooks Online integration. Covers:

- Master-mirror architecture with three documented exceptions (QBO Payments webhook, bank feed CDC pull, one-time reference data import).
- OAuth 2.0 flow with daily refresh-token pinger.
- Sync infrastructure: queue, log, drift events, worker, client class.
- Per-entity sync rules for 15 entity types.
- Tax handling with the tax-override pattern (FF computes, QBO accepts via TxnTaxDetail).
- Place-of-supply rule integration with §23.6 of accounting spec.
- Multi-currency via ASPE 1651 temporal method.
- QBO Payments embed via hosted-page redirect (out of PCI-DSS scope).
- Webhook signature verification with HMAC-SHA256.
- Error handling, retry policy, rate limiting.
- Drift detection with nightly reconciliation.
- Historical backfill (S-QBO-27) with H5 + H6 AR drift remediation absorbed (D-QBO-CORE-11).
- Production cutover runbook with 14-day monitoring window.
- CRA defensibility story preserved.
- 20 new mapping tables.
- 6 new crons.
- 13 D-QBO-CORE-* decisions locked.

Anticipates 28 effective sessions across S-QBO-1 through S-QBO-30 over 50-70 working days.

---

*End of FLEETFORGE_QUICKBOOKS_SPEC.md v1.0.*
*Implementation begins with S-QBO-1 after Phase A, Phase B, Phase C, Phase D complete per master roadmap dependency chain.*
*Companion: `FLEETFORGE_ACCOUNTING_QBO_ROADMAP_v1.1.md` §9 for session-by-session execution plan.*
