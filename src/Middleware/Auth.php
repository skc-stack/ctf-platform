<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

/**
 * Require a logged-in user. Redirects to /login if missing.
 */
final class Auth extends BaseMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user || ($user['status'] ?? null) !== 'active') {
            if ($req->isJson() || str_starts_with($req->path, '/api/')) {
                return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
            }
            // remember intended URL
            $_SESSION['intended_url'] = $req->path;
            return Response::redirect('/login');
        }
        return $next($req);
    }
}
