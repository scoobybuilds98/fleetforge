# Findings — output format

Write findings to `fable-prompts/findings/<domain>.md`. One block per bug, using
this exact template. Order by severity (CRITICAL → LOW). Lead each file with a
**Coverage** section so a reviewer can see what you actually checked.

---

## Coverage (top of each domain findings file)

```
Domain: <name>
Pages audited:   N of M   (list any not reached + why)
Endpoints audited: N of M (list any not reached + why)
Flows traced end-to-end:
  - <flow 1> ✅
  - <flow 2> ✅
DB tables read for verification: <list>
Reproduced against: local / prod-read-only / both
```

---

## Finding block (repeat per bug)

```
### [SEVERITY] <one-line title>            <!-- e.g. [HIGH] Rate-card create blocks on blank item row -->

- **Status:** CONFIRMED | SUSPECTED        <!-- SUSPECTED → say exactly what would confirm it -->
- **Taxonomy class:** <N — name>           <!-- from bug-taxonomy.md -->
- **Module / flow:** <module> → <action>
- **Surfaces:**
  - UI:  `app/admin/<…>.php:<line>`
  - API: `api/v1/<…>.php:<line>`
  - DB:  `<table>.<column>` (enum/constraint if relevant)

**Symptom (what the user sees):**
<the observable behavior, in the operator's terms>

**Root cause (one sentence):**
<the precise mechanism, in the correct layer>

**Three-layer contract (if a mismatch):**
| Layer | Sends / expects / allows |
|-------|--------------------------|
| UI    | … |
| API   | … |
| DB    | … |

**Reproduction:**
1. <exact steps or curl/payload>
2. <exact DB/UI state needed>
3. <observed result vs expected>
<Wrap any DB writes used to reproduce in BEGIN…ROLLBACK.>

**Evidence (source of truth):**
<the DB query + result, log line, or executed-path output that proves it —
not "the code looks like it would". Today's bug was proven by reading
equipment_templates on prod.>

**Blast radius:**
<who/what is affected, how often, since when if known, related endpoints sharing
the pattern>

**Fix sketch (do NOT apply during audit unless instructed):**
<the minimal correct fix, which layer it belongs in, and any sibling files that
share the same flaw and need the same fix>

**Regression smoke (proposed):**
<the schema-real test that should fail pre-fix / pass post-fix — name + what it
executes. See tests/_smoke_*.php for the pattern.>
```

---

## Rules for the report
- **No hunches as facts.** CONFIRMED means you reproduced it or proved it from the
  source of truth. Everything else is SUSPECTED with a stated confirmation path.
- **No silent caps.** If you sampled instead of covering everything (e.g. audited
  20 of 185 accounting endpoints), say so explicitly in Coverage. A terse "looks
  fine" that hides un-audited surface is worse than an honest gap.
- **Cluster shared causes.** If one root cause spans N files (like the 17-instance
  picker bug), file ONE finding and list all sites — don't fragment.
- **Cross-link.** If a finding relates to a known memory/incident, name it.
