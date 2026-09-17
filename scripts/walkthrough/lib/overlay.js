/*
 * scripts/walkthrough/lib/overlay.js
 *
 * Injected into every page (context.addInitScript) while a training chapter
 * records. Draws the on-camera chrome that the Homebrew ffmpeg build cannot
 * (it has no drawtext/libass): caption pill, fake mouse cursor, click ripple,
 * element highlight box and full-screen title cards.
 *
 * Everything lives in a closed-style shadow root with `all: initial` so the
 * app's Atelier CSS (dark :root tokens, resets) cannot restyle it, and state is
 * mirrored to sessionStorage so captions/cursor survive full page navigations.
 */
(() => {
  // Top frame only — embedded previews (e.g. credit-application iframe) would draw a second caption/cursor.
  if (window.__wt || window.top !== window) return;
  const KEY = '__wt_state';
  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY)) || {}; } catch { return {}; } };
  const save = (s) => { try { sessionStorage.setItem(KEY, JSON.stringify(s)); } catch {} };
  const state = Object.assign({ caption: '', chip: '', x: 960, y: 540 }, load());

  let root, cap, capText, capChip, cursor, hl, hlLabel, card;
  const queue = [];

  function mount() {
    const host = document.createElement('div');
    host.id = '__wt_host';
    host.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483647;';
    root = host.attachShadow({ mode: 'open' });
    root.innerHTML = `
      <style>
        /* reset only boxes: a universal all:initial would also un-hide <style> and strip SVG fill attrs */
        :host, div, span { all: initial; box-sizing: border-box; }
        div, span, svg { display: block; }
        /* all:initial resets pointer-events to auto — the invisible card would swallow every click */
        #cap, #cap *, #cursor, #cursor *, #hl, #hl *, #card, #card *, .ripple { pointer-events: none !important; }
        .font { font-family: "Geist", "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        #cap { position: fixed; left: 50%; bottom: 34px; transform: translateX(-50%); max-width: 1480px;
               background: rgba(10,10,12,.88); border: 1px solid rgba(255,255,255,.10); border-radius: 14px;
               padding: 14px 30px 16px; box-shadow: 0 12px 40px rgba(0,0,0,.45); transition: opacity .35s ease; }
        #chip { color: #f59e0b; font-size: 15px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 5px; text-align: center; }
        #capText { color: #fafafa; font-size: 29px; line-height: 1.35; font-weight: 500; text-align: center; }
        #cursor { position: fixed; left: 0; top: 0; width: 30px; height: 30px; will-change: transform;
                  filter: drop-shadow(0 2px 3px rgba(0,0,0,.55)); }
        .ripple { position: fixed; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%;
                  border: 3px solid #f59e0b; animation: rip .55s ease-out forwards; }
        @keyframes rip { from { transform: scale(.4); opacity: 1; } to { transform: scale(3.2); opacity: 0; } }
        #hl { position: fixed; border: 3px solid #f59e0b; border-radius: 10px; opacity: 0; transition: all .35s ease;
              box-shadow: 0 0 0 9999px rgba(0,0,0,.28), 0 0 24px rgba(245,158,11,.55); }
        #hlLabel { position: absolute; left: -3px; top: -38px; background: #f59e0b; color: #111; font-size: 17px;
                   font-weight: 700; padding: 6px 12px; border-radius: 8px; white-space: nowrap; }
        #card { position: fixed; inset: 0; opacity: 0; transition: opacity .6s ease;
                background: radial-gradient(1200px 700px at 20% 15%, #2a1a05 0%, #0b0b0d 60%);
                padding: 0 160px; flex-direction: column; justify-content: center; }
        #card.on { opacity: 1; }
        #cardNum { color: #f59e0b; font-size: 26px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; margin-bottom: 22px; }
        #cardTitle { color: #fafafa; font-size: 96px; font-weight: 700; line-height: 1.02; letter-spacing: -.02em; }
        #cardSub { color: #a1a1aa; font-size: 34px; line-height: 1.4; margin-top: 28px; max-width: 1300px; }
        #cardBrand { position: fixed; left: 160px; bottom: 90px; color: #71717a; font-size: 22px; letter-spacing: .06em; }
      </style>
      <div id="card" class="font" style="display:flex"><div id="cardNum" class="font"></div><div id="cardTitle" class="font"></div><div id="cardSub" class="font"></div><div id="cardBrand" class="font">FLEETFORGE · STAFF TRAINING</div></div>
      <div id="hl"><div id="hlLabel" class="font"></div></div>
      <div id="cap" class="font"><div id="chip" class="font"></div><div id="capText" class="font"></div></div>
      <svg id="cursor" viewBox="0 0 24 24"><path d="M4 2.5 L4 19.5 L8.6 15.3 L11.6 22 L14.6 20.7 L11.7 14.1 L18 14.1 Z" fill="#fff" stroke="#000" stroke-width="1.4" stroke-linejoin="round"/></svg>`;
    document.documentElement.appendChild(host);
    cap = root.getElementById('cap'); capText = root.getElementById('capText'); capChip = root.getElementById('chip');
    cursor = root.getElementById('cursor'); hl = root.getElementById('hl'); hlLabel = root.getElementById('hlLabel');
    card = root.getElementById('card');
    render();
    while (queue.length) queue.shift()();
  }

  function render() {
    capText.textContent = state.caption;
    capChip.textContent = state.chip;
    capChip.style.display = state.chip ? 'block' : 'none';
    cap.style.opacity = state.caption ? '1' : '0';
    cursor.style.transform = `translate(${state.x - 4}px, ${state.y - 2}px)`;
  }

  const ready = (fn) => (root ? fn() : queue.push(fn));
  const ease = (t) => (t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

  window.__wt = {
    setCaption(text, chip) { state.caption = text || ''; if (chip !== undefined) state.chip = chip; save(state); ready(render); },
    moveCursor(x, y, ms = 650) {
      return new Promise((res) => ready(() => {
        const sx = state.x, sy = state.y, t0 = performance.now();
        const step = (now) => {
          const k = Math.min(1, (now - t0) / ms), e = ease(k);
          state.x = sx + (x - sx) * e; state.y = sy + (y - sy) * e;
          cursor.style.transform = `translate(${state.x - 4}px, ${state.y - 2}px)`;
          if (k < 1) requestAnimationFrame(step); else { save(state); res(); }
        };
        requestAnimationFrame(step);
      }));
    },
    ripple(x, y) { ready(() => { const r = document.createElement('div'); r.className = 'ripple'; r.style.left = x + 'px'; r.style.top = y + 'px'; root.appendChild(r); setTimeout(() => r.remove(), 700); }); },
    highlight(rect, label) {
      ready(() => {
        if (!rect) { hl.style.opacity = '0'; return; }
        const p = 8;
        Object.assign(hl.style, { left: rect.x - p + 'px', top: rect.y - p + 'px', width: rect.width + 2 * p + 'px', height: rect.height + 2 * p + 'px', opacity: '1' });
        hlLabel.textContent = label || ''; hlLabel.style.display = label ? 'block' : 'none';
        hlLabel.style.top = rect.y < 60 ? (rect.height + 2 * p + 6) + 'px' : '-38px';
      });
    },
    card(on, num, title, sub) {
      ready(() => {
        if (on) { root.getElementById('cardNum').textContent = num || ''; root.getElementById('cardTitle').textContent = title || ''; root.getElementById('cardSub').textContent = sub || ''; }
        card.classList.toggle('on', !!on);
        cap.style.visibility = on ? 'hidden' : 'visible';
        cursor.style.visibility = on ? 'hidden' : 'visible';
      });
    },
  };

  if (document.documentElement && document.readyState !== 'loading') mount();
  else document.addEventListener('DOMContentLoaded', mount, { once: true });
})();
