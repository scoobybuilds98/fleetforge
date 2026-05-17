# legacy_database_migrations/

Pre-runner manual migrations from 2026-04-05 → 2026-05-03. **Do not execute.** Every DDL/INSERT here is captured in `FLEETFORGE_DATABASE_MASTER.sql` and present in the live DB.

## Origin

These 12 files lived at `database/migrations/` (relative to repo root) until **S-MIGRATION-AUDIT (2026-05-17)** archived them. They were authored and applied manually via `mysql` CLI before the migration runner shipped in **S-MIGRATIONS-RUNNER (2026-05-04)**. After that session, all new schema deltas go through:

- `db_migrations/` directory (canonical, scanned by the runner)
- `bin/migrate.php` CLI
- `schema_migrations` tracking table (SHA-256 checksum + applied_at per file)

The runner has no concept of these legacy files — `lib/Migrations/Runner.php:60` reads only `db_migrations/`.

## Verification

S-MIGRATION-AUDIT (2026-05-17) ran `information_schema` queries against the live DB for every CREATE TABLE / ADD COLUMN / ENUM extension / INSERT in these files. Result: 100% present.

Summary of what each file would have created (all already exist):

| File | DDL | Verified in live DB | In master schema |
|------|-----|---------------------|------------------|
| 027_ai_anomaly_alerts.sql | `ai_anomaly_alerts` table | ✅ | ✅ |
| 028_capex_requests.sql | `acc_capex_requests` table + `accounting.capex_next_number.2026` setting | ✅ | ✅ |
| 029_tax_remit_source.sql | `acc_journal_entries.source_type` += `tax_remittance` + 2× `updated_at` ADD COLUMN | ✅ | ✅ |
| 030_user_permission_overrides.sql | `user_permission_overrides` table | ✅ | ✅ |
| 031_user_display_settings.sql | `users.display_font_size` + `users.display_density` columns | ✅ | ✅ |
| 032_integration_settings.sql | 12 settings rows (`ai.anthropic_api_key`, 6× `email.*`, `storage.driver`, 4× `aws.*`) | ✅ | n/a (settings live in DB, not master DDL) |
| 033_samsara_entity_type.sql | 2× `samsara_entity_type` columns on equipment_units + samsara_location_history | ✅ | ✅ |
| 034_chat_tables.sql | 5 chat_* tables | ✅ | ✅ |
| 035_messenger_tables.sql | 3 messenger_* tables | ✅ | ✅ |
| 036_currency_markup.sql | 2× `currency_markup_pct` columns + `currency.usd_cad_markup_pct` setting | ✅ | ✅ |
| 037_advance_billing.sql | `leases.advance_billing_periods` + `invoices.generation_source` += `advance` + `billing.max_advance_periods` setting | ✅ | ✅ |
| 038_lease_dual_units.sql | 6 dual-unit columns on leases + 2 conversion settings | ✅ | ✅ |

## Discipline

- **Never execute.** All targets exist; `CREATE TABLE IF NOT EXISTS` would be a no-op but `ALTER TABLE ... ADD COLUMN` would fail with "Duplicate column name" because the column already exists.
- **Never `git rm`.** These files carry architectural intent comments worth preserving (e.g. migration 035 documents the MSGR-1 vs CHAT-1 separation rationale; migration 037 documents the advance-billing business rules).
- **Never register in `schema_migrations`.** The runner only governs post-2026-05-04 deltas. Registering would require fabricating SHA-256 checksums and could mask future drift if a real file ever ended up with the same name.
- **Reference, don't resurrect.** If a future schema change needs to extend something one of these introduced, write a NEW migration in `db_migrations/` using the current naming convention `YYYYMMDDhhmm_S-SESSION-LABEL_description.sql`.
