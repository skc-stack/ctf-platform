<?php
declare(strict_types=1);

namespace CTF\Server\Security;

/**
 * Thin wrapper around hash_equals for type safety.
 *
 * Used anywhere we compare user-supplied strings against a Server-side
 * secret (flags, nonces, tokens). Falls back to `==` only if both strings
 * are exactly equal length and `hash_equals` would be no-op anyway.
 */
final class ConstantTimeCompare
{
    public static function stringEquals(string $known, string $user): bool
    {
        return \hash_equals($known, $user);
    }
}
