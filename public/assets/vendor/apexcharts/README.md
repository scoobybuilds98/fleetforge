# Self-Hosted ApexCharts

**Origin:** https://cdn.jsdelivr.net/npm/apexcharts@3.45.1/dist/apexcharts.min.js
**Self-hosted via:** S-PROD-3 2026-05-14 — zero external CDN calls at runtime.
**Version pinned:** 3.45.1 (verified via file-header version comment).
**License:** MIT.

## Activation

Loaded globally from `includes/footer.php` via:
```php
<script src="<?= asset_url('assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>
```

`includes/footer.php` is included by every admin page (and never by portal
or auth pages), so ApexCharts is available on every admin surface that
renders a chart. Consumers:
- `app/admin/dashboard/index.php` (~8 charts)
- `app/admin/accounting/fixed-assets/index.php` (payoff chart)

## Updating

If a future session needs to upgrade ApexCharts, fetch the new minified
file from the CDN (or npm package), replace `apexcharts.min.js` in this
directory, update the version pin in `includes/footer.php`'s comment + in
this README, and bump `FF_ASSET_VERSION` so the cache-bust query string
on `app.js` invalidates the browser-cached chart code.

— S-PROD-3 (2026-05-14)
