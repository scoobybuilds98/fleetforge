<?php declare(strict_types=1);

/**
 * tests/_verify_seed_dataset.php
 *
 * Verifies that scripts/seed_dataset.php actually landed real rows in the DB
 * by counting tagged rows per table and spot-checking a few key linkages.
 */

require_once __DIR__ . '/../config/app.php';

const SEED_TAG = '[seed:dataset]';

$targets = [
    ['equipment_templates',       'description'],
    ['vendors',                   'notes'],
    ['yards',                     'notes'],
    ['rate_cards',                'description'],
    ['rate_card_items',           null],   // no tag col — count via parent
    ['customers',                 'internal_notes'],
    ['customer_contacts',         'notes'],
    ['customer_equipment_rates',  'notes'],
    ['equipment_units',           'internal_notes'],
    ['leases',                    'notes'],
    ['invoices',                  'internal_notes'],
    ['invoice_line_items',        null],   // via parent invoices
    ['payments',                  'internal_notes'],
    ['payment_allocations',       null],   // via parent payments
    ['maintenance_work_orders',   'internal_notes'],
    ['damage_claims',             'notes'],
    ['inspections',               'notes'],
    ['mileage_logs',              'notes'],
    ['reservations',              'internal_notes'],
    ['reservation_units',         null],   // via parent reservations
];

echo "=== seed_dataset verification ===\n";
$grand = 0;
foreach ($targets as [$table, $col]) {
    if ($col) {
        $row = db_row("SELECT COUNT(*) AS n FROM `$table` WHERE `$col` LIKE ?", ['%' . SEED_TAG . '%']);
    } else {
        // count via parent relationship
        $row = match ($table) {
            'rate_card_items'     => db_row("SELECT COUNT(rci.id) AS n FROM rate_card_items rci JOIN rate_cards rc ON rc.id = rci.rate_card_id WHERE rc.description LIKE ?", ['%' . SEED_TAG . '%']),
            'invoice_line_items'  => db_row("SELECT COUNT(ili.id) AS n FROM invoice_line_items ili JOIN invoices i ON i.id = ili.invoice_id WHERE i.internal_notes LIKE ?", ['%' . SEED_TAG . '%']),
            'payment_allocations' => db_row("SELECT COUNT(pa.id) AS n FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id WHERE p.internal_notes LIKE ?", ['%' . SEED_TAG . '%']),
            'reservation_units'   => db_row("SELECT COUNT(ru.id) AS n FROM reservation_units ru JOIN reservations r ON r.id = ru.reservation_id WHERE r.internal_notes LIKE ?", ['%' . SEED_TAG . '%']),
            default => ['n' => 0],
        };
    }
    $n = (int) ($row['n'] ?? 0);
    $grand += $n;
    echo sprintf("  %-26s %5d\n", $table, $n);
}
echo str_repeat('-', 36) . "\n";
echo sprintf("  %-26s %5d\n", 'TOTAL', $grand);

echo "\n-- enum coverage spot checks --\n";

$checks = [
    'customer statuses'          => "SELECT status, COUNT(*) n FROM customers WHERE internal_notes LIKE ? GROUP BY status",
    'customer collection_status' => "SELECT collection_status, COUNT(*) n FROM customers WHERE internal_notes LIKE ? GROUP BY collection_status",
    'customer risk_score'        => "SELECT risk_score, COUNT(*) n FROM customers WHERE internal_notes LIKE ? GROUP BY risk_score",
    'equipment_unit status'      => "SELECT status, COUNT(*) n FROM equipment_units WHERE internal_notes LIKE ? GROUP BY status",
    'equipment_unit ownership'   => "SELECT ownership_type, COUNT(*) n FROM equipment_units WHERE internal_notes LIKE ? GROUP BY ownership_type",
    'lease status'               => "SELECT status, COUNT(*) n FROM leases WHERE notes LIKE ? GROUP BY status",
    'invoice status'             => "SELECT status, COUNT(*) n FROM invoices WHERE internal_notes LIKE ? GROUP BY status",
    'payment method'             => "SELECT payment_method, COUNT(*) n FROM payments WHERE internal_notes LIKE ? GROUP BY payment_method",
    'payment status'             => "SELECT status, COUNT(*) n FROM payments WHERE internal_notes LIKE ? GROUP BY status",
    'work_order type'            => "SELECT work_type, COUNT(*) n FROM maintenance_work_orders WHERE internal_notes LIKE ? GROUP BY work_type",
    'damage severity'            => "SELECT severity, COUNT(*) n FROM damage_claims WHERE notes LIKE ? GROUP BY severity",
    'inspection type'            => "SELECT inspection_type, COUNT(*) n FROM inspections WHERE notes LIKE ? GROUP BY inspection_type",
    'reservation status'         => "SELECT status, COUNT(*) n FROM reservations WHERE internal_notes LIKE ? GROUP BY status",
];

foreach ($checks as $label => $sql) {
    $rows = db_select($sql, ['%' . SEED_TAG . '%']);
    $parts = array_map(fn($r) => ($r[array_key_first($r)] ?? '?') . "=" . ($r['n'] ?? 0), $rows);
    echo sprintf("  %-30s  %s\n", $label, implode(', ', $parts));
}

echo "\n-- linkage sanity --\n";
$linkage = [
    'leases with customer+unit'  => "SELECT COUNT(*) n FROM leases l JOIN customers c ON c.id=l.customer_id JOIN equipment_units eu ON eu.id=l.equipment_unit_id WHERE l.notes LIKE ?",
    'invoices with lease'        => "SELECT COUNT(*) n FROM invoices i JOIN leases l ON l.id=i.lease_id WHERE i.internal_notes LIKE ?",
    'invoice_line_items linked'  => "SELECT COUNT(*) n FROM invoice_line_items ili JOIN invoices i ON i.id=ili.invoice_id WHERE i.internal_notes LIKE ?",
    'payments with customer'     => "SELECT COUNT(*) n FROM payments p JOIN customers c ON c.id=p.customer_id WHERE p.internal_notes LIKE ?",
    'allocations linked to inv'  => "SELECT COUNT(*) n FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id JOIN invoices i ON i.id=pa.invoice_id WHERE p.internal_notes LIKE ?",
    'WOs linked to unit'         => "SELECT COUNT(*) n FROM maintenance_work_orders wo JOIN equipment_units eu ON eu.id=wo.equipment_unit_id WHERE wo.internal_notes LIKE ?",
    'reservation_units linked'   => "SELECT COUNT(*) n FROM reservation_units ru JOIN reservations r ON r.id=ru.reservation_id WHERE r.internal_notes LIKE ?",
];

foreach ($linkage as $label => $sql) {
    $row = db_row($sql, ['%' . SEED_TAG . '%']);
    echo sprintf("  %-30s %5d\n", $label, (int) ($row['n'] ?? 0));
}

echo "\nOK\n";
