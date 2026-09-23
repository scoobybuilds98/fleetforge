---
title: Accounting in FleetForge
short: Accounting
summary: Setup, what posts itself, journal entries, bills, banking, assets, GST/PST, write-offs and reports.
icon: calculator
accent: info
part: Accounting
audience: accountant, super_admin
reviewed: 2026-09-24
---
FleetForge keeps a complete general ledger in CAD, and most of it writes itself: sending an invoice, recording a payment, approving a bill and posting depreciation each post their own journal entry. The accountant's job is to keep the settings complete, enter what is not automatic, reconcile, and close each month. Everything is under **Accounting** in the sidebar; the grey menu bar at the top of each accounting page has the same groups.

:::diagram accounting-cycle The accounting month: documents post themselves, the accountant adds the rest, reconciles, reports and closes.

:::callout danger Never untick Enable Accounting Module
{{Accounting › Settings › General @/accounting/settings}}. Billing carries on, but every automatic entry silently stops and the ledger drifts from the invoices.
:::

## Setup

All under {{Accounting › Settings @/accounting/settings}}.

1. **GL Account Mapping** tab. Every field must hold an account, then press **Save GL Mappings**. A blank field makes the matching action fail with "accounting configuration incomplete".
2. **Revenue Mapping** tab: where each kind of invoice line posts. Rental posts per equipment category (Chassis → 4010, Dry Van → 4020, Reefer → 4030, Flatbed → 4040, anything else → 4050) unless **Rental — every category** is set; mileage lines post to 4060; anything without its own account goes to **Everything not mapped above** (4110). Changes apply to invoices sent from then on. Compare with QuickBooks item accounts on {{QuickBooks › Items @/quickbooks/items}} (F85).
3. **Depreciation** tab: **Samsara Daily Unit Cost (CAD)**. While it is 0.00, GPS revenue posts gross even for customers set to Net. The Default Method, Life and Salvage fields are not used by the asset form.
4. **Tax Filing** tab: GST and PST filing frequency.
5. **Chart of Accounts** ({{General Ledger › Chart of Accounts @/accounting/chart-of-accounts}}): add only accounts the accountant has agreed. Reports and the QuickBooks mapping depend on this list.
   - A header account cannot receive postings.
   - An account with a balance cannot be deactivated until the balance is moved.
   - Types: Asset, Liability, Equity, Revenue, Cost of Revenue, Operating Expense, Other Income, Other Expense.
6. **Periods** ({{Accounting › Periods @/accounting/periods}}): months are created automatically, 12 months ahead. If a posting says "No open accounting period", ask IT to run the period job.
7. **FX & Other** tab: turn on FX revaluation and pick its rate source (Bank of Canada, or a manual rate), and the Damage Recovery revenue account. The **Tax Filing** tab also holds the CRA business number and the GST Quick Method (only if the CRA approved it).

The GL Account Mapping fields:

| Field | Account |
| --- | --- |
| Accounts Receivable (AR) | 1030 |
| Accounts Payable (AP) | 2010 |
| Cash / Bank Account | 1010 — used when a payment or deposit names no bank account |
| GST Payable / PST Payable | 2030 / 2040 |
| GST Receivable (ITC) | 1050 |
| Bad Debt Expense | 6160 — needed for write-offs |
| FX Gain / FX Loss | 7030 / 7040 |
| Retained Earnings | 3020 |
| Customer Deposits | 2050 |
| Customer Credits | 2060 — needed for credit notes and overpayments |
| Opening Balance Equity | 3050 — the other side of bank opening balances |

## What posts automatically

Each card is one automatic journal entry: what happens, the debit side, the credit side.

:::entries
Invoice sent | AR 1030 | Revenue per line type + GST Payable 2030 + PST Payable 2040 | Dated the invoice date
GPS line, customer on Net | — | 4120 GPS Net (the margin) + 1055 Samsara Recoverable (the cost) | Instead of revenue; the AR side is the invoice's
Invoice voided | — | — | Reverses the invoice's entry, dated today
Customer payment received | The bank it was deposited to (default 1010) | AR 1030 | Any FX difference to 7030 / 7040
Overpayment | The bank it was deposited to (default 1010) | AR 1030 + 2060 Customer Credits (the excess) |
Credit note issued | Revenue | 2060 Customer Credits |
Credit note applied | 2060 Customer Credits | AR 1030 |
Bill approved | Each line's account + GST Receivable 1050 | AP 2010 | All PST goes to the first line
Bill payment | AP 2010 | The chosen bank account | In FleetForge or copied from QuickBooks
Vendor credit created | AP 2010 | The expense account you pick | Applying it to a bill posts nothing more
Invoice written off | Bad Debt Expense 6160 | AR 1030 | From the invoice's Write Off button or a damage claim
Money recovered after a write-off | The bank | Bad Debt Expense 6160 |
Credit refunded in cash | 2060 Customer Credits | The bank | Not sent to QuickBooks
Asset added | The asset's cost account | Where it was paid from | Nothing for an opening-balance asset; a bill-bought asset moves the bill's cost
Betterment | The asset's cost account | The bill line's account, or the account paid from |
Bank opening balance | The bank account | 3050 Opening Balance Equity | Not sent to QuickBooks
Bank transfer | The receiving bank (its CAD value) | The sending bank (its CAD value) | A USD side carries its USD amount; a rate spread goes to 7030 / 7040
Depreciation run posted | Depreciation expense | Accumulated depreciation | Per asset account
Asset disposed | Cash + accumulated depreciation + loss | Asset cost + gain | Loss or gain, whichever applies
Asset impaired | 7020 | Accumulated depreciation |
GST/HST remitted | 2030 (the period's GST collected) | 1050 (its input tax credits) + the bank (the net) | A refund period debits the bank instead
PST remitted | 2040 | The bank |
Customer deposit received | The bank it went into (default 1010) | 2050 Customer Deposits |
Customer deposit applied | 2050 Customer Deposits | AR 1030 |
NSF processed | — | — | The payment is reversed; the fee goes to 6170 Bank Charges
:::

:::callout rule Two rules for automatic entries
- **If the month is closed**, invoice, payment, credit and write-off entries move to the next open month. Everything else is refused until you post into an open month (a Super Admin can **Reopen** a closed month on Accounting → Periods, with a reason).
- **Automatic entries are undone from their document.** The Journal Entries page shows them as *Automatic* with no Reverse button: void or credit the invoice, void the payment or bill, and so on — that reverses the entry and updates the document together.
:::

## Journal entries

From {{General Ledger › Journal Entries @/accounting/journal-entries}}:

1. **+ New Journal Entry**.
2. **Entry Date** decides the month, which must be open.
3. **Entry Type**:
   - **Manual** for ordinary entries;
   - **Adjusting**, **Reclassifying** or **Prior Period** for audit adjustments, which always start as drafts.
4. **Description** and **Reference**, then the lines (2–50 lines, each a debit or a credit, no header accounts).
5. Tick **Post immediately** only when you are sure. Otherwise it saves as a draft.
6. **Save Journal Entry** (enabled only when **Balanced**).

Then:

- Drafts don't count anywhere. Clear the **Drafts & Pending** tab before month-end.
- A posted entry is never edited: **Reverse** it (dated today) and enter the right one.
- Lines cannot be tagged with a customer, vendor or unit from this screen.

### Recurring entries

From {{Accounting › Recurring JEs › New Template @/accounting/recurring-entries/create}}:

1. Name, Frequency (Monthly, Quarterly, Annually), Day of Month, Start and End Date.
2. Tick **Post automatically**, or leave it unticked to get drafts to review.
3. Add the lines, then **Create Template**.

The job runs daily at 03:00. An **Overdue** badge means the month was closed or the job did not run; use **Catch up now**. Templates with history can be paused, not deleted.

## Bills and bill payments

### Enter a bill

From {{Payables › Bills @/accounting/bills}} → **+ New Bill**:

1. Vendor, Bill Date, **Due Date** (not calculated from terms), **Vendor Invoice #** (21 characters at most; duplicates per vendor refused).
2. Optionally link the vendor's **Work Order**. The bill's amount then replaces the work order's cost in the vendor's total.
3. Lines: GL Account, Description, Qty, Unit Cost, and the GST and PST exactly as printed on the supplier's invoice. **Auto-Categorize** fills accounts from the rules.
4. Press **Save & Approve** to post now, or **Save as Draft**. A draft is approved later with the **✓** button.

### Pay a bill in FleetForge

Only if the accountant is not paying it in QuickBooks ([golden rules](sop:golden-rules)):

1. Press the **$** button on an approved or partially paid bill.
2. Enter Amount, Payment Date, Method, **Bank Account**, and the Check Number for cheques.
3. Press **Record Payment**.

Bills the accountant pays in QuickBooks appear on {{Payables › Payments @/accounting/ap-payments}} badged **From QuickBooks**.

### Vendor credits

From {{Payables › Vendor Credits @/accounting/vendor-credits}} → **+ New Credit**:

- **Choose the Expense Account** the original bill was charged to (required).
- Apply it later with **Apply to Bill**. Deleting an unapplied credit reverses its entry.

:::callout warning Bill rules
- **Void** (the ✕ on a draft or approved bill) reverses its entry. A bill with payments cannot be voided.
- **AP Aging** counts draft bills, which have no ledger entry. Approve or void every draft before month-end, or **Check Reconciliation** shows a difference.
- Pick the **Unit** (or a work order, which brings its unit) so the bill counts as that trailer's or truck's cost on the Per-Unit P&L.
:::

## Receivables

- **AR Aging** ({{Receivables › AR Aging @/accounting/ar-aging}}) lists sent, unpaid invoices by age.
  - **Check Reconciliation** compares the aging total with account 1030 in the ledger. A difference means an entry was missed or posted by hand: find it before month-end.
  - Written-off, void and draft invoices are not receivables and never appear.
- **Statements** ({{Receivables › Statements @/accounting/statements}}): pick the customer and date range, then email or print. Use them for any customer who disputes a balance.
- **Collections** ({{Receivables › Collections @/accounting/collections}}): the follow-up list for overdue accounts. Log every call or email there, so the next person sees the history.
- **Deposits** ({{Receivables › Deposits @/accounting/deposits}}) — money received before an invoice exists:
  1. **+ New Deposit**: customer, amount, date, type, **Deposited to**. This posts DR that bank / CR 2050 Customer Deposits. A refund comes out of the same bank.
  2. When the invoice is sent, press **Apply** on the deposit and pick the invoice (the customer's open invoices are listed; one with a balance below the deposit, or in another currency, can't be picked).
- **Customer credit** from an overpayment or credit note sits in 2060 until it is applied to an invoice.

## Banking and reconciliation

### Bank accounts

From {{Banking › Bank Accounts @/accounting/bank-accounts}}:

- Each one is linked to a ledger cash account.
- **Setting up an account**: enter the **Opening Balance** from the statement and its date (the day before FleetForge's first transaction). It posts DR the bank / CR 3050 Opening Balance Equity; changing it later re-posts it. Once a reconciliation is completed it can't change. A USD account needs a USD→CAD rate on file for that date. When every opening balance is in, the accountant moves 3050 to Retained Earnings.
- Customer payments and deposits post to the bank chosen in **Deposited to** (blank = the currency's default bank, then the Settings Cash / Bank Account). Payments copied from QuickBooks use the bank linked to QuickBooks' deposit account.

### Transactions

From {{Banking › Transactions @/accounting/bank-transactions}}:

- Import the bank's CSV, or enter lines by hand.
- Match each line to the payment, bill payment or journal entry it belongs to.
- **Transfer** between two accounts: same currency → one amount. CAD ↔ USD → enter the amount sent **and** the amount received; FleetForge books the USD side at its CAD value (the rate the two amounts imply, or the day's rate if you enter one — then the bank's spread goes to FX gain/loss).

### Reconciliation

From {{Banking › Reconciliation @/accounting/bank-reconciliation}}, once a month per bank account:

1. **+ New Reconciliation**: bank account, **statement end date**, **statement ending balance** from the bank statement.
2. Tick every transaction that appears on the statement. Leave items that have not cleared unticked.
3. The page works out **Difference = Statement − (Beginning + Cleared Deposits − Cleared Withdrawals)**. Beginning is last month's reconciled statement balance (or the opening balance). It must read **0.00**.
4. Press **Complete**. A completed reconciliation is locked.

Under the cards, the **book check** compares the ledger with the statement (less deposits in transit, plus uncleared cheques). A difference there is information: it usually means a receipt or payment posted in FleetForge has no bank line.

## Fixed assets and depreciation

**Every trailer and truck is an asset.**

1. **Add the asset** ({{Fixed Assets › Asset Register @/accounting/fixed-assets}} → **+ New Asset**) with cost, in-service date, depreciation method, useful life and salvage value. Link it to the equipment unit.
2. **How was it paid for?** (required): *Paid from an account* (bank, loan… → posts DR the asset account / CR that account); *Bought on a supplier bill* (pick the approved bill — its cost already posted, and any line not coded to the asset account is moved into it); or *Owned before FleetForge* (opening balance — posts nothing). Approving a CapEx request posts nothing; completing it with a new asset asks where it was paid from.
3. Historical assets imported at go-live are **opening balance** assets, so no purchase is posted for them.

### Depreciation, every month

1. {{Fixed Assets › Depreciation @/accounting/depreciation}}, choose the month.
2. **Generate Preview**, then check the list: each asset once, nothing sold still depreciating.
3. **Post** while the month is still open. Posting into a closed month is refused.

### Other asset events

- **Disposal** (sold or scrapped): open the asset, **Dispose**, enter date and proceeds. FleetForge removes the cost and accumulated depreciation and posts the gain or loss.
- **Impairment Tests** ({{Fixed Assets › Impairment Tests @/accounting/impairment}}): record a write-down the accountant has decided. It posts DR 7020 / CR accumulated depreciation.
- **CCA Schedule 8** ({{Fixed Assets › CCA Schedule 8 @/accounting/cca}}) is the tax-depreciation (capital cost allowance) schedule for the corporate tax return. It does not post anything.
- **Payoff Report** ({{Fixed Assets › Payoff Report @/accounting/fixed-assets/payoff-report}}) shows loan payoff amounts on financed units.
- **CapEx Requests** ({{Fixed Assets › CapEx Requests @/accounting/capex}}) are approvals. **Complete** with a new asset posts its purchase from the account you choose. **Capitalize** a flagged work order moves its approved bills' costs into the asset.
- **Betterments** (from a bill line on the bill's page): the line's cost moves into the asset. On a draft bill it happens when the bill is approved.

## GST/HST and PST

1. {{Tax › GST/HST Filing @/accounting/tax}}: choose the period. The page adds up GST collected on sent invoices (2030) and GST paid on approved bills (1050, input tax credits).
2. Compare it with the ledger balances of 2030 and 1050 for the same dates. File with the CRA using these numbers.
3. When paid, press **Remit** on the filed period: date, amount (must equal the return's net tax), method, bank. This posts DR 2030 (the period's GST collected) / CR 1050 (its input tax credits) / CR the bank (the net) — both accounts are cleared for the period.
4. **Refund periods** (more ITC than GST collected): the same **Remit** button records the refund received: DR 2030 / DR the bank / CR 1050. A nil return is recorded with amount 0.
5. Interest or penalties are separate journal entries.
6. PST follows the same steps with 2040, and PST has no input credit.

## Write-offs and bad debt

- **Damage claims**: when a claim's invoice will never be paid, change the claim to **Written off**. FleetForge posts DR Bad Debt Expense / CR AR, closes the invoice, and sends a credit memo to QuickBooks.
- **Any other invoice**: **Write Off** on the invoice (reason required). Money received later: **Record Recovery** on the invoice (DR the bank / CR Bad Debt Expense; sent to QuickBooks). Never use a credit note for bad debt — it reduces revenue instead.
- A written-off invoice is excluded from revenue and from AR everywhere.
- **Damage repairs**: approve the repair shop's bill as usual — that is where the repair cost posts. The claim's repair cost is a reference figure only, so the cost is never counted twice.

## Foreign currency

- **Every report is in CAD.** A USD invoice is converted at the rate frozen on the invoice when it is sent.
- When the customer pays, the difference between the invoice rate and the payment rate posts automatically to 7030 FX Gain or 7040 FX Loss.
- **FX Revaluation** ({{Accounting › FX Revaluation @/accounting/fx-revaluations}}; month-end revaluation of open USD balances): turn it on under Settings → FX & Other. Revalue a month **after it ends and before it is closed** — the entry is dated the month's last day and reverses itself on the 1st of the next month. The monthly job does this on the 1st when the month is still open.

## Reports

| Report | Use it for |
| --- | --- |
| {{Trial Balance @/accounting/reports/trial-balance}} | First check at month-end. Must say **Balanced**. |
| {{Profit & Loss @/accounting/reports/profit-loss}} | Monthly result. Compare with QuickBooks' P&L after go-live. |
| {{Balance Sheet @/accounting/reports/balance-sheet}} | Assets = liabilities + equity. A red banner means something is out: stop and investigate. |
| {{Cash Flow @/accounting/reports/cash-flow}} | Built from the ledger. It always ties: a tie difference other than zero is a bug, report it to IT. |
| {{General Ledger @/accounting/ledger}} | Every posting to one account, with a link to its source document. |
| {{AR Aging @/accounting/ar-aging}} / {{AP Aging @/accounting/ap-aging}} | Who owes us, and whom we owe. Use **Check Reconciliation**. |
| {{Per-Unit P&L @/accounting/reports/per-unit-pnl}} | Revenue and direct costs by trailer or truck. Costs count when the bill names the unit (or its work order). |
| {{Budgets @/accounting/budgets}} | Budget vs actual, once a budget is entered under Accounting → Budgets. |

Revenue in every report excludes draft, void and written-off invoices. A month with invoices still in draft therefore shows low revenue: send them.
