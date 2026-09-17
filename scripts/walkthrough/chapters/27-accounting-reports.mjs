/*
 * Chapter 27 — Accounting: Financial Reports & Budgets
 * The Reports menu, Profit & Loss (date range, prior-year and budget comparisons),
 * Balance Sheet, Cash Flow (indirect method + bank tie-out), Trial Balance with
 * drill-down to the ledger, the Working Trial Balance + a lead schedule (A-100), Per-Unit
 * P&L fleet KPIs, the fixed-asset schedule, and Budgets (list, the budget grid page, and
 * the New Budget form, toured only).
 *
 * Records created: none. Export PDF / AI Narrative / Save Current are hovered, not
 * clicked (PDF opens a pop-up, AI calls an outside service, Save uses a browser prompt).
 * The Per-Unit P&L "Overhead basis" selector is hovered only — changing it writes a setting.
 *
 * Not shown: budgets/edit.php (header edit) — nothing in the app links to it (2026-09-17).
 * Known cosmetic issues visible on camera: P&L / Balance Sheet render only the summary
 * rows (nested <template> inside x-for), and the Net Income / Total rows use a
 * hard-coded light background that is unreadable in dark mode.
 */

const acctNav = (label) => `.acc-topnav button:has-text("${label}"), .acc-topnav a:has-text("${label}")`;
const menuItem = (label) => `.acc-topnav a:has-text("${label}")`;
const openReport = async (d, label) => {
  await d.click(acctNav('Reports'));
  await d.wait(700);
  await d.click(menuItem(label), { nav: true });
};
/** Wait for an Alpine report page to finish its fetch (Run Report button stops saying "Loading…"). */
const settle = async (d) => {
  await d.page.waitForFunction(() => ![...document.querySelectorAll('button')].some((b) => /Loading…/.test(b.textContent)), null, { timeout: 30000 }).catch(() => {});
  await d.wait(900);
};

export default {
  title: 'Accounting: Financial Reports & Budgets',
  subtitle: 'Profit and loss, balance sheet, cash flow and trial balance — straight from the general ledger.',
  start: '/accounting/dashboard',
  intro: 'In this chapter we will run the financial statements: profit and loss, balance sheet, cash flow and trial balance, then look at per-unit profitability and budgets.',
  outro: 'That covers financial reports and budgets. Next, receivables and payables.',
  scenes: [
    {
      say: 'Every financial report lives under Reports in the accounting menu. They all read the general ledger, so they only include posted journal entries. Drafts never count.',
      run: async (d) => {
        await d.click(acctNav('Reports'));
        await d.wait(1800);
        await d.highlight('.acc-topnav-group:has(button:has-text("Reports")) [role="menu"], .acc-topnav-group:has(button:has-text("Reports")) .acc-topnav-dropdown, .acc-topnav-group:has(button:has-text("Reports"))', 'Reports menu', 2400);
      },
    },
    {
      say: 'Start with Profit and Loss. It defaults to the year to date. Change the From and To dates for any month, quarter or year, then click Run Report.',
      run: async (d) => {
        await d.click(menuItem('Profit & Loss'), { nav: true });
        await settle(d);
        await d.highlight('[x-model="form.from"]', 'From', 900);
        await d.highlight('[x-model="form.to"]', 'To', 900);
        await d.click('button:has-text("Run Report")');
        await settle(d);
      },
    },
    {
      say: 'Revenue less cost of revenue gives gross profit. Subtract operating expenses for operating income, and other income and expense for net income.',
      run: async (d) => { await d.highlight('.card table', 'Profit and loss', 3400); },
    },
    {
      say: 'All amounts are in Canadian dollars. U S dollar invoices and payments are converted into the ledger at the exchange rate frozen on each document, so the report never re-converts at today’s rate.',
      caption: 'All amounts are in CAD. USD invoices and payments are converted into the ledger at the exchange rate frozen on each document, so the report never re-converts at today’s rate.',
      run: async (d) => { await d.hover('button:has-text("Run Report")', 1600); },
    },
    {
      say: 'Comparison adds a second column. Prior Year shows the same dates last year, with the dollar variance beside each line.',
      run: async (d) => {
        await d.select('[x-model="form.comparison"]', 'prior_year');
        await d.click('button:has-text("Run Report")');
        await settle(d);
        await d.highlight('.card table', 'Compare and variance', 2000);
      },
    },
    {
      say: 'Choose Budget and pick a budget to see actual results against plan for the same period.',
      run: async (d) => {
        await d.select('[x-model="form.comparison"]', 'budget');
        await d.wait(500);
        await d.select('[x-model="form.budget_id"]', { index: 1 });
        await d.click('button:has-text("Run Report")');
        await settle(d);
      },
    },
    {
      say: 'Export P D F produces a printable copy for the owner or accountant. Save Current keeps these dates and comparison settings as a named view you can reload from the list.',
      caption: 'Export PDF produces a printable copy for the owner or accountant. Save Current keeps these dates and comparison settings as a named view you can reload.',
      run: async (d) => {
        await d.hover('button:has-text("Export PDF")', 1200);
        await d.hover('button:has-text("Save Current")', 1200);
      },
    },
    {
      say: 'The Balance Sheet is a snapshot on one date: what the business owns, what it owes, and the owners’ equity. Pick the As Of date and run it.',
      run: async (d) => {
        await openReport(d, 'Balance Sheet');
        await settle(d);
        await d.highlight('[x-model="form.as_of"]', 'As of date', 1500);
      },
    },
    {
      say: 'Assets must equal liabilities plus equity. If they do not, a red banner shows the difference. Treat that as a signal to involve your accountant before relying on the numbers.',
      run: async (d) => {
        if (await d.exists('.alert, [class*="alert"]', 1500)) await d.highlight('.alert, [class*="alert"]', 'Balance check', 2600);
        else await d.highlight('.card table', 'Totals', 2600);
      },
    },
    {
      say: 'Cash Flow explains why the bank balance moved. It starts from net income, adds back non-cash items like depreciation, then adjusts for changes in receivables, payables and taxes owing.',
      run: async (d) => {
        await openReport(d, 'Cash Flow');
        await settle(d);
        await d.scroll(350);
      },
    },
    {
      say: 'Investing covers equipment bought and sold; financing covers loans and owner draws. A yellow banner appears when the calculated closing cash does not match the bank account in the ledger.',
      run: async (d) => {
        await d.scroll(500);
        await d.wait(900);
        await d.scroll(-850);
        if (await d.exists('.alert-warning', 1500)) await d.highlight('.alert-warning', 'Cash tie-out', 2000);
      },
    },
    {
      say: 'The Trial Balance lists every account with its debit or credit balance as of a date. Total debits must equal total credits, shown by the Balanced badge.',
      run: async (d) => {
        await openReport(d, 'Trial Balance');
        await d.wait(2500);
        await d.highlight('[x-model="asOfDate"]', 'As of date', 1000);
        await d.highlight('.badge:has-text("Balanced"), :text("BALANCED")', 'Debits = credits', 2000);
      },
    },
    {
      say: 'This is the report your accountant usually asks for first. Click any account name to open its ledger and see the entries behind the balance.',
      run: async (d) => {
        await d.scroll(500);
        await d.hover('table tbody tr >> nth=2 >> a', 1500);
        await d.scroll(-500);
      },
    },
    {
      say: 'The Working Trial Balance is the accountant’s version. For the chosen period, each account shows its prior year-end balance, the balance before and after adjusting entries, and the change.',
      run: async (d) => {
        await openReport(d, 'Working Trial Balance');
        await d.click('button:has(span:text-is("Run"))');
        await settle(d);
        await d.exists('table tbody a:has-text("A-100")', 20000);
        await d.highlight('table thead', 'Prior year, unadjusted, adjustments, adjusted, variance', 2600);
      },
    },
    {
      say: 'Enter a materiality amount to flag balances and changes larger than it. The plus button on each row adds a workpaper note for the review file.',
      run: async (d) => {
        await d.highlight('[x-model="materiality"]', 'Materiality', 1400);
        await d.hover('table tbody tr >> nth=1 >> button[title="Add annotation"]', 1200);
        await d.scroll(600);
        await d.wait(800);
        await d.scroll(-600);
      },
    },
    {
      say: 'Each account belongs to a lead schedule, such as A one hundred for cash. Click the code to open the schedule: every account in the group, its opening and closing balance, and the entries in between.',
      caption: 'Each account belongs to a lead schedule, such as A-100 for cash. Click the code to open the schedule: every account in the group, its opening and closing balance, and the entries in between.',
      run: async (d) => {
        await d.click('table tbody a:has-text("A-100")', { nav: true });
        await d.wait(2000);
        await d.highlight('.card:has-text("Opening:") >> nth=0', 'Opening, activity, closing', 2400);
      },
    },
    {
      say: 'Per-Unit P and L shows profitability for each piece of equipment: revenue, direct costs, contribution, an overhead share and return on capital, plus fleet K P Is across the top.',
      caption: 'Per-Unit P&L shows profitability for each unit: revenue, direct costs, contribution, overhead share and return on capital, plus fleet KPIs across the top.',
      run: async (d) => {
        await openReport(d, 'Per-Unit P&L');
        await d.wait(3000);
        await d.highlight('.stat-grid, .card:has-text("RevPAU")', 'Fleet KPIs', 2600);
      },
    },
    {
      say: 'Overhead basis decides how shared costs are spread across units. Changing it saves a company-wide setting, so leave it to the accountant. The Per-Customer Rollup tab totals the same figures by customer.',
      run: async (d) => {
        await d.hover('[x-model="overheadBasis"]', 1600);
        await d.click('button:has-text("Per-Customer Rollup")');
        await d.wait(1600);
      },
    },
    {
      say: 'Asset Schedule is the continuity schedule for fixed assets: opening cost, additions, disposals, depreciation and net book value by asset class.',
      run: async (d) => {
        await openReport(d, 'Asset Schedule');
        await d.wait(2500);
        await d.highlight('table', 'Cost, depreciation, net book value', 2600);
      },
    },
    {
      say: 'Now Budgets. Each budget covers one fiscal year and holds a monthly amount per account. Filter by year or status.',
      run: async (d) => {
        await d.click(acctNav('Budgets'), { nav: true });
        await d.highlight('table', 'Budgets', 2000);
        await d.hover('[name="status"]', 800);
      },
    },
    {
      say: 'A budget moves from draft to active once approved, and to archived when replaced. Open one with View to edit its monthly grid and run a variance report.',
      run: async (d) => {
        await d.highlight('table tbody tr >> nth=0', '2026 Operating Budget', 2000);
        await d.hover('table tbody tr:first-child :is(a, button):has-text("View")', 1200);
      },
    },
    {
      say: 'The budget page is a grid of twelve monthly amounts for each account, with the annual total on the right. Type over any month, add another account at the bottom, then click Save All Changes.',
      run: async (d) => {
        await d.click('table tbody tr:first-child :is(a, button):has-text("View")', { nav: true });
        await d.wait(1500);
        await d.highlight('table', 'Monthly grid', 2400);
        await d.hover('[x-model="newAccountId"]', 800);
        await d.hover('button:has-text("Save All Changes")', 1000);
      },
    },
    {
      say: 'Move to Draft reopens an active budget for changes, and Approve makes a draft active again. Variance Report compares the budget with actual results for the year.',
      run: async (d) => {
        await d.hover('button:has-text("Move to Draft"), button:has-text("Approve")', 1200);
        await d.hover('a:has-text("Variance Report")', 1200);
        await d.click('a:has-text("Back")', { nav: true });
      },
    },
    {
      say: 'To start next year, click New Budget. Give it a name, the fiscal year, and a version such as base, conservative or optimistic.',
      run: async (d) => {
        await d.click('a:has-text("New Budget"), button:has-text("New Budget")', { nav: true });
        await d.type('[x-model="form.name"]', '2027 Operating Budget');
        await d.hover('[x-model="form.version"]', 900);
      },
    },
    {
      say: 'Tick Copy line items to start from the most recent prior-year budget instead of a blank grid. We will cancel here rather than create it.',
      run: async (d) => {
        await d.highlight('[x-model="form.copy_prior_year"]', 'Copy prior year', 2000);
        await d.hover('button:has-text("Create Budget")', 900);
        await d.click('a:has-text("Cancel"), button:has-text("Cancel")', { nav: true });
      },
    },
  ],
};
