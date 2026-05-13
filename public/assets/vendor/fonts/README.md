# Self-Hosted Web Fonts

**Origin:** Google Fonts (https://fonts.google.com).
**Self-hosted via:** S-PROD-3 2026-05-14 — zero external CDN calls at runtime.

## Families

- **DM Sans v17** (variable font, weight 300-700, italic + roman, latin + latin-ext subsets)
- **DM Mono v16** (static weights 300/400/500, italic 300 only, latin + latin-ext subsets)

## Files

Twelve `.woff2` files total, structured as:

```
dm-sans/
  dm-sans-italic-300-700-latin-ext.woff2
  dm-sans-italic-300-700-latin.woff2
  dm-sans-normal-300-700-latin-ext.woff2
  dm-sans-normal-300-700-latin.woff2
dm-mono/
  dm-mono-italic-300-latin-ext.woff2
  dm-mono-italic-300-latin.woff2
  dm-mono-normal-300-latin-ext.woff2
  dm-mono-normal-300-latin.woff2
  dm-mono-normal-400-latin-ext.woff2
  dm-mono-normal-400-latin.woff2
  dm-mono-normal-500-latin-ext.woff2
  dm-mono-normal-500-latin.woff2
```

## Activation

`@font-face` declarations live in `public/assets/css/app.css` under the
new "00. Self-hosted fonts" section (added in S-PROD-3 C2). Files are
referenced via relative path (`../vendor/fonts/...`) so CSS resolves
them from app.css's location automatically. No PHP rendering of font
URLs is needed; fonts are purely static.

## Updating

If a future session needs to upgrade DM Sans / DM Mono to a newer
version, re-fetch the @font-face CSS from Google Fonts (with a modern
browser User-Agent), download the new woff2 files, and replace the
existing files. The relative paths in app.css will continue to work
unchanged. Bump `FF_ASSET_VERSION` so browsers re-fetch app.css and
pick up the new font references.

## License

DM Sans + DM Mono are licensed under the [SIL Open Font License 1.1](https://scripts.sil.org/OFL).
The license permits self-hosting, redistribution, and use in commercial
projects.

— S-PROD-3 (2026-05-14)
