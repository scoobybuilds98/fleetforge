/*
 * Chapter 10 — Batch Invoicing
 * Invoices → Batch Invoicing: choosing a calendar month or custom range, the eligibility list (unbilled /
 * billed / void), selecting leases, presets, delivery options and recipient emails, the dry-run Billing
 * Review ("Preview totals" — really computes and rolls back, creates nothing), generating drafts, and the
 * Review & Send step (preview pane, per-row PDF/Send, ZIP / combined-PDF download, bulk send).
 *
 * Records created on commit (WT_COMMIT=1 or a real recording):
 *   - ONE draft invoice for the current calendar month, for the first customer in PREFERRED_CUSTOMERS that
 *     still has an unbilled lease (never Summit Carriers — chapters 06/08 tour its invoices);
 *   - a batch preset named "Monthly — BC regional accounts", saved on camera and deleted again two scenes later.
 * Never clicked: Send, Send & Email, Download ZIP / combined PDF, Generate PDF, Submit for approval (hover only).
 * Dry runs without WT_COMMIT create nothing; the Review & Send scenes use an existing draft added via search.
 */
const DRY_NO_COMMIT = process.argv.includes('--dry') && !process.env.WT_COMMIT;
const PREFERRED_CUSTOMERS = ['Pacific Haul Transport Ltd.', 'Prairie Line Haulers Inc.', 'Coastal Container Services', 'Northgate Logistics Inc.', 'Rocky Mountain Freight Co.'];
const PRESET_NAME = 'Monthly — BC regional accounts';
const STANDIN_DRAFT = 'INV-2026-00044';

const settle = (d, ms) => d.page.waitForTimeout(ms);
const state = { customer: null };

/** recorder.mjs's dry skip path throws (`log` undefined) — in dry-without-commit only verify the control. */
async function commitClick(d, sel, opts = {}) {
  if (DRY_NO_COMMIT) return d.hover(sel, 300);
  return d.click(sel, { ...opts, commit: true });
}

/** First preferred customer that currently has an unbilled lease in the eligibility list. */
async function pickCustomer(d) {
  await d.page.locator('.batch-customer-group').first().waitFor({ timeout: 20000 });
  const name = await d.page.evaluate((prefs) => {
    const names = [...document.querySelectorAll('.batch-customer-group')]
      .filter((g) => g.querySelector('.batch-lease-status .badge')?.textContent.trim() === 'unbilled')
      .map((g) => g.querySelector('.batch-customer-name strong')?.textContent.trim());
    return prefs.find((p) => names.includes(p)) || names.find((n) => n && !/Summit/.test(n)) || null;
  }, PREFERRED_CUSTOMERS);
  if (!name) throw new Error('no customer with an unbilled lease for this month');
  return name;
}

export default {
  title: 'Batch Invoicing',
  subtitle: 'Bill a whole month across many customers in one run — check the figures, generate drafts, then send.',
  start: '/invoices',
  intro: 'In this chapter we will use Batch Invoicing to bill a month for many leases at once: choosing the period and leases, previewing the totals, generating draft invoices, and reviewing them before they are sent.',
  outro: 'That covers Batch Invoicing. Pick the month, preview, generate, review, then send.',
  scenes: [
    {
      say: 'Batch Invoicing lives under Invoices in the sidebar. Use it at month end instead of generating each lease’s invoice one at a time.',
      run: async (d) => {
        await d.nav('Batch Invoicing', '/invoices/batch');
        await d.page.locator('.batch-customer-group').first().waitFor({ timeout: 20000 });
      },
    },
    {
      say: 'Step one is the billing period. Calendar Month is the normal choice. Use the arrows or This Month and Last Month to move between months.',
      run: async (d) => {
        await d.highlight('.card:has(.card-title:has-text("1. Billing Period"))', 'Billing period', 1800);
        await d.hover('button[aria-label="Previous month"]', 600);
        await d.hover('button:has-text("Last Month")', 700);
      },
    },
    {
      say: 'Custom Range bills any span of dates instead. A full calendar month bills as a full month; any other span bills as a single period.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Custom Range")'); await settle(d, 900);
        await d.highlight('.batch-period-summary', 'How it will bill', 1600);
        await d.click('button.tab-btn:has-text("Calendar Month")');
        await d.page.locator('.batch-customer-group').first().waitFor({ timeout: 20000 });
      },
    },
    {
      say: 'Step two lists every customer with active monthly leases, and tags each lease for the chosen period: unbilled, already billed with its invoice number, or void and ready to bill again.',
      run: async (d) => {
        await d.highlight('.card:has(.card-title:has-text("2. Customers"))', 'Customers & leases', 2600);
        await d.hover('select[aria-label="Filter by billing status"]', 900);
      },
    },
    {
      say: 'Every unbilled lease starts out selected. Select all unbilled and Clear change that in one click. Today we will bill just one customer, so search for them; the selection narrows to the leases shown.',
      run: async (d) => {
        state.customer = await pickCustomer(d);
        await d.hover('button:has-text("Select all unbilled")', 800);
        await d.hover('.table-toolbar-right button:has-text("Clear")', 600);
        await d.type('input[placeholder="Search customer…"]', state.customer.split(' ').slice(0, 2).join(' '), { delay: 60 });
        // debounced reload of the eligibility list — wait for it to finish so the next click isn't lost
        await settle(d, 700);
        await d.page.waitForFunction(() => [...document.querySelectorAll('.batch-customer-group')].length > 0
          && [...document.querySelectorAll('div')].every((el) => !(el.textContent.trim() === 'Loading eligible leases…' && el.offsetParent)), null, { timeout: 30000 });
        await d.page.locator('.batch-customer-group').filter({ hasText: state.customer }).first().waitFor({ timeout: 20000 });
        await settle(d, 800);
      },
    },
    {
      say: 'A ticked lease will be billed; untick any you want to leave out. Ticking the customer row selects or clears all of that customer’s leases. The bar below counts what is selected.',
      run: async (d) => {
        const group = `.batch-customer-group:has(.batch-customer-name strong:text-is("${state.customer}"))`;
        const boxSel = `${group} .batch-lease-row:has(.badge:text-is("unbilled")) input[type=checkbox]`;
        // The search reload auto-selects the unbilled leases it returns (absent key !== false); only tick if it didn't.
        if (!(await d.page.locator(boxSel).first().isChecked())) await d.click(boxSel);
        else await d.highlight(`${group} .batch-lease-row`, 'Selected', 1600);
        await d.page.waitForFunction(() => (document.querySelector('.batch-action-bar strong')?.textContent.trim() || '0') !== '0', null, { timeout: 10000 });
        await d.highlight('.batch-action-bar', 'Selection', 1800);
      },
    },
    {
      say: 'Save a selection you use every month as a preset. It remembers the customers and leases, not the dates, so it works for any month you pick.',
      run: async (d) => {
        await d.click('button:has-text("+ Save current selection")');
        await d.type('#ff-confirm-modal input[data-ff-confirm-input]', PRESET_NAME, { delay: 35 });
        await commitClick(d, '#ff-confirm-modal button:has-text("Save preset")');
        if (DRY_NO_COMMIT) await d.click('#ff-confirm-modal button:has-text("Cancel")');
        await settle(d, 1200);
      },
    },
    {
      say: 'Next month, click the preset to select the same leases again. The cross beside it deletes a preset you no longer need.',
      run: async (d) => {
        const chip = `.batch-preset-chip:has(.batch-preset-apply:text-is("${PRESET_NAME}"))`;
        if (DRY_NO_COMMIT && !(await d.exists(chip, 1000))) { await d.hover('.batch-presets', 1500); return; }
        await d.hover(`${chip} .batch-preset-apply`, 1200);
        await d.click(`${chip} .batch-preset-del`);
        await commitClick(d, '#ff-confirm-modal button:has-text("Confirm")');
        await settle(d, 1000);
      },
    },
    {
      say: 'Delivery Options decide what Send does later. Send always marks invoices as sent and adds them to the customer’s balance. Tick the email option to also email each customer, with or without the P D F attached.',
      caption: 'Delivery Options decide what Send does later. Send always marks invoices as sent and adds them to the customer’s balance. Tick the email option to also email each customer, with or without the PDF attached.',
      run: async (d) => {
        await d.highlight('.card:has(.card-title:text-is("Delivery Options"))', 'Delivery options', 3600);
      },
    },
    {
      say: 'Recipient Emails shows where each customer’s invoice would go. Type an address to override it for this run only; the customer record is not changed.',
      run: async (d) => {
        await d.click('button.batch-collapse-header:has-text("3. Recipient Emails")');
        await settle(d, 900);
        await d.hover('.batch-recipient-row', 1400);
        await d.click('button.batch-collapse-header:has-text("3. Recipient Emails")');
      },
    },
    {
      say: 'Before creating anything, click Preview totals. FleetForge works out every invoice exactly as it would be billed, then throws the result away, so nothing is created.',
      run: async (d) => {
        await d.click('.batch-action-bar button:has-text("Preview totals")');
        await d.page.locator('.batch-review-overlay').waitFor({ timeout: 60000 });
        await settle(d, 800);
      },
    },
    {
      say: 'The Billing Review shows the total that would be billed, how many invoices, and anything that cannot be billed and would be skipped.',
      run: async (d) => { await d.highlight('.batch-review-overlay .batch-total-strip', 'Would bill', 2600); },
    },
    {
      say: 'Each invoice gets a full card: the period, billable days, rates, readings, and every line item with its amount. This is the place to catch a wrong rate before it reaches a customer.',
      run: async (d) => {
        await d.highlight('.batch-review-card', 'One card per invoice', 3000);
        await d.scroll(400, { x: 960, y: 700 });
      },
    },
    {
      say: 'If one looks wrong, Hold for review leaves it out of this run and records why, so it is not forgotten. Print or Save P D F keeps a copy of the review.',
      caption: 'If one looks wrong, Hold for review leaves it out of this run and records why, so it is not forgotten. Print / Save PDF keeps a copy of the review.',
      run: async (d) => {
        await d.hover('.batch-review-card .brc-hold-btn', 1400);
        await d.hover('.batch-review-overlay button:has-text("Print / Save PDF")', 1000);
      },
    },
    {
      say: 'When the figures look right, click Looks right, Generate, and confirm. The invoices are created as drafts. Nothing is sent to anyone yet.',
      caption: 'When the figures look right, click “Looks right — Generate” and confirm. The invoices are created as drafts. Nothing is sent to anyone yet.',
      run: async (d) => {
        if (DRY_NO_COMMIT) {
          await d.hover('.batch-review-overlay button:has-text("Looks right")', 800);
          await d.click('.batch-review-overlay button:has-text("Close")');
          return;
        }
        const n = parseInt(await d.page.locator('.batch-action-bar strong').first().innerText(), 10);
        if (!(n >= 1 && n <= 3)) throw new Error(`refusing to generate: ${n} leases selected (expected 1–3)`);
        await d.click('.batch-review-overlay button:has-text("Looks right")');
        await d.click('#ff-confirm-modal button:has-text("Confirm")', { commit: true });
        await d.page.locator('#review-and-send tbody tr').first().waitFor({ timeout: 60000 });
        await settle(d, 1500);
      },
    },
    {
      say: 'The new drafts appear in step four, Review and Send, and the first one opens in the preview pane on the right, exactly as the customer will see it.',
      run: async (d) => {
        if (DRY_NO_COMMIT) {
          // Stand-in: add an existing draft to the review list (client-side only) so the step-4 scenes have a row.
          await d.type('input[x-model="addDraftQuery"]', STANDIN_DRAFT, { delay: 40 });
          await d.click('.batch-add-result-row button:has-text("Add")');
          await d.click('#review-and-send tbody tr a.link');
        }
        await d.page.locator('#review-and-send').scrollIntoViewIfNeeded();
        await d.highlight('#review-and-send', 'Review & Send', 1800);
        await d.highlight('.batch-preview-card', 'Live invoice preview', 2200);
      },
    },
    {
      say: 'Click an invoice number to preview it. You can also search for an older draft and add it to this list.',
      run: async (d) => {
        await d.hover('#review-and-send tbody tr a.link', 1000);
        await d.hover('input[x-model="addDraftQuery"]', 1200);
      },
    },
    {
      say: 'Each row has its own recipient override, a button to generate or view its P D F, and Send for just that invoice.',
      caption: 'Each row has its own recipient override, a button to generate or view its PDF, and Send for just that invoice.',
      run: async (d) => {
        await d.hover('#review-and-send tbody tr input[type=email]', 900);
        await d.hover('#review-and-send tbody tr td button:has-text("Generate"), #review-and-send tbody tr td button:has-text("View")', 900);
        await d.hover('#review-and-send tbody tr button:has-text("Send")', 1000);
      },
    },
    {
      say: 'Download ZIP gives one P D F per invoice, and Download combined P D F merges them into a single file for printing or mailing.',
      caption: 'Download ZIP gives one PDF per invoice; Download combined PDF merges them into a single file for printing or mailing.',
      run: async (d) => {
        await d.hover('#review-and-send button:has-text("Download ZIP")', 1200);
        await d.hover('#review-and-send button:has-text("Download combined PDF")', 1200);
      },
    },
    {
      say: 'The main button sends every ticked invoice using your delivery options. Sent invoices are frozen and added to each customer’s balance, so only send once you have reviewed them.',
      run: async (d) => { await d.hover('#review-and-send .batch-action-bar .btn-primary', 3000); },
    },
    {
      say: 'If your company requires sign-off, Submit for approval freezes the previewed figures for a manager to approve before any invoice is created. Leases that fail to bill are listed under Needs Attention.',
      run: async (d) => {
        await d.page.locator('.batch-action-bar button:has-text("Submit for approval")').first().scrollIntoViewIfNeeded();
        await d.hover('.batch-action-bar button:has-text("Submit for approval")', 2600);
      },
    },
  ],
};
