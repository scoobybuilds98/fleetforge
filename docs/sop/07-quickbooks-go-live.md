---
title: QuickBooks go-live day
short: Go-live day
summary: The thirteen steps, in order, from production keys to the first proven invoice.
icon: paper-airplane
accent: warning
part: QuickBooks
audience: super_admin, accountant
reviewed: 2026-09-24
---
A Super Admin runs these steps in order, with the accountant available for the matching decisions. Master sync stays off until step 10, so nothing reaches QuickBooks while you set up. Settle the [open decisions](sop:open-decisions) first.

:::diagram golive-phases Six phases, one day. Sync stays off until phase 5.

## Before the day

1. The developer deploys the latest FleetForge and installs the four QuickBooks scheduled jobs on the server. These are the sync worker (every minute), token refresh (02:00), bank mirror (02:30) and drift check (03:30). They are not on the Settings → Scheduled Jobs toggles.
2. In the Intuit Developer site, get the **Production** keys. Add `https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php` as a redirect URI.
3. In QuickBooks itself (the accountant):
   - turn on **Custom transaction numbers**;
   - make sure a payment term exists for every Payment Terms value customers use ("Net 15", "Net 30"…) — each pushed invoice carries it;
   - optional: add a sales-form custom field named **P.O. Number** so PO numbers land in their own field;
   - set **Automatically apply credits** to OFF;
   - decide multicurrency (F82) — it cannot be undone.

## Connect

All on {{QuickBooks › Settings @/quickbooks/settings}}.

4. **API Credentials**:
   - Environment = **Production**;
   - paste the **Client ID**, **Client Secret** and **Webhook Verifier Token**;
   - press **Save Credentials**.
   - Switching environment clears any old connection and turns sync off.
5. **Connection Status** → **Connect to QuickBooks** → sign in and choose the real company. If a red "Sync blocked — different QuickBooks company" alert appears, press **Reset mappings for this company** and type RESET.
6. **Business tagging**:
   - press **Load from QuickBooks**;
   - pick the **Rental Class** and/or **Rental Location**;
   - keep "This QuickBooks company is shared with other businesses" ticked;
   - set **Push transactions dated from** to the first day FleetForge owns (leave empty = the go-live day);
   - press **Save tagging**.

## Map

In this order, which is not the menu order. Each page has **Pull from QuickBooks** then **Auto-Match**. Only exact names link on their own: confirm each **Link suggested** row, use **Link to QBO…** for the rest, and use **Create in QuickBooks** only when QuickBooks truly has no such record.

7. Mapping pages, in order:
   1. {{Accounts @/quickbooks/accounts}} — clear the red "critical account(s) unmapped" banner (AR, AP, undeposited funds, sales revenue, tax payable and receivable, clearing accounts). Also map **Bad Debt Expense**, and the revenue accounts per the F85 decision.
   2. {{Tax Codes @/quickbooks/tax_codes}} — map every FleetForge tax rate. On the **Invoice tax (sales)** card, set mode **per_rate** and choose the **Code for tax-free lines**, then **Save invoice tax**.
   3. {{Items @/quickbooks/items}} — map every FleetForge line type, including **Bad Debt Write-off** (press Pull first so the row appears). Read the yellow income-account warning (F85).
   4. {{Customers @/quickbooks/customers}}.
   5. {{Vendors @/quickbooks/vendors}} — a customer who is also a vendor becomes "ACME (Vendor)" when created.
   6. {{Bank Accounts @/quickbooks/bank_accounts}} — link every QuickBooks bank or card account the accountant pays bills from, or those bill payments wait as drift.

## Link history

On {{QuickBooks › Invoices @/quickbooks/invoices}}, top panel "Go-live: link documents QuickBooks already has".

8. For each document type in turn — **Invoices**, then **Credit notes**, then **Bills**:
   1. Set **From** = the first FleetForge document date, then press **Find matches**.
   2. Press **Select exact + amount/date/unit**, then **Link selected**.
   3. Review the "check" and "same number, other amount" rows one by one. Pick the right QuickBooks document in the dropdown; use **Link anyway** only when the difference is known.
   4. For "no match" rows, widen **Date window ±N days** (up to 45). Use **Push as new** only if QuickBooks really lacks the document.
   5. For invoices, repeat with **Include drafts** ticked. Link drafts QuickBooks already billed; void in FleetForge any draft nobody will send.
9. Press **Check QuickBooks for payments** (top right). QuickBooks payments on the linked invoices and bills now show in FleetForge, with the books updated.

## Switch on

10. {{QuickBooks › Settings › Master Controls @/quickbooks/settings}} (Super Admin only): tick **Master Sync Kill-Switch** and press **Save Master Controls**. Ticked means sync is ON, despite the name. Leave **Dry Run Mode** off. The first switch-on stamps the go-live moment.
11. In the Intuit Developer site, subscribe the webhook to **Payment, BillPayment, Invoice and CreditMemo**, sent to `…/fleetforge/api/v1/webhooks/qbo_payment_notifications.php`.
12. Once QuickBooks Payments is set up on the QuickBooks company, tick **QBO Payments** in {{QuickBooks › Settings › Master Controls @/quickbooks/settings}}. From then on every invoice email, reminder, dunning letter and PDF carries a **Pay now** button — no template change needed.

:::callout warning The switch is named backwards
**Master Sync Kill-Switch** ticked = sync **ON**. Untick it to stop everything.
:::

## Prove it the same day

13. Run these tests (F80):
    - Send one real invoice with GST/PST. In QuickBooks, check the total, the tax on each line and the Class.
    - Pay one invoice through its pay link, and confirm FleetForge shows it paid within minutes.
    - Approve one bill and check it in QuickBooks.
    - Watch **Sync Queue** and **Sync Log** for the rest of the day.
