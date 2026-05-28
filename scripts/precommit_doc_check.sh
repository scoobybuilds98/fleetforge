#!/usr/bin/env bash
#
# scripts/precommit_doc_check.sh
#
# Pre-commit verification wrapper. Runs before EVERY commit to catch
# canonical-doc completeness gaps that the D131 smoke gate enforces.
#
# Usage:
#   ./scripts/precommit_doc_check.sh
#
# Exit codes:
#   0 — all checks pass; safe to commit
#   1 — at least one check failed; investigate before commit
#
# What this script enforces (per D-DOC-FRESHNESS-EXPAND-2):
#   - tests/_smoke_doc_freshness.php passes (CLASSES 1-11)
#     → CLASS 10 is the critical one: catches sessions committed without
#       a SESSION LOG row in PROGRESS.md (the S-QBO-BILL-SYNC-UI failure
#       mode and the S-SES-DIAG failure mode the operator caught manually
#       before the smoke existed).
#   - tests/_smoke_master_schema_parity.php passes (DATABASE_MASTER ↔ live DB)
#   - bin/migrate.php --verify passes (no drift)
#
# What this script does NOT enforce (operator discipline still required):
#   - Whether the session prompt's STOP conditions actually fired
#   - Whether all touched files are staged
#   - Whether the commit message accurately describes the work
#
# Reference: docs/FLEETFORGE_PROGRESS.md DECISIONS table D-DOC-FRESHNESS-
# EXPAND-2-1/2/3 + feedback_canonical_doc_checklist.md memory file.
#
# 2026-05-27 | S-DOC-FRESHNESS-EXPAND-2

set -u

cd "$(dirname "$0")/.."

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "  PRE-COMMIT DOC CHECK — S-DOC-FRESHNESS-EXPAND-2"
echo "════════════════════════════════════════════════════════════════"
echo ""

fail_count=0

run_check() {
  local name="$1"
  local cmd="$2"
  printf "  → %-40s " "$name"
  if eval "$cmd" > /tmp/precommit_doc_check.log 2>&1; then
    echo -e "${GREEN}PASS${NC}"
  else
    echo -e "${RED}FAIL${NC}"
    fail_count=$((fail_count + 1))
    echo ""
    echo -e "${YELLOW}  --- output ---${NC}"
    tail -20 /tmp/precommit_doc_check.log | sed 's/^/    /'
    echo ""
  fi
}

run_check "doc_freshness (CLASSES 1-11)"   "php tests/_smoke_doc_freshness.php"
run_check "master_schema_parity"           "php tests/_smoke_master_schema_parity.php"
run_check "migrate --verify"               "php bin/migrate.php --verify"

echo ""
if [ "$fail_count" -eq 0 ]; then
  echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
  echo -e "${GREEN}  ALL PRE-COMMIT CHECKS PASSED${NC}"
  echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
  echo ""
  echo "  Mental checklist before commit:"
  echo "    [ ] SESSION LOG row in PROGRESS.md added"
  echo "    [ ] DECISIONS row(s) for any new D-* locks"
  echo "    [ ] SHIPPED entry in CURRENT_SESSIONS.md (2026-MM-DD block)"
  echo "    [ ] If QBO-relevant:"
  echo "         - QUICKBOOKS_PROGRESS.md §1 header bump + §2 SESSION LOG row"
  echo "         - QUICKBOOKS_PROGRESS.md §3 D-QBO-N decision table"
  echo "         - QUICKBOOKS_PROGRESS.md §4 SCHEMA CHANGES (if migration)"
  echo "         - QUICKBOOKS_PROGRESS.md §10 NEXT SESSION UP (if next-up changed)"
  echo "    [ ] CLAUDE_CODE_REFERENCE.md D131 extension (if smoke count/sub-checks changed)"
  echo "    [ ] QBO_REMAINING_WORK_2026-05-26.md progress markers (if QBO-relevant)"
  echo "    [ ] QUICKBOOKS_SPEC.md (if architecture shifted)"
  echo ""
  echo "  Then: git add <files> && git commit -m '...' && git push origin main"
  echo ""
  exit 0
else
  echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
  echo -e "${RED}  $fail_count CHECK(S) FAILED — DO NOT COMMIT${NC}"
  echo -e "${RED}════════════════════════════════════════════════════════════════${NC}"
  echo ""
  echo "  Most common causes:"
  echo "    - Session shipped without SESSION LOG row (CLASS 10 catches this)"
  echo "    - Schema drift between live DB and DATABASE_MASTER.sql"
  echo "    - Migration missing or sha256 mismatch"
  echo ""
  echo "  Fix the failure, re-run this script, then commit."
  echo ""
  exit 1
fi
