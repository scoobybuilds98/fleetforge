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

<!-- ── Page Header ──────────────────────────────────────────────────────────
     WHY: Hidden on chat tab to maximize vertical space (Claude/ChatGPT-style
     full-viewport chat layout). Still shown on Reports / Documents / Alerts /
     Usage tabs which benefit from a title and admin actions row.
     The x-show/x-cloak attrs are wired in once Alpine boots — see ai-page-header. -->
<div id="ai-page-header" class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
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
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border-color);padding-bottom:0;">
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

    <!-- ═══ CHAT TAB ═══
         Layout: Claude/ChatGPT-style two-pane fixed-viewport chat.
         WHY: The previous layout used `min-height:500px` + missing
         `min-height:0` on flex children, which let messages overflow the
         container and push the composer below the page fold. The fix is:
           1. Use 100dvh (dynamic viewport height) so the chat area is
              ALWAYS bounded by the actual visible viewport.
           2. Subtract topbar (60) + tab nav (~50) + page-content top
              padding (28) + small bottom breathing room (12) = 150.
           3. Put `min-height:0` on every flex child in the column chain so
              `overflow-y:auto` actually clips and scrolls instead of growing.
    -->
    <div x-show="activeTab === 'chat'" x-cloak class="ai-chat-layout">

        <!-- Session Sidebar -->
        <aside class="ai-chat-sidebar">
            <div class="ai-chat-sidebar-head">
                <button @click="newSession()" class="btn btn-primary" style="width:100%;font-size:0.8125rem;display:flex;align-items:center;justify-content:center;gap:6px;height:38px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    New Chat
                </button>
            </div>
            <div style="padding:0 12px 8px;">
                <input type="text" x-model="sessionSearch" placeholder="Search chats..."
                       style="width:100%;padding:7px 11px;font-size:0.8125rem;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-surface-2);color:var(--text-primary);">
            </div>
            <div style="padding:0 12px;font-size:0.6875rem;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:6px;">
                Recent
            </div>
            <div class="ai-chat-sidebar-list">
                <template x-for="s in filteredSessions" :key="s.id">
                    <div @click="loadSession(s.id)"
                         class="ai-session-row"
                         :style="currentSessionId === s.id
                             ? 'background:var(--color-primary-subtle);border-color:var(--color-primary);'
                             : 'border-color:transparent;'"
                         style="padding:8px 10px;border-radius:6px;cursor:pointer;margin-bottom:2px;border:1px solid transparent;transition:all 0.15s;display:flex;align-items:center;gap:8px;">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.8125rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                 x-text="s.title"></div>
                            <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:2px;"
                                 x-text="formatDate(s.last_message_at || s.created_at)"></div>
                        </div>
                        <!-- WHY: @click.stop prevents triggering loadSession on the parent row -->
                        <button @click.stop="deleteSession(s.id)"
                                class="ai-session-delete"
                                title="Delete chat"
                                aria-label="Delete chat"
                                style="flex-shrink:0;background:transparent;border:0;padding:4px;border-radius:4px;cursor:pointer;color:var(--text-muted);display:flex;align-items:center;justify-content:center;opacity:0.55;transition:all 0.15s;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                <path d="M10 11v6"></path>
                                <path d="M14 11v6"></path>
                                <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </div>
                </template>
                <div x-show="sessions.length === 0 && !sessionsLoading" style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.8125rem;">
                    No conversations yet
                </div>
            </div>
        </aside>

        <!-- Chat Main Area -->
        <section class="ai-chat-main">

            <!-- Messages — scrollable, takes all available height above the composer -->
            <div x-ref="messagesContainer" class="ai-chat-messages">
                <!-- Welcome state -->
                <div x-show="messages.length === 0 && !sending" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;padding:20px;">
                    <div style="font-size:2.5rem;margin-bottom:12px;">&#10024;</div>
                    <h3 style="font-size:1.25rem;font-weight:600;margin:0 0 8px;">How can I help?</h3>
                    <p style="color:var(--text-muted);font-size:0.875rem;max-width:440px;margin:0 0 22px;">
                        Ask me anything about your fleet, customers, leases, invoices, or compliance data.
                    </p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;max-width:560px;">
                        <button @click="sendQuickPrompt('What are the current fleet utilization stats?')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Fleet utilization</button>
                        <button @click="sendQuickPrompt('Show me all overdue invoices')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Overdue invoices</button>
                        <button @click="sendQuickPrompt('What compliance documents expire this month?')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Expiring compliance</button>
                        <button @click="sendQuickPrompt('Give me the dashboard KPIs')" class="btn btn-secondary btn-sm" style="font-size:0.8125rem;">Dashboard KPIs</button>
                    </div>
                </div>

                <!-- Messages list (centered column for readability, like Claude) -->
                <div x-show="messages.length > 0 || sending" style="max-width:820px;margin:0 auto;">
                    <template x-for="(msg, idx) in messages" :key="idx">
                        <div style="margin-bottom:20px;"
                             :style="msg.role === 'user' ? 'display:flex;justify-content:flex-end;' : ''">
                            <div :style="msg.role === 'user'
                                ? 'background:var(--color-primary);color:white;border-radius:14px 14px 4px 14px;padding:12px 16px;max-width:78%;box-shadow:0 1px 3px rgba(0,0,0,0.08);'
                                : 'background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:4px 14px 14px 14px;padding:18px 22px;max-width:100%;box-shadow:0 1px 3px rgba(0,0,0,0.06);'">
                                <!-- User message -->
                                <div x-show="msg.role === 'user'" x-text="msg.content" style="font-size:0.9375rem;line-height:1.55;white-space:pre-wrap;"></div>
                                <!-- Assistant message (rendered as markdown) -->
                                <div x-show="msg.role === 'assistant'" x-html="renderMarkdown(msg.content)" class="ai-response"></div>
                            </div>
                        </div>
                    </template>

                    <!-- Streaming indicator -->
                    <div x-show="sending" x-cloak style="margin-bottom:20px;">
                        <div style="background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:4px 14px 14px 14px;padding:18px 22px;max-width:100%;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                            <div x-show="toolRunning" style="font-size:0.8125rem;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:8px;">
                                <span class="spinner-sm"></span>
                                <span>Looking up <span x-text="toolName" class="font-mono"></span>...</span>
                            </div>
                            <div x-show="streamText" x-html="renderMarkdown(streamText)" class="ai-response"></div>
                            <div x-show="!streamText && !toolRunning" style="display:flex;gap:5px;padding:4px 0;">
                                <span class="typing-dot"></span>
                                <span class="typing-dot" style="animation-delay:0.2s;"></span>
                                <span class="typing-dot" style="animation-delay:0.4s;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Composer — anchored at the bottom of the bounded chat area, never below page fold -->
            <div class="ai-chat-composer">
                <form @submit.prevent="sendMessage()" class="ai-chat-composer-form">
                    <textarea x-model="inputText" x-ref="chatInput"
                              @keydown.enter.prevent="if(!$event.shiftKey) sendMessage()"
                              placeholder="Ask about your fleet, customers, leases..."
                              :disabled="sending"
                              rows="1"
                              @input="autoResize($event.target)"></textarea>
                    <button type="submit" class="btn btn-primary" :disabled="sending || !inputText.trim()"
                            style="height:42px;padding:0 20px;border-radius:8px;white-space:nowrap;align-self:flex-end;">
                        <span x-show="!sending">Send</span>
                        <span x-show="sending" x-cloak>&hellip;</span>
                    </button>
                </form>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;max-width:820px;margin-left:auto;margin-right:auto;">
                    <span style="font-size:0.7rem;color:var(--text-muted);">
                        Shift+Enter for new line &middot; AI responses may be inaccurate
                    </span>
                    <span x-show="currentSessionId > 0" x-cloak style="font-size:0.7rem;color:var(--text-muted);">
                        Session #<span x-text="currentSessionId" class="font-mono"></span>
                    </span>
                </div>
            </div>
        </section>
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
                    <div style="border:2px dashed var(--border-color);border-radius:8px;padding:30px;text-align:center;margin-bottom:12px;cursor:pointer;transition:border-color 0.2s;"
                         @dragover.prevent="$event.currentTarget.style.borderColor='var(--color-primary)'"
                         @dragleave.prevent="$event.currentTarget.style.borderColor='var(--border-color)'"
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
                               style="flex:1;padding:10px 14px;border:1px solid var(--border-color);border-radius:8px;font-size:0.875rem;background:var(--bg-surface-2);color:var(--text-primary);">
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
                    <div style="padding:14px 20px;border-bottom:1px solid var(--border-color);display:flex;gap:12px;align-items:start;">
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
                    <div style="margin-top:8px;height:4px;background:var(--border-color);border-radius:2px;overflow:hidden;">
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
                        <tr style="border-bottom:1px solid var(--border-color);background:var(--bg-surface-2);">
                            <th style="padding:10px 16px;text-align:left;font-weight:600;">User</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Requests</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Tokens</th>
                            <th style="padding:10px 16px;text-align:right;font-weight:600;">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="u in usage.by_user" :key="u.user_id">
                            <tr style="border-bottom:1px solid var(--border-color);">
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
    border: 2px solid var(--border-color);
    border-top-color: var(--color-primary);
    animation: spin 0.8s linear infinite;
    display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Chat layout (Claude/ChatGPT-style two-pane bounded viewport) ──────────
   WHY: Bound the entire chat experience to the actual visible viewport so
   the composer is ALWAYS in view. Use 100dvh (dynamic viewport height) so
   mobile address-bar collapse doesn't break the layout. min-height:0 on
   every flex column child is mandatory — without it, content can grow past
   the container and `overflow-y:auto` silently does nothing. */
.ai-chat-layout {
    display: flex;
    gap: 16px;
    /* topbar 60 + tab nav ~46 + page-content top padding 28 + breathing 16 = 150 */
    height: calc(100dvh - 150px);
    min-height: 420px;
    /* WHY: page-content has bottom padding from footer; pull layout up
       slightly so the composer hugs the actual viewport bottom */
    margin-bottom: -8px;
}
.ai-chat-sidebar {
    width: 280px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;            /* allow children to shrink for scroll */
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: var(--bg-surface);
    overflow: hidden;
}
.ai-chat-sidebar-head {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}
.ai-chat-sidebar-list {
    flex: 1;
    min-height: 0;            /* CRITICAL: enables scroll inside flex column */
    overflow-y: auto;
    padding: 0 8px 8px;
}
.ai-chat-main {
    flex: 1;
    min-width: 0;             /* prevent flex blowout from long content */
    min-height: 0;            /* CRITICAL: enables scroll inside flex column */
    display: flex;
    flex-direction: column;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: var(--bg-surface);
    overflow: hidden;
}
.ai-chat-messages {
    flex: 1;
    min-height: 0;            /* CRITICAL */
    overflow-y: auto;
    overflow-x: hidden;
    padding: 22px 24px;
    scroll-behavior: smooth;
}
.ai-chat-composer {
    flex-shrink: 0;
    border-top: 1px solid var(--border-color);
    padding: 14px 18px 12px;
    background: var(--bg-surface);
}
.ai-chat-composer-form {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    max-width: 820px;
    margin: 0 auto;
}
.ai-chat-composer-form textarea {
    flex: 1;
    resize: none;
    padding: 11px 14px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.9375rem;
    background: var(--bg-surface-2);
    color: var(--text-primary);
    font-family: inherit;
    line-height: 1.5;
    min-height: 44px;
    max-height: 160px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.ai-chat-composer-form textarea:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-subtle, rgba(99, 102, 241, 0.15));
}
/* Compact stacking on narrow viewports */
@media (max-width: 860px) {
    .ai-chat-layout { gap: 12px; height: calc(100dvh - 140px); }
    .ai-chat-sidebar { width: 220px; }
    .ai-chat-messages { padding: 16px; }
}
@media (max-width: 640px) {
    .ai-chat-sidebar { display: none; }
    .ai-chat-layout { gap: 0; }
}

/* ── Session row delete button (always visible, brighter on hover) ───────── */
.ai-session-row:hover .ai-session-delete,
.ai-session-row:focus-within .ai-session-delete { opacity: 1 !important; }
.ai-session-delete:hover {
    background: var(--color-danger-subtle, rgba(220, 38, 38, 0.12)) !important;
    color: var(--color-danger, #dc2626) !important;
    opacity: 1 !important;
}

/* ── AI response markdown rendering ─────────────────────────────────────── */
.ai-response { color: var(--text-primary); font-size: 0.9375rem; line-height: 1.7; }
.ai-response > *:first-child { margin-top: 0 !important; }
.ai-response > *:last-child  { margin-bottom: 0 !important; }

.ai-response h1 { font-size: 1.25rem;   font-weight: 700; margin: 22px 0 10px; line-height: 1.35; color: var(--text-primary); }
.ai-response h2 { font-size: 1.125rem;  font-weight: 700; margin: 20px 0 10px; line-height: 1.35; color: var(--text-primary); }
.ai-response h3 { font-size: 1rem;      font-weight: 600; margin: 18px 0 8px;  line-height: 1.4;  color: var(--text-primary); }
.ai-response h4 { font-size: 0.9375rem; font-weight: 600; margin: 16px 0 6px;  color: var(--text-primary); }

.ai-response p { margin: 10px 0; }

.ai-response ul, .ai-response ol { margin: 12px 0; padding-left: 24px; }
.ai-response li { margin: 6px 0; line-height: 1.65; }
.ai-response li > p { margin: 4px 0; }
.ai-response ul ul, .ai-response ol ol, .ai-response ul ol, .ai-response ol ul { margin: 4px 0; }

.ai-response strong { font-weight: 600; color: var(--text-primary); }
.ai-response em { font-style: italic; }

.ai-response code {
    background: var(--bg-surface-hover);
    color: var(--color-primary-text, var(--color-primary));
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.85em;
    font-family: 'DM Mono', ui-monospace, monospace;
    border: 1px solid var(--border-color);
}
.ai-response pre {
    background: var(--bg-surface-hover);
    border: 1px solid var(--border-color);
    padding: 14px 16px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 14px 0;
    line-height: 1.5;
}
.ai-response pre code { background: none; border: none; padding: 0; color: var(--text-primary); font-size: 0.8125rem; }

.ai-response table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    margin: 16px 0;
    font-size: 0.875rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
}
.ai-response table th,
.ai-response table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    vertical-align: top;
}
.ai-response table tr:last-child td { border-bottom: none; }
.ai-response table th {
    background: var(--bg-surface-hover);
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.8125rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.ai-response table tbody tr:nth-child(even) td { background: rgba(255,255,255,0.015); }
.ai-response table tbody tr:hover td { background: var(--bg-surface-hover); }

.ai-response blockquote {
    border-left: 3px solid var(--color-primary);
    padding: 4px 0 4px 14px;
    margin: 14px 0;
    color: var(--text-secondary);
    font-style: italic;
}
.ai-response hr { border: none; border-top: 1px solid var(--border-color); margin: 20px 0; }

.ai-response a {
    color: var(--color-primary-text, var(--color-primary));
    text-decoration: underline;
    text-underline-offset: 2px;
}
.ai-response a:hover { color: var(--color-primary-hover); }
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
            // WHY: Hide the page header on chat tab to give Claude/ChatGPT-style
            // full-viewport space; show it on every other tab where the title +
            // settings link are useful. The header lives OUTSIDE x-data, so we
            // toggle it imperatively and re-toggle whenever activeTab changes.
            this.$watch('activeTab', tab => this._togglePageHeader(tab));
            this._togglePageHeader(this.activeTab);

            await this.loadSessions();
            this.loadAlerts();
            <?php if ($isAdmin): ?>
            this.loadUsage();
            <?php endif; ?>
        },

        _togglePageHeader(tab) {
            const el = document.getElementById('ai-page-header');
            if (el) el.style.display = (tab === 'chat') ? 'none' : '';
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

        // WHY: User-initiated chat deletion. Backend (api/v1/ai/chat-session.php DELETE)
        // verifies ownership via user_id filter and FK CASCADE removes ai_chat_messages.
        async deleteSession(id) {
            if (!confirm('Delete this chat? This cannot be undone.')) return;
            try {
                const r = await FF_Api.delete('<?= base_url('api/v1/ai/chat-session') ?>?id=' + id);
                if (r.error) {
                    alert(r.message || 'Failed to delete chat.');
                    return;
                }
                // Remove from local list without re-fetching
                this.sessions = this.sessions.filter(s => s.id !== id);
                // If we deleted the currently-open session, reset the chat pane
                if (this.currentSessionId === id) {
                    this.currentSessionId = 0;
                    this.messages = [];
                    this.streamText = '';
                }
            } catch (e) {
                console.error('Failed to delete session:', e);
                alert('Network error — please try again.');
            }
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

        // WHY: Markdown-to-HTML converter for AI responses.
        // Order matters: code blocks first (so their content is not parsed),
        // then tables (so pipes are not broken), then inline formatting.
        renderMarkdown(text) {
            if (!text) return '';

            // 1. Escape HTML
            let html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            // 2. Stash fenced code blocks so we don't touch their contents
            const codeBlocks = [];
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (m, lang, code) => {
                codeBlocks.push('<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
                return '\u0000CODE' + (codeBlocks.length - 1) + '\u0000';
            });

            // 3. Tables — detect contiguous pipe-rows including separator.
            //    Stash them so later regexes (paragraphs, lists) don't touch the markup.
            const tables = [];
            html = html.replace(
                /(^\|[^\n]+\|\n\|[\s\-:|]+\|\n(?:\|[^\n]+\|(?:\n|$))+)/gm,
                (block) => {
                    const lines = block.trim().split('\n');
                    const headerCells = lines[0].slice(1, -1).split('|').map(c => c.trim());
                    const bodyLines = lines.slice(2); // skip header + separator
                    const thead = '<thead><tr>' + headerCells.map(c => '<th>' + c + '</th>').join('') + '</tr></thead>';
                    const tbody = '<tbody>' + bodyLines.map(line => {
                        const cells = line.slice(1, -1).split('|').map(c => c.trim());
                        return '<tr>' + cells.map(c => '<td>' + c + '</td>').join('') + '</tr>';
                    }).join('') + '</tbody>';
                    tables.push('<table>' + thead + tbody + '</table>');
                    return '\u0000TABLE' + (tables.length - 1) + '\u0000';
                }
            );

            // 4. Headers
            html = html
                .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/^# (.+)$/gm, '<h1>$1</h1>');

            // 5. Horizontal rule
            html = html.replace(/^---+$/gm, '<hr>');

            // 6. Blockquotes
            html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');

            // 7. Lists — group contiguous list items into <ul>/<ol>
            //    Unordered
            html = html.replace(/(?:^[\-\*] .+(?:\n|$))+/gm, (block) => {
                const items = block.trim().split('\n').map(l => l.replace(/^[\-\*] /, ''));
                return '<ul>' + items.map(i => '<li>' + i + '</li>').join('') + '</ul>\n';
            });
            //    Ordered
            html = html.replace(/(?:^\d+\. .+(?:\n|$))+/gm, (block) => {
                const items = block.trim().split('\n').map(l => l.replace(/^\d+\. /, ''));
                return '<ol>' + items.map(i => '<li>' + i + '</li>').join('') + '</ol>\n';
            });

            // 8. Inline formatting (bold, italic, code, links)
            html = html
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>')
                .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            // 9. Paragraphs — split on blank lines, wrap chunks that aren't already block-level
            const blockTagRe = /^<(h[1-6]|ul|ol|table|pre|blockquote|hr|\u0000)/i;
            html = html.split(/\n{2,}/).map(chunk => {
                const trimmed = chunk.trim();
                if (!trimmed) return '';
                if (blockTagRe.test(trimmed)) return trimmed;
                // Inline newlines inside a paragraph become <br>
                return '<p>' + trimmed.replace(/\n/g, '<br>') + '</p>';
            }).join('\n');

            // 10. Restore stashed tables and code blocks
            html = html.replace(/\u0000TABLE(\d+)\u0000/g, (m, i) => tables[+i]);
            html = html.replace(/\u0000CODE(\d+)\u0000/g, (m, i) => codeBlocks[+i]);

            return html;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
