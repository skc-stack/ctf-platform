<?php
declare(strict_types=1);

namespace CTF\Server\Http;

/**
 * Simple regex router with middleware chain.
 *
 * Route definition: ['GET', '/health', [AuthMiddleware::class], [Controller::class, 'action']]
 * Pattern params like /users/{id} become $routeParams['id'].
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,middleware:array<int,string>,handler:array{0:string,1:string}}> */
    private array $routes = [];

    public function add(string $method, string $path, array $middleware, array $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$params) {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $path
        );
        $regex = '#^' . rtrim($regex, '/') . '/?$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $path,
            'regex' => $regex,
            'params' => $params,
            'middleware' => $middleware,
            'handler' => $handler,
        ];
    }

    public function get(string $path, array $middleware, array $handler): void
    {
        $this->add('GET', $path, $middleware, $handler);
    }

    public function post(string $path, array $middleware, array $handler): void
    {
        $this->add('POST', $path, $middleware, $handler);
    }

    public function put(string $path, array $middleware, array $handler): void
    {
        $this->add('PUT', $path, $middleware, $handler);
    }

    public function delete(string $path, array $middleware, array $handler): void
    {
        $this->add('DELETE', $path, $middleware, $handler);
    }

    public function dispatch(Request $req): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $req->method) {
                continue;
            }
            $candidate = rtrim($req->path, '/') ?: '/';
            if (!preg_match($route['regex'], $candidate, $m)) {
                continue;
            }
            array_shift($m);
            $req->routeParams = array_combine($route['params'], $m) ?: [];

            // Run middleware chain
            $pipeline = array_reverse($route['middleware']);
            $core = function (Request $r) use ($route): Response {
                [$class, $action] = $route['handler'];
                if (!class_exists($class)) {
                    throw new \RuntimeException("Controller class not found: {$class}");
                }
                $controller = new $class();
                if (!method_exists($controller, $action)) {
                    throw new \RuntimeException("Action not found: {$class}::{$action}");
                }
                // Spread route params after Request, so signatures like
                // `action(Request $req, string $id)` work.
                return $controller->$action($r, ...array_values($r->routeParams));
            };

            $next = $core;
            foreach ($pipeline as $mwClass) {
                $next = function (Request $r) use ($mwClass, $next): Response {
                    if (!class_exists($mwClass)) {
                        throw new \RuntimeException("Middleware class not found: {$mwClass}");
                    }
                    $mw = new $mwClass();
                    return $mw->handle($r, $next);
                };
            }

            return $next($req);
        }

        return Response::make('Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
