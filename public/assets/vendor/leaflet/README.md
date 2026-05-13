# Self-Hosted Leaflet

**Origin:** https://unpkg.com/leaflet@1.9.4/dist/
**Self-hosted via:** S-PROD-3 2026-05-14 — zero external CDN calls at runtime.
**Version pinned:** 1.9.4 (matches the original unpkg URL).
**License:** BSD 2-Clause.

## Contents

- `leaflet.js` — main library (~147 KB).
- `leaflet.css` — default theme (~14.8 KB).
- `images/` — default marker + control icons:
  - `marker-icon.png`, `marker-icon-2x.png` (retina)
  - `marker-shadow.png`
  - `layers.png`, `layers-2x.png` (retina layer-control toggle)

## Activation

Loaded conditionally on two surfaces (no global include):
- `app/admin/tracking/index.php` (fleet-wide tracking map)
- `app/admin/equipment/show.php` (per-unit GPS tracking tab)

Both swapped from `unpkg.com/leaflet@1.9.4/...` to
`asset_url('assets/vendor/leaflet/leaflet.{css,js}')` per D27.

## Why images/ matches Leaflet's layout

`leaflet.css` references marker images via relative path
`url(images/marker-icon.png)`. Browsers resolve relative URLs from the
CSS file's location, so placing the PNGs in `vendor/leaflet/images/`
(this directory) — mirroring upstream's `leaflet/dist/images/` layout —
means Leaflet's default CSS works unmodified. No `L.Icon.Default.imagePath`
override required.

## SRI hashes removed

The original CDN `<link>` and `<script>` tags carried `integrity="sha256-..."`
attributes for Subresource Integrity. Those are CDN-specific guards (they
verify the unpkg-served file matches a known hash). Self-hosted files are
served from our own origin, so SRI is unnecessary; tags were swapped to
plain `<link href>` / `<script src>` without integrity attributes.

## Updating

Re-fetch from unpkg or jsdelivr, replace files in this directory, bump
the version pin in the two consumer comments + this README, and bump
`FF_ASSET_VERSION` if the CSS changes.

— S-PROD-3 (2026-05-14)
