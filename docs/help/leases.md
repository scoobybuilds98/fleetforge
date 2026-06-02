---
description: Create and manage truck and trailer rental leases — rates, billing, mileage tracking, and lease lifecycle.
---

# Leases

Everything you need to manage rental leases in FleetForge.

## Reading the dashboard

The Leases list shows four KPI tiles at the top:

- **Active** — leases currently in progress (includes the pending sub-count)
- **Pending** — leases created but not yet activated
- **Completed** — closed leases (includes the cancelled sub-count)
- **Active Revenue** — total monthly rate across all active leases

Use the three tabs to filter: **Active & Pending**, **Closed**, or **All**.

---

## Creating a new lease

1. Click **+ New Lease** (top right of the Leases page).

2. **Customer & Equipment** section:
   - Select the **Customer** — this auto-fills currency, billing cycle, and tax exemption from the customer record.
   - Select the **Equipment Unit** — available units are selectable; units already on lease or in maintenance are shown greyed out.
   - Leave **Contract Number** blank to auto-generate (format: CN-XXXXXX-YYYY), or enter a custom one.
   - Enter a **PO Number** if the customer requires one.

3. **Dates** section:
   - Set the **Start Date**.
   - Set an **End Date** (or leave blank for an open-ended lease).
   - Set a **Minimum End Date** if an early return fee applies before that date.
   - Choose **Billing Cycle**: *Monthly (auto-invoice)* generates invoices automatically each month; *On Close Only* generates one invoice when the lease is closed.

4. **Rental Rates** section:
   - Enter **Daily Rate**, **Weekly Rate**, and **Monthly Rate**. At least one must be greater than zero. For monthly billing, all three must be filled in.
   - Set the **Mileage & Allowance** if applicable: enter the per-km or per-mile rate and the total km/mile allowance for the lease. Set both to 0 to disable mileage billing.
   - Check **Apply mileage precharge** if the customer prepays a mileage deposit upfront (drawn down against monthly charges, refunded at close if unused).

5. **Discount & Add-ons** section:
   - Set a **Discount Type** (none, percentage, or flat) and value if applicable.
   - Enable **Insurance Add-on**, **Warranty Add-on**, or **GPS Tracking ($/day)** as needed.

6. **Tax Exemption** section: auto-filled from the customer record. Adjust if needed.

7. **Starting Odometer**: enter or fetch from Samsara if the unit is GPS-linked. Capturing this now enables accurate mileage tracking for the lease.

8. Add **Notes** (customer-facing) and **Internal Notes** as needed.

9. Click **Create Lease**. The unit is immediately reserved and the lease is created in **Pending** status.

> **Tip:** A pending lease can be fully edited. Once activated, rate and unit changes require an amendment record.

---

## Activating a lease

A lease starts in **Pending** status. Nothing is invoiced until it is activated.

1. Open the lease and click **Activate Lease** (below the KPI tiles).
2. The unit status changes from *Reserved* to *On Lease*, and billing begins.
3. If you set advance billing periods, Invoice 1 is generated immediately with those prepaid months included.

---

## Finding a lease

1. Go to **Leases** in the sidebar.
2. Type a contract number, company name, or unit number in the search box.
3. Use the tab bar to narrow to **Active & Pending** or **Closed** leases.
4. Use the **Status** filter (on the All tab) and sort controls to refine further.
5. Click the contract number or **View** to open the lease profile.

---

## Viewing a lease profile

Open a lease to see its full profile. The KPI tiles at the top show **Total Invoiced**, **Total Paid**, **Outstanding balance**, and **Currency** — each is clickable and drills through to the relevant records.

Use the tabs to navigate:

- **Overview** — all lease details: contract info, rates, mileage, customer, unit, and odometer data.
- **Status Log** — every status transition with timestamp, who made the change, and any notes.
- **Amendments** — recorded rate changes, date extensions, and other modifications.
- **Invoices** — all invoices linked to this lease. Filter by status, sort by date or amount.
- **Damage Claims** — claims filed against equipment during this lease.
- **Mileage Log** — odometer readings recorded throughout the lease.
- **Inspections** — pre- and post-lease inspection records.
- **Documents** — uploaded files (agreements, condition reports, etc.).

---

## Generating an invoice for a lease

1. Open the lease and click **Generate Invoice** (below the KPI tiles).
2. This takes you to the invoice create form with the lease pre-selected.
3. Set the billing period and complete the form.

→ See the [Invoices guide](/help/invoices) for a full walkthrough of invoice creation.

---

## Recording a rate amendment

When an active lease's rates change (e.g. annual rate review or contract renegotiation):

1. Open the lease and click **Amend Rates** (in the Rates card on the Overview tab).
2. Enter the new rates and a reason.
3. Click **Apply Amendment**.

> **Note:** Rate amendments apply to invoices generated **after** the change. Already-sent invoices are not retroactively changed (they are immutable once sent). Issue a credit note if a retroactive adjustment is needed.

All amendments are logged in the **Amendments** tab.

---

## Closing a lease

1. Open the lease and click **Close Lease** (shown only for Active leases).
2. In the close modal, enter the **Actual Return Date**.
3. Capture the **Closing Odometer** reading — enter manually or fetch from Samsara.
4. The actual mileage is auto-calculated from the odometer readings. Adjust if needed.
5. If the lease has a mileage precharge balance remaining, select the **Precharge Refund** method (Credit Note, Cheque, Account Credit, or Waive).
6. Confirm. The lease moves to **Completed**, the unit returns to *Available*, and a final invoice is queued if any balance is outstanding.

---

## Deleting a pending lease

Only **Pending** leases can be deleted (active leases must be closed first).

1. Open the pending lease.
2. Click **Delete** in the page header.
3. Confirm. The lease is soft-deleted and the unit returns to *Available*.

---

<details>
<summary>Under the hood — how it works technically</summary>

**Status lifecycle**
`pending` → `active` (on Activate) → `completed` (on Close). Pending leases can be deleted; active leases cannot. Cancelled is a separate terminal state for manually cancelled contracts.

**Unit reservation**
Creating a lease sets the equipment unit to `reserved`. Activating changes it to `on_lease`. Closing returns it to `available`. Deleting a pending lease also returns the unit to `available`. This prevents two leases from being created for the same unit simultaneously.

**Contract number**
Auto-generated as `{prefix}-XXXXXX-YYYY` (prefix from Settings, default "CN"). Numbers are de-duplicated.

**Snapshots frozen at creation**
Customer name, unit number, and template name are snapshotted on the lease record. These persist even if the customer or unit is later soft-deleted.

**Rate validation**
At least one rate (daily, weekly, monthly, or mileage) must be > 0. For monthly billing, all three rental tiers (daily, weekly, monthly) must be filled in together.

**Tax rates frozen at activation**
GST and PST rates are looked up from the customer's province and frozen on the lease. Tax exemptions are checked against the lease start date.

**Mileage billing (dual-unit)**
Rates are stored in both km and miles. The primary unit (set at creation) is used for billing. The secondary unit auto-converts using the stored conversion factors (default: 1 km = 0.621371 miles).

**Mileage precharge (Model B)**
If precharge is enabled, the precharge amount is billed on Invoice 1. It initialises a balance that draws down against monthly mileage charges. Any remaining balance at close is refunded using the selected method.

**Advance billing**
If advance billing periods > 0, Invoice 1 includes the first regular month plus the advance months. The cap is set in Settings (default 24).

**Rate amendments don't retroactively change invoices**
Once an invoice is sent it is immutable (D12). Rate amendments apply only to future invoices.

**QuickBooks sync**
Creating and activating a lease does not push to QBO directly. Invoices generated from the lease push to QBO when sent. Lease data syncs to QBO customer records via the CustomerEnqueuer.

</details>

## Related guides

- [Customers](/help/customers)
- [Invoices](/help/invoices)
- [Payments](/help/payments)
- [Equipment](/help/equipment)
