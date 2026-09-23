# FleetForge — Operator Follow-ups (Carry-over Tracking)

**Purpose:** canonical, persistent list of operator-side work items surfaced by shipped sessions that the operator must complete BEFORE live cutover OR that gate downstream functionality. Survives session-context-wipes (it's a file, not a memory). Updated mechanically at every session end (enforced by `tests/_smoke_doc_freshness.php` CLASS 13).

**Discipline:** every session that notes operator follow-ups in its SESSION LOG row MUST add or update matching entries here BEFORE commit. See `memory/feedback_operator_followups_tracking.md` for the rule + canonical incidents.

**Difference from `FLEETFORGE_PREDEPLOY_CHECKLIST.md`:** the predeploy checklist is pre-deploy infrastructure items (DNS, SSL, S3, SES sandbox approval, etc.) per K-14. This doc tracks operator follow-ups surfaced by SHIPPED sessions — things the operator must do to enable a feature that's already in code but blocked on operator action (Intuit dashboard registration, secret configuration, account mapping, etc.). Some items overlap and may also appear in PREDEPLOY_CHECKLIST.md — that's intentional cross-listing.

**Status legend:**
- 🔴 **BLOCKING** — live test or cutover cannot proceed without this
- 🟡 **PARTIAL** — operator can use the feature in degraded mode until completed
- 🟢 **DEFERRED** — queued for a future session; documented for tracking
- ✅ **CLOSED** — operator completed; moved to archive at bottom

**Last updated:** 2026-09-24 via S-REMINDERS-SMOKE-HERMETIC — **F88** added (production has "Honor portal opt-outs" off: tick it back on before turning customer emails on, or a customer who unticked the portal box is emailed anyway). Previously 2026-09-24 via S-QBO-INVOICE-PAYNOW — **F87** added (customers' Payment Terms now set due dates and go to QuickBooks: review the 6 blank ones; add every term customers use in QuickBooks); **F81** step 7c-ter widened and step 7e replaced (no template edit — the Pay now button is automatic once QBO Payments is ticked). Previously 2026-09-24 via S-SOP-MODULE — **F86** added (deploy the in-app SOP; the accountant reviews the Accounting and Month-end chapters; the Known issues chapter I1–I27 is the fix backlog). Previously 2026-09-24 via S-QBO-ITEM-ACCOUNT-CHECK — **F85** added (decide the revenue account plan: FF's revenue mapping vs QuickBooks item accounts; FF books rental revenue to 4110 Other Revenue today). Previously 2026-09-24 via S-QBO-NAME-CLASH — **F81** step 7c-quinquies (what happens when a customer's and a vendor's names clash in QuickBooks). Previously 2026-09-23 via S-QBO-INVOICE-WRITEOFF — **F81** step 7c-quater (map Bad Debt Expense + the Bad Debt Write-off item). Previously 2026-09-23 via S-QBO-CUSTOMER-TERMS-ADDR — **F81** step 7c-ter (QuickBooks needs a term matching each FF payment-terms value). Previously 2026-09-23 via S-QBO-BILLPAY-MIRROR — **F81** gains the BillPayment webhook subscription, a migration and step 7c-bis (link the QuickBooks accounts bills are paid from); **F84** rule 3 updated (bill payments made in QuickBooks now mirror into FF). Previously 2026-09-23 via S-QBO-GOLIVE-AUDIT — **F80** (prove Canadian GST/PST on a Canadian QBO sandbox before the real company — now with the per-rate invoice tax mode and `scripts/qbo_sandbox_verify.php`), **F81** (QBO go-live checklist — now includes the migration, go-live linking and matching steps), **F82** (Multicurrency decision — irreversible), **F83** (pre-go-live receivables — answered: already in QBO → link them) and **F84** (accountant process rules for the shared file) added. Previously 2026-09-17 via S-UTC-STAMPS — **F78** added (deploy, then run the one-time local→UTC timestamp repair on prod). Previously 2026-09-17 via S-LOCAL-DAY-TS — **F77** added (deploy: admin "forgot password" links were expired on creation; one-time timestamp side effects). Previously 2026-09-17 via S-GPS-LOCAL-WINDOW — **F76** added (deploy the local-day Samsara window fix; decide whether to regenerate 23 prod draft invoices whose trailer mileage was fetched on the UTC window). Previously 2026-09-17 via S-CASHFLOW-TIE — **F74** (deploy the cash-flow / working-trial-balance fix; confirm which accounts count as cash) and **F75** (demo dataset registers fixed assets with no GL cost entry) added. Previously 2026-09-16 via S-TRAINING-VIDEO-BUGFIX — **F71** (deploy + migration + fix two prod drafts that double-bill mileage), **F72** (recompute vendor Total Spent on each deployment) and **F73** (three behaviour changes to confirm) added. Previously 2026-09-12 via S-PICKER-OPEN-LEASE — **F69** (deploy to unlock 6 live leases + 9 void-stuck leases) and **F70** (MTTS485 advance-billed draft) added. Previously 2026-08-18 via S-QBO-ENVELOPE-FIX — **F67** and **F68** both ✅ CLOSED (fixed by the two spawned task sessions; landed in commit `8ee2ee3`, see S-SWEPT-COMMIT-DISCLOSURE in PROGRESS.md for the attribution note). No new operator follow-ups: the QuickBooks envelope fix is client-side only and needs nothing from the operator beyond a deploy. Previous: 2026-08-18 via S-LIST-TOOLBAR / S-TOPBAR-CREATE-ALL.

---

## 🔴 BLOCKING — live test cannot proceed without operator action

### F80 — Prove Canadian GST/PST handling on a CANADIAN QuickBooks sandbox before connecting the real company 🔴 BLOCKING (QBO go-live — do NOT enable master sync on the real company until done)

**Surfaced by:** S-QBO-GOLIVE-AUDIT (2026-09-23).
**Why:** every live QBO verification so far (Invoice #147, Bill #148, …) ran against Intuit's **US** sandbox ("Craig's", home currency USD, single-currency). The real company is Canadian. Invoices, credit memos and bills all use the US tax-override pattern: every line carries the "override" tax code (`quickbooks.tax_override_code_id` — the US `NON` code, which does not exist in QBO Canada) and FF's GST+PST+HST total goes in the header `TxnTaxDetail.TotalTax`. QBO Canada requires a real Canadian tax code on every line (error 6000 "Make sure all your transactions have a GST/HST rate before you save") and computes tax from those line codes; there is strong evidence it does not honour a header-only TotalTax. The likely outcomes are (a) every push rejected, or worse (b) invoices land with **$0 tax** — QBO AR short by the tax, GST/PST liability never booked, and every FF payment then over-applies the QBO invoice. On prod 99/99 overdue and 1,805/1,809 draft invoices carry tax, so this affects essentially every document. Bills have the same issue for input tax credits (`quickbooks.bill.tax_mode='per_rate'` exists for bills only and is also untested on Canada).
**Operator action:**
1. Intuit Developer → your app → **Sandbox** → Add a sandbox company, **Country = Canada**. (Enable Multicurrency in it only if you will enable it in the real company — see F82.)
2. Point DEV at it: `/quickbooks/settings` → Environment = sandbox, sandbox keys, Connect. (If the dev DB still has the US-sandbox mappings, the new realm guard blocks sync and shows **Reset mappings for this company** — use it.)
3. Map Accounts → Tax Codes → Items → Customers on `/quickbooks/*`. On Tax Codes, note what the override target resolves to.
4. Push ONE real-shaped invoice with GST + PST (retry button on `/quickbooks/invoices`). In the QBO sandbox compare: invoice **total**, **tax amount**, per-line tax code, and Reports → GST/HST (Sales tax liability). Repeat for a credit memo, an approved bill (both `quickbooks.bill.tax_mode` values), and a payment against the invoice.
5. Report the result back. If QBO drops/recomputes the tax (expected), a per-rate invoice/credit-memo tax mode (line TaxCodeRef = the mapped Canadian GST/PST code + TaxLine detail, like the bill `per_rate` mode) must be built and re-verified here before go-live.
   **Update (same session, third pass):** the per-rate invoice mode is built. On the Canadian sandbox: `/quickbooks/tax_codes` → Pull → map every FF tax rate (BC GST+PST, AB GST, …) to its QuickBooks code → **Invoice tax** card: mode **per_rate**, tax-free code = "Exempt" (or "Zero-rated") → Save. Then run, on the dev machine:
   `php scripts/qbo_sandbox_verify.php --invoice=<an FF invoice with GST+PST> --pay --linker`
   (The dev data is historical and the first sync switch-on stamps the go-live date, so on this DEV rehearsal set Settings → Business tagging → **Push transactions dated from** = 2020-01-01 first — otherwise every demo invoice is held back as "already in QuickBooks". On the real company leave it empty or set it to the real go-live day.)
   Every line must be ✓ — especially "QuickBooks total = FleetForge total", "QuickBooks tax = FleetForge tax", the pay link, and the payment → FF → void round trip. Paste the output back. (Credit notes carry no tax in FF, so they push tax-free by design; bills keep `quickbooks.bill.tax_mode`.)
   **Rehearsed 2026-09-23 (fourth pass) on the Canadian sandbox "Sandbox Company CA 57b7" with a production copy:** per-rate invoice tax ✓ (2 new invoices: QBO total + GST/PST identical to FF; GST-only PST-exempt invoices resolve to the GST code), credit memos ✓, QBO payment → FF paid → void → FF reopened ✓, missed webhook recovered by the catch-up ✓, go-live linking 129/129 ✓. **Still unproven:** the pay link (QuickBooks Payments — Intuit's sandbox supports it for US companies only; check it on the first real invoice at go-live) and the tax-remittance JE below.
**Also verify on the same sandbox:** a JE to the GST/HST payable account (the tax-remittance JE) — QBO Canada restricts journal entries to its sales-tax accounts; and the portal Pay Online link (needs QuickBooks Payments on the sandbox).

---

### F81 — Go-live checklist for the QBO connection (deploy S-QBO-GOLIVE-AUDIT first) 🔴 BLOCKING (QBO go-live sequence)

**Surfaced by:** S-QBO-GOLIVE-AUDIT (2026-09-23).
**Operator action (in order):**
1. Deploy `main` (`sudo /var/www/fleetforge/bin/deploy.sh`) — it applies migrations `202609232000_S-QBO-GOLIVE-AUDIT_cutover_link_origin.sql` (adds `origin` / `link_method` / `linked_at` to the invoice, credit-memo and bill maps) and `202609232100_S-QBO-BILLPAY-MIRROR_bill_payment_origin.sql` (bill payments made in QuickBooks: `acc_ap_payments.origin` + bill-payment map pull columns); all new settings keys are created on first write.
2. Finish F80 on a Canadian sandbox.
3. Intuit Developer → **Production** keys; add `https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php` as a production redirect URI.
4. `/quickbooks/settings`: Environment = **production** (switching environments now clears the old connection and forces sync off — expected), paste the production Client ID / Secret / Webhook verifier token (secrets are now stored encrypted), Connect, approve the REAL company. Prod has no existing mappings, so the realm guard stays quiet; if it ever shows "Sync blocked — different QuickBooks company", use **Reset mappings for this company**.
5. Map in order: Accounts (incl. the `undeposited_funds`, `ar_clearing`, `sales_revenue`, `ap_clearing`, `tax_receivable`, `tax_payable` categories — F4/F12) → Tax Codes → Items → Customers → Vendors → Bank Accounts.
6. Webhooks (F1/F2): Intuit now delivers **CloudEvents** (the legacy format was retired July 2026) — FF now accepts both. Subscribe the **Payment**, **BillPayment**, **Invoice** and **CreditMemo** entities, endpoint `https://mainlandrentals.com/fleetforge/api/v1/webhooks/qbo_payment_notifications.php` (S-QBO-BILLPAY-MIRROR: BillPayment = bills the accountant pays in QuickBooks show paid in FF).
7. Decide F82 (multicurrency) with the accountant; agree the F84 process rules.
7a. **Settings → Business tagging:** Load from QuickBooks, pick the rental business's **Class** and/or **Location**, keep "shared with other businesses" ticked, and set **Push transactions dated from** to the first day FleetForge owns (leave empty = the go-live day). Turn on QuickBooks' *Custom transaction numbers* (the card shows whether it is on) so FF invoice numbers are kept.
7b. **Customers / Vendors:** Pull, Auto-Match. Only exact names link on their own — work through the "Suggested" rows (**Link suggested**) and the rest (**Link to QBO…**, or **Create in QuickBooks** only when QuickBooks truly has no such customer/vendor). FF never creates a QBO customer/vendor that existed before go-live on its own.
7c. **Tax Codes:** map every FF tax rate; Invoice tax = **per_rate** (as proven in F80).
7c-ter. **Payment terms** (S-QBO-CUSTOMER-TERMS-ADDR): customers FleetForge creates in QuickBooks get their FF payment terms as a QuickBooks term — matched by name ("Net 30"), else by number of days. Make sure QuickBooks has a term for each FF value in use (today: "Net 30"); an unmatched value is created with QuickBooks' default terms. Customers linked to existing QuickBooks records keep the accountant's terms and address — FF never overwrites them. **S-QBO-INVOICE-PAYNOW:** every pushed INVOICE now carries the term matching its own due date too, and FF due dates follow the customer's terms — so QuickBooks needs a term for every value in use (Net 15, Net 30…), see F87.
7c-quinquies. **Customers that are also vendors** (S-QBO-NAME-CLASH): QuickBooks names must be unique across customers, vendors and employees. When FF creates a vendor (or customer) whose name the other side already uses, it is created as "ACME (Vendor)" / "ACME (Customer)" — company and cheque name stay "ACME"; the mapping page says so. If QuickBooks already has the SAME kind of record with that name (even inactive), the push stops and asks you to link them on QuickBooks → Vendors / Customers instead.
7c-quater. **Bad-debt write-offs** (S-QBO-INVOICE-WRITEOFF): map FF's **Bad Debt Expense** account on QuickBooks → Accounts, then on QuickBooks → Items press **Pull** (so the **Bad Debt Write-off** row appears) and map it (or **Create in QuickBooks**) — FF creates it on Bad Debt Expense. A written-off invoice (bad debt, or a damage claim written off) is then closed in QuickBooks with a credit memo on that item applied to the invoice; watch QuickBooks → Credit Memos → Invoice write-offs for blocked ones (Send / Retry there). Recovering a write-off in FF is not pushed — reverse the credit memo in QuickBooks by hand.
7c-bis. **Bank accounts bills are paid from** (S-QBO-BILLPAY-MIRROR): every QuickBooks bank / credit-card account the accountant pays bills from must be linked to an FF bank account (QuickBooks → Bank Accounts, or the account's GL account on QuickBooks → Accounts). A QuickBooks bill payment from an unlinked account is not recorded in FF — it waits as a drift event ("not linked to a FleetForge bank account") until the account is linked and **Check QuickBooks for payments** is pressed.
7d. **Go-live linking** (QuickBooks → Invoices, top panel) — see F83. Do this BEFORE step 8's first day of sends.
7e. ~~Email templates: add `{pay_online_link}`~~ — not needed since S-QBO-INVOICE-PAYNOW: once **QBO Payments** is ticked (Master Controls) every invoice email, reminder, dunning letter and PDF carries a **Pay now** button by itself.
8. Turn master sync on (Settings → QuickBooks → Master Controls). The first switch-on stamps `quickbooks.cutover_at`; the drift checker treats QBO records created before it as the accountant's history, not drift. Install the four QBO crons (worker every minute, token refresh daily, drift daily, bank CDC daily — F14).
9. For the first day: watch `/quickbooks/sync_queue` + `/quickbooks/sync_log`; open the first pushed invoice, payment and bill in QBO and compare totals to FF.
**Operating rule to agree with the accountant:** record each customer payment in ONE place — per the operator, QuickBooks (pay links / portal). FF mirrors every QBO payment on an FF invoice (webhook + "Check QuickBooks for payments") and posts its own books. Entering the same cheque in both creates it twice. See F84.

---

### F82 — Decide QuickBooks Multicurrency BEFORE the first push (irreversible in QBO) 🟡 PARTIAL (only matters once a USD customer exists)

**Surfaced by:** S-QBO-GOLIVE-AUDIT (2026-09-23).
**Why:** a new QBO company ships single-currency, and turning Multicurrency on can never be undone. FF now **refuses** to push any USD invoice/payment/credit/bill/JE into a single-currency company (it used to post the USD figures as CAD). All 43 prod customers are CAD today, so nothing is blocked yet.
**Operator action:** with the accountant, decide whether USD customers/vendors will exist. If yes, enable Multicurrency in QBO (Settings → Account and settings → Advanced → Currency) BEFORE connecting, then reconnect so FF re-detects it (CompanyInfoSync). USD records pushed while blocked show as `failed_preflight_currency_mismatch` and can be retried after enabling.

---

### F83 — Decide what happens to pre-go-live receivables (99 overdue invoices on prod) 🟡 PARTIAL (cutover accounting decision)

**Surfaced by:** S-QBO-GOLIVE-AUDIT (2026-09-23).
**Why:** FF only queues an invoice for QBO at the moment it is sent. The 99 invoices already sent (now `overdue`) were sent while sync was off, so they will not reach QBO on their own, and any FF payment against them fails preflight ("invoice has no QBO mapping"). S-QBO-GOLIVE-AUDIT fixed the gate that made them impossible to push at all (it only accepted `status='sent'`), but there is still no bulk backfill — only per-invoice Retry on `/quickbooks/invoices` once a map row exists.
**Operator action:** with the accountant decide: (a) these receivables are already in QBO (entered by the accountant) → do NOT push them; payments against them get recorded in QBO directly; or (b) FF should push them → ask for a backfill session (enqueue every post-send invoice with no QBO mapping, dated from a chosen cut-off). Pushing them when the accountant already has them in QBO would duplicate AR.
**Answered 2026-09-23: (a) — they are in QuickBooks.** Built in the same session: **QuickBooks → Invoices → "Go-live: link documents QuickBooks already has"**.
1. After customers/vendors are mapped (F81 7b): kind = Invoices, From = the first FF invoice date, Find matches.
2. **Select exact + amount/date** → **Link selected**. Linking writes nothing to QuickBooks; it brings in the QBO payments on each invoice (FF shows them paid, FF books updated). Review the "check" / "same number, other amount" rows one by one (pick the right QBO invoice in the dropdown; **Link anyway** when the amounts differ on purpose).
3. Rows with no match: widen the date window; if QuickBooks truly lacks the invoice, **Push as new**.
4. Repeat for Credit notes and Bills (bills entered in FF for per-unit costing).
5. Tick **Include drafts** to see the 1,810 FF drafts: link the ones QuickBooks already billed so they can never be pushed as duplicates; void in FF the ones nobody will ever send.
6. Press **Check QuickBooks for payments** after linking (and any time a payment seems missing).
Safety net: anything dated before go-live is refused as a NEW push until linked or released, so an un-linked old invoice can't slip into QuickBooks by accident.
**Rehearsal (2026-09-23) left two decisions:** (1) the **165 September invoices** (dated Sep 1) — were they entered in QuickBooks? If yes, link them like August; if not, set Settings → Business tagging → **Push transactions dated from = 2026-09-01** so FF sends them as new. (2) **30 July invoices** QuickBooks has as paid are still FF **drafts** — mark them sent in FF (their QBO payments then come in) or void them in FF. Also: have the accountant review the 11 accounts and 28 items left unmapped.

---

### F85 — Decide the revenue account plan (FleetForge ↔ QuickBooks items) 🟡 OPEN (before go-live)

**Surfaced by:** S-QBO-ITEM-ACCOUNT-CHECK (2026-09-24). QuickBooks → Items now shows, for each item, whether QuickBooks posts it to the same account FleetForge books that revenue to.
**What it shows on the production copy:**
1. **None line up yet.** Every QuickBooks item posts to one account ("Sales" in the rehearsal), and none of FleetForge's revenue accounts (4010–4122) is linked to a QuickBooks account.
2. **FleetForge puts most revenue in 4110 "Other Revenue".** Its revenue map (Accounting → Settings → Revenue Mapping) has rental keys per equipment category (`base_rental_chassis`, `base_rental_dry_van`, …) but invoice lines are typed plain `base_rental`, so all rental revenue ($54,079 on the production copy), plus estimated / actual mileage usage, engine hours, cartage, sweep, wash and fuel, falls back to 4110.
**Operator action (with the accountant):**
- Decide where each kind of revenue should live — one "Sales" account (simple; then point FF's revenue map at the account you link to Sales) or separate accounts (then give the QuickBooks items matching accounts and link FF's 4010–4122 to them on QuickBooks → Accounts).
- If rental revenue should split by equipment category, that needs a small code change (the revenue lookup must use the unit's category) — ask for it; until then add a plain `base_rental` entry to the revenue map to at least move it out of "Other Revenue".
- Past entries stay where they are (a reclass journal is the accountant's call).
**Done when:** the Items page warning is gone (or only shows the GPS-net note the accountant accepted).

---

### F88 — Tick "Honor portal opt-outs" back on before turning customer emails on 🟡 OPEN (before any customer email is re-enabled)

**Surfaced by:** S-REMINDERS-SMOKE-HERMETIC (2026-09-24).
**Why:** on 2026-08-11 Settings → Customer Emails was saved on production with every switch off: **Send customer emails**, **Run the reminder dispatcher** and **Honor portal opt-outs** (checked read-only on prod 2026-09-24). No reminder type is on, so nobody is emailed today and nothing is wrong yet. But while **Honor portal opt-outs** is off, a customer who unticks "compliance documents expiring" in their portal still gets those emails once customer emails are switched back on. Today 0 of the 2 portal users have unticked it. FleetForge's own default is ON; the code is correct.
**Operator action:**
1. Settings → Customer Emails → tick **Honor portal opt-outs** → Save. Do this before, or together with, ticking **Send customer emails** or any reminder type.
2. Leave it off only if you mean to email customers even after they have opted out in the portal.
**Done when:** the box is ticked on production, or you have decided it stays off on purpose.

---

### F87 — Customers' Payment Terms now set due dates and go to QuickBooks 🟡 OPEN (review before the next billing run)

**Surfaced by:** S-QBO-INVOICE-PAYNOW (2026-09-24). Operator decision: an invoice is due per the customer's Payment Terms ("Net 15" → 15 days after the invoice date; "Due on receipt" → same day). Blank or unusual terms still get 30 days. The matching QuickBooks term rides on every pushed invoice.
**Operator action:**
1. Customers → check **Payment Terms** on each customer: 37 read "Net 30" and 6 are blank (→ 30 days) on the production copy. Type terms as "Net 15", "Net 30", "Due on receipt" — other wording falls back to 30 days.
2. In QuickBooks, make sure a term exists for every value used (a customer on Net 15 needs a "Net 15" term) — otherwise that invoice is pushed without a term (its due date is still right).
3. Optional: in QuickBooks sales-form settings add a custom field named **P.O. Number**; FleetForge then fills it with the invoice's PO (reconnect or open QuickBooks → Settings once so FF re-reads the preferences).
4. When QuickBooks Payments is set up on the company, tick **QBO Payments** (QuickBooks → Settings → Master Controls): Pay now then appears in invoice emails, reminders, dunning letters and PDFs by itself. Prove one payment end to end (F80).
**Done when:** every customer's terms are ones FleetForge reads, and each has a matching QuickBooks term.

---

### F86 — Deploy the in-app SOP and have the accountant review it 🟡 OPEN (before go-live)

**Surfaced by:** S-SOP-MODULE (2026-09-24). The SOP is now inside FleetForge (sidebar **SOP**), replacing the shared Claude Doc.
**Operator action:**
1. Deploy `main` — one migration (`202609241200_S-SOP-MODULE_sop_tables.sql`, two new tables); `php bin/migrate.php --apply`.
2. Ask the accountant to read chapter 5 (Accounting) and chapter 10 (Month-end close), and to confirm or correct: the GST input-tax-credit clearing entry after each filing (DR 2030 / CR 1050 — known issue I4), the bank opening-balance entry (I8), and the asset-purchase entry (I2). Tell the developer what changes; the chapter is updated in code.
3. Ask each staff member to read the chapters marked **For your role** and press **Mark as read**; super admins see who has on the SOP page.
4. Use the month-end checklist for the next close (September 2026) instead of a paper list.
5. Chapter 12 (Known issues, I1–I27) is the fix backlog found while writing the SOP — pick which to fix first; I1–I11 can make the books wrong.
**Done when:** deployed, the accountant has signed off chapters 5 and 10, and the first month is closed with the checklist.

---

### F84 — Agree the QuickBooks process rules with the accountant (shared company file) 🟡 PARTIAL (before go-live)

**Surfaced by:** S-QBO-GOLIVE-AUDIT third pass (2026-09-23).
**Why:** after go-live FleetForge and the accountant both write to the same company file, which also holds the other businesses. FF now protects the file (links instead of duplicating, never rewrites the accountant's documents, never creates pre-go-live customers/vendors, tags its documents with the rental Class/Location), but some rules can only be kept by people.
**Agree, in writing:**
1. **Rental invoices and credit notes are created in FleetForge only** from go-live. An invoice edited in FF after it was pushed updates QuickBooks; one LINKED at go-live does not (a drift alert asks for the same change in QuickBooks by hand).
2. **Customer payments are recorded in QuickBooks only** (pay links / QuickBooks Payments / deposits entered by the accountant). FF mirrors them. Don't also record them in FF. Apply unapplied money / credits in QuickBooks — FF follows.
3. **Bills:** decide ONE place. If bills are entered in FF (per-unit costing), FF pushes them; the accountant must stop entering those bills in QuickBooks. Bill **payments** can be made in either system but each payment in ONE place only: paid in QuickBooks → FF mirrors it (S-QBO-BILLPAY-MIRROR — webhook + "Check QuickBooks for payments", FF bill shows paid, FF books updated); paid in FF → FF pushes it. Vendor credits applied inside QuickBooks are not copied — apply the same vendor credit in FF (a drift event reminds).
4. **Depreciation of the rental fleet:** FF posts it per unit and pushes one journal entry per period from go-live. The accountant must stop booking depreciation for these assets in QuickBooks from the same date, or it is booked twice. (FF never pushes depreciation dated before the push-from date.)
5. Rental customers stay unique to the rental business where possible; a customer shared with another business is fine (FF only updates name/email/phone/address, never the DisplayName).
6. Watch **QuickBooks → Drift** weekly for "Changed in QuickBooks — needs attention" / "Update QuickBooks by hand" items.

---

### F78 — Deploy S-UTC-STAMPS, then run the one-time timestamp repair on prod 🔴 BLOCKING (data correctness — run right after the deploy)

**Surfaced by:** S-UTC-STAMPS (2026-09-17).
**Why:** ~90 DATETIME columns were stored as Pacific wall time while the app reads DATETIMEs as UTC. The deploy makes them write and read UTC; rows written BEFORE the deploy still hold Pacific time until this repair shifts them (+7h PDT / +8h PST per value). Until it runs, pre-deploy stamps display 7–8h early, AR aging "as of" treats pre-deploy credit applications/payment voids on the wrong side of a day boundary, QBO TxnDates for re-pushed credit applications/refund receipts can move a day, and pre-deploy portal reset links / credit-application links expire 7–8h early.
**Operator action:**
1. Deploy `main`. Note the LOCAL wall time just before the deploy started, e.g. `2026-09-18 21:05:00`.
2. Dry run (writes nothing; prints per-column row counts + before→after samples):
   `sudo -u www-data php /var/www/fleetforge/scripts/migrate_local_stamps_to_utc.php --cutover="2026-09-18 21:05:00"`
3. Apply — **soon after the deploy** (externally-dated Samsara/odometer columns are selected by row write time, so running it promptly keeps that selection tight):
   `sudo -u www-data php /var/www/fleetforge/scripts/migrate_local_stamps_to_utc.php --cutover="2026-09-18 21:05:00" --apply`
4. Re-running is safe: each converted column records a `settings` row `data_migration.utc_stamps.<table>.<column>` and is skipped next time. Do NOT delete those rows.
5. Same on Northland.
6. **Dev too:** the dev apply during S-UTC-STAMPS was undone by a concurrent training-video DB snapshot restore. Re-run the dry run + `--apply` on dev when no `scripts/walkthrough/record.mjs` recording is in progress (cutover = the local time just before the dev checkout at /Users/avi/Documents/fleetforge started running this commit — rows written by older code up to then are still local).
**Also expect:** any re-seed of demo data (seed_portal_accounts / seed_marketing_demo) should happen AFTER the repair.

---

### F77 — Deploy S-LOCAL-DAY-TS: admin "Forgot password" links have never worked on prod 🔴 BLOCKING (auth — LIVE NOW)

**Surfaced by:** S-LOCAL-DAY-TS (2026-09-17).
**Affects:** every staff user who uses **Forgot password** on the login page; plus every evening-time "today / this month" tile and several cache/expiry timestamps.
**Detail:** `app/auth/forgot_password.php` stored the reset token's `expires_at` as Pacific wall time + 1h, but `reset_password.php` checks `expires_at > NOW()` in UTC — so the token was already ~6h (PDT) / ~7h (PST) expired when the email went out, and every link opened as "invalid or expired". The fix writes `DATE_ADD(NOW(), INTERVAL 1 HOUR)`. Same class, also fixed: staff invite links expired ~7h early; AI summary / morning-brief caches expired 7h early (extra paid Claude calls); dashboard KPI/chart cache rows were deleted by `cache_cleanup` as soon as they were written; QBO pay-online links were treated as expired on creation; "Recorded today", notifications "Today", AI token usage/budget, credit-note and inspection "this month" tiles now start at local midnight instead of 5pm/4pm the previous evening.
**Operator action:**
1. Deploy `main` (no migration).
2. Test once: log out → **Forgot password** with your own admin email → the link should open the "choose a new password" form.
3. Expect, once, right after deploy: rows written BEFORE the deploy keep their old Pacific-wall-time stamps (no backfill) — e.g. older morning-digest runs in Settings → Intelligence → Recent runs show up to 7–8h early, and existing summary/brief caches expire once early; one AI budget threshold alert may be re-sent that first evening (its dedup key moved from the UTC day to the local day). Nothing to do.

---

### F79 — Publish the training videos to prod (S3) so the new Training page has chapters 🟡 PARTIAL (feature — page shows "No training videos have been published yet" until done)

**Surfaced by:** S-TRAINING-MODULE (2026-09-17).
**Why:** the Training page and its progress tracking ship with the deploy (migration `202609171900_S-TRAINING-MODULE_training_tables.sql` creates the tables), but the rendered videos are gitignored and only exist on the dev machine at `/Users/avi/Documents/fleetforge/training-videos/`.
**Operator action:**
1. Deploy `main` (the migration runs as usual).
2. Copy the chapter files (not the combined full-course file) to the server:
   `rsync -av --include='[0-9][0-9]-*.mp4' --include='[0-9][0-9]-*.srt' --include='[0-9][0-9]-*.timeline.json' --exclude='*' /Users/avi/Documents/fleetforge/training-videos/ fleetforge:/tmp/training-videos/`
3. Dry run: `sudo -u www-data php /var/www/fleetforge/scripts/training/publish_videos.php --dir=/tmp/training-videos` (should list 34 chapters).
4. Apply: `sudo -u www-data php /var/www/fleetforge/scripts/training/publish_videos.php --dir=/tmp/training-videos --apply` — uploads to S3 under `training/` and fills `training_videos`. Re-running after a re-record keeps everyone's progress.
5. `rm -rf /tmp/training-videos`. Same on Northland if it should have the course.

### F74 — Deploy S-CASHFLOW-TIE, then confirm which accounts count as cash 🟡 PARTIAL (report correctness — no data change, no migration)

**Surfaced by:** S-CASHFLOW-TIE (2026-09-17).
**Affects:** Accounting → Reports → Cash Flow (page, PDF export, year-end package PDF, AI narrative) and Working Trial Balance.
**Detail:**
- The Cash Flow Statement did not tie to the GL. On the dev dataset 2026 YTD was off $73,698.48 and full years were off $660k–$869k. **Prod (checked read-only 2026-09-17):** prod has no cash movement at all yet, but its current statement reports a **−$78.19** phantom net change for 2026 (credit notes posted to 2060 Customer Credits, which the old code never looked at). After deploy it reads $0.00 and ties.
- The Working Trial Balance's Unadj CY / AJEs / Adj CY columns were one month's activity while PY Balance was a cumulative balance, so every variance was meaningless. After deploy every column is a balance (balance-sheet accounts cumulative, P&L accounts fiscal year-to-date) with a computed "Retained Earnings — prior years not yet closed" row.
- Cash is now: accounts flagged "bank account" in the chart of accounts, any GL account linked to a checking/savings account under Accounting → Banking, the default cash account setting, a QuickBooks "undeposited funds" mapping, or an account whose name contains "undeposited". On prod today that is **1010 Cash — Operating Account (CAD)** and **1020 Cash — USD Account**.
**Operator action (prod is read-only for the agent — run it yourself):**
1. Deploy the latest `main` (`sudo /var/www/fleetforge/bin/deploy.sh`). No migration.
2. If the business holds cash anywhere else (petty cash, a second chequing account, undeposited receipts), flag that GL account as a bank account or link it under Banking — otherwise its movements show as working-capital changes instead of cash.
3. Open Cash Flow for 2026 YTD: Closing cash should equal "Closing cash per GL (1010, 1020)" and no amber tie-out banner should show. If the banner ever appears, report it — it means an entry was not classified.

### F71 — Deploy S-TRAINING-VIDEO-BUGFIX, run its migration, then fix two prod drafts that bill mileage twice 🔴 BLOCKING (money — drafts only, nothing sent)

**Surfaced by:** S-TRAINING-VIDEO-BUGFIX (2026-09-16), diagnosed read-only on prod.
**Affects:** every lease close that carries an "Actual Mileage" value.
**Detail:**
- **Closes rejected:** any lease whose starting odometer is not 0 could not be closed with the dialog's pre-filled Actual Mileage ("End mileage cannot be less than start mileage"). Prod manual leases all started at 0 km so far, which is why nobody hit it — the first non-zero start would.
- **Double-billed mileage (live on prod):** when a close generates a partial-month final invoice, the mileage was billed twice on that invoice — a `Mileage usage` line AND a `Mileage overage` line for the same distance. Two prod drafts carry it:
  - **INV-2026-00795** (MTTS399): `Mileage usage 29,399 km × $0.04 = $1,175.96` **and** `Mileage overage … = $1,175.96`.
  - **INV-2026-02131** (MTTS482): `Mileage usage 23.61 miles = $5.90` **and** `Mileage overage 24 miles = $6.00`.
- **Lifetime re-bill risk:** 31 prod manual leases bill mileage month by month from odometer readings; closing any of them pre-fix would have re-billed the whole lease's distance again on the final invoice.
**Operator action:**
1. Deploy `main` (`sudo /var/www/fleetforge/bin/deploy.sh` — runs migrations). The migration `202609161756_S-TRAINING-VIDEO-BUGFIX_repair_double_encoded_seed_text.sql` is data-only: it repairs double-encoded seed text ("â€”" → "—") in email templates / CCA classes / tax notes and rewrites three settings descriptions. Verify: `php bin/migrate.php --status` → `pending: 0`.
2. On each of the two drafts above, open the invoice → edit lines → **remove the `Mileage overage` line** (keep `Mileage usage`, which is the odometer-exact amount). Both are drafts, so this is counter-safe (update_lines.php).
3. Optional read-only re-check afterwards: no live invoice should carry both line types —
   `SELECT COUNT(DISTINCT i.id) FROM invoices i WHERE i.deleted_at IS NULL AND i.status<>'void' AND EXISTS(SELECT 1 FROM invoice_line_items a WHERE a.invoice_id=i.id AND a.item_type='mileage') AND EXISTS(SELECT 1 FROM invoice_line_items b WHERE b.invoice_id=i.id AND b.item_type='mileage_usage');` → **0**.

---

### F72 — Recompute vendor "Total Spent" on each deployment 🟡 PARTIAL (display counter)

**Surfaced by:** S-TRAINING-VIDEO-BUGFIX (bug #7).
**Detail:** `vendors.total_spent` was incremented at work-order completion AND again at bill approval for the same repair. It is now recomputed from one rule (`lib/Accounting/VendorSpend.php`: approved/scheduled/partially-paid/paid bills in CAD + completed work orders that no counted bill links to). Existing stored values keep their old drift until recomputed. Prod currently shows $0.00 for all 4 vendors. The GL was never affected (work-order completion posts no journal entry).
**Operator action (after F71's deploy):** `sudo -u www-data php /var/www/fleetforge/scripts/recompute_vendor_total_spent.php` (dry run, prints stored vs correct), then re-run with `--apply`. Idempotent; writes one audit row per changed vendor. Same on Northland.

---

### F73 — Confirm three behaviour changes from S-TRAINING-VIDEO-BUGFIX 🟡 PARTIAL (product decisions, defaults already chosen)

1. **Damage claims → "Invoiced" now needs the recovery invoice linked** (picker on the claim). A draft invoice is accepted; the recovery is linked in the GL when that invoice is sent. Previously a claim could be marked invoiced with no invoice and the GL step silently skipped.
2. **Payment instructions now print on invoice PDFs** when `invoice.payment_instructions` (or the fallback `company.payment_instructions`) is set — the portal reads the same pair. If prod has `company.payment_instructions` filled, newly generated PDFs will show a "Payment Instructions" box.
3. **Utilization numbers changed** everywhere (they were wrong — Analytics showed 357.9%): Reports, Analytics and the Dashboard trend now share one definition (days on rent ÷ days available, overlapping leases merged). The Dashboard tile is a right-now snapshot and is renamed **"On Lease Now"**.
**Operator action:** none required unless you disagree with a default — say which and it is a small change.

---

### F69 — Deploy S-PICKER-OPEN-LEASE to unlock 6 live leases, then re-bill 9 void-stuck leases 🔴 BLOCKING (LIVE NOW)

**Surfaced by:** S-PICKER-OPEN-LEASE (2026-09-12) — Mike La Pore reported the invoice create page offering
"Aug 26 → Sep 11" when he wanted to bill only to the end of August, and Generate greyed out.
**Affects:** billing on every open-ended lease younger than one calendar month, plus every lease whose only
invoice was voided. Diagnosed read-only on prod; **the fix is committed but NOT deployed, so prod is still
locked.**

**Operator action:**
1. Deploy the latest `main` to prod. No migration, no schema change, no `FF_ASSET_VERSION` bump needed
   (PHP only; `create.php` is server-rendered and carries no new CSS).
2. Confirm the six locked leases now offer September. They were, at diagnosis time:

   | Lease | Contract | Start | Blocked base rental |
   |-------|----------|-------|---------------------|
   | 533 | MTTS480 | 2026-08-20 | $138.57 |
   | 534 | MTTS479 | 2026-08-20 | $138.57 |
   | 535 | MTTS481 | 2026-08-27 | $500.00 |
   | 537 | MTTS483 | 2026-08-27 | $500.00 |
   | 538 | MTTS484 | 2026-08-26 | $450.00 |
   | 532 | MTTS478 | 2026-08-14 | $0.00 (already at the flat cap; blocked but owes no more base rent) |
   | | | **total** | **$1,727.14** |

   Each will now show two rows, with the current month selectable. Note these figures move as the month
   runs on; re-read the page rather than billing from this table.
3. **Then re-bill the 9 void-stuck completed leases.** Their only invoice was voided, and before this fix
   the picker refused to re-offer the period, so they have never been billed at all:
   305/MTTS398 $550.00, 321/MTTS206 $200.00, 173/MTTS184 $60.00, 178/MTTS191 $50.00, 240/MTTS290 $50.00,
   531/MTTS477 $50.00, 175/MTTS186 $30.00, 278/MTTS326 $25.00, 435/MTTS323 $25.00 — **$1,040.00 total.**
   These are `completed` leases, so Batch Invoicing will not take them (`batch_generate.php:165` rejects
   non-active). Use Invoices → Create on each lease; the void row now reads "Void · INV-… — next to bill".
4. **Before the deploy lands**, the only working route for the six active leases is Invoices → Batch
   Invoicing with period **September 1 → today**. Do NOT use September 1 → 30: `createFromLease` treats
   the submitted period end as the lease's extent, so a month-end period bills forward past what has been
   earned.
5. **Do not void an invoice to try to unblock a lease.** That was the dead end this session fixed; on an
   un-deployed prod it still makes the page permanently unusable for that lease.

---

### F70 — Decide whether MTTS485's draft should bill September in advance 🟡 PARTIAL (money decision, draft only)

**Surfaced by:** S-PICKER-OPEN-LEASE (2026-09-12).
**Affects:** one draft invoice. Nothing has been sent.
**Detail:** lease 539/MTTS485 started 2026-09-08 and is open-ended. Its activation invoice
**INV-2026-02128** bills 2026-09-08 → 2026-09-30: base rental $750.00 plus 23 days of GPS, subtotal
$767.25. The amount is **arithmetically correct for the period it covers** (23 days in one calendar month
is the flat monthly rate), so this is advance billing rather than an error. But it charges three weeks
that have not happened yet, on a rental four days old.
**Why it looks inconsistent:** `activate.php:401` always bills `[start .. last day of the start month]`.
For a late-month start that is a small daily-rate invoice (MTTS484 got 6 days / $300); for an early-month
start it is a full flat month. Same rule, very different customer experience.
**Operator action:** decide whether activation should bill the start month in advance. If yes, no change
needed. If no, this needs a session to change `activate.php`'s period rule. Either way, review
INV-2026-02128 before sending it.

---

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

### F63 — Deploy S-UNIT-BRAND + run the prod brand re-assignment script 🟡 PARTIAL (schema change; needs a migration + a data decision)

**Surfaced by:** S-UNIT-BRAND (2026-07-22) — operator: "we can have a 53 feet dry van from 5-6 different brands", so brand had to move off the equipment TYPE and onto the individual unit.
**Affects:** `db_migrations/202607220002_S-UNIT-BRAND_brands_table_and_unit_fk.sql` (NEW table `equipment_brands` + `equipment_units.brand_id` + **drops `equipment_templates.brand`**), ~40 files, plus the new Brands manage screen.
**Operator action:**
1. `git pull` + `ff-deploy`, then run the migration: `php /var/www/fleetforge/bin/migrate.php --dry-run` then `--apply`. **The code and the migration must land together** — the migration drops a column ~34 files used to read, so an app deploy without the migration (or vice-versa) will error on equipment pages.
2. The migration is safe on your data: it seeds the 27 brands and **carries each template's existing brand down onto its units** before dropping the column, so nothing is lost. On dev this preserved all 49/49 units.
3. **The re-assignment you asked about is NOT in this migration.** You said "make everything other than the dry vans a Max Atlas unit… we will write a script later" — that is deployment-specific and destructive, so it was deliberately left out. When you're ready, tell me which categories should become Max Atlas and I'll write an idempotent, dry-run-first script. Until then prod units keep whatever brand their template had.
4. Where to manage the list: **Equipment → Equipment Types → Manage Brands** (or `/equipment/brands`). Add, rename, reorder, deactivate. A brand in use can't be deleted — deactivate it instead and it stays on those units while dropping out of the picker.
5. Brand is **optional** on a unit, so any unit whose old template had no brand simply shows no brand until someone sets it.

### F61 — Deploy S-NORTHLAND-P0: bounce/complaint processing has NEVER worked, plus a TopicArn allowlist ✅ CLOSED (2026-07-22)

**✅ RESOLVED 2026-07-22 — deployed and verified end-to-end on live production (agent-run, operator-authorized).**
- Deployed `44bcf62 → 0ec9323` via `bin/deploy.sh --yes`; all 10 gated steps green (`composer install` pulled `aws/aws-php-sns-message-validator 1.10.0`; migration `202607220001` applied, 0 pending / 0 drift; health `status:ok`, login 200, no fatals).
- `class_exists('\Aws\Sns\MessageValidator')` on prod → **true**.
- **Bounce path proven:** sent to `bounce@simulator.amazonses.com`; `email_bounces` id 55 landed ~1s later — Permanent/General, `action_taken=email_disabled`, matching our exact MessageId. **First bounce this system has ever processed** (all bounce tables were literally 0 before).
- **Complaint path proven — and a second gap fixed:** SES had **no ComplaintTopic** wired at all, so complaints went nowhere regardless of code. Set `ComplaintTopic` = the existing `fleetforge-ses-bounces` topic, then sent to `complaint@simulator.amazonses.com`; `email_bounces` id 56 landed — Complaint/abuse, `action_taken=email_disabled`.
- **Allowlist proven in prod:** wiring the complaint topic triggered a fresh `SubscriptionConfirmation`, which the endpoint auto-confirmed *because the ARN matched the allowlist* — the intended behaviour; a non-matching ARN would have been refused.
- Simulator addresses are excluded from reputation metrics; `customer_id`/`user_id` are NULL on both rows — no real account touched.

**Residual operator action (optional):** none required. If you later rotate the SNS topic, update both `AWS_SNS_TOPIC_ARN` in `.env` **and** the SES Bounce+Complaint topic bindings together.

---

**Surfaced by:** S-NORTHLAND-P0 (2026-07-22), during the Northland Equipment clone audit.
**Affects:** `api/v1/webhooks/ses_notifications.php`. No schema. One migration ships alongside (`202607220001`, additive settings row).
**⚠️ BIGGER THAN FIRST WRITTEN — found by the post-commit adversarial review, confirmed on live prod.** `\Aws\Sns\MessageValidator` is **not part of `aws/aws-sdk-php`** — it ships in a separate package, `aws/aws-php-sns-message-validator`, which was never in `composer.json`. So the endpoint's signature check threw `Class not found` on **every** SNS delivery, the `catch (\Throwable)` swallowed it, and the request 403'd. **SES bounce and complaint auto-disable has been silently dead in production since it shipped.** Live evidence from prod's nginx error log:
`2026/07/21 20:30:36 [error] [ses_webhook] Unexpected error during signature check: Class "Aws\Sns\MessageValidator" not found ... client: 15.221.164.95` (an AWS SNS sender).
**Why this is urgent, not cosmetic:** complaints (recipients hitting "spam") never set `email_disabled=1`, so the system keeps emailing people who reported it. A rising complaint rate is the single fastest way to lose SES production access — and this AWS account's production access is what the Northland deployment will also depend on. The missing package is now in `composer.json`/`composer.lock`, and `_smoke_ses_webhook_topic_allowlist.php` gained a dependency guard so it cannot silently regress.

**The hole:** the bounce webhook authenticated callers by **SNS signature alone**. AWS signs SNS messages for *every* AWS account, so a valid signature proves "AWS sent this", never "OUR topic sent this". Any AWS customer could create their own SNS topic, subscribe this endpoint (it auto-confirmed), publish forged permanent-bounce notifications, and have us set `email_disabled=1` on arbitrary customer addresses — a **denial-of-email attack against our own customers**, no credentials required.
**Already done for you:** `AWS_SNS_TOPIC_ARN=arn:aws:sns:us-west-2:035837222410:fleetforge-ses-bounces` was appended to prod's `/var/www/fleetforge/.env` on 2026-07-21 under a one-time operator write grant (backup: `.env.bak.20260721_200552`). It is **inert until this code deploys** — which is the correct order, since it means the allowlist is enforced the instant the deploy lands, with no unconfigured window.
**Operator action:**
1. `git pull` + `ff-deploy` on prod. **`composer install --no-dev` must run** (step 6 of `bin/deploy.sh` does this) so the new `aws/aws-php-sns-message-validator` package lands — without it the webhook keeps 403ing.
1b. Confirm the class resolves on prod: `sudo -u www-data php -r 'require "vendor/autoload.php"; var_dump(class_exists("\\Aws\\Sns\\MessageValidator"));'` → expect `bool(true)`.
2. Confirm enforcement — the log should be silent, NOT warning:
   `sudo grep -c "no SNS topic allowlist configured" /var/log/nginx/error.log` → expect **0** after the deploy.
3. Confirm bounce processing still works end-to-end: the existing subscription is unchanged and its ARN matches, so real bounces should continue to land. Watch for any `[ses_webhook] REJECTED` line — that would mean the ARN does not match and needs correcting.
**Why blocking:** this is a live, unauthenticated abuse vector on production today. It has been open since the webhook shipped.
**Rollback:** if anything misbehaves, blank `AWS_SNS_TOPIC_ARN` in prod's `.env` — the code then falls back to permissive-for-Notification (old behaviour) while still refusing new subscriptions.

---

### F62 — Confirm the credit-application PDF's new brand colour 🟡 PARTIAL (cosmetic; customer-facing document)

**Surfaced by:** S-NORTHLAND-P0 (2026-07-22).
**Affects:** `includes/partials/credit_application_render.php`. No schema.
**What changed:** the credit-application **PDF** hardcoded `#f97316` (orange) while the HTML form at `app/admin/credit-application.php:39` already read `brand.primary_color`. Mainland's brand is `#2596be` (teal) on both dev and prod — so the PDF has been rendering **off-brand** relative to the app that generated it, since it shipped. It now reads the setting, so form and PDF finally match.
**Operator action:** generate one test credit-application PDF and confirm the teal accent bar + section borders look right. If you preferred the orange, set an explicit `brand_color` param at the call site or change `brand.primary_color` — do not re-hardcode.
**Scope of effect:** **new renders only.** Already-submitted applications keep their snapshotted `rendered_html`, which is the legal record and is deliberately immutable.

---

### F60 — Deploy S-TOPBAR-FIT (topbar no longer overflows on laptops/tablets) 🟢 DEFERRED (non-blocking; CSS + 2 class attrs, no schema)

**Surfaced by:** S-TOPBAR-FIT (2026-07-22), found in passing during S-UNIT-HERO-TILES.
**Affects:** `public/assets/css/app.css`, `includes/topbar.php`. No schema, no migration, no logic.
**What it fixes:** every admin page scrolled sideways at two common window sizes — roughly **1024-1170px** (a part-screen window on a big monitor, or a 1024/1152 laptop) and **768-890px** (tablet / narrow split-screen). The topbar's right-hand group never shrank between the desktop layout and the phone layout, so the excess pushed the whole document wider than the window.
**Operator action:**
1. `git pull` + `ff-deploy`. `FF_ASSET_VERSION` derives from the git HEAD hash, so the deploy cache-busts the CSS automatically — but **hard-refresh once** (⇧⌘R) if you still see a sideways scrollbar.
2. **What you'll notice, by window width** — nothing changes on a full-screen desktop except that a stray duplicate magnifying-glass icon (a phone-only control that was rendering at every width, next to the real search box) is gone:
   - **below 1280px**: the name + role text beside your avatar is dropped; the avatar remains and the full name/role are in the menu it opens.
   - **below 1024px**: the theme toggle, text-size (A+), sound toggle and AI sparkle are dropped. ＋New, search, team chat, notifications and the avatar all stay. Text size / density is still available on your **Profile** page, and the theme toggle is in the sidebar.
   - **below 768px** (phones): unchanged from before.
3. Nothing to remediate; read-only visual fix.

### F59 — Deploy S-DAYS-ON-RENT + review the overlapping-lease data it exposes 🟢 DEFERRED (non-blocking; PHP only, no schema/asset)

**Surfaced by:** S-DAYS-ON-RENT (2026-07-21).
**Affects:** NEW `api/v1/equipment/units/days-on-rent.php`, `app/admin/equipment/show.php` (Lease History tab). No schema, no migration. **Also carries S-DAYS-ON-RENT-2** (per-lease breakdown table, 15 leases/page) and **S-UNIT-HERO-TILES** (unit-profile hero tiles widened so Brand / Make and VIN stop wrapping) — same files, same deploy. S-UNIT-HERO-TILES touches `public/assets/css/app.css`, so **hard-refresh once after deploy** if the tiles still look narrow; `FF_ASSET_VERSION` derives from the git HEAD hash (`config/app.php:110-125`), so the deploy cache-busts it automatically — no `.env` edit needed.
**Operator action:**
1. `git pull` + `ff-deploy`. Read-only feature — nothing to remediate, nothing changes about billing.
2. **Worth a look:** the panel merges overlapping leases and says so when it does. If a unit shows that note on prod, it means that unit has leases booked on the same days — nothing validates a new lease's dates against the unit's other leases (`api/v1/leases/create.php` gates on the unit's *current* status, which is date-blind, so a back-dated lease sails through). On dev, units 1/2/3 all have genuine overlaps. If prod shows the same, a double-booking guard at lease create is worth its own session — say the word and it gets one.
3. Note the number is **calendar occupancy**, not billed days: it deliberately ignores `billing_days_removed` and the return-grace day-drop, so it can differ from an invoice by a day or more on purpose. That is the metric you asked for; a billed-days variant would be a separate figure.

### F58 — Deploy S-HOURLY-ONLY (engine-hours-only leases + on-close $0-underbill fix) 🟢 DEFERRED (non-blocking; PHP only, no schema/asset)

**Surfaced by:** S-HOURLY-ONLY (2026-07-14).
**Affects:** `api/v1/leases/{create,lookup_rates,amend_rate}.php`, `app/admin/leases/create.php`. No schema, no migration, no `app.css`/asset-version change (guidance banners reuse the existing `.alert` classes).
**Operator action:**
1. `git pull` + `ff-deploy`. No `FF_ASSET_VERSION` bump needed.
2. After deploy: engine-hours-only leases (0 daily/weekly/monthly + an hourly rate) are creatable, and a partial period-rate set on an "on close only" lease is now rejected at create/amend (was a silent `$0` base under-bill). No data remediation needed (0 prod leases known exposed; confirm with the exposed-shape query in the session notes if desired).

### F57 — Deploy S-LUX-LOUD (bolder look) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking; this is the visibly-different one)

**Surfaced by:** S-LUX-LOUD (2026-07-12) — response to "the redesign looks the same / too subtle."
**Affects:** `public/assets/css/app.css` only (dark surface ramp, KPI tile accent bars, topbar brand underline, active-nav rail, page headings). No schema, no logic, no markup.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` so the CSS cache-busts (dev at 1.0.55; the pipeline also auto-stamps from the git hash).
3. **Hard-refresh** (`Cmd+Shift+R`) — this is the one you'll actually see: coloured accent bars across the dashboard tiles, a brand-blue line under the topbar, cards that lift off a noticeably darker background, and bigger/bolder page titles.

**Why it matters:** F51–F56 were quiet refinement; this one is the deliberately-louder pass you asked for. If it's *too* loud (or not enough), it's a fast CSS tune — say the word.

---

### F56 — Deploy S-LUX-5 (Atelier closer: semantic-banner fix) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking; fixes real dark-mode bugs)

**Surfaced by:** S-LUX-5 (2026-07-11) — final session of the Atelier arc.
**Affects:** visual layer — `app.css` (`.alert-*` rebase + token aliases) + ~20 accounting/portal/qbo view files (hardcoded amber/red banner idiom → `.alert-*` classes) + the CCA form + `.rate-item-card`. **Unlike the other F5x follow-ups, this one fixes actual bugs**, not just polish: accounting warning/error banners were hardcoded light colours that rendered a jarring pale box in dark mode, and every `.alert` was previously transparent (bg-less) because it referenced undefined tokens. No schema, no PHP logic, no queries.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` (dev went 1.0.53→1.0.54) so browsers drop the cached CSS. (Deploy pipeline auto-stamps from the git hash — verify the served `?v=` changed.)
3. Eyeball in **dark mode**: an accounting screen with a banner (e.g. FX Revaluation when disabled, or a report with a warning) — the amber/red/green banners should now be subtle theme-adapted tints, not pale light boxes; the Rates dashboard cards should be dark (were cream).

**Why deferred:** still a CSS-only change; the old cached CSS keeps rendering until the version bump, and the pre-fix banners were readable (just light/inconsistent), so nothing is broken enough to be urgent.

---

### F55 — Deploy S-LUX-4 (Atelier front door: login/portal/empty-states) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking, cosmetic)

**Surfaced by:** S-LUX-4 (2026-07-11).
**Affects:** visual layer only — auth screens (`app/auth/*.php`), portal chrome (`app/portal/includes/*.php`), and the empty-state CSS in `app.css`. Includes a small **white-label fix**: the 4 aux auth screens (mfa/forgot/reset/mfa_required) + the customer portal now inject the `brand.primary_color` override, so they render your brand colour instead of default orange. No schema, no auth logic, no portal data logic.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` (dev went 1.0.51→1.0.53) so browsers drop the cached CSS. (The prod deploy pipeline auto-stamps the asset version from the git hash, as it did for F51 — verify the served `?v=` changed.)
3. Eyeball: the login page (video kept, card entrance), a password-reset screen (branded card on a subtle glow), the customer portal (glass header, brand nav rail, footer version chip — all in your brand colour now), and any empty list (centered icon + "No … found").

**Why deferred:** pure CSS/markup visual change; old cached assets keep the previous look until the version bump, nothing breaks.

---

### F54 — Deploy S-LUX-3 (Atelier chrome & motion) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking, cosmetic)

**Surfaced by:** S-LUX-3 (2026-07-11).
**Affects:** visual layer only — sidebar/topbar/dropdowns/modals/toasts/page-header styling + motion in `public/assets/css/app.css` (+ a chevron `<svg>` in `includes/topbar.php` and a toast progress `<div>` in `public/assets/js/app.js`). No schema, no PHP logic, no routes, no nav structure.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` (dev went 1.0.50→1.0.51) so browsers drop the cached pre-S-LUX-3 `app.css` + `app.js`. (The prod deploy pipeline auto-stamps the asset version from the git hash, as it did for F51 — verify the served `?v=` changed.)
3. Eyeball the chrome in both themes: open the account menu (chevron flips), trigger a toast (progress hairline), open a modal (rounded panel + blurred backdrop), collapse the sidebar (smooth width + labels fade/slide).

**Why deferred:** pure CSS/markup visual change; old cached assets keep rendering the previous chrome until the version bump, nothing breaks.

---

### F53 — Deploy S-LUX-2.5 (Atelier form controls) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking, cosmetic)

**Surfaced by:** S-LUX-2.5 (2026-07-11).
**Affects:** visual layer only — form controls (pills, checkboxes/radios, segment, pickers, validation states) in `public/assets/css/app.css`. No schema, no PHP, no validation logic, no markup.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` (dev went 1.0.49→1.0.50) so browsers drop the cached pre-S-LUX-2.5 `app.css`. (The prod deploy pipeline auto-stamps the asset version from the git hash, as it did for F51 — verify the served `app.css?v=` changed.)
3. Eyeball a form (lease create / a settings tab) in both themes: checkboxes/radios fill in the brand colour with a white glyph, selects show the warm chevron, focus rings + validation rings read correctly.

**Why deferred:** pure CSS visual change; old cached `app.css` keeps rendering the previous form look until the version bump, nothing breaks.

---

### F52 — Deploy S-LUX-2 (Atelier chart theme + data surfaces) + bump prod FF_ASSET_VERSION 🟢 DEFERRED (non-blocking, cosmetic)

**Surfaced by:** S-LUX-2 (2026-07-11).
**Affects:** visual layer only — chart styling, tables, KPI tiles, a new JS file (`public/assets/js/ff-chart-theme.js`). No schema, no PHP logic, no chart data/queries.
**Operator action:**
1. `git pull` + `ff-deploy`.
2. Bump `FF_ASSET_VERSION` in the prod `.env` (dev went 1.0.47→1.0.48) so browsers drop the cached pre-S-LUX-2 `app.css` + pick up the new `ff-chart-theme.js`. (The prod deploy pipeline may auto-stamp the asset version from the git hash, as it did for F51 — verify the served `app.css?v=` changed.)
3. Eyeball the dashboard + analytics in both themes: charts should render in the brand colour, and toggling the theme should re-colour charts in place (no reload). The KPI tiles are now token surfaces (not cream).

**Why deferred:** pure CSS/JS visual change; old cached assets keep rendering the previous look until the version bump, nothing breaks.

---

### F51 — Deploy S-LUX-1 (Atelier design foundation) + bump prod FF_ASSET_VERSION ✅ CLOSED 2026-07-11 (verified same day)

**Surfaced by:** S-LUX-1 (2026-07-11). **Closed by:** agent verification 2026-07-11 (read-only prod checks) after the operator's redeploy.
**What happened:** the operator's `ff-deploy` for the S-FORM-LAYOUT/S-HOURS-EST-VIS follow-ups carried S-LUX-1 with it — prod HEAD = `46e0358` (includes all S-LUX-1 commits). The deploy pipeline stamps the served asset version from the git hash, so no manual `.env` bump was needed: the live login page serves `app.css?v=46e03582` (cache-busted).
**Verification (2026-07-11):**
- `ssh fleetforge git log` → HEAD `46e0358`; `public/assets/fonts/` contains both Geist WOFF2 + OFL.txt; deployed app.css contains the Atelier tokens.
- `curl -g https://mainlandrentals.com/assets/fonts/Geist[wght].woff2` → **200** (Mono also 200; %5B-encoded form also 200 — nginx decodes before file lookup as predicted).
- Live `https://mainlandrentals.com/assets/css/app.css` serves the Atelier palette (`#0C0B09`/`--card-sheen` present); login page emits both font preloads.
- Remaining (cosmetic, operator-optional): eyeball a couple of pages in both themes on prod.

---

### F50 — Deploy S-HOURS-EST-DAILY + S-UNIT-DECOMMISSION-UI + run migration 202607110001 on prod 🟡 PARTIAL

**Surfaced by:** S-HOURS-EST-DAILY + S-UNIT-DECOMMISSION-UI (2026-07-11) — estimated engine-hours-per-day billing + close-time running true-up (the engine-hours parallel of S-MILEAGE-EST-DAILY), bundled with a one-click unit-decommission button.
**Operator action:**
1. `ff-deploy` the S-HOURS-EST-DAILY + S-UNIT-DECOMMISSION-UI commit.
2. Run the migration: `php /var/www/fleetforge/bin/migrate.php --dry-run` then `--apply` — applies `202607110001_S-HOURS-EST-DAILY_estimated_engine_hours_per_day.sql` (adds `leases.estimated_engine_hours_per_day DECIMAL(10,2) NOT NULL DEFAULT 0.00` AFTER `engine_hours_at_end`; extends `invoice_line_items.item_type` ENUM += `hours_estimate`/`hours_adjustment`/`hours_credit`; extends `credit_notes.source` ENUM += `hours_overpayment`). All three steps INFORMATION_SCHEMA-guarded → idempotent; existing rows untouched.
3. Verify: leases with `estimated_engine_hours_per_day=0` (the default for every existing lease) bill exactly as before — the feature is inert until an operator sets a per-day value on an hourly-rate lease.
**Why PARTIAL (not blocking):** everything is inert-or-safer until deploy; pre-migration no lease carries a per-day hours estimate, and per-day=0 leases keep billing via the legacy `hourly_usage` path. The S-UNIT-DECOMMISSION-UI half is code-only (no migration) — the new Decommission button lands with the deploy.

**Two prod-data notes surfaced this session (operator action, no code fix needed):**
- **INV-2026-01198 "why bill to July 2?" — DIAGNOSED, NOT a bug.** Lease 337 (MTTS450, Mohinder Toor, unit STL2313) started Jun 23 and was still open; Mike manually generated INV-2026-01198 on Jul 2 (`generation_source=manual`, `auto_generated=0`, created_by Mike). An open-lease on-demand invoice bills start→generation-date = Jun 23–Jul 2 (10 days); the final invoice at close continued Jul 3–Jul 9, so INV-2026-01198 + #1786 tile the full 17-day term once (no double-bill). **Cleanup:** the stale draft **INV-2026-00686** (lease 337, Jun 23–30, no mileage line) OVERLAPS INV-2026-01198 and should be **voided**; then send INV-2026-01198 + #1786.
- **STL2038** — write-off unit, currently `available` on prod (`equipment_units` id 148). The operator can now retire it out of the rentable fleet via the new **Decommission** button (S-UNIT-DECOMMISSION-UI, keeps all history) once deployed — or set it **Inactive** today (no code deploy needed for the Inactive path).

### F49 — Deploy S-AUDIT-BILLING-ENGINE-1 (25 billing/GL/FX fixes) + run migrations 202607100002 + 202607100003 on prod; retime the monthly-generation crontab line 🟡 PARTIAL

**Surfaced by:** S-AUDIT-BILLING-ENGINE-1 (2026-07-10) — companion billing-engine/GL audit, all 25 findings fixed (headline: bulk payment delete skipped JE reversal; deposits/apply broke the balance_due identity; NSF reversal ignored overpayments/FX; GL posted USD at face value — now CAD-canonical with realized FX 7030/7040 legs; ≤7-day leases now bill cheaper-of daily-vs-weekly per D-R2-2).
**Operator action:**
1. `ff-deploy` the S-AUDIT-BILLING-ENGINE-1 commits.
2. Run the migrations: `php /var/www/fleetforge/bin/migrate.php --dry-run` then `--apply` — applies `202607100002` (adds `'customer_deposit'` to `acc_journal_entries.source_type` ENUM) and `202607100003` (adds `credit_notes.exchange_rate_to_cad DECIMAL(10,6) NULL`). Both idempotent; existing rows untouched.
3. **Crontab (verified live 2026-07-10):** the prod www-data crontab still runs `0 6 1 * *` for `invoice_generate_monthly.php` — the exact mistimed UTC schedule S-CRON-FIX-1 flagged (fires ~22:00 Vancouver on the PRIOR month's last day). Harmless today only because the cron toggle ships OFF (F32); change the line to `0 14 1 * *` (= 06:00 America/Vancouver on the 1st) BEFORE enabling the toggle. This completes F30.
4. Optional: existing USD credit_notes have NULL `exchange_rate_to_cad` — the bridge falls back to the source invoice's (then source payment's) rate automatically, so no backfill is required; a backfill is cosmetic only.
**Why partial:** everything is inert-or-safer until deploy; pre-migration, deposit JEs simply keep the old source_type and CN FX falls back to the invoice rate.

### F48 — Deploy S-AUDIT-LIFECYCLE-1 (25 lifecycle fixes) + run migration 202607100001 on prod 🟡 PARTIAL

**Surfaced by:** S-AUDIT-LIFECYCLE-1 (2026-07-10) — full lease+invoice lifecycle audit, all 25 findings fixed (1 CRITICAL: precharge drawdown never restored on void/delete → customers double-paid mileage; closes F33 at every removal site).
**Operator action:**
1. `ff-deploy` the S-AUDIT-LIFECYCLE-1 commits (39 files — billing engine, invoice endpoints, close/bulk_close, payments/AR gates).
2. Run the migration: `php /var/www/fleetforge/bin/migrate.php --dry-run` then `--apply` — applies `202607100001_S-AUDIT-LIFECYCLE-1_gps_cost_default.sql` (changes `leases.gps_cost` column DEFAULT 1.00 → 0.00; existing rows untouched; idempotent).
3. Optional data check — leases whose drawdowns were consumed by an ALREADY-voided invoice pre-fix are still under-balanced. Detector (read-only): `SELECT l.id, l.contract_number, SUM(li.amount) AS unrestored FROM leases l JOIN invoices i ON i.lease_id=l.id AND i.status='void' JOIN invoice_line_items li ON li.invoice_id=i.id AND li.item_type='mileage_drawdown_credit' WHERE l.precharge_enabled=1 GROUP BY l.id;` — any rows predate the fix and need a one-time manual `precharge_balance` correction (none expected: the trigger was narrow).
**Why partial:** all behavior is inert-or-safer until deploy; nothing breaks pre-migration (the default only affects INSERTs that omit gps_cost, which the API never does).

### F47 — Tell the manager INV-2026-01760 now reads exactly his math + apply/refund Mander's $42.01 account credit 🟡

**Surfaced by:** S-CAP-MULTILINE + S-ORPHAN-OVERFLOW-CN (2026-07-09); **updated same day** by S-REGEN-PRESERVE-ESTIMATE + S-REFUND-ON-INVOICE — after the operator chose "show the refund on the invoice" + "regenerate the drafts per-period", the whole lease-358 series was regenerated in order on prod.
**State now:** INV-2026-01760 **displays the manager's exact numbers** — base **+$36.67** (his 2 × $18.33) · mileage **−$80.68** (his −2,017 km × $0.04) · GPS **+$2.00** · "Refund converted to customer account credit — **CN-CR-2026-00014 ($42.01)**" (his −$42.02, ±1¢ rounding) — with a $0.00 total (finals never go negative; the refund is the account credit). The Apr/May/Jun drafts were re-priced per-period (Apr $338.10 / May $740.25 / Jun $829.50 — estimates preserved at their billed 800/3,100/5,250 km); customer's series net is $1,865.84, correct per the rate card. CN-11/12/13 are all **VOID** (artifacts of the bug + earlier remediation pass; GL JEs reversed, 2060 ties to $42.01).
**Operator action:**
1. Tell the manager: the invoice now shows exactly his math; the −$42.01 net lives as account credit CN-CR-2026-00014 (finals floor at $0 by design).
2. When the lease-358 drafts are sent, **apply CN-CR-2026-00014 ($42.01)** against Mander's balance (or refund it).
3. Do NOT touch CN-11 / CN-12 / CN-13 — void, must stay void.

### F46 — Deploy S-CLOSE-NO-ESTIMATE + regenerate the Mander drafts ✅ DEPLOYED + DRAFTS REGENERATED 2026-07-08; CN item superseded by F47 ✅

**UPDATE 2026-07-09 (S-ORPHAN-OVERFLOW-CN):** the remaining CN item is CLOSED-SUPERSEDED — CN-CR-2026-00011 turned out to be an ORPHAN (its source invoice INV-2026-01444 was deleted 2026-07-07, and the stale CN was double-poisoning later true-ups). It is now **voided** with its GL JE reversed; the complete, correct refund is CN-CR-2026-00013 ($91.19) — see **F47**. Do not apply/refund CN-11.

**UPDATE 2026-07-08 (evening):** operator ran `ff-deploy` (prod at 04ccdbf, verified) and granted a one-time prod write; the agent regenerated both drafts via `scripts/fix_mander_close_invoices_2026_07_08.php` — INV-2026-01444→**INV-2026-01613** ($0.00) and INV-2026-01454→**INV-2026-01614** ($152.86, identical total). Counters/OB unchanged, no duplicate credit note. ~~⚠ REMAINING (the only open item): CN-CR-2026-00011 ($40.01, Mander Bros, active) — apply it against a future Mander invoice or refund it.~~ **Superseded — see F47.** (Original entry below.)

### F46 (original) — Deploy S-CLOSE-NO-ESTIMATE (close-time mileage estimate suppression + true-up idempotency) 🟡 PARTIAL

**Surfaced by:** S-CLOSE-NO-ESTIMATE (2026-07-08) — operator report on the Mander Bros close invoices (MTTS403 "final billing came out to $0", MTTS406 "doesn't add up right", "remove the estimated mileage as soon as I hit close").
**What shipped:** `InvoiceGenerator` — (1) final-settlement invoices skip the stub-period `mileage_estimate` when a lifetime actual reading is available (the true-up alone settles the lease); (2) completed manual leases feed `mileage_at_end` into the true-up on regenerated final invoices; (3) billed-to-date subtracts issued `mileage_overpayment` credit_notes so re-settlement is idempotent; (4) true-up descriptions show the arithmetic. NO migration.
**Operator action:**
1. `ff-deploy` (code only — no migration).
2. The Mander drafts **INV-2026-01444** + **INV-2026-01454** have correct totals and may be sent as-is. For the cleaner presentation (single true-up line, no stub estimate), void+regenerate them **after** deploy — regenerating BEFORE deploy double-credits the $40.01 overflow (pre-fix true-up can't see the credit note).
3. **CN-CR-2026-00011 ($40.01, Mander Bros, active)** is the missing piece of MTTS403's refund — apply it against a future Mander invoice (or refund it). The $0.00 on INV-2026-01444 is the subtotal floor: $52.67 of charges minus $52.67 of capped credit; the remaining $40.01 of the $92.68 refund lives in this CN.
**Why PARTIAL (not blocking):** billing totals are correct pre-deploy; the fix is presentational + closes the reclose double-credit trap before anyone hits it.
**UPDATE 2026-07-08 (same day, S-CLOSE-TRUEUP-GUARANTEE):** the deploy now also carries the close-path carrier guarantee — pre-deploy, a last-day close onto an existing monthly draft (or a close within an already-billed period) of an estimate-model lease SILENTLY SKIPS the mileage reconciliation entirely (the reading is ignored, only the estimates stand). Same single `ff-deploy`, still no migration. Prod data checked read-only for D132 rate-tier holes: none.

---

### F40 — Run the equipment-taxonomy backfill on prod after deploying S-EQTAX 🟡 PARTIAL

**Surfaced by:** S-EQTAX (2026-06-26) — the two-level Category → Sub-category taxonomy (root-cause fix for "the 3-day minimum only works for some customers": Combo was a top-level category, so the chassis-only minimum skipped it).
**Affects:** Combo equipment billing (Jete's Lumber, and any Combo lease) + the manage-screen per-type usage counts.
**Operator action (after deploying the S-EQTAX commits + running migrations):**
1. `php /var/www/fleetforge/bin/migrate.php --apply` (applies `202606260001_S-EQTAX-1_taxonomy_tables.sql` — creates the tables + seeds the 10 categories).
2. `php /var/www/fleetforge/scripts/backfill_equipment_taxonomy.php --dry-run` to preview, then run it for real (no flag) to populate `category_id`/`subcategory_id` on every template, map the Combo templates → Chassis category + a "Combo" sub-category, and reconcile the enforce-minimum flag from the existing setting. **Idempotent** — safe to re-run.
3. Verify: the script prints "templates with NULL category_id : 0 OK" and "combo templates still unmapped : 0 OK".
4. Re-close / regenerate Jete's Lumber's open Combo draft invoices so the 3-day minimum now applies (the gate resolves at billing time).
**Why PARTIAL (not blocking):** chassis / dry-van / etc. resolve their billing rule immediately via the slug-mirror fallback in `InvoiceGenerator`, so most billing is correct the moment the code deploys. **Combo is the exception** — there is no top-level `combo` category for the fallback to match, so a Combo lease will NOT inherit the chassis minimum until the backfill sets its `category_id` to Chassis. So the operator's original Jete's complaint is only fully fixed once the backfill runs.

---

### F43 — Reopen→reclose DOUBLE-BILLED closeout/mileage (MTTS206) ✅ CODE FIXED + draft cleared; ⚠ regenerate MTTS206's invoice 🟡

**UPDATE 2026-06-28:** (1) **CODE FIXED** — `S-CLOSE-RECLOSE-IDEMPOTENT` (commit 6e32bce) makes the close fold delete-and-replace its own closeout/mileage lines instead of appending, so a reopen→reclose no longer duplicates them (RC-group smoke proves it). **Needs `ff-deploy`** to go live. (2) **DATA** — the corrupted draft INV-2026-00657 was already **soft-deleted by the operator** (bulk_delete, audit #13329) before the agent's dedup ran, so no line-level correction was needed (the dedup script's strict `deleted_at IS NULL` precondition correctly skipped it). (3) **⚠ REMAINING:** lease 321 / **MTTS206** is now `completed` with **NO live invoice** (INV-2026-00656 void, INV-2026-00657 deleted) — the rental needs an invoice **regenerated** (reopen→reclose, or Create Invoice). Do this **after** `ff-deploy` of 6e32bce so the regenerated invoice isn't re-double-billed by the un-fixed fold. (Original entry below.)

---

### F43 (original) — Reopen→reclose DOUBLE-BILLED closeout/mileage on draft INV-2026-00657 (MTTS206) 🟡 PARTIAL

**Surfaced by:** checking prod Sentry FLEETFORGE-15 (2026-06-28, after ff-deploy of the session commits). A manager closed lease 321 / **MTTS206**, then **reopened it to change a date, then re-closed**. The legacy close FOLD (`legacy_append_mileage_to_full_month_draft`, close.php) re-appends the mileage + closeout `$extraLines` to the same clamped draft on EACH close without removing the prior pass — so draft **INV-2026-00657** now has **duplicate** lines: mileage $5.76 ×2 (line ids 1413/1415) + fuel $1,787.50 ×2 (1414/1416) = **$1,793.26 over-billed**. It is a **DRAFT** (status=draft, never sent) so no money has moved. (Note: the engine-hours line did NOT double — its target-scoped idempotency held; this is purely the legacy mileage/closeout fold.)
**Two parts:**
1. **DATA (do before sending the invoice):** correct INV-2026-00657 — remove the duplicate mileage + fuel lines (keep one of each) and recompute totals (Edit Lines, or void + recreate via the close). Expected corrected subtotal drops by $1,793.26. *Agent can do this on request (it's a draft) — not yet done (awaiting go-ahead; outside the earlier one-time prod-write grant which was for the hours invoices).*
2. **CODE (focused fix, pending):** make the close fold idempotent on reopen/reclose — it must not re-append closeout/mileage lines that a prior close already added (delete-and-replace, or regenerate the segment cleanly). Subtle because mileage can be engine-generated OR fold-added; needs care + tests. The `is_credit` warning half (FLEETFORGE-15) IS fixed in this commit; this double-append half is separate.
**Why PARTIAL (not blocking):** the invoice is an un-sent draft; the manager will see it before sending. But any reopen→reclose of a lease with mileage/closeout charges will reproduce it until the code fix lands — and the new editable-pickup-date feature makes reopen-to-change-date a more common flow.

---

### F42 — Missed engine hours on prod hourly leases ✅ DATA FIXED (deploy still pending) 🟢

**UPDATE 2026-06-28 (S-LEASE-HOURLY-RECON-2):** the operator surfaced a SECOND affected invoice (INV-2026-00650, lease 318/MTTS176, $3.00) and granted a one-time prod-write. Both invoices were remediated by the agent via `scripts/bill_missing_hours_2026_06_28.php` (idempotent, dry-run-first, recompute through `InvoiceRecalc`): **INV-2026-00466 $461.95 → $514.04** (+$46.50) and **INV-2026-00650 $233.70 → $237.06** (+$3.00). A post-fix prod scan of all completed hourly leases is now EMPTY. The code also now handles BOTH close shapes that dropped hours (overshoot reissue + partial_end-skip). **Remaining:** `ff-deploy` the S-LEASE-HOURLY-RECON + RECON-2 commits so FUTURE closes auto-bill hours — this rides the general deploy already owed; no further data action. (Original entry below, kept for history.)

---

### F42 (original) — Bill the missed engine hours on prod lease MTTS0262 / INV-2026-00466 🟡 PARTIAL

**Surfaced by:** S-LEASE-HOURLY-RECON (2026-06-27) — operator reported "invoice -2026-00466 did not register hours when created." Root cause: the close **overshoot-reconciliation** voided the activation invoice (INV-2026-00465, billed to month-end) and reissued it clamped to the return date, but the reissue's `createFromLease` call omitted the engine-hours window — so the `hourly_usage` line was dropped and the partial_end final invoice (which would otherwise bill the hours) was skipped because coverage already reached the extent. Code fixed for **all future closes** (close.php → reconcile_overshoot_invoices/adv_partial_refund_containing now forward the hours; regenerate.php now carries the invoice's own hours snapshot).
**Affects:** EXACTLY ONE prod lease — id 223 / **MTTS0262**, draft **INV-2026-00466** (id 448), **$46.50** of un-billed engine hours (8967 → 8998 = 31.00 hrs × $1.50/hr). Confirmed via a full prod scan of completed hourly leases (rate>0, end-hours set, hours accrued) with zero `hourly_usage` lines — only this one row.
**Operator action (after `ff-deploy` of the S-LEASE-HOURLY-RECON commit):**
1. Deploy — fixes every future close automatically (no data step needed for new leases).
2. INV-2026-00466 was created BEFORE the fix, so it carries **no** engine-hours snapshot — regenerate cannot recover it (there is nothing to re-bill from). Two equivalent ways to bill the $46.50:
   - **Preferred:** void the draft INV-2026-00466, then use the lease's **Create Invoice** action for period `2025-12-15 → 2025-12-18`, entering engine hours **start 8967**, **end 8998** → the engine emits base + GPS + mileage + the `31.00 hrs × $1.50 = $46.50` hourly line. (`invoices/create.php` already passes the hours correctly — no deploy needed for this path.)
   - **Or:** add a single `hourly_usage` line ($46.50) to the existing draft via the line-item editor.
3. Verify the resulting invoice carries an `hourly_usage` line for **$46.50**.
**Why PARTIAL (not blocking):** the invoice is a draft (no money has moved), the amount is $46.50, and exactly one lease is affected. New leases are correct the moment the fix deploys.

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

> **S-QBO-GOLIVE-AUDIT (2026-09-23):** resolved in code — the `POST …/quickbooks/v4/payments/charges` call could never work (that endpoint charges a tokenized card; Intuit has no hosted-payment-page API). `QuickBooksClient::generatePaymentsHostedUrl` now reads `Invoice.InvoiceLink` via `GET invoice/{id}?include=invoiceLink`, and InvoicePusher sets `AllowOnlineCreditCardPayment/ACHPayment` + `BillEmail` when `quickbooks.payments_enabled='1'`. Still needs ONE live check on a sandbox with QuickBooks Payments active (folded into F80). Steps 5–6 below are obsolete.

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

### F66 — Nothing has ever been billed through FleetForge: 1,553 unsent draft invoices ($1,080,790.91) 🔴 BLOCKING (LIVE NOW)

**Surfaced by:** S-REPORTS-STALE (2026-08-16) — operator asked "why aren't reports being updated and is empty?". Investigating the Reports module found the module was correct and the DATA was the story.

**Observed on prod (read-only probe, 2026-08-16):**
- `invoices` contains exactly TWO statuses: `draft` (1,553 / $1,080,790.91) and `void` (145). There are **zero** `sent`, `partially_paid`, `paid`, or `overdue` rows — none have ever existed.
- `payments` table is **empty** (0 rows).
- `customers.outstanding_balance` sums to **$0.00** across all 42 customers — internally consistent, since that counter only increments on draft→sent (Path B semantics).
- The operational side IS in real use: 169 units, 514 leases (165 active), 42 customers. Drafts span 2025-05-28 → 2026-12-03.
- `cron.invoice_generate_monthly_enabled = 0` — the monthly recurring-invoice cron is switched OFF, so Aug-2026 produced only 2 drafts against 175 in July.

**What this breaks:** every money-derived surface reads $0.00 for EVERY date range including All Time — Reports (all Financial + Customer views), Dashboard revenue KPIs, AR aging, collections, dunning, and the QBO InvoiceEnqueuer (which only pushes `sent`). None of these are broken code; they are all correctly reporting that nothing has been billed.

**Operator action (decisions required — do NOT bulk-send blindly):**
1. Decide which of the 1,553 drafts are real billings vs. abandoned/superseded artifacts. Future-dated drafts through 2026-12-03 almost certainly should NOT be sent yet.
2. Decide whether `cron.invoice_generate_monthly_enabled` should be turned back ON (Settings → Intelligence → Scheduled Jobs), or whether monthly billing is intentionally manual.
3. Send the approved backlog. **Side effects to expect:** each draft→sent transition increments `customers.outstanding_balance`, and sending fires customer email — 1,553 at once would be a very large mail burst against SES limits. Stage it.
4. After sending, verify: Reports → Financial → By Period should populate, and `customers.outstanding_balance` should become non-zero.

**Why blocking:** FleetForge's core purpose is billing, and no invoice has ever left draft on production. Until this is resolved the operator has no AR, no revenue reporting, and no QBO invoice sync — regardless of how much of the app works.

**Code-side (already shipped in S-REPORTS-STALE, deploy required):** the Reports module AND the Analytics module no longer render silent zeros — Analytics' six money-driven views (revenue forecast, utilization matrix, concentration risk, seasonal pattern, cohort revenue, avg lease value) now carry the same explanation, each scoped to the window that view actually queries. Reports names the cause inline — "N draft invoices totalling $X fall in this range, but drafts are excluded from revenue reporting until they are sent" — with a link straight to the draft list. **No migration** — plain code deploy. Deploying this first will make the backlog visible in-app while it is worked through.


## 🟢 DEFERRED — queued for follow-up sessions

### F76 — Deploy S-GPS-LOCAL-WINDOW, then decide whether to regenerate 23 trailer-mileage drafts 🟢 DEFERRED (non-blocking; PHP only, drafts only)

**Surfaced by:** S-GPS-LOCAL-WINDOW (2026-09-17).
**Affects:** per-invoice Samsara mileage on samsara-mode leases. Nothing has been sent (prod invoices never leave draft).
**Detail:** the Samsara distance for an invoice period used a UTC-midnight window, i.e. 5pm → 5pm Pacific
(4pm → 4pm in winter). Read-only prod check: 64 completed samsara-mode leases, **all trailers**; **24 draft + 5 void**
invoices carry a Samsara `period_distance_km`, and **23 of the drafts have mileage lines (~$2.9k total)**, periods
2025-06-01 → 2025-11-14 (e.g. INV-2026-00088 MTTS43-1 10,910 km / $406.77, INV-2026-00095 MTTS47 10,711 km / $399.32).
Each of those distances is off by the driving in roughly 7–8 hours at each end of the period: it picked up the evening
before the start date and dropped the evening of the last day. For a lease's final period that evening is lost, not
moved. The 24 drafts span 22 leases.
**Operator action:**
1. Deploy the latest `main` (no migration, no schema, no `FF_ASSET_VERSION` bump). Invoices created after that use local days.
2. Decide on the 23 existing drafts. Regenerating a draft re-fetches Samsara on the corrected window (none of these
   drafts has a closing odometer reading, so the engine goes back to Samsara). Samsara must still hold 2025 history for it to work.
   If a re-fetch fails, the draft loses its mileage line, so check each regenerated draft before sending. Leaving them
   as they are is also reasonable given the small shift. Void invoices need no action.

---

### F75 — Demo dataset registers fixed assets with no GL cost entry 🟢 DEFERRED (dev/demo data only)

**Surfaced by:** S-CASHFLOW-TIE (2026-09-17).
**Affects:** the dev / presentation dataset (S-DEMO-MULTIYEAR), not prod — all 166 prod assets are `is_opening_balance = 1`.
**Detail:** the dev register holds 49 fixed assets ($4,419,510.00, `is_opening_balance = 0`, acquired 2019-04 → 2025-10) but **no journal entry ever debits 1210/1230/1250/1270**, while depreciation of $956,846.69 was posted to 1220. So the balance sheet shows accumulated depreciation with no cost behind it (negative net PP&E), and the Cash Flow Statement — which now reads investing activity from the GL instead of the register — shows no equipment purchases. The old statement reported $660k–$869k/yr of "asset acquisitions" that never touched cash, which is what broke its tie-out.
**Action:** when the demo pipeline is next rebuilt (`docs/DEMO_SEED_MANIFEST.md`), post each non-opening-balance asset's acquisition as a JE (DR asset cost / CR cash or AP, or a loan for financed units) — or mark pre-2023 assets `is_opening_balance = 1` with an opening-balance JE. No code change needed.

### F64 — Deploy S-CUSTOMER-NOTIFICATIONS + run migration 202608070001 🟡 PARTIAL (Task 1 already effective on code deploy)

**Surfaced by:** S-CUSTOMER-NOTIFICATIONS (2026-08-07) — operator Task 1: "stop sending customers emails for expiring insurance" (in practice, all compliance-expiry customer emails).
**What changed:** the compliance cron's customer-email branch is now gated on a `compliance_expiry` reminder that ships **disabled**, and `lib/Notifications/CustomerReminders.php` falls back to that OFF default (like `cron_enabled()`). **So simply DEPLOYING this session's code STOPS the customer compliance emails (incl. insurance) immediately — no migration required for Task 1.** The staff in-app compliance alerts are unaffected.
**Operator action (prod is read-only for the agent — run these yourself):**
1. Deploy the latest `main` to prod. ← this alone satisfies Task 1.
2. Run the migration (adds `customer_notification_audience` + seeds the editable settings rows for Settings → Customer Emails):
   ```
   php /var/www/fleetforge/bin/migrate.php --dry-run
   php /var/www/fleetforge/bin/migrate.php --apply
   php /var/www/fleetforge/bin/migrate.php --status   # expect pending: 0
   ```
**Without the migration:** Task 1 still holds (code default), and the settings tab still renders (engine registry fallback), but the audience/suppression table is absent so "Only selected / All-except / do-not-email" degrade to no-op until it runs. Every reminder type is OFF regardless, so nothing sends unexpectedly.

### F65 — Add the customer-reminders cron to the prod crontab 🟢 DEFERRED (no-op until a reminder is enabled)

**Surfaced by:** S-CUSTOMER-NOTIFICATIONS (2026-08-07).
**What:** the scheduled customer reminders (invoice due-soon, overdue/payment, payment receipt, monthly statement, lease-ending, reservation pickup) are dispatched by `cron/customer_reminders.php`, which is NOT yet in the prod crontab. It is safe to add now: every reminder type ships OFF, so it is a no-op until an operator enables one in Settings → Customer Emails. (Compliance customer emails run from the existing `compliance_alerts` cron and need no new crontab line.)
**Operator action (under the `www-data` crontab — matches the other app crons):**
```
sudo -u www-data crontab -e
# add:
0 * * * * php /var/www/fleetforge/cron/customer_reminders.php
```
Runs hourly; the cron only does work at the configured local send-hour on an allowed weekday (Settings → Customer Emails → Sending window). Toggle the whole dispatcher from Settings → Intelligence → Scheduled Jobs ("Customer email reminders").

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

### F33 — S-CLOSE-OVERSHOOT — voiding a drawdown-carrying draft does not restore precharge_balance ✅ CLOSED 2026-07-10 by S-AUDIT-LIFECYCLE-1

**CLOSED:** `ff_reverse_precharge_on_invoice_removal()` (includes/functions.php) now restores the voided/deleted invoice's `mileage_drawdown_credit` sum to `leases.precharge_balance` at ALL five removal sites — `FinancialActions::voidInvoice`, invoices/delete.php, bulk_void.php, bulk_delete.php, AND `adv_void_invoice()` (this finding's original site) — plus un-stamps `precharge_invoiced_at` when the voided invoice carried the `mileage_precharge` charge (the audit found the gap was much wider than this entry's "narrow trigger" framing: EVERY operator void of a drawdown invoice under-refunded). Audit rows `lease_precharge_balance_drawdown_reversal` / `lease_precharge_invoiced_at_unstamp`; hermetic coverage in `tests/_smoke_audit_lifecycle_fixes.php` (S6x/S6y). Pre-fix void residue detector in **F48** step 3. (Original entry below for history.)

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

### F67 — Yards KPI tiles count soft-deleted yards ✅ CLOSED (2026-08-18, commit 8ee2ee3)

**Surfaced by:** S-LIST-TOOLBAR (2026-08-18) while restyling the Yards filter bar — the toolbar's "1 yard" sat directly under tiles reading "TOTAL YARDS 9 / ACTIVE 1 / INACTIVE 8".
**What:** the three server-rendered tiles in `app/admin/yards/index.php` (~lines 44-48) count without the D5 soft-delete filter:
```
$totalYards    = db_count("SELECT COUNT(*) FROM yards");
$activeYards   = db_count("SELECT COUNT(*) FROM yards WHERE is_active = 1");
$inactiveYards = db_count("SELECT COUNT(*) FROM yards WHERE is_active = 0");
```
`api/v1/yards/index.php` (~line 58) correctly excludes them (`WHERE y.deleted_at IS NULL`), so the table below the tiles is right and the tiles are wrong. On the dev DB 8 of 9 yards are soft-deleted.
**Fix:** add `deleted_at IS NULL` to all three counts, then confirm nothing else reads them.
**Closed:** all three `db_count()` calls now carry `deleted_at IS NULL`, with a WHY comment pointing at the API's matching D5 filter. Landed in commit `8ee2ee3` — see S-SWEPT-COMMIT-DISCLOSURE in PROGRESS.md for why that commit's message does not mention it.

---

### F68 — Credit Notes list shows a phantom empty row instead of its empty state ✅ CLOSED (2026-08-18, commit 8ee2ee3)

**Surfaced by:** S-LIST-TOOLBAR (2026-08-18) while restyling the Credit Notes filter bar.
**What:** with zero rows, `app/admin/credit_notes/index.php` renders one table row of "—" placeholders rather than the "No credit notes found" empty state. The tbody's direct children are `[TEMPLATE, TEMPLATE, TEMPLATE, TR]` — that trailing `TR` is the `<template x-for="cn in rows" :key="cn.id">` body sitting in the live DOM, bound with `cn` undefined (its `:href` resolves to `…?id=undefined`). `Alpine.$data` reports `rows.length === 0` and `loading === false`, so the `x-if` empty state should be showing and is not. Suspect keyed-diff breakage when `:key` is undefined.
**Second, probably related:** the page's KPI tiles report "6 active notes / $3,600.00 outstanding" while the list API returns 0 rows — worth checking `api/v1/credit_notes/index.php`'s WHERE clause against the tile queries at the top of the page.
**Confirmed pre-existing:** reproduced by reverting the file to HEAD and reloading; the same phantom row appears. Not caused by the toolbar restyle.
**Closed:** root cause was the API envelope, not Alpine — the page read `data.data` / `data.total` / `data.last_page`, none of which exist, so `rows` was assigned the `{items, pagination}` OBJECT; `rows.length` was undefined (empty state never fired) and `x-for` iterated the object's 2 keys with an undefined `:key`, collapsing them into one dashes row. Now reads `r.data.items` / `r.data.pagination.total` / `.total_pages` behind an `r.success` gate. The "Fully Applied" KPI tile also drilled on a non-existent status `fully_applied`, corrected to `fully_used`. Landed in commit `8ee2ee3` — see S-SWEPT-COMMIT-DISCLOSURE. **This root cause generalised:** the same envelope class was then found on seven QuickBooks consoles and fixed by S-QBO-ENVELOPE-FIX.


## Cross-cutting notes

- **Discipline enforcement:** `tests/_smoke_doc_freshness.php` CLASS 13 (locked 2026-05-29 via S-OPERATOR-FOLLOWUPS-TRACKING) verifies that recent SESSION LOG rows containing the phrase "Operator follow-ups" have matching entries in this doc. Advisory — surfaces orphans without strict-failing because some follow-ups may be re-architected into proper session labels between surfacing and tracking.
- **Memory file:** `memory/feedback_operator_followups_tracking.md` codifies the rule + canonical incident (this file's birth at S-OPERATOR-FOLLOWUPS-TRACKING). Survives context wipes.
- **When operator completes an item:** update the entry's status from 🔴/🟡/🟢 to ✅ + add completion timestamp + verification command output + move to archive at bottom of doc.
- **When a new follow-up surfaces mid-session:** add the entry under the appropriate status bucket BEFORE commit. Include: Surfaced-by (session label + commit ref), Affects (which sessions depend), Operator action (numbered steps), Why-blocking/deferred (rationale).
