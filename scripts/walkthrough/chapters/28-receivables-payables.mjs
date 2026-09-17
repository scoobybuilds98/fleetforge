/*
 * Chapter 28 — Accounting: Receivables & Payables
 * AR aging (expand a customer, reconciliation check), customer statements, collections
 * (notes / promise to pay / dunning letters / write-offs — Generate & Send is HOVERED ONLY),
 * customer deposits; then payables: the bills register, entering and approving a vendor
 * bill, the Record Payment dialog (opened and cancelled), AP payments, AP aging and
 * vendor credits.
 *
 * Records created on commit (WT_COMMIT=1 or a real recording):
 *   - one APPROVED vendor bill for Bridgestone Tire Canada, vendor invoice # BTC-58213,
 *     dated today, due in 30 days: 1 line on 6030 Tires, 4 × $385.00 = $1,540.00,
 *     GST $77.00, PST $107.80, total $1,724.80.
 *     Approval posts a system JE: DR 6030 Tires 1,647.80 (cost + non-recoverable PST),
 *     DR GST/HST Receivable (ITC) 77.00, CR 2010 Accounts Payable 1,724.80.
 *     (Void it from the bills list to undo.) No payment is recorded.
 *
 * Never clicked: Generate & Send (dunning letter endpoint emails the customer and is NOT
 * covered by the recorder's network block), Generate PDF, Record Payment.
 */

const acctNav = (label) => `.acc-topnav button:has-text("${label}"), .acc-topnav a:has-text("${label}")`;
const menuItem = (label) => `.acc-topnav a:has-text("${label}")`;
const openMenu = async (d, group, label) => {
  await d.click(acctNav(group));
  await d.wait(700);
  await d.click(menuItem(label), { nav: true });
};

/** commit:true clicks in a plain --dry run hit a recorder bug (`log is not defined` in the
 *  skip branch) after the target was already located — treat that as the intended skip. */
async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) {
    if (!/log is not defined/.test(e.message)) throw e;
    d.log('(dry) skipped commit click');
  }
}

/** Select an <option> whose visible text starts with `prefix` (labels carry live balances). */
async function selectByPrefix(d, sel, prefix) {
  const value = await d.page.locator(sel).first().evaluate((el, p) => [...el.options].find((o) => o.textContent.trim().startsWith(p))?.value ?? '', prefix);
  if (!value) throw new Error(`no option starting "${prefix}" in ${sel}`);
  await d.select(sel, value);
}

/** Date inputs: set the value directly (typing digits depends on the browser locale). */
async function fillDate(d, sel, iso) {
  await d.hover(sel, 300);
  await d.page.locator(sel).filter({ visible: true }).first().fill(iso);
  await d.wait(300);
}

const BILL_REF = 'BTC-58213';
const billRow = `table tbody tr:has-text("${BILL_REF}")`;
const dueIso = () => new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10);

export default {
  title: 'Accounting: Receivables & Payables',
  subtitle: 'Who owes you, who you owe — aging, collections, vendor bills and payments.',
  start: '/accounting/dashboard',
  intro: 'In this chapter we will work through receivables, meaning money customers owe us, and payables, meaning money we owe suppliers. We will also enter and approve a vendor bill.',
  outro: 'That covers receivables and payables. Next, banking, fixed assets, tax and year-end.',
  scenes: [
    {
      say: 'Open Receivables in the accounting menu and choose A R Aging. It lists every sent invoice that still has a balance, grouped by customer.',
      caption: 'Open Receivables in the accounting menu and choose AR Aging. It lists every sent invoice that still has a balance, grouped by customer.',
      run: async (d) => {
        await d.click(acctNav('Receivables'));
        await d.wait(1200);
        await d.click(menuItem('AR Aging'), { nav: true });
        await d.wait(2500);
      },
    },
    {
      say: 'Balances fall into buckets by days past due as of the chosen date: current, one to thirty, thirty-one to sixty, sixty-one to ninety, and over ninety days.',
      caption: 'Balances fall into buckets by days past due as of the chosen date: current, 1–30, 31–60, 61–90 and 90+ days.',
      run: async (d) => {
        await d.highlight('.stat-grid, .card:has-text("TOTAL AR")', 'Aging buckets', 3000);
        await d.hover('[x-model="asOfDate"]', 800);
      },
    },
    {
      say: 'Click a customer to expand the invoices behind their total, with each invoice’s due date and days past due. Work the oldest buckets first.',
      run: async (d) => {
        await d.click('table tbody tr:has-text("Summit Carriers")');
        await d.wait(1800);
        await d.scroll(400);
        await d.wait(800);
        await d.scroll(-400);
      },
    },
    {
      say: 'Check Reconciliation compares this list with the receivables control account in the general ledger. A difference means something reached one side but not the other.',
      run: async (d) => {
        await d.click('button:has-text("Check Reconciliation")');
        await d.wait(2200);
      },
    },
    {
      say: 'Statements produces a P D F statement for one customer over a date range, showing invoices, payments and the running balance.',
      caption: 'Statements produces a PDF statement for one customer over a date range, showing invoices, payments and the running balance.',
      run: async (d) => {
        await openMenu(d, 'Receivables', 'Statements');
        await selectByPrefix(d, '[x-model="customerId"]', 'Summit Carriers');
        await d.hover('button:has-text("Generate PDF")', 1400);
      },
    },
    {
      say: 'Collections is where you manage a customer who is behind. Pick the customer; the four tabs keep the whole history in one place.',
      run: async (d) => {
        await openMenu(d, 'Receivables', 'Collections');
        await selectByPrefix(d, '[x-model="customerId"]', 'Summit Carriers');
        await d.wait(1500);
      },
    },
    {
      say: 'Collection Notes records every call or email: how you contacted them and the outcome, such as left a message or payment promised.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Collection Notes")');
        await d.wait(1200);
        await d.scroll(300);
        await d.scroll(-300);
      },
    },
    {
      say: 'Promise to Pay logs an amount and date the customer committed to. Mark each promise kept or broken, so broken promises stand out.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Promise to Pay")');
        await d.wait(1500);
      },
    },
    {
      say: 'Dunning Letters escalate from a thirty-day reminder to a final notice. Generate and Send creates the P D F and, when the method is email, sends it to the customer straight away. Only use it when you mean to.',
      caption: 'Dunning Letters escalate from a 30-day reminder to a final notice. Generate & Send creates the PDF and, with the email method, sends it to the customer straight away — only use it when you mean to.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Dunning Letters")');
        await d.wait(1200);
        await d.hover('[x-model="dunningForm.letter_type"]', 1000);
        await d.hover('button:has-text("Generate & Send")', 2000);
      },
    },
    {
      say: 'Write-offs lists invoices written off as bad debt. A write-off moves the balance from receivables to bad debt expense, and a later recovery is recorded against it.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Write-offs")');
        await d.wait(1500);
      },
    },
    {
      say: 'Deposits tracks money held from customers, such as security deposits, until it is applied to an invoice or refunded.',
      run: async (d) => {
        await openMenu(d, 'Receivables', 'Deposits');
        await d.wait(1200);
        await d.hover('button:has-text("New Deposit")', 1200);
      },
    },
    {
      say: 'Now payables. Open Payables and choose Bills. The tiles show the total owed to suppliers, what is due this week, and what is overdue.',
      run: async (d) => {
        await openMenu(d, 'Payables', 'Bills');
        await d.wait(1800);
        await d.highlight('.stat-grid, .card:has-text("OUTSTANDING AP")', 'What we owe', 2400);
      },
    },
    {
      say: 'A bill starts as a draft, becomes approved, then partial or paid as payments are recorded. Void cancels a bill that was entered by mistake.',
      run: async (d) => {
        await d.click('button:text-is("Approved")');
        await d.wait(1400);
        await d.click('button:text-is("All")');
        await d.wait(1200);
      },
    },
    {
      say: "Let's enter a supplier invoice. Click New Bill, choose the vendor, and keep today as the bill date.",
      run: async (d) => {
        await d.click('button:has-text("+ New Bill")');
        await d.wait(900);
        await d.select('select[x-model="form.vendor_id"]', { label: 'Bridgestone Tire Canada' });
        await d.highlight('input[x-model="form.bill_date"]', 'Bill date', 1000);
      },
    },
    {
      say: 'Set the due date from the supplier’s terms, here thirty days, and type the supplier’s own invoice number. It is limited to twenty-one characters to match QuickBooks.',
      caption: 'Set the due date from the supplier’s terms (here 30 days) and type the supplier’s own invoice number — limited to 21 characters to match QuickBooks.',
      run: async (d) => {
        await fillDate(d, 'input[x-model="form.due_date"]', dueIso());
        await d.type('input[x-model="form.vendor_bill_number"]', BILL_REF);
        await d.type('input[x-model="form.notes"]', 'Steer tires for TR-7012 — PM service', { delay: 25 });
      },
    },
    {
      say: 'Each line charges a general ledger account. This one goes to Tires: four steer tires at three hundred eighty-five dollars each.',
      caption: 'Each line charges a GL account. This one goes to 6030 Tires: four steer tires at $385.00 each.',
      run: async (d) => {
        await d.select('.modal-overlay select[x-model="line.account_id"]', { label: '6030 — Tires' });
        await d.type('.modal-overlay input[x-model="line.description"]', '4 × steer tires 295/75R22.5 — TR-7012', { delay: 25 });
        await d.type('.modal-overlay input[x-model="line.quantity"]', '4');
        await d.type('.modal-overlay input[x-model="line.unit_cost"]', '385.00');
      },
    },
    {
      say: 'Enter the G S T and P S T exactly as printed on the supplier invoice. The subtotal, tax and total update underneath.',
      caption: 'Enter the GST and PST exactly as printed on the supplier invoice. The subtotal, tax and total update underneath.',
      run: async (d) => {
        await d.type('.modal-overlay input[x-model="line.tax_gst_amount"]', '77.00');
        await d.type('.modal-overlay input[x-model="line.tax_pst_amount"]', '107.80');
        await d.wait(400);
        await d.highlight('.modal-overlay tfoot', 'Subtotal, tax, total', 2000);
      },
    },
    {
      say: 'Save as Draft keeps it for someone else to approve. Save and Approve posts it now: the expense plus non-recoverable P S T is debited, G S T goes to input tax credits, and Accounts Payable is credited.',
      caption: 'Save as Draft keeps it for approval. Save & Approve posts it now: expense plus non-recoverable PST is debited, GST goes to input tax credits, and Accounts Payable is credited.',
      run: async (d) => {
        await d.hover('.modal-overlay button:has-text("Save as Draft")', 1200);
        await commitClick(d, '.modal-overlay button:has-text("Save & Approve")');
        // Real (uncapped) wait: let the modal's leave transition and the list reload finish.
        await d.page.waitForTimeout(2000);
        if (await d.exists('.modal-overlay button:has-text("Save & Approve")', 500)) await d.click('.modal-overlay button:has-text("Cancel")');
      },
    },
    {
      say: 'The bill now appears as approved with its full balance due. Click the eye icon to review the lines and any payments.',
      run: async (d) => {
        const row = (await d.exists(billRow, 3000)) ? billRow : 'table tbody tr:has(button[title="Pay"])';
        await d.highlight(row, 'Approved bill', 1600);
        await d.click(`${row} button[title="View"]`);
        await d.wait(1800);
        await d.click('.modal-overlay button:has-text("Close")');
      },
    },
    {
      say: 'When you pay the supplier, click the dollar button. Enter the amount, date, method and the bank account the money leaves from. A cheque also needs its cheque number.',
      run: async (d) => {
        const row = (await d.exists(billRow, 1500)) ? billRow : 'table tbody tr:has(button[title="Pay"])';
        await d.click(`${row} button[title="Pay"]`);
        await d.wait(1000);
        await d.highlight('.modal-overlay:has-text("Record Payment") .card', 'Record Payment', 2600);
      },
    },
    {
      say: 'Record Payment debits Accounts Payable and credits that bank account, and reduces the bill’s balance. We will cancel, since we have not actually paid it.',
      run: async (d) => {
        await d.hover('.modal-overlay button:has-text("Record Payment")', 1400);
        await d.click('.modal-overlay:has-text("Record Payment") button:has-text("Cancel")');
      },
    },
    {
      say: 'Payments lists every supplier payment with the bank account used, the method, and the journal entry it posted. Filter by vendor, status or date.',
      run: async (d) => {
        await openMenu(d, 'Payables', 'Payments');
        await d.wait(1200);
        await d.highlight('table thead', 'Payment register', 2400);
      },
    },
    {
      say: 'A P Aging mirrors receivables aging for the bills you owe, by vendor and days past due. Check Reconciliation compares it to the payables account in the ledger.',
      caption: 'AP Aging mirrors AR aging for the bills you owe, by vendor and days past due. Check Reconciliation compares it to the AP account in the ledger.',
      run: async (d) => {
        await openMenu(d, 'Payables', 'AP Aging');
        await d.wait(2000);
        await d.click('button:has-text("Check Reconciliation")');
        await d.wait(1800);
      },
    },
    {
      say: 'Vendor Credits records credits a supplier gives you, for example for returned parts, and applies them against future bills.',
      run: async (d) => {
        await openMenu(d, 'Payables', 'Vendor Credits');
        await d.wait(1200);
        await d.hover('button:has-text("New Credit")', 1400);
      },
    },
  ],
};
