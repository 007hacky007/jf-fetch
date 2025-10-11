<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infra\Db;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Domain-level helpers for working with application users.
 */
final class Users
{
    /**
     * Retrieves all users ordered alphabetically by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $statement = Db::run('SELECT id, name, email, role, created_at, updated_at FROM users ORDER BY name ASC');
        $rows = $statement->fetchAll();

        $users = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $users[] = self::format($row);
                }
            }
        }

        return $users;
    }

    /**
     * Creates a new user and returns the hydrated payload for API consumers.
     *
     * @param array<string, mixed> $payload JSON payload from the request body.
     *
     * @return array<string, mixed>
     */
    public static function create(array $payload): array
    {
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        $role = isset($payload['role']) ? strtolower(trim((string) $payload['role'])) : 'user';
        $password = isset($payload['password']) ? (string) $payload['password'] : '';

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid email address is required.');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            throw new RuntimeException('Role must be either "admin" or "user".');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters long.');
        }

        $existing = Db::run('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email])->fetch();
        if ($existing !== false) {
            throw new RuntimeException('Email address is already in use.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $timestamp = (new DateTimeImmutable())->format('c');

        try {
            Db::run(
                'INSERT INTO users (name, email, password_hash, role, created_at, updated_at) VALUES (:name, :email, :password_hash, :role, :created_at, :updated_at)',
                [
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $hash,
                    'role' => $role,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to create user: ' . $exception->getMessage(), previous: $exception);
        }

        $id = (int) Db::connection()->lastInsertId();
        $row = Db::run('SELECT id, name, email, role, created_at, updated_at FROM users WHERE id = :id', ['id' => $id])->fetch();

        $data = $row !== false ? $row : [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        return self::format($data);
    }

    /**
     * Updates an existing user record.
     *
     * @param array<string, mixed> $payload JSON payload from the request body.
     *
     * @return array<string, mixed>
     */
    public static function update(int $id, array $payload): array
    {
        if ($id <= 0) {
            throw new RuntimeException('User ID must be positive.');
        }

        $existing = Db::run('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if ($existing === false || !is_array($existing)) {
            throw new RuntimeException('User not found.');
        }

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : (string) $existing['name'];
        $email = array_key_exists('email', $payload) ? trim((string) $payload['email']) : (string) $existing['email'];
        $role = array_key_exists('role', $payload) ? strtolower(trim((string) $payload['role'])) : (string) $existing['role'];
        $password = array_key_exists('password', $payload) ? (string) $payload['password'] : '';

        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid email address is required.');
        }

        if (!in_array($role, ['admin', 'user'], true)) {
            throw new RuntimeException('Role must be either "admin" or "user".');
        }

        if (strcasecmp($email, (string) $existing['email']) !== 0) {
            $duplicate = Db::run('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1', [
                'email' => $email,
                'id' => $id,
            ])->fetch();

            if ($duplicate !== false) {
                throw new RuntimeException('Email address is already in use by another user.');
            }
        }

        $shouldUpdatePassword = array_key_exists('password', $payload) && $password !== '';
        if ($shouldUpdatePassword && strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters long.');
        }

        $passwordHash = $shouldUpdatePassword
            ? password_hash($password, PASSWORD_DEFAULT)
            : (string) $existing['password_hash'];

        $timestamp = (new DateTimeImmutable())->format('c');

        try {
            Db::run(
                'UPDATE users SET name = :name, email = :email, role = :role, password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
                [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'password_hash' => $passwordHash,
                    'updated_at' => $timestamp,
                    'id' => $id,
                ]
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to update user: ' . $exception->getMessage(), previous: $exception);
        }

        $row = Db::run('SELECT id, name, email, role, created_at, updated_at FROM users WHERE id = :id', ['id' => $id])->fetch();
        $data = $row !== false ? $row : [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'created_at' => (string) $existing['created_at'],
            'updated_at' => $timestamp,
        ];

        return self::format($data);
    }

    /**
     * Deletes a user and enforces guard rails to keep at least one admin.
     */
    public static function delete(int $id, int $currentUserId): void
    {
        if ($id <= 0) {
            throw new RuntimeException('User ID must be positive.');
        }

        if ($id === $currentUserId) {
            throw new RuntimeException('You cannot delete your own account while signed in.');
        }

        $existing = Db::run('SELECT id, role FROM users WHERE id = :id LIMIT 1', ['id' => $id])->fetch();
        if ($existing === false || !is_array($existing)) {
            throw new RuntimeException('User not found.');
        }

        if (($existing['role'] ?? '') === 'admin') {
            $adminCount = Db::run('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])->fetch();
            $adminTotal = is_array($adminCount) ? (int) ($adminCount['total'] ?? 0) : 0;

            if ($adminTotal <= 1) {
                throw new RuntimeException('At least one administrator account must remain.');
            }
        }

        try {
            Db::run('DELETE FROM users WHERE id = :id', ['id' => $id]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to delete user: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Formats a raw database row for API output.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
