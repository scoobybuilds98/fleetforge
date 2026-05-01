<?php
declare(strict_types=1);

/**
 * app/portal/chat/index.php
 *
 * CHAT-2 — Portal-side customer chat page.
 * Single-thread view: customer sees their conversation with staff.
 * Staff messages show company display name on the LEFT.
 * Customer (portal) messages appear on the RIGHT (iMessage style).
 * Attachments shown as cards with View / Download links.
 * Polls every 10 seconds for new messages.
 *
 * Dependencies: includes/auth.php, includes/header.php, includes/footer.php,
 *               app/portal/api/chat/* endpoints,
 *               FF_PortalChat() in public/assets/js/app.js
 *
 * Spec: CHAT-2
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$pageTitle = 'Chat';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Portal Chat page ─────────────────────────────────────────────────── */
.pchat-wrap {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
    min-height: 400px;
    background: var(--bg-surface);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    overflow: hidden;
}

/* ── Header ──────────────────────────────────────────────────────────── */
.pchat-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.pchat-header-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--color-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.pchat-header-icon svg { width: 20px; height: 20px; color: #fff; }
.pchat-header-info {}
.pchat-header-name { font-weight: 600; font-size: 15px; color: var(--text-primary); }
.pchat-header-sub  { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

/* ── Messages area ──────────────────────────────────────────────────── */
.pchat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px 16px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.pchat-messages::-webkit-scrollbar { width: 4px; }
.pchat-messages::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

.pchat-load-more {
    text-align: center;
    padding: 8px 0 16px;
}

/* Date separator */
.pchat-date-sep {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 12px 0;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.pchat-date-sep::before,
.pchat-date-sep::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ── Message bubbles ─────────────────────────────────────────────────── */
.pchat-msg { display: flex; gap: 8px; margin-bottom: 4px; }

/* Staff messages: icon on left, bubble left-aligned */
.pchat-msg--staff { flex-direction: row; align-items: flex-end; }
.pchat-msg--portal { flex-direction: row-reverse; align-items: flex-end; }

.pchat-msg-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.pchat-msg--portal .pchat-msg-avatar { display: none; }

.pchat-msg-body { max-width: 70%; display: flex; flex-direction: column; gap: 2px; }
.pchat-msg--portal .pchat-msg-body { align-items: flex-end; }

.pchat-msg-author {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 2px;
    padding-left: 4px;
}
.pchat-msg--portal .pchat-msg-author { display: none; }

.pchat-msg-bubble {
    padding: 10px 14px;
    border-radius: 18px;
    font-size: 14px;
    line-height: 1.5;
    word-break: break-word;
}

/* Staff bubble: surface color */
.pchat-msg--staff .pchat-msg-bubble {
    background: var(--bg-elevated);
    color: var(--text-primary);
    border-bottom-left-radius: 4px;
    border: 1px solid var(--border);
}

/* Portal/customer bubble: accent color (iMessage style) */
.pchat-msg--portal .pchat-msg-bubble {
    background: var(--color-accent);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.pchat-msg-time {
    font-size: 10px;
    color: var(--text-muted);
    padding: 0 4px;
    margin-top: 2px;
}
.pchat-msg--portal .pchat-msg-time { text-align: right; }

/* System messages */
.pchat-msg--system {
    justify-content: center;
    margin: 8px 0;
}
.pchat-msg--system .pchat-msg-bubble {
    background: none;
    border: none;
    font-size: 12px;
    color: var(--text-muted);
    font-style: italic;
    text-align: center;
    padding: 2px 8px;
}

/* ── Attachment cards ────────────────────────────────────────────────── */
.pchat-att {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    margin-top: 4px;
    font-size: 13px;
    text-decoration: none;
}
.pchat-msg--staff .pchat-att {
    background: var(--bg-page);
    border: 1px solid var(--border);
    color: var(--text-primary);
}
.pchat-msg--portal .pchat-att {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    color: #fff;
}
.pchat-att-icon { font-size: 20px; flex-shrink: 0; }
.pchat-att-info { flex: 1; min-width: 0; }
.pchat-att-title  { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pchat-att-sub    { font-size: 11px; opacity: .7; margin-top: 1px; }
.pchat-att-badge  { font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: 600; flex-shrink: 0; }
.pchat-att-link   { font-size: 12px; font-weight: 600; text-decoration: none; opacity: .85; flex-shrink: 0; }
.pchat-att-link:hover { opacity: 1; }

/* ── Empty / loading states ──────────────────────────────────────────── */
.pchat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-muted);
    text-align: center;
    padding: 40px 20px;
}
.pchat-empty svg { width: 48px; height: 48px; opacity: .4; }
.pchat-empty-title { font-weight: 600; font-size: 15px; color: var(--text-secondary); }
.pchat-empty-sub   { font-size: 13px; }

/* ── Compose area ────────────────────────────────────────────────────── */
.pchat-compose {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    flex-shrink: 0;
}
.pchat-compose-input {
    flex: 1;
    resize: none;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 9px 16px;
    font-size: 14px;
    font-family: inherit;
    background: var(--bg-page);
    color: var(--text-primary);
    outline: none;
    max-height: 120px;
    overflow-y: auto;
    line-height: 1.5;
}
.pchat-compose-input:focus { border-color: var(--color-accent); }
.pchat-compose-input::placeholder { color: var(--text-muted); }
.pchat-send-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--color-accent);
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity .15s;
}
.pchat-send-btn:disabled { opacity: .4; cursor: not-allowed; }
.pchat-send-btn svg { width: 18px; height: 18px; color: #fff; }
</style>

<div class="pchat-wrap"
     x-data="FF_PortalChat()"
     x-init="init()">

    <!-- ── Loading ────────────────────────────────────────────────── -->
    <div class="pchat-empty" x-show="loading" x-cloak>
        <div class="skeleton-bar" style="width:200px;height:16px"></div>
        <div class="skeleton-bar" style="width:150px;height:12px;margin-top:4px"></div>
    </div>

    <!-- ── No conversation yet ───────────────────────────────────── -->
    <div class="pchat-empty" x-show="!loading && !channel" x-cloak>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
        </svg>
        <p class="pchat-empty-title">No conversations yet</p>
        <p class="pchat-empty-sub">When our team starts a conversation with you, it will appear here.</p>
    </div>

    <!-- ── Active conversation ────────────────────────────────────── -->
    <template x-if="!loading && channel">
        <div style="display:flex;flex-direction:column;height:100%;overflow:hidden">

            <!-- Header -->
            <div class="pchat-header">
                <div class="pchat-header-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                    </svg>
                </div>
                <div class="pchat-header-info">
                    <div class="pchat-header-name" x-text="channel.name || 'Support Chat'"></div>
                    <div class="pchat-header-sub">Our team will reply as soon as possible</div>
                </div>
            </div>

            <!-- Messages -->
            <div class="pchat-messages" x-ref="msgArea">

                <!-- Load older -->
                <div class="pchat-load-more" x-show="hasMore && !loadingMessages">
                    <button type="button" class="btn btn-secondary btn-sm"
                            @click="loadMore()" :disabled="loadingMore">
                        <span x-show="!loadingMore">Load older messages</span>
                        <span x-show="loadingMore" x-cloak>Loading…</span>
                    </button>
                </div>

                <!-- Loading messages skeleton -->
                <template x-if="loadingMessages">
                    <div>
                        <div class="skeleton-bar" style="width:55%;height:40px;margin:8px 0"></div>
                        <div class="skeleton-bar" style="width:40%;height:40px;margin:8px 0;margin-left:auto"></div>
                        <div class="skeleton-bar" style="width:60%;height:40px;margin:8px 0"></div>
                    </div>
                </template>

                <!-- Messages -->
                <template x-if="!loadingMessages">
                    <div>
                        <template x-for="item in messagesWithSeparators" :key="item.key">
                            <div>
                                <!-- Date separator -->
                                <template x-if="item.type === 'separator'">
                                    <div class="pchat-date-sep" x-text="item.label"></div>
                                </template>

                                <!-- System message -->
                                <template x-if="item.type === 'message' && item.msg.type === 'system'">
                                    <div class="pchat-msg pchat-msg--system">
                                        <div class="pchat-msg-body">
                                            <div class="pchat-msg-bubble" x-text="item.msg.message"></div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Regular message -->
                                <template x-if="item.type === 'message' && item.msg.type !== 'system'">
                                    <div class="pchat-msg"
                                         :class="isPortalMessage(item.msg) ? 'pchat-msg--portal' : 'pchat-msg--staff'">

                                        <!-- Staff avatar -->
                                        <div class="pchat-msg-avatar"
                                             x-show="!isPortalMessage(item.msg)"
                                             x-text="staffInitials(item.msg)"></div>

                                        <div class="pchat-msg-body">
                                            <!-- Staff sender name -->
                                            <div class="pchat-msg-author"
                                                 x-show="!isPortalMessage(item.msg)"
                                                 x-text="item.msg.author_name || item.msg.sender_display_name"></div>

                                            <!-- Bubble -->
                                            <div class="pchat-msg-bubble" x-text="item.msg.message"></div>

                                            <!-- Attachments -->
                                            <template x-for="att in (item.msg.attachments || [])" :key="att.id">
                                                <a :href="attachmentHref(att)"
                                                   :target="att.preview_url ? '_blank' : '_self'"
                                                   class="pchat-att">
                                                    <div class="pchat-att-icon" x-text="attachmentIcon(att.attachment_type)"></div>
                                                    <div class="pchat-att-info">
                                                        <div class="pchat-att-title" x-text="att.preview_title || att.file_name"></div>
                                                        <div class="pchat-att-sub" x-text="att.preview_subtitle"></div>
                                                    </div>
                                                    <span class="pchat-att-badge"
                                                          x-show="att.preview_badge"
                                                          x-text="att.preview_badge"></span>
                                                    <span class="pchat-att-link"
                                                          x-text="att.attachment_type === 'file' || att.attachment_type === 'image' ? 'Download' : 'View'">
                                                    </span>
                                                </a>
                                            </template>

                                            <div class="pchat-msg-time" x-text="formatTime(item.msg.created_at)"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <!-- Empty thread -->
                        <div class="pchat-empty"
                             x-show="messages.length === 0"
                             style="padding:40px 20px"
                             x-cloak>
                            <p class="pchat-empty-sub">No messages yet. Say hello below!</p>
                        </div>
                    </div>
                </template>

            </div><!-- /pchat-messages -->

            <!-- Compose -->
            <div class="pchat-compose">
                <textarea
                    class="pchat-compose-input"
                    placeholder="Write a message…"
                    x-model="composing"
                    x-ref="composeInput"
                    @keydown="handleKeydown($event)"
                    rows="1"
                    @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,120)+'px'"></textarea>
                <button type="button"
                        class="pchat-send-btn"
                        @click="send()"
                        :disabled="sending || !composing.trim()"
                        aria-label="Send message">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/>
                    </svg>
                </button>
            </div>

        </div>
    </template>

</div><!-- /pchat-wrap -->

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
