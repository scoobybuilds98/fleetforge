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
Invoices are made on purpose, never automatically: the monthly invoice job is switched off, so each month's invoices come from Batch Invoicing or a lease's Generate Invoice. A draft changes nothing. **Sending** is the moment the invoice becomes revenue, is added to the customer's balance, and goes to QuickBooks.

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

Manager, from {{Invoices › Batch Invoicing @/invoices/batch}}:

1. **1. Billing Period**: **Last Month** (or a custom range).
2. **2. Customers & Leases**: **Select all unbilled**. Untick anything not ready.
3. **Delivery Options**: tick "Also email the invoice to the customer" and "Attach the invoice PDF" if emailing now. Check **3. Recipient Emails**.
4. Press **Preview totals**. Nothing is saved yet. Use **Hold for review** on anything that looks wrong, then **Looks right — Generate N**.
5. Clear **Needs Attention** (**Fixed** or **Skip**).
6. In **4. Review & Send**, open the PDFs of a sample, or **Download combined PDF**.
7. Press **Mark N as Sent**, or **Send & Email N** to email as well.

:::callout info What batch does not cover
Batch only covers active monthly leases. A completed lease with an unbilled month uses **Generate Invoice** on the lease. If "Require approval before batch billing" is on ({{Settings › General @/settings?tab=general}}), **Submit for approval** replaces Generate.
:::

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
- **Customers pay online** once `{pay_online_link}` is in the invoice email template and the invoice is in QuickBooks.

:::callout warning Billing rules
- Invoice date = period start; due = 30 days later.
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

## Payments

:::callout rule After QuickBooks go-live, customer payments are recorded in QuickBooks
Pay links, portal payments and the accountant's deposits all arrive in FleetForge on their own. Record a payment in FleetForge only for money QuickBooks will never see.
:::

If you do record one here: {{Payments › Record Payment @/payments/create}} → pick the invoice → amount (or **Pay full balance**) → method → date → reference → **Record Payment**. Money over the balance becomes an Overpayment credit note after a confirm step. **Void / Remove Payment** (manager or higher) reverses it. There is no screen to re-allocate a payment to another invoice.

## Collections

1. **Aging**: {{Accounting › Receivables › AR Aging @/accounting/ar-aging}}. Work the oldest bucket first, weekly.
2. {{Accounting › Receivables › Collections @/accounting/collections}} → pick the customer:
   - **Collection Notes** — each call or email, with a follow-up date;
   - **Promise to Pay**;
   - **Dunning Letters** — 30/60/90-day and final notices.
   - **Generate & Send** emails the customer at once, even with customer emails switched off.
3. **Statements**: {{Receivables › Statements @/accounting/statements}} → customer → **Generate PDF**.
4. **Automatic reminders** ({{Settings › Customer Emails @/settings?tab=customer_notifications}}): every reminder ships OFF. A reminder sends only when all three are on: the master switch, that reminder's own tick, and the dispatcher job. Use **Send me a sample** before turning one on.
5. **Writing off a bad debt**: today the only on-screen route is a damage claim set to **Written Off** ([Daily operations](sop:daily-operations#damage-claims)). An invoice that is simply uncollectable has no write-off button yet ([Known issues](sop:known-issues)).

Jobs that run on their own: mark overdue invoices; apply late fees (no rules exist yet, so it does nothing); collections escalation (15 days overdue = watch, 45 = collections, internal only); promise-to-pay checks.
