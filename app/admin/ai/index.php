<?php
declare(strict_types=1);

/**
 * app/admin/ai/index.php
 *
 * AI Assistant — full-page chat interface with session management,
 * document analysis, summaries, reports, and anomaly alerts.
 *
 * Features:
 *   - Chat with FleetForge AI (tool-calling for real data queries)
 *   - SSE streaming for typewriter-style responses
 *   - Session history sidebar with search
 *   - Document analysis (upload PDF/images for AI extraction)
 *   - Natural language report generation
 *   - Anomaly alerts panel
 *   - Token usage stats (admin view)
 *
 * Alpine.js component: FF_AiChat()
 *
 * Permission: ai:view
 *
 * @depends lib/AI/ClaudeClient.php, api/v1/ai/*.php
 * @session S027
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('ai', 'view');

$pageTitle = 'AI Assistant';
require_once FF_ROOT . '/includes/header.php';

// WHY: Check if AI is configured so we can show setup prompt if needed
$aiEnabled  = (bool) settings_get('ai.enabled', false);
$hasApiKey  = defined('AI_ANTHROPIC_API_KEY') && AI_ANTHROPIC_API_KEY !== '';
$aiReady    = $aiEnabled && $hasApiKey;
$canCreate  = can('ai', 'create');
$canEdit    = can('ai', 'edit');
$isAdmin    = can('settings', 'view');
?>

<!-- ── Page Header ────────────────────────────────────────────────────────── -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-header-title" style="display:flex;align-items:center;gap:8px;">
            AI Assistant
            <span class="badge badge-info" style="font-size:0.7rem;vertical-align:middle;">Beta</span>
        </h1>
        <p style="margin:4px 0 0;font-size:0.8125rem;color:var(--text-muted);">
            Ask questions about your fleet, customers, leases, and financial data
        </p>
    </div>
    <?php if ($isAdmin): ?>
    <div style="display:flex;gap:8px;align-items:center;">
        <a href="<?= base_url('settings') ?>?tab=integrations" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">
            AI Settings
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$aiReady): ?>
<!-- ── Setup Required ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:16px;">
    <div class="card-body" style="text-align:center;padding:60px 20px;">
        <div style="font-size:2.5rem;margin-bottom:12px;">&#10024;</div>
        <h2 style="font-size:1.25rem;font-weight:600;margin:0 0 8px;">AI Assistant Not Configured</h2>
        <p style="color:var(--text-muted);margin:0 0 20px;max-width:420px;margin-left:auto;margin-right:auto;">
            To use the AI Assistant, you need to enable AI features and configure your Anthropic API key in Settings.
        </p>
        <?php if ($isAdmin): ?>
        <a href="<?= base_url('settings') ?>?tab=integrations" class="btn btn-primary">
            Configure AI Settings
        </a>
        <?php else: ?>
        <p style="font-size:0.875rem;color:var(--text-muted);">Contact your administrator to set up AI features.</p>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<!-- ── AI Chat Interface ──────────────────────────────────────────────────── -->
<div x-data="FF_AiChat()" x-init="init()" style="margin-top:8px;">

    <!-- Tab Navigation -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border-default);padding-bottom:0;">
        <template x-for="t in tabs" :key="t.key">
            <button @click="activeTab = t.key"
                    :class="activeTab === t.key ? 'btn-tab-active' : 'btn-tab'"
                    style="padding:8px 16px;font-size:0.8125rem;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;font-weight:500;"
                    :style="activeTab === t.key
                        ? 'border-bottom-color:var(--color-primary);color:var(--color-primary);'
                        : 'color:var(--text-secondary);'"
                    x-text="t.label">
            </button>
        </template>
    </div>

    <!-- ═══ CHAT TAB ═══ -->
    <div x-show="activeTab === 'chat'" x-cloak style="display:flex;gap:16px;height:calc(100vh - 220px);min-height:500px;">

        <!-- Session Sidebar -->
        <div style="width:260px;flex-shrink:0;display:flex;flex-direction:column;border:1px solid var(--border-default);border-radius:8px;background:var(--bg-surface);">
            <div style="padding:12px;border-bottom:1px solid var(--border-default);">
                <button @click="newSession()" class="btn btn-primary btn-sm" style="width:100%;font-size:0.8125rem;">
                    + New Chat
                </button>
            </div>
            <div style="padding:8px 12px;">
                <input type="text" x-model="sessionSearch" placeholder="Search chats..."
                       style="width:100%;padding:6px 10px;font-size:0.8125rem;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-default);">
            </div>
            <div style="flex:1;overflow-y:auto;padding:4px 8px;">
                <template x-for="s in filteredSessions" :key="s.id">
                    <div @click="loadSession(s.id)"
                         :style="currentSessionId === s.id
                             ? 'background:var(--color-primary-subtle);border-color:var(--color-primary);'
                             : 'border-color:transparent;'"
                         style="padding:8px 10px;border-radius:6px;cursor:pointer;margin-bottom:4px;border:1px solid transparent;transition:all 0.15s;">
                        <div style="font-size:0.8125rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                             x-text="s.title"></div>
                        <div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px;"
                             x-text="formatDate(s.last_message_at || s.created_at)"></div>
                    </div>
                </template>
                <div x-show="sessions.length === 0 && !sessionsLoading" style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.8125rem;">
                    No conversations yet
                </div>
            </div>
        </div>

        <!-- Chat Main Area -->
        <div style="flex:1;display:flex;flex-direction:column;border:1px solid var(--border-default);border-radius:8px;background:var(--bg-surface);overflow:hidden;">

            <!-- Messages -->
            <div x-ref="messagesContainer" style="flex:1;overflow-y:auto;padding:16px 20px;">
                <!-- Welcome state -->
                <div x-show="messages.length === 0 && !sending" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;">
                    <div style="font-size:2.5rem;margin-bottom:12px;">&#10024;</div>
                    <h3 style="font-size:1.125rem;font-weight:600;margin:0 0 8px;">How can I help?</h3>
                    <p style="color:var(--text-muted);font-size:0.875rem;max-width:400px;margin:0 0 20px;">
                        Ask me anything about your fleet, customers, leases, invoices, or compliance data.
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">
                        <button @click="sendQuickPrompt('What are the current fleet utilization stats?')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Fleet utilization</button>
                        <button @click="sendQuickPrompt('Show me all overdue invoices')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Overdue invoices</button>
                        <button @click="sendQuickPrompt('What compliance documents expire this month?')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Expiring compliance</button>
                        <button @click="sendQuickPrompt('Give me the dashboard KPIs')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Dashboard KPIs</button>
                    </div>
                </div>

                <!-- Messages list -->
                <template x-for="(msg, idx) in messages" :key="idx">
                    <div style="margin-bottom:16px;"
                         :style="msg.role === 'user' ? 'display:flex;justify-content:flex-end;' : ''">
                        <div :style="msg.role === 'user'
                            ? 'background:var(--color-primary);color:white;border-radius:12px 12px 2px 12px;padding:10px 14px;max-width:70%;'
                            : 'background:var(--bg-default);border:1px solid var(--border-default);border-radius:2px 12px 12px 12px;padding:12px 16px;max-width:85%;'">
                            <!-- User message -->
                            <div x-show="msg.role === 'user'" x-text="msg.content" style="font-size:0.875rem;white-space:pre-wrap;"></div>
                            <!-- Assistant message (rendered as markdown-lite) -->
                            <div x-show="msg.role === 'assistant'" x-html="renderMarkdown(msg.content)" style="font-size:0.875rem;line-height:1.6;" class="ai-response"></div>
                        </div>
                    </div>
                </template>

                <!-- Streaming indicator -->
                <div x-show="sending" x-cloak style="margin-bottom:16px;">
                    <div style="background:var(--bg-default);border:1px solid var(--border-default);border-radius:2px 12px 12px 12px;padding:12px 16px;max-width:85%;">
                        <div x-show="toolRunning" style="font-size:0.8125rem;color:var(--text-muted);margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                            <span class="spinner-sm"></span>
                            <span>Looking up <span x-text="toolName" class="font-mono"></span>...</span>
                        </div>
                        <div x-show="streamText" x-html="renderMarkdown(streamText)" style="font-size:0.875rem;line-height:1.6;" class="ai-response"></div>
                        <div x-show="!streamText && !toolRunning" style="display:flex;gap:4px;padding:4px;">
                            <span class="typing-dot"></span>
                            <span class="typing-dot" style="animation-delay:0.2s;"></span>
                            <span class="typing-dot" style="animation-delay:0.4s;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Input area -->
            <div style="border-top:1px solid var(--border-default);padding:12px 16px;background:var(--bg-surface);">
                <form @submit.prevent="sendMessage()" style="display:flex;gap:8px;align-items:flex-end;">
                    <textarea x-model="inputText" x-ref="chatInput"
                              @keydown.enter.prevent="if(!$event.shiftKey) sendMessage()"
                              placeholder="Ask about your fleet, customers, leases..."
                              :disabled="sending"
                              rows="1"
                              style="flex:1;resize:none;padding:10px 14px;border:1px solid var(--border-default);border-radius:8px;font-size:0.875rem;background:var(--bg-default);font-family:inherit;min-height:42px;max-height:120px;"
                              @input="autoResize($event.target)"></textarea>
                    <button type="submit" class="btn btn-primary" :disabled="sending || !inputText.trim()"
                            style="height:42px;padding:0 20px;border-radius:8px;white-space:nowrap;">
                        <span x-show="!sending">Send</span>
                        <span x-show="sending" x-cloak>...</span>
                    </button>
                </form>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
                    <span style="font-size:0.7rem;color:var(--text-muted);">
                        Shift+Enter for new line &middot; AI responses may be inaccurate
                    </span>
                    <span x-show="currentSessionId > 0" x-cloak style="font-size:0.7rem;color:var(--text-muted);">
                        Session #<span x-text="currentSessionId" class="font-mono"></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ REPORTS TAB ═══ -->
    <div x-show="activeTab === 'reports'" x-cloak>
        <?php
        // WHY: Full AI visual generator replaces the old text-only report tab
        $aiVizId      = 'FF_AiVizChat';
        $aiVizContext  = 'chat';
        $aiVizCompact  = false;
        include FF_ROOT . '/includes/partials/ai-report-generator.php';
        ?>
    </div>

    <!-- ═══ DOCUMENTS TAB ═══ -->
    <?php if ($canCreate): ?>
    <div x-show="activeTab === 'documents'" x-cloak>
        <div class="card">
            <div class="card-header" style="font-weight:600;">Document Analysis</div>
            <div class="card-body">
                <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 16px;">
                    Upload a document (PDF, image) and AI will extract and analyze the content.
                </p>
                <form @submit.prevent="analyzeDocument()">
                    <div style="border:2px dashed var(--border-default);border-radius:8px;padding:30px;text-align:center;margin-bottom:12px;cursor:pointer;transition:border-color 0.2s;"
                         @dragover.prevent="$event.currentTarget.style.borderColor='var(--color-primary)'"
                         @dragleave.prevent="$event.currentTarget.style.borderColor='var(--border-default)'"
                         @drop.prevent="handleDocDrop($event)"
                         @click="$refs.docFileInput.click()">
                        <input type="file" x-ref="docFileInput" @change="docFile = $event.target.files[0]" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp" style="display:none;">
                        <div x-show="!docFile" style="color:var(--text-muted);">
                            <div style="font-size:1.5rem;margin-bottom:6px;">&#128196;</div>
                            <div style="font-size:0.875rem;">Drop a file here or click to browse</div>
                            <div style="font-size:0.75rem;margin-top:4px;">PDF, PNG, JPG, GIF, WEBP (max 10MB)</div>
                        </div>
                        <div x-show="docFile" x-cloak style="font-size:0.875rem;">
                            <strong x-text="docFile?.name"></strong>
                            <span style="color:var(--text-muted);margin-left:8px;" x-text="docFile ? (docFile.size / 1024).toFixed(0) + ' KB' : ''"></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <input type="text" x-model="docPrompt" placeholder="Optional: specific analysis instructions..."
                               style="flex:1;padding:10px 14px;border:1px solid var(--border-default);border-radius:8px;font-size:0.875rem;background:var(--bg-default);">
                        <button type="submit" class="btn btn-primary" :disabled="!docFile || docLoading">
                            <span x-show="!docLoading">Analyze</span>
                            <span x-show="docLoading" x-cloak>Analyzing...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div x-show="docResult" x-cloak class="card" style="margin-top:16px;">
            <div class="card-header" style="font-weight:600;display:flex;justify-content:space-between;align-items:center;">
                <span>Analysis: <span x-text="docFileName" style="font-weight:400;"></span></span>
                <span style="font-size:0.75rem;color:var(--text-muted);" class="font-mono" x-text="docTokens + ' tokens'"></span>
            </div>
            <div class="card-body">
                <div x-html="renderMarkdown(docResult)" class="ai-response" style="font-size:0.875rem;line-height:1.6;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ ALERTS TAB ═══ -->
    <div x-show="activeTab === 'alerts'" x-cloak>
        <div class="card">
            <div class="card-header" style="font-weight:600;display:flex;justify-content:space-between;align-items:center;">
                <span>Anomaly Alerts</span>
                <div style="display:flex;gap:8px;">
                    <label style="font-size:0.8125rem;color:var(--text-secondary);display:flex;align-items:center;gap:4px;cursor:pointer;">
                        <input type="checkbox" x-model="alertsUnreadOnly" @change="loadAlerts()" style="accent-color:var(--color-primary);">
                        Unread only
                    </label>
                    <?php if (can('settings', 'edit')): ?>
                    <button @click="runAnomalyScan()" class="btn btn-secondary btn-sm" :disabled="scanRunning" style="font-size:0.8125rem;">
                        <span x-show="!scanRunning">Run Scan</span>
                        <span x-show="scanRunning" x-cloak>Scanning...</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <template x-if="alerts.length === 0 && !alertsLoading">
                    <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:0.875rem;">
                        No anomaly alerts found.
                    </div>
                </template>
                <template x-for="alert in alerts" :key="alert.id">
                    <div style="padding:14px 20px;border-bottom:1px solid var(--border-default);display:flex;gap:12px;align-items:start;">
                        <div style="flex-shrink:0;width:8px;height:8px;border-radius:50%;margin-top:6px;"
                             :style="alert.severity === 'high' ? 'background:var(--color-danger);'
                                   : alert.severity === 'medium' ? 'background:var(--color-warning);'
                                   : 'background:var(--color-info);'"></div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.875rem;font-weight:600;" x-text="alert.title"></div>
                            <div style="font-size:0.8125rem;color:var(--text-secondary);margin-top:2px;" x-text="alert.description"></div>
                            <div style="font-size:0.7rem;color:var(--text-muted);margin-top:4px;display:flex;gap:12px;">
                                <span x-text="formatDate(alert.created_at)"></span>
                                <span class="badge" style="font-size:0.65rem;"
                                      :class="alert.severity === 'high' ? 'badge-danger' : alert.severity === 'medium' ? 'badge-warning' : 'badge-info'"
                                      x-text="alert.severity"></span>
                                <span style="font-style:italic;" x-text="alert.alert_type.replace('_', ' ')"></span>
                            </div>
                        </div>
                        <?php if ($canEdit): ?>
                        <button x-show="!alert.acknowledged_at" @click="acknowledgeAlert(alert.id)"
                                class="btn btn-secondary btn-sm" style="font-size:0.75rem;white-space:nowrap;">
                            Dismiss
                        </button>
                        <span x-show="alert.acknowledged_at" style="font-size:0.75rem;color:var(--color-success);">&#10003;</span>
                        <?php endif; ?>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ═══ USAGE TAB (Admin only) ═══ -->
    <?php if ($isAdmin): ?>
    <div x-show="activeTab === 'usage'" x-cloak>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:16px;">
            <div class="card">
                <div class="card-body" style="text-align:center;padding:20px;">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Today's Tokens</div>
                    <div class="font-mono" style="font-size:1.5rem;font-weight:700;" x-text="formatNumber(usage.today?.tokens || 0)"></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">
                        of <span x-text="formatNumber(usage.today?.limit || 0)"></span> limit
                    </div>
                    <div style="margin-top:8px;height:4px;background:var(--border-default);border-radius:2px;overflow:hidden;">
                        <div style="height:100%;border-radius:2px;transition:width 0.3s;"
                             :style="'width:' + Math.min(100, ((usage.today?.tokens||0) / Math.max(usage.today?.limit||1, 1)) * 100) + '%;background:' + (((usage.today?.tokens||0) / Math.max(usage.today?.limit||1, 1)) > 0.9 ? 'var(--color-danger)' : 'var(--color-primary)') + ';'"></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align:center;padding:20px;">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Today's Cost</div>
                    <div class="font-mono" style="font-size:1.5rem;font-weight:700;" x-text="'$' + (usage.today?.cost || 0).toFixed(4)"></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" x-text="(usage.today?.requests || 0) + ' requests'"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align:center;padding:20px;">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Month Tokens</div>
                    <div class="font-mono" style="font-size:1.5rem;font-weight:700;" x-text="formatNumber(usage.month?.tokens || 0)"></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" x-text="(usage.month?.requests || 0) + ' requests'"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="text-align:center;padding:20px;">
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Month Cost</div>
                    <div class="font-mono" style="font-size:1.5rem;font-weight:700;" x-text="'$' + (usage.month?.cost || 0).toFixed(4)"></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);" x-text="(usage.month?.requests || 0) + ' requests'"></div>
                </div>
            </div>
        </div>

        <!-- Per-user breakdown -->
        <div x-show="usage.by_user && usage.by_user.length > 0" class="card">
            <div class="card-header" style="font-weight:600;">Usage by User (This Month)</div>
            <div class="card-body" style="padding:0;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border-default);background:var(--bg-default);">
                            <th style="padding:10px 16px;text-align:left;font-weight:600;">User</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Requests</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Tokens</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="u in usage.by_user" :key="u.user_id">
                            <tr style="border-bottom:1px solid var(--border-default);">
                                <td style="padding:10px 16px;" x-text="u.user_name || 'System'"></td>
                                <td style="padding:10px 16px;text-align:right;" class="font-mono" x-text="u.requests"></td>
                                <td style="padding:10px 16px;text-align:right;" class="font-mono" x-text="formatNumber(u.tokens)"></td>
                                <td style="padding:10px 16px;text-align:right;" class="font-mono" x-text="'$' + parseFloat(u.cost).toFixed(4)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /x-data -->
<?php endif; ?>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
.typing-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--text-muted);
    display: inline-block;
    animation: typingPulse 1.2s infinite ease-in-out;
}
@keyframes typingPulse {
    0%, 80%, 100% { opacity: 0.3; transform: scale(0.8); }
    40% { opacity: 1; transform: scale(1); }
}
.spinner-sm {
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid var(--border-default);
    border-top-color: var(--color-primary);
    animation: spin 0.8s linear infinite;
    display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }
.ai-response h1, .ai-response h2, .ai-response h3 { margin: 12px 0 6px; font-size: 1rem; font-weight: 600; }
.ai-response h3 { font-size: 0.9375rem; }
.ai-response p { margin: 6px 0; }
.ai-response ul, .ai-response ol { margin: 6px 0; padding-left: 20px; }
.ai-response li { margin: 3px 0; }
.ai-response code { background: var(--bg-default); padding: 1px 4px; border-radius: 3px; font-size: 0.8125rem; font-family: 'DM Mono', monospace; }
.ai-response pre { background: var(--bg-default); padding: 10px 14px; border-radius: 6px; overflow-x: auto; margin: 8px 0; }
.ai-response pre code { background: none; padding: 0; }
.ai-response table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.8125rem; }
.ai-response table th, .ai-response table td { padding: 6px 10px; border: 1px solid var(--border-default); text-align: left; }
.ai-response table th { background: var(--bg-default); font-weight: 600; }
.ai-response strong { font-weight: 600; }
.ai-response blockquote { border-left: 3px solid var(--border-default); padding-left: 12px; margin: 8px 0; color: var(--text-secondary); }
</style>

<!-- ── Alpine.js Component ─────────────────────────────────────────────────── -->
<script>
function FF_AiChat() {
    return {
        // ── Tab state ──────────────────────
        activeTab: 'chat',
        tabs: [
            { key: 'chat', label: 'Chat' },
            { key: 'reports', label: 'Reports' },
            <?php if ($canCreate): ?>
            { key: 'documents', label: 'Documents' },
            <?php endif; ?>
            { key: 'alerts', label: 'Alerts' },
            <?php if ($isAdmin): ?>
            { key: 'usage', label: 'Usage' },
            <?php endif; ?>
        ],

        // ── Chat state ─────────────────────
        sessions: [],
        sessionsLoading: false,
        sessionSearch: '',
        currentSessionId: 0,
        messages: [],
        inputText: '',
        sending: false,
        streamText: '',
        toolRunning: false,
        toolName: '',

        // ── Reports state ──────────────────
        reportQuery: '',
        reportResult: '',
        reportTokens: 0,
        reportLoading: false,

        // ── Document state ─────────────────
        docFile: null,
        docPrompt: '',
        docResult: '',
        docFileName: '',
        docTokens: 0,
        docLoading: false,

        // ── Alerts state ───────────────────
        alerts: [],
        alertsLoading: false,
        alertsUnreadOnly: false,
        scanRunning: false,

        // ── Usage state ────────────────────
        usage: {},

        // ════════════════════════════════════
        //  LIFECYCLE
        // ���═══════════════════════════════════

        async init() {
            await this.loadSessions();
            this.loadAlerts();
            <?php if ($isAdmin): ?>
            this.loadUsage();
            <?php endif; ?>
        },

        // ════════════════════════════════════
        //  SESSIONS
        // ════════════════════════════════════

        get filteredSessions() {
            if (!this.sessionSearch.trim()) return this.sessions;
            const q = this.sessionSearch.toLowerCase();
            return this.sessions.filter(s => s.title.toLowerCase().includes(q));
        },

        async loadSessions() {
            this.sessionsLoading = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/ai/chat') ?>');
                // WHY: FF_Api returns raw JSON — no envelope. Check !r.error, not r.success
                if (!r.error) this.sessions = r.sessions || [];
            } catch(e) { console.error('Failed to load sessions:', e); }
            this.sessionsLoading = false;
        },

        async loadSession(id) {
            this.currentSessionId = id;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/ai/chat-session') ?>?id=' + id);
                // WHY: FF_Api returns raw JSON — fields are top-level, not under r.data
                if (!r.error) {
                    this.messages = (r.messages || []).filter(m => m.role !== 'system');
                    this.$nextTick(() => this.scrollToBottom());
                }
            } catch(e) { console.error('Failed to load session:', e); }
        },

        newSession() {
            this.currentSessionId = 0;
            this.messages = [];
            this.streamText = '';
            this.$nextTick(() => this.$refs.chatInput?.focus());
        },

        // ════════════════════════════════════
        //  CHAT (SSE Streaming)
        // ════════════════════════════════════

        sendQuickPrompt(text) {
            this.inputText = text;
            this.sendMessage();
        },

        async sendMessage() {
            const text = this.inputText.trim();
            if (!text || this.sending) return;

            this.inputText = '';
            this.messages.push({ role: 'user', content: text });
            this.sending = true;
            this.streamText = '';
            this.toolRunning = false;
            this.$nextTick(() => this.scrollToBottom());

            try {
                // WHY: Use SSE streaming for typewriter effect
                const body = JSON.stringify({
                    session_id: this.currentSessionId || undefined,
                    message: text,
                });

                const response = await fetch('<?= base_url('api/v1/ai/stream') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: body,
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    const err = await response.json().catch(() => ({}));
                    throw new Error(err.message || 'Request failed');
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line

                    for (const line of lines) {
                        if (!line.startsWith('data: ')) continue;
                        const json = line.slice(6);
                        try {
                            const event = JSON.parse(json);
                            if (event.type === 'token') {
                                this.streamText += event.text;
                                this.scrollToBottom();
                            } else if (event.type === 'tool_start') {
                                this.toolRunning = true;
                                this.toolName = event.name?.replace(/_/g, ' ') || 'data';
                            } else if (event.type === 'tool_end') {
                                this.toolRunning = false;
                            } else if (event.type === 'done') {
                                this.currentSessionId = event.session_id;
                                // Add assistant message
                                this.messages.push({
                                    role: 'assistant',
                                    content: this.streamText || 'No response generated.',
                                });
                                this.streamText = '';
                                this.loadSessions(); // refresh sidebar
                            } else if (event.type === 'error') {
                                this.messages.push({
                                    role: 'assistant',
                                    content: 'Error: ' + (event.message || 'Something went wrong.'),
                                });
                            }
                        } catch(e) { /* skip unparseable lines */ }
                    }
                }
            } catch(e) {
                console.error('Chat error:', e);
                // WHY: Fall back to non-streaming if SSE fails
                await this.sendMessageFallback(text);
            }

            this.sending = false;
            this.toolRunning = false;
            this.$nextTick(() => {
                this.scrollToBottom();
                this.$refs.chatInput?.focus();
            });
        },

        // WHY: Non-streaming fallback if SSE fails (e.g., proxy doesn't support it)
        async sendMessageFallback(text) {
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/chat') ?>', {
                    session_id: this.currentSessionId || undefined,
                    message: text,
                });
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    this.currentSessionId = r.session_id;
                    this.messages.push({ role: 'assistant', content: r.content });
                    this.loadSessions();
                } else {
                    this.messages.push({ role: 'assistant', content: 'Error: ' + (r.message || 'Something went wrong.') });
                }
            } catch(e) {
                this.messages.push({ role: 'assistant', content: 'Failed to reach the AI service. Please try again.' });
            }
        },

        // ════════════════════════════════════
        //  REPORTS
        // ════════════════════════════════════

        async generateReport() {
            if (!this.reportQuery.trim() || this.reportLoading) return;
            this.reportLoading = true;
            this.reportResult = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/report') ?>', {
                    query: this.reportQuery,
                });
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    this.reportResult = r.report;
                    this.reportTokens = r.tokens_used || 0;
                } else {
                    this.reportResult = 'Error: ' + (r.message || 'Failed to generate report.');
                }
            } catch(e) {
                this.reportResult = 'Network error. Please try again.';
            }
            this.reportLoading = false;
        },

        // ════════════════════════════════════
        //  DOCUMENT ANALYSIS
        // ════════════════════════════════════

        handleDocDrop(e) {
            const files = e.dataTransfer?.files;
            if (files?.length > 0) this.docFile = files[0];
        },

        async analyzeDocument() {
            if (!this.docFile || this.docLoading) return;
            this.docLoading = true;
            this.docResult = '';

            try {
                const formData = new FormData();
                formData.append('file', this.docFile);
                if (this.docPrompt.trim()) formData.append('prompt', this.docPrompt);

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const response = await fetch('<?= base_url('api/v1/ai/analyze-document') ?>', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData,
                    credentials: 'same-origin',
                });

                const data = await response.json();
                if (response.ok) {
                    this.docResult = data.analysis;
                    this.docFileName = data.file_name || this.docFile.name;
                    this.docTokens = data.tokens_used || 0;
                } else {
                    this.docResult = 'Error: ' + (data.message || 'Analysis failed.');
                }
            } catch(e) {
                this.docResult = 'Network error. Please try again.';
            }
            this.docLoading = false;
        },

        // ════════════════════════════════════
        //  ALERTS
        // ════════════════════════════════════

        async loadAlerts() {
            this.alertsLoading = true;
            try {
                const url = '<?= base_url('api/v1/ai/anomalies') ?>?limit=50'
                          + (this.alertsUnreadOnly ? '&unread_only=1' : '');
                const r = await FF_Api.get(url);
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) this.alerts = r.alerts || [];
            } catch(e) { console.error('Failed to load alerts:', e); }
            this.alertsLoading = false;
        },

        async acknowledgeAlert(id) {
            try {
                await FF_Api.post('<?= base_url('api/v1/ai/anomalies') ?>', {
                    action: 'acknowledge',
                    alert_id: id,
                });
                // Mark locally
                const alert = this.alerts.find(a => a.id === id);
                if (alert) alert.acknowledged_at = new Date().toISOString();
            } catch(e) { console.error('Failed to acknowledge alert:', e); }
        },

        async runAnomalyScan() {
            this.scanRunning = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/anomalies') ?>', { action: 'scan' });
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    FF_Toast?.success?.('Scan complete: ' + (r.alerts_created || 0) + ' new alert(s)');
                    this.loadAlerts();
                }
            } catch(e) { console.error('Scan failed:', e); }
            this.scanRunning = false;
        },

        // ════════════════════════════════════
        //  USAGE
        // ════════════════════════════════════

        async loadUsage() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/ai/usage') ?>');
                // WHY: FF_Api returns raw JSON — no envelope. Assign r directly
                if (!r.error) this.usage = r;
            } catch(e) { console.error('Failed to load usage:', e); }
        },

        // ════════════════════════════════════
        //  HELPERS
        // ════════════════════════════════════

        scrollToBottom() {
            const el = this.$refs.messagesContainer;
            if (el) el.scrollTop = el.scrollHeight;
        },

        autoResize(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        },

        formatDate(d) {
            if (!d) return '';
            const dt = new Date(d);
            const now = new Date();
            const diff = now - dt;
            if (diff < 86400000) { // today
                return dt.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit' });
            }
            if (diff < 604800000) { // this week
                return dt.toLocaleDateString('en-CA', { weekday: 'short', hour: '2-digit', minute: '2-digit' });
            }
            return dt.toLocaleDateString('en-CA', { month: 'short', day: 'numeric' });
        },

        formatNumber(n) {
            return (n || 0).toLocaleString();
        },

        // WHY: Lightweight markdown-to-HTML converter for AI responses
        // Supports: headers, bold, italic, code, lists, tables, blockquotes, links
        renderMarkdown(text) {
            if (!text) return '';
            let html = text
                // Escape HTML
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                // Code blocks (triple backtick)
                .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>')
                // Inline code
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                // Headers
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/^# (.+)$/gm, '<h1>$1</h1>')
                // Bold
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                // Italic
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                // Blockquotes
                .replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>')
                // Horizontal rule
                .replace(/^---$/gm, '<hr>')
                // Links
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
                // Unordered lists
                .replace(/^[\-\*] (.+)$/gm, '<li>$1</li>')
                // Ordered lists
                .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
                // Tables
                .replace(/^\|(.+)\|$/gm, function(match, content) {
                    const cells = content.split('|').map(c => c.trim());
                    if (cells.every(c => /^[-:]+$/.test(c))) return ''; // separator row
                    const tag = 'td';
                    return '<tr>' + cells.map(c => '<' + tag + '>' + c + '</' + tag + '>').join('') + '</tr>';
                });

            // Wrap consecutive <li> in <ul>
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
            // Wrap consecutive <tr> in <table>
            html = html.replace(/(<tr>.*<\/tr>\n?)+/g, '<table>$&</table>');
            // Paragraphs (double newline)
            html = html.replace(/\n\n/g, '</p><p>');
            // Single newlines to <br> (except inside pre/table)
            html = html.replace(/\n/g, '<br>');

            return html;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
