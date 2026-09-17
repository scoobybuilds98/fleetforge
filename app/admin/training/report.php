<?php
declare(strict_types=1);

// ============================================================
// /training/report — Training Team Report (S-TRAINING-MODULE)
//
// Super admins only (operator decision 2026-09-17). One row per active or
// invited staff user: chapters completed, course progress weighted by
// chapter length, and last activity. Users who never opened Training are
// listed at 0% on purpose — the report exists to show who has not started.
// Click a row to see that person's chapter-by-chapter progress.
//
// Filters are client-side (the whole team is one small payload):
// search by name/email, and status Not started / In progress / Completed.
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();

if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <h2 class="access-wall-title">Super Admins Only</h2>
        <p class="access-wall-message">The training team report is restricted to super admins.</p>
        <a href="<?= base_url('training') ?>" class="btn btn-secondary">Back to Training</a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

$pageTitle      = 'Training Report';
$helpModuleSlug = 'training';
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<style>
.tr-bar { height:6px; border-radius:3px; background:var(--bg-subtle); overflow:hidden; min-width:90px; }
.tr-bar > span { display:block; height:100%; background:var(--color-primary); }
.tr-bar.is-done > span { background:var(--color-success, #3a9d5d); }
.tr-row { cursor:pointer; }
.tr-row.is-active > td { background:var(--bg-selected, var(--bg-subtle)); }
</style>

<div x-data="trainingReport()" x-cloak>

<div class="page-header">
    <div>
        <h1 class="page-header-title">Training Report</h1>
        <p style="color:var(--text-secondary);margin:0;">How far each team member has got through the staff-training course.</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('training') ?>
        <a href="<?= base_url('training') ?>" class="btn btn-secondary">Back to Training</a>
    </div>
</div>

<div class="stat-grid stat-grid--4" style="margin-bottom:1.5rem;">
    <div class="stat-card"><div class="stat-value" x-text="users.length"></div><div class="stat-label">Team Members</div></div>
    <div class="stat-card"><div class="stat-value" x-text="count('done')"></div><div class="stat-label">Completed Course</div></div>
    <div class="stat-card"><div class="stat-value" x-text="count('progress')"></div><div class="stat-label">In Progress</div></div>
    <div class="stat-card"><div class="stat-value" x-text="count('none')"></div><div class="stat-label">Not Started</div></div>
</div>

<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-body" style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <input type="search" class="form-control" style="max-width:280px;" placeholder="Search name or email" x-model="q">
        <select class="form-control" style="max-width:200px;" x-model="state">
            <option value="">All statuses</option>
            <option value="none">Not started</option>
            <option value="progress">In progress</option>
            <option value="done">Completed course</option>
        </select>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <template x-if="loading"><div style="padding:2rem;text-align:center;color:var(--text-secondary);">Loading…</div></template>
        <template x-if="!loading && error"><div class="alert alert-danger" style="margin:1rem;">Could not load the report. Please refresh.</div></template>
        <div class="table-wrapper" x-show="!loading && !error">
            <table class="table">
                <thead>
                    <tr><th>Team Member</th><th>Role</th><th>Chapters</th><th style="width:220px;">Progress</th><th>Last Activity</th></tr>
                </thead>
                <tbody>
                    <template x-for="u in filtered" :key="u.id">
                        <tr class="tr-row" :class="detail && detail.user.id === u.id ? 'is-active' : ''" @click="openUser(u)">
                            <td><div x-text="u.name"></div><div style="font-size:.75rem;color:var(--text-secondary);" x-text="u.email"></div></td>
                            <td x-text="u.role_name || '—'"></td>
                            <td x-text="u.completed + ' / ' + total + ' completed'"></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:.5rem;">
                                    <div class="tr-bar" style="flex:1;" :class="stateOf(u) === 'done' ? 'is-done' : ''"><span :style="'width:' + u.percent + '%'"></span></div>
                                    <span style="font-size:.8rem;min-width:36px;text-align:right;" x-text="u.percent + '%'"></span>
                                </div>
                            </td>
                            <td x-text="u.last_watched_at ? when(u.last_watched_at) : 'Never'"></td>
                        </tr>
                    </template>
                    <tr x-show="!filtered.length"><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-secondary);">No team members match.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1.25rem;" x-show="detail" x-ref="detail">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div class="card-title" x-text="detail ? detail.user.name + ' — ' + detail.summary.completed + ' of ' + detail.summary.chapters + ' chapters, ' + detail.summary.percent + '%' : ''"></div>
        <button type="button" class="btn btn-secondary btn-sm" @click="detail = null">Close</button>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="table">
                <thead><tr><th>#</th><th>Chapter</th><th>Length</th><th style="width:220px;">Watched</th><th>Completed</th><th>Last Watched</th></tr></thead>
                <tbody>
                    <template x-for="c in (detail ? detail.chapters : [])" :key="c.id">
                        <tr>
                            <td x-text="c.chapter_no"></td>
                            <td x-text="c.title"></td>
                            <td x-text="fmt(c.duration_seconds)"></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:.5rem;">
                                    <div class="tr-bar" style="flex:1;" :class="c.completed_at ? 'is-done' : ''"><span :style="'width:' + (c.completed_at ? 100 : c.percent) + '%'"></span></div>
                                    <span style="font-size:.8rem;min-width:36px;text-align:right;" x-text="(c.completed_at ? 100 : c.percent) + '%'"></span>
                                </div>
                            </td>
                            <td x-text="c.completed_at ? when(c.completed_at) : '—'"></td>
                            <td x-text="c.last_watched_at ? when(c.last_watched_at) : '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div>

<script>
function trainingReport() {
    const API = '<?= base_url('api/v1/training/report') ?>';
    return {
        loading: true, error: false, users: [], total: 0, q: '', state: '', detail: null,

        async init() {
            if (this._inited) return; // Alpine double-init guard — before the first await
            this._inited = true;
            try {
                const [team, cat] = await Promise.all([FF_Api.get(API), FF_Api.get('<?= base_url('api/v1/training') ?>')]);
                if (!team.success || !cat.success) throw new Error();
                this.users = team.data.users;
                this.total = cat.data.summary.chapters;
            } catch (e) { this.error = true; }
            this.loading = false;
        },

        // "Completed course" = every chapter completed, not 100% of seconds
        // (a chapter completes at 90%, so seconds-percent rarely reaches 100).
        stateOf(u) { return this.total && u.completed >= this.total ? 'done' : (u.started > 0 ? 'progress' : 'none'); },
        count(s) { return this.users.filter(u => this.stateOf(u) === s).length; },

        get filtered() {
            const q = this.q.trim().toLowerCase();
            return this.users.filter(u =>
                (!q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)) &&
                (!this.state || this.stateOf(u) === this.state));
        },

        async openUser(u) {
            const res = await FF_Api.get(API + '?user_id=' + u.id);
            if (!res.success) return;
            this.detail = res.data;
            this.$nextTick(() => this.$refs.detail.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        },

        when(ts) {
            const d = FF_parseUtc(ts);
            return d ? d.toLocaleString('en-CA', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
        },
        fmt(s) { const m = Math.floor(s / 60); return m + ':' + String(s % 60).padStart(2, '0'); },
    };
}
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
