<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/test_connection.php
 *
 * GET endpoint. Validates the active QBO connection by calling
 * QuickBooksClient::getCompanyInfo(), which (as of S-QBO-2) hits
 * /v3/company/{realmId}/companyinfo/{realmId} for real. Reflects
 * the result back to the UI so the operator gets a green
 * "Connected as <Company Name>" inline message.
 *
 * connection_status side-effects are handled implicitly by the
 * client — refreshAccessToken() sets 'connected' on success and
 * 'expired'/'error' on failure, so by the time this endpoint
 * surfaces success the status row is already correct.
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { success: true, company_name: string, realm_id: string }
 *        | 200 { success: false, error: string }
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5 (connection diagnostics),
 *           §6.6 (QuickBooksClient surface)
 * Session:  S-QBO-1 (stub) → S-QBO-2 (real call)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

try {
    $client = new QuickBooksClient();
    $info   = $client->getCompanyInfo();
    json_success($info);
} catch (\RuntimeException $e) {
    // ensureValidToken() throws RuntimeException when the token is
    // missing or unrefreshable — bubble the message back to UI.
    // Do NOT call json_error() — the front-end interprets HTTP 4xx
    // as transport failures; we want a {success:false} payload so
    // the Alpine handler can render an inline error panel.
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}
