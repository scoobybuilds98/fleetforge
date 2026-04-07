<?php
declare(strict_types=1);

/**
 * includes/partials/ai-chat-widget.php
 *
 * Floating AI chat widget — professional Intercom-style panel that
 * appears on every admin page. Three states: closed (fab only),
 * minimized (header strip), open (full chat).
 *
 * Features:
 *   - Smooth slide-up animation
 *   - Minimize / expand / close controls
 *   - "Ask a question" prompt above the input (bottom, not top)
 *   - AI avatar + online indicator in header
 *   - Auto-scroll on new messages
 *   - Keyboard: Enter to send, Shift+Enter for newline
 *
 * Permission: ai:view
 *
 * @depends lib/AI/ClaudeClient.php, api/v1/ai/chat.php
 * @session S027
 */

// WHY: Only show if user has AI access and AI is configured
if (!function_exists('can') || !can('ai', 'view')) return;

// INT-1: settings table FIRST, .env SECOND.
$widgetAiEnabled = (bool) settings_get('ai.enabled', false);
$widgetHasApiKey = (bool) (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''));
if (!$widgetAiEnabled || !$widgetHasApiKey) return;

// WHY: Don't show on the AI page itself — it has a full chat interface
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
if (str_contains($currentPath, '/ai')) return;
?>

<!-- ── AI Chat Widget ─────────────────────────────────────────────────────── -->
<div x-data="FF_ChatWidget()" x-init="$watch('open', v => { if(v && !minimized) $nextTick(() => $refs.wInput?.focus()) })">

    <!-- ── FAB (floating action button) — visible when panel is closed ──── -->
    <button x-show="!open && !minimized" @click="openChat()"
            class="ff-chat-fab"
            title="AI Assistant">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            <path d="M8 10h.01M12 10h.01M16 10h.01" stroke-width="2.5"/>
        </svg>
        <!-- WHY: Pulse dot draws attention on first visit -->
        <span class="ff-chat-fab-pulse"></span>
    </button>

    <!-- ── Minimized strip — header only ───────────────────────────────── -->
    <div x-show="minimized" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="ff-chat-minimized"
         @click="expandChat()">
        <div class="ff-chat-minimized-inner">
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="ff-chat-avatar-sm">AI</div>
                <span style="font-weight:600;font-size:0.8125rem;color:#fff;">AI Assistant</span>
                <span class="ff-chat-status-dot"></span>
            </div>
            <div style="display:flex;gap:4px;">
                <button @click.stop="expandChat()" class="ff-chat-hdr-btn" title="Expand">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
                </button>
                <button @click.stop="closeChat()" class="ff-chat-hdr-btn" title="Close">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Full panel ──────────────────────────────────────────────────── -->
    <div x-show="open && !minimized" x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="ff-chat-panel">

        <!-- Header -->
        <div class="ff-chat-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="ff-chat-avatar">AI</div>
                <div>
                    <div style="font-weight:600;font-size:0.9375rem;color:#fff;line-height:1.2;">AI Assistant</div>
                    <div style="font-size:0.6875rem;color:rgba(255,255,255,0.7);display:flex;align-items:center;gap:4px;">
                        <span class="ff-chat-status-dot"></span>
                        Online
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:2px;">
                <a href="<?= base_url('ai') ?>" class="ff-chat-hdr-btn" title="Open full chat"
                   style="text-decoration:none;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <button @click="minimizeChat()" class="ff-chat-hdr-btn" title="Minimize">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
                <button @click="closeChat()" class="ff-chat-hdr-btn" title="Close">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div x-ref="wMessages" class="ff-chat-messages">
            <!-- Message list -->
            <template x-for="(msg, idx) in widgetMessages" :key="idx">
                <div :class="msg.role === 'user' ? 'ff-chat-row ff-chat-row-user' : 'ff-chat-row ff-chat-row-ai'">
                    <!-- AI avatar (only for assistant) -->
                    <div x-show="msg.role === 'assistant'" class="ff-chat-msg-avatar">AI</div>
                    <div :class="msg.role === 'user' ? 'ff-chat-bubble ff-chat-bubble-user' : 'ff-chat-bubble ff-chat-bubble-ai'">
                        <!-- WHY: User text is plain (preserves line breaks); AI text rendered as markdown HTML -->
                        <span x-show="msg.role === 'user'" x-text="msg.content" style="white-space:pre-wrap;word-break:break-word;"></span>
                        <div x-show="msg.role === 'assistant'" x-html="renderMd(msg.content)" class="ff-chat-md"></div>
                    </div>
                </div>
            </template>

            <!-- Typing indicator -->
            <div x-show="widgetSending" x-cloak class="ff-chat-row ff-chat-row-ai">
                <div class="ff-chat-msg-avatar">AI</div>
                <div class="ff-chat-bubble ff-chat-bubble-ai" style="padding:10px 16px;">
                    <div class="ff-chat-typing">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input area -->
        <div class="ff-chat-footer">
            <!-- WHY: Hint text sits above input — "ask a question" at bottom per user request -->
            <div x-show="widgetMessages.length === 0 && !widgetSending" class="ff-chat-hint">
                Ask anything about your fleet, customers, leases, or invoices.
            </div>
            <form @submit.prevent="widgetSend()" class="ff-chat-input-row">
                <input type="text" x-model="widgetInput" x-ref="wInput"
                       placeholder="Type a message..."
                       :disabled="widgetSending"
                       @keydown.enter.prevent="widgetSend()"
                       class="ff-chat-input">
                <button type="submit" class="ff-chat-send-btn"
                        :disabled="widgetSending || !widgetInput.trim()"
                        :class="widgetInput.trim() ? 'ff-chat-send-btn-active' : ''">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </form>
            <div class="ff-chat-powered">
                Powered by Claude AI
            </div>
        </div>
    </div>
</div>

<!-- ── Widget Styles ──────────────────────────────────────────────────────── -->
<style>
/* ── FAB ──────────────────────────────── */
.ff-chat-fab {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-hover) 100%);
    box-shadow: 0 4px 16px rgba(249, 115, 22, 0.35), 0 2px 4px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.ff-chat-fab:hover {
    transform: scale(1.08);
    box-shadow: 0 6px 24px rgba(249, 115, 22, 0.45), 0 4px 8px rgba(0,0,0,0.12);
}
.ff-chat-fab:active { transform: scale(0.95); }
.ff-chat-fab-pulse {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--color-success);
    border: 2.5px solid var(--bg-body);
    animation: ffPulse 2s infinite;
}
@keyframes ffPulse {
    0%   { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
    70%  { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
    100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* ── Minimized strip ─────────────────── */
.ff-chat-minimized {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    width: 300px;
    cursor: pointer;
}
.ff-chat-minimized-inner {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-hover) 100%);
    border-radius: 12px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.08);
    transition: box-shadow 0.2s;
}
.ff-chat-minimized:hover .ff-chat-minimized-inner {
    box-shadow: 0 6px 28px rgba(0,0,0,0.2), 0 4px 8px rgba(0,0,0,0.1);
}

/* ── Full panel ──────────────────────── */
.ff-chat-panel {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    width: 440px;
    height: 620px;
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    overflow: hidden;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    box-shadow: 0 12px 48px rgba(0,0,0,0.18), 0 4px 12px rgba(0,0,0,0.08);
}

/* ── Header ──────────────────────────── */
.ff-chat-header {
    padding: 14px 16px;
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-hover) 100%);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.ff-chat-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.75rem;
    color: #fff;
    letter-spacing: 0.02em;
    flex-shrink: 0;
}
.ff-chat-avatar-sm {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.625rem;
    color: #fff;
    flex-shrink: 0;
}
.ff-chat-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #4ade80;
    display: inline-block;
    flex-shrink: 0;
}
.ff-chat-hdr-btn {
    background: rgba(255,255,255,0.12);
    border: none;
    color: rgba(255,255,255,0.85);
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s;
}
.ff-chat-hdr-btn:hover {
    background: rgba(255,255,255,0.22);
    color: #fff;
}

/* ── Messages ────────────────────────── */
.ff-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    scrollbar-width: thin;
    scrollbar-color: var(--border-color-strong) transparent;
}
.ff-chat-messages::-webkit-scrollbar { width: 5px; }
.ff-chat-messages::-webkit-scrollbar-track { background: transparent; }
.ff-chat-messages::-webkit-scrollbar-thumb { background: var(--border-color-strong); border-radius: 4px; }

.ff-chat-row { display: flex; align-items: flex-end; gap: 8px; }
.ff-chat-row-user { justify-content: flex-end; }
.ff-chat-row-ai { justify-content: flex-start; }

.ff-chat-msg-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--color-primary);
    color: #fff;
    font-size: 0.5625rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.ff-chat-bubble {
    max-width: 88%;
    padding: 12px 16px;
    font-size: 0.8125rem;
    line-height: 1.6;
    word-break: break-word;
}
.ff-chat-bubble-user {
    background: var(--color-primary);
    color: #fff;
    border-radius: 14px 14px 4px 14px;
    max-width: 80%;
}
.ff-chat-bubble-ai {
    background: var(--bg-surface-2);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 4px 14px 14px 14px;
}

/* ── AI markdown rendering inside widget bubble ───────────────────────── */
.ff-chat-md > *:first-child { margin-top: 0 !important; }
.ff-chat-md > *:last-child  { margin-bottom: 0 !important; }
.ff-chat-md p { margin: 8px 0; }
.ff-chat-md h1, .ff-chat-md h2, .ff-chat-md h3, .ff-chat-md h4 {
    font-weight: 600;
    color: var(--text-primary);
    margin: 12px 0 6px;
    line-height: 1.35;
}
.ff-chat-md h1 { font-size: 0.9375rem; }
.ff-chat-md h2 { font-size: 0.9375rem; }
.ff-chat-md h3 { font-size: 0.875rem; }
.ff-chat-md h4 { font-size: 0.8125rem; }
.ff-chat-md ul, .ff-chat-md ol { margin: 8px 0; padding-left: 20px; }
.ff-chat-md li { margin: 4px 0; line-height: 1.55; }
.ff-chat-md strong { font-weight: 600; color: var(--text-primary); }
.ff-chat-md em { font-style: italic; }
.ff-chat-md code {
    background: var(--bg-surface-hover);
    padding: 1px 5px;
    border-radius: 4px;
    font-size: 0.78rem;
    font-family: 'DM Mono', ui-monospace, monospace;
    border: 1px solid var(--border-color);
}
.ff-chat-md pre {
    background: var(--bg-surface-hover);
    border: 1px solid var(--border-color);
    padding: 10px 12px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 10px 0;
    font-size: 0.75rem;
    line-height: 1.5;
}
.ff-chat-md pre code { background: none; border: none; padding: 0; font-size: 0.75rem; }
.ff-chat-md table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
    margin: 10px 0;
    font-size: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    overflow: hidden;
}
.ff-chat-md th, .ff-chat-md td {
    padding: 7px 9px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    vertical-align: top;
}
.ff-chat-md tr:last-child td { border-bottom: none; }
.ff-chat-md th {
    background: var(--bg-surface-hover);
    font-weight: 600;
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.ff-chat-md blockquote {
    border-left: 3px solid var(--color-primary);
    padding: 2px 0 2px 10px;
    margin: 8px 0;
    color: var(--text-secondary);
    font-style: italic;
}
.ff-chat-md hr { border: none; border-top: 1px solid var(--border-color); margin: 12px 0; }
.ff-chat-md a { color: var(--color-primary); text-decoration: underline; text-underline-offset: 2px; }

/* ── Typing indicator ────────────────── */
.ff-chat-typing {
    display: flex;
    gap: 4px;
    align-items: center;
    height: 18px;
}
.ff-chat-typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--text-secondary);
    animation: ffTyping 1.4s infinite ease-in-out;
}
.ff-chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.ff-chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes ffTyping {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40%           { transform: scale(1);   opacity: 1; }
}

/* ── Footer / Input ──────────────────── */
.ff-chat-footer {
    border-top: 1px solid var(--border-color);
    padding: 10px 14px 8px;
    background: var(--bg-surface);
    flex-shrink: 0;
}
.ff-chat-hint {
    font-size: 0.6875rem;
    color: var(--text-secondary);
    text-align: center;
    padding: 0 8px 8px;
    line-height: 1.4;
}
.ff-chat-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 4px 4px 4px 14px;
    transition: border-color 0.15s;
}
.ff-chat-input-row:focus-within {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px var(--color-primary-light);
}
.ff-chat-input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-size: 0.8125rem;
    color: var(--text-primary);
    padding: 6px 0;
    font-family: inherit;
}
.ff-chat-input::placeholder { color: var(--text-secondary); }
.ff-chat-send-btn {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: none;
    background: var(--bg-surface-hover);
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s;
    flex-shrink: 0;
}
.ff-chat-send-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.ff-chat-send-btn-active {
    background: var(--color-primary) !important;
    color: #fff !important;
}
.ff-chat-send-btn-active:hover { background: var(--color-primary-hover) !important; }
.ff-chat-powered {
    text-align: center;
    font-size: 0.625rem;
    color: var(--text-secondary);
    margin-top: 6px;
    opacity: 0.6;
    letter-spacing: 0.01em;
}

/* ── Tablet / smaller desktop ─────────── */
@media (max-width: 520px) {
    .ff-chat-panel {
        width: calc(100vw - 16px);
        height: calc(100vh - 80px);
        bottom: 8px;
        right: 8px;
        border-radius: 14px;
    }
    .ff-chat-minimized { width: calc(100vw - 48px); }
    .ff-chat-fab { bottom: 16px; right: 16px; }
}
</style>

<!-- ── Alpine Component ───────────────────────────────────────────────────── -->
<script>
function FF_ChatWidget() {
    return {
        open: false,
        minimized: false,
        widgetMessages: [],
        widgetInput: '',
        widgetSending: false,
        widgetSessionId: 0,

        // ── State transitions ──────────────
        openChat() {
            this.open = true;
            this.minimized = false;
        },
        minimizeChat() {
            this.minimized = true;
        },
        expandChat() {
            this.minimized = false;
            this.open = true;
            this.$nextTick(() => this.$refs.wInput?.focus());
        },
        closeChat() {
            this.open = false;
            this.minimized = false;
        },

        // WHY: Keep old toggle() for backward compat with any keyboard shortcuts
        toggle() {
            if (this.open && !this.minimized) {
                this.closeChat();
            } else {
                this.openChat();
            }
        },

        // ── Send message ───────────────────
        async widgetSend() {
            const text = this.widgetInput.trim();
            if (!text || this.widgetSending) return;

            this.widgetInput = '';
            this.widgetMessages.push({ role: 'user', content: text });
            this.widgetSending = true;
            this.$nextTick(() => this.scrollDown());

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/ai/chat') ?>', {
                    session_id: this.widgetSessionId || undefined,
                    message: text,
                });
                // WHY: FF_Api returns raw JSON — no envelope. Fields are top-level
                if (!r.error) {
                    this.widgetSessionId = r.session_id;
                    this.widgetMessages.push({ role: 'assistant', content: r.content });
                } else {
                    this.widgetMessages.push({ role: 'assistant', content: 'Error: ' + (r.message || 'Something went wrong.') });
                }
            } catch(e) {
                this.widgetMessages.push({ role: 'assistant', content: 'Failed to reach AI service.' });
            }

            this.widgetSending = false;
            this.$nextTick(() => {
                this.scrollDown();
                this.$refs.wInput?.focus();
            });
        },

        scrollDown() {
            const el = this.$refs.wMessages;
            if (el) el.scrollTop = el.scrollHeight;
        },

        // WHY: Same markdown-to-HTML logic as the /ai page so AI responses
        // render with bold/headers/lists/tables instead of raw `**text**` symbols.
        renderMd(text) {
            if (!text) return '';
            let html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            // Stash fenced code blocks
            const codeBlocks = [];
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, (m, lang, code) => {
                codeBlocks.push('<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
                return '\u0000CODE' + (codeBlocks.length - 1) + '\u0000';
            });

            // Stash markdown tables
            const tables = [];
            html = html.replace(
                /(^\|[^\n]+\|\n\|[\s\-:|]+\|\n(?:\|[^\n]+\|(?:\n|$))+)/gm,
                (block) => {
                    const lines = block.trim().split('\n');
                    const headerCells = lines[0].slice(1, -1).split('|').map(c => c.trim());
                    const bodyLines = lines.slice(2);
                    const thead = '<thead><tr>' + headerCells.map(c => '<th>' + c + '</th>').join('') + '</tr></thead>';
                    const tbody = '<tbody>' + bodyLines.map(line => {
                        const cells = line.slice(1, -1).split('|').map(c => c.trim());
                        return '<tr>' + cells.map(c => '<td>' + c + '</td>').join('') + '</tr>';
                    }).join('') + '</tbody>';
                    tables.push('<table>' + thead + tbody + '</table>');
                    return '\u0000TABLE' + (tables.length - 1) + '\u0000';
                }
            );

            // Headers
            html = html
                .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/^# (.+)$/gm, '<h1>$1</h1>');

            html = html.replace(/^---+$/gm, '<hr>');
            html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');

            // Lists
            html = html.replace(/(?:^[\-\*] .+(?:\n|$))+/gm, (block) => {
                const items = block.trim().split('\n').map(l => l.replace(/^[\-\*] /, ''));
                return '<ul>' + items.map(i => '<li>' + i + '</li>').join('') + '</ul>\n';
            });
            html = html.replace(/(?:^\d+\. .+(?:\n|$))+/gm, (block) => {
                const items = block.trim().split('\n').map(l => l.replace(/^\d+\. /, ''));
                return '<ol>' + items.map(i => '<li>' + i + '</li>').join('') + '</ol>\n';
            });

            // Inline formatting
            html = html
                .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>')
                .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            // Paragraphs
            const blockTagRe = /^<(h[1-6]|ul|ol|table|pre|blockquote|hr|\u0000)/i;
            html = html.split(/\n{2,}/).map(chunk => {
                const trimmed = chunk.trim();
                if (!trimmed) return '';
                if (blockTagRe.test(trimmed)) return trimmed;
                return '<p>' + trimmed.replace(/\n/g, '<br>') + '</p>';
            }).join('\n');

            // Restore stashes
            html = html.replace(/\u0000TABLE(\d+)\u0000/g, (m, i) => tables[+i]);
            html = html.replace(/\u0000CODE(\d+)\u0000/g, (m, i) => codeBlocks[+i]);

            return html;
        },
    };
}
</script>
