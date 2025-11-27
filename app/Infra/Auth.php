<?php

declare(strict_types=1);

namespace App\Infra;

use DateTimeImmutable;
use RuntimeException;

/**
 * Session-backed authentication and authorization utilities.
 *
 * Responsibilities:
 * - Start and configure the PHP session used across the application.
 * - Handle user login/logout and persist the authenticated identity.
 * - Provide guard helpers for role-based access control (RBAC).
 * - Offer convenience methods to inspect the current authenticated user.
 */
final class Auth
{
    private const SESSION_KEY = 'auth.user';

    /**
     * Boots the PHP session using configuration-driven values.
     */
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = Config::get('app.session_name');
        if (!is_string($sessionName) || $sessionName === '') {
            throw new RuntimeException('Configuration key app.session_name must be a non-empty string.');
        }

        $sessionPath = self::resolveSessionPath();
        self::ensureSessionDirectory($sessionPath);
        session_save_path($sessionPath);

        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Attempts to authenticate a user with the provided credentials.
     *
     * @param string $email User email address.
     * @param string $password Plaintext password provided by the user.
     *
     * @return array{id:int,name:string,email:string,role:string,created_at:string,updated_at:string}|null
     */
    public static function attempt(string $email, string $password): ?array
    {
        self::boot();

        $user = self::findUserByEmail($email);
        if ($user === null) {
            return null;
        }

        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        if (password_verify($password, $hash) === false) {
            return null;
        }

        // Regenerate session ID to avoid fixation attacks.
        session_regenerate_id(true);

        $identity = [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'created_at' => (string) $user['created_at'],
            'updated_at' => (string) $user['updated_at'],
            'last_login_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $_SESSION[self::SESSION_KEY] = $identity;

        return $identity;
    }

    /**
     * Logs out the current user and destroys the session.
     */
    public static function logout(): void
    {
        self::boot();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
    }

    /**
     * Returns the authenticated user payload.
     *
     * @return array{id:int,name:string,email:string,role:string,created_at:string,updated_at:string,last_login_at:string}|null
     */
    public static function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;

        return is_array($user) ? $user : null;
    }

    /**
     * Returns true when a user is authenticated.
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Ensures a user is authenticated; throws otherwise.
     */
    public static function requireUser(): void
    {
        if (!self::check()) {
            throw new RuntimeException('Authentication required.');
        }
    }

    /**
     * Ensures the authenticated user has the given role(s).
     *
     * @param string|array<int, string> $roles Allowed role or roles.
     */
    public static function requireRole(string|array $roles): void
    {
        self::requireUser();

        $roles = is_array($roles) ? $roles : [$roles];
        $role = self::user()['role'] ?? null;

        if (!in_array($role, $roles, true)) {
            throw new RuntimeException('Insufficient permissions.');
        }
    }

    /**
     * Returns true when the authenticated user is an administrator.
     */
    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && ($user['role'] ?? null) === 'admin';
    }

    /**
     * Ensures the authenticated user is either admin or owns the provided user ID.
     */
    public static function requireUserOrAdmin(int $userId): void
    {
        self::requireUser();

        $user = self::user();
        if ($user === null) {
            throw new RuntimeException('Authentication required.');
        }

        if (self::isAdmin()) {
            return;
        }

        if ((int) $user['id'] !== $userId) {
            throw new RuntimeException('Insufficient permissions.');
        }
    }

    /**
     * Clears all authentication state (useful for tests).
     */
    public static function reset(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::logout();
        }

        $_SESSION = [];
    }

    /**
     * Finds a user by email address.
     *
     * @return array<string, mixed>|null
     */
    private static function findUserByEmail(string $email): ?array
    {
        $statement = Db::run('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        $result = $statement->fetch();

        return $result !== false ? $result : null;
    }

    private static function resolveSessionPath(): string
    {
        $path = null;
        if (Config::has('app.session_path')) {
            $candidate = Config::get('app.session_path');
            if (is_string($candidate) && $candidate !== '') {
                $path = $candidate;
            }
        }

        return $path ?? (sys_get_temp_dir() . '/jf-fetch-sessions');
    }

    private static function ensureSessionDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create session directory: ' . $path);
        }
    }
}
