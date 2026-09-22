<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/business_tagging.php
 *
 * Business tagging for a SHARED QuickBooks company file (several businesses
 * in one QBO company). Chooses the rental business's QuickBooks Class and/or
 * Location, which QboTagging stamps on every document FleetForge pushes, and
 * the shared-file flag that keeps the drift check to FleetForge's own
 * records.
 *
 * GET  → current choice + the company preferences FleetForge read
 *        (CompanyInfoSync::syncPreferences). With ?load=1 it also refreshes
 *        those preferences and returns QuickBooks' live Class and Location
 *        (Department) lists for the pickers.
 * POST → { class_id, class_name, location_id, location_name,
 *          shared_company_file: '0'|'1', push_from_date: 'Y-m-d'|'' }
 *        — ids '' clear the tag; push_from_date '' = the go-live day
 *        (InvoiceLinker::pushFromDay — nothing dated earlier is pushed as new).
 *
 * @auth GET quickbooks.view; POST quickbooks.edit_credentials
 * @session S-QBO-GOLIVE-AUDIT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;
use FleetForge\QboPushers\CompanyInfoSync;

require_auth_api();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('quickbooks', 'edit_credentials');
    $body = json_body();

    $clean = static function ($v, int $max): string {
        return substr(trim((string) ($v ?? '')), 0, $max);
    };
    $classId  = $clean($body['class_id'] ?? '', 50);
    $locId    = $clean($body['location_id'] ?? '', 50);
    $shared   = (string) ($body['shared_company_file'] ?? '1');
    $pushFromRaw = trim((string) ($body['push_from_date'] ?? ''));
    $pushFrom = $pushFromRaw === '' ? '' : (string) (clean_date($pushFromRaw) ?? '');
    $errors = [];
    if ($pushFromRaw !== '' && $pushFrom === '') {
        $errors['push_from_date'] = 'Enter a valid date (YYYY-MM-DD) or leave it empty.';
    }
    foreach (['class_id' => $classId, 'location_id' => $locId] as $k => $v) {
        if ($v !== '' && !ctype_digit($v)) {
            $errors[$k] = 'Pick a value from the QuickBooks list.';
        }
    }
    if (!in_array($shared, ['0', '1'], true)) {
        $errors['shared_company_file'] = "Must be '0' or '1'.";
    }
    if ($errors !== []) {
        json_validation_error($errors);
    }

    $applied = [
        'class_id'            => $classId,
        'class_name'          => $classId === '' ? '' : $clean($body['class_name'] ?? '', 255),
        'location_id'         => $locId,
        'location_name'       => $locId === '' ? '' : $clean($body['location_name'] ?? '', 255),
        'shared_company_file' => $shared,
        'push_from_date'      => $pushFrom,
    ];
    db_transaction(function () use ($applied): void {
        foreach ($applied as $k => $v) {
            QuickBooksClient::settings_write_qbo($k, $v);
        }
        $user = current_user();
        db_insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'user_name'   => $user['name'] ?? 'system',
            'action'      => 'update',
            'module'      => 'quickbooks',
            'entity_type' => 'qbo_business_tagging',
            'notes'       => 'QBO business tagging updated: ' . json_encode($applied),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    });
    json_success(['applied' => $applied]);
}

require_method('GET');
require_permission('quickbooks', 'view');

$out = [
    'class_id'            => (string) settings_get('quickbooks.class_id', ''),
    'class_name'          => (string) settings_get('quickbooks.class_name', ''),
    'location_id'         => (string) settings_get('quickbooks.location_id', ''),
    'location_name'       => (string) settings_get('quickbooks.location_name', ''),
    'shared_company_file' => (string) settings_get('quickbooks.shared_company_file', '1'),
    'push_from_date'      => (string) settings_get('quickbooks.push_from_date', ''),
    'go_live_day'         => null,
    'classes'             => [],
    'locations'           => [],
    'load_error'          => null,
];

if (!empty($_GET['load'])) {
    try {
        $client = new QuickBooksClient();
        CompanyInfoSync::syncPreferences($client);
        foreach (['Class' => 'classes', 'Department' => 'locations'] as $entity => $key) {
            $resp = $client->query("SELECT * FROM {$entity} WHERE Active = true MAXRESULTS 1000", ['entity_type' => strtolower($entity)]);
            foreach ($resp['QueryResponse'][$entity] ?? [] as $row) {
                $out[$key][] = [
                    'id'   => (string) ($row['Id'] ?? ''),
                    'name' => (string) ($row['FullyQualifiedName'] ?? $row['Name'] ?? ''),
                ];
            }
        }
    } catch (\Throwable $e) {
        $out['load_error'] = 'Could not load from QuickBooks: ' . $e->getMessage();
    }
}

settings_cache_flush();
$cutoverAt = (string) settings_get('quickbooks.cutover_at', '');
$out['go_live_day'] = ($cutoverAt !== '' && ($ts = strtotime($cutoverAt)) !== false)
    ? ff_utc_to_local(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d')
    : null;
$out['prefs'] = [
    'class_tracking'     => (string) settings_get('quickbooks.pref.class_tracking', ''),
    'track_locations'    => (string) settings_get('quickbooks.pref.track_locations', ''),
    'custom_txn_numbers' => (string) settings_get('quickbooks.pref.custom_txn_numbers', ''),
    'book_close_date'    => (string) settings_get('quickbooks.pref.book_close_date', ''),
    'synced_at'          => (string) settings_get('quickbooks.pref.synced_at', ''),
];
json_success($out);
