-- ============================================================
-- FleetForge — Seed: Default Settings
-- File: database/seeds/004_settings.sql
--
-- Populates the settings table with installation defaults.
-- All values can be changed post-install via Settings UI.
--
-- Column names: `key` and `value` (reserved words — backtick-quoted).
-- Same pattern used by settings_get() and generate_id() in functions.php.
--
-- Spec ref: FLEETFORGE_SPEC_FINAL.md §7 (Settings Module)
-- ============================================================

-- Idempotent: INSERT IGNORE skips rows whose `key` already exists.
-- This means re-running this file will NOT overwrite admin changes.

INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES

-- ----------------------------------------------------------------
-- Company
-- ----------------------------------------------------------------
('company.name',        'Mainland Truck & Trailer Sales', 'string',  'company', 'Company Name',        'Displayed in topbar, PDFs, and the portal.',                                    1),
('company.address',     '9045 King George Blvd',          'string',  'company', 'Street Address',      'Used in invoice PDF headers.',                                                  0),
('company.city',        'Surrey',                          'string',  'company', 'City',                NULL,                                                                            0),
('company.province',    'BC',                              'string',  'company', 'Province / State',    NULL,                                                                            0),
('company.postal_code', 'V3W 3X2',                        'string',  'company', 'Postal Code',         NULL,                                                                            0),
('company.country',     'CA',                              'string',  'company', 'Country',             NULL,                                                                            0),
('company.phone',       '',                                'string',  'company', 'Phone',               NULL,                                                                            1),
('company.email',       '',                                'string',  'company', 'Email',               'Reply-to address on outbound emails.',                                          1),
('company.website',     '',                                'string',  'company', 'Website',             NULL,                                                                            1),
('company.logo_url',    '',                                'string',  'company', 'Logo URL',            'File path or URL for the logo shown in the topbar and PDFs.',                  1),
('company.timezone',    'America/Vancouver',               'string',  'company', 'Timezone',            'All dates are displayed in this timezone.',                                     0),
('company.currency',    'CAD',                             'string',  'company', 'Currency Code',       'ISO 4217 currency code (e.g. CAD, USD).',                                      0),
('company.currency_symbol', '$',                           'string',  'company', 'Currency Symbol',     'Symbol prepended to all monetary values.',                                     1),
('company.gst_number',          '',                                'string',  'company', 'GST/HST Number',         'CRA Business Number in BN-RT format (e.g. 123456789 RT 0001). Required on all invoices.', 0),
('company.pst_number',          '',                                'string',  'company', 'PST Number',             'BC PST registration number. Required on BC invoices.',                         0),
('company.bank_name',           '',                                'string',  'company', 'Bank Name',              'Bank name shown on portal invoice payment details.',                           0),
('company.bank_account',        '',                                'string',  'company', 'Bank Account',           'Bank account number shown on portal invoice payment details.',                 0),
('company.check_payable_to',    '',                                'string',  'company', 'Cheque Payable To',      'Name shown on portal invoices for cheque payments.',                           0),
('company.payment_instructions','',                                'text',    'company', 'Payment Instructions',   'Default payment instructions shown on portal invoices.',                       0),

-- ----------------------------------------------------------------
-- Invoices & Payments
-- ----------------------------------------------------------------
('invoice.prefix',                 'INV',   'string',  'invoices',      'Invoice Number Prefix',      'Prefix for all invoice numbers (e.g. INV → INV-2025-00001).',      0),
('invoice.due_days_default',       '30',   'integer', 'invoices',      'Default Payment Due Days',   'Days after invoice date that payment is due.',                      0),
('invoice.late_fee_percentage',    '0',    'decimal', 'invoices',      'Late Fee Percentage',        'Monthly late fee applied to overdue invoices (0 = disabled).',     0),
('payment.prefix',                 'PAY',  'string',  'invoices',      'Payment Number Prefix',      'Prefix for all payment numbers (e.g. PAY → PAY-2025-00001).',      0),
('credit_note.prefix',             'CN-CR','string',  'invoices',      'Credit Note Number Prefix',  'Prefix for all credit note numbers (e.g. CN-CR → CN-CR-2025-00001).', 0),
('damage_claim.prefix',            'DMG',  'string',  'maintenance',   'Damage Claim Number Prefix', 'Prefix for all damage claim numbers (e.g. DMG → DMG-2025-00001).', 0),
('lease.prefix',                   'CN',   'string',  'leases',        'Contract Number Prefix',     'Prefix for lease contract numbers (e.g. CN → CN-ABC123-2025).',    0),
('billing.max_advance_periods',    '24',   'integer', 'invoices',      'Max Advance Billing Periods','Hard cap on advance_billing_periods at lease creation (ADV-BILL-1). Lease APIs reject values above this; lease form clamps the input. 0 disables advance billing entirely.', 0),

-- ----------------------------------------------------------------
-- Alerts & Compliance
-- ----------------------------------------------------------------
('alerts.compliance_warning_days',  '30', 'integer', 'alerts', 'Compliance Warning Days',       'Days before document expiry to show a warning alert.',    0),
('alerts.compliance_critical_days', '7',  'integer', 'alerts', 'Compliance Critical Days',      'Days before document expiry to show a critical alert.',   0),
('alerts.lease_end_reminder_days',  '7',  'integer', 'alerts', 'Lease End Reminder Days',       'Days before lease end_date to send a reminder email.',    0),
('alerts.overdue_invoice_days',     '15', 'integer', 'alerts', 'Overdue Escalation Days',       'Days past due before an escalation notification fires.',  0),

-- ----------------------------------------------------------------
-- Currency Conversion
-- ----------------------------------------------------------------
('currency.usd_cad_markup_pct', '0.0000', 'decimal', 'currency', 'USD → CAD Markup', 'Markup % applied on top of the bank exchange rate when generating USD invoices. Frozen per invoice at creation. Visible on invoice and PDF. 0 = no markup.', 0),

-- ----------------------------------------------------------------
-- GPS Integration (keys left blank — filled in by admin)
-- ----------------------------------------------------------------
('gps.primary_provider',    '',  'string', 'gps', 'GPS Primary Provider',    'samsara or geotab.',                                            0),
('gps.samsara_api_key',     '',  'string', 'gps', 'Samsara API Key',         'Encrypted at rest. Leave blank if not using Samsara.',          0),
('gps.samsara_org_id',      '',  'string', 'gps', 'Samsara Org ID',         NULL,                                                            0),
('gps.geotab_database',     '',  'string', 'gps', 'Geotab Database',        NULL,                                                            0),
('gps.geotab_username',     '',  'string', 'gps', 'Geotab Username',        NULL,                                                            0),
('gps.geotab_password',     '',  'string', 'gps', 'Geotab Password',        'Encrypted at rest.',                                            0),
('gps.sync_interval_minutes', '5', 'integer', 'gps', 'GPS Sync Interval (min)', 'How often to poll for GPS location updates.',              0),

-- ----------------------------------------------------------------
-- AI Features
-- ----------------------------------------------------------------
('ai.enabled',           '0',                        'boolean', 'ai', 'AI Features Enabled',   'Master toggle for all AI-powered features.',                                0),
('ai.daily_token_limit', '500000',                   'integer', 'ai', 'Daily Token Limit',     'Max tokens per day across all users (0 = unlimited).',                      0),
('ai.model',             'claude-sonnet-4-20250514', 'string',  'ai', 'AI Model',              'Claude model ID used for all AI features.',                                 0),
('ai.cache_summaries',   '1',                        'boolean', 'ai', 'Cache AI Summaries',    'Cache AI-generated summaries to reduce API calls.',                         0),
('ai.summary_ttl_hours', '24',                       'integer', 'ai', 'Summary Cache TTL (h)', 'Hours before cached AI summaries expire and regenerate.',                   0),

-- ----------------------------------------------------------------
-- Notifications / Email
-- ----------------------------------------------------------------
('notifications.email_enabled', '0',          'boolean', 'notifications', 'Email Notifications Enabled', 'Master toggle for all outbound email.',                       0),
('notifications.smtp_host',     '',           'string',  'notifications', 'SMTP Host',                    NULL,                                                          0),
('notifications.smtp_port',     '587',        'integer', 'notifications', 'SMTP Port',                    NULL,                                                          0),
('notifications.smtp_user',     '',           'string',  'notifications', 'SMTP Username',                NULL,                                                          0),
('notifications.smtp_pass',     '',           'string',  'notifications', 'SMTP Password',                'Stored encrypted.',                                           0),
('notifications.smtp_from',     '',           'string',  'notifications', 'From Address',                 'Sender address on all outbound emails.',                      0),
('notifications.smtp_from_name','FleetForge', 'string',  'notifications', 'From Name',                    'Sender name on all outbound emails.',                         0),

-- ----------------------------------------------------------------
-- Yards
-- ----------------------------------------------------------------
('yard.default', 'surrey', 'string', 'yards', 'Default Yard Slug', 'Slug of the yard pre-selected in all unit/lease dropdowns.', 0);
