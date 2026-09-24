/*
 * Chapter 33 — Settings
 * Tour of every Settings tab: General (company, invoices & billing, lease charges, alerts,
 * currency/mileage/time-of-day cards), Design (brand colour previewed live then put back —
 * never saved), Users/Lockout pointers, Portal & Requests routing, Audit Log, System, Backup,
 * Integrations (incl. Security / MFA), Intelligence (AI core + Scheduled Jobs), Credit
 * Application and Customer Emails.
 *
 * Creates / changes NO records and saves NO settings: there are no commit clicks. Every
 * Save / Run Now / Connect / Generate backup / Test / Send-sample button is hovered only.
 * The only on-screen changes (brand-colour swatch preview, ticking a reminder type to reveal
 * its options) are client-side and are put back in the same scene without saving.
 *
 * Narration facts verified in code:
 *  - Each tab has its own permission (settings_* keys; $tabPermMap) — locked tabs show a padlock;
 *    Lockout is hidden entirely for non-super-admins.
 *  - Each General card is its own <form> with its own Save button (_group).
 *  - GST/HST + PST numbers print on invoice PDFs (InvoicePdfGenerator); bank / cheque /
 *    payment instructions show on portal invoice + payments pages.
 *  - invoices.approval_required gates Batch Invoicing (batch_generate.php); minimum billing days,
 *    sweep/wash defaults and fuel $/gal feed lease close; compliance warning/critical days feed
 *    cron/compliance_alerts.php; conversion factors are snapshotted per lease.
 *  - Brand colour is read by the admin + portal headers and the portal login page.
 *  - Scheduled Jobs: a disabled job exits on its next run; infra crons deliberately not listed;
 *    monthly invoice generation ships OFF (config/cron_jobs.php).
 *  - Customer Emails: three switches (master, dispatcher cron, per-type), every type ships OFF.
 *  - Portal request routing sends IN-APP notifications (PortalRequestNotifier).
 * Deliberately NOT narrated: settings with no code readers found (e.g. notifications.email_enabled,
 * invoice.late_fee_percentage, alerts.lease_end_reminder_days, yard.default, Design tab cards 2–5).
 */
const tab = (name) => `button.tab-btn:has-text("${name}")`;
// exact=true for headers whose text is a substring of another header ("Billing" vs "Invoices & Billing").
const card = (title, exact = false) => `.card:has(> .card-header:${exact ? 'text-is' : 'has-text'}("${title}"))`;

export default {
  title: 'Settings',
  subtitle: 'Company details, billing defaults, branding, integrations, automation and customer emails.',
  start: '/dashboard',
  intro: 'In this chapter we will tour Settings, the control panel for how Fleet Forge behaves for your whole company. We will look at every tab without changing anything.',
  outro: 'That covers Settings. Next, we will look at the customer portal your customers use.',
  scenes: [
    {
      say: 'Open Settings at the bottom of the sidebar. Settings changes affect everyone in the company, so most people will only ever see a few of these tabs.',
      run: async (d) => { await d.nav('Settings', '/settings'); },
    },
    {
      say: 'Each tab has its own permission. Tabs you are not allowed to open show a padlock. Super admins see them all.',
      run: async (d) => { await d.highlight('.tab-bar', 'One permission per tab', 3000); },
    },
    {
      say: 'Up top, How this works opens the help guide, Email Templates edits the wording of system emails, and Bulk Email writes to many customers at once.',
      run: async (d) => {
        await d.hover('.page-header-actions a:has-text("Email Templates")', 1100);
        await d.hover('.page-header-actions a:has-text("Bulk Email")', 1100);
      },
    },
    {
      say: 'The General tab starts with your company details: name, address, phone, email, website, currency and time zone. These appear on invoices, emails and the customer portal.',
      run: async (d) => { await d.highlight(card('Company'), 'Company profile', 3600); },
    },
    {
      say: 'Your G S T or H S T and P S T registration numbers print on every invoice. Tax rates themselves are managed under Accounting, in Tax.',
      caption: 'Your GST/HST and PST registration numbers print on every invoice. Tax rates themselves are managed under Accounting → Tax.',
      run: async (d) => {
        await d.highlight('#company\\.gst_number', 'GST / HST number', 1800);
        await d.highlight('#company\\.pst_number', 'PST number', 1600);
      },
    },
    {
      say: 'Bank details, cheque payable to, and payment instructions are shown to customers on their invoices in the portal.',
      run: async (d) => {
        await d.hover('#company\\.bank_name', 800);
        await d.hover('#company\\.check_payable_to', 800);
        await d.hover('#company\\.payment_instructions', 900);
      },
    },
    {
      say: 'Every card has its own Save button, and it saves only that card. Nothing changes until you click it.',
      run: async (d) => { await d.highlight('button:has-text("Save Company Settings")', 'Saves this card only', 2600); },
    },
    {
      say: 'Invoices and Billing sets the number prefixes for invoices, payments and credit notes, the default number of days until payment is due, and how far ahead a lease may be billed in advance.',
      run: async (d) => {
        await d.highlight(card('Invoices & Billing'), 'Invoices & Billing', 2400);
        await d.hover('#invoice\\.due_days_default', 900);
        await d.hover('#billing\\.max_advance_periods', 900);
      },
    },
    {
      say: 'Turn on Require approval before batch billing and a batch invoicing run must be submitted and approved before invoices are created. You can also stop people approving their own runs.',
      run: async (d) => {
        await d.hover('#invoices\\.approval_required', 1400);
        await d.hover('#invoices\\.approval_allow_self', 1400);
      },
    },
    {
      say: 'The Billing card holds lease charges: the minimum billed days for short rentals and which equipment categories it applies to, plus the default sweep and wash charges and the fuel rate per gallon used when a lease is closed.',
      run: async (d) => {
        await d.highlight(card('Billing', true), 'Lease charges', 2600);
        await d.hover('#lease\\.minimum_billing_days', 900);
        await d.hover('#lease\\.fuel_rate_per_gallon', 900);
      },
    },
    {
      say: 'Alerts and Compliance controls how many days before a document expires it turns into a warning, and then a critical alert.',
      run: async (d) => {
        await d.highlight(card('Alerts & Compliance'), 'Expiry alert thresholds', 2400);
        await d.hover('#alerts\\.compliance_warning_days', 900);
      },
    },
    {
      say: 'Further down: the U S dollar to Canadian dollar markup used on U S dollar invoices, the kilometre and mile conversion factors copied into each new lease, and the grace window for returns on leases with a pickup time.',
      caption: 'Further down: the USD → CAD markup used on USD invoices, the km/mile conversion factors copied into each new lease, and the return grace window for leases with a pickup time.',
      run: async (d) => {
        await d.highlight(card('Currency Conversion'), 'USD → CAD markup', 2000);
        await d.highlight(card('Mileage Conversion Factors'), 'Snapshotted per lease', 2000);
        await d.highlight(card('Lease Time-of-Day Billing'), 'Return grace window', 1800);
      },
    },
    {
      say: 'The Design tab sets your brand. Pick a colour and the whole screen previews it straight away. It is only saved for everyone when you click Save Brand Colour, so we will put it back.',
      caption: 'The Design tab sets your brand. Pick a colour and the whole screen previews it straight away. It is only saved for everyone when you click Save Brand Color, so we will put it back.',
      run: async (d) => {
        await d.tab('Design');
        await d.wait(600);
        await d.click('button:has-text("Fleet Green")');
        await d.wait(1800);
        await d.click('button:has-text("Steel Blue")');
        await d.wait(700);
      },
    },
    {
      say: 'The logo and favicon are uploaded here too. The logo appears in the sidebar, on the login page and in the customer portal. The remaining Design cards store display, regional, P D F and interface preferences.',
      caption: 'The logo and favicon are uploaded here too. The logo appears in the sidebar, on the login page and in the customer portal. The remaining Design cards store display, regional, PDF and interface preferences.',
      run: async (d) => {
        await d.hover('button:has-text("Save Logo")', 1200);
        await d.scroll(1600); await d.wait(700); await d.scroll(-1600);
      },
    },
    {
      say: 'The Users tab links to the Users module, and Lockout is the emergency access control. Both are covered in the Users chapter.',
      run: async (d) => {
        await d.tab('Users');
        await d.wait(700);
        await d.hover(tab('Lockout'), 1200);
      },
    },
    {
      say: 'Portal and Requests decides who is notified inside Fleet Forge when a customer submits a service request from the portal. Choose roles or named people for each request type.',
      run: async (d) => {
        await d.tab('Portal & Requests');
        await d.wait(700);
        await d.highlight(card('Service Request Notification Routing'), 'Who hears about portal requests', 3200);
      },
    },
    {
      say: 'Keep Always include super admins ticked so no request is missed. The default bucket is used for any type you leave empty.',
      run: async (d) => { await d.hover('[name="portal_requests.routing.always_include_super_admin"]', 1800); },
    },
    {
      say: 'The Audit Log tab is a filterable history of every change in the system: who did it, when, and from which IP address.',
      run: async (d) => {
        await d.tab('Audit Log');
        await d.wait(800);
        await d.highlight('table thead', 'Change history', 2400);
      },
    },
    {
      say: 'System shows health checks, server and database details, and when each background task last ran. Run Now starts a task immediately, so leave that to your administrator.',
      run: async (d) => {
        await d.tab('System');
        await d.wait(800);
        await d.highlight(card('Health Checks'), 'Health checks', 2200);
        await d.hover('button:has-text("Run Now")', 1400);
      },
    },
    {
      say: 'Backup shows the three backup destinations: automatic A W S backups, an optional Dropbox copy, and on-demand full backups, with a history of recent runs.',
      caption: 'Backup shows the three backup destinations: automatic AWS backups, an optional Dropbox copy, and on-demand full backups, with a history of recent runs.',
      run: async (d) => {
        await d.tab('Backup');
        await d.wait(800);
        await d.hover('a:has-text("Connect Dropbox")', 1000);
        await d.hover('button:has-text("Generate full backup")', 1200);
      },
    },
    {
      say: 'Integrations holds the connections to outside services: G P S tracking, outgoing email, file storage and A W S. Keys are masked; to replace one, paste the new key over it and save.',
      caption: 'Integrations holds the connections to outside services: GPS tracking, outgoing email, file storage and AWS. Keys are masked; to replace one, paste the new key over it and save.',
      run: async (d) => {
        await d.tab('Integrations');
        await d.wait(800);
        await d.highlight(card('GPS Integration'), 'Sensitive credentials', 2600);
      },
    },
    {
      say: 'Test Connection checks a service without changing anything, and Security and M F A sets which roles must use two-factor sign-in. Only change these with your administrator.',
      caption: 'Test Connection checks a service without changing anything, and Security / MFA sets which roles must use two-factor sign-in. Only change these with your administrator.',
      run: async (d) => {
        await d.hover('button:has-text("Test Connection")', 1200);
        await d.highlight(card('Security / MFA'), 'Two-factor requirements', 2400);
      },
    },
    {
      say: 'Intelligence controls the A I features. A I features enabled is the master switch, and you can also set the model and a daily usage limit.',
      caption: 'Intelligence controls the AI features. AI Features Enabled is the master switch, and you can also set the model and a daily usage limit.',
      run: async (d) => {
        await d.tab('Intelligence');
        await d.wait(800);
        await d.highlight(card('AI Core'), 'AI configuration', 2400);
        await d.hover('#ai\\.enabled', 1000);
      },
    },
    {
      say: 'Scheduled Jobs turns each automatic background job on or off, such as marking invoices overdue or compliance alerts. A job that is switched off simply skips its next run.',
      run: async (d) => { await d.highlight(card('Scheduled Jobs'), 'Background automation', 3400); },
    },
    {
      say: 'Monthly invoice generation is off by default, so monthly invoices are only created when someone runs billing. Backups and QuickBooks sync are deliberately not listed, so they cannot be switched off by accident.',
      run: async (d) => {
        await d.hover('#cron\\.invoice_generate_monthly_enabled', 1600);
        await d.hover('button:has-text("Save Scheduled Jobs")', 1400);
      },
    },
    {
      say: 'Further down are the morning briefing email, who receives it, delivery by Slack or text message, and A I cost tracking.',
      caption: 'Further down are the morning briefing email, who receives it, delivery by Slack or text message, and AI cost tracking.',
      run: async (d) => {
        await d.highlight(card('Morning Briefing'), 'Morning briefing', 1800);
        await d.highlight(card('Token Analytics'), 'Usage and cost', 1800);
      },
    },
    {
      say: 'Credit Application sets how long a credit application link stays valid, where applicants send references and insurance, and the terms they agree to. Below is a log of every application sent.',
      run: async (d) => {
        await d.tab('Credit Application');
        await d.wait(800);
        await d.hover('#cca_credit_application\\.token_expiry_days', 1000);
        await d.hover('a:has-text("Preview Form"), button:has-text("Preview Form")', 900);
        await d.highlight('table thead', 'Applications sent', 1600);
      },
    },
    {
      say: 'Customer Emails controls every automatic reminder sent to customers. Three switches must all be on for one to go out: the master switch, the reminder dispatcher, and that reminder’s own toggle.',
      run: async (d) => {
        await d.tab('Customer Emails');
        await d.wait(900);
        await d.highlight(card('Global settings'), 'Master controls', 3200);
      },
    },
    {
      say: 'Set the send hour and days, a reply-to address, and a B C C copy for yourself. Customers on the do-not-email list never receive any reminder.',
      caption: 'Set the send hour and days, a reply-to address, and a BCC copy for yourself. Customers on the do-not-email list never receive any reminder.',
      run: async (d) => {
        await d.hover('#cn_send_hour', 900);
        await d.hover('#cn_bcc', 900);
        await d.highlight(card('Do-not-email list'), 'Never email these customers', 1800);
      },
    },
    {
      say: 'Every reminder type ships switched off. Ticking one reveals its channels, timing, subject line and which customers receive it. We are not saving this, so we untick it again.',
      run: async (d) => {
        const box = '.card:has-text("Invoice due soon") input[x-model="types[k].enabled"]';
        await d.click(box);
        await d.wait(1600);
        await d.scroll(350);
        await d.wait(900);
        await d.click(box);
        await d.wait(500);
      },
    },
    {
      say: 'Send me a sample emails a preview of that reminder to you, not to a customer. Save Customer Email Settings applies your changes.',
      run: async (d) => {
        await d.hover('.card:has-text("Invoice due soon") button:has-text("Send me a sample")', 1400);
        await d.hover('button:has-text("Save Customer Email Settings")', 1400);
      },
    },
  ],
};
