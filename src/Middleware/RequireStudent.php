<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

final class RequireStudent extends RequireRole
{
    protected function allowedRoles(): array
    {
        return ['student', 'teacher', 'admin'];
    }
}
