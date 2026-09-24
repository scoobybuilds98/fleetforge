<?php
declare(strict_types=1);

/**
 * api/v1/portal/invoices/payable.php
 *
 * Customer portal — the invoices the Pay drawer works with (S-PORTAL-REDESIGN).
 *
 *   GET                 → every open invoice with a balance (oldest due first)
 *   GET ?ids=4,7,9      → exactly those invoices, whatever their status — the
 *                         drawer polls this while a customer pays in another
 *                         tab, and flips a row to "Paid" once `payable` goes false
 *
 * Each row carries `payable` (open + balance > 0) and `online_ready` (the
 * invoice is already in QuickBooks, so its secure pay page exists). `online`
 * says whether online payment is switched on at all.
 *
 * Trap 8: scoped to portal_customer_id(); ids from another customer simply
 * don't come back. Visibility = pt_invoice_visible_sql() (no voids/drafts).
 *
 * @method  GET
 * @auth    portal session (401 JSON when it has ended)
 * @returns { invoices: [...pt_invoice_json], online: bool }
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

require_method('GET');
require_portal_auth_api();

$cid = portal_customer_id();
$ids = pt_parse_ids($_GET['ids'] ?? '', 100);

$sql    = pt_invoice_select_sql() . " WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i');
$params = [$cid];

if ($ids) {
    $sql   .= ' AND i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = array_merge($params, $ids);
} else {
    $sql .= " AND i.status IN (" . pt_open_statuses_sql() . ") AND i.balance_due > 0";
}
$sql .= ' ORDER BY i.due_date ASC, i.id ASC LIMIT 100';

$rows = array_map('pt_invoice_json', db_select($sql, $params));

json_success([
    'invoices' => $rows,
    'online'   => pt_online_pay_enabled(),
]);
