#!/bin/bash
# ============================================================
# FleetForge — Production deploy runner
#
# Operator-facing post-`git push` deploy sequence. Always runs
# 3 commands in order, no decision-tree (per E-DEPLOY-RUNBOOK
# in docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md and the 2026-05-20
# rule revision):
#
#   1. git pull origin main
#   2. migrate.php --apply
#   3. systemctl reload php8.2-fpm
#
# Why no decision-tree: each command is safe + cheap to run when
# "not strictly needed" (git pull on already-current → no-op,
# migrate --apply with nothing pending → ~50ms idempotent verify,
# systemctl reload → graceful sub-second, no requests dropped).
# The 2026-05-19 S-PERM-SESSION-REFRESH incident happened because
# an earlier version of this script let operators skip steps based
# on the diff — and they skipped wrong. Always-run-all-3 removes
# the decision point entirely.
#
# Schema quick-ref regeneration (per F-SCHEMA-REF-1) is a SEPARATE
# step done on a workstation after the deploy, NOT here. Keeps
# production server clean of uncommitted file drift.
#
# Origin: locked in 2026-05-20 post-S-PERM-SESSION-REFRESH
# incident postmortem.
#
# Reference:
#   docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md — ITEM E-DEPLOY-RUNBOOK
#   docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11 — Trap 52 (opcache)
#
# Usage:
#   sudo /var/www/fleetforge/bin/deploy.sh
#
# Manual equivalent (if you'd rather type them or sudo password
# is a hassle):
#   cd /var/www/fleetforge
#   sudo -u www-data git pull origin main
#   sudo -u www-data php bin/migrate.php --apply
#   sudo systemctl reload php8.2-fpm
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
    echo "  or use the manual equivalent (3 commands) — see comment block at top of this file"
    exit 1
fi

if [ ! -d "$REPO_DIR/.git" ]; then
    echo "✖ $REPO_DIR is not a git checkout — refusing to proceed"
    exit 1
fi

cd "$REPO_DIR"

BEFORE_SHA=$(git rev-parse HEAD)

echo "════════════════════════════════════════════════════════════"
echo "FleetForge deploy — $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  Repo:     $REPO_DIR"
echo "  HEAD pre: $BEFORE_SHA"
echo "════════════════════════════════════════════════════════════"

# ── Step 1 — git pull (always) ───────────────────────────────
echo
echo "── [1/3] git pull origin main ──────────────────────────────"
sudo -u "$WEB_USER" git pull origin main
AFTER_SHA=$(git rev-parse HEAD)
if [ "$BEFORE_SHA" = "$AFTER_SHA" ]; then
    echo "  (no new commits — already at $AFTER_SHA; running remaining steps anyway for safety)"
else
    echo "  New commits:"
    git log --oneline "$BEFORE_SHA..$AFTER_SHA" | sed 's/^/    /'
fi

# ── Step 2 — migrate apply (always, idempotent) ──────────────
echo
echo "── [2/3] migrate apply (idempotent — no-op when nothing pending) ──"
sudo -u "$WEB_USER" php bin/migrate.php --apply
echo
echo "    verify:"
sudo -u "$WEB_USER" php bin/migrate.php --verify | sed 's/^/      /'

# ── Step 3 — systemctl reload php-fpm (always) ───────────────
echo
echo "── [3/3] systemctl reload $FPM_UNIT ───────────────────────"
systemctl reload "$FPM_UNIT"
systemctl is-active --quiet "$FPM_UNIT" && echo "  ✓ $FPM_UNIT is active" || {
    echo "  ✖ $FPM_UNIT is NOT active after reload — falling back to restart"
    systemctl restart "$FPM_UNIT"
    systemctl is-active --quiet "$FPM_UNIT" && echo "  ✓ $FPM_UNIT restarted OK" || {
        echo "  ✖ $FPM_UNIT failed to restart — deploy is in a BAD state"
        exit 2
    }
}

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
echo
echo "If a migration ran (look at step 2 output), schema quick-ref needs"
echo "regenerating on a workstation per F-SCHEMA-REF-1:"
echo "  php scripts/generate_schema_ref.php"
echo "  git add docs/FLEETFORGE_SCHEMA_QUICK_REF.md && git commit && git push"
