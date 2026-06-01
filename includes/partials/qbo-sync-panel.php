<?php
/**
 * includes/partials/qbo-sync-panel.php
 *
 * Reusable "QuickBooks Sync" rich panel for FF entity show pages (F8 —
 * S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN). Generalizes the canonical panel first
 * shipped on app/admin/invoices/show.php (S-QBO-INVOICE-SHOW-RICH-PANEL) so the
 * same 6-state badge + identifiers row + Push History table + Retry/View-in-QBO
 * actions render uniformly across bills / payments / bill-payments / journal
 * entries / credit memos — without copy-pasting ~200 lines per page.
 *
 * USAGE — set $qboPanel before including:
 *   $qboPanel = [
 *     'entity_type' => 'bill',                 // acc_qbo_sync_log.entity_type
 *     'map_table'   => 'acc_qbo_bill_map',     // map table to read state from
 *     'qbo_id_col'  => 'qbo_bill_id',          // the QBO id column on the map
 *     'ff_fk'       => 'ff_bill_id',           // map FK → this entity's id
 *     'ff_id'       => (int) $bill['id'],      // the FF entity row id
 *     'deep_link'   => 'bill',                 // QBO app path: /app/{deep_link}?txnId=
 *     'retry_url'   => base_url('api/v1/quickbooks/bills/retry'), // '' to hide retry
 *   ];
 *   require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
 *
 * Renders nothing when QBO is disconnected. Self-contained: loads its own map
 * row + last-20 sync_log history. Hidden in print.
 *
 * @session F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN)
 */

if (!isset($qboPanel) || !is_array($qboPanel) || empty($qboPanel['ff_id'])) {
    return;
}

$qsp_connected = ((string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected');
if (!$qsp_connected) {
    return;
}

$qsp_entityType = (string) ($qboPanel['entity_type'] ?? '');
$qsp_mapTable   = (string) ($qboPanel['map_table'] ?? '');
$qsp_qboIdCol   = (string) ($qboPanel['qbo_id_col'] ?? '');
$qsp_ffFk       = (string) ($qboPanel['ff_fk'] ?? '');
$qsp_ffId       = (int) ($qboPanel['ff_id'] ?? 0);
$qsp_deepLink   = (string) ($qboPanel['deep_link'] ?? '');
$qsp_retryUrl   = (string) ($qboPanel['retry_url'] ?? '');

// Whitelist the table + columns against the known map config (defense against
// any accidental injection via a mis-set config — these are page-author-set,
// not user input, but we validate anyway).
$qsp_allowed = [
    'acc_qbo_bill_map'          => ['qbo_bill_id', 'ff_bill_id'],
    'acc_qbo_payment_map'       => ['qbo_payment_id', 'ff_payment_id'],
    'acc_qbo_bill_payment_map'  => ['qbo_bill_payment_id', 'ff_ap_payment_id'],
    'acc_qbo_journal_entry_map' => ['qbo_journal_entry_id', 'ff_journal_entry_id'],
    'acc_qbo_credit_memo_map'   => ['qbo_credit_memo_id', 'ff_credit_note_id'],
    'acc_qbo_invoice_map'       => ['qbo_invoice_id', 'ff_invoice_id'],
];
if (!isset($qsp_allowed[$qsp_mapTable])
    || $qsp_allowed[$qsp_mapTable][0] !== $qsp_qboIdCol
    || $qsp_allowed[$qsp_mapTable][1] !== $qsp_ffFk) {
    return; // unknown/mismatched config — fail closed
}

$qsp_canEdit = can('quickbooks', 'edit_credentials');
$qsp_env     = (string) settings_get('quickbooks.environment', 'sandbox');

// ── Load map row + sync log history ─────────────────────────────────────────
$qsp_mapping = db_row(
    "SELECT * FROM {$qsp_mapTable} WHERE {$qsp_ffFk} = ? LIMIT 1",
    [$qsp_ffId]
);
$qsp_history = db_select(
    "SELECT created_at, http_method, operation, response_status, error_code, error_message
       FROM acc_qbo_sync_log
      WHERE entity_type = ? AND entity_id = ?
      ORDER BY id DESC LIMIT 20",
    [$qsp_entityType, $qsp_ffId]
);
$qsp_totalRow   = db_row(
    "SELECT COUNT(*) AS cnt FROM acc_qbo_sync_log WHERE entity_type = ? AND entity_id = ?",
    [$qsp_entityType, $qsp_ffId]
);
$qsp_totalCount = (int) ($qsp_totalRow['cnt'] ?? 0);

$qsp_status = $qsp_mapping['push_status'] ?? null;
$qsp_qboId  = $qsp_mapping[$qsp_qboIdCol] ?? null;

// ── 6-state classification (mirrors the invoice panel) ──────────────────────
if ($qsp_mapping === null) {
    $qsp_badge = 'badge-neutral'; $qsp_icon = '○'; $qsp_label = 'Not Synced';
} elseif ($qsp_status === 'pushed') {
    $qsp_badge = 'badge-success'; $qsp_icon = '✓'; $qsp_label = 'Synced';
} elseif ($qsp_status === 'voided') {
    $qsp_badge = 'badge-neutral'; $qsp_icon = '⊘'; $qsp_label = 'Voided';
} elseif ($qsp_status === 'pending') {
    $qsp_badge = 'badge-neutral'; $qsp_icon = '⋯'; $qsp_label = 'Pending';
} elseif ($qsp_status === 'failed') {
    $qsp_badge = 'badge-danger'; $qsp_icon = '✗'; $qsp_label = 'Failed';
} elseif (str_starts_with((string) $qsp_status, 'failed_preflight')) {
    $qsp_badge = 'badge-warning'; $qsp_icon = '⚠'; $qsp_label = 'Failed Pre-flight';
} elseif (str_starts_with((string) $qsp_status, 'skipped_')) {
    $qsp_badge = 'badge-neutral'; $qsp_icon = '–'; $qsp_label = 'Skipped';
} else {
    $qsp_badge = 'badge-neutral'; $qsp_icon = '○'; $qsp_label = ucfirst(str_replace('_', ' ', (string) $qsp_status));
}

$qsp_retryable = ['failed', 'failed_preflight', 'failed_preflight_field_too_long', 'failed_preflight_currency_mismatch'];
$qsp_showRetry = $qsp_canEdit && $qsp_retryUrl !== '' && $qsp_mapping !== null
              && in_array($qsp_status, $qsp_retryable, true);

$qsp_host = $qsp_env === 'production' ? 'app.qbo.intuit.com' : 'app.sandbox.qbo.intuit.com';
$qsp_url  = ($qsp_qboId && $qsp_deepLink !== '')
    ? "https://{$qsp_host}/app/{$qsp_deepLink}?txnId=" . urlencode((string) $qsp_qboId)
    : null;

$qsp_timeAgo = static function (?string $ts): ?string {
    if (!$ts) return null;
    $t = strtotime($ts);
    if ($t === false) return null;
    $diff = time() - $t;
    if ($diff < 60)      return $diff <= 1 ? 'just now' : $diff . ' seconds ago';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('Y-m-d', $t);
};
$qsp_pushedRel = $qsp_timeAgo($qsp_mapping['pushed_at'] ?? null);
$qsp_mapId     = (int) ($qsp_mapping['id'] ?? 0);
$qsp_currency  = $qsp_mapping['qbo_currency'] ?? null;
$qsp_token     = $qsp_mapping['qbo_sync_token'] ?? null;
?>
<div class="card ff-print-hide" id="qbo-sync-panel" style="margin-bottom:24px;">

    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px;">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0;">QuickBooks Sync</h3>
            <span class="badge badge-no-dot <?= $qsp_badge ?>" style="font-size:12px; padding:3px 10px;"
                  title="<?= e($qsp_status ?? 'no mapping row') ?>">
                <span style="font-family:'DM Mono',monospace;"><?= e($qsp_icon) ?></span>
                <?= e($qsp_label) ?>
            </span>
        </div>

        <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-size:13px; margin-left:auto;">
            <?php if ($qsp_qboId): ?>
                <div>
                    <span class="text-secondary">QBO</span>
                    <?php if ($qsp_url): ?>
                        <a href="<?= e($qsp_url) ?>" target="_blank" rel="noopener noreferrer"
                           class="font-mono link" title="Open in QuickBooks">#<?= e($qsp_qboId) ?> ↗</a>
                    <?php else: ?>
                        <span class="font-mono">#<?= e($qsp_qboId) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($qsp_mapping && !empty($qsp_mapping['pushed_at'])): ?>
                <div title="<?= e((string) $qsp_mapping['pushed_at']) ?>">
                    <span class="text-secondary">Pushed</span>
                    <span class="font-mono"><?= e($qsp_pushedRel ?? (string) $qsp_mapping['pushed_at']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($qsp_currency)): ?>
                <div>
                    <span class="text-secondary">Currency</span>
                    <span class="font-mono"><?= e($qsp_currency) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($qsp_token !== null && $qsp_token !== ''): ?>
                <div class="text-secondary text-sm" title="QBO optimistic-lock token — used to detect divergent updates.">
                    Token: <span class="font-mono"><?= e($qsp_token) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="padding:14px 20px;">
        <h4 style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 8px 0;">Push History</h4>
        <?php if (empty($qsp_history)): ?>
            <p class="text-secondary text-sm" style="margin:0;">No push attempts yet.</p>
        <?php else: ?>
            <table class="table" style="margin:0; font-size:12px;">
                <thead>
                    <tr>
                        <th style="font-size:10px;">Timestamp</th>
                        <th style="font-size:10px;">Operation</th>
                        <th style="font-size:10px;">Status</th>
                        <th style="font-size:10px;">Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qsp_history as $lr): ?>
                        <?php
                        $lr_status = (int) ($lr['response_status'] ?? 0);
                        if ($lr_status >= 200 && $lr_status < 300) { $lr_badge = 'badge-success'; $lr_icon = '✓'; }
                        elseif ($lr_status >= 400)                 { $lr_badge = 'badge-danger';  $lr_icon = '✗'; }
                        else                                       { $lr_badge = 'badge-neutral'; $lr_icon = '⋯'; }
                        ?>
                        <tr>
                            <td class="font-mono text-sm" style="white-space:nowrap;" title="<?= e((string) $lr['created_at']) ?>">
                                <?= e($qsp_timeAgo($lr['created_at']) ?? (string) $lr['created_at']) ?>
                            </td>
                            <td class="text-sm"><?= e(((string) ($lr['http_method'] ?? '')) . ' ' . ((string) ($lr['operation'] ?? ''))) ?></td>
                            <td>
                                <span class="badge badge-no-dot <?= $lr_badge ?>" style="font-size:10px;">
                                    <?= e($lr_icon) ?> <?= e($lr_status > 0 ? (string) $lr_status : 'queued') ?>
                                </span>
                            </td>
                            <td class="text-sm text-secondary" style="max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                title="<?= e((string) ($lr['error_message'] ?? '')) ?>">
                                <?php if (!empty($lr['error_code'])): ?>
                                    <span class="font-mono text-danger"><?= e((string) $lr['error_code']) ?></span>
                                <?php endif; ?>
                                <?= e((string) ($lr['error_message'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($qsp_totalCount > count($qsp_history)): ?>
                <p class="text-secondary text-sm" style="margin:8px 0 0 0; font-size:11px;">
                    +<?= (int) ($qsp_totalCount - count($qsp_history)) ?> more attempts (showing most recent 20)
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($qsp_showRetry || $qsp_url): ?>
    <div style="padding:12px 20px; border-top:1px solid var(--border-color); display:flex; gap:8px; align-items:center; flex-wrap:wrap;"
         x-data="qboSyncPanelPartial(<?= $qsp_mapId ?>, '<?= e($qsp_retryUrl) ?>')">
        <?php if ($qsp_showRetry): ?>
            <button type="button" class="btn btn-secondary btn-sm" @click="retry()" :disabled="retrying">
                <span x-show="!retrying">Retry Push</span>
                <span x-show="retrying" x-cloak>Retrying…</span>
            </button>
        <?php endif; ?>
        <?php if ($qsp_url): ?>
            <a href="<?= e($qsp_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">View in QBO ↗</a>
        <?php endif; ?>
        <span x-show="flash" x-cloak x-text="flash" class="text-sm" :class="flashType === 'success' ? 'text-success' : 'text-danger'"></span>
    </div>
    <?php endif; ?>
</div>

<?php
// Define the retry Alpine factory once per request (guard against double-include
// if a page ever renders two panels).
if (!defined('FF_QBO_SYNC_PANEL_JS_EMITTED')) {
    define('FF_QBO_SYNC_PANEL_JS_EMITTED', true);
?>
<script>
function qboSyncPanelPartial(mappingId, retryUrl) {
    return {
        retrying: false, flash: '', flashType: '',
        async retry() {
            if (!mappingId || !retryUrl) { this.flash = 'No mapping row to retry.'; this.flashType = 'danger'; return; }
            this.retrying = true; this.flash = '';
            try {
                const r = await FF_Api.post(retryUrl, { id: mappingId });
                if (r.success) {
                    if (r.action === 'enqueued') {
                        this.flash = 'Re-enqueued for push — reloading…'; this.flashType = 'success';
                        setTimeout(() => window.location.reload(), 600);
                    } else {
                        this.flash = 'Skipped: ' + (r.reason || 'gate refused'); this.flashType = 'danger';
                    }
                } else {
                    this.flash = 'Retry failed: ' + (r.message || 'unknown error'); this.flashType = 'danger';
                }
            } catch (e) {
                this.flash = 'Retry failed: ' + (e.message || e); this.flashType = 'danger';
            } finally { this.retrying = false; }
        },
    };
}
</script>
<?php } ?>
