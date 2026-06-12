# Domain 02 — Billing, Invoices, Payments & Credit

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/02-billing.md`.

Modules: `invoices`, `payments`, `credit_notes`, `credit_applications`,
`rate_cards`, `customer_equipment_rates`. This is where today's rate-card bug
lived — treat the rate surfaces with extra suspicion.

## Scope
```
for g in invoices payments credit_notes credit_applications rate_cards customer_equipment_rates; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/invoices app/admin/payments app/admin/credit_notes app/admin/credit_applications app/admin/rates
ls lib/Invoice* lib/Billing* 2>/dev/null
```
Schema: `invoices`, `invoice_lines`, `payments`, `payment_allocations`,
`credit_notes`, `credit_note_lines`, `credit_applications`, `rate_cards`,
`rate_card_items`, `customer_equipment_rates`, `lease_billing_periods`. Note every
enum (invoice status, payment status/method, `period_type`).

## End-to-end flows
1. **Rate card create/edit** (`app/admin/rates/create.php`, `show.php`) — RE-VERIFY
   today's fix landed and look for siblings: any other repeatable-row form with the
   same blank-row trap (Class 1). The known `S-RATES-UI-CATEGORY-DEDUP` mismatch is
   still open: `lookup_rates.php` keys on equipment_type **category**, the UI stores
   the **name** → overrides silently miss (Class 2). Confirm impact and report.
2. **Invoice generate** (`InvoiceGenerator::createFromLease`) — billing types
   `monthly|mileage_only|adjustment|credit_note`; the `period_type` enum mapping
   (Class 3) MUST hold for every type. Per-month generate UX (commit `f096c0d`).
3. **Invoice issue / send** (draft→sent) — this is the counter trigger:
   `customers.outstanding_balance` reflects SENT only; voids/drafts excluded
   (Class 7, `project_path_b_counter_semantics`, locked as D45). Test void after send.
4. **Payment record + allocation** — over-allocation, partial, currency mismatch;
   undeposited-funds account for QBO (Class 12); does it update the invoice balance
   and the customer counter symmetrically?
5. **Credit note** issue + apply to invoice; **credit application** intake/review
   (Phase CCA — S-CCA contracts in `project_phase_cca_status`): token route, submit
   artifacts, review write-back.
6. **Invoice dates** — issue/due + GL rev-rec derive from billing period (commit
   `a2bc7ff`); confirm no path uses "today" instead (Class 6).

## Hotspots
- **Class 1:** every dynamic item table — rate cards, invoice lines, credit-note
  lines. Blank-row trap, over-strict requireds, line totals that must sum.
- **Class 2:** name vs category vs id keys across rate lookup and customer-equipment
  rate overrides.
- **Class 7:** outstanding_balance and lease totals — reconcile against prod.
- **Class 3:** status + period_type enums on every write.
- bcmath money math (no native floats); rounding on tax/proration.

## Start here
Re-audit the two rate pages first (confirm today's fix + the open category bug),
then walk invoices → payments → credit notes. Reconcile `outstanding_balance` for
a few real customers against a fresh `SUM(sent invoices − payments)` on prod.
