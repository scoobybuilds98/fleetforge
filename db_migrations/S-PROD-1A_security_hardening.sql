-- ============================================================
-- S-PROD-1A — Security Hardening: MFA + IP Rate Limiting
-- Date: 2026-05-02
-- Audit findings resolved: #16 (no MFA), #17 (no IP rate limit)
-- ============================================================

-- ── 1. MFA columns on users ──────────────────────────────────────────────────

ALTER TABLE users
    ADD COLUMN mfa_enabled    TINYINT(1)   NOT NULL DEFAULT 0   AFTER password_hash,
    ADD COLUMN mfa_secret     VARCHAR(500) NULL                  AFTER mfa_enabled,
    ADD COLUMN mfa_enabled_at DATETIME     NULL                  AFTER mfa_secret,
    ADD COLUMN mfa_required   TINYINT(1)   NOT NULL DEFAULT 0   AFTER mfa_enabled_at;

-- WHY: mfa_required is role-policy driven, not user choice.
-- super_admin and manager roles require MFA. Set on existing rows now.
UPDATE users u
JOIN user_roles r ON r.id = u.role_id
SET u.mfa_required = 1
WHERE r.slug IN ('super_admin', 'manager');

-- ── 2. Backup codes table ────────────────────────────────────────────────────

CREATE TABLE user_mfa_backup_codes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME NULL,
    used_ip    VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_used (user_id, used_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Rate limit attempts table ─────────────────────────────────────────────

CREATE TABLE rate_limit_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key    VARCHAR(255) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    window_start  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until DATETIME NULL,
    INDEX idx_bucket_key (bucket_key),
    INDEX idx_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Settings seeds ────────────────────────────────────────────────────────

INSERT INTO settings (`key`, value, value_type, group_name) VALUES
    ('security.rate_limit.login_ip_threshold',              '20',                       'integer', 'security'),
    ('security.rate_limit.login_ip_window_minutes',         '15',                       'integer', 'security'),
    ('security.rate_limit.login_ip_block_minutes',          '60',                       'integer', 'security'),
    ('security.rate_limit.forgot_password_ip_threshold',    '5',                        'integer', 'security'),
    ('security.rate_limit.forgot_password_ip_window_minutes','60',                      'integer', 'security'),
    ('security.rate_limit.forgot_password_ip_block_minutes','60',                       'integer', 'security'),
    ('security.rate_limit.ai_user_threshold',               '60',                       'integer', 'security'),
    ('security.rate_limit.ai_user_window_minutes',          '60',                       'integer', 'security'),
    ('security.rate_limit.mfa_user_threshold',              '5',                        'integer', 'security'),
    ('security.rate_limit.mfa_user_window_minutes',         '15',                       'integer', 'security'),
    ('security.rate_limit.mfa_ip_threshold',                '10',                       'integer', 'security'),
    ('security.rate_limit.mfa_ip_window_minutes',           '15',                       'integer', 'security'),
    ('security.rate_limit.mfa_ip_block_minutes',            '60',                       'integer', 'security'),
    ('security.mfa.required_roles',                         '["super_admin","manager"]','json',    'security'),
    ('security.mfa.totp_window',                            '1',                        'integer', 'security'),
    ('security.mfa.backup_code_count',                      '10',                       'integer', 'security');
