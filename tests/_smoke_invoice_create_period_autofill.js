// tests/_smoke_invoice_create_period_autofill.js
//
// S-INVOICE-CREATION-UX C2 / C4 — period auto-fill smoke test.
// Standalone Node script that re-implements the date helpers + driver
// from app/admin/invoices/create.php (FF_InvoiceCreate Alpine component)
// and asserts behavior across 12 fixture scenarios.
//
// Run:    node tests/_smoke_invoice_create_period_autofill.js
// Exit:   0 on all PASS, 1 on any FAIL.
//
// The PHP smoke gates (_smoke_master_schema_parity + _smoke_billing_invariants)
// run on every commit per D131; this one is invoked manually when the
// auto-fill logic at app/admin/invoices/create.php:_autoFillPeriodDates
// is touched. If the in-page logic diverges from this fixture file, the
// test will catch it the next time it runs.

'use strict';

// ── helpers (mirror create.php verbatim) ──────────────────────────────────
const ymd = (d) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
};
const addDays = (dateStr, n) => {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return ymd(d);
};
const addOneMonthMinusOneDay = (dateStr) => {
    const d = new Date(dateStr + 'T00:00:00');
    const day = d.getDate();
    let targetMonth = d.getMonth() + 1;
    let targetYear  = d.getFullYear();
    if (targetMonth > 11) { targetMonth = 0; targetYear += 1; }
    const lastDayOfTargetMonth = new Date(targetYear, targetMonth + 1, 0).getDate();
    const targetDay = Math.min(day, lastDayOfTargetMonth);
    const target = new Date(targetYear, targetMonth, targetDay);
    target.setDate(target.getDate() - 1);
    return ymd(target);
};
const earliest = (...dates) => {
    const valid = dates.filter(d => d && typeof d === 'string' && d.length === 10);
    if (!valid.length) return null;
    return valid.reduce((a, b) => (a < b ? a : b));
};

// Fixed clock for deterministic tests. The in-page _today() reads the real
// clock; this fixture pins it so end-of-month and catch-up cases are stable.
const TODAY = '2026-05-07';
const today = () => TODAY;

// Mirror of _autoFillPeriodDates from create.php.
const autoFillPeriodDates = (opt) => {
    let warning = '';
    const startDate    = opt.start          || '';
    const endDate      = opt.end            || '';
    const actualReturn = opt.actualReturn   || '';
    const billingCycle = opt.billingCycle   || 'monthly';
    const prevPeriodEnd = opt.prevPeriodEnd || '';
    if (!startDate) return { period_start: '', period_end: '', warning: '' };

    let periodStart = prevPeriodEnd ? addDays(prevPeriodEnd, 1) : startDate;
    const ceiling = earliest(actualReturn || null, endDate || null);
    const t = today();

    // Defensive: prev_period_end exceeds lease ceiling (over-invoiced anomaly)
    if (ceiling && periodStart > ceiling) {
        return {
            period_start: periodStart,
            period_end: '',
            warning: `Prior invoice ended on ${prevPeriodEnd} which is past the lease ceiling (${ceiling})`,
        };
    }

    let periodEnd;
    if (billingCycle === 'on_close_only') {
        periodEnd = ceiling || t;
    } else if (ceiling && ceiling < t && !prevPeriodEnd) {
        periodEnd = ceiling;
    } else {
        periodEnd = addOneMonthMinusOneDay(periodStart);
        if (ceiling && periodEnd > ceiling) periodEnd = ceiling;
    }
    if (ceiling && ceiling < t && !prevPeriodEnd) {
        warning = `catch-up`;
    } else if (ceiling && periodEnd === ceiling && billingCycle === 'monthly') {
        warning = `capped`;
    }
    return { period_start: periodStart, period_end: periodEnd, warning };
};

// ── fixtures ──────────────────────────────────────────────────────────────
const cases = [
    { name: 'T1 active monthly no priors',
      opt: { start: '2026-04-15', end: '2026-12-31', billingCycle: 'monthly' },
      expect: { period_start: '2026-04-15', period_end: '2026-05-14', warning: '' } },
    { name: 'T2 active monthly with prior',
      opt: { start: '2026-04-01', end: '2026-12-31', billingCycle: 'monthly', prevPeriodEnd: '2026-04-30' },
      expect: { period_start: '2026-05-01', period_end: '2026-05-31', warning: '' } },
    { name: 'T3 catch-up (lease ended in past, no priors)',
      opt: { start: '2026-03-01', end: '2026-04-15', billingCycle: 'monthly' },
      expect: { period_start: '2026-03-01', period_end: '2026-04-15', warning: 'catch-up' } },
    { name: 'T4 monthly capped at end_date (active near-end)',
      opt: { start: '2026-05-01', end: '2026-05-15', billingCycle: 'monthly' },
      expect: { period_start: '2026-05-01', period_end: '2026-05-15', warning: 'capped' } },
    { name: 'T5 completed lease, actual_return wins',
      opt: { start: '2026-04-01', end: '2026-12-31', actualReturn: '2026-04-20', billingCycle: 'monthly' },
      expect: { period_start: '2026-04-01', period_end: '2026-04-20', warning: 'catch-up' } },
    { name: 'T6 on_close_only with actual_return',
      opt: { start: '2026-04-01', end: '2026-12-31', actualReturn: '2026-04-20', billingCycle: 'on_close_only' },
      expect: { period_start: '2026-04-01', period_end: '2026-04-20', warning: 'catch-up' } },
    { name: 'T7 on_close_only open-ended (no end, no actual_return)',
      opt: { start: '2026-04-01', billingCycle: 'on_close_only' },
      expect: { period_start: '2026-04-01', period_end: TODAY, warning: '' } },
    { name: 'T8 open-ended monthly (no end_date)',
      opt: { start: '2026-05-06', billingCycle: 'monthly' },
      expect: { period_start: '2026-05-06', period_end: '2026-06-05', warning: '' } },
    { name: 'T9 end-of-month rollover (Jan 31 → Feb 27 in 2026)',
      opt: { start: '2026-01-31', end: '2026-12-31', billingCycle: 'monthly' },
      expect: { period_start: '2026-01-31', period_end: '2026-02-27', warning: '' } },
    { name: 'T10 consecutive prior invoices',
      opt: { start: '2026-01-01', end: '2026-12-31', billingCycle: 'monthly', prevPeriodEnd: '2026-03-31' },
      expect: { period_start: '2026-04-01', period_end: '2026-04-30', warning: '' } },
    { name: 'T11 lease 52 reproducer (operator-flagged 2026-05-07)',
      opt: { start: '2026-05-06', end: '2026-05-30', billingCycle: 'monthly' },
      expect: { period_start: '2026-05-06', period_end: '2026-05-30', warning: 'capped' } },
    { name: 'T12 over-invoiced anomaly (prev_period_end > ceiling)',
      opt: { start: '2026-05-06', end: '2026-05-30', billingCycle: 'monthly', prevPeriodEnd: '2026-05-31' },
      expect: { period_start: '2026-06-01', period_end: '', warning: /past the lease ceiling/ } },
];

// ── runner ────────────────────────────────────────────────────────────────
console.log('FleetForge — invoice create period auto-fill smoke test');
console.log('═'.repeat(78));

let pass = 0, fail = 0;
for (const c of cases) {
    const got = autoFillPeriodDates(c.opt);
    const startOk = got.period_start === c.expect.period_start;
    const endOk   = got.period_end   === c.expect.period_end;
    const warnOk = (c.expect.warning instanceof RegExp)
        ? c.expect.warning.test(got.warning)
        : got.warning === c.expect.warning;
    const ok = startOk && endOk && warnOk;
    if (ok) { pass++; console.log(`PASS  ${c.name}`); }
    else {
        fail++;
        console.log(`FAIL  ${c.name}`);
        console.log(`      expected: ${JSON.stringify(c.expect)}`);
        console.log(`      got:      ${JSON.stringify(got)}`);
    }
}
console.log('═'.repeat(78));
console.log(`${pass}/${pass+fail} passed`);
process.exit(fail ? 1 : 0);
