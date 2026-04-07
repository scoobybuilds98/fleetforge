<?php
declare(strict_types=1);

/**
 * includes/partials/ai-summary-card.php
 *
 * Reusable AI summary card component. Include on entity show pages
 * (customer, lease, equipment) to display AI-generated insights.
 *
 * Required variables (set before including):
 *   $aiSummaryEntityType  — 'customer', 'lease', 'equipment_unit'
 *   $aiSummaryEntityId    — Entity ID
 *   $aiSummaryType        — 'customer_insights', 'lease_summary', 'unit_analysis'
 *   $aiSummaryTitle       — Card header text (optional, defaults to "AI Insights")
 *
 * Example usage:
 *   $aiSummaryEntityType = 'customer';
 *   $aiSummaryEntityId   = $customer['id'];
 *   $aiSummaryType       = 'customer_insights';
 *   include FF_ROOT . '/includes/partials/ai-summary-card.php';
 *
 * @session S027
 */

// WHY: Only show if user has AI access and AI is configured
if (!function_exists('can') || !can('ai', 'view')) return;

// INT-1: settings table FIRST, .env SECOND.
$_aiEnabled  = (bool) settings_get('ai.enabled', false);
$_hasApiKey  = (bool) (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''));
if (!$_aiEnabled || !$_hasApiKey) return;

// Required variables
$_entityType  = $aiSummaryEntityType ?? '';
$_entityId    = $aiSummaryEntityId ?? 0;
$_summaryType = $aiSummaryType ?? '';
$_title       = $aiSummaryTitle ?? 'AI Insights';

if ($_entityType === '' || $_entityId <= 0 || $_summaryType === '') return;

// WHY: Unique component ID to avoid Alpine.js conflicts if multiple cards on one page
$_componentId = 'aiSummary_' . $_entityType . '_' . $_entityId . '_' . $_summaryType;
?>

<div class="card" style="margin-bottom:16px;" x-data="<?= e($_componentId) ?>()">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:1rem;">&#10024;</span>
            <?= e($_title) ?>
            <span class="badge badge-info" style="font-size:0.65rem;">AI</span>
        </div>
        <div style="display:flex;gap:6px;">
            <button @click="load()" :disabled="loading" class="btn btn-secondary btn-sm" style="font-size:0.75rem;">
                <span x-show="!loading">Generate</span>
                <span x-show="loading" x-cloak>Generating...</span>
            </button>
            <button x-show="summary" @click="refresh()" :disabled="loading" class="btn btn-secondary btn-sm" style="font-size:0.75rem;" title="Regenerate">
                &#x21bb;
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Empty state -->
        <div x-show="!summary && !loading && !error" style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.8125rem;">
            Click "Generate" to create an AI-powered summary.
        </div>

        <!-- Loading state -->
        <div x-show="loading" x-cloak style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.8125rem;">
            <div style="display:inline-block;width:20px;height:20px;border:2px solid var(--border-default);border-top-color:var(--color-primary);border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:8px;"></div>
            <div>Analyzing data...</div>
        </div>

        <!-- Error state -->
        <div x-show="error" x-cloak style="padding:12px;background:rgba(220,38,38,0.05);border:1px solid rgba(220,38,38,0.15);border-radius:6px;font-size:0.8125rem;color:var(--color-danger);" x-text="error"></div>

        <!-- Summary content -->
        <div x-show="summary" x-cloak>
            <div x-html="renderMarkdown(summary)" style="font-size:0.8125rem;line-height:1.6;" class="ai-response"></div>
            <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--border-default);display:flex;justify-content:space-between;font-size:0.7rem;color:var(--text-muted);">
                <span x-text="cached ? 'Cached' : 'Fresh'" :style="cached ? 'color:var(--text-muted);' : 'color:var(--color-success);'"></span>
                <span x-text="generatedAt ? 'Generated: ' + generatedAt : ''"></span>
            </div>
        </div>
    </div>
</div>

<script>
function <?= e($_componentId) ?>() {
    return {
        summary: '',
        generatedAt: '',
        cached: false,
        loading: false,
        error: '',

        async load() {
            this.loading = true;
            this.error = '';
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/ai/summary') ?>?entity_type=<?= e($_entityType) ?>&entity_id=<?= (int) $_entityId ?>&summary_type=<?= e($_summaryType) ?>');
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    this.summary = r.summary;
                    this.generatedAt = r.generated_at || '';
                    this.cached = r.cached || false;
                } else {
                    this.error = r.message || 'Failed to generate summary.';
                }
            } catch(e) {
                this.error = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        async refresh() {
            this.loading = true;
            this.error = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/summary') ?>', {
                    entity_type: '<?= e($_entityType) ?>',
                    entity_id: <?= (int) $_entityId ?>,
                    summary_type: '<?= e($_summaryType) ?>',
                });
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    this.summary = r.summary;
                    this.generatedAt = r.generated_at || '';
                    this.cached = false;
                } else {
                    this.error = r.message || 'Failed to generate summary.';
                }
            } catch(e) {
                this.error = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        // WHY: Same markdown renderer as the main AI page (lightweight)
        renderMarkdown(text) {
            if (!text) return '';
            return text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/`([^`]+)`/g, '<code style="background:var(--bg-default);padding:1px 4px;border-radius:3px;font-size:0.75rem;">$1</code>')
                .replace(/^### (.+)$/gm, '<strong>$1</strong>')
                .replace(/^## (.+)$/gm, '<strong>$1</strong>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/^[\-\*] (.+)$/gm, '<li style="margin:2px 0;">$1</li>')
                .replace(/(<li.*<\/li>\n?)+/g, '<ul style="margin:4px 0;padding-left:16px;">$&</ul>')
                .replace(/\n\n/g, '</p><p style="margin:4px 0;">')
                .replace(/\n/g, '<br>');
        },
    };
}
</script>
