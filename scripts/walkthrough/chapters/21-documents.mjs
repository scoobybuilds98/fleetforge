/*
 * Chapter 21 — Documents
 * The global document library (search, type filter, sort, columns), uploading from the library by
 * record type + ID, the same files on the customer page, uploading a CVI certificate from a unit's
 * Documents tab (which also updates the compliance grid), lease documents, and View / Remove.
 *
 * Records created on commit (files go through StorageClient; the dev driver is `local`, so they land
 * under storage/ on this machine — nothing is sent to S3):
 *   1. documents row — customer 5 (Summit Carriers Ltd.), type credit_agreement,
 *      title "Signed credit agreement — 2026", file summit-credit-agreement.pdf (generated).
 *   2. documents row — equipment unit 27 (RF-7027), type cvi, expiration today + 340 days, file
 *      rf-7027-cvi-certificate.pdf (generated). Side effect: equipment_units.cvi_document and
 *      cvi_expiry for RF-7027 are updated (the compliance-grid sync in api/v1/documents/upload.php).
 * View and Remove are only hovered.
 *
 * Helpers implemented inline (recorder.mjs untouched):
 *   ensurePdf()   — writes a small valid one-page PDF to training-videos/.cache/wt-assets/.
 *   commitClick() — tolerates the recorder's dry-run "log is not defined" throw on skipped commits.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');

function ensurePdf(name, lines) {
  const file = path.join(ROOT, 'training-videos/.cache/wt-assets', name);
  if (fs.existsSync(file)) return file;
  fs.mkdirSync(path.dirname(file), { recursive: true });
  const esc = (t) => t.replace(/[\\()]/g, (m) => '\\' + m);
  const stream = ['BT', '/F1 20 Tf', '72 720 Td', `(${esc(lines[0])}) Tj`, '/F1 12 Tf', ...lines.slice(1).flatMap((l) => ['0 -24 Td', `(${esc(l)}) Tj`]), 'ET'].join('\n');
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${Buffer.byteLength(stream)} >>\nstream\n${stream}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  let out = '%PDF-1.4\n';
  const offsets = objs.map((o, i) => { const at = Buffer.byteLength(out); out += `${i + 1} 0 obj\n${o}\nendobj\n`; return at; });
  const xref = Buffer.byteLength(out);
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`;
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  fs.writeFileSync(file, out);
  return file;
}

async function commitClick(d, sel, opts = {}) {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
}
const uploadReq = (d) => d.page.waitForRequest((r) => r.method() === 'POST' && /documents\/upload/.test(r.url()), { timeout: 4000 }).then(() => true, () => false);
const S = (say, run, caption) => ({ say, caption, run: (d) => run(d, caption || say) });
const LIB_MODAL = '.modal:has(h3:has-text("Upload Document"))';

export default {
  title: 'Documents',
  subtitle: 'One library for every file attached to customers, units, leases, inspections and damage claims.',
  start: '/dashboard',
  intro: 'In this chapter we will search the document library, upload files and attach them to the right record, and see where those files show up.',
  outro: 'That covers documents. Attach every signed agreement and certificate to its record, with an expiry date where there is one, so anyone can find it in seconds.',
  scenes: [
    S('Open Documents from the left sidebar. This library lists every uploaded file in one place, whichever record it belongs to.', async (d) => {
      await d.nav('Documents', '/documents');
    }),
    S('Search by title or file name, filter by what the file is attached to: customers, equipment, leases, inspections or damage claims, and sort by upload date, expiry, title, type or size.', async (d) => {
      await d.hover('[x-model="filters.q"]', 500);
      await d.select('[x-model="filters.entity_type"]', 'customer');
      await d.wait(900);
      await d.select('[x-model="filters.entity_type"]', '');
      await d.hover('[x-model="filters.sort"]', 700);
    }),
    S("Let's file Summit Carriers’ signed credit agreement. Click Upload.", async (d) => {
      await d.click('.page-header button:has-text("+ Upload")');
      await d.wait(600);
    }),
    S('First say what the file belongs to. Choose Customer, then enter the record’s ID number: it is the number after id equals in the address bar of that customer’s page. Summit Carriers is 5.', async (d) => {
      await d.select(`${LIB_MODAL} [x-model="uploadModal.entity_type"]`, 'customer');
      await d.type(`${LIB_MODAL} [x-model="uploadModal.entity_id"]`, '5', { delay: 120 });
      await d.wait(600);
    }, 'First say what the file belongs to. Choose Customer, then enter the record’s ID: the number after “id=” in the address bar of that customer’s page. Summit Carriers is 5.'),
    S('The document types change with the record type. For a customer you can file a tax exemption certificate, a credit agreement, or other.', async (d) => {
      await d.select(`${LIB_MODAL} [x-model="uploadModal.document_type"]`, 'credit_agreement');
      await d.type(`${LIB_MODAL} [x-model="uploadModal.title"]`, 'Signed credit agreement — 2026', { delay: 30 });
    }),
    S('Add an expiration date if the document runs out, choose the file, P D F, J P E G or P N G up to 20 megabytes, and add a short note.', async (d) => {
      await d.hover(`${LIB_MODAL} [x-model="uploadModal.expiration_date"]`, 600);
      const pdf = ensurePdf('summit-credit-agreement.pdf', ['Credit Agreement', 'Summit Carriers Ltd.', 'Net 30 terms, credit limit $75,000', 'Signed by Kyle Thompson, Summit Carriers Ltd.']);
      await d.hover(`${LIB_MODAL} input[type="file"]`, 400);
      await d.page.locator(`${LIB_MODAL} input[type="file"]`).setInputFiles(pdf);
      await d.wait(600);
      await d.type(`${LIB_MODAL} [x-model="uploadModal.notes"]`, 'Countersigned copy received by email', { delay: 25 });
    }, 'Add an expiration date if the document runs out, choose the file (PDF, JPEG or PNG, up to 20 MB), and add a short note.'),
    S('Click Upload. The file is stored, and the library refreshes with the new document at the top.', async (d) => {
      const req = uploadReq(d);
      await commitClick(d, `${LIB_MODAL} .modal-footer button:has-text("Upload")`);
      if (await req) await d.wait(2500);
      else await d.click(`${LIB_MODAL} .modal-footer button:has-text("Cancel")`);
    }),
    S('Each row shows the document type, its title, the record it is attached to, file size, expiry, when it was uploaded and by whom. Expiry dates turn amber inside 30 days and red once passed.', async (d) => {
      if (await d.exists('table tbody tr', 2500)) await d.highlight('table tbody tr >> nth=0', 'New document', 3200);
      else await d.highlight('.card', 'Document library', 2600);
    }),
    S('The link under the record type takes you to that record. Every customer, unit and lease page has its own Documents tab showing the same files.', async (d) => {
      if (await d.exists('table tbody a[href*="customers/show"]', 1500)) await d.click('table tbody a[href*="customers/show"] >> nth=0', { nav: true });
      else await d.goto('/customers/show?id=5');
      await d.click('button.tab-btn:has-text("Documents")');
      await d.wait(1500);
    }),
    S('Uploading from a record’s own tab is quicker, because the record is already filled in. You only choose the type and the file.', async (d) => {
      await d.hover('.card:has(.card-title:has-text("Customer Documents")) button:has-text("+ Upload")', 1800);
    }),
    S('For equipment, upload certificates from the unit’s Documents tab. Here is unit R F 7027. Click Upload.', async (d) => {
      await d.goto('/equipment/show?id=27');
      await d.click('button.tab-btn:has-text("Documents")');
      await d.wait(1000);
      await d.click('.card:has(.card-title:has-text("Compliance Documents")) button:has-text("+ Upload")');
      await d.wait(600);
    }, 'For equipment, upload certificates from the unit’s Documents tab. Here is unit RF-7027. Click Upload.'),
    S('Choose C V I Certificate, attach the P D F, and enter the expiry date printed on the certificate.', async (d) => {
      await d.select('[x-model="uploadModal.docType"]', 'cvi');
      const pdf = ensurePdf('rf-7027-cvi-certificate.pdf', ['Commercial Vehicle Inspection Certificate', 'Unit RF-7027', 'Result: PASS', 'Inspection facility: Desmond CVI Inspections']);
      await d.page.locator('.modal input[type="file"][accept*="pdf"]').first().setInputFiles(pdf);
      await d.wait(500);
      await d.hover('[x-model="uploadModal.expiryDate"]', 300);
      await d.page.fill('[x-model="uploadModal.expiryDate"]', new Date(Date.now() + 340 * 86400000).toISOString().slice(0, 10));
      await d.wait(700);
    }, 'Choose CVI Certificate, attach the PDF, and enter the expiry date printed on the certificate.'),
    S('Upload Document saves the file to the unit and also copies the expiry date onto the compliance grid, so the dates and the certificate stay in step.', async (d) => {
      const req = uploadReq(d);
      await commitClick(d, 'button:has-text("Upload Document") >> nth=-1');
      if (await req) await d.wait(2500);
      else await d.click('.modal-footer button:has-text("Cancel")');
    }),
    S('Leases have a Documents tab too, for the signed contract, pre-lease and post-lease inspection reports, and amendments.', async (d) => {
      await d.goto('/leases/show?id=42');
      await d.click('button.tab-btn:has-text("Documents")');
      await d.wait(1200);
      await d.hover('button:has-text("+ Upload Document")', 1800);
    }),
    S('Back in the library, filter to Equipment to see unit certificates and their expiry dates together.', async (d) => {
      await d.nav('Documents', '/documents');
      await d.select('[x-model="filters.entity_type"]', 'equipment_unit');
      await d.wait(1500);
      if (await d.exists('table tbody tr', 2000)) await d.highlight('table tbody tr >> nth=0', 'Certificate with expiry', 2200);
    }),
    S('View opens the file in a new browser tab. Remove takes it off the record and out of the library, but the stored file is kept for the audit trail.', async (d) => {
      await d.select('[x-model="filters.entity_type"]', '');
      await d.wait(1200);
      if (await d.exists('table tbody a:has-text("View")', 2000)) {
        await d.hover('table tbody a:has-text("View") >> nth=0', 1200);
        await d.hover('table tbody button:has-text("Remove") >> nth=0', 1500);
      } else {
        await d.wait(2500);
      }
    }),
  ],
};
