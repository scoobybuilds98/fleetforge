<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/show.php
 *
 * Return full detail for a single damage claim, including:
 *   - claim fields
 *   - photos[] — each photo has a signed URL via StorageClient::url()
 *     (file_path is NEVER returned — Trap 7)
 *   - related entity names (unit_number, customer, lease contract_number)
 *
 * SOFT_DELETE: damage_claims has deleted_at — AND dc.deleted_at IS NULL.
 *
 * @method  GET
 * @param   id (query string)
 * @auth    Session required; require_permission('maintenance','view')
 * @returns 200 { claim fields, photos: [] }
 *
 * Decisions: D5 (soft delete), D9 (StorageClient — signed URL for photos)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Storage\StorageClient;

require_method('GET');
require_auth_api();
require_permission('maintenance', 'view');

// -----------------------------------------------------------------------
// 1. Load claim
// -----------------------------------------------------------------------
$claimId = clean_int($_GET['id'] ?? null);
if (!$claimId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$claim = db_row(
    "SELECT
        dc.id,
        dc.claim_number,
        dc.equipment_unit_id,
        eu.unit_number,
        eu.year,
        et.brand,
        et.model,
        dc.lease_id,
        l.contract_number,
        dc.customer_id,
        c.company_name       AS customer_name,
        dc.inspection_id,
        dc.work_order_id,
        dc.invoice_id,
        dc.description,
        dc.damage_location,
        dc.severity,
        dc.estimated_repair_cost,
        dc.actual_repair_cost,
        dc.customer_liable_amount,
        dc.insurance_claim_amount,
        dc.status,
        dc.notes,
        dc.resolution_notes,
        dc.reported_by,
        u.name               AS reported_by_name,
        dc.created_at,
        dc.updated_at
     FROM damage_claims dc
     LEFT JOIN equipment_units     eu ON eu.id = dc.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et ON et.id = eu.template_id        AND et.deleted_at IS NULL
     LEFT JOIN customers c            ON c.id  = dc.customer_id        AND c.deleted_at  IS NULL
     LEFT JOIN leases l           ON l.id  = dc.lease_id          AND l.deleted_at  IS NULL
     LEFT JOIN users u            ON u.id  = dc.reported_by
     WHERE dc.id = ? AND dc.deleted_at IS NULL",
    [$claimId]
);

if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Load photos — serve signed URLs, never raw file_path (Trap 7)
// -----------------------------------------------------------------------
$photoRows = db_select(
    "SELECT id, claim_id, photo_type, caption, uploaded_at, file_path
     FROM damage_claim_photos
     WHERE claim_id = ?
     ORDER BY uploaded_at ASC",
    [$claimId]
);

$photos = [];
foreach ($photoRows as $p) {
    $photos[] = [
        'id'          => (int) $p['id'],
        'claim_id'    => (int) $p['claim_id'],
        'photo_type'  => $p['photo_type'],
        'caption'     => $p['caption'],
        'uploaded_at' => $p['uploaded_at'],
        // Signed URL — never the raw server path
        'url'         => StorageClient::url($p['file_path']),
    ];
}

$claim['photos'] = $photos;

json_success($claim);
