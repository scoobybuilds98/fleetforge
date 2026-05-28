# FleetForge — Operator Follow-ups (Carry-over Tracking)

**Purpose:** canonical, persistent list of operator-side work items surfaced by shipped sessions that the operator must complete BEFORE live cutover OR that gate downstream functionality. Survives session-context-wipes (it's a file, not a memory). Updated mechanically at every session end (enforced by `tests/_smoke_doc_freshness.php` CLASS 13).

**Discipline:** every session that notes operator follow-ups in its SESSION LOG row MUST add or update matching entries here BEFORE commit. See `memory/feedback_operator_followups_tracking.md` for the rule + canonical incidents.

**Difference from `FLEETFORGE_PREDEPLOY_CHECKLIST.md`:** the predeploy checklist is pre-deploy infrastructure items (DNS, SSL, S3, SES sandbox approval, etc.) per K-14. This doc tracks operator follow-ups surfaced by SHIPPED sessions — things the operator must do to enable a feature that's already in code but blocked on operator action (Intuit dashboard registration, secret configuration, account mapping, etc.). Some items overlap and may also appear in PREDEPLOY_CHECKLIST.md — that's intentional cross-listing.

**Status legend:**
- 🔴 **BLOCKING** — live test or cutover cannot proceed without this
- 🟡 **PARTIAL** — operator can use the feature in degraded mode until completed
- 🟢 **DEFERRED** — queued for a future session; documented for tracking
- ✅ **CLOSED** — operator completed; moved to archive at bottom

**Last updated:** 2026-05-29 via S-OPERATOR-FOLLOWUPS-TRACKING.

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

## 🟢 DEFERRED — queued for follow-up sessions

### F5 — S-QBO-14-UPDATE-FOLLOWUP — PaymentPusher::pushUpdate impl

**Surfaced by:** S-QBO-14 (2026-05-28) — D-QBO-14-5 stub-then-implement pattern
**Operator action:** queue a future session to implement PaymentPusher::pushUpdate. Currently returns `unsupported_in_session` per the stub pattern (matches S-QBO-11 D-QBO-11-4 + S-QBO-18 D-QBO-18-5).

**Why deferred:** v1 ships pushCreate; pushUpdate semantics for payments are non-trivial (LinkedTxn[] mutations, void+recreate vs sparse update tradeoffs, FX rate snapshot rules). Warrant their own session pass.

---

### F6 — S-QBO-19-UPDATE-FOLLOWUP — BillPaymentPusher::pushUpdate impl

**Surfaced by:** S-QBO-19 (2026-05-29) — D-QBO-19-5 stub-then-implement pattern
**Operator action:** queue a future session to implement BillPaymentPusher::pushUpdate. Currently returns `unsupported_in_session`.

**Why deferred:** same rationale as F5 — pushUpdate semantics for bill payments warrant their own pass. Pairs naturally with F5 since the patterns are similar.

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

## ✅ CLOSED — moved to archive after operator confirmation

*(empty — track here when operator confirms completion + provides verification timestamp)*

---

## Cross-cutting notes

- **Discipline enforcement:** `tests/_smoke_doc_freshness.php` CLASS 13 (locked 2026-05-29 via S-OPERATOR-FOLLOWUPS-TRACKING) verifies that recent SESSION LOG rows containing the phrase "Operator follow-ups" have matching entries in this doc. Advisory — surfaces orphans without strict-failing because some follow-ups may be re-architected into proper session labels between surfacing and tracking.
- **Memory file:** `memory/feedback_operator_followups_tracking.md` codifies the rule + canonical incident (this file's birth at S-OPERATOR-FOLLOWUPS-TRACKING). Survives context wipes.
- **When operator completes an item:** update the entry's status from 🔴/🟡/🟢 to ✅ + add completion timestamp + verification command output + move to archive at bottom of doc.
- **When a new follow-up surfaces mid-session:** add the entry under the appropriate status bucket BEFORE commit. Include: Surfaced-by (session label + commit ref), Affects (which sessions depend), Operator action (numbered steps), Why-blocking/deferred (rationale).
