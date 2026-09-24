---
title: Billing — invoices, credits, payments, collections
short: Billing
summary: How invoices are priced, month-end batch billing, sending, credits, payments and collections.
icon: document-text
accent: primary
part: Operations & billing
audience: manager, accountant
reviewed: 2026-09-24
---
Invoices are made on purpose, never automatically: the monthly invoice job is switched off, so each month's invoices come from the Monthly Billing workbench or a lease's Generate Invoice. A draft changes nothing. **Sending** is the moment the invoice becomes revenue, is added to the customer's balance, and goes to QuickBooks.

:::diagram invoice-lifecycle An invoice's life. Only Send touches the books; a sent invoice is corrected with a credit note, never edited.

## How an invoice is priced

Each invoice re-prices the whole lease, from its start to the end of this period, and charges the difference from what was already billed. A negative difference shows as a **Rental Reconciliation Credit** line.

:::diagram pricing-ladder The rate ladder: the longer the lease has run, the cheaper the effective day rate.

| Lease length so far | Rental charged |
| --- | --- |
| Up to 7 days | The cheaper of days × daily rate or the weekly rate |
| 8 days up to the monthly rate | Weekly math: full weeks × weekly, plus leftover days × weekly ÷ 7, capped at monthly |
| One calendar month or less, reaching the monthly tier | The flat monthly rate, even across a month boundary |
| Longer | Each complete calendar month at the monthly rate; partial months at days × monthly ÷ 30 |
| Below the category minimum (when switched on) | Minimum days × daily rate |

**Mileage and engine hours.** Each invoice bills the daily estimate. When an actual reading exists, a **Mileage true-up** line settles actual against everything billed so far:

- Samsara leases get a reading every period.
- Manual leases get one at close, or when you enter an odometer.
- Engine hours true up at close.

## Month-end billing

Each month is a **billing cycle**: {{Billing › Monthly Billing @/billing}}. The **Open billing cycles** scheduled job opens last month's cycle on the 1st and tells the billing owner; **Start the … cycle** does the same by hand. On the cycle page, work the steps left to right (click a step to open its tab):

1. **Prepare**: the **Readiness** tab. Fix every blocker (no rate, no US-dollar rate). Fix or **Acknowledge** each warning — earlier months never billed, missing readings, Samsara units not reporting, leases past their end date, customers with no email or a bounced one, missing PO numbers, expired tax exemptions, a closed ledger month, older drafts.
2. **Readings**: enter the month-end odometer (and engine hours) for every Manual-mileage and hourly lease, then **Save readings**. Generation passes them to the invoice.
3. **Generate**: **Open workbench**. **Preview totals** (nothing is saved), **Hold for review** anything wrong, then **Looks right — Generate N**. Leases on a billing hold are never pre-selected. The **Leases** tab shows what is still **To bill**; a **Closed, unbilled** lease uses its own **Generate Invoice**.
4. **Review**: the **Review** tab. Clear every red flag (double billing, double mileage), look at the amber ones (big change against last month, $0, no tax, no recipient), then **Mark reviewed**. **Query** anything that needs a second look.
5. **Approve** (only when "Require approval before batch billing" is on in {{Billing › Settings @/billing/settings}}): **Submit for approval** from the workbench; a manager approves on {{Billing › Approvals @/billing#approvals}}, then **Generate**.
6. **Send**: the **Delivery** tab. Tick the drafts → **Send & email N draft(s)**. Invoices already sent without an email → **Email N sent invoice(s)**. Print and post the **Print & mail** ones.
7. **Close**: the **Close** tab → **Close and lock**. It needs no drafts, no pending runs and no queried invoices; leases still to bill or open exceptions need **Close anyway** and a note. The month's figures are frozen and the workbench can no longer bill it (a lease's own Generate Invoice still can). **Download billing register (CSV)** for the accountant.

:::callout info What the workbench does not cover
The workbench bills active monthly leases only. A completed lease with an unbilled month uses **Generate Invoice** on the lease; a lease set to bill at close bills when it closes. Both still show on the cycle.
:::

**Billing holds** ({{Billing › Holds @/billing#holds}}): hold a lease or a whole customer (dispute, paperwork) — it is left out of the workbench and the monthly job until released. **Billing exceptions** (leases a run could not bill) are on {{Billing › Exceptions @/billing#exceptions}}: **Fixed**, **Ignore** with a note, or **Hold**.

**Draft backlog**: {{Billing › Cycles @/billing#cycles}} lists months with unsent drafts and no cycle. Open the oldest, send or void its drafts, close it, move on.

## One lease

From the lease → **Generate Invoice**:

1. Pick the month marked **Next to bill** (months must be billed in order).
2. For a Manual-mileage lease, enter **Odometer at Period End**.
3. Press **Generate &lt;Month&gt;** (or **Generate all due**).

## Review, send, void

- **Edit a draft**: **Edit Line Items** (add a line, mark tax or credit), or **Regenerate from Lease** after fixing the lease. The number is kept.
- **Send**: **Send Invoice**. It sets Sent, adds the balance to the customer, posts the revenue entry and queues QuickBooks. **It does not email.** Email with **Email Invoice**; check the To field, which defaults to the main email, not the Invoice Email.
- **Sent invoices are frozen.** Only PO number, notes and delivery details can change. Correct a sent invoice with a credit note.
- **Void**: **Void** → reason → **Void Invoice** (draft or sent only). This reverses the balance and revenue and makes the month billable again. Paid, partially paid and overdue invoices cannot be voided.
- **Customers pay online with the Pay now button.** While QuickBooks Payments is on ({{QuickBooks › Settings › Master Controls @/quickbooks/settings}}), every email about an unpaid invoice gets a **Pay now** button above the signature — Email Invoice, batch Send & Email, the due-soon and overdue reminders — as do dunning letters, and the invoice PDF gets the button plus a QR code. It opens the invoice's QuickBooks payment page (card or bank transfer). Nothing to add to the templates; the compose window says when the button will be added.
- **Copy pay link** on the invoice page copies the same link, to text or paste into a chat.

:::callout warning Billing rules
- Invoice date = period start; due = invoice date + the customer's Payment Terms (30 days when blank).
- Billing a month before it ends bills the whole month ahead.
- Drafts count for nothing in reports or receivables.
- A precharge can be billed on one invoice only.
- Before sending a close invoice, check it does not carry both "Mileage usage" and "Mileage overage" lines for the same distance.
- Send a large draft backlog in stages, not all at once.
:::

## Credit notes

From {{Invoices › Credit Notes › New Credit Note @/credit_notes/create}}:

1. Customer, **Source Type** (Goodwill, Invoice Adjustment, Damage Resolution, Mileage Overpayment, Payment Returned, Other), amount, currency, **Reason**. The reason appears on statements.
2. **Issue Credit Note**. It changes no invoice until applied.
3. On the credit note: **Apply to Invoice** → pick the invoice → **Max** or an amount → **Apply Credit**. **Un-apply** reverses it.

FleetForge also makes credits itself: overpayments, precharge credits at close, and rental reconciliation overflow. Overpayment credits never go to QuickBooks as credit memos, because the QuickBooks payment already holds the excess.

**Apply credits in FleetForge.** FleetForge sends each application to QuickBooks; one applied inside QuickBooks is not copied back (it raises a drift item). The setting is **Credit applications** under {{QuickBooks › Settings › Sync & monitoring @/quickbooks/settings}}.

**Paying a credit back in cash**: on the credit note, **Refund as Cash** → amount, date, how it was paid, reference, the bank it was paid from → **Record Refund**. It posts DR 2060 Customer Credits / CR the bank and reduces the credit. It is not sent to QuickBooks: the accountant records the same refund against the credit memo there. A credit note that has been partly refunded can no longer be voided.

## Payments

:::callout rule After QuickBooks go-live, customer payments are recorded in QuickBooks
Pay now payments, portal payments and the accountant's deposits all arrive in FleetForge on their own — usually within a minute, and within 10 minutes even if QuickBooks' notification is lost. The invoice turns Paid and the ledger entry posts by itself. Record a payment in FleetForge only for money QuickBooks will never see.
:::

If you do record one here: {{Payments › Record Payment @/payments/create}} → pick the invoice → amount (or **Pay full balance**) → method → date → reference → **Deposited to** (the bank account it went into; blank = the default for that currency) → **Record Payment**. Money over the balance becomes an Overpayment credit note after a confirm step. **Void / Remove Payment** (manager or higher) reverses it.

**Money applied to the wrong invoice**: open the payment → **Move** on the allocation → pick another open invoice of the same customer → amount → **Move**. No journal entry is needed (both invoices are in the same receivable) and QuickBooks is updated. Payments that came from QuickBooks are moved in QuickBooks instead.

## Collections

1. **Aging**: {{Accounting › Receivables › AR Aging @/accounting/ar-aging}}. Work the oldest bucket first, weekly.
2. {{Accounting › Receivables › Collections @/accounting/collections}} → pick the customer:
   - **Collection Notes** — each call or email, with a follow-up date;
   - **Promise to Pay**;
   - **Dunning Letters** — 30/60/90-day and final notices.
   - **Generate & Send** always makes the letter; it emails it only when customer emails are on (the master switch in {{Settings › Customer Emails @/settings?tab=customer_notifications}}) and the customer is not on the do-not-email list. The message says which happened — print and mail the letter when it was not emailed: **View PDF** on the letter's row opens it.
   - The nightly automatic dunning letters follow the **Dunning letters** reminder in Customer Emails, which ships OFF.
3. **Statements**: {{Receivables › Statements @/accounting/statements}} → customer → **Generate PDF**.

:::callout info What every PDF looks like
Invoices, statements, dunning letters, credit applications and the accounting reports all print on one letterhead: the logo from {{Settings › Design @/settings?tab=design}} in a band across the top, the company name, address and contact details, and page numbers. The **PDF documents** card there turns the logo off (the company name prints instead), sets the accent colour and the invoice footer line. A draft invoice's PDF is stamped **DRAFT** and a void one **VOID**. **View PDF** (or **Preview PDF** on a draft) on an invoice opens it in a new tab; the customer portal's **Download PDF** works the same way.
:::
4. **Automatic reminders** ({{Settings › Customer Emails @/settings?tab=customer_notifications}}): every reminder ships OFF. A reminder sends only when all three are on: the master switch, that reminder's own tick, and the dispatcher job. Use **Send me a sample** before turning one on.
5. **Writing off a bad debt**: on the invoice, **Write Off** → reason → **Write Off** (sent, overdue or partly paid invoices; needs journal-entry permission). It posts DR Bad Debt Expense / CR AR, closes the invoice and sends QuickBooks a credit memo. Don't use a credit note — that reduces revenue instead. Damage-claim invoices are written off from the claim ([Daily operations](sop:daily-operations#damage-claims)).
6. **Money received after a write-off**: on the written-off invoice, **Record Recovery** → amount, date, bank → it posts DR the bank / CR Bad Debt Expense and the invoice stays written off. The entry is sent to QuickBooks as a journal entry, so don't record the same money there again.

Jobs that run on their own: mark overdue invoices; apply late fees — only once a rule exists on {{Settings › Late Fees @/settings/late_fees}} (a global rule, plus per-customer overrides or exemptions; each overdue invoice gets one late-fee invoice, as a draft); collections escalation (15 days overdue = watch, 45 = collections, internal only); promise-to-pay checks.
