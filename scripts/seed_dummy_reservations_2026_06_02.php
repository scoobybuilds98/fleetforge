<?php
declare(strict_types=1);

/**
 * scripts/seed_dummy_reservations_2026_06_02.php
 *
 * Creates 14 realistic dummy reservations spread across all 11 active
 * customers in the dev database. Run once on a clean (zero-reservation) DB.
 *
 * Coverage:
 *   Statuses   — 5 confirmed · 4 pending · 2 completed · 2 cancelled · 1 pending(manual unit)
 *   Customers  — all 11 active customers (#2–#12); customer #1 is suspended, skipped
 *   Equipment  — dry vans, reefers, flatbeds, step deck, tractor; mix of single + multi-unit
 *   Priorities — urgent, high, medium, low
 *
 * Side-effects (mirrors API behaviour):
 *   confirmed reservations mark their linked unit(s) status → 'reserved'
 *   completed / cancelled reservations leave units at 'available' (no change needed)
 *
 * Idempotent guard: refuses to run if any reservations already exist.
 *
 * USAGE:
 *   php scripts/seed_dummy_reservations_2026_06_02.php
 */

require_once realpath(__DIR__ . '/../config/app.php');

// ── Idempotent guard ──────────────────────────────────────────────────────────
$existing = db_count('SELECT COUNT(*) FROM reservations WHERE deleted_at IS NULL');
if ($existing > 0) {
    echo "⚠  Reservations already exist ({$existing} rows). Nothing inserted.\n";
    exit(0);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$adminId = (int) (db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 1);

/** Pull a customer row (id, company_name, contact_name, email, phone) */
function cust(int $id): array
{
    $r = db_row("SELECT id, company_name, contact_name, email, phone FROM customers WHERE id = ?", [$id]);
    if (!$r) throw new \RuntimeException("Customer #{$id} not found");
    return $r;
}

/** Pull a unit row (id, unit_number, status, yard_location, template name) */
function unit(int $id): array
{
    $r = db_row(
        "SELECT eu.id, eu.unit_number, eu.status, eu.yard_location, et.name AS template
           FROM equipment_units eu
           JOIN equipment_templates et ON et.id = eu.template_id
          WHERE eu.id = ?",
        [$id]
    );
    if (!$r) throw new \RuntimeException("Unit #{$id} not found");
    return $r;
}

$inserted = 0;
$reserved = 0;

// ── Reservation definitions ───────────────────────────────────────────────────
// Each entry: [customer_id, status, pickup_date, pickup_time|null, priority, purpose, notes,
//              created_offset_days (negative = days before today),
//              units_array: [{unit_id|null, snapshot, template_snapshot, entry_type}]]
//
// unit_id = null → manual entry (equipment not tracked in system)

$defs = [

    // ── 1. Coastal Haul Logistics — Confirmed, Dry Van, high ──────────────────
    [
        'customer_id'  => 2,
        'status'       => 'confirmed',
        'pickup_date'  => '2026-06-10',
        'pickup_time'  => '08:00:00',
        'priority'     => 'high',
        'purpose'      => 'Long-haul retail distribution run to the Alberta border crossing',
        'notes'        => 'Customer requested early morning pickup. Load pre-staged by 07:30.',
        'created_days' => -3,
        'units'        => [
            ['unit_id' => 2, 'entry_type' => 'system'],   // T5302 — 53' Dry Van
        ],
    ],

    // ── 2. Pacific Rim Transport — Pending, Reefer, medium ────────────────────
    [
        'customer_id'  => 3,
        'status'       => 'pending',
        'pickup_date'  => '2026-06-09',
        'pickup_time'  => '09:00:00',
        'priority'     => 'medium',
        'purpose'      => 'Cold-chain pharmaceutical shipment — awaiting customs clearance confirmation',
        'notes'        => 'Requires temperature log documentation. Awaiting final PO from customer.',
        'created_days' => -5,
        'units'        => [
            ['unit_id' => 11, 'entry_type' => 'system'],  // T6203 — 53' Reefer
        ],
    ],

    // ── 3. Cascade Carriers — Confirmed, 2× Dry Van, medium ──────────────────
    [
        'customer_id'  => 4,
        'status'       => 'confirmed',
        'pickup_date'  => '2026-06-16',
        'pickup_time'  => '07:30:00',
        'priority'     => 'medium',
        'purpose'      => 'Two-trailer pull for big-box retailer distribution centre — consolidation load',
        'notes'        => 'Both trailers dispatched together. Bill of lading covers entire consignment.',
        'created_days' => -1,
        'units'        => [
            ['unit_id' => 3, 'entry_type' => 'system'],   // T5303 — 53' Dry Van
            ['unit_id' => 4, 'entry_type' => 'system'],   // T5304 — 53' Dry Van
        ],
    ],

    // ── 4. Sumas Mountain Hauling — Pending, Flatbed, low ────────────────────
    [
        'customer_id'  => 5,
        'status'       => 'pending',
        'pickup_date'  => '2026-06-13',
        'pickup_time'  => '10:00:00',
        'priority'     => 'low',
        'purpose'      => 'Agricultural equipment transport — combine header move to Abbotsford farm',
        'notes'        => 'Oversized load permit may be required. Customer confirming dimensions.',
        'created_days' => 0,
        'units'        => [
            ['unit_id' => 16, 'entry_type' => 'system'],  // T4803 — 53' Flatbed
        ],
    ],

    // ── 5. Delta Port Drayage — Confirmed, Reefer, urgent ────────────────────
    [
        'customer_id'  => 6,
        'status'       => 'confirmed',
        'pickup_date'  => '2026-06-07',
        'pickup_time'  => '06:00:00',
        'priority'     => 'urgent',
        'purpose'      => 'Perishable seafood consignment — time-sensitive port pickup, vessel ETA 05:45',
        'notes'        => 'Pre-clearance approved. Driver must arrive 15 min before scheduled pickup.',
        'internal_notes' => 'VIP account — direct to Amrit if any issues arise.',
        'created_days' => -1,
        'units'        => [
            ['unit_id' => 10, 'entry_type' => 'system'],  // T6202 — 53' Reefer
        ],
    ],

    // ── 6. Interior Freight Systems — Confirmed, Step Deck, medium ───────────
    [
        'customer_id'  => 7,
        'status'       => 'confirmed',
        'pickup_date'  => '2026-06-22',
        'pickup_time'  => '08:00:00',
        'priority'     => 'medium',
        'purpose'      => 'Oversized industrial machinery move to Kamloops distribution hub',
        'notes'        => 'Escort vehicle arranged by customer. Strapping and tarping required.',
        'created_days' => -1,
        'units'        => [
            ['unit_id' => 19, 'entry_type' => 'system'],  // T3502 — Step Deck
        ],
    ],

    // ── 7. Cariboo Bulk Transport — Pending, Day Cab Tractor, high ───────────
    [
        'customer_id'  => 8,
        'status'       => 'pending',
        'pickup_date'  => '2026-06-20',
        'pickup_time'  => '07:00:00',
        'priority'     => 'high',
        'purpose'      => 'Day-cab provision for short-haul runs within the Lower Mainland — 5-day contract',
        'notes'        => 'Driver supply TBD. Customer may bring own operator.',
        'created_days' => 0,
        'units'        => [
            ['unit_id' => 22, 'entry_type' => 'system'],  // P1002 — Day Cab Tractor
        ],
    ],

    // ── 8. Westshore Logistics — Completed (past), Reefer, medium ────────────
    [
        'customer_id'  => 9,
        'status'       => 'completed',
        'pickup_date'  => '2026-05-15',
        'pickup_time'  => '08:00:00',
        'priority'     => 'medium',
        'purpose'      => 'Cold-storage overflow for spring produce season — Fraser Valley harvest',
        'notes'        => 'Completed on schedule. Customer satisfied.',
        'created_days' => -18,
        'units'        => [
            ['unit_id' => 12, 'entry_type' => 'system'],  // T6204 — 53' Reefer
        ],
    ],

    // ── 9. Okanagan Cartage — Completed (past), Flatbed, low ─────────────────
    [
        'customer_id'  => 10,
        'status'       => 'completed',
        'pickup_date'  => '2026-05-08',
        'pickup_time'  => '09:00:00',
        'priority'     => 'low',
        'purpose'      => 'Vineyard equipment relocation — tractor & press move to Kelowna winery',
        'notes'        => 'Completed. Invoice #4482 issued same day.',
        'created_days' => -25,
        'units'        => [
            ['unit_id' => 17, 'entry_type' => 'system'],  // T4804 — 53' Flatbed
        ],
    ],

    // ── 10. Pitt River Hauling — Confirmed, Flatbed, high ────────────────────
    [
        'customer_id'  => 11,
        'status'       => 'confirmed',
        'pickup_date'  => '2026-06-28',
        'pickup_time'  => '08:00:00',
        'priority'     => 'high',
        'purpose'      => 'Lumber delivery to residential construction site — multi-drop within Tri-Cities',
        'notes'        => 'Three drop points confirmed. Customer supplying unloading crew.',
        'created_days' => 0,
        'units'        => [
            ['unit_id' => 15, 'entry_type' => 'system'],  // T4802 — 53' Flatbed
        ],
    ],

    // ── 11. Sea-to-Sky Freight — Pending, manual unit entry, low ─────────────
    // Customer is providing their own trailer — unit not in the FF system.
    // Uses entry_type='manual' so no equipment_unit_id is linked.
    [
        'customer_id'  => 12,
        'status'       => 'pending',
        'pickup_date'  => '2026-07-03',
        'pickup_time'  => '10:00:00',
        'priority'     => 'low',
        'purpose'      => 'Summer overflow capacity — seasonal mountain resort supply chain',
        'notes'        => 'Customer supplying their own 53\' dry van (unit SSF-44). Spot booked for yard space + driver.',
        'created_days' => 0,
        'units'        => [
            // Manual entry — customer's own unit, not in our fleet
            ['unit_id' => null, 'entry_type' => 'manual', 'snapshot' => 'SSF-44', 'template_snapshot' => '53\' Dry Van Trailer'],
        ],
    ],

    // ── 12. Coastal Haul Logistics — Cancelled (past), Dry Van, medium ───────
    [
        'customer_id'  => 2,
        'status'       => 'cancelled',
        'pickup_date'  => '2026-05-28',
        'pickup_time'  => null,
        'priority'     => 'medium',
        'purpose'      => 'Retail distribution run — Lower Mainland to Kelowna',
        'notes'        => 'Cancelled by customer — route change. Will rebook Q3.',
        'created_days' => -15,
        'units'        => [
            ['unit_id' => 6, 'entry_type' => 'system'],   // T5306 — 53' Dry Van
        ],
    ],

    // ── 13. Pacific Rim Transport — Cancelled (past), Reefer, low ────────────
    [
        'customer_id'  => 3,
        'status'       => 'cancelled',
        'pickup_date'  => '2026-06-04',
        'pickup_time'  => null,
        'priority'     => 'low',
        'purpose'      => 'Cold chain produce run — cancelled pending new logistics contract',
        'notes'        => 'Customer postponed indefinitely. No charge.',
        'created_days' => -8,
        'units'        => [
            ['unit_id' => 13, 'entry_type' => 'system'],  // T6205 — 53' Reefer
        ],
    ],

    // ── 14. Interior Freight Systems — Pending, Dry Van, medium ──────────────
    // Second booking for same customer — supplementary to the step-deck run
    [
        'customer_id'  => 7,
        'status'       => 'pending',
        'pickup_date'  => '2026-06-30',
        'pickup_time'  => '09:00:00',
        'priority'     => 'medium',
        'purpose'      => 'Dry goods run supplementing the step-deck booking — packaged consumer goods',
        'notes'        => null,
        'created_days' => 0,
        'units'        => [
            ['unit_id' => 7, 'entry_type' => 'system'],   // T5307 — 53' Dry Van
        ],
    ],
];

// ── Insert ────────────────────────────────────────────────────────────────────
echo "Inserting dummy reservations…\n";
echo str_repeat('─', 80) . "\n";

try {
    db_transaction(function () use ($defs, $adminId, &$inserted, &$reserved) {

        foreach ($defs as $i => $def) {
            $c = cust($def['customer_id']);

            $createdAt = date('Y-m-d H:i:s', strtotime("+{$def['created_days']} days"));
            $qty       = count($def['units']);

            // ── Insert reservation row ─────────────────────────────────────
            $rid = db_insert('reservations', [
                'status'         => $def['status'],
                'customer_id'    => $def['customer_id'],
                'contact_name'   => $c['contact_name'] ?? $c['company_name'],
                'company_name'   => $c['company_name'],
                'contact_phone'  => $c['phone']  ?? null,
                'contact_email'  => $c['email']  ?? null,
                'quantity'       => $qty,
                'pickup_date'    => $def['pickup_date'],
                'pickup_time'    => $def['pickup_time'] ?? null,
                'yard_location'  => 'Surrey Yard',
                'purpose'        => $def['purpose']  ?? null,
                'priority'       => $def['priority'],
                'notes'          => $def['notes']          ?? null,
                'internal_notes' => $def['internal_notes'] ?? null,
                'created_by'     => $adminId,
                'updated_by'     => $adminId,
                'created_at'     => $createdAt,
                'updated_at'     => $createdAt,
            ]);

            $unitLabels = [];

            // ── Insert reservation_units rows ──────────────────────────────
            foreach ($def['units'] as $uDef) {
                $entryType = $uDef['entry_type'] ?? 'system';

                if ($entryType === 'manual' || $uDef['unit_id'] === null) {
                    // Manual entry — no equipment_unit_id
                    db_insert('reservation_units', [
                        'reservation_id'         => $rid,
                        'equipment_unit_id'      => null,
                        'unit_number_snapshot'   => $uDef['snapshot']          ?? 'UNKNOWN',
                        'template_name_snapshot' => $uDef['template_snapshot'] ?? null,
                        'status_at_reservation'  => null,
                        'entry_type'             => 'manual',
                        'created_at'             => $createdAt,
                    ]);
                    $unitLabels[] = ($uDef['snapshot'] ?? 'MANUAL') . ' [manual]';
                } else {
                    $u = unit((int) $uDef['unit_id']);
                    db_insert('reservation_units', [
                        'reservation_id'         => $rid,
                        'equipment_unit_id'      => $u['id'],
                        'unit_number_snapshot'   => $u['unit_number'],
                        'template_name_snapshot' => $u['template'],
                        'status_at_reservation'  => $u['status'],
                        'entry_type'             => 'system',
                        'created_at'             => $createdAt,
                    ]);
                    $unitLabels[] = $u['unit_number'];

                    // Mark unit 'reserved' for confirmed reservations (mirrors API)
                    if ($def['status'] === 'confirmed' && $u['status'] === 'available') {
                        db_execute(
                            "UPDATE equipment_units SET status = 'reserved', updated_at = NOW() WHERE id = ?",
                            [$u['id']]
                        );
                        $reserved++;
                    }
                }
            }

            $num = $i + 1;
            printf(
                "  #%-2d %-10s %-36s %s  qty=%-2s  %-8s  [%s]\n",
                $num,
                $def['status'],
                $c['company_name'],
                $def['pickup_date'],
                $qty,
                $def['priority'],
                implode(', ', $unitLabels)
            );

            $inserted++;
        }
    });

    echo str_repeat('─', 80) . "\n";
    printf("✓ Inserted %d reservations  ·  %d unit(s) marked 'reserved'\n", $inserted, $reserved);

} catch (\Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}
