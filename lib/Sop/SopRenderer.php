<?php
declare(strict_types=1);

/**
 * FleetForge — SOP chapter renderer (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopRenderer.php
 * @description Turns one docs/sop/*.md chapter into the HTML the /sop pages
 *              show. Plain markdown goes through Parsedown (like the Help
 *              Center); on top of it the SOP has a few block DIRECTIVES and
 *              one inline token, so the text can carry the graphics without
 *              anyone hand-writing HTML:
 *
 *   :::callout rule|warning|tip|info|danger Optional title    … :::
 *   :::cards          ### :icon: Title  + body, repeated        … :::
 *   :::entries        Event | DR side | CR side | note, per line… :::
 *   :::filter Placeholder text   (wraps a table; adds a live filter box) … :::
 *   :::checklist key  (the shared month-end checklist — see SopChecklist) … :::
 *   :::diagram name Optional caption   (one line; lib/Sop/diagrams/name.svg)
 *
 *   {{Accounting › Settings › GL Account Mapping @/accounting/settings}}
 *       → a breadcrumb "path chip"; with @/path it links to the real screen.
 *   [text](sop:slug#anchor)  → a link to another SOP chapter.
 *
 *              WHY directives are expanded BEFORE Parsedown: Parsedown does not
 *              parse markdown inside HTML blocks, so each directive's body is
 *              rendered on its own and parked behind a %%SOPn%% placeholder
 *              that survives Parsedown untouched, then swapped back in.
 *              Content is trusted repo content (never user input), so safe
 *              mode is off — the same stance as HelpRenderer.
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

use Parsedown;

final class SopRenderer
{
    /** Callout variants → [heroicon, default title]. */
    private const CALLOUTS = [
        'rule'    => ['shield-check',         'Rule'],
        'warning' => ['exclamation-triangle', 'Careful'],
        'tip'     => ['sparkles',             'Tip'],
        'info'    => ['book-open',            'Good to know'],
        'danger'  => ['x-circle',             'Stop'],
    ];

    /** @var array<string,string> placeholder → rendered HTML */
    private array $blocks = [];

    /** @var array<string,int> heading id → times used (for unique anchors) */
    private array $ids = [];

    private Parsedown $pd;

    public function __construct()
    {
        $this->pd = new Parsedown();
        $this->pd->setSafeMode(false);
        $this->pd->setBreaksEnabled(false);
    }

    /**
     * Render one chapter body.
     *
     * @return array{html:string, toc:list<array{level:int,id:string,text:string}>}
     */
    public static function render(string $markdown): array
    {
        return (new self())->run($markdown);
    }

    /**
     * Render a single line of inline markdown (checklist items, card titles).
     */
    public static function inline(string $text): string
    {
        $r = new self();
        return self::rewriteSopLinks($r->pd->line($r->inlineTokens($text)));
    }

    /**
     * @return array{html:string, toc:list<array{level:int,id:string,text:string}>}
     */
    private function run(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = $this->expandDirectives($markdown);
        $html     = $this->pd->text($this->inlineTokens($markdown));

        // Swap the parked directive HTML back in. Parsedown wraps a lone
        // placeholder line in <p>; a placeholder inside a list item may not be.
        foreach ($this->blocks as $token => $blockHtml) {
            $html = str_replace(['<p>' . $token . '</p>', $token], [$blockHtml, $blockHtml], $html);
        }

        $toc  = [];
        $html = $this->decorateHeadings($html, $toc);
        $html = $this->decorate($html);

        return ['html' => $html, 'toc' => $toc];
    }

    // ============================================================
    // Block directives
    // ============================================================

    private function expandDirectives(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $out   = [];
        $n     = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (!preg_match('/^:::([a-z]+)\s*(.*)$/', $line, $m)) {
                $out[] = $line;
                continue;
            }
            $name = $m[1];
            $args = trim($m[2]);

            if ($name === 'diagram') {
                $out[] = '';
                $out[] = $this->park($this->diagram($args));
                $out[] = '';
                continue;
            }

            // Every other directive has a body up to a bare ":::" line.
            $body = [];
            for ($i++; $i < $n && trim($lines[$i]) !== ':::'; $i++) {
                $body[] = $lines[$i];
            }
            if ($i >= $n) {
                throw new \RuntimeException("SOP directive :::{$name} is never closed with ':::'");
            }
            $bodyText = implode("\n", $body);

            $html = match ($name) {
                'callout'   => $this->callout($args, $bodyText),
                'cards'     => $this->cards($bodyText),
                'entries'   => $this->entries($bodyText),
                'filter'    => $this->filter($args, $bodyText),
                'checklist' => $this->checklist($args, $bodyText),
                default     => throw new \RuntimeException("Unknown SOP directive :::{$name}"),
            };
            $out[] = '';
            $out[] = $this->park($html);
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** Park rendered HTML behind a placeholder Parsedown leaves alone. */
    private function park(string $html): string
    {
        $token = '%%SOP' . count($this->blocks) . '%%';
        $this->blocks[$token] = $html;
        return $token;
    }

    /** Render a nested markdown body (directive contents). */
    private function body(string $markdown): string
    {
        return $this->pd->text($this->inlineTokens($markdown));
    }

    private function callout(string $args, string $body): string
    {
        $parts   = preg_split('/\s+/', $args, 2);
        $variant = $parts[0] ?? 'info';
        if (!isset(self::CALLOUTS[$variant])) {
            throw new \RuntimeException("Unknown callout variant '{$variant}'");
        }
        [$icon, $defaultTitle] = self::CALLOUTS[$variant];
        $title = trim($parts[1] ?? '') ?: $defaultTitle;

        return '<aside class="sop-callout sop-callout--' . e($variant) . '">'
            . '<div class="sop-callout-icon">' . SopIcons::svg($icon) . '</div>'
            . '<div class="sop-callout-body"><div class="sop-callout-title">' . $this->pd->line($this->inlineTokens($title)) . '</div>'
            . $this->body($body) . '</div></aside>';
    }

    /**
     * A grid of cards. Each card starts with "### :icon: Title" (icon
     * optional); the lines under it are the card's markdown body.
     */
    private function cards(string $body): string
    {
        $cards   = [];
        $current = null;
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^###\s+(?::([a-z0-9-]+):\s*)?(.+)$/', $line, $m)) {
                if ($current !== null) {
                    $cards[] = $current;
                }
                $current = ['icon' => $m[1] ?: null, 'title' => trim($m[2]), 'body' => []];
                continue;
            }
            if ($current !== null) {
                $current['body'][] = $line;
            }
        }
        if ($current !== null) {
            $cards[] = $current;
        }
        if ($cards === []) {
            throw new \RuntimeException(':::cards needs at least one "### Title" card');
        }

        $html = '<div class="sop-cards">';
        foreach ($cards as $c) {
            $html .= '<div class="sop-card">'
                . '<div class="sop-card-head">'
                . ($c['icon'] ? '<span class="sop-card-icon">' . SopIcons::svg($c['icon']) . '</span>' : '')
                . '<span class="sop-card-title">' . $this->pd->line($this->inlineTokens($c['title'])) . '</span></div>'
                . '<div class="sop-card-body">' . $this->body(implode("\n", $c['body'])) . '</div></div>';
        }
        return $html . '</div>';
    }

    /**
     * Journal-entry cards: "Event | DR side | CR side | note". A side may
     * list several accounts joined with " + "; "—" or empty leaves it out
     * (a reversal has no fixed sides, only a note).
     */
    private function entries(string $body): string
    {
        $html = '<div class="sop-entries">';
        $count = 0;
        foreach (explode("\n", $body) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = array_map('trim', explode('|', rtrim(trim($line), '|')));
            if (count($f) < 3) {
                throw new \RuntimeException("SOP entries line needs 'Event | DR | CR': {$line}");
            }
            [$event, $dr, $cr] = $f;
            $note = $f[3] ?? '';
            $html .= '<div class="sop-entry"><div class="sop-entry-event">' . $this->pd->line($this->inlineTokens($event)) . '</div>';
            foreach (['dr' => $dr, 'cr' => $cr] as $side => $accounts) {
                if ($accounts === '' || $accounts === '—') {
                    continue;
                }
                foreach (explode(' + ', $accounts) as $acct) {
                    $html .= '<div class="sop-entry-line sop-entry-line--' . $side . '"><span class="sop-entry-side">'
                        . strtoupper($side) . '</span><span>' . $this->pd->line($this->inlineTokens($acct)) . '</span></div>';
                }
            }
            if ($note !== '') {
                $html .= '<div class="sop-entry-note">' . $this->pd->line($this->inlineTokens($note)) . '</div>';
            }
            $html .= '</div>';
            $count++;
        }
        if ($count === 0) {
            throw new \RuntimeException(':::entries is empty');
        }
        return $html . '</div>';
    }

    /**
     * A table (or any block) with a live text filter above it. The filtering
     * itself is the sopFilter() Alpine component in public/assets/js/sop.js;
     * without JS the table simply shows in full.
     */
    private function filter(string $placeholder, string $body): string
    {
        $placeholder = $placeholder !== '' ? $placeholder : 'Filter…';
        return '<div class="sop-filter" x-data="sopFilter()">'
            . '<label class="sop-filter-box">' . SopIcons::svg('magnifying-glass')
            . '<input type="search" class="sop-filter-input" x-model="q" @input="apply()" placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '"></label>'
            . '<div class="sop-filter-target" x-ref="target">' . $this->body($body) . '</div>'
            . '<p class="sop-filter-empty" x-show="q && shown === 0" x-cloak>Nothing matches “<span x-text="q"></span>”.</p>'
            . '</div>';
    }

    /**
     * The shared month-end checklist, rendered STATIC here (print view, no
     * JS). The chapter page swaps the whole marked region for the live
     * component (SopChecklist + the sopChecklist() Alpine component).
     */
    private function checklist(string $key, string $body): string
    {
        $def  = SopChecklist::parse($body);
        $html = '<!--sop-checklist:' . e($key) . '--><div class="sop-checklist-static">';
        foreach ($def['stages'] as $stage) {
            $html .= '<h3 class="sop-cl-static-stage">' . e($stage['letter'] . '. ' . $stage['title'])
                . ($stage['owner'] !== '' ? ' <span class="sop-cl-owner">' . e($stage['owner']) . '</span>' : '') . '</h3><ul class="sop-tasks">';
            foreach ($stage['items'] as $item) {
                $html .= '<li class="sop-task"><span class="sop-task-box" aria-hidden="true"></span><span>' . $item['html'] . '</span></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div><!--/sop-checklist-->';
    }

    private function diagram(string $args): string
    {
        $parts   = preg_split('/\s+/', $args, 2);
        $name    = preg_replace('/[^a-z0-9-]/', '', strtolower($parts[0] ?? ''));
        $caption = trim($parts[1] ?? '');
        $file    = __DIR__ . '/diagrams/' . $name . '.svg';
        if ($name === '' || !is_file($file)) {
            throw new \RuntimeException("SOP diagram '{$name}' not found in lib/Sop/diagrams/");
        }
        return '<figure class="sop-figure sop-figure--' . e($name) . '"><div class="sop-figure-scroll">'
            . (string) file_get_contents($file) . '</div>'
            . ($caption !== '' ? '<figcaption>' . $this->pd->line($caption) . '</figcaption>' : '')
            . '</figure>';
    }

    // ============================================================
    // Inline tokens
    // ============================================================

    /**
     * {{A › B › C @/path}} → path chip. Done on the markdown (before
     * Parsedown) and emitted as inline HTML with no "|", so the chip is safe
     * inside table cells.
     */
    private function inlineTokens(string $markdown): string
    {
        return (string) preg_replace_callback('/\{\{([^{}]+?)\}\}/', function (array $m): string {
            $raw  = $m[1];
            $href = null;
            if (preg_match('/^(.*?)\s*@(\/[A-Za-z0-9_\-\/.?=&#]*)\s*$/', $raw, $lm)) {
                $raw  = $lm[1];
                $href = $lm[2];
            }
            $segments = array_values(array_filter(array_map('trim', preg_split('/\s*(?:›|→|>)\s*/u', $raw)), 'strlen'));
            $inner = '';
            foreach ($segments as $i => $seg) {
                if ($i > 0) {
                    $inner .= '<span class="sop-path-sep" aria-hidden="true">›</span>';
                }
                $inner .= '<span class="sop-path-seg">' . e($seg) . '</span>';
            }
            if ($href === null) {
                return '<span class="sop-path">' . $inner . '</span>';
            }
            return '<a class="sop-path sop-path--link" href="' . e(base_url(ltrim($href, '/'))) . '" title="Open this screen">'
                . $inner . '<span class="sop-path-go" aria-hidden="true">↗</span></a>';
        }, $markdown);
    }

    // ============================================================
    // HTML post-processing
    // ============================================================

    /**
     * Give every h2/h3 a stable, unique id and collect the table of contents.
     *
     * @param list<array{level:int,id:string,text:string}> $toc
     */
    private function decorateHeadings(string $html, array &$toc): string
    {
        return (string) preg_replace_callback('/<h([23])>(.*?)<\/h\1>/s', function (array $m) use (&$toc): string {
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $id   = self::slug($text);
            $this->ids[$id] = ($this->ids[$id] ?? 0) + 1;
            if ($this->ids[$id] > 1) {
                $id .= '-' . $this->ids[$id];
            }
            $toc[] = ['level' => (int) $m[1], 'id' => $id, 'text' => $text];
            return '<h' . $m[1] . ' id="' . e($id) . '" class="sop-h">' . $m[2]
                . '<a class="sop-anchor" href="#' . e($id) . '" aria-label="Link to this section">#</a></h' . $m[1] . '>';
        }, $html);
    }

    private function decorate(string $html): string
    {
        // Tables scroll sideways on a phone instead of stretching the page.
        $html = str_replace(['<table>', '</table>'], ['<div class="sop-table-wrap"><table class="sop-table">', '</table></div>'], $html);

        // Steps are numbered by the CSS counter "sopstep" (the default list
        // marker is hidden to draw the numbered rail). A list that continues
        // a sequence ("4. …" after a heading) must start its counter there.
        $html = (string) preg_replace_callback('/<ol start="(\d+)">/', static fn (array $m): string =>
            '<ol start="' . $m[1] . '" style="counter-reset: sopstep ' . ((int) $m[1] - 1) . '">', $html);

        // "- [ ] text" → an (unticked) task row, used for open decisions.
        $html = (string) preg_replace('/<li>\s*\[ \]\s*/', '<li class="sop-task"><span class="sop-task-box" aria-hidden="true"></span>', $html);
        $html = str_replace('<ul>' . "\n" . '<li class="sop-task">', '<ul class="sop-tasks">' . "\n" . '<li class="sop-task">', $html);

        return self::rewriteSopLinks($html);
    }

    /** sop:slug#anchor → the chapter's real URL. */
    private static function rewriteSopLinks(string $html): string
    {
        return (string) preg_replace_callback('/href="sop:([a-z0-9-]*)(#[a-z0-9-]+)?"/', static function (array $m): string {
            $url = base_url('sop' . ($m[1] !== '' ? '/' . $m[1] : ''));
            return 'href="' . e($url . ($m[2] ?? '')) . '"';
        }, $html);
    }

    /** URL-safe anchor from heading text. */
    public static function slug(string $text): string
    {
        $s = strtolower(trim($text));
        $s = (string) preg_replace('/&[a-z]+;/', '', $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: 'section';
    }
}
