<?php
declare(strict_types=1);

/**
 * tests/_smoke_rate_card_conflict_guard.php
 *
 * S-RATES-CARD-CONFLICT-GUARD — schema-real regression smoke for
 * FleetForge\RateCards\ConflictGuard.
 *
 * WHY THIS EXISTS: rate_cards.customer_id has no unique key and the
 * equipment types live in the child table rate_card_items, so the
 * "one customer must not own two active cards covering the same
 * equipment type over an overlapping period" rule is enforced only in
 * PHP (create.php / update.php call ConflictGuard). This smoke seeds
 * real rows through the real schema, exercises the actual overlap SQL,
 * and asserts every branch: same/different type, overlapping vs
 * disjoint windows, open-ended windows, global exemption, self-exclude,
 * and different-customer isolation.
 *
 * Hermetic: all writes happen inside a single transaction that is rolled
 * back at the end — nothing is persisted.
 *
 * Run:  php tests/_smoke_rate_card_conflict_guard.php
 * Exit: 0 all pass, 1 on any failure.
 *
 * @session  S-RATES-CARD-CONFLICT-GUARD
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\RateCards\ConflictGuard;

$passes   = 0;
$failures = [];

/** Assert the conflict count for a scenario. */
$expectCount = static function (string $label, int $expected, array $conflicts) use (&$passes, &$failures): void {
    $actual = count($conflicts);
    if ($actual === $expected) {
        $passes++;
        fwrite(STDOUT, "  PASS  {$label} (got {$expected})\n");
    } else {
        $failures[] = "{$label}: expected {$expected}, got {$actual}";
        fwrite(STDOUT, "  FAIL  {$label}: expected {$expected}, got {$actual}\n");
    }
};

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── Seed ───────────────────────────────────────────────────────────────
    $custA = db_insert('customers', ['company_name' => 'ZZ Guard Test A']);
    $custB = db_insert('customers', ['company_name' => 'ZZ Guard Test B']);

    // Card 1: customer A, open-ended from 2025-01-01, carries dry_van.
    $card1 = db_insert('rate_cards', [
        'name'           => 'ZZ Guard Card 1',
        'customer_id'    => $custA,
        'effective_from' => '2025-01-01',
        'effective_to'   => null,
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', ['rate_card_id' => $card1, 'equipment_type' => 'dry_van']);

    // Card 2: customer A, CLOSED window 2025-01-01..2025-03-31, carries flatbed.
    $card2 = db_insert('rate_cards', [
        'name'           => 'ZZ Guard Card 2',
        'customer_id'    => $custA,
        'effective_from' => '2025-01-01',
        'effective_to'   => '2025-03-31',
        'created_by'     => null,
    ]);
    db_insert('rate_card_items', ['rate_card_id' => $card2, 'equipment_type' => 'flatbed']);

    fwrite(STDOUT, "Seeded custA={$custA} custB={$custB} card1={$card1} card2={$card2}\n\n");

    // ── 1. Same customer, same type, overlapping open-ended → CONFLICT ──────
    $expectCount(
        'overlap: dry_van vs open-ended card1',
        1,
        ConflictGuard::conflicts($custA, ['dry_van'], '2025-06-01', null)
    );

    // ── 2. Same customer, different type → no conflict ──────────────────────
    $expectCount(
        'different type: reefer',
        0,
        ConflictGuard::conflicts($custA, ['reefer'], '2025-06-01', null)
    );

    // ── 3. Same type, window ENTIRELY BEFORE card1 starts → no conflict ─────
    //    new [2024-01-01..2024-12-31] vs card1 starts 2025-01-01 → disjoint.
    $expectCount(
        'disjoint before: dry_van 2024',
        0,
        ConflictGuard::conflicts($custA, ['dry_van'], '2024-01-01', '2024-12-31')
    );

    // ── 4. Global card (customer_id NULL) is exempt ─────────────────────────
    $expectCount(
        'global exempt',
        0,
        ConflictGuard::conflicts(null, ['dry_van'], '2025-06-01', null)
    );

    // ── 5. Excluding self (update path) → no conflict ───────────────────────
    $expectCount(
        'self-exclude card1',
        0,
        ConflictGuard::conflicts($custA, ['dry_van'], '2025-06-01', null, $card1)
    );

    // ── 6. Different customer → isolated ────────────────────────────────────
    $expectCount(
        'different customer B',
        0,
        ConflictGuard::conflicts($custB, ['dry_van'], '2025-06-01', null)
    );

    // ── 7. CLOSED existing window — new window AFTER it ends → no conflict ──
    //    card2 flatbed ends 2025-03-31; new flatbed 2025-04-01.. → disjoint.
    $expectCount(
        'disjoint after closed: flatbed 2025-04-01',
        0,
        ConflictGuard::conflicts($custA, ['flatbed'], '2025-04-01', null)
    );

    // ── 8. CLOSED existing window — new window OVERLAPS it → conflict ───────
    //    card2 flatbed 2025-01-01..2025-03-31; new flatbed 2025-03-01.. → overlap.
    $expectCount(
        'overlap closed: flatbed 2025-03-01',
        1,
        ConflictGuard::conflicts($custA, ['flatbed'], '2025-03-01', null)
    );

    // ── 9. Multiple types, only one collides → exactly one conflict row ─────
    $expectCount(
        'mixed batch: [dry_van, reefer]',
        1,
        ConflictGuard::conflicts($custA, ['dry_van', 'reefer'], '2025-06-01', null)
    );

    // ── 10. Conflict row carries card id + name for the error message ───────
    $c = ConflictGuard::conflicts($custA, ['dry_van'], '2025-06-01', null);
    if ($c && (int)$c[0]['card_id'] === $card1 && $c[0]['card_name'] === 'ZZ Guard Card 1'
        && str_contains(ConflictGuard::message($c), 'dry van')) {
        $passes++;
        fwrite(STDOUT, "  PASS  conflict row annotated with card id/name + message\n");
    } else {
        $failures[] = 'conflict row annotation/message malformed';
        fwrite(STDOUT, "  FAIL  conflict row annotation/message malformed\n");
    }

} finally {
    $pdo->rollBack();
}

fwrite(STDOUT, "\n" . ($failures ? count($failures) . " FAILURE(S)\n" : "ALL {$passes} CHECKS PASSED\n"));
foreach ($failures as $f) {
    fwrite(STDOUT, "  - {$f}\n");
}
exit($failures ? 1 : 0);
