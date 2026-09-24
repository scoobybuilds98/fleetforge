<?php
declare(strict_types=1);

/**
 * api/v1/attention/index.php
 *
 * The current user's Needs attention items (S-ATTENTION-INBOX). Powers the
 * bell panel and the full Notifications page.
 *
 * Visibility is by role (Settings → Notifications decides which roles see
 * which kind; super admins see all) plus anyone named on the item. Money
 * facts are dropped at serve time for users without payments:view.
 *
 * @method  GET
 * @auth    require_auth_api
 * @query   view      open (default) | snoozed | closed (last 60 days)
 *          owner     all (default) | mine | free (nobody on it)
 *          kind      kind key (optional)
 *          priority  urgent | todo (optional)
 *          q         title search (optional)
 *          limit     1-200 (default 50), offset
 * @returns 200 { items[], total, counts{total,urgent,mine,updates_unread}, kinds[{key,label}] }
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Attention\AttentionService;
use FleetForge\Attention\KindRegistry;

require_method('GET');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}
$role     = (string) (current_user()['role_slug'] ?? '');
$canMoney = can_view_financials();

$list = AttentionService::listFor($userId, $role, [
    'view'     => (string) ($_GET['view'] ?? 'open'),
    'owner'    => (string) ($_GET['owner'] ?? 'all'),
    'kind'     => (string) ($_GET['kind'] ?? ''),
    'priority' => (string) ($_GET['priority'] ?? ''),
    'q'        => mb_substr((string) ($_GET['q'] ?? ''), 0, 100),
    'limit'    => clean_int($_GET['limit'] ?? null) ?? 50,
    'offset'   => clean_int($_GET['offset'] ?? null) ?? 0,
]);

// Kinds this user can see — the filter chips on the full page.
$kinds = [];
foreach (KindRegistry::kindsForRole($role) as $key) {
    $k = KindRegistry::get($key);
    if ($k) {
        $kinds[] = ['key' => $key, 'label' => $k->label(), 'area' => $k->area()];
    }
}

json_success([
    'items'  => array_map(static fn(array $r) => AttentionService::present($r, $userId, $canMoney), $list['items']),
    'total'  => $list['total'],
    'counts' => AttentionService::badge($userId, $role),
    'kinds'  => $kinds,
]);
