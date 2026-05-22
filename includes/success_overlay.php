<?php
declare(strict_types=1);

/**
 * FleetForge — Success Overlay (Shared Include)
 *
 * @file        includes/success_overlay.php
 * @description Full-screen truck animation shown on any successful create/save.
 *              Drop this include just before <?php require footer ?> in any create page.
 *
 *              Required PHP variables (set before including):
 *                $overlayTitle    string  e.g. "Customer Created!"
 *                $overlaySubtitle string  e.g. "Redirecting to customer profile…"
 *
 *              Required Alpine state on the host component:
 *                showSuccessOverlay: false
 *
 *              On success in submit():
 *                this.showSuccessOverlay = true;
 *                setTimeout(() => { window.location.href = '...'; }, 3500);
 *
 * @session     S018-EXT
 */

$overlayTitle    = htmlspecialchars($overlayTitle    ?? 'Done!',         ENT_QUOTES, 'UTF-8');
$overlaySubtitle = htmlspecialchars($overlaySubtitle ?? 'Redirecting…',  ENT_QUOTES, 'UTF-8');
?>

<!-- ================================================================
     SUBMITTING OVERLAY — Immediate "Saving…" feedback (S-CREATE-FEEDBACK)
     Fires the instant submit() is called, before the API responds.
     Shown when the host component's `submitting` is true AND
     `showSuccessOverlay` is false (i.e. mid-flight, not yet succeeded).
     Operator wanted clear visual feedback during the API wait so the
     click doesn't feel like it did nothing.

     Disappears the moment either:
       (a) showSuccessOverlay = true → the truck animation below takes
           over (success path)
       (b) submitting = false (failure path; form re-enables for retry)
     ================================================================ -->
<template x-if="submitting && !showSuccessOverlay">
    <div class="ff-saving-overlay"
         style="position:fixed;inset:0;z-index:9998;background:rgba(10,15,28,0.55);
                backdrop-filter:blur(2px);display:flex;align-items:center;
                justify-content:center;animation:ff-fade-in 0.18s ease;">
        <div style="background:white;padding:32px 56px;border-radius:14px;
                    box-shadow:0 20px 60px rgba(0,0,0,0.45);text-align:center;
                    min-width:280px;animation:ff-pop-in 0.22s ease-out;">
            <!-- Spinner -->
            <div class="ff-saving-spinner" style="margin:0 auto 18px;width:48px;height:48px;
                        border:4px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;
                        animation:ff-spin 0.85s linear infinite;"></div>
            <div style="font-size:1.1rem;font-weight:600;color:#1c1c1a;">Saving…</div>
            <div style="color:#64748b;font-size:0.875rem;margin-top:6px;">
                <?= $overlaySubtitle ?: 'One moment please' ?>
            </div>
        </div>
    </div>
</template>

<!-- ================================================================
     SUCCESS OVERLAY — Full-screen truck animation
     Shared across all create pages. Triggered via Alpine
     showSuccessOverlay = true on the host component.
     ================================================================ -->
<template x-if="showSuccessOverlay">
    <div class="ff-success-overlay"
         style="position:fixed;inset:0;z-index:9999;overflow:hidden;background:rgba(10,15,28,0.97);">

        <!-- ── Upper area: checkmark + message ──────────────────── -->
        <div style="position:absolute;top:0;left:0;right:0;bottom:110px;
                    display:flex;flex-direction:column;align-items:center;
                    justify-content:center;gap:24px;">

            <!-- Animated checkmark circle -->
            <div class="ff-check-circle">
                <div style="width:108px;height:108px;border-radius:50%;background:#22c55e;
                            display:flex;align-items:center;justify-content:center;
                            box-shadow:0 0 0 14px rgba(34,197,94,0.12),
                                       0 0 0 28px rgba(34,197,94,0.06);">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                         fill="none" stroke="white" stroke-width="2.8"
                         stroke-linecap="round" stroke-linejoin="round"
                         style="width:56px;height:56px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
            </div>

            <!-- Title + subtitle -->
            <div class="ff-success-text" style="text-align:center;">
                <h2 style="color:#f8fafc;font-size:2rem;font-weight:700;margin:0 0 10px;
                            letter-spacing:-0.5px;">
                    <?= $overlayTitle ?>
                </h2>
                <p style="color:#64748b;font-size:1rem;margin:0;">
                    <?= $overlaySubtitle ?>
                </p>
            </div>

        </div>

        <!-- ── Road ──────────────────────────────────────────────── -->
        <div style="position:absolute;bottom:0;left:0;right:0;height:110px;
                    background:linear-gradient(to bottom,#1e293b 0%,#0f172a 100%);">
            <!-- Top curb -->
            <div style="position:absolute;top:0;left:0;right:0;height:3px;background:#334155;"></div>
            <!-- Scrolling lane dashes -->
            <div class="ff-road-dashes"
                 style="position:absolute;top:52px;left:0;right:0;height:5px;opacity:0.55;"></div>
            <!-- Bottom curb -->
            <div style="position:absolute;bottom:0;left:0;right:0;height:4px;background:#334155;"></div>
        </div>

        <!-- ── Truck SVG (drives in from left) ───────────────────── -->
        <div class="ff-truck-wrap"
             style="position:absolute;bottom:14px;left:50%;margin-left:-195px;">
            <svg width="390" height="94" viewBox="0 0 390 94"
                 xmlns="http://www.w3.org/2000/svg">

                <!-- Trailer body -->
                <rect x="0" y="8" width="248" height="58" rx="4"
                      fill="#2563eb" stroke="#1e40af" stroke-width="1.5"/>
                <!-- Trailer roof stripe -->
                <rect x="0" y="8" width="248" height="11" rx="4" fill="#1d4ed8"/>
                <!-- Trailer vertical ribs -->
                <line x1="41"  y1="19" x2="41"  y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                <line x1="82"  y1="19" x2="82"  y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                <line x1="123" y1="19" x2="123" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                <line x1="164" y1="19" x2="164" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                <line x1="205" y1="19" x2="205" y2="66" stroke="#1e40af" stroke-width="1" opacity="0.45"/>
                <!-- FLEETFORGE branding -->
                <text x="20" y="49" font-family="Arial,Helvetica,sans-serif"
                      font-size="12" font-weight="700" fill="white"
                      letter-spacing="3" opacity="0.95">FLEETFORGE</text>
                <!-- Rear running lights -->
                <rect x="1"  y="22" width="5" height="11" rx="1.5" fill="#ef4444"/>
                <rect x="1"  y="45" width="5" height="11" rx="1.5" fill="#ef4444"/>
                <!-- Rear reflector -->
                <rect x="2"  y="58" width="12" height="5" rx="1" fill="#fbbf24" opacity="0.8"/>
                <!-- Trailer dual-axle wheels -->
                <circle cx="44"  cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                <circle cx="44"  cy="77" r="7"  fill="#1e293b"/>
                <circle cx="44"  cy="77" r="2.5" fill="#6b7280"/>
                <circle cx="70"  cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                <circle cx="70"  cy="77" r="7"  fill="#1e293b"/>
                <circle cx="70"  cy="77" r="2.5" fill="#6b7280"/>
                <!-- Fifth-wheel hitch -->
                <rect x="243" y="56" width="22" height="7" rx="3.5" fill="#475569"/>
                <rect x="248" y="59" width="12" height="4"  rx="2"   fill="#64748b"/>
                <!-- Cab body -->
                <rect x="265" y="24" width="118" height="42" rx="5"
                      fill="#1d4ed8" stroke="#1e40af" stroke-width="1.5"/>
                <!-- Cab roof / sleeper -->
                <path d="M268 24 Q272 4 290 4 L364 4 Q377 4 379 17 L379 24 Z" fill="#1e40af"/>
                <!-- Roof accent strip -->
                <rect x="288" y="4" width="76" height="5" rx="2" fill="#3b82f6"/>
                <!-- Windshield -->
                <path d="M306 6 L372 6 L378 24 L306 24 Z" fill="#bfdbfe" opacity="0.88"/>
                <!-- Windshield glare -->
                <path d="M316 8 L338 8 L335 18 L313 18 Z" fill="white" opacity="0.22"/>
                <!-- Side window -->
                <rect x="267" y="28" width="36" height="20" rx="3"
                      fill="#bfdbfe" opacity="0.75"/>
                <!-- Door handle -->
                <rect x="276" y="41" width="14" height="3" rx="1.5" fill="#60a5fa"/>
                <!-- Door seam -->
                <line x1="305" y1="24" x2="305" y2="66" stroke="#1e40af" stroke-width="1.5"/>
                <!-- Cab skirt -->
                <rect x="265" y="61" width="118" height="5" rx="2" fill="#1e3a8a"/>
                <!-- Exhaust stack -->
                <rect x="267" y="0" width="8" height="26" rx="4" fill="#374151"/>
                <rect x="270" y="0" width="2" height="24" fill="#4b5563"/>
                <!-- Fuel tank -->
                <rect x="268" y="54" width="32" height="14" rx="3"
                      fill="#1e3a8a" stroke="#1e40af" stroke-width="1"/>
                <!-- Grill -->
                <rect x="375" y="28" width="5" height="36" rx="2" fill="#374151"/>
                <line x1="375" y1="34" x2="380" y2="34" stroke="#4b5563"/>
                <line x1="375" y1="40" x2="380" y2="40" stroke="#4b5563"/>
                <line x1="375" y1="46" x2="380" y2="46" stroke="#4b5563"/>
                <line x1="375" y1="52" x2="380" y2="52" stroke="#4b5563"/>
                <!-- Headlights -->
                <rect x="378" y="28" width="10" height="14" rx="2"
                      fill="#fef9c3" stroke="#fcd34d" stroke-width="1"/>
                <rect x="379" y="46" width="9"  height="8"  rx="2"
                      fill="#fef08a" opacity="0.65"/>
                <!-- Position light top -->
                <circle cx="378" cy="25" r="3.5" fill="#fbbf24"/>
                <!-- Brake light -->
                <circle cx="378" cy="67" r="3.5" fill="#f87171"/>
                <!-- Cab front axle wheels -->
                <circle cx="308" cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                <circle cx="308" cy="77" r="7"  fill="#1e293b"/>
                <circle cx="308" cy="77" r="2.5" fill="#6b7280"/>
                <circle cx="362" cy="77" r="15" fill="#0f172a" stroke="#374151" stroke-width="2.5"/>
                <circle cx="362" cy="77" r="7"  fill="#1e293b"/>
                <circle cx="362" cy="77" r="2.5" fill="#6b7280"/>

            </svg>
        </div><!-- /truck -->

    </div>
</template>

<!-- ── Success + Saving Overlay CSS (injected once per page) ────── -->
<style>
/* Saving overlay (S-CREATE-FEEDBACK) — instant feedback on submit. */
@keyframes ff-spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
@keyframes ff-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
@keyframes ff-pop-in {
    from { opacity: 0; transform: scale(0.92); }
    to   { opacity: 1; transform: scale(1); }
}

/* Overlay fade-in */
@keyframes ff-overlay-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}
.ff-success-overlay {
    animation: ff-overlay-in 0.25s ease forwards;
}

/* Truck drives in from left, brakes with bounce */
@keyframes ff-truck-in {
    0%   { transform: translateX(-110vw); }
    72%  { transform: translateX(0); animation-timing-function: cubic-bezier(0.22,0.61,0.36,1); }
    84%  { transform: translateX(18px); }
    92%  { transform: translateX(-6px); }
    100% { transform: translateX(0); }
}
.ff-truck-wrap {
    animation: ff-truck-in 1.5s cubic-bezier(0.16,1,0.3,1) forwards;
}

/* Road dashes scroll left (synced to truck arrival) */
@keyframes ff-road-scroll {
    0%   { background-position: 0 0;      }
    100% { background-position: -110vw 0; }
}
.ff-road-dashes {
    background: repeating-linear-gradient(
        90deg,
        white 0, white 36px,
        transparent 36px, transparent 96px
    );
    background-size: 132px 5px;
    animation: ff-road-scroll 1.5s linear forwards;
}

/* Checkmark circle spring-pops in */
@keyframes ff-check-pop {
    0%   { transform: scale(0) rotate(-18deg); opacity: 0; }
    55%  { transform: scale(1.18) rotate(4deg); opacity: 1; }
    75%  { transform: scale(0.94) rotate(-2deg); }
    90%  { transform: scale(1.04) rotate(1deg); }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
}
.ff-check-circle {
    animation: ff-check-pop 0.6s cubic-bezier(0.34,1.56,0.64,1) 1.65s both;
    opacity: 0;
}

/* Success text fades + slides up */
@keyframes ff-text-up {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0);    }
}
.ff-success-text {
    animation: ff-text-up 0.55s ease 2.1s both;
    opacity: 0;
}
</style>
