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
('company.name',        'Mainland Truck & Trailer Sales & Leasing', 'string',  'company', 'Company Name',        'Displayed in topbar, PDFs, and the portal.',                                    1),
('company.address',     '9616 188 Street, Surrey, BC Canada V4N 3M2', 'string',  'company', 'Street Address',      'Used in invoice PDF headers and email footers.',                                0),
('company.city',        'Surrey',                          'string',  'company', 'City',                NULL,                                                                            0),
('company.province',    'BC',                              'string',  'company', 'Province / State',    NULL,                                                                            0),
('company.postal_code', 'V4N 3M2',                         'string',  'company', 'Postal Code',         NULL,                                                                            0),
('company.country',     'CA',                              'string',  'company', 'Country',             NULL,                                                                            0),
('company.phone',       '+1 866-888-6887',                 'string',  'company', 'Phone',               NULL,                                                                            1),
('company.email',       'info@mainlandtts.ca',             'string',  'company', 'Email',               'Reply-to address on outbound emails.',                                          1),
('company.website',     'mainlandrentals.com',             'string',  'company', 'Website',             NULL,                                                                            1),
('company.logo_url',    'https://mainlandrentals.com/assets/img/logo-email.png', 'string',  'company', 'Logo URL', 'Public URL of the brand logo shown in email headers and PDFs. NOTE: assets served at docroot, no /fleetforge prefix (asset_url() pattern; see includes/functions.php:54).',  1),
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

-- ----------------------------------------------------------------
-- Customer Credit Application (S-CCA-1, Phase CCA)
-- Operator-overridable. minimum_requirements_html + terms_url hold the
-- carrier insurance copy; the legal entity name "Mainland Truck and Trailer
-- Sales Ltd." is VERBATIM legal text and is intentionally NOT tokenized.
-- ----------------------------------------------------------------
INSERT IGNORE INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES
('credit_application.token_expiry_days', '30',                     'integer', 'credit_application', 'Credit application link expiry (days)', 'Days a sent credit-application invite link stays valid before expiry. Default 30.',                          0),
('credit_application.insurance_email',   'Rentals@mainlandtts.ca', 'string',  'credit_application', 'Insurance documents email',             'Email the applicant is directed to send insurance certificates to.',                                        0),
('credit_application.references_email',  'Sales@mainlandtts.ca',   'string',  'credit_application', 'Trade references email',                'Email the applicant is directed to send trade references to.',                                              0),
('credit_application.terms_url',         'https://mainlandtts.com/wp-content/uploads/2025/06/Carrier-Agrmt-carrier.pdf', 'string', 'credit_application', 'Carrier agreement (terms) URL', 'Public URL of the carrier-agreement PDF the applicant accepts. Snapshotted onto each submission for legal trail.', 0),
('credit_application.minimum_requirements_html', '<h3>Non-Owned Trailer Insurance</h3>

<p><strong>1) Liability</strong><br>
Add Mainland Truck and Trailer Sales Ltd. as an <strong>ADDITIONAL INSURED</strong></p>
<ul>
  <li>a) $2,000,000 Automobile Liability</li>
  <li>b) $2,000,000 Commercial General Liability</li>
  <li>c) $2,000,000 Non-Owned Liability</li>
</ul>

<p><strong>2) Physical Damage Coverage for Non-Owned Trailers</strong><br>
Maximum deductible: <strong>$2,500</strong></p>

<p><strong>3) Loss Payee</strong><br>
Add Mainland Truck and Trailer Sales Ltd. as a <strong>LOSS PAYEE</strong><br>
9616 188 Street, Surrey, BC V4N 3M2</p>

<p>If you do not have the appropriate coverage, Mainland Truck and Trailer Sales Ltd. will supply a physical damage waiver for:</p>
<ul>
  <li>$10.00 per day on regular equipment rentals, with a <strong>MAX of $10,000</strong> deductible.</li>
</ul>

<p>Proof of <strong>NON-OWNED PHYSICAL DAMAGE</strong> including coverage for all perils <strong>MUST</strong> be on file to rent to avoid the daily charge.</p>

<p>Mainland Truck and Trailer Sales Ltd. requires notification <strong>30 days prior to cancellation</strong>.</p>', 'text', 'credit_application', 'Minimum requirements (HTML)', 'Carrier insurance minimum-requirements block. Rendered on the public form (S-CCA-2) and injected into the invite email via {minimum_requirements_html} (D-CCA-7). Legal entity name is verbatim — do NOT tokenize.', 0);
