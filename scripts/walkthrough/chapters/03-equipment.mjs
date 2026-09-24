/*
 * Chapter 03 — Equipment
 * The unit list (tiles, filters, bulk bar), the unit detail page and every tab (using
 * TR-7002, which is Samsara-linked and has leases, claims, work orders and a fixed asset),
 * equipment types (templates), the two-level Category → Type taxonomy with the short-lease
 * minimum switch, brands (brand lives on the unit), and registering a new unit.
 *
 * Creates on commit: ONE equipment unit — a 2025 Great Dane Champion 53' dry van, brand
 * Great Dane, parked at Surrey Yard. Its unit number is the first free one of DV-7050…DV-7059
 * (checked live through the units API so a re-render never collides), VIN derived from it.
 *
 * Never clicked: Calculate distance / Sync Now / Unlink (Samsara calls), Track in Samsara
 * (external site), AI Analysis, Decommission / Delete, the short-lease switch, brand buttons.
 */
const tab = (name) => `button.tab-btn:has-text("${name}")`;
const DRY = process.argv.includes('--dry');

/** Data-changing submit. Dry runs without WT_COMMIT only hover it (recorder's own dry-skip path
 *  references an undefined `log`, so we never route a skipped commit through d.click). */
async function commitClick(d, sel, opts = {}) {
  if (DRY && !process.env.WT_COMMIT) { await d.hover(sel, 300); d.log('(dry) skipped commit click'); return false; }
  await d.click(sel, { ...opts, commit: true });
  return true;
}

/** Select an <option> by a substring of its visible text (option labels carry extra whitespace). */
async function selectByText(d, sel, text) {
  const value = await d.page.locator(sel).first().evaluate((el, t) => {
    const o = [...el.options].find((x) => x.textContent.replace(/\s+/g, ' ').includes(t));
    return o ? o.value : null;
  }, text);
  if (value === null) throw new Error(`no option containing "${text}" in ${sel}`);
  await d.select(sel, value);
}

/** First unit number in DV-7050…DV-7059 that the units API does not know about. */
async function freeUnitNumber(d) {
  return d.page.evaluate(async (base) => {
    for (let n = 7050; n < 7060; n++) {
      const num = `DV-${n}`;
      try {
        const r = await fetch(`${base}/api/v1/equipment/units/index.php?search=${num}&per_page=5`, { credentials: 'same-origin' }).then((x) => x.json());
        const items = (r.data && r.data.items) || [];
        if (!items.some((u) => u.unit_number === num)) return num;
      } catch (e) { return num; }
    }
    return 'DV-7060';
  }, d.base);
}

let unitNo = 'DV-7050';

export default {
  title: 'Equipment',
  subtitle: 'Every truck and trailer you own or manage — its specs, compliance dates, GPS link and full history.',
  start: '/dashboard',
  intro: 'In this chapter we will work through the Equipment module: finding a unit, reading its detail page, how equipment types, categories and brands fit together, and registering a new unit.',
  outro: 'That covers Equipment. Next, we will look at the Yards where your units are parked.',
  scenes: [
    {
      say: 'Open Equipment from the sidebar. This is your fleet list: every unit, whether it is out on lease, sitting in a yard, or in the shop.',
      run: async (d) => { await d.nav('Equipment', '/equipment'); await d.wait(1200); },
    },
    {
      say: 'The tiles count units that are Available, On Lease, and In Maintenance. Total Fleet shows how many units you have and what share of them is on lease.',
      run: async (d) => { await d.highlight('.stat-grid', 'Fleet at a glance', 3000); },
    },
    {
      say: 'Click a tile to filter the list. Here, On Lease shows only units currently rented out. Clear filter brings everything back.',
      run: async (d) => {
        await d.click('.stat-card:has(.stat-label:text-is("On Lease"))');
        await d.wait(1500);
        await d.click('button:has-text("Clear filter")');
        await d.wait(800);
      },
    },
    {
      say: 'Each row shows the unit number, its equipment type and category, year, status, yard, and mileage. Compliance says Expiring when a registration, insurance, C V I or M V I date is past or due within thirty days.',
      caption: 'Each row shows the unit number, equipment type and category, year, status, yard and mileage. Compliance says Expiring when a registration, insurance, CVI or MVI date is past or due within 30 days.',
      run: async (d) => { await d.highlight('table thead', 'Unit list', 2400); await d.scroll(500); await d.scroll(-500); },
    },
    {
      say: 'Search by unit number, V I N, licence plate or GPS device. You can also filter by status and equipment type, and change the sort order.',
      caption: 'Search by unit number, VIN, licence plate or GPS device. You can also filter by status and equipment type, and change the sort order.',
      run: async (d) => {
        await d.hover('[x-model="filters.status"]', 500);
        await d.hover('[x-model="filters.template_id"]', 500);
        await d.hover('[x-model="filters.sort"]', 500);
        await d.type('[x-model="filters.search"]', 'TR-7002', { delay: 80 });
        await d.page.waitForFunction(() => { const rows = document.querySelectorAll('table tbody tr'); return rows.length === 1 && rows[0].textContent.includes('TR-7002'); }, null, { timeout: 15000 }).catch(() => {});
        await d.wait(1000);
      },
    },
    {
      say: 'Tick the boxes to act on several units at once: set them Available, Maintenance or Inactive, or delete them. We will clear the selection without changing anything.',
      run: async (d) => {
        await d.click('table tbody input.ff-checkbox');
        await d.wait(500);
        await d.highlight('.ff-bulk-bar', 'Bulk actions', 2200);
        await d.click('.ff-bulk-bar button[aria-label="Clear selection"]');
      },
    },
    {
      say: "Click a unit number to open it. Let's open T R 7002, a sleeper tractor that is on lease right now.",
      caption: "Click a unit number to open it. Let's open TR-7002, a sleeper tractor that is on lease right now.",
      run: async (d) => { await d.click('table tbody a:text-is("TR-7002")', { nav: true }); await d.wait(1200); },
    },
    {
      say: 'The header shows the status and a green Live badge, which means the unit is linked to Samsara G P S. Below it are the equipment type, year and yard.',
      run: async (d) => { await d.highlight('h1.page-header-title', 'Status · Live GPS', 2600); },
    },
    {
      say: 'Across the top: Track in Samsara opens this unit on the Samsara website, AI Analysis summarises its history, and Edit Unit changes any detail. Available units also get Decommission and Delete.',
      run: async (d) => {
        await d.hover('a:has-text("Track in Samsara")', 900);
        await d.hover('.page-header-actions button:has-text("AI Analysis")', 800);
        await d.hover('a:has-text("Edit Unit")', 900);
      },
    },
    {
      say: 'The tiles give the make and model, the V I N, current mileage, and the C V I expiry date, which turns amber within thirty days and red once it has passed.',
      caption: 'The tiles give the make and model, the VIN, current mileage, and the CVI expiry date, which turns amber within 30 days and red once it has passed.',
      run: async (d) => { await d.highlight('.stat-grid', 'Unit at a glance', 3200); },
    },
    {
      say: 'The Overview tab lists identity details such as licence plate and ownership, the physical specifications, and a live Samsara summary with location, speed, odometer and battery.',
      run: async (d) => {
        await d.hover('.card:has(.card-title:text-is("Identity"))', 900);
        await d.scroll(520);
        await d.hover('.card:has(.card-title:text-is("Samsara Live Tracking"))', 1000);
      },
    },
    {
      say: 'Distance Travelled asks Samsara how far the unit went between two dates, for mileage audits. Saved readings are kept underneath. Calculate contacts Samsara, so we will not press it here.',
      run: async (d) => {
        await d.scroll(420);
        await d.hover('.card:has(.card-title:text-is("Distance Travelled")) button:has-text("Calculate")', 1100);
        await d.hover('.card:has(.card-title:text-is("Distance Travelled")) th:has-text("Period")', 800);
        await d.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        await d.wait(600);
      },
    },
    {
      say: 'Lease History starts with Days on Rent: how many calendar days the unit was actually out on lease in the chosen range. Overlapping leases are merged, so a day is never counted twice.',
      run: async (d) => {
        await d.tab('Lease History');
        await d.wait(1600);
        await d.highlight('.card:has(.card-title:text-is("Days on Rent"))', 'Calendar occupancy, not billed days', 2600);
      },
    },
    {
      say: 'Below that is every lease this unit has been on, with the customer, status and dates. Click View to open a lease.',
      run: async (d) => { await d.scroll(450); await d.wait(900); await d.scroll(-450); },
    },
    {
      say: 'Compliance lists the C V I and registration expiry dates, the days remaining, and the renewal interval. These dates also drive the Compliance Alerts on the dashboard.',
      caption: 'Compliance lists the CVI and registration expiry dates, the days remaining, and the renewal interval. These dates also drive the Compliance Alerts on the dashboard.',
      run: async (d) => { await d.tab('Compliance'); await d.wait(1200); await d.highlight('.card:has(.card-title:text-is("Compliance Documents & Expiry"))', 'Expiry dates', 2000); },
    },
    {
      say: 'Documents holds the paperwork for this unit, such as the C V I certificate, registration and insurance. Use Upload to add a file.',
      caption: 'Documents holds the paperwork for this unit, such as the CVI certificate, registration and insurance. Use Upload to add a file.',
      run: async (d) => { await d.tab('Documents'); await d.wait(1200); },
    },
    {
      say: 'The Payoff tab appears when the unit is linked to a fixed asset in Accounting, for roles that can see money. It is the whole payoff story on one page: how much of the unit has paid for itself, what is still to recover, and when it should be paid off.',
      run: async (d) => {
        await d.tab('Payoff'); await d.wait(2000);
        await d.highlight('.po-hero', 'Paid off · still to recover · payoff date', 3000);
      },
    },
    {
      say: 'The projections use the last twelve, six or three months of net revenue. Click a scenario to re-project, or type your own monthly figure and any one-time costs under What if.',
      caption: 'The projections use the last 12, 6 or 3 months of net revenue. Click a scenario to re-project, or type your own monthly figure and any one-time costs under What if.',
      run: async (d) => {
        await d.page.locator('.po-scen').first().scrollIntoViewIfNeeded();
        await d.click('.po-scen-card:has-text("Conservative")');
        await d.wait(1500);
        await d.hover('.po-whatif [x-model="payoffCustomMonthly"]', 900);
      },
    },
    {
      say: 'The charts compare what has been recovered with the target, and what the unit earned against what it cost each month. Below, the money is itemised: acquisition, earnings and spending to date, fixed costs, financing if any, and book value. Only sent invoices count, never drafts.',
      run: async (d) => {
        await d.page.locator('.po-charts').first().scrollIntoViewIfNeeded();
        await d.wait(1500);
        await d.page.locator('.po-money').first().scrollIntoViewIfNeeded();
        await d.highlight('.po-money .card:has(.card-title:text-is("Acquisition"))', 'Acquisition cost', 1600);
        await d.hover('.po-money .card:has(.card-title:text-is("Book value"))', 900);
      },
    },
    {
      say: 'Revenue by lease shows which rentals paid for the unit, and Month by month is a two-year operating profit and loss.',
      run: async (d) => {
        await d.page.locator('.po-table-card').first().scrollIntoViewIfNeeded();
        await d.wait(1200);
        await d.scroll(600);
        await d.wait(600);
        await d.page.evaluate(() => window.scrollTo(0, 0));
      },
    },
    {
      say: 'Damage Claims, Maintenance and Inspections show every claim, work order and inspection for this unit, and each has a button to start a new one already linked to it.',
      run: async (d) => {
        await d.tab('Damage Claims'); await d.wait(1200);
        await d.tab('Maintenance'); await d.wait(1200);
        await d.tab('Inspections'); await d.wait(1100);
      },
    },
    {
      say: 'Mileage Log holds odometer readings, Status Log records every status change, and Activity is the audit trail of edits and who made them.',
      run: async (d) => {
        await d.tab('Mileage Log'); await d.wait(900);
        await d.tab('Status Log'); await d.wait(900);
        await d.tab('Activity'); await d.wait(900);
      },
    },
    {
      say: 'Samsara Mapping links the unit to a Samsara vehicle or trailer. Once linked, G P S data syncs every five minutes. Sync Now and Unlink live here; an unlinked unit shows a picker instead.',
      run: async (d) => {
        await d.tab('Samsara Mapping'); await d.wait(1500);
        await d.hover('button:has-text("Sync Now")', 900);
        await d.hover('button:has-text("Unlink")', 800);
      },
    },
    {
      say: 'To change a unit, click Edit Unit. You can change its type, brand, unit number, V I N, yard, tracking details, specifications, plate, mileage, compliance dates and notes.',
      caption: 'To change a unit, click Edit Unit. You can change its type, brand, unit number, VIN, yard, tracking details, specifications, plate, mileage, compliance dates and notes.',
      run: async (d) => {
        await d.click('a:has-text("Edit Unit")', { nav: true });
        await d.wait(900);
        await d.hover('#unit_number', 600);
        await d.scroll(600);
        await d.hover('#mileage', 600);
        await d.scroll(600);
      },
    },
    {
      say: 'Status is not on this form. It changes through actions such as leasing, maintenance or decommissioning. Save Changes updates the unit; we will click Cancel instead.',
      run: async (d) => {
        await d.hover('button:has-text("Save Changes")', 900);
        await d.click('a.btn:has-text("Cancel")', { nav: true });
        await d.wait(800);
      },
    },
    {
      say: 'Now, how units are organised. Every unit belongs to an equipment type. Go back to Equipment and click Equipment Type.',
      run: async (d) => {
        await d.nav('Equipment', '/equipment');
        await d.click('.page-header a:has-text("Equipment Type")', { nav: true });
        await d.wait(1500);
      },
    },
    {
      say: 'An equipment type is a model you rent, such as a Great Dane Champion fifty-three foot dry van. The list shows its category, how many units you have, and its default daily rate.',
      caption: "An equipment type is a model you rent, such as a Great Dane Champion 53' dry van. The list shows its category, how many units you have, and its default daily rate.",
      run: async (d) => { await d.highlight('table thead', 'Equipment types', 2200); await d.hover('table tbody tr:has-text("Great Dane Champion")', 1000); },
    },
    {
      say: 'A type stores default dimensions, rental rates and compliance renewal intervals. New units copy those defaults, and its rates are used on a lease when no rate card covers that equipment.',
      run: async (d) => {
        await d.click('a:has-text("Add new equipment type")', { nav: true });
        await d.wait(800);
        await d.scroll(700);
        await d.wait(700);
        await d.scroll(700);
      },
    },
    {
      say: 'To change an existing type, click Edit on its row. The form has the same four sections: identity and category, default dimensions, default rental rates, and compliance renewal intervals.',
      run: async (d) => {
        await d.goto('/equipment/templates');
        await d.click('table tbody tr:has-text("Great Dane Champion") a:has-text("Edit")', { nav: true });
        await d.wait(1200);
        await d.hover('.card-title:text-is("Default Rental Rates")', 800);
        await d.scroll(700);
        await d.hover('.card-title:text-is("Compliance Renewal Intervals")', 800);
      },
    },
    {
      say: 'Saving changes the defaults only. Units you have already registered keep their own specifications. We will cancel.',
      run: async (d) => { await d.click('a.btn:has-text("Cancel")', { nav: true }); await d.wait(600); },
    },
    {
      say: 'Types are grouped into categories, such as Chassis, Dry Van or Reefer. It is two levels only: a category holds types, and each type holds units. Open Manage Categories.',
      run: async (d) => {
        await d.goto('/equipment/templates');
        await d.click('a:has-text("Manage Categories")', { nav: true });
        await d.wait(1800);
      },
    },
    {
      say: 'Each category lists its types and unit counts. The short-lease minimum switch makes short leases on everything in that category bill at least the minimum number of days. Here it is on for Chassis only.',
      run: async (d) => {
        await d.highlight('.eqtax-rule.is-on', 'Short-lease minimum', 2600);
        await d.scroll(500);
        await d.hover('.eqtax-type-row >> nth=2', 700);
        await d.scroll(-500);
      },
    },
    {
      say: 'Brands are separate, because one equipment type can be built by several manufacturers. The brand is set on each unit. Open Brands to add, rename or deactivate them.',
      run: async (d) => {
        await d.goto('/equipment/brands');
        await d.wait(1300);
        await d.highlight('.card:has(.card-title:text-is("Add a brand"))', 'Add a brand', 1600);
        await d.hover('table tbody tr:has-text("Great Dane")', 900);
      },
    },
    {
      say: 'A brand that is on units cannot be deleted. Deactivate it instead: it stays on those units but is no longer offered for new ones.',
      run: async (d) => { await d.hover('table tbody tr:has-text("Great Dane") button:has-text("Deactivate")', 1600); },
    },
    {
      say: "Let's register a new unit. From the Equipment list, click New Unit.",
      run: async (d) => {
        unitNo = await freeUnitNumber(d);
        d.log(`new unit number: ${unitNo}`);
        await d.nav('Equipment', '/equipment');
        await d.click('.page-header a:has-text("New Unit")', { nav: true });
      },
    },
    {
      say: 'Pick the equipment type first. Its defaults, such as length and axle count, fill in further down. Then choose the brand, and enter a unit number, which must be unique.',
      run: async (d) => {
        await selectByText(d, '#template_id', "Great Dane Champion 53' Dry Van");
        await selectByText(d, '#brand_id', 'Great Dane');
        await d.type('#unit_number', unitNo, { delay: 70 });
      },
    },
    {
      say: 'Add the V I N, year and ownership. Ownership is required. If the V I N is already on another unit, a warning appears right away.',
      caption: 'Add the VIN, year and ownership. Ownership is required. If the VIN is already on another unit, a warning appears right away.',
      run: async (d) => {
        const digits = unitNo.replace(/\D/g, '').padStart(5, '0');
        await d.type('#vin', `1GRAA0621SB${digits}0`.slice(0, 17), { delay: 35 });
        await d.type('#year', '2025');
        await d.select('#ownership_type', 'owned');
      },
    },
    {
      say: 'Choose the yard where the unit is parked, and the tracking provider if it has a G P S device.',
      run: async (d) => {
        await d.scroll(300);
        await selectByText(d, '#yard_location', 'Surrey Yard');
        await d.hover('#tracking_provider', 800);
      },
    },
    {
      say: 'Check the specifications that came from the type, then add the licence plate, province, starting mileage and the date you acquired it.',
      run: async (d) => {
        await d.hover('#length_ft', 600);
        await d.type('#license_plate', 'TR4715');
        await d.type('#license_state', 'BC');
        await d.type('#mileage', '1200');
        await d.page.locator('#acquired_date').fill('2025-11-03');
        await d.hover('#acquired_date', 400);
      },
    },
    {
      say: 'Enter the compliance expiry dates and renewal intervals. Fleet Forge warns about these on the dashboard, the unit list and the Compliance module as they come due.',
      run: async (d) => {
        await d.scroll(500);
        const inDays = (n) => { const t = new Date(Date.now() + n * 86400000); return t.toISOString().slice(0, 10); };
        await d.page.locator('#cvi_expiry').fill(inDays(300));
        await d.hover('#cvi_expiry', 400);
        await d.type('#cvi_interval_days', '365');
        await d.page.locator('#registration_expiry').fill(inDays(200));
        await d.hover('#registration_expiry', 400);
      },
    },
    {
      say: 'Notes are for anything general. Internal notes are for staff only and are never shown to customers.',
      run: async (d) => {
        await d.scroll(400);
        await d.type('#notes', 'New 2025 Champion dry van, swing doors, logistics posts.', { delay: 22 });
      },
    },
    {
      say: 'Click Register Unit. The unit is created as Available, ready to be reserved or leased, and its detail page opens.',
      run: async (d) => {
        if (await commitClick(d, 'button[type="submit"]:has-text("Register Unit")')) {
          // The page redirects to the new unit ~3.5 s after the API confirms.
          await d.page.waitForURL(/equipment\/show/, { timeout: 15000 }).catch(() => {});
          await d.ready();
          await d.wait(1500);
        }
      },
    },
  ],
};
