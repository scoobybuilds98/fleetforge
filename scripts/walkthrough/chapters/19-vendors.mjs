/*
 * Chapter 19 — Vendors
 * The vendor directory (tiles, filters), a vendor's detail page (spend tiles, details, work orders,
 * equipment worked on, active lease exposure), how vendors connect to work orders and to Accounts
 * Payable bills, and adding a new vendor.
 *
 * Records created on commit: one vendor, "Coquitlam Diesel & Brake Ltd." (type Repair, contact
 * Marcus Lee). Vendor creation calls VendorEnqueuer, which is a no-op while
 * quickbooks.sync_enabled = '0' (the dev default), so nothing is queued for QuickBooks.
 * The New Bill modal is opened and cancelled — no bill is created.
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   commitClick() — tolerates the recorder's dry-run "log is not defined" throw on skipped commits.
 */
const NEW_VENDOR = 'Coquitlam Diesel & Brake Ltd.';

async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}
const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });

export default {
  title: 'Vendors',
  subtitle: 'The repair shops, parts suppliers, towing and inspection companies you pay to keep the fleet running.',
  start: '/dashboard',
  intro: 'In this chapter we will look at the vendor list, read a vendor’s page, see how vendors connect to work orders and bills, and add a new vendor.',
  outro: 'That covers vendors. Keep contact details, rates and ratings current so the right shop gets each job.',
  scenes: [
    S('Open Vendors from the left sidebar. This is your directory of outside companies: repair shops, parts suppliers, towing, inspection stations and insurers.', async (d) => {
      await d.nav('Vendors', '/vendors');
    }),
    S('The tiles show how many vendors you have, how many are marked preferred, how many work orders are active with vendors, and your top vendor by spend.', async (d) => {
      await d.highlight('.stat-grid', 'Vendor tiles', 3200);
    }),
    S('Each row shows the vendor type, main contact, phone, your rating, total spent, and how many work orders they have handled.', async (d) => {
      await d.highlight('table thead', 'Vendor list', 2600);
    }),
    S('Search by name or contact, filter by type, or show preferred vendors only. You can also sort by rating, spend or work order count.', async (d) => {
      await d.type('[x-model="filters.q"]', 'tire');
      await d.wait(1000);
      await d.type('[x-model="filters.q"]', '');
      await d.hover('[x-model="filters.vendor_type"]', 500);
      await d.hover('[x-model="filters.is_preferred"]', 500);
      await d.select('[x-model="sort"]', 'work_order_count');
      await d.wait(800);
      await d.select('[x-model="sort"]', 'name');
    }),
    S("Click a row to open the vendor. Let's open Freightliner Service Center.", async (d) => {
      await d.click('table tbody tr:has-text("Freightliner Service Center")', { nav: true });
    }),
    S('The badge next to the name shows whether this vendor is linked to QuickBooks. Edit, Delete and AI Analysis sit at the top right.', async (d) => {
      if (await d.exists('a.badge:has-text("QuickBooks")', 1500)) await d.hover('a.badge:has-text("QuickBooks")', 1200);
      await d.hover('#btn-edit', 700);
      await d.hover('button:has-text("AI Analysis")', 700);
    }),
    S('Total Spent grows when a work order with this vendor is completed, and when an Accounts Payable bill for them is approved. The other tiles count active, completed and total work orders.', async (d) => {
      await d.highlight('.stat-grid', 'Spend and work orders', 3600);
    }),
    S('Vendor Details holds contact information, the hourly rate they charge, their billing currency, your rating out of five, and their specialties.', async (d) => {
      await d.highlight('#vendor-view-section', 'Vendor details', 3200);
    }),
    S('Below that, Work Orders lists every job assigned to this vendor. Filter by status or type, and click View to open one.', async (d) => {
      await d.scroll(700);
      await d.select('.card:has-text("Work Orders") .tab-filter-bar select >> nth=0', 'completed');
      await d.wait(1000);
      await d.select('.card:has-text("Work Orders") .tab-filter-bar select >> nth=0', '');
      await d.hover('a.btn:has-text("View")', 700);
    }),
    S('Equipment Worked On lists each unit this vendor has serviced, how many times, and the last service date.', async (d) => {
      await d.scroll(900);
      await d.highlight('.card:has(.card-header:has-text("Equipment Worked On"))', 'Service history by unit', 2800);
    }),
    S('Active Lease Exposure shows which of those units are out on lease right now, so you know which customers are affected if there is a problem with this vendor’s work.', async (d) => {
      await d.scroll(700);
      await d.highlight('.card:has(.card-header:has-text("Active Lease Exposure"))', 'Units on lease', 3000);
    }),
    S('To change any detail, click Edit. The form opens in place; Save Changes updates the vendor.', async (d) => {
      await d.scroll(-3000);
      await d.click('#btn-edit');
      await d.wait(600);
      await d.highlight('#vendor-edit-section', 'Edit in place', 2200);
      await d.click('#vendor-edit-section button:has-text("Cancel")');
    }),
    S('Delete is blocked while the vendor has open, in-progress or waiting-parts work orders. Finish or reassign those jobs first.', async (d) => {
      await d.hover('button.btn-danger:has-text("Delete")', 1500);
    }),
    S('Vendors are also who you pay. In Accounting, Payables, each bill is entered against a vendor, and approving a bill posts it to accounts payable.', async (d) => {
      await d.goto('/accounting/bills');
      await d.select('[x-model="filterVendor"]', { label: 'Freightliner Service Center' }).catch(() => {});
      await d.wait(1200);
      await d.highlight('table thead', 'Bills for this vendor', 2200);
    }),
    S('New Bill starts with the vendor, the dates and the vendor’s invoice number, then the expense lines. We will cancel this one.', async (d) => {
      await d.click('button:has-text("+ New Bill")');
      await d.wait(700);
      await d.highlight('[x-model="form.vendor_id"]', 'Choose the vendor', 2200);
      await d.hover('[x-model="form.vendor_bill_number"]', 700);
      await d.click('.modal-overlay button:has-text("Cancel")');
    }),
    S("Now let's add a new vendor. Go back to Vendors and click New Vendor.", async (d) => {
      await d.nav('Vendors', '/vendors');
      await d.click('a:has-text("+ New Vendor"), button:has-text("+ New Vendor")', { nav: true });
    }),
    S('Name and type are required. The type groups vendors in the list: maintenance, repair, parts, inspection, towing or other.', async (d) => {
      await d.type('[x-model="form.name"]', NEW_VENDOR);
      await d.select('[x-model="form.vendor_type"]', 'repair');
    }),
    S('Add the contact person, email and phone, then the shop address.', async (d) => {
      await d.type('[x-model="form.contact_name"]', 'Marcus Lee');
      await d.type('[x-model="form.email"]', 'service@coquitlamdiesel.ca', { delay: 22 });
      await d.type('[x-model="form.phone"]', '604-555-0188');
      await d.type('[x-model="form.address"]', '1850 United Blvd');
      await d.type('[x-model="form.city"]', 'Coquitlam');
      await d.type('[x-model="form.state"]', 'BC');
    }),
    S('Record their hourly rate and your rating. Set the currency carefully: once the vendor is pushed to QuickBooks, its currency cannot be changed there.', async (d) => {
      await d.scroll(500);
      await d.type('[x-model="form.hourly_rate"]', '135');
      await d.select('[x-model="form.rating"]', '4');
      await d.highlight('[x-model="form.currency"]', 'Currency is locked in QuickBooks', 2400);
    }),
    S('Pick their specialties, tick Preferred if you want them to stand out, and add any notes such as opening hours or drop-off instructions.', async (d) => {
      await d.scroll(500);
      const spec = d.page.locator('select[multiple]').filter({ visible: true }).first();
      await d.hover(spec, 400);
      await spec.selectOption(['Brakes', 'Diesel Engine', 'Suspension']);
      await d.wait(600);
      await d.click('input[type="checkbox"][x-model="form.is_preferred"]');
      await d.type('textarea[x-model="form.notes"]', 'Open 7 am to 6 pm weekdays. Call ahead for same-day brake work.', { delay: 22 });
    }),
    S('Click Create Vendor. The new vendor page opens, and the vendor can now be chosen on work orders and bills.', async (d, cap) => {
      await d.scroll(600);
      const req = d.page.waitForRequest((r) => r.method() === 'POST' && /vendors\/create/.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
      await commitClick(d, 'button:has-text("Create Vendor")');
      if (await req) {
        await d.page.waitForURL(/vendors\/show\?id=\d+/, { timeout: 20000 }).catch(() => {});
        await d.ready();
        await d.caption(cap);
        await d.highlight('h1', 'New vendor', 1800);
      }
    }),
    S('To send a job to them, open a new work order and search for the vendor by name in the Vendor field.', async (d) => {
      await d.goto('/maintenance_work_orders/create');
      const input = '.form-group:has(> label:has-text("Vendor")) .ff-picker-input';
      await d.type(input, 'Coquitlam', { delay: 60 });
      await d.wait(1500);
      if (await d.exists(`.ff-picker-option:has-text("${NEW_VENDOR}")`, 2500)) await d.hover(`.ff-picker-option:has-text("${NEW_VENDOR}")`, 1200);
      else await d.highlight('.form-group:has(> label:has-text("Vendor"))', 'Vendor picker', 1800);
    }),
  ],
};
