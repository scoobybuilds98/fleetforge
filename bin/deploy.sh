#!/bin/bash
# ============================================================
# FleetForge — Production deploy runner (S-DEPLOY-SAFETY-1 hardened)
#
# ONE atomic, gated, abort-on-failure deploy command:
#   clean-tree → fetch → confirm → pull --ff-only → backup → composer →
#   migrate --apply → assert 0 pending → reload php-fpm → health gate → deploy-log
#
# `set -euo pipefail` + explicit `abort` on every step — the script NEVER
# continues past a failed pull / backup / migrate / health check. The 2026-06-05
# outage was a schema lag that surfaced only at first request; step 7
# (assert 0 pending) + step 9 (health gate on /api/v1/health migrate-state) make
# that detectable AT DEPLOY TIME and abort before serving traffic.
#
# ── History this hardening preserves (do not delete) ─────────────────────────
# Built originally as "always-run-all-4, no decision-tree" after the 2026-05-20
# S-PERM-SESSION-REFRESH incident (E-DEPLOY-RUNBOOK): an operator pushed code but
# never ran migrate.php --apply (new users.permissions_updated_at column) NOR
# reloaded php-fpm (opcache held pre-push bytecode → "Call to undefined function
# _ff_check_permission_freshness") → opaque HTTP 500s ~1h after push. The lesson:
# NEVER skip a step (especially migrate + reload). This hardened version keeps
# that — all steps always run; composer install is retained. The NEW interactive
# confirm (step 3) gates WHICH COMMITS SHIP, not WHICH STEPS RUN — it stops blind-
# shipping unfinished work; --yes skips it for unattended/automation use. The two
# philosophies are orthogonal and both honored.
#
# ── Validate on STAGING first ────────────────────────────────────────────────
# This script CANNOT be end-to-end tested from a dev box (no systemctl/sudo/prod
# paths). It MUST be run on STAGING before its first production use. See
# docs/runbooks/deploy.md (usage + rollback) and docs/runbooks/staging_setup.md.
#
# ── Usage ────────────────────────────────────────────────────────────────────
#   sudo /var/www/fleetforge/bin/deploy.sh            # interactive (confirm to ship)
#   sudo /var/www/fleetforge/bin/deploy.sh --yes      # skip the ship confirmation
#        /var/www/fleetforge/bin/deploy.sh --dry-run  # print the full sequence, run nothing destructive (no root needed)
#
# All paths/URLs/service names are parameterized via env (D-DEPLOY-3) so the same
# script serves STAGING and PROD — e.g. on staging:
#   FF_DEPLOY_BASE_URL=https://staging.mainlandrentals.com/fleetforge \
#   FF_DEPLOY_REPO_DIR=/var/www/fleetforge sudo -E bin/deploy.sh
#
# Reference:
#   docs/runbooks/deploy.md                       — usage + ROLLBACK procedure
#   docs/FLEETFORGE_PREDEPLOY_CHECKLIST.md        — ITEM E-DEPLOY-RUNBOOK
#   docs/runbooks/restore_drill.md                — DB restore (rollback path)
#   docs/FLEETFORGE_CLAUDE_CODE_REFERENCE.md §11  — Trap 52 (opcache)
# ============================================================

set -euo pipefail

# ── Parameterized constants (env-overridable — D-DEPLOY-3) ────
REPO_DIR="${FF_DEPLOY_REPO_DIR:-/var/www/fleetforge}"
WEB_USER="${FF_DEPLOY_WEB_USER:-www-data}"
FPM_UNIT="${FF_DEPLOY_FPM_UNIT:-php8.2-fpm}"
BASE_URL="${FF_DEPLOY_BASE_URL:-https://mainlandrentals.com/fleetforge}"
HEALTH_URL="${FF_DEPLOY_HEALTH_URL:-${BASE_URL}/api/v1/health}"
LOGIN_URL="${FF_DEPLOY_LOGIN_URL:-${BASE_URL}/auth/login}"
ERROR_LOG="${FF_DEPLOY_ERROR_LOG:-/var/log/php8.2-fpm.log}"
DEPLOY_LOG="${FF_DEPLOY_LOG:-${REPO_DIR}/logs/deploy.log}"

# ── Flags ─────────────────────────────────────────────────────
ASSUME_YES=0
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --yes|-y)   ASSUME_YES=1 ;;
        --dry-run)  DRY_RUN=1 ;;
        -h|--help)
            sed -n '2,52p' "$0"
            exit 0
            ;;
        *)
            echo "✖ unknown argument: $arg (use --yes, --dry-run, or --help)"
            exit 2
            ;;
    esac
done

# ── Helpers ───────────────────────────────────────────────────
abort() {
    echo ""
    echo "════════════════════════════════════════════════════════════"
    echo "✖ DEPLOY ABORTED: $1"
    echo "  Nothing further will run. Rollback if a partial change landed:"
    echo "    docs/runbooks/deploy.md — revert code to ${BEFORE_SHA:-<prior HEAD>}"
    echo "    + restore the pre-deploy dump via docs/runbooks/restore_drill.md"
    echo "════════════════════════════════════════════════════════════"
    exit "${2:-1}"
}

# run a destructive command, or just print it in --dry-run
run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "    [dry-run] would run: $*"
    else
        "$@"
    fi
}

# ── Maintenance-mode gate (FLEETFORGE-C) ─────────────────────────────────────
# The deploy mutates the live checkout in place (git pull, step 4) while php-fpm
# keeps serving. opcache (validate_timestamps=On) only clears at the step-8
# reload, so a request landing in the pull→migrate→reload window can compile a
# freshly-pulled functions.php against stale opcode → "Cannot redeclare ...".
# Flipping MAINTENANCE_MODE=true in .env (read at runtime, NOT opcode-cached;
# .env is gitignored so the pull never clobbers it) makes public/index.php serve
# a 503 to web traffic for that window. CLI deploy steps (backup/composer/migrate)
# are unaffected — the gate is web-only. Fail-CLOSED: if the deploy aborts after
# enabling maintenance, the on_exit trap leaves the site in maintenance (don't
# serve half-migrated state) and prints how to clear it.
MAINT_ON=0
ENV_FILE="$REPO_DIR/.env"

set_maintenance() {
    local val="$1"   # true | false
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "    [dry-run] would set MAINTENANCE_MODE=$val in $ENV_FILE"
        return 0
    fi
    if [ ! -f "$ENV_FILE" ]; then
        echo "  ⚠ $ENV_FILE not found — cannot toggle maintenance mode"
        return 1
    fi
    local owner; owner="$(stat -c '%U:%G' "$ENV_FILE" 2>/dev/null || true)"
    if grep -qE '^MAINTENANCE_MODE=' "$ENV_FILE"; then
        sed -i "s/^MAINTENANCE_MODE=.*/MAINTENANCE_MODE=$val/" "$ENV_FILE"
    else
        printf '\nMAINTENANCE_MODE=%s\n' "$val" >> "$ENV_FILE"
    fi
    [ -n "$owner" ] && chown "$owner" "$ENV_FILE" 2>/dev/null || true
}

on_exit() {
    if [ "$MAINT_ON" -eq 1 ]; then
        echo ""
        echo "⚠⚠⚠  SITE IS STILL IN MAINTENANCE MODE — deploy did not complete cleanly  ⚠⚠⚠"
        echo "  The public site is serving 503 (fail-closed: half-deployed state is not served)."
        echo "  After verifying DB/code state, clear it with:"
        echo "    sudo sed -i 's/^MAINTENANCE_MODE=.*/MAINTENANCE_MODE=false/' $ENV_FILE"
    fi
}
trap on_exit EXIT

# ── Pre-flight ────────────────────────────────────────────────
if [ "$DRY_RUN" -ne 1 ] && [ "$(id -u)" -ne 0 ]; then
    echo "✖ deploy.sh must be run as root (uses sudo -u ${WEB_USER} internally)"
    echo "  try:  sudo $REPO_DIR/bin/deploy.sh   (or add --dry-run to preview without root)"
    exit 1
fi

if [ ! -d "$REPO_DIR/.git" ]; then
    echo "✖ $REPO_DIR is not a git checkout — refusing to proceed"
    exit 1
fi

cd "$REPO_DIR"

BEFORE_SHA="$(git rev-parse HEAD)"

echo "════════════════════════════════════════════════════════════"
echo "FleetForge deploy — $(date '+%Y-%m-%d %H:%M:%S %Z')$([ "$DRY_RUN" -eq 1 ] && echo '  [DRY-RUN]')"
echo "  Repo:     $REPO_DIR"
echo "  Target:   $BASE_URL"
echo "  HEAD pre: $BEFORE_SHA"
echo "════════════════════════════════════════════════════════════"

# ── [1/10] clean-tree precheck ───────────────────────────────
echo
echo "── [1/10] clean-tree precheck ──────────────────────────────"
# --untracked-files=no: only TRACKED modifications/staged changes block a deploy
# (those would collide with the pull). Benign untracked runtime files (uploads,
# logs, storage/) must NOT abort a deploy.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no | sed 's/^/    /'
    abort "uncommitted changes to tracked files in $REPO_DIR — commit, stash, or discard before deploying"
fi
echo "  ✓ working tree clean (no tracked-file modifications)"

# ── [2/10] git fetch origin ──────────────────────────────────
echo
echo "── [2/10] git fetch origin ─────────────────────────────────"
if [ "$DRY_RUN" -eq 1 ]; then
    # read-only; tolerate failure on a box without origin/network so the dry-run
    # can still print the full sequence.
    git fetch origin --quiet 2>/dev/null && echo "  ✓ fetched" || echo "  ⚠ [dry-run] fetch failed (no origin/network?) — continuing"
else
    { sudo -u "$WEB_USER" git fetch origin --quiet 2>/dev/null || git fetch origin --quiet; } || abort "git fetch origin failed — check network / remote"
    echo "  ✓ fetched"
fi

# ── [3/10] show incoming + confirm (gates WHAT ships) ────────
echo
echo "── [3/10] incoming commits (HEAD..origin/main) ─────────────"
INCOMING="$(git log --oneline "HEAD..origin/main" || true)"
if [ -z "$INCOMING" ]; then
    echo "  (already up to date with origin/main — running remaining steps anyway for safety)"
else
    echo "$INCOMING" | sed 's/^/    /'
fi
if [ "$ASSUME_YES" -ne 1 ] && [ "$DRY_RUN" -ne 1 ]; then
    printf "  Ship these commits to %s ? [y/N] " "$BASE_URL"
    read -r ANS
    case "$ANS" in
        [yY]|[yY][eE][sS]) echo "  ✓ confirmed" ;;
        *) echo "  Deploy cancelled by operator (no changes made)."; exit 0 ;;
    esac
elif [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would prompt: Ship these commits? [y/N]  (--yes skips)"
fi

# ── maintenance ON — protect the opcache-inconsistent window (FLEETFORGE-C) ──
echo
echo "── maintenance ON (covers the pull→migrate→reload window) ──"
set_maintenance true
[ "$DRY_RUN" -eq 1 ] || MAINT_ON=1
[ "$DRY_RUN" -eq 1 ] || echo "  ✓ MAINTENANCE_MODE=true — public traffic gets 503; CLI deploy steps continue"

# ── [4/10] git pull --ff-only ────────────────────────────────
echo
echo "── [4/10] git pull --ff-only origin main ───────────────────"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would run: sudo -u $WEB_USER git pull --ff-only origin main"
else
    sudo -u "$WEB_USER" git pull --ff-only origin main || abort "git pull --ff-only failed (non-fast-forward / local divergence) — reconcile manually"
fi
AFTER_SHA="$(git rev-parse HEAD)"

# ── [5/10] DB backup BEFORE migrate (rollback point) ─────────
echo
echo "── [5/10] DB backup (pre-migrate rollback point) ───────────"
# Reuses the existing 6h backup tooling — NOT a hand-rolled mysqldump.
run sudo -u "$WEB_USER" php cron/backup_db.php
[ "$DRY_RUN" -eq 1 ] || echo "  ✓ backup created (see storage/backups/db/ or S3)"

# ── [6/10] composer install (retained, idempotent) ──────────
echo
echo "── [6/10] composer install --no-dev ────────────────────────"
run sudo -u "$WEB_USER" composer install --no-dev --optimize-autoloader --no-interaction

# ── [7/10] migrate --apply + assert 0 pending ───────────────
echo
echo "── [7/10] migrate --apply, then assert 0 pending ───────────"
# D-BASELINE-3 guard: if 000_baseline.sql is present but NOT yet in schema_migrations,
# this deploy MUST NOT run migrate --apply automatically — the operator must pre-mark it
# applied first (INSERT ... 000_baseline) to prevent CREATE TABLE IF NOT EXISTS running
# in the wrong order. See docs/runbooks/baseline_reconcile.md for the exact one-time
# manual sequence: pull → pre-mark INSERT → migrate --apply → reload → health check.
BASELINE_FILE="$REPO_DIR/db_migrations/000_baseline.sql"
if [ -f "$BASELINE_FILE" ] && [ "$DRY_RUN" -ne 1 ]; then
    BASELINE_MARKED="$(sudo -u "$WEB_USER" php -r "
        require_once '$REPO_DIR/config/app.php';
        try {
            \$rows = db_select(\"SELECT 1 FROM schema_migrations WHERE filename = '000_baseline.sql' LIMIT 1\", []);
            echo count(\$rows) > 0 ? 'yes' : 'no';
        } catch (\Throwable \$e) {
            echo 'no';
        }
    " 2>/dev/null || echo 'no')"
    if [ "$BASELINE_MARKED" != "yes" ]; then
        abort "000_baseline.sql is present but NOT marked applied in schema_migrations.
  This deploy must follow the SPECIAL one-time baseline reconcile procedure:
    1. git pull  (already done)
    2. Run the pre-mark INSERT: see docs/runbooks/baseline_reconcile.md
    3. php bin/migrate.php --apply
    4. systemctl reload php8.2-fpm
    5. Verify health + migrate --status
  Do NOT re-run deploy.sh until step 2 is complete."
    fi
    echo "  ✓ 000_baseline.sql pre-marked in schema_migrations (baseline reconcile done)"
fi
if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would check: 000_baseline.sql pre-marked in schema_migrations"
    echo "    [dry-run] would run: sudo -u $WEB_USER php bin/migrate.php --apply"
    echo "    [dry-run] would run: sudo -u $WEB_USER php bin/migrate.php --assert-applied  (abort if exit≠0)"
else
    sudo -u "$WEB_USER" php bin/migrate.php --apply || abort "migrate --apply FAILED — DB may be partially migrated; see rollback"
    sudo -u "$WEB_USER" php bin/migrate.php --assert-applied || abort "migrations STILL PENDING after --apply (schema lag) — refusing to serve traffic"
    echo "  ✓ 0 pending migrations"
fi

# ── [8/10] systemctl reload php-fpm (clears opcache) ────────
echo
echo "── [8/10] systemctl reload $FPM_UNIT ───────────────────────"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would run: systemctl reload $FPM_UNIT"
else
    # Capture error-log size BEFORE reload so step 9 scans only NEW lines.
    LOG_BYTES_BEFORE=0
    if [ -r "$ERROR_LOG" ]; then
        LOG_BYTES_BEFORE="$(wc -c < "$ERROR_LOG" 2>/dev/null || echo 0)"
    fi
    systemctl reload "$FPM_UNIT"
    if systemctl is-active --quiet "$FPM_UNIT"; then
        echo "  ✓ $FPM_UNIT active"
    else
        echo "  ✖ $FPM_UNIT not active after reload — attempting restart"
        systemctl restart "$FPM_UNIT"
        systemctl is-active --quiet "$FPM_UNIT" || abort "$FPM_UNIT failed to restart — BAD state" 2
        echo "  ✓ $FPM_UNIT restarted"
    fi
fi

# ── maintenance OFF — reload done, new bytecode live, opcache cleared ────────
# Safe to expose now: the pull→migrate→reload window (the opcache-inconsistent
# zone) is closed. The health gate below then validates the LIVE (non-maintenance)
# site. If the gate fails, the abort message tells the operator to re-gate.
echo
echo "── maintenance OFF (new code live + opcache cleared) ──"
set_maintenance false
[ "$DRY_RUN" -eq 1 ] || MAINT_ON=0
[ "$DRY_RUN" -eq 1 ] || echo "  ✓ MAINTENANCE_MODE=false — site live"

# ── [9/10] health gate ───────────────────────────────────────
echo
echo "── [9/10] health gate ──────────────────────────────────────"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would curl: $HEALTH_URL  (require 200 + db:true + 0 pending + schema.ok)"
    echo "    [dry-run] would curl: $LOGIN_URL   (require 200)"
    echo "    [dry-run] would scan: $ERROR_LOG   (no fresh PHP Fatal / Uncaught)"
else
    # health.php: parse JSON via php (guaranteed present); assert the gate fields.
    HEALTH_JSON="$(curl -fsS --max-time 15 "$HEALTH_URL" 2>/dev/null || true)"
    [ -n "$HEALTH_JSON" ] || abort "health endpoint $HEALTH_URL returned nothing / non-2xx"
    printf '%s' "$HEALTH_JSON" | php -r '
        $j = json_decode(stream_get_contents(STDIN), true);
        $d = $j["data"] ?? null;
        if (!is_array($d)) { fwrite(STDERR, "unparseable health payload\n"); exit(1); }
        $okDb     = (($d["db"] ?? false) === true);
        $okPend   = (($d["migrations"]["pending"] ?? -1) === 0);
        $okSchema = (($d["schema"]["ok"] ?? false) === true);
        fwrite(STDERR, sprintf("    health: status=%s db=%s pending=%s schema_ok=%s\n",
            $d["status"] ?? "?", var_export($d["db"] ?? null, true),
            var_export($d["migrations"]["pending"] ?? null, true),
            var_export($d["schema"]["ok"] ?? null, true)));
        exit(($okDb && $okPend && $okSchema) ? 0 : 1);
    ' || abort "health gate FAILED (db / pending / schema): $HEALTH_JSON"
    echo "  ✓ health 200 + db:true + 0 pending + schema.ok"

    LOGIN_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$LOGIN_URL" || echo 000)"
    [ "$LOGIN_CODE" = "200" ] || abort "login page $LOGIN_URL returned $LOGIN_CODE (expected 200)"
    echo "  ✓ login page 200"

    if [ -r "$ERROR_LOG" ]; then
        NEW_FATALS="$(tail -c "+$((LOG_BYTES_BEFORE + 1))" "$ERROR_LOG" 2>/dev/null | grep -iE 'PHP Fatal|Uncaught|Call to undefined' || true)"
        if [ -n "$NEW_FATALS" ]; then
            echo "$NEW_FATALS" | sed 's/^/      /'
            abort "fresh PHP fatals in $ERROR_LOG since reload — investigate before declaring success"
        fi
        echo "  ✓ no fresh fatals in $ERROR_LOG"
    else
        echo "  ⚠ $ERROR_LOG not readable — skipping fatal scan (set FF_DEPLOY_ERROR_LOG to enable)"
    fi
fi

# ── [10/10] deploy-log ───────────────────────────────────────
echo
echo "── [10/10] append deploy-log ───────────────────────────────"
MIG_COUNT="$(git diff --name-only "$BEFORE_SHA..$AFTER_SHA" -- db_migrations/ 2>/dev/null | grep -c '\.sql$' || true)"
LOG_LINE="$(date '+%Y-%m-%dT%H:%M:%S%z') deploy ${BEFORE_SHA:0:12} -> ${AFTER_SHA:0:12} migrations_shipped=${MIG_COUNT} by=$(whoami)$([ "$DRY_RUN" -eq 1 ] && echo ' [dry-run]')"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "    [dry-run] would append to $DEPLOY_LOG:"
    echo "      $LOG_LINE"
else
    mkdir -p "$(dirname "$DEPLOY_LOG")"
    echo "$LOG_LINE" >> "$DEPLOY_LOG"
    echo "  ✓ $DEPLOY_LOG"
fi

# ── Done ──────────────────────────────────────────────────────
echo
echo "════════════════════════════════════════════════════════════"
echo "DEPLOY ${DRY_RUN:+DRY-RUN }COMPLETE — $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  $BEFORE_SHA → $AFTER_SHA  (migrations shipped: ${MIG_COUNT})"
echo "════════════════════════════════════════════════════════════"
if [ "$MIG_COUNT" -gt 0 ] 2>/dev/null && [ "$DRY_RUN" -ne 1 ]; then
    echo
    echo "A migration shipped — regenerate schema quick-ref on a workstation (F-SCHEMA-REF-1):"
    echo "  php scripts/generate_schema_ref.php && git add docs/FLEETFORGE_SCHEMA_QUICK_REF.md && git commit && git push"
fi
