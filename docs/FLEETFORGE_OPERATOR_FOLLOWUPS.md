# FleetForge — Operator Follow-ups (Carry-over Tracking)

**Purpose:** canonical, persistent list of operator-side work items surfaced by shipped sessions that the operator must complete BEFORE live cutover OR that gate downstream functionality. Survives session-context-wipes (it's a file, not a memory). Updated mechanically at every session end (enforced by `tests/_smoke_doc_freshness.php` CLASS 13).

**Discipline:** every session that notes operator follow-ups in its SESSION LOG row MUST add or update matching entries here BEFORE commit. See `memory/feedback_operator_followups_tracking.md` for the rule + canonical incidents.

**Difference from `FLEETFORGE_PREDEPLOY_CHECKLIST.md`:** the predeploy checklist is pre-deploy infrastructure items (DNS, SSL, S3, SES sandbox approval, etc.) per K-14. This doc tracks operator follow-ups surfaced by SHIPPED sessions — things the operator must do to enable a feature that's already in code but blocked on operator action (Intuit dashboard registration, secret configuration, account mapping, etc.). Some items overlap and may also appear in PREDEPLOY_CHECKLIST.md — that's intentional cross-listing.

**Status legend:**
- 🔴 **BLOCKING** — live test or cutover cannot proceed without this
- 🟡 **PARTIAL** — operator can use the feature in degraded mode until completed
- 🟢 **DEFERRED** — queued for a future session; documented for tracking
- ✅ **CLOSED** — operator completed; moved to archive at bottom

**Last updated:** 2026-06-24 via S-MONTHLY-SHORT-FLAT — added **F39** (deploy the ≤1-month flat-monthly billing fix + remediate the two MTTS73 draft invoices so the 22-day lease totals the monthly rate $425; drafts only, no money moved, 🟢 DEFERRED). Prior: 2026-06-23 via S-SAMSARA-OFFLINE-NOTIF-OFF — added **F38** (one-time cleanup of the pre-fix device-offline notification backlog: the generator removal stops NEW `samsara.not_connected` notifications, verified live, but 21,187 rows / 18,356 unread accumulated before the fix and persist until cleared; ready-to-run `scripts/clear_device_offline_notifications_2026_06_23.php`, DRY-RUN by default, 🟢 DEFERRED — cosmetic, non-blocking). Prior: 2026-06-23 via S-DB-CONNECT-RETRY — added **F37** (optional infra hardening: `unattended-upgrades` restarting `mysql-server` for ~10s caused the recurring `PDOException [2002] Connection refused` Sentry issue; the app now retries transient connects, so F37 is 🟢 DEFERRED — operator may optionally pin/exclude the MySQL auto-upgrade window). Prior: 2026-06-20 via S-INVOICE-AUDIT-FIX-2 (I14) — added **F36** (live-SES verify that email PDF attachments actually arrive: Mailer now sends attachment-bearing mail via SES `sendRawEmail` + a hand-built multipart/mixed MIME, threaded from EmailService via the new `StorageClient::read()`; operator must send a real Compose-modal invoice email and confirm the PDF arrives + renders. 🟡 PARTIAL — code-complete + unit-verified (22/22), live SES round-trip un-exercisable by the agent). Prior: 2026-06-19 via S-CRON-TOGGLES — added **F32** (the monthly-invoice cron (cron/invoice_generate_monthly.php) now ships OFF: the S-CRON-TOGGLES seed migration 202606191300 sets cron.invoice_generate_monthly_enabled='0', so once prod deploys + migrates, automatic monthly billing will NOT run until an operator re-enables it from **Settings → Intelligence → Scheduled Jobs**. 🟡 PARTIAL — not blocking; billing simply won't auto-generate draft invoices until toggled back on. All other business-automation crons default ON.). Prior: 2026-06-02 via S-CRON-FIX-1-MONTHLY-BILLING-TZ — added **F30** (retime the monthly-billing crontab line to `0 14 1 * *` UTC = 06:00 America/Vancouver on the 1st + review the first-run catch-up batch). The code fix self-heals via `<=` catch-up, so F30 is 🟡 PARTIAL (not blocking) but should be done at cutover for on-time billing. Prior: 2026-06-01 via S-QBO-OFFLINE-TESTBED — **offline fixture HTTP layer + demo-seed orchestrator.** No follow-ups CLOSED, but **operationally unblocks F16/F19/F24/F26/F28/F29 live-verify rehearsals**: each can now be dry-run against the canned QBO fixture at `/quickbooks/manual_sync` → "Load QBO demo data" before the accountant-seeded sandbox lands at cutover. Production guard D-QBO-FIXTURE-2 hard-refuses fixture mode in production OR with a non-sentinel real Intuit realm connected — canned data cannot mix with real-realm reasoning. NEW lib/QboFixture.php + lib/QboDemoSeed.php + QuickBooksClient::executeFixture short-circuit + super_admin-gated Load/Wipe buttons on /quickbooks/manual_sync + NEW api/v1/quickbooks/qbo_demo_seed.php + NEW _smoke_qbo_fixture_pipeline 13/13 (first sanctioned dispatch()-boundary-crosser). NO migration. Prior same-day: S-QBO-BILL-ITC-TAX-RATE — **closed F9** (opt-in per-rate bill-tax ITC emission, gated default-off; acc_qbo_tax_rate_map + tax_codes mapping UI; migration 86→87; NEW _smoke_qbo_bill_itc_tax_rate 7/7). Prior same-day: S-QBO-FX-RECON-FOLLOWUP — **closed F15** (display half: FxConverter + currency badge / CAD-equivalent on bank-transaction mirror rows; NEW _smoke_qbo_fx_converter 6/6). Prior same-day: S-QBO-FA-SYNC-NOTE — **closed F17** (QBO sync-hint banner partial on depreciation/impairment/fixed-asset show pages; NEW _smoke_qbo_fa_sync_note 5/5). Prior same-day: S-QBO-PAYMENTS-SETTINGS-UI — **closed F11** (QBO Payments config card on /quickbooks/settings + save_payments_config endpoint; NEW _smoke_qbo_payments_settings 3/3). Prior same-day: S-CLAUDE-CODE-REFERENCE-D131-BACKFILL — **closed F18** (backfilled the 6 missing D131 history bullets for S-QBO-14/15/18/19/20/21; docs-only). Prior same-day: S-QBO-24-GL-BALANCE-FOLLOWUP — **closed F23** (GL-account-balance drift check added to DriftChecker; gated default-off, enable at cutover; NO migration; NEW _smoke_qbo_gl_balance_drift 8/8). Prior same-day: S-QBO-CREDIT-APP-UNAPPLY — **closed F27** (credit-application un-apply: reversal service + unapply.php + pushVoid + UI; migration 85→86; NEW _smoke_qbo_credit_app_unapply 16/16). Prior same-day: S-QBO-SHOW-PANEL-PAYDOWN — **closed F8** (shared QuickBooks Sync rich panel partial wired into bills/payments/ap-payments/journal-entries/credit_notes show pages; NEW _smoke_qbo_show_panels 8/8). Prior same-day: S-QBO-PAYDOWN-NAV-VENDOR-UI — **closed F21** (Bank Accounts nav child added to config/navigation.php; 6 nav smokes 18→19) + **closed F10** (vendor currency selector added to create/edit forms + show display). Prior 2026-06-01 ships: S-QBO-27 (Historical Backfill machinery — surfaced F29 live-run follow-up) + S-QBO-17 (Refund Receipt — CLOSES Phase QBO-7; surfaced F28) + S-QBO-CREDIT-MEMO-APPLY (closed F25; surfaced F26 + F27).

---

## 🔴 BLOCKING — live test cannot proceed without operator action

### F31 — Deploy + run pending migrations on prod to end the lease-activation schema-drift cascade 🔴 BLOCKING (LIVE NOW)

**Surfaced by:** S-PROD-SCHEMA-DRIFT-SNAPSHOT-COLS (2026-06-07) — operator reported "production keeps failing to activate lease MTTS-9CMH3U-2026 Pending"; Sentry FLEETFORGE-E cascaded province_snapshot → gst_exempt_number_snapshot.
**Affects:** ALL lease activation (and credit-note / overpayment / late-fee paths) on production — currently HARD-FATALING live.
**Root cause:** prod was provisioned from an OLD baseline; 9 columns that exist in `FLEETFORGE_DATABASE_MASTER.sql` were only ever added to the baseline, never as incremental ALTER migrations, so prod never received them. A full prod-vs-master diff (all 157 tables) found EXACTLY 9 missing columns (`invoices`: gst_exempt_number_snapshot, pst_exempt_number_snapshot, late_fee_rule_id, late_fee_rule_snapshot; `credit_notes`: company_name_snapshot, customer_name_snapshot, billing_address_snapshot, province_snapshot, customer_email_snapshot) — 0 missing tables, 0 type/enum drift, 0 reverse drift.
**Operator action:**
1. Deploy the latest `main` to prod (pulls migrations `202606070200` [fixed] + `202606070300`).
2. Run migrations as the deploy user: `php /var/www/fleetforge/bin/migrate.php --dry-run` then `php /var/www/fleetforge/bin/migrate.php --apply`.
3. Verify both ran: `php /var/www/fleetforge/bin/migrate.php --status` should report `pending: 0`.
4. Verify the columns landed:
   `SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='invoices' AND COLUMN_NAME IN ('gst_exempt_number_snapshot','pst_exempt_number_snapshot','late_fee_rule_id','late_fee_rule_snapshot')) OR (TABLE_NAME='credit_notes' AND COLUMN_NAME IN ('company_name_snapshot','customer_name_snapshot','billing_address_snapshot','province_snapshot','customer_email_snapshot')));`  — must return **9**.
5. Re-attempt activation of MTTS-9CMH3U-2026 — should now succeed.
**Why blocking:** until the migrations run on prod, every lease-activation INSERT into `invoices` references columns that don't exist → SQLSTATE[42S22] 1054 → the whole activation transaction aborts.
**Note on the prior half-applied state:** prod already has `invoices.province_snapshot` (migration `202606070200` half-applied earlier and died on a bad AFTER-anchor in its credit_notes statement). `202606070200` was fixed to anchor off `credit_note_number`; both migrations are idempotent and were proven via an ordered schema-real test against a prod-shaped scratch DB (35/35) plus adversarial review. The operator does NOT need to clean up the half-applied state — the idempotent guards handle it.

---

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

### F39 — Deploy S-MONTHLY-SHORT-FLAT + remediate the MTTS73 draft invoices 🟢 DEFERRED (drafts only — no money moved)

**Surfaced by:** S-MONTHLY-SHORT-FLAT (2026-06-24) — operator report "invoice 171, 22 days charged total should have defaulted to the monthly rate."
**What changed:** the billing engine now bills a ≤1-month monthly-tier lease at the FLAT monthly rate even when it straddles a calendar-month boundary (was prorating below the flat month). The two existing MTTS73 invoices are **DRAFTS** generated under the OLD engine, so nothing was sent and no money moved — but they still hold the old $311.67 total base and won't change until regenerated under the deployed fix.
**Operator action (prod is read-only for the agent — run this yourself):**
1. `ff-deploy` the latest `main` to prod (the engine fix lives in `lib/Billing/HolisticLeaseEngine.php`).
2. Regenerate MTTS73's invoices so the total lands at the monthly rate **$425.00**. Two equivalent paths:
   - **Reopen → reclose** (preferred, per the reopen/reclose workflow): Reopen lease MTTS73, then re-close it. The close path voids the draft close invoice and recomputes via the fixed engine → INV-2026-00171 base becomes **$219.29** (was $105.96); combined with the kept INV-2026-00170 ($205.71) the total base = **$425.00**.
   - **Or void both drafts + regenerate** the whole lease in one shot → ONE invoice for Jul 24–Aug 14 = flat **$425.00** base (+ the unchanged GPS $14 + mileage $66.24 lines).
3. Confirm: lease MTTS73 total base rental = **$425.00** (the monthly rate), `rate_method_used = monthly`.
**Why deferred / not blocking:** the invoices are unsent drafts; this is a correctness top-up, not a live-billing emergency. The engine fix is in place for all FUTURE closes the moment it deploys; only this one already-drafted lease needs the manual regenerate.

### F38 — Clear the pre-fix device-offline notification backlog on prod 🟢 DEFERRED (one-time cleanup, ready)

**Surfaced by:** S-SAMSARA-OFFLINE-NOTIF-OFF (2026-06-23) — operator reported "I still see the device offline notifications" after the generator was removed.
**Root cause:** the fix (cron/samsara_sync.php, commit 43f9ce3) stops NEW device-offline notifications — verified live on prod 2026-06-23 (newest `samsara.not_connected` row 16:45:36; zero new across the ~5 cron ticks after deploy). But it does NOT retroactively clear the backlog that accumulated 2026-06-06 → 2026-06-23: **21,187 rows (18,356 unread), ~92% of all unread notifications**, one row per recipient — so they keep filling every user's bell until cleared.
**Operator action (prod is read-only for the agent — run this yourself):**
1. Ensure prod has the latest `main` (the cleanup script is `scripts/clear_device_offline_notifications_2026_06_23.php`).
2. Dry run (counts only, changes nothing): `php /var/www/fleetforge/scripts/clear_device_offline_notifications_2026_06_23.php`
3. Apply — **recommended** (removes them from the bell AND the full list; recoverable via `deleted_at = NULL`): `php /var/www/fleetforge/scripts/clear_device_offline_notifications_2026_06_23.php --apply --soft-delete`
   - Alternative (keeps them as read history, just clears the unread badge): `... --apply --mark-read`
   - Or, without waiting for a deploy, run the equivalent SQL directly: `UPDATE notifications SET deleted_at = NOW() WHERE type = 'samsara.not_connected' AND deleted_at IS NULL;`
**Why deferred / not blocking:** purely cosmetic backlog; no revenue/data risk. Script is DRY-RUN by default, idempotent, and scoped precisely to `type = 'samsara.not_connected'` (battery/lease/invoice notifications untouched). Soft-delete is non-destructive (the rows stay in the DB).

### F37 — Schedule / exclude MySQL auto-restart from the unattended-upgrades window (optional infra hardening) 🟢 DEFERRED

**Surfaced by:** S-DB-CONNECT-RETRY (2026-06-23) — root-cause of the recurring prod Sentry issue `PDOException: SQLSTATE[HY000] [2002] Connection refused` (`includes/db.php`).
**Root cause:** `unattended-upgrades` applied a `mysql-server` security update at 2026-06-23 06:11 (also 2026-06-03 06:28) and restarted `mysqld` for ~10s; during the window every request — chiefly the high-frequency unread-count pollers (`messenger/unread`, `chat/unread/count`) — threw a raw PDOException → 500 + Sentry. 3 restart windows in the retained logs ≈ the "30 users / 30d" Sentry count.
**Mitigated in code (already shipped this session):** `db_pdo()` now retries transient connect failures with bounded exponential backoff (`db_connect_with_retry` + `db_is_transient_connect_error`), so short blips and the reconnection edge are ridden out transparently. This alone substantially cuts the error count.
**Operator action (optional, to fully eliminate it) — pick ONE:**
1. **Accept it (recommended)** — keep auto-security-updates ON; the ~06:1x restart is a low-traffic window and the app now retries. Lowest effort, stays fully patched.
2. **Pin the apt window** to a known low-traffic time: `sudo systemctl edit apt-daily.timer` → set `OnCalendar=` a chosen hour + `RandomizedDelaySec=0`, so the restart is predictable.
3. **Exclude MySQL from auto-upgrades** and patch it manually in a maintenance window: add `"mysql-server"; "mysql-server-8.0";` to `Unattended-Upgrade::Package-Blacklist` in `/etc/apt/apt.conf.d/50unattended-upgrades`. **Tradeoff:** you must remember to apply MySQL security updates by hand.
**Why deferred / not blocking:** the shipped code retry already mitigates; this is infra-policy hardening the agent must not change on prod (prod is operator-only for writes). No revenue/data risk.

### F32 — S-CLOSE-OVERSHOOT — sent/paid straddle credit is linear-day, not tier-aware

**Surfaced by:** S-CLOSE-OVERSHOOT (2026-06-19) adversarial review (money-correctness, confirmed MEDIUM).
**What:** When a lease close clamps an over-billed invoice that is already **sent/paid** (immutable, cannot regenerate), `adv_partial_refund_containing()` in `api/v1/leases/_close_reconciliation.php` issues a credit note for `total_amount × (unusedDays / totalDays)` — a **linear** day-fraction. But base-rental pricing is **tiered/capped** (`lib/Billing/ProRateCalculator.php`: 1–5d daily, 6–7d weekly-flat, 8–29d weekly capped at monthly, 30d+ monthly), so the linear credit can over- or under-refund a sent/paid invoice with tiered pricing (review example: ~$128 over-refund on a $2000 monthly-capped invoice returned at 11 of 27 days). **Draft invoices are NOT affected** (they are voided + regenerated with the exact engine amount). This is **pre-existing behavior** inherited from the advance-billing path (`adv_partial_refund_containing` predates this session); S-CLOSE-OVERSHOOT widened where it applies (non-advance sent/paid straddles).
**Impact / why deferred:** The prod incident (7 invoices) are all **drafts** → engine-correct, unaffected. The linear path only bites sent/paid invoices that straddle the return with tiered pricing — a narrow population. A correct fix (`credit = original_total − engine-recomputed charge for [start..extent]`) must reconstruct tax/discount/FX for an immutable invoice and is non-trivial; deferred to avoid destabilizing the shared advance primitive.
**Fix when picked up:** replace the linear fraction with `original_total − createFromLease-equivalent total for [start..extentEnd]` (or a dry-run engine calc), and add a tier-boundary smoke. Applies to BOTH the advance and overshoot callers of `adv_partial_refund_containing`.

### F33 — S-CLOSE-OVERSHOOT — voiding a drawdown-carrying draft does not restore precharge_balance

**Surfaced by:** S-CLOSE-OVERSHOOT (2026-06-19) adversarial review (edge-cases, confirmed MEDIUM; narrow trigger).
**What:** `adv_void_invoice()` reverses Path-B counters + the accounting JE but does **not** add back `leases.precharge_balance` that an invoice consumed via a mileage `drawdown_credit` line (`InvoiceGenerator.php:786` only ever decrements; nothing restores it on void — repo-wide). If the overshoot pass voids a **draft that carried a drawdown**, the close-time precharge refund (`close.php` re-reads `precharge_balance`) **under-refunds** the customer. This is a **pre-existing gap in the void path** (also reachable via the advance close branch); S-CLOSE-OVERSHOOT can now reach it for non-advance drafts.
**Impact / why deferred:** The canonical overshoot case (activation `partial_start` Invoice 1) has `precharge_invoiced_at = NULL` → carries **no** drawdown, so it is unaffected. The trigger requires a precharge-enabled lease with a manually-created draft that has odometer/drawdown AND overshoots — narrow. Touching precharge accounting is sensitive; deferred to scope properly.
**Fix when picked up:** in `adv_void_invoice()` (or a close-time pre-pass), sum the voided invoice's `mileage_drawdown_credit` lines and `UPDATE leases SET precharge_balance = precharge_balance + <restored>` inside the close transaction, before `close.php` re-reads the residual; add a precharge+drawdown+overshoot smoke.

### F34 — S-LEASE-MIN-DAYS — prod deploy (migration + backfill) + reconcile the pre-existing schema-master drift 🟡 PARTIAL

**Surfaced by:** S-LEASE-MIN-DAYS (2026-06-19, `<this commit>`).
**What (two independent operator actions):**
1. **Deploy the feature to prod.** Apply migration `db_migrations/202606190400_S-LEASE-MIN-DAYS_minimum_billing_days.sql` (`php bin/migrate.php --apply`), then run the chassis backfill — **dry-run first**: `php scripts/backfill_minimum_days_chassis_leases.php` (review the affected ACTIVE chassis leases), then `php scripts/backfill_minimum_days_chassis_leases.php --commit`. Both are idempotent and default-safe (NULL/0/1 = no floor), so prod behaviour is unchanged until applied.
2. **Reconcile the pre-existing master/migration drift this session exposed (operator owns it per the commit-grouping decision).** This commit's master edit is intentionally ONLY the two min-days columns. The dev DB also carries two OTHER sessions' uncommitted schema that the master is now MISSING, so `tests/_smoke_master_schema_parity.php` is **RED** (23 drift lines): **(a)** `ai_pending_changes` table — **S-AI-WRITE-1** code + migration `202606182600` are already committed, only the master CREATE TABLE was never synced; **(b)** `leases.mileage_tracking_mode` — **S-LEASE-MILEAGE-MODE** migration `202606190300_S-LEASE-MILEAGE-MODE_add_mileage_tracking_mode.sql` is UNTRACKED (applied to dev 2026-06-18, checksum 407e6919…, 0 drift) and has no feature code anywhere. To restore parity green: commit `ai_pending_changes` into the master; and commit the untracked mileage migration + its master column (or drop the column from dev if S-LEASE-MILEAGE-MODE is being abandoned). **Caveat:** migration `202606190400` (this session, already applied) adds `minimum_billing_days … AFTER mileage_tracking_mode`, so a FRESH-DB replay needs `202606190300` committed first (timestamp-ordered before it). On existing dev/prod DBs this is moot (INFORMATION_SCHEMA-guarded, columns already present).
**Why 🟡 (not blocking):** the feature ships default-safe so #1 is a normal activation; #2 is pre-existing drift unrelated to this feature (parity was already red at HEAD) that the operator elected to reconcile separately to avoid bundling three sessions into one commit.

**Update 2026-06-19 (`ca6dc2f`):** sub-item #2(a) DONE — `ai_pending_changes` synced into master; `tests/_smoke_master_schema_parity.php` is now down to a SINGLE drift line. Remaining: #2(b) — commit the untracked `db_migrations/202606190300_S-LEASE-MILEAGE-MODE_add_mileage_tracking_mode.sql` + its master column (or drop the column from dev if S-LEASE-MILEAGE-MODE is abandoned) to restore parity green; and #1 (prod migration apply + chassis backfill).
**Update 2026-06-19 (later):** #2(b) is now CLOSED — the S-LEASE-MILEAGE-MODE session shipped (migration + `mileage_tracking_mode` re-synced into master, both committed). Only #1 (prod migration apply + chassis backfill) remains open under F34.

### F35 — S-LEASE-CLOSE-REMOVE-DAYS — prod deploy + the concurrent S-CRON-TOGGLES drift it surfaced 🟡 PARTIAL

**Surfaced by:** S-LEASE-CLOSE-REMOVE-DAYS (2026-06-19, `<this commit>`).
**What (two independent operator actions):**
1. **Deploy the feature to prod.** Apply migration `db_migrations/202606191300_S-LEASE-CLOSE-REMOVE-DAYS_billing_days_removed.sql` (`php bin/migrate.php --apply`). NO backfill — the column defaults `0` (= no removal), so prod behaviour is unchanged until an operator enters a value at close. Default-safe.
2. **Reconcile the concurrent S-CRON-TOGGLES drift this session exposed in the shared dev tree (NOT mine — a parallel session).** Uncommitted at the time of this commit: `cron/*` (12 files: accounting_recurring_entries, collections_auto_escalate, compliance_alerts, gps_mileage_sync, health_scores, invoice_generate_monthly, invoice_overdue, late_fee_apply, promise_to_pay_check, risk_scores, samsara_sync, stale_reservations), `includes/functions.php`, `app/admin/settings/index.php`, `config/cron_jobs.php` (new), `db_migrations/202606191300_S-CRON-TOGGLES_scheduled_jobs.sql` (new), `tests/_smoke_cron_toggles.php` (new). The `scheduled_jobs` table is applied to the dev DB but is **absent from `FLEETFORGE_DATABASE_MASTER.sql`**, so `_smoke_master_schema_parity` stays RED on that table until S-CRON-TOGGLES commits its master sync. The S-CRON-TOGGLES owner should commit it (with its own master sync) on its own commit.
**⚠️ MIGRATION TIMESTAMP COLLISION:** both `202606191300_S-CRON-TOGGLES_scheduled_jobs.sql` and `202606191300_S-LEASE-CLOSE-REMOVE-DAYS_billing_days_removed.sql` use the prefix `202606191300` (chosen concurrently). This is **benign** — the runner keys on full filename, both are already applied to dev under their own distinct names with their own checksums, and they have no inter-dependency (sort order C < L is harmless). Do NOT rename either after the fact (renaming orphans the applied `schema_migrations` record → migrate-verify drift). Future sessions should pick the next free timestamp to avoid the collision class.
**Why 🟡 (not blocking):** #1 is a normal default-safe deploy; #2 is another session's uncommitted work that this session merely surfaced (the recurring shared-dev-tree drift pattern — see memory `project_master_sync_sweeps_drift`).

### F36 — S-INVOICE-AUDIT-FIX-2 (I14) — live-SES verify that email PDF attachments actually arrive 🟡 PARTIAL

**Surfaced by:** S-INVOICE-AUDIT-FIX-2 (2026-06-20, `<this commit>`) — the deferred half of S-INVOICE-AUDIT-FIX-1 (I14).
**What:** `lib/Notifications/Mailer.php` now delivers attachment-bearing mail via SES **`sendRawEmail`** with a hand-built `multipart/mixed` MIME message (text+html `multipart/alternative` body + base64 `application/pdf` parts), and `lib/Email/EmailService::send()` threads the resolved attachment bytes through (read from storage via the new `StorageClient::read()`). Before this fix, SES `sendEmail` silently dropped every attachment — invoice/document PDFs from the Compose modal were logged "attached" but never delivered. No-attachment mail is unchanged (still the plain `sendEmail` API), so the blast radius is limited to attachment sends.
**Operator action (cannot be done by the agent — prod SES is sandbox/read-only):**
1. From a real invoice, use the **Compose Email** modal (admin invoice show page) and attach the invoice PDF (or any customer document), then send to a mailbox you control.
2. Confirm the email **arrives with the PDF attached** and the PDF **opens/renders** correctly (not corrupted).
3. Confirm a **plain** (no-attachment) email still sends normally — i.e. the new raw path did not disturb the default path.
4. Optionally test a non-ASCII filename / subject to confirm encoding renders in your mail client.
**Why 🟡 PARTIAL (not blocking):** the MIME is built + unit-verified locally (`tests/_smoke_mailer_attachments.php` 22/22: byte round-trip, base64 round-trip to exact bytes, CRLF header-injection neutralized, RFC 2047/2231 encoding, 998-char line limit, EmailService threading + fail-closed). Live SES round-trip cannot be exercised by the agent. Until step 1–3 pass, treat invoice-PDF email delivery as "code-complete, unverified in prod." Risk if MIME were wrong = broken outbound mail, so verify before relying on it for customer billing.

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

### F7 — pushVoid absent in PaymentPusher + BillPaymentPusher v1 ✅ CLOSED 2026-06-01

**Surfaced by:** S-QBO-14 + S-QBO-19 (2026-05-28/29)
**Affects:** void semantics for FF-native payments + bill payments + credit memos

**Closed by:** S-QBO-PUSHVOID-TRIO (2026-06-01) — D-QBO-PUSHVOID-TRIO-1 locked. `pushVoid` implemented for PaymentPusher + BillPaymentPusher + CreditMemoPusher, each modeled on InvoicePusher::pushVoidImpl (D-QBO-12-3/4/5): separate pipeline; idempotent on push_status='voided' → already_voided; no mapping → skipped_unmapped_void; HTTP via the uniform QuickBooksClient::voidEntity(type,id,syncToken). **Per-entity void trigger:** bill_payment + credit_note key on status='void' (ap-payments/void.php + credit_notes/void.php); **payment keys on deleted_at IS NOT NULL** (no status='void' on payments — the soft-delete path payments/delete.php is the void; refunded/partially_refunded → a future RefundReceipt entity, out of scope). PaymentPusher::pushVoid also carries the D-QBO-14-1 origin guard. All 3 Enqueuers: gate-3 allowlist widened += 'void' + per-entity gate-0 void eligibility; enqueue('void') hooks wired post-commit into the 3 FF void endpoints. NO migration ('voided'/'skipped_voided' already in all 3 map ENUMs). Smokes: payment 23→26, bill_payment 27→30, credit_memo 28→30. **Every entity Pusher now supports create + update + void.** Commits a4fe6bf + 3060d0e (impl) + 48eb468 (enqueuer-gate repair + green smokes). See FLEETFORGE_PROGRESS.md SESSION LOG.

**Why deferred originally:** v1 handled voided payments only at the skipped_unmapped_void level (don't push voids that never made it to QBO); POST-push voids needed QBO-side void API calls. The uniform QuickBooksClient::voidEntity + the InvoicePusher::pushVoidImpl template made it a clean mirror; the only per-entity judgment was the void *trigger* (payment soft-delete vs status='void'), resolved via AskUserQuestion.

---

### F8 — S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN — rich QBO sync panel on FF show pages ✅ CLOSED 2026-06-01

**Closed by:** S-QBO-SHOW-PANEL-PAYDOWN (2026-06-01). Generalized the invoices/show.php "QuickBooks Sync" rich panel into a reusable partial `includes/partials/qbo-sync-panel.php` (6-state badge + identifiers row [QBO id deep-link + pushed-relative + currency + sync token] + last-20 Push History table + Retry/View-in-QBO actions) and wired it into all 5 parallel show pages: `app/admin/accounting/bills/show.php`, `app/admin/payments/show.php`, `app/admin/accounting/ap-payments/show.php`, `app/admin/accounting/journal-entries/show.php`, `app/admin/credit_notes/show.php` (the last had ZERO QBO mentions before — now at parity with invoices). The partial takes a per-page `$qboPanel` config (entity_type / map_table / qbo_id_col / ff_fk / ff_id / deep_link / retry_url), validates table+columns against a whitelist (fail-closed), renders nothing when QBO is disconnected, and reuses the per-entity retry endpoints. NEW smoke `_smoke_qbo_show_panels` 8/8 guards each page's wiring. NO migration / schema change.

**Original report (preserved):**

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

### F9 — S-QBO-BILL-ITC-TAX-RATE-MAPPING — ITC tax-rate mapping ✅ CLOSED 2026-06-01 (gated default-off; per-rate verify at cutover)

**Closed by:** S-QBO-BILL-ITC-TAX-RATE (2026-06-01). Built the opt-in per-rate bill-tax emission, GATED default-off so the proven override path is completely untouched (D-QBO-BILL-ITC-1). Migration 86→87 adds `acc_qbo_tax_rate_map` (FF tax component gst/pst/hst → QBO `TaxRate.Id`) + seeds `quickbooks.bill.tax_mode='override'`. `BillPusher::buildBillTaxDetail()` returns the EXACT current override shape (`{TotalTax}`) when `tax_mode!='per_rate'`; under `per_rate` it emits `TxnTaxDetail.TaxLine[]` per non-zero mapped component (spec §8.8 ITC), and **falls back to override on any unmapped non-zero component** — never ships a partial/incorrect tax detail. Admin UI: an "ITC Tax-Rate Mapping" section on `/quickbooks/tax_codes` (mode toggle + per-component QBO TaxRate.Id inputs) + NEW `api/v1/quickbooks/save_tax_rate_map.php`. NEW smoke `_smoke_qbo_bill_itc_tax_rate` 7/7 (override byte-identical; per_rate emit/fallback/zero; qboTaxRateId). The existing `_smoke_qbo_bill_push` 25/25 re-verified green (override untouched).

**DEFERRED to cutover (live-verify):** the QBO TaxRate pull (discovering the real `TaxRate.Id`s — needs a live connection; until then the operator enters ids manually) + the actual per-rate push verified against the live company before flipping `tax_mode='per_rate'`.

**Original report (preserved):**

**Surfaced by:** S-QBO-18 (2026-05-27) — D-QBO-18-2 noted ITC tax-rate mapping deferred
**Affects:** bill push tax-line emission with per-rate detail (instead of override pattern)
**Operator action:** queue a session that adds `acc_qbo_tax_rate_map` table + TaxRatePuller + TaxRateMatcher + ITC eligibility flag wiring on bill_line, enabling per-rate tax detail per QBO_SPEC §8.8 example.

**Why deferred:** S-QBO-9 maps tax CODES, not tax RATES. Building per-rate mapping is its own session. v1 BillPusher uses the tax-override pattern (every line TaxCodeRef='NON' + header TxnTaxDetail.TotalTax via bcmath) which is consistent but doesn't expose ITC tax detail in QBO.

---

### F10 — S-VENDOR-UI-CURRENCY-SELECTOR — admin UI vendor currency selector ✅ CLOSED 2026-06-01

**Surfaced by:** S-VENDOR-CURRENCY-COLUMN (2026-05-27) — D-VENDOR-CURRENCY-COLUMN-4
**Closed by:** S-QBO-PAYDOWN-NAV-VENDOR-UI (2026-06-01). `app/admin/vendors/create.php` + `edit.php` Alpine forms now expose a Currency selector (CAD/USD) — added to the form markup, the `form` object init (create default 'CAD'; edit seeded from `$vendor['currency']`), and the submit payload. The create form notes "QBO locks vendor currency at creation"; the edit form notes a change only reaches QBO on a re-create (VendorPusher strips CurrencyRef from UPDATE payloads, per the existing update.php comment). `app/admin/vendors/show.php` gains a Currency display row. Backend (vendors.currency ENUM + VendorPusher CurrencyRef + API accept) already shipped S-VENDOR-CURRENCY-COLUMN — this was UI-only, no schema/API change.

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

### F15 — FX revaluation for multi-currency mirror rows (`S-QBO-FX-RECON-FOLLOWUP`) ✅ CLOSED 2026-06-01 (display half; live revaluation → cutover)

**Closed by:** S-QBO-FX-RECON-FOLLOWUP (2026-06-01). Resolved the operator-confusion half (a USD bank-account mirror row showing a bare USD figure on a CAD-context page): NEW `lib/FxConverter.php` (homeCurrency reads `quickbooks.home_currency`; isForeign; homeEquivalent = foreign × frozen pull-time rate; homeEquivalentLabel) + the bank-transactions list (`app/admin/accounting/bank-transactions/index.php`) now LEFT-JOINs `acc_qbo_bank_transaction_map` (the active `pulled` snapshot) and, for a foreign-currency row, renders a **currency badge + "≈ CAD X.XX" at the frozen `qbo_exchange_rate_snapshot`** (QBO ExchangeRate convention = home units per 1 foreign unit). Chose **display-time conversion at the frozen rate** over a live FX feed (D-QBO-FX-1) — deterministic, can't drift/mislead, and needs no ongoing feed. No-op today (all live bank accounts are CAD); activates correctly when a USD account is mapped. NEW smoke `_smoke_qbo_fx_converter` 6/6. NO migration (the snapshot columns + home_currency setting pre-existed).

**DEFERRED to cutover (the true "revaluation" half):** recomputing unrealized FX gain/loss as rates move — needs a live FX feed, so it's verify-blocked; frozen-rate display never moves so it carries no risk in the interim.

**Original report (preserved):**

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

### F-PAYDOWN-PROGRESS — S-QBO-PUSHER-UPDATE-FOLLOWUPS-PAYDOWN ✅ MECHANICAL ARC COMPLETE (5/5 update slices shipped)

**Surfaced by:** S-QBO-BILL-UPDATE (2026-05-31) — first slice of the umbrella paydown
**Updated:** 2026-05-31 — S-QBO-JE-UPDATE shipped (5th + FINAL slice; F13 closed). **All five pushUpdate stubs (Bill / Payment / BillPayment / CreditMemo / JE) are now implemented.** Remaining QBO update-debt is NOT mechanical-mirror work: the pushVoid trio (F7) + the carved-out credit-memo apply→LinkedTxn (F25, needs a migration).

The umbrella paydown (F5 payment / F6 bill_payment / F13 JE / F20 credit_memo update stubs + the pushVoid trio in F7) is being worked one Pusher at a time. **BillPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-BILL-UPDATE)** as the proven template: routes through `pushImpl` with `operation='update'` → full-payload re-send via `QuickBooksClient::updateEntity` + SyncToken round-trip; demote-to-create when unmapped (D-PUSHER-DEMOTION-RULE); `BillEnqueuer` gate-3 widened to accept `'update'`; `enqueue('update')` wired into `api/v1/accounting/bills/update.php`. **PaymentPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-PAYMENT-UPDATE — F5 closed)** as the 2nd slice (mechanical mirror of the bill template): demote-to-create at pushImpl step 7b sits AFTER the origin/pulled_from_qbo dedup gates (steps 5+6) so the D-QBO-14-1 bidirectional dedup invariant covers the UPDATE verb too without extra code — locked by C23 smoke. PaymentEnqueuer gate-3 widened ['create']→['create','update']. **D2 divergence vs Bill template: NO enqueue hook in api/v1/payments/update.php** — of the 5 editable metadata fields, only reference_number→PaymentRefNum is QBO-pushable; reference_number sync rides the manual-sync path via /quickbooks/manual_sync → Force re-sync (payments) (S-QBO-26 force_resync). **BillPaymentPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-BILL-PAYMENT-UPDATE — F6 closed)** as the 3rd slice (mechanical mirror; simplest of the three because acc_ap_payments has no `origin` column per D-QBO-19-1 — no payment-style dedup-gate dance, demotion at step 5b same as Bill template). BillPaymentEnqueuer gate-3 widened ['create']→['create','update']. **D2 alignment with Bill template: WIRED enqueue('update') hook in api/v1/accounting/ap-payments/update.php** — 3 of 4 editable fields (payment_date / reference_number / check_number) directly affect the QBO BillPayment payload as TxnDate + PrivateNote; only `notes` is FF-only. **CreditMemoPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-CREDIT-MEMO-UPDATE — F20 pushUpdate stub closed)** as the 4th slice. Mechanical mirror only (updateEntity('creditmemo') full-payload re-send + demote-to-create at step 5b + CreditMemoEnqueuer gate-3 widened). **Key finding (operator-confirmed scope decision):** F20's original "apply→LinkedTxn" framing turned out to be a DIFFERENT, larger operation than the mechanical pushUpdate. Credit notes have no editable header (no credit_notes/update.php), so the mechanical update is near-noop (SyncToken/qbo_balance refresh); the valuable credit-APPLICATION propagation needs a zero-dollar QBO Payment with CreditMemo+Invoice LinkedTxns + a migration (no linkage column) + an apply.php hook — **carved out to NEW follow-up F25** (S-QBO-CREDIT-MEMO-APPLY-FOLLOWUP). NO enqueue hook wired this slice (mechanical update rides manual-sync force_resync). **JournalEntryPusher::pushUpdate SHIPPED 2026-05-31 (S-QBO-JE-UPDATE — F13 closed)** as the 5th + FINAL slice. Mechanical mirror; demotion at step 5b placed AFTER the bridge-derived gate (step 4) so an update of a bridge-derived JE is rejected as skipped_bridge_derived before the operation branch (D-QBO-21-1 covers the UPDATE verb). JournalEntryEnqueuer gate-3 widened ['create']→['create','update']; 'void' rejected (not a JE concept — JEs reverse via a companion posted JE). D2: NO enqueue hook (no journal_entries/update.php — JEs immutable post-posting; rides manual-sync). smoke 31→33; D-QBO-JE-UPDATE-1 locked. **✅ MECHANICAL ARC COMPLETE: all 5 pushUpdate stubs (Bill / Payment / BillPayment / CreditMemo / JE) implemented.** **Remaining QBO update-debt — NOT mechanical-mirror work:** (1) the **pushVoid trio (F7)** — PaymentPusher / BillPaymentPusher / CreditMemoPusher `pushVoid` (mirror InvoicePusher `pushVoidImpl`); (2) the carved-out **credit-memo apply→LinkedTxn (F25)** — a zero-dollar QBO Payment with CreditMemo+Invoice LinkedTxns, **needs a migration** (no linkage column), so NOT a no-migration mechanical slice.

### F13 — S-QBO-21-UPDATE-FOLLOWUP — JournalEntryPusher::pushUpdate impl ✅ CLOSED 2026-05-31

**Surfaced by:** S-QBO-21 (2026-05-29) — D-QBO-21-5 stub-then-implement pattern
**Closed by:** S-QBO-JE-UPDATE (2026-05-31) — D-QBO-JE-UPDATE-1 locked. JournalEntryPusher::pushUpdate now routes through the shared pushImpl with operation='update' → full-payload re-send via QuickBooksClient::updateEntity('journalentry', ...) + SyncToken refresh (mirrors InvoicePusher D-QBO-12-1 + the Bill/Payment/BillPayment/CreditMemo slices). Demote-to-create when unmapped per D-PUSHER-DEMOTION-RULE at pushImpl step 5b — placed AFTER the bridge-derived gate (step 4), so an update of a bridge-derived JE is rejected as skipped_bridge_derived before the operation branch (D-QBO-21-1 double-accounting guard covers the UPDATE verb). JournalEntryEnqueuer gate-3 widened ['create']→['create','update']; 'void' rejected — not a JE concept (JEs reverse via a companion posted JE pushed as its own create). **D2: NO enqueue hook** — there is no journal_entries/update.php (JEs immutable post-posting; endpoints are approve/post/recall/reverse/submit); the mechanical update rides manual-sync force_resync / drift resync. Smoke _smoke_qbo_journal_entry_push 31→33 (incl. C32 demote-to-create + C33 reject-void). NO migration. **This CLOSES the S-QBO-PUSHER-UPDATE-FOLLOWUPS-PAYDOWN mechanical arc (5/5).** See FLEETFORGE_PROGRESS.md SESSION LOG row.

**Why deferred originally:** v1 shipped pushCreate; the prompt feared QBO's restrictive JournalEntry update model (sparse=true + Active=false void approximation). In practice the FF-canonical full-payload re-send (D-QBO-CORE-1) sidesteps that — FF sends the complete balanced line set + current SyncToken and QBO replaces, same as every other entity. Resolved by the pattern proven across the four prior slices.

---

### F11 — Admin settings UI for `quickbooks.payments.*` keys ✅ CLOSED 2026-06-01

**Closed by:** S-QBO-PAYMENTS-SETTINGS-UI (2026-06-01). Added a "QBO Payments Configuration" card to `app/admin/quickbooks/settings.php` (gated on `edit_credentials`) exposing `payments.success_url` + `payments.cancel_url` (text) + `payments.url_ttl_minutes` (number, 1–1440), backed by NEW `api/v1/quickbooks/save_payments_config.php` (validates + writes via `QuickBooksClient::settings_write_qbo`). The master `payments_enabled` toggle stays in Master Controls (super-admin). NEW smoke `_smoke_qbo_payments_settings` 3/3. NO migration (keys seeded by S-QBO-15). D-QBO-PAYMENTS-SETTINGS-UI-1.

**Original report (preserved):**

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

### F17 — Fixed-asset admin pages: "QBO sync pending" indicator ✅ CLOSED 2026-06-01

**Closed by:** S-QBO-FA-SYNC-NOTE (2026-06-01). NEW reusable partial `includes/partials/qbo-fa-sync-note.php` renders a small info banner (when QBO is connected) noting that the surface's depreciation/disposal/impairment JEs enqueue for QBO push on posting + a deep-link to `/quickbooks/journal_entries?source_filter=fa` (the D-QBO-22-3 canonical FA sync view). Wired into `app/admin/accounting/depreciation/index.php` (context 'depreciation'), `impairment/index.php` ('impairment'), and `fixed-assets/show.php` ('fixed-asset'). The banner adapts its wording to whether `sync_enabled` is on (enqueues now) vs off (recorded, will enqueue at cutover). Pure UX hint — gates nothing. NEW smoke `_smoke_qbo_fa_sync_note` 5/5. NO migration/schema/endpoint change.

**Original report (preserved):**

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

### F18 — CLAUDE_CODE_REFERENCE D131 history paragraph backfill ✅ CLOSED 2026-06-01

**Closed by:** S-CLAUDE-CODE-REFERENCE-D131-BACKFILL (2026-06-01). Added the 6 missing D131 history bullets for S-QBO-14 / 15 / 18 / 19 / 20 / 21 to `docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md` (the gap the S-QBO-22 entry flagged), each indexing the smoke that session added + its original sub-check count (with the current count noted where later update/void slices extended it). Docs-only — NO code/schema/UI change.

**Original report (preserved):**

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

### F21 — `config/navigation.php` is MISSING the Bank Accounts QuickBooks child ✅ CLOSED 2026-06-01

**Closed by:** S-QBO-PAYDOWN-NAV-VENDOR-UI (2026-06-01). Added the `Bank Accounts` child to `config/navigation.php` between Tax Codes and Items (icon `building-library`, url `/quickbooks/bank_accounts`) — matching the partial's position. The 6 nav-asserting smokes were bumped 18→19 with 'Bank Accounts' inserted into their `$expected`/`$expectedOrder` arrays after 'Tax Codes'. config/navigation.php (19 children) + includes/partials/quickbooks-nav.php (19) + the smokes now all agree. (Original counts in this entry — 15/16 — predate the Refund Receipts + Manual Sync additions; at close time both sources sit at 19.)

**Original report (preserved):**

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

### F23 — S-QBO-24-GL-BALANCE-FOLLOWUP — GL-account-balance drift check ✅ CLOSED 2026-06-01 (machinery; gated default-off → enable at cutover)

**Closed by:** S-QBO-24-GL-BALANCE-FOLLOWUP (2026-06-01). Added the GL-account-balance drift check to `DriftChecker`: `ffAccountNaturalBalance()` (FF account balance from posted JE lines, signed per account_type — debit-normal asset/expense = D−C, credit-normal liability/equity/revenue = C−D, so it aligns with QBO's positive-normal `CurrentBalance`), `glBalanceDrifts()` (|Δ| > tolerance), and `checkGlAccountBalances()` (for each mapped `acc_qbo_account_map` row, compares FF balance vs `qbo_current_balance` snapshot → `category='balance_drift'`, `entity_type='gl_account'` event when beyond tolerance; LIVE layer refreshes `qbo_current_balance` from QBO `Account.CurrentBalance` first). Wired into `runCheck()` after the entity loop. Tolerance `quickbooks.drift.tolerance.gl_account` default $1.00. **GATED default-off** via `quickbooks.drift.gl_balance_enabled='0'` (D-QBO-GL-BALANCE-1) — GL balances legitimately diverge intra-period, so this is noise until the accountant defines the reconciliation cadence; **operator enables it at cutover**. NO migration (`qbo_current_balance` + `balance_drift` category + the setting all pre-existed). NEW smoke `_smoke_qbo_gl_balance_drift` 8/8 (natural-balance sign for both account types + drift decision + gated no-op + records-drift-when-enabled). Events surface on the existing `/quickbooks/drift` page (dynamic category/entity filters). **Operator action at cutover:** flip `quickbooks.drift.gl_balance_enabled='1'`, tune `tolerance.gl_account`, and verify the FF-vs-QBO sign convention against real account data before relying on it.

**Original report (preserved):**

**Surfaced by:** S-QBO-24 (2026-05-30) — D-QBO-24-3 scope deferral
**Operator action:** queue a follow-up to add the GL-account-balance drift check (spec §15.2 step 4 + §15.5 GL row) to DriftChecker: for each mapped `acc_qbo_account_map` row, compare the FF account running balance vs the live QBO `Account.CurrentBalance`; if `|delta| > $1.00` (configurable tolerance) → `category='balance_drift'` drift event.

**Why deferred:** distinct sub-system from the 8 entity-map checks — it compares balances (not entity counts/totals), `acc_qbo_account_map` is Puller-only (accountant owns COA), and it needs a per-account live QBO balance API call. Only meaningful post-cutover (sync_enabled='1'). Keeps S-QBO-24 v1 focused on the entity-drift bulk.

---

### F25 — S-QBO-CREDIT-MEMO-APPLY-FOLLOWUP — credit-memo apply→LinkedTxn propagation ✅ CLOSED 2026-06-01

**Surfaced by:** S-QBO-CREDIT-MEMO-UPDATE (2026-05-31) — carved out of F20 because the apply→LinkedTxn flow needed a migration + a new QBO entity (zero-dollar Payment) + an `apply.php` enqueue hook, which would have expanded the no-migration paydown's scope.

**Closed by:** S-QBO-CREDIT-MEMO-APPLY (2026-06-01) — `CreditApplicationPusher::pushCreate` + `CreditApplicationEnqueuer::enqueue` + migration `202606010000_S-QBO-CREDIT-MEMO-APPLY.sql` (migrate 82→83; ALTER acc_qbo_sync_queue entity_type ENUM += 'credit_application'; CREATE acc_qbo_credit_application_map; seed `quickbooks.sync_mode.credit_application='sync'`). Post-commit `enqueue('create')` hook wired into `api/v1/credit_notes/apply.php`. Admin UI shares `app/admin/quickbooks/credit_memos.php` per CLASS 12 (new "Applications → QBO LinkedTxn" section + `api/v1/quickbooks/credit_applications/{list,retry}.php`). Smoke `_smoke_qbo_credit_application_push` 26/26 PASS; `_smoke_qbo_queue` C8 widened to assert `hasImplementation('credit_application','create')===true` + update/void===false. Locked as D-QBO-CREDIT-MEMO-APPLY-1/-2/-3/-4/-5.

**Big-picture milestone:** every QBO entity sync path — create, update, void, AND apply — is now complete. QBO update-debt fully paid down.

**Operator action:** none for F25 itself (CLOSED). See **F26** for the auto-apply pre-req that gates live cutover.

**Carry-overs (new follow-ups surfaced by this session):**
- **F26** — QBO "Automatically apply credits" setting must be OFF before cutover (gates live correctness)
- **F27** — un-apply / void-after-apply path (forward-apply-only is v1 scope per D-QBO-CREDIT-MEMO-APPLY-4)

---

### F26 — QBO "Automatically apply credits" setting must be OFF before cutover 🔴 BLOCKING

**Surfaced by:** S-QBO-CREDIT-MEMO-APPLY (2026-06-01) — D-QBO-CREDIT-MEMO-APPLY-3
**Affects:** any FF credit application that propagates to QBO via `CreditApplicationPusher`. With auto-apply ON, QBO will auto-apply the CreditMemo to the Invoice the moment they share a CustomerRef, and our explicit zero-dollar Payment will then attempt a second application → double-application, corrupt AR ledger.
**Operator action:**
1. In QBO: Account & Settings → Advanced → Automation → **turn OFF "Automatically apply credits"** for the realm being connected (both sandbox + production)
2. Verify in QBO UI that the toggle is OFF before flipping FF's `quickbooks.sync_enabled='1'` in S-QBO-30 cutover
3. Run a test apply (create FF credit → apply to FF invoice → confirm a SINGLE QBO Payment row appears with TotalAmt=0 + 2 LinkedTxns; confirm the QBO Invoice's `Balance` decremented by exactly `amount_applied`, not 2×)

**Why blocking:** QBO has no runtime API to disable auto-apply per-transaction. With the setting ON, our explicit apply Payment and QBO's implicit auto-apply both fire — there is no idempotency between them. Pre-cutover detection is not possible (sync_enabled='0' suppresses all writes), so this MUST be confirmed via operator-side QBO UI check before flipping the master kill switch.

**Why NOT runtime-probed:** matches FF's pre-flight-as-doc pattern for all other QBO-side prerequisites (tax_override_code_id, sync_enabled, sync_mode). Adding a Preferences API probe would cost an HTTP call per apply push + a new endpoint-scope dependency, for a setting that operators only flip once at cutover.

---

### F27 — Credit-application un-apply / void-after-apply path ✅ CLOSED 2026-06-01

**Closed by:** S-QBO-CREDIT-APP-UNAPPLY (2026-06-01). Migration 85→86 added `status` ENUM('applied','reversed') + `reversed_at` + `reversed_by` to `credit_note_applications` (append-only — reversed rows kept) + `'voided'` to `acc_qbo_credit_application_map.push_status`. NEW testable service `lib/CreditApplicationReversal::reverse()` runs the exact inverse of apply.php in one FOR-UPDATE transaction (restores credit remaining+status, invoice credits_applied+balance_due+status, customer outstanding_balance; marks the application reversed; posts the reversing DR-AR/CR-2060 JE via NEW `AutoEntryBridge::onCreditNoteUnapplied`). Thin endpoint `api/v1/credit_notes/unapply.php` calls it + enqueues the QBO void. `CreditApplicationPusher::pushVoid` voids the QBO apply-Payment (`voidEntity('payment')`; idempotent on push_status='voided'; skipped_unmapped_void when never pushed). `CreditApplicationEnqueuer` gate-3 widened ['create']→['create','void'] + gate-0 status invariant (create→'applied', void→'reversed'). UI: an Un-apply button + Reversed badge on the credit_notes/show.php Application History table (excludes reversed rows from the Total Applied). NEW smoke `_smoke_qbo_credit_app_unapply` 16/16 incl. the apply→reverse counter round-trip + the voided-parent edge (credit left terminal, invoice/customer still restored). `_smoke_qbo_queue` C8 updated (credit_application now create+void). NO cascade change to credit_notes/void.php — it already leaves applied portions intact by design. D-QBO-UNAPPLY-1/-2/-3 locked.

**Original report (preserved):**

**Surfaced by:** S-QBO-CREDIT-MEMO-APPLY (2026-06-01) — D-QBO-CREDIT-MEMO-APPLY-4 (v1 scope = forward-apply only)
**Affects:** any future flow that un-applies a credit from an invoice OR voids an already-applied credit. Today no FF endpoint un-applies (`credit_notes/void.php` voids the parent credit, not individual applications); `credit_note_applications` is append-only with no `status`/`deleted_at` column.

**Operator action:** queue a follow-up session to:
1. Add `status` + `deleted_at` columns to `credit_note_applications` (un-apply is a state transition + soft-delete pair)
2. Build `api/v1/credit_notes/unapply.php` (reverses the 5 counters from `apply.php` + state-machine transition)
3. Add `CreditApplicationPusher::pushVoid` (DELETE on the QBO Payment via QuickBooksClient + idempotency on `push_status='voided'`)
4. Widen `CreditApplicationEnqueuer` gate-3 to accept `'void'` op
5. Decide policy for void-the-parent-credit-while-applications-exist (cascade un-apply all? refuse to void?) — needs business decision

**Why deferred:** no FF endpoint un-applies today, so there is no source-side trigger to propagate. v1 forward-apply (the only flow operators currently exercise) is correct + complete. Builds the path when un-apply becomes an actual user need rather than a speculative one.

---

### F29 — Historical-pull live execution + H5/H6 GL remediation 🔴 BLOCKING (cutover)

**Surfaced by:** S-QBO-27 (2026-06-01) — the machinery-only ship per the locked scope decision.
**Affects:** the entire historical backfill (spec §16). The S-QBO-27 ship is the orchestration + checkpoint + AR-drift DETECTION machinery, all dry-run-gated. The parts that genuinely need a live, accountant-pre-seeded QBO sandbox are deferred here:
1. **Live pull execution** (phases 27.A–E) — pull all historical customers/vendors/invoices/bills/payments/bill_payments/credit_memos/refund_receipts/journal_entries from the real QBO file.
2. **QBO→FF business-row transform** (`HistoricalPuller::writeFfRowFromQbo`) — materializing a brand-new FF row (e.g. a full `invoices` row with billing-period + lease-linkage columns QBO does not carry) for a QBO-only historical entity. The transform is implemented against the real entity shapes, not guessed.
3. **H5/H6 compensating-JE POSTING** (`ArDriftRemediator::postApprovedPlan`) — the $20,764.80 (H5) + −$3,700.18 (H6) AR-drift fixes. Detection + the tagged `[A1-FIX-invoice-N]` plan run today; posting is operator-approved + live-gated (D-QBO-27-5, hard-stop-and-report — never auto-posts).
4. **H6 root-cause bug investigation** — the deterministic 1.375× (11/8) InvoiceGenerator anomaly. The compensating JE resolves the symptom; the investigation finds + fixes the code path so it can't recur.

**Operator action (at the S-QBO-27 live session, after the sandbox is seeded):**
1. Accountant pre-seeds the QBO sandbox with a representative subset of Mainland's real data.
2. Connect FF to the sandbox (real realm + OAuth; not SMOKE-REALM).
3. Run dry-run AR-drift detection on `/quickbooks/manual_sync` → review the H5/H6 report + plan.
4. Implement + verify the per-entity QBO→FF transforms against the seeded shapes; run 27.A dry-run → 27.B full sandbox.
5. With the accountant present, approve + post the H5/H6 compensating JEs; confirm AR drift = $0.00 ±$1 (D-QBO-27-6).
6. Open the H6 InvoiceGenerator bug investigation.

**Why deferred (not blocking the build):** every deferred item needs real QBO entity shapes + the accountant + posts to the GL. Building the transforms blind or auto-posting remediation JEs against assumed data would be guesswork on financial records. The dry-run gate (`quickbooks.historical_pull.dry_run='1'`) + the live-allowed assertion keep the shipped machinery from mutating anything until the gate is explicitly opened with a live connection. Same build-now / verify-at-cutover pattern as F16/F19/F28, at larger scale. **Gates the S-QBO-27→28/29/30 cutover sequence** (§17.1: "Historical pull completed successfully on sandbox" + "AR drift = $0.00").

---

### F28 — Refund-receipt tax-treatment live-verification 🟡 PARTIAL

**Surfaced by:** S-QBO-17 (2026-06-01) — D-QBO-17-3
**Affects:** every QBO RefundReceipt pushed by `RefundReceiptPusher`. The push currently emits `TaxCodeRef=NON` + `TxnTaxDetail.TotalTax=0` (non-taxable refund), a documented assumption from spec §8.7's payload — NOT yet confirmed by the accountant.
**Operator action:**
1. Confirm with the accountant whether a mileage-prepayment cash refund is non-taxable (NON) or must reverse GST/HST originally collected.
2. If non-taxable → no code change; mark this follow-up CLOSED.
3. If it must carry/reverse tax → a small follow-up adjusts `RefundReceiptPusher::buildQboPayload` (line `TaxCodeRef` + `TxnTaxDetail`) + likely a tax-rate setting, mirroring the S-QBO-BILL-ITC tax-rate work.
4. After S-QBO-30 flips `sync_enabled='1'`, push one real refund and confirm the QBO RefundReceipt's tax line matches the accountant's expectation before relying on the path.

**Why deferred (not blocking the build):** the QBO master kill-switch (`sync_enabled='0'`) stays OFF until cutover, so nothing posts to QBO before this verify — the NON assumption cannot corrupt the live ledger pre-cutover. Same defer-to-cutover pattern as F16 (S-QBO-22) + F19 (S-QBO-23) live-verifications. The FF-side GL JE for the refund is a separate concern owned by `S-MILEAGE-3-ACCT-SPEC` (QBO derives its own posting from the RefundReceipt).

---

### F24 — Live-HTTP drift layer verification at cutover

**Surfaced by:** S-QBO-24 (2026-05-30)
**Operator action:** after S-QBO-30 flips `sync_enabled='1'` + connects QBO, verify the DriftChecker LIVE layer end-to-end: run "Run drift check now" → confirm it issues per-entity QBO queries (Invoice/Payment/Bill/…) + records `missing_in_ff` drift events for any QBO entity with no FF mapping (e.g. a manually-created QBO invoice). Pre-cutover this path is unreachable (sync_enabled='0' → `liveModeAvailable()` false → snapshot-only) + there is NO fixture/mock HTTP layer, so the live layer is unit-tested only at the gate-decision level (smoke C15); the actual QBO query + missing_in_ff recording must be verified against the live sandbox.

**Why deferred:** structurally cannot run pre-cutover; no offline fixture for QBO HTTP. The snapshot layer (push_failed + FF-side amount_drift) IS fully tested + runs now.

---

### F30 — Retime (and install) the monthly-billing crontab line 🟡 PARTIAL

**Surfaced by:** S-CRON-FIX-1-MONTHLY-BILLING-TZ (2026-06-02) — cron-audit HIGH-1 fix.
**Affects:** monthly lease billing timeliness at cutover (`cron/invoice_generate_monthly.php`).
**Operator action:** install the monthly-billing cron with the RETIMED line (06:00 America/Vancouver = 14:00 UTC on the 1st), NOT the historical `0 6 1 * *` UTC:
```
0 14 1 * * /usr/bin/php /var/www/fleetforge/cron/invoice_generate_monthly.php >> /var/www/fleetforge/logs/cron.log 2>&1
```
Optionally run it DAILY (`0 14 * * *`) — the cron is now idempotent + catch-up-safe, so a daily run no-ops once a lease is billed and recovers a missed/mistimed run within a day instead of a month.

**First run after deploy:** the fixed cron will catch up ANY periods the old (broken) cron silently skipped — review the first batch of generated invoices (a lease may get several months in one run). This is expected recovery, not a bug.

**Why 🟡 PARTIAL (not blocking):** the code fix self-heals — even with the OLD `0 6 1 * *` line, billing still happens via `<=` catch-up, just ~1 month late. The retime makes billing fire ON TIME (the 1st). No lost revenue either way, but late billing is a real AR/cashflow drag — do the retime at cutover.

---

## ✅ CLOSED — moved to archive after operator confirmation

*(empty — track here when operator confirms completion + provides verification timestamp)*

---

## Cross-cutting notes

- **Discipline enforcement:** `tests/_smoke_doc_freshness.php` CLASS 13 (locked 2026-05-29 via S-OPERATOR-FOLLOWUPS-TRACKING) verifies that recent SESSION LOG rows containing the phrase "Operator follow-ups" have matching entries in this doc. Advisory — surfaces orphans without strict-failing because some follow-ups may be re-architected into proper session labels between surfacing and tracking.
- **Memory file:** `memory/feedback_operator_followups_tracking.md` codifies the rule + canonical incident (this file's birth at S-OPERATOR-FOLLOWUPS-TRACKING). Survives context wipes.
- **When operator completes an item:** update the entry's status from 🔴/🟡/🟢 to ✅ + add completion timestamp + verification command output + move to archive at bottom of doc.
- **When a new follow-up surfaces mid-session:** add the entry under the appropriate status bucket BEFORE commit. Include: Surfaced-by (session label + commit ref), Affects (which sessions depend), Operator action (numbered steps), Why-blocking/deferred (rationale).
