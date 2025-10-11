<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Small helper for generating consistent, high-resolution timestamps.
 */
final class Clock
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';
    private static float $lastEpochSeconds = 0.0;

    /**
     * Returns the current time in UTC including microseconds.
     */
    public static function nowString(): string
    {
        $now = microtime(true);

        if ($now <= self::$lastEpochSeconds) {
            $now = self::$lastEpochSeconds + 0.000001;
        }

        self::$lastEpochSeconds = $now;

        $seconds = (int) $now;
        $micro = (int) round(($now - $seconds) * 1_000_000);

        if ($micro >= 1_000_000) {
            $seconds += 1;
            $micro -= 1_000_000;
            self::$lastEpochSeconds = $seconds + ($micro / 1_000_000);
        }

        $dateTime = DateTimeImmutable::createFromFormat('U u', sprintf('%d %06d', $seconds, $micro), new DateTimeZone('UTC'));

        if ($dateTime === false) {
            $dateTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $dateTime->format(self::TIMESTAMP_FORMAT);
    }
}
