# FleetForge — Schema Quick Reference
**Auto-generated from live database. Do NOT edit manually.**
**Regenerate:** `php scripts/generate_schema_ref.php`
**Generated:** 2026-05-20
**Tables:** 134 total · **Columns:** 2086

> This file is the authoritative source for on-disk column names.
> Use it instead of spec files when writing column references in
> session prompts. Spec column names are idealized and often
> differ from on-disk reality (see K-22 traps in
> `FLEETFORGE_CLAUDE_CODE_REFERENCE.md`).

**Grouping:** core tables → `acc_` tables → all others. Each group alphabetical.

---

# Core tables

_12 tables._

## `customers`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `company_name` | varchar(255) | MUL | NO |
| `is_related_party` | tinyint(1) |  | NO |
| `contact_name` | varchar(255) |  | YES |
| `email` | varchar(255) |  | YES |
| `email_disabled` | tinyint(1) |  | NO |
| `email_disabled_reason` | varchar(255) |  | YES |
| `email_disabled_at` | datetime |  | YES |
| `phone` | varchar(50) |  | YES |
| `alt_phone` | varchar(50) |  | YES |
| `website` | varchar(500) |  | YES |
| `address` | varchar(500) |  | YES |
| `city` | varchar(100) |  | YES |
| `state` | varchar(100) |  | YES |
| `postal_code` | varchar(20) |  | YES |
| `country` | varchar(100) |  | YES |
| `province` | varchar(100) |  | YES |
| `tax_id` | varchar(100) |  | YES |
| `dot_number` | varchar(100) |  | YES |
| `mc_number` | varchar(100) |  | YES |
| `gst_number` | varchar(50) |  | YES |
| `pst_number` | varchar(50) |  | YES |
| `billing_contact_name` | varchar(255) |  | YES |
| `billing_email` | varchar(255) |  | YES |
| `billing_phone` | varchar(50) |  | YES |
| `billing_address` | text |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `mileage_unit` | enum('km','miles') |  | NO |
| `tax_exempt` | tinyint(1) |  | NO |
| `gst_exempt` | tinyint(1) |  | NO |
| `pst_exempt` | tinyint(1) |  | NO |
| `tax_exempt_number` | varchar(100) |  | YES |
| `gst_exempt_number` | varchar(100) |  | YES |
| `pst_exempt_number` | varchar(100) |  | YES |
| `tax_exempt_expiry` | date |  | YES |
| `gst_exempt_expiry` | date |  | YES |
| `pst_exempt_expiry` | date |  | YES |
| `tax_exempt_document` | varchar(500) |  | YES |
| `tax_rate_id` | int unsigned | MUL | YES |
| `billing_cycle` | enum('monthly','on_close_only') |  | NO |
| `gps_revenue_presentation` | enum('net','gross') |  | NO |
| `invoice_delivery` | enum('email','mail','portal','none') |  | NO |
| `invoice_email` | varchar(255) |  | YES |
| `invoice_cc_emails` | json |  | YES |
| `po_required` | tinyint(1) |  | NO |
| `default_po_number` | varchar(100) |  | YES |
| `discount_type` | enum('none','percentage','flat') |  | NO |
| `discount_value` | decimal(8,4) |  | NO |
| `discount_reason` | varchar(255) |  | YES |
| `discount_valid_from` | date |  | YES |
| `discount_valid_to` | date |  | YES |
| `late_fee_enabled` | tinyint(1) |  | NO |
| `late_fee_type` | enum('percentage','flat') |  | YES |
| `late_fee_value` | decimal(8,4) |  | YES |
| `late_fee_grace_days` | tinyint unsigned |  | YES |
| `status` | enum('active','inactive','pending','suspended','credit_hold') | MUL | NO |
| `credit_limit` | decimal(12,2) |  | YES |
| `payment_terms` | varchar(100) |  | YES |
| `preferred_yard` | varchar(100) |  | YES |
| `risk_score` | enum('low','medium','high') | MUL | NO |
| `risk_notes` | text |  | YES |
| `account_credit_balance` | decimal(12,2) |  | NO |
| `lease_count` | int unsigned |  | NO |
| `active_lease_count` | int unsigned |  | NO |
| `total_revenue` | decimal(14,2) |  | NO |
| `outstanding_balance` | decimal(12,2) |  | NO |
| `collection_status` | enum('current','watch','collections','legal','written_off') |  | NO |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `updated_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |

## `documents`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `entity_type` | enum('customer','equipment_unit','lease','inspection','damage_claim','contract','service_request') | MUL | NO |
| `entity_id` | int unsigned |  | NO |
| `title` | varchar(255) |  | NO |
| `document_type` | varchar(100) |  | NO |
| `file_path` | varchar(500) |  | NO |
| `file_name` | varchar(255) |  | NO |
| `file_size_kb` | int unsigned |  | YES |
| `mime_type` | varchar(100) |  | YES |
| `expiration_date` | date | MUL | YES |
| `version` | tinyint unsigned |  | NO |
| `parent_id` | int unsigned | MUL | YES |
| `is_current` | tinyint(1) |  | NO |
| `is_private` | tinyint(1) |  | NO |
| `notes` | text |  | YES |
| `uploaded_by` | int unsigned | MUL | YES |
| `uploaded_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `inspections`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `inspection_number` | varchar(50) | UNI | YES |
| `inspection_type` | enum('pre_lease','post_lease','periodic','damage','compliance') |  | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `overall_condition` | enum('excellent','good','fair','poor','damaged') |  | YES |
| `condition_score` | tinyint unsigned |  | YES |
| `status` | enum('draft','complete','signed') |  | NO |
| `inspected_by` | varchar(255) |  | YES |
| `inspected_by_user_id` | int unsigned | MUL | YES |
| `inspection_date` | date |  | NO |
| `mileage_at_inspection` | int unsigned |  | YES |
| `reefer_hours` | int unsigned |  | YES |
| `fuel_level` | enum('empty','quarter','half','three_quarter','full') |  | YES |
| `cvi_expiry` | date |  | YES |
| `is_clean` | tinyint(1) |  | YES |
| `customer_signature` | varchar(500) |  | YES |
| `signed_at` | datetime |  | YES |
| `pdf_path` | varchar(500) |  | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `invoices`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `invoice_number` | varchar(100) | UNI | NO |
| `invoice_type` | enum('regular','final','credit_note','late_fee','mileage_only','adjustment') |  | NO |
| `customer_id` | int unsigned | MUL | YES |
| `lease_id` | int unsigned | MUL | YES |
| `billing_period_id` | int unsigned | MUL | YES |
| `customer_name_snapshot` | varchar(255) |  | YES |
| `company_name_snapshot` | varchar(255) |  | YES |
| `contract_number_snapshot` | varchar(100) |  | YES |
| `unit_number_invoice_snapshot` | varchar(100) |  | YES |
| `billing_address_snapshot` | text |  | YES |
| `customer_email_snapshot` | varchar(255) |  | YES |
| `tax_exempt_snapshot` | tinyint(1) |  | NO |
| `gst_exempt_snapshot` | tinyint(1) |  | NO |
| `pst_exempt_snapshot` | tinyint(1) |  | NO |
| `tax_exempt_number_snapshot` | varchar(100) |  | YES |
| `po_number` | varchar(100) |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate_to_cad` | decimal(10,6) |  | YES |
| `currency_markup_pct` | decimal(6,4) |  | NO |
| `billing_period_start` | date | MUL | NO |
| `billing_period_end` | date |  | NO |
| `billing_period_days` | smallint unsigned |  | NO |
| `odometer_at_period_start_km` | decimal(10,2) |  | YES |
| `odometer_at_period_end_km` | decimal(10,2) |  | YES |
| `period_distance_km` | decimal(10,2) |  | YES |
| `cumulative_distance_km` | decimal(10,2) |  | YES |
| `odometer_source` | enum('gps','manual','estimated') |  | YES |
| `odometer_fetched_at` | datetime |  | YES |
| `billing_type` | enum('partial_start','full_month','partial_end','single_period','mileage_only','credit_note','adjustment') |  | NO |
| `rate_method_used` | enum('daily','weekly','weekly_capped','monthly','none') |  | NO |
| `rate_method_explanation` | json |  | YES |
| `invoice_date` | date |  | NO |
| `due_date` | date | MUL | NO |
| `paid_date` | date |  | YES |
| `sent_date` | date |  | YES |
| `voided_date` | date |  | YES |
| `status` | enum('draft','sent','partially_paid','paid','overdue','void','written_off') | MUL | NO |
| `subtotal` | decimal(12,2) |  | NO |
| `discount_type` | enum('none','percentage','flat') |  | NO |
| `discount_value` | decimal(8,4) |  | NO |
| `discount_amount` | decimal(12,2) |  | NO |
| `subtotal_after_discount` | decimal(12,2) |  | NO |
| `tax_gst_rate` | decimal(6,4) |  | NO |
| `tax_pst_rate` | decimal(6,4) |  | NO |
| `tax_hst_rate` | decimal(6,4) |  | NO |
| `tax_gst_amount` | decimal(12,2) |  | NO |
| `tax_pst_amount` | decimal(12,2) |  | NO |
| `tax_hst_amount` | decimal(12,2) |  | NO |
| `tax_total` | decimal(12,2) |  | NO |
| `total_amount` | decimal(12,2) |  | NO |
| `amount_paid` | decimal(12,2) |  | NO |
| `credits_applied` | decimal(12,2) |  | NO |
| `balance_due` | decimal(12,2) |  | NO |
| `late_fee_applied` | tinyint(1) |  | NO |
| `late_fee_amount` | decimal(10,2) |  | NO |
| `late_fee_date` | date |  | YES |
| `late_fee_invoice_id` | int unsigned | MUL | YES |
| `credit_note_for_invoice_id` | int unsigned | MUL | YES |
| `pdf_path` | varchar(500) |  | YES |
| `pdf_generated_at` | datetime |  | YES |
| `pdf_version` | tinyint unsigned |  | NO |
| `sent_at` | datetime |  | YES |
| `sent_by` | int unsigned |  | YES |
| `sent_to_email` | varchar(255) |  | YES |
| `sent_cc_emails` | json |  | YES |
| `delivery_method` | enum('email','manual','portal') |  | YES |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `void_reason` | text |  | YES |
| `voided_by` | int unsigned | MUL | YES |
| `write_off_reason` | text |  | YES |
| `written_off_by` | int unsigned |  | YES |
| `written_off_at` | datetime |  | YES |
| `auto_generated` | tinyint(1) |  | NO |
| `auto_generated_at` | datetime |  | YES |
| `generation_source` | enum('cron','manual','lease_close','late_fee_cron','advance') |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `updated_by` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |
| `total_days_at_period_end` | int unsigned |  | YES |
| `cumulative_correct_amount` | decimal(12,2) |  | YES |
| `already_billed_before_this` | decimal(12,2) |  | YES |

## `leases`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `contract_number` | varchar(100) | UNI | NO |
| `customer_id` | int unsigned | MUL | YES |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `customer_name_snapshot` | varchar(255) |  | YES |
| `company_name_snapshot` | varchar(255) |  | YES |
| `unit_number_snapshot` | varchar(100) |  | YES |
| `template_name_snapshot` | varchar(100) |  | YES |
| `equipment_snapshot_json` | json |  | YES |
| `start_date` | date | MUL | NO |
| `end_date` | date |  | YES |
| `actual_return_date` | date |  | YES |
| `status` | enum('pending','active','completed','cancelled') | MUL | NO |
| `classification` | enum('operating','sales_type','direct_financing') |  | NO |
| `classification_signed_off_by` | int unsigned | MUL | YES |
| `classification_signed_off_at` | datetime |  | YES |
| `bargain_purchase_option_amount` | decimal(12,2) |  | YES |
| `bargain_purchase_option_date` | date |  | YES |
| `economic_life_months` | int |  | YES |
| `initial_fair_value` | decimal(12,2) |  | YES |
| `initial_direct_costs` | decimal(12,2) |  | NO |
| `guaranteed_residual_value` | decimal(12,2) |  | NO |
| `unguaranteed_residual_value` | decimal(12,2) |  | NO |
| `implicit_rate` | decimal(7,4) |  | YES |
| `daily_rate` | decimal(10,2) |  | NO |
| `weekly_rate` | decimal(10,2) |  | NO |
| `monthly_rate` | decimal(10,2) |  | NO |
| `rate_notes` | text |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate_to_cad` | decimal(10,6) |  | YES |
| `mileage_unit` | enum('km','miles') |  | NO |
| `mileage_rate` | decimal(8,4) |  | NO |
| `mileage_rate_km` | decimal(10,4) |  | YES |
| `mileage_rate_miles` | decimal(10,4) |  | YES |
| `estimated_mileage` | decimal(10,2) |  | NO |
| `estimated_mileage_km` | decimal(12,3) |  | YES |
| `estimated_mileage_miles` | decimal(12,3) |  | YES |
| `km_to_miles_conversion` | decimal(8,6) |  | NO |
| `miles_to_km_conversion` | decimal(8,6) |  | NO |
| `actual_mileage` | decimal(10,2) |  | NO |
| `mileage_at_start` | int unsigned |  | YES |
| `odometer_start_km` | decimal(10,2) |  | YES |
| `odometer_start_source` | enum('gps','manual') |  | YES |
| `odometer_start_fetched_at` | datetime |  | YES |
| `mileage_at_end` | int unsigned |  | YES |
| `gps_mileage_at_start` | int unsigned |  | YES |
| `gps_mileage_at_end` | int unsigned |  | YES |
| `tax_exempt` | tinyint(1) |  | NO |
| `gst_exempt` | tinyint(1) |  | NO |
| `pst_exempt` | tinyint(1) |  | NO |
| `tax_rate_gst` | decimal(6,4) |  | NO |
| `tax_rate_pst` | decimal(6,4) |  | NO |
| `tax_rate_hst` | decimal(6,4) |  | NO |
| `discount_type` | enum('none','percentage','flat') |  | NO |
| `discount_value` | decimal(8,4) |  | NO |
| `insurance_opt_in` | tinyint(1) |  | NO |
| `insurance_cost` | decimal(10,2) |  | NO |
| `warranty_opt_in` | tinyint(1) |  | NO |
| `warranty_cost` | decimal(10,2) |  | NO |
| `billing_cycle` | enum('monthly','on_close_only') |  | NO |
| `advance_billing_periods` | tinyint unsigned |  | NO |
| `po_number` | varchar(100) |  | YES |
| `last_billed_date` | date |  | YES |
| `last_billed_invoice_id` | int unsigned | MUL | YES |
| `next_billing_date` | date | MUL | YES |
| `total_invoiced` | decimal(14,2) |  | NO |
| `total_paid` | decimal(14,2) |  | NO |
| `outstanding_balance` | decimal(12,2) |  | NO |
| `total_estimated_charge` | decimal(12,2) |  | NO |
| `final_total_charge` | decimal(12,2) |  | NO |
| `contract_file` | varchar(500) |  | YES |
| `inspection_in_file` | varchar(500) |  | YES |
| `inspection_out_file` | varchar(500) |  | YES |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `updated_by` | int unsigned | MUL | YES |
| `closed_by_user_id` | int unsigned | MUL | YES |
| `closed_at` | datetime |  | YES |
| `minimum_end_date` | date |  | YES |
| `cancellation_reason` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |
| `odometer_end_km` | decimal(10,2) |  | YES |
| `odometer_end_source` | enum('gps','manual') |  | YES |
| `odometer_end_fetched_at` | datetime |  | YES |
| `total_distance_km` | decimal(10,2) |  | YES |
| `precharge_enabled` | tinyint(1) |  | NO |
| `precharge_amount` | decimal(12,2) |  | YES |
| `precharge_balance` | decimal(12,2) |  | YES |
| `precharge_invoiced_at` | datetime |  | YES |
| `precharge_refund_method` | enum('cash','credit') |  | YES |
| `precharge_refund_settled_at` | datetime |  | YES |
| `gps_opt_in` | tinyint(1) |  | NO |
| `gps_cost` | decimal(10,2) |  | NO |
| `engine_version` | enum('period_independent','holistic') |  | NO |

## `notifications`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned | MUL | YES |
| `rule_id` | int unsigned | MUL | YES |
| `title` | varchar(500) |  | NO |
| `message` | text |  | NO |
| `type` | varchar(100) | MUL | YES |
| `category` | varchar(50) | MUL | YES |
| `url` | varchar(500) |  | YES |
| `entity_type` | varchar(100) |  | YES |
| `entity_id` | int unsigned |  | YES |
| `severity` | enum('info','warning','critical') |  | NO |
| `is_read` | tinyint(1) | MUL | NO |
| `read_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |

## `payments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `payment_number` | varchar(100) | UNI | NO |
| `customer_id` | int unsigned | MUL | YES |
| `amount` | decimal(12,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate_to_cad` | decimal(10,6) |  | YES |
| `currency_markup_pct` | decimal(6,4) |  | NO |
| `amount_in_cad` | decimal(12,2) |  | YES |
| `payment_method` | enum('check','ach','wire','credit_card','cash','e_transfer','account_credit','other') |  | NO |
| `reference_number` | varchar(100) |  | YES |
| `bank_name` | varchar(100) |  | YES |
| `check_number` | varchar(50) |  | YES |
| `card_last_four` | varchar(4) |  | YES |
| `payment_date` | date | MUL | NO |
| `received_at` | datetime |  | YES |
| `deposited_date` | date |  | YES |
| `cleared_date` | date |  | YES |
| `status` | enum('pending','cleared','failed','refunded','void','returned') | MUL | NO |
| `failure_reason` | text |  | YES |
| `returned_reason` | text |  | YES |
| `returned_date` | date |  | YES |
| `overpayment_amount` | decimal(12,2) |  | NO |
| `overpayment_action` | enum('credit_to_account','refund','hold') |  | YES |
| `overpayment_resolved` | tinyint(1) |  | NO |
| `refund_amount` | decimal(12,2) |  | NO |
| `refund_date` | date |  | YES |
| `refund_method` | varchar(100) |  | YES |
| `refund_reference` | varchar(100) |  | YES |
| `refunded_by` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `deleted_at` | datetime | MUL | YES |
| `recorded_by` | int unsigned | MUL | YES |
| `verified_by` | int unsigned | MUL | YES |
| `verified_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `reservations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `status` | enum('pending','confirmed','cancelled','completed') | MUL | NO |
| `customer_id` | int unsigned | MUL | YES |
| `contact_name` | varchar(255) |  | NO |
| `company_name` | varchar(255) |  | NO |
| `contact_phone` | varchar(50) |  | YES |
| `contact_email` | varchar(255) |  | YES |
| `quantity` | tinyint unsigned |  | NO |
| `pickup_date` | date | MUL | NO |
| `pickup_time` | time |  | YES |
| `yard_location` | varchar(100) |  | YES |
| `purpose` | varchar(500) |  | YES |
| `priority` | enum('low','medium','high','urgent') |  | NO |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `updated_by` | int unsigned | MUL | YES |
| `marked_out_at` | datetime |  | YES |
| `marked_out_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |

## `settings`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `key` | varchar(255) | UNI | NO |
| `value` | longtext |  | YES |
| `value_type` | enum('string','integer','decimal','boolean','json','text') |  | NO |
| `group_name` | varchar(100) | MUL | NO |
| `label` | varchar(255) |  | YES |
| `description` | text |  | YES |
| `is_public` | tinyint(1) |  | NO |
| `is_sensitive` | tinyint(1) |  | NO |
| `updated_by` | int unsigned | MUL | YES |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `users`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `email` | varchar(255) | UNI | NO |
| `password_hash` | varchar(255) |  | YES |
| `mfa_enabled` | tinyint(1) |  | NO |
| `mfa_secret` | varchar(500) |  | YES |
| `mfa_enabled_at` | datetime |  | YES |
| `mfa_required` | tinyint(1) |  | NO |
| `auth0_sub` | varchar(255) | UNI | YES |
| `role_id` | int unsigned | MUL | NO |
| `status` | enum('active','inactive','invited','suspended','locked') | MUL | NO |
| `theme_preference` | enum('dark','light') |  | NO |
| `display_font_size` | tinyint unsigned |  | NO |
| `display_density` | enum('compact','comfortable','spacious') |  | NO |
| `avatar_url` | varchar(500) |  | YES |
| `phone` | varchar(50) |  | YES |
| `timezone` | varchar(100) |  | YES |
| `last_login_at` | datetime |  | YES |
| `last_login_ip` | varchar(45) |  | YES |
| `login_attempts` | tinyint unsigned |  | NO |
| `locked_until` | datetime |  | YES |
| `invite_token` | varchar(100) |  | YES |
| `invite_token_expiry` | datetime |  | YES |
| `invite_sent_at` | datetime |  | YES |
| `password_reset_token` | varchar(100) |  | YES |
| `password_reset_expiry` | datetime |  | YES |
| `remember_token` | varchar(100) |  | YES |
| `mfa_verified_until` | datetime |  | YES |
| `permissions_updated_at` | timestamp |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |

## `vendors`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) | MUL | NO |
| `is_related_party` | tinyint(1) |  | NO |
| `vendor_type` | enum('maintenance','repair','parts','inspection','towing','other') |  | NO |
| `contact_name` | varchar(255) |  | YES |
| `email` | varchar(255) |  | YES |
| `phone` | varchar(50) |  | YES |
| `address` | varchar(500) |  | YES |
| `city` | varchar(100) |  | YES |
| `state` | varchar(100) |  | YES |
| `specializations` | json |  | YES |
| `hourly_rate` | decimal(10,2) |  | YES |
| `rating` | tinyint unsigned |  | YES |
| `notes` | text |  | YES |
| `is_preferred` | tinyint(1) |  | NO |
| `total_spent` | decimal(14,2) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `yards`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) | UNI | NO |
| `slug` | varchar(100) | UNI | NO |
| `address` | varchar(500) |  | YES |
| `city` | varchar(100) |  | YES |
| `state` | varchar(100) |  | YES |
| `postal_code` | varchar(20) |  | YES |
| `lat` | decimal(10,7) |  | YES |
| `lng` | decimal(10,7) |  | YES |
| `capacity` | smallint unsigned |  | YES |
| `manager_id` | int unsigned | MUL | YES |
| `phone` | varchar(50) |  | YES |
| `notes` | text |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

# Accounting (`acc_*`) tables

_52 tables._

## `acc_accounts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `code` | varchar(20) | UNI | NO |
| `name` | varchar(255) |  | NO |
| `description` | text |  | YES |
| `account_type` | enum('asset','liability','equity','revenue','cost_of_revenue','operating_expense','other_income','other_expense') | MUL | NO |
| `lead_schedule_code` | varchar(10) |  | YES |
| `account_subtype` | varchar(100) |  | YES |
| `parent_id` | int unsigned | MUL | YES |
| `is_header` | tinyint(1) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `normal_balance` | enum('debit','credit') |  | NO |
| `is_system` | tinyint(1) |  | NO |
| `is_active` | tinyint(1) | MUL | NO |
| `is_bank_account` | tinyint(1) |  | NO |
| `is_fx_monetary` | tinyint(1) |  | NO |
| `tax_line_code` | varchar(50) |  | YES |
| `coa_group` | varchar(100) |  | YES |
| `sort_order` | smallint unsigned |  | NO |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_ai_suggestions`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `suggestion_type` | enum('categorization','anomaly','forecast','optimization','capitalization','depreciation','collection','reconciliation') | MUL | NO |
| `entity_type` | varchar(100) | MUL | YES |
| `entity_id` | int unsigned |  | YES |
| `title` | varchar(255) |  | NO |
| `description` | text |  | NO |
| `suggested_action` | text |  | YES |
| `confidence` | decimal(3,2) |  | NO |
| `priority` | enum('low','medium','high','critical') | MUL | NO |
| `status` | enum('pending','accepted','dismissed','expired') | MUL | NO |
| `accepted_by` | int unsigned | MUL | YES |
| `accepted_at` | datetime |  | YES |
| `dismissed_by` | int unsigned | MUL | YES |
| `dismissed_at` | datetime |  | YES |
| `metadata` | json |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_ap_payment_allocations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `ap_payment_id` | int unsigned | MUL | NO |
| `bill_id` | int unsigned | MUL | NO |
| `amount_applied` | decimal(15,2) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_ap_payments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `payment_number` | varchar(100) | UNI | NO |
| `vendor_id` | int unsigned | MUL | NO |
| `bank_account_id` | int unsigned | MUL | NO |
| `payment_date` | date | MUL | NO |
| `payment_method` | enum('check','eft','wire','credit_card','cash','other') |  | NO |
| `reference_number` | varchar(100) |  | YES |
| `check_number` | varchar(50) |  | YES |
| `amount` | decimal(15,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate_to_cad` | decimal(10,6) |  | YES |
| `status` | enum('pending','cleared','void') |  | NO |
| `void_reason` | text |  | YES |
| `voided_by` | int unsigned | MUL | YES |
| `voided_at` | datetime |  | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_asset_disposals`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `asset_id` | int unsigned | MUL | NO |
| `disposal_date` | date |  | NO |
| `disposal_type` | enum('sale','scrap','trade_in','write_off','other') |  | NO |
| `proceeds` | decimal(15,2) |  | NO |
| `net_book_value_at_disposal` | decimal(15,2) |  | NO |
| `gain_loss` | decimal(15,2) |  | NO |
| `proceeds_account_id` | int unsigned | MUL | YES |
| `gain_loss_account_id` | int unsigned | MUL | NO |
| `journal_entry_id` | int unsigned | MUL | YES |
| `buyer_name` | varchar(255) |  | YES |
| `buyer_reference` | varchar(255) |  | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_asset_impairments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `asset_id` | int unsigned | MUL | NO |
| `impairment_date` | date |  | NO |
| `pre_impairment_nbv` | decimal(15,2) |  | NO |
| `recoverable_amount` | decimal(15,2) |  | NO |
| `impairment_loss` | decimal(15,2) |  | NO |
| `reason` | text |  | NO |
| `journal_entry_id` | int unsigned | MUL | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_auto_categorization_rules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `rule_type` | enum('vendor_match','description_match','amount_range') | MUL | NO |
| `match_pattern` | varchar(500) |  | NO |
| `account_id` | int unsigned | MUL | NO |
| `confidence` | decimal(3,2) |  | NO |
| `match_count` | int unsigned |  | NO |
| `last_matched_at` | datetime |  | YES |
| `is_active` | tinyint(1) | MUL | NO |
| `created_from` | enum('manual','learned','ai_suggested') |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_bad_debt_writeoffs`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `invoice_id` | int unsigned | MUL | NO |
| `customer_id` | int unsigned | MUL | NO |
| `writeoff_date` | date |  | NO |
| `amount` | decimal(15,2) |  | NO |
| `reason` | text |  | NO |
| `journal_entry_id` | int unsigned | MUL | YES |
| `recovered` | tinyint(1) |  | NO |
| `recovered_amount` | decimal(15,2) |  | YES |
| `recovered_date` | date |  | YES |
| `recovery_journal_entry_id` | int unsigned | MUL | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_bank_accounts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `account_number_last4` | varchar(4) |  | YES |
| `routing_number` | varchar(20) |  | YES |
| `institution` | varchar(255) |  | YES |
| `account_type` | enum('checking','savings','line_of_credit','credit_card') |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `gl_account_id` | int unsigned | MUL | NO |
| `opening_balance` | decimal(15,2) |  | NO |
| `opening_balance_date` | date |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `is_default` | tinyint(1) |  | NO |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_bank_reconciliations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `bank_account_id` | int unsigned | MUL | NO |
| `period_id` | int unsigned | MUL | NO |
| `statement_date` | date |  | NO |
| `statement_ending_balance` | decimal(15,2) |  | NO |
| `book_balance` | decimal(15,2) |  | NO |
| `outstanding_deposits` | decimal(15,2) |  | NO |
| `outstanding_checks` | decimal(15,2) |  | NO |
| `adjusted_book_balance` | decimal(15,2) |  | NO |
| `difference` | decimal(15,2) |  | NO |
| `status` | enum('in_progress','completed','locked') | MUL | NO |
| `completed_by` | int unsigned | MUL | YES |
| `completed_at` | datetime |  | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_bank_transactions`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `bank_account_id` | int unsigned | MUL | NO |
| `transaction_date` | date | MUL | NO |
| `description` | varchar(500) |  | NO |
| `reference` | varchar(255) |  | YES |
| `amount` | decimal(15,2) |  | NO |
| `transaction_type` | enum('deposit','withdrawal','transfer','bank_charge','interest','nsf','other') |  | NO |
| `source` | enum('manual','import','system') |  | NO |
| `status` | enum('unmatched','matched','excluded') | MUL | NO |
| `matched_type` | enum('payment','ap_payment','journal_entry','bank_transfer','other') |  | YES |
| `matched_id` | int unsigned |  | YES |
| `matched_at` | datetime |  | YES |
| `matched_by` | int unsigned | MUL | YES |
| `reconciliation_id` | int unsigned | MUL | YES |
| `is_cleared` | tinyint(1) |  | NO |
| `cleared_date` | date |  | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_bill_lines`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `bill_id` | int unsigned | MUL | NO |
| `account_id` | int unsigned | MUL | NO |
| `description` | varchar(500) |  | NO |
| `quantity` | decimal(10,4) |  | NO |
| `unit_cost` | decimal(15,2) |  | NO |
| `amount` | decimal(15,2) |  | NO |
| `is_tax_input_credit` | tinyint(1) |  | NO |
| `tax_gst_amount` | decimal(15,2) |  | NO |
| `tax_pst_amount` | decimal(15,2) |  | NO |
| `tax_hst_amount` | decimal(15,2) |  | NO |
| `capitalize` | tinyint(1) |  | NO |
| `betterment_note` | text |  | YES |
| `asset_id` | int unsigned | MUL | YES |
| `sort_order` | tinyint unsigned |  | NO |
| `is_auto_categorized` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_bills`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `bill_number` | varchar(100) | UNI | NO |
| `vendor_id` | int unsigned | MUL | NO |
| `vendor_bill_number` | varchar(100) |  | YES |
| `bill_date` | date |  | NO |
| `due_date` | date | MUL | NO |
| `period_id` | int unsigned | MUL | NO |
| `status` | enum('draft','approved','scheduled','partially_paid','paid','void') | MUL | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate_to_cad` | decimal(10,6) |  | YES |
| `subtotal` | decimal(15,2) |  | NO |
| `tax_gst_amount` | decimal(15,2) |  | NO |
| `tax_pst_amount` | decimal(15,2) |  | NO |
| `tax_hst_amount` | decimal(15,2) |  | NO |
| `tax_total` | decimal(15,2) |  | NO |
| `total_amount` | decimal(15,2) |  | NO |
| `amount_paid` | decimal(15,2) |  | NO |
| `balance_due` | decimal(15,2) |  | NO |
| `work_order_id` | int unsigned | MUL | YES |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `void_reason` | text |  | YES |
| `voided_by` | int unsigned | MUL | YES |
| `voided_at` | datetime |  | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_budget_lines`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `budget_id` | int unsigned | MUL | NO |
| `account_id` | int unsigned | MUL | NO |
| `jan` | decimal(15,2) |  | NO |
| `feb` | decimal(15,2) |  | NO |
| `mar` | decimal(15,2) |  | NO |
| `apr` | decimal(15,2) |  | NO |
| `may` | decimal(15,2) |  | NO |
| `jun` | decimal(15,2) |  | NO |
| `jul` | decimal(15,2) |  | NO |
| `aug` | decimal(15,2) |  | NO |
| `sep` | decimal(15,2) |  | NO |
| `oct` | decimal(15,2) |  | NO |
| `nov` | decimal(15,2) |  | NO |
| `dec` | decimal(15,2) |  | NO |
| `annual_total` | decimal(15,2) _(STORED GENERATED)_ |  | YES |
| `notes` | varchar(500) |  | YES |

## `acc_budgets`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `year` | smallint unsigned | MUL | NO |
| `version` | enum('base','conservative','optimistic') |  | NO |
| `status` | enum('draft','active','archived') |  | NO |
| `is_active` | tinyint(1) | MUL | NO |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_capex_requests`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `request_number` | varchar(50) | UNI | NO |
| `title` | varchar(255) |  | NO |
| `description` | text |  | YES |
| `asset_class` | enum('fleet_equipment','vehicles','office_equipment','leasehold_improvements','land','building','other') | MUL | NO |
| `budget_amount` | decimal(15,2) |  | NO |
| `actual_amount` | decimal(15,2) |  | YES |
| `status` | enum('draft','pending_approval','approved','rejected','in_progress','completed','cancelled') | MUL | NO |
| `work_order_id` | int unsigned | MUL | YES |
| `asset_id` | int unsigned | MUL | YES |
| `vendor_id` | int unsigned | MUL | YES |
| `requested_by` | int unsigned | MUL | YES |
| `requested_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `approved_by` | int unsigned | MUL | YES |
| `approved_at` | datetime |  | YES |
| `rejected_reason` | text |  | YES |
| `completed_by` | int unsigned | MUL | YES |
| `completed_at` | datetime |  | YES |
| `justification` | text |  | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_categorization_rules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `vendor_id` | int unsigned | MUL | YES |
| `vendor_name_pattern` | varchar(255) |  | YES |
| `description_keywords` | text |  | YES |
| `amount_min` | decimal(15,2) |  | YES |
| `amount_max` | decimal(15,2) |  | YES |
| `vendor_type` | varchar(100) |  | YES |
| `account_id` | int unsigned | MUL | NO |
| `priority` | smallint unsigned | MUL | NO |
| `is_active` | tinyint(1) | MUL | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_cca_classes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `class_number` | varchar(10) | UNI | NO |
| `description` | varchar(255) |  | NO |
| `rate` | decimal(5,4) |  | NO |
| `method` | enum('declining_balance','straight_line') |  | NO |
| `half_year_rule` | tinyint(1) |  | NO |
| `aiip_eligible` | tinyint(1) |  | NO |
| `recapture_applies` | tinyint(1) |  | NO |
| `terminal_loss_applies` | tinyint(1) |  | NO |
| `one_asset_per_class` | tinyint(1) |  | NO |
| `is_active` | tinyint(1) | MUL | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_cca_continuity`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `fiscal_year` | int | MUL | NO |
| `cca_class_id` | int unsigned | MUL | NO |
| `opening_ucc` | decimal(15,2) |  | NO |
| `cost_of_additions` | decimal(15,2) |  | NO |
| `adjustments_transfers` | decimal(15,2) |  | NO |
| `proceeds_of_disposition` | decimal(15,2) |  | NO |
| `ucc_after_additions_dispositions` | decimal(15,2) |  | NO |
| `aiip_adjustment` | decimal(15,2) |  | NO |
| `base_amount_for_cca` | decimal(15,2) |  | NO |
| `half_year_adjustment` | decimal(15,2) |  | NO |
| `cca_claimed` | decimal(15,2) |  | NO |
| `recapture` | decimal(15,2) |  | NO |
| `terminal_loss` | decimal(15,2) |  | NO |
| `closing_ucc` | decimal(15,2) |  | NO |
| `is_locked` | tinyint(1) |  | NO |
| `computed_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `computed_by` | int unsigned | MUL | YES |

## `acc_collection_notes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `invoice_id` | int unsigned | MUL | YES |
| `note_date` | date |  | NO |
| `contact_method` | enum('phone','email','letter','in_person','other') |  | NO |
| `contact_person` | varchar(255) |  | YES |
| `note` | text |  | NO |
| `outcome` | enum('no_answer','left_message','spoke_with_customer','payment_promised','dispute','other') |  | NO |
| `follow_up_date` | date |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_customer_deposits`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `deposit_number` | varchar(100) | UNI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `deposit_type` | enum('security','damage','advance_payment','other') |  | NO |
| `amount` | decimal(15,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `received_date` | date |  | NO |
| `status` | enum('held','applied','refunded','forfeited') | MUL | NO |
| `applied_to_invoice_id` | int unsigned | MUL | YES |
| `applied_date` | date |  | YES |
| `refund_date` | date |  | YES |
| `refund_method` | varchar(100) |  | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `liability_account_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_depreciation_run_lines`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `run_id` | int unsigned | MUL | NO |
| `asset_id` | int unsigned | MUL | NO |
| `period_id` | int unsigned | MUL | NO |
| `opening_nbv` | decimal(15,2) |  | NO |
| `depreciation` | decimal(15,2) |  | NO |
| `closing_nbv` | decimal(15,2) |  | NO |
| `method_used` | enum('straight_line','declining_balance','units_of_production') |  | NO |
| `calculation_detail` | json |  | YES |

## `acc_depreciation_runs`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `period_id` | int unsigned | MUL | NO |
| `run_date` | datetime |  | NO |
| `status` | enum('preview','posted','reversed') | MUL | NO |
| `total_depreciation` | decimal(15,2) |  | NO |
| `asset_count` | smallint unsigned |  | NO |
| `journal_entry_id` | int unsigned | MUL | YES |
| `run_by` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_disclosure_notes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `fiscal_year` | int | MUL | NO |
| `note_number` | int |  | NO |
| `note_title` | varchar(255) |  | NO |
| `note_content` | text |  | NO |
| `is_auto_generated` | tinyint(1) |  | NO |
| `edited_by` | int unsigned | MUL | YES |
| `edited_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_documents`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `entity_type` | enum('journal_entry','bill','ap_payment','bank_transaction','asset','tax_filing','reconciliation','other') | MUL | NO |
| `entity_id` | int unsigned |  | NO |
| `title` | varchar(255) |  | NO |
| `file_path` | varchar(500) |  | NO |
| `file_name` | varchar(255) |  | NO |
| `file_size_kb` | int unsigned |  | YES |
| `mime_type` | varchar(100) |  | YES |
| `notes` | text |  | YES |
| `uploaded_by` | int unsigned | MUL | YES |
| `uploaded_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_dunning_letters`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `letter_type` | enum('reminder_30','reminder_60','warning_90','final_notice') |  | NO |
| `sent_date` | date |  | NO |
| `sent_method` | enum('email','mail','both') |  | NO |
| `sent_to_email` | varchar(255) |  | YES |
| `total_overdue` | decimal(15,2) |  | NO |
| `invoice_count` | tinyint unsigned |  | NO |
| `pdf_path` | varchar(500) |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_fixed_assets`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `parent_asset_id` | int unsigned | MUL | YES |
| `is_component` | tinyint(1) |  | NO |
| `asset_number` | varchar(100) | UNI | NO |
| `name` | varchar(255) |  | NO |
| `description` | text |  | YES |
| `asset_class` | enum('fleet_equipment','vehicles','office_equipment','leasehold_improvements','land','building','other') | MUL | NO |
| `cca_class_id` | int unsigned | MUL | YES |
| `cra_class` | varchar(20) |  | YES |
| `cra_cca_rate` | decimal(5,4) |  | YES |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `acquisition_date` | date |  | NO |
| `available_for_use_date` | date |  | YES |
| `is_aiip_eligible` | tinyint(1) |  | NO |
| `acquisition_cost` | decimal(15,2) |  | NO |
| `purchase_tax_gst` | decimal(12,2) |  | YES |
| `purchase_tax_pst` | decimal(12,2) |  | YES |
| `delivery_cost` | decimal(12,2) |  | YES |
| `setup_cost` | decimal(12,2) |  | YES |
| `is_financed` | tinyint(1) |  | NO |
| `financing_monthly_payment` | decimal(12,2) |  | YES |
| `financing_interest_rate` | decimal(6,4) |  | YES |
| `financing_remaining_months` | smallint unsigned |  | YES |
| `monthly_insurance_cost` | decimal(10,2) |  | YES |
| `monthly_licensing_cost` | decimal(10,2) |  | YES |
| `monthly_registration_cost` | decimal(10,2) |  | YES |
| `acquisition_bill_id` | int unsigned | MUL | YES |
| `vendor_id` | int unsigned | MUL | YES |
| `depreciation_method` | enum('straight_line','declining_balance','units_of_production','none') |  | NO |
| `useful_life_years` | decimal(5,2) |  | YES |
| `salvage_value` | decimal(15,2) |  | NO |
| `depreciable_cost` | decimal(15,2) |  | NO |
| `accumulated_depreciation` | decimal(15,2) |  | NO |
| `net_book_value` | decimal(15,2) |  | NO |
| `last_depreciation_date` | date |  | YES |
| `depreciation_start_date` | date |  | NO |
| `fully_depreciated_date` | date |  | YES |
| `asset_account_id` | int unsigned | MUL | NO |
| `accum_depr_account_id` | int unsigned | MUL | NO |
| `depr_expense_account_id` | int unsigned | MUL | NO |
| `status` | enum('active','fully_depreciated','disposed','impaired') | MUL | NO |
| `total_expected_units` | int unsigned |  | YES |
| `units_used_to_date` | int unsigned |  | NO |
| `location` | varchar(255) |  | YES |
| `serial_number` | varchar(100) |  | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_fx_revaluations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `revaluation_date` | date |  | NO |
| `period_id` | int unsigned | MUL | NO |
| `exchange_rate_used` | decimal(10,6) |  | NO |
| `total_ar_usd` | decimal(15,2) |  | NO |
| `total_ar_cad_book` | decimal(15,2) |  | NO |
| `total_ar_cad_revalued` | decimal(15,2) |  | NO |
| `unrealized_gain_loss` | decimal(15,2) |  | NO |
| `status` | enum('preview','posted','reversed') |  | NO |
| `run_at` | datetime |  | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_impairment_tests`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `asset_id` | int unsigned | MUL | NO |
| `fiscal_year` | int | MUL | NO |
| `triggering_event` | enum('annual','idle','damage','market_decline','adverse_legal','other') |  | NO |
| `triggering_event_notes` | text |  | YES |
| `step_1_carrying_amount` | decimal(15,2) |  | NO |
| `step_1_undiscounted_cf` | decimal(15,2) |  | NO |
| `step_1_cf_source` | enum('estimator','operator_override') |  | NO |
| `step_1_cf_breakdown_json` | json |  | YES |
| `step_1_passed` | tinyint(1) |  | NO |
| `step_2_fair_value` | decimal(15,2) |  | YES |
| `step_2_impairment_loss` | decimal(15,2) |  | YES |
| `step_2_fair_value_basis` | text |  | YES |
| `impairment_je_id` | int unsigned | MUL | YES |
| `tested_by` | int unsigned | MUL | NO |
| `tested_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `notes` | text |  | YES |

## `acc_journal_entries`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `entry_number` | varchar(50) | UNI | NO |
| `period_id` | int unsigned | MUL | NO |
| `entry_date` | date | MUL | NO |
| `entry_type` | enum('manual','system','recurring','reversing','year_end','adjustment','adjusting','reclassifying','closing','prior_period') | MUL | NO |
| `status` | enum('draft','posted','reversed') | MUL | NO |
| `entry_status` | enum('draft','submitted','approved','posted','reversed') |  | NO |
| `submitted_by_id` | int unsigned | MUL | YES |
| `submitted_at` | datetime |  | YES |
| `approved_by_id` | int unsigned | MUL | YES |
| `approved_at` | datetime |  | YES |
| `description` | varchar(500) |  | NO |
| `reference` | varchar(255) |  | YES |
| `source_type` | enum('invoice','payment','credit_note','ap_bill','ap_payment','bank_transaction','depreciation','asset_disposal','tax_remittance','fx_revaluation','manual','year_end','recurring','damage_recovery','damage_repair','damage_writeoff','lease_inception','lease_period','lease_termination','lease_ni_reclass','lease_residual_impairment') | MUL | YES |
| `source_id` | int unsigned |  | YES |
| `is_reversal` | tinyint(1) |  | NO |
| `reversal_of_id` | int unsigned | MUL | YES |
| `reversed_by_id` | int unsigned | MUL | YES |
| `reversal_date` | date |  | YES |
| `auto_reverse` | tinyint(1) |  | NO |
| `auto_reverse_date` | date |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `exchange_rate` | decimal(10,6) |  | YES |
| `posted_by` | int unsigned | MUL | YES |
| `posted_at` | datetime |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_journal_entry_lines`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `journal_entry_id` | int unsigned | MUL | NO |
| `account_id` | int unsigned | MUL | NO |
| `line_number` | tinyint unsigned |  | NO |
| `description` | varchar(500) |  | YES |
| `debit` | decimal(15,2) |  | NO |
| `credit` | decimal(15,2) |  | NO |
| `foreign_amount` | decimal(15,2) |  | YES |
| `foreign_currency` | enum('CAD','USD') |  | YES |
| `exchange_rate` | decimal(10,6) |  | YES |
| `customer_id` | int unsigned | MUL | YES |
| `vendor_id` | int unsigned | MUL | YES |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_lease_amortization_schedules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `period_number` | int |  | NO |
| `period_date` | date | MUL | NO |
| `opening_net_investment` | decimal(15,2) |  | NO |
| `cash_receipt` | decimal(12,2) |  | NO |
| `finance_income` | decimal(12,2) |  | NO |
| `principal_reduction` | decimal(12,2) |  | NO |
| `closing_net_investment` | decimal(15,2) |  | NO |
| `posted_je_id` | int unsigned | MUL | YES |
| `status` | enum('scheduled','posted','reversed') |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_lease_classifications`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | UNI | NO |
| `criterion_a_met` | tinyint(1) |  | NO |
| `criterion_a_notes` | text |  | YES |
| `criterion_b_met` | tinyint(1) |  | NO |
| `criterion_b_lease_term_months` | int |  | YES |
| `criterion_b_economic_life_months` | int |  | YES |
| `criterion_b_ratio` | decimal(5,4) |  | YES |
| `criterion_c_met` | tinyint(1) |  | NO |
| `criterion_c_pv_mlp` | decimal(15,2) |  | YES |
| `criterion_c_fair_value` | decimal(15,2) |  | YES |
| `criterion_c_ratio` | decimal(5,4) |  | YES |
| `credit_risk_normal` | tinyint(1) |  | NO |
| `costs_estimable` | tinyint(1) |  | NO |
| `any_criterion_met` | tinyint(1) |  | NO |
| `all_conditions_met` | tinyint(1) |  | NO |
| `determined_classification` | enum('operating','sales_type','direct_financing') |  | NO |
| `classification_rationale` | text |  | YES |
| `wizard_completed_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `wizard_completed_by` | int unsigned | MUL | NO |

## `acc_lease_residual_reviews`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `fiscal_year` | int |  | NO |
| `prior_residual_value` | decimal(12,2) |  | NO |
| `revised_residual_value` | decimal(12,2) |  | NO |
| `delta` | decimal(12,2) _(STORED GENERATED)_ |  | YES |
| `impairment_je_id` | int unsigned | MUL | YES |
| `schedule_regenerated` | tinyint(1) |  | NO |
| `notes` | text |  | YES |
| `reviewed_by` | int unsigned | MUL | NO |
| `reviewed_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_oauth_states`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `state_token` | varchar(128) | UNI | NO |
| `provider` | enum('quickbooks') | MUL | NO |
| `initiated_by_user_id` | int unsigned | MUL | YES |
| `initiated_ip` | varchar(45) |  | YES |
| `initiated_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `expires_at` | datetime | MUL | NO |
| `used_at` | datetime |  | YES |
| `consumed_ip` | varchar(45) |  | YES |

## `acc_periods`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `year` | smallint unsigned | MUL | NO |
| `month` | tinyint unsigned |  | NO |
| `name` | varchar(50) |  | NO |
| `start_date` | date |  | NO |
| `end_date` | date |  | NO |
| `status` | enum('open','closed','locked') | MUL | NO |
| `closed_by` | int unsigned | MUL | YES |
| `closed_at` | datetime |  | YES |
| `locked_by` | int unsigned | MUL | YES |
| `locked_at` | datetime |  | YES |
| `is_year_end` | tinyint(1) |  | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_place_of_supply_rules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `rule_type` | enum('goods_delivered','short_lease','long_lease','service','specified_motor_vehicle') | MUL | NO |
| `province_code` | varchar(3) | MUL | NO |
| `applicable_tax_rate_ids` | json |  | NO |
| `priority` | tinyint unsigned |  | NO |
| `notes` | text |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_promise_to_pay`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `invoice_id` | int unsigned | MUL | YES |
| `promised_amount` | decimal(15,2) |  | NO |
| `promise_date` | date | MUL | NO |
| `promised_by` | varchar(255) |  | YES |
| `status` | enum('pending','kept','broken','cancelled') | MUL | NO |
| `actual_payment_date` | date |  | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_qbo_customer_map`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `ff_customer_id` | int unsigned | UNI | YES |
| `qbo_customer_id` | varchar(50) | UNI | YES |
| `qbo_sync_token` | varchar(20) |  | YES |
| `qbo_display_name` | varchar(255) |  | YES |
| `qbo_company_name` | varchar(255) |  | YES |
| `qbo_email` | varchar(255) |  | YES |
| `qbo_phone` | varchar(50) |  | YES |
| `qbo_active` | tinyint(1) |  | YES |
| `qbo_balance` | decimal(15,2) |  | YES |
| `mapping_status` | enum('mapped','ff_only','qbo_only','ignored') | MUL | NO |
| `match_confidence` | enum('exact','high','medium','low','manual') |  | YES |
| `match_notes` | text |  | YES |
| `last_synced_at` | datetime | MUL | YES |
| `last_pull_at` | datetime |  | YES |
| `last_push_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `created_by_user_id` | int unsigned | MUL | YES |

## `acc_qbo_drift_events`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `detected_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `detection_source` | enum('drift_cron','push_failure','pull_failure','manual') |  | NO |
| `category` | enum('count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved') | MUL | NO |
| `entity_type` | varchar(50) | MUL | NO |
| `entity_id` | int unsigned |  | YES |
| `qbo_entity_id` | varchar(50) |  | YES |
| `ff_value` | text |  | YES |
| `qbo_value` | text |  | YES |
| `drift_amount` | decimal(15,2) |  | YES |
| `description` | text |  | YES |
| `queue_id` | int unsigned | MUL | YES |
| `resolved_at` | datetime | MUL | YES |
| `resolved_by_user_id` | int unsigned | MUL | YES |
| `resolution_note` | text |  | YES |
| `realm_id` | varchar(50) |  | NO |
| `environment` | enum('sandbox','production') |  | NO |

## `acc_qbo_sync_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `direction` | enum('push','pull') |  | NO |
| `entity_type` | varchar(50) | MUL | NO |
| `entity_id` | int unsigned |  | YES |
| `qbo_entity_id` | varchar(50) |  | YES |
| `operation` | varchar(20) |  | NO |
| `http_method` | varchar(10) |  | NO |
| `endpoint` | varchar(255) |  | NO |
| `request_payload` | json |  | YES |
| `response_status` | int |  | YES |
| `response_payload` | json |  | YES |
| `duration_ms` | int |  | YES |
| `error_code` | varchar(50) | MUL | YES |
| `error_message` | text |  | YES |
| `user_id` | int unsigned | MUL | YES |
| `queue_id` | int unsigned | MUL | YES |
| `realm_id` | varchar(50) |  | NO |
| `environment` | enum('sandbox','production') |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `acc_qbo_sync_queue`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `entity_type` | enum('customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code') | MUL | NO |
| `entity_id` | int unsigned |  | NO |
| `operation` | enum('create','update','void','delete') |  | NO |
| `status` | enum('queued','processing','completed','failed','skipped') | MUL | NO |
| `priority` | tinyint |  | NO |
| `retry_count` | tinyint |  | NO |
| `max_retries` | tinyint |  | NO |
| `next_retry_at` | datetime |  | YES |
| `error_message` | text |  | YES |
| `error_code` | varchar(50) |  | YES |
| `enqueued_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `picked_up_at` | datetime |  | YES |
| `completed_at` | datetime |  | YES |
| `worker_id` | varchar(50) |  | YES |
| `payload_snapshot` | json |  | YES |

## `acc_recurring_entries`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `description` | varchar(500) |  | YES |
| `frequency` | enum('monthly','quarterly','annually') |  | NO |
| `day_of_month` | tinyint unsigned |  | NO |
| `start_date` | date |  | NO |
| `end_date` | date |  | YES |
| `next_post_date` | date | MUL | NO |
| `last_posted_date` | date |  | YES |
| `is_active` | tinyint(1) | MUL | NO |
| `auto_post` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_recurring_entry_lines`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `recurring_entry_id` | int unsigned | MUL | NO |
| `account_id` | int unsigned | MUL | NO |
| `line_number` | tinyint unsigned |  | NO |
| `description` | varchar(500) |  | YES |
| `debit` | decimal(15,2) |  | NO |
| `credit` | decimal(15,2) |  | NO |

## `acc_report_configurations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `report_type` | varchar(100) | MUL | NO |
| `name` | varchar(255) |  | NO |
| `parameters` | json |  | NO |
| `is_pinned` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_tax_filing_periods`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `tax_type` | enum('gst_hst','pst_bc','pst_sk','pst_mb') | MUL | NO |
| `period_start` | date |  | NO |
| `period_end` | date |  | NO |
| `filing_due_date` | date |  | NO |
| `frequency` | enum('monthly','quarterly','annually') |  | NO |
| `total_sales` | decimal(15,2) |  | NO |
| `total_tax_collected` | decimal(15,2) |  | NO |
| `total_itc` | decimal(15,2) |  | NO |
| `net_tax_owing` | decimal(15,2) |  | NO |
| `status` | enum('open','calculated','filed','remitted') | MUL | NO |
| `filed_date` | date |  | YES |
| `filed_by` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_tax_remittances`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `filing_period_id` | int unsigned | MUL | NO |
| `remittance_date` | date |  | NO |
| `amount` | decimal(15,2) |  | NO |
| `payment_method` | enum('online_banking','check','wire','other') |  | NO |
| `reference_number` | varchar(100) |  | YES |
| `bank_account_id` | int unsigned | MUL | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `acc_vendor_credit_applications`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `vendor_credit_id` | int unsigned | MUL | NO |
| `bill_id` | int unsigned | MUL | NO |
| `amount_applied` | decimal(15,2) |  | NO |
| `applied_by` | int unsigned | MUL | YES |
| `applied_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_vendor_credits`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `credit_number` | varchar(100) | UNI | NO |
| `vendor_id` | int unsigned | MUL | NO |
| `credit_date` | date |  | NO |
| `reason` | varchar(500) |  | NO |
| `amount` | decimal(15,2) |  | NO |
| `amount_remaining` | decimal(15,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `status` | enum('active','partially_used','fully_used','void') | MUL | NO |
| `source_bill_id` | int unsigned | MUL | YES |
| `journal_entry_id` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_workpaper_annotations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `workpaper_type` | enum('trial_balance','lead_schedule','report') | MUL | NO |
| `workpaper_ref` | varchar(50) |  | NO |
| `period_id` | int unsigned | MUL | NO |
| `account_id` | int unsigned | MUL | YES |
| `tickmark` | varchar(8) |  | YES |
| `note` | text |  | YES |
| `created_by` | int unsigned | MUL | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `acc_year_end_checklist`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `year` | smallint unsigned | MUL | NO |
| `item_key` | varchar(100) |  | NO |
| `item_label` | varchar(500) |  | NO |
| `is_complete` | tinyint(1) |  | NO |
| `completed_by` | int unsigned | MUL | YES |
| `completed_at` | datetime |  | YES |
| `notes` | text |  | YES |
| `sort_order` | tinyint unsigned |  | NO |

## `acc_year_end_closures`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `fiscal_year` | smallint unsigned | UNI | NO |
| `closed_at` | datetime |  | NO |
| `closed_by` | int unsigned | MUL | YES |
| `closing_je_id` | int unsigned | MUL | YES |
| `package_path` | varchar(500) |  | YES |
| `package_hash` | varchar(64) |  | YES |
| `status` | enum('closed','reversed') | MUL | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

# Other tables

_70 tables._

## `ai_anomaly_alerts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `alert_type` | varchar(50) | MUL | NO |
| `severity` | enum('low','medium','high') | MUL | NO |
| `title` | varchar(255) |  | NO |
| `description` | text |  | NO |
| `entity_type` | varchar(50) | MUL | NO |
| `entity_id` | int unsigned |  | NO |
| `data_snapshot` | json |  | YES |
| `generated_by` | int unsigned |  | YES |
| `acknowledged_at` | datetime | MUL | YES |
| `acknowledged_by` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `ai_chat_messages`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `session_id` | int unsigned | MUL | NO |
| `role` | enum('user','assistant','system') |  | NO |
| `content` | longtext |  | NO |
| `tokens_used` | int unsigned |  | YES |
| `chart_data` | json |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `ai_chat_sessions`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `session_title` | varchar(255) |  | YES |
| `context_type` | varchar(100) |  | YES |
| `context_id` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `last_message_at` | datetime |  | YES |

## `ai_query_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | YES |
| `query_type` | varchar(100) |  | NO |
| `prompt_tokens` | int unsigned |  | YES |
| `completion_tokens` | int unsigned |  | YES |
| `total_tokens` | int unsigned |  | YES |
| `cost_usd` | decimal(8,6) |  | YES |
| `latency_ms` | int unsigned |  | YES |
| `was_cached` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `ai_summaries`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `entity_type` | varchar(100) | MUL | NO |
| `entity_id` | int unsigned |  | NO |
| `summary_type` | enum('lease_summary','customer_insights','fleet_health','unit_analysis','payment_risk','forecast','anomaly','accounting_overview','pl_narrative','bs_narrative','cashflow_narrative','budget_variance') |  | NO |
| `content` | longtext |  | NO |
| `tokens_used` | int unsigned |  | YES |
| `model_used` | varchar(100) |  | YES |
| `generated_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `expires_at` | datetime | MUL | YES |
| `generated_by` | int unsigned | MUL | YES |
| `is_current` | tinyint(1) |  | NO |

## `audit_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | YES |
| `user_name` | varchar(255) |  | NO |
| `action` | enum('create','update','delete','restore','login','logout','export','status_change','view','bulk_action','payment_recorded','invoice_sent','invoice_voided','lease_closed','cron') | MUL | NO |
| `module` | varchar(100) | MUL | NO |
| `entity_type` | varchar(100) | MUL | NO |
| `entity_id` | int unsigned |  | YES |
| `entity_label` | varchar(255) |  | YES |
| `old_values` | json |  | YES |
| `new_values` | json |  | YES |
| `ip_address` | varchar(45) |  | YES |
| `user_agent` | varchar(500) |  | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `audit_log_archive`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | YES |
| `user_name` | varchar(255) |  | NO |
| `action` | enum('create','update','delete','restore','login','logout','export','status_change','view','bulk_action','payment_recorded','invoice_sent','invoice_voided','lease_closed','cron') | MUL | NO |
| `module` | varchar(100) | MUL | NO |
| `entity_type` | varchar(100) | MUL | NO |
| `entity_id` | int unsigned |  | YES |
| `entity_label` | varchar(255) |  | YES |
| `old_values` | json |  | YES |
| `new_values` | json |  | YES |
| `ip_address` | varchar(45) |  | YES |
| `user_agent` | varchar(500) |  | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `chat_attachments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `message_id` | int unsigned | MUL | NO |
| `attachment_type` | enum('invoice','lease','customer','payment','work_order','damage_claim','document','reservation','equipment','file','image') |  | NO |
| `entity_id` | int unsigned |  | YES |
| `file_path` | varchar(500) |  | YES |
| `file_name` | varchar(255) |  | YES |
| `file_size` | int unsigned |  | YES |
| `mime_type` | varchar(100) |  | YES |
| `preview_title` | varchar(255) |  | YES |
| `preview_subtitle` | varchar(255) |  | YES |
| `preview_badge` | varchar(100) |  | YES |
| `preview_badge_class` | varchar(50) |  | YES |
| `preview_url` | varchar(500) |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `chat_channel_members`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `channel_id` | int unsigned | MUL | NO |
| `user_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned | MUL | YES |
| `role` | enum('owner','admin','member') |  | NO |
| `last_read_at` | datetime |  | YES |
| `last_read_message_id` | int unsigned |  | YES |
| `is_muted` | tinyint(1) |  | NO |
| `joined_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `chat_channels`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(100) |  | NO |
| `slug` | varchar(100) | UNI | NO |
| `description` | text |  | YES |
| `type` | enum('channel','direct','customer') | MUL | NO |
| `is_private` | tinyint(1) |  | NO |
| `is_archived` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | NO |
| `customer_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned |  | YES |
| `last_message_at` | datetime | MUL | YES |
| `last_message_preview` | varchar(255) |  | YES |
| `unread_count_cache` | int unsigned |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `chat_messages`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `channel_id` | int unsigned | MUL | NO |
| `user_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned |  | YES |
| `sender_display_name` | varchar(255) |  | YES |
| `message` | text |  | YES |
| `type` | enum('text','system','attachment','file') |  | NO |
| `is_edited` | tinyint(1) |  | NO |
| `edited_at` | datetime |  | YES |
| `is_deleted` | tinyint(1) |  | NO |
| `deleted_at` | datetime |  | YES |
| `reply_to_id` | int unsigned | MUL | YES |
| `mentions` | json |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `chat_reactions`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `message_id` | int unsigned | MUL | NO |
| `user_id` | int unsigned |  | YES |
| `portal_user_id` | int unsigned |  | YES |
| `emoji` | varchar(20) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `contract_templates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `description` | text |  | YES |
| `content` | longtext |  | NO |
| `variables_used` | json |  | YES |
| `is_default` | tinyint(1) |  | NO |
| `is_active` | tinyint(1) |  | NO |
| `version` | tinyint unsigned |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `credit_note_applications`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `credit_note_id` | int unsigned | MUL | NO |
| `invoice_id` | int unsigned | MUL | NO |
| `amount_applied` | decimal(12,2) |  | NO |
| `applied_by` | int unsigned | MUL | YES |
| `applied_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `credit_notes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `credit_note_number` | varchar(100) | UNI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `source` | enum('mileage_overpayment','invoice_adjustment','damage_resolution','goodwill','payment_returned','overpayment','other','precharge_refund','base_rental_reconciliation_overflow') |  | NO |
| `source_invoice_id` | int unsigned | MUL | YES |
| `source_payment_id` | int unsigned | MUL | YES |
| `amount` | decimal(12,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `amount_remaining` | decimal(12,2) |  | NO |
| `status` | enum('active','partially_used','fully_used','expired','void') | MUL | NO |
| `expires_at` | date |  | YES |
| `reason` | text |  | NO |
| `internal_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `voided_by` | int unsigned | MUL | YES |
| `voided_at` | datetime |  | YES |
| `deleted_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `customer_contacts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `name` | varchar(255) |  | NO |
| `title` | varchar(100) |  | YES |
| `email` | varchar(255) |  | YES |
| `phone` | varchar(50) |  | YES |
| `is_primary` | tinyint(1) |  | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `customer_discounts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `equipment_type` | varchar(255) |  | YES |
| `discount_type` | enum('percentage','flat') |  | NO |
| `discount_value` | decimal(8,4) |  | NO |
| `reason` | varchar(500) |  | NO |
| `valid_from` | date |  | NO |
| `valid_to` | date |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `approved_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `customer_equipment_rates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `equipment_type` | varchar(255) |  | NO |
| `daily_rate` | decimal(10,2) |  | YES |
| `weekly_rate` | decimal(10,2) |  | YES |
| `monthly_rate` | decimal(10,2) |  | YES |
| `mileage_rate` | decimal(8,4) |  | YES |
| `mileage_unit` | enum('km','miles') |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `minimum_charge` | decimal(10,2) |  | YES |
| `notes` | text |  | YES |
| `effective_from` | date _(DEFAULT_GENERATED)_ |  | NO |
| `effective_to` | date |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `customer_notes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `note` | text |  | NO |
| `is_pinned` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `customer_rate_history`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `equipment_type` | varchar(255) |  | NO |
| `lease_id` | int unsigned | MUL | YES |
| `daily_rate` | decimal(10,2) |  | YES |
| `weekly_rate` | decimal(10,2) |  | YES |
| `monthly_rate` | decimal(10,2) |  | YES |
| `mileage_rate` | decimal(8,4) |  | YES |
| `mileage_unit` | enum('km','miles') |  | YES |
| `currency` | enum('CAD','USD') |  | NO |
| `change_type` | enum('created','updated','deleted','used_in_lease','override') |  | NO |
| `change_source` | enum('manual','lease_creation','bulk_update','system','import') |  | NO |
| `change_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `customer_tags`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `tag` | enum('vip','preferred','owner-operator','fleet','net-30','net-45','net-60','cod','tax-exempt','high-risk','watchlist','credit-hold','delinquent','new','seasonal','government','broker') |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `damage_claim_photos`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `claim_id` | int unsigned | MUL | NO |
| `photo_type` | enum('damage','repair_before','repair_after','other') |  | NO |
| `file_path` | varchar(500) |  | NO |
| `caption` | varchar(255) |  | YES |
| `uploaded_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `damage_claims`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `claim_number` | varchar(100) | UNI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `customer_id` | int unsigned | MUL | YES |
| `customer_name` | varchar(255) |  | YES |
| `inspection_id` | int unsigned | MUL | YES |
| `work_order_id` | int unsigned | MUL | YES |
| `invoice_id` | int unsigned | MUL | YES |
| `vendor_id` | int unsigned | MUL | YES |
| `description` | text |  | NO |
| `damage_location` | varchar(255) |  | YES |
| `severity` | enum('minor','moderate','major','total_loss') |  | NO |
| `estimated_repair_cost` | decimal(12,2) |  | YES |
| `actual_repair_cost` | decimal(12,2) |  | YES |
| `customer_liable_amount` | decimal(12,2) |  | YES |
| `insurance_claim_amount` | decimal(12,2) |  | YES |
| `status` | enum('reported','assessed','repair_ordered','invoiced','resolved','written_off') | MUL | NO |
| `notes` | text |  | YES |
| `resolution_notes` | text |  | YES |
| `reported_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `email_attachments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `email_log_id` | int unsigned | MUL | NO |
| `file_name` | varchar(255) |  | NO |
| `file_path` | varchar(500) |  | NO |
| `file_size` | int unsigned |  | YES |
| `mime_type` | varchar(100) |  | YES |
| `source_type` | enum('document','invoice_pdf','report','upload','generated') |  | NO |
| `source_id` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `email_bounces`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | YES |
| `user_id` | int unsigned |  | YES |
| `recipient_email` | varchar(320) | MUL | NO |
| `bounce_type` | enum('Permanent','Transient','Complaint','Undetermined') | MUL | NO |
| `bounce_subtype` | varchar(100) |  | NO |
| `diagnostic_code` | text |  | YES |
| `action_taken` | varchar(100) |  | NO |
| `raw_payload` | json |  | YES |
| `processed_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `email_logs`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `to_email` | varchar(255) | MUL | NO |
| `to_name` | varchar(255) |  | YES |
| `from_email` | varchar(255) |  | NO |
| `from_name` | varchar(255) |  | YES |
| `reply_to` | varchar(255) |  | YES |
| `subject` | varchar(500) |  | NO |
| `body_html` | mediumtext |  | NO |
| `body_text` | mediumtext |  | YES |
| `status` | enum('sent','failed','pending') | MUL | NO |
| `error_message` | text |  | YES |
| `sent_at` | datetime | MUL | YES |
| `customer_id` | int unsigned | MUL | YES |
| `entity_type` | varchar(50) | MUL | YES |
| `entity_id` | int unsigned |  | YES |
| `template_id` | int unsigned | MUL | YES |
| `attachments` | json |  | YES |
| `sent_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `email_templates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `slug` | varchar(100) | UNI | NO |
| `subject` | varchar(500) |  | NO |
| `body_html` | text |  | NO |
| `body_text` | text |  | NO |
| `category` | enum('invoice','payment','lease','reminder','compliance','general','collection') | MUL | NO |
| `variables` | json |  | YES |
| `is_active` | tinyint(1) | MUL | NO |
| `deleted_at` | datetime |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `equipment_status_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `reservation_id` | int unsigned | MUL | YES |
| `old_status` | varchar(50) |  | NO |
| `new_status` | varchar(50) |  | NO |
| `reason` | text |  | YES |
| `changed_by` | varchar(255) |  | NO |
| `changed_by_user_id` | int unsigned | MUL | YES |
| `changed_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `equipment_templates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(100) |  | NO |
| `slug` | varchar(100) | UNI | NO |
| `description` | text |  | YES |
| `category` | enum('chassis','dry_van','reefer','container','flatbed','step_deck','lowboy','tanker','dump','other') |  | NO |
| `brand` | varchar(100) |  | YES |
| `model` | varchar(100) |  | YES |
| `default_length_ft` | decimal(6,2) |  | YES |
| `default_height_ft` | decimal(6,2) |  | YES |
| `default_width_ft` | decimal(6,2) |  | YES |
| `default_weight_capacity_lbs` | int unsigned |  | YES |
| `default_wheel_size` | varchar(50) |  | YES |
| `default_tire_size` | varchar(50) |  | YES |
| `default_axle_count` | tinyint unsigned |  | YES |
| `default_ownership_type` | enum('owned','leased','brokered') |  | YES |
| `default_yard_location` | varchar(100) |  | YES |
| `default_tracking_provider` | enum('samsara','none') |  | YES |
| `default_cvi_interval_days` | smallint unsigned |  | YES |
| `default_mvi_interval_days` | smallint unsigned |  | YES |
| `default_registration_interval_days` | smallint unsigned |  | YES |
| `default_insurance_interval_days` | smallint unsigned |  | YES |
| `default_daily_rate` | decimal(10,2) |  | YES |
| `default_weekly_rate` | decimal(10,2) |  | YES |
| `default_monthly_rate` | decimal(10,2) |  | YES |
| `default_mileage_rate` | decimal(10,4) |  | NO |
| `default_currency` | enum('CAD','USD') |  | NO |
| `default_mileage_unit` | enum('km','miles') |  | NO |
| `default_notes` | text |  | YES |
| `default_inspection_notes` | text |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `sort_order` | smallint unsigned |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `equipment_units`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `template_id` | int unsigned | MUL | NO |
| `unit_number` | varchar(100) | UNI | NO |
| `vin` | varchar(50) | UNI | YES |
| `year` | smallint unsigned |  | YES |
| `gps_device_id` | varchar(100) |  | YES |
| `samsara_vehicle_url` | varchar(500) |  | YES |
| `tracking_provider` | enum('samsara','none') |  | NO |
| `ownership_type` | enum('owned','leased','brokered') |  | NO |
| `owner_company_id` | int unsigned | MUL | YES |
| `yard_location` | varchar(100) | MUL | YES |
| `length_ft` | decimal(6,2) |  | YES |
| `height_ft` | decimal(6,2) |  | YES |
| `width_ft` | decimal(6,2) |  | YES |
| `weight_capacity_lbs` | int unsigned |  | YES |
| `wheel_size` | varchar(50) |  | YES |
| `tire_size` | varchar(50) |  | YES |
| `axle_count` | tinyint unsigned |  | YES |
| `license_plate` | varchar(50) |  | YES |
| `license_state` | varchar(50) |  | YES |
| `cvi_expiry` | date | MUL | YES |
| `registration_expiry` | date | MUL | YES |
| `mvi_expiry` | date | MUL | YES |
| `insurance_expiry` | date | MUL | YES |
| `cvi_interval_days` | smallint unsigned |  | YES |
| `cvi_from_date` | date |  | YES |
| `mvi_interval_days` | smallint unsigned |  | YES |
| `registration_interval_days` | smallint unsigned |  | YES |
| `registration_from_date` | date |  | YES |
| `insurance_interval_days` | smallint unsigned |  | YES |
| `insurance_from_date` | date |  | YES |
| `cvi_document` | varchar(500) |  | YES |
| `registration_document` | varchar(500) |  | YES |
| `insurance_document` | varchar(500) |  | YES |
| `status` | enum('available','reserved','on_lease','maintenance','inactive','decommissioned') | MUL | NO |
| `mileage` | int unsigned |  | NO |
| `notes` | text |  | YES |
| `inspection_notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `tags` | json |  | YES |
| `health_score` | tinyint unsigned |  | YES |
| `health_score_updated_at` | datetime |  | YES |
| `qr_code_path` | varchar(500) |  | YES |
| `lease_count` | int unsigned |  | NO |
| `total_revenue` | decimal(14,2) |  | NO |
| `total_maintenance_cost` | decimal(12,2) |  | NO |
| `acquired_date` | date |  | YES |
| `acquisition_cost` | decimal(12,2) |  | YES |
| `decommissioned_date` | date |  | YES |
| `decommission_reason` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `updated_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime | MUL | YES |
| `samsara_vehicle_id` | varchar(100) |  | YES |
| `samsara_entity_type` | enum('vehicle','trailer') |  | NO |
| `samsara_vehicle_name` | varchar(255) |  | YES |
| `samsara_vin` | varchar(50) |  | YES |
| `samsara_serial_number` | varchar(100) |  | YES |
| `samsara_gateway_id` | varchar(100) |  | YES |
| `samsara_battery_pct` | tinyint unsigned |  | YES |
| `samsara_battery_charging` | tinyint(1) |  | YES |
| `samsara_power_source` | varchar(50) |  | YES |
| `samsara_check_in_mode` | varchar(100) |  | YES |
| `samsara_last_location_lat` | decimal(10,7) |  | YES |
| `samsara_last_location_lng` | decimal(10,7) |  | YES |
| `samsara_last_location_address` | text |  | YES |
| `samsara_last_speed_kph` | decimal(6,2) |  | YES |
| `samsara_last_connected_at` | datetime |  | YES |
| `samsara_last_synced_at` | datetime |  | YES |
| `samsara_odometer_km` | decimal(10,2) |  | YES |
| `samsara_tags` | json |  | YES |

## `exchange_rates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `from_currency` | enum('USD','CAD') | MUL | NO |
| `to_currency` | enum('USD','CAD') |  | NO |
| `rate` | decimal(10,6) |  | NO |
| `rate_date` | date | MUL | NO |
| `source` | enum('manual','api') |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `generated_contracts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `template_id` | int unsigned | MUL | YES |
| `file_path` | varchar(500) |  | NO |
| `generated_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `generated_by` | int unsigned | MUL | YES |
| `signature_status` | enum('pending','sent','signed','declined','expired') |  | NO |
| `signature_sent_at` | datetime |  | YES |
| `signature_signed_at` | datetime |  | YES |
| `signee_email` | varchar(255) |  | YES |

## `inspection_photos`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `inspection_id` | int unsigned | MUL | NO |
| `section_id` | int unsigned | MUL | YES |
| `file_path` | varchar(500) |  | NO |
| `caption` | varchar(255) |  | YES |
| `sort_order` | tinyint unsigned |  | NO |
| `uploaded_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `inspection_sections`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `inspection_id` | int unsigned | MUL | NO |
| `section_name` | varchar(100) |  | NO |
| `condition` | enum('ok','fair','damaged','missing','na') |  | NO |
| `notes` | text |  | YES |
| `section_data` | json |  | YES |
| `sort_order` | tinyint unsigned |  | NO |

## `invoice_line_items`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `invoice_id` | int unsigned | MUL | NO |
| `sort_order` | tinyint unsigned |  | NO |
| `item_type` | enum('base_rental','mileage_precharge','mileage_adjustment','mileage_credit','insurance','warranty','late_fee','early_return_credit','manual_adjustment','damage','discount','account_credit_applied','other','gps','mileage_usage','mileage_drawdown_credit','base_rental_reconciliation_credit') |  | NO |
| `description` | varchar(500) |  | NO |
| `detail_lines` | json |  | YES |
| `quantity` | decimal(10,4) |  | NO |
| `unit` | varchar(50) |  | YES |
| `unit_price` | decimal(12,2) |  | NO |
| `amount` | decimal(12,2) |  | NO |
| `is_credit` | tinyint(1) |  | NO |
| `taxable` | tinyint(1) |  | NO |
| `tax_gst_amount` | decimal(10,2) |  | NO |
| `tax_pst_amount` | decimal(10,2) |  | NO |
| `tax_hst_amount` | decimal(10,2) |  | NO |
| `mileage_distance` | decimal(10,2) |  | YES |
| `mileage_unit` | enum('km','miles') |  | YES |
| `mileage_rate` | decimal(8,4) |  | YES |
| `mileage_estimated` | decimal(10,2) |  | YES |
| `mileage_actual` | decimal(10,2) |  | YES |
| `billing_days` | smallint unsigned |  | YES |
| `rate_method` | enum('daily','weekly','weekly_capped','monthly') |  | YES |
| `period_start` | date |  | YES |
| `period_end` | date |  | YES |
| `reference_type` | varchar(100) |  | YES |
| `reference_id` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `invoices_model_c_backup_S_MILEAGE_2B`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `invoice_id` | int unsigned | MUL | NO |
| `invoice_number` | varchar(50) | MUL | NO |
| `invoice_status` | enum('draft','sent','partially_paid','paid','overdue','void','written_off') |  | NO |
| `excess_distance_km` | decimal(10,2) |  | YES |
| `excess_charge_amount` | decimal(12,2) |  | YES |
| `mileage_review_status` | enum('not_required','pending','approved','overridden') |  | YES |
| `mileage_override_amount` | decimal(12,2) |  | YES |
| `mileage_reviewed_at` | datetime |  | YES |
| `mileage_reviewed_by_user_id` | int unsigned |  | YES |
| `mileage_review_notes` | text |  | YES |
| `snapshot_taken_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `late_fee_rules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | YES |
| `fee_type` | enum('percentage','flat') |  | NO |
| `fee_value` | decimal(8,4) |  | NO |
| `grace_days` | tinyint unsigned |  | NO |
| `max_fee_amount` | decimal(10,2) |  | YES |
| `compound` | tinyint(1) |  | NO |
| `is_active` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `lease_amendments`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `amendment_type` | enum('rate_change','date_extension','unit_swap','add_on','tax_change','other') |  | NO |
| `description` | text |  | NO |
| `old_values` | json |  | YES |
| `new_values` | json |  | YES |
| `document_path` | varchar(500) |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `approved_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `lease_billing_periods`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `invoice_id` | int unsigned | MUL | YES |
| `period_start` | date | MUL | NO |
| `period_end` | date |  | NO |
| `period_days` | smallint unsigned |  | NO |
| `period_type` | enum('partial_start','full_month','partial_end','single_period') |  | NO |
| `rate_method` | enum('daily','weekly','weekly_capped','monthly') |  | NO |
| `rate_method_explanation` | json |  | YES |
| `base_amount` | decimal(12,2) |  | NO |
| `discount_amount` | decimal(12,2) |  | NO |
| `tax_amount` | decimal(12,2) |  | NO |
| `total_amount` | decimal(12,2) |  | NO |
| `daily_rate_used` | decimal(10,2) |  | NO |
| `weekly_rate_used` | decimal(10,2) |  | NO |
| `monthly_rate_used` | decimal(10,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `status` | enum('pending','invoiced','paid','void') | MUL | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `lease_close_adjustments_backup_S_MILEAGE_3`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `adjustment_type` | enum('excess_charge','underage_credit','no_adjustment') |  | NO |
| `calculated_distance_km` | decimal(10,2) |  | NO |
| `calculated_amount` | decimal(12,2) |  | NO |
| `final_amount` | decimal(12,2) |  | NO |
| `decision` | enum('credit_note','final_invoice_adjustment','waived','no_adjustment') |  | NO |
| `related_invoice_id` | int unsigned |  | YES |
| `related_credit_note_id` | int unsigned |  | YES |
| `approved_by_user_id` | int unsigned |  | NO |
| `approved_at` | datetime |  | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `snapshot_taken_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `lease_status_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `old_status` | varchar(50) |  | NO |
| `new_status` | varchar(50) |  | NO |
| `notes` | text |  | YES |
| `changed_by` | varchar(255) |  | NO |
| `user_id` | int unsigned | MUL | YES |
| `changed_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `leases_precharge_backup_S_MILEAGE_1`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `lease_id` | int unsigned | MUL | NO |
| `mileage_precharge_amount` | decimal(12,2) |  | YES |
| `mileage_precharge_invoiced` | tinyint(1) |  | YES |
| `snapshot_taken_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `maintenance_line_items`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `work_order_id` | int unsigned | MUL | NO |
| `item_type` | enum('labor','part','sublet','other') |  | NO |
| `description` | varchar(500) |  | NO |
| `quantity` | decimal(8,2) |  | NO |
| `unit_cost` | decimal(10,2) |  | NO |
| `total_cost` | decimal(10,2) |  | NO |
| `part_number` | varchar(100) |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `maintenance_work_orders`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `work_order_number` | varchar(100) | UNI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `vendor_id` | int unsigned | MUL | YES |
| `work_type` | enum('scheduled_service','repair','inspection','tire','electrical','body_damage','breakdown','other') |  | NO |
| `priority` | enum('low','medium','high','emergency') |  | NO |
| `status` | enum('open','in_progress','waiting_parts','completed','cancelled') | MUL | NO |
| `title` | varchar(500) |  | NO |
| `description` | text |  | YES |
| `mileage_at_service` | int unsigned |  | YES |
| `requested_date` | date |  | NO |
| `scheduled_date` | date |  | YES |
| `completed_date` | date |  | YES |
| `labor_cost` | decimal(10,2) |  | NO |
| `parts_cost` | decimal(10,2) |  | NO |
| `total_cost` | decimal(10,2) |  | NO |
| `notes` | text |  | YES |
| `internal_notes` | text |  | YES |
| `resolution_notes` | text |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `assigned_to` | int unsigned |  | YES |
| `completed_by` | int unsigned |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `messenger_messages`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `thread_id` | int unsigned | MUL | NO |
| `sender_type` | enum('admin','portal') |  | NO |
| `admin_user_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned | MUL | YES |
| `body` | text |  | NO |
| `is_archived` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `messenger_thread_reads`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `thread_id` | int unsigned | MUL | NO |
| `admin_user_id` | int unsigned | MUL | YES |
| `portal_user_id` | int unsigned | MUL | YES |
| `last_read_message_id` | int unsigned |  | YES |
| `last_read_at` | datetime |  | YES |

## `messenger_threads`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `scope` | enum('customer','portal_user') | MUL | NO |
| `portal_user_id` | int unsigned | MUL | YES |
| `subject` | varchar(255) |  | NO |
| `created_by_user_id` | int unsigned | MUL | NO |
| `last_message_at` | datetime | MUL | YES |
| `last_message_preview` | varchar(255) |  | YES |
| `last_message_by` | enum('admin','portal') |  | YES |
| `unread_admin_count` | int unsigned |  | NO |
| `is_archived` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `mileage_logs`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `lease_id` | int unsigned | MUL | YES |
| `log_type` | enum('manual','gps_sync','lease_start','lease_end','service') |  | NO |
| `odometer_reading` | int unsigned |  | NO |
| `mileage_unit` | enum('km','miles') |  | NO |
| `log_date` | date | MUL | NO |
| `notes` | text |  | YES |
| `recorded_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `notification_log`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `rule_id` | int unsigned | MUL | YES |
| `channel` | enum('email','sms','in_app','webhook') |  | NO |
| `recipient` | varchar(255) |  | NO |
| `subject` | varchar(500) |  | YES |
| `body` | text |  | YES |
| `entity_type` | varchar(100) | MUL | YES |
| `entity_id` | int unsigned |  | YES |
| `notification_type` | varchar(100) |  | YES |
| `status` | enum('queued','sent','delivered','failed','bounced') | MUL | NO |
| `error_message` | text |  | YES |
| `sent_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `notification_log_archive`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `rule_id` | int unsigned |  | YES |
| `channel` | enum('email','sms','in_app','webhook') |  | NO |
| `recipient` | varchar(255) |  | NO |
| `subject` | varchar(500) |  | YES |
| `body` | text |  | YES |
| `entity_type` | varchar(100) | MUL | YES |
| `entity_id` | int unsigned |  | YES |
| `notification_type` | varchar(100) |  | YES |
| `status` | enum('queued','sent','delivered','failed','bounced') | MUL | NO |
| `error_message` | text |  | YES |
| `sent_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ | MUL | NO |

## `notification_rules`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `trigger_type` | enum('compliance_expiry','overdue_invoice','lease_end','reservation_pickup','payment_received','low_utilization','damage_claim','custom') |  | NO |
| `trigger_config` | json |  | NO |
| `channels` | json |  | NO |
| `recipients` | json |  | NO |
| `template_subject` | varchar(500) |  | YES |
| `template_body` | text |  | YES |
| `is_active` | tinyint(1) |  | NO |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `password_reset_tokens`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `token_hash` | varchar(64) | MUL | NO |
| `expires_at` | datetime |  | NO |
| `used_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `payment_allocations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `payment_id` | int unsigned | MUL | NO |
| `invoice_id` | int unsigned | MUL | NO |
| `amount` | decimal(12,2) |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `allocation_type` | enum('auto','manual') |  | NO |
| `notes` | text |  | YES |
| `allocated_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `portal_service_requests`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `portal_user_id` | int unsigned | MUL | NO |
| `customer_id` | int unsigned | MUL | NO |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `lease_id` | int unsigned | MUL | YES |
| `request_type` | enum('lease_extension','early_return','damage_report','billing_inquiry','document_request','new_lease_inquiry','general') |  | NO |
| `subject` | varchar(500) |  | NO |
| `message` | text |  | NO |
| `status` | enum('open','in_review','resolved','closed') | MUL | NO |
| `assigned_to` | int unsigned | MUL | YES |
| `response` | text |  | YES |
| `resolved_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `portal_users`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `customer_id` | int unsigned | MUL | NO |
| `name` | varchar(255) |  | NO |
| `email` | varchar(255) | UNI | NO |
| `email_disabled` | tinyint(1) |  | NO |
| `email_disabled_reason` | varchar(255) |  | YES |
| `email_disabled_at` | datetime |  | YES |
| `password_hash` | varchar(255) |  | YES |
| `auth0_sub` | varchar(255) | UNI | YES |
| `status` | enum('active','inactive','invited') |  | NO |
| `invite_token` | varchar(100) |  | YES |
| `invite_token_expiry` | datetime |  | YES |
| `invite_sent_at` | datetime |  | YES |
| `last_login_at` | datetime |  | YES |
| `last_login_ip` | varchar(45) |  | YES |
| `login_attempts` | tinyint unsigned |  | NO |
| `locked_until` | datetime |  | YES |
| `password_reset_token` | varchar(100) |  | YES |
| `password_reset_expiry` | datetime |  | YES |
| `is_primary` | tinyint(1) |  | NO |
| `notification_preferences` | json |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `rate_card_items`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `rate_card_id` | int unsigned | MUL | NO |
| `equipment_type` | varchar(255) |  | NO |
| `daily_rate` | decimal(10,2) |  | YES |
| `weekly_rate` | decimal(10,2) |  | YES |
| `monthly_rate` | decimal(10,2) |  | YES |
| `mileage_rate` | decimal(8,4) |  | YES |
| `mileage_unit` | enum('km','miles') |  | NO |
| `currency` | enum('CAD','USD') |  | NO |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `rate_cards`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(255) |  | NO |
| `description` | text |  | YES |
| `is_default` | tinyint(1) | MUL | NO |
| `effective_from` | date | MUL | NO |
| `effective_to` | date |  | YES |
| `created_by` | int unsigned | MUL | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |
| `deleted_at` | datetime |  | YES |

## `rate_limit_attempts`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `bucket_key` | varchar(255) | MUL | NO |
| `attempt_count` | int unsigned |  | NO |
| `window_start` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `last_attempt` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `blocked_until` | datetime | MUL | YES |

## `report_cache`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `report_type` | varchar(100) | MUL | NO |
| `parameters_hash` | varchar(64) |  | NO |
| `parameters` | json |  | NO |
| `result_data` | longtext |  | NO |
| `generated_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `expires_at` | datetime | MUL | NO |
| `generated_by` | int unsigned | MUL | YES |

## `reservation_units`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `reservation_id` | int unsigned | MUL | NO |
| `equipment_unit_id` | int unsigned | MUL | YES |
| `unit_number_snapshot` | varchar(100) |  | NO |
| `template_name_snapshot` | varchar(100) |  | YES |
| `status_at_reservation` | varchar(50) |  | YES |
| `lease_id_linked` | int unsigned | MUL | YES |
| `entry_type` | enum('system','manual') |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `samsara_location_history`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `samsara_vehicle_id` | varchar(100) |  | NO |
| `samsara_entity_type` | enum('vehicle','trailer') |  | NO |
| `latitude` | decimal(10,7) |  | NO |
| `longitude` | decimal(10,7) |  | NO |
| `speed_kph` | decimal(6,2) |  | YES |
| `heading` | smallint unsigned |  | YES |
| `address` | text |  | YES |
| `recorded_at` | datetime | MUL | NO |
| `synced_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `saved_reports`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `name` | varchar(255) |  | NO |
| `report_type` | varchar(100) |  | NO |
| `parameters` | json |  | NO |
| `is_pinned` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `scheduled_reports`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `name` | varchar(255) |  | NO |
| `report_type` | varchar(100) |  | NO |
| `parameters` | json |  | NO |
| `frequency` | enum('daily','weekly','monthly') |  | NO |
| `send_day` | tinyint unsigned |  | YES |
| `send_time` | time |  | NO |
| `recipients` | json |  | NO |
| `format` | enum('pdf','csv','both') |  | NO |
| `is_active` | tinyint(1) |  | NO |
| `last_sent_at` | datetime |  | YES |
| `next_send_at` | datetime |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `schema_migrations`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `version` | varchar(100) | UNI | NO |
| `filename` | varchar(255) |  | NO |
| `checksum` | char(64) |  | YES |
| `applied_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `applied_by` | varchar(100) |  | NO |
| `execution_ms` | int unsigned |  | YES |

## `tax_rates`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(100) |  | NO |
| `province` | varchar(100) |  | YES |
| `country` | enum('CA','US') |  | NO |
| `gst_rate` | decimal(8,6) |  | NO |
| `pst_rate` | decimal(8,6) |  | NO |
| `hst_rate` | decimal(8,6) |  | NO |
| `is_default` | tinyint(1) |  | NO |
| `is_active` | tinyint(1) |  | NO |
| `effective_from` | date | MUL | NO |
| `effective_to` | date |  | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `user_mfa_backup_codes`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `code_hash` | varchar(255) |  | NO |
| `used_at` | datetime |  | YES |
| `used_ip` | varchar(45) |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `user_permission_overrides`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `user_id` | int unsigned | MUL | NO |
| `module` | varchar(50) |  | NO |
| `action` | varchar(50) |  | NO |
| `granted` | tinyint(1) |  | NO |
| `granted_by` | int unsigned | MUL | NO |
| `reason` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |
| `updated_at` | datetime _(DEFAULT_GENERATED on update CURRENT_TIMESTAMP)_ |  | NO |

## `user_permissions`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `role_id` | int unsigned | MUL | NO |
| `module` | varchar(100) |  | NO |
| `can_view` | tinyint(1) |  | NO |
| `can_create` | tinyint(1) |  | NO |
| `can_edit` | tinyint(1) |  | NO |
| `can_delete` | tinyint(1) |  | NO |
| `can_export` | tinyint(1) |  | NO |

## `user_roles`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `name` | varchar(100) | UNI | NO |
| `slug` | varchar(100) | UNI | NO |
| `description` | text |  | YES |
| `is_system` | tinyint(1) |  | NO |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

## `yard_transfers`

| Column | Type | Key | Nullable |
|--------|------|-----|----------|
| `id` | int unsigned _(auto_increment)_ | PRI | NO |
| `equipment_unit_id` | int unsigned | MUL | NO |
| `from_yard` | varchar(100) |  | NO |
| `to_yard` | varchar(100) |  | NO |
| `transfer_date` | date |  | NO |
| `reason` | text |  | YES |
| `authorized_by` | int unsigned | MUL | YES |
| `notes` | text |  | YES |
| `created_at` | datetime _(DEFAULT_GENERATED)_ |  | NO |

