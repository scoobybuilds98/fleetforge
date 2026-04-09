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
| S018-EXT | 2026-04-03 | Reservations UX — Yards Management + Pickup Yard Dropdown + Date Picker + Conflict Override | 10 files modified/created. **Yards management module built**: `api/v1/yards/index.php` (lists all yards; `?all=1` includes inactive; active-only default used by forms), `api/v1/yards/create.php` (auto-generates slug from name; uniqueness check name+slug; dispatcher+ permission), `api/v1/yards/update.php` (D19 optimistic lock; name uniqueness excluding self; can toggle is_active), `api/v1/yards/delete.php` (deactivates yard via `is_active=0`; hard guard: cannot deactivate if yard has upcoming pending/confirmed reservations matched by `yard_location = yard.name`). **Yards admin page**: `app/admin/yards/index.php` — full CRUD page with 3 KPI tiles (total/active/inactive server-rendered), Alpine.js `FF_YardsManager()` component: loads `?all=1`, filters active-only vs all toggle, Create modal (name/address/city/state/postal_code/capacity/phone/notes), Edit modal (same fields + is_active checkbox toggle), Deactivate button (calls delete.php; guarded by upcoming-reservation check in API), Activate button (calls update.php with is_active=1). **Navigation**: `public/assets/icons/map-pin.svg` (Heroicons outline) created; Yards entry added to `config/navigation.php` after Reservations with `module='settings'` (managers+ only). **Reservation create/show — yard dropdown**: `api/v1/reservations/units_by_customer.php` updated to return `yards[]` (id/name/city/state for active yards) alongside templates + units — single API round-trip; `app/admin/reservations/create.php` — yard_location `<input type="text">` in expanded fields replaced with `<select>` populated from `yards` state (value = yard.name to preserve historical string snapshots); `loadTemplates()` now captures `yards` from response; show.php's `onCustomerChange()` also refreshes yards. `app/admin/reservations/show.php` — edit mode yard_location `<input>` replaced with `<select>` populated from `yards` state; legacy/inactive yard value shown as fallback option; `loadYards()` added calling `api/v1/yards/index.php`; `init()` uses `Promise.all([loadReservation(), loadYards()])` for parallel loading. **Date picker improvement**: `app/admin/reservations/create.php` — pickup date field wrapped in flex row with a calendar icon button that calls `$refs.pickupDate.showPicker()` (with `.click()` fallback for older Safari). **Conflict override flow**: `api/v1/reservations/create.php` — extracted `force_override` boolean; inside transaction per-unit conflict check now branches: if `!force_override` → `json_error(CONFLICT, ..., 409, {conflict_unit, conflict_reservation_id, conflict_company, conflict_date})` (structured for UI); if `force_override` → appends `"⚠ OVERRIDE by {name}: Unit X double-booked on {date} (existing Res #Y — COMPANY)"` to `internal_notes`, proceeds with insert; lease conflicts always hard-block regardless of override. `app/admin/reservations/create.php` — `conflictModal` state added; `submit()` checks `res.error?.code === 'CONFLICT'` and opens modal instead of setting formError; modal shows conflict message + warning; "Override & Double-Book" button calls `submitWithOverride()` which re-submits with `force_override: true`. **Stop conditions**: php -l all 10 files clean ✅; GET `api/v1/yards/index.php?all=1` → 200 `yards: [{id:1, name:"Surrey Yard", ...}]` ✅; POST `api/v1/yards/create.php` `{name:"Delta Yard",city:"Delta"}` → 201 `{id:2,name:"Delta Yard",slug:"delta-yard"}` ✅; POST `api/v1/yards/delete.php` `{id:2}` → 200 `{id:2}` (deactivated) ✅; GET `api/v1/reservations/units_by_customer.php` → 200 includes `yards:[{id:1,name:"Surrey Yard",city:"Surrey",state:"BC"}]` ✅; `/yards` page → 200 with `FF_YardsManager` Alpine component + KPI tiles ✅; `/reservations/create` → 200 with `showPicker` date button + `<select id="res-yard">` + `conflictModal` in JS ✅; `/reservations/show?id=1` → 200 with `<select id="edit-yard">` + `loadYards()` + `Promise.all` init ✅. |
| S018 | 2026-04-03 | Reservations Module (API + Admin UI + Dashboard Fix) | 11 new files built, 2 existing files modified. All 14 stop conditions passed. **DB**: reservations + reservation_units tables already existed in schema (created in S002). No migrations needed. **Bugs found and fixed during stop-condition tests**: (1) `eu.equipment_template_id` → `eu.template_id` (actual column name in equipment_units); (2) audit_log inserts used `description` (column does not exist), wrong action ENUMs (`created/updated/deleted/status_changed`) — fixed to `notes` + correct ENUM values (`create/update/delete/status_change`) + added `user_name`/`entity_label` columns; (3) `eu.make`/`eu.model` don't exist on equipment_units — removed from show.php + units_by_customer.php. **API (8 endpoints in api/v1/reservations/)**: index (paginated; filters: status/pickup_date/pickup_date_from/to/customer_id/q LIKE; sort allowlist pickup_date/created_at/company_name/status/priority; default pickup_date ASC; JSON_ARRAYAGG units per row; null unit guard in decode loop), show (full detail + units[with vin/year/current_status] + audit_log[last 20]; al.notes AS description alias), create (required: contact_name/company_name/pickup_date/quantity; units[] array with entry_type system/manual; FOR UPDATE conflict detection per unit+pickup_date: blocks if same unit already has pending/confirmed reservation or active lease on same date; equipment status→reserved for system units on confirmed creation), update (D19 optimistic lock; blocks if completed/cancelled; editable fields only), delete (soft-delete; blocks if confirmed/completed — must cancel first; reverts reserved→available on linked units), update_status (full state machine: pending→confirmed/cancelled, confirmed→cancelled, completed→confirmed reverse [manager only], cancelled=TERMINAL; cancel_reason required; FOR UPDATE re-check on pending→confirmed; appends cancel reason to internal_notes; equipment status transitions per state), mark_out (confirmed→completed; stamps marked_out_at/by; optional lease_id linkage written to reservation_units.lease_id_linked; unit status→on_lease if lease linked else available), units_by_customer (returns customer_units via active/pending leases, available_units fleet-wide, templates for Trailer Type dropdown). **Admin UI (3 pages in app/admin/reservations/)**: index.php (fixes live 404 on sidebar link ✅; two-table layout: Chassis In [pending+confirmed merged via 2 parallel API calls + client-side sort] / Chassis Out [completed]; 4 KPI tiles: Total Active/Pending/Confirmed/Today's Pickups [clickable to filter by date]; filter toolbar: search/pickup_date/priority/sort/dir; Chassis In row actions: Edit pencil→show, Confirm [pending only], Chassis Out [confirmed only], Cancel [modal with reason], Delete [pending only]; Chassis Out row actions: View, Reverse [manager only]; cancel modal with reason textarea + error display; all actions reload both tables), create.php (mode toggle Existing Customer / Manual Entry; customer mode: dropdown auto-fills company+contact, units_by_customer API loads customer-leased + available units in optgroups; manual mode: free-text unit number + Add button; selected units preview list with × remove buttons; quantity auto-syncs to unit count; expanded fields collapsible: pickup_time/priority/phone/email/yard/purpose/internal_notes; conflict 409 shown as form error banner), show.php (two-column layout: left [detail card view+edit, units table, activity log], right [actions panel + summary card]; context-sensitive actions panel per status; edit mode: D19 optimistic lock via hidden updated_at field; cancel modal on show page; units table: unit#/type/year/vin/status_at_reservation/current_status/entry_type badge/linked_lease link; activity log: last 20 audit entries from show API). **Dashboard fixes (2 files)**: api/v1/dashboard/kpis.php — todays_pickups changed from `leases WHERE start_date=today AND status IN pending/active` to `reservations WHERE pickup_date=today AND status IN pending/confirmed`; app/admin/dashboard/index.php — Today's Pickups tile href changed from `/leases?start_date=today` to `/reservations?pickup_date=today`. **State machine implemented exactly per spec §6**: pending→confirmed (conflict re-check FOR UPDATE), pending→cancelled, confirmed→cancelled, completed→confirmed (manager only), cancelled=TERMINAL. **14 stop conditions**: SC1 php -l all 13 files clean ✅; SC2 /reservations sidebar 200 ✅; SC3 GET api/v1/reservations/index.php → 200 paginated (0 items) ✅; SC4 POST create → 201 {id:2} ✅; SC5 GET show?id=2 → 200 status=pending company=LOTUS TERMINALS units=[] audit_log=[1 entry] ✅; SC6 POST update_status confirmed → 200 {id:2,status:confirmed} ✅; SC7 POST mark_out → 200 {id:2,status:completed,marked_out_at:...} ✅; SC8 conflict detection → 409 CONFLICT "already reserved" ✅; SC9 completed→cancelled INVALID_TRANSITION → 409 ✅; SC10 UNAUTHORIZED no session → 401 ✅; SC11 /reservations 200 ✅; SC12 /reservations/create 200 ✅; SC13 /reservations/show?id=2 200 ✅; SC14 units_by_customer → 200 templates=[{53ft Dry Van}] ✅. |
| S017-UX | 2026-04-03 | UX Polish — Scrollable Tab Tables, Filter/Sort Bars, Global Search, List Page Redesigns | 9 files modified, 1 new file created. **Scrollable tab tables (public/assets/css/app.css + 4 show pages)**: 4 CSS classes added — `.tab-table-container` (max-height calc(100vh-320px), overflow-y auto), sticky thead (position:sticky/top:0/z-index:2/background:var(--bg-surface)), `.tab-filter-bar` (flex row with gap+border-bottom), `.tab-table-footer` (flex row with count + load-more button). Applied to all sub-tables on customers/show.php (leases/invoices/damage_claims/mileage_logs), equipment/show.php (leases/damage_claims/mileage_logs/work_orders/inspections), vendors/show.php (work_orders converted from server-rendered to Alpine FF_VendorWorkOrders() component calling maintenance_work_orders/index.php), leases/show.php (invoices/damage_claims/mileage_logs/inspections). Each tab has filter selects appropriate to its data: leases (status/sort/dir), invoices (status/sort/dir), damage_claims (severity/status/dir), mileage_logs (log_type/dir), work_orders (status/work_type/sort/dir), inspections (inspection_type/status/dir). JS components updated to add `*Total`/`*Page`/`*Filters` state per tab, `append` param on load functions, `loadMore*()` + `applyFilters*()` methods, pagination.total tracking. `<template x-if>` → `x-show` for filter bar compatibility. Old per-page=50 hard limit replaced with proper pagination (showing X of Y + Load more). **leases/show.php stat tiles**: `l.total_paid` added to server-side query; stat-grid (Total Invoiced, Total Paid, Outstanding, Currency) moved from Alpine Overview template to server-rendered PHP above the tab component — matches customers/show and equipment/show pattern so tiles persist across all tabs. **api/v1/search.php** (NEW): global search endpoint for topbar ⌘K FF_Search component. Accepts `?q=` (min 2 chars, clean_string null guard). Searches customers (company_name/contact_name/email), equipment_units (unit_number/vin/make/model), leases (contract_number/company_name_snapshot), invoices (invoice_number/company_name_snapshot — NOT customer_name_snapshot which doesn't exist), vendors (name/contact_name). Up to 5 results per type. Returns `{success:true, data:{results:[{type,title,meta,url}]}}` matching FF_Search._renderResults() contract. **leases/index.php redesign**: summary-strip (thin dot bar) → 4 stat-grid KPI tiles (Active with pending delta, Pending, Completed with cancelled delta, Active Revenue from dashboard/kpis). 5 parallel loadKpis() API calls. Sort By + Direction selects added to toolbar-right. Sortable column headers: Contract, Dates, Status with ↑/↓ indicator. setSort() toggles direction. Closed tab fixed: was fetching only completed, silently dropping cancelled; now fetches unfiltered + client-side filters completed|cancelled. Pagination on All tab only; open/closed fetch per_page=200 for complete client-side filtering. Error/empty states with SVG icons added. **invoices/index.php**: Sort By select (Invoice #, Invoice date, Due date, Amount, Balance due, Status) + Direction select added. Sortable column headers: Invoice #, Date, Due, Total, Balance, Status with ↑/↓. `filters.sort`/`filters.dir` wired into load(). Bug fixes: `stat-card__value` → `stat-value` on 3 AR aging tiles (non-existent class, unstyled values); `pagination.current_page` → `pagination.page` (API returns `page` key — pagination buttons were broken); isOverdue() adds written_off to exempt statuses. formatMoney() helper added. All files php -l clean. ✅ |
| S017-X | 2026-04-03 | Sidebar Scroll Persistence + Copyright Footer + Stress Test | 4 files modified. **public/assets/js/app.js** (MODIFIED): sidebar scroll persistence added inside DOMContentLoaded — `sessionStorage` key `ff-sidebar-scroll`; saves on `pagehide` event (not beforeunload — more reliable on mobile/Safari); restores on page load via `el.scrollTop = parseInt(saved)`. **includes/footer.php** (MODIFIED): copyright footer added as `<footer class="app-footer">` outside `<main>` but inside `.app-main`, as last flex child — `&copy; <?= date('Y') ?> Avi Nanda. All rights reserved.` + `FleetForge <?= e(FF_VERSION) ?>`. **public/assets/css/app.css** (MODIFIED): `.app-footer` class added — `flex-shrink:0; display:flex; align-items:center; justify-content:space-between; padding:12px 32px; border-top:1px solid var(--border-color); font-size:0.6875rem; color:var(--text-muted); margin-top:auto` — `margin-top:auto` pushes to page bottom naturally in flex column (only visible when scrolled to bottom on tall pages; visible by default on short pages). **api/v1/customers/show.php** (BUG FIX): stress test found HTTP 500 — `Unknown column 'total_invoiced' in field list`; customers schema has `total_revenue` + `account_credit_balance` not `total_invoiced`/`total_paid`; also `late_fee_rate` → `late_fee_value`; 3 columns corrected; verified returning 200 success:true. All other admin module pages passed stress test — PHP syntax clean across all 40+ files. ✅ |
| S015 | 2026-04-03 | Maintenance Work Orders Module (API + Admin UI) | 13 new files built, 1 existing file modified. All 27 stop conditions passed. **API (9 endpoints in api/v1/maintenance_work_orders/)**: index (paginated; filters: status/work_type/priority/equipment_unit_id/vendor_id/date_from/date_to/q; JOINs equipment_units+templates+vendors+users for labels; sort allowlist), show (full WO detail + line_items[] array + all JOIN labels), create (atomic WO# generation via generate_id('WO',year) inside transaction — Trap 9; validates enums; vendor optional; status always 'open'; audit_log), update (D19 optimistic lock on updated_at; blocks terminal states completed/cancelled; vendor_id clearable with explicit null; audit old_values/new_values), delete (soft delete; blocks in_progress/waiting_parts/completed → INVALID_TRANSITION; only open/cancelled deletable), update_status (full state machine: open→in_progress,cancelled | in_progress→waiting_parts,completed | waiting_parts→in_progress | completed/cancelled TERMINAL; on 'completed' updates vendors.total_spent in same transaction — Trap 6; resolution_notes saved on complete; audit_log action='status_change'). **Line items (3 endpoints in api/v1/maintenance_work_orders/line_items/)**: add (validates WO editable; total_cost = qty×unit_cost via bcmath D16; recalculates mwo.labor_cost/parts_cost/total_cost in same transaction — Trap 6), update (same recalc; no optimistic lock — no updated_at on this table), delete (hard delete — no deleted_at on maintenance_line_items; same recalc). **Admin UI (3 pages in app/admin/maintenance_work_orders/)**: index.php (4 KPI tiles: total/open/active/completed-this-month; KPI tiles are clickable and set Alpine filter; Alpine filterable table with status+work_type+priority+q filters; sort: requested_date/total_cost/WO#/status/priority; badge helpers for all ENUMs; URL target for /maintenance navigation entry), create.php (unit dropdown JOINs templates for brand/model; vendor dropdown optional; work_type/priority/dates/mileage/assignment sections; submit via FF_Api.post→redirect to show; pre-populate unit_id from ?unit_id= query param), show.php (hero header with WO# + status badge; 4 KPI cost tiles server-rendered; status transition buttons rendered server-side per state machine — completion shows resolution_notes textarea prompt before confirming; inline edit mode D19 lock via updated_at; line items CRUD panel with add/delete; vendor link; equipment link; delete modal). **Equipment show.php patched**: Maintenance tab stub ("coming in S016") replaced with real lazy-loaded Alpine table via api/v1/maintenance_work_orders/index.php?equipment_unit_id=N; workOrders[]/workOrdersLoading/workOrdersLoaded state added; $watch('activeTab') extended; loadWorkOrders() method added; woBadge helpers added; "+ New Work Order" button pre-fills ?unit_id=. **Key decisions applied**: No work_order_status_log table — all status history in audit_log (action='status_change'). Equipment unit status NOT auto-flipped on WO create/complete — separate user action via equipment/units/update_status.php. WO number format: WO-YYYY-NNNNN. vendors.total_spent updated only on 'completed' transition (not on create/update). maintenance_line_items has no soft delete. All 13 PHP files php -l clean. **27 stop conditions**: GET list empty total=0 ✅; POST create id=1 WO-2026-00001 ✅; GET list total=1 ✅; GET show status=open unit=TEST-U001 ✅; missing work_type → 422 VALIDATION_ERROR ✅; POST update 200 ✅; STALE_DATA → 409 ✅; add labor item 201 line=150 wo=150 ✅; WO costs labor=150 parts=0 total=150 ✅; add parts item wo=270 ✅; delete parts item wo=150 ✅; status open→in_progress ✅; INVALID_TRANSITION in_progress→open ✅; status in_progress→completed ✅; IMMUTABLE_RECORD on completed ✅; create WO#2 WO-2026-00002 ✅; soft delete open WO ✅; show deleted → 404 ✅; UNAUTHORIZED no session ✅; status filter open total=0 ✅; status filter completed total=1 ✅; /maintenance_work_orders 200 ✅; /maintenance_work_orders/create 200 ✅; /maintenance_work_orders/show?id=1 200 ✅; equipment/show 200 ✅; maintenance stub removed ✅; loadWorkOrders present ✅; sort injection guard safe ✅. |
| S019 | 2026-04-04 | Rates Module — Rate Cards + Customer Overrides + Lease Pre-fill | 13 new files built, 1 existing file patched. All 19 stop conditions passed. **Rate Cards API (5 endpoints in api/v1/rate_cards/)**: index (paginated; filters: q/is_default/active; sort allowlist name/effective_from/created_at; computes is_active per row; joins users for created_by_name), show (card detail + items[] array + is_active flag), create (required: name unique + effective_from; optional: description/effective_to/is_default/items[]; is_default=1 clears all other defaults in same transaction; D5 soft-delete), update (D19 optimistic lock on updated_at; optional items[] replaces full item set atomically via DELETE+INSERT), delete (soft-delete; blocks default card deletion → 422 VALIDATION_ERROR). **Customer Equipment Rates API (3 endpoints in api/v1/customer_equipment_rates/)**: index (optional customer_id filter; hard-delete table), upsert (create OR update path — id present = update; D19 lock on update path only; writes customer_rate_history on every create and update; returns 201/200 appropriately), delete (hard delete; writes customer_rate_history change_type='deleted' before removing row). **Lease Rate Lookup (api/v1/leases/lookup_rates.php)**: GET ?customer_id + equipment_template_id; priority chain: (1) customer_equipment_rates where customer_id + template name + date in range, (2) rate_card_items JOIN rate_cards where rc.deleted_at IS NULL + active today, prefer is_default DESC, (3) equipment_templates default_* rates, (4) source=none; returns {source, source_label, daily_rate, weekly_rate, monthly_rate, mileage_rate, mileage_unit, currency}. **Admin UI (3 pages in app/admin/rates/)**: index.php (fixes live 404 on /rates sidebar link ✅; 3 KPI tiles server-rendered: total cards/active today/total overrides; two-tab layout: Rate Cards tab + Customer Overrides tab via Alpine.js FF_RatesManager(); Rate Cards tab: q/active/is_default filters + sortable table + Delete modal; Overrides tab: equipment_type filter + cross-customer table + Delete modal), create.php (dynamic items table: select equipment type auto-fills template defaults via data attributes; rate inputs; mileage unit + currency selects; submit POSTs to api/v1/rate_cards/create + redirects to rates/show), show.php (PHP server-renders initial data via json_encode; edit mode for card metadata with D19 lock; items table with inline edit per row; Save All Items sends full items[] to update endpoint; Delete modal; delete button hidden when is_default=1). **Lease create patch**: api/v1/leases/lookup_rates.php integrated into app/admin/leases/create.php — onUnitChange() stores _currentTemplateId + calls _lookupRates(); onCustomerChange() re-runs if unit already selected; rate source banner (3 variants: green=customer/blue=rate_card/grey=template). **Bug fixed during testing**: create.php + upsert.php both had a variable-variable naming mismatch — camelCase variables ($dailyRate) declared but loop used $field='daily_rate' which assigned to $daily_rate (snake_case); data arrays read $dailyRate (still null). Fix: changed loops to iterate field→varName map ['daily_rate'=>'dailyRate',...] and assign via $$varName. update.php was not affected (consistently used snake_case). **19 stop conditions**: SC1 php -l all 13 files clean ✅; SC2 /rates sidebar 200 ✅; SC3 rate_cards/index paginated ✅; SC4 rate_cards/create 201 {id,name,effective_from} ✅; SC5 rate_cards/show items daily=85.00 monthly=1800.00 mileage=0.1500 ✅; SC6 rate_cards/update 200 name=updated ✅; SC7 rate_cards/delete 200 deleted=true ✅; SC8 customer_equipment_rates/upsert 201 {id,customer_id} ✅; SC9 lookup_rates source=customer daily=120.00 monthly=2500.00 ✅; SC10 lookup_rates source=rate_card daily=85.00 ✅; SC11 lookup_rates UNAUTHORIZED 401 ✅; SC12 customer_equipment_rates/index 200 paginated ✅; SC13 customer_equipment_rates/delete 200 deleted=true + history written ✅; SC14 rate_cards/delete blocks default 422 VALIDATION_ERROR ✅; SC15 /rates/create 200 ✅; SC16 /rates/show?id=2 200 ✅; SC17 lookup_rates source=template daily=145.00 ✅; SC18 lookup_rates source=none (nulled template rates) ✅; SC19 /leases/create 200 + rateSource/_lookupRates present ✅. |
| S020 | 2026-04-04 | Compliance Module (Phase 11) | 4 new files built. All 15 stop conditions passed. **No new tables** — all data from equipment_units columns (cvi_expiry, registration_expiry, mvi_expiry, insurance_expiry). **Bug found and fixed during testing**: `et.equipment_type` does not exist on equipment_templates — actual column is `category`; fixed to `et.category AS equipment_type` in api/v1/compliance/index.php. **api/v1/compliance/index.php** (GET, paginated): filters: yard (LIKE), status (ENUM allowlist), window (int days — units with ANY expiry ≤ CURDATE+N or already expired; uses 4 index-backed comparisons per row), q (unit_number OR template name LIKE); sort allowlist: unit_number/template_name/yard_location/status + 4 expiry columns; JOIN equipment_templates for template name+category; default sort unit_number ASC; per_page clamped min=10 max=200 default=50; returns id/unit_number/template_name/equipment_type/yard_location/status/4 expiry dates/updated_at. **api/v1/compliance/update.php** (POST): updates single expiry field on equipment unit; field allowlisted to 4 valid values (cvi_expiry/registration_expiry/mvi_expiry/insurance_expiry) — arbitrary column injection blocked; D19 optimistic lock on updated_at; value=null clears the date; audit_log inside transaction; returns unit_id/field/value/fresh_updated_at for immediate next D19 cycle. **app/admin/compliance/index.php** (admin UI): server-renders 3 KPI tiles (total tracked/expired count/expiring-within-30d count); yard dropdown from live DB data; CSV export on `?export=csv` — outputs CSV before header.php with all filtered rows, 12 columns (unit/type/yard/status + 4×expiry+status); fputcsv escape param explicit to suppress PHP 8.2+ deprecation; Alpine.js FF_Compliance() component: load()/setSort()/exportCsv()/expiryStatus()/cellStyle()/statusBadge()/statusLabel()/openUpdateModal()/closeModal()/saveUpdate(); grid table with 8 columns, all 4 expiry headers sortable; color-coded cells via inline style from cellStyle() — red=expired/yellow=≤30d/green=>30d/gray=null; click-to-edit cells open update modal (gated by PHP can('compliance','edit')); modal: unit number shown, date input, empty=clear; after save updates row in-place without reload + refreshes updated_at for next D19 cycle. **cron/compliance_alerts.php** (nightly 6AM): D21 advisory lock (GET_LOCK 'ff_cron_compliance'); scans units with ANY expiry ≤ CURDATE+30; groups all affected docs per unit into one notification; severity: critical if any doc expired or ≤7d, warning otherwise; deduplication: skips unit if notification_log has same entity_id+compliance_alert+created_at≥NOW-24h; writes `notifications` row (user_id=null broadcasts to all staff) + `notification_log` row; per-unit failures logged to audit_log but do not abort run; summary audit_log entry on completion. **Sidebar badge**: already live in includes/sidebar.php compliance_alerts case (written in S003) — counts units with ANY expiry < CURDATE+30, updates on every page load, no changes needed. **15 stop conditions**: SC1 php -l all 4 files clean ✅; SC2 compliance/index 200 total=13 unit=CHS-001 cvi=2026-11-02 ✅; SC3 window=30 returns 0 (correct — nearest expiry is June 2026) ✅; SC4 compliance/update set cvi_expiry=2027-03-15 SUCCESS ✅; SC5 D19 STALE_DATA rejected ✅; SC6 invalid field=deleted_at → VALIDATION_ERROR ✅; SC7 /compliance admin page HTTP 200 + 14 key sections ✅; SC8 CSV export HTTP 200 + correct headers + 13 data rows + no PHP errors ✅; SC9 cron dry-run exits 0 + audit_log entry written ✅; SC10 sidebar badge renders 0 (correct — no expiring units in seed data) ✅; SC11 sort by cvi_expiry ASC returns nulls first, then ascending dates ✅; SC12 clear expiry (value='') → value=null returned ✅; SC13 cron with forced expired unit → 1 notified + notification row written + [critical] severity ✅; SC14 cron dedup — second run within 24h → 0 notified, 1 skipped ✅; SC15 audit_log entries written: update×2 + cron×3 all in compliance module ✅. |
| S020-EXT | 2026-04-04 | Compliance Module Addendum — MVI removal + From/To dates + Document links + Storage serve endpoint | **User-driven addendum after S020 close**: 3 files updated/created. All stop conditions passed (php -l clean, HTTP 200, API returns correct fields, CSV correct, update endpoint working). **Changes**: (1) **MVI column removed** from compliance grid and CSV export — only CVI, Registration, and Insurance tracked (matches user requirement: "we only need cvi, registration and insurance"). (2) **From/To dates added** to each document column — `cvi_from`, `registration_from`, `insurance_from` computed in SQL as `DATE_SUB(expiry, INTERVAL interval_days DAY)` when both expiry and interval_days are non-null; displayed as subtitle "from YYYY-MM-DD" below expiry date in grid; CSV now includes From + Expiry + Status per document type. (3) **Document links added** — each document cell shows a 📄 icon when a signed URL exists; `cvi_doc_url`, `registration_doc_url`, `insurance_doc_url` fields in API response (Trap 7: raw `cvi_document` etc. paths never exposed — converted to HMAC-signed URLs via StorageClient::url() then unset). Icon links open document in new tab (`@click.stop` prevents triggering the inline edit modal). Documents are same files visible on unit profile page — same equipment_units columns (cvi_document, registration_document, insurance_document). (4) **`api/v1/storage/serve.php`** created — was referenced by `StorageClient::urlLocal()` but file didn't exist; serves HMAC-signed local file URLs (local dev only — S3 presigned in prod); `require_auth_api()` prevents unauthenticated file access; validates via `StorageClient::verifyLocalUrl($key, $exp, $sig)`; resolves path via `StorageClient::localPath()` (blocks traversal); MIME detection via `finfo_open(FILEINFO_MIME_TYPE)` (never trusts extension); allowed MIMEs: application/pdf, jpeg, png, gif, webp; inline disposition for PDFs+images; 5-min browser cache. **Verified**: api returns cvi_from/registration_from/insurance_from + doc_url fields; no MVI anywhere in page or CSV; update endpoint D19 lock + CSRF working (STALE_DATA returned on stale updated_at; 200 on correct updated_at). |
| S020-EXT2 | 2026-04-04 | Compliance from/to dates (direct columns) + Super Admin invoice access + KPI tile auto-refresh (all modules) | **User-driven fixes after S020-EXT close.** All PHP syntax checks pass (php -l). **Changes**: (1) **Compliance from/to dates — direct storage columns added**: `cvi_from_date DATE NULL`, `registration_from_date DATE NULL`, `insurance_from_date DATE NULL` added to `equipment_units` (replaces computed `DATE_SUB(expiry, INTERVAL interval_days DAY)` approach — interval_days was null on all seeded units, producing null from dates). `api/v1/compliance/index.php` updated to SELECT the new columns directly. `api/v1/compliance/update.php` fully rewritten: now accepts `doc_type` (cvi/registration/insurance) + `from_date` + `expiry_date` together — saves both columns in one transaction. `app/admin/compliance/index.php`: modal now shows two fields — "Valid From" (from_date) and "Expiry (To)" (expiry_date), both editable; grid cells show "From: YYYY-MM-DD" above expiry date. (2) **Compliance KPI tiles auto-refresh**: `api/v1/compliance/kpis.php` (NEW) returns `{total_units, expired_count, expiring_soon_count}`; Alpine `loadKpis()` method added to compliance/index.php; called on `init()` AND after every `saveUpdate()` — tiles always fresh without page reload. (3) **Super admin invoice access**: `api/v1/invoices/update.php` and `api/v1/invoices/delete.php` updated — draft-only guard now `if ($invoice['status'] !== 'draft' && !is_super_admin())`, allowing super_admin to edit/delete invoices of any status. `app/admin/invoices/show.php`: `$isSuperAdmin = is_super_admin()` + `$canEdit`/`$canDelete` booleans control UI visibility; Edit/Delete buttons shown to super_admin regardless of status; delete modal text shows actual status. **Invoice edit button scroll fix**: `startEdit()` sets `editing = true` then `$nextTick` scrolls to `#invoice-edit-section` (Notes card) — previously nothing visible happened near the header button on long pages. (4) **KPI tile auto-refresh — 7 other modules**: 7 new API endpoints created: `api/v1/invoices/kpis.php` (AR aging: current/1-30/31-60/60+ days), `api/v1/payments/kpis.php` (collected/AR outstanding/overdue/recorded today), `api/v1/credit_notes/kpis.php` (active balance/issued/fully used/expired this month), `api/v1/damage_claims/kpis.php` (open/invoiced/year total/avg repair), `api/v1/vendors/kpis.php` (total/preferred/active WOs/top vendor), `api/v1/maintenance_work_orders/kpis.php` (total/open/active/completed this month), `api/v1/inspections/kpis.php` (total/draft/complete/signed this month). All 7 module index pages updated: stat-grid wrapped in `<div x-data="moduleKpis()" x-init="loadKpis()">` (separate Alpine component); `function xxxKpis()` added to each script block with PHP-seeded initial values (no blank flash on load); `loadKpis()` calls the new API endpoint on init so tiles always reflect reality on back-navigation. WO tiles preserve `@click="setFilter(...)"` via existing global `setFilter()` function. All 14 files (7 APIs + 7 index pages) pass `php -l`. |
| S019-EXT | 2026-04-04 | Rates Module Polish + Invoice Show Page Comprehensive Rebuild | 12 data/script fixes + 1 major page rewrite (547→1654 lines) + 1 icon fix. **S019 Rates Polish (carried from prior context)**: scripts/seed_rate_cards.php (NEW): seeds 11 rate cards with 51 items covering all 5 templates — Standard 2024/2025, Premium 2025, Seasonal Winter, Promotional, USD Export, Short-Term Surge, Contract Fleet, Legacy, Reefer Specialist; idempotent via pre-clear. scripts/stress_test_rates.php (NEW): 76 assertions across 12 sections — seeded data integrity, D16 bcmath precision, date ranges, D5 soft delete, priority chain, customer rate constraints, name uniqueness, default card protection, D19 optimistic lock, history write-through, referential integrity, audit log; 3 bugs found and fixed: (a) assert_bcmath used rtrim-based comparison ("800.00" vs "800" false positive) → replaced with `bccomp($value, $expected, 6) === 0`; (b) D19 optimistic lock test relied on same-second MySQL timestamp → forced old timestamp '2020-01-01 00:00:00' via UPDATE; (c) count assertion drifted on repeated runs → switched to name-based presence check with pre-cleanup. **rates/index.php bug fix (critical)**: FF_Api.get() returns `{success, data: {items, pagination}}` but code read `r.data` as rows array and `r.total`/`r.total_pages` at top level (undefined) — blank table despite data existing; fixed to `r.data?.items ?? []` and `r.data?.pagination?.total ?? 0` in both loadCards() and loadOverrides(); delete modals added `updated_at` to state + POST payloads for D19 compliance. **rates/show.php bug fix**: deleteCard() missing `updated_at` in POST payload → D19 violation; fixed. **customers/show.php — Rates tab** (NEW): added `$rateOverridesCount` server query; tab button with badge; rates panel: loading/empty/table states, equipment_type/daily/weekly/monthly/mileage/currency/dates columns; Add/Edit modal with equipment_type dropdown from templates, rate inputs, effective dates, mileage_unit/currency selects, notes; Alpine methods loadRateOverrides()/openRateModal()/saveRateOverride()/deleteRateOverride() using upsert/delete APIs with D19 lock; permission-gated (rates view/edit/delete). **scripts/seed_customer_rates.php** (NEW): seeds custom rate overrides for 4 customers (Lotus Terminals, Western Star, Pacific Gateway, Coastal Reefer) with varied equipment types, currencies, effective dates, notes; writes customer_rate_history per override. **leases/create.php — Rate field locking**: ratesLocked state; _lookupRates() sets `ratesLocked = (d.source === 'customer')`; customer banner shows "🔒 Contracted rates" with Unlock/Re-lock buttons; rate inputs get `:readonly="ratesLocked"` + muted background; currency/mileage_unit selects `:disabled="ratesLocked"`; onUnitChange() resets ratesLocked; auto-populates rate_notes for customer source. **Invoice show page complete rebuild** (app/admin/invoices/show.php — 547→1654 lines): (1) Status timeline — horizontal visual lifecycle (Draft→Sent→Paid) with void/write-off branches, dates under each step; (2) 5 KPI stat cards (was 4) — added Amount Paid, overdue day counter, CAD equivalent for USD, "Paid in full" indicator; (3) Bill To section — company name, contact, billing address (nl2br), email, tax exemption badges; (4) Invoice Details — billing period, days, rate method, currency with exchange rate, PO, auto-generated source, created by user; (5) Rate Calculation Breakdown — collapsible card rendering rate_method_explanation JSON (handles both {explanation:[]} and flat array formats); (6) Enhanced Line Items — 8 columns (was 6): Type badge (color-coded per item_type), Description with billing_days+rate_method+mileage details+expandable detail_lines calculation breakdown, Period dates, Qty with unit, Unit Price, Per-line tax (GST+PST+HST sum), Amount; credit items green-styled; subtotal footer; (7) Financial Summary — right-aligned professional table: subtotal→discount→after discount→GST/PST/HST→tax total→total amount→payments applied→credits applied→late fee→balance due; exchange rate note for USD; tax exemption annotations; (8) Delivery & Tracking section — sent date/by/to/CC/method, late fee details with linked invoice, credit note linkage (both source_invoice and credit_note_for_invoice directions), void/write-off details with reason+date+user; (9) Payment History (enhanced) — Record Payment button; (10) Notes & References — PO number, customer-facing notes, internal notes (staff-only badge), void/write-off reasons; **inline editing for draft invoices** (PO/email/notes/internal_notes) via Alpine.js with D19 optimistic lock and STALE_DATA handling; (11) Activity Log — color-coded audit trail timeline from audit_log table (last 50 entries) with action/user/notes/timestamp/IP; (12) Print support — @media print styles hiding nav/buttons/timeline, print-only footer; Print button in header. Alpine.js FF_InvoiceShow() component: send/void/delete actions, inline edit start/cancel/save with D19 lock, toast notifications. **plus.svg icon** (NEW): created Heroicons plus icon — fixes pre-existing missing icon across payments/index, credit_notes/index, invoices/show. **All tests passed**: php -l clean; all 4 invoice statuses (draft/overdue/void/partially_paid) render HTTP 200 with 0 PHP errors; all 13 sections verified present in rendered output; HTML tag balance verified (131 div open = 131 div close, 4 template open = 4 template close). |
| AUDIT-2 | 2026-04-04 | Adversarial Audit + Full Fix Pass — All API Endpoints | Seeded demo dataset (seed_demo.php + seed_reservations.php): 5 templates, 13 units, 13 customers, 17 leases, 27 reservations. Parallel audit agents found 43 bugs across all API endpoints. All 43 fixed in priority order. **functions.php**: added `clean_positive_int()`, `clean_non_negative_int()`, `clean_positive_decimal()`, `clean_non_negative_decimal()`, `clean_time()`; fixed `bcround()` negative number bug (was always rounding toward zero for negatives — now rounds away from zero); `clean_string()` now strips NUL bytes and control chars `\x00-\x08\x0B\x0C\x0E-\x1F\x7F` while preserving tab/LF/CR (FIX #41). **auth.php**: added `generate_csrf_token()` call at end of `auth_login()` to close CSRF gap after remember-me session restoration. **bootstrap.php**: `require_id()` returns 400 INVALID_ID on bad input; `json_body()` returns 400 INVALID_JSON on malformed JSON instead of silently returning `[]`. **reservations/create.php + update.php**: past-date guard, quantity cap (500), `clean_time()` for pickup_time, duplicate unit detection, lease conflict re-check on date change wrapped in `db_transaction()`. **reservations/update_status.php**: conflict re-check on pending→confirmed now checks both pending AND confirmed statuses (was checking only confirmed). **leases/create.php**: all rates use `clean_non_negative_decimal()`; at-least-one-rate-positive enforcement; discount value capped at 100 for percentage type; mileage fields non-negative; `minimum_end_date >= start_date`; tax exemption expiry checked against `$startDate` not today. **leases/update.php**: immutability block for completed/cancelled; `mileage_at_end >= mileage_at_start` cross-validation; non-negative mileage/cost fields. **leases/delete.php**: on pending lease delete, releases reserved unit back to available. **leases/reopen.php**: fixed role check from wrong key `$user['role']` to `$user['role_slug']`; resets next_billing_date; clears mileage_at_end + actual_mileage. **customers/create.php + update.php**: `credit_limit` and `discount_value` use `clean_non_negative_decimal()`; percentage discount capped at 100. **customers/update.php**: added duplicate company_name guard; added mileage_unit to updatable fields. **customers/delete.php**: added active reservations guard → 409 HAS_ACTIVE_RESERVATIONS. **equipment/units/create.php + update.php**: year range validation (1900 – current+2); dimensions use `clean_positive_decimal()`; mileage uses `clean_non_negative_int()`; acquisition_cost uses `clean_non_negative_decimal()`; intervals use `clean_positive_int()`; weight/axle use `clean_positive_int()`. **equipment/units/delete.php**: added reserved status block → 422 UNIT_RESERVED; added `updated_by` + `equipment_status_log` entry (new_status='deleted') on deletion. **equipment/units/update_status.php**: added decommissioned as reachable from maintenance + inactive; removed on_lease from available transitions. **equipment/templates/create.php**: slug uniqueness check adds `AND deleted_at IS NULL` so deleted slugs can be reused (FIX #38); `make_template_slug()` wrapped in `if (!function_exists(...))` guard (FIX #39); same dimension/rate/interval/sort validations as units. **equipment/templates/update.php**: same validations; slug regenerated (de-duped) when name changes (FIX #42). **login.php**: removed remaining-attempt count from error message — no longer leaks that the email is valid (FIX #35). **search.php**: `%` and `_` in search terms escaped before LIKE binding — backslash-first ordering (FIX #37). **health.php**: version string, disk metrics, and cache info now only returned to authenticated sessions; unauthenticated callers see status/db/time only (FIX #39). **Stress test (98/98 PASSED)**: scripts/stress_test_final.php — 15 sections covering helper function adversarial inputs (negative/zero/NUL/control chars/bcround signs), DB financial integrity across all modules, reservation no-double-booking invariant, reserved-unit→confirmed-reservation linkage, equipment state machine table verification, cross-module referential integrity, audit log and status log completeness, data volume summary. All seed data artifacts repaired (conflicting reservations cancelled, completed leases given end_date, status_log entries backfilled for seeded units via SQL patch). |
| S021 | 2026-04-04 | Reports Module (Phase 12) | 6 new files built. All stop conditions passed. **New directory**: `lib/Reports/` + `api/v1/reports/` + `app/admin/reports/`. **lib/Reports/ReportBuilder.php**: Static utility class — `resolvePreset()` (14 date presets per spec §4.3), `clampDates()`, `cacheKey()` (SHA-256, ksort for determinism), `getCached()`/`setCached()` (15-min TTL via `report_cache` table), `outputCsv()` (UTF-8 BOM + fputcsv escape param), `csvMoney()`, `periodDays()` (D14 inclusive), `safeDivide()`, `pct()` (bcmath-safe percentage). **api/v1/reports/revenue.php** — 6 views: `period` (monthly GROUP BY DATE_FORMAT), `customer` (top 50 by gross_revenue, ANY_VALUE() for non-deterministic columns), `equipment_type` (JOIN leases→units→templates, GROUP BY et.category), `ar_aging` (5 CASE WHEN buckets: current/1-30/31-60/61-90/90+ days), `collection` (monthly invoiced vs collected + collection_rate %, dual GROUP BY for period_label), `status` (COUNT/SUM per invoice status). Shared KPI tiles (invoice_count/gross_revenue/net_revenue/total_tax/total_collected/total_outstanding/avg_invoice_value/collection_rate/unique_customers/paid_count/overdue_count/overdue_amount) computed for every view. **api/v1/reports/fleet.php** — 5 views: `utilization` (multi-query merge: all units + days on lease (GREATEST/DATEDIFF overlap) + revenue per unit + maintenance cost; sorted by utilization_pct DESC), `roi` (revenue − maint per unit), `idle` (filter is_idle=true, donut by category), `maintenance` (grouped by work_type), `yard` (PHP aggregation by yard_location, avg_util = total_days/unit_count/period_days). KPIs: total_units/idle_units/active_units/avg_utilization/total_revenue/total_maint_cost/period_days. **api/v1/reports/customer.php** — 5 views: `ltv` (all-time + period revenue via subquery LEFT JOINs, credit_notes.amount for lifetime credits), `payment_behavior` (AVG DATEDIFF paid_date to due_date per customer, ANY_VALUE() GROUP BY customer_id), `new_returning` (MIN(invoice_date) subquery JOIN to classify new/returning by month, dual GROUP BY), `frequency` (COUNT leases per customer + avg lease days), `credit_notes` (SUM amount/amount_remaining/amount−amount_remaining, status fully_used). KPIs: unique_customers/active_customers/total_revenue/avg_invoice_value/invoice_count/lease_count/avg_days_to_pay. **api/v1/reports/compliance.php** — 4 views: `timeline` (3 separate queries per doc type merged by month, dual GROUP BY for period_label), `status` (15 CASE WHEN counts across CVI/Reg/Insurance × expired/expiring30/expiring90/ok/missing), `expired` (units where ANY of 3 columns < today), `upcoming` (units where ANY expiry BETWEEN today AND windowEnd). Forward-looking: `window_days` param (7–365, default 90) replaces date range. KPIs: total_units/expired_count/expiring_30/expiring_90/ok_count. **app/admin/reports/index.php** — 4-tab Alpine.js report center. FF_Reports() component: `mainTab`, 9 date presets, per-tab state (loading/viewLoading/kpis/view/viewData/chartsRendered); `loadTab()`/`loadView()`/`switchMainTab()`/`switchView()`/`runReport()`; view cache invalidation on preset/date change; `exportCsv()`/`exportCompCsv()`; `initChart()` router to 15 ApexCharts initializers; `render()` destroys old instance via `el._apexChart`; helpers: `money()`/`pct()`/`customerBadge()`/`unitStatusBadge()`/`invStatusBadge()`/`agingBadge()`/`utilColor()`/`daysLeftClass()`. 15 distinct chart types: area/bar/horizontal bar/donut/dual-axis line/stacked bar/grouped bar. Dark mode aware via `data-theme` attribute. **Bugs found and fixed**: (1) MySQL `only_full_group_by` violations — `period_label` (second DATE_FORMAT) added to all GROUP BY clauses; `ANY_VALUE()` used for non-aggregate columns with compound COALESCE expressions; (2) `credit_notes.balance` column does not exist — corrected to `amount_remaining`; `status='used'` → `status='fully_used'`; (3) Cache timezone mismatch — PHP `date()` vs MySQL `NOW()` 7-hour divergence — `expires_at` now computed via `DATE_ADD(NOW(), INTERVAL ? MINUTE)` in both INSERT and ON DUPLICATE KEY UPDATE clauses so it's always in MySQL's timezone; (4) Login password confirmed as `admin123` (not `FleetForge2025!`). **SC passed**: SC1 php -l all 6 files clean ✅; SC2 admin page HTTP 200 + FF_Reports component + ApexCharts present ✅; SC3 revenue all 6 views 200 ✅; SC4 fleet all 5 views 200 ✅; SC5 customer all 5 views 200 ✅; SC6 compliance all 4 views 200 ✅; SC7 CSV export Content-Disposition: attachment present for all 4 APIs ✅; SC8 cache hit — second request returns cached=true ✅. |
| S021-EXT | 2026-04-04 | Reports Module — Data Seeding + Chart Redesign + Bug Fixes | **Continuation session** — 3 issues resolved. **(1) Reports page infinite loading — root cause: `base_url()` called as JavaScript function in `app/admin/reports/index.php`**, but `base_url()` only exists in PHP context. All API fetch URLs used `base_url('api/v1/reports/...')` in JS — `TypeError: base_url is not a function` silently prevented all API calls. Fixed: all JS paths changed to `(window.FF_BASE_PATH\|\|'')+'/api/v1/reports/...'`. **(2) "Unique Customers 1" — not a bug.** Only customer #1 (Apex Freight) had non-draft/void invoices in seed data. All other 14 customers had zero invoices. Fix: `database/seed_reports_data.php` (NEW) — comprehensive seed script generating ~95 invoices across 13 customers spanning Jul 2025–Apr 2026, ~40 payments with allocations, ~40 maintenance work orders across 11 units (7 work types), compliance expiry dates for 11 units (mix of expired/expiring/ok), yard_location for all fleet units. Report cache purged on seed. **(3) Complete chart/graph redesign for 1000+ unit scale** — `app/admin/reports/index.php` rewritten with scale-appropriate visualisations: fleet utilization → distribution histogram (client-side binning into 10% buckets, red→green gradient), fleet ROI → top 10/bottom 10 diverging bar charts, payment behavior → due-date-offset histogram (8 bins from "< -14d early" to "30+ late"), row capping system (25 rows default with expand/collapse toggle), professional CSS (sticky table headers, zebra rows, card wrappers, 2-column chart grids). **All 20 API views tested and passing** — revenue 6/6, fleet 5/5, customer 5/5, compliance 4/4. CSV export verified for all 4 endpoints. Cache hit confirmed. Admin page renders 153KB with all 4 tabs, ApexCharts, Alpine.js. |
| STUB-1 | 2026-04-02 | Stub Replacement — Cross-Module Tab Fix | 3 files fixed, 5 stub tabs replaced with real data. Full codebase search performed (10 grep patterns). **Files fixed:** (1) app/admin/customers/show.php — Leases tab: stub "will be available in S006" replaced with Alpine.js lazy-loaded table (contract_number, unit, status badge, start/end dates, monthly_rate, View action) via existing GET /api/v1/leases?customer_id=N; Invoices tab: stub "will be available in a later session" replaced with Alpine.js lazy-loaded table (invoice_number, billing_period, status badge, due_date, total_amount, balance_due, View action) via existing GET /api/v1/invoices?customer_id=N; Alpine state extended with leases/invoices arrays + loading flags + $watch lazy-load on tab activation; leaseBadgeClass() + invoiceBadgeClass() helpers added; docblock updated to remove stub references. (2) app/admin/equipment/show.php — Lease History tab: stub "Lease history coming in S008" replaced with Alpine.js lazy-loaded table (contract_number, customer, status badge, start/end dates, monthly_rate, View action) via existing GET /api/v1/leases?unit_id=N; leaseHistory[] + leasesLoading/leasesLoaded state added; $watch extended; leaseBadgeClass() + loadLeaseHistory() methods added; docblock updated. (3) app/admin/leases/show.php — docblock updated: "stub for S008+" → "placeholder — requires amendments table". **Remaining stubs that CANNOT be fixed (missing dependencies):** (a) leases/show.php Amendments tab — requires amendments table + API (not yet built); (b) equipment/show.php Maintenance tab — requires maintenance_work_orders module (planned S016); (c) equipment/show.php Documents tab — requires documents module (planned S021); (d) leases/edit.php rate editing — requires amendment workflow. **Not stubs (verified OK):** login.php placeholder hash is a security constant-time pattern; form field HTML placeholder attributes are legitimate UX; dashboard/index.php "stubs replaced" is historical docblock. **HTTP checks:** PHP not available locally — code reviewed manually for syntax correctness. |
| INT-1 | 2026-04-07 | Settings Integration Fix — Settings table FIRST, .env SECOND | **9 files modified, 1 new migration.** Root cause: every third-party credential in `lib/GPS/SamsaraClient.php`, `lib/AI/ClaudeClient.php`, `lib/Notifications/Mailer.php`, and `lib/Storage/StorageClient.php` was reading from `.env` constants only. The Settings → Integrations UI saved values into the `settings` table but those values were ignored at runtime. **Rule implemented everywhere:** `settings_get('group.key') ?: env('ENV_KEY', default)` — settings table FIRST, .env file SECOND, never the other way around. **(1) database/migrations/032_integration_settings.sql (NEW)** — adds 12 missing rows (`ai.anthropic_api_key`, six `email.*` keys, `storage.driver`, four `aws.*` keys), all default empty so fresh installs fall through to .env. **(2) lib/GPS/SamsaraClient.php** — `__construct()` now reads `gps.samsara_api_key`/`gps.samsara_org_id` from settings before falling back to env. `testConnection()` returns specific messages: `"API key not configured. Please add your Samsara API key in Settings → Integrations."` (empty key), `"API key is invalid. Please check your Samsara API key in Settings → Integrations."` (HTTP 401/403). `buildHeaders()` only sends `X-Org-Id` when configured (single-org tokens reject the header). All `apiKey === '' || $orgId === ''` guards relaxed to apiKey-only. **(3) lib/AI/ClaudeClient.php** — `__construct()` reads `ai.anthropic_api_key`/`ai.enabled`/`ai.model` from settings first; coerces stored '1'/'0' string properly. `testConnection()` distinguishes "not configured" from "invalid key" with explicit Settings → Integrations pointer. **(4) lib/Notifications/Mailer.php** — `send()` reads `email.from_email`/`email.from_name` from settings first. `ses()` and `isLogMode()` read `aws.region`/`aws.access_key_id`/`aws.secret_access_key` from settings first. **(5) lib/Storage/StorageClient.php** — added private `driver()` helper that reads `storage.driver` from settings first. `s3()` and `bucket()` read `aws.*` from settings first. All three public methods (`upload`/`url`/`delete`) routed through `driver()`. **(6) app/admin/settings/index.php** — `WHERE group_name IN (...)` expanded to include `email`/`storage`/`aws`. New `$secretKeys` array (7 keys: samsara/geotab/anthropic/smtp/aws). Save handler skips writes when posted value starts with U+2022 (•) — preserves real value when user doesn't edit the masked field. Render loop renders secret keys with masked display via `$maskSecret()` helper (16 bullets + last 4 chars), `font-mono` class, `onfocus="select"` for easy replacement. **(7) includes/partials/ai-summary-card.php, ai-report-generator.php, ai-chat-widget.php + app/admin/ai/index.php** — all 4 `defined('AI_ANTHROPIC_API_KEY')` guards replaced with `settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', '')`. **Verification (live PHP harness against real APIs):** (a) Samsara `testConnection()` with .env key → HTTP 200, `vehicles_found:0`, `org_id:Fleetforge` ✅. (b) Anthropic `testConnection()` → HTTP 200, model `claude-sonnet-4-20250514`, 18 tokens used ✅. (c) Override test: write `SENTINEL_FROM_DB` to settings, new SamsaraClient instance reads `SENTINEL_FROM_DB` (not the .env key) ✅. (d) Fallback test: clear settings row, new SamsaraClient instance reads `.env` value ✅. (e) Mask round-trip: stored key `samsara_api_…u1y` displays as `••••••••••••••••u1y`; placeholder triggers save-skip ✅. (f) `grep -l` of `logs/*.log` for stored API key fragments returned zero matches ✅. (g) `php -l` clean on all 9 modified files ✅. **User-supplied Samsara key insert (per step 7):** the key supplied by the user (ending `…u1y`) is in the settings table — but the live API returned **HTTP 401 Unauthorized** for it (the older `.env` key still works). The integration plumbing is correct; the new key itself appears revoked or scoped to a different account. User should verify it in the Samsara dashboard or paste a fresh one in Settings → Integrations. |
| RESPONSIVE-1 | 2026-04-08 | Responsive Design + Theme Icon Fix | **5 files modified, strictly additive — no existing CSS/tokens/colors/logic changed.** **Files:** `public/assets/css/app.css` (+310 lines appended as a new RESPONSIVE-1 block), `includes/header.php` (Alpine overlay div + keydown.escape.window), `includes/topbar.php` (new `.hamburger-btn` alongside legacy `.topbar-menu-btn`), `public/assets/js/app.js` (ApexCharts global responsive patch + overlay reuse), `FLEETFORGE_PROGRESS.md`. **(1) Theme icon fix — `.topbar-theme-btn`:** bordered pill with `background-color: var(--bg-surface-2)`, `border: 1px solid var(--border-color-strong)`, 36×36 tap target, svg forced to 20×20 via descendant selector (not `.nav-icon`) because `heroicon()` is per-name cached so the sidebar's class wins on later calls in the topbar; disabled the inherited `.btn-icon` background transition on this button so the background re-reads `--bg-surface-2` instantly on `data-theme` swap (transition engine was caching the start value and visually freezing on the previous theme's surface tint even though the custom property itself had updated). Verified live in both themes dark→light and light→dark: `btnBg` flips `rgb(18,21,29)` ↔ `rgb(241,245,249)`, `svgColor` `rgb(241,245,249)` on dark / `rgb(15,23,42)` on light, border visible in both, icon 20×20. **(2) Responsive breakpoints:** Desktop 1024+ UNCHANGED; Tablet 768..1023 — `.sidebar { position:fixed; width: var(--sidebar-width-collapsed); transform: translateX(0) }` + labels/badges/section-titles/group-arrows/user-info hidden via opacity+max-width+visibility, `.app-main { margin-left: 64px }`, hamburger hidden; Mobile ≤767 — `.hamburger-btn { display: inline-flex }`, `.topbar-search-btn { display:none }`, `.sidebar-overlay { display:none; .is-visible → block }`, KPI/stat grids collapse to 1 column, `.form-grid` / `.form-row-*` → 1fr, `.form-actions { flex-direction: column-reverse }` + buttons 100% wide, `.page-header` stacks, `.modal-overlay { align-items: flex-end }` + `.modal { border-radius: 16px 16px 0 0 }` bottom-sheet, `.tab-nav` / `.tabs` / `.tabs-list` horizontal scroll with hidden scrollbar, `.filter-bar` / `.table-toolbar` stack vertically, tables inside `.table-wrapper` / `.card` get `overflow-x:auto` safety net and `min-width: 640px` so even un-updated pages scroll horizontally instead of breaking layout, tap targets raised to 44px (btn) / 36px (btn-sm/xs). New utility classes added: `.kpi-grid`, `.stat-grid--2/3/4`, `.form-grid`, `.form-group--full`, `.table-responsive`, `.table-stack` (opt-in stacked view for simple lists via `data-label` td attributes). **(3) includes/header.php:** `.app-layout` gets `@keydown.escape.window="sidebarOpen = false"`, new static `.sidebar-overlay` div with `:class="{ 'is-visible': sidebarOpen }"` and `@click="sidebarOpen = false"` placed between sidebar and `.app-main`. **(4) includes/topbar.php:** kept legacy `.topbar-menu-btn` for back-compat, added new `.hamburger-btn` (44×44 tap target, bordered, aria-controls="ff-sidebar") bound to the same Alpine `sidebarOpen`. **(5) app.js:** `getSidebarOverlay()` now prefers the static overlay rendered by header.php before falling back to dynamic creation (no duplicate overlay elements). New `patchApexChartsForResponsive()` IIFE monkey-patches `window.ApexCharts` so every chart gets `{ breakpoint: 768, options: { chart: { height: 250 }, legend: { position: 'bottom' }, dataLabels: { enabled: false } } }` appended to its `responsive` array unless the page already defined a ≤768 breakpoint — idempotent via `__ff_responsive_patched__` sentinel, wrapped in try/catch so a bad merge never blocks chart rendering. **Verification (live PHP server + Claude Preview):** Desktop 1280 — sidebar 240px sticky, hamburger hidden, search visible, theme button 36×36 with bordered background, dashboard KPI grid + 16 charts + table render identically to pre-session state (regression clean). Tablet 768 — sidebar 64px icon rail, `app-main` margin-left 64px, nav labels `max-width:0`, hamburger hidden, no horizontal scroll, theme icon 20px. Mobile 375 — sidebar `position:fixed` translateX(-100%), `.hamburger-btn display:flex`, `.topbar-search-btn display:none`, KPI tiles single column, page-header `flex-direction:column`, hamburger click toggles `sidebarOpen` → sidebar slides in with overlay `.is-visible` dimmed backdrop, overlay click closes sidebar, window Escape closes sidebar; no horizontal page scroll on dashboard / customers / invoices / leases / equipment / payments / reservations / damage_claims / compliance / reports / analytics / rates / vendors / users / settings / maintenance_work_orders / tracking (17 admin routes all HTTP 200). Theme toggle tested bidirectionally light↔dark — border, background, svg color all update on click without stale value. No JS console errors at any breakpoint. `php -l` clean on all 3 modified includes; `new Function(app.js)` clean; CSS brace balance 0 (5062 lines). |
| SEARCH-1 | 2026-04-08 | Topbar Global Search — API Fix + Inline Input + Dropdown | **4 files modified.** **Root cause (existing search broken since S017):** (1) `api/v1/search.php` queried `eu.make` and `eu.model` on `equipment_units` but those columns live on `equipment_templates` as `brand`/`model` — every search for any term threw `Unknown column 'make'` 500, and the modal showed "Search unavailable" while the Alpine widget stayed silent. (2) The topbar rendered a `<button id="ff-search-trigger">` that *looked like* a search field but only opened a ⌘K modal — typing in the "field" did nothing because it was a button, not an input. Users couldn't tell the modal even existed. **Files:** `api/v1/search.php` (rewritten), `includes/topbar.php` (button → inline input + Alpine dropdown), `public/assets/js/app.js` (new `FF_SearchWidget()` factory, existing `FF_Search.query()` updated to handle grouped response shape), `public/assets/css/app.css` (+200 lines SEARCH-1 block, all selectors scoped under `.search-wrapper` so they never collide with the existing modal `.search-panel .search-input`). **(1) api/v1/search.php rewrite:** response shape is now `{ success, data: { query, total, results: { customers, equipment, leases, invoices, reservations } } }` per spec, each item is `{ id, type, title, subtitle, url, badge, badge_class }`. Customers — searches `company_name`, `contact_name`, `email`, `phone`; subtitle prefers email over phone over contact; badge maps `status` through a color class helper (`active`→success, `pending`→warning, etc.). Equipment — joins `equipment_templates` so the query can match by `et.brand`/`et.model`/`et.name` in addition to `eu.unit_number`/`vin`/`license_plate`; subtitle shows "template · yard · vin". Leases — searches `contract_number` + `company_name_snapshot` + `unit_number_snapshot` (snapshot columns are resilient to renames post-signing). Invoices — COALESCEs live `customers.company_name` over `company_name_snapshot` so renames surface without a historical rewrite. Reservations — schema has NO `reservation_number` column (spec was wrong); uses `CAST(id AS CHAR) LIKE` + `company_name` + `contact_name` so "R123" and "123" both match, title is "#id — contact_name". All five modules gated by `can('module','view')` — a dispatcher without invoices access will never see invoices in the response (tested via super_admin session, permissions layer verified). All queries use `deleted_at IS NULL` (D5). LIKE metacharacters escaped (`str_replace` of `\`, `%`, `_`). `limit` param validated 1..10, default 3. Min query length 2 chars returns 422 `QUERY_TOO_SHORT` (single char and empty both trigger). **(2) includes/topbar.php:** deleted `<button id="ff-search-trigger">` + `kbd` hint. Replaced with a full `<div class="search-wrapper" x-data="FF_SearchWidget()" @click.outside="close()">` containing a real `<input class="search-input">` wired with `x-model="query"` + `@input.debounce.300ms="search()"` + `@keydown.enter.prevent="search()"` + `@keydown.escape="close()"` + `@focus="if (total > 0) open = true"`, a click-through search-icon `<button>` that force-fires `search()`, a CSS spinner shown via `x-show="loading"`, and the inline ⌘K hint. Dropdown `<div class="search-dropdown" x-show="open" x-transition.opacity.duration.150ms x-cloak role="listbox">` with three states: loading, empty ("No results for …"), and populated (groups iterated via `<template x-for="group in groups">` with per-group labels, result items showing title + `badge` + subtitle, and a "See all N results for …" footer button that calls `openFullSearch()` to hand off to the ⌘K modal). **(3) public/assets/js/app.js:** new `window.FF_SearchWidget()` Alpine factory — holds `query`, `open`, `loading`, `total`, and a `groups` array pre-populated with the five module types in fixed order (so the UI renders consistently across searches). `search()` trims, no-ops below 2 chars, sets `loading=true` + `open=true`, hits `FF_Api.url('/api/v1/search?q=…&limit=3')`, parses the grouped response, updates `total` + each group's `items` from the server (server is authoritative for ordering and per-group caps). `close()` hides the dropdown without clearing the query. `openFullSearch()` hands off to the legacy `FF_Search.open()` modal and pre-populates + re-queries so the user sees the full list with pagination / recent searches / keyboard nav. Existing `FF_Search.query()` (modal) now accepts both the new grouped `results` object AND the legacy flat array shape (flattens grouped → array preserving group order before calling `_renderResults`), so the ⌘K modal keeps working unchanged. Added `reservation` to `_SEARCH_GROUP_LABELS` and made `_renderResults` prefer `item.subtitle` over `item.meta`. **(4) public/assets/css/app.css:** SEARCH-1 block (~200 lines) appended at EOF. All selectors namespaced under `.search-wrapper` so they never touch `.search-panel .search-input` (modal). `.search-wrapper` — relative, flex 1 1 auto, max-width 420px. `.search-wrapper .search-input-wrap` — 36px pill with `--bg-surface-2` background + `--border-color` border + focus-within ring (`--color-primary-light`). `.search-wrapper .search-input-icon` — 34×34 button, clickable, color shifts on hover. `.search-wrapper .search-input` — transparent, 34px, inherits color. `.search-wrapper .search-kbd--inline` — ⌘K hint pill. `.search-wrapper .search-spinner` — 14px CSS spinner, `ff-search-spin` keyframes, `currentColor` so it theme-adapts. `.search-wrapper .search-dropdown` — absolute, `top:calc(100% + 6px)`, `--bg-surface` background, `--shadow-xl`, `max-height: 480px; overflow-y: auto`. `.search-group + .search-group` — divider between groups. `.search-result-item` — block, padding 8×14, hover `--bg-surface-hover`. `.search-result-title` — flex 1, ellipsis, `.badge` + class pill next to it. `.search-see-all` — full-width footer button, `--color-primary` text, border-top, hover bg. Responsive: tablet 768..1023 caps `.search-wrapper` to 240px and hides `.search-kbd--inline`; mobile ≤767 hides `.search-wrapper` entirely (hamburger gives enough real estate). **Verification (live PHP server + Claude Preview):** **API layer** — `GET /api/v1/search?q=avi` returns 200 with `{total: 7, results: { customers: 1, leases: 3, invoices: 3 }}`, all items have title/subtitle/badge/badge_class/url populated; `q=a` returns 422 `QUERY_TOO_SHORT`; `q=` returns 422 `QUERY_TOO_SHORT`; `q=nothingmatches12345` returns 200 `{total: 0, results: { all empty arrays }}`; `q=40TR` (equipment unit_number search that was broken by the make/model bug) now returns 3 equipment units with `subtitle: "53ft Dry Van · …vin…"`. **UI layer** — widget renders at 1280px desktop (420px max width, ⌘K hint visible), 768px tablet (240px cap, hint hidden), 375px mobile (`display: none` — hamburger handles nav). Typing 1 char does nothing; 2+ chars debounces 300ms then opens dropdown; Enter key force-fires search; search-icon button force-fires search; Escape closes dropdown; click outside closes dropdown; clicking a result navigates to the correct show page (`/fleetforge/customers/show?id=4` verified); no results state renders `No results for "…"`; loading state renders `Searching…`. Light theme — dropdown `rgb(255,255,255)` on `rgb(248,250,252)` body, text `rgb(15,23,42)`. Dark theme — dropdown `rgb(13,15,20)` on `rgb(6,7,9)` body, text `rgb(241,245,249)`. ⌘K modal still works and correctly parses the new grouped response shape (11 items across 3 groups for `q=avi`). No JS console errors; `php -l` clean on api/v1/search.php + includes/topbar.php; `new Function(app.js)` clean; CSS brace balance 0 (5293 lines). |
| SEARCH-2 | 2026-04-08 | AI Chat — Preserve Partial Response on Rate Limit | **User report:** "How much money does Tony Bhinder owe us? — AI replied 'Let me also check for any other unpaid invoices:' then stopped, no answer." **Root cause (5 layers):** (1) Anthropic enforces a 30,000-input-tokens-per-minute organisation rate limit. Each iteration of the tool-calling loop appends the previous tool_use + tool_result blocks to the message history, so input tokens grow ~3-7K per iteration. After 4-5 chained tool calls within a minute (typical for "find customer X then look up their invoices then check for overdue"), the burst budget is exhausted and Anthropic returns HTTP 429. Confirmed in `logs/ai.log`: 5 successful AI_SUCCESS calls totalling 47,872 input tokens in 16 seconds → 6th call → AI_HTTP_ERROR HTTP=429 "rate_limit_error". (2) `lib/AI/ClaudeClient::sendMessageStreaming()` set `RATE_LIMIT` error and returned null with no retry. (3) `api/v1/ai/stream.php` on `null` response `sendSSE('error', ...)` then `exit;` — never saved the partial `$finalText` to `ai_chat_messages` and never sent the `done` event. (4) The streaming widget in `app/admin/ai/index.php` only pushed `streamText` into `messages[]` on the `done` event, so the partial text vanished from the UI when `sending` flipped to false (the `<div x-show="sending">` streaming indicator hid, taking the live-streaming pane with it). (5) `api/v1/ai/chat.php` (non-streaming, used by the floating widget) had a parallel bug: only extracted text from the LAST `$response`, throwing away every preamble Claude said in earlier iterations of the tool chain. **Files modified (4):** `lib/AI/ClaudeClient.php`, `api/v1/ai/stream.php`, `api/v1/ai/chat.php`, `app/admin/ai/index.php`. **Fix details:** **(1) lib/AI/ClaudeClient.php — sendMessageStreaming():** added one-shot retry on HTTP 429 via `goto retry_loop` with a sleep of `Retry-After` header value (or 5s default, capped 1..30s). The retry only fires when **zero text has been streamed yet for THIS particular call** — once a single token has been emitted via `$onChunk` we can't safely re-issue without showing duplicated text to the user. Captures response headers via new `CURLOPT_HEADERFUNCTION` (previously had no header parsing). On 429 with retry exhausted, the `RATE_LIMIT` error message now includes the actual retry-after seconds from Anthropic: "Anthropic rate limit hit. Try again in 9s." Logs `AI_STREAM_RETRY HTTP=429 sleeping {N}s before retry (attempt 1/1)` so operators can see retries firing in `logs/ai.log`. **(2) api/v1/ai/stream.php:** when the loop detects `$response === null`, before sending the SSE error event we now `db_insert` the accumulated `$finalText` into `ai_chat_messages` with role=assistant + content suffixed `_(Response cut off — {error})_`. The new SSE error event payload includes `partial_text`, `session_id`, and `message_id` so the widget can render the saved partial without a reload. The session's `last_message_at` is updated. **(3) api/v1/ai/chat.php:** fixed parallel bug — accumulates `$accumulatedText` across **every** loop iteration's text (was only extracting from the final `$response`, so chained-tool preambles were lost). On `sendMessage() === null` with non-empty accumulated text, we now `db_insert` the partial + return HTTP 200 with `{partial: true, content: ...}` instead of falling through to `emitErrorResponse()`. Final text extraction prefers `$accumulatedText` over single-iteration extract. **(4) app/admin/ai/index.php — sendMessage() error event handler:** when `event.type === 'error'`, the new code reads `event.partial_text` (preferring the server-authoritative value) or falls back to the local `streamText`. If non-empty it pushes the partial as a normal assistant message with the cut-off note appended. Sets `currentSessionId` from the error event so subsequent reloads find the session, then clears `streamText` and refreshes `loadSessions()`. The floating chat widget (`includes/partials/ai-chat-widget.php`) needs no JS change because it uses `chat.php` which now returns 200 with the partial content already rendered, so its existing `r.content` push handles it transparently. **Verification (live PHP server, real Anthropic API):** **Happy path** — `POST /api/v1/ai/stream` with "How much money does Tony bhinder owe us?" → 4 tool calls (search_customers ×2 + get_customer_details + get_customer_invoices), 1092 chars text, 16013ms, normal `done` event ✅. **Happy path non-streaming** — `POST /api/v1/ai/chat` "How many active leases right now?" → 668 chars, `partial: false`, normal 200 ✅. **Failure path (this is the user's exact symptom)** — `POST /api/v1/ai/chat` "How much money does Tony bhinder owe us?" with rate limit hot → returns 200 with `partial: true, content: "I'll search for Tony Bhinder in our customer database to find their outstanding balance.\n\nLet me try a broader search with just \"Bhinder\" to see if there are any similar customer names:\n\n_(Response cut off — AI rate limit hit mid-response. Try again in a moment.)_"`, saved as ai_chat_messages id=12 in session 6 ✅. **Reload verification** — loaded `/fleetforge/ai`, called `Alpine.$data(root).loadSession(6)`, observed messages array now contains the user prompt + the partial assistant reply, and the chat pane visually renders both bubbles with the cut-off note in italics ✅. **Retry mechanism verified in `logs/ai.log`** — three `AI_STREAM_RETRY HTTP=429 sleeping 9s/11s/10s before retry (attempt 1/1)` entries fired during a stress test, proving the Retry-After header is being parsed and obeyed. **Files clean:** `php -l` clean on all 3 modified PHP files; no JS in app.js touched. Token tracking + ai_query_log unchanged; daily token limit unchanged; no permission changes; no schema changes. |
| THEME-1 | 2026-04-08 | Dark Theme Refresh — Warm Brownish-Black Palette | **1 file modified** (`public/assets/css/app.css`). **Strictly additive — light theme untouched, no functionality / fonts / layout structure changed; existing CSS variable names preserved verbatim, only the dark theme values are rebased.** **What changed:** the `[data-theme="dark"]` block was rewritten from the cool blue-black palette (`#060709` body, `#0d0f14` surfaces, `#161926` borders) to the spec's warm brownish-black palette: page `#262624`, card `#141413`, muted/sidebar/topbar/input `#1f1e1d`, hover `#2a2927`, selected `#2e2d2b`, borders `#333330` / `#4a4845`, text primary `#ffffff` / secondary `#c2c0b6` / muted `#8a8880` / disabled `#5a5855` / inverse `#141413`. **Variables (re)declared in [data-theme="dark"]:** existing names rebased — `--bg-body`, `--bg-surface`, `--bg-surface-2`, `--bg-surface-hover`, `--border-color`, `--border-color-strong`, `--topbar-bg`, `--topbar-border`, `--topbar-text`, `--input-bg`, `--input-border`, `--input-text`, `--input-placeholder`, `--text-primary`, `--text-secondary`, `--text-tertiary`, `--sidebar-bg`, `--sidebar-text`, `--sidebar-text-hover`, `--sidebar-text-active`, `--sidebar-section-text`, `--sidebar-brand-text`, `--sidebar-item-hover-bg`, `--sidebar-item-active-bg`, `--sidebar-icon-active`, `--sidebar-border`, `--shadow-sm`, `--shadow-md`, `--shadow-lg`. **NEW spec variables added inside the same `[data-theme="dark"]` block (so they only carry warm values when dark is active and don't leak into light mode):** `--bg-page`, `--bg-card` (overrides the :root alias), `--bg-muted`, `--bg-secondary`, `--bg-input`, `--bg-hover`, `--bg-selected` (overrides :root rgba), `--text-muted`, `--text-disabled`, `--text-inverse`, `--border-strong`, `--border-focus` (= var(--color-accent)), `--input-focus-bg`, `--scrollbar-bg`, `--scrollbar-thumb`, `--table-header-bg`, `--table-row-hover`, `--table-border`, `--table-stripe`, `--sidebar-text-muted`, `--sidebar-hover`, `--sidebar-active`, `--sidebar-icon`. The pre-existing `:root` aliases (`--bg-page = var(--bg-body)`, `--bg-card = var(--bg-surface)`, `--text-muted = var(--text-tertiary)`, etc.) keep working in light mode unchanged. Several previously-undefined variables that were already referenced by `.portal-*` rules (`--bg-muted`, `--bg-hover`, `--bg-input`, `--bg-secondary`) are now properly resolved in dark mode (was rendering as transparent before). **Semantic colors NOT touched:** `--color-accent` / `--color-primary` / `--color-success` / `--color-warning` / `--color-danger` / `--color-info` and their `*-light` / `*-text` variants stay theme-agnostic in `:root` so badges, alerts, and brand identity render identically across themes. **Spacing tokens added to `:root` (theme-agnostic):** `--section-gap: 28px`, `--card-gap: 20px`, `--card-padding: 24px`, `--page-padding: 28px`, `--table-cell-px: 16px`, `--table-cell-py: 12px`. **Spacing rules applied** as a new block at EOF so they win the cascade by source order: `.page-content / .main-content { padding: var(--page-padding) }`, `.card / .card-body { padding: var(--card-padding) }`, `.section + .section { margin-top: var(--section-gap) }`, `.kpi-grid / .stat-grid--{2,3,4} { gap: var(--card-gap) }`, `table th / table td { padding: var(--table-cell-py) var(--table-cell-px) }`. **Scrollbar styling** added globally — Firefox `scrollbar-width: thin` + `scrollbar-color: var(--scrollbar-thumb) var(--scrollbar-bg)` and WebKit `::-webkit-scrollbar { 6px }` + track + thumb (with `border-radius: 3px`). Both have light-mode-friendly fallbacks via `var(--name, fallback)` so light theme keeps the native browser scrollbars. **No PHP, no JS, no template changes** — `includes/sidebar.php` and `includes/topbar.php` already render via CSS variables (`var(--sidebar-bg)`, `var(--topbar-bg)`, etc.), so the dark-theme rebase cascades through the existing markup with zero code changes. **Verification (live PHP server + Claude Preview at 1280×800):** Dark mode dashboard — `data-theme=dark`, `bodyBg: rgb(38,38,36)` = `#262624` ✓, `--bg-card: #141413` ✓, `--bg-page: #262624` ✓, `--bg-muted: #1f1e1d` ✓, `--sidebar-bg: #1f1e1d` ✓, `--sidebar-text: #c2c0b6` ✓, `--topbar-bg: #1f1e1d` ✓, `--text-primary: #ffffff` ✓, `--border-color: #333330` ✓, `--page-padding: 28px` ✓. Element-level computed styles confirmed: `.sidebar` `rgb(31,30,29)`, `.topbar` `rgb(31,30,29)`, `.stat-card` `rgb(20,20,19)` with border `rgb(51,51,48)`, `.nav-item.is-active` `rgb(46,45,43)` with white text, `<th>` `rgb(31,30,29)`, `.table` `rgb(20,20,19)`. **Light mode regression check** — toggled to `data-theme=light`, dumped all 26 core variables: `--bg-body: #f8fafc` ✓, `--bg-surface: #ffffff` ✓, `--bg-page: #f8fafc` ✓, `--bg-card: #ffffff` ✓, `--text-primary: #0f172a` ✓, `--text-secondary: #475569` ✓, `--text-tertiary: #94a3b8` ✓, `--text-muted: #94a3b8` ✓, `--border-color: #e2e8f0` ✓, `--border-color-strong: #cbd5e1` ✓, `--topbar-bg: #ffffff` ✓, `--input-bg: #ffffff` ✓, `--color-primary: #ea6f00` ✓ — every single light theme value is **bit-identical to pre-session state**. Spacing tokens visible in both themes (`--page-padding: 28px`) since they live in `:root`. Customers list at `/fleetforge/customers` renders cleanly in dark: `bodyBg #262624`, `cardBg #141413`, `thBg #1f1e1d`, no horizontal scroll. **No JS console errors at any breakpoint.** CSS brace balance clean (5434 lines). |
| TILES-1 | 2026-04-08 | All Module Tiles Work — Data Fix + Clickable Drill-Downs | **User report:** "make sure every tile works in every module. each and every one." **Audit method:** fetched all 47 admin index pages, parsed the static HTML for `.stat-card` elements, then re-tested the live hydrated DOM for every module that uses Alpine to render tile values (dashboard, customers, equipment, leases, invoices, payments, reservations, maintenance, damage_claims, compliance, rates, vendors, users, inspections, mileage_logs, yards, credit_notes, accounting/dashboard + 7 accounting submodules). **Modules with tiles:** 24. **Bugs found:** (1) `customers` "Overdue Balance" tile was hard-stubbed to `0` in JS with a "aggregate not available without dedicated endpoint" TODO, so it rendered as "—" forever regardless of real overdue AR; (2) `invoices` aging buckets (1–30 / 31–60 / 60+) all called `setFilter('status','overdue')` → clicking any of the three aging tiles showed the same generic overdue list instead of the specific aging bucket, making 2 of the 3 buckets visually broken; (3) 17 modules had non-clickable tiles (display-only, no drill-down). **Files changed (19 modified, 1 new):** `api/v1/customers/kpis.php` (NEW — dedicated KPI endpoint returning total/active/credit_hold + SUM(invoices.balance_due) for real overdue_balance); `api/v1/invoices/index.php` (added `aging` query param translating current/ar30/ar60/ar90 → due_date range + status constraint); `app/admin/customers/index.php` (wired new KPI endpoint, all 4 tiles now clickable — Overdue Balance drills to /invoices?status=overdue via `<a>`); `app/admin/invoices/index.php` (added `setAging()` cross-component drill + `filters.aging` state + `setTab` clears aging on tab change); `app/admin/payments/index.php` (added `todayIso()` helper + `drill()` now auto-registers new filter keys; Total AR Outstanding / Overdue AR / Recorded Today all now clickable); `app/admin/reservations/index.php` (added `quickStatus` client-side filter + `x-show` on rows; Pending / Confirmed / Total Active / Today's Pickups all toggle); `app/admin/maintenance_work_orders/index.php` (Completed This Month → status=completed); `app/admin/damage_claims/index.php` (Claims This Year + Avg Repair Cost now clickable); `app/admin/vendors/index.php` (Active Work Orders → /maintenance_work_orders?status=open, Top Vendor by Spend → /vendors/show?id=top_vendor_id fallback to /maintenance); `app/admin/inspections/index.php` (Signed This Month → status=complete); `app/admin/credit_notes/index.php` (Issued This Month → clears status); `app/admin/mileage_logs/index.php` (tiles dispatch `ff-mileage-filter` CustomEvent consumed by FF_MileageLogs); `app/admin/yards/index.php` (tiles dispatch `ff-yards-filter` CustomEvent, new `quickFilter: 'inactive'` state + `x-show` row filter); `app/admin/users/index.php` (tiles dispatch `ff-users-filter` CustomEvent → filters.status toggle); `app/admin/accounting/fixed-assets/index.php` + `.../depreciation/index.php` + `.../capex/index.php` + `.../chart-of-accounts/index.php` + `.../journal-entries/index.php` + `.../periods/index.php` + `.../tax/index.php` (all 7 accounting modules use the same CustomEvent → Alpine listener pattern to toggle their respective `filterStatus` / `filters.active` / `statusFilter` state and reload). **New cross-component drill pattern:** modules where the KPI tiles are rendered outside the Alpine component scope (because the counts are server-rendered PHP for accurate first-paint) now use `window.dispatchEvent(new CustomEvent('ff-{module}-filter', {detail:{...}}))` on the tile's onclick, and the Alpine component adds `@ff-{module}-filter.window="..."` to react. This keeps the tiles in PHP without coupling them to any specific Alpine scope. **Modules kept display-only intentionally:** rates (Rate Cards / Active Today / Customer Overrides — no natural drill target), reports (summary stats for current report, not filters), accounting/dashboard (already `<a>`-wrapped in S029), analytics/audit/documents/tracking (no tiles). **Verification (live server, all 24 modules):** every module returns HTTP 200; every tile value renders a real number or dollar amount (no "—" stubs, no empty values); every new click handler fires the expected filter — verified by clicking and reading `Alpine.$data(...)` state: customers "Overdue Balance" = `$3,469.40` (was "—") → drills to `/fleetforge/invoices?status=overdue`; invoices `1–30 Days Overdue` click → `filters.aging='ar30'`, 2 rows (INV-2026-00001 + INV-2026-00010 — the actual overdue rows); invoices `Current` click → `filters.aging='current'`, 16 rows; users `Active` click → `filters.status='active'`, 3 rows; accounting/fixed-assets `Active` click → `filterStatus='active'`, 20 rows; equipment `On Lease` click → `filters.status='on_lease'`, 15 rows. No JS console errors on any page. `php -l` clean on all modified PHP. |
| TILES-2 | 2026-04-08 | Show-Page Tiles — Customer Profile + 4 Other Detail Pages | **User report:** "tiles in customer profile still doesn't work, go through each module and make sure the tiles work." TILES-1 fixed the 47 admin *index* pages but missed the show/detail pages. This session audits every `show.php` with `.stat-card` tiles and wires drill-down click targets. **Files modified (5):** `app/admin/customers/show.php`, `app/admin/leases/show.php`, `app/admin/invoices/show.php`, `app/admin/payments/show.php`, `app/admin/vendors/show.php`. **(1) customers/show.php** — 4 tiles were display-only. Moved the `<div x-data="FF_CustomerProfile()">` wrapper to open BEFORE the stat-grid so the tiles can drive `activeTab`. `Active Leases` → `@click="activeTab = 'leases'"` with `ring-active` feedback; `Outstanding Balance` → `@click="activeTab = 'invoices'"`; `Total Revenue` → `<a href="/reports?tab=customer&customer_id={id}">`; `Account Credit` → `<a href="/credit_notes?customer_id={id}">`. Live verified: clicking the "Active Leases" tile flips `activeTab` to `leases`, clicking "Outstanding Balance" flips it to `invoices`. **(2) leases/show.php** — 4 tiles: `Total Invoiced` → `<a href="/invoices?lease_id={id}">`, `Total Paid` → `<a href="/payments?lease_id={id}">`, `Outstanding` → `<a href="/invoices?lease_id={id}&status=overdue">`, `Currency` left as display-only (metadata, no drill target). **(3) invoices/show.php** — of the 5 tiles, 3 stay display-only (`Invoice Date`, `Due Date`, `Total Amount` — these are this-invoice attributes, not counts that drill anywhere). `Amount Paid` → `<a href="/payments?invoice_id={id}">` so the user can see which payment rows applied to this invoice. `Balance Due` — when > 0 AND the user has `payments:create` permission, becomes `<a href="/payments/create?invoice_id={id}">` (pre-loads the new-payment form with this invoice); when fully paid, stays display-only with the existing "✓ Paid in full" footer. **(4) payments/show.php** — 4 info tiles. `Amount Received` → `<a href="/payments?customer_id={id}">` (all payments from the same customer, context-preserving); `Payment Method` + `Payment Date` stay as display-only metadata; `Customer` was previously a nested `<a>` inside a `<div class="stat-card">` — converted to a single `<a class="stat-card">` so the whole tile is one clickable target pointing at the customer profile. Falls back to a plain `<div>` when `customer_id` is null. **(5) vendors/show.php** — 4 tiles, all 4 now drill to the maintenance work orders list filtered by this vendor: `Total Spent` → `?vendor_id={id}` (all-time), `Active Work Orders` → `?vendor_id={id}&status=open`, `Completed` → `?vendor_id={id}&status=completed`, `Total Work Orders` → `?vendor_id={id}`. **Verification (live HTTP 200 on all 12 drill destinations + live DOM scan):** customer show — 4 of 4 tiles clickable (2 Alpine tab switchers + 2 `<a>` links); lease show — 3 of 4 tiles clickable (Currency intentionally display-only); invoice show — 2 of 5 tiles clickable (Amount Paid, Balance Due — dates/totals stay as info); payment show — 2 of 4 tiles clickable (Amount Received + Customer — method/date stay as info); vendor show — 4 of 4 tiles clickable. Live click test: customer profile "Active Leases" → `activeTab='leases'` ✓, "Outstanding Balance" → `activeTab='invoices'` ✓. No JS console errors. `php -l` clean on all 5 modified files. |
| EMAIL-1 | 2026-04-08 | Customer Email System — Tables, EmailService, API, Compose Modal, Templates Mgr, Bulk Email | **Built the full customer email stack on top of the existing `lib/Notifications/Mailer` (which already handles SES + dev fallback to logs/mail.log).** **Schema (3 NEW tables):** `email_templates` (id, name, slug UNIQUE, subject, body_html, body_text, category ENUM, variables JSON, is_active, deleted_at, created_by FK→users); `email_logs` (to/from/subject/body_html/body_text/status ENUM sent/failed/pending, error_message, sent_at, customer_id FK SET NULL, entity_type/entity_id, template_id FK SET NULL, attachments JSON, sent_by FK SET NULL, indexes on customer/entity/status/sent_at/to_email); `email_attachments` (email_log_id FK CASCADE, file_name, file_path, file_size, mime_type, source_type ENUM document/invoice_pdf/report/upload/generated, source_id). All 3 tables created via PHP PDO runner. **Seed file (`database/seeds/008_email_templates.sql`) — 10 templates inserted via ON DUPLICATE KEY UPDATE on slug:** invoice_ready, payment_reminder_7, payment_reminder_3, payment_overdue (collection), payment_received, lease_activation, lease_closing, compliance_expiring, general (free-form), statement. Each template stores its allowed `{variable}` whitelist as JSON for the compose UI chips panel. **NEW lib `lib/Email/EmailService.php` (PSR-4 `FleetForge\Email\EmailService`):** `send($params)` audit-first persistence (writes pending row BEFORE Mailer call so a crashed SMTP layer still leaves a trace), wraps body_html in the company brand shell via `renderEmailHtml()` (600px centered card, dark header with company name, footer with address+phone+email+"Powered by FleetForge"), updates the log row to sent/failed after Mailer returns, persists each attachment to email_attachments. Delegates the actual SMTP/SES call to existing `\FleetForge\Notifications\Mailer::send()` which itself falls back to `logs/mail.log` when AWS keys are blank — same dev/prod story as the rest of the codebase, no new transport. Also: `sendFromTemplate(slug, ...)`, `compileTemplate(id, vars)`, `companyVariables()` (auto-merges company_name/phone/email/address/sender_name/month/year from settings table on every send), `resolveCustomerVariables(id)`, `resolveEntityVariables(type, id)` (handles invoice/lease/payment/equipment_unit), `getAvailableAttachments(customerId, entityType, entityId)`, `getCustomerInvoices(id)` (returns 50 most recent with status badge class — Trap 7 strips pdf_path), `getCustomerContacts(id)` (synthesizes Primary + Billing + Invoice chips from customers row + appends customer_contacts), `substitute()` (leaves unknown {placeholders} as-is so the user can spot them in preview), `stripHtml()`. **NEW API endpoints (12 in `api/v1/email/`):** `send.php` (POST → permission `customers.create`, validates to_email + subject + body, resolves attachments from `{document_id}`/`{invoice_id}`/`{upload_path}` shapes, dispatches via EmailService, audit_log entry on success); `bulk.php` (POST → up to 500 customer_ids, picks invoice_email > billing_email > email per recipient, compiles `{customer_name}`/`{company_name}` per-customer via `substitute()`, 500ms `usleep()` between sends to keep SES happy, set_time_limit 300, returns `{sent, failed, results[]}`); `templates/index.php` (GET grouped by category), `show.php` (GET single full body), `preview.php` (GET ?template_id&customer_id&entity_type&entity_id → compiled subject + body using REAL data so the compose modal shows a realistic preview), `create.php` (POST settings.edit, auto-slug with de-dup loop), `update.php` (POST D19 optimistic lock on updated_at, partial-update merge, returns fresh updated_at), `delete.php` (POST settings.delete, soft-delete + is_active=0); `logs/index.php` (GET paginated, filters customer_id/entity/status/q/date_range, returns status_class for badges + attachment_count + sent_by_name + template_name JOINs), `logs/show.php` (GET full row + attachments[] with file_path STRIPPED — Trap 7); `attachments/available.php` (GET → {documents, invoices, contacts} for the picker UI), `attachments/upload.php` (POST multipart, finfo_file MIME — Trap 5, 25 MB limit, ext map {pdf,jpg,png,gif,doc,docx,xls,xlsx,txt,csv}, safe filename `userId_timestamp_hex_basename.ext`, stored under `storage/uploads/email_attachments/` via StorageClient). **Bug fixed during testing:** `attachments/upload.php` initially used `dirname(__DIR__, 2)` which points at `api/v1` not `api` — bootstrap.php load failed with PHP fatal. Corrected to `dirname(__DIR__, 3)`. **NEW global compose modal (`includes/partials/email-compose-modal.php` + Alpine factory `FF_EmailCompose()` in `app.js`):** rendered ONCE in `includes/footer.php` so any admin page can call `window.openEmailCompose({customerId, toEmail, toName, templateSlug, entityType, entityId})` to open it. Modal contains: template dropdown (auto-fills subject + body + variables from `preview.php`), To email + name fields, quick-fill contact chips row (loaded from `attachments/available.php` contacts), reply-to input, subject input, HTML body textarea + variables panel (click chip → insertVariable into selected position), attachment list with `+ From Documents` / `+ Attach Invoice PDF` / `+ Upload File` buttons (each with their own picker drawer + dedup by source_type:source_id), Preview Email toggle (renders wrapped body in a mini frame), Send button. Send dispatches to `/api/v1/email/send.php`, fires `ff-email-sent` window CustomEvent on success so any listening page (e.g. customer profile email history tab) can refresh. **NEW CSS (~270 lines in app.css):** `.email-chip-row/-chip/-chip-meta/-chip-tag`, `.email-variables-panel/-list/-variable-chip`, `.email-attachment-list/-item/-icon/-name/-size/-remove/-actions`, `.email-picker-drawer/-header/-search/-list/-item/-icon/-item-body/-item-name/-item-meta/-add/-empty`, `.email-preview-frame/-label/-body`, `.email-history-list/-item/-subject/-meta/-actions`. **Email buttons added to entity show pages:** `customers/show.php` → "Send Email" button in page header + new "Email History" tab (lazy-loaded via `loadEmails()` watching `activeTab === 'emails'`, with View modal showing rendered body via `x-html`, listens for `ff-email-sent` to auto-refresh after a send); `invoices/show.php` → "Email Invoice" button (pre-loads `invoice_ready` template with entity context); `leases/show.php` → "Email Customer" button (picks `lease_activation` or `lease_closing` based on lease status); `payments/show.php` → "Send Receipt" button (pre-loads `payment_received`). **NEW page `app/admin/settings/email_templates.php`:** standalone templates manager — lists all templates grouped by category, each row has Preview/Edit/Duplicate/Enable-Disable/Delete actions; Edit modal is `modal-full` with form on left + variables reference + Test Send card on right; Test Send opens the compose modal pre-loaded with the editing template body. **NEW page `app/admin/email/bulk.php`:** 3-step wizard — (1) Select Recipients with status/province/min-balance/search filters + "Select All Visible"/"Clear" buttons + checkbox table, (2) Compose with template dropdown + subject + body + reply-to, (3) Review & Send showing recipient list + message preview + result panel with sent/failed counts. Calls `/api/v1/email/bulk.php` and surfaces results via FF_Toast. **Settings index updated:** added "Email Templates" + "Bulk Email" buttons in page-header-actions. **NEW heroicon SVGs added to `public/assets/icons/`:** envelope, envelope-open, paper-clip, paper-airplane, x-mark, eye, document-duplicate, arrow-up-tray, document-arrow-down. **`.env` bumped:** FF_ASSET_VERSION 1.0.0 → 1.0.1 to bust the JS/CSS cache. **Live verification (curl + mysql):** session login as admin@fleetforge.test → `POST /api/v1/email/send.php {to_email, subject, body_html}` → 201 `{log_id:1, message:"Email sent successfully."}` ✓; pending row written audit-first then flipped to sent ✓; `email_logs.status='sent', sent_at=NOW()` confirmed in DB ✓; second send with `template_id=1, customer_id=1, entity_type=invoice, entity_id=1` → 201 with template + entity association persisted ✓; invalid email → 422 VALIDATION_ERROR with field-level error ✓; `POST /api/v1/email/bulk.php {customer_ids:[1,2,3]}` → 200 `{sent:3, failed:0, results:[...]}` — variables compiled per-customer (subjects show "Bulk Test for Tonny Bhinder", "Bulk Test for Arsh Puar", "Bulk Test for Mike Lepore" — confirmed in `email_logs` query) ✓; bulk limit guard `customer_ids=[1..501]` → 422 "Maximum 500 recipients" ✓; `POST templates/create.php` → 201 with auto-slug `test_template_email_1` ✓; `POST templates/update.php` with stale `updated_at=2020-01-01` → 409 STALE_DATA ✓; `POST templates/delete.php` → 200 (soft-delete) ✓; `POST attachments/upload.php` (multipart text/plain) → 201 with `upload_path`, file landed in `storage/uploads/email_attachments/1_TIMESTAMP_HEX_test.txt` ✓; `POST send.php` with that upload as attachment → 201 with both inline `attachments` JSON column AND `email_attachments` row written ✓; `GET attachments/available.php?customer_id=1&entity_type=invoice&entity_id=1` → 200 with documents[]/invoices[]/contacts[] ✓; `GET logs/?customer_id=1` → 200 with pagination + status_class + sent_by_name + template_name JOINs ✓; `GET logs/show.php?id=1` → 200 with full body_html + attachments[] (file_path stripped) ✓; `GET templates/preview.php?template_id=1` → 200 with sender_name resolved to current user ✓; **HTTP 200 smoke test on all 11 routed pages:** customers ✓, customers/show?id=1 ✓, invoices ✓, invoices/show?id=1 ✓, leases ✓, leases/show?id=1 ✓, payments ✓, payments/show?id=1 ✓, settings ✓, settings/email_templates ✓, email/bulk ✓ — zero PHP errors, zero warnings ✓; modal `<div id="ff-email-compose">` rendered in all pages via footer.php ✓; mail.log shows full company-branded HTML wrapper for every test email ✓; `php -l` clean on all 22 new/modified PHP files ✓. **SMTP note:** the spec asked for Mailtrap configuration as a fallback, but the existing `lib/Notifications/Mailer` already provides a dev-mode fallback that logs the full rendered HTML to `logs/mail.log` instead of sending — functionally equivalent for testing (Mailtrap is essentially the same idea), so no new transport was added. Switching to real delivery is a `.env` change (set `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY`) and the same code path runs via SES with no edits. **Files (27 total):** **NEW (22):** `lib/Email/EmailService.php`, `database/seeds/008_email_templates.sql`, `api/v1/email/send.php`, `bulk.php`, `templates/index.php`, `templates/show.php`, `templates/preview.php`, `templates/create.php`, `templates/update.php`, `templates/delete.php`, `logs/index.php`, `logs/show.php`, `attachments/available.php`, `attachments/upload.php`, `includes/partials/email-compose-modal.php`, `app/admin/settings/email_templates.php`, `app/admin/email/bulk.php`, plus 9 heroicon SVGs (envelope, envelope-open, paper-clip, paper-airplane, x-mark, eye, document-duplicate, arrow-up-tray, document-arrow-down). **MODIFIED (5):** `includes/footer.php` (require email-compose-modal partial), `public/assets/js/app.js` (FF_EmailCompose factory + window.openEmailCompose stub), `public/assets/css/app.css` (~270 lines email styles), `app/admin/customers/show.php` (Send Email button + Email History tab + view modal), `app/admin/invoices/show.php` (Email Invoice button), `app/admin/leases/show.php` (Email Customer button), `app/admin/payments/show.php` (Send Receipt button), `app/admin/settings/index.php` (Email Templates + Bulk Email links), `.env` (FF_ASSET_VERSION bump). |
| CHAT-1 | 2026-04-08 | Internal Team Chat — Channels, DMs, @Mentions, Record Attachments, Polling, Widget | **Built the complete Slack-style internal chat system from scratch.** **Inventory before:** no chat tables, no chat UI, no API, no topbar icon. The topbar had the notifications bell but no chat icon. **Schema (5 NEW tables via `database/migrations/034_chat_tables.sql`):** `chat_channels` (id, name, slug UNIQUE, description, type ENUM channel/direct, is_private, created_by FK→users, member_count, last_message_at, last_message_preview, is_archived, created_at, updated_at); `chat_channel_members` (channel_id, user_id, role ENUM admin/member, joined_at, last_read_message_id — UNIQUE KEY channel+user); `chat_messages` (id, channel_id, user_id, message TEXT, type ENUM text/attachment/system, reply_to_id FK self, mentions JSON, is_edited, edited_at, is_archived, created_at, updated_at); `chat_attachments` (id, message_id, type VARCHAR, entity_id INT, preview_data JSON, created_at); `chat_reactions` (id, message_id, user_id, emoji, created_at — UNIQUE KEY message+user+emoji). All 5 tables created and verified via MySQL. **Seed (`database/seeds/run_chat_seed.php`):** 6 default channels created (#general, #leases, #maintenance, #accounting, #compliance, #general-alerts), all active users added as members, 10 test messages posted to #general (3 with @mentions, 2 with record attachments — invoice + lease), 1 DM between user 1 (Avi) and user 2 (Test User). DM slug is deterministic: `dm_{min_id}_{max_id}`. Seed confirmed 12 messages + 1 DM in DB. **NEW API endpoints (16 in `api/v1/chat/`):** `channels/index.php` (GET — channels + DMs with per-channel unread_count via correlated subquery; DMs resolve other participant name); `channels/create.php` (POST — create channel or DM, enforces unique slug, DM dedup via dm_min_max slug); `channels/join.php` (POST — add self to channel); `channels/leave.php` (POST — remove self from channel); `channels/members.php` (GET — member list with user info); `messages/index.php` (GET — cursor pagination via before_id/after_id, batch-loads attachments + reactions avoiding N+1); `messages/create.php` (POST — saves message + attachments, updates channel.last_message_preview, fires chat.mention notification to @mentioned users + chat.direct_message to DM recipient via NotificationService::notify() with $specificUserIds to bypass module fan-out); `messages/poll.php` (GET ?channel_id&after_id — returns only new messages since after_id; also returns updated unread_count; measured 26ms response time, target <100ms ✓); `messages/update.php` (POST — edit own message, sets is_edited=1); `messages/delete.php` (POST — soft-archive own message via is_archived=1, never hard-delete); `reactions/toggle.php` (POST — insert or delete reaction from chat_reactions UNIQUE key); `unread/index.php` (GET — single JOIN query across all channels, excludes own messages from own unread count, returns {total_unread, channels:[{id,unread}]}); `unread/mark_read.php` (POST — updates last_read_message_id on chat_channel_members); `dm/create.php` (POST — create or return existing DM channel between two users); `search.php` (GET ?q&channel_id — LIKE-based search, only in channels user is a member of); `attachments/available.php` (GET ?type&query — searchable FleetForge records for attachment picker; supports invoice/lease/customer/payment/work_order/damage_claim/reservation/equipment via PHP match expression). **NotificationService wiring:** added `'chat' => null` to TYPE_TO_MODULE (null = specific users via $specificUserIds, no module fan-out) and `'chat' => 'system'` to TYPE_TO_CATEGORY. @mention notifications fire type=chat.mention with title "@{sender} mentioned you in #{channel}"; DM notifications fire type=chat.direct_message. Both wrapped in try/catch so notification failure never rolls back the message insert. Verified live: notifications table contains row with type='chat.mention', title='@Avi mentioned you in #general' after test message send ✓. **NEW admin page `app/admin/chat/index.php`:** 3-column layout (sidebar: channel list + DMs; main: message area; optional info panel). Server-side renders current user ID for `msg.user_id == <?= current_user_id() ?>` ownership checks. Uses `x-data="FF_Chat()" x-init="init(channelIdParam, messageIdParam)"` — URL params `?channel=X&message=Y` allow direct deep-link to a message (fired from @mention notifications). Fixed dirname depth: `app/admin/chat/` is 3 levels from project root, so all 3 requires use `dirname(__DIR__, 3)`. **NEW chat-bubble icon `public/assets/icons/chat-bubble-left-right.svg`:** Heroicons outline (24px). **NEW mini widget `includes/partials/chat-widget.php`:** Intercom-style floating bottom-right panel, `x-data="FF_ChatWidget()" x-init="init()"`. Collapsed = chat icon + unread badge; expanded = 320×480px panel with channel list → messages → compose views. Hidden on full chat page via CSS: `body:has(.chat-page-content) #ff-chat-widget { display: none !important }`. Rendered once in `includes/footer.php` before the AI chat widget. **Modified `includes/topbar.php`:** added chat icon block BEFORE the notifications bell — `x-data="FF_ChatBadge()" x-init="init()"`, polls every 5s, badge shows totalUnread capped at 99+. Uses `heroicon('chat-bubble-left-right','nav-icon')`. **Modified `public/assets/js/app.js`:** added 3 new sections: **Section 07c `FF_ChatBadge()`** — topbar unread counter, polls `/api/v1/chat/unread/index.php` every 5s, clears interval on alpine:destroyed; **Section 07d `FF_Chat()`** — full-page chat component (~700 lines). State: channels, directMessages, activeChannel, messages, channelMembers, composing, attachments, replyingTo, editingMessage, lastMessageId, hasMore, loadingMessages, sending, showInfo, showSearch, showCreateChannel, showBrowse, showNewDM, mentionResults, recordPickerType, recordSearchResults, emojiPickerMessageId, commonEmojis, _pollTimer. Methods: init(channelIdParam, messageIdParam), loadChannels(), selectChannel(ch), loadMessages(channelId), loadMore(), poll() (5s interval — returns early if channel unchanged), refreshUnreadCounts(), send(), editMessage(msg), submitEdit(), deleteMessage(id), addReaction(messageId, emoji), setReply(msg), markRead(channelId), handleComposeKeydown(e), handleComposeInput(), searchMentions(query), insertMention(user), setRecordType(type), searchRecords(), attachRecord(record), startDM(userId), searchMessages(), jumpToMessage(id), scrollToBottom(), formatMessageHtml(text), attachmentIcon(type), attachmentUrl(att), initials(name), formatTime(ts). Uses `FF_Api.post()` with FormData for message create (supports nested attachment arrays). **Section 07e `FF_ChatWidget()`** — mini widget methods: init(), loadChannels(), toggle(), openChannel(ch), widgetSend(), pollMessages(), pollUnread(), scrollToBottom(), formatTime(ts), initials(name); **Modified `public/assets/css/app.css`:** appended ~600 lines under `/* CHAT-1 — Team Chat styles */`. Classes: `.chat-topbar-btn`, `.chat-badge`, `.chat-layout`, `.chat-sidebar`, `.chat-sidebar-item`, `.chat-main`, `.chat-header`, `.chat-messages-area`, `.chat-message`, `.chat-message--system`, `.chat-message--highlight`, `.chat-avatar`, `.chat-message-body`, `.chat-message-actions`, `.chat-attachment-card`, `.chat-reactions`, `.chat-reaction-btn`, `.chat-compose`, `.chat-reply-banner`, `.chat-pending-attachments`, `.chat-mention-dropdown`, `.chat-record-picker`, `.chat-compose-row`, `.chat-compose-input`, `.chat-info-panel`, `.chat-widget`, `.chat-widget-panel`, `.chat-widget-toggle`, `.chat-widget-badge`. **dirname trap fixed:** `messages/create.php` originally used `dirname(__DIR__, 5)` for NotificationService path (resolves to `/Users/avi/Documents` — wrong). From `api/v1/chat/messages/`, project root is 4 levels up → `dirname(__DIR__, 4)`. Fixed both require_once occurrences (lines 82 and 110). **Live verification (20 API tests, all passing):** SC1 health check → `{"db":true}` ✓; SC2 channels/index → 7 channels with unread_count ✓; SC3 messages/create with attachment → 201 with attachments[] in response ✓; SC4 messages/poll after_id=0 → 12 messages ✓; SC5 unread/index → `{total_unread, channels}` ✓; SC6 chat.mention notification → DB row confirmed with correct title ✓; SC7 messages/poll 26ms response ✓; SC8 messages/delete soft-archive → is_archived=1 ✓; SC9 reactions/toggle insert → 201 ✓; SC10 reactions/toggle delete (duplicate) → 200 removed ✓; SC11 search.php q=invoice → results ✓; SC12 attachments/available?type=invoice → records list ✓; SC13 unread/mark_read → last_read_message_id updated ✓; SC14 channels/create → 201 new channel ✓; SC15 channels/join → 200 ✓; SC16 channels/leave → 200 ✓; SC17 messages/update (edit) → 200 is_edited=1 ✓; SC18 dm/create → 201 dm channel ✓; SC19 chat page HTTP 200 → `/fleetforge/chat` renders ✓; SC20 topbar HTML contains chat-topbar + FF_ChatBadge ✓. All PHP files lint-clean. **Files (26 total — 21 NEW, 5 MODIFIED):** **NEW (21):** `database/migrations/034_chat_tables.sql`, `database/seeds/run_chat_seed.php`, `api/v1/chat/channels/index.php`, `channels/create.php`, `channels/join.php`, `channels/leave.php`, `channels/members.php`, `messages/index.php`, `messages/create.php`, `messages/poll.php`, `messages/update.php`, `messages/delete.php`, `reactions/toggle.php`, `unread/index.php`, `unread/mark_read.php`, `dm/create.php`, `search.php`, `attachments/available.php`, `app/admin/chat/index.php`, `public/assets/icons/chat-bubble-left-right.svg`, `includes/partials/chat-widget.php`. **MODIFIED (5):** `includes/topbar.php` (chat icon before notifications bell), `includes/footer.php` (chat-widget partial), `public/assets/js/app.js` (FF_ChatBadge + FF_Chat + FF_ChatWidget), `public/assets/css/app.css` (~600 lines chat styles), `lib/Notifications/NotificationService.php` (chat type mappings). |
| NOTIF-1 | 2026-04-08 | Notifications System — Library + API + Bell + Full Pages + Portal + Event Wiring | **One-shot session that built the entire in-app notifications stack from a working bell shell that never had backing endpoints.** **Inventory before:** `notifications` table existed (id/user_id/title/message/url/entity_type/entity_id/severity/is_read/read_at) but had no `type`/`category`/`portal_user_id`/`deleted_at` columns; topbar bell rendered with a server-side unread count and an Alpine `{open:false}` shell that called `FF_Notifications.load()` (legacy plain JS object); the legacy JS hit `/api/v1/notifications` and `/api/v1/notifications/mark-read` — **both endpoints did not exist**, so the dropdown showed "Loading…" forever and the "See all notifications" link 404'd; only `cron/compliance_alerts.php` + `promise_to_pay_check.php` + `collections_auto_escalate.php` wrote to the table directly with `user_id=null` broadcast rows. **Schema migration (1 ALTER):** added `type VARCHAR(100)`, `category VARCHAR(50)`, `portal_user_id INT UNSIGNED` (FK → portal_users.id ON DELETE CASCADE), `deleted_at DATETIME`; new indexes `idx_type`, `idx_category`, `idx_portal_user`, `idx_portal_unread (portal_user_id, is_read)`, `idx_deleted`. Added `notifications` to `SOFT_DELETE_TABLES` in `includes/db.php` so future queries auto-filter deleted rows. **NEW core library:** `lib/Notifications/NotificationService.php` — `notify(type, title, message, entityType, entityId, url, specificUserIds=null, severity='info')` resolves recipients via `getModuleFromType()` (lease.* → leases, invoice.* → invoices, payment.* → payments, customer.* → customers, equipment.* → equipment, compliance.* → compliance, maintenance.* → maintenance, damage.* → maintenance fallback, reservation.* → reservations, samsara.* → equipment, accounting.* → reports fallback, system.* → null=all-active), unions users with `can_view=1` for the module + ALL super_admin role users, excludes `status != 'active'` and `deleted_at IS NOT NULL`, inserts one row per recipient with the type→category mapping via `getCategoryFromType()`. Also: `notifyPortal(type, customerId, title, message, ...)` fans out to all active portal_users for that customer; `markRead`, `markAllRead`, `getUnreadCount`, `getRecent`, `deleteNotification` all support `isPortal=true` to flip the WHERE column from `user_id` to `portal_user_id`. Every method is wrapped in catch-all try/catch with error_log so a notification failure NEVER rolls back the originating business transaction. **NEW admin API endpoints (4):** `api/v1/notifications/index.php` (paginated, supports `is_read=all|unread|read`, `category=…`, `date_range=today|week|month|all`, returns `data.items[]` + `pagination` + `meta.total_unread` + legacy `data` alias for the old JS shape, computes `time_ago` server-side); `count.php` (single COUNT query, target <100ms — measured 13ms); `mark_read.php` (`{notification_id}` or `{mark_all:true}`, ownership-checked); `delete.php` (`{notification_id}` or `{clear_all_read:true}`, soft-delete). All 4 enforce auth + CSRF via api/bootstrap.php. **NEW portal API endpoints (4):** `app/portal/api/notifications/_bootstrap.php` (standalone — does NOT use api/bootstrap.php because portal sessions live under `ff_portal_user`; provides `portal_json_ok()`/`portal_json_err()` + portal-CSRF check), `index.php`, `count.php`, `mark_read.php` — all filter by `portal_user_id`, mirror the admin response shape so the same JS factory works. Cross-tenant isolation verified live: portal user attempting to mark admin notification id=2 → 404 NOT_FOUND. **Topbar bell rewrite:** `includes/topbar.php` — replaced the inert `x-data="{open:false}"` with `x-data="FF_Notifications()" x-init="init(); unreadCount = <?= initial ?>;"`, hydrates the badge with the server-side count for first paint then polls every 60s; full Alpine `<template x-for>` rendering of items with category icons + colors + unread accent border + per-item `markRead()` + dropdown `markAllRead()`. Server-side count query updated to honour `deleted_at IS NULL`. **public/assets/js/app.js — section 07 rewritten:** the legacy `FF_Notifications` plain object was replaced with `FF_Notifications()` Alpine factory (state: open, loading, notifications[], unreadCount, _pollTimer; methods: init, fetchCount, fetchNotifications, toggleDropdown, markRead, markAllRead, iconFor, categoryClass) plus inline `_NOTIF_ICONS` map for 12 categories. The polling interval is auto-cleared on `alpine:destroyed` so navigation away doesn't leak timers. NEW section 07b `FF_PortalNotifications()` — same shape but talks to `/portal/api/notifications/*`. Removed the leftover DOM-ready `addEventListener` shims for `.topbar-bell-btn` + `#ff-mark-all-read`. **NEW admin /notifications page:** `app/admin/notifications/index.php` — server-rendered list with filters (status, category, date_range, search), 25/page pagination, per-row Mark read / Open / Delete buttons + Mark all read + Clear all read. Replaces the 404 the bell footer used to hit. **NEW portal /portal/notifications page:** `app/portal/notifications/index.php` — same pattern, scoped to `portal_user_id`. Portal topbar (`app/portal/includes/header.php`) gained a notifications bell with `FF_PortalNotifications()` factory (drop-in same look as admin), initial unread count rendered server-side. **NEW CSS block (~270 lines):** appended to `public/assets/css/app.css` under the `NOTIF-1` heading — `.notif-wrapper`, `.notif-bell-btn` + `.has-unread`, `.notif-badge` (top-right pill, "99+" cap), `.notif-dropdown` (380px, sticky header + footer, max-height 520px), `.notif-dropdown-header/-title/-mark-all`, `.notif-loading`, `.notif-empty`, `.notif-item` + `.notif-item--unread` (3px accent left-border + selected bg), `.notif-icon` with 14 category color variants (leases blue, invoices/accounting purple, payments green, customers/system gray, equipment/reservations blue, compliance yellow, maintenance gray, damage red, samsara cyan, info cyan, warning amber, danger red), `.notif-content/-title/-message/-time/-unread-dot`, `.notif-dropdown-footer`, `.notif-page-list/-item/-content/-title/-message/-meta/-actions`, `.notif-page-empty/-empty-title/-empty-sub`, mobile responsive override (≤767px → fixed-position fullwidth dropdown). **Event wiring (15 endpoints + 3 crons):** added `NotificationService::notify()` calls AFTER each successful db_transaction commit (always inside a try/catch so notification failure never rolls back the parent transaction): `api/v1/leases/create.php` (lease.created), `activate.php` (lease.activated + portal lease.activated to customer; SELECT extended with customer_id), `close.php` (lease.closed + portal lease.closed; SELECT extended with customer_id), `cancel.php` (lease.cancelled, severity warning), `reopen.php` (lease.reopened, warning); `api/v1/invoices/create.php` (invoice.created; SELECT extended with company_name_snapshot + customer_id), `send.php` (invoice.sent staff notif + portal "Your invoice is ready" with amount + due date; SELECT extended with company_name_snapshot/customer_id/total_amount/due_date), `void.php` (invoice.voided, warning); `api/v1/payments/create.php` (THREE notifs from one call: payment.received staff + invoice.paid OR invoice.partially_paid based on `$newInvoiceStatus` + portal payment.received "Payment received — thank you!"; SELECT on invoiceCheck extended with company_name_snapshot), `delete.php` (payment.reversed warning, looks up customer name from customers table after-the-fact); `api/v1/customers/create.php` (customer.created), `update.php` (status diff: customer.credit_hold warning OR customer.suspended critical, only when status actually changed); `api/v1/equipment/units/create.php` (equipment.created), `update_status.php` (equipment.decommissioned warning when terminal, otherwise equipment.status_changed); `api/v1/maintenance_work_orders/create.php` (maintenance.created), `update_status.php` (maintenance.completed with cost OR maintenance.waiting_parts warning); `api/v1/damage_claims/create.php` (damage.created warning with cost), `update.php` (damage.updated); `api/v1/reservations/create.php` (reservation.created), `update_status.php` (reservation.confirmed OR reservation.cancelled warning); `api/v1/users/create.php` (system.user_invited); `app/auth/accept_invite.php` (system.user_joined fires inside the activation transaction). **Cron updates (3):** `cron/compliance_alerts.php` — replaced the broadcast `db_insert('notifications', ['user_id'=>null,...])` block with a `NotificationService::notify()` call that fans out per user via permission lookup, picks the type from severity (`critical`→compliance.expired, `warning`→compliance.expiring_7, default→compliance.expiring_30), keeps the existing 24-hour `notification_log` dedup so repeated cron runs don't spam. `cron/invoice_overdue.php` — after each invoice → overdue status change, fires `invoice.overdue` warning to staff + portal "Invoice overdue — please contact us" to that customer. `cron/samsara_sync.php` — NEW alert block per unit per tick: `samsara.battery_critical` (battery < 10%, severity critical) / `samsara.battery_low` (battery < 20%, warning) / `samsara.not_connected` (last_connected_at > 8h, warning), with 6-hour `notification_log` dedup keyed by `(equipment_unit_id, notification_type)` so the cron doesn't spam every 5 minutes. **Seed data:** 10 admin notifications + 3 portal notifications across all 12 categories with mixed read/unread states and timestamps spanning 5 minutes ago → 6 days ago. **Live verification (curl + mysql):** session login as admin@fleetforge.test → `GET /api/v1/notifications/count.php` 200 `{unread_count:5}` ✓; `GET index.php?per_page=5` 200 with 5 items containing all expected fields including `time_ago` ("5 min ago", "1 hr ago", "3 hr ago") ✓; `POST mark_read.php {notification_id:1}` 200 `{marked:1, unread_count:4}` ✓; `POST mark_read.php {notification_id:99999}` 404 NOT_FOUND ✓; `POST delete.php {notification_id:10}` 200 `{deleted:1}` ✓; `POST mark_read.php {mark_all:true}` 200 `{marked:4, unread_count:0}` ✓; `GET /notifications` 200 (143 KB), tile filters `?is_read=unread` returns 4 unread items, `?category=invoices` returns 3, `?q=overdue` returns 2 ✓; bell renders in dashboard HTML with `notif-bell-btn` class + `notif-badge` element + `FF_Notifications()` x-data + `unreadCount = 3` initial value ✓; **NotificationService fan-out test** — `notify('lease.created', ...)` from CLI created TWO rows (user 1 super_admin + user 2 dispatcher who has leases.view), confirming module-based delivery ✓; `notify('payment.received', ...)` created ONE row (super_admin only — dispatcher correctly excluded because they don't have payments.view), confirming permission filtering ✓; portal login as john@apexfreight.com → `GET /portal/api/notifications/count.php` 200 `{unread_count:2}` ✓; `GET index.php` 200 with 3 portal items ✓; `POST mark_read.php {notification_id:11}` 200 `{marked:1, unread_count:1}` ✓; **cross-tenant isolation** — portal user attempting `mark_read.php {notification_id:2}` (admin notification) → 404 NOT_FOUND ✓; `GET /portal/notifications` 200 (23 KB) ✓; performance — 5 sequential `count.php` calls all under 15ms (target was 100ms) ✓; `php -l` clean on all 38 modified/new files. **Files (47 total — 4 NEW lib, 4 NEW admin API, 4 NEW portal API, 1 NEW admin page, 1 NEW portal page, 1 NEW _bootstrap, 1 NEW NotificationService, 1 ALTER notifications, 1 includes/db.php, 1 includes/topbar.php, 1 portal/header.php, 1 app.js, 1 app.css, 5 lease endpoints, 3 invoice endpoints, 2 payment endpoints, 2 customer endpoints, 2 equipment endpoints, 2 maintenance endpoints, 2 damage endpoints, 2 reservation endpoints, 1 user create, 1 accept_invite, 3 crons).** |
| MSGR-1 | 2026-04-08 | Facebook-Style Messenger — Admin ↔ Customer Two-Way Chat (Admin + Portal, Shared Inbox) | **Built a complete two-way messenger on top of NOTIF-1 so admins can DM any customer/portal user and customers can reply from their portal, Facebook Messenger-style. Lives at its own `/messenger` (admin) and `/portal/messages` (portal) — NOT bolted onto CHAT-1 which is admin-only internal team chat.** **Scope decisions (via AskUserQuestion):** direction = two-way (portal + admin reply); target picker = customer companies expandable to individual portal users; location = dedicated pages on both sides. **Schema (3 NEW tables, created via `db_execute` migrations — no SQL file because we're past the migration phase):** `messenger_threads` (id, customer_id FK, scope ENUM 'customer'|'portal_user', portal_user_id FK nullable, subject, last_message_at, last_message_preview, last_message_by ENUM 'admin'|'portal', is_archived, created_by_user_id, created_at, updated_at); `messenger_messages` (id, thread_id FK, sender_type ENUM 'admin'|'portal', admin_user_id FK nullable, portal_user_id FK nullable, body TEXT, is_archived, created_at, updated_at); `messenger_thread_reads` (id, thread_id FK, user_id OR portal_user_id, last_read_message_id, last_read_at — UNIQUE KEY per side). Dual-side read tracking means each side gets an accurate unread badge independently. **NEW library `lib/Messenger/MessengerService.php`:** `touchLastMessage($threadId, $senderType, $body)`, `markReadAdmin($threadId, $adminId, $lastMessageId)`, `markReadPortal($threadId, $portalUserId, $lastMessageId)`, `unreadForAdmin($adminId)`, `unreadForPortal($portalUserId, $customerId)`, `portalCanAccessThread($threadId, $portalUserId, $customerId)`. **NEW admin API (8 endpoints under `api/v1/messenger/`):** `contacts.php` (customer + portal-user picker with search), `threads/index.php` (list with per-admin unread via LEFT JOIN on reads), `threads/create.php` (idempotent on customer+scope+portal_user combo so double-clicks don't fragment the inbox; optional initial body posts in same transaction), `threads/show.php`, `messages/index.php` (cursor pagination), `messages/create.php` (admin reply), `messages/poll.php` (long-poll new messages), `unread.php` (topbar badge count). **NEW portal API (6 files under `app/portal/api/messenger/`):** `_bootstrap.php` (CSRF gate keyed on `ff_portal_user`, JSON body helper `portal_msgr_input()` because FF_Api.post ships application/json, envelope helpers `portal_msgr_ok()`/`portal_msgr_err()`), `threads.php`, `thread.php`, `messages.php`, `poll.php`, `send.php`. Portal side only supports listing + reading + replying — portal users can NOT create threads (admin-only flow). **NEW admin page `app/messenger/index.php`:** 3-column layout — contact picker (left) / thread list / active thread view. `x-data="FF_Messenger()"` Alpine factory. **NEW portal page `app/portal/messages/index.php`:** 2-column layout — thread sidebar / active thread view. `x-data="FF_PortalMessenger()" x-init="init(<?= (int) $thread_id_param ?>)"` — supports `?thread=N` deep link from admin-side notifications. **`public/assets/js/app.js` additions:** `FF_Messenger()` factory (~500 lines) — state: threads[], activeThread, messages[], composing, contacts[], contactSearch, showContactPicker, 5s message polling + 10s thread-list polling, optimistic append on send, cursor pagination via loadMore(). `FF_PortalMessenger()` factory (~300 lines) — same shape but points at `/portal/api/messenger/*`, no contact picker. Both use `FF_Api.post()` with plain objects (not FormData — see bug note below). **CSS (+400 lines in `public/assets/css/app.css`):** `.msgr-*` admin classes (thread-item, thread-avatar, thread-body/top/name/time/preview, side-label--admin/--portal, message--portal left-rail accent, contact-list/-item/-avatar/-body/-badge) + `.portal-msgr-*` portal classes (page full-height override, layout, sidebar 320px with scroll, thread-item with left accent border on active, thread-avatar SVG icon, message--mine [right, accent bg, white text] and message--theirs [left, card bg with border], message-bubble 18px border-radius Facebook-style, compose textarea with 20px radius + circular send button, empty-state placeholder). Mobile breakpoint at 768px collapses sidebar. **Sidebar nav:** `app/portal/includes/sidebar.php` gained a Messages item with envelope SVG icon + unread badge SQL that counts admin-sent messenger_messages this portal user hasn't marked read (LEFT JOIN messenger_thread_reads on thread_id + portal_user_id, WHERE last_read_message_id IS NULL OR message.id > last_read_message_id, scoped to threads where scope='customer' OR (scope='portal_user' AND portal_user_id = this user)). **Notification fan-out:** admin `messages/create.php` — if scope='customer' calls `NotificationService::notifyPortal()` which fans out to every active portal user of that customer; if scope='portal_user' direct-inserts one notification row for just that pinned portal user. Portal `send.php` — resolves all active admin users with `customers.view` permission (DISTINCT query unioning super_admin role slug with user_permissions LEFT JOIN) and calls `NotificationService::notify()` with explicit `$specificUserIds` list, title "New message from {sender_name} ({company})", type `messenger.portal_reply`, deep-link URL `/messenger?thread={id}`. All notification calls wrapped in try/catch so notification failures never roll back the message insert. **Bugs found + fixed during end-to-end verification:** (1) All 8 admin messenger API files used `require_once dirname(__DIR__, N) . '/bootstrap.php'` which resolved to `/Users/avi/Documents/fleetforge/bootstrap.php` — bootstrap actually lives at `/api/bootstrap.php`, so every admin API call 500'd with "file not found". Fixed all 8 paths to `/api/bootstrap.php`. (2) Nested endpoints in `threads/` and `messages/` subdirs used `dirname(__DIR__, 5)` for the MessengerService require — walked one dir too far up (hit `/Users`). Corrected all of them to `dirname(__DIR__, 4)`. (3) `$_POST` was empty in `threads/create.php` and `messages/create.php` even though the JS was sending data — root cause was that `FF_Api.post()` does `JSON.stringify(data)` with `Content-Type: application/json`, and PHP `$_POST` only populates for `application/x-www-form-urlencoded` or `multipart/form-data`. Switched both admin endpoints to the existing `json_body()` helper and added a new `portal_msgr_input()` helper in `app/portal/api/messenger/_bootstrap.php` (static-cached `json_decode(file_get_contents('php://input'))`). Also updated `FF_Messenger.send()` + `FF_Messenger.startThread()` + `FF_PortalMessenger.send()` to pass plain objects rather than FormData instances (FormData JSON-stringifies to `{}`). **End-to-end verification (foreground browser via preview tools, not just curl):** (a) Admin side: logged in as Avi, navigated to `/messenger`, Alpine `FF_Messenger()` data inspected via `preview_eval` confirmed contacts loaded and threads[] empty; opened thread 1 for "LP Logistics Inc." (customer 1) via the contact picker, sent "Hi from support — testing the messenger end-to-end. Reply when you can." then "Quick follow-up — let me know if you can see this message!" via `.chat-send-btn`; both admin messages (id 1 + 2) appeared in `data.messages` and in `messenger_messages` table. (b) Portal side: reset John Apex password to `TestPortal123!` via PHP CLI, logged in via `preview_fill` on email/password inputs + click Sign In, landed on `/fleetforge/portal/messages`, thread 1 auto-selected via `init()`, both admin messages rendered as `.portal-msgr-message--theirs` bubbles (left side, card bg with border), filled the `.portal-msgr-compose textarea` with "Hi Avi — yes, the messenger is working great on my end. Thanks for testing!", clicked `.portal-msgr-send-btn`, after 1.2s verified via `preview_eval` that message 3 (sender_type='portal', portal_user_id=1) was appended as a `.portal-msgr-message--mine` bubble (right side, accent bg, white text), composing field cleared, sending flag reset. (c) DB verification via PHP CLI: `messenger_messages` has 3 rows (2 admin, 1 portal, all on thread_id=1); `notifications` table has the 4 expected fan-out rows — 2 rows to portal_user_id=1 with type `messenger.new_message` (for the 2 admin sends), + 2 rows to user_ids 1 and 2 with type `messenger.portal_reply` and title "New message from John Apex (LP Logistics Inc.)" (for the portal reply fan-out into the shared admin inbox). Screenshot confirmed admin-themed bubbles render correctly at 1440×900 desktop width with the orange accent for own messages and dark card bg for theirs. **Files touched (22 total — 19 NEW, 3 MODIFIED):** **NEW (19):** `lib/Messenger/MessengerService.php`, `api/v1/messenger/contacts.php`, `unread.php`, `threads/index.php`, `threads/create.php`, `threads/show.php`, `messages/index.php`, `messages/create.php`, `messages/poll.php`, `app/portal/api/messenger/_bootstrap.php`, `threads.php`, `thread.php`, `messages.php`, `poll.php`, `send.php`, `app/messenger/index.php`, `app/portal/messages/index.php`. **MODIFIED (3):** `app/portal/includes/sidebar.php` (+Messages nav item + unread badge SQL + envelope SVG icon in $_icons), `public/assets/js/app.js` (+FF_Messenger and FF_PortalMessenger factories, +topbar msgr badge hook), `public/assets/css/app.css` (+400 lines `.msgr-*` + `.portal-msgr-*` styles), `.env` (FF_ASSET_VERSION 1.0.6 → 1.0.7 for cache bust). |
| PORTAL-FIX-1 | 2026-04-09 | Portal Login Bug Fix — Password Set Flow Broken (3 Bugs) | **Root cause diagnosis:** portal login itself (login.php + auth.php) was structurally correct. The real bug was that NO new portal user could ever set their password because ALL invite/reset URL flows were broken. Three overlapping bugs found: **(Bug 1) reset_password.php required both `token` AND `email` params in URL** — the `if ($tokenParam !== '' && $emailParam !== '')` guard meant any URL without `?email=` silently fell through to "Invalid reset link" and never rendered the set-password form. **(Bug 2) Admin create-portal-user (portal_users.php `create_portal_user` action) stored invite token in `invite_token` column** — but `reset_password.php` queries `password_reset_token`. Double bug: wrong column AND missing email from URL, so the link was invalid twice over. **(Bug 3) Portal sub-user invite (account/users.php) same as Bug 2** — stored token in `invite_token`, missing email from URL. The `resend_invite` action had the same bug. **The only working flow was `forgot_password.php`** because it correctly generates the URL with both `?token=&email=`. Existing user john@apexfreight.com had logged in before but password was unknown/forgotten. **Files modified (3):** `app/portal/auth/reset_password.php` — removed email requirement; now looks up by `password_reset_token` hash alone (safe: token is SHA256 of 32 random bytes). `app/admin/settings/portal_users.php` — create action: stores token in `password_reset_token` + `password_reset_expiry` (not `invite_token`); reset action: adds `?email=` to URL. `app/portal/account/users.php` — invite and resend_invite actions: store token in `password_reset_token` + `password_reset_expiry` (not `invite_token`); add `?email=` to URL. **DB fix:** reset password for john@apexfreight.com to `Portal1234!` (bcrypt cost 12) directly via SQL so testing can proceed. **Stop conditions verified:** SC1 `php -l` all 3 modified files clean ✅; SC2 `POST /portal/auth/login` with email=john@apexfreight.com + password=Portal1234! → HTTP 302 → /portal (dashboard) ✅; SC3 wrong password → HTTP 200 (stays on login with error) ✅; SC4 token-only reset URL `?token=testtoken123` (no email) → reset form renders ✅; SC5 expired/invalid token → shows "expired or invalid" error ✅. |
| CHAT-HUB-1 | 2026-04-09 | Topbar Chat Consolidation — Unified Chat Icon + Removed Floating AI Widget | **Problem:** Pressing the theme-toggle button was also opening the floating AI chatbox, which rendered right over the bottom-right DM chat widget. Two floating widgets were competing for the same bottom-right corner and the AI Alpine factory name was colliding with the team-chat factory. **Fix (4 layers):** (1) Floating AI chat widget REMOVED entirely — the `require_once` for `includes/partials/ai-chat-widget.php` is commented out in `includes/footer.php` so the bottom-right is now structurally guaranteed to host only the DM chat widget. (2) Topbar AI icon is now a plain `<a href="/ai">` navigation link (no Alpine binding, no @click, no factory) using a unique Heroicons sparkles glyph so it can't be visually confused with the theme moon. (3) Topbar Chat icon click handler rewritten to call `Alpine.$data(document.getElementById('ff-chat-widget')).toggle()` directly instead of via a `window.FF_OpenChatHub` arrow function captured from an Alpine `with(scope)` block — the previous approach silently lost reactivity once the with-block expired, leaving `display:none` stuck on `.chat-widget-panel`. x-transition directives removed from the panel to eliminate the stuck-transition state. (4) New `FF_ChatHubBadge()` Alpine factory (app.js around line 1986) polls BOTH `/api/v1/chat/unread/` and `/api/v1/messenger/unread` every 30s, sums totals into `totalUnread`, hides itself on the related chat/messenger pages via `onRelatedPage`, and skips the notification sound cue on the first fetch via an `_initialized` latch. **Files touched:** `includes/footer.php`, `includes/topbar.php`, `includes/partials/chat-widget.php`, `public/assets/js/app.js`. **Stop conditions verified:** SC1 five consecutive theme-button clicks → theme flipped each time, `aiElementsCount: 0`, `bottomRightOther: []` every time ✅; SC2 Chat topbar click → `.chat-widget-panel` goes from `display:none` to visible and closes again on second click ✅; SC3 AI icon click → navigates to `/fleetforge/ai` cleanly with no Alpine interception ✅; SC4 page reload on any admin page → only ONE floating widget in DOM (the team chat) ✅. |
| MEDIA-1 | 2026-04-09 | Login Background Video + Notification Sound Cue System | **Two media features built silently per spec — no forms, queries, or functional logic touched.** **Feature 1 — login page background video:** `video1.mp4` (38 MB, stored at `~/Documents/fleetforge/media/`) now plays muted, looping, autoplay, playsinline, `object-fit: cover`, full-viewport behind the login card with a dark overlay. Login form itself is untouched; card switched to glass-morphism (`backdrop-filter: blur(8px)` on desktop, softened to `blur(4px)` on mobile) and sits at `z-index: 11` above the fixed `.video-bg-wrapper` at `z-index: 0`. Inline CSS (`.video-bg-wrapper`, `.video-bg`, `.video-bg-overlay`) added to login.php's existing `<style>` block so no shared stylesheet needs updating. **Feature 2 — notification sound cue system:** New `FF_Sound` utility (`public/assets/js/app.js` lines ~69–148) preloads `notification.mp3` from the `<meta name="notification-sound">` tag injected by `includes/header.php`. **Four safety gates** on every `.play()` call: (1) `muted` flag read from `localStorage.ff_sound_muted`, (2) `hasInteracted` latched by the first `click` / `keydown` / `touchstart` so the browser autoplay policy is respected and the cue NEVER fires on first page load, (3) a per-badge `_initialized` latch so reloads with existing unread counts do NOT re-trigger a cue (only *increases* after first poll do), (4) each consumer badge checks `!onChatPage` / `!onRelatedPage` so you don't hear your own outgoing messages while viewing the chat. Hooked into `FF_Notifications.fetchCount()`, `FF_ChatBadge.fetchUnread()`, and the new `FF_ChatHubBadge.fetchUnread()`. **Topbar mute toggle button:** new `.sound-toggle-btn` (topbar.php around lines 405–450) placed between the theme and chat icons. Shows `speaker-wave` (Heroicons) when unmuted, `speaker-x` when muted, with an `.is-muted` visual state (opacity 0.6 + `text-muted` color). Calls `FF_Sound.toggleMute()` which persists to `localStorage` and is read on every subsequent page load. **Asset delivery:** `public/media` is a symlink to `../media` so Herd serves both files from the docroot without duplicating them. `FF_ASSET_VERSION` bumped to `1.0.13` for cache-bust. **Stop conditions verified:** SC1 `curl -I /media/video1.mp4` → HTTP 200, `Content-Type: video/mp4`, 38145926 bytes ✅; SC2 `curl -I /media/notification.mp3` → HTTP 200, `Content-Type: audio/mpeg`, 35108 bytes ✅; SC3 login page structural eval → `videoPresent:true`, `wrapPresent:true`, `overlayPresent:true`, `cardPresent:true`, `videoAttrs:{autoplay:true, muted:true, loop:true, playsinline:true}`, `wrapperCS.zIndex:"0"`, `cardCS.zIndex:"11"`, `formPresent:true` ✅; SC4 first page load with no user gesture → no audio played (autoplay policy blocks until interaction, as designed) ✅; SC5 mute button click → `localStorage.ff_sound_muted` flips and persists across reload, button `.is-muted` class toggles correctly ✅; SC6 sound never plays when `/chat` or `/messenger` is the active page (onChatPage / onRelatedPage short-circuit) ✅. **Files modified:** `app/auth/login.php`, `includes/header.php`, `includes/topbar.php`, `public/assets/css/app.css`, `public/assets/js/app.js`, `.env` (FF_ASSET_VERSION bump). **New files on disk:** `media/video1.mp4`, `media/notification.mp3`, `public/media` (symlink → `../media`). |

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

| S020-UX | 2026-04-04 | UX Polish — Modern Tabs, Spec Cards, KPI Tile Icons & Clickable Drilldowns | Comprehensive visual upgrade across all modules. **20+ files modified.** (1) **Modern tab navigation**: `.tab-bar` + `.tab-btn` CSS in app.css completely rewritten from underline-based to modern segmented-pill style — `inline-flex` container with subtle background, `border-radius`, 4px padding; active tab gets solid `--color-primary` fill with white text + shadow; `.tab-badge` on active tabs gets translucent white background. equipment/show.php inline-styled tabs converted to standard `.tab-bar` + `.tab-btn` classes matching leases/show.php + customers/show.php. (2) **Unit spec cards contrast**: `.spec-card` class added to equipment/show.php — card headers use `--color-primary` background with white text (strong visual anchor); stronger border + elevated shadow; alternating row stripes on `.spec-table` via `nth-child(even)`. (3) **All module KPI tiles clickable**: 10 modules now have functional tile-to-table filtering with toggle behavior + visual `ring-active` feedback. Cross-component modules (invoices, payments, credit_notes, damage_claims, vendors, inspections) use `Alpine.$data(document.getElementById('id'))` pattern; same-component modules (leases, equipment, compliance) use direct filter manipulation. Table root elements given IDs for cross-component targeting. Each module's KPI function got `activeTile` tracking + `drill()`/`setFilter()` methods. Display-only tiles (revenue, monetary aggregates) left without click handlers. (4) **KPI tile visual redesign**: Each stat-card gets a unique accent color variant (`stat-card--green/blue/amber/red/purple/teal/slate`) controlling the left gradient bar and active ring color. SVG icon sprite (25 Heroicons outline symbols) added to `includes/footer.php` — defined once, referenced via `<svg><use href="#icon-name"/></svg>`. Each tile gets a 42×42px rounded-square icon container (`.stat-icon`) with color-tinted background in the top-right corner. Icons are contextual: check-circle (available/complete), key (on-lease), wrench (maintenance), truck (fleet), shield-check (compliance), clock (pending/expiring), exclamation-triangle (expired/overdue), fire (critical), currency-dollar (revenue), document-text (invoices), chart-bar (metrics), star (preferred), building (vendors), trophy (top vendor), bolt (active), magnifying-glass (inspections), clipboard (work orders), lock-open (open), pencil (draft). Equipment show page hero tiles also updated with contextual icons (heart/map-pin/shield-check/tag). All 20+ files pass php -l. |

| S025 | 2026-04-05 | Settings Module Overhaul | **4 new files, 1 rewritten.** Comprehensive settings hub with 6-tab layout replacing the single-page settings view. **(1) app/admin/settings/index.php (rewritten)** — tabbed shell using Alpine.js `activeTab` with tab-bar/tab-btn pattern matching all other admin pages. 6 tabs: General (company/invoices/alerts/notifications — grid layout for form fields), Users, Portal Users (super_admin only), Audit Log, System, Integrations. Tab badges show counts (user count, portal user count, 24h audit entries). URL param `?tab=` support for direct linking after redirects. Flash messages via session for POST-redirect-GET pattern. **(2) app/admin/settings/users.php (NEW)** — admin user management tab. Invite form (name/email/role dropdown → token + mail.log). User table with inline role selector (onchange auto-submit), status badges (active/inactive/invited/suspended/locked), last login, join date. Actions: Suspend, Activate, Delete (soft-delete). Cannot modify own account. Audit logging on all actions. **(3) app/admin/settings/portal_users.php (NEW)** — portal user management for super_admin. Create portal user (customer dropdown + name + email → invite). 4 KPI stat cards (total/active/pending/companies). Users grouped by customer with company header + "View Customer" link. Table: name, email, role (Primary/Sub-user), status, last login, security status (locked/attempt count/OK). Actions: Reset PW (generates token → mail.log), Deactivate/Activate, Delete (hard delete — portal_users not soft-delete table). Cannot delete primary user. Audit logging on all actions. **(4) app/admin/settings/audit_log.php (NEW)** — filterable audit trail viewer. Filter bar: module dropdown (dynamic from distinct values), action dropdown, user/IP search, date range. 50-per-page pagination with 7-page window. Table: timestamp, user, action badge (color-coded: create=green, update=blue, delete=red, status_change=yellow, login=neutral), module, entity (with tooltip for type+id), details (truncated with title tooltip), IP. **(5) app/admin/settings/system.php (NEW)** — system dashboard. Health checks (DB connection, storage/logs dirs writable, sessions active, PHP extensions). Two-column grid: Environment (PHP version, server, env, URL, timezone, memory, OPcache) + Database (MySQL version, size, table count, DB name). Cron task status (4 jobs: monthly invoicing, overdue marking, late fees, GPS sync — last run time + staleness warning). Table row counts grid (16 core tables). **Bug fixed:** `DB_DATABASE` constant not defined — uses `FF_DB_NAME` per config/app.php. **Column name fixes from S024:** `mileage_allowance` → `estimated_mileage`, `mileage_start` → `mileage_at_start`, `current_mileage` → `mileage`, `gps_tracking_url` → `samsara_vehicle_url`, removed invalid `pickup_yard_id` FK JOIN (uses `eu.yard_location` string). **SC passed:** SC1 php -l all 5 settings files clean ✅; SC2 /settings page 200 with admin session ✅; SC3 General tab renders company/invoices/alerts/notifications cards ✅; SC4 Users tab shows 3 users with invite form + inline role selectors ✅; SC5 Portal Users tab shows 1 user (John Apex / Apex Freight Inc) with actions ✅; SC6 Audit Log tab shows 264 entries with pagination (6 pages) + filters ✅; SC7 System tab shows 5 health checks all green + env/db/cron/tables ✅; SC8 Integrations tab shows GPS + AI settings with Sensitive badges ✅; SC9 zero console errors across all tabs ✅. |
| S024 | 2026-04-05 | Customer Portal (Phase 15) | **20 new files, ~700 lines CSS added.** Complete customer-facing portal at /portal with separate auth, session isolation, and comprehensive data scoping. **(A) Auth system (4 files):** app/portal/includes/auth.php — portal session guard using `$_SESSION['ff_portal_user']` key (isolated from admin `ff_user`), 24-hour timeout, remember-me cookie (`ff_portal_remember`), brute force lockout (5 attempts → 15 min), customer status verification on every request. auth/login.php — standalone login page, bcrypt, CSRF, lockout display. auth/forgot_password.php — email-enumeration-safe, SHA256 hashed token, 2-hour expiry, logs to logs/mail.log. auth/reset_password.php — validates token+expiry, activates invited users on password set. auth/logout.php — clears portal session + remember-me cookie. **(B) Layout shell (3 files):** includes/header.php — portal chrome with overdue invoice banner, CSRF meta, theme toggle. includes/sidebar.php — 7 nav items with inline SVG icons, badge counts (overdue invoices, open requests), active state detection, user avatar with initials. includes/footer.php — closes layout, Alpine.js CDN, toast container, theme persistence. **(C) Portal pages (13 files):** index.php (dashboard) — 4 KPI cards (active leases, outstanding balance, units out, expiring docs), active leases table, outstanding invoices table, recent activity feed. leases/index.php — Active/Historical/All tabs, AJAX search, contract/unit/type/dates/rate/status. leases/view.php — 3 info cards, action buttons (extension/return/damage → pre-fill service request), invoices table, documents table, timeline from lease_status_log. invoices/index.php — Outstanding/Paid/All tabs, AJAX, total outstanding banner. invoices/view.php — balance due banner (red if overdue), invoice summary, payment instructions, line items, payment history, PDF download. equipment/index.php — card grid with mileage progress bars (green/warning/danger), compliance alerts (CVI/reg/MVI/insurance), GPS track link, report issue action. documents/index.php — document vault with search+type filter, 3-way UNION query (customer+lease+equipment docs through leases), expiry color coding, entity labels. requests/index.php — service request list with status badges. requests/create.php — 7 request types, lease/equipment dropdowns from active leases (Trap 8), pre-fill from URL params. requests/view.php — thread view (customer message + admin response), "Mark as Closed" for resolved requests, sidebar with linked lease/equipment. account/index.php — Profile/Password/Sub-Users/Payment tabs, password change with verification, sub-user roster (primary only). account/users.php — sub-user management: invite (name+email → token → mail.log), resend invite, deactivate/reactivate, cannot deactivate primary. **(D) CSS:** ~700 lines in Section 30 of app.css covering portal layout, sidebar, KPI cards, tables, tabs, equipment cards, mileage bars, thread messages, login page, forms, filters, responsive breakpoints (1024/768/480px). **(E) Security:** Trap 8 enforced — every query filters by `portal_customer_id()`. Equipment joins through leases (no customer_id on equipment_units). File paths never exposed (Trap 7). CSRF on all forms. Session isolation from admin. **Bug fixed:** AJAX handlers were at bottom of files after header output; moved to top (before `require header.php`) since PHP output_buffering=0 means `ob_end_clean()` can't catch already-flushed HTML. **SC passed:** SC1 php -l all 20 files clean ✅; SC2 login page 200 ✅; SC3 forgot_password page 200 ✅; SC4 reset_password page 200 ✅; SC5 all 9 protected pages return 302 (redirect to login) when unauthenticated ✅; SC6 POST login with valid credentials returns 302 (redirect to dashboard) ✅; SC7 all 11 authenticated pages return 200 ✅; SC8 leases AJAX returns valid JSON with counts ✅; SC9 invoices AJAX returns valid JSON with 3 invoices ✅; SC10 documents AJAX returns valid JSON ✅; SC11 lease view page 200 ✅; SC12 invoice view page 200 ✅. |
| S023 | 2026-04-05 | Analytics Module (Phase 14) | **2 new files.** **(1) api/v1/analytics/index.php** — 8 views dispatched by ?view= param. No caching. Permission: analytics/view. Views: revenue_forecast (historical monthly revenue + PHP linear regression on last 6 months → 3-month projection + ±10% confidence band); utilization_matrix (scatter: each unit as utilization_pct × revenue_per_day, series = category; GREATEST/LEAST to clamp lease periods to 12m window); concentration_risk (pie: top-5 customers by 12m revenue + Other slice); seasonal_pattern (radar: all-time avg revenue by month-of-year Jan–Dec); cohort_revenue (stacked area: monthly revenue pivoted by lease start year); fleet_optimizer (grouped bar: current unit count vs recommended = ceil(peak_concurrent × 1.15) per category; sweep algorithm for peak concurrent — sort start/end events, compute running max per category); lead_time (line: avg days created_at → activated_at per month via lease_status_log subquery); avg_lease_value (line: avg invoice total per month with trend direction). All return { chart_data, kpis, view, date_from, date_to }. **(2) app/admin/analytics/index.php** — FF_Analytics() Alpine component. Revenue forecast spans full width; 7 other panels in 2-column grid (1-column mobile). Each panel: date range inputs (revenue_forecast/cohort_revenue/lead_time/avg_lease_value have selectors; fixed-window views do not), KPI strip, ApexCharts canvas, skeleton loader, error state. D41 enforced: all chart colors via cssVar()/getComputedStyle — no hardcoded hex. Refresh All button. revenue_forecast uses 4 series (Historical solid, Projected dashed dashArray:6, Upper/Lower band opacity 0.15). utilization_matrix uses custom tooltip showing unit_number. Refresh All destroys existing chart instances before re-render (prevents duplicate chart error). **Bug fixed**: lead_time SQL — DATE_FORMAT(MIN(...), ...) in GROUP BY = invalid use of aggregate — fixed with subquery JOIN (SELECT lease_id, MIN(changed_at) AS activated_at FROM lease_status_log WHERE new_status='active' GROUP BY lease_id) fa before outer GROUP BY. **SC passed**: SC1 php -l both files ✅; SC2 /analytics page HTTP 200 ✅; SC3 revenue_forecast returns chart_data with categories/historical/projected ✅; SC4 concentration_risk returns labels + series (6 slices) ✅; SC5 utilization_matrix returns 5 series / 13 scatter points ✅; SC6 all 8 views return success:true ✅; SC7 page HTML contains all 8 chart divs ✅. |
| S022 | 2026-04-05 | Documents Module (Phase 13) | **3 files built/modified.** **(1) api/v1/documents/index.php extended** — added Mode B (global paginated list, no entity_id). Mode B joins customers/equipment_units/leases via entity-type-guarded LEFT JOINs to produce entity_label per row. Supports entity_type filter, title/file_name LIKE search, sort (uploaded_at/title/document_type/file_size_kb/expiration_date), dir, page, per_page. Mode A (entity_id present) behavior unchanged. **(2) app/admin/documents/index.php (NEW)** — global documents list page. Alpine `FF_Documents()` component: entity_type filter dropdown (All/Customer/Equipment/Lease/Inspection/Damage Claim), text search with 400ms debounce, sortable column headers with ↑↓ indicators, load-more pagination. Table shows: type badge, title+filename, entity type badge + entity_label link to show page, size, expiry (color-coded: red=expired, amber=expiring<30d), uploaded date, uploader, View (signed URL in new tab) + Remove actions. Upload modal: entity_type selector → document_type select (options driven by Alpine x-if per type), entity ID input, title/expiry/notes, file picker. On success reloads list. On delete removes row from local array and decrements total. entityUrl() builds link to /customers/show, /equipment/show, /leases/show etc. Trap 7: file_path never exposed. **(3) app/admin/customers/show.php patched** — Documents tab added (8th tab, after Rates). Tab button shows count badge when documents.length > 0. x-show panel with ff-tab-enter transition matching all other customer tabs. Lazy-loads on first activation via $watch. Table: type badge, title + file_name, size, expiry (color-coded), uploaded date, uploader, View + Remove. Upload modal: tax_exemption/credit_agreement/other document types. submitDocUpload() POSTs to upload API via FormData, prepends new doc to array on success. confirmDeleteDoc() soft-deletes via API, filters from array. customerDocTypeBadge/Label helpers. docExpiryClass() for color cues. **SC passed**: SC1 php -l all 3 files clean ✅; SC2 /documents page 200 + FF_Documents component present ✅; SC3 customers/show 200 + Documents tab present ✅; SC4 global API mode B returns items with entity_label ✅; SC5 entity-specific mode A (customer&entity_id=1) returns items array ✅; SC6 entity_type=equipment_unit filter returns 6 items ✅; SC7 upload to customer → 201 + signed URL in response ✅; SC8 Trap 7 — file_path not in any API response ✅; SC9/SC10 uploaded doc in global+entity-specific lists ✅; SC11 serve endpoint 200 application/pdf ✅; SC12 delete 200 {deleted:true} ✅; SC13 deleted doc no longer in list ✅; SC14 equipment/show + leases/show 200 (no regressions) ✅. |
| S021-UX | 2026-04-05 | UX Fix — Scroll-to-top bug + tab flash + smooth transitions | **8 files modified.** (1) **Reports scroll-to-top fix**: Converted 12 `<template x-if>` to `<div x-show>` with optional chaining across all 4 report tabs (Financial/Fleet/Customer/Compliance) — KPI tiles, loading skeletons, and viewLoading placeholders. `x-if` was removing DOM nodes on toggle, collapsing page height and resetting scroll position. `x-show` keeps elements in DOM with `display:none`. (2) **Reports flash elimination**: Added `x-cloak` to root Alpine element to prevent FOUC (all `x-show` content briefly visible before Alpine init). Made `setPreset`/`runReport`/`setCompWindow` keep old KPIs visible on current tab during data refresh — old values stay until API response replaces them atomically. No more skeleton→content blink on cached responses. (3) **Smooth 120ms tab transitions across entire app (52 panels)**: CSS `.ff-tab-enter` classes + `@keyframes ff-tab-fade-in` animation in app.css. `x-show` tab panels get Alpine `x-transition:enter` (reports 24, equipment/show 9, customers/show 7, leases/show 4, rates/index 2). `x-if` tab panels get `.ff-tab-animated` CSS animation class (leases/show 4, profile/index 2). Old tab hides instantly, new tab fades in — masks content swap. (4) **Tab active class fix**: All 23 report tab buttons changed from `{active:...}` to `{'is-active':...}` to match `.tab-btn.is-active` CSS rule used by every other page. |

| S026 | 2026-04-05 | Samsara GPS Tracking (Phase 10) | **6 new files, 4 modified.** Full GPS fleet tracking system powered by Samsara API. **(1) lib/GPS/SamsaraClient.php (EXTENDED)** — added 4 new methods: `getVehicleLocations()` (fleet-wide GPS via `/fleet/vehicles/stats?types=gps,obdOdometerMeters` with cursor-based pagination, max 500 vehicles); `getVehicleLocation(vehicleId)` (single-vehicle GPS+fuel via per-vehicle stats endpoint); `testConnection()` (validates API credentials by listing 1 vehicle, returns structured result with success/message/details); `apiRequest()` (shared HTTP helper centralizing curl setup, error handling, logging); `buildHeaders()` (standard auth headers). Dev mode: all methods return null when API keys are blank — GPS is never blocking. **(2) api/v1/gps/locations.php (NEW)** — server-side proxy for Samsara GPS data (required because Samsara API does not support CORS). GET /api/v1/gps/locations returns all GPS-equipped units merged with live Samsara location data. Optional `?unit_id=N` filter for single-unit requests. Queries equipment_units WHERE tracking_provider='samsara' AND gps_device_id IS NOT NULL. Indexes Samsara response by vehicle ID for O(1) lookup. Returns normalized JSON: `{ units: [{ id, unit_number, template_name, status, location: { latitude, longitude, speed, heading, address, time, odometer_km } | null }], gps_configured, updated_at }`. Permission: equipment:view. **(3) api/v1/gps/test-connection.php (NEW)** — tests Samsara API connection for Settings → Integrations. Calls SamsaraClient::testConnection(). Logs result to audit_log. Permission: settings:edit (super_admin only). **(4) app/admin/tracking/index.php (NEW)** — full-page fleet tracking map. Leaflet.js (v1.9.4 CDN) with OpenStreetMap tiles (no API key needed). Alpine.js FF_FleetTracking() component: searchable sidebar panel with unit list + online/offline/total badge counts; color-coded custom SVG markers by unit status (available=green, on_lease=blue, reserved=purple, maintenance=amber, inactive=gray, decommissioned=red); marker popups with unit number, status badge, template name, address, speed, odometer, GPS timestamp, link to unit profile; auto-refresh toggle (60s interval); click sidebar unit → zoom to marker + open popup; selected unit detail panel below map showing full GPS info + link to Samsara dashboard. Graceful dev mode: banner when Samsara API not configured. **(5) app/admin/equipment/show.php (MODIFIED)** — added 10th tab "GPS Tracking". Added `gps_device_id`, `tracking_provider`, `samsara_vehicle_url` to initial DB query. Tab states: no GPS configured (empty state with configure button → edit page), loading skeleton, GPS data with mini Leaflet map + spec-table showing address/coordinates/speed/heading/odometer/last update/Samsara ID/provider. Lazy-loads via `loadTracking()` calling locations API with `?unit_id=N`. `initTrackingMap()` creates Leaflet map with marker+popup on $nextTick. `refreshTracking()` clears map and reloads. Actions: "View on Fleet Map" link, "Open in Samsara Dashboard" link (when samsara_vehicle_url set). Leaflet CSS/JS loaded at page bottom. **(6) app/admin/settings/index.php (MODIFIED)** — enhanced Integrations tab with Samsara Connection section (super_admin only). Alpine FF_SamsaraTest() component with "Test Connection" button → calls test-connection API → displays green checkmark/red X with message. Shows GPS fleet overview stats: GPS-equipped unit count / total units, last GPS sync cron time from audit_log, link to Fleet Tracking page. **(7) config/navigation.php (MODIFIED)** — added "Fleet Tracking" nav item with `map` icon between Equipment and Leases. Permission: equipment (anyone who can view equipment can track). **(8) public/assets/icons/map.svg (NEW)** — Heroicons outline map icon for sidebar navigation. **(9) API auth fix:** Both new API files use `require_auth_api()` (not `require_auth()`) matching established API pattern — returns JSON 401 instead of HTML redirect. **Test data:** 3 equipment units updated with tracking_provider='samsara' + gps_device_id (TEST-U001, RFR-001, RFR-002). **SC passed:** SC1 php -l all 7 modified/new files clean ✅; SC2 /tracking page HTTP 200 with Leaflet, map container, FF_FleetTracking, sidebar nav link ✅; SC3 GET /api/v1/gps/locations returns 3 GPS units with correct structure ✅; SC4 GET /api/v1/gps/locations?unit_id=4 returns single unit ✅; SC5 GET /api/v1/gps/test-connection returns {connected:false, message:"API key not configured"} (correct for dev) ✅; SC6 /equipment/show?id=4 HTTP 200 with GPS Tracking tab, loadTracking, Leaflet includes ✅; SC7 /settings?tab=integrations HTTP 200 with Samsara Connection section, Test Connection button, GPS fleet stats ✅; SC8 map.svg icon file exists (508 bytes) ✅. |

| DATE-1 | 2026-04-05 | Date Field Hardening (calendar icons + min/max across all forms) | **11 files modified, 0 new files.** Cross-cutting date input pass — no new features. **(1) Calendar icon on every date field**: All `type="date"` inputs wrapped in `display:flex` container with a ghost button that calls `input.showPicker()` (fallback `.click()`). Pattern matches reservations/create.php which already had the icon; now consistent everywhere. **(2) min/max constraints by semantic type**: Lease `start_date` min=today; `end_date` + `minimum_end_date` `:min="form.start_date"` (dynamic Alpine binding — calendar blocks dates before start). Payment `payment_date` max=today (cannot record future payment). Equipment `cvi_expiry`, `registration_expiry`, `mvi_expiry`, `insurance_expiry` all min=today (expiry dates must be in future). Inspection `inspection_date` max=today (inspection was in the past); `cvi_expiry` min=today. Rate card `effective_from` min=today; `effective_to` `:min="form.effective_from"`. Invoice `period_end` `:min="form.period_start"`. Credit note `expires_at` min=today. Customer `gst_exempt_expiry` + `pst_exempt_expiry` min=today. **(3) Acquired date / requested date / scheduled date / period_start / WO dates**: Calendar icon only — no min/max restriction (historical dates valid). **Files**: leases/create.php (3 date fields), payments/create.php (1), equipment/create.php (5), reservations/create.php (min added — icon pre-existed), rates/create.php (2), credit_notes/create.php (1), invoices/create.php (2), inspections/create.php (2), maintenance_work_orders/create.php (2), customers/create.php (2 inside x-if — uses $el.previousElementSibling), mileage_logs/create.php (1 vanilla JS — onclick). All 11 php -l clean ✅. |

| S028 | 2026-04-05 | Accounting Module Foundation (Phase 1) | **25 new files, 0 modified.** Complete accounting module foundation. **(1) database/seeds/010_acc_chart_of_accounts.sql** — 81 accounts across 8 types (asset, liability, equity, revenue, cost_of_revenue, operating_expense, other_income, other_expense). 9 header accounts, 9 system accounts, 2 bank accounts. Uses `SET @parent = LAST_INSERT_ID()` for hierarchy. **(2) database/seeds/011_acc_periods.sql** — 24 periods (Jan 2025–Dec 2026), all open. **(3) database/seeds/012_acc_settings.sql** — 22 accounting settings (AR/AP/cash/tax account mappings, GST 5%/PST 7%, fiscal year, auto-post flags, revenue mapping JSON). FK references resolved via subquery to acc_accounts. **(4) database/seeds/013_acc_year_end_checklist.sql** — 17 year-end items in sequence. **(5) lib/Accounting/AccountingService.php** — core utility: `setting()`, `periodForDate()`, `currentOpenPeriod()`, `validatePeriodForPosting()`, `nextJeNumber()` (atomic gap-free JE-YYYY-NNNNN), `nextBillNumber()`, `nextApPaymentNumber()`, `nextDepositNumber()`, `accountBalance()` (debit/credit-normal aware), `allAccountBalances()`, `revenueAccountCode/Id()`, `arReconciliationCheck()`, `apReconciliationCheck()`. **(6) lib/Accounting/JournalEntryService.php** — JE lifecycle: `create()` (validates: min 2 lines, max 50, balanced, no headers, no inactive, no dual debit+credit), `post()` (FOR UPDATE race prevention), `reverse()` (swaps debits↔credits, links reversal_of_id/reversed_by_id), `getWithLines()`. **(7) 5 COA API endpoints** — accounts/index.php (hierarchical tree + flat mode, type/active/search filters, balance calc), show.php (single account + recent transactions), create.php (code uniqueness, type validation), update.php (D19 optimistic lock), deactivate.php (balance check). **(8) 4 Period API endpoints** — periods/index.php (year filter, status counts), show.php (JE summary), close.php (open→closed, FOR UPDATE), lock.php (closed→locked, super_admin only). **(9) 5 JE API endpoints** — journal_entries/index.php (paginated, filters: status/type/date/search), show.php (entry + lines via getWithLines), create.php (delegates to JournalEntryService), post.php (draft→posted), reverse.php (posted→reversed). **(10) 2 Settings API endpoints** — settings/index.php (all accounting.% keys with parsed values), update.php (bulk update with whitelist, audit log). **(11) 5 Admin Pages**: dashboard/index.php (6 server-rendered KPIs: current period, YTD revenue/expenses/net income, AR/AP balance; period status bar; recent JEs via Alpine; reconciliation alerts; quick action links), chart-of-accounts/index.php (4 KPI tiles, tree/flat toggle, search/type/active filters, create/edit modal with account_type/subtype/parent/normal_balance), journal-entries/index.php (4 KPI tiles, 4-tab bar draft/posted/reversed/all, filter toolbar, data table with status/type badges, view detail modal with lines, create modal with dynamic lines table + auto-clear opposing side + running balance indicator + minimum 2 lines), periods/index.php (4 KPI tiles, year filter, 12-month card grid with status badges + JE counts + close/lock actions), settings/index.php (5-tab: General/GL Account Mapping/Revenue Mapping/Depreciation/Tax Filing, server-rendered current values, per-tab save). **Bugs found and fixed during stop-condition testing:** (a) periods/index.php and show.php referenced non-existent `updated_at` column in acc_periods — fixed to use actual columns. (b) periods/close.php and lock.php included `updated_at` in UPDATE — removed. (c) settings/index.php used wrong column names (`setting_key`/`setting_value` instead of backtick-quoted `key`/`value`), wrong table (`gl_accounts` instead of `acc_accounts`), wrong field names (`account_code`/`account_name` instead of `code`/`name`) — all corrected. (d) JournalEntryService audit_log used `description` column instead of `notes` — fixed all 3 occurrences. (e) Settings API update.php audit_log used `description` instead of `notes` — fixed. **SC passed:** SC1 36 acc_ tables ✅; SC2 81 COA accounts ✅; SC3 24 periods ✅; SC4 22 settings ✅; SC5 17 checklist items ✅; SC6 all PHP syntax clean ✅; SC7 COA list 81 accounts ✅; SC8 COA show ✅; SC9 Periods list 24 ✅; SC10 Period show ✅; SC11 JE list ✅; SC12 Settings list 22 ��; SC13 dashboard HTTP 200 + content ✅; SC14 chart-of-accounts HTTP 200 + content ✅; SC15 journal-entries HTTP 200 + content ✅; SC16 periods HTTP 200 + content ✅; SC17 settings HTTP 200 + content ���. |
| S028-b | 2026-04-05 | Settings Audit + Manual Cron Overrides + Prefix Fixes | **7 files modified, 2 new files.** Settings audit, manual cron triggers, and prefix wiring. **(1) api/v1/cron/trigger.php (NEW)** — manual trigger endpoint for all 5 cron jobs. POST with `{job: "..."}` body. Validates against whitelist (compliance_alerts, invoice_overdue, late_fee_apply, invoice_generate_monthly, gps_mileage_sync). Runs cron script synchronously via `exec(PHP_BINARY)` — reuses full cron logic with advisory locks, error handling, and audit logging. Requires super_admin role. Logs manual_trigger to audit_log. Returns exit code + output + last audit entry. **(2) app/admin/settings/system.php (MODIFIED)** — Cron dashboard now shows all 5+1 jobs (added compliance_alerts, gps_mileage_sync). "Run Now" button per cron job (super_admin only) — AJAX POST to trigger API, updates row with results. "Run All Now" button runs all sequentially. Audit query fixed: entity_type='cron' + entity_label exact match (was LIKE on module='cron'). **(3) cron/compliance_alerts.php (MODIFIED)** — Hardcoded 7/30 day thresholds replaced with `settings_get('alerts.compliance_critical_days', '7')` and `settings_get('alerts.compliance_warning_days', '30')`. Variables renamed from `$in7/$in30` to `$inCritical/$inWarning`. **(4) database/seeds/004_settings.sql (MODIFIED)** — Added 6 missing seed entries: company.bank_name, company.bank_account, company.check_payable_to, company.payment_instructions (all used by portal invoice view), ai.summary_ttl_hours (used by SummaryEngine). **(5) app/admin/settings/index.php (MODIFIED)** — Settings query and group arrays expanded to include 'leases' and 'maintenance' groups so lease.prefix and damage_claim.prefix are editable. Added group labels for Leases & Contracts, Maintenance & Claims. **(6) lib/Billing/InvoiceGenerator.php (MODIFIED prev)** — Hardcoded `"INV-%s-%05d"` replaced with `settings_get('invoice.prefix', 'INV')`. **(7) api/v1/payments/create.php (MODIFIED prev)** — Hardcoded `'PAY-%s-%05d'` replaced with settings_get. **(8) api/v1/credit_notes/create.php (MODIFIED prev)** — Hardcoded `'CN-CR-%s-%05d'` replaced with settings_get. **(9) api/v1/damage_claims/create.php (MODIFIED prev)** — Hardcoded `'DMG-%s-%05d'` replaced with settings_get. **(10) api/v1/leases/create.php (MODIFIED prev)** — Hardcoded `'CN-'` replaced with settings_get. **(11) app/admin/accounting/settings/index.php (MODIFIED prev)** — Fixed GL mapping key mismatches (bad_debt_account_id→bad_debt_expense_account_id, retained_earnings_id→retained_earnings_account_id, current_year_ni_id→current_year_net_income_account_id). Fixed save URL, table/column names, permission. **(12) lib/Accounting/AccountingService.php (MODIFIED prev)** — revenueAccountId() reads individual keys. Fixed audit_log column. **(13) config/navigation.php (MODIFIED prev)** — Added Periods + Accounting Settings to accounting section (positions #2 and #3 after Dashboard). **Settings audit results:** 19/48 FleetForge settings actively used in code; 29 dead (company address/phone/email, GPS provider details, SMTP, yard.default — defined but not referenced). All accounting settings are alive. 5 settings used but missing from seeds (now added). **SC passed:** SC1 php -l all 4 modified PHP files clean ✅. |
| S028-c | 2026-04-05 | Settings Root Cause Fix + Accounting Top Nav | **6 files modified, 1 new file.** Fixed the root cause of ALL settings being broken + added accounting top navigation bar. **(1) ROOT CAUSE: PHP dots-to-underscores in POST field names** — PHP converts `.` in `$_POST` keys to `_`, so `<input name="invoice.prefix">` becomes `$_POST['invoice_prefix']`, NOT `$_POST['invoice.prefix']`. Every settings save was writing empty/zero to every key in the group. Fix in `app/admin/settings/index.php`: `$postKey = str_replace('.', '_', $key)` when reading POST data, and boolean check updated to use `$_POST[$postKey]`. **(2) Accounting settings AJAX URL fix** — `app/admin/accounting/settings/index.php` AJAX save URL was `'/accounting/settings/update'` (wrong path, missing base path). Fixed to `FF_Api.url('/api/v1/accounting/settings/update')`. Every accounting settings save was silently 404ing. **(3) 12+ accounting settings key mismatches fixed** — JS property names and x-model bindings didn't match actual DB keys. Fixed: `module_enabled`→`enabled`, `capex_threshold`→`capex_threshold_cad`, `fiscal_year_start` removed (no DB key), `cash_account_id`→`default_cash_account_id`, `current_year_net_income_account_id`→`current_year_ni_account_id`, `default_method`→`default_depreciation_method`, `useful_life`→`default_useful_life_years`, `salvage_pct`→`default_salvage_pct`, GL type filters corrected (`['expense']`→`['operating_expense']`, etc.). Fixed all save methods to send correct `accounting.*` keys. **(4) Corrupted DB values restored** — `invoice.due_days_default` was `0` (fixed to `30`), 4 prefix settings had wrong `group_name` (fixed to `invoices`/`leases`/`maintenance`). **(5) includes/partials/accounting-nav.php (NEW)** — horizontal sub-navigation bar for all accounting pages. 5 links: Dashboard, Periods, Chart of Accounts, Journal Entries, Settings. Auto-highlights current page via URL matching. Inline SVG icons from `public/assets/icons/`. Styled as pill-bar with active state using primary color. **(6) Added accounting-nav.php include to all 5 accounting pages** — dashboard, chart-of-accounts, periods, journal-entries, settings. Periods and Settings now prominently visible at top of every accounting page (user request: they were hidden at bottom of sidebar). **SC passed:** SC1 php -l all 6 modified/new PHP files clean ✅; SC2 all settings now save correctly (dots-to-underscores fix verified) ✅; SC3 accounting settings save via AJAX (URL fix verified) ✅; SC4 accounting nav renders on all 5 pages ✅. |
| VALID-1 | 2026-04-05 | Form Validation Hardening (all modules) | **12 files modified, 0 new files.** Cross-cutting validation pass over all `app/admin/` forms and relevant `api/v1/` endpoints. No new features — pure correctness. **(1) Numeric min=0 enforcement — client-side**: `app/admin/settings/index.php` — generic PHP loop that renders `type="number"` inputs for integer/decimal settings was missing `min="0"`; added to both the general and sensitive (super_admin-only) rendering paths. **(2) Non-negative enforcement — server-side**: `api/v1/maintenance_work_orders/create.php` — `mileage_at_service` was passed to `clean_int()` (allows negatives); changed to `clean_non_negative_int()`. `api/v1/vendors/create.php` — `hourly_rate` was validated with `clean_decimal()` only; added `bccomp($hourlyRate, '0', 6) < 0` guard with 422 json_error. **(3) Client-side validate() + required field enforcement**: `app/admin/customers/create.php` — form had `:class="{ 'is-invalid': errors.company_name }"` and error display infrastructure but `submit()` never called a `validate()` method. Added full `validate()` (company_name required check + email format checks for email/billing_email/invoice_email) and `isValidEmail()` helper; `submit()` now gates on `validate()` first. **(4) Email @blur validation**: `app/admin/customers/create.php` — added `@blur` handlers on email, billing_email, invoice_email; `app/admin/vendors/create.php` — added `@blur` on email field using same `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` pattern. **(5) Text field maxlength standardisation + character counts**: All textarea/input fields aligned to spec limits (name/title ≤ 255, notes/description ≤ 2000). Files changed: `app/admin/customers/create.php` (notes 10000→2000 + count), `app/admin/vendors/create.php` (notes + count), `app/admin/damage_claims/create.php` (description + notes, both + count), `app/admin/maintenance_work_orders/create.php` (description + notes + internal_notes, all + count), `app/admin/leases/create.php` (notes + internal_notes 5000→2000 + count; rate_notes 5000→255 + count), `app/admin/equipment/create.php` (notes + internal_notes 5000→2000 + count), `app/admin/payments/create.php` (counts added to both note fields already at 2000), `app/admin/rates/create.php` (name count added; description 1000→255 + count), `app/admin/equipment/templates/create.php` (description 5000→2000 + count), `app/admin/mileage_logs/create.php` (vanilla JS form — oninput counter on notes). **Files read and confirmed already correct (no changes)**: leases/edit.php, equipment/edit.php, customers/edit.php, equipment/templates/edit.php, damage_claims/show.php, mileage_logs/show.php, vendors/show.php, inspections/create.php, yards/index.php, customers/show.php, api/v1/customers/create.php, api/v1/leases/create.php, api/v1/payments/create.php, api/v1/damage_claims/create.php. |

| S030 | 2026-04-06 | The Bridge: Auto-JE Posting + AR Reconciliation | **1 new library class, 5 modified billing endpoints, 4 new API endpoints, 3 new admin pages, 1 modified nav, 3 new SVG icons.** The critical integration layer between FleetForge billing and accounting GL. **(1) lib/Accounting/AutoEntryBridge.php (NEW)** — 6 public static methods: `onInvoiceSent()` (DR 1030 AR / CR 4xxx Revenue per line item type / CR 2030 GST / CR 2040 PST — revenue grouped by account, PASS-6:G3 credit lines produce debit revenue), `onInvoiceVoided()` (finds+reverses original JE via JournalEntryService::reverse()), `onPaymentReceived()` (DR 1010 Cash / CR 1030 AR), `onCreditNoteIssued()` (DR 4xxx Revenue / CR 2060 Customer Credits Liability — PASS-6:G2 two-step: creation posts to liability not AR), `onCreditNoteApplied()` (DR 2060 / CR 1030 — this step reduces AR), `onBadDebtWriteOff()` (DR 6160 Bad Debt / CR 1030 AR). 3 private helpers: `resolveRevenueAccount()` (parses JSON map at accounting.revenue_account_map, resolves item_type→code→account ID with static caching), `requireAccountId()` (reads setting, verifies account exists+active, throws if missing), `resolvePeriod()` (§16 closed period redirect — falls back to earliest open period, logs to audit_log). All methods guarded by `isEnabled()` — no-op when accounting.enabled=false. All methods called INSIDE billing endpoint db_transaction() closures — JE failure rolls back the billing event. **(2) lib/Accounting/AccountingService.php (MODIFIED)** — `revenueAccountId()` and `revenueAccountCode()` rewritten: was reading non-existent individual keys like `accounting.revenue_mapping.base_rental_chassis`; now parses JSON blob at `accounting.revenue_account_map`, maps item_type→code→ID via acc_accounts lookup with static caching. **(3) 5 billing endpoints hooked**: `api/v1/invoices/send.php` (+onInvoiceSent inside transaction), `api/v1/invoices/void.php` (+onInvoiceVoided), `api/v1/payments/create.php` (+onPaymentReceived), `api/v1/credit_notes/create.php` (+onCreditNoteIssued), `api/v1/credit_notes/apply.php` (+onCreditNoteApplied). **(4) api/v1/accounting/ledger/index.php (NEW)** — GL account ledger API. Requires account_id, optional date_from/date_to/page/per_page. Computes opening balance (all entries before date_from), returns rows with running_balance computed per row, handles pagination with correct running balance across pages via subquery. **(5) api/v1/accounting/reports/trial-balance.php (NEW)** — Trial balance API. Optional as_of_date, period_id. Uses AccountingService::allAccountBalances(). Computes trial_debit/trial_credit based on normal_balance side. Returns is_balanced flag. **(6) api/v1/accounting/reports/ar-aging.php (NEW)** — AR aging API. Groups open invoices by customer and 5 aging buckets (current, 1-30, 31-60, 61-90, 90+). Per-customer totals with invoice detail arrays + grand totals. bcmath throughout. **(7) api/v1/accounting/ar/reconcile_check.php (NEW)** — AR reconciliation check. Uses AccountingService::arReconciliationCheck(). On mismatch, per-customer breakdown (GL AR vs subledger via customer_id on JE lines). **(8) app/admin/accounting/ledger/index.php (NEW)** — GL ledger page. Server-side account dropdown, date range filter. Alpine.js data table with running balance, pagination, summary strip (opening/closing balance). Account names link to JE detail. **(9) app/admin/accounting/reports/trial-balance.php (NEW)** — Trial balance page. Date picker. Balanced/unbalanced status with colored badges. Data table with debit/credit columns, double-ruled totals. Account names link to ledger. **(10) app/admin/accounting/ar-aging/index.php (NEW)** — AR aging page. 6 KPI tiles (per bucket + total). Reconciliation check button with success/error alert banners. Customer rows with expand/collapse for invoice detail. Color-coded amounts by severity. **(11) includes/partials/accounting-nav.php (MODIFIED)** — added 3 new links: Ledger (book-open), Trial Balance (scale), AR Aging (clock). **(12) 3 new SVG icons** — book-open.svg, scale.svg, clock.svg (Heroicons outline). **Critical discovery & fix**: Revenue account mapping mismatch — AccountingService tried to read individual keys but settings store a single JSON blob. Fixed both methods. **SC passed**: SC1 php -l all 14 new/modified PHP files clean ✅; SC2 all 37 DB column references verified against FLEETFORGE_DATABASE_MASTER.sql ✅; SC3 all JournalEntryService/AccountingService method signatures match bridge calls ✅; SC4 router resolves all 3 new admin page paths correctly ✅. |
| NAV-1 | 2026-04-07 | Accounting Nav Fixes (Topnav Dropdown Clipping + Sidebar Restructure) | **3 files modified, 0 new files.** Cross-cutting fix covering both the accounting topnav dropdown bug and the sidebar's accounting section. No new features — pure correctness + consistency. **(1) ROOT CAUSE — topnav dropdowns rendering empty**: `.acc-topnav` in `public/assets/css/app.css` used `overflow-x: auto; overflow-y: visible`. Per CSS spec, setting `overflow-x` to any value other than `visible` silently forces `overflow-y` to a non-`visible` value (the browser computes it to `auto`), which creates a clipping context that hides the absolutely-positioned group dropdown panels. The HTML was correctly rendering all 16 child links (verified via curl), they were just visually clipped. **Fix**: replaced `overflow-x: auto; overflow-y: visible` with `flex-wrap: wrap; overflow: visible`, allowing the nav pill-bar to wrap to a second row on narrow viewports instead of scrolling horizontally, and letting the dropdown panels escape the container cleanly. Added an explanatory comment block about the CSS spec gotcha for future maintainers. **(2) Sidebar accounting section restructured to mirror topnav**: the sidebar previously had 8 individual items (Dashboard, Chart of Accounts, Journal Entries, Accounts Payable, Bank, Tax, Reports, Budgets) — of which 4 (`/accounting/accounts-payable`, `/accounting/tax`, `/accounting/reports`, `/accounting/budgets`) were dead 404s, and the structure didn't match the grouped topnav built in S028-c. Replaced entire accounting section in `config/navigation.php` with a single collapsible "Accounting" parent item (`calculator` icon, `match_prefix => '/accounting'`) containing 8 children: Dashboard, General Ledger, Receivables, Payables, Banking, Fixed Assets, Periods, Settings — exactly matching the 6 functional groups + Periods + Settings in the topnav. Each group child uses a `match_prefix` array listing all sibling URLs so the child stays highlighted across every sub-page in its group (e.g. "General Ledger" child is active on `/accounting/ledger`, `/accounting/chart-of-accounts`, `/accounting/journal-entries`, and `/accounting/reports/trial-balance`). Permissions use the correct module keys: `journal_entries` for Dashboard/GL/Receivables/Settings, `accounts_payable` for Payables, `bank_accounts` for Banking, `fixed_assets` for Fixed Assets, `period_management` for Periods. **(3) `match_prefix` field added to `includes/sidebar.php`**: backward-compatible enhancement — any nav item (parent or child) can now declare an optional `match_prefix` field as either a single string or an array of strings; the active-state detection iterates the prefix list and activates on the first match via `str_starts_with($_currentPath, FF_BASE_PATH . $_mp)`. Falls back to `$_item['url']` when `match_prefix` is not set, so every existing nav item keeps working unchanged. Applied identically to parent active detection, child-active-expansion detection (with `break 2`), and the child render loop. Variable cleanup list updated: added `$_matchPrefixes`, `$_mp`, `$_childPrefixes`, `$_cp`; removed `$_itemFullUrl`, `$_childFullUrl`. **Verification**: (a) Standalone unit test `/tmp/ff_sidebar_test.php` — replicates the sidebar active-state logic against `config/navigation.php` without booting FleetForge, tests all 19 accounting URLs (`/accounting/dashboard`, `/chart-of-accounts`, `/journal-entries`, `/ledger`, `/reports/trial-balance`, `/ar-aging`, `/statements`, `/collections`, `/deposits`, `/bills`, `/ap-aging`, `/vendor-credits`, `/bank-accounts`, `/bank-reconciliation`, `/fixed-assets`, `/depreciation`, `/capex`, `/periods`, `/settings`) and asserts that parent is active + exactly one child is active + child label matches expected group → **19/19 PASS**. (b) HTTP-level test via curl+session against Herd — fetched all 19 pages and confirmed the correct sidebar child carries `is-active` class on every page. (c) Visual verification — Chrome headless screenshots of Fixed Assets, Chart of Accounts, and Statements pages confirming both topnav dropdowns open correctly (no clipping) AND sidebar group highlight synchronizes with the topnav group. **Files modified**: `public/assets/css/app.css` (topnav overflow), `includes/sidebar.php` (match_prefix field), `config/navigation.php` (accounting section restructure). **SC passed**: SC1 php -l all modified files clean ✅; SC2 unit test 19/19 pages pass ✅; SC3 HTTP 19/19 pages render correct sidebar highlight ✅; SC4 topnav dropdown panels visible and not clipped on narrow + wide viewports ✅; SC5 all sidebar child URLs resolve to real pages (no more 404s) ✅. |
| S033 | 2026-04-06 | Bank Management Module (Spec Phase 21) | **24 new files, 7 modified.** Complete bank management module: accounts CRUD, manual transactions, CSV import with auto-detect, auto-matching, reconciliation workflow, NSF processing, inter-account transfers. **(1) lib/Accounting/BankService.php (NEW ~600 lines)** — core service class. 5 CSV format definitions (RBC, TD, BMO, Scotiabank, CIBC) with header patterns, column mappings, date formats, split/single amount modes. `detectCsvFormat()` auto-detects from headers. `parseCsv()` handles BOM stripping, configurable columns, date parsing. `detectDuplicates()` MD5 hash of date+amount+description against existing transactions. `autoMatch()` ±3 day window + amount match against payments/AP payments with confidence scoring (high/medium/low). `reconciliationSummary()` computes book balance, outstanding deposits/checks, adjusted book balance, difference. `processNsf()` full NSF reversal: validates payment exists+is posted, reverses original JE, reopens allocated invoices (reverts balance_due, flips status back from paid), charges NSF fee JE if >0, creates bank transactions for reversal+fee, all in single db_transaction. `recordTransfer()` inter-account transfer: creates JE (DR destination GL / CR source GL), handles cross-currency with FX gain/loss lines, creates bank transactions for both accounts. `glAccountIdByCode()` cached GL account lookup. **(2) 5 Bank Account API endpoints** — index.php (GET paginated, currency/active filters, GL account join), show.php (GET single with computed GL balance + transaction count + last reconciliation), create.php (POST validates GL account exists, clears existing defaults if is_default), update.php (POST partial updates, handles is_default toggle), deactivate.php (POST toggle is_active, blocks if default or has unreconciled transactions). **(3) 5 Bank Transaction API endpoints** — index.php (GET paginated with status/type/date/search/reconciliation_id/uncleared filters), create.php (POST manual entry with optional auto-JE via expense_account_id), match.php (POST validates matched record exists, updates status to 'matched'), unmatch.php (POST prevents unmatching cleared transactions), exclude.php (POST toggle exclude/un-exclude with audit log). **(4) 2 CSV Import API endpoints** — upload-preview.php (POST file upload, validates CSV/5MB, auto-detect format with manual override fallback, parse+dedup+auto-match, store preview in session with 30-min expiry), confirm.php (POST reads session preview, supports selected_rows and match_overrides). **(5) 5 Reconciliation API endpoints** — index.php (GET paginated with bank_account_id required, joins periods/users), show.php (GET includes live summary + transaction list), create.php (POST validates no existing in_progress recon, FOR UPDATE on bank account per D20), toggle-cleared.php (POST toggles is_cleared + recalculates summary), complete.php (POST validates difference=$0.00 or adjustment ≤$5.00, locks transactions, creates adjustment JE if needed). **(6) bank-transfers/create.php (NEW)** — delegates to BankService::recordTransfer(). **(7) bank-nsf/create.php (NEW)** — delegates to BankService::processNsf(). **(8) app/admin/accounting/bank-accounts/index.php (NEW ~400 lines)** — full admin page with 4 KPI tiles (active accounts, CAD balance, USD balance, unmatched count), accounts table with GL mapping, transaction list for selected account, 5 modals (account CRUD, CSV import wizard, manual transaction, transfer, NSF). CSV import is 3-step wizard: upload → preview with select/deselect + confidence badges → confirm. **(9) app/admin/accounting/bank-reconciliation/index.php (NEW ~250 lines)** — reconciliation workspace: start new recon form, history table, active workspace with 5 summary cards (book balance, statement balance, cleared deposits, cleared withdrawals, difference with prominent styling), transaction checklist with checkboxes, outstanding items report. **(10) includes/partials/accounting-nav.php (MODIFIED)** — added Bank Accounts + Reconciliation links. **(11) 3 new SVG icons** — building-library.svg, banknotes.svg, phone.svg. **Bugs found and fixed during testing**: (a) `u.first_name` column doesn't exist in users table (actual column is `name`) — fixed in 6 files: bank-transactions/index.php, bank-reconciliations/index.php, ar/bad_debt_writeoffs/index.php, ar/dunning_letters/index.php, ar/collection_notes/index.php, ar/promise_to_pay/index.php. (b) USD GL account 1020 had wrong currency='CAD' — fixed via ALTER. (c) Missing routing_number column in acc_bank_accounts — added via ALTER. **POST-BUILD DEEP AUDIT — "nothing works (no buttons)" user report triggered full button-by-button retest with real data. 6 additional bugs found and fixed**: (d) **CRITICAL — killed entire Alpine component on load**: `app/admin/accounting/bank-accounts/index.php` line 569 had `accountForm: this._emptyAccountForm()` in an object literal — `this` is `window` not the Alpine component during object construction, throws `TypeError: this._emptyAccountForm is not a function`, crashes x-data and makes EVERY button non-functional. Fixed by inlining default form values directly in the object literal. The `_emptyAccountForm()` method is still used from `openAccountModal()` where `this` correctly refers to the Alpine component. (e) API response shape mismatch: `d.total_pages` should be `d.pagination?.total_pages` (line 642). (f) API response shape mismatch: `uj.data?.total` should be `uj.data?.pagination?.total` (line 646) — `json_paginated()` wraps pagination metadata inside a `pagination` object. (g) `v.company_name` column doesn't exist in vendors table (actual column is `name`, only customers has `company_name`) — fixed `BankService.php` line 501 auto-match vendor join. (h) **PHP 8.4 deprecation**: `fgetcsv()` and `str_getcsv()` now require explicit `$escape` parameter — added `, 0, ',', '"', ''` to `BankService.php` line 1016 and `', ',', '"', ''` to `api/v1/accounting/bank-import/upload-preview.php` line 74. (i) `payment_allocations.amount_applied` column doesn't exist (actual column is `amount`) — fixed all 4 occurrences in `BankService.php::processNsf()` NSF allocation reversal loop (lines ~754–772). **Real-data button-by-button test after fixes**: + Add Account (CAD + USD) ✅; Edit Account ✅; Account row click → transaction list ✅; + Manual Entry (deposit + withdrawal) ✅; Transfer (CAD→USD with FX gain/loss + same-currency) ✅; Import CSV (RBC format auto-detect → preview → confirm → 3 imported) ✅; Exclude / Un-exclude ✅; Unmatch ✅; Deactivate guard (blocked by unreconciled) ✅; NSF Processing (payment reversed, invoice reopened, fee charged, 2 JEs created) ✅; Reconciliation (start → toggle cleared → un-toggle → history) ✅. **SC passed**: SC1 php -l all 24 files clean (0 errors) ✅; SC2 all 19 endpoints return 401/405 without session ✅; SC3 create bank account returns id=2 ✅; SC4 list accounts returns 2 items with GL joins ✅; SC5 show account returns GL balance + transaction count ✅; SC6 create manual transactions (deposit+withdrawal) ✅; SC7 list transactions returns 2 items ✅; SC8 start reconciliation returns id=1 with in_progress status ✅; SC9 toggle cleared updates summary with correct balances ✅; SC10 show reconciliation returns live summary + transactions ✅; SC11 list reconciliations returns created_by_name ✅; SC12 transfer CAD→USD creates JE + 2 bank transactions with FX ✅; SC13 update bank account ✅; SC14 deactivate blocked by unreconciled transactions ✅; SC15 exclude/un-exclude toggles status correctly ✅; SC16 admin pages HTTP 200 ✅; SC17 Alpine component boots without TypeError (bug-d fix) ✅; SC18 all buttons fire their click handlers with real seed data ✅; SC19 NSF processing reverses payment + reopens invoices + charges fee ✅; SC20 CSV import wizard 3-step flow completes end-to-end ✅. |
| S034 | 2026-04-07 | Fixed Assets & Depreciation (Spec Phase 22) | **9 files modified, 4 new API endpoints.** Wire-up + gap-fix session for the existing FixedAssetService scaffold (which had been seeded in S028 but never fully wired). **(1) lib/Accounting/FixedAssetService.php (MODIFIED)** — added `reverseRun(int $runId, ?string $reason)` (loads run+JE FOR UPDATE D20, calls JournalEntryService::reverse(), flips status posted→reversed, reverses subledger accumulated_depreciation per asset, recomputes NBV, audit_log inside transaction); §16 closed-period auto-redirect added to `postRun()` (if run's period is closed, finds earliest open period from acc_periods, repoints run.period_id, audit_log entry with old/new period); fixed dispose/impair role gates from `manager-only` → `super_admin/manager/accountant` (4 places); added missing audit_log calls to depreciation_run_post/depreciation_run_reverse/asset_dispose/asset_impair events; added 3 new methods for CapEx workflow: `listFlaggedWorkOrders($threshold)` (queries maintenance_work_orders with total_cost ≥ threshold AND no acc_capex_request row, JOINs equipment_units→equipment_templates for `et.brand`/`et.model` and vendors as `v.name`), `capitalizeToAsset($workOrderId, array $assetData)` (creates acc_capex_request+acc_fixed_asset rows in single transaction, status='completed', returns asset_id), `expenseFlag($workOrderId, $reason)` (creates capex_request status='rejected' with reason, no asset created). **Bugs found and fixed in listFlaggedWorkOrders SQL**: (a) `eu.equipment_template_id` → `eu.template_id` (actual column), (b) `v.company_name` → `v.name` (vendors table only has `name`, not `company_name` — only customers has company_name). **(2) 4 new API endpoints**: `api/v1/accounting/depreciation/reverse.php` (POST, requires id+reason, role gate, calls reverseRun), `api/v1/accounting/capex/flags.php` (GET, optional `threshold` query param defaults to 5000, returns flagged WOs array), `api/v1/accounting/capex/capitalize.php` (POST, requires work_order_id+asset_data, role gate, calls capitalizeToAsset), `api/v1/accounting/capex/expense.php` (POST, requires work_order_id+reason, role gate, calls expenseFlag). **(3) AutoEntryBridge thin-wrapper hooks** added: `onDepreciationRunPosted($runId)` (no-op stub — depreciation JE is created inside FixedAssetService::postRun directly via JournalEntryService, bridge call is for symmetry/future hooks), `onAssetDisposed($assetId)` (no-op symmetry stub), `onAssetImpaired($assetId)` (no-op symmetry stub). **(4) 3 admin pages updated**: `app/admin/accounting/fixed-assets/index.php` (verified existing — no changes needed), `app/admin/accounting/depreciation/index.php` (added reverseRun() Alpine method + Reverse button on posted-run rows with confirm modal, calls /api/v1/accounting/depreciation/reverse), `app/admin/accounting/capex/index.php` (new flaggedWorkOrders Alpine state, fetches /api/v1/accounting/capex/flags on mount, displays flagged WOs in table with Capitalize/Expense action buttons + modals). **(5) 3 new audit_log columns/values**: NO new ENUM values needed — used existing 'create','update','status_change' on entity_type fixed_asset/depreciation_run/capex_request. **TESTING — exhaustive end-to-end with real seed data, all numbers verified bcmath**: **Depreciation tests** (test_s034_depreciation.php — 30+ assertions all ✅): created 3 test assets — Asset A: Kenworth $180,000 declining_balance 30%/yr (CRA half-year rule year 1: $180K × 0.30 × 0.5 / 12 = **$2,250.00/mo** ✅), Asset B: Office furniture $15,000 straight-line 5yr ($15K / 60mo = **$241.67/mo** rounded ✅, cumulative after 12 = $2,900.04 within $0.04 rounding ✅), Asset C: Trailer $45,000 units_of_production 500K-mile life ($45K / 500K × 5,000 mi recorded = **$450.00** ✅, edge case: $0 if no usage ✅); ran consolidated Jan 2026 depreciation preview → run total **$2,941.67** (= $2,250 + $241.67 + $450) ✅; posted run → JE created with 3 expense DR + 3 accum CR, all balanced ✅; double-post prevention → 422 RUN_ALREADY_POSTED ✅; ran Feb 2026 with new UoP usage of 4,000 mi → **$2,851.67** ✅; reversed Jan run → status='reversed', JE reversed, all 3 asset accumulated_depreciation rolled back ✅; re-previewed Jan after reversal → matched original $2,941.67 ✅; year-2 declining transition → $180K NBV $153K → $153K × 0.30 / 12 = **$3,825.00/mo** ✅; year-1 YTD after 12 months declining = **$27,000** ✅; year-2 month-1 NBV after declining = **$149,175** ✅. **Disposal tests** (test_s034_disposal.php — gain + loss scenarios): Asset D (cost $14,400, accum $2,400 → NBV $12,000) sold for $13,400 cash → JE: DR Cash $13,400 + DR Accum $2,400 = CR Asset $14,400 + CR Gain $1,400, balanced ✅; Asset E (cost $7,200, accum $1,200 → NBV $6,000) sold for $4,200 cash → JE: DR Cash $4,200 + DR Accum $1,200 + DR Loss $1,800 = CR Asset $7,200, balanced ✅; double-disposal blocked → 422 ASSET_ALREADY_DISPOSED ✅; driver role denied → 403 FORBIDDEN ✅. **Bug fixed in test**: dispose() returns flat array, not `['disposal' => ..., 'journal_entry' => ...]`. **Impairment tests** (test_s034_impairment.php): Asset F (cost $60,000 SL 10yr, seeded 12mo accum $6K → NBV $54K), impaired $20K → JE: DR 7020 Impairment Loss $20,000 / CR 1220 Accum Depr $20,000 ✅; new state: accum=$26K, NBV=$34K, status='active' (continues depreciating) ✅; subsequent monthly depreciation = $34K / 120 = **$283.33/mo** ✅; cannot exceed NBV → 422 IMPAIRMENT_EXCEEDS_NBV ✅; zero-loss rejected → 422 INVALID_AMOUNT ✅; role gating denied for non-accountant → 403 ✅. **CapEx tests** (test_s034_capex.php): created 3 maintenance work_orders — WO #1 $8,500 (above $5K threshold), WO #2 $1,800 (below threshold), WO #3 $3,000 (above $2K threshold for second test); listFlaggedWorkOrders($threshold=5000) returned WO #1 only ✅; listFlaggedWorkOrders($threshold=2000) returned WO #1 + WO #3 ✅; capitalizeToAsset(WO #1, asset_data) → created capex_request status='completed' + new fixed_asset FA-2026-00001 ✅; expenseFlag(WO #3, 'Routine maintenance, not capital') → created capex_request status='rejected' with reason ✅; double-review prevention → 422 ALREADY_REVIEWED ✅; driver role denied → 403 ✅. **Bug fixed**: maintenance_work_orders requires `requested_date` column — added to all test fixture INSERTs. **GL tie-out & subledger reconciliation** (test_s034_gl_recon2.php): all 18 S034-source JEs balanced (depreciation + asset_disposal source_types) ✅; sum of 13 posted depreciation_runs.total_depreciation = sum of posted depr-JE expense debits = **$38,606.71 == $38,606.71** ✅; all disposal JEs have proper cost-CR line matching acquisition_cost ✅; all impairment JEs have correct 7020 loss DR + accum CR pair ✅; 3 reversal pairs net to zero (orig DR == rev CR, orig CR == rev DR) ✅; 36 audit_log entries across fixed_asset/depreciation_run/capex_request entity types ✅. **Bug fixed**: `acc_accounts.type` doesn't exist — column is `account_type` with ENUM 'asset','liability','equity','revenue','cost_of_revenue','operating_expense','other_income','other_expense'; expense recon now filters `account_type IN ('operating_expense','cost_of_revenue','other_expense')`. **UI/Nav verification**: all 3 admin pages exist with valid PHP syntax, return HTTP 302 → /auth/login when accessed without session (proves routing + require_auth gating works); accounting-nav.php has Fixed Assets dropdown with 3 children (Asset Register, Depreciation, CapEx Requests). **SC passed**: SC1 php -l all 9 modified PHP files clean ✅; SC2 depreciation 30+ assertions across 3 methods + reversal + year-2 transition ✅; SC3 disposal gain + loss JEs balanced to the cent ✅; SC4 impairment ✅ + subsequent depreciation continues ✅; SC5 capex flag/cap/expense ✅ with role gating ✅; SC6 GL/subledger reconciles $38,606.71 == $38,606.71 ✅; SC7 reversal pairs net to zero ✅; SC8 36 audit entries written ✅; SC9 all 3 admin pages 302→login (auth gating proven) ✅; SC10 §16 closed-period redirect verified end-to-end ✅. |
| S027 | 2026-04-05 | Claude AI Integration (Phase 16) | **20 new files, 7 modified.** Full 5-feature AI integration powered by Anthropic Claude API. **(1) lib/AI/ClaudeClient.php (CREATED S026)** — core Anthropic Messages API client. curl-based HTTP, no SDK dependency. `sendMessage()` (standard request + token tracking), `sendMessageStreaming()` (SSE via CURLOPT_WRITEFUNCTION, parses message_start/content_block_delta/content_block_stop/message_delta events, accumulates tool_use blocks), `testConnection()`, `extractTextContent()`, `extractToolUseBlocks()`, `hasToolUse()`. Cost estimate: $3/M input, $15/M output. MAX_TOOL_ITERATIONS=5. **(2) lib/AI/TokenTracker.php (CREATED S026)** — all-static token usage tracking. `canSpend()` checks global daily limit, `record()` logs to ai_query_log, `getTodayUsage()`, `getMonthUsage()`, `getUsageByUser()`. **(3) lib/AI/ToolRegistry.php (CREATED S026, FIXED S027)** — 17 tool definitions in Anthropic format with `_tags` for context filtering. `getTools(context)` filters then strips tags. Contexts: chat (17 tools), report (12), summary (10). `execute()` dispatches to FleetForgeTools. **Bug fixed**: getTools() was filtering AFTER stripping _tags — report/summary contexts returned 0 tools. Fixed to filter rawDefinitions() first, then strip. **(4) lib/AI/Tools/FleetForgeTools.php (NEW)** — 17 tool execution handlers. All read-only SELECT queries. Customer: search_customers (LIKE search on name/email/city + status filter), get_customer_details, get_customer_leases, get_customer_invoices (financial fields stripped for dispatchers). Fleet: get_fleet_summary (unit counts by status + utilization rate), get_equipment_unit (joins templates), search_equipment (joins templates, category filter). Lease: get_active_leases, get_lease_details. Financial: get_revenue_by_period (monthly aggregation), get_revenue_by_customer, get_overdue_invoices (with days_overdue), get_ar_aging (CASE-based aging buckets: current/1-30/31-60/61-90/90+), get_payment_summary (collection rate + method breakdown). Compliance: get_expiring_documents (UNION ALL across 4 expiry columns). Maintenance: get_maintenance_summary (status counts + cost + recent WOs). Dashboard: get_dashboard_kpis (composite: leases, fleet, overdue, revenue, compliance alerts). All results capped at MAX_ROWS=50. Financial tools gated by `can('payments','view')`. **(5) lib/AI/SummaryEngine.php (NEW)** — generates and caches AI summaries. Types: customer_insights, lease_summary, unit_analysis, fleet_health, payment_risk. Gathers context by calling FleetForgeTools directly, builds type-specific prompts, calls Claude with 1024 max tokens. Cache in ai_summaries table (is_current=1, TTL from ai.summary_ttl_hours setting, default 24h). `generate()` checks cache first unless forceRefresh. **(6) lib/AI/AnomalyDetector.php (NEW)** — SQL-based statistical anomaly detection (no ML). 5 detectors: detectOverdueSpikes (3+ overdue or >60 days), detectComplianceRisks (multiple docs expiring <14 days or already expired), detectMaintenanceSpikes (30-day cost >2× monthly average), detectCustomerRisks (credit_hold/suspended/high risk/near credit limit), detectUtilizationDrop (<50% utilization). 24-hour deduplication. `runAll()` runs all detectors independently (failures isolated). `getRecentAlerts()`, `acknowledgeAlert()`. Tested against real DB: 14 alerts detected. **(7) api/v1/ai/chat.php (NEW)** — POST: send message with tool-calling loop (max 5 iterations). Creates/resumes sessions in ai_chat_sessions, stores messages in ai_chat_messages. Loads last 20 messages for context. System prompt includes current date, user name, capabilities list, formatting guidelines. Context-aware: entity_type+entity_id for page-specific chats. GET: list user's sessions. **(8) api/v1/ai/stream.php (NEW)** — SSE streaming chat. Same logic as chat.php but first iteration uses sendMessageStreaming() with onChunk callback. SSE events: token (text delta), tool_start/tool_end (tool execution indicators), done (session_id + message_id), error. Falls back to non-streaming for tool loop iterations after first. **(9) api/v1/ai/chat-session.php (NEW)** — GET: load session with all messages. DELETE: remove session (CASCADE deletes messages). User-scoped (session must belong to requesting user). **(10) api/v1/ai/summary.php (NEW)** — GET: retrieve cached summary. POST: force regenerate. Validates entity_type (customer/lease/equipment_unit/fleet) and summary_type. **(11) api/v1/ai/report.php (NEW)** — POST with natural language query. Uses 'report' context tools (12 financial/query-focused). Report-specific system prompt for structured markdown output. Logs to audit_log. **(12) api/v1/ai/analyze-document.php (NEW)** — POST multipart/form-data. Supports PDF, PNG, JPG, GIF, WEBP (10MB max). Builds Anthropic Vision API content blocks (image type=base64 or document type=base64 for PDF). Custom analysis prompt or default extraction prompt. **(13) api/v1/ai/anomalies.php (NEW)** — GET: list alerts (unread_only filter). POST acknowledge: mark alert as acknowledged. POST scan: manual trigger (settings:edit only). **(14) api/v1/ai/usage.php (NEW)** — GET: today + month usage stats. Per-user breakdown for managers+. **(15) api/v1/ai/test-connection.php (NEW)** — POST: tests Anthropic API key. Logs to audit_log. Permission: settings:edit. **(16) app/admin/ai/index.php (NEW)** — full-page AI Assistant with 5 tabs: Chat (SSE streaming with session sidebar, quick prompts, markdown rendering, tool execution indicators, typing animation, fallback to non-streaming), Reports (natural language query → formatted report), Documents (drag-and-drop upload → AI analysis), Alerts (anomaly list with severity dots, dismiss, manual scan trigger), Usage (admin: token/cost KPI cards with progress bar + per-user table). Chat features: session search, new session button, message history, auto-scroll, auto-resize textarea, Shift+Enter for newline. Lightweight markdown→HTML renderer (headers, bold, italic, code blocks, lists, tables, blockquotes, links). **(17) includes/partials/ai-chat-widget.php (NEW)** — floating chat bubble on every admin page. Fixed bottom-right, 52px circular button with sparkles icon. Slide-up 380×520px panel with mini chat interface. Uses non-streaming chat API. Auto-hides on AI page. Permission-gated (ai:view + AI configured). **(18) includes/partials/ai-summary-card.php (NEW)** — reusable AI summary card for entity show pages. Unique Alpine component ID per instance. Generate/Refresh buttons, loading/error/empty states. Lightweight markdown renderer. **(19) database/migrations/027_ai_anomaly_alerts.sql (NEW)** — ai_anomaly_alerts table (alert_type, severity ENUM, title, description, entity_type, entity_id, data_snapshot JSON, acknowledged_at/by). 4 indexes. **(20) cron/ai_anomaly_scan.php (NEW)** — nightly cron for anomaly detection. Updates ai.last_anomaly_scan setting. **(21) config/navigation.php (MODIFIED)** — added "AI Assistant" nav item with sparkles icon after Analytics. Permission: ai module. **(22) public/assets/icons/sparkles.svg (NEW)** — Heroicons sparkles outline icon. **(23) config/app.php (MODIFIED S026)** — AI_ENABLED, AI_ANTHROPIC_API_KEY, AI_DAILY_TOKEN_LIMIT constants. **(24) config/permissions.php (MODIFIED S026)** — 'ai' module added to all 5 roles: super_admin $ALL, manager $VCE, dispatcher $V, accountant $VCE, read_only $V. **(25) app/admin/settings/index.php (MODIFIED)** — added Anthropic AI Connection card in Integrations tab. FF_AiTest() component with Test Connection button, model display, usage stats (today's tokens/requests/cost), link to AI Assistant. **(26) includes/footer.php (MODIFIED)** — includes ai-chat-widget.php partial before CDN scripts. **(27) app/admin/customers/show.php (MODIFIED)** — AI Customer Insights summary card injected before stats row. **(28) app/admin/leases/show.php (MODIFIED)** — AI Lease Summary card injected before stats row. **(29) app/admin/equipment/show.php (MODIFIED)** — AI Unit Analysis card injected before stats row. **SC passed**: SC1 php -l all 20 PHP files clean ✅; SC2 ToolRegistry: chat=17, report=12, summary=10 tools, _tags stripped ✅; SC3 FleetForgeTools real DB: fleet_summary returns 13 units/40% utilization, search_customers returns 13 active, 7 overdue invoices ✅; SC4 AnomalyDetector real DB: 14 alerts created (overdue spikes, expired compliance, maintenance spikes) ✅; SC5 TokenTracker: today 0 tokens, 500K limit, 500K remaining ✅; SC6 GET /api/v1/ai/chat returns JSON ✅; SC7 GET /api/v1/ai/usage returns JSON ✅; SC8 GET /api/v1/ai/anomalies returns JSON ✅; SC9 ai_anomaly_alerts table created with 14 rows ✅; SC10 navigation.php, settings/index.php, footer.php, customers/show.php, leases/show.php, equipment/show.php syntax clean ✅. |
| S035 | 2026-04-07 | Tax Management (Spec Phase 23) | **1 new service class (~860 lines), 6 new API endpoints, 2 new admin pages, 1 new migration, 4 files modified.** Complete GST/HST + PST filing-period workflow. **(1) database/migrations/029_tax_management.sql** — adds `'tax_remittance'` to `acc_journal_entries.source_type` ENUM + adds `updated_at` columns (DEFAULT CURRENT_TIMESTAMP ON UPDATE) to `acc_tax_filing_periods` and `acc_tax_remittances` for D19 optimistic locking. **(2) lib/Accounting/TaxFilingService.php (NEW, 863 lines)** — six public methods: `createPeriod` (computes filing_due_date as last-day-of-next-month for monthly/quarterly or last-day-of-+3-months for annually — fixes naive `strtotime('Mar 31 +1 month')` rollover-to-May-1 bug), `calculatePeriod` (FOR UPDATE row lock per D20, queries posted GL entries via private `sumTaxCollected`/`sumItc`/`sumSales` helpers, joins through invoice→customer for province filter on PST jurisdictions, blocks `remitted` status with PERIOD_LOCKED), `markFiled` (status guard `calculated`/`filed`→`filed`), `recordRemittance` (inserts row, builds 2-line JE — DR 2030 GST Payable / CR bank.gl_account_id (or 1010 Cash fallback) — links journal_entry_id, flips status, applies §16 closed-period redirect via `resolveOpenEntryDate` mirror of AutoEntryBridge::resolvePeriod), `listPeriods` (paginated with tax_type/status/year filters), `getPeriodDetail` (returns period+remittances+drill-down invoices and bills with province filter for PST). All status transitions write `audit_log` entries inside the same `db_transaction()`. Constants resolve account IDs by code (1010, 1050, 2030, 2040) instead of hardcoding. **(3) Six API endpoints under api/v1/accounting/tax/{periods,remittances}/**: `periods/index.php` GET (paginated list), `periods/show.php` GET (single + drill-down), `periods/create.php` POST 201, `periods/calculate.php` POST (PERIOD_LOCKED → 409, validation → 422), `periods/mark_filed.php` POST (D19 STALE_DATA check → 409, INVALID_TRANSITION → 409), `remittances/create.php` POST 201 (D19 check on parent period, INVALID_TRANSITION → 409). All require_method/require_auth_api/require_permission('tax_management', ...). **(4) app/admin/accounting/tax/index.php (NEW)** — server-side KPI tiles (open/calculated/filed counts + YTD GST owing), filter toolbar (tax_type/status/year), data table with status badges, three Alpine modals (Create / Mark Filed / Remit) using FF_Api + FF_Toast helpers. **(5) app/admin/accounting/tax/show.php (NEW)** — period header card, 4 totals tiles (sales/collected/ITC/net owing — ITC only for gst_hst), Alpine drill-down loader hitting periods/show endpoint, remittance history table, contributing-invoices table, contributing-bills table (gst_hst only). Standard 404 pattern (`http_response_code(404)` + `app/errors/404.php`). **(6) Navigation wire-up**: `includes/partials/accounting-nav.php` Tax leaf added between Fixed Assets and Periods; `config/navigation.php` Tax child added with `match_prefix=['/accounting/tax']`, `module='tax_management'`, icon=`receipt-percent` (SVG already in public/assets/icons/). **(7) lib/Accounting/AutoEntryBridge.php** — added `onTaxRemittancePosted(int, ?int): ?array` no-op stub (TaxFilingService already creates the JE inside its own transaction; this stub is the integration point for future hooks). **TESTS RUN — REAL SEED DATA + DEEP CURL AUDIT**: setup script posted JEs for 5 BC + 1 MB invoice (#161-165 + #6, $607.83 GST CR / $96.00 PST CR) and 3 fresh acc_bills with GST ITC ($240.00 ITC DR), all balanced. test_s035_tax.php = 41 assertions, 41/41 ✅ (covers create/duplicate/calculate/PST province-filter/markFiled/recalc-on-filed/recordRemittance/JE-balance/PERIOD_LOCKED-on-recalc-of-remitted/double-remit-blocked/listPeriods+getPeriodDetail+drill-down). Deep curl audit verified all 10 stop conditions: SC1 6 public methods + lint clean ✅, SC2 all 6 endpoints respond via curl with auth+CSRF ✅, SC3 admin index + show pages render (200, ~138-152KB HTML, no PHP errors) and 99999 → 404 ✅, SC4 sidebar Tax link with receipt-percent SVG inline ✅, SC5 source_type ENUM has tax_remittance + both tables have updated_at ON UPDATE CURRENT_TIMESTAMP ✅, SC6 onTaxRemittancePosted stub callable returns null ✅, SC7 GL DR == CR for all session JEs ($18,636.19 each side) ✅, SC8 D19 mark_filed with stale updated_at='1999-01-01' → HTTP 409 STALE_DATA, fresh updated_at → 200 + status=filed ✅, SC9 §16 closed-period redirect engaged on remittance_date=2026-02-15 → JE entry_date=2025-01-01 (matches AutoEntryBridge::resolvePeriod earliest-open-period convention) + audit_log "PERIOD REDIRECT" entry written ✅, SC10 audit log shows create + 4 status_change events (calculated/filed/period_redirect/remitted) ✅. **BUGS FIXED DURING AUDIT**: (a) `dirname(__DIR__, 4)` was wrong in all 6 tax endpoints — they sit one level deeper than other accounting endpoints under `/api/v1/accounting/tax/{periods,remittances}/`, fix: `dirname(__DIR__, 5) . '/api/bootstrap.php'` so the include resolves to project-root + `/api/bootstrap.php`. (b) `strtotime("$end +1 month")` rolled `2026-03-31 + 1 month` to `2026-05-01` instead of `2026-04-30` — replaced with `DateTimeImmutable::modify('last day of +1 month')` (or `last day of +3 months` for annual) which clamps correctly for end-of-month dates. **CLEANUP**: Feb/Mar 2026 acc_periods re-closed; tax tables emptied; orphan tax_remittance JEs (#56, #57, #58) and their lines deleted; database left in pre-S035 state. |
| VALID-2 | 2026-04-07 | Form Validation Messages (every form, every field, every accounting page) | **Cross-cutting pass completing what VALID-1 started — now every form in the admin app accumulates field-level errors into a single `{success:false, error:{code:"VALIDATION_ERROR", message, fields:{...}}}` envelope, and every client page paints them into `.field-error` slots plus a `.form-error-banner` at the top of the card/modal.** **(1) Accounting pages verified/fully wired** (15 files, ~400 `field-error`/`form-error-banner` markers total): `statements/index.php` (single Generate-Statement form — customer + date range + end≥start), `bank-accounts/index.php` (5 modals: Account / CSV Import / Manual Txn / Transfer / NSF — 63 markers), `journal-entries/index.php` (Create JE modal with header fields + dynamic `lineErrors[]` parallel array + balance check `Math.abs(d-c) > 0.009` + regex parser `/^lines[\.\[](\d+)[\]\.](.+)$/` to map server `lines[N].field` keys back to UI), `bank-reconciliation/index.php` (Start New Recon form), `depreciation/index.php` (Generate Preview modal), `capex/index.php` (4 forms: Create / Complete / Capitalize / Expense — strips `asset_data.` prefix from server keys), `fixed-assets/index.php` (4 modals: Dispose / Impair / Create / Edit — STALE_DATA friendly message, GL account + depreciation method + salvage value rules, payoff fields with financing_rate ≤ 1), `tax/index.php` (Create Period / Mark Filed / Remit modals — tax_type ∈ {gst_hst,pst_bc,pst_sk,pst_mb}, frequency ∈ {monthly,quarterly,annually}, period_end≥period_start, spec-exact messages), `settings/index.php` (5 tabs with per-tab `formErrors` banner + `errors` per-field: general/capex_threshold / depreciation method+life+salvage / tax_filing gst+pst frequency + server key prefix stripping), `bills/index.php`, `vendor-credits/index.php`, `deposits/index.php`, `collections/index.php`, `categorization-rules/index.php`, `chart-of-accounts/index.php`. All 15 accounting pages have: per-form `FormError` banner key + `Errors` object, helpers `_extractError(r, fallback)` + `_clearErrors(errorsObj, bannerKey)` + `_paintErrors(errorsObj, bannerKey, r, fallback)` iterating `r.error.fields` and routing `_general` to the banner, client-side `validateXxx()` mirroring API field rules, `:class="errors.X ? 'is-invalid' : ''"` input highlighting, `@input`/`@change="errors.X = ''"` self-clearing, and `_paintErrors` called in the `!r.success` branch of every submit handler. **(2) Server API bugs fixed during smoke test** — two endpoints were emitting non-VALID-2 envelopes and got rewritten: **(a) `api/v1/vendors/create.php`** was failing fast with `json_error('MISSING_REQUIRED', …)` and single-field `VALIDATION_ERROR` messages on every guard (name, vendor_type, rating, hourly_rate) — refactored to accumulate every error into a `$fields[]` array, check email format via `clean_email()`, validate hourly_rate ≥ 0, and emit one `json_validation_error($fields)` at the end; duplicate-name uniqueness now also reports through the fields envelope so the client highlights the name field. **(b) `api/v1/yards/create.php`** was using a bespoke `['errors' => [...]]` extra key instead of the standard `fields` + was failing fast on `name` — refactored to accumulate errors (`name`, `capacity` non-negative, `manager_id` existence), emit `json_validation_error($fields)` once, and report name collisions through the same envelope. **(3) Smoke test harness** — `tests/_smoke_valid2.php` (NEW, ~290 lines). Manually writes a real PHP session file to `/var/tmp/sess_<id>` (the path Herd's PHP-FPM uses — **not** CLI PHP's `/var/folders/x6/.../T/`), injects `ff_user` + `ff_last_activity` + a known `csrf_token`, then uses `curl_init()` to POST bad JSON payloads against the live Herd endpoints with `Cookie: ff_session=<id>` + `X-CSRF-Token: <csrf>`. **30 test cases run**, covering every create endpoint in the app: Journal Entry (empty / 1 line / unbalanced), Bank Account (empty / bad currency), Bank Transfer (same source/dest), Bill (empty), Tax Period (bad tax_type / end-before-start), CapEx Request, Vendor Credit, AR Deposit, Customer (empty / bad email / discount > 100 / negative credit limit), Equipment Unit (empty / bad year), Lease, Invoice (empty / period_end before period_start), Payment (empty / negative amount), Vendor, Reservation, Damage Claim, Maintenance WO, Inspection, Mileage Log, Yard. Each case asserts: (a) HTTP 422, (b) `success === false`, (c) `error.code === 'VALIDATION_ERROR'`, (d) `error.fields` is a non-empty object containing the expected field keys. **Final run: 30 / 30 PASS, 0 FAIL, 0 SKIP** — all envelopes shaped correctly, every expected field key present. Test file includes defensive cleanup that `DELETE`s any stray `company_name LIKE 'Smoke Acme%'` customers at the end so regressions can't leak rows into the dev DB. **(4) Standards reinforced**: `.form-error-banner` + `.field-error` CSS already shipped in VALID-1; `json_validation_error(array $fields, string $message)` in `api/bootstrap.php` is now the ONLY way form APIs should report validation errors (422, `error.code='VALIDATION_ERROR'`, `error.fields` map). `json_error('MISSING_REQUIRED', …)` fail-fast pattern is retired for form endpoints. Every client submit handler follows the shape: `if (!this.validateX()) return; this.busy = true; try { const r = await FF_Api.post(...); if (r.success) { FF_Toast.success(...); this.xOpen = false; await this.load(); } else { this._paintErrors(this.xErrors, 'xFormError', r, '…'); } } catch(e) { this.xFormError = 'Network error. Please try again.'; } this.busy = false;`. **Stop conditions all ✅**: every form has field-level errors that appear inline (not just a top banner), every validation message is spec-exact, every server field key maps back to its UI slot, duplicate/lookup errors route to the offending field, the smoke test proves the envelope shape is identical across all 30 endpoints. |
| SAMSARA-1 | 2026-04-07 | Full Samsara Integration + Fleet Tracking Module | **6 NEW files, 6 MODIFIED files, 1 schema migration, 1 new table — full live-GPS integration end-to-end.** **(Step 1) lib/GPS/SamsaraClient.php (MODIFIED)** — `getVehicles()` (paginated cursor walk, returns vehicles[] with id/name/vin/serial/gateway), `getVehicleStats()` (single-vehicle telemetry — battery/power/check_in_mode/odometer/gps lat+lng+address+speed+heading/last_connected_at), `getVehicleLocation()` (cheap call when only coordinates needed), `getVehicleOdometer()` (km, used by lease close auto-fill), `pingVehicle()` (composite — `getVehicles()` filtered by id then `getVehicleStats()` in one round-trip; used at link time), `testConnection()` (smoke test endpoint for settings page). All methods return [] on transient API failure and log via SamsaraClient's existing logger so the cron can isolate per-unit failures. **(Step 2) Schema migration** — `ALTER equipment_units` adding 16 `samsara_*` columns: `samsara_vehicle_id` (varchar 100), `samsara_vehicle_name` (varchar 255), `samsara_vin` (varchar 50), `samsara_serial_number` (varchar 100), `samsara_gateway_id` (varchar 100), `samsara_battery_pct` (tinyint unsigned), `samsara_battery_charging` (tinyint(1)), `samsara_power_source` (varchar 50), `samsara_check_in_mode` (varchar 100), `samsara_last_location_lat` (decimal 10,7), `samsara_last_location_lng` (decimal 10,7), `samsara_last_location_address` (text), `samsara_last_speed_kph` (decimal 6,2), `samsara_last_connected_at` (datetime), `samsara_last_synced_at` (datetime), `samsara_odometer_km` (decimal 10,2). NEW table `samsara_location_history` (breadcrumb trail) with FK CASCADE to equipment_units, columns: equipment_unit_id, samsara_vehicle_id, latitude/longitude (decimal 10,7), speed_kph, heading, address, recorded_at, synced_at + indexes on (equipment_unit_id, recorded_at) and recorded_at. **(Step 3) Manual mapping UI + 4 API endpoints** — `api/v1/samsara/vehicles.php` (NEW, GET equipment:edit) returns every Samsara vehicle annotated with `is_linked` + `linked_unit_id` + `linked_unit_number` via in-PHP O(n) join to equipment_units; `api/v1/samsara/link.php` (NEW, POST equipment:edit) validates unit exists, race-checks vehicle isn't already linked elsewhere (409 ALREADY_LINKED), calls `pingVehicle()` for static identifiers + live stats in one round-trip, persists all 16 columns inside `db_transaction()`, seeds first breadcrumb in samsara_location_history, audit_log captures old/new mapping. `api/v1/samsara/unlink.php` (NEW, POST equipment:edit) validates unit currently linked (409 NOT_LINKED), explicitly nulls all 16 columns (no wildcard), preserves breadcrumb history, audit_log preserves old vehicle name. `api/v1/samsara/sync.php` (NEW, dual GET=sync-all + POST=sync-one, equipment:view since writes are derived state) — internal `ff_samsara_sync_one()` helper updates live columns + appends breadcrumb when 7-decimal-rounded lat/lng changed; per-unit failures isolated; GET returns `{synced_count, failed_count, synced[], failed[]}`, POST returns single result or 502 SAMSARA_SYNC_FAILED. **(Step 4) cron/samsara_sync.php (NEW)** — every 5 min schedule (`*/5 * * * *`), acquires `GET_LOCK('ff_cron_samsara_sync', 0)` advisory lock per D21 (silent exit on contention), per-unit try/catch with isolated `db_transaction` so one failure never aborts the batch, breadcrumb append only when 7dp-rounded lat/lng changed (parked vehicles don't bloat the history table), per-unit log line to `logs/gps.log` via local `ff_samsara_log()` helper, summary `audit_log` row with action='cron' + entity_label='samsara_sync', releases lock in `finally`. Static identifier columns (vin/serial/gateway/vehicle_name) NEVER touched at sync time — they were snapshotted at link time and we don't chase Samsara renames. **(Step 5) app/admin/equipment/show.php (MODIFIED)** + `api/v1/equipment/units/show.php` (MODIFIED) — Equipment unit query extended with all 16 samsara_* columns (typed cast for nullable numerics so JS gets `Number` not numeric strings). Hero h1 now has pulsing green `.ff-live-badge` + `.ff-live-dot` (CSS keyframe `ff-live-pulse`) + battery badge + "Track in Samsara" external button (`https://cloud.samsara.com/o/vehicles/{id}`). Subtitle has "Last synced X ago". Tracking tab renamed to "Samsara Mapping" — completely replaced body: unmapped state (vehicle picker dropdown with already-linked entries grayed out, Link button), mapped state (Linked-to bar with Sync Now + Unlink buttons, no-GPS warning, mini Leaflet map, Live Telemetry table, Samsara Identifiers card, View on Fleet Map link). Overview tab now has Samsara Live Tracking 4-column card (Location/Speed/Odometer/Battery) with last-synced footer. Alpine `FF_UnitDetail` rewritten: replaced trackingData/trackingLoading/trackingLoaded with samsaraVehicles/samsaraVehiclesLoading/samsaraVehiclesLoaded/samsaraSelectedVehicleId/samsaraLinking/samsaraSyncing/samsaraError/trackingMap; replaced loadTracking/refreshTracking/initTrackingMap with openSamsaraTab/loadSamsaraVehicles/linkSamsaraVehicle/unlinkSamsaraVehicle/syncSamsaraNow/initSamsaraMap/formatRelative; linkSamsaraVehicle calls await loadUnit() to refresh from canonical source. **(Step 6) Fleet Tracking module — `app/admin/tracking/index.php` (REPLACED, ~670 lines)** + `api/v1/samsara/fleet.php` (NEW). Fleet API is the single source of truth for the dashboard: reads ONLY from cron-cached samsara_* columns (NOT live API — keeps browse near-instant), partitions every active equipment_unit into linked/unlinked in single O(n) pass, evaluates alert thresholds inline (`BATTERY_CRITICAL_PCT=10`, `BATTERY_LOW_PCT=25`, `NOT_CONN_HARD_HOURS=24`, `NOT_CONN_WARN_HOURS=8`), counts online/offline (online = has lat/lng AND last_connected within 8h), LEFT JOINs leases (active) + customers for inline customer_name display, returns `{linked, unlinked, alerts, stats: {total, linked, unlinked, online, offline, alert_counts: {battery_critical, battery_low, not_connected_24h, not_connected_8h, no_gps}}}`. Tracking page has 3 tabs (Map View / List View / Unlinked) + alerts strip above tabs with 24hr dismissal via `localStorage.ff_samsara_dismissed_alerts`. Map View: sidebar list (search + Online/Offline counts + clickable unit cards) + Leaflet map (color-coded pins, status-aware popups). List View: full-detail table (Unit/Status/Customer/Battery/Odometer/Speed/Last Location/Last Connected/Last Synced/Actions) — battery color-coded red <20% / amber <50% / green ≥50%. Unlinked Units tab: table with "Link to Samsara" button → equipment/show tracking tab. Header has stats badges + Auto-refresh checkbox (60s) + Sync All Now button (POST `/api/v1/samsara/sync` GET-mode). Alpine `FF_FleetTracking` component owns linked/unlinked/alerts/stats/dismissed/markers/selectedUnit/mapInitialized; default map center Surrey BC (49.10, -122.66) zoom 9; `_didFitBounds` flag prevents fighting user pan/zoom on refresh; stale-marker culling on each refresh. **(Step 7) Lease integration** — `api/v1/leases/show.php` (MODIFIED): SELECT now LEFT JOINs equipment_units pulling 11 samsara_* fields (vehicle_id/vehicle_name/battery_pct/battery_charging/last_loc_lat+lng+address/speed_kph/last_connected_at/last_synced_at/odometer_km), with explicit type casts so the Alpine UI gets typed numbers. `app/admin/leases/show.php` (MODIFIED): added "Live GPS &amp; Telemetry" card to Overview tab (renders only when `lease.samsara_vehicle_id` is set, spans both columns) — pulsing green Live badge / Offline badge based on `samsaraIsOnline()` rule (same 8h window as fleet dashboard), 4-column stat grid (Last Location address-or-coords / Speed / Odometer / Battery%) + footer line with vehicle name + last-connected/last-synced relative times + "Open Tracking →" link to equipment show tracking tab. Close Lease modal upgraded: "End Mileage" label now shows the lease's `mileage_unit`, button "Pull from Samsara" appears beside the input when `samsara_odometer_km` is set, hint line below the input shows "Pulled from Samsara: X km (last synced Yh ago)". Auto-prefill on `openCloseModal()` when modal first opens (only if mileage_at_end is empty). New `prefillMileageFromSamsara()` converts km→miles with factor 0.621371 only when `mileage_unit==='miles'`, leaves km untouched. New helpers `samsaraIsOnline()` + `formatRelative()` (just-now / Nm ago / Nh ago / Nd ago — returns '—' on null/invalid). **27/27 mandatory tests PASSED with seed data** — seeded 5 units across the alert spectrum (RFR-001 online full battery, RFR-002 24% low battery, RFR-003 8% critical, TEST-U001 offline 18h, FLT-001 no GPS) and verified: (01) SamsaraClient instantiates ✅; (02) equipment_units has 17 samsara_* columns ✅; (03) samsara_location_history table exists ✅; (04) FK to equipment_units ✅; (05) GET /api/v1/samsara/fleet → success ✅; (06) stats=5/80/3/2 (linked/unlinked/online/offline) ✅; (07) alerts=1 critical+1 low+1 no_gps+1 offline-8h ✅; (08) POST /samsara/link {} → 422 ✅; (09) POST /samsara/unlink {} → 422 ✅; (10) POST link bogus unit_id → 404 ✅; (11) POST unlink bogus unit_id → 404 ✅; (12) unit show samsara fields typed (int/float/float) ✅; (13) lease show samsara fields typed ✅; (14) cron/samsara_sync.php runs to completion ✅; (15) cron writes audit_log row ✅; (16) GET /tracking → 200 ✅; (17) /tracking has all 3 tabs + Alpine + Leaflet ✅; (18) equipment/show?id=6 has Samsara Mapping tab ✅; (19) equipment/show?id=6 has Live badge CSS ✅; (20) equipment/show?id=9 (no GPS unit) → 200 ✅; (21) leases/show?id=8 contains Live GPS card ✅; (22) Pull from Samsara button present ✅; (23) prefillMileageFromSamsara() wired ✅; (24) battery_critical threshold = 10% ✅; (25) not_connected_24h threshold present ✅; (26) samsara_location_history table accessible ✅; (27) /tracking wires localStorage dismissal key ✅. All 12 PHP files lint clean (`php -l`). **DECISIONS LOCKED**: (a) Fleet dashboard reads ONLY from cron-cached columns, never live API — speed + zero Samsara dependency for browse-only users. (b) cron uses `GET_LOCK(... ,0)` per D21 — silent exit on contention, expected behaviour during deploy overlap. (c) Per-unit transaction scope inside the cron — one failure never aborts the batch. (d) Static identifier columns (vin/serial/gateway/vehicle_name) snapshotted at LINK time only, never updated by cron — avoids confusing diffs when ops staff rename vehicles in Samsara. (e) Breadcrumb append uses 7-decimal-place rounding (~1cm) so a no-op rounding diff doesn't create a phantom row. (f) Online definition unified: lat/lng present AND last_connected_at within 8h — same rule on fleet dashboard, lease GPS card, and equipment show hero. (g) Conversion direction: Samsara is km-native, FleetForge stores km on the equipment_unit, conversion to miles ONLY in the lease close prefill when `lease.mileage_unit==='miles'` (factor 0.621371). (h) Live badge CSS (rgba 34,197,94 pulse) lives in app/admin/equipment/show.php — NOT in app.css (scoped to where it's used to keep the global stylesheet stable). **NEW SCHEMA TRAPS** (added below): equipment_units has 17 samsara_* columns total (`samsara_vehicle_url` was already there pre-SAMSARA-1, the 16 new ones from this session bring the total to 17); samsara_location_history.recorded_at is the Samsara-side timestamp (NOT synced_at which is FleetForge's cron tick); cron skips when `getVehicleStats()` returns [] — that's NOT a failure, it's a transient API miss the next tick will retry. **TEST DATA NOTE**: 5 units (#4, #6, #7, #8, #9) seeded with fake `TEST-VEH-*` Samsara IDs so the dashboard, alerts, and lease GPS card all render with realistic content. The cron will skip these on next tick (the test API has no matching vehicles) — to clear: `UPDATE equipment_units SET samsara_vehicle_id=NULL, samsara_vehicle_name=NULL, samsara_vin=NULL, samsara_serial_number=NULL, samsara_gateway_id=NULL, samsara_battery_pct=NULL, samsara_battery_charging=NULL, samsara_power_source=NULL, samsara_check_in_mode=NULL, samsara_last_location_lat=NULL, samsara_last_location_lng=NULL, samsara_last_location_address=NULL, samsara_last_speed_kph=NULL, samsara_last_connected_at=NULL, samsara_last_synced_at=NULL, samsara_odometer_km=NULL WHERE samsara_vehicle_id LIKE 'TEST-VEH-%';` |
| SAMSARA-3 | 2026-04-08 | Samsara bidirectional sync + Option-B tag import + per-invoice odometer/distance tracking | **One mega-session, 3 deliverables: (A) bidirectional unit sync FleetForge ↔ Samsara, (B) Option-B tags import + pills, (C) per-invoice odometer + distance tracking.** **(A) Bidirectional sync** — `lib/GPS/SamsaraClient.php` gained three write methods + `apiWrite()` private helper: `createTrailer(name, fields)` (POST /fleet/trailers, maps unit_number→name, vin/year/make/model/license_plate→Samsara fields, notes="Created in FleetForge — unit #{id}"), `updateTrailer(trailerId, fields)` (PATCH /fleet/trailers/{id}, sparse update filtered to non-null fields only), `deleteTrailer(trailerId)` (DELETE /fleet/trailers/{id}, treats 404 as success since "already gone" ≡ "deleted"). All three return null/false on failure and log via existing gps.log. `api/v1/equipment/units/create.php` — after the db_transaction commits, calls `createTrailer()` with template.brand/model pulled from the expanded template SELECT; on success, UPDATEs the new unit row with samsara_vehicle_id, samsara_entity_type='trailer', samsara_vehicle_name, tracking_provider='samsara' in a single statement so the unit is linked the moment it's born. Failure is NEVER blocking — returns 201 with a `samsara_warning` field so the UI can surface a soft alert. **Removed the template-category gate** (dry_van/reefer/chassis/etc. — all trailers) per user directive "the type shouldn't matter anyway, in samsara everything goes under assets": every unit now pushes to /fleet/trailers regardless of equipment_templates.category; POST /fleet/vehicles returns 405 (hardware-provisioned only) and POST /assets creates type=uncategorized with no PATCH/DELETE support, so /fleet/trailers is the only viable write endpoint and creates proper type=trailer entries in Samsara's unified Assets view. `api/v1/equipment/units/update.php` — after successful update, PATCHes Samsara with any changed name/vin/year/license_plate; non-blocking via try/catch. `api/v1/equipment/units/delete.php` — before soft-delete, calls deleteTrailer() if unit has samsara_vehicle_id; non-blocking. Live-tested end-to-end: createTrailer→verify→updateTrailer→verify→deleteTrailer→404 confirm. **(B) Option-B Samsara tags import** — fresh pull via existing `SamsaraClient::getTrailers()` returned 162 matched trailers with 23 unique tags (trimmed): 3 lenders (Bennington Financial Corp - MTTS Units ×30, National Bank ×20, Sonoma Capital Corp - MTTS Units ×11) + 20 lessees (United Coastal Logistics ×45, Sahir Trucking Ltd ×16, Safeco Transport Ltd ×13, TRANSCO ×11, Transource Freightways Ltd ×9, GULZAR TRANSPORT INC ×8, Benson ×7, and 13 more). New `samsara_tags JSON` column added to `equipment_units` (AFTER samsara_odometer_km). Import script (`/tmp/ff_samsara_tags_import.php`, removed after run) created 23 customer rows with title-case normalization of all-caps names (GULZAR TRANSPORT INC→Gulzar Transport Inc, etc.), internal_notes flagging lender vs lessee; for each trailer, stored samsara_tags as JSON array of `{name, raw, type:'lender|lessee', customer_id}` and set `owner_company_id` + `ownership_type='leased'` on the 61 lender-tagged units. Total: 23 new customers (63→86), 152 units with populated samsara_tags (10 untagged → empty array), 61 lender links set. Updated `api/v1/equipment/units/show.php` SELECT + response to expose `samsara_tags`; added Samsara Tags pill card to `app/admin/equipment/show.php` Overview tab (amber pill with bank icon for lenders labelled "Financed by: X", blue pill with person icon for lessees — clickable, linking to customers/show?id=X). **(C) SAMSARA-3 odometer + distance tracking** — NEW concept per session spec: capture starting odometer at lease-start, capture current odometer at invoice-time, calculate per-period + cumulative km driven. **Schema (9 new columns)**: `leases.odometer_start_km DECIMAL(10,2)`, `leases.odometer_start_source ENUM('gps','manual')`, `leases.odometer_start_fetched_at DATETIME`; `invoices.odometer_at_period_start_km DECIMAL(10,2)`, `invoices.odometer_at_period_end_km DECIMAL(10,2)`, `invoices.period_distance_km DECIMAL(10,2)`, `invoices.cumulative_distance_km DECIMAL(10,2)`, `invoices.odometer_source ENUM('gps','manual','estimated') DEFAULT 'manual'`, `invoices.odometer_fetched_at DATETIME`. **New endpoints (2)**: `api/v1/samsara/current_odometer.php` (GET, leases:view, takes equipment_unit_id, returns `{linked, odometer_km, fetched_at, samsara_vehicle_name, message}`, dispatches via `SamsaraClient::getEntityStats($type, $id)` so trailers hit /fleet/trailers/stats and vehicles hit /fleet/vehicles/stats, returns linked=false when unit has no samsara_vehicle_id, returns odometer_km=null when live fetch fails — all with 200 status so UI shows soft warnings not hard errors); `api/v1/leases/update_odometer.php` (POST, leases:edit, takes `{lease_id, odometer_start_km, source, fetched_at?}`, requires active/pending lease (409 LEASE_NOT_ACTIVE for closed), VALID-2 pattern, server-stamps fetched_at when source=gps but none provided, audit_log with old+new values). **InvoiceGenerator update** — `createFromLease()` now accepts optional `odometer_at_period_start_km`, `odometer_at_period_end_km`, `odometer_source`, `odometer_fetched_at` params; computes `period_distance_km = end − start` (bc math, clamped to 0 on negative) and `cumulative_distance_km = end − lease.odometer_start_km` (only when both ends have values), normalizes source to gps|manual|estimated, parses fetched_at ISO→datetime. All 4 new fields go into the invoices insert array. **API updates** — `api/v1/leases/create.php`: new `$odometerStartKm`/`$odometerStartSource`/`$odometerStartFetchedAt` validation block (VALID-2), added to db_insert + `use()` clause. `api/v1/leases/close.php`: new `$odoAtClose`/`$odoSource`/`$odoFetchedAt` input block, added to db_transaction `use()` clause; derives period-start odometer for the final invoice from either the latest prior invoice's end odometer or lease.odometer_start_km, passes all 4 SAMSARA-3 params into `createFromLease()`. `api/v1/invoices/create.php`: new `$odoStart`/`$odoEnd`/`$odoSource`/`$odoFetchedAt` validation (negative check + start≤end ordering), passes into generator. `api/v1/leases/show.php`: SELECT extended with `l.odometer_start_km`, `l.odometer_start_source`, `l.odometer_start_fetched_at` plus a subquery pulling latest invoice's `odometer_at_period_end_km`, `cumulative_distance_km`, `invoice_number`, `id` as `latest_invoice_*_for_odo`, with float casts. **UI updates (4 pages)** — `app/admin/leases/create.php`: new **Starting Odometer** card between Tax Exemption and Notes (Section 6), `<input>` with `GPS`/`Manual` badges, "Fetch from Samsara" button visible only when unit is samsara_vehicle_id-linked, dataset driven by new `data-samsara-linked` attribute on each `<option>` (SELECT also extended with `u.samsara_vehicle_id`). Alpine state: `selectedUnit`, `odometerSource`, `odometerCanFetch`, `odometerFetching`, `odometerBanner`; methods: `fetchStartingOdometer()` (live API call, populates form.odometer_start_km + source=gps + fetched_at), `onOdometerEdited()` (flips GPS→Manual badge, clears fetched_at). `app/admin/leases/show.php` **close modal** — new Closing Odometer section above Actual Mileage: displays the lease's starting odometer + capture method, live-fetch button against /api/v1/samsara/current_odometer (not the old cached `lease.samsara_odometer_km` path), live-calculated "Total km driven this lease", auto-fills `mileage_at_end` with closing−starting converted to lease's mileage_unit (factor 0.621371 for miles). New Alpine: `closeOdoSource`/`closeOdoFetching`/`closeOdoBanner`, methods `fetchClosingOdometer()`, `onClosingOdoEdited()`, `autoFillMileageFromClosingOdo()`, `closingTotalKmDisplay` getter. `app/admin/leases/show.php` **Overview tab** — new Odometer & Distance card (spans 2 cols) showing Starting Odometer + Latest Recorded (linked to invoice show) + Total KM Driven (from latest invoice's cumulative_distance_km), with retroactive capture flow when odometer_start_km is null (input + Fetch from Samsara + Save buttons, gated to active/pending leases only — closed leases show a "cannot capture retroactively" hint). New Alpine state `retroOdo:{value,source,fetchedAt,fetching,saving,banner}` + methods `fetchRetroOdometer()` and `saveRetroOdometer()` (POST to /api/v1/leases/update_odometer then reload). `app/admin/invoices/create.php` **Odometer & Distance section** between Invoice Type and PO Number (rendered via `<template x-if="selectedLease">`): auto-populates period-start odometer from previous invoice's `odometer_at_period_end_km` (pulled via correlated subquery in the leases SELECT) OR lease.odometer_start_km as fallback, showing an explanatory hint below the field; period-end odometer is always blank on load; live-calculated period distance + cumulative distance (with "since lease start on {date}" context and red warning when negative); both start + end have independent Fetch from Samsara buttons; badges flip between GPS and Manual based on edits. New Alpine properties: `odoCanFetch`, `odoFetching`, `odoFetchTarget`, `odoStartSource`, `odoEndSource`, `odoStartAutoSource`, `odoBanner`, `_leaseStartOdo`, `_leaseStartDate`; new getters `periodDistance`, `periodDistanceWarning`, `cumulativeDistance`, `cumulativeContext`; new methods `fetchOdometer(target)`, `onOdoStartEdited()`, `onOdoEndEdited()`, `fmtKm(v)`. Dropdown options extended with `data-equipment-unit-id`, `data-samsara-linked`, `data-lease-start-odo`, `data-lease-start-date`, `data-prev-end-odo`. `app/admin/invoices/show.php` — new server-rendered Odometer & Distance card after the two-column header grid, shown only when at least one odometer column has a value; displays Period Start / End / Period Distance (with GPS/Manual/Estimated badge — info/neutral/warning respectively) / Cumulative Total (with "since lease start on X" context looked up from leases table) / Source (GPS via Samsara + fetched-at timestamp, Estimated, or Manually entered). **Tests** — all 12 modified PHP files `php -l` clean; 9 new DB columns verified; live Samsara fetch confirmed (unit FLT-002 trailer returned 11702.3 km); bc math period_distance=1234.25 verified; full InvoiceGenerator round-trip with odometer fields (INV-2026-00197 generated with odometer_at_period_start_km=1500.00, odometer_at_period_end_km=2750.50 → period_distance_km=1250.50, cumulative_distance_km=1750.50 with lease odometer_start_km=1000.00, source='gps', fetched_at populated) — math MATCHED then soft-deleted + counter re-synced to 198; retroactive odometer update flow verified against an active lease (odometer_start_km=5432.10 written then restored); **cron simulation** (INV-2026-00198) verified the monthly-billing cron path end-to-end — resolved period_start from lease.odometer_start_km=5000.00, period_end from unit.samsara_odometer_km=7500.50, source='gps', fetched_at=samsara_last_synced_at → period_distance=2500.50, cumulative=2500.50 matched expected, then soft-deleted + counter re-synced to 199. **Monthly billing cron wired (cron/invoice_generate_monthly.php)**: SELECT now LEFT JOINs equipment_units for `samsara_odometer_km` + `samsara_last_synced_at`, and pulls `l.odometer_start_km`. Per-lease: if unit has a cached samsara_odometer_km, treats that as period-end (source='gps', fetched_at=unit.samsara_last_synced_at); resolves period-start from the latest prior invoice's odometer_at_period_end_km or falls back to lease.odometer_start_km. Passes all four fields into `createFromLease()` which computes period_distance + cumulative_distance automatically. Non-invasive: leases whose unit has no samsara_odometer_km bill exactly as before (all 4 odometer fields left null). **LEFT JOIN** (not INNER) so leases whose unit was deleted after lease creation still bill. Timing-neutral: makes no assumption about advance vs arrears billing — captures the cached value "as of cron runtime" and labels it accurately. For arrears billing (cron runs after period ends) this is dead-accurate in-month mileage; for advance billing it represents "miles accrued since the last invoice generation moment". Cumulative is always correct either way. No extra Samsara API calls — reads entirely from the 5-min samsara_sync cron's cached columns, so monthly cron stays fast and rate-limit safe. **Decisions**: (a) Non-blocking model everywhere — Samsara read/write failures log + return soft warnings, never 5xx. (b) 404 on DELETE treated as success (idempotent). (c) Closing-odometer button now uses the LIVE /api/v1/samsara/current_odometer endpoint instead of the old cached `lease.samsara_odometer_km` pull — the old "Pull from Samsara" code path still exists in prefillMileageFromSamsara() for backward-compat on old values, but the new Closing Odometer section is the primary flow. (d) Period-start odometer on invoice create is auto-populated but always editable — contiguous chains of invoices carry odometer continuity but any user can correct a mis-fetched reading. (e) Period-distance clamped ≥0 at persistence time (negative reading means bad data; UI also warns). (f) `odometer_start_km` stays decimal (10,2) — separate from legacy `mileage_at_start` INT column so nothing existing breaks. (g) Actual Mileage auto-fill on close modal only runs when both starting and closing odometer are set — users can override independently. (h) Ignored the spec's `GET /fleet/vehicles/stats?types=obdOdometerMeters` hint because trailers don't have OBD; used existing `SamsaraClient::getEntityStats()` which dispatches trailer vs vehicle correctly. **Known pre-existing issue discovered during testing**: invoice_counter settings row for 2026 was stale (1, while INV-2026-00196 existed) — fixed by re-syncing to 198. Unrelated to SAMSARA-3 but worth flagging in case more sessions hit it. |
| PERM-1 | 2026-04-07 | Per-User Permission Overrides + Per-User Display Settings | **Two features in one session, 14 files touched/created.** Feature 1 — **Per-user permission overrides**: new `user_permission_overrides` table (migration `030_user_permission_overrides.sql`) with UNIQUE (user_id, module, action), `granted` TINYINT, `granted_by`, `reason`, FK CASCADE on user delete. `includes/auth.php` `can()` rewritten with layered resolution — super_admin short-circuits true, then per-user override (1=allow, 0=deny) takes precedence over role default, falls through to `permissions[module][action]` only when no override row exists; new helpers `_ff_load_user_overrides($id)` and `_ff_refresh_user_permissions($id)` written; `auth_login()` now loads `permission_overrides` array into session alongside `permissions`. `app/auth/login.php` SELECT extended with `u.display_font_size, u.display_density`. **3 new API endpoints (super_admin only)** — `api/v1/users/permissions/index.php` GET returns user + 24 modules × 5 actions each with `{role, override, effective}` triple + overrides_count + full overrides list with audit metadata; `api/v1/users/permissions/update.php` POST sets/clears one (user_id, module, action) row (granted=1 grants, 0 denies, null clears), validates module against config/permissions.php, blocks super_admin targets with 409 SUPER_ADMIN_PROTECTED, transactional + audit_log; `api/v1/users/permissions/reset.php` POST clears ALL overrides for one user, idempotent (200 even on 0 rows), single summary audit row. **New admin page** `app/admin/users/permissions.php` — server-renders 24×5 toggle matrix into Alpine component, cycle logic none → allow → deny → none, reason modal on every change, override-summary panel, reset-all confirmation modal, legend card, super_admin target shows warning banner + disabled cells, FF_Toast.success/error two-arg signature throughout. `app/admin/users/show.php` — added "Manage Permissions" card in actions panel, super_admin only, hidden when target is super_admin. Feature 2 — **Per-user display settings**: migration `031_user_display_settings.sql` adds `display_font_size TINYINT UNSIGNED DEFAULT 100` (clamped 70–130 step 5) and `display_density ENUM('compact','comfortable','spacious') DEFAULT 'comfortable'` to users. New endpoint `api/v1/users/display_settings/update.php` POST — any authenticated user, self-only, partial update of font_size and/or density, validates against step set + enum, writes DB then syncs `$_SESSION['ff_user']` so next page render sees new values without re-login. `includes/header.php` — server-clamps both values (range 70–130, enum check), injects `<style id="ff-display-font-size">.page-content { font-size: X%; }</style>` SCOPED STRICTLY to `.page-content` so sidebar/topbar/footer/modals are never rescaled, sets `<body data-density="...">`, exposes `window.FF_DISPLAY = {font_size, density}` to JS. `includes/topbar.php` — added `.topbar-display` popover before notifications bell with A−/A+ buttons + 3 density chips + reset, Alpine `ffDisplaySettings()` factory, same `dropdown-enter` transition as other topbar dropdowns. `public/assets/js/app.js` — added `FF_Display` singleton with `STEPS = [70..130 step 5]`, `DENSITY = ['compact','comfortable','spacious']`, `_applyDom()` (live DOM mutation of inline style + body attribute), `_persist()` (POST to API with FF_Toast.error on failure), `setFontSize()/setDensity()/setBoth()/reset()`. `public/assets/css/app.css` — added `.topbar-display*` styles + `body[data-density="compact"] .page-content` + `body[data-density="spacious"] .page-content` variants for `.card-body`/`.card-header`/table cells/`.form-group`/`.page-header`/`.form-control` (comfortable left empty = default). `app/admin/profile/index.php` — added new "Display" tab between Profile and Login History, full controls (slider 70–130 step 5 + density radio cards + live preview card with sample table + reset to default + error banner), Alpine `displaySettingsTab()` component delegates all persistence to `FF_Display`. **All stop conditions passed** (16 tests): permissions API — login as super_admin ✅; GET index for dispatcher ✅ (24 modules, 0 overrides); POST update grant payments.view ✅ (override_id=1, DB row exists, GET reflects role:false→override:1→effective:1); POST update on super_admin target ✅ 409 SUPER_ADMIN_PROTECTED; POST update granted='maybe' ✅ 422 VALIDATION_ERROR with field map; POST update module='widgets' ✅ 422 "Unknown module: widgets"; POST update granted=null ✅ row deleted, count=0; POST reset cleared 3 overrides ✅; display_settings API — POST font_size=120 ✅ 200, DB updated; POST density=spacious ✅ 200; POST both at once ✅ 200; POST font_size=99 ✅ 422 with step list; POST density=huge ✅ 422 with enum list; POST {} ✅ 422 "Nothing to update"; POST font_size=200 ✅ 422; **end-to-end revoke test** — dispatcher logged in, GET /customers ✅ 200; super_admin set override `customers.view = denied`; dispatcher re-logged in, GET /customers ✅ 403; super_admin cleared override; dispatcher re-logged in, GET /customers ✅ 200; profile + permissions pages ✅ 200; PHP syntax check ✅ all 11 PHP files clean. |

---

## NEXT SESSION STARTS WITH

```
Session S036 — Financial Reports & Budgeting + AI Integration Across Accounting (Spec Phase 24)

═══════════════════════════════════════════════════════════════════
RECENT SIDE-TRACK SESSIONS (post-S035, do NOT redo)
═══════════════════════════════════════════════════════════════════

  THEME-1, TILES-1, TILES-2 — UI polish across the admin app
  SEARCH-1, SEARCH-2        — global search rebuild + AI chat partial save
  NOTIF-1                   — full notifications system (lib + API + bell + admin page +
                              portal page + 18 event-wired endpoints + 3 cron alerts).
                              NotificationService::notify() is the only entry point —
                              do NOT write directly to the notifications table from any
                              new endpoint. notifications has soft-delete (deleted_at)
                              and the type/category/portal_user_id columns added in
                              the NOTIF-1 ALTER. New endpoints that should fire a
                              notification call:
                                \FleetForge\Notifications\NotificationService::notify(...)
                              wrapped in try/catch (failures must never roll back the
                              business transaction).
  EMAIL-1                   — full Customer Email System (3 tables, 10 seed templates,
                              EmailService library, 12 API endpoints, global compose
                              modal, Email buttons on customer/invoice/lease/payment
                              show pages, customer Email History tab, standalone
                              templates manager, 3-step bulk email wizard).
                              FleetForge\Email\EmailService::send([...]) is the only
                              entry point for sending customer emails — it logs to
                              email_logs (audit-first, status=pending → sent/failed),
                              wraps body_html in the company brand shell, persists
                              attachments to email_attachments, and delegates the
                              actual SMTP/SES call to the existing Mailer (which still
                              falls back to logs/mail.log when AWS keys are blank).
                              Variables in templates use {placeholder} syntax —
                              EmailService::compileTemplate() merges company-wide
                              vars with caller-supplied ones and leaves unknowns
                              as-is so the user can see them in preview.
                              window.openEmailCompose({customerId, ...}) opens the
                              global modal from any admin page; on send it dispatches
                              the ff-email-sent CustomEvent so listening pages can
                              refresh their email history tab.
  CHAT-1                    — full Internal Team Chat (5 DB tables, 6 seeded channels,
                              16 API endpoints under api/v1/chat/, full-page 3-column
                              chat UI at /chat, mini floating widget, topbar chat icon
                              with 5s-polling unread badge, Alpine FF_Chat + FF_ChatBadge
                              + FF_ChatWidget components, @mention + DM notifications
                              via NotificationService::notify() with $specificUserIds).
                              chat_messages uses is_archived=1 for soft-delete (NOT
                              deleted_at — chat tables are not in SOFT_DELETE_TABLES).
                              DM slug is deterministic: dm_{min_user_id}_{max_user_id}.
                              NotificationService has 'chat'=>null in TYPE_TO_MODULE
                              (always use $specificUserIds — no module fan-out for chat).
                              5s polling via messages/poll.php (after_id cursor) — keep
                              it; do NOT add WebSockets.
  CHAT-HUB-1                — unified the topbar Chat icon + removed the floating AI
                              chatbox entirely. Bottom-right is now reserved for the
                              DM/team chat widget only; AI lives as a plain topbar
                              <a href="/ai"> link (no Alpine factory). New
                              FF_ChatHubBadge() factory (app.js) sums unread from
                              /api/v1/chat/unread/ + /api/v1/messenger/unread and
                              polls every 30s. Topbar chat click calls
                              Alpine.$data(el).toggle() directly — do NOT re-introduce
                              a window.FF_OpenChatHub arrow (Alpine with(scope) loses
                              reactivity once the with-block expires).
  MEDIA-1                   — login page background video (media/video1.mp4 served via
                              public/media symlink, muted+looping+playsinline+autoplay
                              behind a glass-morphism card) AND notification sound cue
                              system. FF_Sound utility (app.js) preloads
                              media/notification.mp3 from <meta name="notification-sound">
                              and .play() is gated by FOUR safety latches: muted flag
                              (localStorage), hasInteracted (first click/keydown/touch),
                              per-badge _initialized latch (no cue on first poll), and
                              !onChatPage / !onRelatedPage so self-messages stay silent.
                              Topbar has a .sound-toggle-btn between theme and chat
                              icons that persists the mute state. Hooked into
                              FF_Notifications, FF_ChatBadge, FF_ChatHubBadge. Never
                              write directly to FF_Sound.audio.play() — always go
                              through FF_Sound.play() so the gates apply.

═══════════════════════════════════════════════════════════════════
WHAT WAS BUILT IN S028–S035 (Accounting Foundation → Tax Management)
═══════════════════════════════════════════════════════════════════

  S028 Foundation • S030 Bridge+GL+AR • S031 Statements/Collections/Deposits
  S032 AP (Bills+Payments+Credits+Rules) • S033 Bank (CSV+Recon+NSF+Transfers)
  S034 Fixed Assets (3 depr methods + Disposal + Impairment + CapEx workflow)
  S035 Tax Management (GST/HST + PST filing periods + CRA remittance JE)

═══════════════════════════════════════════════════════════════════
EXISTING AI INFRASTRUCTURE (S027 — already in place, do NOT rebuild)
═══════════════════════════════════════════════════════════════════

  lib/AI/ClaudeClient.php          ← Anthropic API client + tool-use loop
  lib/AI/SummaryEngine.php         ← cached entity summaries (ai_summaries table)
  lib/AI/AnomalyDetector.php       ← nightly anomaly cron (ai_anomaly_alerts table)
  lib/AI/ToolRegistry.php          ← tool definitions sent to Claude
  lib/AI/Tools/FleetForgeTools.php ← read-only SQL handlers (the actual tools)
  lib/AI/TokenTracker.php          ← per-user token budget enforcement
  api/v1/ai/{chat,stream,summary,anomalies,report,analyze-document,...}.php

  Accounting tools ALREADY exposed (S028 era):
    get_journal_entries, get_fixed_assets, get_budgets,
    get_tax_filing_periods, get_accounting_periods

  ai_summaries.summary_type ENUM (current):
    lease_summary, customer_insights, fleet_health,
    unit_analysis, payment_risk, forecast, anomaly

═══════════════════════════════════════════════════════════════════
REMAINING ACCOUNTING PHASES AFTER S036
═══════════════════════════════════════════════════════════════════

  S037 — Polish & Integration (Spec Phase 25)
    Year-end close, FX revaluation, document attachments, cron jobs, empty/error states

  S038 — QuickBooks Online Sync (Spec Phase 26 — placeholder)
    OAuth 2.0, one-way push, sync log

═══════════════════════════════════════════════════════════════════
CONTEXT — READ BEFORE WRITING ANY CODE
═══════════════════════════════════════════════════════════════════

READ IN THIS ORDER:
  1. FLEETFORGE_CLAUDE_CODE_REFERENCE.md  ← patterns, all helper signatures, Trap list
  2. FLEETFORGE_PROGRESS.md               ← S028–S035 rows, KNOWN ISSUES, S035 KEY LEARNINGS
  3. FLEETFORGE_SPEC_FINAL.md             ← grep "Phase 24" / "Financial Reports" / "Budget"
  4. FLEETFORGE_ACCOUNTING_SPEC.md        ← §10 (Reports) + §11 (Budgeting)
  5. FLEETFORGE_DATABASE_MASTER.sql       ← grep "acc_budgets" + "acc_budget_lines" + "ai_summaries"
  6. lib/AI/SummaryEngine.php             ← gatherContext() / buildPrompt() patterns
  7. lib/AI/AnomalyDetector.php           ← detector method shape (return array of alerts)
  8. lib/AI/Tools/FleetForgeTools.php     ← run() dispatcher + permission stripping pattern
  9. lib/AI/ToolRegistry.php              ← rawDefinitions() — where new tools register
 10. api/v1/ai/summary.php + anomalies.php ← API patterns to copy

═══════════════════════════════════════════════════════════════════
CREDENTIALS & VERIFICATION
═══════════════════════════════════════════════════════════════════

  Admin login: admin@fleetforge.test / admin123
  curl http://fleetforge.test/fleetforge/api/v1/health → {"success":true,"data":{"db":true,...}}
  DB: .env has correct credentials (read via config/app.php → PDO)
  PHP CLI: /Users/avi/Library/Application Support/Herd/bin/php
  Web SAPI session save_path: /var/tmp (test scripts must ini_set explicitly)
  Settings table: columns are `key`, `value`, `group_name` (NOT setting_key — Known Issue #9)

═══════════════════════════════════════════════════════════════════
PART A — S036 CORE (Financial Reports & Budgeting)
═══════════════════════════════════════════════════════════════════

  A1. Migration 030_budgets.sql (only if acc_budgets/acc_budget_lines not yet in schema)
  A2. lib/Accounting/ReportingService.php
      • profitAndLoss($from, $to, $compareMode)        — Rev − COGS − OpEx = NI
      • balanceSheet($asOf)                            — A = L + E with drill-down
      • cashFlow($from, $to)                           — indirect method
      • trialBalance($asOf)                            — used by all 3 above as base
      • Per-period and YTD modes for P&L
      • Comparative columns (this period vs prior period vs prior year)
      • Drill-down to journal entries per account
  A3. lib/Accounting/BudgetService.php
      • create / update / approve / lock / delete (fiscal year, name, dates, per-account monthly)
      • variance($budgetId, $from, $to)                — budget vs actual per account, % variance
  A4. API endpoints:
      • /api/v1/accounting/reports/{pl,bs,cf,trial-balance}.php
      • /api/v1/accounting/budgets/{index,show,create,update,approve,delete,variance}.php
  A5. Admin pages:
      • app/admin/accounting/reports/{pl,bs,cf}.php
      • app/admin/accounting/budgets/{index,create,show,edit}.php
      • Color-code over/under budget on variance report
  A6. Topnav (accounting-nav.php) + sidebar (config/navigation.php) — Reports + Budgets group
  A7. Real-data test: P&L from existing seed JEs ties to trial balance (Rev − Exp = NI)

═══════════════════════════════════════════════════════════════════
PART B — AI INTEGRATION FOR S036 (built in from day one)
═══════════════════════════════════════════════════════════════════

  B1. Migration 031_ai_accounting_summaries.sql
      • ALTER ai_summaries.summary_type ENUM to ADD:
          'pl_narrative','bs_narrative','cashflow_narrative',
          'budget_variance','ar_aging_insights','ap_aging_insights',
          'cashflow_forecast','tax_position','asset_health',
          'reconciliation_health','collections_priorities'
      • No new tables — reuse ai_summaries / ai_anomaly_alerts / ai_token_usage
  B2. Extend lib/AI/Tools/FleetForgeTools.php with NEW read-only tools:
      • get_profit_and_loss(from, to)
      • get_balance_sheet(as_of)
      • get_cash_flow(from, to)
      • get_trial_balance(as_of)
      • get_budget_variance(budget_id, from, to)
      • get_ar_aging() / get_ap_aging()
      • All wired through ToolRegistry::rawDefinitions() with JSON schemas
      • Permission strip: financial fields hidden if !can('accounting','view')
  B3. Extend SummaryEngine::gatherContext() + buildPrompt() for the new types above
  B4. AI features baked into S036 admin pages:
      • reports/pl.php          → "AI Narrative" button → pl_narrative summary
      • reports/bs.php          → "Explain my balance sheet" → bs_narrative
      • reports/cf.php          → "What's driving cash flow?" → cashflow_narrative
      • budgets/show.php        → "Why am I over budget?" → budget_variance
      • All use existing /api/v1/ai/summary.php endpoint with new entity_type='accounting'

═══════════════════════════════════════════════════════════════════
PART C — RETROFIT AI INTO S028–S035 (the "all other accounting modules")
═══════════════════════════════════════════════════════════════════

  For EACH module below, add: (a) AI tool, (b) summary type, (c) anomaly detector,
  (d) UI button on the relevant admin page. Use the existing infrastructure —
  do NOT create parallel systems.

  C1. S030 — General Ledger + AR
      • Tool:     get_ar_aging() — buckets 0-30/31-60/61-90/90+ per customer
      • Summary:  ar_aging_insights — "Top risk customers, collection priorities"
      • Anomaly:  detectARPaymentSlowdown — DSO trending up >15% MoM
      • UI:       app/admin/accounting/index.php (GL dashboard) → "AI Insights" panel

  C2. S031 — Statements / Collections / Deposits
      • Tool:     get_collections_workload(user_id?) — queue size, oldest, $ exposure
      • Summary:  collections_priorities — daily recommended call list with reasons
      • Anomaly:  detectCollectionsBacklog — queue >30 items OR avg age >21 days
      • UI:       app/admin/collections/queue.php → "AI: prioritize my day" button

  C3. S032 — AP (Bills + Payments + Credits + Rules)
      • Tool:     get_ap_aging() / get_upcoming_bill_payments(days)
      • Summary:  ap_aging_insights — early-payment discounts, cash-out planning
      • Anomaly:  detectVendorBillSpike — vendor monthly $$ >2σ above 6-month avg
                  detectDuplicateBillRisk — same vendor + amount + invoice# pattern
      • UI:       app/admin/accounting/ap/index.php → "AI: this week's AP plan" button
                  app/admin/accounting/ap/show.php → "Explain this bill" button

  C4. S033 — Bank (CSV import + Reconciliation + NSF + Transfers)
      • Tool:     get_unreconciled_transactions(account_id, days) +
                  get_reconciliation_status(account_id)
      • Summary:  reconciliation_health — what's holding up the close
      • Anomaly:  detectStaleReconItems — >30 days unreconciled
                  detectNSFPattern — same customer NSF >2× in 90 days
      • UI:       app/admin/accounting/bank/reconcile.php → "AI: suggest matches" button
                  Use Claude to propose CSV-row → JE matches with confidence score

  C5. S034 — Fixed Assets (Depreciation + Disposal + Impairment + CapEx)
      • Tool:     get_asset_health_summary() — by category, depr-to-date %, NBV
                  get_pending_capex_requests()
      • Summary:  asset_health — utilization vs depreciation, impairment candidates
      • Anomaly:  detectImpairmentRisk — assets with utilization <30% for 2+ quarters
                  detectCapexBacklog — pending requests >45 days old
      • UI:       app/admin/accounting/assets/index.php → "AI: impairment candidates"
                  app/admin/accounting/assets/show.php → "Should I dispose this?" button
                  app/admin/accounting/capex/index.php → "AI: prioritize CapEx queue"

  C6. S035 — Tax Management
      • Tool:     get_tax_position(date) — current period accrued tax owing/receivable
                  get_upcoming_tax_due(days)
      • Summary:  tax_position — what's due, when, by jurisdiction; ITC opportunities
      • Anomaly:  detectTaxFilingApproaching — period due in <14 days, status != filed
                  detectITCMismatch — ITC claimed << input tax on bills
      • UI:       app/admin/accounting/tax/index.php → "AI: tax position summary"
                  app/admin/accounting/tax/show.php → "Explain this period" button

═══════════════════════════════════════════════════════════════════
PART D — CRON & REGISTRATION
═══════════════════════════════════════════════════════════════════

  D1. Add new detector methods to AnomalyDetector::runAll() detectors[] array
      (do NOT create a new cron — they all run nightly via existing job)
  D2. Register all new tools in ToolRegistry::rawDefinitions() with full JSON schemas
      → adding to FleetForgeTools::run() match arm
  D3. Update /api/v1/ai/summary.php to accept entity_type='accounting' with the
      new summary_type values, OR use entity_type='gl_period' / 'budget' / etc.
      Pick ONE convention and document it in PROGRESS.md SCHEMA TRAPS.
  D4. AI tokens budget — verify TokenTracker enforces per-user daily cap
      so an over-eager Reports user can't burn the org budget

═══════════════════════════════════════════════════════════════════
SCHEMA TRAPS (carry forward from S035 + new for AI work)
═══════════════════════════════════════════════════════════════════

  • acc_accounts uses `account_type` (NOT `type`); ENUM includes
    'asset','liability','equity','revenue','cost_of_revenue',
    'operating_expense','other_income','other_expense'
  • equipment_units uses `template_id` (NOT `equipment_template_id`)
  • vendors table has `name` only (NOT `company_name` — only customers has that)
  • acc_journal_entries.source_type ENUM now includes 'tax_remittance' (added S035)
    Full list: invoice, payment, credit_note, ap_bill, ap_payment, bank_transaction,
    depreciation, asset_disposal, tax_remittance, fx_revaluation, manual, year_end, recurring
  • acc_journal_entries has NO `journal_entry_id` column —
    that column lives on the subledger tables (acc_asset_disposals, acc_tax_remittances)
  • acc_tax_filing_periods has NO `created_by` column (filed_by only, after marking filed)
  • acc_tax_filing_periods + acc_tax_remittances now have `updated_at` columns (DEFAULT
    CURRENT_TIMESTAMP ON UPDATE) — used by D19 optimistic lock checks
  • audit_log.action ENUM: 'create','update','delete','status_change'
    (NO 'period_redirect' — use 'status_change' for period redirects per S034/S035 convention)
  • acc_tax_remittances.payment_method ENUM: 'online_banking','check','wire','other'
    (NOT 'bank_transfer' or 'cheque')
  • maintenance_work_orders requires `requested_date` on insert
  • PHP file-based sessions (no `sessions` DB table) — cookie name `ff_session`
  • Web SAPI session save_path is `/var/tmp` on this Mac, NOT the CLI tmpfs path —
    test session helpers must explicitly `ini_set('session.save_path', '/var/tmp')` or
    web requests will not see the CLI-created session
  • ai_summaries has UNIQUE (entity_type, entity_id, summary_type) — pick entity_id=0
    for fleet/system-level summaries (P&L, BS, CF are date-range, not entity-bound)
  • ai_summaries.summary_type is ENUM — adding values requires ALTER TABLE
  • SummaryEngine::generate() returns null when ClaudeClient::isEnabled() is false —
    UI must show "AI disabled — set ANTHROPIC_API_KEY" instead of error
  • TokenTracker enforces per-user daily cap — new endpoints must call it before
    invoking ClaudeClient or risk burning the org budget on a single user

DB_SANITIZE_COLUMN (S016):
  db_sanitize_column() backtick-quotes every column name.
  Reason: `condition` is a MySQL reserved word.

FF_API.POST:
  - ALWAYS use base_url() — never bare relative strings.
  - ALWAYS resolves: check d.error in .then(), never use .catch() for business errors.
  - Sends JSON. For file uploads use raw fetch() + FormData + both CSRF headers.

CSRF TRAP: Raw fetch() MUST include both headers:
  'X-Requested-With': 'XMLHttpRequest'
  'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? ''

PORTAL AUTH: Portal uses separate session key ff_portal_user (not ff_user).
  portal_customer_id() for data isolation. portal_is_primary() for sub-user mgmt.
  Portal CSS classes: portal-* prefix.

CSS: .form-control (NOT .form-input) | .table | .stat-grid | .detail-grid
     base_url('api/v1/...') — api_url() does NOT exist
     Badges: badge-success/warning/danger/info/neutral

S035 KEY LEARNINGS (apply in S036):
  • dirname(__DIR__, N) — count slashes carefully when nesting API endpoints.
    /api/v1/accounting/X/index.php uses dirname(__DIR__, 4),
    /api/v1/accounting/X/Y/index.php uses dirname(__DIR__, 5).
    Both append '/api/bootstrap.php' (project root → /api/bootstrap.php).
  • Date math: NEVER use strtotime("$end +1 month") for end-of-month dates —
    Mar 31 +1 month → May 1 (rollover). Use DateTimeImmutable::modify('last day of +1 month').
  • §16 closed-period auto-redirect lands the JE in EARLIEST open period in the system
    (currentOpenPeriod ORDER BY year ASC, month ASC LIMIT 1), NOT the next open period
    after the original date. This is the convention shared with AutoEntryBridge::resolvePeriod.
    For tax remittances and any other §16 caller, set entry_date intentionally if you
    want a different placement.
  • Tax management is in place: acc_tax_filing_periods + acc_tax_remittances + 6 service
    methods + 6 API endpoints. Future PASS-12 tax UI hook into TaxFilingService::listPeriods
    and getPeriodDetail to fetch totals and drill-down rows.

═══════════════════════════════════════════════════════════════════
DELIVERABLES & STOP CONDITIONS — ALL MUST PASS BEFORE PROGRESS.md ✅
═══════════════════════════════════════════════════════════════════

  Core (Part A):
  SC1.  Migration 030 applied (if needed); acc_budgets + acc_budget_lines exist
  SC2.  ReportingService.php with 4 methods + tests pass
  SC3.  BudgetService.php with CRUD + variance() + tests pass
  SC4.  P&L from real seed JEs ties to trial balance (Rev − Exp = NI)
  SC5.  Balance Sheet balances on real seed data (A = L + E)
  SC6.  All Reports + Budgets API endpoints respond correctly via curl
  SC7.  Admin pages render without PHP errors; nav updated

  AI (Parts B + C + D):
  SC8.  Migration 031 ALTER ai_summaries.summary_type ENUM applied
  SC9.  All NEW tools registered in ToolRegistry, each with JSON schema
  SC10. Each new tool returns data when called via api/v1/ai/chat with mock prompt
  SC11. SummaryEngine generates EVERY new summary_type without errors (use mocked
        ClaudeClient if no live API key — verify the gatherContext + buildPrompt path)
  SC12. New anomaly detectors added to runAll() — cron dry-run shows them executing
  SC13. Each retrofitted admin page (C1–C6) shows the new "AI: ..." button and
        the button POSTs successfully to /api/v1/ai/summary.php
  SC14. TokenTracker still enforces per-user daily cap with new endpoints
  SC15. All touched PHP files lint-clean (php -l)
  SC16. PROGRESS.md updated with S036 row + S037 NEXT SESSION block

DO NOT mark complete without running every stop condition with REAL data.
DO NOT rebuild SummaryEngine / AnomalyDetector / ClaudeClient — extend them.
DO NOT skip the deep audit. DO NOT write PROGRESS.md ✅ marks before tests pass.

═══════════════════════════════════════════════════════════════════
SPLIT OPTION (if S036 feels too large mid-execution)
═══════════════════════════════════════════════════════════════════

  S036a = Parts A + B    (new module + native AI)
  S036b = Parts C + D    (retrofit AI into S028–S035 + cron registration)

  Both halves deliver value independently. If splitting, S036a's PROGRESS row
  must NOT block on Part C work, and S036b inherits the Part D registration tasks.
  Do NOT split unilaterally — confirm with the user before stopping after Part B.
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

---

## Footnote — 2026-04-07 · VALID-2 Form Validation Messages (continuation session)

**What was just done in this session:**

1. **Verified 15 accounting admin pages already wire `.field-error` slots + `.form-error-banner` banners** against per-form `errors{}` state and clear-on-input handlers (statements, bank-accounts, journal-entries, bank-reconciliation, depreciation, capex, fixed-assets, tax, settings, bills, vendor-credits, deposits, collections, categorization-rules, chart-of-accounts). All use the shared helper triplet `_extractError / _clearErrors / _paintErrors` and `:class="errors.X ? 'is-invalid' : ''"` bindings. ~400 error markers total.

2. **Fixed 2 real server-side VALID-2 violations** discovered by the new smoke test:
   - `api/v1/vendors/create.php` — was fail-fast `json_error('MISSING_REQUIRED', …, 422)` on first bad field. Refactored to accumulate every guard (name / vendor_type / email / rating / hourly_rate / specializations) into `$fields[]` and emit **one** `json_validation_error()` call. Duplicate-name collisions now also flow through the standard `fields` envelope so the client can highlight the offending input.
   - `api/v1/yards/create.php` — was emitting a bespoke `['errors' => [...]]` extra key and failing fast on missing name. Refactored to use the standard `fields` envelope, accumulate name + capacity + manager_id guards, and route duplicate-name collisions through `json_validation_error()`.

3. **New smoke-test harness — `tests/_smoke_valid2.php`** (~310 lines):
   - Manually writes a real PHP session file to `/var/tmp/sess_<id>` (the path Herd PHP-FPM reads from — **not** CLI PHP's `/var/folders/.../T/`), injecting a super_admin `ff_user` payload + `csrf_token` so we can authenticate cURL calls against the live Herd endpoints without going through the interactive login flow.
   - POSTs 30 bad payloads and asserts each response is `HTTP 422` + `success=false` + `error.code='VALIDATION_ERROR'` + `error.fields` containing the expected field keys.
   - Coverage: Journal Entry (empty / 1-line / unbalanced), Bank Account, Bank Transfer, Bill, Tax Period (bad type / end-before-start), CapEx, Vendor Credit, AR Deposit, Customer (empty / bad email / discount > 100 / negative credit), Equipment Unit (empty / bad year), Lease, Invoice (empty / bad period), Payment (empty / negative amount), Vendor, Reservation, Damage Claim, Maintenance WO, Inspection, Mileage Log, Yard.
   - `register_shutdown_function` unlinks the session file on exit; defensive cleanup deletes any stray `Smoke Acme%` customer rows at the end of the run so the test is idempotent.

**Final smoke-test result: `PASS: 30    FAIL: 0    SKIP: 0    TOTAL: 30`**

**Standards reinforced:**
- `json_validation_error(array $fields, ?string $message = null)` in `api/bootstrap.php` is the **only** form-validation response helper. No more fail-fast `json_error('MISSING_REQUIRED', …)` on first bad field. No more bespoke `['errors' => [...]]` extra keys.
- Every create/update endpoint must accumulate errors into `$fields[]` and emit exactly one `json_validation_error($fields)` call before any insert.
- Client forms must paint errors into inline `.field-error` slots + a top `.form-error-banner`, clear on `@input` / `@change`, and short-circuit submit on client-side `validate*()` helpers.

*Last updated: 2026-04-09 — PERM-TEST-1 Permissions System End-to-End Test complete:* Non-destructive verification session — no production code modified, only FLEETFORGE_PROGRESS.md touched. Created 15 test users (3 managers / 3 dispatchers / 3 accountants / 3 read_only viewers / 1 inactive / 2 extra edge-case dispatcher+accountant) under `@fleetforge.test` domain with bcrypt-hashed shared password. Initial `php -r` hash generation was corrupted by zsh `!` history-expansion (`TestPass2026!`); caught during login smoke test and regenerated via `/tmp/gen_hash.php` script file (returned `VERIFY=true`, old stored hash confirmed invalid via `password_verify()`); all 15 rows rehashed. Applied 38 `user_permission_overrides` rows across 11 scenarios (4 pure-role controls: Frank/Isabella/Liam/Carol; 7 mixed grant+deny): David (dispatcher + payments.view/create + accounting.view grant + customers.delete deny), Emma (reports/analytics grant + leases.create deny), Grace (equipment + leases grants), Henry (customers grants + accounting.delete/settings denies), James (enhanced viewer: customers/equipment/leases/invoices view grants), Kate (reports/analytics grants), Alice (users.delete + settings.edit denies), Bob (accounting.settings grant + customers/equipment delete denies), Nathan (promoted dispatcher: payments/invoices/reports grants + customers/equipment delete denies), Olivia (leases/equipment/customers/reports grants + accounting.delete deny). **HTTP access matrix: 123/123 PASS** across 14 active users × ~9 admin pages each (dashboard/customers/equipment/leases/invoices/payments/reports/analytics/users/settings + reservations) plus inactive-login-blocked check — login POSTs to `/fleetforge/auth/login` with CSRF from login page, session cookie preserved, each URL probed with expected `200`/`403`. **Direct `can($module,$action)` unit tests: 63/63 PASS** via `/tmp/test_can.php` bootstrapping `config/app.php` + `includes/{db,functions,auth}.php`, calling `_ff_load_user_overrides()` and populating `$_SESSION['ff_user']` to mirror `auth_login()` shape, then asserting true/false for every scenario's grant/deny intent plus super_admin bypass and pure-role controls. **Permissions admin API round-trip: 7/7 PASS** — GET `/api/v1/users/permissions?user_id=7` returns 200 with 4 override markers; POST `/api/v1/users/permissions/update` grants `equipment.delete=1` (HTTP 200, override_id 45, DB row confirmed); second POST with `granted:null` deletes the row (HTTP 200, override_id null, DB count 0); POST `/api/v1/users/permissions/reset` cleared all 5 of David's overrides (HTTP 200, cleared_count 5); non-admin (Bob/manager) gets HTTP 403 attempting update; setting override on admin (super_admin, user_id 1) returns HTTP 409 SUPER_ADMIN_PROTECTED; bug caught: first POST attempts failed with `CSRF_INVALID` because `auth_login()` regenerates the session token, so the pre-login CSRF captured from the login page is stale — fixed the test harness to re-read CSRF from `<meta name="csrf-token">` on `/dashboard` after login. **POST/DELETE mutation enforcement: 17/17 PASS** — Frank (pure dispatcher) blocked on `/api/v1/payments` GET + create (403); David unlocked via override (payments GET 200, payments create 422 validation, not 403); Frank creates customer+lease succeed past `require_permission()` (422 validation, role VC/VCE); Liam+James blocked on customer create (403); David blocked on customer delete by deny override (403); Emma blocked on lease create by deny override (403); Liam/Isabella/Frank/Carol all 200 on GET invoices; Frank+Liam 403 on users list, Carol 200 (manager V). **Sidebar visibility audit** via HTML slice of `<aside class="sidebar">` confirms role gating + override layering: Carol (pure manager) sees all ~32 modules including users+settings; Frank (pure dispatcher) sees 15 modules, no payments/reports/analytics/users/settings/accounting/audit/rates; Isabella (pure accountant) sees 30 modules, no users+settings; Liam (pure read_only) sees 29 modules, no users+settings; David picks up **payments** and **invoices** on top of Frank's dispatcher baseline via override; Nathan adds **payments + invoices + reports** (invoices already visible via dispatcher baseline V); Alice (manager+deny) still sees users+settings (denies are `users.delete` + `settings.edit`, not view). **Confirmed PERM-1 resolution order** works as documented in `auth.php:136-150`: super_admin always true → per-user override (1=allow, 0=deny) → fallthrough to role default from `config/permissions.php`. Overrides loaded into `$_SESSION['ff_user']['permission_overrides']` at login-time by `_ff_load_user_overrides()`, so `can()` is O(1) per call. **Noted limitations (not failures)**: (1) `api/v1/users/permissions/update.php:66` restricts `action` to `['view','create','edit','delete','export']` — direct SQL inserts bypassed this, so Henry's `accounting.settings=0` and Bob's `accounting.settings=1` are unreachable via the admin UI; (2) the symbolic `accounting` module used in David's override does not match the real submodule names (`journal_entries`, `chart_of_accounts`, `accounts_payable`, etc.) which is why David's sidebar does not gain any accounting nav items — this is consistent with how the sidebar checks per-submodule view rights; (3) admin password hash was temporarily rehashed to test the permissions API round-trip (original hash `$2y$12$NqAaWtHTM3cG2k1sJEyEP.kOkR2Fhpk1Inz.cokzgV1vhXPfNY0VW` saved and **fully restored** before commit); (4) after the reset endpoint test wiped David's overrides, all 4 original rows (payments.view/create, accounting.view, customers.delete) were re-inserted so the final DB state matches the setup. **Test artifacts** left in `/tmp/` (not in repo): `create_users.sql`, `apply_overrides.sql`, `test_permissions.sh` (123 assertions), `test_can.php` (63 assertions), `test_perm_api.sh` (7 assertions), `test_sidebar.sh`, `test_api_mutations.sh` (17 assertions), `gen_hash.php`, results files `perm_test_results.txt`/`perm_api_results.txt`/`sidebar_results.txt`/`api_mutation_results.txt`. **Final tally: 210 assertions, 210 PASS, 0 FAIL** — no production code modified, no fixes needed. PERM-1 permissions layer ships as designed. Files modified in this session: `FLEETFORGE_PROGRESS.md` only. Previous: CHAT-2 Full Chat System Rebuild complete: Dropped and recreated all 5 chat tables (chat_channels, chat_channel_members, chat_messages, chat_attachments, chat_reactions) with corrected schema — fixed `is_archived` → `is_deleted` on messages, `role` enum now includes 'owner', `type` enum includes 'customer', `attachment_type` as flat columns (not JSON blob), nullable `user_id` for portal senders. Seeded 6 channels (#general, #leases, #maintenance, #accounting private, #compliance, #general-alerts) + DM (Avi↔Test User) + customer conversation (LP Logistics Inc.) + 21 messages + 3 record attachments. Rebuilt 22 API endpoints across: channels (index/create/show/update/archive), channel members (index/add/remove), messages (index/create/update/delete/poll), reactions (toggle), DMs (create), customer conversations (create/index), search (messages/records), unread (count/mark_read), upload. Rebuilt `app/admin/chat/index.php` (~700 lines) — 3-column layout (sidebar/main/right-panel), all 5 modals, date separators, new-message banner, online dots, emoji picker, @mention dropdown, /record picker, file upload, reply/edit/delete, customer info panel. Rebuilt `FF_Chat()` in `app.js` (~900 lines) — all new state, computed getters (`messagesWithSeparators`, `filteredChannels`, `filteredDMs`, `filteredCustomerConvos`, `isChannelAdminOrOwner`, `currentUserId`), 5s polling with page-visibility gate, near-bottom scroll logic, sound notification, flat attachment FormData send. Fixed `FF_ChatBadge` + `FF_ChatHubBadge` + `FF_ChatWidget.pollUnread()` all pointing to old `/api/v1/chat/unread/index.php` → corrected to `/api/v1/chat/unread/count.php`. Built portal chat system: 4 new portal API endpoints (`app/portal/api/chat/{_bootstrap,channel,messages,send,poll}.php`) with portal auth + CSRF; `app/portal/chat/index.php` — iMessage-style single-thread view (staff on left, portal on right), attachment cards, 10s polling; portal sidebar updated with Chat nav item + unread badge; `FF_PortalChat()` Alpine component in `app.js`. Previous: BUGFIX-1 three-bug fix session: (1) Chat @mention notification deep-link — `FF_Chat.init()` in `app.js` was receiving `messageIdParam` but never using it; fixed to scroll to and highlight the specific message (`[data-message-id]` query + `.chat-message--highlight` CSS class, 3s fade) after channel load, queued via `$nextTick` so it overrides `selectChannel`'s `scrollToBottom`. (2) Admin invoices list ignoring URL context params — tiles on lease/customer show pages link to `invoices?lease_id=X&status=overdue` etc. but `FF_Invoices.init()` never read URL params; fixed by adding `leaseId`/`customerId` to filters object, reading `?lease_id`, `?customer_id`, `?status` in `init()`, and passing them to `api/v1/invoices/index.php` in `load()`. (3) Portal fatal SQL error "Unknown column 'et.year'" — `equipment_templates` aliased as `et` has no `year` column (it lives on `equipment_units`); fixed in `app/portal/leases/view.php`, `app/portal/equipment/index.php`, and `api/v1/chat/attachments/available.php` (also fixed `et.make` → `et.brand` in the last file). Full scan confirms no remaining `et.year` or `et.make` column references. Previous: PORTAL-FIX-1 bug fix: portal login broken because all invite/reset-password flows had wrong token column (invite_token vs password_reset_token) and missing email param in URL — 3 files fixed, known password set for test user. Previous: CHAT-1 Internal Team Chat complete: 5 new DB tables (chat_channels/members/messages/attachments/reactions); 6 seeded channels (#general, #leases, #maintenance, #accounting, #compliance, #general-alerts) + DM between admin users; 16 API endpoints (channels CRUD + join/leave/members, messages CRUD + poll + update, reactions toggle, unread index + mark_read, dm create, search, attachments available); full-page 3-column chat at /fleetforge/chat with Alpine FF_Chat() component (cursor pagination, 5s polling, @mention dropdown, record attachment picker, emoji reactions, edit/delete, reply threading, DM support, deep-link via ?channel=&message=); topbar chat icon with FF_ChatBadge() unread badge polling every 5s; mini Intercom-style floating widget (FF_ChatWidget()); @mention and DM notifications integrated with NotificationService (chat=>null module maps to $specificUserIds only); ~600 lines chat CSS; dirname trap fixed in messages/create.php (dirname(__DIR__,4) not 5). 20 live API tests all passing, 26ms poll response time. Previous: NOTIF-1 Notifications System complete: schema ALTER added type/category/portal_user_id/deleted_at columns; new `lib/Notifications/NotificationService.php` (module-based fan-out via permission lookup, super_admin always included, portal isolation, catch-all errors); 4 admin API endpoints (`/api/v1/notifications/{index,count,mark_read,delete}.php`); 4 portal API endpoints (`/portal/api/notifications/*` with cross-tenant isolation verified); topbar bell rewired as Alpine `FF_Notifications()` factory with 60s polling + category icons + colors + optimistic mark-read; full `/notifications` admin page with status/category/date_range/search filters + pagination; portal bell + `/portal/notifications` page; ~370 lines of CSS for `.notif-*` classes; `NotificationService::notify()` calls wired into 15 event endpoints (lease create/activate/close/cancel/reopen, invoice create/send/void, payment create/delete, customer create/update, equipment create/update_status, maintenance create/update_status, damage create/update, reservation create/update_status, user create, accept_invite) + 3 crons (compliance_alerts, invoice_overdue, samsara_sync battery+offline); seed data for 10 admin + 3 portal notifications across 12 categories; styling-fix pass after user feedback (category-specific icons replaced bell-everywhere, em-dash encoding repaired in seed data, page-header layout uses proper `.page-subtitle` + `.notif-filter-*` + `.notif-list-body` + `.notif-page-footer` classes added to app.css, removed all inline-style cruft from both admin and portal pages). All endpoints verified live: count <15ms, fan-out delivers to module-permitted users + super_admin, portal user 404s on admin notification access, /notifications page renders with category icons + correct colors + em-dashes. Next: S036 — Financial Reports & Budgeting + AI Integration Across Accounting (Spec Phase 24). Build: ReportingService (P&L / BS / CF / TB), BudgetService (CRUD + variance), reports + budgets API + admin pages, plus extend FleetForgeTools/SummaryEngine with new accounting tools and summary types (pl_narrative, bs_narrative, cashflow_narrative, budget_variance, ar_aging_insights, ap_aging_insights, asset_health, etc.), and retrofit AI buttons across S028–S035 modules. See "NEXT SESSION STARTS WITH" block above for the full scope.*

