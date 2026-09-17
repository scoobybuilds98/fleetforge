/*
 * Chapter 31 — QuickBooks Online Sync
 * The QuickBooks section: dashboard (connection, KPIs, activity), sync queue, sync log,
 * drift detection, manual sync, the reference-data mapping pages (customers, vendors,
 * chart of accounts, tax codes, bank accounts, items), the per-transaction push pages
 * (invoices, credit memos, refund receipts, bills, bill payments, payments, journal entries),
 * the drift event detail page (opened via the first row's View link), and QuickBooks settings /
 * master controls. Retry buttons on push pages and Resolve/Accept/Suppress on the drift event
 * are hovered only.
 *
 * Records created: none. PURE TOUR — nothing that talks to QuickBooks or writes a mapping
 * is clicked. Hovered only: Test Connection, Refresh Token Now, Retry/Delete/Clear (queue),
 * Run drift check now, Resolve, Force re-sync / Force pull / Reset SyncToken, Pull from
 * QuickBooks, Auto-Match, Unlink, Ignore, Link to QBO…, Verify mappings, Run CDC now,
 * Disconnect, Save Credentials / Master Controls. The credentials card is scrolled past
 * without highlighting (values are masked to the last 4 characters anyway).
 * Only filters (GET-only list reloads) and page navigation are clicked.
 */

const qboNav = (label) => `.acc-topnav a:text-is("${label}"), .acc-topnav a:has-text("${label}")`;

export default {
  title: 'QuickBooks Online Sync',
  subtitle: 'How FleetForge records reach QuickBooks — mappings, the sync queue, drift checks and the master switches.',
  start: '/dashboard',
  intro: 'In this chapter we will look at the QuickBooks section: how Fleet Forge records are matched to QuickBooks, how changes queue up and sync, how to spot and resolve differences, and the controls that switch syncing on and off.',
  outro: 'That covers QuickBooks Online sync, and completes the accounting chapters.',
  scenes: [
    {
      say: 'Open QuickBooks from the left sidebar. Fleet Forge runs its own books, and this section keeps a QuickBooks Online company in step with them for your accountant.',
      caption: 'Open QuickBooks from the left sidebar. FleetForge runs its own books, and this section keeps a QuickBooks Online company in step with them for your accountant.',
      run: async (d) => { await d.nav('QuickBooks', '/quickbooks/dashboard'); await d.wait(1500); },
    },
    {
      say: 'The strip at the top shows whether the connection is live, whether it points at a sandbox or the real company, and how many days remain before the connection must be renewed.',
      run: async (d) => {
        await d.highlight('.card:has(code):has-text("Realm")', 'Connection status', 2800);
      },
    },
    {
      say: 'The cards show items waiting in the queue, unresolved differences between the two systems, API calls in the last day, and the master sync switch, which is off until go-live.',
      run: async (d) => {
        await d.highlight('.kpi-grid--qbo', 'Queue, drift, activity, master switch', 3400);
      },
    },
    {
      say: 'Below are fourteen days of sync activity and a feed of the latest calls: direction, record type, operation, result and how long it took.',
      caption: 'Below are 14 days of sync activity and a feed of the latest calls: direction, record type, operation, result and how long it took.',
      run: async (d) => {
        await d.scroll(650);
        await d.wait(1600);
      },
    },
    {
      say: 'Quick Actions test the connection and refresh the access token. Both call QuickBooks directly, so only use them when troubleshooting.',
      run: async (d) => {
        await d.scroll(700);
        await d.hover('button:has-text("Test Connection")', 1200);
        await d.hover('button:has-text("Refresh Token Now")', 1200);
        await d.scroll(-1400);
      },
    },
    {
      say: 'Sync Queue holds records waiting to go to QuickBooks. Sending an invoice or approving a bill adds an item here, and a background job works through up to ten items every minute.',
      run: async (d) => {
        await d.click(qboNav('Sync Queue'), { nav: true });
        await d.wait(1200);
        await d.hover('[x-model="filters.status"]', 800);
        await d.hover('[x-model="filters.entity"]', 800);
      },
    },
    {
      say: 'Failed items can be retried once the cause is fixed. Clear Completed and Clear Failed tidy up old rows; they do not undo anything in QuickBooks.',
      run: async (d) => {
        await d.hover('button:has-text("Retry Selected")', 1000);
        await d.hover('button:has-text("Clear Completed")', 900);
        await d.hover('button:has-text("Clear Failed")', 900);
      },
    },
    {
      say: 'Sync Log is the full history of every call to QuickBooks. Filter by direction, record type, status, error code or date, and click a row to see the details.',
      run: async (d) => {
        await d.click(qboNav('Sync Log'), { nav: true });
        await d.wait(1000);
        await d.select('[x-model="filters.range"]', '90');
        await d.click('button:has-text("Apply")');
        await d.wait(2000);
      },
    },
    {
      say: 'Drift lists places where Fleet Forge and QuickBooks disagree, such as an invoice total that differs after a push. Show all ranges to see everything still open.',
      caption: 'Drift lists places where FleetForge and QuickBooks disagree, such as an invoice total that differs after a push. Show all ranges to see everything still open.',
      run: async (d) => {
        await d.click(qboNav('Drift'), { nav: true });
        await d.wait(1200);
        await d.select('[x-model="filters.range"]', 'all');
        await d.wait(2200);
      },
    },
    {
      say: 'Investigate each event, then Resolve it with a note explaining what you found. Bulk resolve handles a whole category at once. Run Drift Check Now re-scans on demand.',
      run: async (d) => {
        if (await d.exists('button:has-text("Resolve")', 1500)) await d.hover('button:has-text("Resolve")', 1200);
        await d.hover('[x-model="bulk.category"]', 900);
        await d.hover('button:has-text("Run drift check now")', 1100);
      },
    },
    {
      say: 'View opens a single event: its category, record type, the Fleet Forge and QuickBooks values side by side, and the difference between them.',
      caption: 'View opens a single event: its category, record type, the FleetForge and QuickBooks values side by side, and the difference between them.',
      run: async (d) => {
        await d.click('table tbody tr >> nth=0 >> a:has-text("View")', { nav: true });
        await d.wait(1200);
        await d.highlight('.card:has(h2:has-text("Drift Details"))', 'Drift details', 2600);
      },
    },
    {
      say: 'Resolve asks for a short note explaining what you found, and records who resolved it and when. Accept marks an intentional difference, and Suppress hides a known false alarm.',
      run: async (d) => {
        await d.hover('.card:has(h2:has-text("Actions")) button:has-text("Resolve")', 1300);
        if (await d.exists('button:has-text("Accept")', 1000)) await d.hover('button:has-text("Accept")', 900);
        if (await d.exists('button:has-text("Suppress")', 1000)) await d.hover('button:has-text("Suppress")', 900);
      },
    },
    {
      say: 'Manual Sync holds the bulk tools. Force re-sync re-queues every mapped record of one type, but still obeys the master switch, so nothing is sent while sync is off.',
      run: async (d) => {
        await d.click(qboNav('Manual Sync'), { nav: true });
        await d.wait(1000);
        await d.hover('tr:has-text("Invoices") button:has-text("Force re-sync")', 1600);
      },
    },
    {
      say: 'Force pull refreshes reference data from QuickBooks, and Reset Sync Token is a recovery tool after moving to a different QuickBooks company. These are for administrators only.',
      caption: 'Force pull refreshes reference data from QuickBooks, and Reset SyncToken is a recovery tool after moving to a different QuickBooks company. Administrators only.',
      run: async (d) => {
        await d.scroll(900);
        await d.hover('button:has-text("Force pull")', 1100);
        await d.hover('button:has-text("Reset SyncToken")', 1100);
        await d.scroll(-900);
      },
    },
    {
      say: 'The mapping pages link each Fleet Forge record to its QuickBooks twin. On Customers, mapped means linked on both sides; F F only or Q B O only means a counterpart is still missing.',
      caption: 'The mapping pages link each FleetForge record to its QuickBooks twin. On Customers, Mapped means linked on both sides; FF only or QBO only means a counterpart is still missing.',
      run: async (d) => {
        await d.click(qboNav('Customers'), { nav: true });
        await d.wait(1800);
        await d.highlight('table thead', 'FF customer ↔ QBO customer', 2000);
      },
    },
    {
      say: 'Pull from QuickBooks fetches their current list, and Auto-Match pairs records by name, email and phone with a confidence score. Review low-confidence matches, and use Unlink or Ignore to correct them.',
      run: async (d) => {
        await d.hover('button:has-text("Pull from QuickBooks")', 1100);
        await d.hover('button:has-text("Auto-Match")', 1100);
        await d.hover('table tbody tr >> nth=0 >> button:has-text("Unlink")', 900);
      },
    },
    {
      say: 'Vendors works the same way for suppliers, so that approved bills and bill payments land on the right QuickBooks vendor.',
      run: async (d) => {
        await d.click(qboNav('Vendors'), { nav: true });
        await d.wait(1600);
      },
    },
    {
      say: 'Accounts maps the chart of accounts one way: the accountant owns the chart in QuickBooks. The red banner counts critical accounts that must be mapped before invoices and journal entries can be pushed.',
      run: async (d) => {
        await d.click(qboNav('Accounts'), { nav: true });
        await d.wait(1800);
        if (await d.exists(':text("critical accounts unmapped")', 1500)) await d.highlight(':is(.alert, div):has-text("critical accounts unmapped") >> nth=-1', 'Critical accounts', 2400);
        await d.hover('table tbody tr >> nth=0 >> button:has-text("Link to QBO")', 900);
      },
    },
    {
      say: 'Tax Codes maps each Fleet Forge G S T and P S T rate to a QuickBooks tax code, and sets the tax rates used when bills with input tax credits are pushed.',
      caption: 'Tax Codes maps each FleetForge GST/PST rate to a QuickBooks tax code, and sets the tax rates used when bills with input tax credits are pushed.',
      run: async (d) => {
        await d.click(qboNav('Tax Codes'), { nav: true });
        await d.wait(1800);
        await d.scroll(600);
        await d.scroll(-600);
      },
    },
    {
      say: 'Bank Accounts links each Fleet Forge bank account to its QuickBooks bank account. Every bank account must be mapped before customer payments and bill payments can sync.',
      caption: 'Bank Accounts links each FleetForge bank account to its QuickBooks bank account. Every bank account must be mapped before customer payments and bill payments can sync.',
      run: async (d) => {
        await d.click(qboNav('Bank Accounts'), { nav: true });
        await d.wait(1600);
        await d.hover('button:has-text("Verify mappings")', 1000);
      },
    },
    {
      say: 'Items maps every kind of invoice line, such as base rental, mileage, fees and recoveries, to a QuickBooks product or service. All of them need a match before invoices can be pushed.',
      run: async (d) => {
        await d.click(qboNav('Items'), { nav: true });
        await d.wait(1800);
        await d.scroll(500);
        await d.scroll(-500);
      },
    },
    {
      say: 'The remaining pages, from Invoices through Journal Entries, show push status for each transaction type. Invoices, for example, shows which were pushed, pending, failed or skipped, with a retry for failures.',
      run: async (d) => {
        await d.click(qboNav('Invoices'), { nav: true });
        await d.wait(1800);
        await d.highlight('table thead', 'Push status per invoice', 2200);
      },
    },
    {
      say: 'Credit Memos lists the credit notes created in Fleet Forge, each queued as a QuickBooks credit memo, with its customer, source and amount. A failed push usually means an unmapped customer or item.',
      caption: 'Credit Memos lists the credit notes created in FleetForge, each queued as a QuickBooks credit memo, with its customer, source and amount. A failed push usually means an unmapped customer or item.',
      run: async (d) => {
        await d.click(qboNav('Credit Memos'), { nav: true });
        await d.wait(1600);
        await d.highlight('.kpi-grid--qbo', 'Push status counts', 2000);
      },
    },
    {
      say: 'Refund Receipts covers cash refunds of lease precharges. Marking a refund settled on the lease queues it here; refunds taken as credit go through Credit Memos instead.',
      run: async (d) => {
        await d.click(qboNav('Refund Receipts'), { nav: true });
        await d.wait(1600);
        await d.highlight('table thead', 'Lease, customer, refund amount, status', 2000);
      },
    },
    {
      say: 'Bills shows each vendor bill queued when it is approved in Fleet Forge accounting. The tiles count pushed, pending, failed and skipped bills, and failed rows get a Retry button once the cause is fixed.',
      caption: 'Bills shows each vendor bill queued when it is approved in FleetForge accounting. The tiles count pushed, pending, failed and skipped bills, and failed rows get a Retry button once the cause is fixed.',
      run: async (d) => {
        await d.click(qboNav('Bills'), { nav: true });
        await d.wait(1600);
        await d.highlight('.kpi-grid--qbo', 'Push status counts', 2000);
      },
    },
    {
      say: 'Bill Payments lists vendor payments, queued when a bill payment is recorded, with the pay type, bank account and the bill it pays. Pre-flight failures name the problem, such as a currency mismatch.',
      run: async (d) => {
        await d.click(qboNav('Bill Payments'), { nav: true });
        await d.wait(1600);
        await d.highlight('table thead', 'Pay type, bank account, linked bill', 2000);
      },
    },
    {
      say: 'Payments works in both directions: customer payments recorded in Fleet Forge are pushed, and payments made online through QuickBooks are pulled in. Retry is only offered for payments that started in Fleet Forge, to avoid duplicates.',
      caption: 'Payments works in both directions: customer payments recorded in FleetForge are pushed, and payments made online through QuickBooks are pulled in. Retry is only offered for payments that started in FleetForge, to avoid duplicates.',
      run: async (d) => {
        await d.click(qboNav('Payments'), { nav: true });
        await d.wait(1600);
        await d.highlight('table thead', 'Origin and status per payment', 2200);
      },
    },
    {
      say: 'Journal Entries lists entries queued when they are posted, such as fixed asset and tax remittance entries. Entries created by invoices, payments, credit notes and bills are skipped here, because those records push on their own pages.',
      run: async (d) => {
        await d.click(qboNav('Journal Entries'), { nav: true });
        await d.wait(1600);
        await d.hover('button:has-text("Fixed Asset")', 900);
        await d.highlight('.kpi-grid--qbo', 'Push status counts', 2000);
      },
    },
    {
      say: 'Settings starts with the connection: environment, company I D and token dates. Disconnect breaks the link to QuickBooks, so never press it unless the administrator asks you to.',
      caption: 'Settings starts with the connection: environment, company ID and token dates. Disconnect breaks the link to QuickBooks — never press it unless the administrator asks.',
      run: async (d) => {
        await d.click(qboNav('Settings'), { nav: true });
        await d.wait(1500);
        await d.hover('button:has-text("Disconnect")', 2000);
      },
    },
    {
      say: 'Company Detection shows the home currency and whether multi-currency is on, which decides how U S dollar records are sent. Credentials below are always masked.',
      caption: 'Company Detection shows the home currency and whether multi-currency is on, which decides how USD records are sent. Credentials below are always masked.',
      run: async (d) => {
        await d.highlight('.card:has(h3:has-text("Company Detection"))', 'Company detection', 2200);
        // Glide past the credentials card straight to Master Controls (smooth, so it reads as a scroll).
        await d.page.evaluate(() => [...document.querySelectorAll('.card h3')].find((h) => h.textContent.includes('Master Controls'))?.closest('.card').scrollIntoView({ behavior: 'smooth', block: 'center' }));
        await d.wait(1200);
      },
    },
    {
      say: 'Master Controls are for the super admin. The kill switch stops all syncing in both directions, Dry Run logs pushes without sending them, and QuickBooks Payments turns on the Pay Online button in the customer portal.',
      run: async (d) => {
        await d.highlight('.card:has(h3:has-text("Master Controls"))', 'Master controls', 3000);
      },
    },
    {
      say: 'Last, the per-entity table shows how each record type behaves once sync is on: queued, immediate, pull only, or disabled. It is read-only here.',
      run: async (d) => {
        await d.scroll(700);
        await d.highlight('.card:has(h3:has-text("Per-Entity Sync Modes"))', 'Per-entity sync modes', 2400);
      },
    },
  ],
};
