/*
 * Chapter 26 — Accounting: Dashboard & General Ledger
 * Accounting dashboard, the grouped accounting menu, chart of accounts, the account
 * ledger, journal entries (list, detail, and creating a balanced manual entry),
 * recurring journal-entry templates, and accounting periods (close / lock — toured,
 * never executed: the Close Period confirm is opened and cancelled).
 *
 * Records created on commit (WT_COMMIT=1 or a real recording):
 *   - one POSTED manual journal entry dated today:
 *     "Accrue September yard utilities — BC Hydro", ref BCH-0926
 *     DR 6060 Utilities 1,240.00 / CR 2020 Accrued Liabilities 1,240.00
 *     (reverse it from the Posted tab to undo).
 *
 * Inline helpers (implemented via d.page, no recorder changes):
 *   - coaRender(): the Chart of Accounts page only computes its row count inside the
 *     tree/flat template it gates on that count (starts at 0 → "No accounts found"
 *     forever). The helper nudges the Alpine count so the real, loaded rows render.
 *   - warnIfUndefinedLabels(): the New Journal Entry account dropdown builds labels from
 *     acct.account_code/account_name but the API returns code/name, so every option
 *     reads "undefined — undefined". Selection by account id still works; the helper
 *     logs a loud warning so nobody records the chapter before that is fixed.
 */

/** commit:true clicks in a plain --dry run hit a recorder bug (`log is not defined` in the
 *  skip branch). The target has already been located and pointed at by then, so treat that
 *  specific error as the intended dry-run skip. */
async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) {
    if (!/log is not defined/.test(e.message)) throw e;
    d.log('(dry) skipped commit click');
  }
}

const acctNav = (label) => `.acc-topnav button:has-text("${label}"), .acc-topnav a:has-text("${label}")`;
const menuItem = (label) => `.acc-topnav a:has-text("${label}")`;

async function coaRender(d) {
  await d.page.waitForFunction(() => {
    const el = [...document.querySelectorAll('[x-data]')].find((e) => { try { return 'treeAccounts' in window.Alpine.$data(e); } catch { return false; } });
    return el && window.Alpine.$data(el).loading === false;
  }, null, { timeout: 20000 }).catch(() => {});
  await d.page.evaluate(() => {
    for (const el of document.querySelectorAll('[x-data]')) {
      try {
        const s = window.Alpine.$data(el);
        if ('treeAccounts' in s && s.filteredCount === 0 && s.accounts.length) s.filteredCount = s.accounts.length;
      } catch { /* not the CoA component */ }
    }
  });
  await d.wait(600);
}

async function warnIfUndefinedLabels(d) {
  const bad = await d.page.evaluate(() => [...document.querySelectorAll('select[x-model="line.account_id"] option')].some((o) => /undefined/.test(o.textContent)));
  if (bad) d.log('WARN: New Journal Entry account dropdown shows "undefined — undefined" labels (app bug: account_code/account_name vs code/name). Fix before recording.');
}

export default {
  title: 'Accounting: Dashboard & General Ledger',
  subtitle: 'The double-entry books behind every invoice, payment and bill — accounts, journal entries and periods.',
  start: '/dashboard',
  intro: 'In this chapter we will tour the accounting module: the dashboard, the chart of accounts, the general ledger, journal entries including a manual entry, recurring entries, and accounting periods.',
  outro: 'That covers the accounting dashboard and general ledger. Next, the financial reports and budgets built on top of it.',
  scenes: [
    {
      say: 'Open Accounting from the left sidebar. Fleet Forge keeps a full double-entry set of books, fed automatically by invoices, payments, credit notes, bills and depreciation.',
      caption: 'Open Accounting from the left sidebar. FleetForge keeps a full double-entry set of books, fed automatically by invoices, payments, credit notes, bills and depreciation.',
      run: async (d) => { await d.nav('Accounting', '/accounting/dashboard'); },
    },
    {
      say: 'The tiles show the current open period, revenue, expenses and net income for the year from posted journal entries, and the receivable and payable control account balances.',
      run: async (d) => { await d.highlight('.stat-grid', 'Books at a glance', 3600); },
    },
    {
      say: 'The period bar shows each month of the year as open, closed or locked. Below it are the most recent journal entries.',
      run: async (d) => {
        await d.highlight('.card:has(h3:has-text("Fiscal Period Status"))', 'Month-by-month status', 2600);
        await d.scroll(450);
      },
    },
    {
      say: 'Alerts flag problems early: for example when the receivables account in the ledger does not match the open invoices, or when past months are still open.',
      caption: 'Alerts flag problems early: e.g. when the AR account in the ledger does not match open invoices, or when past months are still open.',
      run: async (d) => {
        await d.highlight('.card:has(h3:has-text("Alerts"))', 'Alerts', 3000);
        await d.scroll(-450);
      },
    },
    {
      say: 'Every accounting page carries this menu bar. Items with an arrow open a group; for example General Ledger holds the chart of accounts, journal entries and the ledger itself.',
      run: async (d) => {
        await d.highlight('.acc-topnav', 'Accounting menu', 2000);
        await d.click(acctNav('General Ledger'));
        await d.wait(1400);
      },
    },
    {
      say: "Let's open the Chart of Accounts. This is the list of every account the business posts to, grouped into assets, liabilities, equity, revenue and expenses.",
      caption: "Let's open the Chart of Accounts: every account the business posts to, grouped into assets, liabilities, equity, revenue and expenses.",
      run: async (d) => {
        await d.click(menuItem('Chart of Accounts'), { nav: true });
        await coaRender(d);
      },
    },
    {
      say: 'Header accounts group the accounts beneath them. Each row shows the account type and its normal balance, debit or credit. Use search or the type filter to narrow the list.',
      run: async (d) => {
        await d.scroll(500);
        await d.scroll(-500);
        await d.type('[x-model="filters.search"]', 'Cash');
        await d.wait(1200);
        await d.type('[x-model="filters.search"]', '');
        await coaRender(d);
      },
    },
    {
      say: 'New Account adds an account. Only add accounts your accountant has agreed on, because reports and the QuickBooks mapping rely on this list.',
      caption: 'New Account adds an account. Only add accounts your accountant has agreed on — reports and the QuickBooks mapping rely on this list.',
      run: async (d) => { await d.hover('button:has-text("New Account")', 1600); },
    },
    {
      say: 'The Ledger shows every posted line for one account over a date range, with a running balance. Pick an account, such as the operating bank account.',
      run: async (d) => {
        await d.click(acctNav('General Ledger'));
        await d.wait(700);
        await d.click(menuItem('Ledger'), { nav: true });
        await d.select('[x-model="filters.account_id"]', { label: '1010 — Cash — Operating Account (CAD)' });
        await d.wait(2500);
      },
    },
    {
      say: 'Opening and closing balances sit at the top, and each line links back to the journal entry that created it.',
      run: async (d) => { await d.scroll(500); await d.wait(900); await d.scroll(-500); },
    },
    {
      say: 'Journal Entries is the register of every entry. Most are system entries posted automatically; the tabs filter by drafts awaiting review, posted, and reversed.',
      run: async (d) => {
        await d.click(acctNav('General Ledger'));
        await d.wait(700);
        await d.click(menuItem('Journal Entries'), { nav: true });
        await d.wait(1200);
        await d.click('button:has-text("Posted")');
        await d.wait(2200);
      },
    },
    {
      say: 'Every entry balances: total debits always equal total credits. Click View to see the individual lines.',
      run: async (d) => {
        await d.highlight('table thead', 'Debit and credit totals', 2000);
        await d.click('table tbody tr >> nth=0 >> button:has-text("View")');
        await d.wait(1800);
      },
    },
    {
      say: "Entries are never edited once posted. To correct one, use Reverse Entry: it posts a mirror entry with debits and credits swapped, so the history stays intact.",
      run: async (d) => {
        await d.hover('button:has-text("Reverse Entry")', 1800);
        await d.click('.modal button:has-text("Close")');
      },
    },
    {
      say: "Now let's record a manual entry. Click New Journal Entry. The date decides which accounting period it lands in, and that period must be open.",
      run: async (d) => {
        await d.click('button:has-text("New Journal Entry")');
        await d.wait(900);
        await d.highlight('[x-model="form.entry_date"]', 'Entry date → period', 1600);
      },
    },
    {
      say: 'Manual is the everyday type. Adjusting, reclassifying and prior-period entries always start as drafts and go through review before they post.',
      run: async (d) => {
        await d.hover('[x-model="form.entry_type"]', 1800);
      },
    },
    {
      say: 'Describe the purpose clearly and add a reference, such as the supplier invoice number. We are accruing this month’s yard hydro bill.',
      caption: 'Describe the purpose clearly and add a reference, such as the supplier invoice number. We are accruing this month’s yard hydro bill.',
      run: async (d) => {
        await d.type('[x-model="form.description"]', 'Accrue September yard utilities — BC Hydro', { delay: 28 });
        await d.type('[x-model="form.reference"]', 'BCH-0926');
      },
    },
    {
      say: 'Line one debits Utilities expense for twelve hundred and forty dollars.',
      caption: 'Line one debits 6060 Utilities for $1,240.00.',
      run: async (d) => {
        await d.select('select[x-model="line.account_id"] >> nth=0', '60');
        await warnIfUndefinedLabels(d);
        await d.type('input[x-model="line.description"] >> nth=0', 'September hydro — Surrey yard');
        await d.type('input[x-model="line.debit"] >> nth=0', '1240.00');
      },
    },
    {
      say: 'Line two credits Accrued Liabilities for the same amount. The totals update as you type, and Save stays disabled until the entry balances.',
      caption: 'Line two credits 2020 Accrued Liabilities for the same amount. Totals update as you type; Save stays disabled until the entry balances.',
      run: async (d) => {
        await d.select('select[x-model="line.account_id"] >> nth=1', '22');
        await d.type('input[x-model="line.description"] >> nth=1', 'Hydro accrual — invoice to follow');
        await d.type('input[x-model="line.credit"] >> nth=1', '1240.00');
        await d.wait(500);
        await d.highlight('.modal tfoot, .modal table', 'Balanced', 1800);
      },
    },
    {
      say: 'Leave Post Immediately unticked to save a draft for someone to review, or tick it to post straight to the ledger. We will post it now.',
      run: async (d) => {
        await d.click('[x-model="form.post_immediately"]');
        await d.wait(600);
      },
    },
    {
      say: 'Click Save Journal Entry. The entry gets the next J E number and immediately updates both account balances.',
      caption: 'Click Save Journal Entry. The entry gets the next JE number and immediately updates both account balances.',
      run: async (d) => {
        await commitClick(d, 'button:has-text("Save Journal Entry")');
        // Real (uncapped) waits: dry runs shrink d.wait() to 100 ms, but the modal's leave
        // transition and the list reload must finish before we touch the DOM again.
        await d.page.waitForTimeout(1500);
        if (await d.exists('.modal button:has-text("Save Journal Entry")', 500)) await d.click('.modal button:has-text("Cancel")');
        await d.click('button:has-text("Posted")');
        await d.page.waitForTimeout(2500);
        await d.highlight('table tbody tr >> nth=0', 'Our new entry', 2200);
      },
    },
    {
      say: 'Recurring J Es are templates for entries that repeat, such as insurance, software or yard rent. A scheduled job posts each one on its due date.',
      caption: 'Recurring JEs are templates for entries that repeat, such as insurance, software or yard rent. A scheduled job posts each one on its due date.',
      run: async (d) => {
        await d.click(acctNav('Recurring JEs'), { nav: true });
        await d.highlight('table', 'Recurring templates', 2400);
      },
    },
    {
      say: 'The Auto-post column matters: when it says draft, the job creates a draft entry for review instead of posting it. Pause stops a template without deleting it.',
      run: async (d) => {
        await d.highlight('table thead', 'Frequency, next post, auto-post', 2200);
        await d.hover('table tbody tr >> nth=0 >> button:has-text("Pause")', 1200);
      },
    },
    {
      say: 'Open a template to see its lines and posting history. New Template builds one the same way as a journal entry, plus a frequency and start date.',
      run: async (d) => {
        await d.click('a:has-text("Fleet insurance premium")', { nav: true });
        await d.wait(1200);
        await d.highlight('table', 'Template lines', 2000);
        await d.hover('button:has-text("Post Now")', 900);
      },
    },
    {
      say: 'Finally, Periods. Each month is an accounting period. Pick the year to see its twelve months and how many are open.',
      run: async (d) => {
        await d.nav('Periods', '/accounting/periods');
        await d.select('[x-model="year"]', '2026');
        await d.wait(1800);
      },
    },
    {
      say: 'Once a month is reconciled and reviewed, Close Period stops new entries from posting into it. Automatic entries dated in a closed month move forward to the next open period.',
      run: async (d) => {
        await d.click('.card:has(h3:has-text("December 2026")) button:has-text("Close Period")');
        await d.wait(900);
        await d.highlight('.modal:has(#ff-confirm-title)', 'Confirm before closing', 2600);
        await d.click('.modal:has(#ff-confirm-title) button:has-text("Cancel")');
      },
    },
    {
      say: 'Lock Period appears on closed months. Locking is permanent and cannot be undone, so only lock a month after the accountant has signed off.',
      run: async (d) => {
        await d.hover('.card button:has-text("Lock Period")', 2200);
      },
    },
  ],
};
