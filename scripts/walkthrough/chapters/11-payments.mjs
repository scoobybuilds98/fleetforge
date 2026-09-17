/*
 * Chapter 11 — Payments & Credit Notes
 * Payments list + KPI tiles, a payment's detail page, recording a PARTIAL cheque payment
 * against an overdue Summit Carriers invoice (an overpayment's credit-split warning and confirm
 * dialog are shown and cancelled first), then credit notes: the list, issuing a goodwill credit and applying it to the
 * same invoice.
 *
 * Records created on commit:
 *   - one payment (cheque, CAD 2,000.00) on invoice INV-2026-00289 (Summit Carriers Ltd.)
 *   - one credit note (Goodwill, CAD 250.00) for Summit Carriers Ltd., applied to that invoice
 * Both reduce the invoice balance and Summit's outstanding balance on the dev DB.
 *
 * Helpers implemented inline via d.page: onPage() (skip post-commit scenes in plain dry runs,
 * where the commit click is not performed).
 */
const INVOICE = 'INV-2026-00289';
const onPage = (d, frag) => d.page.url().includes(frag);
// commit clicks: lib/recorder.mjs's dry-run skip branch calls an undefined `log`, which throws
// AFTER the click was (correctly) skipped. Swallow only that ReferenceError so plain dry runs stay clean.
const commitClick = async (d, sel, opts = {}) => {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
};

export default {
  title: 'Payments & Credit Notes',
  subtitle: 'Record money received against invoices, and manage credit owed back to customers.',
  start: '/dashboard',
  intro: 'In this chapter we will record customer payments, including a partial payment, read a payment’s detail page, and then issue and apply a credit note.',
  outro: 'That covers Payments and Credit Notes. Every payment and credit you apply lowers the invoice balance and the customer’s outstanding balance at the same time.',
  scenes: [
    {
      say: 'Open Payments from the left sidebar. This list is every payment received from customers, newest first.',
      run: async (d) => { await d.nav('Payments', '/payments'); },
    },
    {
      say: 'The tiles show cleared payments collected this month, the total still owed on sent invoices, how much of that is past due, and how many payments were recorded today.',
      run: async (d) => { await d.highlight('.stat-grid', 'Payment tiles', 3600); },
    },
    {
      say: 'Each row shows the payment number, customer, payment date, method, reference, amount and status. Search by reference or payment number, or filter by status.',
      run: async (d) => {
        await d.highlight('table thead', 'Payment list', 2000);
        await d.hover('[x-model="filters.q"]', 600);
        await d.hover('[x-model="filters.status"]', 600);
      },
    },
    {
      say: "Click a payment number to open it. Let's open one from Summit Carriers.",
      run: async (d) => { await d.click('table tbody tr:has-text("Summit Carriers") a[href*="payments/show"]', { nav: true }); },
    },
    {
      say: 'The tiles repeat the essentials: amount received, method, payment date and customer. Payment Details holds the reference, cheque or bank details, and the status.',
      run: async (d) => {
        await d.highlight('.stat-grid', 'Payment at a glance', 2600);
        await d.scroll(350);
      },
    },
    {
      say: 'Invoice Allocations shows exactly which invoice this money was applied to, how much, and what is left on that invoice afterwards.',
      run: async (d) => {
        await d.scroll(450);
        await d.highlight('.card:has(h3:has-text("Invoice Allocations"))', 'Where the money went', 3000);
      },
    },
    {
      say: 'Edit Notes changes only the reference and notes. Void or Remove takes the payment out and puts the balance back on the invoice, and it needs a reason for the audit trail.',
      run: async (d) => {
        await d.hover('button:has-text("Edit Notes / Reference")', 1200);
        await d.hover('button:has-text("Void / Remove Payment")', 1400);
      },
    },
    {
      say: 'Send Receipt, at the top, emails the customer a payment receipt. We will not send one in this training.',
      run: async (d) => {
        await d.scroll(-1200);
        await d.hover('button:has-text("Send Receipt")', 1800);
      },
    },
    {
      say: "Now let's record a payment. Go back to Payments and click Record Payment.",
      run: async (d) => {
        await d.nav('Payments', '/payments');
        await d.click('a:has-text("Record Payment")', { nav: true });
      },
    },
    {
      say: 'Search for the invoice being paid. Only sent, partially paid or overdue invoices can take a payment, because a draft is not owed yet.',
      run: async (d) => {
        await d.type('input[placeholder^="Search invoices"]', INVOICE, { delay: 40 });
        await d.wait(1800);
        await d.click(`.ff-picker-option:has-text("${INVOICE}")`);
      },
    },
    {
      say: 'The panel on the right confirms the invoice total, balance due, due date and status. The currency locks to the invoice’s currency.',
      run: async (d) => {
        await d.highlight('.card:has(h4:has-text("Selected Invoice"))', 'Selected invoice', 2600);
        await d.hover('#currency', 700);
      },
    },
    {
      say: 'Choose how the customer paid. Cheque adds fields for the cheque number and bank.',
      run: async (d) => {
        await d.select('#payment_method', 'check');
        await d.type('[x-model="form.check_number"]', '104522');
        await d.type('[x-model="form.bank_name"]', 'Royal Bank of Canada');
      },
    },
    {
      say: 'If you type more than the balance due, a warning shows how much would become account credit, and Record Payment asks you to confirm that split. We will cancel.',
      run: async (d) => {
        await d.type('#amount', '6000');
        await d.highlight('.alert-warning:has-text("Overpayment of")', 'Excess becomes account credit', 2000);
        await d.click('button[type="submit"]:has-text("Record Payment")');
        await d.wait(900);
        await d.hover('#ff-confirm-modal button:has-text("Record payment")', 900);
        await d.click('#ff-confirm-modal button:has-text("Cancel")');
      },
    },
    {
      say: 'A partial payment is fine. Enter what was actually received, here two thousand dollars, and set the payment date.',
      caption: 'A partial payment is fine. Enter what was actually received, here $2,000.00, and set the payment date.',
      run: async (d) => {
        await d.type('#amount', '2000.00');
        // The form only re-validates on submit; clear the stale over-balance message the way the
        // app itself does on invoice change (FF_Validate.clear) so the corrected amount reads cleanly.
        await d.page.evaluate(() => window.FF_Validate && FF_Validate.clear(document.querySelector('form')));
        await d.hover('#payment_date', 900);
      },
    },
    {
      say: 'Add the confirmation number and notes. Notes can be seen by the customer; internal notes are for staff only.',
      run: async (d) => {
        await d.type('[x-model="form.reference_number"]', 'CHQ-104522', { delay: 30 });
        await d.type('[x-model="form.notes"]', 'Partial payment received. Balance to follow.', { delay: 22 });
        await d.type('[x-model="form.internal_notes"]', 'Kyle confirmed the remainder will be paid by month end.', { delay: 22 });
      },
    },
    {
      say: 'Click Record Payment. In one step the invoice balance drops, the invoice becomes partially paid, the customer’s outstanding balance goes down, and the accounting entry is posted.',
      run: async (d) => {
        await d.hover('.card:has-text("You are recording")', 600);
        await commitClick(d, 'button[type="submit"]:has-text("Record Payment")', { nav: true });
        await d.wait(1200);
      },
    },
    {
      say: 'The new payment opens. Its allocation shows the amount applied and the balance still owing, with the invoice now marked partially paid.',
      run: async (d) => {
        if (!onPage(d, '/payments/show')) return; // plain dry run: the commit click was skipped
        await d.scroll(700);
        await d.highlight('.card:has(h3:has-text("Invoice Allocations"))', 'Partially paid', 3000);
      },
    },
    {
      say: 'When a customer has credit on account, it lives in Credit Notes. You can reach them from the customer’s Account Credit tile. Here is Summit Carriers.',
      run: async (d) => {
        await d.nav('Customers', '/customers');
        await d.type('[x-model="filters.search"]', 'Summit');
        await d.wait(1200);
        await d.click('table tbody a:has-text("Summit Carriers")', { nav: true });
        await d.highlight('a.stat-card:has-text("Account Credit")', 'Account credit', 2200);
      },
    },
    {
      say: 'Credit notes are created two ways. The system makes one automatically when a final bill’s refunds are bigger than its charges: the invoice bills zero and the difference becomes credit. Staff can also issue one by hand.',
      run: async (d) => {
        await d.click('a.stat-card:has-text("Account Credit")', { nav: true });
        await d.highlight('.stat-grid', 'Credit note tiles', 2600);
      },
    },
    {
      say: "Let's issue a goodwill credit. Click New Credit Note and pick the customer.",
      run: async (d) => {
        await d.click('a:has-text("New Credit Note"), button:has-text("New Credit Note")', { nav: true });
        await d.type('input.ff-picker-input[placeholder^="Search customers by"]', 'Summit', { delay: 40 });
        await d.wait(1800);
        await d.click('.ff-picker-option:has-text("Summit Carriers")');
      },
    },
    {
      say: 'Choose the source, enter the amount and currency, and write the reason. The reason appears on the customer’s statements, so keep it clear and professional.',
      run: async (d) => {
        await d.select('select[x-model="form.source"]', 'goodwill');
        await d.type('input[x-model="form.amount"]', '250.00');
        await d.type('textarea[x-model="form.reason"]', 'Goodwill credit for the two-day delay delivering the replacement flatbed.', { delay: 20 });
      },
    },
    {
      say: 'Optionally link the note to a lease, invoice or payment, set an expiry date, and add staff notes. Then click Issue Credit Note.',
      run: async (d) => {
        await d.hover('button:has-text("Link to Lease")', 700);
        await d.type('textarea[x-model="form.internal_notes"]', 'Approved by operations manager.', { delay: 22 });
        await commitClick(d, 'button:has-text("Issue Credit Note")', { nav: true });
        await d.wait(1200);
      },
    },
    {
      say: 'Issuing a credit does not change any invoice yet. It sits on the account, with its full amount remaining, until you apply it.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        await d.highlight('.stat-grid', 'Available credit', 2800);
      },
    },
    {
      say: 'The tiles show the total amount, what remains after anything already applied, the source, and how many invoices it has credited. Credit Note Details lists the customer, any linked lease or invoice, the currency, expiry and who created it.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        await d.hover('.stat-card:has-text("Remaining Balance")', 1200);
        await d.highlight('.card:has(.card-header:has-text("Credit Note Details"))', 'Details', 2400);
      },
    },
    {
      say: 'Void, at the top, cancels an unused or partly used credit and needs a reason. As the credit is applied, its status moves from Active to Partially Used, then Fully Used.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        await d.hover('button.btn-danger:has-text("Void")', 1500);
      },
    },
    {
      say: 'To use it, go to Apply to Invoice. Pick one of this customer’s open invoices, click Max to fill in the most you can apply, and click Apply Credit.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        const card = '.card:has(.card-header:has-text("Apply to Invoice"))';
        await d.type(`${card} input.ff-picker-input`, INVOICE, { delay: 40 });
        await d.page.locator(`.ff-picker-option:has-text("${INVOICE}")`).first().waitFor({ timeout: 15000 });
        await d.click(`.ff-picker-option:has-text("${INVOICE}")`);
        await d.click(`${card} button:has-text("Max")`);
        // The page reloads ~1.5 s after a successful apply; wait for that real navigation
        // (d.wait is shortened in dry runs). Times out harmlessly when the commit was skipped.
        const reloaded = d.page.waitForEvent('framenavigated', { timeout: 7000 }).catch(() => null);
        await commitClick(d, 'button:has-text("Apply Credit")');
        await d.wait(1200);
        if (await reloaded) { await d.ready(); await d.caption('To use it, go to Apply to Invoice. Pick one of this customer’s open invoices, click Max to fill in the most you can apply, and click Apply Credit.'); }
      },
    },
    {
      say: 'Application History now lists the invoice and the amount applied. The invoice balance and the customer’s outstanding balance both dropped, and Un-apply reverses it if it went on the wrong invoice.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        if (!(await d.exists('.card:has-text("Application History")', 4000))) return;
        await d.scroll(500);
        await d.highlight('.card:has-text("Application History")', 'Credit applied', 3000);
        if (await d.exists('button:has-text("Un-apply")', 1500)) await d.hover('button:has-text("Un-apply")', 1200);
      },
    },
    {
      say: 'One caution: the Account Credit payment method on the payment form is only a label. It does not use up a credit note, so always apply credit from the credit note itself.',
      run: async (d) => {
        if (!onPage(d, '/credit_notes/show')) return;
        await d.scroll(-600);
        await d.hover('.stat-card:has-text("Remaining Balance")', 1800);
      },
    },
    {
      say: 'Back on the Credit Notes list, each note shows its source, original amount, what remains, status and expiry. Filter by status, currency or source to find open credit.',
      run: async (d) => {
        await d.goto('/credit_notes');
        await d.hover('[x-model="filters.status"]', 700);
        await d.hover('[x-model="filters.source"]', 700);
        await d.highlight('table', 'Credit notes', 2200);
      },
    },
  ],
};
