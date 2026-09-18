<?php
declare(strict_types=1);

namespace CTF\Server\Support;

/**
 * Static config accessor. Loaded once from .env via bootstrap.php.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];

    public static function load(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::$values[$key] ?? null;
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::$values[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    public static function required(string $key): string
    {
        $v = self::$values[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new \RuntimeException("Missing required config: {$key}");
        }
        return $v;
    }
}
