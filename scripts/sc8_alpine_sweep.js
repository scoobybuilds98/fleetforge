#!/usr/bin/env node
/**
 * SC8 Automated Alpine Sweep — S-PROD-1A-FIX-5
 *
 * Verifies that Alpine.js loads from the local vendor path (not CDN)
 * on all 8 representative pages, and that no Alpine-related console
 * errors are present.
 *
 * Usage:
 *   node scripts/sc8_alpine_sweep.js
 *
 * Credentials are read from .env:
 *   FF_TEST_ADMIN_EMAIL / FF_TEST_ADMIN_PASSWORD  (super_admin, no MFA)
 *   FF_TEST_MFA_EMAIL   / FF_TEST_MFA_PASSWORD    (super_admin, mfa_enabled=1)
 *   FF_TEST_PORTAL_EMAIL / FF_TEST_PORTAL_PASSWORD (portal user)
 */

'use strict';

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// ── Load .env ─────────────────────────────────────────────────────────────
function loadEnv(envPath) {
  const env = {};
  if (!fs.existsSync(envPath)) return env;
  const lines = fs.readFileSync(envPath, 'utf8').split('\n');
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq < 0) continue;
    const key = trimmed.slice(0, eq).trim();
    const val = trimmed.slice(eq + 1).trim();
    env[key] = val;
  }
  return env;
}

const envFile = path.join(__dirname, '..', '.env');
const env = loadEnv(envFile);

const ADMIN_EMAIL    = env.FF_TEST_ADMIN_EMAIL    || '';
const ADMIN_PASSWORD = env.FF_TEST_ADMIN_PASSWORD || '';
const MFA_EMAIL      = env.FF_TEST_MFA_EMAIL      || '';
const MFA_PASSWORD   = env.FF_TEST_MFA_PASSWORD   || '';
const PORTAL_EMAIL   = env.FF_TEST_PORTAL_EMAIL   || '';
const PORTAL_PASSWORD = env.FF_TEST_PORTAL_PASSWORD || '';
const BASE_URL       = env.APP_URL || 'http://fleetforge.test';
const BASE_PATH      = '/fleetforge';
const BASE           = BASE_URL + BASE_PATH;

if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
  console.error('ERROR: FF_TEST_ADMIN_EMAIL / FF_TEST_ADMIN_PASSWORD not set in .env');
  process.exit(1);
}
if (!PORTAL_EMAIL || !PORTAL_PASSWORD) {
  console.error('ERROR: FF_TEST_PORTAL_EMAIL / FF_TEST_PORTAL_PASSWORD not set in .env');
  process.exit(1);
}

// ── Page definitions ──────────────────────────────────────────────────────
const PAGES = [
  { name: 'dashboard',        url: BASE + '/dashboard',                 authType: 'admin' },
  { name: 'customers_list',   url: BASE + '/customers',                 authType: 'admin' },
  { name: 'customer_show',    url: BASE + '/customers/show?id=1',       authType: 'admin' },
  { name: 'leases_create',    url: BASE + '/leases/create',             authType: 'admin' },
  { name: 'equipment_list',   url: BASE + '/equipment',                 authType: 'admin' },
  { name: 'mfa_setup_volunt', url: BASE + '/account/mfa_setup',        authType: 'admin' },
  // mfa_challenge uses plain vanilla JS — no Alpine on this page by design.
  // PASS criteria: zero CDN Alpine requests, zero Alpine errors (no Alpine at all is correct).
  { name: 'mfa_challenge',    url: BASE + '/auth/mfa_challenge',        authType: 'mfa', noAlpineExpected: true },
  { name: 'portal_dashboard', url: BASE + '/portal',                    authType: 'portal'},
];

const LOCAL_ALPINE_PATTERN = '/assets/vendor/alpinejs/cdn.min.js';
const CDN_ALPINE_PATTERN_1 = 'cdn.jsdelivr.net';
const CDN_ALPINE_PATTERN_2 = 'alpinejs';
const PAGE_TIMEOUT_MS = 15000;

// ── Login helpers ──────────────────────────────────────────────────────────
async function loginAdmin(page, email, password) {
  await page.goto(BASE + '/auth/login', { waitUntil: 'domcontentloaded', timeout: PAGE_TIMEOUT_MS });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  // Wait for navigation after submit
  await page.waitForNavigation({ timeout: PAGE_TIMEOUT_MS }).catch(() => {});
}

async function loginPortal(page, email, password) {
  await page.goto(BASE + '/portal/auth/login', { waitUntil: 'domcontentloaded', timeout: PAGE_TIMEOUT_MS });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  // Portal login button may be a submit button or input[type=submit]
  const btn = page.locator('button[type="submit"], input[type="submit"]').first();
  await btn.click();
  await page.waitForNavigation({ timeout: PAGE_TIMEOUT_MS }).catch(() => {});
}

// ── Run sweep for one page ─────────────────────────────────────────────────
async function sweepPage(page, pageDef) {
  const requests = [];
  const consoleEvents = [];

  // Capture all requests
  page.on('request', (req) => {
    requests.push({ url: req.url(), method: req.method() });
  });
  // Capture response status for the primary navigation
  const responseStatuses = {};
  page.on('response', (resp) => {
    responseStatuses[resp.url()] = resp.status();
  });
  // Capture console events
  page.on('console', (msg) => {
    consoleEvents.push({ type: msg.type(), text: msg.text() });
  });

  let pageStatus = 0;
  let pageError = null;
  try {
    const response = await page.goto(pageDef.url, {
      waitUntil: 'networkidle',
      timeout: PAGE_TIMEOUT_MS,
    });
    pageStatus = response ? response.status() : 0;
  } catch (err) {
    pageError = err.message;
    pageStatus = 0;
  }

  // Wait a moment for Alpine to initialise and any deferred scripts to fire
  await page.waitForTimeout(1000);

  // Classify requests
  const localAlpineReqs = requests.filter(r => r.url.includes(LOCAL_ALPINE_PATTERN));
  const cdnAlpineReqs   = requests.filter(r =>
    r.url.includes(CDN_ALPINE_PATTERN_1) && r.url.includes(CDN_ALPINE_PATTERN_2)
  );

  // Get response statuses for local Alpine requests
  const localAlpineStatuses = localAlpineReqs.map(r => responseStatuses[r.url] || '?');

  // Classify console events
  const consoleErrors = consoleEvents.filter(e => e.type === 'error');
  const alpineKeywords = ['alpine', 'x-data', 'x-show', 'x-init', 'x-cloak'];
  const alpineErrors = consoleErrors.filter(e =>
    alpineKeywords.some(kw => e.text.toLowerCase().includes(kw))
  );

  // PASS/FAIL logic
  // noAlpineExpected: page uses plain JS, no Alpine — CDN=0 and no errors is PASS
  const noAlpineExpected = pageDef.noAlpineExpected || false;
  const localOk  = noAlpineExpected
    ? true  // no Alpine expected = don't require a local load
    : (localAlpineReqs.length === 1 && localAlpineStatuses[0] === 200);
  const cdnOk    = cdnAlpineReqs.length === 0;
  const consoleOk = alpineErrors.length === 0;
  const statusOk  = pageStatus === 200 || pageStatus === 302;
  const passed    = localOk && cdnOk && consoleOk && statusOk;

  return {
    name: pageDef.name,
    url: pageDef.url,
    localAlpineCount: localAlpineReqs.length,
    localAlpineStatus: localAlpineStatuses[0] || '-',
    cdnAlpineCount: cdnAlpineReqs.length,
    totalConsoleErrors: consoleErrors.length,
    alpineConsoleErrors: alpineErrors.length,
    pageStatus,
    pageError,
    passed,
    // Diagnostic details
    localAlpineUrls: localAlpineReqs.map(r => r.url),
    cdnAlpineUrls: cdnAlpineReqs.map(r => r.url),
    alpineErrorTexts: alpineErrors.map(e => e.text),
    allConsoleErrors: consoleErrors.map(e => e.text),
  };
}

// ── Main ───────────────────────────────────────────────────────────────────
(async () => {
  console.log('SC8 Alpine Sweep — S-PROD-1A-FIX-5');
  console.log('====================================\n');

  const browser = await chromium.launch({ headless: true });
  const results = [];

  try {
    // ── Admin context (no MFA) ─────────────────────────────────────────────
    console.log(`Logging in as admin: ${ADMIN_EMAIL}`);
    const adminCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const adminPage = await adminCtx.newPage();
    await loginAdmin(adminPage, ADMIN_EMAIL, ADMIN_PASSWORD);

    const postLoginUrl = adminPage.url();
    if (postLoginUrl.includes('/auth/login') || postLoginUrl.includes('/auth/mfa')) {
      console.error(`FATAL: Admin login failed. Current URL: ${postLoginUrl}`);
      await browser.close();
      process.exit(1);
    }
    console.log(`Admin login OK → ${postLoginUrl}\n`);

    // Sweep admin pages (all except mfa_challenge)
    for (const pageDef of PAGES.filter(p => p.authType === 'admin')) {
      process.stdout.write(`  Testing ${pageDef.name}... `);
      const freshPage = await adminCtx.newPage();
      const result = await sweepPage(freshPage, pageDef);
      results.push(result);
      await freshPage.close();
      console.log(result.passed ? 'PASS' : 'FAIL');
    }
    await adminCtx.close();

    // ── MFA challenge page ─────────────────────────────────────────────────
    console.log(`\nLogging in as MFA user: ${MFA_EMAIL} (to trigger mfa_challenge)`);
    const mfaCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const mfaPage = await mfaCtx.newPage();
    await loginAdmin(mfaPage, MFA_EMAIL, MFA_PASSWORD);

    const mfaPostUrl = mfaPage.url();
    const mfaChallengeDef = PAGES.find(p => p.authType === 'mfa');

    if (mfaPostUrl.includes('/auth/mfa_challenge')) {
      // We're already on the challenge page — sweep it
      process.stdout.write(`  Testing mfa_challenge (already redirected)... `);
      const result = await sweepPage(mfaPage, mfaChallengeDef);
      results.push(result);
      console.log(result.passed ? 'PASS' : 'FAIL');
    } else if (mfaPostUrl.includes('/auth/mfa_required')) {
      // Hit forced-setup gate — navigate directly to mfa_challenge
      process.stdout.write(`  Testing mfa_challenge (navigating directly)... `);
      const freshMfa = await mfaCtx.newPage();
      const result = await sweepPage(freshMfa, mfaChallengeDef);
      results.push(result);
      await freshMfa.close();
      console.log(result.passed ? 'PASS' : 'FAIL');
    } else {
      // Unexpected — try navigating directly anyway
      console.log(`  mfa_challenge: unexpected post-login URL ${mfaPostUrl}, navigating directly`);
      process.stdout.write(`  Testing mfa_challenge (direct nav)... `);
      const freshMfa = await mfaCtx.newPage();
      const result = await sweepPage(freshMfa, mfaChallengeDef);
      results.push(result);
      await freshMfa.close();
      console.log(result.passed ? 'PASS' : 'FAIL');
    }
    await mfaCtx.close();

    // ── Portal context ─────────────────────────────────────────────────────
    console.log(`\nLogging in as portal user: ${PORTAL_EMAIL}`);
    const portalCtx  = await browser.newContext({ ignoreHTTPSErrors: true });
    const portalPage = await portalCtx.newPage();
    await loginPortal(portalPage, PORTAL_EMAIL, PORTAL_PASSWORD);

    const portalPostUrl = portalPage.url();
    if (portalPostUrl.includes('/portal/auth/login')) {
      console.error(`FATAL: Portal login failed. Current URL: ${portalPostUrl}`);
      await browser.close();
      process.exit(1);
    }
    console.log(`Portal login OK → ${portalPostUrl}`);

    for (const pageDef of PAGES.filter(p => p.authType === 'portal')) {
      process.stdout.write(`  Testing ${pageDef.name}... `);
      const freshPage = await portalCtx.newPage();
      const result = await sweepPage(freshPage, pageDef);
      results.push(result);
      await freshPage.close();
      console.log(result.passed ? 'PASS' : 'FAIL');
    }
    await portalCtx.close();

  } finally {
    await browser.close();
  }

  // ── Results table ─────────────────────────────────────────────────────────
  const passed = results.filter(r => r.passed).length;
  const total  = results.length;

  console.log('\n## SC8 Automated Sweep Results\n');
  console.log('| Page | Local Alpine | CDN Alpine | Console Errors (Alpine) | HTTP Status | Verdict |');
  console.log('|------|-------------|-----------|------------------------|-------------|---------|');

  for (const r of results) {
    const noAlpineExpected = PAGES.find(p => p.name === r.name)?.noAlpineExpected || false;
    const localCell   = noAlpineExpected
      ? `N/A (plain JS)`
      : (r.localAlpineCount === 1 ? `1 ✓ (${r.localAlpineStatus})` : `${r.localAlpineCount} ✗`);
    const cdnCell     = r.cdnAlpineCount === 0 ? '0 ✓' : `${r.cdnAlpineCount} ✗`;
    const consoleCell = r.alpineConsoleErrors === 0
      ? `0 ✓`
      : `${r.alpineConsoleErrors} ✗ (${r.totalConsoleErrors} total)`;
    const statusCell  = (r.pageStatus === 200 || r.pageStatus === 302)
      ? `${r.pageStatus} ✓`
      : `${r.pageStatus || 'ERR'} ✗`;
    const verdict     = r.passed ? '**PASS**' : '**FAIL**';
    console.log(`| ${r.name.padEnd(18)} | ${localCell.padEnd(14)} | ${cdnCell.padEnd(10)} | ${consoleCell.padEnd(22)} | ${statusCell.padEnd(12)} | ${verdict} |`);
  }

  console.log(`\nTotal: **${passed}/${total} PASS**`);

  // ── Failure diagnostics ───────────────────────────────────────────────────
  const failures = results.filter(r => !r.passed);
  if (failures.length > 0) {
    console.log('\n## Failure Diagnostics\n');
    console.log('| Page | What Failed | Detail |');
    console.log('|------|------------|--------|');
    for (const r of failures) {
      const issues = [];
      if (r.localAlpineCount !== 1) issues.push({ what: `Local Alpine count=${r.localAlpineCount}`, detail: r.localAlpineUrls.join(', ') || 'no request found' });
      if (r.localAlpineStatus !== 200 && r.localAlpineCount > 0) issues.push({ what: `Local Alpine HTTP ${r.localAlpineStatus}`, detail: r.localAlpineUrls[0] });
      if (r.cdnAlpineCount > 0) issues.push({ what: 'CDN Alpine request found', detail: r.cdnAlpineUrls.join(', ') });
      if (r.alpineConsoleErrors > 0) issues.push({ what: 'Alpine console error', detail: r.alpineErrorTexts.slice(0, 2).join(' | ') });
      if (r.pageStatus !== 200 && r.pageStatus !== 302) issues.push({ what: `Page HTTP ${r.pageStatus || 'timeout'}`, detail: r.pageError || r.url });
      for (const issue of issues) {
        console.log(`| ${r.name} | ${issue.what} | ${issue.detail} |`);
      }
    }
  }

  // ── Visual check reminder ─────────────────────────────────────────────────
  if (failures.length === 0) {
    console.log('\n## One last thing: 30-second visual check\n');
    console.log('Automation verified network + console. Alpine LOADED correctly on all 8 pages.');
    console.log('Do this quick interaction check to confirm Alpine is REACTIVE (not just loaded):\n');
    console.log('  Open any one in incognito with DevTools open:\n');
    console.log('    /fleetforge/dashboard  → click the sidebar hamburger, confirm it collapses');
    console.log('    /fleetforge/customers  → type in the search box, confirm rows filter\n');
    console.log('If the interaction works → SC8 fully PASS.\n');
    console.log('If the interaction does NOT work → ping me. There may be a binding-level issue\n  the network check did not catch (e.g. Alpine initialised before app.js registered components).');
  } else {
    console.log('\nHalt: resolve the failures above before closing SC8.\n');
  }

  // ── Write JSON results ────────────────────────────────────────────────────
  const jsonOut = { swept_at: new Date().toISOString(), total, passed, failed: total - passed, results };
  fs.writeFileSync('/tmp/sc8_results.json', JSON.stringify(jsonOut, null, 2));
  console.log('Full results written to /tmp/sc8_results.json\n');
  console.log(`SC8 SWEEP COMPLETE — ${passed}/${total} PASS — see output above`);

  process.exit(failures.length > 0 ? 1 : 0);
})();
