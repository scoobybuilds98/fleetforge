<?php
declare(strict_types=1);

/**
 * FleetForge — Help Guide Fragment Endpoint (S-HELP-DRAWER-TUTORIAL-REWORK)
 *
 * @file        app/admin/help/fragment.php
 * @description Returns rendered guide HTML as a JSON fragment for the
 *              right-side help drawer. No layout — just the content body.
 *              Only accepts XHR requests (X-Requested-With header required).
 *
 * @method      GET
 * @param       slug (string) — guide slug, e.g. ?slug=customers
 * @auth        Session required; all authenticated roles can read help guides.
 * @returns     200 { found: bool, title: string, html: string }
 *
 * @depends     config/app.php, includes/auth.php, lib/Help/HelpRenderer.php
 * @session     S-HELP-DRAWER-TUTORIAL-REWORK
 */

// dirname(__DIR__, 3): app/admin/help/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// Restrict to XHR — the drawer fetches with X-Requested-With set.
// Non-XHR access (direct URL visit) redirects to the full guide page.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['slug'] ?? ''));
    if ($slug !== '') {
        header('Location: ' . base_url('help/' . $slug));
    } else {
        header('Location: ' . base_url('help'));
    }
    exit;
}

$slug  = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['slug'] ?? ''));
$guide = \FleetForge\Help\HelpRenderer::render($slug);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'found' => $guide['found'],
    'title' => $guide['title'],
    'html'  => $guide['html'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
