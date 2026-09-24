/*
 * Chapter 05 — Rates (S-RATES-MODULE rebuild)
 * The Rates home (key numbers, "Needs a look", customer prices with how they compare to
 * the standard price, standard prices per equipment type, the Price check with a rental
 * estimate), one rate card (prices, leases on these prices, price history, Change prices
 * previewed but NOT saved), how lease creation picks a price (customer card → general card
 * → equipment-type default) shown live on the New Lease form WITHOUT saving a lease, then
 * the guided New rate card page for a customer.
 *
 * Creates on commit: ONE rate card "Summit Carriers — Reefer Contract 2026" for Summit
 * Carriers Ltd. with a single whole-category Reefer line (CAD $84/day, $500/wk, $1,850/mo,
 * $0.17/km, $12/hr, GPS $2/day). Card names must be unique among live cards, so a re-render
 * needs the previous card deleted first (prep_dev_data.php).
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

const customerField = '.form-group:has(> label:has-text("Customer"))';
const unitField = '.form-group:has(> label:has-text("Equipment Unit"))';
const line = (label) => `.rt-line:has(.rt-line-name b:text-is("${label}"))`;

export default {
  title: 'Rates',
  subtitle: 'What every customer pays — standard prices for everyone, customer rate cards for negotiated deals, and a price check that shows which price a new lease gets.',
  start: '/dashboard',
  intro: 'In this chapter we will look at Rates: what each customer pays, the standard prices, how to check a price before quoting it, how to change prices from a date, and how to set up a customer rate card.',
  outro: 'That covers Rates. Next, we will book equipment ahead of time with Reservations.',
  scenes: [
    {
      say: 'Open Rates from the sidebar. Everything you charge lives here: standard prices for everyone, and customer rate cards for negotiated deals.',
      run: async (d) => { await d.nav('Rates', '/rates'); await d.wait(1200); },
    },
    {
      say: 'The key numbers show how many customers have their own prices, how many equipment types have a standard price, what ends in the next thirty days, and anything that needs a look.',
      run: async (d) => { await d.highlight('.stat-grid', 'Key numbers', 3200); },
    },
    {
      say: 'Needs a look lists problems before they reach a lease: a price line with no prices, prices ending soon, or a customer renting with no card of their own.',
      run: async (d) => { if (await d.exists('.rt-attn')) await d.highlight('.rt-attn', 'Needs a look', 3000); },
    },
    {
      say: 'Customer prices lists every customer with their own card, with their prices right on the row. Click Gateway Intermodal to see every line.',
      caption: 'Customer prices lists every customer with their own card, with their prices right on the row. Click Gateway Intermodal to see every line.',
      run: async (d) => {
        await d.tab('Customer prices');
        await d.click('.rt-cust-row:has-text("Gateway")');
        await d.wait(1200);
      },
    },
    {
      say: 'Each line shows daily, weekly and monthly prices, distance, G P S and minimum days, and how the price compares with the standard price, in green when it is below.',
      run: async (d) => { await d.highlight('tbody.is-open .rt-grid', 'Their prices', 3400); },
    },
    {
      say: 'Standard prices is what a customer without their own card pays for each equipment type, with how many customers have a deal on it and how many units are out.',
      run: async (d) => { await d.tab('Standard prices'); await d.wait(1200); await d.scroll(300); await d.wait(900); },
    },
    {
      say: 'Price check answers the everyday question: what would this customer pay? Pick Gateway Intermodal and a dry van.',
      caption: 'Price check answers the everyday question: what would this customer pay? Pick Gateway Intermodal and a dry van.',
      run: async (d) => {
        await d.tab('Price check');
        await pick(d, '.rt-check', 'Gateway', 'Gateway Intermodal');
        const v = await d.page.locator('#rt-check-type option', { hasText: /dry van/i }).first().getAttribute('value');
        if (v) await d.select('#rt-check-type', v);
        await d.wait(1500);
      },
    },
    {
      say: 'You see the price a new lease would get, which card it comes from, and how it compares with the standard price. Below, the steps show exactly why this price won.',
      run: async (d) => {
        await d.highlight('.rt-source', 'Where it comes from', 2200);
        await d.highlight('.rt-tiers', 'Why this price', 2400);
      },
    },
    {
      say: 'Tick Estimate a rental and choose fourteen days. The estimate uses the same rules as invoicing, so it is the amount the customer would be billed before tax.',
      run: async (d) => {
        await d.click('label:has-text("Estimate a rental") input');
        await d.click('.rt-quick button:has-text("14 days")');
        await d.wait(1400);
        await d.highlight('.rt-quote', 'Estimate', 2600);
      },
    },
    {
      say: "Now open Gateway's card. The Prices table shows every line with how it compares to the standard price.",
      run: async (d) => { await d.goto('/rates/show?id=5'); await d.wait(1000); await d.highlight('#prices', 'Prices', 2400); },
    },
    {
      say: 'Leases on these prices lists the leases this card prices today, and flags any still on different prices. A card never changes a lease that is already out.',
      run: async (d) => { if (await d.exists('#leases')) { await d.scroll(700); await d.highlight('#leases', 'Leases on these prices', 2800); } },
    },
    {
      say: 'Price history shows each price over time, and every change with who made it.',
      run: async (d) => { await d.scroll(600); await d.highlight('#history', 'Price history', 2600); },
    },
    {
      say: 'To change prices, use Change prices. Choose the date the new prices start and raise them by a percentage, or type new ones. The old prices end the day before and stay on record.',
      caption: 'To change prices, use Change prices. Choose the date the new prices start and raise them by a percentage, or type new ones. The old prices end the day before and stay on record.',
      run: async (d) => {
        await d.scroll(-2000);
        await d.click('button:has-text("Change prices")');
        await d.wait(700);
        await d.type('.modal .rt-money--pct input', '4', { delay: 90 });
        await d.click('.modal .rt-seg button:has-text("$1")');
      },
    },
    {
      say: 'Preview shows every line before and after. We will go back without saving.',
      run: async (d) => {
        await d.click('.modal button:has-text("Preview")');
        await d.wait(1500);
        await d.highlight('.modal .rt-preview', 'Before and after', 2600);
        await d.click('.modal button:has-text("Back")');
        await d.click('.modal-close-btn');
      },
    },
    {
      say: 'So how does a lease get its prices? When you pick a customer and a unit on a new lease, Fleet Forge looks for a price in this order.',
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
      say: 'Second, a general rate card. Switch the customer to Summit Carriers, who has no dry van card of their own, and the standard Dry Van card is applied instead. These rates can be adjusted.',
      run: async (d) => {
        await d.click(`${customerField} .ff-picker-clear`).catch(() => {});
        await pick(d, customerField, 'Summit', 'Summit Carriers');
        await d.wait(1500);
        await d.highlight('div[x-show="rateSource === \'rate_card\'"]', 'General card', 2400);
      },
    },
    {
      say: 'Third, if no card matches, the default prices of the equipment type are used. If there are none, the fields stay empty for you to fill in. We will leave this lease unsaved.',
      run: async (d) => { await d.hover('#weekly_rate', 1500); },
    },
    {
      say: "Now let's give Summit Carriers contracted reefer pricing. Back on Rates, click New rate card.",
      run: async (d) => {
        await d.nav('Rates', '/rates');
        await d.click('a:has-text("+ New rate card")', { nav: true });
      },
    },
    {
      say: 'Step one: who are the prices for? Choose One customer and pick Summit Carriers. Everyone would make a standard price list instead.',
      run: async (d) => {
        await d.click('.rt-choice:has-text("One customer")');
        await pick(d, '.rt-step', 'Summit', 'Summit Carriers');
        await d.wait(1200);
      },
    },
    {
      say: 'Step two: which equipment? Each option shows what Summit pays today. Tick Reefer to price every reefer type.',
      run: async (d) => {
        await d.click('.rt-pick:has(b:text-is("Reefer"))');
        await d.wait(800);
      },
    },
    {
      say: 'Step three: the line starts from today’s price, so you only change what is different. Enter the contracted prices: daily, weekly, monthly, distance per kilometre, engine hours and G P S.',
      run: async (d) => {
        await d.type(`${line('Reefer')} input[x-model="l.daily_rate"]`, '84');
        await d.type(`${line('Reefer')} input[x-model="l.weekly_rate"]`, '500');
        await d.type(`${line('Reefer')} input[x-model="l.monthly_rate"]`, '1850');
        await d.select(`${line('Reefer')} select[x-model="l.mileage_unit"]`, 'km');
        await d.type(`${line('Reefer')} input[x-model="l.mileage_rate"]`, '0.17');
        await d.type(`${line('Reefer')} input[x-model="l.hourly_rate"]`, '12');
        await d.type(`${line('Reefer')} input[x-model="l.gps_price"]`, '2');
      },
    },
    {
      say: 'Steps four and five: the dates, which start today by default, and a clear name. The summary on the right says in plain words what will change, and checks everything before you save.',
      run: async (d) => {
        await d.type('#rt-name', 'Summit Carriers — Reefer Contract 2026', { delay: 30 });
        await d.type('#rt-desc', 'Contracted reefer pricing per 2026 service agreement.', { delay: 22 });
        await d.highlight('.rt-summary', 'Summary', 2600);
      },
    },
    {
      say: 'Click Create rate card. From now on, a new reefer lease for Summit Carriers picks up these prices automatically, ahead of the standard reefer price.',
      run: async (d) => {
        if (await commitClick(d, 'button:has-text("Create rate card")', { nav: true })) await d.wait(1500);
      },
    },
  ],
};
