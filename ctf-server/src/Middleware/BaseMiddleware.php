<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

/**
 * Base middleware contract: handle(req, next) returns Response.
 * Subclasses may short-circuit by returning without calling $next.
 */
abstract class BaseMiddleware
{
    abstract public function handle(Request $req, callable $next): Response;
}
