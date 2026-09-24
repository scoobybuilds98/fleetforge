/*
 * Chapter 16 — Maintenance Work Orders
 * The work-order list and its tiles/filters, opening a work order for a unit (vendor, priority,
 * dates, assignment), adding labour and parts line items, walking the status flow
 * (Open → In Progress → Waiting Parts → In Progress → Completed), and where the finished cost lands
 * (vendor Total Spent, the unit's Maintenance tab and Payoff Analysis).
 *
 * Records created on commit: one work order on unit TR-7001, vendor Freightliner Service Center,
 * titled "Replace front brake chambers", with two line items (labour 2.5 h × $145, 2 × brake chamber
 * $89.50). It is completed on camera, so it also adds $541.50 to that vendor's total_spent and to
 * TR-7001's total_maintenance_cost.
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   pick()          — drives an FF_RecordPicker (type, then click the matching dropdown option).
 *   commitClick()   — commit click that tolerates the recorder's dry-run "log is not defined" throw.
 *   commitReload()  — commit-click a button whose handler POSTs then reloads the page; waits for the
 *                     reload only if the POST actually fired (dry runs skip commit clicks).
 * Dry runs without WT_COMMIT never create the work order, so the post-create scenes fall back to the
 * completed demo work order WO-2026-0003 and only highlight what exists there.
 */
const FALLBACK_WO = '/maintenance_work_orders/show?id=46'; // WO-2026-0003, completed, vendor Freightliner
let live = false; // true once this run really created (and landed on) the new work order

const pick = async (d, label, query, option) => {
  const input = `.form-group:has(> label:has-text("${label}")) .ff-picker-input`;
  await d.type(input, query, { delay: 60 });
  await d.wait(1200);
  await d.click(`.ff-picker-option:has-text("${option}")`);
};

// Dry runs: recorder.mjs's commit-skip branch calls an undefined `log`, which throws
// "log is not defined" AFTER correctly skipping the click. Treat exactly that as "skipped".
async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}

async function commitReload(d, sel, apiRe, caption) {
  const req = d.page.waitForRequest((r) => r.method() === 'POST' && apiRe.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
  const load = d.page.waitForEvent('load', { timeout: 25000 }).then(() => true, () => false);
  await commitClick(d, sel);
  if (!(await req)) return false;
  await load;
  await d.ready();
  await d.caption(caption);
  return true;
}

const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });

export default {
  title: 'Maintenance Work Orders',
  subtitle: 'Track every repair and service on your fleet — who is doing it, what it costs, and when it is done.',
  start: '/dashboard',
  intro: 'In this chapter we will open a work order for a unit, add labour and parts, move it through its statuses, and close it out.',
  outro: 'That covers maintenance work orders: raise the job, record every cost, move it through its statuses, and close it out with resolution notes.',
  scenes: [
    S('Open Maintenance from the left sidebar. This list holds every work order ever raised against your equipment.', async (d) => {
      await d.nav('Maintenance', '/maintenance_work_orders');
    }),
    S('The tiles show the total, how many are open and waiting to start, how many are active, and how many were completed this month. Click a tile to filter the list to it.', async (d) => {
      await d.highlight('.stat-grid', 'Work order tiles', 3000);
      await d.click('.stat-card:has-text("Open")');
      await d.wait(900);
      await d.click('.stat-card:has-text("Open")');
    }),
    S('Each row shows the work order number, the unit, the job title, its type, priority, status, requested date, cost and vendor.', async (d) => {
      await d.highlight('table thead', 'Work order list', 2400);
      await d.scroll(500);
      await d.scroll(-500);
    }),
    S('Search by title or work order number, or narrow the list by status, work type and priority. Reset clears the filters.', async (d) => {
      await d.type('[x-model="filters.q"]', 'brake');
      await d.wait(900);
      await d.type('[x-model="filters.q"]', '');
      await d.hover('[x-model="filters.status"]', 500);
      await d.hover('[x-model="filters.work_type"]', 500);
      await d.hover('[x-model="filters.priority"]', 500);
      await d.hover('button:has-text("Reset")', 400);
    }),
    S("Let's raise a new job. Click New Work Order.", async (d) => {
      await d.click('a:has-text("+ New Work Order")', { nav: true });
    }),
    S('Start with the unit. Search by unit number and pick it from the list. Out-of-service units are still allowed here; only decommissioned or inactive units are blocked.', async (d) => {
      await pick(d, 'Equipment Unit', 'TR-7001', 'TR-7001');
    }),
    S('If an outside shop is doing the work, choose the vendor. Leave it empty for jobs done in-house.', async (d) => {
      await pick(d, 'Vendor', 'Freightliner', 'Freightliner Service Center');
    }),
    S('Give the job a clear title, then set the work type and priority. Emergency and high priority jobs stand out in the list.', async (d) => {
      await d.type('#title', 'Replace front brake chambers');
      await d.select('#work_type', 'repair');
      await d.select('#priority', 'high');
    }),
    S('Describe what needs doing so the technician knows exactly what was reported.', async (d) => {
      await d.type('#description', 'Driver reported a slow air leak at the front axle. Replace both front brake chambers and test for leaks.', { delay: 22 });
    }),
    S('The requested date defaults to today. Add the date the shop has booked it in, and the odometer reading if you have one.', async (d) => {
      await d.hover('#requested_date', 500);
      await d.hover('#scheduled_date', 300);
      const t = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
      await d.page.fill('#scheduled_date', t);
      await d.wait(500);
      await d.type('#mileage_at_service', '412380');
    }),
    S('Assign it to the staff member responsible for following up. Notes are for instructions meant for the shop; internal notes are for your team only.', async (d) => {
      await d.scroll(600);
      await pick(d, 'Assigned To', 'Training', 'Training Admin');
      await d.type('#notes', 'Unit is at Delta Yard, keys in the office.', { delay: 25 });
      await d.type('#internal_notes', 'Check whether this is covered under the parts warranty.', { delay: 25 });
    }),
    S('Click Create Work Order. It is saved with status Open and the work order page opens.', async (d, cap) => {
      await d.scroll(800);
      const req = d.page.waitForRequest((r) => r.method() === 'POST' && /maintenance_work_orders\/create/.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
      await commitClick(d, 'button[type="submit"]:has-text("Create Work Order")');
      if (await req) {
        live = await d.page.waitForURL(/maintenance_work_orders\/show\?id=\d+/, { timeout: 20000 }).then(() => true, () => false);
        await d.ready();
        await d.caption(cap);
      }
      if (!live) await d.goto(FALLBACK_WO);
    }),
    S('The header shows the work order number and status. The tiles track labour cost, parts cost and the total, which build up from the line items below.', async (d) => {
      await d.highlight('h1', 'Number and status', 2200);
      await d.highlight('.stat-grid', 'Cost tiles', 2600);
    }),
    S('Opening a work order does not change the unit status on its own. If the unit must come off the rental line, set it to Maintenance on the equipment page.', async (d) => {
      await d.highlight('dl.detail-grid dd:has(a[href*="equipment/show"])', 'Unit and its current status', 3200);
    }),
    S('Add costs as they come in. Click Add Item, choose Labour, and enter the hours and the hourly rate. The line total is worked out for you.', async (d, cap) => {
      if (!live) { await d.highlight('.card:has(h3:has-text("Line Items"))', 'Line items', 3000); return; }
      await d.click('button:has-text("+ Add Item")');
      await d.select('#add_item_type', 'labor');
      await d.type('#add_description', 'Labour: replace front brake chambers and leak test', { delay: 22 });
      await d.type('#add_quantity', '2.5');
      await d.type('#add_unit_cost', '145');
      await d.wait(600);
      await commitReload(d, '#wo-add-item-form button[type="submit"]', /line_items\/add/, cap);
    }),
    S('Now the parts. Choose Part, add the part number, quantity and unit cost. Labour lines add up into Labour Cost; parts, sublet and other lines add up into Parts Cost.', async (d, cap) => {
      if (!live) { await d.highlight('.stat-grid', 'Labour vs parts', 3000); return; }
      await d.click('button:has-text("+ Add Item")');
      await d.select('#add_item_type', 'part');
      await d.type('#add_part_number', 'TSE-3030');
      await d.type('#add_description', 'Brake chamber, type 30/30', { delay: 22 });
      await d.type('#add_quantity', '2');
      await d.type('#add_unit_cost', '89.50');
      await d.wait(500);
      await commitReload(d, '#wo-add-item-form button[type="submit"]', /line_items\/add/, cap);
    }),
    S('The line items table and the cost tiles now agree. Use the red cross on a line to remove a mistake while the job is still open.', async (d) => {
      await d.highlight('.card:has(h3:has-text("Line Items"))', 'Line items and total', 2600);
      await d.highlight('.stat-grid', 'Totals updated', 2000);
    }),
    S('Need to change the vendor, dates or description? Use Edit on the details card. Editing is locked once a job is completed or cancelled.', async (d) => {
      if (await d.exists('.card-header button:has-text("Edit")', 1500)) await d.hover('.card-header button:has-text("Edit")', 1500);
      else await d.highlight('.card:has(h3:has-text("Work Order Details"))', 'Work order details', 2500);
    }),
    S('Status buttons move the job along. An open job can be started or cancelled. When the shop begins, click Start Work.', async (d, cap) => {
      if (!live) { await d.highlight('h1 .badge', 'Status', 2500); return; }
      await d.highlight('.card:has-text("Transition:")', 'Status transitions', 2200);
      await commitReload(d, 'button:has-text("Start Work")', /update_status/, cap);
    }),
    S('If the job stalls on a back-ordered part, click Waiting Parts. Your team gets an in-app notification, and the job shows as waiting until you resume it with Start Work.', async (d, cap) => {
      if (!live) { await d.highlight('h1 .badge', 'Status', 2000); return; }
      await commitReload(d, 'button:has-text("Waiting Parts")', /update_status/, cap);
      await d.highlight('h1 .badge', 'Waiting Parts', 1600);
      await commitReload(d, 'button:has-text("Start Work")', /update_status/, cap);
    }),
    S('When the work is done, click Complete and describe what was done in the resolution notes.', async (d) => {
      if (!live) { await d.highlight('.card:has(h3:has-text("Work Order Details"))', 'Resolution notes live here', 2500); return; }
      await d.click('button:has-text("✓ Complete")');
      await d.type('textarea[x-model="resolutionNotes"]', 'Both front chambers replaced, system held pressure for 15 minutes. Unit returned to service.', { delay: 20 });
    }),
    S('Confirm Complete stamps today as the completed date and records who closed it. The job is now locked: no more edits or line items.', async (d, cap) => {
      if (live) await commitReload(d, 'button:has-text("Confirm Complete")', /update_status/, cap);
      await d.highlight('h1', 'Completed', 1800);
      await d.highlight('.card:has(h3:has-text("Work Order Details"))', 'Completed date and resolution', 2400);
    }),
    S('Completing also adds the total cost to the vendor’s Total Spent and to the unit’s lifetime maintenance cost. Click the vendor to see it.', async (d) => {
      await d.click('dl.detail-grid dd a[href*="vendors/show"]', { nav: true });
      await d.highlight('.stat-card:has-text("Total Spent")', 'Vendor total spent', 2800);
    }),
    S('Every unit keeps its own history too. On the equipment page, the Maintenance tab lists its work orders and has its own New Work Order button with the unit filled in.', async (d) => {
      await d.goto('/equipment/show?id=1');
      await d.click('button.tab-btn:has-text("Maintenance")');
      await d.wait(1500);
      await d.hover('a:has-text("+ New Work Order")', 1200);
    }),
    S('Completed work order costs also count against the unit in Payoff Analysis, so repairs are weighed against the revenue the unit earns.', async (d) => {
      await d.tab('Payoff');
      await d.wait(2500);
    }),
    S('One last rule: a work order can only be deleted while it is still open or once cancelled. Completed jobs stay on record for good.', async (d) => {
      await d.nav('Maintenance', '/maintenance_work_orders');
      await d.highlight('table tbody tr >> nth=0', 'Completed jobs stay on file', 2600);
    }),
  ],
};
