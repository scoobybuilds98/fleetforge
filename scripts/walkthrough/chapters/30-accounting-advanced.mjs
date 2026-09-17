/*
 * Chapter 30 — Accounting: Advanced — Leases, CapEx, Impairment and Disclosures
 * Lessor (ASPE 3065) capital-lease accounting: the Capital Lease Register, the
 * classification wizard on New Lease, residual reviews; CapEx requests and the
 * flagged-work-order capitalize/expense review; impairment tests; the Book vs Tax
 * temporary-differences report; the Disclosure Note Builder; the Damage Claims
 * accounting subledger.
 *
 * Records created: none. This chapter is a TOUR. Hovered only: Create New Lease,
 * Create Lease (on the wizard), + New Request, Capitalize, Expense, Approve,
 * Run Annual Preview (Step 1), Generate Notes, Download PDF Note Pack, Edit, Save Settings, Refresh.
 * The only clicks that change anything on screen are UI-only: opening menus, the
 * collapsible classification panel + the "Potential Capital Lease" radio (nothing
 * is submitted), and the CapEx request detail modal.
 *
 * Known dev-data gaps (narrated, not shown):
 *   - Capital Lease Register is empty (no sales-type/direct-financing leases), so the
 *     capital lease detail page (accounting/leases/show) cannot be opened.
 *   - Residual Reviews and Impairment Tests are opened but empty (no capital leases,
 *     no impairment tests recorded). Impairment test detail (impairment/show) needs a
 *     test_id; none exist, and creating one requires Run Annual Preview (a POST that
 *     writes test rows), so the detail page is described, not opened.
 *   - Book vs Tax: tax amount is $0 because no CCA continuity exists for FY 2026.
 */

const acctNav = (label) => `.acc-topnav button:has-text("${label}"), .acc-topnav a:has-text("${label}")`;
const menuItem = (label) => `.acc-topnav a:has-text("${label}")`;
const openGroup = async (d, group) => { await d.click(acctNav(group)); await d.wait(700); };
const openMenu = async (d, group, label) => {
  await openGroup(d, group);
  await d.click(menuItem(label), { nav: true });
};

export default {
  title: 'Accounting: Advanced — Leases, CapEx, Impairment and Disclosures',
  subtitle: 'Capital leases, capital spending, impairment testing, book versus tax, disclosure notes and the damage-claims subledger.',
  start: '/accounting/dashboard',
  intro: 'In this chapter we cover the advanced accounting screens: lessor capital leases, capital expenditure requests, impairment tests, book versus tax differences, disclosure notes, and the damage claims subledger.',
  outro: 'That covers the advanced accounting screens. Next, QuickBooks Online Sync: how FleetForge sends its accounting to QuickBooks.',
  scenes: [
    {
      say: 'Open the Lessor menu. These screens handle leases that are really a sale or a financing arrangement, such as lease-to-own contracts, under the Canadian private-enterprise lease standard.',
      caption: 'Open the Lessor menu. These screens handle leases that are really a sale or a financing arrangement, such as lease-to-own contracts, under ASPE 3065.',
      run: async (d) => {
        await openGroup(d, 'Lessor');
        await d.hover(menuItem('Capital Leases'), 900);
        await d.hover(menuItem('Classification Wizard'), 900);
        await d.hover(menuItem('Residual Reviews'), 900);
      },
    },
    {
      say: 'Capital Leases is the register of sales-type and direct-financing leases. Ordinary rentals are operating leases and stay on the main Leases page.',
      run: async (d) => {
        await d.click(menuItem('Capital Leases'), { nav: true });
        await d.wait(1500);
      },
    },
    {
      say: 'The register is empty here because every lease in this data is a normal rental. A capital lease only appears after the classification wizard decides a lease qualifies.',
      run: async (d) => {
        await d.highlight('.card:has-text("No capital leases on record")', 'Empty until a lease is classified capital', 3000);
      },
    },
    {
      say: 'Opening a capital lease shows its implicit interest rate, the net investment, and an amortization schedule that splits each payment into finance income and principal, month by month.',
      run: async (d) => {
        await d.hover('a:has-text("Create New Lease"), button:has-text("Create New Lease")', 2500);
      },
    },
    {
      say: 'Classification Wizard opens the New Lease form. Near the bottom is the lease classification panel. Operating is the default, and most rentals stay operating.',
      run: async (d) => {
        await openMenu(d, 'Lessor', 'Classification Wizard');
        await d.wait(1500);
        await d.page.locator('.card-title:has-text("ASPE 3065")').first().scrollIntoViewIfNeeded();
        await d.wait(600);
        await d.highlight('.card:has(.card-title:has-text("ASPE 3065"))', 'Lease classification', 2200);
      },
    },
    {
      say: 'Choose Potential Capital Lease only for lease-to-own contracts. You then enter the term, the economic life of the unit, its fair value, the discount rate, residual values and any bargain purchase option.',
      run: async (d) => {
        if (!(await d.exists('input[name="classification_choice"][value="capital"]', 800))) {
          await d.click('.card-header:has(.card-title:has-text("ASPE 3065"))');
          await d.wait(500);
        }
        await d.click('input[name="classification_choice"][value="capital"]');
        await d.wait(900);
        await d.hover('#lessor_term_months', 700);
        await d.hover('#lessor_econ_life', 700);
        await d.hover('#lessor_fair_value', 700);
        await d.hover('#lessor_disc_rate_pct', 700);
        await d.hover('#lessor_bpo_amount', 700);
      },
    },
    {
      say: 'When the lease is created, three tests run. Does title pass or is there a bargain purchase option? Does the term cover at least seventy-five percent of the unit’s life? Are the payments worth at least ninety percent of its fair value?',
      caption: 'When the lease is created, three tests run: title transfer or a bargain purchase option; term at least 75% of the unit’s life; payments worth at least 90% of fair value.',
      run: async (d) => {
        await d.hover('#lessor_title_transfer', 1200);
        await d.hover('#lessor_guar_resid', 1200);
        await d.hover('#lessor_credit_risk', 1200);
      },
    },
    {
      say: 'If any test is met, and collection and costs are predictable, the lease is sales-type when fair value differs from the unit’s book value, or direct financing when they match. Otherwise it stays operating.',
      run: async (d) => {
        await d.hover('#lessor_costs_est', 1200);
        await d.page.locator('button:has-text("Create Lease")').last().scrollIntoViewIfNeeded().catch(() => {});
        await d.hover('button:has-text("Create Lease & Run Classification")', 1800);
      },
    },
    {
      say: 'We will not create a lease here. The result is archived with the lease, and reclassifying an existing capital lease needs a super admin.',
      run: async (d) => {
        await d.click('input[name="classification_choice"][value="operating"]');
        await d.wait(800);
      },
    },
    {
      say: 'Residual Reviews is where the accountant revisits each capital lease’s unguaranteed residual value once a year. The top table lists active capital leases, with a Run Review button on each.',
      run: async (d) => {
        await d.goto('/accounting/leases');
        await d.wait(800);
        await openMenu(d, 'Lessor', 'Residual Reviews');
        await d.wait(1500);
        await d.highlight('.card:has-text("Active Capital Leases") >> nth=-1', 'Active capital leases', 2600);
      },
    },
    {
      say: 'A lower residual posts an impairment loss and rebuilds the unposted part of the schedule. Increases are not allowed. Every review lands in the history below, which is empty here because there are no capital leases yet.',
      run: async (d) => {
        await d.highlight('.card:has-text("Review History") >> nth=-1', 'Review history', 3000);
      },
    },
    {
      say: 'Now CapEx Requests, under Fixed Assets. A capital expenditure request is a proposal to spend on something that becomes an asset, like a new trailer, with a budget and an asset class.',
      caption: 'Now CapEx Requests, under Fixed Assets. A capital expenditure request is a proposal to spend on something that becomes an asset, like a new trailer, with a budget and an asset class.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'CapEx Requests');
        await d.wait(1500);
        await d.highlight('.stat-grid', 'Requests and active budget', 2200);
      },
    },
    {
      say: 'Plus New Request starts one. It then moves from pending approval, to approved, to in progress, to completed. Requests can also be rejected or cancelled.',
      caption: '+ New Request starts one. It moves from pending approval, to approved, to in progress, to completed. Requests can also be rejected or cancelled.',
      run: async (d) => {
        await d.hover('button:has-text("+ New Request")', 1200);
        await d.page.locator('select[x-model="filterStatus"]').first().scrollIntoViewIfNeeded();
        await d.wait(500);
        await d.hover('select[x-model="filterStatus"]', 1200);
      },
    },
    {
      say: 'Click View on a request to see its budget, actual spend and variance. Only managers can approve. Completing a request creates the fixed asset for you.',
      run: async (d) => {
        await d.click('table:has(th:has-text("Request #")) tbody tr >> nth=0 >> button:has-text("View")');
        await d.wait(1500);
        if (await d.exists('button:has-text("Approve")', 600)) await d.hover('button:has-text("Approve")', 1000);
        if (await d.exists('button:has-text("Complete + Create Asset")', 600)) await d.hover('button:has-text("Complete + Create Asset")', 1000);
        await d.click('.modal-footer button:has-text("Close")');
        await d.wait(500);
      },
    },
    {
      say: 'Above the requests, Flagged Work Orders lists completed maintenance that cost more than the capitalization threshold. That threshold is set in Accounting Settings, here two thousand five hundred dollars.',
      caption: 'Flagged Work Orders lists completed maintenance that cost more than the capitalization threshold. The threshold is set in Accounting Settings, here $2,500.',
      run: async (d) => {
        await d.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        await d.wait(700);
        await d.highlight(':is(.card, div):has(> div h3:has-text("Flagged Work Orders")), h3:has-text("Flagged Work Orders")', 'Above the CapEx threshold', 2800);
      },
    },
    {
      say: 'For each one, decide. Capitalize if the work improved the unit or extended its life: it creates a fixed asset. Expense if it was an ordinary repair: it is recorded as rejected for capital, with your reason.',
      run: async (d) => {
        await d.hover('button:has-text("Capitalize")', 1600);
        await d.hover('button:has-text("Expense")', 1600);
      },
    },
    {
      say: 'Impairment Tests, also under Fixed Assets, check whether a unit is worth less than its book value, for example after heavy damage or a long idle period. Each unit is tested on its own.',
      run: async (d) => {
        await openMenu(d, 'Fixed Assets', 'Impairment Tests');
        await d.wait(1500);
      },
    },
    {
      say: 'Run Annual Preview does step one for every fleet asset: it compares book value with the cash the unit is expected to earn plus what it will sell for. Units where book value is higher need a fair value.',
      run: async (d) => {
        await d.hover('button:has-text("Run Annual Preview")', 2400);
      },
    },
    {
      say: 'Posting then records the loss, book value minus fair value, by debiting impairment loss and crediting accumulated depreciation. Impairments are never reversed, so only the accountant posts them.',
      run: async (d) => {
        await d.highlight('.page-header, h1:has-text("Impairment Tests") >> nth=-1', 'Two-step test, never reversed', 2800);
      },
    },
    {
      say: 'Every test is kept in the history for the year, with the carrying amount, cash flows, fair value, loss and journal entry. Detail opens a test’s full breakdown. No tests have been recorded in this data yet.',
      run: async (d) => {
        await d.highlight('.card:has-text("Test History") >> nth=-1', 'Test history', 3000);
      },
    },
    {
      say: 'Book versus Tax Differences, under Reports, lines up book amounts against what is claimed for tax, such as depreciation versus capital cost allowance, and unpaid accruals.',
      caption: 'Book vs Tax Differences, under Reports, lines up book amounts against what is claimed for tax, such as depreciation versus CCA, and unpaid accruals.',
      run: async (d) => {
        await openMenu(d, 'Reports', 'Book vs Tax Differences');
        await d.wait(2000);
        await d.hover('select[x-model\\.number="fiscalYear"]', 900);
      },
    },
    {
      say: 'The banner explains that the company uses the taxes payable method, so no deferred tax is booked. This schedule is reference for the corporate tax return preparer, and Export P D F saves a copy.',
      caption: 'The banner explains the company uses the taxes-payable method, so no deferred tax is booked. This schedule is reference for the T2 preparer; Export PDF saves a copy.',
      run: async (d) => {
        await d.highlight('.alert-warning', 'Taxes-payable method', 2200);
        await d.hover('button:has-text("Export PDF")', 1000);
      },
    },
    {
      say: 'Each line shows the book amount, the tax amount and the difference. Depreciation compares the depreciation runs with the capital cost allowance schedule, and unpaid approved bills are accruals.',
      caption: 'Each line shows the book amount, the tax amount and the difference. Depreciation compares the depreciation runs with the CCA schedule, and unpaid approved bills are accruals.',
      run: async (d) => {
        await d.highlight('table tbody >> nth=0', 'Depreciation vs CCA', 2200);
        await d.highlight('table tbody >> nth=1', 'Unpaid accruals', 1800);
      },
    },
    {
      say: 'The tax column is zero here because the capital cost allowance for this year has not been computed yet. The total temporary difference is at the bottom.',
      caption: 'The tax column is zero here because CCA for this year has not been computed yet. The total temporary difference is at the bottom.',
      run: async (d) => {
        await d.highlight('tfoot', 'Total temporary difference', 2400);
      },
    },
    {
      say: 'Disclosure Notes builds the nine notes that go with the year-end financial statements. Choose the fiscal year and the engagement type.',
      run: async (d) => {
        await openMenu(d, 'Reports', 'Disclosure Notes');
        await d.wait(2000);
        await d.hover('select[x-model="engagementType"]', 1200);
      },
    },
    {
      say: 'Generate Notes rewrites the automatic notes from live data but keeps any you have edited. Download P D F Note Pack produces the finished set.',
      caption: 'Generate Notes rewrites the automatic notes from live data but keeps any you have edited. Download PDF Note Pack produces the finished set.',
      run: async (d) => {
        await d.hover('button:has-text("Generate Notes")', 1300);
        await d.hover('button:has-text("Download PDF Note Pack")', 1300);
      },
    },
    {
      say: 'Each note is marked Auto or Edited. Edit lets the accountant adjust the wording, and an edited note can be regenerated back to the automatic version.',
      run: async (d) => {
        await d.hover('button:has-text("Edit")', 1200);
        await d.scroll(700);
        await d.wait(900);
      },
    },
    {
      say: 'Further down, mark customers and vendors that are related parties for that note, and set the legal entity name and the C P A firm details used on the pack.',
      caption: 'Further down, mark customers and vendors that are related parties, and set the legal entity name and the CPA firm details used on the pack.',
      run: async (d) => {
        await d.page.locator('h2:has-text("Related Parties")').first().scrollIntoViewIfNeeded();
        await d.wait(900);
        await d.hover('button:has-text("Manage Related Parties")', 1000);
        await d.page.locator('h2:has-text("Engagement Settings")').first().scrollIntoViewIfNeeded();
        await d.wait(700);
        await d.hover('button:has-text("Save Settings")', 900);
      },
    },
    {
      say: 'Last, the Damage Claims Subledger. It is the accounting view of the damage claims module, with the repair cost, what was billed to the customer, what was collected and the net profit or loss per claim.',
      run: async (d) => {
        await openMenu(d, 'Reports', 'Damage Claims Subledger');
        await d.wait(2200);
        await d.highlight('.stat-grid, .card:has-text("Total Repair Cost") >> nth=0', 'Cost vs recovery', 2400);
      },
    },
    {
      say: 'Filter by date range and claim status. Repair cost uses the actual cost when entered, otherwise the estimate.',
      run: async (d) => {
        await d.hover('[x-model="periodStart"]', 800);
        await d.hover('[x-model="statusFilter"]', 800);
        await d.hover('button:has-text("Refresh")', 800);
      },
    },
    {
      say: 'Journal Entry Links tie each claim to the general ledger: the recovery entry when the damage invoice is billed, the repair entry, and any write-off. Click one to open the journal entry.',
      caption: 'JE Links tie each claim to the general ledger: the recovery entry when the damage invoice is billed, the repair entry, and any write-off. Click one to open the journal entry.',
      run: async (d) => {
        await d.highlight('th:has-text("JE Links")', 'Links to the GL', 2600);
      },
    },
    {
      say: 'In this data no claim has a recovery invoice linked yet, so billed and recovered are zero, the links are blank, and net loss equals the repair cost. View opens the claim itself.',
      run: async (d) => {
        await d.hover('table tbody tr >> nth=0 >> :is(a, button):has-text("View")', 2400);
      },
    },
  ],
};
