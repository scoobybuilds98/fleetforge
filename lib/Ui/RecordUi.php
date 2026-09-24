<?php
declare(strict_types=1);

/**
 * FleetForge — record page building blocks (S-RECORD-REDESIGN)
 *
 * @file        lib/Ui/RecordUi.php
 * @description Server-rendered pieces of the shared record-page layout
 *              (public/assets/css/records.css): the right-rail cards and the
 *              header's "More" menu. Every record page (customer, lease,
 *              invoice, unit …) composes its rail from these, so the rails
 *              look and behave the same everywhere.
 *
 *              Escaping contract: parameters named *Html / body are RAW HTML
 *              (callers escape user data); plain-string parameters (titles,
 *              labels, URLs) are escaped here.
 *
 *              card()    a rail card: icon + title (+ link) + body
 *              kv()      label/value rows
 *              meter()   a labelled progress bar (0–100 %)
 *              alerts()  "needs attention" items, or an all-clear line
 *              links()   a list of link/button rows (related records, quick
 *                        actions)
 *              entity()  a related record as a mini profile (avatar + name)
 *              big()     a card's headline number
 *              more()    the header's overflow menu (Alpine dropdown) for
 *                        secondary actions — the page passes its own buttons
 *
 * @session     S-RECORD-REDESIGN
 */

namespace FleetForge\Ui;

use FleetForge\Sop\SopIcons;

final class RecordUi
{
    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A rail card.
     *
     * @param array{icon?:string, link?:array{0:string,1:string}, class?:string, foot?:string, id?:string} $o
     *        foot = raw HTML footer line
     */
    public static function card(string $title, string $body, array $o = []): string
    {
        $cls  = trim('rec-card ' . preg_replace('/[^a-z0-9_\- ]/i', '', (string) ($o['class'] ?? '')));
        $icon = (string) ($o['icon'] ?? '');
        $id   = !empty($o['id']) ? ' id="' . self::e($o['id']) . '"' : '';
        $head = '<header class="rec-card-head">'
            . ($icon !== '' ? '<span class="rec-card-ic" aria-hidden="true">' . SopIcons::svg($icon) . '</span>' : '')
            . '<h3 class="rec-card-title">' . self::e($title) . '</h3>'
            . (!empty($o['link']) ? '<a class="rec-card-link" href="' . self::e($o['link'][1]) . '">' . self::e($o['link'][0]) . '</a>' : '')
            . '</header>';
        $foot = !empty($o['foot']) ? '<div class="rec-card-foot">' . $o['foot'] . '</div>' : '';
        return '<section class="' . $cls . '"' . $id . '>' . $head . '<div class="rec-card-body">' . $body . '</div>' . $foot . '</section>';
    }

    /**
     * Label/value rows. A null / '' value is skipped (keeps rails free of
     * "—" noise); pass '—' explicitly to show a dash.
     *
     * @param list<array{0:string,1:?string,2?:string}> $rows [label, valueHtml, ddClass]
     */
    public static function kv(array $rows): string
    {
        $out = '';
        foreach ($rows as $r) {
            $val = $r[1] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            $cls  = !empty($r[2]) ? ' class="' . self::e($r[2]) . '"' : '';
            $out .= '<div><dt>' . self::e($r[0]) . '</dt><dd' . $cls . '>' . $val . '</dd></div>';
        }
        return $out === '' ? '' : '<dl class="rec-kv">' . $out . '</dl>';
    }

    /**
     * A labelled progress bar.
     *
     * @param string $tone ok | warn | danger | info
     */
    public static function meter(string $label, string $valueHtml, float $pct, string $tone = 'ok', string $subHtml = ''): string
    {
        $pct  = max(0.0, min(100.0, $pct));
        $tone = in_array($tone, ['ok', 'warn', 'danger', 'info'], true) ? $tone : 'ok';
        return '<div class="rec-meter rec-meter--' . $tone . '" role="img" aria-label="' . self::e($label) . ': ' . self::e(strip_tags($valueHtml)) . '">'
            . '<div class="rec-meter-row"><span>' . self::e($label) . '</span><b>' . $valueHtml . '</b></div>'
            . '<div class="rec-meter-track"><div class="rec-meter-fill" style="width:' . round($pct, 1) . '%"></div></div>'
            . ($subHtml !== '' ? '<div class="rec-meter-sub">' . $subHtml . '</div>' : '')
            . '</div>';
    }

    /**
     * "Needs attention" items. Empty list → the all-clear line.
     *
     * @param list<array{0:string,1:string}> $items [tone danger|warning|info|success, html]
     */
    public static function alerts(array $items, string $allClear = 'Nothing needs attention.'): string
    {
        if ($items === []) {
            return '<p class="rec-alerts-ok">' . self::e($allClear) . '</p>';
        }
        $out = '<ul class="rec-alerts">';
        foreach ($items as [$tone, $html]) {
            $tone = in_array($tone, ['danger', 'warning', 'info', 'success'], true) ? $tone : 'info';
            $out .= '<li class="is-' . $tone . '">' . '<span>' . $html . '</span></li>';
        }
        return $out . '</ul>';
    }

    /**
     * Link / button rows.
     *
     * @param list<array{0:string,1:string,2?:string,3?:string}> $items
     *        [label, url, icon, smallText]; a url starting with "js:" renders a
     *        <button onclick="…">, one starting with "x:" a <button @click="…">
     *        (Alpine, for a rail inside the page's x-data) — the rest is the
     *        trusted, page-authored handler
     */
    public static function links(array $items, bool $grid = false): string
    {
        $out = '<div class="rec-links' . ($grid ? ' rec-links--grid' : '') . '">';
        foreach ($items as $it) {
            $label = self::e($it[0]);
            $icon  = !empty($it[2]) ? SopIcons::svg((string) $it[2]) : '';
            $small = !empty($it[3]) ? '<small>' . self::e($it[3]) . '</small>' : '';
            $url   = (string) $it[1];
            if (str_starts_with($url, 'js:')) {
                $out .= '<button type="button" onclick="' . self::e(substr($url, 3)) . '">' . $icon . '<span>' . $label . '</span>' . $small . '</button>';
            } elseif (str_starts_with($url, 'x:')) {
                $out .= '<button type="button" @click="' . self::e(substr($url, 2)) . '">' . $icon . '<span>' . $label . '</span>' . $small . '</button>';
            } else {
                $out .= '<a href="' . self::e($url) . '">' . $icon . '<span>' . $label . '</span>' . $small . '</a>';
            }
        }
        return $out . '</div>';
    }

    /** A related record as a mini profile: avatar (initials or icon) + name + sub-line. */
    public static function entity(string $name, string $url, string $subHtml = '', string $avatar = '', string $icon = ''): string
    {
        $av  = $avatar !== '' ? self::e($avatar) : SopIcons::svg($icon !== '' ? $icon : 'document-text');
        $tag = $url !== '' ? 'a href="' . self::e($url) . '"' : 'div';
        $end = $url !== '' ? 'a' : 'div';
        return '<' . $tag . ' class="rec-entity"><span class="rec-entity-av" aria-hidden="true">' . $av . '</span>'
            . '<span><span class="rec-entity-name">' . self::e($name) . '</span>'
            . ($subHtml !== '' ? '<br><span class="rec-entity-sub">' . $subHtml . '</span>' : '')
            . '</span></' . $end . '>';
    }

    /** A card's headline number with an optional caption. */
    public static function big(string $valueHtml, string $captionHtml = ''): string
    {
        return '<div class="rec-big"><b>' . $valueHtml . '</b>' . ($captionHtml !== '' ? '<span>' . $captionHtml . '</span>' : '') . '</div>';
    }

    /**
     * The header's "More" menu. $itemsHtml is the page's own buttons/links
     * (Alpine bindings, permission checks and onclick handlers untouched);
     * records.css turns each into a plain menu row. Returns '' when there is
     * nothing to put in it.
     */
    public static function more(string $itemsHtml, string $label = 'More'): string
    {
        if (trim(strip_tags($itemsHtml, '<button><a><form><input>')) === '') {
            return '';
        }
        return '<div class="rec-more" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">'
            . '<button type="button" class="btn btn-secondary btn-sm" @click="open = !open" :aria-expanded="open ? \'true\' : \'false\'" aria-haspopup="menu">'
            . self::e($label) . ' <span aria-hidden="true" style="margin-left:2px;">▾</span></button>'
            . '<div class="rec-more-menu" role="menu" x-show="open" x-cloak x-transition.opacity.duration.100ms @click="if ($event.target.closest(\'a,button\')) open = false">'
            . $itemsHtml
            . '</div></div>';
    }
}
