/*
 * Chapter 18 — Service Requests
 * Requests customers submit from the portal: where they come from and who is notified (routing),
 * the list and status tiles, reading a request and its conversation, the reply form (hovered only —
 * a reply notifies the customer), and turning a damage report into a work order for the unit.
 *
 * There is no one-click "convert to work order" in the app; the chapter shows the real path:
 * request → Equipment card → unit's Maintenance tab → New Work Order (unit filled in).
 *
 * Records created on commit: one work order on unit DV-7016 ("Replace marker light and repair rear
 * door dent", body damage, medium) that references portal request #1000031. The request itself is
 * NOT changed — Send Reply is never clicked (api/v1/requests/respond.php notifies the customer).
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   commitClick() — tolerates the recorder's dry-run "log is not defined" throw on skipped commits.
 */
const REQ = '/requests/view?id=1000031'; // Damage Report — Rocky Mountain Freight Co., DV-7016

async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}
const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });

export default {
  title: 'Service Requests',
  subtitle: 'Questions, damage reports and lease changes that customers send you through their portal.',
  start: '/dashboard',
  intro: 'In this chapter we will see where service requests come from, how to read and answer one, and how to turn a damage report into a work order.',
  outro: 'That covers service requests. Check the open list every day, route each request to the right module, and always reply so the customer knows where things stand.',
  scenes: [
    S('Open Service Requests from the left sidebar. Every request here was submitted by a customer from their portal login.', async (d) => {
      await d.nav('Service Requests', '/requests');
    }),
    S('In the portal, customers choose a request type: lease extension, early return, damage report, billing inquiry, document request, new lease inquiry or a general question. They can attach it to one of their leases or units.', async (d) => {
      await d.highlight('table tbody tr >> nth=0', 'Type, customer, lease and unit', 3600);
    }),
    S('When a request comes in, staff get an in-app notification. Configure routing decides who: pick roles or specific users for each request type.', async (d, cap) => {
      await d.hover('a:has-text("Configure routing")', 600);
      await d.click('a:has-text("Configure routing")', { nav: true });
      await d.wait(800);
      await d.highlight('#service-request-routing', 'Who is notified, per request type', 2600);
    }),
    S('If a type has no routing set, the default group is used, and with Always include super admin ticked, super admins are notified for everything so nothing is missed.', async (d) => {
      await d.highlight('#service-request-routing label:has-text("Always include super_admin")', 'Safety net', 2600);
    }, 'If a type has no routing set, the default group is used, and with "Always include super_admin" ticked, super admins are notified for everything so nothing is missed.'),
    S('Back on the list, the tiles count requests by status: open, in review, resolved and closed.', async (d) => {
      await d.nav('Service Requests', '/requests');
      await d.highlight('.kpi-grid', 'Requests by status', 2600);
    }),
    S('The list opens on open requests. Use the status filter to see the others, or All Statuses for everything.', async (d) => {
      await Promise.all([d.page.waitForNavigation({ timeout: 20000 }).catch(() => {}), d.select('select[name="status"]', 'all')]);
      await d.ready();
      await d.wait(900);
      await Promise.all([d.page.waitForNavigation({ timeout: 20000 }).catch(() => {}), d.select('select[name="status"]', 'open')]);
      await d.ready();
    }),
    S('Each row shows the type, the customer, who submitted it, the subject, the lease and unit, who it is assigned to, and when it came in.', async (d, cap) => {
      await d.caption(cap);
      await d.highlight('table thead', 'Request details', 2600);
    }),
    S("Click the number or subject to open a request. Let's open this damage report from Rocky Mountain Freight.", async (d) => {
      await d.click(`table tbody a[href*="id=1000031"] >> nth=0`, { nav: true }).catch(() => d.goto(REQ));
    }),
    S('The cards across the top link straight to the customer, the lease and the unit, so you can check the account before you answer.', async (d) => {
      await d.highlight('.card:has-text("Customer") >> nth=0', 'Customer', 1400);
      await d.highlight('.card:has(a[href*="leases/show"])', 'Lease', 1300);
      await d.highlight('.card:has(a[href*="equipment/show"])', 'Unit', 1300);
    }),
    S('Below is the customer’s message, followed by the conversation. Staff replies and customer follow-ups appear here in order.', async (d) => {
      await d.scroll(450);
      await d.highlight('.card:has(.card-header:has-text("Conversation"))', 'Conversation thread', 2800);
    }),
    S('To answer, type your reply and choose the status it should have afterwards. In Review tells the customer you are working on it; Resolved or Closed finishes the request.', async (d) => {
      await d.scroll(400);
      await d.type('#reply-body', 'Thanks Brett. We have booked the marker light and the rear door dent in for repair and will confirm a time to swap the unit.', { delay: 18 });
      await d.hover('#reply-status', 900);
    }),
    S('Send Reply adds your message to the thread, assigns the request to you, updates the status, and notifies the customer in their portal. We will not send it in this demo.', async (d) => {
      await d.hover('button:has-text("Send Reply")', 2500);
      await d.page.fill('#reply-body', '');
    }),
    S('Some requests need work on the unit. There is no convert button; instead, open the unit from the Equipment card.', async (d) => {
      await d.scroll(-2000);
      await d.click('.card a[href*="equipment/show"]', { nav: true });
    }),
    S('On the unit’s Maintenance tab, click New Work Order. The unit is filled in for you.', async (d) => {
      await d.click('button.tab-btn:has-text("Maintenance")');
      await d.wait(1200);
      await d.click('a:has-text("+ New Work Order")', { nav: true });
      await d.highlight('.form-group:has(> label:has-text("Equipment Unit"))', 'Unit already selected', 1800);
    }),
    S('Describe the job and mention the request number in the description, so anyone can trace the work back to the customer’s report.', async (d) => {
      await d.type('#title', 'Replace marker light and repair rear door dent');
      await d.select('#work_type', 'body_damage');
      await d.select('#priority', 'medium');
      await d.type('#description', 'Reported by customer in portal request #1000031: cracked marker light and small dent on the rear door.', { delay: 18 });
    }),
    S('Create the work order. From here it follows the normal maintenance flow covered in the Maintenance chapter.', async (d) => {
      await d.scroll(1500);
      const req = d.page.waitForRequest((r) => r.method() === 'POST' && /maintenance_work_orders\/create/.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
      await commitClick(d, 'button[type="submit"]:has-text("Create Work Order")');
      if (await req) { await d.page.waitForURL(/maintenance_work_orders\/show\?id=\d+/, { timeout: 20000 }).catch(() => {}); await d.ready(); }
    }),
    S('If the customer caused the damage, also open a damage claim from the lease so the repair cost can be billed back. Then return to the request and reply with the work order number.', async (d, cap) => {
      await d.caption(cap);
      await d.goto(REQ);
      await d.scroll(900);
      await d.hover('#reply-body', 1500);
    }),
    S('Other request types are handled in their own modules: lease extensions and early returns on the lease, billing questions on the invoice, and document requests from the Documents library. Close the loop with a reply each time.', async (d) => {
      await d.nav('Service Requests', '/requests');
      await d.highlight('table tbody', 'Open requests', 2800);
    }),
  ],
};
