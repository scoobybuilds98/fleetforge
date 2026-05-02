<?php
declare(strict_types=1);

namespace FleetForge\Auth;

/**
 * lib/Auth/MfaService.php
 *
 * Encapsulates all TOTP-based MFA operations for the admin panel.
 * Portal (System 2) has separate auth — this class is admin-only.
 *
 * Secret storage: mfa_secret column holds AES-256-CBC ciphertext,
 * prefixed with 'ENC:'. Key comes from FF_MFA_SECRET_KEY in .env.
 * Plaintext secrets are NEVER persisted — only used transiently
 * during setup (held in PHP session for ≤ 10 minutes).
 *
 * Backup codes: 8-char alphanumeric, bcrypt-hashed in
 * user_mfa_backup_codes. Single-use — used_at marks consumption.
 *
 * D-B: Self-disable blocked for mfa_required roles. A super_admin
 *      can disable MFA for another user but not for themselves.
 *
 * @session S-PROD-1A
 */

use PragmaRX\Google2FA\Google2FA;

class MfaService
{
    private static ?Google2FA $totp = null;

    private static function totp(): Google2FA
    {
        if (self::$totp === null) {
            self::$totp = new Google2FA();
        }
        return self::$totp;
    }

    // ────────────────────────────────────────────────────────────
    // Role-driven MFA policy (S-PROD-1B #67)
    // ────────────────────────────────────────────────────────────

    /**
     * Returns true if the given role requires MFA, per the
     * security.mfa.required_roles setting (default: super_admin, manager).
     * Returns false for unknown role IDs or misconfigured settings.
     */
    public static function userRoleRequiresMfa(int $roleId): bool
    {
        $role = db_row("SELECT slug FROM user_roles WHERE id = ?", [$roleId]);
        if (!$role) {
            return false;
        }
        $required = json_decode(
            settings_get('security.mfa.required_roles', '["super_admin","manager"]'),
            true
        );
        if (!is_array($required)) {
            return false;
        }
        return in_array($role['slug'], $required, true);
    }

    // ────────────────────────────────────────────────────────────
    // TOTP
    // ────────────────────────────────────────────────────────────

    public static function generateSecret(): string
    {
        return self::totp()->generateSecretKey(32);
    }

    /** Returns the otpauth:// URL for QR code generation. */
    public static function generateQrCodeUrl(string $plainSecret, string $userEmail, string $companyName): string
    {
        return self::totp()->getQRCodeUrl(
            company:  $companyName,
            holder:   $userEmail,
            secret:   $plainSecret
        );
    }

    /** Generates a QR code SVG from an otpauth URL (server-side). */
    public static function generateQrSvg(string $otpauthUrl): string
    {
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(280),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        $writer = new \BaconQrCode\Writer($renderer);
        return $writer->writeString($otpauthUrl);
    }

    /**
     * Verifies a 6-digit TOTP code against a PLAIN (decrypted) secret.
     * Callers must decrypt mfa_secret before calling this.
     * Window setting from security.mfa.totp_window (default 1 = ±30s).
     */
    public static function verifyTotpCode(string $plainSecret, string $code): bool
    {
        $window = (int) settings_get('security.mfa.totp_window', 1);
        return (bool) self::totp()->verifyKey($plainSecret, $code, $window);
    }

    // ────────────────────────────────────────────────────────────
    // Backup codes
    // ────────────────────────────────────────────────────────────

    /**
     * Generates $count cryptographically random backup codes.
     * Deletes ALL unused codes for the user first, then inserts fresh ones.
     * Returns plaintext codes — shown once to the user, never stored again.
     */
    public static function generateBackupCodes(int $userId, int $count = 10): array
    {
        db_execute(
            "DELETE FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $plain = self::randomCode(8);
            $hash  = password_hash($plain, PASSWORD_BCRYPT);
            db_insert('user_mfa_backup_codes', [
                'user_id'   => $userId,
                'code_hash' => $hash,
            ]);
            $codes[] = $plain;
        }

        return $codes;
    }

    /**
     * Verifies a backup code against stored hashes and marks it used.
     * Uses constant-time password_verify to prevent timing attacks.
     * Returns false if code is invalid, unknown, or already consumed.
     */
    public static function consumeBackupCode(int $userId, string $code): bool
    {
        $rows = db_select(
            "SELECT id, code_hash FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );

        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                $ip = \FleetForge\Security\RateLimiter::getClientIp();
                // S-PROD-1A-FIX-4 T16: AND used_at IS NULL guards against two concurrent
                // requests both passing password_verify and double-consuming the same code.
                // db_execute returns rowCount(); 0 means another worker won the race.
                $affected = db_execute(
                    "UPDATE user_mfa_backup_codes SET used_at = NOW(), used_ip = ? WHERE id = ? AND used_at IS NULL",
                    [$ip, $row['id']]
                );
                return $affected === 1;
            }
        }

        return false;
    }

    /** Returns how many unused backup codes the user has. */
    public static function countUnusedBackupCodes(int $userId): int
    {
        $row = db_row(
            "SELECT COUNT(*) AS cnt FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    // ────────────────────────────────────────────────────────────
    // MFA lifecycle
    // ────────────────────────────────────────────────────────────

    /**
     * Final step of setup: verifies the TOTP code, then commits MFA to the DB.
     * Takes the PLAINTEXT secret from the session (not yet encrypted in DB).
     * On success: encrypts secret, sets mfa_enabled=1, issues backup codes.
     */
    public static function enableMfa(int $userId, string $plainSecret, string $verificationCode): array
    {
        if (!self::verifyTotpCode($plainSecret, $verificationCode)) {
            return ['success' => false, 'error' => 'INVALID_CODE'];
        }

        $encryptedSecret = self::encryptSecret($plainSecret);
        $count           = (int) settings_get('security.mfa.backup_code_count', 10);
        $backupCodes     = self::generateBackupCodes($userId, $count);

        db_execute(
            "UPDATE users SET mfa_enabled = 1, mfa_secret = ?, mfa_enabled_at = NOW() WHERE id = ?",
            [$encryptedSecret, $userId]
        );

        return ['success' => true, 'backup_codes' => $backupCodes];
    }

    /**
     * Disables MFA for $userId, performed by $disabledByUserId.
     * D-B: a user cannot disable their own MFA if mfa_required=1.
     * That check is enforced at the API layer; this method executes
     * the disable unconditionally (used by CLI script too).
     */
    public static function disableMfa(int $userId, int $disabledByUserId, string $actorName = ''): void
    {
        db_execute(
            "UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_enabled_at = NULL WHERE id = ?",
            [$userId]
        );
        db_execute(
            "DELETE FROM user_mfa_backup_codes WHERE user_id = ? AND used_at IS NULL",
            [$userId]
        );

        if ($actorName === '') {
            $actorRow  = db_row("SELECT name FROM users WHERE id = ?", [$disabledByUserId]);
            $actorName = $actorRow['name'] ?? 'unknown';
        }
        $target    = db_row("SELECT email FROM users WHERE id = ?", [$userId]);
        $isSelf    = ($userId === $disabledByUserId);
        $ip        = $disabledByUserId > 0 ? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') : '127.0.0.1';

        db_insert('audit_log', [
            'user_id'      => $disabledByUserId > 0 ? $disabledByUserId : null,
            'user_name'    => $actorName,
            'action'       => 'update',
            'module'       => 'auth',
            'entity_type'  => 'user',
            'entity_id'    => $userId,
            'entity_label' => $target['email'] ?? '',
            'notes'        => $isSelf ? 'MFA disabled (self)' : 'MFA disabled by admin',
            'ip_address'   => $ip,
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Encryption helpers (D-C)
    // ────────────────────────────────────────────────────────────

    /**
     * Encrypts a TOTP secret for DB storage.
     * Format: 'ENC:' + base64(iv[16] . ciphertext)
     */
    public static function encryptSecret(string $plain): string
    {
        $key       = self::encryptionKey();
        $iv        = random_bytes(16);
        $cipher    = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return 'ENC:' . base64_encode($iv . $cipher);
    }

    /**
     * Decrypts a stored TOTP secret.
     * Returns null if the value is missing, malformed, or decryption fails.
     */
    public static function decryptSecret(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, 'ENC:')) {
            return null;
        }
        $key  = self::encryptionKey();
        $data = base64_decode(substr($stored, 4));
        if ($data === false || strlen($data) < 17) {
            return null;
        }
        $iv         = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plain      = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return ($plain !== false) ? $plain : null;
    }

    private static function encryptionKey(): string
    {
        $key = env('FF_MFA_SECRET_KEY', '');
        if ($key === '') {
            throw new \RuntimeException('FF_MFA_SECRET_KEY is not set in .env — cannot use MFA.');
        }
        // Hex key from `openssl rand -hex 32` → 32 raw bytes
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            return hex2bin($key);
        }
        // Non-hex fallback: derive 32 bytes via SHA-256
        return hash('sha256', $key, true);
    }

    // ────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────

    /** 8-char backup code from unambiguous alphanumeric character set. */
    private static function randomCode(int $length): string
    {
        $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // excludes 0, O, I, 1
        $result = '';
        $max    = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }
}
