---
title: Known issues and workarounds
short: Known issues
summary: Gaps found while writing this SOP, most serious first, and how the SOP works around each.
icon: exclamation-triangle
accent: danger
part: Reference
audience: dispatcher, manager, accountant, super_admin
reviewed: 2026-09-24
---
Writing this SOP meant checking every screen against the code. The gaps below are real, and the chapters tell staff how to work around each one. They are grouped by how much they matter; the first group can make the books wrong. When one is fixed, it is removed here and the chapter that mentions it is updated.

:::filter Search the issues — e.g. "GST", "deposit", "write-off"
## Can make the books wrong — fix first

| # | Issue | Workaround |
| --- | --- | --- |
| I1 | Settings → Revenue Mapping has no effect. Posting reads a hidden map with no screen, so most revenue lands in 4110 (F85). | [Accounting setup](sop:accounting#setup); check QuickBooks → Items |
| I2 | Creating an asset, a betterment or an approved CapEx request posts no journal entry. The asset register and the balance sheet disagree. | Accountant enters the purchase entry by hand |
| I3 | A vendor credit left on "Auto (from AP)" posts DR AP / CR AP, so payables drift once it is applied. | Always choose an expense account |
| I4 | A GST remittance clears 2030 but never 1050, so input tax credits build up forever. Refund periods cannot be entered at all. | Clearing entry DR 2030 / CR 1050; refunds by journal entry |
| I5 | A bank transfer between CAD and USD accounts posts the same raw number on both sides. | Journal entry with CAD amounts |
| I6 | Year-end close requires December open, and there is no way to reopen a closed period. | Never close December by hand |
| I7 | FX Revaluation only posts into a closed period, so it effectively cannot post. | Adjusting journal entry |
| I8 | A bank account's Opening Balance field posts nothing to the ledger. | Opening journal entry |
| I9 | Reversing an automatic entry from the Journal Entries page leaves the source document unchanged, so ledger and documents disagree. | Void or credit the document instead |
| I10 | Every customer payment posts to the single default Cash / Bank account, whatever bank it went into. | Move it with a journal entry |
| I11 | The bank reconciliation Difference formula has not been proven against a real statement. | Stop and call IT if it looks doubled |

## Missing screens

| # | Issue |
| --- | --- |
| I12 | No billing-address field on the customer forms, so "Bill To" and the QuickBooks billing address are blank for customers not imported. |
| I13 | No Write Off button on an ordinary invoice, and no screen to record a recovery after a write-off (only the damage-claim route works). |
| I14 | Damage claims never post the repair cost; only the shop's bill does. |
| I15 | Bill lines have no equipment-unit field, so the Per-Unit P&L shows almost no direct costs. |
| I16 | Applying a customer deposit asks for the invoice's internal ID instead of letting you pick the invoice. |
| I17 | No screen to re-allocate a customer payment to a different invoice. |
| I18 | No way to refund a credit note as cash (only a lease precharge can be refunded). |
| I19 | Late fees: the "Apply late fees" job is on, but there is no screen to create a late-fee rule, so it never charges anything. |
| I20 | Several accounting settings have no screen, including the QuickBooks bank-sync and drift-check switches. |
| I21 | Chart of Accounts → Create Account refuses the Expense and COGS types. |

## QuickBooks-specific

| # | Issue |
| --- | --- |
| I22 | Collections → Dunning Letters → Generate & Send emails the customer immediately and ignores the customer-email master switch and the do-not-email list. |
| I23 | Unclear rule: F84 says credits are applied in QuickBooks, but FleetForge also pushes its own credit applications. The accountant must pick one side. |
| I24 | The pay-online link has not been proven on a real invoice (F80). |
| I25 | A "webhook replay" cron is referred to in the code but does not exist. |

## Small or cosmetic

| # | Issue |
| --- | --- |
| I26 | Drift page success message typo: "Drift event #N acceptd." |
| I27 | The in-app Help pages are out of date in several places: Send does not email, monthly billing is not automatic, precharge refund options, rate overrides and equipment templates are renamed. This SOP follows the screens as they are today. |
:::
