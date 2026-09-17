/*
 * Chapter 09 — Invoices
 * The invoice list (aging tiles, tabs, statuses, bulk bar), a full invoice read-through on the Summit Carriers
 * demo invoice INV-2026-00042 (timeline, details, odometer, the holistic rate breakdown, line items, tax,
 * payments), a US-dollar invoice (INV-2026-00073) for currency, and the draft-only tools on INV-2026-00044:
 * Send (hover only), Edit Line Items (opened, then cancelled), Regenerate from Lease (hover only) and Void
 * (dialog opened, then cancelled). Credit notes and late fees are explained in narration.
 *
 * Creates NO records: every state-changing button is hovered or its dialog cancelled, because these are
 * shared demo invoices other chapters rely on.
 */
const tab = (name) => `[role=tab]:has-text("${name}")`;
const card = (title) => `.card:has(h3:text-is("${title}"))`;
const settle = (d, ms) => d.page.waitForTimeout(ms);

export default {
  title: 'Invoices',
  subtitle: 'What each invoice status means, how every charge is calculated, and what you can still change.',
  start: '/dashboard',
  intro: 'In this chapter we will read the invoice list, walk through an invoice line by line, and look at the tools for drafts: editing, regenerating, sending and voiding.',
  outro: 'That covers invoices. Next, we will generate a whole month of invoices at once with Batch Invoicing.',
  scenes: [
    // ── List ────────────────────────────────────────────────────────────
    {
      say: 'Open Invoices from the sidebar. Every invoice in the business is listed here, newest first.',
      run: async (d) => {
        await d.goto('/invoices');
        await d.page.locator('table tbody tr').first().waitFor({ timeout: 15000 });
      },
    },
    {
      say: 'The aging tiles total what customers owe on sent invoices: not yet due, and one to thirty, thirty-one to sixty, and over sixty days overdue. U S dollar balances are converted to Canadian dollars. Click a tile to list those invoices.',
      caption: 'The aging tiles total what customers owe on sent invoices: not yet due, 1–30, 31–60 and 60+ days overdue. USD balances are converted to CAD. Click a tile to list those invoices.',
      run: async (d) => { await d.highlight('.stat-grid', 'Accounts receivable aging', 4200); },
    },
    {
      say: 'Outstanding shows everything not yet paid, including drafts. Paid shows settled invoices. All adds a status filter.',
      run: async (d) => {
        await d.click(tab('Paid')); await settle(d, 1000);
        await d.click(tab('All')); await settle(d, 1000);
        await d.hover('select[aria-label="Filter by status"]', 800);
      },
    },
    {
      say: 'Draft means not issued yet. It can still be edited and the customer owes nothing. Sent means issued: the invoice is frozen and counts toward the customer’s balance.',
      run: async (d) => { await d.select('select[aria-label="Filter by status"]', 'draft'); await settle(d, 1200); },
    },
    {
      say: 'Partially paid and paid follow payments. Overdue is a sent invoice past its due date. Void cancels an invoice but keeps its number, and written off is a balance you have given up collecting.',
      run: async (d) => {
        await d.select('select[aria-label="Filter by status"]', 'overdue'); await settle(d, 1200);
        await d.select('select[aria-label="Filter by status"]', 'void'); await settle(d, 1200);
        await d.select('select[aria-label="Filter by status"]', '');
      },
    },
    {
      say: 'Each row shows the customer, lease and unit, billing period, issue and due dates, total and balance. Tick rows to void or delete several at once.',
      run: async (d) => {
        await d.highlight('table thead', 'Invoice list', 2000);
        await d.click('table tbody tr td input[type=checkbox]');
        await d.hover('.ff-bulk-bar', 1200);
        await d.click('.ff-bulk-btn-clear');
      },
    },

    // ── Reading an invoice ──────────────────────────────────────────────
    {
      say: "Search for an invoice number and open it. This is a monthly invoice for Summit Carriers.",
      run: async (d) => {
        await d.type('[x-model="filters.search"]', 'INV-2026-00042');
        await d.page.locator('table tbody a:has-text("INV-2026-00042")').first().waitFor({ timeout: 15000 });
        await d.click('table tbody a:has-text("INV-2026-00042")', { nav: true });
      },
    },
    {
      say: 'The timeline shows where the invoice is: draft, sent, paid. The tiles give the dates, total, amount paid and balance, and how many days it is overdue.',
      run: async (d) => {
        await d.highlight('.invoice-status-timeline', 'Draft → Sent → Paid', 1800);
        await d.highlight('.stat-grid', 'Dates & balance', 2600);
      },
    },
    {
      say: 'If QuickBooks is connected, this card shows whether the invoice has been pushed there.',
      run: async (d) => { if (await d.exists('text=QuickBooks Sync', 1500)) await d.hover('text=QuickBooks Sync', 1800); },
    },
    {
      say: 'Invoice Details show the billing period and days, the billing type, such as a full month or a partial first month, the rate method, currency and P O number.',
      caption: 'Invoice Details show the billing period and days, the billing type (e.g. full month or partial first month), the rate method, currency and PO number.',
      run: async (d) => { await d.scroll(450); await d.highlight(card('Invoice Details'), 'Invoice details', 3000); },
    },
    {
      say: 'For a lease that tracks mileage, the odometer card shows the readings at the start and end of the period and the distance billed.',
      run: async (d) => { await d.scroll(350); await d.hover('text=Period Distance', 1800); },
    },
    {
      say: 'The Rate Calculation Breakdown explains the rental charge. FleetForge prices the whole lease from its start date through this period, then subtracts what earlier invoices already billed. That is why earlier months appear here.',
      run: async (d) => {
        await d.scroll(350);
        await d.highlight('.card:has(h3:text-is("Rate Calculation Breakdown"))', 'Whole-lease calculation', 3800);
      },
    },
    {
      say: 'Line Items list each charge with its period, quantity, unit price, tax and amount. Mileage is distance times the rate. Click Show calculation to see how a rental line was worked out.',
      run: async (d) => {
        await d.scroll(350);
        await d.highlight(card('Line Items'), 'Line items', 2400);
        await d.click('text=Show calculation ▸');
        await d.wait(1600);
      },
    },
    {
      say: 'The Financial Summary adds G S T and P S T. Tax rates are frozen on the invoice when it is created, so a later rate change never alters an existing invoice.',
      caption: 'The Financial Summary adds GST and PST. Tax rates are frozen on the invoice when it is created, so a later rate change never alters an existing invoice.',
      run: async (d) => { await d.scroll(450); await d.highlight(card('Financial Summary'), 'Tax & total', 3000); },
    },
    {
      say: 'Payment History lists payments applied to this invoice, and Record Payment takes you to the payment form with this invoice selected.',
      run: async (d) => {
        await d.scroll(500);
        await d.hover(`${card('Payment History')} :is(a,button):has-text("Record Payment")`, 1800);
      },
    },
    {
      say: 'When a credit note is applied, a Credits Applied section appears here with a link to it. A customer’s credit notes are also reached from their account page.',
      run: async (d) => { await d.scroll(-3000); await d.hover('.page-header a:has-text("Summit Carriers")', 1800); },
    },
    {
      say: 'Late fees are added automatically. When a late fee rule applies, a nightly job raises a separate late-fee invoice for an overdue invoice once its grace period has passed, and only once per invoice.',
      run: async (d) => { await d.highlight('.stat-grid .stat-card:nth-child(2)', 'Overdue', 2600); },
    },

    // ── Currency ────────────────────────────────────────────────────────
    {
      say: 'A U S dollar customer is invoiced in U S dollars. The header marks the currency and shows the Canadian dollar equivalent.',
      caption: 'A USD customer is invoiced in USD. The header marks the currency and shows the CAD equivalent.',
      run: async (d) => {
        await d.goto('/invoices/show?id=73');
        await d.highlight('.stat-grid', 'USD with CAD equivalent', 2400);
      },
    },
    {
      say: 'The summary shows the exchange rate used. It is fixed on the invoice, so reports convert it to Canadian dollars at that same rate.',
      caption: 'The summary shows the exchange rate used. It is fixed on the invoice, so reports convert it to CAD at that same rate.',
      run: async (d) => {
        await d.page.locator('text=Effective rate').first().scrollIntoViewIfNeeded();
        await d.wait(500);
        await d.highlight(card('Financial Summary'), 'Exchange rate', 2800);
      },
    },

    // ── Draft tools ─────────────────────────────────────────────────────
    {
      say: 'Now a draft invoice. Drafts have extra tools in the toolbar: Edit Line Items, Regenerate from Lease, Void and Delete.',
      run: async (d) => {
        await d.goto('/invoices/show?id=44');
        await d.highlight('.page-header-actions', 'Draft toolbar', 2600);
      },
    },
    {
      say: 'Print and Generate P D F produce a copy of the invoice. Email Invoice opens a message to the customer with the P D F attached.',
      caption: 'Print and Generate PDF produce a copy of the invoice. Email Invoice opens a message to the customer with the PDF attached.',
      run: async (d) => {
        await d.hover('.page-header-actions button:has-text("Print")', 700);
        await d.hover('.page-header-actions button:has-text("Generate PDF")', 900);
        await d.hover('.page-header-actions button:has-text("Email Invoice")', 1100);
      },
    },
    {
      say: 'Send Invoice moves a draft to Sent. That freezes it, adds it to the customer’s outstanding balance, posts the revenue to accounting, and queues it for QuickBooks. Sending does not email anything by itself.',
      run: async (d) => { await d.hover('.page-header-actions button:has-text("Send Invoice")', 3500); },
    },
    {
      say: 'Edit Line Items lets you change, add or remove lines on a draft. The server recalculates tax and totals when you save.',
      run: async (d) => {
        await d.click('.page-header-actions a:has-text("Edit Line Items")', { nav: true });
        await d.highlight('table thead', 'Type · description · qty · price · amount · tax · credit', 2200);
      },
    },
    {
      say: 'Tick Credit for a line that reduces the invoice, and untick Tax for a non-taxable charge. We will cancel without saving.',
      run: async (d) => {
        await d.hover('table tbody tr input[x-model="ln.is_credit"]', 900);
        await d.hover('button:has-text("+ Add line")', 800);
        await d.click('a.btn:has-text("Cancel")', { nav: true });
      },
    },
    {
      say: 'Regenerate from Lease rebuilds the draft from the lease’s current dates and rates and keeps the same invoice number. Use it after correcting the lease, then check the new total.',
      run: async (d) => { await d.hover('.page-header-actions button:has-text("Regenerate from Lease")', 3200); },
    },
    {
      say: 'Void cancels a draft or sent invoice and needs a reason. The number is kept, a sent invoice comes off the customer’s balance, and that month can be billed again. A paid invoice cannot be voided; issue a credit note instead.',
      run: async (d) => {
        await d.click('.page-header-actions button:has-text("Void")');
        await d.type('.modal textarea[x-model="voidReason"]', 'Billed for the wrong period.', { delay: 30 });
        await d.wait(1200);
        await d.click('.modal-footer button:has-text("Cancel")');
      },
    },
    {
      say: 'Once an invoice is sent it cannot be edited. Corrections go through a credit note or a new adjustment invoice, so the customer’s records always match what they received.',
      run: async (d) => { await d.highlight('.invoice-status-timeline', 'Sent invoices are frozen', 2600); },
    },
  ],
};
