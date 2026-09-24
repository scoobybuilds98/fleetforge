---
description: What every customer pays — standard prices for everyone, customer rate cards for negotiated deals, a price check that shows which price a new lease gets, and changing prices from a date.
---

# Rates

Rates is where you set what you charge. It answers three everyday questions:

- **What does this customer pay?** — for every piece of equipment, and how that compares with your standard price.
- **What would a new lease get?** — the Price check shows the exact price a new lease would pre-fill, where it comes from and why, and estimates a rental with the same rules invoicing uses.
- **How do I change prices?** — pick the date the new prices start. The old prices end the day before and stay on record as history.

All prices live on **rate cards**. A card is either for **one customer** (their negotiated prices) or for **everyone** (a standard price list). Each card holds **lines** — one per equipment category or per equipment type — with daily, weekly and monthly prices, a distance price, an engine-hours price, a GPS price per day and a minimum number of days. A line can also carry **only a minimum** (no prices) — see *Lines that set only a minimum* below.

---

## The Rates page

**Key numbers** across the top — click one to open the matching view:

- **Customer deals** — customers with their own prices today, and how many cards they hold.
- **Standard prices** — how many equipment types have a price for customers without a card.
- **Ending in 30 days** — cards whose end date is close. Renew them with **Change prices**.
- **Needs a look** — problems to fix before they reach a lease.

**Needs a look** lists, most urgent first:

- a line with **no prices and no minimum** (it does nothing), a line with no prices that only switches the minimum **off** (0 or 1 day — worth a second look), or a line with only some of daily / weekly / monthly (a lease from it could not be saved as-is);
- a card with **no lines** at all;
- prices **ending soon**, and customer prices that **ended without a renewal** while the customer still rents;
- **customers on rent with no card of their own** — their prices live only on each lease (use **Create their card**);
- cards for **archived customers**, and **new prices starting** within two weeks.

Below are four views:

| View | What it shows |
|------|---------------|
| **Customer prices** | One row per customer with their own card, with their prices on the row. Click a row to see every line: each price, how it compares with the standard price (green = below, amber = above), the card it comes from and its dates. Search, filter by status or equipment, and sort by name, ending first or most on rent. |
| **Standard prices** | What a customer *without* their own card pays for each equipment type, where that price comes from (a general card, or the equipment type's own prices), how many customers have a deal on it, and how many units are on rent. **Edit** changes the equipment type's own prices in place. **Standard price sheet (PDF)** prints the list. |
| **All rate cards** | Every card with who it is for, what it covers, its dates and status. Tick cards to **Change prices** on all of them at once, or **Delete**. |
| **Price check** | Pick a customer (or none, for the standard price) and an equipment type. See the price a new lease would pre-fill, which card it comes from, how it compares with the standard price, and the three steps that decided it. Tick **Estimate a rental** and choose dates (plus distance, engine hours and GPS) to see what it would bill before tax. |

**Export** downloads every line in force today as a spreadsheet (roles with export permission).

---

## Setting up prices for a customer

1. Click **+ New rate card** (on Rates, or on the customer's **Rates** tab — the customer is then already chosen).
2. **Who are these prices for?** Choose **One customer** and pick them. You'll see the cards they already have.
3. **Which equipment?** Tick an **equipment type** (one model) or a **whole category** (every type in it). Each option shows what the customer pays today. **Pick what they rent** ticks every type they have on rent.
4. **Prices** — each line starts from today's price, so you only change what is different. **Use their lease prices** copies the prices on their current leases. **Adjust** raises or lowers every daily, weekly and monthly price by a percentage, rounded to the cent, $1 or $5.
5. **When?** Starts today by default. Choose an end date (6 months, 1 year, end of year) or leave it open-ended.
6. **Name it** — a name is suggested from the equipment and customer; change it if you like.
7. The summary on the right says in plain words what will change and checks everything as you type. Click **Create rate card**.

> **Rules the check enforces:** daily, weekly and monthly go together — set all three, or leave all three blank for an engine-hours-only or distance-only line. A customer can't have two cards in force at the same time for the same equipment. Minimum days is a whole number from 0 to 90. Card names are unique.

A **standard price list** is the same, with **Everyone** in step 1. Tick **Make this the main price list** if it should win when two standard lists price the same equipment.

**Duplicate…** (in a card's **More** menu) starts a new card from that card's lines — handy for giving another customer the same deal.

---

## A rate card

The card page shows:

- **Prices** — one table for every line. On a customer card, each price shows how it compares with the standard price. A red note under a line means it would pre-fill a lease wrongly.
- **Details** — name, who it is for, dates, notes.
- **Leases on these prices** — the leases on rent that this card prices today. Any lease on **different prices** (usually created before a price change) is flagged with the difference. A card never changes a lease that is already out — use **Amend rate** on the lease to move one.
- **Price history** — each line's price over time across the customer's cards, and every change to this card with who made it (e.g. *Daily $45.00 → $50.00*).

**Edit** makes the prices and details editable together (the side panel steps aside to give the table room). Changed prices are highlighted; the bar at the bottom shows **Unsaved changes** — click **Save changes** or **Discard**. You can add a line, remove one, **Adjust rent prices** by a percentage, or **Fill blanks from standard**.

**More** menu: **Duplicate…**, **Rate sheet (PDF)** for the customer, **Price check for this customer**, **End these prices…**, **Make this the main price list** (standard lists only), **Delete this rate card**.

---

## Changing prices from a date

Use **Change prices** on a card (or **Renew prices** on an ended card), or tick several cards on **All rate cards** and choose **Change prices…**.

1. Choose when the **new prices start** (1st of next month, tomorrow, or any date after the card started).
2. Either **raise or lower by %** (rounded to the cent, $1 or $5; optionally also distance, engine hours and GPS), or **type the new prices** (single card).
3. Choose when the new card ends — **the same end date as before**, open-ended, or a date — and optionally a name and a note for the history.
4. Click **Preview**. You see every line before and after, and exactly what happens to each card. Nothing is saved yet.
5. Click **Save new prices** (or **Change prices on N cards**).

What happens: each card keeps its prices until the day before the new ones start; a new card with the same customer carries the new prices from that date (named "*old name* · from *Mon YYYY*" unless you name it). The main-price-list flag moves to the new card. Both cards stay on record and point at each other in their history. **Leases already on rent keep their prices.**

When several cards are changed together, it is all-or-nothing: if any card can't change as asked (for example another card already covers that equipment from that date), the preview says why and nothing is saved until every card can.

---

## Ending or deleting prices

- **End these prices…** sets the last day the card is in force. From the next day, new leases get the next price in line (usually the standard price). The card stays as history.
- **Delete this rate card** removes it from use. Leases on rent keep their prices. The main price list can't be deleted — make another list the main one first.

---

## How a new lease picks its price

When you choose a customer and a unit on a new lease, FleetForge looks for a price **in this order** and uses the first it finds:

| Order | Source | Banner on the lease form |
|-------|--------|--------------------------|
| 1 | **The customer's own card** — a line for that equipment, in force today | *Contracted rates — {type} rate · custom card "{card}"* (prices locked; **Unlock** to change) |
| 2 | **A general card** (standard price list) | *{type} rate · card "{card}"* |
| 3 | **The equipment type's own prices** | *{type} rate · template default* |

If none matches, the fields stay empty. Within a step, a line for the **exact equipment type** beats a whole-category line, then the **main price list** wins, then the card that started most recently (then the newest card). The **Price check** shows this decision for any customer and equipment, step by step.

### Lines that set only a minimum

A line with a **minimum number of days but no prices** never decides the price. Its minimum is kept, and the prices come from the next line in the order above that has prices, or from the equipment type's own prices. For example, the standard price list's Chassis line sets a 3-day minimum and no prices, so a customer without a chassis card of their own gets the 40' Tridem Chassis type's $50 / $300 / $650 **with a 3-day minimum**. The banner and the Price check name both sources: *40' Tridem Chassis rate · template default · 3-day minimum from card "Chassis Minimum Days"*.

- A line **with any price** (even just GPS or engine hours) still decides the price on its own. Prices are never mixed from two lines.
- When several lines set a minimum, the one **highest in the order** wins, as long as it is at or above the line that sets the price. A customer's minimum-only line therefore beats the minimum on a general card. A minimum-only line *below* the priced line has no effect.
- A minimum of **0** counts: it switches the minimum off for that equipment.
- A line with **no prices and no minimum** is skipped. **Needs a look** lists it.

If a lease can't be billed because its equipment has no price, the lease form links straight to the card (open in Edit) — or to **New rate card** with that customer and equipment already picked.

> **Tip:** pre-filled prices are a starting point — you can type over them on the lease before saving.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Tables** — `rate_cards` (`customer_id` NULL = standard / general card, `is_default` = main price list, `effective_from`/`effective_to`, soft-deleted via `deleted_at`) and `rate_card_items` (one row per line: `equipment_type` category slug, optional `equipment_template_id`, `daily_rate`, `weekly_rate`, `monthly_rate`, `mileage_rate` + `mileage_unit`, `hourly_rate`, `gps_price`, `minimum_days`, `currency`). No schema change in S-RATES-MODULE.
- **One resolver** — `lib/RateCards/RateResolver.php` decides the price; the lease form's `api/v1/leases/lookup_rates.php`, the Price check, "what they pay", "leases on these prices" and the rate sheet all call it, so they always agree. Final tie-breakers `rc.id DESC, rci.id DESC` make an exact tie deterministic (newest card wins).
- **Minimum-only lines** (S-RATES-MINIMUM-OVERLAY) — `RateResolver::priceWinner()` is the first candidate where `hasPrices()` is true (any price column above $0); `minimumIndex()` is the first candidate at or above it with `minimum_days` set (0 counts). `resolve()` returns the price winner's prices (or the type defaults / none) with that minimum. The response keys are unchanged; `source_label` gains *· N-day minimum from card "X"* when another card supplied the minimum, and `explain()` adds `minimum_card_id` / `minimum_card_name` plus each candidate's `priceless` and `used_for`. "Leases on these prices" counts a lease against the card that sets its price, never a minimum-only card.
- **Estimates use the billing law** — `RateResolver::quote()` runs `HolisticLeaseEngine::cumulativeCorrect()`; the minimum-days floor binds only when the equipment's category enforces minimums (`equipment_categories.enforce_minimum_billing_days`).
- **Line rules** — `lib/RateCards/RateCardItems.php` (shared by create, update and change-prices): prices ≥ 0 and within the column size, D132 rent trio (all of daily / weekly / monthly above $0, or none), CAD/USD, km/miles, minimum days 0–90, one line per category / type. The conflict guard (`lib/RateCards/ConflictGuard.php`) refuses two in-force customer cards covering the same equipment.
- **Change prices** — `lib/RateCards/RateCardRevision.php` via `api/v1/rate_cards/revise.php`; the preview runs the same code inside a transaction that is rolled back; each card runs in its own savepoint; apply is all-or-nothing. Audit rows link old ↔ new (`replaced_by` / `replaces_card_id`).
- **History** — create, update and change-prices write line snapshots into `audit_log` (module `rates`); `api/v1/rate_cards/history.php` turns them into per-line diffs and a price timeline.
- **Leases on a card** — leases keep no `rate_card_id`; each active lease's customer + equipment type is resolved today and counted when the card wins (`api/v1/rate_cards/leases.php`).
- **Money is exact** — decimal strings end to end (bcmath). Changing a card never re-prices existing leases or sent invoices.

</details>

## Related guides

- [Leases](/help/leases)
- [Customers](/help/customers)
- [Equipment](/help/equipment)
