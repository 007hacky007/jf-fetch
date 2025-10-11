<?php

declare(strict_types=1);

namespace App\Providers;

use RuntimeException;

/**
 * Normalizes provider configuration payloads before persistence.
 */
final class ProviderConfig
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function prepare(string $key, array $config): array
    {
        $normalizedKey = strtolower($key);

        return match ($normalizedKey) {
            'webshare' => self::prepareWebshare($config),
            default => $config,
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function prepareWebshare(array $config): array
    {
        $username = isset($config['username']) ? trim((string) $config['username']) : '';
        $password = isset($config['password']) ? (string) $config['password'] : '';
        $token = isset($config['wst']) ? trim((string) $config['wst']) : '';

        if ($token !== '') {
            // Token already provided; keep as-is so existing setups continue to work.
            return array_merge($config, ['wst' => $token]);
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('Webshare username and password are required.');
        }

        $token = WebshareProvider::fetchToken($username, $password);

        $config['username'] = $username;
        $config['password'] = $password;
        $config['wst'] = $token;

        return $config;
    }
}
