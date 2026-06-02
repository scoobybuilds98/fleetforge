---
description: Add, manage, and track your trucking and trailer rental customers — billing, status, QuickBooks sync, and more.
---

# Customers

Everything you need to manage your customer accounts in FleetForge.

## Reading the dashboard

When you open **Customers** in the sidebar, four tiles give you an instant snapshot:

- **Total Customers** — your full customer count. Click to clear any active filters.
- **Active** — customers currently marked Active, shown as a percentage of total.
- **Overdue Balance** — total dollar value of unpaid sent invoices across all customers. Click to jump to the overdue invoices list.
- **Credit Hold** — number of accounts on credit hold. Click to filter the list.

---

## Adding a new customer

1. Click **+ New Customer** (top right of the Customers page).
2. Enter the **Company Name** — this is required and appears on all invoices and documents.
3. Fill in the **Primary Contact Name**, **Email**, and **Phone** as you have them.
4. Set **Status** (defaults to Active) and **Risk Level** (Low / Medium / High).
5. Choose **Currency** (CAD or USD) and **Billing Cycle**:
   - *Monthly* — invoices generate automatically each month.
   - *On Close Only* — invoices only generate when a lease is manually closed.
6. Under **Commercial**, set **Payment Terms** (e.g. Net 30) and **Credit Limit** if applicable.
7. If the customer is tax-exempt, check **GST Exempt** or **PST Exempt** and enter the exemption certificate number and expiry date.
8. Add **Tags** to organize the account (e.g. `vip`, `net-30`, `fleet`, `government`).
9. Optionally write an initial note in the **Notes** field — this becomes the first pinned note on their profile.
10. Click **Create Customer**. FleetForge saves the record and queues a QuickBooks sync automatically.

> **Tip:** The company name and email combination must be unique. If you get a duplicate error, search for the existing record first.

---

## Finding a customer

1. Open **Customers** in the sidebar.
2. Type in the search box — it searches by company name, contact name, email, DOT number, and MC number simultaneously.
3. Use the **Status** dropdown to filter by Active, Inactive, Pending, Suspended, or Credit Hold.
4. Use the **Risk** dropdown to filter by High, Medium, or Low.
5. Sort the list using the dropdown (Newest first, Company name, Status, or Risk).
6. Click a company name or **View** to open their profile, or **Edit** to go directly to the edit form.

---

## Editing a customer

1. Find the customer and click **Edit** in the list row — or open their profile and click **Edit** (top right).
2. Make your changes across any of the form sections.
3. Click **Save Changes**.

> **Note:** If another user edited this customer while your form was open, FleetForge will warn you. Refresh the page to get the latest version before re-entering your changes.

---

## Managing account status

A customer's status controls how they appear in filters and reports. You change it through **Edit** on the customer profile.

| Status | Meaning |
|--------|---------|
| **Active** | Normal account — can create leases and invoices. |
| **Inactive** | Account on hold — still visible but excluded from active filters. |
| **Pending** | New account awaiting approval or setup. |
| **Suspended** | Account suspended — team is notified automatically. |
| **Credit Hold** | Billing concern — team is notified. Existing leases continue but new ones should be reviewed. |

> **Note:** Status can only move along defined paths (e.g. Active → Credit Hold is allowed, but Suspended → Pending is not). FleetForge will show an error if a transition isn't permitted.

---

## Placing a customer on credit hold

1. Open the customer profile and click **Edit**.
2. Change **Status** to **Credit Hold**.
3. Click **Save Changes**.
4. An in-app notification is sent to your team automatically.

To lift the hold, change the status back to **Active** and save.

---

## Viewing a customer's full profile

Open a customer to see their profile. Use the tabs to navigate:

- **Overview** — all contact info, address, regulatory numbers (DOT, MC, GST/PST), billing contact, and commercial terms.
- **Notes** — internal team notes. Pinned notes appear at the top. Anyone with edit access can add notes.
- **Leases** — every lease, active and historical. Filter by status, sort by date or rate.
- **Invoices** — all invoices with status, due date, and balance due. Filter and sort to find what you need.
- **Damage Claims** — claims filed against equipment this customer had on lease.
- **Mileage Logs** — GPS sync and manual odometer records for their leased units.
- **Rates** — any custom rate overrides negotiated for this customer.
- **Documents** — uploaded files like tax exemption certificates or credit agreements.
- **Email History** — every email sent to this customer from FleetForge, with the body and status.

The four stat tiles at the top of the profile are clickable — they jump directly to the Leases, Invoices, or Documents tab.

---

## Adding a note to a customer

1. Open the customer profile and click the **Notes** tab.
2. Type your note in the text box at the top.
3. Check **Pin this note** if it should stay at the top of the list permanently.
4. Click **Add Note**.

Notes are internal only and never visible to the customer.

---

## Uploading a document

1. Open the customer profile and click the **Documents** tab.
2. Click **+ Upload**.
3. Choose the document type (Tax Exemption Certificate, Credit Agreement, or Other).
4. Optionally add a title and an expiry date (useful for certificates that need renewal tracking).
5. Select the file (PDF, JPEG, or PNG — max 20 MB) and click **Upload**.

---

## Setting custom rate overrides

If a customer has negotiated special pricing on specific equipment types:

1. Open the customer profile and click the **Rates** tab.
2. Click **+ Add Override**.
3. Select the equipment type, set the effective date range, and enter the custom rates (daily, weekly, monthly, and/or per-km mileage).
4. Click **Add Override**.

Rate overrides apply automatically when invoices are generated for this customer's leases — no manual adjustment needed.

---

## Starting a lease

1. Open the customer profile.
2. Click the **Leases** tab, then **View All**.
3. Click **+ New Lease**, and the customer will be pre-selected.

→ See the [Leases guide](/help/leases) for a full walkthrough of the lease form.

---

## Sending an email

1. Open the customer profile.
2. Click the **envelope icon** (Send Email) in the page header.
3. Choose a template from the dropdown, or leave it blank to write a custom message.
4. Edit the subject and body as needed, then click **Send**.
5. Sent emails appear immediately in the **Email History** tab.

---

## Deleting a customer

> **Before you delete:** A customer cannot be deleted if they have active leases or pending/confirmed reservations. Close or transfer those first.

1. Open the customer profile.
2. Click **Delete** in the page header (visible only when no active leases exist).
3. Confirm the deletion.

Deleted customers are hidden from all lists but are never permanently removed — the record is preserved for audit history. Their QuickBooks account is **not** deleted.

---

<details>
<summary>Under the hood — how it works technically</summary>

**QuickBooks sync**
Creating or editing a customer queues a background sync to QuickBooks Online. The sync is best-effort and runs via a cron worker — it never blocks the save. If QBO sync is disabled, the enqueue is a silent no-op.

**FF is the source of truth**
Data flows FleetForge → QuickBooks only. Changes made directly in QuickBooks are not reflected back in FleetForge.

**Deleting in FF does not delete in QBO**
This is intentional (D-QBO-6-1). The QBO customer stays active so historical invoices and payments in QuickBooks remain intact. The mapping row is kept with its original status.

**Soft-delete**
Deletion sets `deleted_at` on the row — the customer disappears from all lists and searches but is never physically removed from the database.

**Outstanding Balance**
Only counts invoices with status `sent`, `partially_paid`, or `overdue`. Draft and void invoices are excluded. The counter increments when a draft transitions to sent, and decrements when a payment is applied.

**Optimistic locking**
The edit form sends the customer's current `updated_at` timestamp with the save. If another user saved the record in between, you get a 409 conflict — refresh the page to get the latest data before re-saving.

**Status transitions**
Not all transitions are allowed. The permitted paths are: Active → Inactive / Suspended / Credit Hold; Inactive → Active; Pending → Active / Inactive; Suspended → Active / Inactive; Credit Hold → Active / Suspended.

**Credit limit**
Setting a credit limit does not automatically block new leases or invoices. It's a visible reference field for your team to use when reviewing accounts.

**Tags**
Tags are a predefined set: vip, preferred, owner-operator, fleet, net-30, net-45, net-60, cod, tax-exempt, high-risk, watchlist, credit-hold, delinquent, new, seasonal, government, broker. You can apply multiple tags. Tags on update replace all existing tags wholesale.

</details>

## Related guides

- [Leases](/help/leases)
- [Invoices](/help/invoices)
- [Payments](/help/payments)
- [QuickBooks Sync](/help/quickbooks)
