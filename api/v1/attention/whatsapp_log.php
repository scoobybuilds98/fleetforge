<?php
declare(strict_types=1);

/**
 * api/v1/attention/whatsapp_log.php
 *
 * The WhatsApp delivery log (Settings → Notifications) (S-ATTENTION-WHATSAPP):
 * the latest 100 messages — who, what, status, and Meta's reason when one
 * failed. Numbers are masked. Super admin only.
 *
 * @method  GET
 * @auth    require_auth_api + super admin
 * @query   status  optional filter (queued|sent|delivered|read|failed|skipped)
 * @returns 200 { rows[], counts{status: n} (last 7 days) }
 *
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Attention\AttentionService;

require_method('GET');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only a super admin can see the WhatsApp log.', 403);
}

$status = (string) ($_GET['status'] ?? '');
$params = [];
$where  = '1=1';
if (in_array($status, ['queued', 'sending', 'sent', 'delivered', 'read', 'failed', 'skipped'], true)) {
    $where = 'd.status = ?';
    $params[] = $status;
}

$rows = db_select(
    "SELECT d.id, d.purpose, d.status, d.preview, d.error, d.to_phone, d.attempts,
            d.created_at, d.sent_at, u.name
       FROM notification_deliveries d
       LEFT JOIN users u ON u.id = d.user_id
      WHERE {$where}
      ORDER BY d.id DESC LIMIT 100",
    $params
);

$counts = [];
foreach (db_select(
    'SELECT status, COUNT(*) AS n FROM notification_deliveries WHERE created_at >= ? GROUP BY status',
    [ff_now_utc('-7 days')]
) as $c) {
    $counts[$c['status']] = (int) $c['n'];
}

json_success([
    'rows' => array_map(static fn(array $r) => [
        'id'       => (int) $r['id'],
        'who'      => (string) ($r['name'] ?? 'Someone'),
        'to'       => substr((string) $r['to_phone'], 0, 3) . '•••' . substr((string) $r['to_phone'], -3),
        'purpose'  => $r['purpose'],
        'status'   => $r['status'],
        'preview'  => $r['preview'],
        'error'    => $r['error'],
        'attempts' => (int) $r['attempts'],
        'when'     => AttentionService::localLabel((string) ($r['sent_at'] ?? $r['created_at'])),
    ], $rows),
    'counts' => (object) $counts,
]);
