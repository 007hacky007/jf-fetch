<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Persistence helper for application settings stored in the database.
 */
final class Settings
{
    /**
     * Returns all stored settings as a map of dot-notation keys to typed values.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        try {
            $statement = Db::run('SELECT key, value, type FROM settings');
        } catch (Throwable) {
            return [];
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return [];
        }

        $settings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['key'], $row['value'], $row['type'])) {
                continue;
            }

            /** @var string $key */
            $key = $row['key'];
            $settings[$key] = self::castFromStorage($row['value'], $row['type']);
        }

        return $settings;
    }

    /**
     * Persists multiple settings using an UPSERT strategy.
     *
     * @param array<string, mixed> $settings
     */
    public static function setMany(array $settings): void
    {
        if ($settings === []) {
            return;
        }

        Db::transaction(static function () use ($settings): void {
            $pdo = Db::connection();
            $sql = 'INSERT INTO settings (key, value, type, updated_at) VALUES (:key, :value, :type, :updated_at)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, type = excluded.type, updated_at = excluded.updated_at';
            $statement = $pdo->prepare($sql);

            $now = (new DateTimeImmutable())->format('c');

            foreach ($settings as $key => $value) {
                [$storedValue, $type] = self::prepareForStorage($value);
                $statement->execute([
                    'key' => $key,
                    'value' => $storedValue,
                    'type' => $type,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Stores a single setting key/value pair.
     */
    public static function set(string $key, mixed $value): void
    {
        self::setMany([$key => $value]);
    }

    /**
     * Converts a stored string value back into its native PHP type.
     */
    private static function castFromStorage(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => $value === '1',
            default => $value,
        };
    }

    /**
     * Normalises PHP values for storage in the database.
     *
     * @return array{0:string,1:string}
     */
    private static function prepareForStorage(mixed $value): array
    {
        if (is_int($value)) {
            return [ (string) $value, 'int' ];
        }

        if (is_float($value)) {
            return [ sprintf('%F', $value), 'float' ];
        }

        if (is_bool($value)) {
            return [ $value ? '1' : '0', 'bool' ];
        }

        if ($value === null) {
            return ['', 'string'];
        }

        return [ (string) $value, 'string' ];
    }
}
