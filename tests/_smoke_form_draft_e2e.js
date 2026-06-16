// tests/_smoke_form_draft_e2e.js
//
// FF_FormDraft autosave — real-page browser e2e (Part D of the verification).
// Standalone node script using the installed `playwright` library (no
// @playwright/test runner, matching the repo's _smoke_*.js convention). Drives
// the REAL authenticated admin pages on a local `php -S` dev server.
//
// Covers the manual checklist deterministically:
//   1+2. equipment unit edit: fill -> refresh/Back -> restore banner -> Restore rehydrates
//   3.   successful save -> revisit -> NO restore prompt (cleared)
//   4.   Discard on the banner -> revisit -> no prompt
//   5.   invoice create: an excluded picker/credential field is NOT in the stored
//        draft, while ordinary fields (po_number, notes) ARE
//   6.   lease create: multi-section fill restores fully
//
// Auth: mints a session via tests/e2e/_mint_session.php (the app's own
// auth_login() — NO password) and sets it as the ff_session cookie via
// context.addCookies (handles httponly cleanly). Saves write captured ORIGINAL
// values back, so dev data is unchanged.
//
// Run:   FF_PHP="/path/to/herd/php" node tests/_smoke_form_draft_e2e.js
// Exit:  0 on all PASS, 1 on any FAIL. Self-starts php -S on :8899 if not up.

'use strict';
const { chromium }                 = require('playwright');
const { execFileSync, spawn }      = require('child_process');
const http                         = require('http');
const path                         = require('path');

const PHP  = process.env.FF_PHP || 'php';
const PORT = 8899;
const BASE = `http://localhost:${PORT}`;
const P    = '/fleetforge';
const ROOT = path.join(__dirname, '..');

let pass = 0, fail = 0;
function ok(label, cond, detail) {
    if (cond) { pass++; console.log(`  PASS  ${label}`); }
    else { fail++; console.log(`  FAIL  ${label}${detail ? '  — ' + detail : ''}`); }
}

function httpStatus(url) {
    return new Promise((resolve) => {
        const req = http.get(url, (res) => { res.resume(); resolve(res.statusCode || 0); });
        req.on('error', () => resolve(0));
        req.setTimeout(1500, () => { req.destroy(); resolve(0); });
    });
}
async function waitForServer(ms) {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if (await httpStatus(`${BASE}${P}/auth/login`) > 0) return true;
        await new Promise(r => setTimeout(r, 300));
    }
    return false;
}

// page helpers (Alpine + draft)
const ready = async (page, rootSel) => {
    await page.waitForFunction(() => typeof window.FF_FormDraft === 'object', null, { timeout: 15000 });
    await page.waitForSelector(rootSel, { timeout: 15000 });
};
const draftKey   = (page, s) => page.evaluate((sel) => window.Alpine.$data(document.querySelector(sel))._draft.key, s);
const clearDraft = (page, k) => page.evaluate((key) => localStorage.removeItem(key), k);
const lsGet      = (page, k) => page.evaluate((key) => localStorage.getItem(key), k);
const sliceOf    = (page, k) => page.evaluate((key) => { const r = localStorage.getItem(key); return r ? JSON.parse(r).s._main.d : null; }, k);
const banners    = (page) => page.locator('.ff-draft-banner').count();
const clickBanner = (page, label) => page.locator('.ff-draft-banner button', { hasText: label }).click();

(async () => {
    console.log('FF_FormDraft real-page e2e (Playwright)');
    console.log('='.repeat(72));

    // ── ensure dev server ──
    let server = null;
    if (!(await waitForServer(1000))) {
        // APP_URL must match the served origin so base_url() renders same-origin
        // POST endpoints (otherwise base_url points at the configured prod/Herd
        // host and the cross-origin POST fails).
        server = spawn(PHP, ['-S', `localhost:${PORT}`, '-t', 'public', 'public/index.php'],
            { cwd: ROOT, stdio: 'ignore', env: { ...process.env, APP_URL: BASE } });
        if (!(await waitForServer(15000))) { console.error('dev server failed to start'); process.exit(1); }
    }

    // ── mint auth session (no password) ──
    const sid = execFileSync(PHP, [path.join(__dirname, 'e2e', '_mint_session.php')], { cwd: ROOT, encoding: 'utf8' }).trim();
    if (!sid) { console.error('session mint failed'); if (server) server.kill(); process.exit(1); }
    console.log(`auth: minted session ${sid.slice(0, 8)}…`);

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: BASE });
    await context.addCookies([{ name: 'ff_session', value: sid, domain: 'localhost', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
    const page = await context.newPage();

    const UNIT = '[x-data*="FF_EditUnit"]';
    const UNIT_EDIT = `${P}/equipment/edit?id=1`;

    try {
        // ── confirm authenticated ──
        await page.goto(UNIT_EDIT);
        ok('auth: real edit page loaded (not bounced to login)', !page.url().includes('/auth/login'), page.url());
        await ready(page, UNIT);

        // 1+2 — refresh mid-edit -> banner -> Restore
        {
            const key = await draftKey(page, UNIT);
            ok('1a draft key = ff_draft:equipment-unit-edit:1', key === 'ff_draft:equipment-unit-edit:1', key);
            await clearDraft(page, key);
            await page.fill('[x-model="form.notes"]', 'E2E refresh note');
            await page.fill('[x-model="form.license_plate"]', 'E2E-1');
            await page.waitForTimeout(700);
            await page.reload();
            await ready(page, UNIT);
            ok('2a restore banner shown after refresh', (await banners(page)) === 1);
            await clickBanner(page, 'Restore');
            ok('2b Restore rehydrated notes', (await page.locator('[x-model="form.notes"]').inputValue()) === 'E2E refresh note');
            ok('2c Restore rehydrated license_plate', (await page.locator('[x-model="form.license_plate"]').inputValue()) === 'E2E-1');
            await clearDraft(page, key);
        }

        // 1(back) — browser Back -> value recoverable
        {
            await page.goto(UNIT_EDIT); await ready(page, UNIT);
            const key = await draftKey(page, UNIT);
            await clearDraft(page, key);
            await page.fill('[x-model="form.notes"]', 'E2E back note');
            await page.waitForTimeout(700);
            await page.goto(`${P}/equipment`);
            await page.goBack();
            await ready(page, UNIT);
            if ((await banners(page)) > 0) await clickBanner(page, 'Restore');
            ok('1b value recoverable after browser Back', (await page.locator('[x-model="form.notes"]').inputValue()) === 'E2E back note');
            await clearDraft(page, key);
        }

        // 3 — successful save clears draft (zero-mutation: write originals back)
        {
            await page.goto(UNIT_EDIT); await ready(page, UNIT);
            const key = await draftKey(page, UNIT);
            await clearDraft(page, key);
            const origNotes = await page.locator('[x-model="form.notes"]').inputValue();
            const origPlate = await page.locator('[x-model="form.license_plate"]').inputValue();
            await page.fill('[x-model="form.notes"]', 'E2E to-be-saved');
            await page.waitForTimeout(700);
            ok('3a draft exists before save', (await lsGet(page, key)) !== null);
            await page.fill('[x-model="form.notes"]', origNotes);
            await page.fill('[x-model="form.license_plate"]', origPlate);
            const respP = page.waitForResponse((r) => r.url().includes('/equipment/units/update'), { timeout: 15000 });
            await page.evaluate((s) => window.Alpine.$data(document.querySelector(s)).submit(), UNIT);
            const resp = await respP;
            // body can be unreadable once the success-path redirect navigates away,
            // so assert on HTTP status; the draft-clear + no-prompt checks below
            // prove the success branch (clear + redirect) actually ran.
            ok('3b save endpoint returned HTTP 200', resp.status() === 200, `status=${resp.status()}`);
            await page.waitForFunction((k) => localStorage.getItem(k) === null, key, { timeout: 5000 }).catch(() => {});
            ok('3c draft cleared after successful save', (await lsGet(page, key)) === null);
            await page.goto(UNIT_EDIT); await ready(page, UNIT);
            ok('3d no restore prompt on revisit after save', (await banners(page)) === 0);
        }

        // 4 — Discard removes draft, no prompt on revisit
        {
            await page.goto(UNIT_EDIT); await ready(page, UNIT);
            const key = await draftKey(page, UNIT);
            await clearDraft(page, key);
            await page.fill('[x-model="form.notes"]', 'E2E discard note');
            await page.waitForTimeout(700);
            await page.reload(); await ready(page, UNIT);
            ok('4a banner shown', (await banners(page)) === 1);
            await clickBanner(page, 'Discard');
            ok('4b draft removed after Discard', (await lsGet(page, key)) === null);
            await page.goto(UNIT_EDIT); await ready(page, UNIT);
            ok('4c no prompt on revisit after Discard', (await banners(page)) === 0);
        }

        // 5 — invoice: excluded picker/credential field absent; ordinary fields present
        {
            const ROOT_I = '[x-data*="FF_InvoiceCreate"]';
            await page.goto(`${P}/invoices/create`); await ready(page, ROOT_I);
            const key = await draftKey(page, ROOT_I);
            ok('5a invoice draft key = ff_draft:invoice-create:new', key === 'ff_draft:invoice-create:new', key);
            await clearDraft(page, key);
            await page.evaluate((s) => { window.Alpine.$data(document.querySelector(s)).form.lease_id = 999; }, ROOT_I);
            await page.fill('[x-model="form.po_number"]', 'PO-E2E-5');
            await page.fill('[x-model="form.notes"]', 'invoice e2e note');
            await page.evaluate((s) => window.Alpine.$data(document.querySelector(s))._draft.scheduleSave(), ROOT_I);
            await page.waitForTimeout(700);
            const d = await sliceOf(page, key);
            ok('5b invoice draft written', d !== null);
            ok('5c excluded lease_id (picker) NOT in stored draft', d && !('lease_id' in d));
            ok('5d excluded odometer_source NOT in stored draft', d && !('odometer_source' in d));
            ok('5e po_number IS retained', d && d.po_number === 'PO-E2E-5');
            ok('5f notes IS retained', d && d.notes === 'invoice e2e note');
            await clearDraft(page, key);
        }

        // 6 — lease create multi-section restore
        {
            const ROOT_L = '[x-data*="FF_CreateLease"]';
            await page.goto(`${P}/leases/create`); await ready(page, ROOT_L);
            const key = await draftKey(page, ROOT_L);
            ok('6a lease draft key = ff_draft:lease-create:new', key === 'ff_draft:lease-create:new', key);
            await clearDraft(page, key);
            await page.fill('[x-model="form.contract_number"]', 'CN-E2E-6');
            await page.fill('[x-model="form.monthly_rate"]', '4321');
            await page.fill('[x-model="form.notes"]', 'lease e2e note');
            await page.waitForTimeout(700);
            await page.reload(); await ready(page, ROOT_L);
            ok('6b banner shown', (await banners(page)) === 1);
            await clickBanner(page, 'Restore');
            ok('6c section-1 contract_number restored', (await page.locator('[x-model="form.contract_number"]').inputValue()) === 'CN-E2E-6');
            ok('6d section-3 monthly_rate restored', (await page.locator('[x-model="form.monthly_rate"]').inputValue()) === '4321');
            ok('6e section-7 notes restored', (await page.locator('[x-model="form.notes"]').inputValue()) === 'lease e2e note');
            await clearDraft(page, key);
        }
    } finally {
        await browser.close();
        if (server) server.kill();
    }

    console.log('-'.repeat(72));
    console.log(`PASS: ${pass}   FAIL: ${fail}   TOTAL: ${pass + fail}`);
    process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
