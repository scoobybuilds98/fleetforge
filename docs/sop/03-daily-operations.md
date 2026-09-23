---
title: Daily operations
short: Daily operations
summary: Customers, units, rates, leases from reservation to return, and damage claims.
icon: truck
accent: success
part: Operations & billing
audience: dispatcher, manager
reviewed: 2026-09-24
---
A unit earns money only while it is on an active lease, and every invoice is priced from the lease. So the lease — dates, rates, mileage mode, readings — must be right before anything is billed. Where the in-app Help and a screen disagree, follow the screen: several Help pages predate the current screens.

## Start of day

Dispatcher and manager, every morning:

1. {{Dashboard @/dashboard}} — work the strips top to bottom:
   - **Pending Activations** — units leaving today;
   - **Upcoming Returns**;
   - **Draft Invoices**;
   - **Overdue Payments**.
2. {{Leases @/leases}} → *Active & Pending*: an amber **Billed Thru** date means usage not yet invoiced.
3. Open Service Requests and today's inspections.

## Customers

1. {{Customers › New Customer @/customers/create}}. Fill:
   - **Identity**: Company Name is required.
   - **Address**.
   - **Regulatory & Tax**: GST/PST numbers, plus the **GST/HST Exempt** / **PST Exempt** ticks and certificate.
   - **Billing Contact**: **Invoice Email** is the address batch invoicing uses first.
   - **Commercial Terms**: Currency, Mileage Unit, **Payment Terms** (e.g. "Net 30"), Credit Limit, Billing Cycle, Discount.
2. Press **Create Customer**.
3. Set **GPS Revenue Presentation** on the customer's **Overview** tab: **Regulatory** card → **Edit**, choose Net (Agent) or Gross (Principal).
4. **Portal access**: {{Users › Portal Users @/users?tab=portal}} → choose the customer → **Create & Invite**.
5. **Credit application**: customer → **Credit Application** tab → **+ Send Application**. When it comes back, {{Credit Applications @/credit_applications}} → **View** → set an Outcome in **Review**. Tick "Apply credit limit to customer record" if it should change the customer, then **Save Review**.

:::callout warning Customer rules that surprise people
- **Payment Terms does not set the due date.** Every invoice is due 30 days after its period start (Settings default). Payment Terms goes to QuickBooks as the customer's term when FleetForge creates the customer there.
- **There is no billing-address field on the customer forms.** The invoice PDF's "Bill To" and the QuickBooks billing address come from a stored billing address. Only imported customers have one today ([Known issues](sop:known-issues)).
- **Credit Limit and Credit Hold block nothing.** Batch invoicing still bills Credit Hold customers. Suspended or Inactive customers lose portal login.
- **Delete** appears only when the customer has no active leases. It never deletes the QuickBooks customer.
:::

## Equipment and rates

1. **Add a unit**: {{Equipment › New Unit @/equipment/create}}. Required: Equipment Type, Unit Number, Ownership. Add VIN, Year, Brand, Yard, Tracking Provider (Samsara + device), mileage and the compliance expiry dates, then **Register Unit**. The unit starts **Available**.
2. **Types, categories, brands**: {{Equipment › Equipment Type @/equipment/templates}} (**+ Add new equipment type**, **Manage Categories**, **Manage Brands**). The short-lease minimum switch lives on the category.
3. **Samsara**: open the unit → **Samsara Mapping** tab → choose the vehicle or trailer → **Link to Samsara**.
4. **Rate cards**: {{Rates › New Rate Card @/rates/create}}. Leave Customer blank for a general card, or pick one for a customer card. **+ Add Rate** per equipment type with daily, weekly and monthly rates, mileage rate, hourly rate, the GPS daily rate and minimum days, then **Create Rate Card**.

:::callout info How rates reach a lease
- **Where a new lease gets its rates**, in order: the customer's card (locked; **Unlock** to override), then the general card, then the equipment type's defaults.
- **Changing a rate card never re-prices existing leases** or sent invoices.
- **Unit status is set by the lease**: Reserved, then On Lease, then Available. Use the list's bulk bar for Maintenance or Inactive.
:::

## Leases

:::diagram lease-lifecycle A lease from reservation to return. Reopening is the fix for a wrong reading or date.

### Create

Start from {{Leases › New Lease @/leases/create}}:

1. Customer and an available unit. Leave Contract Number blank to auto-number.
2. Start Date, End Date (blank = open-ended), **Lease Start Time** (the cut-off for same-day returns), Billing Cycle (**Monthly** or **On Close Only**).
3. Rates:
   - daily, weekly and monthly are all-or-none;
   - all zero only for hourly-only or mileage-only leases;
   - add an hourly rate and minimum billing days if they apply.
4. Mileage:
   - km or miles;
   - the rate (required if you set an estimate, allowance or precharge);
   - **Estimated mileage per day** — billed each invoice and trued up later;
   - allowance, and the optional precharge.
5. Add-ons (insurance, warranty, GPS, cartage, estimated engine hours) and tax exemptions.
6. **Mileage Tracking**:
   - **Samsara** — GPS distance each period;
   - **Manual** — you enter odometer readings;
   - **Off** — no mileage billed, even with a rate. A red warning shows.
7. Starting Odometer. For a back-dated lease use **Manual** and type the true starting reading: Samsara would record today's odometer.
8. **Create Lease**. The lease is Pending and the unit Reserved.

### Activate

Press **Activate Lease** when the unit leaves. This creates a draft invoice for the rest of the start month, with cartage. Review it before sending: an early-month start bills the whole month ahead.

### During the lease

- Missing start reading: **Odometer & Distance** card → enter it → **Save** (or **Fetch from Samsara**).
- Manual mileage is entered on each invoice's **Odometer at Period End**. Mileage Logs keep history only; they bill nothing.
- Rate change: Rates card → **Amend Rates** → **Apply Amendment**. The next invoice re-prices the lease with a catch-up line.

### Close

Press **Close Lease** only when the unit is physically back:

1. **Actual Return Date** (billing stops here) and Return Time. A return after the lease start time bills one more day.
2. Closing odometer (or **Fetch from Samsara**) and, for hourly leases, engine hours.
3. Closeout charges: the **Sweep** and **Wash** amounts, and **Fuel (gallons)**.
4. Leave **Actual Mileage (for billing)** as filled unless correcting on purpose.
5. Precharge left over: **Apply as Credit** or **Cash Refund**. A cash refund later needs **Mark Refund Settled**.
6. **Close Lease**. This creates the final invoice and corrects over-billing:
   - any draft past the return date is rebuilt;
   - a sent invoice past it gets a credit note for the unused days.

### Fix a closed lease

Manager or Super Admin: **Reopen Lease** → give a reason → correct it (for example, set mileage mode to Manual) → close again with the right reading. Closing charges are rebuilt, not doubled.

:::callout warning Bulk close is not a real close
**Bulk close** (Leases list → tick → **Close leases**) sets today as the return date. It does not create final invoices, ask for readings, add closeout charges or refund precharges, and it skips leases that need those. Close those one at a time.
:::

:::callout info Lease rules
- A closing odometer below the starting one is refused.
- Nothing stops two leases overlapping on the same unit. Check before back-dating.
:::

## Damage claims

1. {{Damage Claims › New Claim @/damage_claims/create}}: unit, customer, severity, location, lease, description, estimate. Then **Create Claim** (status Reported).
2. Work it forward with **Change Status** → **Apply**: Reported → Assessed → Repair Ordered → Invoiced → Resolved. Any stage can go to **Written Off**.
3. **To bill the customer**:
   1. On a draft invoice, **Edit Line Items** → add a **Damage** line → send it.
   2. Move the claim to **Invoiced** and pick that **Recovery Invoice**.
4. **Written Off** closes the recovery invoice's remaining balance as bad debt: the invoice is closed, the customer's balance drops, and QuickBooks gets a credit memo. It fails with a clear message if Bad Debt Expense isn't set up. With no invoice, or a draft or paid one, only the claim status changes.
5. Only Reported and Assessed claims can be deleted. Resolved and Written Off are final.
