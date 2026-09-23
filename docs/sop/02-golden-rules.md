---
title: Golden rules — enter once, in the right system
short: Golden rules
summary: Every record has one home. Enter it there only; the other system gets a copy.
icon: scale
accent: primary
part: Start here
audience: dispatcher, manager, accountant, super_admin
reviewed: 2026-09-24
---
FleetForge runs rentals, billing and the per-unit books; QuickBooks holds the company books shared with the other businesses. Every record has one home. Enter it there only; the other system receives a copy automatically. Entering the same thing in both creates a duplicate that someone must unwind by hand.

:::diagram two-systems FleetForge sends what it owns; QuickBooks sends back the payments. Nothing is typed twice.

## Where each record lives

| Record | Enter it in | Copied to | Notes |
| --- | --- | --- | --- |
| Units, leases, mileage and hours readings | FleetForge | — | QuickBooks never sees them |
| Customers | FleetForge | QuickBooks, when FleetForge first needs them | Existing QuickBooks customers are linked, never renamed |
| Vendors | FleetForge | QuickBooks, when first needed | Same as customers |
| Invoices | FleetForge (create + send) | QuickBooks, when sent | Pre-go-live invoices are linked to the accountant's copy, never re-sent |
| Credit notes | FleetForge (create, apply) | QuickBooks credit memo; each application is sent too | Overpayment and rounding credits stay in FleetForge only. Apply credits in FleetForge, not QuickBooks. A cash refund of a credit is recorded in both (FleetForge does not send it) |
| Customer payments | **QuickBooks** (pay link, portal, deposits) | FleetForge (webhook + "Check QuickBooks for payments") | Never record the same cheque in FleetForge too |
| Bills (per-unit costs) | FleetForge | QuickBooks, when approved | The accountant stops entering these bills in QuickBooks |
| Bill payments | Either — but each payment in one place | Paid in QuickBooks → copied to FleetForge; paid in FleetForge → sent to QuickBooks | Vendor credits applied in QuickBooks must be repeated in FleetForge |
| Depreciation (rental fleet) | FleetForge (per unit) | QuickBooks, one journal entry per period | The accountant stops booking fleet depreciation in QuickBooks |
| Invoice write-offs | FleetForge (**Write Off** on the invoice) | QuickBooks credit memo applied to the invoice | Money recovered later: **Record Recovery** in FleetForge — it reaches QuickBooks as a journal entry |
| Manual journal entries | FleetForge | QuickBooks | Entries FleetForge makes for invoices, payments and bills are never sent twice |
| Chart of accounts, tax codes, items, terms | **QuickBooks** (the accountant owns them) | FleetForge maps to them | Changes: edit in QuickBooks, then Pull in FleetForge |
| Other businesses' records | QuickBooks | — | FleetForge ignores anything not tagged with the rental Class/Location |

## The other rules

:::cards
### :calendar-days: Dates before go-live never go to QuickBooks
Anything dated before the go-live day is refused as a new push. It is linked to the accountant's existing copy instead.
### :document-duplicate: A linked document belongs to the accountant
Editing or voiding a pre-go-live invoice, credit note or bill in FleetForge does not change QuickBooks; a drift alert asks you to make the same change in QuickBooks by hand.
### :banknotes: Payments from QuickBooks are applied in QuickBooks
FleetForge refuses to re-allocate them.
### :building-library: One QuickBooks company, several businesses
FleetForge tags everything it sends with the rental business's Class/Location and only raises drift about its own records.
:::
