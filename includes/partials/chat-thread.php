<?php
/**
 * includes/partials/chat-thread.php
 *
 * S-CHAT-REBUILD — the thread body shared by staff /chat and portal /portal/chat:
 * message list (day separators, bubbles, live record cards, unsend) and the
 * composer (record chips, paper-clip picker, auto-growing textarea).
 *
 * Rendered INSIDE an x-data="FF_ChatApp(…)" element (public/assets/js/chat.js).
 * Every per-message display field is precomputed in decorate(); the template
 * only reads properties. Record icons come from the `icons` map passed in the
 * component config (static SVG strings from public/assets/icons → x-html).
 *
 * Expects: $cxPlaceholder (composer placeholder expression, JS string)
 * Icons:   includes/partials/chat-icons.php (ff_chat_icon / ff_chat_record_icons)
 *
 * @session S-CHAT-REBUILD
 */

require_once FF_ROOT . '/includes/partials/chat-icons.php';
$cxPlaceholder = $cxPlaceholder ?? "'Message'";
?>
<div class="cx-scroll" x-ref="scroller" aria-live="polite">
    <div class="cx-earlier" x-show="hasMore" x-cloak>
        <button type="button" class="btn btn-secondary btn-sm" @click="loadEarlier()" :disabled="loadingEarlier"
                x-text="loadingEarlier ? 'Loading…' : 'Load earlier messages'"></button>
    </div>

    <template x-if="loadingThread && !messages.length">
        <div class="cx-empty">Loading…</div>
    </template>

    <template x-for="m in messages" :key="m.id">
        <div>
            <div class="cx-day" x-show="m._day"><span x-text="m._day"></span></div>
            <div class="cx-msg" :class="{ 'is-right': m._right, 'gap-top': m._gapTop, ['tone-' + m._tone]: true }">
                <div class="cx-name" x-show="m._name" x-text="m._name"></div>
                <div class="cx-bubble-row">
                    <div class="cx-bubble" :class="{ 'is-deleted': m.deleted, 'has-cards': !m.deleted && m.records.length }">
                        <template x-if="m.deleted"><span>Message unsent</span></template>
                        <template x-if="!m.deleted && m.body"><div class="cx-text" x-text="m.body"></div></template>
                        <template x-if="!m.deleted && m.records.length">
                            <div class="cx-cards">
                                <template x-for="r in m.records" :key="r.key">
                                    <a class="cx-card" :class="{ 'is-gone': !r.available }" :href="r.url || null"
                                       :aria-disabled="r.available ? null : 'true'" @click="if (!r.url) $event.preventDefault()">
                                        <span class="cx-card-icon" x-html="icons[r.type] || ''"></span>
                                        <span class="cx-card-main">
                                            <span class="cx-card-kind" x-text="r.kind"></span>
                                            <span class="cx-card-title" x-text="r.title"></span>
                                            <span class="cx-card-sub" x-show="r.subtitle" x-text="r.subtitle"></span>
                                        </span>
                                        <span class="cx-card-side" x-show="r.status || r.amount">
                                            <span class="cx-pill" :class="'tone-' + r.tone" x-show="r.status" x-text="r.status"></span>
                                            <span class="cx-card-amount" x-show="r.amount" x-text="r.amount"></span>
                                        </span>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                    <span class="cx-time" x-text="m._time"></span>
                    <template x-if="m.mine && !m.deleted">
                        <button type="button" class="cx-unsend" :class="{ 'is-confirm': confirmUnsend === m.id }"
                                @click="unsend(m)" @blur="if (confirmUnsend === m.id) confirmUnsend = null"
                                x-text="confirmUnsend === m.id ? 'Unsend?' : 'Unsend'"></button>
                    </template>
                </div>
                <!-- S-CHAT-SEEN: under the newest message only, when it's on my side -->
                <div class="cx-receipt" :class="{ 'is-seen': receipt && receipt.seen }"
                     x-show="receipt && receipt.message_id === m.id" x-text="receipt ? receipt.text : ''"></div>
            </div>
        </div>
    </template>
</div>

<div class="cx-compose" @keydown.escape="picker.open = false">
    <!-- Attach picker: record-type tabs → search → tap to add/remove -->
    <div class="cx-picker" x-show="picker.open" x-cloak x-transition.opacity.duration.100ms @click.outside="if (!$event.target.closest('.cx-attach')) picker.open = false">
        <div class="cx-picker-tabs" role="tablist" aria-label="Record type">
            <template x-for="t in attachTypes" :key="t.type">
                <button type="button" role="tab" :class="{ 'is-on': picker.type === t.type }" :aria-selected="picker.type === t.type"
                        @click="setPickerType(t.type)" x-text="t.label"></button>
            </template>
        </div>
        <label class="cx-search">
            <?= ff_chat_icon('magnifying-glass') ?>
            <input type="search" x-ref="pickerSearch" x-model="picker.q" @input="queueSearch()"
                   placeholder="Search by number, name or unit" aria-label="Search records">
        </label>
        <div class="cx-picker-list">
            <template x-for="r in picker.results" :key="r.key">
                <button type="button" class="cx-pick" :class="{ 'is-picked': r._picked }" @click="pick(r)" :aria-pressed="r._picked">
                    <span class="cx-card">
                        <span class="cx-card-icon" x-html="icons[r.type] || ''"></span>
                        <span class="cx-card-main">
                            <span class="cx-card-title" x-text="r.title"></span>
                            <span class="cx-card-sub" x-show="r.subtitle" x-text="r.subtitle"></span>
                        </span>
                        <span class="cx-card-side">
                            <span class="cx-pill" :class="'tone-' + r.tone" x-show="r.status" x-text="r.status"></span>
                            <span class="cx-card-amount" x-show="r.amount" x-text="r.amount"></span>
                        </span>
                    </span>
                </button>
            </template>
            <div class="cx-picker-note" x-show="!picker.loading && !picker.results.length"
                 x-text="picker.q ? 'Nothing matches “' + picker.q + '”.' : 'Nothing to attach here yet.'"></div>
            <div class="cx-picker-note" x-show="picker.loading && !picker.results.length">Searching…</div>
        </div>
    </div>

    <div class="cx-error" x-show="error" x-cloak x-text="error" role="alert"></div>

    <div class="cx-chips" x-show="chips.length" x-cloak>
        <template x-for="c in chips" :key="c.key">
            <div class="cx-chip">
                <span class="cx-card">
                    <span class="cx-card-icon" x-html="icons[c.type] || ''"></span>
                    <span class="cx-card-main">
                        <span class="cx-card-kind" x-text="c.kind"></span>
                        <span class="cx-card-title" x-text="c.title"></span>
                    </span>
                </span>
                <button type="button" class="cx-chip-x" @click="removeChip(c.key)" :aria-label="'Remove ' + c.title"><?= ff_chat_icon('x-mark') ?></button>
            </div>
        </template>
    </div>

    <div class="cx-bar">
        <button type="button" class="cx-icon-btn cx-attach" :class="{ 'is-on': picker.open }" @click="togglePicker()"
                x-show="attachTypes.length" :aria-expanded="picker.open" aria-label="Attach a record" title="Attach a lease, invoice, payment…">
            <?= ff_chat_icon('paper-clip') ?>
        </button>
        <textarea x-ref="input" rows="1" x-model="draft" @keydown="onKey($event)" @input="autosize($el)"
                  :placeholder="<?= e($cxPlaceholder) ?>" aria-label="Message" maxlength="4000"></textarea>
        <button type="button" class="cx-icon-btn cx-send" @click="send()" :disabled="!canSend()" aria-label="Send">
            <?= ff_chat_icon('paper-airplane') ?>
        </button>
    </div>
</div>
