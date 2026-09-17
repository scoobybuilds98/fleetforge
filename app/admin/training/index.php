<?php
declare(strict_types=1);

// ============================================================
// /training — Staff Training (S-TRAINING-MODULE)
//
// The narrated training course inside the app. Every signed-in staff user
// sees the chapter list with their OWN progress, plays a chapter with
// captions, and picks up exactly where they left off.
//
//   - 4 tiles: chapters completed, course progress (weighted by chapter
//     length), time watched, time remaining
//   - Player: <video> streamed via api/v1/training/stream (S3 presigned
//     redirect in prod, Range-capable local stream in dev) + VTT captions
//   - Resume: seeks to the saved position on load unless it is within the
//     first 5s or the last 10s (then starts from the top)
//   - Progress: heartbeat to api/v1/training/progress every 10s of play,
//     on pause / seek / end, and when the tab is hidden (keepalive fetch)
//   - Deep link: /training?chapter=<slug>
//   - Super admins get a Team Report button (/training/report)
//
// Permission: any signed-in user. No module key on purpose — new keys are
// invisible until re-login (see config/permissions.php note).
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

$pageTitle      = 'Training';
$helpModuleSlug = 'training';
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
.tr-layout { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:1.25rem; align-items:start; }
@media (max-width: 1100px) { .tr-layout { grid-template-columns:1fr; } }
.tr-player video { width:100%; display:block; background:#000; border-radius:var(--radius-md, 8px) var(--radius-md, 8px) 0 0; aspect-ratio:16/9; }
.tr-bar { height:6px; border-radius:3px; background:var(--bg-subtle); overflow:hidden; }
.tr-bar > span { display:block; height:100%; background:var(--color-primary); transition:width .3s; }
.tr-bar.is-done > span { background:var(--color-success, #3a9d5d); }
.tr-list { max-height:calc(100vh - 260px); overflow-y:auto; }
.tr-item { display:flex; gap:.75rem; align-items:center; padding:.65rem 1rem; cursor:pointer; border-bottom:1px solid var(--border-color); }
.tr-item:hover { background:var(--bg-subtle); }
.tr-item.is-active { background:var(--bg-selected, var(--bg-subtle)); box-shadow:inset 3px 0 0 var(--color-primary); }
.tr-num { flex:0 0 28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:600; background:var(--bg-subtle); color:var(--text-secondary); }
.tr-num.is-done { background:var(--color-success-light); color:var(--color-success-text); }
.tr-item-body { flex:1; min-width:0; }
.tr-item-title { font-size:.875rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tr-item-meta { font-size:.75rem; color:var(--text-secondary); margin:.2rem 0 .35rem; }
</style>

<div x-data="trainingPage()" x-cloak>

<div class="page-header">
    <div>
        <h1 class="page-header-title">Training</h1>
        <p style="color:var(--text-secondary);margin:0;">Narrated walkthroughs of every part of FleetForge. Your place is saved as you watch.</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('training') ?>
        <?php if (is_super_admin()): ?>
        <a href="<?= base_url('training/report') ?>" class="btn btn-secondary">Team Report</a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" x-show="nextChapter" @click="open(nextChapter, true)"
                x-text="summary.completed === 0 && !anyStarted ? 'Start Course' : 'Continue Training'"></button>
    </div>
</div>

<template x-if="loading">
    <div class="card"><div class="card-body" style="padding:2rem;text-align:center;color:var(--text-secondary);">Loading…</div></div>
</template>

<template x-if="!loading && error">
    <div class="alert alert-danger">Could not load training. Please refresh.</div>
</template>

<template x-if="!loading && !error && chapters.length === 0">
    <div class="card"><div class="card-body" style="padding:3rem;text-align:center;color:var(--text-secondary);">
        No training videos have been published yet.
    </div></div>
</template>

<div x-show="!loading && !error && chapters.length > 0">
    <div class="stat-grid stat-grid--4" style="margin-bottom:1.5rem;">
        <div class="stat-card">
            <div class="stat-value" x-text="summary.completed + ' / ' + summary.chapters"></div>
            <div class="stat-label">Chapters Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" x-text="summary.percent + '%'"></div>
            <div class="stat-label">Course Progress</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" x-text="fmtLong(summary.watched_seconds)"></div>
            <div class="stat-label">Time Watched</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" x-text="fmtLong(Math.max(0, summary.total_seconds - summary.watched_seconds))"></div>
            <div class="stat-label">Time Remaining</div>
        </div>
    </div>

    <div class="tr-layout">
        <div class="card tr-player">
            <video x-ref="video" controls playsinline preload="metadata"
                   @loadedmetadata="onLoaded()" @play="startBeat()" @pause="save()" @seeked="save()"
                   @ended="onEnded()"></video>
            <div class="card-body" x-show="current">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;">
                    <div style="min-width:0;">
                        <div style="font-size:.75rem;color:var(--text-secondary);" x-text="current ? 'Chapter ' + current.chapter_no + ' of ' + chapters.length : ''"></div>
                        <h2 style="margin:.15rem 0 .4rem;font-size:1.15rem;" x-text="current?.title"></h2>
                        <p style="margin:0;color:var(--text-secondary);font-size:.875rem;" x-text="current?.description"></p>
                    </div>
                    <span class="badge" :class="current?.completed_at ? 'badge-success' : (current?.percent > 0 ? 'badge-info' : 'badge-neutral')"
                          style="white-space:nowrap;"
                          x-text="current?.completed_at ? 'Completed' : (current?.percent > 0 ? current.percent + '% watched' : 'Not started')"></span>
                </div>
                <div style="display:flex;gap:.5rem;margin-top:1rem;">
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!prevOf(current)" @click="open(prevOf(current), true)">← Previous</button>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!nextOf(current)" @click="open(nextOf(current), true)">Next →</button>
                    <span x-show="resumedFrom" style="margin-left:auto;font-size:.8rem;color:var(--text-secondary);align-self:center;"
                          x-text="'Resumed at ' + fmt(resumedFrom)"></span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title">Chapters</div></div>
            <div class="tr-list">
                <template x-for="c in chapters" :key="c.id">
                    <div class="tr-item" :class="current && current.id === c.id ? 'is-active' : ''" @click="open(c, true)">
                        <div class="tr-num" :class="c.completed_at ? 'is-done' : ''" x-text="c.completed_at ? '✓' : c.chapter_no"></div>
                        <div class="tr-item-body">
                            <div class="tr-item-title" x-text="c.title"></div>
                            <div class="tr-item-meta" x-text="fmt(c.duration_seconds) + (c.completed_at ? ' · Completed' : (c.percent > 0 ? ' · ' + c.percent + '%' : ''))"></div>
                            <div class="tr-bar" :class="c.completed_at ? 'is-done' : ''"><span :style="'width:' + (c.completed_at ? 100 : c.percent) + '%'"></span></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

</div>

<script>
function trainingPage() {
    const API = '<?= base_url('api/v1/training') ?>';
    return {
        loading: true,
        error: false,
        chapters: [],
        summary: { chapters: 0, completed: 0, percent: 0, watched_seconds: 0, total_seconds: 0 },
        current: null,
        resumedFrom: 0,
        _beat: null,
        _lastSent: -1,

        async init() {
            if (this._inited) return; // Alpine double-init guard — before the first await
            this._inited = true;
            try {
                const res = await FF_Api.get(API);
                if (!res.success) throw new Error();
                this.chapters = res.data.chapters;
                this.summary  = res.data.summary;
            } catch (e) {
                this.error = true;
            }
            this.loading = false;
            if (!this.chapters.length) return;

            const want = new URLSearchParams(location.search).get('chapter');
            this.open(this.chapters.find(c => c.slug === want) || this.nextChapter || this.chapters[0], false);

            document.addEventListener('visibilitychange', () => { if (document.hidden) this.save(true); });
            window.addEventListener('pagehide', () => this.save(true));
        },

        get anyStarted() { return this.chapters.some(c => c.percent > 0); },

        // First chapter not yet completed, in order — what "Continue" opens.
        get nextChapter() { return this.chapters.find(c => !c.completed_at) || null; },

        prevOf(c) { const i = this.chapters.indexOf(c); return i > 0 ? this.chapters[i - 1] : null; },
        nextOf(c) { const i = this.chapters.indexOf(c); return i >= 0 && i < this.chapters.length - 1 ? this.chapters[i + 1] : null; },

        open(c, autoplay) {
            if (!c) return;
            if (this.current && this.current.id !== c.id) this.save();
            const v = this.$refs.video;
            this.current = c;
            this.resumedFrom = 0;
            this._lastSent = -1;
            this._autoplay = autoplay;
            v.innerHTML = '';
            v.src = API + '/stream?id=' + c.id;
            if (c.has_captions) {
                const t = document.createElement('track');
                t.kind = 'captions'; t.label = 'English'; t.srclang = 'en'; t.default = true;
                t.src = API + '/stream?kind=captions&id=' + c.id;
                v.appendChild(t);
            }
            v.load();
            const url = new URL(location.href);
            url.searchParams.set('chapter', c.slug);
            history.replaceState(null, '', url);
        },

        onLoaded() {
            const v = this.$refs.video, c = this.current;
            // Resume unless they barely started or were on the outro card.
            if (c && c.position_seconds > 5 && c.position_seconds < v.duration - 10) {
                v.currentTime = c.position_seconds;
                this.resumedFrom = c.position_seconds;
            }
            if (this._autoplay) v.play().catch(() => {});
        },

        startBeat() {
            clearInterval(this._beat);
            this._beat = setInterval(() => { if (!this.$refs.video.paused) this.save(); }, 10000);
        },

        onEnded() {
            clearInterval(this._beat);
            this.save();
        },

        // keepalive: the request must outlive the page when fired from pagehide.
        async save(keepalive = false) {
            const v = this.$refs.video, c = this.current;
            if (!c || !isFinite(v.currentTime)) return;
            const pos = Math.floor(v.ended ? c.duration_seconds : v.currentTime);
            if (pos === this._lastSent) return;
            this._lastSent = pos;
            try {
                const res = await fetch(API + '/progress', {
                    method: 'POST', credentials: 'same-origin', keepalive,
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': FF_CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ video_id: c.id, position: pos }),
                }).then(r => r.json());
                if (!res.success) return;
                c.position_seconds = res.data.position_seconds;
                c.percent          = res.data.percent;
                c.completed_at     = res.data.completed_at;
                this.recompute();
            } catch (e) { /* offline blip — the next heartbeat retries */ }
        },

        // Mirrors TrainingProgress::summarize() so the tiles move without a reload.
        recompute() {
            let total = 0, watched = 0, done = 0;
            for (const c of this.chapters) {
                total   += c.duration_seconds;
                watched += c.completed_at ? c.duration_seconds : Math.floor(c.duration_seconds * c.percent / 100);
                if (c.completed_at) done++;
            }
            this.summary = { chapters: this.chapters.length, completed: done,
                percent: total ? Math.floor(watched * 100 / total) : 0, watched_seconds: watched, total_seconds: total };
        },

        fmt(s) {
            s = Math.max(0, Math.floor(s));
            const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = String(s % 60).padStart(2, '0');
            return h ? h + ':' + String(m).padStart(2, '0') + ':' + sec : m + ':' + sec;
        },
        fmtLong(s) {
            const h = Math.floor(s / 3600), m = Math.round(s % 3600 / 60);
            return h ? h + 'h ' + m + 'm' : m + 'm';
        },
    };
}
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
