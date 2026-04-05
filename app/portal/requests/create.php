<?php
declare(strict_types=1);

/**
 * app/portal/requests/create.php
 *
 * Portal service request creation form.
 * The only write operation customers can perform.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();
$puid = portal_user_id();

// Pre-populate from query params (lease detail page action buttons)
$preType      = clean_string($_GET['type'] ?? null);
$preLeaseId   = clean_int($_GET['lease_id'] ?? null);
$preEquipId   = clean_int($_GET['equipment_id'] ?? null);

// Active leases for dropdown
$leases = db_select(
    "SELECT l.id, l.contract_number, eu.unit_number
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id
     WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL AND eu.deleted_at IS NULL
     ORDER BY l.contract_number",
    [$cid]
);

// Equipment from active leases for dropdown
$equipment = db_select(
    "SELECT eu.id, eu.unit_number, et.category
     FROM equipment_units eu
     JOIN leases l ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
     AND eu.deleted_at IS NULL AND et.deleted_at IS NULL
     ORDER BY eu.unit_number",
    [$cid]
);

$error   = '';
$success = false;
$_csrfToken = portal_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!portal_verify_csrf($submittedCsrf)) {
        http_response_code(403);
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $requestType   = clean_string($_POST['request_type'] ?? null);
        $subject       = clean_string($_POST['subject'] ?? null, 500);
        $message       = clean_string($_POST['message'] ?? null, 5000);
        $leaseId       = clean_int($_POST['lease_id'] ?? null);
        $equipmentId   = clean_int($_POST['equipment_id'] ?? null);

        $validTypes = ['lease_extension', 'early_return', 'damage_report', 'billing_inquiry', 'document_request', 'new_lease_inquiry', 'general'];

        if (!$requestType || !in_array($requestType, $validTypes, true)) {
            $error = 'Please select a request type.';
        } elseif (!$subject) {
            $error = 'Subject is required.';
        } elseif (!$message) {
            $error = 'Please describe your request.';
        } else {
            // Validate lease belongs to customer (if provided)
            if ($leaseId) {
                $leaseCheck = db_exists('leases', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$leaseId, $cid]);
                if (!$leaseCheck) $leaseId = null;
            }
            // Validate equipment via lease (if provided)
            if ($equipmentId) {
                $equipCheck = db_row(
                    "SELECT eu.id FROM equipment_units eu
                     JOIN leases l ON eu.id = l.equipment_unit_id
                     WHERE eu.id = ? AND l.customer_id = ? AND l.deleted_at IS NULL AND eu.deleted_at IS NULL",
                    [$equipmentId, $cid]
                );
                if (!$equipCheck) $equipmentId = null;
            }

            $newId = db_insert('portal_service_requests', [
                'portal_user_id'    => $puid,
                'customer_id'       => $cid,
                'equipment_unit_id' => $equipmentId,
                'lease_id'          => $leaseId,
                'request_type'      => $requestType,
                'subject'           => $subject,
                'message'           => $message,
                'status'            => 'open',
            ]);

            header('Location: ' . base_url('portal/requests/view?id=' . $newId . '&created=1'));
            exit;
        }
    }
}

$pageTitle = 'New Service Request';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:680px;">

    <a href="<?= e(base_url('portal/requests')) ?>" class="portal-form-link" style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:16px;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        Back to Requests
    </a>

    <?php if ($error): ?>
        <div class="portal-login-error" style="margin-bottom:16px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="portal-section">
        <div class="portal-section-header">
            <h2 class="portal-section-title">Submit a Request</h2>
        </div>
        <div class="portal-section-body">

            <form method="POST" action="<?= e(base_url('portal/requests/create')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">

                <div class="portal-form-group">
                    <label class="portal-form-label" for="request_type">Request Type</label>
                    <select id="request_type" name="request_type" class="portal-form-input portal-form-select" required>
                        <option value="">— Select type —</option>
                        <option value="lease_extension" <?= $preType === 'lease_extension' ? 'selected' : '' ?>>Lease Extension Request</option>
                        <option value="early_return" <?= $preType === 'early_return' ? 'selected' : '' ?>>Early Return Notice</option>
                        <option value="damage_report" <?= $preType === 'damage_report' ? 'selected' : '' ?>>Damage Report</option>
                        <option value="billing_inquiry" <?= $preType === 'billing_inquiry' ? 'selected' : '' ?>>Billing Inquiry</option>
                        <option value="document_request" <?= $preType === 'document_request' ? 'selected' : '' ?>>Document Request</option>
                        <option value="new_lease_inquiry" <?= $preType === 'new_lease_inquiry' ? 'selected' : '' ?>>New Lease Inquiry</option>
                        <option value="general" <?= $preType === 'general' ? 'selected' : '' ?>>General Question</option>
                    </select>
                </div>

                <div class="portal-form-group">
                    <label class="portal-form-label" for="subject">Subject</label>
                    <input type="text" id="subject" name="subject"
                           class="portal-form-input"
                           placeholder="Brief description of your request"
                           maxlength="500" required
                           value="<?= e($_POST['subject'] ?? '') ?>">
                </div>

                <div class="portal-form-group">
                    <label class="portal-form-label" for="lease_id">Related Lease (optional)</label>
                    <select id="lease_id" name="lease_id" class="portal-form-input portal-form-select">
                        <option value="">— None —</option>
                        <?php foreach ($leases as $l): ?>
                            <option value="<?= e((string)$l['id']) ?>" <?= $preLeaseId === (int)$l['id'] ? 'selected' : '' ?>>
                                <?= e($l['contract_number']) ?> — <?= e($l['unit_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="portal-form-group">
                    <label class="portal-form-label" for="equipment_id">Related Equipment (optional)</label>
                    <select id="equipment_id" name="equipment_id" class="portal-form-input portal-form-select">
                        <option value="">— None —</option>
                        <?php foreach ($equipment as $eq): ?>
                            <option value="<?= e((string)$eq['id']) ?>" <?= $preEquipId === (int)$eq['id'] ? 'selected' : '' ?>>
                                <?= e($eq['unit_number']) ?> (<?= e($eq['category']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="portal-form-group">
                    <label class="portal-form-label" for="message">Message</label>
                    <textarea id="message" name="message"
                              class="portal-form-input portal-form-textarea"
                              placeholder="Please describe your request in detail..."
                              rows="6" required><?= e($_POST['message'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="submit" class="btn btn-primary btn-md">Submit Request</button>
                    <a href="<?= e(base_url('portal/requests')) ?>" class="btn btn-secondary btn-md">Cancel</a>
                </div>

            </form>

        </div>
    </div>

    <p style="margin-top:16px;font-size:0.8125rem;color:var(--text-muted);">
        We typically respond within 1 business day. For urgent matters, please call us directly.
    </p>

</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
