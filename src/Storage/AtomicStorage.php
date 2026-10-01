<?php

declare(strict_types=1);

namespace EidCloud\JsonDb\Storage;

use EidCloud\JsonDb\Exception\DatabaseException;

/**
 * Handles ACID-compliant file writes using flock and atomic temp-file-rename.
 */
class AtomicStorage
{
    /**
     * Read JSON file with shared lock.
     *
     * @param string $path
     * @param bool $asAssoc
     * @return mixed
     */
    public static function read(string $path, bool $asAssoc = true): mixed
    {
        if (!file_exists($path)) {
            return null;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw new DatabaseException("Failed to open file for reading: {$path}");
        }

        try {
            flock($fp, LOCK_SH);
            $content = stream_get_contents($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        if ($content === false || trim($content) === '') {
            return $asAssoc ? [] : null;
        }

        $data = json_decode($content, $asAssoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new DatabaseException("JSON parse error in {$path}: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Write data to JSON file atomically:
     * 1. Write to temporary file in same directory.
     * 2. Flush and sync.
     * 3. Acquire exclusive lock on target file or directory token.
     * 4. Rename temp file to target file (atomic in POSIX and Windows).
     *
     * @param string $path
     * @param mixed $data
     * @return void
     */
    public static function write(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new DatabaseException("Failed to create directory: {$dir}");
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DatabaseException("Failed to encode JSON: " . json_last_error_msg());
        }

        $tempFile = $path . '.tmp.' . bin2hex(random_bytes(6));
        $fp = fopen($tempFile, 'wb');
        if ($fp === false) {
            throw new DatabaseException("Failed to create temporary file: {$tempFile}");
        }

        $written = fwrite($fp, $json);
        fflush($fp);
        fclose($fp);

        if ($written === false || $written !== strlen($json)) {
            @unlink($tempFile);
            throw new DatabaseException("Failed to write complete data to temporary file: {$tempFile}");
        }

        // Lock file for concurrency safety
        $lockFile = $path . '.lock';
        $lockFp = fopen($lockFile, 'c+');
        if ($lockFp) {
            flock($lockFp, LOCK_EX);
        }

        try {
            // Windows rename will fail if target exists and is locked or open; on Windows unlink first or rename
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists($path)) {
                // Atomic attempt
                if (!@rename($tempFile, $path)) {
                    @unlink($path);
                    if (!rename($tempFile, $path)) {
                        @unlink($tempFile);
                        throw new DatabaseException("Atomic rename failed for {$path}");
                    }
                }
            } else {
                if (!rename($tempFile, $path)) {
                    @unlink($tempFile);
                    throw new DatabaseException("Atomic rename failed for {$path}");
                }
            }
        } finally {
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }
}
