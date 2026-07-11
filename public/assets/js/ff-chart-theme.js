/* ============================================================
 * FleetForge — Global ApexCharts theme  (S-LUX-2, 2026-07-11)
 *
 * ONE place that turns the Atelier design tokens into an ApexCharts
 * options object. Every chart init becomes:
 *
 *     new ApexCharts(el, FF_CHART_THEME(pageOptions))
 *
 * where `pageOptions` carries ONLY that chart's data + intent
 * (series, labels, categories, chart.type/height, formatters,
 * deliberate semantic-color overrides). All shared styling —
 * font, palette, grid, axes, tooltip, legend, area gradient,
 * donut/bar geometry — comes from here.
 *
 * WHY read tokens at call-time (getComputedStyle) rather than
 * bake hex literals:
 *   - the white-label brand override (`<style id="ff-brand-override">`
 *     in includes/header.php) re-binds --color-primary at request
 *     time, so charts recolor to the tenant's brand with zero JS edits;
 *   - the light/dark toggle flips the same tokens, so on a theme
 *     change we just re-read them and updateOptions() (see the
 *     'ff:theme-changed' listener at the bottom — dispatched by
 *     FF_Theme.set() in app.js).
 *
 * Coexistence with the app.js ApexCharts monkey-patch
 * (patchApexChartsForResponsive): that patch only fills keys the
 * options DON'T already set (responsive breakpoint, tooltip.theme,
 * dataLabels.dropShadow). FF_CHART_THEME sets tooltip.theme
 * explicitly (so the patch stays inert there) and deliberately
 * LEAVES `responsive` + `dataLabels.dropShadow` unset so the patch
 * keeps supplying the mobile trim + the label halo. This file is a
 * pure options transformer — it never re-wraps the constructor.
 *
 * Load order (includes/footer.php): ApexCharts lib → THIS FILE →
 * app.js → Alpine. So FF_CHART_THEME + FF_ChartInstances exist
 * before app.js's patch registers instances, and long before any
 * Alpine init() constructs a chart.
 * ============================================================ */
(function () {
    'use strict';
    if (typeof window === 'undefined') return;

    // ── Token reader ────────────────────────────────────────────
    // Resolve a CSS custom property off <html> at call time, with a
    // sane fallback so charts still render if a token is missing.
    function tok(name, fallback) {
        try {
            const v = getComputedStyle(document.documentElement)
                .getPropertyValue(name).trim();
            return v || fallback;
        } catch (_e) {
            return fallback;
        }
    }

    /**
     * FF_CHART_TOKENS — the resolved Atelier values charts care about.
     * Fallbacks mirror the dark-theme defaults in app.css so a chart
     * built before first paint still looks right.
     */
    function FF_CHART_TOKENS() {
        return {
            primary:     tok('--color-primary', '#f97316'),
            info:        tok('--color-info', '#06b6d4'),
            success:     tok('--color-success', '#22c55e'),
            warning:     tok('--color-warning', '#eab308'),
            danger:      tok('--color-danger', '#ef4444'),
            textPrimary: tok('--text-primary', '#F4F1EA'),
            textSecondary: tok('--text-secondary', '#A6A094'),
            textTertiary:  tok('--text-tertiary', '#736D61'),
            border:        tok('--border-color', '#26231D'),
            borderStrong:  tok('--border-color-strong', '#36322A'),
            surface:       tok('--bg-surface', '#151310'),
            surface2:      tok('--bg-surface-2', '#1C1A16'),
            fontSans:      tok('--font-sans', '"Geist", -apple-system, sans-serif'),
        };
    }

    /** Ordered series palette (matches DESIGN_DETAILS chart-theme §). */
    function paletteFrom(t) {
        return [t.primary, t.info, t.success, t.warning, t.danger, t.textTertiary];
    }

    /** Is the app currently in light mode? (dark is the default.) */
    function isLight() {
        return document.documentElement.getAttribute('data-theme') === 'light';
    }

    // ── Deep merge ──────────────────────────────────────────────
    // Recursively merge `src` onto `dst` (mutating a fresh clone).
    // Arrays and functions are REPLACED wholesale, never merged —
    // so a chart's series/colors array or a formatter fn always wins
    // verbatim over the base. Plain objects merge key-by-key.
    function isPlainObject(x) {
        return x && typeof x === 'object' && !Array.isArray(x) &&
            Object.prototype.toString.call(x) === '[object Object]';
    }
    function deepMerge(dst, src) {
        const out = Array.isArray(dst) ? dst.slice() : Object.assign({}, dst);
        if (!isPlainObject(src)) return src === undefined ? out : src;
        Object.keys(src).forEach(function (k) {
            const sv = src[k];
            if (isPlainObject(sv) && isPlainObject(out[k])) {
                out[k] = deepMerge(out[k], sv);
            } else {
                out[k] = sv; // arrays, functions, primitives → replace
            }
        });
        return out;
    }

    /**
     * FF_CHART_THEME(overrides) → a fully-merged ApexCharts options
     * object: the Atelier base with `overrides` deep-merged on top.
     * Pass a chart's data + intent as `overrides`.
     */
    function FF_CHART_THEME(overrides) {
        const t = FF_CHART_TOKENS();
        const light = isLight();
        const axisLabel = { style: { colors: t.textTertiary, fontSize: '11px', fontFamily: t.fontSans } };

        const base = {
            chart: {
                background: 'transparent',
                fontFamily: t.fontSans,
                foreColor: t.textSecondary,
                animations: { enabled: true, speed: 400, easing: 'easeinout', animateGradually: { enabled: false } },
                // toolbar off by default; a page re-enables it via overrides
                toolbar: { show: false },
                parentHeightOffset: 0,
                redrawOnParentResize: true,
                redrawOnWindowResize: true,
            },
            // theme.mode + tooltip.theme keep ApexCharts' own internals
            // (e.g. tooltip contrast) aligned with the app theme; setting
            // tooltip.theme here makes the app.js patch inert for tooltips.
            theme: { mode: light ? 'light' : 'dark' },
            colors: paletteFrom(t),
            grid: {
                borderColor: t.border,
                strokeDashArray: 0,
                padding: { top: 4, right: 12, bottom: 4, left: 12 },
                xaxis: { lines: { show: false } },
                yaxis: { lines: { show: true } },
            },
            xaxis: {
                labels: axisLabel,
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: {
                labels: axisLabel,
            },
            stroke: { width: 2, curve: 'smooth' },
            // Area gradient — brand fade top→bottom. Only bites on
            // area/line charts; harmless elsewhere.
            fill: {
                type: 'solid',
                gradient: { shadeIntensity: 0, opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 100] },
            },
            dataLabels: { enabled: false },
            legend: {
                position: 'bottom',
                fontSize: '11px',
                fontFamily: t.fontSans,
                labels: { colors: t.textSecondary },
                markers: { width: 10, height: 10, radius: 12 },
                itemMargin: { horizontal: 8, vertical: 4 },
            },
            tooltip: {
                theme: light ? 'light' : 'dark',
                style: { fontSize: '12px', fontFamily: t.fontSans },
            },
            plotOptions: {
                bar: { borderRadius: 4, columnWidth: '48%', barHeight: '55%' },
                pie: { donut: { size: '68%' } },
            },
            states: {
                hover: { filter: { type: 'lighten', value: 0.06 } },
                active: { filter: { type: 'darken', value: 0.12 } },
            },
        };

        return deepMerge(base, overrides || {});
    }

    // ── Instance registry + theme re-render ─────────────────────
    // app.js's patched ApexCharts constructor calls FF_CHART_REGISTER
    // on every instance. On a theme toggle we push a fresh token
    // subset into each live chart via updateOptions — no reload, no
    // white flash. Dead instances (destroyed charts) throw on update
    // and get pruned.
    const instances = new Set();
    window.FF_ChartInstances = instances;
    window.FF_CHART_REGISTER = function (chart) {
        if (chart) instances.add(chart);
        return chart;
    };

    // The subset of options that actually change with the theme /
    // brand tokens. Series, formatters, geometry are untouched.
    function themeSubset() {
        const t = FF_CHART_TOKENS();
        const light = isLight();
        const axisLabel = { style: { colors: t.textTertiary, fontFamily: t.fontSans } };
        return {
            chart: { foreColor: t.textSecondary, fontFamily: t.fontSans },
            theme: { mode: light ? 'light' : 'dark' },
            colors: paletteFrom(t),
            grid: { borderColor: t.border },
            xaxis: { labels: axisLabel },
            yaxis: { labels: axisLabel },
            legend: { labels: { colors: t.textSecondary } },
            tooltip: { theme: light ? 'light' : 'dark' },
        };
    }

    function rethemeAll() {
        instances.forEach(function (chart) {
            try {
                if (chart && typeof chart.updateOptions === 'function') {
                    // (options, redrawPaths=false, animate=false) — cheap,
                    // no full re-animation, preserves series/zoom.
                    chart.updateOptions(themeSubset(), false, false);
                }
            } catch (_e) {
                instances.delete(chart); // destroyed / detached → drop it
            }
        });
    }

    window.addEventListener('ff:theme-changed', rethemeAll);

    // Exports
    window.FF_CHART_TOKENS = FF_CHART_TOKENS;
    window.FF_CHART_THEME = FF_CHART_THEME;
})();
