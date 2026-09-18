<?php
declare(strict_types=1);

namespace CTF\Server\Security;

use CTF\Server\Support\Config;

/**
 * Dynamic flag generation.
 *
 * Each task gets a unique flag derived from:
 *   HMAC-SHA256(student_id + ":" + challenge_uuid + ":" + task_uuid, FLAG_MASTER_SECRET)
 *
 * Output format: `flag{<32-char hex>}`.
 *
 * Why HMAC vs plain hash: only Server knows FLAG_MASTER_SECRET, so a Target VM
 * cannot reverse-engineer a flag from the inputs alone. A leaked master would
 * invalidate every past flag at once — operator-controlled rotation.
 */
final class FlagGenerator
{
    private const FLAG_PREFIX = 'flag{';
    private const FLAG_SUFFIX = '}';

    /**
     * Compute the canonical flag for the given identifiers.
     * Throws if FLAG_MASTER_SECRET is not configured.
     */
    public static function compute(int $studentId, string $challengeUuid, string $taskUuid): string
    {
        $secret = Config::get('FLAG_MASTER_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new \RuntimeException('FLAG_MASTER_SECRET is not configured');
        }
        $message = $studentId . ':' . $challengeUuid . ':' . $taskUuid;
        $hex = hash_hmac('sha256', $message, $secret);
        return self::FLAG_PREFIX . $hex . self::FLAG_SUFFIX;
    }

    /**
     * Constant-time check that $submitted matches the expected flag.
     * Does not leak length or position of mismatch.
     */
    public static function matches(string $submitted, string $expected): bool
    {
        return \hash_equals($expected, $submitted);
    }
}
