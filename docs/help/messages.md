---
description: Text your team and your customers in one place — and drop any lease, invoice, payment or unit right into the message.
---

# Messages

Simple texting, split two ways: **Team** (you and your coworkers) and **Customers** (one thread per customer, shared with their portal users). Open it from the **chat bubble** in the top bar — the red number is how many messages you haven't read.

## Texting a teammate

1. Click the **chat bubble** in the top bar, then **+** next to **Messages**.
2. Stay on **Teammate** and click a name. If you've talked before, the same conversation opens — there's only ever one per pair.
3. Type and press **Enter** to send. **Shift + Enter** starts a new line.

## Starting a group

1. Click **+**, then **Group**.
2. Tick **two or more** people and give the group a name, e.g. *Yard crew*.
3. Click **Create**. Only the people in a group can see it.

## Texting a customer

1. Open the **Customers** tab and click **+**, then **Customer** — or open the customer's page and choose **More → Message customer**.
2. Each customer has **one** thread. Everyone on your team who can see customers shares it, so a colleague can pick up where you left off.
3. The yellow **Customer can see this** tag is a reminder: every message here shows up in the customer's portal under **Messages**, for all of their portal users.

## Sharing a lease, invoice, payment or unit

1. In any conversation, click the **paper clip** next to the message box.
2. Pick the type — **Lease**, **Invoice**, **Payment**, **Unit**, **Customer**, **Reservation**, **Work order** or **Damage claim** — and search by number, company or unit.
3. Click a result to add it (up to 5 per message; click again to remove). Add a note if you like, then send.
4. Shortcut: on an invoice, lease or payment page choose **More → Send in chat**. The record is attached for you — pick who to send it to.

The card in the message is **live**: it always shows the record's current status (an invoice sent last week reads **Paid** once it's paid) and clicking it opens the record.

## What customers can see and send

- In a customer thread you can only attach **that customer's** leases, invoices and payments — and only ones they can already see in their portal (no internal draft invoices, nothing voided).
- Customers reply from **Messages** in their portal and can attach their own leases, invoices and payments. **Message us** on a portal invoice or lease page starts a message with it attached.

## Seeing when your message was read

Under your newest message you'll see **Sent** until the other side opens the conversation, then **Seen**. It updates by itself while you watch.

- **Direct message:** **Seen** once your teammate has read it.
- **Group:** **Seen by Mike and Sara**, then **Seen by everyone** once all members have read it.
- **Customer thread:** **Seen by Dana** — the customer's portal users who have read it. It shows under a colleague's reply too, so anyone can check whether the customer saw it.
- Customers see **Seen** on their own message once someone on your team reads it (your staff aren't named).
- The receipt moves away once the other side replies. Deleting a chat doesn't count as reading it.

## Deleting a chat or leaving a group

1. Open the conversation and click the **trash icon** at the top right.
2. For a direct message or a customer thread, choose **Delete chat**. It disappears from **your** Messages only — the other person, the customer and your teammates keep every message. If anyone writes again, it comes back with just the new messages.
3. For a group, the button is **Leave group**. You stop getting its messages and it leaves your list; the others carry on. When the last person leaves, the group is deleted.

## Unsending a message

Hover your own message and click **Unsend**, then **Unsend?** to confirm. The text and any attached records are removed; a small "Message unsent" note stays so the conversation still reads in order.

---

<details>
<summary>Under the hood</summary>

- **One system** — replaces the old Team Chat channels, the separate Messenger inbox and the mini chat widget (S-CHAT-REBUILD). Tables: `conversations` (kind `direct` / `group` / `customer`), `conversation_members`, `conversation_messages`, `conversation_message_records`, `conversation_reads`.
- **Who sees what** — direct messages and groups: members only. Customer threads: every staff user with **Customers → View**, plus every active portal user of that customer.
- **Records are stored as type + id only.** Title, status and amount are looked up when the message is shown, for the person looking (`lib/Chat/RecordRefs.php`). Staff without **Payments → View** see cards without amounts and payment cards as *Not available*. A deleted record, or one you can't open, reads *Not available*.
- **Unread** — Team counts messages from others you haven't opened; Customers counts only the customer's messages (a colleague's reply isn't news to you). Opening a thread marks it read and clears its bell notification.
- **Notifications** — at most one unread bell notification per conversation, however many texts arrive. Customers get a portal notification for new staff messages. Customer texts notify the staff who have already replied in that thread; everyone else sees the Chat badge.
- **Seen** — built from the same read marks as the unread badge (`conversation_reads.last_read_message_id`); no extra tracking. A conversation counts as read when it's open on screen (it refreshes every 4 seconds while the tab is visible).
- **Delete chat is per person** — it stores how far *you* deleted (`conversation_reads.cleared_message_id`); nothing is removed for anyone else, so a customer's record of the conversation is never lost. Groups are left instead; the last member leaving deletes the group and its messages.
- **Refresh** — an open conversation checks for new messages every 4 seconds and the list every 15 seconds, and both pause while the browser tab is in the background.

</details>

## Related

- [Customers](/help/customers)
- [Invoices](/help/invoices)
- [Leases](/help/leases)
- [Payments](/help/payments)
