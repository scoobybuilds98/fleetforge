---
title: QuickBooks problems and fixes
short: Problems & fixes
summary: What you see, why it happens, and what to do — searchable.
icon: wrench-screwdriver
accent: danger
part: QuickBooks
audience: super_admin, accountant
reviewed: 2026-09-24
---
Nearly every blocked item names its own fix in the error text. **Fix the cause first, then press Retry.** Retrying without a fix just blocks again.

:::filter Type what you see — e.g. "pre-flight", "drift", "payment"
| What you see | Why | What to do |
| --- | --- | --- |
| Invoice **Pre-flight Block**: customer has no mapped QBO customer | The customer isn't linked yet | {{Customers @/quickbooks/customers}} → link it (or **Create in QuickBooks**), then **Retry** the invoice |
| Invoice blocked: line type has no mapped QBO item | A new kind of charge | {{Items @/quickbooks/items}} → link or **Create QBO Item**, then **Retry** |
| Invoice blocked: tax rate not mapped to a QuickBooks code | Per-rate tax needs every rate mapped | {{Tax Codes @/quickbooks/tax_codes}} → map the rate, then **Retry** |
| Blocked: "dated before QuickBooks go-live" | It is history QuickBooks already has | Link it (go-live panel on {{Invoices @/quickbooks/invoices}}). Use **Push as new** only if QuickBooks truly lacks it |
| Customer or vendor won't push: "existed in FleetForge before QuickBooks go-live" | It is probably already in QuickBooks under another spelling | **Customers**/**Vendors** → **Link to QBO…**, or **Create in QuickBooks** if it isn't there |
| "QuickBooks already has a vendor (or customer) named …" | The same record exists, maybe inactive | Link it on **Vendors**/**Customers** (make it active in QuickBooks first) |
| Name shows as "ACME (Vendor)" in QuickBooks | The customer side already uses the name | Nothing — by design; cheques still print "ACME" |
| A payment made in QuickBooks isn't in FleetForge | Webhook missed, or the invoice isn't linked | **Invoices** → **Check QuickBooks for payments**. Still missing: link the invoice, then use its **Payments** button |
| "…is '&lt;status&gt;' in FleetForge — send it (or void it)…" when importing payments | QuickBooks has a payment on an invoice that is still a draft in FleetForge | Mark it sent in FleetForge (or void it), then check payments again |
| A bill paid in QuickBooks still shows unpaid; drift says the account "is not linked to a FleetForge bank account" | The pay-from account isn't mapped | {{Bank Accounts @/quickbooks/bank_accounts}} → **Link to QBO…**, then **Check QuickBooks for payments** |
| Drift "Update QuickBooks by hand" | A linked (pre-go-live) document was edited or voided in FleetForge | Make the same change in QuickBooks, then **Resolve** |
| Drift "Changed in QuickBooks — needs attention" | Someone edited, voided or applied a credit in QuickBooks to a FleetForge document | Decide which side is right; repeat the change in FleetForge (for example, apply the vendor credit), then **Resolve** |
| Drift: invoice total differs in QuickBooks after a push | Tax setup differs from FleetForge's | Compare the tax lines; fix the mapping on **Tax Codes**; **Resolve** |
| Invoice stays "partially paid" by 1–5¢ | FleetForge rounds tax per line, QuickBooks per invoice | Settles itself when QuickBooks shows it paid; run **Check QuickBooks for payments** |
| Write-off shows **Blocked** | Bad Debt Write-off item unmapped, or the invoice isn't in QuickBooks | Map the item on **Items** (Pull first), or link the invoice; then **Retry** |
| USD invoice blocked: currency mismatch | The QuickBooks company is single-currency | F82 decision; retry afterwards |
| Queue rows **skipped** (sync_mode_off / dry_run) | Master sync off, or Dry Run on | {{Settings › Master Controls @/quickbooks/settings}}; then **Retry Selected** |
| Connection *expired* or *error* | Refresh token lapsed or was revoked | {{Settings @/quickbooks/settings}} → **Connect to QuickBooks** |
| Red "Sync blocked — different QuickBooks company" | Connected to another company than the one mapped | Super Admin: **Reset mappings for this company**, then map again ([go-live](sop:quickbooks-go-live#map)) |
| Accountant can't see the QuickBooks menu | Not granted by default | Super Admin grants it on {{Users › Role permissions @/users/role_permissions}}; the accountant logs out and back in |
| Yellow "Income accounts don't line up" on **Items** | QuickBooks items and FleetForge revenue accounts differ | F85 decision ([Open decisions](sop:open-decisions)) |
:::
