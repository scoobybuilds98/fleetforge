<?php
/**
 * app/admin/chat/index.php
 *
 * CHAT-2 — Full-page team chat interface.
 * Three-column layout: sidebar (channels + DMs + customer convos),
 * main chat area, toggleable right panel.
 * Polling every 5s via FF_Chat() Alpine.js component.
 * Supports: @mentions, /record attachments, file upload, reactions,
 *           replies, date separators, new-message banner.
 *
 * Dependencies: includes/auth.php, includes/header.php, includes/footer.php,
 *               api/v1/chat/* endpoints, FF_Chat() in public/assets/js/app.js
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

$pageTitle        = 'Team Chat';
$channel_id_param = clean_int($_GET['channel'] ?? null) ?? 0;
$message_id_param = clean_int($_GET['message'] ?? null) ?? 0;
$currentUserId    = (int)(current_user()['id'] ?? 0);
$currentUserName  = e(current_user()['name'] ?? '');

require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
/*
   [UI-AUDIT-1:N10] Replace hardcoded hex colors with CSS variables so the
   chat page honours both dark and light themes. The previous values were
   handpicked for a specific dark palette and broke the moment the user
   toggled to light mode. Design tokens map:
     #1e1e1e  -> var(--bg-surface)
     #2a2a2a  -> var(--bg-surface-2)
     #3a3a3a  -> var(--bg-surface-hover)
     #fff     -> var(--text-primary)
     #ccc     -> var(--text-primary)
     #ddd     -> var(--text-primary)
     #aaa     -> var(--text-secondary)
     #888     -> var(--text-tertiary)
     #666     -> var(--text-tertiary)
     #555     -> var(--text-tertiary)
     #22c55e  -> var(--color-success)
*/

/* ── Chat page overrides — full viewport height ─────────────────────── */
.page-content.chat-page-content { padding: 0; height: calc(100vh - 60px); display: flex; flex-direction: column; overflow: hidden; }
.chat-layout { display: flex; flex: 1; overflow: hidden; }

/* ── Left Sidebar ────────────────────────────────────────────────────── */
.chat-sidebar { width: 260px; min-width: 260px; background: var(--bg-surface); display: flex; flex-direction: column; overflow: hidden; border-right: 1px solid var(--border-color); }
.chat-sidebar-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 10px; border-bottom: 1px solid var(--border-color); }
.chat-sidebar-title { font-weight: 600; font-size: 15px; color: var(--text-primary); }
.chat-sidebar-search { padding: 8px 12px; }
.chat-sidebar-search-input { width: 100%; padding: 6px 10px; background: var(--bg-surface-2); border: 1px solid var(--border-color-strong); border-radius: 6px; color: var(--text-primary); font-size: 13px; outline: none; }
.chat-sidebar-search-input::placeholder { color: var(--text-tertiary); }
.chat-sidebar-search-input:focus { border-color: var(--color-accent); }
.chat-sidebar-body { flex: 1; overflow-y: auto; padding-bottom: 16px; }
.chat-sidebar-body::-webkit-scrollbar { width: 4px; }
.chat-sidebar-body::-webkit-scrollbar-track { background: transparent; }
.chat-sidebar-body::-webkit-scrollbar-thumb { background: var(--border-color-strong); border-radius: 2px; }
.chat-sidebar-section { padding: 8px 0; }
.chat-sidebar-section-header { display: flex; align-items: center; justify-content: space-between; padding: 4px 10px 4px 16px; }
.chat-sidebar-label { font-size: 11px; font-weight: 700; color: var(--text-tertiary); letter-spacing: .06em; text-transform: uppercase; flex: 1; }
.chat-sidebar-add { display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 4px; color: var(--text-tertiary); cursor: pointer; background: none; border: none; font-size: 16px; line-height: 1; }
.chat-sidebar-add:hover { background: var(--bg-surface-2); color: var(--text-secondary); }
.chat-sidebar-item { display: flex; align-items: center; gap: 6px; width: 100%; padding: 5px 16px; cursor: pointer; background: none; border: none; color: var(--text-secondary); font-size: 14px; text-align: left; border-radius: 0; transition: background .1s; }
.chat-sidebar-item:hover { background: var(--bg-surface-2); color: var(--text-primary); }
.chat-sidebar-item.is-active { background: var(--bg-surface-hover); color: var(--text-primary); font-weight: 600; }
.chat-sidebar-item.has-unread { color: var(--text-primary); font-weight: 600; }
.chat-channel-hash { color: var(--text-tertiary); font-size: 15px; line-height: 1; flex-shrink: 0; }
.chat-sidebar-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-unread-badge { background: var(--color-accent); color: var(--text-on-primary); font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.chat-online-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-tertiary); flex-shrink: 0; }
.chat-online-dot.is-online { background: var(--color-success); }
.chat-customer-icon { color: var(--text-tertiary); font-size: 13px; flex-shrink: 0; }
.chat-sidebar-browse { display: block; width: 100%; padding: 4px 16px; background: none; border: none; color: var(--text-tertiary); font-size: 12px; text-align: left; cursor: pointer; }
.chat-sidebar-browse:hover { color: var(--text-secondary); }

/* ── Main area ───────────────────────────────────────────────────────── */
.chat-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--bg-card); }
.chat-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--text-muted); padding: 40px; }
.chat-empty-title { font-size: 18px; font-weight: 600; color: var(--text-secondary); }
.chat-empty-sub { font-size: 14px; color: var(--text-muted); }
.chat-channel-view { display: flex; flex-direction: column; flex: 1; overflow: hidden; }

/* ── Channel header ──────────────────────────────────────────────────── */
.chat-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); flex-shrink: 0; }
.chat-header-info { display: flex; align-items: baseline; gap: 8px; min-width: 0; }
.chat-header-hash { font-size: 18px; color: var(--text-muted); font-weight: 400; }
.chat-header-name { font-size: 16px; font-weight: 700; color: var(--text-primary); }
.chat-header-desc { font-size: 13px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-header-actions { display: flex; gap: 4px; flex-shrink: 0; }

/* ── Message search bar ──────────────────────────────────────────────── */
.chat-search-bar { padding: 8px 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-muted); }
.chat-search-input { width: 100%; padding: 7px 12px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-card); color: var(--text-primary); font-size: 13px; outline: none; }
.chat-search-input:focus { border-color: var(--color-accent); }
.chat-search-results { margin-top: 8px; max-height: 300px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-card); }
.chat-search-result { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid var(--border-color); }
.chat-search-result:last-child { border-bottom: none; }
.chat-search-result:hover { background: var(--bg-hover); }
.chat-search-result-user { font-size: 12px; font-weight: 600; color: var(--text-secondary); display: block; }
.chat-search-result-msg { font-size: 13px; color: var(--text-primary); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-search-result-time { font-size: 11px; color: var(--text-muted); display: block; }

/* ── New messages banner ─────────────────────────────────────────────── */
.chat-new-msgs-banner { position: absolute; bottom: 90px; left: 50%; transform: translateX(-50%); background: var(--color-accent); color: #fff; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; cursor: pointer; z-index: 10; box-shadow: var(--shadow-md); }

/* ── Messages area ───────────────────────────────────────────────────── */
.chat-messages-wrap { flex: 1; overflow: hidden; position: relative; }
.chat-messages-area { height: 100%; overflow-y: auto; padding: 16px 20px 8px; display: flex; flex-direction: column; gap: 0; }
.chat-msg-wrapper { display: contents; } /* transparent wrapper — child element inherits flex parent layout */
.chat-messages-area::-webkit-scrollbar { width: 6px; }
.chat-messages-area::-webkit-scrollbar-track { background: transparent; }
.chat-messages-area::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }
.chat-load-more { text-align: center; padding: 8px 0 16px; }
.chat-loading { padding: 16px; }

/* ── Date separator ──────────────────────────────────────────────────── */
.chat-date-sep { display: flex; align-items: center; gap: 12px; margin: 16px 0 8px; }
.chat-date-sep-line { flex: 1; height: 1px; background: var(--border-color); }
.chat-date-sep-label { font-size: 11px; font-weight: 600; color: var(--text-muted); white-space: nowrap; background: var(--bg-card); padding: 0 8px; border-radius: 10px; border: 1px solid var(--border-color); }

/* ── Messages ────────────────────────────────────────────────────────── */
.chat-message { display: flex; gap: 10px; padding: 4px 0; position: relative; border-radius: 6px; transition: background .1s; }
.chat-message:hover { background: var(--bg-hover); }
.chat-message:hover .chat-message-actions { opacity: 1; }
.chat-message--system { justify-content: center; padding: 6px 0; }
.chat-message--system .chat-avatar { display: none; }
.chat-message-system-text { font-size: 12px; color: var(--text-muted); font-style: italic; }
.chat-message--highlight { background: rgba(59,130,246,.1) !important; transition: background 1s; }

/* ── Avatar ──────────────────────────────────────────────────────────── */
.chat-avatar { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; margin-top: 2px; }

/* ── Message body ────────────────────────────────────────────────────── */
.chat-message-body { flex: 1; min-width: 0; }
.chat-message-header { display: flex; align-items: baseline; gap: 8px; margin-bottom: 2px; }
.chat-message-author { font-weight: 700; font-size: 14px; color: var(--text-primary); }
.chat-message-time { font-size: 11px; color: var(--text-muted); }
.chat-edited-marker { font-size: 11px; color: var(--text-muted); font-style: italic; }
.chat-message-text { font-size: 14px; color: var(--text-primary); line-height: 1.5; word-break: break-word; }
.chat-message-deleted { font-size: 13px; color: var(--text-muted); font-style: italic; }
.chat-mention { color: var(--color-accent); font-weight: 600; background: rgba(59,130,246,.08); border-radius: 3px; padding: 0 2px; }
.chat-link { color: var(--color-accent); text-decoration: underline; }

/* ── Reply context ───────────────────────────────────────────────────── */
.chat-reply-context { display: flex; gap: 6px; align-items: center; border-left: 3px solid var(--color-accent); padding: 3px 8px; margin-bottom: 4px; background: var(--bg-muted); border-radius: 0 4px 4px 0; }
.chat-reply-name { font-size: 12px; font-weight: 600; color: var(--color-accent); white-space: nowrap; }
.chat-reply-text { font-size: 12px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }

/* ── Attachment card ─────────────────────────────────────────────────── */
.chat-attachment-card { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; margin-top: 6px; max-width: 360px; background: var(--bg-muted); transition: background .1s; }
.chat-attachment-card:hover { background: var(--bg-hover); }
.chat-attachment-icon { font-size: 22px; flex-shrink: 0; }
.chat-attachment-body { flex: 1; min-width: 0; }
.chat-attachment-title { font-size: 13px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-attachment-sub { font-size: 12px; color: var(--text-secondary); }
.chat-attachment-badge { font-size: 10px; }
.chat-attachment-link { flex-shrink: 0; }

/* ── Reactions ───────────────────────────────────────────────────────── */
.chat-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.chat-reaction-btn { display: flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-muted); font-size: 13px; cursor: pointer; transition: background .1s; }
.chat-reaction-btn:hover { background: var(--bg-hover); }
.chat-reaction-btn.mine { border-color: var(--color-accent); background: rgba(59,130,246,.1); }
.chat-reaction-add { padding: 2px 6px; border-radius: 12px; border: 1px dashed var(--border-color); background: none; font-size: 12px; cursor: pointer; color: var(--text-muted); }
.chat-reaction-add:hover { background: var(--bg-hover); color: var(--text-primary); }
.chat-emoji-picker { position: absolute; bottom: 100%; right: 0; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px; box-shadow: var(--shadow-lg); display: flex; flex-wrap: wrap; gap: 4px; width: 200px; z-index: 50; }
.chat-emoji-btn { font-size: 18px; padding: 3px; border-radius: 4px; cursor: pointer; background: none; border: none; }
.chat-emoji-btn:hover { background: var(--bg-hover); }

/* ── Message actions ─────────────────────────────────────────────────── */
.chat-message-actions { position: absolute; top: -16px; right: 8px; display: flex; gap: 2px; opacity: 0; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; padding: 2px; box-shadow: var(--shadow-sm); transition: opacity .1s; z-index: 5; }
.chat-action-btn { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 4px; border: none; background: none; cursor: pointer; font-size: 14px; color: var(--text-secondary); transition: background .1s; }
.chat-action-btn:hover { background: var(--bg-hover); color: var(--text-primary); }
.chat-action-btn--danger:hover { background: rgba(220,38,38,.1); color: var(--color-danger); }

/* ── Compose area ────────────────────────────────────────────────────── */
.chat-compose { border-top: 1px solid var(--border-color); padding: 12px 20px; flex-shrink: 0; position: relative; background: var(--bg-card); }
.chat-reply-banner { display: flex; align-items: center; gap: 8px; background: var(--bg-muted); border-left: 3px solid var(--color-accent); padding: 6px 10px; border-radius: 0 6px 6px 0; margin-bottom: 8px; font-size: 13px; color: var(--text-secondary); }
.chat-reply-banner--edit { border-left-color: var(--color-warning); }
.chat-reply-preview { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-muted); }
.chat-reply-cancel { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 14px; padding: 0 4px; }
.chat-reply-cancel:hover { color: var(--text-primary); }
.chat-pending-attachments { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.chat-pending-att { display: flex; align-items: center; gap: 6px; background: var(--bg-muted); border: 1px solid var(--border-color); border-radius: 6px; padding: 4px 8px; font-size: 12px; }
.chat-pending-att-icon { font-size: 14px; }
.chat-pending-att-name { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-secondary); }
.chat-pending-att-remove { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 13px; }
.chat-pending-att-remove:hover { color: var(--color-danger); }
.chat-compose-row { display: flex; gap: 8px; align-items: flex-end; }
.chat-compose-input { flex: 1; padding: 9px 13px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-input); color: var(--text-primary); font-size: 14px; font-family: inherit; resize: none; overflow: hidden; outline: none; max-height: 160px; line-height: 1.5; }
.chat-compose-input:focus { border-color: var(--color-accent); }
.chat-compose-actions { display: flex; gap: 4px; align-items: flex-end; }
.chat-send-btn { height: 38px; }
.chat-compose-hint { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.chat-compose-hint kbd { background: var(--bg-muted); border: 1px solid var(--border-color); border-radius: 3px; padding: 0 3px; font-size: 10px; }

/* ── @mention dropdown ────────────────────────────────────────────────── */
.chat-mention-dropdown { position: absolute; bottom: calc(100% + 4px); left: 20px; width: 240px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: var(--shadow-lg); z-index: 50; overflow: hidden; max-height: 200px; overflow-y: auto; }
.chat-mention-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 12px; cursor: pointer; background: none; border: none; text-align: left; font-size: 14px; color: var(--text-primary); }
.chat-mention-item:hover, .chat-mention-item.is-selected { background: var(--bg-hover); }
.chat-mention-avatar { width: 24px; height: 24px; border-radius: 50%; background: var(--color-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; }

/* ── /record picker ───────────────────────────────────────────────────── */
.chat-record-picker { position: absolute; bottom: calc(100% + 4px); left: 20px; width: 340px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: var(--shadow-lg); z-index: 50; overflow: hidden; }
.chat-record-types { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--border-color); }
.chat-record-type-btn { padding: 10px 12px; background: var(--bg-card); border: none; cursor: pointer; text-align: left; font-size: 13px; color: var(--text-primary); display: flex; align-items: center; gap: 6px; }
.chat-record-type-btn:hover { background: var(--bg-hover); }
.chat-record-search { padding: 12px; }
.chat-record-search-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.chat-record-search-input { flex: 1; padding: 7px 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-input); color: var(--text-primary); font-size: 13px; outline: none; }
.chat-record-search-input:focus { border-color: var(--color-accent); }
.chat-record-results { max-height: 200px; overflow-y: auto; }
.chat-record-result { display: flex; flex-direction: column; width: 100%; padding: 8px 10px; cursor: pointer; background: none; border: none; text-align: left; border-radius: 6px; }
.chat-record-result:hover, .chat-record-result.is-selected { background: var(--bg-hover); }
.chat-record-result-title { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.chat-record-result-sub { font-size: 12px; color: var(--text-secondary); }
.chat-record-empty { padding: 12px; text-align: center; color: var(--text-muted); font-size: 13px; }
.chat-record-loading { padding: 12px; text-align: center; color: var(--text-muted); font-size: 13px; }

/* ── Right panel ─────────────────────────────────────────────────────── */
.chat-right-panel { width: 300px; min-width: 300px; border-left: 1px solid var(--border-color); display: flex; flex-direction: column; background: var(--bg-card); overflow-y: auto; }
.chat-right-panel::-webkit-scrollbar { width: 4px; }
.chat-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 15px; color: var(--text-primary); }
.chat-panel-section { padding: 16px; border-bottom: 1px solid var(--border-color); }
.chat-panel-section-title { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }
.chat-panel-member { display: flex; align-items: center; gap: 8px; padding: 5px 0; }
.chat-panel-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--color-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; }
.chat-panel-member-name { flex: 1; font-size: 13px; color: var(--text-primary); }
.chat-panel-member-role { font-size: 10px; }

/* ── Modals ──────────────────────────────────────────────────────────── */
.user-chip { display: flex; align-items: center; gap: 4px; background: var(--bg-muted); border: 1px solid var(--border-color); border-radius: 14px; padding: 3px 8px; font-size: 12px; }
.user-chip-remove { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 13px; line-height: 1; padding: 0 0 0 2px; }
.user-chip-remove:hover { color: var(--color-danger); }
.user-search-result { display: flex; align-items: center; gap: 8px; padding: 8px 10px; cursor: pointer; border-radius: 6px; }
.user-search-result:hover { background: var(--bg-hover); }

/* ── Channel empty state ─────────────────────────────────────────────── */
.chat-channel-empty { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 14px; padding: 40px; }

/* ── Avatar colors (cycle by user_id) ───────────────────────────────── */
.chat-avatar-0 { background: #3b82f6; } .chat-avatar-1 { background: #8b5cf6; }
.chat-avatar-2 { background: #ec4899; } .chat-avatar-3 { background: #f59e0b; }
.chat-avatar-4 { background: #10b981; } .chat-avatar-5 { background: #ef4444; }
.chat-avatar-6 { background: #06b6d4; } .chat-avatar-7 { background: #f97316; }
</style>

<div class="page-content chat-page-content"
     x-data="FF_Chat()"
     x-init="init(<?= (int)$channel_id_param ?>, <?= (int)$message_id_param ?>)"
     @keydown.window="handleKeydown($event)"
     data-current-user-id="<?= $currentUserId ?>">

    <div class="chat-layout">

        <!-- ── LEFT SIDEBAR ───────────────────────────────────────────── -->
        <div class="chat-sidebar">

            <div class="chat-sidebar-header">
                <span class="chat-sidebar-title">💬 Team Chat</span>
                <button type="button" class="btn-icon" @click="openCreateChannel()" title="New Channel" style="color:#aaa">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                </button>
            </div>

            <!-- Sidebar search -->
            <div class="chat-sidebar-search">
                <input type="text" class="chat-sidebar-search-input" placeholder="Search channels…"
                       x-model="sidebarSearch">
            </div>

            <div class="chat-sidebar-body">

                <!-- CHANNELS -->
                <div class="chat-sidebar-section">
                    <div class="chat-sidebar-section-header">
                        <span class="chat-sidebar-label">Channels</span>
                        <button type="button" class="chat-sidebar-add" @click="openCreateChannel()" title="New channel">+</button>
                    </div>
                    <template x-for="ch in filteredChannels" :key="ch.id">
                        <button type="button"
                                class="chat-sidebar-item"
                                :class="{ 'is-active': activeChannel?.id === ch.id, 'has-unread': ch.unread_count > 0 }"
                                @click="selectChannel(ch)">
                            <span class="chat-channel-hash">#</span>
                            <span class="chat-sidebar-name" x-text="ch.name"></span>
                            <span class="chat-unread-badge" x-show="ch.unread_count > 0"
                                  x-text="ch.unread_count > 99 ? '99+' : ch.unread_count"></span>
                        </button>
                    </template>
                    <button type="button" class="chat-sidebar-browse" @click="openBrowseChannels()">+ Browse All</button>
                </div>

                <!-- DIRECT MESSAGES -->
                <div class="chat-sidebar-section">
                    <div class="chat-sidebar-section-header">
                        <span class="chat-sidebar-label">Direct Messages</span>
                        <button type="button" class="chat-sidebar-add" @click="openNewDM()" title="New DM">+</button>
                    </div>
                    <template x-for="dm in filteredDMs" :key="dm.id">
                        <button type="button"
                                class="chat-sidebar-item"
                                :class="{ 'is-active': activeChannel?.id === dm.id, 'has-unread': dm.unread_count > 0 }"
                                @click="selectChannel(dm)">
                            <span class="chat-online-dot" :class="{ 'is-online': isOnline(dm.other_user?.id) }"></span>
                            <span class="chat-sidebar-name" x-text="dm.other_user ? dm.other_user.name : dm.name"></span>
                            <span class="chat-unread-badge" x-show="dm.unread_count > 0"
                                  x-text="dm.unread_count > 99 ? '99+' : dm.unread_count"></span>
                        </button>
                    </template>
                    <button type="button" class="chat-sidebar-browse" @click="openNewDM()">+ New Direct Message</button>
                </div>

                <!-- CUSTOMER CHATS -->
                <div class="chat-sidebar-section">
                    <div class="chat-sidebar-section-header">
                        <span class="chat-sidebar-label">Customer Chats</span>
                        <button type="button" class="chat-sidebar-add" @click="openCustomerConvo()" title="New conversation">+</button>
                    </div>
                    <template x-for="cv in filteredCustomerConvos" :key="cv.id">
                        <button type="button"
                                class="chat-sidebar-item"
                                :class="{ 'is-active': activeChannel?.id === cv.id, 'has-unread': cv.unread_count > 0 }"
                                @click="selectChannel(cv)">
                            <span class="chat-customer-icon">💬</span>
                            <span class="chat-sidebar-name" x-text="cv.customer_company_name || cv.name"></span>
                            <span class="chat-unread-badge" x-show="cv.unread_count > 0"
                                  x-text="cv.unread_count > 99 ? '99+' : cv.unread_count"></span>
                        </button>
                    </template>
                    <button type="button" class="chat-sidebar-browse" @click="openCustomerConvo()">+ Start Customer Chat</button>
                </div>

            </div><!-- /chat-sidebar-body -->
        </div><!-- /chat-sidebar -->

        <!-- ── MAIN CHAT AREA ─────────────────────────────────────────── -->
        <div class="chat-main">

            <!-- Empty state -->
            <div class="chat-empty-state" x-show="!activeChannel" x-cloak>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:48px;height:48px;opacity:.3"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                <p class="chat-empty-title">Select a channel to start chatting</p>
                <p class="chat-empty-sub">Choose a channel from the sidebar or start a direct message</p>
            </div>

            <!-- Active channel view -->
            <template x-if="activeChannel">
                <div class="chat-channel-view">

                    <!-- Channel header -->
                    <div class="chat-header">
                        <div class="chat-header-info">
                            <span class="chat-header-hash" x-text="activeChannel.type === 'channel' ? '#' : (activeChannel.type === 'customer' ? '💬' : '')"></span>
                            <span class="chat-header-name"
                                  x-text="activeChannel.type === 'direct' ? (activeChannel.other_user?.name || activeChannel.name) : (activeChannel.type === 'customer' ? (activeChannel.customer_company_name || activeChannel.name) : activeChannel.name)"></span>
                            <span class="chat-header-desc"
                                  x-show="activeChannel.description"
                                  x-text="activeChannel.description"></span>
                        </div>
                        <div class="chat-header-actions">
                            <button type="button" class="btn-icon" @click="toggleSearch()" title="Search messages">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                            </button>
                            <button type="button" class="btn-icon" @click="showRightPanel = !showRightPanel" title="Members">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Search bar -->
                    <div class="chat-search-bar" x-show="showSearch" x-cloak>
                        <input type="text" class="chat-search-input"
                               placeholder="Search messages in this channel…"
                               x-model="messageSearch"
                               @input.debounce.300ms="searchMessages()"
                               @keydown.escape="toggleSearch()"
                               x-ref="searchInput">
                        <div class="chat-search-results" x-show="searchResults.length > 0">
                            <template x-for="r in searchResults" :key="r.id">
                                <div class="chat-search-result" @click="jumpToMessage(r.id)">
                                    <span class="chat-search-result-user" x-text="r.user_name"></span>
                                    <span class="chat-search-result-msg" x-text="r.snippet || r.message"></span>
                                    <span class="chat-search-result-time" x-text="formatTime(r.created_at)"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Messages wrap (relative for banner) -->
                    <div class="chat-messages-wrap">

                        <!-- New messages banner -->
                        <div class="chat-new-msgs-banner"
                             x-show="showNewMsgsBanner"
                             x-cloak
                             @click="scrollToBottom(true)">
                            ↓ New messages
                        </div>

                        <!-- Messages scroll area -->
                        <div class="chat-messages-area"
                             id="chat-messages-scroll"
                             @scroll="handleScroll($event)">

                            <div class="chat-load-more" x-show="hasMore">
                                <button type="button" class="btn btn-secondary btn-sm"
                                        @click="loadMore()" :disabled="loadingMore">
                                    <span x-show="!loadingMore">Load older messages</span>
                                    <span x-show="loadingMore">Loading…</span>
                                </button>
                            </div>

                            <!-- Loading skeleton -->
                            <div class="chat-loading" x-show="loadingMessages" x-cloak>
                                <template x-for="i in [1,2,3,4,5]">
                                    <div style="display:flex;gap:10px;margin-bottom:18px">
                                        <div class="skeleton-cell" style="width:36px;height:36px;border-radius:50%;flex-shrink:0"></div>
                                        <div style="flex:1"><div class="skeleton-bar" style="width:25%;height:11px;margin-bottom:6px"></div><div class="skeleton-bar" style="width:65%;height:14px"></div></div>
                                    </div>
                                </template>
                            </div>

                            <!-- Message list with date separators -->
                            <!-- WHY single wrapper div: Alpine x-for requires
                                 exactly ONE root element per iteration. Having
                                 two sibling `<template x-if>` blocks silently
                                 dropped the second (message) template — so
                                 newly-sent messages never rendered even
                                 though the POST succeeded. -->
                            <template x-for="(item, idx) in messagesWithSeparators" :key="item.key">
                                <div class="chat-msg-wrapper">
                                <template x-if="item.type === 'separator'">
                                    <div class="chat-date-sep">
                                        <div class="chat-date-sep-line"></div>
                                        <span class="chat-date-sep-label" x-text="item.label"></span>
                                        <div class="chat-date-sep-line"></div>
                                    </div>
                                </template>
                                <template x-if="item.type === 'message'">
                                    <div class="chat-message"
                                         :class="{
                                            'chat-message--system': item.msg.type === 'system',
                                            'chat-message--highlight': highlightedMsgId === item.msg.id
                                         }"
                                         :data-message-id="item.msg.id"
                                         style="position:relative">

                                        <!-- Avatar -->
                                        <div class="chat-avatar"
                                             :class="'chat-avatar-' + (item.msg.user_id % 8)"
                                             x-show="item.msg.type !== 'system'">
                                            <span x-text="item.msg.initials || initials(item.msg.user_name)"></span>
                                        </div>

                                        <div class="chat-message-body" x-show="item.msg.type !== 'system'">
                                            <!-- Header -->
                                            <div class="chat-message-header">
                                                <span class="chat-message-author" x-text="item.msg.user_name"></span>
                                                <span class="chat-message-time" x-text="formatTime(item.msg.created_at)"></span>
                                                <span class="chat-edited-marker" x-show="item.msg.is_edited">(edited)</span>
                                            </div>
                                            <!-- Reply context -->
                                            <div class="chat-reply-context" x-show="item.msg.reply_to_id" x-cloak>
                                                <span class="chat-reply-name" x-text="item.msg.reply_to_user_name || 'Unknown'"></span>
                                                <span class="chat-reply-text" x-text="truncate(item.msg.reply_to_message || '[deleted]', 80)"></span>
                                            </div>
                                            <!-- Text -->
                                            <div class="chat-message-text"
                                                 x-show="!item.msg.is_deleted"
                                                 x-html="formatMessageHtml(item.msg.message)"></div>
                                            <div class="chat-message-deleted" x-show="item.msg.is_deleted">[Message deleted]</div>
                                            <!-- Attachments -->
                                            <template x-for="att in item.msg.attachments" :key="att.id">
                                                <div class="chat-attachment-card">
                                                    <div class="chat-attachment-icon" x-text="attachmentIcon(att.attachment_type || att.type)"></div>
                                                    <div class="chat-attachment-body">
                                                        <div class="chat-attachment-title" x-text="att.preview_title || att.file_name || (att.attachment_type || att.type)"></div>
                                                        <div class="chat-attachment-sub" x-text="att.preview_subtitle || ''"></div>
                                                        <span class="badge chat-attachment-badge"
                                                              :class="att.preview_badge_class || 'badge-neutral'"
                                                              x-show="att.preview_badge"
                                                              x-text="att.preview_badge"></span>
                                                    </div>
                                                    <a :href="att.preview_url || attachmentUrl(att)"
                                                       class="chat-attachment-link btn btn-secondary btn-xs"
                                                       target="_blank"
                                                       x-show="att.preview_url || att.entity_id">View →</a>
                                                </div>
                                            </template>
                                            <!-- Reactions -->
                                            <div class="chat-reactions" x-show="item.msg.reactions && item.msg.reactions.length > 0">
                                                <template x-for="r in item.msg.reactions" :key="r.emoji">
                                                    <button type="button"
                                                            class="chat-reaction-btn"
                                                            :class="{ 'mine': r.mine }"
                                                            @click="toggleReaction(item.msg.id, r.emoji)"
                                                            :title="r.users?.join(', ')">
                                                        <span x-text="r.emoji"></span>
                                                        <span x-text="r.count"></span>
                                                    </button>
                                                </template>
                                                <button type="button" class="chat-reaction-add"
                                                        @click.stop="showEmojiFor(item.msg.id)">+😊</button>
                                            </div>
                                        </div>

                                        <!-- System message -->
                                        <div class="chat-message-system-text"
                                             x-show="item.msg.type === 'system'"
                                             x-text="'— ' + item.msg.message + ' —'"></div>

                                        <!-- Message actions (hover) -->
                                        <div class="chat-message-actions" x-show="!item.msg.is_deleted && item.msg.type !== 'system'">
                                            <button type="button" class="chat-action-btn"
                                                    @click.stop="showEmojiFor(item.msg.id)"
                                                    title="React">😊</button>
                                            <button type="button" class="chat-action-btn"
                                                    @click.stop="setReply(item.msg)"
                                                    title="Reply">↩</button>
                                            <button type="button" class="chat-action-btn"
                                                    @click.stop="editMsg(item.msg)"
                                                    x-show="item.msg.user_id == <?= $currentUserId ?>"
                                                    title="Edit">✏️</button>
                                            <button type="button" class="chat-action-btn chat-action-btn--danger"
                                                    @click.stop="deleteMsg(item.msg.id)"
                                                    x-show="item.msg.user_id == <?= $currentUserId ?> || isChannelAdminOrOwner"
                                                    title="Delete">🗑️</button>
                                        </div>

                                        <!-- Emoji picker (per message) -->
                                        <div class="chat-emoji-picker"
                                             x-show="emojiPickerForMsg === item.msg.id"
                                             x-cloak
                                             @click.stop
                                             style="position:absolute;bottom:calc(100% + 4px);right:8px">
                                            <template x-for="em in commonEmojis" :key="em">
                                                <button type="button" class="chat-emoji-btn"
                                                        @click="toggleReaction(item.msg.id, em); emojiPickerForMsg=null"
                                                        x-text="em"></button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                                </div><!-- /chat-msg-wrapper -->
                            </template>

                            <!-- Empty channel -->
                            <div class="chat-channel-empty" x-show="!loadingMessages && messages.length === 0" x-cloak>
                                No messages yet. Start the conversation!
                            </div>

                        </div><!-- /chat-messages-area -->
                    </div><!-- /chat-messages-wrap -->

                    <!-- Compose area -->
                    <div class="chat-compose" style="position:relative">

                        <!-- Reply banner -->
                        <div class="chat-reply-banner" x-show="replyingTo" x-cloak>
                            <span>↩ Replying to <strong x-text="replyingTo?.user_name"></strong></span>
                            <span class="chat-reply-preview" x-text="truncate(replyingTo?.message || '', 60)"></span>
                            <button type="button" class="chat-reply-cancel" @click="replyingTo = null">✕</button>
                        </div>

                        <!-- Edit banner -->
                        <div class="chat-reply-banner chat-reply-banner--edit" x-show="editingMessage" x-cloak>
                            <span>✏️ Editing message</span>
                            <button type="button" class="chat-reply-cancel" @click="cancelEdit()">✕ Cancel</button>
                        </div>

                        <!-- Pending attachments -->
                        <div class="chat-pending-attachments" x-show="pendingAttachments.length > 0" x-cloak>
                            <template x-for="(att, i) in pendingAttachments" :key="i">
                                <div class="chat-pending-att">
                                    <span class="chat-pending-att-icon" x-text="attachmentIcon(att.attachment_type || att.type)"></span>
                                    <span class="chat-pending-att-name" x-text="att.preview_title || att.file_name || att.type"></span>
                                    <button type="button" class="chat-pending-att-remove" @click="removePendingAttachment(i)">✕</button>
                                </div>
                            </template>
                        </div>

                        <!-- @mention dropdown -->
                        <div class="chat-mention-dropdown" x-show="showMentions && mentionResults.length > 0" x-cloak>
                            <template x-for="(u, i) in mentionResults" :key="u.id || u.user_id">
                                <button type="button"
                                        class="chat-mention-item"
                                        :class="{ 'is-selected': mentionIndex === i }"
                                        @click.prevent="insertMention(u)">
                                    <span class="chat-mention-avatar" x-text="initials(u.name)"></span>
                                    <span x-text="u.name"></span>
                                </button>
                            </template>
                        </div>

                        <!-- /record picker -->
                        <div class="chat-record-picker" x-show="showRecordPicker" x-cloak @click.stop>
                            <div class="chat-record-types" x-show="!recordPickerType">
                                <button class="chat-record-type-btn" @click="setRecordType('invoice')">📄 Invoice</button>
                                <button class="chat-record-type-btn" @click="setRecordType('lease')">📋 Lease</button>
                                <button class="chat-record-type-btn" @click="setRecordType('customer')">👤 Customer</button>
                                <button class="chat-record-type-btn" @click="setRecordType('payment')">💰 Payment</button>
                                <button class="chat-record-type-btn" @click="setRecordType('work_order')">🔧 Work Order</button>
                                <button class="chat-record-type-btn" @click="setRecordType('damage_claim')">⚠️ Damage Claim</button>
                                <button class="chat-record-type-btn" @click="setRecordType('reservation')">📅 Reservation</button>
                                <button class="chat-record-type-btn" @click="setRecordType('equipment')">🚛 Equipment</button>
                            </div>
                            <div class="chat-record-search" x-show="recordPickerType">
                                <div class="chat-record-search-header">
                                    <button class="btn-icon btn-sm" @click="recordPickerType = null; recordResults = []">← Back</button>
                                    <input type="text" class="chat-record-search-input"
                                           :placeholder="'Search ' + (recordPickerType || '') + 's…'"
                                           x-model="recordQuery"
                                           @input.debounce.200ms="searchRecords()"
                                           @keydown.arrow-up.prevent="recordIndex = Math.max(0, recordIndex - 1)"
                                           @keydown.arrow-down.prevent="recordIndex = Math.min(recordResults.length - 1, recordIndex + 1)"
                                           @keydown.enter.prevent="recordResults[recordIndex] && attachRecord(recordResults[recordIndex])"
                                           @keydown.escape="showRecordPicker = false"
                                           x-ref="recordSearchInput">
                                </div>
                                <div class="chat-record-results">
                                    <div class="chat-record-loading" x-show="recordLoading">Searching…</div>
                                    <template x-for="(r, i) in recordResults" :key="r.id">
                                        <button type="button"
                                                class="chat-record-result"
                                                :class="{ 'is-selected': recordIndex === i }"
                                                @click="attachRecord(r)">
                                            <span class="chat-record-result-title" x-text="r.title"></span>
                                            <span class="chat-record-result-sub" x-text="r.subtitle"></span>
                                        </button>
                                    </template>
                                    <div class="chat-record-empty"
                                         x-show="!recordLoading && recordResults.length === 0 && recordQuery.length > 0">
                                        No results found
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Compose input row -->
                        <div class="chat-compose-row">
                            <textarea
                                class="chat-compose-input"
                                :placeholder="'Message ' + (activeChannel.type === 'channel' ? '#' : '') + (activeChannel.type === 'direct' ? (activeChannel.other_user?.name || activeChannel.name) : (activeChannel.customer_company_name || activeChannel.name)) + '…'"
                                x-model="composing"
                                x-ref="composeInput"
                                @keydown="handleComposeKeydown($event)"
                                @input="handleComposeInput(); autoResizeTextarea($event)"
                                rows="1"></textarea>
                            <div class="chat-compose-actions">
                                <button type="button" class="btn-icon" title="Attach file" @click="$refs.fileInput.click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
                                </button>
                                <button type="button"
                                        class="btn btn-primary btn-sm chat-send-btn"
                                        @click="send()"
                                        :disabled="sending || (!composing.trim() && pendingAttachments.length === 0)">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px"><path d="M3.478 2.404a.75.75 0 0 0-.926.941l2.432 7.905H13.5a.75.75 0 0 1 0 1.5H4.984l-2.432 7.905a.75.75 0 0 0 .926.94 60.519 60.519 0 0 0 18.445-8.986.75.75 0 0 0 0-1.218A60.517 60.517 0 0 0 3.478 2.404Z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="chat-compose-hint">
                            <kbd>Enter</kbd> send · <kbd>Shift+Enter</kbd> newline · <kbd>@</kbd> mention · <kbd>/</kbd> attach record · <kbd>↑</kbd> edit last · <kbd>Esc</kbd> cancel
                        </div>
                        <input type="file" x-ref="fileInput" style="display:none"
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.xlsx,.xls,.docx,.doc"
                               @change="handleFileUpload($event)">
                    </div><!-- /chat-compose -->

                </div><!-- /chat-channel-view -->
            </template>

        </div><!-- /chat-main -->

        <!-- ── RIGHT PANEL ────────────────────────────────────────────── -->
        <div class="chat-right-panel" x-show="showRightPanel && activeChannel" x-cloak>
            <div class="chat-panel-header">
                <span x-text="activeChannel?.type === 'customer' ? 'Customer Info' : 'Members'"></span>
                <button type="button" class="btn-icon" @click="showRightPanel = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Members section (channels + DMs) -->
            <template x-if="activeChannel && activeChannel.type !== 'customer'">
                <div>
                    <div class="chat-panel-section">
                        <div class="chat-panel-section-title" x-text="'Members (' + channelMembers.length + ')'"></div>
                        <template x-for="m in channelMembers" :key="m.id">
                            <div class="chat-panel-member">
                                <div class="chat-panel-avatar" x-text="m.initials || initials(m.name)"></div>
                                <span class="chat-panel-member-name" x-text="m.name"></span>
                                <span class="badge chat-panel-member-role"
                                      :class="{ 'badge-info': m.role === 'owner', 'badge-neutral': m.role === 'member', 'badge-warning': m.role === 'admin' }"
                                      x-text="m.role"></span>
                            </div>
                        </template>
                        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;width:100%"
                                x-show="activeChannel && activeChannel.type === 'channel'"
                                @click="openAddMembers()">+ Add Members</button>
                    </div>

                    <!-- Channel settings (owner/admin only) -->
                    <div class="chat-panel-section" x-show="isChannelAdminOrOwner && activeChannel?.type === 'channel'" x-cloak>
                        <div class="chat-panel-section-title">Channel Settings</div>
                        <div class="form-group" style="margin-bottom:8px">
                            <label class="form-label" style="font-size:12px">Name</label>
                            <input type="text" class="form-control form-control-sm" x-model="editChannelName">
                        </div>
                        <div class="form-group" style="margin-bottom:8px">
                            <label class="form-label" style="font-size:12px">Description</label>
                            <input type="text" class="form-control form-control-sm" x-model="editChannelDesc">
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button type="button" class="btn btn-primary btn-sm" @click="saveChannelSettings()">Save</button>
                            <button type="button" class="btn btn-danger btn-sm" @click="archiveChannel()" style="margin-left:auto">Archive</button>
                        </div>
                    </div>

                    <div class="chat-panel-section">
                        <button type="button" class="btn btn-secondary btn-sm" style="width:100%" @click="leaveChannel()" x-show="activeChannel?.type === 'channel'" x-cloak>Leave Channel</button>
                    </div>
                </div>
            </template>

            <!-- Customer info section -->
            <template x-if="activeChannel && activeChannel.type === 'customer'">
                <div>
                    <div class="chat-panel-section">
                        <div class="chat-panel-section-title">Customer</div>
                        <p style="font-size:14px;font-weight:600;color:var(--text-primary);margin:0" x-text="activeChannel.customer_company_name || activeChannel.name"></p>
                        <p style="font-size:12px;color:var(--text-secondary);margin:4px 0 8px" x-text="activeChannel.description"></p>
                        <a :href="'/fleetforge/customers/show?id=' + activeChannel.customer_id"
                           class="btn btn-secondary btn-sm" x-show="activeChannel.customer_id">
                           View Customer →
                        </a>
                    </div>
                    <div class="chat-panel-section">
                        <div class="chat-panel-section-title">Staff in Conversation</div>
                        <template x-for="m in channelMembers" :key="m.id">
                            <div class="chat-panel-member" x-show="m.member_type === 'staff' || !m.portal_user_id">
                                <div class="chat-panel-avatar" x-text="m.initials || initials(m.name)"></div>
                                <span class="chat-panel-member-name" x-text="m.name"></span>
                                <span class="badge badge-neutral chat-panel-member-role" x-text="m.role"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

        </div><!-- /chat-right-panel -->

    </div><!-- /chat-layout -->

    <!-- ══ MODALS ══════════════════════════════════════════════════════ -->

    <!-- Create Channel -->
    <div class="modal" x-show="showCreateChannel" x-cloak @click.self="showCreateChannel = false">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3>Create New Channel</h3>
                <button type="button" class="btn-icon" @click="showCreateChannel = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Channel Name *</label>
                    <input type="text" class="form-control" x-model="newChannel.name"
                           placeholder="e.g. project-updates" maxlength="100"
                           @input="newChannel.name = $event.target.value.toLowerCase().replace(/[^a-z0-9-]/g,'-').replace(/--+/g,'-')">
                    <p class="form-hint">Lowercase letters, numbers, and hyphens only</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" x-model="newChannel.description" placeholder="What is this channel about?" maxlength="500">
                </div>
                <div class="form-group">
                    <label class="form-label">Privacy</label>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="pub" name="privacy" :value="false" x-model="newChannel.is_private">
                        <label class="form-check-label" for="pub">Public — anyone can join</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="priv" name="privacy" :value="true" x-model="newChannel.is_private">
                        <label class="form-check-label" for="priv">Private — invite only</label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Add Members</label>
                    <input type="text" class="form-control" x-model="newChannelMemberSearch"
                           placeholder="Search team members…"
                           @input.debounce.200ms="searchNewChannelMembers()">
                    <div style="margin-top:6px;max-height:150px;overflow-y:auto">
                        <template x-for="u in newChannelMemberResults" :key="u.id">
                            <div class="user-search-result" @click="addNewChannelMember(u)">
                                <span x-text="u.name"></span>
                                <span style="font-size:12px;color:var(--text-muted);margin-left:auto" x-text="u.role_name || ''"></span>
                            </div>
                        </template>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
                        <template x-for="u in newChannel.members" :key="u.id">
                            <span class="user-chip">
                                <span x-text="u.name"></span>
                                <button type="button" class="user-chip-remove" @click="removeNewChannelMember(u.id)">×</button>
                            </span>
                        </template>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="showCreateChannel = false">Cancel</button>
                <button type="button" class="btn btn-primary" @click="createChannel()" :disabled="!newChannel.name.trim()">Create Channel</button>
            </div>
        </div>
    </div>

    <!-- New Direct Message -->
    <div class="modal" x-show="showNewDM" x-cloak @click.self="showNewDM = false">
        <div class="modal-box modal-sm">
            <div class="modal-header">
                <h3>New Direct Message</h3>
                <button type="button" class="btn-icon" @click="showNewDM = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Search team members</label>
                    <input type="text" class="form-control" x-model="dmSearch"
                           placeholder="Type a name…"
                           @input.debounce.200ms="searchDMUsers()"
                           x-ref="dmSearchInput">
                </div>
                <div style="max-height:250px;overflow-y:auto">
                    <template x-for="u in dmResults" :key="u.id">
                        <div class="user-search-result" @click="startDM(u.id)">
                            <div class="chat-panel-avatar" style="width:32px;height:32px;font-size:12px;flex-shrink:0" x-text="initials(u.name)"></div>
                            <div>
                                <div style="font-size:14px;font-weight:600;color:var(--text-primary)" x-text="u.name"></div>
                                <div style="font-size:12px;color:var(--text-muted)" x-text="u.role_name || ''"></div>
                            </div>
                        </div>
                    </template>
                    <div x-show="!dmResults.length && dmSearch.length > 0" style="padding:12px;text-align:center;color:var(--text-muted);font-size:13px">No users found</div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Customer Conversation -->
    <div class="modal" x-show="showCustomerConvo" x-cloak @click.self="showCustomerConvo = false">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3>Start Customer Conversation</h3>
                <button type="button" class="btn-icon" @click="showCustomerConvo = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Customer *</label>
                    <input type="text" class="form-control" x-model="customerSearch"
                           placeholder="Search customers…"
                           @input.debounce.200ms="searchCustomers()">
                    <div style="max-height:150px;overflow-y:auto;margin-top:4px" x-show="customerResults.length > 0">
                        <template x-for="c in customerResults" :key="c.id">
                            <div class="user-search-result" @click="selectCustomer(c)">
                                <div>
                                    <div style="font-size:14px;font-weight:600" x-text="c.company_name"></div>
                                    <div style="font-size:12px;color:var(--text-muted)" x-text="c.contact_name"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div x-show="selectedCustomer" style="margin-top:6px;padding:8px 12px;background:var(--bg-muted);border-radius:6px;font-size:13px">
                        Selected: <strong x-text="selectedCustomer?.company_name"></strong>
                        <button type="button" style="margin-left:8px;color:var(--text-muted);background:none;border:none;cursor:pointer" @click="selectedCustomer=null;customerSearch=''">✕</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Subject *</label>
                    <input type="text" class="form-control" x-model="customerConvoSubject"
                           placeholder="Invoice query / Lease question…" maxlength="255">
                </div>
                <div class="form-group">
                    <label class="form-label">Initial Message (optional)</label>
                    <textarea class="form-control" x-model="customerConvoMessage" rows="3"
                              placeholder="Hi, we wanted to follow up on…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="showCustomerConvo = false">Cancel</button>
                <button type="button" class="btn btn-primary"
                        @click="startCustomerConvo()"
                        :disabled="!selectedCustomer || !customerConvoSubject.trim()">
                    Start Conversation
                </button>
            </div>
        </div>
    </div>

    <!-- Add Members to Channel -->
    <div class="modal" x-show="showAddMembers" x-cloak @click.self="showAddMembers = false">
        <div class="modal-box modal-md">
            <div class="modal-header">
                <h3 x-text="'Add Members to #' + (activeChannel?.name || '')"></h3>
                <button type="button" class="btn-icon" @click="showAddMembers = false">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="nav-icon"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control" x-model="addMemberSearch"
                       placeholder="Search team members…"
                       @input.debounce.200ms="searchAddMembers()">
                <div style="max-height:250px;overflow-y:auto;margin-top:8px">
                    <template x-for="u in addMemberResults" :key="u.id">
                        <div class="user-search-result"
                             @click="toggleAddMember(u)"
                             :style="addMemberSelected.find(s => s.id === u.id) ? 'background:var(--bg-selected)' : ''">
                            <div class="chat-panel-avatar" style="width:28px;height:28px;font-size:10px;flex-shrink:0" x-text="initials(u.name)"></div>
                            <div>
                                <div style="font-size:13px;font-weight:600" x-text="u.name"></div>
                                <div style="font-size:11px;color:var(--text-muted)" x-text="isExistingMember(u.id) ? 'Already a member' : 'Click to add'"></div>
                            </div>
                            <span x-show="addMemberSelected.find(s => s.id === u.id)" style="margin-left:auto;color:var(--color-success)">✓</span>
                        </div>
                    </template>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" @click="showAddMembers = false">Cancel</button>
                <button type="button" class="btn btn-primary"
                        @click="confirmAddMembers()"
                        :disabled="!addMemberSelected.length"
                        x-text="'Add ' + (addMemberSelected.length || '') + ' Member' + (addMemberSelected.length === 1 ? '' : 's')">
                </button>
            </div>
        </div>
    </div>

    <!-- Browse Channels -->
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
                       x-model="browseQuery">
                <div style="margin-top:12px;max-height:300px;overflow-y:auto">
                    <template x-for="ch in filteredBrowseChannels" :key="ch.id">
                        <div style="display:flex;align-items:center;gap:10px;padding:10px;border-radius:6px" :style="isMyChannel(ch.id) ? 'opacity:.6' : ''">
                            <div>
                                <div style="font-size:14px;font-weight:600;color:var(--text-primary)"># <span x-text="ch.name"></span></div>
                                <div style="font-size:12px;color:var(--text-muted)" x-text="ch.description || 'No description'"></div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-left:auto"
                                    x-show="!isMyChannel(ch.id)"
                                    @click="joinChannel(ch)">Join</button>
                            <span style="font-size:12px;color:var(--color-success);margin-left:auto" x-show="isMyChannel(ch.id)">✓ Joined</span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

</div><!-- /page-content -->

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
