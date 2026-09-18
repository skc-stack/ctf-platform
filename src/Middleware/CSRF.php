<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Security\CSRF as CsrfToken;
use CTF\Server\Support\Logger;

/**
 * CSRF check — verifies the _csrf field on POST/PUT/DELETE/GET-with-side-effects.
 * GET requests are passed through (no state change).
 */
final class CSRF extends BaseMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        $method = $req->method;
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return $next($req);
        }

        // Token may come from POST body OR header X-CSRF (for fetch())
        $submitted = is_string($req->post['_csrf'] ?? null)
            ? (string)$req->post['_csrf']
            : (is_string($req->header('X-CSRF')) ? (string)$req->header('X-CSRF') : null);

        if (!CsrfToken::check($submitted)) {
            Logger::get()->info('CSRF mismatch', ['path' => $req->path, 'ip' => $req->ip()]);
            // For HTML form: re-render the form with an error.
            // For API/JSON: return JSON 403.
            if ($req->isJson() || str_starts_with($req->path, '/api/')) {
                return Response::json(['success' => false, 'error' => 'CSRF token mismatch'], 403);
            }
            return Response::make(
                '<!doctype html><meta charset=utf-8><title>403</title>'
                . '<h1 style="font-family:sans-serif;color:#EF4444">CSRF token mismatch</h1>'
                . '<p>Please go back, reload the page, and try again.</p>',
                403,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        return $next($req);
    }
}
