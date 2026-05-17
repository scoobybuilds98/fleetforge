<?php
declare(strict_types=1);

/**
 * tests/helpers/Fixtures.php
 *
 * Lease/customer/rate fixture loader + DB creator helpers.
 *
 * Fixtures live as JSON under tests/fixtures/. Each fixture is a partial
 * row that gets merged with sensible defaults at DB-insert time.
 *
 * Signatures match spec Appendix D.
 *
 * @session S-BILLING-TESTS-ADVERSARIAL
 * @spec    FleetForge_Holistic_Billing_Engine_Test_Spec.docx §37 + Appendix C, D
 */

namespace FleetForge\Tests;

class Fixtures
{
    private static array $cache = [];

    /** Load a fixture from JSON by name (e.g. 'standard_monthly' from leases.json). */
    public static function load(string $name): array
    {
        // Try each fixture file in turn
        foreach (['leases', 'customers', 'rates'] as $file) {
            $data = self::loadFile($file);
            if (isset($data[$name])) {
                return $data[$name];
            }
        }
        throw new \RuntimeException("Fixture '$name' not found in any tests/fixtures/*.json file");
    }

    /** Create a test customer with given overrides on top of standard_bc defaults. */
    public static function createCustomer(array $overrides = []): int
    {
        $base = self::load('standard_bc');
        $data = array_merge($base, $overrides);
        $data['contact_name'] = ($data['contact_name'] ?? 'Test') . ' ' . uniqid();
        $data['email']        = uniqid('test-', true) . '@example.test';
        return db_insert('customers', $data);
    }

    /**
     * Create a test lease for the given customer.
     *
     * Merges defaults from the 'standard_monthly' fixture, then applies
     * the caller's overrides, then auto-fills required columns
     * (contract_number, equipment_unit_id, snapshots).
     */
    public static function createLease(int $customerId, array $overrides = []): int
    {
        $base = self::load('standard_monthly');
        $data = array_merge($base, $overrides);

        // Required defaults
        $data['contract_number']         ??= 'TEST-' . uniqid();
        $data['customer_id']             = $customerId;
        $data['equipment_unit_id']       ??= self::firstEquipmentUnit();
        $data['customer_name_snapshot']  ??= 'Test Cust';
        $data['company_name_snapshot']   ??= 'Test Co';
        $data['unit_number_snapshot']    ??= 'TEST-U';
        $data['start_date']              ??= '2026-03-28';
        $data['status']                  ??= 'active';
        $data['mileage_unit']            ??= 'km';
        $data['mileage_rate']            ??= '0.0000';
        $data['mileage_rate_km']         ??= '0.0000';
        $data['estimated_mileage']       ??= '0.00';
        $data['estimated_mileage_km']    ??= '0.000';
        $data['tax_exempt']              ??= 0;
        $data['gst_exempt']              ??= 0;
        $data['pst_exempt']              ??= 0;
        $data['discount_type']           ??= 'none';
        $data['discount_value']          ??= '0.0000';
        $data['insurance_opt_in']        ??= 0;
        $data['insurance_cost']          ??= '0.00';
        $data['warranty_opt_in']         ??= 0;
        $data['warranty_cost']           ??= '0.00';
        $data['advance_billing_periods'] ??= 0;
        $data['precharge_enabled']       ??= 0;
        $data['gps_opt_in']              ??= 0;
        $data['gps_cost']                ??= '0.00';

        return db_insert('leases', $data);
    }

    /**
     * Create a customer+lease pair from named fixtures, returning lease_id.
     * Convenience for the many tests that need both with no customization.
     */
    public static function createFromFixture(string $leaseFixture, ?string $customerFixture = null, array $overrides = []): int
    {
        $custFixture = $customerFixture
            ? self::load($customerFixture)
            : self::load('standard_bc');
        $custId = self::createCustomer($custFixture);

        $lease = array_merge(self::load($leaseFixture), $overrides);
        return self::createLease($custId, $lease);
    }

    /** Generate an invoice via the engine. Thin wrapper for test ergonomics. */
    public static function generateInvoice(int $leaseId, string $periodStart, string $periodEnd, string $billingType = 'partial_start', array $params = []): array
    {
        $gen = new \FleetForge\Billing\InvoiceGenerator();
        return $gen->createFromLease(array_merge([
            'lease_id'     => $leaseId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'billing_type' => $billingType,
            'invoice_type' => 'regular',
        ], $params));
    }

    private static function loadFile(string $name): array
    {
        if (isset(self::$cache[$name])) return self::$cache[$name];
        $path = dirname(__DIR__) . "/fixtures/$name.json";
        if (!is_readable($path)) return self::$cache[$name] = [];
        $data = json_decode((string)file_get_contents($path), true);
        return self::$cache[$name] = is_array($data) ? $data : [];
    }

    private static function firstEquipmentUnit(): int
    {
        $row = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
        return (int)($row['id'] ?? 0);
    }
}
