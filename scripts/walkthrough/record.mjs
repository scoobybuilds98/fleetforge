/*
 * scripts/walkthrough/record.mjs
 *
 * CLI for the FleetForge staff-training video series.
 *
 *   node scripts/walkthrough/record.mjs list
 *   node scripts/walkthrough/record.mjs customers            # record one chapter -> training-videos/NN-customers.mp4
 *   node scripts/walkthrough/record.mjs customers --dry      # fast selector check, no audio/video, screenshots failures
 *   node scripts/walkthrough/record.mjs all [--from leases]  # record every chapter in order
 *
 * Needs the dev server running (the "fleetforge" launch config, :8899).
 * Restore the dev DB afterwards if you recorded create/edit flows.
 */
import fs from 'node:fs';
import path from 'node:path';
import { recordChapter } from './lib/recorder.mjs';

const WT = path.dirname(new URL(import.meta.url).pathname);
const argv = process.argv.slice(2);
const flag = (k) => argv.includes(k);
const opt = (k) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : null; };
const base = opt('--base') || process.env.WT_BASE || 'http://localhost:8899/fleetforge';

const files = fs.readdirSync(path.join(WT, 'chapters')).filter((f) => /^\d\d-.+\.mjs$/.test(f)).sort();
const chapters = [];
for (const f of files) {
  const mod = (await import(path.join(WT, 'chapters', f))).default;
  chapters.push({ num: parseInt(f, 10), id: f.replace(/^\d\d-|\.mjs$/g, ''), ...mod });
}
chapters.forEach((c, i) => { c.next = chapters[i + 1]?.title; });

// --file <chapter.mjs>: record a standalone demo outside the numbered course (e.g. demos/batch-invoicing-deep.mjs).
if (opt("--file")) {
  const p = path.resolve(opt("--file"));
  const mod = (await import(p)).default;
  const c = { num: 0, id: path.basename(p, ".mjs"), slug: `demo-${path.basename(p, ".mjs")}`, ...mod };
  const r = await recordChapter(c, { base, dry: flag("--dry") });
  console.log(`${r.slug}: ${r.duration ? r.duration.toFixed(0) + "s, " : ""}${r.problems.length} problem(s)${r.problems.length ? "\n  " + r.problems.map((x) => `#${x.scene} ${x.error}`).join("\n  ") : ""}`);
  process.exit(0);
}

const target = argv[0];
if (!target || target === 'list') {
  for (const c of chapters) console.log(`${String(c.num).padStart(2, '0')}  ${c.id.padEnd(26)} ${c.scenes.length} scenes  ${c.title}`);
  process.exit(0);
}

let todo = target === 'all' ? chapters : chapters.filter((c) => c.id === target || String(c.num) === target || String(c.num).padStart(2, '0') === target);
if (opt('--from')) todo = todo.slice(Math.max(0, todo.findIndex((c) => c.id === opt('--from'))));
if (!todo.length) { console.error(`no chapter "${target}"`); process.exit(1); }

const summary = [];
for (const c of todo) {
  try {
    summary.push(await recordChapter(c, { base, dry: flag('--dry') }));
  } catch (e) {
    console.error(`[${c.id}] ABORTED: ${e.stack}`);
    summary.push({ slug: c.id, aborted: e.message });
  }
}
console.log('\n=== summary ===');
for (const s of summary) console.log(`${s.slug}: ${s.aborted ? 'ABORTED ' + s.aborted : `${s.duration ? s.duration.toFixed(0) + 's, ' : ''}${s.problems.length} problem(s)`}${s.problems?.length ? '\n  ' + s.problems.map((p) => `#${p.scene} ${p.error}`).join('\n  ') : ''}`);
