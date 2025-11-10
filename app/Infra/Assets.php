<?php

declare(strict_types=1);

namespace App\Infra;

use function filesize;
use function filemtime;
use function hash;
use function implode;
use function is_file;
use function substr;

/**
 * Utilities for dealing with build assets (cache-busting version etc.).
 */
final class Assets
{
    private const FALLBACK_VERSION = 'dev';

    private function __construct()
    {
    }

    public static function version(): string
    {
        $candidates = [
            __DIR__ . '/../../public/ui/app.js',
            __DIR__ . '/../../public/ui/styles.css',
            __DIR__ . '/../../public/ui/index.html',
        ];

        $fingerprintParts = [];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = @filemtime($file);
            $size = @filesize($file);

            if ($mtime !== false) {
                $fingerprintParts[] = (string) $mtime;
            }

            if ($size !== false) {
                $fingerprintParts[] = (string) $size;
            }
        }

        if ($fingerprintParts === []) {
            return self::FALLBACK_VERSION;
        }

        $fingerprint = implode('|', $fingerprintParts);
        $hash = hash('sha256', $fingerprint, false);

        return $hash !== '' ? substr($hash, 0, 12) : self::FALLBACK_VERSION;
    }
}
