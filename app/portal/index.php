<?php
declare(strict_types=1);

/**
 * app/portal/index.php
 *
 * Customer portal — Home (S-PORTAL-REDESIGN).
 *
 * Built around the two questions a customer opens the portal with: "what do
 * I owe?" and "what's going on with my rentals?".
 *
 *   Hero          greeting, account, quick actions + the balance panel
 *                 (balance, past due, due this week, credits, Pay button)
 *   Key numbers   units on rent, open invoices, paid this year, open requests
 *   Attention     past-due / due-soon invoices, expiring documents, leases
 *                 ending, staff replies waiting, credits to use, credit
 *                 application invitations — only what applies, most urgent first
 *   Billing chart what was billed each month for 12 months (single series,
 *                 per-month hover with billed + paid; a table for screen readers)
 *   Open invoices the next five due, each payable from the Pay drawer
 *   Your fleet    units on rent right now
 *   Activity      invoices issued, payments received, requests — newest first
 *
 * Data isolation: every query filters by portal_customer_id() (Trap 8).
 * Money is bcmath (D16); chart heights are display-only ratios.
 *
 * @session S-PORTAL-REDESIGN (was: 4 KPI tiles + two tables)
 */

require_once __DIR__ . '/includes/auth.php';
require_portal_auth();
require_once __DIR__ . '/includes/ui.php';

$cid      = portal_customer_id();
$today    = ff_today();
$summary  = pt_account_summary($cid);
$currency = $summary['currency'];
$user     = portal_user();

$customer = db_row("SELECT company_name, payment_terms FROM customers WHERE id = ?", [$cid]) ?: ['company_name' => '', 'payment_terms' => ''];

// ── Greeting in the company's timezone ───────────────────────────────────
$tz       = new DateTimeZone((string) settings_get('company.timezone', APP_TIMEZONE));
$now      = new DateTimeImmutable('now', $tz);
$hour     = (int) $now->format('G');
$salute   = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first    = preg_split('/\s+/', trim((string) ($user['name'] ?? '')))[0] ?? '';

// ── Fleet on rent ────────────────────────────────────────────────────────
$fleet = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.monthly_rate, l.currency,
            eu.unit_number, eb.label AS brand, et.name AS type_name, et.category
       FROM leases l
       JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
       LEFT JOIN equipment_templates et ON et.id = eu.template_id
       LEFT JOIN equipment_brands eb ON eb.id = eu.brand_id
      WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
      ORDER BY l.start_date DESC",
    [$cid]
);
$unitsOnRent = count($fleet);

// ── Key numbers ──────────────────────────────────────────────────────────
$yearStart  = substr($today, 0, 4) . '-01-01';
$paidThisYear = (string) (db_row(
    "SELECT COALESCE(SUM(p.amount), 0) AS t
       FROM payments p
      WHERE p.customer_id = ? AND p.deleted_at IS NULL
        AND p.status IN ('pending','cleared')
        AND p.payment_date >= ?",
    [$cid, $yearStart]
)['t'] ?? '0.00');
$openRequests = db_count(
    "SELECT COUNT(*) FROM portal_service_requests WHERE customer_id = ? AND status IN ('open','in_review')",
    [$cid]
);

// ── Open invoices (next five due) ────────────────────────────────────────
$openInvoices = array_map('pt_invoice_json', db_select(
    pt_invoice_select_sql() . " WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i') . "
       AND i.status IN (" . pt_open_statuses_sql() . ") AND i.balance_due > 0
     ORDER BY i.due_date ASC, i.id ASC LIMIT 5",
    [$cid]
));

// ── Needs your attention ─────────────────────────────────────────────────
$attn = [];
if ($summary['past_due_count'] > 0) {
    $attn[] = ['danger', 'exclamation-triangle',
        pt_money($summary['past_due'], $currency) . ' is past due',
        $summary['past_due_count'] . ' invoice' . ($summary['past_due_count'] === 1 ? '' : 's')
            . ($summary['oldest_past_due_days'] > 0 ? ' · oldest ' . $summary['oldest_past_due_days'] . ' days late' : ''),
        pt_url('invoices?tab=past_due')];
}
if ($summary['due_soon_count'] > 0) {
    $attn[] = ['warning', 'clock',
        pt_money($summary['due_soon'], $currency) . ' due this week',
        $summary['due_soon_count'] . ' invoice' . ($summary['due_soon_count'] === 1 ? '' : 's') . ' due by ' . format_date(ff_local_date_add($today, 7)),
        pt_url('invoices?tab=open')];
}
[$docSql, $docParams] = pt_portal_documents_sql($cid);
$expiring = db_select(
    "SELECT d.id, d.title, d.document_type, d.expiration_date
       FROM documents d
      WHERE {$docSql} AND d.expiration_date IS NOT NULL AND d.expiration_date <= ?
      ORDER BY d.expiration_date ASC LIMIT 3",
    array_merge($docParams, [ff_local_date_add($today, 30)])
);
foreach ($expiring as $d) {
    $late = $d['expiration_date'] < $today;
    $attn[] = [$late ? 'danger' : 'warning', 'document-text',
        ($d['title'] ?: pt_document_type_label($d['document_type'])) . ($late ? ' has expired' : ' expires ' . pt_relative_day($d['expiration_date'])),
        pt_document_type_label($d['document_type']) . ' · ' . format_date($d['expiration_date']),
        pt_url('documents')];
}
foreach ($fleet as $l) {
    if (!empty($l['end_date']) && $l['end_date'] >= $today && $l['end_date'] <= ff_local_date_add($today, 21)) {
        $attn[] = ['info', 'calendar-days',
            'Lease ' . $l['contract_number'] . ' ends ' . pt_relative_day($l['end_date']),
            'Unit ' . $l['unit_number'] . ' · need it longer? Ask for an extension',
            pt_url('leases/view?id=' . (int) $l['id'])];
    }
}
$resolved = db_select(
    "SELECT id, subject FROM portal_service_requests
      WHERE customer_id = ? AND status = 'resolved' ORDER BY updated_at DESC LIMIT 2",
    [$cid]
);
foreach ($resolved as $r) {
    $attn[] = ['success', 'check-circle', 'Request resolved: ' . $r['subject'], 'Happy with it? Close it — or reply if something is still off.', pt_url('requests/view?id=' . (int) $r['id'])];
}
if (bccomp($summary['credit'], '0', 2) > 0) {
    $attn[] = ['brand', 'receipt-percent',
        'You have ' . pt_money($summary['credit'], $currency) . ' in credit',
        'We can apply it to an open invoice — just ask',
        pt_url('requests/create?type=billing_inquiry&subject=' . rawurlencode('Please apply my account credit')
            . '&message=' . rawurlencode('Please apply my available credit of ' . pt_money($summary['credit'], $currency) . ' to my open invoices.'))];
}
$cca = db_row(
    "SELECT id, status FROM customer_credit_applications
      WHERE customer_id = ? AND deleted_at IS NULL AND status IN ('sent','opened')
      ORDER BY created_at DESC LIMIT 1",
    [$cid]
);
if ($cca) {
    $attn[] = ['brand', 'clipboard-document-check', 'Your credit application is waiting', 'Check your email for the secure link to complete it.', pt_url('credit-applications')];
}

// ── Billing, last 12 months (customer's own currency) ────────────────────
$months = [];
$start  = (new DateTimeImmutable($today))->modify('first day of this month')->modify('-11 months');
for ($i = 0; $i < 12; $i++) {
    $m = $start->modify("+{$i} months");
    $months[$m->format('Y-m')] = ['label' => $m->format('M'), 'long' => $m->format('F Y'), 'billed' => '0.00', 'paid' => '0.00'];
}
$from = $start->format('Y-m-d');
foreach (db_select(
    "SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym, SUM(total_amount) AS t
       FROM invoices
      WHERE customer_id = ? AND deleted_at IS NULL AND status NOT IN ('void','draft')
        AND currency = ? AND invoice_date >= ?
      GROUP BY ym",
    [$cid, $currency, $from]
) as $r) {
    if (isset($months[$r['ym']])) $months[$r['ym']]['billed'] = (string) $r['t'];
}
foreach (db_select(
    "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(pa.amount) AS t
       FROM payment_allocations pa
       JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL AND p.status IN ('pending','cleared')
       JOIN invoices i ON i.id = pa.invoice_id AND i.customer_id = ? AND i.currency = ?
      WHERE p.payment_date >= ?
      GROUP BY ym",
    [$cid, $currency, $from]
) as $r) {
    if (isset($months[$r['ym']])) $months[$r['ym']]['paid'] = (string) $r['t'];
}
$billedTotal = '0.00';
$maxBilled   = '0.00';
foreach ($months as $mm) {
    $billedTotal = bcadd($billedTotal, $mm['billed'], 2);
    if (bccomp($mm['billed'], $maxBilled, 2) > 0) $maxBilled = $mm['billed'];
}
// Clean y-axis ceiling: 1 / 2 / 2.5 / 5 × 10^n above the tallest month.
$chartMax = 0;
$maxF = (float) $maxBilled; // display geometry only — never money math
if ($maxF > 0) {
    $pow = 10 ** floor(log10($maxF));
    foreach ([1, 2, 2.5, 5, 10] as $step) {
        if ($step * $pow >= $maxF) { $chartMax = $step * $pow; break; }
    }
}
$hasChart = bccomp($billedTotal, '0', 2) > 0;
$curMonth = substr($today, 0, 7);

// ── Recent activity ──────────────────────────────────────────────────────
$activity = [];
foreach (db_select(
    "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.currency
       FROM invoices i
      WHERE i.customer_id = ? AND i.deleted_at IS NULL AND i.status NOT IN ('void','draft')
      ORDER BY i.invoice_date DESC, i.id DESC LIMIT 6",
    [$cid]
) as $r) {
    $activity[] = ['d' => $r['invoice_date'], 'icon' => 'document-text', 'tone' => 'brand',
        'title' => 'Invoice ' . $r['invoice_number'] . ' issued', 'url' => pt_url('invoices/view?id=' . (int) $r['id']),
        'meta' => format_date($r['invoice_date']), 'amt' => pt_money($r['total_amount'], (string) $r['currency'])];
}
foreach (db_select(
    "SELECT p.id, p.payment_date, p.amount, p.currency, p.payment_method
       FROM payments p
      WHERE p.customer_id = ? AND p.deleted_at IS NULL AND p.status IN ('pending','cleared')
      ORDER BY p.payment_date DESC, p.id DESC LIMIT 6",
    [$cid]
) as $r) {
    $activity[] = ['d' => $r['payment_date'], 'icon' => 'check-circle', 'tone' => 'success',
        'title' => 'Payment received — thank you', 'url' => pt_url('payments#history'),
        'meta' => format_date($r['payment_date']) . ' · ' . pt_payment_method($r['payment_method']), 'amt' => pt_money($r['amount'], (string) $r['currency'])];
}
foreach (db_select(
    "SELECT id, subject, status, created_at FROM portal_service_requests
      WHERE customer_id = ? ORDER BY created_at DESC LIMIT 4",
    [$cid]
) as $r) {
    $activity[] = ['d' => substr(ff_utc_to_local((string) $r['created_at'], 'Y-m-d H:i:s'), 0, 10), 'icon' => 'wrench-screwdriver', 'tone' => 'info',
        'title' => $r['subject'], 'url' => pt_url('requests/view?id=' . (int) $r['id']),
        'meta' => 'Request · ' . format_datetime($r['created_at'], 'M j, Y'), 'amt' => ''];
}
usort($activity, static fn ($a, $b) => strcmp((string) $b['d'], (string) $a['d']));
$activity = array_slice($activity, 0, 7);

$pageTitle    = 'Home';
$ptHideRibbon = true;
require_once __DIR__ . '/includes/header.php';

$hasBalance = bccomp($summary['outstanding'], '0', 2) > 0;
?>

<section class="pt-hero">
    <div>
        <div class="pt-hero-date"><?= e($now->format('l, F j')) ?></div>
        <h1 class="pt-hero-title"><?= e($salute) ?><?= $first !== '' ? ', ' . e($first) : '' ?></h1>
        <p class="pt-hero-sub">
            You're signed in for <strong><?= e($customer['company_name']) ?></strong>.
            <?php if ($unitsOnRent > 0): ?>
                You have <strong><?= $unitsOnRent ?> unit<?= $unitsOnRent === 1 ? '' : 's' ?></strong> on rent<?= $summary['open_count'] > 0 ? ' and <strong>' . $summary['open_count'] . ' open invoice' . ($summary['open_count'] === 1 ? '' : 's') . '</strong>' : '' ?>.
            <?php elseif ($summary['open_count'] > 0): ?>
                You have <strong><?= $summary['open_count'] ?> open invoice<?= $summary['open_count'] === 1 ? '' : 's' ?></strong>.
            <?php else: ?>
                Everything's squared away.
            <?php endif; ?>
        </p>
        <div class="pt-quick">
            <a href="<?= e(pt_url('requests/create')) ?>"><?= pt_icon('wrench-screwdriver') ?> Request service</a>
            <a href="<?= e(pt_url('requests/create?type=new_lease_inquiry')) ?>"><?= pt_icon('truck') ?> Rent more equipment</a>
            <button type="button" x-data @click="$store.statement.show()"><?= pt_icon('document-arrow-down') ?> Get a statement</button>
            <button type="button" x-data @click="$store.notice.show()"><?= pt_icon('paper-airplane') ?> Tell us about a payment</button>
        </div>
    </div>

    <div class="pt-balance">
        <div class="pt-balance-label">
            <span>Balance due</span>
            <?php if (!empty($customer['payment_terms'])): ?>
                <span class="pt-tag"><?= e($customer['payment_terms']) ?></span>
            <?php endif; ?>
        </div>
        <div class="pt-balance-amount"><?= e(pt_money($summary['outstanding'], $currency)) ?></div>
        <div class="pt-balance-note">
            <?php if ($hasBalance): ?>
                Across <?= $summary['open_count'] ?> open invoice<?= $summary['open_count'] === 1 ? '' : 's' ?><?= $summary['next_due_date'] ? ' · next due ' . e(format_date($summary['next_due_date'])) : '' ?>
            <?php else: ?>
                No open invoices
            <?php endif; ?>
        </div>

        <?php if ($hasBalance): ?>
            <ul class="pt-balance-split">
                <li><i class="pt-dot pt-dot--danger"></i><span>Past due</span><span><?= e(pt_money($summary['past_due'], $currency)) ?></span></li>
                <li><i class="pt-dot pt-dot--warning"></i><span>Due in the next 7 days</span><span><?= e(pt_money($summary['due_soon'], $currency)) ?></span></li>
                <?php if (bccomp($summary['credit'], '0', 2) > 0): ?>
                <li><i class="pt-dot pt-dot--success"></i><span>Credit on account</span><span><?= e(pt_money($summary['credit'], $currency)) ?></span></li>
                <?php endif; ?>
            </ul>
            <div class="pt-balance-actions">
                <button type="button" class="pt-btn pt-btn--primary pt-btn--lg" x-data @click="$store.checkout.start([])">
                    <?= pt_icon('credit-card') ?> Pay now
                </button>
                <a href="<?= e(pt_url('payments')) ?>" class="pt-btn pt-btn--secondary pt-btn--lg" aria-label="All payment options">Options</a>
            </div>
        <?php else: ?>
            <div class="pt-paid-up"><?= pt_icon('check-circle') ?> You're all paid up. Thank you!</div>
            <div class="pt-balance-actions" style="grid-template-columns:1fr">
                <a href="<?= e(pt_url('payments#history')) ?>" class="pt-btn pt-btn--secondary">View payment history</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="pt-stats">
    <a class="pt-stat" href="<?= e(pt_url('equipment')) ?>">
        <div class="pt-stat-top"><span class="pt-stat-label">Units on rent</span><span class="pt-stat-ic pt-stat-ic--brand"><?= pt_icon('truck') ?></span></div>
        <div class="pt-stat-value"><?= $unitsOnRent ?></div>
        <div class="pt-stat-sub"><?= $summary['active_leases'] ?> active lease<?= $summary['active_leases'] === 1 ? '' : 's' ?></div>
    </a>
    <a class="pt-stat" href="<?= e(pt_url('invoices?tab=open')) ?>">
        <div class="pt-stat-top"><span class="pt-stat-label">Open invoices</span><span class="pt-stat-ic <?= $summary['past_due_count'] > 0 ? 'pt-stat-ic--danger' : 'pt-stat-ic--info' ?>"><?= pt_icon('document-text') ?></span></div>
        <div class="pt-stat-value"><?= $summary['open_count'] ?></div>
        <div class="pt-stat-sub"><?= $summary['past_due_count'] > 0 ? $summary['past_due_count'] . ' past due' : 'None past due' ?></div>
    </a>
    <a class="pt-stat" href="<?= e(pt_url('payments#history')) ?>">
        <div class="pt-stat-top"><span class="pt-stat-label">Paid in <?= e(substr($today, 0, 4)) ?></span><span class="pt-stat-ic pt-stat-ic--success"><?= pt_icon('banknotes') ?></span></div>
        <div class="pt-stat-value"><?= e(pt_money($paidThisYear, $currency)) ?></div>
        <div class="pt-stat-sub">Payments received this year</div>
    </a>
    <a class="pt-stat" href="<?= e(pt_url('requests')) ?>">
        <div class="pt-stat-top"><span class="pt-stat-label">Open requests</span><span class="pt-stat-ic pt-stat-ic--warning"><?= pt_icon('wrench-screwdriver') ?></span></div>
        <div class="pt-stat-value"><?= $openRequests ?></div>
        <div class="pt-stat-sub"><?= $openRequests > 0 ? 'Our team is on it' : 'Nothing waiting on us' ?></div>
    </a>
</div>

<div class="pt-grid pt-grid--main-wide">
    <div class="pt-stack">

        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('bolt') ?> Needs your attention</h2>
                </div>
            </div>
            <?php if (!$attn): ?>
                <div class="pt-allclear"><?= pt_icon('check-circle') ?> Nothing needs your attention right now.</div>
            <?php else: ?>
                <ul class="pt-attn">
                    <?php foreach (array_slice($attn, 0, 6) as [$tone, $icon, $title, $text, $href]): ?>
                    <li>
                        <a class="pt-attn-item" data-tone="<?= e($tone) ?>" href="<?= e($href) ?>">
                            <span class="pt-attn-ic"><?= pt_icon($icon) ?></span>
                            <span class="pt-attn-body">
                                <span class="pt-attn-title" style="display:block"><?= e($title) ?></span>
                                <span class="pt-attn-text" style="display:block"><?= e($text) ?></span>
                            </span>
                            <span class="pt-attn-go"><?= pt_icon('chevron-right') ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('chart-bar') ?> Billed each month</h2>
                    <p class="pt-card-sub">
                        <?php if ($hasChart): ?>
                            <?= e(pt_money($billedTotal, $currency)) ?> over the last 12 months · about <?= e(pt_money(bcdiv($billedTotal, '12', 2), $currency)) ?> a month
                        <?php else: ?>
                            Your monthly billing will appear here.
                        <?php endif; ?>
                    </p>
                </div>
                <button type="button" class="pt-card-link" style="background:none;border:0;cursor:pointer" x-data @click="$store.statement.show()">Statement <?= pt_icon('arrow-right', 'pt-ic pt-ic--xs') ?></button>
            </div>
            <?php if ($hasChart): ?>
            <div class="pt-chart" role="img" aria-label="Amount billed each month for the last 12 months">
                <div class="pt-chart-plot">
                    <div class="pt-chart-grid" aria-hidden="true">
                        <?php for ($g = 4; $g >= 0; $g--): $val = $chartMax * $g / 4; ?>
                            <div class="pt-chart-gl<?= $g === 0 ? ' is-base' : '' ?>" style="bottom:<?= $g * 25 ?>%"><span><?= e($val >= 1000 ? '$' . rtrim(rtrim(number_format($val / 1000, 1), '0'), '.') . 'k' : '$' . number_format($val, 0)) ?></span></div>
                        <?php endfor; ?>
                    </div>
                    <div class="pt-chart-cols">
                        <?php foreach ($months as $ym => $mm):
                            $h = $chartMax > 0 ? max(0, min(100, ((float) $mm['billed'] / $chartMax) * 100)) : 0;
                            $zero = bccomp($mm['billed'], '0', 2) <= 0;
                        ?>
                            <button type="button" class="pt-chart-col<?= $ym === $curMonth ? ' is-current' : '' ?><?= $zero ? ' is-zero' : '' ?>" aria-label="<?= e($mm['long'] . ': billed ' . pt_money($mm['billed'], $currency) . ', paid ' . pt_money($mm['paid'], $currency)) ?>">
                                <span class="pt-chart-bar" style="height:<?= $zero ? '2px' : number_format($h, 2, '.', '') . '%' ?>"></span>
                                <span class="pt-chart-tip" aria-hidden="true">
                                    <b><?= e($mm['long']) ?></b>
                                    <div><span>Billed</span><span><?= e(pt_money($mm['billed'], $currency)) ?></span></div>
                                    <div><span>Paid</span><span><?= e(pt_money($mm['paid'], $currency)) ?></span></div>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="pt-chart-x" aria-hidden="true">
                    <?php foreach ($months as $mm): ?><span><?= e($mm['label']) ?></span><?php endforeach; ?>
                </div>
                <table class="pt-sr">
                    <caption>Billed and paid by month</caption>
                    <thead><tr><th>Month</th><th>Billed</th><th>Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($months as $mm): ?>
                            <tr><td><?= e($mm['long']) ?></td><td><?= e(pt_money($mm['billed'], $currency)) ?></td><td><?= e(pt_money($mm['paid'], $currency)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <?= pt_empty('chart-bar', 'No billing yet', 'Once invoices are issued you\'ll see a month-by-month view here.') ?>
            <?php endif; ?>
        </section>

        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('document-text') ?> Open invoices</h2>
                    <p class="pt-card-sub"><?= $summary['open_count'] > 0 ? 'Oldest due first' : 'Nothing outstanding' ?></p>
                </div>
                <a class="pt-card-link" href="<?= e(pt_url('invoices')) ?>">All invoices <?= pt_icon('arrow-right', 'pt-ic pt-ic--xs') ?></a>
            </div>
            <?php if (!$openInvoices): ?>
                <?= pt_empty('check-circle', 'All caught up', 'You have no open invoices.') ?>
            <?php else: ?>
                <div class="pt-card-body--flush pt-table-wrap">
                    <table data-no-auto-label class="pt-table pt-table--stack">
                        <thead><tr><th>Invoice</th><th>Due</th><th>Status</th><th class="num">Balance</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($openInvoices as $inv): ?>
                            <tr>
                                <td class="pt-cell-primary">
                                    <a class="pt-table-main" href="<?= e(pt_url('invoices/view?id=' . $inv['id'])) ?>"><?= e($inv['number']) ?></a>
                                    <?php if ($inv['period'] !== ''): ?><span class="pt-table-sub"><?= e($inv['period']) ?></span><?php endif; ?>
                                </td>
                                <td class="nw" data-label="Due"><?= e(format_date($inv['due_date'])) ?><?php if ($inv['days_late'] > 0): ?><span class="pt-table-sub pt-danger-ink"><?= $inv['days_late'] ?> days late</span><?php endif; ?></td>
                                <td data-label="Status"><?= pt_badge($inv['status_label'], $inv['status_tone']) ?></td>
                                <td class="num" data-label="Balance"><strong><?= e($inv['balance_fmt']) ?></strong></td>
                                <td class="shrink pt-cell-actions" style="text-align:right">
                                    <button type="button" class="pt-btn pt-btn--soft pt-btn--sm" x-data @click="$store.checkout.start([<?= (int) $inv['id'] ?>])">Pay</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="pt-stack">
        <section class="pt-card">
            <div class="pt-card-head">
                <div>
                    <h2 class="pt-card-title"><?= pt_icon('truck') ?> Your fleet</h2>
                    <p class="pt-card-sub"><?= $unitsOnRent ?> unit<?= $unitsOnRent === 1 ? '' : 's' ?> on rent</p>
                </div>
                <a class="pt-card-link" href="<?= e(pt_url('leases')) ?>">Leases <?= pt_icon('arrow-right', 'pt-ic pt-ic--xs') ?></a>
            </div>
            <?php if (!$fleet): ?>
                <?= pt_empty('truck', 'No units on rent', 'Need equipment? We\'ll put a quote together.', '<a class="pt-btn pt-btn--soft pt-btn--sm" href="' . e(pt_url('requests/create?type=new_lease_inquiry')) . '">Rent equipment</a>') ?>
            <?php else: ?>
                <ul class="pt-attn" style="padding-top:8px">
                    <?php foreach (array_slice($fleet, 0, 6) as $l):
                        $days = max(0, pt_days_between($l['start_date'], $today));
                    ?>
                    <li>
                        <a class="pt-attn-item" data-tone="brand" href="<?= e(pt_url('leases/view?id=' . (int) $l['id'])) ?>">
                            <span class="pt-attn-ic"><?= pt_icon('truck') ?></span>
                            <span class="pt-attn-body">
                                <span class="pt-attn-title" style="display:block"><?= e($l['unit_number']) ?> <span class="pt-faint" style="font-weight:500">· <?= e($l['contract_number']) ?></span></span>
                                <span class="pt-attn-text" style="display:block"><?= e(trim(($l['brand'] ? $l['brand'] . ' ' : '') . ($l['type_name'] ?: ucfirst((string) $l['category'])))) ?> · <?= $days ?> day<?= $days === 1 ? '' : 's' ?> on rent</span>
                            </span>
                            <span class="pt-attn-go"><?= pt_icon('chevron-right') ?></span>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($unitsOnRent > 6): ?>
                    <div class="pt-card-foot"><span>+<?= $unitsOnRent - 6 ?> more</span><a class="pt-card-link" href="<?= e(pt_url('equipment')) ?>">See all equipment</a></div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="pt-card">
            <div class="pt-card-head">
                <div><h2 class="pt-card-title"><?= pt_icon('clock') ?> Recent activity</h2></div>
            </div>
            <?php if (!$activity): ?>
                <?= pt_empty('clock', 'No activity yet', 'Invoices, payments and requests will show up here.') ?>
            <?php else: ?>
                <ul class="pt-timeline" style="padding-top:12px">
                    <?php foreach ($activity as $a): ?>
                    <li class="pt-tl-item">
                        <span class="pt-tl-ic pt-tl-ic--<?= e($a['tone']) ?>"><?= pt_icon($a['icon']) ?></span>
                        <div class="pt-tl-body">
                            <div class="pt-tl-title"><a href="<?= e($a['url']) ?>"><?= e($a['title']) ?></a></div>
                            <div class="pt-tl-meta"><?= e($a['meta']) ?></div>
                        </div>
                        <?php if ($a['amt'] !== ''): ?><div class="pt-tl-amt"><?= e($a['amt']) ?></div><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
