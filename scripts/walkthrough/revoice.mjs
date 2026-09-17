/*
 * scripts/walkthrough/revoice.mjs
 *
 * Re-voices already-rendered chapters with cached neural narration — no re-recording,
 * no dev DB, no app server.
 *
 * For every scene in <slug>.timeline.json:
 *   - the new clip (training-videos/.cache/voice/<voice>/<hash>.wav) is placed at the
 *     scene start + lead-in;
 *   - if it runs longer than the original scene slot, the scene's LAST frame is held
 *     (tpad clone) for the difference, and everything after shifts later — the caption
 *     burned into that frame stays correct, and no two lines ever overlap;
 *   - captions (.srt) and the timeline are re-timed to the new cut.
 *
 * The original Samantha render is moved to training-videos/_orig_say/ the first time a
 * chapter is re-voiced (so re-runs always start from the original footage).
 *
 *   node scripts/walkthrough/revoice.mjs <slug|all> [--voice cillian] [--jobs 3]
 *   then: node scripts/walkthrough/assemble.mjs
 */
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { voiceKey, voiceDir } from './lib/voice.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const OUT = path.join(ROOT, 'training-videos');
const ORIG = path.join(OUT, '_orig_say');
const argv = process.argv.slice(2);
const opt = (k, d) => { const i = argv.indexOf(k); return i >= 0 ? argv[i + 1] : d; };
const VOICE = opt('--voice', 'cillian');
const JOBS = +opt('--jobs', 3);
const LEAD = 0.25;   // same lead-in the recorder used
const TAIL = 0.45;   // breathing room before the next line
// Seed Audio paces lines inconsistently (≈95–185 wpm for the same voice). Slow clips are sped up
// toward TARGET_WPM with a pitch-preserving atempo, capped so no line sounds rushed.
const TARGET_WPM = +opt("--wpm", 150);
const MAX_TEMPO = +opt("--max-tempo", 1.2);
const tempoFor = (text, dur) => { const words = text.split(/\s+/).filter(Boolean).length; const wpm = words / (dur / 60); return Math.min(MAX_TEMPO, Math.max(1, TARGET_WPM / wpm)); };

const probe = (f) => parseFloat(spawnSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f], { encoding: 'utf8' }).stdout);
const run = (args, cwd) => new Promise((res, rej) => {
  const p = spawn('ffmpeg', args, { cwd, stdio: ['ignore', 'ignore', 'pipe'] });
  let err = ''; p.stderr.on('data', (d) => { err += d; });
  p.on('close', (c) => (c === 0 ? res() : rej(new Error(err.slice(-1500)))));
});
const srtTime = (s) => { const ms = Math.max(0, Math.round(s * 1000)); const p = (n, w = 2) => String(n).padStart(w, '0'); return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`; };

async function revoice(slug) {
  fs.mkdirSync(ORIG, { recursive: true });
  const src = path.join(ORIG, `${slug}.mp4`);
  const srcTl = path.join(ORIG, `${slug}.timeline.json`);
  if (!fs.existsSync(src)) {
    for (const ext of ['.mp4', '.srt', '.timeline.json']) fs.copyFileSync(path.join(OUT, slug + ext), path.join(ORIG, slug + ext));
  }
  const tl = JSON.parse(fs.readFileSync(srcTl, 'utf8'));
  const vdur = probe(src);
  const scenes = tl.timeline;

  // Every line must be voiced before we cut anything.
  const raws = scenes.map((s) => path.join(voiceDir(ROOT, VOICE), `${voiceKey(VOICE, s.text)}.wav`));
  const missing = raws.filter((c) => !fs.existsSync(c));
  if (missing.length) throw new Error(`${slug}: ${missing.length} narration clip(s) not generated yet`);
  // Some generations contain multi-second dead air mid-sentence. Trim leading silence and squeeze any
  // pause over 0.5 s down to 0.35 s; cached next to the raw clip (rebuilt if the raw is newer).
  const clips = [];
  for (const r of raws) {
    const c = r.replace(/\.wav$/, ".clean.wav");
    if (!fs.existsSync(c) || fs.statSync(c).mtimeMs < fs.statSync(r).mtimeMs) {
      await run(["-y", "-i", r, "-af", "silenceremove=start_periods=1:start_threshold=-45dB:stop_periods=-1:stop_duration=0.5:stop_threshold=-40dB:stop_silence=0.35,apad=pad_dur=0.05", "-c:a", "pcm_s16le", c]);
    }
    clips.push(c);
  }

  const work = fs.mkdtempSync(path.join(os.tmpdir(), `revoice-${slug}-`));
  let shift = 0;
  const segs = [];
  const newTl = [];
  for (const [i, s] of scenes.entries()) {
    const segStart = i === 0 ? 0 : s.start;
    const segEnd = i + 1 < scenes.length ? scenes[i + 1].start : vdur;
    const slot = segEnd - s.start;
    const raw = probe(clips[i]);
    const tempo = tempoFor(s.text, raw);
    const d = raw / tempo;
    const hold = Math.max(0, LEAD + d + TAIL - slot);
    const newStart = s.start + shift;
    newTl.push({ ...s, start: +newStart.toFixed(3), end: +(segEnd + shift + hold).toFixed(3), voice_dur: +d.toFixed(3), tempo: +tempo.toFixed(3), hold: +hold.toFixed(3), audio: clips[i] });
    segs.push({ i, segStart, segEnd, hold });
    shift += hold;
  }

  // Cut each scene out of the original (frame-accurate re-encode), hold its last frame if needed.
  const list = [];
  for (const g of segs) {
    const f = path.join(work, `seg${String(g.i).padStart(3, '0')}.mp4`);
    const vf = [`fps=30`, g.hold > 0.01 ? `tpad=stop_mode=clone:stop_duration=${g.hold.toFixed(3)}` : null, 'format=yuv420p'].filter(Boolean).join(',');
    // -t must be an INPUT option: as an output option it would also cut off the tpad hold.
    await run(['-y', '-ss', g.segStart.toFixed(3), '-t', (g.segEnd - g.segStart).toFixed(3), '-i', src, '-an', '-vf', vf,
      '-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-video_track_timescale', '15360', f]);
    list.push(`file '${f}'`);
  }
  fs.writeFileSync(path.join(work, 'list.txt'), list.join('\n'));
  const video = path.join(work, 'video.mp4');
  await run(['-y', '-f', 'concat', '-safe', '0', '-i', path.join(work, 'list.txt'), '-c', 'copy', video]);

  // Lay the new narration at each scene's shifted start.
  const args = ['-y', '-i', video];
  newTl.forEach((s) => args.push('-i', s.audio));
  const filt = newTl.map((s, k) => `[${k + 1}:a]${s.tempo > 1.001 ? `atempo=${s.tempo},` : ""}adelay=${Math.round((s.start + LEAD) * 1000)}:all=1[a${k}]`).join(';')
    + `;${newTl.map((_, k) => `[a${k}]`).join('')}amix=inputs=${newTl.length}:normalize=0:dropout_transition=0,apad[aout]`;
  const tmpOut = path.join(work, 'final.mp4');
  await run([...args, '-filter_complex', filt, '-map', '0:v', '-map', '[aout]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-shortest',
    '-metadata', `title=FleetForge Training — ${tl.chapter}`, '-movflags', '+faststart', tmpOut]);

  fs.copyFileSync(tmpOut, path.join(OUT, `${slug}.mp4`));
  fs.writeFileSync(path.join(OUT, `${slug}.srt`), newTl.map((t, k) => `${k + 1}\n${srtTime(t.start)} --> ${srtTime(t.end)}\n${t.text}\n`).join('\n'));
  const total = vdur + shift;
  fs.writeFileSync(path.join(OUT, `${slug}.timeline.json`), JSON.stringify({ ...tl, voice: VOICE, duration: total, timeline: newTl }, null, 2));
  fs.rmSync(work, { recursive: true, force: true });
  const held = newTl.filter((t) => t.hold > 0.01);
  console.log(`[${slug}] ${VOICE}: ${(vdur / 60).toFixed(2)} → ${(total / 60).toFixed(2)} min, ${held.length}/${newTl.length} scenes held (max ${Math.max(0, ...held.map((t) => t.hold)).toFixed(1)}s)`);
}

const target = argv[0];
const slugs = fs.readdirSync(fs.existsSync(ORIG) && target === 'all' ? OUT : OUT)
  .filter((f) => /^\d\d-.+\.mp4$/.test(f)).map((f) => f.replace('.mp4', ''))
  .filter((s) => target === 'all' || s === target || s.startsWith(target));
if (!slugs.length) { console.error('usage: revoice.mjs <slug|all> [--voice cillian] [--jobs 3]'); process.exit(1); }

const queue = [...slugs];
const failures = [];
await Promise.all(Array.from({ length: Math.min(JOBS, queue.length) }, async () => {
  while (queue.length) {
    const s = queue.shift();
    try { await revoice(s); } catch (e) { failures.push(s); console.error(`[${s}] FAILED: ${e.message.split('\n')[0]}`); }
  }
}));
if (failures.length) { console.error(`failed: ${failures.join(', ')}`); process.exit(1); }
