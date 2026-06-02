<?php
declare(strict_types=1);

/**
 * FleetForge — Help Guide Markdown Renderer
 *
 * @file        lib/Help/HelpRenderer.php
 * @description Thin wrapper around erusev/parsedown for rendering docs/help/{slug}.md guides.
 *              Strips YAML-style front matter and returns metadata alongside the HTML body.
 *              Only reads files inside docs/help/ — never user-supplied paths.
 *
 * @session     S-HELP-SYSTEM-FOUNDATION
 */

namespace FleetForge\Help;

use Parsedown;

class HelpRenderer
{
    /**
     * Render a single help guide.
     *
     * Returns an array with:
     *   found       - bool: false when no .md file exists for the slug
     *   title       - string: first # heading or front-matter title
     *   description - string: front-matter description (used in index listing)
     *   html        - string: rendered HTML body
     */
    public static function render(string $slug): array
    {
        // Sanitise slug — only lowercase alphanumeric, hyphens, and underscores.
        // Prevents path traversal even before the realpath check below.
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug));

        if ($slug === '') {
            return self::notFound();
        }

        $path = FF_ROOT . '/docs/help/' . $slug . '.md';

        if (!is_file($path)) {
            return self::notFound();
        }

        $raw  = (string) file_get_contents($path);
        [$meta, $markdown] = self::parseFrontMatter($raw);

        $pd = new Parsedown();
        // Safe: we only render trusted repo content, not user-supplied input.
        $pd->setSafeMode(false);
        $html = $pd->text($markdown);

        $title = $meta['title'] ?? self::extractTitle($markdown);

        return [
            'found'       => true,
            'title'       => $title,
            'description' => $meta['description'] ?? '',
            'html'        => $html,
        ];
    }

    /**
     * List all available guides sorted alphabetically by title.
     * Skips files whose basename starts with '_' (templates, internal meta files).
     *
     * @return list<array{slug: string, title: string, description: string}>
     */
    public static function listGuides(): array
    {
        $dir    = FF_ROOT . '/docs/help/';
        $guides = [];

        foreach ((array) glob($dir . '*.md') as $file) {
            $basename = basename((string) $file, '.md');

            if (str_starts_with($basename, '_')) {
                continue;
            }

            $raw  = (string) file_get_contents((string) $file);
            [$meta, $markdown] = self::parseFrontMatter($raw);

            $guides[] = [
                'slug'        => $basename,
                'title'       => $meta['title'] ?? self::extractTitle($markdown),
                'description' => $meta['description'] ?? '',
            ];
        }

        usort($guides, fn(array $a, array $b) => strcmp((string) $a['title'], (string) $b['title']));

        return $guides;
    }

    /**
     * Parse YAML-style front matter delimited by `---\n...\n---\n`.
     * Returns [$meta, $body] where $meta is a key→value map.
     */
    private static function parseFrontMatter(string $raw): array
    {
        $meta = [];

        if (str_starts_with($raw, "---\n")) {
            $end = strpos($raw, "\n---", 3);
            if ($end !== false) {
                $block     = substr($raw, 4, $end - 4);
                $remaining = ltrim(substr($raw, $end + 4));

                foreach (explode("\n", $block) as $line) {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $meta[trim($k)] = trim($v);
                    }
                }

                return [$meta, $remaining];
            }
        }

        return [$meta, $raw];
    }

    /** Extract the first `# Heading` as the guide title. */
    private static function extractTitle(string $markdown): string
    {
        if (preg_match('/^#\s+(.+)/m', $markdown, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private static function notFound(): array
    {
        return ['found' => false, 'title' => '', 'description' => '', 'html' => ''];
    }
}
