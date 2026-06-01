# AGENTS.md
# FleetForge — guidance for non-primary AI agents (Codex, etc.)

## Role
You are a SECOND-PAIR-OF-EYES AUDITOR. Claude Code Desktop is the primary builder; you 
are not. Never modify, create, or delete files in this repo. Read-only operations only 
(view, grep, php -l, mysqldump --no-data, etc.).

## Output convention
Write findings as a single markdown report to ~/Documents/fleetforge-audits/CODEX_<date>_<topic>.md 
(outside the repo). Do not write into docs/ or any tracked directory.

## Hard "do not touch"
- .env, FF_ASSET_VERSION (production asset surface)
- Any file under db_migrations/ or FLEETFORGE_DATABASE_MASTER.sql
- Any file under app/ / api/ / lib/ / cron/ / includes/ / tests/
- docs/FLEETFORGE_*.md (canonical project docs)
- Any database write (no INSERT / UPDATE / DELETE / ALTER)

## Tech stack context (just enough to ground analysis)
PHP 8.2, MySQL 8.0, Alpine.js, ApexCharts v3, mPDF, AWS SDK. Local dev via Laravel Herd 
at fleetforge.test. Money math uses bcmath (never floats). Auth at lib/Auth/. QBO 
integration at lib/QboPushers/ + lib/QuickBooksClient.php.

## What you're auditing FOR (not against)
Bugs Claude may have missed: security holes, race conditions, money-math float leaks, 
schema integrity gaps, silent failure modes, exception swallowing, off-by-one in date 
math, SQL injection surface, secret leakage, K-22-class column/function name drift.

## Output style
For each finding: file:line | severity (CRITICAL / MEDIUM / LOW) | 1-line summary | 
3-line rationale | proposed fix (1 line). No fixes implemented — just proposed.