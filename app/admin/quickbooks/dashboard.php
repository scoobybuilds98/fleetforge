<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/dashboard.php
 *
 * Stub — full dashboard ships in S-QBO-4 (Sync infrastructure UI:
 * Sync Log page, Drift Detection page, QuickBooks Dashboard page).
 *
 * Session: S-QBO-1 (stub) → S-QBO-4 (implementation)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$pageTitle = 'QuickBooks Dashboard';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">QuickBooks</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">QuickBooks — Dashboard</h1>
</div>

<div class="card" style="padding:24px;">
    <p class="text-secondary">Coming in S-QBO-4.</p>
    <p class="text-secondary text-sm" style="margin-top:8px;">
        This page will surface: connection state, today's sync queue counts, recent errors, and drift summary.
        For now, use <a href="<?= base_url('quickbooks/settings') ?>">Settings</a> to manage the OAuth connection.
    </p>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
