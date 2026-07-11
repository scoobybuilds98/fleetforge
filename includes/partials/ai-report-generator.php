<?php
declare(strict_types=1);

/**
 * includes/partials/ai-report-generator.php
 *
 * Reusable AI visualization generator component. Drop into any page
 * that needs AI-powered chart + table generation from natural language.
 *
 * Renders an input bar, quick-action buttons, and a results area that
 * can display ApexCharts, HTML tables, and markdown text — all from
 * a single Claude response.
 *
 * Required: ApexCharts must be loaded (already in footer.php).
 *
 * Optional variables (set before including):
 *   $aiVizId        — unique Alpine component name (default: 'FF_AiViz')
 *   $aiVizContext    — 'reports' | 'analytics' | 'chat' (controls quick-actions)
 *   $aiVizCompact    — true for inline/embedded mode, false for full card (default: false)
 *
 * @depends api/v1/ai/generate-visual.php, ApexCharts v3
 * @session S027
 */

// WHY: Only show if user has AI + reports access and AI is configured
if (!function_exists('can') || !can('ai', 'view') || !can('reports', 'view')) return;

// INT-1: settings table FIRST, .env SECOND.
$_vizEnabled = (bool) settings_get('ai.enabled', false);
$_vizHasKey  = (bool) (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''));
if (!$_vizEnabled || !$_vizHasKey) return;

$_vizId      = $aiVizId ?? 'FF_AiViz';
$_vizContext  = $aiVizContext ?? 'reports';
$_vizCompact  = $aiVizCompact ?? false;

// WHY: Quick-action presets depend on the page context
$_quickActions = [];
if ($_vizContext === 'reports') {
    $_quickActions = [
        'Revenue by customer this quarter as a bar chart',
        'Monthly revenue trend for the last 6 months',
        'Fleet utilization breakdown by category as a donut chart',
        'Overdue invoices by aging bucket',
        'Top 10 customers by outstanding balance',
    ];
} elseif ($_vizContext === 'analytics') {
    $_quickActions = [
        'Revenue forecast for the next 3 months as a line chart',
        'Fleet utilization vs revenue per unit as a scatter chart',
        'Customer concentration — top 5 customers by revenue',
        'Seasonal revenue patterns by month',
        'Equipment category distribution as a pie chart',
    ];
} else {
    $_quickActions = [
        'Show revenue by customer as a bar chart',
        'Fleet utilization trend as a line chart',
        'AR aging breakdown as a donut chart',
        'Overdue invoices summary table',
    ];
}
?>

<!-- ── AI Visualization Generator ──────────────────────────────────────────── -->
<div x-data="<?= e($_vizId) ?>()" class="ff-viz-root" style="width:100%;">

    <?php if (!$_vizCompact): ?>
    <!-- Full card mode -->
    <div style="margin-bottom:16px;width:100%;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span style="font-weight:600;font-size:1rem;">AI Report Generator</span>
                <span class="badge badge-info" style="font-size:0.6rem;">AI</span>
            </div>
            <span x-show="tokensUsed > 0" x-cloak style="font-size:0.7rem;color:var(--text-secondary);" class="font-mono"
                  x-text="tokensUsed.toLocaleString() + ' tokens'"></span>
        </div>
    <?php endif; ?>

            <!-- Input area — full-width field, button below -->
            <form @submit.prevent="generate()" style="width:100%;">
                <div style="width:100%;display:flex;align-items:center;border:1px solid var(--border-color);border-radius:12px;padding:8px 16px;background:var(--bg-surface-2);transition:border-color 0.15s,box-shadow 0.15s;box-sizing:border-box;"
                     :style="focused ? 'border-color:var(--color-primary);box-shadow:0 0 0 3px var(--color-primary-light);' : ''">
                    <input type="text" x-model="prompt" x-ref="vizInput"
                           @focus="focused=true" @blur="focused=false"
                           @keydown.enter.prevent="generate()"
                           placeholder="Describe the chart or report you want..."
                           :disabled="loading"
                           style="width:100%;border:none;background:transparent;outline:none;font-size:1rem;color:var(--text-primary);padding:10px 0;font-family:inherit;">
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:10px;">
                    <button type="submit" :disabled="loading || !prompt.trim()"
                            style="height:44px;padding:0 24px;border-radius:10px;border:none;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;transition:all 0.15s;font-size:0.875rem;font-weight:600;font-family:inherit;"
                            :style="prompt.trim()
                                ? 'background:var(--color-primary);color:#fff;'
                                : 'background:var(--bg-surface-hover);color:var(--text-secondary);cursor:not-allowed;'">
                        <template x-if="!loading">
                            <span style="display:flex;align-items:center;gap:6px;">
                                Generate
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </span>
                        </template>
                        <template x-if="loading">
                            <span style="display:flex;align-items:center;gap:6px;">
                                Generating
                                <div style="width:16px;height:16px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 0.7s linear infinite;"></div>
                            </span>
                        </template>
                    </button>
                </div>
            </form>

            <!-- Quick-action chips -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
                <?php foreach ($_quickActions as $qa): ?>
                <button @click="prompt = '<?= e($qa) ?>'; $nextTick(() => generate())"
                        class="ff-viz-chip" :disabled="loading">
                    <?= e(mb_strimwidth($qa, 0, 50, '...')) ?>
                </button>
                <?php endforeach; ?>
            </div>

    <?php if (!$_vizCompact): ?>
    </div>
    <?php endif; ?>

    <!-- ── Loading state ─────────────────────────────────────────────────── -->
    <div x-show="loading" x-cloak style="margin-top:16px;">
        <div class="card">
            <div class="card-body" style="text-align:center;padding:40px 20px;">
                <div style="display:inline-block;width:28px;height:28px;border:3px solid var(--border-color);border-top-color:var(--color-primary);border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:12px;"></div>
                <div style="font-size:0.875rem;color:var(--text-secondary);">Querying data and generating visualization...</div>
                <div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;opacity:0.7;">This may take a few seconds</div>
            </div>
        </div>
    </div>

    <!-- ── Error state ───────────────────────────────────────────────────── -->
    <div x-show="error" x-cloak style="margin-top:16px;">
        <div class="card" style="border-color:rgba(220,38,38,0.3);">
            <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-danger)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span style="font-size:0.8125rem;color:var(--color-danger);" x-text="error"></span>
            </div>
        </div>
    </div>

    <!-- ── Results ───────────────────────────────────────────────────────── -->
    <div x-show="results.length > 0" x-cloak style="margin-top:16px;">
        <template x-for="(block, bIdx) in results" :key="bIdx">
            <div style="margin-bottom:16px;">

                <!-- Text block -->
                <template x-if="block.type === 'text'">
                    <div class="card">
                        <div class="card-body" style="padding:16px 20px;">
                            <div x-html="renderMd(block.content)" class="ai-response" style="font-size:0.875rem;line-height:1.6;"></div>
                        </div>
                    </div>
                </template>

                <!-- Chart block -->
                <template x-if="block.type === 'chart'">
                    <div class="card">
                        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:600;font-size:0.875rem;" x-text="block.config.title || 'Chart'"></span>
                            <span class="badge badge-info" style="font-size:0.6rem;" x-text="block.config.type"></span>
                        </div>
                        <div class="card-body" style="padding:12px 8px;">
                            <div :id="'ffviz-chart-' + vizUid + '-' + bIdx" style="min-height:320px;"></div>
                        </div>
                    </div>
                </template>

                <!-- Table block -->
                <template x-if="block.type === 'table'">
                    <div class="card">
                        <template x-if="block.config.title">
                            <div class="card-header" style="font-weight:600;font-size:0.875rem;" x-text="block.config.title"></div>
                        </template>
                        <div class="card-body" style="padding:0;overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                                <thead>
                                    <tr style="background:var(--bg-surface-2);">
                                        <template x-for="(h, hi) in (block.config.headers || [])" :key="hi">
                                            <th style="padding:10px 14px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);white-space:nowrap;" x-text="h"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, ri) in (block.config.rows || [])" :key="ri">
                                        <tr style="border-bottom:1px solid var(--border-color);" :style="ri % 2 === 1 ? 'background:var(--bg-surface-2);opacity:0.7;' : ''">
                                            <template x-for="(cell, ci) in row" :key="ci">
                                                <td style="padding:8px 14px;white-space:nowrap;" x-text="cell"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                                <template x-if="block.config.footer">
                                    <tfoot>
                                        <tr style="background:var(--bg-surface-2);font-weight:600;border-top:2px solid var(--border-color-strong);">
                                            <template x-for="(f, fi) in block.config.footer" :key="fi">
                                                <td style="padding:10px 14px;" x-text="f"></td>
                                            </template>
                                        </tr>
                                    </tfoot>
                                </template>
                            </table>
                        </div>
                    </div>
                </template>

            </div>
        </template>
    </div>
</div>

<!-- ── Component Styles ───────────────────────────────────────────────────── -->
<style>
.ff-viz-chip {
    padding: 5px 12px;
    font-size: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 20px;
    background: var(--bg-surface);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
    white-space: nowrap;
}
.ff-viz-chip:hover:not(:disabled) {
    border-color: var(--color-primary);
    color: var(--color-primary);
    background: var(--color-primary-light);
}
.ff-viz-chip:disabled { opacity: 0.5; cursor: not-allowed; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<!-- ── Alpine Component ───────────────────────────────────────────────────── -->
<script>
function <?= e($_vizId) ?>() {
    return {
        prompt: '',
        loading: false,
        error: '',
        results: [],       // Array of {type:'text'|'chart'|'table', content|config}
        tokensUsed: 0,
        focused: false,
        chartInstances: [],
        vizUid: Math.random().toString(36).slice(2, 8),

        async generate() {
            const text = this.prompt.trim();
            if (!text || this.loading) return;

            this.loading = true;
            this.error = '';
            this.results = [];
            this.tokensUsed = 0;
            this.destroyCharts();

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/generate-visual') ?>', {
                    prompt: text,
                });

                // WHY: FF_Api returns raw JSON — no envelope
                if (r.error) {
                    this.error = r.message || 'Failed to generate visualization.';
                    this.loading = false;
                    return;
                }

                this.tokensUsed = r.tokens_used || 0;

                // Parse the content into renderable blocks
                this.results = this.parseResponse(r.text || r.content || '', r.visuals || []);

                // WHY: Charts need DOM to be rendered first, so wait for Alpine to update
                this.$nextTick(() => {
                    this.$nextTick(() => this.renderAllCharts());
                });

            } catch(e) {
                console.error('AI viz error:', e);
                this.error = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        // ── Parse response into ordered blocks ──────────────
        parseResponse(text, visuals) {
            const blocks = [];

            if (visuals.length === 0) {
                // No visuals — entire response is text
                if (text.trim()) blocks.push({ type: 'text', content: text });
                return blocks;
            }

            // Split on <!--visual:N--> placeholders
            const parts = text.split(/<!--visual:(\d+)-->/);
            for (let i = 0; i < parts.length; i++) {
                const part = parts[i].trim();
                if (!part) continue;

                // Even indices are text, odd indices are visual references
                if (i % 2 === 0) {
                    if (part) blocks.push({ type: 'text', content: part });
                } else {
                    const vIdx = parseInt(part, 10);
                    if (visuals[vIdx]) {
                        const v = visuals[vIdx];
                        blocks.push({
                            type: v._type || (v.series ? 'chart' : 'table'),
                            config: v,
                        });
                    }
                }
            }

            // WHY: If backend didn't inject placeholders, append visuals after text
            if (!text.includes('<!--visual:')) {
                if (text.trim()) blocks.push({ type: 'text', content: text });
                visuals.forEach(v => {
                    blocks.push({
                        type: v._type || (v.series ? 'chart' : 'table'),
                        config: v,
                    });
                });
                // Clear the text-only block we added if there are duplicates
                if (blocks.length > 1 && blocks[0].type === 'text') {
                    // Already have it — no duplicates needed
                }
            }

            return blocks;
        },

        // ── Render all chart blocks ─────────────────────────
        renderAllCharts() {
            this.results.forEach((block, idx) => {
                if (block.type === 'chart') {
                    this.renderChart(idx, block.config);
                }
            });
        },

        renderChart(idx, config) {
            const elId = 'ffviz-chart-' + this.vizUid + '-' + idx;
            const el = document.getElementById(elId);
            if (!el) return;

            // WHY: Read theme from document for ApexCharts dark/light mode
            const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
            const textColor = isDark ? '#94a3b8' : '#64748b';
            const gridColor = isDark ? '#1e2230' : '#e2e8f0';

            // Default brand palette
            const defaultColors = [
                'var(--color-primary)', '#3b82f6', '#22c55e', '#eab308',
                '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4',
            ];
            const resolvedColors = (config.colors || defaultColors).map(c => {
                if (c.startsWith('var(')) {
                    const raw = getComputedStyle(document.documentElement)
                        .getPropertyValue(c.slice(4, -1)).trim();
                    return raw || '#f97316';
                }
                return c;
            });

            // Value formatters
            const fmt = config.value_format || 'number';
            const yFormatter = (val) => {
                if (fmt === 'currency') return '$' + (val || 0).toLocaleString('en-CA', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                if (fmt === 'percent') return (val || 0).toFixed(1) + '%';
                return (val || 0).toLocaleString();
            };
            const tooltipFormatter = (val) => {
                if (fmt === 'currency') return '$' + (val || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                if (fmt === 'percent') return (val || 0).toFixed(1) + '%';
                return (val || 0).toLocaleString();
            };

            const chartType = config.type || 'bar';
            const isPie = ['pie', 'donut', 'radialBar'].includes(chartType);

            // Build ApexCharts options
            // WHY: Atelier theme (background, foreColor, palette, grid, axis/legend/tooltip
            // colours, theme.mode from data-theme) is owned by FF_CHART_THEME(); only
            // series/data, formatters, and deliberate per-chart overrides live here.
            const opts = {
                chart: {
                    type: chartType,
                    height: 340,
                    toolbar: { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } },
                },
                // WHY: honour AI-report-supplied colours; otherwise defer to the base palette
                ...(config.colors ? { colors: resolvedColors } : {}),
                ...(isPie ? {
                    labels: config.categories || [],
                    series: (config.series?.[0]?.data || config.series || []),
                } : {
                    xaxis: {
                        categories: config.categories || [],
                        labels: { rotate: -45, rotateAlways: (config.categories || []).length > 8 },
                    },
                    yaxis: {
                        labels: { formatter: yFormatter },
                    },
                    series: config.series || [],
                }),
                grid: { strokeDashArray: 3 },
                dataLabels: { enabled: isPie },
                stroke: { curve: 'smooth', width: chartType === 'line' ? 3 : chartType === 'area' ? 2 : 0 },
                fill: { opacity: chartType === 'area' ? 0.15 : 1 },
                tooltip: {
                    y: { formatter: tooltipFormatter },
                },
                legend: {
                    position: isPie ? 'bottom' : 'top',
                },
                plotOptions: {
                    bar: { borderRadius: 4, columnWidth: '60%', distributed: (config.series || []).length <= 1 },
                    pie: { donut: { size: chartType === 'donut' ? '60%' : '0%' } },
                    radialBar: { hollow: { size: '50%' } },
                },
            };

            if (config.stacked) {
                opts.chart.stacked = true;
            }

            try {
                const chart = new ApexCharts(el, FF_CHART_THEME(opts));
                chart.render();
                this.chartInstances.push(chart);
            } catch(e) {
                console.error('Chart render failed:', e);
                el.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-secondary);font-size:0.8125rem;">Chart rendering failed.</div>';
            }
        },

        // ── Auto-resize textarea ────────────────────────────
        autoResizeViz(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        },

        // ── Cleanup ─────────────────────────────────────────
        destroyCharts() {
            this.chartInstances.forEach(c => {
                try { c.destroy(); } catch(e) {}
            });
            this.chartInstances = [];
        },

        // ── Lightweight markdown renderer ───────────────────
        renderMd(text) {
            if (!text) return '';
            return text
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
                .replace(/`([^`]+)`/g, '<code style="background:var(--bg-surface-2);padding:1px 4px;border-radius:3px;font-size:0.8125rem;">$1</code>')
                .replace(/^### (.+)$/gm, '<h3 style="font-size:0.9375rem;font-weight:600;margin:12px 0 4px;">$1</h3>')
                .replace(/^## (.+)$/gm, '<h2 style="font-size:1rem;font-weight:600;margin:14px 0 6px;">$1</h2>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/^[\-\*] (.+)$/gm, '<li style="margin:2px 0;">$1</li>')
                .replace(/(<li.*<\/li>\n?)+/g, '<ul style="margin:4px 0 8px;padding-left:18px;">$&</ul>')
                .replace(/\n\n/g, '</p><p style="margin:6px 0;">')
                .replace(/\n/g, '<br>');
        },
    };
}
</script>
