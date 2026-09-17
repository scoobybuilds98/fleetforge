/*
 * Chapter 12 — Credit Applications
 * The module list + tiles + filters, what each status means, sending an application from a
 * customer's Credit Application tab (Preview is opened; Send / Re-send is only HOVERED — it
 * emails the customer), reviewing a submitted application, and the three review outcomes.
 *
 * Records changed on commit:
 *   - the first application in "Awaiting Review" (dev data: Cross-Border Logistics USA #41,
 *     then Cascade Freight Lines #40) is saved as Reviewed / Approved with a $50,000 limit, and — via the opt-in
 *     checkbox — that limit is written to the customer's credit limit.
 * No application is created and no email is sent.
 *
 * Applicant view: raw tokens are never stored (only SHA-256 hashes), so no existing link can be
 * opened. The chapter gives the dormant "opened" application (dev id 42) a temporary token + future
 * expiry, opens /credit-application?token=… exactly as the applicant would, and puts the original
 * token_hash / token_expires_at back in a finally block. The status is already 'opened', so the
 * GET does not flip it; nothing is typed or submitted.
 *
 * Helpers implemented inline via d.page: onPage() (skip post-commit checks in plain dry runs)
 * and commitClick() (tolerates the recorder's dry-run `log is not defined` skip-branch error).
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');
const DEMO_APP_ID = 42;
/** Dev-only PHP snippet with the app bootstrapped; returns the last stdout line. */
const php = (code, env = {}) => execFileSync('php', ['-r',
  `require 'config/app.php'; require_once FF_ROOT.'/includes/auth.php'; if (APP_ENV !== 'development') { fwrite(STDERR, "dev only\\n"); exit(1); } ${code}`],
  { cwd: ROOT, encoding: 'utf8', env: { ...process.env, ...env } }).trim().split('\n').pop();
let savedApp = null;
const ccaSection = (n) => `.cca-section-title:has-text("§${n} ")`;

const onPage = (d, frag) => d.page.url().includes(frag);
// lib/overlay.js is injected into EVERY frame, so the filed-application <iframe> mounts its own
// caption pill + cursor (restored from sessionStorage = a stale caption). Remove it from child frames.
const hideFrameOverlays = async (d) => {
  await d.page.waitForTimeout(600);
  for (const f of d.page.frames()) {
    if (f === d.page.mainFrame()) continue;
    await f.evaluate(() => document.getElementById('__wt_host')?.remove()).catch(() => {});
  }
};
const commitClick = async (d, sel, opts = {}) => {
  try { await d.click(sel, { ...opts, commit: true }); } catch (e) { if (!/log is not defined/.test(e.message)) throw e; }
};

export default {
  title: 'Credit Applications',
  subtitle: 'Send customers a secure online credit form, then review what they submit and record your decision.',
  start: '/dashboard',
  intro: 'In this chapter we will track credit applications, send one to a customer, and review a submitted application through to a decision.',
  outro: 'That covers Credit Applications. Remember: nothing the applicant types changes the customer record unless you choose to apply it during review.',
  scenes: [
    {
      say: 'Open Credit Applications from the sidebar. The red badge is the number of applications waiting for someone to review them.',
      run: async (d) => {
        await d.hover('aside a:has-text("Credit Applications"), nav a:has-text("Credit Applications")', 900);
        await d.nav('Credit Applications', '/credit_applications');
      },
    },
    {
      say: 'The tiles count applications awaiting review, everything customers have submitted, those already reviewed, and all applications ever sent. Click a tile to filter the list.',
      run: async (d) => { await d.highlight('.stat-card >> nth=0', 'Awaiting review', 1600); await d.highlight('.stat-card >> nth=3', 'All applications', 1600); },
    },
    {
      say: 'Each row shows the customer, status, who sent it, when it was sent and submitted, the review outcome, and the credit limit that was approved.',
      run: async (d) => { await d.highlight('table thead', 'Applications', 2400); await d.scroll(400); await d.scroll(-400); },
    },
    {
      say: 'Statuses move in order. Sent means the link went out. Opened means the customer clicked it. Submitted means they signed and sent the form back. Reviewed means a decision has been recorded.',
      run: async (d) => {
        await d.highlight('table tbody tr:has-text("Opened") td >> nth=1', 'Opened', 1500);
        await d.highlight('table tbody tr:has-text("Submitted") td >> nth=1', 'Submitted', 1500);
        await d.highlight('table tbody tr:has-text("Approved") td >> nth=1', 'Reviewed', 1500);
      },
    },
    {
      say: 'A link that was never completed expires after thirty days by default, and shows as expired. The expiry and the form’s terms are set under Settings.',
      caption: 'A link that was never completed expires after 30 days by default, and shows as expired. The expiry and the form’s terms are set under Settings.',
      run: async (d) => {
        await d.highlight('table tbody tr:has-text("expired") td >> nth=1', 'Expired link', 2000);
        await d.hover('a:has-text("Settings")', 1200);
      },
    },
    {
      say: 'Filter by status, by outcome, or by a date range, and sort by when applications were sent, submitted or reviewed.',
      run: async (d) => {
        await d.select('[x-model="filters.outcome"]', 'approved');
        await d.wait(1200);
        await d.select('[x-model="filters.outcome"]', '');
        await d.hover('[x-model="filters.date_from"]', 600);
        await d.hover('[x-model="filters.sort"]', 600);
      },
    },
    {
      say: 'Applications are sent from the customer’s own page. Click Customer on a row to go there.',
      run: async (d) => {
        await d.click('table tbody tr:has-text("Coastal Container") a:has-text("Customer")', { nav: true });
      },
    },
    {
      say: 'Open the Credit Application tab. It shows the current status and every application ever sent to this customer, with when it expires.',
      run: async (d) => {
        await d.click('button.tab-btn:has-text("Credit Application")');
        await d.wait(1500);
        await d.highlight('[role="tabpanel"] table, .tab-table-container table', 'Application history', 2200);
      },
    },
    {
      say: 'Preview shows the exact email the customer will receive, with a button that opens the secure online form. The customer does not need a login.',
      run: async (d) => {
        await d.click('[role="tabpanel"] button:has-text("Preview")');
        await d.wait(2200);
        await d.scroll(300, { x: 960, y: 600 });
      },
    },
    {
      say: 'Send Application emails that link. Each send creates a new record and keeps the old ones, so a re-send never erases history. We will not send one now.',
      run: async (d) => {
        await d.click('.modal button:has-text("Close")');
        await d.hover('[role="tabpanel"] button:has-text("Send Application"), [role="tabpanel"] button:has-text("Re-send Application")', 2600);
      },
    },
    {
      say: 'This is what the customer sees when they open the link: your company name at the top and the application form below. There is nothing to log in to.',
      run: async (d) => {
        savedApp = php(`$r = db_row("SELECT token_hash, token_expires_at FROM customer_credit_applications WHERE id = ? AND status = 'opened' AND deleted_at IS NULL", [${DEMO_APP_ID}]); echo base64_encode(json_encode($r));`);
        if (!JSON.parse(Buffer.from(savedApp, 'base64').toString() || 'null')) { savedApp = null; throw new Error('demo application not found'); }
        const token = php(`$t = bin2hex(random_bytes(32)); db_execute("UPDATE customer_credit_applications SET token_hash = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ? AND status = 'opened'", [hash('sha256', $t), ${DEMO_APP_ID}]); echo $t;`);
        await d.goto(`/credit-application?token=${token}`);
        await d.highlight('header.cca-header', 'Your company branding', 2000);
        await d.highlight(ccaSection(1), 'Company information', 1600);
      },
    },
    {
      say: 'The form is split into sections: company information, principals, insurance, equipment, credit information and trade references. Required fields are marked with a star.',
      caption: 'The form is split into sections: company information, principals, insurance, equipment, credit information and trade references. Required fields are marked with *.',
      run: async (d) => {
        await d.highlight(ccaSection(2), 'Principals', 1200);
        await d.highlight(ccaSection(3), 'Insurance', 1200);
        await d.highlight(ccaSection(5), 'Credit information', 1200);
        await d.highlight(ccaSection(6), 'Trade references', 1200);
      },
    },
    {
      say: 'At the end they can upload supporting documents, print their name, accept your terms and draw a signature, then click Submit Application. We will not submit this one.',
      run: async (d) => {
        try {
          await d.highlight(ccaSection(7), 'Document upload', 1300);
          await d.highlight('#sig-canvas', 'Signature pad', 1600);
          await d.hover('button.cca-submit-btn', 1600);
        } finally {
          if (savedApp) {
            php(`$r = json_decode(base64_decode(getenv('WT_SAVED')), true); db_execute("UPDATE customer_credit_applications SET token_hash = ?, token_expires_at = ? WHERE id = ?", [$r['token_hash'], $r['token_expires_at'], ${DEMO_APP_ID}]); echo 'ok';`, { WT_SAVED: savedApp });
            savedApp = null;
          }
        }
      },
    },
    {
      say: 'The customer fills in company, insurance, equipment and credit details, lists trade references, and signs. When they submit, it arrives here as Submitted.',
      run: async (d) => {
        await d.nav('Credit Applications', '/credit_applications');
        await d.click('.stat-card:has-text("Awaiting Review")');
        await d.wait(1200);
      },
    },
    {
      say: "Click View to open one. Let's review the first application waiting.",
      run: async (d) => { await d.click('table tbody a:has-text("View")', { nav: true }); await hideFrameOverlays(d); },
    },
    {
      say: 'On the left is the filed application, exactly as the customer submitted it. It is frozen as a legal record and cannot be edited.',
      run: async (d) => {
        await d.highlight('#cca-snapshot-frame', 'Filed application', 2200);
        await d.scroll(700, { x: 900, y: 650 });
        await d.wait(800);
      },
    },
    {
      say: 'The right side shows the status, who signed and when, whether they accepted the terms, and an audit of the internet address and device they submitted from.',
      run: async (d) => {
        await d.scroll(-700, { x: 900, y: 650 });
        await d.highlight('.card:has(h3:has-text("Signer Details"))', 'Signer details', 1800);
        await d.highlight('.card:has(h3:has-text("Submission Audit"))', 'Submission audit', 1800);
      },
    },
    {
      say: 'Generate PDF saves a PDF copy of the application into Documents, for your records.',
      run: async (d) => { await d.hover('#btn-regen-pdf, button:has-text("Generate PDF")', 1800); },
    },
    {
      say: 'To decide, use the Review panel. Choose Approved, Declined, or Needs Info, and add internal notes explaining why.',
      run: async (d) => {
        await d.scroll(700, { x: 1750, y: 650 });
        await d.click('#review-panel input[name="review_outcome"][value="approved"]');
        await d.type('#review_notes', 'Trade references and insurance verified. Approved with a starting limit; review again in six months.', { delay: 20 });
      },
    },
    {
      say: 'Enter the credit limit you are approving. It starts empty on purpose: you decide the number, it is never copied from what the applicant asked for.',
      run: async (d) => { await d.type('#approved_credit_limit', '50000'); },
    },
    {
      say: 'Nothing changes on the customer’s account unless you tick these boxes. Tick Apply credit limit to write the approved limit to the customer. Update customer status can also change their account status.',
      run: async (d) => {
        await d.click('#apply_credit_limit');
        await d.hover('#apply_customer_status', 1400);
      },
    },
    {
      say: 'Click Save Review. The application becomes Reviewed with its outcome, and the date and reviewer are recorded. No email goes to the customer automatically; tell them the decision yourself.',
      run: async (d) => {
        await commitClick(d, '#review-submit-btn', { nav: true });
        await hideFrameOverlays(d);
        await d.wait(1200);
      },
    },
    {
      say: 'The status now shows Reviewed and Approved, with who reviewed it and when. You can re-open the review later and change it; every change is logged.',
      run: async (d) => {
        if (!onPage(d, '/credit_applications/show')) return;
        await d.scroll(-2000, { x: 1750, y: 600 });
        await d.highlight('.card:has(h3:has-text("Status"))', 'Decision recorded', 2600);
      },
    },
    {
      say: 'If you choose Needs Info, a Re-send Application Link button appears. It sends a fresh link, and your notes are included in the email so the customer knows what to fix.',
      run: async (d) => {
        await d.nav('Credit Applications', '/credit_applications');
        await d.select('[x-model="filters.outcome"]', 'needs_info');
        await d.wait(1500);
        await d.click('table tbody a:has-text("View")', { nav: true });
        await hideFrameOverlays(d);
        await d.scroll(900, { x: 1750, y: 650 });
        await d.hover('#btn-resend', 2200);
      },
    },
    {
      say: 'Whatever the outcome, the application stays on file with your notes. A Declined decision changes nothing on the customer’s account unless you tick one of the boxes.',
      run: async (d) => {
        await d.highlight('#review-panel', 'Review panel', 2400);
      },
    },
  ],
};
