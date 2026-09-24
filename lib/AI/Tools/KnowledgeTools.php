<?php
declare(strict_types=1);

namespace FleetForge\AI\Tools;

use FleetForge\Help\HelpRenderer;
use FleetForge\Sop\SopLibrary;

/**
 * lib/AI/Tools/KnowledgeTools.php
 *
 * "How does FleetForge work?" tools for the AI assistant — S-AI-KNOWLEDGE.
 *
 * WHY: the data tools answer "what is the state of X", but staff also ask
 * "how do I close a lease", "where do I enter month-end readings", "why did
 * this invoice get a reconciliation credit". Those answers already live in the
 * in-app Help Center (docs/help/*.md) and SOP (docs/sop/*.md), which are
 * updated in the same commit as every screen change. Reading them at question
 * time means the assistant is current the moment the guides are — no second
 * copy of the product knowledge to drift inside a system prompt.
 *
 * Both sources are readable by every signed-in staff user (the /help and /sop
 * pages have no role gate), so these tools need no permission check beyond the
 * chat endpoint's ai:view.
 *
 * Tools:
 *   search_help — ranked sections across both sources, each with its page link
 *   read_help   — one whole guide/chapter (or one section of it); no slug = catalog
 *
 * Only files inside docs/help and docs/sop are ever read; slugs are sanitised
 * and resolved through HelpRenderer / SopLibrary, never used as paths directly.
 *
 * @depends lib/Help/HelpRenderer.php, lib/Sop/SopLibrary.php
 * @session S-AI-KNOWLEDGE
 */
final class KnowledgeTools
{
    /** Longest section body returned by search_help (chars). */
    private const SNIPPET_CHARS = 1800;

    /** Longest document returned by read_help (chars) — a token backstop. */
    private const READ_CHARS = 24000;

    /** Words too common to rank on. */
    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'are', 'was',
        'be', 'it', 'its', 'this', 'that', 'how', 'do', 'does', 'did', 'i', 'we', 'you', 'my', 'our',
        'can', 'what', 'where', 'when', 'why', 'which', 'who', 'at', 'by', 'from', 'as', 'if', 'me',
        'should', 'would', 'will', 'there', 'about', 'into', 'get', 'use', 'using', 'fleetforge',
    ];

    /** @var list<array{source:string,slug:string,doc:string,section:string,url:string,text:string}>|null */
    private static ?array $index = null;

    // ────────────────────────────────────────────────────────────
    // Module contract (see FleetForgeTools::MODULES)
    // ────────────────────────────────────────────────────────────

    /** Anthropic tool definitions (+ internal _tags) for this module. */
    public static function definitions(): array
    {
        return [
            // ── Product knowledge (S-AI-KNOWLEDGE) ──────────────
            // WHY first: "how do I…" questions are as common as data questions,
            // and these read the live Help Center + SOP, so answers track every
            // screen change without a prompt edit.
            [
                'name' => 'search_help',
                'description' => "Search FleetForge's own Help Center guides and the office SOP for how the software works and how the office does things. Use for ANY \"how do I / where do I / what does X mean / why did the system do Y / what is our process or policy for Z\" question — creating or closing leases, month-end billing cycles, readings, generating/sending invoices, credit notes, payments, rates and price changes, equipment, Samsara tracking, customer portal, customer emails, QuickBooks, accounting, month-end close, roles and permissions, settings. Returns the best-matching sections (text + page link). Answer from the returned text; never invent screens, buttons or steps.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A few keywords — the task, screen or button name (e.g. "close lease odometer refund", "billing cycle readings", "change price from a date").'],
                        'limit' => ['type' => 'integer', 'description' => 'Max sections to return (1-8, default 5).'],
                    ],
                    'required' => ['query'],
                ],
                '_tags' => ['chat'],
            ],
            [
                'name' => 'read_help',
                'description' => 'Read one whole Help Center guide or SOP chapter (or one named section of it). Call with no slug to list every guide and chapter with a one-line summary. Use after search_help when a section was cut off or the user wants the full procedure.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'source'  => ['type' => 'string', 'enum' => ['help', 'sop', ''], 'description' => "'help' = Help Center guide, 'sop' = SOP chapter. Optional when the slug is unambiguous."],
                        'slug'    => ['type' => 'string', 'description' => 'Guide/chapter slug from search_help results or the catalog (e.g. "leases", "billing", "month-end-close"). Empty = list all.'],
                        'section' => ['type' => 'string', 'description' => 'Optional section heading (or part of it) to return just that section.'],
                    ],
                    'required' => [],
                ],
                '_tags' => ['chat'],
            ],
        ];
    }

    /** Does this module own $toolName? */
    public static function handles(string $toolName): bool
    {
        return in_array($toolName, ['search_help', 'read_help'], true);
    }

    /** Dispatch one tool call (read-only; no user-specific data). */
    public static function run(string $toolName, array $input, ?int $userId = null, ?int $sessionId = null): mixed
    {
        return match ($toolName) {
            'search_help' => self::searchHelp($input),
            'read_help'   => self::readHelp($input),
        };
    }

    // ────────────────────────────────────────────────────────────
    // search_help — rank every guide/SOP section against the query
    // ────────────────────────────────────────────────────────────
    public static function searchHelp(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        $limit = max(1, min(8, (int) ($input['limit'] ?? 5)));
        $terms = self::terms($query);

        if ($terms === []) {
            return ['error' => true, 'message' => 'Give a few keywords, e.g. "close lease odometer" or "month-end billing readings". Call read_help with no slug to list every guide.'];
        }

        $scored = [];
        foreach (self::index() as $i => $entry) {
            $score = self::score($entry, $terms, mb_strtolower($query));
            if ($score > 0) {
                $scored[$i] = $score;
            }
        }
        arsort($scored);

        $results = [];
        foreach (array_slice($scored, 0, $limit, true) as $i => $score) {
            $e = self::index()[$i];
            $results[] = [
                'source'  => $e['source'] === 'sop' ? 'SOP' : 'Help Center',
                'guide'   => $e['doc'],
                'section' => $e['section'],
                'url'     => $e['url'],
                'slug'    => $e['slug'],
                'text'    => mb_strlen($e['text']) > self::SNIPPET_CHARS
                    ? mb_substr($e['text'], 0, self::SNIPPET_CHARS) . ' …'
                    : $e['text'],
            ];
        }

        if ($results === []) {
            return [
                'results' => [],
                'note'    => 'No guide section matched. Try other words (the screen or button name), or call read_help with no slug to see every guide. If the guides do not cover it, say so rather than guessing.',
            ];
        }

        return [
            'results' => $results,
            'note'    => 'Answer from these sections and link the page (url). Use read_help(source, slug) for the whole guide when a section is cut off.',
        ];
    }

    // ────────────────────────────────────────────────────────────
    // read_help — one full guide / chapter, one section, or the catalog
    // ────────────────────────────────────────────────────────────
    public static function readHelp(array $input): array
    {
        $source  = strtolower(trim((string) ($input['source'] ?? '')));
        $slug    = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) ($input['slug'] ?? '')))) ?? '';
        $section = trim((string) ($input['section'] ?? ''));

        if ($slug === '') {
            return self::catalog();
        }

        // Tolerate a missing source: a slug belongs to at most one of the two.
        if ($source === '') {
            $source = SopLibrary::chapter($slug) !== null ? 'sop' : 'help';
        }

        $entries = array_values(array_filter(
            self::index(),
            static fn (array $e): bool => $e['source'] === $source && $e['slug'] === $slug
        ));
        if ($entries === []) {
            return ['error' => true, 'message' => "No {$source} guide '{$slug}'. Call read_help with no slug to list every guide."];
        }

        if ($section !== '') {
            $needle = mb_strtolower($section);
            foreach ($entries as $e) {
                if (str_contains(mb_strtolower($e['section']), $needle)) {
                    return ['source' => $source, 'guide' => $e['doc'], 'section' => $e['section'], 'url' => $e['url'], 'text' => $e['text']];
                }
            }
        }

        $out = '';
        foreach ($entries as $e) {
            $out .= ($e['section'] !== $e['doc'] ? '## ' . $e['section'] . "\n" : '') . $e['text'] . "\n\n";
        }
        $truncated = mb_strlen($out) > self::READ_CHARS;

        return [
            'source'    => $source,
            'guide'     => $entries[0]['doc'],
            'url'       => $source === 'sop' ? base_url('sop/' . $slug) : base_url('help/' . $slug),
            'sections'  => array_values(array_unique(array_column($entries, 'section'))),
            'text'      => $truncated ? mb_substr($out, 0, self::READ_CHARS) . "\n… (cut off — ask for a specific section)" : $out,
        ];
    }

    // ────────────────────────────────────────────────────────────
    // catalog — every guide and SOP chapter with its one-line summary
    // ────────────────────────────────────────────────────────────
    private static function catalog(): array
    {
        $help = [];
        foreach (HelpRenderer::listGuides() as $g) {
            $help[] = ['slug' => $g['slug'], 'title' => $g['title'], 'about' => $g['description']];
        }
        $sop = [];
        foreach (SopLibrary::chapters() as $c) {
            $sop[] = ['slug' => $c['slug'], 'title' => $c['title'], 'about' => $c['summary']];
        }
        return [
            'help_center' => $help,
            'sop'         => $sop,
            'note'        => 'Help Center = how each screen works. SOP = the office procedures (roles, golden rules, daily work, billing, accounting, QuickBooks, month-end). Read one with read_help(source, slug).',
        ];
    }

    // ────────────────────────────────────────────────────────────
    // index — one entry per ##/### section of every guide + chapter
    //
    // Help guides are split on their markdown headings. SOP chapters reuse
    // SopLibrary::searchIndex(), which is built from the RENDERED chapter so
    // path chips ("Billing › Monthly Billing") read the way the page shows them.
    // ────────────────────────────────────────────────────────────
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $index = [];

        foreach (HelpRenderer::listGuides() as $g) {
            $path = FF_ROOT . '/docs/help/' . $g['slug'] . '.md';
            if (!is_file($path)) {
                continue;
            }
            $md = (string) file_get_contents($path);
            // Strip front matter and the <details> "under the hood" wrapper tags
            // (keep their text — admins do ask how things work technically).
            $md = (string) preg_replace('/\A---\R.*?\R---\R/s', '', $md);
            $md = str_replace(['<details>', '</details>'], '', $md);
            $md = str_replace(['<summary>', '</summary>'], ['### ', ''], $md);

            $section = $g['title'];
            $buffer  = '';
            $flush   = static function () use (&$index, &$buffer, &$section, $g): void {
                $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\n---\n", "\n", $buffer)));
                if ($text !== '') {
                    $index[] = [
                        'source'  => 'help',
                        'slug'    => $g['slug'],
                        'doc'     => $g['title'],
                        'section' => $section,
                        'url'     => base_url('help/' . $g['slug']),
                        'text'    => $text,
                    ];
                }
                $buffer = '';
            };
            foreach (preg_split('/\R/', $md) ?: [] as $line) {
                if (preg_match('/^#{1,3}\s+(.+)$/', $line, $m)) {
                    $flush();
                    $section = trim($m[1]);
                    continue;
                }
                $buffer .= $line . "\n";
            }
            $flush();
        }

        foreach (SopLibrary::searchIndex() as $s) {
            $index[] = [
                'source'  => 'sop',
                'slug'    => $s['slug'],
                'doc'     => 'SOP ' . $s['number'] . ' — ' . $s['chapter'],
                'section' => $s['section'],
                'url'     => base_url('sop/' . $s['slug']) . ($s['anchor'] !== '' ? '#' . $s['anchor'] : ''),
                'text'    => $s['text'],
            ];
        }

        return self::$index = $index;
    }

    /** Lower-cased, de-stopworded, lightly stemmed query terms. */
    private static function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $out   = [];
        foreach ($words as $w) {
            if ($w === '' || in_array($w, self::STOPWORDS, true) || mb_strlen($w) < 2) {
                continue;
            }
            $out[] = self::stem($w);
        }
        return array_values(array_unique($out));
    }

    /** Crude suffix strip so "invoices"/"invoicing"/"invoiced" meet "invoice". */
    private static function stem(string $w): string
    {
        foreach (['ing', 'ies', 'ed', 'es', 's'] as $suffix) {
            if (mb_strlen($w) > mb_strlen($suffix) + 3 && str_ends_with($w, $suffix)) {
                return mb_substr($w, 0, -mb_strlen($suffix));
            }
        }
        return $w;
    }

    /**
     * Section score: heading hits weigh most, then guide title, then body
     * frequency (capped so one long section can't drown the rest). Sections
     * matching every term, or the exact phrase, get a bonus.
     */
    private static function score(array $e, array $terms, string $phrase): float
    {
        $head = mb_strtolower($e['section']);
        $doc  = mb_strtolower($e['doc']);
        $body = mb_strtolower($e['text']);

        $score   = 0.0;
        $matched = 0;
        foreach ($terms as $t) {
            $hit = false;
            if (str_contains($head, $t)) { $score += 6; $hit = true; }
            if (str_contains($doc, $t))  { $score += 3; $hit = true; }
            $n = substr_count($body, $t);
            if ($n > 0) { $score += min(5, $n) * 1.0; $hit = true; }
            if ($hit) {
                $matched++;
            }
        }
        if ($matched === 0) {
            return 0.0;
        }
        $score *= $matched / count($terms);
        if ($matched === count($terms) && count($terms) > 1) {
            $score += 4;
        }
        if (mb_strlen($phrase) > 6 && str_contains($body, $phrase)) {
            $score += 8;
        }
        return $score;
    }
}
