<?php

declare(strict_types=1);

namespace App\Infra;

use RuntimeException;
use Throwable;

/**
 * Application configuration loader.
 *
 * Reads INI files from the config directory, merges them by section, and allows access via dot notation.
 * All configuration is required to be present in INI files; no defaults are applied in code.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $baseConfig = [];

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var array<string, mixed> */
    private static array $overrides = [];

    private static bool $booted = false;

    private function __construct()
    {
    }

    /**
     * Bootstraps configuration from the provided directory.
     *
     * @param string $configDirectory Absolute path to the configuration directory containing INI files.
     */
    public static function boot(string $configDirectory): void
    {
        if (self::$booted) {
            return;
        }

        $realPath = realpath($configDirectory);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException('Configuration directory not found: ' . $configDirectory);
        }

        $files = glob($realPath . '/*.ini');
        sort($files);

        $aggregated = [];

        foreach ($files as $file) {
            $parsed = parse_ini_file($file, true, INI_SCANNER_TYPED);
            if ($parsed === false) {
                throw new RuntimeException('Unable to parse configuration file: ' . $file);
            }

            foreach ($parsed as $section => $values) {
                if (!is_array($values)) {
                    throw new RuntimeException('Configuration section must be an array: ' . $section);
                }

                if (!array_key_exists($section, $aggregated)) {
                    $aggregated[$section] = [];
                }

                $aggregated[$section] = array_merge($aggregated[$section], $values);
            }
        }

        self::$baseConfig = $aggregated;
        self::$config = $aggregated;
        self::$overrides = [];
        self::$booted = true;

        self::reloadOverrides();
    }

    /**
     * Retrieves a configuration value using dot notation (section.key.subkey ...).
     *
     * @param string $key The configuration key to resolve.
     *
     * @return mixed The configuration value.
     */
    public static function get(string $key): mixed
    {
        self::assertBooted();

        return self::valueByPath(self::$config, $key);
    }

    /**
     * Returns all configuration data as a nested array.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::assertBooted();

        return self::$config;
    }

    /**
     * Checks if a configuration key exists.
     *
     * @param string $key Configuration key in dot notation.
     */
    public static function has(string $key): bool
    {
        if (!self::$booted) {
            return false;
        }

        try {
            self::valueByPath(self::$config, $key);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Resets the configuration (mainly used for tests).
     */
    public static function reset(): void
    {
        self::$baseConfig = [];
        self::$config = [];
        self::$overrides = [];
        self::$booted = false;
    }

    /**
     * Reloads configuration overrides from the database-backed settings store.
     */
    public static function reloadOverrides(): void
    {
        if (!self::$booted) {
            return;
        }

        try {
            $overrides = Settings::all();
        } catch (Throwable) {
            $overrides = [];
        }

        self::applyOverrides($overrides);
    }

    /**
     * Applies or updates a single override value at runtime.
     */
    public static function override(string $key, mixed $value): void
    {
        self::assertBooted();

        $overrides = self::$overrides;
        $overrides[$key] = $value;

        self::applyOverrides($overrides);
    }

    /**
     * Replaces the active configuration with the base config + overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private static function applyOverrides(array $overrides): void
    {
        self::$overrides = $overrides;
        self::$config = self::$baseConfig;

        foreach ($overrides as $key => $value) {
            self::setValueByPath(self::$config, $key, $value);
        }
    }

    /**
     * Reads a value from an array using dot-notation.
     */
    private static function valueByPath(array $source, string $key): mixed
    {
        $segments = explode('.', $key);
        $value = $source;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new RuntimeException('Configuration key not found: ' . $key);
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Writes a value into the provided array using dot-notation, creating sections on demand.
     */
    private static function setValueByPath(array &$target, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        if ($last === null) {
            return;
        }

        $cursor =& $target;
        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor =& $cursor[$segment];
        }

        $cursor[$last] = $value;
    }

    private static function assertBooted(): void
    {
        if (!self::$booted) {
            throw new RuntimeException('Configuration has not been booted yet.');
        }
    }
}
