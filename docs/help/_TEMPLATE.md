---
description: One-line description of what this module does (shown on the Help Center index).
---

# {Module Name} — How This Works

## Overview

One or two plain-language paragraphs: what this module is for and who uses it. Write for
Mainland staff who are new to the system — no jargon, no assumed technical knowledge.

---

## Common Tasks

### Task 1: {Action}

1. Step one.
2. Step two.
3. Step three.

### Task 2: {Action}

1. Step one.
2. Step two.

### Task 3: {Action}

1. Step one.
2. Step two.

---

## Key Concepts

**Term** — Definition in one or two sentences. What does it mean in the context of this module?

**Term** — Definition.

**Term** — Definition.

---

## Understanding the Fields

**Field Name** — What it means and when to fill it in. Note any non-obvious behaviour.

**Field Name** — What it means.

**Field Name** — Explain any calculated or derived fields here.

---

## How It Connects

- **Module** — How this module relates to it. Example: "Each customer can have multiple active leases."
- **Module** — How this module relates to it.
- **Module** — How this module relates to it.

---

## Under the Hood

> *Technical section — accurate to the code. Intended for operators, accountants, and staff
> who need to understand what's happening behind the scenes.*

### Data Model

The primary table is `{table_name}`. Key columns:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | int | Primary key |
| `{col}` | {type} | {purpose} |

Related tables: `{table}` (purpose), `{table}` (purpose).

### Business Rules & Invariants

- **Rule 1** — Describe the invariant and when it fires.
- **Rule 2** — Describe the invariant and when it fires.
- **Soft-delete** — Records are soft-deleted (setting `deleted_at`). They remain in the database and cannot be hard-deleted from the UI.

### Integrations

**QuickBooks Online** — Describe the sync direction, enqueue trigger, and any design decisions
(e.g. best-effort, blocking vs. non-blocking, what happens on delete).

**Other system** — Describe the integration.

### Edge Cases & Behaviours

- **Edge case 1** — What happens and why.
- **Edge case 2** — What happens and why.

---

## Related Guides

- [Module A](/help/module-a)
- [Module B](/help/module-b)
