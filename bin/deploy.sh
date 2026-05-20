#!/bin/bash
# ============================================================
# FleetForge — Production deploy runner
#
# Operator-facing post-`git push` deploy sequence. Replaces the
# 3-step manual ritual (migrate verify+apply+verify → systemctl
# reload php-fpm → schema_quick_ref regen) with a single
# fail-fast command that runs them in the right order.
#
# Origin: locked in S-DEPLOY-RUNBOOK-2026-05-20 after the
# S-PERM-SESSION-REFRESH live-prod incident — operator pushed
# c3684d4 to origin/main, but never ran migrate --apply (so a
# new column was missing) AND never reloaded php-fpm (so the
# new top-level function in includes/auth.php was invisible to
# workers via stale opcache). Both failure modes hit the
# api/bootstrap.php exception handler which deliberately hides
# the real message in production, so the UI just showed an
# opaque "An unexpected error occurred". Avoiding that gap is
# what this script exists for.
#
# Reference:
#   docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md — ITEM E-DEPLOY-RUNBOOK
#   docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11 — Trap 52 (opcache)
#
# Usage:
#   sudo /var/www/fleetforge/bin/deploy.sh
#
# Behaviour:
#   - `set -e` aborts on the first failed step (safer than
#     partial deploys that leave migrate applied but php-fpm
#     stale).
#   - All four steps are idempotent — re-running after a partial
#     failure is safe.
#   - On a no-op pull (already up to date), steps 2-4 still run.
#     Each is cheap (single-row PK reads + signal send + 5kb
#     dump) so the overhead is < 5 seconds total.
#
# Hard-coded environment assumptions (Lightsail standard layout
# from the 2026-05-16 deploy):
#   - Repo at /var/www/fleetforge
#   - www-data is the web user
#   - php-fpm unit is php8.2-fpm
# Edit the constants below if these change.
# ============================================================

set -euo pipefail

# ── Constants ────────────────────────────────────────────────
REPO_DIR="/var/www/fleetforge"
WEB_USER="www-data"
FPM_UNIT="php8.2-fpm"

# ── Pre-flight ────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    echo "✖ deploy.sh must be run as root (uses sudo internally — easier to run as root once)"
    echo "  try:  sudo $REPO_DIR/bin/deploy.sh"
    exit 1
fi

if [ ! -d "$REPO_DIR/.git" ]; then
    echo "✖ $REPO_DIR is not a git checkout — refusing to proceed"
    exit 1
fi

cd "$REPO_DIR"

# Capture the HEAD before the pull so we can report what changed.
BEFORE_SHA=$(git rev-parse HEAD)

echo "════════════════════════════════════════════════════════════"
echo "FleetForge deploy — $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  Repo:     $REPO_DIR"
echo "  HEAD pre: $BEFORE_SHA"
echo "════════════════════════════════════════════════════════════"

# ── Step 1 — git pull ────────────────────────────────────────
echo
echo "── [1/4] git pull origin main ──────────────────────────────"
sudo -u "$WEB_USER" git pull origin main
AFTER_SHA=$(git rev-parse HEAD)

if [ "$BEFORE_SHA" = "$AFTER_SHA" ]; then
    echo "  (no new commits — already at $AFTER_SHA)"
    NEW_COMMITS=""
else
    NEW_COMMITS=$(git log --oneline "$BEFORE_SHA..$AFTER_SHA")
    echo "  New commits:"
    echo "$NEW_COMMITS" | sed 's/^/    /'
fi

# Determine what changed in this pull to decide which downstream steps must run.
# When BEFORE == AFTER (no-op pull), we still re-run all steps for safety
# (idempotent + cheap). When there ARE new commits, we inspect their diff.
NEEDS_MIGRATE=1
NEEDS_FPM_RELOAD=1
NEEDS_SCHEMA_REGEN=1

if [ "$BEFORE_SHA" != "$AFTER_SHA" ]; then
    CHANGED=$(git diff --name-only "$BEFORE_SHA" "$AFTER_SHA")
    echo "$CHANGED" | grep -q '^db_migrations/' || NEEDS_MIGRATE=0
    echo "$CHANGED" | grep -qE '\.php$'        || NEEDS_FPM_RELOAD=0
    # schema_quick_ref regen tracks migrations specifically
    NEEDS_SCHEMA_REGEN=$NEEDS_MIGRATE
    echo
    echo "  Decision:"
    echo "    migrate --apply        : $([ $NEEDS_MIGRATE -eq 1 ]    && echo YES || echo skip)"
    echo "    systemctl reload fpm   : $([ $NEEDS_FPM_RELOAD -eq 1 ] && echo YES || echo skip)"
    echo "    regen schema_quick_ref : $([ $NEEDS_SCHEMA_REGEN -eq 1 ] && echo YES || echo skip)"
fi

# ── Step 2 — migrate verify → apply → verify ─────────────────
if [ "$NEEDS_MIGRATE" -eq 1 ]; then
    echo
    echo "── [2/4] migrate verify (pre) ─────────────────────────────"
    sudo -u "$WEB_USER" php bin/migrate.php --verify
    echo
    echo "── [2/4] migrate apply ────────────────────────────────────"
    sudo -u "$WEB_USER" php bin/migrate.php --apply
    echo
    echo "── [2/4] migrate verify (post — expect 0 drift / 0 missing)"
    sudo -u "$WEB_USER" php bin/migrate.php --verify
else
    echo
    echo "── [2/4] migrate — SKIPPED (no db_migrations/ files in diff) ──"
fi

# ── Step 3 — systemctl reload php-fpm ────────────────────────
if [ "$NEEDS_FPM_RELOAD" -eq 1 ]; then
    echo
    echo "── [3/4] systemctl reload $FPM_UNIT ───────────────────────"
    systemctl reload "$FPM_UNIT"
    echo "  reload signaled — verifying status..."
    systemctl is-active --quiet "$FPM_UNIT" && echo "  ✓ $FPM_UNIT is active" || {
        echo "  ✖ $FPM_UNIT is NOT active after reload — falling back to restart"
        systemctl restart "$FPM_UNIT"
        systemctl is-active --quiet "$FPM_UNIT" && echo "  ✓ $FPM_UNIT restarted OK" || {
            echo "  ✖ $FPM_UNIT failed to restart — deploy is in a BAD state"
            exit 2
        }
    }
else
    echo
    echo "── [3/4] php-fpm reload — SKIPPED (no .php files in diff) ──"
fi

# ── Step 4 — schema_quick_ref regeneration ───────────────────
if [ "$NEEDS_SCHEMA_REGEN" -eq 1 ]; then
    echo
    echo "── [4/4] regenerate docs/FLEETFORGE_SCHEMA_QUICK_REF.md ───"
    sudo -u "$WEB_USER" php scripts/generate_schema_ref.php
    # Check if the regenerated file differs from HEAD — if it does, the operator
    # should commit it. We don't auto-commit because deploy.sh runs as root +
    # operator's git identity isn't configured server-side.
    if ! git diff --quiet docs/FLEETFORGE_SCHEMA_QUICK_REF.md; then
        echo "  ⚠ schema_quick_ref has new diff vs HEAD."
        echo "    On a workstation, run:"
        echo "      git pull && php scripts/generate_schema_ref.php && \\"
        echo "      git add docs/FLEETFORGE_SCHEMA_QUICK_REF.md && \\"
        echo "      git commit -m 'docs: regenerate schema quick-ref post-deploy' && git push"
        echo "    (or reset the working tree here — \`git checkout docs/FLEETFORGE_SCHEMA_QUICK_REF.md\`"
        echo "    — and do the regen on a workstation instead.)"
    else
        echo "  (no diff — schema_quick_ref already current)"
    fi
else
    echo
    echo "── [4/4] schema_quick_ref regen — SKIPPED (no migration ran) ──"
fi

# ── Done ──────────────────────────────────────────────────────
echo
echo "════════════════════════════════════════════════════════════"
echo "DEPLOY COMPLETE — $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  HEAD post: $AFTER_SHA"
echo "════════════════════════════════════════════════════════════"
echo
echo "Next step: smoke-check in a browser."
echo "  Open https://mainlandrentals.com/fleetforge/dashboard"
echo "  Log in, exercise one path that touches the newly-deployed code."
echo "  Expect HTTP 200, no 'An unexpected error occurred' messages."
