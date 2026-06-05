-- ============================================================================
-- S-CCA-1 — Customer Credit Application (Phase CCA / session 1 of 4)
-- ============================================================================
--
-- Adds the schema + structural seed for the Customer Credit Application
-- feature. An operator sends a prospective/active customer a PUBLIC tokenized
-- link (NO login — D-CCA-1, mirrors the portal password-reset token mechanism:
-- a random token is emailed, only its SHA-256 hash is stored, lookup is by
-- hash). The customer fills a public form (built in S-CCA-2), signs, and
-- submits (S-CCA-3). This session ships ONLY the schema, settings, email
-- template, the admin "Credit Application" tab, and the send endpoint.
--
-- KEY DESIGN DECISIONS encoded here:
--   D-CCA-2  Lifecycle ENUM('sent','opened','submitted','reviewed') default
--            'sent'. "not_sent" is DERIVED (no active row), NOT an enum value.
--            review_outcome ENUM('approved','declined','needs_info') NULL.
--   D-CCA-3  Re-send allowed; FULL history retained. Each send = a NEW row.
--            Soft-delete (deleted_at). UI lists newest first.
--   D-CCA-4  NO customer-entered value EVER writes to the customers row. The
--            full submission lands in form_data JSON; the admin's own review
--            decision (S-CCA-4) may optionally write approved_credit_limit here
--            and copy it to customers MANUALLY — never auto-copied.
--   D-CCA-5  Signature: drawn-PNG path + legal metadata (typed first/last name,
--            signed_date, terms_accepted, terms_version/terms_url, submitted_ip,
--            submitted_user_agent, submitted_at). Columns created now; captured
--            in S-CCA-2.
--   D-CCA-6  On submit (S-CCA-3): rendered_html snapshot stored on the row AND
--            an mPDF generated into the customer's Documents tab
--            (generated_pdf_document_id FK documents); customer-uploaded files
--            also land as documents rows (uploaded_document_ids JSON). Hooks
--            created now.
--   D-CCA-9  Table name `customer_credit_applications` — deliberately distinct
--            from the UNRELATED QBO `acc_qbo_credit_application_map`
--            (credit-memo-application accounting) and `credit_note_applications`.
--
-- THREE motions:
--   1. CREATE customer_credit_applications (FK CASCADE customers, FK SET NULL
--      documents + users).
--   2. INSERT IGNORE 5 settings (credit_application.*) — operator-overridable.
--   3. INSERT ... ON DUPLICATE KEY UPDATE the `credit_application_invite` email
--      template (body-only; the HTML shell is wrapped at send-time by
--      EmailService::renderEmailHtml).
--
-- MIGRATE COUNT: 93 → 94.
--
-- @session  S-CCA-1
-- @spec     FLEETFORGE_SPEC_FINAL.md (§ documents, § customer portal)
-- @decision D-CCA-1 (public tokenized link, hash-stored, mirrors reset_password),
--           D-CCA-2 (lifecycle + review_outcome ENUMs; not_sent derived),
--           D-CCA-3 (re-send = new row; full history; soft-delete; newest-first),
--           D-CCA-4 (no customer value ever writes to customers; JSON snapshot),
--           D-CCA-5 (signature PNG + legal metadata columns),
--           D-CCA-6 (rendered_html snapshot + generated PDF doc + uploaded docs),
--           D-CCA-9 (table name distinct from acc_qbo_credit_application_map),
--           D-CCA-10 (send via EmailService template `credit_application_invite`)
-- ============================================================================

-- ─── Motion 1: customer_credit_applications ──────────────────────────────
CREATE TABLE IF NOT EXISTS `customer_credit_applications` (
    `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id`               INT UNSIGNED NOT NULL COMMENT 'customers.id — the customer this application belongs to. FK CASCADE.',
    `token_hash`                VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hex of the public link token (D-CCA-1). The RAW token is emailed once and never stored; lookup is by hash. UNIQUE.',
    `token_expires_at`          DATETIME NOT NULL COMMENT 'Link expiry (UTC). Default window = settings credit_application.token_expiry_days (30).',
    `status`                    ENUM('sent','opened','submitted','reviewed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent' COMMENT 'Lifecycle (D-CCA-2). not_sent is DERIVED (no active row), not an enum value.',
    `review_outcome`            ENUM('approved','declined','needs_info') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Admin review decision (S-CCA-4). NULL until reviewed.',
    `form_data`                 JSON DEFAULT NULL COMMENT 'Full customer submission snapshot (D-CCA-4). NEVER written back to the customers row.',
    `rendered_html`             MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Frozen HTML render of the submitted application (D-CCA-6). Populated in S-CCA-3.',
    `print_name_first`          VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Typed legal first name at signing (D-CCA-5).',
    `print_name_last`           VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Typed legal last name at signing (D-CCA-5).',
    `signed_date`               DATE DEFAULT NULL COMMENT 'Date the applicant signed (D-CCA-5).',
    `terms_accepted`            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Applicant checked the terms box (D-CCA-5).',
    `terms_version`             VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Terms version label in force at submit (D-CCA-5).',
    `terms_url`                 VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot of the carrier-agreement URL shown at submit (D-CCA-5).',
    `signature_path`            VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'StorageClient path of the drawn-signature PNG (D-CCA-5). NEVER emitted in API output (Trap 7).',
    `submitted_ip`              VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Applicant IP at submit (legal metadata, D-CCA-5).',
    `submitted_user_agent`      VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Applicant User-Agent at submit (legal metadata, D-CCA-5).',
    `generated_pdf_document_id` INT UNSIGNED DEFAULT NULL COMMENT 'documents.id of the mPDF generated at submit (D-CCA-6). FK SET NULL.',
    `uploaded_document_ids`     JSON DEFAULT NULL COMMENT 'documents.id list of customer-uploaded files, entity_type=customer (D-CCA-6).',
    `sent_at`                   DATETIME DEFAULT NULL COMMENT 'When the invite was sent.',
    `opened_at`                 DATETIME DEFAULT NULL COMMENT 'When the public link was first opened (S-CCA-2).',
    `submitted_at`              DATETIME DEFAULT NULL COMMENT 'When the applicant submitted (S-CCA-3).',
    `reviewed_at`               DATETIME DEFAULT NULL COMMENT 'When an admin recorded a review_outcome (S-CCA-4).',
    `sent_by`                   INT UNSIGNED DEFAULT NULL COMMENT 'users.id who sent the invite. FK SET NULL.',
    `reviewed_by`               INT UNSIGNED DEFAULT NULL COMMENT 'users.id who reviewed. FK SET NULL.',
    `review_notes`              TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Internal admin notes on the review (S-CCA-4).',
    `approved_credit_limit`     DECIMAL(12,2) DEFAULT NULL COMMENT 'Admin-decided credit limit (S-CCA-4). Copied to customers.credit_limit MANUALLY — never auto-copied (D-CCA-4).',
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`                DATETIME DEFAULT NULL COMMENT 'Soft-delete (D-CCA-3). NULL = active.',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_status` (`status`),
    KEY `idx_deleted` (`deleted_at`),
    KEY `idx_generated_pdf` (`generated_pdf_document_id`),
    KEY `idx_sent_by` (`sent_by`),
    KEY `idx_reviewed_by` (`reviewed_by`),
    CONSTRAINT `fk_cca_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cca_generated_pdf` FOREIGN KEY (`generated_pdf_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cca_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cca_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase CCA S-CCA-1: customer credit-application lifecycle. One row per send (re-send = new row, D-CCA-3); public tokenized invite (hash-stored, D-CCA-1); customer submission stored as a JSON snapshot, never written back to customers (D-CCA-4).';

-- ─── Motion 2: settings (INSERT IGNORE — operator-overridable) ────────────
-- minimum_requirements_html + terms_url carry the operator-supplied carrier
-- insurance copy (S-CCA-1 AskUserQuestion Q2). The legal entity name
-- "Mainland Truck and Trailer Sales Ltd." is VERBATIM legal text and is
-- intentionally NOT tokenized with {company_name} (which is the brand
-- "Mainland Truck & Trailer Sales & Leasing" — a different string).
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`) VALUES
    ('credit_application.token_expiry_days', '30', 'integer', 'credit_application',
     'Credit application link expiry (days)',
     'Number of days a sent credit-application invite link stays valid before it expires. Default 30.'),
    ('credit_application.insurance_email', 'Rentals@mainlandtts.ca', 'string', 'credit_application',
     'Insurance documents email',
     'Email address the applicant is directed to send insurance certificates to.'),
    ('credit_application.references_email', 'Sales@mainlandtts.ca', 'string', 'credit_application',
     'Trade references email',
     'Email address the applicant is directed to send trade references to.'),
    ('credit_application.terms_url', 'https://mainlandtts.com/wp-content/uploads/2025/06/Carrier-Agrmt-carrier.pdf', 'string', 'credit_application',
     'Carrier agreement (terms) URL',
     'Public URL of the carrier-agreement PDF the applicant accepts. Snapshotted onto each submission (terms_url) for legal trail.'),
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

<p>Mainland Truck and Trailer Sales Ltd. requires notification <strong>30 days prior to cancellation</strong>.</p>', 'text', 'credit_application',
     'Minimum requirements (HTML)',
     'Carrier insurance minimum-requirements block. Rendered at the bottom of the public credit-application form (S-CCA-2) and injected into the invite email via the {minimum_requirements_html} variable (D-CCA-7). Legal entity name is verbatim — do NOT tokenize.');

-- ─── Motion 3: email template `credit_application_invite` (D-CCA-10) ──────
-- body-only (no <html>/<body> shell — EmailService::renderEmailHtml wraps it
-- at send-time with logo + accent bar + footer). Single-brace {variables}.
-- {minimum_requirements_html} is raw HTML injected verbatim by
-- EmailService::substitute (plain str_replace, not escaped) — it renders.
INSERT INTO `email_templates`
  (`name`, `slug`, `subject`, `body_html`, `body_text`, `category`, `variables`, `is_active`)
VALUES
(
  'Credit Application Invite',
  'credit_application_invite',
  'Credit Application from {company_name}',
  '<h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111827;line-height:1.3;">Credit Application</h1>
<p style="margin:0 0 24px;font-size:13px;color:#6b7280;">{company_name}</p>

<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Dear {customer_name},</p>

<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">Thank you for your interest in establishing a credit account with <strong>{company_name}</strong>. Please complete our secure online credit application using the button below. The form takes only a few minutes and lets you sign electronically.</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
  <tr><td align="center">
    <table cellpadding="0" cellspacing="0" border="0">
      <tr><td style="border-radius:6px;background-color:#F97316;">
        <a href="{credit_application_url}" target="_blank" style="display:inline-block;padding:14px 32px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">Start Your Credit Application</a>
      </td></tr>
    </table>
  </td></tr>
</table>

<p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#6b7280;">If the button does not work, copy and paste this link into your browser:<br><a href="{credit_application_url}" target="_blank" style="color:#F97316;word-break:break-all;">{credit_application_url}</a></p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
  <tr><td style="border-left:4px solid #F97316;background:#fff7ed;padding:16px 20px;border-radius:0 6px 6px 0;">
    {minimum_requirements_html}
  </td></tr>
</table>

<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#6b7280;">Questions? Reply to this email or call us at <strong>{company_phone}</strong>.</p>

<p style="margin:32px 0 0;font-size:15px;line-height:1.6;color:#374151;">Sincerely,<br><strong>{sender_name}</strong><br>{company_name}</p>',
  'Dear {customer_name},

Thank you for your interest in establishing a credit account with {company_name}.

Please complete our secure online credit application here:
{credit_application_url}

The form takes only a few minutes and lets you sign electronically.

Questions? Reply to this email or call us at {company_phone}.

Sincerely,
{sender_name}
{company_name}',
  'general',
  '["company_name","customer_name","credit_application_url","sender_name","minimum_requirements_html","company_phone"]',
  1
)
ON DUPLICATE KEY UPDATE
  `name`      = VALUES(`name`),
  `subject`   = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `body_text` = VALUES(`body_text`),
  `category`  = VALUES(`category`),
  `variables` = VALUES(`variables`);
