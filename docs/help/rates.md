---
description: Set rental pricing with rate cards — general cards for everyone and customer rate cards for negotiated pricing — the rates that pre-fill new leases.
---

# Rates

Set the daily, weekly, monthly, and mileage rates FleetForge suggests when you create a lease.

## Reading the dashboard

All pricing lives on **rate cards**. A card is either **general** (applies to every customer) or a **customer rate card** (applies to one customer only). The Rates page shows four tiles at the top — each counts rate lines (one per equipment type on a card):

- **Rate Cards** — total rate lines across all cards.
- **Active Today** — lines on cards whose effective date range covers today.
- **Customer Cards** — lines on customer rate cards. Click to jump to the customer section.
- **Global Cards** — lines on general cards. Click to jump to the global section.

Below the tiles:

- **Customer Rate Cards** — one tile per customer that has its own card(s). Use the search box to find a customer; click a tile to see that customer's rates, with **Edit** / **Delete** on each and **+ New Card** to add another.
- **Global Rate Cards** — your general price lists. This section is hidden by default; flip its toggle to show it.

---

## Creating a rate card

1. Click **+ New Rate Card** (top right of the Rates page).

2. Fill in the **Rate Card Details** section:
   - **Card Name** (required) — e.g. *Standard 2025 Rates*.
   - **Customer** (optional) — leave blank for a general card that applies to everyone, or pick a customer to make this that customer's own rate card.
   - **Effective From** (required) — the date this card starts applying.
   - **Effective To** — leave blank for open-ended (no expiry).
   - **Set as Default Rate Card** — tick this to make it the fallback card. A warning reminds you that setting a new default removes default status from the existing default card.
   - **Description** — optional notes.

3. In the **Rate Items** section, click **+ Add Rate** for each equipment type you want to price.

4. For each item:
   - Pick the **Equipment Type** category from the dropdown (e.g. Dry Van, Reefer) and the **Currency** (*CAD* or *USD*).
   - Optionally narrow it with **Specific Unit Type** — search for one Equipment Type (from **Equipment → Equipment Types**). Leave it blank and the rate applies to every unit in that category.
   - Enter **Daily Rate**, **Weekly Rate**, **Monthly Rate**, and **Mileage Rate** (choose */ km* or */ mi*) as needed, plus **Hourly (reefer) $/hr**, **GPS $/day**, and **Min days** if they apply. Any rate left blank is simply not set.

5. Click **Create Rate Card**.

> **Note:** Each equipment type can appear only once per card, and rates cannot be negative. A card name must be unique among non-deleted cards.

> **Tip:** You don't have to fill every rate column. A card can define just a monthly rate for one equipment type and full daily/weekly/monthly for another.

---

## Editing a rate card

1. On the Rates page, click **Edit** on the card (for a customer card, click the customer's tile first; for a general card, show **Global Rate Cards**).

2. To change the header, click **Edit** in the **Card Details** panel, adjust the fields, then click **Save Changes**. Click **Cancel** to discard.

3. To change pricing, use the **Rate Items** table:
   - Click **+ Add Rate** to add an equipment type.
   - Click **Edit** on a row to change its values inline.
   - Click the **×** button to remove a row.
   - Click **Save All Items** (or **Save All**) to commit. All rows are saved together.

> **Note:** If two people edit the same card at the same time, the last save wins. Reload the card before editing if someone else may have changed it.

---

## Setting a default rate card

1. Open a rate card (or use **+ New Rate Card**).
2. Tick **Set as Default Rate Card** / **Set as Default** and save.

Only one card can be the default at a time — setting a new one automatically clears the previous default. The default card is preferred whenever more than one active rate card matches an equipment type during lease pricing.

---

## Deleting a rate card

1. On the Rates page, click **Delete** on the card, or open the card and click **Delete This Rate Card**.
2. Confirm in the **Delete Rate Card** dialog by clicking **Delete**.

> **Note:** The **default** card cannot be deleted — its Delete button is disabled. Make another card the default first. Deleting a card does not change rates already saved on existing leases ("Historical lease rates are unaffected").

---

## Setting a custom rate for one customer

A customer's negotiated pricing is simply a **customer rate card** — a rate card with that customer chosen in the **Customer** field. (The old per-customer "overrides" have been retired; any that existed were converted into customer rate cards.)

1. Click **+ New Rate Card** on the Rates page and pick the customer — or open the customer (**Customers → the customer → Rates** tab) and click **+ New Rate Card** there, which pre-selects them.
2. Fill in the card exactly as in *Creating a rate card* above, adding an item for each equipment type the customer has special pricing on.
3. Click **Create Rate Card**.

The customer's **Rates** tab lists all of their cards. To change one, click **Edit** on it (or use its customer tile on the Rates page).

→ See the [Customers guide](/help/customers) for the full customer-page walkthrough.

---

## How a lease picks its rates

When you choose a customer and an equipment unit on a new lease, FleetForge looks up rates and pre-fills the rate fields, showing where they came from. It checks three sources **in this order** and stops at the first match:

| Priority | Source | Banner shown on the lease form |
|----------|--------|-------------------------------|
| 1 | **Customer rate card** — an active card for that customer with a matching equipment-type item | *Contracted rates — {type} rate · custom card "{card name}"* (rate fields locked; click **Unlock** to change them) |
| 2 | **General rate card** — an active card for everyone with a matching item | *{type} rate · card "{card name}"* |
| 3 | **Equipment type defaults** — the default rates set on the unit's Equipment Type | *{type} rate · template default* |

If none match, the fields are left empty (*No rates configured for {type}*). A rate is only "active" when today falls within its card's **Effective From** / **Effective To** range. Within a tier, an item set for the unit's **Specific Unit Type** beats a category-wide item; after that the **default** card wins, then the one with the latest **Effective From**.

> **Tip:** Pre-filled rates are only a starting point — you can always type over them on the lease before saving.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Tables** — `rate_cards` (header + `is_default`, `effective_from`, `effective_to`, soft-deleted via `deleted_at`, and `customer_id` — NULL = general card), and `rate_card_items` (one row per equipment type: `equipment_type` category slug, optional `equipment_template_id`, `daily_rate`, `weekly_rate`, `monthly_rate`, `mileage_rate`, `mileage_unit`, `hourly_rate`, `gps_price`, `minimum_days`, `currency`). The retired `customer_equipment_rates` override table is kept but no longer read.
- **Resolution lives in** `api/v1/leases/lookup_rates.php`. Order is strictly: active rate card, ordered `customer card first → template-specific item first → is_default DESC → effective_from DESC` → equipment-type (template) defaults → none.
- **Active = date window** — every lookup filters `effective_from <= today AND (effective_to IS NULL OR effective_to >= today)`.
- **Matching key is the equipment type's `category`** — the lookup keys on the category slug (`dry_van`, `reefer`, `flatbed`, etc.), so all Equipment Types sharing a category share one rate unless an item names a **Specific Unit Type** (`equipment_template_id`). The rate-card dropdowns store category slugs.
- **Money is exact** — all rates are stored and validated as decimal strings (bcmath); the UI uses `step="0.01"` for daily/weekly/monthly and `step="0.0001"` for mileage. Negative values are rejected.
- **Default is singular** — saving a card with default on clears `is_default` on every other card in the same transaction.
- **Soft delete** — rate cards are soft-deleted (recoverable in data, hidden everywhere), and all changes are written to the `audit_log` (module `rates`).
- **Optimistic locking (D19)** — card edits send the row's `updated_at`; a `STALE_DATA` conflict is only raised when optimistic locking is switched on (it is currently off — last write wins).
- **No retroactive repricing** — changing a card never alters rates already frozen on existing leases or sent invoices; new values apply only to leases created (or rates looked up) afterward.

</details>

## Related guides

- [Leases](/help/leases)
- [Customers](/help/customers)
- [Equipment](/help/equipment)
