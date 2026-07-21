<?php
declare(strict_types=1);

/**
 * api/v1/inspections/show.php
 *
 * Full inspection detail: header fields + sections[] (with section_data) + photos[].
 *
 * Business rules:
 *   - Requires ?id= query param.
 *   - Returns sections ordered by sort_order.
 *   - Returns photos ordered by sort_order, grouped by section_id.
 *   - section_data decoded from JSON string to object for client convenience.
 *   - pdf_path is NOT returned in response (Trap 7 — server path exposure).
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('inspections','view')
 * @returns 200 { inspection{}, sections[], photos[] }
 *
 * Decisions: D7 (routing), Trap 7 (never expose file_path/pdf_path)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('inspections', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) json_error('MISSING_REQUIRED', 'id is required.', 422);

// ── Fetch inspection with unit/template/lease labels
$insp = db_row(
    "SELECT
        i.id,
        i.inspection_number,
        i.inspection_type,
        i.status,
        i.inspection_date,
        i.overall_condition,
        i.condition_score,
        i.inspected_by,
        i.inspected_by_user_id,
        i.mileage_at_inspection,
        i.reefer_hours,
        i.fuel_level,
        i.cvi_expiry,
        i.is_clean,
        i.customer_signature,
        i.signed_at,
        i.notes,
        i.lease_id,
        i.equipment_unit_id,
        i.created_at,
        i.updated_at,
        eu.unit_number,
        eb.label AS brand,
        et.model,
        et.category  AS unit_type,
        l.contract_number,
        l.customer_id,
        c.company_name  AS customer_name,
        u.name          AS inspected_by_user_name
     FROM inspections i
     LEFT JOIN equipment_units     eu  ON eu.id = i.equipment_unit_id AND eu.deleted_at IS NULL
     LEFT JOIN equipment_templates et  ON et.id = eu.template_id      AND et.deleted_at IS NULL
     LEFT JOIN equipment_brands    eb  ON eb.id = eu.brand_id
     LEFT JOIN leases              l   ON l.id  = i.lease_id          AND l.deleted_at IS NULL
     LEFT JOIN customers           c   ON c.id  = l.customer_id       AND c.deleted_at IS NULL
     LEFT JOIN users               u   ON u.id  = i.inspected_by_user_id
     WHERE i.id = ?",
    [$id]
);

if (!$insp) json_error('NOT_FOUND', 'Inspection not found.', 404);

// ── Fetch sections ordered by sort_order
$sections = db_select(
    "SELECT id, inspection_id, section_name, `condition`, notes, section_data, sort_order
     FROM inspection_sections
     WHERE inspection_id = ?
     ORDER BY sort_order ASC",
    [$id]
);

// Decode section_data JSON for each section (stored as JSON string in DB)
foreach ($sections as &$sec) {
    if ($sec['section_data'] !== null) {
        $sec['section_data'] = json_decode($sec['section_data'], true);
    }
}
unset($sec);

// ── Fetch photos ordered by sort_order
$photos = db_select(
    "SELECT id, inspection_id, section_id, caption, sort_order, uploaded_at
     FROM inspection_photos
     WHERE inspection_id = ?
     ORDER BY sort_order ASC, id ASC",
    [$id]
    // NOTE: file_path excluded — Trap 7 (never expose server filesystem paths in API responses)
    // Photo serving via StorageClient pre-signed URL is a future enhancement
);

json_success([
    'inspection' => $insp,
    'sections'   => $sections,
    'photos'     => $photos,
]);
