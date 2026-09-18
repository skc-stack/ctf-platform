<?php
declare(strict_types=1);

namespace CTF\Server\Database;

use CTF\Server\Support\Config;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO singleton. All DB access goes through here.
 * Caller MUST use prepared statements via prepare()/execute().
 */
final class Connection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::required('DB_HOST'),
                Config::int('DB_PORT', 3306),
                Config::required('DB_DATABASE')
            );
            try {
                self::$pdo = new PDO(
                    $dsn,
                    Config::required('DB_USERNAME'),
                    Config::required('DB_PASSWORD'),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_PERSISTENT => false,
                    ]
                );
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 500, $e);
            }
        }
        return self::$pdo;
    }

    /**
     * Convenience: prepare + execute + return PDOStatement.
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Convenience: fetch one row.
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Convenience: fetch all rows.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback(self::$pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
