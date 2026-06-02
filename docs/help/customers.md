---
description: Manage your trucking and trailer rental customers — billing settings, contact details, and QuickBooks sync.
---

# Customers — How This Works

## Overview

The Customers module is the starting point for everything revenue-related in FleetForge. A customer record stores the company's contact information, billing preferences, tax exemption status, and commercial terms (payment terms, currency, discounts). Every lease, invoice, and payment in the system belongs to a customer.

When you create or update a customer, FleetForge automatically queues a sync to QuickBooks Online in the background — so your AR records stay in step without any manual export steps.

---

## Common Tasks

### Add a new customer

1. Go to **Customers** in the sidebar and click **+ New Customer**.
2. Fill in the company name (required) and as much contact information as you have.
3. Set the **Status** (default: Active), **Currency**, and **Billing Cycle**.
4. If the customer is GST or PST exempt, check the corresponding box and enter the exemption number and expiry date.
5. Optionally add **Tags** (e.g. `vip`, `net-30`, `fleet`) to help filter and categorise.
6. Click **Save**. The customer is created and a QuickBooks sync is queued automatically.

### Edit a customer

1. Find the customer in the list and click **Edit** (or open their profile and click **Edit** in the top right).
2. Make your changes and click **Save**.
3. FleetForge checks that no one else edited the record while your form was open. If a conflict is detected, you will be asked to refresh and try again.

### Place a customer on credit hold

1. Open the customer's profile and click **Edit**.
2. Change **Status** to **Credit Hold**.
3. Save. An in-app notification is sent to your team.
4. Invoices can still be created for credit-hold customers, but new leases should be reviewed before approval.

### View a customer's activity

Open the customer's profile. The tab bar gives you a full picture:

- **Overview** — contact info, address, regulatory numbers, billing terms.
- **Notes** — internal notes added by your team.
- **Leases** — active and historical leases.
- **Invoices** — all invoices with status and balance due.
- **Damage Claims** — claims linked to equipment rented by this customer.
- **Mileage Logs** — GPS and manual odometer records for their leased units.
- **Rates** — custom rate overrides (if the customer has negotiated pricing).
- **Documents** — uploaded files (tax exemption certificates, credit agreements, etc.).
- **Email History** — emails sent from FleetForge to this customer.

### Send an email to a customer

From the customer profile, click the **envelope icon** (Send Email) in the page header. Choose a template or compose a free-form message. Sent emails are logged under the **Email History** tab.

### Delete a customer

A customer can only be deleted if they have **no active leases** and **no pending or confirmed reservations**. Find the delete button at the bottom of the edit form or in the profile header. Deletion is a soft-delete — the record is hidden from all lists but is retained in the database for audit history.

---

## Key Concepts

**Status** — Reflects the current account standing. Statuses move along defined paths (e.g. Active → Suspended; Inactive → Active). The available statuses are: Active, Inactive, Pending, Suspended, and Credit Hold.

**Risk Score** — A manual assessment: Low, Medium, or High. Displayed as a coloured badge on the customer profile and list. Used to surface high-risk accounts in the customer list filters.

**Outstanding Balance** — The sum of all sent (billed) invoice balances that have not yet been paid in full. Excludes draft and void invoices. This counter is updated each time an invoice transitions from draft to sent.

**Tags** — Predefined labels (vip, preferred, fleet, net-30, net-45, net-60, cod, tax-exempt, high-risk, watchlist, credit-hold, delinquent, new, seasonal, government, broker) that can be applied to a customer for grouping and filtering.

**Billing Cycle** — Controls how monthly billing is generated. `monthly` = the cron job generates invoices each month. `on_close_only` = invoices are only generated when a lease is closed.

**GPS Revenue Presentation** — A per-customer accounting policy (ASPE 3400): whether Mainland recognises GPS pass-through revenue as **net (agent)** — only the margin — or **gross (principal)** — the full billed amount. Defaults to net.

---

## Understanding the Fields

**Company Name** — Required. Must be unique in combination with the email address. The name used across all documents, invoices, and QuickBooks.

**Contact Name / Email / Phone** — The primary operational contact. The email address is also used for sending invoices and notifications.

**Billing Contact / Billing Email** — Separate from the primary contact. If set, billing emails (invoices, statements) are sent here instead of the primary email.

**Invoice Email** — An additional email address specifically for invoice delivery. Useful when invoices go to a billing department and correspondence goes to an account manager.

**Invoice Delivery** — How invoices are sent: `email`, `mail`, `portal`, or `none`.

**Payment Terms** — Free-text note (e.g. "Net 30", "COD"). Printed on invoices.

**Credit Limit** — Sets an internal ceiling. FleetForge does not automatically block new leases when the limit is exceeded, but it is visible on the customer profile for manual review.

**DOT Number / MC Number** — U.S. Department of Transportation and Motor Carrier numbers for regulated carriers. Used for regulatory reference only.

**GST Number / PST Number** — Canadian sales tax registration numbers. Included on invoices when present.

**GST Exempt / PST Exempt** — Marks the customer as exempt from the corresponding tax. When checked, invoices for this customer are generated without the tax line. Enter the exemption certificate number and expiry date so you know when the exemption needs renewal.

**Discount** — A standing discount applied to all invoices: none, a flat dollar amount, or a percentage. Capped at 100% for percentage discounts.

**Is Related Party** — ASPE 3840 flag. When checked, marks the customer as a related-party transaction for disclosure in the financial statements.

---

## How It Connects

- **Leases** — A customer can have many leases. Creating a lease requires an existing customer. The customer's active lease count is maintained as a denormalized counter.
- **Invoices** — Invoices belong to a customer via the lease. The customer's Outstanding Balance tile is driven by the sum of unpaid sent invoice balances.
- **Payments** — Payments are applied to invoices, which indirectly reduce the customer's outstanding balance.
- **Damage Claims** — Claims for equipment damage are linked to the customer who had the unit on lease when the damage occurred.
- **QuickBooks Online** — Customers sync to QBO as Customer entities. FleetForge is the source of truth; changes flow FF → QBO, never the reverse.
- **Rates** — Custom rate overrides for a specific customer (per equipment type, with effective dates) override the global rate card when invoices are generated for that customer.

---

## Under the Hood

> *Technical section — for operators and staff who need to understand what happens behind the scenes.*

### Data Model

Primary table: `customers`. Related tables:

| Table | Purpose |
|-------|---------|
| `customer_tags` | Many-to-many tag assignments (one row per tag per customer) |
| `customer_notes` | Internal notes (pinnable, created by staff) |
| `customer_equipment_rates` | Per-customer rate overrides by equipment type and effective date |
| `acc_qbo_customer_map` | QuickBooks mapping: links `customers.id` to the QBO Customer ID |

Key denormalized counters on `customers`:

- `active_lease_count` — count of leases with `status = 'active'`; maintained by lease create/update transactions
- `lease_count` — total lease count
- `outstanding_balance` — sum of sent, unpaid invoice balances (updated on draft→sent transitions)
- `total_revenue`, `account_credit_balance` — maintained by payment and credit-note transactions

### Business Rules & Invariants

- **Company name + email uniqueness** — The pair `(company_name, email)` must be unique across active customers. A clear 422 error is returned on duplicate creation or when renaming to an existing pair.
- **Status transitions** — Not all status changes are allowed. The permitted transitions are: Active → Inactive / Suspended / Credit Hold; Inactive → Active; Pending → Active / Inactive; Suspended → Active / Inactive; Credit Hold → Active / Suspended. Attempting an invalid transition returns a 409 INVALID_TRANSITION error.
- **Optimistic locking on edits** — Every edit request must include the customer's current `updated_at` timestamp. If another user saved the record between when your form loaded and when you submitted, a 409 STALE_DATA error is returned and you are prompted to refresh. This prevents overwriting concurrent changes.
- **Soft-delete** — Deletion sets `deleted_at` to the current timestamp. The record disappears from all lists and searches but is never physically removed. Deletion is blocked if `active_lease_count > 0` or if the customer has any pending or confirmed reservations.
- **Tag validation** — Only the predefined 17 tag values are accepted. Unknown tags in an API request are silently dropped.
- **Tags on update** — Passing a `tags` array on an update replaces all existing tags wholesale (delete-then-insert in the same transaction). If `tags` is not included in the request, existing tags are left untouched.

### Integrations

**QuickBooks Online (CustomerEnqueuer)** — After a successful create or update, `CustomerEnqueuer::enqueue($customerId, 'create'|'update')` is called outside the main transaction. The enqueue is best-effort: if QBO sync is disabled (`quickbooks.sync_enabled = '0'`, the default until the operator connects QBO) or the sync mode rejects pushes, the call is a no-op. The enqueue **never throws** — a QBO failure must not break the customer create/update flow. The sync runs asynchronously via the QBO worker cron.

**Delete and QBO** — Soft-deleting a customer in FleetForge intentionally does **not** push a delete to QuickBooks. The design decision (D-QBO-6-1) is to leave the QBO customer active. This preserves historical invoices and payments in QBO that reference that customer. The QBO mapping row remains in `acc_qbo_customer_map` with the original status.

**In-app notifications** — Customer creation fires a `customer.created` notification. Status changes to `credit_hold` fire a `customer.credit_hold` (warning severity) notification, and status changes to `suspended` fire a `customer.suspended` (critical severity) notification. These fire inside the database transaction so they are rolled back if the transaction fails.

**Audit log** — Every create, update, and delete is recorded in `audit_log` with the acting user, old values, and new values.

### Edge Cases & Behaviours

- **Partial update** — The update API only writes fields that are explicitly sent in the request body. If you send `{"id": 5, "updated_at": "...", "status": "active"}`, only the status column is updated — all other fields are left as-is. This means a single-field edit cannot accidentally blank other fields.
- **Boolean fields on update** — `gst_exempt`, `pst_exempt`, and `po_required` are boolean. Because `false` is a valid update value (to un-check a box), these are handled separately from nullable optional fields and are written whenever they appear in the request body.
- **active_lease_count staleness guard** — The delete endpoint checks both the denormalized `active_lease_count` column AND runs a live `COUNT(*)` on the leases table. If the counter is ever stale due to a failed transaction, the live count provides a safety net and still blocks deletion.
- **Billing cycle `on_close_only`** — Customers with this billing cycle are excluded from the monthly billing cron. Their invoices are only generated when a lease is manually closed.
- **GPS revenue presentation inline edit** — The Regulatory card on the customer profile includes an inline edit for `gps_revenue_presentation`. It posts to the same `customers/update` API endpoint, but only sends the `gps_revenue_presentation` field — no other fields are touched.

---

## Related Guides

- [Leases](/help/leases)
- [Invoices](/help/invoices)
- [Payments](/help/payments)
- [QuickBooks Sync](/help/quickbooks)
