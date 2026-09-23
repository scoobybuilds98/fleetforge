---
title: Month-end close
short: Month-end close
summary: The shared monthly checklist — documents, ledger, reconciliation, QuickBooks, close — with live checks.
icon: clipboard-document-check
accent: warning
part: Close the month
audience: manager, accountant, super_admin
reviewed: 2026-09-24
---
Close each month within the first ten days of the next. Work top to bottom; each step assumes the ones above it are done. FleetForge steps are done by the office manager or accountant, and QuickBooks steps by the accountant.

The checklist below is **shared by the whole team**: tick a step when it is done, and everyone sees who ticked it and when. Next to each step, FleetForge shows what the books say right now — a ticked step with a red or amber check is the one to look at again.

:::checklist month_end
## A | Finish the month's documents | Office
- {leases_closed} Every lease that ended in the month is **closed** with its final reading. @/leases
- {invoices_sent} Every draft invoice for the month is **reviewed and sent**, or voided if not needed. Drafts are not revenue and are not pushed to QuickBooks. @/invoices
- {payments_recorded} Every customer payment received in the month is **recorded**, with the right date and method. @/payments
- {bills_approved} Every supplier invoice received is entered as a bill, and **every draft bill is approved or voided**. @/accounting/bills
- {damage_claims} Damage claims settled in the month are updated; claims that will not be paid are **Written off**. @/damage_claims
## B | Ledger housekeeping | Accountant
- {je_drafts} Journal Entries → **Drafts & Pending** is empty: post or delete each draft. @/accounting/journal-entries
- {recurring_current} Recurring JEs: no template shows **Overdue**. If one does, use **Catch up now**. @/accounting/recurring-entries
- {depreciation_posted} Depreciation for the month is **Generated, checked and Posted**. @/accounting/depreciation
- {asset_entries} Every asset bought this month is on the Asset Register (adding it posts the purchase), disposals are recorded, and loan payments have their journal entries. @/accounting/fixed-assets
- {bank_moves} Payments and deposits recorded this month show the bank they really went into (**Deposited to**); CAD ↔ USD moves were entered as **Transfers** with both amounts. @/payments
- {itc_cleared} Every GST/HST period filed and paid (or refunded) this month is marked with **Remit** — that clears 2030 and 1050 for the period. @/accounting/tax
## C | Reconcile | Accountant
- {bank_reconciled} **Bank reconciliation** for every bank account, Difference **0.00**, then **Complete**. @/accounting/bank-reconciliation
- {ar_reconciled} **AR Aging → Check Reconciliation**: aging total equals account 1030. @/accounting/ar-aging
- {ap_reconciled} **AP Aging → Check Reconciliation**: aging total equals account 2010. Draft bills cause a false difference, which is why step A matters. @/accounting/ap-aging
- {trial_balance} **Trial Balance** says **Balanced**. @/accounting/reports/trial-balance
- {statements_clean} Balance Sheet and Cash Flow show **no warning banner**. @/accounting/reports/balance-sheet
- {reviewed} Review the P&L and Balance Sheet against last month and budget. Explain anything unusual before closing. @/accounting/reports/profit-loss
## D | QuickBooks | Accountant · after go-live
- {qbo_queue} QuickBooks → **Sync Queue**: no failed items. Retry or fix every failed item ([Problems & fixes](sop:quickbooks-problems)). @/quickbooks/sync_queue
- {qbo_drift} **Drift** page: no open drift events. Resolve each one ([Problems & fixes](sop:quickbooks-problems)). @/quickbooks/drift
- {qbo_bill_payments} Bill payments made in QuickBooks this month appear in Payables → Payments badged **From QuickBooks**. @/accounting/ap-payments
- {qbo_totals} **Compare totals**: FleetForge's AR (1030) and QuickBooks' A/R for this business's Class/Location agree; the same for AP. Compare revenue on both P&Ls, filtered to this business. @/accounting/reports/trial-balance
- {qbo_items} QuickBooks → **Items**: no item shows **Different** (until F85 is decided, rental revenue shows as fallback — [Open decisions](sop:open-decisions)). @/quickbooks/items
- {qbo_closed} Close the month in QuickBooks too, with the closing date set in the accountant's QuickBooks settings. This is done by the accountant, not FleetForge.
## E | Close the period | Accountant
- {period_closed} Accounting → **Periods**: find the month and press **Close Period** (December too). Revalue USD balances first if you use FX revaluation. @/accounting/periods
:::

## Closing the period

- **Close Period** ({{Accounting › Periods @/accounting/periods}}): new entries dated in a closed month either move to the next open month (invoices, payments, credits, write-offs) or are refused (everything else).
- **FX revaluation** (if used) goes in **before** the month is closed: it is dated the month's last day.
- **Reopen** (Super Admin, reason required): a closed month goes back to open to post a late correction; close it again afterwards. The reason is kept on the period and in the audit log.
- **Lock** a period only after the accountant has signed off the month (Super Admin). A locked month takes no entries at all. **Unlock** (Super Admin, with a reason) returns it to closed. A year that has been through the year-end close can't be reopened or unlocked month by month — reverse the year-end first.

## Year-end

Once a year, after December's month-end.

1. Do the whole monthly checklist for December. Closing December is fine; **don't Lock it**.
2. {{Accounting › Year-End @/accounting/year-end}}: work through the checklist.
3. Run the **pre-flight checks**. Every one must pass: all 12 months exist (December not locked), AR and AP tie out, no draft entries, checklist complete.
4. Press **Start Year-End Close for YYYY**. FleetForge:
   - closes revenue and expense accounts to 3020 Retained Earnings, in an entry dated December 31 (even with December closed);
   - locks all 12 periods;
   - creates next year's periods;
   - produces a **ZIP package** (trial balance, financial statements, ledger) for the accountant's year-end file.
5. Download and keep the ZIP. Give a copy to the external accountant with the {{CCA Schedule 8 @/accounting/cca}} report for the tax return.

:::callout warning Undoing a year-end close
A Super Admin can reverse it on the Year-End page (with a reason). The closing entry is reversed on December 31 and the 12 months go back to Closed, so a correction can be made (Reopen the month), then the year closed again.
:::
