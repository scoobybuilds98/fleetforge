---
description: Create, send, and manage rental invoices — billing periods, AR aging, payments, voids, and QuickBooks sync.
---

# Invoices

Everything you need to manage invoices in FleetForge.

## Reading the AR aging dashboard

The Invoices list shows four AR aging tiles at the top:

- **Current** — sent invoices not yet past their due date
- **1–30 Days Overdue** — sent/overdue invoices 1–30 days past due
- **31–60 Days Overdue** — 31–60 days past due
- **60+ Days Overdue** — more than 60 days past due

Click any tile to filter the list to that aging bucket. Use the **Outstanding**, **Paid**, and **All** tabs to switch views.

---

## Creating an invoice

1. Click **+ New Invoice** (top right of the Invoices page), or open a lease and click **Generate Invoice**.
2. Select the **Lease** — the dropdown shows contract number, customer, unit, and status. Rates are previewed once you select a lease.
3. Set **Period Start** and **Period End** — the billing days are calculated automatically.
4. Choose the **Billing Type**:
   - *Partial Start* — first partial month of a lease
   - *Full Month* — a complete calendar month
   - *Partial End* — final partial month when a lease closes
   - *Single Period* — a custom one-off billing period
5. Choose the **Invoice Type**: *Regular*, *Final*, *Mileage Only*, or *Adjustment*.
6. If the unit is Samsara-linked, the **Odometer** section appears — enter or fetch the starting and ending odometer readings for the period. Distance is calculated automatically.
7. Enter a **PO Number** if required, and any **Notes** (customer-facing) or **Internal Notes**.
8. Click **Create Invoice**. The invoice is created in **Draft** status.

> **Tip:** Invoices for monthly leases are also generated automatically by the billing cron each month. Manual creation is for corrections, final invoices, or custom billing periods.

---

## Sending an invoice

A draft invoice must be sent before it counts toward the customer's outstanding balance.

1. Open the invoice and click **Send Invoice**.
2. The invoice status changes to **Sent**, the due date is set, and the customer and lease outstanding balances are updated.
3. If email delivery is configured for the customer, the invoice is emailed automatically.

> **Note:** Once sent, all financial fields on the invoice are locked and cannot be changed. You can still edit the PO number, internal notes, and delivery details.

---

## Recording a payment on an invoice

1. Open the invoice.
2. Click **Record Payment** (visible for Sent, Partially Paid, and Overdue invoices).
3. Complete the payment form and save.

→ See the [Payments guide](/help/payments) for a full walkthrough.

---

## Finding an invoice

1. Go to **Invoices** in the sidebar.
2. Type an invoice number or company name in the search box.
3. Use the **Outstanding** / **Paid** / **All** tabs, or click an AR aging tile to filter by aging bucket.
4. On the **All** tab, use the **Status** filter (Draft, Sent, Partially Paid, Paid, Overdue, Void, Written Off) and sort controls.
5. Click the invoice number or **View** to open it.

---

## Viewing an invoice

Opening an invoice shows:

- **Status timeline** — visual flow from Draft → Sent → Paid (or Voided/Written Off).
- **Five KPI cards** — Invoice Date, Due Date, Total Amount, Amount Paid, and Balance Due. Balance Due and Amount Paid are clickable links.
- **Invoice details** — billing period, rates, line items, taxes, and notes.
- **QuickBooks panel** — sync status and QBO invoice ID (if QBO is connected).

**Action buttons** in the header (shown based on status and permissions):

| Button | When shown |
|--------|-----------|
| **Print** | Always |
| **Email Invoice** | Always (if permission) |
| **Edit** | Draft only |
| **Send Invoice** | Draft only |
| **Record Payment** | Sent / Partially Paid / Overdue |
| **View Lease** | If linked to a lease |
| **Void** | Draft or Sent |
| **Delete** | Draft only (admins can delete any status) |

---

## Voiding an invoice

Use void to cancel a sent invoice that should not have been billed (e.g. billing error, duplicate).

1. Open the invoice and click **Void**.
2. Enter a **Reason for voiding** (required).
3. Click **Void Invoice**.

The invoice status changes to **Void**, the outstanding balance on the lease and customer is reversed, and a reversal journal entry is posted. Voided invoices cannot be edited or reactivated.

> **Note:** You cannot void a paid invoice. Use a credit note instead.

---

## Printing or emailing an invoice to a customer

- **Print**: click **Print** on the invoice — opens the browser print dialog with a formatted invoice layout.
- **Email**: click **Email Invoice** — opens the email compose dialog with the invoice pre-attached.

---

## Understanding invoice statuses

| Status | Meaning |
|--------|---------|
| **Draft** | Created, not yet sent. Fully editable. Does not count toward outstanding balance. |
| **Sent** | Sent to customer. Financially locked. Added to outstanding balance. |
| **Partially Paid** | Payment received but balance remains. |
| **Paid** | Fully paid. |
| **Overdue** | Sent invoice past its due date (automatically set). |
| **Void** | Cancelled. Balance reversed. |
| **Written Off** | Deemed uncollectable. Counters cleared. |

---

<details>
<summary>Under the hood — how it works technically</summary>

**Immutability (D12)**
Once an invoice is sent, ALL financial fields are locked for all users — no exceptions, including admins. This is intentional for CRA audit defensibility. Post-send editable fields are limited to: PO number, internal notes, sent-to email, and delivery method.

**Outstanding balance counters (Path B)**
`leases.outstanding_balance` and `customers.outstanding_balance` increase when an invoice is *sent* and decrease when a payment is applied, when the invoice is voided, or when it is deleted (if sent). Draft invoices do not affect the balance counters.

**Status transitions**
Only `draft → sent` is valid via the Send action. Paid, partially paid, and overdue invoices cannot be voided — issue a credit note instead. Written-off invoices are terminal.

**Invoice numbers**
Auto-generated at creation. Numbers are preserved even when an invoice is soft-deleted (never reused) per D15.

**QuickBooks sync**
Invoices are not pushed to QBO while in draft. The push is queued when the invoice is sent (draft → sent). Void pushes are queued when an invoice is voided. The per-row QBO badge on the list shows push status.

**Mileage precharge (first invoice)**
The first invoice carrying a mileage precharge line locks the precharge on the lease at send time. Subsequent invoices cannot include another precharge line — attempting to send one returns a PRECHARGE_ALREADY_BILLED error.

**Auto-journal entry**
Sending an invoice posts a double-entry journal: DR Accounts Receivable / CR Revenue / CR GST Payable / CR PST Payable. Voiding reverses this entry.

**Odometer data on invoices**
If odometer readings are captured, the invoice records `odometer_at_period_start_km` and `odometer_at_period_end_km`. Distance driven that period is calculated from these and used for mileage billing.

**Automatic monthly invoicing**
For leases with `billing_cycle = 'monthly'`, the billing cron generates draft invoices each month automatically. These still require manual sending (or auto-send if configured).

</details>

## Related guides

- [Leases](/help/leases)
- [Payments](/help/payments)
- [Customers](/help/customers)
- [QuickBooks Sync](/help/quickbooks)
