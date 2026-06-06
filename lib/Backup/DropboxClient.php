<?php
declare(strict_types=1);

namespace FleetForge\Backup;

/**
 * lib/Backup/DropboxClient.php
 *
 * Dropbox API v2 client for the FleetForge backup arc (S-BACKUP-2, D-BACKUP-1).
 *
 * Responsibilities:
 *   - OAuth access-token refresh (refresh_token grant)
 *   - Chunked file upload: ≤140 MB → single-shot /files/upload;
 *     >140 MB → upload session (32 MB chunks) via start/append/finish
 *   - listFolder, deletePath, getCurrentAccount, exchangeCode
 *
 * Encryption (D-BACKUP-1): dropbox.app_secret + dropbox.refresh_token are
 * stored in settings with is_sensitive=1 and the ENC: prefix (AES-256-CBC,
 * same format as MfaService). Key is derived from APP_SECRET (constant,
 * always set in production; same key already used for HMAC in StorageClient).
 *
 * All HTTP calls use cURL, 30 s timeout (file upload steps use 300 s).
 */

use RuntimeException;

class DropboxClient
{
    /** Single-shot upload limit per D-BACKUP-5 (Dropbox max ~150 MB; use 140 to be safe). */
    private const SINGLE_SHOT_MAX = 140 * 1024 * 1024;

    /** Chunk size for upload sessions per D-BACKUP-5. */
    private const CHUNK_SIZE = 32 * 1024 * 1024;

    private const TOKEN_URL   = 'https://api.dropbox.com/oauth2/token';
    private const API_URL     = 'https://api.dropboxapi.com/2';
    private const CONTENT_URL = 'https://content.dropboxapi.com/2';

    private string $appKey;
    private string $appSecret;
    private string $refreshToken;

    /** Access token cached for the lifetime of this instance. */
    private ?string $cachedAccessToken = null;

    /**
     * Loads and decrypts Dropbox credentials from the settings table.
     *
     * @throws RuntimeException if any required credential is missing.
     */
    public function __construct()
    {
        $this->appKey = (string) settings_get('dropbox.app_key', '');
        if ($this->appKey === '') {
            throw new RuntimeException('DropboxClient: dropbox.app_key is not configured.');
        }

        $rawSecret       = (string) settings_get('dropbox.app_secret', '');
        $rawRefreshToken = (string) settings_get('dropbox.refresh_token', '');

        $this->appSecret    = str_starts_with($rawSecret, 'ENC:')
            ? (self::decrypt($rawSecret) ?? '')
            : $rawSecret;
        $this->refreshToken = str_starts_with($rawRefreshToken, 'ENC:')
            ? (self::decrypt($rawRefreshToken) ?? '')
            : $rawRefreshToken;

        if ($this->appSecret === '') {
            throw new RuntimeException('DropboxClient: dropbox.app_secret is not configured or could not be decrypted.');
        }
        if ($this->refreshToken === '') {
            throw new RuntimeException('DropboxClient: dropbox.refresh_token is not configured or could not be decrypted.');
        }
    }

    /**
     * Returns a valid short-lived access token by exchanging the stored refresh token.
     * Result is cached for the lifetime of this instance.
     *
     * @throws RuntimeException on network error or API rejection.
     */
    public function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id'     => $this->appKey,
                'client_secret' => $this->appSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT    => 30,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '') {
            throw new RuntimeException("DropboxClient: token refresh cURL error: {$curlErr}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("DropboxClient: token refresh failed (HTTP {$httpCode}): {$body}");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('DropboxClient: token refresh returned malformed body.');
        }

        $this->cachedAccessToken = (string) $data['access_token'];
        return $this->cachedAccessToken;
    }

    /**
     * Uploads a local file to a Dropbox path.
     *
     * Selects single-shot or upload session based on file size (D-BACKUP-5).
     *
     * @param string $localPath   Absolute path to the source file.
     * @param string $dropboxPath Dropbox destination (e.g. '/db/fleetforge_20260606.sql.gz').
     *
     * @throws RuntimeException on missing file, network error, or API rejection.
     */
    public function upload(string $localPath, string $dropboxPath): void
    {
        if (!file_exists($localPath)) {
            throw new RuntimeException("DropboxClient: local file not found: {$localPath}");
        }

        $fileSize = (int) filesize($localPath);

        if ($fileSize <= self::SINGLE_SHOT_MAX) {
            $this->uploadSingleShot($localPath, $dropboxPath);
        } else {
            $this->uploadSession($localPath, $dropboxPath, $fileSize);
        }
    }

    /**
     * Lists files in a Dropbox folder (non-recursive, files only).
     *
     * @return array<int, array{name: string, path: string, size: int, modified: string}>
     *
     * @throws RuntimeException on API error.
     */
    public function listFolder(string $dropboxPath): array
    {
        $token = $this->getAccessToken();
        $body  = $this->apiPost('/files/list_folder', ['path' => $dropboxPath, 'recursive' => false], $token);
        $data  = json_decode($body, true);

        if (!is_array($data) || !isset($data['entries'])) {
            return [];
        }

        $results = [];
        foreach ($data['entries'] as $entry) {
            if (($entry['.tag'] ?? '') !== 'file') {
                continue;
            }
            $results[] = [
                'name'     => (string) ($entry['name'] ?? ''),
                'path'     => (string) ($entry['path_lower'] ?? ''),
                'size'     => (int)    ($entry['size'] ?? 0),
                'modified' => (string) ($entry['client_modified'] ?? ''),
            ];
        }
        return $results;
    }

    /**
     * Deletes a file or folder at the given Dropbox path.
     *
     * @throws RuntimeException on API error.
     */
    public function deletePath(string $dropboxPath): void
    {
        $token = $this->getAccessToken();
        $this->apiPost('/files/delete_v2', ['path' => $dropboxPath], $token);
    }

    /**
     * Returns account info for the connected Dropbox user.
     *
     * @throws RuntimeException on API error.
     */
    public function getCurrentAccount(): array
    {
        $token = $this->getAccessToken();
        $body  = $this->apiPost('/users/get_current_account', null, $token);
        $data  = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Exchanges a Dropbox authorization code for tokens.
     *
     * Static — callable before a DropboxClient instance can be constructed,
     * since the refresh_token is not yet in settings at callback time.
     *
     * @return array{access_token: string, refresh_token: string, account_id: string, ...}
     *
     * @throws RuntimeException on network error or API rejection.
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        $appKey    = (string) settings_get('dropbox.app_key', '');
        $rawSecret = (string) settings_get('dropbox.app_secret', '');
        $appSecret = str_starts_with($rawSecret, 'ENC:')
            ? (self::decrypt($rawSecret) ?? '')
            : $rawSecret;

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
                'client_id'     => $appKey,
                'client_secret' => $appSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT    => 30,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '') {
            throw new RuntimeException("DropboxClient: code exchange cURL error: {$curlErr}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("DropboxClient: code exchange failed (HTTP {$httpCode}): {$body}");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['refresh_token'])) {
            throw new RuntimeException('DropboxClient: code exchange returned malformed body.');
        }

        return $data;
    }

    // ── Encryption helpers (AES-256-CBC, ENC: prefix — same format as MfaService) ──

    /**
     * Encrypts a plain-text value for DB storage.
     * Format: 'ENC:' + base64(random_iv[16] . ciphertext)
     */
    public static function encrypt(string $plain): string
    {
        $key    = self::encryptionKey();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return 'ENC:' . base64_encode($iv . $cipher);
    }

    /**
     * Decrypts a stored ENC:-prefixed value.
     * Returns null if missing, not ENC:-prefixed, malformed, or decryption fails.
     */
    public static function decrypt(?string $stored): ?string
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

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Single-shot upload (file ≤ SINGLE_SHOT_MAX) via /files/upload.
     * Reads the entire file into memory — acceptable for ≤140 MB.
     */
    private function uploadSingleShot(string $localPath, string $dropboxPath): void
    {
        $token   = $this->getAccessToken();
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new RuntimeException("DropboxClient: cannot read '{$localPath}'.");
        }

        $ch = curl_init(self::CONTENT_URL . '/files/upload');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $content,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode([
                    'path'       => $dropboxPath,
                    'mode'       => 'overwrite',
                    'autorename' => false,
                ]),
            ],
            CURLOPT_TIMEOUT => 300,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);
        unset($content);

        if ($curlErr !== '') {
            throw new RuntimeException("DropboxClient: upload cURL error: {$curlErr}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("DropboxClient: upload failed (HTTP {$httpCode}): {$body}");
        }
    }

    /**
     * Upload session (file > SINGLE_SHOT_MAX) — start / append(s) / finish.
     * Reads 32 MB at a time so memory stays bounded regardless of file size.
     */
    private function uploadSession(string $localPath, string $dropboxPath, int $fileSize): void
    {
        $token = $this->getAccessToken();
        $fh    = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException("DropboxClient: cannot open '{$localPath}'.");
        }

        try {
            $firstChunk = (string) fread($fh, self::CHUNK_SIZE);
            $sessionId  = $this->uploadSessionStart($token, $firstChunk);
            $offset     = strlen($firstChunk);

            while ($offset < $fileSize) {
                $toRead = min(self::CHUNK_SIZE, $fileSize - $offset);
                $chunk  = (string) fread($fh, $toRead);
                $isLast = ($offset + strlen($chunk)) >= $fileSize;

                if ($isLast) {
                    $this->uploadSessionFinish($token, $chunk, $sessionId, $offset, $dropboxPath);
                } else {
                    $this->uploadSessionAppend($token, $chunk, $sessionId, $offset);
                }
                $offset += strlen($chunk);
            }
        } finally {
            fclose($fh);
        }
    }

    /** Starts an upload session with the first chunk; returns the session_id. */
    private function uploadSessionStart(string $token, string $chunk): string
    {
        $ch = curl_init(self::CONTENT_URL . '/files/upload_session/start');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $chunk,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode(['close' => false]),
            ],
            CURLOPT_TIMEOUT => 300,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '' || $httpCode >= 400) {
            throw new RuntimeException("DropboxClient: upload_session/start failed (HTTP {$httpCode}): {$curlErr}{$body}");
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['session_id'])) {
            throw new RuntimeException('DropboxClient: upload_session/start returned malformed body.');
        }

        return (string) $data['session_id'];
    }

    /** Appends a middle chunk to an in-progress upload session. */
    private function uploadSessionAppend(string $token, string $chunk, string $sessionId, int $offset): void
    {
        $ch = curl_init(self::CONTENT_URL . '/files/upload_session/append_v2');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $chunk,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode([
                    'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                    'close'  => false,
                ]),
            ],
            CURLOPT_TIMEOUT => 300,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '' || $httpCode >= 400) {
            throw new RuntimeException("DropboxClient: upload_session/append failed (HTTP {$httpCode}): {$curlErr}{$body}");
        }
    }

    /** Sends the final chunk and commits the upload session to the target path. */
    private function uploadSessionFinish(string $token, string $chunk, string $sessionId, int $offset, string $dropboxPath): void
    {
        $ch = curl_init(self::CONTENT_URL . '/files/upload_session/finish');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $chunk,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode([
                    'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                    'commit' => [
                        'path'       => $dropboxPath,
                        'mode'       => 'overwrite',
                        'autorename' => false,
                    ],
                ]),
            ],
            CURLOPT_TIMEOUT => 300,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '' || $httpCode >= 400) {
            throw new RuntimeException("DropboxClient: upload_session/finish failed (HTTP {$httpCode}): {$curlErr}{$body}");
        }
    }

    /**
     * POSTs to the Dropbox API and returns the raw response body.
     *
     * @param array|null $params JSON-encodable body (null → empty body for no-arg endpoints).
     *
     * @throws RuntimeException on network error or non-200 response.
     */
    private function apiPost(string $endpoint, ?array $params, string $token): string
    {
        $ch = curl_init(self::API_URL . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params !== null ? json_encode($params) : '',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        [$body, $httpCode, $curlErr] = self::curlExec($ch);

        if ($curlErr !== '') {
            throw new RuntimeException("DropboxClient: API {$endpoint} cURL error: {$curlErr}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("DropboxClient: API {$endpoint} failed (HTTP {$httpCode}): {$body}");
        }

        return $body;
    }

    /**
     * Executes a cURL handle and returns [body, httpCode, curlError].
     * Always closes the handle.
     *
     * @return array{string, int, string}
     */
    private static function curlExec($ch): array
    {
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        return [$body === false ? '' : (string) $body, $httpCode, $curlErr];
    }

    /**
     * Derives a 32-byte AES key from APP_SECRET using the same approach as MfaService:
     * 64-char hex string → hex2bin; otherwise SHA-256.
     */
    private static function encryptionKey(): string
    {
        $key = APP_SECRET;
        if ($key === '') {
            throw new RuntimeException('DropboxClient: APP_SECRET is not set — cannot encrypt credentials.');
        }
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            return hex2bin($key);
        }
        return hash('sha256', $key, true);
    }
}
