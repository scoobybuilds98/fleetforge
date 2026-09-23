---
title: Roles — who does what
short: Roles
summary: The five roles, what each one works on, and who can see money.
icon: user-group
accent: info
part: Start here
audience: dispatcher, manager, accountant, super_admin
reviewed: 2026-09-24
---
FleetForge has five roles. Money (rates, balances, amounts) is visible only to roles that can view payments, so dispatchers see statuses and dates but never dollar figures.

:::cards
### :shield-check: Super Admin
Everything, including users, settings and lockouts.

- **Money:** visible
- **Accounting:** full
- **QuickBooks:** connect, map, go-live, retries, reset
### :clipboard-document-list: Manager
Customers, leases, equipment, maintenance, invoices (create, send, approve batches), payments.

- **Money:** visible
- **Accounting:** view only
- **QuickBooks:** no
### :truck: Dispatcher
Leases, equipment, maintenance; creates customers.

- **Money:** hidden
- **Accounting:** none
- **QuickBooks:** no
### :calculator: Accountant
Invoices, payments, journal entries (post and approve), bills and bill payments.

- **Money:** visible
- **Accounting:** full
- **QuickBooks:** no, unless granted
### :eye: Read Only
Views everything except users and settings.

- **Money:** visible
- **Accounting:** view only
- **QuickBooks:** no
:::

:::callout rule The QuickBooks routine is a Super Admin job today
The QuickBooks chapters ([go-live](sop:quickbooks-go-live), [routine](sop:quickbooks-routine), [problems](sop:quickbooks-problems)) need QuickBooks access, which only Super Admins have by default. If the accountant should run them, a Super Admin grants the Accountant role *QuickBooks → view*, *force resync* and *force full resync* on {{Users › Role permissions @/users/role_permissions}}. The accountant then logs out and back in, because permissions are read at login.
:::

## At a glance

| Role | Works on | Money visible | Accounting | QuickBooks screens |
| --- | --- | --- | --- | --- |
| Super Admin | Everything, including users, settings, lockouts | Yes | Full | Yes — connect, map, go-live, retries, reset |
| Manager | Customers, leases, equipment, maintenance, invoices (create, send, approve batches), payments | Yes | View only | No |
| Dispatcher | Leases, equipment, maintenance; creates customers | No | None | No |
| Accountant | Invoices, payments, journal entries (post + approve), bills and bill payments | Yes | Full | No (by default) |
| Read Only | Views everything except users and settings | Yes | View only | No |

## Who does each routine

- **Dispatcher** — opens and closes leases, records mileage and hours readings, logs damage.
- **Manager** — reviews and sends invoices, records FleetForge-side payments and credit notes, chases overdue accounts.
- **Accountant** — bills and bill payments, journal entries, bank reconciliation, [month-end close](sop:month-end-close), GST/PST, the QuickBooks drift review (once granted access).
- **Super Admin** — QuickBooks connection and mappings, go-live, users and permissions, scheduled jobs.
