/*
 * Chapter 25 — Email, Messaging & Notifications
 * Customer email (Compose modal on Summit Carriers Ltd.: templates, variables, attachments,
 * preview), Email History, Email Templates, Bulk Email wizard, automated Customer Emails
 * settings, Messenger (customer portal inbox), Team Chat, the notification bell and
 * Notifications page, and where notification preferences live.
 * Creates no records: Send / Send Email / Mark all read / Clear all read / Save are only
 * hovered. The invoice attachment added in Compose is client-side and discarded on Cancel.
 *
 * Inline helper: `glide` smooth-scrolls an inner scroller (the compose modal body) so the
 * preview and attachment list come into view without wheel events leaking to the page.
 */
const glide = async (d, sel, top, ms = 1200) => {
  await d.page.evaluate(([s, t]) => {
    const el = [...document.querySelectorAll(s)].find((e) => e.scrollHeight > e.clientHeight + 4) || document.querySelector(s);
    if (el) el.scrollTo({ top: t === 'bottom' ? el.scrollHeight : t, behavior: 'smooth' });
  }, [sel, top]).catch(() => {});
  await d.wait(ms);
};
const modal = '#ff-email-compose';
const scroller = `${modal} .modal-body, ${modal} .modal`;

export default {
  title: 'Email, Messaging & Notifications',
  subtitle: 'Emailing customers, chatting with customers and your team, and staying on top of alerts.',
  start: '/dashboard',
  intro: 'In this chapter we will cover how you communicate from FleetForge: emailing customers one at a time or in bulk, messaging customers and teammates, and managing your notifications.',
  outro: 'That covers email, messaging and notifications.',
  scenes: [
    {
      say: 'Most customer emails start from the customer’s own page. Open Customers and click Summit Carriers.',
      run: async (d) => {
        await d.nav('Customers', '/customers');
        await d.click('table tbody a:has-text("Summit Carriers")', { nav: true });
      },
    },
    {
      say: 'Click Send Email at the top right. The compose window opens with the customer’s primary contact already filled in.',
      run: async (d) => {
        await d.click('button:has-text("Send Email")');
        await d.wait(1500);
        await d.highlight(`${modal} input[x-model="toEmail"]`, 'Primary contact', 1800);
      },
    },
    {
      say: 'Quick fill lists the customer’s other contacts, so you can switch recipients with one click. Reply-to is optional; leave it blank to use the company’s default sender.',
      run: async (d) => {
        await d.hover(`${modal} .email-chip-label`, 900);
        await d.hover(`${modal} input[x-model="replyTo"]`, 1500);
      },
    },
    {
      say: 'Choose a template to start from saved wording. The subject and message fill in, with this customer’s details already swapped in, like the contact’s name.',
      run: async (d) => {
        await d.select(`${modal} select[x-model="selectedTemplateId"]`, { label: 'General Message (general)' });
        await d.wait(1800);
        await d.highlight(`${modal} textarea[x-model="bodyHtml"]`, 'Filled from template', 2600);
      },
    },
    {
      say: 'Or write your own. Type a clear subject and a short message. The message is sent as H T M L, so use paragraph tags if you need separate paragraphs.',
      caption: 'Or write your own. Type a clear subject and a short message. The message is sent as HTML, so use paragraph tags if you need separate paragraphs.',
      run: async (d) => {
        await d.select(`${modal} select[x-model="selectedTemplateId"]`, { index: 0 });
        await d.type(`${modal} input[x-model="subject"]`, 'Unit swap for TR-7003 scheduled Friday', { delay: 30 });
        await d.type(`${modal} textarea[x-model="bodyHtml"]`,
          'Hi Kyle, we have booked the swap of TR-7003 for Friday morning at your Kamloops yard. Please have the trailer empty by 8 a.m.', { delay: 16 });
      },
    },
    {
      say: 'Show variables lists placeholders such as customer name or company phone. Click one to insert it; it is replaced with real details when the email goes out.',
      run: async (d) => {
        await d.click(`${modal} button:has-text("Show variables")`);
        await d.wait(700);
        await d.highlight(`${modal} .email-variables-label`, 'Click to insert', 2200);
      },
    },
    {
      say: 'Attach files from the customer’s Documents, attach an invoice P D F, or upload a file from your computer. Attachments really are delivered; if one cannot be read, the email fails instead of going out without it.',
      caption: 'Attach files from the customer’s Documents, attach an invoice PDF, or upload a file from your computer. Attachments really are delivered; if one cannot be read, the email fails instead of going out without it.',
      run: async (d) => {
        await d.click(`${modal} button:has-text("Attach Invoice PDF")`);
        await d.wait(1500);
        await glide(d, scroller, 'bottom', 1200);
        const inv = `${modal} .email-picker-item:has(.email-picker-item-name:text-matches("^INV-"))`;
        if (await d.exists(inv, 4000)) { await d.click(inv); await d.wait(900); }
        await d.hover(`${modal} button:has-text("From Documents")`, 700);
        await d.hover(`${modal} label:has-text("Upload File")`, 700);
      },
    },
    {
      say: 'Click Preview Email to see the message inside the standard email layout before it goes out.',
      run: async (d) => {
        await d.click(`${modal} button:has-text("Preview Email")`);
        await d.wait(1200);
        await glide(d, scroller, 'bottom', 2000);
      },
    },
    {
      say: 'Send Email delivers the message and records it on the customer’s Email History, whether it succeeds or fails. We will cancel this one.',
      run: async (d) => {
        await d.hover(`${modal} .modal-footer button:has-text("Send Email"), ${modal} button:has-text("Send Email")`, 1800);
        await d.click(`${modal} button:has-text("Cancel")`);
        await d.wait(600);
      },
    },
    {
      say: 'The Email History tab lists every email sent to this customer, with its status and attachments. Compose Email here opens the same window.',
      run: async (d) => {
        await d.click('button:has-text("Email History")');
        await d.wait(1500);
        await d.highlight('button:has-text("Compose Email")', 'Same compose window', 2200);
      },
    },
    {
      say: 'Templates are managed under Settings. Click Email Templates at the top of the Settings page.',
      run: async (d) => {
        await d.nav('Settings', '/settings');
        await d.wait(800);
        await d.click('a:has-text("Email Templates")', { nav: true });
      },
    },
    {
      say: 'Templates are grouped by purpose: invoices, payments, leases, reminders, compliance and general. Each one can be previewed, edited, duplicated, disabled or deleted.',
      run: async (d) => {
        await d.highlight('table >> nth=0', 'Invoice templates', 2400);
        await d.hover('tr:has-text("Invoice Ready") button:has-text("Edit")', 700);
        await d.hover('tr:has-text("Invoice Ready") button:has-text("Duplicate")', 700);
        await d.hover('tr:has-text("Invoice Ready") button:has-text("Disable")', 700);
      },
    },
    {
      say: 'Preview shows the template rendered with sample details. Anything still in curly brackets is filled in from the real customer when you send.',
      run: async (d) => {
        await d.click('tr:has-text("General Message") button:has-text("Preview")');
        await d.wait(1800);
        await d.hover('.modal button:has-text("Close")', 600);
        await d.click('.modal button:has-text("Close")');
      },
    },
    {
      say: 'To reach many customers at once, use Bulk Email, also linked from the top of Settings. Step one is choosing recipients.',
      run: async (d) => {
        await d.nav('Settings', '/settings');
        await d.wait(800);
        await d.click('a:has-text("Bulk Email")', { nav: true });
      },
    },
    {
      say: 'Filter by customer status, province, minimum outstanding balance, or search by name. Here we narrow it to customers owing more than five thousand dollars, then select them all.',
      run: async (d) => {
        await d.hover('select[x-model="filters.status"]', 500);
        await d.hover('select[x-model="filters.province"]', 500);
        await d.type('input[x-model="filters.minBalance"]', '5000');
        await d.press('Tab'); // the filter applies on change
        await d.wait(1500);
        await d.click('button:has-text("Select All Visible")');
        await d.wait(800);
      },
    },
    {
      say: 'Step two is the message, with an optional template. Variables are replaced separately for each recipient.',
      run: async (d) => {
        await d.click('button:has-text("Next: Compose")');
        await d.wait(1200);
        await d.hover('select[x-model="message.template_id"]', 900);
        await d.type('input[x-model="message.subject"]', 'Account balance reminder from Mainland Truck & Trailer', { delay: 25 });
      },
    },
    {
      say: 'Step three reviews the recipient list before sending. Every customer gets their own email and their own entry in Email History, so replies stay private.',
      run: async (d) => {
        await d.scroll(700);
        await d.hover('button:has-text("Next: Review")', 1600);
        await d.scroll(-700);
      },
    },
    {
      say: 'Automatic reminders, like invoice due soon or lease ending, are set under Settings, Customer Emails. Every reminder ships switched off, and three switches must all be on before anything sends.',
      run: async (d) => {
        await d.goto('/settings?tab=customer_notifications');
        await d.wait(1200);
        await d.highlight('text=Global settings', 'Master switch and schedule', 3200);
      },
    },
    {
      say: 'The do-not-email list blocks a customer from every reminder, whatever the other settings say.',
      run: async (d) => { await d.highlight('text=Do-not-email list', 'Never email these customers', 2800); },
    },
    {
      say: 'For two-way conversations with customers who use the portal, use Messenger. It is a shared team inbox: everyone with customer access sees every thread. It has no sidebar link yet, so bookmark it.',
      run: async (d) => {
        await d.goto('/messenger');
        await d.wait(1200);
        await d.highlight('.chat-sidebar', 'Customer conversations', 2600);
      },
    },
    {
      say: 'Start a conversation by picking a company to message all of its portal users, or a single contact to message one person.',
      run: async (d) => {
        await d.click('button:has-text("Start a conversation")');
        await d.wait(1200);
        await d.type('input[x-model="contactQuery"]', 'Summit', { delay: 60 });
        await d.wait(1200);
        await d.click('.modal-overlay .modal-close-btn');
      },
    },
    {
      say: 'Team Chat is for talking with colleagues. Open it from the chat icon in the top bar; a badge shows unread messages.',
      run: async (d) => {
        await d.hover('.topbar-chat-btn', 900);
        await d.click('.topbar-chat-btn', { nav: true });
      },
    },
    {
      say: 'Chat has channels for teams or topics, direct messages between two people, and customer chats. In a message you can mention a colleague, attach a record like a lease or invoice, and react.',
      run: async (d) => {
        await d.hover('button:has-text("+ Browse All")', 900);
        await d.hover('button:has-text("+ New Direct Message")', 900);
        await d.hover('button:has-text("+ Start Customer Chat")', 900);
      },
    },
    {
      say: 'Now notifications. The bell in the top bar shows how many are unread. Click it to see the latest.',
      run: async (d) => {
        await d.click('.notif-bell-btn');
        await d.wait(1800);
      },
    },
    {
      say: 'Switch between a flat list and a grouped view, mark everything read, or click See all notifications for the full page.',
      run: async (d) => {
        await d.hover('.notif-view-toggle button:has-text("Grouped")', 800);
        await d.hover('.notif-mark-all', 900);
        await d.click('a:has-text("See all notifications")', { nav: true });
      },
    },
    {
      say: 'The Notifications page lets you search, and filter by read status, category and date. Choosing a category reloads the list straight away.',
      run: async (d) => {
        await d.hover('input[name="q"]', 600);
        await d.hover('select[name="is_read"]', 500);
        await d.select('select[name="category"]', { index: 2 });
        await d.page.waitForLoadState('load').catch(() => {});
        await d.wait(1500);
        await d.caption('The Notifications page lets you search, and filter by read status, category and date. Choosing a category reloads the list straight away.');
      },
    },
    {
      say: 'Each notification has Open to jump to the record, Mark read, and Delete. Mark all read and Clear all read tidy up in bulk.',
      run: async (d) => {
        await d.hover('a:has-text("Open"), button:has-text("Open")', 800);
        await d.hover('#notif-mark-all-btn', 800);
        await d.hover('#notif-clear-read-btn', 800);
        await d.click('a:has-text("Reset")', { nav: true });
      },
    },
    {
      say: 'The speaker icon in the top bar mutes notification sounds on this computer.',
      run: async (d) => { await d.highlight('.sound-toggle-btn', 'Mute sounds', 2200); },
    },
    {
      say: 'Which categories of notifications you receive is not set in My Profile. A super admin sets it for each person under Users, on that user’s Permissions page.',
      run: async (d) => {
        await d.goto('/users/permissions?user_id=19');
        await d.wait(1200);
        await d.highlight('.perm-sidebar-card:has-text("Notifications")', 'Notification categories', 3000);
      },
    },
    {
      say: 'Turn a category off and that person stops receiving those notifications, for example Samsara or QuickBooks alerts. Click Save to apply.',
      run: async (d) => {
        await d.hover('.perm-notif-row:has-text("Samsara")', 1200);
        await d.hover('.perm-notif-save-btn', 1400);
      },
    },
  ],
};
