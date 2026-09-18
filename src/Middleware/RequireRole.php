<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

/**
 * Base role check. Subclasses declare which roles are allowed.
 *
 * Convention:
 *   RequireAdmin  → only admin
 *   RequireTeacher → teacher or admin (admin can manage teacher features)
 *   RequireStudent → student, teacher, or admin (any logged-in user)
 */
abstract class RequireRole extends BaseMiddleware
{
    /** @return string[] */
    abstract protected function allowedRoles(): array;

    public function handle(Request $req, callable $next): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            return Response::redirect('/login');
        }
        $role = $user['role'] ?? '';
        if (!in_array($role, $this->allowedRoles(), true)) {
            if ($req->isJson() || str_starts_with($req->path, '/api/')) {
                return Response::json(['success' => false, 'error' => 'Forbidden'], 403);
            }
            return Response::make(
                '<!doctype html><meta charset=utf-8><title>403</title>'
                . '<h1 style="font-family:sans-serif;color:#EF4444">403 Forbidden</h1>'
                . '<p>You do not have permission to access this page.</p>',
                403,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }
        return $next($req);
    }
}
