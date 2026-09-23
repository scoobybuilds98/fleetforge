---
title: QuickBooks — how the connection works
short: QuickBooks: how it works
summary: What travels to QuickBooks, what comes back, and the guard rails in between.
icon: arrow-path
accent: success
part: QuickBooks
audience: accountant, super_admin
reviewed: 2026-09-24
---
FleetForge is the source for rental documents and sends them to QuickBooks through a queue; QuickBooks is the source for customer payments and sends them back through a webhook. Nothing moves until a Super Admin turns master sync on.

:::diagram qbo-sync Read it left to right: FleetForge queues each change, a background worker sends it, and QuickBooks' webhook reports payments and changes back.

## How a document reaches QuickBooks

Sending an invoice, approving a bill or saving a credit note adds a job to {{QuickBooks › Sync Queue @/quickbooks/sync_queue}}. The worker sends it and records the result in {{QuickBooks › Sync Log @/quickbooks/sync_log}}; the document's QuickBooks page shows its push status (pushed, failed, blocked).

- A job that fails because of a network or QuickBooks outage is **retried automatically**.
- One that fails a check (an unmapped customer, a missing tax code) **waits** until someone fixes the cause and presses **Retry** ([Problems & fixes](sop:quickbooks-problems)).

## How a payment reaches FleetForge

When a customer pays through the QuickBooks pay link or portal, or the accountant records a deposit against a FleetForge invoice, QuickBooks notifies FleetForge. FleetForge records the payment, marks the invoice paid and posts its own books. Bill payments the accountant makes against FleetForge bills come back the same way.

If a notification is missed, FleetForge notices by itself: every 10 minutes it asks QuickBooks for payment changes and brings in any it hasn't seen. **Check QuickBooks for payments** ({{QuickBooks › Invoices @/quickbooks/invoices}}) does the same for every open invoice at once, on demand.

## What a QuickBooks invoice carries

Every invoice FleetForge sends fills in:

- the customer (linked or created), the invoice number, invoice date and **due date**;
- the **payment term** matching the due date (Net 15, Net 30… — QuickBooks needs a term with that many days, otherwise the invoice goes without one and the due date still applies);
- the customer's **billing email** (QuickBooks never emails it — FleetForge does);
- the **Bill To** address when the invoice has one (otherwise QuickBooks uses the customer's);
- the **PO number** in QuickBooks' own P.O. field when the accountant has a sales-form custom field named like "P.O. Number"; it is also in the message;
- a customer message with the unit, contract, PO and invoice notes;
- every line with its item, description, quantity, rate, service date and tax code;
- the rental business's Class / Location, and the online-payment switches when QuickBooks Payments is on.

## Linked vs pushed

:::cards
### :paper-airplane: Pushed
Created in QuickBooks **by FleetForge**, which keeps it up to date: edits and voids flow through.
### :paper-clip: Linked
Already existed in QuickBooks **before go-live**. FleetForge only points at it and never changes it; a change you make in FleetForge raises a drift alert telling you to repeat it in QuickBooks.
:::

## Guard rails already built in

- Nothing dated before go-live is ever sent as new.
- Customers and vendors that existed before go-live are never created in QuickBooks unless someone chooses **Create in QuickBooks**.
- Automatic matching links exact names only; close matches are suggestions a person confirms.
- A customer who is also a vendor is created as "ACME (Vendor)", and a same-kind name clash asks for a link instead.
- Canadian GST/PST is sent per rate, and every pushed invoice's total is checked against FleetForge's. A difference raises drift.
- A difference of 5¢ or less, caused by per-line tax rounding, is settled automatically once QuickBooks shows the invoice paid.
- Connecting to a different QuickBooks company blocks all syncing until a Super Admin resets the mappings.
