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
//   STORAGE_DRIVER=local → FF_ROOT/storage/ (development)
//   STORAGE_DRIVER=s3    → Amazon S3 (production)
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

        return env('STORAGE_DRIVER', 'local') === 's3'
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

        return env('STORAGE_DRIVER', 'local') === 's3'
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

        return env('STORAGE_DRIVER', 'local') === 's3'
            ? self::deleteS3($key)
            : self::deleteLocal($key);
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

    // ----------------------------------------------------------
    // S3 DRIVER — private implementation
    // ----------------------------------------------------------

    private static function s3(): S3Client
    {
        if (self::$s3Instance !== null) {
            return self::$s3Instance;
        }

        self::$s3Instance = new S3Client([
            'version'     => 'latest',
            'region'      => (string) env('AWS_REGION', 'us-west-2'),
            'credentials' => [
                'key'    => (string) env('AWS_ACCESS_KEY_ID', ''),
                'secret' => (string) env('AWS_SECRET_ACCESS_KEY', ''),
            ],
        ]);

        return self::$s3Instance;
    }

    private static function bucket(): string
    {
        return (string) env('AWS_S3_BUCKET', '');
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
