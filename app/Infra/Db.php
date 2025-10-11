<?php

declare(strict_types=1);

namespace App\Infra;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Database helper built on top of PDO.
 *
 * Manages a single PDO connection configured via INI files and offers
 * convenience helpers for executing queries and wrapping transactions.
 */
final class Db
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    /**
     * Returns the active PDO connection, creating it if necessary.
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = (string) Config::get('db.dsn');
        $user = Config::has('db.user') ? Config::get('db.user') : null;
        $pass = Config::has('db.pass') ? Config::get('db.pass') : null;

        try {
            $pdo = new PDO(
                $dsn,
                $user === '' ? null : $user,
                $pass === '' ? null : $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }

        self::$pdo = $pdo;

        return $pdo;
    }

    /**
     * Executes a prepared statement and returns the PDOStatement.
     *
     * @param string $sql SQL query with positional or named parameters.
     * @param array<int|string, mixed> $params Parameters to bind.
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Executes a callback inside a database transaction.
     *
     * @template T
     * @param Closure():T $callback
     *
     * @return T
     */
    public static function transaction(Closure $callback)
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Closes the active PDO connection (useful for tests or graceful shutdown).
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /**
     * Overrides the active PDO connection (primarily for testing).
     */
    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
