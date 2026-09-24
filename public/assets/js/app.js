/**
 * FleetForge — Application JavaScript
 *
 * Sections
 * ────────────────────────────────────────
 * 01. Platform detection
 * 02. Escape & utility helpers
 * 03. CSRF token & API helper (FF_Api)
 * 04. FF_Theme  — dark / light toggle
 * 05. FF_Toast  — toast notification manager
 * 06. FF_Confirm — confirm dialog trigger
 * 07. FF_Notifications — notification dropdown
 * 08. FF_Search — global search (⌘K / Ctrl+K)
 * 09. Alpine component factories
 * 10. DOM-ready boot
 *
 * Load order (footer.php):
 *   ApexCharts → app.js (this file) → Alpine.js [defer]
 *
 * All public APIs are on window so Alpine x-data and
 * inline page scripts can call them without imports.
 */

'use strict';

// ============================================================
// 01. Platform detection
// ============================================================
(function detectPlatform() {
    const isMac =
        /Mac|iPhone|iPad|iPod/.test(navigator.platform) ||
        navigator.userAgentData?.platform === 'macOS';
    if (isMac) {
        document.documentElement.classList.add('is-mac');
    }
})();


// ============================================================
// 02. Escape & utility helpers (internal)
// ============================================================

/**
 * Escape a value for safe insertion into HTML.
 * Used when setting innerHTML to avoid XSS.
 */
function ffEsc(val) {
    return String(val ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Debounce: return a function that only fires after `wait` ms of quiet.
 */
function ffDebounce(fn, wait) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}


// ============================================================
// 02b. FF_Sound — notification / chat audio cue manager (MEDIA-1)
// ============================================================
// Plays a short mp3 when a new notification or chat message
// arrives. Rules baked in:
//   - Never plays on first page load (only for NEW deltas).
//   - Never plays while the browser tab has never received a
//     user gesture — required by modern autoplay policies.
//   - Respects a mute flag stored in localStorage.
//   - Source URL read from <meta name="notification-sound">.
//     If the meta tag isn't present (e.g. login page) the
//     utility is a no-op.
//
// Public surface:
//   FF_Sound.play()        — attempt to play the cue
//   FF_Sound.toggleMute()  — flip mute, persist, return new state
//   FF_Sound.muted         — current mute state
//   FF_Sound.hasInteracted — did the user interact yet?
// ============================================================

const FF_Sound = {
    audio: null,
    muted: false,
    hasInteracted: false,

    init() {
        // localStorage read wrapped in try/catch — some privacy modes
        // throw instead of returning null.
        try {
            this.muted = localStorage.getItem('ff_sound_muted') === 'true';
        } catch (e) { this.muted = false; }

        // Satisfy autoplay policy: the first user gesture unblocks audio.
        // `once: true` removes the listener after it fires so we don't
        // pay for every future click.
        const markInteracted = () => { this.hasInteracted = true; };
        document.addEventListener('click',   markInteracted, { once: true });
        document.addEventListener('keydown', markInteracted, { once: true });
        document.addEventListener('touchstart', markInteracted, { once: true, passive: true });

        // Preload the audio element once so play() is instant.
        const metaEl = document.querySelector('meta[name="notification-sound"]');
        const src    = metaEl?.content || '';
        if (src) {
            try {
                this.audio = new Audio(src);
                this.audio.volume = 0.5; // gentle, not startling
                this.audio.preload = 'auto';
            } catch (e) { this.audio = null; }
        }
    },

    play() {
        if (this.muted)         return;
        if (!this.hasInteracted) return;
        if (!this.audio)        return;
        if (!this.audio.src)    return;
        try {
            // Rewind so rapid-fire messages still fire a sound each time
            this.audio.currentTime = 0;
            const p = this.audio.play();
            if (p && typeof p.catch === 'function') {
                p.catch(() => { /* autoplay blocked — silent */ });
            }
        } catch (e) { /* silent */ }
    },

    toggleMute() {
        this.muted = !this.muted;
        try {
            localStorage.setItem('ff_sound_muted', this.muted.toString());
        } catch (e) { /* localStorage blocked — in-memory only */ }
        return this.muted;
    },
};

// Initialise on DOMContentLoaded — must run after the meta tag
// is in the DOM but before Alpine calls any fetchCount() handlers.
document.addEventListener('DOMContentLoaded', () => FF_Sound.init());

window.FF_Sound = FF_Sound;


// ============================================================
// 03. CSRF token & API helper
// ============================================================

const FF_CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * FF_Api — thin fetch wrapper that automatically attaches the CSRF
 * token and parses JSON. All endpoints return { success, data?, error? }.
 */
const FF_Api = {

    _headers(extra = {}) {
        return {
            'Content-Type':   'application/json',
            'X-CSRF-Token':   FF_CSRF_TOKEN,
            'X-Requested-With': 'XMLHttpRequest',
            ...extra,
        };
    },

    /**
     * GET request. Returns parsed JSON or throws on network error.
     * @param {string} url
     * @returns {Promise<object>}
     */
    async get(url) {
        const res = await fetch(url, {
            method: 'GET',
            headers: this._headers(),
            credentials: 'same-origin',
        });
        // Not auto-popped: a GET is a page load or a background poll, not
        // something the operator just clicked. The guidance still rides in the
        // payload, so a page that wants it can call FF_Guidance.show() itself.
        return await res.json();
    },

    /**
     * POST request with a JSON body.
     * @param {string} url
     * @param {object} data
     * @returns {Promise<object>}
     */
    async post(url, data = {}, opts = {}) {
        const res = await fetch(url, {
            method: 'POST',
            headers: this._headers(),
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        // opts.quiet = true for a call the operator did not initiate (a
        // background save, a retry loop) where a modal would interrupt
        // something they are in the middle of.
        return opts.quiet ? await res.json() : this._guide(await res.json());
    },

    /**
     * DELETE request. Returns parsed JSON or throws on network error.
     * WHY: Used for destructive operations like removing AI chat sessions,
     * draft records, or attachments — endpoints that follow REST conventions.
     * @param {string} url
     * @returns {Promise<object>}
     */
    async delete(url, opts = {}) {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: this._headers(),
            credentials: 'same-origin',
        });
        return opts.quiet ? await res.json() : this._guide(await res.json());
    },

    /**
     * POST multipart/form-data (file uploads).
     * Content-Type is intentionally omitted so the browser sets the boundary.
     * @param {string} url
     * @param {FormData} formData
     * @returns {Promise<object>}
     */
    async upload(url, formData) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-Token':   FF_CSRF_TOKEN,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: formData,
        });
        return this._guide(await res.json());
    },

    /**
     * S-BILLING-GUIDANCE: pop the explain-and-fix modal for any error payload
     * that carries `error.guidance`, then hand the response back untouched.
     *
     * WHY here: a dead-end error is a dead end on EVERY page that hits the
     * endpoint. Doing it in the one wrapper every caller already goes through
     * means an endpoint opts in purely server-side — attach `guidance` to its
     * json_error() and the modal appears, with no page-level JS. Callers keep
     * their own inline banner (it persists after the modal is dismissed); the
     * modal carries the part a banner has no room for — the numbers behind the
     * error and the links that fix it.
     *
     * Never throws: a malformed payload must not take down the caller's own
     * error handling.
     */
    _guide(json) {
        try {
            if (json && json.success === false && json.error && json.error.guidance
                && window.FF_Guidance) {
                window.FF_Guidance.show(json.error.guidance);
            }
        } catch (err) {
            console.error('[FF_Api] guidance modal failed:', err);
        }
        return json;
    },

    /**
     * Show the guidance modal for a response the caller fetched with get()
     * (which never auto-pops). Returns true if a modal was shown.
     */
    guide(json) {
        this._guide(json);
        return !!(json && json.error && json.error.guidance);
    },

    /** Build an absolute URL under FF_BASE_PATH.
     *
     * Idempotent guard (S-FIX-SELECTORS-EMPTY): if the caller already passed a
     * fully-qualified URL (e.g. PHP base_url('api/v1/…') → https://host/fleetforge/…),
     * return it untouched. Prepending FF_BASE_PATH again produced the malformed
     * `/fleetforgehttps://host/fleetforge/api/v1/…` that 404'd, which FF_RecordPicker's
     * try/catch swallowed into an empty result set — making customer/equipment/etc.
     * selectors render empty on every create/edit form. The contract is still
     * "pass a root-relative path"; this guard just stops a base_url() slip from
     * silently breaking a picker. */
    url(path) {
        if (typeof path === 'string' && /^https?:\/\//i.test(path)) {
            return path;
        }
        return (window.FF_BASE_PATH ?? '') + path;
    },
};

window.FF_Api = FF_Api;


// ============================================================
// 03b. FF_LookupPicker — reusable search-as-you-type dropdown
// ----------------------------------------------------------------
// Operator-caught 2026-05-29: forms across accounting (Fixed Assets,
// CapEx, etc.) had raw `number` inputs for GL account IDs + equipment
// unit IDs. Users couldn't search by name/code — they had to know
// the numeric ID by heart. This Alpine factory provides:
//   • a type-to-search input that hits an API endpoint
//   • a dropdown of results with primary label (e.g. account code +
//     name; unit number + VIN)
//   • a "Manual entry" toggle that swaps to a raw numeric input
//     for power users who already know the ID
//   • two-way binding via x-model on the wrapper's `selectedId`
//
// Usage in any modal/form:
//
//   <div x-data="FF_LookupPicker({
//          endpoint: '/api/v1/accounting/accounts/index.php',
//          searchParam: 'search',
//          extraParams: { flat: 1, active: 1 },
//          minChars: 0,
//          format: r => r.code + ' — ' + r.name,
//          initialId: createForm.asset_account_id,
//          onSelect: id => createForm.asset_account_id = id
//        })">
//     ... renders the picker ...
//   </div>
//
// Endpoint contract: GET returns { success: true, data: { items: [...] } }
// where each item has at minimum {id, ...whatever the format fn needs}.
// Most FF endpoints return data.items or data.units or data.accounts —
// the factory tries common shapes.
// ============================================================
function FF_LookupPicker(opts) {
    const o = {
        endpoint: '',
        searchParam: 'search',
        extraParams: {},
        minChars: 0,
        debounceMs: 250,
        format: r => r.name || r.label || ('#' + r.id),
        initialId: '',
        initialLabel: '',
        placeholder: 'Search or select…',
        manualPlaceholder: 'Enter ID',
        onSelect: () => {},
        // targetPath: dot-path into the nearest ancestor Alpine component
        // (e.g. "createForm.asset_account_id"). Lets the picker write the
        // selected ID into the parent form without the parent needing
        // event wiring. Used by accounting modals where the picker is
        // a nested x-data inside the modal's main Alpine component.
        targetPath: '',
        ...opts,
    };

    // Helper: walk up from picker DOM root to the nearest ANCESTOR
    // Alpine component (skip self). Returns its data stack [0] or null.
    function findParentAlpine(el) {
        let p = el?.parentElement;
        while (p) {
            if (p._x_dataStack && p._x_dataStack.length) {
                return p._x_dataStack[0];
            }
            p = p.parentElement;
        }
        return null;
    }

    function applyTargetPath(rootEl, value) {
        if (!o.targetPath) return;
        const parent = findParentAlpine(rootEl);
        if (!parent) return;
        const parts = o.targetPath.split('.');
        let obj = parent;
        for (let i = 0; i < parts.length - 1; i++) {
            if (obj[parts[i]] === undefined) return;
            obj = obj[parts[i]];
        }
        obj[parts[parts.length - 1]] = value;
    }

    return {
        // State
        manualMode: false,
        selectedId: o.initialId || '',
        selectedLabel: o.initialLabel || '',
        query: '',
        results: [],
        open: false,
        loading: false,
        highlightIndex: -1,
        _debounceTimer: null,

        // Lifecycle
        init() {
            // If we have an initial ID but no label, fetch it once to populate.
            if (this.selectedId && !this.selectedLabel) {
                this.hydrateLabel();
            }
        },

        async hydrateLabel() {
            try {
                const params = new URLSearchParams({...o.extraParams, [o.searchParam]: ''});
                const r = await FF_Api.get(FF_Api.url(o.endpoint) + '?' + params.toString());
                const items = this._extractItems(r);
                const hit = items.find(it => String(it.id) === String(this.selectedId));
                if (hit) this.selectedLabel = o.format(hit);
            } catch (_) { /* silent */ }
        },

        toggleManual() {
            this.manualMode = !this.manualMode;
            if (this.manualMode) {
                this.open = false;
            } else {
                this.query = '';
                this.results = [];
            }
        },

        openDropdown() {
            this.open = true;
            if (this.query.length >= o.minChars) this.search();
        },

        closeDropdown() {
            // Delay so click events on items still register.
            setTimeout(() => { this.open = false; this.highlightIndex = -1; }, 150);
        },

        onInput() {
            this.open = true;
            this.highlightIndex = -1;
            clearTimeout(this._debounceTimer);
            this._debounceTimer = setTimeout(() => {
                if (this.query.length >= o.minChars) this.search();
            }, o.debounceMs);
        },

        async search() {
            this.loading = true;
            try {
                const params = new URLSearchParams({...o.extraParams, [o.searchParam]: this.query});
                const r = await FF_Api.get(FF_Api.url(o.endpoint) + '?' + params.toString());
                this.results = this._extractItems(r).slice(0, 50);
            } catch (_) {
                this.results = [];
            } finally {
                this.loading = false;
            }
        },

        _extractItems(r) {
            if (!r) return [];
            const d = r.data || r;
            return d.items || d.units || d.accounts || d.results
                || (Array.isArray(d) ? d : []);
        },

        choose(item) {
            this.selectedId = item.id;
            this.selectedLabel = o.format(item);
            this.query = this.selectedLabel;
            this.open = false;
            this.highlightIndex = -1;
            applyTargetPath(this.$root || this.$el, item.id);
            o.onSelect(item.id, item);
        },

        clear() {
            this.selectedId = '';
            this.selectedLabel = '';
            this.query = '';
            this.results = [];
            this.open = false;
            applyTargetPath(this.$root || this.$el, '');
            o.onSelect('', null);
        },

        setManualId(value) {
            this.selectedId = value;
            this.selectedLabel = value ? ('#' + value) : '';
            applyTargetPath(this.$root || this.$el, value);
            o.onSelect(value, null);
        },

        onKeyDown(e) {
            if (!this.open || !this.results.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.highlightIndex = Math.min(this.highlightIndex + 1, this.results.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.highlightIndex = Math.max(this.highlightIndex - 1, 0);
            } else if (e.key === 'Enter' && this.highlightIndex >= 0) {
                e.preventDefault();
                this.choose(this.results[this.highlightIndex]);
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },

        // Expose format helper for the template to render result labels.
        formatItem(item) {
            return o.format(item);
        },
    };
}
window.FF_LookupPicker = FF_LookupPicker;


// ============================================================
// 04. FF_Theme — dark / light toggle
// ============================================================

const FF_Theme = {

    STORAGE_KEY: 'ff-theme',

    /** Return the currently active theme. */
    current() {
        return document.documentElement.getAttribute('data-theme') ?? 'dark';
    },

    /**
     * Apply a theme: updates the <html> attribute, persists to
     * localStorage, and POSTs to the server so the preference
     * survives a hard refresh.
     * @param {'light'|'dark'} theme
     */
    set(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(this.STORAGE_KEY, theme);
        } catch {
            /* storage unavailable in private mode — ignore */
        }
        // S-LUX-2: notify live ApexCharts so they re-read the Atelier
        // tokens and re-theme in place (ff-chart-theme.js listens). CSS
        // reacts to the data-theme attribute on its own; charts don't.
        try {
            window.dispatchEvent(new CustomEvent('ff:theme-changed', { detail: { theme } }));
        } catch (_e) { /* CustomEvent unsupported — ignore */ }
        // Non-blocking server persist (best-effort)
        FF_Api.post(FF_Api.url('/api/v1/account/theme'), { theme }).catch(() => {});
    },

    toggle() {
        this.set(this.current() === 'dark' ? 'light' : 'dark');
    },

    /**
     * Called at boot: apply any localStorage preference over the
     * server-rendered attribute (so the toggle persists client-side
     * even if the DB hasn't been updated yet).
     */
    init() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY);
            if (stored === 'light' || stored === 'dark') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        } catch {
            /* ignore */
        }
    },
};

window.FF_Theme = FF_Theme;


// ============================================================
// 04b. FF_Display — per-user main-content font-size + density
// PERM-1 Feature 2.
//
// Manages two settings that apply ONLY to .page-content (the main
// content area). Sidebar/topbar/footer/modals are not affected.
//
//   font-size: 70..130 in steps of 5 (percentage scaling factor)
//   density:   compact | comfortable | spacious
//
// Persistence:
//   - Source of truth is the DB (users.display_font_size /
//     display_density), seeded into the page via window.FF_DISPLAY
//     by header.php and stamped into <body data-density> + an inline
//     <style> tag (#ff-display-font-size) for first paint.
//   - apply() updates BOTH the DOM (so the change is visible
//     immediately, no page reload) AND posts to the API (so the
//     setting persists across devices and sessions).
// ============================================================
const FF_Display = {

    STEPS:   [70, 75, 80, 85, 90, 95, 100, 105, 110, 115, 120, 125, 130],
    DENSITY: ['compact', 'comfortable', 'spacious'],

    /** Current state, hydrated from window.FF_DISPLAY. */
    state() {
        return {
            fontSize: (window.FF_DISPLAY && window.FF_DISPLAY.font_size) || 100,
            density:  (window.FF_DISPLAY && window.FF_DISPLAY.density)   || 'comfortable',
        };
    },

    /**
     * Apply font_size + density to the live DOM. Does NOT call the
     * server — call _persist() for that.
     */
    _applyDom(fontSize, density) {
        const styleEl = document.getElementById('ff-display-font-size');
        if (styleEl) {
            styleEl.textContent = `.page-content { font-size: ${fontSize}%; }`;
        }
        document.body.setAttribute('data-density', density);
        window.FF_DISPLAY = { font_size: fontSize, density };
    },

    /**
     * Persist the current settings to the DB. Returns the parsed
     * API response so callers can show errors. Non-blocking — does
     * NOT throw, errors are surfaced via FF_Toast.
     */
    async _persist(payload) {
        try {
            const res = await FF_Api.post(
                FF_Api.url('/api/v1/users/display_settings/update.php'),
                payload
            );
            if (!res.success) {
                FF_Toast.error('Display settings',
                    (res.error && res.error.message) || 'Could not save display settings.');
            }
            return res;
        } catch (e) {
            FF_Toast.error('Display settings', 'Network error. Please try again.');
            return { success: false };
        }
    },

    /** Set font size only. Step is clamped to the validated set. */
    async setFontSize(value) {
        const v = Number(value);
        if (!this.STEPS.includes(v)) return;
        const cur = this.state();
        this._applyDom(v, cur.density);
        await this._persist({ font_size: v });
    },

    /** Set density only. Value is clamped to the validated set. */
    async setDensity(value) {
        if (!this.DENSITY.includes(value)) return;
        const cur = this.state();
        this._applyDom(cur.fontSize, value);
        await this._persist({ density: value });
    },

    /** Set both at once (used by the profile page reset/preview). */
    async setBoth(fontSize, density) {
        const v = Number(fontSize);
        if (!this.STEPS.includes(v) || !this.DENSITY.includes(density)) return;
        this._applyDom(v, density);
        await this._persist({ font_size: v, density });
    },
};

window.FF_Display = FF_Display;

/**
 * Alpine factory for the topbar quick-controls popover.
 * Used by includes/topbar.php — `x-data="ffDisplaySettings()"`.
 */
function ffDisplaySettings() {
    const seed = (window.FF_DISPLAY) || { font_size: 100, density: 'comfortable' };
    return {
        open:     false,
        saving:   false,
        fontSize: seed.font_size,
        density:  seed.density,

        async incFont() {
            if (this.fontSize >= 130) return;
            this.saving = true;
            this.fontSize = Math.min(130, this.fontSize + 5);
            await FF_Display.setFontSize(this.fontSize);
            this.saving = false;
        },

        async decFont() {
            if (this.fontSize <= 70) return;
            this.saving = true;
            this.fontSize = Math.max(70, this.fontSize - 5);
            await FF_Display.setFontSize(this.fontSize);
            this.saving = false;
        },

        async setDensity(value) {
            if (!FF_Display.DENSITY.includes(value)) return;
            this.saving = true;
            this.density = value;
            await FF_Display.setDensity(value);
            this.saving = false;
        },

        async reset() {
            this.saving = true;
            this.fontSize = 100;
            this.density  = 'comfortable';
            await FF_Display.setBoth(100, 'comfortable');
            this.saving = false;
        },
    };
}
window.ffDisplaySettings = ffDisplaySettings;


// ============================================================
// 05. FF_Toast — toast notification manager
// ============================================================

/** Inline SVG icons for each toast type (Heroicons outline). */
const _TOAST_ICONS = {
    success: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>',
    warning: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008z"/></svg>',
    danger:  '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/></svg>',
    info:    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25z"/></svg>',
    close:   '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>',
};

const FF_Toast = {

    _container: null,

    _getContainer() {
        if (!this._container) {
            this._container = document.getElementById('ff-toast-container');
        }
        return this._container;
    },

    /**
     * Show a toast notification.
     *
     * @param {'success'|'warning'|'danger'|'info'} type
     * @param {string} title
     * @param {string} [message]
     * @param {number} [duration]  ms; 0 = sticky
     */
    show(type, title, message = '', duration = 4500) {
        const container = this._getContainer();
        if (!container) return;

        const icon = _TOAST_ICONS[type] ?? _TOAST_ICONS.info;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML =
            `<span class="toast-icon">${icon}</span>` +
            `<div class="toast-body">` +
            `<div class="toast-title">${ffEsc(title)}</div>` +
            (message ? `<div class="toast-message">${ffEsc(message)}</div>` : '') +
            `</div>` +
            `<button class="toast-close btn-icon" aria-label="Dismiss notification" type="button">` +
            `${_TOAST_ICONS.close}</button>` +
            // S-LUX-3: auto-dismiss progress hairline — only for auto-dismissing
            // toasts (duration > 0); animation-duration is set to the real
            // dismiss time so the bar stays in sync. Sticky toasts get no bar.
            (duration > 0
                ? `<div class="toast-progress" style="animation-duration:${duration}ms"></div>`
                : '');

        toast.querySelector('.toast-close').addEventListener('click', () => {
            this._dismiss(toast);
        });

        container.appendChild(toast);

        if (duration > 0) {
            setTimeout(() => this._dismiss(toast), duration);
        }

        return toast;
    },

    _dismiss(toast) {
        if (!toast || !toast.isConnected) return;
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), 250);
    },

    // Convenience shortcuts
    success(title, message, duration) { return this.show('success', title, message, duration); },
    warning(title, message, duration) { return this.show('warning', title, message, duration); },
    error(title, message, duration)   { return this.show('danger',  title, message, duration); },
    info(title, message, duration)    { return this.show('info',    title, message, duration); },
};

window.FF_Toast = FF_Toast;


// ============================================================
// 06. FF_Confirm — confirm dialog trigger
//
// Pages call FF_Confirm.show({...}) to open the generic dialog
// rendered in footer.php as FF_ConfirmModal().
//
// Example:
//   FF_Confirm.show({
//       title:        'Delete Customer',
//       message:      'This cannot be undone.',
//       confirmLabel: 'Delete',
//       dangerMode:   true,
//       onConfirm:    () => deleteCustomer(id),
//   });
// ============================================================

const FF_Confirm = {
    /**
     * @param {{
     *   title?: string,
     *   message?: string,
     *   confirmLabel?: string,
     *   dangerMode?: boolean,
     *   onConfirm: Function
     * }} options
     */
    show(options = {}) {
        window.dispatchEvent(new CustomEvent('ff-confirm', { detail: options }));
    },

    /**
     * Promise-based replacement for native confirm().
     * Returns true if the user clicks the confirm button, false otherwise.
     *
     * WHY: [UI-AUDIT-1:M13] the codebase had 40+ `if (!confirm(...)) return;`
     * call sites. Rather than restructure every function to put its body
     * inside an onConfirm callback, this lets us migrate with a minimal
     * diff:
     *
     *   Before:  if (!confirm('Delete?')) return;
     *   After:   if (!(await FF_Confirm.ask('Delete?'))) return;
     *
     * @param {string|object} messageOrOptions - Plain message string or
     *        full options object ({ title, message, confirmLabel, dangerMode }).
     * @returns {Promise<boolean>}
     */
    ask(messageOrOptions) {
        return new Promise(function (resolve) {
            const opts = typeof messageOrOptions === 'string'
                ? { message: messageOrOptions }
                : (messageOrOptions || {});

            window.dispatchEvent(new CustomEvent('ff-confirm', {
                detail: Object.assign({
                    title: 'Confirm',
                    dangerMode: true,
                    confirmLabel: 'Confirm',
                }, opts, {
                    onConfirm: function () { resolve(true); },
                    onCancel:  function () { resolve(false); },
                }),
            }));
        });
    },

    /**
     * Promise-based replacement for native prompt().
     * Returns the entered string if the user clicks Confirm, null on Cancel.
     *
     *   Before:  const x = prompt('Reason:'); if (!x) return;
     *   After:   const x = await FF_Confirm.askText('Reason:'); if (!x) return;
     */
    askText(messageOrOptions) {
        return new Promise(function (resolve) {
            const opts = typeof messageOrOptions === 'string'
                ? { message: messageOrOptions }
                : (messageOrOptions || {});

            window.dispatchEvent(new CustomEvent('ff-confirm', {
                detail: Object.assign({
                    title: 'Input required',
                    confirmLabel: 'OK',
                    prompt: true,
                    placeholder: '',
                }, opts, {
                    onConfirm: function (value) { resolve(value || ''); },
                    onCancel:  function () { resolve(null); },
                }),
            }));
        });
    },
};

window.FF_Confirm = FF_Confirm;


// ============================================================
// 05a2. FF_Guidance — the explain-and-fix modal (S-BILLING-GUIDANCE)
// ============================================================
// For errors the OPERATOR can fix but a one-line banner can't explain:
// what happened, the numbers behind it, the steps, and the links that do
// it. Rendered in footer.php as FF_GuidanceModal(), so it is available on
// every admin page.
//
// Usually you never call this by hand — FF_Api pops it automatically for
// any API error carrying `error.guidance` (see FF_Api._guide). An endpoint
// opts in server-side; see lib/Billing/BillingRateGuidance.php for the
// payload shape.
//
//   FF_Guidance.show({ title, summary, cause, steps: [], actions: [{label,url}], detail })
const FF_Guidance = {
    // Repeat-suppression window (ms). Every module can raise a popup now, and
    // a retried action or a re-fired request must not stack or machine-gun
    // modals — the same explanation twice in a row helps nobody.
    _repeatWindowMs: 15000,
    _last: { key: '', at: 0 },

    show(detail = {}) {
        const key = String(detail.title || '') + '|' + String(detail.summary || '');
        const now = Date.now();
        if (key === this._last.key && (now - this._last.at) < this._repeatWindowMs) {
            return false;
        }
        this._last = { key: key, at: now };
        window.dispatchEvent(new CustomEvent('ff-guidance', { detail }));
        return true;
    },
};

window.FF_Guidance = FF_Guidance;


// ============================================================
// 05b. Auto data-label for responsive tables (UI-AUDIT-1:M1)
// ============================================================
// WHY: app.css ships a .table-stack mobile pattern that renders
// each <td> as a label/value row on narrow screens using
// `content: attr(data-label)`. But NONE of the 147 tables in
// the app populate data-label manually, so the stacking pattern
// was useless. Auto-labelling from the <th> text lets every
// admin/portal table gracefully degrade on mobile without any
// template edits.
//
// Behaviour:
//   • Runs once on DOMContentLoaded and once more after Alpine
//     init so dynamically rendered tables also get the labels.
//   • Re-runs whenever a new <table> is added to the DOM
//     (tabs lazy-load tables into existing panels).
//   • Skips tables already marked with [data-no-auto-label].
//   • Skips <td>s that already have data-label so legacy
//     manual labels keep working.
//   • Ignores tables without a <thead><tr><th> structure
//     (bills-of-material tables, etc.).
// ============================================================

(function () {
    function labelCellsIn(table) {
        if (table.hasAttribute('data-no-auto-label')) return;
        if (table.dataset.labelled === '1') return;
        const ths = table.querySelectorAll(':scope > thead > tr > th');
        if (ths.length === 0) return;
        const labels = Array.from(ths).map(function (th) {
            // Strip HTML, collapse whitespace, trim.
            return (th.textContent || '').replace(/\s+/g, ' ').trim();
        });
        const rows = table.querySelectorAll(':scope > tbody > tr');
        rows.forEach(function (row) {
            const tds = row.querySelectorAll(':scope > td');
            tds.forEach(function (td, idx) {
                if (td.hasAttribute('data-label')) return;
                const lbl = labels[idx];
                if (lbl) td.setAttribute('data-label', lbl);
            });
        });
        // Also add the .table-stack class so the mobile CSS kicks in.
        if (!table.classList.contains('table-stack')) {
            table.classList.add('table-stack');
        }
        table.dataset.labelled = '1';
    }

    // ── FF_TableFit (S-TABLES-REDESIGN) ─────────────────────────
    // Reads each table's header row once and tags the TABLE (not the
    // cells, so rows Alpine renders later are covered by plain CSS):
    //   .ff-nw-cN    short values — IDs, dates, amounts, statuses,
    //                counts — that must never fold onto two lines;
    //   .ff-wrap-cN  free text (Description, Notes, Address …): wraps,
    //                but never collapses into a sliver;
    //   .ff-title-cN the record's name column (first real column):
    //                a floor width so it stays readable;
    //   .ff-act      the last column holds row actions (View / Edit).
    // Everything else (names, types, places) wraps only when space runs
    // out, so a list fits its card at laptop widths. The first version
    // kept EVERY cell on one line; at 1280px the customers, invoices and
    // leases lists grew 160–350px wider than their card and the View /
    // Edit buttons slid out of sight.
    // If a table still overflows its scroll container it gets
    // .ff-overflow, which pins the actions column to the right edge.
    // Tables with no scrollable ancestor get no nowrap columns — they
    // would push a narrow card wider than the page.
    const FREE_TEXT = /\b(description|descr|notes?|memo|details?|message|subject|reason|summary|comments?|remarks?|item|items|address|narration|explanation|activity|changes?|event|body|what|purpose|instructions?)\b/i;
    const SHORT_VALUE = /(#|%|\b(no|num|number|id|ref|reference|date|dates|due|issued|created|updated|modified|start|starts|started|end|ends|ended|return|returned|returning|period|expires?|expiry|since|amount|amt|total|balance|outstanding|owing|owed|paid|subtotal|tax|gst|pst|hst|rate|price|cost|value|deposit|credit|debit|fee|qty|quantity|count|days|age|miles|km|odometer|mileage|hours|hrs|status|risk|phone|vin|plate|year|currency|terms|leases|invoice|contract|score|priority|severity|method|stage)\b)/i;
    function scrollAncestor(el) {
        let n = el.parentElement;
        for (let depth = 0; n && depth < 5; depth++, n = n.parentElement) {
            const ox = getComputedStyle(n).overflowX;
            if (ox === 'auto' || ox === 'scroll') return n;
        }
        return null;
    }
    function hasScrollAncestor(el) { return scrollAncestor(el) !== null; }
    function isSelectColumn(th) {
        return th.classList.contains('th-checkbox') || !!th.querySelector('input[type="checkbox"]');
    }
    function checkOverflow(table, scroller) {
        table.classList.toggle('ff-overflow', scroller.scrollWidth > scroller.clientWidth + 1);
    }
    const overflowWatch = typeof ResizeObserver === 'function'
        ? new ResizeObserver(function (entries) {
            for (const en of entries) {
                const t = en.target.tagName === 'TABLE' ? en.target : en.target.querySelector('table.ff-fit');
                if (t && t._ffScroller) checkOverflow(t, t._ffScroller);
            }
        })
        : null;
    // The card around a list table (the table, or its scroll box, is the
    // card's own child or sits alone in a .card-body): tables.css trims the
    // card's padding so the rows run close to the edge, and the list wears
    // its module's colour — the page hero's .ff-acc--* (module-chrome.css;
    // it sets only --acc) is copied onto the card and the toolbar above it,
    // so the header capsule, hover and selection pick it up. Needs no scroll
    // box: only the one-line column rules do.
    function markListCard(table, scroller) {
        const box = scroller || table;
        const up = box.parentElement;
        let listCard = null;
        if (box.classList.contains('card-body') && up && up.classList.contains('card')) {
            listCard = up;
        } else if (up && up.classList.contains('card')) {
            listCard = up;
        } else if (up && up.classList.contains('card-body') && up.parentElement
            && up.parentElement.classList.contains('card') && up.children.length === 1) {
            listCard = up.parentElement;
        }
        if (!listCard) return;
        listCard.classList.add('ff-table-card');
        const hero = document.querySelector('.ff-hero');
        const acc = hero && Array.from(hero.classList).find(function (c) { return c.indexOf('ff-acc--') === 0; });
        if (acc) {
            listCard.classList.add(acc);
            const bar = listCard.previousElementSibling;
            if (bar && bar.classList.contains('table-toolbar')) bar.classList.add(acc);
        }
    }
    function fitColumns(table) {
        if (table.dataset.ffFitted === '1') return;
        if (!table.classList.contains('table') && !table.classList.contains('data-table')) return;
        if (table.classList.contains('spec-table') || table.hasAttribute('data-no-fit')) return;
        const head = table.tHead;
        if (!head || !head.rows.length) return;
        table.dataset.ffFitted = '1';
        let scroller = scrollAncestor(table);
        markListCard(table, scroller);
        // A list table placed straight in its card had no scroll box and was
        // clipped by the card; a list card scrolls sideways (tables.css), so
        // look again — the card itself is now the scroll box.
        if (!scroller) scroller = scrollAncestor(table);
        if (!scroller) return;
        const cells = Array.from(head.rows[head.rows.length - 1].cells);
        let col = 1;
        let titled = false;
        cells.forEach(function (th, i) {
            const span = th.colSpan || 1;
            const text = (th.textContent || '').replace(/\s+/g, ' ').trim();
            const cls = [];
            if (isSelectColumn(th)) {
                cls.push('ff-nw-c');
            } else if (FREE_TEXT.test(text)) {
                cls.push('ff-wrap-c');
            } else {
                // An ID title ("Invoice #") is both the title and short; a bare
                // row number ("#", "ID") is never the name column.
                if (!titled && text && !/^(#|id|no\.?)$/i.test(text)) { cls.push('ff-title-c'); titled = true; }
                if (SHORT_VALUE.test(text)) cls.push('ff-nw-c');
            }
            cls.forEach(function (c) {
                for (let k = 0; k < span && col + k <= 16; k++) table.classList.add(c + (col + k));
            });
            if (i === cells.length - 1 && i > 0 && (text === '' || /^actions?$/i.test(text))) {
                table.classList.add('ff-act');
            }
            col += span;
        });
        table.classList.add('ff-fit');
        table._ffScroller = scroller;
        checkOverflow(table, scroller);
        if (overflowWatch) {
            overflowWatch.observe(table);
            overflowWatch.observe(scroller);
        }
    }

    function labelAllTables() {
        document.querySelectorAll('table').forEach(function (t) { labelCellsIn(t); fitColumns(t); });
    }

    window.FF_TableFit = fitColumns;

    // Initial pass.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', labelAllTables);
    } else {
        labelAllTables();
    }

    // Re-run after Alpine has initialised (Alpine renders x-for late).
    document.addEventListener('alpine:initialized', labelAllTables);

    // Observe future DOM additions for dynamically inserted tables.
    const observer = new MutationObserver(function (mutations) {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.tagName === 'TABLE') {
                    labelCellsIn(node); fitColumns(node);
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('table').forEach(function (t) { labelCellsIn(t); fitColumns(t); });
                }
            }
        }
    });
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
})();


// ============================================================
// 06a. Global modal keyboard & backdrop handlers (UI-AUDIT-1)
// ============================================================
// WHY: the audit found that only 6 of 23 modal-hosting files wired
// up Escape-to-close, and only 10 supported backdrop click-to-close.
// Rather than edit 17+ files to add @keydown.escape / @click.self
// individually (and risk breaking scoped Alpine state), we install
// two document-level listeners that:
//
//   • On Escape:  locate the topmost visible close button inside a
//     .modal-overlay or .modal-backdrop and simulate a click.
//   • On mousedown inside .modal-overlay (but NOT on the .modal
//     card itself): simulate clicking the nearest close button.
//
// This is intentionally DOM-driven so every modal that follows the
// standard structure (.modal-overlay > .modal > header > .modal-close-btn)
// gets Escape + backdrop-close behaviour without any template edits.
//
// Modals that already wire @keydown.escape or @click.self keep working
// — the listener finds the button and calls .click(), which triggers
// whatever Alpine handler the close button is already bound to.
// ============================================================

(function () {
    /**
     * Find the topmost visible close button in the DOM.
     * We look for elements inside a .modal-overlay that are currently
     * rendered (display !== 'none'), and return the close button of
     * the LAST one (which is visually the top-most overlapping modal).
     */
    function topmostVisibleCloseButton() {
        const overlays = Array.from(document.querySelectorAll(
            '.modal-overlay, .modal-backdrop'
        )).filter(function (el) {
            if (el.offsetParent === null) return false;
            const cs = window.getComputedStyle(el);
            return cs.display !== 'none' && cs.visibility !== 'hidden';
        });
        if (overlays.length === 0) return null;
        const top = overlays[overlays.length - 1];
        return top.querySelector('.modal-close-btn, .modal-close, [data-modal-close]');
    }

    // Escape key → click topmost close button.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' && e.keyCode !== 27) return;
        const btn = topmostVisibleCloseButton();
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            btn.click();
        }
    });

    // Backdrop click → click close button of that specific overlay.
    // We use mousedown+mouseup on the same target to avoid closing
    // when the user starts a drag INSIDE the modal and releases it
    // outside (which would fire click on the overlay).
    let mousedownTarget = null;
    document.addEventListener('mousedown', function (e) {
        mousedownTarget = e.target;
    }, true);

    document.addEventListener('click', function (e) {
        // Only act if the same element received both mousedown and click.
        if (e.target !== mousedownTarget) return;
        const overlay = e.target.closest('.modal-overlay, .modal-backdrop');
        if (!overlay) return;
        // If the click happened inside a .modal card, ignore it.
        if (e.target.closest('.modal, .modal-dialog, .modal-box')) return;
        const btn = overlay.querySelector('.modal-close-btn, .modal-close, [data-modal-close]');
        if (btn) {
            e.preventDefault();
            btn.click();
        }
    });
})();


// ============================================================
// 06b. FF_Validate — form error messaging (VALID-2)
// ============================================================
// Shared helpers every create/edit form uses to show clear,
// specific validation errors. No magic — just a few functions
// pages can call directly.
//
// USAGE — client-side (before submit):
//   FF_Validate.clear(form);
//   if (!amount || Number(amount) < 0) {
//       FF_Validate.field(form, 'amount', 'Payment amount cannot be negative');
//   }
//   if (FF_Validate.hasErrors(form)) {
//       FF_Validate.scrollToFirst(form);
//       return;
//   }
//
// USAGE — after API call returns VALIDATION_ERROR:
//   const res = await FF_Api.post('/api/v1/leases', body);
//   if (!res.success && res.error?.code === 'VALIDATION_ERROR') {
//       FF_Validate.applyApi(form, res.error);   // wires up fields[]
//       return;
//   }
//
// Element markup — pages must render an error slot for each field:
//   <input id="daily_rate" name="daily_rate" ...>
//   <div class="field-error" data-error-for="daily_rate"></div>
//
// Or opt into auto-slot (FF_Validate creates the slot on-the-fly):
//   <input id="daily_rate" name="daily_rate" ...>
//   — a <div class="field-error"> will be inserted after the input.
//
// For form-level errors (e.g. unbalanced JE):
//   <div class="form-error-banner" data-form-error></div>
// ============================================================

const FF_Validate = {
    /**
     * Clear every field + form-level error in the given form.
     * Accepts a <form> element, a selector, or null → document.
     */
    clear(form) {
        const root = typeof form === 'string'
            ? document.querySelector(form)
            : (form || document);
        if (!root) return;

        root.querySelectorAll('.field-error').forEach((el) => {
            el.textContent = '';
            el.innerHTML = '';
        });
        root.querySelectorAll('[data-form-error], .form-error-banner')
            .forEach((el) => {
                el.textContent = '';
                el.innerHTML = '';
            });
        root.querySelectorAll('.ff-invalid').forEach((el) => {
            el.classList.remove('ff-invalid', 'is-invalid');
        });
    },

    /**
     * Mark a specific field as invalid and show a message beneath it.
     * Looks up the input by name (preferred) or id.
     */
    field(form, nameOrId, message) {
        const root = typeof form === 'string'
            ? document.querySelector(form)
            : (form || document);
        if (!root || !message) return;

        // Find the input — try name first, then id
        let input = root.querySelector(
            `[name="${CSS.escape(nameOrId)}"]`
        );
        if (!input) {
            input = root.querySelector(`#${CSS.escape(nameOrId)}`);
        }
        if (input) {
            input.classList.add('ff-invalid');
        }

        // Find or create the error slot
        let slot = root.querySelector(
            `.field-error[data-error-for="${CSS.escape(nameOrId)}"]`
        );
        if (!slot && input) {
            // Insert a slot right after the input or its wrapper
            slot = document.createElement('div');
            slot.className = 'field-error';
            slot.setAttribute('data-error-for', nameOrId);
            const container = input.closest('.form-group, .mb-3, .col') || input.parentElement;
            if (container) {
                container.appendChild(slot);
            } else {
                input.insertAdjacentElement('afterend', slot);
            }
        }
        if (slot) {
            slot.innerHTML =
                '<span class="icon">⚠</span>' +
                '<span>' + FF_Validate._escape(message) + '</span>';
        }
    },

    /**
     * Show a form-level error banner at the top of the form.
     */
    banner(form, message, opts = {}) {
        const root = typeof form === 'string'
            ? document.querySelector(form)
            : (form || document);
        if (!root) return;

        let banner = root.querySelector('.form-error-banner, [data-form-error]');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'form-error-banner';
            banner.setAttribute('data-form-error', '');
            root.prepend(banner);
        }
        const title = opts.title || 'Cannot save:';
        banner.innerHTML =
            '<span class="icon">⚠</span>' +
            '<div><strong>' + FF_Validate._escape(title) + '</strong> ' +
            FF_Validate._escape(message) + '</div>';
    },

    /**
     * Apply an API VALIDATION_ERROR response to the form.
     * Accepts the `error` object from FF_Api — can have either
     * `fields: {name: message, ...}` OR `errors: [...]` OR a plain
     * message.
     */
    applyApi(form, errorObj) {
        if (!errorObj) return;
        const fields = errorObj.fields || {};
        let any = false;
        Object.keys(fields).forEach((name) => {
            FF_Validate.field(form, name, fields[name]);
            any = true;
        });
        if (!any && errorObj.message) {
            FF_Validate.banner(form, errorObj.message);
        }
        FF_Validate.scrollToFirst(form);
    },

    /**
     * Scroll the first invalid field into view + focus it.
     * S-ANIMATIONS-PACK Bundle D: also shakes the submit button so
     * the user gets a tactile cue that something failed. Respects
     * prefers-reduced-motion via FF_ShakeForm.
     */
    scrollToFirst(form) {
        const root = typeof form === 'string'
            ? document.querySelector(form)
            : (form || document);
        if (!root) return;

        const firstBad = root.querySelector('.ff-invalid, .is-invalid');
        const firstBanner = root.querySelector('.form-error-banner, [data-form-error]');
        const target = firstBad || firstBanner;
        if (!target) return;

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (firstBad && typeof firstBad.focus === 'function') {
            setTimeout(() => firstBad.focus({ preventScroll: true }), 250);
        }

        // Shake the submit button(s) of the form so the user sees the
        // form rejected their submit. Uses FF_ShakeForm if loaded.
        if (window.FF_ShakeForm && root.querySelectorAll) {
            const submitBtns = root.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitBtns.forEach(btn => window.FF_ShakeForm.shake(btn));
        }
    },

    /**
     * True if any .ff-invalid or non-empty .field-error exists.
     */
    hasErrors(form) {
        const root = typeof form === 'string'
            ? document.querySelector(form)
            : (form || document);
        if (!root) return false;

        if (root.querySelector('.ff-invalid, .is-invalid')) return true;
        const slots = root.querySelectorAll('.field-error');
        for (const s of slots) {
            if (s.textContent.trim() !== '') return true;
        }
        return false;
    },

    /**
     * Internal — escape for safe innerHTML interpolation.
     */
    _escape(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },
};

window.FF_Validate = FF_Validate;


// ============================================================
// 07. FF_Notifications — Alpine factory for the topbar bell + dropdown
// ============================================================
//
// Used by includes/topbar.php as:
//   <div x-data="FF_Notifications()" x-init="init()">
//
// Features:
//   - Initial fetch of recent 10 notifications + unread count
//   - 60s polling for unread count (lightweight)
//   - Mark single read on click-through
//   - Mark all read
//   - Category icon + colour mapping
//   - Cleans up the polling timer on Alpine destroy
//
// Endpoint contract (api/v1/notifications/):
//   GET  index.php?per_page=10  → { items[], pagination, meta.total_unread, total_unread }
//   GET  count.php              → { unread_count }
//   POST mark_read.php { notification_id | mark_all }
//

function FF_Notifications() {
    return {
        open: false,
        loading: false,
        notifications: [],
        unreadCount: 0,
        _pollTimer: null,
        // flat | grouped — persisted to localStorage
        viewMode: 'flat',
        // tracks which category sections are expanded in grouped mode
        // default (key absent) = collapsed, so sections start closed
        expandedGroups: {},
        // MEDIA-1: _initialized gates sound playback so we don't
        // ding the user on first page load just for seeing the
        // current unread total.
        _initialized: false,

        async init() {
            // S-PERF-POLL: guard against Alpine's double init (auto-init from
            // x-data + the explicit init() in includes/topbar.php's x-init).
            if (this._pollTimer) return;

            try { this.viewMode = localStorage.getItem('ff_notif_view') || 'flat'; } catch {}

            // S-PERF-POLL: no initial fetchCount() here any more. topbar.php
            // already computed this exact COUNT server-side and seeds
            // unreadCount from the same x-init attribute — which runs AFTER
            // this, so the fetched value was always overwritten and thrown
            // away. That made it a guaranteed-wasted round trip on every page
            // load. Marking _initialized here means the seeded value is the
            // baseline the first 60s poll compares against, so a genuine
            // increase still dings; without it that first ding is swallowed.
            this._initialized = true;

            // Refresh badge every 60s — lightweight COUNT only.
            this._pollTimer = setInterval(() => { this.fetchCount(); }, 60000);
            // Stop polling when Alpine tears the component down.
            // Without this, navigating away leaks the timer until GC.
            this.$el?.addEventListener?.('alpine:destroyed', () => {
                if (this._pollTimer) clearInterval(this._pollTimer);
            });
        },

        async fetchCount() {
            try {
                const data = await FF_Api.get(FF_Api.url('/api/v1/notifications/count.php'));
                if (data?.success) {
                    const newCount = data.data?.unread_count ?? 0;
                    // MEDIA-1: only ding when the count INCREASES and
                    // only after the first successful poll has primed
                    // _initialized. First-load is always silent.
                    if (this._initialized && newCount > this.unreadCount) {
                        if (window.FF_Sound) FF_Sound.play();
                        // S-ANIMATIONS-PACK Bundle C: ring the bell visually.
                        // Adds .ff-anim-bell-ringing to the SVG icon for one
                        // shake cycle, and .ff-anim-badge-pop to the count
                        // badge so it bounces in when the new total appears.
                        try {
                            const bellSvg = this.$el?.querySelector?.('.notif-bell-btn .nav-icon');
                            const badge   = this.$el?.querySelector?.('.notif-badge');
                            if (bellSvg) {
                                bellSvg.classList.remove('ff-anim-bell-ringing');
                                void bellSvg.offsetWidth;
                                bellSvg.classList.add('ff-anim-bell-ringing');
                                setTimeout(() => bellSvg.classList.remove('ff-anim-bell-ringing'), 600);
                            }
                            if (badge) {
                                badge.classList.remove('ff-anim-badge-pop');
                                void badge.offsetWidth;
                                badge.classList.add('ff-anim-badge-pop');
                                setTimeout(() => badge.classList.remove('ff-anim-badge-pop'), 500);
                            }
                        } catch { /* never block the bell on animation errors */ }
                    }
                    this.unreadCount = newCount;
                    this._initialized = true;
                }
            } catch { /* silent — bell never crashes the page */ }
        },

        async fetchNotifications() {
            this.loading = true;
            // Fetch more items in grouped mode so all categories have representation
            const perPage = this.viewMode === 'grouped' ? 40 : 10;
            try {
                const data = await FF_Api.get(FF_Api.url(`/api/v1/notifications/index.php?per_page=${perPage}`));
                if (data?.success) {
                    this.notifications = data.data?.items ?? [];
                    this.unreadCount = data.data?.total_unread
                                     ?? data.data?.meta?.total_unread
                                     ?? this.unreadCount;
                } else {
                    this.notifications = [];
                }
            } catch {
                this.notifications = [];
            } finally {
                this.loading = false;
            }
        },

        async setView(v) {
            this.viewMode = v;
            try { localStorage.setItem('ff_notif_view', v); } catch {}
            // Refetch since per_page differs between modes
            await this.fetchNotifications();
        },

        toggleGroup(cat) {
            this.expandedGroups = { ...this.expandedGroups, [cat]: !this.expandedGroups[cat] };
        },

        // Returns [{cat, items, unread}] sorted: most unread first, then by total
        groupedEntries() {
            const map = {};
            this.notifications.forEach(n => {
                const cat = n.category || 'system';
                if (!map[cat]) map[cat] = [];
                map[cat].push(n);
            });
            return Object.keys(map)
                .map(cat => ({
                    cat,
                    items:  map[cat],
                    unread: map[cat].filter(n => !n.is_read).length,
                }))
                .sort((a, b) => b.unread - a.unread || b.items.length - a.items.length);
        },

        categoryLabel(cat) {
            const labels = {
                leases: 'Leases', invoices: 'Invoices', payments: 'Payments',
                customers: 'Customers', equipment: 'Equipment', compliance: 'Compliance',
                maintenance: 'Maintenance', damage: 'Damage Claims',
                reservations: 'Reservations', samsara: 'GPS / Samsara',
                accounting: 'Accounting', quickbooks: 'QuickBooks', system: 'System',
            };
            return labels[cat] || (cat.charAt(0).toUpperCase() + cat.slice(1));
        },

        async toggleDropdown() {
            this.open = !this.open;
            if (this.open) {
                // Always refetch on open so we don't show stale data after polling
                await this.fetchNotifications();
            }
        },

        async markRead(id) {
            // Optimistic UI: flip the local flag immediately
            const n = this.notifications.find(x => x.id === id);
            if (n && !n.is_read) {
                n.is_read = true;
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
            try {
                await FF_Api.post(FF_Api.url('/api/v1/notifications/mark_read.php'),
                    { notification_id: id });
            } catch { /* server-side error doesn't undo optimistic UI */ }
        },

        async markAllRead() {
            try {
                const data = await FF_Api.post(FF_Api.url('/api/v1/notifications/mark_read.php'),
                    { mark_all: true });
                if (data?.success) {
                    this.notifications.forEach(n => { n.is_read = true; });
                    this.unreadCount = 0;
                    if (window.FF_Toast) FF_Toast.success('Done', 'All notifications marked as read.');
                }
            } catch {
                if (window.FF_Toast) FF_Toast.error('Error', 'Could not mark notifications as read.');
            }
        },

        // Inline SVG by category — kept compact so the dropdown is one round-trip
        iconFor(category) {
            return _NOTIF_ICONS[category] ?? _NOTIF_ICONS.system;
        },

        categoryClass(n) {
            // Map (type, severity) to a CSS class for the icon background colour
            const t = n.type ?? '';
            if (t === 'compliance.expired'      || n.severity === 'critical') return 'notif-icon--danger';
            if (t === 'compliance.expiring_7'   || n.severity === 'warning')  return 'notif-icon--warning';
            if (t === 'samsara.battery_critical') return 'notif-icon--danger';
            if (t === 'samsara.battery_low')      return 'notif-icon--warning';
            const cat = n.category ?? 'system';
            return 'notif-icon--' + cat;
        },
    };
}

// Inline SVG icons keyed by notification category. Outline Heroicons.
const _NOTIF_ICONS = {
    leases:       '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>',
    invoices:     '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>',
    payments:     '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>',
    customers:    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
    equipment:    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
    compliance:   '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z"/></svg>',
    maintenance:  '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>',
    damage:       '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z"/></svg>',
    reservations: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>',
    samsara:      '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>',
    accounting:   '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z"/></svg>',
    system:       '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
};

window.FF_Notifications = FF_Notifications;


// ============================================================
// 07b. FF_PortalNotifications — same shape, portal endpoints
// ============================================================
//
// Used by app/portal/includes/header.php — talks to the portal-scoped
// notification endpoints which filter by portal_user_id (not user_id).
//

function FF_PortalNotifications() {
    return {
        open: false,
        loading: false,
        notifications: [],
        unreadCount: 0,
        _pollTimer: null,

        async init() {
            await this.fetchCount();
            this._pollTimer = setInterval(() => { this.fetchCount(); }, 60000);
            this.$el?.addEventListener?.('alpine:destroyed', () => {
                if (this._pollTimer) clearInterval(this._pollTimer);
            });
        },

        async fetchCount() {
            try {
                const data = await FF_Api.get(FF_Api.url('/portal/api/notifications/count.php'));
                if (data?.success) {
                    this.unreadCount = data.data?.unread_count ?? 0;
                }
            } catch { /* silent */ }
        },

        async fetchNotifications() {
            this.loading = true;
            try {
                const data = await FF_Api.get(FF_Api.url('/portal/api/notifications/index.php?per_page=10'));
                if (data?.success) {
                    this.notifications = data.data?.items ?? [];
                    this.unreadCount = data.data?.total_unread ?? this.unreadCount;
                } else {
                    this.notifications = [];
                }
            } catch {
                this.notifications = [];
            } finally {
                this.loading = false;
            }
        },

        async toggleDropdown() {
            this.open = !this.open;
            if (this.open) await this.fetchNotifications();
        },

        async markRead(id) {
            const n = this.notifications.find(x => x.id === id);
            if (n && !n.is_read) {
                n.is_read = true;
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
            try {
                await FF_Api.post(FF_Api.url('/portal/api/notifications/mark_read.php'),
                    { notification_id: id });
            } catch { /* silent */ }
        },

        async markAllRead() {
            try {
                const data = await FF_Api.post(FF_Api.url('/portal/api/notifications/mark_read.php'),
                    { mark_all: true });
                if (data?.success) {
                    this.notifications.forEach(n => { n.is_read = true; });
                    this.unreadCount = 0;
                    if (window.FF_Toast) FF_Toast.success('Done', 'All notifications marked as read.');
                }
            } catch {
                if (window.FF_Toast) FF_Toast.error('Error', 'Could not mark notifications as read.');
            }
        },
    };
}

window.FF_PortalNotifications = FF_PortalNotifications;


// ============================================================
// 07c. FF_ChatHubBadge — topbar Chat unread badge (S-CHAT-REBUILD)
// ============================================================
// One number for everything the user has to read in /chat: team DMs +
// groups they're in, plus customer texts (customers.view). Polls
// /api/v1/chat/unread.php every 10s while the tab is visible and catches up
// on refocus. The /chat page itself posts 'ff-chat-unread' events so the
// badge updates the moment a thread is read there.

function FF_ChatHubBadge() {
    return {
        totalUnread: 0,
        _timer: null,
        // S-PERF-POLL-2: synchronous re-entry guard — init() can run twice
        // (Alpine auto-init); a guard set after the first await races.
        _initStarted: false,
        // MEDIA-1: no ding on first load.
        _initialized: false,

        async init() {
            if (this._initStarted) return;
            this._initStarted = true;
            await this.fetchUnread();
            this._timer = setInterval(() => { if (!document.hidden) this.fetchUnread(); }, 10000);
            this._onVis = () => { if (!document.hidden) this.fetchUnread(); };
            this._onPage = (e) => { this.totalUnread = e.detail?.total ?? this.totalUnread; };
            document.addEventListener('visibilitychange', this._onVis);
            window.addEventListener('ff-chat-unread', this._onPage);
            this.$el.addEventListener('alpine:destroyed', () => {
                clearInterval(this._timer);
                document.removeEventListener('visibilitychange', this._onVis);
                window.removeEventListener('ff-chat-unread', this._onPage);
            });
        },

        async fetchUnread() {
            try {
                const res = await FF_Api.get(FF_Api.url('/api/v1/chat/unread.php'));
                if (!res || !res.success) return;
                const total = res.data?.total || 0;
                // Ding when unread grows — unless /chat is open, where it's already on screen.
                const onChat = /\/chat(\/|$|\?)/.test(window.location.pathname);
                if (this._initialized && total > this.totalUnread && !onChat && window.FF_Sound) FF_Sound.play();
                this.totalUnread = total;
                this._initialized = true;
            } catch (e) {
                // Silent — the badge just keeps its last value.
            }
        },
    };
}

window.FF_ChatHubBadge = FF_ChatHubBadge;


// ============================================================
// 07g. FF_RecordPicker — universal search-as-you-type record selector
// ============================================================
// WHY: replaces brittle static <select> dropdowns (which become unusable
//      once a customer/lease/equipment list grows past ~50 rows) with a
//      reusable Alpine factory. Any form that needs to pick a customer,
//      lease, equipment unit, vendor, or invoice can drop in a
//      FF_RecordPicker() component instance with a few config options
//      and get a debounced, keyboard-navigable, pagination-aware picker.
//
// Usage (inside a parent Alpine component):
//   <div x-data="FF_RecordPicker({
//          endpoint:  '/api/v1/customers/index.php',
//          searchParam: 'search',            // or 'q' — varies per API
//          resultKey: 'items',               // default; 'results' for some
//          mapResult: r => ({
//              id:       r.id,
//              label:    r.company_name,
//              sublabel: r.city + ', ' + r.province,
//              raw:      r,
//          }),
//          placeholder: 'Search customers…',
//        })"
//        @record-picked="form.customer_id = $event.detail.id"
//        @record-cleared="form.customer_id = ''">
//     <!-- picker UI renders itself via x-template -->
//     <div x-html="renderPicker()"></div>
//   </div>
//
// Or use the template-less drop-in pattern: include the markup inline
// in the parent page and reference the picker factory's state directly.

function FF_RecordPicker(config) {
    return {
        // ── Configuration (merged from caller) ─────────────────────────
        _cfg: Object.assign({
            endpoint:    '',
            searchParam: 'search',
            resultKey:   'items',        // where in data.data the array lives
            perPage:     10,
            extraParams: '',             // additional query string fragment
            minChars:    1,
            placeholder: 'Search or select…', // visible affordance that this is a picker
            mapResult:   (r) => ({ id: r.id, label: r.name || r.title || ('#' + r.id), sublabel: '', raw: r }),
            initialId:   null,           // for edit forms — pre-select by ID
            initialLabel: '',            // display text if initialId is given
            loadInitial: true,           // fetch initial set (capped 20) on first focus
            initialPerPage: 20,          // max rows in the initial-open list
        }, config || {}),

        // ── State ──────────────────────────────────────────────────────
        query:           '',
        results:         [],
        selected:        null,
        loading:         false,
        showDropdown:    false,
        highlightIdx:    0,
        _searchTimer:    null,
        _initialLoaded:  false, // avoid re-fetching initial set on every focus

        init() {
            // Pre-fill if editing an existing record
            if (this._cfg.initialId && this._cfg.initialLabel) {
                this.selected = { id: this._cfg.initialId, label: this._cfg.initialLabel };
                this.query    = this._cfg.initialLabel;
            }
        },

        async search() {
            const q = (this.query || '').trim();
            if (q.length < this._cfg.minChars) {
                this.results = [];
                this.showDropdown = false;
                return;
            }
            this.loading = true;
            this.showDropdown = true;
            try {
                let url = FF_Api.url(this._cfg.endpoint) +
                          '?' + this._cfg.searchParam + '=' + encodeURIComponent(q) +
                          '&per_page=' + this._cfg.perPage;
                if (this._cfg.extraParams) url += '&' + this._cfg.extraParams;

                const data  = await FF_Api.get(url);
                const items = data.data?.[this._cfg.resultKey] || data.data || [];
                this.results = Array.isArray(items)
                    ? items.map(r => this._cfg.mapResult(r))
                    : [];
                this.highlightIdx = 0;
            } catch (e) {
                this.results = [];
            }
            this.loading = false;
        },

        // Fetch an initial set (capped) on focus/chevron-click so the dropdown
        // opens immediately without requiring the user to type first.
        // WHY: without this, the picker looks like a blank text input — nothing
        // signals it can be clicked to browse options. Capped at initialPerPage
        // (default 20) to keep the initial render fast on large datasets.
        async fetchInitial() {
            if (this._initialLoaded) {
                // Results already cached from a prior focus — just show them.
                if (this.results.length > 0) this.showDropdown = true;
                return;
            }
            this.loading      = true;
            this.showDropdown = true;
            try {
                // Always include extraParams (e.g. status filters) + empty search
                // so the result set is correctly scoped even on initial load.
                let url = FF_Api.url(this._cfg.endpoint) +
                          '?' + this._cfg.searchParam + '=&per_page=' + this._cfg.initialPerPage;
                if (this._cfg.extraParams) url += '&' + this._cfg.extraParams;

                const data  = await FF_Api.get(url);
                const items = data.data?.[this._cfg.resultKey] || data.data || [];
                this.results         = Array.isArray(items) ? items.map(r => this._cfg.mapResult(r)) : [];
                this._initialLoaded  = true;
                this.highlightIdx    = 0;
            } catch (e) {
                this.results = [];
            }
            this.loading = false;
        },

        onInput() {
            // Debounce search by 200ms
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => this.search(), 200);
            // If user clears the text, also clear the selection
            if ((this.query || '').trim() === '' && this.selected) {
                this.clear();
            }
        },

        onFocus() {
            if (this.selected) return; // already picked — don't re-open
            if (this.results.length > 0) {
                this.showDropdown = true; // re-show cached results
            } else if ((this.query || '').trim() !== '') {
                this.search();           // resume mid-type search
            } else if (this._cfg.loadInitial) {
                this.fetchInitial();     // open-on-focus: load initial set
            }
        },

        // Chevron click: toggle the dropdown open/closed.
        onChevronClick() {
            if (this.showDropdown) {
                this.showDropdown = false;
            } else {
                if (this.results.length > 0) {
                    this.showDropdown = true;
                } else if ((this.query || '').trim() !== '') {
                    this.search();
                } else if (this._cfg.loadInitial) {
                    this.fetchInitial();
                }
                // Bring focus to the input so keyboard nav works after click
                this.$el.querySelector('input')?.focus();
            }
        },

        onBlur() {
            // Delay so clicks on results register before dropdown hides
            setTimeout(() => { this.showDropdown = false; }, 180);
        },

        handleKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.highlightIdx = Math.min(this.highlightIdx + 1, this.results.length - 1);
                this.showDropdown = true;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.highlightIdx = Math.max(this.highlightIdx - 1, 0);
            } else if (e.key === 'Enter') {
                if (this.showDropdown && this.results[this.highlightIdx]) {
                    e.preventDefault();
                    this.pick(this.results[this.highlightIdx]);
                }
            } else if (e.key === 'Escape') {
                this.showDropdown = false;
            }
        },

        pick(r) {
            this.selected = r;
            this.query    = r.label;
            this.results  = [];
            this.showDropdown = false;
            // Emit a DOM event so the parent Alpine component can react.
            // WHY $dispatch: Alpine's built-in event bus bubbles the event
            //      up through the scope chain so @record-picked on the
            //      parent <div> receives it cleanly — no global state.
            if (this.$dispatch) this.$dispatch('record-picked', r);
        },

        clear() {
            this.selected = null;
            this.query    = '';
            this.results  = [];
            this.showDropdown = false;
            if (this.$dispatch) this.$dispatch('record-cleared');
        },
    };
}

window.FF_RecordPicker = FF_RecordPicker;


// ============================================================
// 07h. FF_FormDraft — reusable form-draft autosave (localStorage)
// ============================================================
// WHY: multi-section create/edit forms (lease create, lease edit, and any
//      future form that opts in) lose every in-progress edit if the user
//      hits Back, refreshes, or accidentally closes the tab before saving.
//      FF_FormDraft transparently mirrors a form's reactive state into
//      localStorage (debounced) and offers a one-click "restore" on return.
//
// DESIGN
//   • Key  = `ff_draft:<formId>:<entityId>`  (entityId 'new' for create).
//   • Value= { v:<DRAFT_VERSION>, t:<epoch ms>, s:{ <section>:{ d:{...}, t } } }
//            Each PAGE/STEP writes its own SECTION slice into the SAME entity
//            key and merges — so a multi-page wizard never clobbers a sibling
//            step's fields. Single-page forms use the default section ('_main')
//            which holds the whole form.
//   • DRAFT_VERSION tags the payload. On load, a stored draft whose `v` does
//     not match the current DRAFT_VERSION is silently discarded + deleted
//     (so a schema/shape change never mis-applies a stale draft).
//   • exclude[] lists field names (dot-paths allowed, e.g. 'lessor.bpo_amount')
//     that must NEVER be persisted — sensitive fields (password/token/payment)
//     and non-restorable/transient fields (optimistic-lock tokens, picker-owned
//     ids, GPS-fetch metadata).
//   • All storage access is wrapped in try/catch — privacy modes throw on
//     localStorage; the helper then degrades to a no-op (form still works).
//
// USAGE (from inside an Alpine component's init()):
//   this._draft = FF_FormDraft.attach({
//       formId:   'lease-create',
//       entityId: 'new',            // or the record id on edit
//       el:       this.$root,       // listeners + banner mount point
//       model:    this.form,        // the reactive object to track/restore
//       exclude:  ['customer_id', 'equipment_unit_id'],
//       // section: 'step-2',       // wizard pages: a per-step slice key
//       // beforeUnloadWarn: true,  // optional native unload prompt (default off)
//   });
//   // …and in the submit() success branch, once the server confirms the save:
//   if (this._draft) this._draft.clear(true);   // wipe the whole entity key
//
// The returned controller also exposes save()/scheduleSave()/flush()/restore()/
// hasDraft()/showBanner() for callers that need finer control.
const FF_FormDraft = (function () {
    'use strict';

    // Bump DRAFT_VERSION to invalidate every existing draft app-wide (e.g. when
    // a form's field shape changes in a way that would make old drafts wrong).
    const DRAFT_VERSION = '1';
    const PREFIX        = 'ff_draft:';
    const DEBOUNCE_MS   = 500;
    const DEFAULT_SECTION = '_main';

    // ── Safe localStorage wrappers (privacy mode throws) ────────────────────
    function lsGet(k)    { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { window.localStorage.setItem(k, v); return true; } catch (e) { return false; } }
    function lsDel(k)    { try { window.localStorage.removeItem(k); } catch (e) { /* best-effort */ } }

    function deepClone(o) { return JSON.parse(JSON.stringify(o)); }

    // Remove excluded keys from a snapshot. Supports dotted paths so nested
    // fields (e.g. 'lessor.bpo_amount') can be excluded too.
    function stripExcludes(data, excludes) {
        if (!excludes || !excludes.length) return data;
        const clone = deepClone(data);
        excludes.forEach(function (path) {
            const parts = String(path).split('.');
            let node = clone;
            for (let i = 0; i < parts.length - 1 && node; i++) node = node[parts[i]];
            if (node && typeof node === 'object') delete node[parts[parts.length - 1]];
        });
        return clone;
    }

    // Deep-merge `source` INTO `target`, mutating target in place so an Alpine
    // reactive object keeps its identity (replacing it would break reactivity).
    // Only keys present in source are touched — fields absent from the draft
    // (e.g. an excluded optimistic-lock token) keep their current value.
    function assign(target, source) {
        if (!target || !source || typeof source !== 'object') return target;
        Object.keys(source).forEach(function (k) {
            const sv = source[k];
            if (sv && typeof sv === 'object' && !Array.isArray(sv) &&
                target[k] && typeof target[k] === 'object' && !Array.isArray(target[k])) {
                assign(target[k], sv);
            } else {
                target[k] = sv;
            }
        });
        return target;
    }

    function relativeTime(ts) {
        const s = Math.max(0, Math.round((Date.now() - ts) / 1000));
        if (s < 45) return 'just now';
        const m = Math.round(s / 60);
        if (m < 60) return m + (m === 1 ? ' minute' : ' minutes') + ' ago';
        const h = Math.round(m / 60);
        if (h < 24) return h + (h === 1 ? ' hour' : ' hours') + ' ago';
        const d = Math.round(h / 24);
        return d + (d === 1 ? ' day' : ' days') + ' ago';
    }

    function attach(config) {
        config = config || {};
        const formId   = config.formId || 'form';
        const entityId = (config.entityId === undefined || config.entityId === null || config.entityId === '')
            ? 'new' : String(config.entityId);
        const section  = config.section || DEFAULT_SECTION;
        const version  = config.version || DRAFT_VERSION;
        const excludes = config.exclude || [];
        const el       = config.el || null;
        const key      = PREFIX + formId + ':' + entityId;
        const warnUnload = !!config.beforeUnloadWarn;
        const bannerText = config.bannerLabel || 'Unsaved changes';

        // Idempotency guard. Alpine auto-invokes a component's init() method AND
        // re-invokes it via x-init="init()", so attach() commonly runs twice for
        // the same element. Without this, we would stack duplicate banners and
        // double-bind input/change/click + pagehide listeners. Return the
        // already-attached controller instead of wiring a second one.
        const guardProp = '__ffDraft_' + key;
        if (el && el[guardProp]) return el[guardProp];

        // get()/set() — either supplied directly, or derived from `model`.
        const getFn = config.get || (config.model ? function () { return config.model; } : null);
        const setFn = config.set || (config.model ? function (d) { assign(config.model, d); } : null);

        let debounceTimer = null;
        let bannerEl      = null;
        let stopped       = false;   // hard-disable after a confirmed server save

        function readStore() {
            const raw = lsGet(key);
            if (!raw) return null;
            let parsed;
            try { parsed = JSON.parse(raw); } catch (e) { lsDel(key); return null; }
            // Version mismatch → discard silently so a stale-shape draft is
            // never mis-applied.
            if (!parsed || parsed.v !== version) { lsDel(key); return null; }
            return parsed;
        }

        function writeSlice(data) {
            let store = null;
            const raw = lsGet(key);
            if (raw) { try { store = JSON.parse(raw); } catch (e) { store = null; } }
            if (!store || store.v !== version) store = { v: version, t: 0, s: {} };
            const now = Date.now();
            store.s[section] = { d: data, t: now };
            store.t = now;
            lsSet(key, JSON.stringify(store));
        }

        function save() {
            if (stopped || !getFn) return;
            let snapshot;
            try { snapshot = deepClone(getFn()); } catch (e) { return; }
            writeSlice(stripExcludes(snapshot, excludes));
        }

        function scheduleSave() {
            if (stopped) return;
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () { debounceTimer = null; save(); }, DEBOUNCE_MS);
        }

        // Persist any pending debounced write immediately (called on pagehide so
        // a Back/refresh/close within the debounce window doesn't lose the last
        // keystrokes).
        function flush() {
            if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; save(); }
        }

        // Delete the whole entity key. Pass stop=true after a confirmed server
        // save to also disable any further autosave for this page lifecycle —
        // prevents a late click/redirect from resurrecting the just-cleared draft.
        function clear(stop) {
            if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
            if (stop) stopped = true;
            lsDel(key);
            removeBanner();
        }

        function hasDraft() {
            const store = readStore();
            return !!(store && store.s && store.s[section] && store.s[section].d);
        }

        function restore() {
            const store = readStore();
            if (!store || !store.s || !store.s[section]) return false;
            const data = store.s[section].d;
            if (setFn) { try { setFn(data); } catch (e) { /* ignore */ } }
            if (typeof config.onRestore === 'function') { try { config.onRestore(data); } catch (e) {} }
            return true;
        }

        // ── Restore banner ──────────────────────────────────────────────────
        function removeBanner() {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
            bannerEl = null;
        }

        function showBanner() {
            if (!el) return;
            const store = readStore();
            if (!store || !store.s || !store.s[section] || !store.s[section].d) return;
            removeBanner();

            const ts = store.t || store.s[section].t || Date.now();
            const wrap = document.createElement('div');
            wrap.className = 'ff-draft-banner';
            wrap.setAttribute('role', 'status');
            wrap.setAttribute('aria-live', 'polite');
            wrap.style.cssText =
                'display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;' +
                'margin-bottom:1.25rem;padding:.7rem 1rem;border-radius:8px;' +
                'background:var(--color-warning-light,#fff7e6);' +
                'border:1px solid var(--color-warning,#e0a106);' +
                'color:var(--color-warning-text,var(--text-primary,#1a1a1a));' +
                'font-size:.9rem;';

            const msg = document.createElement('span');
            msg.style.cssText = 'flex:1 1 auto;min-width:12rem;';
            msg.innerHTML = '<strong>' + bannerText + '</strong> from ' + relativeTime(ts) +
                ' — restore your in-progress edits?';

            const btnRestore = document.createElement('button');
            btnRestore.type = 'button';
            btnRestore.textContent = 'Restore';
            btnRestore.style.cssText =
                'border:none;cursor:pointer;font-weight:600;font-size:.85rem;' +
                'padding:.38rem .85rem;border-radius:6px;' +
                'background:var(--color-primary,#2563eb);color:var(--text-on-primary,#fff);';

            const btnDiscard = document.createElement('button');
            btnDiscard.type = 'button';
            btnDiscard.textContent = 'Discard';
            btnDiscard.style.cssText =
                'cursor:pointer;font-weight:600;font-size:.85rem;' +
                'padding:.38rem .85rem;border-radius:6px;' +
                'background:transparent;color:var(--text-secondary,#555);' +
                'border:1px solid var(--border-color,#d0d0d0);';

            const btnDismiss = document.createElement('button');
            btnDismiss.type = 'button';
            btnDismiss.setAttribute('aria-label', 'Dismiss');
            btnDismiss.innerHTML = '&times;';
            btnDismiss.style.cssText =
                'cursor:pointer;background:transparent;border:none;line-height:1;' +
                'font-size:1.2rem;color:var(--text-muted,#888);padding:0 .25rem;';

            // stopPropagation is belt-and-suspenders — the banner is mounted as a
            // SIBLING above `el`, so its clicks don't reach the save listeners on
            // `el` anyway; this also guards if a caller mounts it inside instead.
            btnRestore.addEventListener('click', function (e) { e.stopPropagation(); restore(); removeBanner(); });
            btnDiscard.addEventListener('click', function (e) { e.stopPropagation(); clear(false); });
            btnDismiss.addEventListener('click', function (e) { e.stopPropagation(); removeBanner(); });

            wrap.appendChild(msg);
            wrap.appendChild(btnRestore);
            wrap.appendChild(btnDiscard);
            wrap.appendChild(btnDismiss);

            // Mount as a sibling BEFORE the component root when possible.
            if (el.parentNode) el.parentNode.insertBefore(wrap, el);
            else el.insertBefore(wrap, el.firstChild);
            bannerEl = wrap;
        }

        // ── Wire change tracking ────────────────────────────────────────────
        // Bubble-phase listeners fire AFTER Alpine's x-model has updated the
        // model, so save() reads fresh values. `click` covers programmatic
        // toggles (segmented controls, opt-in buttons, GPS-fetch) that don't
        // emit input/change.
        if (el) {
            el.addEventListener('input',  scheduleSave, false);
            el.addEventListener('change', scheduleSave, false);
            el.addEventListener('click',  scheduleSave, false);
        }

        const onHide = function () { flush(); };
        window.addEventListener('pagehide', onHide);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') flush();
        });

        if (warnUnload) {
            window.addEventListener('beforeunload', function (e) {
                if (!stopped && hasDraft()) { e.preventDefault(); e.returnValue = ''; return ''; }
            });
        }

        // On attach: offer to restore a valid existing draft. (A version
        // mismatch was already deleted silently by readStore() inside showBanner.)
        showBanner();

        const controller = {
            key: key, version: version, section: section,
            save: save, scheduleSave: scheduleSave, flush: flush,
            clear: clear, restore: restore, hasDraft: hasDraft,
            showBanner: showBanner, removeBanner: removeBanner,
        };
        if (el) el[guardProp] = controller;
        return controller;
    }

    return {
        attach: attach,
        assign: assign,
        DRAFT_VERSION: DRAFT_VERSION,
        PREFIX: PREFIX,
        // exposed for unit/edge testing
        _stripExcludes: stripExcludes,
        _relativeTime: relativeTime,
    };
})();

window.FF_FormDraft = FF_FormDraft;


// ============================================================
// 08. FF_Search — global search (⌘K / Ctrl+K)
// ============================================================

/** Inline SVG icons keyed by result type. */
const _SEARCH_TYPE_ICONS = {
    customer:  '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
    equipment: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
    lease:     '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>',
    invoice:   '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5z"/></svg>',
    payment:   '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>',
};

const _SEARCH_GROUP_LABELS = {
    customer:    'Customers',
    equipment:   'Equipment',
    lease:       'Leases',
    invoice:     'Invoices',
    payment:     'Payments',
    reservation: 'Reservations',
};

const FF_Search = {

    RECENT_KEY: 'ff-recent-searches',
    MAX_RECENT: 8,

    open() {
        window.dispatchEvent(new Event('ff-search-open'));
    },

    close() {
        window.dispatchEvent(new Event('ff-search-close'));
    },

    /**
     * Execute a search query and render results.
     * Called on input (debounced) and on recent-search click.
     * @param {string} term
     */
    async query(term) {
        term = term.trim();
        const resultsEl = document.getElementById('ff-search-results');
        const recentEl  = document.getElementById('ff-search-recent');

        if (!term) {
            if (resultsEl) resultsEl.innerHTML = '';
            if (recentEl)  this._renderRecent(recentEl);
            return;
        }

        if (recentEl)  recentEl.innerHTML  = '';
        if (resultsEl) resultsEl.innerHTML =
            '<div class="search-empty">Searching&hellip;</div>';

        try {
            const data = await FF_Api.get(
                FF_Api.url('/api/v1/search?q=' + encodeURIComponent(term) + '&limit=10')
            );

            if (!resultsEl) return;

            // SEARCH-1: API now returns grouped results
            // { results: { customers: [], equipment: [], leases: [], ... } }
            // Flatten back to a single array for the modal's _renderResults()
            // which already does its own grouping by item.type.
            let results = [];
            if (data.success && data.data) {
                const raw = data.data.results;
                if (Array.isArray(raw)) {
                    // Legacy flat array shape — keep working if anyone still returns it
                    results = raw;
                } else if (raw && typeof raw === 'object') {
                    // New grouped shape — flatten preserving group order
                    for (const groupKey of Object.keys(raw)) {
                        if (Array.isArray(raw[groupKey])) {
                            results = results.concat(raw[groupKey]);
                        }
                    }
                }
            }

            if (!results.length) {
                resultsEl.innerHTML =
                    `<div class="search-empty">No results for &ldquo;${ffEsc(term)}&rdquo;</div>`;
                return;
            }

            this._renderResults(resultsEl, results);
            this._saveRecent(term);

        } catch {
            if (resultsEl) {
                resultsEl.innerHTML = '<div class="search-empty">Search unavailable.</div>';
            }
        }
    },

    /** Render grouped search results into a container element. */
    _renderResults(container, results) {
        // Group results by type, preserving server order within each group
        const groups = {};
        for (const item of results) {
            (groups[item.type] ??= []).push(item);
        }

        let html = '';
        for (const [type, items] of Object.entries(groups)) {
            const label = _SEARCH_GROUP_LABELS[type] ?? type;
            const icon  = _SEARCH_TYPE_ICONS[type]  ?? _SEARCH_TYPE_ICONS.invoice;

            html += `<div class="search-result-group-label">${ffEsc(label)}</div>`;

            for (const item of items) {
                // SEARCH-1: new API uses `subtitle`; legacy API used `meta`.
                // Prefer subtitle, fall back to meta.
                const subtitle = item.subtitle ?? item.meta ?? '';
                html +=
                    `<a href="${ffEsc(item.url ?? '#')}" class="search-result-item">` +
                    `<div class="search-result-icon">${icon}</div>` +
                    `<div class="search-result-body">` +
                    `<div class="search-result-title">${ffEsc(item.title ?? '')}</div>` +
                    (subtitle ? `<div class="search-result-meta">${ffEsc(subtitle)}</div>` : '') +
                    `</div></a>`;
            }
        }

        container.innerHTML = html;
    },

    /** Render recent searches from localStorage. */
    _renderRecent(container) {
        const recent = this._getRecent();

        if (!recent.length) {
            container.innerHTML = '';
            return;
        }

        const clockIcon = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>';

        let html = '<div class="search-result-group-label">Recent searches</div>';
        for (const term of recent) {
            html +=
                `<button class="search-result-item" data-recent-term="${ffEsc(term)}" type="button">` +
                `<div class="search-result-icon">${clockIcon}</div>` +
                `<div class="search-result-body">` +
                `<div class="search-result-title">${ffEsc(term)}</div>` +
                `</div></button>`;
        }

        container.innerHTML = html;
    },

    _getRecent() {
        try {
            const stored = localStorage.getItem(this.RECENT_KEY);
            const parsed = JSON.parse(stored ?? '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    },

    _saveRecent(term) {
        try {
            const recent = this._getRecent().filter(t => t !== term);
            recent.unshift(term);
            localStorage.setItem(
                this.RECENT_KEY,
                JSON.stringify(recent.slice(0, this.MAX_RECENT))
            );
        } catch {
            /* storage unavailable */
        }
    },
};

window.FF_Search = FF_Search;


/**
 * FF_SearchWidget — Alpine factory for the inline topbar search dropdown.
 *
 * SEARCH-1 (2026-04-08): replaces the old "button that opens ⌘K modal"
 * topbar pattern with an actual text input + dropdown per spec.
 *
 * API contract (see api/v1/search.php):
 *   Response: { success, data: { query, total, results: { customers:[], equipment:[], leases:[], invoices:[], reservations:[] } } }
 *
 * Each result item: { id, type, title, subtitle, url, badge, badge_class }
 *
 * Consumed via:  <div x-data="FF_SearchWidget()" @click.outside="close()">
 */
window.FF_SearchWidget = function () {
    return {
        query:   '',
        open:    false,
        loading: false,
        total:   0,
        groups: [
            { type: 'customers',    label: 'Customers',    items: [] },
            { type: 'equipment',    label: 'Equipment',    items: [] },
            { type: 'leases',       label: 'Leases',       items: [] },
            { type: 'invoices',     label: 'Invoices',     items: [] },
            { type: 'reservations', label: 'Reservations', items: [] },
        ],

        async search() {
            const term = (this.query || '').trim();

            // Min 2 chars — hide dropdown on shorter input without an API call
            if (term.length < 2) {
                this.open  = false;
                this.total = 0;
                this.groups.forEach(g => (g.items = []));
                return;
            }

            this.loading = true;
            this.open    = true;

            try {
                const resp = await FF_Api.get(
                    FF_Api.url('/api/v1/search?q=' + encodeURIComponent(term) + '&limit=3')
                );

                if (!resp.success) {
                    this.total = 0;
                    this.groups.forEach(g => (g.items = []));
                    return;
                }

                const data = resp.data ?? {};
                this.total = Number(data.total ?? 0);

                // Server is authoritative for per-type ordering and caps.
                const res = data.results ?? {};
                this.groups.forEach((g) => {
                    g.items = Array.isArray(res[g.type]) ? res[g.type] : [];
                });
            } catch (_e) {
                this.total = 0;
                this.groups.forEach(g => (g.items = []));
            } finally {
                this.loading = false;
            }
        },

        /** Close the dropdown without clearing the query (keeps it re-openable). */
        close() {
            this.open = false;
        },

        /**
         * Escape hatch for power users: "See all N results" click falls
         * through to the full-screen ⌘K modal which has pagination /
         * recent searches / keyboard nav already built.
         */
        openFullSearch() {
            this.close();
            try {
                FF_Search.open();
                // Pre-populate the modal's input + re-run the query
                setTimeout(() => {
                    const modalInput = document.getElementById('ff-search-input');
                    if (modalInput) {
                        modalInput.value = this.query;
                        modalInput.focus();
                    }
                    FF_Search.query(this.query);
                }, 50);
            } catch (_e) { /* modal unavailable — noop */ }
        },
    };
};


// ============================================================
// 09. Alpine component factories
//
// These must be on window before Alpine initialises (Alpine
// is defer-loaded, so it initialises after this file runs).
// ============================================================

/**
 * FF_ConfirmModal — backing component for the generic confirm
 * dialog in footer.php.
 *
 * Consumed via:  x-data="FF_ConfirmModal()"
 * Triggered via: FF_Confirm.show({ ... })
 *                → dispatches 'ff-confirm' window event
 *                → @ff-confirm.window="show($event.detail)"
 */
window.FF_ConfirmModal = function () {
    return {
        open:         false,
        title:        '',
        message:      '',
        confirmLabel: 'Confirm',
        dangerMode:   false,
        prompt:       false,        // [M13] when true, show a text input field
        promptValue:  '',           // [M13] text input value
        placeholder:  '',           // [M13] placeholder for the prompt input
        _callback:    null,         // onConfirm function — stored but not reactive
        _cancelCb:    null,         // [M13] onCancel function — for FF_Confirm.ask()

        show(detail) {
            this.title        = detail.title        ?? 'Confirm';
            this.message      = detail.message      ?? '';
            this.confirmLabel = detail.confirmLabel ?? 'Confirm';
            this.dangerMode   = detail.dangerMode   ?? false;
            this.prompt       = detail.prompt       ?? false;
            this.promptValue  = detail.defaultValue ?? '';
            this.placeholder  = detail.placeholder  ?? '';
            this._callback    = typeof detail.onConfirm === 'function'
                                    ? detail.onConfirm
                                    : null;
            this._cancelCb    = typeof detail.onCancel === 'function'
                                    ? detail.onCancel
                                    : null;
            this.open = true;

            // Focus the input field after the modal has rendered.
            if (this.prompt) {
                this.$nextTick(() => {
                    const input = this.$el.querySelector('[data-ff-confirm-input]');
                    if (input) input.focus();
                });
            }
        },

        confirm() {
            if (this._callback) {
                try {
                    // [M13] Pass the prompt value if this is a prompt dialog,
                    // otherwise call the callback with no arguments (preserving
                    // the old FF_Confirm.show() contract).
                    if (this.prompt) {
                        this._callback(this.promptValue);
                    } else {
                        this._callback();
                    }
                } catch (err) {
                    console.error('[FF_ConfirmModal] onConfirm threw:', err);
                }
            }
            this._callback = null;
            this._cancelCb = null;
            this.open = false;
        },

        cancel() {
            // [M13] Invoke the cancel callback if FF_Confirm.ask() is waiting
            // for a Promise resolution. Wrapped in try/catch like confirm().
            if (this._cancelCb) {
                try {
                    this._cancelCb();
                } catch (err) {
                    console.error('[FF_ConfirmModal] onCancel threw:', err);
                }
            }
            this._callback = null;
            this._cancelCb = null;
            this.open = false;
        },
    };
};


/**
 * FF_GuidanceModal — backing component for the explain-and-fix dialog
 * in footer.php (S-BILLING-GUIDANCE).
 *
 * Consumed via:  x-data="FF_GuidanceModal()"
 * Triggered via: FF_Guidance.show({ ... })  — or automatically by
 *                FF_Api._guide() for any error carrying `error.guidance`.
 */
window.FF_GuidanceModal = function () {
    return {
        open:        false,
        title:       '',
        summary:     '',
        cause:       '',
        steps:       [],
        actions:     [],
        detail:      '',
        showDetail:  false,

        show(d) {
            d = d || {};
            this.title      = d.title   || 'This needs your attention';
            this.summary    = d.summary || '';
            this.cause      = d.cause   || '';
            this.steps      = Array.isArray(d.steps) ? d.steps.filter((s) => typeof s === 'string') : [];
            this.detail     = typeof d.detail === 'string' ? d.detail : '';
            this.showDetail = false;
            // Links come from an API payload, so only same-origin destinations
            // are rendered — a relative path, or an absolute URL on this host.
            // An action without a usable URL is dropped rather than rendered
            // as a dead button.
            this.actions = (Array.isArray(d.actions) ? d.actions : [])
                .filter((a) => a && typeof a.label === 'string' && this._safeUrl(a.url))
                .map((a) => ({ label: a.label, url: this._safeUrl(a.url), primary: !!a.primary }));
            this.open = true;
        },

        close() { this.open = false; },

        _safeUrl(url) {
            if (typeof url !== 'string' || url === '') return null;
            try {
                const resolved = new URL(url, window.location.origin);
                if (resolved.origin !== window.location.origin) return null;
                if (resolved.protocol !== 'http:' && resolved.protocol !== 'https:') return null;
                return resolved.href;
            } catch (err) {
                return null;
            }
        },
    };
};


// ============================================================
// FF_EmailCompose — Alpine factory for the global compose modal
//
// EMAIL-1: backing component for includes/partials/email-compose-modal.php
//
// The modal is rendered ONCE in includes/footer.php so any page can
// open it via:
//   window.openEmailCompose({
//     customerId:   5,
//     toEmail:      'john@abc.com',
//     toName:       'John Smith',
//     templateSlug: 'invoice_ready',
//     entityType:   'invoice',
//     entityId:     42,
//   });
//
// Behavior:
//   - Loads templates + customer contacts/documents/invoices on first open
//   - Renders a live preview wrapped in the same brand shell the server uses
//   - Validates required fields client-side before POSTing
//   - Surfaces success/failure via FF_Toast
// ============================================================

/**
 * FF_emailBodyToHtml — render a Compose/Bulk message body for preview.
 *
 * The textarea holds EITHER a template's HTML or a free-typed plain-text
 * message. A plain-text body (no real tag like <p>/<br>/</div>) is escaped
 * and its line breaks become <br>; otherwise every "\n" collapses to a space
 * and the preview shows one run-on paragraph. MUST stay in lock-step with
 * EmailService::isPlainTextBody()/bodyToHtml() (lib/Email/EmailService.php),
 * which applies the identical rule to the email that is actually sent.
 *
 * @param {string} body Raw textarea value
 * @returns {string} HTML for x-html
 */
window.FF_emailBodyToHtml = function (body) {
    const s = String(body ?? '');
    if (/<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?\/?>/i.test(s)) return s;
    return ffEsc(s.replace(/\r\n?/g, '\n').trim()).replace(/\n/g, '<br>');
};

window.FF_EmailCompose = function () {
    return {
        // ── visibility ──
        show: false,
        sending: false,
        showPreview: false,
        showVariables: false,
        showDocumentPicker: false,
        showInvoicePicker: false,

        // ── pre-filled context ──
        customerId: null,
        entityType: null,
        entityId:   null,

        // ── form fields ──
        toEmail: '',
        toName:  '',
        replyTo: '',
        subject: '',
        bodyHtml: '',
        selectedTemplateId: '',

        // ── data ──
        templates: [],
        contacts: [],
        availableDocs: [],
        invoices: [],
        payNow: null,
        availableVariables: [],
        attachments: [],
        defaultReplyTo: '',
        docSearch: '',

        // Helpful for x-html preview inside the modal
        get bodyHtmlPreview() {
            // Show a minimal company shell so the user can see structure
            const company = (window.FF_COMPANY_NAME) || 'FleetForge';
            return '<div style="background:#1c1c1a;color:#fff;padding:12px 16px;font-family:Arial,Helvetica,sans-serif;font-weight:600;font-size:14px;">'
                + this.ffEsc(company)
                + '</div>'
                + '<div style="padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1c1c1a;background:#fff;line-height:1.6;">'
                // Plain-text bodies keep their line breaks (same rule the server applies on send).
                + (this.bodyHtml ? window.FF_emailBodyToHtml(this.bodyHtml) : '<em style="color:#999;">No body yet…</em>')
                + '</div>';
        },

        get filteredDocs() {
            const q = (this.docSearch || '').toLowerCase().trim();
            if (!q) return this.availableDocs;
            return this.availableDocs.filter((d) =>
                ((d.name || '') + ' ' + (d.entity_label || '')).toLowerCase().includes(q)
            );
        },

        ffEsc(s) { return ffEsc(s); },

        // ── opener ──
        async open(opts) {
            opts = opts || {};
            this.reset();

            this.customerId = opts.customerId || null;
            this.entityType = opts.entityType || null;
            this.entityId   = opts.entityId   || null;
            this.toEmail    = opts.toEmail || '';
            this.toName     = opts.toName  || '';
            this.subject    = opts.subject || '';
            this.bodyHtml   = opts.body    || '';

            // Always show modal first so the user sees the spinner/empty state
            this.show = true;

            // Parallel preload of templates + contacts/docs/invoices
            const promises = [this.loadTemplates()];
            if (this.customerId || this.entityType) {
                promises.push(this.loadAttachmentsAndContacts());
            }
            await Promise.all(promises);

            // S-INVOICE-PDF: composing FROM an invoice's own page should
            // default to attaching that invoice's own PDF — the operator
            // opened this modal specifically to email THIS invoice, not to
            // go hunting for it in the "Attach Invoice PDF" list below.
            // this.invoices was just populated by loadAttachmentsAndContacts()
            // above (it lists every invoice for this.customerId, which
            // show.php always passes), so no extra fetch is needed to find
            // the row. If it has no PDF yet, generate one now rather than
            // silently attaching nothing — api/v1/email/send.php drops an
            // invoice_id attachment whose pdf_path is still empty with no
            // error, so skipping this would mean "Email Invoice" quietly
            // sends without the PDF the operator clicked the button to send.
            if (this.entityType === 'invoice' && this.entityId) {
                const self = this.invoices.find((inv) => inv.id === this.entityId);
                if (self && self.has_pdf) {
                    this.addInvoiceAttachment(self);
                } else if (self) {
                    try {
                        const r = await FF_Api.post(FF_Api.url('/api/v1/invoices/generate_pdf.php'), { id: self.id });
                        if (r && r.success) this.addInvoiceAttachment(self);
                    } catch (e) {
                        console.error('[FF_EmailCompose] auto-generate PDF failed', e);
                    }
                }
            }

            // If a template slug was passed in, find + activate it
            if (opts.templateSlug) {
                const t = this.templates.find((x) => x.slug === opts.templateSlug);
                if (t) {
                    this.selectedTemplateId = t.id;
                    await this.loadTemplate();
                }
            }
        },

        async loadTemplates() {
            try {
                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/'));
                if (r && r.success) {
                    this.templates = r.data.templates || [];
                }
            } catch (e) {
                console.error('[FF_EmailCompose] loadTemplates failed', e);
            }
        },

        async loadAttachmentsAndContacts() {
            try {
                const params = new URLSearchParams();
                if (this.customerId) params.set('customer_id', this.customerId);
                if (this.entityType) params.set('entity_type', this.entityType);
                if (this.entityId)   params.set('entity_id',   this.entityId);

                const r = await FF_Api.get(FF_Api.url('/api/v1/email/attachments/available.php?' + params.toString()));
                if (r && r.success) {
                    this.availableDocs = r.data.documents || [];
                    this.invoices      = r.data.invoices  || [];
                    this.contacts      = r.data.contacts  || [];
                    // S-QBO-INVOICE-PAYNOW: send adds a Pay now button for a payable invoice.
                    this.payNow        = r.data.pay_now   || null;

                    // Auto-pick the first contact email if none was supplied
                    if (!this.toEmail && this.contacts.length > 0) {
                        this.toEmail = this.contacts[0].email;
                        this.toName  = this.contacts[0].name;
                    }
                }
            } catch (e) {
                console.error('[FF_EmailCompose] loadAttachmentsAndContacts failed', e);
            }
        },

        async loadTemplate() {
            if (!this.selectedTemplateId) {
                // Reset to a clean slate if user picks "Custom message"
                return;
            }
            try {
                const params = new URLSearchParams();
                params.set('template_id', this.selectedTemplateId);
                if (this.customerId) params.set('customer_id', this.customerId);
                if (this.entityType) params.set('entity_type', this.entityType);
                if (this.entityId)   params.set('entity_id',   this.entityId);

                const r = await FF_Api.get(FF_Api.url('/api/v1/email/templates/preview.php?' + params.toString()));
                if (r && r.success) {
                    this.subject  = r.data.subject  || '';
                    this.bodyHtml = r.data.body_html || '';
                    this.availableVariables = r.data.variables || [];
                } else if (r && r.error) {
                    FF_Toast.error('Template', r.error.message || 'Could not load template.');
                }
            } catch (e) {
                console.error('[FF_EmailCompose] loadTemplate failed', e);
                FF_Toast.error('Template', 'Could not load template.');
            }
        },

        fillContact(c) {
            this.toEmail = c.email;
            this.toName  = c.name;
        },

        insertVariable(v) {
            const ta = this.$refs.bodyField;
            const token = '{' + v + '}';
            if (ta && typeof ta.selectionStart === 'number') {
                const start = ta.selectionStart;
                const end   = ta.selectionEnd;
                this.bodyHtml = (this.bodyHtml || '').slice(0, start) + token + (this.bodyHtml || '').slice(end);
                this.$nextTick(() => {
                    ta.focus();
                    ta.selectionStart = ta.selectionEnd = start + token.length;
                });
            } else {
                this.bodyHtml = (this.bodyHtml || '') + token;
            }
        },

        addDocumentAttachment(doc) {
            // De-dupe by source_type + source_id
            const key = 'document:' + doc.source_id;
            if (this.attachments.some((a) => a._key === key)) return;
            this.attachments.push({
                _key: key,
                document_id: doc.source_id,
                name: doc.name,
                size: doc.size,
                type: doc.type,
            });
            FF_Toast.success('Attached', doc.name);
        },

        addInvoiceAttachment(inv) {
            const key = 'invoice:' + inv.id;
            if (this.attachments.some((a) => a._key === key)) return;
            this.attachments.push({
                _key: key,
                invoice_id: inv.id,
                name: inv.invoice_number + '.pdf',
                size: null,
                type: 'application/pdf',
            });
            FF_Toast.success('Attached', inv.invoice_number + '.pdf');
        },

        async uploadAttachment(ev) {
            const files = ev?.target?.files;
            if (!files || files.length === 0) return;
            for (let i = 0; i < files.length; i++) {
                const f = files[i];
                const fd = new FormData();
                fd.append('file', f);
                try {
                    const r = await FF_Api.upload(FF_Api.url('/api/v1/email/attachments/upload.php'), fd);
                    if (r && r.success) {
                        this.attachments.push({
                            _key: 'upload:' + r.data.upload_path,
                            upload_path: r.data.upload_path,
                            name: r.data.name,
                            size: r.data.size,
                            type: r.data.type,
                        });
                        FF_Toast.success('Uploaded', r.data.name);
                    } else {
                        FF_Toast.error('Upload failed', (r && r.error && r.error.message) || 'Unknown error');
                    }
                } catch (e) {
                    console.error('[FF_EmailCompose] upload failed', e);
                    FF_Toast.error('Upload failed', 'Network error');
                }
            }
            ev.target.value = ''; // allow re-selecting the same file
        },

        removeAttachment(i) {
            this.attachments.splice(i, 1);
        },

        formatSize(bytes) {
            if (!bytes && bytes !== 0) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        },

        togglePreview() {
            this.showPreview = !this.showPreview;
        },

        async send() {
            if (!this.toEmail || !this.subject || !this.bodyHtml) {
                FF_Toast.error('Missing fields', 'Please fill in To, Subject, and Message.');
                return;
            }
            this.sending = true;
            try {
                const payload = {
                    to_email:    this.toEmail,
                    to_name:     this.toName,
                    reply_to:    this.replyTo,
                    subject:     this.subject,
                    body_html:   this.bodyHtml,
                    customer_id: this.customerId,
                    entity_type: this.entityType,
                    entity_id:   this.entityId,
                    template_id: this.selectedTemplateId || null,
                    attachments: this.attachments.map((a) => {
                        const o = {};
                        if (a.document_id) o.document_id = a.document_id;
                        if (a.invoice_id)  o.invoice_id  = a.invoice_id;
                        if (a.upload_path) {
                            o.upload_path = a.upload_path;
                            o.name = a.name;
                            o.size = a.size;
                            o.type = a.type;
                        }
                        return o;
                    }),
                };
                const r = await FF_Api.post(FF_Api.url('/api/v1/email/send.php'), payload);
                if (r && r.success) {
                    FF_Toast.success('Email sent', 'Delivered to ' + this.toEmail);
                    // Notify the page so it can refresh its email history tab
                    window.dispatchEvent(new CustomEvent('ff-email-sent', {
                        detail: { log_id: r.data.log_id, customer_id: this.customerId },
                    }));
                    this.close();
                } else {
                    FF_Toast.error('Send failed', (r && r.error && r.error.message) || 'Unknown error');
                }
            } catch (e) {
                console.error('[FF_EmailCompose] send failed', e);
                FF_Toast.error('Send failed', 'Network error');
            } finally {
                this.sending = false;
            }
        },

        close() {
            this.show = false;
            // Defer reset until the close animation finishes
            setTimeout(() => this.reset(), 200);
        },

        reset() {
            this.toEmail = '';
            this.toName  = '';
            this.replyTo = '';
            this.subject = '';
            this.bodyHtml = '';
            this.selectedTemplateId = '';
            this.attachments = [];
            this.contacts = [];
            this.availableDocs = [];
            this.invoices = [];
            this.payNow = null;
            this.availableVariables = [];
            this.showPreview = false;
            this.showVariables = false;
            this.showDocumentPicker = false;
            this.showInvoicePicker = false;
            this.docSearch = '';
            this.customerId = null;
            this.entityType = null;
            this.entityId   = null;
            this.sending = false;
        },
    };
};

// Convenience opener — call from any page:
//   window.openEmailCompose({customerId, toEmail, toName, templateSlug, entityType, entityId})
//
// Uses an Alpine custom event so the modal can be opened before
// Alpine has hydrated. The @ff-email-compose.window listener inside
// the modal partial picks it up.
window.openEmailCompose = function (opts) {
    window.dispatchEvent(new CustomEvent('ff-email-compose', { detail: opts || {} }));
};


// ============================================================
// RESPONSIVE-1 — ApexCharts global responsive + theme patch
//
// Monkey-patches the ApexCharts constructor so every chart instance
// automatically receives:
//   1. a `responsive` breakpoint that trims height, moves legends to the
//      bottom, and disables dataLabels on mobile; and
//   2. a `theme.mode` + `tooltip.theme` matching the active app theme, so
//      hover tooltips are legible in dark mode (ApexCharts otherwise
//      defaults to a light tooltip — white text on white = unreadable in
//      night mode). Fixes it across EVERY chart in one place.
// Keeps page-level chart code untouched.
// ============================================================
(function patchApexChartsForResponsive() {
    if (typeof window === 'undefined' || typeof window.ApexCharts !== 'function') return;
    if (window.ApexCharts.__ff_responsive_patched__) return;

    const DEFAULT_RESPONSIVE = [{
        breakpoint: 768,
        options: {
            chart: { height: 250 },
            legend: { position: 'bottom' },
            dataLabels: { enabled: false },
        },
    }];

    // WHY: 'dark' is the default theme; only an explicit 'light' attribute
    // flips us to light mode. Read at construction time.
    function _isDarkTheme() {
        return (document.documentElement.getAttribute('data-theme') || 'dark') !== 'light';
    }

    const Original = window.ApexCharts;
    function Patched(el, opts) {
        try {
            opts = opts || {};
            const existing = Array.isArray(opts.responsive) ? opts.responsive.slice() : [];
            const hasMobile = existing.some((r) => r && r.breakpoint && r.breakpoint <= 768);
            if (!hasMobile) {
                opts.responsive = existing.concat(DEFAULT_RESPONSIVE);
            }

            // ── Tooltip legibility ──────────────────────────────────
            // ApexCharts defaults tooltips to a LIGHT theme (light bg +
            // pale text) → unreadable in night mode. Match the tooltip to
            // the app theme unless the page explicitly set its own, so we
            // never clobber intentional config. Scoped to the tooltip only
            // (bars/axes already render correctly in dark mode).
            const dark = _isDarkTheme();
            opts.tooltip = opts.tooltip || {};
            if (typeof opts.tooltip.theme === 'undefined') {
                opts.tooltip.theme = dark ? 'dark' : 'light';
            }

            // ── Data-label legibility ───────────────────────────────
            // ApexCharts bar data labels default to white text with no
            // shadow — readable inside a colored bar, but invisible when a
            // short bar pushes the label onto a light background (light
            // mode). Add a subtle dark halo so labels stay legible on ANY
            // background, in both themes. Only injected when the chart did
            // not set its own dropShadow, and never changes label colors or
            // whether labels are enabled — so it can't alter other charts.
            opts.dataLabels = opts.dataLabels || {};
            if (typeof opts.dataLabels.dropShadow === 'undefined') {
                opts.dataLabels.dropShadow = {
                    enabled: true, top: 0, left: 0, blur: 2, color: '#000', opacity: 0.5,
                };
            }
        } catch (_e) { /* noop — never break chart rendering */ }
        const _inst = new Original(el, opts);
        // S-LUX-2: register every instance so a theme toggle can re-theme
        // it in place (ff-chart-theme.js listens on 'ff:theme-changed').
        try { if (typeof window.FF_CHART_REGISTER === 'function') window.FF_CHART_REGISTER(_inst); } catch (_e2) {}
        return _inst;
    }
    Patched.prototype = Original.prototype;
    // ApexCharts 3.45 bug: its parent-resize handler schedules ctx.update()
    // 150 ms later, and destroy() never clears that timer. Hiding a chart
    // (x-show) and destroying it inside that window made the dead chart
    // re-create its SVG in the element afterwards — a stale zero-width chart
    // that pushed the NEXT chart drawn there out of view (Reports: blank first
    // chart after an empty date range). Clear the pending timer on destroy.
    if (typeof Original.prototype.destroy === 'function' && !Original.prototype.__ff_destroy_patched__) {
        const _origDestroy = Original.prototype.destroy;
        Original.prototype.destroy = function () {
            try { if (this.w && this.w.globals) window.clearTimeout(this.w.globals.resizeTimer); } catch (_e3) {}
            return _origDestroy.apply(this, arguments);
        };
        Original.prototype.__ff_destroy_patched__ = true;
    }
    // Copy static members (exec, initOnLoad, etc.)
    Object.keys(Original).forEach((k) => { Patched[k] = Original[k]; });
    Patched.__ff_responsive_patched__ = true;
    window.ApexCharts = Patched;
})();


// ============================================================
// 09b. FF_TabHash — URL-hash tab persistence + scroll restoration
// ============================================================
// WHY: Alpine's x-show tab components default to 'overview' on every
// page load.  This module persists the active tab in the URL hash
// (e.g. equipment/show?id=1#maintenance) and saves per-tab scroll
// positions in sessionStorage so a refresh brings the user back to
// exactly where they were.
//
// Usage pattern (in each Alpine component's init() / loadXxx()):
//
//   const TABS = ['overview', 'leases', ...];
//   const _init = FF_TabHash.init(TABS, 'overview');  // read hash
//   this.activeTab = _init;               // set BEFORE $watch so watcher
//   FF_TabHash.write(_init);              //   doesn't fire for this change
//   _onTabEnter(_init);                   // trigger lazy-load
//   FF_TabHash.watchUnload(() => this.activeTab);
//   this.$nextTick(() => FF_TabHash.restoreScroll(_init));
//   let _prev = _init;
//   this.$watch('activeTab', (tab) => {
//       FF_TabHash.onSwitch(_prev, tab);  // save scroll, write hash, scroll top
//       _prev = tab;
//       _onTabEnter(tab);
//   });
//
// For index pages that use a setTab(tab) method:
//   setTab(tab) {
//       FF_TabHash.save(this.activeTab);  // save before changing
//       this.activeTab = tab;
//       FF_TabHash.write(tab);
//       // ... clear filters, this.load() etc.
//   }
window.FF_TabHash = (function () {
    'use strict';

    function _key(tab) {
        // Path+search scopes keys so different pages never collide.
        return 'ffscroll:' + location.pathname + location.search + ':' + tab;
    }

    return {
        /**
         * Read the URL hash and return the matching tab, or defaultTab.
         * Call at the TOP of init(), BEFORE registering $watch, so the
         * initial assignment does not trigger the watcher.
         */
        init: function (validTabs, defaultTab) {
            var h = location.hash.slice(1);
            return (h && validTabs.indexOf(h) !== -1) ? h : defaultTab;
        },

        /**
         * Like init(), but for pages that ALSO accept a server-side `?tab=`
         * deep-link (e.g. Settings, linked to as settings?tab=integrations).
         *
         * - If `?tab=` is present, it is authoritative: we promote it into the
         *   URL hash and STRIP the query param, so every subsequent refresh is
         *   driven by the hash (and reflects the user's later tab clicks).
         * - Otherwise we restore from the hash, falling back to serverDefault.
         *
         * `serverDefault` is the PHP-validated initial tab (already
         * permission-checked), used as the value when `?tab=` is present.
         */
        initWithQuery: function (validTabs, serverDefault) {
            var params = new URLSearchParams(location.search);
            if (params.has('tab')) {
                params.delete('tab');
                var qs = params.toString();
                history.replaceState(
                    null, '',
                    location.pathname + (qs ? '?' + qs : '') + '#' + serverDefault
                );
                return serverDefault;
            }
            var t = this.init(validTabs, serverDefault);
            this.write(t);
            return t;
        },

        /** Write tab into the URL hash — no navigation, no scroll jump. */
        write: function (tab) {
            if (tab) history.replaceState(null, '', '#' + tab);
        },

        /** Persist scrollY for `tab` to sessionStorage. */
        save: function (tab) {
            if (!tab) return;
            try { sessionStorage.setItem(_key(tab), String(Math.round(window.scrollY))); } catch (_) {}
        },

        /**
         * Restore saved scroll for `tab`.
         * Call from $nextTick so x-show content is painted before scrollTo.
         * Uses double-RAF to survive CSS transition completion timing.
         */
        restoreScroll: function (tab) {
            var y = parseInt(sessionStorage.getItem(_key(tab)) || '0', 10);
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    window.scrollTo({ top: y, behavior: 'instant' });
                });
            });
        },

        /**
         * Called inside $watch on a user-initiated tab switch.
         * Saves old tab's scroll, writes new hash, scrolls page to top
         * so the user sees the start of the incoming tab's content.
         */
        onSwitch: function (prevTab, nextTab) {
            this.save(prevTab);
            this.write(nextTab);
            requestAnimationFrame(function () {
                window.scrollTo({ top: 0, behavior: 'instant' });
            });
        },

        /**
         * S-RECORD-REDESIGN — onSwitch for a page whose tab bar is sticky
         * (.tab-bar--sticky, record pages). Jumping to the very top threw
         * the tab bar back down under the hero on every click; instead,
         * only when the reader has scrolled past the tab bar's natural
         * spot, scroll back to exactly where it sticks — the bar stays put
         * and the new tab starts right under it.
         * @param {HTMLElement} bar  the tab bar. Its un-stuck position is read
         *                           from an empty `.tab-anchor` placed right
         *                           before it (a stuck bar's own rect is where
         *                           it sticks, not where it sits); without one,
         *                           from its parent's top.
         */
        onSwitchKeep: function (prevTab, nextTab, bar) {
            this.save(prevTab);
            this.write(nextTab);
            requestAnimationFrame(function () {
                if (!bar || !bar.parentElement) return;
                var prev = bar.previousElementSibling;
                var anchor = (prev && prev.classList.contains('tab-anchor')) ? prev : bar.parentElement;
                var stick = parseFloat(getComputedStyle(bar).top);
                var off = isNaN(stick) ? 72 : stick;
                var y = anchor.getBoundingClientRect().top + window.scrollY - off;
                if (window.scrollY > y) window.scrollTo({ top: y, behavior: 'instant' });
            });
        },

        /**
         * Register a pagehide listener that saves scrollY before leaving.
         * @param {Function} getTab  Returns the current tab name string.
         */
        watchUnload: function (getTab) {
            window.addEventListener('pagehide', function () {
                var tab = getTab();
                if (!tab) return;
                try { sessionStorage.setItem(_key(tab), String(Math.round(window.scrollY))); } catch (_) {}
            });
        },
    };
}());

// ============================================================
// 10. DOM-ready boot
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // Apply stored theme preference (may override server-rendered attribute)
    FF_Theme.init();

    // ── Sidebar scroll persistence ─────────────────────────
    // WHY: the sidebar is a full-height scrollable element. Without this,
    // every page load resets scrollTop to 0, losing the user's position
    // when they click a link while scrolled down the nav.
    (function () {
        const KEY = 'ff-sidebar-scroll';
        const el  = document.getElementById('ff-sidebar');
        if (!el) return;

        // Restore saved position immediately on load
        const saved = sessionStorage.getItem(KEY);
        if (saved) el.scrollTop = parseInt(saved, 10) || 0;

        // Save position just before the page unloads (navigation or close)
        window.addEventListener('pagehide', function () {
            sessionStorage.setItem(KEY, String(el.scrollTop));
        });
    })();
    // ──────────────────────────────────────────────────────

    // ── Global keyboard shortcuts ──────────────────────────

    document.addEventListener('keydown', function (e) {
        // ⌘K / Ctrl+K → open global search
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            FF_Search.open();
        }
    });

    // ── Search trigger button ──────────────────────────────

    document.getElementById('ff-search-trigger')
        ?.addEventListener('click', () => FF_Search.open());

    // ── Search input: debounced query ──────────────────────

    const searchInput = document.getElementById('ff-search-input');
    if (searchInput) {
        const debouncedQuery = ffDebounce(
            (val) => FF_Search.query(val),
            280
        );

        searchInput.addEventListener('input', function () {
            debouncedQuery(this.value);
        });

        // Show recent searches on focus when input is empty
        searchInput.addEventListener('focus', function () {
            if (!this.value.trim()) {
                FF_Search._renderRecent(document.getElementById('ff-search-recent'));
            }
        });
    }

    // ── Search results: event delegation (close on result click) ──

    document.getElementById('ff-search-results')?.addEventListener('click', function (e) {
        if (e.target.closest('.search-result-item')) {
            FF_Search.close();
        }
    });

    // ── Recent search term clicks ──────────────────────────

    document.getElementById('ff-search-recent')?.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-recent-term]');
        if (!btn) return;

        const term = btn.dataset.recentTerm;
        if (searchInput) searchInput.value = term;
        FF_Search.query(term);
    });

    // ── Notification bell + dropdown ───────────────────────
    // FF_Notifications is now an Alpine factory consumed by includes/topbar.php
    // via x-data="FF_Notifications()" — no DOM-ready wiring needed here.

    // ── Mobile sidebar overlay ─────────────────────────────
    // The overlay element is rendered by app.js (not in PHP templates)
    // so Alpine's sidebarOpen toggle can also dismiss the sidebar.

    let _sidebarOverlay = null;

    function getSidebarOverlay() {
        if (!_sidebarOverlay) {
            // RESPONSIVE-1: prefer the static overlay rendered by header.php so
            // we don't create a duplicate — fall back to dynamic creation for
            // legacy pages that haven't been updated.
            _sidebarOverlay = document.querySelector('.sidebar-overlay');
            if (!_sidebarOverlay) {
                _sidebarOverlay = document.createElement('div');
                _sidebarOverlay.className = 'sidebar-overlay';
                document.body.appendChild(_sidebarOverlay);

                _sidebarOverlay.addEventListener('click', () => {
                    // Close sidebar by clicking overlay — we need to reach Alpine's
                    // sidebarOpen. The cleanest way is to click the close button or
                    // dispatch a custom event and let Alpine handle it.
                    document.querySelector('.topbar-menu-btn')?.click();
                });
            }
        }
        return _sidebarOverlay;
    }

    // Watch for sidebar open/close on mobile using MutationObserver
    const sidebar = document.getElementById('ff-sidebar');
    if (sidebar && window.innerWidth < 1024) {
        const observer = new MutationObserver(() => {
            const isOpen = sidebar.classList.contains('is-open');
            getSidebarOverlay().classList.toggle('is-visible', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        });
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    }

    // ── S-DASHBOARD-CHART-POLISH — ApexCharts reflow on sidebar toggle ──
    // When Alpine flips sidebarOpen, .sidebar gains/loses .is-open and
    // .app-main gains/loses .sidebar-collapsed. The width of .app-main
    // changes by ~176px (240 - 64), so any ApexCharts living inside it
    // still draws to the old width and leaves whitespace or overflows.
    //
    // We do two things AFTER the CSS transition finishes (~200ms):
    //   1) dispatch a window resize event — every chart with
    //      redrawOnWindowResize:true (which the dashboard sets) re-fits
    //   2) fallback: iterate window.FF_DashboardCharts (registered by
    //      the dashboard component) and call .render() on each, so pages
    //      that didn't set the redraw flag still reflow.
    //
    // Always active (not gated to mobile) because the primary use case
    // is desktop sidebar expand/collapse.
    if (sidebar) {
        let _reflowTimer = null;
        function FF_ReflowCharts() {
            if (_reflowTimer) return;        // coalesce rapid class flips
            _reflowTimer = setTimeout(() => {
                _reflowTimer = null;
                try { window.dispatchEvent(new Event('resize')); } catch (_e) {}
                const charts = window.FF_DashboardCharts;
                if (charts && typeof charts === 'object') {
                    Object.values(charts).forEach((ch) => {
                        try { ch && typeof ch.render === 'function' && ch.render(); } catch (_e) {}
                    });
                }
            }, 260);                         // 200ms transition + 60ms buffer
        }

        const reflowObserver = new MutationObserver((mutations) => {
            for (const m of mutations) {
                if (m.attributeName === 'class') { FF_ReflowCharts(); break; }
            }
        });
        reflowObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

        // Also watch .app-main since its .sidebar-collapsed class is the
        // signal Alpine commits on the other side of the toggle.
        const appMain = document.querySelector('.app-main');
        if (appMain) {
            reflowObserver.observe(appMain, { attributes: true, attributeFilter: ['class'] });
        }
    }

    // Re-check on resize (collapse overlay logic on wide screens)
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) {
            if (_sidebarOverlay) {
                _sidebarOverlay.classList.remove('is-visible');
            }
            document.body.style.overflow = '';
        }
    });

    // ── Nav group toggle (collapsible sidebar sub-items) ──
    document.querySelectorAll('.nav-group > .nav-item').forEach(function (parentLink) {
        var arrow = parentLink.querySelector('.nav-group-arrow');
        if (!arrow) return;

        arrow.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            parentLink.closest('.nav-group').classList.toggle('is-open');
        });
    });

});

/* ============================================================
   S-TOPBAR-CREATE-ALL — `?new=1` deep link → open a page's create modal.

   A few list pages create records through an in-page modal rather than a
   dedicated create.php form (Yards, Journal Entries, Documents). The topbar
   "New" menu links to those pages with `?new=1`; this listener turns that
   into the window event the page's Alpine root already listens for.

   The event name is declared per page via `$createModalEvent` before the
   header include, which header.php emits as body[data-ff-new-event]. Pages
   without the attribute are unaffected.

   WHY alpine:initialized: the listener on the page root (`@evt.window`) is
   not registered until Alpine walks the DOM, so dispatching any earlier —
   DOMContentLoaded included — would fire into a void.
   ============================================================ */
document.addEventListener('alpine:initialized', function () {
    var evt = document.body && document.body.dataset
        ? document.body.dataset.ffNewEvent
        : '';
    if (!evt) return;

    var params;
    try {
        params = new URLSearchParams(window.location.search);
    } catch (e) {
        return;
    }
    if (params.get('new') !== '1') return;

    window.dispatchEvent(new CustomEvent(evt));

    // Drop the flag so a refresh (or a back-navigation) does not re-open the
    // modal the user just dismissed. replaceState keeps the history entry.
    params.delete('new');
    var qs = params.toString();
    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname + (qs ? '?' + qs : '') + window.location.hash
    );
});
