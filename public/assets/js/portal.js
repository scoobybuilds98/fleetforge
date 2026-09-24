/* ==========================================================================
   FleetForge — Customer Portal behaviour (S-PORTAL-REDESIGN)
   public/assets/js/portal.js

   Loaded by app/portal/includes/footer.php AFTER app.js and BEFORE the
   deferred Alpine bundle, so the stores/components below are registered on
   `alpine:init` before Alpine walks the page.

   What lives here:
     PT.get / PT.post      fetch wrappers — same-origin, CSRF header, and a
                           401 (session ended) sends the customer to sign-in
                           instead of surfacing "network error"
     PT.money / PT.date    display formatting that matches the PHP side
     PT.copy               clipboard + toast
     $store.checkout       the Pay drawer: pay one or many invoices through
                           QuickBooks (each opens its own secure page in a new
                           tab) and tick them off live as payments land
     $store.notice         "Tell us about a payment" (remittance) form
     $store.statement      statement-of-account date picker → PDF
     PT_Shell()            root: mobile nav, topbar scroll edge, ⌘K
     PT_Search()           command palette over invoices/leases/units/requests
     PT_InvoiceList()      invoices page: tabs, search, dates, select → pay/zip

   Rules: never build a payment URL client-side (the server decides whether
   an invoice can be paid and returns the link); money math stays on the
   server — the client only sums already-formatted balances for display
   using integer cents.
   ========================================================================== */

(function () {
    'use strict';

    const BASE = (window.FF_BASE_PATH || '').replace(/\/$/, '');
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const CFG  = window.PT_CONFIG || {};

    function url(path) {
        if (/^https?:\/\//.test(path)) return path;
        return BASE + '/' + String(path).replace(/^\//, '');
    }

    async function request(method, path, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = CSRF;
            opts.body = JSON.stringify(body || {});
        }
        let res;
        try {
            res = await fetch(url(path), opts);
        } catch (e) {
            return { success: false, error: { code: 'NETWORK', message: 'We couldn’t reach the server. Check your connection and try again.' } };
        }
        if (res.status === 401) {
            window.location.href = url('portal/auth/login');
            return { success: false, error: { code: 'UNAUTHORIZED', message: 'Please sign in again.' } };
        }
        try {
            return await res.json();
        } catch (e) {
            return { success: false, error: { code: 'BAD_RESPONSE', message: 'Something went wrong. Please try again.' } };
        }
    }

    function toCents(v) {
        const s = String(v ?? '0').replace(/[^0-9.\-]/g, '');
        if (s === '' || s === '-' || s === '.') return 0;
        const neg = s.startsWith('-');
        const [w, f = ''] = s.replace('-', '').split('.');
        const cents = (parseInt(w || '0', 10) * 100) + parseInt((f + '00').slice(0, 2), 10);
        return neg ? -cents : cents;
    }

    /** Format integer cents as $1,234.56 (+ currency code when not CAD). */
    function moneyFromCents(cents, currency) {
        const neg = cents < 0;
        const abs = Math.abs(Math.round(cents));
        const whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        const out = (neg ? '-$' : '$') + whole + '.' + String(abs % 100).padStart(2, '0');
        return currency && currency !== 'CAD' ? out + ' ' + currency : out;
    }

    function money(v, currency) {
        return moneyFromCents(toCents(v), currency);
    }

    function date(ymd) {
        if (!ymd) return '—';
        const d = new Date(String(ymd).slice(0, 10) + 'T12:00:00Z');
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
    }

    function toast(type, title, msg) {
        if (window.FF_Toast) window.FF_Toast.show(type, title, msg || '');
    }

    async function copy(text, label) {
        try {
            await navigator.clipboard.writeText(String(text));
            toast('success', (label || 'Copied') + ' copied');
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = String(text);
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); toast('success', (label || 'Copied') + ' copied'); }
            catch (e2) { toast('warning', 'Couldn’t copy', 'Select the text and copy it manually.'); }
            ta.remove();
        }
    }

    function lockScroll(on) {
        document.documentElement.style.overflow = on ? 'hidden' : '';
    }

    window.PT = { url, get: (p) => request('GET', p), post: (p, b) => request('POST', p, b), money, moneyFromCents, toCents, date, toast, copy };

    // ── Alpine registrations ───────────────────────────────────────────
    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        /* ── Pay drawer ─────────────────────────────────────────────── */
        Alpine.store('checkout', {
            open: false,
            loading: false,
            online: !!CFG.online_pay,
            items: [],          // {id, number, due_date, balance, currency, status_label, status_tone, state, error}
            error: '',
            _timer: null,
            _polls: 0,

            get payable() { return this.items.filter(i => i.state !== 'paid'); },
            get totalCents() { return this.payable.reduce((s, i) => s + toCents(i.balance), 0); },
            get currencies() { return [...new Set(this.payable.map(i => i.currency))]; },
            get totalLabel() {
                const c = this.currencies;
                if (c.length > 1) return 'Multiple currencies';
                return moneyFromCents(this.totalCents, c[0] || 'CAD');
            },
            get paidCount() { return this.items.filter(i => i.state === 'paid').length; },
            get waitingCount() { return this.items.filter(i => i.state === 'waiting').length; },
            get allPaid() { return this.items.length > 0 && this.paidCount === this.items.length; },

            /**
             * Open the drawer. ids = [] → every open invoice on the account.
             */
            async start(ids) {
                this.open = true;
                lockScroll(true);
                this.loading = true;
                this.error = '';
                const q = (ids && ids.length) ? '?ids=' + ids.join(',') : '';
                const r = await request('GET', 'api/v1/portal/invoices/payable' + q);
                this.loading = false;
                if (!r || !r.success) {
                    this.items = [];
                    this.error = (r && r.error && r.error.message) || 'We couldn’t load your invoices.';
                    return;
                }
                this.online = !!r.data.online;
                this.items = (r.data.invoices || [])
                    .filter(i => i.payable)
                    .map(i => Object.assign({}, i, { state: 'idle', error: '' }));
            },

            close() {
                this.open = false;
                lockScroll(false);
                this.stopPolling();
                if (this.paidCount > 0) {
                    // Balances changed — show the fresh numbers.
                    setTimeout(() => window.location.reload(), 150);
                }
            },

            payUrl(item) { return url('portal/payments/go?invoice=' + item.id); },

            /** The <a target=_blank> does the navigation; we just start watching. */
            opened(item) {
                item.state = 'waiting';
                item.error = '';
                this.startPolling();
            },

            startPolling() {
                if (this._timer) return;
                this._polls = 0;
                const tick = async () => {
                    this._polls++;
                    await this.refresh();
                    if (this.waitingCount === 0 || this._polls > 200 || !this.open) {
                        this.stopPolling();
                    }
                };
                this._timer = setInterval(() => { if (!document.hidden) tick(); }, 6000);
                this._onFocus = () => tick();
                window.addEventListener('focus', this._onFocus);
            },

            stopPolling() {
                if (this._timer) clearInterval(this._timer);
                this._timer = null;
                if (this._onFocus) window.removeEventListener('focus', this._onFocus);
                this._onFocus = null;
            },

            async refresh() {
                const ids = this.items.map(i => i.id);
                if (!ids.length) return;
                const r = await request('GET', 'api/v1/portal/invoices/payable?ids=' + ids.join(','));
                if (!r || !r.success) return;
                const byId = {};
                (r.data.invoices || []).forEach(i => { byId[i.id] = i; });
                this.items.forEach(item => {
                    const fresh = byId[item.id];
                    if (!fresh) return;
                    if (!fresh.payable || toCents(fresh.balance) <= 0) {
                        if (item.state !== 'paid') {
                            item.state = 'paid';
                            toast('success', 'Payment received', 'Invoice ' + item.number + ' is paid. Thank you!');
                        }
                    } else if (toCents(fresh.balance) < toCents(item.balance)) {
                        item.balance = fresh.balance;
                        item.status_label = fresh.status_label;
                    }
                });
            },
        });

        /* ── Payment notice (remittance advice) ─────────────────────── */
        Alpine.store('notice', {
            open: false,
            sending: false,
            done: null,       // {request_id, url}
            error: '',
            form: { amount: '', paid_on: '', method: 'e_transfer', reference: '', note: '', invoice_ids: [] },
            invoices: [],     // open invoices to pick from
            loaded: false,

            async show(preset) {
                this.open = true;
                lockScroll(true);
                this.done = null;
                this.error = '';
                const today = CFG.today || new Date().toISOString().slice(0, 10);
                this.form = { amount: '', paid_on: today, method: 'e_transfer', reference: '', note: '', invoice_ids: [] };
                if (!this.loaded) {
                    const r = await request('GET', 'api/v1/portal/invoices/payable');
                    if (r && r.success) this.invoices = (r.data.invoices || []).filter(i => i.payable);
                    this.loaded = true;
                }
                if (preset && preset.ids && preset.ids.length) {
                    this.form.invoice_ids = preset.ids.map(Number);
                    this.syncAmount();
                }
            },

            close() { this.open = false; lockScroll(false); },

            toggle(id) {
                id = Number(id);
                const i = this.form.invoice_ids.indexOf(id);
                if (i === -1) this.form.invoice_ids.push(id); else this.form.invoice_ids.splice(i, 1);
                this.syncAmount();
            },

            syncAmount() {
                const cents = this.invoices
                    .filter(inv => this.form.invoice_ids.includes(Number(inv.id)))
                    .reduce((s, inv) => s + toCents(inv.balance), 0);
                if (cents > 0) this.form.amount = (cents / 100).toFixed(2);
            },

            async submit() {
                this.error = '';
                if (!this.form.amount || toCents(this.form.amount) <= 0) { this.error = 'Enter the amount you paid.'; return; }
                if (!this.form.paid_on) { this.error = 'Enter the date you sent the payment.'; return; }
                this.sending = true;
                const r = await request('POST', 'api/v1/portal/payments/notice', this.form);
                this.sending = false;
                if (r && r.success) {
                    this.done = r.data;
                } else {
                    this.error = (r && r.error && r.error.message) || 'We couldn’t send that. Please try again.';
                }
            },
        });

        /* ── Statement picker ───────────────────────────────────────── */
        Alpine.store('statement', {
            open: false,
            preset: '3m',
            from: '',
            to: '',
            show() {
                this.open = true;
                lockScroll(true);
                this.apply('3m');
            },
            close() { this.open = false; lockScroll(false); },
            apply(p) {
                this.preset = p;
                const today = CFG.today || new Date().toISOString().slice(0, 10);
                const t = new Date(today + 'T12:00:00Z');
                const ymd = (d) => d.toISOString().slice(0, 10);
                const firstOf = (d) => new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1, 12));
                this.to = today;
                if (p === 'month') {
                    this.from = ymd(firstOf(t));
                } else if (p === 'last') {
                    const f = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() - 1, 1, 12));
                    const l = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), 0, 12));
                    this.from = ymd(f); this.to = ymd(l);
                } else if (p === '3m') {
                    this.from = ymd(new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() - 3, 1, 12)));
                } else if (p === 'ytd') {
                    this.from = t.getUTCFullYear() + '-01-01';
                } else if (p === '12m') {
                    this.from = ymd(new Date(Date.UTC(t.getUTCFullYear() - 1, t.getUTCMonth(), t.getUTCDate(), 12)));
                }
            },
            get href() {
                return url('api/v1/portal/statement?from=' + encodeURIComponent(this.from) + '&to=' + encodeURIComponent(this.to));
            },
            get valid() { return this.from && this.to && this.from <= this.to; },
        });
    });

    /* ── Shell (root x-data) ────────────────────────────────────────── */
    window.PT_Shell = function () {
        return {
            navOpen: false,
            searchOpen: false,
            init() {
                const top = document.querySelector('.pt-top');
                const onScroll = () => { if (top) top.classList.toggle('is-scrolled', window.scrollY > 4); };
                window.addEventListener('scroll', onScroll, { passive: true });
                onScroll();
                window.addEventListener('keydown', (e) => {
                    const tag = (e.target && e.target.tagName) || '';
                    const typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable);
                    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                        e.preventDefault();
                        this.openSearch();
                    } else if (e.key === '/' && !typing && !this.searchOpen) {
                        e.preventDefault();
                        this.openSearch();
                    }
                });
                this.$watch('navOpen', (v) => lockScroll(v));
            },
            openSearch() {
                this.searchOpen = true;
                this.navOpen = false;
                window.dispatchEvent(new CustomEvent('pt-search-open'));
            },
        };
    };

    /* ── Command palette ────────────────────────────────────────────── */
    window.PT_Search = function () {
        return {
            q: '',
            results: [],
            loading: false,
            active: 0,
            _seq: 0,
            _t: null,
            get quick() { return CFG.quick_links || []; },
            get flat() { return this.q.trim() === '' ? this.quick : this.results; },
            onOpen() {
                this.q = '';
                this.results = [];
                this.active = 0;
                lockScroll(true);
                this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
            },
            onClose() { lockScroll(false); },
            onInput() {
                clearTimeout(this._t);
                const term = this.q.trim();
                this.active = 0;
                if (term.length < 2) { this.results = []; this.loading = false; return; }
                this.loading = true;
                this._t = setTimeout(() => this.run(term), 180);
            },
            async run(term) {
                const seq = ++this._seq;
                const r = await request('GET', 'api/v1/portal/search?q=' + encodeURIComponent(term));
                if (seq !== this._seq) return;
                this.loading = false;
                this.results = (r && r.success && r.data.results) || [];
            },
            move(d) {
                const n = this.flat.length;
                if (!n) return;
                this.active = (this.active + d + n) % n;
                this.$nextTick(() => {
                    const el = this.$root.querySelector('.pt-cmdk-item.is-active');
                    if (el) el.scrollIntoView({ block: 'nearest' });
                });
            },
            go() {
                const item = this.flat[this.active];
                if (item && item.url) window.location.href = item.url;
            },
            groupStart(i) {
                const list = this.flat;
                return i === 0 || list[i].group !== list[i - 1].group;
            },
        };
    };

    /* ── Invoices page ──────────────────────────────────────────────── */
    window.PT_InvoiceList = function (init) {
        return {
            tab: init.tab || 'open',
            q: '',
            from: '',
            to: '',
            rows: [],
            counts: init.counts || {},
            total: 0,
            page: 1,
            perPage: 25,
            loading: true,
            selected: [],
            online: !!CFG.online_pay,
            _t: null,
            _seq: 0,
            init() {
                this.load();
                this.$watch('tab', () => { this.page = 1; this.selected = []; this.load(); this.syncUrl(); });
            },
            syncUrl() {
                try {
                    const u = new URL(window.location.href);
                    u.searchParams.set('tab', this.tab);
                    history.replaceState(null, '', u.toString());
                } catch (e) {}
            },
            debounced() {
                clearTimeout(this._t);
                this._t = setTimeout(() => { this.page = 1; this.load(); }, 300);
            },
            get params() {
                const p = new URLSearchParams({ tab: this.tab, page: String(this.page), per_page: String(this.perPage) });
                if (this.q.trim()) p.set('q', this.q.trim());
                if (this.from) p.set('from', this.from);
                if (this.to) p.set('to', this.to);
                return p;
            },
            async load() {
                const seq = ++this._seq;
                this.loading = true;
                const r = await request('GET', 'api/v1/portal/invoices/list?' + this.params.toString());
                if (seq !== this._seq) return;
                this.loading = false;
                if (!r || !r.success) {
                    toast('danger', 'Couldn’t load invoices', (r && r.error && r.error.message) || '');
                    return;
                }
                this.rows = r.data.invoices || [];
                this.counts = r.data.counts || this.counts;
                this.total = r.data.total || 0;
            },
            get pages() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
            go(p) { if (p < 1 || p > this.pages) return; this.page = p; this.load(); },
            get payableRows() { return this.rows.filter(r => r.payable); },
            isSel(id) { return this.selected.includes(id); },
            toggle(id) {
                const i = this.selected.indexOf(id);
                if (i === -1) this.selected.push(id); else this.selected.splice(i, 1);
            },
            get allSel() { return this.rows.length > 0 && this.rows.every(r => this.selected.includes(r.id)); },
            get someSel() { return this.selected.length > 0 && !this.allSel; },
            toggleAll() {
                if (this.allSel) { this.selected = []; }
                else { this.selected = this.rows.map(r => r.id); }
            },
            get selRows() { return this.rows.filter(r => this.selected.includes(r.id)); },
            get selPayable() { return this.selRows.filter(r => r.payable); },
            get selSum() {
                const rows = this.selPayable;
                const cur = [...new Set(rows.map(r => r.currency))];
                const cents = rows.reduce((s, r) => s + toCents(r.balance_due), 0);
                if (cur.length > 1) return 'Multiple currencies';
                return moneyFromCents(cents, cur[0] || 'CAD') + ' due';
            },
            paySelected() {
                const ids = this.selPayable.map(r => r.id);
                if (!ids.length) { toast('info', 'Nothing to pay', 'The selected invoices have no balance due.'); return; }
                Alpine.store('checkout').start(ids);
            },
            reportSelected() {
                Alpine.store('notice').show({ ids: this.selPayable.map(r => r.id) });
            },
            get zipHref() { return url('api/v1/portal/invoices/zip?ids=' + this.selected.join(',')); },
            get csvHref() {
                const p = this.params; p.delete('page'); p.delete('per_page');
                return url('api/v1/portal/invoices/export?' + p.toString());
            },
            clearFilters() { this.q = ''; this.from = ''; this.to = ''; this.page = 1; this.load(); },
            get hasFilters() { return !!(this.q.trim() || this.from || this.to); },
            viewUrl(id) { return url('portal/invoices/view?id=' + id); },
            pdfUrl(id) { return url('api/v1/portal/invoices/pdf?id=' + id); },
        };
    };
})();
