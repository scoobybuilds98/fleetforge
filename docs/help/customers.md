---
description: Manage your trucking and trailer rental customers — contacts, billing, and QuickBooks sync.
---

# Customers

Quick tutorials for working with customers in FleetForge.

## Adding a new customer

1. In the sidebar, click **Customers**, then click **+ New Customer** (top right).
2. Enter the **Company Name** (required) and the primary contact details — name, email, phone.
3. Choose **Status** (default: Active) and **Risk Level**.
4. Set **Currency** (CAD or USD) and **Billing Cycle** (Monthly or On Close Only).
5. If the customer is tax-exempt, check **GST Exempt** or **PST Exempt** and enter the exemption certificate number and expiry date.
6. Optionally select **Tags** (e.g. vip, net-30, fleet) and add a note in the **Notes** field.
7. Click **Create Customer**. FleetForge saves the record and queues a sync to QuickBooks automatically.

## Finding a customer

1. In the sidebar, click **Customers**.
2. Type a company name, contact, email, DOT number, or MC number in the search box.
3. Use the **Status** or **Risk** dropdowns to filter the list further.
4. Click the company name or **View** to open the full customer profile. Click **Edit** to edit directly from the list.

## Editing a customer

1. Open the customer from the list and click **Edit** (top right on the profile), or click **Edit** directly in the list row.
2. Make your changes and click **Save Changes**.
3. If another user saved the record while your form was open, FleetForge will alert you — refresh the page and re-enter your changes.

## Viewing a customer's history

Open the customer profile and use the tabs to navigate:

- **Overview** — contact info, address, regulatory numbers (DOT, MC, GST/PST), and billing terms.
- **Leases** — all active and completed leases. Click **View All** to go to the filtered lease list.
- **Invoices** — every invoice with status and balance due.
- **Notes** — internal team notes. Pinned notes appear at the top.
- **Damage Claims** — claims linked to equipment this customer had on lease.
- **Documents** — uploaded files such as tax exemption certificates or credit agreements.
- **Email History** — emails sent from FleetForge to this customer.

## Starting a lease for a customer

1. Open the customer profile.
2. Click the **Leases** tab, then **View All** — or click **Leases** in the sidebar.
3. Click **+ New Lease**, select this customer from the search, and complete the lease form.

→ See the [Leases guide](/help/leases) for a full walkthrough.

## Sending an email to a customer

1. Open the customer profile.
2. Click the **envelope icon** (Send Email) in the page header.
3. Choose a template or write a custom message, then click **Send**.
4. Sent emails are logged under the **Email History** tab.

---

<details>
<summary>Under the hood</summary>

- **QuickBooks sync** — Creating or editing a customer queues a background sync to QuickBooks Online. The sync is best-effort and never blocks the save; a separate cron worker handles it.
- **Deleting in FleetForge doesn't delete in QBO** — the QBO customer stays active so historical invoices and payments remain intact in QuickBooks (design decision D-QBO-6-1).
- **FleetForge is the source of truth** — changes flow FF → QBO only, never QBO → FF.
- **Soft-delete** — deleted customers are hidden from all lists but never physically removed. Deletion is blocked if the customer has active leases or pending reservations.
- **Outstanding Balance** — reflects only *sent* invoices. Drafts and void invoices don't count toward the balance.
- **Status transitions** — not all transitions are allowed. Active can move to Inactive, Suspended, or Credit Hold. Attempting an invalid transition (e.g. Suspended → Pending) returns an error.
- **Optimistic locking** — the edit form requires the current `updated_at` timestamp. If another user saved the record first, you'll be asked to refresh before retrying.

</details>

## Related

- [Leases](/help/leases)
- [Invoices](/help/invoices)
- [Payments](/help/payments)
- [QuickBooks Sync](/help/quickbooks)
