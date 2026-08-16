<?php
/**
 * includes/partials/chat-widget.php
 *
 * CHAT-1 — Mini chat widget (Intercom-style) rendered on every admin page.
 * Collapsed: shows 💬 button with unread badge.
 * Expanded: 320×480px popup with channel list → messages → compose.
 * Uses the same FF_Chat API endpoints. Polling shared with FF_ChatBadge.
 *
 * Dependencies: api/v1/chat/* endpoints, FF_ChatWidget() in app.js
 * Spec: CHAT-1
 */
?>
<!--
  Topbar wiring: the topbar Chat icon opens this widget by calling
  Alpine.$data(document.getElementById('ff-chat-widget')).toggle()
  directly — no window globals, no arrow-function scope tricks, no
  dispatched events. That's the only way Alpine reactivity reliably
  triggers x-show from an external click handler because x-init's
  `with(scope)` block does not persist beyond the init phase.
-->
<div id="ff-chat-widget"
     class="chat-widget"
     x-data="FF_ChatWidget()"
     @keydown.escape.window="if(open) open = false">

    <!--
      Expanded panel.
      NOTE: x-transition removed intentionally. Alpine was getting stuck
      in the middle of the enter transition (panel left with inline
      display:none + enter-start class applied and never cleared),
      which made the whole chat widget invisible on every click. The
      CSS classes exist but Alpine's transition state-machine doesn't
      recover when the user clicks the topbar icon while another
      widget is also animating. Showing/hiding instantly is zero
      regression vs. the previous 200ms fade.
    -->
    <div class="chat-widget-panel"
         x-show="open"
         x-cloak>

        <!-- Panel header -->
        <div class="chat-widget-header">
            <template x-if="!activeChannel">
                <span>Team Chat</span>
            </template>
            <template x-if="activeChannel">
                <div style="display:flex;align-items:center;gap:6px">
                    <button type="button" class="btn-icon" @click="activeChannel = null; messages = []" style="padding:2px">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                    </button>
                    <span x-text="activeChannel.type === 'channel' ? '#' + activeChannel.name : activeChannel.name"></span>
                </div>
            </template>
            <div style="display:flex;align-items:center;gap:4px">
                <a href="<?= base_url('chat') ?>" class="btn-icon" title="Open full chat" style="font-size:11px;padding:4px 6px;text-decoration:none;color:var(--text-secondary)">Full chat ↗</a>
                <button type="button" class="btn-icon" @click="open = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Channel list (when no channel selected) -->
        <div class="chat-widget-channels" x-show="!activeChannel">
            <div class="chat-widget-section-label">CHANNELS</div>
            <template x-for="ch in channels" :key="ch.id">
                <button type="button" class="chat-widget-channel-item" @click="openChannel(ch)">
                    <span style="color:var(--text-muted)">#</span>
                    <span x-text="ch.name" style="flex:1;text-align:left"></span>
                    <span class="chat-widget-unread" x-show="ch.unread_count > 0" x-text="ch.unread_count > 9 ? '9+' : ch.unread_count"></span>
                </button>
            </template>
            <div class="chat-widget-section-label" style="margin-top:8px">DIRECT MESSAGES</div>
            <template x-for="dm in directMessages" :key="dm.id">
                <button type="button" class="chat-widget-channel-item" @click="openChannel(dm)">
                    <span class="chat-dm-dot" style="margin-right:4px"></span>
                    <span x-text="dm.other_user ? dm.other_user.name : dm.name" style="flex:1;text-align:left"></span>
                    <span class="chat-widget-unread" x-show="dm.unread_count > 0" x-text="dm.unread_count > 9 ? '9+' : dm.unread_count"></span>
                </button>
            </template>
        </div>

        <!-- Messages view (when channel selected) -->
        <div class="chat-widget-messages" x-show="activeChannel" x-cloak id="chat-widget-scroll">
            <template x-for="msg in messages" :key="msg.id">
                <div class="chat-widget-message">
                    <div class="chat-widget-msg-header">
                        <span class="chat-widget-msg-author" x-text="msg.user_name"></span>
                        <span class="chat-widget-msg-time" x-text="formatTime(msg.created_at)"></span>
                    </div>
                    <div class="chat-widget-msg-text"
                         x-show="!msg.is_archived"
                         x-text="msg.message"></div>
                    <div class="chat-widget-msg-deleted" x-show="msg.is_archived">[message deleted]</div>
                </div>
            </template>
            <div x-show="messages.length === 0 && !loading" style="color:var(--text-muted);text-align:center;padding:16px;font-size:12px">No messages yet</div>
        </div>

        <!-- Compose (when channel selected) -->
        <div class="chat-widget-compose" x-show="activeChannel" x-cloak>
            <textarea
                class="chat-widget-input"
                :placeholder="'Message ' + (activeChannel ? (activeChannel.type === 'channel' ? '#' + activeChannel.name : activeChannel.name) : '') + '…'"
                x-model="composing"
                @keydown.enter.exact.prevent="widgetSend()"
                @keydown.shift.enter="null"
                rows="1"
                style="resize:none;overflow:hidden"
                @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight, 80)+'px'">
            </textarea>
            <button type="button" class="btn btn-primary btn-sm"
                    @click="widgetSend()"
                    :disabled="!composing.trim() || sending"
                    style="flex-shrink:0">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/></svg>
            </button>
        </div>

    </div><!-- /chat-widget-panel -->

    <!-- Toggle button -->
    <button type="button"
            class="chat-widget-toggle"
            @click="toggle()"
            :class="{ 'has-unread': totalUnread > 0 }"
            :title="open ? 'Close chat' : 'Team chat' + (totalUnread > 0 ? ' (' + totalUnread + ' unread)' : '')">
        <!-- Chat icon when closed -->
        <template x-if="!open">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
        </template>
        <!-- X when open -->
        <template x-if="open">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </template>
        <span class="chat-widget-badge" x-show="!open && totalUnread > 0" x-text="totalUnread > 99 ? '99+' : totalUnread" aria-hidden="true"></span>
    </button>

</div>
