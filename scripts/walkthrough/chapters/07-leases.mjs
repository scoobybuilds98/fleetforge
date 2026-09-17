/*
 * Chapter 07 — Leases (creating & managing)
 * The lease list, the lease detail page and every tab (toured on the Summit Carriers demo lease
 * CN-DEMO-43E6BA-2026), the in-order month picker behind "Generate Invoice" (hover only — nothing
 * is generated), then creating a lease end to end and activating it.
 *
 * Records created on commit (WT_COMMIT=1 or a real recording):
 *   - one lease for Fraser Valley Distribution on the first available reefer (RF-…), manual mileage,
 *     starting odometer 0 km (the Manual default), starting engine hours 1,240, cartage $150, open-ended, starting today;
 *   - activating it reserves → puts the unit On Lease and generates the lease's first (draft) invoice.
 * Closing and reopening a lease is chapter 07, which sets up its own lease.
 *
 * Extra helpers implemented inline (recorder.mjs untouched): setDate() fills a native date input,
 * onNewLease() guards the post-submit scenes.
 */
const DRY_NO_COMMIT = process.argv.includes('--dry') && !process.env.WT_COMMIT;
const TOUR_LEASE = 'CN-DEMO-43E6BA-2026';       // Summit Carriers, manual mileage, mixed invoice statuses
const STANDIN_PENDING_LEASE = 35;                 // demo pending lease — only used by dry runs without commits

const tab = (name) => `button.tab-btn:has-text("${name}")`;
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'America/Vancouver' });

/** Native <input type=date> can't be typed into reliably — point at it, then fill ISO. */
async function setDate(d, sel, iso) {
  await d.hover(sel, 300);
  await d.loc(sel).fill(iso);
  await d.wait(400);
}

/**
 * Commit click. recorder.mjs's dry-run skip path calls an undefined `log()` and throws, so in a dry run
 * without WT_COMMIT we only verify the control is visible (hover) instead of calling d.click({commit}).
 */
async function commitClick(d, sel, opts = {}) {
  if (DRY_NO_COMMIT) return d.hover(sel, 300);
  return d.click(sel, { ...opts, commit: true });
}

/** Real wait for async data (d.wait() is capped at 100 ms in dry runs, which races API responses). */
const settle = (d, ms) => d.page.waitForTimeout(ms);

/** After "Create Lease" we should be on the new lease. Dry runs without commits borrow a demo pending lease. */
async function onNewLease(d) {
  if (/\/leases\/show\?id=/.test(d.page.url())) return;
  if (!DRY_NO_COMMIT) throw new Error(`expected the new lease page, got ${d.page.url()}`);
  await d.goto(`/leases/show?id=${STANDIN_PENDING_LEASE}`);
}

export default {
  title: 'Leases',
  subtitle: 'Every rental contract — who has which unit, at what rates, and how it is billed.',
  start: '/dashboard',
  intro: 'In this chapter we will find and read a lease, see how its invoices are generated month by month, and then create and activate a new lease from start to finish.',
  outro: 'That covers creating and managing leases. Next, we will close a lease when the unit comes back.',
  scenes: [
    // ── List ────────────────────────────────────────────────────────────
    {
      say: 'Open Leases from the sidebar. A lease is the contract that puts one unit with one customer, at agreed rates.',
      run: async (d) => { await d.nav('Leases', '/leases'); },
    },
    {
      say: 'The tiles count active, pending and completed leases, and add up the monthly rate of everything currently on rent. Click a tile to filter the list.',
      run: async (d) => { await d.highlight('.stat-grid', 'Lease summary', 3000); },
    },
    {
      say: 'The tabs split open leases, closed ones, and everything. Each row shows the contract, customer, unit, dates, rates and status.',
      run: async (d) => {
        await d.click('[role=tab]:has-text("Closed")'); await d.wait(1200);
        await d.click('[role=tab]:has-text("Active & Pending")'); await d.wait(900);
        await d.highlight('table thead', 'Lease list', 1800);
      },
    },
    {
      say: 'Billed Thru shows how far each lease has been invoiced. Amber means an active lease is billed only up to a date in the past, so there is usage nobody has invoiced yet.',
      run: async (d) => { await d.highlight('table thead th:has-text("Billed Thru")', 'Billed through', 2200); await d.hover('table tbody tr td .text-warning', 1200); },
    },
    {
      say: 'Search by contract number, company or unit, and change the sort order on the right.',
      run: async (d) => {
        await d.type('[x-model="filters.search"]', '43E6BA');
        await d.page.locator('table tbody tr').filter({ hasText: TOUR_LEASE }).first().waitFor({ timeout: 15000 });
        await settle(d, 800);
        await d.hover('[x-model="filters.sort"]', 700);
      },
    },
    {
      say: 'Tick rows to act on several leases at once. The bar that appears can close or delete them in bulk, so double-check your selection.',
      run: async (d) => {
        await d.click('table tbody tr td.td-checkbox input');
        await d.hover('.ff-bulk-bar', 1600);
        await d.click('.ff-bulk-btn-clear');
      },
    },

    // ── Detail tour ─────────────────────────────────────────────────────
    {
      say: "Click the contract number to open the lease. This one is a step deck on rent to Summit Carriers.",
      run: async (d) => { await d.click(`table tbody a:has-text("${TOUR_LEASE}")`, { nav: true }); },
    },
    {
      say: 'The header shows the status, customer and unit. The tiles are running totals for this lease only. For what a customer really owes across all their leases, use their customer account.',
      run: async (d) => { await d.highlight('h1', 'Status', 1600); await d.highlight('.stat-grid', 'Lease totals', 2400); },
    },
    {
      say: 'The main actions sit together: Close Lease when the unit comes back, Edit Lease, and Generate Invoice.',
      run: async (d) => {
        await d.hover('button:has-text("Close Lease")', 900);
        await d.hover('a:has-text("Edit Lease")', 900);
        await d.hover('a:has-text("Generate Invoice")', 900);
      },
    },
    {
      say: 'Edit Lease changes everything while a lease is pending. Once it is active, only a few fields can change, such as how mileage is tracked.',
      run: async (d) => { await d.highlight('a:has-text("Edit Lease")', 'Edit Lease', 2200); },
    },
    {
      say: 'The Rates card holds the daily, weekly and monthly rates, the mileage rate and allowance, and whether mileage is tracked manually, from Samsara, or not at all.',
      run: async (d) => { await d.highlight('.card:has(.card-title:text-is("Rates"))', 'Rates', 3200); },
    },
    {
      say: 'To change rates on an active lease, use Amend Rates. The change is recorded in the Amendments tab, and the next invoice re-prices the whole lease at the new rates with a catch-up charge or credit.',
      run: async (d) => { await d.hover('button:has-text("Amend Rates")', 2400); },
    },
    {
      say: 'Further down are the customer and unit links, notes, and the odometer card: the starting reading, the latest recorded reading, and total distance driven.',
      run: async (d) => {
        await d.scroll(700);
        await d.highlight('.card:has(.card-title:has-text("Odometer"))', 'Odometer & distance', 2200);
        await d.scroll(-700);
      },
    },
    {
      say: 'The Invoices tab lists every invoice for this lease with its period, status, total and balance. Filter by status, or click View to open one.',
      run: async (d) => {
        await d.click(tab('Invoices')); await d.wait(1200);
        await d.highlight('.card:has(.card-title:has-text("Invoices")) table', 'Invoices for this lease', 2600);
      },
    },
    {
      say: 'Documents holds the signed contract and anything else you upload. Inspections holds the pre-lease and post-lease inspections for this unit.',
      run: async (d) => {
        await d.click(tab('Documents')); await d.wait(1000);
        await d.hover('button:has-text("Upload Document")', 600);
        await d.click(tab('Inspections')); await d.wait(1200);
      },
    },
    {
      say: 'Status Log records every status change and who made it. Amendments lists rate changes, date extensions and other recorded changes to the contract.',
      run: async (d) => {
        await d.click(tab('Status Log')); await d.wait(1200);
        await d.click(tab('Amendments')); await d.wait(1200);
      },
    },
    {
      say: 'Damage Claims shows claims raised against this rental. Mileage Log holds odometer readings recorded for the lease, and Activity is the full audit trail.',
      run: async (d) => {
        await d.click(tab('Damage Claims')); await d.wait(1300);
        await d.click(tab('Mileage Log')); await d.wait(1000);
        await d.click(tab('Activity')); await d.wait(1000);
        await d.click(tab('Overview'));
      },
    },

    // ── Month picker ────────────────────────────────────────────────────
    {
      say: 'Now click Generate Invoice. This page lists every month of the lease in order, with the invoice that billed each one.',
      run: async (d) => {
        await d.click('a:has-text("Generate Invoice")', { nav: true });
        await d.wait(1500);
        await d.scroll(500);
      },
    },
    {
      say: 'Months are billed one at a time, in order. Only the month marked Next to bill can be chosen, and it is selected for you. Later months wait until earlier ones are billed.',
      run: async (d) => {
        await d.highlight('.badge:has-text("Next to bill")', 'Next month to bill', 2400);
        await d.hover('.badge:has-text("Upcoming")', 900);
      },
    },
    {
      say: 'The period, billing type and odometer fields fill in from the selected month. For a manually tracked lease, enter the closing odometer reading for the month so mileage bills correctly.',
      run: async (d) => {
        await d.scroll(500);
        await d.hover('[x-model="form.period_start"]', 700);
        await d.hover('[x-model="form.odometer_at_period_end_km"]', 1400);
      },
    },
    {
      say: 'Generate creates that month as a draft invoice. Nothing goes to the customer until someone sends it. Generate all due catches up every unbilled month in one go.',
      run: async (d) => {
        await d.scroll(800);
        await d.hover('button[type=submit]:has-text("Generate")', 1500);
        if (await d.exists('button:has-text("Generate all due")', 1000)) await d.hover('button:has-text("Generate all due")', 1200);
      },
    },

    // ── Create ──────────────────────────────────────────────────────────
    {
      say: "Let's create a new lease. Go back to Leases and click New Lease.",
      run: async (d) => {
        await d.nav('Leases', '/leases');
        await d.click('a:has-text("+ New Lease")', { nav: true });
      },
    },
    {
      say: 'Search for the customer and pick them from the list. Their currency, billing cycle and tax exemptions come across automatically.',
      run: async (d) => {
        await d.type('input.ff-picker-input[placeholder="Search customers…"]', 'Fraser', { delay: 70 });
        await d.click('.ff-picker-option:has-text("Fraser Valley Distribution")');
      },
    },
    {
      say: 'Then pick the unit. Only available units are listed. We will take a refrigerated trailer.',
      run: async (d) => {
        await d.type('input.ff-picker-input[placeholder="Search available units…"]', 'RF-', { delay: 70 });
        await d.page.locator('.ff-picker-option:has-text("RF-")').first().waitFor({ timeout: 15000 });
        await settle(d, 500);
        await d.click('.ff-picker-option:has-text("RF-")');
        // rate lookup is async — wait until the hourly rate from the reefer rate card lands
        await d.page.waitForFunction(() => parseFloat(document.querySelector('#hourly_rate')?.value || 0) > 0, null, { timeout: 15000 });
      },
    },
    {
      say: 'Set the start date and the lease start time. Leave End Date blank for an open-ended rental. The start time decides whether a same-day return is billed for an extra day.',
      run: async (d) => {
        await setDate(d, '#start_date', today());
        await d.select('#start_time_h', '08');
        await d.select('#start_time_m', '00');
        await d.hover('#end_date', 900);
      },
    },
    {
      say: 'Minimum End Date sets when an early return fee stops applying. Billing Cycle is Monthly for normal rentals, or On Close Only to bill everything when the unit comes back. Advance Billing Periods prepays extra months at activation.',
      run: async (d) => {
        await d.hover('#minimum_end_date', 900);
        await d.hover('#billing_cycle', 1100);
        await d.hover('#advance_billing_periods', 1100);
      },
    },
    {
      say: 'Rates fill in automatically. A customer-specific rate card is used first and locks the fields; otherwise the standard rate card, then the equipment defaults. The banner tells you which one applied.',
      run: async (d) => {
        await d.scroll(600);
        await d.highlight('div[x-show="rateSource === \'rate_card\'"], div[x-show="rateSource === \'customer\'"], div[x-show="rateSource === \'template\'"]', 'Where the rates came from', 2600);
        await d.highlight('.form-row-4', 'Daily, weekly, monthly, hourly', 1800);
      },
    },
    {
      say: 'Minimum Billing Days is a floor for short rentals. A unit returned sooner is billed that many days at the daily rate.',
      run: async (d) => { await d.highlight('#minimum_billing_days', 'Short-rental floor', 2200); },
    },
    {
      say: 'The mileage section sets kilometres or miles, the rate per unit of distance, and any allowance. An estimated daily mileage bills an estimate each month and trues it up against actual distance. Leave it at zero to bill actual distance only.',
      run: async (d) => {
        await d.scroll(450);
        await d.hover('#mileage_rate', 1000);
        await d.hover('#estimated_mileage_per_day', 1300);
        await d.hover('#estimated_mileage', 900);
      },
    },
    {
      say: 'Mileage precharge collects an amount up front on the first invoice and draws it down against mileage charges. Anything unused is refunded at close.',
      run: async (d) => { await d.hover('[x-model="form.precharge_enabled"]', 1800); },
    },
    {
      say: 'Discounts and add-ons come next. G P S tracking is on by default and its daily price comes from the rate card.',
      caption: 'Discounts and add-ons come next. GPS tracking is on by default and its daily price comes from the rate card.',
      run: async (d) => {
        await d.scroll(600);
        await d.hover('#discount_type', 800);
        await d.hover('[x-model="form.gps_opt_in"]', 1100);
      },
    },
    {
      say: 'This reefer has an hourly rate, so enter the starting engine hours. Engine hours are billed on the closing invoice from this reading.',
      run: async (d) => {
        await d.type('#engine_hours_at_start', '1240');
      },
    },
    {
      say: 'If we deliver the unit, enter the cartage charge. It bills once, on the first invoice. Leave it blank if the customer picks up.',
      run: async (d) => { await d.type('#cartage_amount', '150'); },
    },
    {
      say: 'Tax rates come from the customer’s province and are frozen on the lease when it is created. The exemption boxes are copied from the customer record.',
      run: async (d) => {
        await d.scroll(400);
        await d.highlight('.card:has(.card-title:has-text("Tax Exemption"))', 'Tax exemption', 2400);
      },
    },
    {
      say: 'Mileage Tracking decides how distance is captured. Off, the default, never bills mileage, even if a rate is set. Manual means you enter odometer readings. Samsara pulls them from the unit’s G P S.',
      caption: 'Mileage Tracking decides how distance is captured. Off (default) never bills mileage, even if a rate is set. Manual = you enter readings. Samsara = from the unit’s GPS.',
      run: async (d) => {
        await d.scroll(400);
        await d.highlight('.ff-segment-control--3', 'Manual · Off · Samsara', 2000);
        await d.hover('.alert-danger:has-text("mileage rate")', 1200).catch(() => {});
      },
    },
    {
      say: "This customer's mileage is read by hand, so choose Manual. The starting odometer fills in as zero, and a manual reading is never overwritten by Samsara.",
      run: async (d) => {
        await d.click('.ff-segment-control__option:has-text("Manual")');
        // Left at the app's pre-filled 0: a non-zero start currently makes the Close dialog's auto-filled
        // "Actual Mileage" fail close.php's end>=start check (reported as an app bug).
        await d.highlight('#odometer_start_km', 'Starting odometer', 1800);
      },
    },
    {
      say: 'Add notes if needed. Notes can be seen by the customer in their portal; internal notes stay with staff.',
      run: async (d) => {
        await d.scroll(500);
        await d.type('#internal_notes', 'Delivered to the Abbotsford yard. Reefer set point confirmed with dispatch.', { delay: 25 });
      },
    },
    {
      say: 'Click Create Lease. The lease is saved as Pending and the unit is reserved, so nobody else can rent it.',
      run: async (d) => {
        await d.scroll(800);
        await commitClick(d, 'button[type=submit]:has-text("Create Lease")', { nav: true });
        await d.wait(1200);
      },
    },

    // ── Activate ────────────────────────────────────────────────────────
    {
      say: 'A pending lease is a booking. When the unit actually leaves the yard, click Activate Lease and confirm.',
      run: async (d) => {
        await onNewLease(d);
        await d.click('button:has-text("Activate Lease")');
        await d.wait(700);
        await commitClick(d, '#ff-confirm-modal button:has-text("Confirm")', { nav: true });
        if (DRY_NO_COMMIT) await d.press('Escape');
        await d.wait(1200);
      },
    },
    {
      say: 'The lease is now Active and the unit is On Lease. Activation also generates the first invoice as a draft, including the cartage charge.',
      run: async (d) => {
        await d.highlight('h1', 'Now active', 1800);
        await d.click(tab('Invoices')); await d.wait(1500);
        await d.highlight('.card:has(.card-title:has-text("Invoices"))', 'First invoice', 2200);
      },
    },
  ],
};
