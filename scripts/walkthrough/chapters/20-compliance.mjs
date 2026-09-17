/*
 * Chapter 20 — Compliance
 * The fleet compliance grid (CVI and registration per unit), its tiles, colour legend and filters,
 * updating Valid From / Expiry dates in the cell modal, the sidebar alerts badge, where the same dates
 * appear on the unit, the nightly staff alerts, and the (off-by-default) customer expiry emails.
 *
 * Scope note, verified in code: the grid and the unit Compliance tab show CVI and Registration only.
 * MVI and Insurance were removed from the screens on operator request (2026-06-17), although the
 * sidebar badge query and cron/compliance_alerts.php still read mvi_expiry / insurance_expiry.
 * Customer compliance emails are gated by Settings → Customer Emails → "Compliance document expiry",
 * which ships OFF — the Customer Emails tab is only viewed, never saved or sampled.
 *
 * Records changed on commit (existing demo units, no new rows):
 *   TR-7002 (id 2)  CVI          from = today − 347 days, expiry = today + 18 days   (amber)
 *   TR-7002 (id 2)  Registration from = today − 110 days, expiry = today + 255 days  (green)
 *   FB-7042 (id 42) Registration from = today − 371 days, expiry = today − 6 days    (red)
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   commitClick() — tolerates the recorder's dry-run "log is not defined" throw on skipped commits.
 *   setDates()    — opens a grid cell's modal and fills both dates, then saveOrCancel().
 *   saveOrCancel() — commit Save; waits for the modal to close if the POST fired, else cancels (dry run).
 */
const day = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}
async function findUnit(d, unit) {
  await d.type('[x-model="filters.q"]', unit, { delay: 70 });
  await d.wait(1400);
}
async function setDates(d, cellIndex, from, expiry) {
  await d.click(`table tbody tr .compliance-cell-edit >> nth=${cellIndex}`);
  await d.wait(600);
  await d.page.fill('.modal-overlay [x-model="modal.fromDate"]', from);
  await d.wait(500);
  await d.hover('.modal-overlay [x-model="modal.expiryDate"]', 300);
  await d.page.fill('.modal-overlay [x-model="modal.expiryDate"]', expiry);
  await d.wait(700);
  await saveOrCancel(d);
}
// Save (commit). If the POST really fired, wait for the modal to close; otherwise (dry run) cancel it.
async function saveOrCancel(d) {
  const req = d.page.waitForRequest((r) => r.method() === 'POST' && /compliance\/update/.test(r.url()), { timeout: 3000 }).then(() => true, () => false);
  await commitClick(d, '.modal-overlay button:has-text("Save")');
  if (await req) {
    await d.page.locator('.modal-overlay:has([x-model="modal.expiryDate"])').waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
    await d.wait(900);
  } else {
    await d.click('.modal-overlay:has([x-model="modal.expiryDate"]) button:has-text("Cancel")');
  }
}
const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });

export default {
  title: 'Compliance',
  subtitle: 'Keep every unit’s CVI and registration current, and see at a glance what is expired or about to be.',
  start: '/dashboard',
  intro: 'In this chapter we will read the compliance grid, update expiry dates, and see how expiring documents are flagged to the team.',
  outro: 'That covers compliance. Check the grid weekly, update dates as soon as a new certificate arrives, and act on anything amber or red.',
  scenes: [
    S('Open Compliance from the left sidebar. This grid lists every active unit with its C V I, the commercial vehicle inspection, and its registration.', async (d) => {
      await d.nav('Compliance', '/compliance');
    }, 'Open Compliance from the left sidebar. This grid lists every active unit with its CVI (commercial vehicle inspection) and its registration.'),
    S('The tiles count the units tracked, units with at least one expired document, and units with something expiring in the next 30 days. Inactive and decommissioned units are left out.', async (d) => {
      await d.highlight('.stat-grid', 'Compliance tiles', 3400);
    }),
    S('Each date cell is colour coded: red is expired, amber expires within 30 days, green is valid for longer, and grey means no date has been entered.', async (d) => {
      await d.highlight('xpath=//span[normalize-space()="Expiring within 30 days"]/..', 'Colour legend', 3000);
    }),
    S('Filter by yard or unit status, or use the expiry window to see only units expiring or expired within 7 to 90 days. You can also sort by C V I or registration expiry.', async (d) => {
      await d.hover('[x-model="filters.yard"]', 500);
      await d.hover('[x-model="filters.status"]', 500);
      await d.select('[x-model="filters.window"]', '30');
      await d.wait(1200);
      await d.select('[x-model="filters.window"]', '0');
      await d.hover('[x-model="sort"]', 600);
    }, 'Filter by yard or unit status, or use the expiry window to see only units expiring or expired within 7 to 90 days. You can also sort by CVI or registration expiry.'),
    S('Export C S V downloads the grid with your current filters, including each document’s status, for audits or your insurer.', async (d) => {
      await d.hover('button:has-text("Export CSV")', 2200);
    }),
    S("A new C V I certificate has come in for unit T R 7002. Search for the unit, then click its C V I cell.", async (d) => {
      await findUnit(d, 'TR-7002');
      await d.click('table tbody tr .compliance-cell-edit >> nth=0');
      await d.wait(900);
    }, 'A new CVI certificate has come in for unit TR-7002. Search for the unit, then click its CVI cell.'),
    S('Enter Valid From, the date on the certificate, and Expiry, the date it runs out. Save updates the grid and the tiles straight away.', async (d) => {
      await d.page.fill('.modal-overlay [x-model="modal.fromDate"]', day(-347));
      await d.wait(700);
      await d.hover('.modal-overlay [x-model="modal.expiryDate"]', 300);
      await d.page.fill('.modal-overlay [x-model="modal.expiryDate"]', day(18));
      await d.wait(900);
      await saveOrCancel(d);
    }),
    S('This one expires in less than 30 days, so the cell turns amber and the Expiring tile goes up. Book the re-inspection now.', async (d) => {
      await d.highlight('table tbody tr >> nth=0', 'Expiring soon', 2200);
      await d.highlight('.stat-card:has-text("Expiring Within 30 Days")', 'Tile updated', 2000);
    }),
    S('Do the same for the registration. With an expiry months away, it shows green.', async (d) => {
      await setDates(d, 1, day(-110), day(255));
      await d.highlight('table tbody tr >> nth=0', 'Valid', 1600);
    }),
    S('Expired documents show red. Here unit F B 7042’s registration lapsed last week. FleetForge does not stop a lease on an expired document, so check this page before a unit goes out.', async (d) => {
      await d.type('[x-model="filters.q"]', '');
      await findUnit(d, 'FB-7042');
      await setDates(d, 1, day(-371), day(-6));
      await d.highlight('table tbody tr >> nth=0', 'Expired', 1800);
    }, 'Expired documents show red. Here unit FB-7042’s registration lapsed last week. FleetForge does not stop a lease on an expired document, so check this page before a unit goes out.'),
    S('To clear a wrong date, open the cell, empty the Expiry field and save. The cell goes back to grey, not set.', async (d) => {
      await d.click('table tbody tr .compliance-cell-edit >> nth=0');
      await d.wait(700);
      await d.highlight('.modal-overlay .modal', 'Leave Expiry empty to clear it', 2400);
      await d.click('.modal-overlay:has([x-model="modal.expiryDate"]) button:has-text("Cancel")');
    }),
    S('The Compliance entry in the sidebar carries a red badge: the number of units with a document expired or due within 30 days. It updates every time a page loads.', async (d) => {
      await d.goto('/compliance');
      await d.highlight('aside a:has-text("Compliance"), nav a:has-text("Compliance")', 'Alerts badge', 3000);
    }),
    S('Every morning a scheduled job also sends in-app alerts to staff for documents that are expired, due within 7 days, or due within 30 days, grouped by unit.', async (d) => {
      await d.click('.stat-card:has-text("Expired Documents")');
      await d.wait(1500);
      await d.click('.stat-card:has-text("Expired Documents")');
    }),
    S('Customer emails about expiring documents are separate, and they are switched off. That switch lives in Settings, Customer Emails, and should only be turned on deliberately.', async (d) => {
      await d.goto('/settings?tab=customer_notifications');
      await d.wait(1200);
      const card = 'xpath=//span[normalize-space()="Compliance document expiry"]/ancestor::div[contains(concat(" ", @class, " "), " card ")][1]';
      if (await d.exists(card, 3000)) await d.highlight(card, 'Off by default', 3000);
    }),
    S('The same dates appear on each unit. On the equipment page, the Compliance tab shows days remaining and the renewal interval for C V I and registration.', async (d) => {
      await d.goto('/equipment/show?id=2');
      await d.click('button.tab-btn:has-text("Compliance")');
      await d.wait(1200);
      await d.highlight('.card:has(.card-title:has-text("Compliance Documents"))', 'Unit compliance', 2800);
    }, 'The same dates appear on each unit. On the equipment page, the Compliance tab shows days remaining and the renewal interval for CVI and registration.'),
    S('This page covers C V I and registration only. M V I and insurance dates are not managed on these screens.', async (d) => {
      await d.wait(2500);
    }, 'This page covers CVI and registration only. MVI and insurance dates are not managed on these screens.'),
    S('Keep the certificate itself with the dates. On the unit’s Documents tab, upload the C V I or registration P D F with its expiry date, and the compliance grid is updated and shows a P D F badge.', async (d) => {
      await d.click('button.tab-btn:has-text("Documents")');
      await d.wait(1200);
      await d.hover('.card:has(.card-title:has-text("Compliance Documents")) button:has-text("+ Upload")', 2000);
    }, 'Keep the certificate itself with the dates. On the unit’s Documents tab, upload the CVI or registration PDF with its expiry date, and the compliance grid is updated and shows a PDF badge.'),
  ],
};
