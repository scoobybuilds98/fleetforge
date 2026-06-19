<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/show.php
 *
 * Return a single credit note with its full application history.
 * applications[] contains every credit_note_applications row for this note,
 * joined to the invoice number and status so the caller can render links.
 *
 * SOFT_DELETE: credit_notes and invoices both have deleted_at — filtered on both.
 * Trap 7: file_path / pdf_path are never returned in API responses.
 *
 * @method  GET
 * @query   id (required) — credit note ID
 * @auth    Session required; require_permission('invoices','view')
 * @returns 200 { ...credit_note, customer_name, applications: [...] }
 *
 * Decisions: D5, §5 Soft Delete, Trap 7
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// Load the credit note (never expose internal path columns)
$cn = db_row(
    "SELECT
        cn.id,
        cn.credit_note_number,
        cn.customer_id,
        c.company_name  AS customer_name,
        c.contact_name  AS customer_contact_name,
        cn.lease_id,
        cn.source,
        cn.source_invoice_id,
        cn.source_payment_id,
        cn.amount,
        cn.currency,
        cn.amount_remaining,
        cn.status,
        cn.expires_at,
        cn.reason,
        cn.internal_notes,
        cn.created_by,
        u.name          AS created_by_name,
        cn.voided_by,
        cn.voided_at,
        cn.created_at,
        cn.updated_at
     FROM credit_notes cn
     LEFT JOIN customers c ON c.id = cn.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u     ON u.id = cn.created_by
     WHERE cn.id = ? AND cn.deleted_at IS NULL",
    [$id]
);

if (!$cn) {
    json_error('NOT_FOUND', 'Credit note not found.', 404);
}

// Load application history — what invoices has this credit been applied to?
$applications = db_select(
    "SELECT
        cna.id,
        cna.credit_note_id,
        cna.invoice_id,
        i.invoice_number,
        i.status        AS invoice_status,
        i.total_amount  AS invoice_total,
        cna.amount_applied,
        cna.applied_by,
        u2.name         AS applied_by_name,
        cna.applied_at
     FROM credit_note_applications cna
     JOIN invoices i  ON i.id = cna.invoice_id AND i.deleted_at IS NULL
     LEFT JOIN users u2 ON u2.id = cna.applied_by
     WHERE cna.credit_note_id = ?
     ORDER BY cna.applied_at ASC",
    [$id]
);

$cn['applications'] = $applications;

// I03: strip credit-note dollar amounts (note + per-application) for roles
// without payments:view; operational fields (number, source, status, dates,
// linked invoice numbers) stay visible.
if (!can_view_financials()) {
    $cn = redact_keys($cn, ['amount', 'amount_remaining']);
    if (isset($cn['applications']) && is_array($cn['applications'])) {
        $cn['applications'] = redact_rows($cn['applications'], ['amount_applied', 'invoice_total']);
    }
}

json_success($cn);
