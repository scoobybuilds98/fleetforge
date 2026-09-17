/*
 * Demo — Batch Invoicing, in depth (standalone, outside the numbered course).
 * Record with: node scripts/walkthrough/record.mjs --file scripts/walkthrough/demos/batch-invoicing-deep.mjs [--dry]
 *
 * Covers, in month-end order: why batch billing exists; the billing period (calendar month, arrows,
 * This/Last Month, custom range, full_month vs single_period, how open-ended / mid-month / short leases
 * are priced); the eligibility list (unbilled / billed with invoice number / void, status filter, search,
 * per-customer and per-lease ticks, Select all unbilled, Clear, the counter); presets; delivery options
 * and per-run recipient overrides; the dry-run Billing Review (every part of a card, skipped leases,
 * Hold for review, Needs Attention, Print); the approval workflow (setting on, Submit for approval, the
 * batch run page, approve, generate, setting back off); direct Generate; Review and Send (preview pane,
 * row override, PDF, add an older draft, ZIP / combined PDF, Send — hover only); then Invoices: open a
 * new draft, regenerate it, void it, and show its lease become billable again.
 *
 * Records created on commit (WT_COMMIT=1 or a real recording) — the dev DB is snapshotted around runs:
 *   - a batch preset "Monthly — BC regional accounts" (settings row), applied and deleted again on camera;
 *   - one billing exception (Hold for review) for customer B, then marked Fixed on camera;
 *   - settings invoices.approval_required 0 -> 1 -> 0 (saved twice through Settings);
 *   - one approval run (invoice_batch_runs, pending -> approved -> generated) for customer A,
 *     which creates ONE draft invoice for the current calendar month;
 *   - ONE more draft invoice for customer B via direct Generate, then regenerated in place and VOIDED;
 *   - PDF files for the drafts in the review list (Generate PDF, Download ZIP, Download combined PDF);
 *   - audit_log rows for all of the above.
 * Customers A and B are chosen at runtime from PREFERRED (never Summit Carriers, never SMOKE debris).
 * Never clicked: Send, Mark as Sent, Send and Email, Email Invoice, Print dialogs, QuickBooks anything.
 * Dry runs without WT_COMMIT create nothing; post-commit scenes fall back to existing records.
 */
const DRY_NO_COMMIT = process.argv.includes('--dry') && !process.env.WT_COMMIT;
const PREFERRED = ['Pacific Haul Transport Ltd.', 'Prairie Line Haulers Inc.', 'Evergreen Transport Group', 'Coastal Container Services', 'Interior Bulk Transport', 'Rocky Mountain Freight Co.', 'Northgate Logistics Inc.'];
const PRESET_NAME = 'Monthly — BC regional accounts';
const STANDIN_QUERY = 'Pacific Haul';

const state = { a: null, b: null, invB: null, runUrl: null };
const settle = (d, ms) => d.page.waitForTimeout(ms);
const grp = (name) => `.batch-customer-group:has(.batch-customer-name strong:text-is("${name}"))`;
const bar = '.batch-left > .batch-action-bar';
const confirmBtn = (label) => `#ff-confirm-modal button:has-text("${label}")`;

/** Commit click that in a plain dry run only hovers the control (so no modal is left half-done). */
async function commitClick(d, sel, opts = {}) {
  if (DRY_NO_COMMIT) return d.hover(sel, 300);
  return d.click(sel, { ...opts, commit: true });
}

/** Wait for the eligibility list to finish (re)loading. */
async function waitElig(d) {
  // the search box reloads on a 400ms debounce, so give a pending reload time to start first
  await settle(d, 1000);
  await d.page.waitForFunction(() => {
    const el = document.getElementById('batch-invoicing-app');
    const s = el && window.Alpine && window.Alpine.$data(el);
    return s && !s.eligLoading;
  }, null, { timeout: 30000 });
  await settle(d, 300);
}

/** Two customers (A for the approval run, B for direct generate) with exactly one unbilled lease each. */
async function pickCustomers(d) {
  await waitElig(d);
  const names = await d.page.evaluate(() => {
    const s = window.Alpine.$data(document.getElementById('batch-invoicing-app'));
    return s.customers.filter((c) => c.leases.filter((l) => l.billing_status === 'unbilled').length === 1 && c.leases.length === 1)
      .map((c) => c.company_name);
  });
  const pool = PREFERRED.filter((p) => names.includes(p));
  if (pool.length < 2) throw new Error(`need two single-lease unbilled customers, found: ${pool.join(', ')}`);
  [state.a, state.b] = pool;
}

/** Re-try a pointer action once — the eligibility list re-renders when Alpine reloads it. */
async function retry(fn) {
  try { return await fn(); } catch { return fn(); }
}

/** Make sure exactly the named customers' leases are ticked (repairs a click lost to a re-render). */
async function ensureSelected(d, names) {
  const ok = () => d.page.evaluate((names) => {
    const s = window.Alpine.$data(document.getElementById('batch-invoicing-app'));
    const want = s.customers.filter((c) => names.includes(c.company_name)).flatMap((c) => c.leases.filter((l) => l.billing_status === 'unbilled').map((l) => l.id));
    const have = s.selectedLeaseIds();
    return want.length === have.length && want.every((id) => have.includes(id));
  }, names);
  for (let i = 0; i < 3 && !(await ok()); i++) {
    await d.click('.table-toolbar-right button:has-text("Clear")');
    for (const n of names) await retry(() => d.click(`${grp(n)} .batch-lease-row input[type=checkbox]`));
    await settle(d, 400);
  }
  if (!(await ok())) throw new Error(`selection is not exactly ${names.join(' + ')}`);
}

async function setPeriodThisMonth(d) {
  await d.click('button:has-text("This Month")');
  await waitElig(d);
}

export default {
  title: 'Batch Invoicing — In Depth',
  label: 'In-depth demo',
  chip: 'Demo · Batch Invoicing',
  subtitle: 'A full month-end billing run: period, selection, review, approval, drafts, and what happens next.',
  start: '/invoices',
  intro: 'This in-depth demo walks through a complete month-end billing run with Batch Invoicing. We will cover every control on the page, the approval workflow, what gets created, and how to fix an invoice after the run.',
  outro: 'That is Batch Invoicing in depth. Pick the period, check the selection, preview, get approval if required, generate drafts, review them, and only then send.',
  scenes: [
    // ───────────────────────── Orientation ─────────────────────────
    {
      say: 'Each lease can generate its own invoice, but at month end that means opening dozens of leases one by one. Batch Invoicing bills the month for every customer from one screen.',
      run: async (d) => {
        await d.hover('a:has-text("Batch Invoicing")', 1200);
        await d.nav('Batch Invoicing', '/invoices/batch');
        await waitElig(d);
      },
    },
    {
      say: 'It sits under Invoices in the sidebar. Previewing and generating need permission to create invoices; sending needs permission to edit them.',
      run: async (d) => {
        await d.highlight('.page-header', 'Batch Invoicing', 2000);
        await d.hover('.page-header-actions a:has-text("All Invoices")', 800);
      },
    },

    // ───────────────────────── Step 1: period ─────────────────────────
    {
      say: 'Step one is the billing period. Calendar Month opens on the current month. The arrows step one month at a time, and This Month and Last Month jump straight there.',
      run: async (d) => {
        await d.highlight('.batch-period-row', 'Billing period', 1400);
        await d.click('button[aria-label="Previous month"]');
        await waitElig(d);
        await d.click('button[aria-label="Next month"]');
        await waitElig(d);
        await d.click('button:has-text("Last Month")');
        await waitElig(d);
      },
    },
    {
      say: 'Last month is already billed, so set the filter to All statuses. Each lease is tagged billed, with the invoice that covers it.',
      run: async (d) => {
        await d.select('select[aria-label="Filter by billing status"]', 'all');
        await settle(d, 600);
        await d.highlight('.batch-lease-row:has(.badge:text-is("billed"))', 'Billed', 2000);
      },
    },
    {
      say: 'Billed means an invoice that is not void already covers part of the period, drafts included. Click the invoice number to preview it on the right.',
      run: async (d) => {
        await d.click('.batch-lease-row:has(.badge:text-is("billed")) .batch-lease-status a.link');
        await d.page.locator('.batch-preview-card iframe').waitFor({ timeout: 15000 });
        await settle(d, 1500);
        await d.highlight('.batch-preview-card', 'Existing invoice', 1600);
        await d.click('.batch-preview-card button:has-text("Close")');
      },
    },
    {
      say: 'Void means the only invoice for the period was voided, so the lease can be billed again. Void leases are never ticked automatically; tick them yourself when you mean to rebill.',
      run: async (d) => {
        await d.select('select[aria-label="Filter by billing status"]', 'void');
        await settle(d, 600);
        await d.highlight('.batch-customer-list', 'Void — rebillable', 2200);
      },
    },
    {
      say: 'Custom Range bills any span of dates. First to last day of a month still counts as a full month; anything else bills as a single period.',
      run: async (d) => {
        await d.select('select[aria-label="Filter by billing status"]', 'unbilled');
        await setPeriodThisMonth(d);
        await d.click('button.tab-btn:has-text("Custom Range")');
        await settle(d, 500);
        const month = await d.page.evaluate(() => window.Alpine.$data(document.getElementById('batch-invoicing-app')).monthValue);
        await d.hover('input[aria-label="Period start"]', 300);
        await d.page.fill('input[aria-label="Period start"]', `${month}-01`);
        await d.hover('input[aria-label="Period end"]', 300);
        await d.page.fill('input[aria-label="Period end"]', `${month}-15`);
        await d.page.locator('input[aria-label="Period end"]').dispatchEvent('change');
        await waitElig(d);
        await d.highlight('.batch-period-summary', 'Single period', 1800);
      },
    },
    {
      say: 'That label is only a description. The amount is what the lease should have billed in total up to the end of the period, minus what is already invoiced. The lease end used is the return date, else the expected end date if that is still ahead, else the end of the period, so a lease still out past its expected end keeps billing.',
      run: async (d) => { await d.highlight('.batch-period-summary', 'How the amount is worked out', 4000); },
    },
    {
      say: 'So a lease returned mid-month bills only to its return date, and a monthly lease of one calendar month or less pays the flat monthly rate, even across a month boundary. A full month run early bills the whole month in advance.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Calendar Month")');
        await waitElig(d);
        await d.highlight('.batch-period-summary', 'Full calendar month', 2600);
      },
    },

    // ───────────────────────── Step 2: selection ─────────────────────────
    {
      say: 'Step two lists every customer with an active, monthly-billed lease that started by the period end, counted in the header.',
      run: async (d) => {
        await pickCustomers(d);
        await d.highlight('.card:has(.card-title:has-text("2. Customers")) .card-header', 'Customers and leases', 2200);
      },
    },
    {
      say: 'Each customer row shows the account status, lease count, and the email the invoice would go to, with a warning icon for a missing or blocked address. A customer on credit hold is not skipped automatically.',
      run: async (d) => {
        await retry(() => d.highlight(`${grp(state.a)} .batch-customer-row`, 'Customer', 2400));
        await retry(() => d.hover(`${grp(state.a)} .batch-customer-recipient`, 1200));
      },
    },
    {
      say: 'Unbilled leases start out ticked. Clear unticks everything, Select all unbilled ticks them again, and the bar counts the leases and customers selected.',
      run: async (d) => {
        await d.click('.table-toolbar-right button:has-text("Clear")');
        await d.highlight(bar, '0 selected', 1200);
        await d.click('button:has-text("Select all unbilled")');
        await d.highlight(bar, 'Selection', 1500);
      },
    },
    {
      say: 'Search matches customer names. It reloads the list with just the matches and ticks their unbilled leases, so anything outside the search drops out of the selection.',
      run: async (d) => {
        await d.type('input[placeholder="Search customer…"]', state.a.split(' ').slice(0, 2).join(' '), { delay: 60 });
        await waitElig(d);
        await d.highlight('.batch-customer-list', 'Search result', 1600);
        await d.page.fill('input[placeholder="Search customer…"]', '');
        await d.page.locator('input[placeholder="Search customer…"]').dispatchEvent('input');
        await waitElig(d);
      },
    },
    {
      say: 'Click a lease row to tick or untick it, or tick the customer row for all of their leases. For this run we clear everything and pick two customers.',
      run: async (d) => {
        await d.click('.table-toolbar-right button:has-text("Clear")');
        await retry(() => d.click(`${grp(state.a)} .batch-customer-row input[type=checkbox]`));
        await retry(() => d.click(`${grp(state.b)} .batch-lease-row input[type=checkbox]`));
        await ensureSelected(d, [state.a, state.b]);
        await d.highlight(bar, '2 leases, 2 customers', 1800);
      },
    },
    {
      say: 'Save a group you bill every month as a preset. It stores the leases, customers, status filter and delivery options, never dates, and it is shared with everyone who uses this page.',
      run: async (d) => {
        await d.click('button:has-text("+ Save current selection")');
        await d.type('#ff-confirm-modal input[data-ff-confirm-input]', PRESET_NAME, { delay: 35 });
        await commitClick(d, confirmBtn('Save preset'));
        if (DRY_NO_COMMIT) await d.click(confirmBtn('Cancel'));
        await settle(d, 1000);
      },
    },
    {
      say: 'Next month, click the preset to tick the same leases again. Here we clear the selection, click the preset, and both leases come back, with a note of any that are no longer eligible.',
      run: async (d) => {
        const chip = `.batch-preset-chip:has(.batch-preset-apply:text-is("${PRESET_NAME}"))`;
        if (!(await d.exists(chip, 1500))) { await d.hover('.batch-presets', 2000); return; }
        await d.click('.table-toolbar-right button:has-text("Clear")');
        await d.highlight(bar, '0 selected', 1000);
        await d.click(`${chip} .batch-preset-apply`);
        await d.page.waitForFunction(() => document.querySelector('.batch-left > .batch-action-bar strong')?.textContent.trim() === '2', null, { timeout: 8000 });
        await ensureSelected(d, [state.a, state.b]);
        await d.highlight(bar, 'Preset applied', 1800);
      },
    },
    {
      say: 'Saving again under the same name replaces a preset, and the cross deletes it.',
      run: async (d) => {
        const chip = `.batch-preset-chip:has(.batch-preset-apply:text-is("${PRESET_NAME}"))`;
        if (!(await d.exists(chip, 1500))) { await d.hover('.batch-presets', 1500); return; }
        await d.click(`${chip} .batch-preset-del`);
        await commitClick(d, confirmBtn('Confirm'));
        if (DRY_NO_COMMIT) await d.click(confirmBtn('Cancel'));
        await settle(d, 900);
      },
    },
    {
      say: 'Delivery Options set what Send does later, for this run only. Send always marks invoices as sent. Email is optional, and the P D F can only be attached to an email. Leave email off when invoices are posted or hand-delivered.',
      caption: 'Delivery Options set what Send does later, for this run only. Send always marks invoices as sent. Email is optional, and the PDF can only be attached to an email. Leave email off when invoices are posted or hand-delivered.',
      run: async (d) => {
        await d.highlight('.card:has(.card-title:text-is("Delivery Options"))', 'Delivery options', 1800);
        await d.click('label:has-text("Also email the invoice to the customer") input');
        await settle(d, 700);
        await d.click('label:has-text("Also email the invoice to the customer") input');
        await d.hover('label:has-text("Attach the invoice PDF")', 900);
      },
    },
    {
      say: 'Recipient Emails shows each customer’s address: invoice email first, then billing email, then general email. Type an address to override it for this run; it is carried onto the invoice, and the customer record is unchanged.',
      run: async (d) => {
        await d.click('button.batch-collapse-header:has-text("3. Recipient Emails")');
        await settle(d, 700);
        const domain = state.a.toLowerCase().split(' ').slice(0, 2).join('');
        await d.type(`.batch-recipient-row:has(.batch-recipient-name:text-is("${state.a}")) input[type=email]`, `priya.sandhu@${domain}.ca`, { delay: 35 });
        await settle(d, 600);
        await d.click('button.batch-collapse-header:has-text("3. Recipient Emails")');
      },
    },

    // ───────────────────────── Billing Review (dry run) ─────────────────────────
    {
      say: 'Now click Preview totals. FleetForge runs the real invoice calculation for each lease, then rolls it back, so nothing is saved and no invoice numbers are used.',
      run: async (d) => {
        await d.click(`${bar} button:has-text("Preview totals")`);
        await d.page.locator('.batch-review-overlay').waitFor({ timeout: 60000 });
        await settle(d, 900);
        await d.highlight('.batch-review-head', 'Dry run', 1600);
      },
    },
    {
      say: 'The strip shows the amount per currency, invoices and customers. Leases that cannot be billed, for example with no rates, are counted and listed first with the reason, and Generate flags them for follow-up.',
      run: async (d) => {
        await d.highlight('.batch-review-overlay .batch-total-strip', 'Would bill', 2400);
        if (await d.exists('.batch-review-problems', 800)) await d.highlight('.batch-review-problems', 'Will be skipped', 2000);
      },
    },
    {
      say: 'Each invoice gets a card: customer, contract, unit and total, then the period, billable days, rate method, billing type, invoice and due dates, and the lease term.',
      run: async (d) => {
        await d.highlight('.batch-review-card .brc-head', 'Card header', 1800);
        await d.highlight('.batch-review-card .brc-facts', 'Facts', 2000);
      },
    },
    {
      say: 'Below are the rates the lease bills from, mileage settings, odometer and engine hour readings when they apply, add-ons like insurance and G P S, minimum days, and the exchange rate for U S dollar leases.',
      caption: 'Below are the rates the lease bills from, mileage settings, odometer and engine hour readings when they apply, add-ons like insurance and GPS, minimum days, and the exchange rate for US dollar leases.',
      run: async (d) => { await d.hover('.batch-review-card .brc-fact:has-text("Monthly rate")', 3200); },
    },
    {
      say: 'Then every line item with quantity, unit price and amount, credits in red, and the subtotal, discount, taxes and total. This is where you catch a wrong rate.',
      run: async (d) => {
        await d.highlight('.batch-review-card .brc-lines', 'Line items', 2000);
        await d.highlight('.batch-review-card .brc-summary-figures', 'Totals', 1800);
      },
    },
    {
      say: 'If one looks wrong, click Hold for review and say what is wrong. The lease is dropped from this run and the reason is recorded; the rest of the batch is unaffected.',
      run: async (d) => {
        const card = `.batch-review-card:has(.brc-customer span:text-is("${state.b}"))`;
        await d.page.locator(`${card} .brc-hold-btn`).first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
        await settle(d, 400);
        await d.click(`${card} .brc-hold-btn`);
        await d.type('#ff-confirm-modal input[data-ff-confirm-input]', 'Insurance charge needs checking against the signed agreement', { delay: 25 });
        await commitClick(d, confirmBtn('Hold it back'));
        if (DRY_NO_COMMIT) { await d.click(confirmBtn('Cancel')); return; }
        await d.page.locator(`${card}.is-held`).waitFor({ timeout: 15000 });
        await d.highlight(`${card} .brc-head`, 'Held for review', 1800);
      },
    },
    {
      say: 'The card is marked Held for review, and the Generate button now counts one fewer, plus one flagged. Print or Save P D F keeps a copy of the review for the month-end file.',
      caption: 'The card is marked Held for review, and the Generate button now counts one fewer, plus one flagged. Print / Save PDF keeps a copy of the review for the month-end file.',
      run: async (d) => {
        await d.highlight('.batch-review-overlay .batch-review-head-actions .btn-primary', 'Generate', 1600);
        await d.hover('.batch-review-overlay button:has-text("Print / Save PDF")', 1400);
        await d.click('.batch-review-overlay button:has-text("Close")');
      },
    },
    {
      say: 'The held lease now waits under Needs Attention with its reason, as would any lease that failed to generate. Skip records why it is deliberately not billed; Fixed clears the flag once it has been checked.',
      run: async (d) => {
        const na = '.card:has(.card-title:has-text("Needs Attention"))';
        if (!(await d.exists(na, 3000))) { await d.highlight(bar, 'Selection', 3000); return; }
        await d.highlight(na, 'Needs attention', 2200);
        await d.hover(`${na} tr:has-text("${state.b}") button:has-text("Skip")`, 900);
        await commitClick(d, `${na} tr:has-text("${state.b}") button:has-text("Fixed")`);
        await settle(d, 1000);
      },
    },
    {
      say: 'Having checked it, reopen the review. Reopen review brings the figures back without recalculating, and Include again puts the held lease back into the run.',
      run: async (d) => {
        await d.click(`${bar} button:has-text("Reopen review")`);
        await d.page.locator('.batch-review-overlay').waitFor({ timeout: 10000 });
        const card = `.batch-review-card:has(.brc-customer span:text-is("${state.b}"))`;
        if (await d.exists(`${card} .brc-hold-btn.is-held`, 1500)) {
          await d.page.locator(`${card} .brc-hold-btn.is-held`).first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
          await settle(d, 400);
          await d.click(`${card} .brc-hold-btn.is-held`);
          await settle(d, 900);
        } else {
          await d.hover(`${card} .brc-hold-btn`, 1500);
        }
        await d.click('.batch-review-overlay button:has-text("Close")');
      },
    },
    {
      say: 'Some companies want a second person to sign off billing. That is in Settings, General tab, Invoices and Billing. Require approval turns off direct generation here; self-approval decides whether the submitter may approve.',
      run: async (d) => {
        await d.goto('/settings');
        await d.highlight('.card:has([id="invoices.approval_required"])', 'Invoices and Billing', 2000);
        await d.hover('label[for="invoices.approval_allow_self"]', 1000);
      },
    },
    {
      say: 'We turn approval on and save.',
      run: async (d) => {
        await d.click('[id="invoices.approval_required"]');
        await d.click('button:has-text("Save Invoices & Billing Settings")', { nav: true, commit: true });
      },
    },
    {
      say: 'Back on Batch Invoicing, our selection was restored from this browser tab, but not the figures. The bar now shows Approval required, Generate is gone, and Submit for approval is the main action.',
      run: async (d) => {
        await d.goto('/invoices/batch');
        await waitElig(d);
        if (await d.exists('.batch-restored-note', 1500)) await d.highlight('.batch-restored-note', 'Restored', 1800);
        await d.highlight(bar, 'Approval required', 2200);
      },
    },
    {
      say: 'Submitting runs the preview once more and freezes the figures. Add a note for the approver and submit.',
      run: async (d) => {
        await ensureSelected(d, [state.a]);
        await d.click(`${bar} button:has-text("Submit for approval")`);
        await d.type('#ff-confirm-modal input[data-ff-confirm-input]', 'Monthly run for approval', { delay: 35 });
        if (DRY_NO_COMMIT) { await d.hover(confirmBtn('Submit for approval'), 600); await d.click(confirmBtn('Cancel')); return; }
        await d.click(confirmBtn('Submit for approval'), { commit: true });
        await d.page.waitForURL(/batch_run\?id=/, { timeout: 60000 });
        await d.ready();
        state.runUrl = d.page.url();
      },
    },
    {
      say: 'Each run has its own page and reference, so you can send the link to the approver. The figures are the frozen snapshot, never recalculated here, shown per lease or per customer.',
      run: async (d) => {
        if (!state.runUrl) { await d.hover(`${bar} button:has-text("Submit for approval")`, 3000); return; }
        await d.highlight('.batch-review-head', 'Batch run', 1800);
        await d.click('button.brc-view-btn:has-text("By customer")');
        await settle(d, 900);
        await d.click('button.brc-view-btn:has-text("By lease")');
      },
    },
    {
      say: 'Approving needs the invoice approve permission, held by managers, accountants and super admins by default. Reject asks for a reason. With self-approval off, the submitter is blocked, super admins included, and a colleague must approve.',
      run: async (d) => {
        if (!state.runUrl) { await d.hover(`${bar} button:has-text("Submit for approval")`, 3000); return; }
        await d.hover('.batch-review-head-actions button:has-text("Reject")', 1400);
        await d.hover('.batch-review-head-actions button:has-text("Approve")', 1400);
      },
    },
    {
      say: 'Self-approval is allowed here, so we approve it ourselves, with an optional note.',
      run: async (d) => {
        if (!state.runUrl) { await d.hover(`${bar} button:has-text("Submit for approval")`, 2000); return; }
        await d.click('.batch-review-head-actions button:has-text("Approve")');
        await d.click(confirmBtn('Confirm'), { commit: true });
        await d.type('#ff-confirm-modal input[data-ff-confirm-input]', 'Checked against lease rates', { delay: 30 });
        await d.click(confirmBtn('Approve run'), { commit: true });
        await d.page.locator('.brc-status-pill[data-status="approved"]').waitFor({ timeout: 30000 });
        await d.ready();
      },
    },
    {
      say: 'An approved run can be generated. Each lease is rechecked first: anything closed or billed since approval is skipped, and any total that changed is listed on the run.',
      run: async (d) => {
        if (!state.runUrl) { await d.hover(`${bar} button:has-text("Submit for approval")`, 3000); return; }
        await d.click('.batch-review-head-actions button.btn-primary:has-text("Generate")');
        await d.click(confirmBtn('Confirm'), { commit: true });
        await d.page.locator('.brc-status-pill[data-status="generated"]').waitFor({ timeout: 60000 });
        await d.ready();
        await d.highlight('.brc-note:has-text("created")', 'Generated', 2000);
      },
    },
    {
      say: 'Approval only applies to this page; single invoices and the monthly schedule are unaffected. We turn the setting back off.',
      run: async (d) => {
        await d.goto('/settings');
        await d.hover('[id="invoices.approval_required"]', 500);
        if (await d.page.locator('[id="invoices.approval_required"]').isChecked()) await d.click('[id="invoices.approval_required"]');
        await d.click('button:has-text("Save Invoices & Billing Settings")', { nav: true, commit: true });
      },
    },

    // ───────────────────────── Direct generate ─────────────────────────
    {
      say: 'Back here, Approval Runs lists recent runs with status and totals. Without approval, select the second customer and click Generate, or use Looks right, Generate in the review.',
      caption: 'Back here, Approval Runs lists recent runs with status and totals. Without approval, select the second customer and click Generate, or use “Looks right — Generate” in the review.',
      run: async (d) => {
        await d.goto('/invoices/batch');
        await waitElig(d);
        if (await d.exists('.card:has(.card-title:has-text("Approval Runs"))', 1500)) await d.highlight('.card:has(.card-title:has-text("Approval Runs"))', 'Approval runs', 2000);
        await d.click('.table-toolbar-right button:has-text("Clear")');
        await d.type('input[placeholder="Search customer…"]', state.b.split(' ').slice(0, 2).join(' '), { delay: 50 });
        await waitElig(d);
        await ensureSelected(d, [state.b]);
        await d.hover(`${bar} button.btn-primary:has-text("Generate")`, 800);
      },
    },
    {
      say: 'Confirm, and the invoices are created as drafts. Nothing is sent and no balances change. The result card lists anything skipped, and failures are flagged under Needs Attention.',
      run: async (d) => {
        await d.click(`${bar} button.btn-primary:has-text("Generate")`);
        if (DRY_NO_COMMIT) {
          await d.hover(confirmBtn('Confirm'), 600);
          await d.click(confirmBtn('Cancel'));
          await d.type('input[x-model="addDraftQuery"]', STANDIN_QUERY, { delay: 40 });
          await d.page.locator('.batch-add-result-row').first().waitFor({ timeout: 15000 });
          await d.click('.batch-add-result-row button:has-text("Add")');
          await d.click('#review-and-send tbody tr a.link');
          return;
        }
        await d.click(confirmBtn('Confirm'), { commit: true });
        await d.page.locator('#review-and-send tbody tr').first().waitFor({ timeout: 60000 });
        await settle(d, 1500);
        state.invB = (await d.page.locator('#review-and-send tbody tr', { hasText: state.b }).last().locator('a.link').first().innerText()).trim();
        if (await d.exists('.card:has(.card-title:text-is("Generation Result"))', 1500)) await d.highlight('.card:has(.card-title:text-is("Generation Result"))', 'Result', 1800);
      },
    },

    // ───────────────────────── Step 4: Review & Send ─────────────────────────
    {
      say: 'New drafts land in step four, Review and Send, and the first opens in the preview pane as the customer will see it. The arrow beside a number opens it in a new tab.',
      run: async (d) => {
        await d.page.locator('#review-and-send').scrollIntoViewIfNeeded();
        await d.highlight('#review-and-send table', 'Review and Send', 1800);
        await d.highlight('.batch-preview-card', 'Live preview', 1800);
        await d.hover('#review-and-send tbody tr a[title^="Open the full invoice"]', 800);
      },
    },
    {
      say: 'Older drafts can join the list: search by number or customer and click Add, here the draft from the approved run.',
      run: async (d) => {
        await d.type('input[x-model="addDraftQuery"]', state.a.split(' ').slice(0, 2).join(' '), { delay: 45 });
        await settle(d, 1200);
        if (await d.exists('.batch-add-result-row', 6000)) await d.click('.batch-add-result-row button:has-text("Add")');
        await settle(d, 600);
      },
    },
    {
      say: 'Each row has its own Send To override. The P D F button generates the invoice P D F and opens it; after that it reads View.',
      caption: 'Each row has its own Send To override. The PDF button generates the invoice PDF and opens it; after that it reads View.',
      run: async (d) => {
        await d.hover('#review-and-send tbody tr input[type=email]', 1400);
        const btn = '#review-and-send tbody tr td button:has-text("Generate"), #review-and-send tbody tr td button:has-text("View")';
        if (DRY_NO_COMMIT) { await d.hover(btn, 1500); return; }
        const popup = d.page.context().waitForEvent('page', { timeout: 30000 }).catch(() => null);
        await d.click(btn, { commit: true });
        const p = await popup;
        await settle(d, 1500);
        if (p) await p.close().catch(() => {});
        await d.page.bringToFront();
      },
    },
    {
      say: 'The strip totals the list and counts drafts and sent. Download ZIP gives one P D F per invoice for filing; Download combined P D F merges them for printing or mailing.',
      caption: 'The strip totals the list and counts drafts and sent. Download ZIP gives one PDF per invoice for filing; Download combined PDF merges them for printing or mailing.',
      run: async (d) => {
        await d.highlight('#review-and-send .batch-total-strip', 'List totals', 1500);
        // Downloads stream through fetch and a temporary link, so no tab or dialog opens.
        const idle = () => d.page.waitForFunction(() => !window.Alpine.$data(document.getElementById('batch-invoicing-app')).downloading, null, { timeout: 90000 });
        const zip = d.page.waitForEvent('download', { timeout: 90000 }).catch(() => null);
        await commitClick(d, '#review-and-send button:has-text("Download ZIP")');
        if (!DRY_NO_COMMIT) { await idle(); if (!(await zip)) throw new Error('ZIP download did not start'); }
        const pdf = d.page.waitForEvent('download', { timeout: 90000 }).catch(() => null);
        await commitClick(d, '#review-and-send button:has-text("Download combined PDF")');
        if (!DRY_NO_COMMIT) { await idle(); if (!(await pdf)) throw new Error('combined PDF download did not start'); }
      },
    },
    {
      say: 'The main button sends every ticked draft, and each row has its own Send. Sending makes the invoice final, adds it to the customer’s balance, posts revenue to the general ledger, and queues it for QuickBooks.',
      run: async (d) => {
        await d.hover('#review-and-send tbody tr button:has-text("Send")', 1200);
        await d.hover('#review-and-send .batch-action-bar .btn-primary', 3000);
      },
    },
    {
      say: 'With email on, the button reads Send and Email, and each customer gets the invoice email, with the P D F if chosen. An email failure is reported separately, so only the email needs retrying.',
      caption: 'With email on, the button reads Send & Email, and each customer gets the invoice email, with the PDF if chosen. An email failure is reported separately, so only the email needs retrying.',
      run: async (d) => { await d.hover('#review-and-send .batch-action-bar', 3500); },
    },

    // ───────────────────────── After the run ─────────────────────────
    {
      say: 'After the run, drafts are ordinary invoices. In Invoices, search for one and open it.',
      run: async (d) => {
        await d.nav('Invoices', '/invoices');
        const num = state.invB || 'INV-2026-01438';
        await d.type('[x-model="filters.search"]', num, { delay: 45 });
        await d.page.locator(`a:text-is("${num}")`).first().waitFor({ timeout: 20000 });
        await settle(d, 600);
        await d.click(`a:text-is("${num}")`, { nav: true });
      },
    },
    {
      say: 'While it is a draft you can fix it. Edit Line Items changes lines by hand. Regenerate from Lease rebuilds it from the lease as it is now, keeping the invoice number, which is what you want after correcting the lease.',
      run: async (d) => {
        await d.hover('a:has-text("Edit Line Items")', 1500);
        await d.click('button:has-text("Regenerate from Lease")', { commit: true, nav: !DRY_NO_COMMIT });
        await settle(d, 800);
      },
    },
    {
      say: 'If the invoice should not exist, void it with a reason. It stays on record but can no longer be edited or sent.',
      run: async (d) => {
        await d.click('.page-header-actions button.btn-danger:text-is("Void")');
        await d.type('textarea[x-model="voidReason"]', 'Rebilling in the next batch run after the rate correction', { delay: 25 });
        if (DRY_NO_COMMIT) { await d.hover('.modal button:has-text("Void Invoice")', 600); await d.click('.modal button:has-text("Cancel")'); return; }
        await d.click('.modal button:has-text("Void Invoice")', { commit: true });
        await d.page.waitForLoadState('load');
        await settle(d, 2200);
        await d.ready();
      },
    },
    {
      say: 'Back in Batch Invoicing, filter on Void. The lease shows void with the voided invoice number, ready to tick and bill again.',
      run: async (d) => {
        await d.goto('/invoices/batch');
        await waitElig(d);
        if (await d.exists('.batch-restored-note button:has-text("Start fresh")', 1000)) await d.click('.batch-restored-note button:has-text("Start fresh")');
        await setPeriodThisMonth(d);
        await d.select('select[aria-label="Filter by billing status"]', 'void');
        await settle(d, 700);
        const row = state.invB ? `.batch-lease-row:has(a.link:text-is("${state.invB}"))` : '.batch-lease-row:has(.badge:text-is("void"))';
        await d.highlight(row, 'Rebillable', 2600);
      },
    },

    // ───────────────────────── Checklist ─────────────────────────
    {
      say: 'The month-end checklist. Pick the month. Check the unbilled list and tick any void leases to rebill. Confirm delivery options and recipient emails.',
      run: async (d) => { await d.highlight('.card:has(.card-title:has-text("1. Billing Period"))', 'Checklist', 3500); },
    },
    {
      say: 'Preview and read every card, holding anything doubtful. Get approval if required, then generate. Review the drafts, download copies, and only then send. Finally, clear Needs Attention.',
      run: async (d) => { await d.hover(`${bar} button:has-text("Preview totals")`, 3500); },
    },
  ],
};
