/**
 * public/assets/js/sop.js — SOP module behaviour (S-SOP-MODULE)
 *
 * Alpine components used by the /sop pages. Loaded by those pages only,
 * BEFORE the footer's Alpine script, so the factory functions exist when
 * Alpine walks the DOM (same pattern as the Training page).
 *
 *   sopHub(cfg)        — hub: instant search over every section, role filter
 *   sopChapter(cfg)    — reader: progress bar, scroll-spy TOC, Mark as read
 *   sopChecklist(cfg)  — the shared month-end checklist (ticks + live checks)
 *   sopFilter()        — live text filter over a table (:::filter)
 *
 * Alpine auto-runs init(); never add x-init="init()" (double-init trap).
 * FF_Api.post RESOLVES on 4xx — always gate on `.success`.
 */
(function () {
    'use strict';

    // localStorage is a per-viewer convenience only; it may throw (private
    // mode, blocked storage) and the page must work without it.
    const store = {
        get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
    };

    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const reEsc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    // ============================================================
    // Hub
    // ============================================================
    window.sopHub = function (cfg) {
        return {
            q: '',
            role: store.get('ff.sop.role', 'all'),
            results: [],
            active: 0,
            open: false,
            index: cfg.index || [],
            base: cfg.base,

            init() {
                if (!['all', 'dispatcher', 'manager', 'accountant', 'super_admin'].includes(this.role)) this.role = 'all';
                const qs = new URLSearchParams(location.search).get('q');
                if (qs) { this.q = qs; this.search(); this.open = true; }
                // "/" focuses search, like most docs sites.
                document.addEventListener('keydown', (e) => {
                    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '')) {
                        e.preventDefault();
                        this.$refs.search?.focus();
                    }
                });
            },

            setRole(r) { this.role = r; store.set('ff.sop.role', r); },
            inRole(audience) { return this.role === 'all' || (audience || []).includes(this.role); },

            search() {
                const q = this.q.trim().toLowerCase();
                this.active = 0;
                if (q.length < 2) { this.results = []; return; }
                const terms = q.split(/\s+/).filter((t) => t.length > 1);
                const scored = [];
                for (const e of this.index) {
                    const title = e.section.toLowerCase();
                    const text = e.text.toLowerCase();
                    let score = 0, all = true;
                    for (const t of terms) {
                        const inTitle = title.includes(t), inText = text.includes(t), inCh = e.chapter.toLowerCase().includes(t);
                        if (!inTitle && !inText && !inCh) { all = false; break; }
                        score += (inTitle ? 6 : 0) + (inCh ? 2 : 0) + (inText ? 1 + Math.min(3, text.split(t).length - 1) * 0.3 : 0);
                    }
                    if (!all) continue;
                    if (text.includes(q)) score += 3;
                    scored.push({ e, score });
                }
                scored.sort((a, b) => b.score - a.score);
                this.results = scored.slice(0, 12).map(({ e }) => ({
                    href: this.base + '/' + e.slug + '?hl=' + encodeURIComponent(this.q.trim()) + (e.anchor ? '#' + e.anchor : ''),
                    where: String(e.number).padStart(2, '0') + ' · ' + e.chapter,
                    title: e.section,
                    snippet: this.snippet(e.text, terms),
                }));
            },

            snippet(text, terms) {
                const lower = text.toLowerCase();
                let at = -1;
                for (const t of terms) { at = lower.indexOf(t); if (at >= 0) break; }
                const start = Math.max(0, at - 60);
                let s = (start > 0 ? '…' : '') + text.slice(start, start + 190) + (start + 190 < text.length ? '…' : '');
                s = esc(s);
                for (const t of terms) s = s.replace(new RegExp('(' + reEsc(esc(t)) + ')', 'ig'), '<mark>$1</mark>');
                return s;
            },

            move(d) {
                if (!this.results.length) return;
                this.active = (this.active + d + this.results.length) % this.results.length;
            },
            go() { if (this.results[this.active]) location.href = this.results[this.active].href; },
        };
    };

    // ============================================================
    // Chapter reader
    // ============================================================
    window.sopChapter = function (cfg) {
        return {
            status: cfg.status,          // read | updated | unread
            readAt: cfg.readAt || null,
            busy: false,
            activeId: '',

            init() {
                const bar = this.$refs.readbar;
                // Scroll-spy: the last heading above the upper third of the
                // window is the current section. Computed on scroll (one rAF
                // per frame) rather than IntersectionObserver, which misses
                // jumps (anchor links, search results) that skip past headings.
                const heads = [...document.querySelectorAll('.sop-content h2.sop-h, .sop-content h3.sop-h, #checklist')];
                let queued = false;
                const update = () => {
                    queued = false;
                    const h = document.documentElement;
                    const max = h.scrollHeight - h.clientHeight;
                    if (bar) bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, h.scrollTop / max) : 0) + ')';
                    let current = heads.length ? heads[0].id : '';
                    const line = window.innerHeight * 0.33;
                    for (const hd of heads) { if (hd.getBoundingClientRect().top < line) current = hd.id; else break; }
                    this.activeId = current;
                };
                window.addEventListener('scroll', () => { if (!queued) { queued = true; requestAnimationFrame(update); } }, { passive: true });
                update();

                // Arriving from a search result: highlight the searched words once.
                const hl = new URLSearchParams(location.search).get('hl');
                if (hl) this.highlight(hl);
            },

            highlight(q) {
                const terms = q.toLowerCase().split(/\s+/).filter((t) => t.length > 1);
                if (!terms.length) return;
                // Only the section the search result pointed at: from its
                // heading to the next heading of the same or a higher level.
                // Highlighting a common word across a whole chapter is noise.
                const head = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
                const scope = [];
                if (head && /^H[23]$/.test(head.tagName)) {
                    const level = Number(head.tagName[1]);
                    for (let el = head.nextElementSibling; el; el = el.nextElementSibling) {
                        if (/^H[23]$/.test(el.tagName) && Number(el.tagName[1]) <= level) break;
                        scope.push(el);
                    }
                } else {
                    const first = document.querySelector('.sop-content');
                    if (first) scope.push(first);
                }
                const re = new RegExp('(' + terms.map(reEsc).join('|') + ')', 'ig');
                const nodes = [];
                for (const root of scope) {
                    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                        acceptNode: (n) => (n.parentElement.closest('svg, script, style, .sop-cl') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
                    });
                    while (walker.nextNode()) { if (re.test(walker.currentNode.nodeValue)) nodes.push(walker.currentNode); re.lastIndex = 0; }
                }
                nodes.slice(0, 40).forEach((n) => {
                    const span = document.createElement('span');
                    span.innerHTML = esc(n.nodeValue).replace(re, '<mark class="sop-hit">$1</mark>');
                    n.replaceWith(...span.childNodes);
                });
            },

            async toggleRead() {
                if (this.busy) return;
                this.busy = true;
                const want = this.status !== 'read';
                try {
                    const res = await FF_Api.post(cfg.api, { slug: cfg.slug, read: want });
                    if (res && res.success) {
                        this.status = want ? 'read' : 'unread';
                        this.readAt = want ? res.data.read_at : null;
                        if (want) {
                            if (window.FF_Toast) FF_Toast.success('Marked as read', 'Chapter ' + cfg.number + ' — ' + cfg.title);
                            if (cfg.allRead && window.FF_Confetti) FF_Confetti.burst({ count: 70 });
                        }
                    } else if (window.FF_Toast) {
                        FF_Toast.error('Could not save', (res && res.error && res.error.message) || 'Please try again.');
                    }
                } catch (e) {
                    if (window.FF_Toast) FF_Toast.error('Could not save', 'Network error. Please try again.');
                } finally {
                    this.busy = false;
                }
            },

            readLabel() {
                if (this.status === 'read') return 'Read' + (this.readAt ? ' · ' + window.FF_formatUtc(this.readAt, { hour: undefined, minute: undefined }) : '');
                if (this.status === 'updated') return 'Updated since you read it';
                return 'Not read yet';
            },
        };
    };

    // ============================================================
    // Month-end checklist
    // ============================================================
    window.sopChecklist = function (cfg) {
        return {
            d: cfg.payload,
            loading: false,
            pending: {},
            celebrated: false,

            init() { this.celebrated = this.d.done === this.d.total; },

            pct() { return this.d.total ? this.d.done / this.d.total : 0; },
            ringOffset(r) { const c = 2 * Math.PI * r; return c * (1 - this.pct()); },
            ringLength(r) { return 2 * Math.PI * r; },
            initials(name) { return (name || '?').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase(); },
            when(v) { return window.FF_formatUtc(v); },
            since() { return window.FF_formatUtc(this.d.checked_at, { year: undefined, month: undefined, day: undefined }); },

            async load(period) {
                if (!period || this.loading) return;
                this.loading = true;
                try {
                    const res = await FF_Api.get(cfg.api + '?key=' + encodeURIComponent(this.d.key) + '&period=' + encodeURIComponent(period));
                    if (res && res.success) {
                        this.d = res.data;
                        this.celebrated = this.d.done === this.d.total;
                        const u = new URL(location.href); u.searchParams.set('period', period); history.replaceState(null, '', u);
                    } else if (window.FF_Toast) {
                        FF_Toast.error('Could not load the checklist', (res && res.error && res.error.message) || 'Please try again.');
                    }
                } catch (e) {
                    if (window.FF_Toast) FF_Toast.error('Could not load the checklist', 'Network error. Please try again.');
                } finally {
                    this.loading = false;
                }
            },
            refresh() { this.load(this.d.period); },

            async toggle(stage, item) {
                if (!this.d.can_tick || this.pending[item.key]) return;
                const want = !item.ticked;
                // Optimistic: flip now, put it back if the server says no.
                this.pending[item.key] = true;
                this.apply(stage, item, want, want ? cfg.me : null, want ? new Date().toISOString().slice(0, 19).replace('T', ' ') : null);
                try {
                    const res = await FF_Api.post(cfg.api, { key: this.d.key, period: this.d.period, item: item.key, checked: want }, { quiet: true });
                    if (res && res.success) {
                        this.d = res.data;
                        if (this.d.done === this.d.total && !this.celebrated) {
                            this.celebrated = true;
                            if (window.FF_Confetti) FF_Confetti.burst({ count: 120 });
                        }
                        if (this.d.done !== this.d.total) this.celebrated = false;
                    } else {
                        this.apply(stage, item, !want, null, null);
                        if (window.FF_Toast) FF_Toast.error('Not saved', (res && res.error && res.error.message) || 'Please try again.');
                    }
                } catch (e) {
                    this.apply(stage, item, !want, null, null);
                    if (window.FF_Toast) FF_Toast.error('Not saved', 'Network error. Please try again.');
                } finally {
                    delete this.pending[item.key];
                }
            },

            apply(stage, item, ticked, by, at) {
                if (item.ticked === ticked) return;
                item.ticked = ticked;
                item.by_name = by;
                item.at = at;
                stage.done += ticked ? 1 : -1;
                this.d.done += ticked ? 1 : -1;
            },

            jump(letter) {
                const el = document.getElementById('cl-stage-' + letter);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
        };
    };

    // ============================================================
    // Table filter (:::filter)
    // ============================================================
    window.sopFilter = function () {
        return {
            q: '',
            shown: 0,
            init() { this.apply(); },
            apply() {
                const q = this.q.trim().toLowerCase();
                const root = this.$refs.target;
                let shown = 0;
                root.querySelectorAll('.sop-table-wrap').forEach((wrap) => {
                    let visible = 0;
                    wrap.querySelectorAll('tbody tr').forEach((tr) => {
                        const hit = !q || tr.textContent.toLowerCase().includes(q);
                        tr.style.display = hit ? '' : 'none';
                        if (hit) visible++;
                    });
                    wrap.style.display = visible ? '' : 'none';
                    // Hide a group's heading when its whole table is filtered out.
                    const prev = wrap.previousElementSibling;
                    if (prev && /^H[23]$/.test(prev.tagName)) prev.style.display = visible ? '' : 'none';
                    shown += visible;
                });
                this.shown = shown;
            },
        };
    };
})();
