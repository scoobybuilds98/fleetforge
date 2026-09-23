---
title: Known issues and workarounds
short: Known issues
summary: Gaps that are still open, and how to work around each.
icon: exclamation-triangle
accent: danger
part: Reference
audience: dispatcher, manager, accountant, super_admin
reviewed: 2026-09-24
---
Writing this SOP meant checking every screen against the code. It found 27 gaps (I1–I27); all but one were fixed on 24 September 2026, and the chapters now describe the screens as they work. What is left is below. When one is fixed, it is removed here and the chapter that mentions it is updated.

:::filter Search the issues — e.g. "USD", "deposit", "sample"
## Still open

| # | Issue | Workaround |
| --- | --- | --- |
| I24 | The pay-online link has not yet been proven on a real invoice. QuickBooks only issues it once QuickBooks Payments is active on the company, and Intuit's test companies can't do that for a Canadian company (F80). | On go-live day, pay one invoice through its link and confirm FleetForge shows it paid ([Go-live day](sop:quickbooks-go-live)). |
| I28 | A customer deposit in USD would post its USD figure to the ledger unconverted. The Deposits screen only records CAD deposits, so staff can't reach this. | Record USD deposits as a payment against the invoice instead. |
| I29 | A customer deposit is applied whole: it can't be split across invoices, and it can't be applied to an invoice whose balance is smaller than the deposit. | Apply it to an invoice at least as large, or refund it and record payments. |
| I30 | A payment's **Deposited to** bank can't be changed after it is recorded. | Void the payment and record it again with the right bank. |
| I31 | The finance-lease (lessor) accounting settings still have no screen. They matter only if a lease is set up as a sales-type lease. | Ask IT before using lessor accounting. |
| I32 | **Send me a sample** on the Dunning letters card (Settings → Customer Emails) says no sample is available. | Generate a letter from Collections without emailing, and read the PDF. |
:::

:::callout info Fixed on 24 September 2026
Revenue mapping, asset purchase entries, vendor credits, GST/HST remittance and refunds, CAD ↔ USD transfers, year-end with December closed and Reopen / Unlock periods, FX revaluation, bank opening balances, reversal of automatic entries, payments to the right bank, the reconciliation formula, billing addresses, invoice write-off and recovery, damage repair costs (by design — the shop's bill posts them), unit costs on bills, picking an invoice for a deposit, moving a payment, cash refunds of credits, late-fee rules, QuickBooks sync and monitoring settings, the Chart of Accounts types, dunning emails honouring the customer-email switches, the QuickBooks credit rule, the webhook retry, the Drift message typo and the Help pages.
:::
