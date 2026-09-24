/**
 * public/assets/js/chat.js
 *
 * S-CHAT-REBUILD — FF_ChatApp(): the one messaging UI.
 *   side 'staff'  → app/admin/chat/index.php  (Team | Customers list + thread)
 *   side 'portal' → app/portal/chat/index.php (the customer's single thread)
 *
 * Plain texting: bubbles, day separators, sender names only where they help,
 * Enter to send (Shift+Enter = new line). Records (lease / invoice / payment /
 * unit / …) attach through the paper-clip picker and render as LIVE cards the
 * server resolves per viewer (lib/Chat/RecordRefs.php) — the client only ever
 * sends {type, id}.
 *
 * Alpine notes (project traps):
 *   - init() is auto-called by Alpine; the markup must NOT add x-init="init()".
 *     _started guards a double call before the first await.
 *   - message display fields (_day, _name, _time, _right, …) are precomputed
 *     in decorate() — no per-row function calls inside x-for.
 *
 * Polling: open thread every 4s (after=lastId), inbox every 15s (staff),
 * paused while the tab is hidden, caught up on refocus. The staff page posts
 * 'ff-chat-unread' so the topbar badge updates instantly.
 *
 * Dependencies: FF_Api (app.js), FF_parseUtc (header.php / portal header.php)
 */
(function () {
    'use strict';

    const MAX_RECORDS = 5;

    function parseUtc(ts) {
        if (!ts) return null;
        if (window.FF_parseUtc) return window.FF_parseUtc(ts);
        return new Date(String(ts).replace(' ', 'T') + 'Z');
    }
    function sameDay(a, b) {
        return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }
    function dayLabel(d) {
        const today = new Date();
        const y = new Date(); y.setDate(today.getDate() - 1);
        if (sameDay(d, today)) return 'Today';
        if (sameDay(d, y)) return 'Yesterday';
        const opts = { weekday: 'short', month: 'short', day: 'numeric' };
        if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
        return d.toLocaleDateString([], opts);
    }
    function timeLabel(d) {
        return d ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '';
    }
    function listStamp(ts) {
        const d = parseUtc(ts);
        if (!d) return '';
        const now = new Date();
        if (sameDay(d, now)) return timeLabel(d);
        const diffDays = (now - d) / 86400000;
        if (diffDays < 6) return d.toLocaleDateString([], { weekday: 'short' });
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    function FF_ChatApp(cfg) {
        return {
            // ── config ───────────────────────────────────────────────
            side: cfg.side,                 // 'staff' | 'portal'
            api: cfg.api,                   // endpoint map
            canCustomers: !!cfg.canCustomers,
            companyName: cfg.companyName || '',
            icons: cfg.icons || {},         // record type → SVG (static, from PHP)

            // ── inbox (staff) ────────────────────────────────────────
            tab: cfg.initialTab || 'team',
            lists: { team: [], customers: [] },
            unread: { team: 0, customers: 0, total: 0 },
            search: '',
            loadingList: true,

            // ── thread ───────────────────────────────────────────────
            activeId: null,
            conv: null,
            messages: [],
            hasMore: false,
            loadingThread: false,
            loadingEarlier: false,
            attachTypes: [],
            mobileThread: false,

            // ── composer ─────────────────────────────────────────────
            draft: '',
            chips: [],
            sending: false,
            error: '',
            confirmUnsend: null,
            picker: { open: false, type: '', q: '', results: [], loading: false },

            // ── delete chat / leave group (staff) ────────────────────
            // For me only: the other side keeps every message (S-CHAT-DELETE).
            async deleteChat() {
                if (!this.conv || !this.activeId) return;
                const c = this.conv;
                const group = c.kind === 'group';
                const who = c.kind === 'customer'
                    ? c.title + ' and your teammates keep the conversation.'
                    : c.title + ' keeps their copy.';
                const ok = await FF_Confirm.ask({
                    title: group ? 'Leave ' + c.title + '?' : 'Delete this chat?',
                    message: group
                        ? 'You will stop getting its messages and it is removed from your Messages. The others keep the conversation.'
                        : 'It is removed from your Messages only — ' + who + ' If anyone writes again, it comes back with just the new messages.',
                    confirmLabel: group ? 'Leave group' : 'Delete chat',
                    dangerMode: true,
                });
                if (!ok) return;
                const id = this.activeId;
                const res = await FF_Api.post(FF_Api.url(this.api.delete), { conversation_id: id }, { quiet: true }).catch(() => null);
                if (!res || !res.success) { this.error = res?.error?.message || 'Couldn\'t delete this chat. Try again.'; return; }
                for (const key of ['team', 'customers']) this.lists[key] = this.lists[key].filter(x => x.id !== id);
                this.recount();
                this.activeId = null;
                this.conv = null;
                this.messages = [];
                this.chips = [];
                this.draft = '';
                this.mobileThread = false;
                try { history.replaceState(null, '', location.pathname); } catch (e) {}
                if (window.FF_Toast) FF_Toast.success(group ? 'Left the group' : 'Chat deleted', group ? '' : 'Removed from your Messages.');
            },

            // ── new conversation (staff) ─────────────────────────────
            newer: { open: false, mode: 'direct', q: '', staff: [], customers: [], title: '', ids: [], busy: false, error: '' },

            _started: false,
            _threadTimer: null,
            _listTimer: null,
            _pickerSeq: 0,
            _peopleSeq: 0,
            _pickerDebounce: null,
            _peopleDebounce: null,

            async init() {
                if (this._started) return;
                this._started = true;
                this._root = this.$el;

                if (this.side === 'portal') {
                    await this.loadThread();
                    await this.applyDeepLink(cfg.deepLink || {});
                } else {
                    await this.loadList();
                    await this.applyDeepLink(cfg.deepLink || {});
                }

                this._threadTimer = setInterval(() => { if (!document.hidden) this.poll(); }, 4000);
                if (this.side === 'staff') {
                    this._listTimer = setInterval(() => { if (!document.hidden) this.loadList(true); }, 15000);
                }
                this._onVis = () => { if (!document.hidden) { this.poll(); if (this.side === 'staff') this.loadList(true); } };
                document.addEventListener('visibilitychange', this._onVis);
                this.$el.addEventListener('alpine:destroyed', () => {
                    clearInterval(this._threadTimer);
                    clearInterval(this._listTimer);
                    document.removeEventListener('visibilitychange', this._onVis);
                });
            },

            // Methods can be invoked from elements inside x-if blocks (the New
            // dialog) that are removed mid-call; Alpine binds $refs/$nextTick to
            // the CALLING element, so after removal they silently no-op. Resolve
            // refs from the component root and defer with a timer instead.
            _ref(name) {
                return this._root ? this._root.querySelector('[x-ref="' + name + '"]') : null;
            },
            _after(fn) {
                setTimeout(fn, 0);
            },

            // ── inbox ────────────────────────────────────────────────
            // Methods, not getters: x-show/x-for bound to a getter can go stale (project trap).
            visibleList() {
                const items = this.lists[this.tab] || [];
                const q = this.search.trim().toLowerCase();
                return q ? items.filter(i => i.title.toLowerCase().includes(q) || (i.preview || '').toLowerCase().includes(q)) : items;
            },

            async loadList(quiet) {
                try {
                    const res = await FF_Api.get(FF_Api.url(this.api.list));
                    if (!res || !res.success) return;
                    const stamp = (i) => Object.assign(i, { _when: listStamp(i.last_at) });
                    // The thread I'm reading has nothing unread, whatever the poll raced.
                    const clearActive = (i) => (i.id === this.activeId ? Object.assign(i, { unread: 0 }) : i);
                    this.lists = {
                        team: (res.data.team || []).map(stamp).map(clearActive),
                        customers: (res.data.customers || []).map(stamp).map(clearActive),
                    };
                    this.canCustomers = !!res.data.can_customers;
                    this.recount();
                } catch (e) {
                    if (!quiet) this.error = 'Couldn\'t load conversations. Check your connection.';
                } finally {
                    this.loadingList = false;
                }
            },

            recount() {
                const sum = (a) => a.reduce((n, i) => n + (i.unread || 0), 0);
                this.unread = { team: sum(this.lists.team), customers: sum(this.lists.customers) };
                this.unread.total = this.unread.team + this.unread.customers;
                window.dispatchEvent(new CustomEvent('ff-chat-unread', { detail: { total: this.unread.total } }));
            },

            async applyDeepLink(dl) {
                if (dl.attach) {
                    const [type, id] = String(dl.attach).split(':');
                    try {
                        const res = await FF_Api.get(FF_Api.url(this.api.records + '?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id)));
                        if (res && res.success && res.data.record) this.chips = [res.data.record];
                    } catch (e) { /* the chip just doesn't pre-fill */ }
                }
                if (dl.customer) return this.start({ kind: 'customer', customer_id: dl.customer });
                if (dl.to) return this.start({ kind: 'direct', user_id: dl.to });
                if (dl.c) return this.open(Number(dl.c));
            },

            // ── thread ───────────────────────────────────────────────
            async open(id) {
                if (!id) return;
                this.activeId = id;
                this.mobileThread = true;
                this.messages = [];
                this.conv = null;
                this.hasMore = false;
                this.error = '';
                this.confirmUnsend = null;
                this.picker.open = false;
                const item = [...this.lists.team, ...this.lists.customers].find(i => i.id === id);
                if (item) { this.tab = item.kind === 'customer' ? 'customers' : 'team'; item.unread = 0; this.recount(); }
                try { history.replaceState(null, '', location.pathname + '?c=' + id); } catch (e) {}
                await this.loadThread();
                this._after(() => this._ref('input') && this._ref('input').focus());
            },

            back() {
                this.mobileThread = false;
            },

            threadUrl(extra) {
                if (this.side === 'portal') return FF_Api.url(this.api.thread + (extra ? '?' + extra : ''));
                return FF_Api.url(this.api.thread + '?id=' + this.activeId + (extra ? '&' + extra : ''));
            },

            async loadThread() {
                this.loadingThread = true;
                try {
                    const res = await FF_Api.get(this.threadUrl(''));
                    if (!res || !res.success) {
                        this.error = res?.error?.message || 'Couldn\'t open this conversation.';
                        return;
                    }
                    if (this.side === 'portal') this.activeId = res.data.conversation_id;
                    this.conv = res.data.conversation || null;
                    this.attachTypes = res.data.attach_types || [];
                    this.messages = this.decorate(res.data.messages || []);
                    this.hasMore = !!res.data.has_more;
                    this._after(() => this.scrollToEnd());
                } catch (e) {
                    this.error = 'Couldn\'t open this conversation. Check your connection.';
                } finally {
                    this.loadingThread = false;
                }
            },

            async loadEarlier() {
                if (!this.messages.length || this.loadingEarlier) return;
                this.loadingEarlier = true;
                const box = this._ref('scroller');
                const before = box ? box.scrollHeight : 0;
                try {
                    const res = await FF_Api.get(this.threadUrl('before=' + this.messages[0].id));
                    if (res && res.success) {
                        this.messages = this.decorate([...(res.data.messages || []), ...this.messages]);
                        this.hasMore = !!res.data.has_more;
                        this._after(() => { if (box) box.scrollTop = box.scrollHeight - before; });
                    }
                } finally {
                    this.loadingEarlier = false;
                }
            },

            async poll() {
                if (this.loadingThread || this.sending) return;
                if (this.side === 'staff' && !this.activeId) return;
                if (this.side === 'portal' && !this.activeId) {
                    // No thread yet — only the team can start it; look again quietly.
                    const res = await FF_Api.get(this.threadUrl('')).catch(() => null);
                    if (res && res.success && res.data.conversation_id) {
                        this.activeId = res.data.conversation_id;
                        this.messages = this.decorate(res.data.messages || []);
                        this._after(() => this.scrollToEnd());
                    }
                    return;
                }
                const last = this.messages.length ? this.messages[this.messages.length - 1].id : 0;
                const convAtStart = this.activeId;
                try {
                    const res = await FF_Api.get(this.threadUrl('after=' + last));
                    if (!res || !res.success || convAtStart !== this.activeId) return;
                    this.merge(res.data.messages || []);
                } catch (e) { /* next tick retries */ }
            },

            merge(incoming) {
                const known = new Set(this.messages.map(m => m.id));
                const fresh = incoming.filter(m => !known.has(m.id));
                if (!fresh.length) return;
                const box = this._ref('scroller');
                const nearEnd = !box || (box.scrollHeight - box.scrollTop - box.clientHeight) < 120;
                this.messages = this.decorate([...this.messages, ...fresh]);
                if (this.side === 'staff') this.bumpList(fresh[fresh.length - 1]);
                if (nearEnd || fresh.some(m => m.mine)) this._after(() => this.scrollToEnd());
            },

            // Move the open conversation to the top of its list with the new preview.
            bumpList(msg) {
                // A brand-new thread isn't in the inbox yet — fetch it rather than wait for the poll.
                if (![...this.lists.team, ...this.lists.customers].some(x => x.id === this.activeId)) {
                    this.loadList(true);
                    return;
                }
                for (const key of ['team', 'customers']) {
                    const i = this.lists[key].findIndex(x => x.id === this.activeId);
                    if (i === -1) continue;
                    const item = this.lists[key].splice(i, 1)[0];
                    item.preview = msg.body ? msg.body.replace(/\s+/g, ' ').slice(0, 160) : (msg.records.length ? 'Shared ' + (msg.records.length === 1 ? 'a ' + msg.records[0].kind.toLowerCase() : msg.records.length + ' records') : item.preview);
                    item.last_at = msg.created_at;
                    item._when = listStamp(msg.created_at);
                    item.unread = 0;
                    this.lists[key].unshift(item);
                }
            },

            // Precompute everything the template shows per message.
            decorate(list) {
                const customerThread = this.side === 'portal' || (this.conv && this.conv.kind === 'customer');
                const mySide = this.side === 'portal' ? 'customer' : 'staff';
                let prev = null;
                return list.map((m) => {
                    const d = parseUtc(m.created_at);
                    const right = m.mine || (customerThread && m.side === mySide);
                    const senderKey = m.side + ':' + m.sender;
                    const newDay = !prev || !sameDay(prev._date, d);
                    const newCluster = newDay || !prev || prev._senderKey !== senderKey || (d - prev._date) > 5 * 60000;
                    const showName = newCluster && !m.mine && (customerThread || (this.conv && this.conv.kind === 'group'));
                    const out = Object.assign({}, m, {
                        _date: d,
                        _day: newDay ? dayLabel(d) : '',
                        _time: timeLabel(d),
                        _right: right,
                        _tone: m.mine ? 'mine' : (right ? 'ours' : 'theirs'),
                        _name: showName ? (m.sender + (m.sender_note ? ' · ' + m.sender_note : '')) : '',
                        _senderKey: senderKey,
                        _gapTop: newCluster && !newDay,
                    });
                    prev = out;
                    return out;
                });
            },

            scrollToEnd() {
                const box = this._ref('scroller');
                if (box) box.scrollTop = box.scrollHeight;
            },

            // ── composer ─────────────────────────────────────────────
            onKey(e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    this.send();
                }
            },

            autosize(el) {
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 160) + 'px';
            },

            canSend() {
                return !this.sending && (this.draft.trim() !== '' || this.chips.length > 0) && (this.side === 'portal' || !!this.activeId);
            },

            async send() {
                if (!this.canSend()) return;
                this.sending = true;
                this.error = '';
                const payload = { body: this.draft, records: this.chips.map(c => ({ type: c.type, id: c.id })) };
                if (this.side === 'staff') payload.conversation_id = this.activeId;
                try {
                    // quiet: show the reason inline under the composer, not as a guidance modal.
                    const res = await FF_Api.post(FF_Api.url(this.api.send), payload, { quiet: true });
                    // FF_Api.post RESOLVES on 422 — gate on success.
                    if (!res || !res.success) {
                        this.error = res?.error?.message || 'Message not sent. Try again.';
                        return;
                    }
                    if (this.side === 'portal' && res.data.conversation_id) this.activeId = res.data.conversation_id;
                    this.draft = '';
                    this.chips = [];
                    this.picker.open = false;
                    if (this._ref('input')) { this._ref('input').style.height = 'auto'; this._ref('input').focus(); }
                    if (res.data.message) this.merge([res.data.message]);
                } catch (e) {
                    this.error = 'Message not sent — check your connection and try again.';
                } finally {
                    this.sending = false;
                }
            },

            async unsend(m) {
                if (this.confirmUnsend !== m.id) { this.confirmUnsend = m.id; return; }
                this.confirmUnsend = null;
                const res = await FF_Api.post(FF_Api.url(this.api.unsend), { message_id: m.id }, { quiet: true }).catch(() => null);
                if (!res || !res.success) { this.error = res?.error?.message || 'Couldn\'t unsend that message.'; return; }
                this.messages = this.decorate(this.messages.map(x => x.id === m.id ? Object.assign({}, x, { body: '', records: [], deleted: true }) : x));
            },

            // ── attach picker ────────────────────────────────────────
            togglePicker() {
                this.picker.open = !this.picker.open;
                if (this.picker.open) {
                    if (!this.picker.type || !this.attachTypes.some(t => t.type === this.picker.type)) {
                        this.picker.type = this.attachTypes.length ? this.attachTypes[0].type : '';
                    }
                    this.picker.q = '';
                    this.searchRecords();
                    this._after(() => this._ref('pickerSearch') && this._ref('pickerSearch').focus());
                }
            },

            setPickerType(t) {
                this.picker.type = t;
                this.searchRecords();
                this._after(() => this._ref('pickerSearch') && this._ref('pickerSearch').focus());
            },

            queueSearch() {
                clearTimeout(this._pickerDebounce);
                this._pickerDebounce = setTimeout(() => this.searchRecords(), 220);
            },

            async searchRecords() {
                if (!this.picker.type) return;
                const seq = ++this._pickerSeq;
                this.picker.loading = true;
                let url = this.api.records + '?type=' + encodeURIComponent(this.picker.type) + '&q=' + encodeURIComponent(this.picker.q);
                if (this.side === 'staff') url += '&conversation_id=' + this.activeId;
                try {
                    const res = await FF_Api.get(FF_Api.url(url));
                    if (seq !== this._pickerSeq) return;   // a newer search already answered
                    const picked = new Set(this.chips.map(c => c.key));
                    this.picker.results = (res && res.success ? res.data.results : []).map(r => Object.assign(r, { _picked: picked.has(r.key) }));
                } catch (e) {
                    if (seq === this._pickerSeq) this.picker.results = [];
                } finally {
                    if (seq === this._pickerSeq) this.picker.loading = false;
                }
            },

            pick(card) {
                if (this.chips.some(c => c.key === card.key)) {
                    this.chips = this.chips.filter(c => c.key !== card.key);
                } else {
                    if (this.chips.length >= MAX_RECORDS) { this.error = 'Up to ' + MAX_RECORDS + ' records per message.'; return; }
                    this.chips = [...this.chips, card];
                }
                const picked = new Set(this.chips.map(c => c.key));
                this.picker.results = this.picker.results.map(r => Object.assign(r, { _picked: picked.has(r.key) }));
                this.error = '';
            },

            removeChip(key) {
                this.chips = this.chips.filter(c => c.key !== key);
            },

            // ── new conversation (staff) ─────────────────────────────
            openNew(mode) {
                this.newer = { open: true, mode: mode || (this.tab === 'customers' && this.canCustomers ? 'customer' : 'direct'), q: '', staff: [], customers: [], title: '', ids: [], busy: false, error: '' };
                this.findPeople();
                this._after(() => this._ref('newSearch') && this._ref('newSearch').focus());
            },

            queuePeople() {
                clearTimeout(this._peopleDebounce);
                this._peopleDebounce = setTimeout(() => this.findPeople(), 220);
            },

            async findPeople() {
                const seq = ++this._peopleSeq;
                try {
                    const res = await FF_Api.get(FF_Api.url(this.api.people + '?q=' + encodeURIComponent(this.newer.q)));
                    if (seq !== this._peopleSeq || !res || !res.success) return;
                    this.newer.staff = res.data.staff || [];
                    this.newer.customers = res.data.customers || [];
                } catch (e) { /* list stays as it was */ }
            },

            toggleMember(id) {
                this.newer.ids = this.newer.ids.includes(id) ? this.newer.ids.filter(x => x !== id) : [...this.newer.ids, id];
            },

            async start(body) {
                this.newer.busy = true;
                this.newer.error = '';
                try {
                    const res = await FF_Api.post(FF_Api.url(this.api.start), body, { quiet: true });
                    if (!res || !res.success) {
                        this.newer.error = res?.error?.message || 'Couldn\'t start that conversation.';
                        this.error = this.newer.open ? '' : this.newer.error;
                        return;
                    }
                    this.newer.open = false;
                    this.tab = body.kind === 'customer' ? 'customers' : 'team';
                    await this.loadList(true);
                    await this.open(res.data.id);
                } finally {
                    this.newer.busy = false;
                }
            },
        };
    }

    window.FF_ChatApp = FF_ChatApp;
})();
