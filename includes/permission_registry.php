<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Permission Registry Helpers
//
// S-PERM-EXPAND — free functions for reading the per-module action
// vocabulary, action descriptions, and group definitions added in
// this session.
//
// Loaded by includes/auth.php (which is loaded by every authenticated
// page + every API endpoint via api/bootstrap.php), so these
// helpers are universally available without per-call require_once.
//
// All three loaders use request-scoped static caches: the config
// files are require()'d at most once per request even when called
// from multiple endpoints in a single bootstrap.
// ============================================================

/**
 * Return the cached config/permission_actions.php array.
 *
 * Shape:
 *   [
 *     'module_actions' => [
 *       '_default'        => ['view','create','edit','delete','export'],
 *       'journal_entries' => ['view','create','edit','delete','export','post','approve'],
 *       ...
 *     ],
 *     'action_descriptions' => ['view' => '...', 'post' => '...', ...],
 *   ]
 */
function _ff_load_permission_actions(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = require FF_ROOT . '/config/permission_actions.php';
    }
    return $cache;
}

/**
 * Return the cached config/permission_groups.php array.
 *
 * Shape: ['accounting' => ['label' => '...', 'description' => '...', 'modules' => [...]], ...]
 */
function _ff_load_permission_groups(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = require FF_ROOT . '/config/permission_groups.php';
    }
    return $cache;
}

/**
 * Return the ordered list of valid actions for a module.
 *
 * Falls back to the '_default' set (view/create/edit/delete/export)
 * when the module has no explicit action declaration. Falls back
 * once more to a hardcoded 5-action set if the config file is
 * missing or '_default' is unset — defensive belt-and-suspenders so
 * the function never returns an empty array (callers use it for
 * whitelist validation).
 *
 * @param  string $module  Module slug (e.g. 'journal_entries')
 * @return string[]        Ordered action names
 */
function get_actions_for_module(string $module): array
{
    $config       = _ff_load_permission_actions();
    $moduleAction = $config['module_actions'] ?? [];

    return $moduleAction[$module]
        ?? $moduleAction['_default']
        ?? ['view', 'create', 'edit', 'delete', 'export'];
}

/**
 * Return the human-readable description for an action.
 *
 * Falls back to a ucfirst() of the action verb when not declared.
 *
 * @param  string $action  Action verb (e.g. 'post')
 * @return string          Tooltip text
 */
function get_action_description(string $action): string
{
    $config = _ff_load_permission_actions();
    return $config['action_descriptions'][$action] ?? ucfirst($action);
}

/**
 * Return the permission group definitions for the admin UI.
 *
 * @return array<string, array{label:string,description:string,modules:string[]}>
 */
function get_permission_groups(): array
{
    return _ff_load_permission_groups();
}
