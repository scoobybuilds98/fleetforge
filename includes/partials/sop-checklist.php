<?php
declare(strict_types=1);

/**
 * includes/partials/sop-checklist.php — the live, shared month-end checklist
 * (S-SOP-MODULE)
 *
 * Rendered by app/admin/sop/_chapter.php in place of the static checklist
 * that SopRenderer emits for ":::checklist month_end". It lives in
 * includes/partials (not app/admin/sop) because files under app/admin are
 * routable URLs, and this fragment must never be requested on its own.
 *
 * Expects:
 *   $sopChecklistPayload  array  SopChecklist::payload() for the period shown
 *
 * Behaviour is the sopChecklist() Alpine component (public/assets/js/sop.js):
 * optimistic ticks, month navigation, confetti when the last step is ticked.
 * item.html is server-rendered SOP markdown (trusted repo content), which is
 * why x-html is safe here.
 */

use FleetForge\Sop\SopIcons;

$sopChecklistCfg = [
    'payload' => $sopChecklistPayload,
    'api'     => base_url('api/v1/sop/checklist'),
    'me'      => (string) (current_user()['name'] ?? 'You'),
];
?>
<section id="checklist" class="sop-cl" x-data="sopChecklist(<?= e(json_encode($sopChecklistCfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)"
         :class="{ 'sop-cl-loading': loading }" aria-label="Month-end close checklist">
    <div class="sop-cl-head">
        <div class="sop-cl-ringwrap" :class="{ 'is-done': d.done === d.total }">
            <svg class="sop-ring sop-ring--lg" viewBox="0 0 80 80" aria-hidden="true">
                <circle class="sop-ring-track" cx="40" cy="40" r="34"/>
                <circle class="sop-ring-fill" cx="40" cy="40" r="34" :stroke-dasharray="ringLength(34)" :stroke-dashoffset="ringOffset(34)"/>
            </svg>
            <div class="sop-cl-ringtext"><b x-text="d.done + '/' + d.total"></b><span>steps</span></div>
        </div>
        <div class="sop-cl-headmain">
            <div class="sop-cl-title">Month-end close · shared checklist</div>
            <div class="sop-cl-period">
                <button type="button" class="sop-iconbtn" :disabled="!d.prev || loading" @click="load(d.prev)" aria-label="Previous month"><?= SopIcons::svg('chevron-left') ?></button>
                <div class="sop-cl-period-label" x-text="d.period_label" aria-live="polite"></div>
                <button type="button" class="sop-iconbtn" :disabled="!d.next || loading" @click="load(d.next)" aria-label="Next month"><span style="display:inline-flex;transform:scaleX(-1)"><?= SopIcons::svg('chevron-left') ?></span></button>
            </div>
            <div class="sop-cl-sub">
                <span class="sop-signal-live">Live checks as of <span x-text="since()"></span></span>
                <button type="button" class="btn btn-ghost btn-xs" @click="refresh()" :disabled="loading">Refresh</button>
                <span x-show="!d.can_tick" x-cloak>View only — your role can look but not tick.</span>
            </div>
        </div>
    </div>

    <div class="sop-cl-track">
        <template x-for="s in d.stages" :key="s.letter">
            <button type="button" class="sop-cl-step" :class="{ 'is-complete': s.done === s.total }" @click="jump(s.letter)">
                <div class="sop-cl-step-top"><span class="sop-cl-letter" x-text="s.letter"></span><span class="sop-cl-step-name" x-text="s.title"></span></div>
                <div class="sop-cl-step-count" x-text="s.done + ' of ' + s.total + ' done'"></div>
                <div class="sop-cl-bar"><span :style="'transform:scaleX(' + (s.total ? s.done / s.total : 0) + ')'"></span></div>
            </button>
        </template>
    </div>

    <template x-if="d.done === d.total">
        <div class="alert alert-success sop-cl-banner">Every step is ticked for <strong x-text="d.period_label"></strong>. Nice work — make sure the period is closed.</div>
    </template>
    <template x-if="d.is_december">
        <div class="alert alert-warning sop-cl-banner">December: do every step, but don't press Close Period — the year-end close does that.</div>
    </template>

    <template x-for="s in d.stages" :key="s.letter">
        <div class="sop-cl-stage" :id="'cl-stage-' + s.letter" :class="{ 'is-complete': s.done === s.total }">
            <div class="sop-cl-stage-head">
                <span class="sop-cl-letter" x-text="s.letter"></span>
                <span class="sop-cl-stage-title" x-text="s.title"></span>
                <span class="sop-cl-owner" x-show="s.owner" x-text="s.owner"></span>
                <span class="sop-cl-stage-count" x-text="s.done + ' / ' + s.total"></span>
            </div>
            <template x-for="it in s.items" :key="it.key">
                <div class="sop-cl-item" :class="{ 'is-ticked': it.ticked }">
                    <button type="button" class="sop-cl-check" :disabled="!d.can_tick || !!pending[it.key]" @click="toggle(s, it)"
                            :aria-pressed="it.ticked ? 'true' : 'false'" :aria-label="it.ticked ? 'Untick this step' : 'Tick this step as done'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                    </button>
                    <div class="sop-cl-text" x-html="it.html"></div>
                    <a class="sop-cl-open" x-show="it.href" :href="it.href">Open ↗</a>
                    <div class="sop-cl-meta">
                        <template x-if="it.signal">
                            <span class="sop-signal" :class="'sop-signal--' + it.signal.state" :title="'Live check: ' + it.signal.text">
                                <span class="sop-signal-dot"></span><span x-text="it.signal.text"></span>
                            </span>
                        </template>
                        <template x-if="it.ticked">
                            <span class="sop-cl-who"><span class="sop-cl-avatar" x-text="initials(it.by_name)"></span><span x-text="'Ticked by ' + it.by_name + ' · ' + when(it.at)"></span></span>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </template>

    <div class="sop-cl-foot">
        Ticks are shared with the whole team and kept per month — the first person to tick a step is the one on record.
        The live checks are counts from FleetForge right now (never amounts); AR/AP checks compare today's balances.
    </div>
</section>
