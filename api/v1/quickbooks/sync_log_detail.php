<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/sync_log_detail.php
 *
 * Single-row detail backing the Sync Log detail modal. Returns the
 * full row INCLUDING request_payload + response_payload — BUT both
 * payload fields are nullified to '[REDACTED — requires
 * quickbooks.view_raw_payloads]' unless the caller holds that
 * extended permission.
 *
 * Defense in depth: even though QuickBooksClient::scrubRequestForLog()
 * already strips Authorization headers before persistence (S-QBO-2),
 * this endpoint additionally walks the persisted JSON one more time
 * to scrub any auth-shaped keys before returning. Belt-and-suspenders
 * for the case where a payload arrived via some other code path that
 * forgot to scrub.
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { row: {...} } — with raw payloads gated
 *        | 404 NOT_FOUND
 *
 * Spec ref: §13.4 (operator-visible error display), §13.5 (Sentry scrub)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    json_validation_error(['id' => 'Provide id (positive integer).']);
}

try {
    $row = db_row(
        "SELECT id, created_at, direction, entity_type, entity_id, qbo_entity_id,
                operation, http_method, endpoint, request_payload, response_status,
                response_payload, duration_ms, error_code, error_message,
                user_id, queue_id, realm_id, environment
           FROM acc_qbo_sync_log
          WHERE id = ?",
        [$id]
    );

    if (!$row) {
        json_error('NOT_FOUND', "Sync log row #{$id} not found.", 404);
    }

    // Payload gate.
    $canViewPayloads = can('quickbooks', 'view_raw_payloads');
    if (!$canViewPayloads) {
        $row['request_payload']  = $row['request_payload']  !== null ? '[REDACTED — requires quickbooks.view_raw_payloads]' : null;
        $row['response_payload'] = $row['response_payload'] !== null ? '[REDACTED — requires quickbooks.view_raw_payloads]' : null;
    } else {
        // Defense in depth: walk the payloads once more and scrub any
        // Authorization-shaped keys that may have slipped past
        // QuickBooksClient::scrubRequestForLog. The persisted strings
        // are JSON; decode, scrub, re-encode.
        $row['request_payload']  = redactAuthInJson($row['request_payload']);
        $row['response_payload'] = redactAuthInJson($row['response_payload']);
    }

    json_success(['row' => $row]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Detail fetch failed: ' . $e->getMessage(), 500);
}

/**
 * redactAuthInJson — JSON-walk + redact authorization/password/secret/
 * token keys. Returns null when input is null; returns the original
 * string unchanged if it's not parseable JSON (don't break display on
 * malformed historical rows).
 */
function redactAuthInJson(?string $jsonStr): ?string
{
    if ($jsonStr === null || $jsonStr === '') {
        return $jsonStr;
    }
    $decoded = json_decode($jsonStr, true);
    if (!is_array($decoded)) {
        return $jsonStr;
    }
    $walked = redactAuthKeys($decoded);
    $out = json_encode($walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $out === false ? $jsonStr : $out;
}

function redactAuthKeys(array $data): array
{
    foreach ($data as $key => $value) {
        if (preg_match('/authorization|password|secret|access_token|refresh_token|client_secret|webhook_verifier/i', (string) $key) === 1) {
            $data[$key] = '[REDACTED]';
            continue;
        }
        if (is_array($value)) {
            $data[$key] = redactAuthKeys($value);
        }
    }
    return $data;
}
