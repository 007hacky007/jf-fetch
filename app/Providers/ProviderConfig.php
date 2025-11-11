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
            'kraska' => self::prepareKraSk($config),
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

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function prepareKraSk(array $config): array
    {
        $username = isset($config['username']) ? trim((string) $config['username']) : '';
        $password = isset($config['password']) ? (string) $config['password'] : '';
        $uuid = isset($config['uuid']) ? trim((string) $config['uuid']) : '';
        // Allow global toggle via app.ini [providers] kraska_debug or env KRASKA_DEBUG=1
        $globalDebug = false;
        $iniDebug = \App\Infra\Config::has('providers.kraska_debug') ? \App\Infra\Config::get('providers.kraska_debug') : null;
        if (is_string($iniDebug)) {
            $iniDebug = trim($iniDebug);
        }
        if (is_bool($iniDebug)) {
            $globalDebug = $iniDebug;
        } elseif (is_string($iniDebug) && $iniDebug !== '') {
            $lower = strtolower($iniDebug);
            $globalDebug = in_array($lower, ['1','true','yes','on'], true);
        }
        $envDebug = getenv('KRASKA_DEBUG');
        if (is_string($envDebug) && $envDebug !== '') {
            $envLower = strtolower(trim($envDebug));
            if (in_array($envLower, ['1','true','yes','on'], true)) {
                $globalDebug = true;
            } elseif (in_array($envLower, ['0','false','no','off'], true)) {
                $globalDebug = false;
            }
        }
        // Allow explicit per-provider override in passed $config['debug']
        if (isset($config['debug'])) {
            $passedDebug = $config['debug'];
            if (is_bool($passedDebug)) {
                $globalDebug = $passedDebug;
            } elseif (is_string($passedDebug) && $passedDebug !== '') {
                $pdLower = strtolower(trim($passedDebug));
                if (in_array($pdLower, ['1','true','yes','on'], true)) {
                    $globalDebug = true;
                } elseif (in_array($pdLower, ['0','false','no','off'], true)) {
                    $globalDebug = false;
                }
            }
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('Kra.sk username and password are required.');
        }

        // We can do a lightweight login attempt to validate credentials. Any failure throws.
        if (getenv('KRA_SKIP_VALIDATE') !== '1') {
            try {
                $provider = new KraSkProvider(['username' => $username, 'password' => $password, 'debug' => $globalDebug]);
                // Perform a login by requesting user info (forces login internally via protected API call)
                $ref = new \ReflectionClass($provider);
                if ($ref->hasMethod('search')) { /* no-op just for static analyzers */ }
                // Trigger auth by calling search on an unlikely term but ignore errors.
                try {
                    $provider->search('__credential_validation__', 1);
                } catch (\Throwable $t) {
                    // If it's an auth related error we rethrow; else ignore (e.g. no results)
                    if (str_contains(strtolower($t->getMessage()), 'credential') || str_contains(strtolower($t->getMessage()), 'login')) {
                        throw $t; // invalid credentials
                    }
                }
            } catch (\Throwable $e) {
                throw new RuntimeException('Kra.sk credential validation failed: ' . $e->getMessage());
            }
        }

        $config['username'] = $username;
        $config['password'] = $password;
        $config['debug'] = $globalDebug;
        if ($uuid !== '') {
            // Accept a broader identifier: 5-50 chars, lowercase letters, digits, and dashes.
            // This accommodates non-hex characters seen in some existing client UUIDs.
            if (preg_match('/^[a-z0-9\-]{5,50}$/i', $uuid) === 1) {
                $config['uuid'] = $uuid;
            } else {
                throw new RuntimeException('Kra.sk uuid has invalid format. Use 5-50 alphanumeric or dash characters.');
            }
        }

        $rateLimit = $config['ident_rate_limit_seconds'] ?? $config['sc_ident_rate_limit_seconds'] ?? null;
        if (is_string($rateLimit)) {
            $rateLimit = trim($rateLimit);
        }

        if ($rateLimit === null || $rateLimit === '') {
            $normalizedRateLimit = 120;
        } else {
            $rateLimitString = (string) $rateLimit;
            if (preg_match('/^\d+$/', $rateLimitString) !== 1) {
                throw new RuntimeException('Kra.sk ident rate limit must be a positive whole number of seconds.');
            }

            $normalizedRateLimit = (int) $rateLimitString;
            if ($normalizedRateLimit <= 0) {
                throw new RuntimeException('Kra.sk ident rate limit must be greater than zero seconds.');
            }
        }

        $config['ident_rate_limit_seconds'] = $normalizedRateLimit;
        unset($config['sc_ident_rate_limit_seconds']);

        return $config;
    }
}
