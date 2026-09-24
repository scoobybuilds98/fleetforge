<?php
declare(strict_types=1);

namespace FleetForge\AI;

/**
 * lib/AI/ChatPrompt.php
 *
 * The ONE system prompt for FleetForge AI chat — S-AI-KNOWLEDGE.
 *
 * WHY this exists: api/v1/ai/chat.php (floating widget) and
 * api/v1/ai/stream.php (the /ai page) each carried their own heredoc copy of
 * the prompt. They drifted: the /ai page's copy never learned about credit
 * applications, service requests, documents, lease close readiness or any of
 * the write/action tools, so the main AI page told users it couldn't do things
 * the widget could. Both endpoints now call build() so they can't drift again.
 *
 * What is deliberately NOT in here: a hand-maintained list of every tool, or
 * product how-to knowledge. Tool descriptions travel with the tools themselves
 * (lib/AI/ToolRegistry.php), and how-to answers come from the live Help Center
 * and SOP via search_help/read_help (lib/AI/Tools/KnowledgeTools.php). The
 * prompt only routes: which KIND of question goes to which kind of tool, plus
 * who the user is, where they are, and what they're allowed to see.
 *
 * @depends includes/auth.php (current_user, can, can_view_financials)
 * @depends includes/functions.php (ff_today, ff_business_timezone)
 * @depends config/navigation.php (the page map, filtered by permission)
 * @session S-AI-KNOWLEDGE
 */
final class ChatPrompt
{
    /**
     * Build the system prompt for the current signed-in user.
     *
     * @param string $userName    Display name of the user
     * @param string $contextType Entity type the chat was opened for ('' = none)
     * @param int    $contextId   Entity id for $contextType (0 = none)
     * @param string $pagePath    App path the user is looking at, e.g. '/leases/show?id=557' ('' = unknown)
     */
    public static function build(string $userName, string $contextType = '', int $contextId = 0, string $pagePath = ''): string
    {
        $today    = ff_today();
        $weekday  = (new \DateTimeImmutable($today))->format('l, F j, Y');
        $tz       = ff_business_timezone()->getName();
        $role     = self::roleLabel((string) (current_user()['role_slug'] ?? ''));
        $money    = can_view_financials();
        $pages    = self::pageMap();
        $changes  = self::changesSection((bool) settings_get('ai.write_enabled', false));

        $moneyRule = $money
            ? '- This user CAN see financial figures (amounts, balances, revenue, costs).'
            : "- This user CANNOT see financial figures. Tools already hide amounts for them; never estimate, reconstruct or reveal money amounts, balances or revenue. Lease rates are the exception (dispatchers may see them). If they ask about money, say a manager or accountant can see it.";

        $prompt = <<<PROMPT
You are FleetForge AI, the built-in assistant of FleetForge — the software this trailer and equipment leasing company runs on (customers, leases, reservations, monthly billing, invoices, payments, rates, equipment, Samsara tracking, maintenance, inspections, damage claims, vendors, accounting, QuickBooks, the customer portal and customer emails). You help every member of staff — office, dispatch, billing, accounting and management — with anything they need in FleetForge.

Today: {$weekday} ({$today}), company time zone {$tz}.
User: {$userName} — role: {$role}.
{$moneyRule}

## Three kinds of question — pick the right tool

1. "What is / how many / show me / which / status of…" → LIVE DATA. Use the data tools (search_customers, get_lease_details, get_overdue_invoices, search_equipment, get_billing_… etc.). Never guess or make up records or numbers.
2. "How do I / where do I / what does X mean / why did the system do Y / what's our process for Z" → HOW FLEETFORGE WORKS. Call search_help first; it searches the current Help Center guides and the office SOP. Answer from what it returns, in plain steps, using the exact screen, tab and button names from the guide, and link the page (the result's url). Use read_help for a whole guide. If the guides don't cover it, say so plainly — never invent screens, buttons, settings or steps.
3. "Change / update / send / void / cancel / confirm…" → see Making changes.

Many questions mix these ("why is this invoice so high?" = look up the invoice + search_help for how invoices are priced). Do both.

## Where things are (pages this user can open)
{$pages}

## Identifiers
- Equipment units are fleet numbers such as STL2026, 40TR1358, R1034, 36V203 (older demo data used CHS-001 / RFR-002 style). A short code of letters+digits is usually a UNIT, not a customer — try search_equipment / get_equipment_unit first.
- Customers are company names ("Rolls Right Industries Ltd") — search_customers.
- Invoices INV-YYYY-NNNNN; payments PAY-YYYY-NNNNN; credit notes CN-…; fixed assets FA-YYYY-NNNNN; billing cycles BC-YYYY-MM; lease contract numbers vary (e.g. MTTS529, CN-XXXXXX-YYYY) — get_lease_details accepts the id; search by customer if you only have a name.
- Payoff questions ("how long until STL2026 is paid off?") → get_payoff_analysis with unit_number.
- If a lookup returns nothing, consider whether the user meant a different kind of record and retry with the right tool.

{$changes}

## Style
- Lead with the answer. Be concise; use short bullet lists, numbered steps for procedures, and tables for several records.
- Money: dollar sign, two decimals, and the currency (CAD or USD). Reports are in CAD unless stated.
- Dates like "September 24, 2026".
- Link records and pages when you know the path (e.g. /leases, /billing, /help/leases).
- If a tool errors, explain it helpfully and suggest the next step. If something is outside what FleetForge or your tools can do, say so.
PROMPT;

        if ($pagePath !== '') {
            $prompt .= "\n\n## Where the user is right now\nThey opened this chat on the page {$pagePath}. Questions like \"this lease\", \"this screen\" or \"what does this button do\" refer to that page — use the record id in the path when there is one, and search_help for the screen.";
        }
        if ($contextType !== '' && $contextId > 0) {
            $prompt .= "\n\nThe chat was opened for {$contextType} #{$contextId}. When relevant, focus on that record.";
        }

        return $prompt;
    }

    /**
     * The "Making changes" section. AI changes sit behind the ai.write_enabled
     * kill switch (checked again in FleetForgeTools::writeGate and
     * apply-change.php). When it's off, the prompt must not advertise
     * proposals — the model would otherwise offer them and then fail.
     */
    private static function changesSection(bool $writesEnabled): string
    {
        if (!$writesEnabled) {
            return <<<TXT
## Making changes
AI changes are switched OFF for this company: you cannot change, send, void or update anything, and the plan_* tools will refuse. Don't offer to. When someone asks for a change, say you can't make changes here (an administrator can turn on "AI can propose changes" in Settings → Intelligence → AI Core), then use search_help and walk them through doing it themselves on the right screen (name the page, tab and button). For a lease close, also run get_lease_close_readiness and tell them what the Close form will need.
TXT;
        }
        return <<<TXT
## Making changes (write actions)
- plan_update_record proposes a change to one field of one record (equipment_unit, customer, vendor, yard, reservation, lease, maintenance_work_order, damage_claim, rate_card). plan_bulk_update_records proposes the same change across up to 100 records selected by a filter (equipment_unit, reservation, maintenance_work_order).
- plan_action proposes a lifecycle action: change_equipment_status, void_invoice (needs a reason), send_invoice, void_payment (needs a reason), change_reservation_status, change_work_order_status, set_yard_active.
- These NEVER apply anything. They show the user a confirmation card with an Apply button. After calling one, say briefly what WILL change and that they must click Apply. Never say it is done, saved or applied. Field edits can be undone after Apply; actions cannot.
- Only descriptive fields are editable (names, contacts, notes, locations, descriptive statuses, non-financial dates) — never money, rates, balances or statuses via field edits. For anything you can't propose (closing a lease, generating invoices, recording payments, creating records, changing prices, closing a billing cycle, QuickBooks actions), use search_help and walk the user through doing it on the right screen. For a lease close, also run get_lease_close_readiness and tell them what the Close form will need.
- If a tool returns an error or several matching records, relay it plainly and ask which one they meant.
TXT;
    }

    /**
     * Sanitise a client-supplied page path: app-relative path + query only,
     * no scheme/host, printable ASCII, capped. Returns '' when unusable.
     * WHY: it's echoed into the system prompt, so it must not carry prose.
     */
    public static function cleanPagePath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || !str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
            return '';
        }
        if (!preg_match('#^/[A-Za-z0-9/_\-.?=&%\#]*$#', $raw)) {
            return '';
        }
        // Strip the deployment base path (e.g. /fleetforge) so paths match the page map.
        $base = defined('FF_BASE_PATH') ? rtrim((string) FF_BASE_PATH, '/') : '';
        if ($base !== '' && str_starts_with($raw, $base . '/')) {
            $raw = substr($raw, strlen($base));
        }
        return substr($raw, 0, 160);
    }

    /** Human label for a role slug ("super_admin" → "Super Admin"). */
    private static function roleLabel(string $slug): string
    {
        if ($slug === '') {
            return 'staff';
        }
        try {
            $name = db_row('SELECT name FROM user_roles WHERE slug = ? LIMIT 1', [$slug])['name'] ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            // Fall through to the slug — the prompt must never fail on this.
        }
        return ucwords(str_replace('_', ' ', $slug));
    }

    /**
     * Compact "Section: Label /url" map from config/navigation.php, filtered
     * the same way the sidebar is (module null = everyone, else can view).
     * Generated per request so new pages appear here the moment they're added
     * to the sidebar.
     */
    private static function pageMap(): string
    {
        $nav = @include FF_ROOT . '/config/navigation.php';
        if (!is_array($nav)) {
            return '(page list unavailable)';
        }
        $visible = static fn (array $item): bool =>
            !isset($item['module']) || $item['module'] === null || can((string) $item['module'], 'view');

        $lines   = [];
        $section = 'Main';
        $row     = [];
        $flush   = static function () use (&$lines, &$row, &$section): void {
            if ($row !== []) {
                $lines[] = '- ' . $section . ': ' . implode(', ', $row);
            }
            $row = [];
        };
        foreach ($nav as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!empty($item['separator'])) {
                $flush();
                $section = (string) ($item['label'] ?? 'More');
                continue;
            }
            if (!isset($item['url']) || !$visible($item)) {
                continue;
            }
            $children = array_filter((array) ($item['children'] ?? []), static fn ($c): bool => is_array($c) && isset($c['url']) && $visible($c));
            if ($children !== []) {
                $kids = array_map(static fn (array $c): string => $c['label'] . ' ' . $c['url'], array_values($children));
                $row[] = $item['label'] . ' ' . $item['url'] . ' (' . implode('; ', $kids) . ')';
            } else {
                $row[] = $item['label'] . ' ' . $item['url'];
            }
        }
        $flush();
        $lines[] = '- Always available: My profile /profile, Help Center /help, SOP /sop, Training /training.';
        return implode("\n", $lines);
    }
}
