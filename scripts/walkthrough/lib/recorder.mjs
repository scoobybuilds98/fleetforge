/*
 * scripts/walkthrough/lib/recorder.mjs
 *
 * Records one training chapter end-to-end:
 *   1. Synthesises every scene's narration with macOS `say` (cached by text hash)
 *      and measures it, so each scene lasts at least as long as its voiceover.
 *   2. Drives the real app in headless Chromium, capturing frames over the CDP
 *      screencast (crisp JPEGs — Playwright's recordVideo is a 1 Mbit VP8 that
 *      blurs table text at 1080p).
 *   3. Builds a CFR H.264 MP4 from the variable-rate frames, lays each voice clip
 *      at its scene's start offset, and writes a sidecar .srt.
 *
 * Safety: DEV ONLY (mint_session.php enforces APP_ENV=development) and every
 * non-GET request whose URL looks like it would email, push to QuickBooks, or
 * call Samsara/AI is aborted at the network layer — narration explains those
 * buttons, the recorder never presses them for real.
 */
import { chromium } from 'playwright';
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { voiceKey as _vk, voiceDir as _vd } from "./voice.mjs";

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');
const WT = path.join(ROOT, 'scripts/walkthrough');
const OUT = path.join(ROOT, 'training-videos');
const CACHE = path.join(OUT, '.cache');

const VOICE = process.env.WT_VOICE || 'Samantha';
const RATE = process.env.WT_RATE || '182';
const W = 1920, H = 1080;

// Spoken-only substitutions: captions keep the written form.
const SPEAK = [
  [/\bFleetForge\b/g, 'Fleet Forge'], [/\bQBO\b/g, 'QuickBooks Online'], [/\bGL\b/g, 'G L'],
  [/\bA\/R\b|\bAR\b/g, 'A R'], [/\bA\/P\b|\bAP\b/g, 'A P'], [/\bJEs?\b/g, (m) => (m === 'JEs' ? 'journal entries' : 'journal entry')],
  [/\bCAD\b/g, 'Canadian dollars'], [/\bFX\b/g, 'F X'], [/\bVIN\b/g, 'V I N'], [/\bCSV\b/g, 'C S V'],
  [/\bPDF(s?)\b/g, 'P D F$1'], [/\bGPS\b/g, 'G P S'], [/\bMFA\b/g, 'M F A'], [/\bKPIs?\b/g, (m) => (m.endsWith('s') ? 'K P Is' : 'K P I')],
  [/\be\.g\./g, 'for example'], [/\bi\.e\./g, 'that is'], [/→/g, ' to '], [/&/g, ' and '], [/\bkm\b/g, 'kilometres'],
];
const spoken = (t) => SPEAK.reduce((s, [re, rep]) => s.replace(re, rep), t);

// Server-side side-effects we must never trigger on camera.
const BLOCK = /(\/email\/|\/send|send_|resend|\/quickbooks\/.*(push|sync|connect|disconnect|enqueue)|\/qbo\/|samsara|\/gps\/(refresh|sync|poll)|\/ai\/(chat|ask|query|message)|dunning|year[-_]?end\/(close|run|start)|notify|\/portal\/invite|\/api\/v1\/(portal_)?users\/create)/i; // users/create always emails an invite

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sh = (cmd, args) => {
  const r = spawnSync(cmd, args, { encoding: 'utf8', maxBuffer: 1 << 26 });
  if (r.status !== 0) throw new Error(`${cmd} failed: ${r.stderr?.slice(-2000)}`);
  return r.stdout;
};

// WT_TTS=<voice> (e.g. cillian) records straight from the cached Higgsfield clips instead of macOS `say`,
// so scene timing follows the real narration. Missing clips are listed in <cache>/voice/<voice>/missing.json.
const NEURAL = process.env.WT_TTS || "";
const neuralMissing = [];
function neuralClip(text) {
  const raw = path.join(_vd(ROOT, NEURAL), `${_vk(NEURAL, text)}.wav`);
  if (!fs.existsSync(raw)) { neuralMissing.push(text); return null; }
  const clean = raw.replace(/\.wav$/, ".clean.wav");
  if (!fs.existsSync(clean) || fs.statSync(clean).mtimeMs < fs.statSync(raw).mtimeMs) {
    sh("ffmpeg", ["-y", "-v", "error", "-i", raw, "-af", "silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_duration=0.5:stop_threshold=-40dB:stop_silence=0.35,apad=pad_dur=0.05", "-c:a", "pcm_s16le", clean]);
  }
  const dur = parseFloat(sh("ffprobe", ["-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", clean]));
  return { wav: clean, dur };
}

function tts(text) {
  fs.mkdirSync(CACHE, { recursive: true });
  const say = spoken(text);
  const h = createHash('sha1').update(`${VOICE}|${RATE}|${say}`).digest('hex').slice(0, 16);
  const wav = path.join(CACHE, `${h}.wav`);
  if (!fs.existsSync(wav)) sh('say', ['-v', VOICE, '-r', RATE, '--file-format=WAVE', '--data-format=LEI16@48000', '-o', wav, say]);
  const dur = parseFloat(sh('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', wav]));
  return { wav, dur };
}

/** Director — the verbs chapter scripts use. All fail-soft in record mode. */
function makeDirector(page, ctx) {
  const d = {
    page,
    base: ctx.base,
    log: ctx.log,
    async ready() {
      await page.waitForLoadState('domcontentloaded');
      await page.waitForLoadState('load').catch(() => {});
      await page.waitForFunction(() => window.__wt, null, { timeout: 5000 }).catch(() => {});
      await sleep(ctx.dry ? 100 : 700);
    },
    async goto(p) {
      await page.goto(p.startsWith('http') ? p : ctx.base + p, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await d.ready();
      await d.caption(ctx.currentCaption);
    },
    async caption(text) {
      ctx.currentCaption = text;
      await page.evaluate(([t, c]) => window.__wt && window.__wt.setCaption(t, c), [text, ctx.chip]).catch(() => {});
    },
    loc(target) {
      if (typeof target !== 'string') return target;
      // Visible-only: the topbar "New" menu and mobile nav duplicate many labels as hidden nodes.
      return page.locator(target).filter({ visible: true }).first();
    },
    async point(target) {
      const l = d.loc(target);
      await l.waitFor({ state: 'visible', timeout: 8000 });
      await l.scrollIntoViewIfNeeded().catch(() => {});
      await sleep(ctx.dry ? 0 : 250);
      const b = await l.boundingBox();
      if (!b) throw new Error('no bounding box');
      return { l, b, x: b.x + Math.min(b.width / 2, 60 + b.width / 4), y: b.y + b.height / 2 };
    },
    async moveTo(x, y, ms = 650) {
      await page.mouse.move(x, y, { steps: ctx.dry ? 1 : 8 });
      await page.evaluate(([x, y, ms]) => window.__wt && window.__wt.moveCursor(x, y, ms), [x, y, ctx.dry ? 0 : ms]).catch(() => {});
    },
    async hover(target, pause = 600) {
      const { x, y } = await d.point(target);
      await d.moveTo(x, y);
      await sleep(ctx.dry ? 0 : pause);
    },
    /** True if a visible match exists — for optional UI that depends on data. */
    async exists(target, timeout = 2500) {
      return d.loc(target).waitFor({ state: 'visible', timeout }).then(() => true, () => false);
    },
    async click(target, { nav = false, pause = 500, commit = false } = {}) {
      const { l, x, y } = await d.point(target);
      await d.moveTo(x, y);
      // commit:true = a data-creating submit. Dry runs only verify it is clickable unless WT_COMMIT=1,
      // so selector iteration doesn't litter the dev DB.
      if (commit && ctx.dry && !process.env.WT_COMMIT) { ctx.log(`(dry) skipped commit click`); return; }
      if (commit) ctx.lastCommitAt = Date.now();
      await page.evaluate(([x, y]) => window.__wt && window.__wt.ripple(x, y), [x, y]).catch(() => {});
      await sleep(ctx.dry ? 0 : 180);
      if (nav) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {}), l.click()]);
        await d.ready();
        await d.caption(ctx.currentCaption);
      } else {
        await l.click();
        await sleep(ctx.dry ? 50 : pause);
      }
    },
    /** Click a sidebar entry by its visible label (falls back to direct URL). */
    async nav(label, url) {
      const link = page.locator(`aside a, nav a, .sidebar a`).filter({ hasText: new RegExp(`^\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`) }).first();
      if (await link.isVisible().catch(() => false)) return d.click(link, { nav: true });
      if (url) return d.goto(url);
      throw new Error(`sidebar link "${label}" not visible`);
    },
    async type(target, text, { clear = true, delay = 45 } = {}) {
      const { l, x, y } = await d.point(target);
      await d.moveTo(x, y);
      await l.click();
      if (clear) await l.fill('');
      await l.pressSequentially(text, { delay: ctx.dry ? 0 : delay });
      await sleep(ctx.dry ? 0 : 300);
    },
    async select(target, value) {
      const { l, x, y } = await d.point(target);
      await d.moveTo(x, y);
      await l.selectOption(value);
      await sleep(ctx.dry ? 0 : 500);
    },
    async highlight(target, label, ms = 1800) {
      const { b } = await d.point(target);
      await page.evaluate(([r, lb]) => window.__wt && window.__wt.highlight(r, lb), [b, label || '']);
      await sleep(ctx.dry ? 0 : ms);
      await page.evaluate(() => window.__wt && window.__wt.highlight(null));
      await sleep(ctx.dry ? 0 : 350);
    },
    /** Wheel-scroll whatever container sits under (x,y) — works for the app's inner scroller too. */
    async scroll(dy, { x = 1100, y = 600, steps = 10 } = {}) {
      await d.moveTo(x, y, 350);
      for (let i = 0; i < steps; i++) { await page.mouse.wheel(0, dy / steps); await sleep(ctx.dry ? 0 : 45); }
      await sleep(ctx.dry ? 0 : 400);
    },
    async press(key) { await page.keyboard.press(key); await sleep(ctx.dry ? 0 : 400); },
    async wait(ms) { await sleep(ctx.dry ? Math.min(ms, 100) : ms); },
    /** First row link in the main list table — used to open a record. */
    async openFirstRow(sel = 'table tbody tr a[href]') { await d.click(page.locator(sel).first(), { nav: true }); },
  };
  return d;
}

export async function recordChapter(chapter, { base, dry = false, userId = 19, only = null } = {}) {
  const slug = chapter.slug || `${String(chapter.num).padStart(2, "0")}-${chapter.id}`;
  const work = path.join(CACHE, 'work', slug);
  fs.rmSync(work, { recursive: true, force: true });
  fs.mkdirSync(path.join(work, 'frames'), { recursive: true });
  const problems = [];
  const log = (m) => { console.log(`[${slug}] ${m}`); };

  // Scene list: intro card + authored scenes + outro.
  const scenes = [
    { card: true, say: chapter.intro, caption: chapter.intro },
    ...chapter.scenes,
    { card: 'outro', say: chapter.outro || `That wraps up ${chapter.title}.`, caption: chapter.outro || `That wraps up ${chapter.title}.` },
  ];
  if (!dry) {
    for (const s of scenes) Object.assign(s, { audio: NEURAL ? neuralClip(s.caption || s.say) : tts(s.say) });
    if (NEURAL && neuralMissing.length) {
      fs.writeFileSync(path.join(_vd(ROOT, NEURAL), "missing.json"), JSON.stringify(neuralMissing, null, 1));
      throw new Error(`${neuralMissing.length} narration line(s) have no ${NEURAL} clip yet — see voice/${NEURAL}/missing.json`);
    }
  }

  const sid = sh('php', [path.join(WT, 'mint_session.php'), String(userId)]).trim();
  const browser = await chromium.launch({ headless: true, args: ['--hide-scrollbars', '--force-color-profile=srgb'] });
  const context = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1, locale: 'en-CA', timezoneId: 'America/Vancouver' });
  const host = new URL(base).hostname;
  await context.addCookies([{ name: 'ff_session', value: sid, domain: host, path: '/', httpOnly: true }]);
  await context.addInitScript({ path: path.join(WT, 'lib/overlay.js') });
  await context.route('**/*', (route) => {
    const r = route.request();
    if (r.method() !== 'GET' && BLOCK.test(r.url()) && !(chapter.allow || []).some((re) => re.test(r.url()))) {
      log(`BLOCKED ${r.method()} ${r.url()}`);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { walkthrough_blocked: true } }) });
    }
    return route.continue();
  });

  const page = await context.newPage();
  // Native confirm()/alert(): accept ONLY within 6s of an intended commit click; anything else is
  // dismissed so a stray click can never confirm a destructive action (e.g. Start Year-End Close).
  page.on('dialog', (dl) => {
    const ok = Date.now() - (ctx.lastCommitAt || 0) < 6000;
    log(`dialog "${dl.message().slice(0, 80)}" -> ${ok ? 'accept' : 'DISMISS'}`);
    (ok ? dl.accept() : dl.dismiss()).catch(() => {});
  });
  page.on('pageerror', (e) => log(`pageerror: ${e.message.slice(0, 160)}`));
  // Coverage log: every page/URL (incl. pushState tab changes) the chapter visits → work/<slug>/urls.txt
  const visited = new Set();
  page.on('framenavigated', (f) => { if (f === page.mainFrame()) visited.add(f.url().replace(base, '')); });
  const ctx = { base, dry, log, chip: chapter.chip || `${String(chapter.num).padStart(2, "0")} · ${chapter.title}`, currentCaption: '' };
  const d = makeDirector(page, ctx);

  // Land on the chapter's first page before capture starts so frame 0 is not about:blank.
  await d.goto(chapter.start || '/dashboard');
  if (page.url().includes('/login')) throw new Error('session mint did not authenticate (redirected to /login)');

  const frames = [];
  let cdp, t0 = Date.now();
  if (!dry) {
    cdp = await context.newCDPSession(page);
    let n = 0;
    cdp.on('Page.screencastFrame', async ({ data, sessionId }) => {
      const f = path.join(work, 'frames', `${String(n++).padStart(6, '0')}.jpg`);
      frames.push({ f, t: (Date.now() - t0) / 1000 });
      fs.writeFileSync(f, Buffer.from(data, 'base64'));
      cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
    });
    t0 = Date.now();
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: W, maxHeight: H, everyNthFrame: 1 });
  }

  const timeline = [];
  for (const [i, s] of scenes.entries()) {
    if (only && !s.card && !only.includes(i)) continue;
    const start = (Date.now() - t0) / 1000;
    const minMs = dry ? 0 : Math.round((s.audio?.dur || 0) * 1000) + (s.card ? 900 : 550);
    try {
      if (s.card) {
        await d.caption('');
        await page.evaluate(([on, n, t, sub]) => window.__wt.card(on, n, t, sub), [true, s.card === 'outro' ? 'Chapter complete' : (chapter.label || `Chapter ${chapter.num}`), s.card === 'outro' ? chapter.title : chapter.title, s.card === 'outro' ? (chapter.next ? `Next: ${chapter.next}` : '') : chapter.subtitle]);
        await sleep(minMs);
        if (s.card === true) { await page.evaluate(() => window.__wt.card(false)); await sleep(dry ? 0 : 700); }
      } else {
        await d.caption(s.caption || s.say);
        await Promise.all([
          (async () => { try { await s.run?.(d); } catch (e) { problems.push({ scene: i, say: s.say.slice(0, 70), error: e.message.split('\n')[0] }); log(`scene ${i} FAILED: ${e.message.split('\n')[0]}`); if (dry) await page.screenshot({ path: path.join(work, `fail-${i}.png`) }); } })(),
          sleep(minMs),
        ]);
      }
    } catch (e) {
      problems.push({ scene: i, error: e.message.split('\n')[0] });
    }
    timeline.push({ i, start, end: (Date.now() - t0) / 1000, text: s.caption || s.say, audio: s.audio?.wav });
  }
  const total = (Date.now() - t0) / 1000 + 0.3;
  await sleep(300);
  if (cdp) await cdp.send('Page.stopScreencast').catch(() => {});
  await sleep(300);
  await browser.close();

  fs.writeFileSync(path.join(work, 'urls.txt'), [...visited].join('\n') + '\n');
  fs.writeFileSync(path.join(work, 'problems.json'), JSON.stringify(problems, null, 2));
  if (dry) { log(`dry run done — ${problems.length} problem(s)`); return { slug, problems }; }

  // ---- video: VFR frames -> ffconcat -> 30fps H.264 ----
  if (!frames.length) throw new Error('no frames captured');
  const list = ['ffconcat version 1.0'];
  frames.forEach((fr, k) => {
    const next = k + 1 < frames.length ? frames[k + 1].t : total;
    const dur = Math.max(0.001, next - (k === 0 ? 0 : fr.t));
    list.push(`file '${fr.f}'`, `duration ${dur.toFixed(4)}`);
  });
  list.push(`file '${frames.at(-1).f}'`);
  fs.writeFileSync(path.join(work, 'frames.ffconcat'), list.join('\n'));
  const silent = path.join(work, 'video.mp4');
  sh('ffmpeg', ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(work, 'frames.ffconcat'),
    '-vf', `scale=${W}:${H}:force_original_aspect_ratio=decrease,pad=${W}:${H}:(ow-iw)/2:(oh-ih)/2,fps=30,format=yuv420p`,
    '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-tune', 'stillimage', '-movflags', '+faststart', silent]);

  // ---- audio: each clip delayed to its scene start, mixed without normalisation ----
  const clips = timeline.filter((t) => t.audio);
  const args = ['-y', '-v', 'error', '-i', silent];
  clips.forEach((c) => args.push('-i', c.audio));
  const lead = 0.25;
  const filt = clips.map((c, k) => `[${k + 1}:a]adelay=${Math.round((c.start + lead) * 1000)}:all=1[a${k}]`).join(';')
    + `;${clips.map((_, k) => `[a${k}]`).join('')}amix=inputs=${clips.length}:normalize=0:dropout_transition=0,apad[aout]`;
  fs.mkdirSync(OUT, { recursive: true });
  const mp4 = path.join(OUT, `${slug}.mp4`);
  sh('ffmpeg', [...args, '-filter_complex', filt, '-map', '0:v', '-map', '[aout]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-shortest',
    '-metadata', `title=FleetForge Training — ${chapter.num}. ${chapter.title}`, '-movflags', '+faststart', mp4]);

  // ---- captions sidecar ----
  const ts = (s) => { const ms = Math.max(0, Math.round(s * 1000)); const p = (n, w = 2) => String(n).padStart(w, '0'); return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`; };
  const srt = timeline.map((t, k) => `${k + 1}\n${ts(t.start)} --> ${ts(t.end)}\n${t.text}\n`).join('\n');
  fs.writeFileSync(path.join(OUT, `${slug}.srt`), srt);
  fs.writeFileSync(path.join(OUT, `${slug}.timeline.json`), JSON.stringify({ chapter: chapter.title, duration: total, timeline, problems }, null, 2));
  fs.rmSync(path.join(work, 'frames'), { recursive: true, force: true });
  log(`wrote ${mp4} (${total.toFixed(1)}s, ${frames.length} frames, ${problems.length} problem(s))`);
  return { slug, mp4, duration: total, problems };
}
