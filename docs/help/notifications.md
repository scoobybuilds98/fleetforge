---
description: The bell — Needs attention (one shared item per problem, until it's fixed) and Updates (what's been happening).
---

# Notifications

Notifications are split into two lists so the important things don't get buried: **Needs attention** (problems someone has to deal with) and **Updates** (things that happened, for your information). The number on the bell counts only Needs attention.

## Reading the bell

1. The **number on the bell** is how many problems are open in the team's list. It's **red** when any of them is urgent, **amber** when they're all to-dos.
2. A small **dot** (no number) means there are new updates, but nothing needs anyone.
3. Click the bell. **Needs attention** opens first, with **Urgent** items at the top, then **To do**. Each item shows how long it's been open and who has it.
4. Switch to **Updates** to see activity: leases out and back, invoices created and sent, payments in. A burst, such as a Batch Invoicing run, is one line ("42 invoices created"). Opening the tab marks the updates read.

## Dealing with an item

1. Click **Open** to go to the customer, unit, application or request the item is about.
2. Click **Take it** so the team can see it's yours. Nobody else chases the same thing, and nobody assumes someone else has it.
3. Fix the problem in FleetForge (record the payment, renew the document, review the application, reply to the customer). **The item closes by itself** once FleetForge sees the fix.
4. If you dealt with it another way, click **Done** and write what you did (for example "Called, paying Friday"). The note is required while the problem is still there. It's done for everyone, and it only comes back if the problem gets worse (for example an invoice crossing from 30 to 60 days overdue).
5. Not today? Click **Snooze** and pick **Tomorrow**, **Next Monday**, **In a week** or a date. It comes back by itself at 8:00 that morning. If it gets worse in the meantime, it comes back straight away.

## The full list

1. Click **Open the full list** at the bottom of the bell, or go to **Notifications** (`/notifications`).
2. Filter to **Mine** or **Nobody on it**, by kind, or search.
3. Click **History** on an item to see everything that happened to it (who took it, notes, snoozes). From there you can **Give to** a colleague or **Add note** for the team.
4. **Snoozed** lists what's been pushed to a later date (**Bring back now**). **Closed** lists the last 60 days, with who closed each item and why; a done item whose problem is still there can be **Reopened**.
5. **Updates** has the full activity feed with filters.

## What counts as "Needs attention"

- **Urgent:** a credit application waiting for review, a customer's portal request or reply, a unit document (CVI or registration) that has expired, a broken QuickBooks connection, a customer 90+ days overdue, a sales-tax return due within 7 days.
- **To do:** customers with overdue invoices (one item per customer, with their risk rating and collections step), unit documents expiring within 30 days, reopened leases that need closing again, new damage claims, batch runs waiting for approval, billing running behind, customers whose emails bounced, QuickBooks sync failures or differences, red unit health, low GPS batteries, and the nightly balance check.
- **Everything else** is an update.

## Escalation

1. If an **urgent** item sits for 24 hours with nobody on it, it's marked **Escalated** and goes to the owner (super admins). This happens once per item.
2. Taking the item stops the clock.

## WhatsApp on your phone

1. Open your **Profile → Notifications** and scroll to **WhatsApp**.
2. Enter your WhatsApp number (with the country code, e.g. +1 604 555 0142).
3. Choose **Morning summary only**, or **Urgent items + morning summary**.
4. Pick when the summary arrives and your **quiet hours** (anything due then waits until they end).
5. Optionally tick updates you also want right away, such as **New leases** or **Payments received**.
6. Click **Save WhatsApp**, then **Send me today's summary** to see what it looks like.

Only urgent items and one summary a day come by WhatsApp; everything else stays in the app. An urgent item that's already been dealt with before it would have gone out is never sent late.

## Your own settings

1. Open your **Profile → Notifications**.
2. See which kinds of Needs attention your role gets, and untick any **Updates** categories you don't want.

→ Super admins choose which roles see each kind, how urgent it is, and the escalation time in **Settings → Notifications**.

---

<details>
<summary>Under the hood</summary>

- **One item per problem.** Items are keyed to their record (for example the customer or the unit). Hourly and nightly checks update the same item in place, so there are no repeats. The database allows only one live item per problem.
- **Closes itself.** Each kind knows what fixes it. The fixing action (a payment, a review, a reply, a renewed date) re-checks the item immediately, and an hourly sweep (`cron/attention_sweep.php`) catches anything missed. That same sweep brings snoozed items back and escalates urgent items nobody has taken.
- **Shared.** Take, give to, snooze, done and notes are team-wide and kept in the item's history with names and times.
- **Who sees what.** Visibility is decided by role (Settings → Notifications). Customer requests go to the people Portal & Requests routing names. Money amounts are hidden from roles that can't see payments.
- **Updates** are per person (your own read/unread), grouped when they arrive in bursts.
- **WhatsApp** uses Meta's official WhatsApp Business Platform with two approved message templates. Messages are queued and sent by a job that runs every minute (`cron/whatsapp_dispatch.php`), which respects quiet hours and retries temporary failures. Each person turns it on for their own number; super admins connect it and see the delivery log in **Settings → Notifications**. Amounts are left out for people who can't see payments.
</details>
