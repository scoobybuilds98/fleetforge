<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/drift.php
 *
 * Stub — full drift detection UI ships in S-QBO-4 (basic) and
 * S-QBO-24 / S-QBO-25 (full per-entity comparison + resolution
 * workflows). The acc_qbo_drift_events table is created in S-QBO-4.
 *
 * Session: S-QBO-1 (stub) → S-QBO-4 (basic) → S-QBO-24/25 (full)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Drift';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Drift</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Drift Detection</h1>
</div>

<div class="card" style="padding:24px;">
    <p class="text-secondary">Coming in S-QBO-4.</p>
    <p class="text-secondary text-sm" style="margin-top:8px;">
        This page will list active drift events with per-entity comparison, tolerance thresholds (D-QBO-CORE-10),
        and resolve / accept / suppress actions. The underlying acc_qbo_drift_events table is created in S-QBO-4.
    </p>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
