<?php
declare(strict_types=1);

/**
 * FleetForge — SOP chapter library (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopLibrary.php
 * @description Finds, describes and renders the SOP chapters in docs/sop/.
 *
 *              One file per chapter, named NN-slug.md (NN = chapter number,
 *              which also sets the order). Each starts with front matter:
 *
 *                ---
 *                title: Month-end close
 *                short: Month-end
 *                summary: The monthly checklist, from drafts to Close Period.
 *                icon: clipboard-document-check      (public/assets/icons/)
 *                accent: warning                      (primary|info|success|warning|danger|purple)
 *                part: Accounting                     (hub grouping)
 *                audience: manager, accountant        (see AUDIENCES)
 *                reviewed: 2026-09-24                 (last time the chapter was checked
 *                                                      against the screens — bump it on edit)
 *                ---
 *
 *              A chapter's content_hash (sha256 of the whole file, 16 chars)
 *              is what "Mark as read" records, so editing a chapter flags it
 *              "Updated since you read it" for everyone who read the old one.
 *
 *              Files starting with "_" are ignored (templates / notes).
 *              Content is trusted repo content — never user input.
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

final class SopLibrary
{
    /** Role filters on the hub — keys used in front matter `audience:`. */
    public const AUDIENCES = [
        'dispatcher'  => 'Dispatcher',
        'manager'     => 'Manager',
        'accountant'  => 'Accountant',
        'super_admin' => 'Super Admin',
    ];

    public const ACCENTS = ['primary', 'info', 'success', 'warning', 'danger', 'purple'];

    /** @var list<array<string,mixed>>|null */
    private static ?array $chapters = null;

    /** @var array<string,array{html:string,toc:list<array>}> */
    private static array $rendered = [];

    public static function dir(): string
    {
        return FF_ROOT . '/docs/sop';
    }

    /**
     * Every chapter's metadata, in chapter order.
     *
     * @return list<array{slug:string, number:int, file:string, title:string, short:string, summary:string, icon:string, accent:string, part:string, audience:list<string>, reviewed:string, hash:string, minutes:int}>
     */
    public static function chapters(): array
    {
        if (self::$chapters !== null) {
            return self::$chapters;
        }
        $out = [];
        foreach ((array) glob(self::dir() . '/*.md') as $file) {
            $base = basename((string) $file, '.md');
            if (str_starts_with($base, '_') || !preg_match('/^(\d{2})-([a-z0-9-]+)$/', $base, $m)) {
                continue;
            }
            $raw = (string) file_get_contents((string) $file);
            [$meta, $body] = self::frontMatter($raw);

            $audience = array_values(array_filter(
                array_map('trim', explode(',', strtolower($meta['audience'] ?? ''))),
                static fn (string $a): bool => isset(self::AUDIENCES[$a])
            ));
            $words = str_word_count(strip_tags((string) preg_replace('/[{}:#|*`>\-\[\]()@]/', ' ', $body)));

            $out[] = [
                'slug'     => $m[2],
                'number'   => (int) $m[1],
                'file'     => (string) $file,
                'title'    => $meta['title'] ?? ucwords(str_replace('-', ' ', $m[2])),
                'short'    => $meta['short'] ?? ($meta['title'] ?? $m[2]),
                'summary'  => $meta['summary'] ?? '',
                'icon'     => $meta['icon'] ?? 'book-open',
                'accent'   => in_array($meta['accent'] ?? '', self::ACCENTS, true) ? $meta['accent'] : 'primary',
                'part'     => $meta['part'] ?? '',
                'audience' => $audience,
                'reviewed' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['reviewed'] ?? '') ? $meta['reviewed'] : '',
                'hash'     => substr(hash('sha256', $raw), 0, 16),
                'minutes'  => max(1, (int) round($words / 200)),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        return self::$chapters = $out;
    }

    /** @return array<string,mixed>|null */
    public static function chapter(string $slug): ?array
    {
        foreach (self::chapters() as $c) {
            if ($c['slug'] === $slug) {
                return $c;
            }
        }
        return null;
    }

    /** The chapter's markdown body (front matter removed). */
    public static function body(string $slug): string
    {
        $c = self::chapter($slug);
        if ($c === null) {
            throw new \InvalidArgumentException("No SOP chapter '{$slug}'");
        }
        return self::frontMatter((string) file_get_contents($c['file']))[1];
    }

    /**
     * Rendered chapter (cached for the request).
     *
     * @return array{html:string, toc:list<array{level:int,id:string,text:string}>}
     */
    public static function render(string $slug): array
    {
        return self::$rendered[$slug] ??= SopRenderer::render(self::body($slug));
    }

    /**
     * Previous / next chapter for the reader's footer.
     *
     * @return array{prev:?array, next:?array}
     */
    public static function neighbours(string $slug): array
    {
        $all = self::chapters();
        foreach ($all as $i => $c) {
            if ($c['slug'] === $slug) {
                return ['prev' => $all[$i - 1] ?? null, 'next' => $all[$i + 1] ?? null];
            }
        }
        return ['prev' => null, 'next' => null];
    }

    /**
     * Search index: one entry per chapter section (h2/h3), plain text.
     * Built from the RENDERED html so path chips, cards and entry cards are
     * searchable by what the reader sees.
     *
     * @return list<array{slug:string, number:int, chapter:string, section:string, anchor:string, text:string}>
     */
    public static function searchIndex(): array
    {
        $index = [];
        foreach (self::chapters() as $c) {
            $html  = self::render($c['slug'])['html'];
            // Drop inline SVG (diagram labels are repeated in the prose) and
            // the static checklist copy is fine to keep — it's real text.
            $html  = (string) preg_replace('/<svg\b.*?<\/svg>/s', ' ', $html);
            $parts = preg_split('/(<h[23] id="[^"]+"[^>]*>.*?<\/h[23]>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            $section = $c['title'];
            $anchor  = '';
            foreach ($parts as $part) {
                if (preg_match('/^<h[23] id="([^"]+)"[^>]*>(.*?)<\/h[23]>$/s', $part, $m)) {
                    $anchor  = $m[1];
                    $section = trim(html_entity_decode(strip_tags((string) preg_replace('/<a class="sop-anchor".*?<\/a>/s', '', $m[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    continue;
                }
                $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $part)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($text === '') {
                    continue;
                }
                $index[] = [
                    'slug'    => $c['slug'],
                    'number'  => $c['number'],
                    'chapter' => $c['title'],
                    'section' => $section,
                    'anchor'  => $anchor,
                    'text'    => mb_substr($text, 0, 1600),
                ];
            }
        }
        return $index;
    }

    /** Total reading time across all chapters, in minutes. */
    public static function totalMinutes(): int
    {
        return array_sum(array_column(self::chapters(), 'minutes'));
    }

    /**
     * The most recent `reviewed:` date across chapters (Y-m-d, or '' when
     * none). WHY front matter and not filemtime: a deploy rewrites every
     * file's mtime, so "last updated" would read as the deploy day.
     */
    public static function lastReviewed(): string
    {
        $dates = array_filter(array_column(self::chapters(), 'reviewed'));
        return $dates === [] ? '' : max($dates);
    }

    /**
     * Split `---\nkey: value\n---\n` front matter from the body.
     *
     * @return array{0: array<string,string>, 1: string}
     */
    private static function frontMatter(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!str_starts_with($raw, "---\n")) {
            return [[], $raw];
        }
        $end = strpos($raw, "\n---", 3);
        if ($end === false) {
            return [[], $raw];
        }
        $meta = [];
        foreach (explode("\n", substr($raw, 4, $end - 4)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $meta[trim($k)] = trim($v);
            }
        }
        return [$meta, ltrim(substr($raw, $end + 4), "\n")];
    }
}
