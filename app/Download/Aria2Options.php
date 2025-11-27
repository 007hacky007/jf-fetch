<?php

declare(strict_types=1);

namespace App\Download;

use App\Infra\Config;

/**
 * Helper for building aria2 option payloads driven by configuration.
 */
final class Aria2Options
{
    private const BYTES_IN_MEGABYTE = 1024 * 1024;

    private function __construct()
    {
    }

    /**
     * Applies the configured per-download speed cap (if any) to the provided options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function applySpeedLimit(array $options): array
    {
        $limit = self::maxDownloadLimitBytes();
        if ($limit !== null) {
            $options['max-download-limit'] = (string) $limit;
        }

        return $options;
    }

    /**
     * Resolves the configured aria2 per-download limit in bytes per second.
     */
    public static function maxDownloadLimitBytes(): ?int
    {
        if (!Config::has('aria2.max_speed_mb_s')) {
            return null;
        }

        $value = Config::get('aria2.max_speed_mb_s');
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        $megabytesPerSecond = (float) $value;
        if ($megabytesPerSecond <= 0) {
            return null;
        }

        $bytesPerSecond = (int) floor($megabytesPerSecond * self::BYTES_IN_MEGABYTE);
        if ($bytesPerSecond <= 0) {
            return null;
        }

        return $bytesPerSecond;
    }
}
