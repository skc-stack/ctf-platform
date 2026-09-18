<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

/**
 * Inverse of Auth: send logged-in users to their dashboard.
 * Used for /login and /register routes.
 */
final class Guest extends BaseMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $user = $_SESSION['user'] ?? null;
        if ($user) {
            return Response::redirect($this->dashboardUrl($user['role'] ?? 'student'));
        }
        return $next($req);
    }

    private function dashboardUrl(string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'teacher' => '/teacher',
            default => '/student',
        };
    }
}
