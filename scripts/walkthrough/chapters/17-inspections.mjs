/*
 * Chapter 17 — Inspections
 * The inspection list, reading a signed check-out inspection, starting a pre-lease inspection from a
 * lease (unit, lease and type filled in), recording the nine sections (condition + notes, a photo,
 * the tire table and the trailer checklist), Mark Complete (overall condition is worked out) and
 * Sign Off, then the links back to the lease and to damage claims.
 *
 * Records created on commit: one Pre-Lease inspection on unit DV-7023 linked to Summit Carriers Ltd.'s
 * pending lease CN-DEMO-49C1AF-2026 (lease id 34), with its 9 auto-created sections, one uploaded photo
 * (a generated PNG stored through the local StorageClient driver), and status taken to Signed.
 * The damage-claim button is only hovered.
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   ensurePhoto()   — writes a small generated PNG to training-videos/.cache/wt-assets/ for the upload.
 *   commitClick()   — tolerates the recorder's dry-run "log is not defined" throw on skipped commits.
 *   commitReload()  — commit-click whose handler POSTs then reloads; waits only if the POST fired.
 * Without WT_COMMIT the inspection is never created, so the conduct scenes only highlight the signed
 * demo inspection INSP-2025-0010 instead.
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');
const LEASE = '/leases/show?id=34'; // CN-DEMO-49C1AF-2026 — Summit Carriers Ltd., DV-7023, pending
const FALLBACK = '/inspections/show?id=28'; // INSP-2025-0010 — signed pre-lease, Summit, TR-7003
let live = false;

function ensurePhoto() {
  const file = path.join(ROOT, 'training-videos/.cache/wt-assets/left-side-scuff.png');
  if (fs.existsSync(file)) return file;
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const W = 480, H = 320;
  const raw = Buffer.alloc((W * 3 + 1) * H);
  for (let y = 0; y < H; y++) {
    raw[y * (W * 3 + 1)] = 0;
    for (let x = 0; x < W; x++) {
      const o = y * (W * 3 + 1) + 1 + x * 3;
      let v = 150 + ((x * 7 + y * 13) % 23) - 11 + Math.round(20 * Math.sin(y / 9));
      const dist = Math.abs(y - (0.45 * x + 60));
      if (dist < 10 && x > 90 && x < 400) v -= 70 - dist * 5; // dark diagonal scuff
      raw[o] = v; raw[o + 1] = v + 4; raw[o + 2] = v + 10;
    }
  }
  const crcT = []; for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcT[n] = c >>> 0; }
  const crc = (b) => { let c = 0xffffffff; for (const byte of b) c = crcT[(c ^ byte) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(W, 0); ihdr.writeUInt32BE(H, 4); ihdr[8] = 8; ihdr[9] = 2;
  fs.writeFileSync(file, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]));
  return file;
}

async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}
async function commitReload(d, sel, apiRe, caption) {
  const req = d.page.waitForRequest((r) => r.method() === 'POST' && apiRe.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
  const load = d.page.waitForEvent('load', { timeout: 25000 }).then(() => true, () => false);
  await commitClick(d, sel);
  if (!(await req)) return false;
  await load;
  await d.ready();
  await d.caption(caption);
  return true;
}
const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });
const CONFIRM = '#ff-confirm-modal .modal-footer button:has-text("Confirm")';

export default {
  title: 'Inspections',
  subtitle: 'A dated, photographed record of each unit’s condition when it goes out, comes back, or is checked on schedule.',
  start: '/dashboard',
  intro: 'In this chapter we will review inspections, record a pre-lease inspection section by section, complete and sign it, and see how it links to the lease and to damage claims.',
  outro: 'That covers inspections. Record every check-out and check-in, attach photos, and sign the report so it stands up if damage is disputed.',
  scenes: [
    S('Open Inspections from the left sidebar. Every walkaround is stored here: pre-lease when a unit goes out, post-lease when it comes back, plus periodic, damage and compliance checks.', async (d) => {
      await d.nav('Inspections', '/inspections');
    }),
    S('The tiles count all inspections, drafts still in progress, completed ones awaiting a signature, and those signed this month.', async (d) => {
      await d.highlight('.stat-grid', 'Inspection tiles', 3000);
    }),
    S('Each row shows the inspection number, type, unit, date, inspector, the linked lease, the overall condition and the status. Search, or filter by status and type.', async (d) => {
      await d.highlight('table thead', 'Inspection list', 2400);
      await d.hover('[x-model="filters.status"]', 500);
      await d.select('[x-model="filters.inspection_type"]', 'pre_lease');
      await d.wait(1000);
      await d.select('[x-model="filters.inspection_type"]', '');
    }),
    S("Let's open a signed one. This is the check-out inspection for Summit Carriers on unit T R 7003.", async (d) => {
      await d.goto(FALLBACK);
    }, "Let's open a signed one. This is the check-out inspection for Summit Carriers on unit TR-7003."),
    S('The header links to the lease and names the customer, date and inspector. The tiles record overall condition, mileage, reefer hours, fuel, C V I expiry and cleanliness.', async (d) => {
      await d.highlight('.page-header', 'Unit, lease, customer', 2400);
      await d.highlight('.stat-grid', 'Condition at a glance', 2600);
    }, 'The header links to the lease and names the customer, date and inspector. The tiles record overall condition, mileage, reefer hours, fuel, CVI expiry and cleanliness.'),
    S('Signed inspections are locked for good. That is what makes them reliable evidence if there is a dispute about damage later.', async (d) => {
      await d.highlight('.card:has-text("Status transitions")', 'Signed and locked', 2800);
    }),
    S('Inspections are usually started from the lease. Here is a pending lease for Summit Carriers. Open its Inspections tab.', async (d) => {
      await d.goto(LEASE);
      await d.click('button.tab-btn:has-text("Inspections")');
      await d.wait(1200);
    }),
    S('Use Pre-Lease Inspection before the unit leaves the yard, and Post-Lease Inspection when it comes back. Click Pre-Lease Inspection.', async (d) => {
      await d.hover('a:has-text("+ Post-Lease Inspection")', 800);
      await d.click('a:has-text("+ Pre-Lease Inspection")', { nav: true });
    }),
    S('The unit, the lease and the inspection type are already filled in from the lease. The date defaults to today and cannot be in the future.', async (d) => {
      await d.highlight('.form-row-2 >> nth=0', 'Filled in from the lease', 2400);
      await d.hover('[x-model="form.inspection_date"]', 800);
    }),
    S('Enter who did the walkaround, and link the staff user if they have a login.', async (d) => {
      await d.type('[x-model="form.inspected_by"]', 'Jordan Mills');
      await d.type('.form-group:has(> label:has-text("Inspector (User)")) .ff-picker-input', 'Training', { delay: 60 });
      await d.wait(1200);
      await d.click('.ff-picker-option:has-text("Training Admin")');
    }),
    S('Record the odometer, reefer hours on refrigerated units, fuel level and the unit’s C V I expiry. This expiry is saved on the inspection only; the fleet compliance dates are updated on the Compliance page.', async (d) => {
      await d.type('input[placeholder="km or miles"]', '186420');
      await d.hover('input[placeholder="hours"]', 500);
      await d.hover('[x-model="form.fuel_level"]', 500);
      const t = new Date(Date.now() + 200 * 86400000).toISOString().slice(0, 10);
      await d.hover('[x-model="form.cvi_expiry"]', 300);
      await d.page.fill('[x-model="form.cvi_expiry"]', t);
      await d.wait(400);
    }, 'Record the odometer, reefer hours on refrigerated units, fuel level and the unit’s CVI expiry. This expiry is saved on the inspection only; fleet compliance dates are updated on the Compliance page.'),
    S('Mark whether the unit was clean, add any general notes, then click Create Inspection.', async (d, cap) => {
      await d.click('label:has(input[name="is_clean"]):has-text("Clean")');
      await d.type('[x-model="form.notes"]', 'Walkaround with the customer’s driver before pickup. Doors, seals and lights checked.', { delay: 20 });
      const req = d.page.waitForRequest((r) => r.method() === 'POST' && /inspections\/create/.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
      await commitClick(d, 'button:has-text("Create Inspection")');
      if (await req) {
        live = await d.page.waitForURL(/inspections\/show\?id=\d+/, { timeout: 20000 }).then(() => true, () => false);
        await d.ready();
        await d.caption(cap);
      }
    }),
    S('The inspection opens as a Draft with nine sections: the four walls, roof, floor, interior, tires, and the trailer checklist.', async (d) => {
      if (!live) { await d.goto(FALLBACK); await d.highlight('h1', 'Inspection', 2000); return; }
      await d.highlight('h1', 'Draft', 1800);
      await d.scroll(500);
      await d.highlight('[id^="section-"] >> nth=0', 'One card per section', 2200);
    }),
    S('For each wall, set the condition: O K, fair, damaged, missing, or not applicable. It saves as soon as you change it.', async (d) => {
      if (!live) { await d.highlight('.stat-grid .stat-card >> nth=0', 'Overall condition', 2400); return; }
      await d.select('select[id^="cond-"] >> nth=0', 'fair');
      await d.wait(900);
    }, 'For each wall, set the condition: OK, fair, damaged, missing, or not applicable. It saves as soon as you change it.'),
    S('Describe what you see in the notes. Be specific about location and size, because this is what you will compare against at check-in.', async (d) => {
      if (!live) return d.wait(1500);
      await d.type('textarea[id^="notes-"] >> nth=0', 'Light scuff along the lower rail, about 30 cm, just ahead of the rear axle. No dent.', { delay: 22 });
      await d.press('Tab');
      await d.wait(900);
    }),
    S('Add photos to back it up. Click Add Photo on the section and choose a picture from your phone or computer. JPEG, PNG and HEIC are accepted, up to twenty per inspection.', async (d) => {
      if (!live) return d.wait(1500);
      const file = ensurePhoto();
      await d.hover('label:has-text("+ Add Photo") >> nth=0', 900);
      const req = d.page.waitForRequest((r) => r.method() === 'POST' && /inspections\/photos\/upload/.test(r.url()), { timeout: 5000 }).then(() => true, () => false);
      await d.page.locator('[id^="section-"] input[type="file"]').first().setInputFiles(file);
      if (await req) await d.wait(1500);
      await d.highlight('[id^="photos-"] >> nth=0', 'Photo attached', 1800);
    }),
    S('The Tires section has a row for every wheel position. Enter brake and tread readings, brand and wheel type, then click Save Tire Data.', async (d) => {
      if (!live) return d.wait(1500);
      await d.page.locator('[id^="tire-table-"]').first().scrollIntoViewIfNeeded();
      await d.type('input[data-tire="LO.1"][data-field="brakes"]', '8');
      await d.type('input[data-tire="LO.1"][data-field="tread"]', '12');
      await d.type('input[data-tire="LO.1"][data-field="brand"]', 'Bridgestone');
      await d.select('select[data-tire="LO.1"][data-field="wheels"]', 'AL');
      await d.click('button:has-text("Save Tire Data")');
      await d.wait(900);
    }),
    S('The Trailer Condition checklist covers mud flaps, lights, canlocks, landing gear, inflation, skirts and rub rail, each with a code from the legend. Click Save Trailer Condition.', async (d) => {
      if (!live) return d.wait(1500);
      await d.page.locator('[id^="trailer-table-"]').first().scrollIntoViewIfNeeded();
      await d.select('select[data-trailer="mud_flaps"]', 'S');
      await d.type('input[data-trailer="mud_flaps"][data-field="notes"]', 'Right flap scratched', { delay: 25 });
      await d.click('button:has-text("Save Trailer Condition")');
      await d.wait(900);
    }),
    S('When the walkaround is done, scroll up and click Mark Complete. The overall condition is set from the worst wall, roof, floor or interior condition, so our fair section makes this inspection Fair.', async (d, cap) => {
      if (!live) return d.highlight('.stat-grid .stat-card >> nth=0', 'Overall condition', 2600);
      await d.scroll(-6000);
      await d.click('button:has-text("Mark Complete")');
      await d.wait(700);
      if (!(await commitReload(d, CONFIRM, /inspections\/update_status/, cap))) await d.press('Escape');
      await d.highlight('.stat-grid .stat-card >> nth=0', 'Overall condition', 2000);
    }),
    S('A completed inspection can no longer be edited. A manager can still re-open it as a draft if something was missed, until it is signed.', async (d) => {
      if (await d.exists('button:has-text("Re-open (Draft)")', 1500)) await d.hover('button:has-text("Re-open (Draft)")', 1800);
      else await d.highlight('.card:has-text("Status transitions")', 'Status', 2000);
    }),
    S('Click Sign Off to finalise the report. Signing stamps the date and time and locks it permanently.', async (d, cap) => {
      if (!live) return d.highlight('.card:has-text("Status transitions")', 'Signed', 2400);
      await d.click('button:has-text("Sign Off")');
      await d.wait(700);
      if (!(await commitReload(d, CONFIRM, /inspections\/update_status/, cap))) await d.press('Escape');
      await d.highlight('.card:has-text("Status transitions")', 'Signed', 1800);
    }),
    S('If the inspection found damage the customer is responsible for, Create Damage Claim starts a new claim with this lease filled in, so repair costs can be recovered.', async (d) => {
      if (await d.exists('a:has-text("+ Create Damage Claim")', 2000)) await d.hover('a:has-text("+ Create Damage Claim")', 2200);
    }),
    S('Back on the lease, the Inspections tab now lists this report. At check-in, create the post-lease inspection from the same tab and compare the two.', async (d) => {
      await d.goto(LEASE);
      await d.click('button.tab-btn:has-text("Inspections")');
      await d.wait(1500);
      if (await d.exists('.tab-table-container table', 2000)) await d.highlight('.tab-table-container table', 'Inspections on this lease', 2200);
    }),
    S('The same history is on each unit’s equipment page under its Inspections tab, across every lease it has been on.', async (d) => {
      await d.goto('/equipment/show?id=23');
      await d.click('button.tab-btn:has-text("Inspections")');
      await d.wait(1800);
    }),
  ],
};
