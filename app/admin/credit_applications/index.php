<?php
declare(strict_types=1);

/**
 * app/admin/credit_applications/index.php
 *
 * Credit Applications — comprehensive module index.
 *
 * Shows KPI tiles (sent/opened/submitted/reviewed/total), a filterable and
 * sortable table of all applications across all customers, and quick-action
 * links to the individual application view and the customer profile.
 *
 * Filters applied via GET params so they survive page reload and can be
 * bookmarked / shared:
 *   ?status=submitted   — filter by lifecycle status
 *   ?outcome=approved   — filter by review outcome
 *   ?q=Fraser           — search customer company_name or applicant name
 *   ?date_from=2026-01-01 / ?date_to=2026-12-31
 *   ?sort=submitted_at&dir=ASC
 *   ?page=2
 *
 * Permission: customers:view (credit applications are a customer sub-resource
 * per D-CCA-PERM).
 *
 * Trap 7: token_hash / signature_path / form_data / rendered_html / submitted_ip
 * never selected, never rendered.
 *
 * @session S-CCA-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

// ── KPI tiles (server-rendered counts) ───────────────────────────────────────
$kpis = [
    'awaiting_review' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status = 'submitted' AND deleted_at IS NULL"
    ),
    'submitted_total' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status IN ('submitted','reviewed') AND deleted_at IS NULL"
    ),
    'active' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status IN ('sent','opened') AND deleted_at IS NULL"
    ),
    'reviewed' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications
          WHERE status = 'reviewed' AND deleted_at IS NULL"
    ),
    'total' => db_count(
        "SELECT COUNT(*) FROM customer_credit_applications WHERE deleted_at IS NULL"
    ),
];

// ── Filters ───────────────────────────────────────────────────────────────────
$filterStatus    = clean_string($_GET['status']    ?? '');
$filterOutcome   = clean_string($_GET['outcome']   ?? '');
$filterQ         = clean_string($_GET['q']         ?? '');
$filterDateFrom  = clean_string($_GET['date_from'] ?? '');
$filterDateTo    = clean_string($_GET['date_to']   ?? '');

// Validate sort column against a whitelist (SQL injection guard)
$sortAllowed = ['sent_at','submitted_at','reviewed_at','created_at','company_name','status'];
$sort = in_array(clean_string($_GET['sort'] ?? ''), $sortAllowed, true)
    ? clean_string($_GET['sort'])
    : 'created_at';
$dir  = (clean_string($_GET['dir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = ['ca.deleted_at IS NULL'];
$params = [];

if ($filterStatus !== '') {
    $where[]  = 'ca.status = ?';
    $params[] = $filterStatus;
}
if ($filterOutcome !== '') {
    $where[]  = 'ca.review_outcome = ?';
    $params[] = $filterOutcome;
}
if ($filterQ !== '') {
    $where[]  = '(c.company_name LIKE ? OR ca.print_name_first LIKE ? OR ca.print_name_last LIKE ?)';
    $like     = '%' . $filterQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filterDateFrom !== '') {
    $where[]  = 'DATE(ca.created_at) >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[]  = 'DATE(ca.created_at) <= ?';
    $params[] = $filterDateTo;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ── Counts for pagination ─────────────────────────────────────────────────────
$totalRows = db_count(
    "SELECT COUNT(*)
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
     $whereSql",
    $params
);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Fetch rows ────────────────────────────────────────────────────────────────
// Trap 7: token_hash, signature_path, form_data, rendered_html, submitted_ip excluded.
$sortCol = $sort === 'company_name' ? 'c.company_name' : 'ca.' . $sort;
$rows = db_select(
    "SELECT ca.id, ca.status, ca.review_outcome,
            ca.sent_at, ca.opened_at, ca.submitted_at, ca.reviewed_at,
            ca.created_at, ca.token_expires_at,
            ca.approved_credit_limit,
            ca.print_name_first, ca.print_name_last,
            (ca.generated_pdf_document_id IS NOT NULL) AS has_pdf,
            c.id   AS customer_id,
            c.company_name,
            sb.name AS sent_by_name,
            rb.name AS reviewed_by_name
       FROM customer_credit_applications ca
       JOIN customers c ON c.id = ca.customer_id
       LEFT JOIN users sb ON sb.id = ca.sent_by
       LEFT JOIN users rb ON rb.id = ca.reviewed_by
     $whereSql
     ORDER BY $sortCol $dir
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// Derive is_expired for 'sent' rows
$now = time();
foreach ($rows as &$row) {
    $row['is_expired'] = ($row['status'] === 'sent'
        && !empty($row['token_expires_at'])
        && strtotime((string) $row['token_expires_at']) < $now);
}
unset($row);

// ── Page render ───────────────────────────────────────────────────────────────
$pageTitle      = 'Credit Applications';
$helpModuleSlug = 'credit-applications';
require_once FF_ROOT . '/includes/header.php';

// Helper — build current URL with modified params
function cca_url(array $overrides = []): string {
    $params = array_filter([
        'status'    => $_GET['status']    ?? '',
        'outcome'   => $_GET['outcome']   ?? '',
        'q'         => $_GET['q']         ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'sort'      => $_GET['sort']      ?? '',
        'dir'       => $_GET['dir']       ?? '',
        'page'      => $_GET['page']      ?? '',
    ], static fn($v) => $v !== '');
    foreach ($overrides as $k => $v) {
        if ($v === '') { unset($params[$k]); } else { $params[$k] = $v; }
    }
    $qs = http_build_query($params);
    return base_url('credit_applications') . ($qs ? '?' . $qs : '');
}

// Helper — sort link
function cca_sort_url(string $col): string {
    $current = $_GET['sort'] ?? 'created_at';
    $dir     = ($current === $col && ($_GET['dir'] ?? 'DESC') === 'DESC') ? 'ASC' : 'DESC';
    return cca_url(['sort' => $col, 'dir' => $dir, 'page' => '1']);
}
function cca_sort_icon(string $col): string {
    $current = $_GET['sort'] ?? 'created_at';
    if ($current !== $col) return '<span style="opacity:.3;">↕</span>';
    return ($_GET['dir'] ?? 'DESC') === 'DESC' ? '↓' : '↑';
}

// Status badge config
$statusBadge = [
    'sent'      => ['label' => 'Sent',      'class' => 'badge-info'],
    'opened'    => ['label' => 'Opened',    'class' => 'badge-warning'],
    'submitted' => ['label' => 'Submitted', 'class' => 'badge-primary'],
    'reviewed'  => ['label' => 'Reviewed',  'class' => 'badge-success'],
];

// Outcome badge config
$outcomeBadge = [
    'approved'   => ['label' => 'Approved',   'color' => '#16a34a'],
    'declined'   => ['label' => 'Declined',   'color' => '#dc2626'],
    'needs_info' => ['label' => 'Needs Info', 'color' => '#d97706'],
];
?>

<div class="page-header">
    <h1 class="page-header-title">Credit Applications</h1>
    <div class="page-header-actions">
        <a href="<?= base_url('settings?tab=credit_application') ?>"
           class="btn btn-ghost btn-sm">⚙ Settings</a>
        <?= help_button('credit-applications') ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <a href="<?= cca_url(['status' => 'submitted', 'page' => '1']) ?>"
       class="stat-card stat-card--amber" style="text-decoration:none;cursor:pointer;">
        <span class="stat-icon stat-icon--amber">
            <?= heroicon('clipboard-document-check') ?>
        </span>
        <div class="stat-label">Awaiting Review</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['awaiting_review']) ?></div>
        <div class="stat-delta">submitted, not yet reviewed</div>
    </a>

    <a href="<?= cca_url(['status' => '', 'outcome' => '', 'page' => '1']) ?>"
       class="stat-card stat-card--blue" style="text-decoration:none;cursor:pointer;">
        <span class="stat-icon stat-icon--blue">
            <?= heroicon('document-text') ?>
        </span>
        <div class="stat-label">Total Submitted</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['submitted_total']) ?></div>
        <div class="stat-delta">submitted + reviewed</div>
    </a>

    <a href="<?= cca_url(['status' => 'reviewed', 'page' => '1']) ?>"
       class="stat-card stat-card--teal" style="text-decoration:none;cursor:pointer;">
        <span class="stat-icon stat-icon--teal">
            <?= heroicon('shield-check') ?>
        </span>
        <div class="stat-label">Reviewed</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['reviewed']) ?></div>
        <div class="stat-delta">approved / declined / needs info</div>
    </a>

    <a href="<?= cca_url(['status' => '', 'page' => '1']) ?>"
       class="stat-card stat-card--purple" style="text-decoration:none;cursor:pointer;">
        <span class="stat-icon stat-icon--purple">
            <?= heroicon('clipboard-document-list') ?>
        </span>
        <div class="stat-label">All Applications</div>
        <div class="stat-value font-mono"><?= e((string)$kpis['total']) ?></div>
        <div class="stat-delta">across all customers</div>
    </a>

</div>

<!-- ── Table ─────────────────────────────────────────────────────────────── -->
<div class="card">
    <!-- Filter bar -->
    <form method="GET" action="<?= base_url('credit_applications') ?>"
          style="padding:16px 20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--border-default);">

        <input type="text" name="q" class="form-input form-input-sm"
               style="max-width:200px;" placeholder="Search customer / applicant…"
               value="<?= e($filterQ) ?>">

        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <?php foreach (['sent' => 'Sent', 'opened' => 'Opened', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed'] as $val => $lbl): ?>
            <option value="<?= e($val) ?>"<?= $filterStatus === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="outcome" class="form-select form-select-sm">
            <option value="">All Outcomes</option>
            <?php foreach (['approved' => 'Approved', 'declined' => 'Declined', 'needs_info' => 'Needs Info'] as $val => $lbl): ?>
            <option value="<?= e($val) ?>"<?= $filterOutcome === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" class="form-input form-input-sm"
               title="Sent from" value="<?= e($filterDateFrom) ?>"
               placeholder="From">
        <input type="date" name="date_to" class="form-input form-input-sm"
               title="Sent to" value="<?= e($filterDateTo) ?>"
               placeholder="To">

        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        <a href="<?= base_url('credit_applications') ?>" class="btn btn-ghost btn-sm">Reset</a>

        <span style="margin-left:auto;font-size:0.8125rem;color:var(--text-secondary);">
            <?= e((string)$totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?>
        </span>
    </form>

    <!-- Table -->
    <?php if (empty($rows)): ?>
    <div style="padding:40px;text-align:center;color:var(--text-secondary);">
        <p style="margin:0;font-size:0.9375rem;">No credit applications match your filters.</p>
        <?php if ($filterStatus || $filterOutcome || $filterQ || $filterDateFrom || $filterDateTo): ?>
        <a href="<?= base_url('credit_applications') ?>" style="font-size:0.875rem;">Clear filters</a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div style="overflow-x:auto;">
    <table class="data-table" style="width:100%;font-size:0.8125rem;">
        <thead>
            <tr>
                <th><a href="<?= cca_sort_url('company_name') ?>" style="color:inherit;text-decoration:none;">Customer <?= cca_sort_icon('company_name') ?></a></th>
                <th><a href="<?= cca_sort_url('status') ?>" style="color:inherit;text-decoration:none;">Status <?= cca_sort_icon('status') ?></a></th>
                <th>Sent By</th>
                <th><a href="<?= cca_sort_url('sent_at') ?>" style="color:inherit;text-decoration:none;">Sent <?= cca_sort_icon('sent_at') ?></a></th>
                <th><a href="<?= cca_sort_url('submitted_at') ?>" style="color:inherit;text-decoration:none;">Submitted <?= cca_sort_icon('submitted_at') ?></a></th>
                <th>Outcome</th>
                <th>Approved Limit</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
            $sb    = $statusBadge[$row['status']]  ?? ['label' => ucfirst((string)$row['status']), 'class' => 'badge-secondary'];
            $ob    = $row['review_outcome'] ? ($outcomeBadge[$row['review_outcome']] ?? null) : null;
            $name  = trim(((string)($row['print_name_first'] ?? '')) . ' ' . ((string)($row['print_name_last'] ?? '')));
            // Expired sent link
            $isExpired = $row['is_expired'] ?? false;
        ?>
        <tr>
            <td>
                <a href="<?= base_url('customers/' . (int)$row['customer_id']) ?>"
                   style="font-weight:500;"><?= e($row['company_name']) ?></a>
                <?php if ($name): ?>
                <div style="font-size:0.73rem;color:var(--text-secondary);margin-top:1px;"><?= e($name) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge <?= e($sb['class']) ?>"><?= e($sb['label']) ?></span>
                <?php if ($isExpired): ?>
                <span style="font-size:0.7rem;color:var(--text-secondary);display:block;">expired</span>
                <?php endif; ?>
            </td>
            <td style="color:var(--text-secondary);"><?= e($row['sent_by_name'] ?? '—') ?></td>
            <td style="white-space:nowrap;color:var(--text-secondary);font-size:0.75rem;">
                <?= $row['sent_at'] ? e(date('M j, Y', strtotime($row['sent_at']))) : '—' ?>
            </td>
            <td style="white-space:nowrap;color:var(--text-secondary);font-size:0.75rem;">
                <?= $row['submitted_at'] ? e(date('M j, Y', strtotime($row['submitted_at']))) : '—' ?>
            </td>
            <td>
                <?php if ($ob): ?>
                <span style="font-size:0.78rem;font-weight:600;color:<?= e($ob['color']) ?>;"><?= e($ob['label']) ?></span>
                <?php else: ?><span style="color:var(--text-secondary);">—</span>
                <?php endif; ?>
            </td>
            <td style="font-size:0.78rem;">
                <?php if ($row['approved_credit_limit'] !== null): ?>
                $<?= e(number_format((float)$row['approved_credit_limit'], 2)) ?>
                <?php else: ?><span style="color:var(--text-secondary);">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:right;white-space:nowrap;">
                <?php if ($row['has_pdf']): ?>
                <a href="<?= base_url('credit_applications/show') ?>?id=<?= (int)$row['id'] ?>"
                   class="btn btn-ghost btn-xs">View Form</a>
                <?php endif; ?>
                <a href="<?= base_url('customers/' . (int)$row['customer_id']) ?>"
                   class="btn btn-ghost btn-xs">Customer →</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:14px 20px;display:flex;gap:8px;align-items:center;justify-content:center;border-top:1px solid var(--border-default);">
        <?php if ($page > 1): ?>
        <a href="<?= cca_url(['page' => (string)($page - 1)]) ?>" class="btn btn-ghost btn-sm">← Prev</a>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($p = $start; $p <= $end; $p++): ?>
        <a href="<?= cca_url(['page' => (string)$p]) ?>"
           class="btn btn-sm<?= $p === $page ? ' btn-primary' : ' btn-ghost' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="<?= cca_url(['page' => (string)($page + 1)]) ?>" class="btn btn-ghost btn-sm">Next →</a>
        <?php endif; ?>

        <span style="font-size:0.8125rem;color:var(--text-secondary);margin-left:8px;">
            Page <?= $page ?> of <?= $totalPages ?> &nbsp;·&nbsp; <?= $totalRows ?> total
        </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div><!-- /card -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
