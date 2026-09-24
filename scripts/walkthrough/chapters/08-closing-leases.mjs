/*
 * Chapter 08 — Closing a lease (and reopening it)
 * The Close Lease dialog field by field (return date/time, closing odometer, engine hours, sweep/wash/fuel,
 * actual mileage, remove days, notes), what closing does to the unit and the invoices, the final
 * invoice it produces, and Reopen Lease for corrections.
 *
 * Records created on commit (WT_COMMIT=1 or a real recording):
 *   - OFF CAMERA in scene 1, through the app's own API as the recorder user: a lease for
 *     Rocky Mountain Freight Co. on the first available reefer (RF-…), rates from the rate-card lookup,
 *     started 12 days ago at 08:00, manual mileage (starting odometer 0 km — see note on the close-mileage bug), engine hours 3,150 — then activated
 *     (which generates its first draft invoice);
 *   - ON CAMERA: the lease is closed today (odometer 2,280 km, engine hours 3,196.5, sweep $30, wash $120,
 *     12 gal fuel) — producing the final invoice — and then reopened with a reason, so it ends ACTIVE.
 * Dry runs without WT_COMMIT create nothing: they open the Close dialog on demo lease CN-DEMO-43E6BA-2026
 * (active) and the Reopen dialog on a demo completed lease, and never press the commit buttons.
 *
 * Inline helpers (recorder.mjs untouched): setupLease() via window.FF_Api, setDate(), commitClick().
 */
const DRY_NO_COMMIT = process.argv.includes('--dry') && !process.env.WT_COMMIT;
const STANDIN_ACTIVE_LEASE = 6;      // CN-DEMO-43E6BA-2026 — dry runs only
const STANDIN_COMPLETED_LEASE = 28;  // CN-DEMO-1A105B-2025 — dry runs only
const CUSTOMER = 'Rocky Mountain Freight Co.';

const tab = (name) => `button.tab-btn:has-text("${name}")`;
const isoDay = (offsetDays = 0) => {
  const t = new Date(Date.now() + offsetDays * 86400000);
  return t.toLocaleDateString('en-CA', { timeZone: 'America/Vancouver' });
};
const settle = (d, ms) => d.page.waitForTimeout(ms);

async function setDate(d, sel, iso) {
  await d.hover(sel, 300);
  await d.loc(sel).fill(iso);
  await d.wait(300);
}

/** recorder.mjs's dry skip path throws (`log` undefined) — in dry-without-commit just verify the control. */
async function commitClick(d, sel, opts = {}) {
  if (DRY_NO_COMMIT) return d.hover(sel, 300);
  return d.click(sel, { ...opts, commit: true });
}

/** Create + activate the lease this chapter closes, using the app's own API client (CSRF included). */
async function setupLease(d) {
  const res = await d.page.evaluate(async ({ customer, startDate }) => {
    const base = window.location.pathname.split('/').slice(0, 2).join('/'); // "/fleetforge"
    const api = (p) => base + '/api/v1/' + p;
    const c = await FF_Api.get(api('customers/index.php?per_page=10&search=' + encodeURIComponent(customer)));
    const cust = (c.data?.items || []).find((x) => x.company_name === customer);
    if (!cust) return { error: 'customer not found' };
    const u = await FF_Api.get(api('equipment/units/index.php?status=available&per_page=10&search=RF-'));
    const unit = (u.data?.items || []).find((x) => x.status === 'available' || !x.status) || (u.data?.items || [])[0];
    if (!unit) return { error: 'no available reefer' };
    const r = await FF_Api.get(api(`leases/lookup_rates?customer_id=${cust.id}&equipment_template_id=${unit.template_id}`));
    const rt = r.data || {};
    const payload = {
      customer_id: cust.id, equipment_unit_id: unit.id,
      start_date: startDate, start_time: '08:00', billing_cycle: 'monthly', currency: rt.currency || cust.currency || 'CAD',
      daily_rate: rt.daily_rate || '0', weekly_rate: rt.weekly_rate || '0', monthly_rate: rt.monthly_rate || '0',
      mileage_rate: rt.mileage_rate || '0', mileage_unit: rt.mileage_unit || 'km', hourly_rate: rt.hourly_rate || '0',
      gps_opt_in: true, gps_cost: rt.gps_price || '', minimum_billing_days: rt.minimum_days ?? '',
      mileage_tracking_mode: 'manual', odometer_start_km: 0, odometer_start_source: 'manual',
      engine_hours_at_start: 3150, internal_notes: 'Cold-chain contract — reefer returned to Calgary yard.',
    };
    const created = await FF_Api.post(api('leases/create'), payload, { quiet: true });
    if (!created.success) return { error: 'create failed: ' + JSON.stringify(created.error) };
    const act = await FF_Api.post(api('leases/activate'), { id: created.data.id }, { quiet: true });
    if (!act.success) return { error: 'activate failed: ' + JSON.stringify(act.error), id: created.data.id };
    return { id: created.data.id };
  }, { customer: CUSTOMER, startDate: isoDay(-12) });
  if (res.error) throw new Error(`setupLease: ${res.error}`);
  return res.id;
}

export default {
  title: 'Closing a Lease',
  subtitle: 'Recording the return, the final readings and closeout charges — and correcting a closed lease.',
  start: '/leases',
  intro: 'In this chapter we will close a lease when the unit comes back: the return date, final odometer and engine hours, closeout charges, the closing invoice, and how to reopen a lease to fix a mistake.',
  outro: 'That covers closing and reopening leases. Next, we will look at invoices in detail.',
  scenes: [
    {
      say: "Here is an active lease for Rocky Mountain Freight. Their reefer came back to the yard this morning, so it is time to close it.",
      run: async (d) => {
        const id = DRY_NO_COMMIT ? STANDIN_ACTIVE_LEASE : await setupLease(d);
        await d.goto(`/leases/show?id=${id}`);
        await d.highlight('h1', 'Active lease', 1600);
      },
    },
    {
      say: 'Only close a lease once the unit is physically back. Click Close Lease.',
      run: async (d) => {
        await d.click('button:has-text("Close Lease")');
        await d.page.locator('.modal-title:has-text("Close Lease")').waitFor({ timeout: 10000 });
      },
    },
    {
      say: 'Actual Return Date starts at today. Change it if the unit came back on an earlier day, because billing stops on this date.',
      run: async (d) => { await setDate(d, '#actual_return_date', isoDay(0)); await d.highlight('#actual_return_date', 'Return date', 1600); },
    },
    {
      say: 'Return Time is optional. A unit returned later in the day than the lease start time is billed for that extra day.',
      run: async (d) => {
        if (DRY_NO_COMMIT && !(await d.exists('#actual_return_time_h', 800))) return; // stand-in lease has no start time
        await d.select('#actual_return_time_h', '07');
        await d.select('#actual_return_time_m', '30');
      },
    },
    {
      say: 'Enter the closing odometer. The dialog shows the starting reading and works out the total distance driven.',
      run: async (d) => {
        await d.highlight('.modal-body div:has(> div:text-is("Closing Odometer"))', 'Closing odometer', 1500);
        await d.type('#odometer_at_close_km', '2280', { delay: 70 });
        await d.wait(900);
      },
    },
    {
      say: 'If the unit is linked to Samsara, Fetch from Samsara pulls the live reading instead. We enter it by hand here.',
      run: async (d) => {
        if (await d.exists('.modal button:has-text("Fetch from Samsara")', 800)) await d.hover('.modal button:has-text("Fetch from Samsara")', 1400);
        else await d.hover('#odometer_at_close_km', 1400);
      },
    },
    {
      say: 'This reefer bills engine hours, so enter the current hour meter. The hours since the last reading are billed at the lease’s hourly rate.',
      run: async (d) => {
        if (DRY_NO_COMMIT && !(await d.exists('#engine_hours_at_close', 800))) return; // stand-in lease has no hourly rate
        await d.type('#engine_hours_at_close', '3196.5', { delay: 70 });
      },
    },
    {
      say: 'Closeout charges are optional. Enter a sweep or wash amount to bill it, and the gallons of fuel needed to top it up. Fuel is billed at the per-gallon rate set in Settings.',
      run: async (d) => {
        await d.type('#sweep_amount', '30');
        await d.type('#wash_amount', '120');
        await d.type('#fuel_gallons', '12');
        await d.wait(600);
        await d.highlight('.modal .form-hint:has-text("gal ×")', 'Fuel charge', 1600);
      },
    },
    {
      say: 'Actual Mileage is filled in from the odometer readings. Only change it if you need to bill a different distance.',
      run: async (d) => {
        await d.scroll(400, { x: 960, y: 600 });
        await d.highlight('#mileage_at_end', 'Distance to bill', 2000);
      },
    },
    {
      say: 'Remove days takes days off the end of the billed period, for example the return day. The dates stay the same, the minimum billing days still apply, and the customer does not see it.',
      run: async (d) => { await d.hover('#billing_days_removed', 2200); },
    },
    {
      say: 'Add close notes for the team. Leases with a mileage precharge or prepaid months show extra choices here for refunding the unused amount.',
      run: async (d) => {
        await d.type('#close_notes', 'Returned clean. Minor scuff on rear door noted on post-lease inspection.', { delay: 22 });
      },
    },
    {
      say: 'Click Close Lease. The lease becomes Completed, the unit goes back to Available, and the closing invoice is built with the rental up to the return date plus mileage, engine hours and closeout charges.',
      run: async (d) => {
        await commitClick(d, '.modal-footer button:has-text("Close Lease")', { nav: true });
        if (DRY_NO_COMMIT) { await d.click('.modal-footer button:has-text("Cancel")'); await d.goto(`/leases/show?id=${STANDIN_COMPLETED_LEASE}`); }
        await settle(d, 1000);
      },
    },
    {
      say: 'The lease now shows Completed, and the Rates card lists the closeout charges that were billed.',
      run: async (d) => {
        await d.highlight('h1', 'Completed', 1600);
        await d.highlight('.card:has(.card-title:text-is("Rates"))', 'Rates & closeout charges', 2200);
      },
    },
    {
      say: 'Any invoice that had already billed past the return date is corrected automatically, so the customer is never charged for days after the unit came back.',
      run: async (d) => {
        await d.tab('Invoices'); await settle(d, 1500);
        await d.highlight('.card:has(.card-title:has-text("Invoices")) table', 'Invoices after closing', 2600);
      },
    },
    {
      say: "Let's open the newest invoice to check the final charges.",
      run: async (d) => {
        await d.click('.card:has(.card-title:has-text("Invoices")) table tbody tr a:has-text("View"), .card:has(.card-title:has-text("Invoices")) table tbody tr button:has-text("View")', { nav: true });
        await settle(d, 800);
      },
    },
    {
      say: 'Each charge is its own line: base rental, mileage, engine hours, sweep, wash and fuel. It is still a draft, so review it before sending.',
      run: async (d) => {
        await d.page.locator('h3:has-text("Line Items")').first().scrollIntoViewIfNeeded();
        await d.wait(600);
        await d.highlight('.card:has(h3:has-text("Line Items")), div:has(> div > h3:has-text("Line Items"))', 'Final charges', 3000);
      },
    },
    {
      say: 'If something was missed, like a wrong odometer reading, a manager can reopen the lease. Go back to the lease and click Reopen Lease.',
      run: async (d) => {
        await d.click('a:has-text("View Lease")', { nav: true });
        await d.click('button:has-text("Reopen Lease")');
      },
    },
    {
      say: 'Reopening puts the lease back to Active and the unit back On Lease, and clears the return date and closing mileage. A reason is required and is kept in the status history.',
      run: async (d) => {
        await d.highlight('.modal:has(.modal-title:has-text("Reopen Lease")) .alert-warning', 'What reopening does', 2200);
        await d.type('#reopen_reason', 'Closing odometer was mistyped — re-close with the corrected reading.', { delay: 22 });
      },
    },
    {
      say: 'Click Reopen Lease to confirm.',
      run: async (d) => {
        await commitClick(d, '.modal-footer button:has-text("Reopen Lease")', { nav: true });
        if (DRY_NO_COMMIT) await d.click('.modal-footer button:has-text("Cancel")');
        await settle(d, 1000);
      },
    },
    {
      say: 'The lease is Active again. Make any corrections, then close it again with the right readings. The closing charges on the draft invoice are rebuilt, not added twice.',
      run: async (d) => {
        await d.highlight('h1', 'Active again', 1800);
        await d.tab('Status Log'); await settle(d, 1000);
        await d.highlight('.card:has(.card-title:has-text("Status Log"))', 'Reopen recorded', 2000);
      },
    },
  ],
};
