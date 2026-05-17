# FLEETFORGE — DESIGN & IMPLEMENTATION DETAILS
**Companion to FLEETFORGE_SPEC_FINAL.md — fills in the exact values the spec describes conceptually.**

---

## 1. DARK / LIGHT THEME — EXACT CSS VARIABLE VALUES

The spec names the variables but never provides the hex values. These are the definitive values.

```css
/* ============================================================
   LIGHT THEME (default)
   ============================================================ */
[data-theme="light"] {
  /* Backgrounds */
  --bg-page:        #f5f5f4;        /* stone-100 — page canvas */
  --bg-card:        #ffffff;        /* white — card surfaces */
  --bg-muted:       #f0efed;        /* warm gray — subtle sections, table alt rows */
  --bg-sidebar:     #1a1a1a;        /* near-black — sidebar is ALWAYS dark */
  --bg-input:       #ffffff;
  --bg-hover:       #f5f4f2;        /* row/item hover */
  --bg-selected:    #eff6ff;        /* blue-50 — selected row */

  /* Text */
  --text-primary:   #1c1c1a;        /* near-black */
  --text-secondary: #6b6b66;        /* warm gray-500 */
  --text-muted:     #a3a39e;        /* warm gray-400 */
  --text-inverse:   #ffffff;        /* on dark backgrounds */

  /* Borders */
  --border-color:   #e5e5e2;        /* light warm gray */
  --border-strong:  #d4d4cf;        /* darker for emphasis */

  /* Shadows */
  --shadow-sm:  0 1px 2px rgba(0,0,0,0.05);
  --shadow-md:  0 2px 8px rgba(0,0,0,0.08);
  --shadow-lg:  0 4px 16px rgba(0,0,0,0.10);
}

/* ============================================================
   DARK THEME
   ============================================================ */
[data-theme="dark"] {
  --bg-page:        #0f0f0f;        /* near-black canvas */
  --bg-card:        #1a1a1a;        /* dark gray card surfaces */
  --bg-muted:       #242424;        /* subtle sections */
  --bg-sidebar:     #1a1a1a;        /* same as card — sidebar blends */
  --bg-input:       #242424;
  --bg-hover:       #2a2a2a;
  --bg-selected:    #1e2a3a;        /* dark blue tint */

  --text-primary:   #e8e8e4;
  --text-secondary: #9c9c96;
  --text-muted:     #6b6b66;
  --text-inverse:   #1c1c1a;

  --border-color:   #2e2e2e;
  --border-strong:  #404040;

  --shadow-sm:  0 1px 2px rgba(0,0,0,0.20);
  --shadow-md:  0 2px 8px rgba(0,0,0,0.30);
  --shadow-lg:  0 4px 16px rgba(0,0,0,0.40);
}

/* ============================================================
   SEMANTIC COLORS — Same in both themes
   ============================================================ */
:root {
  --color-accent:   #3b82f6;       /* blue-500 — primary actions, links */
  --color-accent-hover: #2563eb;   /* blue-600 */
  --color-success:  #16a34a;       /* green-600 */
  --color-warning:  #d97706;       /* amber-600 */
  --color-danger:   #dc2626;       /* red-600 */
  --color-info:     #0891b2;       /* cyan-600 */

  /* Status badge backgrounds (light tinted) */
  --badge-success-bg:  #dcfce7;    --badge-success-text: #166534;
  --badge-warning-bg:  #fef3c7;    --badge-warning-text: #92400e;
  --badge-danger-bg:   #fee2e2;    --badge-danger-text:  #991b1b;
  --badge-info-bg:     #cffafe;    --badge-info-text:    #155e75;
  --badge-neutral-bg:  #f3f4f6;    --badge-neutral-text: #374151;
  --badge-purple-bg:   #f3e8ff;    --badge-purple-text:  #6b21a8;
}

/* Dark theme badge overrides */
[data-theme="dark"] {
  --badge-success-bg:  #052e16;    --badge-success-text: #4ade80;
  --badge-warning-bg:  #451a03;    --badge-warning-text: #fbbf24;
  --badge-danger-bg:   #450a0a;    --badge-danger-text:  #f87171;
  --badge-info-bg:     #083344;    --badge-info-text:    #22d3ee;
  --badge-neutral-bg:  #1f2937;    --badge-neutral-text: #9ca3af;
  --badge-purple-bg:   #2e1065;    --badge-purple-text:  #c084fc;
}
```

---

## 1.5 INPUTS & LABELS — APPLE MACOS AESTHETIC (S-DESIGN-INPUTS, 2026-05-03)

Form inputs render as borderless tinted-gray pills with a 2px brand-orange focus ring. Labels render in muted alpha. Token-level — every `.form-control`, `.form-input`, `.form-select`, `.portal-form-input` cascades from the variables below.

```css
/* DARK MODE (warm-tinted alphas — palette-matched to FleetForge dark theme) */
:root,                          /* default theme = dark */
[data-theme="dark"] {           /* explicit dark refresh */
  --input-bg:          rgba(140, 130, 115, 0.18);
  --input-bg-focus:    rgba(140, 130, 115, 0.32);
  --input-text:        rgba(255, 255, 255, 0.96);
  --input-placeholder: rgba(235, 230, 220, 0.40);
  --label-text:        rgba(235, 230, 220, 0.60);
  --label-text-strong: rgba(255, 255, 255, 0.85);
}

/* LIGHT MODE (Apple cool gray rgba 118,118,128 verbatim) */
[data-theme="light"] {
  --input-bg:          rgba(118, 118, 128, 0.12);
  --input-bg-focus:    rgba(118, 118, 128, 0.22);
  --input-text:        rgba(0, 0, 0, 0.92);
  --input-placeholder: rgba(60, 60, 67, 0.40);
  --label-text:        rgba(60, 60, 67, 0.72);  /* bumped from Apple 0.60 for WCAG AA */
  --label-text-strong: rgba(0, 0, 0, 0.85);
}
```

**Component rules** (see `public/assets/css/app.css` lines ~2167-2240):
- `.form-control / .form-input / .form-select` — `border:none; border-radius:8px; height:40px; background:var(--input-bg)`
- Focus — `background:var(--input-bg-focus); box-shadow:0 0 0 2px var(--color-primary)` (no border so no layout shift)
- Disabled — `opacity:0.5; cursor:not-allowed`
- Invalid (`.is-invalid` / `.ff-invalid`) — `box-shadow:0 0 0 2px var(--color-danger)`
- `.form-label` — `color:var(--label-text); font-size:0.8125rem; font-weight:500; letter-spacing:0.01em`

**Decisions:**
- Warm dark-mode tints (140,130,115) chosen over Apple's cool (118,118,128) for palette coherence with the brownish-black FleetForge dark theme (`#262624` page / `#141413` cards).
- Light-mode label alpha bumped 0.60 → 0.72 to pass WCAG AA contrast (4.5:1) for 13px labels over white.
- `--input-border` retained — `.form-check-input` (checkboxes) intentionally keep their bordered look.
- All `input[type=*]` types (text/email/number/password/search/tel/url/date/datetime-local/time/select/textarea) cascade through the `.form-control/.form-input/.form-select` selector set. No per-type rules needed.
- Specialized inputs retain custom treatments: `.search-input` (cmd-K palette), `.search-wrapper .search-input` (topbar pill), `.chat-search-input`, `.portal-search-input`.

---

## 1.6 SEGMENTED CONTROL — APPLE iOS PILL TOGGLE (S-LEASE-UNITS / S-LEASE-UNITS-FORM, 2026-05-03)

A two-option toggle that slides a grayscale "active pill" between options. Used in lease forms for the km/miles primary-unit picker (D-D from S-LEASE-UNITS: no brand orange — the active pill is a neutral elevated surface). Component CSS lives at `public/assets/css/app.css` lines 8402-8484, plus the responsive override at line 8564.

```css
/* DARK MODE (default at :root) — mid-gray pill on translucent track */
:root {
  --segment-track-bg:     rgba(118, 118, 128, 0.24);
  --segment-active-bg:    #636366;
  --segment-active-shadow:
      0 3px 8px rgba(0, 0, 0, 0.32),
      0 1px 2px rgba(0, 0, 0, 0.18);
  --segment-active-text:  rgba(255, 255, 255, 1);
  --segment-inactive-text: rgba(235, 235, 245, 0.6);
}

/* LIGHT MODE — white pill on lighter translucent track (Apple iOS spec) */
[data-theme="light"] {
  --segment-track-bg:     rgba(118, 118, 128, 0.12);
  --segment-active-bg:    #FFFFFF;
  --segment-active-shadow:
      0 3px 8px rgba(0, 0, 0, 0.12),
      0 1px 2px rgba(0, 0, 0, 0.04);
  --segment-active-text:  rgba(0, 0, 0, 1);
  --segment-inactive-text: rgba(60, 60, 67, 0.6);
}
```

**Component anatomy** (3 elements per control):

| Element | Class | Role |
|---------|-------|------|
| Outer | `.ff-segment-control` | 320px × 44px track, `inline-flex`, 9px radius, `padding: 2px`, `user-select: none` |
| Pill | `.ff-segment-control__pill` | Absolute-positioned active surface, `width: calc(50% - 2px)`, `border-radius: 7px`, slides via `transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)`. Modifier `--right` translates by 100% |
| Option | `.ff-segment-control__option` | Click target, flex 1, `font-size: 14px; font-weight: 500; letter-spacing: -0.01em`. Modifier `--active` applies the active text color. `:focus-visible` gets a 2px brand-primary ring |

**Responsive** (≤540px): control width drops from fixed `320px` to `width: 100%; max-width: 320px;` so it fills the column on phones (line 8564).

### Variant A — Interactive (Alpine + ARIA tablist)

Used at [app/admin/leases/create.php:410-433](app/admin/leases/create.php:410). User clicks an option to commit it.

```html
<div class="ff-segment-control"
     role="tablist"
     aria-label="Mileage unit">
  <div class="ff-segment-control__pill"
       :class="{ 'ff-segment-control__pill--right': form.mileage_unit === 'miles' }"></div>
  <div class="ff-segment-control__option"
       :class="{ 'ff-segment-control__option--active': form.mileage_unit === 'km' }"
       @click="!ratesLocked && togglePrimaryUnit('km')"
       role="tab"
       :aria-selected="form.mileage_unit === 'km'"
       :aria-disabled="ratesLocked"
       tabindex="0">
    Kilometers
  </div>
  <div class="ff-segment-control__option"
       :class="{ 'ff-segment-control__option--active': form.mileage_unit === 'miles' }"
       @click="!ratesLocked && togglePrimaryUnit('miles')"
       role="tab"
       :aria-selected="form.mileage_unit === 'miles'"
       :aria-disabled="ratesLocked"
       tabindex="0">
    Miles
  </div>
</div>
```

**Required bindings:**
- `:class` on the pill — `__pill--right` when state matches the second option (else default position).
- `:class` on each option — `__option--active` when state matches that option's value.
- `@click` on each option — guarded by any disabled flag (e.g. `!ratesLocked`).
- `tabindex="0"` so each option is keyboard-focusable.
- `role="tablist"` on the wrapper, `role="tab"` + `:aria-selected` on each option.

### Variant B — Read-only (no Alpine click handlers)

Used at [app/admin/leases/edit.php:215-233](app/admin/leases/edit.php:215). Visible state but the user must use a separate amendment flow to change it.

```html
<div class="ff-segment-control"
     role="group"
     aria-label="Mileage unit (read-only — change via amendment)"
     style="opacity:0.92;">
  <div class="ff-segment-control__pill"
       :class="{ 'ff-segment-control__pill--right': _mileageUnit === 'miles' }"></div>
  <div class="ff-segment-control__option"
       :class="{ 'ff-segment-control__option--active': _mileageUnit === 'km' }"
       aria-disabled="true"
       style="cursor:not-allowed;">
    Kilometers
  </div>
  <div class="ff-segment-control__option"
       :class="{ 'ff-segment-control__option--active': _mileageUnit === 'miles' }"
       aria-disabled="true"
       style="cursor:not-allowed;">
    Miles
  </div>
</div>
```

**Read-only differences:**
- `role="group"` instead of `tablist` (no tab navigation semantics).
- `aria-disabled="true"` on each option, no `tabindex`.
- No `@click` handler.
- Outer `style="opacity:0.92;"` and `cursor:not-allowed` on each option to communicate the disabled state visually.
- ARIA label spells out the reason ("change via amendment").

**Decisions:**
- Active pill is grayscale, not brand orange (D-D from S-LEASE-UNITS) — matches Apple's iOS segmented-control spec where the active pill is just an elevated surface, never a tinted hue. Brand orange is reserved for primary actions.
- Pill slides via CSS `transform` not by re-rendering — single transition, no Alpine `x-transition` needed.
- Two-option only. A 3+ option control would need recalculating `width: calc(33.33% - …)` and a new `--right2` modifier; not implemented because no current product surface needs it.
- Mobile uses `width: 100%` rather than scaling down — the 14px font and 44px height stay constant for tap-target accessibility.

---

## 2. BUTTON VARIANTS — The 8 × 5 matrix

### 8 variants:
| Variant | Use case | Background | Text | Border |
|---------|----------|-----------|------|--------|
| `btn-primary` | Main CTA | var(--color-accent) | white | none |
| `btn-secondary` | Secondary action | transparent | var(--text-primary) | var(--border-color) |
| `btn-success` | Confirm/approve | var(--color-success) | white | none |
| `btn-danger` | Delete/void | var(--color-danger) | white | none |
| `btn-warning` | Caution action | var(--color-warning) | white | none |
| `btn-ghost` | Subtle action | transparent | var(--text-secondary) | none |
| `btn-link` | Text link style | transparent | var(--color-accent) | none |
| `btn-icon` | Icon-only button | transparent | var(--text-secondary) | none (circle on hover) |

### 5 sizes:
| Size | Class | Padding | Font | Height |
|------|-------|---------|------|--------|
| XS | `btn-xs` | 4px 8px | 11px | 24px |
| SM | `btn-sm` | 6px 12px | 12px | 30px |
| MD | `btn-md` (default) | 8px 16px | 13px | 36px |
| LG | `btn-lg` | 10px 20px | 14px | 42px |
| XL | `btn-xl` | 12px 24px | 15px | 48px |

### States:
- Hover: 10% darker background (filter: brightness(0.9))
- Active/pressed: scale(0.98) + 15% darker
- Disabled: opacity 0.5, cursor not-allowed
- Loading: `.btn-loading` — text hidden, spinner centered, disabled

---

## 3. SIDEBAR NAVIGATION — Exact items, icons, badges

```php
// config/navigation.php
return [
    ['label' => 'Dashboard',     'icon' => 'home',              'url' => '/dashboard',     'module' => null,          'badge' => null],
    ['label' => 'Customers',     'icon' => 'user-group',        'url' => '/customers',     'module' => 'customers',   'badge' => null],
    ['label' => 'Equipment',     'icon' => 'truck',             'url' => '/equipment',     'module' => 'equipment',   'badge' => null],
    ['label' => 'Leases',        'icon' => 'document-text',     'url' => '/leases',        'module' => 'leases',      'badge' => null],
    ['label' => 'Reservations',  'icon' => 'calendar',          'url' => '/reservations',  'module' => 'reservations','badge' => null],
    ['label' => 'Invoices',      'icon' => 'banknotes',         'url' => '/invoices',      'module' => 'invoices',    'badge' => 'overdue_invoices'],
    ['label' => 'Payments',      'icon' => 'credit-card',       'url' => '/payments',      'module' => 'payments',    'badge' => null],
    ['label' => 'Rates',         'icon' => 'currency-dollar',   'url' => '/rates',         'module' => 'rates',       'badge' => null],
    ['label' => 'Maintenance',   'icon' => 'wrench-screwdriver','url' => '/maintenance',   'module' => 'maintenance', 'badge' => null],
    ['label' => 'Compliance',    'icon' => 'shield-check',      'url' => '/compliance',    'module' => 'compliance',  'badge' => 'compliance_alerts'],
    ['label' => 'Documents',     'icon' => 'folder-open',       'url' => '/documents',     'module' => null,          'badge' => null],
    ['label' => 'Reports',       'icon' => 'chart-bar',         'url' => '/reports',       'module' => 'reports',     'badge' => null],
    ['label' => 'Analytics',     'icon' => 'chart-pie',         'url' => '/analytics',     'module' => 'analytics',   'badge' => null],

    // Separator
    ['separator' => true, 'label' => 'Admin'],

    ['label' => 'Users',         'icon' => 'users',             'url' => '/users',         'module' => 'users',       'badge' => null],
    ['label' => 'Audit Log',     'icon' => 'clipboard-document','url' => '/audit',         'module' => 'audit',       'badge' => null],
    ['label' => 'Settings',      'icon' => 'cog-6-tooth',       'url' => '/settings',      'module' => 'settings',    'badge' => null],
];
// Icons are Heroicons outline names (24px SVG inline)
// badge key maps to a PHP function that returns the count (0 = hidden)
// module = null means visible to ALL roles (dashboard, documents)
```

### Sidebar badge counts (computed inline in sidebar.php, NOT via API):
```php
function sidebar_badge_count(string $key): int {
    return match($key) {
        'overdue_invoices' => db_count(
            "SELECT COUNT(*) FROM invoices WHERE status = 'overdue' AND deleted_at IS NULL", []
        ),
        'compliance_alerts' => db_count(
            "SELECT COUNT(DISTINCT id) FROM equipment_units
             WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
             AND (cvi_expiry < CURDATE() + INTERVAL 30 DAY
               OR registration_expiry < CURDATE() + INTERVAL 30 DAY
               OR mvi_expiry < CURDATE() + INTERVAL 30 DAY
               OR insurance_expiry < CURDATE() + INTERVAL 30 DAY)", []
        ),
        default => 0,
    };
}
```

---

## 4. DASHBOARD GRID LAYOUT

Desktop (>1200px): 3-column grid
```
┌──────────────────────────────────────────────────────────────┐
│ KPI    KPI    KPI    KPI    KPI    KPI                       │  ← 6 tiles, single row, equal width
├──────────────────────────────────────────────────────────────┤
│ Revenue Chart (area, 12mo)           │ Fleet Status (donut)  │  ← 2:1 ratio
├──────────────────────────────────────┤                       │
│ AR Aging (horiz bar)                 │                       │
├──────────────────────────────────────┤───────────────────────┤
│ Top Customers (horiz bar)            │ Utilization Trend     │  ← 1:1
├──────────────────────────────────────┤───────────────────────┤
│ Leases Opened vs Closed (grouped)   │ Revenue by Type       │
├──────────────────────────────────────┤───────────────────────┤
│ Today's Pickups (list)               │ Compliance Alerts     │  ← widgets
├──────────────────────────────────────┤ (list, color-coded)   │
│ Recent Activity (feed)               │                       │
├──────────────────────────────────────┤───────────────────────┤
│ AI Fleet Brief (paragraph)           │ Weekly Heatmap        │
└──────────────────────────────────────┴───────────────────────┘
```

Tablet (768–1200px): 2-column, KPIs wrap to 2 rows of 3
Mobile (<768px): single column, KPIs 2 per row

---

## 5. TOAST NOTIFICATIONS — Behavior spec

| Property | Value |
|----------|-------|
| Position | Top-right, 16px from edges |
| Duration | Success: 4s, Info: 5s, Warning: 6s, Error: 8s |
| Auto-dismiss | Yes, with progress bar at bottom of toast |
| Dismiss on click | Yes — clicking the × or the toast itself |
| Stacking | Newest on top, max 5 visible, older ones pushed down |
| Animation | Slide in from right (200ms), fade out (300ms) |
| Width | 360px fixed |
| Content | Icon (left) + Title (bold 13px) + Message (12px, up to 2 lines) + × button |

---

## 6. MODAL — Sizes and behavior

| Size | Class | Max width | Use case |
|------|-------|-----------|----------|
| Small | `modal-sm` | 400px | Confirmations, simple forms |
| Medium | `modal-md` | 560px | Standard forms, detail views |
| Large | `modal-lg` | 720px | Complex forms, multi-section |
| Full | `modal-full` | 90vw, max 1100px | Data-heavy views, editors |

- Overlay: rgba(0,0,0,0.5) — click outside closes (unless `data-persistent`)
- Animation: fade overlay 200ms + scale content from 95% to 100% (200ms)
- Focus trap: Tab cycles within modal
- Escape key: closes (unless persistent)
- Scroll: modal body scrolls, header/footer fixed
- Stacking: only 1 modal at a time. Opening a second closes the first.

---

## 7. LOADING SKELETON PATTERN

```html
<!-- Table skeleton (5 rows) -->
<div class="skeleton-table">
  <div class="skeleton-row" style="--delay: 0">
    <div class="skeleton-cell" style="width: 15%"></div>
    <div class="skeleton-cell" style="width: 25%"></div>
    <div class="skeleton-cell" style="width: 20%"></div>
    <div class="skeleton-cell" style="width: 15%"></div>
    <div class="skeleton-cell" style="width: 10%"></div>
  </div>
  <!-- repeat 5× with increasing --delay -->
</div>

<!-- KPI skeleton -->
<div class="skeleton-kpi">
  <div class="skeleton-bar" style="width: 40%; height: 12px;"></div>  <!-- label -->
  <div class="skeleton-bar" style="width: 70%; height: 24px;"></div>  <!-- value -->
</div>

<!-- Chart skeleton -->
<div class="skeleton-chart" style="height: 300px;"></div>
```

CSS animation: `@keyframes shimmer` — left-to-right gradient sweep, 1.5s, infinite.
Color: `var(--bg-muted)` base → `var(--bg-hover)` highlight sweep.

---

## 8. EMAIL TEMPLATES — HTML structure for all transactional emails

All emails use the same outer wrapper. Content blocks change per email type.

### Outer wrapper (all emails):
```
┌─────────────────────────────────────────┐
│ [LOGO]  Company Name                     │  ← from settings
├─────────────────────────────────────────┤
│                                          │
│   [Email-specific content here]          │
│                                          │
├─────────────────────────────────────────┤
│ Company Address | Phone | Website        │
│ "Powered by FleetForge"                  │
└─────────────────────────────────────────┘
```

Max width: 600px, centered. Background: #f5f5f4. Card: white, 1px #e5e5e2 border.
Font: Arial/Helvetica (email-safe). No web fonts in email.

### Email types and content:

**Password Reset:**
- Subject: "Reset your password — [Company Name]"
- Body: "Hi [name], we received a request to reset your password. Click the button below within 2 hours."
- CTA button: "Reset Password" → reset link
- Footer: "If you didn't request this, ignore this email."

**User Invitation:**
- Subject: "You've been invited to [Company Name]'s FleetForge"
- Body: "Hi [name], [inviter name] has invited you as [role]. Click below to set up your account."
- CTA button: "Accept Invitation" → accept link
- Note: "This invitation expires in 7 days."

**Invoice Delivery:**
- Subject: "Invoice [INV-YYYY-NNNNN] from [Company Name]"
- Body: invoice summary table (invoice #, date, due date, total, balance)
- Attachment: PDF
- CTA button: "View in Portal" → portal invoice link

**Payment Confirmation:**
- Subject: "Payment received — [Company Name]"
- Body: "Payment of [amount] received on [date] for invoice [number]."

**Overdue Reminder:**
- Subject: "Invoice [number] is overdue — [Company Name]"
- Body: "Your invoice [number] for [amount] was due on [date]. Please remit payment at your earliest convenience."
- Tone: professional, not aggressive

**Compliance Alert (internal):**
- Subject: "[count] units have expiring compliance documents"
- Body: table of unit#, document type, expiry date, days remaining

---

## 9. STATUS BADGE COLOR MAPPING

| Entity | Status | Badge class |
|--------|--------|-------------|
| Customer | active | success |
| Customer | inactive | neutral |
| Customer | pending | info |
| Customer | suspended | danger |
| Customer | credit_hold | warning |
| Equipment | available | success |
| Equipment | on_lease | info |
| Equipment | reserved | purple |
| Equipment | maintenance | warning |
| Equipment | inactive | neutral |
| Equipment | decommissioned | danger |
| Lease | pending | info |
| Lease | active | success |
| Lease | completed | neutral |
| Lease | cancelled | danger |
| Invoice | draft | neutral |
| Invoice | sent | info |
| Invoice | paid | success |
| Invoice | partially_paid | warning |
| Invoice | overdue | danger |
| Invoice | void | neutral (strikethrough text) |
| Invoice | written_off | danger |
| Payment | pending | info |
| Payment | cleared | success |
| Payment | failed | danger |
| Payment | refunded | warning |
| Reservation | pending | info |
| Reservation | confirmed | success |
| Reservation | completed | neutral |
| Reservation | cancelled | danger |
| Work Order | open | info |
| Work Order | in_progress | warning |
| Work Order | waiting_parts | purple |
| Work Order | completed | success |
| Work Order | cancelled | danger |
| Risk Score | low | success |
| Risk Score | medium | warning |
| Risk Score | high | danger |

---

## 10. RESPONSIVE BREAKPOINTS

```css
/* Mobile first — these are the breakpoints */
--bp-sm:   640px;    /* Small phones → large phones */
--bp-md:   768px;    /* Phones → tablets */
--bp-lg:  1024px;    /* Tablets → laptops */
--bp-xl:  1280px;    /* Laptops → desktops */
--bp-2xl: 1536px;    /* Large monitors */

/* Admin panel behavior */
@media (max-width: 1024px) {
  /* Sidebar collapses to icons-only (56px wide) */
  /* Click hamburger to expand as overlay */
  /* Tables: horizontal scroll with sticky first column */
}

@media (max-width: 768px) {
  /* Sidebar fully hidden — hamburger menu only */
  /* KPI tiles: 2 per row instead of 6 */
  /* Charts: full width, stacked vertically */
  /* Tables: card view on mobile (each row becomes a card) */
}

/* Portal — must be fully mobile-friendly */
@media (max-width: 640px) {
  /* Portal: single column everything */
  /* Portal sidebar: bottom tab bar (5 icons) instead of side nav */
}
```

---

## 11. EMPTY STATE PATTERNS

Every empty table/list has a styled empty state. Never plain "No records found."

```
┌───────────────────────────────────────┐
│                                       │
│         [Heroicon - 48px, muted]      │
│                                       │
│     No customers yet                  │  ← primary text, 16px, --text-primary
│     Add your first customer to get    │  ← secondary text, 13px, --text-secondary
│     started with FleetForge.          │
│                                       │
│     [ + Add Customer ]                │  ← btn-primary (if user has create permission)
│                                       │
└───────────────────────────────────────┘
```

| Module | Icon | Primary | Secondary | Action |
|--------|------|---------|-----------|--------|
| Customers | user-group | No customers yet | Add your first customer to get started. | + Add Customer |
| Equipment | truck | No equipment registered | Register your fleet to start tracking. | + Add Unit |
| Leases | document-text | No leases found | Create a lease to start billing. | + New Lease |
| Reservations | calendar | No reservations | Schedule equipment pickups and returns. | + New Reservation |
| Invoices | banknotes | No invoices | Invoices are generated from active leases. | — (no action) |
| Payments | credit-card | No payments recorded | Record payments as they come in. | + Record Payment |
| Maintenance | wrench | No work orders | Create work orders to track repairs. | + New Work Order |
| Documents | folder-open | No documents | Upload documents for customers and equipment. | + Upload |

**Filtered empty state** (when filters are active but return no results):
- Icon: funnel
- Primary: "No results match your filters"
- Secondary: "Try adjusting your search or filter criteria."
- Action: "Clear Filters" (btn-secondary)

---

## 12. LOCAL DEVELOPMENT WORKFLOW

Claude Code writes files locally. Here's how to test before deploying:

### Option A: PHP built-in server (simplest)
```bash
cd /path/to/fleetforge
cp .env.example .env
# Edit .env: APP_ENV=development, APP_DEBUG=true, STORAGE_DRIVER=local
# Set DB credentials pointing to a local MySQL

php -S localhost:8080 -t public/
# Visit http://localhost:8080/fleetforge/
```

### Option B: Local Apache (matches production)
```bash
# Add to /etc/hosts:
127.0.0.1  fleetforge.local

# Apache vhost pointing DocumentRoot to /path/to/fleetforge/public
# Same config as production but without SSL
```

### Local MySQL setup:
```sql
CREATE DATABASE fleetforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleetforge_user'@'localhost' IDENTIFIED BY 'localdev';
GRANT ALL PRIVILEGES ON fleetforge.* TO 'fleetforge_user'@'localhost';
```

Then run the schema: `mysql -u fleetforge_user -plocaldev fleetforge < FLEETFORGE_DATABASE_MASTER.sql`

---

## 13. SEED DATA — Exact records for development/demo

### 001_user_roles.sql
```sql
INSERT INTO user_roles (id, name, slug, description, is_system) VALUES
(1, 'Super Admin',  'super_admin', 'Full system access', 1),
(2, 'Manager',      'manager',     'Operational management', 1),
(3, 'Dispatcher',   'dispatcher',  'Fleet operations', 1),
(4, 'Accountant',   'accountant',  'Financial operations', 1),
(5, 'Read Only',    'read_only',   'View-only access', 1);
```

### 003_settings.sql
```sql
INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('company.name', 'Mainland Truck & Trailer Sales', 'company'),
('company.address', '9616 188 St, Surrey, BC V4N 3M2', 'company'),
('company.phone', '+1 866-888-6887', 'company'),
('company.email', 'info@mainlandtruck.com', 'company'),
('company.timezone', 'America/Vancouver', 'company'),
('company.gst_number', '', 'company'),
('company.pst_number', '', 'company'),
('company.currency_symbol', '$', 'company'),
('invoice.due_days_default', '30', 'invoices'),
('invoice.prefix', 'INV', 'invoices'),
('invoice.payment_instructions', 'Payment by cheque or wire transfer. Contact us for details.', 'invoices'),
('alerts.compliance_warning_days', '30', 'alerts'),
('alerts.compliance_critical_days', '7', 'alerts'),
('ai.enabled', 'false', 'ai'),
('ai.daily_token_limit', '500000', 'ai');
```

### 004_yards.sql
```sql
INSERT INTO yards (name, slug, address, city, province, is_active) VALUES
('Main Yard', 'main-yard', '9616 188 St', 'Surrey', 'BC', 1);
```

### 005_tax_rates.sql
```sql
INSERT INTO tax_rates (province, gst_rate, pst_rate, hst_rate, effective_from, is_active) VALUES
('BC',  5.00, 7.00, 0.00, '2024-01-01', 1),
('AB',  5.00, 0.00, 0.00, '2024-01-01', 1),
('ON',  0.00, 0.00, 13.00, '2024-01-01', 1),
('SK',  5.00, 6.00, 0.00, '2024-01-01', 1),
('MB',  5.00, 7.00, 0.00, '2024-01-01', 1),
('QC',  5.00, 9.975, 0.00, '2024-01-01', 1),
('NS',  0.00, 0.00, 15.00, '2024-01-01', 1),
('NB',  0.00, 0.00, 15.00, '2024-01-01', 1),
('NL',  0.00, 0.00, 15.00, '2024-01-01', 1),
('PE',  0.00, 0.00, 15.00, '2024-01-01', 1);
```

---

Before continuing, I want to establish a global commenting 
standard for every file in this project.

Every PHP file must have:

1. A comment block at the very top (after <?php declare) that includes:
   - File path relative to project root
   - One-line description of what this file does
   - Dependencies (what it requires/includes)
   - Key functions or classes defined in this file
   - Any important decisions or spec references (e.g. [D7], [PASS-8:1])

Example format:
/**
 * config/app.php
 *
 * Application bootstrap — loaded by every entry point before anything else.
 * Parses .env, defines all FF_* constants, configures PHP runtime settings,
 * sets up session parameters, and loads Composer autoloader.
 *
 * Required by: public/index.php, api/bootstrap.php, all cron jobs
 * Defines: FF_ROOT, FF_BASE_PATH, FF_VERSION, FF_ENV, FF_DEBUG, 
 *          FF_DB_*, APP_URL, APP_TIMEZONE, env()
 *
 * Decisions: D7 (base path), D17 (PSR-4 autoload), D25 (function guards)
 * Spec ref:  PASS-8:1 (FF_LOADED guard), PASS-8:7 (PHP ini settings)
 */

2. Inline comments on any non-obvious logic explaining WHY 
   not just WHAT — especially for:
   - Security decisions
   - Business logic rules
   - Edge cases
   - bcmath usage
   - FOR UPDATE queries
   - State machine transitions

3. Every function must have a docblock comment explaining:
   - What it does
   - Parameters and types
   - Return value
   - Any exceptions or edge cases

Apply this standard to ALL files going forward — both files 
being built in this session and any files you touch or modify.

For existing files already built in S001, add this to the 
known issues list to retrofit comments in a dedicated cleanup 
session later.

Confirm you understand this standard before continuing.

*This file fills in the implementation details the spec describes conceptually.*
*Add to project knowledge alongside FLEETFORGE_SPEC_FINAL.md.*

---

## 2. DESIGN SETTINGS TAB (S-DESIGN-SETTINGS-FOOTER-LOGIN)

New tab in **Settings → Design** (super_admin only).
API endpoint: `api/v1/settings/brand.php` (POST, multipart/form-data).

**Settings rows added** (seeded by
`db_migrations/202605170200_S-DESIGN-SETTINGS-FOOTER-LOGIN_brand_settings_seed.sql`,
all INSERT IGNORE):

- `brand.primary_color`, `brand.primary_hover`, `brand.primary_light`
- `brand.logo_path`, `brand.favicon_path`
- `defaults.theme`, `defaults.density`, `defaults.font_size`, `defaults.rows_per_page`
- `regional.date_format`, `regional.time_format`, `regional.currency_symbol`,
  `regional.timezone`, `regional.distance_unit`
- `pdf.invoice_footer_text`, `pdf.show_logo`, `pdf.accent_color`
- `ui.sidebar_collapsed_default`, `ui.session_timeout_minutes`

**Brand injection:** `includes/header.php` injects `<style id="ff-brand-override">`
that re-binds `--color-primary` / `--color-primary-hover` / `--color-primary-light`
from the settings rows. The block sits **after** the `app.css` link so its
declarations win. When the rows are empty the block is skipped entirely
and `app.css` defaults survive. A second override below replaces the
favicon `<link rel="icon">` when `brand.favicon_path` is set.

**Color derivation** (computed server-side in `api/v1/settings/brand.php`
and stored alongside the primary so the runtime read is flat):

- `primary_hover` = each RGB channel × 0.88 (12% darker)
- `primary_light` = primary × 0.10 + 255 × 0.90 (90% white mix)

**Footer:** `includes/footer.php` reads `company.name` from settings.
Copyright string is `"© YYYY {company.name}. All rights reserved."`.
Fallback to `"Avi Technologies"` when the setting is empty.

**Login page:** `app/auth/login.php` reads `company.name`, `company.tagline`,
`brand.logo_path`, `brand.primary_color` (and the derived hover/light).
Shows the uploaded logo if set; otherwise renders an inline SVG truck
placeholder tinted with the brand color. Brand color is injected via
inline `<style id="ff-login-brand-override">` in the login page `<head>`.
Login footer mirrors the admin footer copyright string. Favicon override
also applies — uses the customer favicon at sign-in when configured.

**File storage:** Logo / favicon writes use `StorageClient::upload()`
under `branding/` (e.g. `branding/logo_1715961234.png`). Previous file
at `brand.logo_path` / `brand.favicon_path` is deleted **after** the
settings transaction commits — orphaned-file risk is preferred over
orphaned-row risk.
