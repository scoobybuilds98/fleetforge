<?php
declare(strict_types=1);

/**
 * includes/partials/ai-panel.php
 *
 * AI Analysis side-panel component. Outputs a fixed right-side drawer that
 * streams the Claude response token-by-token. Include once per page; the
 * trigger button is emitted inline here so the page just needs one include.
 *
 * Required variables (set before including):
 *   $aiSummaryEntityType  — 'customer', 'lease', 'equipment_unit', 'fleet'
 *   $aiSummaryEntityId    — Entity ID (0 for fleet-level types)
 *   $aiSummaryType        — 'customer_insights', 'lease_summary', 'unit_analysis', etc.
 *   $aiSummaryTitle       — Panel title text (optional, defaults to "AI Analysis")
 *
 * @session S-AI-PANEL
 */

if (!function_exists('can') || !can('ai', 'view')) return;

$_aiEnabled = (bool) settings_get('ai.enabled', false);
$_hasApiKey = (bool) (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''));
if (!$_aiEnabled || !$_hasApiKey) return;

$_entityType  = $aiSummaryEntityType ?? '';
$_entityId    = $aiSummaryEntityId ?? 0;
$_summaryType = $aiSummaryType ?? '';
$_title       = $aiSummaryTitle ?? 'AI Analysis';

$_fleetLevelTypes  = ['fleet_health', 'accounting_overview'];
$_requiresEntityId = !in_array($_summaryType, $_fleetLevelTypes, true);

if ($_entityType === '' || $_summaryType === '') return;
if ($_requiresEntityId && $_entityId <= 0) return;

$_panelId = 'aiPanel_' . $_entityType . '_' . $_entityId . '_' . $_summaryType;
?>

<?php /* ── Backdrop (mobile only) ── */ ?>
<div class="ai-panel-backdrop" id="<?= e($_panelId) ?>_backdrop" onclick="<?= e($_panelId) ?>_close()" style="display:none;"></div>

<?php /* ── Panel ── */ ?>
<div class="ai-panel" id="<?= e($_panelId) ?>_panel" style="display:none;" x-data="<?= e($_panelId) ?>()">

    <?php /* Header */ ?>
    <div class="ai-panel__header">
        <div class="ai-panel__header-top">
            <div class="ai-panel__eyebrow">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:11px;height:11px;flex-shrink:0;" aria-hidden="true">
                    <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
                </svg>
                AI ANALYSIS
            </div>
            <button type="button" class="ai-panel__close" onclick="<?= e($_panelId) ?>_close()" title="Close panel">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <h2 class="ai-panel__title"><?= e($_title) ?></h2>

        <?php /* Action toolbar — shown once content is available */ ?>
        <div class="ai-panel__toolbar" x-show="summary || loading">
            <button
                type="button"
                class="btn btn-primary btn-sm"
                @click="generate()"
                :disabled="loading"
                x-show="!summary && !loading"
            >Generate</button>

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="regenerate()"
                :disabled="loading"
                x-show="summary && !loading"
                title="Regenerate"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;margin-right:3px;vertical-align:-2px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>
                </svg>
                Regenerate
            </button>

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="copyToClipboard()"
                x-show="summary && !loading"
                :title="copied ? 'Copied!' : 'Copy to clipboard'"
                :class="copied ? 'btn-success' : 'btn-secondary'"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;margin-right:3px;vertical-align:-2px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184"/>
                </svg>
                <span x-text="copied ? 'Copied!' : 'Copy'"></span>
            </button>

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="download()"
                x-show="summary && !loading"
                title="Download as Markdown"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;margin-right:3px;vertical-align:-2px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                </svg>
                Download
            </button>

            <span x-show="loading" class="ai-panel__generating">
                <span class="ai-panel__spinner"></span>
                Generating…
            </span>
        </div>
    </div>

    <?php /* Body */ ?>
    <div class="ai-panel__body">

        <!-- Empty / prompt state -->
        <div x-show="!summary && !loading && !error" class="ai-panel__empty">
            <div class="ai-panel__empty-graphic">
                <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:42px;height:42px;opacity:0.18;" aria-hidden="true">
                    <path d="M24 4L29 19L44 24L29 29L24 44L19 29L4 24L19 19L24 4Z" fill="currentColor"/>
                </svg>
            </div>
            <p class="ai-panel__empty-title">Generate AI Analysis</p>
            <p class="ai-panel__empty-desc">Claude will synthesise all available data for this <?= e(str_replace('_', ' ', $_entityType)) ?> and surface risks, opportunities, and specific action items.</p>
            <button type="button" class="btn btn-primary" @click="generate()" style="margin-top:4px;">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;margin-right:5px;vertical-align:-2px;" aria-hidden="true">
                    <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
                </svg>
                Generate Now
            </button>
        </div>

        <!-- Error state -->
        <div x-show="error && !loading" x-cloak class="ai-panel__error" x-text="error"></div>

        <!-- Streaming / result area -->
        <div x-show="summary || (loading && streamText)" x-cloak>
            <div class="ai-response" x-html="renderMarkdown(loading ? streamText : summary)"></div>
            <span x-show="loading" class="ai-summary-card__cursor">&#x2588;</span>
            <div x-show="!loading && summary" class="ai-panel__meta">
                <span :class="cached ? 'ai-panel__meta-cached' : 'ai-panel__meta-fresh'"
                      x-text="cached ? 'Cached result' : 'Fresh analysis'"></span>
                <span x-text="generatedAt ? 'Generated ' + generatedAt : ''"></span>
            </div>
        </div>
    </div>
</div>

<script>
// Open / close helpers (non-Alpine so the trigger button outside Alpine scope can call them)
function <?= e($_panelId) ?>_open() {
    document.getElementById('<?= e($_panelId) ?>_panel').style.display = 'flex';
    document.getElementById('<?= e($_panelId) ?>_backdrop').style.display = 'block';
    document.body.setAttribute('data-ai-panel-open', '1');
}
function <?= e($_panelId) ?>_close() {
    document.getElementById('<?= e($_panelId) ?>_panel').style.display = 'none';
    document.getElementById('<?= e($_panelId) ?>_backdrop').style.display = 'none';
    document.body.removeAttribute('data-ai-panel-open');
}

function <?= e($_panelId) ?>() {
    return {
        summary:     '',
        streamText:  '',
        generatedAt: '',
        cached:      false,
        loading:     false,
        error:       '',
        copied:      false,

        async generate() {
            if (this.loading) return;
            this.loading    = true;
            this.error      = '';
            this.streamText = '';
            try { await this._stream(false); }
            catch (e) { this.error = 'Network error — please try again.'; }
            this.loading = false;
        },

        async regenerate() {
            if (this.loading) return;
            this.loading    = true;
            this.error      = '';
            this.streamText = '';
            this.summary    = '';
            try { await this._stream(true); }
            catch (e) { this.error = 'Network error — please try again.'; }
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
                this.error = j?.error?.message || j?.message || 'Failed to generate analysis.';
                return;
            }

            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let   buf     = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buf += decoder.decode(value, { stream: true });
                const parts = buf.split('\n\n');
                buf = parts.pop();

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
                        this.generatedAt = new Date().toLocaleString('en-CA', {
                            month: 'short', day: 'numeric',
                            hour: '2-digit', minute: '2-digit',
                        });
                    } else if (evt.t === 'err') {
                        this.error      = evt.m || 'Failed to generate analysis.';
                        this.streamText = '';
                        this.summary    = '';
                        return;
                    }
                }
            }
        },

        copyToClipboard() {
            if (!this.summary) return;
            navigator.clipboard.writeText(this.summary).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2200);
            });
        },

        download() {
            if (!this.summary) return;
            const ts       = new Date().toISOString().slice(0, 10);
            const filename = <?= json_encode(strtolower(str_replace(' ', '-', $_title))) ?> + '-' + ts + '.md';
            const header   = '# ' + <?= json_encode($_title) ?> + '\n_Generated ' + new Date().toLocaleString('en-CA') + '_\n\n';
            const blob     = new Blob([header + this.summary], { type: 'text/markdown' });
            const url      = URL.createObjectURL(blob);
            const a        = document.createElement('a');
            a.href         = url;
            a.download     = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        renderMarkdown(text) {
            if (!text) return '';
            let h = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            h = h.replace(/```[\w]*\n?([\s\S]*?)```/g,
                '<pre class="ai-code-block"><code>$1</code></pre>');
            h = h.replace(/`([^`\n]+)`/g,
                '<code class="ai-inline-code">$1</code>');
            h = h.replace(/^### (.+)$/gm,
                '<div class="ai-section-header">$1</div>');
            h = h.replace(/^## (.+)$/gm,
                '<div class="ai-section-header ai-section-header--h2">$1</div>');
            h = h.replace(/^# (.+)$/gm,
                '<div class="ai-section-header ai-section-header--h1">$1</div>');
            h = h.replace(/^\*\*([^*\n]{3,})\*\*\s*$/gm,
                '<div class="ai-section-header">$1</div>');
            h = h.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
            h = h.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
            h = h.replace(/^[•\-] (.+)$/gm, '<li>$1</li>');
            h = h.replace(/(<li>[\s\S]*?<\/li>(\n|$))+/g,
                (m) => '<ul class="ai-list">' + m + '</ul>');
            h = h.replace(/^---+$/gm, '<hr class="ai-divider">');
            h = h.replace(/\n\n+/g, '</p><p class="ai-para">');
            h = h.replace(/\n/g, '<br>');
            return '<p class="ai-para">' + h + '</p>';
        },
    };
}
</script>
