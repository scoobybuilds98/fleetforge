<?php
/**
 * app/admin/chat/index.php
 *
 * CHAT-1 — Full-page team chat interface.
 * 3-column layout: left sidebar (channels + DMs), main chat area, right panel.
 * Polling every 5s via FF_Chat() Alpine component.
 * Supports: @mentions, /record attachments, file upload, reactions, replies.
 *
 * Dependencies: includes/auth.php, includes/header.php, includes/footer.php,
 *               api/v1/chat/* endpoints, FF_Chat() in app.js
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

$pageTitle = 'Team Chat';
$channel_id_param = clean_int($_GET['channel'] ?? null) ?? 0;
$message_id_param = clean_int($_GET['message'] ?? null) ?? 0;

require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-content chat-page-content" x-data="FF_Chat()" x-init="init(<?= (int)$channel_id_param ?>, <?= (int)$message_id_param ?>)" @keydown.window="handleKeydown($event)">

    <!-- ── Chat Layout ─────────────────────────────────────────────── -->
    <div class="chat-layout">

        <!-- ── LEFT SIDEBAR ─────────────────────────────────────────── -->
        <div class="chat-sidebar">

            <div class="chat-sidebar-header">
                <span class="chat-sidebar-title">Team Chat</span>
                <button type="button"
                        class="btn-icon"
                        @click="openCreateChannel()"
                        title="New Channel">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                </button>
            </div>

            <!-- Channels section -->
            <div class="chat-sidebar-section">
                <div class="chat-sidebar-label">CHANNELS</div>
                <template x-for="ch in channels" :key="ch.id">
                    <button type="button"
                            class="chat-sidebar-item"
                            :class="{ 'is-active': activeChannel && activeChannel.id === ch.id,
                                      'has-unread': ch.unread_count > 0 }"
                            @click="selectChannel(ch)">
                        <span class="chat-channel-hash">#</span>
                        <span class="chat-sidebar-name" x-text="ch.name"></span>
                        <span class="chat-unread-badge"
                              x-show="ch.unread_count > 0"
                              x-text="ch.unread_count > 99 ? '99+' : ch.unread_count"></span>
                    </button>
                </template>
                <button type="button" class="chat-sidebar-browse" @click="openBrowseChannels()">
                    + Browse / Join
                </button>
            </div>

            <!-- Direct Messages section -->
            <div class="chat-sidebar-section">
                <div class="chat-sidebar-label">DIRECT MESSAGES</div>
                <template x-for="dm in directMessages" :key="dm.id">
                    <button type="button"
                            class="chat-sidebar-item"
                            :class="{ 'is-active': activeChannel && activeChannel.id === dm.id,
                                      'has-unread': dm.unread_count > 0 }"
                            @click="selectChannel(dm)">
                        <span class="chat-dm-dot"></span>
                        <span class="chat-sidebar-name" x-text="dm.other_user ? dm.other_user.name : dm.name"></span>
                        <span class="chat-unread-badge"
                              x-show="dm.unread_count > 0"
                              x-text="dm.unread_count > 99 ? '99+' : dm.unread_count"></span>
                    </button>
                </template>
                <button type="button" class="chat-sidebar-browse" @click="openNewDM()">
                    + New Message
                </button>
            </div>

        </div><!-- /chat-sidebar -->

        <!-- ── MAIN CHAT AREA ────────────────────────────────────────── -->
        <div class="chat-main">

            <!-- Empty state — no channel selected -->
            <div class="chat-empty-state" x-show="!activeChannel" x-cloak>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;color:var(--text-muted)"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                <p class="chat-empty-title">Select a channel to start chatting</p>
                <p class="chat-empty-sub">Choose a channel from the sidebar or start a direct message</p>
            </div>

            <!-- Active channel view -->
            <template x-if="activeChannel">
                <div class="chat-channel-view">

                    <!-- Channel header -->
                    <div class="chat-header">
                        <div class="chat-header-info">
                            <span class="chat-header-hash" x-text="activeChannel.type === 'channel' ? '#' : ''"></span>
                            <span class="chat-header-name" x-text="activeChannel.type === 'direct' ? (activeChannel.other_user ? activeChannel.other_user.name : activeChannel.name) : activeChannel.name"></span>
                            <span class="chat-header-desc" x-text="activeChannel.description || ''" x-show="activeChannel.description"></span>
                        </div>
                        <div class="chat-header-actions">
                            <button type="button" class="btn-icon" @click="toggleSearch()" title="Search messages">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                            </button>
                            <button type="button" class="btn-icon" @click="showInfo = !showInfo" title="Channel info">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Search bar (inline) -->
                    <div class="chat-search-bar" x-show="showSearch" x-cloak>
                        <input type="text" class="chat-search-input" placeholder="Search messages…"
                               x-model="searchQuery"
                               @input.debounce.400ms="searchMessages()"
                               @keydown.escape="toggleSearch()">
                        <div class="chat-search-results" x-show="searchResults.length > 0">
                            <template x-for="r in searchResults" :key="r.id">
                                <div class="chat-search-result" @click="jumpToMessage(r.id)">
                                    <span class="chat-search-result-user" x-text="r.user_name"></span>
                                    <span class="chat-search-result-msg" x-text="r.message"></span>
                                    <span class="chat-search-result-time" x-text="formatTime(r.created_at)"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Messages area -->
                    <div class="chat-messages-area"
                         id="chat-messages-scroll"
                         @scroll="handleScroll($event)">

                        <!-- Load more button -->
                        <div class="chat-load-more" x-show="hasMore">
                            <button type="button" class="btn btn-secondary btn-sm" @click="loadMore()" :disabled="loadingMore">
                                <span x-show="!loadingMore">Load older messages</span>
                                <span x-show="loadingMore">Loading…</span>
                            </button>
                        </div>

                        <!-- Messages skeleton -->
                        <div class="chat-loading" x-show="loadingMessages" x-cloak>
                            <div class="skeleton-row" style="margin-bottom:16px" x-for="i in [1,2,3,4,5]">
                                <div class="skeleton-cell" style="width:40px;height:40px;border-radius:50%;margin-right:12px;flex-shrink:0"></div>
                                <div style="flex:1"><div class="skeleton-bar" style="width:30%;height:12px;margin-bottom:6px"></div><div class="skeleton-bar" style="width:70%;height:14px"></div></div>
                            </div>
                        </div>

                        <!-- Message list -->
                        <template x-for="msg in messages" :key="msg.id">
                            <div class="chat-message"
                                 :class="{ 'chat-message--system': msg.type === 'system',
                                           'chat-message--mine': msg.user_id == <?= (int)(current_user()['id'] ?? 0) ?> }"
                                 :data-message-id="msg.id">

                                <!-- Avatar -->
                                <div class="chat-avatar" x-show="msg.type !== 'system'">
                                    <span x-text="initials(msg.user_name)"></span>
                                </div>

                                <div class="chat-message-body">
                                    <!-- Header: name + time -->
                                    <div class="chat-message-header" x-show="msg.type !== 'system'">
                                        <span class="chat-message-author" x-text="msg.user_name"></span>
                                        <span class="chat-message-time" x-text="formatTime(msg.created_at)"></span>
                                        <span class="chat-edited-marker" x-show="msg.is_edited">(edited)</span>
                                    </div>

                                    <!-- Reply context -->
                                    <div class="chat-reply-context" x-show="msg.reply_to_id">
                                        <span class="chat-reply-name" x-text="msg.reply_to_user_name || 'Unknown'"></span>
                                        <span class="chat-reply-text" x-text="truncate(msg.reply_to_message || '[deleted]', 80)"></span>
                                    </div>

                                    <!-- Message text -->
                                    <div class="chat-message-text"
                                         x-show="!msg.is_archived"
                                         x-html="formatMessageHtml(msg.message)"></div>
                                    <div class="chat-message-deleted" x-show="msg.is_archived">[message deleted]</div>

                                    <!-- Attachments -->
                                    <template x-for="att in msg.attachments" :key="att.id">
                                        <div class="chat-attachment-card" x-show="att.entity_id || att.file_path">
                                            <div class="chat-attachment-icon" x-text="attachmentIcon(att.type)"></div>
                                            <div class="chat-attachment-body">
                                                <div class="chat-attachment-title" x-text="attachmentTitle(att)"></div>
                                                <div class="chat-attachment-sub" x-text="attachmentSubtitle(att)"></div>
                                            </div>
                                            <a :href="attachmentUrl(att)"
                                               class="chat-attachment-link btn btn-secondary btn-xs"
                                               x-show="att.entity_id">View →</a>
                                        </div>
                                    </template>

                                    <!-- Reactions -->
                                    <div class="chat-reactions" x-show="msg.reactions && msg.reactions.length > 0">
                                        <template x-for="r in msg.reactions" :key="r.emoji">
                                            <button type="button"
                                                    class="chat-reaction-btn"
                                                    :class="{ 'mine': r.mine }"
                                                    @click="addReaction(msg.id, r.emoji)">
                                                <span x-text="r.emoji"></span>
                                                <span x-text="r.count"></span>
                                            </button>
                                        </template>
                                        <button type="button" class="chat-reaction-add" @click="showEmojiPicker(msg.id)" title="Add reaction">+😊</button>
                                    </div>

                                    <!-- Message actions (hover) -->
                                    <div class="chat-message-actions" x-show="!msg.is_archived">
                                        <button type="button" class="chat-action-btn" @click="showEmojiPicker(msg.id)" title="React">😊</button>
                                        <button type="button" class="chat-action-btn" @click="setReply(msg)" title="Reply">↩</button>
                                        <button type="button" class="chat-action-btn" @click="editMessage(msg)"
                                                x-show="msg.user_id == <?= (int)(current_user()['id'] ?? 0) ?>"
                                                title="Edit">✏️</button>
                                        <button type="button" class="chat-action-btn chat-action-btn--danger"
                                                @click="deleteMessage(msg.id)"
                                                x-show="msg.user_id == <?= (int)(current_user()['id'] ?? 0) ?>"
                                                title="Delete">🗑️</button>
                                    </div>

                                </div>
                            </div>
                        </template>

                        <!-- Empty channel -->
                        <div class="chat-channel-empty" x-show="!loadingMessages && messages.length === 0" x-cloak>
                            <p>No messages yet. Be the first to say something!</p>
                        </div>

                    </div><!-- /chat-messages-area -->

                    <!-- Compose area -->
                    <div class="chat-compose">

                        <!-- Reply context banner -->
                        <div class="chat-reply-banner" x-show="replyingTo" x-cloak>
                            <span>Replying to <strong x-text="replyingTo ? replyingTo.user_name : ''"></strong>:</span>
                            <span class="chat-reply-preview" x-text="replyingTo ? truncate(replyingTo.message, 60) : ''"></span>
                            <button type="button" class="chat-reply-cancel" @click="replyingTo = null">✕</button>
                        </div>

                        <!-- Edit context banner -->
                        <div class="chat-reply-banner chat-reply-banner--edit" x-show="editingMessage" x-cloak>
                            <span>Editing message</span>
                            <button type="button" class="chat-reply-cancel" @click="cancelEdit()">✕ Cancel</button>
                        </div>

                        <!-- Pending attachments -->
                        <div class="chat-pending-attachments" x-show="attachments.length > 0" x-cloak>
                            <template x-for="(att, i) in attachments" :key="i">
                                <div class="chat-pending-att">
                                    <span class="chat-pending-att-icon" x-text="attachmentIcon(att.type)"></span>
                                    <span class="chat-pending-att-name" x-text="att.preview_data ? att.preview_data.title : att.type"></span>
                                    <button type="button" class="chat-pending-att-remove" @click="removeAttachment(i)">✕</button>
                                </div>
                            </template>
                        </div>

                        <!-- @mention dropdown -->
                        <div class="chat-mention-dropdown" x-show="showMentions && mentionResults.length > 0" x-cloak>
                            <template x-for="u in mentionResults" :key="u.id">
                                <button type="button" class="chat-mention-item" @click.prevent="insertMention(u)">
                                    <span class="chat-mention-avatar" x-text="initials(u.name)"></span>
                                    <span x-text="u.name"></span>
                                </button>
                            </template>
                        </div>

                        <!-- /record picker dropdown -->
                        <div class="chat-record-picker" x-show="showRecordPicker" x-cloak>
                            <div class="chat-record-types" x-show="!recordPickerType">
                                <button class="chat-record-type-btn" @click="setRecordType('invoice')">📄 Invoice</button>
                                <button class="chat-record-type-btn" @click="setRecordType('lease')">📋 Lease</button>
                                <button class="chat-record-type-btn" @click="setRecordType('customer')">👤 Customer</button>
                                <button class="chat-record-type-btn" @click="setRecordType('equipment')">🚛 Equipment</button>
                                <button class="chat-record-type-btn" @click="setRecordType('work_order')">🔧 Work Order</button>
                                <button class="chat-record-type-btn" @click="setRecordType('damage_claim')">⚠️ Damage Claim</button>
                                <button class="chat-record-type-btn" @click="setRecordType('reservation')">📅 Reservation</button>
                            </div>
                            <div class="chat-record-search" x-show="recordPickerType">
                                <div class="chat-record-search-header">
                                    <button class="btn-icon" @click="recordPickerType = null">← Back</button>
                                    <input type="text" class="chat-record-search-input"
                                           :placeholder="'Search ' + recordPickerType + 's…'"
                                           x-model="recordSearchQuery"
                                           @input.debounce.300ms="searchRecords()"
                                           x-ref="recordSearchInput">
                                </div>
                                <div class="chat-record-results">
                                    <template x-for="r in recordSearchResults" :key="r.id">
                                        <button type="button" class="chat-record-result" @click="attachRecord(r)">
                                            <span class="chat-record-result-title" x-text="r.title"></span>
                                            <span class="chat-record-result-sub" x-text="r.subtitle || r.badge"></span>
                                        </button>
                                    </template>
                                    <div class="chat-record-empty" x-show="recordSearchResults.length === 0 && recordSearchQuery">
                                        No results found
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Compose input row -->
                        <div class="chat-compose-row">
                            <textarea
                                class="chat-compose-input"
                                :placeholder="'Message ' + (activeChannel.type === 'channel' ? '#' : '') + (activeChannel.type === 'direct' ? (activeChannel.other_user ? activeChannel.other_user.name : activeChannel.name) : activeChannel.name) + '…'"
                                x-model="composing"
                                x-ref="composeInput"
                                @keydown="handleComposeKeydown($event)"
                                @input="handleComposeInput()"
                                rows="1"
                                style="resize:none;overflow:hidden;"
                                @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight, 160)+'px'">
                            </textarea>

                            <div class="chat-compose-actions">
                                <button type="button" class="btn-icon chat-attach-btn" title="Attach file" @click="$refs.fileInput.click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
                                </button>
                                <button type="button"
                                        class="btn btn-primary btn-sm chat-send-btn"
                                        @click="send()"
                                        :disabled="sending || (!composing.trim() && attachments.length === 0)"
                                        title="Send (Enter)">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="chat-compose-hint">
                            <span>Type <kbd>@</kbd> to mention someone · <kbd>/</kbd> to attach a record · <kbd>Enter</kbd> to send · <kbd>Shift+Enter</kbd> for new line</span>
                        </div>

                        <!-- Hidden file input -->
                        <input type="file" x-ref="fileInput" style="display:none"
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.xlsx,.xls,.docx,.doc"
                               @change="handleFileUpload($event)">

                    </div><!-- /chat-compose -->

                </div><!-- /chat-channel-view -->
            </template>

        </div><!-- /chat-main -->

        <!-- ── RIGHT INFO PANEL ──────────────────────────────────────── -->
        <div class="chat-info-panel" x-show="showInfo && activeChannel" x-cloak>
            <div class="chat-info-header">
                <span x-text="activeChannel ? activeChannel.name : ''"></span>
                <button type="button" class="btn-icon" @click="showInfo = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="chat-info-desc" x-show="activeChannel && activeChannel.description">
                <p x-text="activeChannel ? activeChannel.description : ''"></p>
            </div>
            <div class="chat-info-section">
                <div class="chat-info-label" x-text="(activeChannel ? activeChannel.member_count : 0) + ' Members'"></div>
                <template x-for="m in channelMembers" :key="m.id">
                    <div class="chat-info-member">
                        <div class="chat-info-member-avatar" x-text="initials(m.name)"></div>
                        <div class="chat-info-member-name" x-text="m.name"></div>
                        <div class="chat-info-member-role badge" :class="m.role === 'admin' ? 'badge-info' : 'badge-neutral'" x-text="m.role"></div>
                    </div>
                </template>
            </div>
        </div><!-- /chat-info-panel -->

    </div><!-- /chat-layout -->

    <!-- Create Channel Modal -->
    <div class="modal" x-show="showCreateChannel" x-cloak @click.self="showCreateChannel = false">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3>Create Channel</h3>
                <button type="button" class="btn-icon" @click="showCreateChannel = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Channel Name</label>
                    <input type="text" class="form-control" x-model="newChannel.name" placeholder="e.g. project-updates" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" x-model="newChannel.description" placeholder="What is this channel about?" maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-check-label">
                        <input type="checkbox" x-model="newChannel.is_private"> Private channel
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="showCreateChannel = false">Cancel</button>
                <button type="button" class="btn btn-primary" @click="createChannel()" :disabled="!newChannel.name.trim()">Create Channel</button>
            </div>
        </div>
    </div>

    <!-- Browse Channels Modal -->
    <div class="modal" x-show="showBrowse" x-cloak @click.self="showBrowse = false">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3>Browse Channels</h3>
                <button type="button" class="btn-icon" @click="showBrowse = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control" placeholder="Search channels…"
                       x-model="browseQuery"
                       @input.debounce.300ms="loadBrowseChannels()">
                <div style="margin-top:12px">
                    <template x-for="ch in browseChannels" :key="ch.id">
                        <div class="chat-browse-item">
                            <div class="chat-browse-info">
                                <span class="chat-browse-name"># <span x-text="ch.name"></span></span>
                                <span class="chat-browse-desc" x-text="ch.description || ''"></span>
                                <span class="chat-browse-count" x-text="ch.member_count + ' members'"></span>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    @click="joinChannel(ch)"
                                    x-show="!isChannelMember(ch.id)">Join</button>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    @click="selectChannelById(ch.id); showBrowse = false"
                                    x-show="isChannelMember(ch.id)">Open</button>
                        </div>
                    </template>
                    <div x-show="browseChannels.length === 0" style="color:var(--text-muted);text-align:center;padding:24px">No channels found</div>
                </div>
            </div>
        </div>
    </div>

    <!-- New DM Modal -->
    <div class="modal" x-show="showNewDM" x-cloak @click.self="showNewDM = false">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3>New Direct Message</h3>
                <button type="button" class="btn-icon" @click="showNewDM = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control" placeholder="Search users…"
                       x-model="dmUserQuery"
                       @input.debounce.300ms="searchDMUsers()">
                <div style="margin-top:12px">
                    <template x-for="u in dmUserResults" :key="u.id">
                        <button type="button" class="chat-dm-user-item" @click="startDM(u.id)">
                            <span class="chat-dm-user-avatar" x-text="initials(u.name)"></span>
                            <span x-text="u.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Emoji Picker (simple inline) -->
    <div class="chat-emoji-picker" x-show="emojiPickerMessageId !== null" x-cloak @click.outside="emojiPickerMessageId = null">
        <template x-for="em in commonEmojis" :key="em">
            <button type="button" class="chat-emoji-btn" @click="addReaction(emojiPickerMessageId, em); emojiPickerMessageId = null" x-text="em"></button>
        </template>
    </div>

</div><!-- /page-content -->

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
