---
title: QuickBooks routine — daily, weekly, monthly
short: QuickBooks routine
summary: Ten minutes a day keeps both systems in step. Plus who can press what.
icon: calendar-days
accent: info
part: QuickBooks
audience: super_admin, accountant
reviewed: 2026-09-24
---
Ten minutes a day keeps both systems in step: look at the Dashboard, clear anything failed or blocked, and make sure yesterday's payments arrived. Everything lives under the **QuickBooks** menu.

:::cards
### :clock: Daily · 10 minutes
Dashboard, Sync Queue failures, blocked documents, missing payments.
### :calendar: Weekly
Drift events, write-offs, the Items warning, bank-account mappings.
### :calendar-days: Monthly
Connection token, queue clean-up, and the [month-end close](sop:month-end-close).
:::

## Daily

1. {{QuickBooks › Dashboard @/quickbooks/dashboard}}: the connection badge must read *connected*. The four cards should read low:
   - **Sync Queue** (queued, processing, failed);
   - **Unresolved Drift**;
   - **Last 24h Activity** (errors);
   - **Master Sync** (must be ON).
2. {{Sync Queue @/quickbooks/sync_queue}} (the filter opens on *Queued*; switch it to *Failed*). A failed row shows its reason on hover. Fix the cause ([Problems & fixes](sop:quickbooks-problems)), then press **Retry** on the row, or tick several and press **Retry Selected**.
3. Open {{Invoices @/quickbooks/invoices}}, {{Bills @/quickbooks/bills}}, {{Credit Memos @/quickbooks/credit_memos}}, {{Payments @/quickbooks/payments}} and {{Bill Payments @/quickbooks/bill_payments}}. Look at the **Failed** and **Pre-flight Block** tiles. Each blocked row names what is missing. Fix it, then press the row's **Retry**.
4. Payments arrive by themselves (webhook, plus a catch-up every 10 minutes). If a customer says they paid and FleetForge still shows the invoice open after 10 minutes, press **Check QuickBooks for payments** on **Invoices**. It checks every open invoice and bill against QuickBooks.

## Weekly

1. {{Drift @/quickbooks/drift}} (the default view shows open events). Work each one:
   - **Resolve** — you fixed it (for example, made the same change in QuickBooks). It re-opens if it happens again.
   - **Re-sync** — for a failed push: queue the record again.
   - **Accept** — the difference is intentional. Final: it will never be raised again.
   - **Suppress** — a known false alarm. Final, and hidden from the default view.
   - Every choice needs a note of at least 5 characters. Use **Bulk resolve by category** only for a batch you have checked.
2. {{Credit Memos @/quickbooks/credit_memos}} → **Invoice write-offs**: every row should read *In QuickBooks*. Press **Send** or **Retry** on the others after fixing the reason shown.
3. {{Items @/quickbooks/items}}: the yellow "Income accounts don't line up" warning should shrink as F85 is carried out.
4. {{Bank Accounts @/quickbooks/bank_accounts}} → **Verify mappings**: a *conflict* status means someone renamed or deactivated the account in QuickBooks.

## Monthly

1. {{Settings › Connection Status @/quickbooks/settings}}: the refresh token lasts about 100 days. A warning appears 14 days before expiry, and the nightly job refreshes it on its own. If it shows *expired*, press **Connect to QuickBooks** again.
2. **Sync Queue**: press **Clear Completed (>7d)** and **Clear Failed (>30d)** to keep the list short.
3. The [month-end close](sop:month-end-close) steps.

## Who can press what

Permissions are set on {{Users › Role permissions @/users/role_permissions}} under QuickBooks.

| Action | Permission |
| --- | --- |
| See every QuickBooks page; mapping actions; **Resolve** drift | view |
| **Retry** on the Invoices / Bills / Credit Memos / Payments pages; **Accept**, **Suppress**, **Re-sync** drift; **Run drift check now**; **Create QBO Item**; save tax cards and tagging | edit_credentials |
| **Retry** and **Retry Selected** on Sync Queue | force_resync |
| **Clear Completed**, **Clear Failed**, **Delete Selected** | clear_queue |
| Go-live linking panel, **Check QuickBooks for payments**, **Push as new**, Manual Sync | force_full_resync |
| **Disconnect** | disconnect |
| Full request / response data in Sync Log | view_raw_payloads |
| **Master Controls**, **Reset mappings** | Super Admin only |

:::callout warning View is more than viewing
Mapping actions need only *view*, so anyone who can see the QuickBooks pages can change a mapping. Grant *view* only to people who should.
:::
