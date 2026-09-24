<?php
declare(strict_types=1);

/**
 * config/backgrounds.php
 *
 * The background palettes a super admin can pick in Settings → Design →
 * Background (setting `brand.background`). One source of truth for three
 * things that must agree:
 *   1. ff_background()          (includes/functions.php) — validates the
 *      stored key and falls back to FF_BACKGROUND_DEFAULT;
 *   2. public/assets/css/backgrounds.css — one html[data-bg="<key>"] token
 *      block per palette, plus a [data-theme="light"] companion;
 *   3. the picker tiles on the Design tab (label, blurb, preview swatches)
 *      and the allowlist in api/v1/settings/brand.php.
 *
 * A palette only re-colours surfaces, text greys, borders and the always-dark
 * sidebar. Brand colour, status colours and every component stay the same.
 * 'espresso' is the original warm Atelier palette (S-LUX-1): it needs no
 * token block because app.css already defines it.
 *
 * `swatch` feeds the preview tile only: [page, card, card-2, text] for the
 * dark theme and the light theme.
 *
 * @session S-BACKGROUNDS
 */

if (!defined('FF_BACKGROUND_DEFAULT')) {
    // The steel-blue brand (#2596be) sits best on a cool ink background;
    // the warm espresso browns fought it.
    define('FF_BACKGROUND_DEFAULT', 'midnight');
}

return [
    'midnight' => [
        'label' => 'Midnight',
        'blurb' => 'Cool blue-black ink. Calm, and made for a blue brand.',
        'dark'  => ['#070A0F', '#10151D', '#1C2430', '#EBF0F6'],
        'light' => ['#F3F6F9', '#FFFFFF', '#EDF1F6', '#111827'],
    ],
    'graphite' => [
        'label' => 'Graphite',
        'blurb' => 'True neutral charcoal. Lets every colour speak for itself.',
        'dark'  => ['#09090B', '#141417', '#1F1F24', '#F2F2F4'],
        'light' => ['#F4F4F5', '#FFFFFF', '#F0F0F2', '#18181B'],
    ],
    'navy' => [
        'label' => 'Navy',
        'blurb' => 'Deep saturated blue. The boldest of the set.',
        'dark'  => ['#050A18', '#0C152A', '#16243F', '#E8EEFA'],
        'light' => ['#F1F5FC', '#FFFFFF', '#EAF0FA', '#0D1B33'],
    ],
    'obsidian' => [
        'label' => 'Obsidian',
        'blurb' => 'Pure black with crisp cards. Highest contrast.',
        'dark'  => ['#000000', '#0E0E0F', '#1A1A1C', '#F5F5F5'],
        'light' => ['#FAFAFA', '#FFFFFF', '#F4F4F4', '#0A0A0A'],
    ],
    'pine' => [
        'label' => 'Pine',
        'blurb' => 'Dark green-teal. Quiet, natural, easy on the eyes.',
        'dark'  => ['#050D0B', '#0D1916', '#172622', '#E9F2EF'],
        'light' => ['#F2F6F4', '#FFFFFF', '#EAF1EE', '#0F1D19'],
    ],
    'espresso' => [
        'label' => 'Espresso',
        'blurb' => 'The original warm brown-black and paper.',
        'dark'  => ['#060403', '#1E1913', '#2A241B', '#F4F1EA'],
        'light' => ['#F7F5F1', '#FFFFFF', '#F1EEE8', '#1A1815'],
    ],
];
