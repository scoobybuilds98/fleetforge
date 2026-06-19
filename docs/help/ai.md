---
description: Ask questions about your fleet, customers, leases, invoices, and financial data in plain English — plus AI-generated charts, document analysis, and anomaly alerts.
---

# AI Assistant

A chat assistant that answers questions about your live FleetForge data — fleet, customers, leases, invoices, payments, accounting, maintenance, and compliance. It looks up the real records before answering rather than guessing. Marked **Beta**.

## Asking a question

1. Open **AI Assistant** from the sidebar (or click the floating chat button in the bottom-right corner of any page).
2. You land on the **Chat** tab. If this is a fresh conversation you'll see **How can I help?** with a few starter buttons — **Fleet utilization**, **Overdue invoices**, **Expiring compliance**, **Dashboard KPIs**. Click one to run it instantly.
3. Otherwise type your question in the box at the bottom (*Ask about your fleet, customers, leases...*) and press **Send** or hit Enter. Use **Shift+Enter** for a new line.
4. The answer streams in line by line. While it works, you'll see *Looking up …* when it's pulling a specific dataset.
5. Ask follow-up questions in the same thread — the assistant remembers the recent conversation.

> **Note:** A reminder under the box reads *AI responses may be inaccurate* — always confirm anything important against the underlying record.

### Managing your chats

- **New Chat** (top of the left sidebar) starts a fresh conversation. Each chat is auto-titled from your first message.
- The **Recent** list shows your past chats. Click one to reopen it, or use **Search chats...** to filter by title.
- Hover a chat and click the trash icon to delete it (you'll be asked to confirm — this cannot be undone).
- Your chats are private to you.

---

## What it can answer

The assistant has read-only access to most of FleetForge and picks the right lookup for your question. It can pull data on:

- **Customers** — search, account details, their leases and invoices.
- **Equipment & fleet** — fleet summary, unit lookups by number (e.g. `CHS-001`, `RFR-002`, `DRY-014`), yard inventory.
- **Leases & reservations** — active leases, lease details, upcoming reservations.
- **Invoicing & AR** — revenue by period or customer, overdue invoices, AR aging, payments, credit notes.
- **Rates & pricing** — rate cards, rate-card items, customer-specific rates.
- **Maintenance & inspections** — work-order summaries, inspection records.
- **Damage & mileage** — damage claims, mileage logs.
- **Vendors & AP** — vendor lookups, bills, AP aging.
- **Accounting** — chart of accounts, journal entries, trial balance, account and bank balances, tax periods, budgets.
- **Fixed assets** — asset details, payoff analysis (e.g. "how long until `CHS-001` is paid off?"), depreciation, capex requests.
- **Collections & compliance** — promise-to-pay, collection notes, expiring documents.
- **Dashboard** — the same KPIs shown on your home screen.

> **Tip:** Be specific with identifiers. Unit numbers like `CHS-001` are equipment, company names like *Acme Logistics* are customers, `INV-2026-…` are invoices, and `LSE-2026-…` are leases — naming the exact record gets a faster, more accurate answer.

---

## Other tabs

Across the top of the page:

- **Reports** — describe a chart or table in plain English (e.g. *Revenue by customer this quarter as a bar chart*) and the assistant builds it. Quick-action buttons offer common ones.
- **Documents** — drag in or browse to a PDF or image (PDF, PNG, JPG, GIF, WEBP, max 10 MB), optionally add analysis instructions, and click **Analyze** to have the AI read and extract its contents. (Requires create access.)
- **Alerts** — the **Anomaly Alerts** panel lists unusual patterns the system has flagged (overdue spikes, compliance risk, maintenance spikes, customer risk, utilization drops), color-coded by severity. Tick **Unread only** to filter, click **Dismiss** to acknowledge one, and administrators see a **Run Scan** button to re-check on demand.
- **Usage** *(admin only)* — token counts, estimated cost, and request volume for today and this month, plus a per-user breakdown.

---

## Setup

The AI Assistant only works once an administrator has turned it on. Until then, opening the page shows **AI Assistant Not Configured**:

- If you're an **administrator**, click **Configure AI Settings** (or the **AI Settings** button in the page header) to go to **Settings → Integrations**, enable AI features, and add an Anthropic API key.
- If you're not an admin, you'll see *Contact your administrator to set up AI features.*

Both an enabled toggle **and** a valid API key are required before chat, reports, document analysis, and the on-page insight cards become available.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **Provider & model** — every AI feature routes through `lib/AI/ClaudeClient.php`, which calls the Anthropic Messages API (`https://api.anthropic.com/v1/messages`). The model defaults to `claude-sonnet-4-6` and is overridable via the `ai.model` setting.
- **Readiness gate** — the page computes `$aiReady = ai.enabled && ai.anthropic_api_key`. Credentials are read settings-table-first, `.env` second (the `ai.*` rows let admins rotate the key without redeploying). If either is missing, the not-configured card renders and no API calls are made.
- **Tool-calling, real data** — chat sends a system prompt plus a tool registry (`lib/AI/ToolRegistry.php`). Claude requests a tool, the server runs the SQL lookup, returns the result, and loops — up to 5 iterations (`ClaudeClient::MAX_TOOL_ITERATIONS`) — before answering. Financial tools are gated behind the `payments:view` permission.
- **Streaming** — responses stream over Server-Sent Events (`api/v1/ai/stream.php`) for the typewriter effect; if SSE fails (e.g. a proxy blocks it) the page falls back to the non-streaming `api/v1/ai/chat.php`. A one-shot retry absorbs Anthropic rate-limit (HTTP 429) bursts, and partial answers are preserved if a stream is cut off mid-response.
- **Sessions** — conversations are stored in `ai_chat_sessions` / `ai_chat_messages`, scoped by `user_id`; deleting a session cascades its messages. The last 20 messages are sent as context per request.
- **Token tracking & limits** — every call is logged to `ai_query_log` with token counts and an estimated cost (~$3/M input, ~$15/M output). A per-user daily token budget is enforced before each call, and a per-user request rate limit guards the AI endpoints.
- **AI elsewhere in the app** — the same engine powers:
  - A **floating chat widget** on every admin page (the bottom-right launcher), backed by the same chat endpoint.
  - **Insight cards** on entity pages via `includes/partials/ai-summary-card.php` — **AI Customer Insights** (customer page), **AI Lease Summary** (lease page), **AI Unit Analysis** (equipment page), and **AI Accounting Overview** (accounting dashboard). Click **Generate** to produce one; results are cached and tagged **Cached** or **Fresh**, with a refresh button to regenerate (`api/v1/ai/summary.php`, `lib/AI/SummaryEngine.php`).
  - **Anomaly detection** (`lib/AI/AnomalyDetector.php`) runs as a nightly scan storing alerts in `ai_anomaly_alerts`; the Alerts tab reads and acknowledges them.
- **Logging** — failures are written to `logs/ai.log` and never thrown to the user; the UI maps specific failure codes (no key, disabled, token limit, rate limit, network) to friendly messages.

</details>

## Related guides

- [Analytics](/help/analytics)
- [Dashboard](/help/dashboard)
