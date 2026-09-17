# FleetForge staff-training videos — authoring guide

One narrated MP4 per module, recorded from the real app on the **dev** DB.
Output: `training-videos/NN-<id>.mp4` + `.srt` + `.timeline.json` (gitignored).

## Pipeline

| File | Role |
|---|---|
| `record.mjs` | CLI — `list`, `<id> [--dry]`, `all [--from <id>]` |
| `lib/recorder.mjs` | TTS (`say`), CDP screencast capture, director verbs, ffmpeg mux, network safety block |
| `lib/overlay.js` | In-page caption pill, fake cursor, ripple, highlight box, title cards |
| `peek.mjs` | Authoring aid — screenshot + visible buttons/fields/tabs/tables of any page |
| `mint_session.php` | Logs the recorder in as user 19 (super_admin) via `auth_login()` — dev only |
| `prep_dev_data.php` | `--apply` hides SMOKE/Test Co debris + renames user → "Training Admin"; `--revert` undoes |
| `chapters/NN-<id>.mjs` | One chapter each |

Dev server: the `fleetforge` launch config, base `http://localhost:8899/fleetforge` (note the `/fleetforge` base path).

## Re-rendering the series

Recording clicks through real create/close/save flows, so bracket it with a dev-DB snapshot:

```bash
# 1. snapshot (dev only!)
MYSQL_PWD="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)" mysqldump -h127.0.0.1 -uroot --single-transaction --routines --triggers fleetforge | gzip > /tmp/ff_pre_walkthrough.sql.gz
# 2. hide smoke/test debris + rename the recorder user
php scripts/walkthrough/prep_dev_data.php --apply
# 3. start the "fleetforge" launch config, then record (≈3 h for all 33) and assemble
node scripts/walkthrough/record.mjs all && node scripts/walkthrough/assemble.mjs
# 4. put the dev DB back exactly as it was
gunzip -c /tmp/ff_pre_walkthrough.sql.gz | MYSQL_PWD="…" mysql -h127.0.0.1 -uroot fleetforge && rm -f training-videos/.cache/prep_state.json
```

Re-record a single chapter with `node scripts/walkthrough/record.mjs <id>`, then re-run `assemble.mjs`.
Voice/pace overrides: `WT_VOICE="Daniel" WT_RATE=170 node scripts/walkthrough/record.mjs <id>`
(`say -v '?'` lists installed voices; higher-quality Premium voices install under System Settings → Accessibility → Spoken Content). Narration audio is cached by text hash in `training-videos/.cache/`, so re-renders only re-synthesise lines you changed.

## Narration voice (Higgsfield "Cillian")

The shipped videos use the Higgsfield preset voice **Cillian** (Seed Audio engine), not macOS `say`.
Re-voicing never re-records: `revoice.mjs` lays new clips over the original `say` renders kept in
`training-videos/_orig_say/`, holds a scene's last frame where a line runs long, speeds slow clips
toward 150 wpm (pitch-preserving, ≤1.2×), and re-times captions.

| File | Role |
|---|---|
| `lib/voice.mjs` | Voice config + `neuralText()` pronunciation rewrites (unit numbers digit-by-digit, AR/AP/GL/ID spelled, `$2,000.00` → `$2,000`) |
| `voice_lines.mjs` | `status` / `plan` (freeze missing lines into batches) / `show N` / `ingest file.tsv` (downloads + −16 LUFS normalise, cache by text hash) |
| `revoice.mjs` | Rebuild chapters from `_orig_say/` + cached clips → `training-videos/NN-*.mp4/.srt/.timeline.json` |

Generation runs through the Higgsfield MCP tools (`generate_audio_batch`, ~12 jobs per batch; bursts beyond that return 429 without charging).
Changing narration text only needs the changed lines regenerated: `voice_lines.mjs plan cillian x` then submit, `ingest`, `revoice.mjs <slug>`, `assemble.mjs`.
Full series ≈ 849 lines ≈ 836 credits (Sept 2026).

## Chapter file shape

```js
export default {
  title: 'Leases',
  subtitle: 'One sentence shown on the title card.',
  start: '/leases',                  // page loaded (off-camera) before capture starts
  intro: 'Spoken over the title card — what this chapter covers.',
  outro: 'Spoken over the end card.',
  allow: [/\/api\/v1\/ai\//],        // optional: un-block a normally blocked non-GET URL
  scenes: [
    { say: 'Narration (also the caption).', caption: 'Optional different caption text', run: async (d) => { ... } },
  ],
};
```

A scene lasts `max(voice length + 0.55s, run() time)`. **The action should illustrate the sentence while it is being spoken.** Keep `say` to 1–2 sentences (≈ 6–12 s). Aim for 15–30 scenes (≈ 3–6 min).

## Director verbs (`d`)

All selectors are Playwright selectors, automatically filtered to **visible** matches and `.first()`.

- `d.nav('Leases', '/leases')` — click the sidebar entry (fallback URL)
- `d.goto('/leases/show?id=12')` — direct load (paths are relative to the base)
- `d.click(sel, { nav: true })` — use `nav:true` when the click loads a new page
- `d.click(sel, { commit: true, nav: true })` — **any click that creates/changes data** (Save, Create, Close lease, Record payment…). Dry runs skip it unless `WT_COMMIT=1`.
- `d.type(sel, 'text')`, `d.select(sel, value | {label} | {index})`
- `d.hover(sel, ms)`, `d.highlight(sel, 'Label', ms)` — dims the page and boxes the element
- `d.scroll(px)` — wheel-scroll under the cursor (works for inner scroll containers); negative = up
- `d.press('Escape')`, `d.wait(ms)`, `d.exists(sel)` → boolean
- `d.page` — raw Playwright page for anything else

## Rules

1. **Never trigger outward side-effects.** Do not click Send / Email / Resend / Push to QuickBooks / Sync / Connect / Refresh GPS. *Hover* them and narrate what they do. `recorder.mjs` also blocks such non-GET URLs at the network layer, but don't rely on it — a blocked call returns a fake `{success:true}` that may render oddly on camera.
2. Records created on camera use realistic, recognisable names: customer **Harbourview Logistics Ltd.**, contact **Priya Sandhu**, notes/descriptions plain and professional. Never "test", "foo", "asdf".
3. Prefer data that exists in the presentation dataset (Summit Carriers Ltd., units TR-7001…CH-7049) over creating new records, so chapters don't depend on each other. If a flow needs a fresh record, create it inside the same chapter.
4. Narration is for **staff learning the job**: say *what* the screen is for, *when* you'd use it, and *what happens* when you click — including business consequences (e.g. "sending moves the invoice from draft to sent and adds it to the customer's balance"). Verify such claims in the code before saying them. No marketing fluff.
5. Written for TTS: spell acronyms the way they should be spoken in `say` if the auto-map (`SPEAK` in recorder.mjs) doesn't cover them; put the written form in `caption`.
6. Selectors: prefer ids / `name` / `x-model` attributes / `button:has-text("…")` over positional CSS. Check with `peek.mjs` (use `--click "<sel>"` to open tabs/modals first).
7. Iterate with `node scripts/walkthrough/record.mjs <id> --dry` until **0 problems**; failures drop `fail-N.png` in `training-videos/.cache/work/<slug>/`. Finish with one `WT_COMMIT=1 ... --dry` run if the chapter has commit clicks, to prove the post-submit scenes work.
8. Top-of-file comment on each chapter: what it covers + any records it creates.
