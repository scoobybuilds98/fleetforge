---
title: Open decisions before go-live
short: Open decisions
summary: Seven decisions for the owner and the accountant — not code — before QuickBooks goes live.
icon: chat-bubble-left-right
accent: purple
part: Reference
audience: super_admin, accountant
reviewed: 2026-09-24
---
These need the owner and the accountant, not code. Each is written up in full in the operator follow-ups list (the F-number).

- [ ] **F84 — Process rules, in writing.** Rental invoices and credit notes are created in FleetForge only. Customer payments are recorded in QuickBooks only. Bills live in one place, with each bill payment in one place. The accountant stops booking fleet depreciation in QuickBooks. Someone checks QuickBooks → Drift weekly.
- [ ] **F85 — Revenue account plan.** FleetForge books all rental revenue (and mileage usage, engine hours, cartage, sweep, wash, fuel) to 4110 Other Revenue, while QuickBooks items all post to one Sales account. Decide one of: one Sales account, and point FleetForge's revenue mapping at it; separate accounts per kind of revenue, matched in QuickBooks items and linked in FleetForge; or splitting rental revenue by equipment category, which needs a small code change.
- [ ] **F82 — Multicurrency.** Decide whether any customer or vendor will be billed in USD. Turning multicurrency on in QuickBooks can never be undone; decide before the first push.
- [ ] **F83 — Pre-go-live receivables.** Confirm whether the 165 invoices dated Sep 1 were entered in QuickBooks: if yes, link them; if not, set "Push transactions dated from" to 2026-09-01. Then either mark sent or void the 30 July drafts QuickBooks shows as paid. Have the accountant review the 11 unmapped accounts and 28 items.
- [ ] **F80 — Prove on the real company.** On go-live day, test one customer paying through the QuickBooks pay link, and one GST remittance journal entry.
- [ ] **QuickBooks access for the accountant.** Decide whether the accountant runs the QuickBooks routine (grant access on {{Users › Role permissions @/users/role_permissions}}) or a Super Admin does.
- [ ] **F81 — Go-live checklist.** Run the [go-live day](sop:quickbooks-go-live) chapter in order.

:::callout info Where these are tracked
The F-numbers are the operator follow-ups list the developer keeps. When a decision is made, tell the developer so the list — and this SOP — can be updated.
:::
