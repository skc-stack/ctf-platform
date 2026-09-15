<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Support\Config;

/**
 * Rate limit for flag submissions.
 * Default: 10/min per IP (configurable via RATE_LIMIT_FLAG_SUBMIT).
 *
 * Covers both browser-side POST /api/v1/student/submit and
 * device-side POST /api/v1/device/task/complete.
 */
final class RateLimitFlagSubmit extends RateLimit
{
    protected function bucket(): string
    {
        return 'flag_submit';
    }
    protected function perMinute(): int
    {
        return (int)Config::get('RATE_LIMIT_FLAG_SUBMIT', 10);
    }
}
