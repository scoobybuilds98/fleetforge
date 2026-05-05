# Things To Do At The End

Running list of polish work to tackle once the core build is complete.
Add new items as they come up — don't lose them mid-feature.

---

## Mobile / iPad Responsive Audit

Go through every admin page (and every portal page) and verify mobile + iPad
rendering. The auto-stack table fix (S-PROD-1A-FIX-2 era) covers most list
pages, but every page still needs a manual pass for:

- **Page header**: title + action buttons should stack vertically on narrow
  screens (currently they wrap awkwardly when the title is long).
- **Tab bars**: when there are 3+ tabs (e.g. invoices Active/Sent/Paid/Void),
  the bar overflows. Needs horizontal scroll or wrap.
- **Table toolbars**: search input + status filter + sort dropdown often
  collide. Should reflow to vertical stack ≤767px.
- **Show/detail pages**: the 2/3 + 1/3 grid layout should already collapse to
  1-column at ≤1023px (`.grid-2-1` rule), but verify on every show page.
  - ✅ Spec tables (key/value, no `<thead>`) inside `.card` now stack
    label-above-value on mobile — was previously overflowing 640px and
    being clipped (commit 3a6799a, 2026-05-05).
  - ✅ `.grid-2 > *` now resets `grid-column: auto` on mobile so inline
    `style="grid-column: span 2"` (used on Odometer / GPS cards in
    leases-show) doesn't break the single-column stack (commit 3a6799a).
  - Verified clean: leases/show, customers/show, equipment/show.
  - Still to verify: invoices/show, payments/show, vendors/show,
    reservations/show, maintenance_work_orders/show, damage_claims/show,
    mileage_logs/show, inspections/show, credit_notes/show, rates/show,
    users/show, accounting/tax/show.
- **Sub-tables inside tabs**: leases/show, customers/show, equipment/show all
  have nested tables in tabs that need their own auto-stack pass.
- **Modals**: most are fixed-width and overflow on narrow viewports. Needs
  `max-width: 100vw - 32px` and full-bleed on mobile.
- **Action button rows**: View / Edit / Delete buttons in the trailing td
  should wrap to a new line on mobile cards rather than try to inline.
- **Form pages** (create/edit): `.form-row-N` already collapses to 1fr on
  mobile, but verify date pickers, file uploads, and multi-select widgets
  work with touch.
- **KPI tile grids**: 4-tile rows currently stack to 1 column ≤1023px. May
  want 2×2 grid on tablet (768–1023px) instead of 1×4 stack — looks too tall.
- **Sidebar**: confirmed working as overlay on ≤1023px. Verify swipe-to-close
  and that backdrop click dismisses.
- **Topbar**: density toggle + theme toggle + search + notifications + avatar
  all have to fit on a 375px screen. Probably need to hide some behind a "more"
  menu on the smallest sizes.

### Pages still needing mobile QA (snapshot 2026-05-05)

Scope is ~90 admin pages — full list in `find app/admin -name '*.php'`.
Group them and tick off in batches:

**Core lists** (table-stack auto-applies — quick spot-check)
- [ ] dashboard, customers, leases, equipment, invoices, payments,
      reservations, maintenance_work_orders, damage_claims, compliance,
      rates, vendors, users, inspections, mileage_logs, yards,
      credit_notes, documents, audit, notifications

**Show / detail pages** (2-col layouts, tabs, sub-tables — needs care)
- [ ] customers/show, leases/show, invoices/show, payments/show,
      vendors/show, equipment/show, reservations/show,
      maintenance_work_orders/show, damage_claims/show, mileage_logs/show,
      inspections/show, credit_notes/show, rates/show, users/show,
      accounting/tax/show

**Forms** (create / edit — verify input sizing + stacking)
- [ ] customers/create, customers/edit, leases/create, leases/edit,
      equipment/create, equipment/edit, equipment/templates/create,
      equipment/templates/edit, invoices/create, payments/create,
      reservations/create, maintenance_work_orders/create,
      damage_claims/create, mileage_logs/create, inspections/create,
      credit_notes/create, rates/create, users/create, vendors/create,
      vendors/edit, email/bulk

**Settings + account**
- [ ] settings/index (6-tab shell), settings/users, settings/portal_users,
      settings/audit_log, settings/system, settings/email_templates,
      account/mfa_setup, profile/index, users/permissions

**Accounting hub** (16 sub-pages)
- [ ] accounting/dashboard, ar-aging, ap-aging, bank-accounts,
      bank-reconciliation, bills, capex, categorization-rules,
      chart-of-accounts, collections, deposits, depreciation,
      fixed-assets/index, fixed-assets/payoff-report, journal-entries,
      ledger, periods, statements, tax/index, vendor-credits,
      reports/trial-balance, settings/index

**Specialty**
- [ ] analytics, reports, ai, chat, messenger, tracking,
      equipment/templates/index

**Portal** (separate audit — customer-facing)
- [ ] portal/index, portal/leases/{index,view}, portal/invoices/{index,view},
      portal/equipment, portal/documents, portal/requests/{index,create,view},
      portal/account/{index,users}

---

(Add new TODO items below as they come up.)
