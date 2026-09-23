<?php
declare(strict_types=1);

/**
 * FleetForge — module hero banner (S-MODULE-CHROME)
 *
 * @file        lib/Ui/ModuleHero.php
 * @description The SOP hub's hero, generalised for every module: blueprint
 *              grid + accent glows (public/assets/css/module-chrome.css), an
 *              eyebrow with the module icon, the page title, a one-line
 *              purpose, optional fact chips, the page's own action buttons,
 *              and an animated illustration (lib/Ui/art/{name}.svg).
 *
 *              Two shapes:
 *                list   — a module's index page (title + purpose + art)
 *                entity — one record (avatar + name + facts + a large faint
 *                         watermark, e.g. the unit number)
 *
 *              The page keeps full control of its action buttons: it passes
 *              them in as ready HTML (captured with ob_start()), so Alpine
 *              bindings, permission checks and help buttons are untouched.
 *
 *              Options (all optional except title):
 *                title      string   page title (escaped)
 *                title_html string   raw title HTML instead (e.g. with a badge)
 *                eyebrow    string   small label above the title
 *                icon       string   heroicon name for the eyebrow / avatar
 *                accent     string   primary|info|success|warning|danger|purple
 *                subtitle   string   one line under the title (raw HTML allowed —
 *                                    callers escape user data)
 *                crumbs     list<array{0:string,1:?string}>  [label, url|null]
 *                facts      list<string> raw HTML chips (callers escape)
 *                actions    string   raw HTML (the page's buttons)
 *                art        string   illustration name (list shape)
 *                avatar     string   initials / short text (entity shape);
 *                                    empty → the icon
 *                mark       string   watermark text (entity shape)
 *                entity     bool     entity shape
 *                class      string   extra classes on the section (e.g.
 *                                    'ff-print-hide' where a page prints its
 *                                    own letterhead instead)
 *                main_html  string   the page's OWN title block, placed as-is
 *                                    under the crumbs/eyebrow instead of
 *                                    title/subtitle/facts (detail pages with
 *                                    live badges keep their markup untouched)
 *
 * @session     S-MODULE-CHROME
 */

namespace FleetForge\Ui;

use FleetForge\Sop\SopIcons;

final class ModuleHero
{
    public const ACCENTS = ['primary', 'info', 'success', 'warning', 'danger', 'purple'];

    /** @param array<string,mixed> $o */
    public static function render(array $o): string
    {
        $accent = in_array($o['accent'] ?? '', self::ACCENTS, true) ? $o['accent'] : 'primary';
        $entity = !empty($o['entity']);
        $icon   = (string) ($o['icon'] ?? '');
        $e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $extra = trim((string) preg_replace('/[^a-z0-9_\- ]/i', '', (string) ($o['class'] ?? '')));
        $html  = '<section class="ff-hero ff-acc--' . $accent . ($entity ? ' ff-hero--entity' : '') . ($extra !== '' ? ' ' . $extra : '') . '">';

        // ── main column ──────────────────────────────────────────
        $main = '';
        if (!empty($o['crumbs'])) {
            $parts = [];
            foreach ((array) $o['crumbs'] as $c) {
                $label = $e($c[0] ?? '');
                $parts[] = !empty($c[1]) ? '<a href="' . $e($c[1]) . '">' . $label . '</a>' : '<span>' . $label . '</span>';
            }
            $main .= '<nav class="ff-hero-crumbs" aria-label="Breadcrumb">' . implode('<span class="sep">/</span>', $parts) . '</nav>';
        }
        if (!empty($o['eyebrow'])) {
            $main .= '<div class="ff-hero-eyebrow">'
                . ($icon !== '' && !$entity ? '<span class="ff-hero-ic">' . SopIcons::svg($icon) . '</span>' : '')
                . $e($o['eyebrow']) . '</div>';
        }
        if (isset($o['main_html'])) {
            $main .= '<div class="ff-hero-own">' . $o['main_html'] . '</div>';
        } else {
            $title = isset($o['title_html']) ? (string) $o['title_html'] : $e($o['title'] ?? '');
            $main .= '<h1 class="ff-hero-title">' . $title . '</h1>';
            if (!empty($o['subtitle'])) {
                $main .= '<p class="ff-hero-sub">' . $o['subtitle'] . '</p>';
            }
        }
        if (!empty($o['facts'])) {
            $main .= '<div class="ff-hero-facts">';
            foreach ((array) $o['facts'] as $f) {
                $main .= '<span class="ff-hero-fact">' . $f . '</span>';
            }
            $main .= '</div>';
        }

        $actions = trim((string) ($o['actions'] ?? ''));

        if ($entity) {
            $avatar = trim((string) ($o['avatar'] ?? ''));
            $html .= '<div class="ff-hero-id">'
                . '<span class="ff-hero-avatar" aria-hidden="true">' . ($avatar !== '' ? $e($avatar) : SopIcons::svg($icon !== '' ? $icon : 'document-text')) . '</span>'
                . '<div class="ff-hero-main">' . $main . '</div></div>';
            if ($actions !== '') {
                $html .= '<div class="ff-hero-actions">' . $actions . '</div>';
            }
            if (!empty($o['mark'])) {
                $html .= '<div class="ff-hero-mark" aria-hidden="true">' . $e($o['mark']) . '</div>';
            }
        } else {
            if ($actions !== '') {
                $main .= '<div class="ff-hero-actions">' . $actions . '</div>';
            }
            $html .= '<div class="ff-hero-main">' . $main . '</div>';
            $art = self::art((string) ($o['art'] ?? ''));
            if ($art !== '') {
                $html .= '<div class="ff-hero-art" aria-hidden="true">' . $art . '</div>';
            }
        }

        return $html . '</section>';
    }

    /** The illustration's SVG markup, or '' when there is none. */
    public static function art(string $name): string
    {
        $name = (string) preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        $file = __DIR__ . '/art/' . $name . '.svg';
        return $name !== '' && is_file($file) ? (string) file_get_contents($file) : '';
    }

    /** Two-letter initials for an avatar ("Rolls Right Industries" → "RR"). */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\-&.,]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, static fn ($w) => !in_array(strtolower($w), ['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'and'], true)));
        if ($words === []) {
            return '?';
        }
        $a = mb_substr($words[0], 0, 1);
        $b = isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1);
        return mb_strtoupper($a . $b);
    }
}
