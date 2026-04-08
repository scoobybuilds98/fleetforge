<?php
declare(strict_types=1);

/**
 * app/portal/messages/index.php
 *
 * MSGR-1 — Portal-side messenger page.
 *
 * Customers see every conversation visible to them (every thread on their
 * customer account, plus any thread pinned directly to them as a portal
 * user). They can reply to existing threads but cannot start new ones —
 * conversation initiation is admin-only by design (admin → customer flow).
 *
 * Layout (2 columns, lighter than admin):
 *   LEFT  : conversation list
 *   RIGHT : active thread + reply box
 *
 * Polls /portal/api/messenger/threads.php every 10s for the list and
 * /portal/api/messenger/poll.php every 5s for new messages in the open
 * thread. Both gated by the portal session and customer scoping rules
 * inside MessengerService::portalCanAccessThread().
 *
 * @auth     portal session
 * @session  MSGR-1
 * @depends  app/portal/includes/{auth,header,footer}.php
 *           app/portal/api/messenger/* endpoints
 *           FF_PortalMessenger() in public/assets/js/app.js
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$pageTitle = 'Messages';
// Optional ?thread=N deep-link — admin notifications use this so a click
// from the bell drops the customer straight into the right conversation.
$thread_id_param = isset($_GET['thread']) ? (int) $_GET['thread'] : 0;

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="portal-msgr-page"
     x-data="FF_PortalMessenger()"
     x-init="init(<?= (int) $thread_id_param ?>)">

    <div class="portal-msgr-layout">

        <!-- ── LEFT — Thread list ─────────────────────────────────────── -->
        <aside class="portal-msgr-sidebar">
            <div class="portal-msgr-sidebar-header">
                <span class="portal-msgr-sidebar-title">Conversations</span>
            </div>

            <div class="portal-msgr-thread-list">
                <div class="portal-msgr-loading" x-show="loadingThreads" x-cloak>
                    <div class="skeleton-bar" style="width:90%;height:48px;margin:8px auto"></div>
                    <div class="skeleton-bar" style="width:85%;height:48px;margin:8px auto"></div>
                    <div class="skeleton-bar" style="width:80%;height:48px;margin:8px auto"></div>
                </div>

                <template x-for="t in threads" :key="t.id">
                    <button type="button"
                            class="portal-msgr-thread-item"
                            :class="{ 'is-active': activeThread && activeThread.id === t.id,
                                      'has-unread': t.unread_count > 0 }"
                            @click="selectThread(t)">
                        <div class="portal-msgr-thread-avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                        </div>
                        <div class="portal-msgr-thread-body">
                            <div class="portal-msgr-thread-top">
                                <span class="portal-msgr-thread-name" x-text="threadDisplayName(t)"></span>
                                <span class="portal-msgr-thread-time" x-text="formatTimeShort(t.last_message_at || t.created_at)"></span>
                            </div>
                            <div class="portal-msgr-thread-preview">
                                <span x-show="t.last_message_by === 'portal'" class="portal-msgr-thread-prefix">You: </span>
                                <span x-text="t.last_message_preview || 'No messages yet'"></span>
                            </div>
                        </div>
                        <span class="portal-msgr-unread-badge"
                              x-show="t.unread_count > 0"
                              x-text="t.unread_count > 99 ? '99+' : t.unread_count"></span>
                    </button>
                </template>

                <div class="portal-msgr-empty"
                     x-show="!loadingThreads && threads.length === 0"
                     x-cloak>
                    <p class="portal-msgr-empty-title">No conversations yet</p>
                    <p class="portal-msgr-empty-sub">When our team sends you a message, it will appear here.</p>
                </div>
            </div>
        </aside>

        <!-- ── RIGHT — Active thread ──────────────────────────────────── -->
        <section class="portal-msgr-main">

            <!-- Empty state when nothing selected -->
            <div class="portal-msgr-empty-state" x-show="!activeThread" x-cloak>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:56px;height:56px;color:var(--text-muted)"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                <p class="portal-msgr-empty-title">Select a conversation</p>
                <p class="portal-msgr-empty-sub">Pick a thread on the left to read and reply.</p>
            </div>

            <template x-if="activeThread">
                <div class="portal-msgr-thread-view">

                    <!-- Thread header -->
                    <header class="portal-msgr-thread-header">
                        <div class="portal-msgr-thread-header-info">
                            <div class="portal-msgr-thread-avatar portal-msgr-thread-avatar--lg">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                            </div>
                            <div>
                                <div class="portal-msgr-thread-header-name" x-text="threadDisplayName(activeThread)"></div>
                                <div class="portal-msgr-thread-header-sub">
                                    <span>Support team</span>
                                </div>
                            </div>
                        </div>
                    </header>

                    <!-- Messages list -->
                    <div class="portal-msgr-messages-area" id="portal-msgr-scroll">

                        <div class="portal-msgr-loading" x-show="loadingMessages" x-cloak>
                            <div class="skeleton-bar" style="width:60%;height:36px;margin:12px auto"></div>
                            <div class="skeleton-bar" style="width:50%;height:36px;margin:12px auto"></div>
                            <div class="skeleton-bar" style="width:65%;height:36px;margin:12px auto"></div>
                        </div>

                        <div class="portal-msgr-load-more" x-show="hasMore && !loadingMessages">
                            <button type="button" class="btn btn-secondary btn-sm" @click="loadMore()" :disabled="loadingMore">
                                <span x-show="!loadingMore">Load older messages</span>
                                <span x-show="loadingMore">Loading…</span>
                            </button>
                        </div>

                        <template x-for="msg in messages" :key="msg.id">
                            <div class="portal-msgr-message"
                                 :class="msg.sender_type === 'portal'
                                            ? 'portal-msgr-message--mine'
                                            : 'portal-msgr-message--theirs'">
                                <div class="portal-msgr-message-bubble">
                                    <div class="portal-msgr-message-author"
                                         x-show="msg.sender_type === 'admin'"
                                         x-text="msg.author_name"></div>
                                    <div class="portal-msgr-message-text"
                                         x-show="!msg.is_archived"
                                         x-text="msg.display_text"></div>
                                    <div class="portal-msgr-message-deleted"
                                         x-show="msg.is_archived">[message deleted]</div>
                                    <div class="portal-msgr-message-time" x-text="formatTime(msg.created_at)"></div>
                                </div>
                            </div>
                        </template>

                        <div class="portal-msgr-thread-empty"
                             x-show="!loadingMessages && messages.length === 0"
                             x-cloak>
                            <p>No messages in this conversation yet.</p>
                        </div>
                    </div>

                    <!-- Compose -->
                    <div class="portal-msgr-compose">
                        <textarea
                            class="portal-msgr-compose-input"
                            placeholder="Write a reply…"
                            x-model="composing"
                            x-ref="composeInput"
                            @keydown="handleComposeKeydown($event)"
                            rows="1"
                            @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight, 160)+'px'"></textarea>
                        <button type="button"
                                class="btn btn-primary portal-msgr-send-btn"
                                @click="send()"
                                :disabled="sending || !composing.trim()">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/></svg>
                            <span>Send</span>
                        </button>
                    </div>
                </div>
            </template>

        </section>

    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
