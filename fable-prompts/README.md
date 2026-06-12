# FleetForge Bug-Hunt Prompts (for Claude Fable)

A structured, end-to-end bug audit of the **entire** FleetForge application —
every module, every endpoint, every UI → API → DB flow — modeled on the
diagnostic discipline that cracked the rate-card "Item 2" bug (a UI validation
trap misdiagnosed as a database problem).

## What's here

| File | Purpose |
|------|---------|
| `00-mission-and-method.md` | **The master prompt.** Mission, ground rules (incl. PROD IS READ-ONLY), and the exact verify-against-source-of-truth method. Read first; every domain prompt builds on it. |
| `bug-taxonomy.md` | The catalog of bug *classes* to hunt, each with a real incident from this codebase so you know the shape of the prey. |
| `findings-template.md` | The required output format for every finding. Copy it per bug. |
| `01` … `10-*.md` | **Domain prompts.** Each is self-contained and scoped to a set of modules + endpoints. Hand one to a fresh Fable agent. |

## Domain map (every module is covered exactly once)

| Prompt | Domains | Rough surface |
|--------|---------|---------------|
| `01-accounting-quickbooks.md` | accounting, quickbooks, oauth | 252 endpoints, 78 pages — **the giant; subdivide** |
| `02-billing-invoices-payments-credit.md` | invoices, payments, credit_notes, credit_applications, rate_cards, customer_equipment_rates | ~40 endpoints |
| `03-leases-reservations-requests.md` | leases, reservations, requests | ~28 endpoints |
| `04-equipment-maintenance-inspections-compliance-damage.md` | equipment, maintenance_work_orders, inspections, compliance, damage_claims | ~47 endpoints |
| `05-customers-vendors-users-portal-account.md` | customers, vendors, users, portal_users, portal, account, profile | ~55 endpoints |
| `06-reports-analytics-dashboard-search-audit.md` | reports, analytics, dashboard, search, audit, documents | ~16 endpoints |
| `07-crons-notifications-email-ai.md` | cron/, notifications, email, ai | 33 cron scripts + ~26 endpoints |
| `08-integrations-samsara-gps-tracking-oauth-webhooks-storage.md` | samsara, gps, tracking, webhooks, storage | ~13 endpoints |
| `09-settings-auth-permissions-mfa.md` | settings, auth/login, permissions, MFA, mileage_logs, yards | ~20 endpoints |
| `10-chat-messenger.md` | chat, messenger, realtime/Pusher | ~36 endpoints |

## How to run it

1. Spin up one Fable agent per domain prompt (they're independent — run in parallel).
2. Each agent: reads `00-mission-and-method.md` + `bug-taxonomy.md`, then executes its
   domain prompt, and writes findings using `findings-template.md`.
3. Findings go to `fable-prompts/findings/<domain>.md` (create the folder).
4. Triage the findings, then fix in separate, reviewable commits — **never** as one mega-PR.

## Non-negotiable

**Production (`ssh fleetforge` → mainlandrentals.com) is READ-ONLY.** Reading for
diagnosis is encouraged. Prepare any write/fix command and hand it to the operator —
never execute prod mutations, sends, deploys, settings changes, or crontab edits.
The local dev DB (`fleetforge` on 127.0.0.1, Herd) is yours to reproduce against.
