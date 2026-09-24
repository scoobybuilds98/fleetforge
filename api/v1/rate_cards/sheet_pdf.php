<?php
declare(strict_types=1);

/**
 * api/v1/rate_cards/sheet_pdf.php
 *
 * S-RATES-MODULE — a printable rate sheet on the company letterhead
 * (lib/Pdf/PdfKit.php), to send a customer their prices:
 *
 *   ?customer_id=N           what that customer pays today for every
 *                            equipment type with a price (their own lines
 *                            first, then standard prices), plus any price
 *                            change already scheduled on their cards
 *   ?customer_id=N&own=1     only their own negotiated lines
 *   ?standard=1              the standard price list (no customer)
 *
 * A GET stream (D-PDF-LETTERHEAD: links are GET streams, never a pop-up
 * opened after an await). Prices are before tax.
 *
 * @method  GET
 * @auth    Session required; require_permission('rates','view')
 * @returns 200 application/pdf (inline) · 404 NOT_FOUND · 500 PDF_GENERATION_FAILED
 * @session S-RATES-MODULE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Pdf\PdfKit;
use FleetForge\RateCards\RateCardItems;
use FleetForge\RateCards\RateInsights;

require_method('GET');
require_auth_api();
require_permission('rates', 'view');

$today      = ff_today();
$customerId = clean_int($_GET['customer_id'] ?? null);
$customer   = null;
if ($customerId) {
    $customer = db_row(
        "SELECT id, company_name, contact_name, address, city, province, postal_code, email
           FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$customerId]
    );
    if (!$customer) {
        json_error('NOT_FOUND', 'Customer not found.', 404);
    }
} elseif (empty($_GET['standard'])) {
    json_validation_error(['customer_id' => 'Choose a customer, or ask for the standard price list.']);
}

$m   = static fn ($v): string => ($v === null || $v === '' || bccomp((string) $v, '0', 4) <= 0) ? '—' : PdfKit::money($v);
$m4  = static fn ($v): string => ($v === null || $v === '' || bccomp((string) $v, '0', 4) <= 0) ? '—' : '$' . rtrim(rtrim(bcadd((string) $v, '0', 4), '0'), '.');
$rows = [];

// Short-lease minimums only bind for categories that enforce them
// (equipment_categories.enforce_minimum_billing_days, S-EQTAX) — the sheet
// shows a minimum only where billing would actually apply it.
$enforce = [];
foreach (db_select(
    "SELECT et.id, COALESCE(ec.enforce_minimum_billing_days, 0) AS enforce
       FROM equipment_templates et
       LEFT JOIN equipment_categories ec
              ON ec.deleted_at IS NULL
             AND (ec.id = et.category_id OR (et.category_id IS NULL AND ec.slug = et.category))
      WHERE et.deleted_at IS NULL"
) as $er) {
    $enforce[(int) $er['id']] = (int) $er['enforce'] === 1;
}
$minDefault = (int) settings_get('lease.minimum_billing_days', '3');

if ($customer) {
    $data = RateInsights::customerPrices((int) $customer['id'], $today);
    foreach ($data['prices'] as $p) {
        $src = $p['price']['source'];
        if ($src === 'none' || (!empty($_GET['own']) && $src !== 'customer')) {
            continue;
        }
        $rows[] = ['name' => $p['name'], 'group' => $p['category_label'], 'own' => $src === 'customer', 'p' => $p['price'], 'tid' => (int) $p['template_id']];
    }
} else {
    foreach (RateInsights::priceBook($today)['groups'] as $g) {
        foreach ($g['types'] as $t) {
            if (!$t['is_active'] || ($t['standard']['source'] ?? 'none') === 'none') {
                continue;
            }
            $rows[] = ['name' => $t['name'], 'group' => $g['label'], 'own' => false, 'p' => $t['standard'], 'tid' => (int) $t['id']];
        }
    }
}

$html = '';
if ($customer) {
    $addr = PdfKit::addressLines((string) ($customer['address'] ?? ''), (string) ($customer['city'] ?? ''), (string) ($customer['province'] ?? ''), (string) ($customer['postal_code'] ?? ''));
    $own  = count(array_filter($rows, static fn ($r) => $r['own']));
    $html .= '<table class="ff-panels"><tr>'
        . '<td class="ff-panel" width="48%"><div class="ff-panel-label">Prepared for</div>'
        . '<strong>' . e($customer['company_name']) . '</strong><br>'
        . (!empty($customer['contact_name']) ? 'Attn: ' . e($customer['contact_name']) . '<br>' : '')
        . implode('<br>', array_map('e', $addr))
        . '</td><td class="ff-gap"></td>'
        . '<td class="ff-panel" width="48%"><div class="ff-panel-label">About these prices</div>'
        . e($own > 0
            ? $own . ' of the prices below are negotiated for ' . $customer['company_name'] . '; the rest are our standard prices.'
            : 'These are our standard prices.')
        . '<br><span class="muted">Prices are before tax and apply to rentals starting on or after ' . e(PdfKit::date($today)) . '.</span>'
        . '</td></tr></table>';
}

$html .= '<div class="ff-h">Rental prices</div>';
if ($rows === []) {
    $html .= '<div class="ff-panel-label" style="text-align:center;padding:6mm 0;">No prices are set up yet.</div>';
} else {
    $html .= '<table class="ff-grid"><thead><tr>'
        . '<th>Equipment</th><th class="num" width="11%">Daily</th><th class="num" width="11%">Weekly</th>'
        . '<th class="num" width="12%">Monthly</th><th class="num" width="13%">Distance</th>'
        . '<th class="num" width="10%">GPS / day</th><th class="num" width="9%">Min. days</th>'
        . '</tr></thead><tbody>';
    // The line's minimum, else the company default — shown only where the
    // equipment's category enforces it (as billing does).
    $minCell = static function (array $r) use ($enforce, $minDefault): string {
        if (empty($enforce[$r['tid']])) {
            return '—';
        }
        $n = ($r['p']['minimum_days'] ?? null) !== null && $r['p']['minimum_days'] !== '' ? (int) $r['p']['minimum_days'] : $minDefault;
        return $n >= 2 ? (string) $n : '—';
    };
    foreach ($rows as $r) {
        $p    = $r['p'];
        $unit = ($p['mileage_unit'] ?? 'km') === 'miles' ? 'mi' : 'km';
        $html .= '<tr>'
            . '<td><strong>' . e($r['name']) . '</strong><br><span class="muted">' . e($r['group'])
            . ($customer ? ($r['own'] ? ' · your price' : ' · standard price') : '')
            . (($p['currency'] ?? 'CAD') !== 'CAD' ? ' · ' . e($p['currency']) : '') . '</span></td>'
            . '<td class="num">' . $m($p['daily_rate'] ?? null) . '</td>'
            . '<td class="num">' . $m($p['weekly_rate'] ?? null) . '</td>'
            . '<td class="num">' . $m($p['monthly_rate'] ?? null) . '</td>'
            . '<td class="num">' . ($m4($p['mileage_rate'] ?? null) !== '—' ? $m4($p['mileage_rate']) . ' / ' . $unit : '—')
                . ($m4($p['hourly_rate'] ?? null) !== '—' ? '<br>' . $m4($p['hourly_rate']) . ' / engine hr' : '') . '</td>'
            . '<td class="num">' . $m($p['gps_price'] ?? null) . '</td>'
            . '<td class="num">' . $minCell($r) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
}

// Scheduled changes on the customer's cards.
if ($customer) {
    $upcoming = db_select(
        "SELECT id, name, effective_from FROM rate_cards
          WHERE customer_id = ? AND deleted_at IS NULL AND effective_from > ?
          ORDER BY effective_from",
        [(int) $customer['id'], $today]
    );
    if ($upcoming) {
        $items = RateInsights::itemsByCard(array_map('intval', array_column($upcoming, 'id')));
        $html .= '<div class="ff-h">Scheduled price changes</div><table class="ff-grid"><thead><tr>'
            . '<th width="18%">From</th><th>Equipment</th><th class="num" width="12%">Daily</th><th class="num" width="12%">Weekly</th><th class="num" width="13%">Monthly</th>'
            . '</tr></thead><tbody>';
        foreach ($upcoming as $u) {
            foreach (RateInsights::decorateLines($items[(int) $u['id']] ?? [], $today) as $l) {
                $html .= '<tr><td class="nw">' . e(PdfKit::date($u['effective_from'])) . '</td><td>' . e($l['label']) . '</td>'
                    . '<td class="num">' . $m($l['daily_rate']) . '</td><td class="num">' . $m($l['weekly_rate']) . '</td>'
                    . '<td class="num">' . $m($l['monthly_rate']) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
    }
}

$html .= '<div class="ff-h">How rentals are priced</div>'
    . '<table class="ff-kv" width="100%">'
    . '<tr><td class="k">Up to 7 days</td><td>The lower of the daily price for each day, or one weekly price.</td></tr>'
    . '<tr><td class="k">Over 7 days</td><td>The weekly price for each week plus one seventh of it for each extra day — until that passes the monthly price.</td></tr>'
    . '<tr><td class="k">About a month or more</td><td>One monthly price for a rental of up to about a month; longer rentals pay the monthly price for each full calendar month and one thirtieth of it per day for part months.</td></tr>'
    . '<tr><td class="k">Minimum days</td><td>Where shown, a shorter rental is charged that many days at the daily price.</td></tr>'
    . '<tr><td class="k">Distance · GPS</td><td>Distance is charged per kilometre or mile driven; GPS tracking per rental day when included.</td></tr>'
    . '</table>';

try {
    $bytes = PdfKit::render($html, [
        'title'       => 'Rate sheet',
        'reference'   => $customer ? (string) $customer['company_name'] : 'Standard prices',
        'meta'        => [
            'Prices as of' => PdfKit::date($today),
            'Currency'     => 'CAD unless noted',
        ],
        'footer_note' => 'Prices are subject to change. Please contact us with any questions about your rates.',
        'generated'   => true,
    ]);
} catch (\Throwable $e) {
    error_log('[rate_cards/sheet_pdf] ' . ($customer ? 'customer ' . $customer['id'] : 'standard') . ': ' . $e->getMessage());
    json_error('PDF_GENERATION_FAILED', 'Could not produce the rate sheet. Please try again.', 500);
}

PdfKit::stream($bytes, 'rate_sheet_' . ($customer ? $customer['id'] . '_' . $customer['company_name'] : 'standard') . '_' . $today);
