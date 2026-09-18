<?php
declare(strict_types=1);

namespace CTF\Server\Security;

/**
 * Password hashing + policy validation.
 *
 * Algorithm: PASSWORD_ARGON2ID (per CLAUDE.md §9 + CTF_Server_SPEC §20).
 *
 * Policy (configurable, defaults to a reasonable minimum):
 *   - min length 8
 *   - at least one lowercase letter
 *   - at least one uppercase letter
 *   - at least one digit
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 8;

    public const ALGO = PASSWORD_ARGON2ID;

    /** @var array<string,mixed> */
    private const OPTIONS = [
        'memory_cost' => 65536,   // 64 MB
        'threads' => 1,
        'time_cost' => 4,
    ];

    public static function hash(string $password): string
    {
        $hash = password_hash($password, self::ALGO, self::OPTIONS);
        if ($hash === false) {
            throw new \RuntimeException('password_hash() failed');
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Returns null if OK, or an error message describing the first violated rule.
     */
    public static function policyError(?string $password): ?string
    {
        if ($password === null || $password === '') {
            return '密碼不可為空';
        }
        if (strlen($password) < self::MIN_LENGTH) {
            return '密碼至少需要 ' . self::MIN_LENGTH . ' 個字元';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return '密碼需含至少一個小寫字母';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return '密碼需含至少一個大寫字母';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return '密碼需含至少一個數字';
        }
        return null;
    }
}
