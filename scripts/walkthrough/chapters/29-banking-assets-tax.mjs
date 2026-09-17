/*
 * Chapter 29 — Accounting: Banking, Fixed Assets, Tax & Year-End
 * Bank accounts, bank transactions, bank reconciliation; the fixed-asset register
 * (asset detail + depreciation forecast), payoff report, depreciation runs, CCA
 * Schedule 8; GST/HST & PST filing (tax dashboard, GST34 generator, remittances);
 * FX revaluation; the year-end close console; accounting settings.
 *
 * Records created: none. This chapter is a TOUR. Every state-changing control is
 * hovered only: Import CSV, Transfer, Add Account, Start (reconciliation), New Asset,
 * Dispose/Impair, Generate Preview, Reverse, Compute/Lock (CCA), New Filing Period,
 * Generate GST34, Preview/Post Revaluation, year-end checklist boxes, Start Year-End
 * Close, and every Save button in Settings.
 *   !! Start Year-End Close uses a NATIVE confirm() and the recorder auto-accepts
 *   dialogs — never click it in any chapter.
 *
 * Inline helper (via d.page, no recorder changes):
 *   - syncSelects(): Accounting Settings → GL Account Mapping renders every dropdown as
 *     "-- Not Mapped --" even though the Alpine model holds the saved account ids (the
 *     options are x-for rendered after x-model binds). The helper copies each model value
 *     back onto its <select> so the screen shows the mappings that are actually saved.
 *     Display only — nothing is written.
 */

const acctNav = (label) => `.acc-topnav button:has-text("${label}"), .acc-topnav a:has-text("${label}")`;
const menuItem = (label) => `.acc-topnav a:has-text("${label}")`;
const openMenu = async (d, group, label) => {
  await d.click(acctNav(group));
  await d.wait(700);
  await d.click(menuItem(label), { nav: true });
};

async function syncSelects(d) {
  await d.page.evaluate(() => {
    const host = [...document.querySelectorAll('[x-data]')].find((e) => { try { return 'gl_mapping' in window.Alpine.$data(e); } catch { return false; } });
    if (!host) return;
    const data = window.Alpine.$data(host);
    for (const sel of document.querySelectorAll('select[x-model]')) {
      const m = sel.getAttribute('x-model').match(/^(\w+)\[mapping\.key\]$/);
      if (!m) continue;
      const scope = window.Alpine.$data(sel);
      const key = scope?.mapping?.key;
      const v = key && data[m[1]] ? data[m[1]][key] : null;
      if (v != null && v !== '') sel.value = String(v);
    }
  });
}

export default {
  title: 'Accounting: Banking, Fixed Assets, Tax & Year-End',
  subtitle: 'Bank reconciliation, the asset register and depreciation, sales-tax filing, FX and closing the year.',
  start: '/accounting/dashboard',
  intro: 'In this chapter we will tour banking and reconciliation, fixed assets and depreciation, G S T and P S T filing, foreign exchange revaluation, the year-end close, and accounting settings.',
  outro: 'That completes the accounting module. Next, how Fleet Forge keeps QuickBooks Online in sync.',
  scenes: [
    {
      say: 'Open Banking and choose Bank Accounts. Each bank account is linked to a cash account in the general ledger, and its balance comes from that ledger account.',
      run: async (d) => {
        await openMenu(d, 'Banking', 'Bank Accounts');
        await d.wait(1500);
        await d.highlight('table', 'Bank accounts ↔ GL cash accounts', 2600);
      },
    },
    {
      say: 'The U S dollar account is shown in U S dollars here, but it is carried in the ledger in Canadian dollars at the rates on each transaction.',
      caption: 'The USD account is shown in US dollars here, but it is carried in the ledger in CAD at the rates on each transaction.',
      run: async (d) => {
        await d.highlight('table tbody tr:has-text("USD")', 'USD account', 2400);
      },
    },
    {
      say: 'Import C S V loads a statement file from the bank. Transfer moves money between your own accounts and posts the entry for you. Add Account sets up a new bank account.',
      caption: 'Import CSV loads a statement file from the bank. Transfer moves money between your own accounts and posts the entry for you. Add Account sets up a new bank account.',
      run: async (d) => {
        await d.hover('button:has-text("Import CSV")', 1000);
        await d.hover('button:has-text("Transfer")', 1000);
        await d.hover('button:has-text("Add Account")', 1000);
      },
    },
    {
      say: 'Transactions lists every bank line. Matched lines are tied to a journal entry, unmatched lines still need attention, and excluded lines are ignored.',
      run: async (d) => {
        await openMenu(d, 'Banking', 'Transactions');
        await d.wait(1200);
        await d.hover('[name="status"]', 900);
        await d.highlight('table thead', 'Status and JE #', 1800);
      },
    },
    {
      say: 'Reconcile each account every month. Choose the account, enter the statement date and the ending balance from the bank statement, then click Start.',
      run: async (d) => {
        await openMenu(d, 'Banking', 'Reconciliation');
        await d.wait(1000);
        await d.select('[x-model="newRecon.bank_account_id"]', { index: 1 });
        await d.hover('[x-model="newRecon.statement_date"]', 700);
        await d.hover('[x-model="newRecon.statement_ending_balance"]', 700);
        await d.hover('button:has-text("Start")', 900);
      },
    },
    {
      say: 'You then tick off each transaction that appears on the statement. The difference must reach zero before the reconciliation can be completed, and once completed it is locked.',
      run: async (d) => { await d.highlight('.card:has(h3:has-text("Reconciliation History"))', 'Reconciliation history', 2600); },
    },
    {
      say: 'Fixed Assets holds the asset register: every truck and trailer the business owns, with its cost, accumulated depreciation and net book value.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'Asset Register');
        await d.wait(1800);
        await d.highlight('.stat-grid', 'Register totals', 2000);
      },
    },
    {
      say: 'Click View on an asset for its details: depreciation method, useful life, salvage value, the linked unit, and a month-by-month depreciation forecast.',
      run: async (d) => {
        await d.click('table tbody tr:has-text("TR-7013") :is(a, button):has-text("View")');
        await d.wait(1600);
        await d.scroll(450, { x: 960, y: 700 });
        await d.wait(700);
      },
    },
    {
      say: 'Dispose Asset records a sale or scrapping and posts the gain or loss. Impair Asset writes the value down. Both post journal entries, so check with the accountant first.',
      run: async (d) => {
        await d.hover('button:has-text("Dispose Asset")', 1100);
        await d.hover('button:has-text("Impair Asset")', 1100);
        await d.click('button:text-is("Close")');
      },
    },
    {
      say: 'The Payoff Report compares what each unit cost with the net revenue it has earned, and projects when it will pay for itself. Draft invoices never count as revenue here.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'Payoff Report');
        await d.wait(2500);
        await d.highlight('table thead', 'Invested vs recovered', 2400);
      },
    },
    {
      say: 'Depreciation Runs post monthly depreciation for every active asset in one journal entry. Generate Preview calculates a month first, so you can review it before posting.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'Depreciation');
        await d.wait(1500);
        await d.highlight('table tbody tr >> nth=0', 'Posted run + JE', 2000);
        await d.hover('button:has-text("Generate Preview")', 1000);
      },
    },
    {
      say: 'Reverse undoes a posted run by posting the opposite entry. The banner notes that depreciation entries will also go to QuickBooks once sync is switched on.',
      run: async (d) => {
        await d.hover('table tbody tr >> nth=0 >> button:has-text("Reverse")', 1400);
        if (await d.exists('.alert, [class*="banner"], .card:has-text("QuickBooks sync")', 1200)) {
          await d.highlight(':is(.alert, .card, div):has-text("QuickBooks sync connected") >> nth=-1', 'QuickBooks note', 1800);
        }
      },
    },
    {
      say: 'C C A Schedule 8 is the tax version of depreciation for the corporate tax return, calculated by C C A class. Compute builds a year, and Lock freezes it once filed.',
      caption: 'CCA Schedule 8 is the tax version of depreciation for the T2 return, calculated by CCA class. Compute builds a year, and Lock freezes it once filed.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'CCA Schedule 8');
        await d.wait(1500);
        await d.hover('button:has-text("Compute")', 1000);
        await d.hover('button:has-text("Lock Schedule")', 900);
      },
    },
    {
      say: 'Tax covers G S T, H S T and P S T. A filing period is created for each return; you calculate it, mark it filed, then record the remittance to the government.',
      caption: 'Tax covers GST/HST and PST. A filing period is created for each return; you calculate it, mark it filed, then record the remittance.',
      run: async (d) => {
        await openMenu(d, 'Tax', 'GST/HST Filing');
        await d.wait(1500);
        await d.highlight('.stat-grid', 'Filing status', 1800);
        await d.hover('button:has-text("New Filing Period")', 1000);
      },
    },
    {
      say: 'The shortcut cards open the G S T 34 generator for the C R A return, the input tax credit documentation, and tax detail by province and customer.',
      caption: 'The shortcut cards open the GST34 generator for the CRA return, the input tax credit documentation, and tax detail by province and customer.',
      run: async (d) => {
        await d.hover('a:has-text("GST34 Generator"), :text("GST34 Generator")', 1000);
        await d.hover(':text("ITC Documentation")', 900);
        await d.hover(':text("Tax Detail by Province")', 900);
      },
    },
    {
      say: 'Further down, Place of Supply rules decide which province’s tax rates apply, based on the kind of transaction and where the equipment is delivered or used.',
      caption: 'Further down, Place of Supply rules decide which province’s tax rates apply, based on the kind of transaction and where the equipment is delivered or used.',
      run: async (d) => {
        await d.scroll(900);
        await d.wait(1200);
        await d.scroll(-900);
      },
    },
    {
      say: 'Remittances lists every payment made to the tax authorities, filterable by tax type and year, as an audit trail.',
      run: async (d) => {
        await openMenu(d, 'Tax', 'Remittances');
        await d.wait(1500);
      },
    },
    {
      say: 'F X Revaluation marks U S dollar balances to the month-end exchange rate. It only runs on closed periods and must first be enabled in settings.',
      caption: 'FX Revaluation marks USD balances to the month-end exchange rate. It only runs on closed periods and must first be enabled in settings.',
      run: async (d) => {
        await d.click(acctNav('FX Revaluation'), { nav: true });
        await d.wait(1200);
        if (await d.exists('.alert', 1000)) await d.highlight('.alert', 'Disabled until enabled in Settings', 2000);
      },
    },
    {
      say: 'Preview shows the unrealized gain or loss per account using the Bank of Canada rate or a manual rate. Posting records it and automatically reverses it on the first day of the next month.',
      run: async (d) => {
        await d.hover('button:has-text("Preview")', 1000);
        await d.hover('button:has-text("Post Revaluation")', 1200);
      },
    },
    {
      say: 'Year-End is where the fiscal year is closed. Choose the year; the checklist on the left tracks the seventeen close tasks, from bank reconciliations to the final G S T return.',
      caption: 'Year-End is where the fiscal year is closed. Choose the year; the checklist tracks the 17 close tasks, from bank reconciliations to the final GST return.',
      run: async (d) => {
        await d.click(acctNav('Year-End'), { nav: true });
        await d.wait(1200);
        await d.select('[x-model\\.number="fiscalYear"]', '2025');
        await d.wait(2200);
      },
    },
    {
      say: 'Pre-flight checks confirm all twelve periods exist, receivables and payables agree with the ledger, and no draft entries remain.',
      caption: 'Pre-flight checks confirm all 12 periods exist, AR and AP agree with the ledger, and no draft entries remain.',
      run: async (d) => {
        await d.highlight('.card:has-text("Pre-Flight Checks")  >> nth=-1', 'Pre-flight checks', 3000);
      },
    },
    {
      say: 'Closing posts the entry that moves the year’s revenue and expenses into retained earnings, locks all twelve months, and opens next year. It is done by the accountant, so we will not run it here.',
      caption: 'Closing posts the entry that moves the year’s revenue and expenses into retained earnings, locks all 12 months and opens next year. The accountant runs it — we will not.',
      run: async (d) => {
        await d.hover('button:has-text("Start Year-End Close")', 2600);
      },
    },
    {
      say: 'Finally, Settings. General switches the module on and sets the capital expenditure threshold above which purchases are treated as assets.',
      run: async (d) => {
        await d.click(acctNav('Settings'), { nav: true });
        await d.wait(1200);
        await d.highlight('.card:has(h3:has-text("General Settings"))', 'General', 2000);
      },
    },
    {
      say: 'G L Account Mapping tells the automatic entries which accounts to use for receivables, payables, cash, sales taxes, bad debt and exchange gains and losses. Change these only with your accountant.',
      caption: 'GL Account Mapping tells the automatic entries which accounts to use for AR, AP, cash, sales taxes, bad debt and FX gains and losses. Change these only with your accountant.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("GL Account Mapping")');
        await d.wait(1200);
        await syncSelects(d);
        await d.scroll(400);
        await d.scroll(-400);
      },
    },
    {
      say: 'Revenue Mapping sends each type of invoice line to its revenue account. Depreciation holds the default method and salvage, and Tax Filing sets how often G S T and P S T returns are due.',
      caption: 'Revenue Mapping sends each invoice line type to its revenue account. Depreciation holds the default method and salvage, and Tax Filing sets how often GST and PST returns are due.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Revenue Mapping")');
        await d.wait(1100);
        await d.click('button.tab-btn:has-text("Depreciation")');
        await d.wait(1100);
        await d.click('button.tab-btn:has-text("Tax Filing")');
        await d.wait(1100);
      },
    },
  ],
};
