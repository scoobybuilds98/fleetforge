/*
 * Chapter 34 — Customer Portal
 * Admin side: Users → Portal Users (tiles, the Create & Invite form filled but only hovered —
 * the API always emails an invite), a portal user's page (reset link / deactivate hovered).
 * Customer side: the portal login page, then the portal as Kyle Thompson (primary contact,
 * Summit Carriers Ltd.): dashboard, leases, invoices, payments, equipment, service requests
 * (new-request form filled, Submit only hovered), account. Ends back in the admin Service
 * Requests queue.
 *
 * Creates / changes NO records: there are no commit clicks.
 *
 * How the customer view is shown without typing a password: becomeCustomer() below (an inline
 * helper — lib/recorder.mjs is not modified) runs scripts/walkthrough/mint_portal_session.php
 * (DEV-only, read-only session mint for portal user 1000027) and swaps the browser's ff_session
 * cookie via page.context(). becomeStaff() restores the recorder's admin cookie afterwards.
 * Portal and admin share the cookie NAME but keep separate session keys, so the swap is clean.
 *
 * Narration facts verified in code:
 *  - First portal user for a customer becomes primary; later ones are sub-users; invite = status
 *    'invited' + emailed set-password link valid 7 days (api/v1/portal_users/create.php).
 *  - Every portal query filters by the logged-in customer (portal_customer_id()).
 *  - Portal access is re-checked every request: deactivated portal user OR customer not
 *    active/pending/credit_hold → signed out (portal_status_revoked()).
 *  - Portal invoices hide voids and ordinary drafts; Pay Now appears only when QuickBooks
 *    payments are on and the invoice is synced to QuickBooks (payments/index.php).
 *  - New request → in-app notification to routed staff (PortalRequestNotifier; Settings →
 *    Portal & Requests). Primary users manage sub-users (account/users.php).
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const WT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const PORTAL_USER_ID = '1000027'; // Kyle Thompson — Summit Carriers Ltd. (primary)
let staffCookie = null;

/** Swap the browser session to a freshly minted portal session for the demo customer. */
async function becomeCustomer(d) {
  const sid = execFileSync('php', [path.join(WT, 'mint_portal_session.php'), PORTAL_USER_ID], { encoding: 'utf8' })
    .trim().split('\n').pop();
  if (!/^[a-z0-9,-]{20,}$/i.test(sid)) throw new Error(`portal session mint failed: ${sid}`);
  const ctx = d.page.context();
  staffCookie = (await ctx.cookies()).find((c) => c.name === 'ff_session') || staffCookie;
  await ctx.clearCookies({ name: 'ff_session' });
  await ctx.addCookies([{ name: 'ff_session', value: sid, url: new URL(d.base).origin, httpOnly: true }]);
}

/** Put the recorder's staff (admin) session cookie back. */
async function becomeStaff(d) {
  if (!staffCookie) return;
  const ctx = d.page.context();
  await ctx.clearCookies({ name: 'ff_session' });
  await ctx.addCookies([staffCookie]);
}

const tab = (name) => `button.tab-btn:has-text("${name}")`;
const portalNav = (label) => `.portal-nav a:has-text("${label}")`;

export default {
  title: 'Customer Portal',
  subtitle: 'The self-service website where your customers see their leases, invoices and payments, and send you requests.',
  start: '/dashboard',
  intro: 'In this chapter we will set up customer portal logins, then sign in as a customer to see exactly what they see.',
  outro: 'That covers the customer portal, and wraps up the administration chapters.',
  scenes: [
    // ── Admin side ──────────────────────────────────────────────────────────
    {
      say: 'Portal logins are managed in Users, on the Portal Users tab. These are your customers’ contacts, completely separate from staff accounts.',
      run: async (d) => {
        await d.nav('Users', '/users');
        await d.tab('Portal Users');
        await d.wait(900);
      },
    },
    {
      say: 'The tiles show every portal login, how many are active, invitations not yet accepted, and addresses where email has been switched off because messages bounced and need review.',
      run: async (d) => { await d.highlight('.stat-grid', 'Portal logins at a glance', 3400); },
    },
    {
      say: 'To give a customer access, choose the customer, then enter the contact’s name and email.',
      run: async (d) => {
        await d.select('[x-model="form.customer_id"]', { label: 'Summit Carriers Ltd.' });
        await d.type('[x-model="form.name"]', 'Priya Sandhu');
        await d.type('[x-model="form.email"]', 'priya.sandhu@summitcarriers.ca', { delay: 22 });
      },
    },
    {
      say: 'Create and Invite emails them a link to set their own password, valid for seven days. The first login for a customer becomes the primary account holder; later ones are sub-users. We will not send it here.',
      caption: 'Create & Invite emails them a link to set their own password, valid for seven days. The first login for a customer becomes the primary account holder; later ones are sub-users. We will not send it here.',
      run: async (d) => {
        await d.highlight('button:has-text("Create & Invite")', 'Emails the invite — not clicked', 3200);
        await d.type('[x-model="form.name"]', '');
        await d.type('[x-model="form.email"]', '');
      },
    },
    {
      say: 'The list below shows each login, its customer, whether it is the primary contact, and when they last signed in. Click View to open one.',
      run: async (d) => {
        await d.type('[x-model="filters.q"]', 'Summit');
        await d.wait(1400);
        await d.click('table tbody tr:has-text("Kyle Thompson") a:has-text("View")', { nav: true });
      },
    },
    {
      say: 'From here you can send a password reset link, valid for twenty-four hours, or deactivate the login. Deactivating takes effect on their very next click.',
      run: async (d) => {
        await d.hover('button:has-text("Send Reset Link")', 1400);
        await d.hover('button:has-text("Deactivate")', 1400);
      },
    },
    {
      say: 'Portal access also follows the customer account. If a customer is made suspended or inactive, all of their portal logins stop working. Customers on credit hold can still sign in.',
      run: async (d) => { await d.highlight('.card:has-text("Portal user details")', 'Tied to the customer account', 3000); },
    },
    // ── Customer side ───────────────────────────────────────────────────────
    {
      say: 'Now let’s see the customer’s side. Customers sign in at the portal login page with their own email and password.',
      run: async (d) => {
        await d.goto('/portal/auth/login');
        await d.hover('input[name="email"]', 1500);
      },
    },
    {
      say: 'We are signed in as Kyle Thompson from Summit Carriers. His dashboard shows active leases, his outstanding balance, units he has out, and documents about to expire, with a reminder about any overdue invoices.',
      run: async (d) => {
        await becomeCustomer(d);
        await d.goto('/portal');
        await d.wait(600);
        await d.highlight('.portal-kpi-grid', 'Customer dashboard', 2600);
      },
    },
    {
      say: 'Everything in the portal is limited to that one customer. Kyle can never see another company’s leases, invoices or equipment.',
      run: async (d) => { await d.scroll(500); await d.wait(900); await d.scroll(-500); },
    },
    {
      say: 'Leases lists his contracts, split into active and historical. Opening one shows the unit, dates and rate, and every invoice for that lease.',
      run: async (d) => {
        await d.click(portalNav('Leases'), { nav: true });
        await d.wait(700);
        await d.click('table tbody a:has-text("LSE-2026-00011")', { nav: true });
      },
    },
    {
      say: 'Buttons on the lease let him ask for an extension, give notice of an early return, or report damage. Each one opens a service request already filled in with this lease.',
      run: async (d) => {
        await d.hover('a:has-text("Request Extension")', 1100);
        await d.hover('a:has-text("Report Early Return")', 1100);
        await d.hover('a:has-text("Report Damage")', 1100);
      },
    },
    {
      say: 'Invoices shows outstanding, paid and all invoices. Voided invoices and unsent drafts are never shown to customers.',
      run: async (d) => {
        await d.click(portalNav('Invoices'), { nav: true });
        await d.wait(700);
        await d.highlight('table thead', 'Billing period, amount, balance, status', 2400);
      },
    },
    {
      say: 'Opening an invoice shows its line items and any payments applied.',
      run: async (d) => {
        await d.click('table tbody a:has-text("INV-2026-00037")', { nav: true });
        await d.wait(600);
        await d.scroll(400);
      },
    },
    {
      say: 'Payments totals what is owed and lists his payment history. Where online payment through QuickBooks is switched on and the invoice is synced, a Pay Now button takes the customer to pay by card or bank.',
      run: async (d) => {
        await d.click(portalNav('Payments'), { nav: true });
        await d.wait(700);
        if (await d.exists('button:has-text("Pay Now")')) await d.hover('button:has-text("Pay Now")', 1600);
        await d.scroll(700);
      },
    },
    {
      say: 'Equipment lists the units he currently has on lease, with a quick link to report an issue. Documents holds files you have shared with the customer.',
      run: async (d) => {
        await d.click(portalNav('Equipment'), { nav: true });
        await d.wait(600);
        if (await d.exists('a:has-text("Report Issue")')) await d.hover('a:has-text("Report Issue")', 1200);
      },
    },
    {
      say: 'Requests is how customers reach your team. Here Kyle can see each request he has sent and its status.',
      run: async (d) => {
        await d.click(portalNav('Requests'), { nav: true });
        await d.wait(600);
        await d.highlight('table', 'His service requests', 2200);
      },
    },
    {
      say: 'A new request needs a type, such as a billing inquiry or damage report, a subject and a description. He can also link a lease or a unit.',
      run: async (d) => {
        await d.click('a:has-text("New Request")', { nav: true });
        await d.select('#request_type', 'billing_inquiry');
        await d.type('#subject', 'Question about the July invoice');
        await d.type('#message', 'Could you confirm the mileage charge on INV-2026-00037? Thanks, Kyle.', { delay: 20 });
      },
    },
    {
      say: 'Submitting sends an in-app notification to the staff chosen in Settings, under Portal and Requests. We will not submit this one.',
      caption: 'Submitting sends an in-app notification to the staff chosen in Settings → Portal & Requests. We will not submit this one.',
      run: async (d) => { await d.highlight('button:has-text("Submit Request")', 'Notifies your team — not clicked', 3000); },
    },
    {
      say: 'Opening a request shows the whole conversation. Replies from your team appear here, and the customer can reply back.',
      run: async (d) => {
        await d.click('a:has-text("Back to Requests")', { nav: true });
        await d.click('table tbody a >> nth=0', { nav: true });
        await d.wait(600);
        await d.hover('#portal-reply-body', 1400);
      },
    },
    {
      say: 'Under Account, customers update their profile and password and choose which notifications they receive. The primary contact can also add and remove sub-users for their own company.',
      run: async (d) => {
        await d.click(portalNav('Account'), { nav: true });
        await d.wait(600);
        await d.click('button.portal-tab-btn:has-text("Notifications")');
        await d.wait(900);
        await d.click('button.portal-tab-btn:has-text("Sub-Users")');
        await d.wait(900);
      },
    },
    // ── Back to staff ───────────────────────────────────────────────────────
    {
      say: 'Back on the staff side, every request lands in Service Requests. Your team replies there and moves each one through open, in review, resolved and closed, and the customer is notified whenever you reply or change the status.',
      run: async (d) => {
        await becomeStaff(d);
        await d.goto('/requests');
        await d.highlight('table thead', 'Customer service requests', 2600);
      },
    },
  ],
};
