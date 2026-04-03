<?php
declare(strict_types=1);

/**
 * app/admin/settings/index.php
 *
 * Application settings management page.
 * Displays settings grouped by group_name. Each group gets a card.
 *
 * Editable only if can('settings','edit') — super_admin only.
 * POSTs back to self with group + key/value pairs.
 *
 * Groups displayed: company, invoices, alerts, notifications.
 * Sensitive groups (gps, ai) shown in a collapsed section.
 *
 * Value types: string/text → text input/textarea
 *              integer/decimal → number input
 *              boolean → checkbox toggle
 *
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @decisions D7/D30/D32
 * @session  S017
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

$canEdit = can('settings', 'edit');

// ── Flash message from previous save ────────────────────────────────────────
$saveFlash = null;
$saveError = null;

// ── Handle POST (save a group) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {

    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $saveError = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $groupName = clean_string($_POST['_group'] ?? null);

        if ($groupName) {
            // Fetch all keys belonging to this group
            $groupKeys = db_select(
                "SELECT `key`, value_type FROM settings WHERE group_name = ?",
                [$groupName]
            );

            db_transaction(function () use ($groupKeys, $groupName) {
                foreach ($groupKeys as $setting) {
                    $key       = $setting['key'];
                    $valueType = $setting['value_type'];
                    $raw       = $_POST[$key] ?? null;

                    // Normalize value by type
                    if ($valueType === 'boolean') {
                        // Checkbox: present = 1, absent = 0
                        $val = isset($_POST[$key]) ? '1' : '0';
                    } elseif ($valueType === 'integer') {
                        $val = $raw !== null ? (string)(int)$raw : '0';
                    } elseif ($valueType === 'decimal') {
                        // WHY: store as string to preserve precision (D16 bcmath approach)
                        $val = $raw !== null ? (string)preg_replace('/[^0-9.\-]/', '', $raw) : '0';
                    } else {
                        // string / text / json
                        $val = $raw !== null ? (string)$raw : '';
                    }

                    db_execute(
                        "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = ?",
                        [$val, current_user_id(), $key]
                    );
                }

                // Single audit log entry per group save
                db_insert('audit_log', [
                    'user_id'      => current_user_id(),
                    'user_name'    => current_user()['name'] ?? 'system',
                    'action'       => 'update',
                    'module'       => 'settings',
                    'entity_type'  => 'settings_group',
                    'entity_label' => $groupName,
                    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    'notes'        => "Settings group '{$groupName}' updated",
                ]);
            });

            $saveFlash = 'Settings saved successfully.';
        }
    }
}

// ── Load all settings, grouped ───────────────────────────────────────────────
$allSettings = db_select(
    "SELECT `key`, `value`, value_type, group_name, label, description
     FROM settings
     WHERE group_name IN ('company','invoices','alerts','notifications','gps','ai')
       AND label IS NOT NULL
     ORDER BY group_name ASC, `key` ASC"
);

// Group settings by group_name
$grouped = [];
foreach ($allSettings as $s) {
    $grouped[$s['group_name']][] = $s;
}

// Display order for groups — primary then sensitive
$primaryGroups   = ['company', 'invoices', 'alerts', 'notifications'];
$sensitiveGroups = ['gps', 'ai'];

// Group labels
$groupLabels = [
    'company'       => 'Company',
    'invoices'      => 'Invoices',
    'alerts'        => 'Alerts',
    'notifications' => 'Notifications',
    'gps'           => 'GPS Integration',
    'ai'            => 'AI / Machine Learning',
];

$pageTitle = 'Settings';
require_once FF_ROOT . '/includes/header.php';

// Ensure CSRF token is available (it should be — auth.php starts session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<div class="page-header">
    <h1 class="page-header-title">Settings</h1>
    <?php if (!$canEdit): ?>
    <span class="badge badge-neutral" style="margin-left:8px;">View Only</span>
    <?php endif; ?>
</div>

<?php if ($saveFlash): ?>
<div class="toast toast-success" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </span>
    <div class="toast-body"><div class="toast-message"><?= e($saveFlash) ?></div></div>
</div>
<?php endif; ?>

<?php if ($saveError): ?>
<div class="toast toast-danger" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
        </svg>
    </span>
    <div class="toast-body"><div class="toast-message"><?= e($saveError) ?></div></div>
</div>
<?php endif; ?>

<!-- ── Primary groups ─────────────────────────────────────────────────────── -->
<?php foreach ($primaryGroups as $grp): ?>
<?php if (empty($grouped[$grp])): continue; endif; ?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="font-weight:600;"><?= e($groupLabels[$grp] ?? ucfirst($grp)) ?></div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="_group"     value="<?= e($grp) ?>">

            <?php foreach ($grouped[$grp] as $setting): ?>
            <?php
            $key       = $setting['key'];
            $val       = $setting['value'] ?? '';
            $vtype     = $setting['value_type'];
            $label     = $setting['label'] ?? $key;
            $desc      = $setting['description'] ?? null;
            $inputName = $key;
            ?>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>

                <?php if ($vtype === 'boolean'): ?>
                <div class="form-check">
                    <input type="checkbox"
                           id="<?= e($key) ?>"
                           name="<?= e($key) ?>"
                           value="1"
                           <?= $val === '1' ? 'checked' : '' ?>
                           <?= !$canEdit ? 'disabled' : '' ?>>
                    <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                </div>

                <?php elseif ($vtype === 'text'): ?>
                <textarea id="<?= e($key) ?>"
                          name="<?= e($key) ?>"
                          class="form-control"
                          rows="3"
                          <?= !$canEdit ? 'readonly' : '' ?>><?= e($val) ?></textarea>

                <?php elseif (in_array($vtype, ['integer', 'decimal'], true)): ?>
                <input type="number"
                       id="<?= e($key) ?>"
                       name="<?= e($key) ?>"
                       class="form-control"
                       value="<?= e($val) ?>"
                       step="<?= $vtype === 'decimal' ? '0.01' : '1' ?>"
                       <?= !$canEdit ? 'readonly' : '' ?>>

                <?php else: ?>
                <!-- string / default -->
                <input type="text"
                       id="<?= e($key) ?>"
                       name="<?= e($key) ?>"
                       class="form-control"
                       value="<?= e($val) ?>"
                       maxlength="500"
                       <?= !$canEdit ? 'readonly' : '' ?>>
                <?php endif; ?>

                <?php if ($desc): ?>
                <p class="text-muted" style="font-size:0.8125rem;margin:4px 0 0;"><?= e($desc) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if ($canEdit): ?>
            <div style="padding-top:16px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary btn-sm">
                    Save <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?> Settings
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Sensitive / integration groups ───────────────────────────────────────── -->
<?php
$hasSensitive = false;
foreach ($sensitiveGroups as $sg) {
    if (!empty($grouped[$sg])) { $hasSensitive = true; break; }
}
?>

<?php if ($hasSensitive): ?>
<details style="margin-bottom:20px;">
    <summary style="cursor:pointer;font-weight:600;padding:12px 0;font-size:0.9375rem;
                    color:var(--text-secondary);user-select:none;">
        ▶ Integration Settings (GPS, AI) — click to expand
    </summary>
    <div style="margin-top:16px;">

    <?php foreach ($sensitiveGroups as $grp): ?>
    <?php if (empty($grouped[$grp])): continue; endif; ?>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="font-weight:600;display:flex;align-items:center;gap:8px;">
            <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?>
            <span class="badge badge-warning" style="font-size:0.7rem;">Sensitive</span>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="_group"     value="<?= e($grp) ?>">

                <?php foreach ($grouped[$grp] as $setting): ?>
                <?php
                $key   = $setting['key'];
                $val   = $setting['value'] ?? '';
                $vtype = $setting['value_type'];
                $label = $setting['label'] ?? $key;
                $desc  = $setting['description'] ?? null;
                ?>
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>

                    <?php if ($vtype === 'boolean'): ?>
                    <div class="form-check">
                        <input type="checkbox"
                               id="<?= e($key) ?>"
                               name="<?= e($key) ?>"
                               value="1"
                               <?= $val === '1' ? 'checked' : '' ?>
                               <?= !$canEdit ? 'disabled' : '' ?>>
                        <label for="<?= e($key) ?>" style="margin-left:6px;font-size:0.875rem;">Enabled</label>
                    </div>

                    <?php elseif (in_array($vtype, ['integer', 'decimal'], true)): ?>
                    <input type="number"
                           id="<?= e($key) ?>"
                           name="<?= e($key) ?>"
                           class="form-control"
                           value="<?= e($val) ?>"
                           step="<?= $vtype === 'decimal' ? '0.01' : '1' ?>"
                           <?= !$canEdit ? 'readonly' : '' ?>>

                    <?php else: ?>
                    <input type="text"
                           id="<?= e($key) ?>"
                           name="<?= e($key) ?>"
                           class="form-control"
                           value="<?= e($val) ?>"
                           maxlength="500"
                           autocomplete="off"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                    <?php endif; ?>

                    <?php if ($desc): ?>
                    <p class="text-muted" style="font-size:0.8125rem;margin:4px 0 0;"><?= e($desc) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if ($canEdit): ?>
                <div style="padding-top:16px;border-top:1px solid var(--border-default);">
                    <button type="submit" class="btn btn-primary btn-sm">
                        Save <?= e($groupLabels[$grp] ?? ucfirst($grp)) ?> Settings
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    </div><!-- /details inner -->
</details>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
