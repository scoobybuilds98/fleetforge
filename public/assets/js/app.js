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
        return res.json();
    },

    /**
     * POST request with a JSON body.
     * @param {string} url
     * @param {object} data
     * @returns {Promise<object>}
     */
    async post(url, data = {}) {
        const res = await fetch(url, {
            method: 'POST',
            headers: this._headers(),
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });
        return res.json();
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
        return res.json();
    },

    /** Build an absolute URL under FF_BASE_PATH */
    url(path) {
        return (window.FF_BASE_PATH ?? '') + path;
    },
};

window.FF_Api = FF_Api;


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
            `${_TOAST_ICONS.close}</button>`;

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
};

window.FF_Confirm = FF_Confirm;


// ============================================================
// 07. FF_Notifications — notification dropdown
// ============================================================

const FF_Notifications = {
    _loaded: false,

    /**
     * Fetch and render the notification list.
     * Idempotent — only fetches once per page load.
     */
    async load() {
        if (this._loaded) return;

        const list = document.getElementById('ff-notification-list');
        if (!list) return;

        try {
            const data = await FF_Api.get(FF_Api.url('/api/v1/notifications?limit=10'));

            if (!data.success) {
                list.innerHTML = '<div class="notification-empty">Could not load notifications.</div>';
                return;
            }

            const items = data.data?.items ?? [];

            if (!items.length) {
                list.innerHTML = '<div class="notification-empty">No new notifications.</div>';
                this._loaded = true;
                return;
            }

            list.innerHTML = items.map(n => {
                const href = ffEsc(n.url ?? '#');
                const unread = n.is_read ? '' : ' is-unread';
                return (
                    `<a href="${href}" class="notification-item${unread}" data-id="${ffEsc(String(n.id ?? ''))}">` +
                    `<div class="notification-item-body">` +
                    `<div class="notification-item-text">${ffEsc(n.message ?? '')}</div>` +
                    `<div class="notification-item-time">${ffEsc(n.time_ago ?? '')}</div>` +
                    `</div></a>`
                );
            }).join('');

            this._loaded = true;

        } catch {
            list.innerHTML = '<div class="notification-empty">Could not load notifications.</div>';
        }
    },

    /** Mark all notifications read, clear the badge dot. */
    async markAllRead() {
        try {
            const data = await FF_Api.post(FF_Api.url('/api/v1/notifications/mark-read'));

            if (!data.success) {
                FF_Toast.error('Error', data.error?.message ?? 'Could not mark notifications as read.');
                return;
            }

            // Remove unread styling
            document.querySelectorAll('.notification-item.is-unread').forEach(el => {
                el.classList.remove('is-unread');
            });

            // Remove the red dot from the bell button
            document.querySelectorAll('.notification-dot').forEach(el => el.remove());

            FF_Toast.success('Done', 'All notifications marked as read.');

        } catch {
            FF_Toast.error('Error', 'Could not mark notifications as read.');
        }
    },
};

window.FF_Notifications = FF_Notifications;


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
    customer:  'Customers',
    equipment: 'Equipment',
    lease:     'Leases',
    invoice:   'Invoices',
    payment:   'Payments',
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
                FF_Api.url('/api/v1/search?q=' + encodeURIComponent(term))
            );

            if (!resultsEl) return;

            const results = data.success ? (data.data?.results ?? []) : [];

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
                html +=
                    `<a href="${ffEsc(item.url ?? '#')}" class="search-result-item">` +
                    `<div class="search-result-icon">${icon}</div>` +
                    `<div class="search-result-body">` +
                    `<div class="search-result-title">${ffEsc(item.title ?? '')}</div>` +
                    (item.meta ? `<div class="search-result-meta">${ffEsc(item.meta)}</div>` : '') +
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
        _callback:    null,     // onConfirm function — stored but not reactive

        show(detail) {
            this.title        = detail.title        ?? 'Confirm';
            this.message      = detail.message      ?? '';
            this.confirmLabel = detail.confirmLabel ?? 'Confirm';
            this.dangerMode   = detail.dangerMode   ?? false;
            this._callback    = typeof detail.onConfirm === 'function'
                                    ? detail.onConfirm
                                    : null;
            this.open = true;
        },

        confirm() {
            if (this._callback) {
                try {
                    this._callback();
                } catch (err) {
                    console.error('[FF_ConfirmModal] onConfirm threw:', err);
                }
            }
            this.open = false;
        },

        cancel() {
            this.open = false;
        },
    };
};


// ============================================================
// 10. DOM-ready boot
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // Apply stored theme preference (may override server-rendered attribute)
    FF_Theme.init();

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

    // ── Notification: load on first bell open ──────────────

    document.querySelector('.topbar-bell-btn')
        ?.addEventListener('click', () => FF_Notifications.load(), { once: true });

    // ── Notification: mark all read ────────────────────────

    document.getElementById('ff-mark-all-read')
        ?.addEventListener('click', () => FF_Notifications.markAllRead());

    // ── Mobile sidebar overlay ─────────────────────────────
    // The overlay element is rendered by app.js (not in PHP templates)
    // so Alpine's sidebarOpen toggle can also dismiss the sidebar.

    let _sidebarOverlay = null;

    function getSidebarOverlay() {
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

    // Re-check on resize (collapse overlay logic on wide screens)
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) {
            if (_sidebarOverlay) {
                _sidebarOverlay.classList.remove('is-visible');
            }
            document.body.style.overflow = '';
        }
    });

});
