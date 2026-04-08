<?php
/**
 * app/admin/messenger/index.php
 *
 * MSGR-1 — Facebook-Messenger-style admin↔customer messaging page.
 *
 * Layout (3 columns):
 *   LEFT   : thread list (all open customer conversations)
 *   MAIN   : active thread header + message history + compose box
 *   RIGHT  : thread info panel (customer contact + participants)
 *
 * Access: any admin with customers.view permission. Messenger is a
 * shared team inbox — every eligible admin sees every thread. Polling
 * every 5s via FF_Messenger() Alpine component in public/assets/js/app.js.
 *
 * Dependencies: includes/auth.php, includes/header.php, includes/footer.php,
 *               api/v1/messenger/* endpoints, FF_Messenger() in app.js
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('customers', 'view');

$pageTitle = 'Messenger';
$thread_id_param = clean_int($_GET['thread'] ?? null) ?? 0;

require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-content chat-page-content"
     x-data="FF_Messenger()"
     x-init="init(<?= (int) $thread_id_param ?>)">

    <div class="chat-layout">

        <!-- ── LEFT SIDEBAR — Thread list ────────────────────────────── -->
        <div class="chat-sidebar">

            <div class="chat-sidebar-header">
                <span class="chat-sidebar-title">Messenger</span>
                <button type="button"
                        class="btn-icon"
                        @click="openNewThread()"
                        title="New conversation">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                </button>
            </div>

            <div class="chat-sidebar-section">
                <div class="chat-sidebar-label">CUSTOMER CONVERSATIONS</div>

                <div class="chat-loading" x-show="loadingThreads" x-cloak>
                    <div class="skeleton-bar" style="width:90%;height:32px;margin:6px"></div>
                    <div class="skeleton-bar" style="width:85%;height:32px;margin:6px"></div>
                    <div class="skeleton-bar" style="width:80%;height:32px;margin:6px"></div>
                </div>

                <template x-for="t in threads" :key="t.id">
                    <button type="button"
                            class="chat-sidebar-item msgr-thread-item"
                            :class="{ 'is-active': activeThread && activeThread.id === t.id,
                                      'has-unread': t.unread_count > 0 }"
                            @click="selectThread(t)">
                        <div class="msgr-thread-avatar" x-text="initials(t.customer_name)"></div>
                        <div class="msgr-thread-body">
                            <div class="msgr-thread-top">
                                <span class="msgr-thread-name" x-text="threadDisplayName(t)"></span>
                                <span class="msgr-thread-time" x-text="formatTimeShort(t.last_message_at)"></span>
                            </div>
                            <div class="msgr-thread-preview">
                                <span x-show="t.last_message_by === 'admin'" style="color:var(--text-muted)">You: </span>
                                <span x-text="t.last_message_preview || 'No messages yet'"></span>
                            </div>
                        </div>
                        <span class="chat-unread-badge"
                              x-show="t.unread_count > 0"
                              x-text="t.unread_count > 99 ? '99+' : t.unread_count"></span>
                    </button>
                </template>

                <div class="chat-channel-empty" x-show="!loadingThreads && threads.length === 0" x-cloak style="padding:16px;text-align:center">
                    <p style="color:var(--text-muted);font-size:13px">No conversations yet.</p>
                    <button type="button" class="btn btn-primary btn-sm" @click="openNewThread()" style="margin-top:8px">Start a conversation</button>
                </div>
            </div>

        </div><!-- /chat-sidebar -->

        <!-- ── MAIN — Thread view ────────────────────────────────────── -->
        <div class="chat-main">

            <!-- Empty state -->
            <div class="chat-empty-state" x-show="!activeThread" x-cloak>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;color:var(--text-muted)"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                <p class="chat-empty-title">Select a conversation</p>
                <p class="chat-empty-sub">Pick a thread from the sidebar or start a new one to message a customer</p>
            </div>

            <template x-if="activeThread">
                <div class="chat-channel-view">

                    <!-- Thread header -->
                    <div class="chat-header">
                        <div class="chat-header-info">
                            <div class="msgr-thread-avatar" x-text="initials(activeThread.customer_name)" style="margin-right:12px"></div>
                            <div>
                                <div class="chat-header-name" x-text="threadDisplayName(activeThread)"></div>
                                <div class="chat-header-desc">
                                    <span x-text="activeThread.customer_name"></span>
                                    <span x-show="activeThread.scope === 'customer'"> · All portal users</span>
                                    <span x-show="activeThread.scope === 'portal_user'"> · Direct</span>
                                </div>
                            </div>
                        </div>
                        <div class="chat-header-actions">
                            <a :href="'<?= e(base_url('customers')) ?>/' + activeThread.customer_id"
                               class="btn btn-secondary btn-sm"
                               title="Open customer profile">View customer →</a>
                            <button type="button" class="btn-icon" @click="showInfo = !showInfo" title="Thread info">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Messages area -->
                    <div class="chat-messages-area"
                         id="msgr-messages-scroll"
                         @scroll="handleScroll($event)">

                        <div class="chat-load-more" x-show="hasMore">
                            <button type="button" class="btn btn-secondary btn-sm" @click="loadMore()" :disabled="loadingMore">
                                <span x-show="!loadingMore">Load older messages</span>
                                <span x-show="loadingMore">Loading…</span>
                            </button>
                        </div>

                        <div class="chat-loading" x-show="loadingMessages" x-cloak>
                            <div class="skeleton-row" style="margin-bottom:16px" x-for="i in [1,2,3]">
                                <div class="skeleton-cell" style="width:40px;height:40px;border-radius:50%;margin-right:12px;flex-shrink:0"></div>
                                <div style="flex:1"><div class="skeleton-bar" style="width:30%;height:12px;margin-bottom:6px"></div><div class="skeleton-bar" style="width:70%;height:14px"></div></div>
                            </div>
                        </div>

                        <template x-for="msg in messages" :key="msg.id">
                            <div class="chat-message msgr-message"
                                 :class="{ 'msgr-message--admin': msg.sender_type === 'admin',
                                           'msgr-message--portal': msg.sender_type === 'portal' }"
                                 :data-message-id="msg.id">

                                <div class="chat-avatar" x-text="initials(msg.author_name)"></div>

                                <div class="chat-message-body">
                                    <div class="chat-message-header">
                                        <span class="chat-message-author" x-text="msg.author_name"></span>
                                        <span class="msgr-side-label"
                                              :class="msg.sender_type === 'admin' ? 'msgr-side-label--admin' : 'msgr-side-label--portal'"
                                              x-text="msg.sender_type === 'admin' ? 'Team' : 'Customer'"></span>
                                        <span class="chat-message-time" x-text="formatTime(msg.created_at)"></span>
                                    </div>
                                    <div class="chat-message-text"
                                         x-show="!msg.is_archived"
                                         x-text="msg.display_text"></div>
                                    <div class="chat-message-deleted" x-show="msg.is_archived">[message deleted]</div>
                                </div>
                            </div>
                        </template>

                        <div class="chat-channel-empty" x-show="!loadingMessages && messages.length === 0" x-cloak>
                            <p>No messages yet. Say hi to <span x-text="threadDisplayName(activeThread)"></span>.</p>
                        </div>

                    </div>

                    <!-- Compose area -->
                    <div class="chat-compose">
                        <div class="chat-compose-row">
                            <textarea
                                class="chat-compose-input"
                                :placeholder="'Reply to ' + threadDisplayName(activeThread) + '…'"
                                x-model="composing"
                                x-ref="composeInput"
                                @keydown="handleComposeKeydown($event)"
                                rows="1"
                                style="resize:none;overflow:hidden;"
                                @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight, 160)+'px'">
                            </textarea>
                            <div class="chat-compose-actions">
                                <button type="button"
                                        class="btn btn-primary btn-sm chat-send-btn"
                                        @click="send()"
                                        :disabled="sending || !composing.trim()"
                                        title="Send (Enter)">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="chat-compose-hint">
                            <span><kbd>Enter</kbd> to send · <kbd>Shift+Enter</kbd> for a new line</span>
                        </div>
                    </div>
                </div>
            </template>

        </div><!-- /chat-main -->

        <!-- ── RIGHT PANEL — Thread info ─────────────────────────────── -->
        <div class="chat-info-panel" x-show="showInfo && activeThread" x-cloak>
            <div class="chat-info-header">
                <span>Conversation</span>
                <button type="button" class="btn-icon" @click="showInfo = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="chat-info-section" x-show="activeThreadDetail">
                <div class="chat-info-label">Customer</div>
                <div style="padding:8px 0">
                    <div style="font-weight:600" x-text="activeThreadDetail?.thread?.customer_name"></div>
                    <div style="color:var(--text-muted);font-size:13px" x-text="activeThreadDetail?.thread?.customer_email"></div>
                    <div style="color:var(--text-muted);font-size:13px" x-text="activeThreadDetail?.thread?.customer_phone"></div>
                </div>
            </div>
            <div class="chat-info-section" x-show="activeThreadDetail && activeThreadDetail.participants">
                <div class="chat-info-label">Participants (<span x-text="(activeThreadDetail?.participants || []).length"></span>)</div>
                <template x-for="p in (activeThreadDetail?.participants || [])" :key="p.id">
                    <div class="chat-info-member">
                        <div class="chat-info-member-avatar" x-text="initials(p.name)"></div>
                        <div class="chat-info-member-name">
                            <div x-text="p.name"></div>
                            <div style="color:var(--text-muted);font-size:12px" x-text="p.email"></div>
                        </div>
                        <span class="badge badge-info" x-show="p.is_primary == 1">Primary</span>
                    </div>
                </template>
            </div>
        </div>

    </div><!-- /chat-layout -->

    <!-- ── New Thread Modal ─────────────────────────────────────────── -->
    <!--
      Uses the standard .modal-overlay → .modal wrapping so it renders
      as a fixed-position centered overlay. Without the overlay wrapper
      the inner .modal is position:relative and gets laid out inline
      at the bottom of the page, pushing the contact list off-screen.
    -->
    <div class="modal-overlay" x-show="showNewThread" x-cloak @click.self="showNewThread = false">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="modal-title">New Conversation</h3>
                <button type="button" class="modal-close-btn" @click="showNewThread = false" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Search customers and contacts</label>
                    <input type="text"
                           class="form-control"
                           placeholder="Search company name, contact, or email…"
                           x-model="contactQuery"
                           @input.debounce.300ms="searchContacts()"
                           x-ref="contactSearchInput">
                </div>

                <div class="msgr-contact-list">

                    <!-- Customer companies (scope=customer) -->
                    <div x-show="contactResults.customers.length > 0">
                        <div class="chat-sidebar-label" style="padding:8px 4px 4px">COMPANIES — Message everyone</div>
                        <template x-for="c in contactResults.customers" :key="'c-' + c.id">
                            <button type="button"
                                    class="msgr-contact-item"
                                    @click="pickCustomer(c)">
                                <div class="msgr-contact-avatar" x-text="initials(c.company_name)"></div>
                                <div class="msgr-contact-body">
                                    <div class="msgr-contact-name" x-text="c.company_name"></div>
                                    <div class="msgr-contact-sub">
                                        <span x-text="c.portal_user_count + ' portal user' + (c.portal_user_count === 1 ? '' : 's')"></span>
                                        <span x-show="c.contact_name"> · <span x-text="c.contact_name"></span></span>
                                    </div>
                                </div>
                                <span class="msgr-contact-badge">Company</span>
                            </button>
                        </template>
                    </div>

                    <!-- Individual portal users (scope=portal_user) -->
                    <div x-show="contactResults.portal_users.length > 0">
                        <div class="chat-sidebar-label" style="padding:8px 4px 4px">CONTACTS — Message one person</div>
                        <template x-for="pu in contactResults.portal_users" :key="'pu-' + pu.id">
                            <button type="button"
                                    class="msgr-contact-item"
                                    @click="pickPortalUser(pu)">
                                <div class="msgr-contact-avatar" x-text="initials(pu.name)"></div>
                                <div class="msgr-contact-body">
                                    <div class="msgr-contact-name" x-text="pu.name"></div>
                                    <div class="msgr-contact-sub">
                                        <span x-text="pu.company_name"></span>
                                        <span> · <span x-text="pu.email"></span></span>
                                    </div>
                                </div>
                                <span class="msgr-contact-badge msgr-contact-badge--user">Contact</span>
                            </button>
                        </template>
                    </div>

                    <div x-show="contactResults.customers.length === 0 && contactResults.portal_users.length === 0 && !loadingContacts"
                         style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">
                        No customers or contacts match.
                    </div>
                    <div x-show="loadingContacts" style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">
                        Searching…
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /page-content -->

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
