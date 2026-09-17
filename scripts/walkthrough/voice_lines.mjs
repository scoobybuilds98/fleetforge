/*
 * scripts/walkthrough/voice_lines.mjs
 *
 * Lists the narration lines that still need a neural voice clip, as ready-to-submit
 * Higgsfield batch requests (max 12 per batch), and ingests finished clips.
 *
 *   node scripts/walkthrough/voice_lines.mjs pending <voice> [chapter-slug|all] [--batch N]
 *       → JSON: [{ index, params }] for the Nth batch of 12 still-missing lines (index = global line id)
 *   node scripts/walkthrough/voice_lines.mjs status <voice>
 *       → per-chapter have/missing counts
 *   node scripts/walkthrough/voice_lines.mjs ingest <voice> <file.tsv>
 *       → TSV lines "index<TAB>url": downloads each clip, converts to 48 kHz mono WAV at
 *         -16 LUFS (consistent loudness across 800+ separate generations), stores by hash.
 *
 * Line ids are stable: the order of chapters (filename) then scenes in each
 * <slug>.timeline.json, so batches can be submitted and ingested in any order.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { VOICES, neuralText, voiceKey, voiceDir } from './lib/voice.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const OUT = path.join(ROOT, 'training-videos');
const [cmd, voice = 'cillian', a3, ...rest] = process.argv.slice(2);
if (!VOICES[voice]) { console.error(`unknown voice ${voice}`); process.exit(1); }
const dir = voiceDir(ROOT, voice);
fs.mkdirSync(dir, { recursive: true });

const lines = [];
// --chapter <file.mjs>: voice a chapter script directly (intro, scene captions, outro) instead of rendered timelines.
const chapterFile = process.argv.includes("--chapter") ? path.resolve(process.argv[process.argv.indexOf("--chapter") + 1]) : null;
if (chapterFile) {
  const ch = (await import(chapterFile)).default;
  const texts = [ch.intro, ...ch.scenes.map((s) => s.caption || s.say), ch.outro || `That wraps up ${ch.title}.`];
  texts.forEach((text, k) => { const key = voiceKey(voice, text); lines.push({ id: k, slug: path.basename(chapterFile, ".mjs"), scene: k, text, key, have: fs.existsSync(path.join(dir, `${key}.wav`)) }); });
}
for (const f of chapterFile ? [] : fs.readdirSync(OUT).filter((f) => /^\d\d-.+\.timeline\.json$/.test(f)).sort()) {
  const slug = f.replace('.timeline.json', '');
  JSON.parse(fs.readFileSync(path.join(OUT, f), 'utf8')).timeline.forEach((s, k) => {
    const key = voiceKey(voice, s.text);
    lines.push({ id: lines.length, slug, scene: k, text: s.text, key, have: fs.existsSync(path.join(dir, `${key}.wav`)) });
  });
}

if (cmd === 'status') {
  const by = {};
  for (const l of lines) { by[l.slug] ??= { have: 0, missing: 0 }; by[l.slug][l.have ? 'have' : 'missing']++; }
  console.table(by);
  console.log(`total ${lines.length}, have ${lines.filter((l) => l.have).length}, missing ${lines.filter((l) => !l.have).length}`);
} else if (cmd === 'pending') {
  const scope = a3 && a3 !== 'all' ? lines.filter((l) => l.slug === a3 || l.slug.startsWith(a3)) : lines;
  // Dedupe identical spoken text so a repeated sentence is generated once.
  const seen = new Set();
  const todo = scope.filter((l) => !l.have && !seen.has(l.key) && seen.add(l.key));
  const bi = rest.includes('--batch') ? +rest[rest.indexOf('--batch') + 1] : 0;
  const v = VOICES[voice];
  const batch = todo.slice(bi * 12, bi * 12 + 12).map((l) => ({
    index: l.id,
    params: { model: v.engine, prompt: neuralText(l.text), voice_type: v.voice_type, voice_id: v.voice_id, format: 'wav', sample_rate: 48000, ...(v.speech_rate ? { speech_rate: v.speech_rate } : {}) },
  }));
  console.log(JSON.stringify({ remaining_batches: Math.ceil(todo.length / 12), batch }));
} else if (cmd === 'plan') {
  // Freeze the still-missing lines into numbered batches of 12, so batches can be submitted
  // and ingested concurrently without the "next missing" set shifting underneath.
  const seen = new Set();
  // WT_SKIP="id,id" leaves out lines whose jobs are still queued at Higgsfield, so they are not paid for twice.
  const skip = new Set((process.env.WT_SKIP || "").split(",").filter(Boolean).map(Number));
  const todo = lines.filter((l) => !l.have && !skip.has(l.id) && !seen.has(l.key) && seen.add(l.key));
  const batches = [];
  const size = rest.includes("--size") ? +rest[rest.indexOf("--size") + 1] : 11;
  for (let i = 0; i < todo.length; i += size) batches.push(todo.slice(i, i + size).map((l) => ({ index: l.id, prompt: neuralText(l.text) })));
  fs.writeFileSync(path.join(dir, 'plan.json'), JSON.stringify(batches));
  console.log(`${todo.length} lines in ${batches.length} batches`);
} else if (cmd === 'show') {
  // Compact "index<TAB>prompt" view of plan batch N (the caller supplies the constant voice params).
  const batches = JSON.parse(fs.readFileSync(path.join(dir, 'plan.json'), 'utf8'));
  for (const b of batches[+a3] || []) console.log(`${b.index}\t${b.prompt}`);
} else if (cmd === 'ingest') {
  // Rows: "index<TAB>url"  or  "index<TAB>job_id<TAB>YYYYMMDD_HHMMSS" (batch submit stamp).
  // Higgsfield result URLs are <prefix>/hf_<stamp>_<job_id>.wav, where the stamp can drift a
  // few seconds per job — so for job-id rows we probe nearby seconds with HEAD requests.
  const PREFIX = process.env.WT_HF_PREFIX || 'https://d8j0ntlcm91z4.cloudfront.net/user_3Es1MCHTm2aqbC8OzH2VpZhvTBh';
  const findUrl = (job, stamp) => {
    const t0 = Date.UTC(+stamp.slice(0, 4), +stamp.slice(4, 6) - 1, +stamp.slice(6, 8), +stamp.slice(9, 11), +stamp.slice(11, 13), +stamp.slice(13, 15));
    for (const off of [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 15, 20, 25, 30, 40, 50, 60, -1, -2]) {
      const d = new Date(t0 + off * 1000);
      const p = (n) => String(n).padStart(2, '0');
      const s = `${d.getUTCFullYear()}${p(d.getUTCMonth() + 1)}${p(d.getUTCDate())}_${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}`;
      const url = `${PREFIX}/hf_${s}_${job}.wav`;
      if (spawnSync('curl', ['-sfI', '-o', '/dev/null', url]).status === 0) return url;
    }
    return null;
  };
  const rows = fs.readFileSync(a3, 'utf8').trim().split('\n').map((r) => r.trim().split('\t'));
  // "index<TAB>job_id" rows (no stamp): the jobs were submitted moments ago and may still be
  // generating. Poll CloudFront until each result exists — the stamp is the submit second, so
  // find it once in a window before now, then every other job in the batch is within ±2 s.
  const pending = rows.filter((r) => r.length === 2 && !/^https?:/.test(r[1]));
  const found = new Map();
  if (pending.length) {
    const fmt = (ms) => { const d = new Date(ms); const p = (n) => String(n).padStart(2, '0'); return `${d.getUTCFullYear()}${p(d.getUTCMonth() + 1)}${p(d.getUTCDate())}_${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}`; };
    // One parallel curl per sweep: returns the subset of candidate URLs that exist (HTTP 200).
    const exists = (urls) => {
      if (!urls.length) return [];
      const args = ['-s', '-I', '--parallel', '--parallel-max', '48', '-w', '%{http_code} %{url_effective}\n'];
      for (const u of urls) args.push('-o', '/dev/null', u);
      const r = spawnSync('curl', args, { encoding: 'utf8', maxBuffer: 1 << 24 });
      return (r.stdout || '').split('\n').filter((l) => l.startsWith('200 ')).map((l) => l.slice(4).trim());
    };
    const t0 = Math.floor(Date.now() / 1000) * 1000;
    const since = 1000 * (+(rest[rest.indexOf('--since') + 1]) || 45);
    // Fixed window of candidate submit-seconds (the stamp never moves once a batch is submitted).
    const windowMs = Array.from({ length: since / 1000 + 6 }, (_, k) => t0 - since + k * 1000);
    let stampMs = null;
    const deadline = Date.now() + 300000;
    while (found.size < pending.length && Date.now() < deadline) {
      const todo = pending.filter(([idx]) => !found.has(idx));
      const cands = stampMs
        ? todo.flatMap(([idx, job]) => [-2, -1, 0, 1, 2, 3].map((o) => ({ idx, url: `${PREFIX}/hf_${fmt(stampMs + o * 1000)}_${job}.wav` })))
        : todo.flatMap(([idx, job]) => windowMs.map((ms) => ({ idx, url: `${PREFIX}/hf_${fmt(ms)}_${job}.wav`, ms })));
      const hit = new Set(exists(cands.map((c) => c.url)));
      for (const c of cands) {
        if (hit.has(c.url) && !found.has(c.idx)) { found.set(c.idx, c.url); if (!stampMs && c.ms) stampMs = c.ms; }
      }
      if (found.size < pending.length) spawnSync('sleep', ['3']);
    }
  }
  let ok = 0;
  for (const row of rows) {
    const idx = row[0];
    const url = row.length >= 3 ? findUrl(row[1], row[2]) : /^https?:/.test(row[1]) ? row[1] : found.get(idx);
    const l = lines[+idx];
    if (!l || !url) { console.error(`bad row / url not found: ${row.join(' ')}`); continue; }
    const raw = path.join(dir, `${l.key}.src`);
    const wav = path.join(dir, `${l.key}.wav`);
    const dl = spawnSync('curl', ['-sSfL', '--retry', '3', '-o', raw, url]);
    if (dl.status !== 0) { console.error(`download failed ${idx}: ${dl.stderr}`); continue; }
    // Each generation is normalised on its own so loudness never jumps between scenes.
    const cv = spawnSync('ffmpeg', ['-y', '-v', 'error', '-i', raw, '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11,aresample=48000', '-ac', '1', '-c:a', 'pcm_s16le', wav]);
    fs.rmSync(raw, { force: true });
    if (cv.status !== 0) { console.error(`convert failed ${idx}: ${cv.stderr}`); continue; }
    // Dupes share a key — one file serves every scene with the same text.
    ok++;
  }
  console.log(`ingested ${ok}/${rows.length}`);
} else {
  console.error('usage: voice_lines.mjs status|pending|ingest <voice> ...');
  process.exit(1);
}
