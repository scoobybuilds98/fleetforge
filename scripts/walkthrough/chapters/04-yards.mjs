/*
 * Chapter 04 — Yards
 * The Yards list (tiles, filters, row actions and their guards), where yards are used
 * (a unit's Yard field and a reservation's Pickup Yard), how to see which units sit in a yard,
 * creating a yard, and the edit form (opened on Surrey Yard and cancelled — nothing saved).
 *
 * Creates on commit: ONE yard — the first free name of "Port Coquitlam Yard" / "Langley Yard" /
 * "Richmond Yard" (checked live against the yards API so a re-render never collides),
 * 1650 Kingsway Ave, Port Coquitlam BC V3C 1S5, capacity 60, phone 604-555-0187.
 *
 * Never clicked: Deactivate, Permanently delete, Save Changes, bulk actions.
 * Director note: Escape is avoided for closing things because the app shell's
 * @keydown.escape.window also collapses the desktop sidebar.
 */
const DRY = process.argv.includes('--dry');

async function commitClick(d, sel, opts = {}) {
  if (DRY && !process.env.WT_COMMIT) { await d.hover(sel, 300); d.log('(dry) skipped commit click'); return false; }
  await d.click(sel, { ...opts, commit: true });
  return true;
}

async function freeYardName(d) {
  return d.page.evaluate(async (base) => {
    const candidates = ['Port Coquitlam Yard', 'Langley Yard', 'Richmond Yard'];
    try {
      const r = await fetch(`${base}/api/v1/yards/index.php?all=1`, { credentials: 'same-origin' }).then((x) => x.json());
      const data = r.data || {};
      const list = Array.isArray(data) ? data : (data.yards || data.items || []);
      const taken = new Set(list.map((y) => y.name));
      return candidates.find((c) => !taken.has(c)) || candidates[0];
    } catch (e) { return candidates[0]; }
  }, d.base);
}

let yardName = 'Port Coquitlam Yard';
const modal = (title) => `.card:has(h3:has-text("${title}"))`;

export default {
  title: 'Yards',
  subtitle: 'The physical lots and depots where your equipment is parked and where customers collect it.',
  start: '/dashboard',
  intro: 'In this chapter we will look at Yards: the list of your lots, where yards are used across Fleet Forge, how to see which units are parked where, and adding or editing a yard.',
  outro: 'That covers Yards. Next, we will set the prices you charge for your equipment in Rates.',
  scenes: [
    {
      say: 'Open Yards from the sidebar. A yard is a physical lot or depot. Units are assigned to a yard, and reservations name the yard a customer picks up from.',
      run: async (d) => { await d.nav('Yards', '/yards'); await d.wait(1200); },
    },
    {
      say: 'The tiles count all yards, active yards that appear in dropdowns, and inactive yards that are hidden from them. Click a tile to filter the list.',
      run: async (d) => { await d.highlight('.stat-grid', 'Yards at a glance', 2600); },
    },
    {
      say: 'Each row shows the yard name and address, its location, how many units it can hold, a phone number, and whether it is active. Search by name, city or address, or filter by status.',
      run: async (d) => {
        await d.highlight('table thead', 'Yard list', 2000);
        await d.hover('[x-model="filters.q"]', 600);
        await d.hover('[x-model="statusFilter"]', 600);
        await d.hover('[x-model="sort"]', 500);
      },
    },
    {
      say: 'Edit changes a yard’s details. Deactivate hides it from dropdowns but keeps its history; it is blocked while the yard has upcoming reservations.',
      run: async (d) => {
        await d.hover('button[title="Edit yard"]', 900);
        await d.hover('button[title="Deactivate yard"]', 1300);
      },
    },
    {
      say: 'The last button deletes a yard. That is only allowed once no active reservations use it and no units are parked there, so move units out first.',
      run: async (d) => { await d.hover('button[title="Permanently delete yard"]', 2200); },
    },
    {
      say: 'Capacity is a guide for planning. To see which units are in a yard, use the Yard column on the Equipment list; each unit page also shows its yard under the unit number.',
      run: async (d) => {
        await d.nav('Equipment', '/equipment');
        await d.wait(1200);
        await d.highlight('table thead th:text-is("Yard")', 'Yard per unit', 2400);
      },
    },
    {
      say: 'A unit’s yard is set on the unit’s create or edit form, from the list of active yards.',
      run: async (d) => {
        await d.goto('/equipment/create');
        await d.scroll(350);
        await d.highlight('#yard_location', 'Yard Location', 2200);
      },
    },
    {
      say: 'Reservations use the same list for the Pickup Yard, so the yard team knows where to have the unit ready.',
      run: async (d) => {
        await d.goto('/reservations/create');
        await d.scroll(450);
        await d.highlight('#res-yard', 'Pickup Yard', 2200);
      },
    },
    {
      say: "Now let's add a yard. On the Yards page, click New Yard. The New menu in the top bar opens the same form.",
      run: async (d) => {
        yardName = await freeYardName(d);
        d.log(`new yard name: ${yardName}`);
        await d.nav('Yards', '/yards');
        await d.click('.page-header button:has-text("New Yard")');
        await d.wait(700);
      },
    },
    {
      say: 'Only the name is required, and it must be unique. Add the street address, city, province and postal code so staff and customers can find it.',
      run: async (d) => {
        await d.type('#create-name', yardName, { delay: 45 });
        await d.type('#create-address', '1650 Kingsway Ave', { delay: 35 });
        await d.type('#create-city', 'Port Coquitlam');
        await d.type('#create-state', 'BC');
        await d.type('#create-postal', 'V3C 1S5');
      },
    },
    {
      say: 'Enter the capacity in units and the yard phone number. Use Notes for gate codes, opening hours or site contacts.',
      run: async (d) => {
        await d.type('#create-capacity', '60');
        await d.type('#create-phone', '604-555-0187');
        await d.type('#create-notes', 'Gate code held by dispatch. Open 6 am to 6 pm on weekdays.', { delay: 22 });
      },
    },
    {
      say: 'Click Create Yard. The yard is active straight away, so it appears in the unit and reservation dropdowns.',
      run: async (d) => {
        if (await commitClick(d, `${modal('New Yard')} button:has-text("Create Yard")`)) await d.wait(1800);
        // Dry run, or the save was refused: close the modal so the next scene can reach the list.
        if (await d.exists(modal('New Yard'), 800)) await d.click(`${modal('New Yard')} button:has-text("Cancel")`);
      },
    },
    {
      say: 'To change a yard, click Edit. The same fields appear, plus Yard is Active. Untick it to retire a yard without losing its history.',
      run: async (d) => {
        await d.click('tr:has-text("Surrey Yard") button[title="Edit yard"]');
        await d.wait(700);
        await d.highlight(modal('Edit Yard'), 'Edit Yard', 1600);
        await d.scroll(500, { x: 960, y: 600 });
        await d.hover(`${modal('Edit Yard')} input[x-model="editModal.form.is_active"]`, 1000);
      },
    },
    {
      say: 'Be careful when renaming: units and reservations store the yard name as text, so units parked there keep the old name until you update them. We will cancel without saving.',
      run: async (d) => {
        await d.hover('input[x-model="editModal.form.name"]', 1600);
        await d.click(`${modal('Edit Yard')} button:has-text("Cancel")`);
        await d.wait(500);
      },
    },
  ],
};
