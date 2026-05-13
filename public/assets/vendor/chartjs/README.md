# Self-Hosted Chart.js

**Origin:** https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js
**Self-hosted via:** S-PROD-3 2026-05-14 — zero external CDN calls at runtime.
**Version pinned:** 4.4.3 (matches the original CDN URL; verified via
file-header comment "Original file: /npm/chart.js@4.4.3/dist/chart.umd.js").
**License:** MIT.

## Activation

Loaded conditionally on one surface (no global include):
- `app/admin/reservations/index.php` — donut + bar charts on the
  reservations dashboard (loaded deferred so it doesn't block table
  render).

Swapped from `cdn.jsdelivr.net/npm/chart.js@4.4.3/...` to
`asset_url('assets/vendor/chartjs/chart.umd.min.js')` per D27.

## Note on coexistence with ApexCharts

FleetForge uses both Chart.js (reservations module) and ApexCharts
(dashboard + accounting fixed-assets module). The two libraries are
independent; both are self-hosted in this `vendor/` directory tree.

## Updating

Re-fetch from cdn.jsdelivr or npm, replace `chart.umd.min.js` in this
directory, update the version pin in the consumer comment + this README,
and bump `FF_ASSET_VERSION` if any consumer page's CSS changes (the JS
itself doesn't have a cache-bust query string today; URL change is
sufficient).

— S-PROD-3 (2026-05-14)
