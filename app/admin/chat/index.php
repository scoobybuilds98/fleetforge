<?php
/**
 * app/admin/chat/index.php
 *
 * S-CHAT-REBUILD — Messages. Simple texting, split two ways:
 *   Team      — direct messages and small named groups between staff.
 *   Customers — one thread per customer; their portal users see it in
 *               /portal/chat (shared inbox for staff with customers.view).
 * Any lease / invoice / payment / unit / … can be attached as a live card.
 *
 * Replaces the Discord-style Team Chat (channels, reactions, mentions,
 * replies), the separate Messenger inbox and the floating mini widget.
 *
 * Deep links:
 *   ?c=ID                   open a conversation
 *   ?customer=ID            open (or start) that customer's thread
 *   ?to=USER_ID             open (or start) a DM
 *   ?attach=invoice:42      pre-attach a record ("Send in chat" on record pages)
 *
 * Dependencies: includes/auth.php, includes/header.php, includes/footer.php,
 *               includes/partials/chat-thread.php, api/v1/chat/*,
 *               public/assets/js/chat.js (FF_ChatApp), public/assets/css/chat.css
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

$pageTitle = 'Messages';

$attach = (string) ($_GET['attach'] ?? '');
$deepLink = [
    'c'        => clean_int($_GET['c'] ?? null) ?: null,
    'customer' => clean_int($_GET['customer'] ?? null) ?: null,
    'to'       => clean_int($_GET['to'] ?? null) ?: null,
    'attach'   => preg_match('/^[a-z_]{3,20}:\d{1,10}$/', $attach) ? $attach : null,
];

require_once dirname(__DIR__, 3) . '/includes/header.php';
require_once FF_ROOT . '/includes/partials/chat-icons.php';

$chatConfig = [
    'side'         => 'staff',
    'canCustomers' => can('customers', 'view'),
    'initialTab'   => $deepLink['customer'] ? 'customers' : 'team',
    'deepLink'     => $deepLink,
    'icons'        => ff_chat_record_icons(),
    'api'          => [
        'list'    => '/api/v1/chat/conversations.php',
        'thread'  => '/api/v1/chat/conversation.php',
        'send'    => '/api/v1/chat/send.php',
        'unsend'  => '/api/v1/chat/unsend.php',
        'records' => '/api/v1/chat/records.php',
        'people'  => '/api/v1/chat/people.php',
        'start'   => '/api/v1/chat/start.php',
    ],
];
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/chat.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<div class="cx cx--staff" :class="{ 'is-thread': mobileThread }"
     x-data="FF_ChatApp(<?= e(json_encode($chatConfig, JSON_UNESCAPED_SLASHES)) ?>)">

    <!-- ── Inbox ─────────────────────────────────────────────────────── -->
    <aside class="cx-inbox" aria-label="Conversations">
        <div class="cx-inbox-head">
            <h1 class="cx-inbox-title">Messages</h1>
            <button type="button" class="cx-icon-btn cx-send cx-new-btn" @click="openNew()" title="New message" aria-label="New message">
                <?= ff_chat_icon('plus') ?>
            </button>
        </div>

        <div class="cx-seg" role="tablist" aria-label="Conversation type" x-show="canCustomers">
            <button type="button" role="tab" :class="{ 'is-on': tab === 'team' }" :aria-selected="tab === 'team'" @click="tab = 'team'">
                Team <span class="cx-count" x-show="unread.team" x-text="unread.team > 99 ? '99+' : unread.team"></span>
            </button>
            <button type="button" role="tab" :class="{ 'is-on': tab === 'customers' }" :aria-selected="tab === 'customers'" @click="tab = 'customers'">
                Customers <span class="cx-count" x-show="unread.customers" x-text="unread.customers > 99 ? '99+' : unread.customers"></span>
            </button>
        </div>

        <label class="cx-search">
            <?= ff_chat_icon('magnifying-glass') ?>
            <input type="search" x-model="search" :placeholder="tab === 'customers' ? 'Search customers' : 'Search team'" aria-label="Search conversations">
        </label>

        <div class="cx-list">
            <template x-for="c in visibleList()" :key="c.id">
                <button type="button" class="cx-row" :class="{ 'is-active': c.id === activeId, 'has-unread': c.unread > 0 }" @click="open(c.id)">
                    <span class="cx-avatar" :class="{ 'cx-avatar--customer': c.kind === 'customer', 'cx-avatar--group': c.kind === 'group' }">
                        <template x-if="c.kind === 'customer'"><?= ff_chat_icon('building-office') ?></template>
                        <template x-if="c.kind === 'group'"><?= ff_chat_icon('user-group') ?></template>
                        <template x-if="c.kind === 'direct'"><span x-text="c.initials"></span></template>
                    </span>
                    <span class="cx-row-main">
                        <span class="cx-row-top">
                            <span class="cx-row-title" x-text="c.title"></span>
                            <span class="cx-row-when" x-text="c._when"></span>
                        </span>
                        <span class="cx-row-bottom">
                            <span class="cx-row-preview" x-text="c.preview || 'No messages yet'"></span>
                            <span class="cx-count" x-show="c.unread" x-text="c.unread > 99 ? '99+' : c.unread"></span>
                        </span>
                    </span>
                </button>
            </template>

            <template x-if="!loadingList && !visibleList().length">
                <div class="cx-empty">
                    <template x-if="search"><span>No conversations match “<span x-text="search"></span>”.</span></template>
                    <template x-if="!search && tab === 'team'">
                        <div><strong>No team conversations yet</strong>Message a teammate or start a group — tap <b>+</b>.</div>
                    </template>
                    <template x-if="!search && tab === 'customers'">
                        <div><strong>No customer texts yet</strong>Start one with <b>+</b>, or use <b>Message customer</b> on a customer's page. Their portal users see it under Messages.</div>
                    </template>
                </div>
            </template>
            <template x-if="loadingList"><div class="cx-empty">Loading…</div></template>
        </div>
    </aside>

    <!-- ── Thread ────────────────────────────────────────────────────── -->
    <section class="cx-thread" aria-label="Conversation">
        <template x-if="!activeId">
            <div class="cx-blank">
                <?= ff_chat_icon('chat-bubble-left-right') ?>
                <strong>Pick a conversation</strong>
                <p x-show="!chips.length">Text a teammate, or a customer from the Customers tab. Tap the paper clip to share a lease, invoice, payment or unit right in the message.</p>
                <p x-show="chips.length" x-cloak>Choose who to send <b x-text="chips[0] && chips[0].title"></b> to — open a conversation on the left or start a new one.</p>
            </div>
        </template>

        <template x-if="activeId">
            <div style="display:contents">
                <header class="cx-thread-head">
                    <button type="button" class="cx-icon-btn cx-back" @click="back()" aria-label="Back to conversations"><?= ff_chat_icon('arrow-left') ?></button>
                    <span class="cx-avatar cx-avatar--sm" :class="{ 'cx-avatar--customer': conv && conv.kind === 'customer', 'cx-avatar--group': conv && conv.kind === 'group' }">
                        <template x-if="conv && conv.kind === 'customer'"><?= ff_chat_icon('building-office') ?></template>
                        <template x-if="conv && conv.kind === 'group'"><?= ff_chat_icon('user-group') ?></template>
                        <template x-if="conv && conv.kind === 'direct'"><span x-text="(conv.title || '').split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase()"></span></template>
                    </span>
                    <div class="cx-thread-meta">
                        <div class="cx-thread-title">
                            <template x-if="conv && conv.customer_url"><a :href="conv.customer_url" style="color:inherit" x-text="conv.title"></a></template>
                            <template x-if="conv && !conv.customer_url"><span x-text="conv ? conv.title : ''"></span></template>
                        </div>
                        <div class="cx-thread-sub" x-text="conv ? conv.subtitle : ''" :title="conv ? conv.subtitle : ''"></div>
                    </div>
                    <span class="cx-visible" x-show="conv && conv.kind === 'customer'" title="Everything in this thread is visible to the customer's portal users">Customer can see this</span>
                </header>

                <?php $cxPlaceholder = "conv && conv.kind === 'customer' ? 'Text ' + conv.title + '…' : 'Message'"; ?>
                <?php require FF_ROOT . '/includes/partials/chat-thread.php'; ?>
            </div>
        </template>
    </section>

    <!-- ── New conversation ──────────────────────────────────────────── -->
    <template x-if="newer.open">
        <div class="cx-modal-backdrop" @click.self="newer.open = false" @keydown.escape.window="newer.open = false">
            <div class="cx-modal" role="dialog" aria-modal="true" aria-labelledby="cx-new-title">
                <div class="cx-modal-head">
                    <h2 id="cx-new-title">New message</h2>
                    <button type="button" class="cx-icon-btn" @click="newer.open = false" aria-label="Close"><?= ff_chat_icon('x-mark') ?></button>
                </div>
                <div class="cx-seg" role="tablist">
                    <button type="button" role="tab" :class="{ 'is-on': newer.mode === 'direct' }" @click="newer.mode = 'direct'">Teammate</button>
                    <button type="button" role="tab" :class="{ 'is-on': newer.mode === 'group' }" @click="newer.mode = 'group'">Group</button>
                    <button type="button" role="tab" x-show="canCustomers" :class="{ 'is-on': newer.mode === 'customer' }" @click="newer.mode = 'customer'">Customer</button>
                </div>
                <label class="cx-search">
                    <?= ff_chat_icon('magnifying-glass') ?>
                    <input type="search" x-ref="newSearch" x-model="newer.q" @input="queuePeople()"
                           :placeholder="newer.mode === 'customer' ? 'Search customers' : 'Search people'" aria-label="Search">
                </label>
                <div class="cx-modal-error" x-show="newer.error" x-text="newer.error"></div>

                <div class="cx-modal-list">
                    <!-- Teammate: tap to open the DM -->
                    <template x-if="newer.mode === 'direct'">
                        <div>
                            <template x-for="p in newer.staff" :key="p.id">
                                <button type="button" class="cx-row" @click="start({ kind: 'direct', user_id: p.id })" :disabled="newer.busy">
                                    <span class="cx-avatar cx-avatar--sm" x-text="p.name.split(/\s+/).map(x => x[0]).slice(0, 2).join('').toUpperCase()"></span>
                                    <span class="cx-row-main"><span class="cx-row-title cx-block" x-text="p.name"></span><span class="cx-row-preview cx-block" x-text="p.role"></span></span>
                                </button>
                            </template>
                            <div class="cx-empty" x-show="!newer.staff.length">No teammates match.</div>
                        </div>
                    </template>
                    <!-- Group: tick 2+ people, name it -->
                    <template x-if="newer.mode === 'group'">
                        <div>
                            <template x-for="p in newer.staff" :key="p.id">
                                <button type="button" class="cx-row" @click="toggleMember(p.id)" :aria-pressed="newer.ids.includes(p.id)">
                                    <span class="cx-check" :class="{ 'is-on': newer.ids.includes(p.id) }"><template x-if="newer.ids.includes(p.id)"><?= ff_chat_icon('check') ?></template></span>
                                    <span class="cx-row-main"><span class="cx-row-title cx-block" x-text="p.name"></span><span class="cx-row-preview cx-block" x-text="p.role"></span></span>
                                </button>
                            </template>
                        </div>
                    </template>
                    <!-- Customer: tap to open/start their one thread -->
                    <template x-if="newer.mode === 'customer'">
                        <div>
                            <template x-for="c in newer.customers" :key="c.id">
                                <button type="button" class="cx-row" @click="start({ kind: 'customer', customer_id: c.id })" :disabled="newer.busy">
                                    <span class="cx-avatar cx-avatar--sm cx-avatar--customer"><?= ff_chat_icon('building-office') ?></span>
                                    <span class="cx-row-main">
                                        <span class="cx-row-title cx-block" x-text="c.name"></span>
                                        <span class="cx-row-preview cx-block"
                                              x-text="(c.conversation_id ? 'Open conversation · ' : '') + (c.portal_users ? c.portal_users + ' portal ' + (c.portal_users === 1 ? 'user' : 'users') : 'No portal users yet')"></span>
                                    </span>
                                </button>
                            </template>
                            <div class="cx-empty" x-show="!newer.customers.length">No customers match.</div>
                        </div>
                    </template>
                </div>

                <div class="cx-modal-foot" x-show="newer.mode === 'group'">
                    <input type="text" class="form-control" x-model="newer.title" maxlength="120" placeholder="Group name, e.g. Yard crew" aria-label="Group name">
                    <button type="button" class="btn btn-primary" :disabled="newer.busy || !newer.title.trim() || newer.ids.length < 2"
                            @click="start({ kind: 'group', title: newer.title, user_ids: newer.ids })"
                            x-text="newer.ids.length < 2 ? 'Pick 2+' : 'Create (' + (newer.ids.length + 1) + ')'"></button>
                </div>
            </div>
        </div>
    </template>
</div>

<script src="<?= asset_url('assets/js/chat.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
