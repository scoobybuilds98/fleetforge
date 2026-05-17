# FLEETFORGE — DESIGN & IMPLEMENTATION DETAILS
**Companion to FLEETFORGE_SPEC_FINAL.md — fills in the exact values the spec describes conceptually.**

---

## 1. DESIGN TOKENS — EXACT CSS VARIABLE VALUES

The full token system lives at `public/assets/css/app.css` section "02. CSS Custom Properties (Design Tokens)" (lines ~215-337) with a dark-theme refresh block at lines 398-497 ("THEME-1 (2026-04-08) — Dark theme refresh"). **Brand identity is orange `#f97316`** ("fleet/logistics identity") — NOT blue. **Default theme is DARK** at `:root`; light is opt-in via `[data-theme="light"]`.

The runtime can override `--color-primary` / `--color-primary-hover` / `--color-primary-light` at request-time via `<style id="ff-brand-override">` injected in `includes/header.php` from the `brand.*` settings rows (S-DESIGN-SETTINGS-FOOTER-LOGIN, see Section 2 below). When those settings are empty, `app.css` defaults survive.

### 1.1 :root (dark theme — DEFAULT)

```css
:root {
  /* Brand colours */
  --color-primary:         #f97316;   /* orange — fleet/logistics identity */
  --color-primary-hover:   #ea6f00;
  --color-primary-light:   rgba(249, 115, 22, 0.14);
  --color-primary-text:    #fb923c;

  /* Semantic colours (same names in both themes; values change between :root and [data-theme="light"]) */
  --color-success:         #22c55e;   --color-success-light:   rgba(34, 197, 94, 0.13);   --color-success-text:    #4ade80;
  --color-warning:         #eab308;   --color-warning-light:   rgba(234, 179, 8, 0.13);   --color-warning-text:    #facc15;
  --color-danger:          #ef4444;   --color-danger-light:    rgba(239, 68, 68, 0.13);   --color-danger-text:     #f87171;
  --color-info:            #06b6d4;   --color-info-light:      rgba(6, 182, 212, 0.12);   --color-info-text:       #22d3ee;

  /* Dark surfaces (default theme) */
  --bg-body:               #0a0b0e;
  --bg-surface:            #111318;
  --bg-surface-2:          #181b23;
  --bg-surface-hover:      #1e2230;

  /* Text */
  --text-primary:          #f1f5f9;
  --text-secondary:        #94a3b8;
  --text-tertiary:         #64748b;
  --text-on-primary:       #ffffff;
  --text-on-danger:        #ffffff;

  /* Borders */
  --border-color:          #1d2133;
  --border-color-strong:   #2a3347;
  --border-focus:          #f97316;

  /* Sidebar (always dark — sidebar doesn't theme-shift) */
  --sidebar-width:           240px;
  --sidebar-width-collapsed:  64px;
  --sidebar-bg:              #0d0e12;
  --sidebar-border:          rgba(255, 255, 255, 0.06);
  --sidebar-text:            #6b7280;
  --sidebar-text-hover:      #f9fafb;
  --sidebar-text-active:     #ffffff;
  --sidebar-item-hover-bg:   rgba(255, 255, 255, 0.05);
  --sidebar-item-active-bg:  rgba(249, 115, 22, 0.15);   /* brand orange @ 15% */
  --sidebar-icon-active:     #f97316;
  --sidebar-section-text:    #4b5563;
  --sidebar-brand-text:      #f9fafb;

  /* Topbar */
  --topbar-height:         60px;
  --topbar-bg:             #111318;
  --topbar-border:         #1d2133;
  --topbar-text:           #f1f5f9;

  /* Shadows (deeper alpha on dark bg) */
  --shadow-xs:  0 1px 2px rgba(0, 0, 0, 0.30);
  --shadow-sm:  0 1px 4px rgba(0, 0, 0, 0.40), 0 1px 2px rgba(0, 0, 0, 0.25);
  --shadow-md:  0 4px 12px rgba(0, 0, 0, 0.45), 0 2px 4px rgba(0, 0, 0, 0.25);
  --shadow-lg:  0 10px 24px rgba(0, 0, 0, 0.55), 0 4px 8px rgba(0, 0, 0, 0.30);
  --shadow-xl:  0 20px 40px rgba(0, 0, 0, 0.60), 0 8px 16px rgba(0, 0, 0, 0.35);
  --shadow-glow-primary: 0 0 20px rgba(249, 115, 22, 0.18);

  /* Radius */
  --radius-sm:   4px;
  --radius-md:   6px;
  --radius-lg:   8px;
  --radius-xl:  12px;
  --radius-full: 9999px;

  /* Transitions */
  --transition-fast:   120ms ease;
  --transition-base:   200ms ease;
  --transition-slow:   280ms ease;

  /* Z-index stack */
  --z-sidebar:   40;
  --z-topbar:    30;
  --z-dropdown:  50;
  --z-modal:     60;
  --z-toast:     70;

  /* Form (Apple-aesthetic — see Section 1.5 for the full pill-input docs) */
  --input-height:      40px;
  --input-height-sm:   32px;
  --input-height-lg:   48px;
  --input-bg:          rgba(140, 130, 115, 0.18);
  --input-bg-focus:    rgba(140, 130, 115, 0.32);
  --input-border:      #2a3347;
  --input-text:        rgba(255, 255, 255, 0.96);
  --input-placeholder: rgba(235, 230, 220, 0.40);
  --label-text:        rgba(235, 230, 220, 0.60);
  --label-text-strong: rgba(255, 255, 255, 0.85);

  /* Spec-name aliases (FIX #28) — keep both conventions working */
  --bg-page:      var(--bg-body);
  --bg-card:      var(--bg-surface);
  --color-accent: var(--color-primary);
  --color-accent-hover: var(--color-primary-hover);
  --text-muted:   var(--text-tertiary);
  --text-inverse: var(--text-on-primary);
  --border-strong: var(--border-color-strong);
  --bg-selected:  rgba(249, 115, 22, 0.12);   /* FIX #35 */
}
```

### 1.2 [data-theme="light"]

```css
[data-theme="light"] {
  --color-primary:         #ea6f00;
  --color-primary-hover:   #c45f00;
  --color-primary-light:   #fff7ed;
  --color-primary-text:    #c2410c;

  --color-success:         #16a34a;   --color-success-light:   #dcfce7;   --color-success-text:    #15803d;
  --color-warning:         #d97706;   --color-warning-light:   #fef3c7;   --color-warning-text:    #b45309;
  --color-danger:          #dc2626;   --color-danger-light:    #fee2e2;   --color-danger-text:     #b91c1c;
  --color-info:            #0891b2;   --color-info-light:      #cffafe;   --color-info-text:       #0e7490;

  --bg-body:               #f8fafc;
  --bg-surface:            #ffffff;
  --bg-surface-2:          #f1f5f9;
  --bg-surface-hover:      #f8fafc;

  --text-primary:          #0f172a;
  --text-secondary:        #475569;
  --text-tertiary:         #94a3b8;

  --border-color:          #e2e8f0;
  --border-color-strong:   #cbd5e1;
  --border-focus:          #ea6f00;

  --topbar-bg:             #ffffff;
  --topbar-border:         #e2e8f0;
  --topbar-text:           #0f172a;

  --shadow-xs:  0 1px 2px rgba(0, 0, 0, 0.05);
  --shadow-sm:  0 1px 3px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.04);
  --shadow-md:  0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
  --shadow-lg:  0 10px 15px -3px rgba(0, 0, 0, 0.10), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  --shadow-xl:  0 20px 25px -5px rgba(0, 0, 0, 0.10), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

  /* Apple cool gray (118,118,128) for light-mode inputs */
  --input-bg:          rgba(118, 118, 128, 0.12);
  --input-bg-focus:    rgba(118, 118, 128, 0.22);
  --input-border:      #cbd5e1;
  --input-text:        rgba(0, 0, 0, 0.92);
  --input-placeholder: rgba(60, 60, 67, 0.40);
  --label-text:        rgba(60, 60, 67, 0.72);   /* bumped from Apple 0.60 for WCAG AA */
  --label-text-strong: rgba(0, 0, 0, 0.85);
}
```

### 1.3 [data-theme="dark"] (explicit refresh — THEME-1, 2026-04-08)

A warm brownish-black palette overlays the `:root` defaults when the user explicitly toggles dark mode. Strictly scoped to `[data-theme="dark"]`; light is untouched. Variable NAMES match `:root` so the rest of the codebase keeps rendering — only VALUES are rebased onto the warm palette. New spec names (`--bg-card`, `--bg-page`, `--bg-muted`, `--bg-hover`, `--bg-input`, `--bg-secondary`, `--text-disabled`, `--input-focus-bg`, `--scrollbar-*`, `--table-*`, `--sidebar-text-muted`, `--sidebar-hover`, `--sidebar-active`, `--sidebar-icon`) are added so spec selectors work without rewriting every existing rule. `--bg-card` + `--bg-page` are *redefined* (overriding the `:root` aliases) so cards get the deep `#141413` instead of the fall-through `var(--bg-surface)`. `--color-accent` / `--color-success` / `--color-warning` / `--color-danger` / `--color-info` are NEVER touched — they stay theme-agnostic.

```css
[data-theme="dark"] {
  --bg-body:               #262624;
  --bg-page:               #262624;
  --bg-surface:            #141413;
  --bg-card:               #141413;
  --bg-surface-2:          #1f1e1d;
  /* … plus the spec-name aliases listed above … */
}
```

See `public/assets/css/app.css:418-497` for the full block.

### 1.4 Status badge palette (theme-aware)

Badge backgrounds + text colours live in the `--color-{success,warning,danger,info}-light` and `--color-{success,warning,danger,info}-text` token pairs above. There are no separate `--badge-*` tokens — `.badge-success`, `.badge-warning`, etc. consume the light/text token pairs directly. Light mode: lighter pastel fills with darker text; dark mode: darker fills with brighter text (per `:root` defaults).

For status → badge-class mapping by domain (Customer / Equipment / Lease / Invoice / Payment / Reservation / Work Order / Risk Score) see Section 9 below.

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

**Save mechanics (S-DESIGN-SETTINGS-DEBUG, 2026-05-17):** the brand
endpoint writes via `INSERT ... ON DUPLICATE KEY UPDATE` (not UPDATE-only)
so saves self-heal when the seed migration has not yet been applied.
This is the canonical pattern for any settings write.

**Sidebar logo render (S-DESIGN-SETTINGS-DEBUG / S-DESIGN-LOGO-* arc):**
`includes/sidebar.php` reads `brand.logo_path` (NOT the never-written
`company.logo_url`) and resolves it through `StorageClient::url()` for
the signed URL. The `.sidebar-brand` area is 96px tall (S-DESIGN-LOGO-BIGGER),
the logo fills the track via `width:100% height:auto max-height:80px`
(S-DESIGN-LOGO-WIDE), and the company name moved out of the sidebar to
a `.topbar-company-name` breadcrumb root with a `›` separator before
the page title (S-DESIGN-LOGO-TOPBAR). Collapsed-sidebar (≥1024px) +
tablet (768-1023px) breakpoints shrink the brand area back to topbar
height + cap the logo at 40×44 so wide wordmarks don't overflow the
64px icon-only track.

---

## 3. UNDOCUMENTED COMPONENT SECTIONS (rolling index)

`public/assets/css/app.css` carries 24+ named sections. Sections covered
elsewhere in this doc: 02 (Tokens — §1 above), 10 (Forms — §1.5 above),
S-LEASE-UNITS (§1.6 above), 04 (Dashboard Grid — §4 below), 09 (Badges —
§9 below). The remaining sections are listed here as a navigation index
with one-line descriptions. Source-of-truth values stay in `app.css`.

| § | app.css section | Range | What it owns |
|---|------------------|-------|--------------|
| 03 | Typography | ~498-552 | DM Sans (UI) + DM Mono (data) — base font scale, heading sizes, `.font-mono` data cell |
| 04 | App Layout Shell | ~552-573 | `.app-shell`, `.app-main`, `.sidebar-collapsed` modifier, viewport-height container math |
| 05 | Sidebar | ~573-1011 | `.sidebar`, `.sidebar-brand` (96px tall per S-DESIGN-LOGO-BIGGER), `.sidebar-logo` (width:100% per S-DESIGN-LOGO-WIDE), nav items, collapsed-state media queries, scrollbar |
| 06 | Topbar | ~1012-1714 | `.topbar`, `.topbar-company-name` breadcrumb root (S-DESIGN-LOGO-TOPBAR), `›` separator, global search trigger, notifications bell, display-settings popover (PERM-1 font/density), user menu (NOTIF-1 follow-up) |
| 07 | Page Content & Panels | ~1714-1918 | `.page-content` density variants (PERM-1: compact/comfortable/spacious), `.dl-grid` label/value detail grid, `.section-divider` eyebrow separator, show-page sub-table height containment |
| Stats | Stats / Summary tiles | ~1919-2102 | `.stat-card` modern + legacy structures, currency value sizing (~$1,234,567.89 must fit), `.stat-card__header` / `.stat-card__icon` |
| 08 | Buttons | ~2102-2360 | 8 variants × 5 sizes — see §2 above |
| VALID-2 | Field validation messaging | ~2527-2674 | `.is-invalid` / `.ff-invalid` ring rules + helper text below input + form-level error summary card |
| 11 | Tables | ~2675-2830 | `.table` base, `.table-wrapper` overflow shell, sort indicators, sticky headers, density modifiers |
| 12 | Modals | ~2831-2951 | Size variants — see §6 above |
| 13 | Toasts | ~2952-3045 | Position/duration/animation — see §5 above |
| 14 | Notification Dropdown | ~3046-3073 | Alpine x-transition rules for the notifications-bell dropdown |
| 14b | Accounting Sub-Nav | ~3074-3243 | `.acc-topnav` — accounting module's secondary nav strip |
| 15 | Global Search Modal | ~3244-3390 | Cmd-K palette modal, `.search-input`, result list, keyboard nav |
| 16 | Skeleton Loaders | ~3391-3451 | Shimmer animation — see §7 above |
| 17 | Utilities | ~3452-3547 | Margin/padding helpers, `.text-*`, display utilities |
| 18 | Alpine Helpers | ~3548-3593 | x-transition presets, `[x-cloak]` hide rule |
| 19 | Responsive / Mobile | ~3594-3789 | Breakpoint media queries — see §10 below |
| 20 | Dashboard & Page-Specific | ~3789-4321 | `.dashboard-grid` family (S-DASHBOARD-CHART-POLISH: `--charts 2fr 1fr` / `--equal 1fr 1fr` / `--widgets 1fr 1fr` + single-column under 1024px), `.chart-wrap` flex shrink-past-content trio, `.dashboard-widget` density (S-DASHBOARD-CHART-POLISH C2: font 0.8125rem + cell padding 10/12px + font-mono ellipsis-END), `.dashboard-grid--widgets th/td:last-child` hide (S-DASHBOARD-CHART-POLISH C3) |
| 30 | Customer Portal (S024) | ~4322-6316 | Portal-mirror class set: `.portal-form-*`, `.portal-search-input`, `.portal-filter-select`, portal layout shell, mobile bottom tab bar (≤640px) |
| NOTIF-1 | Notifications system | ~6317-6692 | Bell, dropdown card, full-page index, badge counts, mark-read transitions |
| EMAIL-1 | Email Compose Modal | ~6693-6996 | Global email composer (record-picker integration, attachment dropzone, recipient autocomplete) |
| MEDIA-1 | Sound mute toggle | ~6997-7029 | Topbar mute button + persistence |
| CHAT-1 | Team Chat | ~7030-8018 | Channel sidebar, message list, composer, reactions, threading |
| MSGR-1 (admin) | Customer Messenger admin | ~8019-8182 | Admin-side messenger thread list + composer + recipient picker |
| MSGR-1 (portal) | Customer Messenger portal | ~8183-8516 | Portal-side messenger UI (mirror of admin, simplified for customers) |
| FF_RecordPicker | Reusable record selector | ~8517-9004 | Search-as-you-type entity picker (Customer / Lease / Equipment / Invoice / etc.), used by email compose + chat attach + portal request flows |
| S-LEASE-UNITS | Segmented control | ~9005+ | Apple iOS pill toggle — see §1.6 above |

Sections added since 2026-05-13 (rolling 30-day window): `00. Self-hosted Fonts` (S-PROD-3, 2026-05-14), display-settings density rules in section 06 (PERM-1 2026-05-14 / S-DISPLAY-REVAMP 2026-05-14 collapsed-sidebar nav-badge fix at app.css:927), the `.dashboard-grid` family in section 20 (S-DASHBOARD-CHART-POLISH, 2026-05-17), and the `.topbar-company-name` block in section 06 (S-DESIGN-LOGO-TOPBAR, 2026-05-17).

---

## 4. SETTINGS REGISTRY (live DB snapshot — 2026-05-17)

The `settings` table is the runtime config store. Schema: `id` / `key` UNIQUE / `value` longtext / `value_type` enum / `group_name` / `label` / `description` / `is_public` tinyint / `updated_by` / `updated_at`. Only `is_public=1` rows are readable by portal users; admin-side reads bypass the gate.

**Group count (live, 147 rows total):**

| Group | Rows | Originating session(s) |
|-------|-----:|------------------------|
| accounting | 31 | S028 (Phase 1 — Accounting Foundation, 2026-04-05) + S028-b (audit + cron overrides, 2026-04-05) + S028-c (root-cause fix + Top Nav, 2026-04-05) + S034 (Fixed Assets, 2026-04-07) + S035 (Tax, 2026-04-07) |
| ai | 6 | S027 (Claude AI Integration Phase 16, 2026-04-05) — `ai.enabled`, `ai.daily_token_limit`, `ai.model`, `ai.anthropic_api_key`, `ai.cache_summaries`, `ai.summary_ttl_hours` |
| alerts | 4 | S003 (Seed Data Foundation, 2026-04-01) — `alerts.compliance_warning_days`, `alerts.compliance_critical_days`, `alerts.lease_end_reminder_days`, `alerts.overdue_invoice_days` |
| app | 1 | S001 (Foundation) — `app.url` |
| archive | 2 | S-CRON-2 (Integrity & Cleanup, 2026-05-02) — `archive.audit_log_retention_days`, `archive.notification_log_retention_days` |
| aws | 4 | S001 / S-PROD-2 (Sentry + SES, 2026-05-02) — `aws.access_key_id`, `aws.region`, `aws.s3_bucket`, `aws.secret_access_key` |
| billing | 1 | S-BILLING-HOLISTIC-ENGINE (2026-05-17) — `billing.engine_version` (migration 202605170105, INSERT IGNORE, is_public=0) |
| brand | 5 | S-DESIGN-SETTINGS-FOOTER-LOGIN (2026-05-17) — `brand.primary_color`, `brand.primary_hover`, `brand.primary_light`, `brand.logo_path`, `brand.favicon_path` |
| company | 20 | S001 + S003 (seed) + S025 (Settings Overhaul, 2026-04-05) — `company.name`, `company.address`, `company.phone`, `company.email`, `company.website`, `company.gst_number`, `company.pst_number`, `company.timezone`, `company.tagline`, `company.logo_url`, `company.currency_symbol`, `company.currency`, `company.country`, `company.city`, `company.province`, `company.postal_code`, `company.bank_name`, `company.bank_account`, `company.check_payable_to`, `company.payment_instructions` |
| credit_notes | 1 | S011 (Credit Notes Module, 2026-04-02) — `credit_note.next_number.2026` |
| currency | 1 | migration 036 (currency_markup) — `currency.usd_cad_markup_pct` |
| damage_claims | 1 | S012 (Damage Claims, 2026-04-02) — `damage_claim.next_number.2026` |
| defaults | 4 | S-DESIGN-SETTINGS-FOOTER-LOGIN (2026-05-17) — `defaults.theme`, `defaults.density`, `defaults.font_size`, `defaults.rows_per_page` |
| email | 6 | S025 / S-PROD-2 — `email.from_email`, `email.from_name`, `email.smtp_host`, `email.smtp_port`, `email.smtp_user`, `email.smtp_pass` |
| gps | 7 | S026 (Samsara GPS Tracking Phase 10, 2026-04-05) + SAMSARA-1 (Full Integration, 2026-04-07) — `gps.primary_provider`, `gps.samsara_api_key`, `gps.samsara_org_id`, `gps.geotab_username`, `gps.geotab_password`, `gps.geotab_database`, `gps.sync_interval_minutes` |
| insp | 1 | S016 (Inspections Module, 2026-04-03) — `insp.next_number.2026` |
| invoices | 8 | S003 + S008 (Invoices Module, 2026-04-02) + migration 037 (advance billing) — `invoice.prefix`, `invoice.due_days_default`, `invoice.payment_instructions`, `invoice.late_fee_percentage`, `invoice.next_number.2026`, `billing.max_advance_periods`, `credit_note.prefix`, `payment.prefix` |
| leases | 3 | S007 (Leases Module, 2026-04-01) + migration 038 (lease_dual_units) — `lease.prefix`, `lease.km_to_miles_default`, `lease.miles_to_km_default` |
| maintenance | 1 | S015 (Maintenance Work Orders, 2026-04-03) — `damage_claim.prefix` (filed under maintenance group historically) |
| notifications | 8 | S025 + S-CRON-3 (Notifications & Digest, 2026-05-02) — `notifications.digest_hour`, `notifications.email_enabled`, `notifications.smtp_from`, `notifications.smtp_from_name`, `notifications.smtp_host`, `notifications.smtp_port`, `notifications.smtp_user`, `notifications.smtp_pass` |
| payments | 1 | S009 (Payments Module, 2026-04-02) — `payment.next_number.2026` |
| pdf | 3 | S-DESIGN-SETTINGS-FOOTER-LOGIN (2026-05-17) — `pdf.invoice_footer_text`, `pdf.show_logo`, `pdf.accent_color` |
| regional | 5 | S-DESIGN-SETTINGS-FOOTER-LOGIN (2026-05-17) — `regional.date_format`, `regional.time_format`, `regional.currency_symbol`, `regional.timezone`, `regional.distance_unit` |
| reservations | 1 | S018 (Reservations Module, 2026-04-03) — `reservations.stale_after_days` |
| samsara | 1 | S-MILEAGE-1B (Samsara historical-distance, 2026-05-04) — `samsara.fixture_mode` (boolean, gates the FixtureProvider path) |
| security | 16 | S-PROD-1A (MFA + IP rate limiting, 2026-05-02) + S-SETTINGS-CLEANUP (2026-05-14, label backfill via migration 202605140907) — `security.mfa.required_roles`, `security.mfa.totp_window`, `security.mfa.backup_code_count`, plus 13 `security.rate_limit.*` rows (login_ip, mfa_ip, mfa_user, forgot_password_ip, ai_user — each with threshold + window_minutes + block_minutes where applicable) |
| storage | 1 | S001 — `storage.driver` (local / s3) |
| ui | 2 | S-DESIGN-SETTINGS-FOOTER-LOGIN (2026-05-17) — `ui.sidebar_collapsed_default`, `ui.session_timeout_minutes` |
| wo | 1 | S015 — `wo.next_number.2026` |
| yards | 1 | S018-EXT (Reservations UX — Yards Management, 2026-04-03) — `yard.default` |

**Editing surface:** the runtime Settings UI lives at `app/admin/settings/index.php` with tabs for Company / Branding / Notifications / Integrations / Users / Portal Users / Audit Log / Email Templates / System / **Design** (super_admin only — S-DESIGN-SETTINGS-FOOTER-LOGIN, 2026-05-17). Design tab covers `brand.*` + `defaults.*` + `regional.*` + `pdf.*` + `ui.*` (the 19 rows seeded by migration 202605170200). Integrations tab carries the MFA + AWS + GPS + Email/SMTP blocks. Counter rows (`*.next_number.YYYY`) are updated by their owning modules at issuance time, never exposed in the UI.

---

## 3. LEGAL PAGES & FOOTER SYSTEM (S-LEGAL-FOOTER-COMMERCIAL, 2026-05-17)

Single-source-of-truth for company / legal metadata + 6 public legal pages + 3 footer variants tuned for their surface.

### Config + helpers

- **`config/legal.php`** — company info (legal_name, brand_name, product_name, address, governing_law, support / privacy / legal / security emails, website), effective + last-updated dates, registry of 6 legal pages (slug → title + URL).
- Loaded into `$GLOBALS['_ff_legal']` by `config/app.php` at boot.
- **`legal_config('dot.path')`** in `includes/functions.php` reads the config with dot notation; returns `null` for missing keys.
- **`legal_url('slug')`** returns the full URL via `base_url('/legal/' . slug)`.

### Public routes

`public/index.php` routes `/legal/*` to `FF_ROOT . '/app/legal/'`. Resolved page files deliberately do NOT call `require_auth*()` so anonymous visitors can read them. The same `resolve_route()` security guarantees apply (segment validation, traversal blocked, realpath verified inside tree).

| Slug | URL | File |
|------|-----|------|
| terms    | `/fleetforge/legal/terms`    | `app/legal/terms.php`    |
| privacy  | `/fleetforge/legal/privacy`  | `app/legal/privacy.php`  |
| aup      | `/fleetforge/legal/aup`      | `app/legal/aup.php`      |
| dpa      | `/fleetforge/legal/dpa`      | `app/legal/dpa.php`      |
| cookies  | `/fleetforge/legal/cookies`  | `app/legal/cookies.php`  |
| security | `/fleetforge/legal/security` | `app/legal/security.php` |

### Shared shell

- **`includes/legal_header.php`** — light-theme-forced standalone surface with sticky nav (brand logo + Terms / Privacy / Acceptable Use / Security / Contact links) and self-contained CSS scoped under `.legal-*` so the global tokens are not inherited.
- **`includes/legal_footer.php`** — full 6-page link list + support email + copyright + Avi Technologies attribution.
- Nav logo follows the same 3-source resolution chain as `app/auth/login.php`: `brand.logo_path` settings → `public/media/login-logo.{svg,png,jpg,jpeg}` file → inline SVG truck.

### Footer variants

| Surface | File | Layout |
|---------|------|--------|
| Admin   | `includes/footer.php`               | `.app-footer` — slim flex row (brand attribution left, 4 legal links middle, copyright right; collapses to centered single-column under 900px) |
| Login   | `app/auth/login.php`                | `.login-footer` — centered (5 legal links + copyright + Avi attribution; lives inside the auth-card) |
| Portal  | `app/portal/includes/footer.php`    | `.portal-footer` — 2-column commercial (brand block left with logo + tagline + Avi attribution; Legal + Support link columns right; copyright + version pill bottom; collapses to single column under 768px) |

All three footers read company name from `settings_get('company.name')` with fallback to `legal_config('company.brand_name')` = "Avi Technologies". All legal links use `legal_url()` so route changes propagate from a single point.

---

### 3.1 New Client Deployment Recipe

When deploying FleetForge for a client other than Mainland Truck & Trailer:

**Step 1 — config/legal.php**
Replace: `legal_name`, `brand_name`, `product_name`, `address`,
`governing_law`, and all four `email_*` fields with the new operator's info.

**Step 2 — Settings → Design (in the running app)**
Set: `company.name`, `company.tagline`
Upload: `brand.logo_path`, `brand.favicon_path`
Pick: `brand.primary_color`

**Step 3 — Static logo fallback (optional)**
Drop `media/login-logo.{ext}` as a fallback only if Step 2 isn't done.

**Step 4 — Legal pages review**
Review each `app/legal/*.php` for client-specific claims:
jurisdiction clauses, sub-processor lists, retention periods.

The footers need no manual edits — they pull everything automatically
from `settings_get()` + `legal_config()`.

---

### 3.2 Key Distinctions & Watch Points

**Two separate "company name" concepts — do not conflate:**
- `company.name` (DB settings) — customer-facing name shown in footers
  e.g. "Mainland Truck & Trailer Sales"
- `company.brand_name` (config/legal.php) — the SOFTWARE VENDOR shown as
  "A software by …" e.g. "Avi Technologies"
  This never changes per deployment — it always refers to Avi Technologies.

**Legal pages are public routes:**
`/legal/*` pages are intentionally accessible without login so customers
can audit terms. If a deployment ever needs to gate them (e.g. white-label
where the vendor identity is confidential), add a route guard in
`public/index.php` before the legal route block.

**Legal email addresses are hardcoded in config/legal.php, not DB-editable:**
This is intentional — legal contacts rarely change and DB-editing risks
an admin pointing them at the wrong inbox. If DB-editable legal emails
are ever needed, move them to `settings_get('legal.email_*')` with
`legal_config()` as fallback — approximately a 10-minute change.
