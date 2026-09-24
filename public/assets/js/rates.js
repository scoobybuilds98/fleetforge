/**
 * FleetForge — Rates module helpers (S-RATES-MODULE)
 *
 * @file        public/assets/js/rates.js
 * @description Shared by app/admin/rates/{index,show,create}.php and the
 *              customer profile's Rates tab:
 *
 *                FF_Rates.money / num / pct / date / status   display helpers
 *                FF_Rates.vs(value, standard)   "−17% vs standard" delta
 *                FF_Rates.gridMixin(opts)       the editable price table used
 *                                               by the card page and the
 *                                               New rate card page (lines,
 *                                               add / remove, adjust by %,
 *                                               validate, payload)
 *
 *              Money is only FORMATTED here; every figure that is saved or
 *              billed is computed server-side in bcmath (D16). The percentage
 *              adjust in the grid is a typing aid — the server re-validates
 *              every value it receives.
 *
 * Loaded after includes/header.php by those pages; depends on nothing at
 * load time (FF_Api is used lazily).
 *
 * @session     S-RATES-MODULE
 */
(function () {
    'use strict';

    const PRICE_FIELDS = ['daily_rate', 'weekly_rate', 'monthly_rate', 'mileage_rate', 'hourly_rate', 'gps_price'];
    const FIELD_LABELS = {
        daily_rate: 'Daily', weekly_rate: 'Weekly', monthly_rate: 'Monthly',
        mileage_rate: 'Distance', hourly_rate: 'Hourly', gps_price: 'GPS / day', minimum_days: 'Min. days',
        mileage_unit: 'Distance unit', currency: 'Currency',
    };
    const FIELD_DP = { daily_rate: 2, weekly_rate: 2, monthly_rate: 2, mileage_rate: 4, hourly_rate: 4, gps_price: 2 };
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    const isBlank = (v) => v === null || v === undefined || v === '';
    const toNum = (v) => (isBlank(v) ? null : Number(v));

    const FF_Rates = {
        PRICE_FIELDS,
        FIELD_LABELS,
        FIELD_DP,

        /** "$1,234.50" — or "—" for blank / zero (a blank price means "not offered"). */
        money(v, dp = 2, zeroDash = true) {
            const n = toNum(v);
            if (n === null || isNaN(n) || (zeroDash && n === 0)) return '—';
            const fixed = Math.abs(n).toFixed(dp);
            let [i, f] = fixed.split('.');
            i = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            // Trim trailing zeros past cents on 4-dp rates ($0.0400 → $0.04).
            if (dp > 2 && f) f = f.replace(/0+$/, '').padEnd(2, '0');
            return (n < 0 ? '-' : '') + '$' + i + (f ? '.' + f : '');
        },

        /** Plain number for inputs / compact chips ($50 not $50.00 when whole). */
        short(v) {
            const n = toNum(v);
            if (n === null || isNaN(n) || n === 0) return '—';
            return '$' + (Number.isInteger(n) ? n.toLocaleString('en-CA') : n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 4 }));
        },

        /** "+5%" / "−12.5%" from a numeric string. */
        pct(v) {
            const n = toNum(v);
            if (n === null || isNaN(n)) return '';
            const s = Math.abs(n) % 1 === 0 ? Math.abs(n).toFixed(0) : Math.abs(n).toFixed(1);
            return (n > 0 ? '+' : n < 0 ? '−' : '') + s + '%';
        },

        /** Compare a price to its standard → {cls, text} or null. */
        vs(value, standard) {
            const v = toNum(value), s = toNum(standard);
            if (v === null || s === null || isNaN(v) || isNaN(s) || s <= 0) return null;
            const p = ((v - s) / s) * 100;
            if (Math.abs(p) < 0.05) return { cls: 'rt-vs--same', text: 'same as standard' };
            return { cls: p < 0 ? 'rt-vs--below' : 'rt-vs--above', text: FF_Rates.pct(p.toFixed(1)) + ' vs standard' };
        },

        /** A server-computed percentage ("-12.5") → {cls, text}. */
        vsPct(p) {
            if (p === null || p === undefined || p === '') return null;
            const n = Number(p);
            if (Math.abs(n) < 0.05) return { cls: 'rt-vs--same', text: 'same as standard' };
            return { cls: n < 0 ? 'rt-vs--below' : 'rt-vs--above', text: FF_Rates.pct(n) + ' vs standard' };
        },

        /** "Sep 24, 2026" from Y-m-d (no timezone shift). */
        date(ymd) {
            if (!ymd) return '—';
            const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number);
            if (!y || !m || !d) return String(ymd);
            return MONTHS[m - 1] + ' ' + d + ', ' + y;
        },

        /** "Sep 2026 → open" style window. */
        window(from, to) {
            return FF_Rates.date(from) + ' → ' + (to ? FF_Rates.date(to) : 'open-ended');
        },

        /** Y-m-d for today + n days in the browser's local calendar. */
        ymd(offsetDays = 0, base = null) {
            const d = base ? new Date(base + 'T12:00:00') : new Date();
            d.setDate(d.getDate() + offsetDays);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },

        /** First day of next month (Y-m-d). */
        nextMonthStart() {
            const d = new Date();
            const n = new Date(d.getFullYear(), d.getMonth() + 1, 1, 12);
            return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-01';
        },

        daysUntil(ymd) {
            if (!ymd) return null;
            const t = new Date(FF_Rates.ymd() + 'T12:00:00'), d = new Date(String(ymd).slice(0, 10) + 'T12:00:00');
            return Math.round((d - t) / 86400000);
        },

        statusLabel(s) {
            return { active: 'In force', ending: 'Ending soon', upcoming: 'Starts later', expired: 'Ended' }[s] || s || '';
        },

        /** Short line prices for a chip: "$50/day · $600/mo". */
        chipPrices(l) {
            const parts = [];
            if (toNum(l.daily_rate) > 0) parts.push(FF_Rates.short(l.daily_rate) + '/day');
            if (toNum(l.monthly_rate) > 0) parts.push(FF_Rates.short(l.monthly_rate) + '/mo');
            else if (toNum(l.weekly_rate) > 0) parts.push(FF_Rates.short(l.weekly_rate) + '/wk');
            if (!parts.length && toNum(l.hourly_rate) > 0) parts.push(FF_Rates.short(l.hourly_rate) + '/hr');
            if (!parts.length && toNum(l.mileage_rate) > 0) parts.push(FF_Rates.short(l.mileage_rate) + '/' + (l.mileage_unit === 'miles' ? 'mi' : 'km'));
            return parts.length ? parts.join(' · ') : 'no prices';
        },

        /** Two-letter initials for an avatar. */
        initials(name) {
            const words = String(name || '').split(/[\s\-&.,]+/).filter(w => w && !['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'and'].includes(w.toLowerCase()));
            if (!words.length) return '?';
            return (words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[1][0]).toUpperCase();
        },

        /** Round a number to a step ('0.01' | '1' | '5'), half-up. */
        roundTo(n, step) {
            const s = Number(step) || 0.01;
            if (s === 0.01) return (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);
            return (Math.round(n / s) * s).toFixed(2);
        },

        /**
         * The editable price table, as an Alpine mixin. The page supplies:
         *   opts.options   [{value:'c:slug'|'t:id', label, group, category, template_id}]
         *   opts.standards {key: {daily, weekly, monthly, range}}
         * and keeps `this.lines` (array). Each line:
         *   { _k, key, equipment_type, equipment_template_id, label, scope,
         *     daily_rate … gps_price, mileage_unit, currency, minimum_days, notes, _orig }
         */
        gridMixin(opts) {
            let seq = 1;
            const optByValue = {};
            (opts.options || []).forEach(o => { optByValue[o.value] = o; });

            return {
                lineOptions: opts.options || [],
                standards: opts.standards || {},
                adjustPct: '',
                adjustRound: '1',
                newLineKey: '',

                /** Build a grid line from a stored / API row. */
                makeLine(row) {
                    const key = row.key || (row.equipment_template_id ? 't:' + row.equipment_template_id : 'c:' + row.equipment_type);
                    const o = optByValue[key] || {};
                    const line = {
                        _k: seq++,
                        id: row.id || null,
                        key,
                        equipment_type: row.equipment_type || o.category || (key.startsWith('c:') ? key.slice(2) : ''),
                        equipment_template_id: row.equipment_template_id || o.template_id || null,
                        label: row.label || o.label || key,
                        scope: row.scope || o.scope || '',
                        mileage_unit: row.mileage_unit || 'km',
                        currency: row.currency || 'CAD',
                        minimum_days: isBlank(row.minimum_days) ? '' : String(row.minimum_days),
                        notes: row.notes || '',
                    };
                    PRICE_FIELDS.forEach(f => { line[f] = isBlank(row[f]) ? '' : FF_Rates.clean(row[f], FIELD_DP[f]); });
                    line._orig = JSON.stringify(FF_Rates.lineComparable(line));
                    return line;
                },

                /** Options not already on the grid, for the "add" select. */
                availableOptions() {
                    const used = new Set(this.lines.map(l => l.key));
                    return this.lineOptions.filter(o => !used.has(o.value));
                },

                /** Add a line; prefill with `prices` when given (e.g. what they pay today). */
                addLine(key, prices = null) {
                    key = key || this.newLineKey;
                    if (!key || this.lines.some(l => l.key === key)) return null;
                    const o = optByValue[key] || {};
                    const line = this.makeLine(Object.assign({
                        key,
                        equipment_type: o.category,
                        equipment_template_id: o.template_id || null,
                        label: o.label,
                        scope: o.scope,
                    }, prices || {}));
                    line._orig = null; // new → always "changed"
                    this.lines.push(line);
                    this.newLineKey = '';
                    return line;
                },

                removeLine(line) {
                    this.lines = this.lines.filter(l => l._k !== line._k);
                },

                isChanged(line, field) {
                    if (!line._orig) return true;
                    const o = JSON.parse(line._orig);
                    return String(o[field] ?? '') !== String(FF_Rates.lineComparable(line)[field] ?? '');
                },

                linesDirty() {
                    return this.lines.some(l => !l._orig || l._orig !== JSON.stringify(FF_Rates.lineComparable(l)));
                },

                stdFor(line) { return this.standards[line.key] || null; },

                /** Raise / lower daily-weekly-monthly by adjustPct, rounded. */
                applyAdjust() {
                    const p = Number(this.adjustPct);
                    if (!p || isNaN(p)) return;
                    const factor = 1 + p / 100;
                    this.lines.forEach(l => {
                        ['daily_rate', 'weekly_rate', 'monthly_rate'].forEach(f => {
                            if (isBlank(l[f])) return;
                            l[f] = FF_Rates.roundTo(Math.max(0, Number(l[f]) * factor), this.adjustRound);
                        });
                    });
                    this.adjustPct = '';
                },

                /** Fill blank rent prices from the standard (single figure only). */
                fillFromStandard(line) {
                    const s = this.stdFor(line);
                    if (!s) return;
                    [['daily_rate', 'daily'], ['weekly_rate', 'weekly'], ['monthly_rate', 'monthly']].forEach(([f, k]) => {
                        if (isBlank(line[f]) && !isBlank(s[k])) line[f] = FF_Rates.clean(s[k], 2);
                    });
                },

                /** Client-side checks (the server re-checks everything). */
                gridProblems() {
                    const out = [];
                    const seen = new Set();
                    this.lines.forEach((l, i) => {
                        const name = l.label || ('Line ' + (i + 1));
                        if (seen.has(l.key)) out.push(name + ' is listed twice.');
                        seen.add(l.key);
                        PRICE_FIELDS.forEach(f => {
                            if (isBlank(l[f])) return;
                            const n = Number(l[f]);
                            if (isNaN(n)) out.push(name + ': ' + FIELD_LABELS[f] + ' must be a number.');
                            else if (n < 0) out.push(name + ': ' + FIELD_LABELS[f] + ' cannot be negative.');
                        });
                        if (!isBlank(l.minimum_days)) {
                            const m = Number(l.minimum_days);
                            if (!Number.isInteger(m) || m < 0 || m > 90) out.push(name + ': minimum days must be a whole number from 0 to 90.');
                        }
                        const any = PRICE_FIELDS.some(f => !isBlank(l[f]) && Number(l[f]) > 0);
                        const trio = ['daily_rate', 'weekly_rate', 'monthly_rate'].filter(f => Number(l[f]) > 0).length;
                        if (!any) out.push(name + ' has no prices — a lease would start at $0.');
                        else if (trio > 0 && trio < 3) {
                            out.push(name + ': daily, weekly and monthly go together — set all three, or leave all three blank.');
                        }
                    });
                    return out;
                },

                /** items[] for api/v1/rate_cards/{create,update,revise}. */
                gridPayload() {
                    return this.lines.map(l => {
                        const out = {
                            equipment_type: l.equipment_type,
                            equipment_template_id: l.equipment_template_id || null,
                            mileage_unit: l.mileage_unit,
                            currency: l.currency,
                            minimum_days: isBlank(l.minimum_days) ? null : l.minimum_days,
                            notes: l.notes || null,
                        };
                        PRICE_FIELDS.forEach(f => { out[f] = isBlank(l[f]) ? null : String(l[f]); });
                        return out;
                    });
                },
            };
        },

        /** The fields that decide "changed" for a grid line. */
        lineComparable(l) {
            const o = { key: l.key, mileage_unit: l.mileage_unit, currency: l.currency, minimum_days: isBlank(l.minimum_days) ? '' : String(Number(l.minimum_days)), notes: l.notes || '' };
            PRICE_FIELDS.forEach(f => { o[f] = isBlank(l[f]) ? '' : Number(l[f]).toFixed(FIELD_DP[f]); });
            return o;
        },

        /** Stored decimal → input string without noisy zeros ("50.00" → "50.00", "0.0400" → "0.04"). */
        clean(v, dp) {
            if (isBlank(v)) return '';
            const n = Number(v);
            if (isNaN(n)) return String(v);
            if (dp > 2) return String(Number(n.toFixed(dp)));
            return n.toFixed(2);
        },
    };

    window.FF_Rates = FF_Rates;
})();
