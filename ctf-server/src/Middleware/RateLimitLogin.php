<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Support\Config;

/**
 * 5 login attempts per minute per IP (configurable via RATE_LIMIT_LOGIN).
 */
final class RateLimitLogin extends RateLimit
{
    protected function bucket(): string
    {
        return 'login';
    }

    protected function perMinute(): int
    {
        return Config::int('RATE_LIMIT_LOGIN', 5);
    }
}
