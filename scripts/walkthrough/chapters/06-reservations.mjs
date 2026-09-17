/*
 * Chapter 06 — Reservations
 * The reservations dashboard (tiles, charts, booking-density calendar, Chassis In / Chassis Out
 * tables, Timeline), a pending and a confirmed reservation's detail page and actions, how a
 * reservation turns into a lease (there is no one-click convert — Chassis Out releases the unit,
 * then the lease is created in Leases), and creating a new reservation.
 *
 * Creates on commit: ONE PENDING reservation for Summit Carriers Ltd. (contact auto-filled),
 * one reefer unit (first of RF-7033/RF-7034/RF-7032/SD-7037/FB-7043 not already on a pending or
 * confirmed reservation — checked live so re-renders don't hit the double-booking warning),
 * pickup 5 days after the recording date at 09:00, Surrey Yard, priority High.
 * Pending is deliberate: a Confirmed reservation flips the unit to Reserved, which would hide it
 * from other chapters' "available unit" pickers.
 *
 * Never clicked: Confirm / Chassis Out / Cancel / Reverse / Delete, AI Analysis.
 */
const DRY = process.argv.includes('--dry');

async function commitClick(d, sel, opts = {}) {
  if (DRY && !process.env.WT_COMMIT) { await d.hover(sel, 300); d.log('(dry) skipped commit click'); return false; }
  await d.click(sel, { ...opts, commit: true });
  return true;
}

async function selectByText(d, sel, text) {
  const value = await d.page.locator(sel).first().evaluate((el, t) => {
    const o = [...el.options].find((x) => x.textContent.replace(/\s+/g, ' ').includes(t));
    return o ? o.value : null;
  }, text);
  if (value === null) throw new Error(`no option containing "${text}" in ${sel}`);
  await d.select(sel, value);
}

async function pick(d, scope, query, text) {
  await d.type(`${scope} .ff-picker-input`, query, { delay: 70 });
  const opt = d.page.locator(`${scope} .ff-picker-option`).filter({ hasText: text }).first();
  await opt.waitFor({ state: 'visible', timeout: 15000 });
  await d.wait(500);
  await d.click(opt);
}

/** First candidate unit that is not on any pending/confirmed reservation. */
async function freeReservableUnit(d) {
  return d.page.evaluate(async (base) => {
    const candidates = ['RF-7033', 'RF-7034', 'RF-7032', 'SD-7037', 'FB-7043'];
    const busy = new Set();
    for (const st of ['pending', 'confirmed']) {
      try {
        const r = await fetch(`${base}/api/v1/reservations/index.php?status=${st}&per_page=100`, { credentials: 'same-origin' }).then((x) => x.json());
        for (const res of (r.data && r.data.items) || []) for (const u of res.units || []) busy.add(u.unit_number);
      } catch (e) { /* fall through to first candidate */ }
    }
    return candidates.find((c) => !busy.has(c)) || candidates[0];
  }, d.base);
}

let unitNo = 'RF-7033';
const tile = (label) => `.stat-card:has(.stat-label:text-is("${label}"))`;

export default {
  title: 'Reservations',
  subtitle: 'Book trucks and trailers ahead of a customer pickup, confirm them, and check them out of the yard.',
  start: '/dashboard',
  intro: 'In this chapter we will look at Reservations: reading the reservations board, what confirming and checking out a reservation does, how a reservation becomes a lease, and booking a new one.',
  outro: 'That covers Reservations. Next, we will turn a booking into a signed rental with Leases.',
  scenes: [
    {
      say: 'Open Reservations from the sidebar. A reservation holds equipment for a customer who is coming to pick it up on a set date, before any lease exists.',
      run: async (d) => { await d.nav('Reservations', '/reservations'); await d.wait(1500); },
    },
    {
      say: "The tiles show active reservations, meaning pending plus confirmed, then each of those on its own, and today's pickups. Click a tile to filter the list.",
      run: async (d) => {
        await d.highlight('.stat-grid', 'Reservations at a glance', 2200);
        await d.click(tile('Pending'), { pause: 1200 });
        await d.click(tile('Pending'), { pause: 600 });
      },
    },
    {
      say: 'Below are a status breakdown and the number of pickups over the past week. Search by contact or company, filter by pickup date or priority, and sort the list.',
      run: async (d) => {
        await d.hover('[x-model="filters.q"]', 700);
        await d.hover('[x-model="filters.pickup_date"]', 600);
        await d.hover('[x-model="filters.priority"]', 600);
      },
    },
    {
      say: 'The booking density calendar shades each of the next four weeks by how many pickups are booked that day, so busy days stand out.',
      run: async (d) => { await d.scroll(420); await d.wait(600); await d.highlight('.card:has-text("Fleet Booking Density")', 'Next 4 weeks', 2400); },
    },
    {
      say: 'Chassis In lists pending and confirmed reservations, units that have not left the yard yet. A coloured edge flags high and urgent priority.',
      run: async (d) => { await d.scroll(600); await d.wait(500); await d.highlight('.card:has-text("Chassis In") table thead', 'Chassis In', 2400); },
    },
    {
      say: 'Row buttons: Confirm a pending booking, Chassis Out a confirmed one when the customer collects it, or Cancel. Pending reservations can also be deleted.',
      run: async (d) => {
        await d.hover('table button:text-is("Confirm")', 900);
        await d.hover('table button:text-is("Chassis Out")', 900);
        await d.hover('table button:text-is("Cancel")', 700);
      },
    },
    {
      say: 'Chassis Out holds completed reservations whose units have been checked out. A manager can Reverse a check-out made by mistake.',
      run: async (d) => { await d.scroll(700); await d.wait(500); await d.hover('table button:text-is("Reverse")', 1400); },
    },
    {
      say: 'Timeline switches to a unit-by-day grid of active reservations over the next two weeks.',
      run: async (d) => {
        await d.scroll(-700);
        await d.click('button:has-text("Timeline")');
        await d.wait(1500);
        await d.scroll(300);
        await d.click('button:has-text("Table")');
      },
    },
    {
      say: "Let's open a pending reservation. Summit Carriers has one for a Volvo sleeper.",
      run: async (d) => { await d.goto('/reservations/show?id=5'); await d.wait(1200); },
    },
    {
      say: 'The progress bar shows where it is: Pending, Confirmed, then Completed. The details card holds the contact, pickup date and time, quantity, priority, yard and notes.',
      run: async (d) => {
        await d.highlight('h1, .page-header', 'Reservation #5', 1200);
        await d.scroll(300);
        await d.hover('.card:has-text("Reservation Details")', 1600);
      },
    },
    {
      say: 'Units lists each unit on the booking, with its status when it was reserved and its status now. The activity log records every change.',
      run: async (d) => { await d.scroll(900); await d.wait(600); await d.highlight('.card:has(table):has-text("Status at Reservation")', 'Units', 2400); },
    },
    {
      say: 'Confirm Reservation locks it in and marks its linked units Reserved. Cancel releases them again. We will not change this one.',
      run: async (d) => {
        await d.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        await d.wait(600);
        await d.hover('button:has-text("Confirm Reservation")', 1300);
        await d.hover('button:has-text("Cancel Reservation")', 900);
      },
    },
    {
      say: 'On a confirmed reservation the main action is Mark Out, or Chassis Out. Use it when the unit physically leaves the yard. The reservation becomes Completed.',
      run: async (d) => { await d.goto('/reservations/show?id=1'); await d.wait(1200); await d.hover('button:has-text("Mark Out")', 2000); },
    },
    {
      say: 'There is no one-click conversion to a lease. Chassis Out releases the unit back to Available, and you then create the lease for it in Leases. A unit still marked Reserved cannot be put on a new lease.',
      run: async (d) => { await d.highlight('.card:has-text("Actions")', 'Then create the lease', 3000); },
    },
    {
      say: "Now let's book a new reservation. Click New in the top right, or New Reservation on the list.",
      run: async (d) => {
        unitNo = await freeReservableUnit(d);
        d.log(`reservation unit: ${unitNo}`);
        await d.nav('Reservations', '/reservations');
        await d.click('a:has-text("New Reservation"), button:has-text("New Reservation")', { nav: true });
        await d.wait(800);
      },
    },
    {
      say: 'Existing Customer links the booking to an account. Manual Entry is for someone without an account yet, where the company and unit numbers are typed in by hand.',
      run: async (d) => {
        await d.hover('button:has-text("Manual Entry")', 1000);
        await d.hover('button:has-text("Existing Customer")', 800);
      },
    },
    {
      say: 'Status can be Pending or Confirmed. Pending holds the booking without touching the unit; Confirmed also marks the unit Reserved straight away. We will keep this one Pending.',
      run: async (d) => { await d.select('#res-status', 'pending'); },
    },
    {
      say: 'Search for the customer. Their company and contact name fill in automatically, and the unit list loads their leased units plus everything available.',
      run: async (d) => {
        await pick(d, '.form-group:has(> label:has-text("Customer"))', 'Summit', 'Summit Carriers');
        await d.wait(1500);
        await d.hover('#res-contact', 800);
      },
    },
    {
      say: 'Choose the trailer type and add a unit. The quantity follows the number of units you add. You can add several units to one reservation.',
      run: async (d) => {
        const typeFor = { 'RF-7032': 'Great Dane Everest', 'RF-7033': 'Great Dane Everest', 'RF-7034': 'Great Dane Everest', 'SD-7037': 'Doonan', 'FB-7043': 'Manac' };
        await selectByText(d, '#res-trailer-type', typeFor[unitNo] || 'Reefer');
        await d.page.waitForFunction((u) => [...document.querySelectorAll('#res-unit option')].some((o) => o.textContent.includes(u)), unitNo, { timeout: 15000 });
        await selectByText(d, '#res-unit', unitNo);
        await d.wait(700);
        await d.hover('#res-qty', 700);
      },
    },
    {
      say: 'Set the pickup date, which cannot be in the past, an optional pickup time, and the yard the customer will collect from.',
      run: async (d) => {
        const t = new Date(Date.now() + 5 * 86400000);
        const ymd = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
        await d.hover('#res-pickup', 400);
        await d.page.locator('#res-pickup').fill(ymd);
        await d.page.locator('#res-pickup-time').fill('09:00');
        await d.hover('#res-pickup-time', 500);
        await selectByText(d, '#res-yard', 'Surrey Yard');
      },
    },
    {
      say: 'Add notes for the yard team. Additional Fields holds the priority, contact phone and email, purpose, and internal staff-only notes.',
      run: async (d) => {
        await d.type('#res-notes', 'Pre-cool reefer to 2 degrees before pickup.', { delay: 25 });
        await d.click('summary:has-text("Additional Fields")');
        await d.wait(500);
        await d.select('#res-priority', 'high');
        await d.type('#res-purpose', 'Produce run to Kelowna', { delay: 25 });
      },
    },
    {
      say: 'If a unit is already on another active reservation, you are warned and can choose to double-book; the override is written into the internal notes. A lease starting that same day on the unit blocks the booking outright.',
      run: async (d) => { await d.scroll(300); await d.hover('#res-internal', 1500); },
    },
    {
      say: 'Click Submit Reservation. It appears on the Chassis In list and the booking calendar, and its detail page opens.',
      run: async (d) => {
        if (await commitClick(d, 'button:has-text("Submit Reservation")')) {
          await d.page.waitForURL(/reservations\/show/, { timeout: 15000 }).catch(() => {});
          await d.ready();
          await d.wait(1500);
        }
      },
    },
  ],
};
