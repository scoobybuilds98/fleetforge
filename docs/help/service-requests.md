---
description: Read and reply to service requests customers submit through the portal — lease extensions, damage reports, billing inquiries, and more — and track them from open to closed.
---

# Service Requests

A shared inbox for everything your customers send in from the portal. Read each request, reply in a back-and-forth thread, and move it from open to closed — the customer is kept in the loop automatically.

## What a service request is

A service request is a message a customer submits through the **customer portal**. There is no way to create one from the admin side — staff read, reply, and resolve them. Each request carries a **Type**, a **Subject**, a message, and an optional link to one of the customer's leases or equipment units.

When a customer fills out **Submit a Request** in the portal, the request lands here in status **open** and the staff routed to that request type get an in-app notification.

## Reading the dashboard

When you open **Service Requests** in the sidebar, four tiles run across the top — a live count by status:

- **Open** — new requests no one has resolved yet.
- **In Review** — requests a staff member is actively working.
- **Resolved** — requests answered and marked done (the customer can still reply).
- **Closed** — requests finished and put to bed.

Below the tiles is a **Status:** filter row. Click a pill — **open**, **in_review**, **resolved**, **closed**, or **all** — to filter the table. The list opens on **open** by default.

The table shows one row per request with these columns:

| Column | What it shows |
|--------|---------------|
| **#** | The request ID. Click it to open the request. |
| **Type** | The request type the customer picked (see the table below). |
| **Customer** | The company that submitted it — click through to the customer record. |
| **Submitted By** | The portal user's name and email. |
| **Subject** | The customer's one-line summary. Click it to open the request. |
| **Lease / Unit** | The linked lease contract number and/or equipment unit, if the customer attached one. |
| **Assigned** | The staff member who last replied (set automatically on reply). |
| **Status** | Current status badge. |
| **Created** | When the request came in. |

Rows are newest-first, 25 per page. Use **Prev** / **Next** at the bottom to page through.

## Opening and replying to a request

The detail view is reached by clicking a request — there is no separate open button.

1. Click the **#** number or the **Subject** of any row to open it.
2. At the top you'll see reference cards for the **Customer**, **Submitted by** portal user, and — if the customer attached them — the **Lease** and **Equipment**. Each links out to the full record.
3. Read the **Subject** and the original message, then scroll to the **Conversation** thread. The customer's first message sits at the top; replies stack below it, with **Customer** and **Staff** badges so you can tell who said what.
4. In **Your reply**, type your response to the customer.
5. Set **Status after send** to **Open**, **In Review**, **Resolved**, or **Closed** — whichever reflects where the request now stands.
6. Click **Send Reply**.

Your reply is added to the thread and the customer is notified ("Reply sent. Customer notified."). If you changed the status, the customer is told that too.

> **The button stays disabled until there's something to send.** You must either type a reply or change **Status after send** — an empty save with no status change does nothing and is rejected. Click **Back to list** to return without sending.

## Changing status without replying

You don't have to write a message to move a request along:

1. Open the request.
2. Leave **Your reply** empty.
3. Pick a new value in **Status after send** (it must differ from the current status).
4. Click **Send Reply**.

The status flips and the customer is notified of the change. Marking a request **Resolved** or **Closed** stamps a resolved time on it.

## When a customer replies back

A service request is a two-way conversation, not a one-shot ticket.

- A customer can reply to their request from the portal at any time, even after you've resolved or closed it.
- A customer reply to a **resolved** or **closed** request automatically **re-opens** it back to **open** so it resurfaces in your queue — and the routed staff are notified again.
- Customers can also click **Mark as Closed** on their end once a request is **resolved**.

## Who gets notified — routing

Each request type can notify a different set of staff. If you have settings access, click **Configure routing** (top right of the list) to open **Service Request Notification Routing** in Settings.

- For each request type, tick the **Roles** and/or **Specific users** who should be notified.
- Routing is a union of roles plus users. If a type has nothing configured, the **Default fallback** bucket applies.
- Leave **Always include super_admin users** on (recommended) so a misconfigured type never silently drops a request.

## Request types

These are the seven types a customer can choose on the portal form. **Damage Report** and **Early Return Notice** notify staff at a higher severity since operations usually needs to react fast.

| Type | What it means |
|------|---------------|
| **Lease Extension Request** | Customer wants to keep equipment past the current lease end. |
| **Early Return Notice** | Customer intends to return a unit ahead of schedule. |
| **Damage Report** | Customer is reporting damage to a unit. |
| **Billing Inquiry** | A question about an invoice or charge. |
| **Document Request** | Customer is asking for paperwork. |
| **New Lease Inquiry** | Interest in starting a new lease. |
| **General Question** | Anything that doesn't fit the buckets above. |

## Status reference

| Status | Meaning |
|--------|---------|
| **open** | New, or re-opened by a customer reply. Awaiting staff action. |
| **in_review** | A staff member is working it. |
| **resolved** | Answered and marked done. A customer reply re-opens it. |
| **closed** | Finished. A customer reply still re-opens it. |

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Origin** — Requests are created only from the portal (`app/portal/requests/create.php`), inserted into `portal_service_requests` at status `open`. There is no admin create path; the admin module is read-and-reply triage. Read access is gated on the `customers` **view** permission; replying needs `customers` **edit**.
- **Conversation thread** — Replies live in `portal_service_request_messages`, fetched by `RequestMessageService::fetchThread()`. Each row is tagged `sender_type` `admin` or `portal`. The original submission renders as the first thread item from the request's own `message` field.
- **Admin reply** — `Send Reply` posts to `api/v1/requests/respond.php` → `RequestMessageService::appendAdminMessage()`, which inserts the message, stamps `assigned_to` to the replying user, mirrors the latest body into the legacy `response` column, flips `status` if changed, sets `resolved_at` when moving to `resolved`/`closed` (and clears it when moving back to `open`), writes an audit-log entry, and notifies the customer's portal users (best-effort).
- **No-op guard** — An empty reply with no status change returns `NO_CHANGE` (422); the Send button is disabled client-side to match.
- **Customer reply re-opens** — `api/v1/portal/requests/reply.php` → `appendPortalMessage()` re-opens any `resolved`/`closed` request to `open` and re-notifies the routed admins. Ownership is enforced (Trap-8) via the portal user's `customer_id`.
- **Routing** — `PortalRequestNotifier::resolveRecipients()` reads `portal_requests.routing.{type}.role_slugs` and `.user_ids` from settings, unions them, falls back to the `default` bucket when a type is empty, and (by default) always adds active `super_admin` users as a safety net. The same routing applies to the original submission and to every later customer reply.
- **Customer / lease / equipment links** — `customer_id`, `lease_id`, and `equipment_unit_id` are optional foreign keys the customer sets at submit time; the customer can only attach their own active leases and units. They surface as the reference cards and the **Lease / Unit** column.
- **Notifications** — Built on `NotificationService`; all dispatch is best-effort and never blocks the request insert or the reply. Severity is `warning` for `damage_report` and `early_return`, `info` otherwise.

</details>

## Related guides

- [Customers](/help/customers)
- [Leases](/help/leases)
- [Equipment](/help/equipment)
