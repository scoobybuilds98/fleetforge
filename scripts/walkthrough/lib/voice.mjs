/*
 * scripts/walkthrough/lib/voice.mjs
 *
 * Neural-voice narration (Higgsfield) for the training series.
 *
 * The first renders used macOS `say`, whose scripts spell acronyms out ("P D F").
 * Neural voices read ordinary written English well but still stumble on a few
 * fleet/accounting tokens (AR → "are", DOT → "dot", TR-7010 → "seven thousand
 * ten"), so neuralText() rewrites only those before synthesis. Captions keep the
 * written form.
 *
 * Generated clips are cached by a hash of (engine|voice|spoken text) in
 * training-videos/.cache/voice/<voice>/<hash>.wav — the recorder and revoice.mjs
 * both read from there, so a line is only ever paid for once.
 */
import { createHash } from 'node:crypto';
import path from 'node:path';

export const VOICES = {
  // Higgsfield preset "Cillian" (male), Seed Audio 1.0 engine — chosen 2026-09-16.
  cillian: { engine: 'seed_audio', voice_type: 'preset', voice_id: 'd8ba9f14-8a24-44db-932b-99e16c45bd32', speech_rate: 0 },
};

// Read letter-by-letter (spaces force it); everything else in caps is left to the model.
const SPELL = ['AR', 'AP', 'GL', 'CCA', 'PO', 'ID', 'IP', 'US', 'MC', 'DOT', 'FF', 'ROI', 'PNG', 'BCC', 'CRA', 'VIN', 'FX', 'CVI', 'MVI', 'TR', 'RF', 'FB', 'DV', 'SD', 'CH', 'HEIC'];
const DIGITS = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

export function neuralText(t) {
  let s = t
    .replace(/\be\.g\./g, 'for example').replace(/\bi\.e\./g, 'that is')
    .replace(/→/g, ' to ').replace(/&/g, ' and ').replace(/Print \/ Save/g, 'Print or Save').replace(/\s\/\s/g, ' and ')
    .replace(/“id=”/g, '"id equals"')
    .replace(/⌘K \(Ctrl\+K on Windows\)/g, 'Command K, or Control K on Windows,')
    .replace(/\s=\s/g, ' means ').replace(/(\d)\+/g, '$1 plus').replace(/(^|\s)\+\s(?=[A-Z])/g, '$1')
    .replace(/(\d)\s?–\s?(\d)/g, '$1 to $2').replace(/super_admin/g, 'super admin')
    .replace(/\$([\d,]+)\.00\b/g, '$$$1') // "$2,000.00" is said "two thousand dollars", not "... and zero cents"
    // Unit numbers are said digit by digit on the yard: TR-7010 → "T R seven zero one zero"
    .replace(/\b([A-Z]{2})-(\d{4})\b/g, (_, p, n) => `${p} ${[...n].map((c) => DIGITS[+c]).join(' ')}`)
    .replace(/\b([A-Z]{2,4})\/([A-Z]{2,4})\b/g, '$1 or $2')
    .replace(/\bkm\/mile\b/g, 'kilometre and mile').replace(/(\d)\s?MB\b/g, '$1 megabytes')
    .replace(/\bFleetForge\b/g, 'Fleet Forge').replace(/\bQBO\b/g, 'QuickBooks Online')
    .replace(/\bJEs\b/g, 'journal entries').replace(/\bJE\b/g, 'journal entry')
    .replace(/\bKPIs\b/g, 'K P Is').replace(/\bCAD\b/g, 'Canadian dollars')
    .replace(/\bOK\b/g, 'okay');
  for (const w of SPELL) s = s.replace(new RegExp(`\\b${w}\\b`, 'g'), [...w].join(' '));
  return s.replace(/\s{2,}/g, ' ').trim();
}

export function voiceKey(voice, text) {
  const v = VOICES[voice];
  return createHash('sha1').update(`${v.engine}|${v.voice_id}|${v.speech_rate}|${neuralText(text)}`).digest('hex').slice(0, 16);
}

export const voiceDir = (root, voice) => path.join(root, 'training-videos/.cache/voice', voice);
