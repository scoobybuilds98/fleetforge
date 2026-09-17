/*
 * scripts/walkthrough/assemble.mjs
 *
 * Joins every rendered chapter in training-videos/ into one course file:
 *   FleetForge-Staff-Training-Full.mp4  — stream-copied concat with embedded chapter markers
 *                                         (QuickTime / VLC / YouTube-style chapter navigation)
 *   FleetForge-Staff-Training-Full.srt  — captions re-timed across the whole course
 *   INDEX.md                            — chapter list with durations and start times
 *
 * Chapters share identical encode settings (recorder.mjs), so `-c copy` is safe and lossless.
 * Usage: node scripts/walkthrough/assemble.mjs
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const OUT = path.join(ROOT, 'training-videos');
const sh = (cmd, args) => {
  const r = spawnSync(cmd, args, { encoding: 'utf8', maxBuffer: 1 << 26 });
  if (r.status !== 0) throw new Error(`${cmd}: ${r.stderr.slice(-1500)}`);
  return r.stdout;
};
const dur = (f) => parseFloat(sh('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]));
const hms = (s) => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + `:${String(x).padStart(2, '0')}`; };

const vids = fs.readdirSync(OUT).filter((f) => /^\d\d-.+\.mp4$/.test(f)).sort();
if (!vids.length) { console.error('no chapter videos in training-videos/'); process.exit(1); }

let t = 0;
const chapters = vids.map((f) => {
  const file = path.join(OUT, f);
  const tl = fs.existsSync(file.replace(/\.mp4$/, '.timeline.json')) ? JSON.parse(fs.readFileSync(file.replace(/\.mp4$/, '.timeline.json'), 'utf8')) : null;
  const c = { f, file, title: tl?.chapter || f.replace(/^\d\d-|\.mp4$/g, ''), num: parseInt(f, 10), start: t, dur: dur(file), srt: file.replace(/\.mp4$/, '.srt') };
  t += c.dur;
  return c;
});

// ffmetadata chapter markers
const meta = [';FFMETADATA1', 'title=FleetForge Staff Training — Full Course', 'artist=FleetForge'];
for (const c of chapters) meta.push('[CHAPTER]', 'TIMEBASE=1/1000', `START=${Math.round(c.start * 1000)}`, `END=${Math.round((c.start + c.dur) * 1000)}`, `title=${c.num}. ${c.title}`);
const work = path.join(OUT, '.cache');
fs.mkdirSync(work, { recursive: true });
fs.writeFileSync(path.join(work, 'chapters.ffmeta'), meta.join('\n'));
fs.writeFileSync(path.join(work, 'concat.txt'), chapters.map((c) => `file '${c.file}'`).join('\n'));

const full = path.join(OUT, 'FleetForge-Staff-Training-Full.mp4');
sh('ffmpeg', ['-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(work, 'concat.txt'), '-i', path.join(work, 'chapters.ffmeta'),
  '-map', '0', '-map_metadata', '1', '-map_chapters', '1', '-c', 'copy', '-movflags', '+faststart', full]);

// Re-timed combined SRT
const parse = (s) => { const [h, m, x] = s.split(':'); const [sec, ms] = x.split(','); return +h * 3600 + +m * 60 + +sec + +ms / 1000; };
const fmt = (s) => { const ms = Math.round(s * 1000); const p = (n, w = 2) => String(n).padStart(w, '0'); return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`; };
let n = 0; const out = [];
for (const c of chapters) {
  if (!fs.existsSync(c.srt)) continue;
  for (const block of fs.readFileSync(c.srt, 'utf8').trim().split(/\n\n+/)) {
    const lines = block.split('\n');
    const m = lines[1]?.match(/(\S+) --> (\S+)/);
    if (!m) continue;
    out.push(`${++n}\n${fmt(parse(m[1]) + c.start)} --> ${fmt(parse(m[2]) + c.start)}\n${lines.slice(2).join('\n')}\n`);
  }
}
fs.writeFileSync(full.replace(/\.mp4$/, '.srt'), out.join('\n'));

const idx = ['# FleetForge Staff Training', '', `Full course: \`FleetForge-Staff-Training-Full.mp4\` — ${hms(t)} (chapter markers embedded; captions in the matching .srt).`, '',
  '| # | Chapter | Length | Starts at (full course) | File |', '|---|---|---|---|---|',
  ...chapters.map((c) => `| ${c.num} | ${c.title} | ${hms(c.dur)} | ${hms(c.start)} | \`${c.f}\` |`)];
fs.writeFileSync(path.join(OUT, 'INDEX.md'), idx.join('\n') + '\n');
console.log(idx.join('\n'));
console.log(`\nwrote ${full} (${(fs.statSync(full).size / 1e6).toFixed(0)} MB)`);
