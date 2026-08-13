<?php
declare(strict_types=1);

/**
 * api/v1/invoices/batch_runs/index.php
 *
 * S-BATCH-APPROVAL — list batch runs (newest first), optionally by status.
 * Returns headline figures only; the full snapshot is deliberately NOT
 * included (it can be hundreds of KB per run) — fetch a single run's page
 * for the detail.
 *
 * @method  GET
 * @query   status? (pending|approved|rejected|generated|cancelled), limit? (default 20, max 100)
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { runs: [...] }
 *
 * @session S-BATCH-APPROVAL
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$sql    = "SELECT r.id, r.reference, r.period_start, r.period_end, r.status,
                  r.invoice_count, r.skipped_count, r.total_by_currency, r.note,
                  r.submitted_at, r.decided_at, r.generated_at,
                  su.name AS submitted_by_name,
                  du.name AS decided_by_name,
                  gu.name AS generated_by_name
             FROM invoice_batch_runs r
             LEFT JOIN users su ON su.id = r.submitted_by
             LEFT JOIN users du ON du.id = r.decided_by
             LEFT JOIN users gu ON gu.id = r.generated_by
            WHERE r.deleted_at IS NULL";
$params = [];

$status = clean_string($_GET['status'] ?? null, 20);
if ($status && in_array($status, ['pending', 'approved', 'rejected', 'generated', 'cancelled'], true)) {
    $sql .= " AND r.status = ?";
    $params[] = $status;
}

$limit = clean_int($_GET['limit'] ?? null) ?: 20;
$limit = max(1, min(100, $limit));
$sql  .= " ORDER BY r.id DESC LIMIT {$limit}";

$rows = db_select($sql, $params);

$runs = array_map(static fn ($r) => [
    'id'                => (int) $r['id'],
    'reference'         => $r['reference'],
    'period_start'      => $r['period_start'],
    'period_end'        => $r['period_end'],
    'status'            => $r['status'],
    'invoice_count'     => (int) $r['invoice_count'],
    'skipped_count'     => (int) $r['skipped_count'],
    'total_by_currency' => json_decode((string) $r['total_by_currency'], true) ?: [],
    'note'              => $r['note'],
    'submitted_at'      => $r['submitted_at'],
    'submitted_by_name' => $r['submitted_by_name'],
    'decided_at'        => $r['decided_at'],
    'decided_by_name'   => $r['decided_by_name'],
    'generated_at'      => $r['generated_at'],
    'generated_by_name' => $r['generated_by_name'],
], $rows);

json_success(['runs' => $runs]);
