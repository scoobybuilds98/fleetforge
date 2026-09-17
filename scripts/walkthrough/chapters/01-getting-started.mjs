/*
 * Chapter 01 — Getting Started
 * A tour of the shell every page shares: the Dashboard (all twelve KPI tiles, the charts and
 * the card strips), the sidebar, the top bar (+ New quick-create, global search and ⌘K,
 * light/dark toggle, display settings, sounds, team chat, AI, notifications, account menu),
 * the "How this works" help drawer, the Help Center and the floating AI chat bubble.
 *
 * Creates no records. Side-effects on camera are cosmetic and reversed in the same scene:
 * the theme is toggled to light and back to dark, and text size is bumped up and back down
 * (both are saved as the recorder user's own preferences). No question is sent to the AI.
 */
const tile = (label) => `.stat-card:has(.stat-label:text-is("${label}"))`;
const section = (title) => `h3.dashboard-section-title:text-is("${title}")`;
const card = (title) => `.card:has(.card-title:text-is("${title}"))`;
// Close popovers with a click on empty page-header space, NOT Escape: the app shell binds
// @keydown.escape.window to collapse the desktop sidebar, so Escape would fold it on camera.
const clickAway = async (d) => { await d.moveTo(760, 104, 300); await d.page.mouse.click(760, 104); await d.wait(350); };

export default {
  title: 'Getting Started',
  subtitle: 'Find your way around Fleet Forge — the dashboard, the sidebar, the top bar and where to get help.',
  start: '/dashboard',
  intro: 'Welcome to Fleet Forge. In this first chapter we will tour the dashboard, learn the layout every page shares, and see where to get help when you are stuck.',
  outro: 'That is the lay of the land. Next, we will look at Customers, the companies you rent equipment to.',
  scenes: [
    {
      say: 'After you sign in, you land on the Dashboard. It is a live summary of the whole business, and every number on it links to the records behind it.',
      run: async (d) => { await d.wait(2500); await d.highlight('h1.page-header-title', 'Dashboard', 1800); },
    },
    {
      say: 'Active Revenue adds up the monthly rate of every active lease, in Canadian dollars. Fleet Utilization is the share of your fleet that is out on lease right now.',
      caption: 'Active Revenue adds up the monthly rate of every active lease, in CAD. Fleet Utilization is the share of your fleet out on lease right now.',
      run: async (d) => {
        await d.highlight(tile('Active Revenue'), 'Monthly rates of active leases', 2600);
        await d.highlight(tile('Fleet Utilization'), 'Units on lease ÷ fleet', 2600);
      },
    },
    {
      say: 'Overdue Invoices counts invoices past their due date and what is still owing. Compliance Alerts counts units with a registration, insurance, C V I or M V I date that has expired or expires within thirty days.',
      caption: 'Overdue Invoices counts invoices past due and what is owing. Compliance Alerts counts units with a registration, insurance, CVI or MVI date expired or due within 30 days.',
      run: async (d) => {
        await d.highlight(tile('Overdue Invoices'), 'Past due', 2800);
        await d.highlight(tile('Compliance Alerts'), 'Expiring within 30 days', 2800);
      },
    },
    {
      say: "Open Leases are active and pending contracts. Today's Pickups are reservations due to be collected today, and Available Units are ready to rent.",
      run: async (d) => {
        await d.hover(tile('Open Leases'), 900);
        await d.hover(tile("Today's Pickups"), 900);
        await d.hover(tile('Available Units'), 900);
      },
    },
    {
      say: 'The second row covers open work orders, open damage claims, invoices sent and awaiting payment, money collected this month, and pending or confirmed reservations.',
      run: async (d) => { await d.highlight('#kpi-grid', 'Twelve key numbers', 2600); },
    },
    {
      say: 'Click any tile to open that list already filtered. For example, Overdue Invoices opens the invoice list showing only overdue invoices.',
      run: async (d) => {
        await d.click(tile('Overdue Invoices'), { nav: true });
        await d.wait(1800);
        await d.nav('Dashboard', '/dashboard');
      },
    },
    {
      say: 'Below the tiles, Revenue compares invoiced revenue month by month against last year, and Fleet Status breaks your units down by status.',
      run: async (d) => {
        await d.scroll(430);
        await d.hover(card('Revenue — Last 12 Months'), 1200);
        await d.hover(card('Fleet Status'), 1200);
      },
    },
    {
      say: 'Strips of cards follow: active leases, upcoming reservations, and draft invoices still waiting to be sent. Each strip has a View All link to the full list.',
      run: async (d) => {
        await d.scroll(520);
        await d.highlight(section('Active Leases'), 'Active leases', 1400);
        await d.scroll(900);
        await d.highlight(section('Draft Invoices'), 'Waiting to be sent', 1400);
        await d.hover(`a:near(${section('Draft Invoices')}):has-text("View all")`, 700);
      },
    },
    {
      say: 'Further down are receivables aging, utilization over the past year, overdue payments, average days to pay, and a calendar of when leases expire.',
      run: async (d) => {
        await d.scroll(700);
        await d.hover(card('AR Aging'), 900);
        await d.scroll(1100);
        await d.hover(card('Lease Expiry Calendar — Next 12 Months'), 1000);
      },
    },
    {
      say: 'Near the bottom, Pending Activations lists leases whose start date has passed but have not been activated, and Upcoming Returns shows units due back soon. Recent Activity is a live feed of changes across the system.',
      run: async (d) => {
        await d.scroll(1100);
        await d.highlight(section('Pending Activations'), 'Start date passed, not activated', 1600);
        await d.scroll(700);
        await d.hover(card('Recent Activity'), 1400);
      },
    },
    {
      say: 'Now the layout. The sidebar on the left lists every module. Red badges flag work waiting for you, such as pending credit applications or open damage claims.',
      run: async (d) => {
        await d.page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        await d.wait(700);
        await d.highlight('aside nav.sidebar-nav', 'Modules', 2200);
        await d.hover('nav.sidebar-nav a:has(.nav-badge) >> nth=0', 800);
      },
    },
    {
      say: 'Modules your role cannot use show a small lock. The arrow at the top shrinks the sidebar to icons when you need more room, and the menu button in the top bar brings it back.',
      run: async (d) => {
        await d.click('.sidebar-collapse-btn');
        await d.wait(1400);
        await d.click('button[aria-label="Toggle navigation menu"]');
        await d.wait(600);
      },
    },
    {
      say: 'At the bottom of the sidebar are your name and role, a theme switch, and the log out button.',
      run: async (d) => { await d.highlight('.sidebar-footer', 'You', 2400); },
    },
    {
      say: 'The top bar is the same on every page. The New button is a shortcut to create almost anything: a customer, lease, reservation, invoice, payment, unit, work order and more.',
      run: async (d) => {
        await d.click('.topbar-create-btn');
        await d.wait(400);
        await d.highlight('.topbar-create-dropdown', 'Quick create', 2600);
        await clickAway(d);
      },
    },
    {
      say: 'Global search finds customers, units, leases, invoices and reservations from anywhere. Results are grouped by type as you type.',
      run: async (d) => {
        await d.type('.search-input', 'Summit', { delay: 90 });
        await d.wait(1800);
        await d.highlight('#ff-search-results-inline', 'Grouped results', 1800).catch(() => {});
      },
    },
    {
      say: 'Press Command K, or Control K on Windows, to open the same search full-screen, along with your recent searches.',
      caption: 'Press ⌘K (Ctrl+K on Windows) to open the same search full-screen, along with your recent searches.',
      run: async (d) => {
        await d.loc('.search-input').fill('');
        await clickAway(d);
        await d.press('Meta+k');
        await d.wait(700);
        if (!(await d.exists('#ff-search-input', 1500))) await d.page.evaluate(() => window.FF_Search && window.FF_Search.open());
        await d.type('#ff-search-input', 'TR-70', { delay: 90 });
        await d.wait(1600);
        await d.page.evaluate(() => window.FF_Search && window.FF_Search.close());
        await d.wait(300);
      },
    },
    {
      say: 'The sun and moon button switches between dark and light mode. Your choice is saved to your account, so it follows you to any computer.',
      run: async (d) => {
        await d.click('.topbar-theme-btn', { pause: 1600 });
        await d.click('.topbar-theme-btn', { pause: 700 });
      },
    },
    {
      say: 'Display settings let you make text larger or smaller, and choose compact, cozy or spacious spacing. They only change the page content, and are also saved to your account.',
      run: async (d) => {
        await d.click('.topbar-display-btn');
        await d.highlight('.topbar-display-dropdown', 'Text size & density', 1200);
        await d.click('button[aria-label="Increase text size"]', { pause: 1100 });
        await d.click('button[aria-label="Decrease text size"]', { pause: 800 });
        await d.hover('.topbar-display-chip:has-text("Compact")', 500);
        await clickAway(d);
      },
    },
    {
      say: 'Next to it, the speaker mutes notification sounds, the speech bubbles open Team Chat with your colleagues, and the sparkles open the AI Assistant.',
      run: async (d) => {
        await d.hover('.sound-toggle-btn', 900);
        await d.hover('.topbar-chat-btn', 900);
        await d.hover('.topbar-ai-btn', 900);
      },
    },
    {
      say: 'The bell shows your notifications, such as overdue invoices or expiring documents. Switch between a flat list and grouped by category, or mark them all read.',
      run: async (d) => {
        await d.click('.notif-bell-btn');
        await d.wait(1200);
        await d.highlight('.notif-dropdown', 'Notifications', 2200);
        await clickAway(d);
      },
    },
    {
      say: 'Your name at the far right opens the account menu: your profile, system settings if your role allows it, and Sign Out.',
      run: async (d) => {
        await d.click('.user-trigger');
        await d.highlight('.user-dropdown', 'Account menu', 2400);
        await clickAway(d);
      },
    },
    {
      say: 'Most pages have a How this works button in the header. It opens a guide for that module in a side drawer, so you can read it without leaving the page.',
      run: async (d) => {
        await d.click('.page-header .help-btn');
        await d.wait(1500);
        await d.highlight('.help-drawer-panel', 'Guide for this page', 1600);
        await d.scroll(500, { x: 1650, y: 600 });
      },
    },
    {
      say: 'Open full guide takes you to the complete article. Close the drawer when you are done.',
      run: async (d) => {
        await d.hover('.help-drawer-full-link', 1000);
        await d.click('.help-drawer-close');
      },
    },
    {
      say: 'Every guide also lives in the Help Center, at the bottom of the sidebar: one plain-language article per module.',
      run: async (d) => {
        await d.nav('Help Center', '/help');
        await d.wait(700);
        await d.highlight('.help-guide-grid', 'One guide per module', 2200);
      },
    },
    {
      say: "Let's open the Leases guide. Each one explains what the module is for, the everyday steps, and what happens behind the scenes.",
      run: async (d) => {
        await d.click('a.help-guide-card:has(.help-guide-title:text-is("Leases"))', { nav: true });
        await d.wait(800);
        await d.scroll(700);
      },
    },
    {
      say: 'Finally, the round chat bubble in the bottom corner opens the AI Assistant on any page. Ask it plain questions about your fleet, customers, leases or invoices.',
      run: async (d) => {
        await d.click('.ff-chat-fab');
        await d.wait(900);
        await d.highlight('.ff-chat-panel', 'AI Assistant', 2200);
      },
    },
    {
      say: 'You can minimise it, pop it out to the full AI page, or close it. We will not send a question here.',
      run: async (d) => {
        await d.hover('.ff-chat-panel .ff-chat-hdr-btn[title="Open full chat"]', 800);
        await d.hover('.ff-chat-panel button[title="Minimize"]', 700);
        await d.click('.ff-chat-panel button[title="Close"]');
      },
    },
  ],
};
