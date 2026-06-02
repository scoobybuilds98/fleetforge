---
description: Record and track customer payments against invoices — methods, allocations, outstanding balance, voids, and QuickBooks sync.
---

# Payments

Record and track customer payments against invoices.

## Reading the dashboard

The Payments list shows four tiles at the top:

- **Collected This Month** — total of cleared payments dated this calendar month, with the payment count below. Click it to filter the table to **Cleared** payments.
- **Total AR Outstanding** — total balance due across outstanding invoices (Sent, Partially Paid, Overdue), with the invoice count. Click it to jump to outstanding invoices.
- **Overdue AR** — total balance due on invoices past their due date, with the count of invoices past due. Click it to view overdue invoices.
- **Recorded Today** — count of payments recorded today. Click it to filter the table to today's date range.

---

## Recording a payment

1. Click **Record Payment** (top right of the Payments page). You can also open an invoice and click **Record Payment** to arrive here with the invoice pre-selected.
2. In the **Invoice** field, search by invoice # and pick one. Only invoices that can still receive a payment appear (Sent, Partially Paid, Overdue). The **Balance due** is shown once you select, and the **Selected Invoice** card on the right summarizes the invoice total, balance, due date, and status.
3. Enter the **Amount**. Use the **Pay full balance** button to fill the exact outstanding balance.
4. The **Currency** is locked to the invoice's currency (CAD or USD) once an invoice is selected.
5. Select the **Payment Method** — Cheque, ACH / Direct Deposit, Wire Transfer, Credit Card, Cash, e-Transfer, Account Credit, or Other.
6. Set the **Payment Date** (cannot be in the future).
7. Fill any reference details. **Reference / Confirmation #** is always available; method-specific fields appear as needed: **Cheque Number** (cheque), **Bank Name** (cheque/ACH/wire), and **Card Last 4 Digits** (credit card).
8. Optionally add **Notes (customer-visible)** and **Internal Notes (staff only)**.
9. Click **Record Payment**. The payment is recorded, applied to the invoice, and you are redirected to the payment detail page.

> **Note:** Payment currency must match the invoice currency. Recording a payment applies it to the selected invoice, lowers that invoice's balance, and reduces the customer's outstanding balance automatically.

---

## Finding a payment

1. Go to **Payments** in the sidebar.
2. Type a reference or payment # in the search box ("Search by reference or payment #…").
3. Use the **Status** filter (All Statuses, Cleared, Pending, Failed, Refunded, Void, Returned).
4. Click the **Payment #** column, **Date**, or **Amount** column headers to sort.
5. Click **Reset** to clear filters.
6. Click the payment number or **View** to open it.

---

## Viewing a payment

Opening a payment shows:

- **Status badge** in the header, plus when and who recorded it.
- **Summary tiles** — Amount Received, Payment Method, Payment Date, and Customer. Amount Received links to all payments from that customer; Customer links to the customer profile.
- **Payment Details** — payment number, amount (with CAD equivalent for USD), method, reference #, cheque #, bank, card last 4, payment/deposited/cleared dates, and status.
- **Financial Notes** — overpayment, refund, verification, and notes (internal notes shown only with edit permission).
- **Invoice Allocations** table — which invoices the payment was applied to, with Invoice #, Billing Period, Invoice Total, Applied Amount, Remaining Balance, Invoice Status, and allocation Type.
- **QuickBooks Sync panel** — sync status and QBO payment ID (if QuickBooks is connected).
- **Send Receipt** button — emails a payment receipt to the customer (if permitted).

---

## Editing a payment

Financial details (amount, method, currency, dates) are locked once a payment is recorded. Only reference and note fields can be edited.

1. Open the payment and click **Edit Notes / Reference**.
2. Update the **Reference Number**, **Bank Name**, **Public Notes**, or **Internal Notes**.
3. Click **Save Changes**.

---

## Voiding / removing a payment

Use this to reverse a payment that was recorded in error.

1. Open the payment and click **Void / Remove Payment**.
2. Enter a **Reason** (required).
3. Click **Confirm Remove**.

This soft-deletes the payment and reverses all of its invoice allocations. Each affected invoice's amount paid and balance are recomputed and its status reverts (paid → partially paid → sent, depending on what remains), and the customer's outstanding balance goes back up by the amount that was applied.

> **Note:** Voiding/removing requires Manager-or-higher permission. Accountants can record payments but not void them.

---

## Payment statuses & methods

| Status | Meaning |
|--------|---------|
| **Cleared** | Payment recorded and applied (the status every newly recorded payment receives). |
| **Pending** | Payment recorded but not yet cleared. |
| **Failed** | Payment did not go through. |
| **Refunded** | Payment was refunded. |
| **Void** | Payment reversed/removed. |
| **Returned** | Payment was returned (e.g. NSF cheque). |

| Payment method | Shown as |
|----------------|----------|
| Cheque | Cheque |
| ACH / Direct Deposit | ACH |
| Wire Transfer | Wire |
| Credit Card | Credit Card |
| Cash | Cash |
| e-Transfer | e-Transfer |
| Account Credit | Acct Credit |
| Other | Other |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **One atomic transaction** — recording a payment writes the payment row, the allocation, the invoice status change, and all balance counters together. If any step fails (including the journal entry), the whole thing rolls back.
- **How a payment applies to an invoice** — the payment is allocated to the selected invoice. The invoice's `amount_paid` increases by the allocated amount and `balance_due` is recomputed as `total_amount − credits_applied − amount_paid` (clamped at 0).
- **Invoice status transition** — after applying, the invoice becomes **paid** if the balance reaches 0, otherwise **partially_paid** (and `paid_date` is stamped when fully paid).
- **Outstanding-balance counter (Path B)** — `customers.outstanding_balance` decreases by the amount applied to the invoice (never below 0), and `leases.total_paid` increases by the same amount, in the same transaction. Voiding/removing the payment reverses both.
- **Currency match (D18)** — payment currency must equal the invoice currency. USD payments freeze the USD→CAD exchange rate (and any configured markup) at receipt time and store a CAD equivalent.
- **Overpayments** — if the amount exceeds the invoice balance, only the balance is applied to the invoice and the excess is auto-routed to a customer **credit note** (source = overpayment) in the same transaction.
- **Payment numbers** — auto-generated gap-free as `PAY-YYYY-NNNNN` (prefix configurable in settings).
- **Immutability (D12)** — once recorded, financial fields are read-only for CRA compliance; only reference/bank/notes fields are editable afterward.
- **Soft-delete only (D5/D13)** — payments are never hard-deleted; "Void / Remove" sets `deleted_at` and reverses the counters and invoice statuses.
- **Journal entries** — a standard payment posts DR Cash / CR Accounts Receivable. An overpayment posts a 3-line entry (DR Cash / CR AR applied / CR overpayment liability).
- **QuickBooks sync** — recording a payment queues a push to QBO; voiding/removing queues the void to the mapped QBO payment. (Pushes respect the sync-enabled and native-origin gates.)
- **Notifications** — recording fires an in-app `payment.received` notification, an `invoice.paid` or `invoice.partially_paid` notification, and a portal confirmation to the customer; voiding fires a `payment.reversed` notification.

</details>

## Related guides

- [Invoices](/help/invoices)
- [Customers](/help/customers)
- [QuickBooks Sync](/help/quickbooks)
