<?php

declare(strict_types=1);

namespace App\Tests\Infra;

use App\Infra\Auth;
use App\Tests\TestCase;
use PDO;
use RuntimeException;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDefaultConfig();
        $pdo = $this->useInMemoryDatabase();
        $this->createSchema($pdo);
        $this->seedUsers($pdo);
    }

    public function testRequireRoleAllowsAdmin(): void
    {
        $identity = Auth::attempt('admin@example.com', 'secret');
        $this->assertNotNull($identity);

        Auth::requireRole('admin');
        $this->assertTrue(Auth::isAdmin());
    }

    public function testRequireRoleDeniesNonAdmin(): void
    {
        $identity = Auth::attempt('user@example.com', 'secret');
        $this->assertNotNull($identity);

        $this->expectException(RuntimeException::class);
        Auth::requireRole('admin');
    }

    public function testRequireUserOrAdminAllowsOwner(): void
    {
        $identity = Auth::attempt('user@example.com', 'secret');
        $this->assertNotNull($identity);

        Auth::requireUserOrAdmin((int) $identity['id']);
        $this->assertFalse(Auth::isAdmin());
    }

    public function testRequireUserOrAdminAllowsAdminForDifferentUser(): void
    {
        $identity = Auth::attempt('admin@example.com', 'secret');
        $this->assertNotNull($identity);

        Auth::requireUserOrAdmin(2);
        $this->assertTrue(Auth::isAdmin());
    }

    public function testRequireUserOrAdminDeniesDifferentUser(): void
    {
        $identity = Auth::attempt('user@example.com', 'secret');
        $this->assertNotNull($identity);

        $this->expectException(RuntimeException::class);
        Auth::requireUserOrAdmin(1);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
    }

    private function seedUsers(PDO $pdo): void
    {
    $now = '2024-01-01T00:00:00.000000+00:00';
        $insert = $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, created_at, updated_at) VALUES (:id, :name, :email, :hash, :role, :created_at, :updated_at)');

        $users = [
            [
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'role' => 'admin',
            ],
            [
                'id' => 2,
                'name' => 'Regular',
                'email' => 'user@example.com',
                'role' => 'user',
            ],
        ];

        foreach ($users as $user) {
            $insert->execute([
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'hash' => password_hash('secret', PASSWORD_BCRYPT),
                'role' => $user['role'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
