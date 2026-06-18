<?php
declare(strict_types=1);

/**
 * includes/partials/ai-summary-card.php
 *
 * Reusable AI summary card component. Streams the Claude response token-by-token
 * via SSE so text appears immediately — no waiting for the full generation.
 *
 * Required variables (set before including):
 *   $aiSummaryEntityType  — 'customer', 'lease', 'equipment_unit'
 *   $aiSummaryEntityId    — Entity ID
 *   $aiSummaryType        — 'customer_insights', 'lease_summary', 'unit_analysis'
 *   $aiSummaryTitle       — Card header text (optional, defaults to "AI Insights")
 *
 * @session S-AI-STREAM-SUMMARY
 */

// WHY: Only show if user has AI access and AI is configured
if (!function_exists('can') || !can('ai', 'view')) return;

$_aiEnabled  = (bool) settings_get('ai.enabled', false);
$_hasApiKey  = (bool) (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''));
if (!$_aiEnabled || !$_hasApiKey) return;

$_entityType  = $aiSummaryEntityType ?? '';
$_entityId    = $aiSummaryEntityId ?? 0;
$_summaryType = $aiSummaryType ?? '';
$_title       = $aiSummaryTitle ?? 'AI Insights';

$_fleetLevelTypes  = ['fleet_health', 'accounting_overview'];
$_requiresEntityId = !in_array($_summaryType, $_fleetLevelTypes, true);

if ($_entityType === '' || $_summaryType === '') return;
if ($_requiresEntityId && $_entityId <= 0) return;

// WHY: Unique component ID to avoid Alpine.js conflicts if multiple cards on one page
$_componentId = 'aiSummary_' . $_entityType . '_' . $_entityId . '_' . $_summaryType;
?>

<div class="card ai-summary-card" style="margin-bottom:16px;" x-data="<?= e($_componentId) ?>()">
    <div class="card-header">
        <div class="ai-summary-card__header-left">
            <svg class="ai-summary-card__icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor" opacity="0.9"/>
            </svg>
            <span class="ai-summary-card__title"><?= e($_title) ?></span>
            <span class="badge badge-info ai-summary-card__badge">AI</span>
        </div>
        <div class="ai-summary-card__header-right">
            <button
                @click="generate()"
                :disabled="loading"
                class="btn btn-secondary btn-sm ai-summary-card__btn"
                x-show="!summary"
            >
                <span x-show="!loading">Generate</span>
                <span x-show="loading" x-cloak class="ai-summary-card__btn-loading">
                    <span class="ai-summary-card__spinner"></span>
                    Generating…
                </span>
            </button>
            <template x-if="summary">
                <div style="display:flex;gap:6px;">
                    <button
                        @click="regenerate()"
                        :disabled="loading"
                        class="btn btn-secondary btn-sm ai-summary-card__btn"
                        title="Regenerate"
                    >
                        <span x-show="!loading">&#x21bb; Regenerate</span>
                        <span x-show="loading" x-cloak class="ai-summary-card__btn-loading">
                            <span class="ai-summary-card__spinner"></span>
                            Generating…
                        </span>
                    </button>
                </div>
            </template>
        </div>
    </div>

    <div class="card-body">
        <!-- Empty state -->
        <div x-show="!summary && !loading && !error" class="ai-summary-card__empty">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;opacity:0.35;margin-bottom:10px;">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
            </svg>
            <div style="font-weight:500;margin-bottom:4px;">Generate AI Insights</div>
            <div style="font-size:0.75rem;opacity:0.7;">Click "Generate" to get an AI-powered analysis of this <?= e(str_replace('_', ' ', $_entityType)) ?>.</div>
        </div>

        <!-- Error state -->
        <div x-show="error" x-cloak class="ai-summary-card__error" x-text="error"></div>

        <!-- Streaming / result area -->
        <div x-show="summary || (loading && streamText)" x-cloak>
            <div class="ai-response" x-html="renderMarkdown(loading ? streamText : summary)"></div>

            <!-- Streaming cursor -->
            <span x-show="loading" x-cloak class="ai-summary-card__cursor">▌</span>

            <!-- Footer meta (only when done) -->
            <div x-show="!loading && summary" x-cloak class="ai-summary-card__meta">
                <span :class="cached ? 'ai-summary-card__meta-cached' : 'ai-summary-card__meta-fresh'" x-text="cached ? '● Cached' : '● Fresh'"></span>
                <span x-text="generatedAt ? 'Generated ' + generatedAt : ''"></span>
            </div>
        </div>
    </div>
</div>

<script>
function <?= e($_componentId) ?>() {
    return {
        summary:     '',
        streamText:  '',
        generatedAt: '',
        cached:      false,
        loading:     false,
        error:       '',

        async generate() {
            if (this.loading) return;
            this.loading   = true;
            this.error     = '';
            this.streamText = '';

            try {
                await this._stream(false);
            } catch (e) {
                this.error = 'Network error — please try again.';
            }

            this.loading = false;
        },

        async regenerate() {
            if (this.loading) return;
            this.loading    = true;
            this.error      = '';
            this.streamText = '';
            this.summary    = '';

            try {
                await this._stream(true);
            } catch (e) {
                this.error = 'Network error — please try again.';
            }

            this.loading = false;
        },

        async _stream(force) {
            const res = await fetch(<?= json_encode(base_url('api/v1/ai/summary-stream')) ?>, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': FF_CSRF_TOKEN },
                body:    JSON.stringify({
                    entity_type:  <?= json_encode($_entityType) ?>,
                    entity_id:    <?= (int) $_entityId ?>,
                    summary_type: <?= json_encode($_summaryType) ?>,
                    force:        force ? 1 : 0,
                }),
            });

            if (!res.ok || !res.body) {
                const j = await res.json().catch(() => ({}));
                this.error = j?.error?.message || j?.message || 'Failed to generate summary.';
                return;
            }

            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let   buf     = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buf += decoder.decode(value, { stream: true });

                // SSE lines are separated by \n\n
                const parts = buf.split('\n\n');
                buf = parts.pop(); // keep incomplete last chunk

                for (const part of parts) {
                    if (!part.startsWith('data: ')) continue;
                    let evt;
                    try { evt = JSON.parse(part.slice(6)); } catch { continue; }

                    if (evt.t === 'tok') {
                        this.streamText += evt.c;
                    } else if (evt.t === 'done') {
                        this.summary    = this.streamText;
                        this.streamText = '';
                        this.cached     = false;
                        this.generatedAt = new Date().toLocaleString('en-CA', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    } else if (evt.t === 'err') {
                        this.error      = evt.m || 'Failed to generate summary.';
                        this.streamText = '';
                        this.summary    = '';
                        return;
                    }
                }
            }
        },

        // Professional markdown renderer — handles bold headers, bullets, inline code, bold, italic
        renderMarkdown(text) {
            if (!text) return '';
            let h = text
                // Escape HTML
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                // Fenced code blocks (``` ... ```)
                .replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre class="ai-code-block"><code>$1</code></pre>')
                // Inline code
                .replace(/`([^`\n]+)`/g, '<code class="ai-inline-code">$1</code>')
                // H3 — bold line used as section header
                .replace(/^### (.+)$/gm, '<h4 class="ai-section-header">$1</h4>')
                // H2 — larger section header
                .replace(/^## (.+)$/gm, '<h3 class="ai-section-header ai-section-header--h2">$1</h3>')
                // H1
                .replace(/^# (.+)$/gm, '<h2 class="ai-section-header ai-section-header--h1">$1</h2>')
                // Bold lines that act as headers (entire line is **text**)
                .replace(/^\*\*([^*\n]+)\*\*\s*$/gm, '<h4 class="ai-section-header">$1</h4>')
                // Bold inline
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                // Italic inline
                .replace(/\*([^*\n]+)\*/g, '<em>$1</em>')
                // Bullet lists — convert runs of - or * lines into <ul>
                .replace(/^[•\-\*] (.+)$/gm, '<li>$1</li>')
                .replace(/(<li>[\s\S]*?<\/li>)(\n<li>|$)/g, '$1$2')
                .replace(/(<li>.*?<\/li>\n?)+/g, (match) => '<ul class="ai-list">' + match + '</ul>')
                // Numbered lists
                .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
                // Horizontal rules
                .replace(/^---+$/gm, '<hr class="ai-divider">')
                // Double newline → paragraph break
                .replace(/\n\n/g, '</p><p class="ai-para">')
                // Single newline → <br> inside paragraphs
                .replace(/\n/g, '<br>');

            // Wrap in a paragraph so single-line content gets spacing too
            return '<p class="ai-para">' + h + '</p>';
        },
    };
}
</script>
