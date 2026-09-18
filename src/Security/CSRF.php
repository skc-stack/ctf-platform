<?php
declare(strict_types=1);

namespace CTF\Server\Security;

/**
 * CSRF token utility — session-bound, double-submit.
 *
 * The token is stored in $_SESSION['_csrf'] (rotated on login).
 * Templates include it via CSRF::field(); middleware verifies POSTs.
 */
final class CSRF
{
    public const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::generate();
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function check(?string $submitted): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || !is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals($expected, $submitted);
    }

    /** Rotate token — call after login / privilege change. */
    public static function rotate(): string
    {
        $_SESSION[self::SESSION_KEY] = self::generate();
        return $_SESSION[self::SESSION_KEY];
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return string HTML hidden input */
    public static function field(): string
    {
        $value = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $value . '">';
    }
}
