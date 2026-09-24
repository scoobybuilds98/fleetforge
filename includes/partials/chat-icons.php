<?php
/**
 * includes/partials/chat-icons.php
 *
 * S-CHAT-REBUILD — icon helpers for the messaging UI. Outputs nothing; safe to
 * require_once from both the staff page (includes/header.php context, where
 * heroicon() exists) and the portal page (where it doesn't), which is why the
 * SVGs are read straight from public/assets/icons.
 *
 * @session S-CHAT-REBUILD
 */

if (!function_exists('ff_chat_icon')) {
    /** Inline a Heroicons SVG from public/assets/icons (works on staff + portal pages). */
    function ff_chat_icon(string $name): string
    {
        $file = FF_ROOT . '/public/assets/icons/' . preg_replace('/[^a-z0-9-]/', '', $name) . '.svg';
        $svg  = is_file($file) ? (string) file_get_contents($file) : '';
        return preg_replace('/<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1) ?? '';
    }

    /** type → icon SVG, for the component config. */
    function ff_chat_record_icons(): array
    {
        return array_map('ff_chat_icon', [
            'lease'        => 'document-text',
            'invoice'      => 'banknotes',
            'payment'      => 'credit-card',
            'equipment'    => 'truck',
            'customer'     => 'building-office',
            'reservation'  => 'calendar-days',
            'work_order'   => 'wrench-screwdriver',
            'damage_claim' => 'exclamation-triangle',
        ]);
    }
}
