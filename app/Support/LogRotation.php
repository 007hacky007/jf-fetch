<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Provides log file rotation functionality to prevent unbounded growth.
 */
final class LogRotation
{
    /**
     * Rotates a log file if it exists, keeping only the specified number of rotations.
     * 
     * Example: For file.log with maxRotations=5:
     * - file.log -> file.log.1
     * - file.log.1 -> file.log.2
     * - ...
     * - file.log.5 is deleted
     * 
     * @param string $logPath Absolute path to the log file
     * @param int $maxRotations Maximum number of rotations to keep (default: 5)
     * @param int $maxSizeBytes Maximum file size in bytes before rotation (default: 10MB)
     */
    public static function rotate(string $logPath, int $maxRotations = 5, int $maxSizeBytes = 10485760): void
    {
        if (!file_exists($logPath)) {
            return;
        }

        // Check if rotation is needed based on file size
        clearstatcache(true, $logPath);
        $fileSize = @filesize($logPath);
        if ($fileSize === false || $fileSize < $maxSizeBytes) {
            return;
        }

        // Remove the oldest rotation if it exists
        $oldestRotation = $logPath . '.' . $maxRotations;
        if (file_exists($oldestRotation)) {
            @unlink($oldestRotation);
        }

        // Shift existing rotations
        for ($i = $maxRotations - 1; $i >= 1; $i--) {
            $oldFile = $logPath . '.' . $i;
            $newFile = $logPath . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }

        // Rotate the current log file
        @rename($logPath, $logPath . '.1');
    }

    /**
     * Rotates all log files in a directory.
     * 
     * @param string $logDirectory Absolute path to the logs directory
     * @param int $maxRotations Maximum number of rotations to keep (default: 5)
     * @param int $maxSizeBytes Maximum file size in bytes before rotation (default: 10MB)
     */
    public static function rotateAll(string $logDirectory, int $maxRotations = 5, int $maxSizeBytes = 10485760): void
    {
        if (!is_dir($logDirectory)) {
            return;
        }

        $files = @scandir($logDirectory);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            // Skip directories and hidden files
            if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
                continue;
            }

            // Skip already rotated files (those with .N suffix)
            if (preg_match('/\.log\.\d+$/', $file)) {
                continue;
            }

            // Only process .log files
            if (!str_ends_with($file, '.log')) {
                continue;
            }

            $logPath = $logDirectory . DIRECTORY_SEPARATOR . $file;
            self::rotate($logPath, $maxRotations, $maxSizeBytes);
        }
    }
}
