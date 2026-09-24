/*
 * Chapter 02 — Customers
 * Directory, filters, the customer 360 page and every tab, then creating a customer.
 * The customer created on camera is named "Harbourview Logistics Ltd." so it is easy to purge.
 */
const tab = (name) => `main button:has-text("${name}"), .content button:has-text("${name}"), button:has-text("${name}")`;

export default {
  title: 'Customers',
  subtitle: 'Your master list of every company you rent to — contacts, credit, billing preferences and full history.',
  start: '/dashboard',
  intro: 'In this chapter we will look at the Customers module: finding a customer, reading their account page, and adding a new customer.',
  outro: 'That covers Customers. Next, we will set up the equipment you rent to them.',
  scenes: [
    {
      say: 'Open Customers from the left sidebar. This list shows every company you do business with.',
      run: async (d) => { await d.nav('Customers', '/customers'); },
    },
    {
      say: 'The tiles across the top show total and active customers, the overdue balance across all accounts, and anyone on credit hold.',
      run: async (d) => {
        await d.highlight('.stat-grid, .stat-card >> nth=0', 'Summary tiles', 2600);
      },
    },
    {
      say: 'Each row shows the company, its main contact, location, status, risk level, how many leases they have, and what they owe.',
      run: async (d) => { await d.highlight('table thead', 'Customer list', 2200); await d.scroll(500); await d.scroll(-500); },
    },
    {
      say: 'To find someone fast, type in the search box. It matches company name, contact, email, and D O T or M C number.',
      caption: 'To find someone fast, type in the search box. It matches company name, contact, email, and DOT or MC number.',
      run: async (d) => { await d.type('[x-model="filters.search"]', 'Summit'); await d.wait(1200); },
    },
    {
      say: 'You can also filter by status or risk level, and change the sort order.',
      run: async (d) => {
        await d.type('[x-model="filters.search"]', '');
        await d.hover('[x-model="filters.status"]', 500);
        await d.hover('[x-model="filters.risk_score"]', 500);
        await d.hover('[x-model="filters.sort"]', 500);
      },
    },
    {
      say: "Click a company name to open its account page. Let's open Summit Carriers.",
      run: async (d) => { await d.click('table tbody a:has-text("Summit Carriers")', { nav: true }); },
    },
    {
      say: 'The header shows the account status, its risk rating, and whether it is synced to QuickBooks.',
      run: async (d) => { await d.highlight('h1', 'Status badges', 2600); },
    },
    {
      say: 'Below that are the key numbers: active leases, outstanding balance, lifetime revenue, and any account credit. Click a tile to jump to the details behind it.',
      run: async (d) => { await d.highlight('.stat-grid', 'Account at a glance', 3200); },
    },
    {
      say: 'The Overview tab holds contact details, address, tax and regulatory numbers, and the billing contact who receives invoices.',
      run: async (d) => { await d.scroll(450); await d.wait(900); await d.scroll(-450); },
    },
    {
      say: "The Leases tab lists every contract for this customer, current and past. Filter by status, or click View to open one.",
      run: async (d) => { await d.tab('Leases'); await d.wait(700); await d.highlight('table', 'Leases for this customer', 2200); },
    },
    {
      say: 'The Invoices tab shows every invoice with its billing period, status, due date, and remaining balance.',
      run: async (d) => { await d.tab('Invoices'); await d.wait(700); await d.scroll(400); await d.scroll(-400); },
    },
    {
      say: 'Credit Application tracks the credit form sent to this customer: when it went out, whether it came back, and the decision.',
      run: async (d) => { await d.tab('Credit Application'); await d.wait(1200); },
    },
    {
      say: 'Rates holds any customer-specific rate card. When one exists, new leases for this customer use those prices instead of the standard rates.',
      run: async (d) => { await d.tab('Rates'); await d.wait(1500); },
    },
    {
      say: 'Documents, Damage Claims, Mileage Logs and Email History each gather that customer’s records in one place, so you never have to search other modules.',
      caption: 'Documents, Damage Claims, Mileage Logs and Email History each gather that customer’s records in one place.',
      run: async (d) => {
        await d.tab('Damage Claims'); await d.wait(900);
        await d.tab('Email History'); await d.wait(900);
      },
    },
    {
      say: 'Use Notes for anything the team should know about the account. Activity is an automatic log of every change and who made it.',
      run: async (d) => {
        await d.tab('Notes'); await d.wait(600);
        await d.type('[x-model="newNote"]', 'Prefers invoices on the 1st. Call Kyle before any unit swap.', { delay: 28 });
        await d.wait(600);
        await d.tab('Activity'); await d.wait(1200);
      },
    },
    {
      say: 'At the top right, Send Email writes to the customer from inside the app, and AI Analysis summarizes their payment behaviour and risk. Edit changes any of the account details.',
      run: async (d) => {
        await d.hover('button:has-text("Send Email")', 900);
        await d.hover('button:has-text("AI Analysis")', 900);
        await d.hover('a:has-text("Edit"), button:has-text("Edit")', 900);
      },
    },
    {
      say: 'Click Edit to open the account form. It has the same sections as a new customer: identity and status, address, regulatory and tax numbers, billing contact, commercial terms, and tags.',
      run: async (d) => {
        await d.click('.page-header a.btn:text-is("Edit"), a.btn:text-is("Edit")', { nav: true });
        await d.wait(900);
        await d.hover('#status', 700);
        await d.scroll(700);
        await d.hover('#gst_number', 600);
        await d.scroll(700);
        await d.hover('#payment_terms', 700);
      },
    },
    {
      say: 'Status can only move along allowed steps; for example, an account on credit hold can go back to active or be suspended. Save Changes updates the account. We will click Cancel.',
      run: async (d) => {
        await d.hover('button:has-text("Save Changes")', 900);
        await d.click('a.btn:has-text("Cancel")', { nav: true });
        await d.wait(700);
      },
    },
    {
      say: "Now let's add a new customer. Go back to the list and click New Customer.",
      run: async (d) => {
        await d.nav('Customers', '/customers');
        await d.click('a:has-text("New Customer"), button:has-text("New Customer")', { nav: true });
      },
    },
    {
      say: 'Only the company name is required, but fill in as much as you can. Start with the company and its primary contact.',
      run: async (d) => {
        await d.type('#company_name', 'Harbourview Logistics Ltd.');
        await d.type('#contact_name', 'Priya Sandhu');
        await d.type('#email', 'dispatch@harbourview-logistics.ca', { delay: 22 });
        await d.type('#phone', '604-555-0142');
      },
    },
    {
      say: 'Set the status and risk level, then enter the address.',
      run: async (d) => {
        await d.select('#status', { index: 0 });
        await d.type('#address', '2250 Commissioner St');
        await d.type('#city', 'Vancouver');
        await d.type('#province', 'BC');
        await d.type('#postal_code', 'V5L 1A4');
      },
    },
    {
      say: 'The billing section controls how this customer is invoiced: who receives invoices, the currency, mileage unit, payment terms, credit limit and billing cycle.',
      run: async (d) => {
        await d.scroll(700);
        await d.type('#invoice_email', 'ap@harbourview-logistics.ca', { delay: 22 });
        await d.select('#currency', 'CAD').catch(() => {});
        await d.type('#credit_limit', '50000');
        await d.hover('#billing_cycle', 700);
      },
    },
    {
      say: 'Currency matters: a US-dollar customer is invoiced in U S D, and reports convert it back to Canadian dollars automatically.',
      caption: 'Currency matters: a USD customer is invoiced in USD, and reports convert it back to CAD automatically.',
      run: async (d) => { await d.highlight('#currency', 'Invoice currency', 2400); },
    },
    {
      say: 'When everything looks right, click Create Customer. The new account opens, ready for its first lease.',
      run: async (d) => {
        await d.scroll(1500);
        await d.click('button:has-text("Create Customer")', { nav: true, commit: true });
        await d.wait(1500);
      },
    },
  ],
};
