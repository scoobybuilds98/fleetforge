<?php
declare(strict_types=1);

/**
 * app/admin/credit-application.php
 *
 * Public token-gated credit application form (D-CCA-1, S-CCA-2).
 * NO login required. Route: GET/POST /credit-application?token=<raw>.
 *
 * Security model (D-CCA-2-B):
 *   • Token looked up by SHA-256(raw) — raw token is emailed once and never stored.
 *   • CSRF token in session, verified on every POST.
 *   • Rate-limited per token-prefix + IP (5 POST attempts / 60 min).
 *   • Trap 7: signature_path, token_hash never emitted in response.
 *
 * POST persists (D-CCA-2):
 *   form_data JSON snapshot → customer_credit_applications row (status='submitted').
 *   Signature PNG → StorageClient; signature_path stored on row (never returned).
 *   Customer-uploaded files → documents rows (entity_type='customer',
 *   document_type='credit_application') → uploaded_document_ids JSON.
 *   PDF generation and manager notifications are S-CCA-3 scope (D-CCA-2-G).
 *
 * @session S-CCA-2
 */

require_once FF_ROOT . '/includes/auth.php';
require_once FF_ROOT . '/includes/partials/credit_application_render.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Storage\StorageClient;
use FleetForge\Security\RateLimiter;
use FleetForge\Notifications\NotificationService;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

// ── Brand / company settings ─────────────────────────────────────────────────
$companyName  = (string)(settings_get('company.name') ?: 'FleetForge');
$brandLogo    = (string)(settings_get('brand.logo_path') ?? '');
$brandColor   = (string)(settings_get('brand.primary_color') ?: '#f97316');
$brandHover   = (string)(settings_get('brand.primary_hover') ?: '#ea6f00');

$logoUrl = '';
if ($brandLogo !== '') {
    try { $logoUrl = StorageClient::url($brandLogo, 3600); } catch (\Throwable $_e) {}
}
if ($logoUrl === '') {
    foreach (['svg', 'png', 'jpg', 'jpeg'] as $ext) {
        if (is_file(FF_ROOT . "/public/media/login-logo.{$ext}")) {
            $logoUrl = asset_url("media/login-logo.{$ext}");
            break;
        }
    }
}

// ── CCA settings ─────────────────────────────────────────────────────────────
$insuranceEmail  = (string)(settings_get('credit_application.insurance_email',  'insurance@example.com'));
$referencesEmail = (string)(settings_get('credit_application.references_email', 'sales@example.com'));
$termsUrl        = (string)(settings_get('credit_application.terms_url', '#'));
$disclaimerHtml  = (string)(settings_get('credit_application.disclaimer_html',  ''));
$minReqHtml      = (string)(settings_get('credit_application.minimum_requirements_html', ''));

// ── Token resolution ─────────────────────────────────────────────────────────
$tokenParam = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$app        = null;
$tokenHash  = '';
$pageState  = 'form'; // form | invalid | expired | submitted_already | confirmed

if ($tokenParam === '') {
    $pageState = 'invalid';
} else {
    $tokenHash = hash('sha256', $tokenParam);
    $app = db_row(
        "SELECT cca.id, cca.customer_id, cca.status, cca.token_expires_at,
                cca.updated_at, cca.deleted_at,
                c.company_name AS customer_company_name
           FROM customer_credit_applications cca
           JOIN customers c ON c.id = cca.customer_id
          WHERE cca.token_hash = ?",
        [$tokenHash]
    );

    if (!$app || $app['deleted_at'] !== null) {
        $pageState = 'invalid';
    } elseif (strtotime($app['token_expires_at']) <= time()) {
        $pageState = 'expired';
    } elseif (in_array($app['status'], ['submitted', 'reviewed'], true)) {
        // Post-submit PRG flash: show confirmation page on the redirect GET
        $flashKey = 'cca_confirmed_' . $app['id'];
        if (!empty($_SESSION[$flashKey])) {
            unset($_SESSION[$flashKey]);
            $pageState = 'confirmed';
        } else {
            $pageState = 'submitted_already';
        }
    } elseif (!in_array($app['status'], ['sent', 'opened'], true)) {
        $pageState = 'invalid';
    }
}

// ── On first valid GET of a 'sent' row → flip to 'opened' (D-CCA-2-D) ────────
if ($pageState === 'form'
    && $app !== null
    && $app['status'] === 'sent'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    db_execute(
        "UPDATE customer_credit_applications
            SET status='opened', opened_at=NOW(), updated_at=NOW()
          WHERE id=? AND status='sent'",
        [(int)$app['id']]
    );
    $app['status'] = 'opened';
}

// ── CSRF token (scoped per application to avoid cross-form sharing) ───────────
$csrfKey   = 'cca_csrf_' . ($app['id'] ?? 'none');
if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION[$csrfKey];

// ── POST handler ─────────────────────────────────────────────────────────────
$errors = [];
$old    = $_POST; // re-fill form inputs on validation error

if ($pageState === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        http_response_code(403);
        $errors['_global'] = 'Invalid request token. Please refresh the page and try again.';
    }

    // 2. Rate limit: 5 POST attempts per 60 min per (token-prefix + IP)
    if (empty($errors)) {
        $rlKey = 'cca:submit:' . substr($tokenHash, 0, 24) . ':' . RateLimiter::getClientIp();
        $rl = RateLimiter::check($rlKey, 5, 60, 60);
        if (!$rl['allowed']) {
            http_response_code(429);
            $errors['_global'] = 'Too many submission attempts. Please try again in a few minutes.';
        }
    }

    // 3. Re-read row for optimistic-lock baseline; confirm still submittable
    $freshApp = null;
    if (empty($errors)) {
        $freshApp = db_row(
            "SELECT id, status, updated_at
               FROM customer_credit_applications
              WHERE id=? AND deleted_at IS NULL",
            [(int)$app['id']]
        );
        if (!$freshApp || !in_array($freshApp['status'], ['sent', 'opened'], true)) {
            $errors['_global'] = 'This application has already been submitted.';
        }
    }

    // 4. Required field validation ──────────────────────────────────────────
    $company_name       = '';
    $company_email      = '';
    $company_phone      = '';
    $physical_address   = '';
    $physical_city      = '';
    $physical_province  = '';
    $physical_postal    = '';
    $same_as_physical   = '';
    $has_trailer_ins    = '';
    $signed_date        = '';
    $print_name_first   = '';
    $print_name_last    = '';
    $terms_accepted     = 0;
    $signature_data     = '';

    if (empty($errors)) {
        $company_name      = trim($_POST['company_name']      ?? '');
        $company_email     = trim($_POST['company_email']     ?? '');
        $company_phone     = trim($_POST['company_phone']     ?? '');
        $physical_address  = trim($_POST['physical_address']  ?? '');
        $physical_city     = trim($_POST['physical_city']     ?? '');
        $physical_province = trim($_POST['physical_province'] ?? '');
        $physical_postal   = trim($_POST['physical_postal']   ?? '');
        $same_as_physical  = trim($_POST['same_as_physical']  ?? '');
        $has_trailer_ins   = trim($_POST['has_trailer_insurance'] ?? '');
        $signed_date       = trim($_POST['signed_date']       ?? '');
        $print_name_first  = trim($_POST['print_name_first']  ?? '');
        $print_name_last   = trim($_POST['print_name_last']   ?? '');
        $terms_accepted    = (($_POST['terms_accepted'] ?? '') === '1') ? 1 : 0;
        $signature_data    = trim($_POST['signature_data']    ?? '');

        if ($company_name === '')     $errors['company_name']     = 'Company name is required.';
        if ($company_email === '') {
            $errors['company_email'] = 'Email is required.';
        } elseif (!filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
            $errors['company_email'] = 'Please enter a valid email address.';
        }
        if ($company_phone === '')    $errors['company_phone']    = 'Phone number is required.';
        if ($physical_address === '') $errors['physical_address']  = 'Physical address is required.';
        if ($physical_city === '')    $errors['physical_city']     = 'City is required.';
        if ($physical_province === '') $errors['physical_province'] = 'Province is required.';
        if ($physical_postal === '')  $errors['physical_postal']   = 'Postal code is required.';
        if (!in_array($same_as_physical, ['Yes', 'No'], true)) {
            $errors['same_as_physical'] = 'Please indicate if billing address is the same as physical.';
        }
        if (!in_array($has_trailer_ins, ['Yes', 'No'], true)) {
            $errors['has_trailer_insurance'] = 'Please indicate whether you have trailer insurance.';
        }
        if ($signed_date === '') {
            $errors['signed_date'] = 'Date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signed_date)) {
            $errors['signed_date'] = 'Invalid date format.';
        }
        if ($print_name_first === '') $errors['print_name_first'] = 'First name is required.';
        if ($print_name_last === '')  $errors['print_name_last']  = 'Last name is required.';
        if (!$terms_accepted)         $errors['terms_accepted']   = 'You must accept the Terms and Conditions.';
        if ($signature_data === '' || !str_starts_with($signature_data, 'data:image/png;base64,')) {
            $errors['signature'] = 'Please draw your signature.';
        }
    }

    // 5. Optional file uploads: validate MIME + size + count ────────────────
    $validatedFiles = [];
    if (empty($errors)) {
        $allowedDocMimes = [
            'application/pdf'  => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        $uf = $_FILES['documents'] ?? [];
        if (!empty($uf['name']) && is_array($uf['name'])) {
            $n = count($uf['name']);
            if ($n > 10) {
                $errors['documents'] = 'Maximum 10 files allowed per submission.';
            } else {
                for ($i = 0; $i < $n; $i++) {
                    if ($uf['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($uf['error'][$i] !== UPLOAD_ERR_OK) {
                        $errors['documents'] = 'Upload error on file ' . ($i + 1) . '. Please try again.';
                        break;
                    }
                    if ($uf['size'][$i] > 20 * 1024 * 1024) {
                        $errors['documents'] = 'Each file must be under 20 MB.';
                        break;
                    }
                    $tmpPath = $uf['tmp_name'][$i];
                    $fi      = finfo_open(FILEINFO_MIME_TYPE);
                    $detMime = finfo_file($fi, $tmpPath);
                    finfo_close($fi);
                    // DOCX is a ZIP archive; some finfo versions report application/zip
                    if ($detMime === 'application/zip') {
                        if (strtolower(pathinfo($uf['name'][$i], PATHINFO_EXTENSION)) === 'docx') {
                            $detMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                        }
                    }
                    if (!isset($allowedDocMimes[$detMime])) {
                        $errors['documents'] = 'Only PDF, DOC, and DOCX files are accepted.';
                        break;
                    }
                    $validatedFiles[] = [
                        'tmp_path' => $tmpPath,
                        'orig_name' => $uf['name'][$i],
                        'mime'      => $detMime,
                        'ext'       => $allowedDocMimes[$detMime],
                        'size_kb'   => (int)ceil($uf['size'][$i] / 1024),
                    ];
                }
            }
        }
    }

    // 6. Persist if no validation errors ────────────────────────────────────
    if (empty($errors)) {
        $now           = date('Y-m-d H:i:s');
        $submittedIp   = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $submittedUa   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $uploadedDocIds = [];

        // 6a. Store signature PNG via StorageClient (Trap 7 — path never returned)
        $sigPath = null;
        $sigB64  = str_replace('data:image/png;base64,', '', $signature_data);
        $sigBin  = base64_decode($sigB64, true);
        if ($sigBin !== false && strlen($sigBin) > 0) {
            $tmpSig = tempnam(sys_get_temp_dir(), 'ff_sig_');
            if ($tmpSig !== false) {
                file_put_contents($tmpSig, $sigBin);
                try {
                    $sigPath = 'credit_applications/' . $app['id'] . '/signature_' . time() . '.png';
                    StorageClient::upload($tmpSig, $sigPath);
                } catch (\RuntimeException $e) {
                    error_log('[S-CCA-2] Signature upload failed for app ' . $app['id'] . ': ' . $e->getMessage());
                    $sigPath = null;
                } finally {
                    @unlink($tmpSig);
                }
            }
        }

        // 6b. Store customer-uploaded documents → documents rows (D-CCA-2-A)
        foreach ($validatedFiles as $vf) {
            $storagePath = 'credit_applications/' . $app['id'] . '/doc_' . time() . '_' . uniqid() . '.' . $vf['ext'];
            try {
                StorageClient::upload($vf['tmp_path'], $storagePath);
                $docTitle = pathinfo($vf['orig_name'], PATHINFO_FILENAME) ?: 'Credit Application Document';
                $docId = db_insert('documents', [
                    'entity_type'   => 'customer',
                    'entity_id'     => (int)$app['customer_id'],
                    'document_type' => 'credit_application',
                    'title'         => substr($docTitle, 0, 255),
                    'file_path'     => $storagePath,
                    'file_name'     => basename($storagePath),
                    'file_size_kb'  => $vf['size_kb'],
                    'mime_type'     => $vf['mime'],
                    'uploaded_by'   => null,
                ]);
                $uploadedDocIds[] = $docId;
            } catch (\RuntimeException $e) {
                error_log('[S-CCA-2] Document upload failed for app ' . $app['id'] . ': ' . $e->getMessage());
            }
        }

        // 6c. Build form_data JSON snapshot (D-CCA-4 — never written back to customers)
        $formDataJson = json_encode([
            'company' => [
                'name'              => $company_name,
                'email'             => $company_email,
                'phone'             => $company_phone,
                'physical_address'  => $physical_address,
                'physical_city'     => $physical_city,
                'physical_province' => $physical_province,
                'physical_postal'   => $physical_postal,
                'same_as_physical'  => $same_as_physical,
                'billing_address'   => trim($_POST['billing_address']   ?? ''),
                'billing_city'      => trim($_POST['billing_city']      ?? ''),
                'billing_province'  => trim($_POST['billing_province']  ?? ''),
                'billing_postal'    => trim($_POST['billing_postal']    ?? ''),
                'business_type'     => trim($_POST['business_type']     ?? ''),
                'incorporation'     => trim($_POST['incorporation']     ?? ''),
                'duration'          => trim($_POST['duration']          ?? ''),
                'wcb_number'        => trim($_POST['wcb_number']        ?? ''),
                'gst_number'        => trim($_POST['gst_number']        ?? ''),
                'pst_number'        => trim($_POST['pst_number']        ?? ''),
            ],
            'principals' => [
                ['name' => trim($_POST['principal1_name'] ?? ''), 'title' => trim($_POST['principal1_title'] ?? '')],
                ['name' => trim($_POST['principal2_name'] ?? ''), 'title' => trim($_POST['principal2_title'] ?? '')],
            ],
            'insurance' => [
                'has_trailer_insurance' => $has_trailer_ins,
                'company'               => trim($_POST['insurance_company'] ?? ''),
                'agent'                 => trim($_POST['insurance_agent']   ?? ''),
                'phone'                 => trim($_POST['insurance_phone']   ?? ''),
            ],
            'equipment' => [
                'tractors_owned'   => trim($_POST['tractors_owned']   ?? ''),
                'tractors_leased'  => trim($_POST['tractors_leased']  ?? ''),
                'owner_operators'  => trim($_POST['owner_operators']  ?? ''),
            ],
            'credit' => [
                'credit_requested'   => trim($_POST['credit_requested']   ?? ''),
                'has_purchase_order' => trim($_POST['has_purchase_order'] ?? ''),
            ],
            'references' => [
                ['company' => trim($_POST['ref1_company'] ?? ''), 'phone' => trim($_POST['ref1_phone'] ?? ''), 'email' => trim($_POST['ref1_email'] ?? '')],
                ['company' => trim($_POST['ref2_company'] ?? ''), 'phone' => trim($_POST['ref2_phone'] ?? ''), 'email' => trim($_POST['ref2_email'] ?? '')],
                ['company' => trim($_POST['ref3_company'] ?? ''), 'phone' => trim($_POST['ref3_phone'] ?? ''), 'email' => trim($_POST['ref3_email'] ?? '')],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 6d. Optimistic-lock UPDATE (D-CCA-2-B, D-CCA-2-E)
        $affected = db_execute(
            "UPDATE customer_credit_applications
                SET status               = 'submitted',
                    submitted_at         = ?,
                    submitted_ip         = ?,
                    submitted_user_agent = ?,
                    form_data            = ?,
                    print_name_first     = ?,
                    print_name_last      = ?,
                    signed_date          = ?,
                    terms_accepted       = 1,
                    terms_version        = 'snapshot',
                    terms_url            = ?,
                    signature_path       = ?,
                    uploaded_document_ids = ?,
                    updated_at           = ?
              WHERE id = ?
                AND status IN ('sent','opened')
                AND updated_at = ?",
            [
                $now,
                $submittedIp,
                $submittedUa,
                $formDataJson,
                $print_name_first,
                $print_name_last,
                $signed_date,
                $termsUrl,
                $sigPath,
                !empty($uploadedDocIds) ? json_encode($uploadedDocIds) : null,
                $now,
                (int)$app['id'],
                $freshApp['updated_at'],
            ]
        );

        if ($affected === 0) {
            // Optimistic lock missed — concurrent submission
            $errors['_global'] = 'A concurrent submission was detected. Please refresh and try again.';
        } else {
            // 6e. Audit log
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'applicant (public)',
                'action'       => 'create',
                'module'       => 'customers',
                'entity_type'  => 'credit_application',
                'entity_id'    => (int)$app['id'],
                'entity_label' => 'Credit Application #' . $app['id'] . ' submitted — ' . $company_name,
                'new_values'   => json_encode(['status' => 'submitted', 'company_name' => $company_name]),
                'ip_address'   => $submittedIp,
            ]);

            // ── Post-commit: rendered_html + PDF + notifications (D-CCA-3-A) ───────
            // Core record already committed above. These steps are NON-FATAL: any
            // failure logs to error_log/Sentry but NEVER rolls back the submission.

            // Extract base64 signature for inline embedding (D-CCA-3-C, Trap 7)
            $sigB64ForRender = str_replace('data:image/png;base64,', '', $signature_data);

            $formDataArray = json_decode((string)$formDataJson, true) ?: [];

            // 6g. Build + store rendered_html (D-CCA-3-B)
            try {
                $renderedHtml = cca_render_html([
                    'app_id'           => (int)$app['id'],
                    'customer_company' => (string)($app['customer_company_name'] ?? ''),
                    'submitted_at'     => $now,
                    'submitted_ip'     => $submittedIp,
                    'print_name_first' => $print_name_first,
                    'print_name_last'  => $print_name_last,
                    'signed_date'      => $signed_date,
                    'terms_accepted'   => $terms_accepted,
                    'terms_version'    => 'snapshot',
                    'form_data'        => $formDataArray,
                    'signature_b64'    => $sigB64ForRender,
                    'disclaimer_html'  => $disclaimerHtml,
                    'min_req_html'     => $minReqHtml,
                    'company_name'     => $companyName,
                ]);
                db_execute(
                    "UPDATE customer_credit_applications SET rendered_html = ? WHERE id = ?",
                    [$renderedHtml, (int)$app['id']]
                );
            } catch (\Throwable $e) {
                error_log('[S-CCA-3] rendered_html build failed for app ' . $app['id'] . ': ' . $e->getMessage());
                $renderedHtml = '';
            }

            // 6h. Generate PDF via mPDF → StorageClient → documents row (D-CCA-3-D)
            if ($renderedHtml !== '') {
                try {
                    $tmpDir = FF_ROOT . '/storage/tmp';
                    if (!is_dir($tmpDir)) {
                        @mkdir($tmpDir, 0755, true);
                    }
                    $mpdf = new Mpdf([
                        'mode'          => 'utf-8',
                        'format'        => 'A4',
                        'margin_top'    => 12,
                        'margin_bottom' => 12,
                        'margin_left'   => 12,
                        'margin_right'  => 12,
                        'default_font'  => 'dejavusans',
                        'tempDir'       => $tmpDir,
                    ]);
                    $submittedDateLabel = $signed_date ?: date('Y-m-d');
                    $mpdf->SetTitle('Credit Application — ' . ($app['customer_company_name'] ?? '') . ' ' . $submittedDateLabel);
                    $mpdf->SetAuthor((string)(settings_get('company.name') ?: 'FleetForge'));
                    $mpdf->WriteHTML($renderedHtml);
                    $pdfBytes = $mpdf->Output('', Destination::STRING_RETURN);

                    if ($pdfBytes !== '') {
                        $tmpPdf = tempnam(sys_get_temp_dir(), 'ff_cca_');
                        if ($tmpPdf !== false) {
                            file_put_contents($tmpPdf, $pdfBytes);
                            try {
                                $pdfPath = 'credit_applications/' . $app['id'] . '/credit_application_' . date('Ymd') . '.pdf';
                                StorageClient::upload($tmpPdf, $pdfPath);

                                // D-CCA-3-D: title from FF customer record + submitted date
                                $docTitle = 'Credit Application — '
                                    . ($app['customer_company_name'] ?? 'Customer')
                                    . ' (' . $submittedDateLabel . ')';
                                $docId = db_insert('documents', [
                                    'entity_type'   => 'customer',
                                    'entity_id'     => (int)$app['customer_id'],
                                    'document_type' => 'credit_application',
                                    'title'         => substr($docTitle, 0, 255),
                                    'file_path'     => $pdfPath,
                                    'file_name'     => basename($pdfPath),
                                    'file_size_kb'  => (int)ceil(strlen($pdfBytes) / 1024),
                                    'mime_type'     => 'application/pdf',
                                    'uploaded_by'   => null,
                                ]);
                                db_execute(
                                    "UPDATE customer_credit_applications SET generated_pdf_document_id = ? WHERE id = ?",
                                    [(int)$docId, (int)$app['id']]
                                );
                            } catch (\Throwable $e) {
                                error_log('[S-CCA-3] PDF upload/documents row failed for app ' . $app['id'] . ': ' . $e->getMessage());
                            } finally {
                                @unlink($tmpPdf);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[S-CCA-3] PDF generation failed for app ' . $app['id'] . ': ' . $e->getMessage());
                }
            }

            // 6i. Notify manager + super_admin + accountant (D-CCA-3-E, D-CCA-8)
            try {
                $notifyRows = db_select(
                    "SELECT DISTINCT u.id FROM users u
                       JOIN user_roles r ON r.id = u.role_id
                      WHERE r.slug IN ('super_admin','manager','accountant')
                        AND u.deleted_at IS NULL
                        AND u.status = 'active'"
                );
                $notifyIds = array_map(static fn($r) => (int)$r['id'], $notifyRows);

                if (!empty($notifyIds)) {
                    $viewUrl = base_url('credit_applications/show') . '?id=' . (int)$app['id'];
                    NotificationService::notify(
                        'customer.credit_application_submitted',
                        'New Credit Application — ' . ($app['customer_company_name'] ?? 'Customer'),
                        'A credit application was submitted by ' . trim($print_name_first . ' ' . $print_name_last)
                            . ' (' . $company_name . '). Click to review.',
                        'credit_application',
                        (int)$app['id'],
                        $viewUrl,
                        $notifyIds,
                        'info'
                    );
                }
            } catch (\Throwable $e) {
                error_log('[S-CCA-3] Notification dispatch failed for app ' . $app['id'] . ': ' . $e->getMessage());
            }

            // 6f. POST-Redirect-GET — set flash then redirect
            $_SESSION['cca_confirmed_' . $app['id']] = true;
            header('Location: ' . base_url('credit-application') . '?token=' . urlencode($tokenParam));
            exit;
        }
    }
}

// ── HTML output ───────────────────────────────────────────────────────────────
$pageTitle = 'Credit Application — ' . e($companyName);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $pageTitle ?></title>
    <link rel="icon" href="<?= asset_url('assets/icons/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <style id="ff-cca-brand-override">
        :root {
            --color-primary:       <?= e($brandColor) ?>;
            --color-primary-hover: <?= e($brandHover) ?>;
        }
    </style>
    <style>
        /* ── CCA public form styles ──────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg-body, #0a0b0e);
            color: var(--text-primary, #f1f5f9);
            font-family: var(--font-sans, system-ui, sans-serif);
            font-size: 15px;
            line-height: 1.6;
        }

        /* Header bar */
        .cca-header {
            background: var(--bg-surface, #111318);
            border-bottom: 1px solid var(--border-color, #1d2133);
            padding: 0 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            height: 62px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .cca-header-logo {
            height: 36px;
            width: auto;
        }
        .cca-header-company {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .cca-header-divider {
            flex: 1;
        }
        .cca-header-label {
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--color-primary-light, rgba(249,115,22,.14));
            color: var(--color-primary-text, #fb923c);
            border-radius: 4px;
            padding: 3px 10px;
        }

        /* Page wrapper */
        .cca-page {
            max-width: 860px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }

        /* State pages (invalid / expired / submitted / confirmed) */
        .cca-state-card {
            background: var(--bg-surface, #111318);
            border: 1px solid var(--border-color, #1d2133);
            border-radius: 12px;
            padding: 56px 40px;
            text-align: center;
            margin-top: 40px;
        }
        .cca-state-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .cca-state-icon.success { background: rgba(34,197,94,.12); color: #4ade80; }
        .cca-state-icon.warning { background: rgba(234,179,8,.12); color: #facc15; }
        .cca-state-icon.error   { background: rgba(239,68,68,.12); color: #f87171; }
        .cca-state-icon svg     { width: 28px; height: 28px; }
        .cca-state-title {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 10px;
        }
        .cca-state-body {
            color: var(--text-secondary);
            max-width: 480px;
            margin: 0 auto;
        }

        /* Form section cards */
        .cca-section {
            background: var(--bg-surface, #111318);
            border: 1px solid var(--border-color, #1d2133);
            border-radius: 10px;
            padding: 28px 32px;
            margin-bottom: 20px;
        }
        .cca-section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--color-primary-text, #fb923c);
            margin: 0 0 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color, #1d2133);
        }

        /* Grid layouts */
        .cca-grid-2  { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .cca-grid-3  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .cca-grid-12 { display: grid; grid-template-columns: 1fr 2fr; gap: 16px; }
        @media (max-width: 640px) {
            .cca-grid-2, .cca-grid-3, .cca-grid-12 { grid-template-columns: 1fr; }
            .cca-section { padding: 20px 18px; }
        }

        /* Form groups */
        .cca-form-group { margin-bottom: 16px; }
        .cca-form-group:last-child { margin-bottom: 0; }

        .cca-label {
            display: block;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--label-text-strong, rgba(255,255,255,.85));
            margin-bottom: 6px;
        }
        .cca-label .req { color: var(--color-primary-text, #fb923c); margin-left: 2px; }

        .cca-input {
            width: 100%;
            padding: 9px 13px;
            background: var(--input-bg, rgba(140,130,115,.18));
            border: 1px solid var(--input-border, #2a3347);
            border-radius: 7px;
            color: var(--input-text, rgba(255,255,255,.96));
            font-size: 14px;
            line-height: 1.5;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .cca-input:focus {
            border-color: var(--color-primary, #f97316);
            box-shadow: 0 0 0 3px rgba(249,115,22,.18);
            background: var(--input-bg-focus, rgba(140,130,115,.32));
        }
        .cca-input::placeholder { color: var(--input-placeholder, rgba(235,230,220,.4)); }
        .cca-input:disabled {
            opacity: .45;
            cursor: not-allowed;
        }
        select.cca-input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke-width='1.5' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='m19.5 8.25-7.5 7.5-7.5-7.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 16px;
            padding-right: 36px;
        }

        /* Validation error states */
        .cca-input.has-error { border-color: var(--color-danger, #ef4444); }
        .cca-error-msg {
            font-size: 12px;
            color: var(--color-danger-text, #f87171);
            margin-top: 4px;
        }

        /* Radio / checkbox groups */
        .cca-radio-group {
            display: flex;
            gap: 20px;
            margin-top: 4px;
        }
        .cca-radio-label {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            font-size: 14px;
            color: var(--text-primary);
        }
        .cca-radio-label input[type="radio"],
        .cca-radio-label input[type="checkbox"] {
            accent-color: var(--color-primary, #f97316);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        /* Info note box */
        .cca-note {
            background: rgba(249,115,22,.07);
            border-left: 3px solid var(--color-primary, #f97316);
            border-radius: 0 6px 6px 0;
            padding: 12px 16px;
            font-size: 13.5px;
            color: var(--text-secondary);
            margin: 12px 0 16px;
            line-height: 1.55;
        }
        .cca-note a { color: var(--color-primary-text, #fb923c); }

        /* Signature canvas */
        .cca-sig-wrap {
            border: 1px solid var(--input-border, #2a3347);
            border-radius: 8px;
            background: #ffffff;
            cursor: crosshair;
            position: relative;
            overflow: hidden;
            margin-top: 8px;
        }
        .cca-sig-wrap.has-error { border-color: var(--color-danger, #ef4444); }
        .cca-sig-canvas {
            display: block;
            width: 100%;
            height: 140px;
            touch-action: none;
        }
        .cca-sig-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            font-size: 14px;
            pointer-events: none;
            user-select: none;
        }
        .cca-sig-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 6px;
        }
        .cca-btn-ghost {
            font-size: 12.5px;
            color: var(--text-secondary);
            background: none;
            border: 1px solid var(--border-color-strong, #2a3347);
            border-radius: 5px;
            padding: 4px 12px;
            cursor: pointer;
            transition: color .12s, border-color .12s;
        }
        .cca-btn-ghost:hover { color: var(--text-primary); border-color: var(--text-muted, #64748b); }

        /* File upload */
        .cca-file-input-wrap {
            border: 1.5px dashed var(--border-color-strong, #2a3347);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: border-color .15s;
            cursor: pointer;
            position: relative;
        }
        .cca-file-input-wrap:hover { border-color: var(--color-primary, #f97316); }
        .cca-file-input-wrap input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }
        .cca-file-icon { color: var(--text-secondary); margin-bottom: 6px; }
        .cca-file-text { font-size: 13.5px; color: var(--text-secondary); }
        .cca-file-text strong { color: var(--color-primary-text, #fb923c); }
        .cca-file-list { list-style: none; padding: 0; margin: 10px 0 0; text-align: left; }
        .cca-file-list li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            padding: 4px 0;
        }
        .cca-file-list li svg { color: var(--color-success-text, #4ade80); flex-shrink: 0; }

        /* Disclaimer */
        .cca-disclaimer {
            background: var(--bg-surface-2, #181b23);
            border: 1px solid var(--border-color, #1d2133);
            border-radius: 8px;
            padding: 18px 20px;
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.65;
            max-height: 220px;
            overflow-y: auto;
            margin-bottom: 18px;
        }
        .cca-disclaimer p { margin: 0; }

        /* Terms checkbox */
        .cca-terms-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 6px;
        }
        .cca-terms-row input[type="checkbox"] {
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: var(--color-primary, #f97316);
            width: 17px;
            height: 17px;
            cursor: pointer;
        }
        .cca-terms-text {
            font-size: 14px;
            color: var(--text-primary);
            line-height: 1.5;
        }
        .cca-terms-text a {
            color: var(--color-primary-text, #fb923c);
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        /* Min requirements (§9) */
        .cca-min-req {
            background: var(--bg-surface-2, #181b23);
            border: 1px solid var(--border-color, #1d2133);
            border-radius: 8px;
            padding: 20px 24px;
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.65;
        }
        .cca-min-req h3 { color: var(--text-primary); font-size: 15px; margin-top: 0; }
        .cca-min-req p  { margin: 8px 0; }
        .cca-min-req ul { padding-left: 22px; margin: 6px 0; }
        .cca-min-req li { margin-bottom: 4px; }

        /* Submit button */
        .cca-submit-btn {
            width: 100%;
            padding: 14px 20px;
            background: var(--color-primary, #f97316);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            border: none;
            border-radius: 9px;
            cursor: pointer;
            transition: background .14s, transform .1s;
            margin-top: 8px;
        }
        .cca-submit-btn:hover  { background: var(--color-primary-hover, #ea6f00); }
        .cca-submit-btn:active { transform: scale(.99); }

        /* Global error alert */
        .cca-alert-error {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.3);
            border-radius: 8px;
            padding: 14px 18px;
            color: var(--color-danger-text, #f87171);
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .cca-alert-error svg { flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px; }

        /* Section subtitle */
        .cca-subsection-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .05em;
            margin: 20px 0 12px;
        }
        .cca-subsection-title:first-of-type { margin-top: 0; }

        /* Reference group divider */
        .cca-ref-block {
            border: 1px solid var(--border-color, #1d2133);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            background: var(--bg-surface-2, #181b23);
        }
        .cca-ref-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="cca-header">
    <?php if ($logoUrl !== ''): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($companyName) ?>" class="cca-header-logo">
    <?php else: ?>
        <svg class="cca-header-logo" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 40 28" aria-hidden="true">
            <rect width="40" height="28" rx="4" fill="var(--color-primary,#f97316)" opacity=".15"/>
            <path fill="var(--color-primary,#f97316)" d="M6 18l6-10 5 7 3-4 6 7H6z"/>
        </svg>
    <?php endif; ?>
    <span class="cca-header-company"><?= e($companyName) ?></span>
    <span class="cca-header-divider"></span>
    <span class="cca-header-label">Credit Application</span>
</header>

<div class="cca-page">

<?php // ── State: invalid token ──────────────────────────────────────────── ?>
<?php if ($pageState === 'invalid'): ?>
<div class="cca-state-card">
    <div class="cca-state-icon error">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
    </div>
    <h1 class="cca-state-title">Invalid Link</h1>
    <p class="cca-state-body">This credit application link is invalid or has been removed. Please contact <?= e($companyName) ?> to request a new invite.</p>
</div>

<?php // ── State: expired ────────────────────────────────────────────────── ?>
<?php elseif ($pageState === 'expired'): ?>
<div class="cca-state-card">
    <div class="cca-state-icon warning">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </div>
    <h1 class="cca-state-title">Link Expired</h1>
    <p class="cca-state-body">This credit application link has expired. Please contact <?= e($companyName) ?> to request a new invite.</p>
</div>

<?php // ── State: already submitted ──────────────────────────────────────── ?>
<?php elseif ($pageState === 'submitted_already'): ?>
<div class="cca-state-card">
    <div class="cca-state-icon warning">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
        </svg>
    </div>
    <h1 class="cca-state-title">Already Submitted</h1>
    <p class="cca-state-body">This credit application has already been submitted. Our team will be in touch. If you have questions, please contact <?= e($companyName) ?> directly.</p>
</div>

<?php // ── State: confirmation (post-submit PRG) ────────────────────────── ?>
<?php elseif ($pageState === 'confirmed'): ?>
<div class="cca-state-card">
    <div class="cca-state-icon success">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </div>
    <h1 class="cca-state-title">Application Submitted</h1>
    <p class="cca-state-body">Thank you! Your credit application has been successfully submitted to <?= e($companyName) ?>. Our team will review your application and be in touch soon.</p>
</div>

<?php // ── State: form ────────────────────────────────────────────────────── ?>
<?php else: ?>

<form method="POST"
      action="<?= e(base_url('credit-application') . '?token=' . urlencode($tokenParam)) ?>"
      enctype="multipart/form-data"
      id="cca-form"
      x-data="ccaForm()"
      @submit="captureSignature">

    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <input type="hidden" name="token"      value="<?= e($tokenParam) ?>">
    <input type="hidden" name="signature_data" id="signature_data_input">

    <!-- Global error -->
    <?php if (!empty($errors['_global'])): ?>
    <div class="cca-alert-error" role="alert">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
        <span><?= e($errors['_global']) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors) && empty($errors['_global'])): ?>
    <div class="cca-alert-error" role="alert">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
        <span>Please correct the errors below and resubmit.</span>
    </div>
    <?php endif; ?>

    <!-- §1 COMPANY INFORMATION ──────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§1 Company Information</div>

        <div class="cca-form-group">
            <label class="cca-label" for="company_name">Legal Company Name <span class="req">*</span></label>
            <input id="company_name" name="company_name" type="text" class="cca-input <?= isset($errors['company_name']) ? 'has-error' : '' ?>"
                   value="<?= e($old['company_name'] ?? '') ?>" autocomplete="organization" required>
            <?php if (isset($errors['company_name'])): ?><div class="cca-error-msg"><?= e($errors['company_name']) ?></div><?php endif; ?>
        </div>

        <div class="cca-grid-2">
            <div class="cca-form-group">
                <label class="cca-label" for="company_email">Email <span class="req">*</span></label>
                <input id="company_email" name="company_email" type="email" class="cca-input <?= isset($errors['company_email']) ? 'has-error' : '' ?>"
                       value="<?= e($old['company_email'] ?? '') ?>" autocomplete="email" required>
                <?php if (isset($errors['company_email'])): ?><div class="cca-error-msg"><?= e($errors['company_email']) ?></div><?php endif; ?>
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="company_phone">Phone <span class="req">*</span></label>
                <input id="company_phone" name="company_phone" type="tel" class="cca-input <?= isset($errors['company_phone']) ? 'has-error' : '' ?>"
                       value="<?= e($old['company_phone'] ?? '+1 ') ?>" autocomplete="tel" placeholder="+1 (___) ___-____" required>
                <?php if (isset($errors['company_phone'])): ?><div class="cca-error-msg"><?= e($errors['company_phone']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Physical Address -->
        <div class="cca-form-group">
            <label class="cca-label" for="physical_address">Physical Address <span class="req">*</span></label>
            <input id="physical_address" name="physical_address" type="text" class="cca-input <?= isset($errors['physical_address']) ? 'has-error' : '' ?>"
                   value="<?= e($old['physical_address'] ?? '') ?>" autocomplete="street-address" required>
            <?php if (isset($errors['physical_address'])): ?><div class="cca-error-msg"><?= e($errors['physical_address']) ?></div><?php endif; ?>
        </div>
        <div class="cca-grid-3">
            <div class="cca-form-group">
                <label class="cca-label" for="physical_city">City <span class="req">*</span></label>
                <input id="physical_city" name="physical_city" type="text" class="cca-input <?= isset($errors['physical_city']) ? 'has-error' : '' ?>"
                       value="<?= e($old['physical_city'] ?? '') ?>" required>
                <?php if (isset($errors['physical_city'])): ?><div class="cca-error-msg"><?= e($errors['physical_city']) ?></div><?php endif; ?>
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="physical_province">Province <span class="req">*</span></label>
                <input id="physical_province" name="physical_province" type="text" class="cca-input <?= isset($errors['physical_province']) ? 'has-error' : '' ?>"
                       value="<?= e($old['physical_province'] ?? '') ?>" placeholder="e.g. BC" required>
                <?php if (isset($errors['physical_province'])): ?><div class="cca-error-msg"><?= e($errors['physical_province']) ?></div><?php endif; ?>
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="physical_postal">Postal Code <span class="req">*</span></label>
                <input id="physical_postal" name="physical_postal" type="text" class="cca-input <?= isset($errors['physical_postal']) ? 'has-error' : '' ?>"
                       value="<?= e($old['physical_postal'] ?? '') ?>" placeholder="V1A 2B3" required>
                <?php if (isset($errors['physical_postal'])): ?><div class="cca-error-msg"><?= e($errors['physical_postal']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Same as physical? -->
        <div class="cca-form-group">
            <label class="cca-label">Billing address same as physical address? <span class="req">*</span></label>
            <div class="cca-radio-group">
                <label class="cca-radio-label">
                    <input type="radio" name="same_as_physical" value="Yes"
                           x-model="sameAsPhysical"
                           <?= (($old['same_as_physical'] ?? '') === 'Yes') ? 'checked' : '' ?>>
                    Yes
                </label>
                <label class="cca-radio-label">
                    <input type="radio" name="same_as_physical" value="No"
                           x-model="sameAsPhysical"
                           <?= (($old['same_as_physical'] ?? '') === 'No') ? 'checked' : '' ?>>
                    No
                </label>
            </div>
            <?php if (isset($errors['same_as_physical'])): ?><div class="cca-error-msg"><?= e($errors['same_as_physical']) ?></div><?php endif; ?>
        </div>

        <!-- Billing address (shown when sameAsPhysical === 'No') -->
        <div x-show="sameAsPhysical === 'No'" x-cloak>
            <div class="cca-form-group">
                <label class="cca-label" for="billing_address">Billing Address</label>
                <input id="billing_address" name="billing_address" type="text" class="cca-input"
                       value="<?= e($old['billing_address'] ?? '') ?>">
            </div>
            <div class="cca-grid-3">
                <div class="cca-form-group">
                    <label class="cca-label" for="billing_city">City</label>
                    <input id="billing_city" name="billing_city" type="text" class="cca-input"
                           value="<?= e($old['billing_city'] ?? '') ?>">
                </div>
                <div class="cca-form-group">
                    <label class="cca-label" for="billing_province">Province</label>
                    <input id="billing_province" name="billing_province" type="text" class="cca-input"
                           value="<?= e($old['billing_province'] ?? '') ?>">
                </div>
                <div class="cca-form-group">
                    <label class="cca-label" for="billing_postal">Postal Code</label>
                    <input id="billing_postal" name="billing_postal" type="text" class="cca-input"
                           value="<?= e($old['billing_postal'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Business details -->
        <div class="cca-grid-3">
            <div class="cca-form-group">
                <label class="cca-label" for="business_type">Business Type</label>
                <input id="business_type" name="business_type" type="text" class="cca-input"
                       value="<?= e($old['business_type'] ?? '') ?>" placeholder="e.g. Sole Proprietor">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="incorporation">Incorporation #</label>
                <input id="incorporation" name="incorporation" type="text" class="cca-input"
                       value="<?= e($old['incorporation'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="duration">Years in Business</label>
                <input id="duration" name="duration" type="text" class="cca-input"
                       value="<?= e($old['duration'] ?? '') ?>" placeholder="e.g. 5 years">
            </div>
        </div>
        <div class="cca-grid-3">
            <div class="cca-form-group">
                <label class="cca-label" for="wcb_number">WCB #</label>
                <input id="wcb_number" name="wcb_number" type="text" class="cca-input"
                       value="<?= e($old['wcb_number'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="gst_number">GST #</label>
                <input id="gst_number" name="gst_number" type="text" class="cca-input"
                       value="<?= e($old['gst_number'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="pst_number">PST #</label>
                <input id="pst_number" name="pst_number" type="text" class="cca-input"
                       value="<?= e($old['pst_number'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- §2 PRINCIPALS ───────────────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§2 Principals</div>
        <div class="cca-grid-2">
            <div class="cca-form-group">
                <label class="cca-label" for="principal1_name">Principal Name 1</label>
                <input id="principal1_name" name="principal1_name" type="text" class="cca-input"
                       value="<?= e($old['principal1_name'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="principal1_title">Title</label>
                <input id="principal1_title" name="principal1_title" type="text" class="cca-input"
                       value="<?= e($old['principal1_title'] ?? '') ?>" placeholder="e.g. Owner, Director">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="principal2_name">Principal Name 2</label>
                <input id="principal2_name" name="principal2_name" type="text" class="cca-input"
                       value="<?= e($old['principal2_name'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="principal2_title">Title</label>
                <input id="principal2_title" name="principal2_title" type="text" class="cca-input"
                       value="<?= e($old['principal2_title'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- §3 INSURANCE INFORMATION ─────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§3 Insurance Information</div>

        <div class="cca-form-group">
            <label class="cca-label">Do you have Trailer Insurance? <span class="req">*</span></label>
            <div class="cca-radio-group">
                <label class="cca-radio-label">
                    <input type="radio" name="has_trailer_insurance" value="Yes"
                           x-model="hasInsurance"
                           <?= (($old['has_trailer_insurance'] ?? '') === 'Yes') ? 'checked' : '' ?>>
                    Yes
                </label>
                <label class="cca-radio-label">
                    <input type="radio" name="has_trailer_insurance" value="No"
                           x-model="hasInsurance"
                           <?= (($old['has_trailer_insurance'] ?? '') === 'No') ? 'checked' : '' ?>>
                    No
                </label>
            </div>
            <?php if (isset($errors['has_trailer_insurance'])): ?><div class="cca-error-msg"><?= e($errors['has_trailer_insurance']) ?></div><?php endif; ?>
        </div>

        <div class="cca-note">
            <span x-show="hasInsurance === 'Yes'" x-cloak>
                If yes, upload or email your Certificate of Insurance to
                <a href="mailto:<?= e($insuranceEmail) ?>"><?= e($insuranceEmail) ?></a> before rental.
            </span>
            <span x-show="hasInsurance === 'No' || hasInsurance === ''" x-cloak>
                If no, the minimum insurance requirements are listed at the bottom of this page.
                Upload or email any documentation to
                <a href="mailto:<?= e($insuranceEmail) ?>"><?= e($insuranceEmail) ?></a> before rental.
            </span>
            <noscript>
                Upload or email your Certificate of Insurance to
                <a href="mailto:<?= e($insuranceEmail) ?>"><?= e($insuranceEmail) ?></a> before rental.
                If you do not have trailer insurance, see the minimum requirements at the bottom of this page.
            </noscript>
        </div>

        <div class="cca-grid-3">
            <div class="cca-form-group">
                <label class="cca-label" for="insurance_company">Insurance Company</label>
                <input id="insurance_company" name="insurance_company" type="text" class="cca-input"
                       value="<?= e($old['insurance_company'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="insurance_agent">Agent Name</label>
                <input id="insurance_agent" name="insurance_agent" type="text" class="cca-input"
                       value="<?= e($old['insurance_agent'] ?? '') ?>">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="insurance_phone">Agent Phone</label>
                <input id="insurance_phone" name="insurance_phone" type="tel" class="cca-input"
                       value="<?= e($old['insurance_phone'] ?? '+1 ') ?>" placeholder="+1 (___) ___-____">
            </div>
        </div>
    </div>

    <!-- §4 EQUIPMENT INFORMATION ────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§4 Equipment Information</div>
        <div class="cca-grid-3">
            <div class="cca-form-group">
                <label class="cca-label" for="tractors_owned">Tractors Owned</label>
                <input id="tractors_owned" name="tractors_owned" type="text" class="cca-input"
                       value="<?= e($old['tractors_owned'] ?? '') ?>" placeholder="0">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="tractors_leased">Tractors Leased</label>
                <input id="tractors_leased" name="tractors_leased" type="text" class="cca-input"
                       value="<?= e($old['tractors_leased'] ?? '') ?>" placeholder="0">
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="owner_operators">Owner Operators</label>
                <input id="owner_operators" name="owner_operators" type="text" class="cca-input"
                       value="<?= e($old['owner_operators'] ?? '') ?>" placeholder="0">
            </div>
        </div>
    </div>

    <!-- §5 CREDIT INFORMATION ───────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§5 Credit Information</div>
        <div class="cca-grid-2">
            <div class="cca-form-group">
                <label class="cca-label" for="credit_requested">Credit Amount Requested ($)</label>
                <input id="credit_requested" name="credit_requested" type="text" class="cca-input"
                       value="<?= e($old['credit_requested'] ?? '') ?>" placeholder="e.g. 10,000">
            </div>
            <div class="cca-form-group">
                <label class="cca-label">Purchase Order?</label>
                <div class="cca-radio-group" style="margin-top:8px;">
                    <label class="cca-radio-label">
                        <input type="radio" name="has_purchase_order" value="Yes"
                               <?= (($old['has_purchase_order'] ?? '') === 'Yes') ? 'checked' : '' ?>>
                        Yes
                    </label>
                    <label class="cca-radio-label">
                        <input type="radio" name="has_purchase_order" value="No"
                               <?= (($old['has_purchase_order'] ?? '') === 'No') ? 'checked' : '' ?>>
                        No
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- §6 TRADE REFERENCES ─────────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§6 Trade References</div>
        <div class="cca-note" style="margin-top:0;">
            Please upload or email trade reference documentation to
            <a href="mailto:<?= e($referencesEmail) ?>"><?= e($referencesEmail) ?></a> before rental.
        </div>
        <?php foreach ([1, 2, 3] as $n): ?>
        <div class="cca-ref-block">
            <div class="cca-ref-label">Reference <?= $n ?></div>
            <div class="cca-grid-3">
                <div class="cca-form-group">
                    <label class="cca-label" for="ref<?= $n ?>_company">Company</label>
                    <input id="ref<?= $n ?>_company" name="ref<?= $n ?>_company" type="text" class="cca-input"
                           value="<?= e($old["ref{$n}_company"] ?? '') ?>">
                </div>
                <div class="cca-form-group">
                    <label class="cca-label" for="ref<?= $n ?>_phone">Phone</label>
                    <input id="ref<?= $n ?>_phone" name="ref<?= $n ?>_phone" type="tel" class="cca-input"
                           value="<?= e($old["ref{$n}_phone"] ?? '+1 ') ?>" placeholder="+1 (___) ___-____">
                </div>
                <div class="cca-form-group">
                    <label class="cca-label" for="ref<?= $n ?>_email">Email</label>
                    <input id="ref<?= $n ?>_email" name="ref<?= $n ?>_email" type="email" class="cca-input"
                           value="<?= e($old["ref{$n}_email"] ?? '') ?>">
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- §7 DOCUMENT UPLOAD ──────────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§7 Document Upload</div>
        <?php if (isset($errors['documents'])): ?>
        <div class="cca-error-msg" style="margin-bottom:10px;"><?= e($errors['documents']) ?></div>
        <?php endif; ?>
        <div class="cca-file-input-wrap" @click.stop>
            <input type="file" name="documents[]" id="doc-upload" multiple
                   accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                   @change="onFilesChange($event)">
            <div class="cca-file-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:32px;height:32px;margin:0 auto;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/>
                </svg>
            </div>
            <div class="cca-file-text">
                <strong>Click to select files</strong> or drag and drop<br>
                PDF, DOC, DOCX · max 20 MB per file · up to 10 files · Optional
            </div>
            <ul class="cca-file-list" x-show="fileNames.length > 0" x-cloak>
                <template x-for="name in fileNames" :key="name">
                    <li>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                        </svg>
                        <span x-text="name"></span>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    <!-- §8 DISCLAIMER + SIGN-OFF ─────────────────────────────────────────── -->
    <div class="cca-section">
        <div class="cca-section-title">§8 Disclaimer &amp; Sign-Off</div>

        <?php if ($disclaimerHtml !== ''): ?>
        <div class="cca-disclaimer"><?= $disclaimerHtml /* already trusted HTML from settings */ ?></div>
        <?php endif; ?>

        <div class="cca-grid-2" style="margin-bottom:16px;">
            <div class="cca-form-group">
                <label class="cca-label" for="signed_date">Date <span class="req">*</span></label>
                <input id="signed_date" name="signed_date" type="date" class="cca-input <?= isset($errors['signed_date']) ? 'has-error' : '' ?>"
                       value="<?= e($old['signed_date'] ?? date('Y-m-d')) ?>" required>
                <?php if (isset($errors['signed_date'])): ?><div class="cca-error-msg"><?= e($errors['signed_date']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="cca-grid-2" style="margin-bottom:16px;">
            <div class="cca-form-group">
                <label class="cca-label" for="print_name_first">First Name (Print) <span class="req">*</span></label>
                <input id="print_name_first" name="print_name_first" type="text" class="cca-input <?= isset($errors['print_name_first']) ? 'has-error' : '' ?>"
                       value="<?= e($old['print_name_first'] ?? '') ?>" autocomplete="given-name" required>
                <?php if (isset($errors['print_name_first'])): ?><div class="cca-error-msg"><?= e($errors['print_name_first']) ?></div><?php endif; ?>
            </div>
            <div class="cca-form-group">
                <label class="cca-label" for="print_name_last">Last Name (Print) <span class="req">*</span></label>
                <input id="print_name_last" name="print_name_last" type="text" class="cca-input <?= isset($errors['print_name_last']) ? 'has-error' : '' ?>"
                       value="<?= e($old['print_name_last'] ?? '') ?>" autocomplete="family-name" required>
                <?php if (isset($errors['print_name_last'])): ?><div class="cca-error-msg"><?= e($errors['print_name_last']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Terms checkbox -->
        <div class="cca-form-group">
            <div class="cca-terms-row">
                <input type="checkbox" id="terms_accepted" name="terms_accepted" value="1"
                       <?= !empty($old['terms_accepted']) ? 'checked' : '' ?> required>
                <label class="cca-terms-text" for="terms_accepted">
                    I have read and acknowledge the
                    <?php if ($termsUrl && $termsUrl !== '#'): ?>
                        <a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>
                    <?php else: ?>
                        Terms and Conditions
                    <?php endif; ?>
                    <span class="req">*</span>
                </label>
            </div>
            <?php if (isset($errors['terms_accepted'])): ?><div class="cca-error-msg"><?= e($errors['terms_accepted']) ?></div><?php endif; ?>
        </div>

        <!-- Signature canvas -->
        <div class="cca-form-group">
            <label class="cca-label">Signature <span class="req">*</span></label>
            <div class="cca-sig-wrap <?= isset($errors['signature']) ? 'has-error' : '' ?>">
                <canvas id="sig-canvas" class="cca-sig-canvas" width="820" height="140" aria-label="Signature pad"></canvas>
                <div class="cca-sig-placeholder" x-show="!hasSig" x-cloak>Draw your signature here</div>
            </div>
            <div class="cca-sig-actions">
                <button type="button" class="cca-btn-ghost" @click="clearSignature">Clear</button>
            </div>
            <?php if (isset($errors['signature'])): ?><div class="cca-error-msg"><?= e($errors['signature']) ?></div><?php endif; ?>
        </div>

        <button type="submit" class="cca-submit-btn">Submit Application</button>
    </div>

    <!-- §9 MINIMUM REQUIREMENTS ─────────────────────────────────────────── -->
    <?php if ($minReqHtml !== ''): ?>
    <div class="cca-section">
        <div class="cca-section-title">§9 Minimum Insurance Requirements</div>
        <div class="cca-min-req"><?= $minReqHtml /* trusted HTML from settings */ ?></div>
    </div>
    <?php endif; ?>

</form>

<?php endif; ?>

</div><!-- .cca-page -->

<script>
    function ccaForm() {
        return {
            sameAsPhysical: '<?= e($old['same_as_physical'] ?? '') ?>',
            hasInsurance:   '<?= e($old['has_trailer_insurance'] ?? '') ?>',
            hasSig:         false,
            fileNames:      [],
            _pad:           null,

            init() {
                const canvas = document.getElementById('sig-canvas');
                if (canvas && typeof SignaturePad !== 'undefined') {
                    this._pad = new SignaturePad(canvas);
                    // Track drawing state for placeholder visibility
                    const self = this;
                    canvas.addEventListener('mousedown',  () => { self.hasSig = true; });
                    canvas.addEventListener('touchstart', () => { self.hasSig = true; }, { passive: true });
                }
            },

            clearSignature() {
                if (this._pad) { this._pad.clear(); this.hasSig = false; }
            },

            captureSignature(event) {
                // Capture drawn signature into the hidden input before form submits
                if (this._pad) {
                    const data = this._pad.toPng();
                    document.getElementById('signature_data_input').value = data;
                    if (!data) {
                        // Let server-side validation catch it — do not block submit here
                    }
                }
            },

            onFilesChange(e) {
                const files = e.target.files;
                this.fileNames = [];
                for (let i = 0; i < files.length; i++) {
                    this.fileNames.push(files[i].name);
                }
            },
        };
    }
</script>

<!-- Signature pad (local asset — no CDN) -->
<script src="<?= asset_url('assets/js/signature-pad.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<!-- Alpine.js -->
<script defer src="<?= asset_url('assets/vendor/alpinejs/cdn.min.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

</body>
</html>
