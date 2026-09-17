/*
 * scripts/walkthrough/peek.mjs
 *
 * Authoring aid for chapter scripts: opens a page as the recorder's user,
 * saves a screenshot, and prints the visible headings, buttons, links, tabs and
 * form fields with a ready-to-use Playwright selector for each.
 *
 * Usage: node scripts/walkthrough/peek.mjs /customers [--click "text=New Customer"] [--shot out.png]
 */
import { chromium } from 'playwright';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const argv = process.argv.slice(2);
const url = argv.find((a) => !a.startsWith('--') && !argv[argv.indexOf(a) - 1]?.startsWith('--')) || '/dashboard';
const opt = (k) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : null; };
const base = process.env.WT_BASE || 'http://localhost:8899/fleetforge';
const WT = path.dirname(new URL(import.meta.url).pathname);

const sid = spawnSync('php', [path.join(WT, 'mint_session.php'), process.env.WT_USER || '19'], { encoding: 'utf8' }).stdout.trim();
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
await ctx.addCookies([{ name: 'ff_session', value: sid, domain: new URL(base).hostname, path: '/', httpOnly: true }]);
await ctx.route('**/*', (r) => (r.request().method() !== 'GET' && /(email|send|quickbooks|samsara|\/ai\/)/i.test(r.request().url()) ? r.abort() : r.continue()));
const page = await ctx.newPage();
await page.goto(base + url, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(1200);
for (const c of argv.flatMap((a, i) => (a === '--click' ? [argv[i + 1]] : []))) {
  await page.locator(c).first().click();
  await page.waitForTimeout(1200);
}
const shot = opt('--shot') || path.join(process.env.TMPDIR || '/tmp', `peek-${url.replace(/\W+/g, '_')}.png`);
await page.screenshot({ path: shot });

const info = await page.evaluate(() => {
  const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const txt = (el) => (el.innerText || el.value || el.getAttribute('aria-label') || el.title || '').trim().replace(/\s+/g, ' ').slice(0, 70);
  const inMain = (el) => !el.closest('aside, .sidebar, #sidebar');
  const out = { title: document.title, url: location.pathname + location.search, headings: [], buttons: [], links: [], fields: [], tabs: [], tables: [] };
  document.querySelectorAll('h1,h2,h3').forEach((h) => vis(h) && out.headings.push(`${h.tagName} ${txt(h)}`));
  document.querySelectorAll('button, [role=button], a.btn, input[type=submit]').forEach((b) => vis(b) && inMain(b) && txt(b) && out.buttons.push(txt(b)));
  document.querySelectorAll('[role=tab], .tab, .tabs a, .tabs button, [data-tab]').forEach((t) => vis(t) && out.tabs.push(txt(t)));
  document.querySelectorAll('main a[href], .main a[href], .content a[href]').forEach((a) => vis(a) && !a.classList.contains('btn') && out.links.length < 25 && out.links.push(`${txt(a)} -> ${a.getAttribute('href')}`));
  document.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((f) => {
    if (!vis(f)) return;
    const lab = (f.id && document.querySelector(`label[for="${f.id}"]`)?.innerText) || f.closest('label')?.innerText || f.placeholder || f.name || '';
    const sel = f.id ? `#${f.id}` : f.name ? `[name="${f.name}"]` : f.getAttribute('x-model') ? `[x-model="${f.getAttribute('x-model')}"]` : f.tagName.toLowerCase();
    out.fields.push(`${sel}  (${f.tagName.toLowerCase()}${f.type ? ':' + f.type : ''})  "${lab.trim().slice(0, 40)}"`);
  });
  document.querySelectorAll('table').forEach((t) => vis(t) && out.tables.push(`${[...t.querySelectorAll('thead th')].map(txt).join(' | ')}  [${t.querySelectorAll('tbody tr').length} rows]`));
  out.buttons = [...new Set(out.buttons)];
  return out;
});
console.log(JSON.stringify({ shot, ...info }, null, 2));
await browser.close();
