<?php
declare(strict_types=1);

namespace FleetForge\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use InvalidArgumentException;
use RuntimeException;

// ============================================================
// FleetForge — Storage Abstraction Layer [INFRA / D9]
//
// Single interface for file storage in both environments:
//   storage.driver = local → FF_ROOT/storage/ (development)
//   storage.driver = s3    → Amazon S3 (production)
//
// Credentials lookup order [INT-1]:
//   1. settings table FIRST  — settings_get('storage.driver' / 'aws.*')
//   2. .env file  SECOND     — env('STORAGE_DRIVER' / 'AWS_*')
// Settings → Integrations UI saves rows, runtime reads them
// without a redeploy. Empty/missing setting → fall through.
//
// RULES:
//   • NEVER call move_uploaded_file() directly in business logic.
//   • NEVER expose server file paths in API responses (Trap 7).
//   • ALWAYS use StorageClient::upload() for all file writes.
//   • ALWAYS use StorageClient::url()    for all file reads.
//   • ALWAYS use StorageClient::delete() for all file removals.
//
// The $storagePath argument uses forward-slash separated segments
// that are meaningful to the app, e.g.:
//   customers/42/contract_signed_1712345678.pdf
//   equipment/8/cvi_1712345678.pdf
//   generated/pdfs/INV-2025-00012.pdf
// ============================================================

class StorageClient
{
    /** @var S3Client|null Singleton — created once per process */
    private static ?S3Client $s3Instance = null;

    // Prevent instantiation — all methods are static
    private function __construct() {}

    // ============================================================
    // upload() — store a file and return its storage key
    //
    // $tmpPath     — absolute path to the source file
    //                (from $_FILES['field']['tmp_name'] or a generated file)
    // $storagePath — logical destination path (no leading slash)
    //                e.g. 'customers/42/doc_1234.pdf'
    //
    // Returns the storage key string (same as $storagePath).
    // Throws RuntimeException on failure.
    // ============================================================
    public static function upload(string $tmpPath, string $storagePath): string
    {
        self::validateStoragePath($storagePath);

        return self::driver() === 's3'
            ? self::uploadS3($tmpPath, $storagePath)
            : self::uploadLocal($tmpPath, $storagePath);
    }

    // ============================================================
    // url() — return a time-limited URL for accessing a stored file
    //
    // $key    — the storage key returned by upload()
    // $expiry — seconds until the URL expires (default 1 hour)
    //
    // Local:  HMAC-signed URL → api/v1/storage/serve.php validates it
    // S3:     AWS pre-signed URL generated via SDK
    // ============================================================
    public static function url(string $key, int $expiry = 3600): string
    {
        self::validateStoragePath($key);

        return self::driver() === 's3'
            ? self::urlS3($key, $expiry)
            : self::urlLocal($key, $expiry);
    }

    // ============================================================
    // delete() — remove a file from storage
    //
    // Returns true on success, false if the file did not exist.
    // Throws RuntimeException on unexpected errors.
    // ============================================================
    public static function delete(string $key): bool
    {
        self::validateStoragePath($key);

        return self::driver() === 's3'
            ? self::deleteS3($key)
            : self::deleteLocal($key);
    }

    // ============================================================
    // exists() — check whether a stored file exists
    //
    // Returns false if the file does not exist (does not throw).
    // ============================================================
    public static function exists(string $key): bool
    {
        self::validateStoragePath($key);
        return self::driver() === 's3'
            ? self::existsS3($key)
            : self::existsLocal($key);
    }

    // ============================================================
    // fileSize() — return the byte size of a stored file.
    //
    // Returns 0 if the file does not exist.
    // ============================================================
    public static function fileSize(string $key): int
    {
        self::validateStoragePath($key);
        return self::driver() === 's3'
            ? self::fileSizeS3($key)
            : self::fileSizeLocal($key);
    }

    // ============================================================
    // download() — download a stored file to a local filesystem path
    //
    // $key      — the storage key (as returned by upload())
    // $destPath — absolute filesystem path where the file should be saved
    //
    // Throws RuntimeException on failure (file not found, cURL error,
    // write error, etc.).
    // ============================================================
    public static function download(string $key, string $destPath): void
    {
        self::validateStoragePath($key);
        if (self::driver() === 's3') {
            self::downloadS3($key, $destPath);
        } else {
            self::downloadLocal($key, $destPath);
        }
    }

    // ============================================================
    // read() — return the raw bytes of a stored file.
    //
    // $key — the storage key (as returned by upload()).
    //
    // Returns the full file contents as a binary string, or null if
    // the file does not exist. This is the driver-agnostic way to
    // pull a stored file into memory (e.g. to build an email MIME
    // attachment) without round-tripping through a temp file.
    //
    // Throws RuntimeException only on unexpected backend errors —
    // a plain "not found" returns null so callers can skip cleanly.
    // ============================================================
    public static function read(string $key): ?string
    {
        self::validateStoragePath($key);
        return self::driver() === 's3'
            ? self::readS3($key)
            : self::readLocal($key);
    }

    // ============================================================
    // listByPrefix() — list all stored files under a path prefix.
    //
    // Returns array of:
    //   ['key' => string, 'size' => int, 'last_modified' => string]
    // 'last_modified' is a UTC datetime string (Y-m-d H:i:s).
    // An empty prefix lists everything under the storage root.
    // ============================================================
    public static function listByPrefix(string $prefix): array
    {
        $checkPath = rtrim($prefix, '/');
        if ($checkPath !== '') {
            self::validateStoragePath($checkPath);
        }
        return self::driver() === 's3'
            ? self::listByPrefixS3($prefix)
            : self::listByPrefixLocal($prefix);
    }

    // ============================================================
    // driver() — resolve the active storage driver
    //
    // INT-1: settings table FIRST, .env SECOND. Returns 'local'
    // by default so a fresh install never tries to talk to S3.
    // ============================================================
    private static function driver(): string
    {
        $driver = (string) (
            settings_get('storage.driver')
            ?: env('STORAGE_DRIVER', 'local')
        );
        return $driver === 's3' ? 's3' : 'local';
    }

    // ============================================================
    // verifyLocalUrl() — validate a signed local storage URL
    //
    // Called by api/v1/storage/serve.php before serving a file.
    // Returns the storage key if valid, null if invalid/expired.
    // ============================================================
    public static function verifyLocalUrl(string $key, int $exp, string $sig): ?string
    {
        if (time() > $exp) return null;

        $secret = APP_SECRET;
        if ($secret === '') return null; // APP_SECRET not configured

        $expected = hash_hmac('sha256', $key . ':' . $exp, $secret);
        if (!hash_equals($expected, $sig)) return null;

        return $key;
    }

    // ============================================================
    // localPath() — return the absolute filesystem path for a key
    //
    // Used only by the local serve endpoint — not in business logic.
    // ============================================================
    public static function localPath(string $key): string
    {
        self::validateStoragePath($key);
        return FF_ROOT . '/storage/' . $key;
    }

    // ----------------------------------------------------------
    // LOCAL DRIVER — private implementation
    // ----------------------------------------------------------

    private static function uploadLocal(string $tmpPath, string $storagePath): string
    {
        $dest = FF_ROOT . '/storage/' . $storagePath;
        $dir  = dirname($dest);

        // Create the directory tree if needed
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    "StorageClient: could not create directory '{$dir}'."
                );
            }
        }

        // Use copy + unlink (not rename) to handle cross-device moves
        // (PHP tmp dir and project dir may be on different filesystems)
        if (!copy($tmpPath, $dest)) {
            throw new RuntimeException(
                "StorageClient: could not copy '{$tmpPath}' to '{$dest}'."
            );
        }

        // Remove the temp file if it still exists (it may have been moved already)
        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        return $storagePath;
    }

    private static function urlLocal(string $key, int $expiry): string
    {
        $exp    = time() + $expiry;
        $secret = APP_SECRET;

        if ($secret === '') {
            // APP_SECRET not set — return a non-expiring URL with a warning
            // This should never happen in production.
            error_log('[StorageClient] APP_SECRET is empty — URL will not be signed.');
            $sig = 'unsigned';
        } else {
            $sig = hash_hmac('sha256', $key . ':' . $exp, $secret);
        }

        return base_url('api/v1/storage/serve') . '?' . http_build_query([
            'key' => $key,
            'exp' => $exp,
            'sig' => $sig,
        ]);
    }

    private static function deleteLocal(string $key): bool
    {
        $path = FF_ROOT . '/storage/' . $key;

        if (!file_exists($path)) {
            return false; // already gone — treat as success
        }

        if (!unlink($path)) {
            throw new RuntimeException(
                "StorageClient: could not delete '{$path}'."
            );
        }

        return true;
    }

    private static function existsLocal(string $key): bool
    {
        return file_exists(FF_ROOT . '/storage/' . $key);
    }

    private static function fileSizeLocal(string $key): int
    {
        $path = FF_ROOT . '/storage/' . $key;
        if (!file_exists($path)) return 0;
        $size = filesize($path);
        return $size === false ? 0 : (int) $size;
    }

    private static function listByPrefixLocal(string $prefix): array
    {
        $baseDir = FF_ROOT . '/storage/' . $prefix;
        $results = [];

        if (!is_dir($baseDir)) {
            return $results;
        }

        $storageBase    = FF_ROOT . '/storage/';
        $storageBaseLen = strlen($storageBase);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            if (!str_starts_with($realPath, $storageBase)) continue;
            $results[] = [
                'key'           => str_replace('\\', '/', substr($realPath, $storageBaseLen)),
                'size'          => (int) $file->getSize(),
                'last_modified' => gmdate('Y-m-d H:i:s', $file->getMTime()),
            ];
        }

        return $results;
    }

    private static function downloadLocal(string $key, string $destPath): void
    {
        $src = FF_ROOT . '/storage/' . $key;
        if (!file_exists($src)) {
            throw new RuntimeException(
                "StorageClient: local file not found for key '{$key}'."
            );
        }
        if (!copy($src, $destPath)) {
            throw new RuntimeException(
                "StorageClient: could not copy '{$src}' to '{$destPath}'."
            );
        }
    }

    private static function readLocal(string $key): ?string
    {
        $path = FF_ROOT . '/storage/' . $key;
        if (!is_file($path)) {
            return null; // missing file → null (not an error)
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException(
                "StorageClient: could not read local file for key '{$key}'."
            );
        }
        return $data;
    }

    // ----------------------------------------------------------
    // S3 DRIVER — private implementation
    // ----------------------------------------------------------

    private static function s3(): S3Client
    {
        if (self::$s3Instance !== null) {
            return self::$s3Instance;
        }

        // INT-1: settings table FIRST, .env SECOND.
        $region = (string) (
            settings_get('aws.region')
            ?: env('AWS_REGION', 'us-west-2')
        );
        $key = (string) (
            settings_get('aws.access_key_id')
            ?: env('AWS_ACCESS_KEY_ID', '')
        );
        $secret = (string) (
            settings_get('aws.secret_access_key')
            ?: env('AWS_SECRET_ACCESS_KEY', '')
        );

        self::$s3Instance = new S3Client([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ]);

        return self::$s3Instance;
    }

    private static function bucket(): string
    {
        // INT-1: settings table FIRST, .env SECOND.
        return (string) (
            settings_get('aws.s3_bucket')
            ?: env('AWS_S3_BUCKET', '')
        );
    }

    private static function uploadS3(string $tmpPath, string $storagePath): string
    {
        try {
            self::s3()->putObject([
                'Bucket'     => self::bucket(),
                'Key'        => $storagePath,
                'SourceFile' => $tmpPath,
                'ACL'        => 'private', // never public — always served via signed URLs
            ]);
        } catch (S3Exception $e) {
            throw new RuntimeException(
                "StorageClient S3 upload failed: " . $e->getMessage()
            );
        }

        // Remove tmp file
        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        return $storagePath;
    }

    private static function urlS3(string $key, int $expiry): string
    {
        try {
            $cmd     = self::s3()->getCommand('GetObject', [
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ]);
            $request = self::s3()->createPresignedRequest($cmd, '+' . $expiry . ' seconds');
            return (string) $request->getUri();
        } catch (S3Exception $e) {
            throw new RuntimeException(
                "StorageClient S3 presign failed: " . $e->getMessage()
            );
        }
    }

    private static function deleteS3(string $key): bool
    {
        try {
            // deleteObject does not throw if the key does not exist
            self::s3()->deleteObject([
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            throw new RuntimeException(
                "StorageClient S3 delete failed: " . $e->getMessage()
            );
        }
    }

    private static function existsS3(string $key): bool
    {
        try {
            self::s3()->headObject([
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) return false;
            throw new RuntimeException("StorageClient S3 head failed: " . $e->getMessage());
        }
    }

    private static function fileSizeS3(string $key): int
    {
        try {
            $result = self::s3()->headObject([
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ]);
            return (int) ($result['ContentLength'] ?? 0);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) return 0;
            throw new RuntimeException("StorageClient S3 head failed: " . $e->getMessage());
        }
    }

    private static function listByPrefixS3(string $prefix): array
    {
        $results = [];
        try {
            $paginator = self::s3()->getPaginator('ListObjectsV2', [
                'Bucket' => self::bucket(),
                'Prefix' => $prefix,
            ]);
            foreach ($paginator as $page) {
                foreach ($page['Contents'] ?? [] as $obj) {
                    $lastMod = $obj['LastModified'];
                    $modStr  = $lastMod instanceof \DateTimeInterface
                        ? $lastMod->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
                        : (string) $lastMod;
                    $results[] = [
                        'key'           => (string) $obj['Key'],
                        'size'          => (int)    $obj['Size'],
                        'last_modified' => $modStr,
                    ];
                }
            }
        } catch (S3Exception $e) {
            throw new RuntimeException("StorageClient S3 list failed: " . $e->getMessage());
        }
        return $results;
    }

    private static function downloadS3(string $key, string $destPath): void
    {
        try {
            self::s3()->getObject([
                'Bucket' => self::bucket(),
                'Key'    => $key,
                'SaveAs' => $destPath,
            ]);
        } catch (S3Exception $e) {
            throw new RuntimeException(
                "StorageClient S3 download failed: " . $e->getMessage()
            );
        }
    }

    private static function readS3(string $key): ?string
    {
        try {
            $result = self::s3()->getObject([
                'Bucket' => self::bucket(),
                'Key'    => $key,
            ]);
            $body = $result['Body'] ?? null;
            return $body !== null ? (string) $body : null;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) return null; // missing → null
            throw new RuntimeException(
                "StorageClient S3 read failed: " . $e->getMessage()
            );
        }
    }

    // ----------------------------------------------------------
    // Shared validation
    // ----------------------------------------------------------

    private static function validateStoragePath(string $path): void
    {
        // Must not be empty, start with /, or contain path traversal
        if (
            $path === '' ||
            str_starts_with($path, '/') ||
            str_contains($path, '..') ||
            str_contains($path, "\0")
        ) {
            throw new InvalidArgumentException(
                "StorageClient: invalid storage path '{$path}'."
            );
        }
    }
}
