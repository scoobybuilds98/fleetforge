<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/sync_log.php
 *
 * Stub — full sync log ships in S-QBO-4 (Sync infrastructure UI).
 * The acc_qbo_sync_log table is created in S-QBO-3.
 *
 * Session: S-QBO-1 (stub) → S-QBO-4 (implementation)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Sync Log';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Sync Log</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Sync Log</h1>
</div>

<div class="card" style="padding:24px;">
    <p class="text-secondary">Coming in S-QBO-4.</p>
    <p class="text-secondary text-sm" style="margin-top:8px;">
        This page will list every QBO API call (request, response, latency, error class) with 365-day retention per spec §6.5.
        The underlying acc_qbo_sync_log table is created in S-QBO-3.
    </p>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
