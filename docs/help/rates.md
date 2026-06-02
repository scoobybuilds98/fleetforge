---
description: Set standard rental pricing with rate cards and per-customer rate overrides — the rates that pre-fill new leases.
---

# Rates

Set the daily, weekly, monthly, and mileage rates FleetForge suggests when you create a lease.

## Reading the dashboard

The Rates page shows three KPI tiles at the top:

- **Rate Cards** — total rate cards in the system.
- **Active Today** — rate cards whose effective date range covers today.
- **Customer Overrides** — total custom rates set across all customers.

Below the tiles are two tabs:

- **Rate Cards** — your reusable, standard price lists.
- **Customer Overrides** — every customer-specific rate, listed in one place (read-only here; edit them on the customer's own page).

---

## Creating a rate card

1. Click **+ New Rate Card** (top right of the Rates page).

2. Fill in the **Rate Card Details** section:
   - **Name** (required) — e.g. *Standard 2025 Rates*.
   - **Effective From** (required) — the date this card starts applying.
   - **Effective To** — leave blank for open-ended (no expiry).
   - **Set as Default Rate Card** — tick this to make it the fallback card. A warning reminds you that setting a new default removes default status from the existing default card.
   - **Description** — optional notes.

3. In the **Rate Items** section, click **+ Add Equipment Type** for each equipment type you want to price. One row starts open by default.

4. For each row:
   - Pick the **Equipment Type** from the dropdown (the list is your active equipment templates). Selecting one **pre-fills the rate columns from that template's default rates** — you can then edit any value.
   - Enter **Daily ($)**, **Weekly ($)**, **Monthly ($)**, and **Mileage Rate** as needed. Any rate left blank is simply not set.
   - Choose the **Unit** (*km* or *miles*) and **Currency** (*CAD* or *USD*).

5. Click **Create Rate Card**.

> **Note:** Each equipment type can appear only once per card, and rates cannot be negative. A card name must be unique among non-deleted cards.

> **Tip:** You don't have to fill every rate column. A card can define just a monthly rate for one equipment type and full daily/weekly/monthly for another.

---

## Editing a rate card

1. On the **Rate Cards** tab, click the card's **Name** (or its **Edit** button).

2. To change the header, click **Edit** in the **Card Details** panel, adjust the fields, then click **Save Changes**. Click **Cancel** to discard.

3. To change pricing, use the **Rate Items** table:
   - Click **+ Add Row** to add an equipment type.
   - Click **Edit** on a row to change its values inline.
   - Click the **×** button to remove a row.
   - Click **Save All Items** (or **Save All**) to commit. All rows are saved together.

> **Note:** If someone else edits the same card while you have it open, saving shows *"This rate card was modified by another user. Please reload this page and try again."* Reload and redo your change.

---

## Setting a default rate card

1. Open a rate card (or use **+ New Rate Card**).
2. Tick **Set as Default Rate Card** / **Set as Default** and save.

Only one card can be the default at a time — setting a new one automatically clears the previous default. The default card is preferred whenever more than one active rate card matches an equipment type during lease pricing.

---

## Deleting a rate card

1. From the **Rate Cards** tab, click **Delete** on the card's row, or open the card and click **Delete This Rate Card**.
2. Confirm in the **Delete Rate Card** dialog by clicking **Delete**.

> **Note:** The **default** card cannot be deleted — its Delete button is disabled. Make another card the default first. Deleting a card does not change rates already saved on existing leases ("Historical lease rates are unaffected").

---

## Setting a custom rate for one customer

Customer-specific overrides are **not** created on the Rates page — they live on each customer's profile. The **Customer Overrides** tab here is a read-only roll-up of them all.

1. Open the customer (**Customers → the customer → Rates** tab).
2. Click **+ Add Override**.
3. In the **Add Rate Override** dialog:
   - Pick the **Equipment Type** (required).
   - Set **Effective From** (required) and, optionally, **Effective To** (blank = open-ended).
   - Enter any of **Daily Rate**, **Weekly Rate**, **Monthly Rate**, **Mileage Rate**, plus **Mileage Unit** and **Currency**.
   - Add optional **Notes**.
4. Click **Add Override**. To change one later, click **Edit Rate Override** (equipment type and effective-from are locked once created), then **Save Changes**.

> **Note:** A customer can have only one override per equipment type per effective-from date. Deleting an override removes the active custom rate but preserves rate history.

→ See the [Customers guide](/help/customers) for the full customer-page walkthrough.

---

## How a lease picks its rates

When you choose a customer and an equipment unit on a new lease, FleetForge looks up rates and pre-fills the rate fields, showing where they came from. It checks three sources **in this order** and stops at the first match:

| Priority | Source | Banner shown on the lease form |
|----------|--------|-------------------------------|
| 1 | **Customer override** — an active rate for that customer + equipment type | *Custom rates for {customer}* |
| 2 | **Rate card** — an active card with a matching equipment-type item | *Rate card: {card name}* |
| 3 | **Equipment template defaults** — the template's built-in default rates | *Default rates from template* |

If none match, the fields are left empty (*No rates configured for this equipment type*). A rate is only "active" when today falls within its **Effective From** / **Effective To** range. When two rate cards both match, the **default** card wins; otherwise the one with the latest **Effective From** wins.

> **Tip:** Pre-filled rates are only a starting point — you can always type over them on the lease before saving.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Tables** — `rate_cards` (header + `is_default`, `effective_from`, `effective_to`, soft-deleted via `deleted_at`), `rate_card_items` (one row per equipment type: `daily_rate`, `weekly_rate`, `monthly_rate`, `mileage_rate`, `mileage_unit`, `currency`), and `customer_equipment_rates` (per-customer overrides, plus a `minimum_charge` column not yet exposed in the rate-card UI).
- **Resolution lives in** `api/v1/leases/lookup_rates.php`. Order is strictly: customer override → active rate card (`is_default DESC, effective_from DESC`) → template defaults → none.
- **Active = date window** — every lookup filters `effective_from <= today AND (effective_to IS NULL OR effective_to >= today)`.
- **Matching key is the template's `category`, not its name** — the lookup keys on the equipment template's category enum (`dry_van`, `reefer`, `flatbed`, etc.), so all templates sharing a category share one rate. The rate-card and override dropdowns currently submit template *names*; aligning the two is a known open item (`S-RATES-UI-CATEGORY-DEDUP`).
- **Money is exact** — all rates are stored and validated as decimal strings (bcmath); the UI uses `step="0.01"` for daily/weekly/monthly and `step="0.0001"` for mileage. Negative values are rejected.
- **Default is singular** — saving a card with default on clears `is_default` on every other card in the same transaction.
- **Soft vs hard delete** — rate cards are soft-deleted (recoverable in data, hidden everywhere); customer overrides are hard-deleted, but every create / update / delete is written to `customer_rate_history` first, and all changes are written to the `audit_log` (module `rates`).
- **Optimistic locking (D19)** — card and override edits send the row's `updated_at`; a mismatch returns `STALE_DATA` instead of silently overwriting a concurrent change.
- **No retroactive repricing** — changing a card or override never alters rates already frozen on existing leases or sent invoices; new values apply only to leases created (or rates looked up) afterward.

</details>

## Related guides

- [Leases](/help/leases)
- [Customers](/help/customers)
- [Equipment](/help/equipment)
