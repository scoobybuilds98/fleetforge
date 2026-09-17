/*
 * Chapter 13 — Damage Claims
 * The claims list (tiles, filters, statuses), an existing claim's detail page (status panel,
 * details + edit form, photos card), filing a new claim against a Summit Carriers lease/unit,
 * moving it from Reported to Assessed, and how billing the customer works (the claim itself
 * never creates a charge).
 *
 * Records created/changed on commit:
 *   - one damage claim (DMG-YYYY-NNNNN): unit FB-7039, customer Summit Carriers Ltd.,
 *     linked lease CN-DEMO-DEB565-2026, severity Moderate, est. $1,850.00, liable $1,850.00
 *   - that claim's status changed Reported -> Assessed
 * No photo is uploaded (the upload form is opened and explained only); the existing claim's
 * Edit form is opened and cancelled without saving.
 *
 * Helpers implemented inline via d.page: onPage() and commitClick() (tolerates the recorder's
 * dry-run `log is not defined` skip-branch error). pickFirst() types into a record picker and
 * picks the first matching option.
 */
const onPage = (d, frag) => d.page.url().includes(frag);
const commitClick = async (d, sel, opts = {}) => {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
};
const pickFirst = async (d, placeholderStart, text, optionText) => {
  await d.type(`input.ff-picker-input[placeholder^="${placeholderStart}"]`, text, { delay: 45 });
  await d.wait(1800);
  await d.click(`.ff-picker-option:has-text("${optionText}")`);
};

export default {
  title: 'Damage Claims',
  subtitle: 'Record damage to rental equipment, track the repair, and settle who pays.',
  start: '/dashboard',
  intro: 'In this chapter we will read the damage claims list, walk through a claim’s lifecycle, and file a new claim against a customer’s lease.',
  outro: 'That covers Damage Claims. File the claim as soon as damage is found, keep costs and photos up to date, and bill the customer before marking it Invoiced.',
  scenes: [
    {
      say: 'Open Damage Claims from the sidebar. The red badge counts claims that are still open.',
      run: async (d) => { await d.nav('Damage Claims', '/damage_claims'); },
    },
    {
      say: 'The tiles show open claims, claims marked invoiced, claims filed this year, and the average estimated repair cost. Click a tile to filter the list.',
      run: async (d) => { await d.highlight('.stat-grid, .stat-card >> nth=0', 'Claim tiles', 3200); },
    },
    {
      say: 'Each row shows the claim number, the unit, the customer, how severe the damage is, the status, the estimated cost, and when it was reported.',
      run: async (d) => { await d.highlight('table thead', 'Claims', 2600); },
    },
    {
      say: 'Search by claim or unit number, or filter by status and severity. Severity runs from Minor, to Moderate, Major, and Total Loss.',
      run: async (d) => {
        await d.hover('[x-model="filters.q"]', 700);
        await d.select('[x-model="filters.severity"]', 'major');
        await d.wait(1400);
        await d.select('[x-model="filters.severity"]', '');
      },
    },
    {
      say: 'Every claim moves through set stages: Reported, then Assessed, then Repair Ordered, then Invoiced or Resolved. At any stage before it closes, a claim can be Written Off instead.',
      run: async (d) => {
        await d.hover('[x-model="filters.status"]', 800);
        await d.select('[x-model="filters.status"]', 'repair_ordered');
        await d.wait(1600);
      },
    },
    {
      say: "Click a row to open the claim. Let's open Summit Carriers’ repair-ordered claim.",
      run: async (d) => {
        await d.click('table tbody tr:has-text("Summit Carriers")', { nav: true });
        await d.wait(800);
      },
    },
    {
      say: 'The header shows the status and severity. The tiles link to the unit, the customer and lease, and show the estimated repair cost and how much the customer is liable for.',
      run: async (d) => {
        await d.highlight('.page-header', 'Status and severity', 1800);
        await d.highlight('.stat-grid, .stat-card >> nth=0', 'Unit, customer, costs', 2200);
      },
    },
    {
      say: 'Change Status opens a panel that offers only the valid next steps for this claim, so a claim can never skip a stage.',
      run: async (d) => {
        await d.click('button:has-text("Change Status")');
        await d.wait(700);
        await d.hover('.card:has(h2:has-text("Change Status")) select', 800);
        await d.highlight('.card:has(h2:has-text("Change Status"))', 'Allowed next steps', 2000);
        await d.click('.card:has(h2:has-text("Change Status")) button:has-text("Cancel")');
      },
    },
    {
      say: 'Edit updates the details as the repair progresses: severity and location, the vendor doing the work, the actual repair cost once it is known, the liable and insurance amounts, and resolution notes.',
      run: async (d) => {
        await d.click('.card:has(h2:has-text("Claim Details")) button:has-text("Edit")');
        await d.wait(800);
        await d.highlight('#edit_actual_repair_cost', 'Actual repair cost', 1600);
        await d.highlight('#edit_resolution_notes', 'Resolution notes', 1600);
        await d.click('button:has-text("Cancel")');
      },
    },
    {
      say: 'Photos keeps the evidence. Add Photo lets you tag each picture as damage, before repair or after repair, with a caption. A claim holds up to ten photos.',
      caption: 'Photos keeps the evidence. Add Photo lets you tag each picture as damage, before repair or after repair, with a caption. A claim holds up to 10 photos.',
      run: async (d) => {
        await d.scroll(700);
        await d.click('button:has-text("Add Photo")');
        await d.wait(700);
        await d.hover('select[x-model="uploadPhotoType"]', 1200);
        await d.click('button:has-text("Cancel Upload")');
      },
    },
    {
      say: 'The Activity log records every edit, status change and photo, with who did it.',
      run: async (d) => { await d.scroll(600); await d.highlight('.card:has(h3:has-text("Activity"))', 'Activity', 2000); },
    },
    {
      say: "Now let's file a new claim. Go back to the list and click New Claim. You can also start one from a lease’s Damage Claims tab or from an inspection, which fills in the details for you.",
      run: async (d) => {
        await d.nav('Damage Claims', '/damage_claims');
        await d.click('a:has-text("New Claim")', { nav: true });
      },
    },
    {
      say: 'Pick the damaged unit, then the customer responsible.',
      run: async (d) => {
        await pickFirst(d, 'Search by unit number', 'FB-7039', 'FB-7039');
        await pickFirst(d, 'Search customers', 'Summit', 'Summit Carriers');
      },
    },
    {
      say: 'If the unit is going to a repair shop, choose the vendor. Then set the severity and describe where the damage is.',
      run: async (d) => {
        await d.hover('input.ff-picker-input[placeholder^="Search vendors"]', 900);
        await d.select('#severity', 'moderate');
        await d.type('#damage_location', 'Rear deck, passenger-side rub rail', { delay: 30 });
      },
    },
    {
      say: 'Link the lease the unit was on when it was damaged, so the claim shows up on that lease and the customer’s account.',
      run: async (d) => {
        await pickFirst(d, 'Search by contract', 'FB-7039', 'CN-DEMO-DEB565');
      },
    },
    {
      say: 'Describe what happened in plain detail. This is the record everyone will rely on later.',
      run: async (d) => {
        await d.type('#description', 'Rub rail bent and two deck boards cracked near the rear corner. Customer reports contact with a loading dock post on return. Unit is safe to move but not to load.', { delay: 12 });
      },
    },
    {
      say: 'Enter the estimated repair cost, how much the customer is liable for, and any amount you expect to claim from insurance. You can update these as quotes come in.',
      run: async (d) => {
        await d.type('#estimated_repair_cost', '1850.00');
        await d.type('#customer_liable_amount', '1850.00');
        await d.hover('#insurance_claim_amount', 800);
        await d.type('#notes', 'Photos taken at the yard on return. Waiting on a quote from the body shop.', { delay: 18 });
      },
    },
    {
      say: 'Click Create Claim. It gets the next claim number and starts in Reported status.',
      run: async (d) => {
        await d.scroll(600);
        await commitClick(d, 'button[type="submit"]:has-text("Create Claim")', { nav: true });
        await d.wait(1200);
      },
    },
    {
      say: 'Here is the new claim. Once someone has inspected the damage and confirmed the estimate, move it to Assessed: click Change Status, choose Assessed, and click Apply.',
      run: async (d) => {
        if (!onPage(d, '/damage_claims/show')) return;
        await d.click('button:has-text("Change Status")');
        await d.wait(600);
        await d.select('.card:has(h2:has-text("Change Status")) select', 'assessed');
        await commitClick(d, '.card:has(h2:has-text("Change Status")) button:has-text("Apply")', { nav: true });
        await d.wait(1000);
      },
    },
    {
      say: 'A claim in Reported or Assessed can still be deleted if it was filed by mistake. After a repair is ordered it can only be resolved or written off.',
      run: async (d) => {
        if (!onPage(d, '/damage_claims/show')) return;
        await d.highlight('.page-header', 'Assessed', 1800);
        if (await d.exists('button:has-text("Delete")', 1500)) await d.hover('button:has-text("Delete")', 1200);
      },
    },
    {
      say: 'Billing the customer is a separate step. The claim does not create a charge on its own. Add a Damage charge for the liable amount to the customer’s invoice, then move the claim to Invoiced so everyone can see it has been billed.',
      run: async (d) => {
        if (!onPage(d, '/damage_claims/show')) return;
        await d.highlight('.stat-card:has-text("Customer Liable")', 'Amount to bill', 2600);
      },
    },
    {
      say: 'When the money is collected or the matter is settled, move the claim to Resolved. If the company absorbs the cost instead, choose Written Off. Both are final.',
      run: async (d) => {
        if (!onPage(d, '/damage_claims/show')) return;
        await d.hover('button:has-text("Change Status")', 2000);
      },
    },
  ],
};
