<?php
declare(strict_types=1);

/**
 * GET|POST /api/v1/sop/checklist
 *
 * S-SOP-MODULE — the shared month-end checklist in the SOP.
 *
 * GET  ?key=month_end&period=YYYY-MM
 *      → the checklist for that month: stages, items, who ticked what and
 *        when, and the live checks of the books (SopSignals — counts and
 *        yes/no only, never amounts).
 * POST { key, period, item, checked: bool }
 *      → ticks / unticks one item, then returns the same payload as GET.
 *
 * @auth    Signed-in staff. Seeing it needs money visibility
 *          (SopChecklist::canView — can view payments); ticking needs
 *          invoice or journal-entry edit rights (SopChecklist::canTick).
 * @returns { key, period, period_label, prev, next, is_december, stages[], done, total, can_tick, checked_at }
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Sop\SopChecklist;

require_method('GET', 'POST');
require_auth_api();

if (!SopChecklist::canView()) {
    json_error('FORBIDDEN', 'Your role cannot see the month-end checklist.', 403);
}

$isPost = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in     = $isPost ? $_POST : $_GET;

// Only one checklist exists today; the key is explicit so a second one
// (e.g. year-end) can be added to the SOP without a new endpoint.
$key = (string) ($in['key'] ?? SopChecklist::MONTH_END);
if ($key !== SopChecklist::MONTH_END) {
    json_error('NOT_FOUND', 'No SOP checklist with that name.', 404);
}

$period = (string) ($in['period'] ?? SopChecklist::defaultPeriod());
if (!SopChecklist::isValidPeriod($period)) {
    json_error('INVALID_VALUE', 'period must be a month (YYYY-MM) no later than this month.', 422);
}

if ($isPost) {
    if (!SopChecklist::canTick()) {
        json_error('FORBIDDEN', 'Your role can view the checklist but not tick steps.', 403);
    }
    $item = (string) ($in['item'] ?? '');
    if ($item === '' || !array_key_exists('checked', $in)) {
        json_error('MISSING_REQUIRED', 'item and checked are required.', 422);
    }
    $checked = filter_var($in['checked'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($checked === null) {
        json_error('INVALID_VALUE', 'checked must be true or false.', 422);
    }
    try {
        SopChecklist::set($key, $period, $item, $checked, (int) current_user_id());
    } catch (\InvalidArgumentException $e) {
        json_error('INVALID_VALUE', $e->getMessage(), 422);
    }
}

json_success(SopChecklist::payload($key, $period));
