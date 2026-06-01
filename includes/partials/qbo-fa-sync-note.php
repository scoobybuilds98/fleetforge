<?php
/**
 * includes/partials/qbo-fa-sync-note.php
 *
 * Small informational note (F17 — S-QBO-FA-SYNC-NOTE) for fixed-asset posting
 * surfaces: when QBO is connected, depreciation / disposal / impairment journal
 * entries are auto-enqueued for QBO push on posting (the FA-derived JE flow from
 * S-QBO-22). Pure UX hint — gates nothing; the JE flow works regardless. Renders
 * nothing when QBO is disconnected. Hidden in print.
 *
 * USAGE — set $qboFaNote to the context word before including:
 *   $qboFaNote = 'depreciation';  // 'depreciation' | 'disposal' | 'impairment' | 'fixed-asset'
 *   require FF_ROOT . '/includes/partials/qbo-fa-sync-note.php';
 *
 * @session F17 (S-QBO-FA-SYNC-NOTE)
 */

if ((string) settings_get('quickbooks.connection_status', 'disconnected') !== 'connected') {
    return;
}

$qfa_ctx = isset($qboFaNote) ? (string) $qboFaNote : 'fixed-asset';
$qfa_label = [
    'depreciation' => 'Depreciation',
    'disposal'     => 'Disposal',
    'impairment'   => 'Impairment',
    'fixed-asset'  => 'Fixed-asset',
][$qfa_ctx] ?? 'Fixed-asset';

$qfa_synced = ((string) settings_get('quickbooks.sync_enabled', '0') === '1');
?>
<div class="alert alert-info ff-print-hide" style="margin-bottom:16px; display:flex; align-items:flex-start; gap:10px;">
    <span style="font-size:15px; line-height:1.2;">↗</span>
    <div class="text-sm">
        <strong>QuickBooks sync connected.</strong>
        <?= e($qfa_label) ?> journal entries are
        <?= $qfa_synced ? 'enqueued for QBO push when posted' : 'recorded and will enqueue for QBO push once the master sync switch is on (currently off — pre-cutover)' ?>.
        <a href="<?= base_url('quickbooks/journal_entries') ?>?source_filter=fa" class="link">View FA sync state →</a>
    </div>
</div>
