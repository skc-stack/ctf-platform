<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Support\Config;

/**
 * Rate-limit password-reset requests. 3/min per IP (configurable).
 * Higher threshold than login because legitimate users occasionally
 * forget and may retry. Lower than activation (which only fires once).
 */
final class RateLimitPasswordReset extends RateLimit
{
    protected function bucket(): string
    {
        return 'password_reset';
    }

    protected function perMinute(): int
    {
        return Config::int('RATE_LIMIT_PASSWORD_RESET', 3);
    }
}
