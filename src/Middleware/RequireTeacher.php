<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

final class RequireTeacher extends RequireRole
{
    protected function allowedRoles(): array
    {
        return ['teacher', 'admin'];
    }
}
