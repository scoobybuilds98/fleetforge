<?php
declare(strict_types=1);

/**
 * FleetForge — Credit Applications List API
 *
 * @file        api/v1/credit_applications/index.php
 * @description Lists the credit-application history for one customer, newest
 *              first (D-CCA-3 — each send is a new row; full history retained;
 *              soft-deleted rows excluded). Drives the "Credit Application" tab
 *              on the customer profile page.
 *
 *              Trap 7: NEVER emits token_hash, signature_path, raw form_data,
 *              rendered_html, or the applicant IP/User-Agent. The public link
 *              token is a secret known only to the recipient — it is emailed
 *              once and never surfaced through the API.
 *
 * @method      GET
 * @query       customer_id (required int)
 * @auth        Session required; require_permission('customers','view')
 *              (credit applications are a customer sub-resource, like documents
 *              and notes — gated on the customers module, S-CCA-1 Q1)
 * @returns     200 { items: [ { id, status, review_outcome, print_name_first,
 *                    print_name_last, signed_date, approved_credit_limit,
 *                    review_notes, token_expires_at, is_expired, has_pdf,
 *                    pdf_document_id, sent_at, opened_at, submitted_at,
 *                    reviewed_at, created_at, sent_by_name, reviewed_by_name } ] }
 *              400 if customer_id missing/invalid
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md (§ customer portal, § documents)
 * @session     S-CCA-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId || $customerId <= 0) {
    json_error('INVALID_ID', 'A valid customer_id is required.', 400);
}

// ── Fetch history (newest first; soft-deleted excluded per D-CCA-3) ─────────
// SELECT is column-explicit — Trap 7: token_hash / signature_path / form_data /
// rendered_html / submitted_ip / submitted_user_agent are deliberately absent.
$rows = db_select(
    "SELECT ca.id, ca.status, ca.review_outcome,
            ca.print_name_first, ca.print_name_last, ca.signed_date,
            ca.approved_credit_limit, ca.review_notes,
            ca.token_expires_at, ca.generated_pdf_document_id,
            ca.sent_at, ca.opened_at, ca.submitted_at, ca.reviewed_at,
            ca.created_at,
            sb.name AS sent_by_name,
            rb.name AS reviewed_by_name
       FROM customer_credit_applications ca
       LEFT JOIN users sb ON sb.id = ca.sent_by
       LEFT JOIN users rb ON rb.id = ca.reviewed_by
      WHERE ca.customer_id = ?
        AND ca.deleted_at IS NULL
      ORDER BY ca.created_at DESC, ca.id DESC",
    [$customerId]
);

$now = time();
foreach ($rows as &$row) {
    // Derived: a 'sent' link past its expiry can no longer be opened.
    $row['is_expired'] = ($row['status'] === 'sent'
        && !empty($row['token_expires_at'])
        && strtotime((string) $row['token_expires_at']) < $now);
    // has_pdf drives the (S-CCA-3-wired) View link without exposing storage paths.
    $row['has_pdf']         = $row['generated_pdf_document_id'] !== null;
    $row['pdf_document_id'] = $row['generated_pdf_document_id'] !== null
        ? (int) $row['generated_pdf_document_id']
        : null;
    unset($row['generated_pdf_document_id']);
}
unset($row);

json_success(['items' => $rows]);
