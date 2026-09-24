/*
 * Chapter 22 — Reports
 * The four report areas (Financial, Fleet, Customers, Compliance) and every sub-view,
 * the date-range presets and custom range, the draft/void/written-off exclusion and
 * CAD-canonical conversion, row expansion, CSV export, Print, and the AI Generate tab.
 * Creates no records. The CSV click downloads a file locally (GET only).
 *
 * Inline helper: `redraw` fires a window resize so ApexCharts re-measures a chart that
 * was drawn while its container was still hidden (see report: first chart after a
 * preset change from an empty range renders blank until a resize or sub-tab switch).
 */
const redraw = async (d) => { await d.page.evaluate(() => window.dispatchEvent(new Event('resize'))).catch(() => {}); await d.wait(600); };
const tab = (name) => `button.tab-btn:has-text("${name}")`;

export default {
  title: 'Reports',
  subtitle: 'Historical numbers for money, fleet, customers and compliance — filtered by date and exportable.',
  start: '/dashboard',
  intro: 'In this chapter we will tour the Reports module: choosing a date range, reading each financial, fleet, customer and compliance report, and exporting what you see.',
  outro: 'That covers Reports. Next, Analytics looks forward instead of back, with forecasts and patterns.',
  scenes: [
    {
      say: 'Open Reports from the left sidebar. Reports answer the question: what actually happened in a period?',
      run: async (d) => { await d.nav('Reports', '/reports'); await d.wait(1200); },
    },
    {
      say: 'The date range bar at the top controls every report on the page. It starts on This Month.',
      run: async (d) => { await d.highlight('#date-range-bar', 'Date range', 2600); },
    },
    {
      say: 'If a range shows no figures, the page tells you why. Draft, void and written-off invoices are never counted as revenue, so a month of unsent drafts reads as zero.',
      run: async (d) => {
        if (await d.exists('.empty-state', 4000)) await d.highlight('.empty-state', 'Why this is empty', 3600);
        else await d.highlight('.stat-grid', 'Only sent invoices count', 3000);
      },
    },
    {
      say: "Let's pick This Year. The resolved dates appear on the right, so you always know exactly what range you are looking at.",
      run: async (d) => {
        await d.click('#date-range-bar button:has-text("This Year")');
        await d.wait(2200);
        await redraw(d);
        await d.highlight('.rpt-date-range', 'Resolved range', 1800);
      },
    },
    {
      say: 'The Financial tiles summarize invoices dated in the range: gross revenue including tax, what has been collected, what is still outstanding and overdue, the average invoice, and tax collected.',
      run: async (d) => { await d.highlight('.stat-grid', 'Financial summary', 4200); },
    },
    {
      say: 'Every dollar figure is in Canadian dollars. U S dollar invoices are converted using the exchange rate saved on each invoice, so mixed-currency customers add up correctly.',
      caption: 'Every dollar figure is in CAD. USD invoices are converted using the exchange rate saved on each invoice, so mixed-currency customers add up correctly.',
      run: async (d) => { await d.hover('.stat-card >> nth=0', 1500); await d.hover('.stat-card >> nth=1', 1500); },
    },
    {
      say: 'Below the tiles, each tab is a different cut of the same data. By Customer ranks your top fifteen customers by revenue, with what each still owes.',
      run: async (d) => {
        await d.tab('By Customer');
        await d.wait(2200);
        await d.highlight('#chart-rev-customer', 'Top customers', 2600);
      },
    },
    {
      say: 'The table underneath lists every customer in the range with invoice count, net and gross revenue, collected, outstanding and average invoice.',
      run: async (d) => { await d.scroll(650); await d.wait(900); await d.scroll(-650); },
    },
    {
      say: 'By Period breaks the range into months: net revenue, collected and outstanding side by side, with a totals row at the bottom of the table.',
      run: async (d) => {
        await d.tab('By Period');
        await d.wait(2000);
        await redraw(d);
        await d.highlight('#chart-rev-period', 'Monthly trend', 2400);
        await d.scroll(600); await d.wait(700); await d.scroll(-600);
      },
    },
    {
      say: 'By Type shows which equipment categories earn the most, with the number of units and leases behind each.',
      run: async (d) => { await d.tab('By Type'); await d.wait(2000); await d.highlight('#chart-rev-type', 'Revenue by category', 2400); },
    },
    {
      say: 'A R Aging lists every sent, partly paid or overdue invoice with a balance, grouped by how many days past due it is at the end of the range. Aging shows each balance as billed, in the invoice’s own currency.',
      caption: 'AR Aging lists every sent, partly paid or overdue invoice with a balance, grouped by days past due at the end of the range. Aging shows each balance as billed, in the invoice’s own currency.',
      run: async (d) => {
        await d.tab('AR Aging');
        await d.wait(2200);
        await d.highlight('#chart-aging', 'Aging buckets', 2600);
      },
    },
    {
      say: 'Long tables show the first twenty-five rows. Click Show all to expand the full list.',
      run: async (d) => {
        await d.scroll(700);
        if (await d.exists('button:has-text("Show all")', 3000)) { await d.click('button:has-text("Show all")'); await d.wait(900); await d.scroll(900); await d.wait(600); }
        await d.scroll(-1600);
      },
    },
    {
      say: 'Collection Rate compares what you invoiced each month with what came in, with the collection percentage drawn as a line.',
      run: async (d) => { await d.tab('Collection Rate'); await d.wait(2200); await d.highlight('#chart-collection', 'Invoiced vs collected', 2600); },
    },
    {
      say: 'Invoice Status counts invoices by status. This is the one view that includes drafts and voids, so you can see what the other reports left out.',
      run: async (d) => { await d.tab('Invoice Status'); await d.wait(2200); await d.highlight('#chart-inv-status', 'All statuses', 2600); },
    },
    {
      say: 'To export, click C S V. The file contains the current tab for the same date range, ready for a spreadsheet.',
      caption: 'To export, click CSV. The file contains the current tab for the same date range, ready for a spreadsheet.',
      run: async (d) => {
        await d.tab('By Period');
        await d.wait(1500);
        await d.hover('button:has-text("CSV")', 700);
        // GET download only — no server-side side effect.
        await d.click('button:has-text("CSV")');
        await d.wait(1200);
      },
    },
    {
      say: 'For any other range, choose Custom, enter a start and end date, and click Go.',
      run: async (d) => {
        await d.click('#date-range-bar button:has-text("Custom")');
        await d.wait(500);
        const from = d.page.locator('#date-range-bar input[type="date"]').first();
        const to = d.page.locator('#date-range-bar input[type="date"]').nth(1);
        await d.hover('#date-range-bar input[type="date"] >> nth=0', 300);
        await from.fill('2026-01-01');
        await d.wait(500);
        await d.hover('#date-range-bar input[type="date"] >> nth=1', 300);
        await to.fill('2026-06-30');
        await d.wait(500);
        await d.click('#date-range-bar button:has-text("Go")');
        await d.wait(2200);
        await redraw(d);
      },
    },
    {
      say: 'The Fleet tab measures your equipment: average utilization, total and idle units, fleet revenue, completed maintenance cost, and R O I, which is simply revenue minus maintenance.',
      caption: 'The Fleet tab measures your equipment: average utilization, total and idle units, fleet revenue, completed maintenance cost, and ROI, which is simply revenue minus maintenance.',
      run: async (d) => {
        await d.tab('Fleet');
        await d.wait(2500);
        await d.highlight('.stat-grid', 'Fleet summary', 3200);
      },
    },
    {
      say: 'Utilization is days on lease divided by days in the range. The chart groups units into ten percent brackets, so a large fleet still reads at a glance.',
      run: async (d) => {
        await redraw(d);
        await d.highlight('#chart-fleet-util', 'Utilization brackets', 3000);
        await d.scroll(650); await d.wait(900); await d.scroll(-650);
      },
    },
    {
      say: 'R O I Ranking shows the ten best and ten worst earning units. A unit at the bottom is costing more in repairs than it brings in.',
      caption: 'ROI Ranking shows the ten best and ten worst earning units. A unit at the bottom is costing more in repairs than it brings in.',
      run: async (d) => { await d.tab('ROI Ranking'); await d.wait(2400); await d.highlight('.rpt-grid-2', 'Top and bottom ten', 3000); },
    },
    {
      say: 'Idle Units lists equipment with zero lease days in the range. Maintenance breaks cost down by work type, and By Yard compares each location.',
      run: async (d) => {
        await d.tab('Idle Units'); await d.wait(1800);
        await d.tab('Maintenance'); await d.wait(1800);
        await d.tab('By Yard'); await d.wait(1800);
      },
    },
    {
      say: 'The Customers tab covers who pays and how. Average days to pay compares payment date to due date, so a negative number means customers pay early.',
      run: async (d) => {
        await d.tab('Customers');
        await d.wait(2500);
        await d.highlight('.stat-card:has-text("Avg Days to Pay")', 'Negative = early', 3000);
      },
    },
    {
      say: 'Lifetime Value ranks customers by all-time revenue, whatever range is selected, with this period’s revenue alongside for comparison.',
      run: async (d) => { await redraw(d); await d.highlight('#chart-cust-ltv', 'All-time vs period', 3000); },
    },
    {
      say: 'Payment Behavior shows how many customers pay early, on time or late. New versus Returning splits revenue by first-time customers, and Lease Frequency ranks customers by number of leases.',
      run: async (d) => {
        await d.tab('Payment Behavior'); await d.wait(2200);
        await d.highlight('#chart-pay-behavior', 'Early, on time, late', 1800);
        await d.tab('New vs Returning'); await d.wait(1800);
        await d.tab('Lease Frequency'); await d.wait(1800);
      },
    },
    {
      say: 'Credit Notes shows credit issued to each customer, how much has been used, and what remains.',
      run: async (d) => { await d.tab('Credit Notes'); await d.wait(2200); },
    },
    {
      say: 'Compliance looks ahead instead of back. Choose a window of thirty days up to a year to see C V I, registration and insurance documents coming due.',
      caption: 'Compliance looks ahead instead of back. Choose a window of 30 days up to a year to see CVI, registration and insurance documents coming due.',
      run: async (d) => {
        await d.tab('Compliance');
        await d.wait(2200);
        await d.highlight('#comp-window-bar', 'Look-ahead window', 2000);
        await d.click('#comp-window-bar button:has-text("365d")');
        await d.wait(2000);
      },
    },
    {
      say: 'Timeline counts expiries per month, Status shows each document type at a glance, and Expired and Upcoming list the exact units to renew. When nothing falls in the window, the page says so.',
      caption: 'Timeline counts expiries per month, Status shows each document type at a glance, and Expired and Upcoming list the exact units to renew. When nothing falls in the window, the page says so.',
      run: async (d) => {
        await d.tab('Status'); await d.wait(1600);
        await d.tab('Expired'); await d.wait(1600);
        await d.tab('Upcoming'); await d.wait(1600);
      },
    },
    {
      say: 'AI Generate lets you describe a chart in plain English, such as revenue by customer this quarter, and builds it from your live data.',
      run: async (d) => {
        await d.tab('AI Generate');
        await d.wait(1200);
        await d.hover('button:has-text("Revenue by customer this quarter")', 900);
        await d.hover('button:has-text("Generate")', 1200);
      },
    },
    {
      say: 'Finally, Print gives a clean copy of the current tab without the menus. Choose Save as P D F in the print dialog to keep a P D F.',
      caption: 'Finally, Print gives a clean copy of the current tab without the menus. Choose Save as PDF in the print dialog to keep a PDF.',
      run: async (d) => { await d.tab('Financial'); await d.wait(1500); await d.hover('button:has-text("Print")', 1800); },
    },
  ],
};
