/**
 * public/assets/js/ff-animations.js
 *
 * S-ANIMATIONS-PACK — supplemental JS helpers for FleetForge UI
 * animations.
 *
 * Exposes:
 *   FF_CountUp  — animated number tick-up
 *   FF_Confetti — celebration burst (canvas-free, DOM particles)
 *   FF_StatusPulse — convenience for status-badge transitions
 *   FF_ShakeForm — shake a form when validation fails
 *
 * Respects prefers-reduced-motion (skips animation, snaps to final
 * state). Loaded after app.js in includes/header.php.
 */
(function () {
    'use strict';

    const reducedMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ============================================================
    // FF_CountUp — animate a number from 0 (or a start value) to a
    // target, eased over `duration` ms.
    //
    // Usage (vanilla):
    //   FF_CountUp.run(el, { from: 0, to: 1243567, duration: 900, prefix: '$', decimals: 2 });
    //
    // Usage (auto-bind on dashboard load):
    //   <div data-ff-countup data-ff-countup-to="1243567" data-ff-countup-prefix="$"></div>
    //   FF_CountUp.scan();  // call after the element exists
    // ============================================================
    const FF_CountUp = {

        /** Run a count-up on a single element. */
        run(el, opts = {}) {
            if (!el) return;
            const to       = Number(opts.to ?? 0);
            const from     = Number(opts.from ?? 0);
            const duration = Number(opts.duration ?? 900);
            const prefix   = String(opts.prefix ?? '');
            const suffix   = String(opts.suffix ?? '');
            const decimals = Number(opts.decimals ?? 0);
            const useGrouping = opts.useGrouping !== false; // default true

            const format = (n) => {
                const fixed = decimals > 0 ? n.toFixed(decimals) : Math.round(n).toString();
                if (!useGrouping) return prefix + fixed + suffix;
                const [intPart, decPart] = fixed.split('.');
                const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                return prefix + grouped + (decPart !== undefined ? '.' + decPart : '') + suffix;
            };

            if (reducedMotion || duration <= 0) {
                el.textContent = format(to);
                return;
            }

            const startTs = performance.now();
            const easeOutQuart = (t) => 1 - Math.pow(1 - t, 4);

            const step = (now) => {
                const elapsed = now - startTs;
                const t = Math.min(1, elapsed / duration);
                const eased = easeOutQuart(t);
                const current = from + (to - from) * eased;
                el.textContent = format(current);
                if (t < 1) requestAnimationFrame(step);
                else      el.textContent = format(to);
            };
            requestAnimationFrame(step);
        },

        /** Scan the document for `[data-ff-countup]` elements and run them. */
        scan(root = document) {
            const nodes = root.querySelectorAll('[data-ff-countup]');
            nodes.forEach((el) => {
                if (el.dataset.ffCountupApplied === '1') return;
                el.dataset.ffCountupApplied = '1';
                FF_CountUp.run(el, {
                    to:       parseFloat(el.dataset.ffCountupTo || el.textContent.replace(/[^0-9.\-]/g, '') || '0'),
                    from:     parseFloat(el.dataset.ffCountupFrom || '0'),
                    duration: parseInt(el.dataset.ffCountupDuration || '900', 10),
                    prefix:   el.dataset.ffCountupPrefix || '',
                    suffix:   el.dataset.ffCountupSuffix || '',
                    decimals: parseInt(el.dataset.ffCountupDecimals || '0', 10),
                });
            });
        },
    };
    window.FF_CountUp = FF_CountUp;


    // ============================================================
    // FF_Confetti — DOM-particle celebration burst. No canvas, no
    // external library. Each "piece" is an absolutely-positioned
    // div animated via the ff-anim-confetti-fall keyframe in
    // animations.css.
    //
    // Usage:
    //   FF_Confetti.burst();            // default 100 pieces, brand colors
    //   FF_Confetti.burst({ count: 60, duration: 2000, colors: ['#F97316', '#16a34a'] });
    // ============================================================
    const FF_Confetti = {

        /** Brand colors — orange / blue / green / amber / purple. */
        DEFAULT_COLORS: ['#F97316', '#2563eb', '#16a34a', '#fbbf24', '#7c3aed', '#ef4444'],

        /**
         * Fire a confetti burst.
         *
         * @param {object} opts
         * @param {number} [opts.count]    Number of particles (default 100)
         * @param {number} [opts.duration] Animation duration ms (default 2400)
         * @param {string[]} [opts.colors] Color palette
         * @param {number} [opts.spread]   Horizontal spread vw (default 60)
         */
        burst(opts = {}) {
            if (reducedMotion) return;
            const count    = opts.count ?? 100;
            const duration = opts.duration ?? 2400;
            const colors   = opts.colors ?? this.DEFAULT_COLORS;
            const spread   = opts.spread ?? 60; // vw

            const host = document.createElement('div');
            host.className = 'ff-anim-confetti-host';
            document.body.appendChild(host);

            for (let i = 0; i < count; i++) {
                const piece = document.createElement('div');
                piece.className = 'ff-anim-confetti-piece';
                const x = (Math.random() - 0.5) * spread; // -30vw..+30vw
                const startLeft = 50 + (Math.random() - 0.5) * 30; // around center
                piece.style.left = startLeft + 'vw';
                piece.style.background = colors[Math.floor(Math.random() * colors.length)];
                piece.style.setProperty('--ff-x', x + 'vw');
                piece.style.animationDelay = (Math.random() * 0.3) + 's';
                piece.style.animationDuration = (duration / 1000 + Math.random() * 0.6) + 's';
                piece.style.transform = `rotate(${Math.random() * 360}deg)`;
                // Vary shape slightly — some pieces are squares, some thin rectangles.
                if (Math.random() < 0.4) {
                    piece.style.width = '6px';
                    piece.style.height = '14px';
                }
                host.appendChild(piece);
            }

            // Clean up after the slowest piece finishes.
            setTimeout(() => { host.remove(); }, duration + 1200);
        },
    };
    window.FF_Confetti = FF_Confetti;


    // ============================================================
    // FF_StatusPulse — apply a one-shot status pulse to any element
    // (typically a status badge after a state transition).
    //
    // Usage:
    //   FF_StatusPulse.go(el, 'green');   // success transition
    //   FF_StatusPulse.go(el, 'amber');   // warning transition
    //   FF_StatusPulse.go(el, 'red');     // error/void transition
    // ============================================================
    const FF_StatusPulse = {

        go(el, color = 'green') {
            if (!el || reducedMotion) return;
            const cls = `ff-anim-pulse-${color}`;
            el.classList.remove(cls);
            // Force reflow so re-adding the class re-triggers the animation.
            void el.offsetWidth;
            el.classList.add(cls);
            setTimeout(() => el.classList.remove(cls), 1500);
        },
    };
    window.FF_StatusPulse = FF_StatusPulse;


    // ============================================================
    // FF_ShakeForm — shake a form (or submit button) when validation
    // fails. Adds .ff-anim-shake briefly, removes after animation.
    //
    // Usage:
    //   FF_ShakeForm.shake(formEl);
    //   FF_ShakeForm.shake(document.querySelector('button[type="submit"]'));
    // ============================================================
    const FF_ShakeForm = {

        shake(el) {
            if (!el || reducedMotion) return;
            el.classList.remove('ff-anim-shake');
            void el.offsetWidth;
            el.classList.add('ff-anim-shake');
            setTimeout(() => el.classList.remove('ff-anim-shake'), 550);
        },
    };
    window.FF_ShakeForm = FF_ShakeForm;


    // ============================================================
    // Auto-init: scan for [data-ff-countup] on DOMContentLoaded so
    // page-load count-ups Just Work without per-page glue code.
    // ============================================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => FF_CountUp.scan());
    } else {
        FF_CountUp.scan();
    }
})();
