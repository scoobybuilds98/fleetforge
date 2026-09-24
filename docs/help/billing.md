---
description: Run each month's billing from preparation to close — readiness checks, meter readings, the workbench, review, approval, delivery and closing the month.
---

# Monthly Billing

Each month's billing is a **cycle**: one record per month that checks the month before anything is billed, collects the manual readings, bills from the workbench, flags anything unusual, tracks what reached each customer, then closes and locks the month.

Open it from **Billing → Monthly Billing** in the sidebar.

## Starting the month

1. On **Monthly Billing**, the card at the top shows the **cycle to work on now** — by default last month (billing in arrears; change it in **Billing → Settings**).
2. Click **Start the … cycle**, or **+ Open a month** to open any month (past months too — see *Working a draft backlog*).
3. The cycle page opens. The stepper under the header shows the seven steps — **Prepare → Readings → Generate → Review → Approve → Send → Close** — and which one you are on. Click a step to open its tab.

The **Open billing cycles** scheduled job does step 2 for you on the open day and tells the billing owner.

## Prepare — the readiness checks

1. Open the **Readiness** tab. The checks run straight away (**Re-run checks** after fixing things).
2. Work top-down:
   - **Blockers** (red) stop billing — for example a lease with no rate at all, or no US-dollar exchange rate on file. Fix them first.
   - **Warnings** (amber) will bill, but probably wrongly or without reaching the customer: earlier months never billed, readings missing, Samsara units not reporting, leases past their end date, customers with no email or a bounced one, missing PO numbers, expired tax exemptions, the month closed in the ledger, drafts from earlier months, failed QuickBooks pushes.
   - **To note** (blue) is information: customers billed by mail or portal, invoices that will already be past due when sent, leases that bill only at close, holds, credit holds, rate changes this month.
3. Click a check to see the leases or customers involved — each name links to the page where it is fixed.
4. If a warning is known and fine, click **Acknowledge — bill anyway** and say why. The acknowledgement is kept on the cycle.

## Readings — manual mileage and engine hours

Leases in **Manual** mileage mode (with a mileage rate) and leases with an **hourly rate** bill usage only from a reading.

1. Open the **Readings** tab. **Needs a reading** lists the leases still missing one.
2. Type each lease's odometer at the end of the month, in the lease's own unit (km or miles), and/or the engine hours. **Use Samsara …** fills in the unit's last Samsara odometer when there is one.
3. Click **Save readings**. A reading lower than the previous one is refused (the previous reading and where it came from are shown).

Generating the month in the workbench — and its dry run — passes these readings to each invoice exactly as the single-invoice form does. A reading entered after the invoice exists changes nothing; use **Regenerate from Lease** on the draft.

## Generate — the workbench

1. Click **Open workbench** (the cycle's header). It opens on the cycle's month.
2. Leases that are already billed are hidden (**Unbilled only**). Leases **on hold** are shown but never pre-selected; leases with a missing reading show **Reading needed**.
3. **Preview totals** runs a dry run — nothing is saved. Use **Hold for review** on anything that looks wrong.
4. **Looks right — Generate N** creates the drafts. Anything that could not be billed lands in **Billing → Exceptions**.
5. If **Require approval before batch billing** is on, **Submit for approval** replaces Generate; see *Approvals*.

Back on the cycle, the **Leases** tab shows every lease on rent that month and what happened to it: **Billed**, **Covered by another bill**, **On hold**, **Exception**, **Bills at close**, **Closed, unbilled** (use the lease's **Generate Invoice**) or **To bill**.

## Review

1. Open the **Review** tab. **Flagged** lists the invoices that need a look:
   - **Double billing** — another invoice for the same lease covers the same days.
   - **Double mileage** — a *Mileage usage* and a *Mileage overage* line for the same distance. Remove the overage line with **Edit Line Items**.
   - **Billed while held**, **Big change** against last month (thresholds in **Billing → Settings**), **$0 invoice**, **No tax**, **USD, no rate**, **No recipient**.
   - For information: credit lines, large usage true-ups, first invoices, several invoices for one lease.
2. Open an invoice (it opens in a new tab), fix it if needed, then **✓ Reviewed** — or tick several and **Mark reviewed**.
3. **Query…** marks an invoice for a second look with a note. A cycle cannot close while anything is queried.
4. **Billed last month, nothing this month** lists leases that dropped out of billing.

## Approvals

When approval is required, runs are submitted from the workbench and listed on **Monthly Billing → Approvals**.

1. Open a run. The figures are frozen — the approver signs off on exactly those numbers.
2. **Approve** or **Reject** (a reason is required). With self-approval off, someone else must approve.
3. After approval, **Generate N Invoices**. Anything billed since approval is skipped, and totals that changed are listed.
4. **Cancel run** withdraws a pending run (its submitter or an approver). A rejected or cancelled run can be **Resubmitted as a new run**, priced again from today's data.

## Send — the Delivery tab

1. Open the **Delivery** tab. Each invoice shows how it reached the customer: **Draft — not sent**, **Emailed**, **Sent, never emailed**, **Email failed**, **Email bounced**, **Print & mail** or **Portal / no delivery**.
2. Tick drafts and click **Send & email N draft(s)** (or **Mark N as sent (no email)**). Sending posts the revenue, adds the invoice to the customer's balance and queues QuickBooks.
3. For invoices already sent, tick them and click **Email N sent invoice(s)** — this only emails them again.
4. Type in the **Recipient** box to send to a different address this time only. **Attach the PDF** is on by default.

Send a large number of drafts in stages. The last email attempt and any error are shown per invoice.

## Close the month

1. Open the **Close** tab. It lists what must be fixed (**Must fix**: unsent drafts, pending runs, queried invoices) and what should be checked (**Check**: leases still to bill, open exceptions, readiness never run).
2. Add a **Close note** and click **Close and lock**. If there are checks, tick **Close anyway** and explain in the note.
3. Closing freezes the month's figures and stops the workbench billing that month. A lease's own **Generate Invoice** and a lease close still work; those invoices show as **Added after the cycle closed**.
4. Someone with invoice approval rights can **Reopen** the cycle (a reason is required).

The Close tab (**Summary** once closed) shows the month's figures — invoices, billed and collected so far, what was billed by category, per currency, the largest customers, the change against last month and how long the month took — and **Download billing register (CSV)**.

## Billing holds

A hold stops a lease — or every lease of a customer — being billed by the workbench and the monthly job until it is released. Billing is deferred, not forgiven.

1. **Monthly Billing → Holds → + New hold**, or **Hold** on a billing exception.
2. Pick **One lease** or **Every lease of a customer**, give the reason and the dates (leave **Until** blank to hold until released).
3. **Release** the hold when billing should resume.

## Working a draft backlog

**Monthly Billing → Cycles** lists months that have unsent drafts but no cycle. Click **Open cycle** on the oldest, review and send (or void) its drafts from the Review and Delivery tabs, close it, then move to the next month.

## Settings

**Billing → Settings** holds the cycle schedule (which month, the open day, review-by and send-by targets, the default owner), the review thresholds, **Require approval before batch billing** / **Allow self-approval**, and the **US-dollar exchange rate** (enter the day's USD→CAD rate before generating US-dollar leases). It links to the billing settings kept in their own modules: scheduled jobs, invoice numbering and terms, late fees, customer reminder emails and email templates.

---

<details>
<summary>Under the hood</summary>

- **A cycle owns no invoices.** An invoice belongs to the cycle of the month its billing period starts in (lease invoices only; late fees and credit notes are not part of the month's billing). Every way of creating an invoice lands in the right cycle.
- **Leases in the month** are active leases that started by the month end, plus closed leases whose return reaches into the month.
- **Readings** are stored in km and passed to the invoice only when the billed period ends on the month's last day.
- **Holds** are honoured by the workbench, the dry run, approved runs and the monthly invoice job (which does not advance past a held month). A lease's own Generate Invoice is not blocked; Review flags an invoice billed while held.
- **Closing** locks the workbench out of the month (generating, submitting a run, generating an approved run). The close snapshot keeps the figures as they stood.
- **Permissions:** viewing uses Invoices *view*; generating, readings and opening cycles need *create*; sending, reviewing, holds and closing need *edit*; reopening needs *approve*; the register export needs *export*. Amounts are shown only to roles that can see payments. Billing settings need the Settings (General) *edit* permission.

</details>

## Related

- [Invoices](/help/invoices)
- [Leases](/help/leases)
- [Payments](/help/payments)
- [Rates](/help/rates)
