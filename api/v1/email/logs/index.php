<?php
declare(strict_types=1);

/**
 * api/v1/email/logs/index.php
 *
 * GET → list email logs (filterable, paginated).
 *
 * Used by the customer Email History tab and the global email
 * activity feed. Filters: customer_id, entity_type, entity_id,
 * status, q (search subject + to_email), date_from/date_to.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$where  = ['1 = 1'];
$params = [];

if (!empty($_GET['customer_id'])) {
    $where[]  = 'el.customer_id = ?';
    $params[] = (int)$_GET['customer_id'];
}
if (!empty($_GET['entity_type'])) {
    $where[]  = 'el.entity_type = ?';
    $params[] = clean_string($_GET['entity_type'], 50);
}
if (!empty($_GET['entity_id'])) {
    $where[]  = 'el.entity_id = ?';
    $params[] = (int)$_GET['entity_id'];
}
if (!empty($_GET['status'])) {
    $where[]  = 'el.status = ?';
    $params[] = clean_string($_GET['status'], 20);
}
if (!empty($_GET['q'])) {
    $q = '%' . clean_string($_GET['q'], 200) . '%';
    $where[]  = '(el.subject LIKE ? OR el.to_email LIKE ?)';
    $params[] = $q;
    $params[] = $q;
}
if (!empty($_GET['date_from'])) {
    $where[]  = 'el.created_at >= ?';
    $params[] = clean_string($_GET['date_from']) . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $where[]  = 'el.created_at <= ?';
    $params[] = clean_string($_GET['date_to']) . ' 23:59:59';
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$whereSQL = implode(' AND ', $where);

$total = db_count("SELECT COUNT(*) FROM email_logs el WHERE $whereSQL", $params);

$rows = db_select(
    "SELECT el.id, el.to_email, el.to_name, el.subject, el.status,
            el.error_message, el.sent_at, el.created_at,
            el.customer_id, el.entity_type, el.entity_id,
            el.template_id, el.sent_by,
            u.name AS sent_by_name,
            t.name AS template_name,
            (SELECT COUNT(*) FROM email_attachments ea WHERE ea.email_log_id = el.id) AS attachment_count
     FROM email_logs el
     LEFT JOIN users u ON u.id = el.sent_by
     LEFT JOIN email_templates t ON t.id = el.template_id
     WHERE $whereSQL
     ORDER BY el.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

foreach ($rows as &$r) {
    $r['id']               = (int)$r['id'];
    $r['customer_id']      = $r['customer_id'] ? (int)$r['customer_id'] : null;
    $r['entity_id']        = $r['entity_id'] ? (int)$r['entity_id'] : null;
    $r['template_id']      = $r['template_id'] ? (int)$r['template_id'] : null;
    $r['sent_by']          = $r['sent_by'] ? (int)$r['sent_by'] : null;
    $r['attachment_count'] = (int)$r['attachment_count'];
    $r['status_class']     = match((string)$r['status']) {
        'sent'    => 'success',
        'failed'  => 'danger',
        'pending' => 'warning',
        default   => 'neutral',
    };
}
unset($r);

json_paginated($rows, $total, $page, $perPage);
