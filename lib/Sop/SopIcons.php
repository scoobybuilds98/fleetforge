<?php
declare(strict_types=1);

/**
 * FleetForge — SOP icon helper (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopIcons.php
 * @description Inline Heroicons (outline) for the SOP pages, read from the
 *              same public/assets/icons/*.svg files the sidebar uses.
 *
 *              WHY not the sidebar's heroicon(): that function is defined
 *              inside includes/sidebar.php, so it only exists on full admin
 *              pages. The SOP renderer also runs from the API, the print view
 *              and CLI smokes, where the sidebar is never included.
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

final class SopIcons
{
    /** @var array<string,string> per-request cache: name → raw svg */
    private static array $cache = [];

    /**
     * Inline SVG for a bundled icon; an empty span when the icon file is
     * missing (the smoke asserts every icon the SOP names exists).
     */
    public static function svg(string $name, string $class = 'sop-ic'): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        if (!array_key_exists($name, self::$cache)) {
            $file = FF_ROOT . '/public/assets/icons/' . $name . '.svg';
            self::$cache[$name] = is_file($file) ? (string) file_get_contents($file) : '';
        }
        $svg = self::$cache[$name];
        if ($svg === '') {
            return '<span class="' . e($class) . '" aria-hidden="true"></span>';
        }
        return (string) preg_replace('/<svg\b/', '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"', $svg, 1);
    }

    /** True when the icon file exists (used by the smoke). */
    public static function exists(string $name): bool
    {
        return is_file(FF_ROOT . '/public/assets/icons/' . preg_replace('/[^a-z0-9-]/', '', strtolower($name)) . '.svg');
    }
}
