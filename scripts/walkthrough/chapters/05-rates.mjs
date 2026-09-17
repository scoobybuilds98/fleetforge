/*
 * Chapter 05 — Rates
 * The Rates page (tiles, customer-card tiles, global cards), reading a rate card and what
 * each rate field means (daily / weekly / monthly / mileage / hourly / GPS / min days), how
 * lease creation picks a rate (customer card → global card → equipment-type default) shown
 * live on the New Lease form WITHOUT saving a lease, then creating a customer-specific card.
 *
 * Creates on commit: ONE rate card "Summit Carriers — Reefer Contract 2026" for Summit
 * Carriers Ltd. with a single Reefer item (CAD $84/day, $500/wk, $1,850/mo, $0.17/km,
 * $12/hr, GPS $2/day). Card names are not unique, so a re-render simply adds another card.
 *
 * The lease form is only used to show the rate banner; it is abandoned, never submitted.
 */
const DRY = process.argv.includes('--dry');

/** Data-changing submit; dry runs without WT_COMMIT only hover (see chapter 03 for why). */
async function commitClick(d, sel, opts = {}) {
  if (DRY && !process.env.WT_COMMIT) { await d.hover(sel, 300); d.log('(dry) skipped commit click'); return false; }
  await d.click(sel, { ...opts, commit: true });
  return true;
}

/** Type into the FF_RecordPicker inside `scope` and pick the option containing `text`. */
async function pick(d, scope, query, text) {
  const input = `${scope} .ff-picker-input`;
  await d.type(input, query, { delay: 70 });
  const opt = d.page.locator(`${scope} .ff-picker-option`).filter({ hasText: text }).first();
  await opt.waitFor({ state: 'visible', timeout: 15000 });
  await d.wait(500);
  await d.click(opt);
}

const k = (label) => `.rate-item-card__k:text-is("${label}")`;
const customerField = '.form-group:has(> label:has-text("Customer"))';
const unitField = '.form-group:has(> label:has-text("Equipment Unit"))';

export default {
  title: 'Rates',
  subtitle: 'The prices you charge — standard rate cards for everyone, and custom cards for customers with contracted pricing.',
  start: '/dashboard',
  intro: 'In this chapter we will look at Rates: how rate cards are organised, what each rate means, how a new lease picks its prices, and how to set up custom pricing for a customer.',
  outro: 'That covers Rates. Next, we will book equipment ahead of time with Reservations.',
  scenes: [
    {
      say: 'Open Rates from the sidebar. Prices live on rate cards. A global card applies to every customer; a customer card holds contracted prices for one customer.',
      run: async (d) => { await d.nav('Rates', '/rates'); await d.wait(1200); },
    },
    {
      say: 'The tiles count rate lines: all of them, those in effect today, those on customer cards, and those on global cards. Each equipment category on a card counts as one line.',
      run: async (d) => { await d.highlight('.stat-grid', 'Rate lines', 3000); },
    },
    {
      say: 'Customers with their own pricing appear as tiles. Click one to see their cards. Gateway Intermodal has contracted U S dollar prices for dry vans, tractors and reefers.',
      caption: 'Customers with their own pricing appear as tiles. Click one to see their cards. Gateway Intermodal has contracted USD prices for dry vans, tractors and reefers.',
      run: async (d) => {
        await d.hover('.rate-cust-tile >> nth=0', 600);
        await d.click('.rate-cust-tile:has-text("Gateway")');
        await d.wait(1600);
      },
    },
    {
      say: 'Each line shows the daily, weekly and monthly rate and the mileage rate, with the currency and whether it is active today.',
      run: async (d) => { await d.scroll(300); await d.hover(k('Daily'), 1200); await d.hover(k('Mileage'), 1000); },
    },
    {
      say: 'Global cards are hidden by default. Flip the switch to show them. These are the standard prices for anyone without a customer card.',
      run: async (d) => {
        await d.click('label.ios-toggle');
        await d.wait(1200);
        await d.scroll(500);
        await d.wait(800);
      },
    },
    {
      say: "Let's open Gateway's card to see everything on it. Click the customer name, or Edit on any line.",
      run: async (d) => { await d.goto('/rates/show?id=5'); await d.wait(1000); },
    },
    {
      say: 'The card details are the name, the customer, and the dates it is effective. Leave Effective To blank for a card with no end date. A card outside its dates is ignored.',
      run: async (d) => { await d.highlight('.card:has-text("Card Details")', 'Card details', 2800); },
    },
    {
      say: 'Below are the rate items, one per equipment category, or per specific equipment type when a single model needs its own price.',
      run: async (d) => { await d.scroll(620); await d.wait(600); await d.highlight('.card:has-text("Rate Items")', 'Rate items', 2200); },
    },
    {
      say: 'Daily, weekly and monthly rates price the rental time; billing uses them according to how long the lease runs. The mileage rate is charged per kilometre or per mile driven.',
      run: async (d) => { await d.hover(k('Weekly'), 1200); await d.hover(k('Mileage'), 1200); },
    },
    {
      say: 'Hourly is for reefer engine hours. G P S is the daily charge for the G P S tracking add-on when it is switched on for a lease.',
      run: async (d) => { await d.hover(k('Hourly'), 1300); await d.hover(k('GPS'), 1100); },
    },
    {
      say: 'Min days is a short-lease minimum. A lease shorter than this is billed as that many days, but only for categories where the short-lease minimum is switched on.',
      run: async (d) => { await d.hover(k('Min days'), 2200); },
    },
    {
      say: 'Use Edit on a line to change its prices, Add Rate for another category, then Save All Items. Changes affect new leases only; existing leases keep the rates they were created with.',
      run: async (d) => {
        await d.hover('button:has-text("+ Add Rate")', 900);
        await d.hover('button:has-text("Save All Items")', 900);
      },
    },
    {
      say: 'So how does a lease get its prices? When you pick a customer and a unit on a new lease, Fleet Forge looks for a matching rate in this order.',
      run: async (d) => { await d.goto('/leases/create'); await d.wait(1200); },
    },
    {
      say: 'First, an active customer card for that equipment. Pick Gateway Intermodal and an available dry van.',
      run: async (d) => {
        await pick(d, customerField, 'Gateway', 'Gateway Intermodal');
        await pick(d, unitField, 'DV-70', 'DV-');
        await d.wait(1500);
      },
    },
    {
      say: 'The green banner says contracted rates came from Gateway’s custom card. The rates are locked so they are not changed by accident; Unlock lets you override them.',
      run: async (d) => {
        await d.highlight('div[x-show="rateSource === \'customer\'"]', 'Customer card', 2600);
        await d.hover('#daily_rate', 700);
        await d.hover('#monthly_rate', 700);
      },
    },
    {
      say: 'Second, a global rate card. Switch the customer to Summit Carriers, who has no dry van card of their own, and the standard Dry Van card is applied instead. These rates can be adjusted.',
      run: async (d) => {
        await d.click(`${customerField} .ff-picker-clear`).catch(() => {});
        await pick(d, customerField, 'Summit', 'Summit Carriers');
        await d.wait(1500);
        await d.highlight('div[x-show="rateSource === \'rate_card\'"]', 'Global card', 2400);
      },
    },
    {
      say: 'Third, if no card matches, the default rates from the equipment type are used. If there are none, the fields stay empty for you to fill in. We will leave this lease unsaved.',
      run: async (d) => { await d.hover('#weekly_rate', 1500); },
    },
    {
      say: "Now let's give Summit Carriers contracted reefer pricing. Back on Rates, click New Rate Card.",
      run: async (d) => {
        await d.nav('Rates', '/rates');
        await d.click('a:has-text("New Rate Card")', { nav: true });
      },
    },
    {
      say: 'Give the card a clear name, and pick the customer. Leaving the customer blank would make it a global card for everyone.',
      run: async (d) => {
        await d.type('#name', 'Summit Carriers — Reefer Contract 2026', { delay: 30 });
        await pick(d, '.form-group:has(> label:has-text("Customer"))', 'Summit', 'Summit Carriers');
      },
    },
    {
      say: 'Effective From defaults to today. Add an end date if the contract expires. Only tick Set as Default if this should be the tie-breaker card; there can be just one.',
      run: async (d) => {
        await d.hover('#effective_from', 900);
        await d.hover('#effective_to', 700);
        await d.hover('#is_default', 900);
        await d.type('#description', 'Contracted reefer pricing per 2026 service agreement.', { delay: 22 });
      },
    },
    {
      say: 'Click Add Rate, choose the equipment category and currency, and optionally narrow it to a specific equipment type.',
      run: async (d) => {
        await d.scroll(500);
        await d.click('button:has-text("+ Add Rate")');
        await d.wait(500);
        await d.select('select[x-model="item.equipment_type"]', 'reefer');
        await d.select('select[x-model="item.currency"]', 'CAD');
        await d.hover('input[x-model="item._templateSearch"]', 700);
      },
    },
    {
      say: 'Enter the contracted prices: daily, weekly, monthly, the mileage rate per kilometre, the hourly reefer rate, and the G P S charge.',
      run: async (d) => {
        await d.type('input[x-model="item.daily_rate"]', '84');
        await d.type('input[x-model="item.weekly_rate"]', '500');
        await d.type('input[x-model="item.monthly_rate"]', '1850');
        await d.select('select[x-model="item.mileage_unit"]', 'km');
        await d.type('input[x-model="item.mileage_rate"]', '0.17');
        await d.type('input[x-model="item.hourly_rate"]', '12');
        await d.type('input[x-model="item.gps_price"]', '2');
      },
    },
    {
      say: 'Click Create Rate Card. From now on, a new reefer lease for Summit Carriers picks up these prices automatically, ahead of the global reefer card.',
      run: async (d) => {
        await d.scroll(400);
        if (await commitClick(d, 'button[type="submit"]:has-text("Create Rate Card")', { nav: true })) await d.wait(1500);
      },
    },
  ],
};
