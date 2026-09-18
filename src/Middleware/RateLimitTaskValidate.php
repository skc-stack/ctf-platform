<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Support\Config;

/**
 * Rate limit for POST /api/v1/device/task/validate.
 * Default: 10/min per IP (configurable via RATE_LIMIT_TASK_VALIDATE).
 */
final class RateLimitTaskValidate extends RateLimit
{
    protected function bucket(): string
    {
        return 'task_validate';
    }
    protected function perMinute(): int
    {
        return (int)Config::get('RATE_LIMIT_TASK_VALIDATE', 10);
    }
}
