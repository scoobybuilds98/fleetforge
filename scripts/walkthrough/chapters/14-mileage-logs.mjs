/*
 * Chapter 14 — Mileage Logs
 * The Mileage Logs list (tiles, filters, entry types), recording a manual odometer reading
 * against a Summit Carriers lease, the entry's detail page, and — the important part — how
 * readings relate to billing: log entries are a history; invoices bill from the odometer
 * readings on the lease/invoice/close form, according to the lease's Mileage Tracking mode.
 *
 * Records created on commit:
 *   - one Manual mileage log: unit FB-7039, lease CN-DEMO-DEB565-2026 (Summit Carriers Ltd.),
 *     37,120 km, dated today. (Also bumps equipment_units.mileage for FB-7039 if higher.)
 *
 * Billing claims verified in code: nothing in lib/Billing, leases/close.php or the invoice
 * create path reads mileage_logs; the Samsara daily job (cron/gps_mileage_sync.php) writes
 * gps_sync rows only for active leases in 'samsara' mode.
 *
 * Helpers implemented inline via d.page: onPage(), commitClick() (tolerates the recorder's
 * dry-run `log is not defined` skip-branch error) and pickFirst() for record pickers.
 */
const onPage = (d, frag) => d.page.url().includes(frag);
const commitClick = async (d, sel, opts = {}) => {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
};
// The odometer card is the last thing on the lease page, so it can't scroll above the caption
// pill; give the page some bottom room first (cosmetic, in-page only).
const padBottom = (d) => d.page.evaluate(() => { const m = document.querySelector('main') || document.body; m.style.paddingBottom = '420px'; });
const pickFirst = async (d, placeholderStart, text, optionText) => {
  await d.type(`input.ff-picker-input[placeholder^="${placeholderStart}"]`, text, { delay: 45 });
  await d.wait(1800);
  await d.click(`.ff-picker-option:has-text("${optionText}")`);
};

export default {
  title: 'Mileage Logs',
  subtitle: 'A dated history of odometer readings for every unit, and how readings connect to mileage billing.',
  start: '/dashboard',
  intro: 'In this chapter we will look at mileage logs, record an odometer reading by hand, and see exactly which readings a mileage invoice uses.',
  outro: 'That covers Mileage Logs. Keep readings accurate, but remember that the invoice bills from the odometer entered on the invoice or at close, based on the lease’s mileage tracking mode.',
  scenes: [
    {
      say: 'Open Mileage Logs from the sidebar. This is a history of odometer readings for every unit in the fleet.',
      run: async (d) => { await d.nav('Mileage Logs', '/mileage_logs'); },
    },
    {
      say: 'The tiles count all entries, entries made by hand, entries pulled automatically from G P S, and the date of the last G P S reading. Click a tile to filter.',
      caption: 'The tiles count all entries, entries made by hand, entries pulled automatically from GPS, and the date of the last GPS reading. Click a tile to filter.',
      run: async (d) => { await d.highlight('.stat-grid, .stat-card >> nth=0', 'Mileage tiles', 3000); },
    },
    {
      say: 'Filter by unit, by entry type, or by a date range. Each row shows the date, unit, reading with its kilometre or mile unit, the type, the linked lease, and who recorded it.',
      run: async (d) => {
        await d.hover('[x-model="filters.equipment_unit_id"]', 700);
        await d.hover('[x-model="filters.log_type"]', 700);
        await d.hover('[x-model="filters.date_from"]', 700);
      },
    },
    {
      say: 'There are five entry types. Manual and Service are entered by staff. G P S Sync entries are added once a day for units on a lease tracked by Samsara. Lease Start and Lease End are reserved for the system and cannot be entered by hand.',
      caption: 'There are five entry types. Manual and Service are entered by staff. GPS Sync entries are added once a day for units on a lease tracked by Samsara. Lease Start and Lease End are reserved for the system.',
      run: async (d) => {
        await d.highlight('[x-model="filters.log_type"]', 'Entry types', 2600);
      },
    },
    {
      say: "Let's record a reading. Click Record Mileage.",
      run: async (d) => { await d.click('a:has-text("Record Mileage")', { nav: true }); },
    },
    {
      say: 'Pick the unit, then link the lease it is on. The lease must be for that same unit, and linking puts the reading into that lease’s mileage history.',
      run: async (d) => {
        await pickFirst(d, 'Search by unit number', 'FB-7039', 'FB-7039');
        await pickFirst(d, 'Search by contract', 'FB-7039', 'CN-DEMO-DEB565');
      },
    },
    {
      say: 'Choose Manual for a reading taken in the yard or reported by the customer, or Service for one taken during maintenance. The date defaults to today and cannot be in the future.',
      run: async (d) => {
        await d.select('#log_type', 'manual');
        await d.hover('#log_date', 1200);
      },
    },
    {
      say: 'Enter the odometer as a whole number and choose kilometres or miles. It cannot be lower than the last reading logged for this unit.',
      run: async (d) => {
        await d.type('#odometer_reading', '37120', { delay: 70 });
        await d.select('#mileage_unit', 'km');
      },
    },
    {
      say: 'Add a note saying where the reading came from, then click Save Entry.',
      run: async (d) => {
        await d.type('#notes', 'Read from the dash at the Abbotsford yard during a safety check.', { delay: 20 });
        await commitClick(d, '#submit-btn', { nav: true });
        await d.wait(1200);
      },
    },
    {
      say: 'The entry opens. Manual and Service entries can be edited or deleted. G P S Sync entries cannot be edited, because they came from the device.',
      caption: 'The entry opens. Manual and Service entries can be edited or deleted. GPS Sync entries cannot be edited, because they came from the device.',
      run: async (d) => {
        if (!onPage(d, '/mileage_logs/show')) return;
        await d.highlight('.stat-grid, .stat-card >> nth=0', 'Reading details', 2200);
        await d.hover('.page-header-actions button:has-text("Edit")', 900);
        await d.hover('.page-header-actions button:has-text("Delete")', 900);
      },
    },
    {
      say: 'Entry Details shows the unit, the linked lease, the reading, who recorded it, the notes and when it was created. Edit opens a small form to correct the reading, its unit, the date or the notes; we will cancel.',
      run: async (d) => {
        if (!onPage(d, '/mileage_logs/show')) return;
        await d.highlight('.card:has(.card-title:text-is("Entry Details"))', 'Entry details', 2400);
        await d.click('.page-header-actions button:has-text("Edit")');
        await d.wait(600);
        await d.highlight('#edit-section', 'Edit entry', 2200);
        await d.click('#edit-section button:has-text("Cancel")');
      },
    },
    {
      say: 'Now the key point: a mileage log entry does not bill anything by itself. To see what does, open the lease. This Summit Carriers lease is set to Manual mileage tracking.',
      run: async (d) => {
        await d.nav('Leases', '/leases');
        await d.type('[x-model="filters.search"]', 'CN-DEMO-DEB565', { delay: 50 });
        await d.wait(1500);
        await d.click('table tbody tr:has-text("CN-DEMO-DEB565") a[href*="leases/show"]', { nav: true });
        await d.highlight('.ff-rate-cell:has-text("Mileage tracking")', 'Manual', 2200);
      },
    },
    {
      say: 'The Mileage Log tab lists the readings logged against this lease, including the one we just recorded. Plus Record Mileage here fills in the lease for you.',
      caption: 'The Mileage Log tab lists the readings logged against this lease, including the one we just recorded. + Record Mileage here fills in the lease for you.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Mileage Log")');
        await d.wait(1500);
        await d.highlight('.card:has(.card-title:has-text("Mileage Log"))', 'Lease mileage history', 2600);
      },
    },
    {
      say: 'Billing uses the Odometer and Distance card on the Overview. It holds the starting odometer, the latest reading recorded on an invoice, and the total distance driven since the lease started.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Overview")');
        await d.wait(800);
        await padBottom(d);
        await d.scroll(1400);
        await d.highlight('#mileage-tracking-card', 'Odometer and distance', 3000);
      },
    },
    {
      say: 'On a Manual lease, you type the readings where they bill. Generate Invoice has odometer fields: the period start fills in from the last invoice, you enter the period end, and the distance is charged at the lease’s mileage rate.',
      run: async (d) => {
        await d.scroll(-1400);
        await d.hover('button:has-text("Generate Invoice"), a:has-text("Generate Invoice")', 2400);
      },
    },
    {
      say: 'When the unit comes back, enter the closing odometer on the Close Lease form. The closing invoice charges the distance driven that has not already been billed.',
      run: async (d) => { await d.hover('button:has-text("Close Lease")', 2400); },
    },
    {
      say: 'On a Samsara lease, the distance comes from Samsara instead, and the daily G P S Sync entries appear in Mileage Logs automatically. On an Off lease, no mileage is billed, whatever you log.',
      caption: 'On a Samsara lease, the distance comes from Samsara instead, and the daily GPS Sync entries appear in Mileage Logs automatically. On an Off lease, no mileage is billed, whatever you log.',
      run: async (d) => { await d.highlight('.ff-rate-cell:has-text("Mileage tracking")', 'Mileage tracking mode', 2400); },
    },
    {
      say: 'Some leases also carry an estimated daily mileage. Each invoice charges the estimate, and once actual readings are known, every period on Samsara or at close on Manual, a true-up line charges or credits the difference.',
      run: async (d) => { await d.hover('text=Per-unit rate', 2400); },
    },
  ],
};
