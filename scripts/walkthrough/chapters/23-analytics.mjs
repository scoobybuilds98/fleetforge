/*
 * Chapter 23 — Analytics
 * The eight forward-looking panels on /analytics and what each metric means:
 * revenue forecast, utilization matrix, concentration risk, seasonal pattern,
 * cohort revenue, fleet composition optimizer, lead time and average lease value,
 * plus the per-panel date ranges, chart download menu and the AI Report Generator.
 * Creates no records. The AI Generate button is only hovered (it would call the AI).
 *
 * Inline helper: `redraw` fires a window resize. Analytics charts are drawn while
 * their container is still hidden (x-show waits on `loading`), so they stay blank
 * until ApexCharts re-measures on resize — see report.
 */
const redraw = async (d) => { await d.page.evaluate(() => window.dispatchEvent(new Event('resize'))).catch(() => {}); await d.wait(700); };
const panel = (title) => `.an-panel:has(.an-panel-title:text-is("${title}"))`;

export default {
  title: 'Analytics',
  subtitle: 'Forecasts, patterns and fleet-sizing signals to help you plan, not just look back.',
  start: '/dashboard',
  intro: 'In this chapter we will walk through Analytics: eight panels that forecast revenue, spot patterns, and suggest how your fleet should be sized.',
  outro: 'That covers Analytics. Next, we will put questions to the AI Assistant directly.',
  scenes: [
    {
      say: 'Open Analytics from the sidebar. Where Reports tell you what happened, Analytics helps you plan what comes next.',
      run: async (d) => { await d.nav('Analytics', '/analytics'); await d.wait(3500); await redraw(d); },
    },
    {
      say: 'Money panels follow the same rules as Reports: only sent invoices count, drafts, voids and write-offs are left out, and U S dollar invoices are converted to Canadian dollars.',
      caption: 'Money panels follow the same rules as Reports: only sent invoices count, drafts, voids and write-offs are left out, and USD invoices are converted to CAD.',
      run: async (d) => { await d.highlight(panel('Revenue Forecast'), 'Sent invoices only · CAD', 3600); },
    },
    {
      say: 'Revenue Forecast plots monthly revenue as a solid line. The dashed line projects the next three months from the trend of the last six, with a ten percent band either side.',
      run: async (d) => {
        await d.hover(`${panel('Revenue Forecast')} #chart-revenue-forecast`, 1500);
        await d.highlight('#chart-revenue-forecast', 'History, projection, band', 3000);
      },
    },
    {
      say: 'Above the chart: total revenue for the period, the monthly average, the peak month, and the projected total for the next three months.',
      run: async (d) => { await d.highlight(`${panel('Revenue Forecast')} .an-kpi-strip`, 'Forecast summary', 3400); },
    },
    {
      say: 'Panels with From and To boxes have their own date range. Change a date and only that panel reloads.',
      run: async (d) => {
        const from = `${panel('Revenue Forecast')} input[type="date"] >> nth=0`;
        await d.hover(from, 600);
        await d.page.locator(from).fill('2025-01-01');
        await d.wait(2500);
        await redraw(d);
      },
    },
    {
      say: 'The Utilization Efficiency Matrix puts every unit on one chart. Further right means more days on lease, higher up means more revenue per day, and each colour is an equipment category.',
      run: async (d) => {
        await d.highlight(panel('Utilization Efficiency Matrix'), 'One dot per unit', 3000);
        await d.hover('#chart-utilization-matrix', 1200);
      },
    },
    {
      say: 'Units in the top right are your best earners. Units in the bottom left are rarely out and earn little when they are, so they are worth a closer look.',
      run: async (d) => { await d.hover(`${panel('Utilization Efficiency Matrix')} .an-kpi-strip`, 2400); },
    },
    {
      say: 'Customer Concentration Risk shows how much of the last twelve months of revenue comes from your top five customers. The share turns red when one customer passes forty percent, or the top five pass seventy-five.',
      run: async (d) => { await d.highlight(panel('Customer Concentration Risk'), 'Revenue dependence', 4200); },
    },
    {
      say: 'Seasonal Revenue Pattern compares calendar months across all years, using the average invoice value in each month. Use it to see which months bring bigger bills.',
      run: async (d) => { await d.highlight(panel('Seasonal Revenue Pattern'), 'Busy and quiet months', 3400); },
    },
    {
      say: 'Best and worst month are named above the chart. Seasonal variance is the gap between them, as a percentage of the best month.',
      run: async (d) => { await d.hover(`${panel('Seasonal Revenue Pattern')} .an-kpi-strip`, 2600); },
    },
    {
      say: 'Cohort Revenue Analysis stacks monthly revenue by the year each lease started, so you can see whether older contracts or new business is carrying you.',
      run: async (d) => { await d.highlight(panel('Cohort Revenue Analysis'), 'Revenue by lease start year', 3600); },
    },
    {
      say: 'The Fleet Composition Optimizer compares how many units you own in each category with how many you need: the busiest point in the last year, plus fifteen percent headroom.',
      run: async (d) => { await d.highlight(panel('Fleet Composition Optimizer'), 'Current vs recommended', 3800); },
    },
    {
      say: 'Over capacity means more units than demand has needed, which is money tied up in idle equipment. Under capacity means you may be turning work away.',
      run: async (d) => { await d.hover(`${panel('Fleet Composition Optimizer')} .an-kpi-strip`, 1500); await d.hover('#chart-fleet-optimizer', 1500); },
    },
    {
      say: 'Lead Time Analysis tracks the average number of days between creating a lease and activating it, month by month. Lower is faster.',
      run: async (d) => { await d.highlight(panel('Lead Time Analysis'), 'Created to activated', 3400); },
    },
    {
      say: 'Average Lease Value Trend shows the average invoice total each month, against the all-time average, and tells you whether it is trending up or down.',
      run: async (d) => { await d.highlight(panel('Average Lease Value Trend'), 'Average invoice per month', 3600); },
    },
    {
      say: 'Every chart has a menu in its corner. Use it to download the chart as an image or its numbers as a C S V.',
      caption: 'Every chart has a menu in its corner. Use it to download the chart as an image or its numbers as a CSV.',
      run: async (d) => {
        if (await d.exists('#chart-avg-lease-value .apexcharts-menu-icon', 3000)) {
          await d.click('#chart-avg-lease-value .apexcharts-menu-icon');
          await d.wait(1600);
          await d.moveTo(1500, 980);
          await d.page.mouse.click(1500, 980).catch(() => {}); // click empty space closes the menu
        } else {
          await d.hover('#chart-avg-lease-value', 1500);
        }
      },
    },
    {
      say: 'At the top of the page, the AI Report Generator builds a custom chart from a plain-English request, like seasonal revenue patterns by month. Pick a suggestion or type your own, then click Generate.',
      run: async (d) => {
        await d.scroll(-4000);
        await d.hover('button:has-text("Seasonal revenue patterns by month")', 1200);
        await d.hover('button:has-text("Generate")', 1600);
      },
    },
    {
      say: 'If a money panel shows Nothing billed yet, the period only holds drafts. Send those invoices and the panel fills in.',
      run: async (d) => { await d.highlight(panel('Revenue Forecast'), 'Drafts are not revenue', 3000); },
    },
  ],
};
