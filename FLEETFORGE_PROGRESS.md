# FLEETFORGE — BUILD PROGRESS TRACKER
**Version:** 5.1 | **Project:** FleetForge v2.5 FINAL — All 15 audit passes integrated + build-readiness review applied
**Owner:** Avi — Mainland Truck & Trailer Sales
**Architect / Builder:** Claude Sonnet 4.6
**Schema status:** AUDITED & LOCKED — 28 issues found and corrected (see FLEETFORGE_DATABASE_MASTER.sql (v1.2 — sole schema source) [PASS-1:L7])
**Table count:** 94 (59 core + 34 accounting + 1 utility) | **Sessions:** ~150 atomic vertical slices | **SOFT_DELETE_TABLES:** 15 (payments added)

---

> **EVERY Claude Code session must start with:**
> `"Read FLEETFORGE_SPEC_FINAL.md, FLEETFORGE_PROGRESS.md, and FLEETFORGE_DATABASE_MASTER.sql (v1.2 — sole schema source) [PASS-1:L7] before writing a single line of code."`
>
> SPEC = law. DATABASE_MASTER.sql = sole schema source. PROGRESS = memory across sessions.

---

## HOW TO USE THIS FILE

| Symbol | Meaning |
|--------|---------|
| ✅ | Complete — built, tested, working |
| 🔄 | Partial — started but incomplete |
| ⬜ | Not started |
| ❌ | Skipped / Descoped |
| 🐛 | Has a known bug — see KNOWN ISSUES |
| 🔒 | Locked decision — do not change |

**End of every session — Claude Code MUST:**
1. Mark all touched items ✅ or 🔄
2. Add a row to SESSION LOG
3. Log any new decisions or deviations
4. Add any bugs to KNOWN ISSUES
5. Write the exact NEXT SESSION STARTS WITH instruction

---

## SESSION LOG

| # | Date | Phase | Summary |
|---|------|-------|---------|
| 0 | Pre-build | Schema Audit | 28 schema issues found and corrected. gps_devices dropped. Billing engine architecture locked. AWS setup guide written. All decisions locked. |
| 0.5 | Pre-build | 15-Pass Audit | Comprehensive 15-pass audit integrated. 17 indexes added to schema. Portal security hardened. Invoice/lease state machines corrected. Billing edge cases resolved. CRA compliance gaps addressed. Infrastructure decisions locked (S3, SES, Cloudflare). StorageClient + Mailer added to Session 1 scope. SOFT_DELETE_TABLES expanded to 15. Financial record immutability rules defined. |
| 0.7 | Pre-build | Final File Audit | All 7 project files reviewed. D11 resolved (invoice-time tax rates). D23 added (invite token = 7 days). D24 added (AWS SDK in composer.json is correct). Stale "93 tables" references in spec noted — SQL file (94) is authoritative. Session opening updated to include all 7 files. Ready for Session S001. |
| S001 | 2026-04-01 | Foundation Layer | 20 files built and verified locally. Login renders with full design system. Health endpoint returns db:true. PHP 8.2 confirmed. Covers original plan sessions S005–S013 + router + CSS/JS + error pages + API bootstrap. 4 deviations logged (D25–D28). Lightsail/infrastructure (original S001–S004) deferred to production deployment. |
| S002 | 2026-04-01 | Database Schema + Seed Data + Foundation Carry-overs | 94-table schema applied via PHP PDO runner (MySQL CLI blocked by caching_sha2_password). 7 schema bugs fixed. Seeds: 5 roles + 1 super_admin verified. CSRF functions + require_id() + require_input() added. storage/.htaccess + all subdirs created. Skip-nav added. Deep audit in same session found and fixed 4 bugs: (a) login.php query used wrong column names (role_slug/theme/is_active — all non-existent, login was completely broken), (b) CSRF failure returned 200 not 403, (c) header.php + login.php duplicated CSRF generation instead of calling generate_csrf_token(), (d) logout.php queried non-existent user_remember_tokens table, leaving remember_me token live after logout. All bugs fixed. Login, audit_log, CSRF, StorageClient, Mailer all verified. |
| S003 | 2026-04-01 | Remaining Seed Data + Dashboard Stub | Fixed Bug #9: settings_get() and generate_id() in functions.php used wrong column names (setting_key/setting_value/setting_group vs actual DB columns key/value/group_name — reserved words requiring backtick quoting). All 4 seed files created and applied: 003_permissions.sql (70 rows, 5×14 core modules, role_id resolved by slug JOIN), 004_settings.sql (42 default settings), 005_yard.sql (Surrey Yard), 006_tax_rates.sql (BC GST+PST, Ontario HST, Alberta GST). Dashboard stub at app/admin/dashboard/index.php — all 14 modules visible in sidebar, company name from settings_get() confirmed live. All 6 stop conditions passed. Fixed Bug #10: header.php + footer.php used base_url() for static assets (app.css, app.js, favicon) — produces /fleetforge/assets/... (404 under Herd). Corrected to asset_url() per D27. Dashboard confirmed fully styled after fix. |
| S004 | 2026-04-01 | Dashboard KPIs + Charts API | 3 new API endpoints + dashboard page wired. api/v1/dashboard/kpis.php: 6 KPI tiles (active revenue, fleet utilization, overdue invoices, compliance alerts, open leases, today's pickups) with 5-min report_cache. api/v1/dashboard/charts.php: 8 ApexCharts datasets (revenue trend, fleet status donut, AR aging, top customers, leases trend, utilization trend, revenue by type, weekly heatmap) with 15-min cache, individual ?chart= param supported. api/v1/dashboard/activity_feed.php: 20 most recent audit_log events, noise-filtered, time_ago computed. app/admin/dashboard/index.php: all stubs replaced — Alpine.js FF_Dashboard() component fetches all 3 APIs in parallel on mount, ApexCharts rendered after data arrives, skeleton loaders + error states + empty states + drilldown links on every KPI tile. All stop conditions passed: kpis 200 ✅, charts 200 ✅, ?chart=fleet_status single ✅, ?chart=bogus 404 ✅, activity_feed 200 ✅, dashboard page 200 no PHP errors ✅. NOTE: session assignment in PROGRESS.md said S004=Customers — overridden by user instruction to build Dashboard KPIs first (matches spec Phase 3 order). Customers moved to S005. |
| S005 | 2026-04-01 | Customers Module (API + Admin UI) + Global Design Fixes | 11 files built and verified. API: api/v1/customers/index.php (paginated list, FULLTEXT search, filters), api/v1/customers/show.php (single customer + tags + contacts), api/v1/customers/create.php (with tags + initial note + audit_log, 422 on duplicate), api/v1/customers/update.php (D19 optimistic lock on updated_at, wholesale tag replace, audit_log), api/v1/customers/delete.php (soft-delete, blocks with HAS_ACTIVE_LEASES). Notes: api/v1/customers/notes/index.php (pinned first), api/v1/customers/notes/create.php. Admin UI: index.php (4 KPI tiles + filter bar + data table + pagination, Alpine.js), create.php (7-section form: Identity/Address/Regulatory/Billing Contact/Commercial/Tags/Notes), edit.php (pre-populated with server-side PHP, D19 optimistic lock, tags wholesale replace), show.php (4 quick-stat tiles, 4 tabs: Overview/Notes/Leases stub/Invoices stub, inline note add). Router note: file-based router requires /customers/show?id=1 URL pattern (not /customers/1 — no dynamic segment support). All stop conditions passed: list 200 ✅, create 201 ✅, show 200 ✅, update 200 ✅, stale 409 ✅, soft-delete 200 ✅, list page 200 ✅, create page 200 ✅, no PHP errors ✅. CLOSE-OUT FIXES (same session, second pass): (a) All 4 customer admin UI pages fully rewritten — original versions used ~60 invented CSS class names not present in app.css (Bugs #12); now use only confirmed classes. (b) Created all 32 Heroicons SVG files in public/assets/icons/ — directory was empty, causing theme toggle and sidebar to render as invisible spans (Bug #13). (c) app/admin/dashboard/index.php fixed: FF_API→FF_Api (3×, was ReferenceError silently killing all API fetches), empty-state-primary→empty-state-title and empty-state-secondary→empty-state-text (Bug #14). (d) public/assets/css/app.css: added Section 20 "Dashboard & Page-Specific Components" — 14 new classes: .stat-card--link, .stat-card--danger, .stat-card--warning, .stat-card--error, .stat-skeleton, .chart-skeleton, .grid-2-1, .activity-feed, .activity-item, .activity-dot, .activity-dot--{module}, .activity-body, .activity-desc, .activity-meta, .p-4, .text-muted, .link. Decisions D32–D33 added. All pages and dashboard verified: HTTP 200, no PHP errors, correct JS in rendered output. |
| S007 | 2026-04-01 | Leases Module (API + Admin UI) | 11 files built and all 9 stop conditions passed. API (7): index (paginated, FULLTEXT search, status/customer/unit filters, created_at DESC), show (full lease + status_log, Trap 7 strips file paths), create (FOR UPDATE D20 on unit: checks status=available, snapshots customer+unit+template at creation, tax rates frozen from customer record D11, contract_number CN-XXXXXX-YYYY auto-generated), update (D19 optimistic lock, metadata-only — rates excluded require amendment), delete (soft-delete, blocks active leases with LEASE_NOT_ACTIVE), activate (FOR UPDATE D20: pending→active, unit→on_lease, next_billing_date set for monthly cycle, equipment_status_log + lease_status_log written), close (FOR UPDATE D20: active→completed, unit→available, actual_return_date + mileage_at_end captured, all logs written). Admin UI (4): leases/index.php (3-tab interface: Active+Pending / Closed / All, inline tab styles per D32, search + status filters), leases/show.php (server-side hero render with status badge, Activate/Close action buttons per status, Close modal with return date + mileage, 3 tabs: Overview / Status Log / Amendments stub), leases/create.php (customer dropdown auto-fills currency/billing cycle/tax exemptions, unit dropdown auto-fills template rates, 6 sections: Customer+Unit / Dates / Rates / Discounts+Addons / Tax / Notes), leases/edit.php (server pre-populated, D19 lock, rates read-only — require amendment, editable: dates/po/mileage/addons/notes). Bug fixed during stop-condition testing: equipment_units has no mileage_unit column (removed from queries). Audit_log schema mismatch: action ENUM values 'created'/'updated'/'deleted'/'status_changed' → corrected to 'create'/'update'/'delete'/'status_change'/'lease_closed'; description/entity_label/user_name columns also corrected. All 9 stop conditions passed: GET /leases ✅ 200 paginated, POST /leases/create ✅ 201 id+contract_number returned unit FOR UPDATE, GET /leases/show?id=N ✅ 200 with status_log, POST /leases/activate ✅ 200 pending→active unit→on_lease, POST /leases/create on on_lease unit ✅ 409 UNIT_UNAVAILABLE, POST /leases/close ✅ 200 active→completed unit→available, POST /leases/update stale ✅ 409 STALE_DATA, /leases page ✅ 200 no PHP errors, /leases/create page ✅ 200 no PHP errors. |
| S006 | 2026-04-01 | Equipment Module (Templates + Units API + Admin UI) | 16 files built and all 9 stop conditions passed. API Templates (5): index (paginated, category filter, unit_count via LEFT JOIN), show (full field set), create (auto-slug de-dup, audit_log), update (D19 optimistic lock), delete (HAS_ACTIVE_UNITS block when unit_count > 0). API Units (5): index (FULLTEXT search, status/template/yard filters, unit_number ASC default sort per spec), show (full field set, Trap 7 — file paths stripped), create (transaction: unit + equipment_status_log "none→available" entry + audit_log), update (D19 optimistic lock, metadata-only — status changes deferred to dedicated endpoint), delete (LEASE_NOT_ACTIVE block when status=on_lease). Admin UI (6): equipment/index.php (4 KPI tiles: available/on_lease/maintenance/total, drilldown filter banner, status+template+search filters, health score + compliance warning columns, statusBadgeClass() maps all 6 statuses per design §9), equipment/show.php (server-side hero render, 6-tab Alpine component: Overview/Compliance/Lease History stub/Status Log/Maintenance stub/Documents stub, compliance expiry countdown with day calculation), equipment/create.php (server-loaded templates for dropdown, onTemplateChange() pre-fills defaults, 5 sections: Identity/Location+GPS/Physical/Compliance/Notes), equipment/edit.php (server pre-populated, D19 lock on submit, status field excluded — requires dedicated endpoint), equipment/templates/index.php (category filter, unit_count drilldown link, delete disabled when unit_count > 0), equipment/templates/create.php (all default fields incl. rates + compliance intervals). CSS: added .badge-purple per design §9 (reserved status). dirname depth: app/admin/equipment/templates/ uses dirname(__DIR__, 4). All stop conditions: GET units ✅, create 201 ✅, show correct ✅, update 200 ✅, stale 409 STALE_DATA ✅, soft-delete 200 ✅, list page 200 ✅, create page 200 ✅, all 6 pages zero PHP errors ✅. |
| S008 | 2026-04-02 | Invoices Module (API + Admin UI + Billing Engine) | 13 files built and all 9 stop conditions passed. Billing engine (3): lib/Billing/ProRateCalculator.php (THE LAW formula — pure math, bcmath strings, 10 unit tests all pass: 0d=none, 1-5d=daily, 6-7d=weekly, 8-29d=weekly or capped, 30+=monthly), lib/Billing/TaxCalculator.php (D11 invoice-time lookup from tax_rates, D22 independent gst_exempt/pst_exempt, 7 unit tests pass: BC GST+PST, ON HST, exemptions suppress correctly, unknown province=zero), lib/Billing/InvoiceGenerator.php (orchestrator — creates invoice+line_items+billing_period in single transaction, gap-free invoice number via FOR UPDATE on settings row D15/D20, denormalized counters updated in same transaction Trap 6, handles discount/tax/add-ons per spec §9 calculation order). API (7): index (paginated, FULLTEXT search, status/customer_id/lease_id filters), show (full invoice + line items, pdf_path stripped Trap 7), create (manual creation via InvoiceGenerator, validates lease active/completed), update (draft only — D12 IMMUTABLE_RECORD on non-draft, D19 optimistic lock), delete (soft-delete draft only, reverses denormalized counters), send (draft→sent state transition, freezes financials D12), void (draft/sent→void with required reason, reverses counters). Admin UI (3): invoices/index.php (4 AR aging KPI tiles server-rendered: Current/1-30/31-60/60+ days, 3-tab Alpine component: Outstanding/Paid/All, search+status filter, paginated table), invoices/show.php (server-rendered detail: breadcrumb, status badge, 4 summary tiles, 2-col billing+financial cards, line items table, Send/Void action buttons via Alpine, void modal with reason), invoices/create.php (lease picker dropdown with rate data attributes, auto-fill rates on selection, period date pickers with D14 inclusive day counter, billing type + invoice type selects, PO/notes fields, Alpine submit to API). Bug found and fixed: TaxCalculator initially divided tax_rates by 100 — but DB stores rates as decimal fractions (0.0500 = 5%), not percentages. Fixed to multiply directly. Also changed mileage_unit default from 'miles' to 'km' across entire codebase (D34): schema (8 columns in 7 tables), 4 API endpoints, 5 admin UI pages, live DB altered. All 9 stop conditions passed: GET /invoices ✅ 200 paginated, POST /create ✅ 201 INV-2026-00001 sequential, GET /show ✅ 200 line_items+pdf_path stripped, POST /send ✅ 200 draft→sent, POST /update on sent ✅ 422 IMMUTABLE_RECORD, ProRateCalculator 10/10 ✅, TaxCalculator 7/7 ✅, /invoices page ✅ 200, /invoices/create page ✅ 200. |
| AUDIT-1 | 2026-04-02 | Comprehensive QC Audit (S001–S008) | 69 issues found: 13 CRITICAL, 16 MAJOR, 40 MINOR. No files modified. Full findings in KNOWN ISSUES #16–#43 and NEXT SESSION contract for S008.5. Top 5 critical: (1) lease create doesn't reserve unit, (2) activate doesn't generate Invoice 1, (3) close doesn't generate final invoice, (4) 6 endpoints have audit_log outside transactions, (5) customer notes bypass soft-delete filter. Recommended: insert S008.5 fix session before S009 Payments. |
| S008.5 | 2026-04-02 | Critical Fix Session — Audit Remediation (28 issues #16–#43) | All 28 AUDIT-1 findings resolved. Phase 1 critical (6): lease create now reserves unit; activate wires Invoice 1 via InvoiceGenerator; close wires final invoice with mileage overage extra_lines; all 5 invoice endpoints have audit_log inside transaction; lease update wrapped in transaction; customer notes soft-delete verified (db_exists() auto-handles). Phase 2 major (11): pending badge → badge-info (3 locations); cancel.php + reopen.php created with full state machine + FOR UPDATE + logs; update_status.php created (6 statuses, decommissioned terminal); equipment search fix ($_GET['search']); invoice BEM classes fixed (stat-label/stat-value); CSS aliases added for spec names (--bg-page, --bg-card, --color-accent, etc.); modal-md + modal-full added; customer status transition map with 409 INVALID_TRANSITION; customers/show.php SELECT * → explicit columns; invoices/send.php transaction added. Phase 2 resolved (2 no-ops): invoice search param and customer notes soft-delete both already correct. Phase 3 minor (11): font-mono on invoice tiles + .font-mono utility; .line-through + void badge updated; btn-xl added; units/update.php uniqueness fix; close appends internal_notes; error codes renamed (LEASE_IS_ACTIVE, UNIT_ON_LEASE); invoices/create.php wrapped in form; Invoices tab on lease show; dashboard CSS vars via getComputedStyle; equipment show delete button; InvoiceGenerator updated_by. Key infrastructure fix: includes/db.php db_transaction() nesting guard (inTransaction() check) required to allow InvoiceGenerator calls from within existing transactions. All 10 stop conditions passed. |
| S009 | 2026-04-02 | Payments Module (API + Admin UI) | 10 files built and all 10 stop conditions passed. API (6): index (paginated list, filters: customer_id/invoice_id/status/reference search via LIKE — no FULLTEXT needed, sort by payment_date DESC), show (full payment + allocations[] with invoice detail), create (gap-free PAY-YYYY-NNNNN number via FOR UPDATE on settings row D15; D18 currency match enforced; D20 FOR UPDATE on invoice prevents concurrent over-allocation race; auto-allocates to invoice; drives invoice state machine sent→partially_paid→paid; updates all 4 denormalized counters — invoices.amount_paid/balance_due + leases.total_paid + customers.outstanding_balance — in single transaction; overpayment tracked on payment row; audit_log inside transaction), update (metadata-only: reference_number/bank_name/notes; D19 optimistic lock; D12 financial fields immutable), delete (soft-delete D5/D13; reverses all allocations: per-invoice bcmath counter reversal, invoice status revert paid→partially_paid→sent depending on remaining applied amount, leases.total_paid reversed, customers.outstanding_balance restored; audit_log inside transaction; returns invoice_statuses_reverted array), allocate (manual allocation of unapplied payment to different invoice; D18/D20/ALLOCATION_EXCEEDS_BALANCE guards; updates counters in same transaction). Admin UI (3 pages): payments/index.php (4 KPI tiles server-rendered: collected this month/total AR outstanding/overdue AR/today count; Alpine.js table with status filter + reference search + sortable columns; status badges per §9 design: cleared=success/failed=danger/refunded=warning/pending=info; formatMethod() label map), payments/show.php (full detail: 4 summary tiles, 2-col detail card + financial notes card, allocation table with invoice links, edit-metadata inline form + delete modal with reason — all via Alpine.js), payments/create.php (outstanding invoice picker with balance pre-fill; amount field with quick-fill-balance button; currency auto-locks to invoice currency D18; method-conditional fields: check#/bank/card-last-4; submit→redirect to show page). Invoice extension: invoices/show.php now has server-rendered Payments History section — queries payment_allocations JOIN payments for this invoice, shows applied_amount (not total payment amount), total applied footer row, Record Payment button when invoice is payable. CSS additions: dl.detail-grid added to app.css (key-value pair layout for detail cards). Bug found and fixed during stop conditions: api_url() function does not exist — all 3 UI pages used api_url() incorrectly; fixed to base_url('api/v1/payments/...'); CSS class audit: data-table→table, stats-grid→stat-grid, page-title→page-header-title, page-subtitle→inline style (D32 verified classes only). All 10 stop conditions passed: POST create → 201 PAY-2026-00001 sent→partially_paid ✅; currency mismatch → 422 CURRENCY_MISMATCH ✅; concurrent FOR UPDATE test — one succeeds/one ALLOCATION_EXCEEDS_BALANCE, invoice balance exact ✅; GET index → 200 paginated ✅; GET show?id=1 → 200 with allocations ✅; POST delete → soft-deleted invoice paid→partially_paid balance restored ✅; POST update → 200 + stale → 409 STALE_DATA ✅; /payments page → 200 no PHP errors ✅; /payments/create page → 200 no PHP errors ✅; /invoices/show?id=1 → 200 Payment History section with PAY-2026 entries ✅. |
| S010 | 2026-04-02 | Monthly Billing Cron + Late Fee Engine | 5 files built and all 6 stop conditions passed. New directory: cron/ created. lib/Billing/LateFeeEngine.php (pure math, no DB; calculate(balance, rule): percentage 2% of $1356=$27.12 ✅, flat $50=$50 ✅, max_fee_amount cap $100 ✅, zero balance=$0 ✅, fee_type returned ✅ — 7/7 unit tests pass). InvoiceGenerator.php extended: added generateLateFeeInvoice(int $invoiceId) method — loads original invoice with FOR UPDATE (D20), finds late_fee_rule (customer-specific wins over global NULL), checks grace period, delegates math to LateFeeEngine (D3), applies same tax exemption snapshots (D22), generates late_fee invoice + single line item in transaction, marks original late_fee_applied=1, updates all denormalized counters in same transaction (Trap 6), returns ['skipped'=>bool] for non-error skips. cron/invoice_generate_monthly.php: advisory lock ff_cron_invoice_generate_monthly (D21); queries active+monthly leases WHERE next_billing_date=PHP_date(); outer db_transaction wraps createFromLease(billing_type=full_month, generation_source=cron) + DATE_ADD(next_billing_date, INTERVAL 1 MONTH); per-invoice audit_log + cron_completed entry; continue-on-error per lease. cron/invoice_overdue.php: advisory lock ff_cron_invoice_overdue; flips sent/partially_paid invoices past due_date to 'overdue'; audit_log inside transaction per invoice (FIX #19 pattern). cron/late_fee_apply.php: advisory lock ff_cron_late_fee_apply; finds overdue+late_fee_applied=0 invoices; calls InvoiceGenerator::generateLateFeeInvoice() per invoice; skips logged at debug level; audit_log on apply. Bug found and fixed: cron template in CLAUDE_CODE_REFERENCE.md uses 'description' field and 'cron_completed'/'cron_failed' action values — actual audit_log schema uses 'notes' and ENUM 'cron'; all 3 cron files corrected. Also: PHP date() returned 2026-04-01 while MySQL CURDATE() returned 2026-04-02 (timezone offset) — test data inserted with PHP date. All 6 stop conditions passed: monthly cron manual run → INV-2026-00005 generated for lease with next_billing_date=today ✅; advisory lock held → exit 0 + zero output ✅; overdue cron → INV-2026-00010 sent→overdue (due 2026-03-15) ✅; late fee cron → INV-2026-00006/00007 late_fee invoices created, originals late_fee_applied=1 ✅; LateFeeEngine 7/7 unit tests ✅; second monthly cron run → INV-2026-00008 sequential, counter=9 ✅. |
| S012 | 2026-04-02 | Damage Claims Module (API + Admin UI) | 11 files built and all 10 stop conditions passed. API (7): index (paginated list, filters: customer_id/equipment_unit_id/status/severity/date range/q search; JOINs equipment_templates for brand/model — not on equipment_units), show (full claim + photos[] with signed StorageClient URLs, brand/model from template JOIN; never returns file_path Trap 7), create (gap-free DMG-YYYY-NNNNN via FOR UPDATE on settings row; unit/customer pre-flight checks; D16 bcmath on all monetary fields; status always 'reported'; reported_by from session), update (D19 optimistic lock; state machine: reported→assessed/written_off, assessed→repair_ordered/written_off, repair_ordered→invoiced/resolved/written_off, invoiced→resolved/written_off, resolved/written_off=terminal; metadata + status in one endpoint; audit_log inside transaction with old_values/new_values), delete (soft-delete; only reported/assessed may be deleted — repair_ordered+ blocked with INVALID_TRANSITION referencing written_off instead), upload_photo (multipart FormData; finfo_file() MIME detection Trap 5; MIME map: image/jpeg→jpg, image/png→png, image/heic→heic, image/heif→heic; 10 photo max per claim; 10 MB max; safe filename {claim_id}_photo_{timestamp}.{ext}; storage path damage/{claim_id}/{safe_name}; StorageClient::upload() D9; returns signed URL not file_path), delete_photo (hard DELETE on damage_claim_photos; StorageClient::delete() outside transaction; failure logged but non-fatal). Admin UI (3 pages): index.php (4 KPI tiles server-rendered: open/invoiced/year-total/avg-repair-cost; Alpine.js filterable table with status+severity+search filters; sortable columns; badge maps for severity minor=info/moderate=warning/major=danger/total_loss=danger; row-click to show page), create.php (unit dropdown JOINs equipment_templates for brand/model display; customer dropdown active-only; pre-populate from query string params; all monetary fields via number inputs; server-side + client-side validation; submit→redirect to show page), show.php (server-rendered; 4 summary tiles; detail card with view+edit modes; D19 lock in edit save; photo gallery grid with upload form + per-photo delete; status transition panel; delete confirm modal; StorageClient::url() for all photo URLs; brand from et.brand not eu.make). Navigation: Damage Claims added to config/navigation.php between Maintenance and Compliance (module: maintenance); exclamation-triangle.svg Heroicon created in public/assets/icons/; sidebar badge 'open_damage_claims' counts reported+assessed+repair_ordered claims added to sidebar_badge_count(). Bug found and fixed mid-session: equipment_units has no make/model columns — those are on equipment_templates (brand/model); all three API endpoints and admin UI fixed to JOIN equipment_templates. All 10 stop conditions passed: POST create → 201 DMG-2026-00001 ✅; GET index → 200 total=1 ✅; GET show → 200 photos[] ✅; status transition reported→assessed → 200 ✅; INVALID_TRANSITION assessed→resolved (must go through repair_ordered) → 409 ✅; STALE_DATA wrong updated_at → 409 ✅; sequential DMG-2026-00002 ✅; soft-delete reported claim → 200, verify 404 ✅; cannot delete repair_ordered claim → 409 INVALID_TRANSITION ✅; all 3 admin pages 200 no PHP errors ✅. All 12 PHP files php -l clean ✅. Audit log: 8 entries across creates/status_changes/deletes verified. |
| S011 | 2026-04-02 | Credit Notes Module (API + Admin UI) | 10 files built and all 15 stop conditions passed. lib/Billing/CreditEngine.php (pure math, no DB; apply(invoiceTotal, credits[]) → [credits_applied, balance_due, applied_per_credit]; 4/4 unit tests pass: full coverage, partial coverage, multi-credit with early exit, zero balance). API (6): index (paginated list, filters: customer_id/status/currency/source/date range, sort allowlist), show (full CN detail + applications[] with invoice links), create (gap-free CN-CR-YYYY-NNNNN via FOR UPDATE on settings row; customer/lease/invoice/payment cross-ownership validation; D18 currency; D16 bcmath; audit inside transaction), update (metadata-only: reason/internal_notes/expires_at; D19 optimistic lock; D12 financial fields immutable; void CN → IMMUTABLE_RECORD), void (active/partially_used only; FOR UPDATE D20 prevents concurrent void+apply race; zeroes amount_remaining; appends void reason to internal_notes; INVALID_TRANSITION on wrong status), apply (D18 CURRENCY_MISMATCH; same-customer guard; D20 FOR UPDATE on BOTH credit_note AND invoice — consistent lock order prevents deadlock; CREDIT_EXCEEDS_BALANCE on both invoice.balance_due and cn.amount_remaining; 5 counters in one transaction: cn.amount_remaining, cn.status active→partially_used→fully_used, invoices.credits_applied, invoices.balance_due, customers.outstanding_balance; invoice state machine sent/overdue→partially_paid→paid; credit_note_applications row; audit inside transaction). Admin UI (3 pages): index.php (4 KPI server-rendered tiles: outstanding balance/issued this month/fully applied this month/voided this month; Alpine.js table with status/currency/source filters + sortable columns; status badges active=success/partially_used=warning/fully_used=neutral/void=neutral+line-through), show.php (4 summary tiles; detail card; apply-to-invoice form shown when active/partially_used; edit-metadata form when status is non-applicable and non-void; void modal with required reason; applications history table with invoice links and total footer), create.php (customer dropdown pre-filtered to active customers; source type selector; amount/currency fields; reason textarea; optional expiry date; collapsible optional links section for lease_id/source_invoice_id/source_payment_id; internal notes; submit→redirect to show page). All stop conditions passed: POST create → 201 CN-CR-2026-00001 ✅; GET index → 200 total=1 ✅; GET show?id=1 → 200 with applications[] ✅; CURRENCY_MISMATCH USD→CAD → 422 ✅; apply $100 to invoice → balance 1200→1100, credits_applied 0→100, CN remaining 150→50, status partially_used ✅; CREDIT_EXCEEDS_BALANCE $60 on $50 remaining → 422 ✅; void partially_used → status=void remaining=0.00 ✅; apply voided CN → INVALID_TRANSITION ✅; STALE_DATA wrong updated_at → 409 ✅; sequential CN-CR-2026-00003 ✅; /credit_notes page 200 no PHP errors ✅; /credit_notes/create page 200 no PHP errors ✅; /credit_notes/show?id=2 page 200 no PHP errors ✅; CreditEngine 4/4 unit tests ✅; all 10 files PHP -l clean ✅. |
| S009-EXT | 2026-04-02 | Topbar Enhancement (UX — all pages) | includes/topbar.php fully rewritten; public/assets/css/app.css extended with 17 new classes. Three features added to the right-side topbar cluster: (1) Quick-create "+" dropdown — pill-shaped "New" button; opens a permission-gated dropdown with shortcuts for New Customer / New Lease / New Invoice / Record Payment; each entry only rendered if current user's role has create permission on that module; uses icons confirmed on disk (users, clipboard-document-list, document-text, credit-card). (2) Theme toggle button — `.btn-icon .topbar-theme-btn`; shows moon in light mode / sun in dark mode; Alpine.js `x-data` initialises `dark` from `document.documentElement.getAttribute('data-theme')` and tracks state locally so icon flips instantly; calls existing `FF_Theme.toggle()` from app.js — no app.js changes needed. (3) User avatar dropdown — circular initials button (first char of first + last name word, e.g. "AV"); opens panel with large avatar + full name + role badge (role_slug → human label map) + email; Settings link (rendered only if `can('settings', 'view')`); My Profile link (always shown); Sign Out (links to `base_url('auth/logout')` via GET; logout.php accepts GET, no CSRF required per D29); Sign Out has danger-tint hover. Right-cluster order: [New ▾] [Search ⌘K] [🌙] [🔔] [avatar ▾]. New CSS classes added to app.css: `.topbar-theme-btn`, `.topbar-create`, `.topbar-create-btn`, `.topbar-create-dropdown`, `.topbar-dropdown-label`, `.topbar-create-item`, `.topbar-user`, `.user-avatar`, `.user-avatar--lg`, `.user-dropdown`, `.user-dropdown-header`, `.user-dropdown-identity`, `.user-dropdown-name`, `.user-dropdown-meta`, `.user-dropdown-email`, `.user-dropdown-divider`, `.user-dropdown-item`, `.user-dropdown-item--danger`. Implementation notes: heroicon() accepts only 2 params — no third attr arg; caches by $name only (nav-icon class used consistently throughout). Icons for quick-create verified against public/assets/icons/ directory listing before use. |
| S013 | 2026-04-02 | Mileage Logs Module (API + Admin UI + GPS + Cron) | 12 new files built, 5 existing files modified, 1 bug fixed during stop conditions. All 17 stop conditions passed. **format_mileage()** added to includes/functions.php (Known Issue #2 resolved): 4/4 assertions pass. **lib/GPS/SamsaraClient.php** (FleetForge\GPS): getMileageForLease()→?float (km driven), getOdometerReading()→?int (current odometer km); 10s HTTP timeout; logs all failures to logs/gps.log; dev-safe (blank keys → null, no HTTP). **API (5 endpoints in api/v1/mileage_logs/)**: index (paginated; filters: equipment_unit_id/lease_id/log_type/date_from/date_to; JOINs equipment_units+templates+users for labels), show (full detail + linked lease context), create (log_type coerced to manual/service only; date not future; updates equipment_units.mileage in same transaction; audit inside transaction), update (blocks gps_sync/lease_start/lease_end → 422 IMMUTABLE_RECORD; optimistic lock on created_at — no updated_at on this table), delete (hard delete; blocks lease_start/lease_end → 422 IMMUTABLE_RECORD). **api/v1/gps/mileage.php**: called from close-lease form; uses l.mileage_unit not eu.mileage_unit (equipment_units has no mileage_unit — bug caught+fixed mid-session); returns distance_km + odometer_estimate or source=no_device/unavailable; never blocks close flow. **cron/gps_mileage_sync.php**: advisory lock ff_cron_gps_mileage_sync (D21); queries active leases with gps_device_id; calls getOdometerReading() per unit; skips already-synced units; updates equipment_units.mileage + inserts gps_sync rows; dev mode: 0 processed, exit 0. **Admin UI (3 pages)**: index.php (4 KPI tiles; Alpine table with type/date/unit-search filters; type badges; client-side unit search), create.php (unit+lease dropdowns; log_type restricted to manual/service; submit→redirect), show.php (4 summary tiles; edit mode for manual/service; D19 lock via created_at; delete modal blocks lease_start/lease_end). **Navigation**: Mileage Logs added after Damage Claims (module=maintenance; chart-bar-square.svg created). **Integration**: Mileage Log tab added to equipment/show.php and leases/show.php with lazy-load pattern matching existing tabs. All 17 stop conditions: POST create → 201 ✅; GET index total=1 ✅; GET show ✅; POST update → 200 ✅; STALE_DATA wrong created_at → 409 ✅; POST create id=2 ✅; DELETE service → 200 ✅; show deleted → 404 ✅; gps/mileage no_device → 200 ✅; gps/mileage missing lease_id → 422 ✅; /mileage_logs → 200 ✅; /mileage_logs/create → 200 ✅; /mileage_logs/show?id=1 → 200 ✅; GPS cron dev mode exit 0 ✅; equipment/show → 200 ✅; leases/show → 200 ✅; format_mileage 4/4 ✅. All 12 new PHP files php -l clean ✅. |
| S014 | 2026-04-02 | Vendors Module (API + Admin UI) | 8 new files built. All 19 stop conditions passed. **API (5 endpoints in api/v1/vendors/)**: index (paginated; filters: vendor_type ENUM, is_preferred bool, q FULLTEXT on name+contact_name; sort allowlist: name/total_spent/rating/created_at; default name ASC; work_order_count via subquery; specializations JSON decoded per row), show (full vendor detail + work_order_count + recent_work_orders[] last 5; LEFT JOIN users for created_by_name), create (vendor_type ENUM validation; name uniqueness among non-deleted; rating 1–5 or null; specializations as JSON array of strings; hourly_rate via clean_decimal() D16; total_spent always starts 0.00 — owned by work order module; audit inside transaction), update (D19 optimistic lock on updated_at; name uniqueness excludes self; specializations replaces entire array; audit old_values/new_values; returns fresh updated_at), delete (soft delete; blocks if active WOs in open/in_progress/waiting_parts; ON DELETE SET NULL only fires on hard DELETE — soft delete preserves mwo.vendor_id history). **Admin UI (3 pages)**: index.php (4 KPI tiles: total/preferred/active WOs/top vendor by spend; Alpine filterable table with vendor_type+is_preferred+q search filters; typeBadge() helper for 6 vendor types), create.php (name+type required; contact/location/rates/rating sections; specializations multi-select with 10 options; is_preferred checkbox; FF_Api.post() → redirect to show), show.php (4 KPI tiles: total_spent/active WOs/completed/total; view+edit mode; D19 lock via updated_at in save(); specializations multi-select with server-pre-selected options; Work Orders tab server-rendered with last 20 WOs; delete modal blocks on active WO error message from API). **Navigation**: Vendors added to config/navigation.php after Mileage Logs (module: maintenance); building-storefront.svg Heroicon created. **FK confirmed**: maintenance_work_orders.vendor_id nullable FK → vendors(id) ON DELETE SET NULL — soft delete preserves history. **Permission module**: maintenance. All 19 stop conditions: GET list empty total=0 ✅; POST create id=1 ✅; GET list total=1 ✅; GET show wo_count=0 ✅; POST update 200 ✅; STALE_DATA wrong updated_at → 409 ✅; create id=2 (second vendor) ✅; duplicate name → 409 ALREADY_EXISTS ✅; rating=6 → 422 VALIDATION_ERROR ✅; soft delete id=2 → 200 deleted=true ✅; show deleted → 404 NOT_FOUND ✅; vendor_type filter repair → total=1 ✅; is_preferred filter → total=1 ✅; /vendors 200 ✅; /vendors/create 200 ✅; /vendors/show?id=1 200 ✅; UNAUTHORIZED no session ✅; missing vendor_type → 422 ✅; specializations round-trip JSON ✅; sort injection guard → safe fallback ✅; php -l all 8 files clean ✅. |
| S013-EXT | 2026-04-02 | Mileage Logs Post-Session Fixes + Stress Test | 3 bugs fixed, 1 API filter added, 49-test stress test run. **Bug 1 — empty black page on /mileage_logs:** root cause was `<?= icon('plus') ?>` call — no such function exists; changed to plain string `+ Record Mileage` matching codebase pattern. **Bug 2 — "Delete failed." modal on show.php:** root cause was all three raw `fetch()` calls in show.php (edit form + confirmDelete()) and create.php sent `X-Requested-With` but omitted `X-CSRF-Token` header — FF_Api does this automatically; raw fetch does not. Fixed by adding `'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? ''` to all three fetch headers. **Bug 3 — unit filter was text input:** replaced `unit_search` text input + client-side filter loop with server-rendered `<select>` dropdown (same `$filterUnits` query as create.php); changed Alpine filter state from `unit_search` to `equipment_unit_id`; filter now sent as API query param (no more client-side loop). **New feature — customer_id filter on mileage_logs API:** added to api/v1/mileage_logs/index.php via subquery `ml.lease_id IN (SELECT id FROM leases WHERE customer_id = ? AND deleted_at IS NULL)` — handles the fact that mileage_logs has no direct customer_id column. **Mileage Logs tab on customers/show.php:** added tab button + tab panel + `mileageLogs[]`/`mileageLogsLoaded`/`mileageLogsLoading` state + `loadMileageLogs()` method + `mlTypeBadge()`/`mlTypeLabel()` helpers + `$watch` handler — matches lazy-load pattern on equipment/show and leases/show. **Stress test (49 tests, all pass):** CRUD ✅, validation (future date/negative odo/missing fields) ✅, immutability guards (lease_start/lease_end block both; gps_sync blocks edit only) ✅, STALE_DATA optimistic lock ✅, CSRF enforcement ✅, auth guard (UNAUTHORIZED) ✅, method enforcement ✅, all filters (unit/log_type/date/customer_id) ✅, SQL injection via sort/dir/log_type/unit_id (all neutralized) ✅, XSS in notes (clean_string strips on input; e() escapes on output) ✅, lower-odometer-does-not-overwrite-higher ✅, GPS edge cases ✅, pagination caps (min 10, max 100) ✅, all 3 admin pages HTTP 200 ✅. |
| S014-EXT | 2026-04-03 | Vendors + Damage Claims — Integration, Bug Fixes, URL Fix | 7 files changed. **Vendor–Equipment–Lease integration:** `app/admin/vendors/show.php` fully rewritten to plain JS onclick + DOM toggle + raw `fetch()` pattern (matching mileage_logs/show.php). Added two PHP queries: (1) `$unitHistory` — units serviced via maintenance_work_orders with service_count + last_service_date, LEFT JOIN equipment_templates for brand/model; (2) `$leaseExposure` — active/pending leases on units the vendor has ever serviced, JOINs customers + leases + equipment_units. Added two new cards: "Equipment Worked On" (table with unit#/year/brand+model/service count/last service/link) and "Active Lease Exposure" (table with lease#/customer/unit/status/end date — orange warning note when rows exist). **Vendor selector on damage claim create:** Added `$vendors = db_select(...)` to `app/admin/damage_claims/create.php`; added optional "Vendor Sent To" dropdown to form; added `vendor_id: null` to Alpine form state + payload. **DB migrations run on live DB:** `ALTER TABLE damage_claims ADD COLUMN vendor_id INT UNSIGNED NULL AFTER invoice_id, ADD INDEX idx_dc_vendor (vendor_id), ADD CONSTRAINT fk_damage_claim_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL; ALTER TABLE damage_claims ADD COLUMN customer_name VARCHAR(255) NULL AFTER customer_id;` — also added to FLEETFORGE_DATABASE_MASTER.sql. **customer_name free-text field (mutual exclusivity with customer_id):** Both `api/v1/damage_claims/create.php` and `api/v1/damage_claims/update.php` updated to accept `customer_name` free-text (mutually exclusive: setting customer_id nulls customer_name, setting customer_name nulls customer_id). `app/admin/damage_claims/create.php` and `show.php` both updated with dual select+text pattern (`@change` clears text, `@input` clears dropdown). **Damage claim show page fixes:** (1) SQL query aliased `c.company_name AS customer_company_name` to avoid collision with new `dc.customer_name` column; added `dc.vendor_id`, `v.name AS vendor_name` + LEFT JOIN vendors. (2) View mode shows customer with FK→company name fallback to free-text; "Vendor Sent To" row in detail grid. (3) Edit form: Customer field dual select+text; "Liable Amount ($)" label (renamed from "Customer Liable" to prevent confusion with customer selector); Vendor select. (4) `saveEdit()` payload includes customer_id/customer_name/vendor_id. **FF_Api.post always-resolves fix:** ALL five `.then()` handlers in `damage_claims/show.php` now check `d.error` before treating response as success (FF_Api.post never rejects — `.catch()` only fires on network errors). **Critical URL bug fixed across 4 files:** All bare relative `FF_Api.post('api/v1/...')` strings in `damage_claims/show.php` (5 calls), `damage_claims/create.php` (1 call), `vendors/create.php` (1 call), and `vendors/index.php` (1 call: `FF_Api.get('api/v1/vendors/index.php?...')`) replaced with `base_url()` absolute paths. Root cause: browser resolves relative strings from page URL — `/fleetforge/damage_claims/api/v1/...` → 404. Fix pattern: `FF_Api.post('<?= base_url('api/v1/...') ?>', payload)`. | S012-EXT | 2026-04-02 | Damage Claims Integration + UI Fixes | 6 files changed. **Bug fixes:** (1) create.php form was squished/unstyled — root cause: `.form-input`, `textarea.form-input`, `.form-input-sm`, `.form-select-sm`, and all `.alert`/`.alert-danger`/`.alert-success`/`.alert-warning`/`.alert-info` classes were missing from app.css. Added all as confirmed aliases; rewrote create.php form HTML to use `.form-control` directly + `.form-row-2` for Equipment Unit+Customer and Severity+Damage Location pairs + `.form-row-3` for three financial fields + styled submit row with border-top. (2) index.php table showed 0 rows despite KPI showing 1 claim — root cause: Alpine read `d.data` (an object) instead of `d.data?.items` (array) and `d.meta` instead of `d.data?.pagination`; fixed to `d.data?.items ?? []`, `d.data?.pagination?.total`, `d.data?.pagination?.total_pages`. **Cross-module integration:** Damage Claims tab added to three profile pages — equipment/show.php (between Lease History and Status Log; filtered by equipment_unit_id; columns: Claim #, Customer, Severity, Status, Est. Cost, Reported, View), leases/show.php (after Invoices tab; filtered by lease_id; columns: Claim #, Severity, Status, Est. Cost, Reported, View), customers/show.php (after Invoices tab; filtered by customer_id; columns: Claim #, Unit, Severity, Status, Est. Cost, Reported, View). All three: lazy-load on first tab activation; `+ New Claim` button pre-populates query string with the relevant ID; `can('maintenance','create')` permission-gated. Badge helpers (dcSeverityBadge/Label, dcStatusBadge/Label) added inline to each page's JS component. **Topbar home icon:** `home.svg` already existed in public/assets/icons/; added `<a class="btn-icon topbar-home-btn" href="dashboard">` between hamburger toggle and page title in topbar.php; added `.topbar-home-btn` rule to app.css (color: --text-secondary, text-decoration: none, hover: --text-primary + --bg-surface-2). |
| SEARCH-FIX | 2026-04-03 | Global Search Bug Fix (All Index Tables) | Two bugs fixed across 13 files. **Bug 1 — partial search returned no results (5 API files)**: All index APIs (customers, vendors, invoices, leases, equipment/units) used MySQL FULLTEXT `MATCH...AGAINST` which requires complete words and a 3-char minimum. Replaced with LIKE `%term%` substring matching in all 5. **Bug 2 — table didn't refresh when search bar was cleared (8 UI files)**: `x-model.debounce.400ms` delays the data sync by 400ms but `@input="resetPage()"` fires immediately — so `load()` always read the *previous* value of `filters.search`. Fix: moved the debounce to the event handler (`x-model="filters.search"` + `@input.debounce.400ms="resetPage()"`) in customers, invoices, leases, equipment, equipment/templates, vendors, maintenance_work_orders, damage_claims index pages. |
| S017 | 2026-04-03 | Users + Admin Module (API + Admin UI + Invite Flow + Audit Log + Settings) | 13 new files built, 1 existing file rewritten. All 15 stop conditions passed. **accept_invite.php rewritten**: removed reference to non-existent `user_invitations` table — token lookup now uses `users.invite_token` (SHA-256 hash), `users.invite_token_expiry`, `users.status` ENUM; fixed `u.role_slug` → JOIN `user_roles`; fixed `u.is_active` → `status='active'`; added missing audit_log entry on account activation. **API (7 endpoints in api/v1/users/)**: index (paginated; LIKE search on name/email; filters: status, role_id; sort allowlist; JOIN user_roles; deleted_at IS NULL), show (explicit column list — never returns password_hash/invite_token/remember_token), create (email unique check; role_id validation; 64-char plain token + SHA-256 hash stored; invite_token_expiry = NOW()+7d; status='invited'; Mailer invite email; audit action='create'), update (D19 optimistic lock; email unique excluding self; audit old_values/new_values), delete (soft-delete; blocks self-delete; blocks active users), update_status (active/inactive/suspended only; blocks setting 'locked'/'invited'; self-lockout guard; audit action='status_change'), invite (resend: only status='invited'; regenerate token+expiry; re-send email; audit). **Admin UI (3 pages in app/admin/users/)**: index.php (4 KPI tiles: total/active/invited/suspended; Alpine filterable table status+role+q; "Invite New User" shown only if can('users','create'); status badges active=success/invited=info/suspended=danger/inactive=neutral/locked=danger), create.php (name/email/role/phone/timezone form; FF_Api.post → show page on success with flash), show.php (view+edit mode with D19 hidden updated_at; actions panel: Resend Invite if invited, Activate/Deactivate/Suspend per state + self-guard). **app/admin/audit/index.php**: read-only; server-side PHP pagination with ?page= links; filters: module (distinct from DB), user_id (all users dropdown), action (ENUM values), date_from/date_to; columns: created_at, user_name, action badge, module, entity_type+id, entity_label, ip_address; permission: audit view. **app/admin/settings/index.php**: CSRF-protected POST to self; settings grouped by group_name (Company, Invoices, Alerts, Notifications shown; GPS/AI in collapsed section); value_type drives input type (string/text→input, text→textarea, integer/decimal→number, boolean→checkbox); editable only if can('settings','edit') (super_admin only); saves via direct db_execute with backtick-quoted `key`; audit action='update'. **Bug found and fixed**: invite URL in create.php + invite.php used `auth/accept-invite` (hyphen) but router maps to file `accept_invite.php` (underscore) — fixed to `auth/accept_invite`. **15 stop conditions**: POST create → 201 status=invited ✅; GET accept_invite token → form shown ✅; POST set password → activated, token cleared ✅; login with new creds → 200 ✅; reuse link → "expired or used" ✅; php -l all 13 files clean ✅; GET /api/v1/users/index.php → 200 paginated ✅; GET show?id=1 → 200 no password_hash ✅; POST update_status → 200 ✅; /users page 200 ✅; /users/create 200 ✅; /users/show?id=1 200 ✅; /audit 200 ✅; /settings 200 ✅; no session → 401 ✅. |
| S016 | 2026-04-03 | Inspections Module (API + Admin UI + Photo Upload) | 13 new files built, 3 existing files patched. All 16 stop conditions passed. **Schema migrations applied**: 5 columns added to `inspections` (inspection_number VARCHAR(50) UNIQUE, reefer_hours, fuel_level ENUM, cvi_expiry DATE, is_clean TINYINT), 1 column added to `inspection_sections` (section_data JSON). **Physical inspection form incorporated**: 9 default sections auto-created per inspection — sections 1-7 exterior/interior (condition + notes + photos), section 8 Tires (20-position JSON: LO.1–LI.5/RO.1–RI.5 × brakes/tread/brand/org/wheels), section 9 Trailer Condition (7-item JSON: mud_flaps/lights/canlocks/landing_gear/inflation/tray_skirts/rub_rail × code+notes). Damage legend: C=Cut, D=Dent, S=Scratch, B=Bruise, P=Patch, H=Hole. **API (10 endpoints)**: index (paginated; filters: status/inspection_type/equipment_unit_id/lease_id/date_from/date_to/q; JOINs unit+template+lease for labels; no soft delete on inspections), show (full detail + sections[with section_data decoded] + photos[]; Trap 7: no file_path), create (atomic INSP-YYYY-NNNNN via generate_id inside transaction; 9 default sections auto-created in same transaction; tire JSON skeleton 20 positions; trailer JSON skeleton 7 items), update (D19 optimistic lock; blocks complete/signed), delete (hard delete; blocks complete/signed → INVALID_TRANSITION), update_status (draft→complete→signed TERMINAL; complete→draft re-open requires manager; on complete recalculates overall_condition from worst section; on signed records signed_at), sections/update (no optimistic lock — no updated_at on sections; blocks if signed; section_data replaces entirely), photos/upload (raw multipart; StorageClient; finfo_file MIME — Trap 5; max 20 photos/inspection, 10MB/photo; JPEG/PNG/HEIC; blocks if signed; Trap 7 no file_path), photos/delete (hard delete; StorageClient::delete best-effort; blocks if signed). **Admin UI (3 pages)**: index.php (4 KPI tiles: total/draft/complete/signed-this-month; tiles clickable to set filter; Alpine filterable table with q/status/inspection_type filters; badge helpers typeBadge/statusBadge/conditionBadge), create.php (pre-populate from ?unit_id/lease_id/type; equipment+lease+user dropdowns; all header fields; FF_Api.post→redirect), show.php (server-renders all 9 sections: exterior/interior cards with condition dropdown+notes+photo zone, tire table matching physical form with per-position inputs, trailer condition checklist with legend codes; status buttons server-rendered per state machine; "Create Damage Claim" button on complete/signed; canEdit flag; raw fetch() for photo upload multipart; photo delete via FF_Api.post). **Cross-module patches**: equipment/show.php Inspections tab (lazy-loaded via ?equipment_unit_id=N), leases/show.php Inspections tab + "+ Pre-Lease Inspection" / "+ Post-Lease Inspection" buttons (pre-fill ?lease_id+unit_id+type). **Navigation**: Inspections added with clipboard-document-check.svg icon, module='inspections'. **Bugs found and fixed**: (1) `db_sanitize_column()` didn't backtick-quote column names — `condition` is a MySQL reserved word → SQLSTATE[42000]; fixed by returning `` `{$col}` ``; (2) `et.unit_type` doesn't exist — column is `et.category`; fixed in both show.php files with `AS unit_type` alias. **16 stop conditions**: SC1 empty list ✅; SC2 create INSP-2026-00001 ✅; SC3 9 sections + 20 tire positions ✅; SC4a condition update ✅; SC4b tire JSON round-trip ✅; SC5 photo upload URL ✅; SC6 draft→complete ✅; SC7 complete→signed ✅; SC8 signed→draft INVALID_TRANSITION ✅; SC9 delete signed INVALID_TRANSITION ✅; SC10 /inspections 200 ✅; SC11 /inspections/create 200 ✅; SC12 /inspections/show tire table ✅; SC13 equipment/show 200 ✅; SC14 leases/show 200 ✅; SC15 UNAUTHORIZED 401 ✅; SC16 Create Damage Claim button ✅. |
| S017 | 2026-04-03 | Users + Admin Module (API + Admin UI + Audit + Settings) | 13 new files built, 1 existing file fixed. All 15 stop conditions passed. **app/auth/accept_invite.php fixed**: `find_valid_invite()` rewrote from non-existent `user_invitations` table to direct `users` table query (invite_token SHA-256 hash, invite_token_expiry, status='invited' guard); UPDATE block changed from `is_active=1 / DELETE FROM user_invitations` to `status='active', invite_token=NULL, invite_token_expiry=NULL` with full audit_log entry; role label map added `read_only` and `maintenance_tech`; form action URL fixed to underscore (`accept_invite`) matching filesystem. **API (7 endpoints in api/v1/users/)**: index (paginated; filters: status ENUM/role_id/q LIKE; sort allowlist name/email/last_login_at/created_at/status; no FULLTEXT — users table has no FULLTEXT index; JOIN user_roles; safe column list — no password_hash), show (explicit safe column list — NEVER returns password_hash/invite_token/invite_token_expiry/remember_token/password_reset_token; JOINs user_roles), create (name+email+role_id required; email uniqueness among non-deleted; role_id validated; status='invited'; token = bin2hex(32)→SHA-256 hash stored; invite_token_expiry=+7days; Mailer::send() — dev logs to logs/mail.log; audit action='create'), update (D19 optimistic lock; updatable: name/email/phone/timezone/role_id; email uniqueness excludes self; role_id validated; audit old_values/new_values; returns fresh updated_at), delete (soft delete; blocks self-delete; blocks active users — must deactivate first; audit action='delete'), update_status (status: active/inactive/suspended only — locked/invited not settable; self-lockout guard; no-op if same; audit action='status_change'), invite (resend invite; only status='invited'; fresh token generated; Mailer::send(); audit action='update' notes='Invite resent'). **Admin UI (3 pages in app/admin/users/)**: index.php (4 KPI tiles: total/active/invited/suspended; Alpine filterable table with q/role_id/status filters; status badges: active=badge-success, invited=badge-info, inactive=badge-neutral, suspended/locked=badge-danger; row click→show; 'Invite New User' button gated by can('users','create')), create.php (name+email+role required; phone+timezone optional; roles dropdown server-rendered; Alpine FF_Api.post→redirect to show?id=N&flash=...; client-side guards), show.php (server-renders user header with status+role badges; flash message from ?flash= query param; detail card view+edit mode with D19 lock; edit form: name/email/phone/timezone/role dropdown; actions panel: Resend Invite (status='invited'), Activate/Deactivate/Suspend (not self); all actions via FF_Api.post/raw fetch; status transitions reload page). **app/admin/audit/index.php**: read-only; server-side filters (module/user_id/action/date_from/date_to); server-side pagination (?page= links, 50/page); inline PHP WHERE clause build; action badges (create=success, update=info, delete=danger, status_change=warning, login/logout/cron=neutral); entity + label columns; user links; no separate API endpoint. **app/admin/settings/index.php**: CSRF-protected POST-to-self; groups: company/invoices/alerts/notifications displayed; gps/ai in collapsed `<details>` section; per-type rendering (boolean=checkbox, integer/decimal=number, text=textarea, string=text input); save by group; single audit_log entry per group save; canEdit gates all inputs+buttons. **Bug fixed**: invite URL in create.php/invite.php used `auth/accept-invite` (hyphen) but file is `accept_invite.php` (underscore) → router returned 404; fixed to `auth/accept_invite`. **15 stop conditions**: SC1 POST create → 201 status=invited ✅; SC2 GET accept_invite?token=... → form shown ✅; POST activate → status=active token cleared ✅; SC3 login with new creds → 200 ✅; SC4 reuse invite link → expired message ✅; SC5 php -l all files ✅; SC6 GET /api/v1/users/index.php → 200 paginated ✅; SC7 GET /api/v1/users/show.php?id=1 → no password_hash ✅; SC8 POST update_status → 200 ✅; SC9 /users 200 ✅; SC10 /users/create 200 ✅; SC11 /users/show?id=1 200 ✅; SC12 /audit 200 with entries ✅; SC13 /settings 200 with groups ✅; SC14 no session → 401 ✅. |
| S017-C | 2026-04-03 | Users Module — Super Admin Password + Delete Extensions | 3 files modified. **api/v1/users/set_password.php** (NEW): super_admin sets any user's password directly; `if (!is_super_admin()) json_error(...,403)` fires first; cannot target self (use change_password); bcrypt cost 12; audit action='update' notes='Password set by super_admin'; returns 200 on success. **api/v1/users/delete.php** (MODIFIED): active-user block changed from unconditional 422 to `if ($user['status'] === 'active' && !is_super_admin())` — super_admin can delete active user profiles without deactivating first. **app/admin/users/show.php** (MODIFIED): two new super_admin-only cards added inside `is_super_admin() && !$isSelf` guard — (1) "Set Password" card: Alpine `x-data="setPasswordForm()"` with new_password + confirm_password fields, min 10 chars, bcrypt pattern match, submit to set_password.php; (2) "Delete User" card: danger zone with confirmation modal (`FF_Confirm.show({ dangerMode:true })`), calls delete.php on confirm, redirects to /users on success. All 3 PHP files php -l clean. ✅ |
| S017-B | 2026-04-03 | Users Module — My Profile, Change Password, Theme Sync, Login History | 6 files built/modified. **app/admin/profile/index.php** (NEW): fixes live 404 on topbar "My Profile" link; two-column layout — left: tabbed card (Profile Edit + Login History tabs), right: Change Password card + Account Info card; Profile tab uses D19 optimistic lock, posts to api/v1/users/update.php with role_id unchanged; Login History tab shows last 10 audit_log WHERE action='login' for current user. **api/v1/users/change_password.php** (NEW): any authenticated user changes own password inline; requires current_password verified via `password_verify()`; new_password min 10 chars; bcrypt cost 12; audit action='update'. **api/v1/users/send_password_reset.php** (NEW): super_admin triggers password reset email for another user; generates bin2hex(32) plain token, stores SHA-256 hash, 2-hour expiry; cannot target self; Mailer::send(). **api/v1/users/save_preference.php** (NEW): persists theme_preference ('dark'/'light') to users.theme_preference + syncs $_SESSION['ff_user']['theme']; reads JSON body (FF_Api.post sends JSON, not form data). **includes/topbar.php** (MODIFIED): theme toggle `toggle()` function extended to call save_preference.php via `FF_Api.post(...)` after FF_Theme.toggle() — persists choice to DB. **app/admin/users/show.php** (MODIFIED): Send Password Reset Email card added (super_admin only, not self); login history table added (last 10 from audit_log action='login' for this user). All files php -l clean. ✅ |
| S018 | 2026-04-03 | Reservations Module (API + Admin UI + Dashboard Fix) | 11 new files built, 2 existing files modified. All 14 stop conditions passed. **DB**: reservations + reservation_units tables already existed in schema (created in S002). No migrations needed. **Bugs found and fixed during stop-condition tests**: (1) `eu.equipment_template_id` → `eu.template_id` (actual column name in equipment_units); (2) audit_log inserts used `description` (column does not exist), wrong action ENUMs (`created/updated/deleted/status_changed`) — fixed to `notes` + correct ENUM values (`create/update/delete/status_change`) + added `user_name`/`entity_label` columns; (3) `eu.make`/`eu.model` don't exist on equipment_units — removed from show.php + units_by_customer.php. **API (8 endpoints in api/v1/reservations/)**: index (paginated; filters: status/pickup_date/pickup_date_from/to/customer_id/q LIKE; sort allowlist pickup_date/created_at/company_name/status/priority; default pickup_date ASC; JSON_ARRAYAGG units per row; null unit guard in decode loop), show (full detail + units[with vin/year/current_status] + audit_log[last 20]; al.notes AS description alias), create (required: contact_name/company_name/pickup_date/quantity; units[] array with entry_type system/manual; FOR UPDATE conflict detection per unit+pickup_date: blocks if same unit already has pending/confirmed reservation or active lease on same date; equipment status→reserved for system units on confirmed creation), update (D19 optimistic lock; blocks if completed/cancelled; editable fields only), delete (soft-delete; blocks if confirmed/completed — must cancel first; reverts reserved→available on linked units), update_status (full state machine: pending→confirmed/cancelled, confirmed→cancelled, completed→confirmed reverse [manager only], cancelled=TERMINAL; cancel_reason required; FOR UPDATE re-check on pending→confirmed; appends cancel reason to internal_notes; equipment status transitions per state), mark_out (confirmed→completed; stamps marked_out_at/by; optional lease_id linkage written to reservation_units.lease_id_linked; unit status→on_lease if lease linked else available), units_by_customer (returns customer_units via active/pending leases, available_units fleet-wide, templates for Trailer Type dropdown). **Admin UI (3 pages in app/admin/reservations/)**: index.php (fixes live 404 on sidebar link ✅; two-table layout: Chassis In [pending+confirmed merged via 2 parallel API calls + client-side sort] / Chassis Out [completed]; 4 KPI tiles: Total Active/Pending/Confirmed/Today's Pickups [clickable to filter by date]; filter toolbar: search/pickup_date/priority/sort/dir; Chassis In row actions: Edit pencil→show, Confirm [pending only], Chassis Out [confirmed only], Cancel [modal with reason], Delete [pending only]; Chassis Out row actions: View, Reverse [manager only]; cancel modal with reason textarea + error display; all actions reload both tables), create.php (mode toggle Existing Customer / Manual Entry; customer mode: dropdown auto-fills company+contact, units_by_customer API loads customer-leased + available units in optgroups; manual mode: free-text unit number + Add button; selected units preview list with × remove buttons; quantity auto-syncs to unit count; expanded fields collapsible: pickup_time/priority/phone/email/yard/purpose/internal_notes; conflict 409 shown as form error banner), show.php (two-column layout: left [detail card view+edit, units table, activity log], right [actions panel + summary card]; context-sensitive actions panel per status; edit mode: D19 optimistic lock via hidden updated_at field; cancel modal on show page; units table: unit#/type/year/vin/status_at_reservation/current_status/entry_type badge/linked_lease link; activity log: last 20 audit entries from show API). **Dashboard fixes (2 files)**: api/v1/dashboard/kpis.php — todays_pickups changed from `leases WHERE start_date=today AND status IN pending/active` to `reservations WHERE pickup_date=today AND status IN pending/confirmed`; app/admin/dashboard/index.php — Today's Pickups tile href changed from `/leases?start_date=today` to `/reservations?pickup_date=today`. **State machine implemented exactly per spec §6**: pending→confirmed (conflict re-check FOR UPDATE), pending→cancelled, confirmed→cancelled, completed→confirmed (manager only), cancelled=TERMINAL. **14 stop conditions**: SC1 php -l all 13 files clean ✅; SC2 /reservations sidebar 200 ✅; SC3 GET api/v1/reservations/index.php → 200 paginated (0 items) ✅; SC4 POST create → 201 {id:2} ✅; SC5 GET show?id=2 → 200 status=pending company=LOTUS TERMINALS units=[] audit_log=[1 entry] ✅; SC6 POST update_status confirmed → 200 {id:2,status:confirmed} ✅; SC7 POST mark_out → 200 {id:2,status:completed,marked_out_at:...} ✅; SC8 conflict detection → 409 CONFLICT "already reserved" ✅; SC9 completed→cancelled INVALID_TRANSITION → 409 ✅; SC10 UNAUTHORIZED no session → 401 ✅; SC11 /reservations 200 ✅; SC12 /reservations/create 200 ✅; SC13 /reservations/show?id=2 200 ✅; SC14 units_by_customer → 200 templates=[{53ft Dry Van}] ✅. |
| S017-UX | 2026-04-03 | UX Polish — Scrollable Tab Tables, Filter/Sort Bars, Global Search, List Page Redesigns | 9 files modified, 1 new file created. **Scrollable tab tables (public/assets/css/app.css + 4 show pages)**: 4 CSS classes added — `.tab-table-container` (max-height calc(100vh-320px), overflow-y auto), sticky thead (position:sticky/top:0/z-index:2/background:var(--bg-surface)), `.tab-filter-bar` (flex row with gap+border-bottom), `.tab-table-footer` (flex row with count + load-more button). Applied to all sub-tables on customers/show.php (leases/invoices/damage_claims/mileage_logs), equipment/show.php (leases/damage_claims/mileage_logs/work_orders/inspections), vendors/show.php (work_orders converted from server-rendered to Alpine FF_VendorWorkOrders() component calling maintenance_work_orders/index.php), leases/show.php (invoices/damage_claims/mileage_logs/inspections). Each tab has filter selects appropriate to its data: leases (status/sort/dir), invoices (status/sort/dir), damage_claims (severity/status/dir), mileage_logs (log_type/dir), work_orders (status/work_type/sort/dir), inspections (inspection_type/status/dir). JS components updated to add `*Total`/`*Page`/`*Filters` state per tab, `append` param on load functions, `loadMore*()` + `applyFilters*()` methods, pagination.total tracking. `<template x-if>` → `x-show` for filter bar compatibility. Old per-page=50 hard limit replaced with proper pagination (showing X of Y + Load more). **leases/show.php stat tiles**: `l.total_paid` added to server-side query; stat-grid (Total Invoiced, Total Paid, Outstanding, Currency) moved from Alpine Overview template to server-rendered PHP above the tab component — matches customers/show and equipment/show pattern so tiles persist across all tabs. **api/v1/search.php** (NEW): global search endpoint for topbar ⌘K FF_Search component. Accepts `?q=` (min 2 chars, clean_string null guard). Searches customers (company_name/contact_name/email), equipment_units (unit_number/vin/make/model), leases (contract_number/company_name_snapshot), invoices (invoice_number/company_name_snapshot — NOT customer_name_snapshot which doesn't exist), vendors (name/contact_name). Up to 5 results per type. Returns `{success:true, data:{results:[{type,title,meta,url}]}}` matching FF_Search._renderResults() contract. **leases/index.php redesign**: summary-strip (thin dot bar) → 4 stat-grid KPI tiles (Active with pending delta, Pending, Completed with cancelled delta, Active Revenue from dashboard/kpis). 5 parallel loadKpis() API calls. Sort By + Direction selects added to toolbar-right. Sortable column headers: Contract, Dates, Status with ↑/↓ indicator. setSort() toggles direction. Closed tab fixed: was fetching only completed, silently dropping cancelled; now fetches unfiltered + client-side filters completed|cancelled. Pagination on All tab only; open/closed fetch per_page=200 for complete client-side filtering. Error/empty states with SVG icons added. **invoices/index.php**: Sort By select (Invoice #, Invoice date, Due date, Amount, Balance due, Status) + Direction select added. Sortable column headers: Invoice #, Date, Due, Total, Balance, Status with ↑/↓. `filters.sort`/`filters.dir` wired into load(). Bug fixes: `stat-card__value` → `stat-value` on 3 AR aging tiles (non-existent class, unstyled values); `pagination.current_page` → `pagination.page` (API returns `page` key — pagination buttons were broken); isOverdue() adds written_off to exempt statuses. formatMoney() helper added. All files php -l clean. ✅ |
| S017-X | 2026-04-03 | Sidebar Scroll Persistence + Copyright Footer + Stress Test | 4 files modified. **public/assets/js/app.js** (MODIFIED): sidebar scroll persistence added inside DOMContentLoaded — `sessionStorage` key `ff-sidebar-scroll`; saves on `pagehide` event (not beforeunload — more reliable on mobile/Safari); restores on page load via `el.scrollTop = parseInt(saved)`. **includes/footer.php** (MODIFIED): copyright footer added as `<footer class="app-footer">` outside `<main>` but inside `.app-main`, as last flex child — `&copy; <?= date('Y') ?> Avi Nanda. All rights reserved.` + `FleetForge <?= e(FF_VERSION) ?>`. **public/assets/css/app.css** (MODIFIED): `.app-footer` class added — `flex-shrink:0; display:flex; align-items:center; justify-content:space-between; padding:12px 32px; border-top:1px solid var(--border-color); font-size:0.6875rem; color:var(--text-muted); margin-top:auto` — `margin-top:auto` pushes to page bottom naturally in flex column (only visible when scrolled to bottom on tall pages; visible by default on short pages). **api/v1/customers/show.php** (BUG FIX): stress test found HTTP 500 — `Unknown column 'total_invoiced' in field list`; customers schema has `total_revenue` + `account_credit_balance` not `total_invoiced`/`total_paid`; also `late_fee_rate` → `late_fee_value`; 3 columns corrected; verified returning 200 success:true. All other admin module pages passed stress test — PHP syntax clean across all 40+ files. ✅ |
| S015 | 2026-04-03 | Maintenance Work Orders Module (API + Admin UI) | 13 new files built, 1 existing file modified. All 27 stop conditions passed. **API (9 endpoints in api/v1/maintenance_work_orders/)**: index (paginated; filters: status/work_type/priority/equipment_unit_id/vendor_id/date_from/date_to/q; JOINs equipment_units+templates+vendors+users for labels; sort allowlist), show (full WO detail + line_items[] array + all JOIN labels), create (atomic WO# generation via generate_id('WO',year) inside transaction — Trap 9; validates enums; vendor optional; status always 'open'; audit_log), update (D19 optimistic lock on updated_at; blocks terminal states completed/cancelled; vendor_id clearable with explicit null; audit old_values/new_values), delete (soft delete; blocks in_progress/waiting_parts/completed → INVALID_TRANSITION; only open/cancelled deletable), update_status (full state machine: open→in_progress,cancelled | in_progress→waiting_parts,completed | waiting_parts→in_progress | completed/cancelled TERMINAL; on 'completed' updates vendors.total_spent in same transaction — Trap 6; resolution_notes saved on complete; audit_log action='status_change'). **Line items (3 endpoints in api/v1/maintenance_work_orders/line_items/)**: add (validates WO editable; total_cost = qty×unit_cost via bcmath D16; recalculates mwo.labor_cost/parts_cost/total_cost in same transaction — Trap 6), update (same recalc; no optimistic lock — no updated_at on this table), delete (hard delete — no deleted_at on maintenance_line_items; same recalc). **Admin UI (3 pages in app/admin/maintenance_work_orders/)**: index.php (4 KPI tiles: total/open/active/completed-this-month; KPI tiles are clickable and set Alpine filter; Alpine filterable table with status+work_type+priority+q filters; sort: requested_date/total_cost/WO#/status/priority; badge helpers for all ENUMs; URL target for /maintenance navigation entry), create.php (unit dropdown JOINs templates for brand/model; vendor dropdown optional; work_type/priority/dates/mileage/assignment sections; submit via FF_Api.post→redirect to show; pre-populate unit_id from ?unit_id= query param), show.php (hero header with WO# + status badge; 4 KPI cost tiles server-rendered; status transition buttons rendered server-side per state machine — completion shows resolution_notes textarea prompt before confirming; inline edit mode D19 lock via updated_at; line items CRUD panel with add/delete; vendor link; equipment link; delete modal). **Equipment show.php patched**: Maintenance tab stub ("coming in S016") replaced with real lazy-loaded Alpine table via api/v1/maintenance_work_orders/index.php?equipment_unit_id=N; workOrders[]/workOrdersLoading/workOrdersLoaded state added; $watch('activeTab') extended; loadWorkOrders() method added; woBadge helpers added; "+ New Work Order" button pre-fills ?unit_id=. **Key decisions applied**: No work_order_status_log table — all status history in audit_log (action='status_change'). Equipment unit status NOT auto-flipped on WO create/complete — separate user action via equipment/units/update_status.php. WO number format: WO-YYYY-NNNNN. vendors.total_spent updated only on 'completed' transition (not on create/update). maintenance_line_items has no soft delete. All 13 PHP files php -l clean. **27 stop conditions**: GET list empty total=0 ✅; POST create id=1 WO-2026-00001 ✅; GET list total=1 ✅; GET show status=open unit=TEST-U001 ✅; missing work_type → 422 VALIDATION_ERROR ✅; POST update 200 ✅; STALE_DATA → 409 ✅; add labor item 201 line=150 wo=150 ✅; WO costs labor=150 parts=0 total=150 ✅; add parts item wo=270 ✅; delete parts item wo=150 ✅; status open→in_progress ✅; INVALID_TRANSITION in_progress→open ✅; status in_progress→completed ✅; IMMUTABLE_RECORD on completed ✅; create WO#2 WO-2026-00002 ✅; soft delete open WO ✅; show deleted → 404 ✅; UNAUTHORIZED no session ✅; status filter open total=0 ✅; status filter completed total=1 ✅; /maintenance_work_orders 200 ✅; /maintenance_work_orders/create 200 ✅; /maintenance_work_orders/show?id=1 200 ✅; equipment/show 200 ✅; maintenance stub removed ✅; loadWorkOrders present ✅; sort injection guard safe ✅. |
| STUB-1 | 2026-04-02 | Stub Replacement — Cross-Module Tab Fix | 3 files fixed, 5 stub tabs replaced with real data. Full codebase search performed (10 grep patterns). **Files fixed:** (1) app/admin/customers/show.php — Leases tab: stub "will be available in S006" replaced with Alpine.js lazy-loaded table (contract_number, unit, status badge, start/end dates, monthly_rate, View action) via existing GET /api/v1/leases?customer_id=N; Invoices tab: stub "will be available in a later session" replaced with Alpine.js lazy-loaded table (invoice_number, billing_period, status badge, due_date, total_amount, balance_due, View action) via existing GET /api/v1/invoices?customer_id=N; Alpine state extended with leases/invoices arrays + loading flags + $watch lazy-load on tab activation; leaseBadgeClass() + invoiceBadgeClass() helpers added; docblock updated to remove stub references. (2) app/admin/equipment/show.php — Lease History tab: stub "Lease history coming in S008" replaced with Alpine.js lazy-loaded table (contract_number, customer, status badge, start/end dates, monthly_rate, View action) via existing GET /api/v1/leases?unit_id=N; leaseHistory[] + leasesLoading/leasesLoaded state added; $watch extended; leaseBadgeClass() + loadLeaseHistory() methods added; docblock updated. (3) app/admin/leases/show.php — docblock updated: "stub for S008+" → "placeholder — requires amendments table". **Remaining stubs that CANNOT be fixed (missing dependencies):** (a) leases/show.php Amendments tab — requires amendments table + API (not yet built); (b) equipment/show.php Maintenance tab — requires maintenance_work_orders module (planned S016); (c) equipment/show.php Documents tab — requires documents module (planned S021); (d) leases/edit.php rate editing — requires amendment workflow. **Not stubs (verified OK):** login.php placeholder hash is a security constant-time pattern; form field HTML placeholder attributes are legitimate UX; dashboard/index.php "stubs replaced" is historical docblock. **HTTP checks:** PHP not available locally — code reviewed manually for syntax correctness. |

---

## DECISIONS & DEVIATIONS — LOCKED 🔒

| # | Topic | Decision | Reason |
|---|-------|----------|--------|
| D1 | Auth | Custom PHP auth (email/password/bcrypt/sessions) — NOT Auth0 | Simpler, fully controlled. auth0_sub column kept nullable for future use. |
| D2 | gps_devices table | DROPPED. GPS fields stay on equipment_units directly. | GPS simplified to tracking URL + mileage only. Can add separate table later if needed. |
| D3 | Billing math | All pure math isolated in lib/Billing/ separate files. InvoiceGenerator is the ONLY class that writes to DB. | Easier to modify, test, audit individual formulas. |
| D4 | Schema corrections | 28 issues corrected. FLEETFORGE_DATABASE_MASTER.sql (v1.2 — sole schema source) [PASS-1:L7] is the authoritative schema reference. Raw spec schema sections are superseded. | Correctness + integrity before a line of code is written. |
| D5 | SOFT_DELETE_TABLES | Final list (15 tables — payments added [PASS-13:F2]): users, customers, customer_notes, equipment_templates, equipment_units, leases, damage_claims, invoices, maintenance_work_orders, documents, vendors, credit_notes, reservations, rate_cards, payments | customer_documents and equipment_documents dropped. equipment_templates, equipment_units, and rate_cards confirmed via full schema scan. Payments added per CRA compliance (PASS-13:F2). |
| D6 | Table count | 94 tables total: 59 core + 34 accounting + 1 utility (schema_migrations). gps_devices, customer_documents, equipment_documents dropped from original 62. | D2 + agent review + PASS-12:M2 |
| D7 | Base path | `/fleetforge` subpath — matches existing spec. 🔒 | Dedicated Lightsail instance. All URLs: yourdomain.com/fleetforge/... |
| D8 | Server | Lightsail to be provisioned in Session 1. Fresh AWS account. 🔒 | Follow AWS SETUP GUIDE in this file. $20/mo plan, us-west-2, Ubuntu 22.04. |
| D9 | Storage | S3 via StorageClient abstraction [INFRA] | All files to S3 in production. Local driver for development. StorageClient built in Session 1. |
| D10 | Email | AWS SES SMTP via Mailer.php [INFRA] | Mailer configured in Session 1. All email through lib/Notifications/Mailer.php. |
| D11 | Tax rates | **LOCKED: Look up at invoice time** — TaxCalculator reads current rate from tax_rates table when invoice is generated. [INFRA:U19 resolved] | CRA compliance. If BC PST changes mid-lease, new invoices use the new rate. Rates never frozen on lease. |
| D12 | Invoice immutability | Sent invoices are frozen [PASS-13:F1] | Financial fields cannot be edited after status leaves 'draft'. Void + recreate for corrections. |
| D13 | Payments soft-delete | Added to SOFT_DELETE_TABLES [PASS-13:F2] | Payments must NEVER be hard-deleted. 15 soft-delete tables total. |
| D14 | Day counting | Inclusive: (end - start) + 1 [PASS-3:1A] | A lease from Mar 10 to Mar 10 = 1 billable day. |
| D15 | Invoice numbering | Strictly sequential, gap-free [PASS-13:I2] | Atomic counter in settings per year. Permanent deletion never allowed. |
| D16 | Monetary arithmetic | bcmath only [PASS-10:6] | Never use float operators on monetary values. ProRateCalculator uses string-typed decimals. |
| D17 | Autoloading | PSR-4 via Composer [PASS-8:6] | FleetForge\\ namespace maps to lib/. vendor/autoload.php in config/app.php. |
| D18 | Cross-currency payments | BLOCKED — payment currency must match invoice currency [PASS-1:H3] | API returns 422 CURRENCY_MISMATCH if they differ. All FX handling explicit in accounting module. |
| D19 | Optimistic locking | All update endpoints check updated_at before saving [PASS-8:4G] | Returns 409 STALE_DATA if record modified by another user since form load. |
| D20 | Concurrency — FOR UPDATE | Required for: lease creation, lease close, payment allocation, credit application [PASS-8:4] | Not just lease creation — every operation that reads-then-writes financial state. |
| D21 | Cron advisory locks | Every write-heavy cron uses MySQL GET_LOCK() [PASS-8:4B, PASS-15:C1] | Prevents duplicate runs. Applies to: invoice_generate_monthly, invoice_overdue, late_fee_apply, health_scores, risk_scores, compliance_alerts, reconcile_counters. |
| D22 | Granular tax exemption | gst_exempt and pst_exempt are independent booleans on customer, lease, and invoice [PASS-13:T2] | A customer can be GST-exempt but PST-liable. TaxCalculator accepts both flags. |
| D23 | Invite token expiry | **7 days** — resolves 3-way conflict in spec (lines 108/2388/3587 said 7 days/48 hours/1 hour respectively). 7 days is the correct value everywhere. | New employees may not check email immediately. Single-use, stored hashed. |
| D24 | Composer dependencies | `composer.json` includes both `mpdf/mpdf` AND `aws/aws-sdk-php` — this is correct and intentional. Spec comment `vendor/ ← Composer (mPDF only)` is stale — do NOT remove AWS SDK. | StorageClient.php requires AWS SDK for S3 driver. Mailer.php uses it for SES. |
| D25 | function_exists() guards | Every function in `includes/functions.php` and the `env()` function in `config/app.php` is wrapped with `if (!function_exists(...))`. | Laravel Herd uses symlinks — different `require_once` call paths resolved to different canonical paths, causing PHP to load files twice and fatal on redeclaration. Guards make both files safe to include any number of times. |
| D26 | /auth/ route in router | `public/index.php` has an explicit `/auth/` route branch pointing to `app/auth/` — added BEFORE the admin catch-all. | Auth pages live at `app/auth/`, not `app/admin/auth/`. Without this, all auth URLs returned 404. |
| D27 | Herd docroot + asset_url() | Laravel Herd auto-detects `public/` as the document root. `APP_URL` in `.env` is the origin only (`http://fleetforge.test`) — no `/fleetforge` suffix. `base_url()` now appends `FF_BASE_PATH` for app routes. New `asset_url()` function generates static asset URLs without the base path prefix so they resolve correctly under Herd. `FF_BASE_PATH = '/fleetforge'` remains locked (D7). | Herd's `try_files` resolves `/fleetforge/assets/css/app.css` to `public/fleetforge/assets/css/app.css` which does not exist. Assets must be served from the docroot directly. Production (Apache + Alias `/fleetforge` → `public/`) uses the same `APP_URL` origin-only pattern. |
| D28 | .env editor warning | `.env` must be edited with VS Code, nano, or a plain-text editor ONLY. Never open with macOS TextEdit. | TextEdit silently replaces standard ASCII characters (quotes, hyphens, URLs) with Unicode "smart" equivalents that break PHP's env parser. Corrupts APP_URL and other values invisibly. |
| D29 | Remember-me token storage | Token hash stored in `users.remember_token` column — NOT a separate `user_remember_tokens` table. `auth_login()`, `auth_logout()`, `auth_check_remember_me()` all use `users.remember_token`. logout.php clears it with `UPDATE users SET remember_token = NULL WHERE id = ?`. | Schema has `remember_token` column on users. A separate table was considered but rejected for simplicity. |
| D30 | Static asset URLs — always asset_url() | Every static asset reference (CSS, JS, favicon, images, icons) in any PHP file MUST use `asset_url()`, never `base_url()`. `base_url()` prepends FF_BASE_PATH (/fleetforge) → produces /fleetforge/assets/... → 404 under Herd (and in production under Apache Alias). `asset_url()` outputs origin-only URLs that resolve correctly in both environments. Rule: if it's in `public/assets/`, use `asset_url()`. If it's an app route (page link, form action, API call), use `base_url()`. | header.php + footer.php shipped with base_url() for assets in S001 — dashboard rendered with no styling until caught in S003. |
| D31 | Session compression — dashboard delivered in one pass | Build sessions S004 covered all of the original plan's S029 (KPI backend), S030 (KPI UI + drilldowns), and the planned-but-not-detailed S031+ dashboard chart sessions (all 8 ApexCharts + activity feed). This is a session compression decision — the original ~3 sessions were merged into one because the work was tightly coupled (chart API + chart rendering + dashboard page are useless without each other). Session numbering: our build S001–S004 maps to original-plan S005–S028 (foundation) + S029–S030+ (dashboard). Customers originally queued at our S004 is now S005. | All dashboard data (KPIs, 8 charts, activity feed) is fetched in a single parallel burst on page load — separating backend from frontend would have required re-touching the same files twice. |
| D32 | CSS class hygiene — only use confirmed classes | Every HTML class used in any PHP template MUST exist in public/assets/css/app.css. Invented class names produce silent layout breakage — pages render but look completely wrong. Before writing any new template, grep app.css to confirm each class exists. Do not invent classes; extend app.css with a new named section if a new component is genuinely needed. | S005 customer UI pages shipped with ~60 non-existent CSS classes. Silent breakage not caught until user visual review. |
| D33 | Heroicon SVG files must be pre-created | public/assets/icons/ must contain an SVG file for every icon name passed to heroicon(). The function returns an invisible span placeholder when the file is missing — no error, no warning. All icons referenced in config/navigation.php and all theme-toggle icons (sun, moon) must exist as files. When adding a new icon to navigation, create the SVG file in the same session. | Theme toggle and sidebar icons were invisible for multiple sessions because public/assets/icons/ was empty. |
| D34 | mileage_unit default = 'km' | All mileage_unit columns default to 'km' (not 'miles'). ENUM order is ('km','miles') across all 8 columns in 7 tables: customers, equipment_templates, rate_card_items, leases, customer_equipment_rates, customer_rate_history, invoice_line_items, mileage_logs. All API create endpoints and admin UI forms default to 'km'. | FleetForge is primarily used by Canadian companies — kilometres is the standard unit. Changed in S008 across schema, API, UI, and live DB. |

---

## KNOWN ISSUES / CARRY-FORWARD

| # | Session | Module | Issue | Status |
|---|---------|--------|-------|--------|
| 1 | S001 | Auth | `audit_log` inserts in login.php + logout.php are not yet written — table does not exist until DB schema is run in S002. Two ⬜ tasks remain in S013. | ✅ RESOLVED S002 — audit_log inserts added to both files. |
| 2 | S001 | Functions | `clean_url()`, `format_mileage()`, and named ID generators (`generate_invoice_number()` etc.) not yet implemented. `generate_invoice_number()` now lives in `lib/Billing/InvoiceGenerator.php` (not in functions.php). Generic `generate_id()` + `generate_random_code()` are built and sufficient until specific wrappers are needed. | Partially resolved S008 — invoice numbering built. ✅ `format_mileage()` RESOLVED S013 — added to includes/functions.php. Remaining: `clean_url()`, other named generators. |
| 3 | S001 | Auth/CSRF | `generate_csrf_token()` and `verify_csrf_token()` standalone functions not written. CSRF is implemented inline in `api/bootstrap.php` (header check) and `login.php` (session token). No standalone callable yet. | ✅ RESOLVED S002 — both functions added to includes/functions.php with function_exists() guards. |
| 4 | S002 | Auth — CRITICAL | login.php SELECT query used non-existent columns `role_slug`, `theme`, `is_active` (actual: need JOIN for role_slug, `theme_preference`, `status`). Login was completely broken — PDO fatal error on every POST. | ✅ FIXED S002 deep audit — query rewritten with JOIN user_roles, correct column names, status check fixed. |
| 5 | S002 | Auth — Security | logout.php queried non-existent `user_remember_tokens` table (silently swallowed by try/catch), leaving `users.remember_token` hash live in DB after logout. Captured cookie could replay after logout. | ✅ FIXED S002 deep audit — logout.php now clears users.remember_token directly. |
| 6 | S002 | Auth — Security | login.php CSRF failure returned HTTP 200 (just set $error variable). Spec requires 403. Also: both login.php and header.php duplicated CSRF generation inline instead of calling generate_csrf_token(). | ✅ FIXED S002 deep audit — login.php now calls verify_csrf_token() + http_response_code(403). Both files now call generate_csrf_token(). |
| 7 | S002 | Schema | FULLTEXT index count: PROGRESS.md stop condition says "Must return 5 rows" but S027 test queries information_schema.STATISTICS which returns 1 row per indexed column (not per index). Actual distinct FULLTEXT indexes = 6 (customers, equipment_units, invoices, leases, vendors + acc_accounts). Count of 5 was undercount. | ✅ NOTED — 6 distinct FULLTEXT indexes, all correct. STATISTICS query returns 18 rows (multi-column indexes). Test condition was misleading. |
| 8 | S001–S002 | All PHP files | **Comment retrofit needed.** Global commenting standard established after S002: every file needs (1) top-of-file `/** */` block with path, description, dependencies, defined symbols, spec refs; (2) inline WHY comments on security/business-logic/bcmath/FOR UPDATE; (3) docblocks on every function. Files built in S001/S002 predate this standard and have partial or no comments. | Schedule a dedicated comment-retrofit session before Phase 3 (or batch into early Phase 3 sessions as files are touched). **All files built from S003 onward must include full comments from the start.** |
| 9 | S003 | Functions | `settings_get()` and `generate_id()` in `includes/functions.php` used wrong column names (`setting_key`, `setting_value`, `setting_group`) against a DB whose actual columns are `key`, `value`, `group_name` (reserved SQL words, backtick-quoted). Both functions were silently catching PDOException and returning defaults, masking the bug entirely. `generate_id()` also used `db_insert()` which doesn't add backticks — fixed to use raw `db_execute()` with explicit backticks for the INSERT. | ✅ FIXED S003 — both functions corrected. Seeded settings now readable. generate_id() atomic counter now works correctly. |
| 10 | S001 | Layout — D27 violation | `includes/header.php` used `base_url('assets/css/app.css')` and `base_url('assets/icons/favicon.svg')`. `includes/footer.php` used `base_url('assets/js/app.js')`. All three generated `/fleetforge/assets/...` URLs → 404 under Herd. Dashboard and every admin page shipped with zero CSS/JS styling from S001 onward. Masked because login page (standalone, no header.php) worked fine. | ✅ FIXED S003 — all three changed to `asset_url()`. Decision D30 added. All future files must follow D30. |
| 11 | S004 | Dashboard — KPI field names differ from original S029 contract | Original contract (S029 in session map) named fields `fleet_utilization_pct`, `open_leases_count`, `overdue_invoices_count`, `overdue_invoices_amount`, `compliance_alerts_count`, `todays_pickups_count`. Implemented names are `fleet_utilization`, `on_lease_count`, `total_active_units`, `overdue_invoices: {count, total}`, `compliance_alerts`, `open_leases`, `todays_pickups`. The implemented shape is richer (nested overdue object, separate on_lease + total counts for utilization display). No consumers exist yet beyond the dashboard page itself. | Carry-forward: if a future session builds a separate KPI widget or external consumer, be aware of the actual field names. Not a bug — the dashboard page uses the actual field names correctly. |
| 12 | S005 | Customer Admin UI — all 4 pages shipped with ~60 invented CSS class names | app/admin/customers/index.php, create.php, edit.php, show.php were written using class names that do not exist in app.css: form-input, filter-bar, data-table, badge-secondary, badge-xs, tab-nav, detail-list, note-card, stat-grid--4, form-grid, form-group--full, page-header-back, page-header-meta, and many more. Pages rendered but all layout/badge/table/form styles were completely broken. Silent failure — no PHP errors, no browser console errors. | ✅ FIXED S005 close-out — all 4 pages fully rewritten using only confirmed CSS classes. Verified HTTP 200 + no PHP errors. Decision D32 added to prevent recurrence. |
| 13 | S005 | public/assets/icons/ was empty — no SVG files existed | heroicon() reads SVGs from public/assets/icons/{name}.svg. The directory existed but contained zero files. Every heroicon() call returned `<span aria-hidden="true" class="icon-missing">` — invisible placeholder. Theme toggle button had no sun/moon icons (invisible), sidebar had no nav icons, topbar had no bell/search icons. Persisted from S001 through S005 without detection. | ✅ FIXED S005 close-out — 32 Heroicons v2 outline SVG files created in public/assets/icons/. All icons verified non-empty. Theme toggle and sidebar icons confirmed rendering. Decision D33 added. |
| 15 | S008 | Billing — TaxCalculator divide-by-100 bug | TaxCalculator initially did `bcdiv($rate, '100', 6)` before multiplying subtotal — but tax_rates DB stores rates as decimal fractions (0.0500 = 5%), not percentages (5.00). This produced near-zero tax on all invoices ($0 tax on a $2200 subtotal). | ✅ FIXED S008 — removed the division. Rates are already multipliers, multiply directly: `bcmul($subtotal, $rate, 6)`. |
| 14 | S005 | dashboard/index.php — FF_API typo + non-existent empty-state class names | `FF_API.get(...)` used on 3 lines (fetchKpis, fetchCharts, fetchActivity). Object is `window.FF_Api` (mixed case). Caused `ReferenceError: FF_API is not defined` in browser console on every dashboard load — silently killed all 3 API fetches (no KPI data, no charts, no activity). Also: `empty-state-primary` and `empty-state-secondary` used throughout (correct classes: `empty-state-title` and `empty-state-text`). Additionally, dashboard used 14 CSS classes not present in app.css: stat-card--link, stat-card--danger, stat-card--warning, stat-card--error, stat-skeleton, chart-skeleton, grid-2-1, activity-feed, activity-item, activity-dot, activity-body, activity-desc, activity-meta, p-4, text-muted, link. | ✅ FIXED S005 close-out — FF_API→FF_Api (3×), empty-state class names corrected (4× each). All 14 missing CSS classes added to app.css Section 20. Dashboard verified: HTTP 200, correct FF_Api calls in rendered HTML. |
| | | | | |
| **—** | **—** | **— AUDIT-1 FINDINGS (2026-04-02) —** | **69 issues found across S001–S008. Grouped below by priority.** | **—** |
| | | | | |
| 16 | AUDIT-1 | **CRITICAL — Leases: unit not reserved on create** | `api/v1/leases/create.php` creates lease in `pending` but does NOT change unit status to `reserved`. Two sequential requests can create two pending leases for the same unit. FOR UPDATE only prevents concurrent, not sequential. Fix: set unit status=`reserved` + write `equipment_status_log` inside the create transaction. | ✅ FIXED S008.5 — unit reserved on lease create; equipment_status_log written. |
| 17 | AUDIT-1 | **CRITICAL — Leases: activate does not generate Invoice 1** | `api/v1/leases/activate.php` lines 152-155 had only a `// TODO` comment. `InvoiceGenerator` class exists in `lib/Billing/` but was never wired in. Activating a lease produced zero invoices. Fix: call `InvoiceGenerator::createFromLease()` inside the activate transaction after status change. | ✅ FIXED S008.5 — Invoice 1 generated on activate; invoice_id returned in response. Also required nesting guard in db_transaction() (includes/db.php). |
| 18 | AUDIT-1 | **CRITICAL — Leases: close does not generate final invoice** | `api/v1/leases/close.php` lines 167-169 had only a `// TODO` comment. Final billing (mileage reconciliation, pro-rate) not wired. Fix: call `InvoiceGenerator` for final period + mileage inside close transaction. | ✅ FIXED S008.5 — Final invoice generated on close with mileage overage extra_lines; invoice_id returned. |
| 19 | AUDIT-1 | **CRITICAL — Invoices: 5 endpoints have audit_log OUTSIDE transaction** | `api/v1/invoices/create.php`, `update.php`, `send.php`, `void.php`, `delete.php` — all had `db_insert('audit_log')` outside `db_transaction()`. Fix: move all audit_log inserts inside the transaction closure. | ✅ FIXED S008.5 — all 5 endpoints: audit_log moved inside transaction. send.php wrapped in new db_transaction(). |
| 20 | AUDIT-1 | **CRITICAL — Leases: update has no transaction** | `api/v1/leases/update.php` lines 133-151 — `db_update` and `audit_log` were separate unprotected statements. Fix: wrap in transaction. | ✅ FIXED S008.5 — both db_update and audit_log wrapped in db_transaction(). |
| 21 | AUDIT-1 | **CRITICAL — Customer notes: missing soft-delete filter** | `api/v1/customers/notes/index.php` (ln 36) and `notes/create.php` (ln 51) use `db_exists('customers', 'id = ?', ...)` without `deleted_at IS NULL`. Allows fetching/creating notes for soft-deleted customers. | ✅ RESOLVED S008.5 — db_exists() already auto-appends AND deleted_at IS NULL for all SOFT_DELETE_TABLES. Verified in includes/db.php. No code change needed. |
| 22 | AUDIT-1 | **MAJOR — Leases: pending badge wrong color** | `app/admin/leases/index.php` and `show.php` both mapped `pending` to `badge-warning`. Design spec §9 says pending=`badge-info`. Also summary strip dot used `var(--color-warning)`. | ✅ FIXED S008.5 — all three locations changed: index.php JS map + summary dot, show.php PHP match + JS map. |
| 23 | AUDIT-1 | **MAJOR — Leases: missing cancel + reopen endpoints** | No `api/v1/leases/cancel.php` or `reopen.php`. State machine required pending→cancelled, active→cancelled, completed→active(reopen). | ✅ FIXED S008.5 — both endpoints created. cancel.php: pending→cancelled or active→cancelled (Manager required for active). reopen.php: completed→active (Manager required). Full transaction + FOR UPDATE + all logs. |
| 24 | AUDIT-1 | **MAJOR — Equipment: no status-change endpoint** | Cannot put units into `maintenance`, `inactive`, `decommissioned`, or `reserved` via API. Only implicit changes happen via lease activate/close. | ✅ FIXED S008.5 — api/v1/equipment/units/update_status.php created with full state machine (6 statuses, decommissioned terminal). FOR UPDATE, equipment_status_log, audit_log all in transaction. |
| 25 | AUDIT-1 | **MAJOR — Equipment: search filter copy-paste bug** | `app/admin/equipment/index.php` ln 347: search field initialized from `$_GET['status']` instead of `$_GET['search']`. Search was broken. | ✅ FIXED S008.5 — changed to $_GET['search']. |
| 26 | AUDIT-1 | **MAJOR — Invoices: BEM class mismatch** | `app/admin/invoices/index.php` and `show.php` used `stat-card__label` / `stat-card__value` (BEM) — classes don't exist in app.css → unstyled. | ✅ FIXED S008.5 — changed to stat-label / stat-value / stat-delta in both files. |
| 27 | AUDIT-1 | **MAJOR — Invoices: search param mismatch** | `app/admin/invoices/index.php` ln 304 sends search as `q` param. | ✅ RESOLVED S008.5 — verified API also uses 'q'. Both sides match. No code change needed. |
| 28 | AUDIT-1 | **MAJOR — CSS: variable names diverge from spec** | `app.css` uses different names than spec: `--bg-body` vs `--bg-page`, etc. | ✅ FIXED S008.5 — spec-name aliases added in :root: --bg-page, --bg-card, --color-accent, --color-accent-hover, --text-muted, --text-inverse, --border-strong, --bg-selected. |
| 29 | AUDIT-1 | **MAJOR — CSS: modal-md and modal-full missing** | Only `modal-sm` and `modal-lg` existed. Spec defines 4 sizes. | ✅ FIXED S008.5 — .modal-md (560px) and .modal-full (min(90vw,1100px)) added to app.css. |
| 30 | AUDIT-1 | **MAJOR — Customers: no status transition validation** | `api/v1/customers/update.php` allowed any status→any status with no state machine checks. | ✅ FIXED S008.5 — allowed transitions map added: active→inactive/suspended/credit_hold; inactive→active; pending→active/inactive; suspended→active/inactive; credit_hold→active/suspended. Returns 409 INVALID_TRANSITION. |
| 31 | AUDIT-1 | **MAJOR — Customers: SELECT * in show endpoint** | `api/v1/customers/show.php` used `SELECT *` — exposes all internal columns. | ✅ FIXED S008.5 — replaced with explicit ~30-column list. |
| 32 | AUDIT-1 | **MAJOR — Invoices: send has no status_log + no transaction** | `api/v1/invoices/send.php` had status update + audit_log outside a transaction. | ✅ FIXED S008.5 — wrapped in db_transaction() (covered under FIX #19). |
| 33 | AUDIT-1 | **MINOR — font-mono missing on amounts/dates** | ~15 locations across 6 files lacked `font-mono` class. | ✅ FIXED S008.5 — font-mono class added to stat-value elements in invoices/index.php and invoices/show.php. .font-mono utility class added to app.css. |
| 34 | AUDIT-1 | **MINOR — Invoice void badge lacks strikethrough** | `invoices/index.php` and `show.php` — void uses `badge-neutral` without line-through. | ✅ FIXED S008.5 — .line-through utility class added to app.css; void badge now uses badge-neutral line-through. |
| 35 | AUDIT-1 | **MINOR — CSS: btn-xl and --bg-selected missing** | `btn-xl` size class and `--bg-selected` variable not in app.css. | ✅ FIXED S008.5 — .btn-xl added (font-size:1rem, padding:12px 24px, height:54px). --bg-selected added as alias in :root (covered under FIX #28). |
| 36 | AUDIT-1 | **MINOR — Equipment: slug/unit_number uniqueness includes soft-deleted** | `units/create.php`, `units/update.php`, `templates/create.php` — uniqueness checks included soft-deleted rows. | ✅ FIXED S008.5 — units/update.php direct query updated with AND deleted_at IS NULL. Other files use db_exists() which auto-filters. |
| 37 | AUDIT-1 | **MINOR — Lease close overwrites internal_notes** | `api/v1/leases/close.php` replaced `internal_notes` with `close_notes` instead of appending. | ✅ FIXED S008.5 — reads existing internal_notes then appends with "---\nClose notes:" separator. |
| 38 | AUDIT-1 | **MINOR — Error code naming** | `leases/delete.php` and `equipment/units/delete.php` used `LEASE_NOT_ACTIVE` for opposite meanings. | ✅ FIXED S008.5 — leases/delete.php → LEASE_IS_ACTIVE; equipment/units/delete.php → UNIT_ON_LEASE. |
| 39 | AUDIT-1 | **MINOR — Invoices: create.php not in <form> tag** | Uses bare `<button @click>` instead of `<form @submit.prevent>` — Enter-to-submit broken. | ✅ FIXED S008.5 — outer div changed to form with @submit.prevent; button type="submit". |
| 40 | AUDIT-1 | **MINOR — Leases: no Invoices tab on show page** | `leases/show.php` had Overview/Status Log/Amendments tabs but no Invoices tab. | ✅ FIXED S008.5 — Invoices tab added: button + panel + Alpine loadInvoices() + invBadgeClass(). |
| 41 | AUDIT-1 | **MINOR — Dashboard: hardcoded hex in ApexCharts** | `dashboard/index.php` — chart palette used hardcoded hex instead of CSS vars. | ✅ FIXED S008.5 — palette reads via getComputedStyle(document.documentElement) + cssVar() helper. |
| 42 | AUDIT-1 | **MINOR — Equipment show: no delete button** | `equipment/show.php` had Edit button but no Delete button. | ✅ FIXED S008.5 — Delete Unit button added (hidden when status=on_lease); deleteUnit() JS function added. |
| 43 | AUDIT-1 | **MINOR — InvoiceGenerator missing updated_by** | `lib/Billing/InvoiceGenerator.php` invoice insert set `created_by` but not `updated_by`. | ✅ FIXED S008.5 — 'updated_by' => $params['created_by'] ?? null added to invoice insert. |

---

## NEXT SESSION STARTS WITH

```
Session S018 — Reservations Module (Phase 9)

S017 — Users + Admin Module — fully complete and tested (15/15 stop conditions pass).
  Built: api/v1/users/ (7 endpoints), app/admin/users/ (3 pages),
         app/admin/audit/index.php, app/admin/settings/index.php,
         accept_invite.php rewritten (was referencing non-existent user_invitations table).

═══════════════════════════════════════════════════════════════════
CONTEXT — READ BEFORE WRITING ANY CODE
═══════════════════════════════════════════════════════════════════

READ IN THIS ORDER:
  1. FLEETFORGE_CLAUDE_CODE_REFERENCE.md  ← patterns, all helper signatures, Trap list
  2. FLEETFORGE_PROGRESS.md               ← SESSION LOG, DECISIONS, KNOWN ISSUES
  3. FLEETFORGE_SPEC_FINAL.md             ← grep "reservations\|reservation"
  4. FLEETFORGE_DATABASE_MASTER.sql       ← grep "reservations\|reservation_units"

VERIFY BEFORE STARTING:
  curl http://fleetforge.test/fleetforge/api/v1/health → {"success":true,"data":{"db":true,...}}
  Login: admin@fleetforge.test / FleetForge2025!

═══════════════════════════════════════════════════════════════════
ROADMAP — REMAINING MODULES (priority order)
═══════════════════════════════════════════════════════════════════

S018 — Reservations Module (Phase 9)
  WHY NEXT: /reservations is in the sidebar and leads to a 404. Required for equipment booking workflow.
  SCOPE:
    - reservations table: id, customer_id, equipment_unit_id, reservation_date, return_date, status ENUM(pending,confirmed,active,completed,cancelled), notes, created_by
    - reservation_units table: reservation_id, equipment_unit_id (for multi-unit reservations)
    - api/v1/reservations/{index,show,create,update,delete,update_status}.php
    - State machine: pending → confirmed → active → completed; pending/confirmed → cancelled (TERMINAL)
    - app/admin/reservations/{index,create,show}.php
    - Index: two-column view — Equipment In (pending/confirmed) + Equipment Out (active/completed)
    - Conflict detection: block if unit already reserved/leased for overlapping dates
    - Permission module: reservations
    - Stop conditions: create, confirm, conflict detection, calendar view, UNAUTHORIZED

S019 — Rates Module (Phase 10)
  WHY NEXT: /rates is in the sidebar; rate cards drive lease pricing defaults.
  SCOPE:
    - rate_cards table: id, name, equipment_template_id (or null = all types), daily_rate, weekly_rate, monthly_rate, currency, effective_from, effective_to (date range), is_active
    - customer_equipment_rates: customer_id, equipment_template_id, daily_rate, weekly_rate, monthly_rate (custom per-customer overrides)
    - api/v1/rate_cards/{index,show,create,update,delete}.php
    - api/v1/customer_equipment_rates/{index,upsert,delete}.php
    - app/admin/rates/index.php — rate cards list + customer rate overrides
    - app/admin/rates/create.php, show.php
    - Integration: lease create.php pre-fills rates from customer_equipment_rates → rate_cards → template default
    - Permission module: rates
    - Stop conditions: rate card CRUD, customer override, lease pre-fill uses rate card

S020 — Compliance Module (Phase 11 remnant)
  WHY: /compliance is in the sidebar with a badge slot. Critical for fleet operations.
  SCOPE:
    - No new tables — reads equipment_units columns: cvi_expiry, registration_expiry, mvi_expiry, insurance_expiry
    - api/v1/compliance/index.php — all units × 4 expiry types; filters: yard, status, window (7/14/30/60/90d)
    - api/v1/compliance/update.php — update one expiry field on a unit (POST, D19 optimistic lock)
    - app/admin/compliance/index.php — grid view: unit rows × 4 expiry columns, color-coded cells
    - Color coding: red=expired/today, orange=≤30d, yellow=≤60d, green=OK, grey=null
    - Export to CSV button
    - Sidebar badge count: units with ANY expiry ≤30 days
    - Permission module: compliance
    - Stop conditions: grid renders all units, color coding correct, update expiry date, CSV export, badge count

S021 — Reports Module (Phase 12)
  WHY: /reports in sidebar. Financial visibility and export capability.
  SCOPE:
    - api/v1/reports/{revenue,fleet,customer,compliance}.php — query-based reports with date range
    - app/admin/reports/index.php — 4-tab report center (Financial / Fleet / Customer / Compliance)
    - Each report: date range picker, chart (ApexCharts), summary table, Export CSV button
    - Financial: revenue by month, AR aging, top customers by revenue
    - Fleet: utilization rate, idle units, maintenance cost vs revenue per unit
    - Customer: lease frequency, payment behavior, lifetime value
    - Compliance: expiry timeline, units needing renewal by month
    - report_cache table used for 15-min cache
    - Permission module: reports
    - Stop conditions: all 4 report tabs render, date filter works, CSV export, cache hit path

S022 — Documents Module (Phase 13)
  WHY: /documents in sidebar. Equipment Documents tab already has a stub.
  SCOPE:
    - documents table: id, entity_type ENUM(customer,equipment_unit,lease,inspection,damage_claim), entity_id, name, file_path, mime_type, file_size, uploaded_by, deleted_at
    - api/v1/documents/{index,upload,delete}.php — polymorphic entity attachment
    - app/admin/documents/index.php — global document list with entity_type filter
    - Patch equipment/show.php Documents tab (currently "coming soon" stub)
    - Patch leases/show.php, customers/show.php with Documents tab
    - StorageClient upload (Trap 5 finfo_file MIME, Trap 7 no file_path in API)
    - Permission module: documents (visible to all logged-in users per nav config)
    - Stop conditions: upload to customer, upload to lease, download URL, delete, entity filter

After S022: S023 — Analytics, S024 — Customer Portal, S025+ — Accounting Module

═══════════════════════════════════════════════════════════════════
CRITICAL CARRY-FORWARD (all sessions)
═══════════════════════════════════════════════════════════════════

SEARCH FIX (SEARCH-FIX — applied to all index pages):
  All index APIs now use LIKE %term% (not FULLTEXT MATCH...AGAINST).
  All search inputs: x-model="filters.x" + @input.debounce.400ms="resetPage()"
  (NOT x-model.debounce.400ms + @input — that pattern fires before the value updates)

DB_SANITIZE_COLUMN (S016):
  db_sanitize_column() backtick-quotes every column name.
  Reason: `condition` is a MySQL reserved word.

SCHEMA TRAP: equipment_units has NO make/model columns.
  Always JOIN equipment_templates. Use et.brand, et.model, et.category (NOT et.unit_type).

FF_API.POST:
  - ALWAYS use base_url() — never bare relative strings.
  - ALWAYS resolves: check d.error in .then(), never use .catch() for business errors.
  - Sends JSON. For file uploads use raw fetch() + FormData + both CSRF headers.

CSRF TRAP: Raw fetch() MUST include both headers:
  'X-Requested-With': 'XMLHttpRequest'
  'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? ''

AUDIT_LOG SCHEMA: user_name, notes, entity_label (NOT 'description').
  action ENUM: 'create'/'update'/'delete'/'status_change'.

CSS: .form-control (NOT .form-input) | .table | .stat-grid | .detail-grid
     base_url('api/v1/...') — api_url() does NOT exist
     icon() function does NOT exist — use plain text
     Badges: badge-success/warning/danger/info/neutral
```

---
---

# AWS SETUP GUIDE

> Follow these steps in order. You are at: **Step 1 — AWS console just opened.**

---

## STEP 1 — Create the Lightsail Instance

1. Go to **lightsail.aws.amazon.com**
2. Click **"Create instance"**
3. Configure:
   - **Region:** `us-west-2` (Oregon) — closest to BC/Canada west coast
   - **Platform:** Linux/Unix
   - **Blueprint:** OS Only → **Ubuntu 22.04 LTS** — do NOT use LAMP blueprint
   - **Launch script:** Leave empty
   - **SSH key pair:** Create new → **download the .pem file and save it permanently** — cannot be re-downloaded
   - **Plan:** **$20/month** (4 GB RAM, 2 vCPU, 80 GB SSD) — do not go cheaper
   - **Instance name:** `fleetforge-prod`
4. Click **"Create instance"** — wait ~2 minutes until status shows "Running"

---

## STEP 2 — Attach a Static IP

1. Lightsail console → **Networking** tab (left sidebar)
2. Click **"Create static IP"**
3. Attach to: `fleetforge-prod`
4. Name: `fleetforge-static-ip`
5. Click **"Create and attach"**
6. **Write down this IP address** — needed for DNS and SSH

---

## STEP 3 — Configure Firewall

1. Click `fleetforge-prod` → **Networking** tab → Firewall section
2. Set rules exactly:
   - ✅ SSH — TCP — port 22 — **Restrict to your IP** (click "Restrict to IP address")
   - ✅ HTTP — TCP — port 80 — Any IP
   - ✅ HTTPS — TCP — port 443 — Any IP
   - ❌ Remove any other rules
3. Save

> **Why:** Port 22 open to the world gets brute-forced within hours.

---

## STEP 4 — Point Your Domain to the Static IP

**If your domain is at an external registrar (GoDaddy, Namecheap, etc.):**
- Add A record: `@` → your static IP
- Add A record: `www` → your static IP
- DNS propagation: 5–30 minutes

**If buying a new domain via Route 53:**
- AWS console → Route 53 → Register Domain
- After registration → Hosted Zones → your domain → Create Record → A record → static IP

> Test: `ping yourdomain.com` should return your static IP before proceeding to Step 12 (SSL).

---

## STEP 5 — SSH Into the Server

```bash
# On your local machine
chmod 400 ~/path/to/your-key.pem
ssh -i ~/path/to/your-key.pem ubuntu@YOUR_STATIC_IP
```

---

## STEP 6 — Install PHP 8.2 + Apache + MySQL

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
  php8.2 php8.2-mysql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-gd php8.2-intl \
  php8.2-opcache php8.2-bcmath \
  apache2 mysql-server git unzip curl

# Verify
php -v          # Must show 8.2.x
mysql --version # Must show 8.0.x
```

---

## STEP 7 — Configure PHP

```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

Set these values (Ctrl+W to search):
```ini
memory_limit = 256M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 60
date.timezone = America/Vancouver
```

Save: Ctrl+X → Y → Enter

---

## STEP 8 — Set Up MySQL

```bash
sudo mysql_secure_installation
# Set strong root password, answer Y to all prompts

sudo mysql -u root -p
```

```sql
CREATE DATABASE fleetforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleetforge_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON fleetforge.* TO 'fleetforge_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

> Use a 20+ character random password. Store it in a password manager.

---

## STEP 9 — Install Composer

```bash
cd ~
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version  # Must show 2.x
```

---

## STEP 10 — Create Project Directories + Permissions

```bash
sudo mkdir -p /var/www/fleetforge
sudo chown ubuntu:ubuntu /var/www/fleetforge

# Create all required subdirectories
mkdir -p /var/www/fleetforge/storage/uploads/{customers,equipment,leases,inspections,damage,contracts,branding}
mkdir -p /var/www/fleetforge/storage/generated/{pdfs,exports,qrcodes}
mkdir -p /var/www/fleetforge/logs
mkdir -p /var/www/fleetforge/cache

# Block PHP execution in storage
echo 'Options -ExecCGI
AddHandler cgi-script .php .php3 .phtml .pl .py
php_flag engine off' | sudo tee /var/www/fleetforge/storage/.htaccess

# Backup directory
sudo mkdir -p /var/backups/fleetforge
sudo chown www-data:www-data /var/backups/fleetforge
```

---

## STEP 11 — Configure Apache Virtual Host

```bash
sudo nano /etc/apache2/sites-available/fleetforge.conf
```

Paste (replace `yourdomain.com`):
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    ServerAlias www.yourdomain.com
    DocumentRoot /var/www/fleetforge/public

    <Directory /var/www/fleetforge>
        Require all denied
    </Directory>

    <Directory /var/www/fleetforge/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/fleetforge_error.log
    CustomLog ${APACHE_LOG_DIR}/fleetforge_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite fleetforge.conf
sudo a2dissite 000-default.conf
sudo a2enmod rewrite headers
sudo systemctl restart apache2
sudo systemctl status apache2   # Must show: active (running)
```

---

## STEP 12 — SSL Certificate

> Domain must be resolving to the server IP before this step.

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com
# Enter email, agree (A), redirect (2)
```

Verify:
```bash
curl -I https://yourdomain.com   # Must return HTTP/2 200
```

---

## STEP 13 — Deploy Project Code

```bash
cd /var/www/fleetforge

# Via git:
git clone https://github.com/YOUR_REPO/fleetforge.git .

# Install mPDF (only Composer dependency)
composer install --no-dev --optimize-autoloader

# Set permissions
sudo chown -R www-data:www-data /var/www/fleetforge
sudo chmod -R 755 /var/www/fleetforge
sudo chmod -R 775 /var/www/fleetforge/storage
sudo chmod -R 775 /var/www/fleetforge/logs
sudo chmod -R 775 /var/www/fleetforge/cache
```

---

## STEP 14 — Configure .env

```bash
cp /var/www/fleetforge/.env.example /var/www/fleetforge/.env
nano /var/www/fleetforge/.env
```

```env
APP_ENV=production
APP_URL=https://yourdomain.com/fleetforge
APP_DEBUG=false
APP_TIMEZONE=America/Vancouver
FF_ASSET_VERSION=1.0.0
SESSION_LIFETIME_HOURS=8
MAINTENANCE_BYPASS_IPS=YOUR_OFFICE_IP_HERE

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fleetforge
DB_USERNAME=fleetforge_user
DB_PASSWORD=STRONG_PASSWORD_FROM_STEP_8

GPS_SAMSARA_API_KEY=
GPS_SAMSARA_ORG_ID=

AI_ANTHROPIC_API_KEY=
AI_ENABLED=false
AI_DAILY_TOKEN_LIMIT=500000

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your@email.com
SMTP_PASSWORD=your_app_password
SMTP_FROM_EMAIL=noreply@yourdomain.com
SMTP_FROM_NAME=Mainland Truck & Trailer
```

Lock down .env permissions:
```bash
chmod 600 /var/www/fleetforge/.env
```

---

## STEP 15 — Run Database Schema (Session 2 creates these files)

```bash
cd /var/www/fleetforge

# Run all schema files in sorted order
for f in $(ls database/schema/*.sql | sort); do
    echo "Running: $f"
    mysql -u fleetforge_user -p'PASSWORD' fleetforge < "$f"
done

# Run seeds
for f in $(ls database/seeds/*.sql | sort); do
    echo "Running: $f"
    mysql -u fleetforge_user -p'PASSWORD' fleetforge < "$f"
done
```

---

## STEP 16 — Install Crontab

```bash
sudo -u www-data crontab -e
```

Paste all entries:
```bash
# FleetForge cron jobs
0  2  * * *  php /var/www/fleetforge/cron/health_scores.php >> /var/www/fleetforge/logs/cron.log 2>&1
30 2  * * *  php /var/www/fleetforge/cron/risk_scores.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  3  * * *  php /var/www/fleetforge/cron/ai_anomaly_detection.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  5  * * *  php /var/www/fleetforge/cron/ai_fleet_brief.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  6  * * *  php /var/www/fleetforge/cron/compliance_alerts.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  6  1 * *  php /var/www/fleetforge/cron/invoice_generate_monthly.php >> /var/www/fleetforge/logs/cron.log 2>&1
15 6  * * *  php /var/www/fleetforge/cron/invoice_overdue.php >> /var/www/fleetforge/logs/cron.log 2>&1
30 6  * * *  php /var/www/fleetforge/cron/late_fee_apply.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  7  * * *  php /var/www/fleetforge/cron/gps_mileage_sync.php >> /var/www/fleetforge/logs/gps.log 2>&1
0  8  * * *  php /var/www/fleetforge/cron/notification_digest.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  *  * * *  php /var/www/fleetforge/cron/cache_cleanup.php >> /var/www/fleetforge/logs/cron.log 2>&1
0  4  1 * *  php /var/www/fleetforge/cron/archive_old_data.php >> /var/www/fleetforge/logs/cron.log 2>&1
# Database backups
0 1  * * *  mysqldump -u fleetforge_user -p'PASSWORD' fleetforge | gzip > /var/backups/fleetforge/db_$(date +\%Y\%m\%d).sql.gz 2>> /var/www/fleetforge/logs/cron.log
0 2  * * 0  tar -czf /var/backups/fleetforge/files_$(date +\%Y\%m\%d).tar.gz /var/www/fleetforge/storage/uploads/ 2>> /var/www/fleetforge/logs/cron.log
0 3  * * *  find /var/backups/fleetforge/ -name "db_*.sql.gz" -mtime +30 -delete
```

---

## STEP 17 — Enable Lightsail Automated Snapshots

1. Lightsail console → `fleetforge-prod` → **Snapshots** tab
2. Automatic snapshots: **Turn ON**
3. Snapshot time: 3:00 AM
4. Retain: 7 snapshots
5. Save

---

## STEP 18 — Final Verification

```bash
# PHP version
php -r "echo PHP_VERSION . PHP_EOL;"              # Must show 8.2.x

# MySQL connection
mysql -u fleetforge_user -p'PASSWORD' -e "SELECT VERSION();" fleetforge

# HTTPS working
curl -I https://yourdomain.com                     # Must return HTTP/2 200

# CRITICAL security check — .env must NOT be accessible from web
curl https://yourdomain.com/.env                   # Must return 403 or 404
curl https://yourdomain.com/../.env                # Must return 403 or 404
# If EITHER returns file contents → STOP. Fix .htaccess before anything else.

# Storage writable
ls -la /var/www/fleetforge/storage/uploads/

# Cron installed
sudo -u www-data crontab -l
```

---

## AWS SETUP CHECKLIST

| Step | Task | Status |
|------|------|--------|
| 1 | Lightsail instance created (Ubuntu 22.04, $20/mo plan) | ⬜ |
| 2 | Static IP created and attached | ⬜ |
| 3 | Firewall: SSH restricted to your IP, 80+443 open | ⬜ |
| 4 | Domain A record pointing to static IP | ⬜ |
| 5 | SSH access verified | ⬜ |
| 6 | PHP 8.2 + all extensions installed | ⬜ |
| 7 | PHP.ini configured (256M, 25M upload, timezone) | ⬜ |
| 8 | MySQL 8.0, database + user created | ⬜ |
| 9 | Composer 2.x installed | ⬜ |
| 10 | Project directories + permissions created | ⬜ |
| 11 | Apache virtual host configured + active | ⬜ |
| 12 | SSL certificate issued via Certbot | ⬜ |
| 13 | Project code deployed | ⬜ |
| 14 | .env configured + chmod 600 | ⬜ |
| 15 | Database schema run (Session 2 task) | ⬜ |
| 16 | Crontab installed | ⬜ |
| 17 | Lightsail automated snapshots enabled (7 daily) | ⬜ |
| 18 | Full verification passed (.env inaccessible from web ✓) | ⬜ |

---
---

# BUILD PLAN — v4.0 ATOMIC SESSIONS

---

## THE SENIOR ENGINEER MINDSET

```
RULE 1: ONE VERIFIABLE THING PER SESSION
  A session is complete when one specific user-visible behaviour
  works correctly and all its failure modes are handled.
  Not "the customers module." Not "the API." 
  One thing. Fully working. Both sides. All edge cases.

RULE 2: CONTRACT BEFORE CODE
  Every session that builds an API endpoint starts with a written 
  contract. Claude Code writes the contract. You approve it. 
  Then and only then does code get written.
  Contract = exact request shape, exact response shape, 
             exact validation rules, exact error codes.

RULE 3: VERIFY THE FOUNDATION BEFORE BUILDING ON IT
  Before Session N+1 starts, Session N's output is re-tested.
  If it breaks, fix it before building anything on top of it.
  This is non-negotiable.

RULE 4: SAD PATH IS MORE IMPORTANT THAN HAPPY PATH
  The happy path usually works. It is the edge cases, the 
  concurrent requests, the missing data, the expired sessions,
  the 11MB files, the duplicate submissions — these are where
  production bugs live.

RULE 5: BUSINESS LOGIC LIVES IN EXACTLY ONE PLACE
  Validation: PHP only. JS validation is UX, not security.
  Billing math: lib/Billing/ pure classes only.
  State machines: API layer only. JS never transitions state.
  Permissions: PHP only. JS hiding is UX, not access control.

RULE 6: NO SESSION ENDS WITH "MOSTLY WORKING"
  If a test fails, fix it in the same session.
  If you cannot fix it, the session is not complete.
  Update PROGRESS with the exact failing test as a known issue.

RULE 7: THE CONTRACT IS THE SHARED TRUTH
  Frontend and backend implement against the same contract.
  When they disagree, the contract is updated, then both sides.
  Never let either side define reality unilaterally.
```

---

## SESSION OPENING TEMPLATE — MANDATORY EVERY SESSION

```
Read FLEETFORGE_SPEC_FINAL.md and FLEETFORGE_PROGRESS.md 
before writing a single line of code.

Verify: [state the specific thing from the last session that 
must still be working before we proceed]

Today's session: [single sentence describing the one thing 
we are building]

Step 1: Define the contract for this session (if it involves 
an API). Show me: exact endpoint, method, request fields, 
validation rules, success response shape, every error case 
with its HTTP status and error_code. Wait for my approval.

Step 2: After approval, build both sides (backend + frontend) 
against the approved contract.

Step 3: Run every test in the session's stop-condition list. 
Show me each result. Fix all failures before ending.

Step 4: Update FLEETFORGE_PROGRESS.md. Mark this session 
complete. Write the next session's opening instruction.
```

---

## SESSION MAP

Total: ~150 sessions for the complete platform including accounting.
Core platform (Sessions 1–110): operational FleetForge.
Accounting module (Sessions 111–150): added after core is live.

Each session is designed to take 30–90 minutes.
Most are under 60 minutes.

---

# PHASE 1: INFRASTRUCTURE
*No features. No business logic. Just a working, secure server.*

---

### S001 — Lightsail Instance + Domain + HTTPS
**One thing:** Server exists. Domain resolves. HTTPS works.
**Verify before starting:** Nothing (first session)
**STATUS: ❌ DEFERRED — Building locally first (D8). All tasks done at production launch.**

| Task | Status |
|------|--------|
| Lightsail $20/mo instance created (Ubuntu 22.04) | ❌ |
| Static IP attached | ❌ |
| SSH firewall rule restricted to your IP only | ❌ |
| HTTP (80) + HTTPS (443) open to all | ❌ |
| Domain A record → static IP | ❌ |
| PHP 8.2 + apache2 + mysql-server installed | ❌ |
| All required PHP extensions installed | ❌ |
| PHP.ini configured (256M memory, 25M upload, UTC timezone) | ❌ |
| Apache virtual host: DocumentRoot → /var/www/fleetforge/public | ❌ |
| mod_rewrite + mod_headers enabled | ❌ |
| SSL certificate via Certbot | ❌ |
| Lightsail automated snapshots: ON, 7 daily | ❌ |

**Stop conditions — all must pass:**
```bash
curl -I https://yourdomain.com              # HTTP/2 200
php -r "echo PHP_VERSION;"                 # 8.2.x
mysql --version                            # 8.0.x
```

---

### S002 — MySQL: Database + User + Permissions
**One thing:** Database exists. App user has correct privileges. Nothing more.
**Verify before starting:** `curl -I https://yourdomain.com` → 200
**STATUS: ❌ DEFERRED — Production server task. Locally using Homebrew MySQL (root / fleetforge123).**

| Task | Status |
|------|--------|
| Database `fleetforge` created (utf8mb4, unicode_ci) | ❌ |
| User `fleetforge_user` created with strong password | ❌ |
| GRANT ALL on `fleetforge.*` to `fleetforge_user` | ❌ |
| MySQL root password secured | ❌ |
| `mysql_secure_installation` completed | ❌ |

**Stop conditions:**
```bash
mysql -u fleetforge_user -p'PASSWORD' -e "SELECT USER(), DATABASE();" fleetforge
# Returns: fleetforge_user@localhost, fleetforge

mysql -u fleetforge_user -p'PASSWORD' -e "SHOW GRANTS;" fleetforge
# Shows: GRANT ALL PRIVILEGES ON fleetforge.* TO fleetforge_user
```

---

### S003 — Project Structure + Composer + Git
**One thing:** Code structure exists. Dependencies installed. GitHub connected.
**Verify before starting:** S002 DB connection works

| Task | Status |
|------|--------|
| GitHub private repo created | ⬜ |
| Full folder structure per spec Section 3 | ✅ |
| `.env.example` — every key documented with description | ✅ |
| `.env` — filled in with real values | ✅ |
| `.gitignore` — .env, vendor, storage, logs, cache, *.sql with passwords | ✅ |
| `composer.json` — mpdf + aws-sdk-php (D24: AWS SDK intentional, spec note stale) | ✅ |
| `composer install --no-dev` | ✅ |
| `storage/.htaccess` — denies PHP execution, denies direct access | ✅ |
| All storage subdirectories created | ✅ |
| Correct file permissions set (www-data:www-data, storage 775) | ❌ |
| Initial commit pushed to GitHub | ✅ |

**Stop conditions:**
```bash
cat .gitignore | grep "\.env"              # .env present in gitignore
ls vendor/mpdf/                            # mPDF installed
git log --oneline | head -1               # initial commit exists
ls -la storage/uploads/                   # directory exists with correct perms
curl https://yourdomain.com/.env          # 403 or 404 — NEVER file contents
curl https://yourdomain.com/../.env       # 403 or 404
```

---

### S004 — Apache Security: .htaccess + Security Headers
**One thing:** All HTTP security headers correct. All dangerous paths blocked.
**Why its own session:** Security hardening is never "we'll do it later." Verify it now before a single PHP file is written.

| Task | Status |
|------|--------|
| `public/.htaccess` — route all requests to index.php | ✅ |
| Block direct access to .env, .git, composer files | ✅ |
| Block directory listing | ✅ |
| Content-Security-Policy header | ✅ |
| X-Content-Type-Options: nosniff | ✅ |
| X-Frame-Options: DENY | ✅ |
| X-XSS-Protection: 1; mode=block | ✅ |
| Referrer-Policy: strict-origin-when-cross-origin | ✅ |
| Strict-Transport-Security (HTTPS only) | ✅ |
| Permissions-Policy: camera=(), microphone=(), geolocation=(self) | ✅ |

**Stop conditions:**
```bash
# All headers present
curl -I https://yourdomain.com | grep -E "X-Frame|X-Content|Strict-Transport|Content-Security"

# All must return 403 or 404 — never file contents
curl https://yourdomain.com/.env
curl https://yourdomain.com/.git/config
curl https://yourdomain.com/composer.json
curl https://yourdomain.com/storage/
curl https://yourdomain.com/includes/db.php
```

---

### S005 — config/app.php + Environment Loading
**One thing:** All FF_ constants available. .env parsed correctly. Settings infrastructure in place.
**Verify before starting:** S004 security headers all pass

| Task | Status |
|------|--------|
| `config/app.php` — custom .env parser (no Composer dependency) | ✅ |
| All FF_ constants defined and accessible | ✅ |
| FF_ASSET_VERSION constant | ✅ |
| FF_ENV (production/development) | ✅ |
| FF_DEBUG (false in production) | ✅ |
| `config/permissions.php` — 5 roles × 14 modules matrix | ✅ |
| `config/navigation.php` — sidebar nav, single source of truth | ✅ |
| `cron/README.md` — all crontab entries documented | ⬜ |
| Crontab installed on server | ❌ |

**Stop conditions:**
```php
// Test script (delete after):
require 'config/app.php';
assert(defined('FF_ASSET_VERSION'));
assert(defined('FF_ENV'));
assert(FF_ENV === 'production');
assert(FF_DEBUG === false);
echo "Config OK\n";
```

---

# PHASE 2: PHP FOUNDATION
*The engine. Every module depends on this. Test exhaustively.*

---

### S006 — DB Helper: Connection + Basic Queries
**One thing:** PDO connects. Basic CRUD helpers work. UTC enforced.
**Why split from other helpers:** DB connection is the most foundational dependency. If it's wrong, everything is wrong.

| Task | Status |
|------|--------|
| `includes/db.php` — PDO singleton | ✅ |
| Connection: charset=utf8mb4, emulate_prepares=false | ✅ |
| Connection: `SET time_zone = '+00:00'` on connect | ✅ |
| Connection: `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` | ✅ |
| `db_select(sql, params)` → array of rows | ✅ |
| `db_row(sql, params)` → single row or null | ✅ |
| `db_insert(table, data)` → last insert ID | ✅ |
| `db_update(table, data, where, whereParams)` → affected rows | ✅ |
| `db_execute(sql, params)` → affected rows | ✅ |
| `db_count(sql, params)` → int | ✅ |
| `db_exists(table, condition, params)` → bool | ✅ |
| All queries: parameterized — NEVER string interpolation | ✅ |

**Stop conditions:**
```php
// Must ALL pass:
$id = db_insert('yards', ['name'=>'Test Yard','slug'=>'test-yard-'.time(),'is_active'=>1]);
assert(is_int($id) && $id > 0, 'insert must return int ID');

$row = db_row("SELECT * FROM yards WHERE id = ?", [$id]);
assert($row['name'] === 'Test Yard', 'db_row must return correct row');
assert($row !== null, 'db_row must not return null for existing ID');

$null = db_row("SELECT * FROM yards WHERE id = 999999", []);
assert($null === null, 'db_row must return null for missing row');

$rows = db_select("SELECT * FROM yards WHERE id = ?", [$id]);
assert(count($rows) === 1, 'db_select must return array');
assert($rows[0]['id'] === $id, 'db_select must return correct data');

$affected = db_update('yards', ['name'=>'Updated'], 'id = ?', [$id]);
assert($affected === 1, 'db_update must return affected row count');

$exists = db_exists('yards', 'id = ?', [$id]);
assert($exists === true, 'db_exists must return true for existing row');

$notExists = db_exists('yards', 'id = ?', [999999]);
assert($notExists === false, 'db_exists must return false for missing row');

$count = db_count("SELECT COUNT(*) FROM yards WHERE id = ?", [$id]);
assert(is_int($count), 'db_count must return int');

// UTC timezone enforced
$tzRow = db_row("SELECT @@session.time_zone as tz", []);
assert($tzRow['tz'] === '+00:00', 'UTC timezone must be enforced on connection');

// Cleanup
db_execute("DELETE FROM yards WHERE id = ?", [$id]);
echo "ALL DB BASIC TESTS PASSED\n";
```

---

### S007 — DB Helper: Transactions + Rollback
**One thing:** Transactions work. Rollback works. Partial writes are impossible.
**Why its own session:** Transaction integrity is the #1 protection against corrupt financial data. Verify this in complete isolation before billing code touches it.

| Task | Status |
|------|--------|
| `db_transaction(callable)` — wraps in BEGIN/COMMIT | ✅ |
| Auto-rollback on any exception thrown inside callable | ✅ |
| Re-throws exception after rollback | ✅ |
| Returns callable return value on success | ✅ |
| Nested transaction handling (savepoints) | ✅ |

**Stop conditions:**
```php
// HAPPY PATH: both inserts commit
$result = db_transaction(function() {
    $id1 = db_insert('yards', ['name'=>'TX Test 1','slug'=>'tx-1-'.time(),'is_active'=>1]);
    $id2 = db_insert('yards', ['name'=>'TX Test 2','slug'=>'tx-2-'.time(),'is_active'=>1]);
    return [$id1, $id2];
});
assert(count($result) === 2, 'transaction must return callable result');
$check1 = db_row("SELECT id FROM yards WHERE id = ?", [$result[0]]);
$check2 = db_row("SELECT id FROM yards WHERE id = ?", [$result[1]]);
assert($check1 !== null, 'first insert must have committed');
assert($check2 !== null, 'second insert must have committed');
db_execute("DELETE FROM yards WHERE id IN (?,?)", $result);

// CRITICAL: rollback test — NOTHING must persist
$insertedId = null;
try {
    db_transaction(function() use (&$insertedId) {
        $insertedId = db_insert('yards', ['name'=>'Should Rollback','slug'=>'rollback-'.time(),'is_active'=>1]);
        throw new RuntimeException("Forced rollback");
    });
} catch (RuntimeException $e) {
    assert($e->getMessage() === "Forced rollback", 'exception must be re-thrown');
}
assert($insertedId !== null, 'ID was generated');
$rolled = db_row("SELECT id FROM yards WHERE id = ?", [$insertedId]);
assert($rolled === null, 'CRITICAL: rolled-back insert MUST NOT exist in DB');

echo "ALL TRANSACTION TESTS PASSED\n";
```

---

### S008 — Input Sanitization + Output Escaping Functions
**One thing:** Every input cleaning function is correct. Output escaping is bulletproof.
**Why its own session:** These are the #1 defence against XSS and injection. Get them right once, use everywhere.

| Task | Status |
|------|--------|
| `includes/functions.php` (partial — sanitization only) | ✅ |
| `e($value)` — htmlspecialchars, ENT_QUOTES, UTF-8, null-safe | ✅ |
| `clean_string($val, $maxLen)` — trim, strip_tags, max length | ✅ |
| `clean_decimal($val)` — returns float or null, rejects formatted | ✅ |
| `clean_date($val)` — validates Y-m-d, rejects invalid dates | ✅ |
| `clean_int($val)` — returns int or null, rejects floats | ✅ |
| `clean_email($val)` — filter_var FILTER_VALIDATE_EMAIL | ✅ |
| `clean_url($val)` — filter_var FILTER_VALIDATE_URL | ⬜ |

**Stop conditions:**
```php
// e() — output escaping
assert(e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
assert(e("O'Brien") === "O&#039;Brien");
assert(e(null) === '');
assert(e(0) === '0');
assert(e('') === '');

// clean_string()
assert(clean_string('  hello  ') === 'hello');
assert(clean_string('<b>bold</b>') === 'bold');
assert(clean_string(str_repeat('a', 300), 255) === str_repeat('a', 255));
assert(clean_string(null) === null);

// clean_decimal() — strict
assert(clean_decimal('1234.56') === 1234.56);
assert(clean_decimal('$1,234.56') === null);  // formatted → null
assert(clean_decimal('1234') === 1234.0);
assert(clean_decimal('abc') === null);
assert(clean_decimal('') === null);
assert(clean_decimal(null) === null);
assert(clean_decimal('-100.50') === -100.50);  // negatives valid

// clean_date()
assert(clean_date('2025-03-21') === '2025-03-21');
assert(clean_date('2025-13-01') === null);     // month 13 invalid
assert(clean_date('2025-02-29') === null);     // 2025 not leap year
assert(clean_date('2024-02-29') === '2024-02-29'); // 2024 IS leap year
assert(clean_date('21/03/2025') === null);     // wrong format
assert(clean_date('') === null);

// clean_int()
assert(clean_int('42') === 42);
assert(clean_int('42.5') === null);            // floats rejected
assert(clean_int('-5') === -5);
assert(clean_int('abc') === null);
assert(clean_int('') === null);
assert(clean_int(0) === 0);

// clean_email()
assert(clean_email('user@example.com') !== null);
assert(clean_email('notanemail') === null);
assert(clean_email('') === null);

echo "ALL SANITIZATION TESTS PASSED\n";
```

---

### S009 — Format + Generate Functions
**One thing:** All display formatting functions correct. All ID generators produce correct format with no confusable characters.

| Task | Status |
|------|--------|
| `format_currency(amount, symbol)` | ✅ |
| `format_date(value)` — DATE → display | ✅ |
| `format_datetime(value)` — UTC DATETIME → company timezone | ✅ |
| `format_mileage(distance, unit)` | ⬜ |
| `settings_get(key, default)` | ✅ |
| `generate_contract_number()` — CN-XXXXXX-YYYY | ⬜ |
| `generate_invoice_number()` — INV-YYYY-NNNNN | ⬜ |
| `generate_payment_number()` — PAY-YYYY-NNNNN | ⬜ |
| `generate_wo_number()` — WO-YYYY-NNNNN | ⬜ |
| `generate_claim_number()` — DC-YYYY-NNNNN | ⬜ |
| ID charset: no 0, O, 1, I (confusable chars excluded) | ✅ |

**Stop conditions:**
```php
// format_currency
assert(format_currency(null) === '—');
assert(format_currency(0) === '$0.00');
assert(format_currency(1234567.89) === '$1,234,567.89');
assert(format_currency(1234567.89, 'US$') === 'US$1,234,567.89');
assert(format_currency(-500.00) === '-$500.00');

// format_date
assert(format_date(null) === '—');
assert(format_date('2025-03-21') === 'Mar 21, 2025');

// format_datetime (tests UTC → timezone conversion)
// Store UTC, display in America/Vancouver (UTC-7 in March)
assert(format_datetime('2025-03-21 20:00:00') === 'Mar 21, 2025 1:00 PM');

// format_mileage
assert(format_mileage(84200, 'miles') === '84,200 mi');
assert(format_mileage(84200, 'km') === '84,200 km');
assert(format_mileage(null, 'miles') === '—');

// ID generators — no confusable chars in random portion
for ($i = 0; $i < 100; $i++) {
    $cn = generate_contract_number();
    assert(preg_match('/^CN-[A-HJ-NP-Z2-9]{6}-\d{4}$/', $cn), "bad contract number: $cn");
    assert(strpos($cn, '0') === false, "contains 0: $cn");
    assert(strpos($cn, 'O') === false, "contains O: $cn");
    assert(strpos($cn, '1') === false, "contains 1: $cn");
    assert(strpos($cn, 'I') === false, "contains I: $cn");
}

// ID uniqueness — generate 1000, check for collisions
$ids = [];
for ($i = 0; $i < 1000; $i++) $ids[] = generate_contract_number();
assert(count(array_unique($ids)) === 1000, 'COLLISION: duplicate contract numbers generated');

echo "ALL FORMAT + GENERATE TESTS PASSED\n";
```

---

### S010 — CSRF Protection
**One thing:** CSRF tokens generate, store, and validate correctly. Replay attacks blocked.
**Why its own session:** CSRF is a cross-cutting security concern. One missed verification = account takeover. Test every edge case.

| Task | Status |
|------|--------|
| `generate_csrf_token()` — 64-char hex, stored in session | ✅ |
| `verify_csrf_token(token)` — constant-time comparison | ✅ |
| `verify_csrf_token()` returns false for wrong token | ✅ |
| `verify_csrf_token()` returns false for empty/null | ✅ |
| `verify_csrf_token()` returns false if no session | ✅ |
| API bootstrap: POST requests verify CSRF header | ✅ |
| HTML forms: hidden CSRF field auto-included in header.php | ✅ |
| `API.post()` in app.js: auto-sends CSRF token in X-CSRF-Token header | ✅ |

**Stop conditions:**
```php
session_start();

$token = generate_csrf_token();
assert(strlen($token) === 64, 'token must be 64 chars');
assert(ctype_xdigit($token), 'token must be hex');

// Valid token
assert(verify_csrf_token($token) === true, 'valid token must pass');

// Wrong token
assert(verify_csrf_token('wrongtoken') === false, 'wrong token must fail');
assert(verify_csrf_token('') === false, 'empty token must fail');
assert(verify_csrf_token(null) === false, 'null token must fail');

// Timing attack: verify uses hash_equals not ==
// (inspect code — must use hash_equals)

// Each call generates NEW token (tokens don't reuse)
$token2 = generate_csrf_token();
assert($token !== $token2, 'each call must generate unique token');

echo "ALL CSRF TESTS PASSED\n";
```
```bash
# API test (after api/bootstrap.php exists)
curl -X POST /api/v1/customers/create.php \
  -d '{"company_name":"Test"}' \
  -H "Content-Type: application/json"
# Must return 403 — no CSRF token
```

---

### S011 — Permission System: can() + require_permission()
**One thing:** Permission checks work correctly for all 5 roles across all 14 modules.
**Why its own session:** Permissions are the last line of defence if a URL is guessed. Wrong permission = data breach.

| Task | Status |
|------|--------|
| `includes/auth.php` — permission functions only (no session/login yet) | ✅ |
| `can(module, action)` — reads from $_SESSION['ff_user']['permissions'] | ✅ |
| `can()` — super_admin always returns true | ✅ |
| `can()` — returns false if no session | ✅ |
| `is_super_admin()` — returns bool | ✅ |
| `current_user()` — returns session array or null | ✅ |
| `require_permission(module, action)` — calls http_response_code(403) + exit | ✅ |
| `require_auth_api()` — returns 401 JSON if no valid session | ✅ |
| `require_permission()` in API context — returns 403 JSON | ✅ |

**Stop conditions:**
```php
// Mock session for testing
$_SESSION['ff_user'] = [
    'id' => 1,
    'role_slug' => 'accountant',
    'permissions' => [
        'invoices' => ['view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1],
        'customers' => ['view'=>1,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0],
        'users'     => ['view'=>0,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0],
    ]
];

// Accountant can view + create invoices
assert(can('invoices', 'view') === true);
assert(can('invoices', 'create') === true);

// Accountant cannot delete invoices or manage users
assert(can('invoices', 'delete') === false);
assert(can('users', 'view') === false);
assert(can('customers', 'create') === false);

// Super admin can do anything
$_SESSION['ff_user']['role_slug'] = 'super_admin';
assert(can('users', 'delete') === true);
assert(can('nonexistent_module', 'view') === true);

// No session
unset($_SESSION['ff_user']);
assert(can('invoices', 'view') === false);
assert(current_user() === null);
assert(is_super_admin() === false);

echo "ALL PERMISSION TESTS PASSED\n";
```

---

### S012 — Session Management + require_auth()
**One thing:** Sessions created correctly. Inactivity timeout works. require_auth() redirects correctly.

| Task | Status |
|------|--------|
| Session configuration: HttpOnly, SameSite=Lax, Secure | ✅ |
| Session lifetime: 8 hours inactivity (from settings) | ✅ |
| `require_auth()` — redirect to login if no session | ✅ |
| `require_auth()` — redirect to login if session expired | ✅ |
| `require_auth()` — stores requested URL for post-login redirect | ✅ |
| Session regenerated on login (prevents session fixation) | ✅ |
| Session destroyed completely on logout | ✅ |

**Stop conditions:**
```php
// Session config
$cookieParams = session_get_cookie_params();
assert($cookieParams['httponly'] === true, 'HttpOnly required');
assert($cookieParams['samesite'] === 'Lax', 'SameSite=Lax required');
assert($cookieParams['secure'] === true, 'Secure required (HTTPS)');

// After login, session should have:
// $_SESSION['ff_user']['id']
// $_SESSION['ff_user']['login_at'] — for inactivity check
// $_SESSION['ff_last_activity'] — updated on every request

// Inactivity test (mock $_SESSION['ff_last_activity'] to 9 hours ago):
$_SESSION['ff_last_activity'] = time() - (9 * 3600);
// require_auth() should redirect (test by checking headers sent)
```
```bash
# Browser test:
# 1. Login
# 2. Note session cookie attributes in DevTools → Application → Cookies
#    Must show: HttpOnly=✓, Secure=✓, SameSite=Lax
# 3. Visit protected page, note URL
# 4. Delete session cookie in DevTools
# 5. Refresh → redirect to login
# 6. Login → redirect back to original URL
```

---

### S013 — Login Page + Brute Force Protection
**One thing:** Login form works. Brute force is blocked. No username enumeration.
**Contract: POST /auth/login.php**
```
Request: { email, password, csrf_token, remember_me? }
Success: redirect to dashboard (or redirect_after_login)
Failures:
  missing CSRF              → 403
  invalid credentials       → 200 with error (never specify which field)
  account locked            → 200 with locked message + unlock time
  account inactive/invited  → 200 with appropriate message
```

| Task | Status |
|------|--------|
| `app/auth/login.php` — form page + POST handler | ✅ |
| CSRF token verified on POST | ✅ |
| email + password from POST via clean_email() + clean_string() | ✅ |
| `password_verify()` with bcrypt | ✅ |
| login_attempts counter incremented on failure | ✅ |
| After 5 failures in 60 min → `locked_until` set to +15 min | ✅ |
| Locked account check runs BEFORE password check | ✅ |
| Generic error message always (never "wrong email" vs "wrong password") | ✅ |
| Successful login: session_regenerate_id(true) | ✅ |
| Successful login: $_SESSION['ff_user'] populated with permissions | ✅ |
| Successful login: audit_log entry (action=login) | ✅ |
| Failed login: audit_log entry (action=login, notes=failed) | ✅ |
| "Remember me": separate 30-day secure cookie | ✅ |

**Stop conditions:**
```
HAPPY PATH:
  POST valid email + password → session created → redirect to dashboard

ENUMERATION CHECK (critical):
  POST valid email + wrong password → "Invalid email or password"
  POST invalid email + anything → EXACT same message, same timing
  (timing attack: both paths must take same time — no early exit on email check)

BRUTE FORCE:
  POST wrong credentials 4× → still "Invalid email or password"
  POST wrong credentials 5× → "Account locked for 15 minutes"
  POST correct credentials while locked → still "locked" (lock checked first)
  Wait 15 min (mock locked_until to past) → login works again

CSRF:
  POST without CSRF token → 403
  POST with wrong CSRF token → 403

AUDIT LOG:
  Successful login: audit_log has action=login, user_id set, ip_address set
  Failed login: audit_log has action=login, notes contains "failed attempt N"
  Locked: audit_log has action=login, notes contains "account locked"

SESSION SECURITY:
  Session ID BEFORE login !== session ID AFTER login (regenerated)
  Session cookie: HttpOnly, Secure, SameSite=Lax
```

---

### S014 — Logout
**One thing:** Logout destroys everything. Browser back button cannot return to authenticated state.
*(Small but must be its own session — logout bugs are security bugs)*

| Task | Status |
|------|--------|
| `app/auth/logout.php` | ✅ |
| Unsets all session variables | ✅ |
| Calls session_destroy() | ✅ |
| Deletes session cookie from browser | ✅ |
| Deletes "remember me" cookie if present | ✅ |
| Audit log entry: action=logout | ⬜ |
| Redirects to login page | ✅ |

**Stop conditions:**
```
  Login → visit dashboard → click logout → redirected to login
  Browser back button → login page (not dashboard)
  Copy session cookie value before logout
  After logout, try to use that cookie value manually → redirected to login
  Audit log: logout entry present with user_id + timestamp
```

---

### S015 — Password Reset Flow
**One thing:** Full password reset works. Tokens are secure and single-use.

| Task | Status |
|------|--------|
| `app/auth/forgot_password.php` | ✅ |
| Token: bin2hex(random_bytes(32)) — 64 hex chars | ✅ |
| Token stored HASHED in DB (never plaintext) | ✅ |
| Expiry: 1 hour from generation | ✅ |
| Anti-enumeration: same response whether email exists or not | ✅ |
| `app/auth/reset_password.php` | ✅ |
| Token lookup: hash matches, not expired | ✅ |
| Token single-use: cleared immediately on use | ✅ |
| Password min 10 chars | ✅ |
| bcrypt cost 12 | ✅ |
| Audit log: password_reset action | ⬜ |

**Stop conditions:**
```
  POST valid email → "If registered, email sent" (check logs for email)
  POST invalid email → EXACT same message (anti-enumeration)
  GET reset link → new password form shown
  POST password < 10 chars → validation error
  POST valid password → success, redirected to login
  POST on same link again → "Invalid or expired link" (single-use)
  Mock token to expired (>1 hour) → "Link expired"
  DB: password_reset_token stored as HASH not plaintext
```

---

### S016 — Inspections Module (API + Admin UI + Photo Upload)
**One thing:** Full inspection lifecycle — create, conduct (section-by-section), photo upload, close out. Linked to equipment units AND leases (pre/post). Damage claim creatable from inspection.

**SCHEMA ADDITIONS (run before any code — ALTER TABLE migrations):**
```sql
-- inspections table: add inspection_number + trailer-specific fields
ALTER TABLE inspections
  ADD COLUMN inspection_number VARCHAR(50) NULL UNIQUE AFTER id,
  ADD COLUMN reefer_hours INT UNSIGNED NULL AFTER mileage_at_inspection,
  ADD COLUMN fuel_level ENUM('empty','quarter','half','three_quarter','full') NULL AFTER reefer_hours,
  ADD COLUMN cvi_expiry DATE NULL AFTER fuel_level,
  ADD COLUMN is_clean TINYINT(1) NULL AFTER cvi_expiry;

-- inspection_sections table: add section_data JSON for structured tire/checklist data
ALTER TABLE inspection_sections
  ADD COLUMN section_data JSON NULL AFTER notes;
```

**INSPECTION FORM DESIGN (based on physical inspection sheet — 2026-04-03):**

The physical form has two identical panels side-by-side (one for PRE-lease OUT, one for POST-lease IN). Each panel contains:

**Panel: Header fields**
- Exterior diagram views: Left Side, Right Side, Front Wall, Rear Door, Roof, Floor (condition boxes)
- Interior diagram (container interior view for dry vans, reefers, etc.)
- Reefer Hours (numeric field)
- Fuel Level (empty/quarter/half/three_quarter/full)
- CVI Expiry (date field)
- Clean / Dirty (checkbox)

**Panel: Tire table** — per axle-position:
```
Positions (left side):  L0.1, LI.1, L0.2, LI.2, L0.3, LI.3, L0.4, LI.4, L0.5, LI.5
Positions (right side): R0.1, RI.1, R0.2, RI.2, R0.3, RI.3, R0.4, RI.4, R0.5, RI.5
  (L/R = Left/Right, O/I = Outer/Inner, .N = axle number)
Per-position data: brakes, tread, brand, org, wheels (AL=aluminum / STL=steel)
```
Stored as JSON in inspection_sections.section_data for the "Tires" section.

**Panel: Trailer Condition checklist**
Items: Mud Flaps, Lights, Canlocks, Landing Gear (L/G), Inflation, Tray/Skirts, Rub Rail
Each item: condition code from legend (OK / C / D / S / B / P / H) + notes
Stored as JSON in inspection_sections.section_data for the "Trailer Condition" section.

**Damage legend:** C=Cut, D=Dent, S=Scratch, B=Bruise, P=Patch, H=Hole

**DEFAULT SECTIONS — auto-created on every new inspection (9 sections):**
| sort_order | section_name | condition (default) | section_data |
|------------|--------------|---------------------|--------------|
| 1 | Exterior - Left Side | ok | null |
| 2 | Exterior - Right Side | ok | null |
| 3 | Exterior - Front Wall | ok | null |
| 4 | Exterior - Rear Door | ok | null |
| 5 | Exterior - Roof | ok | null |
| 6 | Exterior - Floor | ok | null |
| 7 | Interior | ok | null |
| 8 | Tires | ok | JSON: {positions:{L0.1:{brakes:'',tread:'',brand:'',org:'',wheels:''},…}} |
| 9 | Trailer Condition | ok | JSON: {mud_flaps:'ok',lights:'ok',canlocks:'ok',landing_gear:'ok',inflation:'ok',tray_skirts:'ok',rub_rail:'ok'} |

**UI design for show.php (inspection conduct page):**
- Sections 1–7 (exterior/interior): condition dropdown (ok/fair/damaged/missing/na) + notes textarea + photo upload zone
- Section 8 (Tires): full tire table UI matching physical form — per-position inputs for brakes/tread/brand/org/wheels, saved as JSON via sections/update.php
- Section 9 (Trailer Condition): 7-item checklist with legend code dropdown + notes per item, saved as JSON
- Overall completion bar: N of 9 sections filled (condition != null)
- Status transitions shown as buttons: draft → complete → signed
- "Create Damage Claim" button visible on complete/signed inspections (pre-fills damage_claims/create.php?inspection_id=N&unit_id=N&lease_id=N)

| Task | Status |
|------|--------|
| ALTER TABLE migrations (inspection_number, reefer_hours, fuel_level, cvi_expiry, is_clean, section_data) | ✅ |
| `api/v1/inspections/index.php` — paginated list, filters: status/unit/lease_id/type/date | ✅ |
| `api/v1/inspections/show.php` — full inspection + sections[] (with section_data) + photos[] | ✅ |
| `api/v1/inspections/create.php` — generate INSP-YYYY-NNNNN; auto-create 9 default sections | ✅ |
| `api/v1/inspections/update.php` — D19 optimistic lock; update header fields + reefer/fuel/cvi/clean | ✅ |
| `api/v1/inspections/delete.php` — block if status=complete or signed | ✅ |
| `api/v1/inspections/update_status.php` — state machine: draft→complete→signed (ACTUAL DB ENUM) | ✅ |
| `api/v1/inspections/sections/update.php` — update condition + notes + section_data JSON | ✅ |
| `api/v1/inspections/photos/upload.php` — StorageClient upload; bind to section_id optional | ✅ |
| `api/v1/inspections/photos/delete.php` — HARD DELETE (no deleted_at on inspection_photos) | ✅ |
| `app/admin/inspections/index.php` — KPI tiles + Alpine filterable table | ✅ |
| `app/admin/inspections/create.php` — unit + type + lease_id + date; auto-sections on API call | ✅ |
| `app/admin/inspections/show.php` — section conduct UI: exterior cards + tire table + trailer checklist + photos + status buttons + "Create Damage Claim" | ✅ |
| Patch `app/admin/equipment/show.php` — Inspections tab (lazy-loaded) | ✅ |
| Patch `app/admin/leases/show.php` — Inspections tab + "Start Pre-Lease Inspection" / "Start Post-Lease Inspection" buttons | ✅ |
| Patch `config/navigation.php` — add Inspections entry | ✅ |
| Permission module: `inspections` | ✅ |

**SCHEMA CORRECTIONS vs original task list:**
- Status machine is `draft → complete → signed` (NOT draft→in_progress→completed — that was wrong)
- `inspection_photos` has NO deleted_at → photos/delete.php is HARD DELETE
- `inspection_sections` has NO updated_at → no D19 lock on section updates (just update directly)
- `inspection_number` did NOT exist in schema — added via ALTER TABLE above

**Stop conditions:**
```
  ALTER TABLE migrations run → inspection_number column present ✅
  POST create → 201 inspection_number=INSP-2026-00001 returned ✅
  GET show → sections[] has 9 items, section 8 section_data has 20 tire positions ✅
  POST sections/update → condition + section_data saved (tire table round-trip, Michelin LO.1) ✅
  POST photos/upload → photo URL returned ✅
  Status draft→complete: 200 ✅
  Status complete→signed: 200 ✅
  INVALID_TRANSITION signed→draft: 409 ✅
  Block delete on signed: 409 ✅
  GET /inspections page 200 ✅
  GET /inspections/create page 200 ✅
  GET /inspections/show?id=N page 200 + tire table visible ✅
  equipment/show Inspections tab loads (insp_refs=28) ✅
  leases/show Inspections tab visible (insp_refs=33) ✅
  UNAUTHORIZED no-session API → 401 ✅
  "Create Damage Claim" link present on signed inspection ✅
```
**Bugs found and fixed during stop-condition testing:**
- `db_sanitize_column()` did not backtick-quote column names — `condition` (MySQL reserved word) caused SQLSTATE[42000] syntax error on `db_insert('inspection_sections', ...)`. Fixed: `db_sanitize_column()` now returns `` `{$col}` `` (backtick-wrapped).
- `api/v1/inspections/show.php` and `app/admin/inspections/show.php` both referenced `et.unit_type` — column is actually `et.category`. Fixed: aliased as `et.category AS unit_type` in both queries.

---

### S016-B — User Invite + Accept Flow
**One thing:** Admin invites user. User receives invite. User activates account.
*(Moved from original S016 — Inspections is higher priority post-maintenance)*

| Task | Status |
|------|--------|
| `app/auth/accept_invite.php` | ✅ |
| Invite token validation (7-day expiry) | ✅ |
| Token single-use | ✅ |
| user.status: invited → active on accept | ✅ |
| Audit log: account activation | ⬜ |
| (Admin invite creation is built in the Users module session) | ⬜ |

**Stop conditions:**
```
  GET invite link → set password form shown
  POST valid password → account active, redirected to login
  Login with new credentials → success
  GET same link → "Used or expired"
  Mock to 8 days old → "Expired"
```

---

### S017 — API Bootstrap
**One thing:** Every API contract enforcement function works correctly.
**This is the security gate for every API endpoint. Verify completely.**

| Task | Status |
|------|--------|
| `api/bootstrap.php` | ✅ |
| `require_method(method)` — 405 if wrong HTTP method | ✅ |
| `require_auth_api()` — 401 JSON if no valid session | ✅ |
| `require_permission(module, action)` — 403 JSON | ✅ |
| `json_success(data, meta)` — correct envelope | ✅ |
| `json_error(code, message, status)` — correct envelope | ✅ |
| `require_id(param)` — 400 if missing, 400 if not positive integer | ✅ |
| `require_input(fields)` — 422 with per-field errors if missing | ✅ |
| All responses: Content-Type: application/json | ✅ |
| All responses: no PHP errors/warnings leak into JSON | ✅ |

**Stop conditions:**
```bash
# Wrong method
curl -X GET /api/v1/leases/create.php
# → {"success":false,"error_code":"METHOD_NOT_ALLOWED","message":"..."}  HTTP 405

# No auth
curl -X POST /api/v1/leases/create.php -H "Content-Type: application/json"
# → {"success":false,"error_code":"UNAUTHORIZED","message":"..."} HTTP 401

# Wrong permission (login as dispatcher, try to access payments)
# → {"success":false,"error_code":"FORBIDDEN","message":"..."} HTTP 403

# Success envelope shape
# → {"success":true,"data":{...},"meta":{...}}

# Error envelope shape  
# → {"success":false,"error_code":"SNAKE_CASE","message":"Human readable"}

# require_input with missing field
# → {"success":false,"error_code":"VALIDATION_ERROR","message":"...","fields":{"field_name":"error message"}}
# HTTP 422
```

---

### S018 — Public Router + Error Pages
**One thing:** All URLs route correctly. All error pages render. Maintenance mode works.

| Task | Status |
|------|--------|
| `public/index.php` — router | ✅ |
| /portal/* → portal app | ✅ |
| /api/* → api layer | ✅ |
| /* → admin app | ✅ |
| Maintenance mode: reads storage/maintenance.flag | ✅ |
| Maintenance mode: bypass for MAINTENANCE_BYPASS_IPS | ✅ |
| `app/errors/403.php` — branded, links to dashboard | ✅ |
| `app/errors/404.php` — branded, links back | ✅ |
| `app/errors/500.php` — no stack trace in production | ✅ |
| `app/errors/maintenance.php` — reads ETA from flag file | ✅ |
| `public/error.php` — unified error handler | ✅ |

**Stop conditions:**
```bash
curl https://yourdomain.com/nonexistent-page
# → Branded 404 page (not Apache default)

echo "2025-12-25 15:00 PST" > storage/maintenance.flag
curl https://yourdomain.com
# → Maintenance page with ETA shown

rm storage/maintenance.flag
curl https://yourdomain.com
# → Normal page

# 500 page: no file paths, no stack traces
# (temporarily trigger a PHP error, verify output)
```

---

# PHASE 3: DESIGN SYSTEM
*Visual layer. Zero business logic.*

---

### S019 — CSS Design System: Tokens + Typography + Layout
**One thing:** CSS variables, typography scale, and layout grid all correct in dark + light.

| Task | Status |
|------|--------|
| `public/assets/css/app.css` — design tokens section | ✅ |
| All CSS custom properties under [data-theme="light"] | ✅ |
| All CSS custom properties under [data-theme="dark"] | ✅ |
| --bg-page, --bg-card, --bg-muted, --border-color | ✅ |
| --text-primary, --text-secondary, --text-muted | ✅ |
| Semantic colours (same both themes): success, warning, danger, accent | ✅ |
| DM Sans + DM Mono loaded via Google Fonts | ✅ |
| Typography scale: 11px → 20px | ✅ |
| Font weights: 400, 500, 600 only (never 700+) | ✅ |
| Layout: sidebar + main content, sidebar always dark | ✅ |
| 200ms transition on all colour properties | ✅ |
| Flash prevention script in header (reads localStorage before render) | ✅ |

**Stop conditions (visual — use browser dev tools):**
```
Create public/dev/tokens.php showing all tokens as swatches.
Toggle dark/light:
  □ All --bg-* change
  □ All --text-* change
  □ Sidebar stays dark both modes
  □ No white flash on toggle
  □ Colour transitions smooth (200ms)
  □ DM Sans used for all text
  □ DM Mono available for data class
  □ Page refresh: theme remembered (localStorage)
```

---

### S020 — CSS: Core Components
**One thing:** All interactive UI components styled correctly in both themes.

| Task | Status |
|------|--------|
| Buttons: 8 variants × 5 sizes | ✅ |
| Buttons: loading state (.btn-loading) | ✅ |
| Buttons: disabled state | ✅ |
| Badges/pills: all status colours | ✅ |
| Form inputs: input, select, textarea | ✅ |
| Form inputs: focus ring visible | ✅ |
| Form inputs: error state (red border, error message below) | ✅ |
| Form inputs: disabled state | ✅ |
| Cards: --bg-card, border, shadow | ✅ |
| KPI tiles: icon, value (DM Mono), label, clickable hover | ✅ |
| Toasts: 4 variants, positioned top-right | ✅ |
| Modal: overlay, content box, close button | ✅ |

**Stop conditions (visual):**
```
public/dev/components.php — delete after QA:
  □ All 8 button variants side by side
  □ All badge colours
  □ Form inputs: normal, focused, error, disabled
  □ KPI tile: number in DM Mono, label in DM Sans
  □ Toast: all 4 variants visible simultaneously
  □ Modal: opens, focus trapped, Escape closes
  □ All components look correct in BOTH dark and light
```

---

### S021 — CSS: Tables + Data Components
**One thing:** Table styles correct. Skeleton, empty state, pagination working.

| Task | Status |
|------|--------|
| Table: header, row, hover, selected row | ✅ |
| Table: currency columns right-aligned, DM Mono | ✅ |
| Table: status column centred with badge | ✅ |
| Table: actions column right-aligned | ✅ |
| Table: row cursor pointer | ✅ |
| Table: soft-deleted row muted (0.6 opacity) | ✅ |
| Skeleton: animated shimmer for rows | ✅ |
| Skeleton: animated shimmer for cards | ✅ |
| Empty state: centred icon + title + subtitle + action button | ✅ |
| Pagination: page buttons, current page, prev/next | ✅ |
| Per-page selector | ✅ |
| Tabs: active, hover, disabled | ✅ |
| Print @media: hide sidebar, topbar, buttons, pagination | ✅ |

**Stop conditions (visual):**
```
  □ Table row hover: background changes
  □ Table row click: cursor is pointer
  □ Currency column: right-aligned, DM Mono font
  □ Skeleton: shimmer animation plays
  □ Empty state: icon centred, readable, button visible
  □ Pagination: current page highlighted
  □ Ctrl+P: sidebar + topbar hidden, table expands
```

---

### S022 — JavaScript: API Client + Error Handling
**One thing:** Every API response type handled correctly. Every failure mode shows appropriate feedback.

| Task | Status |
|------|--------|
| `public/assets/js/app.js` — API client section | ✅ |
| `API.get(url, params)` — builds query string, calls fetch | ✅ |
| `API.post(url, data)` — JSON body, includes CSRF header | ✅ |
| Both methods: parse JSON response | ✅ |
| 200 success → return data | ✅ |
| 401 → redirect to login (preserve current URL in redirect param) | ✅ |
| 403 → Toast.error("You don't have permission to do that") | ✅ |
| 422 → return field errors object (let caller handle inline display) | ✅ |
| 500 → Toast.error("Something went wrong. Please try again.") | ✅ |
| Network error → Toast.warning("No internet connection") | ✅ |
| All requests: X-CSRF-Token header from meta tag | ✅ |
| Buttons triggering API calls: .btn-loading class while pending | ✅ |

**Stop conditions:**
```javascript
// In browser console after login:

// Happy path
API.get('/api/v1/dashboard/kpis.php')
  .then(d => console.assert(d.success === true, 'success shape correct'));

// 422 — validation error
API.post('/api/v1/customers/create.php', {})
  .then(d => {
    console.assert(d.success === false);
    console.assert(typeof d.fields === 'object', 'fields present on 422');
  });

// 401 — (expire session, then call)
// → should redirect to login, not show error

// 403 — (call endpoint your role cannot access)
// → Toast.error shown, no redirect

// Network error — (disable wifi, then call)
// → Toast.warning("No internet connection")

// CSRF header
// Open Network tab, make any POST
// → X-CSRF-Token header present in request headers
```

---

### S023 — JavaScript: Toast + Modal + Theme
**One thing:** User feedback components work correctly. Focus management correct for accessibility.

| Task | Status |
|------|--------|
| `Toast.success(msg, duration)` | ✅ |
| `Toast.error(msg)` | ✅ |
| `Toast.warning(msg)` | ✅ |
| `Toast.info(msg)` | ✅ |
| Toast: role="status" aria-live="polite" | ✅ |
| Toast: auto-dismiss after duration | ✅ |
| Toast: dismiss on click | ✅ |
| Toast: max 3 visible simultaneously (queue rest) | ✅ |
| `Modal.confirm(title, msg, onConfirm, onCancel)` | ✅ |
| Modal: focus trapped inside while open | ✅ |
| Modal: Escape key closes (fires onCancel) | ✅ |
| Modal: focus returns to trigger element on close | ✅ |
| Modal: overlay click closes (fires onCancel) | ✅ |
| `Theme.apply(theme)` — sets data-theme on html | ✅ |
| `Theme.toggle()` — switches + saves to localStorage + API call | ✅ |

**Stop conditions:**
```javascript
// Toast queue
Toast.success('First');
Toast.error('Second');
Toast.warning('Third');
Toast.info('Fourth');
// → First 3 visible, Fourth queues until one dismisses

// Modal focus trap
Modal.confirm('Delete?', 'Cannot be undone', () => {}, () => {});
// → Tab key stays inside modal
// → Shift+Tab stays inside modal  
// → Escape fires onCancel, modal closes
// → Focus returns to whatever triggered it

// Theme persistence
Theme.toggle();
// Reload page → same theme applied (no flash)
Theme.toggle();
// Reload page → other theme applied (no flash)
```

---

### S024 — JavaScript: Forms + Tables + Charts
**One thing:** Form tracking, bulk select, and chart lifecycle all work.

| Task | Status |
|------|--------|
| `FF_Form.validateRequired(formEl)` | ✅ |
| FF_Form: marks invalid fields, returns false | ✅ |
| FF_Form: clears errors on re-submit | ✅ |
| `FF_Form.trackChanges(formEl)` | ✅ |
| FF_Form.trackChanges: captures original values on page load | ✅ |
| FF_Form.trackChanges: returns true only if values changed | ✅ |
| `beforeunload` warning when unsaved changes | ✅ |
| `FF_Table.initBulkSelect(tableEl)` | ✅ |
| Header checkbox: selects/deselects all visible rows | ✅ |
| Bulk bar: appears on selection with count | ✅ |
| Bulk bar: disappears when selection cleared | ✅ |
| `FF.currency(amount)` | ✅ |
| `FF.compact(amount)` | ✅ |
| `FF.date(value)` — uses FF_TIMEZONE | ✅ |
| `FF_Charts.create(id, type, opts)` | ✅ |
| `FF_Charts.destroy(id)` — prevents duplicate chart error | ✅ |
| `FF_Charts.updateTheme(isDark)` | ✅ |

**Stop conditions:**
```javascript
// FF_Form unsaved changes
// Fill in a form field, try to navigate away
// → "You have unsaved changes" browser dialog appears

// FF_Table bulk select
// Check header checkbox → all rows selected, bulk bar appears
// Uncheck one row → header checkbox becomes indeterminate
// Check it again → all re-selected

// FF_Charts lifecycle
FF_Charts.create('test-chart', 'line', {
    series: [{name:'Test', data:[10,20,30]}],
    xaxis: {categories:['A','B','C']}
});
// Chart renders

FF_Charts.destroy('test-chart');
// Chart gone

FF_Charts.create('test-chart', 'line', {
    series: [{name:'Test', data:[40,50,60]}],
    xaxis: {categories:['D','E','F']}
});
// New chart renders — NO "chart already exists" error in console
```

---

### S025 — Layout Shell
**One thing:** Complete page layout renders for all roles. Navigation correct.

| Task | Status |
|------|--------|
| `includes/header.php` — HTML head, fonts, CSS, theme script, FF_TIMEZONE global | ✅ |
| `includes/sidebar.php` — reads navigation.php, hides per can() | ✅ |
| Sidebar active state: detected from current URL path | ✅ |
| Sidebar badge slots: overdue invoices count, compliance alerts count | ✅ |
| Sidebar: ALWAYS dark regardless of page theme | ✅ |
| `includes/topbar.php` — search input, theme toggle, bell, user menu | ✅ |
| `includes/footer.php` — CDN scripts (Alpine.js, ApexCharts), app.js | ✅ |
| Keyboard shortcuts init | ✅ |
| Skip navigation link (visually hidden, visible on focus) | ✅ |

**Stop conditions:**
```
Login as super_admin → all 14 modules visible in sidebar
Login as dispatcher → Invoices, Payments, Reports, Analytics NOT visible
Login as accountant → Maintenance, Reservations NOT visible

All roles:
  □ Current page highlighted in sidebar
  □ Sidebar stays dark in light mode
  □ Theme toggle button switches content area only
  □ ⌘K focuses search bar
  □ User menu dropdown shows name + logout
  □ Skip nav link visible on Tab keypress
  □ No console errors on any page
```

---

# PHASE 4: DATABASE
*Schema + seeds. Verified before any business logic.*

---

### S026 — Schema Creation: Groups 1–4 (Core Tables)
**One thing:** First 40 core tables created with zero FK errors.
*(Split from Group 5+ because financial tables have circular FKs requiring ALTER TABLE)*
**STATUS: ✅ COMPLETED in S002 — full 94-table schema applied as single run.**

| Task | Status |
|------|--------|
| Run schema groups 1–4 from FLEETFORGE_DATABASE_MASTER.sql | ✅ |
| Groups: user_roles, users, user_permissions, audit_log, tax_rates | ✅ |
| Groups: exchange_rates, customers, customer_*, equipment_*, yards | ✅ |
| Groups: rate_cards, vendors, leases, lease_*, reservations, reservation_units | ✅ |
| Groups: late_fee_rules, maintenance_*, inspections, equipment_status_log, yard_transfers | ✅ |
| Verify zero FK errors | ✅ |

**Stop conditions:**
```sql
SELECT COUNT(*) FROM information_schema.tables 
WHERE table_schema = 'fleetforge';
-- Must be correct count for groups 1-4

SHOW ENGINE INNODB STATUS\G
-- No LATEST FOREIGN KEY ERROR section
```

---

### S027 — Schema Creation: Groups 5–8 (Financial + Deferred FKs)
**One thing:** Remaining 19 core tables created. All circular FKs resolved. Total 59 core tables + schema_migrations utility table.
**STATUS: ✅ COMPLETED in S002 — full 94-table schema applied as single run.**

| Task | Status |
|------|--------|
| Run schema groups 5–8 (invoices, payments, credit_notes, damage_claims, etc.) | ✅ |
| Deferred FKs are inline in master SQL — no separate file needed [PASS-1:C6] | ✅ |
| Run groups: documents, contracts, reports, notifications, AI, settings, portal | ✅ |
| Run schema_migrations utility table (end of master SQL) | ✅ |
| Verify all 59 core tables + 1 utility table (schema_migrations) exist | ✅ |
| Verify all 3 deferred FKs resolved | ✅ |
| Verify all FULLTEXT indexes exist | ✅ |
| Verify no FLOAT columns anywhere | ✅ |

**Stop conditions:**
```sql
-- Total core + utility table count (accounting tables added later)
SELECT COUNT(*) FROM information_schema.tables 
WHERE table_schema='fleetforge';
-- Must be 60 (59 core + 1 schema_migrations)

-- Deferred FKs resolved (must find these 3 constraints)
SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA='fleetforge' 
AND CONSTRAINT_NAME IN (
  'fk_invoices_billing_period',
  'fk_leases_last_invoice',
  'fk_bill_lines_asset'
);
-- Must return 2 rows (third is accounting, added later)

-- FULLTEXT indexes
SELECT TABLE_NAME, INDEX_NAME 
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='fleetforge' AND INDEX_TYPE='FULLTEXT';
-- Must return 5 rows

-- No FLOAT columns
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='fleetforge' AND DATA_TYPE='float';
-- Must return 0 rows
```

---

### S028 — Seeds + First Admin User
**One thing:** All seed data correct. First admin user can log in.
**STATUS: 🔄 PARTIAL — roles (001) + super_admin user (002) done in S002. Remaining seeds (002–005) are S003 scope.**

| Task | Status |
|------|--------|
| Seed 001: 5 user roles | ✅ |
| Seed 002: 70 permission rows (5 × 14) | ⬜ |
| Seed 003: all default settings | ⬜ |
| Seed 004: Surrey BC yard | ⬜ |
| Seed 005: BC + Ontario + Alberta tax rates | ⬜ |
| First super_admin user created (script or manual SQL) | ✅ |
| Login verified in browser | ⬜ |

**Stop conditions:**
```sql
SELECT COUNT(*) FROM user_roles;          -- 5
SELECT COUNT(*) FROM user_permissions;    -- 70
SELECT COUNT(*) FROM settings;            -- > 0
SELECT COUNT(*) FROM yards;               -- 1
SELECT COUNT(*) FROM tax_rates;           -- 3

-- Dispatcher permissions: cannot access invoices
SELECT can_view FROM user_permissions 
JOIN user_roles ON user_roles.id = user_permissions.role_id
WHERE user_roles.slug='dispatcher' AND module='invoices';
-- Must return 1 (dispatchers CAN view invoices per spec permission matrix — financial field stripping is handled at API response level, not at module permission level) [PASS-7:W7]

-- BC tax rates correct
SELECT gst_rate, pst_rate, hst_rate FROM tax_rates WHERE province='BC';
-- 0.0500, 0.0700, 0.0000
```
```
Browser: Login with seeded admin → dashboard page renders (even if empty)
```

---

# PHASE 5: DASHBOARD

---

### S029 — Dashboard KPIs: Contract + Backend
**✅ COMPLETED in build session S004 (2026-04-01)**
**One thing:** KPI endpoint defined, implemented, and tested. No UI yet.

**Contract (define and approve before coding):**
```
GET /api/v1/dashboard/kpis.php
Auth: required
Response: {
  success: true,
  data: {
    active_revenue: decimal,        // sum of active lease monthly rates
    fleet_utilization_pct: decimal, // 1 decimal place, 0–100
    open_leases_count: int,
    overdue_invoices_count: int,
    overdue_invoices_amount: decimal,
    compliance_alerts_count: int,   // expiring within 30 days
    todays_pickups_count: int
  }
}
All values: numbers not null (use 0.0 / 0 when no data)
```

| Task | Status |
|------|--------|
| Contract reviewed and approved | ✅ |
| `api/v1/dashboard/kpis.php` implemented | ✅ |
| Each KPI uses correct query per spec business logic | ✅ |
| fleet_utilization: excludes inactive + decommissioned from denominator | ✅ |
| All values return numeric (no nulls) | ✅ |
| 5-min report_cache write on every fresh response | ✅ |
| Cache hit path returns without re-running queries | ✅ |

**Stop conditions — all passed S004:**
```bash
curl -b <cookie> http://fleetforge.test/fleetforge/api/v1/dashboard/kpis
# → 200, all 6 KPI fields, active_revenue:"0.00", fleet_utilization:0, etc.
curl http://fleetforge.test/fleetforge/api/v1/dashboard/kpis  # no auth
# → 401 UNAUTHORIZED
```

---

### S030 — Dashboard KPIs: Tiles + Drilldowns + Charts + Activity Feed
**✅ COMPLETED in build session S004 (2026-04-01)**
**Expanded scope:** Original S030 covered only KPI tiles. Build session S004 also delivered all 8 ApexCharts and the activity feed in the same pass, so original S031+ dashboard-chart sessions are merged in here.

| Task | Status |
|------|--------|
| `app/admin/dashboard/index.php` — page shell | ✅ |
| Alpine.js FF_Dashboard() component — fetches all 3 APIs in parallel on mount | ✅ |
| 6 KPI tile components, each linked to drilldown destination | ✅ |
| Loading skeleton for each tile (stat-skeleton) | ✅ |
| Error state: tile shows "—" on API failure | ✅ |
| Each tile: clickable `<a>` with correct filtered destination URL | ✅ |
| `api/v1/dashboard/charts.php` — 8 ApexCharts datasets, 15-min cache | ✅ |
| `?chart=<key>` single-chart param supported + allowlist guard | ✅ |
| Revenue trend area chart (12-month, YoY) | ✅ |
| Fleet status donut (5 segments) | ✅ |
| AR aging horizontal bar (4 buckets, bcmath balance_due totals) | ✅ |
| Top 5 customers horizontal bar (YTD revenue) | ✅ |
| Leases opened vs closed grouped bar (12 months) | ✅ |
| Utilization trend line (12-month %) | ✅ |
| Revenue by equipment type donut (JOIN through lease → unit → template) | ✅ |
| Weekly revenue heatmap (7×12 grid, last 84 days) | ✅ |
| Chart skeleton loaders while data fetches | ✅ |
| Empty state for revenue_by_type when no invoice data | ✅ |
| `api/v1/dashboard/activity_feed.php` — last 20 audit_log events | ✅ |
| Activity feed noise-filtered (excludes view/login/logout/cron) | ✅ |
| time_ago() helper — seconds/mins/hours/days/date | ✅ |
| Activity feed empty state + error state with Retry | ✅ |
| Compliance alerts widget (count summary + link) | ✅ |
| Today's pickups widget (count summary + link) | ✅ |
| ApexCharts theme auto-switches dark/light (isDark check on render) | ✅ |
| Dark/light colour tokens applied to all chart axes and grids | ✅ |

**Stop conditions — all passed S004:**
```
GET /api/v1/dashboard/kpis → 200, 6 fields         ✅
GET /api/v1/dashboard/charts → 200, 8 chart datasets ✅
GET /api/v1/dashboard/charts?chart=fleet_status → 200, single dataset ✅
GET /api/v1/dashboard/charts?chart=bogus → 404 NOT_FOUND ✅
GET /api/v1/dashboard/activity_feed → 200, items array ✅
Dashboard page /fleetforge/dashboard → 200, no PHP errors ✅
Dashboard contains FF_Dashboard + ApexCharts markers (11 instances) ✅
```

---

*(Sessions S031 onward follow the same pattern for every feature:
 contract → backend → frontend → full test → commit)*

---

## CONTINUING SESSIONS (S031+)

The sessions from S031 onward follow the exact same structure as above.
Each session will be written out fully when we approach it.

The full session map is in the SESSION MAP table above.
Sessions S031–S110 cover the full core operational platform.
Sessions S111–S150 cover the accounting module.

When a session is about to start, Claude Code will:
1. Read this file
2. Find the NEXT SESSION STARTS WITH instruction
3. Write out the full session plan (contract + tasks + stop conditions)
4. Show it to you for approval before writing any code

---

## GLOBAL STANDARDS

| Standard | Rule |
|----------|------|
| **Soft deletes** | Every query on the 15 SOFT_DELETE_TABLES includes `AND {table}.deleted_at IS NULL` |
| **SOFT_DELETE_TABLES** | users, customers, customer_notes, equipment_templates, equipment_units, leases, damage_claims, invoices, maintenance_work_orders, documents, vendors, credit_notes, reservations, rate_cards, payments [PASS-13:F2] |
| **DB helpers only** | NEVER raw `$pdo->query()` — always the typed helpers |
| **Output escaping** | NEVER raw echo of any user/DB data — always `e()` |
| **Input cleaning** | All POST/GET data through clean_*() before use |
| **Monetary** | DECIMAL(12,2) in DB — NEVER float. bcmath in PHP — NEVER float operators [PASS-10:6] |
| **UTC** | Store UTC, display via format_datetime() in company timezone |
| **Strict types** | `<?php declare(strict_types=1);` — first line of every PHP file |
| **API format** | `{success:true,data:{},meta:{}}` or `{success:false,error_code:"",message:""}` |
| **Numbers are links** | Every count/KPI/summary value drills down |
| **DM Mono** | All numbers, amounts, dates, codes use DM Mono |
| **State machines** | Invalid transitions → 409. All transitions → status_log. |
| **File uploads** | finfo_file() MIME check, safe rename, ALL uploads via StorageClient [INFRA] — NEVER move_uploaded_file() directly |
| **Row locking** | Lease creation, lease close, payment allocation, credit application — all use SELECT ... FOR UPDATE inside db_transaction() [PASS-8:4, D20] |
| **Optimistic locking** | All update endpoints compare updated_at before saving → 409 STALE_DATA if stale [PASS-8:4G, D19] |
| **Cron locks** | Every write-heavy cron uses MySQL GET_LOCK() advisory lock to prevent duplicate runs [D21] |
| **Audit logging** | Every write: user_id, action, module, entity_type, entity_id, old_values, new_values |
| **Permission gates** | PHP enforces. JS only hides for UX. Both always present. |
| **Validation** | Server-side always. Client-side for UX only — never trusted. |
| **Business logic** | Lives in PHP only. Never in JS. Never split across both. |
| **Denormalized counters** | Updated in the SAME transaction as the triggering event |
| **Portal isolation** | Every portal query: AND customer_id = portal_customer_id() |
| **Billing math** | Pure classes: no DB, bcmath only [PASS-10:6]. InvoiceGenerator: only class that writes. Day count: inclusive (end-start+1) [PASS-3:1A]. |
| **Contract first** | Every API session starts with written contract, approved before code |

---

*Total planned sessions: ~150*
*Infrastructure + Foundation: S001–S028*
*Core operational platform: S029–S110*  
*Accounting module: S111–S150*
*Schema: 94 tables locked (59 core + 34 accounting + 1 utility). Session plan: atomic vertical slices.*
*Build order corrections from PASS-7:*
*- Sessions reordered: S012 (Sessions) → S010 (CSRF) → S011 (Permissions) [PASS-7:V1/V2]*
*- S013 split: S013a (login happy path) → S013b (brute force + audit) → S013c (remember-me) [PASS-7:SC3]*
*- Missing sessions added: audit_log helper (S008), file upload helper (before Phase 5), pagination helper (before S031), mailer setup (before S015), exchange rate CRUD (before Phase 7) [PASS-7:M1-M12]*
*- Dispatcher invoice permission: can_view=1 per spec permission matrix [PASS-7:W7]*

*Last updated: 2026-04-03 — S018 complete. Sessions completed to date: S001 (Foundation), S002 (Schema + Seeds), S003 (Dashboard Stub + Remaining Seeds), S004 (Dashboard KPIs + Charts), S005 (Customers), S006 (Equipment), S007 (Leases), S008 (Invoices + Billing Engine), AUDIT-1 (69-issue QC audit), S008.5 (Critical Fix), S009 (Payments), S009-EXT (Topbar), S010 (Billing Cron), S011 (Credit Notes), S012 (Damage Claims), S013 (Mileage Logs), S014 (Vendors), S015 (Maintenance Work Orders), S016 (Inspections), SEARCH-FIX, S017 (Users + Admin + Audit + Settings), S017-B (My Profile + change_password + save_preference + login history), S017-C (super_admin set_password + delete bypass), S017-X (sidebar scroll + copyright footer + stress test + customers/show bug fix), S017-UX (scrollable tab tables + filter/sort bars on all show pages + global search endpoint + leases/invoices list redesign), S018 (Reservations Module). 34 decisions locked. SOFT_DELETE_TABLES: 15. lib/Billing/ 5 files. lib/GPS/ 1 file. Next: S019.*

*Next session: S019 — Rates Module. Build rate_cards + customer_equipment_rates CRUD, api/v1/rate_cards/ + api/v1/customer_equipment_rates/, app/admin/rates/ (index/create/show). Fixes live 404 on /rates sidebar link. Lease create pre-fill from customer rates → rate cards → template default.*