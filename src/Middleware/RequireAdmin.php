<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

final class RequireAdmin extends RequireRole
{
    protected function allowedRoles(): array
    {
        return ['admin'];
    }
}
