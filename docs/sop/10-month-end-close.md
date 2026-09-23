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
- {asset_entries} Asset purchases, disposals and loan payments have their journal entries (asset creation posts nothing by itself). @/accounting/fixed-assets
- {bank_moves} Any money deposited to a bank other than the default Cash / Bank account is moved with a journal entry. @/accounting/journal-entries
- {itc_cleared} GST ITC clearing entry (DR 2030 / CR 1050) posted for any period filed this month. @/accounting/tax
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
- {period_closed} Accounting → **Periods**: find the month and press **Close Period** — never for December (see below). @/accounting/periods
:::

## Closing the period

:::callout danger Never close December from the Periods page
December is closed by the year-end process below, which needs it open.
:::

- **Close Period** ({{Accounting › Periods @/accounting/periods}}): new entries dated in a closed month either move to the next open month (invoices, payments, credits, write-offs) or are refused (everything else).
- **Lock** a period only after the accountant has signed off the month (super admin only). There is no reopen button: a mistake in a closed or locked month is fixed by a correcting entry in the current month.

## Year-end

Once a year, after November is closed.

1. Do the whole monthly checklist for December, but **do not close December**.
2. {{Accounting › Year-End @/accounting/year-end}}: work through the checklist. When it offers **Close all periods**, tick it only for January–November (all already closed if you followed this SOP).
3. Run the **pre-flight checks**. Every one must pass: balanced trial balance, no draft entries, depreciation posted for all 12 months, reconciliations complete.
4. Press **Start Year-End Close for YYYY**. FleetForge:
   - closes revenue and expense accounts to 3020 Retained Earnings;
   - locks all 12 periods;
   - creates next year's periods;
   - produces a **ZIP package** (trial balance, financial statements, ledger) for the accountant's year-end file.
5. Download and keep the ZIP. Give a copy to the external accountant with the {{CCA Schedule 8 @/accounting/cca}} report for the tax return.

:::callout warning If the year-end close refuses to start
If it refuses because December is already closed, stop and call IT. Do not try to post around it ([Known issues](sop:known-issues)).
:::
