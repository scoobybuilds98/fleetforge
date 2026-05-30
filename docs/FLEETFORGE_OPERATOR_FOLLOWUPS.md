# FleetForge — Operator Follow-ups (Carry-over Tracking)

**Purpose:** canonical, persistent list of operator-side work items surfaced by shipped sessions that the operator must complete BEFORE live cutover OR that gate downstream functionality. Survives session-context-wipes (it's a file, not a memory). Updated mechanically at every session end (enforced by `tests/_smoke_doc_freshness.php` CLASS 13).

**Discipline:** every session that notes operator follow-ups in its SESSION LOG row MUST add or update matching entries here BEFORE commit. See `memory/feedback_operator_followups_tracking.md` for the rule + canonical incidents.

**Difference from `FLEETFORGE_PREDEPLOY_CHECKLIST.md`:** the predeploy checklist is pre-deploy infrastructure items (DNS, SSL, S3, SES sandbox approval, etc.) per K-14. This doc tracks operator follow-ups surfaced by SHIPPED sessions — things the operator must do to enable a feature that's already in code but blocked on operator action (Intuit dashboard registration, secret configuration, account mapping, etc.). Some items overlap and may also appear in PREDEPLOY_CHECKLIST.md — that's intentional cross-listing.

**Status legend:**
- 🔴 **BLOCKING** — live test or cutover cannot proceed without this
- 🟡 **PARTIAL** — operator can use the feature in degraded mode until completed
- 🟢 **DEFERRED** — queued for a future session; documented for tracking
- ✅ **CLOSED** — operator completed; moved to archive at bottom

**Last updated:** 2026-05-31 via S-QBO-CREDIT-MEMO-UPDATE (closed F20 pushUpdate stub; carved out F25 apply→LinkedTxn; updated F-PAYDOWN-PROGRESS — 4/5 update slices shipped).

---

## 🔴 BLOCKING — live test cannot proceed without operator action

### F1 — `quickbooks.webhook_verifier_token` is EMPTY

**Surfaced by:** S-QBO-13 (2026-05-27, commit 0d7175f) — payment pull webhook
**Affects:** S-QBO-13, S-QBO-15 (portal embed handshake), any future webhook
**Operator action:**
1. Configure webhook in Intuit Developer dashboard at `developer.intuit.com` → My Apps → FleetForge → Webhooks
2. Subscribe to `Payment.Create`, `Payment.Update`, `Payment.Void` events for the sandbox + production realms
3. Intuit generates a verifier token at subscription time
4. Copy the token into FF settings: `UPDATE settings SET value=? WHERE key='quickbooks.webhook_verifier_token'` (or via /admin/settings)
5. Verify via: `SELECT key, IF(value='','EMPTY','SET') FROM settings WHERE key='quickbooks.webhook_verifier_token'`

**Why blocking:** `QboWebhookSignature::verify()` fails-closed when verifier_token is empty per D-QBO-13-3 (constant-time HMAC compare against an unconfigured token would let any payload through). All inbound webhook events return 403 until configured.

**Without this:** S-QBO-15 portal "Pay Online" flow appears to work (URL generation succeeds) but customer payment is never reflected in FF — webhook handshake never completes, PaymentInitiator row stays `pending` indefinitely (until TTL expires).

---

### F2 — Webhook URL not registered in Intuit Developer dashboard

**Surfaced by:** S-QBO-13 (2026-05-27, commit 0d7175f)
**Affects:** S-QBO-13, S-QBO-15
**Operator action:**
1. In Intuit Developer dashboard, register webhook URL:
   - Sandbox: `https://<ngrok-tunnel>.ngrok.io/fleetforge/api/v1/webhooks/qbo_payment_notifications.php` during dev
   - Production: `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php` (cutover-time)
2. Subscribe to event types per F1
3. Verify Intuit pings the webhook URL with a test event (Intuit dashboard surfaces the test-ping result)

**Why blocking:** without registration, Intuit never delivers webhook events to FF. The endpoint exists + signature verification works + handler logic is shipped, but no events arrive.

**Note:** F1 + F2 are sequential — must configure F2 first (to get the verifier_token from Intuit) then F1 (to paste the token into FF settings).

---

### F3 — Intuit Payments API endpoint path needs live-test verification

**Surfaced by:** S-QBO-15 (2026-05-29, commit 96e52af) — D-QBO-15-2
**Affects:** S-QBO-15
**Operator action:**
1. Connect to sandbox realm `9341457119548719` (already connected per pre-flight)
2. Verify OAuth scope includes `com.intuit.quickbooks.payment` (verified at S-QBO-15 pre-flight: scope IS in `app/admin/oauth/qbo/init.php` line 82)
3. If existing tokens predate that scope, re-OAuth via /admin/oauth/qbo
4. First live test: have a portal user click "Pay Online" on a synced invoice (sandbox)
5. Inspect the cURL request in `acc_qbo_sync_log` — verify the endpoint path `POST {sandbox-api.intuit.com}/quickbooks/v4/payments/charges` matches Intuit's current API contract
6. If endpoint returns 404 or other API-shape error, adjust `QuickBooksClient::generatePaymentsHostedUrl` per Intuit's latest docs at `developer.intuit.com/payments`

**Why blocking** (for first live test only): the endpoint signature is documented per Intuit Payments API v4 but Intuit has historically renamed Payments endpoints between major versions. The OPERATOR LIVE-TEST NOTE in `QuickBooksClient::generatePaymentsHostedUrl` docblock flags this explicitly. The defensive response-key extraction handles minor variations but a fundamental path change requires manual update.

**Workaround:** offline development works fine — `tests/_smoke_qbo_payments_embed.php` covers all PaymentInitiator behavior without making the actual Intuit HTTP call.

---

### F4 — UndepositedFunds FF account not tagged + mapped

**Surfaced by:** S-QBO-14 (2026-05-28, commit 50295c9) — D-QBO-14-4
**Affects:** S-QBO-14 (PaymentPusher pushCreate live test)
**Operator action:**
1. Create or identify an FF Asset account for "Undeposited Funds" (e.g. code 1015 or similar)
2. In `/quickbooks/accounts` admin UI: tag this FF account's `critical_category='undeposited_funds'` (currently no FF account has this tag — verified via `SELECT * FROM acc_qbo_account_map WHERE critical_category='undeposited_funds'` returns empty)
3. Pull QBO accounts via /quickbooks/accounts → identify QBO's standard "Undeposited Funds" account (typically Id=4 in Craig's sandbox)
4. Map the FF UF account to the QBO UF account via the Save Mapping action
5. Verify: `AccountValidator::assertReadyForPaymentPush()` no longer throws (test via CLI: `php -r "require 'api/bootstrap.php'; \FleetForge\QboPushers\AccountValidator::assertReadyForPaymentPush(); echo 'OK';"`)

**Why blocking** (for S-QBO-14 live test only): PaymentPusher::runPreflight gate 2 calls AccountValidator::assertReadyForPaymentPush which throws ChartOfAccountsIncompleteException when UF is unmapped. First live FF→QBO payment push will fail at preflight with actionable error directing operator here.

**Without this:** S-QBO-14 PaymentPusher returns `failed_preflight` status on every push attempt; admin UI surfaces the actionable error.

**Note:** Webhook-pull payments (S-QBO-13 / S-QBO-15) don't need this — they go through QBO's own UF account, FF mirrors the payment without needing to specify a deposit destination.

---

### F12 — `tax_receivable` + `tax_payable` critical_category mappings required for JE push

**Surfaced by:** S-QBO-21 (2026-05-29) — D-QBO-VALIDATOR-3 + JournalEntryPusher::runPreflight gate 1
**Affects:** S-QBO-21 (JournalEntryPusher::pushCreate live test); future Phase QBO-11 sessions S-QBO-22 (depreciation JE) + S-QBO-23 (tax remittance JE) that flow through this Pusher.
**Operator action:**
1. Identify FF Asset account(s) representing tax receivable (e.g. GST/HST Input Tax Credits — typical codes 1310 / 1320 region)
2. In `/quickbooks/accounts` admin UI: tag each FF account's `critical_category='tax_receivable'`
3. Identify FF Liability account(s) representing tax payable (e.g. GST/HST collected — typical codes 2310 / 2320 region)
4. In `/quickbooks/accounts` admin UI: tag each FF account's `critical_category='tax_payable'`
5. Map each tagged account to the corresponding QBO account via the Save Mapping action
6. Verify: `AccountValidator::assertReadyForJournalEntryPush()` no longer throws (test via CLI: `php -r "require 'api/bootstrap.php'; \FleetForge\QboPushers\AccountValidator::assertReadyForJournalEntryPush(); echo 'OK';"`)

**Why blocking** (for S-QBO-21 live test only): JournalEntryPusher::runPreflight gate 1 calls AccountValidator::assertReadyForJournalEntryPush which throws ChartOfAccountsIncompleteException when either category is unmapped. First live FF→QBO JE push (depreciation, tax remittance, year-end, recurring, manual, AJE) will fail at preflight with actionable error directing operator to /quickbooks/accounts.

**Without this:** S-QBO-21 JournalEntryPusher returns `failed_preflight` status on every push attempt; admin UI surfaces the actionable error. Bridge-derived JEs (source_type IN invoice/payment/credit_note/ap_bill/ap_payment) still skip cleanly without map row write per D-QBO-21-1 — only the non-bridge-derived JEs need these categories mapped.

**Note:** This gate is per-session per D-QBO-VALIDATOR-3 (S-QBO-VALIDATOR-SCOPE-SPLIT). Other Pushers have their own category requirements: InvoicePusher needs ar_clearing + sales_revenue; PaymentPusher needs ar_clearing + undeposited_funds; BillPusher needs ap_clearing; BillPaymentPusher needs ap_clearing + undeposited_funds.

---

## 🟢 DEFERRED — queued for follow-up sessions

### F5 — S-QBO-14-UPDATE-FOLLOWUP — PaymentPusher::pushUpdate impl ✅ CLOSED 2026-05-31

**Surfaced by:** S-QBO-14 (2026-05-28) — D-QBO-14-5 stub-then-implement pattern
**Closed by:** S-QBO-PAYMENT-UPDATE (2026-05-31) — D-QBO-PAYMENT-UPDATE-1 locked. PaymentPusher::pushUpdate now routes through the shared pushImpl with operation='update' → full-payload re-send via QuickBooksClient::updateEntity + SyncToken refresh (mirrors InvoicePusher D-QBO-12-1 + BillPusher D-QBO-BILL-UPDATE-1). Demote-to-create when unmapped per D-PUSHER-DEMOTION-RULE at pushImpl step 7b. PaymentEnqueuer gate-3 widened ['create']→['create','update']. **NO enqueue hook in api/v1/payments/update.php (D2 decision)** — only reference_number→PaymentRefNum is QBO-pushable among the 5 editable metadata fields; reference_number sync rides the manual-sync path via /quickbooks/manual_sync → Force re-sync (payments) (S-QBO-26 force_resync). Smoke _smoke_qbo_payment_push 20→23 (incl. C23 CRITICAL proving D-QBO-14-1 dedup covers the update verb). NO migration. See FLEETFORGE_PROGRESS.md SESSION LOG row.

**Why deferred originally:** v1 ships pushCreate; pushUpdate semantics for payments are non-trivial (LinkedTxn[] mutations, void+recreate vs sparse update tradeoffs, FX rate snapshot rules). Resolved by the full-payload re-send pattern proven by InvoicePusher D-QBO-12-1.

---

### F6 — S-QBO-19-UPDATE-FOLLOWUP — BillPaymentPusher::pushUpdate impl ✅ CLOSED 2026-05-31

**Surfaced by:** S-QBO-19 (2026-05-29) — D-QBO-19-5 stub-then-implement pattern
**Closed by:** S-QBO-BILL-PAYMENT-UPDATE (2026-05-31) — D-QBO-BILL-PAYMENT-UPDATE-1 locked. BillPaymentPusher::pushUpdate now routes through the shared pushImpl with operation='update' → full-payload re-send via QuickBooksClient::updateEntity + SyncToken refresh (mirrors InvoicePusher D-QBO-12-1 + BillPusher D-QBO-BILL-UPDATE-1 + PaymentPusher D-QBO-PAYMENT-UPDATE-1). Demote-to-create when unmapped per D-PUSHER-DEMOTION-RULE at pushImpl step 5b (same placement as Bill template; no payment-style dedup-gate dance because acc_ap_payments has no `origin` column per D-QBO-19-1). BillPaymentEnqueuer gate-3 widened ['create']→['create','update']. **WIRED enqueue('update') hook in api/v1/accounting/ap-payments/update.php (D2 decision)** — 3 of 4 editable fields (payment_date / reference_number / check_number) directly affect the QBO BillPayment payload as TxnDate + PrivateNote; only `notes` is FF-only, so the hook propagates real QBO-relevant changes. Aligns with bills/update.php pattern from S-QBO-BILL-UPDATE; diverges from payments/update.php which decided no-hook because there only 1 of 5 fields was QBO-pushable. Smoke _smoke_qbo_bill_payment_push 25→27 (incl. C26 demote-to-create + C27 rejects void). NO migration. See FLEETFORGE_PROGRESS.md SESSION LOG row.

**Why deferred originally:** same rationale as F5 — pushUpdate semantics for bill payments warrant their own pass. Pairs naturally with F5 since the patterns are similar. Resolved by the full-payload re-send pattern proven by InvoicePusher D-QBO-12-1.

---

### F7 — pushVoid absent in PaymentPusher + BillPaymentPusher v1

**Surfaced by:** S-QBO-14 + S-QBO-19 (2026-05-28/29)
**Affects:** void semantics for FF-native payments + bill payments
**Operator action:** queue a session pair `S-QBO-14-VOID-FOLLOWUP` + `S-QBO-19-VOID-FOLLOWUP` (or combined `S-QBO-AR-AP-PAYMENT-VOID-FOLLOWUP`).

**Why deferred:** v1 handles voided payments at the skipped_unmapped_void level (don't push voids that never made it to QBO) but POST-push voids need QBO-side void API calls. Mirrors S-QBO-12 which added pushVoid for invoices after S-QBO-11.

---

### F8 — S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN — rich QBO sync panel on FF show pages

**Surfaced by:** S-QBO-19 (2026-05-29) — operator catch during S-QBO-19 audit
**Affects:** UX parity across all 4 FF-origin push surfaces
**Operator action:** queue a session to extend the canonical rich QBO sync panel pattern (already shipped on `app/admin/invoices/show.php` via S-QBO-INVOICE-SHOW-RICH-PANEL 2026-05-26) to the parallel show pages:
- `app/admin/accounting/bills/show.php` — add panel for bill push state (acc_qbo_bill_map)
- `app/admin/payments/show.php` — add panel for payment push state (acc_qbo_payment_map; bidirectional surface)
- `app/admin/accounting/ap-payments/show.php` — add panel for bill_payment push state (acc_qbo_bill_payment_map)
- `app/admin/accounting/journal-entries/show.php` (or equivalent) — JE push state (acc_qbo_journal_entry_map) [S-QBO-21]
- `app/admin/credit_notes/show.php` — credit memo push state (acc_qbo_credit_memo_map) [added S-QBO-16: this FF show page has ZERO QBO mentions today vs invoices/show.php's rich panel]

Each follows the 6-state badge + identifiers row + Push History table pattern from S-QBO-INVOICE-SHOW-RICH-PANEL.

**Why deferred:** D-UI-COMPLETENESS-1 only mandates the `/quickbooks/{entity}` admin surface. The FF-side show.php rich panels are a separate UX consistency concern — operator looks at an invoice/bill/payment and wants to see at-a-glance QBO sync state without navigating to /quickbooks/{entity}. Worth doing as a single debt-paydown session to apply the pattern uniformly across 3 entities at once.

**Reference impl:** `app/admin/invoices/show.php` rich panel (S-QBO-INVOICE-SHOW-RICH-PANEL, search "QuickBooks Sync" section).

---

### F9 — S-QBO-BILL-ITC-TAX-RATE-MAPPING — ITC tax-rate mapping

**Surfaced by:** S-QBO-18 (2026-05-27) — D-QBO-18-2 noted ITC tax-rate mapping deferred
**Affects:** bill push tax-line emission with per-rate detail (instead of override pattern)
**Operator action:** queue a session that adds `acc_qbo_tax_rate_map` table + TaxRatePuller + TaxRateMatcher + ITC eligibility flag wiring on bill_line, enabling per-rate tax detail per QBO_SPEC §8.8 example.

**Why deferred:** S-QBO-9 maps tax CODES, not tax RATES. Building per-rate mapping is its own session. v1 BillPusher uses the tax-override pattern (every line TaxCodeRef='NON' + header TxnTaxDetail.TotalTax via bcmath) which is consistent but doesn't expose ITC tax detail in QBO.

---

### F10 — S-VENDOR-UI-CURRENCY-SELECTOR — admin UI vendor currency selector

**Surfaced by:** S-VENDOR-CURRENCY-COLUMN (2026-05-27) — D-VENDOR-CURRENCY-COLUMN-4
**Affects:** operator workflow for setting per-vendor currency
**Operator action:** queue a session to extend `app/admin/vendors/{create,edit}.php` Alpine forms with currency selector. Currently the API accepts `currency` ENUM input but admin UI form doesn't expose it.

**Why deferred:** S-VENDOR-CURRENCY-COLUMN was scoped XS — column + Pusher read + API endpoints. UI extension was explicitly deferred. Operators can set currency via API call or DB UPDATE in the interim.

---

### F14 — Crontab install for `cron/qbo_bank_cdc.php`

**Surfaced by:** S-QBO-20 (2026-05-29) — D-QBO-20 cron pattern
**Affects:** S-QBO-20 (daily bank-CDC pull)
**Operator action:**
1. At S-QBO-30 production cutover, install crontab entry:
   ```
   30 2 * * * php /var/www/fleetforge/cron/qbo_bank_cdc.php >> /var/log/fleetforge/qbo_bank_cdc.log 2>&1
   ```
2. Verify via: `crontab -l | grep qbo_bank_cdc`
3. First-run test (operator can run manually before crontab install): `php cron/qbo_bank_cdc.php` — should print starting + completion summary + write audit_log row.
4. Verify the audit_log shows: `SELECT * FROM audit_log WHERE module='quickbooks' AND entity_type='qbo_bank_cdc' ORDER BY created_at DESC LIMIT 5;`

**Why deferred** (until S-QBO-30 cutover): the cron pattern matches the 3 existing QBO crons (qbo_token_refresh, qbo_sync_worker, qbo_drift_check — the last is also deferred to S-QBO-24/30); operator wires them all together at production cutover per S-QBO-30 alongside DNS/SSL/secrets per K-14. Pre-cutover the operator can run the cron manually via the admin UI's "Run CDC now" button on `/quickbooks/bank_accounts`.

**Without this:** the daily mirror pull won't run unattended. Operator can still manually trigger via admin UI button.

---

### F15 — FX revaluation for multi-currency mirror rows (`S-QBO-FX-RECON-FOLLOWUP`)

**Surfaced by:** S-QBO-20 (2026-05-29) — D-QBO-20 multi-currency design note
**Affects:** S-QBO-20 mirror rows for QBO bank accounts denominated in USD (or any non-CAD currency)
**Operator action:** queue a future session `S-QBO-FX-RECON-FOLLOWUP` (or roll into S-QBO-24 drift-check cron) to handle:
1. FF acc_bank_transactions.amount is currently stored as the QBO-emitted home-currency value (typically the foreign currency, e.g. USD for a USD-denominated bank account).
2. acc_qbo_bank_transaction_map snapshots `qbo_currency_snapshot` + `qbo_exchange_rate_snapshot` at pull time for forensic trail.
3. v1 does NOT convert to FF home currency (CAD) — the mirror row shows USD amounts in a CAD-context FF list, which may confuse operators reading the bank transactions page.
4. The follow-up should: (a) decide whether to convert at pull time (lossy — historical rate frozen) or display-time (live — needs ongoing FX feed) and (b) implement the chosen path with operator-visible currency badge per mirror row.

**Why deferred:** v1 covers the canonical 99% CAD-bank-account-with-CAD-transactions case cleanly. Multi-currency banking is a Mainland-future concern (no USD bank accounts on the live chart as of 2026-05-29). Defer until a USD bank account is mapped, OR roll into the S-QBO-24 drift-check pass which would naturally need to revalue mirror rows for drift comparison anyway.

**Without this:** USD bank account mirror rows display the USD value without conversion — visually wrong on a CAD-context page but accounting-neutral (QBO is canonical for reconciliation; FF mirror is observational).

---

### F-PAYDOWN-PROGRESS — S-QBO-PUSHER-UPDATE-FOLLOWUPS-PAYDOWN in progress (4/5 update slices shipped)

**Surfaced by:** S-QBO-BILL-UPDATE (2026-05-31) — first slice of the umbrella paydown
**Updated:** 2026-05-31 — S-QBO-CREDIT-MEMO-UPDATE shipped (4th slice; F20 pushUpdate stub closed; apply→LinkedTxn carved out to NEW F25)

The umbrella paydown (F5 payment / F6 bill_payment / F13 JE / F20 credit_memo update stubs + the pushVoid trio in F7) is being worked one Pusher at a time. **BillPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-BILL-UPDATE)** as the proven template: routes through `pushImpl` with `operation='update'` → full-payload re-send via `QuickBooksClient::updateEntity` + SyncToken round-trip; demote-to-create when unmapped (D-PUSHER-DEMOTION-RULE); `BillEnqueuer` gate-3 widened to accept `'update'`; `enqueue('update')` wired into `api/v1/accounting/bills/update.php`. **PaymentPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-PAYMENT-UPDATE — F5 closed)** as the 2nd slice (mechanical mirror of the bill template): demote-to-create at pushImpl step 7b sits AFTER the origin/pulled_from_qbo dedup gates (steps 5+6) so the D-QBO-14-1 bidirectional dedup invariant covers the UPDATE verb too without extra code — locked by C23 smoke. PaymentEnqueuer gate-3 widened ['create']→['create','update']. **D2 divergence vs Bill template: NO enqueue hook in api/v1/payments/update.php** — of the 5 editable metadata fields, only reference_number→PaymentRefNum is QBO-pushable; reference_number sync rides the manual-sync path via /quickbooks/manual_sync → Force re-sync (payments) (S-QBO-26 force_resync). **BillPaymentPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-BILL-PAYMENT-UPDATE — F6 closed)** as the 3rd slice (mechanical mirror; simplest of the three because acc_ap_payments has no `origin` column per D-QBO-19-1 — no payment-style dedup-gate dance, demotion at step 5b same as Bill template). BillPaymentEnqueuer gate-3 widened ['create']→['create','update']. **D2 alignment with Bill template: WIRED enqueue('update') hook in api/v1/accounting/ap-payments/update.php** — 3 of 4 editable fields (payment_date / reference_number / check_number) directly affect the QBO BillPayment payload as TxnDate + PrivateNote; only `notes` is FF-only. **CreditMemoPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-CREDIT-MEMO-UPDATE — F20 pushUpdate stub closed)** as the 4th slice. Mechanical mirror only (updateEntity('creditmemo') full-payload re-send + demote-to-create at step 5b + CreditMemoEnqueuer gate-3 widened). **Key finding (operator-confirmed scope decision):** F20's original "apply→LinkedTxn" framing turned out to be a DIFFERENT, larger operation than the mechanical pushUpdate. Credit notes have no editable header (no credit_notes/update.php), so the mechanical update is near-noop (SyncToken/qbo_balance refresh); the valuable credit-APPLICATION propagation needs a zero-dollar QBO Payment with CreditMemo+Invoice LinkedTxns + a migration (no linkage column) + an apply.php hook — **carved out to NEW follow-up F25** (S-QBO-CREDIT-MEMO-APPLY-FOLLOWUP). NO enqueue hook wired this slice (mechanical update rides manual-sync force_resync). **Remaining update stub** (follows the proven template — SyncToken load → merge → updateEntity → demote-to-create when unmapped): JournalEntryPusher (F13 — bridge-derived guard interaction to verify; the last clean mechanical mirror). **Remaining void stubs** (F7 + credit_memo void): PaymentPusher / BillPaymentPusher / CreditMemoPusher `pushVoid` — separate follow-on slice (mirror InvoicePusher `pushVoidImpl`). **Carved-out application flow:** F25 (credit-memo apply→LinkedTxn — needs a migration, so NOT a mechanical paydown slice). **NO migration** needed for the remaining JE update slice — `push_status` ENUM + `qbo_sync_token` columns already exist on every map table.

### F13 — S-QBO-21-UPDATE-FOLLOWUP — JournalEntryPusher::pushUpdate impl

**Surfaced by:** S-QBO-21 (2026-05-29) — D-QBO-21-5 stub-then-implement pattern (template now available — see F-PAYDOWN-PROGRESS)
**Operator action:** queue a future session to implement JournalEntryPusher::pushUpdate. Currently returns `unsupported_in_session` per the stub pattern (matches S-QBO-11 D-QBO-11-4 + S-QBO-14 D-QBO-14-5 + S-QBO-18 D-QBO-18-5 + S-QBO-19 D-QBO-19-5).

**Why deferred:** v1 ships pushCreate; pushUpdate semantics for posted JEs are non-trivial — QBO's JournalEntry update model is restrictive (sparse=true + Active=false approximates void; full-line edits often require void-and-recreate for cleanliness). Posted-period validation adds complexity. Pairs naturally with F5 (S-QBO-14-UPDATE-FOLLOWUP) + F6 (S-QBO-19-UPDATE-FOLLOWUP) since the patterns are similar — could be combined as `S-QBO-PUSHER-UPDATE-FOLLOWUPS-PAYDOWN`.

---

### F11 — Admin settings UI for `quickbooks.payments.*` keys

**Surfaced by:** S-QBO-15 (2026-05-29)
**Affects:** operator workflow for configuring QBO Payments embed
**Operator action:** queue a session to extend `app/admin/quickbooks/settings.php` with a "QBO Payments" section that exposes:
- `quickbooks.payments_enabled` toggle (currently '0' — master gate)
- `quickbooks.payments.success_url` (default `portal/payments/payment_success`)
- `quickbooks.payments.cancel_url` (default `portal/payments/payment_cancel`)
- `quickbooks.payments.url_ttl_minutes` (default 30)

**Why deferred:** S-QBO-15 v1 deferred this — operator can configure via DB UPDATE or existing /admin/settings (advanced section). Has the keys seeded but no dedicated UI form.

---

### F16 — S-QBO-22 live verification — depreciation/disposal/impairment JE push end-to-end

**Surfaced by:** S-QBO-22 (2026-05-29) — Phase QBO-11 / 1 of 2 Fixed Asset JE sync
**Affects:** ongoing-operations confidence that FA-derived JEs reach QBO with correct PrivateNote enrichment
**Operator action:** after S-QBO-30 production cutover flips `quickbooks.sync_enabled='1'`, run one of each FA action against production data + verify QBO-side outcome:

1. **Depreciation run** — post a monthly depreciation run via `/admin/accounting/fixed-assets/depreciation` → run completes (acc_journal_entries row source_type='depreciation') → worker picks up the enqueued JE → confirm QBO JournalEntry created → open in QBO UI + verify PrivateNote contains `FA-DEP run#X period='...' assets=N total=$Y.YY` enrichment + per-line PostingType+AccountRef correct.
2. **Asset disposal** — record an asset disposal via `/admin/accounting/fixed-assets/disposals/create` → confirm JE source_type='asset_disposal' enqueued + pushed → QBO PrivateNote contains `FA-DISP asset=FA-XXXX type=sale proceeds=$X gain_loss=$Y`.
3. **Asset impairment** — record an impairment via `/admin/accounting/fixed-assets/impairments/create` → confirm JE source_type='impairment' (NEW ENUM value per D-QBO-22-2) → QBO PrivateNote contains `FA-IMP asset=FA-XXXX reason='...' loss=$Y` + sanitized reason text (no embedded single quotes or `|` separators).
4. **Pre-S-QBO-22 impairment audit** (optional cleanup): `SELECT COUNT(*) FROM acc_journal_entries WHERE source_type='asset_disposal' AND reference LIKE 'IMP-%'` — these are pre-D-QBO-22-2 impairments that used the "closest enum match" workaround. They were intentionally NOT backfilled (audit-trail preservation per D-QBO-22-2). If operator wants taxonomic cleanup post-cutover, queue `S-QBO-22-IMPAIRMENT-BACKFILL` (one-shot UPDATE with audit_log entry).

**Why deferred:** S-QBO-22 ships with 24/24 smoke PASS proving the unit + integration behavior offline. Live verification requires real FA artifacts + sandbox/prod QBO realm + the master sync_enabled='1' flip which is locked behind D-CPA-5 until S-QBO-30 cutover. Same pattern as F12 (S-QBO-21 live verify) — covered by the cutover sequence, not blocking now.

**Companion item:** `/admin/quickbooks/journal_entries` Show FA Only filter chip + 3-tile FA KPI strip (D-QBO-22-3) gives operator at-a-glance FA sync health post-cutover.

---

### F17 — Fixed-asset admin pages: "QBO sync pending" indicator

**Surfaced by:** S-QBO-22 post-ship audit (2026-05-29) — operator-asked "is every UI updated?"
**Affects:** operator UX when posting depreciation runs / disposals / impairments — currently no visual indication that the resulting JE will be enqueued for QBO push
**Operator action:** queue a small UX session to add a "QBO sync enabled — depreciation/disposal/impairment JEs are enqueued for QBO push when posted" badge/note on:

1. `/admin/accounting/fixed-assets/depreciation/index.php` (or wherever depreciation runs are posted) — show next-step hint after posting
2. `/admin/accounting/fixed-assets/disposals/create.php` — show note in the disposal form
3. `/admin/accounting/fixed-assets/impairments/create.php` — show note in the impairment form
4. Optional: status badge on each FA detail page showing "Last JE pushed: pushed/pending/failed" with link to `/quickbooks/journal_entries?source_filter=fa&entity_id=N`

**Why deferred:** S-QBO-22 D-QBO-22-3 scope locked the admin UI surface to `/admin/quickbooks/journal_entries` filter chip + FA KPI strip — that's the canonical QBO-sync visibility. Adding hints on FA pages is pure UX polish that doesn't gate functionality; the JE flow works end-to-end without it. Recommended size: XS Sonnet (~30 min).

**Companion:** F16 (live verification of FA JE push end-to-end) covers the functional verification post-cutover.

---

### F18 — CLAUDE_CODE_REFERENCE D131 history paragraph backfill

**Surfaced by:** S-QBO-22 post-ship audit (2026-05-29) — operator-asked "is every doc updated?"
**Affects:** documentation drift in `docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md` D131 history paragraph (line 2672)
**Operator action:** queue `S-CLAUDE-CODE-REFERENCE-D131-BACKFILL` session to add D131 history entries for:

- S-QBO-14 (2026-05-28) — `tests/_smoke_qbo_payment_push.php` 20 sub-checks
- S-QBO-15 (2026-05-29) — extended `tests/_smoke_qbo_payments_embed.php` 28→31 sub-checks
- S-QBO-18 (2026-05-27) — `tests/_smoke_qbo_bill_push.php` 20 sub-checks (later 20→23)
- S-QBO-19 (2026-05-29) — `tests/_smoke_qbo_bill_payment_push.php` (count from session log)
- S-QBO-20 (2026-05-29) — `tests/_smoke_qbo_bank_mapping.php` 14 + `_smoke_qbo_bank_cdc.php` 16
- S-QBO-21 (2026-05-29) — `tests/_smoke_qbo_journal_entry_push.php` 31 sub-checks
- S-QBO-22 entry was added 2026-05-29 inline (first session to close the lag)

**Why deferred:** cumulative drift from 6 sessions — not a single-session fix; backfill requires reading each SESSION LOG row to summarize the smoke additions correctly. Recommended size: S Sonnet (mechanical write-up).

**Why this matters:** the D131 history paragraph is the only place that documents *which smoke was added by which session* — the SESSION LOG rows are descriptive but not indexed by D131. Without backfill, future drift-detection sessions can't easily answer "which smoke did S-QBO-19 add?".

---

### F19 — S-QBO-23 live verification — tax remittance JE push end-to-end

**Surfaced by:** S-QBO-23 (2026-05-29) — Phase QBO-11 / 2 of 2 Tax Remittance JE sync
**Affects:** ongoing-operations confidence that tax-remittance JEs reach QBO with correct PrivateNote enrichment
**Operator action:** after S-QBO-30 production cutover flips `quickbooks.sync_enabled='1'`, record a tax remittance + verify the QBO-side outcome:

1. Take a tax filing period to status='filed' via `/admin/accounting/tax-filing` (or the GST34 workflow).
2. Record the remittance via `TaxFilingService::recordRemittance` (the "Record Remittance" action) → confirm a JE is created with `source_type='tax_remittance'` + `reference='TAX-REMIT-{id}'`.
3. Worker picks up the enqueued JE → confirm QBO JournalEntry created → open in QBO UI + verify PrivateNote contains `TAX-REMIT remit#X type=gst_hst period=A..B amount=$Y method=Z` enrichment.
4. Confirm the 2-line JE posts DR tax-payable / CR bank with correct per-line AccountRef from acc_qbo_account_map.

**Why deferred:** S-QBO-23 ships with 19/19 smoke PASS proving unit + integration behavior offline (incl. dispatcher-refactor regression guards). Live verification needs a real filing period + remittance + sandbox/prod QBO realm + the master sync_enabled='1' flip locked behind D-CPA-5 until S-QBO-30 cutover. Same pattern as F16 (S-QBO-22 live verify) + F12 (S-QBO-21 live verify) — covered by the cutover sequence, not blocking now.

**Companion:** the `/admin/quickbooks/journal_entries` "Tax Remittance" source-type chip + "By source type" KPI strip (D-QBO-23-3) gives operator at-a-glance tax-remittance sync health post-cutover.

---

### F20 — S-QBO-16-UPDATE-FOLLOWUP — CreditMemoPusher apply→LinkedTxn + void ⚠️ PARTIALLY CLOSED 2026-05-31

**Surfaced by:** S-QBO-16 (2026-05-29) — D-QBO-16-2 stub-then-implement pattern

**pushUpdate STUB closed by:** S-QBO-CREDIT-MEMO-UPDATE (2026-05-31) — D-QBO-CREDIT-MEMO-UPDATE-1 locked. `CreditMemoPusher::pushUpdate` now routes through the shared pushImpl with operation='update' → MECHANICAL full-payload re-send via `QuickBooksClient::updateEntity('creditmemo', ...)` + SyncToken round-trip + demote-to-create when unmapped (D-PUSHER-DEMOTION-RULE step 5b). `CreditMemoEnqueuer` gate-3 widened `['create']`→`['create','update']`. Smoke `_smoke_qbo_credit_memo_push` 26→28. NO migration.

**STILL OPEN (carved out → F25):** the *credit-APPLICATION propagation* — the apply→LinkedTxn flow that links a QBO CreditMemo to a QBO Invoice when an FF credit is applied — is **NOT** delivered by the mechanical pushUpdate. As-built reality (discovered during S-QBO-CREDIT-MEMO-UPDATE): credit notes have no editable header (`credit_notes/update.php` only edits reason/internal_notes/expires_at — amount/source/customer immutable post-issuance), so the mechanical updateEntity re-send is effectively a SyncToken/qbo_balance refresh. The valuable apply event is a DIFFERENT QBO operation (a zero-dollar Payment entity carrying CreditMemo + Invoice LinkedTxns) needing schema (no linkage column on acc_qbo_credit_memo_map) + an apply.php enqueue hook. Tracked as **F25**.

**STILL OPEN (→ F7):** `pushVoid` — rides the pushVoid trio (F7), NOT this slice. Still returns `unsupported_in_session`.

**Operator action:** F20's pushUpdate-stub obligation is satisfied. Remaining work is split into **F25** (apply→LinkedTxn application propagation) + **F7** (pushVoid trio incl. credit_memo void), both individually tracked.

**Why deferred originally:** v1 ships pushCreate (the dominant flow). The mechanical pushUpdate was paid down in S-QBO-CREDIT-MEMO-UPDATE as the 4th slice of `S-QBO-PUSHER-UPDATE-FOLLOWUPS-PAYDOWN` (alongside F5 BillPayment... actually Bill/Payment/BillPayment). The genuinely-valuable apply→LinkedTxn flow turned out to need a migration + a different QBO entity, so it was correctly split out to F25 rather than forced into the no-migration paydown.

---

### F21 — `config/navigation.php` is MISSING the Bank Accounts QuickBooks child

**Surfaced by:** S-QBO-16 (2026-05-29) nav audit — caught while adding Credit Memos to the nav.
**Affects:** the live admin sidebar may not render the "Bank Accounts" link (S-QBO-20's `/quickbooks/bank_accounts` page) depending on which nav source the layout reads.
**Root cause:** S-QBO-20 added "Bank Accounts" to `includes/partials/quickbooks-nav.php` (the breadcrumb/tab partial) but NOT to `config/navigation.php` (the sidebar config the nav-asserting smokes read). The two nav definitions have drifted: the partial has 16 QBO children (+ Bank Accounts + Credit Memos), config/navigation.php has 15 (Credit Memos added by S-QBO-16, but still no Bank Accounts).
**Operator action:** queue a tiny fix session (XS) to add the `Bank Accounts` entry to `config/navigation.php` (between Tax Codes and Items, matching the partial's position) + bump the 6 nav-asserting smokes' expected child count 16→17 + add 'Bank Accounts' to their `$expected` label arrays. Verify the live sidebar renders it.

**Why not fixed in S-QBO-16:** out of scope (S-QBO-20's drift, not credit-memo work) + would expand this commit's diff into 6 more smoke files for an unrelated reason. Flagged here so it's tracked rather than silently carried. Low urgency — Bank Accounts is still reachable via direct URL + the breadcrumb partial; only the sidebar config entry is missing.

---

### F22 — Crontab install for `cron/qbo_drift_check.php`

**Surfaced by:** S-QBO-24 (2026-05-30)
**Operator action:** during S-QBO-30 cutover, add the drift cron to the server crontab alongside the other QBO crons:
```
30 3 * * * php /var/www/fleetforge/cron/qbo_drift_check.php
```
Runs nightly at 03:30 (after token refresh 02:00 + bank CDC 02:30) per spec §15.2. Until installed, drift detection only runs when an operator clicks "Run drift check now" on `/quickbooks/drift`.

**Why deferred:** NOT installed by the session (same discipline as qbo_bank_cdc F14 — crons are wired during cutover, not by build sessions). Pre-cutover the cron is harmless (snapshot-only, no QBO calls) but there's no value running it nightly until sync is live.

---

### F23 — S-QBO-24-GL-BALANCE-FOLLOWUP — GL-account-balance drift check

**Surfaced by:** S-QBO-24 (2026-05-30) — D-QBO-24-3 scope deferral
**Operator action:** queue a follow-up to add the GL-account-balance drift check (spec §15.2 step 4 + §15.5 GL row) to DriftChecker: for each mapped `acc_qbo_account_map` row, compare the FF account running balance vs the live QBO `Account.CurrentBalance`; if `|delta| > $1.00` (configurable tolerance) → `category='balance_drift'` drift event.

**Why deferred:** distinct sub-system from the 8 entity-map checks — it compares balances (not entity counts/totals), `acc_qbo_account_map` is Puller-only (accountant owns COA), and it needs a per-account live QBO balance API call. Only meaningful post-cutover (sync_enabled='1'). Keeps S-QBO-24 v1 focused on the entity-drift bulk.

---

### F24 — Live-HTTP drift layer verification at cutover

**Surfaced by:** S-QBO-24 (2026-05-30)
**Operator action:** after S-QBO-30 flips `sync_enabled='1'` + connects QBO, verify the DriftChecker LIVE layer end-to-end: run "Run drift check now" → confirm it issues per-entity QBO queries (Invoice/Payment/Bill/…) + records `missing_in_ff` drift events for any QBO entity with no FF mapping (e.g. a manually-created QBO invoice). Pre-cutover this path is unreachable (sync_enabled='0' → `liveModeAvailable()` false → snapshot-only) + there is NO fixture/mock HTTP layer, so the live layer is unit-tested only at the gate-decision level (smoke C15); the actual QBO query + missing_in_ff recording must be verified against the live sandbox.

**Why deferred:** structurally cannot run pre-cutover; no offline fixture for QBO HTTP. The snapshot layer (push_failed + FF-side amount_drift) IS fully tested + runs now.

---

## ✅ CLOSED — moved to archive after operator confirmation

*(empty — track here when operator confirms completion + provides verification timestamp)*

---

## Cross-cutting notes

- **Discipline enforcement:** `tests/_smoke_doc_freshness.php` CLASS 13 (locked 2026-05-29 via S-OPERATOR-FOLLOWUPS-TRACKING) verifies that recent SESSION LOG rows containing the phrase "Operator follow-ups" have matching entries in this doc. Advisory — surfaces orphans without strict-failing because some follow-ups may be re-architected into proper session labels between surfacing and tracking.
- **Memory file:** `memory/feedback_operator_followups_tracking.md` codifies the rule + canonical incident (this file's birth at S-OPERATOR-FOLLOWUPS-TRACKING). Survives context wipes.
- **When operator completes an item:** update the entry's status from 🔴/🟡/🟢 to ✅ + add completion timestamp + verification command output + move to archive at bottom of doc.
- **When a new follow-up surfaces mid-session:** add the entry under the appropriate status bucket BEFORE commit. Include: Surfaced-by (session label + commit ref), Affects (which sessions depend), Operator action (numbered steps), Why-blocking/deferred (rationale).
